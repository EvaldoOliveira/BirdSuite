<?php
################################################################################
# scripts/location_data.php - THE DATA JOBS: ENDPOINTS AND THE PER-LOCATION ROWS OF DATA AND BACKUP/IMPORT
#
# PURPOSE:
#   The endpoints behind Data › Data and Data › Backup/Import (the job state, the import peek, the disk
#   settings, the period preview, the job controls and the job start), and ld_rows(), the per-location rows
#   both pages show. The work runs in the background as the station user (scripts/location_data.py), one job
#   at a time. The pages themselves are data_page.php and backup_import_page.php (owner 2026-11-06: one file
#   served both).
#
# DEPENDENCIES:
#   - scripts/common.php
#   - scripts/location_data.py (the engine), scripts/disk_species_count.sh, scripts/clip_dispose.py
#
# USAGE:
#   Requested directly: ?job ?peek=<folder> ?period=1&from=&to=[&sci=] ?disk_count=1, POST ?disk=1
#   ?ctl=pause|continue|cancel ?run=1
#   Included by the pages (require_once): the functions only, no endpoint runs.
################################################################################

require_once __DIR__ . '/common.php';

$home = get_home();
$user = get_user();
$job_file = BS_STATE . '/location_job.json';

// a folder of the station user's home or a mounted share, seen with the station user's own rights (owner 2026-10-23)
function ld_folder($p) {
	return root_folder($p);
}
function ld_job() {
	global $job_file;
	$j = json_decode((string)@file_get_contents($job_file), true) ?: array();
	// a job whose process is gone (station restarted, process killed) is shown as stopped, so a new one can start
	if (in_array($j['status'] ?? '', array('running', 'paused', 'cancelling'), true)
			&& strpos((string)@file_get_contents('/proc/' . intval($j['pid'] ?? 0) . '/cmdline'), 'location_data.py') === false) {
		$j['status'] = 'error';
		$j['error'] = t('str_interrupted_the_job_was_help');
		$j['finished'] = $j['finished'] ?? '';
	}
	return $j;
}

