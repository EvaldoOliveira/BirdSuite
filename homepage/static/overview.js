//##############################################################################
// homepage/static/overview.js - LATEST: THE PERIODIC QUESTIONS, THE FILTERS, THE VIEWS AND THE AUTO REFRESH
//
// PURPOSE:
//   The script of Latest (scripts/overview.php): the periodic questions one at a time, the filters and their
//   defaults, the view and the count kept per browser, the spectrogram, the refresh of the detections.
//
// DEPENDENCIES:
//   - static/custom-audio-player.js, static/spectro-dialog.js, static/review-player.js, static/std-table.js, static/name-mode.js, static/detection-actions.js
//
// USAGE:
//   <script src="static/overview.js"> in scripts/overview.php, after window.BS_OVERVIEW = {...}
//##############################################################################

// the periodic questions of the page (2026-11-04 hardening): one at a time per kind (a slow station piled up a request
// every few seconds), a 15 s timeout, only a 200 answer counts; anything else (503 of a busy database, an error, a
// timeout) is no answer: the page keeps what it shows and calls miss() when given (Gitea #77)
var nowBusy = {};
function nowGet(kind, url, ok, miss) {
	if (nowBusy[kind]) return;
	nowBusy[kind] = true;
	var x = new XMLHttpRequest();
	var answered = false;
	x.timeout = 15000;
	x.onloadend = function () { nowBusy[kind] = false; if (!answered && miss) miss(); };
	x.onload = function () { if (this.status === 200) { answered = true; ok(this.responseText); } };
	x.open('GET', url, true);
	x.send();
}
// the newest clip is asked every few seconds: a new one reloads the list (newest page only; the first answer loads it too)
var lastDetection;
function loadDetectionIfNewExists() {
	nowGet('latest', 'overview.php?latest_file=1', function (txt) {
		var f = txt.trim();
		// the text check stays for a station whose busy answer is still a 200 page (the 503 is ignored by nowGet)
		if (f === '' || f.includes("Database is busy") || f === lastDetection) return;
		var first = lastDetection === undefined;
		lastDetection = f;
		if (nowOffset === 0 || first) loadFiveMostRecentDetections();
	});
}
function refreshDetection() {
	if (document.hidden) return;
	// not while a sound of the page plays
	var playing = false;
	document.querySelectorAll('audio').forEach(function (a) { if (!a.paused && !a.ended && a.currentTime > 0) playing = true; });
	if (!playing) loadDetectionIfNewExists();
}
// The live spectrogram belongs to the Spectrogram view (owner 2026-10-21): there it is on by default and its button
// on the first line hides it; Simple and Full show neither the spectrogram nor the button
var analyzingOn = true;
function applyAnalyzing() {
	var spectro = typeof nowView !== 'undefined' && nowView === 'spectrogram';
	var img = document.getElementById('spectrogramimage'), b = document.getElementById('analyzing_toggle');
	if (img) img.style.display = (spectro && analyzingOn) ? '' : 'none';
	if (b) { b.parentNode.style.display = spectro ? '' : 'none'; b.classList.toggle('active', analyzingOn); }
}
function toggleAnalyzing() {
	analyzingOn = !analyzingOn;
	applyAnalyzing();
	if (analyzingOn) bsLivePng(document.getElementById('spectrogramimage'));
}
document.addEventListener('DOMContentLoaded', applyAnalyzing);
// the station not answering (Gitea #77): after three missed status questions in a row the first line says so, in the
// warning colour, until the next good answer
var nowStatusMissed = 0;
function nowStatusMiss() {
	if (++nowStatusMissed < 3) return;
	var el = document.getElementById('analysis_status');
	if (!el) return;
	el.textContent = t('str_the_station_is_not_answering_help');
	el.classList.add('late');
}
function refreshAnalysisStatus() {
	if (document.hidden) return;
	nowGet('status', 'overview.php?analysis_status=1', function (txt) {
		var st; try { st = JSON.parse(txt); } catch (e) { st = null; }
		if (!st || typeof st !== 'object') { nowStatusMiss(); return; }
		nowStatusMissed = 0;
		var el = document.getElementById('analysis_status');
		if (!el) return;
		// the units through the catalogues (Gitea #79); no delay when the station could not tell it (behind null)
		var behind = st.behind === null || st.behind === undefined ? ''
			: st.behind >= 60 ? t('str_n_minutes_short', {n: Math.round(st.behind / 60)}) : t('str_n_seconds_short', {n: st.behind || 0});
		// "12:48:30, Waiting: 2, Delay: 16s" (owner 2026-10-21): the time of the recording shown first
		var delay = st.waiting === 0 ? t('str_n_seconds_short', {n: 0}) : behind;
		el.textContent = (st.shown ? st.shown + ', ' : '') + (!st.running ? t('str_analysis_stopped') + ', ' : '')
			+ t('str_waiting', {n: st.waiting}) + (delay !== '' ? ', ' + t('str_delay', {time: delay}) : '');
		el.classList.toggle('late', !st.running || st.waiting > 10);
	}, nowStatusMiss);
}
document.addEventListener('DOMContentLoaded', function () { refreshAnalysisStatus(); setInterval(refreshAnalysisStatus, 5000); });
// the Now cards page through the detections 50 at a time; only the newest page follows new detections
var nowOffset = 0;
var nowTerm = '';
// any value is accepted while typing; leaving a field rounds it to its precision and keeps it inside its limits
// (owner 2026-10-09: the browser refused 0.5 for Loc. Thresh., whose min 0.0005 + step 0.01 allowed only 0.0105, 0.0205...)
// a field whose value is not the model's default turns light yellow (owner 2026-10-10)
function naMarkDefaults() {
	var b = document.querySelector('.nadefault');
	if (!b) return;
	var d = JSON.parse(b.dataset.defaults);
	var any = false;
	Object.keys(d).forEach(function (id) {
		var inp = document.getElementById(id);
		var off = !!inp && parseFloat(inp.value) !== parseFloat(d[id]);
		if (inp) inp.classList.toggle('nondefault', off);
		any = any || off;
	});
	// the Default button only while a value is off its default (owner 2026-11-04, the Watch pages)
	b.style.display = any ? '' : 'none';
}
document.addEventListener('DOMContentLoaded', function () {
	naMarkDefaults();
	['na_conf', 'na_sf', 'na_sens', 'na_len', 'na_over'].forEach(function (id) { var inp = document.getElementById(id); if (inp) inp.addEventListener('input', naMarkDefaults); });
});
function naDefaults() {
	var d = JSON.parse(document.querySelector('.nadefault').dataset.defaults);
	Object.keys(d).forEach(function (id) { var inp = document.getElementById(id); if (inp) inp.value = d[id]; });
	naMarkDefaults();
	document.getElementById('na_form').requestSubmit();
}
var naOpen = false;
function naShow(open) {
	naOpen = open;
	try { localStorage.setItem('now_params', open ? 'open' : 'closed'); } catch (e) {}
	naApply();
}
// the analysis settings belong to the Full and Spectrogram views (owner 2026-10-21): hidden in Simple
function naApply() {
	var simple = typeof nowView !== 'undefined' && nowView === 'simple';
	var f = document.getElementById('na_form'), b = document.getElementById('na_open'), w = document.getElementById('na_wrap');
	if (f) f.style.display = naOpen ? '' : 'none';
	if (b) b.style.display = (simple || naOpen) ? 'none' : '';
	if (w) w.style.display = simple ? 'none' : '';
}
document.addEventListener('DOMContentLoaded', function () {
	var saved = null;
	try { saved = localStorage.getItem('now_params'); } catch (e) {}
	naShow(saved ? saved === 'open' : NOW_OPEN_DEFAULTS.params[!bsPhone() ? 1 : 0]);
});
var NA_DECIMALS = {na_conf: 2, na_sf: 4, na_sens: 2, na_over: 1, na_len: 0};
function naRound(inp) {
	var v = parseFloat(String(inp.value).replace(',', '.'));
	if (isNaN(v)) return;
	v = Math.min(parseFloat(inp.max), Math.max(parseFloat(inp.min), v));
	inp.value = String(parseFloat(v.toFixed(NA_DECIMALS[inp.id])));
}
document.addEventListener('DOMContentLoaded', function () {
	Object.keys(NA_DECIMALS).forEach(function (id) {
		var inp = document.getElementById(id);
		if (inp) inp.addEventListener('change', function () { naRound(inp); naMarkDefaults(); });
	});
});
function saveAnalysis(e) {
	Object.keys(NA_DECIMALS).forEach(function (id) { var inp = document.getElementById(id); if (inp) naRound(inp); });
	e.preventDefault();
	// no message beside the button (owner 2026-10-09: it pushed the line); the button itself says Saved for a moment,
	// an error opens a dialog
	var b = document.getElementById('na_apply');
	var x = new XMLHttpRequest();
	x.onload = function () {
		if (this.status === 200 && this.responseText.trim() === 'OK') {
			b.textContent = '\u2713';
			b.title = t('str_saved_applied_from_the_next_help');
			setTimeout(function () { b.textContent = t('str_apply'); }, 1500);
		} else bsNotice(t('str_not_saved_reason', {reason: this.status === 401 ? t('str_log_in_first_lc') : this.responseText}));
	};
	x.open('POST', 'overview.php?set_analysis=1', true);
	x.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
	x.send('confidence=' + encodeURIComponent(document.getElementById('na_conf').value)
		+ '&sensitivity=' + encodeURIComponent(document.getElementById('na_sens').value) + '&overlap=' + encodeURIComponent(document.getElementById('na_over').value)
		+ '&sf_thresh=' + encodeURIComponent(document.getElementById('na_sf').value) + '&recording_length=' + encodeURIComponent(document.getElementById('na_len').value));
}
// filters (owner 2026-10-09/10): Uncommon (species detected at most N times since yesterday 00:00), Low Conf (confidence
// below P %), Low Prob (location probability below P %) — each button switches its filter on/off, several apply together,
// each active one keeps its picklist open beside its button; All switches them all off. Remembered in this browser.
var nowFilters = {};
function nowStore(k, v) { try { if (v === undefined) return localStorage.getItem(k); localStorage.setItem(k, v); } catch (e) {} return null; }
// the values a filter starts with (LATEST_LOWPROB / LATEST_LOWCONF / LATEST_UNCOMMON of factory_defaults.conf, US-121)
var NOW_FILTER_DEFAULTS = BS_OVERVIEW.filterDefaults;
// the days of the Rare filter, with the station's own value among them
var NOW_RARE_STEPS = [7, 14, 30, 60, 90, 180, 365].concat([NOW_FILTER_DEFAULTS.rare]).filter(function (v, i, a) { return a.indexOf(v) === i; }).sort(function (a, b) { return a - b; });
function nowDefaultValue(kind) {
	if (kind === 'rare') return String(NOW_FILTER_DEFAULTS.rare);
	if (kind === 'loss') return '0';
	if (kind === 'uncommon') return String(NOW_UNCOMMON_STEPS.filter(function (x) { return x <= NOW_FILTER_DEFAULTS.uncommon; })[0] || NOW_UNCOMMON_STEPS[NOW_UNCOMMON_STEPS.length - 1]);
	return String(Math.round(NOW_FILTER_DEFAULTS[kind] / 5) * 5 || 50);
}
function nowFilterOptions(kind) {
	if (kind === 'rare') return NOW_RARE_STEPS.map(function (v) { return [v, '\u2265 ' + t('str_days_without', {n: v})]; });
	// the audio the recorder lost in the recording, an xrun (owner 2026-11-04): any, or more than N ms
	if (kind === 'loss') return [[0, t('str_any_loss')], [1, '> 1 ms'], [5, '> 5 ms'], [20, '> 20 ms'], [100, '> 100 ms']];
	return kind === 'uncommon'
		? NOW_UNCOMMON_STEPS.map(function (v) { return [v, '\u2264 ' + t('str_since_yesterday', {n: v})]; })
		: Array.apply(null, Array(19)).map(function (_, i) { var v = (i + 1) * 5; return [v, '< ' + v + '%']; });
}
function nowFilterButtons() {
	var any = Object.keys(nowFilters).length > 0;
	document.querySelectorAll('.nowfilters button[data-filter]').forEach(function (b) {
		var k = b.dataset.filter;
		b.classList.toggle('active', k === '' ? !any : nowFilters[k] !== undefined);
		if (k === '') return;
		var sel = document.getElementById('nowbelow_' + k);
		if (nowFilters[k] === undefined) { if (sel) sel.remove(); return; }
		if (!sel) {
			sel = document.createElement('select');
			sel.id = 'nowbelow_' + k;
			sel.className = 'nowbelow';
			sel.innerHTML = nowFilterOptions(k).map(function (o) { return '<option value="' + o[0] + '">' + o[1] + '</option>'; }).join('');
			// the chosen value is kept as this filter's default: switched off and on again, it comes back with it
			sel.onchange = function () { nowFilters[k] = sel.value; nowStore('now_below_' + k, sel.value); nowSaveFilters(); if (typeof nowDefaultMark === 'function') nowDefaultMark(); nowOffset = 0; loadFiveMostRecentDetections(); };
			b.after(sel);
		}
		if (![].some.call(sel.options, function (o) { return o.value === String(nowFilters[k]); })) nowFilters[k] = nowDefaultValue(k);
		sel.value = nowFilters[k];
	});
	nowFilterMark();
}
// closing the panel switches the filters and the search off (like All); opening it again brings back the last ones
// (owner 2026-10-20) — kept in nowParked meanwhile, and in the browser for the next visit
var nowParked = null;
function nowFilterPanel() {
	var closed = document.getElementById('nowsearch').classList.toggle('nfclosed');
	if (closed) {
		nowParked = {filters: nowFilters, term: nowTerm};
		nowFilters = {};
		nowTerm = '';
	} else if (nowParked) {
		nowFilters = nowParked.filters;
		nowTerm = document.getElementById('searchterm').value.trim();
		nowParked = null;
	}
	nowFilterButtons();
	nowOffset = 0;
	loadFiveMostRecentDetections();
}
// the icon shows that something filters the list, also while the buttons are hidden
// the panels open or closed by default, phone / wider screen (owner 2026-10-25: Settings › Defaults, Latest page)
var NOW_OPEN_DEFAULTS = BS_OVERVIEW.openDefaults;
function nowFilterMark() {
	var i = document.getElementById('nowfiltericon');
	if (i) i.classList.toggle('nfon', Object.keys(nowFilters).length > 0 || nowTerm !== '');
}
function nowSaveFilters() { if (!nowParked) nowStore('now_filters', JSON.stringify(nowFilters)); }
function nowFilter(kind) {
	if (kind === '') nowFilters = {};
	else if (nowFilters[kind] !== undefined) delete nowFilters[kind];
	else nowFilters[kind] = nowStore('now_below_' + kind) || nowDefaultValue(kind);
	nowFilterButtons();
	Object.keys(nowFilters).forEach(function (k) { nowStore('now_below_' + k, nowFilters[k]); });
	nowSaveFilters();
	nowOffset = 0;
	loadFiveMostRecentDetections();
}
document.addEventListener('DOMContentLoaded', function () {
	try { nowFilters = JSON.parse(nowStore('now_filters') || '{}') || {}; } catch (e) { nowFilters = {}; }
	Object.keys(nowFilters).forEach(function (k) { if (['uncommon', 'rare', 'lowconf', 'lowprob', 'loss'].indexOf(k) < 0) delete nowFilters[k]; });
	// the page always opens with the filter off (owner 2026-10-21): the saved filters wait for the Filter icon
	nowParked = {filters: nowFilters, term: ''}; nowFilters = {};
	nowFilterButtons();
	// a link that asks for a filter (owner 2026-11-04: Station › Microphone › Audio loss opens the sonograms of the
	// detections with lost audio): &lfilter=loss[&lval=N][&lview=spectrogram][&lperiod=today|7d|all]
	var lq = new URLSearchParams(location.search);
	if (lq.get('lfilter') === 'loss') {
		// the view applied as at load (Gitea #77: lview=spectrogram showed neither the live spectrogram nor Now Recording)
		if (['simple', 'list', 'spectrogram'].indexOf(lq.get('lview')) >= 0) { nowView = lq.get('lview'); nowViewChosen = true; analyzingOn = true; applyAnalyzing(); nowModeButtons(); }
		if (['today', '7d', 'all'].indexOf(lq.get('lperiod')) >= 0) { nowPeriod = lq.get('lperiod'); var pp = document.getElementById('nowperiod'); if (pp) pp.value = nowPeriod; }
		nowParked = {filters: {loss: /^\d+$/.test(lq.get('lval') || '') ? lq.get('lval') : '0'}, term: ''};
		if (document.getElementById('nowsearch').classList.contains('nfclosed')) nowFilterPanel();
		else { nowFilters = nowParked.filters; nowParked = null; nowFilterButtons(); nowOffset = 0; loadFiveMostRecentDetections(); }
		return;
	}
	// open by default when the station says so for this kind of screen (LATEST_FILTERS_PHONE / LATEST_FILTERS_DESKTOP)
	if (NOW_OPEN_DEFAULTS.filters[!bsPhone() ? 1 : 0]) { nowFilterPanel(); return; }
	if (Object.keys(nowFilters).length) loadFiveMostRecentDetections();
});
// period of the filters and the search (owner 2026-10-20): Today by default — a filter that matches few detections
// otherwise walks the whole history; remembered per browser
var nowPeriod = (function () { var v = nowStore('now_period'); return ['today', '7d', 'all'].indexOf(v) >= 0 ? v : BS_OVERVIEW.period; })();
document.addEventListener('DOMContentLoaded', function () { var p = document.getElementById('nowperiod'); if (p) p.value = nowPeriod; });
function nowSetPeriod(v) { nowPeriod = v; nowStore('now_period', v); if (typeof nowDefaultMark === 'function') nowDefaultMark(); nowOffset = 0; if (Object.keys(nowFilters).length || nowTerm) loadFiveMostRecentDetections(); }
function nowFilterQuery() { return Object.keys(nowFilters).map(function (k) { return '&' + k + '=' + encodeURIComponent(nowFilters[k]); }).join('') + '&period=' + nowPeriod; }
// every page load starts sorted by time, newest first (owner 2026-10-20); a column click sorts until the next reload
try { Object.keys(localStorage).forEach(function (k) { if (/^stdsort:.*:detections_table$/.test(k)) localStorage.removeItem(k); }); } catch (e) {}
function nowSearch(v) { nowTerm = v.trim(); nowOffset = 0; nowFilterMark(); loadFiveMostRecentDetections(); }
// search as you type (owner 2026-10-09): 300 ms after the last key; an empty box shows every detection again
var nowSearchTimer = null;
function nowSearchTyped(v) {
	clearTimeout(nowSearchTimer);
	nowSearchTimer = setTimeout(function () { if (v.trim() !== nowTerm) nowSearch(v); }, v.trim() === '' ? 0 : 300);
}
// spectrogram cards or list (owner 2026-10-09): LATEST_VIEW (Settings › Defaults) is the default (list when unset, owner
// 2026-10-10), the buttons switch it
var nowView = BS_OVERVIEW.view;
// a phone opens in Simple with the filters closed; a wider screen opens in the default view (Full unless set) with the
// filters open (owner 2026-10-20); the buttons and the Filter icon still switch
var nowPhone = bsPhone();
var nowDefault = nowView; // the saved default, before the phone override, so "Set as default" compares with it
if (nowPhone) nowView = 'simple';
function nowModeButtons() {
	document.querySelectorAll('.nowmodes button[data-mode]').forEach(function (b) { b.classList.toggle('active', b.dataset.mode === nowView); });
	var d = document.getElementById('nowview_default');
	if (d) nowDefaultMark();
	if (typeof naApply === 'function') naApply();
}
// how many detections the list shows (owner 2026-10-20): 10 / 25 / 50 / 100, default 50, remembered per browser
var NOW_COUNT_DEFAULT = BS_OVERVIEW.countDefault;
var nowCount = (function () { var v = parseInt(nowStore('now_count') || String(NOW_COUNT_DEFAULT), 10); return [10, 25, 50, 100].indexOf(v) >= 0 ? v : NOW_COUNT_DEFAULT; })();
document.addEventListener('DOMContentLoaded', function () { var c = document.getElementById('nowcount'); if (c) c.value = String(nowCount); });
function nowSetCount(v) {
	nowCount = parseInt(v, 10) || NOW_COUNT_DEFAULT;
	nowStore('now_count', String(nowCount));
	nowDefaultMark();
	nowOffset = 0;
	// a refresh at once: the cards on top and the list with the new size
	document.getElementById('detections_table').style.opacity = '.5';
	loadFiveMostRecentDetections();
}
var nowViewChosen = false; // the view picked by a button, not the phone's own Simple at opening
function nowMode(mode) {
	if (mode === 'spectrogram' && nowView !== 'spectrogram') analyzingOn = true; // on each time the view is entered
	nowViewChosen = true;
	nowView = mode; nowModeButtons(); applyAnalyzing(); loadFiveMostRecentDetections();
}
// the station defaults the button compares with (owner 2026-10-23: view, count, period and the filter values together)
var nowSaved = {view: nowDefault, count: String(NOW_COUNT_DEFAULT), period: BS_OVERVIEW.period,
								lowprob: nowDefaultValue('lowprob'), lowconf: nowDefaultValue('lowconf'), uncommon: nowDefaultValue('uncommon'), rare: nowDefaultValue('rare')};
