//##############################################################################
// homepage/static/location-data.js - DATA AND BACKUP/IMPORT: THE JOB WINDOW, THE ACTIONS AND THE FOLDER TREES
//
// PURPOSE:
//   The script of Data › Data and Data › Backup/Import (scripts/data_page.php, backup_import_page.php): the
//   job box with pause / continue / cancel, Delete all, Restore, Purge, Move, Remove by period, Export /
//   Backup, Import, the life-cycle settings; every call goes to scripts/location_data.php. Moved out of the
//   page (owner 2026-11-06).
//
// DEPENDENCIES:
//   - static/species-modal.js (askModal, bsAsk, bsNotice), static/wipe-deleted.js (restoreRemoved, wipeDeleted), static/folder-picker.js (folderPicker)
//
// USAGE:
//   <script src="static/location-data.js">, then ldPoll() and the page's own ldBrowse() calls
//##############################################################################

// Purge audio: play.php?purgeaudio removes the clips and spectrograms, the detections stay in the database; then reload
// Purge Ignored: the audio of the location's detections answered with a cause and of its excluded species
// (play.php?purgeaudio, both kinds); the detections stay in the database; then reload
function purgeAudio(loc, btn) {
	askModal(t('str_purge_the_audio_of_these_detections'), t('str_the_clips_and_spectrograms_are_help') + '\n' + t('str_the_detections_stay_in_the_help'), t('str_purge_audio'), true).then(function (ok) {
		if (!ok) return;
		btn.disabled = true;
		var kinds = ['causes', 'others'], step = function () {
			if (!kinds.length) return location.reload();
			var x = new XMLHttpRequest();
			x.onload = function () { if (/^OK \d+/.test(this.responseText)) step(); else { btn.disabled = false; bsNotice(this.status === 401 ? t('str_log_in_first') : this.responseText); } };
			x.open('POST', 'play.php?purgeaudio=' + kinds.shift() + '&loc=' + encodeURIComponent(loc), true);
			x.send();
		};
		step();
	});
}
// Restore of removed detections (US-13): the species folded under the location line; each asks first
function ldRestoreFold(btn, cls) {
	var rows = document.querySelectorAll('tr.' + cls), show = rows.length && rows[0].style.display === 'none';
	rows.forEach(function (r) { r.style.display = show ? '' : 'none'; });
	btn.innerHTML = btn.innerHTML.replace(/[▾▴]/, show ? '▴' : '▾');
}
function ldRestore(sci, btn, loc, n) {
	bsAsk(t('str_restore_removed_detections', {n: window.fmtNum(n)}), false).then(function (ok) { if (ok) restoreRemoved(sci, btn, loc); });
}
// Remove by period (US-13): the preview, then the confirmation and the job
var rpBody = null;
function rpPreview() {
	var from = document.getElementById('rpfrom').value, to = document.getElementById('rpto').value, sci = document.getElementById('rpsci').value;
	var msg = document.getElementById('rpmsg'), res = document.getElementById('rpres');
	res.innerHTML = ''; rpBody = null;
	if (!from || !to || from > to) { msg.textContent = t('str_choose_a_valid_period'); return; }
	msg.textContent = t('str_loading_please_wait');
	ldGetJ('scripts/location_data.php?period=1&from=' + encodeURIComponent(from) + '&to=' + encodeURIComponent(to) + '&sci=' + encodeURIComponent(sci), function (j) {
		msg.textContent = '';
		if (!j || j.error) { msg.textContent = j ? j.error : t('str_log_in_first'); return; }
		if (!j.detections) { msg.textContent = t('str_no_detections_in_this_period'); return; }
		rpBody = {action: 'period', from: from, to: to, sci: sci};
		res.innerHTML = '<table class="stdtable" data-nosort><tr><th>' + t('str_species') + '</th><th>' + t('str_detections') + '</th></tr>'
			+ j.species.map(function (s) { return '<tr><td>' + esc(s.com) + ' <small><i>' + esc(s.sci) + '</i></small></td><td class="num">' + window.fmtNum(s.n) + '</td></tr>'; }).join('')
			+ '<tr><td><b>' + t('str_total') + '</b></td><td class="num"><b>' + window.fmtNum(j.detections) + '</b></td></tr></table>'
			+ '<div class="row">' + esc(t('str_detections_and_files', {n: window.fmtNum(j.detections), files: window.fmtNum(j.files)}))
			+ ' <button type="button" class="ldbtn del" onclick="rpGo()">' + t('str_remove') + '</button></div>';
	});
}
function rpGo() {
	if (!rpBody) return;
	askModal(t('str_remove_by_period'), t('str_move_the_detections_of_this_period_help', {from: rpBody.from.replace('T', ' '), to: rpBody.to.replace('T', ' ')})
		+ '\n' + t('str_they_leave_every_page_and_help'), t('str_remove'), true).then(function (ok) { if (ok) ldRun(rpBody, 'rpmsg'); });
}
var ldAct = '', ldLoc = 0, ldName = '', ldN = 0, ldFolder = '', rsFolder = '', bxFolder = '', ldTimer = null;
var esc = function (s) { return String(s == null ? '' : s).replace(/[&<>"]/g, function (c) { return {'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;'}[c]; }); };
function ldGetJ(url, done) { var x = new XMLHttpRequest(); x.onload = function () { try { done(JSON.parse(this.responseText)); } catch (e) { done(null); } }; x.open('GET', url, true); x.send(); }
function ldRun(body, msgEl) {
	var x = new XMLHttpRequest();
	x.onload = function () {
		var t = this.status === 401 ? window.t('str_log_in_first') : this.responseText.trim();
		document.getElementById(msgEl).textContent = t === 'OK' ? '' : t;
		if (t === 'OK') { document.getElementById('ldpanel').style.display = 'none'; window.scrollTo(0, 0); setTimeout(ldPoll, 1000); }
	};
	x.open('POST', 'scripts/location_data.php?run=1', true); x.setRequestHeader('Content-Type', 'application/json'); x.send(JSON.stringify(body));
}
// the folder browser: the tree of the whole station as the station user sees it, with the home, USB disks and network
// shares as shortcuts (static/folder-picker.js, owner 2026-10-29), in the dirs element; pick(path) on each folder chosen
function ldBrowse(p, crumbs, dirs, pick) {
	var c = document.getElementById(crumbs); if (c) c.style.display = 'none';
	folderPicker(dirs, p, function (path) { pick(path); });
}
function ldRemove(btn) {
	var tr = btn.closest('tr');
	askModal(t('str_delete_all'), t('str_move_all_detections_of_to_the_help', {count: window.fmtNum(parseInt(tr.dataset.n, 10)), name: tr.dataset.name})
		+ '\n' + t('str_they_leave_every_page_and_help'), t('str_delete_all'), true)
		.then(function (ok) { if (ok) ldRun({action: 'remove', loc: parseInt(tr.dataset.id, 10)}, 'ldmsg'); });
}
function ldPick(btn, act) {
	var tr = btn.closest('tr');
	ldAct = act; ldLoc = parseInt(tr.dataset.id, 10); ldName = tr.dataset.name; ldN = parseInt(tr.dataset.n, 10);
	var tv = {name: ldName, count: window.fmtNum(ldN)};
	document.getElementById('ldtitle').textContent = act === 'reassign' ? t('str_move_to_another_location_detections', tv) : t('str_move_to_another_station_detections', tv);
	document.getElementById('ldto').style.display = act === 'reassign' ? '' : 'none';
	document.getElementById('ldst').style.display = act === 'station' ? '' : 'none';
	document.getElementById('ldfold').style.display = act === 'reassign' ? 'none' : '';
	document.getElementById('ldmsg').textContent = '';
	document.getElementById('ldpanel').style.display = '';
	if (act !== 'reassign') ldBrowse(ldFolder, 'ldcrumbs', 'lddirs', function (p) { ldFolder = p; });
}
function ldGo() {
	var body = {action: ldAct, loc: ldLoc}, q;
	if (ldAct === 'reassign') {
		body.to = parseInt(document.getElementById('ldtosel').value, 10);
		var toName = document.getElementById('ldtosel').selectedOptions[0].textContent;
		q = t('str_file_the_detections_of_under_help', {count: window.fmtNum(ldN), name: ldName, to: toName});
	} else {
		if (!ldFolder) { document.getElementById('ldmsg').textContent = t('str_choose_a_folder'); return; }
		body.folder = ldFolder;
		var st = document.getElementById('ldstsel');
		if (!st.value) { document.getElementById('ldmsg').textContent = t('str_register_the_other_station_help'); return; }
		try { localStorage.setItem('ld_station', st.value); } catch (e) {}
		q = t('str_copy_the_detections_of_to_then_help', {count: window.fmtNum(ldN), name: ldName, folder: ldFolder, station: st.selectedOptions[0].textContent});
	}
	bsAsk(q, true).then(function (ok) { if (ok) ldRun(body, 'ldmsg'); });
}
// Export / Backup: the location (0 = all) in the format chosen
function bxGo() {
	if (!bxFolder) { document.getElementById('bxmsg').textContent = t('str_choose_a_folder'); return; }
	var loc = document.getElementById('bxloc'), fmt = document.getElementById('bxfmt'), lang = document.getElementById('bxlang');
	var o = loc.selectedOptions[0];
	var inc = fmt.value !== 'birdnet' && document.getElementById('bxinc').checked;
	bsAsk(inc ? t('str_copy_what_is_new_since_the_last_help', {name: o.textContent, folder: bxFolder})
			: t('str_copy_the_detections_of_to_a_new_help', {count: window.fmtNum(parseInt(o.dataset.n, 10)), name: o.textContent,
			format: fmt.value === 'birdnet' ? fmt.selectedOptions[0].textContent + ', ' + lang.selectedOptions[0].textContent : fmt.selectedOptions[0].textContent, folder: bxFolder})).then(function (ok) {
		if (!ok) return;
		var body = fmt.value === 'birdnet' ? {action: 'legacy', loc: parseInt(loc.value, 10), lang: lang.value, folder: bxFolder}
			: {action: 'backup', loc: parseInt(loc.value, 10), folder: bxFolder, incremental: inc};
		ldRun(body, 'bxmsg');
	});
}
function rsGo() {
	var to = document.getElementById('rsto').value;
	bsAsk(t('str_import_the_folder_into_this_station', {folder: rsFolder}), true).then(function (ok) { if (ok) ldRun({action: 'restore', folder: rsFolder, to: to}, 'rsmsg'); });
}
function ldStation() { try { return localStorage.getItem('ld_station') || ''; } catch (e) { return ''; } }
function ldCtl(what) {
	var x = new XMLHttpRequest();
	x.onload = function () { var t = this.status === 401 ? window.t('str_log_in_first') : this.responseText.trim(); if (t !== 'OK') bsNotice(t); setTimeout(ldPoll, 600); };
	x.open('POST', 'scripts/location_data.php?ctl=' + what, true); x.send();
}
function ldJobClose(finished) {
	try { localStorage.setItem('ld_job_seen', finished); } catch (e) {}
	document.getElementById('ldjob').style.display = 'none';
}
function ldPoll() {
	clearTimeout(ldTimer);
	ldGetJ('scripts/location_data.php?job=1', function (j) {
		var box = document.getElementById('ldjob');
		if (!j || !j.status) { box.style.display = 'none'; return; }
		// a finished job's report shows until it is closed, and for an hour at most (owner 2026-10-24: an old report stayed)
		if (['done', 'cancelled', 'error'].indexOf(j.status) >= 0) {
			var seen = ''; try { seen = localStorage.getItem('ld_job_seen') || ''; } catch (e) {}
			var age = j.finished ? (Date.now() - new Date(String(j.finished).replace(' ', 'T')).getTime()) / 60000 : 0;
			if (seen === (j.finished || '') || age > 60) { box.style.display = 'none'; return; }
		}
		box.style.display = '';
		var names = {remove: t('str_deleting'), period: t('str_deleting'), backup: t('str_backing_up'), restore: t('str_importing'), migrate: t('str_importing'), reassign: t('str_moving'), legacy: t('str_exporting')};
		var live = j.status === 'running' || j.status === 'paused', reassign = j.action === 'reassign';
		document.getElementById('ldctl').style.display = live && !reassign ? '' : 'none';
		document.getElementById('ldpause').style.display = j.status === 'running' ? '' : 'none';
		document.getElementById('ldcont').style.display = j.status === 'paused' ? '' : 'none';
		if (live || j.status === 'cancelling') {
			document.getElementById('ldjobtxt').textContent = (j.status === 'cancelling' ? t('str_cancelling_undoing') + ' ' : j.status === 'paused' ? t('str_paused', {time: j.paused_at || ''}) + ' ' : '')
				+ (names[j.action] || j.action) + ' ' + (j.location || '') + ': ' + (j.total ? t('str_done_of_total', {done: window.fmtNum((j.done || 0)), total: window.fmtNum(j.total)}) : window.fmtNum((j.done || 0)));
			document.getElementById('ldbar').style.width = (j.total ? 100 * j.done / j.total : 0) + '%';
			ldTimer = setTimeout(ldPoll, 2000);
		} else {
			document.getElementById('ldbar').style.width = j.status === 'done' ? '100%' : '0';
			document.getElementById('ldjobtxt').innerHTML = (j.status === 'done' || j.status === 'cancelled' ? (j.status === 'done' ? '&#10003; ' : '') + esc(j.result) : '<span style="color:#c62828">' + t('str_stopped', {action: esc(names[j.action] || j.action), error: esc(j.error)}) + '</span>')
				+ ' <span class="small">(' + esc(j.finished) + ')</span>'
				+ (j.status === 'done' && j.action === 'remove' && j.target && ldStation() ? '<br>' + t('str_now_restore_it_on_the_other_help') + ' <a target="_top" href="' + location.protocol + '//' + esc(ldStation()) + '/?view=Backup%2FImport">' + t('str_open_backup_restore_there') + '</a>' : '')
				+ ' <button type="button" class="ldbtn" onclick="location.reload()">' + t('str_refresh_the_table') + '</button>'
				+ ' <button type="button" class="ldbtn" onclick="ldJobClose(' + esc(JSON.stringify(j.finished || '')) + ')">' + t('str_close') + '</button>';
		}
	});
}
// Export starts in the folder of the sounds, Import in the home folder (owner 2026-10-24)

// the life cycle settings of Data (saved at once): the table, the external folder and the disk summary
	function diskSave(btn) {
		var body = {};
		document.querySelectorAll('#disktab input[type=number]').forEach(function (i) { body[i.name] = i.value; });
		var fd = document.querySelector('#disktab input[name=FULL_DISK]:checked'); if (fd) body.FULL_DISK = fd.value;
		if (fd && fd.value === 'external') {
			if (!diskExtTree || !diskExtTree.selected()) { document.getElementById('diskmsg').textContent = t('str_choose_the_destination_folder'); return; }
			body.FULL_DISK_PATH = diskExtTree.selected();
			var fb = document.querySelector('#disktab input[name=FULL_DISK_FALLBACK]:checked'); if (fb) body.FULL_DISK_FALLBACK = fb.value;
		}
		var m = document.getElementById('diskmsg'); m.textContent = t('str_saving');
		var x = new XMLHttpRequest();
		x.onload = function () {
			var r = this.responseText.trim();
			if (r === 'OK') location.reload();
			// saved, but the external folder cannot be used now: said, the page stays
			else if (r.indexOf('OK ') === 0) m.textContent = t('str_saved_dot') + ' ' + t('str_the_external_backup_is_not_help') + ' ' + r.slice(3);
			else m.textContent = this.status === 401 ? t('str_log_in_first') : (r || t('str_not_saved'));
		};
		x.open('POST', 'scripts/location_data.php?disk=1', true); x.setRequestHeader('Content-Type', 'application/json'); x.send(JSON.stringify(body));
	}
	// External backup: the destination folder tree and the fallback, shown only with that choice (US-128)
	var diskExtTree = null;
	function diskExt() {
		var on = (document.querySelector('#disktab input[name=FULL_DISK]:checked') || {}).value === 'external';
		document.querySelectorAll('#disktab tr.diskext').forEach(function (r) { r.style.display = on ? '' : 'none'; });
		if (on && !diskExtTree) { var el = document.getElementById('diskextdirs'); diskExtTree = folderPicker(el, el.dataset.path || '', function () {}); }
	}
	window.addEventListener('load', diskExt);
	function diskCount(btn) {
		var pre = document.getElementById('diskcount'); pre.style.display = ''; pre.textContent = t('str_loading_please_wait');
		var x = new XMLHttpRequest();
		x.onload = function () { pre.textContent = this.status === 401 ? t('str_log_in_first') : this.responseText; };
		x.open('GET', 'scripts/location_data.php?disk_count=1', true); x.send();
	}
