<?php
/* Change of the current location (owner 2026-10-21): every change of place goes through these steps, so no recording
 * mixes two places and no recording made here is analysed with the coordinates of the next place:
 *   1. the recording (and the live audio) is stopped
 *   2. the recordings already made are analysed (StreamData empties), still with this location's coordinates
 *   3. the location changes (coordinates, timezone, species list: location_activate)
 *   4. the recording starts again, when it was running before
 * The steps run in the background (php location_switch.php run <id>), so they go on when the page is closed; their state
 * is in location_switch.json, which every page shows in a window while a change runs (static/location-switch.js).
 *   GET  ?state=1      the state (steps, status, error)
 *   POST ?start=<id>   starts a change to that location; OK or an error */
require_once __DIR__ . '/common.php';

$home = get_home();
$ls_file = BS_STATE . '/location_switch.json';
$ls_wait_max = 1200; // seconds the analysis of the last recordings may take before the change is given up

function ls_state() {
  global $ls_file;
  $j = json_decode((string)@file_get_contents($ls_file), true) ?: array();
  // a change whose process is gone (station restarted, process killed) is shown as interrupted
  // (pid 0 = just started, the process has not written its pid yet)
  if (($j['status'] ?? '') === 'running' && !(intval($j['pid'] ?? 0) === 0 && time() - strtotime($j['started'] ?? '') < 15)
      && strpos((string)@file_get_contents('/proc/' . intval($j['pid'] ?? 0) . '/cmdline'), 'location_switch.php') === false) {
    $j['status'] = 'error';
    $j['error'] = t('interrupted (the station restarted or the change was stopped)');
  }
  return $j;
}
function ls_write($j) {
  global $ls_file;
  $tmp = $ls_file . '.tmp';
  file_put_contents($tmp, json_encode($j, JSON_UNESCAPED_UNICODE));
  rename($tmp, $ls_file);
}
function ls_active($svc) {
  return trim((string)shell_exec('systemctl is-active ' . escapeshellarg($svc) . ' 2>/dev/null')) === 'active';
}
function ls_queue() {
  $c = get_config();
  $dir = rtrim((string)($c['RECS_DIR'] ?? get_home() . '/BirdSongs'), '/') . '/StreamData';
  $dir = str_replace('$HOME', get_home(), $dir);
  return count(glob($dir . '/*.wav') ?: array());
}

