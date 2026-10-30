<?php
/* eBird (owner 2026-10-27): the species of a day, a week or a month, one column per day with the day's weather, with a
 * filter (all detections, only the confirmed ones, or from a minimum confidence), the eBird file of what is shown
 * (eBird Record Format, one checklist per day and location; scripts/ebird_report.py), a button that e-mails it, and the
 * weekly schedule with its own filter. Opened as views.php?view=eBird. */
require_once __DIR__ . '/common.php';
$h = function ($s) { return htmlspecialchars((string)$s, ENT_QUOTES); };
$config = get_config();

function eb_run($args) {
  $cmd = station_prefix() . escapeshellarg(get_home() . '/BirdNET-Pi/birdnet/bin/python3') . ' '
    . escapeshellarg(__DIR__ . '/ebird_report.py') . ' ' . implode(' ', array_map('escapeshellarg', $args)) . ' 2>/dev/null';
  return (string)shell_exec($cmd);
}
$valid_date = function ($d) { return is_string($d) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) && strtotime($d) !== false; };
// the filter of a request: mode (all / confirmed / minconf) and the minimum confidence
$req_mode = function ($v) { return in_array($v, array('all', 'confirmed', 'minconf'), true) ? $v : 'confirmed'; };
$req_min = function ($v) { return is_numeric($v) ? round(max(0, min(0.99, floatval($v))), 2) : 0.7; };
// the species unticked in the table (owner 2026-10-27): scientific names joined by |, left out of the file and the e-mail
$req_skip = function ($v) {
  $out = array();
  foreach (explode('|', (string)$v) as $sci) { $sci = trim($sci); if (preg_match('/^[\p{L}][\p{L} .()×\-]{1,80}$/u', $sci)) $out[] = $sci; }
  return implode('|', array_slice(array_unique($out), 0, 2000));
};

