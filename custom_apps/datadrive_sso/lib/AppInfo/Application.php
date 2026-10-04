<?php

declare(strict_types=1);

namespace OCA\DatadriveSso\AppInfo;

use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCA\DatadriveSso\Middleware\GuestRedirectMiddleware;
use OCA\DatadriveSso\Listener\DisabledUserListener;
use OCP\User\Events\UserChangedEvent;
use OCP\AppFramework\Bootstrap\IRegistrationContext;

class Application extends App implements IBootstrap {
	public const APP_ID = 'datadrive_sso';

	public function __construct(array $urlParams = []) {
		parent::__construct(self::APP_ID, $urlParams);
	}

	public function register(IRegistrationContext $context): void {
		// true = ap dung cho MOI controller, ke ca LoginController cua core.
		// Loi thoat: ?direct=1 (quan tri + WordPress dang nhap ho) va luong /login/flow cua app.
		$context->registerMiddleware(GuestRedirectMiddleware::class, true);
		// Khoa tai khoan -> thu hoi token de app nhan 401 (dang nhap lai) thay vi 503.
		$context->registerEventListener(UserChangedEvent::class, DisabledUserListener::class);
	}

	public function boot(IBootContext $context): void {
	}
}
