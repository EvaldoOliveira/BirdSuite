<?php
################################################################################
# scripts/defaults.php - SYSTEM › DEFAULTS
#
# PURPOSE:
#   System › Defaults (US-121, owner 2026-10-20): every setting of factory_defaults.conf (the defaults that
#   ship with the release) beside the value this station uses (BirdSuite.conf), editable; the changed ones
#   marked, Default per key and for all of them; and, like the Lists, the whole set saved under a name, loaded
#   and deleted (settings_profiles/). Opened as views.php?view=Defaults; POST scripts/defaults.php ?reset=KEY
#   | ?resetall=1 | ?save=1 (JSON key => value) | ?profile=save|load|delete&name=NAME.
#
# DEPENDENCIES:
#   - scripts/common.php
#
# USAGE:
#   views.php?view=All%20settings
#   Referenced by homepage/views.php
################################################################################

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
// text keys translated by the catalogues (the comments of factory_defaults.conf are for the people who read the file)
function dfl_notes() {
	return array(
		'NOW_BACKGROUND' => 'str_now_page_the_picture_that_fills_help',
		'NOW_BACKGROUND_TEST' => 'str_now_page_the_background_of_test_help',
		'NOW_PERIOD' => 'str_now_period_help',
		'NOW_CONF' => 'str_now_conf_help',
		'MODEL' => 'str_the_birdnet_model_that_analyses_help',
		'CONFIDENCE' => 'str_min_conf_the_lowest_score_a_help',
		'SENSITIVITY' => 'str_sigmoid_sensitivity_bends_the_help',
		'SF_THRESH' => 'str_min_prob_species_the_location_help',
		'OVERLAP' => 'str_seconds_the_3_s_analysis_help',
		'AVOID_DUPLICITY' => 'str_1_only_the_top_species_of_each_help',
		'PRIVACY_THRESHOLD' => 'str_privacy_a_recording_where_a_help',
		'RECORDING_LENGTH' => 'str_seconds_of_each_recorded_and_help',
		'EXTRACTION_LENGTH' => 'str_seconds_of_each_saved_detection_help',
		'CHANNELS' => 'str_channels_recorded_from_the_help',
		'AUDIOFMT' => 'str_audio_format_of_the_saved_clips_help',
		'SHADOW_ENABLED' => 'str_1_a_second_model_also_analyses_help',
		'SHADOW_MODEL_NAME' => 'str_the_second_model_its_detections_help',
		'SHADOW_MIN_CONF' => 'str_min_conf_of_the_second_model_0_help',
		'SHADOW_SENS' => 'str_sensitivity_of_the_second_model_help',
		'SHADOW_GEO_THRESH' => 'str_min_prob_of_the_second_models_help',
		'MAX_FILES_SPECIES_DAY' => 'str_each_day_a_species_keeps_its_n_help',
		'MAX_FILES_SPECIES' => 'str_outside_the_recent_days_each_help',
		'NOCTURNAL_DAWN_MARGIN' => 'str_the_species_from_this_help',
		'NOCTURNAL_DUSK_MARGIN' => 'str_the_species_until_this_help',
		'PURGE_RECENT_DAYS' => 'str_the_clips_of_the_last_n_days_help',
		'IGNORED_PURGE_DAYS' => 'str_the_audio_of_ignored_detections_help',
		'PURGE_PROTECT_TOP_N' => 'str_the_n_best_clips_of_each_help',
		'FULL_DISK' => 'str_when_the_disk_reaches_purge_help',
		'FULL_DISK_PATH' => 'str_the_external_folder_of_full_help',
		'FULL_DISK_FALLBACK' => 'str_when_the_external_folder_cannot_help',
		'PURGE_THRESHOLD' => 'str_disk_use_pct_that_starts_the_help',
		'APPRISE_NOTIFY_EACH_DETECTION' => 'str_1_a_notification_for_every_help',
		'APPRISE_NOTIFY_NEW_SPECIES' => 'str_1_a_notification_when_a_species_help',
		'APPRISE_NOTIFY_NEW_SPECIES_EACH_DAY' => 'str_1_a_notification_for_the_first_help',
		'APPRISE_NOTIFY_REGION_RARE' => 'str_1_species_the_location_model_help',
		'NOTIFICATION_DEFAULT_TIER' => 'str_tier_of_a_species_without_its_help',
		'APPRISE_MIN_SEC_PER_SPECIES' => 'str_minimum_seconds_between_two_help',
		'APPRISE_NOTIFICATION_TITLE' => 'str_title_of_a_normal_notification_help',
		'APPRISE_NOTIFICATION_TITLE_RARE' => 'str_title_of_a_rare_channel_help',
		'APPRISE_QUIET_START_NORMAL' => 'str_start_of_normal_tier_help',
		'APPRISE_QUIET_END_NORMAL' => 'str_end_of_normal_tier_hh_help',
		'APPRISE_QUIET_START_RARE' => 'str_start_of_rare_tier_hh_help',
		'APPRISE_QUIET_END_RARE' => 'str_end_of_rare_tier_hh_mm_help',
		'BIRDWEATHER_ENABLED' => 'str_1_detections_are_uploaded_to_help',
		'MULTI_LOCATION' => 'str_enable_support_locations_help',
		'MULTI_STATION' => 'str_enable_support_stations_help',
		'HTTPS_ALWAYS' => 'str_1_the_pages_only_over_https_the_help',
		'ACTIVATE_BIRDDB' => 'str_activate_birddb_true_the_birddb_help',
		'ACTIVATE_KIOSK' => 'str_activate_the_kiosk_true_system_help',
		'BIRDDB_ENABLED' => 'str_1_this_stations_clips_are_help',
		'SOUND_REPO_UPLOAD_MINUTES' => 'str_minutes_between_uploads_to_the_help',
		'LATEST_VIEW' => 'str_how_the_latest_page_opens_on_a_help',
		'LATEST_COUNT' => 'str_detections_in_the_latest_list_help',
		'LATEST_PERIOD' => 'str_period_searched_by_the_latest_help',
		'REPORTS_FILTERS_PHONE' => 'str_the_filter_phone_open_help',
		'REPORTS_FILTERS_DESKTOP' => 'str_the_filter_wider_screen_help',
		'LATEST_FILTERS_PHONE' => 'str_the_filter_phone_open_2_help',
		'LATEST_FILTERS_DESKTOP' => 'str_the_filter_wider_screen_2_help',
		'LATEST_PARAMS_PHONE' => 'str_the_analysis_phone_open_help',
		'LATEST_PARAMS_DESKTOP' => 'str_the_analysis_wider_screen_help',
		'LATEST_LOWPROB' => 'str_low_prob_filter_location_help',
		'LATEST_LOWCONF' => 'str_low_conf_filter_confidence_help',
		'LATEST_UNCOMMON' => 'str_uncommon_filter_species_help',
		'RARE_SPECIES_THRESHOLD' => 'str_a_species_not_detected_for_this_help',
		'EBIRD_REPORT_ENABLED' => 'str_weekly_ebird_e_mail_1_the_help',
		'EBIRD_REPORT_FREQ' => 'str_ebird_e_mail_daily_every_day_help',
		'EBIRD_REPORT_DAY' => 'str_day_of_the_weekly_ebird_e_mail_help',
		'EBIRD_REPORT_TIME' => 'str_time_of_the_weekly_ebird_e_mail_help',
		'EBIRD_REPORT_SPECIES' => 'str_filter_of_the_weekly_ebird_e_help',
		'EBIRD_REPORT_MIN_CONF' => 'str_with_minconf_the_lowest_help',
		'EBIRD_PAGE_MODE' => 'str_filter_the_ebird_page_opens_help',
		'EBIRD_PAGE_MIN_CONF' => 'str_minimum_confidence_the_ebird_help',
		'EBIRD_REPORT_NAME' => 'str_subject_of_the_weekly_ebird_e_help',
		'EBIRD_LOCATION_NAME' => 'str_location_name_of_the_ebird_help',
		'EBIRD_LOCATION_NAMES' => 'str_location_names_used_before_help',
		'SPECTROGRAM_PALETTE' => 'str_colour_palette_of_the_help',
		'SPECTROGRAM_HEIGHT' => 'str_height_of_the_live_spectrogram_help',
		'SPECTROGRAM_FLOOR_DB' => 'str_floor_of_the_spectrogram_colour_help',
		'SPECTROGRAM_RANGE_DB' => 'str_range_of_the_colour_scale_above_help',
		'SPECTROGRAM_CONTRAST' => 'str_contrast_gamma_of_the_help',
		'SPECTROGRAM_SILENT' => 'str_1_the_live_spectrogram_starts_help',
		'SPECTROGRAM_COMPRESSION' => 'str_1_the_live_audio_goes_through_a_help',
		'RAW_SPECTROGRAM' => 'str_1_clip_spectrograms_without_help',
		'RTSP_STREAM_TO_LIVESTREAM' => 'str_which_rtsp_stream_the_live_help',
		'FREQSHIFT_HI' => 'str_ffmpeg_frequency_in_hz_moved_help',
		'FREQSHIFT_LO' => 'str_ffmpeg_frequency_in_hz_that_help',
		'FREQSHIFT_BASE' => 'str_freqshift_base_help',
		'RAW_REC_ENABLED' => 'str_1_the_raw_recording_schedule_is_help',
		'RAW_REC_DAYS' => 'str_days_of_the_week_comma_help',
		'RAW_REC_RECURRENT' => 'str_1_every_chosen_day_0_only_the_help',
		'RAW_REC_START' => 'str_start_time_hh_mm_ss',
		'RAW_REC_END' => 'str_end_time_hh_mm_ss_earlier_than_help',
		'RAW_REC_SEGMENT_MIN' => 'str_maximum_minutes_per_wav_file',
		'RAW_REC_RATE' => 'str_raw_rate_help',
		'RAW_REC_DIR' => 'str_raw_dir_help',
		'FIELD_WIFI_MODE' => 'str_the_station_wifi_when_the_help',
		'FIELD_BOOT_WAIT_SEC' => 'str_seconds_after_a_start_up_before_help',
		'FIELD_LOST_WAIT_SEC' => 'str_seconds_without_any_network_help',
		'FIELD_AP_WINDOW_MIN' => 'str_cycle_and_off_modes_minutes_the_help',
		'FIELD_CYCLE_MIN' => 'str_cycle_mode_the_station_wifi_help',
		'FIELD_AP_IDLE_MIN' => 'str_minutes_after_the_last_phone_help',
		'LOCATION_CHECK' => 'str_1_at_boot_the_network_position_help',
		'GEOLOCATION_API_KEY' => 'str_google_geolocation_api_key_help',
		'LOCATION_IP_ERROR_KM' => 'str_how_far_off_the_network_ip_help',
		'APP_THEME' => 'str_colours_of_the_app_ocean_dark_help',
		'UI_LANG' => 'str_interface_language_empty_follow_help',
		'AUTOMATIC_UPDATE' => 'str_1_the_station_updates_itself_on_help',
		'AUTOMATIC_UPDATE_DAYS' => 'str_days_of_the_automatic_update_help',
		'AUTOMATIC_UPDATE_TIME' => 'str_time_of_the_automatic_update_hh_help',
		'SILENCE_UPDATE_INDICATOR' => 'str_1_no_update_badge_in_the_menu_help',
		'LOG_RETENTION_DAYS' => 'str_the_system_journal_is_kept_this_help',
	);
}
// the defaults with the comment lines (## ...) written right above each key; the page shows dfl_notes() instead (text
// keys the catalogues translate; the file stays English for the people who read it)
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