// the rows of the two pages: per location its detections, removed ones, first and last day, format; the removed
// detections per species (Restore), the orphans (outside every location), the species of Remove by period
function ld_rows($ld_mode) {
	// per location: detections, removed ones, first and last day (main tables: the header filter does not apply).
	// 2026-11-03 destructive phase SCL-13 (3.2 s at ten times the station's data): a COUNT walks every detection of the
	// location, so the days before today are counted once and kept in the state folder until a past change (the
	// past_changes counters, kept by triggers: removal, restore, move, import) or a new day; today is counted live from
	// the (Location, Date) index; first and last day are two index lookups
	$db = new SQLite3(BS_DB, SQLITE3_OPEN_READONLY);
	$db->busyTimeout(5000);
	$today = (string)$db->querySingle("SELECT date('now', 'localtime')");
	$past_sig = (string)$db->querySingle("SELECT group_concat(Month || ':' || N) FROM (SELECT Month, N FROM past_changes ORDER BY Month)");
	$past_file = BS_STATE . '/cache/location-counts.json';
	$past = is_file($past_file) ? (json_decode((string)@file_get_contents($past_file), true) ?: array()) : array();
	$past_key = md5($today . '|' . $past_sig);
	if (($past['key'] ?? '') !== $past_key) $past = array('key' => $past_key, 'n' => array());
	$past_changed = false;
	$rows = array();
	foreach (locations_load() as $l) {
		$w = location_sql($l);
		$id = (string)intval($l['id']);
		if (!isset($past['n'][$id])) {
			$past['n'][$id] = intval($db->querySingle("SELECT COUNT(*) FROM main.detections WHERE $w AND Date < '" . SQLite3::escapeString($today) . "'"));
			$past_changed = true;
		}
		$n_today = intval($db->querySingle("SELECT COUNT(*) FROM main.detections WHERE $w AND Date >= '" . SQLite3::escapeString($today) . "'"));
		$l['n'] = $past['n'][$id] + $n_today;
		$l['first'] = (string)$db->querySingle("SELECT MIN(Date) FROM main.detections WHERE $w");
		$l['last'] = (string)$db->querySingle("SELECT MAX(Date) FROM main.detections WHERE $w");
		$l['removed'] = 0;
		// the Ignored column (owner 2026-10-24): audio to purge of detections that keep counting
		$l['ignored'] = 0;
		if ($ld_mode === 'data') {
			$pa = purge_audio_rows($db, $w);
			$l['ignored'] = array_sum(array_column($pa['causes'], 'n')) + array_sum(array_column($pa['others'], 'n'));
		}
		$rows[] = $l;
	}
	// the removed detections per location and species: Restore (all or one species) until Purge Removed (US-13)
	$removed_sp = array();
	$res = $db->query('SELECT Location, Sci_Name, COUNT(*) AS n FROM deleted_detections GROUP BY Location, Sci_Name ORDER BY n DESC');
	while ($res && ($r = $res->fetchArray(SQLITE3_ASSOC))) { $r['com'] = common_name($r['Sci_Name']); $removed_sp[intval($r['Location'])][] = $r; }
	foreach ($rows as $k => $l) $rows[$k]['removed'] = array_sum(array_column($removed_sp[intval($l['id'])] ?? array(), 'n'));
	// the removed detections of a location deleted since (or of none): one line "Outside every location" with the same
	// Restore (into the current location, play.php loc=none) and Purge Removed (run 5 RV2, PUR-01: 225 of them were shown
	// nowhere and the clips stayed in the Removed folder for good); the ids of those locations beside its name
	$orphans = array('n' => 0, 'ids' => array(), 'sp' => array());
	$known = array_flip(array_map(function ($l) { return intval($l['id']); }, $rows));
	foreach ($removed_sp as $lid => $list) {
		if (isset($known[$lid])) continue;
		$orphans['ids'][] = $lid;
		foreach ($list as $r) {
			$orphans['n'] += intval($r['n']);
			if (!isset($orphans['sp'][$r['Sci_Name']])) $orphans['sp'][$r['Sci_Name']] = array('Sci_Name' => $r['Sci_Name'], 'com' => $r['com'], 'n' => 0);
			$orphans['sp'][$r['Sci_Name']]['n'] += intval($r['n']);
		}
	}
	usort($orphans['sp'], function ($a, $b) { return $b['n'] <=> $a['n']; });
	if ($past_changed) {
		if (!is_dir(dirname($past_file))) @mkdir(dirname($past_file), 0775, true);
		$tmp = @tempnam(dirname($past_file), 'loc-');
		if ($tmp !== false && @file_put_contents($tmp, json_encode($past)) !== false) @rename($tmp, $past_file); elseif ($tmp) @unlink($tmp);
	}
	// the species detected, for Remove by period (one species or all): each species found by an index jump to the next
	// one (SCL-13: a GROUP BY walked the whole index, 1.1 s at ten times the data; this is a few milliseconds), its name
	// presentation only (common_name(), owner 2026-11-05), sorted by it in PHP
	$period_sp = array();
	if ($ld_mode === 'data') {
		$res = $db->query('WITH RECURSIVE s(sci) AS (SELECT MIN(Sci_Name) FROM main.detections'
			. ' UNION ALL SELECT (SELECT MIN(Sci_Name) FROM main.detections WHERE Sci_Name > s.sci) FROM s WHERE s.sci IS NOT NULL)'
			. ' SELECT sci AS Sci_Name FROM s WHERE sci IS NOT NULL');
		while ($res && ($r = $res->fetchArray(SQLITE3_ASSOC))) $period_sp[] = array('Sci_Name' => $r['Sci_Name'], 'com' => common_name($r['Sci_Name']));
		usort($period_sp, function ($a, $b) { return strcmp($a['com'], $b['com']); });
	}
	$db->close();
	// the format of each location's clips (owner 2026-10-24): BirdSuite (scientific name) or BirdNET (a common-name language)
	$fmts = $ld_mode !== 'data' ? array() : (json_decode((string)shell_exec(station_prefix() . 'timeout 60 python3 ' . escapeshellarg(BS_SCRIPTS . '/location_data.py') . ' formats 2>/dev/null'), true) ?: array());
	// (location_data.py formats: the clips not named by the scientific name; the others are the location's count minus them)
	$fmt_text = function ($f, $n) {
		if (!$f || $n === 0) return '';
		$lang = $f['lang'] !== '' ? t(common_name_languages()[$f['lang']] ?? $f['lang']) : t('str_unknown_language');
		if ($f['birdnet'] === 0) return t('str_scientific_birdsuite');
		if ($f['birdnet'] >= $n) return t('str_birdnet', array('lang' => $lang));
		return t('str_scientific_birdsuite_plus_birdnet', array('n' => fmt_num($f['birdnet']), 'lang' => $lang));
	};
	$cur = location_current();
	$stations = is_file(BS_CONFIG . '/stations.json') ? (json_decode((string)file_get_contents(BS_CONFIG . '/stations.json'), true) ?: array()) : array();
	$h = function ($s) { return htmlspecialchars((string)$s, ENT_QUOTES); };
	return compact('rows', 'removed_sp', 'orphans', 'period_sp', 'fmts', 'fmt_text', 'cur', 'stations', 'h');
}

