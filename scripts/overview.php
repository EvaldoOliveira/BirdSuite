<?php
error_reporting(E_ERROR);
ini_set('display_errors', 0);  // errors to the log, never to the page (2026-10-23 security run)
ini_set('session.gc_maxlifetime', 7200);
session_set_cookie_params(7200);
session_start(array('cookie_httponly' => true, 'cookie_samesite' => 'Lax'));  // as common.php BS_SESSION_OPTIONS (2026-10-23 test run)
if (!defined('NOW_PAGE')) define('NOW_PAGE', true); // the Location picklist of Now may show every location (owner 2026-10-21)
require_once 'scripts/common.php';
$home = get_home();
$config = get_config();

set_timezone();
// the session is only read from here on (2026-10-20): released at once, so the page's parallel requests (charts, list,
// stats, status every 5 s) no longer wait for each other on the session file lock
session_write_close();
$db = new SQLite3(BS_DB, SQLITE3_OPEN_READONLY);
apply_location_filter($db); // the location chosen in the header (US-58)
$db->busyTimeout(1000);

// Analysis settings from the Latest page: CONFIDENCE, SENSITIVITY, OVERLAP (the analysis reloads them on the next file)
if (isset($_GET['set_analysis'])) {
  ensure_authenticated(t('str_you_must_change_the_settings_help'));
  if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); die(); }
  $limits = array('CONFIDENCE' => array('confidence', 0.01, 0.99), 'SENSITIVITY' => array('sensitivity', 0.5, 1.5), 'OVERLAP' => array('overlap', 0.0, 2.9),
                  'SF_THRESH' => array('sf_thresh', 0.0005, 0.99));
  $set = array();
  foreach ($limits as $key => $l) {
    $v = str_replace(',', '.', trim($_POST[$l[0]] ?? ''));
    if ($v === '' || !is_numeric($v) || floatval($v) < $l[1] || floatval($v) > $l[2]) { echo t('str_invalid', array('name' => $l[0], 'min' => $l[1], 'max' => $l[2])); die(); }
    $set[$key] = rtrim(rtrim(sprintf($key === 'SF_THRESH' ? '%.4f' : '%.2f', floatval($v)), '0'), '.');
  }
  // recording length (owner 2026-10-09): whole seconds 3–60; the extraction length can not be longer; the recording
  // service is restarted so the next file has the new length
  $len = trim($_POST['recording_length'] ?? '');
  $restart = false;
  if ($len !== '') {
    if (!ctype_digit($len) || intval($len) < 3 || intval($len) > 60) { echo t('str_invalid_recording_length_3_to_help'); die(); }
    $restart = intval($len) !== intval($config['RECORDING_LENGTH'] ?? 15);
    $set['RECORDING_LENGTH'] = intval($len);
    if (intval($config['EXTRACTION_LENGTH'] ?? 0) > intval($len)) $set['EXTRACTION_LENGTH'] = intval($len);
  }
  $ok = conf_set_many($set, $err);
  if ($ok && $restart) exec('sudo -n ' . BS_SVC_HELPER . ' restart ' . BS_SVC_RECORDING . '.service > /dev/null 2>&1 &');
  echo $ok ? 'OK' : $err;
  die();
}

// The station moved? (owner 2026-10-10) scripts/location_check.py compares the network position with the coordinates
// at boot and writes location_check.json; every page asks (location_moved.php). location=use: the detected coordinates (4 decimals), the
// Brazilian state species list when the current filter is a state, the detected timezone; location=keep: remembered,
// asked again only after another move beyond the location's radius, at least LOCATION_IP_ERROR_KM (owner 2026-10-21)
if (isset($_GET['location'])) {
  ensure_authenticated(t('str_you_must_change_the_settings_help'));
  if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); die(); }
  $f = BS_STATE . '/location_check.json';
  $st = json_decode((string)@file_get_contents($f), true);
  if (!is_array($st) || empty($st['moved'])) { echo t('str_nothing_to_do'); die(); }
  $user = get_user();
  if ($_GET['location'] === 'update') {
    // simple mode (owner 2026-10-29, US-125): the station moved for good, the current location takes the detected
    // coordinates and timezone (the state species list follows them, apply_station_coordinates); no second location
    $cur = location_current();
    if (!$cur) { echo t('str_unknown_location'); die(); }
    $lat = round(floatval($st['lat']), 4); $lon = round(floatval($st['lon']), 4);
    $list = locations_load();
    foreach ($list as &$x) {
      if (intval($x['id']) !== intval($cur['id'])) continue;
      $x['lat'] = $lat; $x['lon'] = $lon;
      if (in_array((string)($st['timezone'] ?? ''), DateTimeZone::listIdentifiers(), true)) $x['timezone'] = $st['timezone'];
      unset($x['species_list']);  // the list of the state there, as the coordinates give it
    }
    unset($x);
    if (!locations_save($list)) { echo t('str_error_writing_the_settings'); die(); }
    $switch_to = intval($cur['id']);
    $st['moved'] = false; $st['configured'] = array('lat' => $lat, 'lon' => $lon); $st['distance_km'] = 0; $st['applied'] = date('Y-m-d H:i:s');
  } elseif ($_GET['location'] === 'use' || ctype_digit((string)$_GET['location'])) {
    // use = the detected position, as a location of its own when no location holds it (named after the city);
    // a location id = the station is at that registered location (US-58, owner 2026-10-20)
    if ($_GET['location'] === 'use') {
      $lat = round(floatval($st['lat']), 4); $lon = round(floatval($st['lon']), 4);
      if (!location_at($lat, $lon)) {
        $list = locations_load();
        $list[] = array('id' => max(array_map('intval', array_column($list, 'id'))) + 1,
          'name' => trim(($st['city'] ?? '') . (($st['region'] ?? '') !== '' ? ', ' . $st['region'] : '')) ?: "$lat, $lon",
          'lat' => $lat, 'lon' => $lon, 'radius_km' => LOCATION_RADIUS_KM);
        // the detected timezone comes with it
        if (in_array((string)($st['timezone'] ?? ''), DateTimeZone::listIdentifiers(), true)) $list[count($list) - 1]['timezone'] = $st['timezone'];
        locations_save($list);
      }
    } else {
      $l = location_by_id($_GET['location']);
      if (!$l) { echo t('str_unknown_location'); die(); }
      $lat = round(floatval($l['lat']), 4); $lon = round(floatval($l['lon']), 4);
    }
    // the change itself runs through scripts/location_switch.php (owner 2026-10-21): the page starts it with this id
    // (the location chosen by its id: two locations may share coordinates)
    $at = ctype_digit((string)$_GET['location']) ? location_by_id($_GET['location']) : location_at($lat, $lon);
    if (!$at) { echo t('str_unknown_location'); die(); }
    $switch_to = intval($at['id']);
    // a new place in simple mode (US-125): several locations are switched on, so both show and the old one can come back
    if ($_GET['location'] === 'use' && !multi_location()) {
      if (!conf_set('MULTI_LOCATION', '1', $err)) { echo $err; die(); }
    }
    $st['moved'] = false; $st['configured'] = array('lat' => $lat, 'lon' => $lon); $st['distance_km'] = 0; $st['applied'] = date('Y-m-d H:i:s');
  } else {
    $st['moved'] = false; $st['dismissed'] = array('lat' => $st['lat'], 'lon' => $st['lon']);
  }
  if (file_put_contents($f, json_encode($st, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)) === false) { echo t('str_error'); die(); }
  // ID:<id>:<name> (the name for the window of the change; the page has no list of locations in simple mode)
  echo isset($switch_to) ? 'ID:' . $switch_to . ':' . ((location_by_id($switch_to) ?: array())['name'] ?? '') : 'OK';
  die();
}

