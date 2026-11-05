<?php
/* System › Status (owner 2026-10-21): one page for the health and the control of the station, in place of System
 * Controls, Services, Station Doctor and System Info. From the top: the version with Reboot / Update / Shutdown (and
 * the notes of a new release), the Station Doctor checks with their one-click fixes, the services as a table with
 * their state and buttons, and a link to the full hardware details (phpsysinfo). The commands go through
 * views.php?submit= and its allow list, exactly as on the former pages. Backup, Restore and Clear ALL data moved to
 * Tools › Backup/Restore. Opened as views.php?view=Status (the old views Doctor, Services and System Controls too). */
require_once __DIR__ . '/common.php';
if (session_status() === PHP_SESSION_NONE) @session_start();
$user = get_user();
$home = get_home();
$h = function ($s) { return htmlspecialchars((string)$s, ENT_QUOTES); };

// version and update state (from the former System Controls)
$git = station_prefix() . "git -C " . BS_PROG . " ";
// the newest release tag fetched
function status_latest_tag($git) {
  $latest = '';
  foreach (explode("\n", (string)shell_exec($git . "tag -l 'v*' 2>/dev/null")) as $tg) {
    if (preg_match('~^v\d+\.\d+\.\d+$~', trim($tg)) && ($latest === '' || version_compare(substr(trim($tg), 1), substr($latest, 1), '>'))) $latest = trim($tg);
  }
  return $latest;
}
// the newest commit without making the page wait (US-05: Status under 2 s): the page is drawn with the state of the
// last fetch (FETCH_HEAD); when that fetch is older than 10 minutes the page asks ?check=1 in the background, which
// fetches now (time limit: an offline station must not hang) and answers the new state; the page reloads when it changed
$update_branch = get_config()['UPDATE_BRANCH'] ?? 'main';
// the services: label, unit, and the four commands of the former Services page (the allow list of views.php)
$svc = function ($label, $unit, $extra_stop = '', $extra_start = '') {
  return array($label, $unit,
    "sudo -n " . BS_SVC_HELPER . " stop $unit" . $extra_stop, "sudo -n " . BS_SVC_HELPER . " restart $unit" . $extra_start,
    "sudo -n " . BS_SVC_HELPER . " disable-now $unit", "sudo -n " . BS_SVC_HELPER . " enable-now $unit");
};
$services = array(
  array(t('str_live_audio_stream'), BS_SVC_LIVESTREAM . '.service',
    'sudo -n ' . BS_SVC_HELPER . ' stop ' . BS_SVC_LIVESTREAM . '.service && sudo -n ' . BS_SVC_HELPER . ' stop ' . BS_SVC_ICECAST . '.service',
    'sudo -n ' . BS_SVC_HELPER . ' restart ' . BS_SVC_LIVESTREAM . '.service && sudo -n ' . BS_SVC_HELPER . ' restart ' . BS_SVC_ICECAST . '.service',
    'sudo -n ' . BS_SVC_HELPER . ' disable-now ' . BS_SVC_LIVESTREAM . '.service && sudo -n ' . BS_SVC_HELPER . ' disable ' . BS_SVC_ICECAST . ' && sudo -n ' . BS_SVC_HELPER . ' stop ' . BS_SVC_ICECAST . '.service',
    'sudo -n ' . BS_SVC_HELPER . ' enable ' . BS_SVC_ICECAST . ' && sudo -n ' . BS_SVC_HELPER . ' start ' . BS_SVC_ICECAST . '.service && sudo -n ' . BS_SVC_HELPER . ' enable-now ' . BS_SVC_LIVESTREAM . '.service'),
  $svc(t('str_recording_service'), BS_SVC_RECORDING . '.service'),
  $svc(t('str_analysis_service'), BS_SVC_ANALYSIS . '.service'),
  $svc(t('str_analysis_log'), BS_SVC_LOG . '.service'),
  $svc(t('str_spectrogram_view'), BS_SVC_SPECTROGRAM . '.service'),
  $svc(t('str_console_login'), BS_SVC_TERMINAL . '.service'),
);
// a service button on a screen of its own, as the update (owner 2026-10-24): ?svc=start (POST, cmd = one of the
// commands above) runs it detached (Restart Core Services restarts the web server too), then shows the state of its
// units; ?svc=log&from=N answers as ?update=log
if (isset($_GET['svc'])) {
  ensure_authenticated(t('str_you_must_change_the_services_help'));
  header('Content-Type: application/json');
  $dir = BS_STATE;
  @mkdir($dir, 0775, true);
  $log = $dir . '/web_service.log';
  $mark = '__BIRDSUITE_SVC_EXIT__';
  if ($_GET['svc'] === 'start' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $cmd = (string)($_POST['cmd'] ?? '');
    $allowed = array('stop_core_services.sh', 'restart_services.sh');
    foreach ($services as $sv) array_push($allowed, $sv[2], $sv[3], $sv[4], $sv[5]);
    if (!in_array($cmd, $allowed, true)) { echo json_encode(array('ok' => false)); die(); }
    $txt = (string)@file_get_contents($log);
    if ($txt !== '' && strpos($txt, $mark) === false && filemtime($log) > time() - 600) { echo json_encode(array('ok' => false, 'busy' => true)); die(); }
    preg_match_all('/\S+\.service\b/', $cmd, $m);
    $units = array_unique($m[0]);
    if (!$units) $units = array(BS_SVC_RECORDING . '.service', BS_SVC_ANALYSIS . '.service', BS_SVC_LOG . '.service', BS_SVC_SPECTROGRAM . '.service');
    $show = 'echo; for u in ' . implode(' ', array_map('escapeshellarg', $units)) . '; do sudo -n ' . BS_SVC_HELPER . ' status "$u" 2>&1 | head -n 4; echo; done';
    file_put_contents($log, '$ ' . $cmd . "\n");
    shell_exec('setsid -f sh -c ' . escapeshellarg('(' . $cmd . '); rc=$?; sleep 2; ' . $show . '; echo "' . $mark . ' $rc"')
      . ' >> ' . escapeshellarg($log) . ' 2>&1 < /dev/null');
    echo json_encode(array('ok' => true));
    die();
  }
  $from = max(0, intval($_GET['from'] ?? 0));
  $txt = (string)@file_get_contents($log);
  $new = substr($txt, min($from, strlen($txt)));
  $code = preg_match('/' . $mark . ' (\d+)/', $txt, $m) ? intval($m[1]) : null;
  $new = preg_replace('/' . $mark . ' \d+\s*/', '', preg_replace('/\e\[[0-9;?]*[A-Za-z]/', '', $new));
  echo json_encode(array('text' => $new, 'size' => strlen($txt), 'done' => $code !== null, 'code' => $code));
  die();
}
// the update with its progress on screen (owner 2026-10-24): ?update=start runs it detached from the web request
// (setsid: the update restarts php-fpm, which would end a request that waited for it), its output in a log;
// ?update=log&from=N answers what was added since byte N and, at the end, the exit code
if (isset($_GET['update'])) {
  ensure_authenticated(t('str_you_must_be_authenticated_to_update'));
  header('Content-Type: application/json');
  $dir = BS_STATE;
  @mkdir($dir, 0775, true);
  $log = $dir . '/web_update.log';
  $mark = '__BIRDSUITE_UPDATE_EXIT__';
  if ($_GET['update'] === 'start' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $txt = (string)@file_get_contents($log);
    $running = $txt !== '' && strpos($txt, $mark) === false && filemtime($log) > time() - 1800;
    if (!$running) {
      file_put_contents($log, '');
      session_unset();  // the settings are read again after the update (as the former Update did)
      shell_exec('setsid -f sh -c ' . escapeshellarg('sudo -n ' . BS_ROOT_HELPER . ' update; echo "' . $mark . ' $?"')
        . ' >> ' . escapeshellarg($log) . ' 2>&1 < /dev/null');
    }
    echo json_encode(array('ok' => true));
    die();
  }
  $from = max(0, intval($_GET['from'] ?? 0));
  $txt = (string)@file_get_contents($log);
  $new = substr($txt, min($from, strlen($txt)));
  $code = preg_match('/' . $mark . ' (\d+)/', $txt, $m) ? intval($m[1]) : null;
  $new = preg_replace('/' . $mark . ' \d+\s*/', '', preg_replace('/\e\[[0-9;?]*[A-Za-z]/', '', $new));
  echo json_encode(array('text' => $new, 'size' => strlen($txt), 'done' => $code !== null, 'code' => $code));
  die();
}
// the migration from BirdNET-Pi on the same card (US-01, US-05; owner 2026-11-02): ?migration=remove | back | import
// (POST, login) and ?migration=archive (the BirdNET-Pi settings, login). Remove and Go back are the closed actions of
// the root helper, which work only on their own fixed paths; Import now starts a cancelled or failed import again
if (isset($_GET['migration'])) {
  ensure_authenticated(t('str_you_must_be_authenticated_to_change_migration'));
  $act = (string)$_GET['migration'];
  if ($act === 'archive') {
    if (!is_file(BS_UPSTREAM_ARCHIVE)) { header('HTTP/1.0 404 Not Found'); die(); }
    header('Content-Type: application/gzip');
    header('Content-Disposition: attachment; filename="' . basename(BS_UPSTREAM_ARCHIVE) . '"');
    header('Content-Length: ' . filesize(BS_UPSTREAM_ARCHIVE));
    readfile(BS_UPSTREAM_ARCHIVE);
    die();
  }
  header('Content-Type: application/json');
  if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !in_array($act, array('remove', 'back', 'import'), true)) { echo json_encode(array('ok' => false)); die(); }
  $o = array();
  $rc = 1;
  if ($act === 'import') {
    exec(station_prefix() . 'timeout 60 python3 ' . escapeshellarg(BS_SCRIPTS . '/migration.py') . ' requeue 2>&1', $o, $rc);
    echo json_encode(array('ok' => $rc === 0 && trim(implode('', $o)) === 'OK', 'msg' => implode("\n", $o)));
    die();
  }
  // Go back ends with a restart of the station a few seconds later, so the answer still gets out
  exec('sudo -n ' . BS_ROOT_HELPER . ' migration-' . $act . ' 2>&1', $o, $rc);
  echo json_encode(array('ok' => $rc === 0, 'msg' => $rc === 0 ? ($act === 'remove' ? t('str_the_birdnet_pi_leftovers_were_removed') : t('str_birdnet_pi_is_back_the_station_help')) : implode("\n", $o)));
  die();
}
if (isset($_GET['check'])) {
  shell_exec("timeout 25 " . $git . "fetch --tags --force origin " . escapeshellarg($update_branch) . " > /dev/null 2>&1");
  header('Content-Type: application/json');
  echo json_encode(array('behind' => update_behind(), 'latest' => status_latest_tag($git)));
  die();
}
$fetched = update_fetch_age() < 600;
// the version block costs a dozen git calls through the station user: cached in state/ until the git state changes
// (HEAD, the checked-out branch, FETCH_HEAD, the tags), so Status draws under 2 s (US-05)
$git_dir = BS_PROG . '/.git';
$head_ref = preg_match('~^ref: (\S+)~', (string)@file_get_contents($git_dir . '/HEAD'), $hm) ? $hm[1] : 'HEAD';
$vkey = implode(':', array_map(function ($f) use ($git_dir) { return (int)@filemtime($git_dir . '/' . $f); },
  array('HEAD', $head_ref, 'FETCH_HEAD', 'packed-refs', 'refs/tags'))) . ':' . $update_branch . ':' . ui_lang();
