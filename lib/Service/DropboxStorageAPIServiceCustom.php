<?php
/**
 * Nextcloud - dropbox
 *
 * This file is licensed under the Affero General Public License version 3 or
 * later. See the COPYING file.
 *
 * @author Julien Veyssier
 * @copyright Julien Veyssier 2020
 */

namespace OCA\DropboxEncryption\Service;

use Datetime;
use OCP\Files\Folder;
use Psr\Log\LoggerInterface;
use OCP\IConfig;
use OCP\Files\IRootFolder;
use OCP\Files\FileInfo;
use OCP\Files\Node;
use OCP\Files\NotFoundException;
use OCP\Lock\LockedException;

use OCP\BackgroundJob\IJobList;

use OCA\Dropbox\AppInfo\Application;
//use OCA\Dropbox\BackgroundJob\ImportDropboxJob;
use OCA\Dropbox\Service\DropboxStorageAPIService;
use OCA\Dropbox\Service\UserScopeService;
use OCA\Dropbox\Service\DropboxAPIService;
use OCP\Files\ForbiddenException;

class DropboxStorageAPIServiceCustom extends DropboxStorageAPIService {
	/**
	 * @var string
	 */
	private $appName;
	/**
	 * @var LoggerInterface
	 */
	private $logger;
	/**
	 * @var IRootFolder
	 */
	private $root;
	/**
	 * @var IConfig
	 */
	private $config;
	/**
	 * @var IJobList
	 */
	private $jobList;
	/**
	 * @var DropboxAPIService
	 */
	private $dropboxApiService;
	/**
	 * @var UserScopeService
	 */
	private $userScopeService;

	/**
	 * Service to make requests to Dropbox API
	 */
	public function __construct (string $appName,
								LoggerInterface $logger,
								IRootFolder $root,
								IConfig $config,
								IJobList $jobList,
								UserScopeService $userScopeService,
								DropboxAPIService $dropboxApiService) {
		parent::__construct($appName,
							$logger,
							$root,
							$config,
							$jobList,
							$userScopeService,
							$dropboxApiService);

		$this->appName = $appName;
		$this->logger = $logger;
		$this->root = $root;
		$this->config = $config;
		$this->jobList = $jobList;
		$this->dropboxApiService = $dropboxApiService;
		$this->userScopeService = $userScopeService;
	}

	/**
	 * @param string $userId
	 * @return array
	 */
	public function startImportDropbox(string $userId): array {
		$targetPath = $this->config->getUserValue($userId, Application::APP_ID, 'output_dir', '/Dropbox import');
		$targetPath = $targetPath ?: '/Dropbox import';
		// create root folder
		$userFolder = $this->root->getUserFolder($userId);
		if (!$userFolder->nodeExists($targetPath)) {
			$userFolder->newFolder($targetPath);
		} else {
			$folder = $userFolder->get($targetPath);
			if ($folder->getType() !== FileInfo::TYPE_FOLDER) {
				return ['error' => 'Impossible to create Dropbox folder'];
			}
		}
		$this->config->setUserValue($userId, Application::APP_ID, 'importing_dropbox', '1');
		$this->config->setUserValue($userId, Application::APP_ID, 'nb_imported_files', '0');
		$this->config->setUserValue($userId, Application::APP_ID, 'last_dropbox_import_timestamp', '0');
		$this->config->setUserValue($userId, Application::APP_ID, 'dropbox_import_running', '0');

		//$this->jobList->add(ImportDropboxJob::class, ['user_id' => $userId]);
		return ['targetPath' => $targetPath];
	}

