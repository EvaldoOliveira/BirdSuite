<?php
/* Station Doctor (owner 2026-10-08): one page that says whether the station is healthy and, when it is not,
 * what is wrong and the one-click fix. Checks: services, microphone, recording and analysis backlog, latest
 * detection, disk and purge protection, quarantined recordings, model files, the location (region) profile,
 * raw recording, sound-repository spool, version and new release, clock, power and temperature, database; since the
 * 2026-10-23 test run also the recording watchdog's and systemd's restarts, detections stopped in daylight while the
 * services run, the offline notification queue, the web password, world-writable files and the last backup.
 * views.php?view=Doctor = the page; scripts/doctor.php?format=json = the same checks as JSON (for monitoring,
 * e.g. the Mesh menu): allowed from the station itself or an authenticated session. */
require_once __DIR__ . '/common.php';
if (session_status() === PHP_SESSION_NONE) @session_start();
set_timezone();
$json = (($_GET['format'] ?? '') === 'json');
if ($json && !in_array($_SERVER['REMOTE_ADDR'] ?? '', array('127.0.0.1', '::1'), true)) {
  ensure_authenticated(t('You must be authenticated to read the station health.'));
}
$home = get_home();
$user = get_user();
$config = get_config();
$checks = array();
function check(&$checks, $group, $name, $status, $detail, $fix = null) {
  $checks[] = array('group' => $group, 'name' => $name, 'status' => $status, 'detail' => $detail, 'fix' => $fix);
}
$sh = function ($cmd) { return trim((string)shell_exec($cmd . ' 2>/dev/null')); };
$age = function ($seconds) {
  if ($seconds < 120) return $seconds . ' s';
  if ($seconds < 7200) return round($seconds / 60) . ' min';
  if ($seconds < 172800) return round($seconds / 3600) . ' h';
  return t('{n} days', array('n' => round($seconds / 86400)));
};

// services: enabled but not running = failure, with a restart button (the commands views.php allows)
$svc_state = array();
$fixable = array('birdnet_analysis', 'birdnet_recording', 'birdnet_log', 'chart_viewer', 'spectrogram_viewer', 'web_terminal');
foreach (array('birdnet_recording' => 'Recording', 'birdnet_analysis' => 'Analysis', 'chart_viewer' => 'Daily chart',
               'spectrogram_viewer' => 'Spectrogram', 'birdnet_log' => 'Log viewer', 
               'livestream' => 'Live audio', 'icecast2' => 'Live audio server', 'web_terminal' => 'Web terminal',
               'caddy' => 'Web server', 'php8.4-fpm' => 'PHP') as $svc => $label) {
  $active = $sh("systemctl is-active $svc.service");
  $svc_state[$svc] = $active;
  $enabled = $sh("systemctl is-enabled $svc.service");
  if ($active === 'active') {
    check($checks, 'Services', $label, 'ok', t('{svc} running', array('svc' => $svc)));
  } elseif ($enabled === 'enabled') {
    $fix = $svc === 'livestream' || $svc === 'icecast2'
      ? array(t('Restart live audio'), 'sudo -n /usr/local/sbin/birdsuite-svc restart livestream.service && sudo -n /usr/local/sbin/birdsuite-svc restart icecast2.service')
      : (in_array($svc, $fixable, true) ? array(t('Restart'), "sudo -n /usr/local/sbin/birdsuite-svc restart $svc.service") : null);
    check($checks, 'Services', $label, 'fail', t('{svc} is {state} although enabled', array('svc' => $svc, 'state' => $active)), $fix);
  } else {
    check($checks, 'Services', $label, 'info', t('{svc} disabled', array('svc' => $svc)));
  }
}

