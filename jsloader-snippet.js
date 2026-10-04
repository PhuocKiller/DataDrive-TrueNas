/* DataDrive live refresh: tu cap nhat danh sach file + dung luong khi co thay doi (tu bat ky dau) */
(function () {
	if (window.__ddLive) return
	window.__ddLive = true
	const INTERVAL = 5000
	const user = window.OC?.getCurrentUser?.()
	if (!user?.uid || !/\/apps\/files/.test(location.pathname)) return
	const root = window.OC.linkToRemote('dav') + '/files/' + encodeURIComponent(user.uid) + '/'
	const body = '<?xml version="1.0"?><d:propfind xmlns:d="DAV:" xmlns:oc="http://owncloud.org/ns"><d:prop><d:getetag/><oc:size/></d:prop></d:propfind>'
	let lastEtag = null
	let busy = false

	function findVm(pred) {
		for (const el of document.querySelectorAll('#app-content-vue, #app-content, .files-list, #app-navigation-vue, .app-navigation, [class*="quota"]')) {
			let vm = el.__vue__
			while (vm) {
				if (pred(vm)) return vm
				vm = vm.$parent
			}
		}
		for (const el of document.querySelectorAll('*')) {
			if (el.__vue__ && pred(el.__vue__)) return el.__vue__
		}
		return null
	}

	function refresh(size) {
		const list = findVm(vm => typeof vm.fetchContent === 'function' && 'currentFolder' in vm)
		if (list && !list.loading) list.fetchContent()
		const quota = findVm(vm => typeof vm.updateStorageStats === 'function' && vm.storageStats)
		if (quota && Number.isFinite(size)) {
			const s = { ...quota.storageStats, used: size }
			if (s.quota > 0) {
				s.free = Math.max(0, s.quota - size)
				s.total = s.quota
				s.relative = Math.min(100, Math.round(size / s.quota * 10000) / 100)
			}
			quota.storageStats = s
		}
	}

	async function tick() {
		if (busy || document.hidden) return
		busy = true
		try {
			const r = await fetch(root, {
				method: 'PROPFIND',
				credentials: 'same-origin',
				headers: { Depth: '0', 'Content-Type': 'application/xml', requesttoken: window.OC.requestToken },
				body,
			})
			const xml = await r.text()
			const etag = (xml.match(/<d:getetag>([^<]*)</) || [])[1]
			const size = Number((xml.match(/<oc:size>(\d+)</) || [])[1])
			if (etag && lastEtag && etag !== lastEtag) refresh(size)
			if (etag) lastEtag = etag
		} catch (e) {
			// mat mang tam thoi: bo qua, lan sau thu lai
		} finally {
			busy = false
		}
	}

	setInterval(tick, INTERVAL)
	document.addEventListener('visibilitychange', tick)
	window.addEventListener('focus', tick)
	tick()
})()

/* Logo goc trai: bam vao quay ve trang chu datadrive.vn (mac dinh cua Nextcloud la ve /apps/files) */
;(function () {
	const HOME = 'https://datadrive.vn/'
	function fixLogo() {
		const a = document.getElementById('nextcloud')
		if (!a || a.dataset.ddHome === '1') return
		a.setAttribute('href', HOME)
		a.setAttribute('aria-label', 'Về trang chủ datadrive.vn')
		a.removeAttribute('target')
		a.dataset.ddHome = '1'
	}
	fixLogo()
	document.addEventListener('DOMContentLoaded', fixLogo)
	// Giao dien Vue dung lai header nen phai theo doi thay doi DOM.
	new MutationObserver(fixLogo).observe(document.documentElement, { childList: true, subtree: true })
})()


/* Trang Accounts (/settings/users):
 * - keo mep tieu de de doi do rong cot (bam dup = vua noi dung); 4 cot Display name / Account name / Email / Quota tu vua noi dung khi vao trang
 * - thu tu cot: ... Email, Quota, Ngay het han, Groups ...
 * - cot "Ngay het han" lay tu datadrive.vn qua /apps/datadrive_sso/expiry (khong co trong danh sach / never_expires = Unlimited) */
