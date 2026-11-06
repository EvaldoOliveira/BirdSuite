//##############################################################################
// homepage/static/species-modal.js - SHARED CONFIRMATION MODAL OF THE SPECIES LISTS
//
// PURPOSE:
//   Shared confirmation modal of the species lists (Species Management and the species page, owner
//   2026-10-08): title, what it does / impact, Yes and No; the safe answer is the default (No for whitelist
//   and exclude). var, not const: loaded on every page by common.php ui_js (2026-11-01) and still by some
//   pages themselves
//
// DEPENDENCIES:
//   - (none beyond the system)
//
// USAGE:
//   <script src="static/species-modal.js">, loaded by scripts/backup_import_page.php, scripts/curation.php,
//   scripts/data_page.php, scripts/disk_space.php, scripts/external_files.php, scripts/lib/ui.php,
//   scripts/locations.php; named in homepage/static/curation.js, homepage/static/location-data.js,
//   homepage/static/review-player.js, homepage/static/species-page.js, homepage/static/species-tools.js
//##############################################################################

var SPECIES_TOGGLE_TEXT = {
	confirmed: { add: [t('str_confirm_name'), t('str_what_it_does_marks_the_species_help') + '\n\n' + t('str_impact_a_validation_marker_only_help')],
							 del: [t('str_remove_the_confirmation_of_name'), t('str_what_it_does_the_species_is_no_help') + '\n\n' + t('str_impact_a_validation_marker_only_help')] },
	whitelist: { add: [t('str_whitelist_name'), t('str_what_it_accepted_even_help') + '\n\n' + t('str_impact_it_can_be_detected_all_help')],
							 del: [t('str_remove_name_from_the_whitelist'), t('str_what_it_does_the_location_help') + '\n\n' + t('str_impact_it_is_only_detected_in_help')] },
	exclude:   { add: [t('str_exclude_name'), t('str_what_it_never_detected_help') + '\n\n' + t('str_impact_no_new_detections_help')],
							 del: [t('str_detect_name_again'), t('str_what_it_does_the_species_leaves_help') + '\n\n' + t('str_impact_it_is_detected_again_help')] },
};

