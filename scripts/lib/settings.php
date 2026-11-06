<?php
################################################################################
# scripts/lib/settings.php - THE STATION SETTINGS (BirdSuite.conf): DEFAULTS, RULES, READ AND WRITE
#
# PURPOSE:
#   The station settings (BirdSuite.conf): defaults, rules, read and write: the helpers of the BirdSuite pages
#   on this subject, moved out of common.php (one file per subject, owner 2026-11-06).
#
# DEPENDENCIES:
#   - scripts/common.php (loads this file after core.php and view_registry.php; every lib may call the others)
#
# USAGE:
#   Loaded by common.php on every page; never requested on its own (404 without BS_PROG).
################################################################################

if (!defined('BS_PROG')) { http_response_code(404); exit; }

// the station's timezone, read on every request from the /etc/localtime link (2026-10-20): kept in the session it went
// stale for two hours after a location in another timezone was made current, and PHP's "today" then disagreed with
// SQLite's DATE('now', 'localtime')
function set_timezone() {
	$tz = preg_replace('~^.*/zoneinfo/~', '', (string)@readlink('/etc/localtime'));
	if ($tz === '' || !in_array($tz, timezone_identifiers_list(), true)) $tz = trim((string)shell_exec('timedatectl show --value --property=Timezone'));
	if ($tz !== '') date_default_timezone_set($tz);
	$_SESSION['my_timezone'] = $tz;
}

// US-121 (2026-10-20): every default of the program, factory_defaults.conf in the base folder (read-only, in git)
function birdsuite_defaults() {
	static $d = null;
	if ($d === null) {
		$f = BS_DEFAULTS;
		$d = is_file($f) ? (parse_ini_string(preg_replace("~^#+.*$~m", "", file_get_contents($f))) ?: array()) : array();
	}
	return $d;
}

// the station's settings: the defaults, with BirdSuite.conf over them (a key it never set takes its default)
function get_config($force_reload = false) {
	// the content, not the mtime (2026-10-23 run 3: two saves within one second kept the old values in the session)
	$mtime = @md5_file(BS_CONF) . '/' . @filemtime(BS_DEFAULTS);
	if (isset($_SESSION['my_config_version']) && $_SESSION['my_config_version'] !== $mtime) {
		$force_reload = true;
	}
	if (!isset($_SESSION['my_config']) || $force_reload) {
		$source = preg_replace("~^#+.*$~m", "", file_get_contents(BS_CONF));
		$my_config = parse_ini_string($source);
		if ($my_config) {
			// the words off/on/yes/no/true/false/none/null stay words (owner 2026-10-31: FIELD_WIFI_MODE=off read as "", the
			// Network page selected nothing and showed "Always on", so choosing it saved nothing and the station stayed off)
			$raw = parse_ini_string($source, false, INI_SCANNER_RAW);
			foreach ((array)$raw as $k => $v) {
				if (isset($my_config[$k]) && preg_match('/^(off|on|yes|no|true|false|none|null)$/i', (string)$v)) {
					$my_config[$k] = $v;
				}
			}
			$_SESSION['my_config'] = array_merge(birdsuite_defaults(), $my_config);
		} else {
			syslog(LOG_ERR, "Cannot parse config");
		}
		$_SESSION['my_config_version'] = $mtime;
	}
	return $_SESSION['my_config'];
}

