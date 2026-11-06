<?php
################################################################################
# scripts/external_files.php - EXTERNAL FILES
#
# PURPOSE:
#   External Files (US-119, owner 2026-10-20): the species in the audio of any folder of the station or of a
#   mounted network share, with the station's model — the results are written beside the audio
#   (scripts/external_files.py) and nothing enters the base. Then, optionally, Import: the detections enter
#   this station at the analysis location (copy or move of the audio into External in the data folder);
#   another registered station imports on its own page. Opened as views.php?view=External%20files.
#
# DEPENDENCIES:
#   - scripts/common.php
#   - homepage/static/species-modal.js
#   - homepage/static/location-picker.js
#   - homepage/static/folder-picker.js
#   - scripts/external_files.py
#
# USAGE:
#   views.php?view=External%20files
#   Referenced by homepage/static/folder-picker.js, homepage/views.php, scripts/external_files.py
################################################################################

require_once __DIR__ . '/common.php';

$home = get_home();
$user = get_user();
$py = BS_PYTHON;
$engine = BS_SCRIPTS . '/external_files.py';

// the handlers below answer the page's own requests: login and same-origin checked (2026-10-23 security run: they were
// open to anyone, also with a web password set); the page itself is checked by views.php
if (basename($_SERVER['SCRIPT_FILENAME'] ?? '') !== 'views.php') ensure_authenticated();

// the folders that can be browsed (owner 2026-10-29): the whole station, each seen with the station user's own rights
// (common.php station_user_*): what that user cannot read is not shown; the home, USB media and mounted shares are the
// shortcuts
function ext_roots() {
	return array_values(array_map(function ($r) { return $r['path']; }, array_filter(folder_roots(), function ($r) { return $r['ok']; })));
}
function ext_folder($p) {
	return root_folder($p);
}
function ext_audio($dir) {
	return array_values(array_filter(station_user_ls($dir)['files'], function ($f) {
		return preg_match('/\.(wav|flac|mp3|ogg|m4a|aac|opus|wma|aif|aiff)$/i', $f);
	}));
}
function ext_json($a) { header('Content-Type: application/json'); echo json_encode($a, JSON_UNESCAPED_UNICODE); die(); }
function ext_csv($path) {
	$rows = array();
	$txt = station_user_read($path);
	if ($txt === false) return $rows;
	$f = fopen('php://temp', 'r+'); fwrite($f, $txt); rewind($f);
	$head = fgetcsv($f);
	while (($r = fgetcsv($f)) !== false) if ($head && count($r) === count($head)) $rows[] = array_combine($head, $r);
	fclose($f);
	return $rows;
}
function ext_launch($args) {
	global $py, $engine, $user;
	$cmd = station_prefix() . 'nohup nice -n 19 ' . escapeshellarg($py) . ' ' . escapeshellarg($engine);
	foreach ($args as $a) $cmd .= ' ' . escapeshellarg((string)$a);
	shell_exec($cmd . ' > /dev/null 2>&1 &');
}

