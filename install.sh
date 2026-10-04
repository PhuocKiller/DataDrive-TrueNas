#!/usr/bin/env bash
# Cai MOI DataDrive tren TrueNAS (25.10+, apps chay docker): tao app catalog Nextcloud
# voi ten instance "datadrive", roi ap rebrand. Chay TREN may TrueNAS:
#
#   git clone <repo> && cd datadrive-truenas-app
#   sudo ./install.sh
#
# Dieu kien: da tao pool va chon pool cho Apps (Apps -> Configuration -> Choose Pool).
set -euo pipefail

APP="${APP:-datadrive}"
HERE="$(cd "$(dirname "$0")" && pwd)"
[ "$(id -u)" = 0 ] || { echo "Chay bang sudo"; exit 1; }
if [ ! -f "$HERE/.env" ]; then
	# Khong can tu soan .env: hoi vai cau roi tu ghi file.
	rnd() { head -c 24 /dev/urandom | base64 | tr -dc 'A-Za-z0-9' | head -c 24; }
	read -rp "Ten tai khoan quan tri [admin]: " u
	while :; do read -rsp "Mat khau quan tri (bat buoc): " p; echo; [ -n "$p" ] && break; done
	read -rp "DD_SSO_SECRET trong wp-config.php cua datadrive.vn (Enter = bo qua): " s
	read -rp "May chu SMTP (Enter = bo qua email): " sh_; su_=; m=
	if [ -n "$sh_" ]; then read -rp "Tai khoan SMTP (email): " su_; read -rsp "Mat khau SMTP: " m; echo; fi
	umask 077
	cat > "$HERE/.env" <<EOF
NC_ADMIN_USER='${u:-admin}'
NC_ADMIN_PASSWORD='$p'
NC_DB_PASSWORD='$(rnd)'
NC_REDIS_PASSWORD='$(rnd)'
DD_SSO_SECRET='$s'
SMTP_HOST='$sh_'
SMTP_USER='$su_'
SMTP_PASSWORD='$m'
EOF
	echo "Da luu vao $HERE/.env"
fi
set -a; . "$HERE/.env"; set +a
: "${NC_ADMIN_PASSWORD:?thieu trong .env}" "${NC_DB_PASSWORD:?thieu trong .env}" "${NC_REDIS_PASSWORD:?thieu trong .env}"

if midclt call app.query "[[\"name\",\"=\",\"$APP\"]]" | grep -q "\"name\": \"$APP\""; then
	echo "App $APP da ton tai - bo qua buoc tao."
else
	echo "== Tao app $APP tu catalog nextcloud (stable)"
	PAYLOAD="$(APP="$APP" python3 - "$HERE/app-values.json" <<'PY'
import json, os, sys
v = json.load(open(sys.argv[1]))
e = os.environ
n = v["nextcloud"]
n["admin_user"] = e.get("NC_ADMIN_USER", "admin")
n["admin_password"] = e["NC_ADMIN_PASSWORD"]
n["db_password"] = e["NC_DB_PASSWORD"]
n["redis_password"] = e["NC_REDIS_PASSWORD"]
n["host"] = e.get("NC_HOST", "storage.datadrive.vn") + ":443"
v["network"]["web_port"]["port_number"] = int(e.get("NC_PORT", "30027"))
cid = e.get("NC_CERTIFICATE_ID", "")
v["network"]["certificate_id"] = int(cid) if cid else None
print(json.dumps({"app_name": e["APP"], "catalog_app": "nextcloud", "train": "stable",
                  "version": "latest", "values": v}))
PY
)"
	midclt call -j app.create "$PAYLOAD" >/dev/null
fi

echo "== Cho Nextcloud cai xong"
CT="ix-$APP-nextcloud-1"
for i in $(seq 1 90); do
	docker exec -u www-data "$CT" php occ status 2>/dev/null | grep -q "installed: true" && break
	sleep 10
	[ "$i" = 90 ] && { echo "Qua 15 phut chua xong - xem log: docker logs $CT"; exit 1; }
done

APP="$APP" "$HERE/apply-rebrand.sh"
echo
echo "Con lai (lam tay): xem README.md muc 'Sau khi cai'."