$vcache = @json_decode((string)@file_get_contents(BS_STATE . '/status_version.json'), true);
if (is_array($vcache) && ($vcache['key'] ?? '') === $vkey) {
  extract($vcache['data']);
} else {
  $behind = update_behind();
  $_SESSION['behind'] = $behind;
  $_SESSION['behind_time'] = time();
  list($installed, $ahead) = installed_release();  // a shallow clone gives the release but not the commit count ($ahead null)
  $latest = status_latest_tag($git);
  $_SESSION['release_installed'] = $installed;
  $_SESSION['release_new'] = ($latest !== '' && $installed !== '' && version_compare(substr($latest, 1), substr($installed, 1), '>')) ? $latest : '';
  $update_label = t('str_update_to_the_latest', array('branch' => $update_branch));
  // Update is always shown; greyed when nothing newer is on the update branch (owner 2026-10-22: the latest version is
  // installed); its label says what it installs
  $up_to_date = $behind === '0' && $_SESSION['release_new'] === '';
  $release_notes = array();
  if ($_SESSION['release_new'] !== '') {
    foreach (explode("\n", (string)shell_exec($git . "tag -l 'v*' --sort=-v:refname 2>/dev/null")) as $tg) {
      $tg = trim($tg);
      if (!preg_match('~^v\d+\.\d+\.\d+$~', $tg) || version_compare(substr($tg, 1), substr($installed, 1), '<=')
        || version_compare(substr($tg, 1), substr($latest, 1), '>')) continue;
      $msg = trim(preg_replace('~-----BEGIN PGP SIGNATURE-----.*~s', '', trim((string)shell_exec($git . "tag -l --format='%(contents)' " . escapeshellarg($tg) . " 2>/dev/null"))));
      $lines = explode("\n", $msg, 2);
      $release_notes[] = array('tag' => $tg, 'title' => trim($lines[0]) !== '' ? trim($lines[0]) : $tg, 'body' => trim($lines[1] ?? ''));
    }
  }
  // the installed commit and what the update installs (owner 2026-10-24: "from → to" on the update screen: the fetched
  // head of the update branch), each with its date and time in the station's time, in one git call (release run 4,
  // PERF-21: the first Status after a change made eight of them)
  $refs = is_file(BS_PROG . '/.git/FETCH_HEAD') ? 'HEAD FETCH_HEAD' : 'HEAD';
  $lines = explode("\n", trim((string)shell_exec($git . "show -s --format='%h|%cd' --date=format-local:'%Y-%m-%d %H:%M' " . $refs . " 2>/dev/null")));
  list($commit, $from_when) = array_pad(explode('|', trim($lines[0] ?? ''), 2), 2, '');
  list($target, $to_when) = array_pad(explode('|', trim($lines[1] ?? ''), 2), 2, '');
  // a FETCH_HEAD git cannot read fails the whole call: then HEAD alone
  if ($commit === '' && $refs !== 'HEAD') {
    list($commit, $from_when) = array_pad(explode('|', trim((string)shell_exec($git . "show -s --format='%h|%cd' --date=format-local:'%Y-%m-%d %H:%M' HEAD 2>/dev/null")), 2), 2, '');
    $target = $to_when = '';
    $lines = array();
  }
  // the same commit twice is shown once by git: nothing new fetched
  if ($refs !== 'HEAD' && count($lines) === 1) list($target, $to_when) = array($commit, $from_when);
  @file_put_contents(BS_STATE . '/status_version.json', json_encode(array('key' => $vkey, 'data' => compact('behind', 'installed', 'ahead',
    'latest', 'update_label', 'up_to_date', 'release_notes', 'commit', 'target', 'from_when', 'to_when'))), LOCK_EX);
}
$_SESSION['behind'] = $behind;
$_SESSION['behind_time'] = time();
$_SESSION['release_installed'] = $installed;
$_SESSION['release_new'] = ($latest !== '' && $installed !== '' && version_compare(substr($latest, 1), substr($installed, 1), '>')) ? $latest : '';
$up_from = trim(($installed ?: '') . ' (' . $commit . ($from_when !== '' ? ', ' . $from_when : '') . ')');
$up_to = trim($update_branch
  . ($target !== '' ? ' (' . $target . ($to_when !== '' ? ', ' . $to_when : '') . ')' : ''));

