<?php
/* System › Defaults (US-121, owner 2026-10-20): every setting of factory_defaults.conf (the defaults that ship with the
 * release) beside the value this station uses (BirdSuite.conf), editable; the changed ones marked, Default per key and
 * for all of them; and, like the Lists, the whole set saved under a name, loaded and deleted (settings_profiles/).
 * Opened as views.php?view=Defaults; POST scripts/defaults.php ?reset=KEY | ?resetall=1 | ?save=1 (JSON key => value)
 * | ?profile=save|load|delete&name=NAME. */
require_once __DIR__ . '/common.php';
if (session_status() === PHP_SESSION_NONE) @session_start();

// the field of a setting: a list when its values are known (owner 2026-10-25), else a text box
function dfl_field($k, $val, $opts, $title, $h) {
  $common = ' class="dflin" data-orig="' . $h($val) . '" oninput="dflEdited(this)" onchange="dflSaveOne(this)"' . ($title !== '' ? ' title="' . $h($title) . '"' : '');
  if ($opts === null) return '<input type="text"' . $common . ' value="' . $h($val) . '">';
  $val = trim((string)$val, '" ');  // "false" as written in the file is false
  if (!array_key_exists($val, $opts)) $opts = array($val => $val) + $opts;
  $o = '';
  foreach ($opts as $v => $label) $o .= '<option value="' . $h($v) . '"' . ((string)$v === $val ? ' selected' : '') . '>' . $h($label) . '</option>';
  return '<select' . $common . '>' . $o . '</select>';
}
// what each setting is, short and in one style (owner 2026-10-25: every "What it is" filled, as the spectrogram ones);
// English keys translated by the catalogues; a setting missing here shows the comment of factory_defaults.conf
function dfl_notes() {
  return array(
    'MODEL' => 'The BirdNET model that analyses the audio (Settings › Model).',
    'CONFIDENCE' => 'Min. Conf.: the lowest score a detection needs to be kept (0.01–0.99).',
    'SENSITIVITY' => 'Sigmoid sensitivity: bends the model\'s scores before Min. Conf. applies (0.5–1.5; higher = more detections).',
    'SF_THRESH' => 'Min. Prob.: species the location model expects here this week below this probability are left out (0.0005–0.99).',
    'OVERLAP' => 'Seconds the 3 s analysis windows overlap (0–2.9; more = fewer missed calls, more CPU).',
    'AVOID_DUPLICITY' => '1 = only the top species of each recording is kept; 0 = every species above its minimum.',
    'PRIVACY_THRESHOLD' => 'Privacy: a recording where a human voice is among the top N% of the predictions is dropped (0–3; 0 = off).',
    'RECORDING_LENGTH' => 'Seconds of each recorded and analysed file (3–60).',
    'EXTRACTION_LENGTH' => 'Seconds of each saved detection clip (3 to RECORDING_LENGTH).',
    'CHANNELS' => 'Channels recorded from the sound card: 1 = mono, 2 = stereo.',
    'AUDIOFMT' => 'Audio format of the saved clips: flac, mp3, wav or ogg.',
    'SHADOW_ENABLED' => '1 = a second model also analyses every recording (Tools › Shadow); 0 = off.',
    'SHADOW_MODEL_NAME' => 'The second model; its detections go to birds_shadow.db.',
    'SHADOW_MIN_CONF' => 'Min. Conf. of the second model (0.01–0.99).',
    'SHADOW_SENS' => 'Sensitivity of the second model (0.5–1.5).',
    'SHADOW_GEO_THRESH' => 'Min. Prob. of the second model\'s location filter (0.0005–0.99).',
    'MAX_FILES_SPECIES_DAY' => 'Each day, a species keeps its N clips of highest confidence (0 = no limit).',
    'MAX_FILES_SPECIES' => 'Outside the recent days, each species keeps its N best clips in total (0 = no limit).',
    'NOCTURNAL_DAWN_MARGIN' => 'The species of Lists › Nocturnal are dropped from this many minutes after sunrise.',
    'NOCTURNAL_DUSK_MARGIN' => 'The species of Lists › Nocturnal are dropped until this many minutes before sunset.',
    'PURGE_RECENT_DAYS' => 'The clips of the last N days are left out of the per-species limit (0 = no per-species limit).',
    'IGNORED_PURGE_DAYS' => 'The audio of Ignored detections (Dog, Human, Engine, Noise, Ignore) is deleted N days later; the detections keep counting (0 = only by hand).',
    'PURGE_PROTECT_TOP_N' => 'The N best clips of each species are never deleted, besides the locked, Yes and Review later ones (0 = all protected).',
    'FULL_DISK' => 'When the disk reaches PURGE_THRESHOLD: purge = delete the oldest clips, keep = stop the recording, external = move the oldest sounds to FULL_DISK_PATH.',
    'FULL_DISK_PATH' => 'The external folder of FULL_DISK = external (a mounted share or disk, writable by the station user).',
    'FULL_DISK_FALLBACK' => 'When the external folder cannot be used (not mounted, not writable, full, same disk): keep = stop the recording, purge = delete the oldest clips.',
    'PURGE_THRESHOLD' => 'Disk use (%) that starts the purge or the stop (0 = never).',
    'APPRISE_NOTIFY_EACH_DETECTION' => '1 = a notification for every detection (many messages); 0 = off.',
    'APPRISE_NOTIFY_NEW_SPECIES' => '1 = a notification when a species is detected for the first time at the station.',
    'APPRISE_NOTIFY_NEW_SPECIES_EACH_DAY' => '1 = a notification for the first detection of each species each day.',
    'APPRISE_NOTIFY_REGION_RARE' => '1 = species the location model does not expect here go to the Rare channel.',
    'NOTIFICATION_DEFAULT_TIER' => 'Tier of a species without its own: muted (never notified), normal or rare (Rare channel).',
    'APPRISE_MIN_SEC_PER_SPECIES' => 'Minimum seconds between two notifications of the same species (0 = no limit).',
    'APPRISE_NOTIFICATION_TITLE' => 'Title of a normal notification; variables such as $common_name, $conf_pct, $date, $time (Notifications › Content).',
    'APPRISE_NOTIFICATION_TITLE_RARE' => 'Title of a Rare channel notification; the same variables.',
    'APPRISE_EXCLUDE_SPECIES' => 'Old name filter: these common names, comma separated, are never notified in the normal tier (empty = none); Muted in Species Cards does the same by species.',
    'APPRISE_ONLY_SPECIES' => 'Old name filter: only these common names, comma separated, are notified in the normal tier (empty = all); the default tier muted plus Normal or Rare in Species Cards does the same by species.',
    'APPRISE_QUIET_START_NORMAL' => 'Start of the quiet hours of the normal tier, HH:MM (empty = none).',
    'APPRISE_QUIET_END_NORMAL' => 'End of the quiet hours of the normal tier, HH:MM.',
    'APPRISE_QUIET_START_RARE' => 'Start of the quiet hours of the rare tier, HH:MM (empty = none).',
    'APPRISE_QUIET_END_RARE' => 'End of the quiet hours of the rare tier, HH:MM.',
    'BIRDWEATHER_ENABLED' => '1 = detections are uploaded to BirdWeather (needs BIRDWEATHER_ID); 0 = paused, the token is kept.',
    'MULTI_LOCATION' => 'Enable support for several locations: 1 = the Loc: picker, the location in the header and lists, the list of locations and New location; 0 = one place, those hidden.',
    'MULTI_STATION' => 'Enable support for several stations: 1 = the station list in the header, System › Stations and Move to station; 0 = one station, those hidden.',
    'HTTPS_ALWAYS' => '1 = the pages only over https (the browser warns once per device about the station\'s own certificate); 0 = http, https only for a moment to read the GPS of a location.',
    'ACTIVATE_BIRDDB' => 'Activate BirdDB: true = the BirdDB-Br section is shown in Settings › Repositories; false = hidden.',
    'BIRDDB_ENABLED' => '1 = this station\'s clips are contributed to BirdDB-Br; 0 = off.',
    'SOUND_REPO_UPLOAD_MINUTES' => 'Minutes between uploads to the BirdDB-Br repository (0 = never).',
    'NOW_VIEW' => 'How the Latest page opens on a wide screen: simple, list (Full) or spectrogram (cards); a phone always opens simple.',
    'NOW_ANALYZING' => 'The Currently Analyzing spectrogram when the Latest page opens: show or hide.',
    'NOW_COUNT' => 'Detections in the Latest list: 10, 25, 50 or 100; each browser may pick another.',
    'NOW_PERIOD' => 'Period searched by the Latest filters and search: today, 7d or all.',
    'REPORTS_FILTERS_PHONE' => 'The filter line of Reports › By Hour and By Week when they open on a phone: open or closed.',
    'REPORTS_FILTERS_DESKTOP' => 'The filter line of Reports › By Hour and By Week when they open on a wider screen: open or closed.',
    'NOW_FILTERS_PHONE' => 'The filter panel of the Latest page when it opens on a phone: open or closed.',
    'NOW_FILTERS_DESKTOP' => 'The filter panel of the Latest page when it opens on a wider screen: open or closed.',
    'NOW_PARAMS_PHONE' => 'The analysis settings box (Param.) of the Latest page on a phone: open or closed.',
    'NOW_PARAMS_DESKTOP' => 'The analysis settings box (Param.) of the Latest page on a wider screen: open or closed.',
    'NOW_LOWPROB' => 'Low Prob filter: location probability below this % (5–95).',
    'NOW_LOWCONF' => 'Low Conf filter: confidence below this % (5–95).',
    'NOW_UNCOMMON' => 'Uncommon filter: species detected at most this many times since yesterday.',
    'RARE_SPECIES_THRESHOLD' => 'A species not detected for this many days is highlighted as rare on the Latest page.',
    'EBIRD_REPORT_ENABLED' => 'Weekly eBird e-mail: 1 = the species confirmed by ear of the last seven days and their eBird file go by e-mail every week.',
    'EBIRD_REPORT_FREQ' => 'eBird e-mail: daily = every day the previous day, weekly = on its day the last seven days.',
    'EBIRD_REPORT_DAY' => 'Day of the weekly eBird e-mail: 1 = Monday ... 7 = Sunday.',
    'EBIRD_REPORT_TIME' => 'Time of the weekly eBird e-mail (HH:MM).',
    'EBIRD_REPORT_SPECIES' => 'Filter of the weekly eBird e-mail: confirmed = only the species confirmed by ear, minconf = from the minimum confidence and the confirmed ones, all = every detection.',
    'EBIRD_REPORT_MIN_CONF' => 'With minconf, the lowest confidence of a detection to count in the weekly eBird e-mail (0-0.99).',
    'EBIRD_PAGE_MODE' => 'Filter the eBird page opens with: confirmed, minconf (from EBIRD_PAGE_MIN_CONF) or all.',
    'EBIRD_PAGE_MIN_CONF' => 'Minimum confidence the eBird page opens with (0-0.99).',
    'EBIRD_REPORT_NAME' => 'Subject of the weekly eBird e-mail and name of its file: {start} {end} (YYYY-MM-DD, or a pattern such as {start:YYYYMMDD}), {week}, {year}, {station}, {id}, {location}.',
    'SPECTROGRAM_PALETTE' => 'Colour palette of the spectrograms: birdnet (Classic), viridis, inferno, ocean, grayscale, soxheat, turbo or roseus.',
    'SPECTROGRAM_HEIGHT' => 'Height of the live spectrogram, in % of the page height.',
    'SPECTROGRAM_FLOOR_DB' => 'Floor of the spectrogram colour scale in dB (-120 to -40; lower = fainter sounds show).',
    'SPECTROGRAM_RANGE_DB' => 'Range of the colour scale above the floor, in dB (30–120).',
    'SPECTROGRAM_CONTRAST' => 'Contrast gamma of the spectrograms (0.5–2; 1 = linear).',
    'SPECTROGRAM_SILENT' => '1 = the live spectrogram starts without sound until the viewer unticks Silent (each browser keeps its choice).',
    'SPECTROGRAM_COMPRESSION' => '1 = the live audio goes through a dynamics compressor; 0 = off.',
    'RAW_SPECTROGRAM' => '1 = clip spectrograms without axes and labels; 0 = with them.',
    'RTSP_STREAM_TO_LIVESTREAM' => 'Which RTSP stream the live audio plays: 0 = the first, 1 = the second…',
    'ACTIVATE_FREQSHIFT_IN_LIVESTREAM' => 'true = the live audio is pitch-shifted down (for listeners who do not hear high notes); false = off.',
    'FREQSHIFT_TOOL' => 'Pitch-shift tool: sox (uses FREQSHIFT_PITCH) or ffmpeg (uses FREQSHIFT_HI and FREQSHIFT_LO).',
    'FREQSHIFT_PITCH' => 'sox: shift in hundredths of a semitone (-1500 = 15 semitones down).',
    'FREQSHIFT_HI' => 'ffmpeg: frequency in Hz moved down to FREQSHIFT_LO.',
    'FREQSHIFT_LO' => 'ffmpeg: frequency in Hz that FREQSHIFT_HI is moved to.',
    'FREQSHIFT_RECONNECT_DELAY' => 'Milliseconds before the shifted live audio reconnects after a break.',
    'RAW_REC_ENABLED' => '1 = the Raw Recording schedule is on (long unprocessed WAV files beside the analysis).',
    'RAW_REC_DAYS' => 'Days of the week, comma separated: 0 = Sunday … 6 = Saturday.',
    'RAW_REC_RECURRENT' => '1 = every chosen day; 0 = only the next one, then off.',
    'RAW_REC_START' => 'Start time, HH:MM:SS.',
    'RAW_REC_END' => 'End time, HH:MM:SS; earlier than the start = the next day.',
    'RAW_REC_SEGMENT_MIN' => 'Maximum minutes per WAV file.',
    'FIELD_WIFI_MODE' => 'The station WiFi when the station has no network: always (on until a known network is back), cycle (on every few minutes for a while) or off (only after start-up, then the WiFi antenna off).',
    'FIELD_BOOT_WAIT_SEC' => 'Seconds after a start-up before the station WiFi may turn on (the known networks first).',
    'FIELD_LOST_WAIT_SEC' => 'Seconds without any network (cable, WiFi or phone hotspot gone) before the station WiFi turns on.',
    'FIELD_AP_WINDOW_MIN' => 'Cycle and off modes: minutes the station WiFi stays on when no phone joins it.',
    'FIELD_CYCLE_MIN' => 'Cycle mode: the station WiFi turns on again every so many minutes.',
    'FIELD_AP_IDLE_MIN' => 'Minutes after the last phone left before the station WiFi turns off (cycle and off modes, Bluetooth, the page).',
    'LOCATION_CHECK' => '1 = at boot, the network position is compared with the coordinates and the Latest page asks if the station moved; 0 = off.',
    'GEOLOCATION_API_KEY' => 'Google Geolocation API key (optional) to locate the station by the Wi-Fi around it; empty = BeaconDB, then the IP.',
    'LOCATION_IP_ERROR_KM' => 'How far off the network (IP) position can be, in km; the boot check never asks below this.',
    'APP_THEME' => 'Colours of the app: ocean, dark, forest, blossom, white, red or custom1, custom2…; a location can have its own.',
    'COLOR_SCHEME' => 'light or dark; follows the theme by itself (dark with Dark) and is read by the daily chart.',
    'UI_LANG' => 'Interface language; empty = follow DATABASE_LANG when available, else English.',
    'IMAGE_PROVIDER' => 'Bird photos from Wikipedia (fixed).',
    'INFO_SITE' => 'Site of the species links: ALLABOUTBIRDS, EBIRD or WIKIAVES (WikiAves with Brazilian names, else eBird).',
    'AUTOMATIC_UPDATE' => '1 = the station updates itself on the days and at the time below; 0 = only by hand.',
    'AUTOMATIC_UPDATE_DAYS' => 'Days of the automatic update, comma separated: 0 = Sunday … 6 = Saturday.',
    'AUTOMATIC_UPDATE_TIME' => 'Time of the automatic update, HH:MM, local time.',
    'SILENCE_UPDATE_INDICATOR' => '1 = no update badge in the menu.',
  );
}
// the defaults with the comment lines (## ...) written right above each key; the page shows each note through t() (the
// file stays English, the catalogues lang/<code>/settings.json translate the notes, 2026-10-23 test run)
function defaults_with_notes() {
  $out = array();
  $note = array();
  $f = BS_DEFAULTS;
  if (!is_file($f)) return $out;
  foreach (file($f, FILE_IGNORE_NEW_LINES) as $line) {
    if (preg_match('/^##\s?(.*)$/', $line, $m)) { $note[] = $m[1]; continue; }
    if (preg_match('/^([A-Z][A-Z0-9_]*)=(.*)$/', $line, $m)) {
      $out[$m[1]] = array('value' => trim($m[2], '"'), 'note' => implode(' ', $note));
    }
    $note = array();
  }
  return $out;
}
// the station's own values, without the defaults under them
function station_values() {
  // read line by line like the saved files (2026-10-23 run 3: parse_ini_string turned false / no / off into "", so the
  // Default button never cleared them and a loaded profile looked changed at once)
  return profile_read(BS_CONF);
}
function same_value($a, $b) {
  $a = trim((string)$a, '" '); $b = trim((string)$b, '" ');
  if (is_numeric($a) && is_numeric($b)) return abs(floatval($a) - floatval($b)) < 1e-9;
  return $a === $b;
}

