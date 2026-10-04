<?php

declare(strict_types=1);

/**
 * datadrive.vn - mau email tuy bien (dung qua config.php: 'mail_template_class').
 *
 * Mau goc (lib/private/Mail/EMailTemplate.php) dat logo trong hinh tron nen mau chu dao
 * va gioi han logo 105x105 px -> logo ngang bi thu nho, chu khong doc duoc.
 * Lop nay chi thay phan dau email: khung bo goc nen trang xanh nhat, logo xanh rong 300 px.
 * Cac phan con lai (tieu de, noi dung, nut, chan email) giu nguyen cua Nextcloud.
 */

namespace OCA\DatadriveMail;

use OC\Mail\EMailTemplate as BaseEMailTemplate;

class EMailTemplate extends BaseEMailTemplate {
	private const BANNER_BG = '#EEF6FC';
	// Anh goc 840x240 (ti le 3.5:1), hien thi 300x86 -> net tren man hinh do phan giai cao
	private const LOGO_WIDTH = 300;
	private const LOGO_HEIGHT = 86;

	protected string $header = <<<EOF
<table align="center" class="wrapper header float-center" style="Margin:0 auto;background:#fff;border-collapse:collapse;border-spacing:0;float:none;margin:0 auto;padding:0;text-align:center;vertical-align:top;width:100%%">
	<tr style="padding:0;text-align:left;vertical-align:top">
		<td class="wrapper-inner" style="Margin:0;border-collapse:collapse!important;padding:24px 20px 0;text-align:center;vertical-align:top">
			<table align="center" class="container" style="Margin:0 auto;border-collapse:collapse;border-spacing:0;margin:0 auto;padding:0;text-align:center;vertical-align:top;width:580px;max-width:100%%">
				<tbody>
				<tr style="padding:0;text-align:left;vertical-align:top">
					<td align="center" bgcolor="%s" style="Margin:0;background-color:%s;border-collapse:collapse!important;border-radius:16px;padding:32px 24px;text-align:center;vertical-align:middle">
						<img class="logo float-center" src="%s" alt="%s" width="%d" height="%d" align="center" style="-ms-interpolation-mode:bicubic;border:0;clear:both;display:block;float:none;height:auto;margin:0 auto;max-width:100%%;outline:0;text-decoration:none;width:%dpx">
					</td>
				</tr>
				</tbody>
			</table>
		</td>
	</tr>
</table>
<table class="spacer float-center" style="Margin:0 auto;border-collapse:collapse;border-spacing:0;float:none;margin:0 auto;padding:0;text-align:center;vertical-align:top;width:100%%">
	<tbody>
	<tr style="padding:0;text-align:left;vertical-align:top">
		<td height="32px" style="Margin:0;border-collapse:collapse!important;font-size:32px;line-height:32px;margin:0;mso-line-height-rule:exactly;padding:0">&#xA0;</td>
	</tr>
	</tbody>
</table>
EOF;

	public function addHeader(): void {
		if ($this->headerAdded) {
			return;
		}
		$this->headerAdded = true;

		// Luon ghep theo overwrite.cli.url (dia chi cong khai), khong theo dia chi cua request dang gui mail:
		// gui tu web qua dia chi noi bo (vd. :30027) se tao link anh ma Gmail khong tai duoc.
		$path = $this->urlGenerator->imagePath('datadrive_mail', 'logo-email.png');
		$publicBase = rtrim(\OCP\Server::get(\OCP\IConfig::class)->getSystemValueString('overwrite.cli.url', ''), '/');
		$logoUrl = $publicBase !== '' ? $publicBase . $path : $this->urlGenerator->getAbsoluteURL($path);
		$this->htmlBody .= vsprintf($this->header, [
			self::BANNER_BG,
			self::BANNER_BG,
			$logoUrl,
			$this->themingDefaults->getName(),
			self::LOGO_WIDTH,
			self::LOGO_HEIGHT,
			self::LOGO_WIDTH,
		]);
	}
}
