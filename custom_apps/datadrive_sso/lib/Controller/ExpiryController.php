<?php

declare(strict_types=1);

/**
 * Cột "Ngày hết hạn" ở trang Accounts (/settings/users): hạn gói lưu ở WordPress (wp_dd_customers),
 * máy chủ Nextcloud hỏi datadrive.vn bằng chữ ký HMAC dùng chung DD_SSO_SECRET rồi trả cho trình duyệt admin.
 * Tài khoản không có trong danh sách (vd. admin tạo tay) = không giới hạn thời gian.
 */

namespace OCA\DatadriveSso\Controller;

use OCA\DatadriveSso\AppInfo\Application;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\Http\Client\IClientService;
use OCP\ICacheFactory;
use OCP\IConfig;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

class ExpiryController extends Controller {

	private const CACHE_TTL = 60;

	public function __construct(
		string $appName,
		IRequest $request,
		private IConfig $config,
		private IUserSession $userSession,
		private IGroupManager $groupManager,
		private IClientService $clientService,
		private ICacheFactory $cacheFactory,
		private LoggerInterface $logger,
	) {
		parent::__construct($appName, $request);
	}

	/** Admin hoặc admin nhóm (hai loại người vào được trang Accounts). */
	#[NoAdminRequired]
	public function index(): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null || (!$this->groupManager->isAdmin($user->getUID())
			&& !$this->groupManager->getSubAdmin()->isSubAdmin($user))) {
			return new JSONResponse(['error' => 'forbidden'], Http::STATUS_FORBIDDEN);
		}

		$cache = $this->cacheFactory->createDistributed(Application::APP_ID);
		$data = $cache->get('expiry-map');
		if (!is_array($data)) {
			$cfg = $this->config->getSystemValue('datadrive_sso', []);
			$secret = is_array($cfg) ? (string)($cfg['secret'] ?? '') : '';
			$home = is_array($cfg) ? rtrim((string)($cfg['home'] ?? 'https://datadrive.vn'), '/') : 'https://datadrive.vn';
			$ts = (string)time();
			try {
				$res = $this->clientService->newClient()->get($home . '/wp-json/datadrive/v1/expiry-map', [
					'query' => ['ts' => $ts, 'sig' => hash_hmac('sha256', 'expiry-map|' . $ts, $secret)],
					'timeout' => 8,
				]);
				$data = json_decode((string)$res->getBody(), true);
			} catch (\Throwable $e) {
				$this->logger->warning('Khong lay duoc han goi tu datadrive.vn: ' . $e->getMessage(), ['app' => Application::APP_ID]);
				return new JSONResponse(['error' => 'upstream'], Http::STATUS_BAD_GATEWAY);
			}
			if (!is_array($data) || !isset($data['users'])) {
				return new JSONResponse(['error' => 'upstream'], Http::STATUS_BAD_GATEWAY);
			}
			$cache->set('expiry-map', $data, self::CACHE_TTL);
		}
		return new JSONResponse(['now' => time(), 'users' => $data['users']]);
	}
}