if (PHP_SAPI === 'cli') {
  $a = array_slice($argv, 1);
  if (($a[0] ?? '') !== 'run' || !ctype_digit((string)($a[1] ?? ''))) { echo "usage: php location_switch.php run <location id>\n"; exit(2); }
  $l = location_by_id($a[1]);
  $j = ls_state();
  $j['pid'] = getmypid();
  ls_write($j);
  $step = function ($i, $status, $extra = array()) use (&$j) {
    $j['steps'][$i] = array_merge($j['steps'][$i], array('status' => $status), $extra);
    ls_write($j);
  };
  $fail = function ($i, $msg) use (&$j, $step) {
    $step($i, 'error');
    $j['status'] = 'error'; $j['error'] = $msg; $j['finished'] = date('Y-m-d H:i:s');
    ls_write($j);
  };
  if (!$l) { $fail(0, t('Unknown location')); exit(1); }
  $rec = ls_active('birdnet_recording.service');
  $live = ls_active('livestream.service');
  $j['was_recording'] = $rec;
  // 1. stop the recording
  $step(0, 'running');
  if ($rec || $live) {
    shell_exec('sudo -n /usr/local/sbin/birdsuite-svc stop birdnet_recording.service livestream.service 2>&1');
    if (ls_active('birdnet_recording.service')) { $fail(0, t('The recording did not stop')); exit(1); }
    $step(0, 'done');
  } else {
    $step(0, 'skipped', array('note' => t('the recording was not running')));
  }
  // 2. the recordings already made are analysed here
  $step(1, 'running', array('left' => ls_queue()));
  $t0 = time();
  while (($n = ls_queue()) > 0) {
    if (!ls_active('birdnet_analysis.service')) {
      $fail(1, t('The analysis is stopped, so {n} recordings made here wait: start it (System › Services) and change the location again.', array('n' => $n)));
      if ($rec) shell_exec('sudo -n /usr/local/sbin/birdsuite-svc start birdnet_recording.service 2>&1');
      if ($live) shell_exec('sudo -n /usr/local/sbin/birdsuite-svc start livestream.service 2>&1');
      exit(1);
    }
    if (time() - $t0 > $ls_wait_max) {
      $fail(1, t('The analysis of the last recordings takes too long ({n} left): the location was not changed.', array('n' => $n)));
      if ($rec) shell_exec('sudo -n /usr/local/sbin/birdsuite-svc start birdnet_recording.service 2>&1');
      if ($live) shell_exec('sudo -n /usr/local/sbin/birdsuite-svc start livestream.service 2>&1');
      exit(1);
    }
    $step(1, 'running', array('left' => $n));
    sleep(2);
  }
  $step(1, 'done', array('left' => 0));
  // 3. the location changes
  $step(2, 'running');
  $err = location_activate(locations_complete(array($l))[0]);
  if ($err !== '') {
    $fail(2, $err);
    if ($rec) shell_exec('sudo -n /usr/local/sbin/birdsuite-svc start birdnet_recording.service 2>&1');
    if ($live) shell_exec('sudo -n /usr/local/sbin/birdsuite-svc start livestream.service 2>&1');
    exit(1);
  }
  @unlink(mic_reconnected_file()); // the question of the Latest page is answered too
  $step(2, 'done');
  // 4. the recording starts again
  $step(3, 'running');
  // the live audio (Spectrogram page, Live) comes back whenever it was running, also with the recording stopped
  // (owner 2026-10-21: it stayed off after a change made while the recording was off)
  if ($live) shell_exec('sudo -n /usr/local/sbin/birdsuite-svc start livestream.service 2>&1');
  if ($rec) {
    shell_exec('sudo -n /usr/local/sbin/birdsuite-svc start birdnet_recording.service 2>&1');
    sleep(2);
    if (!ls_active('birdnet_recording.service')) { $fail(3, t('The recording did not start again: check the microphone and System › Services.')); exit(1); }
    $step(3, 'done');
  } else {
    $step(3, 'skipped', array('note' => t('the recording was not running before')));
  }
  $j['status'] = 'done'; $j['finished'] = date('Y-m-d H:i:s');
  ls_write($j);
  exit(0);
}

