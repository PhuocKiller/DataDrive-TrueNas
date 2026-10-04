#!/usr/bin/env python3
"""Tao file ngon ngu cho theme datadrive: thay chu "Nextcloud" (va "vWorkspace") trong ban dich.

Chay lai sau MOI lan nang cap Nextcloud (tren may TrueNAS):
    sudo python3 <html>/themes/datadrive/build-l10n.py <html>            # ghi file
    sudo python3 <html>/themes/datadrive/build-l10n.py <html> --dry-run  # chi xem truoc

Co che (Nextcloud 34):
  - PHP  <theme>/<core|lib|apps/x>/l10n/<lang>.json : duoc GOP sau file goc -> chi can chuoi thay doi.
  - JS   <theme>/core/core/l10n/<lang>.js va <theme>/<app>/l10n/<lang>.js : THAY CA file goc
         (JSResourceLocator dung o file dau tien tim thay) -> phai la ban sao DAY DU.
Khong sua bat ky file nao ngoai thu muc theme.
"""
import glob
import json
import os
import re
import sys

ROOT = next((a for a in sys.argv[1:] if not a.startswith('--')), '/var/www/html').rstrip('/')
DRY = '--dry-run' in sys.argv
THEME = os.path.join(ROOT, 'themes', 'datadrive')
BRAND = 'datadrive.vn'
EN_PLURAL = 'nplurals=2; plural=(n != 1);'

# (regex, thay cho tieng Anh, thay cho tieng Viet). None = khong ap dung cho ngon ngu do.
# Thu tu quan trong: quy tac cu the dung truoc, quy tac chung "Nextcloud" -> BRAND dung sau cung.
RULES = [
	(r'Nextcloud GmbH', 'the provider', 'nhà cung cấp'),
	(r'\bNC GmbH\b', 'the provider', 'nhà cung cấp'),
	(r'\bA Nextcloud Enterprise', 'An Enterprise', None),
	(r'Nextcloud Enterprise', 'Enterprise', 'Enterprise'),
	(r'Nextcloud All-in-One', 'All-in-One', 'All-in-One'),
	(r'Nextcloud subscription', 'subscription', 'subscription'),
	(r'thuê bao Nextcloud', None, 'thuê bao'),
	(r'Nextcloud community', 'community', 'community'),
	(r'[Cc]ộng đồng Nextcloud', None, 'cộng đồng'),
	(r'Nextcloud (Talk|Text|Assistant|Mail|Calendar|Hub)\b', r'\1', r'\1'),
	(r'Trợ lý Nextcloud', None, 'Trợ lý'),
	(r'#Nextcloud ', '', ''),
	(r'user@your-nextcloud\.org', 'user@' + BRAND, 'user@' + BRAND),
	(r'người_dùng@nextcloud_của_bạn\.org', None, 'nguoidung@' + BRAND),
	(r'Nextcloud(\s*(?:<=|>=|<|>)?\s*)(?=\d|%)', r'server\1', r'máy chủ\1'),
	(r'\bnextcloud log\b', 'server log', 'server log'),
	(r'Nextcloud', BRAND, BRAND),
	(r'vWorkspace', BRAND, BRAND),
]


def apply(value, lang):
	if isinstance(value, list):
		return [apply(v, lang) for v in value]
	if not isinstance(value, str):
		return value
	for pattern, en, vi in RULES:
		repl = en if lang == 'en' else vi
		if repl is not None:
			value = re.sub(pattern, repl, value)
	return value


def en_value(key):
	"""Gia tri tieng Anh cua mot key. Key so nhieu co dang _singular_::_plural_."""
	if '::' in key and key.startswith('_'):
		return [p[1:-1] if p.startswith('_') and p.endswith('_') else p for p in key.split('::')]
	return key


def load(path):
	try:
		with open(path, encoding='utf-8') as f:
			return json.load(f)
	except (OSError, ValueError):
		return None


