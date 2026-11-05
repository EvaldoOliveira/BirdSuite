<?php
error_reporting(E_ERROR);
ini_set('display_errors', 0);  // errors to the log, never to the page (2026-10-23 security run)
define('__ROOT__', dirname(dirname(__FILE__)));
require_once(__ROOT__.'/scripts/common.php');

$user = get_user();
$home = get_home();
$config = get_config();
// the BirdDB-Br section of Repositories: Settings › Defaults › Sharing › ACTIVATE_BIRDDB (owner 2026-10-27: false by default
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

if (file_exists(BS_NOTIFY . "/apprise.txt")) {
  $apprise_config = file_get_contents(BS_NOTIFY . "/apprise.txt");
} else {
  $apprise_config = "";
}
$apprise_config_rare = file_exists(BS_NOTIFY . "/apprise-rare.txt") ? file_get_contents(BS_NOTIFY . "/apprise-rare.txt") : "";
$apprise_notification_body_rare = file_exists(BS_NOTIFY . "/body-rare.txt") ? file_get_contents(BS_NOTIFY . "/body-rare.txt") : "";

if (file_exists(BS_NOTIFY . "/body.txt")) {
  $apprise_notification_body = file_get_contents(BS_NOTIFY . "/body.txt");
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

// the Common name Language saved by its own Save button: the setting and the model's label file (labels.txt). The names
// are presentation only (owner 2026-11-05, "one index only": the database and the lists hold the scientific name), so
// nothing is renamed and no service restarts: the pages look the names up when they show them, and the analysis reads the
// language again when BirdSuite.conf changes (analysis.py reload_settings_if_changed)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_GET['set_lang'])) {
  header('Content-Type: application/json');
  $lang = (string)$_GET['set_lang'];
  if (!isset(common_name_languages()[$lang]) || !is_file(BS_MODEL . "/l18n/labels_$lang.json")) { echo json_encode(array('ok' => false)); die(); }
  if ($lang !== ($config['DATABASE_LANG'] ?? '')) {
    if (!conf_set('DATABASE_LANG', $lang)) { echo json_encode(array('ok' => false)); die(); }
    syslog_shell_exec(BS_SCRIPTS . "/install_language_label.sh", $user);
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

  $command = station_prefix() . BS_PYTHON . " " . BS_SCRIPTS . "/species.py --threshold $threshold 2>&1";

  $output = shell_exec($command);

  echo $output;
  die();
}

# the settings form (Settings › Model, Settings › Repositories, Notifications › Content)
if(($_GET['submit'] ?? '') === 'settings'){
  if (isset($_GET["birdweather_id"])) $birdweather_id = $_GET["birdweather_id"];
  // BirdWeather upload switch (the token is kept when it is off); only the Settings form carries it
  $birdweather_enabled = isset($_GET['birdweather_enabled']) ? 1 : 0;
  $apprise_input = $_GET['apprise_input'];
  if(isset($_GET['apprise_input_rare'])) { $apprise_input_rare = $_GET['apprise_input_rare']; }
  if (isset($_GET['apprise_notification_title'])) $apprise_notification_title = $_GET['apprise_notification_title'];
  $apprise_notification_body = htmlspecialchars_decode($_GET['apprise_notification_body'], ENT_QUOTES);
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
  if(isset($_GET['apprise_notification_title_rare'])) { $apprise_notification_title_rare = $_GET['apprise_notification_title_rare']; }
  if(isset($_GET['apprise_notification_body_rare'])) { $apprise_notification_body_rare = htmlspecialchars_decode($_GET['apprise_notification_body_rare'], ENT_QUOTES); }
  if (isset($_GET['minimum_time_limit'])) $minimum_time_limit = $_GET['minimum_time_limit'];
  $language = (string)($_GET["language"] ?? ($config['DATABASE_LANG'] ?? ''));
  $model = (string)($_GET["model"] ?? ($config['MODEL'] ?? ''));
  $known_models = array("BirdNET_GLOBAL_6K_V2.4_Model_FP16", "BirdNET_6K_GLOBAL_MODEL", "BirdNET-Plus_V3.0-preview3.1_Global_10K");
  $shadow_model = isset($_GET['shadow_model']) && in_array($_GET['shadow_model'], $known_models) && $_GET['shadow_model'] != $model ? $_GET['shadow_model'] : '';
  $shadow_min_conf = isset($_GET['shadow_min_conf']) && is_numeric($_GET['shadow_min_conf']) ? max(0.01, min(0.99, round(floatval($_GET['shadow_min_conf']), 2))) : ($config['SHADOW_MIN_CONF'] ?? 0.7);
  $sf_thresh = (string)($_GET["sf_thresh"] ?? ($config['SF_THRESH'] ?? ''));
  // US-47 (owner 2026-09-22): both models and their parameters live in one "Models" block.
  // Official: CONFIDENCE / SENSITIVITY / SF_THRESH; shadow: SHADOW_MIN_CONF / SHADOW_SENS /
  // SHADOW_GEO_THRESH; OVERLAP is shared (one recording pipeline, both models see the same windows).
  $confidence = isset($_GET['confidence']) && is_numeric($_GET['confidence']) ? max(0.01, min(0.99, round(floatval($_GET['confidence']), 2))) : $config['CONFIDENCE'];
  $sensitivity = isset($_GET['sensitivity']) && is_numeric($_GET['sensitivity']) ? max(0.5, min(1.5, round(floatval($_GET['sensitivity']), 2))) : $config['SENSITIVITY'];
  $overlap = isset($_GET['overlap']) && is_numeric($_GET['overlap']) ? max(0.0, min(2.9, round(floatval($_GET['overlap']), 1))) : $config['OVERLAP'];
  $shadow_sens = isset($_GET['shadow_sens']) && is_numeric($_GET['shadow_sens']) ? max(0.5, min(1.5, round(floatval($_GET['shadow_sens']), 2))) : ($config['SHADOW_SENS'] ?? 1.25);
  $shadow_geo_thresh = isset($_GET['shadow_geo_thresh']) && is_numeric($_GET['shadow_geo_thresh']) ? round(max(0.0005, min(0.99, floatval($_GET['shadow_geo_thresh']))), 4) : ($config['SHADOW_GEO_THRESH'] ?? 0.99);
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

  // written into BirdSuite.conf, which shell scripts source: a plain address only, nothing a shell would expand
  if(isset($_GET['notification_email']) && preg_match('/^[^\s"\'`$\\\\@]+@[^\s"\'`$\\\\@]+$/', trim($_GET['notification_email']))) { $notification_email = trim($_GET['notification_email']); }
  if(isset($_GET['notification_default_tier'])) {
    $notification_default_tier = strtolower($_GET['notification_default_tier']);
    if(!in_array($notification_default_tier, ['muted', 'normal', 'rare'], true)) {
      $notification_default_tier = 'normal';
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

  // the clock is NTP's (owner 2026-10-22): the hidden Time and Date fields of the upstream form were sent with every save
  // while NTP was not synchronised, turned NTP off (set-ntp false disabled chrony for good) and set the clock to the time
  // the page was opened; without internet the clock is set from the phone (Now: Use this device's time)
  if(trim((string)exec("timedatectl show -p NTP --value")) !== 'yes'){
    exec("sudo -n " . BS_ROOT_HELPER . " ntp on");
    sleep(3);
  }

  // every key through conf_set_many() (common.php): each value checked against its rule, all or nothing written
  $set = array();
  if (isset($birdweather_id)) $set['BIRDWEATHER_ID'] = trim((string)$birdweather_id);
  $set['BIRDWEATHER_ENABLED'] = $birdweather_enabled;
  if (isset($apprise_notification_title)) $set['APPRISE_NOTIFICATION_TITLE'] = $apprise_notification_title;
  foreach ($quiet as $key => $val) $set[$key] = $val;
  if (isset($notify_region_rare)) $set['APPRISE_NOTIFY_REGION_RARE'] = $notify_region_rare;
  if (isset($apprise_notification_title_rare)) $set['APPRISE_NOTIFICATION_TITLE_RARE'] = $apprise_notification_title_rare;
  $set['APPRISE_NOTIFY_EACH_DETECTION'] = $apprise_notify_each_detection;
  $set['APPRISE_NOTIFY_NEW_SPECIES'] = $apprise_notify_new_species;
  $set['APPRISE_NOTIFY_NEW_SPECIES_EACH_DAY'] = $apprise_notify_new_species_each_day;
  if ((string)$language !== '') $set['DATABASE_LANG'] = $language;
  if (isset($minimum_time_limit)) $set['APPRISE_MIN_SEC_PER_SPECIES'] = trim((string)$minimum_time_limit);
  if (isset($model)) $set['MODEL'] = $model;
  $set['CONFIDENCE'] = $confidence;
  $set['SENSITIVITY'] = $sensitivity;
  $set['OVERLAP'] = $overlap;
  $set['SHADOW_MODEL_NAME'] = $shadow_model;
  $set['SHADOW_MIN_CONF'] = $shadow_min_conf;
  $set['SHADOW_SENS'] = $shadow_sens;
  $set['SHADOW_GEO_THRESH'] = $shadow_geo_thresh;
  $set['PRIVACY_THRESHOLD'] = $privacy_threshold;
  $set['AVOID_DUPLICITY'] = $avoid_duplicity;
  $set['SHADOW_ENABLED'] = $shadow_enabled;
  if (isset($sf_thresh)) $set['SF_THRESH'] = trim((string)$sf_thresh);
  $set['DATA_MODEL_VERSION'] = $data_model_version;
  if (isset($notification_email)) $set['NOTIFICATION_EMAIL'] = $notification_email;
  if (isset($notification_default_tier)) $set['NOTIFICATION_DEFAULT_TIER'] = $notification_default_tier;
  // US-40: station-side transport to the central sound repository (rclone remote + upload interval)
  if (isset($_GET['sound_repo_upload_minutes'])) {
    $set['SOUND_REPO_UPLOAD_MINUTES'] = is_numeric($_GET['sound_repo_upload_minutes']) ? max(0, min(1440, intval($_GET['sound_repo_upload_minutes']))) : 0;
    $set['SOUND_REPO_REMOTE'] = isset($_GET['sound_repo_remote']) ? trim($_GET['sound_repo_remote']) : '';
  }
  // only the Settings form carries birdweather_id (BirdWeather and BirdDB-Br, Settings › Repositories)
  if (isset($_GET['birdweather_id'])) {
    // BirdWeather: one token for every location, or a token for each location (owner 2026-10-22: a BirdWeather station
    // has fixed coordinates); the tokens of the locations are kept in config/locations/locations.json ('birdweather_id')
    $set['BIRDWEATHER_PER_LOCATION'] = (($_GET['birdweather_mode'] ?? '') === 'location') ? 1 : 0;
    // BirdDB-Br switch (default off), only when the form shows its section (a form without it keeps the setting)
    if (isset($_GET['birddb_shown'])) $set['BIRDDB_ENABLED'] = isset($_GET['birddb_enabled']) ? 1 : 0;
  }
  if (!conf_set_many($set, $err)) {
    echo '<div class="settings"><p>' . htmlspecialchars($err) . '</p><p><a href="views.php?view=' . htmlspecialchars(rawurlencode((string)($_GET['view'] ?? 'Sharing'))) . '">' . t('str_back') . '</a></p></div>';
    die();
  }
  if (isset($_GET['birdweather_id']) && isset($_GET['bw_loc']) && is_array($_GET['bw_loc'])) {
    $bw_list = locations_load();
    foreach ($bw_list as &$bw_l) {
      if (!array_key_exists((string)$bw_l['id'], $_GET['bw_loc'])) continue;
      $bw_t = trim((string)$_GET['bw_loc'][(string)$bw_l['id']]);
      if ($bw_t === '' || preg_match('/^[A-Za-z0-9_-]{4,64}$/', $bw_t)) $bw_l['birdweather_id'] = $bw_t;
    }
    unset($bw_l);
    locations_save($bw_list);
  }
  if ($language != ($config['DATABASE_LANG'] ?? '')) station_numeric_sync((string)($config['UI_LANG'] ?? ''), (string)$language);
  // settings saved here answer the first-run questions too: stop opening the Overview on the wizard
  if (file_exists(BS_FIRSTRUN)) { @unlink(BS_FIRSTRUN); }

  if(isset($apprise_input)){
    $appriseconfig = fopen(BS_NOTIFY . "/apprise.txt", "w");
    fwrite($appriseconfig, $apprise_input);
    $apprise_config = $apprise_input;
  }
  if(isset($apprise_input_rare)){
    $appriseconfigrare = fopen(BS_NOTIFY . "/apprise-rare.txt", "w");
    fwrite($appriseconfigrare, $apprise_input_rare);
    $apprise_config_rare = $apprise_input_rare;
  }
  if(isset($apprise_notification_body)){
    $apprisebody = fopen(BS_NOTIFY . "/body.txt", "w");
    fwrite($apprisebody, $apprise_notification_body);
  }
  if(isset($apprise_notification_body_rare)){
    $apprisebodyrare = fopen(BS_NOTIFY . "/body-rare.txt", "w");
    fwrite($apprisebodyrare, $apprise_notification_body_rare);
  }
  if ($model != $config['MODEL'] || $language != $config['DATABASE_LANG']){
    if(strlen($language) == 2 || strlen($language) == 5){
      syslog_shell_exec(BS_SCRIPTS . "/install_language_label.sh", $user);
      syslog(LOG_INFO, "Successfully changed language to '$language' and model to '$model'");
    }
  }
  syslog(LOG_INFO, "Restarting Services");
  shell_exec(station_prefix() . "restart_services.sh");

  // the saved form opens again by GET with the "updated" alert (post/redirect/get: a reload does not send it twice);
  // the menu is already printed, so the browser is sent there by script
  $back = json_encode('views.php?view=' . (in_array($_GET['view'] ?? '', array('Model', 'What and when'), true) ? $_GET['view'] : 'Sharing') . '&status=success');
  echo "<script>location.replace($back);</script>";
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

  $cmd = station_prefix() . BS_PYTHON . " " . BS_SCRIPTS . "/send_test_notification.py --body $t_body_path --config $t_conf_path --title " . escapeshellarg($title) . " 2>&1";  // escapeshellarg (2026-10-22): escapeshellcmd inside quotes left \$comname in the title
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
            document.getElementById(msgspan).innerHTML = this.responseText + " " + t('str_test_sent') + " " + t('str_make_sure_to_update_settings_below')
            e.classList.remove("disabled");
        }
    }
    // POST (2026-10-23 test run): the notification URLs carry tokens, which a GET left in the web server log
    xmlHttp.open("POST", "scripts/config.php", true); // true for asynchronous
    xmlHttp.setRequestHeader("Content-Type", "application/x-www-form-urlencoded");
    xmlHttp.send("sendtest=true"+"&apprise_notification_body="+apprise_notification_body+"&apprise_config="+apprise_config+"&apprise_notification_title="+encodeURIComponent(apprise_notification_title));
}
</script>
      <?php // Common name Language first on the Model page (owner 2026-10-22); on Station › Model every section has its own
            // button (owner 2026-11-05): Save here (it only renames), Apply (the services restart) for Models and Recording ?>
      <table class="settingstable"><tr><td>
      <h2 data-sec="model"><?php echo t('str_common_name_language'); ?></h2>
      <?php // the Interface language lives in the menu (flags), not here (owner 2026-10-21) ?>
      <?php // since one index only (owner 2026-11-05) the language changes only the names shown, nothing is renamed ?>
      <p><?php echo t('str_changing_it_only_changes_the_names_help'); ?></p>
      <label for="language"><?php echo t('str_common_name_language_colon'); ?> </label>
      <select name="language" class="testbtn">
      <?php
        $langs = common_name_languages();

        // Create options for each language
        foreach($langs as $langTag => $langName){
          $isSelected = "";
          if($config['DATABASE_LANG'] == $langTag){
            $isSelected = 'selected="selected"';
          }

          echo "<option value='{$langTag}' $isSelected>" . t($langName) . "</option>";
        }
      ?>

      </select> <button type="button" class="stdbtn" onclick="cnlSave(document.querySelector('select[name=language]'))"><?php echo t('str_save'); ?></button> <b id="cnlmsg" class="small"></b>
      <script>
      // saved by its own Save button (owner 2026-11-05: every section of Station › Model has its button; this one only
      // changes the names shown, so Save, not Apply; was saved on change since 2026-10-24)
      function cnlSave(sel) {
        if (sel.value === 'not-selected') return;
        var m = document.getElementById('cnlmsg');
        m.textContent = t('str_saving');
        var x = new XMLHttpRequest();
        x.onload = function () {
          var r; try { r = JSON.parse(this.responseText); } catch (e) { r = {}; }
          m.textContent = r.ok ? t('str_saved_names_shown_in_the_new_language') : (this.status === 401 ? t('str_log_in_first') : t('str_not_saved'));
        };
        x.onerror = function () { m.textContent = t('str_not_saved'); };
        x.open('POST', 'scripts/config.php', true);
        x.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
        x.send('set_lang=' + encodeURIComponent(sel.value));
      }
      </script>
      </td></tr></table>
      <br>

      <table class="settingstable"><tr><td>
      <h2 data-sec="model"><?php echo t('str_models'); ?></h2>
      <?php
      // US-47: official and shadow model side by side, each with its own minimum confidence,
      // sigmoid sensitivity and location threshold; the overlap is shared. Config keys unchanged.
      $models = array("BirdNET-Plus_V3.0-preview3.1_Global_10K", "BirdNET_GLOBAL_6K_V2.4_Model_FP16", "BirdNET_6K_GLOBAL_MODEL");
      ?>
      <?php // owner's layout (2026-10-20): the table (official, Swap, shadow) with the overlap as its last column; the
            // species range model only with the V2.4 model; shadow mode and avoid duplicity; the models; the thresholds note ?>
      <table class="modelstable">
        <tr><th></th><th><?php echo t('str_model'); ?></th><th><?php echo t('str_min_confidence'); ?><br><small>[<?php echo t('str_min_to_max', array('min' => fmt_setting('0.01'), 'max' => fmt_setting('0.99'))); ?>]</small></th><th><?php echo t('str_sensitivity'); ?><br><small>[<?php echo t('str_min_to_max', array('min' => fmt_setting('0.5'), 'max' => fmt_setting('1.5'))); ?>]</small></th><th><?php echo t('str_min_prob'); ?><br><small>[<?php echo t('str_min_to_max', array('min' => fmt_setting('0.0005'), 'max' => fmt_setting('0.99'))); ?>]</small></th><th><?php echo t('str_overlap'); ?><br><small>[<?php echo t('str_min_to_max', array('min' => fmt_setting('0.0'), 'max' => fmt_setting('2.9'))); ?>]</small></th></tr>
        <tr>
          <td><b><?php echo t('str_official'); ?></b></td>
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
          <td><input name="overlap" type="number" style="width:5em;" min="0.0" max="2.9" step="0.1" value="<?php print($config['OVERLAP']);?>" title="<?php echo t('str_shared_the_recording_is_cut_help'); ?>"/> s</td>
        </tr>
        <tr>
          <td></td>
          <td colspan="5"><button type="submit" name="swap_models" value="1" class="stdbtn" <?php if (($config['SHADOW_MODEL_NAME'] ?? '') === '') echo 'disabled title="' . t('str_choose_a_shadow_model_first') . '"'; ?>
            onclick="return bsAskSubmit(this, t('str_swap_the_official_and_the_help') + ' ' + t('str_services_restart'), true);"><?php echo t('str_swap_models'); ?></button></td>
        </tr>
        <tr id="shadowrow" class="<?php if((string)($config['SHADOW_ENABLED'] ?? '0') === '0') echo 'shadow-off'; ?>">
          <td><b><?php echo t('str_shadow'); ?></b></td>
          <td><select name="shadow_model" class="testbtn">
        <option value=""><?php echo t('str_none'); ?></option>
      <?php
      foreach($models as $modelName){
          $isSelected = (($config['SHADOW_MODEL_NAME'] ?? '') == $modelName) ? 'selected="selected"' : '';
          echo "<option value='{$modelName}' $isSelected>$modelName</option>";
        }
      ?>
          </select></td>
          <td><input name="shadow_min_conf" type="number" style="width:5em;" max="0.99" min="0.01" step="0.01" value="<?php print($config['SHADOW_MIN_CONF'] ?? '0.7');?>"/></td>
          <td><input name="shadow_sens" type="number" style="width:5em;" min="0.5" max="1.5" step="0.01" value="<?php print($config['SHADOW_SENS'] ?? '1.25');?>"/></td>
          <td><input name="shadow_geo_thresh" type="number" style="width:5em;" max="0.99" min="0.0005" step="any" value="<?php print($config['SHADOW_GEO_THRESH'] ?? '0.99');?>"/></td>
          <td></td><?php // the overlap is shared with the official model (owner 2026-10-21: no "same" label) ?>
        </tr>
      </table>
      <br>
      <?php // the species range model and its threshold belong to the V2 models only ?>
      <span <?php if($config['MODEL'] !== "BirdNET_GLOBAL_6K_V2.4_Model_FP16") { ?>style="display: none"<?php } ?> id="soft">
      <input type="checkbox" name="data_model_version" <?php if($config['DATA_MODEL_VERSION'] == 2) { echo "checked"; };?> >
      <label for="data_model_version"><?php echo t('str_species_range_model'); ?> V2.4 - V2</label>  [ <a target="_blank" href="https://github.com/kahst/BirdNET-Analyzer/discussions/234"><?php echo t('str_info_here'); ?></a> ]<br>
      &nbsp;&nbsp;&nbsp;&nbsp;<label><?php echo t('str_minimum_probability_at_the_location_the_help'); ?></label> <span onclick="document.getElementById('sfhelp').style.display='unset'" style="text-decoration:underline;cursor:pointer">[<?php echo t('str_more_info'); ?>]</span>
      <p id="sfhelp" style='display:none'><?php echo t('str_this_value_is_used_by_the_model_help'); ?>
        <?php echo t('str_a_minimum_probability_of_help'); ?>
        <?php echo t('str_so_the_lower_the_minimum_help'); ?><br><img style='max-width:100%;padding-top:5px;padding-bottom:5px' alt="<?php echo t('str_birdsuite_new_model_detection_help'); ?>" title="<?php echo t('str_birdsuite_new_model_detection_help'); ?>" src="images/BirdNET-Pi_nm_flowchart.alpha.png">
        <br><?php if($config['MODEL'] == "BirdNET_6K_GLOBAL_MODEL"){ ?><?php echo t('str_if_youd_please_click_apply_help'); ?><?php } else { ?><?php echo t('str_if_youd_you_can_use_this_help'); ?> <button type="button" class="testbtn" id="openModal"><?php echo t('str_species_list_tester'); ?></button><?php } ?></p>
      <br></span>
      <?php // Shadow mode and Avoid duplicity in one line each (owner 2026-10-21) ?>
      <p><b><?php echo t('str_shadow_mode'); ?></b> &nbsp;<label style="font-weight:normal"><input type="checkbox" id="shadow_enabled" name="shadow_enabled" value="1" onchange="document.getElementById('shadowrow').classList.toggle('shadow-off', !this.checked)" <?php if((string)($config['SHADOW_ENABLED'] ?? '0') !== '0') echo 'checked'; ?>> <?php echo t('str_enables_two_parallel_models_to_help'); ?></label></p>
      <p><b><?php echo t('str_avoid_duplicity'); ?></b> &nbsp;<label style="font-weight:normal"><input type="checkbox" name="avoid_duplicity" value="1" <?php if((string)($config['AVOID_DUPLICITY'] ?? '0') === '1') echo 'checked'; ?>> <?php echo t('str_one_species_per_recording_in_help', array('len' => intval($config['RECORDING_LENGTH'] ?? 15) ?: 15)); ?></label></p>
      <?php // from Settings › Advanced (owner 2026-10-25) ?>
      <?php // BirdNET+ V3 has no human class (owner 2026-10-25): the filter works only through a shadow model V2.4 that has one
      $pv_v3 = strpos($config['MODEL'] ?? '', 'BirdNET-Plus') === 0;
      $pv_shadow = (string)($config['SHADOW_ENABLED'] ?? '0') !== '0' && strpos($config['SHADOW_MODEL_NAME'] ?? '', 'BirdNET-Plus') !== 0 && ($config['SHADOW_MODEL_NAME'] ?? '') !== '';
      $pv_off = $pv_v3 && !$pv_shadow; ?>
      <p><b><?php echo t('str_privacy_threshold'); ?></b> &nbsp;<input type="number" name="privacy_threshold" aria-label="<?php echo htmlspecialchars(t('str_privacy_threshold'), ENT_QUOTES); ?>" min="0" max="3" step="1" style="width:4em<?php echo $pv_off ? ';opacity:.75' : ''; ?>" value="<?php echo intval($config['PRIVACY_THRESHOLD'] ?? 1); ?>"> %
        <?php // one sentence per line, the owner's wording (2026-10-26) ?>
        <?php echo t('str_a_recording_where_a_human_voice_help'); ?>
        <?php // the lines under it indented as the model list below (2em, owner 2026-10-26) ?>
        <span style="display:block;padding-left:2em"><?php echo t('str_0_off_start_with_1'); ?>
        <?php if ($pv_off) { ?><br><span style="color:#c62828"><?php echo t('str_the_birdnet_plus_v3_model_has_help'); ?></span>
          <br><small><?php echo t('str_for_privacy_with_v3_0_turn_on_help'); ?><br><?php echo t('str_v2_4_has_human_voice_detection_help'); ?><br><?php echo t('str_the_pis_processing_load_goes_up_help'); ?></small>
        <?php } elseif ($pv_v3) { ?><br><small style="color:#1b5e20"><?php echo t('str_with_birdnet_plus_v3_it_works_help'); ?></small><?php } ?></span></p>

<script src="static/dialog-polyfill.js"></script>

<dialog id="modal">
  <div>
    <label for="threshold"><?php echo t('str_min_probability'); ?></label>
    <input type="number" id="threshold" step="0.01" min="0" max="1" value="">
    <button type="button" id="runProcess"><?php echo t('str_preview_species_list'); ?></button>
  </div>
  <pre id="output"></pre>
  <button type="button" id="closeModal"><?php echo t('str_close'); ?></button>
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
  output.innerHTML = t('str_loading');

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
            array('BirdNET-Plus_V3.0-preview3.1_Global_10K', t('str_birdsuite_factory'), $bd['CONFIDENCE'] ?? '-', isset($bd['SENSITIVITY']) && is_numeric($bd['SENSITIVITY']) ? number_format((float)$bd['SENSITIVITY'], max(1, strlen(substr(strrchr($bd['SENSITIVITY'], '.') ?: '', 1)))) : '-', $bd['SF_THRESH'] ?? '-', $bd['OVERLAP'] ?? '-'),
            array('BirdNET-Plus_V3.0-preview3.1_Global_10K', t('str_cornell_birdnet_live_app'), '0.5', '1.0', '0.03', '0'),
            array('BirdNET-Plus_V3.0-preview3.1_Global_10K', t('str_cornell_analysis_script'), '0.25', '-', '-', '0'),
            array('BirdNET_GLOBAL_6K_V2.4_Model_FP16', t('str_cornell_birdnet_analyzer'), '0.25', '1.0', '0.03', '0'),
            array('BirdNET_GLOBAL_6K_V2.4_Model_FP16', t('str_original_project_birdnet_pi'), '0.7', '1.25', '0.03', '0'),
            array('BirdNET_6K_GLOBAL_MODEL', t('str_legacy_6k_model'), '0.7', '1.25', '-', '0'),
          ); ?>
        <table class="stdtable" style="width:auto;margin-top:6px" data-nosort>
          <tr><th><?php echo t('str_model'); ?></th><th><?php echo t('str_defaults_of'); ?></th><th class="num"><?php echo t('str_min_conf'); ?></th><th class="num"><?php echo t('str_sigm_sens'); ?></th><th class="num"><?php echo t('str_min_prob'); ?></th><th class="num"><?php echo t('str_overlap'); ?></th><th data-nosort></th></tr>
          <?php // Use: these values into the official model's fields (saved with the form), only for the model selected above
          // a thicker line between the model generations (V3, V2.4, 6K), owner 2026-10-22
          $prevm = null;
          foreach ($defrows as $r) { $gen = $prevm !== null && $prevm !== $r[0] ? ' class="gensep"' : ''; $prevm = $r[0]; echo '<tr' . $gen . '><td>' . htmlspecialchars(str_replace('_', ' ', preg_replace('/_Model_FP16|_Global_10K|-preview3\.1/', '', $r[0]))) . '</td><td>' . htmlspecialchars($r[1]) . '</td><td class="num">' . htmlspecialchars(fmt_setting($r[2])) . '</td><td class="num">' . htmlspecialchars(fmt_setting($r[3])) . '</td><td class="num">' . htmlspecialchars(fmt_setting($r[4])) . '</td><td class="num">' . htmlspecialchars($r[5] === '-' ? '-' : fmt_setting($r[5]) . ' s') . '</td>'
            . '<td><button type="button" class="stdbtn defuse" data-model="' . htmlspecialchars($r[0]) . '" data-v="' . htmlspecialchars(json_encode(array_slice($r, 2, 4))) . '" onclick="defUse(this)">' . t('str_use') . '</button></td></tr>'; } ?>
        </table>
        <div class="small" id="defusemsg" style="font-weight:600;margin-top:4px"></div>
        <style>.defuse:disabled { opacity: .4; cursor: default; } tr.gensep td { border-top: 3px solid rgba(0,0,0,.35) !important; } .modellist span b { font-size: inherit; }
          /* what each value does, in the page's normal size throughout (owner 2026-10-22: no small lines between) */
          .paramhelp { font-size: calc(1em / 0.85); } .paramhelp span { font-size: inherit !important; }</style>
        <script>
        // a row's values into the official model's fields; the button only for the model selected above (owner 2026-10-22)
        function defRefresh() {
          var m = document.getElementById('modelsel').value;
          document.querySelectorAll('.defuse').forEach(function (b) { b.disabled = b.dataset.model !== m; b.title = b.disabled ? t('str_only_for_the_model_selected_above') : ''; });
        }
        function defUse(b) {
          if (b.disabled) return;
          var v = JSON.parse(b.dataset.v), names = ['confidence', 'sensitivity', 'sf_thresh', 'overlap'];
          names.forEach(function (n, i) {
            var f = document.querySelector('input[name="' + n + '"]');
            if (f && v[i] !== '-') { f.value = v[i]; f.dispatchEvent(new Event('input', {bubbles: true})); f.dispatchEvent(new Event('change', {bubbles: true})); }
          });
          document.getElementById('defusemsg').textContent = t('str_values_filled_in_above_save_the_help');
        }
        document.getElementById('modelsel').addEventListener('change', defRefresh);
        defRefresh();
        </script>
        <p style="margin:8px 0 0"><b><?php echo t('str_thresholds_are_not_comparable_help'); ?></b></p>
        <?php // what changing each value does (owner 2026-10-22), right under the table ?>
        <div class="modellist paramhelp" style="margin-top:8px">
          <p><b><?php echo t('str_what_changing_each_value_does'); ?></b></p>
          <p><b><?php echo t('str_min_conf'); ?></b><br><span><?php echo t('str_higher_fewer_detections_and_help'); ?><br><?php echo t('str_lower_more_detections_and_more_help'); ?></span></p>
          <p><b><?php echo t('str_sigm_sens'); ?></b><br><span><?php echo t('str_higher_uncertain_sounds_get_a_help'); ?><br><?php echo t('str_lower_only_clear_songs_pass'); ?></span></p>
          <p><b><?php echo t('str_min_prob'); ?></b><br><span><?php echo t('str_higher_a_shorter_species_list_help'); ?><br><?php echo t('str_lower_a_longer_list_vagrants_help'); ?></span></p>
          <p><b><?php echo t('str_overlap'); ?></b><br><span><?php echo t('str_the_3_second_analysis_windows_help'); ?><br><?php echo t('str_more_more_windows_per_recording_help'); ?><br><?php echo t('str_0_the_fastest_but_a_call_split_help'); ?></span></p>
        </div>
