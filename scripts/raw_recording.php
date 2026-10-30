<?php
/* Raw Recording page (owner 2026-10-08): scheduled long, unprocessed recordings (dawn chorus) from the shared
 * microphone, in parallel with the analysis — days of the week, recurrent or once, start and end time, segment
 * length. Settings in BirdSuite.conf (RAW_REC_*), schedule in the station user's crontab
 * (raw_recording_cron.sh), sessions by raw_recording.sh into ~/BirdNET-Pi/raw-recording/. */
require_once __DIR__ . '/common.php';
ensure_authenticated();
$home = get_home();
$user = get_user();
$config = get_config();
$conf_file = BS_CONF;
$out_dir = $home . '/BirdNET-Pi/raw-recording';
$dow = array(1 => t('Mon'), 2 => t('Tue'), 3 => t('Wed'), 4 => t('Thu'), 5 => t('Fri'), 6 => t('Sat'), 0 => t('Sun'));
$message = '';

function set_conf_key($contents, $key, $value, $comment) {
  if (preg_match("/^$key=/m", $contents)) return preg_replace("/^$key=.*/m", "$key=$value", $contents);
  return $contents . "\n## $comment\n$key=$value\n";
}
function hms($v, $default) {
  return preg_match('/^([01]\d|2[0-3]):[0-5]\d(:[0-5]\d)?$/', (string)$v) ? (strlen($v) == 5 ? "$v:00" : $v) : $default;
}

if (isset($_POST['raw_save'])) {
  $enabled = isset($_POST['enabled']) ? 1 : 0;
  $days = array();
  foreach ($dow as $n => $label) if (isset($_POST['day_' . $n])) $days[] = $n;
  $recurrent = isset($_POST['recurrent']) ? 1 : 0;
  $start = hms($_POST['start'] ?? '', '04:00:00');
  $end = hms($_POST['end'] ?? '', '10:00:00');
  $segment = max(1, min(240, intval($_POST['segment'] ?? 30)));
  $c = file_get_contents($conf_file);
  $c = set_conf_key($c, 'RAW_REC_ENABLED', $enabled, 'RAW_REC_*: scheduled raw recording (Raw Recording page); 1 = on');
  $c = set_conf_key($c, 'RAW_REC_DAYS', '"' . implode(',', $days) . '"', 'days of the week to record (cron numbers, 0 = Sunday)');
  $c = set_conf_key($c, 'RAW_REC_RECURRENT', $recurrent, '1 = every chosen day, 0 = only the next one, then off');
  $c = set_conf_key($c, 'RAW_REC_START', $start, 'start time HH:MM:SS');
  $c = set_conf_key($c, 'RAW_REC_END', $end, 'end time HH:MM:SS (next day when earlier than the start)');
  $c = set_conf_key($c, 'RAW_REC_SEGMENT_MIN', $segment, 'maximum length of each WAV file in minutes');
  file_put_contents($conf_file, $c);
  $message = trim((string)shell_exec(station_prefix() . escapeshellarg($home . '/BirdNET-Pi/scripts/raw_recording_cron.sh') . ' 2>&1'));
  $config = get_config(true);
} elseif (isset($_POST['raw_now'])) {
  shell_exec(station_prefix() . 'nohup ' . escapeshellarg($home . '/BirdNET-Pi/scripts/raw_recording.sh') . ' --now > /dev/null 2>&1 &');
  $message = t('Recording started now; it stops at the end time.');
} elseif (isset($_POST['raw_stop'])) {
  // the station user's own processes: no root needed
  shell_exec(station_prefix() . 'pkill -f ' . escapeshellarg('BirdNET-Pi/scripts/raw_recording.sh') . ' 2>&1; ' . station_prefix() . 'pkill -f ' . escapeshellarg('arecord .*raw-recording') . ' 2>&1');
  $message = t('Recording stopped.');
}

$enabled = ($config['RAW_REC_ENABLED'] ?? '0') == '1';
$days = array_filter(explode(',', trim($config['RAW_REC_DAYS'] ?? '', '"')), 'strlen');
$recurrent = ($config['RAW_REC_RECURRENT'] ?? '0') == '1'; // off by default (owner 2026-10-20)
$start = hms($config['RAW_REC_START'] ?? '', '04:00:00');
$end = hms($config['RAW_REC_END'] ?? '', '10:00:00');
$segment = intval($config['RAW_REC_SEGMENT_MIN'] ?? 30) ?: 30;
// [r]aw: the shell that runs pgrep holds the pattern too; the brackets keep it from matching itself (it always said "recording")
$running = trim((string)shell_exec("pgrep -f 'BirdNET-Pi/scripts/[r]aw_recording.sh' 2>/dev/null")) !== '';