def targets():
	yield 'core', 'core/l10n'
	yield 'lib', 'lib/l10n'
	for base in ('apps', 'custom_apps'):
		for d in sorted(glob.glob(f'{ROOT}/{base}/*/l10n')):
			yield os.path.basename(os.path.dirname(d)), os.path.relpath(d, ROOT)


def js_register(app, translations, plural):
	body = json.dumps(translations, ensure_ascii=False, indent=4, sort_keys=True)
	return f'OC.L10N.register(\n    {json.dumps(app)},\n    {body},\n{json.dumps(plural)});\n'


OWNER = os.stat(os.path.join(ROOT, 'occ'))
written = []


def write(path, content):
	written.append(os.path.relpath(path, ROOT))
	if DRY:
		return
	os.makedirs(os.path.dirname(path), exist_ok=True)
	with open(path, 'w', encoding='utf-8') as f:
		f.write(content)
	os.chmod(path, 0o644)
	os.chown(path, OWNER.st_uid, OWNER.st_gid)


def clean_old():
	"""Xoa file ngon ngu do script nay tao lan truoc (chi trong thu muc theme)."""
	for path in glob.glob(f'{THEME}/**/l10n/*', recursive=True):
		if os.path.basename(path) in ('en.json', 'vi.json', 'en.js', 'vi.js') and not DRY:
			os.remove(path)


def main():
	clean_old()
	report = []
	for app, rel in targets():
		src = os.path.join(ROOT, rel)
		vi_orig = load(f'{src}/vi.json')
		keys = set()
		for name in ('en_GB.json', 'vi.json'):
			data = load(f'{src}/{name}')
			if data:
				keys.update(data.get('translations', {}).keys())
		if not keys:
			continue

		en_changes = {}
		for k in keys:
			v = en_value(k)
			nv = apply(v, 'en')
			if nv != v:
				en_changes[k] = nv

		vi_changes = {}
		vi_trans = (vi_orig or {}).get('translations', {})
		vi_plural = (vi_orig or {}).get('pluralForm', 'nplurals=1; plural=0;')
		for k, v in vi_trans.items():
			nv = apply(v, 'vi')
			if nv != v:
				vi_changes[k] = nv
		for k, v in en_changes.items():
			if not vi_trans.get(k):
				vi_changes[k] = v  # chua co ban dich: dung tieng Anh da thay chu

		if not en_changes and not vi_changes:
			continue
		report.append((app, len(en_changes), len(vi_changes)))

		php_dir = os.path.join(THEME, rel)
		if en_changes:
			write(f'{php_dir}/en.json', json.dumps({'translations': en_changes, 'pluralForm': EN_PLURAL}, ensure_ascii=False, indent=4, sort_keys=True))
		if vi_changes:
			write(f'{php_dir}/vi.json', json.dumps({'translations': vi_changes, 'pluralForm': vi_plural}, ensure_ascii=False, indent=4, sort_keys=True))

		if app == 'lib':
			continue  # lib chi dung phia PHP
		js_dir = os.path.join(THEME, 'core', 'core', 'l10n') if app == 'core' else os.path.join(THEME, app, 'l10n')
		if en_changes:
			write(f'{js_dir}/en.js', js_register(app, en_changes, EN_PLURAL))
		if vi_changes:
			write(f'{js_dir}/vi.js', js_register(app, {**vi_trans, **vi_changes}, vi_plural))

		if DRY:
			for k, v in sorted(en_changes.items()):
				print(f'[{app}] EN: {k[:110]!r}\n        -> {str(v)[:110]!r}')
			for k, v in sorted(vi_changes.items()):
				if vi_trans.get(k):
					print(f'[{app}] VI: {str(vi_trans[k])[:110]!r}\n        -> {str(v)[:110]!r}')

	print(f"\n{'app':28} {'en':>4} {'vi':>4}")
	for app, e, v in report:
		print(f'{app:28} {e:>4} {v:>4}')
	print(f"Tong: {sum(r[1] for r in report)} chuoi en, {sum(r[2] for r in report)} chuoi vi, {len(report)} app; {len(written)} file {'(chay thu, CHUA ghi)' if DRY else 'da ghi'}")


if __name__ == '__main__':
	main()
