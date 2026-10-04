<?php

/**
 * Theme datadrive.vn - ghi de thong tin thuong hieu cua OC_Defaults (lib/private/legacy/OC_Defaults.php).
 *
 * Luu y: app "theming" dang bat va boc lop nay, nen getName/getTitle/getEntity/getSlogan/
 * getShortFooter/getBaseUrl/getDocBaseUrl hien thi thuc te lay tu cau hinh theming (occ theming:config).
 * Cac method ben duoi van dinh nghia day du de dung khi theming bi tat.
 */
class OC_Theme {
	private const BRAND = 'datadrive.vn';
	private const SITE = 'https://datadrive.vn';

	public function getBaseUrl(): string {
		return 'https://storage.datadrive.vn';
	}

	public function getDocBaseUrl(): string {
		return self::SITE;
	}

	public function buildDocLinkToKey($key): string {
		return self::SITE;
	}

	public function getSyncClientUrl(): string {
		return self::SITE;
	}

	public function getAndroidClientUrl(): string {
		return self::SITE;
	}

	public function getiOSClientUrl(): string {
		return self::SITE;
	}

	public function getFDroidClientUrl(): string {
		return self::SITE;
	}

	public function getiTunesAppId(): string {
		// De trong de trinh duyet iPhone khong goi y cai app Nextcloud chinh thuc
		return '';
	}

	public function getTitle(): string {
		return self::BRAND;
	}

	public function getName(): string {
		return self::BRAND;
	}

	public function getHTMLName(): string {
		return self::BRAND;
	}

	public function getEntity(): string {
		return self::BRAND;
	}

	public function getProductName(): string {
		return self::BRAND;
	}

	public function getSlogan(?string $lang = null): string {
		return 'Secure Cloud Storage';
	}

	public function getShortFooter(): string {
		return '<a href="' . self::SITE . '" target="_blank" rel="noreferrer noopener">' . self::BRAND . '</a> – Secure Cloud Storage';
	}

	public function getLongFooter(): string {
		return $this->getShortFooter();
	}
}