################################################################################
# Writing BirdSuite.conf (security 2026-11-02)
################################################################################
// The file is sourced by the station's bash scripts and read by PHP and Python, so every page writes it through
// conf_set() / conf_set_many() only: each key has a rule below (a key without one is refused), the value must pass it
// and may hold nothing the shell acts on ($ ` " \ ; | & < > or a line break). The quoted kinds (text, tpl, or 'q') may
// hold ; | & < > and spaces, which are inert inside the double quotes the value is then written in; tpl also allows
// $name (the notification variables). The file is rewritten whole, atomically (rename), under a lock.
// Rules: enum (values), int (min, max), num (min, max), re (pattern), text (max length), tpl (max length), fn (check).
function conf_rules() {
	static $r = null;
	if ($r !== null) return $r;
	$b = array('enum', array('0', '1'));
	$tf = array('enum', array('true', 'false'));
	$hm = array('re', '/^([01]\d|2[0-3]):[0-5]\d$/');
	$hm0 = array('re', '/^(([01]\d|2[0-3]):[0-5]\d)?$/');
	$hms = array('re', '/^([01]\d|2[0-3]):[0-5]\d:[0-5]\d$/');
	$days = array('re', '/^([0-6](,[0-6]){0,6})?$/');
	$level = array('enum', array('error', 'warning', 'info', 'debug'));
	$oc = array('enum', array('open', 'closed'));
	$models = array('BirdNET_GLOBAL_6K_V2.4_Model_FP16', 'BirdNET_6K_GLOBAL_MODEL', 'BirdNET-Plus_V3.0-preview3.1_Global_10K');
	$img = array('fn', function ($v) { return $v === '' || custom_image_ok($v); });
	$r = array(
		// station and location
		'SITE_NAME' => array('text', 60),
		'LATITUDE' => array('num', -90, 90),
		'LONGITUDE' => array('num', -180, 180),
		'LOCATION_ID' => array('int', 0, 999999),
		'LOCATION_CHECK' => $b,
		'LOCATION_IP_ERROR_KM' => array('int', 1, 500),
		'GEOLOCATION_API_KEY' => array('re', '/^[A-Za-z0-9_-]{0,100}$/'),
		'MULTI_LOCATION' => $b,
		'MULTI_STATION' => $b,
		'SPECIES_LIST' => array('fn', 'species_list_valid'),
		// model and analysis
		'MODEL' => array('enum', $models),
		'SF_THRESH' => array('num', 0.0005, 0.99),
		'CONFIDENCE' => array('num', 0.01, 0.99),
		'SENSITIVITY' => array('num', 0.5, 1.5),
		'OVERLAP' => array('num', 0, 2.9),
		'AVOID_DUPLICITY' => $b,
		'PRIVACY_THRESHOLD' => array('int', 0, 3),
		'DATA_MODEL_VERSION' => array('enum', array('1', '2')),
		'DATABASE_LANG' => array('fn', function ($v) { return isset(common_name_languages()[$v]); }),
		'SHADOW_MODEL_NAME' => array('enum', array_merge(array(''), $models)),
		'SHADOW_ENABLED' => $b,
		'SHADOW_MIN_CONF' => array('num', 0.01, 0.99),
		'SHADOW_SENS' => array('num', 0.5, 1.5),
		'SHADOW_GEO_THRESH' => array('num', 0.0005, 0.99),
		'NOCTURNAL_DAWN_MARGIN' => array('int', 0, 180),
		'NOCTURNAL_DUSK_MARGIN' => array('int', 0, 180),
		// audio
		'REC_CARD' => array('re', '/^[A-Za-z0-9_:,.=-]{1,100}$/'),
		// the chosen microphone by its ALSA card id (US-07), empty = the first USB one
		'REC_MIC' => array('re', '/^([A-Za-z_][A-Za-z0-9_]{0,31})?$/'),
		'CHANNELS' => array('int', 1, 32),
		'RECORDING_LENGTH' => array('int', 3, 60),
		'EXTRACTION_LENGTH' => array('int', 3, 60),
		'AUDIOFMT' => array('re', '/^[a-z0-9-]{1,10}$/'),
		'RTSP_STREAM' => array('fn', function ($v) {
			foreach (array_filter(explode(',', $v), 'strlen') as $u) if (!preg_match('#^(rtsps?|rtmp|https?)://[^\s"\'`$\\\\;|&<>]+$#', $u)) return false;
			return true;
		}),
		'RTSP_STREAM_TO_LIVESTREAM' => array('int', 0, 999),
		'FREQSHIFT_HI' => array('int', 0, 20000),
		'FREQSHIFT_LO' => array('int', 0, 20000),
		'FREQSHIFT_BASE' => array('int', 0, 10000),
		'LogLevel_BirdnetRecordingService' => $level,
		'LogLevel_SpectrogramViewerService' => $level,
		'LogLevel_LiveAudioStreamService' => $level,
		'RAW_REC_ENABLED' => $b,
		'RAW_REC_DAYS' => $days,
		'RAW_REC_RECURRENT' => $b,
		'RAW_REC_START' => $hms,
		'RAW_REC_END' => $hms,
		'RAW_REC_SEGMENT_MIN' => array('int', 1, 240),
		'RAW_REC_RATE' => array('int', 8000, 384000),
		'RAW_REC_DIR' => array('re', '#^(/[A-Za-z0-9._ -]+)*$#'),
		// access
		'CADDY_USER' => array('re', '/^[A-Za-z0-9]{1,32}$/'),
		// the web password is stored only as its bcrypt hash (SEC-15, 2026-11-02), in single quotes so neither PHP nor a shell
		// that sources the file reads its $ signs; empty = the factory password
		'CADDY_PWD' => array('re', '/^(\$2[aby]\$\d\d\$[.\/A-Za-z0-9]{53})?$/', 'sq' => true),
		'BIRDNETPI_URL' => array('re', '#^(https?://[A-Za-z0-9.-]+(:[0-9]{1,5})?)?$#'),
		'HTTPS_ALWAYS' => $b,
		'WEB_LOGIN' => array('enum', array('on', 'off')),
		'AUDIO_LOSS_DISCARD' => $b,
		'REC_BITS' => array('enum', array('16', '24')),
		'AUDIO_LOSS_MAX_MS' => array('int', 1, 1000),
		'LAN_TRUST' => array('enum', array('everyone', 'household')),
		'UI_PRESET' => array('enum', array('', 'home', 'birder', 'research')),
		'UI_CONTEXT' => array('fn', function ($v) { return $v === '' || (bool)preg_match('/^(home|field_phone|field_offline)(,(home|field_phone|field_offline))*$/', $v); }),
		'PWD_RECOVERY' => array('enum', array('', 'email', 'telegram', 'sdcard')),
		// notifications
		'APPRISE_NOTIFICATION_TITLE' => array('tpl', 200),
		'APPRISE_NOTIFICATION_TITLE_RARE' => array('tpl', 200),
		'APPRISE_NOTIFY_EACH_DETECTION' => $b,
		'APPRISE_NOTIFY_NEW_SPECIES' => $b,
		'APPRISE_NOTIFY_NEW_SPECIES_EACH_DAY' => $b,
		'APPRISE_NOTIFY_REGION_RARE' => $b,
		'APPRISE_MIN_SEC_PER_SPECIES' => array('int', 0, 86400),
		'APPRISE_QUIET_START_NORMAL' => $hm0,
		'APPRISE_QUIET_END_NORMAL' => $hm0,
		'APPRISE_QUIET_START_RARE' => $hm0,
		'APPRISE_QUIET_END_RARE' => $hm0,
		'NOTIFICATION_DEFAULT_TIER' => array('enum', array('muted', 'normal', 'rare')),
		'NOTIFICATION_EMAIL' => array('re', '/^([^\s"\'`$\\\\@;|&<>]+@[^\s"\'`$\\\\@;|&<>]+)?$/'),
		// repositories
		'BIRDWEATHER_ID' => array('re', '/^([A-Za-z0-9_-]{4,64})?$/'),
		'BIRDWEATHER_ENABLED' => $b,
		'BIRDWEATHER_PER_LOCATION' => $b,
		'ACTIVATE_BIRDDB' => $tf,
		'BIRDDB_ENABLED' => $b,
		'SOUND_REPO_REMOTE' => array('re', '/^([A-Za-z0-9_-]+:[^\s"\'`$\\\\]*)?$/', 'q' => true),
		'SOUND_REPO_UPLOAD_MINUTES' => array('int', 0, 1440),
		// disk
		'FULL_DISK' => array('enum', array('purge', 'keep', 'external')),
		'FULL_DISK_PATH' => array('re', '#^(/[^\x00-\x1F"`$\\\\]*)?$#', 'q' => true),
		'FULL_DISK_FALLBACK' => array('enum', array('keep', 'purge')),
		'PURGE_THRESHOLD' => array('int', 0, 99),
		'PURGE_PROTECT_TOP_N' => array('int', 0, 100),
		'PURGE_RECENT_DAYS' => array('int', 0, 365),
		'IGNORED_PURGE_DAYS' => array('int', 0, 3650),
		'MAX_FILES_SPECIES' => array('int', 0, 100000),
		'MAX_FILES_SPECIES_DAY' => array('int', 0, 100000),
		'LOG_RETENTION_DAYS' => array('enum', array('0', '7', '30', '90', '365')),
		// pages and look
		'APP_THEME' => array('fn', 'theme_valid'),
		'UI_LANG' => array('fn', function ($v) { return $v === '' || isset(ui_languages()[$v]); }),
		'UI_LEVEL' => array('enum', array('', 'essential', 'birder', 'expert')),
		'RARE_SPECIES_THRESHOLD' => array('int', 1, 3650),
		'NOW_BACKGROUND' => $img,
		'NOW_BACKGROUND_TEST' => $img,
		'NOW_PERIOD' => array('enum', array('1', '5', '15', '60', '240', '480', '720')),
		'NOW_CONF' => array('enum', array('10', '20', '30', '40', '50', '60', '70', '80', '90')),
		'LATEST_VIEW' => array('enum', array('simple', 'list', 'spectrogram')),
		'LATEST_COUNT' => array('enum', array('10', '25', '50', '100')),
		'LATEST_LOWPROB' => array('int', 5, 95),
		'LATEST_LOWCONF' => array('int', 5, 95),
		'LATEST_UNCOMMON' => array('int', 1, 100000),
		'LATEST_PERIOD' => array('enum', array('today', '7d', 'all')),
		'LATEST_FILTERS_PHONE' => $oc,
		'LATEST_FILTERS_DESKTOP' => $oc,
		'LATEST_PARAMS_PHONE' => $oc,
		'LATEST_PARAMS_DESKTOP' => $oc,
		'REPORTS_FILTERS_PHONE' => $oc,
		'REPORTS_FILTERS_DESKTOP' => $oc,
		'SPECTROGRAM_HEIGHT' => array('int', 20, 100),
		'SPECTROGRAM_PALETTE' => array('enum', array('turbo', 'inferno', 'roseus', 'soxheat', 'birdnet', 'viridis', 'ocean', 'grayscale')),
		'SPECTROGRAM_SILENT' => $b,
		'SPECTROGRAM_COMPRESSION' => $b,
		'SPECTROGRAM_FLOOR_DB' => array('int', -120, -40),
		'SPECTROGRAM_RANGE_DB' => array('int', 30, 120),
		'SPECTROGRAM_CONTRAST' => array('num', 0.5, 2),
		'RAW_SPECTROGRAM' => $b,
		'ACTIVATE_KIOSK' => $tf,
		// updates
		'SILENCE_UPDATE_INDICATOR' => $b,
		'AUTOMATIC_UPDATE' => $b,
		'AUTOMATIC_UPDATE_DAYS' => $days,
		'AUTOMATIC_UPDATE_TIME' => $hm,
		// field access
		'FIELD_WIFI_MODE' => array('enum', array('always', 'cycle', 'off')),
		'FIELD_BOOT_WAIT_SEC' => array('int', 0, 3600),
		'FIELD_LOST_WAIT_SEC' => array('int', 0, 3600),
		'FIELD_AP_WINDOW_MIN' => array('int', 1, 120),
		'FIELD_CYCLE_MIN' => array('int', 1, 1440),
		'FIELD_AP_IDLE_MIN' => array('int', 1, 1440),
		// eBird
		'EBIRD_REPORT_ENABLED' => $b,
		'EBIRD_REPORT_FREQ' => array('enum', array('daily', 'weekly')),
		'EBIRD_REPORT_DAY' => array('int', 1, 7),
		'EBIRD_REPORT_TIME' => $hm,
		'EBIRD_REPORT_NAME' => array('text', 100),
		'EBIRD_REPORT_SPECIES' => array('enum', array('all', 'confirmed', 'minconf')),
		'EBIRD_REPORT_MIN_CONF' => array('num', 0, 0.99),
		'EBIRD_PAGE_MODE' => array('enum', array('all', 'confirmed', 'minconf')),
		'EBIRD_PAGE_MIN_CONF' => array('num', 0, 0.99),
		'EBIRD_LOCATION_NAME' => array('text', 100),
		'EBIRD_LOCATION_NAMES' => array('text', 1010),
	);
	return $r;
}