</div>
      <p><b><?php echo t('str_models_colon'); ?></b></p>
      <div class="modellist">
        <p><b>BirdNET-Plus_V3.0-preview3.1_Global_10K (2026)</b><br>
          <span><?php echo t('str_developer_preview_of_the_next_help'); ?></span></p>
        <p><b>BirdNET_GLOBAL_6K_V2.4_Model_FP16 (2023)</b><br>
          <span><?php echo t('str_this_is_the_birdnet_analyzer_help'); ?><br><?php echo t('str_currently_it_supports_over_6_help'); ?></span></p>
        <p><b>BirdNET_6K_GLOBAL_MODEL (2020)</b><br>
          <span><?php echo t('str_this_is_the_birdnet_lite_model_help'); ?><br>
          <?php echo t('str_this_has_generally_worse_help'); ?></span></p>
        <p><?php echo t('str_in_depth_technical_write_up_on_help'); ?></p>
      </div>
      <style>.modellist p { margin: 0 0 10px; } .modellist span { display: inline-block; padding-left: 2em; font-size: .85em; }</style>
      <?php // the section's own Apply (owner 2026-11-05): the whole form is sent, as Update Settings did, and the services restart ?>
      <p style="margin-top:12px"><button type="submit" class="stdbtn" name="view" value="Model" title="<?php echo htmlspecialchars(t('str_services_restart'), ENT_QUOTES); ?>"
        onclick="if(document.getElementById('basicform').checkValidity()){this.innerHTML = t('str_updating_please_wait');this.classList.add('disabled')}"><?php echo t('str_apply'); ?></button></p>

      </td></tr></table><br>
      <?php // the recording length, a setting of the analysis (owner 2026-11-04: was on Station › Microphone); the three fields are
            // sent together by the section's Apply (owner 2026-11-05; were saved at once): settings_set.php restarts recording and analysis ?>
      <table class="settingstable"><tr><td>
      <h2 data-sec="model"><?php echo t('str_recording'); ?></h2>
      <?php // one sentence per line, an explicit <br>: the first help ends in a number ("60."), which static/sentence-lines.js does not take for the end of a sentence (release run 5, NEW-UX-R5-15) ?>
      <?php echo setting_field('RECORDING_LENGTH', t('str_recording_length'), array('min' => 3, 'max' => 60, 'unit' => 's', 'apply' => true),
        t('str_set_recording_length_in_seconds_help') . '<br>' . t('str_multiples_of_3_are_recommended_help')); ?>
      <?php // the clip cut from it, its length and format, beside the recording length (owner 2026-11-04) ?>
      <?php echo setting_field('EXTRACTION_LENGTH', t('str_extraction_length'), array('min' => 3, 'max' => 60, 'unit' => 's', 'apply' => true),
        t('str_set_extraction_length_to_help') . ' ' . t('str_min_3_max_recording_length')); ?>
      <?php echo setting_field('AUDIOFMT', t('str_extractions_audio_format'), array('apply' => true, 'choices' => array("8svx", "aif", "aifc", "aiff", "aiffc", "al", "amb", "amr-nb", "amr-wb", "anb", "au", "avr", "awb", "caf", "cdda", "cdr", "cvs", "cvsd", "cvu", "dat", "dvms", "f32", "f4", "f64", "f8", "fap", "flac", "fssd", "gsm", "gsrt", "hcom", "htk", "ima", "ircam", "la", "lpc", "lpc10", "lu", "mat", "mat4", "mat5", "maud", "mp2", "mp3", "nist", "ogg", "paf", "prc", "pvf", "raw", "s1", "s16", "s2", "s24", "s3", "s32", "s4", "s8", "sb", "sd2", "sds", "sf", "sl", "sln", "smp", "snd", "sndfile", "sndr", "sndt", "sou", "sox", "sph", "sw", "txw", "u1", "u16", "u2", "u24", "u3", "u32", "u4", "u8", "ub", "ul", "uw", "vms", "voc", "vorbis", "vox", "w64", "wav", "wavpcm", "wv", "wve", "xa", "xi"))); ?>
      <p style="margin-top:12px"><button type="button" class="stdbtn" onclick="bsApplySettings(this)" title="<?php echo htmlspecialchars(t('str_services_restart'), ENT_QUOTES); ?>"><?php echo t('str_apply'); ?></button></p>
      </td></tr></table><br>

      <table class="settingstable" style="width:100%"><tr><td>
      <h2 id="notifications" data-sec="notifications"><?php echo t('str_notifications_global'); ?></h2>
      <style>h3.vartitle { font-size: 1.2em; font-weight: 700; margin: 16px 0 6px; }</style>
      <p><?php echo t('str_apprise_notifications_can_be_help'); ?><br><?php echo t('str_each_service_should_be_on_its_help'); ?></p>
      <?php // the global options by theme (owner 2026-10-22): when a Normal species notifies (the Repetition limit under
      // "each new detection"), which species notify; the e-mail recipient lives only in Notifications › E-mail ?>
      <h3 class="vartitle"><?php echo t('str_when_a_normal_species_is_notified'); ?></h3>
      <?php // the boxes show what BirdSuite.conf says, also with no channel set up yet (2026-10-23 test run: an empty
      // Apprise box showed them off, and the next Update saved them off) ?>
      <input type="checkbox" name="apprise_notify_each_detection" <?php if($config['APPRISE_NOTIFY_EACH_DETECTION'] == 1) { echo "checked"; };?> >
      <label for="apprise_notify_each_detection"><?php echo t('str_notify_each_new_detection'); ?></label><br>
      <div style="margin:4px 0 8px 2em">
        <label for="minimum_time_limit"><?php echo t('str_repetition_limit_seconds_before_help'); ?></label>
        <input type="number" id="minimum_time_limit" name="minimum_time_limit" value="<?php echo $config['APPRISE_MIN_SEC_PER_SPECIES'];?>" style="width:6em;" min="0"> <?php echo t('str_0_no_limit'); ?><br>
        <small><?php echo t('str_also_applies_to_the_region_rare_help'); ?></small>
      </div>
      <input type="checkbox" name="apprise_notify_new_species_each_day" <?php if($config['APPRISE_NOTIFY_NEW_SPECIES_EACH_DAY'] == 1) { echo "checked"; };?> >
      <label for="apprise_notify_new_species_each_day"><?php echo t('str_notify_each_species_first_help'); ?></label><br>
      <input type="checkbox" name="apprise_notify_new_species" <?php if($config['APPRISE_NOTIFY_NEW_SPECIES'] == 1) { echo "checked"; };?> >
      <label for="apprise_notify_new_species"><?php echo t('str_notify_each_infrequent_species_help'); ?></label><br>
      <small><?php echo t('str_when_more_than_one_applies_one_help'); ?></small>
      <h3 class="vartitle"><?php echo t('str_which_species_are_notified'); ?></h3>
      <label for="notification_default_tier"><?php echo t('str_default_notification_tier_for_help'); ?></label>
      <select name="notification_default_tier" id="notification_default_tier" style="width:12em;">
        <?php $ndt = strtolower($config['NOTIFICATION_DEFAULT_TIER'] ?? 'normal');
        foreach (['muted' => 'str_none_muted', 'normal' => 'str_normal_tier', 'rare' => 'str_rare_tier'] as $t_val => $t_label) {
          $t_sel = $ndt === $t_val ? " selected" : "";
          echo "<option value='{$t_val}'{$t_sel}>" . t($t_label) . "</option>";
        } ?>
      </select><br>
      <label><?php echo t('str_per_species_notification_policy'); ?> </label>
      <a href="views.php?view=Species"><b><?php echo t('str_edit_the_species_list_in_help'); ?></b></a>:<br>
      &nbsp;&nbsp;<?php echo t('str_set_a_species_to_muted_to_help'); ?><br>
      &nbsp;&nbsp;<?php echo t('str_to_notify_only_selected_species_help'); ?>
