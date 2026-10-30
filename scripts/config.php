<?php
error_reporting(E_ERROR);
ini_set('display_errors', 0);  // errors to the log, never to the page (2026-10-23 security run)
define('__ROOT__', dirname(dirname(__FILE__)));
require_once(__ROOT__.'/scripts/common.php');

$user = get_user();
$home = get_home();
$config = get_config();
// the BirdDB-Br section of Repositories: Tools › Defaults › Sharing › ACTIVATE_BIRDDB (owner 2026-10-27: false by default
// until the upload is decided); a station that contributes (BIRDDB_ENABLED=1) always shows it, so it can be turned off
if (!defined('BIRDDB_SECTION_SHOWN')) define('BIRDDB_SECTION_SHOWN', trim((string)($config['ACTIVATE_BIRDDB'] ?? 'false'), "\"' ") === 'true'
  || trim((string)($config['BIRDDB_ENABLED'] ?? '0'), "\"' ") === '1');
set_timezone();

ensure_authenticated();

// changes arrive by POST (2026-10-23 test run: sent by GET, the BirdWeather tokens and the notification URLs landed in
// the web server log and the browser history); the handlers below read $_GET, so the posted fields join it, and a GET
// keeps only what chooses the page (view, the "updated" alert) and the species threshold preview
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $_GET = array_merge((array)$_GET, (array)$_POST);
} else {
  $_GET = array_intersect_key((array)$_GET, array_flip(array('view', 'status', 'threshold')));
}

if (file_exists($home."/BirdNET-Pi/config/notifications/apprise.txt")) {
  $apprise_config = file_get_contents($home."/BirdNET-Pi/config/notifications/apprise.txt");
} else {
  $apprise_config = "";
}
$apprise_config_rare = file_exists($home."/BirdNET-Pi/config/notifications/apprise-rare.txt") ? file_get_contents($home."/BirdNET-Pi/config/notifications/apprise-rare.txt") : "";
$apprise_notification_body_rare = file_exists($home."/BirdNET-Pi/config/notifications/body-rare.txt") ? file_get_contents($home."/BirdNET-Pi/config/notifications/body-rare.txt") : "";

if (file_exists($home."/BirdNET-Pi/config/notifications/body.txt")) {
  $apprise_notification_body = file_get_contents($home."/BirdNET-Pi/config/notifications/body.txt");
} else {
  $apprise_notification_body = "";
}

function syslog_shell_exec($cmd, $sudo_user = null) {
  if ($sudo_user) {
    $cmd = station_prefix() . $cmd;  // the station user (directly when the pages already are that user)
  }
  $output = shell_exec($cmd);

  if (strlen($output) > 0) {
    syslog(LOG_INFO, $output);
  }
}

// the Common name Language saved as soon as it is chosen (owner 2026-10-24: it only renames, nothing else to confirm):
// the setting, the label file, the species renamed in the database and the lists (migrate_names.py, progress in Tools ›
// Data), the services restarted with the new names (detached: the web server may restart with them)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_GET['set_lang'])) {
  header('Content-Type: application/json');
  $lang = (string)$_GET['set_lang'];
  if (!isset(common_name_languages()[$lang]) || !is_file("$home/BirdNET-Pi/model/l18n/labels_$lang.json")) { echo json_encode(array('ok' => false)); die(); }
  if ($lang !== ($config['DATABASE_LANG'] ?? '')) {
    $c = file_get_contents(BS_CONF);
    $c = preg_match('/^DATABASE_LANG=/m', $c) ? preg_replace('/^DATABASE_LANG=.*/m', "DATABASE_LANG=$lang", $c) : $c . "\nDATABASE_LANG=$lang\n";
    file_put_contents(BS_CONF, $c);
    syslog_shell_exec("$home/BirdNET-Pi/scripts/install_language_label.sh", $user);
    names_migration_recheck();
    names_migration_start();
    shell_exec('setsid -f sh -c ' . escapeshellarg(station_prefix() . 'restart_services.sh') . ' > /dev/null 2>&1 < /dev/null');
    syslog(LOG_INFO, "Common name Language changed to '$lang'");
  }
  echo json_encode(array('ok' => true));
  die();
}

if(isset($_GET['threshold'])) {
  $threshold = $_GET['threshold'];
  if (!is_numeric($threshold) || $threshold < 0 || $threshold > 1) {
    die('Invalid threshold value');
  }

  $command = station_prefix() . "".$home."/BirdNET-Pi/birdnet/bin/python3 ".$home."/BirdNET-Pi/scripts/species.py --threshold $threshold 2>&1";

  $output = shell_exec($command);

  echo $output;
  die();
}

if(isset($_GET['restart_php']) && $_GET['restart_php'] == "true") {
  shell_exec("sudo -n /usr/local/sbin/birdsuite-svc restart php" . PHP_MAJOR_VERSION . "." . PHP_MINOR_VERSION . "-fpm");
  die();
}