// the value to write for this key, or null when the key is unknown or the value breaks its rule
function conf_check($key, $value) {
	$rules = conf_rules();
	if (!is_string($key) || !isset($rules[$key]) || !is_scalar($value)) return null;
	$rule = $rules[$key];
	$v = trim((string)$value);
	if (preg_match('/[\x00-\x1F\x7F]/', $v)) return null;
	// a single-quoted key (the web password's bcrypt hash, $2b$12$...): inside single quotes nothing expands, only a
	// single quote could end it (2026-11-03: the '$' check below refused every hash, so no page could change the password)
	if (!empty($rule['sq'])) {
		if (strpos($v, "'") !== false) return null;
	} else {
		$quoted = $rule[0] === 'text' || $rule[0] === 'tpl' || !empty($rule['q']);
		if (preg_match($quoted ? '/[`"\\\\]/' : '/[$`"\\\\;|&<>]/', $v)) return null;
		// inside double quotes only $name expands (to nothing in a script that does not set it); never $( or ${
		if ($quoted && preg_match($rule[0] === 'tpl' ? '/\$(?![A-Za-z_])/' : '/\$/', $v)) return null;
	}
	switch ($rule[0]) {
		case 'enum': return in_array($v, $rule[1], true) ? $v : null;
		case 'int': return preg_match('/^-?\d{1,9}$/', $v) && intval($v) >= $rule[1] && intval($v) <= $rule[2] ? (string)intval($v) : null;
		case 'num': return preg_match('/^-?\d{1,9}(\.\d{1,8})?$/', $v) && floatval($v) >= $rule[1] && floatval($v) <= $rule[2] ? $v : null;
		case 're': return preg_match($rule[1], $v) ? $v : null;
		case 'text': case 'tpl': return mb_strlen($v) <= $rule[1] ? $v : null;
		case 'fn': return call_user_func($rule[1], $v) ? $v : null;
	}
	return null;
}