// microphone
$cards = (string)@file_get_contents('/proc/asound/cards');
$usb = preg_match_all('/USB-Audio|USB Audio/i', $cards);
$rec_card = trim($config['REC_CARD'] ?? '', '"');
if ($usb > 0) check($checks, 'Audio', 'Microphone', 'ok', t('{n} USB sound card(s) present; recording device {device}', array('n' => $usb, 'device' => ($rec_card ?: t('default')))));
else check($checks, 'Audio', 'Microphone', 'fail', t('no USB sound card, microphone unplugged? (the station stops until it is back)'));

// recording and analysis backlog
$recs = rtrim($config['RECS_DIR'] ?? ($home . '/BirdSongs'), '/');
$stream = glob($recs . '/StreamData/*.wav') ?: array();
$len = max(1, intval($config['RECORDING_LENGTH'] ?? 15));
if ($stream) {
  $newest = max(array_map('filemtime', $stream));
  $lag = time() - $newest;
  check($checks, 'Audio', 'Recording', $lag <= 3 * $len + 30 ? 'ok' : 'fail', t('newest chunk {age} old', array('age' => $age($lag))));
  $n = count($stream);
  check($checks, 'Audio', 'Analysis backlog', $n <= 10 ? 'ok' : ($n <= 40 ? 'warn' : 'fail'),
        ($n > 10 ? t('{n} chunks waiting ({age} of audio), the analysis is not keeping up', array('n' => $n, 'age' => $age($n * $len)))
                  : t('{n} chunks waiting ({age} of audio)', array('n' => $n, 'age' => $age($n * $len)))),
        $n > 10 ? array(t('Restart analysis'), 'sudo -n /usr/local/sbin/birdsuite-svc restart birdnet_analysis.service') : null);
} else {
  check($checks, 'Audio', 'Recording', 'fail', t('no chunks in StreamData, the recording is not running'),
        array(t('Restart recording'), 'sudo -n /usr/local/sbin/birdsuite-svc restart birdnet_recording.service'));
}

// the recording watchdog (scripts/recording_watchdog.py): its restarts of the last 24 h (2026-10-23 test run)
$wd = json_decode((string)@file_get_contents(BS_STATE . '/recording_watchdog.json'), true);
if (is_array($wd)) {
  $hist = array_filter($wd['history'] ?? array(floatval($wd['last_restart'] ?? 0)), function ($ts) { return time() - $ts < 86400; });
  // one or two restarts a day cost a few minutes of audio (owner 2026-10-25): a warning from three on
  check($checks, 'Audio', 'Recording watchdog', count($hist) >= 3 ? 'warn' : 'ok', $hist
        ? t('{n} restart(s) of the recording in the last 24 h because of empty clips, the last at {time}', array('n' => count($hist), 'time' => date('H:i', intval(max($hist)))))
        : t('no restart of the recording in the last 24 h'));
}
// the privacy filter (owner 2026-10-25): what it blanked in 24 h, and whether it can work with the model in use (BirdNET+
// V3 has no human class: only through a shadow model V2.4)
$pt = floatval($config['PRIVACY_THRESHOLD'] ?? 1);
$v3 = strpos($config['MODEL'] ?? '', 'BirdNET-Plus') === 0;
$shadow_ok = (string)($config['SHADOW_ENABLED'] ?? '0') !== '0' && ($config['SHADOW_MODEL_NAME'] ?? '') !== '' && strpos($config['SHADOW_MODEL_NAME'], 'BirdNET-Plus') !== 0;
$pv = json_decode((string)@file_get_contents(BS_STATE . '/privacy.json'), true);
$pm = $ps = $pd = 0;
foreach ((is_array($pv) ? ($pv['hours'] ?? array()) : array()) as $hk => $hv) {
  if (time() - intval($hk) < 86400) { $pm += intval($hv['model'] ?? 0); $ps += intval($hv['shadow'] ?? 0); $pd += intval($hv['dropped'] ?? 0); }
}
$menu_model = t('Settings') . ' › ' . t('Model');
if ($v3 && !$shadow_ok) {
  check($checks, 'Audio', 'Privacy filter', $pt > 0 ? 'warn' : 'ok', t('not active: BirdNET+ V3 has no human voice class; turn on Shadow mode with BirdNET V2.4 in {menu} to drop human voices', array('menu' => $menu_model)),
        array($menu_model, 'Model', 'view'));
} elseif ($pt <= 0 && $v3) {
  check($checks, 'Audio', 'Privacy filter', 'ok', t('off (threshold 0)'));
} else {
  check($checks, 'Audio', 'Privacy filter', 'ok', ($v3 ? t('through the shadow model') . ': ' : '') . t('{n} moment(s) with a human voice dropped in the last 24 h', array('n' => $pm + $ps))
        . ($pd ? ' (' . t('{n} detection(s) removed', array('n' => $pd)) . ')' : ''));
}
// automatic restarts by systemd since the service was last started (a crash loop, e.g. the analysis during the
// microphone lock-up of 2026-10-23, restarted 98 times in a day without any alert)
$nr = array();
foreach (array('birdnet_analysis' => 'Analysis', 'birdnet_recording' => 'Recording') as $svc => $label) {
  $nr[$svc] = array(t($label), intval($sh("systemctl show -p NRestarts --value $svc.service")));
}
$nr_max = max(array_column($nr, 1));
check($checks, 'Audio', 'Service restarts', $nr_max >= 5 ? 'warn' : ($nr_max > 0 ? 'info' : 'ok'),
      t('automatic restarts since the last start: {list}', array('list' => implode(', ', array_map(function ($r) { return $r[0] . ' ' . $r[1]; }, $nr))))
      . ($nr_max >= 5 ? ', ' . t('the service keeps stopping, see its log') : ''));