// write several keys of BirdSuite.conf at once (only keys of factory_defaults.conf; one line each, quoted when needed)
function conf_set_many($pairs) {
  $f = BS_CONF;
  $c = (string)file_get_contents($f);
  foreach ($pairs as $key => $v) {
    $v = str_replace(array('"', "\r", "\n"), '', (string)$v);
    $line = $key . '=' . (preg_match('/^[A-Za-z0-9_.\-]*$/', $v) ? $v : '"' . $v . '"');
    $c = preg_match('/^' . $key . '=/m', $c) ? preg_replace('/^' . $key . '=.*/m', str_replace(array('\\', '$'), array('\\\\', '\\$'), $line), $c)
                                              : rtrim($c, "\n") . "\n" . $line . "\n";
  }
  return file_put_contents($f, $c) !== false;
}
function profiles_dir() {
  $d = get_home() . '/BirdNET-Pi/config/settings_profiles';
  if (!is_dir($d)) { @mkdir($d, 0775, true); @chmod($d, 0775); }
  return $d;
}
function profile_read($file) {
  $out = array();
  foreach (file($file, FILE_IGNORE_NEW_LINES) ?: array() as $line) if (preg_match('/^([A-Z][A-Z0-9_]*)=(.*)$/', $line, $m)) $out[$m[1]] = trim($m[2], '"');
  return $out;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  ensure_authenticated(t('You must be authenticated to change the settings.'));
  $defs = defaults_with_notes();
  // HTTPS_ALWAYS changed by any of the actions below: the web server configuration is written again (US-126)
  $https_before = (string)(station_values()['HTTPS_ALWAYS'] ?? '0');
  register_shutdown_function(function () use ($https_before) {
    clearstatcache();
    if ((string)(station_values()['HTTPS_ALWAYS'] ?? '0') !== $https_before) exec('sudo -n /usr/local/sbin/birdsuite-root caddyfile > /dev/null 2>&1 &');
  });
  if (isset($_GET['reset'])) {
    $key = (string)$_GET['reset'];
    if (!isset($defs[$key])) { echo t('Unknown setting'); die(); }
    echo conf_set_many(array($key => $defs[$key]['value'])) ? 'OK' : t('Error writing the settings');
  } elseif (isset($_GET['resetall'])) {
    echo conf_set_many(array_map(function ($d) { return $d['value']; }, $defs)) ? 'OK' : t('Error writing the settings');
  } elseif (isset($_GET['save'])) {
    $in = json_decode((string)file_get_contents('php://input'), true) ?: array();
    $pairs = array_intersect_key($in, $defs);
    if (!$pairs) { echo t('Nothing to save'); die(); }
    echo conf_set_many($pairs) ? 'OK' : t('Error writing the settings');
  } elseif (isset($_GET['profile'])) {
    $name = (string)($_GET['name'] ?? '');
    if (!preg_match('/^[A-Za-z0-9_-]{1,40}$/', $name)) { echo t('Name: letters, digits, - and _ only'); die(); }
    $file = profiles_dir() . "/$name.conf";
    $act = $_GET['profile'];
    if ($act === 'save') {
      $mine = array_merge(array_map(function ($d) { return $d['value']; }, $defs), array_intersect_key(station_values(), $defs));
      $txt = "## BirdSuite settings saved " . date('Y-m-d H:i') . " (System › Defaults)\n";
      foreach ($mine as $k => $v) $txt .= $k . '=' . (preg_match('/^[A-Za-z0-9_.\-]*$/', (string)$v) ? $v : '"' . str_replace('"', '', (string)$v) . '"') . "\n";
      if (file_put_contents($file, $txt) === false) { echo t('Cannot write the saved settings'); die(); }
      @chmod($file, 0664);
      @file_put_contents(profiles_dir() . '/.loaded', $name);
      echo t('Saved as {name} ({n} settings).', array('name' => $name, 'n' => count($mine)));
    } elseif ($act === 'load') {
      if (!is_file($file)) { echo t('No such saved settings'); die(); }
      $pairs = array_intersect_key(profile_read($file), $defs);
      if (!conf_set_many($pairs)) { echo t('Error writing the settings'); die(); }
      @file_put_contents(profiles_dir() . '/.loaded', $name);
      echo t('{name} loaded ({n} settings). Analysis settings apply from the next recording; a few after a restart in System Controls.', array('name' => $name, 'n' => count($pairs)));
    } elseif ($act === 'delete') {
      if (!is_file($file) || preg_match('/^_*factory/i', $name)) { echo t('No such saved settings'); die(); }
      unlink($file);
      echo t('{name} deleted (the station\'s settings are unchanged).', array('name' => $name));
    } else echo t('Unknown action');
  } else echo t('Unknown action');
  die();
}