function profiles_dir() {
	$d = BS_CONFIG . '/settings_profiles';
	if (!is_dir($d)) { @mkdir($d, 0775, true); @chmod($d, 0775); }
	return $d;
}
function profile_read($file) {
	$out = array();
	foreach (file($file, FILE_IGNORE_NEW_LINES) ?: array() as $line) if (preg_match('/^([A-Z][A-Z0-9_]*)=(.*)$/', $line, $m)) $out[$m[1]] = trim($m[2], '"');
	return $out;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
	ensure_authenticated(t('str_you_must_change_the_settings_help'));
	$defs = defaults_with_notes();
	// HTTPS_ALWAYS changed by any of the actions below: the web server configuration is written again (US-126)
	$https_before = (string)(station_values()['HTTPS_ALWAYS'] ?? '0');
	register_shutdown_function(function () use ($https_before) {
		clearstatcache();
		if ((string)(station_values()['HTTPS_ALWAYS'] ?? '0') !== $https_before) exec('sudo -n ' . BS_ROOT_HELPER . ' caddyfile > /dev/null 2>&1 &');
	});
	if (isset($_GET['reset'])) {
		$key = (string)$_GET['reset'];
		if (!isset($defs[$key])) { echo t('str_unknown_setting'); die(); }
		echo conf_set($key, $defs[$key]['value'], $err) ? 'OK' : $err;
	} elseif (isset($_GET['resetall'])) {
		echo conf_set_many(array_map(function ($d) { return $d['value']; }, $defs), $err) ? 'OK' : $err;
	} elseif (isset($_GET['save'])) {
		$in = json_decode((string)file_get_contents('php://input'), true) ?: array();
		$pairs = array_intersect_key($in, $defs);
		if (!$pairs) { echo t('str_nothing_to_save'); die(); }
		// each value checked against its key's rule (common.php conf_rules): a wrong one is refused, nothing is written
		echo conf_set_many($pairs, $err) ? 'OK' : $err;
	} elseif (isset($_GET['profile'])) {
		$name = (string)($_GET['name'] ?? '');
		if (!preg_match('/^[A-Za-z0-9_-]{1,40}$/', $name)) { echo t('str_name_letters_digits_and_only'); die(); }
		$file = profiles_dir() . "/$name.conf";
		$act = $_GET['profile'];
		if ($act === 'save') {
			$mine = array_merge(array_map(function ($d) { return $d['value']; }, $defs), array_intersect_key(station_values(), $defs));
			$txt = "## BirdSuite settings saved " . date('Y-m-d H:i') . " (" . menu_path('All settings') . ")\n";
			foreach ($mine as $k => $v) $txt .= $k . '=' . (preg_match('/^[A-Za-z0-9_.\-]*$/', (string)$v) ? $v : '"' . str_replace('"', '', (string)$v) . '"') . "\n";
			if (file_put_contents($file, $txt) === false) { echo t('str_cannot_write_the_saved_settings'); die(); }
			@chmod($file, 0664);
			@file_put_contents(profiles_dir() . '/.loaded', $name);
			echo t('str_saved_as_settings', array('name' => $name, 'n' => count($mine)));
		} elseif ($act === 'load') {
			if (!is_file($file)) { echo t('str_no_such_saved_settings'); die(); }
			$pairs = array_intersect_key(profile_read($file), $defs);
			if (!conf_set_many($pairs, $err)) { echo $err; die(); }
			@file_put_contents(profiles_dir() . '/.loaded', $name);
			echo t('str_loaded_settings_analysis_help', array('name' => $name, 'n' => count($pairs)));
		} elseif ($act === 'delete') {
			if (!is_file($file) || preg_match('/^_*factory/i', $name)) { echo t('str_no_such_saved_settings'); die(); }
			unlink($file);
			echo t('str_deleted_the_stations_settings_help', array('name' => $name));
		} else echo t('str_unknown_action');
	} else echo t('str_unknown_action');
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
.dfl a.dflfirstrun { display: inline-block; padding: 4px 14px; border-radius: 12px; background: var(--accent, #2b5e22); color: #fff; text-decoration: none; font-weight: 600; margin-right: 10px; }
.dfl tr.dflsec td a.dflown { font-weight: normal; font-size: 12px; margin-left: 10px; color: inherit; }
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
	<p class="note"><?php echo t('str_every_setting_its_factory_help'); ?>
		<?php echo t('str_not_listed_what_the_help'); ?></p>
	<?php // the set-up questions of a new station, for an owner who did not answer them at the first visit (owner 2026-11-04) ?>
	<div class="bar"><a class="dflfirstrun" href="views.php?view=First%20Run" title="<?php echo $h(t('str_run_the_first_run_help')); ?>">&#9654; <?php echo t('str_run_the_first_run'); ?></a>
		<small><?php echo t('str_run_the_first_run_help'); ?></small></div>
	<div class="bar">
		<?php // one control (owner 2026-10-25): "Select set: [the set in use ▾] Load Delete" ?>
		<span><?php echo t('str_select_set'); ?>
		<?php // choosing does nothing by itself (owner 2026-10-25: a saved set could not be deleted, choosing it loaded it) ?>
		<select id="dflprofiles" aria-label="<?php echo htmlspecialchars(t('str_defaults'), ENT_QUOTES); ?>">
			<option value=""><?php echo $changed === 0 ? t('str_factory_defaults') : ($matching ? $h($matching[0]) : t('str_not_saved_lc')); ?></option>
			<option value="__factory__"><?php echo t('str_factory_defaults'); ?></option>
			<?php foreach ($profiles as $n => $is) echo '<option value="' . $h($n) . '">' . $h($n) . ($matching && $matching[0] === $n ? ' ' . t('str_current_lc_paren') : '') . '</option>'; ?>
		</select>
		<button type="button" id="dflload" onclick="dflProfile('load')" disabled title="<?php echo t('str_writes_the_chosen_settings_over_help'); ?>"><?php echo t('str_load_set'); ?></button>
		<button type="button" id="dfldel" onclick="dflProfile('delete')" disabled title="<?php echo t('str_deletes_the_stations_help'); ?>"><?php echo t('str_delete'); ?></button></span>
		<span style="margin-left:auto"><?php // on the right, as Save edits below (owner 2026-10-25) ?><?php echo t('str_save_the_settings_in_use_as'); ?> <input type="text" id="dflname" placeholder="<?php echo t('str_name_lc'); ?>" style="width:10em">
		<button type="button" onclick="dflProfile('save')"><?php echo t('str_save'); ?></button></span>
	</div>
	<?php // short (owner 2026-10-25): "4/79 changed - Only changed: [ ]", Save edits on the right, no filter box ?>
	<div class="bar"><span title="<?php echo t('str_settings_that_differ_from_the_help'); ?>"><?php echo t('str_n_total_changed', array('n' => '<span id="dflcount">' . $changed . '</span>', 'total' => count($defs))); ?></span> -
		<label><?php echo t('str_show_only_changes'); ?> <input type="checkbox" id="dflonly" checked onchange="dflFilter()"></label>
		<?php // no Save button (owner 2026-10-25): a field is saved when it is left (or the page is) ?>
		<span id="dflmsg" style="margin-left:auto"></span></div>
	<table class="stdtable" id="dfltable" data-nosort><?php // every line always, no pages (owner 2026-10-24) ?>
		<tr><th><?php echo t('str_setting'); ?></th><th><?php echo t('str_default_title'); ?></th><th><?php echo t('str_this_station'); ?></th><th><?php echo t('str_what_it_is'); ?></th><th data-nosort></th></tr>
		<?php
		// the settings with a known set of values get a list instead of a text box (owner 2026-10-25: no guessing); the
		// value in use stays in the list even when it is not one of these
		$onoff = array('0' => t('str_0_off'), '1' => t('str_1_on'));
		$choices = array_fill_keys(array('APPRISE_NOTIFY_REGION_RARE', 'AVOID_DUPLICITY', 'SHADOW_ENABLED', 'BIRDWEATHER_ENABLED', 'RTSP_STREAM_TO_LIVESTREAM',
			'APPRISE_NOTIFY_EACH_DETECTION', 'APPRISE_NOTIFY_NEW_SPECIES', 'APPRISE_NOTIFY_NEW_SPECIES_EACH_DAY', 'BIRDDB_ENABLED', 'SPECTROGRAM_SILENT',
			'SPECTROGRAM_COMPRESSION', 'LOCATION_CHECK', 'SILENCE_UPDATE_INDICATOR', 'AUTOMATIC_UPDATE', 'RAW_SPECTROGRAM', 'RAW_REC_ENABLED',
			'RAW_REC_RECURRENT', 'EBIRD_REPORT_ENABLED', 'HTTPS_ALWAYS', 'MULTI_LOCATION', 'MULTI_STATION'), $onoff);
		$plain = function ($list) { return array_combine($list, $list); };
		$choices += array(
			'ACTIVATE_BIRDDB' => $plain(array('false', 'true')),
			'ACTIVATE_KIOSK' => $plain(array('false', 'true')),
			'MODEL' => $plain(array('BirdNET-Plus_V3.0-preview3.1_Global_10K', 'BirdNET_GLOBAL_6K_V2.4_Model_FP16', 'BirdNET_6K_GLOBAL_MODEL')),
			'SHADOW_MODEL_NAME' => array('' => t('str_none_lc_paren')) + $plain(array('BirdNET_GLOBAL_6K_V2.4_Model_FP16', 'BirdNET-Plus_V3.0-preview3.1_Global_10K', 'BirdNET_6K_GLOBAL_MODEL')),
			'NOTIFICATION_DEFAULT_TIER' => $plain(array('muted', 'normal', 'rare')),
			'FIELD_WIFI_MODE' => $plain(array('always', 'cycle', 'off')),
			'FULL_DISK' => $plain(array('purge', 'keep', 'external')),
			'FULL_DISK_FALLBACK' => $plain(array('keep', 'purge')),
			'SPECTROGRAM_PALETTE' => $plain(array('turbo', 'inferno', 'roseus', 'soxheat', 'birdnet', 'viridis', 'ocean', 'grayscale')),
			'CHANNELS' => $plain(array('1', '2')),
			'APP_THEME' => $plain(array_keys(theme_presets())),
			'AUDIOFMT' => $plain(array('flac', 'mp3', 'wav', 'ogg')),
			'LATEST_VIEW' => $plain(array('simple', 'list', 'spectrogram')),
			'LATEST_COUNT' => $plain(array('10', '25', '50', '100')),
			'NOW_PERIOD' => $plain(array('1', '5', '15', '60', '240', '480', '720')),
			'NOW_CONF' => $plain(array('10', '20', '30', '40', '50', '60', '70', '80', '90')),
			'LATEST_PERIOD' => $plain(array('today', '7d', 'all')),
			'EBIRD_REPORT_DAY' => $plain(array('1', '2', '3', '4', '5', '6', '7')),
			'EBIRD_REPORT_SPECIES' => $plain(array('confirmed', 'minconf', 'all')), 'EBIRD_PAGE_MODE' => $plain(array('confirmed', 'minconf', 'all')),
			'EBIRD_REPORT_FREQ' => $plain(array('daily', 'weekly')),
			'LATEST_FILTERS_PHONE' => $plain(array('closed', 'open')), 'LATEST_FILTERS_DESKTOP' => $plain(array('closed', 'open')),
			'LATEST_PARAMS_PHONE' => $plain(array('closed', 'open')), 'LATEST_PARAMS_DESKTOP' => $plain(array('closed', 'open')),
			'REPORTS_FILTERS_PHONE' => $plain(array('closed', 'open')), 'REPORTS_FILTERS_DESKTOP' => $plain(array('closed', 'open')),
			'UI_LANG' => array('' => t('str_follow_database_lang')) + $plain(array('en', 'pt_BR', 'es', 'zh_CN', 'ja', 'de', 'fr', 'it', 'nl', 'pl', 'ru')),
			// US-52 / US-53
			'UI_LEVEL' => $plain(array('', 'essential', 'birder', 'expert')), 'UI_PRESET' => $plain(array('', 'home', 'birder', 'research')),
			'WEB_LOGIN' => $plain(array('on', 'off')), 'LAN_TRUST' => $plain(array('everyone', 'household')),
			'PWD_RECOVERY' => $plain(array('', 'email', 'telegram', 'sdcard')),
		);
		// grouped by subject, in the owner's order (2026-10-25); a setting not listed goes to Other
		$sections = array(
			// first (owner 2026-10-29, US-125): several locations, then several stations
			'str_locations_and_stations' => array('MULTI_LOCATION', 'MULTI_STATION', 'UI_PRESET', 'UI_CONTEXT'),
			'str_appearance_and_language' => array('APP_THEME', 'UI_LANG', 'UI_LEVEL'),
			'str_now_page' => array('NOW_PERIOD', 'NOW_CONF', 'NOW_BACKGROUND', 'NOW_BACKGROUND_TEST'),  // view=Now (owner 2026-11-02)
			'str_latest_page' => array('LATEST_VIEW', 'LATEST_FILTERS_PHONE', 'LATEST_FILTERS_DESKTOP', 'LATEST_PARAMS_PHONE', 'LATEST_PARAMS_DESKTOP', 'LATEST_COUNT', 'LATEST_PERIOD', 'LATEST_LOWPROB', 'LATEST_LOWCONF', 'LATEST_UNCOMMON', 'RARE_SPECIES_THRESHOLD'),
			'str_reports' => array('REPORTS_FILTERS_PHONE', 'REPORTS_FILTERS_DESKTOP'),
			'str_ebird' => array('EBIRD_PAGE_MODE', 'EBIRD_PAGE_MIN_CONF', 'EBIRD_REPORT_ENABLED', 'EBIRD_REPORT_FREQ', 'EBIRD_REPORT_DAY', 'EBIRD_REPORT_TIME', 'EBIRD_REPORT_SPECIES', 'EBIRD_REPORT_MIN_CONF', 'EBIRD_REPORT_NAME', 'EBIRD_LOCATION_NAME', 'EBIRD_LOCATION_NAMES'),
			'str_spectrogram_and_live_audio' => array('SPECTROGRAM_PALETTE', 'SPECTROGRAM_HEIGHT', 'SPECTROGRAM_FLOOR_DB', 'SPECTROGRAM_RANGE_DB', 'SPECTROGRAM_CONTRAST', 'SPECTROGRAM_SILENT',
				'SPECTROGRAM_COMPRESSION', 'RAW_SPECTROGRAM', 'RTSP_STREAM_TO_LIVESTREAM', 'FREQSHIFT_HI', 'FREQSHIFT_LO', 'FREQSHIFT_BASE'),
			// the order of Tools › Data (owner 2026-10-25)
			'str_disk_and_life_cycle_of_the_help' => array('MAX_FILES_SPECIES_DAY', 'MAX_FILES_SPECIES', 'PURGE_RECENT_DAYS', 'IGNORED_PURGE_DAYS', 'PURGE_PROTECT_TOP_N', 'FULL_DISK', 'FULL_DISK_PATH', 'FULL_DISK_FALLBACK', 'PURGE_THRESHOLD'),
			'str_model_and_analysis' => array('MODEL', 'CONFIDENCE', 'SENSITIVITY', 'SF_THRESH', 'OVERLAP', 'AVOID_DUPLICITY', 'PRIVACY_THRESHOLD', 'RECORDING_LENGTH', 'EXTRACTION_LENGTH', 'CHANNELS', 'AUDIOFMT'),
			'str_shadow_model' => array('SHADOW_ENABLED', 'SHADOW_MODEL_NAME', 'SHADOW_MIN_CONF', 'SHADOW_SENS', 'SHADOW_GEO_THRESH'),
			'str_notifications' => array('APPRISE_NOTIFY_EACH_DETECTION', 'APPRISE_NOTIFY_NEW_SPECIES', 'APPRISE_NOTIFY_NEW_SPECIES_EACH_DAY', 'APPRISE_NOTIFY_REGION_RARE', 'NOTIFICATION_DEFAULT_TIER',
				'APPRISE_MIN_SEC_PER_SPECIES', 'APPRISE_NOTIFICATION_TITLE', 'APPRISE_NOTIFICATION_TITLE_RARE',
				'APPRISE_QUIET_START_NORMAL', 'APPRISE_QUIET_END_NORMAL', 'APPRISE_QUIET_START_RARE', 'APPRISE_QUIET_END_RARE'),
			'str_sharing' => array('BIRDWEATHER_ENABLED', 'ACTIVATE_BIRDDB', 'BIRDDB_ENABLED', 'SOUND_REPO_UPLOAD_MINUTES'),
			'str_updates' => array('AUTOMATIC_UPDATE', 'AUTOMATIC_UPDATE_DAYS', 'AUTOMATIC_UPDATE_TIME', 'SILENCE_UPDATE_INDICATOR'),
			'str_audio_loss' => array('AUDIO_LOSS_DISCARD', 'AUDIO_LOSS_MAX_MS'),
			'str_raw_recording_lc' => array('RAW_REC_ENABLED', 'RAW_REC_DAYS', 'RAW_REC_RECURRENT', 'RAW_REC_START', 'RAW_REC_END', 'RAW_REC_SEGMENT_MIN', 'RAW_REC_RATE', 'RAW_REC_DIR'),
			'str_location' => array('LOCATION_CHECK', 'GEOLOCATION_API_KEY', 'LOCATION_IP_ERROR_KM'),
			'str_web_access' => array('HTTPS_ALWAYS', 'WEB_LOGIN', 'LAN_TRUST', 'PWD_RECOVERY'),
			'str_field_access_plan_a' => array('FIELD_WIFI_MODE', 'FIELD_BOOT_WAIT_SEC', 'FIELD_LOST_WAIT_SEC', 'FIELD_AP_WINDOW_MIN', 'FIELD_CYCLE_MIN', 'FIELD_AP_IDLE_MIN'),
		);
		// the page that owns each group of settings (US-54, owner 2026-11-04): a setting is set there, this table only shows
		// and resets it; the link opens that page
		$owner_page = array('str_locations_and_stations' => 'First Run', 'str_appearance_and_language' => 'Themes', 'str_now_page' => 'Now',
			'str_latest_page' => 'Latest', 'str_reports' => 'By Hour', 'str_ebird' => 'eBird', 'str_spectrogram_and_live_audio' => 'Sonogram',
			'str_disk_and_life_cycle_of_the_help' => 'Data', 'str_model_and_analysis' => 'Model', 'str_shadow_model' => 'Model',
			'str_notifications' => 'What and when', 'str_sharing' => 'Sharing', 'str_updates' => 'Status', 'str_raw_recording_lc' => 'Raw Recording', 'str_audio_loss' => 'Microphone',
			'str_location' => 'Locations', 'str_web_access' => 'Access', 'str_field_access_plan_a' => 'Network');
		$notes = dfl_notes();
		$listed = array();
		foreach ($sections as $keys) $listed = array_merge($listed, $keys);
		$sections['str_other'] = array_values(array_diff(array_keys($defs), $listed));
		foreach ($sections as $title => $keys) {
			$keys = array_values(array_filter($keys, function ($k) use ($defs) { return isset($defs[$k]); }));
			if (!$keys) continue;
			$op = $owner_page[$title] ?? '';
			echo '<tr class="dflsec nosortrow"><td colspan="5">' . $h(t($title))
				. ($op !== '' && view_available($op) ? ' <a class="dflown" href="views.php?view=' . rawurlencode($op) . '" title="' . $h(t('str_set_on_its_page')) . '">' . $h(menu_path($op)) . ' &#8599;</a>' : '')
				. '</td></tr>';
		foreach ($keys as $k) {
			$d = $defs[$k];
			$has = isset($mine[$k]);
			$chg = $has && !same_value($mine[$k], $d['value']);
			// id dfl-KEY: other pages link straight to a setting (views.php?view=Defaults#dfl-KEY, owner 2026-11-01)
			echo '<tr id="dfl-' . $h($k) . '" data-k="' . $h($k) . '"' . ($chg ? ' class="chg"' : '') . '><td class="k">' . $h($k) . '</td><td class="v">' . $h($d['value'] === '' ? t('str_empty_lc_paren') : $d['value']) . '</td>'
				. '<td class="v">' . dfl_field($k, $has ? $mine[$k] : $d['value'], $choices[$k] ?? null, $has ? '' : t('str_not_set_on_this_station_the_help'), $h) . '</td><td class="n">' . $h(isset($notes[$k]) ? t($notes[$k]) : '') . '</td>'
				. '<td>' . ($chg ? '<button type="button" onclick="dflReset(this)">' . t('str_default_button') . '</button>' : '') . '</td></tr>';
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
	x.onload = function () { done(this.status === 401 ? t('str_log_in_first') : this.responseText.trim()); };
	x.open('POST', url, true);
	if (body !== null) x.setRequestHeader('Content-Type', 'application/json');
	x.send(body);
}
function dflMark(inp) {
	var def = inp.closest('tr').cells[1].textContent;
	inp.classList.toggle('nondefault', !dflSame(inp.value, def === t('str_empty_lc_paren') ? '' : def));
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
		.then(function (r) { return r.status === 401 ? t('str_log_in_first') : r.text(); })
		.then(function (r) {
			if (leaving) return;
			r = String(r).trim();
			if (r !== 'OK') { msg.textContent = r; msg.style.color = '#c62828'; return; }
			inp.dataset.orig = v; inp.classList.remove('edited');
			dflRowState(tr);
			msg.style.color = ''; msg.textContent = t('str_key_saved', {key: k});
		})
		.catch(function () { if (!leaving) { msg.textContent = t('str_not_saved_the_station_did_not_help'); msg.style.color = '#c62828'; } });
}
// the row after a change: marked and with its Default button when it differs from the factory value; the count above
function dflRowState(tr) {
	var inp = tr.querySelector('.dflin'), def = tr.cells[1].textContent, chg = !dflSame(inp.value, def === t('str_empty_lc_paren') ? '' : def);
	tr.classList.toggle('chg', chg);
	var cell = tr.cells[4], b = cell.querySelector('button');
	if (chg && !b) cell.innerHTML = '<button type="button" onclick="dflReset(this)">' + t('str_default_button') + '</button>';
	if (!chg && b) b.remove();
	document.getElementById('dflcount').textContent = document.querySelectorAll('#dfltable tr.chg').length;
}
window.addEventListener('pagehide', function () {
	document.querySelectorAll('#dfltable .dflin.edited').forEach(function (i) { dflSaveOne(i, true); });
});
function dflResetAll() {
	bsAsk(t('str_put_every_setting_back_to_its_help'), true).then(function (ok) {
		if (!ok) { document.getElementById('dflprofiles').value = ''; return; }
		dflPost('scripts/defaults.php?resetall=1', null, function (r) { if (r !== 'OK') bsNotice(r); else location.reload(); });
	});
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
	if (act === 'save' && !/^[A-Za-z0-9_-]+$/.test(n)) { bsNotice(t('str_name_letters_digits_and_only')); return; }
	var post = function () { dflPost('scripts/defaults.php?profile=' + act + '&name=' + encodeURIComponent(n), null, function (r) { bsNotice(r).then(function () { location.reload(); }); }); };
	if (act === 'load') {
		bsAsk(t('str_write_the_saved_settings_over_help', {name: n}), true).then(function (ok) {
			if (!ok) { document.getElementById('dflprofiles').value = ''; return; }
			post();
		});
		return;
	}
	if (act === 'delete') {
		bsAsk(t('str_delete_the_saved_settings_the_help', {name: n}), true).then(function (ok) { if (ok) post(); });
		return;
	}
	post();
}
function dflReset(b) {
	var tr = b.closest('tr'), k = tr.dataset.k;
	bsAsk(t('str_put_back_to_its_default', {key: k, value: tr.cells[1].textContent}), true).then(function (ok) {
		if (!ok) return;
		var x = new XMLHttpRequest();
		x.onload = function () {
			var r = this.status === 401 ? t('str_log_in_first') : this.responseText.trim();
			if (r !== 'OK') { document.getElementById('dflmsg').textContent = r; return; }
			var i = tr.querySelector('.dflin'); i.value = i.dataset.orig = tr.cells[1].textContent.replace(t('str_empty_lc_paren'), ''); i.classList.remove('edited'); dflMark(i); dflRowState(tr);
			document.getElementById('dflmsg').textContent = t('str_is_back_to_its_default', {key: k});
		};
		x.open('POST', 'scripts/defaults.php?reset=' + encodeURIComponent(k), true);
		x.send();
	});
}
</script>
