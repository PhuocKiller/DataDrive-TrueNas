<?php

declare(strict_types=1);

/**
 * Điểm tiếp nhận đăng nhập một lần từ datadrive.vn.
 *
 * Vì sao cần app này: nếu WordPress gắn cookie Nextcloud cho miền cha (.datadrive.vn) thì
 * trình duyệt khách sẽ có HAI cookie trùng tên (cookie cũ của riêng storage.datadrive.vn +
 * cookie miền cha). Nextcloud thử cookie ghi nhớ cũ, xác minh hỏng và tự đăng xuất
 * -> khách thấy Files loé lên rồi bị đá về trang đăng nhập.
 *
 * Cách làm ở đây: WordPress chỉ gửi một MÃ KÝ (HMAC-SHA256, dùng một lần, hết hạn 2 phút).
 * App xoá cookie cũ theo host rồi tự đăng nhập -> Nextcloud tự quản cookie của nó như bình thường.
 */

namespace OCA\DatadriveSso\Controller;

use OCA\DatadriveSso\AppInfo\Application;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\Attribute\UseSession;
use OCP\AppFramework\Http\RedirectResponse;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\ICacheFactory;
use OCP\IConfig;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\IUserManager;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

class SsoController extends Controller {

	private const TTL = 120;          // mã chỉ sống 2 phút
	private const COOKIES_TO_CLEAR = ['oc_sessionPassphrase', 'nc_username', 'nc_token', 'nc_session_id'];

	public function __construct(
		string $appName,
		IRequest $request,
		private IConfig $config,
		private IUserManager $userManager,
		private IUserSession $userSession,
		private IURLGenerator $urlGenerator,
		private ICacheFactory $cacheFactory,
		private LoggerInterface $logger,
	) {
		parent::__construct($appName, $request);
	}

	private function secret(): string {
		$cfg = $this->config->getSystemValue('datadrive_sso', []);
		return is_array($cfg) ? (string)($cfg['secret'] ?? '') : '';
	}

	/** Chuỗi ký phải khớp đúng bên WordPress. */
	private function signature(string $action, string $uid, string $exp, string $nonce, string $redirect): string {
		return hash_hmac('sha256', implode('|', [$action, $uid, $exp, $nonce, $redirect]), $this->secret());
	}

	private function fail(string $reason): TemplateResponse {
		$this->logger->warning('SSO tu datadrive.vn that bai: ' . $reason, ['app' => Application::APP_ID]);
		return new TemplateResponse('core', '403', ['message' => 'Liên kết đăng nhập không hợp lệ hoặc đã hết hạn. Vui lòng đăng nhập lại tại datadrive.vn.'], 'guest');
	}

	/** Chỉ cho phép chuyển hướng nội bộ (tránh open redirect). */
	private function safePath(string $path): string {
		if ($path === '' || !str_starts_with($path, '/') || str_starts_with($path, '//')) {
			return '/apps/files/';
		}
		return $path;
	}

	/** Xoá cookie cũ đặt riêng cho host này (không có thuộc tính Domain). */
	private function clearHostCookies(): void {
		$names = array_merge([session_name()], self::COOKIES_TO_CLEAR);
		foreach ($names as $name) {
			if ($name === '' || $this->request->getCookie($name) === null) {
				continue;
			}
			setcookie($name, '', [
				'expires'  => 1,
				'path'     => \OC::$WEBROOT ?: '/',
				'secure'   => true,
				'httponly' => true,
				'samesite' => 'Lax',
			]);
		}
	}

	private function checkAndBurn(string $action, string $uid, string $exp, string $nonce, string $sig, string $redirect): ?TemplateResponse {
		if ($this->secret() === '') {
			return $this->fail('chua cau hinh datadrive_sso secret');
		}
		if (!ctype_digit($exp) || (int)$exp < time()) {
			return $this->fail('ma het han');
		}
		if ((int)$exp > time() + self::TTL + 60) {
			return $this->fail('thoi han bat thuong');
		}
		if (!hash_equals($this->signature($action, $uid, $exp, $nonce, $redirect), $sig)) {
			return $this->fail('chu ky sai');
		}
		// Dùng một lần: ghi nonce vào cache chung (Redis).
		$cache = $this->cacheFactory->createDistributed(Application::APP_ID . '_');
		if ($cache->get($nonce) !== null) {
			return $this->fail('ma da dung roi');
		}
		$cache->set($nonce, 1, self::TTL + 120);
		return null;
	}

	#[PublicPage]
	#[NoCSRFRequired]
	#[UseSession]
	public function login(string $uid = '', string $exp = '', string $nonce = '', string $sig = '', string $redirect = '/apps/files/'): mixed {
		$redirect = $this->safePath($redirect);

		// Đã đăng nhập đúng người rồi thì đi thẳng — kiểm tra TRƯỚC khi đốt mã,
		// để khách bấm F5 hoặc Back trên URL này không gặp trang lỗi.
		if ($this->userSession->isLoggedIn() && $this->userSession->getUser()?->getUID() === $uid) {
			return new RedirectResponse($this->urlGenerator->getAbsoluteURL($redirect));
		}

		$err = $this->checkAndBurn('login', $uid, $exp, $nonce, $sig, $redirect);
		if ($err !== null) {
			return $err;
		}

		$user = $this->userManager->get($uid);
		if ($user === null || !$user->isEnabled()) {
			return $this->fail('user khong ton tai hoac bi khoa: ' . $uid);
		}

		$this->clearHostCookies();

		/** @var \OC\User\Session $session API nội bộ: cần completeLogin + createSessionToken để lập phiên không cần mật khẩu */
		$session = \OC::$server->get(\OC\User\Session::class);
		if ($session->isLoggedIn()) {
			$session->logout();
		}
		// NC 34: PostLoginEvent yêu cầu password là chuỗi; truyền null sẽ lỗi 500.
		$session->completeLogin($user, ['loginName' => $uid, 'password' => '']);
		$session->createSessionToken($this->request, $user->getUID(), $uid);
		$session->createRememberMeToken($user);

		$this->logger->info('SSO tu datadrive.vn: da dang nhap ' . $uid, ['app' => Application::APP_ID]);
		return new RedirectResponse($this->urlGenerator->getAbsoluteURL($redirect));
	}

	#[PublicPage]
	#[NoCSRFRequired]
	#[UseSession]
	public function logout(string $uid = '', string $exp = '', string $nonce = '', string $sig = '', string $redirect = '/'): mixed {
		$err = $this->checkAndBurn('logout', $uid, $exp, $nonce, $sig, $redirect);
		if ($err !== null) {
			return $err;
		}
		if ($this->userSession->isLoggedIn()) {
			$this->userSession->logout();
		}
		$this->clearHostCookies();
		$this->logger->info('SSO tu datadrive.vn: da dang xuat ' . $uid, ['app' => Application::APP_ID]);

		$back = $this->config->getSystemValue('datadrive_sso', [])['home'] ?? 'https://datadrive.vn/';
		return new RedirectResponse((string)$back);
	}
}