// the endpoints: only when this file is the request (the pages include it for the functions above)
if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') !== __FILE__) return;

if (isset($_GET['job'])) { header('Content-Type: application/json'); echo json_encode(ld_job(), JSON_UNESCAPED_UNICODE); die(); }
if (isset($_GET['peek'])) { // a folder to import: what it holds, BirdSuite or BirdNET, and the language (location_data.py peek)
	ensure_authenticated();   // as every other endpoint of the page (Gitea #103: it ran the engine on any folder without the login)
	header('Content-Type: application/json');
	$d = ld_folder($_GET['peek']);
	$m = $d ? json_decode((string)shell_exec(station_prefix() . 'timeout 60 python3 ' . escapeshellarg(BS_SCRIPTS . '/location_data.py')
		. ' peek ' . escapeshellarg($d) . ' 2>/dev/null'), true) : null;
	if (is_array($m) && ($m['lang'] ?? '') !== '') $m['lang_name'] = t(common_name_languages()[$m['lang']] ?? $m['lang']);
	echo json_encode($m ?: array('error' => t('str_not_a_backup_no_location_json_help')), JSON_UNESCAPED_UNICODE);
	die();
}
// the disk management settings, edited under the life cycle of the sounds (owner 2026-10-24, formerly Settings ›
// Advanced › Disk Management): POST ?disk=1 with {KEY: value}; each key checked against its range
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_GET['disk'])) {
	ensure_authenticated(t('str_you_must_change_the_settings_help'));
	$p = json_decode((string)file_get_contents('php://input'), true) ?: array();
	// 0 keeps everything in each of them (owner 2026-10-24); a threshold under 20% other than 0 is raised to 20
	$ranges = array('PURGE_THRESHOLD' => array(0, 99), 'PURGE_PROTECT_TOP_N' => array(0, 100), 'MAX_FILES_SPECIES_DAY' => array(0, 100000),
		'PURGE_RECENT_DAYS' => array(0, 365), 'MAX_FILES_SPECIES' => array(0, 100000), 'IGNORED_PURGE_DAYS' => array(0, 3650));
	$c = array();
	$set = function ($k, $v) use (&$c) { $c[$k] = $v; };
	foreach ($ranges as $k => $r) if (isset($p[$k])) {
		$v = max($r[0], min($r[1], intval($p[$k])));
		if ($k === 'PURGE_THRESHOLD' && $v > 0 && $v < 20) $v = 20;
		$set($k, $v);
	}
	if (isset($p['FULL_DISK']) && in_array($p['FULL_DISK'], array('purge', 'keep', 'external'), true)) $set('FULL_DISK', $p['FULL_DISK']);
	// US-128: the external folder (one the station user can enter and write to) and what happens when it cannot be used
	$msg = 'OK';
	if (isset($p['FULL_DISK_PATH'])) {
		$d = $p['FULL_DISK_PATH'] === '' ? '' : root_folder($p['FULL_DISK_PATH']);
		if ($d === false || preg_match('/["`$\\\n]/', (string)$d)) { echo t('str_folder_not_allowed'); die(); }
		$set('FULL_DISK_PATH', (string)$d);
	}
	if (isset($p['FULL_DISK_FALLBACK']) && in_array($p['FULL_DISK_FALLBACK'], array('keep', 'purge'), true)) $set('FULL_DISK_FALLBACK', $p['FULL_DISK_FALLBACK']);
	if (!conf_set_many($c, $err)) { echo $err; die(); }
	if (($p['FULL_DISK'] ?? '') === 'external') {
		// said at once when the folder cannot be used (not writable, full, on the station's own disk)
		$out = trim((string)shell_exec(station_prefix() . escapeshellarg(BS_PYTHON) . ' ' . escapeshellarg(BS_SCRIPTS . '/clip_dispose.py') . ' --check 2>&1; echo " rc=$?"'));
		if (!preg_match('/ rc=0$/', $out) || strpos($out, 'fallback purge') !== false) $msg = 'OK ' . preg_replace('/ rc=\d+$/', '', $out);
	}
	echo $msg;
	die();
}
// Remove by period (US-13): what it would take, per species, with the totals of detections and files (JSON)
function ld_period_ok($from, $to) {
	return preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}$/', $from) && preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}$/', $to) && $from <= $to;
}
if (isset($_GET['period'])) {
	ensure_authenticated();
	header('Content-Type: application/json');
	$from = str_replace('T', ' ', (string)($_GET['from'] ?? '')); $to = str_replace('T', ' ', (string)($_GET['to'] ?? ''));
	$sci = (string)($_GET['sci'] ?? '');
	if (!ld_period_ok($from, $to) || !preg_match('/^[A-Za-z .()\'-]{0,80}$/', $sci)) { echo json_encode(array('error' => t('str_choose_a_valid_period'))); die(); }
	// a timeout (exit 124) or a failure of the engine is said as such (Gitea #104: both read "choose a valid period")
	exec(station_prefix() . 'timeout 60 python3 ' . escapeshellarg(BS_SCRIPTS . '/location_data.py') . ' period '
		. escapeshellarg($from) . ' ' . escapeshellarg($to) . ($sci !== '' ? ' ' . escapeshellarg($sci) : '') . ' 2>/dev/null', $lines, $rc);
	$out = implode("\n", $lines);
	if (json_decode($out, true) !== null) echo $out;
	else echo json_encode(array('error' => t($rc === 124 ? 'str_the_preview_took_too_long_help' : 'str_the_preview_failed_help')));
	die();
}
if (isset($_GET['disk_count'])) {
	ensure_authenticated();
	header('Content-Type: text/plain; charset=utf-8');
	echo (string)shell_exec(station_prefix() . escapeshellarg(BS_SCRIPTS . '/disk_species_count.sh') . ' 2>&1');
	die();
}
// pause / continue / cancel the running job (owner 2026-10-20) — cancel undoes what it did; signalled as the station user
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_GET['ctl'])) {
	ensure_authenticated(t('str_you_must_control_the_job_help'));
	if (!in_array($_GET['ctl'], array('pause', 'continue', 'cancel'), true)) { echo 'Error'; die(); }
	echo trim((string)shell_exec(station_prefix() . 'python3 ' . escapeshellarg(BS_SCRIPTS . '/location_data.py')
		. ' ctl ' . escapeshellarg($_GET['ctl']) . ' 2>&1 | tail -1'));
	die();
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_GET['run'])) {
	ensure_authenticated(t('str_you_must_change_the_location_data_help'));
	$p = json_decode((string)file_get_contents('php://input'), true) ?: array();
	$j = ld_job();
	if (in_array($j['status'] ?? '', array('running', 'paused', 'cancelling'), true)) { echo t('str_another_job_is_running_or_help'); die(); }
	$act = $p['action'] ?? '';
	$id = intval($p['loc'] ?? 0);
	if (!in_array($act, array('restore', 'period'), true) && !(in_array($act, array('legacy', 'backup'), true) && $id === 0) && !location_by_id($id)) { echo t('str_unknown_location'); die(); }
	$args = array();
	if ($act === 'remove') $args = array('remove', $id);
	elseif ($act === 'period') {
		// Remove by period (US-13): every location, all species or one
		$from = str_replace('T', ' ', (string)($p['from'] ?? '')); $to = str_replace('T', ' ', (string)($p['to'] ?? ''));
		$sci = (string)($p['sci'] ?? '');
		if (!ld_period_ok($from, $to) || !preg_match('/^[A-Za-z .()\'-]{0,80}$/', $sci)) { echo t('str_choose_a_valid_period'); die(); }
		$args = array('remove-period', $from, $to);
		if ($sci !== '') $args[] = $sci;
	}
	elseif ($act === 'reassign') {
		$to = intval($p['to'] ?? 0);
		if (!location_by_id($to) || $to === $id) { echo t('str_choose_another_location'); die(); }
		$args = array('reassign', $id, $to);
	} elseif ($act === 'backup' || $act === 'station') {
		$d = ld_folder($p['folder'] ?? '');
		if ($d === false) { echo t('str_choose_a_folder'); die(); }
		exec(station_prefix() . 'test -w ' . escapeshellarg($d), $o, $rc);
		if ($rc !== 0) { echo t('str_the_station_cannot_write_in_help'); die(); }
		$args = array($act === 'station' ? 'station' : 'backup', $id, $d);
		// incremental (owner 2026-10-29): only what no earlier backup of this station and location in the folder holds
		if ($act === 'backup' && !empty($p['incremental'])) $args[] = '--incremental';
		// Export / Backup (owner 2026-10-24): always by the scientific name (BirdSuite), never a folder of mixed formats
	} elseif ($act === 'legacy') {
		// Export to BirdNET (old): common names in the language chosen (owner 2026-10-24); 0 = every location
		$d = ld_folder($p['folder'] ?? '');
		if ($d === false) { echo t('str_choose_a_folder'); die(); }
		exec(station_prefix() . 'test -w ' . escapeshellarg($d), $o, $rc);
		if ($rc !== 0) { echo t('str_the_station_cannot_write_in_help'); die(); }
		$lang = (string)($p['lang'] ?? '');
		if (!isset(common_name_languages()[$lang]) || !is_file(BS_MODEL . "/l18n/labels_$lang.json")) { echo t('str_choose_a_language'); die(); }
		$args = array('legacy', $id, $lang, $d);
	} elseif ($act === 'restore') {
		$d = ld_folder($p['folder'] ?? '');
		if ($d === false || !(station_user_is_file("$d/location.json") || station_user_is_file("$d/birds.db") || station_user_is_file("$d/scripts/birds.db") || array_filter(glob("$d/*/scripts/birds.db") ?: array(), 'station_user_is_file'))) {  // an installation folder of the BirdNET-Pi kind
			echo t('str_not_a_backup_no_location_json_help'); die();
		}
		$args = array('restore', $d);
		if (($p['to'] ?? '') === 'new') $args[] = '--new';
		elseif (intval($p['to'] ?? 0) > 0) { $args[] = '--to'; $args[] = intval($p['to']); }
	} else { echo 'Error'; die(); }
	// the job takes its lock and goes to the background itself (2026-10-20): OK, or why it cannot start (another job holds
	// the lock: two quick clicks both passed the check above); Move to another station = backup + remove in that one job
	$cmd = station_prefix() . 'python3 ' . escapeshellarg(BS_SCRIPTS . '/location_data.py') . ' start';
	foreach ($args as $a) $cmd .= ' ' . escapeshellarg((string)$a);
	$out = trim((string)shell_exec($cmd . ' 2>&1 | tail -1'));
	echo $out === '' ? t('str_the_job_did_not_start') : $out;
	die();
}