# Basic Settings
if(isset($_GET["latitude"])){
  $latitude = $_GET["latitude"];
  $longitude = $_GET["longitude"];
  $site_name = $_GET["site_name"];
  $site_name = str_replace('"', "", $site_name);
  $site_name = str_replace('\'', "", $site_name);
  $birdweather_id = $_GET["birdweather_id"];
  // BirdWeather upload switch (the token is kept when it is off); only the Settings form carries it
  $birdweather_enabled = isset($_GET['birdweather_enabled']) ? 1 : 0;
  $apprise_input = $_GET['apprise_input'];
  if(isset($_GET['apprise_input_rare'])) { $apprise_input_rare = $_GET['apprise_input_rare']; }
  $apprise_notification_title = $_GET['apprise_notification_title'];
  $apprise_notification_body = htmlspecialchars_decode($_GET['apprise_notification_body'], ENT_QUOTES);
  // written quoted into BirdSuite.conf (sourced by shell scripts): no quotes, backticks, backslashes, $( or control characters
  // quiet hours per tier (owner 2026-10-08): HH:MM to HH:MM, empty = always notify
  $quiet = array();
  foreach (array('quiet_start_normal' => 'APPRISE_QUIET_START_NORMAL', 'quiet_end_normal' => 'APPRISE_QUIET_END_NORMAL',
                 'quiet_start_rare' => 'APPRISE_QUIET_START_RARE', 'quiet_end_rare' => 'APPRISE_QUIET_END_RARE') as $param => $key) {
    if (isset($_GET[$param])) {
      $quiet[$key] = preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $_GET[$param]) ? $_GET[$param] : '';
    }
  }
  // region-rare alerts (Rare block checkbox; the rare title field is always sent with it)
  if(isset($_GET['apprise_notification_title_rare'])) { $notify_region_rare = isset($_GET['notify_region_rare']) ? 1 : 0; }
  if(isset($_GET['apprise_notification_title_rare'])) { $apprise_notification_title_rare = str_replace('$(', '', preg_replace('/["`\\\\\x00-\x1F\x7F]/', '', $_GET['apprise_notification_title_rare'])); }
  if(isset($_GET['apprise_notification_body_rare'])) { $apprise_notification_body_rare = htmlspecialchars_decode($_GET['apprise_notification_body_rare'], ENT_QUOTES); }
  $minimum_time_limit = $_GET['minimum_time_limit'];
  $flickr_filter_email = $_GET["flickr_filter_email"];
  $language = $_GET["language"];
  // Portuguese has two name sets (owner 2026-10-07): pt_BR = CBRO (Brazil), pt_PT = the upstream
  // Portugal names, two entries of the list; a plain 'pt' (older form) is stored as pt_BR
  if($language === 'pt') {
    $language = 'pt_BR';
  }
  $info_site = in_array($_GET["info_site"] ?? '', array('ALLABOUTBIRDS', 'EBIRD', 'WIKIAVES'), true) ? $_GET["info_site"] : 'ALLABOUTBIRDS';
  $color_scheme = $_GET["color_scheme"];
  // US-41 follow-up (owner 2026-09-17): spectrogram height + palette live here, under "Spectrogram and colours"
  $spectrogram_height = isset($_GET['spectrogram_height']) && is_numeric($_GET['spectrogram_height']) ? max(20, min(100, intval($_GET['spectrogram_height']))) : null;
  // Latest page: the most recent detections as spectrogram cards or as a list (owner 2026-10-09)
  $now_view = isset($_GET['now_view']) && in_array($_GET['now_view'], array('simple', 'list', 'spectrogram'), true) ? $_GET['now_view'] : null;
  // boot check of the network position (owner 2026-10-10, scripts/location_check.py): 1 = on (default), 0 = off
  $location_check = isset($_GET['submit']) && ($_GET['submit'] ?? '') === 'settings' ? (isset($_GET['location_check']) ? '1' : '0') : null;
  $now_analyzing = isset($_GET['now_analyzing']) && in_array($_GET['now_analyzing'], array('show', 'hide'), true) ? $_GET['now_analyzing'] : null;
  $spectrogram_palette = isset($_GET['spectrogram_palette']) && in_array($_GET['spectrogram_palette'], array('turbo','inferno','roseus','soxheat','birdnet','viridis','ocean','grayscale'), true) ? $_GET['spectrogram_palette'] : null;
  // US-42: colour sensitivity
  $spectrogram_floor_db = isset($_GET['spectrogram_floor_db']) && is_numeric($_GET['spectrogram_floor_db']) ? max(-120, min(-40, intval($_GET['spectrogram_floor_db']))) : null;
  $spectrogram_range_db = isset($_GET['spectrogram_range_db']) && is_numeric($_GET['spectrogram_range_db']) ? max(30, min(120, intval($_GET['spectrogram_range_db']))) : null;
  $spectrogram_contrast = isset($_GET['spectrogram_contrast']) && is_numeric($_GET['spectrogram_contrast']) ? max(0.5, min(2.0, round(floatval($_GET['spectrogram_contrast']), 2))) : null;
  $timezone = $_GET["timezone"];
  $model = $_GET["model"];
  $known_models = array("BirdNET_GLOBAL_6K_V2.4_Model_FP16", "BirdNET_6K_GLOBAL_MODEL", "BirdNET-Plus_V3.0-preview3.1_Global_10K");
  $shadow_model = isset($_GET['shadow_model']) && in_array($_GET['shadow_model'], $known_models) && $_GET['shadow_model'] != $model ? $_GET['shadow_model'] : '';
  $shadow_min_conf = isset($_GET['shadow_min_conf']) && is_numeric($_GET['shadow_min_conf']) ? max(0.01, min(0.99, round(floatval($_GET['shadow_min_conf']), 2))) : 0.35;
  $sf_thresh = $_GET["sf_thresh"];
  // US-47 (owner 2026-09-22): both models and their parameters live in one "Models" block.
  // Official: CONFIDENCE / SENSITIVITY / SF_THRESH; shadow: SHADOW_MIN_CONF / SHADOW_SENS /
  // SHADOW_GEO_THRESH; OVERLAP is shared (one recording pipeline, both models see the same windows).
  $confidence = isset($_GET['confidence']) && is_numeric($_GET['confidence']) ? max(0.01, min(0.99, round(floatval($_GET['confidence']), 2))) : $config['CONFIDENCE'];
  $sensitivity = isset($_GET['sensitivity']) && is_numeric($_GET['sensitivity']) ? max(0.5, min(1.5, round(floatval($_GET['sensitivity']), 2))) : $config['SENSITIVITY'];
  $overlap = isset($_GET['overlap']) && is_numeric($_GET['overlap']) ? max(0.0, min(2.9, round(floatval($_GET['overlap']), 1))) : $config['OVERLAP'];
  $shadow_sens = isset($_GET['shadow_sens']) && is_numeric($_GET['shadow_sens']) ? max(0.5, min(1.5, round(floatval($_GET['shadow_sens']), 2))) : ($config['SHADOW_SENS'] ?? 1.0);
  $shadow_geo_thresh = isset($_GET['shadow_geo_thresh']) && is_numeric($_GET['shadow_geo_thresh']) ? max(0.0005, min(0.99, floatval($_GET['shadow_geo_thresh']))) : ($config['SHADOW_GEO_THRESH'] ?? 0.03);
  // Shadow on/off flag: unchecked = the shadow model and its parameters stay configured
  // but nothing is analysed by it (only the official model runs). Absent key = off (the default).
  $shadow_enabled = isset($_GET['shadow_enabled']) ? 1 : 0;
  // one species per recording (Settings > Models): only the highest-confidence species of each file is kept
  $avoid_duplicity = isset($_GET['avoid_duplicity']) ? 1 : 0;
  // the privacy filter, from Settings › Advanced (owner 2026-10-25): 0-3 % of the species list; absent = unchanged
  $privacy_threshold = isset($_GET['privacy_threshold']) && is_numeric($_GET['privacy_threshold']) ? max(0, min(3, intval($_GET['privacy_threshold']))) : intval($config['PRIVACY_THRESHOLD'] ?? 1);
  // Swap: the shadow model becomes the official one and vice versa, each keeping its own
  // three parameters (what the owner did by hand on 2026-09-21). Only when a shadow is set.
  if(isset($_GET['swap_models']) && $shadow_model != '') {
    list($model, $shadow_model) = array($shadow_model, $model);
    list($confidence, $shadow_min_conf) = array($shadow_min_conf, $confidence);
    list($sensitivity, $shadow_sens) = array($shadow_sens, $sensitivity);
    list($sf_thresh, $shadow_geo_thresh) = array($shadow_geo_thresh, $sf_thresh);
  }
  if(isset($_GET['data_model_version'])) {
    $data_model_version = 2;
  } else {
    $data_model_version = 1;
  }
  if(isset($_GET['only_notify_species_names'])) { $only_notify_species_names = htmlspecialchars_decode($_GET['only_notify_species_names'], ENT_QUOTES); }
  if(isset($_GET['only_notify_species_names_2'])) { $only_notify_species_names_2 = htmlspecialchars_decode($_GET['only_notify_species_names_2'], ENT_QUOTES); }

  // written into BirdSuite.conf, which shell scripts source: a plain address only, nothing a shell would expand
  if(isset($_GET['notification_email']) && preg_match('/^[^\s"\'`$\\\\@]+@[^\s"\'`$\\\\@]+$/', trim($_GET['notification_email']))) { $notification_email = trim($_GET['notification_email']); }
  if(isset($_GET['notification_default_tier'])) {
    $notification_default_tier = strtolower($_GET['notification_default_tier']);
    if(!in_array($notification_default_tier, ['muted', 'normal', 'rare'], true)) {
      $notification_default_tier = 'normal';
    }
  }
  // Species list filter (owner 2026-10-08): '' = none (the model's species distribution decides), BR-<UF> =
  // a Brazilian state (built once into species_lists/), or a list of ~/BirdNET-Pi/config/species_lists/custom/ —
  // scripts/select_species_list.py links include_species_list.txt to it
  if(isset($_GET['species_list'])) {
    $species_list = $_GET['species_list'];
    $is_state = preg_match('/^BR-[A-Z]{2}$/', $species_list) && is_file($home."/BirdNET-Pi/model/include_lists/".$species_list.".txt");
    $is_file = preg_match('/^[A-Za-z0-9_-]+$/', $species_list) && is_file($home."/BirdNET-Pi/config/species_lists/custom/".$species_list.".txt");
    if($species_list !== '' && !$is_state && !$is_file) {
      $species_list = '';
    }
  }
  if(isset($_GET['apprise_notify_each_detection'])) {
    $apprise_notify_each_detection = 1;
  } else {
    $apprise_notify_each_detection = 0;
  }
  if(isset($_GET['apprise_notify_new_species'])) {
    $apprise_notify_new_species = 1;
  } else {
    $apprise_notify_new_species = 0;
  }
  if(isset($_GET['apprise_notify_new_species_each_day'])) {
    $apprise_notify_new_species_each_day = 1;
  } else {
    $apprise_notify_new_species_each_day = 0;
  }

  if(isset($timezone) && in_array($timezone, DateTimeZone::listIdentifiers())) {
    # dpkg-reconfigure tzdata is a pain to run non-interactively, so we do it in two steps instead
    # tzlocal.get_localzone() will fail if the Debian specific /etc/timezone is not in sync
    set_station_timezone($timezone);  // common.php: timedatectl and /etc/timezone, the only two root steps
    $_SESSION['my_timezone'] = $timezone;
    date_default_timezone_set($timezone);
    // php-fpm restarts for the new time zone once this answer is in the browser (see the redirect below)
    $restart_php = true;
  }

  // the clock is NTP's (owner 2026-10-22): the hidden Time and Date fields of the upstream form were sent with every save
  // while NTP was not synchronised, turned NTP off (set-ntp false disabled chrony for good) and set the clock to the time
  // the page was opened; without internet the clock is set from the phone (Now: Use this device's time)
  if(trim((string)exec("timedatectl show -p NTP --value")) !== 'yes'){
    exec("sudo -n /usr/local/sbin/birdsuite-root ntp on");
    sleep(3);
  }

  $contents = file_get_contents(BS_CONF);
  $contents = preg_replace("/SITE_NAME=.*/", "SITE_NAME=\"$site_name\"", $contents);
  // coordinates only when valid numbers (the Location section is hidden on these pages; 2026-10-23 test run)
  if (is_numeric($latitude) && abs((float)$latitude) <= 90) $contents = preg_replace("/LATITUDE=.*/", "LATITUDE=$latitude", $contents);
  if (is_numeric($longitude) && abs((float)$longitude) <= 180) $contents = preg_replace("/LONGITUDE=.*/", "LONGITUDE=$longitude", $contents);
  $contents = preg_replace("/BIRDWEATHER_ID=.*/", "BIRDWEATHER_ID=$birdweather_id", $contents);
  if(preg_match("/^BIRDWEATHER_ENABLED=/m", $contents)) {
    $contents = preg_replace("/^BIRDWEATHER_ENABLED=.*/m", "BIRDWEATHER_ENABLED=$birdweather_enabled", $contents);
  } else {
    $contents .= "\n## BIRDWEATHER_ENABLED: 1 = upload soundscapes and detections to BirdWeather (needs BIRDWEATHER_ID), 0 = paused\nBIRDWEATHER_ENABLED=$birdweather_enabled\n";
  }
  $contents = preg_replace("/APPRISE_NOTIFICATION_TITLE=.*/", "APPRISE_NOTIFICATION_TITLE=\"$apprise_notification_title\"", $contents);
  foreach ($quiet ?? array() as $key => $val) {
    if (preg_match("/^$key=/m", $contents)) {
      $contents = preg_replace("/^$key=.*/m", "$key=\"$val\"", $contents);
    } else {
      $contents .= "\n## $key: quiet hours of the notification tier (HH:MM, empty = always notify)\n$key=\"$val\"\n";
    }
  }
  if(isset($notify_region_rare)) {
    if(preg_match("/^APPRISE_NOTIFY_REGION_RARE=/m", $contents)) {
      $contents = preg_replace("/^APPRISE_NOTIFY_REGION_RARE=.*/m", "APPRISE_NOTIFY_REGION_RARE=$notify_region_rare", $contents);
    } else {
      $contents .= "\n## APPRISE_NOTIFY_REGION_RARE: 1 = species the location model does not expect here (vagrant / out of season) go to the Rare channel\nAPPRISE_NOTIFY_REGION_RARE=$notify_region_rare\n";
    }
  }
  if(isset($apprise_notification_title_rare)) {
    if(preg_match("/^APPRISE_NOTIFICATION_TITLE_RARE=/m", $contents)) {
      $contents = preg_replace("/APPRISE_NOTIFICATION_TITLE_RARE=.*/", "APPRISE_NOTIFICATION_TITLE_RARE=\"$apprise_notification_title_rare\"", $contents);
    } else {
      // Config written before this setting existed - append the new key
      $contents .= "\nAPPRISE_NOTIFICATION_TITLE_RARE=\"$apprise_notification_title_rare\"\n";
    }
  }
  $contents = preg_replace("/APPRISE_NOTIFY_EACH_DETECTION=.*/", "APPRISE_NOTIFY_EACH_DETECTION=$apprise_notify_each_detection", $contents);
  $contents = preg_replace("/APPRISE_NOTIFY_NEW_SPECIES=.*/", "APPRISE_NOTIFY_NEW_SPECIES=$apprise_notify_new_species", $contents);
  $contents = preg_replace("/APPRISE_NOTIFY_NEW_SPECIES_EACH_DAY=.*/", "APPRISE_NOTIFY_NEW_SPECIES_EACH_DAY=$apprise_notify_new_species_each_day", $contents);
  if(strlen($language) == 2 || strlen($language) == 5){
    $contents = preg_replace("/DATABASE_LANG=.*/", "DATABASE_LANG=$language", $contents);
  }
  // interface language (US-122): one of the known codes or empty (follow the species names)
  if (isset($_GET['ui_lang'])) {
    $ui_lang = isset(ui_languages()[$_GET['ui_lang']]) ? $_GET['ui_lang'] : '';
    if (preg_match('/^UI_LANG=/m', $contents)) $contents = preg_replace('/^UI_LANG=.*/m', "UI_LANG=$ui_lang", $contents);
    else $contents .= "\nUI_LANG=$ui_lang\n";
  }
  $contents = preg_replace("/INFO_SITE=.*/", "INFO_SITE=$info_site", $contents);
  $contents = preg_replace("/COLOR_SCHEME=.*/", "COLOR_SCHEME=$color_scheme", $contents);  
  if($spectrogram_height !== null) {
    if(preg_match("/^SPECTROGRAM_HEIGHT=/m", $contents)) {
      $contents = preg_replace("/SPECTROGRAM_HEIGHT=.*/", "SPECTROGRAM_HEIGHT=$spectrogram_height", $contents);
    } else {
      $contents .= "\n## SPECTROGRAM_HEIGHT is the height of the live spectrogram in percent of the page height (vh)\nSPECTROGRAM_HEIGHT=$spectrogram_height\n";
    }
  }
  if($now_analyzing !== null) {
    if(preg_match("/^NOW_ANALYZING=/m", $contents)) {
      $contents = preg_replace("/^NOW_ANALYZING=.*/m", "NOW_ANALYZING=$now_analyzing", $contents);
    } else {
      $contents .= "\n## NOW_ANALYZING: the Latest page opens with the Currently Analyzing spectrogram shown (show) or hidden (hide)\nNOW_ANALYZING=$now_analyzing\n";
    }
  }
  if($now_view !== null) {
    if(preg_match("/^NOW_VIEW=/m", $contents)) {
      $contents = preg_replace("/^NOW_VIEW=.*/m", "NOW_VIEW=$now_view", $contents);
    } else {
      $contents .= "\n## NOW_VIEW: how the Latest page shows the most recent detections: simple (list, few columns), list (full) or spectrogram (cards)\nNOW_VIEW=$now_view\n";
    }
  }
  if($spectrogram_palette !== null) {
    if(preg_match("/^SPECTROGRAM_PALETTE=/m", $contents)) {
      $contents = preg_replace("/SPECTROGRAM_PALETTE=.*/", "SPECTROGRAM_PALETTE=$spectrogram_palette", $contents);
    } else {
      $contents .= "\n## SPECTROGRAM_PALETTE is the colour palette of the spectrograms: birdnet, viridis, inferno, ocean, grayscale, soxheat, turbo, roseus\nSPECTROGRAM_PALETTE=$spectrogram_palette\n";
    }
    if($spectrogram_palette != ($config['SPECTROGRAM_PALETTE'] ?? '')) {
      shell_exec("sudo -n /usr/local/sbin/birdsuite-svc restart spectrogram_viewer.service");
    }
  }
  $spec_restart = false;
  foreach (array('SPECTROGRAM_FLOOR_DB' => array($spectrogram_floor_db, 'dB floor of the spectrogram colour scale'),
                 'SPECTROGRAM_RANGE_DB' => array($spectrogram_range_db, 'dB range of the spectrogram colour scale above the floor'),
                 'SPECTROGRAM_CONTRAST' => array($spectrogram_contrast, 'contrast (gamma) of the spectrogram colour ramp; 1 = linear')) as $key => $pair) {
    list($val, $desc) = $pair;
    if($val === null) continue;
    if(preg_match("/^$key=/m", $contents)) {
      $contents = preg_replace("/$key=.*/", "$key=$val", $contents);
    } else {
      $contents .= "\n## $key is the $desc\n$key=$val\n";
    }
    if((string)$val !== (string)($config[$key] ?? '')) $spec_restart = true;
  }
  if($spec_restart) shell_exec("sudo -n /usr/local/sbin/birdsuite-svc restart spectrogram_viewer.service");
  $contents = preg_replace("/FLICKR_FILTER_EMAIL=.*/", "FLICKR_FILTER_EMAIL=$flickr_filter_email", $contents);
  $contents = preg_replace("/APPRISE_MIN_SEC_PER_SPECIES=.*/", "APPRISE_MIN_SEC_PER_SPECIES=$minimum_time_limit", $contents);
  $contents = preg_replace("/MODEL=.*/", "MODEL=$model", $contents);
  $contents = preg_replace("/^CONFIDENCE=.*/m", "CONFIDENCE=$confidence", $contents);
  $contents = preg_replace("/^SENSITIVITY=.*/m", "SENSITIVITY=$sensitivity", $contents);
  $contents = preg_replace("/^OVERLAP=.*/m", "OVERLAP=$overlap", $contents);
  foreach (array('SHADOW_MODEL_NAME' => array($shadow_model, 'model that analyses the same recordings beside the official one, into scripts/birds_shadow.db only; empty = off'),
                 'SHADOW_MIN_CONF' => array($shadow_min_conf, 'minimum confidence of a shadow model detection'),
                 'SHADOW_SENS' => array($shadow_sens, 'sigmoid sensitivity of the shadow model'),
                 'SHADOW_GEO_THRESH' => array($shadow_geo_thresh, 'location (species occurrence) threshold of the shadow model'),
                 'PRIVACY_THRESHOLD' => array($privacy_threshold, 'privacy filter: a recording where a human voice is among the top N% of the predictions is dropped (0-3, 0 = off)'),
                 'AVOID_DUPLICITY' => array($avoid_duplicity, 'avoid duplicity filter: 1 = only the species with the highest confidence in each recording is kept (fewer species), 0 = every species above its minimum (default)'),
                 'SHADOW_ENABLED' => array($shadow_enabled, 'shadow switch: 1 = the shadow model analyses every recording, 0 = only the official model runs (shadow settings kept)')) as $key => $pair) {
    list($val, $desc) = $pair;
    if(preg_match("/^$key=/m", $contents)) {
      $contents = preg_replace("/^$key=.*/m", "$key=$val", $contents);
    } else {
      $contents .= "\n## $key is the $desc\n$key=$val\n";
    }
  }
  $contents = preg_replace("/SF_THRESH=.*/", "SF_THRESH=$sf_thresh", $contents);
  $contents = preg_replace("/DATA_MODEL_VERSION=.*/", "DATA_MODEL_VERSION=$data_model_version", $contents);
  if(isset($only_notify_species_names)) { $contents = preg_replace("/APPRISE_EXCLUDE_SPECIES=.*/", "APPRISE_EXCLUDE_SPECIES=\"$only_notify_species_names\"", $contents); }
  if(isset($only_notify_species_names_2)) { $contents = preg_replace("/APPRISE_ONLY_SPECIES=.*/", "APPRISE_ONLY_SPECIES=\"$only_notify_species_names_2\"", $contents); }
  if(isset($notification_email)) {
    if(preg_match("/^NOTIFICATION_EMAIL=/m", $contents)) {
      $contents = preg_replace("/NOTIFICATION_EMAIL=.*/", "NOTIFICATION_EMAIL=\"$notification_email\"", $contents);
    } else {
      // Config written before this setting existed - append the new key
      $contents .= "\nNOTIFICATION_EMAIL=\"$notification_email\"\n";
    }
  }
  if(isset($location_check)) {
    $contents = preg_match('/^LOCATION_CHECK=/m', $contents) ? preg_replace('/^LOCATION_CHECK=.*/m', "LOCATION_CHECK=$location_check", $contents)
      : $contents . "\n## LOCATION_CHECK: 1 = at boot, compare the network position with the coordinates and ask when the station moved more than the move distance\nLOCATION_CHECK=$location_check\n";
  }
  if(isset($species_list)) {
    if(preg_match("/^SPECIES_LIST=/m", $contents)) {
      $contents = preg_replace("/^SPECIES_LIST=.*/m", "SPECIES_LIST=$species_list", $contents);
    } else {
      $contents .= "\n## SPECIES_LIST is the species list filter: empty = none, BR-<UF> = a Brazilian state, or a list of species_lists/\nSPECIES_LIST=$species_list\n";
    }
  }
  if(isset($notification_default_tier)) {
    if(preg_match("/^NOTIFICATION_DEFAULT_TIER=/m", $contents)) {
      $contents = preg_replace("/NOTIFICATION_DEFAULT_TIER=.*/", "NOTIFICATION_DEFAULT_TIER=$notification_default_tier", $contents);
    } else {
      // Config written before this setting existed - append the new key
      $contents .= "\nNOTIFICATION_DEFAULT_TIER=$notification_default_tier\n";
    }
  }

  if($site_name != $config["SITE_NAME"] || $color_scheme != $config["COLOR_SCHEME"]) {
    echo "<script>setTimeout(
    function() {
      window.parent.document.location.reload();
    }, 1000);</script>";

    shell_exec("sudo -n /usr/local/sbin/birdsuite-svc restart chart_viewer.service");
    // the sleep allows for the service to restart and image to be generated
    sleep(5);
  }

  // BirdDB-Br (Basic Settings, below BirdWeather — owner 2026-10-08)
  // US-40: station-side transport to the central sound repository (rclone remote + upload interval)
  if(isset($_GET['sound_repo_upload_minutes'])) {
    $sound_repo_upload_minutes = is_numeric($_GET['sound_repo_upload_minutes']) ? max(0, min(1440, intval($_GET['sound_repo_upload_minutes']))) : 5;
    $sound_repo_remote = isset($_GET['sound_repo_remote']) ? trim($_GET['sound_repo_remote']) : '';
    // remote:path — no blanks, quotes, backticks, $ or backslashes (the value is written into BirdSuite.conf;
    // the old class ended in an escaped ']' and never compiled, so every remote was dropped)
    if(!preg_match('/^[A-Za-z0-9_-]+:[^\s"\'`$\\\\]*$/', $sound_repo_remote)) { $sound_repo_remote = ''; }
  }
  // BirdDB-Br switch (default off): the spool, the upload and the clean-up all follow it.
  // Only the Settings form carries the checkbox (it always sends birdweather_id).
  if (isset($_GET['birdweather_id'])) {
    // BirdWeather: one token for every location, or a token for each location (owner 2026-10-22: a BirdWeather station
    // has fixed coordinates); the tokens of the locations are kept in locations.json ('birdweather_id')
    $bw_per_loc = (($_GET['birdweather_mode'] ?? '') === 'location') ? 1 : 0;
    if(preg_match("/^BIRDWEATHER_PER_LOCATION=/m", $contents)) {
      $contents = preg_replace("/^BIRDWEATHER_PER_LOCATION=.*/m", "BIRDWEATHER_PER_LOCATION=$bw_per_loc", $contents);
    } else {
      $contents .= "\n## BIRDWEATHER_PER_LOCATION: 1 = each location uploads with its own token (Settings › Repositories), 0 = BIRDWEATHER_ID for every location\nBIRDWEATHER_PER_LOCATION=$bw_per_loc\n";
    }
    if (isset($_GET['bw_loc']) && is_array($_GET['bw_loc'])) {
      $bw_list = locations_load();
      foreach ($bw_list as &$bw_l) {
        if (!array_key_exists((string)$bw_l['id'], $_GET['bw_loc'])) continue;
        $bw_t = trim((string)$_GET['bw_loc'][(string)$bw_l['id']]);
        if ($bw_t === '' || preg_match('/^[A-Za-z0-9_-]{4,64}$/', $bw_t)) $bw_l['birdweather_id'] = $bw_t;
      }
      unset($bw_l);
      locations_save($bw_list);
    }
    // only when the form shows the BirdDB-Br section (hidden for now: a form without it keeps the setting)
    if (isset($_GET['birddb_shown'])) {
    $birddb_enabled = isset($_GET['birddb_enabled']) ? 1 : 0;
    if(preg_match("/^BIRDDB_ENABLED=/m", $contents)) {
      $contents = preg_replace("/^BIRDDB_ENABLED=.*/m", "BIRDDB_ENABLED=$birddb_enabled", $contents);
    } else {
      $contents .= "\n## BIRDDB_ENABLED: 1 = contribute this station's clips to BirdDB-Br (sound repository), 0 = off (default)\nBIRDDB_ENABLED=$birddb_enabled\n";
    }
    }
  }
  if(isset($sound_repo_upload_minutes)) {
    foreach (array('SOUND_REPO_REMOTE' => array("\"$sound_repo_remote\"", 'rclone destination of the central sound repository (remote:path; empty = deposits stay in SOUND_REPO_PATH)'),
                   'SOUND_REPO_UPLOAD_MINUTES' => array($sound_repo_upload_minutes, 'interval in minutes between uploads of SOUND_REPO_PATH to SOUND_REPO_REMOTE (0 = never)')) as $key => $pair) {
      list($val, $desc) = $pair;
      if(preg_match("/^$key=/m", $contents)) {
        $contents = preg_replace("/^$key=.*/m", "$key=$val", $contents);
      } else {
        $contents .= "\n## $key is the $desc\n$key=$val\n";
      }
    }
  }
  $fh = fopen(BS_CONF, "w");
  fwrite($fh, $contents);
  fclose($fh);
  if (isset($_GET['ui_lang']) || $language != ($config['DATABASE_LANG'] ?? '')) {
    station_numeric_sync(isset($_GET['ui_lang']) ? $ui_lang : (string)($config['UI_LANG'] ?? ''), (string)$language);
  }
  // the location of these coordinates gets the name typed beside them (owner 2026-10-20)
  if (isset($_GET['location_name']) && is_numeric($latitude) && is_numeric($longitude)) location_set_named($latitude, $longitude, $_GET['location_name']);
  // another species list: select_species_list.py builds a state list once and links the active list to it
  if (isset($species_list) && $species_list !== ($config['SPECIES_LIST'] ?? '')) {
    syslog_shell_exec("python3 ".escapeshellarg($home."/BirdNET-Pi/scripts/select_species_list.py"), $user);
  }
  // settings saved here answer the first-run questions too: stop opening the Overview on the wizard
  if (file_exists($home.'/BirdNET-Pi/state/firstrun_pending')) { @unlink($home.'/BirdNET-Pi/state/firstrun_pending'); }

  if(isset($apprise_input)){
    $appriseconfig = fopen($home."/BirdNET-Pi/config/notifications/apprise.txt", "w");
    fwrite($appriseconfig, $apprise_input);
    $apprise_config = $apprise_input;
  }
  if(isset($apprise_input_rare)){
    $appriseconfigrare = fopen($home."/BirdNET-Pi/config/notifications/apprise-rare.txt", "w");
    fwrite($appriseconfigrare, $apprise_input_rare);
    $apprise_config_rare = $apprise_input_rare;
  }
  if(isset($apprise_notification_body)){
    $apprisebody = fopen($home."/BirdNET-Pi/config/notifications/body.txt", "w");
    fwrite($apprisebody, $apprise_notification_body);
  }
  if(isset($apprise_notification_body_rare)){
    $apprisebodyrare = fopen($home."/BirdNET-Pi/config/notifications/body-rare.txt", "w");
    fwrite($apprisebodyrare, $apprise_notification_body_rare);
  }
  if ($model != $config['MODEL'] || $language != $config['DATABASE_LANG']){
    if(strlen($language) == 2 || strlen($language) == 5){
      syslog_shell_exec("$home/BirdNET-Pi/scripts/install_language_label.sh", $user);
      syslog(LOG_INFO, "Successfully changed language to '$language' and model to '$model'");
      // a new Common name Language renames the species in the database, the clips and the lists (owner 2026-10-24:
      // the species is its scientific name, the common name can change at any time); progress on Status
      if ($language != $config['DATABASE_LANG']) {
        names_migration_recheck();
        names_migration_start();
      }
    }
  }
  syslog(LOG_INFO, "Restarting Services");
  shell_exec(station_prefix() . "restart_services.sh");

  // the saved form opens again by GET with the "updated" alert (post/redirect/get: a reload does not send it twice);
  // the menu is already printed, so the browser is sent there by script
  $back = json_encode('views.php?view=' . (in_array($_GET['view'] ?? '', array('Model', 'NotifyRules'), true) ? $_GET['view'] : 'Settings') . '&status=success');
  if (!empty($restart_php)) {
    echo "<script>setTimeout(function() {
      var x = new XMLHttpRequest();
      x.open('POST', 'scripts/config.php', true);
      x.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
      x.onloadend = function() { setTimeout(function() { location.replace($back); }, 1500); };
      x.send('restart_php=true');
    }, 1000);</script>";
  } else {
    echo "<script>location.replace($back);</script>";
  }
  die();
}

