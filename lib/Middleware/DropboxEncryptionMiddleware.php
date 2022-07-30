<?php

namespace OCA\DropboxEncryption\Middleware;

use OCP\AppFramework\Middleware;
use OCP\AppFramework\Http\Response;
use OCA\Dropbox\Controller\ConfigController;
use OCA\Dropbox\Controller\DropboxAPIController;
use OCA\Dropbox\Service\UserScopeService;
use OCA\Dropbox\Service\DropboxAPIService;
use OCA\Dropbox\Service\DropboxStorageAPIService;
use OCA\Dropbox\AppInfo\Application as DropboxApplication;
use OCA\DropboxEncryption\Service\DropboxStorageAPIServiceCustom;
use OC;

class DropboxEncryptionMiddleware extends Middleware {
	public function __construct() {
	}

	public function beforeOutput($controller, $methodName, $output){
		return $output;
	}

	public function beforeController($controller, $methodName) {
		if (! ($controller instanceof DropboxAPIController && $methodName == 'importDropbox')) {
			return;
		}

		$userId = OC::$server->getUserSession()->getUser()->getUID();
		$loggerInterface = \OC::$server->get(\Psr\Log\LoggerInterface::class);
		$appId = DropboxApplication::APP_ID;
		$logger = new OC\AppFramework\ScopedPsrLogger($loggerInterface, $appId);
		
		$root = OC::$server->getLazyRootFolder();
		$config = OC::$server->getConfig();
		$jobList = OC::$server->getJobList();
		$userScopeService = OC::$server->get(UserScopeService::class);
		$userScopeService->setUserScope($userId);
		$userScopeService->setFilesystemScope($userId);

		$dropboxApiService = OC::$server->get(DropboxAPIService::class);
		$service = new DropboxStorageAPIServiceCustom($appId, $logger, $root, $config, $jobList, $userScopeService, $dropboxApiService);
		$response = $service->startImportDropbox($userId);

		ob_start();
		echo json_encode($response);
		$size = ob_get_length();
		header("Content-Encoding: none");
		header("Content-Length: {$size}");
		header("Connection: close");
		ob_end_flush();
		flush();

		if (session_id()) {
			session_write_close();
		}

		$service->importDropboxJob($userId);

		exit();
	}
	public function afterController($controller, $methodName, Response $response): Response {
		return $response;
	}
}