// latest detection
$db = get_db();
$last = $db->querySingle("SELECT Date || ' ' || Time FROM detections ORDER BY Date DESC, Time DESC LIMIT 1");
if ($last) {
  $since = time() - strtotime($last);
  // detections stopped although recording and analysis run: daylight hours (sunrise + 1 h to sunset - 1 h) without a
  // detection, counted from the later of the last detection and this morning's start
  $sun = @date_sun_info(time(), floatval($config['LATITUDE'] ?? 0), floatval($config['LONGITUDE'] ?? 0));
  $running = ($svc_state['birdnet_recording'] ?? '') === 'active' && ($svc_state['birdnet_analysis'] ?? '') === 'active';
  $quiet = 0;
  if ($running && is_array($sun) && is_int($sun['sunrise'] ?? null) && is_int($sun['sunset'] ?? null)
      && time() > $sun['sunrise'] + 3600 && time() < $sun['sunset'] - 3600) {
    $quiet = time() - max(strtotime($last), $sun['sunrise'] + 3600);
  }
  $stopped = $quiet > 3 * 3600;
  check($checks, 'Detections', 'Latest detection', $since < 86400 && !$stopped ? 'ok' : 'warn', t('{time} ({age} ago)', array('time' => $last, 'age' => $age(max(0, $since))))
        . ($stopped ? ', ' . t('no detection for {age} of daylight although recording and analysis run: check the microphone', array('age' => $age($quiet))) : ''));
} else {
  check($checks, 'Detections', 'Latest detection', 'warn', t('no detections yet'));
}
$total = intval($db->querySingle('SELECT COUNT(*) FROM detections'));
$dbsize = @filesize(BS_DB);
check($checks, 'Detections', 'Database', 'ok', t('{n} detections, {mb} MB', array('n' => fmt_num($total), 'mb' => fmt_num($dbsize / 1048576, 1))));
if (not_rejected_sql() !== '') {
  $rv = $db->querySingle("SELECT COUNT(*) FROM detection_reviews");
  check($checks, 'Detections', 'Reviews', 'info', intval($rv) == 1 ? t('{n} detection reviewed', array('n' => 1)) : t('{n} detections reviewed', array('n' => intval($rv))));
}