// noLabel: the other button's text (default No); '' = no second button, a notice with one button
function askModal(title, text, okLabel, defaultNo = false, noLabel) {
	return new Promise(resolve => {
		let m = document.getElementById('spModal');
		if (!m) {
			m = document.createElement('div');
			m.id = 'spModal';
			// a real dialog for a screen reader, named by its title (release run 5, NEW-A11Y-R5-03)
			m.setAttribute('role', 'dialog');
			m.setAttribute('aria-modal', 'true');
			m.setAttribute('aria-labelledby', 'spModalTitle');
			m.setAttribute('aria-describedby', 'spModalText');
			m.innerHTML = '<div class="spm-box"><div class="spm-head"><h3 id="spModalTitle"></h3></div>'
				+ '<p id="spModalText"></p><div class="spm-buttons"><button id="spModalCancel">' + t('str_no') + '</button><button id="spModalOk"></button></div></div>';
			document.body.appendChild(m);
			const st = document.createElement('style');
			st.textContent = '#spModal{position:fixed;inset:0;background:rgba(0,0,0,.45);display:flex;align-items:center;justify-content:center;z-index:1000}'
				+ '#spModal .spm-box{background:var(--panel,#fff);color:var(--text,#000);border-radius:10px;max-width:460px;width:calc(100% - 32px);overflow:hidden;box-shadow:0 6px 24px rgba(0,0,0,.35);text-align:left;font-size:14px}'
				+ '#spModal .spm-head{display:flex;align-items:center;gap:10px;background:var(--accent,#2b5e22);color:#fff;padding:10px 14px}'
				+ '#spModal .spm-head img{width:26px;height:26px}#spModal h3{margin:0;font-size:16px;color:#fff;text-align:left}'
				+ '#spModal p{margin:0;padding:14px 16px;white-space:pre-line;line-height:1.4}'
				+ '#spModal .spm-buttons{display:flex;justify-content:flex-end;gap:8px;padding:0 16px 14px}'
				+ '#spModal .spm-buttons button{min-width:72px;padding:6px 14px;border-radius:14px;border:1px solid var(--accent,#2b5e22);background:#fff;color:var(--accent,#2b5e22);font-weight:600;cursor:pointer;width:auto}'
				+ '#spModal .spm-buttons button.primary{background:var(--accent,#2b5e22);color:#fff}'
				+ '#spModal .spm-buttons button:focus{outline:2px solid #d97a00;outline-offset:1px}';
			document.head.appendChild(st);
		}
		document.getElementById('spModalTitle').textContent = title;
		document.getElementById('spModalText').textContent = text;
		const yes = document.getElementById('spModalOk'), no = document.getElementById('spModalCancel');
		yes.textContent = okLabel;
		no.textContent = noLabel === undefined ? t('str_no') : noLabel;
		no.style.display = noLabel === '' ? 'none' : '';
		yes.className = defaultNo ? '' : 'primary';
		no.className = defaultNo ? 'primary' : '';
		// the focus goes back to what opened the question when it closes, and Tab stays inside it while it is open
		const opener = document.activeElement;
		m.style.display = 'flex';
		(defaultNo ? no : yes).focus();
		const done = v => {
			m.style.display = 'none';
			document.removeEventListener('keydown', key);
			if (opener && opener !== document.body && opener.isConnected && typeof opener.focus === 'function') opener.focus();
			resolve(v);
		};
		const key = e => {
			if (e.key === 'Escape') { done(false); return; }
			if (e.key !== 'Tab') return;
			const f = Array.prototype.filter.call(m.querySelectorAll('button, input, select, textarea, a[href], [tabindex]:not([tabindex="-1"])'),
				el => !el.disabled && el.offsetParent !== null);
			if (!f.length) return;
			const i = f.indexOf(document.activeElement);
			if (e.shiftKey && i <= 0) { e.preventDefault(); f[f.length - 1].focus(); }
			else if (!e.shiftKey && (i === -1 || i === f.length - 1)) { e.preventDefault(); f[0].focus(); }
		};
		document.addEventListener('keydown', key);
		yes.onclick = () => done(true);
		no.onclick = () => done(false);
		m.onclick = e => { if (e.target === m) done(false); };
	});
}

// a notice with one OK button (never the browser's alert box); resolves when it is closed
function bsNotice(text) {
	return askModal(String(text == null ? '' : text), '', t('str_ok'), false, '');
}
// a question with a text box (never the browser's prompt box): resolves with the text, or null when cancelled
function bsPrompt(text, value) {
	const p = askModal(text, '', t('str_ok'), false, t('str_cancel'));
	const box = document.getElementById('spModalText');
	const inp = document.createElement('input');
	inp.type = 'text';
	inp.value = value == null ? '' : value;
	inp.setAttribute('aria-label', String(text == null ? '' : text));
	inp.style.cssText = 'width:100%;box-sizing:border-box';
	inp.onkeydown = e => { if (e.key === 'Enter') { e.preventDefault(); document.getElementById('spModalOk').click(); } };
	box.appendChild(inp);
	inp.focus();
	inp.select();
	return p.then(ok => ok ? inp.value : null);
}
// a question with a picklist (owner 2026-11-05: delete a saved list or name): resolves with the value chosen, or null
function bsPick(text, values, okLabel) {
	const p = askModal(text, '', okLabel || t('str_ok'), true, t('str_cancel'));
	const box = document.getElementById('spModalText');
	const sel = document.createElement('select');
	sel.style.cssText = 'width:100%;box-sizing:border-box';
	sel.setAttribute('aria-label', String(text == null ? '' : text));
	values.forEach(v => { const o = document.createElement('option'); o.textContent = v; sel.appendChild(o); });
	box.appendChild(sel);
	sel.focus();
	return p.then(ok => ok ? sel.value : null);
}
