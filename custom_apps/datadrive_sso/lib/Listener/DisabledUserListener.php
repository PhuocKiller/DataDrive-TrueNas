<?php

declare(strict_types=1);

namespace OCA\DatadriveSso\Listener;

use OC\Authentication\Token\IProvider;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\User\Events\UserChangedEvent;
use Psr\Log\LoggerInterface;

/**
 * Khoa tai khoan (vd. het han goi tren datadrive.vn) -> thu hoi MOI ma dang nhap cua app.
 *
 * Neu chi disable, token cu cua app van xac thuc duoc va WebDAV tra 503 "Account disabled"
 * -> app hieu la server bao tri va thu lai mai. Thu hoi token thi moi request tra 401
 * -> app tu dua ve man hinh dang nhap; dang nhap lai thi Nextcloud bao tai khoan bi vo hieu hoa.
 * @template-implements IEventListener<UserChangedEvent>
 */
class DisabledUserListener implements IEventListener {
	public function __construct(
		private IProvider $tokens,
		private LoggerInterface $logger,
	) {
	}

	public function handle(Event $event): void {
		if (!($event instanceof UserChangedEvent) || $event->getFeature() !== 'enabled' || $event->getValue() !== false) {
			return;
		}
		$uid = $event->getUser()->getUID();
		// Tung token mot: invalidateTokenById xoa ca cache token (TTL 10s) -> 401 ngay, khong co 10s tra 503.
		foreach ($this->tokens->getTokenByUser($uid) as $token) {
			$this->tokens->invalidateTokenById($uid, $token->getId());
		}
		$this->logger->info('datadrive_sso: da thu hoi token cua user bi khoa ' . $uid, ['app' => 'datadrive_sso']);
	}
}