<br>
      <h3 class="vartitle"><?php echo t('str_where_they_go'); ?></h3>
      <?php echo t('str_the_e_mail_recipient_is_set_in_help', array('email' => '<a style="text-decoration:underline" href="views.php?view=E-mail">' . menu_path('E-mail') . '</a>')); ?><br>
      <?php echo t('str_the_telegram_bot_and_groups_are_help', array('telegram' => '<a style="text-decoration:underline" href="views.php?view=Telegram">' . menu_path('Telegram') . '</a>')); ?><br>
      </td></tr></table><br>
      <?php // the variables in a section of their own, after the global options (owner 2026-10-22) ?>
      <table class="settingstable" style="width:100%"><tr><td>
      <h2 data-sec="notifications"><?php echo t('str_notification_variables'); ?></h2>
      <?php // the variables as a table, named after the app's columns (owner 2026-10-22, a rewrite: the names before are gone);
      // personal data never as an example (the e-mail address and the coordinates are generic)
      // the newest by Date, Time (run 5 RV2, NTC-06: detections is a view of the current location, it has no rowid)
      $ex_sci = (string)get_db()->querySingle('SELECT Sci_Name FROM detections ORDER BY Date DESC, Time DESC LIMIT 1') ?: 'Pitangus sulphuratus';
      $ex = array('Com_Name' => common_name($ex_sci), 'Sci_Name' => $ex_sci);
      $vrow = function ($names, $example, $desc) {
        return '<tr><td><code>' . implode('</code>, <code>', array_map('htmlspecialchars', (array)$names)) . '</code></td><td>' . htmlspecialchars($example) . '</td><td>' . $desc . '</td></tr>';
      };
      $vhead = '<tr><th>' . t('str_variable') . '</th><th>' . t('str_example') . '</th><th>' . t('str_description') . '</th></tr>'; ?>
      <p><?php echo t('str_each_name_in_the_title_or_the_help'); ?><br>
        <?php echo t('str_the_names_follow_the_columns_of_help'); ?></p>
      <style>.vartable td:first-child { white-space: nowrap; } .vartable td { vertical-align: top; text-align: left; } .vartable { margin-bottom: 8px; }
        h3.vartitle { font-size: 1.2em; font-weight: 700; margin: 16px 0 6px; } p.varnote { margin-bottom: 1em; }</style>
      <h3 class="vartitle"><?php echo t('str_title_and_body'); ?></h3>
      <table class="stdtable vartable" data-nosort>
        <?php echo $vhead
          . $vrow('$common_name', $ex['Com_Name'], t('str_common_name'))
          . $vrow('$scientific_name', $ex['Sci_Name'], t('str_scientific_name'))
          . $vrow('$reason', t('str_rare_species_lc'), t('str_the_reason_a_notification_was_sent'))
          . $vrow('$conf_pct', '91', t('str_confidence_conf_as_a_percentage'))
          . $vrow('$conf', '0.91', t('str_confidence_conf_as_a_number'))
          . $vrow('$conf_min_pct', '20%', t('str_minimum_confidence_percentage_help'))
          . $vrow('$conf_min', '0.2', t('str_minimum_confidence_number_help'))
          . $vrow('$conf_manual_pct', '60%', t('str_the_species_manual_confidence_help'))
          . $vrow('$occurrence_probability', '12.5%', t('str_probability_of_prob_help'))
          . $vrow('$occurrence_probability_min', '3%', t('str_minimum_probability_at_the_location_min_help'))
          . $vrow('$station', get_sitename(), t('str_the_stations_name_settings_stations'))
          . $vrow('$location', (string)((location_current() ?: array())['name'] ?? ''), t('str_the_location_the_detection_was_help') . '<br>' . t('str_a_text_without_this_tag_gets_a_help'))
          . $vrow('$date', date('Y-m-d'), t('str_date'))
          . $vrow('$time', date('H:i:s'), t('str_time'))
          . $vrow('$calendar_week', date('W'), t('str_iso_calendar_week'))
          . $vrow('$latitude', '-23.55', t('str_latitude'))
          . $vrow('$longitude', '-46.63', t('str_longitude'))
          . $vrow('$sigmoid_sensitivity', '1.0', t('str_sigmoid_sensitivity_sigm_sens'))
          . $vrow('$overlap', '1.2', t('str_overlap_in_seconds'))
          . $vrow('$recording_length', '15 s', t('str_recording_length_rec_length'))
          . $vrow('$listen_url', 'https://…?filename=…', t('str_a_link_to_the_detection'))
          . $vrow('$listen_link_markdown', '[' . t('str_listen_here') . '](…)', t('str_a_masked_link_to_the_detection') . '<br>' . t('str_only_useful_for_services_that_help'))
          . $vrow('$image', '', t('str_a_photo_of_the_species_attached_help'))
          . $vrow('$audio', '', t('str_attaches_the_detections_audio_help')); ?>
      </table>
      <h3 class="vartitle"><?php echo t('str_only_in_the_apprise_help'); ?></h3>
      <p class="varnote"><?php echo t('str_never_in_the_notification_title_help'); ?> <?php echo t('str_secrets_never_appear_on_this_page'); ?></p>
      <table class="stdtable vartable" data-nosort>
        <?php echo $vhead
          . $vrow(array('$user', '$hostname'), get_user() . ', ' . gethostname(), t('str_this_stations_user_and_hostname_help', array('example' => '<code>from=$user@$hostname.local</code>')))
          . $vrow('$email_recipient', 'you@example.com', t('str_the_recipient_set_in_help'))
          . $vrow(array('$email_user', '$email_password', '$email_sender'), '', t('str_the_sender_account_set_in_help'))
          . $vrow(array('$telegram_bot', '$telegram_group_normal', '$telegram_group_rare'), '', t('str_the_bot_and_the_groups_set_in_help'))
          . $vrow('$token1 … $token20', '', t('str_other_secret_values_from_the_help')); ?>
      </table>
      </td></tr></table><br>
      <table class="settingstable" style="width:100%"><tr><td>
      <h2 data-sec="notifications"><?php echo t('str_notifications_normal'); ?></h2>
      <label for="apprise_notification_title"><?php echo t('str_normal_notification_title'); ?> </label>
      <input name="apprise_notification_title" style="width: 100%" type="text" value="<?php print($config['APPRISE_NOTIFICATION_TITLE']);?>" /><br><br>
      <label for="apprise_notification_body"><?php echo t('str_normal_notification_body'); ?> </label>
      <textarea class="testbtn" name="apprise_notification_body" rows="18" style="width:100%; margin-top:0" type="text" ><?php print($apprise_notification_body);?></textarea><br><br>
      <?php // the two default channels, on or off, set up on their own pages (owner 2026-10-22: Telegram and e-mail always in sight)
      // on = set up and its test confirmed, as the channel's page and the sender decide (notify_channel_confirmed,
      // utils/notify_channels.py); set up but off or not confirmed yet = off, with the link to turn it on or test it there;
      // not set up = off, set up (run 5 RV2, NTC-07: an e-mail switched off was shown on)
      $channels = function ($text) {
        $one = function ($name, $line, $channel, $view) {
          $st = notify_channel_state($channel);
          if ($line && $st === 'confirmed') return $name . ' <b>' . t('str_on') . '</b>';
          $todo = !$line ? t('str_set_up') : ($st === 'off' ? t('str_turn_on_lc') : t('str_test_it_lc'));
          return $name . ' ' . t('str_off_lc') . ' (<a style="text-decoration:underline" href="views.php?view=' . rawurlencode($view) . '">' . $todo . '</a>)';
        };
        return '<div style="margin:4px 0 6px">' . t('str_channels') . ' ' . $one('Telegram', preg_match('~^\s*tgram://~mi', (string)$text), 'telegram', 'Telegram')
          . ' &middot; ' . $one(t('str_e_mail'), preg_match('~^\s*mailtos?://~mi', (string)$text), 'email', 'E-mail') . '</div>';
      };
      echo $channels($apprise_config ?? ''); ?>
      <label for="apprise_input"><?php echo t('str_apprise_notifications_configuration'); ?> </label>
      <textarea placeholder="tgram://$token1/$token2   (<?php echo t('str_token1_token20_secrets_help'); ?>)