// write one key; $err gets the reason when it answers false
function conf_set($key, $value, &$err = null) {
	return conf_set_many(array($key => $value), $err);
}

// write several keys at once: all of them pass their rule or nothing is written
function conf_set_many($pairs, &$err = null) {
	$err = '';
	$clean = array();
	foreach ((array)$pairs as $k => $v) {
		$c = conf_check($k, $v);
		if ($c === null) { $err = t('str_not_a_valid_value_for', array('key' => $k)); return false; }
		$clean[$k] = $c;
	}
	if (!$clean) return true;
	$target = realpath(BS_CONF) ?: BS_CONF;
	if (!is_dir(BS_STATE)) @mkdir(BS_STATE, 0775, true);
	$lock = @fopen(BS_STATE . '/settings.lock', 'c');
	if (!$lock || !flock($lock, LOCK_EX)) { $err = t('str_error_writing_the_settings'); return false; }
	$c = (string)@file_get_contents($target);
	foreach ($clean as $k => $v) {
		$re = '/^' . preg_quote($k, '/') . '=(.*)$/m';
		$was_quoted = preg_match($re, $c, $m) && strpos($m[1], '"') === 0;
		$rule = conf_rules()[$k] ?? array();
		if (!empty($rule['sq'])) $line = $k . "='" . $v . "'";
		else $line = $k . '=' . ($was_quoted || !preg_match('/^[A-Za-z0-9._:,\/+@%=-]*$/', $v) ? '"' . $v . '"' : $v);
		if (preg_match($re, $c)) $c = preg_replace_callback($re, function () use ($line) { return $line; }, $c);
		else $c = rtrim($c, "\n") . "\n" . $line . "\n";
	}
	// a new file beside it, renamed over it: a reader never sees half a file; the mode and the group of the old one kept
	// (the pages may run as another user than the station's, in its group)
	$tmp = $target . '.tmp' . getmypid();
	$ok = file_put_contents($tmp, $c) !== false;
	if ($ok) {
		$mode = @fileperms($target);
		@chmod($tmp, $mode !== false ? ($mode & 0777) : 0664);
		$grp = @filegroup($target);
		if ($grp !== false) @chgrp($tmp, $grp);
		$ok = rename($tmp, $target);
	}
	if (!$ok) { @unlink($tmp); $err = t('str_error_writing_the_settings'); }
	flock($lock, LOCK_UN);
	fclose($lock);
	if ($ok) get_config(true);
	return $ok;
}