if (isset($_GET['roots'])) {
	// every starting folder, the ones the station user cannot enter too (ok false: shown greyed with the reason)
	ext_json(array_map(function ($r) { return array('path' => $r['path'], 'name' => $r['path'], 'ok' => $r['ok']); }, folder_roots()));
}
if (isset($_GET['ls'])) {
	$d = ext_folder($_GET['ls']);
	if ($d === false) ext_json(array('error' => t('str_folder_not_allowed')));
	$ls = station_user_ls($d);
	if ($d === '/') $ls['dirs'] = array_values(array_filter($ls['dirs'], function ($x) { return ext_folder('/' . $x) !== false; }));
	$audio = array_filter($ls['files'], function ($f) { return preg_match('/\.(wav|flac|mp3|ogg|m4a|aac|opus|wma|aif|aiff)$/i', $f); });
	// the subfolders that are a backup (BirdSuite location.json or BirdNET-Pi birds.db): marked in the folder tree
	$bk = array();
	foreach (explode("\0", (string)shell_exec(station_user_cmd('find ' . escapeshellarg($d) . ' -mindepth 2 -maxdepth 2 \\( -name location.json -o -name birds.db \\) -printf ' . escapeshellarg('%h\0') . ' 2>/dev/null'))) as $x)
		if ($x !== '') $bk[basename($x)] = true;
	ext_json(array('path' => $d, 'parent' => ext_folder(dirname($d)) ?: '', 'dirs' => $ls['dirs'], 'backups' => array_keys($bk), 'audio' => count($audio),
		'results' => in_array('birdnet-external.json', $ls['files'], true)));
}
// an import whose process is gone (station restarted, process killed): the analysis results stay, the import shows as
// stopped, so it can be started again (the detections already imported are skipped).
// An analysis whose process is gone the same way (power loss, crash: 2026-11-03 destructive phase EXT-06, it stayed
// "running" for ever and Analyse again was refused): shown as interrupted, and Analyse again continues it (the files
// already done are skipped). Only with a recorded pid (an analysis of an older version has none: left as it is)
function ext_alive($pid) {
	return intval($pid) > 0 && strpos((string)@file_get_contents('/proc/' . intval($pid) . '/cmdline'), 'external_files.py') !== false;
}
function ext_state($d) {
	$st = json_decode((string)station_user_read("$d/birdnet-external.json"), true) ?: array();
	if (in_array($st['status'] ?? '', array('running', 'paused'), true) && intval($st['pid'] ?? 0) > 0 && !ext_alive($st['pid'])) {
		$st['status'] = 'cancelled';
		$st['interrupted'] = true;
	}
	if (in_array($st['status'] ?? '', array('importing', 'import_paused', 'import_cancelling'), true)
			&& !ext_alive($st['import_pid'] ?? 0)) {
		$st['status'] = 'done';
		$st['import_error'] = t('str_interrupted_the_import_help');
	}
	return $st;
}
if (isset($_GET['status'])) {
	$d = ext_folder($_GET['status']);
	if ($d === false) ext_json(array('error' => t('str_folder_not_allowed')));
	$st = ext_state($d);
	$st['audio'] = count(ext_audio($d));
	$st['species_rows'] = in_array($st['status'] ?? '', array('done', 'importing', 'import_paused', 'import_cancelling', 'imported'), true) ? ext_csv("$d/birdnet-external-species.csv") : array();
	ext_json($st);
}
if (isset($_GET['dets'])) {
	$d = ext_folder($_GET['dets']);
	if ($d === false) ext_json(array());
	$sci = (string)($_GET['sci'] ?? '');
	$rows = array_values(array_filter(ext_csv("$d/birdnet-external-detections.csv"), function ($r) use ($sci) { return $r['sci_name'] === $sci; }));
	usort($rows, function ($a, $b) { return $b['confidence'] <=> $a['confidence']; });
	ext_json(array_slice($rows, 0, 50));
}
// a few seconds of a file around a detection, as MP3, to listen to
if (isset($_GET['clip'])) {
	$d = ext_folder($_GET['clip']);
	$f = basename((string)($_GET['file'] ?? ''));
	if ($d === false || $f === '' || !station_user_is_file("$d/$f")) { http_response_code(404); die(); }
	$s = max(0, floatval($_GET['start'] ?? 0) - 1.5);
	header('Content-Type: audio/mpeg');
	passthru(station_user_cmd('ffmpeg -v error -ss ' . escapeshellarg((string)$s) . ' -t 6 -i ' . escapeshellarg("$d/$f") . ' -ac 1 -f mp3 - 2>/dev/null'));
	die();
}
// pause / continue / cancel an analysis (owner 2026-10-20), as the station user who runs it
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_GET['ctl'])) {
	ensure_authenticated(t('str_you_must_control_the_analysis_help'));
	$p = json_decode((string)file_get_contents('php://input'), true) ?: array();
	$d = ext_folder($p['folder'] ?? '');
	$act = $_GET['ctl'];
	if ($d === false || !in_array($act, array('pause', 'continue', 'cancel'), true)) { echo 'Error'; die(); }
	global $py, $engine, $user;
	$out = shell_exec(station_prefix() . '' . escapeshellarg($py) . ' ' . escapeshellarg($engine) . ' ' . escapeshellarg($act) . ' ' . escapeshellarg($d) . ' 2>&1');
	$r = json_decode(trim((string)$out), true);
	echo ($r['ok'] ?? false) ? 'OK' : ($r['error'] ?? trim((string)$out));
	die();
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (isset($_GET['start']) || isset($_GET['import']))) {
	ensure_authenticated(t('str_you_must_analyse_or_import_help'));
	$p = json_decode((string)file_get_contents('php://input'), true) ?: array();
	$d = ext_folder($p['folder'] ?? '');
	if ($d === false) { echo t('str_folder_not_allowed'); die(); }
	// the station user writes the results beside the audio
	exec(station_prefix() . 'test -w ' . escapeshellarg($d), $o, $rc);
	if ($rc !== 0) { echo t('str_the_station_the_results_help'); die(); }
	$st = ext_state($d);
	if (in_array($st['status'] ?? '', array('running', 'importing', 'paused', 'import_paused', 'import_cancelling'), true)) { echo t('str_an_analysis_or_import_is_help'); die(); }
	if (isset($_GET['start'])) {
		if (!ext_audio($d)) { echo t('str_no_audio_file_in_this_folder'); die(); }
		if (($p['loc'] ?? '') === 'new') {
			$name = trim(preg_replace('/[\x00-\x1F\x7F<>"]/', '', (string)($p['name'] ?? '')));
			$lat = floatval($p['lat'] ?? ''); $lon = floatval($p['lon'] ?? '');
			if ($name === '' || abs($lat) > 90 || abs($lon) > 180 || ($lat == 0 && $lon == 0)) { echo t('str_a_name_for_the_new_location_help'); die(); }
			// a location of its own, even close to another one (each detection belongs to the nearest centre), owner 2026-10-20
			$list = locations_load();
			$same = array_filter($list, function ($x) use ($name) { return mb_strtolower($x['name']) === mb_strtolower($name); });
			if ($same) { $l = array_values($same)[0]; }
			else {
				$l = array('id' => max(array_map('intval', array_column($list, 'id'))) + 1, 'name' => mb_substr($name, 0, 60), 'lat' => round($lat, 4), 'lon' => round($lon, 4),
									 'radius_km' => LOCATION_RADIUS_KM, 'timezone' => timezone_for($lat, $lon)[0], 'species_list' => (string)(get_config()['SPECIES_LIST'] ?? ''));
				$list[] = $l;
				if (!locations_save($list)) { echo t('str_error_writing_the_locations'); die(); }
			}
		} else {
			$l = location_by_id($p['loc'] ?? 0) ?: location_current();
			if (!$l) { echo t('str_choose_a_location'); die(); }
		}
		$args = array('analyze', $d, '--lat', $l['lat'], '--lon', $l['lon'], '--loc-id', $l['id'], '--loc-name', $l['name']);
		// an analysis interrupted by a power loss or a crash continues where it stopped (the files done are skipped,
		// their detections kept: EXT-06), when it goes on with the same location; another location starts over (the
		// results of the files done were made with the other coordinates); a cancelled one starts over as it says
		if (!empty($st['interrupted']) && intval($st['location']['id'] ?? -1) === intval($l['id'])) $args[] = '--resume';
		ext_launch($args);
	} else {
		if (!in_array($st['status'] ?? '', array('done', 'imported'), true)) { echo t('str_analyse_the_folder_first'); die(); }
		$args = array('import', $d, '--mode', ($p['mode'] ?? '') === 'move' ? 'move' : 'copy');
		$l = location_by_id($p['loc'] ?? 0);
		if ($l) { $args[] = '--lat'; $args[] = $l['lat']; $args[] = '--lon'; $args[] = $l['lon']; $args[] = '--loc-id'; $args[] = $l['id']; $args[] = '--loc-name'; $args[] = $l['name']; }
		ext_launch($args);
	}
	echo 'OK';
	die();
}

