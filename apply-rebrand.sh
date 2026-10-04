#!/usr/bin/env bash
# Ap toan bo rebrand DataDrive len app Nextcloud cua TrueNAS (chay TREN may TrueNAS, bang sudo).
# Chay lai duoc nhieu lan (idempotent). PHAI chay lai sau MOI lan update Nextcloud.
#
#   sudo ./apply-rebrand.sh              # app ten "datadrive" (cai moi)
#   sudo APP=nextcloud ./apply-rebrand.sh   # app dang chay hien tai (ten cu)
set -euo pipefail

APP="${APP:-datadrive}"
HERE="$(cd "$(dirname "$0")" && pwd)"
HTML="/mnt/.ix-apps/app_mounts/$APP/html"
CT="ix-$APP-nextcloud-1"

[ "$(id -u)" = 0 ] || { echo "Chay bang sudo"; exit 1; }
[ -d "$HTML" ] || { echo "Khong thay $HTML - sai ten APP?"; exit 1; }
docker inspect "$CT" >/dev/null 2>&1 || { echo "Khong thay container $CT"; exit 1; }
[ -f "$HERE/.env" ] && { set -a; . "$HERE/.env"; set +a; }

occ() { docker exec -u www-data "$CT" php occ "$@"; }
sys() { occ config:system:set "$@" >/dev/null; }

echo "== 1. Theme + custom apps"
mkdir -p "$HTML/themes" "$HTML/custom_apps"
rm -rf "$HTML/themes/datadrive"
cp -a "$HERE/theme/datadrive" "$HTML/themes/datadrive"
for a in datadrive_mail datadrive_sso jsloader; do
	rm -rf "$HTML/custom_apps/$a"
	cp -a "$HERE/custom_apps/$a" "$HTML/custom_apps/$a"
done
chown -R 33:33 "$HTML/themes/datadrive" "$HTML/custom_apps"

echo "== 2. Patch PreviewMapper.php (bug Postgres ambiguous file_id)"
PM="$HTML/lib/private/Preview/Db/PreviewMapper.php"
if patch -R --dry-run -s "$PM" "$HERE/patches/preview-ambiguous-file_id.patch" >/dev/null 2>&1; then
	echo "   da patch roi - bo qua"
elif patch --dry-run -s "$PM" "$HERE/patches/preview-ambiguous-file_id.patch" >/dev/null 2>&1; then
	cp -a "$PM" "$PM.orig-$(date +%Y%m%d)"
	patch -s "$PM" "$HERE/patches/preview-ambiguous-file_id.patch"
	echo "   da patch"
else
	echo "   !! patch KHONG khop voi ban NC nay - kiem tra tay (xem patches/README.txt)"
fi

echo "== 3. Sinh file ngon ngu (thay chu Nextcloud)"
python3 "$HTML/themes/datadrive/build-l10n.py" "$HTML" | tail -n 2
chown -R 33:33 "$HTML/themes/datadrive"

echo "== 4. Bat/tat app"
for a in datadrive_mail datadrive_sso jsloader; do occ app:enable "$a" >/dev/null || echo "   !! khong bat duoc $a"; done
for a in firstrunwizard nextcloud_announcements survey_client updatenotification recommendations support weather_status dashboard; do
	occ app:disable "$a" >/dev/null 2>&1 || true
done

echo "== 5. Cau hinh he thong"
sys theme --value=datadrive
sys defaultapp --value=files
sys skeletondirectory --value=""
sys templatedirectory --value=""
sys simpleSignUpLink.shown --type=boolean --value=false
sys knowledgebaseenabled --type=boolean --value=false
sys appstoreenabled --type=boolean --value=false
sys updatechecker --type=boolean --value=false
sys overwrite.cli.url --value="https://${NC_HOST:-storage.datadrive.vn}"
sys overwriteprotocol --value=https
sys customclient_desktop --value=https://datadrive.vn
sys mail_template_class --value='\OCA\DatadriveMail\EMailTemplate'
sys remember_login_cookie_lifetime --type=integer --value=2592000
sys files.chunked_upload.max_size --type=integer --value=10485760
sys files.chunked_upload.max_parallel_count --type=integer --value=3
sys datadrive_sso login --value=https://datadrive.vn/dang-nhap/
sys datadrive_sso logout_back --value='https://datadrive.vn/wp-admin/admin-post.php?action=dd_logout_back'
sys datadrive_sso home --value=https://datadrive.vn/
if [ -n "${DD_SSO_SECRET:-}" ]; then sys datadrive_sso secret --value="$DD_SSO_SECRET"
else echo "   !! DD_SSO_SECRET trong (.env) - SSO tu datadrive.vn se khong chay"; fi
occ background:cron >/dev/null

if [ -n "${SMTP_PASSWORD:-}" ]; then
	echo "== 5b. Email (SMTP)"
	sys mail_smtpmode --value=smtp
	sys mail_sendmailmode --value=smtp
	sys mail_smtpsecure --value=ssl
	sys mail_smtpauth --type=boolean --value=true
	sys mail_smtpport --type=integer --value="${SMTP_PORT:-465}"
	sys mail_smtphost --value="${SMTP_HOST:?thieu SMTP_HOST}"
	sys mail_smtpname --value="${SMTP_USER:?thieu SMTP_USER}"
	sys mail_smtppassword --value="$SMTP_PASSWORD"
	sys mail_from_address --value="${SMTP_USER%%@*}"
	sys mail_domain --value="${SMTP_USER##*@}"
else
	echo "   (SMTP_PASSWORD trong - giu nguyen cau hinh email hien co)"
fi

echo "== 6. Theming (ten, mau, logo, nen)"
th() { occ theming:config "$@" >/dev/null; }
th name "datadrive.vn"
th url "https://${NC_HOST:-storage.datadrive.vn}"
th slogan "Secure Cloud Storage"
th privacyUrl "https://datadrive.vn/privacy"
th imprintUrl "https://datadrive.vn/terms"
th primary_color "#0A93E0"
th background_color "#EEF6FC"
th disable-user-theming yes
docker exec "$CT" mkdir -p /tmp/dd-theming
for f in background.png favicon.png logo.svg logoheader.svg; do
	docker cp -q "$HERE/theming-images/$f" "$CT:/tmp/dd-theming/$f"
done
docker exec "$CT" chown -R 33:33 /tmp/dd-theming
for k in background:png favicon:png logo:svg logoheader:svg; do
	th "${k%%:*}" "/tmp/dd-theming/${k%%:*}.${k##*:}"
done
docker exec "$CT" rm -rf /tmp/dd-theming

echo "== 7. JSLoader snippet"
docker cp -q "$HERE/jsloader-snippet.js" "$CT:/tmp/dd-snippet.js"
docker exec -u www-data "$CT" sh -c 'php occ config:app:set jsloader snippet --value="$(cat /tmp/dd-snippet.js)" >/dev/null'
docker exec "$CT" rm -f /tmp/dd-snippet.js
occ config:app:set jsloader cachebuster --value="$(date +%s)" >/dev/null

echo "== 8. Repair"
occ maintenance:repair >/dev/null
echo "XONG. productname: $(occ status --output=json | python3 -c 'import json,sys;print(json.load(sys.stdin).get("productname"))')"