mailto://{user}:{password}@gmail.com
twitter://{ConsumerKey}/{ConsumerSecret}/{AccessToken}/{AccessSecret}
https://discordapp.com/api/webhooks/{WebhookID}/{WebhookToken}
..." style="vertical-align: top; width:100%; margin-top:0" class="testbtn" name="apprise_input" rows="3" type="text" ><?php print($apprise_config);?></textarea><br>

      <br>

      <label><?php echo t('str_quiet_hours_normal_from'); ?> <input type="time" name="quiet_start_normal" value="<?php echo htmlspecialchars(trim($config['APPRISE_QUIET_START_NORMAL'] ?? '', '"')); ?>">
      <?php echo t('str_to_lc'); ?> <input type="time" name="quiet_end_normal" value="<?php echo htmlspecialchars(trim($config['APPRISE_QUIET_END_NORMAL'] ?? '', '"')); ?>"></label>
      <small><?php echo t('str_no_normal_notifications_in_this_help'); ?></small><br><br>
      <button type="button" class="stdbtn" onclick="sendTestNotification(this)"><?php echo t('str_send_test_notification'); ?></button><br>
      <span id="testsuccessmsg"></span>
      </td></tr></table><br>
      <table class="settingstable" style="width:100%"><tr><td>
      <h2 data-sec="notifications"><?php echo t('str_notifications_rare'); ?></h2>
      <?php // Region-rare alerts first; off = the rest of the box greyed, as BirdWeather (owner 2026-10-22); the fields stay
      // editable and are sent with the form ?>
      <?php $rre = ($config['APPRISE_NOTIFY_REGION_RARE'] ?? '1') == '1'; ?>
      <label><input type="checkbox" name="notify_region_rare" id="notify_region_rare" <?php echo $rre ? 'checked' : ''; ?> onchange="document.getElementById('rare_box').style.opacity = this.checked ? '' : '0.7'"> <?php echo t('str_region_rare_alerts'); ?></label>
      <small><?php echo t('str_any_species_the_location_model_help'); ?></small>
      <div id="rare_box" style="margin-top:1em;<?php echo $rre ? '' : 'opacity:0.7;'; ?>">
      <label for="apprise_notification_title_rare"><?php echo t('str_rare_notification_title'); ?> </label>
      <input name="apprise_notification_title_rare" style="width: 100%" type="text" placeholder="<?php echo t('str_empty_use_the_normal_title'); ?>" value="<?php print($config['APPRISE_NOTIFICATION_TITLE_RARE'] ?? '');?>" /><br><br>
      <label for="apprise_notification_body_rare"><?php echo t('str_rare_notification_body'); ?> </label>
      <textarea class="testbtn" name="apprise_notification_body_rare" rows="18" style="width:100%; margin-top:0" type="text" placeholder="<?php echo t('str_empty_use_the_normal_body'); ?>"><?php print($apprise_notification_body_rare);?></textarea><br><br>
      <?php echo $channels($apprise_config_rare ?? ''); ?>
      <label for="apprise_input_rare"><?php echo t('str_apprise_configuration_for_rare_help'); ?> </label>
      <textarea placeholder="tgram://$token1/$token3   (<?php echo t('str_token1_token20_placeholders_help'); ?>)