// the microphone came back after a removal (US-58, mic_hotplug.sh writes state/mic_reconnected.json): every page asks
// in a window where the station is (owner 2026-10-24: a modal on any page, not only the Latest page); unanswered for
// 5 minutes = the same place
// the answer: POST ?mic=same closes the question (the station stays: no login needed, it changes nothing);
// POST ?mic=new&name&lat&lon creates the location (login) and answers ID:<id>, the page then starts the switch to it
if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array($_GET['mic'] ?? '', array('same', 'new'), true)) {
  if ($_GET['mic'] === 'new') {
    ensure_authenticated(t('You must be authenticated to change the settings.'));
    $name = trim(preg_replace('/[\x00-\x1F\x7F<>"]/', '', (string)($_GET['name'] ?? '')));
    $lat = floatval($_GET['lat'] ?? ''); $lon = floatval($_GET['lon'] ?? '');
    if ($name === '' || abs($lat) > 90 || abs($lon) > 180 || ($lat == 0 && $lon == 0)) { echo t('A name and valid coordinates are needed'); die(); }
    $list = locations_load();
    $id = max(array_map('intval', array_column($list, 'id'))) + 1;
    $list[] = array('id' => $id, 'name' => mb_substr($name, 0, 60), 'lat' => round($lat, 4), 'lon' => round($lon, 4), 'radius_km' => LOCATION_RADIUS_KM,
                    'timezone' => station_timezone(), 'species_list' => (string)(get_config()['SPECIES_LIST'] ?? ''));
    if (!locations_save($list)) { echo t('Error writing the locations'); die(); }
    @unlink(mic_reconnected_file());
    echo 'ID:' . $id;
    die();
  }
  @unlink(mic_reconnected_file());
  echo 'OK';
  die();
}
if (isset($_GET['mic'])) {
  header('Content-Type: application/json');
  session_write_close();
  set_timezone();  // "at" is the station's local time
  $mr = is_file(mic_reconnected_file()) ? json_decode((string)@file_get_contents(mic_reconnected_file()), true) : null;
  $since = is_array($mr) ? (!empty($mr['ts']) ? intval($mr['ts']) : (!empty($mr['at']) ? strtotime($mr['at']) : time())) : time();
  $left = 300 - (time() - $since);
  if ($mr !== null && $left <= 0) { @unlink(mic_reconnected_file()); $mr = null; }
  // one location only (simple mode, US-125): the station stays where it is, nothing to ask
  if ($mr !== null && !multi_location()) { @unlink(mic_reconnected_file()); $mr = null; }
  if ($mr === null) { echo json_encode(array('pending' => false)); die(); }
  $cur = location_current();
  // a new location starts at the network position (location_check.py, refreshed when the microphone came back), else at
  // the current location's coordinates; Settings › Locations fixes them later (owner 2026-10-24: no map here)
  $net = json_decode((string)@file_get_contents(BS_STATE . '/location_check.json'), true);
  $guess = (is_array($net) && isset($net['lat'], $net['lon']))
    ? array('lat' => round(floatval($net['lat']), 4), 'lon' => round(floatval($net['lon']), 4), 'from' => 'network', 'place' => trim(implode(', ', array_filter(array($net['city'] ?? '', $net['region'] ?? ''))), ', '))
    : ($cur ? array('lat' => round(floatval($cur['lat']), 4), 'lon' => round(floatval($cur['lon']), 4), 'from' => 'current', 'place' => $cur['name']) : null);
  // the coordinates only after the login (owner 2026-10-25: the positions are private); the name of the place stays
  if ($guess && !is_authenticated()) unset($guess['lat'], $guess['lon']);
  echo json_encode(array('pending' => true, 'at' => substr((string)($mr['at'] ?? ''), 11, 5), 'left' => max(1, $left), 'guess' => $guess,
    'current' => $cur ? array('id' => intval($cur['id']), 'name' => $cur['name']) : null,
    'locations' => array_values(array_map(function ($l) { return array('id' => intval($l['id']), 'name' => $l['name']); }, locations_load()))),
    JSON_UNESCAPED_UNICODE);
  die();
}
if (isset($_GET['state'])) {
  header('Content-Type: application/json');
  session_write_close();
  echo json_encode(ls_state(), JSON_UNESCAPED_UNICODE);
  die();
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_GET['start'])) {
  ensure_authenticated(t('You must be authenticated to change the current location.'));
  $l = location_by_id($_GET['start']);
  if (!$l) { echo t('Unknown location'); die(); }
  if ((ls_state()['status'] ?? '') === 'running') { echo t('A change of location is already running'); die(); }
  $cur = location_current();
  ls_write(array('status' => 'running', 'pid' => 0, 'id' => intval($l['id']), 'name' => $l['name'],
    'from' => $cur ? $cur['name'] : '', 'started' => date('Y-m-d H:i:s'), 'steps' => array(
      array('key' => 'stop', 'status' => 'pending'), array('key' => 'analyse', 'status' => 'pending'),
      array('key' => 'change', 'status' => 'pending'), array('key' => 'start', 'status' => 'pending'))));
  shell_exec('setsid nohup php ' . escapeshellarg(__FILE__) . ' run ' . intval($l['id']) . ' > /dev/null 2>&1 < /dev/null &');
  echo 'OK';
  die();
}
http_response_code(400);