function nowCurrent() {
	var cur = {view: nowView, count: String(nowCount), period: nowPeriod};
	['lowprob', 'lowconf', 'uncommon', 'rare'].forEach(function (k) {
		cur[k] = String(nowFilters[k] !== undefined ? nowFilters[k] : (nowStore('now_below_' + k) || nowSaved[k]));
	});
	return cur;
}
function nowDefaultMark() {
	var d = document.getElementById('nowview_default');
	if (!d) return;
	var cur = nowCurrent();
	// a phone opens in Simple by itself: that alone is no reason to offer the button, but a view picked there
	// with the buttons is (2026-11-02: the phone never offered it after a view change)
	var differs = Object.keys(nowSaved).some(function (k) { return k !== 'view' && cur[k] !== nowSaved[k]; })
		|| (cur.view !== nowSaved.view && (!nowPhone || nowViewChosen));
	d.style.display = differs ? '' : 'none'; // hidden takes no room, so the bar fits a phone line
}
function nowSetDefault() {
	var cur = nowCurrent();
	var x = new XMLHttpRequest();
	x.onload = function () {
		if (this.status === 200 && this.responseText.trim() === 'OK') { nowSaved = cur; nowDefault = cur.view; nowDefaultMark(); }
		else bsNotice(t('str_not_saved_reason', {reason: this.status === 401 ? t('str_log_in_first_lc') : this.responseText}));
	};
	x.open('POST', 'overview.php?set_now_view=' + cur.view + '&count=' + cur.count + '&period=' + cur.period
		+ '&lowprob=' + cur.lowprob + '&lowconf=' + cur.lowconf + '&uncommon=' + cur.uncommon + '&rare=' + cur.rare, true);
	x.send();
}
document.addEventListener('DOMContentLoaded', nowModeButtons);
function nowPage(offset) {
	nowOffset = offset;
	loadFiveMostRecentDetections();
	var h = document.querySelector('h3.now-cards');
	if (h) h.scrollIntoView({behavior: 'smooth'});
}
// only the answer to the latest request is shown (owner 2026-10-10): a filtered request is slower, and switching a filter
// off quickly let its late answer overwrite the unfiltered list, so the filter looked still on
// (2026-10-20) only an answer to ANOTHER query (filter, search, page, view) is dropped: with a detection every few seconds
// each one asks again, and dropping every older answer to the same query left the table on the old list while busy
var nowListQuery = '';
// the keyboard stays where it was when the list is built again (Gitea #79, WCAG 2.4.3): the same control of the same
// detection, else the first control of the first row, never the page itself
var NOW_FOCUS_KINDS = ['button.validatebtn', 'a.spcard', 'a[data-com]'];
function nowFocusMark(box) {
	var a = document.activeElement;
	if (!a || a === box || !box.contains(a)) return null;
	var row = a.closest('[data-ri]');
	var file = a.dataset.file || (row ? row.dataset.file : '');
	var kind = NOW_FOCUS_KINDS.filter(function (k) { return a.matches(k); })[0] || '';
	return {file: file || '', kind: kind, id: a.id || ''};
}
function nowFocusBack(box, mark) {
	if (!mark) return;
	// a control of its own outside the rows (the Last N picklist) is found again by its id
	var own = mark.id ? document.getElementById(mark.id) : null;
	if (own && box.contains(own)) { own.focus({preventScroll: true}); return; }
	var rows = box.querySelectorAll('[data-ri]');
	var row = mark.file ? [].filter.call(rows, function (r) { return r.dataset.file === mark.file; })[0] : null;
	var el = row && mark.kind ? row.querySelector(mark.kind) : null;
	if (!el && row) el = row.querySelector(NOW_FOCUS_KINDS.join(','));
	if (!el && rows.length) el = rows[0].querySelector(NOW_FOCUS_KINDS.join(','));
	if (!el) el = box.querySelector('a[href], button, select, input, [tabindex="0"]');
	if (el) el.focus({preventScroll: true});
}
function loadFiveMostRecentDetections() {
	const xhttp = new XMLHttpRequest();
	const mine = nowView + '|' + nowCount + '|' + nowOffset + '|' + nowTerm + '|' + nowFilterQuery() + '|' + (!bsPhone());
	nowListQuery = mine;
	var box = document.getElementById("detections_table");
	xhttp.timeout = 30000;
	// only a 200 answer replaces the table (2026-11-04 hardening: an error page, 401 or 500, was put inside it); a failure
	// or a timeout leaves the list as it was, no longer dimmed
	xhttp.onloadend = function () { if (mine === nowListQuery) box.style.opacity = ''; };
	xhttp.onload = function() {
		if (mine !== nowListQuery || this.status !== 200) return;
		if(this.responseText.length > 0 && !this.responseText.includes("Database is busy")) {
			var focusMark = nowFocusMark(box);
			box.innerHTML = this.responseText;
			if (window.stdTables) stdTables(box);
			if (window.applyNameMode) applyNameMode();
			nowFocusBack(box, focusMark);
		}
	}
	xhttp.open("GET", "todays_detections.php?ajax_detections=true&display_limit=undefined&hard_limit=" + nowCount + "&gallery=1" + (bsPhone() ? "&mobile=true" : "") + "&mode=" + nowView + "&offset=" + nowOffset + (nowTerm ? "&searchterm=" + encodeURIComponent(nowTerm) : "") + nowFilterQuery(), true);
	xhttp.send();
}
var i_fn1 = null, i_fn2 = null;
function startAutoRefresh() {
		clearInterval(i_fn1); clearInterval(i_fn2);
		i_fn1 = window.setInterval(function(){
										// a hidden spectrogram is not downloaded again; a shown one is asked with a conditional request
										// (304 while unchanged, static/ui-standard.js bsLivePng, Gitea #82)
										if (document.hidden || document.getElementById("spectrogramimage").style.display === "none") return;
										bsLivePng(document.getElementById("spectrogramimage"));
										}, BS_OVERVIEW.refresh*1000);
		i_fn2 = window.setInterval(refreshDetection, BS_OVERVIEW.dividedRefresh*1000);
}
window.addEventListener("load", function(){
	loadDetectionIfNewExists();
});
document.addEventListener("visibilitychange", function() {
	if (document.hidden) {
		clearInterval(i_fn1);
		clearInterval(i_fn2);
		i_fn1 = i_fn2 = null;
	} else {
		loadDetectionIfNewExists();
		refreshAnalysisStatus();
		startAutoRefresh();
	}
});
startAutoRefresh();