mailto://{user}:{password}@gmail.com
..." style="vertical-align: top; width:100%; margin-top:0" class="testbtn" name="apprise_input_rare" rows="3" type="text" ><?php print($apprise_config_rare);?></textarea><br>
      &nbsp;&nbsp;<?php echo t('str_empty_rare_apprise_title_body_help'); ?><br><br>
      <label><?php echo t('str_quiet_hours_rare_from'); ?> <input type="time" name="quiet_start_rare" value="<?php echo htmlspecialchars(trim($config['APPRISE_QUIET_START_RARE'] ?? '', '"')); ?>">
      <?php echo t('str_to_lc'); ?> <input type="time" name="quiet_end_rare" value="<?php echo htmlspecialchars(trim($config['APPRISE_QUIET_END_RARE'] ?? '', '"')); ?>"></label>
      <small><?php echo t('str_no_rare_notifications_in_this_help'); ?></small><br><br>
      <button type="button" class="stdbtn" onclick="sendTestNotification(this, 'apprise_input_rare', 'testsuccessmsgrare', 'apprise_notification_title_rare', 'apprise_notification_body_rare')"><?php echo t('str_send_test_notification_rare'); ?></button><br>
      <span id="testsuccessmsgrare"></span>
      </div>
      </td></tr></table><br>
      <table class="settingstable"><tr><td>
      <h2 data-sec="basic">BirdWeather</h2>
      <label for="birdweather_enabled"><?php echo t('str_upload_to_birdweather'); ?> </label>
      <?php $bwe = ($config['BIRDWEATHER_ENABLED'] ?? '0') != '0'; ?>
      <input type="checkbox" name="birdweather_enabled" id="birdweather_enabled" <?php echo $bwe ? 'checked' : ''; ?> onchange="birdweatherToggle(this.checked)">