// disk and purge
$extracted = $config['EXTRACTED'] ?? ($recs . '/Extracted');
$total_b = @disk_total_space($extracted);
$free_b = @disk_free_space($extracted);
if ($total_b) {
  $used = round(100 * (1 - $free_b / $total_b));
  $limit = intval($config['PURGE_THRESHOLD'] ?? 95);
  $mode = $config['FULL_DISK'] ?? 'purge';
  check($checks, 'Disk', 'Disk space', $used >= $limit ? 'fail' : ($used >= $limit - 10 ? 'warn' : 'ok'),
        t('{used}% used, {gb} GB free; at {limit}% the station will {action}', array('used' => $used, 'gb' => round($free_b / 1073741824), 'limit' => $limit,
          'action' => ($mode === 'keep' ? t('STOP (keep)') : ($mode === 'external' ? t('move old sounds to the external folder') : t('purge old clips'))))));
}
// FULL_DISK=external (US-128): the external folder must be usable before the disk fills up
if (($config['FULL_DISK'] ?? '') === 'external') {
  $why = trim((string)shell_exec(station_prefix() . escapeshellarg(__ROOT__ . '/birdnet/bin/python3') . ' ' . escapeshellarg(__ROOT__ . '/scripts/clip_dispose.py') . ' --check 2>&1; echo " rc=$?"'));
  $ok = (bool)preg_match('/ rc=0$/', $why);
  $why = preg_replace('/ rc=\d+$/', '', $why);
  check($checks, 'Disk', 'External backup', $ok && strpos($why, 'fallback purge') === false ? 'ok' : ($ok ? 'warn' : 'fail'), $why);
}
$excl = BS_CONFIG . '/scripts/disk_check_exclude.txt';
$lines = is_file($excl) ? file($excl, FILE_IGNORE_NEW_LINES) : array();
$s = array_search('##start', $lines, true);
$e = array_search('##end', $lines, true);
if ($s !== false && $e !== false) {
  $auto = intdiv($e - $s - 1, 3);  // clip, .png and .jpg (purge_protection.py)
  $manual = count(array_filter(array_slice($lines, $e + 1), function ($l) { return $l !== '' && !in_array(substr($l, -4), array('.png', '.jpg'), true); }));
  check($checks, 'Disk', 'Purge protection', 'ok', t('{auto} clips protected automatically (best {top} per species + confirmed), {manual} locked by hand, list of {date}',
        array('auto' => $auto, 'top' => intval($config['PURGE_PROTECT_TOP_N'] ?? 50), 'manual' => $manual, 'date' => date('Y-m-d H:i', filemtime($excl)))));
} else {
  // normal on a new station (owner 2026-10-25: not an error)
  check($checks, 'Disk', 'Purge protection', 'ok', t('the protection list has not been built yet (it is built before the first purge)'));
}
$quar = glob($recs . '/quarantine/*.wav') ?: array();
check($checks, 'Disk', 'Quarantine', $quar ? 'warn' : 'ok',
      $quar ? t('{n} recordings failed to be reported and were kept in {dir}', array('n' => count($quar), 'dir' => $recs . '/quarantine')) : t('no failed recordings'));
$raw = glob($home . '/BirdNET-Pi/raw-recording/*.wav') ?: array();
$raw_on = ($config['RAW_REC_ENABLED'] ?? '0') == '1';
$raw_size = array_sum(array_map('filesize', $raw));
check($checks, 'Disk', 'Raw recording', 'info', ($raw_on ? t('schedule on') : t('schedule off')) . ', '
      . t('{n} files, {gb} GB (never deleted automatically)', array('n' => count($raw), 'gb' => fmt_num($raw_size / 1073741824, 1))));
$spool = trim($config['SOUND_REPO_PATH'] ?? '', '"');
if ($spool !== '') {
  $waiting = intval($sh('find ' . escapeshellarg($spool) . ' -type f -mmin +60 | wc -l'));
  check($checks, 'Disk', 'Sound repository spool', $waiting > 50 ? 'warn' : 'ok', t('{n} clips older than an hour waiting for the upload', array('n' => $waiting)));
}

