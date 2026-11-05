<?php
/* The microphone of Settings › Audio (US-07, owner 2026-11-02): which capture device records (Microphone in use) and its
 * capture volume (Microphone gain). Included by advanced.php for the page; requested directly by the page's script for a
 * change (POST, login, same origin):
 *   mic=ID    the card with that ALSA id becomes the recording device: REC_MIC = ID, install_audio.sh points the shared
 *             device at it and restarts recording and live stream (it does so only when something changed)
 *   gain=N    the capture volume of the microphone in use, 0 to 100 %, through birdsuite-root mic-gain (root: the value
 *             is kept in the ALSA state, alsactl store)
 * Each answers JSON: {"ok": true, "gain": {...} | null, "active": ID} or {"ok": false, "error": text}. */
require_once __DIR__ . '/common.php';

// every capture device of the station: [{id, card, name}] from /proc/asound (a card with a capture PCM), ids as ALSA
// gives them (stable across replugs, the number is not)
function mic_devices() {
  $names = array();
  foreach (@file('/proc/asound/cards', FILE_IGNORE_NEW_LINES) ?: array() as $l) {
    if (preg_match('/^\s*(\d+)\s+\[[^\]]*\]:\s*.*? - (.+)$/', $l, $m)) $names[$m[1]] = trim($m[2]);
  }
  $out = array();
  foreach (glob('/proc/asound/card[0-9]*', GLOB_ONLYDIR) ?: array() as $d) {
    if (!glob($d . '/pcm*c', GLOB_ONLYDIR)) continue;
    $n = substr(basename($d), 4);
    $id = trim((string)@file_get_contents($d . '/id'));
    if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]{0,31}$/', $id)) continue;
    // the port it is plugged into (owner 2026-11-04): the USB port path (1-1.3) and its speed, read from sysfs
    $port = ''; $speed = '';
    $dev = @realpath('/sys/class/sound/card' . $n . '/device');
    if ($dev && preg_match('~/usb\d+/(?:[0-9.-]+/)*([0-9]+-[0-9.]+):[0-9.]+$~', $dev, $pm)) {
      $port = $pm[1];
      $speed = trim((string)@file_get_contents(dirname($dev) . '/speed'));
    }
    $out[] = array('id' => $id, 'card' => intval($n), 'name' => $names[$n] ?? $id, 'usb' => file_exists($d . '/usbid'),
                   'port' => $port, 'speed' => ctype_digit($speed) ? intval($speed) : 0);
  }
  usort($out, function ($a, $b) { return $a['card'] - $b['card']; });
  return $out;
}

// the ALSA id of the card the shared device records from (the station user's ALSA file, written by install_audio.sh:
// hw:CARD=<id> or an older hw:<n>), else the chosen one (REC_MIC) when present, else the first USB card, else ''
function mic_active_id($devices) {
  $by_id = array(); $by_n = array();
  foreach ($devices as $d) { $by_id[$d['id']] = $d; $by_n[$d['card']] = $d; }
  $rc = (string)@file_get_contents(BS_ASOUNDRC);
  if (preg_match('/hw:CARD=([A-Za-z0-9_]+)/', $rc, $m) && isset($by_id[$m[1]])) return $m[1];
  if (preg_match('/hw:(\d+)/', $rc, $m) && isset($by_n[intval($m[1])])) return $by_n[intval($m[1])]['id'];
  $want = trim((string)(get_config()['REC_MIC'] ?? ''), '"');
  if ($want !== '' && isset($by_id[$want])) return $want;
  foreach ($devices as $d) if ($d['usb']) return $d['id'];
  return '';
}

// the capture volume of card $id (birdsuite-root mic-gain), set first to $pct when given: {card, control, percent, db}
// or null when the card has no adjustable capture control (or the helper failed)
function mic_gain($id, $pct = null) {
  $cmd = 'sudo -n ' . BS_ROOT_HELPER . ' mic-gain';
  if ($id !== '') $cmd .= ' ' . escapeshellarg($id);
  if ($pct !== null) $cmd .= ' ' . escapeshellarg((string)intval($pct));
  $out = trim((string)shell_exec($cmd . ' 2>/dev/null'));
  if (!preg_match('/^card=(\d+) control=(.+) percent=(\d{1,3}) db=(-?[0-9.]+|-)$/', $out, $m)) return null;
  return array('card' => intval($m[1]), 'control' => $m[2], 'percent' => intval($m[3]), 'db' => $m[4] === '-' ? null : $m[4]);
}

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
  ensure_authenticated();
  header('Content-Type: application/json');
  $answer = function ($a) { echo json_encode($a); exit; };
  if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); $answer(array('ok' => false)); }
  $devices = mic_devices();
  if (isset($_POST['gain'])) {
    $g = (string)$_POST['gain'];
    if (!preg_match('/^(0|[1-9]\d?|100)$/', $g)) $answer(array('ok' => false, 'error' => t('str_the_microphone_gain_could_not_be_set')));
    $gain = mic_gain(mic_active_id($devices), intval($g));
    // the new gain into state/microphone.json at once: the next detections keep it (owner 2026-11-04)
    exec(as_station(escapeshellarg(BS_SCRIPTS . '/mic_state.sh')) . ' > /dev/null 2>&1 &');
    $answer($gain ? array('ok' => true, 'gain' => $gain) : array('ok' => false, 'error' => t('str_the_microphone_gain_could_not_be_set')));
  }
  if (isset($_POST['mic'])) {
    $id = (string)$_POST['mic'];
    if (!in_array($id, array_column($devices, 'id'), true) || !conf_set('REC_MIC', $id, $err)) {
      $answer(array('ok' => false, 'error' => t('str_the_microphone_could_not_be_changed')));
    }
    get_config(true);
    // the shared device follows (as the station user: its own ALSA file and settings); it restarts recording and live
    // stream through birdsuite-svc when the device changed
    exec(as_station(escapeshellarg(BS_SCRIPTS . '/install_audio.sh')) . ' > /dev/null 2>&1', $o, $rc);
    $active = mic_active_id(mic_devices());
    exec(as_station(escapeshellarg(BS_SCRIPTS . '/mic_state.sh')) . ' > /dev/null 2>&1');
    if ($rc !== 0 || $active !== $id) $answer(array('ok' => false, 'error' => t('str_the_microphone_could_not_be_changed')));
    $answer(array('ok' => true, 'active' => $active, 'gain' => mic_gain($active)));
  }
  http_response_code(400);
  $answer(array('ok' => false));
}