if(isset($_GET['sendtest']) && $_GET['sendtest'] == "true") {
  $conf = $_GET['apprise_config'];
  $title = $_GET['apprise_notification_title'];
  $body = $_GET['apprise_notification_body'];

  $temp_conf = tmpfile();
  $t_conf_path = stream_get_meta_data($temp_conf)['uri'];
  chmod($t_conf_path, 0644);
  fwrite($temp_conf, $conf);

  $temp_body = tmpfile();
  $t_body_path = stream_get_meta_data($temp_body)['uri'];
  chmod($t_body_path, 0644);
  fwrite($temp_body, $body);

  $cmd = station_prefix() . "$home/BirdNET-Pi/birdnet/bin/python3 $home/BirdNET-Pi/scripts/send_test_notification.py --body $t_body_path --config $t_conf_path --title " . escapeshellarg($title) . " 2>&1";  // escapeshellarg (2026-10-22): escapeshellcmd inside quotes left \$comname in the title
  $ret = shell_exec($cmd);
  echo "<pre class=\"bash\">".$ret."</pre>";
  fclose($temp_conf);
  fclose($temp_body);

  die();
}

// have to get the config again after we change the variables, so the UI reflects the changes too
$config = get_config($force_reload=true);
?>
<meta name="viewport" content="width=device-width, initial-scale=1">
<style>
  </style>
<?php if (in_array($config_section ?? 'all', array('model', 'notifications', 'basic'), true)) { ?>
<style>
  /* only the sections of this menu entry, hidden before the first paint (2026-10-23 run 3: the script at the end of the
     form hid them after the whole form had been drawn, layout shift 0.74 on Notifications › Content); the script stays
     for browsers without :has() */
  #basicform > table.settingstable:not(:has(h2[data-sec="<?php echo $config_section; ?>"])),
  #basicform > table.settingstable:not(:has(h2[data-sec="<?php echo $config_section; ?>"])) + br { display: none; }
</style>
<?php } ?>
<div class="settings">
    <form id="basicform" action=""  method="POST">