// model
$model = $config['MODEL'] ?? '';
$model_files = glob($home . '/BirdNET-Pi/model/' . $model . '.*') ?: array();
$is_v3 = stripos($model, 'V3') !== false;
$geo_ok = !$is_v3 || (glob($home . '/BirdNET-Pi/model/' . $model . '_Geo.onnx') ?: false);
check($checks, 'Model', 'Detection model', $model_files && $geo_ok ? 'ok' : 'fail',
      $model . ($model_files ? '' : ', ' . t('model file missing')) . ($geo_ok ? '' : ', ' . t('location (geo) model missing'))
      . '; ' . t('confidence {c}, sensitivity {s}, overlap {o}', array('c' => fmt_setting($config['CONFIDENCE'] ?? '?'), 's' => fmt_setting($config['SENSITIVITY'] ?? '?'), 'o' => fmt_setting($config['OVERLAP'] ?? '?')))
      . ', SF_THRESH ' . fmt_setting($config['SF_THRESH'] ?? '?'));
$pf = __DIR__ . '/region_profile.json';
if (is_file($pf)) {
  $p = json_decode(file_get_contents($pf), true);
  check($checks, 'Model', 'Location profile', ($p['date'] ?? '') >= date('Y-m-d', strtotime('-2 days')) ? 'ok' : 'warn',
        t('{n} species, computed {date} for {lat}, {lon}', array('n' => count($p['data'] ?? array()), 'date' => ($p['date'] ?? '?'), 'lat' => ($p['lat'] ?? '?'), 'lon' => ($p['lon'] ?? '?'))));
} elseif (($config['APPRISE_NOTIFY_REGION_RARE'] ?? '0') == '1') {
  check($checks, 'Model', 'Location profile', 'info', t('not computed yet: it is built at the first notification of the day'));
}

// offline notification queue (scripts/utils/notify_queue.py): what waits for the internet, and a failed send (its
// .retry mark; the queue is retried every 5 minutes once online)
$q = BS_STATE . '/notify_queue.jsonl';
$q_lines = is_file($q) ? @file($q, FILE_SKIP_EMPTY_LINES) : array();
if ($q_lines === false) {
  check($checks, 'Notifications', 'Notification queue', 'info', t('the queue cannot be read'));
} else {
  $qn = count($q_lines);
  $retry = is_file($q . '.retry') ? filemtime($q . '.retry') : 0;
  check($checks, 'Notifications', 'Notification queue', ($retry || $qn > 50) ? 'warn' : ($qn > 0 ? 'info' : 'ok'),
        ($qn > 0 ? t('{n} notifications waiting to be sent', array('n' => $qn)) : t('nothing waiting to be sent'))
        . ($retry ? ', ' . t('the last send failed at {time}, retried every 5 minutes', array('time' => date('Y-m-d H:i', $retry))) : ''));
}

// security: the web password and the files anyone on the device could change
$menu_pwd = t('Settings') . ' › ' . t('Access');
if (($config['CADDY_PWD'] ?? '') === '') {
  check($checks, 'Security', 'Web password', 'warn', t('not set: anyone on the network can change the settings; set one in {menu}', array('menu' => $menu_pwd)));
} elseif ($config['CADDY_PWD'] === 'birdsuite') {
  // the factory password of a new station (owner 2026-10-23): known to everyone who has read the manual
  check($checks, 'Security', 'Web password', 'warn', is_authenticated() ? t('still the factory password birdsuite: change the default web password in {menu}', array('menu' => $menu_pwd))
        : t('still the factory password: change it in {menu}', array('menu' => $menu_pwd)),
        array($menu_pwd, 'Access', 'view'));
} else {
  check($checks, 'Security', 'Web password', 'ok', t('set'));
}
$ww = intval($sh('find ' . escapeshellarg($home . '/BirdNET-Pi') . ' -xdev -perm -o+w ! -type l | wc -l'));
check($checks, 'Security', 'File permissions', $ww > 0 ? 'warn' : 'ok', $ww > 0
      ? t('{n} files or folders under {dir} can be changed by anyone on the device (chmod -R o-w)', array('n' => $ww, 'dir' => $home . '/BirdNET-Pi'))
      : t('no program file can be changed by other users'));

