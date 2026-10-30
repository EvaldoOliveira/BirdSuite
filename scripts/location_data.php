<?php
/* Backup/Restore, formerly Location Data (owner 2026-10-20; the whole station joined it 2026-10-21): the records of one location as a whole — Remove all (to the Removed folder,
 * restorable), Backup (a copy in a folder, e.g. the NAS), Move to another location of this station, Move to another
 * station (backup to a folder both reach, removed here, restored there) and Restore a backup. The work runs in the
 * background as the station user (scripts/location_data.py), one job at a time. Opened as views.php?view=LocationData;
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
    $j['error'] = t('interrupted (the station restarted or the job was stopped); what it had done so far was kept');
    $j['finished'] = $j['finished'] ?? '';
  }
  return $j;
}

if (isset($_GET['job'])) { header('Content-Type: application/json'); echo json_encode(ld_job(), JSON_UNESCAPED_UNICODE); die(); }
if (isset($_GET['peek'])) { // a folder to import: what it holds, BirdSuite or BirdNET, and the language (location_data.py peek)
  header('Content-Type: application/json');
  $d = ld_folder($_GET['peek']);
  $m = $d ? json_decode((string)shell_exec(station_prefix() . 'timeout 60 python3 ' . escapeshellarg($home . '/BirdNET-Pi/scripts/location_data.py')
    . ' peek ' . escapeshellarg($d) . ' 2>/dev/null'), true) : null;
  if (is_array($m) && ($m['lang'] ?? '') !== '') $m['lang_name'] = t(common_name_languages()[$m['lang']] ?? $m['lang']);
  echo json_encode($m ?: array('error' => t('Not a backup: no location.json (BirdSuite) or birds.db (BirdNET)')), JSON_UNESCAPED_UNICODE);
  die();
}
// the disk management settings, edited under the life cycle of the sounds (owner 2026-10-24, formerly Settings ›
// Advanced › Disk Management): POST ?disk=1 with {KEY: value}; each key checked against its range
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_GET['disk'])) {
  ensure_authenticated(t('You must be authenticated to change the settings.'));
  $p = json_decode((string)file_get_contents('php://input'), true) ?: array();
  // 0 keeps everything in each of them (owner 2026-10-24); a threshold under 20% other than 0 is raised to 20
  $ranges = array('PURGE_THRESHOLD' => array(0, 99), 'PURGE_PROTECT_TOP_N' => array(0, 100), 'MAX_FILES_SPECIES_DAY' => array(0, 100000),
    'PURGE_RECENT_DAYS' => array(0, 365), 'MAX_FILES_SPECIES' => array(0, 100000), 'IGNORED_PURGE_DAYS' => array(0, 3650));
  $c = file_get_contents(BS_CONF);
  $set = function ($k, $v) use (&$c) {
    $c = preg_match("/^$k=/m", $c) ? preg_replace("/^$k=.*/m", "$k=$v", $c) : $c . "\n$k=$v\n";
  };
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
    if ($d === false || preg_match('/["`$\\\n]/', (string)$d)) { echo t('Folder not allowed'); die(); }
    $set('FULL_DISK_PATH', '"' . $d . '"');
  }
  if (isset($p['FULL_DISK_FALLBACK']) && in_array($p['FULL_DISK_FALLBACK'], array('keep', 'purge'), true)) $set('FULL_DISK_FALLBACK', $p['FULL_DISK_FALLBACK']);
  file_put_contents(BS_CONF, $c);
  if (($p['FULL_DISK'] ?? '') === 'external') {
    // said at once when the folder cannot be used (not writable, full, on the station's own disk)
    $out = trim((string)shell_exec(station_prefix() . escapeshellarg($home . '/BirdNET-Pi/birdnet/bin/python3') . ' ' . escapeshellarg($home . '/BirdNET-Pi/scripts/clip_dispose.py') . ' --check 2>&1; echo " rc=$?"'));
    if (!preg_match('/ rc=0$/', $out) || strpos($out, 'fallback purge') !== false) $msg = 'OK ' . preg_replace('/ rc=\d+$/', '', $out);
  }
  echo $msg;
  die();
}
if (isset($_GET['disk_count'])) {
  ensure_authenticated();
  header('Content-Type: text/plain; charset=utf-8');
  echo (string)shell_exec(station_prefix() . escapeshellarg($home . '/BirdNET-Pi/scripts/disk_species_count.sh') . ' 2>&1');
  die();
}
// pause / continue / cancel the running job (owner 2026-10-20) — cancel undoes what it did; signalled as the station user
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_GET['ctl'])) {
  ensure_authenticated(t('You must be authenticated to control the job.'));
  if (!in_array($_GET['ctl'], array('pause', 'continue', 'cancel'), true)) { echo 'Error'; die(); }
  echo trim((string)shell_exec(station_prefix() . 'python3 ' . escapeshellarg($home . '/BirdNET-Pi/scripts/location_data.py')
    . ' ctl ' . escapeshellarg($_GET['ctl']) . ' 2>&1 | tail -1'));
  die();
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_GET['run'])) {
  ensure_authenticated(t('You must be authenticated to change the location data.'));
  $p = json_decode((string)file_get_contents('php://input'), true) ?: array();
  $j = ld_job();
  if (in_array($j['status'] ?? '', array('running', 'paused', 'cancelling'), true)) { echo t('Another job is running or paused: continue or cancel it first'); die(); }
  $act = $p['action'] ?? '';
  $id = intval($p['loc'] ?? 0);
  if ($act !== 'restore' && !(in_array($act, array('legacy', 'backup'), true) && $id === 0) && !location_by_id($id)) { echo t('Unknown location'); die(); }
  $args = array();
  if ($act === 'remove') $args = array('remove', $id);
  elseif ($act === 'reassign') {
    $to = intval($p['to'] ?? 0);
    if (!location_by_id($to) || $to === $id) { echo t('Choose another location'); die(); }
    $args = array('reassign', $id, $to);
  } elseif ($act === 'backup' || $act === 'station') {
    $d = ld_folder($p['folder'] ?? '');
    if ($d === false) { echo t('Choose a folder'); die(); }
    exec(station_prefix() . 'test -w ' . escapeshellarg($d), $o, $rc);
    if ($rc !== 0) { echo t('The station cannot write in this folder'); die(); }
    $args = array($act === 'station' ? 'station' : 'backup', $id, $d);
    // incremental (owner 2026-10-29): only what no earlier backup of this station and location in the folder holds
    if ($act === 'backup' && !empty($p['incremental'])) $args[] = '--incremental';
    // Export / Backup (owner 2026-10-24): always by the scientific name (BirdSuite), never a folder of mixed formats
  } elseif ($act === 'legacy') {
    // Export to BirdNET (old): common names in the language chosen (owner 2026-10-24); 0 = every location
    $d = ld_folder($p['folder'] ?? '');
    if ($d === false) { echo t('Choose a folder'); die(); }
    exec(station_prefix() . 'test -w ' . escapeshellarg($d), $o, $rc);
    if ($rc !== 0) { echo t('The station cannot write in this folder'); die(); }
    $lang = (string)($p['lang'] ?? '');
    if (!isset(common_name_languages()[$lang]) || !is_file("$home/BirdNET-Pi/model/l18n/labels_$lang.json")) { echo t('Choose a language'); die(); }
    $args = array('legacy', $id, $lang, $d);
  } elseif ($act === 'restore') {
    $d = ld_folder($p['folder'] ?? '');
    if ($d === false || !(station_user_is_file("$d/location.json") || station_user_is_file("$d/birds.db") || station_user_is_file("$d/scripts/birds.db") || station_user_is_file("$d/BirdNET-Pi/scripts/birds.db"))) {
      echo t('Not a backup: no location.json (BirdSuite) or birds.db (BirdNET)'); die();
    }
    $args = array('restore', $d);
    if (($p['to'] ?? '') === 'new') $args[] = '--new';
    elseif (intval($p['to'] ?? 0) > 0) { $args[] = '--to'; $args[] = intval($p['to']); }
  } else { echo 'Error'; die(); }
  // the job takes its lock and goes to the background itself (2026-10-20): OK, or why it cannot start (another job holds
  // the lock: two quick clicks both passed the check above); Move to another station = backup + remove in that one job
  $cmd = station_prefix() . 'python3 ' . escapeshellarg($home . '/BirdNET-Pi/scripts/location_data.py') . ' start';
  foreach ($args as $a) $cmd .= ' ' . escapeshellarg((string)$a);
  $out = trim((string)shell_exec($cmd . ' 2>&1 | tail -1'));
  echo $out === '' ? t('The job did not start') : $out;
  die();
}