<script>
  document.addEventListener('DOMContentLoaded', function() {
  document.getElementById('modelsel').addEventListener('change', function() {
    // the species range model belongs to the V2 models only (owner 2026-10-20)
    if(this.value == "BirdNET_GLOBAL_6K_V2.4_Model_FP16"){
      document.getElementById("soft").style.display="unset";
    } else {
      document.getElementById("soft").style.display="none";
    }
  });
}, false);
function sendTestNotification(e, which, msgspan, titlefield, bodyfield) {
  which = which || "apprise_input";
  msgspan = msgspan || "testsuccessmsg";
  document.getElementById(msgspan).innerHTML = "";
  e.classList.add("disabled");

  var apprise_notification_title = (titlefield && document.getElementsByName(titlefield)[0].value)
    || document.getElementsByName("apprise_notification_title")[0].value;
  var apprise_notification_body = encodeURIComponent((bodyfield && document.getElementsByName(bodyfield)[0].value)
    || document.getElementsByName("apprise_notification_body")[0].value);
  var apprise_config = encodeURIComponent(document.getElementsByName(which)[0].value);

  var xmlHttp = new XMLHttpRequest();
    xmlHttp.onreadystatechange = function() { 
        if (xmlHttp.readyState == 4 && xmlHttp.status == 200) {
            document.getElementById(msgspan).innerHTML = this.responseText + " " + t('Test sent!') + " " + t('Make sure to <b>Update Settings</b> below.')
            e.classList.remove("disabled");
        }
    }
    // POST (2026-10-23 test run): the notification URLs carry tokens, which a GET left in the web server log
    xmlHttp.open("POST", "scripts/config.php", true); // true for asynchronous
    xmlHttp.setRequestHeader("Content-Type", "application/x-www-form-urlencoded");
    xmlHttp.send("sendtest=true"+"&apprise_notification_body="+apprise_notification_body+"&apprise_config="+apprise_config+"&apprise_notification_title="+encodeURIComponent(apprise_notification_title));
}
</script>
      <?php // Common name Language first on the Model page (owner 2026-10-22) ?>
      <table class="settingstable"><tr><td>
      <h2 data-sec="model"><?php echo t('Common name Language'); ?></h2>
      <?php // the Interface language lives in the menu (flags), not here (owner 2026-10-21) ?>
      <p><?php echo t('Changing it renames the species in the database and the lists to the new language; the clips keep their scientific names (progress in Tools › Data).'); ?></p>
      <label for="language"><?php echo t('Common name Language:'); ?> </label>
      <select name="language" class="testbtn" onchange="cnlSave(this)">
      <?php
        $langs = array('not-selected' => 'Not Selected') + common_name_languages();

        // Create options for each language
        foreach($langs as $langTag => $langName){
          $isSelected = "";
          if($config['DATABASE_LANG'] == $langTag || ($langTag == 'pt_BR' && $config['DATABASE_LANG'] == 'pt')){
            $isSelected = 'selected="selected"';
          }

          echo "<option value='{$langTag}' $isSelected>" . t($langName) . "</option>";
        }
      ?>

      </select> <b id="cnlmsg" class="small"></b>
      <script>
      // saved on its own when changed (owner 2026-10-24); the rest of the page keeps its Save button
      function cnlSave(sel) {
        if (sel.value === 'not-selected') return;
        var m = document.getElementById('cnlmsg');
        m.textContent = t('Saving…');
        var x = new XMLHttpRequest();
        x.onload = function () {
          var r; try { r = JSON.parse(this.responseText); } catch (e) { r = {}; }
          m.textContent = r.ok ? t('Saved: the species are renamed in the background (progress in Tools › Data).') : (this.status === 401 ? t('Log in first.') : t('Not saved'));
        };
        x.onerror = function () { m.textContent = t('Not saved'); };
        x.open('POST', 'scripts/config.php', true);
        x.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
        x.send('set_lang=' + encodeURIComponent(sel.value));
      }
      </script>
      </td></tr></table>
      <br>

      <table class="settingstable"><tr><td>
      <?php // shown in Settings › Model with the species-name language (owner 2026-10-23); before, only the first-run set it ?>
      <h2 data-sec="model"><?php echo t('Species information site'); ?></h2>
      <label for="info_site"><?php echo t('Site the species information links open:'); ?> </label>
      <select name="info_site" id="info_site" class="testbtn">
      <?php
        $info_site = array(
          'ALLABOUTBIRDS' => 'allaboutbirds.org',
          "EBIRD" => "ebird.org"
        );
        // WikiAves only with CBRO names (Portuguese Brazil)
        if (($config['DATABASE_LANG'] ?? '') === 'pt_BR' || ($config['INFO_SITE'] ?? '') === 'WIKIAVES') {
          $info_site['WIKIAVES'] = t('wikiaves.com.br (Brazilian birds, CBRO names)');
        }

        // Create options for each site
        foreach($info_site as $infoTag => $infoName){
          $isSelected = "";
          if($config['INFO_SITE'] == $infoTag){
            $isSelected = 'selected="selected"';
          }

          echo "<option value='{$infoTag}' $isSelected>" . htmlspecialchars($infoName) . "</option>";
        }
      ?>

      </select>
      <p><?php echo t('The first set-up picks it by region: allaboutbirds.org in the US and Canada, wikiaves.com.br in Brazil, ebird.org elsewhere.'); ?>
</p>
      <?php // the details folded (owner 2026-10-24: as "Where do I find the name and password?" on Network) ?>
      <details class="small"><summary><?php echo t('More about the species links'); ?></summary>
      <?php echo t('wikiaves.com.br is offered with Portuguese Brazil (CBRO) names; a species not on WikiAves opens eBird.'); ?>
      <br><?php echo t('The icons next to the name on a species page always link to WikiAves (CBRO names only), eBird, Birds of the World and Wikipedia.'); ?></details>
      </td></tr></table><br>
      <table class="settingstable"><tr><td>
      <h2 data-sec="model"><?php echo t('Models'); ?></h2>
      <?php
      // US-47: official and shadow model side by side, each with its own minimum confidence,
      // sigmoid sensitivity and location threshold; the overlap is shared. Config keys unchanged.
      $models = array("BirdNET-Plus_V3.0-preview3.1_Global_10K", "BirdNET_GLOBAL_6K_V2.4_Model_FP16", "BirdNET_6K_GLOBAL_MODEL");
      ?>
      <?php // owner's layout (2026-10-20): the table (official, Swap, shadow) with the overlap as its last column; the
            // species range model only with the V2.4 model; shadow mode and avoid duplicity; the models; the thresholds note ?>
      <table class="modelstable">
        <tr><th></th><th><?php echo t('Model'); ?></th><th><?php echo t('Min. confidence'); ?><br><small>[<?php echo t('{min} to {max}', array('min' => fmt_setting('0.01'), 'max' => fmt_setting('0.99'))); ?>]</small></th><th><?php echo t('Sensitivity'); ?><br><small>[<?php echo t('{min} to {max}', array('min' => fmt_setting('0.5'), 'max' => fmt_setting('1.5'))); ?>]</small></th><th><?php echo t('Min. Prob.'); ?><br><small>[<?php echo t('{min} to {max}', array('min' => fmt_setting('0.0005'), 'max' => fmt_setting('0.99'))); ?>]</small></th><th><?php echo t('Overlap'); ?><br><small>[<?php echo t('{min} to {max}', array('min' => fmt_setting('0.0'), 'max' => fmt_setting('2.9'))); ?>]</small></th></tr>
        <tr>
          <td><b><?php echo t('Official'); ?></b></td>
          <td><select id="modelsel" name="model" class="testbtn">
      <?php
      foreach($models as $modelName){
          $isSelected = ($config['MODEL'] == $modelName) ? 'selected="selected"' : '';
          echo "<option value='{$modelName}' $isSelected>$modelName</option>";
        }
      ?>
          </select></td>
          <td><input name="confidence" type="number" style="width:5em;" min="0.01" max="0.99" step="0.01" value="<?php print($config['CONFIDENCE']);?>"/></td>
          <td><input name="sensitivity" type="number" style="width:5em;" min="0.5" max="1.5" step="0.01" value="<?php print($config['SENSITIVITY']);?>"/></td>
          <td><input name="sf_thresh" type="number" style="width:5em;" max="0.99" min="0.0005" step="any" value="<?php print($config['SF_THRESH']);?>"/></td>
          <td><input name="overlap" type="number" style="width:5em;" min="0.0" max="2.9" step="0.1" value="<?php print($config['OVERLAP']);?>" title="<?php echo t('Shared: the recording is cut once, both models analyse the same 3 s windows'); ?>"/> s</td>
        </tr>
        <tr>
          <td></td>
          <td colspan="5"><button type="submit" name="swap_models" value="1" class="stdbtn" <?php if (($config['SHADOW_MODEL_NAME'] ?? '') === '') echo 'disabled title="' . t('Choose a shadow model first') . '"'; ?>
            onclick="return confirm(t('Swap the official and the shadow model (each keeps its own minimum confidence, sensitivity and minimum probability of occurrence at the location)?') + '\n' + t('Services restart.'));"><?php echo t('Swap Models'); ?></button></td>
        </tr>
        <tr id="shadowrow" class="<?php if((string)($config['SHADOW_ENABLED'] ?? '0') === '0') echo 'shadow-off'; ?>">
          <td><b><?php echo t('Shadow'); ?></b></td>
          <td><select name="shadow_model" class="testbtn">
        <option value=""><?php echo t('None'); ?></option>
      <?php
      foreach($models as $modelName){
          $isSelected = (($config['SHADOW_MODEL_NAME'] ?? '') == $modelName) ? 'selected="selected"' : '';
          echo "<option value='{$modelName}' $isSelected>$modelName</option>";
        }
      ?>
          </select></td>
          <td><input name="shadow_min_conf" type="number" style="width:5em;" max="0.99" min="0.01" step="0.01" value="<?php print($config['SHADOW_MIN_CONF'] ?? '0.35');?>"/></td>
          <td><input name="shadow_sens" type="number" style="width:5em;" min="0.5" max="1.5" step="0.01" value="<?php print($config['SHADOW_SENS'] ?? '1.0');?>"/></td>
          <td><input name="shadow_geo_thresh" type="number" style="width:5em;" max="0.99" min="0.0005" step="any" value="<?php print($config['SHADOW_GEO_THRESH'] ?? $config['SF_THRESH']);?>"/></td>
          <td></td><?php // the overlap is shared with the official model (owner 2026-10-21: no "same" label) ?>
        </tr>
      </table>
      <br>
      <?php // the species range model and its threshold belong to the V2 models only ?>
      <span <?php if($config['MODEL'] !== "BirdNET_GLOBAL_6K_V2.4_Model_FP16") { ?>style="display: none"<?php } ?> id="soft">
      <input type="checkbox" name="data_model_version" <?php if($config['DATA_MODEL_VERSION'] == 2) { echo "checked"; };?> >
      <label for="data_model_version"><?php echo t('Species range model'); ?> V2.4 - V2</label>  [ <a target="_blank" href="https://github.com/kahst/BirdNET-Analyzer/discussions/234"><?php echo t('Info here'); ?></a> ]<br>
      &nbsp;&nbsp;&nbsp;&nbsp;<label><?php echo t('Minimum probability of occurrence at the location = the "Min. Prob." column of the Models table above.'); ?></label> <span onclick="document.getElementById('sfhelp').style.display='unset'" style="text-decoration:underline;cursor:pointer">[<?php echo t('more info'); ?>]</span>
      <p id="sfhelp" style='display:none'><?php echo t('This value is used by the model to constrain the list of possible species that it will try to detect, given the minimum occurrence frequency.'); ?>
        <?php echo t('A minimum probability of occurrence at the location of 0.03 means that for a species to be included in this list, it needs to, on average, be seen on at least 3% of historically submitted eBird checklists for your given lat/lon/current week of year.'); ?>
        <?php echo t('So, the lower the minimum probability of occurrence at the location, the rarer the species it will include.'); ?><br><img style='max-width:100%;padding-top:5px;padding-bottom:5px' alt="<?php echo t('BirdSuite new model detection flowchart'); ?>" title="<?php echo t('BirdSuite new model detection flowchart'); ?>" src="images/BirdNET-Pi_nm_flowchart.alpha.png">
        <br><?php if($config['MODEL'] == "BirdNET_6K_GLOBAL_MODEL"){ ?><?php echo t('If you\'d like to tinker with this minimum probability of occurrence at the location and see which species make it onto the list, please click "Update Settings" at the very bottom of this page to install the appropriate label file, then come back here and you\'ll be able to use the Species List Tester.'); ?><?php } else { ?><?php echo t('If you\'d like to tinker with this minimum probability of occurrence at the location and see which species make it onto the list, you can use this tool:'); ?> <button type="button" class="testbtn" id="openModal"><?php echo t('Species List Tester'); ?></button><?php } ?></p>
      <br></span>
      <?php // Shadow mode and Avoid duplicity in one line each (owner 2026-10-21) ?>
      <p><b><?php echo t('Shadow mode'); ?></b> &nbsp;<label style="font-weight:normal"><input type="checkbox" id="shadow_enabled" name="shadow_enabled" value="1" onchange="document.getElementById('shadowrow').classList.toggle('shadow-off', !this.checked)" <?php if((string)($config['SHADOW_ENABLED'] ?? '0') !== '0') echo 'checked'; ?>> <?php echo t('Enables two parallel models to run simultaneously - use the <a href="views.php?view=Models">model comparison</a> in the Shadow menu to evaluate.'); ?></label></p>
      <p id="shadowhelp" style='display:none'><?php echo t('A shadow model analyses every recording right after the model selected above and writes what it would have detected to a database of its own (<code>scripts/birds_shadow.db</code>).'); ?>
        <?php echo t('It never extracts audio, never notifies and never touches the detections of the station, so a new model can be compared with the current one for weeks before switching.'); ?>
        <?php echo t('<b>BirdNET-Plus_V3.0-preview3.1_Global_10K</b> is the developer preview model of the BirdNET Live app (32 kHz, about 10,000 classes including amphibians, mammals and insects, its own location filter).'); ?>
        <?php echo t('It needs ONNX Runtime and is downloaded (about 80 MB) the first time it is used.'); ?>
        <?php echo t('It has no human voice class yet, so the privacy filter does not work while it is the selected model.'); ?></p>
      <p><b><?php echo t('Avoid duplicity'); ?></b> &nbsp;<label style="font-weight:normal"><input type="checkbox" name="avoid_duplicity" value="1" <?php if((string)($config['AVOID_DUPLICITY'] ?? '0') === '1') echo 'checked'; ?>> <?php echo t('One species per recording; in each {len} s recording, only the species with the highest confidence is considered.', array('len' => intval($config['RECORDING_LENGTH'] ?? 15) ?: 15)); ?></label></p>
      <?php // from Settings › Advanced (owner 2026-10-25) ?>
      <?php // BirdNET+ V3 has no human class (owner 2026-10-25): the filter works only through a shadow model V2.4 that has one
      $pv_v3 = strpos($config['MODEL'] ?? '', 'BirdNET-Plus') === 0;
      $pv_shadow = (string)($config['SHADOW_ENABLED'] ?? '0') !== '0' && strpos($config['SHADOW_MODEL_NAME'] ?? '', 'BirdNET-Plus') !== 0 && ($config['SHADOW_MODEL_NAME'] ?? '') !== '';
      $pv_off = $pv_v3 && !$pv_shadow; ?>
      <p><b><?php echo t('Privacy Threshold'); ?></b> &nbsp;<input type="number" name="privacy_threshold" min="0" max="3" step="1" style="width:4em<?php echo $pv_off ? ';opacity:.5' : ''; ?>" value="<?php echo intval($config['PRIVACY_THRESHOLD'] ?? 1); ?>"> %
        <?php // one sentence per line, the owner's wording (2026-10-26) ?>
        <?php echo t('A recording where a human voice is among the top N% of the predictions is dropped.'); ?>
        <?php // the lines under it indented as the model list below (2em, owner 2026-10-26) ?>
        <span style="display:block;padding-left:2em"><?php echo t('0 = off, start with 1.'); ?>
        <?php if ($pv_off) { ?><br><span style="color:#c62828"><?php echo t('The BirdNET+ V3 model has no human voice class!'); ?></span>
          <br><small><?php echo t('For privacy with V3.0, turn on Shadow mode with BirdNET V2.4.'); ?><br><?php echo t('V2.4 has human voice detection and then drops those moments of the official detections too.'); ?><br><?php echo t('The Pi\'s processing load goes up: test it first (two models on every recording).'); ?></small>
        <?php } elseif ($pv_v3) { ?><br><small style="color:#1b5e20"><?php echo t('With BirdNET+ V3 it works through the shadow model (on): its human voice detection drops those moments of the official detections too.'); ?></small><?php } ?></span></p>

