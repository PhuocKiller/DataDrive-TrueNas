<?php

declare(strict_types=1);

/**
 * Khách không bao giờ thấy trang đăng nhập của kho lưu trữ.
 *
 * Mọi thao tác tài khoản (đăng ký, đăng nhập, quên mật khẩu) làm tại datadrive.vn.
 * storage.datadrive.vn chỉ còn là giao diện SAU khi đã đăng nhập.
 *
 *  - Chưa đăng nhập mà vào bất kỳ trang nào -> Nextcloud đưa tới /login -> chặn tại đây,
 *    chuyển thẳng sang https://datadrive.vn/dang-nhap/.
 *  - Bấm "Đăng xuất" trong kho lưu trữ -> quay về datadrive.vn và huỷ luôn phiên bên đó.
 *
 * HAI LỐI THOÁT cố ý giữ lại:
 *  - `?direct=1`: vào thẳng form gốc của Nextcloud. Nếu SSO hỏng mà chặn tuyệt đối thì
 *    không ai vào được nữa, kể cả quản trị.
 *  - Luồng `/login/flow` (app máy tính / điện thoại lấy mật khẩu ứng dụng): không chặn,
 *    chặn là các app đó không đăng nhập được.
 */

namespace OCA\DatadriveSso\Middleware;

use OCA\DatadriveSso\AppInfo\Application;
use OCP\AppFramework\Http\Response;
use OCP\AppFramework\Middleware;
use OCP\IConfig;
use OCP\IRequest;

class GuestRedirectMiddleware extends Middleware {

	public function __construct(
		private IRequest $request,
		private IConfig $config,
	) {
	}

	private function cfg(string $key, string $default): string {
		$c = $this->config->getSystemValue('datadrive_sso', []);
		$v = is_array($c) ? ($c[$key] ?? '') : '';
		return is_string($v) && $v !== '' ? $v : $default;
	}

	public function afterController($controller, $methodName, Response $response): Response {
		if (!($controller instanceof \OC\Core\Controller\LoginController)) {
			return $response;
		}

		if ($methodName === 'logout') {
			// Nextcloud tu choi yeu cau (sai requesttoken...) thi KHONG duoc gia vo la
			// da dang xuat: chi doi dich khi no that su tra ve mot chuyen huong.
			if (!($response instanceof \OCP\AppFramework\Http\RedirectResponse)) {
				return $response;
			}
			// Nextcloud đã huỷ phiên của nó; đưa khách về datadrive.vn để huỷ nốt phiên bên đó.
			//
			// PHẢI giữ nguyên response gốc: nó mang theo các header xoá cookie phiên và
			// cookie ghi nhớ (nc_token, nc_username). Trả về một RedirectResponse mới sẽ
			// vứt mất những header đó -> khách "đăng xuất" xong quay lại vẫn vào thẳng được.
			$response->setHeaders(array_merge($response->getHeaders(), [
				'Location' => $this->cfg(
					'logout_back',
					'https://datadrive.vn/wp-admin/admin-post.php?action=dd_logout_back'
				),
			]));
			$response->setStatus(\OCP\AppFramework\Http::STATUS_SEE_OTHER);
			return $response;
		}

		if ($methodName !== 'showLoginForm') {
			return $response;
		}

		// Lối thoát cho quản trị.
		if ($this->request->getParam('direct') === '1') {
			return $response;
		}

		// Luồng đăng nhập của app máy tính/điện thoại: để nguyên.
		$redirectUrl = (string)($this->request->getParam('redirect_url') ?? '');
		if (str_contains($redirectUrl, '/login/flow')) {
			return $response;
		}

		$login = $this->cfg('login', 'https://datadrive.vn/dang-nhap/');
		$sep   = str_contains($login, '?') ? '&' : '?';
		$response->setHeaders(array_merge($response->getHeaders(), [
			'Location' => $login . $sep . 'need_login=1',
		]));
		$response->setStatus(\OCP\AppFramework\Http::STATUS_SEE_OTHER);
		return $response;
	}
}