// per location: detections, removed ones, first and last day (main tables: the header filter does not apply)
$db = new SQLite3(BS_DB, SQLITE3_OPEN_READONLY);
$db->busyTimeout(5000);
$has_deleted = (bool)$db->querySingle("SELECT 1 FROM sqlite_master WHERE type='table' AND name='deleted_detections'");
$rows = array();
foreach (locations_load() as $l) {
  $w = location_sql($l);
  $r = $db->querySingle("SELECT COUNT(*) AS n, MIN(Date) AS first, MAX(Date) AS last FROM main.detections WHERE $w", true) ?: array();
  $l['n'] = intval($r['n'] ?? 0); $l['first'] = $r['first'] ?? ''; $l['last'] = $r['last'] ?? '';
  $l['removed'] = $has_deleted ? intval($db->querySingle("SELECT COUNT(*) FROM deleted_detections WHERE " . location_sql($l, true))) : 0;
  // the Ignored column (owner 2026-10-24): audio to purge of detections that keep counting
  $l['ignored'] = 0;
  if ($ld_mode === 'data') {
    $pa = purge_audio_rows($db, $w);
    $l['ignored'] = array_sum(array_column($pa['causes'], 'n')) + array_sum(array_column($pa['others'], 'n'));
  }
  $rows[] = $l;
}
$db->close();
// the format of each location's clips (owner 2026-10-24): BirdSuite (scientific name) or BirdNET (a common-name language)
$fmts = $ld_mode !== 'data' ? array() : (json_decode((string)shell_exec(station_prefix() . 'timeout 60 python3 ' . escapeshellarg($home . '/BirdNET-Pi/scripts/location_data.py') . ' formats 2>/dev/null'), true) ?: array());
$fmt_text = function ($f) {
  if (!$f || ($f['birdsuite'] + $f['birdnet']) === 0) return '';
  $lang = $f['lang'] !== '' ? t(common_name_languages()[$f['lang']] ?? $f['lang']) : t('unknown language');
  if ($f['birdnet'] === 0) return t('Scientific (BirdSuite)');
  if ($f['birdsuite'] === 0) return t('{lang} (BirdNET)', array('lang' => $lang));
  return t('Scientific (BirdSuite) + {n} {lang} (BirdNET)', array('n' => fmt_num($f['birdnet']), 'lang' => $lang));
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
@media (max-width: 600px) { .ld table.stdtable { display: block; overflow-x: auto; max-width: 100%; } .ld .ldbtn { white-space: normal; } }
/* the life cycle settings on a phone (owner 2026-10-25: three narrow columns): one block per setting, its title and
   note across the width, the value and the variable on one line under them */
@media (max-width: 700px) {
  .ld table#disktab, .ld #disktab tbody, .ld #disktab tr { display: block; width: 100%; overflow: visible; }
  .ld #disktab tr:first-child { display: none; }
  .ld #disktab tr { padding: 8px 0; border-bottom: 1px solid rgba(0,0,0,.12); }
  .ld #disktab td { display: block; border: 0; padding: 2px 0; background: none !important; color: inherit !important; }
  .ld #disktab td:nth-child(2), .ld #disktab td:nth-child(3) { display: inline-block; vertical-align: middle; margin-right: 10px; }
}
</style>
<div class="ld">
  <div class="box" id="ldjob" style="display:none"><div id="ldjobtxt"></div><div class="bar"><i id="ldbar"></i></div>
    <div class="row" id="ldctl" style="display:none"><button type="button" class="ldbtn" id="ldpause" onclick="ldCtl('pause')"><?php echo t('Pause'); ?></button>
      <button type="button" class="ldbtn" id="ldcont" onclick="ldCtl('continue')" style="display:none"><?php echo t('Continue'); ?></button>
      <button type="button" class="ldbtn" id="ldcancel" onclick="if (confirm(t('Cancel? What this job did so far is undone: nothing changes.'))) ldCtl('cancel')" style="border-color:#c62828;color:#c62828"><?php echo t('Cancel'); ?></button>
      <span class="small"><?php echo t('Pause holds the job and frees the station; Cancel undoes what was done so far.'); ?></span></div></div>

  <?php if ($ld_mode === 'data') { ?>
  <?php // 1. the locations: their records, the format of their clips, Move (owner 2026-10-24: Backup and Delete all in the sections below) ?>
  <?php // every section a panel with its title inside, as Settings › Model (owner 2026-10-24: one look in the whole app) ?>
  <table class="settingstable"><tr><td>
  <h2 id="ldformats"><?php echo t('Locations Data'); ?></h2>
  <table class="stdtable">
    <tr><th><?php echo t('Location'); ?></th><th><?php echo t('Detections'); ?></th><th title="<?php echo $h(t('Removed detections: out of the statistics, restorable until purged')); ?>"><?php echo t('Deleted'); ?></th><th title="<?php echo $h(t('Answered Dog, Human, Engine, Noise or Ignore, and excluded species: the detections keep counting, only the audio is purged')); ?>"><?php echo t('Ignored'); ?></th><th><?php echo t('First'); ?></th><th><?php echo t('Last'); ?></th><th><?php echo t('Format'); ?></th><th></th></tr>
    <?php foreach ($rows as $l) {
      $is_cur = $cur && intval($cur['id']) === intval($l['id']);
      echo '<tr data-id="' . intval($l['id']) . '" data-name="' . $h($l['name']) . '" data-n="' . $l['n'] . '"><td><b>' . $h($l['name']) . '</b> ' . ($is_cur ? '<span class="curbadge">' . t('current') . '</span>' : '') . '</td>'
        . '<td class="num">' . fmt_num($l['n']) . '</td><td class="num">' . fmt_num($l['removed']) . '</td><td class="num">' . fmt_num($l['ignored']) . '</td><td>' . $h($l['first']) . '</td><td>' . $h($l['last']) . '</td>'
        . '<td>' . $h($fmt_text($fmts[(string)$l['id']] ?? null)) . '</td><td>'
        // Move to location / to station only with several of them (simple mode, US-125)
        . ($l['n'] ? (multi_location() ? '<button type="button" class="ldbtn" onclick="ldPick(this,\'reassign\')">' . t('Move to location') . '</button>' : '')
          . (multi_station() ? '<button type="button" class="ldbtn" onclick="ldPick(this,\'station\')">' . t('Move to station') . '</button>' : '')
          // every detection of the location to the Removed folder (owner 2026-10-28: lost in the 2026-10-24 reorganisation)
          . '<button type="button" class="ldbtn del" onclick="ldRemove(this)">' . t('Delete all') . '</button>' : '')
        // the deleted detections of the location for good (owner 2026-10-24: in place of the section Purge Removed Sounds)
        . '<button type="button" class="ldbtn del" onclick="wipeDeleted(\'\', this, ' . $h(json_encode((string)intval($l['id']))) . ')"' . ($l['removed'] ? '' : ' disabled title="' . $h(t('Nothing to purge')) . '"') . '>' . t('Purge Removed') . '</button>'
        . '<button type="button" class="ldbtn del" onclick="purgeAudio(' . $h(json_encode((string)intval($l['id']))) . ', this)"' . ($l['ignored'] ? '' : ' disabled title="' . $h(t('Nothing to purge')) . '"') . '>' . t('Purge Ignored') . '</button>'
        . '</td></tr>';
    } ?>
  </table>
  <p class="small" style="margin:6px 0 0"><?php echo t('<b>Deleted</b>: the detections you removed; they left the statistics and can be restored until purged (Purge Removed deletes them for good).'); ?><br>
    <?php echo t('<b>Ignored</b>: answered Dog, Human, Engine, Noise or Ignore, and excluded species; the detections keep counting, Purge Ignored deletes only their audio.'); ?><br>
    <?php echo t('<b>Delete all</b>: every detection of the location to the Removed folder; they leave the statistics and can be restored until purged (Purge Removed).'); ?><br>
    <?php if (multi_location()) { ?><?php echo t('<b>Move to location</b>: the detections filed under another location of this station (a recording is one detection in one place, so there is no copy between locations).'); ?><br><?php } ?>
    <?php if (multi_station()) { ?><?php echo t('<b>Move to station</b>: a backup, then removed here; to keep them here too, make a Backup below and import it on the other station.'); ?><?php } ?></p>
  <p class="small" style="margin:6px 0 0"><b><?php echo t('Format:'); ?></b><br>
    <?php echo t('BirdSuite = the database uses the scientific name: the language does not matter to the model, fully portable.'); ?><br>
    <?php echo t('BirdNET = the database uses the common name; Import and Export handle the translation between languages.'); ?></p>
  <?php $nst = names_migration_state(); if ($nst !== null) { ?>
    <div class="row" style="border-left:4px solid #e0a000;padding-left:8px"><?php echo $h(names_migration_what($nst)); ?> <?php echo t('The database is copied first.'); ?>
      <button type="button" class="ldbtn main" onclick="nmStart(<?php echo $nst['running'] ? 'true' : 'false'; ?>)"><?php echo $nst['running'] ? t('Show progress') : t('Migrate to BirdSuite (scientific)'); ?></button></div>
  <?php } ?>
  <div class="box" id="ldpanel" style="display:none">
    <div class="row"><b id="ldtitle"></b></div>
    <div id="ldto" class="row" style="display:none"><?php echo t('Move to location:'); ?> <select id="ldtosel"><?php foreach ($rows as $l) echo '<option value="' . intval($l['id']) . '">' . $h($l['name']) . '</option>'; ?></select></div>
    <div id="ldst" class="row" style="display:none"><?php echo t('Station'); ?> <select id="ldstsel"><?php foreach ($stations as $s) {
      $a = $s['addresses'][0] ?? '';
      if ($a !== '' && strcasecmp($s['name'], get_sitename()) !== 0) echo '<option value="' . $h($a) . '">' . $h($s['name'] . ' (' . $a . ')') . '</option>';
    } ?></select> <span class="small"><?php echo t('the folder below must be reachable from that station too (e.g. the NAS)'); ?></span></div>
    <div id="ldfold" style="display:none"><div class="small"><?php echo t('Folder:'); ?></div><div class="crumbs" id="ldcrumbs"></div><div class="dirs" id="lddirs"></div></div>
    <div class="row"><button type="button" class="ldbtn main" id="ldgo" onclick="ldGo()"><?php echo t('Start'); ?></button> <button type="button" class="ldbtn" onclick="document.getElementById('ldpanel').style.display='none'"><?php echo t('Cancel'); ?></button> <b id="ldmsg"></b></div>
  </div>
  </td></tr></table><br>

  <?php // the life cycle of the sounds (owner 2026-10-24, text B), with this station's own numbers
  $cf = get_config();
  $lc = array('day' => intval($cf['MAX_FILES_SPECIES_DAY'] ?? 50), 'recent' => intval($cf['PURGE_RECENT_DAYS'] ?? 3),
    'total' => intval($cf['MAX_FILES_SPECIES'] ?? 100), 'disk' => intval($cf['PURGE_THRESHOLD'] ?? 70),
    'ignored' => intval($cf['IGNORED_PURGE_DAYS'] ?? 5), 'top' => intval($cf['PURGE_PROTECT_TOP_N'] ?? 50)); ?>
  <table class="settingstable"><tr><td>
  <h2><?php echo t('Life cycle of the sounds'); ?></h2>
  <div style="font-weight:700;margin-top:10px"><?php echo t('Detection.'); ?></div>
  <p style="margin-top:2px"><?php echo t('Each detection records a sound and a spectrogram in the folder of the day, by the scientific name.'); ?><br>
    <?php echo t('The record goes to the database, and it is the record that counts in the statistics.'); ?></p>
  <div style="font-weight:700;margin-top:10px"><?php echo t('Automatic limits (every night).'); ?></div>
  <p style="margin-top:2px"><?php // 0 = no limit (owner 2026-10-24): the sentence says so
    echo $lc['day'] ? t('In each day, a species keeps only its {n} sounds of highest confidence.', array('n' => $lc['day'] . ' (MAX_FILES_SPECIES_DAY)')) : t('No daily limit: a species keeps every sound of each day (MAX_FILES_SPECIES_DAY = 0).'); ?><br>
    <?php echo ($lc['total'] && $lc['recent']) ? t('Outside the last {days} days, each species keeps its {n} best in total.', array('days' => $lc['recent'] . ' (PURGE_RECENT_DAYS)', 'n' => $lc['total'] . ' (MAX_FILES_SPECIES)'))
      : t('No limit per species: every sound stays ({var} = 0).', array('var' => $lc['total'] ? 'PURGE_RECENT_DAYS' : 'MAX_FILES_SPECIES')); ?><br>
    <?php echo $lc['disk'] ? t('If the disk goes over {pct}, the oldest sounds are deleted until space is free.', array('pct' => $lc['disk'] . '% (PURGE_THRESHOLD)')) : t('No disk limit: nothing is deleted or stopped when the disk fills (PURGE_THRESHOLD = 0).'); ?><br>
    <?php echo t('These limits delete only the sound; the detection keeps counting.'); ?></p>
  <div style="font-weight:700;margin-top:10px"><?php echo t('Ignored.'); ?></div>
  <p style="margin-top:2px"><?php echo t('The answers Dog, Human, Engine, Noise or Ignore keep the detection in the database.'); ?><br>
    <?php echo $lc['ignored'] ? t('The sound goes to the Purge folder and is deleted by itself after {days} days, or at once with Purge Ignored.', array('days' => $lc['ignored'] . ' (IGNORED_PURGE_DAYS)')) : t('The sound goes to the Purge folder and stays there, no time limit, until Purge Ignored (IGNORED_PURGE_DAYS = 0).'); ?></p>
  <div style="font-weight:700;margin-top:10px"><?php echo t('Removed.'); ?></div>
  <p style="margin-top:2px"><?php echo t('Removing a detection takes it out of the lists and statistics and moves the sound to the Removed folder.'); ?><br>
    <?php echo t('Until Purge Removed it can be restored; after that it is gone for good.'); ?></p>
  <div style="font-weight:700;margin-top:10px"><?php echo t('Protected.'); ?></div>
  <p style="margin-top:2px"><?php echo $lc['top'] ? t('Sounds with the lock, confirmed "Yes", marked Review later and the {n} best of each species are never deleted by any limit.', array('n' => $lc['top'] . ' (PURGE_PROTECT_TOP_N)')) : t('Every sound is protected: no limit deletes any (PURGE_PROTECT_TOP_N = 0).'); ?></p>
  <?php // the settings behind the numbers, edited here (owner 2026-10-24: one place; their defaults in Settings › Defaults) ?>
  <table class="stdtable" data-nosort id="disktab" style="margin-top:14px">
    <tr><th><?php echo t('Setting'); ?></th><th><?php echo t('Value'); ?></th><th><?php echo t('Variable'); ?></th></tr>
    <?php // the owner's order (2026-10-24); 0 keeps everything in every one of them
    foreach (array(
      array(t('Maximum clips per species per day:'), t('In each day, a species keeps its N sounds of highest confidence.'), 'MAX_FILES_SPECIES_DAY', $lc['day'], 100000),
      array(t('Number of files to keep for each species:'), t('Outside the recent days, the N best sounds of each species stay.'), 'MAX_FILES_SPECIES', $lc['total'], 100000),
      array(t('Protect the clips of the last (days):'), t('The limit per species leaves the sounds of these days untouched.'), 'PURGE_RECENT_DAYS', $lc['recent'], 365),
      array(t('Purge the audio of Ignored detections after (days):'), t('After this, the sound of an Ignored detection is deleted by itself.'), 'IGNORED_PURGE_DAYS', $lc['ignored'], 3650),
      array(t('Always protect the best detections of each species:'), t('Besides the locks, "Yes" and Review later.'), 'PURGE_PROTECT_TOP_N', $lc['top'], 100)) as $row)
      echo '<tr><td><b>' . $h(rtrim($row[0], ': ')) . '</b><br><small>' . $h($row[1]) . ' ' . $h(t('0 = keep everything.')) . '</small></td><td><input type="number" name="' . $row[2] . '" value="' . intval($row[3])
        . '" min="0" max="' . $row[4] . '" step="1" style="width:6.5em"></td><td><code>' . $row[2] . '</code></td></tr>'; ?>
    <?php $fdm = in_array($cf['FULL_DISK'] ?? '', array('keep', 'external'), true) ? $cf['FULL_DISK'] : 'purge'; ?>
    <tr><td><b><?php echo t('When the disk is full'); ?></b><br><small><?php echo t('Purge deletes the oldest sounds; Keep stops the recording instead.'); ?><br><?php echo t('External backup moves the old sounds to another folder instead of deleting them.'); ?></small></td>
      <td style="white-space:nowrap"><label><input type="radio" name="FULL_DISK" value="purge" onchange="diskExt()"<?php echo $fdm === 'purge' ? ' checked' : ''; ?>> <?php echo t('Purge'); ?></label>
        <label><input type="radio" name="FULL_DISK" value="keep" onchange="diskExt()"<?php echo $fdm === 'keep' ? ' checked' : ''; ?>> <?php echo t('Keep'); ?></label>
        <label><input type="radio" name="FULL_DISK" value="external" onchange="diskExt()"<?php echo $fdm === 'external' ? ' checked' : ''; ?>> <?php echo t('External backup'); ?></label></td><td><code>FULL_DISK</code></td></tr>
    <?php // US-128 (owner 2026-10-29): the destination folder and what happens when it cannot be used, shown with External backup ?>
    <tr class="diskext"<?php echo $fdm === 'external' ? '' : ' style="display:none"'; ?>><td colspan="2"><b><?php echo t('Destination folder'); ?></b><br><small><?php echo t('The old sounds are moved here; their detections stay in the database.'); ?></small>
      <div id="diskextdirs" data-path="<?php echo $h($cf['FULL_DISK_PATH'] ?? ''); ?>"></div></td><td><code>FULL_DISK_PATH</code></td></tr>
    <tr class="diskext"<?php echo $fdm === 'external' ? '' : ' style="display:none"'; ?>><td><b><?php echo t('If the backup is not possible'); ?></b><br><small><?php echo t('Folder not mounted, not writable, full or on the station\'s own disk.'); ?></small></td>
      <td style="white-space:nowrap"><label><input type="radio" name="FULL_DISK_FALLBACK" value="keep"<?php echo ($cf['FULL_DISK_FALLBACK'] ?? 'keep') !== 'purge' ? ' checked' : ''; ?>> <?php echo t('Stop the recording'); ?></label>
        <label><input type="radio" name="FULL_DISK_FALLBACK" value="purge"<?php echo ($cf['FULL_DISK_FALLBACK'] ?? '') === 'purge' ? ' checked' : ''; ?>> <?php echo t('Delete the oldest'); ?></label></td><td><code>FULL_DISK_FALLBACK</code></td></tr>
    <tr><td><b><?php echo t('Purge Threshold (Disk Used %)'); ?></b><br><small><?php echo $h(t('How full the disk may get before the purge (or the stop, with Keep).') . ' ' . t('0 = keep everything.')); ?></small></td>
      <td><input type="number" name="PURGE_THRESHOLD" value="<?php echo intval($lc['disk']); ?>" min="0" max="99" step="1" style="width:6.5em"></td><td><code>PURGE_THRESHOLD</code></td></tr>
  </table>
  <div class="row"><button type="button" class="ldbtn main" onclick="diskSave(this)"><?php echo t('Save'); ?></button> <b id="diskmsg"></b>
    <button type="button" class="ldbtn" onclick="diskCount(this)"><?php echo t('Disk usage summary'); ?></button></div>
  <pre id="diskcount" style="display:none;max-height:40vh;overflow:auto;background:#111;color:#ddd;border-radius:8px;padding:8px;font-size:12px;white-space:pre-wrap"></pre>
  <script>
  function diskSave(btn) {
    var body = {};
    document.querySelectorAll('#disktab input[type=number]').forEach(function (i) { body[i.name] = i.value; });
    var fd = document.querySelector('#disktab input[name=FULL_DISK]:checked'); if (fd) body.FULL_DISK = fd.value;
    if (fd && fd.value === 'external') {
      if (!diskExtTree || !diskExtTree.selected()) { document.getElementById('diskmsg').textContent = t('Choose the destination folder.'); return; }
      body.FULL_DISK_PATH = diskExtTree.selected();
      var fb = document.querySelector('#disktab input[name=FULL_DISK_FALLBACK]:checked'); if (fb) body.FULL_DISK_FALLBACK = fb.value;
    }
    var m = document.getElementById('diskmsg'); m.textContent = t('Saving…');
    var x = new XMLHttpRequest();
    x.onload = function () {
      var r = this.responseText.trim();
      if (r === 'OK') location.reload();
      // saved, but the external folder cannot be used now: said, the page stays
      else if (r.indexOf('OK ') === 0) m.textContent = t('Saved.') + ' ' + t('The external backup is not possible now:') + ' ' + r.slice(3);
      else m.textContent = this.status === 401 ? t('Log in first.') : (r || t('Not saved'));
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
    var pre = document.getElementById('diskcount'); pre.style.display = ''; pre.textContent = t('Loading ... please wait.');
    var x = new XMLHttpRequest();
    x.onload = function () { pre.textContent = this.status === 401 ? t('Log in first.') : this.responseText; };
    x.open('GET', 'scripts/location_data.php?disk_count=1', true); x.send();
  }
  </script>
  </td></tr></table><br>
  <?php } ?>
  <?php if ($ld_mode === 'backup') { ?>
  <?php // 2. Export / Backup (owner 2026-10-24): the location and the format ?>
  <table class="settingstable"><tr><td>
  <h2><?php echo t('Export / Backup'); ?></h2>
    <div class="row"><?php echo t('Location'); ?> <select id="bxloc"><option value="0" data-n="<?php echo array_sum(array_column($rows, 'n')); ?>"><?php echo t('All locations'); ?></option><?php
      foreach ($rows as $l) if ($l['n']) echo '<option value="' . intval($l['id']) . '" data-n="' . $l['n'] . '">' . $h($l['name']) . '</option>'; ?></select>
      <?php echo t('Format'); ?> <select id="bxfmt" onchange="document.getElementById('bxlangrow').style.display = this.value === 'birdnet' ? '' : 'none'; document.getElementById('bxincrow').style.display = this.value === 'birdnet' ? 'none' : ''">
        <option value="birdsuite"><?php echo t('BirdSuite: scientific (default)'); ?></option>
        <option value="birdnet"><?php echo t('BirdNET: common names'); ?></option></select>
      <span id="bxlangrow" style="display:none"><?php echo t('Common names in'); ?> <select id="bxlang"><?php
        $db_lang = get_config()['DATABASE_LANG'] ?? 'en';
        foreach (common_name_languages() as $code => $name) {
          if (is_file("$home/BirdNET-Pi/model/l18n/labels_$code.json")) echo '<option value="' . $h($code) . '"' . ($code === $db_lang ? ' selected' : '') . '>' . $h(t($name)) . '</option>';
        } ?></select></span></div>
    <div class="row small"><?php echo t('Choose the destination folder.'); ?></div>
    <?php // incremental backup (owner 2026-10-29), BirdSuite format only ?>
    <div class="row" id="bxincrow"><label><input type="checkbox" id="bxinc"> <?php echo t('Incremental: only what is new since the last backup in this folder'); ?></label></div>
    <div class="crumbs" id="bxcrumbs"></div><div class="dirs" id="bxdirs"></div>
    <div class="row"><button type="button" class="ldbtn main" onclick="bxGo()"><?php echo t('Start'); ?></button> <b id="bxmsg"></b></div>
  </td></tr></table><br>

  <?php // 3. Import (formerly Restore a backup, owner 2026-10-24): BirdSuite or BirdNET, brought into the BirdSuite format ?>
  <table class="settingstable"><tr><td>
  <h2><?php echo t('Import'); ?></h2>
    <div class="row small"><?php echo t('Select the folder that holds the backup to restore.'); ?><br>
      <?php echo t('📦 = a backup'); ?></div>
    <div class="crumbs" id="rscrumbs"></div><div class="dirs" id="rsdirs"></div>
    <div class="row" id="rsinfo"></div>
    <div class="row"><label for="rsto" style="font-weight:normal"><?php echo t('Into'); ?></label> <select id="rsto"><?php
      // Test / Playback, or new locations (owner 2026-10-24): checked there first, then filed under the real one in Move Data
      foreach ($rows as $l) if (($l['builtin'] ?? '') === 'test') echo '<option value="' . intval($l['id']) . '" selected>' . $h($l['name']) . '</option>'; ?>
      <option value="new"><?php echo t('New location (one per location of the backup)'); ?></option>
      <?php // an existing location too, below New location (owner 2026-10-24)
      foreach ($rows as $l) if (($l['builtin'] ?? '') !== 'test') echo '<option value="' . intval($l['id']) . '">' . $h($l['name']) . '</option>'; ?></select>
      <button type="button" class="ldbtn main" id="rsgo" onclick="rsGo()" disabled><?php echo t('Import'); ?></button> <b id="rsmsg"></b></div>
    <div class="row small"><?php echo t('Best into Test / Playback or a new location, then move it in Tools › Data.'); ?><br>
      <?php echo t('A backup of several locations: choose New location.'); ?></div>
  </td></tr></table><br>
  <?php } ?>
  <?php // 4. the settings (Backup/Import) and 5. the danger zone (Data) ?>
  <?php include __DIR__ . '/station_backup.php'; ?>
</div>
<?php names_migration_overlay(); ?>
<script src="static/species-modal.js?v=<?php echo @filemtime(__DIR__ . "/../homepage/static/species-modal.js"); ?>"></script>
<script src="static/wipe-deleted.js?v=<?php echo @filemtime(__DIR__ . "/../homepage/static/wipe-deleted.js"); ?>"></script>
<script src="static/folder-picker.js?v=<?php echo @filemtime(__DIR__ . "/../homepage/static/folder-picker.js"); ?>"></script>
<script>
// Purge audio: play.php?purgeaudio removes the clips and spectrograms, the detections stay in the database; then reload
// Purge Ignored: the audio of the location's detections answered with a cause and of its excluded species
// (play.php?purgeaudio, both kinds); the detections stay in the database; then reload
function purgeAudio(loc, btn) {
  askModal(t('Purge the audio of these detections?'), t('The clips and spectrograms are deleted for good and cannot be restored.') + '\n' + t('The detections stay in the database and keep counting in every statistic.'), t('Purge audio'), true).then(function (ok) {
    if (!ok) return;
    btn.disabled = true;
    var kinds = ['causes', 'others'], step = function () {
      if (!kinds.length) return location.reload();
      var x = new XMLHttpRequest();
      x.onload = function () { if (/^OK \d+/.test(this.responseText)) step(); else { btn.disabled = false; alert(this.status === 401 ? t('Log in first.') : this.responseText); } };
      x.open('GET', 'play.php?purgeaudio=' + kinds.shift() + '&loc=' + encodeURIComponent(loc), true);
      x.send();
    };
    step();
  });
}
var ldAct = '', ldLoc = 0, ldName = '', ldN = 0, ldFolder = '', rsFolder = '', bxFolder = '', ldTimer = null;
var esc = function (s) { return String(s == null ? '' : s).replace(/[&<>"]/g, function (c) { return {'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;'}[c]; }); };
function ldGetJ(url, done) { var x = new XMLHttpRequest(); x.onload = function () { try { done(JSON.parse(this.responseText)); } catch (e) { done(null); } }; x.open('GET', url, true); x.send(); }
function ldRun(body, msgEl) {
  var x = new XMLHttpRequest();
  x.onload = function () {
    var t = this.status === 401 ? window.t('Log in first.') : this.responseText.trim();
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
  askModal(t('Delete all'), t('Move all {count} detections of "{name}" to the Removed folder?', {count: window.fmtNum(parseInt(tr.dataset.n, 10)), name: tr.dataset.name})
    + '\n' + t('They leave every page and statistic and can be restored here until purged (Purge Removed).'), t('Delete all'), true)
    .then(function (ok) { if (ok) ldRun({action: 'remove', loc: parseInt(tr.dataset.id, 10)}, 'ldmsg'); });
}
function ldPick(btn, act) {
  var tr = btn.closest('tr');
  ldAct = act; ldLoc = parseInt(tr.dataset.id, 10); ldName = tr.dataset.name; ldN = parseInt(tr.dataset.n, 10);
  var tv = {name: ldName, count: window.fmtNum(ldN)};
  document.getElementById('ldtitle').textContent = act === 'reassign' ? t('Move to another location: {name} ({count} detections)', tv) : t('Move to another station: {name} ({count} detections)', tv);
  document.getElementById('ldto').style.display = act === 'reassign' ? '' : 'none';
  document.getElementById('ldst').style.display = act === 'station' ? '' : 'none';
  document.getElementById('ldfold').style.display = act === 'reassign' ? 'none' : '';
  document.getElementById('ldmsg').textContent = '';
  document.getElementById('ldpanel').style.display = '';
  if (act !== 'reassign') ldBrowse(ldFolder, 'ldcrumbs', 'lddirs', function (p) { ldFolder = p; });
}
function ldGo() {
  var body = {action: ldAct, loc: ldLoc};
  if (ldAct === 'reassign') {
    body.to = parseInt(document.getElementById('ldtosel').value, 10);
    var toName = document.getElementById('ldtosel').selectedOptions[0].textContent;
    if (!confirm(t('File the {count} detections of "{name}" under "{to}"? Their coordinates become those of "{to}".', {count: window.fmtNum(ldN), name: ldName, to: toName}))) return;
  } else {
    if (!ldFolder) { document.getElementById('ldmsg').textContent = t('Choose a folder'); return; }
    body.folder = ldFolder;
    var st = document.getElementById('ldstsel');
    if (!st.value) { document.getElementById('ldmsg').textContent = t('Register the other station first (Settings › Stations)'); return; }
    try { localStorage.setItem('ld_station', st.value); } catch (e) {}
    if (!confirm(t('Copy the {count} detections of "{name}" to {folder}, then move them here to the Removed folder? Then restore them on {station}.', {count: window.fmtNum(ldN), name: ldName, folder: ldFolder, station: st.selectedOptions[0].textContent}))) return;
  }
  ldRun(body, 'ldmsg');
}
// Export / Backup: the location (0 = all) in the format chosen
function bxGo() {
  if (!bxFolder) { document.getElementById('bxmsg').textContent = t('Choose a folder'); return; }
  var loc = document.getElementById('bxloc'), fmt = document.getElementById('bxfmt'), lang = document.getElementById('bxlang');
  var o = loc.selectedOptions[0];
  var inc = fmt.value !== 'birdnet' && document.getElementById('bxinc').checked;
  if (!confirm(inc ? t('Copy what is new since the last backup of "{name}" in {folder} to a new folder there?', {name: o.textContent, folder: bxFolder})
      : t('Copy the {count} detections of "{name}" ({format}) to a new folder in {folder}?', {count: window.fmtNum(parseInt(o.dataset.n, 10)), name: o.textContent,
      format: fmt.value === 'birdnet' ? fmt.selectedOptions[0].textContent + ', ' + lang.selectedOptions[0].textContent : fmt.selectedOptions[0].textContent, folder: bxFolder}))) return;
  var body = fmt.value === 'birdnet' ? {action: 'legacy', loc: parseInt(loc.value, 10), lang: lang.value, folder: bxFolder}
    : {action: 'backup', loc: parseInt(loc.value, 10), folder: bxFolder, incremental: inc};
  ldRun(body, 'bxmsg');
}
function rsGo() {
  var to = document.getElementById('rsto').value;
  if (!confirm(t('Import the folder {folder} into this station?', {folder: rsFolder}))) return;
  ldRun({action: 'restore', folder: rsFolder, to: to}, 'rsmsg');
}
function ldStation() { try { return localStorage.getItem('ld_station') || ''; } catch (e) { return ''; } }
function ldCtl(what) {
  var x = new XMLHttpRequest();
  x.onload = function () { var t = this.status === 401 ? window.t('Log in first.') : this.responseText.trim(); if (t !== 'OK') alert(t); setTimeout(ldPoll, 600); };
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
    var names = {remove: t('Deleting'), backup: t('Backing up'), restore: t('Importing'), reassign: t('Moving'), legacy: t('Exporting')};
    var live = j.status === 'running' || j.status === 'paused', reassign = j.action === 'reassign';
    document.getElementById('ldctl').style.display = live && !reassign ? '' : 'none';
    document.getElementById('ldpause').style.display = j.status === 'running' ? '' : 'none';
    document.getElementById('ldcont').style.display = j.status === 'paused' ? '' : 'none';
    if (live || j.status === 'cancelling') {
      document.getElementById('ldjobtxt').textContent = (j.status === 'cancelling' ? t('Cancelling: undoing') + ' ' : j.status === 'paused' ? t('Paused ({time}):', {time: j.paused_at || ''}) + ' ' : '')
        + (names[j.action] || j.action) + ' ' + (j.location || '') + ': ' + (j.total ? t('{done} of {total}', {done: window.fmtNum((j.done || 0)), total: window.fmtNum(j.total)}) : window.fmtNum((j.done || 0)));
      document.getElementById('ldbar').style.width = (j.total ? 100 * j.done / j.total : 0) + '%';
      ldTimer = setTimeout(ldPoll, 2000);
    } else {
      document.getElementById('ldbar').style.width = j.status === 'done' ? '100%' : '0';
      document.getElementById('ldjobtxt').innerHTML = (j.status === 'done' || j.status === 'cancelled' ? (j.status === 'done' ? '&#10003; ' : '') + esc(j.result) : '<span style="color:#c62828">' + t('{action} stopped: {error}', {action: esc(names[j.action] || j.action), error: esc(j.error)}) + '</span>')
        + ' <span class="small">(' + esc(j.finished) + ')</span>'
        + (j.status === 'done' && j.action === 'remove' && j.target && ldStation() ? '<br>' + t('Now restore it on the other station:') + ' <a target="_top" href="' + location.protocol + '//' + esc(ldStation()) + '/?view=Backup">' + t('open Backup/Restore there') + '</a>' : '')
        + ' <button type="button" class="ldbtn" onclick="location.reload()">' + t('Refresh the table') + '</button>'
        + ' <button type="button" class="ldbtn" onclick="ldJobClose(' + esc(JSON.stringify(j.finished || '')) + ')">' + t('Close') + '</button>';
    }
  });
}
// Export starts in the folder of the sounds, Import in the home folder (owner 2026-10-24)
if (document.getElementById('bxcrumbs')) ldBrowse(<?php echo json_encode(get_home() . '/BirdSongs'); ?>, 'bxcrumbs', 'bxdirs', function (p) { bxFolder = p; });
if (document.getElementById('rscrumbs')) ldBrowse(<?php echo json_encode(get_home()); ?>, 'rscrumbs', 'rsdirs', function (p) {
  rsFolder = p;
  document.getElementById('rsgo').disabled = true;
  document.getElementById('rsinfo').textContent = '';
  if (!p) return;
  ldGetJ('scripts/location_data.php?peek=' + encodeURIComponent(p), function (m) {
    if (!m || m.error) return;
    var fmt = m.birdnet === 0 ? t('Scientific (BirdSuite)') : t('{lang} (BirdNET)', {lang: esc(m.lang_name || t('unknown language'))});
    if (m.birdnet > 0 && m.birdsuite > 0) fmt = t('Scientific (BirdSuite) + {n} {lang} (BirdNET)', {n: window.fmtNum(m.birdnet), lang: esc(m.lang_name || t('unknown language'))});
    document.getElementById('rsinfo').innerHTML = (m.kind === 'birdnet'
      ? t('BirdNET folder {name}: {count} detections', {name: '<b>' + esc(m.station) + '</b>', count: window.fmtNum(m.detections || 0)})
      : t('Backup of <b>{name}</b> from station {station} ({date}): {count} detections', {name: esc(m.location), station: esc(m.station), date: esc(m.exported), count: window.fmtNum((m.detections || 0))}))
      + ' — ' + t('Format') + ': <b>' + fmt + '</b>' + (m.birdnet > 0 ? ' <span class="small">(' + t('converted to Scientific (BirdSuite) on import') + ')</span>' : '');
    document.getElementById('rsgo').disabled = false;
  });
});
ldPoll();
</script>
