<?php
/* Raw Recording page (owner 2026-10-08): scheduled long, unprocessed recordings (dawn chorus) in parallel with the
 * analysis. One group per microphone plugged in (owner 2026-11-02), the station's first: its schedule (days, recurrent
 * or once, start and end, segment length), folder and sample rate, Record now / Stop, the status, and its Sessions.
 * Settings in config/raw_recording.json by ALSA card id (raw_groups.py; a new microphone starts from the RAW_REC_*
 * defaults), schedules in the station user's crontab (raw_recording_cron.sh), sessions by raw_recording.sh <id>, one
 * subfolder <start>-<station>-<microphone name> each with its WAV files and its .recording log.
 * ?mic=<id>&file=<session>/<name> sends one of those files. */
require_once __DIR__ . '/common.php';
require_once __DIR__ . '/audio_input.php';
ensure_authenticated();
$config = get_config();
$dow = array(1 => t('str_mon'), 2 => t('str_tue'), 3 => t('str_wed'), 4 => t('str_thu'), 5 => t('str_fri'), 6 => t('str_sat'), 0 => t('str_sun'));

function hms($v, $default) {
  return preg_match('/^([01]\d|2[0-3]):[0-5]\d(:[0-5]\d)?$/', (string)$v) ? (strlen($v) == 5 ? "$v:00" : $v) : $default;
}
// the saved groups (config/raw_recording.json), by microphone ALSA id
function raw_groups_load() {
  $d = json_decode((string)@file_get_contents(BS_RAW_REC_FILE), true);
  return is_array($d) ? $d : array();
}
function raw_groups_save($all) {
  ksort($all);
  $ok = file_put_contents(BS_RAW_REC_FILE, json_encode($all, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n", LOCK_EX) !== false;
  if ($ok) @chmod(BS_RAW_REC_FILE, 0664);
  return $ok;
}
// one microphone's group: its saved values, else the RAW_REC_* defaults of BirdSuite.conf
function raw_group($id) {
  $c = get_config();
  $g = raw_groups_load()[$id] ?? array();
  $v = function ($k, $ck, $d) use ($g, $c) { return (string)($g[$k] ?? trim((string)($c[$ck] ?? $d), '"')); };
  $dir = rtrim($v('dir', 'RAW_REC_DIR', ''), '/');
  return array('enabled' => $v('enabled', 'RAW_REC_ENABLED', '0') === '1',
    'days' => array_values(array_filter(explode(',', $v('days', 'RAW_REC_DAYS', '')), function ($x) { return preg_match('/^[0-6]$/', $x); })),
    'recurrent' => $v('recurrent', 'RAW_REC_RECURRENT', '0') === '1',
    'start' => hms($v('start', 'RAW_REC_START', '04:00:00'), '04:00:00'), 'end' => hms($v('end', 'RAW_REC_END', '10:00:00'), '10:00:00'),
    'segment' => max(1, min(240, intval($v('segment', 'RAW_REC_SEGMENT_MIN', '30')) ?: 30)),
    'rate' => intval($v('rate', 'RAW_REC_RATE', '48000')) ?: 48000, 'dir' => $dir !== '' ? $dir : BS_RAW_REC);
}
// the microphone's own sample rates (/proc/asound/cardN/stream*: "Rates:" lines; a list or a range) and the rates offered:
// the usual lower ones and every own rate, never above the highest own rate (a converter would only interpolate)
function raw_rates($mic) {
  $own = array();
  foreach (glob('/proc/asound/card' . intval($mic['card']) . '/stream*') ?: array() as $f) {
    foreach (@file($f, FILE_IGNORE_NEW_LINES) ?: array() as $l) {
      if (!preg_match('/^\s*Rates:\s*(.+)$/', $l, $m)) continue;
      if (preg_match('/^(\d+)\s*-\s*(\d+)/', $m[1], $r)) { foreach (array(8000, 16000, 22050, 24000, 32000, 44100, 48000, 96000, 192000) as $v) if ($v >= $r[1] && $v <= $r[2]) $own[$v] = 1; }
      else foreach (preg_split('/[,\s]+/', trim($m[1])) as $v) if (ctype_digit($v)) $own[intval($v)] = 1;
    }
  }
  $own = array_keys($own);
  sort($own);
  $max = $own ? max($own) : 48000;
  $offer = array();
  foreach (array_merge(array(16000, 22050, 24000, 32000, 44100, 48000), $own) as $v) if ($v <= $max) $offer[$v] = 1;
  $offer = array_keys($offer);
  sort($offer);
  return array($own, $offer);
}
// a rate in kHz in the page's number format, without trailing zeros (22.05 / 22,05, 44.1, 48)
function raw_khz($v) { $k = $v / 1000; return fmt_num($k, abs($k - round($k)) < 1e-9 ? 0 : (abs($k * 10 - round($k * 10)) < 1e-9 ? 1 : 2)) . ' kHz'; }
// the recorder of this microphone is running ([r]: the shell running pgrep holds the pattern too)
function raw_running($id) {
  return trim((string)shell_exec('pgrep -f ' . escapeshellarg(preg_quote(BS_SCRIPTS . '/', '') . '[r]aw_recording\.sh ' . $id . '( |$)') . ' 2>/dev/null')) !== '';
}
// the microphone's name in its session folders (as raw_recording.sh): its card name in ASCII, words joined by _; two
// cards with the same name get the ALSA id added
function raw_tag($mic, $devs) {
  $old = setlocale(LC_CTYPE, 0);
  setlocale(LC_CTYPE, 'C.UTF-8');  // the transliteration needs a UTF-8 locale (Ø → O), as LC_ALL=C.UTF-8 in the recorder
  $t = trim(preg_replace('/[^A-Za-z0-9]+/', '_', (string)@iconv('UTF-8', 'ASCII//TRANSLIT', $mic['name'])), '_');
  setlocale(LC_CTYPE, $old);
  if ($t === '') return $mic['id'];
  $same = 0;
  foreach ($devs as $d) if ($d['name'] === $mic['name']) $same++;
  return $same > 1 ? $t . '_' . $mic['id'] : $t;
}
// a WAV cut by a power loss or a crash keeps the planned length in arecord's 44-byte header (2026-11-03 RES-13: 30 min
// announced, 6.4 min there): the real sizes are written once it is no longer being recorded (unchanged for 2 minutes),
// as raw_recording.sh does after Stop, so every player shows and plays the real length
function raw_fix_wav_header($w) {
  $n = @filesize($w);
  if ($n === false || $n <= 44 || @filemtime($w) > time() - 120) return;
  $h = @file_get_contents($w, false, null, 0, 44);
  if ($h === false || strlen($h) < 44 || substr($h, 0, 4) !== 'RIFF' || substr($h, 36, 4) !== 'data') return;
  if (unpack('V', substr($h, 40, 4))[1] === $n - 44) return;
  $f = @fopen($w, 'r+b');
  if (!$f) return;
  fseek($f, 4); fwrite($f, pack('V', $n - 8));
  fseek($f, 40); fwrite($f, pack('V', $n - 44));
  fclose($f);
}
// this microphone's sessions in its folder, newest first: [folder name, WAV paths, bytes, log name]
function raw_sessions($tag, $dir) {
  $out = array();
  foreach (glob($dir . '/*-' . $tag, GLOB_ONLYDIR) ?: array() as $d) {
    $base = basename($d);
    if (!preg_match('/^\d{4}-\d\d-\d\d-\d\dh\d\dm\d\ds-[A-Za-z0-9_-]+-' . preg_quote($tag, '/') . '$/', $base)) continue;
    $wavs = glob($d . '/*.wav') ?: array();
    sort($wavs);
    $size = 0;
    foreach ($wavs as $w) { raw_fix_wav_header($w); $size += filesize($w); }
    $logs = glob($d . '/*.recording') ?: array();
    $out[] = array($base, $wavs, $size, $logs ? basename($logs[0]) : '');
  }
  rsort($out);
  return $out;
}

// the analysis of a session (Analysis section): the External files results beside its WAV files (external_files.py
// run = analyse + import at once, the WAV files kept); a job whose process is gone shows as stopped
function raw_analysis($dir) {
  $st = json_decode((string)@file_get_contents($dir . '/birdnet-external.json'), true);
  if (!is_array($st)) return null;
  $pid = intval(($st['status'] ?? '') === 'importing' ? ($st['import_pid'] ?? 0) : ($st['pid'] ?? 0));
  if (in_array($st['status'] ?? '', array('running', 'importing'), true) && strpos((string)@file_get_contents('/proc/' . $pid . '/cmdline'), 'external_files.py') === false)
    $st['status'] = 'stopped';
  return $st;
}
// the name of the new location a session is imported at: "<YYYY-MM-DD-HHhMMmSSs> import", the start of the recording from
// the session log ("Start of recording:"), else from the session folder's name
// the new location is named exactly as the session (owner 2026-11-03): <start>-<station>-<microphone>
function raw_import_name($sess, $dir = '') {
  return $sess !== '' ? $sess : 'import';
}
// the coordinates the session was recorded at (its log), else the station's
function raw_session_coords($dir, $config) {
  $logs = glob($dir . '/*.recording') ?: array();
  $log = $logs ? (string)@file_get_contents($logs[0]) : '';
  $lat = preg_match('/^LATITUDE\s+(-?[0-9.]+)/m', $log, $m) ? floatval($m[1]) : floatval($config['LATITUDE'] ?? 0);
  $lon = preg_match('/^LONGITUDE\s+(-?[0-9.]+)/m', $log, $m) ? floatval($m[1]) : floatval($config['LONGITUDE'] ?? 0);
  return array($lat, $lon);
}

$devs = mic_devices();
$station_mic = mic_active_id($devs);
$by_id = array();
foreach ($devs as $d) $by_id[$d['id']] = $d;
// the station's microphone first, then the others by card number
usort($devs, function ($a, $b) use ($station_mic) { return ($b['id'] === $station_mic) - ($a['id'] === $station_mic) ?: $a['card'] - $b['card']; });
$mic_in = (string)($_REQUEST['mic'] ?? '');
$mic_ok = preg_match('/^[A-Za-z_][A-Za-z0-9_]{0,31}$/', $mic_in) ? $mic_in : '';

// one file of a session (the links of the Sessions table): only a WAV or the log, inside a session subfolder of that
// microphone's folder; a Range request is answered in part, so the browser's player can seek in a long WAV
if (isset($_GET['file'])) {
  $rel = (string)$_GET['file'];
  $tag = isset($by_id[$mic_ok]) ? raw_tag($by_id[$mic_ok], $devs) : '';
  $f = $tag !== '' ? raw_group($mic_ok)['dir'] . '/' . $rel : '';
  if ($tag === '' || !preg_match('#^[A-Za-z0-9_-]+-' . preg_quote($tag, '#') . '/[A-Za-z0-9_-]+\.(wav|recording)$#', $rel) || !is_file($f)) { http_response_code(404); exit; }
  $wav = substr($rel, -4) === '.wav';
  $size = filesize($f); $from = 0; $to = $size - 1;
  if ($wav && preg_match('/^bytes=(\d*)-(\d*)$/', $_SERVER['HTTP_RANGE'] ?? '', $m) && ($m[1] !== '' || $m[2] !== '')) {
    if ($m[1] === '') { $from = max(0, $size - intval($m[2])); } else { $from = intval($m[1]); if ($m[2] !== '') $to = min($to, intval($m[2])); }
    if ($from > $to) { http_response_code(416); header('Content-Range: bytes */' . $size); exit; }
    http_response_code(206);
    header("Content-Range: bytes $from-$to/$size");
  }
  while (ob_get_level()) ob_end_clean();
  header('Content-Type: ' . ($wav ? 'audio/wav' : 'text/plain; charset=utf-8'));
  header('Accept-Ranges: bytes');
  header('Content-Length: ' . ($to - $from + 1));
  header('Content-Disposition: inline; filename="' . basename($rel) . '"');
  $fh = fopen($f, 'rb'); fseek($fh, $from); $left = $to - $from + 1;
  while ($left > 0 && !feof($fh)) { $chunk = fread($fh, min(1048576, $left)); echo $chunk; $left -= strlen($chunk); flush(); }
  fclose($fh);
  exit;
}

// the actions of one group (its microphone in the form)
$messages = array();
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $mic_ok !== '') {
  $g = raw_group($mic_ok);
  $msg = '';
  if (isset($_POST['raw_save']) && isset($by_id[$mic_ok])) {
    $days = array();
    foreach ($dow as $n => $label) if (isset($_POST['day_' . $n])) $days[] = $n;
    sort($days);
    $rate = intval($_POST['rate'] ?? 48000);
    if (!in_array($rate, raw_rates($by_id[$mic_ok])[1], true)) $rate = 48000;
    // the folder: empty = ~/raw-recording; another one must be a folder the station user can write in
    $dir_in = rtrim(trim((string)($_POST['rawdir'] ?? '')), '/');
    $dir = '';
    $dir_err = '';
    if ($dir_in !== '' && $dir_in !== BS_RAW_REC) {
      $real = realpath($dir_in);
      if ($real === false || !is_dir($real) || !preg_match('#^(/[A-Za-z0-9._ -]+)+$#', $real)) $dir_err = t('str_raw_dir_not_found', array('dir' => $dir_in));
      elseif (trim((string)shell_exec(station_prefix() . 'test -w ' . escapeshellarg($real) . ' && echo ok')) !== 'ok') $dir_err = t('str_raw_dir_not_writable', array('dir' => $real));
      else $dir = $real;
    }
    $all = raw_groups_load();
    $old = $all[$mic_ok] ?? array();
    $all[$mic_ok] = array('enabled' => isset($_POST['enabled']) ? '1' : '0', 'days' => implode(',', $days), 'recurrent' => isset($_POST['recurrent']) ? '1' : '0',
      'start' => hms($_POST['start'] ?? '', '04:00:00'), 'end' => hms($_POST['end'] ?? '', '10:00:00'),
      'segment' => (string)max(1, min(240, intval($_POST['segment'] ?? 30))), 'rate' => (string)$rate,
      'dir' => $dir_err === '' ? $dir : (string)($old['dir'] ?? ''));
    $msg = raw_groups_save($all) ? trim($dir_err . ' ' . trim((string)shell_exec(station_prefix() . escapeshellarg(BS_SCRIPTS . '/raw_recording_cron.sh') . ' 2>&1'))) : t('str_raw_not_saved');
  } elseif (isset($_POST['raw_now']) && isset($by_id[$mic_ok])) {
    shell_exec(station_prefix() . 'nohup ' . escapeshellarg(BS_SCRIPTS . '/raw_recording.sh') . ' ' . escapeshellarg($mic_ok) . ' --now > /dev/null 2>&1 &');
    sleep(1);
    $msg = raw_running($mic_ok) ? t('str_recording_started_now_it_stops_help') : t('str_raw_not_started');
  } elseif (isset($_POST['raw_stop'])) {
    // TERM to this microphone's recorder: it ends the WAV being written and closes the session (station user's processes)
    shell_exec(station_prefix() . 'pkill -f ' . escapeshellarg(preg_quote(BS_SCRIPTS . '/', '') . 'raw_recording\.sh ' . $mic_ok . '( |$)') . ' 2>&1');
    for ($k = 0; $k < 30 && raw_running($mic_ok); $k++) usleep(200000);  // the state below is the real one (up to 6 s)
    $msg = t('str_recording_stopped');
  } elseif (isset($_POST['raw_analyse']) || isset($_POST['raw_acancel'])) {
    // Analysis: the station's model on a session, its detections imported at a new location "<start> import"
    $sess = (string)($_POST['raw_analyse'] ?? $_POST['raw_acancel']);
    $list = isset($by_id[$mic_ok]) ? raw_sessions(raw_tag($by_id[$mic_ok], $devs), $g['dir']) : array();
    $names = array_map(function ($x) { return $x[0]; }, $list);
    $sdir = $g['dir'] . '/' . $sess;
    $engine = escapeshellarg(BS_PYTHON) . ' ' . escapeshellarg(BS_SCRIPTS . '/external_files.py');
    $a = in_array($sess, $names, true) ? raw_analysis($sdir) : null;
    if (!in_array($sess, $names, true)) $msg = t('str_session_not_found');
    elseif (isset($_POST['raw_acancel'])) {
      shell_exec(station_prefix() . $engine . ' cancel ' . escapeshellarg($sdir) . ' > /dev/null 2>&1');
      $msg = t('str_raw_analysis_cancelled');
    } elseif (raw_running($mic_ok) && $names[0] === $sess) $msg = t('str_this_session_is_still_recording');
    elseif ($a && in_array($a['status'] ?? '', array('running', 'paused', 'importing', 'import_paused'), true)) $msg = t('str_raw_analysis_running');
    else {
      // the location: an existing one chosen, else (the default) a new one named as the session, made once
      // (the same name again is the same location)
      $lname = raw_import_name($sess, $g['dir']);
      list($lat, $lon) = raw_session_coords($sdir, $config);
      $locs = locations_load();
      $pick = (string)($_POST['raw_loc'] ?? 'new');
      $same = array_values(array_filter($locs, function ($x) use ($lname) { return mb_strtolower($x['name']) === mb_strtolower($lname); }));
      if ($pick !== 'new') $l = ctype_digit($pick) ? location_by_id($pick) : null;
      elseif ($same) $l = $same[0];
      else {
        // a copy of the station's current location (owner 2026-11-02: its metadata: coordinates, radius, timezone, species
        // list, biome, land cover, theme, Now background...) under its own id and name; without one, the session's
        // coordinates
        $cur = location_current();
        $l = $cur ? $cur : array('lat' => round($lat, 4), 'lon' => round($lon, 4), 'radius_km' => LOCATION_RADIUS_KM,
          'timezone' => timezone_for($lat, $lon)[0], 'species_list' => (string)($config['SPECIES_LIST'] ?? ''));
        unset($l['builtin']);
        $l = array('id' => max(array_merge(array(0), array_map('intval', array_column($locs, 'id')))) + 1, 'name' => $lname) + $l;
        $locs[] = $l;
        if (!locations_save($locs)) $l = null;
      }
      if (!$l) $msg = $pick !== 'new' ? t('str_choose_a_location') : t('str_error_writing_the_locations');
      else {
        shell_exec(station_prefix() . 'nohup nice -n 19 ' . $engine . ' run ' . escapeshellarg($sdir) . ' --lat ' . escapeshellarg((string)$l['lat'])
          . ' --lon ' . escapeshellarg((string)$l['lon']) . ' --loc-id ' . intval($l['id']) . ' --loc-name ' . escapeshellarg($l['name']) . ' > /dev/null 2>&1 &');
        $msg = t('str_raw_analysis_started', array('name' => $l['name']));
      }
    }
  } elseif (isset($_POST['raw_delete'])) {
    // a session's subfolder and every file in it; never the session being recorded (the newest while it runs)
    $sess = (string)$_POST['raw_delete'];
    $list = isset($by_id[$mic_ok]) ? raw_sessions(raw_tag($by_id[$mic_ok], $devs), $g['dir']) : array();
    $names = array_map(function ($x) { return $x[0]; }, $list);
    if (!in_array($sess, $names, true)) $msg = t('str_session_not_found');
    elseif (raw_running($mic_ok) && $names[0] === $sess) $msg = t('str_this_session_is_still_recording');
    else {
      shell_exec(station_prefix() . 'rm -rf -- ' . escapeshellarg($g['dir'] . '/' . $sess) . ' 2>&1');
      $msg = is_dir($g['dir'] . '/' . $sess) ? t('str_session_not_deleted', array('name' => $sess)) : t('str_session_deleted', array('name' => $sess));
    }
  }
  $messages[$mic_ok . (isset($_POST['raw_analyse']) || isset($_POST['raw_acancel']) ? ':an' : '')] = $msg;
}
$channels = max(1, intval($config['CHANNELS'] ?? 1));
$h = function ($s) { return htmlspecialchars((string)$s, ENT_QUOTES); };
?>
<?php // the layout of the other pages (owner 2026-10-20): left-aligned boxes, pill buttons, the state on top, one sentence per line ?>
<style>
.raw { max-width: 900px; margin: 0; text-align: left; padding: 0 12px; }
.raw .box { background: var(--panel, #f4f6f8); border-radius: 10px; padding: 10px 14px; margin: 10px 0; }
.raw .row { display: flex; flex-wrap: wrap; gap: 8px; align-items: center; margin: 8px 0; }
.raw .rawbtn { width: auto; padding: 4px 12px; border-radius: 12px; border: 1px solid var(--accent,#2b5e22); background: #fff; color: var(--accent,#2b5e22); font-weight: 600; cursor: pointer; }
.raw .rawbtn:hover, .raw .rawbtn.main { background: var(--accent,#2b5e22); color: #fff; }
.raw .rawbtn.stop { border-color: #c62828; color: #c62828; } .raw .rawbtn.stop:hover { background: #c62828; color: #fff; }
.raw .small { font-size: 12px; opacity: .8; }
.raw details summary { cursor: pointer; }
.raw img.rawdel { cursor: pointer; max-width: 20px; }
.raw .ratebox { border-top: 1px solid rgba(128,128,128,.3); margin-top: 10px; padding-top: 8px; }
.raw ul.rawfiles { margin: 4px 0 2px; padding-left: 18px; }
.raw ul.rawfiles li { margin: 2px 0; }
.raw .state { font-size: 1.05em; font-weight: 700; }
.raw h2.rawmic { margin: 22px 0 6px; font-size: 1.6em; }
/* one size for the three panels of a group (owner 2026-11-02): titles 1.15em, every explanation small, tables normal */
.raw h3 { font-size: 1.15em; margin: 2px 0 6px; }
.raw p.small, .raw div.small { margin: 4px 0; }
.raw h2.rawmic .small { font-size: .55em; font-weight: normal; }
.raw table.rawsess { margin-top: 8px; }
.raw input[type=time], .raw input[type=number] { padding: 3px 6px; border-radius: 6px; border: 1px solid #bbb; }
</style>
<div class="raw">
<?php // the page's intro once, then one group per microphone: the settings panel and its Sessions (owner 2026-11-02) ?>
<p><?php echo t('str_make_long_unprocessed_wav_help'); ?></p>
<p class="small"><?php echo t('str_raw_groups_info'); ?></p>
<?php if (!$devs) echo '<p><b>' . t('str_raw_no_microphone') . '</b></p>'; ?>
<?php foreach ($devs as $mic) {
  $id = $mic['id'];
  $g = raw_group($id);
  $mine = $id === $station_mic;
  $running = raw_running($id);
  list($own_rates, $rates) = raw_rates($mic);
  $sessions = raw_sessions(raw_tag($mic, $devs), $g['dir']);
  $u = $h($id); ?>
<div class="rawgroup" id="rawg-<?php echo $u; ?>">
<h2 class="rawmic">&#127908; <?php echo $h($mic['name']) . ' <span class="small">(' . ($mine ? t('str_the_stations_microphone_shared') : t('str_raw_own_microphone')) . ')</span>'; ?></h2>
<table class="settingstable"><tr><td>
<form method="POST" action="views.php?view=Raw%20Recording#raw-<?php echo $u; ?>" id="raw-<?php echo $u; ?>">
  <input type="hidden" name="mic" value="<?php echo $u; ?>">
  <div class="box">
    <h3><?php echo t('str_recording_title'); ?></h3>
    <div class="row"><label><input type="checkbox" name="enabled" <?php echo $g['enabled'] ? 'checked' : ''; ?>> <b><?php echo t('str_scheduled_recording_on'); ?></b></label></div>
    <div class="row"><b><?php echo t('str_days_colon'); ?></b>
      <?php foreach ($dow as $n => $label) echo '<label><input type="checkbox" name="day_' . $n . '"' . (in_array((string)$n, $g['days'], true) ? ' checked' : '') . '> ' . $label . '</label>'; ?></div>
    <div class="row" style="margin-bottom:0"><label><input type="checkbox" name="recurrent" <?php echo $g['recurrent'] ? 'checked' : ''; ?>> <?php echo t('str_recurrent'); ?></label><span class="small"><?php echo t('str_default_off'); ?></span></div>
    <div class="small" style="margin:0 0 8px 22px"><?php echo t('str_every_chosen_day'); ?><br><?php echo t('str_untick_only_the_next_one_then_help'); ?></div>
    <div class="row"><label><?php echo t('str_start_title'); ?> <input type="time" step="1" name="start" value="<?php echo $h($g['start']); ?>"></label>
      <label><?php echo t('str_end'); ?> <input type="time" step="1" name="end" value="<?php echo $h($g['end']); ?>"></label>
      <span class="small"><?php echo t('str_an_end_earlier_than_the_start_help'); ?></span></div>
    <div class="row"><label><?php echo t('str_segment_length_max'); ?> <input type="number" name="segment" min="1" max="240" style="width:5em" value="<?php echo $g['segment']; ?>"> <?php echo t('str_minutes_per_wav_file'); ?></label></div>
    <?php // the folder: ~/raw-recording by default, or a mounted drive or network share (the NAS) ?>
    <div class="ratebox">
      <div class="row" style="margin-bottom:2px"><b><?php echo t('str_folder'); ?></b>
        <input type="text" name="rawdir" class="rawdir" aria-label="<?php echo $h(t('str_folder')); ?>" value="<?php echo $h($g['dir']); ?>" style="flex:1;min-width:16em;font-family:monospace" spellcheck="false">
        <button type="button" class="rawbtn" onclick="rawPick(this)">&#128193; <?php echo t('str_choose_folder_btn'); ?></button>
        <button type="button" class="rawbtn" onclick="this.form.rawdir.value = <?php echo $h(json_encode(BS_RAW_REC)); ?>"><?php echo t('str_default_button'); ?></button></div>
      <div class="rawdirs" style="display:none"></div>
      <div class="small"><?php echo t('str_raw_dir_info', array('dir' => $h(BS_RAW_REC))); ?></div>
    </div>
    <?php // the sample rate: up to the microphone's own (the station's is converted by its shared device) ?>
    <div class="ratebox">
      <div class="row" style="margin-bottom:2px"><b><?php echo t('str_sample_rate'); ?></b>
        <select name="rate" aria-label="<?php echo $h(t('str_sample_rate')); ?>"><?php foreach ($rates as $v) echo '<option value="' . $v . '"' . ($v === $g['rate'] ? ' selected' : '') . '>' . raw_khz($v) . (in_array($v, $own_rates, true) ? ' ★' : '') . '</option>'; ?></select>
        <span class="small"><?php echo t('str_raw_rate_size', array('mb' => round($g['rate'] * ((string)(get_config()['REC_BITS'] ?? '24') === '16' ? 2 : 3) * $channels * 3600 / 1048576))); ?></span></div>
      <div class="small"><?php echo t('str_raw_rate_own', array('rates' => $own_rates ? implode(', ', array_map('raw_khz', $own_rates)) : '?')); ?><br>
        <?php echo $mine ? t('str_raw_rate_info') : t('str_raw_rate_info_own'); ?></div>
    </div>
    <?php // the schedule with its button and state, then the recording with its button and state (owner 2026-11-02);
    // one recording button: Record now while it does not record, Stop recording while it does
    $sched_vars = $g['enabled'] && $g['days'] ? array('start' => $h($g['start']), 'end' => $h($g['end']), 'days' => $h(implode(', ', array_map(function ($d) use ($dow) { return $dow[intval($d)]; }, $g['days'])))) : array(); ?>
    <div class="row" style="margin-bottom:2px"><button type="submit" class="rawbtn main" name="raw_save" value="1"><?php echo t('str_save_schedule'); ?></button></div>
    <div class="state"><?php echo t('str_raw_schedule_line', array('schedule' => $sched_vars ? ($g['recurrent'] ? t('str_on_to_on_every_week', $sched_vars) : t('str_on_to_on_once', $sched_vars)) : t('str_off_lc'))); ?></div>
    <div class="row" style="margin:14px 0 2px"><?php if ($running) { ?><button type="submit" class="rawbtn stop" name="raw_stop" value="1"><?php echo t('str_stop_recording'); ?></button>
      <?php } else { ?><button type="submit" class="rawbtn" name="raw_now" value="1" title="<?php echo $h(t('str_after_the_end_time_for_as_long_help')); ?>"><?php echo t('str_record_now_until_the_end_time'); ?></button><?php } ?></div>
    <div class="state"><?php echo t('str_raw_now_line', array('state' => $running ? t('str_raw_recording_now') : t('str_no_raw_recording'))); ?></div>
    <?php if (($messages[$id] ?? '') !== '') echo '<div class="small" style="margin-top:4px">' . $h($messages[$id]) . '</div>'; ?>
    <?php if ($mine) { ?><div class="small" style="margin-top:14px"><?php echo t('str_the_stations_normal_recording_help'); ?></div><?php } ?>
  </div>
</form>
</td></tr></table>
<table class="settingstable rawsess"><tr><td><div class="box">
<h3><?php echo t('str_sessions'); ?></h3>
<p class="small"><?php echo t('str_raw_recordings_folder_help', array('dir' => $h($g['dir']))); ?></p>
<?php if (!$sessions) { echo '<p class="small">' . t('str_no_raw_recordings_yet') . '</p>'; } else { ?>
<table class="stdtable"><tr><th><?php echo t('str_session'); ?></th><th class="num"><?php echo t('str_wav_files'); ?></th><th class="num"><?php echo t('str_size'); ?></th><th></th></tr>
<?php // a session opens its files below it: each WAV and the log open in the browser (?mic=&file=), the WAV in its player
foreach (array_slice($sessions, 0, 30) as $s) {
  $link = function ($name) use ($id, $s) { return 'scripts/raw_recording.php?mic=' . rawurlencode($id) . '&amp;file=' . rawurlencode($s[0]) . '/' . rawurlencode($name); };
  $files = '';
  foreach ($s[1] as $w) $files .= '<li><a href="' . $link(basename($w)) . '" target="_blank" rel="noopener">' . $h(basename($w)) . '</a> <span class="small">' . round(filesize($w) / 1048576) . ' MB</span></li>';
  if ($s[3] !== '') $files .= '<li><a href="' . $link($s[3]) . '" target="_blank" rel="noopener">' . $h($s[3]) . '</a> <span class="small">' . t('str_session_log') . '</span></li>';
  echo '<tr><td><details><summary>' . $h($s[0]) . '</summary><div class="small">' . $h($g['dir'] . '/' . $s[0] . '/') . '</div><ul class="rawfiles">' . $files . '</ul></details></td>'
    . '<td class="num">' . count($s[1]) . '</td><td class="num">' . round($s[2] / 1048576) . ' MB</td>'
    . '<td><img class="rawdel" src="images/delete.svg" alt="' . $h(t('str_delete')) . '" title="' . $h(t('str_delete_this_session_and_its_files')) . '" data-mic="' . $u . '" data-session="' . $h($s[0]) . '" data-files="' . (count($s[1]) + ($s[3] !== '' ? 1 : 0)) . '" data-mb="' . round($s[2] / 1048576) . '" onclick="rawDelete(this)"></td></tr>';
} ?>
</table>
<?php } ?>
</div></td></tr></table>
<?php // Analysis (owner 2026-11-02): the station's model run on a session, by itself: a location "<start> import" is made at
// the session's coordinates and its detections are imported there with their clips and spectrograms, the WAV files stay
$done = array();
foreach ($sessions as $s) { $a = raw_analysis($g['dir'] . '/' . $s[0]); if ($a) $done[$s[0]] = $a; }
if (array_filter($done, function ($a) { return in_array($a['status'] ?? '', array('running', 'importing'), true); })) $raw_reload = true; ?>
<table class="settingstable rawsess"><tr><td><div class="box">
<h3><?php echo t('str_process_and_import_raw_recordings'); ?></h3>
<p class="small"><?php echo t('str_raw_analysis_info', array('link' => '<a href="views.php?view=Data">' . t('str_tools_data_move_to_location') . '</a>')); ?></p>
<?php if (!$sessions) { echo '<p class="small">' . t('str_no_raw_recordings_yet') . '</p>'; } else { ?>
<form method="POST" action="views.php?view=Raw%20Recording#raw-an-<?php echo $u; ?>" id="raw-an-<?php echo $u; ?>" class="row">
  <input type="hidden" name="mic" value="<?php echo $u; ?>">
  <select name="raw_analyse" aria-label="<?php echo $h(t('str_session')); ?>" onchange="rawLocName(this)"><?php foreach ($sessions as $k => $s) {
    if ($k === 0 && $running) continue;
    $a = $done[$s[0]] ?? null;
    echo '<option value="' . $h($s[0]) . '" data-new="' . $h(raw_import_name($s[0], $g['dir'])) . '">' . $h($s[0]) . ($a && ($a['status'] ?? '') === 'imported' ? ' ✓' : '') . '</option>';
  } ?></select>
  <?php // where the detections go: a new location "<start> import" (the default, its name follows the session) or an existing one ?>
  <label><?php echo t('str_location'); ?> <select name="raw_loc" class="rawloc"><?php
    $first = null;
    foreach ($sessions as $k => $s) { if ($k === 0 && $running) continue; $first = $s[0]; break; }
    echo '<option value="new" selected>' . $h(t('str_new_location_named', array('name' => raw_import_name((string)$first, $g['dir'])))) . '</option>';
    foreach (locations_load() as $l) echo '<option value="' . intval($l['id']) . '">' . $h($l['name']) . '</option>'; ?></select></label>
  <button type="submit" class="rawbtn main"><?php echo t('str_analyse_and_import'); ?></button>
</form>
<?php if (($messages[$id . ':an'] ?? '') !== '') echo '<div><b>' . $h($messages[$id . ':an']) . '</b></div>'; ?>
<?php if ($done) { ?>
<table class="stdtable"><tr><th><?php echo t('str_session'); ?></th><th><?php echo t('str_status'); ?></th><th class="num"><?php echo t('str_detections'); ?></th><th><?php echo t('str_location'); ?></th><th></th></tr>
<?php foreach ($done as $sn => $a) {
  $stt = $a['status'] ?? '';
  $p = $a['progress'] ?? array(); $ip = $a['import_progress'] ?? array();
  if ($stt === 'running') $txt = t('str_raw_analysing', array('k' => min(intval($p['files_done'] ?? 0) + 1, max(1, intval($p['files_total'] ?? 1))), 'n' => intval($p['files_total'] ?? 0)));
  elseif ($stt === 'importing') $txt = t('str_raw_importing', array('k' => intval($ip['done'] ?? 0), 'n' => intval($ip['total'] ?? 0)));
  elseif ($stt === 'imported') $txt = t('str_raw_imported_at', array('at' => $h($a['imported']['at'] ?? '')));
  elseif ($stt === 'cancelled') $txt = t('str_cancelled');
  elseif ($stt === 'stopped') $txt = t('str_raw_analysis_stopped');
  else $txt = $h($stt);
  $n = $stt === 'imported' ? intval($a['imported']['added'] ?? 0) : intval($a['detections'] ?? 0);
  $loc = $a['location'] ?? array();
  echo '<tr><td>' . $h($sn) . '</td><td>' . $txt . '</td><td class="num">' . $n . '</td><td>'
    . (!empty($loc['id']) ? '<a href="views.php?view=Latest&amp;loc=' . intval($loc['id']) . '">' . $h($loc['name'] ?? '') . '</a>' : '') . '</td><td>'
    . (in_array($stt, array('running', 'importing'), true) ? '<form method="POST" action="views.php?view=Raw%20Recording#raw-an-' . $u . '" style="margin:0"><input type="hidden" name="mic" value="' . $u . '"><button class="rawbtn stop" name="raw_acancel" value="' . $h($sn) . '">' . t('str_cancel') . '</button></form>' : '')
    . '</td></tr>';
} ?>
</table>
<?php } ?>
<?php } ?>
</div></td></tr></table>
</div>
<?php } ?>
<p class="small"><?php echo t('str_raw_recordings_are_not_deleted_disk_help'); ?> <a href="views.php?view=Disk%20Space"><?php echo t('str_disk_space'); ?></a></p>
</div>
<?php // while an analysis runs the page refreshes itself every 10 s (its progress) ?>
<?php if (!empty($raw_reload)) { ?><script data-rawreload>window.rawTimer = setTimeout(function () { location.href = 'views.php?view=Raw%20Recording' + location.hash; }, 10000);  // a GET: a reload after a POST would send the form again</script><?php } ?>
<script src="static/species-modal.js?v=<?php echo @filemtime(__DIR__ . '/../homepage/static/species-modal.js'); ?>"></script>
<script src="static/folder-picker.js?v=<?php echo @filemtime(__DIR__ . '/../homepage/static/folder-picker.js'); ?>"></script>
<script>
// the folder tree of a group (static/folder-picker.js: home, USB disks and network shares as shortcuts) under its field
function rawPick(btn) {
  var box = btn.closest('.ratebox'), el = box.querySelector('.rawdirs'), inp = box.querySelector('.rawdir');
  el.style.display = el.style.display === 'none' ? '' : 'none';
  if (!el.dataset.on && el.style.display === '') { el.dataset.on = '1'; folderPicker(el, inp.value, function (path) { inp.value = path; }); }
}
// the new location's name follows the session chosen
function rawLocName(sel) {
  var o = sel.options[sel.selectedIndex], nw = sel.form.querySelector('.rawloc option[value="new"]');
  if (o && nw) nw.textContent = t('str_new_location_named', {name: o.dataset.new});
}
// every action of a group (Save schedule, Record now / Stop, Analyse, Cancel, delete) is posted in the background and
// only that microphone's group is redrawn from the answer (owner 2026-11-02: the whole page flashed on Record now)
function rawPost(fd, mic, btn) {
  if (btn) btn.disabled = true;
  return fetch('views.php?view=Raw%20Recording', {method: 'POST', body: fd, credentials: 'same-origin'}).then(function (r) { return r.text(); }).then(function (txt) {
    var doc = new DOMParser().parseFromString(txt, 'text/html'), id = 'rawg-' + mic;
    var fresh = doc.getElementById(id), old = document.getElementById(id);
    if (fresh && old) old.innerHTML = fresh.innerHTML; else location.href = 'views.php?view=Raw%20Recording';
    if (doc.querySelector('script[data-rawreload]') && !window.rawTimer) window.rawTimer = setTimeout(function () { location.href = 'views.php?view=Raw%20Recording'; }, 10000);
  }).catch(function () { location.href = 'views.php?view=Raw%20Recording'; });
}
document.addEventListener('submit', function (e) {
  var f = e.target, g = f.closest('.rawgroup'); if (!g) return;
  e.preventDefault();
  var fd = new FormData(f);
  if (e.submitter && e.submitter.name) fd.append(e.submitter.name, e.submitter.value);
  rawPost(fd, g.id.slice(5), e.submitter);
});
// the delete icon of a session: asks first (No is the default), then posts raw_delete with its microphone
function rawDelete(img) {
  var d = img.dataset;
  askModal(t('str_delete_session_q', {name: d.session}), t('str_delete_session_help', {n: d.files, mb: d.mb}), t('str_delete'), true).then(function (ok) {
    if (!ok) return;
    var fd = new FormData(); fd.append('raw_delete', d.session); fd.append('mic', d.mic);
    rawPost(fd, d.mic, null);
  });
}
</script>
<script src="static/std-table.js?v=<?php echo @filemtime(__DIR__ . "/../homepage/static/std-table.js"); ?>"></script>
