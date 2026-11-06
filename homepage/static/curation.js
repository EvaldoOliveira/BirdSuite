//##############################################################################
// homepage/static/curation.js - REVIEW: CALIBRATE THRESHOLDS, THE REVIEW QUEUE, THE SORTS AND THE LOW PROBABILITY FILTER
//
// PURPOSE:
//   The script of Review (scripts/curation.php): Calibrate thresholds, the species queue of the review, the
//   column sorts and titles, the Low Prob. filter and the recent reviews.
//
// DEPENDENCIES:
//   - static/species-sort.js, static/std-table.js, static/name-mode.js, static/species-modal.js, static/precision.js
//
// USAGE:
//   <script src="static/curation.js"> in scripts/curation.php, after window.BS_CURATION = {...}
//##############################################################################

// Calibrate thresholds (owner 2026-10-28): the reviews of every species (curation.php?calib=1) through the same method
// as the species page's Precision (static/precision.js); listed = a suggestion that differs from the threshold in force,
// raised when the precision there is below the target, lowered when the target is already reached below it
var curCalData = null;
function curCalibrate() {
	var box = document.getElementById('curcal');
	box.hidden = false;
	document.getElementById('curcalmsg').textContent = '';
	document.getElementById('curcalout').innerHTML = '<p>' + t('str_loading') + '</p>';
	var x = new XMLHttpRequest();
	var failed = function () { document.getElementById('curcalout').innerHTML = '<p>' + t('str_not_loaded') + '</p>'; };
	x.timeout = 30000;
	x.onerror = x.ontimeout = failed;
	x.onload = function () {
		var d = null;
		try { d = this.status === 200 ? JSON.parse(this.responseText) : null; } catch (e) {}
		if (!d || !Array.isArray(d.species)) { failed(); return; }
		curCalData = d;
		curCalDraw();
	};
	x.open('GET', 'scripts/curation.php?calib=1', true);
	x.send();
}
function curCalClose() { document.getElementById('curcal').hidden = true; }
document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && !document.getElementById('curcal').hidden) curCalClose(); });
function curCalPct(v) { return v === null || v === undefined ? '-' : window.fmtNum(Math.round(v * 100)) + '%'; }
function curCalAt(s, th, target) {
	// the precision line of one threshold: the table's steps plus that value
	var P = window.bsPrecision, steps = P.confSteps().filter(function (v) { return Math.abs(v - th) > 1e-9; }).concat([th]).sort(function (a, b) { return a - b; });
	var c = P.curve(s.rows, 0, steps, P.countConf(s.nconf), target);
	return c.lines.filter(function (l) { return Math.abs(l.th - th) < 1e-9; })[0];
}
function curCalDraw() {
	if (!curCalData) return;
	var P = window.bsPrecision, target = parseFloat(document.getElementById('curcaltarget').value), list = [], waiting = 0;
	curCalData.species.forEach(function (s) {
		var c = P.curve(s.rows, 0, P.confSteps(), P.countConf(s.nconf), target);
		var now = s.own !== null ? s.own : curCalData.global;
		if (c.best === null) { waiting++; return; }
		var atBest = c.lines.filter(function (l) { return l.th === c.best; })[0], atNow = curCalAt(s, now, target), why, fine = Math.abs(c.best - now) < 0.005;
		if (fine) why = t('str_already_at_the_threshold_the_help');
		else if (c.best > now) why = t('str_raise_at_the_threshold_in_force_help', {p: curCalPct(atNow ? atNow.prec : null)});
		else why = t('str_lower_the_target_is_already_help', {v: window.fmtNum(c.best, 2), n: window.fmtNum(Math.round(Math.max(0, (atNow ? atNow.lost : 0) - atBest.lost)))});
		why += '<br>' + t('str_reviews_above_it_guaranteed', {n: atBest.y + atBest.f, g: curCalPct(atBest.lb)});
		if (c.lb < target) why += '<br>' + t('str_guaranteed_below_the_target_help');
		list.push({s: s, now: now, best: c.best, prec: atBest.prec, why: why, fine: fine});
	});
	// the new suggestions first, then those already at the threshold; by name inside each
	list.sort(function (a, b) { return (a.fine - b.fine) || a.s.com.localeCompare(b.s.com); });
	var out = document.getElementById('curcalout'), h = function (v) { return String(v).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/"/g, '&quot;'); };
	var html = '', nNew = list.filter(function (r) { return !r.fine; }).length;
	if (list.length) {
		html += '<table class="stdtable" id="curcaltable" data-nosort><tr><th data-nosort><input type="checkbox" id="curcalallcb" onclick="curCalAll(this)" title="' + h(t('str_tick_untick_all')) + '"></th>'
			+ '<th><select class="namemode" title="' + h(t('str_names_shown')) + '"><option value="com">' + t('str_common_name') + '</option><option value="sci">' + t('str_scientific_name') + '</option><option value="en">' + t('str_english_name') + '</option></select></th>'
			+ '<th class="num">' + t('str_right') + '</th><th class="num">' + t('str_false') + '</th><th class="num">' + t('str_in_force') + '</th><th class="num">' + t('str_suggested') + '</th><th class="num">' + t('str_precision') + '</th><th>' + t('str_why') + '</th></tr>';
		list.forEach(function (r) {
			var y = r.s.rows.filter(function (x) { return x[2]; }).length, f = r.s.rows.length - y;
			html += '<tr data-sci="' + h(r.s.sci) + '"' + (r.fine ? ' class="curcalfine"' : '') + '><td><input type="checkbox" class="curcalon"' + (r.fine ? '' : ' checked') + ' onchange="curCalCount()"></td>'
				+ '<td><a href="views.php?view=Species&amp;sci=' + encodeURIComponent(r.s.sci) + '#precision" data-com="' + h(r.s.com) + '" data-sci="' + h(r.s.sci) + '" data-en="' + h(r.s.en || r.s.com) + '">' + h(r.s.com) + '</a></td>'
				+ '<td class="num">' + y + '</td><td class="num">' + f + '</td>'
				+ '<td class="num">' + window.fmtNum(r.now, 2) + (r.s.own === null ? ' <small>' + t('str_station_lc_paren') + '</small>' : '') + '</td>'
				+ '<td class="num"><input type="number" class="curcalv" min="0.01" max="0.99" step="0.01" value="' + r.best.toFixed(2) + '" data-best="' + r.best.toFixed(2) + '" oninput="curCalEdit(this)"></td>'
				+ '<td class="num curcalp">' + curCalPct(r.prec) + '</td><td class="curcalwhy">' + r.why + '</td></tr>';
		});
		html += '</table>';
	} else html += '<p>' + t('str_no_species_reaches_the_criteria_help') + '</p>';
	html += '<p><small>' + t('str_new_suggestions_species_already_help', {a: nNew, b: list.length - nNew, c: waiting}) + '</small></p>';
	out.innerHTML = html;
	if (window.applyNameMode) applyNameMode();
	curCalCount();
}
function curCalAll(cb) { document.querySelectorAll('#curcaltable input.curcalon:not(:disabled)').forEach(function (c) { c.checked = cb.checked; }); curCalCount(); }
function curCalCount() {
	var b = document.getElementById('curcalapply');
	if (!b) return;
	var n = document.querySelectorAll('#curcaltable input.curcalon:checked:not(:disabled)').length;
	b.textContent = t('str_apply_the_ticked_ones', {n: n});
	b.disabled = !n;
	var all = document.getElementById('curcalallcb'), open = document.querySelectorAll('#curcaltable input.curcalon:not(:disabled)').length;
	if (all) { all.checked = open > 0 && n === open; all.indeterminate = n > 0 && n < open; }
}
// a value changed by hand: its precision from the same reviews, the edited value marked
function curCalEdit(inp) {
	var tr = inp.closest('tr'), sci = tr.dataset.sci, v = parseFloat(String(inp.value).replace(',', '.')), s = curCalData.species.filter(function (x) { return x.sci === sci; })[0];
	var ok = s && v >= 0.01 && v <= 0.99;
	inp.style.outline = ok ? (Math.abs(v - parseFloat(inp.dataset.best)) > 1e-9 ? '2px solid #f9a825' : '') : '2px solid #c62828';
	tr.querySelector('.curcalp').textContent = ok ? curCalPct((curCalAt(s, Math.round(v * 100) / 100, parseFloat(document.getElementById('curcaltarget').value)) || {}).prec) : '-';
	var cb = tr.querySelector('input.curcalon');
	if (ok && !cb.disabled && Math.abs(v - parseFloat(inp.dataset.best)) > 1e-9) cb.checked = true;
	curCalCount();
}
function curCalApply(btn) {
	var set = {}, bad = false, trs = [];
	document.querySelectorAll('#curcaltable tr[data-sci]').forEach(function (tr) {
		var on = tr.querySelector('input.curcalon');
		if (!on.checked || on.disabled) return;
		var v = parseFloat(String(tr.querySelector('input.curcalv').value).replace(',', '.'));
		if (!(v >= 0.01 && v <= 0.99)) { bad = true; return; }
		set[tr.dataset.sci] = Math.round(v * 100) / 100; trs.push(tr);
	});
	if (bad) { bsNotice(t('str_invalid_value_0_01_0_99_empty_help')); return; }
	if (!trs.length) return;
	btn.disabled = true;
	var x = new XMLHttpRequest();
	x.onload = function () {
		if (this.status === 200 && this.responseText.trim() === 'OK') {
			trs.forEach(function (tr) {
				var v = set[tr.dataset.sci], s = curCalData.species.filter(function (x) { return x.sci === tr.dataset.sci; })[0];
				if (s) s.own = v;
				tr.classList.add('curcaldone');
				tr.cells[4].textContent = window.fmtNum(v, 2);
				tr.querySelector('input.curcalon').disabled = true;
				var a = document.querySelector('#curtable a[data-sci="' + (window.CSS && CSS.escape ? CSS.escape(tr.dataset.sci) : tr.dataset.sci) + '"]'), c = a && a.closest('tr').querySelector('td.curmin');
				if (c) { c.textContent = window.fmtNum(v, 2); c.dataset.v = v; }
				tr.querySelector('input.curcalv').disabled = true;
			});
			document.getElementById('curcalmsg').textContent = t('str_applied_to_species', {n: trs.length});
			curCalCount();
			// the modal closes and says what was saved (owner 2026-10-30: the greyed rows alone looked like nothing happened)
			curCalClose();
			askModal(t('str_calibrate_thresholds'), t('str_applied_to_species', {n: trs.length}) + '\n' + t('str_the_analysis_uses_the_new_help'), t('str_ok'), false, '');
		} else { btn.disabled = false; bsNotice(t('str_not_saved_reason', {reason: this.status === 401 ? t('str_log_in_first_lc') : this.responseText})); }
	};
	x.onerror = function () { btn.disabled = false; bsNotice(t('str_not_changed_network_error')); };
	x.open('POST', 'scripts/species_tools.php?setconfs=1', true);
	x.setRequestHeader('Content-Type', 'application/json');
	x.send(JSON.stringify(set));
}
// the species of this table, in the order shown (sorted and filtered), from the given row on: the review player goes
// on to the next one when a species is finished (review-player.js reviewQueueNext, owner 2026-10-18)
function curationQueue(tr) {
	var rows = Array.prototype.slice.call(document.querySelectorAll('.curtable tr[data-n]')).filter(function (r) { return r.style.display !== 'none' && getComputedStyle(r).display !== 'none'; });
	var list = rows.map(function (r) { var a = r.querySelector('a[data-sci]'); return {sci: a.dataset.sci, com: a.dataset.com, conf: r.dataset.conf === '1', todo: parseInt(r.dataset.todo, 10) || 0}; });
	try { sessionStorage.setItem('rp_queue', JSON.stringify(list)); } catch (e) {}
}
// Confirmed / Whitelist / Exclude and the notification tier, the same lists and questions as Species Pages; the row is
// updated in place (no reload: the place in the list is kept)
function curPost(url, done) {
	var x = new XMLHttpRequest();
	x.onload = function () { if (this.status === 200 && this.responseText.trim() === 'OK') done(); else bsNotice(this.status === 401 ? t('str_log_in_first') : this.responseText); };
	x.onerror = function () { bsNotice(t('str_not_changed_network_error')); };
	x.open('POST', url, true);
	x.send();
}
// the notification tier of a species (owner 2026-10-29), saved as in Species Cards: a green outline when saved, red with the
// reason in the tooltip and the old value back when refused (not logged in)
function curTier(sci, sel) {
	var x = new XMLHttpRequest();
	x.onload = function () {
		var r = this.responseText.trim(), ok = this.status === 200 && r === 'OK';
		if (ok) sel.dataset.was = sel.value; else if (sel.dataset.was) sel.value = sel.dataset.was;
		sel.parentNode.dataset.v = {muted: 0, normal: 1, rare: 2}[sel.value];
		sel.style.outline = ok ? '2px solid green' : '2px solid red';
		sel.title = ok ? '' : (this.status === 401 ? t('str_log_in_first') : r);
		setTimeout(function () { sel.style.outline = ''; }, ok ? 1500 : 4000);
	};
	x.onerror = function () { if (sel.dataset.was) sel.value = sel.dataset.was; sel.style.outline = '2px solid red'; sel.title = t('str_not_changed_network_error'); };
	x.open('POST', 'scripts/species_tools.php?settier=1&species=' + encodeURIComponent(sci) + '&tier=' + encodeURIComponent(sel.value), true);
	x.send();
}
function curToggle(el) {
	var list = el.dataset.list, id = el.dataset.id, action = el.classList.contains('on') ? 'del' : 'add';
	var parts = id.split('_'), tr = el.closest('tr'), a = tr.querySelector('a[data-com]');
	var name = (a ? a.dataset.com : parts.slice(1).join('_')) + ' (' + parts[0] + ')';
	var tx = (SPECIES_TOGGLE_TEXT[list] || {})[action];
	var go = function () {
		curPost('scripts/species_tools.php?toggle=' + list + '&species=' + encodeURIComponent(id) + '&action=' + action, function () {
			var on = action === 'add';
			el.classList.toggle('on', on);
			el.title = on ? t('str_ticked_click_to_untick') : t('str_click_to_tick');
			el.parentNode.dataset.v = on ? 0 : 1;
			if (list === 'confirmed') tr.dataset.conf = on ? 1 : 0;
			if (list === 'exclude') tr.dataset.excl = on ? 1 : 0;
			if (list === 'whitelist') tr.dataset.wl = on ? 1 : 0;
		});
	};
	if (!tx) { go(); return; }
	askModal(tx[0].replace('NAME', name), tx[1], t('str_yes'), list === 'whitelist' || list === 'exclude').then(function (ok) { if (ok) go(); });
}
// Remove all (owner 2026-10-19): every detection of the species to the Removed folder, after a confirmation (No is the
// default); the row leaves the table
function curRemoveAll(btn) {
	var tr = btn.closest('tr'), a = tr.querySelector('a[data-sci]'), n = parseInt(tr.dataset.n, 10);
	askModal(t('str_delete_all_detections_of', {n: window.fmtNum(n), name: a.dataset.com + ' (' + a.dataset.sci + ')'}),
		t('str_clips_and_spectrograms_go_to_help'),
		t('str_delete_all'), true).then(function (ok) {
		if (!ok) return;
		btn.disabled = true;
		var x = new XMLHttpRequest();
		x.onload = function () {
			if (/^OK \d+/.test(this.responseText)) {
				tr.remove();
				document.querySelectorAll('.curchip').forEach(function (c) {
					c.querySelector('.curcount').textContent = '(' + document.querySelectorAll('#curtable tr.g-' + c.dataset.group).length + ')';
				});
			} else { btn.disabled = false; bsNotice(this.status === 401 ? t('str_log_in_first') : this.responseText); }
		};
		x.onerror = function () { btn.disabled = false; bsNotice(t('str_not_changed_network_error')); };
		x.open('POST', 'play.php?deletespecies=' + encodeURIComponent(a.dataset.sci), true);
		x.send();
	});
}
function curationStart() {
	var rows = Array.prototype.slice.call(document.querySelectorAll('.curtable tr[data-n]')).filter(function (r) { return r.style.display !== 'none' && getComputedStyle(r).display !== 'none'; });
	var first = rows.filter(function (r) { return parseInt(r.dataset.todo, 10) > 0; })[0];
	if (!first) { curationNothingLeft(); return; }
	curationReview(first.querySelector('button.openbtn'));
}
// Validation's filters go with the review, frozen (period, location, the moment the table was drawn): the species page
// lists exactly the rows counted here (common.php validation_source, owner 2026-11-02)
var CUR_SRC = BS_CURATION.src;
function curationReview(btn) {
	var tr = btn.closest('tr'), a = tr.querySelector('a[data-sci]');
	curationQueue(tr);
	curationReturnPoint();
	location.href = 'views.php?view=Species&sci=' + encodeURIComponent(a.dataset.sci) + '&review=1' + CUR_SRC;
}
// the species name opens the same review mode as the Review button (a review started from Curation always goes on to
// the next species when one is finished, owner 2026-10-18)
document.querySelectorAll('.curtable a[data-sci]').forEach(function (a) { a.addEventListener('click', function () { curationQueue(a.closest('tr')); curationReturnPoint(); }); });
// the review's return point (review-player.js rpReturnSet, same sessionStorage key): back to this page, with its filters
function curationReturnPoint() { try { sessionStorage.setItem('rp_return', JSON.stringify({url: location.href, state: null})); } catch (e) {} }
// nothing left to review in the list shown: a notice (owner 2026-10-30), also when a review comes back at the end of it
function curationNothingLeft() {
	askModal(t('str_nothing_left_to_review_in_this_help'), t('str_every_species_of_this_list_has_help') + '\n' + t('str_other_groups_of_the_table_may_help'), t('str_ok'), false, '');
}
// back from a review (review-player.js leave()): the page opens at the species just reviewed (owner 2026-10-20)
(function () {
	var sci = null, done = null;
	try { sci = sessionStorage.getItem('cur_return'); sessionStorage.removeItem('cur_return'); done = sessionStorage.getItem('cur_done'); sessionStorage.removeItem('cur_done'); } catch (e) {}
	if (done) curationNothingLeft();
	if (!sci) return;
	var a = Array.prototype.slice.call(document.querySelectorAll('.curtable a[data-sci]')).filter(function (x) { return x.dataset.sci === sci; })[0];
	if (!a) return;
	var tr = a.closest('tr');
	tr.scrollIntoView({block: 'center'});
	tr.style.outline = '2px solid #1f6feb';
	setTimeout(function () { tr.style.outline = ''; }, 2500);
})();
function undoReview(file, btn) {
	// one click at a time: the button waits for the answer
	if (btn.disabled) return;
	btn.disabled = true;
	var x = new XMLHttpRequest();
	x.onload = function () {
		if (this.status === 200 && this.responseText.trim() === 'OK') { var tr = btn.closest('tr'); tr.style.opacity = .4; btn.textContent = t('str_undone'); }
		else { btn.disabled = false; bsNotice(this.status === 401 ? t('str_log_in_first') : this.responseText); }
	};
	x.onerror = function () { btn.disabled = false; bsNotice(t('str_not_changed_network_error')); };
	x.open('POST', 'play.php?review=' + encodeURIComponent(file) + '&verdict=clear', true);
	x.send();
}
// sort a species table by a column (click the header), as the standard tables (2026-10-22: the table was called t, which
// hid t() — every click threw and nothing sorted); the titles look clickable as on the other tables
document.querySelectorAll('.curtable').forEach(function (tbl) {
	Array.prototype.forEach.call(tbl.rows[0].cells, function (th, i) {
		if (th.dataset.nosort !== undefined || !th.textContent.trim()) return;
		th.classList.add('sortable');
		var asc = false;
		th.onclick = function (e) {
			if (e && e.target.closest('select')) return;
			var rows = Array.prototype.slice.call(tbl.rows, 1);
			asc = !asc;
			// the Group column: groups 1 → 5 in their default order, clicked again 5 → 1 (each group keeps its own order)
			if (th.textContent.trim() === t('str_group')) {
				var def = document.querySelector('.hmsort button[data-sort="cur"]');
				if (def) def.click();
				if (!asc) {
					rows = Array.prototype.slice.call(tbl.rows, 1);
					rows.sort(function (a, b) { return (parseFloat(b.dataset.grp) - parseFloat(a.dataset.grp)) || (rows.indexOf(a) - rows.indexOf(b)); });
					rows.forEach(function (r) { tbl.tBodies[0].appendChild(r); });
				}
				return;
			}
			rows.sort(function (a, b) {
				var x = a.cells[i].dataset.v || a.cells[i].textContent, y = b.cells[i].dataset.v || b.cells[i].textContent;
				var nx = parseFloat(String(x).replace(/[^0-9.\-]/g, '')), ny = parseFloat(String(y).replace(/[^0-9.\-]/g, ''));
				var c = (!isNaN(nx) && !isNaN(ny)) ? nx - ny : String(x).localeCompare(String(y));
				return asc ? c : -c;
			});
			rows.forEach(function (r) { tbl.tBodies[0].appendChild(r); });
		};
	});
});
// a legend entry hides / shows its group (remembered in this browser)
function curHide(chip, set) {
	var id = chip.dataset.group, on = set === undefined ? !chip.classList.contains('off') : set;
	chip.classList.toggle('off', on);
	document.getElementById('curtable').classList.toggle('hide-' + id, on);
	if (set === undefined) try { localStorage.setItem('curhide:' + id, on ? '1' : '0'); } catch (e) {}
}
// US-132: the hidden groups are filters of the line too: folding shows every group, unfolding hides the same ones again
(window.bsFilterParts = window.bsFilterParts || []).push({
	bar: '.curlegend',
	state: function () { return Array.prototype.slice.call(document.querySelectorAll('.curchip.off')).map(function (c) { return c.dataset.group; }); },
	active: function () { return !!document.querySelector('.curchip.off'); },
	clear: function () { document.querySelectorAll('.curchip.off').forEach(function (c) { curHide(c, false); }); },
	restore: function (ids) { document.querySelectorAll('.curchip').forEach(function (c) { curHide(c, ids.indexOf(c.dataset.group) >= 0); }); }
});
document.querySelectorAll('.curchip').forEach(function (c) {
	var on = false;
	try { on = localStorage.getItem('curhide:' + c.dataset.group) === '1'; } catch (e) {}
	if (on) curHide(c, true);
});
// the species still to review are low probability or not reviewed yet by the threshold picked (remembered)
var CUR_ORDERS = BS_CURATION.orders;
var CUR_TITLES = BS_CURATION.titles;
function curRegroup(save) {
	var sel = document.getElementById('curlowthr'), pct = parseFloat(String(sel.value).replace(',', '.').replace('%', ''));
	if (isNaN(pct) || pct < 0 || pct > 100) { sel.style.outline = '2px solid #c62828'; return; }
	sel.style.outline = '';
	sel.value = pct;
	var thr = pct / 100;
	document.querySelectorAll('#curtable tr[data-work="1"]').forEach(function (r) {
		var g = parseFloat(r.dataset.prob) >= thr ? 'curtodo' : 'curlow';
		r.className = 'g-' + g;
		r.dataset.grp = g === 'curlow' ? 0 : 1;
		r.dataset.order = CUR_ORDERS[g];
		var gc = r.querySelector('td.curgrp');
		if (gc) { gc.textContent = CUR_TITLES[g]; gc.dataset.v = r.dataset.grp; }
	});
	document.querySelectorAll('.curchip').forEach(function (c) {
		c.querySelector('.curcount').textContent = '(' + document.querySelectorAll('#curtable tr.g-' + c.dataset.group).length + ')';
	});
	if (window.speciesSortRefresh) window.speciesSortRefresh();
}
// Low Prob. opens on its default every time (owner 2026-10-20); a change applies until the next visit