$defs = defaults_with_notes();
$mine = station_values();
// saved variations, the one matching the station now marked current (the last loaded wins among identical ones)
$cur = array_merge(array_map(function ($d) { return $d['value']; }, $defs), array_intersect_key($mine, $defs));
$profiles = array();
foreach (glob(profiles_dir() . '/*.conf') ?: array() as $pf) {
  $vals = array_intersect_key(profile_read($pf), $defs);
  $same = true;
  foreach ($cur as $k => $v) if (!isset($vals[$k]) || !same_value($vals[$k], $v)) { $same = false; break; }
  $profiles[basename($pf, '.conf')] = $same;
}
$matching = array_keys(array_filter($profiles));
$last = trim((string)@file_get_contents(profiles_dir() . '/.loaded'));
if ($last !== '' && in_array($last, $matching, true)) $matching = array($last);
$h = function ($s) { return htmlspecialchars((string)$s, ENT_QUOTES); };
$changed = 0;
foreach ($defs as $k => $d) if (isset($mine[$k]) && !same_value($mine[$k], $d['value'])) $changed++;
?>
<style>
.dfl { max-width: 1100px; }
.dfl .note { font-size: 13px; opacity: .85; }
.dfl table { width: 100%; }
.dfl td.k { font-family: monospace; font-size: 12.5px; white-space: nowrap; }
.dfl td.v { font-family: monospace; font-size: 12.5px; word-break: break-all; }
.dfl tr.dflsec td { font-weight: bold; color: #000; background: color-mix(in srgb, #e3f0fa 70%, #000); padding-top: 8px; }  /* 30% darker than the light blue (owner 2026-10-25) */
.dfl .dflin { width: 100%; min-width: 8em; box-sizing: border-box; font-family: monospace; font-size: 12.5px; }
.dfl .dflin.edited { outline: 2px solid var(--accent,#2b5e22); }
/* as in Now › Param.: a value that is not the default has a light yellow field (owner 2026-10-20) */
.dfl .dflin.nondefault { background: #fff8c4 !important; }
.dfl td.n { font-size: 12px; opacity: .8; }
.dfl button { padding: 2px 10px; border-radius: 10px; border: 1px solid var(--accent,#2b5e22); background: transparent; cursor: pointer; }
.dfl .bar { display: flex; gap: 14px; align-items: center; flex-wrap: wrap; margin: 6px 0 10px; }
</style>
<div class="dfl">
  <?php // owner 2026-10-24: one clear set of actions: the settings in use (saved under a name or not), load a saved set or
  // the factory defaults, save the current ones under a name; then the table, edited and saved in place ?>
  <p class="note"><?php echo t('Every setting: its factory default (<code>factory_defaults.conf</code>, comes with each update) and the value this station uses. A light yellow field differs from the factory default.'); ?>
    <?php echo t('Not listed: what the installation sets for this station (name, coordinates, user, folders, passwords, tokens).'); ?></p>
  <div class="bar">
    <?php // one control (owner 2026-10-25): "Select set: [the set in use ▾] Load Delete" ?>
    <span><?php echo t('Select set:'); ?>
    <?php // choosing does nothing by itself (owner 2026-10-25: a saved set could not be deleted, choosing it loaded it) ?>
    <select id="dflprofiles">
      <option value=""><?php echo $changed === 0 ? t('Factory defaults') : ($matching ? $h($matching[0]) : t('not saved')); ?></option>
      <option value="__factory__"><?php echo t('Factory defaults'); ?></option>
      <?php foreach ($profiles as $n => $is) echo '<option value="' . $h($n) . '">' . $h($n) . ($matching && $matching[0] === $n ? ' ' . t('(current)') : '') . '</option>'; ?>
    </select>
    <button type="button" id="dflload" onclick="dflProfile('load')" disabled title="<?php echo t('Writes the chosen settings over the station\'s (asks first)'); ?>"><?php echo t('Load set'); ?></button>
    <button type="button" id="dfldel" onclick="dflProfile('delete')" disabled title="<?php echo t('Deletes the saved copy only; the station\'s settings stay'); ?>"><?php echo t('Delete'); ?></button></span>
    <span style="margin-left:auto"><?php // on the right, as Save edits below (owner 2026-10-25) ?><?php echo t('Save the settings in use as'); ?> <input type="text" id="dflname" placeholder="<?php echo t('name'); ?>" style="width:10em">
    <button type="button" onclick="dflProfile('save')"><?php echo t('Save'); ?></button></span>
  </div>
  <?php // short (owner 2026-10-25): "4/79 changed - Only changed: [ ]", Save edits on the right, no filter box ?>
  <div class="bar"><span title="<?php echo t('Settings that differ from the factory default'); ?>"><?php echo t('<b>{n}</b>/{total} changed', array('n' => '<span id="dflcount">' . $changed . '</span>', 'total' => count($defs))); ?></span> -
    <label><?php echo t('Show only changes:'); ?> <input type="checkbox" id="dflonly" checked onchange="dflFilter()"></label>
    <?php // no Save button (owner 2026-10-25): a field is saved when it is left (or the page is) ?>
    <span id="dflmsg" style="margin-left:auto"></span></div>
  <table class="stdtable" id="dfltable" data-nosort><?php // every line always, no pages (owner 2026-10-24) ?>
    <tr><th><?php echo t('Setting'); ?></th><th><?php echo t('Default'); ?></th><th><?php echo t('This station'); ?></th><th><?php echo t('What it is'); ?></th><th data-nosort></th></tr>
    <?php
    // the settings with a known set of values get a list instead of a text box (owner 2026-10-25: no guessing); the
    // value in use stays in the list even when it is not one of these
    $onoff = array('0' => t('0 = off'), '1' => t('1 = on'));
    $choices = array_fill_keys(array('APPRISE_NOTIFY_REGION_RARE', 'AVOID_DUPLICITY', 'SHADOW_ENABLED', 'BIRDWEATHER_ENABLED', 'RTSP_STREAM_TO_LIVESTREAM',
      'APPRISE_NOTIFY_EACH_DETECTION', 'APPRISE_NOTIFY_NEW_SPECIES', 'APPRISE_NOTIFY_NEW_SPECIES_EACH_DAY', 'BIRDDB_ENABLED', 'SPECTROGRAM_SILENT',
      'SPECTROGRAM_COMPRESSION', 'LOCATION_CHECK', 'SILENCE_UPDATE_INDICATOR', 'AUTOMATIC_UPDATE', 'RAW_SPECTROGRAM', 'RAW_REC_ENABLED',
      'RAW_REC_RECURRENT', 'EBIRD_REPORT_ENABLED', 'HTTPS_ALWAYS', 'MULTI_LOCATION', 'MULTI_STATION'), $onoff);
    $plain = function ($list) { return array_combine($list, $list); };
    $choices += array(
      'ACTIVATE_FREQSHIFT_IN_LIVESTREAM' => $plain(array('false', 'true')),
      'ACTIVATE_BIRDDB' => $plain(array('false', 'true')),
      'MODEL' => $plain(array('BirdNET-Plus_V3.0-preview3.1_Global_10K', 'BirdNET_GLOBAL_6K_V2.4_Model_FP16', 'BirdNET_6K_GLOBAL_MODEL')),
      'SHADOW_MODEL_NAME' => array('' => t('(none)')) + $plain(array('BirdNET_GLOBAL_6K_V2.4_Model_FP16', 'BirdNET-Plus_V3.0-preview3.1_Global_10K', 'BirdNET_6K_GLOBAL_MODEL')),
      'NOTIFICATION_DEFAULT_TIER' => $plain(array('muted', 'normal', 'rare')),
      'FIELD_WIFI_MODE' => $plain(array('always', 'cycle', 'off')),
      'IMAGE_PROVIDER' => $plain(array('WIKIPEDIA')),
      'INFO_SITE' => $plain(array('ALLABOUTBIRDS', 'EBIRD', 'WIKIAVES')),
      'COLOR_SCHEME' => $plain(array('light', 'dark')),
      'FULL_DISK' => $plain(array('purge', 'keep', 'external')),
      'FULL_DISK_FALLBACK' => $plain(array('keep', 'purge')),
      'FREQSHIFT_TOOL' => $plain(array('sox', 'ffmpeg')),
      'SPECTROGRAM_PALETTE' => $plain(array('turbo', 'inferno', 'roseus', 'soxheat', 'birdnet', 'viridis', 'ocean', 'grayscale')),
      'CHANNELS' => $plain(array('1', '2')),
      'APP_THEME' => $plain(array_keys(theme_presets())),
      'AUDIOFMT' => $plain(array('flac', 'mp3', 'wav', 'ogg')),
      'NOW_VIEW' => $plain(array('simple', 'list', 'spectrogram')),
      'NOW_ANALYZING' => $plain(array('show', 'hide')),
      'NOW_COUNT' => $plain(array('10', '25', '50', '100')),
      'NOW_PERIOD' => $plain(array('today', '7d', 'all')),
      'EBIRD_REPORT_DAY' => $plain(array('1', '2', '3', '4', '5', '6', '7')),
      'EBIRD_REPORT_SPECIES' => $plain(array('confirmed', 'minconf', 'all')), 'EBIRD_PAGE_MODE' => $plain(array('confirmed', 'minconf', 'all')),
      'EBIRD_REPORT_FREQ' => $plain(array('daily', 'weekly')),
      'NOW_FILTERS_PHONE' => $plain(array('closed', 'open')), 'NOW_FILTERS_DESKTOP' => $plain(array('closed', 'open')),
      'NOW_PARAMS_PHONE' => $plain(array('closed', 'open')), 'NOW_PARAMS_DESKTOP' => $plain(array('closed', 'open')),
      'REPORTS_FILTERS_PHONE' => $plain(array('closed', 'open')), 'REPORTS_FILTERS_DESKTOP' => $plain(array('closed', 'open')),
      'UI_LANG' => array('' => t('(follow DATABASE_LANG)')) + $plain(array('en', 'pt_BR', 'es', 'zh_CN', 'ja', 'de', 'fr', 'it', 'nl', 'pl', 'ru')),
    );
    // grouped by subject, in the owner's order (2026-10-25); a setting not listed goes to Other
    $sections = array(
      // first (owner 2026-10-29, US-125): several locations, then several stations
      'Locations and stations' => array('MULTI_LOCATION', 'MULTI_STATION'),
      'Appearance and language' => array('APP_THEME', 'COLOR_SCHEME', 'UI_LANG', 'IMAGE_PROVIDER', 'INFO_SITE'),
      'Latest page' => array('NOW_VIEW', 'NOW_ANALYZING', 'NOW_FILTERS_PHONE', 'NOW_FILTERS_DESKTOP', 'NOW_PARAMS_PHONE', 'NOW_PARAMS_DESKTOP', 'NOW_COUNT', 'NOW_PERIOD', 'NOW_LOWPROB', 'NOW_LOWCONF', 'NOW_UNCOMMON', 'RARE_SPECIES_THRESHOLD'),
      'Reports' => array('REPORTS_FILTERS_PHONE', 'REPORTS_FILTERS_DESKTOP'),
      'eBird' => array('EBIRD_PAGE_MODE', 'EBIRD_PAGE_MIN_CONF', 'EBIRD_REPORT_ENABLED', 'EBIRD_REPORT_FREQ', 'EBIRD_REPORT_DAY', 'EBIRD_REPORT_TIME', 'EBIRD_REPORT_SPECIES', 'EBIRD_REPORT_MIN_CONF', 'EBIRD_REPORT_NAME'),
      'Spectrogram and live audio' => array('SPECTROGRAM_PALETTE', 'SPECTROGRAM_HEIGHT', 'SPECTROGRAM_FLOOR_DB', 'SPECTROGRAM_RANGE_DB', 'SPECTROGRAM_CONTRAST', 'SPECTROGRAM_SILENT',
        'SPECTROGRAM_COMPRESSION', 'RAW_SPECTROGRAM', 'RTSP_STREAM_TO_LIVESTREAM', 'ACTIVATE_FREQSHIFT_IN_LIVESTREAM', 'FREQSHIFT_TOOL', 'FREQSHIFT_PITCH', 'FREQSHIFT_HI', 'FREQSHIFT_LO', 'FREQSHIFT_RECONNECT_DELAY'),
      // the order of Tools › Data (owner 2026-10-25)
      'Disk and life cycle of the sounds' => array('MAX_FILES_SPECIES_DAY', 'MAX_FILES_SPECIES', 'PURGE_RECENT_DAYS', 'IGNORED_PURGE_DAYS', 'PURGE_PROTECT_TOP_N', 'FULL_DISK', 'FULL_DISK_PATH', 'FULL_DISK_FALLBACK', 'PURGE_THRESHOLD'),
      'Model and analysis' => array('MODEL', 'CONFIDENCE', 'SENSITIVITY', 'SF_THRESH', 'OVERLAP', 'AVOID_DUPLICITY', 'PRIVACY_THRESHOLD', 'RECORDING_LENGTH', 'EXTRACTION_LENGTH', 'CHANNELS', 'AUDIOFMT'),
      'Shadow model' => array('SHADOW_ENABLED', 'SHADOW_MODEL_NAME', 'SHADOW_MIN_CONF', 'SHADOW_SENS', 'SHADOW_GEO_THRESH'),
      'Notifications' => array('APPRISE_NOTIFY_EACH_DETECTION', 'APPRISE_NOTIFY_NEW_SPECIES', 'APPRISE_NOTIFY_NEW_SPECIES_EACH_DAY', 'APPRISE_NOTIFY_REGION_RARE', 'NOTIFICATION_DEFAULT_TIER',
        'APPRISE_MIN_SEC_PER_SPECIES', 'APPRISE_NOTIFICATION_TITLE', 'APPRISE_NOTIFICATION_TITLE_RARE', 'APPRISE_EXCLUDE_SPECIES', 'APPRISE_ONLY_SPECIES',
        'APPRISE_QUIET_START_NORMAL', 'APPRISE_QUIET_END_NORMAL', 'APPRISE_QUIET_START_RARE', 'APPRISE_QUIET_END_RARE'),
      'Sharing' => array('BIRDWEATHER_ENABLED', 'ACTIVATE_BIRDDB', 'BIRDDB_ENABLED', 'SOUND_REPO_UPLOAD_MINUTES'),
      'Updates' => array('AUTOMATIC_UPDATE', 'AUTOMATIC_UPDATE_DAYS', 'AUTOMATIC_UPDATE_TIME', 'SILENCE_UPDATE_INDICATOR'),
      'Raw recording' => array('RAW_REC_ENABLED', 'RAW_REC_DAYS', 'RAW_REC_RECURRENT', 'RAW_REC_START', 'RAW_REC_END', 'RAW_REC_SEGMENT_MIN'),
      'Location' => array('LOCATION_CHECK', 'GEOLOCATION_API_KEY', 'LOCATION_IP_ERROR_KM'),
      'Web access' => array('HTTPS_ALWAYS'),
      'Field access (Plan A)' => array('FIELD_WIFI_MODE', 'FIELD_BOOT_WAIT_SEC', 'FIELD_LOST_WAIT_SEC', 'FIELD_AP_WINDOW_MIN', 'FIELD_CYCLE_MIN', 'FIELD_AP_IDLE_MIN'),
    );
    $notes = dfl_notes();
    $listed = array();
    foreach ($sections as $keys) $listed = array_merge($listed, $keys);
    $sections['Other'] = array_values(array_diff(array_keys($defs), $listed));
    foreach ($sections as $title => $keys) {
      $keys = array_values(array_filter($keys, function ($k) use ($defs) { return isset($defs[$k]); }));
      if (!$keys) continue;
      echo '<tr class="dflsec nosortrow"><td colspan="5">' . $h(t($title)) . '</td></tr>';
    foreach ($keys as $k) {
      $d = $defs[$k];
      $has = isset($mine[$k]);
      $chg = $has && !same_value($mine[$k], $d['value']);
      echo '<tr data-k="' . $h($k) . '"' . ($chg ? ' class="chg"' : '') . '><td class="k">' . $h($k) . '</td><td class="v">' . $h($d['value'] === '' ? t('(empty)') : $d['value']) . '</td>'
        . '<td class="v">' . dfl_field($k, $has ? $mine[$k] : $d['value'], $choices[$k] ?? null, $has ? '' : t('Not set on this station: the default applies'), $h) . '</td><td class="n">' . $h(isset($notes[$k]) ? t($notes[$k]) : str_replace(' — ', ': ', t($d['note']))) . '</td>'
        . '<td>' . ($chg ? '<button type="button" onclick="dflReset(this)">' . t('Default') . '</button>' : '') . '</td></tr>';
    } } ?>
  </table>
</div>
<script>
function dflFilter() {
  var only = document.getElementById('dflonly').checked;  // no filter box (owner 2026-10-25)
  document.querySelectorAll('#dfltable tr[data-k]').forEach(function (tr) {
    tr.style.display = !only || tr.classList.contains('chg') ? '' : 'none';
  });
  // a section title only while one of its settings shows
  document.querySelectorAll('#dfltable tr.dflsec').forEach(function (sec) {
    var r = sec.nextElementSibling, any = false;
    for (; r && !r.classList.contains('dflsec'); r = r.nextElementSibling) if (r.style.display !== 'none') { any = true; break; }
    sec.style.display = any ? '' : 'none';
  });
}
dflFilter();  // the page opens with only the changes (owner 2026-10-27)
function dflPost(url, body, done) {
  var x = new XMLHttpRequest();
  x.onload = function () { done(this.status === 401 ? t('Log in first.') : this.responseText.trim()); };
  x.open('POST', url, true);
  if (body !== null) x.setRequestHeader('Content-Type', 'application/json');
  x.send(body);
}
function dflMark(inp) {
  var def = inp.closest('tr').cells[1].textContent;
  inp.classList.toggle('nondefault', !dflSame(inp.value, def === t('(empty)') ? '' : def));
}
function dflSame(a, b) {
  a = String(a).trim().replace(/^"|"$/g, ''); b = String(b).trim().replace(/^"|"$/g, '');
  return (a !== '' && b !== '' && !isNaN(a) && !isNaN(b)) ? parseFloat(a) === parseFloat(b) : a === b;
}
document.querySelectorAll('#dfltable .dflin').forEach(dflMark);
function dflEdited(inp) {
  dflMark(inp);
  inp.classList.toggle('edited', inp.value.trim() !== inp.dataset.orig);
}
// a field is written as soon as it is left (owner 2026-10-25: no Save button); keepalive: also when the page is left
function dflSaveOne(inp, leaving) {
  var v = inp.value.trim(), tr = inp.closest('tr'), k = tr.dataset.k, pairs = {};
  if (v === inp.dataset.orig) { inp.classList.remove('edited'); return; }
  pairs[k] = v;
  var msg = document.getElementById('dflmsg');
  fetch('scripts/defaults.php?save=1', {method: 'POST', headers: {'Content-Type': 'application/json'}, body: JSON.stringify(pairs), keepalive: true, credentials: 'same-origin'})
    .then(function (r) { return r.status === 401 ? t('Log in first.') : r.text(); })
    .then(function (r) {
      if (leaving) return;
      r = String(r).trim();
      if (r !== 'OK') { msg.textContent = r; msg.style.color = '#c62828'; return; }
      inp.dataset.orig = v; inp.classList.remove('edited');
      dflRowState(tr);
      msg.style.color = ''; msg.textContent = t('{key} saved', {key: k});
    })
    .catch(function () { if (!leaving) { msg.textContent = t('Not saved: the station did not answer'); msg.style.color = '#c62828'; } });
}
// the row after a change: marked and with its Default button when it differs from the factory value; the count above
function dflRowState(tr) {
  var inp = tr.querySelector('.dflin'), def = tr.cells[1].textContent, chg = !dflSame(inp.value, def === t('(empty)') ? '' : def);
  tr.classList.toggle('chg', chg);
  var cell = tr.cells[4], b = cell.querySelector('button');
  if (chg && !b) cell.innerHTML = '<button type="button" onclick="dflReset(this)">' + t('Default') + '</button>';
  if (!chg && b) b.remove();
  document.getElementById('dflcount').textContent = document.querySelectorAll('#dfltable tr.chg').length;
}
window.addEventListener('pagehide', function () {
  document.querySelectorAll('#dfltable .dflin.edited').forEach(function (i) { dflSaveOne(i, true); });
});
function dflResetAll() {
  if (!confirm(t('Put EVERY setting back to its default? (Save the current settings first if you want to keep them.)'))) { document.getElementById('dflprofiles').value = ''; return; }
  dflPost('scripts/defaults.php?resetall=1', null, function (r) { if (r !== 'OK') alert(r); else location.reload(); });
}
// Delete only for a saved set (not the factory defaults)
document.getElementById('dflprofiles').addEventListener('change', function () {
  // the factory defaults cannot be deleted (owner 2026-10-25): no Delete button for them at all
  document.getElementById('dfldel').disabled = !this.value || this.value === '__factory__';
  document.getElementById('dfldel').style.display = this.value === '__factory__' ? 'none' : '';
  document.getElementById('dflload').disabled = !this.value;
});
function dflProfile(act) {
  var n = act === 'save' ? document.getElementById('dflname').value.trim() : document.getElementById('dflprofiles').value;
  if (!n) return;
  if (n === '__factory__') { if (act === 'load') { dflResetAll(); } return; }
  if (act === 'save' && !/^[A-Za-z0-9_-]+$/.test(n)) { alert(t('Name: letters, digits, - and _ only')); return; }
  if (act === 'load' && !confirm(t('Write the saved settings "{name}" over the station\'s? (Save the current ones first if you want to keep them.)', {name: n}))) { document.getElementById('dflprofiles').value = ''; return; }
  if (act === 'delete' && !confirm(t('Delete the saved settings "{name}"? The station\'s settings stay as they are.', {name: n}))) return;
  dflPost('scripts/defaults.php?profile=' + act + '&name=' + encodeURIComponent(n), null, function (r) { alert(r); location.reload(); });
}
function dflReset(b) {
  var tr = b.closest('tr'), k = tr.dataset.k;
  if (!confirm(t('Put {key} back to its default ({value})?', {key: k, value: tr.cells[1].textContent}))) return;
  var x = new XMLHttpRequest();
  x.onload = function () {
    var r = this.status === 401 ? t('Log in first.') : this.responseText.trim();
    if (r !== 'OK') { document.getElementById('dflmsg').textContent = r; return; }
    var i = tr.querySelector('.dflin'); i.value = i.dataset.orig = tr.cells[1].textContent.replace(t('(empty)'), ''); i.classList.remove('edited'); dflMark(i); dflRowState(tr);
    document.getElementById('dflmsg').textContent = t('{key} is back to its default', {key: k});
  };
  x.open('POST', 'scripts/defaults.php?reset=' + encodeURIComponent(k), true);
  x.send();
}
</script>