// Currently Analyzing status (owner 2026-10-09): recordings waiting for the analysis (StreamData *.wav minus the one
// being recorded), how far behind real time that is, and the recording the spectrogram shows (analyzing_now.txt)
if (isset($_GET['analysis_status'])) {
  header('Content-Type: application/json');
  $sd = rtrim($config['RECS_DIR'] ?? (BS_RECS), '/') . '/StreamData';
  $waiting = max(0, count(glob($sd . '/*.wav') ?: array()) - 1);
  $now = trim((string)@file_get_contents($sd . '/analyzing_now.txt'));
  $shown = preg_match('/(\d{4}-\d{2}-\d{2})_(\d{2})h(\d{2})m(\d{2})s/', basename($now), $m) ? "$m[2]:$m[3]:$m[4]" : '';
  // behind = age of the recording being analysed (its name carries when it started); without it, waiting × length
  $behind = $shown !== '' ? max(0, time() - strtotime("$m[1] $shown")) : $waiting * max(1, intval($config['RECORDING_LENGTH'] ?? 15));
  $running = trim((string)shell_exec('systemctl is-active ' . BS_SVC_ANALYSIS . '.service 2>/dev/null')) === 'active';
  echo json_encode(array('waiting' => $waiting, 'behind' => $behind, 'shown' => $shown, 'running' => $running));
  die();
}

// "Set as default" of the Latest page: LATEST_VIEW = simple | list (Full) | spectrogram, and with it (owner 2026-10-23) how many
// detections (LATEST_COUNT), the period of a filtered list (LATEST_PERIOD) and the values the filters start with (LATEST_LOWPROB,
// LATEST_LOWCONF, LATEST_UNCOMMON); each one optional
if (isset($_GET['set_now_view'])) {
  ensure_authenticated(t('str_you_must_change_the_settings_help'));
  if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); die(); }
  $set = array('LATEST_VIEW' => in_array($_GET['set_now_view'], array('simple', 'list'), true) ? $_GET['set_now_view'] : 'spectrogram');
  if (isset($_GET['count']) && in_array(intval($_GET['count']), array(10, 25, 50, 100), true)) $set['LATEST_COUNT'] = intval($_GET['count']);
  if (isset($_GET['period']) && in_array($_GET['period'], array('today', '7d', 'all'), true)) $set['LATEST_PERIOD'] = $_GET['period'];
  foreach (array('lowprob' => 'LATEST_LOWPROB', 'lowconf' => 'LATEST_LOWCONF') as $p => $k) {
    if (isset($_GET[$p]) && ctype_digit($_GET[$p]) && intval($_GET[$p]) >= 5 && intval($_GET[$p]) <= 95) $set[$k] = intval($_GET[$p]);
  }
  if (isset($_GET['uncommon']) && ctype_digit($_GET['uncommon']) && intval($_GET['uncommon']) >= 1 && intval($_GET['uncommon']) <= 100000) $set['LATEST_UNCOMMON'] = intval($_GET['uncommon']);
  // the Rare filter's days are the rare species threshold (owner 2026-10-25: moved here from Settings › Themes)
  if (isset($_GET['rare']) && ctype_digit($_GET['rare']) && intval($_GET['rare']) >= 1 && intval($_GET['rare']) <= 3650) $set['RARE_SPECIES_THRESHOLD'] = intval($_GET['rare']);
  echo conf_set_many($set, $err) ? 'OK' : $err;
  die();
}

// the newest detection's clip: the page reloads its list when it changes
if (isset($_GET['latest_file'])) {
  $r = $db->querySingle('SELECT Date, Sci_Name, File_Name FROM detections ORDER BY Date DESC, Time DESC LIMIT 1', true);
  echo $r ? clip_rel($r) : '';
  die();
}

if (get_included_files()[0] === __FILE__) {
  echo '<!DOCTYPE html>
<html lang="' . str_replace('_', '-', ui_lang()) . '">
<head>
' . ui_js() . '
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Latest</title>
</head>';
}
?>
<div class="overview">
<div class="overview-stats part-now">
<div class="right-column">
<?php // the clock not confirmed: on every page now (owner 2026-11-03, scripts/location_moved.php) ?>
<?php $curloc = location_current(); // the station's current location: its line is at the bottom of the page (owner 2026-10-20) ?>
<?php // the microphone question is a window on every page now (owner 2026-10-24, static/location-switch.js) ?>
<!-- today's totals and the search, formerly the top of Today's Detections (owner 2026-10-09) -->
<?php // the question "did the station move?" is on every page now (owner 2026-10-30, scripts/location_moved.php) ?>
<!-- Latest page (owner 2026-10-08): Currently Analyzing first, then the most recent detections; the page asks for the
     newest clip and refreshes the list when it changes -->
<?php // first line (owner 2026-10-21): the analysis state ?>
<h3 class="now-only nowsec nowanalyzing">
  <span id="analysis_status" class="nowstatus" title="<?php echo t('str_recordings_waiting_for_the_help'); ?>"></span>