;(function () {
	if (!/\/settings\/users/.test(location.pathname) || window.__ddColResize) return
	window.__ddColResize = true
	const STORE = 'dd-user-list-col-widths'
	const AUTO = ['displayname', 'username', 'email', 'quota']
	const CELL_KEY = { languages: 'language' } // header "languages" <-> o "language"
	const SKIP = ['avatar', 'actions']
	const ORDER = ['avatar', 'displayname', 'username', 'email', 'quota', 'expiry', 'groups', 'subadmins', 'languages', 'storage-location', 'first-login', 'last-login', 'manager']
	const MIN = 60
	const MAX_AUTO = 480
	let widths = { expiry: 150 }
	try { Object.assign(widths, JSON.parse(localStorage.getItem(STORE)) || {}) } catch (e) {}
	const dragged = new Set() // cot da keo tay trong lan vao trang nay -> khong tu can lai
	const style = document.createElement('style')
	document.head.appendChild(style)
	const cellKey = k => CELL_KEY[k] || k
	const sel = k => `[data-cy-user-list-header-${k}],[data-cy-user-list-cell-${cellKey(k)}]`

	function render() {
		style.textContent = 'th.header__cell{position:relative}' +
			'.dd-col-resizer{position:absolute;top:0;bottom:0;inset-inline-end:-4px;width:9px;cursor:col-resize;z-index:5;touch-action:none}' +
			'.dd-col-resizer::after{content:"";position:absolute;top:25%;bottom:25%;left:4px;width:1px;background:var(--color-border-dark)}' +
			'.dd-col-resizer:hover::after,.dd-col-resizer.active::after{top:0;bottom:0;width:3px;left:3px;background:var(--color-primary-element)}' +
			'[data-cy-user-list-header-actions],[data-cy-user-list-cell-actions]{order:99}' +
			'.dd-exp--warn .dd-exp__left{color:var(--color-warning-text)}.dd-exp--bad .dd-exp__left{color:var(--color-error-text);font-weight:600}' +
			ORDER.map((k, i) => `${sel(k)}{order:${i}}`).join('') +
			Object.entries(widths).map(([k, w]) =>
				`${sel(k)}{width:${w}px!important;min-width:${w}px!important;max-width:${w}px!important;flex:none!important}`,
			).join('')
	}
	function save() {
		const keep = {}
		for (const k in widths) if (!AUTO.includes(k)) keep[k] = widths[k]
		try { localStorage.setItem(STORE, JSON.stringify(keep)) } catch (e) {}
	}
	function keyOf(th) {
		for (const a of th.attributes) {
			const m = a.name.match(/^data-cy-user-list-header-(.+)$/)
			if (m) return m[1]
		}
		return null
	}
	function scopeAttr(el) {
		for (const a of el.attributes) if (a.name.startsWith('data-v-')) return a.name
		return null
	}
	// do chieu dai THAT cua chu (the chu trong o co the gian bang o nen khong dung scrollWidth)
	const range = document.createRange()
	function naturalWidth(el) {
		let w = 0
		for (const c of el.querySelectorAll('strong, span, label')) {
			range.selectNodeContents(c)
			w = Math.max(w, range.getBoundingClientRect().width)
		}
		const cs = getComputedStyle(el)
		return w + parseFloat(cs.paddingLeft) + parseFloat(cs.paddingRight) + 6
	}
	function fit(k) {
		const cells = document.querySelectorAll(sel(k))
		if (!cells.length) return false
		delete widths[k]
		render()
		let w = MIN
		for (const c of cells) w = Math.max(w, naturalWidth(c))
		widths[k] = Math.min(MAX_AUTO, Math.ceil(w))
		render()
		return true
	}
	let fitTimer = null
	function autoFit() {
		clearTimeout(fitTimer)
		fitTimer = setTimeout(() => {
			for (const k of AUTO) if (!dragged.has(k)) fit(k)
		}, 150)
	}

	/* ---- cot Ngay het han ---- */
	let expiry = null // null = dang tai, false = loi, object = {now, users}
	let fetchedAt = 0
	async function loadExpiry() {
		try {
			const r = await fetch(window.OC.generateUrl('/apps/datadrive_sso/expiry'), {
				credentials: 'same-origin', headers: { requesttoken: window.OC.requestToken, Accept: 'application/json' },
			})
			if (!r.ok) throw new Error(r.status)
			const d = await r.json()
			d.skew = d.now - Date.now() / 1000
			expiry = d
		} catch (e) {
			if (!expiry) expiry = false
		}
		fetchedAt = Date.now()
		syncExpiry()
	}
	function fmtDate(ts) {
		return new Date(ts * 1000).toLocaleDateString('vi-VN', { timeZone: 'Asia/Ho_Chi_Minh', day: '2-digit', month: '2-digit', year: 'numeric' })
	}
	function expiryView(uid) {
		if (expiry === null) return { main: '…' }
		if (expiry === false) return { main: '—', sub: 'Không tải được' }
		const u = expiry.users[uid]
		if (!u || u.never || !u.expires) return { main: 'Unlimited' }
		const left = u.expires - (Date.now() / 1000 + expiry.skew)
		const date = fmtDate(u.expires)
		if (u.status === 'locked') return { main: date, sub: 'Đã khoá', cls: 'bad' }
		if (u.status === 'expired' || left <= 0) return { main: date, sub: 'Đã hết hạn', cls: 'bad' }
		const d = Math.floor(left / 86400)
		const sub = d >= 1 ? `còn ${d} ngày` : `còn ${Math.max(1, Math.floor(left / 3600))} giờ`
		return { main: date, sub, cls: d < 7 ? 'warn' : '' }
	}
	function syncExpiry() {
		const qh = document.querySelector('th[data-cy-user-list-header-quota]')
		if (qh && !qh.parentNode.querySelector('th[data-cy-user-list-header-expiry]')) {
			const th = qh.cloneNode(true)
			th.removeAttribute('data-cy-user-list-header-quota')
			th.setAttribute('data-cy-user-list-header-expiry', '')
			th.querySelector('.dd-col-resizer')?.remove()
			th.querySelector('span').textContent = 'Ngày hết hạn'
			qh.parentNode.appendChild(th)
		}
		for (const tr of document.querySelectorAll('tr.user-list__row')) {
			const q = tr.querySelector(':scope > td[data-cy-user-list-cell-quota]')
			if (!q) continue
			let td = tr.querySelector(':scope > td[data-cy-user-list-cell-expiry]')
			if (!td) {
				td = q.cloneNode(false)
				td.removeAttribute('data-cy-user-list-cell-quota')
				td.setAttribute('data-cy-user-list-cell-expiry', '')
				tr.appendChild(td)
			}
			const uid = tr.querySelector('[data-cy-user-list-cell-username] .row__subtitle')?.textContent.trim() || ''
			const v = expiryView(uid)
			const key = uid + '|' + v.main + '|' + (v.sub || '') + '|' + (v.cls || '')
			if (td.dataset.ddKey === key) continue
			td.dataset.ddKey = key
			const sa = scopeAttr(td)
			td.className = 'row__cell dd-exp' + (v.cls ? ' dd-exp--' + v.cls : '')
			if (sa) td.setAttribute(sa, '') // giu style scoped cua Vue
			td.textContent = ''
			const main = document.createElement('span')
			main.textContent = v.main
			td.appendChild(main)
			if (v.sub) {
				const sub = document.createElement('span')
				sub.className = 'row__subtitle dd-exp__left'
				sub.textContent = v.sub
				td.appendChild(sub)
			}
			if (sa) for (const s of td.children) s.setAttribute(sa, '')
		}
	}

	function addHandles() {
		for (const th of document.querySelectorAll('th.header__cell')) {
			const k = keyOf(th)
			if (!k || SKIP.includes(k) || th.querySelector(':scope > .dd-col-resizer')) continue
			const h = document.createElement('div')
			h.className = 'dd-col-resizer'
			h.title = 'Kéo để đổi độ rộng cột · bấm đúp để vừa nội dung'
			h.addEventListener('pointerdown', ev => {
				ev.preventDefault()
				ev.stopPropagation()
				h.setPointerCapture(ev.pointerId)
				h.classList.add('active')
				document.body.style.userSelect = 'none'
				const rtl = getComputedStyle(th).direction === 'rtl'
				const x0 = ev.clientX
				const w0 = th.getBoundingClientRect().width
				const move = e => {
					widths[k] = Math.max(MIN, Math.round(w0 + (rtl ? x0 - e.clientX : e.clientX - x0)))
					dragged.add(k)
					render()
				}
				const up = () => {
					h.classList.remove('active')
					document.body.style.userSelect = ''
					h.removeEventListener('pointermove', move)
					h.removeEventListener('pointerup', up)
					h.removeEventListener('pointercancel', up)
					save()
				}
				h.addEventListener('pointermove', move)
				h.addEventListener('pointerup', up)
				h.addEventListener('pointercancel', up)
			})
			h.addEventListener('click', ev => ev.stopPropagation())
			h.addEventListener('dblclick', ev => {
				ev.stopPropagation()
				dragged.add(k)
				fit(k)
				save()
			})
			th.appendChild(h)
		}
	}
	render()
	loadExpiry()
	setInterval(() => { if (!document.hidden && Date.now() - fetchedAt > 120000) loadExpiry() }, 30000)
	setInterval(syncExpiry, 60000) // cap nhat "con N ngay/gio"
	let sig = ''
	let busy = false
	new MutationObserver(() => {
		if (busy) return
		busy = true
		try {
			syncExpiry()
			addHandles()
		} finally {
			busy = false
		}
		// danh sach tai xong / tai them / doi nhom -> can lai cac cot tu vua
		const n = location.pathname + '|' + document.querySelectorAll('.user-list__row').length
		if (n !== sig) { sig = n; autoFit() }
	}).observe(document.documentElement, { childList: true, characterData: true, subtree: true })
})()