<script src="static/dialog-polyfill.js"></script>

<dialog id="modal">
  <div>
    <label for="threshold"><?php echo t('Min. probability:'); ?></label>
    <input type="number" id="threshold" step="0.01" min="0" max="1" value="">
    <button type="button" id="runProcess"><?php echo t('Preview Species List'); ?></button>
  </div>
  <pre id="output"></pre>
  <button type="button" id="closeModal"><?php echo t('Close'); ?></button>
</dialog>

<style>
#output {
  max-width: 100vw;
  word-wrap: break-word;
  white-space: pre-wrap;
}
#modal {
  max-height: 80vh;
  overflow-y: auto;
}
#modal div {
  display: flex;
  align-items: center;
}

#modal input[type="number"] {
  height: 32px;
}

#modal button {
  height: 32px;
  margin-left: 5px;
  padding: 0 10px;
  box-sizing: border-box;
}
</style>


<script>
// Get the button and modal elements
const openModalBtn = document.getElementById('openModal');
const modal = document.getElementById('modal');
dialogPolyfill.registerDialog(modal);
const output = document.getElementById('output');
const thresholdInput = document.getElementById('threshold');
const runProcessBtn = document.getElementById('runProcess');
const sfThreshInput = document.getElementsByName('sf_thresh')[0];
const closeModalBtn = document.getElementById('closeModal');


// Add an event listener to the button to open the modal
openModalBtn.addEventListener('click', () => {

  // Set the initial value of the threshold input element
  thresholdInput.value = sfThreshInput.value;

// Show the modal
  modal.showModal();
});

// Add an event listener to the "Preview Species List" button
runProcessBtn.addEventListener('click', () => {

  runProcess();
});

// Add an event listener to the "Close" button
closeModalBtn.addEventListener('click', () => {
  modal.close();
});

// Function to run the process
function runProcess() {
  // Get the value of the threshold input element
  const threshold = thresholdInput.value;

 // Set the output to "Loading..."
  output.innerHTML = t('Loading...');

  // Make the AJAX request
  const xhr = new XMLHttpRequest();
  xhr.onreadystatechange = function() {
    if (xhr.readyState === XMLHttpRequest.DONE) {
      // Handle the response
      output.innerHTML = xhr.responseText;
    }
  };
  xhr.open('GET', `scripts/config.php?threshold=${threshold}`);
  xhr.send();
}
</script>

      <div style="font-size:.85em;margin:14px 0">
        <?php // the defaults of each model and of its variants as a table (owner 2026-10-22): the BirdSuite factory values
          // (factory_defaults.conf), Cornell's own (BirdNET+ V3 analyze / web demo, the BirdNET Live app, BirdNET-Analyzer) and
          // the original BirdNET-Pi project's; '-' = the tool has no such setting
          $bd = birdsuite_defaults();
          $defrows = array(
            array('BirdNET-Plus_V3.0-preview3.1_Global_10K', t('BirdSuite (factory)'), $bd['CONFIDENCE'] ?? '-', isset($bd['SENSITIVITY']) && is_numeric($bd['SENSITIVITY']) ? number_format((float)$bd['SENSITIVITY'], max(1, strlen(substr(strrchr($bd['SENSITIVITY'], '.') ?: '', 1)))) : '-', $bd['SF_THRESH'] ?? '-', $bd['OVERLAP'] ?? '-'),
            array('BirdNET-Plus_V3.0-preview3.1_Global_10K', t('Cornell (BirdNET Live app)'), '0.5', '1.0', '0.03', '0'),
            array('BirdNET-Plus_V3.0-preview3.1_Global_10K', t('Cornell (analysis script)'), '0.25', '-', '-', '0'),
            array('BirdNET_GLOBAL_6K_V2.4_Model_FP16', t('Cornell (BirdNET-Analyzer)'), '0.25', '1.0', '0.03', '0'),
            array('BirdNET_GLOBAL_6K_V2.4_Model_FP16', t('original project (BirdNET-Pi)'), '0.7', '1.25', '0.03', '0'),
            array('BirdNET_6K_GLOBAL_MODEL', t('legacy 6K model'), '0.7', '1.25', '-', '0'),
          ); ?>
        <table class="stdtable" style="width:auto;margin-top:6px">
          <tr><th><?php echo t('Model'); ?></th><th><?php echo t('Defaults of'); ?></th><th class="num"><?php echo t('Min. Conf.'); ?></th><th class="num"><?php echo t('Sigm. Sens.'); ?></th><th class="num"><?php echo t('Min. Prob.'); ?></th><th class="num"><?php echo t('Overlap'); ?></th><th data-nosort></th></tr>
          <?php // Use: these values into the official model's fields (saved with the form), only for the model selected above
          // a thicker line between the model generations (V3, V2.4, 6K), owner 2026-10-22
          $prevm = null;
          foreach ($defrows as $r) { $gen = $prevm !== null && $prevm !== $r[0] ? ' class="gensep"' : ''; $prevm = $r[0]; echo '<tr' . $gen . '><td>' . htmlspecialchars(str_replace('_', ' ', preg_replace('/_Model_FP16|_Global_10K|-preview3\.1/', '', $r[0]))) . '</td><td>' . htmlspecialchars($r[1]) . '</td><td class="num">' . htmlspecialchars(fmt_setting($r[2])) . '</td><td class="num">' . htmlspecialchars(fmt_setting($r[3])) . '</td><td class="num">' . htmlspecialchars(fmt_setting($r[4])) . '</td><td class="num">' . htmlspecialchars($r[5] === '-' ? '-' : fmt_setting($r[5]) . ' s') . '</td>'
            . '<td><button type="button" class="stdbtn defuse" data-model="' . htmlspecialchars($r[0]) . '" data-v="' . htmlspecialchars(json_encode(array_slice($r, 2, 4))) . '" onclick="defUse(this)">' . t('Use') . '</button></td></tr>'; } ?>
        </table>
        <div class="small" id="defusemsg" style="font-weight:600;margin-top:4px"></div>
        <style>.defuse:disabled { opacity: .4; cursor: default; } tr.gensep td { border-top: 3px solid rgba(0,0,0,.35) !important; } .modellist span b { font-size: inherit; }
          /* what each value does, in the page's normal size throughout (owner 2026-10-22: no small lines between) */
          .paramhelp { font-size: calc(1em / 0.85); } .paramhelp span { font-size: inherit !important; }</style>
        <script>
        // a row's values into the official model's fields; the button only for the model selected above (owner 2026-10-22)
        function defRefresh() {
          var m = document.getElementById('modelsel').value;
          document.querySelectorAll('.defuse').forEach(function (b) { b.disabled = b.dataset.model !== m; b.title = b.disabled ? t('Only for the model selected above') : ''; });
        }
        function defUse(b) {
          if (b.disabled) return;
          var v = JSON.parse(b.dataset.v), names = ['confidence', 'sensitivity', 'sf_thresh', 'overlap'];
          names.forEach(function (n, i) {
            var f = document.querySelector('input[name="' + n + '"]');
            if (f && v[i] !== '-') { f.value = v[i]; f.dispatchEvent(new Event('input', {bubbles: true})); f.dispatchEvent(new Event('change', {bubbles: true})); }
          });
          document.getElementById('defusemsg').textContent = t('Values filled in above: save the settings to apply them.');
        }
        document.getElementById('modelsel').addEventListener('change', defRefresh);
        defRefresh();
        </script>
        <p style="margin:8px 0 0"><b><?php echo t('Thresholds are NOT comparable between generations.'); ?></b></p>
        <?php // what changing each value does (owner 2026-10-22), right under the table ?>
        <div class="modellist paramhelp" style="margin-top:8px">
          <p><b><?php echo t('What changing each value does:'); ?></b></p>
          <p><b><?php echo t('Min. Conf.'); ?></b><br><span><?php echo t('Higher: fewer detections and fewer false positives, but quiet or distant birds are missed.'); ?><br><?php echo t('Lower: more detections, and more errors to review.'); ?></span></p>
          <p><b><?php echo t('Sigm. Sens.'); ?></b><br><span><?php echo t('Higher: uncertain sounds get a higher confidence, so more of them pass Min. Conf. (more detections, more false positives).'); ?><br><?php echo t('Lower: only clear songs pass.'); ?></span></p>
          <p><b><?php echo t('Min. Prob.'); ?></b><br><span><?php echo t('Higher: a shorter species list for the place and the week, fewer species that do not occur here, but vagrants and out-of-season species are cut.'); ?><br><?php echo t('Lower: a longer list, vagrants pass, and more errors from unlikely species.'); ?></span></p>
          <p><b><?php echo t('Overlap'); ?></b><br><span><?php echo t('The 3-second analysis windows overlap by this many seconds.'); ?><br><?php echo t('More: more windows per recording, short calls caught more often, the same song counted more times, more processor load.'); ?><br><?php echo t('0: the fastest, but a call split between two windows can be missed.'); ?></span></p>
        </div>
        <?php echo t('Calibrate from a shadow period before judging false positives.'); ?></div>
      <p><b><?php echo t('Models:'); ?></b></p>
      <div class="modellist">
        <p><b>BirdNET-Plus_V3.0-preview3.1_Global_10K (2026)</b><br>
          <span><?php echo t('Developer preview of the next BirdNET generation, the model of the BirdNET Live app.'); ?><br><?php echo t('Try it as a shadow model first.'); ?></span></p>
        <p><b>BirdNET_GLOBAL_6K_V2.4_Model_FP16 (2023)</b><br>
          <span><?php echo t('This is the BirdNET-Analyzer model, the most advanced BirdNET model to date.'); ?><br><?php echo t('Currently it supports over 6,000 species worldwide, giving quite good species coverage for people in most of the world.'); ?></span></p>
        <p><b>BirdNET_6K_GLOBAL_MODEL (2020)</b><br>
          <span><?php echo t('This is the BirdNET-Lite model, with bird sound recognition for more than 6,000 species worldwide.'); ?><br>
          <?php echo t('This has generally worse performance than the newer models but is kept as a legacy option.'); ?></span></p>
        <p><?php echo t('In-depth technical write-up on the models: <a target="_blank" href="https://github.com/mcguirepr89/BirdNET-Pi/wiki/BirdNET-Pi:-some-theory-on-classification-&-some-practical-hints">here</a>'); ?></p>
      </div>
      <style>.modellist p { margin: 0 0 10px; } .modellist span { display: inline-block; padding-left: 2em; font-size: .85em; }</style>

      </td></tr></table><br>

      <table class="settingstable"><tr><td>
      <h2 data-sec="hidden">Location</h2>
      <table class="settingstable plaintable">
        <tr>
          <td><label for="site_name">Station name:</label></td>
          <td><input name="site_name" type="text" value="<?php print($config['SITE_NAME']);?>"/></td>
          <td>(Optional)</td>
        </tr>
        <?php $loc_here = location_current(); ?>
        <tr class="locfield" title="The name of the place at these coordinates (Settings › Locations); shown beside the station name">
          <td><label for="location_name">Location name:</label></td>
          <td><input name="location_name" id="location_name" type="text" maxlength="60" value="<?php echo htmlspecialchars($loc_here ? $loc_here['name'] : ''); ?>"/></td>
          <td><small>e.g. Garden, Farm</small></td>
        </tr>
        <!-- boot location check (owner 2026-10-10): beside the coordinates it compares with -->
        <tr class="locfield" title="At every boot the station compares its approximate network position (public IP geolocation: ipapi.co / ip-api.com) with these coordinates; after a move of more than the move distance the Latest page asks whether to use the new location (coordinates, Brazilian state species list, timezone). Nothing changes without your answer.">
          <td><label for="location_check">Auto location:</label></td>
          <td><label><input type="checkbox" name="location_check" id="location_check" <?php echo ($config['LOCATION_CHECK'] ?? '1') !== '0' ? 'checked' : ''; ?>> Enable</label></td>
          <td><small>at every boot, compare the network position with these coordinates and ask when the station moved</small></td>
        </tr>
        <tr class="locfield">
          <td></td>
          <td colspan="2"><button type="button" class="autolocate" onclick="autoLocateNow(this)" title="Find the station's network position now and put it in Latitude / Longitude (save to keep it)">Auto Locate Now</button>
            <small id="autolocate_msg"></small></td>
        </tr>
        <tr class="locfield"><td colspan="3"><small>Get coordinates with <b>Auto Locate Now</b> or on <a href="https://latlong.net" target="_blank">latlong.net</a> (4 decimal places); detections are stamped with the timezone below.</small></td></tr>
        <tr class="locfield">
          <td><label for="latitude">Latitude:</label></td>
          <td><input name="latitude" type="number" style="width:6em;" max="90" min="-90" step="0.0001" value="<?php print($config['LATITUDE']);?>" required/></td>
        </tr>
        <tr class="locfield">
          <td><label for="longitude">Longitude: </label></td>
          <td><input name="longitude" type="number" style="width:6em;" max="180" min="-180" step="0.0001" value="<?php print($config['LONGITUDE']);?>" required/></td>
          <td><button type="button" class="autolocate" id="lp_btn" title="Pick the place on a map: a click (or dragging the pin) fills latitude and longitude">&#128506; Map</button></td>
        </tr>
        <tr class="locfield"><td colspan="3" id="lp_box"></td></tr>
        <script src="static/species-modal.js?v=<?php echo @filemtime(__DIR__ . '/../homepage/static/species-modal.js'); ?>"></script>
        <script src="static/location-picker.js?v=<?php echo @filemtime(__DIR__ . '/../homepage/static/location-picker.js'); ?>"></script>
        <script>locationPicker(window.bsLocPicker = {lat: document.querySelector('input[name=latitude]'), lon: document.querySelector('input[name=longitude]'),
          button: document.getElementById('lp_btn'), box: document.getElementById('lp_box'), home: [<?php echo floatval($config['LATITUDE']) . ', ' . floatval($config['LONGITUDE']); ?>]});</script>
        <tr>
          <td><label for="timezone">Timezone:</label></td>
          <td colspan="2"><select name="timezone" id="timezone" class="testbtn">
            <?php $current_timezone = trim((string)shell_exec("timedatectl show --value --property=Timezone"));
              foreach (DateTimeZone::listIdentifiers(DateTimeZone::ALL) as $tzid) {
                echo '<option value="' . $tzid . '"' . ($tzid === $current_timezone ? ' selected="selected"' : '') . '>' . $tzid . '</option>';
              } ?>
          </select></td>
        </tr>
        <tr><td colspan="3" style="height:12px"></td></tr>
        <tr>
          <td><label for="species_list">Species list filter:</label></td>
          <td><select name="species_list" id="species_list">
            <?php
            $cur_list = $config['SPECIES_LIST'] ?? '';
            echo "<option value=''" . ($cur_list === '' ? ' selected' : '') . ">None: use the model's species distribution</option>";
            $own = array();
            foreach (glob($home."/BirdNET-Pi/config/species_lists/custom/*.txt") as $f) { $own[] = basename($f, '.txt'); }
            sort($own);
            $br_states = ['AC' => 'Acre', 'AL' => 'Alagoas', 'AP' => 'Amapá', 'AM' => 'Amazonas', 'BA' => 'Bahia', 'CE' => 'Ceará',
                          'DF' => 'Distrito Federal', 'ES' => 'Espírito Santo', 'GO' => 'Goiás', 'MA' => 'Maranhão', 'MT' => 'Mato Grosso',
                          'MS' => 'Mato Grosso do Sul', 'MG' => 'Minas Gerais', 'PA' => 'Pará', 'PB' => 'Paraíba', 'PR' => 'Paraná',
                          'PE' => 'Pernambuco', 'PI' => 'Piauí', 'RJ' => 'Rio de Janeiro', 'RN' => 'Rio Grande do Norte',
                          'RS' => 'Rio Grande do Sul', 'RO' => 'Rondônia', 'RR' => 'Roraima', 'SC' => 'Santa Catarina', 'SP' => 'São Paulo',
                          'SE' => 'Sergipe', 'TO' => 'Tocantins'];
            if ($own) {
              echo "<optgroup label='" . htmlspecialchars(t('Station lists (species_lists/custom/)'), ENT_QUOTES) . "'>";
              foreach ($own as $n) {
                $label = (strpos($n, 'BR-') === 0 && isset($br_states[substr($n, 3)])) ? "$n: " . $br_states[substr($n, 3)] : $n;
                echo "<option value='" . htmlspecialchars($n, ENT_QUOTES) . "'" . ($cur_list === $n ? ' selected' : '') . ">" . htmlspecialchars($label) . "</option>";
              }
              echo "</optgroup>";
            }
            echo "<optgroup label='Brazilian states (WikiAves records, CBRO names)'>";
            foreach ($br_states as $uf => $uf_name) {
              $region = "BR-$uf";
              if (in_array($region, $own, true) || !is_file($home."/BirdNET-Pi/model/include_lists/$region.txt")) continue;
              echo "<option value='$region'" . ($cur_list === $region ? ' selected' : '') . ">$uf_name ($uf)</option>";
            }
            echo "</optgroup>";
            ?>
          </select></td>
          <td></td>
        </tr>
        <tr>
          <td></td>
          <td colspan="2"><small>
            <b>None</b>: every species the model's location filter allows for this place and week; use it outside Brazil or to detect species not yet registered in your area.<br>
            <b>Station list</b>: only the species of that list; edit, save and load lists in Lists › Custom Species.<br>
            <b>Brazilian state</b>: only the birds with WikiAves records in the state (CBRO names) plus the model's non-bird classes; built once, then kept as a station list you can edit.
          </small></td>
        </tr>
      </table>
