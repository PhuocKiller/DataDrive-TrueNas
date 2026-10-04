# DataDrive trên TrueNAS

Gói rebrand Nextcloud → **DataDrive** (storage.datadrive.vn) cho app Nextcloud của TrueNAS
(25.10+, apps chạy Docker). Dùng để cài lại từ đầu hoặc áp lại rebrand sau khi update Nextcloud.

Repo này **chỉ chứa giao diện + cấu hình**, không chứa dữ liệu khách, database hay mật khẩu.

## Cài mới trên TrueNAS trắng

1. Tạo pool, vào **Apps → Configuration → Choose Pool**.
2. (Nên làm) Tạo chứng chỉ TLS cho `storage.datadrive.vn` ở **Credentials → Certificates**, ghi lại ID.
3. SSH vào TrueNAS:

```bash
git clone <URL-repo> datadrive-truenas-app && cd datadrive-truenas-app
sudo ./install.sh
```

Script hỏi tên + mật khẩu quản trị (và tuỳ chọn: khoá SSO, máy chủ + tài khoản SMTP), còn lại tự sinh.

App sẽ xuất hiện trong TrueNAS với tên **datadrive** (container `ix-datadrive-nextcloud-1`).

## Áp lại sau mỗi lần update Nextcloud

Update ghi đè file gốc nên phải chạy lại (theme, file ngôn ngữ, patch PreviewMapper):

```bash
sudo ./apply-rebrand.sh                 # app tên "datadrive"
sudo APP=nextcloud ./apply-rebrand.sh   # app đang chạy hiện tại (tên cũ "nextcloud")
```

## Sau khi cài (làm tay)

- `.env` → `DD_SSO_SECRET` phải trùng `DD_SSO_SECRET` trong `wp-config.php` của datadrive.vn.
- `wp-config.php`: `DD_NC_LAN_IP` = IP LAN của TrueNAS, `DD_NC_LAN_PORT` = 30027.
- Router: forward 443 → `<IP TrueNAS>:30027`.
- Thêm `<IP TrueNAS>:30027` vào `trusted_domains` nếu gọi thẳng qua LAN.
- Khôi phục dữ liệu khách + database từ bản sao lưu (không nằm trong repo này).

## Nội dung

| Thư mục / file | Là gì |
|---|---|
| `install.sh` | Tạo app từ catalog Nextcloud với tên `datadrive`, rồi gọi `apply-rebrand.sh` |
| `apply-rebrand.sh` | Áp toàn bộ rebrand, chạy lại được nhiều lần |
| `app-values.json` | Cấu hình app TrueNAS (port 30027, nginx timeout 1800s, RAM 4G, cron 5 phút) |
| `theme/datadrive/` | Theme: `defaults.php`, `core/css/server.css`, `build-l10n.py` + file ngôn ngữ en/vi |
| `custom_apps/datadrive_mail/` | Mẫu email có logo DataDrive |
| `custom_apps/datadrive_sso/` | Đăng nhập một lần từ datadrive.vn, khoá/hết hạn tài khoản |
| `custom_apps/jsloader/` | App JSLoader 2.1.0 (bên thứ ba, AGPL) |
| `jsloader-snippet.js` | Snippet: tự làm mới danh sách file, logo, cột trang Accounts |
| `theming-images/` | Logo, logo header, favicon, ảnh nền đăng nhập |
| `patches/` | Sửa bug Postgres `ambiguous file_id` ở `PreviewMapper.php` (NC 34.0.3) |

## Lưu ý

- Tên **Nextcloud**, logo và nút *Convert to custom app* trong trang Apps của TrueNAS lấy từ
  catalog của TrueNAS, chỉ quản trị viên thấy, không đổi được. Chỉ tên instance (`datadrive`) là tự đặt.
- Bản đã kiểm: TrueNAS 25.10.7, app Nextcloud 2.3.63, Nextcloud 34.0.3.
- Patch `PreviewMapper.php` chỉ áp nếu khớp; bản Nextcloud mới hơn có thể đã tự sửa.
