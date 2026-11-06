<?php
################################################################################
# scripts/overview.php - LATEST: THE DETECTIONS AS THEY ARRIVE, THE ANALYSIS SETTINGS AND THE RECORDING LENGTH
#
# PURPOSE:
#   The page of Latest (views.php?view=Latest): the detections as they arrive with the filters and the views
#   (list, cards, spectrogram), today's totals (todays_detections.php), the analysis settings changed from the
#   page (CONFIDENCE, SENSITIVITY, OVERLAP, the recording length) and the kiosk. Its script is
#   static/overview.js.
#
# DEPENDENCIES:
#   - scripts/common.php
#   - homepage/static/location-switch.js
#   - homepage/static/overview.js
#   - homepage/static/custom-audio-player.js
#   - homepage/static/spectro-dialog.js
#   - homepage/static/review-player.js
#   - homepage/static/std-table.js
#   - homepage/static/name-mode.js
#   - homepage/static/detection-actions.js
#   - scripts/location_check.py
#
# USAGE:
#   views.php?view=Latest
#   Referenced by homepage/static/overview.js, homepage/views.php, scripts/clear_all_data.sh,
#   scripts/install_helpers.sh, scripts/location_moved.php
################################################################################

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
	// the analysis and the recording service asked in one systemctl call (one line each, in this order)
	$states = explode("\n", trim((string)shell_exec('systemctl is-active ' . BS_SVC_ANALYSIS . '.service ' . BS_SVC_RECORDING . '.service 2>/dev/null')));
	$running = trim($states[0] ?? '') === 'active';
	$recording = trim($states[1] ?? '') === 'active';
	// the file being recorded is not waiting, but only while the recorder runs (Gitea #77: a stopped recorder showed one less)
	$waiting = max(0, count(glob($sd . '/*.wav') ?: array()) - ($recording ? 1 : 0));
	$now = trim((string)@file_get_contents($sd . '/analyzing_now.txt'));
	$shown = preg_match('/(\d{4}-\d{2}-\d{2})_(\d{2})h(\d{2})m(\d{2})s/', basename($now), $m) ? "$m[2]:$m[3]:$m[4]" : '';
	// behind = age of the recording being analysed (its name carries when it started); without it, waiting × length;
	// a name strtotime() can not read (e.g. 25h) gives no delay rather than the age of 1970 (Gitea #77)
	$started = $shown !== '' ? strtotime("$m[1] $shown") : false;
	if ($shown !== '') $behind = $started === false ? null : max(0, time() - $started);
	else $behind = $waiting * max(1, intval($config['RECORDING_LENGTH'] ?? 15));
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
// the picture named as its button (Gitea #79, WCAG 1.1.1)
echo "<img id=\"spectrogramimage\" src=\"spectrogram.png?nocache=$time\" alt=\"" . htmlspecialchars(t('str_now_recording'), ENT_QUOTES) . "\">";
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
<script>window.BS_OVERVIEW = {filterDefaults: <?php echo json_encode(array('lowprob' => intval($config['LATEST_LOWPROB'] ?? 40), 'lowconf' => intval($config['LATEST_LOWCONF'] ?? 60), 'uncommon' => intval($config['LATEST_UNCOMMON'] ?? 10), 'rare' => max(1, intval($config['RARE_SPECIES_THRESHOLD'] ?? 30)))); ?>, openDefaults: <?php echo json_encode(array('filters' => array(($config['LATEST_FILTERS_PHONE'] ?? 'closed') === 'open', ($config['LATEST_FILTERS_DESKTOP'] ?? 'open') !== 'closed'),
	'params' => array(($config['LATEST_PARAMS_PHONE'] ?? 'closed') === 'open', ($config['LATEST_PARAMS_DESKTOP'] ?? 'open') !== 'closed'))); ?>, period: <?php echo json_encode(in_array($config['LATEST_PERIOD'] ?? 'today', array('today', '7d', 'all'), true) ? $config['LATEST_PERIOD'] : 'today'); ?>, view: <?php echo json_encode($now_view_cfg); ?>, countDefault: <?php echo json_encode(in_array(intval($config['LATEST_COUNT'] ?? 25), array(10, 25, 50, 100), true) ? intval($config['LATEST_COUNT'] ?? 25) : 25); ?>, refresh: <?php echo $refresh; ?>, dividedRefresh: <?php echo intval($dividedrefresh); ?>};</script>
<script src="static/overview.js?v=<?php echo @filemtime(__DIR__ . "/../homepage/static/overview.js"); ?>"></script>

<style>
/* a phone's Simple table fits 375 px (Gitea #79: it scrolled 19 px sideways): narrower cell padding and Review button
	 below 400 px; the names still never break */
@media (max-width: 400px) {
	#detections_table table.stdtable th, #detections_table table.stdtable td { padding-left: 4px !important; padding-right: 4px !important; }
	#detections_table table.stdtable td .validatebtn { padding-left: 4px !important; padding-right: 4px !important; }
}
</style>
<script src="static/custom-audio-player.js?v=<?php echo @filemtime(__DIR__ . "/../homepage/static/custom-audio-player.js"); ?>"></script>
<script src="static/spectro-dialog.js?v=<?php echo @filemtime(__DIR__ . "/../homepage/static/spectro-dialog.js"); ?>"></script>
<script src="static/review-player.js?v=<?php echo @filemtime(__DIR__ . "/../homepage/static/review-player.js"); ?>"></script>
<script src="static/std-table.js?v=<?php echo @filemtime(__DIR__ . "/../homepage/static/std-table.js"); ?>"></script>
<script src="static/name-mode.js?v=<?php echo @filemtime(__DIR__ . "/../homepage/static/name-mode.js"); ?>"></script>
<script src="static/detection-actions.js?v=<?php echo @filemtime(__DIR__ . "/../homepage/static/detection-actions.js"); ?>"></script>