// the last backup (scripts/backup_data.sh writes state/last_backup)
$menu_bkp = t('Tools') . ' › ' . t('Data');
$lb = BS_STATE . '/last_backup';
if (is_file($lb)) {
  $lb_age = time() - filemtime($lb);
  check($checks, 'System', 'Last backup', $lb_age > 30 * 86400 ? 'warn' : 'ok', t('{time} ({age} ago)', array('time' => date('Y-m-d H:i', filemtime($lb)), 'age' => $age(max(0, $lb_age))))
        . ($lb_age > 30 * 86400 ? ', ' . t('make a new one in {menu}', array('menu' => $menu_bkp)) : ''));
} else {
  check($checks, 'System', 'Last backup', 'info', t('no backup made on this station yet ({menu})', array('menu' => $menu_bkp)));
}

// version
$branch =$sh(station_prefix() . "git -C $home/BirdNET-Pi rev-parse --abbrev-ref HEAD");
$commit = $sh(station_prefix() . "git -C $home/BirdNET-Pi log -1 --format='%h %cs'");
list($tag, $ahead) = installed_release();
$tag = $tag === '' ? t('unknown') : $tag . ($ahead === null ? '+' : ($ahead > 0 ? '+' . $ahead : ''));
$new = $_SESSION['release_new'] ?? '';
check($checks, 'System', 'Version', $new !== '' ? 'warn' : 'ok', t('{tag} on {branch} ({commit})', array('tag' => $tag, 'branch' => $branch, 'commit' => $commit))
      . ($new !== '' ? ', ' . t('new release {v} available', array('v' => $new)) : ''), $new !== '' ? array(t('Update'), 'sudo -n /usr/local/sbin/birdsuite-root update') : null);

// clock, power, temperature, load
$ntp = $sh('timedatectl show -p NTPSynchronized --value');
check($checks, 'System', 'Clock', $ntp === 'yes' ? 'ok' : 'warn', $ntp === 'yes' ? t('synchronised (NTP)') : t('not synchronised: detection times may be wrong'));
$thr = $sh('sudo -n /usr/local/sbin/birdsuite-root throttled');
if (preg_match('/0x([0-9a-f]+)/i', $thr, $m)) {
  $bits = hexdec($m[1]);
  $now = $bits & 0xF;
  $past = ($bits >> 16) & 0xF;
  $txt = array();
  if ($now & 1) $txt[] = t('under-voltage NOW');
  if ($now & 4) $txt[] = t('throttled NOW');
  if ($past & 1) $txt[] = t('under-voltage since boot');
  if ($past & 4) $txt[] = t('throttled since boot');
  check($checks, 'System', 'Power', $now ? 'fail' : ($past ? 'warn' : 'ok'), $txt ? implode(', ', $txt) . ', ' . t('check the power supply') : t('no under-voltage or throttling'));
}
$temp = @file_get_contents('/sys/class/thermal/thermal_zone0/temp');
if ($temp !== false) {
  $c = round(intval($temp) / 1000);
  check($checks, 'System', 'CPU temperature', $c >= 80 ? 'fail' : ($c >= 70 ? 'warn' : 'ok'), "$c °C");
}
$load = sys_getloadavg();
$cores = max(1, intval($sh('nproc')));
check($checks, 'System', 'Load', $load[1] > $cores ? 'warn' : 'ok', t('{load} (5 min) on {n} cores', array('load' => fmt_num($load[1], 2), 'n' => $cores)));
$up = intval(explode(' ', (string)@file_get_contents('/proc/uptime'))[0]);
check($checks, 'System', 'Uptime', 'info', $age($up));

