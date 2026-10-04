NC 34.0.3 + PostgreSQL bug: SQLSTATE[42702] Ambiguous column "file_id"
File: lib/private/Preview/Db/PreviewMapper.php
Nguyen nhan: getPreviewForSpecification() va getByFileId() dung WHERE file_id khong co alias,
trong khi joinLocation() join oc_preview_versions - bang nay CUNG co cot file_id.
Hau qua: GET /index.php/core/preview tra HTTP 500 (thumbnail hong tren app iOS/web).
Fix: qualify cot bang alias p. (xem preview-ambiguous-file_id.patch)
Patch ngay 2026-09-25. PHAI AP DUNG LAI SAU MOI LAN UPDATE NEXTCLOUD
(giong theme datadrive + build-l10n.py).
Cach khoi phuc: cp PreviewMapper.php.orig ve /var/www/html/lib/private/Preview/Db/PreviewMapper.php