<br>
      <?php // off = the rest of the section greyed, as BirdDB-Br (owner 2026-10-21); the token is read-only, not disabled, so
      // the form still sends it and it is kept ?>
      <div id="bw_box" style="margin-top:1em;<?php echo $bwe ? '' : 'opacity:0.7;'; ?>"><?php // a blank line between the switch and the token (owner 2026-10-22) ?>
      <?php // one token, or one per location (owner 2026-10-22): a BirdWeather station has its own coordinates, so a station
        // that moves between places uploads each place to its own BirdWeather station; Test / Playback never uploads
        $bw_pl = ($config['BIRDWEATHER_PER_LOCATION'] ?? '0') === '1'; ?>
      <label style="font-weight:normal"><input type="radio" name="birdweather_mode" value="one" <?php echo $bw_pl ? '' : 'checked'; ?> onchange="bwMode()"> <?php echo t('str_one_token_for_every_location'); ?></label><br>
      <label style="font-weight:normal"><input type="radio" name="birdweather_mode" value="location" <?php echo $bw_pl ? 'checked' : ''; ?> onchange="bwMode()"> <?php echo t('str_a_token_for_each_location'); ?></label>
      <div id="bw_one" style="margin-top:8px<?php echo $bw_pl ? ';display:none' : ''; ?>">
      <label for="birdweather_id"><?php echo t('str_birdweather_token'); ?> </label>
      <input name="birdweather_id" id="birdweather_id" type="text" value="<?php print($config['BIRDWEATHER_ID']);?>" <?php echo $bwe ? '' : 'readonly'; ?>/>
      </div>
      <div id="bw_loc" style="margin-top:8px<?php echo $bw_pl ? '' : ';display:none'; ?>">
      <table class="stdtable" style="width:auto"><tr><th><?php echo t('str_location'); ?></th><th data-nosort><?php echo t('str_birdweather_token'); ?></th></tr>
      <?php foreach (locations_load() as $bw_l) {
        echo '<tr><td>' . htmlspecialchars($bw_l['name']) . '</td><td>'
          . (($bw_l['builtin'] ?? '') === 'test' ? '<small>' . t('str_never_uploads_tests_and_playback') . '</small>'
            : '<input type="text" class="bwtok" name="bw_loc[' . intval($bw_l['id']) . ']" value="' . htmlspecialchars($bw_l['birdweather_id'] ?? '') . '" placeholder="' . htmlspecialchars(t('str_empty_this_location_does_not_upload')) . '" pattern="[A-Za-z0-9_\\-]{4,64}" title="' . htmlspecialchars(t('str_4_to_64_letters_digits_or')) . '" style="width:22em"' . ($bwe ? '' : ' readonly') . '>')
          . '</td></tr>';
      } ?>
      </table>
      <small><?php echo t('str_each_location_uploads_with_its_help'); ?></small>
      </div><br>
           <p><?php echo t('str_birdweather_com_is_a_weather_help'); ?>
        <?php echo t('str_stations_around_the_world_help'); ?>
        <?php echo t('str_birdweather_catalogues_the_bird_help'); ?> <br><br>
        <?php echo t('str_to_request_a_birdweather_token_help'); ?> <a href="https://app.birdweather.com/login" target="_blank">https://app.birdweather.com/</a><br>
        <?php echo t('str_once_thats_done_you_can_go_to_help'); ?> <a href="https://app.birdweather.com/account/stations" target="_blank">https://app.birdweather.com/account/stations</a><br>
        <?php echo t('str_make_sure_that_the_latitude_and_help'); ?>
        <br><br>
        <span style="display:block"><?php echo t('str_note_by_using_your_birdweather_help'); ?></span></p>
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
        onchange="birddbToggle(this.checked)"> <?php echo t('str_contribute_to_birddb_br_central_help'); ?></label>
      <small><?php echo t('str_off_default_no_clip_is_copied_help'); ?></small><br>
      <div id="birddb_box" style="margin-top:1em;<?php echo $bde ? '' : 'opacity:0.7;'; ?>"><?php // a paragraph before rclone (owner 2026-10-21) ?>
      <?php $srm = $config['SOUND_REPO_REMOTE'] ?? ''; $sru = $config['SOUND_REPO_UPLOAD_MINUTES'] ?? '0'; ?>
      <table class="settingstable plaintable">
        <tr>
          <td><label for="sound_repo_remote"><?php echo t('str_rclone_remote_remote_path'); ?></label></td>
          <td><input name="sound_repo_remote" class="birddb_field" type="text" style="width:14em;" value="<?php print(htmlspecialchars($srm, ENT_QUOTES));?>" pattern="[A-Za-z0-9_\-]+:.*" placeholder="birddb:" <?php echo $bde ? '' : 'disabled'; ?>/></td>
          <td>(<?php echo t('str_empty_no_upload_deposits_stay_help'); ?>)</td>
        </tr>
        <tr>
          <td><label for="sound_repo_upload_minutes"><?php echo t('str_upload_every'); ?></label></td>
          <td><input name="sound_repo_upload_minutes" class="birddb_field" type="number" style="width:5em;" min="0" max="1440" step="1" value="<?php print(intval($sru));?>" <?php echo $bde ? '' : 'disabled'; ?>/> <?php echo t('str_minutes'); ?></td>
          <td>(<?php echo t('str_0_never_the_spool_is_moved_to_help'); ?>)</td>
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


      <?php // Time and Date removed (owner 2026-10-22): see the save above ?>

      <br><br>

      <input type="hidden" name="status" value="success">
      <input type="hidden" name="submit" value="settings">