	/**
	 * @param string $userId
	 * @return void
	 */
	public function importDropboxJob(string $userId): void {
		// This is unnecessary and is throwing errors.
		//$this->logger->error('Importing dropbox files for ' . $userId);

		// in case SSE is enabled
		$this->userScopeService->setUserScope($userId);
		$this->userScopeService->setFilesystemScope($userId);

		$importingDropbox = $this->config->getUserValue($userId, Application::APP_ID, 'importing_dropbox', '0') === '1';
		$jobRunning = $this->config->getUserValue($userId, Application::APP_ID, 'dropbox_import_running', '0') === '1';
		if (!$importingDropbox || $jobRunning) {
			return;
		}
		$this->config->setUserValue($userId, Application::APP_ID, 'dropbox_import_running', '1');

		$accessToken = $this->config->getUserValue($userId, Application::APP_ID, 'token');
		$refreshToken = $this->config->getUserValue($userId, Application::APP_ID, 'refresh_token');
		$clientID = $this->config->getAppValue(Application::APP_ID, 'client_id', Application::DEFAULT_DROPBOX_CLIENT_ID);
		$clientID = $clientID ?: Application::DEFAULT_DROPBOX_CLIENT_ID;
		$clientSecret = $this->config->getAppValue(Application::APP_ID, 'client_secret', Application::DEFAULT_DROPBOX_CLIENT_SECRET);
		$clientSecret = $clientSecret ?: Application::DEFAULT_DROPBOX_CLIENT_SECRET;
		// import batch of files
		$targetPath = $this->config->getUserValue($userId, Application::APP_ID, 'output_dir', '/Dropbox import');
		$targetPath = $targetPath ?: '/Dropbox import';
		// import by batch of 500 Mo
		$alreadyImported = $this->config->getUserValue($userId, Application::APP_ID, 'nb_imported_files', '0');
		$alreadyImported = (int) $alreadyImported;
		try {
			$result = $this->importFiles($accessToken, $refreshToken, $clientID, $clientSecret, $userId, $targetPath, 500000000, $alreadyImported);
		} catch (\Exception | \Throwable $e) {
			$result = [
				'error' => 'Unknow job failure. ' . $e->getMessage(),
			];
		}
		if (isset($result['error']) || (isset($result['finished']) && $result['finished'])) {
			$this->config->setUserValue($userId, Application::APP_ID, 'importing_dropbox', '0');
			$this->config->setUserValue($userId, Application::APP_ID, 'nb_imported_files', '0');
			$this->config->setUserValue($userId, Application::APP_ID, 'last_dropbox_import_timestamp', '0');
			if (isset($result['finished']) && $result['finished']) {
				$this->dropboxApiService->sendNCNotification($userId, 'import_dropbox_finished', [
					'nbImported' => $result['totalSeen'],
					'targetPath' => $targetPath,
				]);
			}
		} else {
			$ts = (new Datetime())->getTimestamp();
			$this->config->setUserValue($userId, Application::APP_ID, 'last_dropbox_import_timestamp', $ts);
			//$this->jobList->add(ImportDropboxJob::class, ['user_id' => $userId]);
		}
		$this->config->setUserValue($userId, Application::APP_ID, 'dropbox_import_running', '0');
	}

	/**
	 * @param string $accessToken
	 * @param string $refreshToken
	 * @param string $clientID
	 * @param string $clientSecret
	 * @param string $userId
	 * @param string $targetPath
	 * @param ?int $maxDownloadSize
	 * @param int $alreadyImported
	 * @return array
	 */
	public function importFiles(string $accessToken, string $refreshToken, string $clientID, string $clientSecret,
								string $userId, string $targetPath,
								?int $maxDownloadSize = null, int $alreadyImported = 0): array {
		// create root folder
		$userFolder = $this->root->getUserFolder($userId);
		if (!$userFolder->nodeExists($targetPath)) {
			$folder = $userFolder->newFolder($targetPath);
		} else {
			$folder = $userFolder->get($targetPath);
			if ($folder->getType() !== FileInfo::TYPE_FOLDER) {
				return ['error' => 'Impossible to create ' . $targetPath . ' folder'];
			}
		}

		$downloadedSize = 0;
		$nbDownloaded = 0;
		$totalSeenNumber = 0;

		$params = [
			'limit' => 2000,
			'path' => '',
			'recursive' => true,
			'include_deleted' => false,
			'include_has_explicit_shared_members' => false,
			'include_mounted_folders' => true,
			'include_non_downloadable_files' => false,
		];
		do {
			$suffix = isset($params['cursor']) ? '/continue' : '';
			$result = $this->dropboxApiService->request(
				$accessToken, $refreshToken, $clientID, $clientSecret, $userId, 'files/list_folder' . $suffix, $params, 'POST'
			);
			if (isset($result['error'])) {
				return $result;
			}
			if (isset($result['entries']) && is_array($result['entries'])) {
				foreach ($result['entries'] as $entry) {
					if (isset($entry['.tag']) && $entry['.tag'] === 'file') {
						$totalSeenNumber++;
						$size = $this->getFile($accessToken, $refreshToken, $clientID, $clientSecret, $userId, $entry, $folder);
						if (!is_null($size)) {
							$nbDownloaded++;
							$this->config->setUserValue($userId, Application::APP_ID, 'nb_imported_files', $alreadyImported + $nbDownloaded);
							$downloadedSize += $size;
							if ($maxDownloadSize && $downloadedSize > $maxDownloadSize) {
								return [
									'nbDownloaded' => $nbDownloaded,
									'targetPath' => $targetPath,
									'finished' => false,
									'totalSeen' => $totalSeenNumber,
								];
							}
						}
					}
				}
			}
			$params = [
				'cursor' => $result['cursor'] ?? '',
			];
		} while (isset($result['has_more'], $result['cursor']) && $result['has_more']);

		return [
			'nbDownloaded' => $nbDownloaded,
			'targetPath' => $targetPath,
			'finished' => true,
			'totalSeen' => $totalSeenNumber,
		];
	}