<script>
// Auto Locate Now: the network position into the Latitude / Longitude fields of this form (save to keep it)
function autoLocateNow(btn) {
  // the device's GPS first (owner 2026-10-29, as the map's GPS icon); the network position when no GPS position came
  if (window.bsLocPicker && window.bsLocPicker.gps) { window.bsLocPicker.gps(function () { autoLocateNet(btn); }); return; }
  autoLocateNet(btn);
}
function autoLocateNet(btn) {
  var msg = document.getElementById('autolocate_msg');
  msg.textContent = 'Locating\u2026';
  btn.disabled = true;
  var x = new XMLHttpRequest();
  x.onload = function () {
    btn.disabled = false;
    var r; try { r = JSON.parse(this.responseText); } catch (e) { r = {error: this.status === 401 ? 'log in first' : 'no answer'}; }
    if (r.error) { msg.textContent = 'Not located: ' + r.error; return; }
    var lat = document.querySelector('input[name=latitude]'), lon = document.querySelector('input[name=longitude]');
    lat.value = Number(r.lat).toFixed(4); lon.value = Number(r.lon).toFixed(4);
    [lat, lon].forEach(function (i) { i.style.background = '#fff8c4'; });
    msg.textContent = r.where + ', ' + r.distance_km + ' km from the saved coordinates; filled in above, save to keep it'
      + (r.timezone ? ' (timezone there: ' + r.timezone + ')' : '');
  };
  x.open('GET', 'scripts/locate_now.php', true);
  x.send();
}
</script>
      </td></tr></table><br>
      <table class="settingstable" style="width:100%"><tr><td>
      <h2 id="notifications" data-sec="notifications"><?php echo t('Notifications - Global'); ?></h2>
      <style>h3.vartitle { font-size: 1.2em; font-weight: 700; margin: 16px 0 6px; }</style>
      <p><?php echo t('<a target="_blank" href="https://github.com/caronc/apprise/wiki">Apprise Notifications</a> can be setup and enabled for 90+ notification services.'); ?><br><?php echo t('Each service should be on its own line.'); ?></p>
      <?php // the global options by theme (owner 2026-10-22): when a Normal species notifies (the Repetition limit under
      // "each new detection"), which species notify; the e-mail recipient lives only in Notifications › E-mail ?>
      <h3 class="vartitle"><?php echo t('When a Normal species is notified'); ?></h3>
      <?php // the boxes show what BirdSuite.conf says, also with no channel set up yet (2026-10-23 test run: an empty
      // Apprise box showed them off, and the next Update saved them off) ?>
      <input type="checkbox" name="apprise_notify_each_detection" <?php if($config['APPRISE_NOTIFY_EACH_DETECTION'] == 1) { echo "checked"; };?> >
      <label for="apprise_notify_each_detection"><?php echo t('Notify each new detection'); ?></label><br>
      <div style="margin:4px 0 8px 2em">
        <label for="minimum_time_limit"><?php echo t('Repetition limit: seconds before the same species notifies again:'); ?></label>
        <input type="number" id="minimum_time_limit" name="minimum_time_limit" value="<?php echo $config['APPRISE_MIN_SEC_PER_SPECIES'];?>" style="width:6em;" min="0"> <?php echo t('(0 = no limit)'); ?><br>
        <small><?php echo t('Also applies to the region-rare alerts.'); ?></small>
      </div>
      <input type="checkbox" name="apprise_notify_new_species_each_day" <?php if($config['APPRISE_NOTIFY_NEW_SPECIES_EACH_DAY'] == 1) { echo "checked"; };?> >
      <label for="apprise_notify_new_species_each_day"><?php echo t('Notify each species first detection of the day'); ?></label><br>
      <input type="checkbox" name="apprise_notify_new_species" <?php if($config['APPRISE_NOTIFY_NEW_SPECIES'] == 1) { echo "checked"; };?> >
      <label for="apprise_notify_new_species"><?php echo t('Notify each infrequent species (up to 5 detections in the last 7 days)'); ?></label><br>
      <small><?php echo t('When more than one applies, one message goes out with every reason.'); ?></small>
      <h3 class="vartitle"><?php echo t('Which species are notified'); ?></h3>
      <label for="notification_default_tier"><?php echo t('Default notification tier for species not listed in Species Cards:'); ?></label>
      <select name="notification_default_tier" id="notification_default_tier" style="width:12em;">
        <?php $ndt = strtolower($config['NOTIFICATION_DEFAULT_TIER'] ?? 'normal');
        foreach (['muted' => 'None (muted)', 'normal' => 'Normal', 'rare' => 'Rare'] as $t_val => $t_label) {
          $t_sel = $ndt === $t_val ? " selected" : "";
          echo "<option value='{$t_val}'{$t_sel}>" . t($t_label) . "</option>";
        } ?>
      </select><br>
      <label><?php echo t('Per-species notification policy:'); ?> </label>
      <a href="views.php?view=Bird"><b><?php echo t('edit the species list in Species Cards'); ?></b></a>:<br>
      &nbsp;&nbsp;<?php echo t('Set a species to <b>Muted</b> to silence it;'); ?><br>
      &nbsp;&nbsp;<?php echo t('To notify ONLY selected species, set the default tier above to <b>None (muted)</b> and mark the wanted species Normal or Rare.'); ?>
      <?php if(($config['APPRISE_EXCLUDE_SPECIES'] ?? '') != '' || ($config['APPRISE_ONLY_SPECIES'] ?? '') != '') {
        echo '<br><i>' . t('Note: legacy comma-separated name filters are present in BirdSuite.conf and still apply to Normal-tier notifications.') . '</i>';
      } ?><br>
      <h3 class="vartitle"><?php echo t('Where they go'); ?></h3>
      <?php echo t('The e-mail recipient is set in {email}.', array('email' => '<a style="text-decoration:underline" href="views.php?view=Email">' . t('Notifications') . ' › ' . t('E-mail') . '</a>')); ?><br>
      <?php echo t('The Telegram bot and groups are set in {telegram}.', array('telegram' => '<a style="text-decoration:underline" href="views.php?view=Telegram">' . t('Notifications') . ' › Telegram</a>')); ?><br>
      </td></tr></table><br>
      <?php // the variables in a section of their own, after the global options (owner 2026-10-22) ?>
      <table class="settingstable" style="width:100%"><tr><td>
      <h2 data-sec="notifications"><?php echo t('Notification variables'); ?></h2>
      <?php // the variables as a table, named after the app's columns (owner 2026-10-22, a rewrite: the names before are gone);
      // personal data never as an example (the e-mail address and the coordinates are generic)
      $ex = get_db()->querySingle('SELECT Com_Name, Sci_Name FROM detections ORDER BY rowid DESC LIMIT 1', true) ?: array('Com_Name' => 'Great Kiskadee', 'Sci_Name' => 'Pitangus sulphuratus');
      $vrow = function ($names, $example, $desc) {
        return '<tr><td><code>' . implode('</code>, <code>', array_map('htmlspecialchars', (array)$names)) . '</code></td><td>' . htmlspecialchars($example) . '</td><td>' . $desc . '</td></tr>';
      };
      $vhead = '<tr><th>' . t('Variable') . '</th><th>' . t('Example') . '</th><th>' . t('Description') . '</th></tr>'; ?>
      <p><?php echo t('Each $name in the title or the body is replaced by its value when the notification is sent.'); ?><br>
        <?php echo t('The names follow the columns of the app: Conf., Min. Conf., Manual Conf., Prob., Min. Prob.'); ?></p>
      <style>.vartable td:first-child { white-space: nowrap; } .vartable td { vertical-align: top; text-align: left; } .vartable { margin-bottom: 8px; }
        h3.vartitle { font-size: 1.2em; font-weight: 700; margin: 16px 0 6px; } p.varnote { margin-bottom: 1em; }</style>
      <h3 class="vartitle"><?php echo t('Title and body'); ?></h3>
      <table class="stdtable vartable">
        <?php echo $vhead
          . $vrow('$common_name', $ex['Com_Name'], t('Common name'))
          . $vrow('$scientific_name', $ex['Sci_Name'], t('Scientific name'))
          . $vrow('$reason', t('rare species'), t('The reason a notification was sent'))
          . $vrow('$conf_pct', '91', t('Confidence (Conf.) as a percentage'))
          . $vrow('$conf', '0.91', t('Confidence (Conf.) as a number'))
          . $vrow('$conf_min_pct', '20%', t('Minimum confidence (Min. Conf.) as a percentage'))
          . $vrow('$conf_min', '0.2', t('Minimum confidence (Min. Conf.) as a number'))
          . $vrow('$conf_manual_pct', '60%', t('The species\' manual confidence (Manual Conf.), or none'))
          . $vrow('$occurrence_probability', '12.5%', t('Probability of occurrence of the species at the location this week (Prob.)'))
          . $vrow('$occurrence_probability_min', '3%', t('Minimum probability of occurrence at the location (Min. Prob.)'))
          . $vrow('$station', get_sitename(), t('The station\'s name (Settings › Stations).'))
          . $vrow('$location', (string)((location_current() ?: array())['name'] ?? ''), t('The location the detection was recorded at.') . '<br>' . t('A text without this tag gets a Location line under its first line.'))
          . $vrow('$date', date('Y-m-d'), t('Date'))
          . $vrow('$time', date('H:i:s'), t('Time'))
          . $vrow('$calendar_week', date('W'), t('ISO calendar week'))
          . $vrow('$latitude', '-23.55', t('Latitude'))
          . $vrow('$longitude', '-46.63', t('Longitude'))
          . $vrow('$sigmoid_sensitivity', '1.0', t('Sigmoid sensitivity (Sigm. Sens.)'))
          . $vrow('$overlap', '1.2', t('Overlap, in seconds'))
          . $vrow('$recording_length', '15 s', t('Recording length (Rec. Length)'))
          . $vrow('$listen_url', 'https://…?filename=…', t('A link to the detection'))
          . $vrow('$listen_link_markdown', '[' . t('Listen here') . '](…)', t('A masked link to the detection.') . '<br>' . t('Only useful for services that support Markdown (e.g. Discord).'))
          . $vrow('$image', '', t('A photo of the species, attached to the notification'))
          . $vrow('$audio', '', t('Attaches the detection\'s audio clip to the notification')); ?>
      </table>
      <h3 class="vartitle"><?php echo t('Only in the Apprise Configuration boxes'); ?></h3>
      <p class="varnote"><?php echo t('Never in the notification title or body.'); ?> <?php echo t('Secrets never appear on this page.'); ?></p>
      <table class="stdtable vartable">
        <?php echo $vhead
          . $vrow(array('$user', '$hostname'), get_user() . ', ' . gethostname(), t('This station\'s user and hostname, e.g. {example}', array('example' => '<code>from=$user@$hostname.local</code>')))
          . $vrow('$email_recipient', 'you@example.com', t('The recipient set in Notifications › E-mail'))
          . $vrow(array('$email_user', '$email_password', '$email_sender'), '', t('The sender account set in Notifications › E-mail'))
          . $vrow(array('$telegram_bot', '$telegram_group_normal', '$telegram_group_prio'), '', t('The bot and the groups set in Notifications › Telegram'))
          . $vrow('$token1 … $token20', '', t('Other secret values from the station\'s private token store (apprise-tokens.txt)')); ?>
      </table>
      </td></tr></table><br>
      <table class="settingstable" style="width:100%"><tr><td>
      <h2 data-sec="notifications"><?php echo t('Notifications - Normal'); ?></h2>
      <label for="apprise_notification_title"><?php echo t('NORMAL Notification Title:'); ?> </label>
      <input name="apprise_notification_title" style="width: 100%" type="text" value="<?php print($config['APPRISE_NOTIFICATION_TITLE']);?>" /><br><br>
      <label for="apprise_notification_body"><?php echo t('NORMAL Notification Body:'); ?> </label>
      <textarea class="testbtn" name="apprise_notification_body" rows="18" style="width:100%; margin-top:0" type="text" ><?php print($apprise_notification_body);?></textarea><br><br>
      <?php // the two default channels, on or off, set up on their own pages (owner 2026-10-22: Telegram and e-mail always in sight)
      $channels = function ($text) {
        $one = function ($name, $on, $view) {
          return $name . ' ' . ($on ? '<b>' . t('on') . '</b>' : t('off') . ' (<a style="text-decoration:underline" href="views.php?view=' . $view . '">' . t('set up') . '</a>)');
        };
        return '<div style="margin:4px 0 6px">' . t('Channels:') . ' ' . $one('Telegram', preg_match('~^\s*tgram://~mi', (string)$text), 'Telegram')
          . ' &middot; ' . $one(t('E-mail'), preg_match('~^\s*mailtos?://~mi', (string)$text), 'Email') . '</div>';
      };
      echo $channels($apprise_config ?? ''); ?>
      <label for="apprise_input"><?php echo t('Apprise Notifications Configuration:'); ?> </label>
      <textarea placeholder="tgram://$token1/$token2   (<?php echo t('$token1..$token20 placeholders resolve from the station\'s private apprise-tokens.txt; secrets never appear here'); ?>)