$worst = 'ok';
foreach ($checks as $c) {
  if ($c['status'] === 'fail') { $worst = 'fail'; break; }
  if ($c['status'] === 'warn') $worst = 'warn';
}

if ($json) {
  header('Content-Type: application/json');
  echo json_encode(array('station' => get_sitename(), 'time' => date('c'), 'status' => $worst,
    'checks' => array_map(function ($c) { return array('group' => $c['group'], 'name' => $c['name'], 'status' => $c['status'], 'detail' => $c['detail']); }, $checks)),
    JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  exit;
}
$h = function ($s) { return htmlspecialchars((string)$s, ENT_QUOTES); };
$icon = array('ok' => '✅', 'warn' => '⚠️', 'fail' => '❌', 'info' => 'ℹ️');
?>
<style>
.doctor { max-width: 900px; margin: 0 auto; text-align: left; padding: 0 12px; }
.doctor table { width: 100%; border-collapse: collapse; }
.doctor td { text-align: left !important; padding: 5px 6px; border-top: 1px solid rgba(128,128,128,.25); vertical-align: top; }
.doctor td.st { width: 1.6em; }
.doctor td.nm { width: 30%; font-weight: bold; }
.doctor tr.grp td { font-weight: bold; border-top: none; padding-top: 14px; font-size: 1.05em; opacity: .8; }
.doctor .summary { padding: 10px 12px; border-radius: 8px; margin: 10px 0; }
.doctor .summary.ok { background: rgba(46,125,50,.15); } .doctor .summary.warn { background: rgba(217,122,0,.18); } .doctor .summary.fail { background: rgba(198,40,40,.18); }
</style>
<?php // embedded in System › Status since 2026-10-21 ($doctor_embed): the same checks, no page of its own ?>
<div class="doctor">
  <div class="summary <?php echo $worst; ?>"><?php echo $icon[$worst] . ' ' . ($worst === 'ok' ? t('Everything looks healthy.') : ($worst === 'warn' ? t('The station works, with warnings below.') : t('Something needs attention, see the red lines.'))); ?>
    <small style="float:right"><?php echo date('Y-m-d H:i:s'); ?>, <a href="views.php?view=<?php echo empty($doctor_embed) ? 'Doctor' : 'Status'; ?>"><?php echo t('check again'); ?></a>, <a href="scripts/doctor.php?format=json" target="_blank">JSON</a></small></div>
  <?php // every line always, no pages (owner 2026-10-24) ?>
  <table>
  <?php $g = '';
  foreach ($checks as $c) {
    // in Status the services have their own table below, with the buttons: their checks only count in the summary
    if (!empty($doctor_embed) && $c['group'] === 'Services' && $c['status'] !== 'fail') continue;
    if ($c['group'] !== $g) { $g = $c['group']; echo '<tr class="grp"><td colspan="3">' . $h(t($g)) . '</td></tr>'; }
    $fix = '';
    if ($c['fix'] && ($c['fix'][2] ?? '') === 'view') {
      // a page to open (the setting is changed there), not a command
      $fix = ' <form action="views.php" method="GET" style="display:inline"><button type="submit" name="view" value="' . $h($c['fix'][1]) . '">' . $h($c['fix'][0]) . '</button></form>';
    } elseif ($c['fix']) {
      $fix = ' <form action="views.php" method="GET" style="display:inline"><button type="submit" name="submit" value="' . $h($c['fix'][1]) . '">' . $h($c['fix'][0]) . '</button></form>';
    }
    echo '<tr><td class="st">' . $icon[$c['status']] . '</td><td class="nm">' . $h(t($c['name'])) . '</td><td>' . $h($c['detail']) . $fix . '</td></tr>';
  } ?>
  </table>
</div>