// sessions: the .recording logs, newest first, with their WAV files
$sessions = array();
foreach (glob($out_dir . '/*.recording') ?: array() as $log) {
  $base = basename($log, '.recording');
  $day = substr($base, 0, 10);
  $wavs = glob($out_dir . '/' . $day . '-*.wav') ?: array();
  $size = 0;
  foreach ($wavs as $w) $size += filesize($w);
  $sessions[] = array($base, count($wavs), $size);
}
rsort($sessions);
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
.raw a.folderbtn { display: inline-block; margin-top: 8px; padding: 3px 10px; border-radius: 12px; border: 1px solid var(--accent,#2b5e22); background: #fff; color: var(--accent,#2b5e22); font-weight: 600; text-decoration: none; white-space: nowrap; }
.raw a.folderbtn:hover { background: var(--accent,#2b5e22); color: #fff; }
.raw .state { font-size: 1.05em; font-weight: 700; }
.raw input[type=time], .raw input[type=number] { padding: 3px 6px; border-radius: 6px; border: 1px solid #bbb; }
</style>
<div class="raw">
<?php // owner's layout (2026-10-20): what it does, the files, the schedule, the buttons in one row, the status last;
// each part a panel with its title inside, as Settings › Model (owner 2026-10-24) ?>
<table class="settingstable"><tr><td>
<h2><?php echo t('Raw Recording'); ?></h2>
<p><?php echo t('Make long, unprocessed WAV recordings (e.g. the whole dawn chorus) while the analysis keeps running.'); ?></p>
<p><?php echo t('Files:'); ?> <code>~/BirdNET-Pi/raw-recording/YYYY-MM-DD-&lt;time&gt;-&lt;station&gt;.wav</code> (48 kHz, 16-bit), <?php echo t('with a <code>.recording</code> session log.'); ?><br>
  <?php // Tools › Browse files (owner 2026-10-24: the sftp:// link was refused by the browsers, port 22) ?>
  <a class="folderbtn" href="views.php?view=Files" title="<?php echo $h(t('Midnight Commander, after a login with your password')); ?>">&#128194; <?php echo t('Browse files'); ?></a></p>
<form method="POST" action="views.php?view=Raw%20Recording">
  <div class="box">
    <div class="row"><label><input type="checkbox" name="enabled" <?php echo $enabled ? 'checked' : ''; ?>> <b><?php echo t('Scheduled recording on'); ?></b></label></div>
    <div class="row"><b><?php echo t('Days:'); ?></b>
      <?php foreach ($dow as $n => $label) {
        echo '<label><input type="checkbox" name="day_' . $n . '"' . (in_array((string)$n, $days, true) ? ' checked' : '') . '> ' . $label . '</label>';
      } ?></div>
    <div class="row" style="margin-bottom:0"><label><input type="checkbox" name="recurrent" <?php echo $recurrent ? 'checked' : ''; ?>> <?php echo t('Recurrent'); ?></label><span class="small"><?php echo t('(default off)'); ?></span></div>
    <div class="small" style="margin:0 0 8px 22px"><?php echo t('every chosen day;'); ?><br><?php echo t('untick = only the next one, then off'); ?></div>
    <div class="row"><label><?php echo t('Start'); ?> <input type="time" step="1" name="start" value="<?php echo $h($start); ?>"></label>
      <label><?php echo t('End'); ?> <input type="time" step="1" name="end" value="<?php echo $h($end); ?>"></label>
      <span class="small"><?php echo t('(an end earlier than the start ends the next day)'); ?></span></div>
    <div class="row"><label><?php echo t('Segment length (max)'); ?> <input type="number" name="segment" min="1" max="240" style="width:5em" value="<?php echo $segment; ?>"> <?php echo t('minutes per WAV file'); ?></label></div>
    <div class="row"><button type="submit" class="rawbtn main" name="raw_save" value="1"><?php echo t('Save schedule'); ?></button>
      <button type="submit" class="rawbtn" name="raw_now" value="1" title="<?php echo $h(t('After the end time: for as long as the window from Start to End lasts')); ?>"><?php echo t('Record now (until the end time)'); ?></button>
      <?php if ($running) { ?><button type="submit" class="rawbtn stop" name="raw_stop" value="1"><?php echo t('Stop recording'); ?></button><?php } ?></div>
    <?php if ($message !== '') echo '<div><b>' . $h($message) . '</b></div>'; ?>
    <?php // the status names the raw recording: the station's own recording for the analysis goes on in any case ?>
    <div class="state" style="margin-top:6px"><?php
      $sched_vars = $enabled && $days ? array('start' => $h($start), 'end' => $h($end), 'days' => $h(implode(', ', array_map(function ($d) use ($dow) { return $dow[intval($d)]; }, $days)))) : array();
      echo t('Status: {state}; schedule {schedule}.', array('state' => $running ? t('raw recording now') : t('no raw recording'),
        'schedule' => $sched_vars ? ($recurrent ? t('on, {start} to {end} on {days} (every week)', $sched_vars) : t('on, {start} to {end} on {days} (once)', $sched_vars)) : t('off'))); ?></div>
    <div class="small"><?php echo t('The station\'s normal recording for the analysis goes on either way.'); ?></div>
  </div>
</form>
</td></tr></table><br>
<table class="settingstable"><tr><td>
<h2><?php echo t('Sessions'); ?></h2>
<?php if (!$sessions) { echo '<p>' . t('No raw recordings yet.') . '</p>'; } else { ?>
<table class="stdtable"><tr><th><?php echo t('Session'); ?></th><th class="num"><?php echo t('WAV files (day)'); ?></th><th class="num"><?php echo t('Size'); ?></th></tr>
<?php foreach (array_slice($sessions, 0, 30) as $s) {
  echo '<tr><td>' . $h($s[0]) . '</td><td class="num">' . $s[1] . '</td><td class="num">' . round($s[2] / 1048576) . ' MB</td></tr>';
} ?>
</table>
<?php } ?>
<p class="small"><?php echo t('Raw recordings are not deleted automatically; keep an eye on the disk space (System › Status).'); ?></p>
</td></tr></table>
</div>
<script src="static/std-table.js?v=<?php echo @filemtime(__DIR__ . "/../homepage/static/std-table.js"); ?>"></script>