// the eBird file of the days shown (scripts/ebird_page.php?csv=1&from=&to=&mode=&min=, outside the page frame)
if (isset($_GET['csv'])) {
  ensure_authenticated(t('You must be authenticated to download the eBird file.'));  // it carries the coordinates
  $from = $_GET['from'] ?? ''; $to = $_GET['to'] ?? $from;
  if (!$valid_date($from) || !$valid_date($to)) { http_response_code(400); die('bad dates'); }
  $fname = trim(eb_run(array('name', $from, $to)));
  header('Content-Type: text/csv; charset=utf-8');
  header('Content-Disposition: attachment; filename="' . (preg_match('/^[\w.\- ]+\.csv$/u', $fname) ? $fname : 'ebird-' . $from . '.csv') . '"');
  echo eb_run(array('csv', $from, $to, $req_mode($_GET['mode'] ?? ''), (string)$req_min($_GET['min'] ?? ''), $req_skip($_GET['skip'] ?? '')));
  die();
}
// the sounds for eBird (owner 2026-10-27): a zip with the n best clips (asked, 5 by default) confirmed by ear of every bird of the days shown
// (whatever the filter), named
// date_time_common_scientific_confidence, to add to the imported checklists with eBird's Add media
if (isset($_GET['sounds'])) {
  ensure_authenticated(t('You must be authenticated to download the eBird file.'));
  $from = $_GET['from'] ?? ''; $to = $_GET['to'] ?? $from;
  if (!$valid_date($from) || !$valid_date($to)) { http_response_code(400); die('bad dates'); }
  $list = json_decode(eb_run(array('clips', $from, $to, $req_mode($_GET['mode'] ?? ''), (string)$req_min($_GET['min'] ?? ''), $req_skip($_GET['skip'] ?? ''))), true) ?: array();
  $root = get_home() . '/BirdSongs/Extracted/By_Date/';
  $tmp = tempnam(sys_get_temp_dir(), 'ebsounds');
  $zip = new ZipArchive();
  if ($zip->open($tmp, ZipArchive::OVERWRITE) !== true) { http_response_code(500); die('zip'); }
  $count = array(); $added = 0; $wavs = array();
  $per = max(1, min(20, intval($_GET['n'] ?? 5)));  // asked when the button is pressed (owner 2026-10-27)
  foreach ($list as $c) {
    if (($count[$c['sci']] ?? 0) >= $per) continue;
    $rel = $c['date'] . '/' . species_folder($c['sci'], $c['com'], $c['file']) . '/' . $c['file'];
    $path = is_file($root . $rel) ? $root . $rel : (is_file($root . 'shifted/' . $rel) ? $root . 'shifted/' . $rel : '');
    if ($path === '') continue;  // cleaned up by the nightly purge
    // WAV, the format the Macaulay Library takes for sure (the clips are FLAC or MP3); the clip as it is if sox fails
    $name = $c['date'] . '_' . str_replace(':', 'h', substr($c['time'], 0, 5)) . '_' . name_safe($c['com']) . '_' . name_safe($c['sci']) . '_'
      . sprintf('%.2f', $c['conf']);
    $wav = tempnam(sys_get_temp_dir(), 'ebwav') . '.wav';
    exec('sox ' . escapeshellarg($path) . ' -b 16 ' . escapeshellarg($wav) . ' 2>/dev/null', $o, $rc);
    if ($rc === 0 && is_file($wav)) { $wavs[] = $wav; $name .= '.wav'; $zip->addFile($wav, $name); }
    else { $name .= '.' . pathinfo($c['file'], PATHINFO_EXTENSION); $zip->addFile($path, $name); }
    $zip->setCompressionName($name, ZipArchive::CM_STORE);  // audio does not compress
    $count[$c['sci']] = ($count[$c['sci']] ?? 0) + 1; $added++;
  }
  if (!$added) $zip->addFromString('README.txt', t('No sound confirmed by ear is left on the disk for this period.'));
  $zip->close();
  foreach ($wavs as $w) { @unlink($w); @unlink(substr($w, 0, -4)); }
  $fname = preg_replace('/\.csv$/', '', trim(eb_run(array('name', $from, $to))));
  header('Content-Type: application/zip');
  header('Content-Disposition: attachment; filename="' . (preg_match('/^[\w.\- ]+$/u', $fname) ? $fname : 'ebird-' . $from) . '_sounds.zip"');
  header('Content-Length: ' . filesize($tmp));
  readfile($tmp);
  @unlink($tmp);
  die();
}
// the weekly schedule: on/off, time, its filter and the report's name
if (isset($_GET['save'])) {
  ensure_authenticated(t('You must be authenticated to change the settings.'));
  $time = (string)($_GET['time'] ?? '');
  $name = trim(str_replace(array('"', "\r", "\n", '$', '\\'), '', (string)($_GET['name'] ?? '')));
  if (!preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $time) || mb_strlen($name) > 100) { http_response_code(400); die('bad value'); }
  $c = (string)file_get_contents(BS_CONF);
  $day = max(1, min(7, intval($_GET['day'] ?? 1)));
  foreach (array('EBIRD_REPORT_ENABLED' => !empty($_GET['on']) ? '1' : '0', 'EBIRD_REPORT_DAY' => (string)$day, 'EBIRD_REPORT_TIME' => $time,
                 'EBIRD_REPORT_SPECIES' => $req_mode($_GET['mode'] ?? ''), 'EBIRD_REPORT_MIN_CONF' => (string)$req_min($_GET['min'] ?? ''),
                 'EBIRD_REPORT_NAME' => '"' . $name . '"') as $k => $v) {
    $c = preg_match("/^$k=/m", $c) ? preg_replace("/^$k=.*/m", str_replace('\\', '\\\\', "$k=$v"), $c) : rtrim($c, "\n") . "\n$k=$v\n";
  }
  header('Content-Type: text/plain');
  echo file_put_contents(BS_CONF, $c) !== false ? 'OK' : t('not saved');
  die();
}
// "Set as default" (owner 2026-10-27, as on the Latest page): the filter and the minimum confidence the page opens with
if (isset($_GET['pagedefault'])) {
  ensure_authenticated(t('You must be authenticated to change the settings.'));
  $c = (string)file_get_contents(BS_CONF);
  foreach (array('EBIRD_PAGE_MODE' => $req_mode($_GET['mode'] ?? ''), 'EBIRD_PAGE_MIN_CONF' => (string)$req_min($_GET['min'] ?? '')) as $k => $v) {
    $c = preg_match("/^$k=/m", $c) ? preg_replace("/^$k=.*/m", "$k=$v", $c) : rtrim($c, "\n") . "\n$k=$v\n";
  }
  header('Content-Type: text/plain');
  echo file_put_contents(BS_CONF, $c) !== false ? 'OK' : t('not saved');
  die();
}
// "Send by e-mail": the report on screen, with its filter
if (isset($_GET['send'])) {
  ensure_authenticated(t('You must be authenticated to change the settings.'));
  $from = $_GET['from'] ?? ''; $to = $_GET['to'] ?? $from;
  header('Content-Type: text/plain');
  if (!$valid_date($from) || !$valid_date($to)) { echo 'bad dates'; die(); }
  echo trim(eb_run(array('send', $from, $to, $req_mode($_GET['mode'] ?? ''), (string)$req_min($_GET['min'] ?? ''), $req_skip($_POST['skip'] ?? $_GET['skip'] ?? '')))) ?: t('no answer');
  die();
}

