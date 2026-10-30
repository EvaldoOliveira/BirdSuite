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
$git = station_prefix() . "git -C " . $home . "/BirdNET-Pi ";
// how far the installation is behind its update branch, from the last fetch: FETCH_HEAD, as update_birdnet.sh compares
// (the update branch can differ from the checked-out one: a station installed from stable updates from main)
function status_behind($git) {
  $n = trim((string)shell_exec($git . "rev-list --count HEAD..FETCH_HEAD 2>/dev/null"));
  if (ctype_digit($n)) return $n;
  $str = trim((string)shell_exec($git . "status"));
  if (preg_match('/\b(\d+)\b and \b(\d+)\b different commits each/', $str, $m)) return (string)((int)$m[1] + (int)$m[2]);
  return preg_match("/behind '.*?' by (\d+) commit(s?)\b/", $str, $m) ? $m[1] : '0';
}
// the newest release tag fetched
function status_latest_tag($git) {
  $latest = '';
  foreach (explode("\n", (string)shell_exec($git . "tag -l 'v*' 2>/dev/null")) as $tg) {
    if (preg_match('~^v\d+\.\d+\.\d+$~', trim($tg)) && ($latest === '' || version_compare(substr(trim($tg), 1), substr($latest, 1), '>'))) $latest = trim($tg);
  }
  return $latest;
}
// every visit checks the newest commit (owner 2026-10-24: the update showed only after a reload): the page opens at
// once with the state of the last fetch and asks ?check=1, which fetches now (time limit: an offline station must not
// hang, 2026-10-23 test run) and answers the new state; the page reloads itself when it changed
$update_branch = get_config()['UPDATE_BRANCH'] ?? 'stable';
// the services: label, unit, and the four commands of the former Services page (the allow list of views.php)
$svc = function ($label, $unit, $extra_stop = '', $extra_start = '') {
  return array($label, $unit,
    "sudo -n /usr/local/sbin/birdsuite-svc stop $unit" . $extra_stop, "sudo -n /usr/local/sbin/birdsuite-svc restart $unit" . $extra_start,
    "sudo -n /usr/local/sbin/birdsuite-svc disable-now $unit", "sudo -n /usr/local/sbin/birdsuite-svc enable-now $unit");
};
$services = array(
  array(t('Live Audio Stream'), 'livestream.service',
    'sudo -n /usr/local/sbin/birdsuite-svc stop livestream.service && sudo -n /usr/local/sbin/birdsuite-svc stop icecast2.service',
    'sudo -n /usr/local/sbin/birdsuite-svc restart livestream.service && sudo -n /usr/local/sbin/birdsuite-svc restart icecast2.service',
    'sudo -n /usr/local/sbin/birdsuite-svc disable-now livestream.service && sudo -n /usr/local/sbin/birdsuite-svc disable icecast2 && sudo -n /usr/local/sbin/birdsuite-svc stop icecast2.service',
    'sudo -n /usr/local/sbin/birdsuite-svc enable icecast2 && sudo -n /usr/local/sbin/birdsuite-svc start icecast2.service && sudo -n /usr/local/sbin/birdsuite-svc enable-now livestream.service'),
  $svc(t('Recording Service'), 'birdnet_recording.service'),
  $svc(t('Analysis Service'), 'birdnet_analysis.service'),
  $svc(t('Analysis Log'), 'birdnet_log.service'),
  $svc(t('Chart Viewer'), 'chart_viewer.service'),
  $svc(t('Spectrogram Viewer'), 'spectrogram_viewer.service'),
  $svc(t('Console Login'), 'web_terminal.service'),
);
// a service button on a screen of its own, as the update (owner 2026-10-24): ?svc=start (POST, cmd = one of the
// commands above) runs it detached (Restart Core Services restarts the web server too), then shows the state of its
// units; ?svc=log&from=N answers as ?update=log
if (isset($_GET['svc'])) {
  ensure_authenticated(t('You must be authenticated to change the services.'));
  header('Content-Type: application/json');
  $dir = $home . '/BirdNET-Pi/state';
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
    if (!$units) $units = array('birdnet_recording.service', 'birdnet_analysis.service', 'birdnet_log.service', 'chart_viewer.service', 'spectrogram_viewer.service');
    $show = 'echo; for u in ' . implode(' ', array_map('escapeshellarg', $units)) . '; do sudo -n /usr/local/sbin/birdsuite-svc status "$u" 2>&1 | head -n 4; echo; done';
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
  ensure_authenticated(t('You must be authenticated to update.'));
  header('Content-Type: application/json');
  $dir = $home . '/BirdNET-Pi/state';
  @mkdir($dir, 0775, true);
  $log = $dir . '/web_update.log';
  $mark = '__BIRDSUITE_UPDATE_EXIT__';
  if ($_GET['update'] === 'start' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $txt = (string)@file_get_contents($log);
    $running = $txt !== '' && strpos($txt, $mark) === false && filemtime($log) > time() - 1800;
    if (!$running) {
      file_put_contents($log, '');
      session_unset();  // the settings are read again after the update (as the former Update did)
      shell_exec('setsid -f sh -c ' . escapeshellarg('sudo -n /usr/local/sbin/birdsuite-root update; echo "' . $mark . ' $?"')
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
if (isset($_GET['check'])) {
  shell_exec("timeout 25 " . $git . "fetch --tags --force origin " . escapeshellarg($update_branch) . " > /dev/null 2>&1");
  header('Content-Type: application/json');
  echo json_encode(array('behind' => status_behind($git), 'latest' => status_latest_tag($git)));
  die();
}
// the newest commit before the page is drawn (owner 2026-10-24: no reload to see an update): a short fetch, usually
// 1-2 s; an offline or slow station gives up after 6 s and the page asks again in the background (?check=1)
$fetched = trim((string)shell_exec("timeout 6 " . $git . "fetch --tags --force origin " . escapeshellarg($update_branch) . " > /dev/null 2>&1 && echo ok")) === 'ok';
$behind = status_behind($git);
$_SESSION['behind'] = $behind;
$_SESSION['behind_time'] = time();
list($installed, $ahead) = installed_release();  // a shallow clone gives the release but not the commit count ($ahead null)
$latest = status_latest_tag($git);
$_SESSION['release_installed'] = $installed;
$_SESSION['release_new'] = ($latest !== '' && $installed !== '' && version_compare(substr($latest, 1), substr($installed, 1), '>')) ? $latest : '';
$update_label = $update_branch === 'stable'
  ? ($_SESSION['release_new'] !== '' ? t('Update to {v}', array('v' => $_SESSION['release_new'])) : t('Update'))
  : t('Update to the latest {branch}', array('branch' => $update_branch));
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
$commit = trim((string)shell_exec($git . "rev-parse --short HEAD 2>/dev/null"));
// what the update installs (owner 2026-10-24: "from → to" on the update screen): the fetched head of the update branch,
// with the release it is when on stable
$target = trim((string)shell_exec($git . "rev-parse --short FETCH_HEAD 2>/dev/null"));
// with the date and time of each commit (owner 2026-10-24), in the station's time, as the clock is shown elsewhere
$commit_when = function ($ref) use ($git) { return trim((string)shell_exec($git . "log -1 --format=%cd --date=format-local:'%Y-%m-%d %H:%M' " . escapeshellarg($ref) . " 2>/dev/null")); };
$from_when = $commit_when('HEAD');
$to_when = $target !== '' ? $commit_when('FETCH_HEAD') : '';
$up_from = trim(($installed ?: '') . ' (' . $commit . ($from_when !== '' ? ', ' . $from_when : '') . ')');
$up_to = trim(($update_branch === 'stable' && $_SESSION['release_new'] !== '' ? $_SESSION['release_new'] : $update_branch)
  . ($target !== '' ? ' (' . $target . ($to_when !== '' ? ', ' . $to_when : '') . ')' : ''));

$state = function ($unit) use ($home) {
  if ($unit === 'birdnet_analysis.service') {
    $n = intval(trim((string)shell_exec("ls " . escapeshellarg($home . '/BirdSongs/StreamData') . " | wc -l")));
    if ($n > 200) return array('warn', t('stalled - backlog of {n} files in ~/BirdSongs/StreamData/', array('n' => $n)));
  }
  $op = (string)shell_exec("sudo -n /usr/local/sbin/birdsuite-svc status " . escapeshellarg($unit) . " | grep Active");
  if (stripos($op, " active (running)") || stripos($op, " active (mounted)")) return array('ok', t('active'));
  if (stripos($op, " inactive ")) return array('warn', t('inactive'));
  return array('fail', preg_match("/(\S*)\s*\((\S+)\)/", $op, $m) ? $m[1] . ' [' . $m[2] . ']' : 'ERROR');
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
.status .svcname { font-weight: 600; } .status .svcname.ok { color: #1b5e20; } .status .svcname.fail { color: #c62828; }
.status small.svcst { opacity: .8; }
.status td.btns .btnrow { display: flex; gap: 6px; margin: 2px 0; } .status td.btns .btnrow .stdbtn { flex: 1 1 0; }
.status table.stdtable td.btns { white-space: nowrap; }
/* a phone: the buttons wrap and a table still too wide scrolls inside its box, not the page (2026-10-23 test run) */
@media (max-width: 600px) { .status table.stdtable td.btns { white-space: normal; } .status table.stdtable { display: block; overflow-x: auto; max-width: 100%; } }
.status .doctor { max-width: none; padding: 0; }
.status h3 { margin: 18px 0 6px; }
</style>
<div class="status">
  <?php names_migration_notice(); // owner 2026-10-24: clips in the BirdNET (old) format link to the migration ?>
  <div class="box">
    <?php // owner 2026-10-24: "BirdSuite v0.13.0 + newer commits (main): <commit> - <date>", then the update waiting the same way ?>
    <div>BirdSuite <b><?php echo $h($installed !== '' ? $installed : t('unknown')); ?></b><?php
      if ($ahead === null) echo ' + ' . t('newer commits') . ' (' . $h($update_branch) . ')';
      elseif ($ahead > 0) echo ' + ' . ($ahead > 1 ? t('{n} commits', array('n' => $ahead)) : t('{n} commit', array('n' => $ahead))) . ' (' . $h($update_branch) . ')';
      // the commit and its date together, as on the line of the update below (owner 2026-10-24)
      if ($commit !== '') echo ': <b>' . $h($commit) . '</b>' . ($from_when !== '' ? ' - ' . $h($from_when) : ''); ?></div>
    <?php if ($behind !== '0' && $target !== '') { ?>
    <div style="margin-top:4px"><?php echo t('Update available:'); ?> <b><?php echo $h($target); ?></b><?php echo $to_when !== '' ? ' - ' . $h($to_when) : ''; ?></div>
    <?php } ?>
    <?php if ($_SESSION['release_new'] !== '') { ?>
    <div style="margin-top:6px"><?php echo t('<b>New release {v}</b> is available (installed: {installed}).', array('v' => $h($_SESSION['release_new']), 'installed' => $h($installed ?: t('unknown')))); ?>
      <?php if ($release_notes) { ?><a href="#" onclick="document.getElementById('whatsnew').showModal(); return false;"><?php echo t('What\'s new'); ?></a><?php } ?></div>
    <dialog id="whatsnew" style="max-width:min(640px,92vw);max-height:80vh;overflow:auto;text-align:left;border-radius:10px">
      <?php foreach ($release_notes as $r) { ?>
      <h3 style="margin:0 0 6px"><?php echo $h($r['title']); ?></h3>
      <?php if ($r['body'] !== '') { ?><pre style="white-space:pre-wrap;font-family:inherit;margin:0 0 16px"><?php echo $h($r['body']); ?></pre><?php } else { ?><p style="margin:0 0 16px;color:#777"><?php echo t('No notes for this release.'); ?></p><?php } ?>
      <?php } ?>
      <div style="text-align:right"><button type="button" class="stdbtn" onclick="this.closest('dialog').close()"><?php echo t('Close'); ?></button></div>
    </dialog>
    <?php } ?>
    <form action="views.php" method="GET" class="actions">
      <button type="submit" class="stdbtn" name="submit" value="sudo -n /usr/local/sbin/birdsuite-root reboot" onclick="return confirm(<?php echo $h(json_encode(t('Are you sure you want to reboot?'))); ?>)"><?php echo t('Reboot'); ?></button>
      <button type="button" class="stdbtn" id="updatebtn" onclick="stUpdate();"<?php echo $up_to_date ? ' disabled title="' . $h(t('BirdSuite is up to date')) . '"' : ''; ?>><?php echo $h($update_label); ?></button>
      <button type="submit" class="stdbtn del" name="submit" value="sudo -n /usr/local/sbin/birdsuite-root shutdown" onclick="return confirm(<?php echo $h(json_encode(t('Are you sure you want to shutdown?'))); ?>)"><?php echo t('Shutdown'); ?></button>
    </form>
    <?php // from Settings › Advanced › Options (owner 2026-10-25), saved as soon as ticked ?>
    <?php $cf_up = get_config(); ?>
    <?php // the days (more than one) and the local time of the automatic update, shown once it is ticked (owner 2026-10-25)
    $up_days = array_filter(explode(',', trim($cf_up['AUTOMATIC_UPDATE_DAYS'] ?? '0', '"')), 'strlen');
    $up_dow = array(1 => t('Mon'), 2 => t('Tue'), 3 => t('Wed'), 4 => t('Thu'), 5 => t('Fri'), 6 => t('Sat'), 0 => t('Sun')); ?>
    <div style="margin-top:8px"><label><input type="checkbox" id="autoup" data-setting="AUTOMATIC_UPDATE"<?php echo ($cf_up['AUTOMATIC_UPDATE'] ?? '0') == 1 ? ' checked' : ''; ?> onchange="document.getElementById('autoupwhen').hidden = !this.checked"> <?php echo t('Automatic update'); ?></label>
      <span id="autoupwhen"<?php echo ($cf_up['AUTOMATIC_UPDATE'] ?? '0') == 1 ? '' : ' hidden'; ?>>:
        <?php foreach ($up_dow as $n => $d) echo '<label style="margin-right:4px"><input type="checkbox" class="autoupday" value="' . $n . '"' . (in_array((string)$n, $up_days, true) ? ' checked' : '') . ' onchange="stUpDays()"> ' . $h($d) . '</label>'; ?>
        <input type="hidden" id="autoupdays" data-setting="AUTOMATIC_UPDATE_DAYS">
        <label><?php echo t('at'); ?> <input type="time" data-setting="AUTOMATIC_UPDATE_TIME" value="<?php echo $h(trim($cf_up['AUTOMATIC_UPDATE_TIME'] ?? '03:00', '"')); ?>"></label> <span class="small"><?php echo t('(local time)'); ?></span></span><br>
      <label><input type="checkbox" data-setting="SILENCE_UPDATE_INDICATOR"<?php echo ($cf_up['SILENCE_UPDATE_INDICATOR'] ?? '0') == 1 ? ' checked' : ''; ?>> <?php echo t('No update badge in the menu'); ?></label></div>
  </div>

  <h3><?php echo t('Services'); ?></h3>
  <form action="views.php" method="GET">
  <table class="stdtable">
    <?php // narrower for the phone (owner 2026-10-25): the service in green (running) or red with its state under it; the
    // buttons in two lines, Stop / Restart and Disable / Enable ?>
    <tr><th><?php echo t('Service'); ?></th><th data-nosort></th></tr>
    <?php foreach ($services as $s) { list($st, $txt) = $state($s[1]); ?>
    <tr><td><span class="svcname <?php echo $st === 'ok' ? 'ok' : 'fail'; ?>"><?php echo $h($s[0]); ?></span><br><small class="svcst"><?php echo $h($txt); ?></small></td><td class="btns">
      <?php foreach (array(array(2 => t('Stop'), 3 => t('Restart')), array(4 => t('Disable'), 5 => t('Enable'))) as $row) { ?><div class="btnrow">
      <?php foreach ($row as $i => $lbl) { ?>
      <button type="button" class="stdbtn" data-cmd="<?php echo $h($s[$i]); ?>" data-what="<?php echo $h($lbl . ': ' . $s[0]); ?>" onclick="svRun(this)"><?php echo $lbl; ?></button>
      <?php } ?></div><?php } ?></td></tr>
    <?php } list($st, $txt) = $state($mount); ?>
    <tr><td><span class="svcname <?php echo $st === 'ok' ? 'ok' : 'fail'; ?>"><?php echo t('Ram drive (!experimental!)'); ?></span><br><small class="svcst"><?php echo $h($txt); ?></small></td><td class="btns"><div class="btnrow">
      <button type="submit" class="stdbtn" name="submit" value="<?php echo $h('sudo -n /usr/local/sbin/birdsuite-svc disable ' . $mount . ' && sudo -n /usr/local/sbin/birdsuite-root reboot'); ?>" onclick="return confirm(<?php echo $h(json_encode(t('This will reboot, are you sure?'))); ?>)"><?php echo t('Disable'); ?></button>
      <button type="submit" class="stdbtn" name="submit" value="<?php echo $h('sudo -n /usr/local/sbin/birdsuite-svc enable ' . $mount . ' && sudo -n /usr/local/sbin/birdsuite-root reboot'); ?>" onclick="return confirm(<?php echo $h(json_encode(t('This will reboot, are you sure?'))); ?>)"><?php echo t('Enable'); ?></button></div></td></tr>
  </table>
  <?php // what the RAM drive does, folded (owner 2026-10-24: as "Where do I find the name and password?" on Network) ?>
  <details class="small" style="margin-top:6px"><summary><?php echo t('Advantages and disadvantages of the RAM drive (experimental!)'); ?></summary>
    <?php echo t('It keeps the recordings waiting for the analysis (BirdSongs/StreamData, 15-second files of about 2.9 MB) in RAM instead of on the card or SSD. Turning it on or off restarts the station.'); ?><br>
    <b><?php echo t('Advantage:'); ?></b> <?php echo t('about 16 GB a day less written to the disk, the main wear of an SD card on a station that runs day and night.'); ?><br>
    <b><?php echo t('Disadvantages:'); ?></b> <?php echo t('if the analysis stops, the recordings pile up in RAM (about 700 MB an hour) and can fill it in an hour or two on a 2 GB Pi; a power cut loses the last seconds not yet analysed (the detections already saved stay).'); ?><br>
    <?php echo t('Worth it with an SD card and 4 GB of RAM or more; with an SSD the gain is small.'); ?>
  </details>
  <div class="actions">
    <button type="button" class="stdbtn" data-cmd="stop_core_services.sh" data-what="<?php echo $h(t('Stop Core Services')); ?>" onclick="svRun(this)"><?php echo t('Stop Core Services'); ?></button>
    <button type="button" class="stdbtn" data-cmd="restart_services.sh" data-what="<?php echo $h(t('Restart Core Services')); ?>" onclick="svRun(this)"><?php echo t('Restart Core Services'); ?></button>
  </div>
  </form>

  <?php // the Doctor checks after the services (owner 2026-10-21: services second) ?>
  <h3><?php echo t('Checks'); ?></h3>
  <?php $doctor_embed = true; include __DIR__ . '/doctor.php'; ?>

  <p style="margin-top:18px"><a href="views.php?view=System%20Info"><?php echo t('Hardware details'); ?></a></p>
</div>
<script>
// the newest commit, checked on every visit; a reload when the update state changed
// only when the fetch before the page did not finish (offline, slow)
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
function stUpdate() {
  if (!confirm(t('Are you sure you want to update?'))) return;
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
    document.getElementById('upstate').textContent = t('Updating, do not close this page…');
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
  document.getElementById('upstate').textContent = t('The station is restarting its web server, waiting…');
  setTimeout(stPoll, 3000);
}
function stDone(code) {
  stUp.done = true;
  var ok = code === 0;
  var st = document.getElementById('upstate');
  st.textContent = ok ? t('Update finished. Reloading…') : t('The update failed (code {code}). See the messages above.', {code: code});
  st.style.color = ok ? '' : '#c62828';
  if (ok) setTimeout(function () { (window.top || window).location.reload(); }, 2500);
  else document.getElementById('upclose').style.display = '';
}
</script>
<script>
// a service button on a screen of its own (owner 2026-10-24, as the update): the terminal output, no way out until it
// ends, then Close reloads Status with the new states
var sv = {from: 0, done: false};
function svRun(btn) {
  sv.from = 0; sv.done = false;
  document.getElementById('svtitle').textContent = btn.dataset.what;
  document.getElementById('svlog').textContent = '';
  document.getElementById('svstate').textContent = t('Running, do not close this page…');
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
    document.getElementById('svstate').textContent = r.busy ? t('Another service command is still running. Try again in a moment.') : t('This command is not allowed.');
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
    st.textContent = r.code === 0 ? t('Done.') : t('Finished with an error (code {code}). See the messages above.', {code: r.code});
    st.style.color = r.code === 0 ? '' : '#c62828';
    document.getElementById('svclose').style.display = '';
  };
  // Restart Core Services restarts the web server: keep asking
  x.onerror = x.ontimeout = function () { document.getElementById('svstate').textContent = t('The station is restarting its web server, waiting…'); setTimeout(svPoll, 2000); };
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
    <div style="text-align:right"><button type="button" class="stdbtn" id="svclose" style="display:none" onclick="location.reload()"><?php echo t('Close'); ?></button></div>
  </div>
</div>
<div id="upov" style="display:none;position:fixed;inset:0;z-index:2000;background:rgba(0,0,0,.65);align-items:center;justify-content:center">
  <div style="background:var(--panel,#fff);color:var(--text,#000);border-radius:12px;padding:16px;width:min(760px,calc(100% - 24px));max-height:calc(100% - 24px);display:flex;flex-direction:column;gap:8px">
    <b style="font-size:16px"><?php echo t('Updating BirdSuite'); ?> <span id="uptime" style="font-weight:normal">00:00</span></b>
    <div><?php echo t('From {from} to {to}', array('from' => '<b>' . $h($up_from) . '</b>', 'to' => '<b>' . $h($up_to) . '</b>')); ?></div>
    <div id="upstate"><?php echo t('Starting the update…'); ?></div>
    <pre id="uplog" style="flex:1;min-height:200px;max-height:60vh;overflow:auto;background:#111;color:#ddd;border-radius:8px;padding:8px;font-size:12px;white-space:pre-wrap;margin:0"></pre>
    <div style="text-align:right"><button type="button" class="stdbtn" id="upclose" style="display:none" onclick="(window.top || window).location.reload()"><?php echo t('Reload'); ?></button></div>
  </div>
</div>