mailto://{user}:{password}@gmail.com
twitter://{ConsumerKey}/{ConsumerSecret}/{AccessToken}/{AccessSecret}
https://discordapp.com/api/webhooks/{WebhookID}/{WebhookToken}
..." style="vertical-align: top; width:100%; margin-top:0" class="testbtn" name="apprise_input" rows="3" type="text" ><?php print($apprise_config);?></textarea><br>

      <br>

      <label><?php echo t('Quiet hours (Normal): from'); ?> <input type="time" name="quiet_start_normal" value="<?php echo htmlspecialchars(trim($config['APPRISE_QUIET_START_NORMAL'] ?? '', '"')); ?>">
      <?php echo t('to'); ?> <input type="time" name="quiet_end_normal" value="<?php echo htmlspecialchars(trim($config['APPRISE_QUIET_END_NORMAL'] ?? '', '"')); ?>"></label>
      <small><?php echo t('no Normal notifications in this window (it may cross midnight, e.g. 22:00 to 06:00); empty = always notify'); ?></small><br><br>
      <button type="button" class="stdbtn" onclick="sendTestNotification(this)"><?php echo t('Send Test Notification'); ?></button><br>
      <span id="testsuccessmsg"></span>
      </td></tr></table><br>
      <table class="settingstable" style="width:100%"><tr><td>
      <h2 data-sec="notifications"><?php echo t('Notifications - Rare'); ?></h2>
      <?php // Region-rare alerts first; off = the rest of the box greyed, as BirdWeather (owner 2026-10-22); the fields stay
      // editable and are sent with the form ?>
      <?php $rre = ($config['APPRISE_NOTIFY_REGION_RARE'] ?? '1') == '1'; ?>
      <label><input type="checkbox" name="notify_region_rare" id="notify_region_rare" <?php echo $rre ? 'checked' : ''; ?> onchange="document.getElementById('rare_box').style.opacity = this.checked ? '' : '0.7'"> <?php echo t('Region-rare alerts'); ?></label>
      <small><?php echo t('any species the location model does not expect here (in no week: vagrant; not now: out of season) is also sent here, with the reason, once per Repetition limit'); ?></small>
      <div id="rare_box" style="margin-top:1em;<?php echo $rre ? '' : 'opacity:0.7;'; ?>">
      <label for="apprise_notification_title_rare"><?php echo t('RARE Notification Title:'); ?> </label>
      <input name="apprise_notification_title_rare" style="width: 100%" type="text" placeholder="<?php echo t('empty = use the Normal title'); ?>" value="<?php print($config['APPRISE_NOTIFICATION_TITLE_RARE'] ?? '');?>" /><br><br>
      <label for="apprise_notification_body_rare"><?php echo t('RARE Notification Body:'); ?> </label>
      <textarea class="testbtn" name="apprise_notification_body_rare" rows="18" style="width:100%; margin-top:0" type="text" placeholder="<?php echo t('empty = use the Normal body'); ?>"><?php print($apprise_notification_body_rare);?></textarea><br><br>
      <?php echo $channels($apprise_config_rare ?? ''); ?>
      <label for="apprise_input_rare"><?php echo t('Apprise Configuration for Rare species:'); ?> </label>
      <textarea placeholder="tgram://$token1/$token3   (<?php echo t('$token1..$token20 placeholders resolve from the station\'s private apprise-tokens.txt'); ?>)
mailto://{user}:{password}@gmail.com
..." style="vertical-align: top; width:100%; margin-top:0" class="testbtn" name="apprise_input_rare" rows="3" type="text" ><?php print($apprise_config_rare);?></textarea><br>
      &nbsp;&nbsp;<?php echo t('Empty Rare Apprise/title/body fall back to the Normal ones.'); ?><br><br>
      <label><?php echo t('Quiet hours (Rare): from'); ?> <input type="time" name="quiet_start_rare" value="<?php echo htmlspecialchars(trim($config['APPRISE_QUIET_START_RARE'] ?? '', '"')); ?>">
      <?php echo t('to'); ?> <input type="time" name="quiet_end_rare" value="<?php echo htmlspecialchars(trim($config['APPRISE_QUIET_END_RARE'] ?? '', '"')); ?>"></label>
      <small><?php echo t('no Rare notifications in this window (it may cross midnight, e.g. 22:00 to 06:00); empty = always notify'); ?></small><br><br>
      <button type="button" class="stdbtn" onclick="sendTestNotification(this, 'apprise_input_rare', 'testsuccessmsgrare', 'apprise_notification_title_rare', 'apprise_notification_body_rare')"><?php echo t('Send Test Notification (Rare)'); ?></button><br>
      <span id="testsuccessmsgrare"></span>
      </div>
      </td></tr></table><br>
      <table class="settingstable"><tr><td>
      <h2 data-sec="basic">BirdWeather</h2>
      <label for="birdweather_enabled"><?php echo t('Upload to BirdWeather:'); ?> </label>
      <?php $bwe = ($config['BIRDWEATHER_ENABLED'] ?? '1') != '0'; ?>
      <input type="checkbox" name="birdweather_enabled" id="birdweather_enabled" <?php echo $bwe ? 'checked' : ''; ?> onchange="birdweatherToggle(this.checked)">