// every unit's state in one systemctl call (it was a sudo + systemctl status per unit)
$unit_state = function ($unit) {
  static $map = null;
  if ($map === null) {
    $map = array();
    global $services, $mount;
    $units = array_merge(array_map(function ($x) { return $x[1]; }, $services ?? array()), isset($mount) ? array($mount) : array());
    foreach (preg_split('/\n\s*\n/', (string)shell_exec('systemctl show -p Id,ActiveState,SubState ' . implode(' ', array_map('escapeshellarg', $units)) . ' 2>/dev/null')) as $blk) {
      $kv = array();
      foreach (explode("\n", trim($blk)) as $l) { $q = strpos($l, '='); if ($q !== false) $kv[substr($l, 0, $q)] = substr($l, $q + 1); }
      if (!empty($kv['Id'])) $map[$kv['Id']] = $kv;
    }
  }
  return $map[$unit] ?? null;
};
$state = function ($unit) use ($home, $unit_state) {
  if ($unit === BS_SVC_ANALYSIS . '.service') {
    $n = intval(trim((string)shell_exec("ls " . escapeshellarg(BS_STREAMDATA) . " | wc -l")));
    if ($n > 200) return array('warn', t('str_stalled_backlog_of_files_in', array('n' => $n, 'dir' => BS_STREAMDATA)));
  }
  $u = $unit_state($unit);
  // the RAM drive near its limit (owner 2026-11-03): the recordings waiting would soon find no room
  if ($u && $u['SubState'] === 'mounted' && ($tot = @disk_total_space(BS_STREAMDATA)) > 0 && 1 - @disk_free_space(BS_STREAMDATA) / $tot > 0.8)
    return array('warn', t('str_the_ram_drive_is_pct_full', array('pct' => round(100 * (1 - disk_free_space(BS_STREAMDATA) / $tot)))));
  if ($u && $u['ActiveState'] === 'active' && in_array($u['SubState'], array('running', 'mounted'), true)) return array('ok', t('str_active_lc'));
  if ($u && $u['ActiveState'] === 'inactive') return array('warn', t('str_inactive'));
  return array('fail', $u ? $u['ActiveState'] . ' [' . $u['SubState'] . ']' : 'ERROR');
};
$mount = get_service_mount_name();
?>
<style>
.status { max-width: 1000px; margin: 0; text-align: left; padding: 0 12px; box-sizing: border-box; }
.status .box { padding: 10px 14px; margin: 10px 0; }
.status .actions { display: flex; flex-wrap: wrap; gap: 8px; align-items: center; margin-top: 8px; }
.status form { display: inline; margin: 0; }
.status .del { border-color: #c62828 !important; color: #c62828 !important; }
.status .del:hover { background: #c62828 !important; color: #fff !important; }
.status button:disabled { opacity: .4; cursor: default; }
.status .svcst.ok { color: #1b5e20; } .status .svcst.warn { color: #8a4b00; } .status .svcst.fail { color: #c62828; }
.status .svcname { font-weight: 600; } .status .svcname.ok { color: #1b5e20; } .status .svcname.fail { color: #b71c1c; }
.status small.svcst { opacity: .8; }
.status td.btns .btnrow { display: flex; gap: 6px; margin: 2px 0; } .status td.btns .btnrow .stdbtn { flex: 1 1 0; }
.status table.stdtable td.btns { white-space: nowrap; }
/* a phone: the buttons wrap and a table still too wide scrolls inside its box, not the page (2026-10-23 test run) */
@media (max-width: 600px) { .status table.stdtable td.btns { white-space: normal; } .status table.stdtable { display: block; overflow-x: auto; max-width: 100%; } }
.status .doctor { max-width: none; padding: 0; }
.status h3 { margin: 18px 0 6px; }
</style>
<div class="status">
  <div class="box">
    <?php // owner 2026-10-24: "BirdSuite v0.13.0 + newer commits (main): <commit> - <date>", then the update waiting the same way ?>
    <div>BirdSuite <b><?php echo $h($installed !== '' ? $installed : t('str_unknown_lc')); ?></b><?php
      if ($ahead === null) echo ' + ' . t('str_newer_commits') . ' (' . $h($update_branch) . ')';
      elseif ($ahead > 0) echo ' + ' . ($ahead > 1 ? t('str_commits', array('n' => $ahead)) : t('str_commit', array('n' => $ahead))) . ' (' . $h($update_branch) . ')';
      // the commit and its date together, as on the line of the update below (owner 2026-10-24)
      if ($commit !== '') echo ': <b>' . $h($commit) . '</b>' . ($from_when !== '' ? ' - ' . $h($from_when) : ''); ?></div>
    <?php if ($behind !== '0' && $target !== '') { ?>
    <div style="margin-top:4px"><?php echo t('str_update_available'); ?> <b><?php echo $h($target); ?></b><?php echo $to_when !== '' ? ' - ' . $h($to_when) : ''; ?></div>
    <?php } ?>
    <?php if ($_SESSION['release_new'] !== '') { ?>
    <div style="margin-top:6px"><?php echo t('str_new_release_is_available_installed', array('v' => $h($_SESSION['release_new']), 'installed' => $h($installed ?: t('str_unknown_lc')))); ?>
      <?php if ($release_notes) { ?><a href="#" onclick="document.getElementById('whatsnew').showModal(); return false;"><?php echo t('str_whats_new'); ?></a><?php } ?></div>
    <dialog id="whatsnew" style="max-width:min(640px,92vw);max-height:80vh;overflow:auto;text-align:left;border-radius:10px">
      <?php foreach ($release_notes as $r) { ?>
      <h3 style="margin:0 0 6px"><?php echo $h($r['title']); ?></h3>
      <?php if ($r['body'] !== '') { ?><pre style="white-space:pre-wrap;font-family:inherit;margin:0 0 16px"><?php echo $h($r['body']); ?></pre><?php } else { ?><p style="margin:0 0 16px;color:#777"><?php echo t('str_no_notes_for_this_release'); ?></p><?php } ?>
      <?php } ?>
      <div style="text-align:right"><button type="button" class="stdbtn" onclick="this.closest('dialog').close()"><?php echo t('str_close'); ?></button></div>
    </dialog>
    <?php } ?>
    <form action="views.php" method="GET" class="actions">
      <button type="submit" class="stdbtn" name="submit" value="sudo -n /usr/local/sbin/birdsuite-root reboot" onclick="return bsAskSubmit(this, <?php echo $h(json_encode(t('str_are_you_sure_you_want_to_reboot_help'))); ?>, true)"><?php echo t('str_reboot'); ?></button>
      <button type="button" class="stdbtn" id="updatebtn" onclick="stUpdate();"<?php echo $up_to_date ? ' disabled title="' . $h(t('str_birdsuite_is_up_to_date')) . '"' : ''; ?>><?php echo $h($update_label); ?></button>
      <button type="submit" class="stdbtn del" name="submit" value="sudo -n /usr/local/sbin/birdsuite-root shutdown" onclick="return bsAskSubmit(this, <?php echo $h(json_encode(t('str_are_you_sure_you_want_to_help'))); ?>, true)"><?php echo t('str_shutdown'); ?></button>
    </form>
    <?php // from Settings › Advanced › Options (owner 2026-10-25), saved as soon as ticked ?>
    <?php $cf_up = get_config(); ?>
    <?php // the days (more than one) and the local time of the automatic update, shown once it is ticked (owner 2026-10-25)
    $up_days = array_filter(explode(',', trim($cf_up['AUTOMATIC_UPDATE_DAYS'] ?? '0', '"')), 'strlen');
    $up_dow = array(1 => t('str_mon'), 2 => t('str_tue'), 3 => t('str_wed'), 4 => t('str_thu'), 5 => t('str_fri'), 6 => t('str_sat'), 0 => t('str_sun')); ?>
    <div style="margin-top:8px"><label><input type="checkbox" id="autoup" data-setting="AUTOMATIC_UPDATE"<?php echo ($cf_up['AUTOMATIC_UPDATE'] ?? '0') == 1 ? ' checked' : ''; ?> onchange="document.getElementById('autoupwhen').hidden = !this.checked"> <?php echo t('str_automatic_update'); ?></label>
      <span id="autoupwhen"<?php echo ($cf_up['AUTOMATIC_UPDATE'] ?? '0') == 1 ? '' : ' hidden'; ?>>:
        <?php foreach ($up_dow as $n => $d) echo '<label style="margin-right:4px"><input type="checkbox" class="autoupday" value="' . $n . '"' . (in_array((string)$n, $up_days, true) ? ' checked' : '') . ' onchange="stUpDays()"> ' . $h($d) . '</label>'; ?>
        <input type="hidden" id="autoupdays" data-setting="AUTOMATIC_UPDATE_DAYS">
        <label><?php echo t('str_at'); ?> <input type="time" data-setting="AUTOMATIC_UPDATE_TIME" value="<?php echo $h(trim($cf_up['AUTOMATIC_UPDATE_TIME'] ?? '03:00', '"')); ?>"></label> <span class="small"><?php echo t('str_local_time'); ?></span></span><br>
      <label><input type="checkbox" data-setting="SILENCE_UPDATE_INDICATOR"<?php echo ($cf_up['SILENCE_UPDATE_INDICATOR'] ?? '0') == 1 ? ' checked' : ''; ?>> <?php echo t('str_no_update_badge_in_the_menu'); ?></label></div>
  </div>

  <?php // the migration from BirdNET-Pi on the same card (US-01, US-05): above Services while its record exists
  $mig = is_file(BS_MIGRATION) ? json_decode((string)@file_get_contents(BS_MIGRATION), true) : null;
  if (is_array($mig)) {
    $mig_days = intval(BS_MIGRATION_DAYS);
    $mig_start = intval($mig['started_at'] ?? time());
    $mig_left = max(0, $mig_days - intdiv(max(0, time() - $mig_start), 86400));
    $mig_copy = ($mig['mode'] ?? '') === 'copy';
    $mig_imp = (string)($mig['import'] ?? 'pending');
    $mig_file = basename(BS_UPSTREAM_ARCHIVE);
    $mig_size = function ($b) { $b = floatval($b); return $b >= 1e9 ? fmt_num($b / 1e9, 1) . ' GB' : fmt_num($b / 1e6, 0) . ' MB'; };
    $mig_total = array_sum(array_map(function ($k) { return floatval($k['bytes'] ?? 0); }, (array)($mig['kept'] ?? array()))); ?>
  <h3 id="migration"><?php echo t('str_migration_from_birdnet_pi'); ?></h3>
  <div class="box">
    <div><?php echo $mig_copy ? t('str_birdsuite_was_installed_over_copy_help', array('date' => $h($mig['started'] ?? '')))
      : t('str_birdsuite_was_installed_over_move_help', array('date' => $h($mig['started'] ?? ''), 'file' => '<code>' . $h($mig_file) . '</code>')); ?></div>
    <div style="margin-top:6px"><?php
      if ($mig_imp === 'done') echo t('str_birdnet_pi_detections_imported_on', array('n' => fmt_num(intval($mig['imported'] ?? 0)), 'date' => $h($mig['imported_on'] ?? '')));
      elseif ($mig_imp === 'running') echo t('str_the_import_of_the_birdnet_pi_help') . ' <a href="/?view=Backup%2FImport" target="_top">' . t('str_backup_import') . '</a>';
      elseif ($mig_imp === 'pending') echo t('str_the_birdnet_pi_detections_are_help');
      else echo '<span style="color:#c62828">' . t('str_the_import_of_the_birdnet_pi_stopped_help', array('error' => $h($mig['error'] ?? $mig_imp))) . '</span> <button type="button" class="stdbtn" onclick="migRun(\'import\')">' . t('str_import_now') . '</button>'; ?></div>
    <table class="stdtable" style="margin-top:8px">
      <tr><th><?php echo t('str_kept_from_birdnet_pi'); ?></th><th><?php echo t('str_size'); ?></th></tr>
      <?php foreach ((array)($mig['kept'] ?? array()) as $k) { ?>
      <tr><td><code><?php echo $h($k['path'] ?? ''); ?></code></td><td data-sort="<?php echo floatval($k['bytes'] ?? 0); ?>"><?php echo $mig_size($k['bytes'] ?? 0); ?></td></tr>
      <?php } ?>
    </table>
    <div style="margin-top:6px"><?php echo $mig_left > 0
      ? t('str_days_left_of_the_test_period', array('n' => $mig_left, 'days' => $mig_days, 'date' => date('Y-m-d', $mig_start + $mig_days * 86400)))
      : t('str_the_test_period_ended_on', array('date' => date('Y-m-d', $mig_start + $mig_days * 86400))); ?></div>
    <div class="actions">
      <button type="button" class="stdbtn del" onclick="migRun('remove')"<?php echo $mig_imp !== 'done' ? ' disabled title="' . $h(t('str_possible_once_the_birdnet_pi_help')) . '"' : ''; ?>><?php echo t('str_remove_the_birdnet_pi_leftovers'); ?></button>
      <button type="button" class="stdbtn" onclick="migRun('back')"<?php echo $mig_imp === 'running' ? ' disabled title="' . $h(t('str_wait_until_the_import_of_the_help')) . '"' : ''; ?>><?php echo t('str_go_back_to_birdnet_pi'); ?></button>
      <?php if (is_file(BS_UPSTREAM_ARCHIVE)) { ?><a class="stdbtn" href="scripts/status.php?migration=archive"><?php echo t('str_download_the_birdnet_pi_settings'); ?></a><?php } ?>
    </div>
  </div>
  <script>
  // Remove and Go back ask through the app's dialog first (Go back without the kept program only explains the way back)
  var migText = <?php echo json_encode(array('copy' => $mig_copy, 'size' => $mig_size($mig_total), 'file' => $mig_file), JSON_HEX_TAG); ?>;
  function migRun(act) {
    var ask;
    if (act === 'back' && !migText.copy) { bsNotice(t('str_birdnet_pi_cannot_be_put_back_help', {file: migText.file})); return; }
    if (act === 'remove') ask = bsAsk(t('str_remove_the_birdnet_pi_leftovers_help', {size: migText.size}), true);
    else if (act === 'back') ask = bsAsk(t('str_go_back_to_birdnet_pi_help'), true);
    else ask = Promise.resolve(true);
    ask.then(function (ok) {
      if (!ok) return;
      var x = new XMLHttpRequest();
      x.onload = function () {
        var r; try { r = JSON.parse(this.responseText); } catch (e) { r = {ok: false, msg: this.responseText}; }
        if (act === 'import' && r.ok) return location.reload();
        bsNotice(r.msg || '').then(function () { if (r.ok && act !== 'back') location.reload(); });
      };
      x.open('POST', 'scripts/status.php?migration=' + act, true); x.send();
    });
  }
  </script>
  <?php } ?>

  <h3><?php echo t('str_services'); ?></h3>
  <form action="views.php" method="GET">
  <table class="stdtable">
    <?php // narrower for the phone (owner 2026-10-25): the service in green (running) or red with its state under it; the
    // buttons in two lines, Stop / Restart and Disable / Enable ?>
    <tr><th><?php echo t('str_service'); ?></th><th data-nosort></th></tr>
    <?php foreach ($services as $s) { list($st, $txt) = $state($s[1]); ?>
    <tr><td><span class="svcname <?php echo $st === 'ok' ? 'ok' : 'fail'; ?>"><?php echo $h($s[0]); ?></span><br><small class="svcst"><?php echo $h($txt); ?></small></td><td class="btns">
      <?php foreach (array(array(2 => t('str_stop'), 3 => t('str_restart')), array(4 => t('str_disable'), 5 => t('str_enable'))) as $row) { ?><div class="btnrow">
      <?php // Stop, Restart and Disable ask first (owner 2026-11-01), Start / Enable need nothing
      $ask = array(2 => t('str_stop_name', array('name' => $s[0])), 3 => t('str_restart_name', array('name' => $s[0])), 4 => t('str_disable_it_does_not_start_with_help', array('name' => $s[0])));
      foreach ($row as $i => $lbl) { ?>
      <button type="button" class="stdbtn" data-cmd="<?php echo $h($s[$i]); ?>" data-what="<?php echo $h($lbl . ': ' . $s[0]); ?>"<?php echo isset($ask[$i]) ? ' data-ask="' . $h($ask[$i]) . '"' : ''; ?> onclick="svRun(this)"><?php echo $lbl; ?></button>
      <?php } ?></div><?php } ?></td></tr>
    <?php } list($st, $txt) = $state($mount); ?>
    <tr><td><span class="svcname <?php echo $st === 'ok' ? 'ok' : 'fail'; ?>"><?php echo t('str_ram_drive_experimental'); ?></span><br><small class="svcst"><?php echo $h($txt); ?></small></td><td class="btns"><div class="btnrow">
      <button type="submit" class="stdbtn" name="submit" value="<?php echo $h('echo off > ' . BS_RAMDISK_CHOICE . ' && sudo -n ' . BS_SVC_HELPER . ' disable ' . $mount . ' && sudo -n ' . BS_ROOT_HELPER . ' reboot'); ?>" onclick="return bsAskSubmit(this, <?php echo $h(json_encode(t('str_this_will_reboot_are_you_sure'))); ?>, true)"><?php echo t('str_disable'); ?></button>
      <button type="submit" class="stdbtn" name="submit" value="<?php echo $h('echo on > ' . BS_RAMDISK_CHOICE . ' && sudo -n ' . BS_SVC_HELPER . ' enable ' . $mount . ' && sudo -n ' . BS_ROOT_HELPER . ' reboot'); ?>" onclick="return bsAskSubmit(this, <?php echo $h(json_encode(t('str_this_will_reboot_are_you_sure'))); ?>, true)"><?php echo t('str_enable'); ?></button></div></td></tr>
  </table>
  <?php // what the RAM drive does, folded (owner 2026-10-24: as "Where do I find the name and password?" on Network) ?>
  <details class="small" style="margin-top:6px"><summary><?php echo t('str_advantages_and_disadvantages_of_help'); ?></summary>
    <?php echo t('str_it_keeps_the_recordings_waiting_help', array('dir' => BS_STREAMDATA)); ?><br>
    <b><?php echo t('str_advantage'); ?></b> <?php echo t('str_about_16_gb_a_day_less_written_help'); ?><br>
    <b><?php echo t('str_disadvantages'); ?></b> <?php echo t('str_if_the_analysis_stops_the_help'); ?><br>
    <?php echo t('str_worth_it_with_an_sd_card_and_4_help'); ?>
  </details>
  <div class="actions">
    <button type="button" class="stdbtn" data-cmd="stop_core_services.sh" data-what="<?php echo $h(t('str_stop_core_services')); ?>" data-ask="<?php echo $h(t('str_stop_the_core_services_help')); ?>" onclick="svRun(this)"><?php echo t('str_stop_core_services'); ?></button>
    <button type="button" class="stdbtn" data-cmd="restart_services.sh" data-what="<?php echo $h(t('str_restart_core_services')); ?>" data-ask="<?php echo $h(t('str_restart_the_core_services_help')); ?>" onclick="svRun(this)"><?php echo t('str_restart_core_services'); ?></button>
  </div>
  </form>

  <?php // the Doctor checks after the services (owner 2026-10-21: services second) ?>
  <h3><?php echo t('str_checks'); ?></h3>
  <?php include __DIR__ . '/doctor.php'; ?>

  <p style="margin-top:18px"><a href="views.php?view=Hardware%20details"><?php echo t('str_hardware_details'); ?></a></p>
</div>
<script>
// the newest commit, fetched in the background when the last fetch is older than 10 minutes; a reload when the update
// state changed
<?php if (!$fetched) { ?>(function () {
  var shown = <?php echo json_encode(array('behind' => $behind, 'latest' => $latest)); ?>;
  var x = new XMLHttpRequest();
  x.onload = function () {
    var r; try { r = JSON.parse(this.responseText); } catch (e) { return; }
    if (r.behind !== shown.behind || r.latest !== shown.latest) location.reload();
  };
  x.open('GET', 'scripts/status.php?check=1', true); x.send();
})();<?php } ?>
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
</script>
<script>
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
</script>
<div id="svov" style="display:none;position:fixed;inset:0;z-index:2000;background:rgba(0,0,0,.65);align-items:center;justify-content:center">
  <div style="background:var(--panel,#fff);color:var(--text,#000);border-radius:12px;padding:16px;width:min(760px,calc(100% - 24px));max-height:calc(100% - 24px);display:flex;flex-direction:column;gap:8px">
    <b style="font-size:16px" id="svtitle"></b>
    <div id="svstate"></div>
    <pre id="svlog" style="flex:1;min-height:160px;max-height:60vh;overflow:auto;background:#111;color:#ddd;border-radius:8px;padding:8px;font-size:12px;white-space:pre-wrap;margin:0"></pre>
    <div style="text-align:right"><button type="button" class="stdbtn" id="svclose" style="display:none" onclick="location.reload()"><?php echo t('str_close'); ?></button></div>
  </div>
</div>
<div id="upov" style="display:none;position:fixed;inset:0;z-index:2000;background:rgba(0,0,0,.65);align-items:center;justify-content:center">
  <div style="background:var(--panel,#fff);color:var(--text,#000);border-radius:12px;padding:16px;width:min(760px,calc(100% - 24px));max-height:calc(100% - 24px);display:flex;flex-direction:column;gap:8px">
    <b style="font-size:16px"><?php echo t('str_updating_birdsuite'); ?> <span id="uptime" style="font-weight:normal">00:00</span></b>
    <div><?php echo t('str_from_to', array('from' => '<b>' . $h($up_from) . '</b>', 'to' => '<b>' . $h($up_to) . '</b>')); ?></div>
    <div id="upstate"><?php echo t('str_starting_the_update'); ?></div>
    <pre id="uplog" style="flex:1;min-height:200px;max-height:60vh;overflow:auto;background:#111;color:#ddd;border-radius:8px;padding:8px;font-size:12px;white-space:pre-wrap;margin:0"></pre>
    <div style="text-align:right"><button type="button" class="stdbtn" id="upclose" style="display:none" onclick="(window.top || window).location.reload()"><?php echo t('str_reload'); ?></button></div>
  </div>
</div>