<?php // Station › Model has a button per section (owner 2026-11-05), so no Update Settings at the end ?>
<?php if (($config_section ?? 'all') !== 'model') { ?>
<div class="float">
      <button type="submit" id="basicformsubmit" onclick="if(document.getElementById('basicform').checkValidity()){this.innerHTML = t('str_updating_please_wait');this.classList.add('disabled')}" name="view" value="<?php echo in_array($_GET['view'] ?? '', array('Model', 'What and when'), true) ? $_GET['view'] : 'Sharing'; ?>">
<?php
if(isset($_GET['status'])){
  echo '<script>bsNotice(' . json_encode(t('str_settings_successfully_updated'), JSON_HEX_TAG | JSON_UNESCAPED_UNICODE) . ');</script>';
}
echo t('str_update_settings');
?>
      </button></div>
<?php } ?>
      </form>
</div>
<script>
// Settings › Model, Settings › Repositories and Notifications › Content are this one form (owner 2026-10-20): every field is
// still sent on Update, so nothing hidden is reset; only the sections of the menu entry are shown
(function () {
  var part = <?php echo json_encode($config_section ?? 'all'); ?>;
  if (part === 'all') return;
  // Repositories (BirdWeather and BirdDB-Br), Model and Content: each section heading carries a stable data-sec (model /
  // notifications / basic), so the headings can be translated (US-122)
  document.querySelectorAll('#basicform > table.settingstable').forEach(function (tb) {
    var h2 = tb.querySelector('h2');
    var sec = h2 && h2.getAttribute('data-sec');
    if (sec !== part) {
      tb.style.display = 'none'; var br = tb.nextElementSibling; if (br && br.tagName === 'BR') br.style.display = 'none';
      // a hidden field cannot be fixed by the user: it must not block Update (2026-10-23 test run: an empty hidden
      // latitude stopped the Repositories page without a word); the server keeps or checks the value as before
      tb.querySelectorAll('[required]').forEach(function (f) { f.removeAttribute('required'); });
    }
  });
})();
</script>
