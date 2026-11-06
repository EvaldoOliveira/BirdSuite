//##############################################################################
// homepage/static/species-page.js - THE SPECIES PAGE: LIST SWITCHES, THE REVIEW, THE CALENDAR AND THE PLAYERS
//
// PURPOSE:
//   The script of one species page (scripts/species_page.php): the list switches with the shared modal, the
//   review, the calendar scroll and one player at a time.
//
// DEPENDENCIES:
//   - static/species-modal.js, static/detection-actions.js, static/review-player.js, static/wipe-deleted.js, static/precision.js
//
// USAGE:
//   <script src="static/species-page.js"> in scripts/species_page.php, after window.BS_SPECIES = {...}
//##############################################################################

// list switches: the same modal and texts as the Species Pages list (static/species-modal.js); whitelist and exclude
// default to No
function spList(box, list, species) {
	var action = box.checked ? 'add' : 'del';
	box.checked = !box.checked;   // only changes once confirmed and saved
	var t = SPECIES_TOGGLE_TEXT[list][action];
	askModal(t[0].replace('NAME', BS_SPECIES.title), t[1], window.t('str_yes'), list === 'whitelist' || list === 'exclude').then(function (ok) {
		if (!ok) return;
		var x = new XMLHttpRequest();
		x.onload = function () {
			if (this.status === 200 && this.responseText.trim() === 'OK') box.checked = (action === 'add');
			else bsNotice(window.t('str_not_changed_reason', {reason: this.status === 401 ? window.t('str_log_in_first_lc') : this.responseText}));
		};
		x.onerror = function () { bsNotice(window.t('str_not_changed_network_error')); };
		x.open('POST', 'scripts/species_tools.php?toggle=' + list + '&species=' + encodeURIComponent(species) + '&action=' + action, true);
		x.send();
	});
}
// species threshold typed in Species Settings: saved at once (empty = back to the global Min. Conf.)
function spThreshold(inp, sci) {
	var v = inp.value.trim();
	if (v !== '') { v = Math.min(0.99, Math.max(0.01, parseFloat(v.replace(',', '.')))); if (isNaN(v)) { inp.value = ''; return; } v = v.toFixed(2); inp.value = v; }
	var x = new XMLHttpRequest();
	x.onload = function () {
		var ok = this.status === 200 && this.responseText.trim() === 'OK';
		inp.style.outline = ok ? '2px solid #2e7d32' : '2px solid #c62828';
		setTimeout(function () { inp.style.outline = ''; }, 1500);
		if (!ok) bsNotice(window.t('str_not_saved_reason', {reason: this.status === 401 ? window.t('str_log_in_first_lc') : this.responseText}));
	};
	x.open('POST', 'scripts/species_tools.php?setconf=1&species=' + encodeURIComponent(sci) + '&value=' + encodeURIComponent(v), true);
	x.send();
}
// files to keep for this species (owner 2026-10-21): saved at once, empty = the global number
function spFiles(inp, sci) {
	var v = inp.value.trim();
	if (v !== '') { v = Math.max(0, Math.min(100000, parseInt(v, 10))); if (isNaN(v)) { inp.value = ''; return; } inp.value = v; }
	var x = new XMLHttpRequest();
	x.onload = function () {
		var ok = this.status === 200 && this.responseText.trim() === 'OK';
		inp.style.outline = ok ? '2px solid #2e7d32' : '2px solid #c62828';
		setTimeout(function () { inp.style.outline = ''; }, 1500);
		if (!ok) bsNotice(window.t('str_not_saved_reason', {reason: this.status === 401 ? window.t('str_log_in_first_lc') : this.responseText}));
	};
	x.open('POST', 'scripts/species_tools.php?setfiles=1&species=' + encodeURIComponent(sci) + '&value=' + encodeURIComponent(v), true);
	x.send();
}
// calibrated threshold: saved as the species threshold (same endpoint as the Species Pages list)
// Precision (owner 2026-10-22): for each threshold, the right and false reviewed detections at or above it, the
// precision, the guaranteed precision (Wilson lower bound, 95 %) and the right ones that would be lost below it; the
// suggestion is the lowest threshold whose precision reaches the target with at least 20 reviews above it (owner
// 2026-10-22: the guaranteed precision asked too much of a few dozen reviews; it is shown as a warning only)
function spPrecision() {
	var box = document.getElementById('spprec');
	if (!box) return;
	var rows = JSON.parse(box.dataset.conf || '[]'), out = box.querySelector('.spprec-out'), target = parseFloat(box.querySelector('.spprec-target').value);
	var P = window.bsPrecision, MIN_N = P.MIN_N, pct = function (v, d) { var r = +(v * 100).toFixed(d || 0); return window.fmtNum(r, (String(r).split('.')[1] || '').length) + '%'; };  // station locale, at most d decimals
	if (!rows.length) { out.innerHTML = '<p>' + window.t('str_no_reviewed_detection_with_yes_help') + '</p>'; return; }
	// detections of the species per 5 % confidence band and per week probability: the weights of the bands
	var nConf = JSON.parse(box.dataset.nconf || '[]'), nProb = JSON.parse(box.dataset.nprob || '[]');
	var countConf = P.countConf(nConf);
	var countProb = function (lo, hi) { var s = 0; nProb.forEach(function (e) { if (e[0] >= lo && e[0] < hi) s += e[1]; }); return s; };
	// the stratified precision above each threshold (static/precision.js, shared with Validation › Calibrate thresholds)
	function curve(idx, steps, digits, count) {
		var c = P.curve(rows, idx, steps, count, target), html = '';
		c.lines.forEach(function (l) {
			html += '<tr' + (c.best === l.th ? ' class="spprec-best"' : '') + '><td>' + pct(l.th, digits) + '</td><td class="num">' + l.y + '</td><td class="num">' + l.f + '</td><td class="num">' + (l.prec !== null ? pct(l.prec) : '-')
				+ '</td><td class="num">' + (l.lb !== null ? pct(l.lb) : '-') + '</td><td class="num">' + (l.all ? pct(l.share) : '-') + '</td><td class="num">' + window.fmtNum(Math.round(l.lost)) + '</td></tr>';
		});
		return {best: c.best, lb: c.lb, html: html, none: c.none};
	}
	// Wood & Kahl (2024): the confidence where one detection is right with the target probability
	function logisticLine() {
		var r = P.logisticThreshold(rows, target), t = r.t;
		if (r.why === 'few') return '<p>' + window.t('str_logistic_needs_at_least_reviews_help', {n: MIN_N}) + '</p>';
		if (r.why === 'flat') return '<p>' + window.t('str_logistic_for_this_species_the_help') + '</p>';
		if (r.why === 'none') return '<p>' + window.t('str_logistic_no_confidence_reaches_help') + '</p>';
		return '<p>' + window.t('str_logistic_wood_and_kahl_at_a_help', {v: '<b>' + pct(t) + '</b>', p: pct(target)})
			+ ' <button type="button" class="openbtn" onclick="applyThreshold(' + JSON.stringify(box.dataset.sci).replace(/"/g, '&quot;') + ', ' + t + ', this)">' + window.t('str_apply') + '</button></p>';
	}
	function block(title, c, cur, glob, kind, digits, extra) {
		var head = '<tr><th>' + title + ' &ge;</th><th class="num">' + window.t('str_right') + '</th><th class="num">' + window.t('str_false') + '</th><th class="num">' + window.t('str_precision') + '</th><th class="num">' + window.t('str_guaranteed') + '</th><th class="num">' + window.t('str_covered') + '</th><th class="num">' + window.t('str_real_lost') + '</th></tr>';
		// the Prob. in force is the higher of the species' own and the station's (the analysis drops below either)
		var now = cur !== '' ? (kind === 'prob' ? Math.max(parseFloat(cur), glob) : parseFloat(cur)) : glob, sug;
		if (c.none) sug = window.t('str_no_probability_of_occurrence_help');
		else if (c.best === null) sug = window.t('str_no_threshold_reaches_the_target_help');
		else if (c.best <= now) sug = window.t('str_the_threshold_in_force_already_help', {now: pct(now, digits)});
		else sug = window.t('str_suggested_v', {v: '<b>' + pct(c.best, digits) + '</b>'}) + (c.lb < target ? ' <small>(' + window.t('str_guaranteed_review_more_to_confirm', {g: pct(c.lb)}) + ')</small>' : '') + ' <button type="button" class="openbtn" onclick="' + (kind === 'conf' ? 'applyThreshold' : 'applyMinProb') + '(' + JSON.stringify(box.dataset.sci).replace(/"/g, '&quot;') + ', ' + c.best + ', this)">' + window.t('str_apply') + '</button>';
		return '<div class="spprec-blk"><h4>' + title + ' <small>(' + window.t('str_in_force_v', {v: pct(now, digits) + (cur === '' || (kind === 'prob' && parseFloat(cur) < glob) ? ' ' + window.t('str_station_lc_paren') : '')}) + ')</small></h4><table class="stdtable">' + head + c.html + '</table><p>' + sug + '</p>' + (extra || '') + '</div>';
	}
	var cs = P.confSteps(), ps = [0, 0.005, 0.01, 0.02, 0.03, 0.05, 0.1, 0.2, 0.3, 0.5, 0.7];
	out.innerHTML = block(window.t('str_confidence'), curve(0, cs, 0, countConf), box.dataset.curConf, parseFloat(box.dataset.globalConf), 'conf', 0, logisticLine())
		+ block(window.t('str_probability_of_occurrence_at_help'), curve(1, ps, 1, countProb), box.dataset.curProb, parseFloat(box.dataset.globalProb), 'prob', 1);
}
function spPrecisionBind() {
	var sel = document.querySelector('#spprec .spprec-target');
	if (sel) { sel.onchange = spPrecision; spPrecision(); }
}
document.addEventListener('DOMContentLoaded', spPrecisionBind);
// back from the review player with answers (owner 2026-10-22): Precision and the Reviews card are drawn again from a
// fresh copy of the page, the target chosen is kept
document.addEventListener('rp:closed', function (e) {
	if (!e.detail || !e.detail.answered || !document.getElementById('spprec')) return;
	var target = document.querySelector('#spprec .spprec-target').value;
	fetch(location.href, {credentials: 'same-origin'}).then(function (r) { return r.text(); }).then(function (html) {
		var doc = new DOMParser().parseFromString(html, 'text/html');
		var fresh = doc.getElementById('spprec'), old = document.getElementById('spprec');
		if (fresh && old) { old.replaceWith(fresh); fresh.querySelector('.spprec-target').value = target; spPrecisionBind(); }
		var fr = doc.getElementById('spreviews'), or = document.getElementById('spreviews');
		if (fr && or) or.replaceWith(fr);
	}).catch(function () {});
});
// the species Min. Prob. (species_min_prob.txt), from the Precision suggestion
function applyMinProb(sci, value, btn) {
	var x = new XMLHttpRequest();
	x.onload = function () {
		if (this.status === 200 && this.responseText.trim() === 'OK') { btn.textContent = window.t('str_applied'); btn.disabled = true; var i = document.getElementById('sp_minprob'); if (i) i.value = value; }
		else bsNotice(window.t('str_not_saved_reason', {reason: this.status === 401 ? window.t('str_log_in_first_lc') : this.responseText}));
	};
	x.open('POST', 'scripts/species_tools.php?setprob=1&species=' + encodeURIComponent(sci) + '&value=' + value, true);
	x.send();
}
function spMinProb(inp, sci) {
	var x = new XMLHttpRequest();
	x.onload = function () {
		if (this.status === 200 && this.responseText.trim() === 'OK') { inp.style.outline = '2px solid #2e7d32'; setTimeout(function () { inp.style.outline = ''; }, 1500); }
		else bsNotice(window.t('str_not_saved_reason', {reason: this.status === 401 ? window.t('str_log_in_first_lc') : this.responseText}));
	};
	x.open('POST', 'scripts/species_tools.php?setprob=1&species=' + encodeURIComponent(sci) + '&value=' + encodeURIComponent(inp.value), true);
	x.send();
}
function applyThreshold(sci, value, btn) {
	var x = new XMLHttpRequest();
	x.onload = function () {
		if (this.status === 200 && this.responseText.trim() === 'OK') { btn.textContent = window.t('str_applied'); btn.disabled = true; var i = document.getElementById('sp_threshold'); if (i) i.value = Number(value).toFixed(2); }
		else bsNotice(window.t('str_not_saved_reason', {reason: this.status === 401 ? window.t('str_log_in_first_lc') : this.responseText}));
	};
	x.open('POST', 'scripts/species_tools.php?setconf=1&species=' + encodeURIComponent(sci) + '&value=' + value, true);
	x.send();
}
// notification tier of the species (same file and endpoint as the Species Pages list), saved at once
function spTier(sel, sci) {
	var was = sel.dataset.was;
	var x = new XMLHttpRequest();
	x.onload = function () {
		if (this.status === 200 && this.responseText.trim() === 'OK') { sel.dataset.was = sel.value; sel.style.outline = '2px solid #2e7d32'; setTimeout(function () { sel.style.outline = ''; }, 1200); }
		else { sel.value = was; bsNotice(window.t('str_not_changed_reason', {reason: this.status === 401 ? window.t('str_log_in_first_lc') : this.responseText})); }
	};
	x.onerror = function () { sel.value = was; bsNotice(window.t('str_not_changed_network_error')); };
	x.open('POST', 'scripts/species_tools.php?settier=1&species=' + encodeURIComponent(sci) + '&tier=' + encodeURIComponent(sel.value), true);
	x.send();
}
document.addEventListener('play', function (e) { document.querySelectorAll('.sp audio').forEach(function (a) { if (a !== e.target) a.pause(); }); }, true);