	/**
	 * @param string $accessToken
	 * @param string $refreshToken
	 * @param string $clientID
	 * @param string $clientSecret
	 * @param string $userId
	 * @param array $fileItem
	 * @param Folder $topFolder
	 * @return ?float downloaded size, null if already existing or network error
	 */
	private function getFile(string $accessToken, string $refreshToken, string $clientID, string $clientSecret,
							string $userId, array $fileItem, Folder $topFolder): ?float {
		
		if ($this->getUserConfigValue($userId, 'importing_dropbox') == 0) {
			return null;
		}

		$fileName = $fileItem['name'];
		$path = preg_replace('/^\//', '', $fileItem['path_display'] ?? '.');
		$pathParts = pathinfo($path);
		$dirName = $pathParts['dirname'];
		if ($dirName === '.') {
			$saveFolder = $topFolder;
		} else {
			$saveFolder = $this->createAndGetFolder($dirName, $topFolder);
			if (is_null($saveFolder)) {
				$this->logger->warning(
					'Dropbox error, can\'t create directory "' . $dirName . '"',
					['app' => $this->appName]
				);
				return null;
			}
		}
		try {
			if ($saveFolder->nodeExists($fileName)) {
				if ($this->dropbox_content_hash($saveFolder->get($fileName)) != $fileItem['content_hash']) {
					$saveFolder->get($fileName)->delete();
				}
			}
		} catch (ForbiddenException $e) {
			return null;
		}
		try {
			$savedFile = $saveFolder->newFile($fileName);
			$resource = $savedFile->fopen('w');
		} catch (NotFoundException $e) {
			$this->logger->warning(
				'Dropbox error, can\'t create file "' . $fileName . '" in "' . $saveFolder->getPath() . '"',
				['app' => $this->appName]
			);
			return null;
		}
		$res = $this->dropboxApiService->downloadFile(
			$accessToken, $refreshToken, $clientID, $clientSecret, $userId, $resource, $fileItem['id']
		);
		if (isset($res['error'])) {
			$this->logger->warning('Dropbox error downloading file ' . $fileName . ' : ' . $res['error'], ['app' => $this->appName]);
			try {
				if ($savedFile->isDeletable()) {
					$savedFile->delete();
				}
			} catch (LockedException $e) {
				$this->logger->warning('Dropbox error deleting file ' . $fileName, ['app' => $this->appName]);
			}
			return null;
		}
		if (is_resource($resource)) {
			fclose($resource);
		}
		if (isset($fileItem['server_modified'])) {
			$d = new Datetime($fileItem['server_modified']);
			$ts = $d->getTimestamp();
			$savedFile->touch($ts);
		} else {
			$savedFile->touch();
		}
		return $fileItem['size'];
	}

	/**
	 * @param string $dirName
	 * @param Folder $topFolder
	 * @return ?Node
	 * @throws NotFoundException
	 * @throws \OCP\Files\NotPermittedException
	 */
	private function createAndGetFolder(string $dirName, Folder $topFolder): ?Folder {
		$dirs = explode('/', $dirName);
		$dirNode = $topFolder;
		foreach ($dirs as $dir) {
			if (!$dirNode->nodeExists($dir)) {
				$dirNode = $dirNode->newFolder($dir);
			} else {
				$dirNode = $dirNode->get($dir);
				if ($dirNode->getType() !== FileInfo::TYPE_FOLDER) {
					return null;
				}
			}
		}
		return $dirNode;
	}

	private function dropbox_content_hash($file) {
		$fh = $file->fopen('r+');

		if (!$fh) {
			# warning already issued
			return;
		}
		$hashes = '';
		$zero_byte_exception_check = true;
		do {
			$chunk = fread($fh, 4194304);
			if ($zero_byte_exception_check) {
				if ($chunk === '') {
					return 'e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855';
				}
				$zero_byte_exception_check = false;
			}
			$hashes .= hash('sha256', $chunk, true);
		} while (!feof($fh));
		fclose($fh);
		return hash('sha256', $hashes);
	}

	private function getUserConfigValue($userId, $key) {
		$query = 'SELECT `appid`, `configkey`, `configvalue` FROM `*PREFIX*preferences` WHERE `userid` = ? AND `appid` = ? AND `configkey` = ?';
		$result = \OC::$server->getDatabaseConnection()->executeQuery($query, [$userId, $this->appName, $key]);
		$results = $result->fetchAll();
		
		if (sizeof($results) == 1 && $results[0]['configkey'] == $key) {
			return $results[0]['configvalue'];
		}
		else {
			return null;
		}
	}
}