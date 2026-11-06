<?php
/* Backup/Restore, formerly Location Data (owner 2026-10-20; the whole station joined it 2026-10-21): the records of one location as a whole — Remove all (to the Removed folder,
 * restorable), Backup (a copy in a folder, e.g. the NAS), Move to another location of this station, Move to another
 * station (backup to a folder both reach, removed here, restored there) and Restore a backup. The work runs in the
 * background as the station user (scripts/location_data.py), one job at a time. Opened as views.php?view=Data;
 * the folder browser is the one of External Files (scripts/external_files.php?ls). */
require_once __DIR__ . '/common.php';
// two menu items, one page (owner 2026-10-24): Tools › Data (Locations Data, danger zone) and Tools › Backup/Import
// (Export / Backup, Import, Settings); views.php sets $ld_mode
$ld_mode = (isset($ld_mode) && $ld_mode === 'backup') ? 'backup' : 'data';

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
?>
<style>
.ld { max-width: 1000px; margin: 0; text-align: left; padding: 0 12px; }
.ld .box { background: var(--panel, #f4f6f8); border-radius: 10px; padding: 10px 14px; margin: 10px 0; }
.ld table.stdtable td.num { text-align: right; }
.ld .ldbtn { width: auto; padding: 3px 10px; margin: 2px; border-radius: 12px; border: 1px solid var(--accent,#2b5e22); background: #fff; color: var(--accent,#2b5e22); font-weight: 600; cursor: pointer; font-size: .85em; }
.ld a.ldbtn { display: inline-block; text-decoration: none; line-height: 1.3; }
.ld .ldbtn:hover { background: var(--accent,#2b5e22); color: #fff; }
.ld .ldbtn.del { border-color: #c62828; color: #c62828; }
.ld .ldbtn.del:hover { background: #c62828; color: #fff; }
.ld .ldbtn:disabled { opacity: .4; cursor: default; background: #fff; color: var(--accent,#2b5e22); }
.ld .ldbtn.del:disabled { color: #c62828; }
.ld .ldbtn.main { background: var(--accent,#2b5e22); color: #fff; font-size: 1em; padding: 5px 14px; }
.ld .curbadge { display: inline-block; padding: 1px 8px; border-radius: 10px; background: var(--accent,#2b5e22); color: #fff; font-size: .75em; }
.ld .crumbs { font-family: monospace; font-size: 13px; margin: 6px 0; word-break: break-all; }
.ld .crumbs a { cursor: pointer; color: var(--accent, #1f4e79); }
.ld .dirs { display: flex; flex-wrap: wrap; gap: 6px; max-height: 200px; overflow: auto; }
.ld .dirs button { width: auto; padding: 4px 10px; border-radius: 8px; border: 1px solid #ccc; background: #fff; cursor: pointer; font-size: 13px; }
.ld .bar { height: 10px; background: #ddd; border-radius: 5px; overflow: hidden; margin: 6px 0; }
.ld .bar i { display: block; height: 100%; background: var(--accent,#2b5e22); width: 0; }
.ld .small { font-size: 12px; opacity: .8; }
.ld .row { display: flex; flex-wrap: wrap; gap: 10px; align-items: center; margin: 6px 0; }
/* a phone: a table too wide scrolls inside its box, not the page (2026-10-23 test run) */
/* the actions of a location wrap two or three to a line (each label on one line: the global button rule of style.css; owner 2026-11-03: on a phone the
   narrow cell broke every label word by word and stretched the row to a whole screen) */
.ld td.ldacts { min-width: 19em; }
@media (max-width: 600px) { .ld table.stdtable { display: block; overflow-x: auto; max-width: 100%; } }
/* the life cycle settings on a phone (owner 2026-10-25: three narrow columns): one block per setting, its title and
   note across the width, the value and the variable on one line under them */
@media (max-width: 700px) {
  .ld table#disktab, .ld #disktab tbody, .ld #disktab tr { display: block; width: 100%; overflow: visible; }
  .ld #disktab tr:first-child { display: none; }
  .ld #disktab tr { padding: 8px 0; border-bottom: 1px solid rgba(0,0,0,.12); }
  .ld #disktab td { display: block; border: 0; padding: 2px 0; background: none !important; color: inherit !important; }
  .ld #disktab td:nth-child(2), .ld #disktab td:nth-child(3) { display: inline-block; vertical-align: middle; margin-right: 10px; }
}
/* the folder trees are drawn after the page (folder-picker.js): their place is kept from the start, so the sections
   below never move when they appear (release run 5, PERF-56: 0.34 layout shift) */
.ld #bxdirs, .ld #rsdirs { min-height: 440px; }
@media (max-width: 600px) { .ld #bxdirs, .ld #rsdirs { min-height: 490px; } }
</style>
<div class="ld">
  <div class="box" id="ldjob" style="display:none"><div id="ldjobtxt"></div><div class="bar"><i id="ldbar"></i></div>
    <div class="row" id="ldctl" style="display:none"><button type="button" class="ldbtn" id="ldpause" onclick="ldCtl('pause')"><?php echo t('str_pause'); ?></button>
      <button type="button" class="ldbtn" id="ldcont" onclick="ldCtl('continue')" style="display:none"><?php echo t('str_continue'); ?></button>
      <button type="button" class="ldbtn" id="ldcancel" onclick="bsAsk(t('str_cancel_what_this_job_did_so_far_help'), true).then(function (ok) { if (ok) ldCtl('cancel'); })" style="border-color:#c62828;color:#c62828"><?php echo t('str_cancel'); ?></button>
      <span class="small"><?php echo t('str_pause_holds_the_job_and_frees_help'); ?></span></div></div>

  <?php if ($ld_mode === 'data') { ?>
  <?php // 1. the locations: their records, the format of their clips, Move (owner 2026-10-24: Backup and Delete all in the sections below) ?>
  <?php // every section a panel with its title inside, as Settings › Model (owner 2026-10-24: one look in the whole app) ?>
  <table class="settingstable"><tr><td>
  <h2 id="ldformats"><?php echo t('str_locations_data'); ?></h2>
  <table class="stdtable">
    <tr><th><?php echo t('str_location'); ?></th><th><?php echo t('str_detections'); ?></th><th title="<?php echo $h(t('str_removed_detections_out_of_the_help')); ?>"><?php echo t('str_deleted'); ?></th><th title="<?php echo $h(t('str_answered_human_noise_or_ignore_help')); ?>"><?php echo t('str_ignored_col'); ?></th><th><?php echo t('str_first'); ?></th><th><?php echo t('str_last'); ?></th><th><?php echo t('str_format'); ?></th><th></th></tr>
    <?php foreach ($rows as $l) {
      $is_cur = $cur && intval($cur['id']) === intval($l['id']);
      echo '<tr data-id="' . intval($l['id']) . '" data-name="' . $h($l['name']) . '" data-n="' . $l['n'] . '"><td><b>' . $h($l['name']) . '</b> ' . ($is_cur ? '<span class="curbadge">' . t('str_current') . '</span>' : '') . '</td>'
        . '<td class="num">' . fmt_num($l['n']) . '</td><td class="num">' . fmt_num($l['removed']) . '</td><td class="num">' . fmt_num($l['ignored']) . '</td><td>' . $h($l['first']) . '</td><td>' . $h($l['last']) . '</td>'
        . '<td>' . $h($fmt_text($fmts[(string)$l['id']] ?? null, $l['n'])) . '</td><td class="ldacts">'
        // Move to location / to station only with several of them (simple mode, US-125)
        . ($l['n'] ? (multi_location() ? '<button type="button" class="ldbtn" onclick="ldPick(this,\'reassign\')">' . t('str_move_to_location') . '</button>' : '')
          . (multi_station() ? '<button type="button" class="ldbtn" onclick="ldPick(this,\'station\')">' . t('str_move_to_station') . '</button>' : '')
          // every detection of the location to the Removed folder (owner 2026-10-28: lost in the 2026-10-24 reorganisation)
          . '<button type="button" class="ldbtn del" onclick="ldRemove(this)">' . t('str_delete_all') . '</button>' : '')
        // the removed detections back (US-13): all of them or one species, listed under the line
        . '<button type="button" class="ldbtn" onclick="ldRestoreFold(this, \'rs' . intval($l['id']) . '\')"' . ($l['removed'] ? '' : ' disabled title="' . $h(t('str_nothing_to_restore')) . '"') . '>' . t('str_restore') . ' &#9662;</button>'
        // the deleted detections of the location for good (owner 2026-10-24: in place of the section Purge Removed Sounds)
        . '<button type="button" class="ldbtn del" onclick="wipeDeleted(\'\', this, ' . $h(json_encode((string)intval($l['id']))) . ')"' . ($l['removed'] ? '' : ' disabled title="' . $h(t('str_nothing_to_purge')) . '"') . '>' . t('str_purge_removed') . '</button>'
        . '<button type="button" class="ldbtn del" onclick="purgeAudio(' . $h(json_encode((string)intval($l['id']))) . ', this)"' . ($l['ignored'] ? '' : ' disabled title="' . $h(t('str_nothing_to_purge')) . '"') . '>' . t('str_purge_ignored') . '</button>'
        . '</td></tr>';
      if ($l['removed']) {
        $loc = $h(json_encode((string)intval($l['id'])));
        echo '<tr class="rs' . intval($l['id']) . '" style="display:none"><td style="padding-left:22px"><i>' . t('str_all_species') . '</i></td><td></td><td class="num">' . fmt_num($l['removed']) . '</td><td colspan="4"></td>'
          . '<td><button type="button" class="ldbtn" onclick="ldRestore(\'\', this, ' . $loc . ', ' . intval($l['removed']) . ')">' . t('str_restore_all') . '</button></td></tr>';
        foreach ($removed_sp[intval($l['id'])] as $r)
          echo '<tr class="rs' . intval($l['id']) . '" style="display:none"><td style="padding-left:22px">' . $h($r['com']) . ' <small><i>' . $h($r['Sci_Name']) . '</i></small></td><td></td><td class="num">' . fmt_num($r['n']) . '</td><td colspan="4"></td>'
            . '<td><button type="button" class="ldbtn" onclick="ldRestore(' . $h(json_encode($r['Sci_Name'])) . ', this, ' . $loc . ', ' . intval($r['n']) . ')">' . t('str_restore') . '</button></td></tr>';
      }
    }
    // outside every location (run 5 RV2): only Restore and Purge Removed, the species folded under it as for a location
    if ($orphans['n']) {
      $loc = $h(json_encode('none'));
      sort($orphans['ids']);
      echo '<tr><td><b>' . t('str_outside_every_location') . '</b> <small>('
        . $h(implode(', ', array_map(function ($i) { return $i ? '#' . $i : '-'; }, $orphans['ids']))) . ')</small></td>'
        . '<td class="num">' . fmt_num(0) . '</td><td class="num">' . fmt_num($orphans['n']) . '</td><td class="num">' . fmt_num(0) . '</td><td></td><td></td><td></td><td class="ldacts">'
        . '<button type="button" class="ldbtn" onclick="ldRestoreFold(this, \'rsnone\')">' . t('str_restore') . ' &#9662;</button>'
        . '<button type="button" class="ldbtn del" onclick="wipeDeleted(\'\', this, ' . $loc . ')">' . t('str_purge_removed') . '</button></td></tr>';
      echo '<tr class="rsnone" style="display:none"><td style="padding-left:22px"><i>' . t('str_all_species') . '</i></td><td></td><td class="num">' . fmt_num($orphans['n']) . '</td><td colspan="4"></td>'
        . '<td><button type="button" class="ldbtn" onclick="ldRestore(\'\', this, ' . $loc . ', ' . intval($orphans['n']) . ')">' . t('str_restore_all') . '</button></td></tr>';
      foreach ($orphans['sp'] as $r)
        echo '<tr class="rsnone" style="display:none"><td style="padding-left:22px">' . $h($r['com']) . ' <small><i>' . $h($r['Sci_Name']) . '</i></small></td><td></td><td class="num">' . fmt_num($r['n']) . '</td><td colspan="4"></td>'
          . '<td><button type="button" class="ldbtn" onclick="ldRestore(' . $h(json_encode($r['Sci_Name'])) . ', this, ' . $loc . ', ' . intval($r['n']) . ')">' . t('str_restore') . '</button></td></tr>';
    } ?>
  </table>
  <p class="small" style="margin:6px 0 0"><?php echo t('str_deleted_the_detections_you_help'); ?><br>
    <?php echo t('str_ignored_answered_human_noise_or_help'); ?><br>
    <?php echo t('str_delete_all_every_detection_of_help'); ?><br>
    <?php echo t('str_restore_puts_a_detection_back_help'); ?><br>
    <?php if ($orphans['n']) { ?><?php echo t('str_outside_every_location_the_help'); ?><br><?php } ?>
    <?php if (multi_location()) { ?><?php echo t('str_move_to_location_the_detections_help'); ?><br><?php } ?>
    <?php if (multi_station()) { ?><?php echo t('str_move_to_station_a_backup_then_help'); ?><?php } ?></p>
  <p class="small" style="margin:6px 0 0"><b><?php echo t('str_format_colon'); ?></b><br>
    <?php echo t('str_birdsuite_the_database_uses_the_help'); ?><br>
    <?php echo t('str_birdnet_the_database_uses_the_help'); ?></p>
  <div class="box" id="ldpanel" style="display:none">
    <div class="row"><b id="ldtitle"></b></div>
    <div id="ldto" class="row" style="display:none"><?php echo t('str_move_to_location_colon'); ?> <select id="ldtosel"><?php foreach ($rows as $l) echo '<option value="' . intval($l['id']) . '">' . $h($l['name']) . '</option>'; ?></select></div>
    <div id="ldst" class="row" style="display:none"><?php echo t('str_station'); ?> <select id="ldstsel"><?php foreach ($stations as $s) {
      $a = $s['addresses'][0] ?? '';
      if ($a !== '' && strcasecmp($s['name'], get_sitename()) !== 0) echo '<option value="' . $h($a) . '">' . $h($s['name'] . ' (' . $a . ')') . '</option>';
    } ?></select> <span class="small"><?php echo t('str_the_folder_below_must_be_help'); ?></span></div>
    <div id="ldfold" style="display:none"><div class="small"><?php echo t('str_folder'); ?></div><div class="crumbs" id="ldcrumbs"></div><div class="dirs" id="lddirs"></div></div>
    <div class="row"><button type="button" class="ldbtn main" id="ldgo" onclick="ldGo()"><?php echo t('str_start'); ?></button> <button type="button" class="ldbtn" onclick="document.getElementById('ldpanel').style.display='none'"><?php echo t('str_cancel'); ?></button> <b id="ldmsg"></b></div>
  </div>
  </td></tr></table><br>

  <?php // Remove by period (US-13): from date + time to date + time, every species or one; a preview with the counts, then
  // the confirmation; the detections go to the Removed folder (restorable above until Purge Removed) ?>
  <table class="settingstable"><tr><td>
  <h2><?php echo t('str_remove_by_period'); ?></h2>
  <div class="row"><label><?php echo t('str_period_from'); ?> <input type="datetime-local" id="rpfrom" step="60"></label>
    <label><?php echo t('str_period_to'); ?> <input type="datetime-local" id="rpto" step="60"></label>
    <label><?php echo t('str_species'); ?> <select id="rpsci"><option value=""><?php echo t('str_all_species'); ?></option><?php
      foreach ($period_sp as $r) echo '<option value="' . $h($r['Sci_Name']) . '">' . $h($r['com'] . ' (' . $r['Sci_Name'] . ')') . '</option>'; ?></select></label>
    <button type="button" class="ldbtn main" onclick="rpPreview()"><?php echo t('str_preview'); ?></button> <b id="rpmsg"></b></div>
  <div id="rpres"></div>
  <p class="small" style="margin:6px 0 0"><?php echo t('str_remove_by_period_moves_the_help'); ?><br><?php echo t('str_lists_tiers_and_confirmed_help'); ?></p>
  </td></tr></table><br>

  <?php // the life cycle of the sounds (owner 2026-10-24, text B), with this station's own numbers
  $cf = get_config();
  $lc = array('day' => intval($cf['MAX_FILES_SPECIES_DAY'] ?? 50), 'recent' => intval($cf['PURGE_RECENT_DAYS'] ?? 3),
    'total' => intval($cf['MAX_FILES_SPECIES'] ?? 100), 'disk' => intval($cf['PURGE_THRESHOLD'] ?? 70),
    'ignored' => intval($cf['IGNORED_PURGE_DAYS'] ?? 5), 'top' => intval($cf['PURGE_PROTECT_TOP_N'] ?? 50)); ?>
  <table class="settingstable"><tr><td>
  <h2><?php echo t('str_life_cycle_of_the_sounds'); ?></h2>
  <div style="font-weight:700;margin-top:10px"><?php echo t('str_detection_dot'); ?></div>
  <p style="margin-top:2px"><?php echo t('str_each_detection_records_a_sound_help'); ?><br>
    <?php echo t('str_the_record_goes_to_the_database_help'); ?></p>
  <div style="font-weight:700;margin-top:10px"><?php echo t('str_automatic_limits_every_night'); ?></div>
  <p style="margin-top:2px"><?php // 0 = no limit (owner 2026-10-24): the sentence says so
    echo $lc['day'] ? t('str_in_each_day_a_species_keeps_help', array('n' => $lc['day'] . ' (MAX_FILES_SPECIES_DAY)')) : t('str_no_daily_limit_a_species_keeps_help'); ?><br>
    <?php echo ($lc['total'] && $lc['recent']) ? t('str_outside_the_last_days_each_help', array('days' => $lc['recent'] . ' (PURGE_RECENT_DAYS)', 'n' => $lc['total'] . ' (MAX_FILES_SPECIES)'))
      : t('str_no_limit_per_species_every_help', array('var' => $lc['total'] ? 'PURGE_RECENT_DAYS' : 'MAX_FILES_SPECIES')); ?><br>
    <?php echo $lc['disk'] ? t('str_if_the_disk_goes_over_the_help', array('pct' => $lc['disk'] . '% (PURGE_THRESHOLD)')) : t('str_no_disk_limit_nothing_is_help'); ?><br>
    <?php echo t('str_these_limits_delete_only_the_help'); ?></p>
  <div style="font-weight:700;margin-top:10px"><?php echo t('str_ignored_dot'); ?></div>
  <p style="margin-top:2px"><?php echo t('str_the_answers_human_noise_or_help'); ?><br>
    <?php echo $lc['ignored'] ? t('str_the_sound_is_deleted_help', array('days' => $lc['ignored'] . ' (IGNORED_PURGE_DAYS)')) : t('str_the_sound_stays_there_help'); ?></p>
  <div style="font-weight:700;margin-top:10px"><?php echo t('str_removed'); ?></div>
  <p style="margin-top:2px"><?php echo t('str_removing_a_detection_takes_it_help'); ?><br>
    <?php echo t('str_until_purge_removed_it_can_be_help'); ?></p>
  <div style="font-weight:700;margin-top:10px"><?php echo t('str_protected'); ?></div>
  <p style="margin-top:2px"><?php echo $lc['top'] ? t('str_sounds_with_the_lock_confirmed_help', array('n' => $lc['top'] . ' (PURGE_PROTECT_TOP_N)')) : t('str_every_sound_is_protected_no_help'); ?></p>
  <?php // the settings behind the numbers, edited here (owner 2026-10-24: one place; their defaults in Settings › Defaults) ?>
  <table class="stdtable" data-nosort id="disktab" style="margin-top:14px">
    <tr><th><?php echo t('str_setting'); ?></th><th><?php echo t('str_value'); ?></th><th><?php echo t('str_variable'); ?></th></tr>
    <?php // the owner's order (2026-10-24); 0 keeps everything in every one of them
    foreach (array(
      array(t('str_maximum_clips_per_species_per_day'), t('str_in_each_day_a_species_keeps_its_help'), 'MAX_FILES_SPECIES_DAY', $lc['day'], 100000),
      array(t('str_number_of_files_to_keep_for_help'), t('str_outside_the_recent_days_the_n_help'), 'MAX_FILES_SPECIES', $lc['total'], 100000),
      array(t('str_protect_the_clips_of_the_last_help'), t('str_the_limit_per_species_leaves_help'), 'PURGE_RECENT_DAYS', $lc['recent'], 365),
      array(t('str_purge_the_audio_of_ignored_help'), t('str_after_this_the_sound_of_an_help'), 'IGNORED_PURGE_DAYS', $lc['ignored'], 3650),
      array(t('str_always_protect_the_best_help'), t('str_besides_the_locks_yes_and_help'), 'PURGE_PROTECT_TOP_N', $lc['top'], 100)) as $row)
      echo '<tr><td><b>' . $h(rtrim($row[0], ': ')) . '</b><br><small>' . $h($row[1]) . ' ' . $h(t('str_0_keep_everything')) . '</small></td><td><input type="number" name="' . $row[2] . '" value="' . intval($row[3])
        . '" min="0" max="' . $row[4] . '" step="1" style="width:6.5em"></td><td><code>' . $row[2] . '</code></td></tr>'; ?>
    <?php $fdm = in_array($cf['FULL_DISK'] ?? '', array('keep', 'external'), true) ? $cf['FULL_DISK'] : 'purge'; ?>
    <tr><td><b><?php echo t('str_when_the_disk_is_full'); ?></b><br><small><?php echo t('str_purge_deletes_the_oldest_sounds_help'); ?><br><?php echo t('str_external_backup_moves_the_old_help'); ?></small></td>
      <td style="white-space:nowrap"><label><input type="radio" name="FULL_DISK" value="purge" onchange="diskExt()"<?php echo $fdm === 'purge' ? ' checked' : ''; ?>> <?php echo t('str_purge'); ?></label>
        <label><input type="radio" name="FULL_DISK" value="keep" onchange="diskExt()"<?php echo $fdm === 'keep' ? ' checked' : ''; ?>> <?php echo t('str_keep'); ?></label>
        <label><input type="radio" name="FULL_DISK" value="external" onchange="diskExt()"<?php echo $fdm === 'external' ? ' checked' : ''; ?>> <?php echo t('str_external_backup'); ?></label></td><td><code>FULL_DISK</code></td></tr>
    <?php // US-128 (owner 2026-10-29): the destination folder and what happens when it cannot be used, shown with External backup ?>
    <tr class="diskext"<?php echo $fdm === 'external' ? '' : ' style="display:none"'; ?>><td colspan="2"><b><?php echo t('str_destination_folder'); ?></b><br><small><?php echo t('str_the_old_sounds_are_moved_here_help'); ?></small>
      <div id="diskextdirs" data-path="<?php echo $h($cf['FULL_DISK_PATH'] ?? ''); ?>"></div></td><td><code>FULL_DISK_PATH</code></td></tr>
    <tr class="diskext"<?php echo $fdm === 'external' ? '' : ' style="display:none"'; ?>><td><b><?php echo t('str_if_the_backup_is_not_possible'); ?></b><br><small><?php echo t('str_folder_not_mounted_not_writable_help'); ?></small></td>
      <td style="white-space:nowrap"><label><input type="radio" name="FULL_DISK_FALLBACK" value="keep"<?php echo ($cf['FULL_DISK_FALLBACK'] ?? 'keep') !== 'purge' ? ' checked' : ''; ?>> <?php echo t('str_stop_the_recording'); ?></label>
        <label><input type="radio" name="FULL_DISK_FALLBACK" value="purge"<?php echo ($cf['FULL_DISK_FALLBACK'] ?? '') === 'purge' ? ' checked' : ''; ?>> <?php echo t('str_delete_the_oldest'); ?></label></td><td><code>FULL_DISK_FALLBACK</code></td></tr>
    <tr><td><b><?php echo t('str_purge_threshold_disk_used_pct'); ?></b><br><small><?php echo $h(t('str_how_full_the_disk_may_get_help') . ' ' . t('str_0_keep_everything')); ?></small></td>
      <td><input type="number" name="PURGE_THRESHOLD" value="<?php echo intval($lc['disk']); ?>" min="0" max="99" step="1" style="width:6.5em"></td><td><code>PURGE_THRESHOLD</code></td></tr>
  </table>
  <div class="row"><button type="button" class="ldbtn main" onclick="diskSave(this)"><?php echo t('str_save'); ?></button> <b id="diskmsg"></b>
    <button type="button" class="ldbtn" onclick="diskCount(this)"><?php echo t('str_disk_usage_summary'); ?></button></div>
  <pre id="diskcount" style="display:none;max-height:40vh;overflow:auto;background:#111;color:#ddd;border-radius:8px;padding:8px;font-size:12px;white-space:pre-wrap"></pre>
  <script>
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
  </script>
  </td></tr></table><br>
  <?php } ?>
  <?php if ($ld_mode === 'backup') { ?>
  <?php // 2. Export / Backup (owner 2026-10-24): the location and the format ?>
  <table class="settingstable"><tr><td>
  <h2><?php echo t('str_export_backup'); ?></h2>
    <div class="row"><?php echo t('str_location'); ?> <select id="bxloc" aria-label="<?php echo htmlspecialchars(t('str_location'), ENT_QUOTES); ?>"><option value="0" data-n="<?php echo array_sum(array_column($rows, 'n')); ?>"><?php echo t('str_all_locations'); ?></option><?php
      foreach ($rows as $l) if ($l['n']) echo '<option value="' . intval($l['id']) . '" data-n="' . $l['n'] . '">' . $h($l['name']) . '</option>'; ?></select>
      <?php echo t('str_format'); ?> <select id="bxfmt" aria-label="<?php echo htmlspecialchars(t('str_format'), ENT_QUOTES); ?>" onchange="document.getElementById('bxlangrow').style.display = this.value === 'birdnet' ? '' : 'none'; document.getElementById('bxincrow').style.display = this.value === 'birdnet' ? 'none' : ''">
        <option value="birdsuite"><?php echo t('str_birdsuite_scientific_default'); ?></option>
        <option value="birdnet"><?php echo t('str_birdnet_common_names'); ?></option></select>
      <span id="bxlangrow" style="display:none"><?php echo t('str_common_names_in'); ?> <select id="bxlang"><?php
        $db_lang = get_config()['DATABASE_LANG'] ?? 'en';
        foreach (common_name_languages() as $code => $name) {
          if (is_file(BS_MODEL . "/l18n/labels_$code.json")) echo '<option value="' . $h($code) . '"' . ($code === $db_lang ? ' selected' : '') . '>' . $h(t($name)) . '</option>';
        } ?></select></span></div>
    <div class="row small"><?php echo t('str_choose_the_destination_folder'); ?></div>
    <?php // incremental backup (owner 2026-10-29), BirdSuite format only ?>
    <div class="row" id="bxincrow"><label><input type="checkbox" id="bxinc"> <?php echo t('str_incremental_only_what_is_new_help'); ?></label></div>
    <div class="crumbs" id="bxcrumbs"></div><div class="dirs" id="bxdirs"></div>
    <div class="row"><button type="button" class="ldbtn main" onclick="bxGo()"><?php echo t('str_start'); ?></button> <b id="bxmsg"></b></div>
  </td></tr></table><br>

  <?php // 3. Import (formerly Restore a backup, owner 2026-10-24): BirdSuite or BirdNET, brought into the BirdSuite format ?>
  <table class="settingstable"><tr><td>
  <h2><?php echo t('str_import'); ?></h2>
    <div class="row small"><?php echo t('str_select_the_folder_that_holds_help'); ?><br>
      <?php echo t('str_a_backup'); ?></div>
    <div class="crumbs" id="rscrumbs"></div><div class="dirs" id="rsdirs"></div>
    <div class="row" id="rsinfo"></div>
    <div class="row"><label for="rsto" style="font-weight:normal"><?php echo t('str_into'); ?></label> <select id="rsto"><?php
      // Test / Playback, or new locations (owner 2026-10-24): checked there first, then filed under the real one in Move Data
      foreach ($rows as $l) if (($l['builtin'] ?? '') === 'test') echo '<option value="' . intval($l['id']) . '" selected>' . $h($l['name']) . '</option>'; ?>
      <option value="new"><?php echo t('str_new_location_one_per_location_help'); ?></option>
      <?php // an existing location too, below New location (owner 2026-10-24)
      foreach ($rows as $l) if (($l['builtin'] ?? '') !== 'test') echo '<option value="' . intval($l['id']) . '">' . $h($l['name']) . '</option>'; ?></select>
      <button type="button" class="ldbtn main" id="rsgo" onclick="rsGo()" disabled><?php echo t('str_import'); ?></button> <b id="rsmsg"></b></div>
    <div class="row small"><?php echo t('str_best_into_test_playback_or_a_help'); ?><br>
      <?php echo t('str_a_backup_of_several_locations_help'); ?></div>
    <?php // recordings made elsewhere (not a backup): analysed with the station's model, optionally imported (US-15) ?>
    <div class="row small"><?php echo t('str_recordings_made_elsewhere_help'); ?> <a class="ldbtn" href="views.php?view=External%20files"><?php echo t('str_external_files'); ?></a></div>
  </td></tr></table><br>
  <?php } ?>
  <?php // 4. the settings (Backup/Import) and 5. the danger zone (Data) ?>
  <?php include __DIR__ . '/station_backup.php'; ?>
</div>
<script src="static/species-modal.js?v=<?php echo @filemtime(__DIR__ . "/../homepage/static/species-modal.js"); ?>"></script>
<script src="static/wipe-deleted.js?v=<?php echo @filemtime(__DIR__ . "/../homepage/static/wipe-deleted.js"); ?>"></script>
<script src="static/folder-picker.js?v=<?php echo @filemtime(__DIR__ . "/../homepage/static/folder-picker.js"); ?>"></script>
<script>
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
if (document.getElementById('bxcrumbs')) ldBrowse(<?php echo json_encode(BS_RECS); ?>, 'bxcrumbs', 'bxdirs', function (p) { bxFolder = p; });
if (document.getElementById('rscrumbs')) ldBrowse(<?php echo json_encode(get_home()); ?>, 'rscrumbs', 'rsdirs', function (p) {
  rsFolder = p;
  document.getElementById('rsgo').disabled = true;
  document.getElementById('rsinfo').textContent = '';
  if (!p) return;
  ldGetJ('scripts/location_data.php?peek=' + encodeURIComponent(p), function (m) {
    if (!m || m.error) return;
    var fmt = m.birdnet === 0 ? t('str_scientific_birdsuite') : t('str_birdnet', {lang: esc(m.lang_name || t('str_unknown_language'))});
    if (m.birdnet > 0 && m.birdsuite > 0) fmt = t('str_scientific_birdsuite_plus_birdnet', {n: window.fmtNum(m.birdnet), lang: esc(m.lang_name || t('str_unknown_language'))});
    document.getElementById('rsinfo').innerHTML = (m.kind === 'birdnet'
      ? t('str_birdnet_folder_detections', {name: '<b>' + esc(m.station) + '</b>', count: window.fmtNum(m.detections || 0)})
      : t('str_backup_of_from_station_detections', {name: esc(m.location), station: esc(m.station), date: esc(m.exported), count: window.fmtNum((m.detections || 0))}))
      + ' — ' + t('str_format') + ': <b>' + fmt + '</b>' + (m.birdnet > 0 ? ' <span class="small">(' + t('str_converted_to_scientific_help') + ')</span>' : '');
    document.getElementById('rsgo').disabled = false;
  });
});
ldPoll();
</script>
