//##############################################################################
// homepage/static/see.js - NOW: THE LIVE DETECTIONS, THE PLAYER AND THE REVIEW
//
// PURPOSE:
//   The script of Now (scripts/see.php): the live list of detections, the player, the review from the page.
//
// DEPENDENCIES:
//   - static/custom-audio-player.js, static/review-player.js
//
// USAGE:
//   <script src="static/see.js"> in scripts/see.php
//##############################################################################

(function () {
	var grid = document.getElementById('seegrid'), empty = document.getElementById('seeempty'), sel = document.getElementById('seeconf'), per = document.getElementById('seeperiod');
	var cards = {}, seq = 0, first = true;
	var size = document.getElementById('seesize'), root = document.querySelector('.see');
	function setSize(v) { root.style.setProperty('--seesize', v + 'px'); root.classList.toggle('seesmall', parseInt(v, 10) < 140); }
	try { var sz = localStorage.getItem('see_size'); if (sz) size.value = sz; } catch (e) {}
	setSize(size.value);
	size.oninput = function () { setSize(size.value); try { localStorage.setItem('see_size', size.value); } catch (e) {} };
	// pictures only (clean, the default) or with the name and time, kept in this browser (owner 2026-11-01)
	var clean = document.getElementById('seeclean');
	try { var cl = localStorage.getItem('see_clean'); if (cl !== null) clean.checked = cl === '1'; } catch (e) {}
	root.classList.toggle('seeclean', clean.checked);
	clean.onchange = function () { root.classList.toggle('seeclean', clean.checked); try { localStorage.setItem('see_clean', clean.checked ? '1' : '0'); } catch (e) {} };
	// the background picture (owner 2026-11-02): the icon opens the pictures saved on the station; a click uses one for
	// this location, Upload adds one (made at most 1920 px in the browser, a JPEG, so a phone photo fits the upload),
	// Clear leaves the location without one; the pictures stay saved, to swap back and forth
	var bgIcon = document.getElementById('seebgicon'), bgFile = document.getElementById('seebgfile'), bgModal = document.getElementById('seeBgModal');
	var bgUrl = 'scripts/see.php?loc=' + root.dataset.loc + '&bg=';
	function bgFail(e) { askModal(t('str_background_picture'), t('str_not_saved_reason', {reason: e.message}), t('str_ok'), false, ''); }
	function bgSend(url, body) {
		return fetch(url, {method: 'POST', body: body, credentials: 'same-origin'}).then(function (r) {
			return r.text().then(function (txt) {
				if (r.ok && txt.trim() === 'OK') return;
				// the login page or a server error page (Gitea #74): its message, never the raw HTML
				if (r.status === 401) throw new Error(t('str_log_in_first'));
				throw new Error(txt.trim().charAt(0) === '<' ? String(r.status) : (txt || r.status));
			});
		});
	}
	// the picture and veil for the picker's choice: from the station after a change, and with every refresh of the cards
	function bgApply(st) {
		if (!st || (st.url === root.dataset.bg && String(st.fade) === root.dataset.fade)) return;
		root.dataset.bg = st.url; root.dataset.fade = String(st.fade);
		root.classList.toggle('seebg', !!st.url);
		if (st.url) root.style.setProperty('--seebgimg', 'url("' + st.url + '")'); else root.style.removeProperty('--seebgimg');
		fade.value = 100 - st.fade; bgVeil(100 - st.fade);
	}
	function bgState() { return fetch(bgUrl + 'state', {credentials: 'same-origin'}).then(function (r) { if (!r.ok) throw new Error(r.status); return r.json(); }).then(bgApply); }
	// in All a change goes to every location except Test / Playback: asked first
	function bgConfirm() {
		if (root.dataset.loc !== 'all') return Promise.resolve(true);
		return askModal(t('str_background_picture'), t('str_all_the_picture_goes_to_every_help'), t('str_continue'), false, t('str_cancel'));
	}
	function bgList() {
		var box = document.getElementById('seebglist');
		fetch(bgUrl + 'list', {credentials: 'same-origin'}).then(function (r) { if (!r.ok) throw new Error(r.status); return r.json(); }).then(function (rows) {
			if (!Array.isArray(rows)) rows = [];
			box.innerHTML = rows.length ? '' : '<em>' + t('str_no_picture_saved_yet_upload_one') + '</em>';
			rows.forEach(function (r) {
				var i = document.createElement('img');
				i.src = r.url; i.alt = r.name; i.title = r.name; i.tabIndex = 0;
				if (r.current) i.className = 'cur';
				var use = function () { bgConfirm().then(function (ok) { if (ok) bgSend(bgUrl + 'use&name=' + encodeURIComponent(r.name), '').then(bgState).then(bgClose).catch(bgFail); }); };
				i.onclick = use;
				i.onkeydown = function (e) { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); use(); } };
				box.appendChild(i);
			});
		}).catch(function (e) { box.innerHTML = '<em>' + t('str_not_saved_reason', {reason: e.message}).replace(/</g, '&lt;') + '</em>'; });
	}
	function bgKey(e) { if (e.key === 'Escape') bgClose(); }
	function bgClose() { bgModal.style.display = 'none'; document.removeEventListener('keydown', bgKey); bgIcon.focus(); }
	function bgOpen() {
		document.getElementById('seebgclear').style.display = root.classList.contains('seebg') ? '' : 'none';
		bgModal.style.display = 'flex';
		document.addEventListener('keydown', bgKey);
		document.getElementById('seebgupload').focus();
		bgList();
	}
	// Prominence (owner 2026-11-02): a veil of the page colour over the picture, 100 % = the picture as it is; seen at
	// once while sliding, saved for this location when let go
	var fade = document.getElementById('seebgfade'), fadeV = document.getElementById('seebgfadev');
	function bgVeil(p) { root.style.setProperty('--seebgveil', 'color-mix(in srgb, var(--bg, #77c487) ' + (100 - p) + '%, transparent)'); fadeV.textContent = p + '%'; }
	bgVeil(100 - parseInt(root.dataset.fade || '0', 10));
	window.seeBgApply = bgApply;  // the refresh of the cards passes the X-See-Bg header
	fade.oninput = function () { bgVeil(parseInt(fade.value, 10)); };
	fade.onchange = function () { bgSend(bgUrl + 'fade&v=' + (100 - parseInt(fade.value, 10)), '').catch(bgFail); };
	bgModal.onclick = function (e) { if (e.target === bgModal) bgClose(); };
	document.getElementById('seebgclose').onclick = bgClose;
	document.getElementById('seebgupload').onclick = function () { bgConfirm().then(function (ok) { if (ok) bgFile.click(); }); };
	document.getElementById('seebgclear').onclick = function () { bgConfirm().then(function (ok) { if (ok) bgSend(bgUrl + 'clear', '').then(bgState).then(bgClose).catch(bgFail); }); };
	bgIcon.onclick = bgOpen;
	bgIcon.onkeydown = function (e) { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); bgOpen(); } };
	bgFile.onchange = function () {
		var f = bgFile.files && bgFile.files[0];
		bgFile.value = '';
		if (!f) return;
		var img = new Image(), u = URL.createObjectURL(f);
		img.onload = function () {
			var k = Math.min(1, 1920 / Math.max(img.naturalWidth, img.naturalHeight)), cv = document.createElement('canvas');
			cv.width = Math.round(img.naturalWidth * k); cv.height = Math.round(img.naturalHeight * k);
			cv.getContext('2d').drawImage(img, 0, 0, cv.width, cv.height);
			URL.revokeObjectURL(u);
			cv.toBlob(function (b) {
				// a picture too big for the browser's memory gives no file
				if (!b) { askModal(t('str_background_picture'), t('str_not_an_image_jpeg_png_or_webp_help'), t('str_ok'), false, ''); return; }
				bgSend(bgUrl + 'set', b).then(bgState).then(bgClose).catch(bgFail);
			}, 'image/jpeg', 0.85);
		};
		img.onerror = function () { URL.revokeObjectURL(u); askModal(t('str_background_picture'), t('str_not_an_image_jpeg_png_or_webp_help'), t('str_ok'), false, ''); };
		img.src = u;
	};
	try { var saved = localStorage.getItem('see_conf'); if (saved && sel.querySelector('option[value="' + saved + '"]')) sel.value = saved;
		saved = localStorage.getItem('see_period'); if (saved && per.querySelector('option[value="' + saved + '"]')) per.value = saved; } catch (e) {}
	// the station not answering (Gitea #74): the note above the cards, which stay as they are, and no "no bird" meanwhile
	var off = document.getElementById('seeoff');
	function refreshEmpty() { empty.hidden = grid.children.length > 0 || !off.hidden; }
	function offline(on) { if (off.hidden === !on) return; off.hidden = !on; refreshEmpty(); }
	function show(r, ms) {
		var c = cards[r.sci];
		if (!c) {
			var a = document.createElement('div');
			a.className = 'seecard';
			a.appendChild(document.createElement('img'));
			a.appendChild(document.createElement('b'));
			a.appendChild(document.createElement('small'));
			var pic = a.querySelector('img');
			// a picture that does not load (no internet, a moved file): the BirdSuite logo instead of a broken image
			pic.onerror = function () { if (pic.getAttribute('src') !== 'images/BirdNetBr.png') pic.src = 'images/BirdNetBr.png'; };
			c = cards[r.sci] = {el: a};
			pic.tabIndex = 0;
			pic.setAttribute('role', 'button');
			// the shared review player (static/review-player.js) on the card's last detection: a fresh item each time, so a
			// species that sang again is reviewed on its newest clip
			var review = function () {
				if (typeof openReviewPlayer !== 'function') return;
				// the card's newest detection as the item, its attributes from common.php review_item_attrs()
				Object.keys(a.dataset).forEach(function (k) { delete a.dataset[k]; });
				var tmp = document.createElement('div');
				tmp.innerHTML = '<div' + c.ri + '></div>';
				var src = tmp.firstChild.dataset;
				Object.keys(src).forEach(function (k) { a.dataset[k] = src[k]; });
				openReviewPlayer(pic, {returnTo: location.href});  // back to Now (the return point of the call)
			};
			// the picture opens the review (owner 2026-11-01: no Review button)
			pic.onclick = review;
			pic.onkeydown = function (e) { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); review(); } };
		}
		var img = c.el.querySelector('img');
		img.alt = r.com;
		img.title = img.ariaLabel = t('str_review') + ': ' + r.com;
		if (img.dataset.src !== (r.img || 'none')) { img.dataset.src = r.img || 'none'; img.src = r.img || 'images/BirdNetBr.png'; }  // no picture: the BirdSuite logo (owner 2026-11-02)
		c.el.querySelector('b').textContent = r.com;
		c.el.querySelector('small').textContent = r.time + ' · ' + r.conf + '%';
		keep(c, r);
		c.until = Date.now() + ms;
		c.el.classList.remove('seenew');
		void c.el.offsetWidth;  // the highlight runs again when the same card sings again
		c.el.classList.add('seenew');
		if (!c.el.parentNode) grid.appendChild(c.el);
	}
	// the card's detection, the one its picture opens in the review player
	function keep(c, r) { c.key = r.key; c.clip = r.clip; c.file = r.file; c.ri = r.ri; c.last = r.last; }
	// always by the last detection, the newest top left: a clip may reach the database after a newer one of another species
	function order() {
		var list = Object.keys(cards).map(function (s) { return cards[s]; }).sort(function (a, b) { return a.last < b.last ? 1 : (a.last > b.last ? -1 : 0); });
		list.forEach(function (c, i) { if (grid.children[i] !== c.el) grid.insertBefore(c.el, grid.children[i] || null); });
	}
	// while the review player is open the page stands still: no card leaves or moves under it, and closing it finds the
	// page as it was (owner 2026-11-01); it catches up right after
	function reviewing() { return !!document.getElementById('rpDialog'); }  // the player removes its box on close
	// an answer given in the player (owner 2026-11-01): the cards are read again, so a detection answered No / a cause /
	// Review later leaves at once (its species stays only with an older detection that still qualifies)
	document.addEventListener('rp:closed', function (e) {
		setTimeout(function () {
			if (e.detail && e.detail.answered) { Object.keys(cards).forEach(function (s) { cards[s].el.remove(); }); cards = {}; first = true; lastBody = null; }
			expire(); load(true);
		}, 0);
	});
	function expire() {
		if (reviewing()) return;
		Object.keys(cards).forEach(function (s) { if (cards[s].until <= Date.now()) { cards[s].el.remove(); delete cards[s]; } });
		refreshEmpty();
	}
	// one question at a time with a 15 s timeout (2026-11-04 hardening: a slow station piled up one every 5 s); a change
	// of period or confidence drops the running one, its answer would be for the old choice
	// the cards' answer of the last poll: the same one again (the station's ETag, Gitea #75) leaves the grid alone
	var pending = null, lastBody = null;
	function load(fresh) {
		if (document.hidden || reviewing()) return;
		if (pending) { if (!fresh) return; pending.abort(); }
		var mine = ++seq, x = pending = new XMLHttpRequest();
		x.timeout = 15000;
		x.onloadend = function () { if (pending === x) pending = null; };
		// no answer, a timeout, an error or a broken answer (Gitea #74): the cards stay, the note says so, the next poll tries again
		x.onerror = x.ontimeout = function () { if (mine === seq) offline(true); };
		x.onload = function () {
			if (mine !== seq) return;
			if (this.status !== 200) { offline(true); return; }
			// the player opened while the poll was out: the grid stands still under it, closing it reads the cards again
			if (reviewing()) return;
			var rows, body = this.responseText;
			try { rows = JSON.parse(body); } catch (e) { rows = null; }
			if (!Array.isArray(rows)) { offline(true); return; }
			offline(false);
			try { if (window.seeBgApply) window.seeBgApply(JSON.parse(this.getResponseHeader('X-See-Bg') || 'null')); } catch (e) {}
			document.getElementById('seerec').hidden = this.getResponseHeader('X-See-Rec') !== '0';
			if (body === lastBody) return;
			lastBody = body;
			var period = parseInt(per.value, 10) * 60000, now = parseInt(this.getResponseHeader('X-See-Now') || '', 10), seen = {};
			// On opening a card has what is left of its period; a new song all of it
			rows.forEach(function (r) {
				seen[r.sci] = true;
				r.age = isNaN(now) ? 0 : Math.max(0, now - r.ts);
				var c = cards[r.sci];
				if (c && c.key === r.key) return;
				// its newest detection was answered No, a cause or Review later elsewhere: the card opens the one that now
				// qualifies, without a new period (the review item never points at a rejected clip)
				if (c && r.last < c.last) { keep(c, r); c.el.querySelector('small').textContent = r.time + ' · ' + r.conf + '%'; return; }
				var left = period - r.age * 1000;
				if (!first && r.age < 120) left = period;
				if (left > 0) show(r, left);
			});
			// a species with no detection left that qualifies leaves
			Object.keys(cards).forEach(function (s) { if (!seen[s]) { cards[s].el.remove(); delete cards[s]; } });
			first = false;
			expire();
			order();
		};
		x.open('GET', 'scripts/see.php?data=1&loc=' + encodeURIComponent(root.dataset.loc) + '&conf=' + (parseInt(sel.value, 10) / 100) + '&min=' + per.value, true);
		x.send();
	}
	sel.onchange = per.onchange = function () {
		try { localStorage.setItem('see_conf', sel.value); localStorage.setItem('see_period', per.value); } catch (e) {}
		Object.keys(cards).forEach(function (s) { cards[s].el.remove(); });
		cards = {}; first = true; lastBody = null; load(true);
	};
	load();
	setInterval(load, 5000);
	setInterval(expire, 1000);
	// back on a tab that slept: what is on screen may be old
	document.addEventListener('visibilitychange', function () { if (!document.hidden) { expire(); load(); } });
})();