</h3>
<?php // the Now Recording button (was Live Spectro) on its own line, on the left, right above the live spectrogram (owner 2026-10-21) ?>
<div class="now-only nowmodes nowlivespec"><button type="button" id="analyzing_toggle" onclick="toggleAnalyzing()" title="<?php echo t('str_show_or_hide_the_live_spectrogram'); ?>"><?php echo t('str_now_recording'); ?></button></div>
<style>
/* one line, also on a phone (owner 2026-10-21): the state shrinks, the Now Recording button stays on the right */
h3.nowsec.nowanalyzing { display: flex; align-items: center; gap: 8px; flex-wrap: nowrap !important; margin: 0 0 2px !important; min-height: 1.5em; padding-top: 0; } /* one line kept while the state loads (2026-10-23 test run: it moved the page when filled) */
.nowanalyzing .nowstatus { margin: 0; flex: 1 1 auto; min-width: 0; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.nowlivespec { display: block; text-align: left; margin: 0 0 4px; }
</style>
<?php
// a number in any case: an empty or odd RECORDING_LENGTH left "*1000" in the script below and stopped the whole page
$refresh = max(3, min(60, intval($config['RECORDING_LENGTH'] ?? 15) ?: 15));
$dividedrefresh = max(1, intval($refresh / 4));  // the newest clip is asked four times per recording
$time = time();
echo "<img id=\"spectrogramimage\" src=\"spectrogram.png?nocache=$time\">";
// the Essential level opens in Simple (owner 2026-11-05); Birder and Expert in the station's LATEST_VIEW
$now_view_cfg = ui_level() === 'essential' ? 'simple' : (in_array($config['LATEST_VIEW'] ?? 'list', array('simple', 'spectrogram'), true) ? $config['LATEST_VIEW'] : 'list');

?>
<?php // the parts of the view shown or hidden before the first paint (2026-10-23 test run: the live spectrogram and its
// button went away at DOMContentLoaded and moved the list, layout shift 0.13-0.25); the rules of nowView / applyAnalyzing
// below, which keep them up to date afterwards ?>
<script>(function () {
  var spectro = !bsPhone() && <?php echo json_encode($now_view_cfg); ?> === 'spectrogram';
  var img = document.getElementById('spectrogramimage'), b = document.getElementById('analyzing_toggle');
  if (img) img.style.display = spectro ? '' : 'none';
  if (b) b.parentNode.style.display = spectro ? '' : 'none';
})();</script>
<?php // the bar over the table (owner 2026-10-21): location, view, then Param. and the filter on the right; the count
// of detections shown is under the table ?>
<h3 class="now-only now-cards nowhead nowsec">
  <?php echo location_picker(); // Loc: the data filter shared by every page (owner 2026-10-22) ?>
  <span class="nowmodes"><button type="button" data-mode="simple" onclick="nowMode('simple')" title="<?php echo t('str_time_species_confidence_and_help'); ?>"><?php echo t('str_simple'); ?></button><button type="button" data-mode="list" onclick="nowMode('list')" title="<?php echo t('str_every_column_date_minimums_and_help'); ?>"><?php echo t('str_full'); ?></button><button type="button" data-mode="spectrogram" onclick="nowMode('spectrogram')"><?php echo t('str_spectrogram'); ?></button>
  <button type="button" class="setdefault" id="nowview_default" onclick="nowSetDefault()" title="<?php echo t('str_open_the_latest_page_with_this_help'); ?>"><?php echo t('str_set_as_default'); ?></button></span>
  <!-- the filter buttons and the search open and close with the Filter icon: closed on a phone, open on a wider screen (owner 2026-10-20);
       the icon is filled while a filter or a search applies -->
  <style>
  .nowsearch.nfclosed .nowfilters > :not(.nowfilterlabel), .nowsearch.nfclosed > input, .nowsearch.nfclosed > select { display: none; }
  .nowhead .nowright { margin-left: auto; display: flex; align-items: center; gap: 6px; font-weight: normal; }
  .nowhead .nowright .nowsearch { margin-left: 0; }
  .nowfilters .nowfilterlabel { cursor: pointer; border-radius: 50%; padding: 3px; }
  /* filled while the filter line is open (its filters in force), plain while folded: the global rule of US-132 (owner 2026-11-02) */
  .nowsearch:not(.nfclosed) .nowfilters .nowfilterlabel { background: var(--accent,#2b5e22); color: #fff; }
  </style>
  <span class="nowright">
  <button type="button" class="naopen" id="na_open" onclick="naShow(true)" title="<?php echo t('str_analysis_settings_min_conf_min_help'); ?>"><?php echo t('str_param'); ?></button>
  <span class="nowsearch nfclosed" id="nowsearch"><span class="nowmodes nowfilters" title="<?php echo t('str_only_the_detections_worth_a_help'); ?>">
    <span class="nowfilterlabel" id="nowfiltericon" title="<?php echo t('str_filter_and_search_show_hide'); ?>" role="button" tabindex="0" onclick="nowFilterPanel()" onkeydown="if (event.key === 'Enter' || event.key === ' ') { event.preventDefault(); nowFilterPanel(); }"><svg viewBox="0 0 24 24" width="18" height="18" aria-label="<?php echo t('str_filter'); ?>" role="img"><path fill="currentColor" d="M3 4h18l-7 8.5V19l-4 2v-8.5z"/></svg></span><button type="button" data-filter="" onclick="nowFilter('')"><?php echo t('str_all'); ?></button><button type="button" data-filter="uncommon" onclick="nowFilter('uncommon')" title="<?php echo t('str_only_species_detected_at_most_n_help'); ?>"><?php echo t('str_uncommon'); ?></button><button type="button" data-filter="rare" onclick="nowFilter('rare')" title="<?php echo t('str_only_species_with_no_detection_help'); ?>"><?php echo t('str_rare_filter'); ?></button><button type="button" data-filter="lowconf" onclick="nowFilter('lowconf')"><?php echo t('str_low_conf'); ?></button><button type="button" data-filter="lowprob" onclick="nowFilter('lowprob')"><?php echo t('str_low_prob'); ?></button><button type="button" data-filter="loss" onclick="nowFilter('loss')" title="<?php echo t('str_audio_loss_filter_help'); ?>"><?php echo t('str_audio_loss'); ?></button>
    </span>
    <?php // Uncommon picklist: from the largest species count since yesterday 00:00 down to 1, in round steps
      $most = intval(get_db()->querySingle("SELECT MAX(n) FROM (SELECT COUNT(*) AS n FROM detections WHERE Date >= DATE('now', 'localtime', '-1 day') GROUP BY Sci_Name)"));
      $steps = array();
      foreach (array(1, 2, 3, 5, 10, 20, 30, 50, 100, 200, 300, 500, 1000, 2000, 3000, 5000, 10000, 20000, 50000) as $v) if ($v < $most) $steps[] = $v;
      if ($most > 0) $steps[] = $most;
      rsort($steps); ?>
    <script>var NOW_UNCOMMON_STEPS = <?php echo json_encode($steps ?: array(1)); ?>;</script>
    <select id="nowperiod" class="nowperiod" onchange="nowSetPeriod(this.value)" title="<?php echo t('str_period_searched_by_the_filters_help'); ?>"><option value="today"><?php echo t('str_today'); ?></option><option value="7d"><?php echo t('str_7_days'); ?></option><option value="all"><?php echo t('str_all'); ?></option></select>
    <input autocomplete="off" size="22" type="search" placeholder="<?php echo htmlspecialchars(t('str_search_detections')); ?>" id="searchterm"
    title="<?php echo htmlspecialchars(t('str_common_or_scientific_name_time_help')); ?>" oninput="nowSearchTyped(this.value)"></span></span></h3>
<?php // the today / all-days cards left Now (owner 2026-10-21): the day's are in Detections › By Hour, the all-days ones in
// By Week; the analysis settings open under the bar from its Param. button (Full and Spectrogram views only) ?>
<div class="now-only nowtoday" id="na_wrap">
  <!-- analysis settings, applied from the next recording (owner 2026-10-09) -->
  <!-- folded to one Param. button (owner 2026-10-10: the box takes too much room on a phone); open / closed is remembered
       per browser: closed by default on a phone, open on a wider screen -->
  <div class="nacontrols"><div class="nablock">
  <form class="nowanalysis" id="na_form" onsubmit="saveAnalysis(event)" title="<?php echo t('str_applied_from_the_next_recording_help', array('s' => intval($config['RECORDING_LENGTH'] ?? 15))); ?>">
    <!-- Default: the BirdSuite defaults (factory_defaults.conf, US-121) with the BirdNET+ V3 model; the V2.4 generation keeps
         its own scale (Loc. Thresh. 0.03), then saved like Apply -->
    <?php $is_v3 = strpos($config['MODEL'] ?? '', 'BirdNET-Plus') === 0;
      $bd = birdsuite_defaults();
      $na_defaults = $is_v3 ? array('na_conf' => (string)($bd['CONFIDENCE'] ?? '0.4'), 'na_sf' => (string)($bd['SF_THRESH'] ?? '0.05'), 'na_sens' => (string)($bd['SENSITIVITY'] ?? '1'),
                                    'na_len' => (string)($bd['RECORDING_LENGTH'] ?? '15'), 'na_over' => (string)($bd['OVERLAP'] ?? '0.5'))
                            : array('na_conf' => '0.7', 'na_sf' => '0.03', 'na_sens' => '1.25', 'na_len' => '15', 'na_over' => '0'); ?>
    <button type="button" class="nadefault" onclick="naDefaults()" data-defaults="<?php echo htmlspecialchars(json_encode($na_defaults), ENT_QUOTES); ?>"
      title="<?php echo t('str_back_to_the_defaults_and_save', array('list' => $is_v3 ? t('str_birdsuite_min_conf_min_prob_help', array('conf' => fmt_setting($na_defaults['na_conf']), 'sf' => fmt_setting($na_defaults['na_sf']), 'sens' => fmt_setting($na_defaults['na_sens']), 'len' => fmt_setting($na_defaults['na_len']), 'over' => fmt_setting($na_defaults['na_over']))) : t('str_birdnet_v2_4_model_min_conf_0_7_help'))); ?>"><?php echo t('str_default_button'); ?></button>
    <!-- two groups (owner 2026-10-09): the thresholds, then the analysis settings -->
    <span class="nagroup" title="<?php echo t('str_minimums'); ?>">
      <label title="<?php echo t('str_min_conf_the_minimum_confidence_help', array('def' => htmlspecialchars(fmt_setting($bd['CONFIDENCE'] ?? '')))); ?>"><?php echo t('str_min_conf'); ?> <input type="number" id="na_conf" min="0.01" max="0.99" step="any" value="<?php echo htmlspecialchars($config['CONFIDENCE'] ?? ''); ?>"></label>
      <label title="<?php echo t('str_min_prob_the_minimum_help', array('def' => htmlspecialchars(fmt_setting($bd['SF_THRESH'] ?? '')))); ?>"><?php echo t('str_min_prob'); ?> <input type="number" id="na_sf" min="0.0005" max="0.99" step="any" value="<?php echo htmlspecialchars($config['SF_THRESH'] ?? ''); ?>"></label>
    </span>
    <span class="nagroup" title="<?php echo t('str_analysis'); ?>">
      <label title="<?php echo t('str_sigm_sens_bends_the_model_and_help', array('def' => htmlspecialchars(fmt_setting($bd['SENSITIVITY'] ?? '')))); ?>"><?php echo t('str_sigm_sens'); ?> <input type="number" id="na_sens" min="0.5" max="1.5" step="any" value="<?php echo htmlspecialchars($config['SENSITIVITY'] ?? ''); ?>"></label>
      <label title="<?php echo t('str_rec_length_seconds_of_each_help', array('def' => htmlspecialchars(fmt_setting($bd['RECORDING_LENGTH'] ?? '15')))); ?>"><?php echo t('str_rec_length'); ?> <input type="number" id="na_len" min="3" max="60" step="any" value="<?php echo htmlspecialchars($config['RECORDING_LENGTH'] ?? '15'); ?>"></label>
      <label title="<?php echo t('str_overlap_seconds_the_3_s_help', array('def' => htmlspecialchars(fmt_setting($bd['OVERLAP'] ?? '')))); ?>"><?php echo t('str_overlap'); ?> <input type="number" id="na_over" min="0" max="2.9" step="any" value="<?php echo htmlspecialchars($config['OVERLAP'] ?? ''); ?>"></label>
    </span>
    <span class="nabuttons"><button type="button" class="naclose" onclick="naShow(false)" title="<?php echo t('str_fold_the_settings_away'); ?>"><?php echo t('str_close'); ?></button><button type="submit" id="na_apply"><?php echo t('str_apply'); ?></button></span>
  </form>
  </div>
  </div>
</div>
<?php // the analysis settings open or closed before the first paint, as naShow / naApply do at DOMContentLoaded (2026-10-23
// test run: the box folding away then moved the list) ?>
<script>(function () {
  var simple = bsPhone() || <?php echo json_encode($now_view_cfg); ?> === 'simple';
  var s = null; try { s = localStorage.getItem('now_params'); } catch (e) {}
  var open = s ? s === 'open' : <?php echo json_encode(array(($config['LATEST_PARAMS_PHONE'] ?? 'closed') === 'open', ($config['LATEST_PARAMS_DESKTOP'] ?? 'open') !== 'closed')); ?>[!bsPhone() ? 1 : 0];  // NOW_PARAMS_* (owner 2026-10-25)
  var f = document.getElementById('na_form'), b = document.getElementById('na_open'), w = document.getElementById('na_wrap');
  if (f) f.style.display = open ? '' : 'none';
  if (b) b.style.display = (simple || open) ? 'none' : '';
  if (w) w.style.display = simple ? 'none' : '';
})();</script>
<div style="padding-bottom:10px;" id="detections_table"><h3><?php echo t('str_loading'); ?></h3></div>
<?php // the location line under the table left Now (owner 2026-10-21): the header and the picklist name it ?>

<br>

</div>
</div>
</div>
<script>
// the periodic questions of the page (2026-11-04 hardening): one at a time per kind (a slow station piled up a request
// every few seconds), a 15 s timeout, only a 200 answer counts
var nowBusy = {};
function nowGet(kind, url, ok) {
  if (nowBusy[kind]) return;
  nowBusy[kind] = true;
  var x = new XMLHttpRequest();
  x.timeout = 15000;
  x.onloadend = function () { nowBusy[kind] = false; };
  x.onload = function () { if (this.status === 200) ok(this.responseText); };
  x.open('GET', url, true);
  x.send();
}
// the newest clip is asked every few seconds: a new one reloads the list (newest page only; the first answer loads it too)
var lastDetection;
function loadDetectionIfNewExists() {
  nowGet('latest', 'overview.php?latest_file=1', function (txt) {
    var f = txt.trim();
    if (f === '' || f.includes("Database is busy") || f === lastDetection) return;
    var first = lastDetection === undefined;
    lastDetection = f;
    if (nowOffset === 0 || first) loadFiveMostRecentDetections();
  });
}
function refreshDetection() {
  if (document.hidden) return;
  // not while a sound of the page plays
  var playing = false;
  document.querySelectorAll('audio').forEach(function (a) { if (!a.paused && !a.ended && a.currentTime > 0) playing = true; });
  if (!playing) loadDetectionIfNewExists();
}
// The live spectrogram belongs to the Spectrogram view (owner 2026-10-21): there it is on by default and its button
// on the first line hides it; Simple and Full show neither the spectrogram nor the button
var analyzingOn = true;
function applyAnalyzing() {
  var spectro = typeof nowView !== 'undefined' && nowView === 'spectrogram';
  var img = document.getElementById('spectrogramimage'), b = document.getElementById('analyzing_toggle');
  if (img) img.style.display = (spectro && analyzingOn) ? '' : 'none';
  if (b) { b.parentNode.style.display = spectro ? '' : 'none'; b.classList.toggle('active', analyzingOn); }
}
function toggleAnalyzing() {
  analyzingOn = !analyzingOn;
  applyAnalyzing();
  if (analyzingOn) document.getElementById('spectrogramimage').src = 'spectrogram.png?nocache=' + Date.now();
}
document.addEventListener('DOMContentLoaded', applyAnalyzing);
function refreshAnalysisStatus() {
  if (document.hidden) return;
  nowGet('status', 'overview.php?analysis_status=1', function (txt) {
    var st; try { st = JSON.parse(txt); } catch (e) { return; }
    if (!st) return;
    var el = document.getElementById('analysis_status');
    if (!el) return;
    var behind = st.behind >= 60 ? Math.round(st.behind / 60) + 'min' : (st.behind || 0) + 's';
    // "12:48:30, Waiting: 2, Delay: 16s" (owner 2026-10-21): the time of the recording shown first
    el.textContent = (st.shown ? st.shown + ', ' : '') + (!st.running ? t('str_analysis_stopped') + ', ' : '')
      + t('str_waiting', {n: st.waiting}) + ', ' + t('str_delay', {time: st.waiting === 0 ? '0s' : behind});
    el.classList.toggle('late', !st.running || st.waiting > 10);
  });
}
document.addEventListener('DOMContentLoaded', function () { refreshAnalysisStatus(); setInterval(refreshAnalysisStatus, 5000); });
// the Now cards page through the detections 50 at a time; only the newest page follows new detections
var nowOffset = 0;
var nowTerm = '';
// any value is accepted while typing; leaving a field rounds it to its precision and keeps it inside its limits
// (owner 2026-10-09: the browser refused 0.5 for Loc. Thresh., whose min 0.0005 + step 0.01 allowed only 0.0105, 0.0205...)
// a field whose value is not the model's default turns light yellow (owner 2026-10-10)
function naMarkDefaults() {
  var b = document.querySelector('.nadefault');
  if (!b) return;
  var d = JSON.parse(b.dataset.defaults);
  var any = false;
  Object.keys(d).forEach(function (id) {
    var inp = document.getElementById(id);
    var off = !!inp && parseFloat(inp.value) !== parseFloat(d[id]);
    if (inp) inp.classList.toggle('nondefault', off);
    any = any || off;
  });
  // the Default button only while a value is off its default (owner 2026-11-04, the Watch pages)
  b.style.display = any ? '' : 'none';
}
document.addEventListener('DOMContentLoaded', function () {
  naMarkDefaults();
  ['na_conf', 'na_sf', 'na_sens', 'na_len', 'na_over'].forEach(function (id) { var inp = document.getElementById(id); if (inp) inp.addEventListener('input', naMarkDefaults); });
});
function naDefaults() {
  var d = JSON.parse(document.querySelector('.nadefault').dataset.defaults);
  Object.keys(d).forEach(function (id) { var inp = document.getElementById(id); if (inp) inp.value = d[id]; });
  naMarkDefaults();
  document.getElementById('na_form').requestSubmit();
}
var naOpen = false;
function naShow(open) {
  naOpen = open;
  try { localStorage.setItem('now_params', open ? 'open' : 'closed'); } catch (e) {}
  naApply();
}
// the analysis settings belong to the Full and Spectrogram views (owner 2026-10-21): hidden in Simple
function naApply() {
  var simple = typeof nowView !== 'undefined' && nowView === 'simple';
  var f = document.getElementById('na_form'), b = document.getElementById('na_open'), w = document.getElementById('na_wrap');
  if (f) f.style.display = naOpen ? '' : 'none';
  if (b) b.style.display = (simple || naOpen) ? 'none' : '';
  if (w) w.style.display = simple ? 'none' : '';
}
document.addEventListener('DOMContentLoaded', function () {
  var saved = null;
  try { saved = localStorage.getItem('now_params'); } catch (e) {}
  naShow(saved ? saved === 'open' : NOW_OPEN_DEFAULTS.params[!bsPhone() ? 1 : 0]);
});
var NA_DECIMALS = {na_conf: 2, na_sf: 4, na_sens: 2, na_over: 1, na_len: 0};
function naRound(inp) {
  var v = parseFloat(String(inp.value).replace(',', '.'));
  if (isNaN(v)) return;
  v = Math.min(parseFloat(inp.max), Math.max(parseFloat(inp.min), v));
  inp.value = String(parseFloat(v.toFixed(NA_DECIMALS[inp.id])));
}
document.addEventListener('DOMContentLoaded', function () {
  Object.keys(NA_DECIMALS).forEach(function (id) {
    var inp = document.getElementById(id);
    if (inp) inp.addEventListener('change', function () { naRound(inp); naMarkDefaults(); });
  });
});
function saveAnalysis(e) {
  Object.keys(NA_DECIMALS).forEach(function (id) { var inp = document.getElementById(id); if (inp) naRound(inp); });
  e.preventDefault();
  // no message beside the button (owner 2026-10-09: it pushed the line); the button itself says Saved for a moment,
  // an error opens a dialog
  var b = document.getElementById('na_apply');
  var x = new XMLHttpRequest();
  x.onload = function () {
    if (this.status === 200 && this.responseText.trim() === 'OK') {
      b.textContent = '\u2713';
      b.title = t('str_saved_applied_from_the_next_help');
      setTimeout(function () { b.textContent = t('str_apply'); }, 1500);
    } else bsNotice(t('str_not_saved_reason', {reason: this.status === 401 ? t('str_log_in_first_lc') : this.responseText}));
  };
  x.open('POST', 'overview.php?set_analysis=1', true);
  x.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
  x.send('confidence=' + encodeURIComponent(document.getElementById('na_conf').value)
    + '&sensitivity=' + encodeURIComponent(document.getElementById('na_sens').value) + '&overlap=' + encodeURIComponent(document.getElementById('na_over').value)
    + '&sf_thresh=' + encodeURIComponent(document.getElementById('na_sf').value) + '&recording_length=' + encodeURIComponent(document.getElementById('na_len').value));
}
// filters (owner 2026-10-09/10): Uncommon (species detected at most N times since yesterday 00:00), Low Conf (confidence
// below P %), Low Prob (location probability below P %) — each button switches its filter on/off, several apply together,
// each active one keeps its picklist open beside its button; All switches them all off. Remembered in this browser.
var nowFilters = {};
function nowStore(k, v) { try { if (v === undefined) return localStorage.getItem(k); localStorage.setItem(k, v); } catch (e) {} return null; }
// the values a filter starts with (LATEST_LOWPROB / LATEST_LOWCONF / LATEST_UNCOMMON of factory_defaults.conf, US-121)
var NOW_FILTER_DEFAULTS = <?php echo json_encode(array('lowprob' => intval($config['LATEST_LOWPROB'] ?? 40), 'lowconf' => intval($config['LATEST_LOWCONF'] ?? 60), 'uncommon' => intval($config['LATEST_UNCOMMON'] ?? 10), 'rare' => max(1, intval($config['RARE_SPECIES_THRESHOLD'] ?? 30)))); ?>;
// the days of the Rare filter, with the station's own value among them
var NOW_RARE_STEPS = [7, 14, 30, 60, 90, 180, 365].concat([NOW_FILTER_DEFAULTS.rare]).filter(function (v, i, a) { return a.indexOf(v) === i; }).sort(function (a, b) { return a - b; });
function nowDefaultValue(kind) {
  if (kind === 'rare') return String(NOW_FILTER_DEFAULTS.rare);
  if (kind === 'loss') return '0';
  if (kind === 'uncommon') return String(NOW_UNCOMMON_STEPS.filter(function (x) { return x <= NOW_FILTER_DEFAULTS.uncommon; })[0] || NOW_UNCOMMON_STEPS[NOW_UNCOMMON_STEPS.length - 1]);
  return String(Math.round(NOW_FILTER_DEFAULTS[kind] / 5) * 5 || 50);
}
function nowFilterOptions(kind) {
  if (kind === 'rare') return NOW_RARE_STEPS.map(function (v) { return [v, '\u2265 ' + t('str_days_without', {n: v})]; });
  // the audio the recorder lost in the recording, an xrun (owner 2026-11-04): any, or more than N ms
  if (kind === 'loss') return [[0, t('str_any_loss')], [1, '> 1 ms'], [5, '> 5 ms'], [20, '> 20 ms'], [100, '> 100 ms']];
  return kind === 'uncommon'
    ? NOW_UNCOMMON_STEPS.map(function (v) { return [v, '\u2264 ' + t('str_since_yesterday', {n: v})]; })
    : Array.apply(null, Array(19)).map(function (_, i) { var v = (i + 1) * 5; return [v, '< ' + v + '%']; });
}
function nowFilterButtons() {
  var any = Object.keys(nowFilters).length > 0;
  document.querySelectorAll('.nowfilters button[data-filter]').forEach(function (b) {
    var k = b.dataset.filter;
    b.classList.toggle('active', k === '' ? !any : nowFilters[k] !== undefined);
    if (k === '') return;
    var sel = document.getElementById('nowbelow_' + k);
    if (nowFilters[k] === undefined) { if (sel) sel.remove(); return; }
    if (!sel) {
      sel = document.createElement('select');
      sel.id = 'nowbelow_' + k;
      sel.className = 'nowbelow';
      sel.innerHTML = nowFilterOptions(k).map(function (o) { return '<option value="' + o[0] + '">' + o[1] + '</option>'; }).join('');
      // the chosen value is kept as this filter's default: switched off and on again, it comes back with it
      sel.onchange = function () { nowFilters[k] = sel.value; nowStore('now_below_' + k, sel.value); nowSaveFilters(); if (typeof nowDefaultMark === 'function') nowDefaultMark(); nowOffset = 0; loadFiveMostRecentDetections(); };
      b.after(sel);
    }
    if (![].some.call(sel.options, function (o) { return o.value === String(nowFilters[k]); })) nowFilters[k] = nowDefaultValue(k);
    sel.value = nowFilters[k];
  });
  nowFilterMark();
}
// closing the panel switches the filters and the search off (like All); opening it again brings back the last ones
// (owner 2026-10-20) — kept in nowParked meanwhile, and in the browser for the next visit
var nowParked = null;
function nowFilterPanel() {
  var closed = document.getElementById('nowsearch').classList.toggle('nfclosed');
  if (closed) {
    nowParked = {filters: nowFilters, term: nowTerm};
    nowFilters = {};
    nowTerm = '';
  } else if (nowParked) {
    nowFilters = nowParked.filters;
    nowTerm = document.getElementById('searchterm').value.trim();
    nowParked = null;
  }
  nowFilterButtons();
  nowOffset = 0;
  loadFiveMostRecentDetections();
}
// the icon shows that something filters the list, also while the buttons are hidden
// the panels open or closed by default, phone / wider screen (owner 2026-10-25: Settings › Defaults, Latest page)
var NOW_OPEN_DEFAULTS = <?php echo json_encode(array('filters' => array(($config['LATEST_FILTERS_PHONE'] ?? 'closed') === 'open', ($config['LATEST_FILTERS_DESKTOP'] ?? 'open') !== 'closed'),
  'params' => array(($config['LATEST_PARAMS_PHONE'] ?? 'closed') === 'open', ($config['LATEST_PARAMS_DESKTOP'] ?? 'open') !== 'closed'))); ?>;
function nowFilterMark() {
  var i = document.getElementById('nowfiltericon');
  if (i) i.classList.toggle('nfon', Object.keys(nowFilters).length > 0 || nowTerm !== '');
}
function nowSaveFilters() { if (!nowParked) nowStore('now_filters', JSON.stringify(nowFilters)); }
function nowFilter(kind) {
  if (kind === '') nowFilters = {};
  else if (nowFilters[kind] !== undefined) delete nowFilters[kind];
  else nowFilters[kind] = nowStore('now_below_' + kind) || nowDefaultValue(kind);
  nowFilterButtons();
  Object.keys(nowFilters).forEach(function (k) { nowStore('now_below_' + k, nowFilters[k]); });
  nowSaveFilters();
  nowOffset = 0;
  loadFiveMostRecentDetections();
}
document.addEventListener('DOMContentLoaded', function () {
  try { nowFilters = JSON.parse(nowStore('now_filters') || '{}') || {}; } catch (e) { nowFilters = {}; }
  Object.keys(nowFilters).forEach(function (k) { if (['uncommon', 'rare', 'lowconf', 'lowprob', 'loss'].indexOf(k) < 0) delete nowFilters[k]; });
  // the page always opens with the filter off (owner 2026-10-21): the saved filters wait for the Filter icon
  nowParked = {filters: nowFilters, term: ''}; nowFilters = {};
  nowFilterButtons();
  // a link that asks for a filter (owner 2026-11-04: Station › Microphone › Audio loss opens the sonograms of the
  // detections with lost audio): &lfilter=loss[&lval=N][&lview=spectrogram][&lperiod=today|7d|all]
  var lq = new URLSearchParams(location.search);
  if (lq.get('lfilter') === 'loss') {
    if (['simple', 'list', 'spectrogram'].indexOf(lq.get('lview')) >= 0) { nowView = lq.get('lview'); nowViewChosen = true; nowModeButtons(); }
    if (['today', '7d', 'all'].indexOf(lq.get('lperiod')) >= 0) { nowPeriod = lq.get('lperiod'); var pp = document.getElementById('nowperiod'); if (pp) pp.value = nowPeriod; }
    nowParked = {filters: {loss: /^\d+$/.test(lq.get('lval') || '') ? lq.get('lval') : '0'}, term: ''};
    if (document.getElementById('nowsearch').classList.contains('nfclosed')) nowFilterPanel();
    else { nowFilters = nowParked.filters; nowParked = null; nowFilterButtons(); nowOffset = 0; loadFiveMostRecentDetections(); }
    return;
  }
  // open by default when the station says so for this kind of screen (LATEST_FILTERS_PHONE / LATEST_FILTERS_DESKTOP)
  if (NOW_OPEN_DEFAULTS.filters[!bsPhone() ? 1 : 0]) { nowFilterPanel(); return; }
  if (Object.keys(nowFilters).length) loadFiveMostRecentDetections();
});
// period of the filters and the search (owner 2026-10-20): Today by default — a filter that matches few detections
// otherwise walks the whole history; remembered per browser
var nowPeriod = (function () { var v = nowStore('now_period'); return ['today', '7d', 'all'].indexOf(v) >= 0 ? v : <?php echo json_encode(in_array($config['LATEST_PERIOD'] ?? 'today', array('today', '7d', 'all'), true) ? $config['LATEST_PERIOD'] : 'today'); ?>; })();
document.addEventListener('DOMContentLoaded', function () { var p = document.getElementById('nowperiod'); if (p) p.value = nowPeriod; });
function nowSetPeriod(v) { nowPeriod = v; nowStore('now_period', v); if (typeof nowDefaultMark === 'function') nowDefaultMark(); nowOffset = 0; if (Object.keys(nowFilters).length || nowTerm) loadFiveMostRecentDetections(); }
function nowFilterQuery() { return Object.keys(nowFilters).map(function (k) { return '&' + k + '=' + encodeURIComponent(nowFilters[k]); }).join('') + '&period=' + nowPeriod; }
// every page load starts sorted by time, newest first (owner 2026-10-20); a column click sorts until the next reload
try { Object.keys(localStorage).forEach(function (k) { if (/^stdsort:.*:detections_table$/.test(k)) localStorage.removeItem(k); }); } catch (e) {}
function nowSearch(v) { nowTerm = v.trim(); nowOffset = 0; nowFilterMark(); loadFiveMostRecentDetections(); }
// search as you type (owner 2026-10-09): 300 ms after the last key; an empty box shows every detection again
var nowSearchTimer = null;
function nowSearchTyped(v) {
  clearTimeout(nowSearchTimer);
  nowSearchTimer = setTimeout(function () { if (v.trim() !== nowTerm) nowSearch(v); }, v.trim() === '' ? 0 : 300);
}
// spectrogram cards or list (owner 2026-10-09): LATEST_VIEW (Settings › Defaults) is the default (list when unset, owner
// 2026-10-10), the buttons switch it
var nowView = <?php echo json_encode($now_view_cfg); ?>;
// a phone opens in Simple with the filters closed; a wider screen opens in the default view (Full unless set) with the
// filters open (owner 2026-10-20); the buttons and the Filter icon still switch
var nowPhone = bsPhone();
var nowDefault = nowView; // the saved default, before the phone override, so "Set as default" compares with it
if (nowPhone) nowView = 'simple';
function nowModeButtons() {
  document.querySelectorAll('.nowmodes button[data-mode]').forEach(function (b) { b.classList.toggle('active', b.dataset.mode === nowView); });
  var d = document.getElementById('nowview_default');
  if (d) nowDefaultMark();
  if (typeof naApply === 'function') naApply();
}
// how many detections the list shows (owner 2026-10-20): 10 / 25 / 50 / 100, default 50, remembered per browser
var NOW_COUNT_DEFAULT = <?php echo json_encode(in_array(intval($config['LATEST_COUNT'] ?? 25), array(10, 25, 50, 100), true) ? intval($config['LATEST_COUNT'] ?? 25) : 25); ?>;
var nowCount = (function () { var v = parseInt(nowStore('now_count') || String(NOW_COUNT_DEFAULT), 10); return [10, 25, 50, 100].indexOf(v) >= 0 ? v : NOW_COUNT_DEFAULT; })();
document.addEventListener('DOMContentLoaded', function () { var c = document.getElementById('nowcount'); if (c) c.value = String(nowCount); });
function nowSetCount(v) {
  nowCount = parseInt(v, 10) || NOW_COUNT_DEFAULT;
  nowStore('now_count', String(nowCount));
  nowDefaultMark();
  nowOffset = 0;
  // a refresh at once: the cards on top and the list with the new size
  document.getElementById('detections_table').style.opacity = '.5';
  loadFiveMostRecentDetections();
}
var nowViewChosen = false; // the view picked by a button, not the phone's own Simple at opening
function nowMode(mode) {
  if (mode === 'spectrogram' && nowView !== 'spectrogram') analyzingOn = true; // on each time the view is entered
  nowViewChosen = true;
  nowView = mode; nowModeButtons(); applyAnalyzing(); loadFiveMostRecentDetections();
}
// the station defaults the button compares with (owner 2026-10-23: view, count, period and the filter values together)
var nowSaved = {view: nowDefault, count: String(NOW_COUNT_DEFAULT), period: <?php echo json_encode(in_array($config['LATEST_PERIOD'] ?? 'today', array('today', '7d', 'all'), true) ? $config['LATEST_PERIOD'] : 'today'); ?>,
                lowprob: nowDefaultValue('lowprob'), lowconf: nowDefaultValue('lowconf'), uncommon: nowDefaultValue('uncommon'), rare: nowDefaultValue('rare')};
function nowCurrent() {
  var cur = {view: nowView, count: String(nowCount), period: nowPeriod};
  ['lowprob', 'lowconf', 'uncommon', 'rare'].forEach(function (k) {
    cur[k] = String(nowFilters[k] !== undefined ? nowFilters[k] : (nowStore('now_below_' + k) || nowSaved[k]));
  });
  return cur;
}
function nowDefaultMark() {
  var d = document.getElementById('nowview_default');
  if (!d) return;
  var cur = nowCurrent();
  // a phone opens in Simple by itself: that alone is no reason to offer the button, but a view picked there
  // with the buttons is (2026-11-02: the phone never offered it after a view change)
  var differs = Object.keys(nowSaved).some(function (k) { return k !== 'view' && cur[k] !== nowSaved[k]; })
    || (cur.view !== nowSaved.view && (!nowPhone || nowViewChosen));
  d.style.display = differs ? '' : 'none'; // hidden takes no room, so the bar fits a phone line
}
function nowSetDefault() {
  var cur = nowCurrent();
  var x = new XMLHttpRequest();
  x.onload = function () {
    if (this.status === 200 && this.responseText.trim() === 'OK') { nowSaved = cur; nowDefault = cur.view; nowDefaultMark(); }
    else bsNotice(t('str_not_saved_reason', {reason: this.status === 401 ? t('str_log_in_first_lc') : this.responseText}));
  };
  x.open('POST', 'overview.php?set_now_view=' + cur.view + '&count=' + cur.count + '&period=' + cur.period
    + '&lowprob=' + cur.lowprob + '&lowconf=' + cur.lowconf + '&uncommon=' + cur.uncommon + '&rare=' + cur.rare, true);
  x.send();
}
document.addEventListener('DOMContentLoaded', nowModeButtons);
function nowPage(offset) {
  nowOffset = offset;
  loadFiveMostRecentDetections();
  var h = document.querySelector('h3.now-cards');
  if (h) h.scrollIntoView({behavior: 'smooth'});
}
// only the answer to the latest request is shown (owner 2026-10-10): a filtered request is slower, and switching a filter
// off quickly let its late answer overwrite the unfiltered list, so the filter looked still on
// (2026-10-20) only an answer to ANOTHER query (filter, search, page, view) is dropped: with a detection every few seconds
// each one asks again, and dropping every older answer to the same query left the table on the old list while busy
var nowListQuery = '';
function loadFiveMostRecentDetections() {
  const xhttp = new XMLHttpRequest();
  const mine = nowView + '|' + nowCount + '|' + nowOffset + '|' + nowTerm + '|' + nowFilterQuery() + '|' + (!bsPhone());
  nowListQuery = mine;
  var box = document.getElementById("detections_table");
  xhttp.timeout = 30000;
  // only a 200 answer replaces the table (2026-11-04 hardening: an error page, 401 or 500, was put inside it); a failure
  // or a timeout leaves the list as it was, no longer dimmed
  xhttp.onloadend = function () { if (mine === nowListQuery) box.style.opacity = ''; };
  xhttp.onload = function() {
    if (mine !== nowListQuery || this.status !== 200) return;
    if(this.responseText.length > 0 && !this.responseText.includes("Database is busy")) {
      box.innerHTML = this.responseText;
      if (window.stdTables) stdTables(box);
      if (window.applyNameMode) applyNameMode();
    }
  }
  xhttp.open("GET", "todays_detections.php?ajax_detections=true&display_limit=undefined&hard_limit=" + nowCount + "&gallery=1" + (bsPhone() ? "&mobile=true" : "") + "&mode=" + nowView + "&offset=" + nowOffset + (nowTerm ? "&searchterm=" + encodeURIComponent(nowTerm) : "") + nowFilterQuery(), true);
  xhttp.send();
}
var i_fn1 = null, i_fn2 = null;
function startAutoRefresh() {
    clearInterval(i_fn1); clearInterval(i_fn2);
    i_fn1 = window.setInterval(function(){
                    // a hidden spectrogram is not downloaded again
                    if (document.hidden || document.getElementById("spectrogramimage").style.display === "none") return;
                    document.getElementById("spectrogramimage").src = "spectrogram.png?nocache="+Date.now();
                    }, <?php echo $refresh; ?>*1000);
    i_fn2 = window.setInterval(refreshDetection, <?php echo intval($dividedrefresh); ?>*1000);
}
window.addEventListener("load", function(){
  loadDetectionIfNewExists();
});
document.addEventListener("visibilitychange", function() {
  if (document.hidden) {
    clearInterval(i_fn1);
    clearInterval(i_fn2);
    i_fn1 = i_fn2 = null;
  } else {
    loadDetectionIfNewExists();
    refreshAnalysisStatus();
    startAutoRefresh();
  }
});
startAutoRefresh();
</script>

<style>
</style>
<script src="static/custom-audio-player.js?v=<?php echo @filemtime(__DIR__ . "/../homepage/static/custom-audio-player.js"); ?>"></script>
<script src="static/spectro-dialog.js?v=<?php echo @filemtime(__DIR__ . "/../homepage/static/spectro-dialog.js"); ?>"></script>
<script src="static/review-player.js?v=<?php echo @filemtime(__DIR__ . "/../homepage/static/review-player.js"); ?>"></script>
<script src="static/std-table.js?v=<?php echo @filemtime(__DIR__ . "/../homepage/static/std-table.js"); ?>"></script>
<script src="static/name-mode.js?v=<?php echo @filemtime(__DIR__ . "/../homepage/static/name-mode.js"); ?>"></script>
<script src="static/detection-actions.js?v=<?php echo @filemtime(__DIR__ . "/../homepage/static/detection-actions.js"); ?>"></script>
