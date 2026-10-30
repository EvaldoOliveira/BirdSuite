<?php
/* One setting written at once (owner 2026-10-25): the options moved out of Settings › Advanced save as soon as they
 * change — Status (updates), Themes (Latest page image), Detection Log (log levels), Network (WiFi in the field).
 * POST JSON {KEY: value}; only the keys below, each checked; the service that reads it restarts when needed. */
require_once __DIR__ . '/common.php';
header('Content-Type: text/plain; charset=utf-8');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { echo 'POST only'; die(); }
ensure_authenticated(t('You must be authenticated to change the settings.'));
$in = json_decode((string)file_get_contents('php://input'), true);
if (!is_array($in) || !$in) { echo t('Nothing to save'); die(); }
$level = function ($v) { return in_array($v, array('error', 'warning', 'info', 'debug'), true) ? $v : null; };
$onoff = function ($v) { return in_array((string)$v, array('0', '1'), true) ? (string)$v : null; };
$margin = function ($v) { return preg_match('/^\d{1,3}$/', (string)$v) && intval($v) <= 180 ? (string)intval($v) : null; };
// key => (check returning the value to write or null, quoted, service to restart)
$keys = array(
  'SILENCE_UPDATE_INDICATOR' => array($onoff, false, ''),
  'AUTOMATIC_UPDATE' => array($onoff, false, ''),
  'AUTOMATIC_UPDATE_DAYS' => array(function ($v) { return preg_match('/^[0-6](,[0-6]){0,6}$/', (string)$v) ? (string)$v : null; }, true, ''),
  'AUTOMATIC_UPDATE_TIME' => array(function ($v) { return preg_match('/^([01][0-9]|2[0-3]):[0-5][0-9]$/', (string)$v) ? (string)$v : null; }, false, ''),
  'FIELD_WIFI_MODE' => array(function ($v) { return in_array((string)$v, array('always', 'cycle', 'off'), true) ? (string)$v : null; }, false, ''),  // read by the field service on every round
  'CUSTOM_IMAGE' => array(function ($v) { $v = trim((string)$v); return $v === '' || custom_image_ok($v) ? $v : null; }, false, ''),
  'CUSTOM_IMAGE_TITLE' => array(function ($v) { return mb_substr(str_replace(array('"', '$', '`', '\\', "\r", "\n"), '', (string)$v), 0, 80); }, true, ''),
  'NOCTURNAL_DAWN_MARGIN' => array($margin, false, ''),
  'NOCTURNAL_DUSK_MARGIN' => array($margin, false, ''),
  'LogLevel_BirdnetRecordingService' => array($level, true, 'birdnet_recording'),
  'LogLevel_SpectrogramViewerService' => array($level, true, 'spectrogram_viewer'),
  'LogLevel_LiveAudioStreamService' => array($level, true, 'livestream'),
);
$c = (string)file_get_contents(BS_CONF);
$restart = array();
foreach ($in as $k => $v) {
  if (!isset($keys[$k])) { echo t('Unknown setting'); die(); }
  list($check, $quoted, $svc) = $keys[$k];
  $val = $check($v);
  if ($val === null) { echo t('Not a valid value for {key}', array('key' => $k)); die(); }
  $line = $k . '=' . ($quoted ? '"' . $val . '"' : $val);
  $c = preg_match('/^' . $k . '=/m', $c) ? preg_replace('/^' . $k . '=.*/m', str_replace(array('\\', '$'), array('\\\\', '\\$'), $line), $c) : rtrim($c, "\n") . "\n" . $line . "\n";
  if ($svc !== '') $restart[$svc] = true;
}
if (file_put_contents(BS_CONF, $c) === false) { echo t('Error writing the settings'); die(); }
foreach (array_keys($restart) as $svc) exec('sudo -n /usr/local/sbin/birdsuite-svc restart ' . escapeshellarg($svc) . ' > /dev/null 2>&1 &');
echo 'OK';