$wd = array(1 => t('Monday'), 2 => t('Tuesday'), 3 => t('Wednesday'), 4 => t('Thursday'), 5 => t('Friday'), 6 => t('Saturday'), 7 => t('Sunday'));
// the page's filter, in the address (&period= &date= &mode= &min=); it starts from the page's defaults
$period = in_array($_GET['period'] ?? '', array('day', 'week', 'month'), true) ? $_GET['period'] : 'week';
$s_mode = $req_mode(trim((string)($config['EBIRD_REPORT_SPECIES'] ?? ''), "\"' "));
$s_min = $req_min(trim((string)($config['EBIRD_REPORT_MIN_CONF'] ?? ''), "\"' "));
$p_mode = $req_mode(trim((string)($config['EBIRD_PAGE_MODE'] ?? ''), "\"' "));
$p_min = $req_min(trim((string)($config['EBIRD_PAGE_MIN_CONF'] ?? ''), "\"' "));
$mode = isset($_GET['mode']) ? $req_mode($_GET['mode']) : $p_mode;
$min = isset($_GET['min']) ? $req_min($_GET['min']) : $p_min;
$any = $_GET['date'] ?? date('Y-m-d');
if (!$valid_date($any)) $any = date('Y-m-d');
$t0 = strtotime($any);
if ($period === 'day') {
  $first = $last = date('Y-m-d', $t0);
  $title = $wd[intval(date('N', $t0))] . ', ' . date('d/m/Y', $t0);
  $prev = date('Y-m-d', strtotime($first . ' -1 day')); $next = date('Y-m-d', strtotime($first . ' +1 day'));
} elseif ($period === 'month') {
  $first = date('Y-m-01', $t0); $last = date('Y-m-t', $t0);
  $title = t(date('F', $t0)) . ' ' . date('Y', $t0);
  $prev = date('Y-m-d', strtotime($first . ' -1 month')); $next = date('Y-m-d', strtotime($first . ' +1 month'));
} else {
  $first = date('Y-m-d', strtotime(date('Y-m-d', $t0) . ' -' . (intval(date('N', $t0)) - 1) . ' days'));
  $last = date('Y-m-d', strtotime($first . ' +6 days'));
  $title = t('Week {n}', array('n' => date('W', strtotime($first)))) . ' · ' . date('d/m', strtotime($first)) . ' – ' . date('d/m/Y', strtotime($last));
  $prev = date('Y-m-d', strtotime($first . ' -7 days')); $next = date('Y-m-d', strtotime($first . ' +7 days'));
}
$data = json_decode(eb_run(array('days', $first, $last, $mode, (string)$min)), true);
$days = $data['days'] ?? array();
$rows = $data['rows'] ?? array();
$q = '&amp;mode=' . $mode . '&amp;min=' . $min;
$nav = function ($date) use ($period, $q) { return 'views.php?view=eBird&amp;period=' . $period . '&amp;date=' . $date . $q; };
$many = count($days) > 1;
$on = trim((string)($config['EBIRD_REPORT_ENABLED'] ?? '0'), "\"' ") === '1';
$rname = trim((string)($config['EBIRD_REPORT_NAME'] ?? ''), "\"' ") ?: '{start}_{end}_BirdSuite_eBird_{id}_{location}';
$rtime = preg_match('/^\d{2}:\d{2}$/', trim((string)($config['EBIRD_REPORT_TIME'] ?? ''), "\"' ")) ? trim($config['EBIRD_REPORT_TIME'], "\"' ") : '08:00';
$modes = array('all' => t('All'), 'confirmed' => t('Confirmed'), 'minconf' => t('Minimum confidence'));
$pct = function ($v) { return rtrim(rtrim(sprintf('%.2f', $v), '0'), '.'); };
$rday = max(1, min(7, intval($config['EBIRD_REPORT_DAY'] ?? 1)));
// the minimum confidence as a picklist, 0.10 to 0.95 (the value in use stays in it)
$conf_opts = function ($cur) use ($pct) {
  $vals = array(); for ($v = 10; $v <= 95; $v += 5) $vals[] = $v / 100;
  if (!in_array(round($cur, 2), array_map(function ($x) { return round($x, 2); }, $vals))) { $vals[] = $cur; sort($vals); }
  $o = ''; foreach ($vals as $v) $o .= '<option value="' . $pct($v) . '"' . (abs($v - $cur) < 1e-6 ? ' selected' : '') . '>' . $pct($v) . '</option>';
  return $o;
};
?>
<style>
/* the layout of By Hour / By Week (owner 2026-10-27: the same left edge, period control and panels) */
.ebp { max-width: 1200px; margin: 0 auto; padding: 0 12px; text-align: left; }
.ebp .ebrow { display: flex; align-items: center; gap: 8px 18px; flex-wrap: wrap; margin: 6px 0 10px; font-size: 13px; }
.ebp .dashperiod { width: auto; font-size: 13px; padding: 3px 6px; }
.ebp .panel { background: rgba(255,255,255,.65); border-radius: 12px; padding: 10px 14px; margin-bottom: 16px; box-shadow: 0 1px 3px rgba(0,0,0,.08); overflow-x: auto; }
.ebp .panel h3 { margin: 0 0 6px; font-size: 1.05em; }
.ebp table.stdtable td.c, .ebp table.stdtable th.c { text-align: center; }
.ebp .note { font-size: 12px; color: #555; }
.ebp .ebtable { overflow-x: auto; }
.ebp #ebsched.off { opacity: .45; }
</style>
<div class="ebp">
  <?php // two sections (owner 2026-10-27): the report on screen, then the weekly schedule ?>
  <div class="panel"><h3><?php echo t('Report'); ?></h3>
  <div class="ebrow">
    <span class="periodpick"><a class="stdbtn pnav" href="<?php echo $nav($prev); ?>" title="<?php echo t('The period before'); ?>">&#9664;</a>
      <select id="ebperiod" class="dashperiod" onchange="ebView(true)" aria-label="<?php echo t('Period'); ?>">
        <option value="day"<?php echo $period === 'day' ? ' selected' : ''; ?>><?php echo t('By day'); ?></option>
        <option value="week"<?php echo $period === 'week' ? ' selected' : ''; ?>><?php echo t('By week'); ?></option>
        <option value="month"<?php echo $period === 'month' ? ' selected' : ''; ?>><?php echo t('By month'); ?></option></select>
      <a class="stdbtn pnav" href="<?php echo $nav($next); ?>" title="<?php echo t('The period after'); ?>">&#9654;</a></span>
    <b><?php echo $h($title); ?></b>
  </div>
  <div class="ebrow">
    <select id="ebmode" class="dashperiod" onchange="ebView()" title="<?php echo t('Species in the table, the eBird files and the e-mail'); ?>">
      <?php foreach ($modes as $k => $v) echo '<option value="' . $k . '"' . ($k === $mode ? ' selected' : '') . '>' . $h($v) . '</option>'; ?></select>
    <label><?php echo t('Minimum confidence:'); ?> <select id="ebmin" class="dashperiod" onchange="ebView()"<?php echo $mode === 'minconf' ? '' : ' disabled'; ?>><?php echo $conf_opts($min); ?></select></label>
    <?php if ($mode !== $p_mode || abs($min - $p_min) > 1e-6) { ?><button type="button" class="setdefault" onclick="ebPageDefault(this)" title="<?php echo t('Open the eBird page with this filter and minimum confidence'); ?>"><?php echo t('Set as default'); ?></button><?php } ?>
  </div>
  <div class="ebtable">
  <table class="stdtable" data-nosort>
    <tr><th class="c"><input type="checkbox" id="eball" checked onchange="document.querySelectorAll('.ebsel').forEach(function (c) { c.checked = this.checked; }, this)" title="<?php echo t('In the eBird file and the e-mail sent from here'); ?>"></th><th class="nm"><select class="namemode" title="<?php echo t('Names shown'); ?>"><option value="com"><?php echo t('Common name'); ?></option><option value="sci"><?php echo t('Scientific name'); ?></option><option value="en"><?php echo t('English name'); ?></option></select></th>
    <?php foreach ($days as $d) {
      echo '<th class="c">' . ($period === 'month' ? date('d', strtotime($d)) : $h($wd[intval(date('N', strtotime($d)))]) . '<br>' . date('d/m', strtotime($d))) . '</th>';
    } ?><?php if ($many) { ?><th class="c"><?php echo t('Total'); ?></th><?php } ?><th class="c"><?php echo t('Lowest confidence'); ?></th></tr>
    <?php if (!$rows) { ?><tr><td colspan="<?php echo count($days) + 4; ?>"><?php echo t('No species in this period with this filter.'); ?></td></tr><?php }
    foreach ($rows as $r) {
      echo '<tr><td class="c"><input type="checkbox" class="ebsel" checked data-sci="' . $h($r['sci']) . '" aria-label="' . $h($r['com']) . '"></td><td class="nm"><a href="views.php?view=Bird&amp;sci=' . rawurlencode($r['sci']) . '" title="' . $h($r['sci']) . '" data-com="' . $h($r['com']) . '" data-sci="' . $h($r['sci']) . '" data-en="' . $h($r['en']) . '">' . $h($r['com']) . '</a></td>';
      $tot = 0;
      foreach ($days as $d) { $n = intval($r['days'][$d] ?? 0); $tot += $n; echo '<td class="c">' . ($n ? fmt_num($n) : '') . '</td>'; }
      echo ($many ? '<td class="c"><b>' . fmt_num($tot) . '</b></td>' : '') . '<td class="c">' . round(100 * floatval($r['low'] ?? 0)) . '%</td></tr>';
    } ?>
  </table>
  </div>
  <p class="note"><?php echo t('Numbers = detections; the e-mail also brings the weather of each day (Open-Meteo, N/A without internet).'); ?><br>
    <?php echo t('An unticked species stays out of the eBird file and of the e-mail sent from here; the weekly schedule follows the station\'s species lists.'); ?></p>
  <p class="ebrow">
    <button type="button" class="stdbtn" onclick="ebSend(this)"><?php echo t('Send by e-mail'); ?></button>
    <a class="stdbtn" href="scripts/ebird_page.php?csv=1&amp;from=<?php echo $first; ?>&amp;to=<?php echo $last . $q; ?>" onclick="this.href = this.href.split('&skip=')[0] + '&skip=' + encodeURIComponent(ebSkip())">&#11015; <?php echo t('eBird file'); ?></a>
    <a class="stdbtn" href="scripts/ebird_page.php?sounds=1&amp;from=<?php echo $first; ?>&amp;to=<?php echo $last . $q; ?>" onclick="return ebSounds(this)" title="<?php echo t('The best sounds confirmed by ear of every bird, to add to the imported checklists with eBird\'s Add media'); ?>">&#11015; <?php echo t('Sounds (zip)'); ?></a>
    <span id="ebsent" style="font-weight:600"></span></p>
  </div>

  <div class="panel"><h3><?php echo t('Weekly schedule'); ?></h3>
    <div class="ebrow"><label><?php echo t('Active'); ?> <input type="checkbox" id="ebon" <?php echo $on ? 'checked' : ''; ?> onchange="ebActive()"></label></div>
    <?php // greyed while the schedule is off (owner 2026-10-27) ?>
    <div id="ebsched"<?php echo $on ? '' : ' class="off"'; ?>>
      <div class="ebrow">
        <select id="ebsmode" class="dashperiod" onchange="document.getElementById('ebsmin').disabled = this.value !== 'minconf'">
          <?php foreach ($modes as $k => $v) echo '<option value="' . $k . '"' . ($k === $s_mode ? ' selected' : '') . '>' . $h($v) . '</option>'; ?></select>
        <label><?php echo t('Minimum confidence:'); ?> <select id="ebsmin" class="dashperiod"<?php echo $s_mode === 'minconf' ? '' : ' disabled'; ?>><?php echo $conf_opts($s_min); ?></select></label>
      </div>
      <div class="ebrow">
        <label><?php echo t('Day:'); ?> <select id="ebday" class="dashperiod"><?php foreach ($wd as $k => $v) echo '<option value="' . $k . '"' . ($k === $rday ? ' selected' : '') . '>' . $h($v) . '</option>'; ?></select></label>
        <label><?php echo t('Time:'); ?> <input type="time" id="ebtime" value="<?php echo $h($rtime); ?>"></label>
      </div>
      <div><?php echo t('The e-mail subject and the file name.'); ?></div>
      <div class="ebrow"><input type="text" id="ebname" maxlength="100" size="44" value="<?php echo $h($rname); ?>" aria-label="<?php echo t('The e-mail subject and the file name.'); ?>"></div>
      <small><?php echo t('Fields: {start} and {end} (YYYY-MM-DD), any date pattern as {start:YYYYMMDD} or {end:YY-MM-DD}, {week}, {year}, {station}, {id}, {location}.'); ?></small>
    </div>
    <div class="ebrow"><button type="button" class="stdbtn" onclick="ebSave()"><?php echo t('Save'); ?></button> <span id="ebmsg" style="font-weight:600"></span></div>
  </div>
</div>
<script>
// a new period opens on today (today, this week, this month; owner 2026-10-27); the filters keep the period shown
function ebView(today) {
  location.href = 'views.php?view=eBird&period=' + document.getElementById('ebperiod').value + (today ? '' : '&date=<?php echo $first; ?>') + '&mode=' + document.getElementById('ebmode').value
    + '&min=' + encodeURIComponent(document.getElementById('ebmin').value);
}
function ebPageDefault(btn) {
  var x = new XMLHttpRequest();
  x.onload = function () { if (this.status === 200 && this.responseText.trim() === 'OK') btn.remove(); else document.getElementById('ebsent').textContent = this.status === 401 ? t('Log in first.') : t('not saved'); };
  x.open('GET', 'scripts/ebird_page.php?pagedefault=1&mode=<?php echo $mode; ?>&min=<?php echo $min; ?>');
  x.send();
}
function ebActive() {
  var on = document.getElementById('ebon').checked, box = document.getElementById('ebsched');
  box.classList.toggle('off', !on);
  box.querySelectorAll('select, input').forEach(function (e) { e.disabled = !on || (e.id === 'ebsmin' && document.getElementById('ebsmode').value !== 'minconf'); });
}
document.addEventListener('DOMContentLoaded', ebActive);
function ebSave() {
  var m = document.getElementById('ebmsg'), x = new XMLHttpRequest();
  m.textContent = t('saving…');
  x.onload = function () { m.textContent = this.status === 200 && this.responseText.trim() === 'OK' ? t('saved') : (this.status === 401 ? t('Log in first.') : t('not saved')); };
  x.open('GET', 'scripts/ebird_page.php?save=1&on=' + (document.getElementById('ebon').checked ? 1 : 0) + '&day=' + document.getElementById('ebday').value + '&time=' + encodeURIComponent(document.getElementById('ebtime').value)
    + '&mode=' + document.getElementById('ebsmode').value + '&min=' + encodeURIComponent(document.getElementById('ebsmin').value)
    + '&name=' + encodeURIComponent(document.getElementById('ebname').value));
  x.send();
}
// how many confirmed sounds of each species go in the zip: asked each time, 1 to 20 (owner 2026-10-27)
function ebSounds(a) {
  var n = window.prompt(t('How many sounds confirmed by ear of each species? (1 to 20)'), '5');
  if (n === null) return false;
  n = parseInt(n, 10);
  if (!(n >= 1 && n <= 20)) { window.alert(t('A number from 1 to 20.')); return false; }
  a.href = a.href.split('&skip=')[0].split('&n=')[0] + '&n=' + n + '&skip=' + encodeURIComponent(ebSkip());
  return true;
}
// the species unticked in the table, joined by |
function ebSkip() {
  return Array.prototype.filter.call(document.querySelectorAll('.ebsel'), function (c) { return !c.checked; }).map(function (c) { return c.dataset.sci; }).join('|');
}
function ebSend(btn) {
  var m = document.getElementById('ebsent'), x = new XMLHttpRequest();
  btn.disabled = true; m.textContent = t('sending…');
  x.onload = function () { btn.disabled = false; var r = this.responseText.trim(); m.textContent = this.status === 401 ? t('Log in first.') : (r === 'OK' ? t('Sent.') : r); };
  x.onerror = function () { btn.disabled = false; m.textContent = t('not sent'); };
  x.open('POST', 'scripts/ebird_page.php?send=1&from=<?php echo $first; ?>&to=<?php echo $last; ?>&mode=<?php echo $mode; ?>&min=<?php echo $min; ?>');
  x.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
  x.send('skip=' + encodeURIComponent(ebSkip()));
}
</script>
<script src="static/name-mode.js?v=<?php echo @filemtime(__DIR__ . "/../homepage/static/name-mode.js"); ?>"></script>
