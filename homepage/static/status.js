//##############################################################################
// homepage/static/status.js - STATUS: THE UPDATE CHECK, THE SERVICE BUTTONS AND THEIR SCREEN
//
// PURPOSE:
//   The script of Status (scripts/status.php): the newest commit fetched in the background and the reload
//   after an update, the service buttons on a screen of their own with the terminal output.
//
// DEPENDENCIES:
//   - static/species-modal.js (bsAsk)
//
// USAGE:
//   <script src="static/status.js"> in scripts/status.php, after window.BS_STATUS = {...}
//##############################################################################

// the newest commit, fetched in the background when the last fetch is older than 10 minutes; a reload when the update
// state changed
if (BS_STATUS.update) (function () {
	var shown = BS_STATUS.update;
	var x = new XMLHttpRequest();
	x.onload = function () {
		var r; try { r = JSON.parse(this.responseText); } catch (e) { return; }
		if (r.behind !== shown.behind || r.latest !== shown.latest) location.reload();
	};
	x.open('GET', 'scripts/status.php?check=1', true); x.send();
})();
// the update on a screen of its own (owner 2026-10-24): its progress, no way out until it ends (the page warns before
// leaving), then a fresh load of the whole app
var stUp = {from: 0, t0: 0, fails: 0, done: false};
function stUpdate(asked) {
	// asked through the app's modal first; on Yes it comes back here with asked = true
	if (!asked) { bsAsk(t('str_are_you_sure_you_want_to_update_help')).then(function (ok) { if (ok) stUpdate(true); }); return; }
	var ov = document.getElementById('upov');
	ov.style.display = 'flex';
	stUp.t0 = Date.now();
	window.addEventListener('beforeunload', stLeave);
	try { window.top.addEventListener('beforeunload', stLeave); } catch (e) {}
	setInterval(function () {
		if (stUp.done) return;
		document.getElementById('uptime').textContent = new Date(Date.now() - stUp.t0).toISOString().substring(14, 19);
	}, 1000);
	var x = new XMLHttpRequest();
	x.onload = function () { stPoll(); };
	x.onerror = function () { stPoll(); };
	x.open('POST', 'scripts/status.php?update=start', true); x.send();
}
function stLeave(e) { if (!stUp.done) { e.preventDefault(); e.returnValue = ''; return ''; } }
function stPoll() {
	var x = new XMLHttpRequest();
	x.timeout = 10000;
	x.onload = function () {
		var r; try { r = JSON.parse(this.responseText); } catch (e) { return stRetry(); }
		stUp.fails = 0;
		document.getElementById('upstate').textContent = t('str_updating_do_not_close_this_page');
		if (r.text) {
			var pre = document.getElementById('uplog');
			pre.textContent += r.text;
			pre.scrollTop = pre.scrollHeight;
		}
		stUp.from = r.size;
		if (r.done) return stDone(r.code);
		setTimeout(stPoll, 1500);
	};
	x.onerror = x.ontimeout = stRetry;
	x.open('GET', 'scripts/status.php?update=log&from=' + stUp.from, true); x.send();
}
// the web server restarts during the update: keep asking
function stRetry() {
	stUp.fails += 1;
	document.getElementById('upstate').textContent = t('str_the_station_is_restarting_its_help');
	setTimeout(stPoll, 3000);
}
function stDone(code) {
	stUp.done = true;
	var ok = code === 0;
	var st = document.getElementById('upstate');
	st.textContent = ok ? t('str_update_finished_reloading') : t('str_the_update_failed_code_see_the_help', {code: code});
	st.style.color = ok ? '' : '#c62828';
	if (ok) setTimeout(function () { (window.top || window).location.reload(); }, 2500);
	else document.getElementById('upclose').style.display = '';
}

// a service button on a screen of its own (owner 2026-10-24, as the update): the terminal output, no way out until it
// ends, then Close reloads Status with the new states
var sv = {from: 0, done: false};
function svRun(btn, asked) {
	// a button with data-ask (Stop, Restart, Disable) asks through the app's modal first; on Yes it comes back with asked = true
	if (btn.dataset.ask && !asked) { bsAsk(btn.dataset.ask, true).then(function (ok) { if (ok) svRun(btn, true); }); return; }
	sv.from = 0; sv.done = false;
	document.getElementById('svtitle').textContent = btn.dataset.what;
	document.getElementById('svlog').textContent = '';
	document.getElementById('svstate').textContent = t('str_running_do_not_close_this_page');
	document.getElementById('svstate').style.color = '';
	document.getElementById('svclose').style.display = 'none';
	document.getElementById('svov').style.display = 'flex';
	window.addEventListener('beforeunload', svLeave);
	try { window.top.addEventListener('beforeunload', svLeave); } catch (e) {}
	var x = new XMLHttpRequest();
	x.onload = function () {
		var r; try { r = JSON.parse(this.responseText); } catch (e) { r = {ok: false}; }
		if (r.ok) return svPoll();
		sv.done = true;
		document.getElementById('svstate').textContent = r.busy ? t('str_another_service_command_is_help') : t('str_this_command_is_not_allowed');
		document.getElementById('svclose').style.display = '';
	};
	x.onerror = function () { setTimeout(svPoll, 2000); };
	x.open('POST', 'scripts/status.php?svc=start', true);
	x.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
	x.send('cmd=' + encodeURIComponent(btn.dataset.cmd));
}
function svLeave(e) { if (!sv.done) { e.preventDefault(); e.returnValue = ''; return ''; } }
function svPoll() {
	var x = new XMLHttpRequest();
	x.timeout = 10000;
	x.onload = function () {
		var r; try { r = JSON.parse(this.responseText); } catch (e) { return setTimeout(svPoll, 2000); }
		if (r.text) { var pre = document.getElementById('svlog'); pre.textContent += r.text; pre.scrollTop = pre.scrollHeight; }
		sv.from = r.size;
		if (!r.done) return setTimeout(svPoll, 1000);
		sv.done = true;
		var st = document.getElementById('svstate');
		st.textContent = r.code === 0 ? t('str_done_dot') : t('str_finished_with_an_error_code_see_help', {code: r.code});
		st.style.color = r.code === 0 ? '' : '#c62828';
		document.getElementById('svclose').style.display = '';
	};
	// Restart Core Services restarts the web server: keep asking
	x.onerror = x.ontimeout = function () { document.getElementById('svstate').textContent = t('str_the_station_is_restarting_its_help'); setTimeout(svPoll, 2000); };
	x.open('GET', 'scripts/status.php?svc=log&from=' + sv.from, true); x.send();
}
// the ticked days of the automatic update into AUTOMATIC_UPDATE_DAYS (at least one: the last one cannot be unticked)
function stUpDays() {
	var b = document.querySelectorAll('.autoupday'), v = [];
	b.forEach(function (c) { if (c.checked) v.push(c.value); });
	if (!v.length) { event.target.checked = true; return; }
	var f = document.getElementById('autoupdays'); f.value = v.join(','); window.bsSet(f);
}