$cur = location_current();
$stations = is_file(BS_CONFIG . '/stations.json') ? (json_decode((string)file_get_contents(BS_CONFIG . '/stations.json'), true) ?: array()) : array();
?>
<style>
.ext { max-width: 1000px; margin: 0; text-align: left; padding: 0 12px; }
.ext .box { background: var(--panel, #f4f6f8); border-radius: 10px; padding: 10px 14px; margin: 10px 0; }
.ext .crumbs { font-family: monospace; font-size: 13px; margin-bottom: 6px; word-break: break-all; }
.ext .crumbs a { cursor: pointer; color: var(--accent, #1f4e79); }
.ext .dirs { display: flex; flex-wrap: wrap; gap: 6px; max-height: 240px; overflow: auto; }
.ext .dirs button { width: auto; padding: 4px 10px; border-radius: 8px; border: 1px solid #ccc; background: #fff; cursor: pointer; font-size: 13px; }
.ext .extbtn { width: auto; padding: 5px 14px; border-radius: 12px; border: 1px solid var(--accent,#2b5e22); background: var(--accent,#2b5e22); color: #fff; font-weight: 600; cursor: pointer; }
.ext .extbtn.light { background: #fff; color: var(--accent,#2b5e22); }
.ext .extbtn:disabled { opacity: .5; cursor: default; }
.ext .bar { height: 10px; background: #ddd; border-radius: 5px; overflow: hidden; margin: 6px 0; }
.ext .bar i { display: block; height: 100%; background: var(--accent,#2b5e22); width: 0; }
.ext .msg { font-weight: 600; margin-left: 8px; }
.ext .row { display: flex; flex-wrap: wrap; gap: 10px; align-items: center; margin: 6px 0; }
.ext table.stdtable td.num { text-align: right; }
.ext .dets td { font-size: 13px; }
.ext .small { font-size: 12px; opacity: .8; }
</style>
<div class="ext">
	<div class="box">
		<div class="row"><b><?php echo t('str_1_folder'); ?></b> <span class="small"><?php echo t('str_the_stations_disk_or_a_network_help'); ?></span></div>
		<div class="crumbs" id="extcrumbs"></div>
		<div class="dirs" id="extdirs"></div>
		<div class="row"><span id="extcount"></span> <button type="button" class="extbtn light" id="extuse" onclick="extUse()" disabled><?php echo t('str_use_this_folder'); ?></button></div>
	</div>
	<div id="extwork" style="display:none">
		<div class="box" id="extform">
			<div class="row"><b><?php echo t('str_2_analyse'); ?></b> <span class="small" id="extfolder"></span></div>
			<div class="row"><?php echo t('str_recorded_at'); ?>
				<select id="extloc" onchange="document.getElementById('extnew').style.display = this.value === 'new' ? '' : 'none'">
					<?php foreach (locations_load() as $l) echo '<option value="' . intval($l['id']) . '"' . ($cur && intval($cur['id']) === intval($l['id']) ? ' selected' : '') . '>'
						. htmlspecialchars($l['name']) . ($cur && intval($cur['id']) === intval($l['id']) ? ' (' . t('str_current') . ')' : '') . '</option>'; ?>
					<option value="new"><?php echo t('str_plus_new_location'); ?></option>
				</select>
				<span id="extnew" style="display:none"><input id="extname" placeholder="<?php echo htmlspecialchars(t('str_name'), ENT_QUOTES); ?>" size="14"> <input id="extlat" placeholder="<?php echo htmlspecialchars(t('str_latitude'), ENT_QUOTES); ?>" size="9"> <input id="extlon" placeholder="<?php echo htmlspecialchars(t('str_longitude'), ENT_QUOTES); ?>" size="9">
					<button type="button" class="extbtn light" id="extmapbtn" title="<?php echo htmlspecialchars(t('str_pick_the_place_on_a_map_a_click_help'), ENT_QUOTES); ?>">&#128506; <?php echo t('str_map'); ?></button></span>
				<button type="button" class="extbtn" id="extstart" onclick="extStart()"><?php echo t('str_analyse'); ?></button><span class="msg" id="extmsg"></span></div>
			<div id="extmapbox"></div>
			<div class="small"><?php echo t('str_the_location_model_of_that_help'); ?></div>
		</div>
		<div class="box" id="extprog" style="display:none"><div id="extprogtxt"></div><div class="bar"><i id="extbar"></i></div>
			<div class="row"><button type="button" class="extbtn light" id="extpause" onclick="extCtl('pause')"><?php echo t('str_pause'); ?></button>
				<button type="button" class="extbtn light" id="extcont" onclick="extCtl('continue')" style="display:none"><?php echo t('str_continue'); ?></button>
				<button type="button" class="extbtn light" id="extcancel" onclick="bsAsk(this.dataset.q || t('str_cancel_q'), true).then(function (ok) { if (ok) extCtl('cancel'); })" style="border-color:#c62828;color:#c62828"><?php echo t('str_cancel'); ?></button>
				<span class="small"><?php echo t('str_pause_frees_the_stations_help'); ?></span></div></div>
		<div id="extres" style="display:none">
			<div class="box"><div id="extsummary"></div></div>
			<table class="stdtable" id="exttable"><tr><th><?php echo t('str_species'); ?></th><th><?php echo t('str_detections'); ?></th><th><?php echo t('str_best'); ?></th><th><?php echo t('str_first'); ?></th><th><?php echo t('str_last'); ?></th><th><?php echo t('str_files'); ?></th><th></th></tr></table>
			<div class="box" id="extimport">
				<div class="row"><b><?php echo t('str_3_import_optional'); ?></b></div>
				<?php // the other stations only with several stations (simple mode, US-125): the list stays, hidden, on this station ?>
				<div class="row"<?php echo multi_station() ? '' : ' style="display:none"'; ?>><?php echo t('str_into_station'); ?> <select id="extstation">
					<option value=""><?php echo t('str_this_station_name', array('name' => htmlspecialchars(get_sitename()))); ?></option>
					<?php foreach ($stations as $s) {
						$a = $s['addresses'][0] ?? '';
						if ($a === '' || strcasecmp($s['name'], get_sitename()) === 0) continue;
						echo '<option value="' . htmlspecialchars($a) . '">' . htmlspecialchars($s['name'] . ' (' . $a . ')') . '</option>';
					} ?></select>
					<?php echo t('str_at_location'); ?> <select id="extimploc"><?php foreach (locations_load() as $l) echo '<option value="' . intval($l['id']) . '">' . htmlspecialchars($l['name']) . '</option>'; ?></select>
					<a href="views.php?view=Locations" class="small"><?php echo t('str_new_location_lc_ellipsis'); ?></a></div>
				<div class="row"><?php echo t('str_audio_files'); ?> <label><input type="radio" name="extmode" value="copy" checked> <?php echo t('str_copy_into_the_station_the_help'); ?></label>
					<label><input type="radio" name="extmode" value="move"> <?php echo t('str_move_into_the_station'); ?></label></div>
				<div class="row"><button type="button" class="extbtn" id="extimpbtn" onclick="extImport()"><?php echo t('str_import'); ?></button><span class="msg" id="extimpmsg"></span></div>
				<div class="small"><?php echo t('str_the_detections_get_a_clip_and_a_help'); ?><br>
					<?php echo t('str_the_audio_goes_to_and_lt_folder_help', array('dir' => htmlspecialchars(BS_RECS . '/External'))); ?></div>
			</div>
		</div>
	</div>
</div>
<script src="static/species-modal.js?v=<?php echo @filemtime(__DIR__ . '/../homepage/static/species-modal.js'); ?>"></script>
<script src="static/location-picker.js?v=<?php echo @filemtime(__DIR__ . '/../homepage/static/location-picker.js'); ?>"></script>
<script src="static/folder-picker.js?v=<?php echo @filemtime(__DIR__ . '/../homepage/static/folder-picker.js'); ?>"></script>
<script>
var extPath = '', extTimer = null, extState = null;
var esc = function (s) { return String(s == null ? '' : s).replace(/[&<>"]/g, function (c) { return {'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;'}[c]; }); };
function extGet(url, done) { var x = new XMLHttpRequest(); x.onload = function () { try { done(JSON.parse(this.responseText)); } catch (e) { done(null); } }; x.open('GET', url, true); x.send(); }
function extPost(url, body, done) {
	var x = new XMLHttpRequest();
	x.onload = function () { done(this.status === 401 ? t('str_log_in_first') : this.responseText.trim()); };
	x.open('POST', url, true); x.setRequestHeader('Content-Type', 'application/json'); x.send(JSON.stringify(body));
}
// the folder: the tree of the whole station as the station user sees it, shortcuts above (static/folder-picker.js,
// owner 2026-10-29); the audio count of the folder chosen and Use this folder follow it
var extTree = null;
function extLs(p) {
	if (extTree) { extTree.goTo(p); return; }
	document.getElementById('extcrumbs').style.display = 'none';
	extTree = folderPicker('extdirs', p, function (path, r) {
		extPath = path;
		try { localStorage.setItem('ext_folder', path); } catch (e) {}
		document.getElementById('extcount').textContent = (r.audio === 1 ? t('str_audio_file_here', {n: r.audio}) : t('str_audio_files_here', {n: r.audio})) + (r.results ? t('str_analysed_before') : '');
		document.getElementById('extuse').disabled = !r.audio && !r.results;
	});
}
function extRoots() { extLs(''); }
function extUse() {
	document.getElementById('extwork').style.display = '';
	document.getElementById('extfolder').textContent = extPath;
	extPoll();
}
function extStart() {
	var loc = document.getElementById('extloc').value;
	var body = {folder: extPath, loc: loc};
	if (loc === 'new') { body.name = document.getElementById('extname').value; body.lat = document.getElementById('extlat').value.replace(',', '.'); body.lon = document.getElementById('extlon').value.replace(',', '.'); }
	document.getElementById('extmsg').textContent = t('str_starting');
	extPost('scripts/external_files.php?start=1', body, function (t) { document.getElementById('extmsg').textContent = t === 'OK' ? '' : t; if (t === 'OK') setTimeout(extPoll, 1500); });
}
function extImport() {
	var st = document.getElementById('extstation').value;
	if (st) { // another station imports on its own page (the folder must be reachable there too)
		window.top.location.href = location.protocol + '//' + st + '/?view=External%20files';
		return;
	}
	var mode = document.querySelector('input[name=extmode]:checked').value, ls = document.getElementById('extimploc');
	var locName = ls.selectedOptions[0] ? ls.selectedOptions[0].textContent : '';
	// moving the audio files out of the folder is the disruptive one: No is its default answer
	bsAsk(mode === 'move' ? t('str_import_detections_and_help', {count: extState.detections || 0, location: locName})
		: t('str_import_detections_into_this_help', {count: extState.detections || 0, location: locName}), mode === 'move').then(function (ok) {
		if (!ok) return;
		document.getElementById('extimpmsg').textContent = t('str_starting');
		extPost('scripts/external_files.php?import=1', {folder: extPath, mode: mode, loc: ls.value}, function (t) { document.getElementById('extimpmsg').textContent = t === 'OK' ? '' : t; if (t === 'OK') setTimeout(extPoll, 1500); });
	});
}
function extCtl(what) {
	extPost('scripts/external_files.php?ctl=' + what, {folder: extPath}, function (t) { if (t !== 'OK') bsNotice(t); setTimeout(extPoll, 800); });
}
function extPoll() {
	clearTimeout(extTimer);
	extGet('scripts/external_files.php?status=' + encodeURIComponent(extPath), function (s) {
		if (!s) return;
		extState = s;
		var imp = ['importing', 'import_paused', 'import_cancelling'].indexOf(s.status) >= 0;
		var running = s.status === 'running' || imp, paused = s.status === 'paused';
		document.getElementById('extstart').disabled = running || paused;
		document.getElementById('extimpbtn').disabled = running || paused;
		var prog = document.getElementById('extprog');
		prog.style.display = running || paused ? '' : 'none';
		document.getElementById('extpause').style.display = s.status === 'running' || s.status === 'importing' ? '' : 'none';
		document.getElementById('extcont').style.display = paused || s.status === 'import_paused' ? '' : 'none';
		document.getElementById('extcancel').style.display = s.status === 'running' || paused || s.status === 'importing' || s.status === 'import_paused' ? '' : 'none';
		document.getElementById('extcancel').dataset.q = imp ? t('str_cancel_the_import_the_help')
			: t('str_cancel_the_analysis_what_was_help');
		if (paused) {
			var pp = s.progress || {};
			document.getElementById('extprogtxt').textContent = t('str_paused_at_file_of_min_of_audio_help', {time: s.paused_at || '', file: Math.min((pp.files_done || 0) + 1, pp.files_total || 0), total: pp.files_total || 0, min: Math.round((pp.seconds_done || 0) / 60)});
		}
		if (s.status === 'cancelled') document.getElementById('extmsg').textContent = s.interrupted ? t('str_interrupted_the_analysis_help') : t('str_cancelled_what_was_found_is_in_help');
		if (s.status === 'running') {
			var p = s.progress || {};
			document.getElementById('extprogtxt').textContent = t('str_analysing_file_of_min_of_audio_help', {file: Math.min((p.files_done || 0) + 1, p.files_total || 0), total: p.files_total || 0, name: p.current || '', min: Math.round((p.seconds_done || 0) / 60)});
			document.getElementById('extbar').style.width = (p.files_total ? 100 * (p.files_done || 0) / p.files_total : 0) + '%';
		} else if (imp) {
			var ip = s.import_progress || {};
			document.getElementById('extprogtxt').textContent = (function (v) { return s.status === 'import_paused' ? t('str_import_paused_at_of_detections', v)
				: s.status === 'import_cancelling' ? t('str_cancelling_the_import_undoing_help', v) : t('str_importing_of_detections', v); })({time: s.paused_at || '', done: ip.done || 0, total: ip.total || 0});
			document.getElementById('extbar').style.width = (ip.total ? 100 * (ip.done || 0) / ip.total : 0) + '%';
			document.getElementById('extbar').style.width = (ip.total ? 100 * ip.done / ip.total : 0) + '%';
		}
		if (s.status === 'error') document.getElementById('extmsg').textContent = t('str_the_analysis_stopped', {error: s.error || ''});
		var res = document.getElementById('extres');
		res.style.display = ['done', 'imported'].indexOf(s.status) >= 0 || imp ? '' : 'none';
		if (res.style.display === '') extResults(s);
		if (running) extTimer = setTimeout(extPoll, 3000);
	});
}
function extResults(s) {
	var loc = s.location || {};
	var ls = document.getElementById('extimploc');
	if (loc.id && !ls.dataset.touched) ls.value = String(loc.id);
	ls.onchange = function () { ls.dataset.touched = '1'; };
	document.getElementById('extsummary').innerHTML = window.t('str_detections_of_species_in_files_help', {detections: s.detections || 0, species: s.species || 0, files: (s.progress || {}).files_total, location: esc(loc.name), date: esc(s.finished), model: esc(s.model)})
		+ ' (' + window.t('str_min_conf') + ' ' + esc((s.settings || {}).confidence) + ')'
		+ ((s.errors || []).length ? '<br><span class="small">' + window.t('str_file_s_could_not_be_read', {n: s.errors.length, files: esc(s.errors.map(function (e) { return e.file; }).join(', '))}) + '</span>' : '')
		+ '<br><span class="small">' + window.t('str_saved_in_this_folder', {files: 'birdnet-external-detections.csv, birdnet-external-species.csv'}) + '</span>'
		+ (s.import_note ? '<br>' + esc(s.import_note) : '')
		+ (s.imported ? '<br>&#10003; ' + (s.imported.mode === 'move' ? window.t('str_imported_new_moved_to_help', {date: esc(s.imported.at), count: s.imported.added, folder: esc(s.imported.audio_to)})
				: window.t('str_imported_new_copied_to_help', {date: esc(s.imported.at), count: s.imported.added, folder: esc(s.imported.audio_to)})) : '')
		+ (s.import_error ? '<br><span style="color:#c62828">' + window.t('str_import_stopped', {error: esc(s.import_error)}) + '</span>' : '');
	var t = document.getElementById('exttable');
	while (t.rows.length > 1) t.deleteRow(1);
	(s.species_rows || []).forEach(function (r) {
		var tr = t.insertRow(-1);
		tr.innerHTML = '<td><b>' + esc(r.com_name) + '</b> <i class="small">' + esc(r.sci_name) + '</i></td><td class="num">' + esc(r.count) + '</td><td class="num">' + Math.round(100 * r.best_confidence) + '%</td>'
			+ '<td>' + esc(r.first) + '</td><td>' + esc(r.last) + '</td><td class="num">' + esc(r.files) + '</td><td><button type="button" class="extbtn light">' + window.t('str_listen') + '</button></td>';
		tr.querySelector('button').onclick = function () { extDets(tr, r.sci_name); };
	});
}
function extDets(tr, sci) {
	var next = tr.nextElementSibling;
	if (next && next.classList.contains('dets')) { next.remove(); return; }
	extGet('scripts/external_files.php?dets=' + encodeURIComponent(extPath) + '&sci=' + encodeURIComponent(sci), function (rows) {
		var row = tr.parentNode.insertBefore(document.createElement('tr'), tr.nextSibling);
		row.className = 'dets';
		row.innerHTML = '<td colspan="7"><table style="width:100%">' + (rows || []).map(function (d) {
			return '<tr><td>' + esc(d.date + ' ' + d.time) + '</td><td>' + esc(d.file) + ' @ ' + Math.floor(d.start / 60) + ':' + ('0' + Math.floor(d.start % 60)).slice(-2) + '</td><td class="num">' + Math.round(100 * d.confidence) + '%</td>'
				+ '<td><audio controls preload="none" src="scripts/external_files.php?clip=' + encodeURIComponent(extPath) + '&file=' + encodeURIComponent(d.file) + '&start=' + d.start + '"></audio></td></tr>';
		}).join('') + '</table>' + ((rows || []).length >= 50 ? '<div class="small">' + t('str_the_50_most_confident') + '</div>' : '') + '</td>';
	});
}
(function () { var f = null; try { f = localStorage.getItem('ext_folder'); } catch (e) {} if (f) extLs(f); else extRoots(); })();
locationPicker({lat: document.getElementById('extlat'), lon: document.getElementById('extlon'), button: document.getElementById('extmapbtn'),
	box: document.getElementById('extmapbox'), home: [<?php $c = get_config(); echo floatval($c['LATITUDE'] ?? 0) . ', ' . floatval($c['LONGITUDE'] ?? 0); ?>]});
</script>
