<?php
/* One setting written at once (owner 2026-10-25): the options moved out of Settings › Advanced save as soon as they
 * change — Status (updates), Detection Log (log levels), Network (WiFi in the field).
 * POST JSON {KEY: value}; only the keys below, each checked; the service that reads it restarts when needed. */
require_once __DIR__ . '/common.php';
header('Content-Type: text/plain; charset=utf-8');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { echo 'POST only'; die(); }
ensure_authenticated(t('str_you_must_change_the_settings_help'));
$in = json_decode((string)file_get_contents('php://input'), true);
if (!is_array($in) || !$in) { echo t('str_nothing_to_save'); die(); }
// the keys this endpoint takes => the service that reads it (restarted after the change); the values are checked by
// conf_set_many() (common.php, one rule per key)
$keys = array(
  'SILENCE_UPDATE_INDICATOR' => '',
  'AUTOMATIC_UPDATE' => '',
  'AUTOMATIC_UPDATE_DAYS' => '',
  'AUTOMATIC_UPDATE_TIME' => '',
  'LOG_RETENTION_DAYS' => '',  // applied by birdsuite-root logs below
  'FIELD_WIFI_MODE' => '',  // read by the field service on every round
  'NOCTURNAL_DAWN_MARGIN' => '',
  // Station › Microphone, the audio loss (owner 2026-11-04): read by the analysis for every recording
  'AUDIO_LOSS_DISCARD' => '',
  'AUDIO_LOSS_MAX_MS' => '',
  // moved out of Station › Microphone (owner 2026-11-04): the recording length (Station › Model) restarts recording
  // and analysis; the clip's length and format (Data › Data) are read at each extraction; the hearing settings
  // (Watch › Sonogram) restart the live audio
  'RECORDING_LENGTH' => array(BS_SVC_RECORDING, BS_SVC_ANALYSIS),
  'REC_BITS' => BS_SVC_RECORDING,
  'EXTRACTION_LENGTH' => '',
  'AUDIOFMT' => '',
  'FREQSHIFT_HI' => '',
  'FREQSHIFT_LO' => '',
  'FREQSHIFT_BASE' => '',
  'NOCTURNAL_DUSK_MARGIN' => '',
  'LogLevel_BirdnetRecordingService' => BS_SVC_RECORDING,
  'LogLevel_SpectrogramViewerService' => BS_SVC_SPECTROGRAM,
  'LogLevel_LiveAudioStreamService' => BS_SVC_LIVESTREAM,
);
$restart = array();
foreach ($in as $k => $v) {
  if (!isset($keys[$k])) { echo t('str_unknown_setting'); die(); }
  foreach ((array)$keys[$k] as $svc) if ($svc !== '') $restart[$svc] = true;
}
// a clip is cut from its recording: never longer than it (as Station › Microphone did)
$rl = intval($in['RECORDING_LENGTH'] ?? get_config()['RECORDING_LENGTH'] ?? 15);
if (intval($in['EXTRACTION_LENGTH'] ?? get_config()['EXTRACTION_LENGTH'] ?? 3) > $rl) $in['EXTRACTION_LENGTH'] = (string)$rl;
if (!conf_set_many($in, $err)) { echo $err; die(); }
// the log policy goes to the system at once (owner 2026-11-01, Disk Space)
if (isset($in['LOG_RETENTION_DAYS'])) exec('sudo -n ' . BS_ROOT_HELPER . ' logs ' . escapeshellarg((string)$in['LOG_RETENTION_DAYS']) . ' > /dev/null 2>&1');
foreach (array_keys($restart) as $svc) exec('sudo -n ' . BS_SVC_HELPER . ' restart ' . escapeshellarg($svc) . ' > /dev/null 2>&1 &');
echo 'OK';