<br>
      <?php // off = the rest of the section greyed, as BirdDB-Br (owner 2026-10-21); the token is read-only, not disabled, so
      // the form still sends it and it is kept ?>
      <div id="bw_box" style="margin-top:1em;<?php echo $bwe ? '' : 'opacity:0.7;'; ?>"><?php // a blank line between the switch and the token (owner 2026-10-22) ?>
      <?php // one token, or one per location (owner 2026-10-22): a BirdWeather station has its own coordinates, so a station
        // that moves between places uploads each place to its own BirdWeather station; Test / Playback never uploads
        $bw_pl = ($config['BIRDWEATHER_PER_LOCATION'] ?? '0') === '1'; ?>
      <label style="font-weight:normal"><input type="radio" name="birdweather_mode" value="one" <?php echo $bw_pl ? '' : 'checked'; ?> onchange="bwMode()"> <?php echo t('One token for every location'); ?></label><br>
      <label style="font-weight:normal"><input type="radio" name="birdweather_mode" value="location" <?php echo $bw_pl ? 'checked' : ''; ?> onchange="bwMode()"> <?php echo t('A token for each location'); ?></label>
      <div id="bw_one" style="margin-top:8px<?php echo $bw_pl ? ';display:none' : ''; ?>">
      <label for="birdweather_id"><?php echo t('BirdWeather Token:'); ?> </label>
      <input name="birdweather_id" id="birdweather_id" type="text" value="<?php print($config['BIRDWEATHER_ID']);?>" <?php echo $bwe ? '' : 'readonly'; ?>/>
      </div>
      <div id="bw_loc" style="margin-top:8px<?php echo $bw_pl ? '' : ';display:none'; ?>">
      <table class="stdtable" style="width:auto"><tr><th><?php echo t('Location'); ?></th><th data-nosort><?php echo t('BirdWeather Token:'); ?></th></tr>
      <?php foreach (locations_load() as $bw_l) {
        echo '<tr><td>' . htmlspecialchars($bw_l['name']) . '</td><td>'
          . (($bw_l['builtin'] ?? '') === 'test' ? '<small>' . t('never uploads (tests and playback)') . '</small>'
            : '<input type="text" class="bwtok" name="bw_loc[' . intval($bw_l['id']) . ']" value="' . htmlspecialchars($bw_l['birdweather_id'] ?? '') . '" placeholder="' . htmlspecialchars(t('empty = this location does not upload')) . '" pattern="[A-Za-z0-9_\\-]{4,64}" title="' . htmlspecialchars(t('4 to 64 letters, digits, _ or -')) . '" style="width:22em"' . ($bwe ? '' : ' readonly') . '>')
          . '</td></tr>';
      } ?>
      </table>
      <small><?php echo t('Each location uploads with its own token; create one BirdWeather station per location, with that location\'s coordinates.'); ?></small>
      </div><br>
           <p><?php echo t('<a href="https://app.birdweather.com" target="_blank">BirdWeather.com</a> is a weather map for bird sounds.'); ?>
        <?php echo t('Stations around the world supply audio and video streams to BirdWeather where they are then analyzed by BirdNET and compared to eBird Grid data.'); ?>
        <?php echo t('BirdWeather catalogues the bird audio and spectrogram visualizations so that you can listen to, view, and read about birds throughout the world.'); ?> <br><br>
        <?php echo t('To request a BirdWeather Token, you\'ll first need to create an account:'); ?> <a href="https://app.birdweather.com/login" target="_blank">https://app.birdweather.com/</a><br>
        <?php echo t('Once that\'s done, you can go to:'); ?> <a href="https://app.birdweather.com/account/stations" target="_blank">https://app.birdweather.com/account/stations</a><br>
        <?php echo t('Make sure that the Latitude and Longitude match what is in your BirdSuite configuration.'); ?>
        <br><br>
        <span style="display:block"><?php echo t('NOTE: by using your BirdWeather Token, you are consenting to sharing your soundscapes and detections with BirdWeather'); ?></span></p>
      </div>
      <script>
        function bwMode() {
          var loc = document.querySelector('input[name="birdweather_mode"][value="location"]').checked;
          document.getElementById('bw_one').style.display = loc ? 'none' : '';
          document.getElementById('bw_loc').style.display = loc ? '' : 'none';
        }
        function birdweatherToggle(on) {
          document.getElementById('bw_box').style.opacity = on ? '' : '0.7';
          document.getElementById('birdweather_id').readOnly = !on;
          document.querySelectorAll('.bwtok').forEach(function (i) { i.readOnly = !on; });
        }
      </script>
      </td></tr></table><br>
      <?php // BirdDB-Br hidden (owner 2026-10-27) until the upload without an account per station is decided: the code stays,
            // the destination will come from birdbr-upload.txt in the birdsuite-assets repository
      if (BIRDDB_SECTION_SHOWN) { ?>
      <table class="settingstable" style="width:100%"><tr><td>
      <h2 data-sec="basic">BirdDB-Br</h2>
      <input type="hidden" name="birddb_shown" value="1">
      <?php $bde = (string)($config['BIRDDB_ENABLED'] ?? '0') === '1'; ?>
      <label><input type="checkbox" name="birddb_enabled" id="birddb_enabled" value="1" <?php echo $bde ? 'checked' : ''; ?>
        onchange="birddbToggle(this.checked)"> <?php echo t('Contribute to BirdDB-Br (central sound repository)'); ?></label>
      <small><?php echo t('off (default): no clip is copied, uploaded or kept for the repository'); ?></small><br>
      <div id="birddb_box" style="margin-top:1em;<?php echo $bde ? '' : 'opacity:0.7;'; ?>"><?php // a paragraph before rclone (owner 2026-10-21) ?>
      <?php $srm = $config['SOUND_REPO_REMOTE'] ?? ''; $sru = $config['SOUND_REPO_UPLOAD_MINUTES'] ?? '5'; ?>
      <table class="settingstable plaintable">
        <tr>
          <td><label for="sound_repo_remote"><?php echo t('rclone remote (remote:path):'); ?></label></td>
          <td><input name="sound_repo_remote" class="birddb_field" type="text" style="width:14em;" value="<?php print(htmlspecialchars($srm, ENT_QUOTES));?>" pattern="[A-Za-z0-9_\-]+:.*" placeholder="birddb:" <?php echo $bde ? '' : 'disabled'; ?>/></td>
          <td>(<?php echo t('empty = no upload, deposits stay in the local spool'); ?>)</td>
        </tr>
        <tr>
          <td><label for="sound_repo_upload_minutes"><?php echo t('Upload every:'); ?></label></td>
          <td><input name="sound_repo_upload_minutes" class="birddb_field" type="number" style="width:5em;" min="0" max="1440" step="1" value="<?php print(intval($sru));?>" <?php echo $bde ? '' : 'disabled'; ?>/> <?php echo t('minutes'); ?></td>
          <td>(<?php echo t('0 = never; the spool is moved to the remote at this interval'); ?>)</td>
        </tr>
      </table>
      </div>
      <script>
        function birddbToggle(on) {
          document.getElementById('birddb_box').style.opacity = on ? '' : '0.7';
          document.querySelectorAll('.birddb_field').forEach(function (f) { f.disabled = !on; });
        }
      </script>
      </td></tr></table><br>
      <?php } ?>


      <table class="settingstable"><tr><td>
      <h2 data-sec="hidden">Spectrogram and colours</h2>
      Note: when changing themes the daily chart may need a page refresh before updating.<br><br>
      <label for="color_scheme">Color scheme for the site : </label>
      <select name="color_scheme" class="testbtn">
      <?php
      $scheme = array("light", "dark");
      foreach($scheme as $color_scheme){
          $isSelected = "";
          if($config['COLOR_SCHEME'] == $color_scheme){
            $isSelected = 'selected="selected"';
          }

          echo "<option value='{$color_scheme}' $isSelected>$color_scheme</option>";
        }
      ?>
      </select><br><br>
      <label for="now_view">Latest page: most recent detections shown as: </label>
      <select name="now_view" class="testbtn">
        <?php foreach (array('simple' => 'Simple list', 'list' => 'Full list', 'spectrogram' => 'Spectrogram cards') as $nv => $nl) {
          echo "<option value='$nv'" . (($config['NOW_VIEW'] ?? 'list') === $nv ? " selected" : "") . ">$nl</option>";
        } ?>
      </select><br><br>
      <label for="now_analyzing">Latest page: Currently Analyzing spectrogram: </label>
      <select name="now_analyzing" class="testbtn">
        <?php foreach (array('show' => 'Shown', 'hide' => 'Hidden') as $nv => $nl) {
          echo "<option value='$nv'" . (($config['NOW_ANALYZING'] ?? 'show') === $nv ? " selected" : "") . ">$nl</option>";
        } ?>
      </select><br><br>
      <label for="spectrogram_palette">Spectrogram palette: </label>
      <select name="spectrogram_palette" class="testbtn">
      <?php
      $pal = $config['SPECTROGRAM_PALETTE'] ?? 'turbo';
      foreach (array('turbo'=>'Turbo (blue → green → red)','inferno'=>'Inferno (black → red → yellow)','roseus'=>'Roseus (black → violet → yellow)','soxheat'=>'SoX heat (black → purple → orange → white)','birdnet'=>'Classic (purple → red → orange)','viridis'=>'Viridis (dark → yellow)','ocean'=>'Ocean (black → cyan)','grayscale'=>'Grayscale (black → white)') as $k => $l) {
        echo "<option value='{$k}'" . ($k == $pal ? ' selected="selected"' : '') . ">{$l}</option>";
      }
      ?>
      </select><br>
      Applies to the live waterfall and to the SoX images (Overview, detections). Also selectable at the top left of the Spectrogram page.<br><br>
      <label for="spectrogram_height">Live spectrogram height (% of the page): </label>
      <input name="spectrogram_height" type="number" style="width:5em;" min="20" max="100" step="1" value="<?php print(is_numeric($config['SPECTROGRAM_HEIGHT'] ?? null) ? $config['SPECTROGRAM_HEIGHT'] : 70);?>" required/><br><br>
      <b>Colour sensitivity</b> (live waterfall; the SoX images follow floor and range)<br>
      <label for="spectrogram_floor_db">Floor (dB, −120…−40): </label>
      <input name="spectrogram_floor_db" type="number" style="width:5em;" min="-120" max="-40" step="5" value="<?php print(is_numeric($config['SPECTROGRAM_FLOOR_DB'] ?? null) ? $config['SPECTROGRAM_FLOOR_DB'] : -100);?>" required/>
      &nbsp; <label for="spectrogram_range_db">Range (dB, 30…120): </label>
      <input name="spectrogram_range_db" type="number" style="width:5em;" min="30" max="120" step="5" value="<?php print(is_numeric($config['SPECTROGRAM_RANGE_DB'] ?? null) ? $config['SPECTROGRAM_RANGE_DB'] : 70);?>" required/>
      &nbsp; <label for="spectrogram_contrast">Contrast (gamma, 0.5…2): </label>
      <input name="spectrogram_contrast" type="number" style="width:5em;" min="0.5" max="2" step="0.1" value="<?php print(is_numeric($config['SPECTROGRAM_CONTRAST'] ?? null) ? $config['SPECTROGRAM_CONTRAST'] : 1.0);?>" required/><br>
      Floor: signal at or below it takes the darkest colour. Range: width of the scale above the floor. Contrast: below 1 lifts faint sounds, above 1 keeps only the strong ones.
      </td></tr></table><br>
        
      <?php // Time and Date removed (owner 2026-10-22): see the save above ?>

      <br><br>

      <input type="hidden" name="status" value="success">
      <input type="hidden" name="submit" value="settings">
<div class="float">
      <button type="submit" id="basicformsubmit" onclick="if(document.getElementById('basicform').checkValidity()){this.innerHTML = t('Updating... please wait.');this.classList.add('disabled')}" name="view" value="<?php echo in_array($_GET['view'] ?? '', array('Model', 'NotifyRules'), true) ? $_GET['view'] : 'Settings'; ?>">
<?php
if(isset($_GET['status'])){
  echo '<script>alert(' . json_encode(t('Settings successfully updated'), JSON_HEX_TAG | JSON_UNESCAPED_UNICODE) . ');</script>';
}
echo t('Update Settings');
?>
      </button></div>
      </form>
</div>
<script>
// Settings › Model, Basic Settings and Notifications › Content are this one form (owner 2026-10-20): every field is
// still sent on Update, so nothing hidden is reset; only the sections of the menu entry are shown
(function () {
  var part = <?php echo json_encode($config_section ?? 'all'); ?>;
  if (part === 'all') return;
  // the whole Location section (station name, coordinates, timezone, species list filter) is set in Settings › Locations
  // (owner 2026-10-20); its fields stay in this form, hidden, so Update keeps them as they are.
  // Basic Settings became Repositories (owner 2026-10-20; first named Uploads): BirdWeather and BirdDB-Br only; Localization went to Model;
  // Spectrogram and colours (defaults the Now and Spectrogram
  // pages set with their own buttons) and Time and Date (automatic; Now offers this device's time when the clock is
  // not confirmed) are no longer shown — their fields stay hidden in the form
  // each section heading carries a stable data-sec (model / notifications / basic / hidden), so the headings can be
  // translated (US-122)
  document.querySelectorAll('#basicform > table.settingstable').forEach(function (tb) {
    var h2 = tb.querySelector('h2');
    var sec = (h2 && h2.getAttribute('data-sec')) || 'hidden';
    if (sec !== part) {
      tb.style.display = 'none'; var br = tb.nextElementSibling; if (br && br.tagName === 'BR') br.style.display = 'none';
      // a hidden field cannot be fixed by the user: it must not block Update (2026-10-23 test run: an empty hidden
      // latitude stopped the Repositories page without a word); the server keeps or checks the value as before
      tb.querySelectorAll('[required]').forEach(function (f) { f.removeAttribute('required'); });
    }
  });
})();
</script>