function get_user() {
	$config = get_config();
	$user = $config['BIRDNET_USER'];
	return $user;
}

// the station user's home (core.php: the folder above the program folder)
function get_home() {
	return BS_HOME;
}

################################################################################
# The settings pages (Model, What and when, Sharing, Microphone, Access): the shared steps of their forms
################################################################################
// changes arrive by POST (2026-10-23 test run: sent by GET, tokens landed in the web server log and the browser
// history); the handlers read $_GET, so the posted fields join it, and a GET keeps only the keys that choose the page
function settings_post_merge($get_keys) {
	if ($_SERVER['REQUEST_METHOD'] === 'POST') {
		$_GET = array_merge((array)$_GET, (array)$_POST);
	} else {
		$_GET = array_intersect_key((array)$_GET, array_flip($get_keys));
	}
}
// the clock is NTP's (owner 2026-10-22): a save never leaves NTP off (the hidden Time and Date fields of the upstream
// form once turned it off for good)
function settings_ntp_on() {
	if (trim((string)exec("timedatectl show -p NTP --value")) !== 'yes') {
		exec("sudo -n " . BS_ROOT_HELPER . " ntp on");
		sleep(3);
	}
}
// true when one of the values to write differs from the settings file (numbers compared as numbers, "1.0" = "1")
function settings_dirty($set, $config) {
	$same = function ($a, $b) { return is_numeric($a) && is_numeric($b) ? floatval($a) == floatval($b) : (string)$a === (string)$b; };
	foreach ($set as $k => $v) if (!$same($v, trim((string)($config[$k] ?? ''), "\"'"))) return true;
	return false;
}
// the saved form opens again by GET with its outcome (post/redirect/get: a reload does not send it twice); the menu is
// already printed, so the browser is sent there by script; status = success | nochange
function settings_redirect($view, $status) {
	echo '<script>location.replace(' . json_encode('views.php?view=' . $view . '&status=' . $status) . ');</script>';
	die();
}
// the outcome of a save, or the reason a value was refused, as a notice at the end of the page
function settings_notice($cfg_error) {
	if ($cfg_error !== '') {
		echo '<script>bsNotice(' . json_encode($cfg_error, JSON_HEX_TAG | JSON_UNESCAPED_UNICODE) . ');</script>';
	} elseif (isset($_GET['status'])) {
		echo '<script>bsNotice(' . json_encode(t($_GET['status'] === 'nochange' ? 'str_nothing_to_save' : 'str_settings_successfully_updated'), JSON_HEX_TAG | JSON_UNESCAPED_UNICODE) . ');</script>';
	}
}
