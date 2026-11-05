<?php
/* First-run setup wizard (US-51c part 2). Shown by views.php instead of the Overview while
 * state/firstrun_pending (BS_FIRSTRUN) exists (an installation nobody answered: no terminal, no seed
 * file), and on demand from System › First Run. Same answers as the installer questions
 * (scripts/install_firstrun.sh). Included from views.php ($config, $home, $user available). */
require_once 'scripts/common.php';
require_once __DIR__ . '/presets.php';

$pending_file = BS_FIRSTRUN;
$pending = file_exists($pending_file);
// an unconfigured station without a web password is open on purpose; anything else needs the login
if (!($pending && empty($config['CADDY_PWD']))) {
  ensure_authenticated();
}

$model_v3 = 'BirdNET-Plus_V3.0-preview3.1_Global_10K';
$model_v24 = 'BirdNET_GLOBAL_6K_V2.4_Model_FP16';
$langs = array();
foreach (glob(BS_MODEL . '/l18n/labels_*.json') as $f) {
  $langs[] = preg_replace('/^labels_(.*)\.json$/', '$1', basename($f));
}
sort($langs);
$states = array();
foreach (glob(BS_MODEL . '/include_lists/BR-*.txt') as $f) {
  $states[] = substr(basename($f, '.txt'), 3);
}
sort($states);
$station_lists = array();
foreach (glob(BS_CUSTOM_LISTS . '/*.txt') as $f) {
  $station_lists[] = basename($f, '.txt');
}
sort($station_lists);
$timezones = DateTimeZone::listIdentifiers();
$current_tz = trim(shell_exec('timedatectl show --value --property=Timezone 2>/dev/null'));

// field_net.py as the station user, the password on stdin (the same call as scripts/network.php)
function wizard_field_net($args, $stdin) {
  $cmd = station_prefix() . 'python3 ' . escapeshellarg(BS_SCRIPTS . '/field_net.py');
  foreach ($args as $a) $cmd .= ' ' . escapeshellarg((string)$a);
  $p = proc_open($cmd, array(0 => array('pipe', 'r'), 1 => array('pipe', 'w'), 2 => array('pipe', 'w')), $pipes);
  if (!is_resource($p)) return array('ok' => false, 'error' => 'field_net.py');
  fwrite($pipes[0], $stdin . "\n");
  fclose($pipes[0]);
  $out = stream_get_contents($pipes[1]);
  fclose($pipes[1]); fclose($pipes[2]);
  proc_close($p);
  return json_decode((string)$out, true) ?: array('ok' => false, 'error' => trim((string)$out));
}

$errors = array();
$field_errors = array();
$saved = false;
if (isset($_POST['wizard_save'])) {
  $p = array_map(function ($v) { return is_string($v) ? trim(htmlspecialchars_decode($v, ENT_QUOTES)) : $v; }, $_POST);
  // control characters too: a newline would leave a second, executable line in BirdSuite.conf
  // no station or location name asked at the first run (owner 2026-10-20): BirdSuite-<last 4 of the MAC> and Home, changed later in
  // Settings › Locations (the location name) and System › Stations (the station name); a name already set is kept
  $site_name = ($config['SITE_NAME'] ?? '') !== '' ? str_replace(array('"', "'", '\\', '$', '`'), '', $config['SITE_NAME']) : default_sitename();
  $lat = $p['latitude'] ?? '';
  $lon = $p['longitude'] ?? '';
  if (!is_numeric($lat) || $lat < -90 || $lat > 90) $errors[] = t('str_latitude_must_be_a_number_help');
  if (!is_numeric($lon) || $lon < -180 || $lon > 180) $errors[] = t('str_longitude_must_be_a_number_help');
  $tz = $p['timezone'] ?? '';
  if (!in_array($tz, $timezones, true)) $errors[] = t('str_unknown_timezone');
  $model = ($p['model'] ?? '') === 'V2.4' ? $model_v24 : $model_v3;
  $lang = $p['language'] ?? 'en';
  if (!in_array($lang, $langs, true)) $errors[] = t('str_no_species_names_for_that_language');
  // species list filter, the same choice as Settings: '' none, a station list, BR-<UF>, or AUTO (the state
  // of the coordinates: OpenStreetMap online, the shipped IBGE boundaries offline)
  $sel = (string)($p['species_list'] ?? '');
  $state_detected = false;
  if ($sel === 'AUTO' && empty($errors)) {
    $uf = trim((string)shell_exec('python3 ' . escapeshellarg(BS_SCRIPTS . '/locate_state.py') . ' '
      . escapeshellarg((string)(float)$lat) . ' ' . escapeshellarg((string)(float)$lon) . ' 2>/dev/null'));
    $sel = in_array($uf, $states, true) ? "BR-$uf" : '';
    $state_detected = true;
  }
  if ($sel === 'AUTO') $sel = '';
  $is_state = preg_match('/^BR-[A-Z]{2}$/', $sel) && in_array(substr($sel, 3), $states, true);
  if ($sel !== '' && !$is_state && !in_array($sel, $station_lists, true)) $errors[] = t('str_no_such_species_list');
  $state = $is_state ? substr($sel, 3) : '';
  // step 6 (US-53): an open station (no password, no login dialog anywhere) or a password, who on the LAN opens the pages
  // without it, and how a forgotten password comes back
  $open_station = isset($p['open_station']);
  $pwd = $open_station ? '' : ($p['password'] ?? '');
  if ($pwd !== '' && !web_password_ok($pwd)) $errors[] = t('str_the_password_may_contain_help');
  if (!$open_station && $pwd !== ($p['password2'] ?? '')) $errors[] = t('str_the_two_passwords_differ');
  // Web login On needs a password of the station's own (bug #60): the factory one is never kept as the login
  if (!$open_station && (($pwd === '' && web_password_is_factory()) || hash_equals(BS_WEB_PASSWORD_DEFAULT, $pwd))) $errors[] = t('str_web_login_on_needs_a_password_help');
  $lan_trust = in_array($p['lan_trust'] ?? '', array('everyone', 'household'), true) ? $p['lan_trust'] : 'everyone';
  $recovery = (string)($p['pwd_recovery'] ?? 'sdcard');
  if (!in_array($recovery, array('email', 'telegram', 'sdcard'), true) || ($recovery !== 'sdcard' && !notify_channel_confirmed($recovery))) $recovery = 'sdcard';
  // step 3: how the station is used and where it runs; step 5: the alerts; step 1: the interface language
  $preset = in_array($p['ui_preset'] ?? '', array_keys(ui_presets()), true) ? $p['ui_preset'] : 'home';
  $ctx = array();
  foreach (array('home', 'field_phone', 'field_offline') as $c) if (isset($p['ctx_' . $c])) $ctx[] = $c;
  if (!$ctx) $ctx = array('home');
  $alerts_channel = in_array($p['alerts_channel'] ?? '', array('later', 'none', 'telegram', 'email'), true) ? $p['alerts_channel'] : 'later';
  $alerts_what = (string)($p['alerts_what'] ?? 'new');
  $ui_lang_new = isset(ui_languages()[$p['ui_lang'] ?? '']) ? $p['ui_lang'] : '';
  // boot location check (owner 2026-10-10): on/off; the distance is the location's radius (owner 2026-10-21)
  $loc_check = isset($p['location_check']) ? '1' : '0';
  // app colours (Settings › Theme; the custom theme is set later in Settings › Theme)
  $theme = strtolower($p['app_theme'] ?? 'ocean');
  if (!isset(theme_presets()[$theme])) $errors[] = t('str_unknown_colour_theme');
  $bw = $p['birdweather_id'] ?? '';
  if ($bw !== '' && !preg_match('/^[A-Za-z0-9]+$/', $bw)) $errors[] = t('str_invalid_birdweather_id');
  // the field network, asked here since the installer asks nothing (owner 2026-11-03): the phone hotspot (Plan B) and
  // the station WiFi password (Plan A, it goes on the QR label, so never the web password); empty = later in
  // Settings › Network; stored by field_net.py like the Network page does
  $fleet_ssid = (string)($p['fleet_ssid'] ?? '');
  $fleet_psk = (string)($p['fleet_psk'] ?? '');
  if ($fleet_ssid !== '') {
    if (mb_strlen($fleet_ssid) > 32 || preg_match('/[\x00-\x1f\x7f]/', $fleet_ssid)) $errors[] = t('str_the_network_name_needs_1_to_32_help');
    if (strlen($fleet_psk) < 8 || strlen($fleet_psk) > 63) $errors[] = t('str_the_password_needs_8_to_63_help');
    if ($fleet_psk !== (string)($p['fleet_psk2'] ?? '')) $errors[] = t('str_the_two_passwords_differ');
  }
  $ap_psk = (string)($p['ap_psk'] ?? '');
  if ($ap_psk !== '') {
    if (strlen($ap_psk) < 8 || strlen($ap_psk) > 63) $errors[] = t('str_the_password_needs_8_to_63_help');
    if ($ap_psk !== (string)($p['ap_psk2'] ?? '')) $errors[] = t('str_the_two_passwords_differ');
    if ($ap_psk === $pwd || ($pwd === '' && web_password_matches($ap_psk))) $errors[] = t('str_the_station_wifi_password_is_not_help');
  }

  if (empty($errors)) {
    $set = array();
    $old_model = $config['MODEL'] ?? '';
    $old_lang = $config['DATABASE_LANG'] ?? '';
    // the default name stays computed (bug #65): only a name of the owner's own is written
    $set['SITE_NAME'] = $site_name === default_sitename() ? '' : $site_name;
    $set['LATITUDE'] = round((float)$lat, 4);
    $set['LONGITUDE'] = round((float)$lon, 4);
    $set['MODEL'] = $model;
    if ($model !== $old_model) {
      // per-generation defaults: V3 = the values of factory_defaults.conf (2026-10-29: 0.2 / 0.03 of 2026-10-22 were
      // written over the calibrated defaults of v0.16.0); V2.4 = upstream 0.7 / 1.25 / 0.03 / 0.0
      $fd = birdsuite_defaults();
      $set['CONFIDENCE'] = $model === $model_v3 ? (string)$fd['CONFIDENCE'] : '0.7';
      $set['SENSITIVITY'] = $model === $model_v3 ? (string)$fd['SENSITIVITY'] : '1.25';
      $set['SF_THRESH'] = $model === $model_v3 ? (string)$fd['SF_THRESH'] : '0.03';
      $set['OVERLAP'] = $model === $model_v3 ? (string)$fd['OVERLAP'] : '0.0';
    }
    $set['DATABASE_LANG'] = $lang;
    $set['SPECIES_LIST'] = $sel;
    // Brazil: WikiAves, the factory default (2026-10-23 run 3: the wizard, the first-run questions and the defaults disagreed)
    $set['BIRDWEATHER_ID'] = $bw;
    // a token typed here is the owner's yes to upload (owner 2026-11-03: the token was saved and nothing was sent);
    // switched off again in Settings › Repositories
    if ($bw !== '' && $bw !== (string)($config['BIRDWEATHER_ID'] ?? '')) $set['BIRDWEATHER_ENABLED'] = '1';
    $set['APP_THEME'] = $theme;
    $set['LOCATION_CHECK'] = $loc_check;
    // simple mode (owner 2026-10-29, US-125): several locations / stations, else their controls stay hidden
    $set['MULTI_LOCATION'] = isset($p['multi_location']) ? '1' : '0';
    $set['MULTI_STATION'] = isset($p['multi_station']) ? '1' : '0';
    $update_caddy = false;
    if ($pwd !== '' && !web_password_matches($pwd)) {
      $set['CADDY_PWD'] = web_password_hash($pwd);  // kept only as its hash (SEC-15)
      $update_caddy = true;
    }
    // US-53: the access, the preset (only the keys still untouched), where it runs, what the alerts say, the language
    $set['WEB_LOGIN'] = $open_station ? 'off' : 'on';
    $set['LAN_TRUST'] = $lan_trust;
    if (!$open_station) $set['PWD_RECOVERY'] = $recovery;
    if ($set['WEB_LOGIN'] !== (string)($config['WEB_LOGIN'] ?? 'on') || $lan_trust !== (string)($config['LAN_TRUST'] ?? 'everyone')) $update_caddy = true;
    $set += preset_changes($preset, $config);
    $set['UI_CONTEXT'] = implode(',', $ctx);
    $set += alert_changes($alerts_what);
    if ($ui_lang_new !== '' && $ui_lang_new !== (string)($config['UI_LANG'] ?? '')) $set['UI_LANG'] = $ui_lang_new;
    // notification titles and bodies stay English (owner 2026-10-07)
  }
  // every value checked again by its key's rule (common.php conf_rules), all or nothing written
  if (empty($errors) && !conf_set_many($set, $err)) $errors[] = $err;
  if (empty($errors)) {
    // the place of these coordinates, named (owner 2026-10-20: station name and location name at the first run)
    location_set_named($lat, $lon, '');
    // without internet the clock comes from the phone that does the setup (US-120)
    set_time_from_client($p['client_epoch'] ?? 0);

    if ($tz !== $current_tz) {
      set_station_timezone($tz);
    }
    // another species list: a state is built once (its birds + the model's non-bird classes), then linked
    if ($sel !== ($config['SPECIES_LIST'] ?? '')) {
      shell_exec(station_prefix() . 'python3 ' . escapeshellarg(BS_SCRIPTS . '/select_species_list.py') . ' > /dev/null 2>&1');
    }
    if ($model !== $old_model || $lang !== $old_lang) {
      shell_exec(station_prefix() . '' . escapeshellarg(BS_SCRIPTS . '/install_language_label.sh') . ' > /dev/null 2>&1');
    }
    if ($update_caddy) exec("sudo -n " . BS_ROOT_HELPER . " caddyfile > /dev/null 2>&1 &");
    $field_errors = array();
    if ($fleet_ssid !== '') {
      $r = wizard_field_net(array('set-fleet', $fleet_ssid), $fleet_psk);
      if (empty($r['ok'])) $field_errors[] = t('str_phone_hotspot_not_saved', array('error' => (string)($r['error'] ?? '')));
    }
    if ($ap_psk !== '') {
      $r = wizard_field_net(array('set-ap'), $ap_psk);
      if (empty($r['ok'])) $field_errors[] = t('str_station_wifi_password_not_saved', array('error' => (string)($r['error'] ?? '')));
    }
    if (isset($set['UI_LANG'])) station_numeric_sync($set['UI_LANG'], $lang);
    if ($pending) @unlink($pending_file);
    shell_exec(station_prefix() . 'restart_services.sh > /dev/null 2>&1 &');
    $saved = true;
    $config = get_config($force_reload = true);
  }
}

$cur_lang = $config['DATABASE_LANG'] ?? 'en';
$cur_list = (string)($config['SPECIES_LIST'] ?? '');
// no list yet: offer the Brazilian state of the station's coordinates, preselected
$detected_state = '';
if ($cur_list === '' && is_numeric($config['LATITUDE'] ?? '') && is_numeric($config['LONGITUDE'] ?? '')) {
  $uf = trim((string)shell_exec('python3 ' . escapeshellarg(BS_SCRIPTS . '/locate_state.py') . ' '
    . escapeshellarg((string)(float)$config['LATITUDE']) . ' ' . escapeshellarg((string)(float)$config['LONGITUDE']) . ' 2>/dev/null'));
  if (in_array($uf, $states, true)) $detected_state = "BR-$uf";
}
$preselect = $cur_list !== '' ? $cur_list : $detected_state;
$cur_model = ($config['MODEL'] ?? '') === $model_v24 ? 'V2.4' : 'V3';
$h = function ($s) { return htmlspecialchars((string)$s, ENT_QUOTES); };
?>
<div class="settings">
<?php if ($saved) { ?>
  <table class="settingstable"><tr><td>
    <h2><?php echo t('str_saved'); ?></h2>
    <p><?php echo t('str_the_station_restarts_its_help'); ?>
    <?php echo $update_caddy ? t('str_detections_start_tools_help')
      : t('str_detections_start_appearing_on_help'); ?></p>
    <?php foreach ($field_errors as $e) { echo '<p style="color:red">' . $h($e) . '</p>'; } ?>
    <?php // the alert channel chosen at step 5: its guided setup now (US-53) ?>
    <?php if (in_array($alerts_channel ?? '', array('telegram', 'email'), true)) { $av = $alerts_channel === 'email' ? 'E-mail' : 'Telegram'; ?>
    <p><?php echo t('str_wiz_now_set_up_the_alerts_help'); ?> <a class="autolocate" href="views.php?view=<?php echo rawurlencode($av); ?>"><?php echo $h(menu_path($av)); ?></a></p>
    <?php } ?>
    <?php if ($state_detected) { echo '<p>' . t('str_species_list_from_the_coordinates') . ' <b>' . ($state === '' ? t('str_none_outside_brazil') : 'BR-' . $h($state)) . '</b>.</p>'; } ?>
  </td></tr></table>
<?php } else { ?>
<?php if ($pending) { ?>
  <p><?php echo t('str_welcome'); ?><br>
  <?php echo t('str_check_the_values_below_the_help'); ?></p>
<?php } ?>
<?php foreach ($errors as $e) { echo '<p style="color:red">' . $h($e) . '</p>'; } ?>
  <form method="POST" action="views.php?view=First%20Run" onsubmit="this.client_epoch.value = Math.floor(Date.now() / 1000)">
  <input type="hidden" name="client_epoch" value="">
  <?php if (!time_confirmed()) { ?><p style="background:#fff3cd;padding:8px;border-radius:8px">&#9200; <?php echo t('str_the_stations_saving_takes_help', array('time' => '<span id="devtime"></span>')); ?></p>
  <script>document.getElementById('devtime').textContent = new Date().toLocaleString(document.documentElement.lang || undefined);</script><?php } ?>
  <section class="wizstep" data-step="1"><table class="settingstable"><tr><td>
    <h2><span class="wiznum">1.</span> <?php echo t('str_wiz_your_language'); ?></h2>
    <?php // the interface language (US-122) and the species names, which follow it by default (owner 2026-11-04, US-53) ?>
    <label><?php echo t('str_interface_language'); ?> <select name="ui_lang" id="wiz_ui_lang">
<?php foreach (ui_languages() as $code => $name) echo '<option value="' . $h($code) . '"' . ($code === ui_lang() ? ' selected' : '') . '>' . $h($name) . '</option>'; ?>
    </select></label><br>
    <label><?php echo t('str_species_names'); ?> <select name="language" id="wiz_lang">
<?php
// the language names of Station › Model (common_name_languages(), run 5 RV2 SET-09: only pt_BR and pt_PT had a name)
$lang_names = common_name_languages();
foreach ($langs as $l) {
  $sel = $l === $cur_lang ? ' selected' : '';
  echo '<option value="' . $h($l) . '"' . $sel . '>' . $h(isset($lang_names[$l]) ? t($lang_names[$l]) : $l) . '</option>';
}
?>
    </select></label>
    <br><small><?php echo t('str_wiz_species_names_follow_help'); ?></small>
    <?php echo more_options('expert'); ?>
    <h3><?php echo t('str_theme'); ?></h3>
    <div class="wizthemes">
<?php $cur_theme = strtolower(trim($config['APP_THEME'] ?? 'ocean')); if (!isset(theme_presets()[$cur_theme])) $cur_theme = 'ocean';
  foreach (theme_presets() as $k => $t) {
    echo '<label class="wiztheme"><input type="radio" name="app_theme" value="' . $k . '"' . ($k === $cur_theme ? ' checked' : '') . ' data-colors="' . implode(',', array_slice($t, 1)) . '">'
      . '<span class="mock" style="background:' . $t[1] . '"><span class="mm" style="background:' . $t[2] . '"></span><span class="mp" style="background:' . $t[3] . '"><i style="background:' . $t[4] . '"></i><i style="background:' . $t[5] . '"></i></span></span>'
      . '<b>' . str_replace(' (', '<br>(', $h(t($t[0]))) . '</b></label>';
  } ?>
    </div>
    <small><?php echo t('str_colours_of_the_app_shown_at_help'); ?></small>
  </td></tr></table><br>
  <style>
    .wizthemes { display: flex; flex-wrap: wrap; gap: 10px; margin: 6px 0; }
    .wiztheme { display: flex; flex-direction: column; align-items: center; gap: 4px; cursor: pointer; padding: 6px; border-radius: 10px; border: 2px solid rgba(0,0,0,.08); }
    .wiztheme input { display: none; } .wiztheme:has(input:checked) { border-color: var(--accent, #2b5e22); box-shadow: 0 0 0 2px var(--accent, #2b5e22); }
    .wiztheme .mock { display: flex; width: 110px; height: 56px; border-radius: 6px; padding: 5px; gap: 5px; box-sizing: border-box; }
    .wiztheme .mm { width: 26%; border-radius: 3px; } .wiztheme .mp { flex: 1; border-radius: 3px; display: flex; align-items: flex-end; gap: 3px; padding: 4px; }
    .wiztheme .mp i { display: block; width: 20px; height: 9px; border-radius: 5px; }
  </style>
  <script>
    // a theme is shown on this page and on the frame around it as soon as it is picked; saved with the rest
    document.querySelectorAll('.wiztheme input').forEach(function (r) {
      r.addEventListener('change', function () {
        var c = r.dataset.colors.split(','), vars = ['--bg', '--menu', '--panel', '--accent', '--accent2'];
        [document, window.parent && window.parent.document].forEach(function (doc) {
          if (!doc) return;
          c.forEach(function (v, i) { doc.documentElement.style.setProperty(vars[i], v); });
          doc.documentElement.style.setProperty('--accent-rgb', [1, 3, 5].map(function (j) { return parseInt(c[3].substr(j, 2), 16); }).join(','));
        });
      });
    });
  </script>
    <?php echo more_options_end(); ?>
  </td></tr></table><br></section>
  <section class="wizstep" data-step="2"><table class="settingstable"><tr><td>
    <h2><span class="wiznum">2.</span> <?php echo t('str_wiz_where_is_the_station'); ?></h2>
    <?php $loc_here = location_current(); ?>
    <p><?php echo t('str_this_station_is_at', array('station' => $h(get_sitename()), 'location' => $h($loc_here ? $loc_here['name'] : t('str_home')))); ?>
      <small>(MAC <?php echo $h(station_mac()); ?>): <?php echo t('str_names_can_be_changed_later_in_help'); ?></small></p>
    <button type="button" class="autolocate" onclick="autoLocateNow(this)" title="<?php echo t('str_find_the_stations_network_help'); ?>"><?php echo t('str_auto_locate_now'); ?></button> <small id="autolocate_msg"></small><br>
    <script>
    // Auto Locate Now: the network position into the Latitude / Longitude fields of this form (save to keep it)
    function autoLocateNow(btn) {
      // the device's GPS first (owner 2026-10-29, as the map's GPS icon); the network position when no GPS position came
      if (window.bsLocPicker && window.bsLocPicker.gps) { window.bsLocPicker.gps(function () { autoLocateNet(btn); }); return; }
      autoLocateNet(btn);
    }
    function autoLocateNet(btn) {
      var msg = document.getElementById('autolocate_msg');
      msg.textContent = t('str_locating');
      btn.disabled = true;
      var x = new XMLHttpRequest();
      x.onload = function () {
        btn.disabled = false;
        var r; try { r = JSON.parse(this.responseText); } catch (e) { r = {error: this.status === 401 ? t('str_log_in_first_lc') : t('str_no_answer_lc')}; }
        if (r.error) { msg.textContent = t('str_not_located', {error: r.error}); return; }
        var lat = document.querySelector('input[name=latitude]'), lon = document.querySelector('input[name=longitude]');
        lat.value = Number(r.lat).toFixed(4); lon.value = Number(r.lon).toFixed(4);
        [lat, lon].forEach(function (i) { i.style.background = '#fff8c4'; });
        msg.textContent = t('str_km_from_filled_in_above_help', {where: r.where, km: r.distance_km})
          + (r.timezone ? ' ' + t('str_timezone_there', {tz: r.timezone}) : '');
      };
      x.open('GET', 'scripts/locate_now.php', true);
      x.send();
    }
    </script>
    <p><small><?php echo t('str_get_coordinates_with_auto_help'); ?></small></p>
    <label><?php echo t('str_latitude_colon'); ?> <input name="latitude" type="number" step="0.0001" min="-90" max="90" style="width:9em" value="<?php echo $h($config['LATITUDE'] ?? ''); ?>" required></label>
    <label><?php echo t('str_longitude_colon'); ?> <input name="longitude" type="number" step="0.0001" min="-180" max="180" style="width:9em" value="<?php echo $h($config['LONGITUDE'] ?? ''); ?>" required></label>
    <button type="button" class="autolocate" id="lp_btn" title="<?php echo t('str_pick_the_place_on_a_map_a_click_help'); ?>">&#128506; <?php echo t('str_map'); ?></button><br>
    <div id="lp_box"></div>
    <script src="static/species-modal.js?v=<?php echo @filemtime(__DIR__ . '/../homepage/static/species-modal.js'); ?>"></script>
    <script src="static/location-picker.js?v=<?php echo @filemtime(__DIR__ . '/../homepage/static/location-picker.js'); ?>"></script>
    <script>locationPicker(window.bsLocPicker = {lat: document.querySelector('input[name=latitude]'), lon: document.querySelector('input[name=longitude]'),
      button: document.getElementById('lp_btn'), box: document.getElementById('lp_box'), home: [<?php echo floatval($config['LATITUDE'] ?? 0) . ', ' . floatval($config['LONGITUDE'] ?? 0); ?>]});</script>
    <label><?php echo t('str_timezone_colon'); ?> <select name="timezone">
<?php foreach ($timezones as $tz) { echo '<option' . ($tz === $current_tz ? ' selected' : '') . '>' . $h($tz) . '</option>'; } ?>
    </select></label><br>
    <br>
    <label><?php echo t('str_species_list_filter'); ?> <select name="species_list">
      <option value=""<?php echo $preselect === '' ? ' selected' : ''; ?>><?php echo t('str_none_use_the_models_species_help'); ?></option>
      <option value="AUTO"><?php echo t('str_detect_the_brazilian_state_from_help'); ?></option>
<?php if ($station_lists) { echo '<optgroup label="' . $h(t('str_station_lists')) . '">';
  foreach ($station_lists as $n) { echo '<option value="' . $h($n) . '"' . ($n === $preselect ? ' selected' : '') . '>' . $h($n) . '</option>'; }
  echo '</optgroup>'; } ?>
      <optgroup label="<?php echo $h(t('str_brazilian_states_wikiaves_help')); ?>">
<?php foreach ($states as $uf) { $v = "BR-$uf"; if (in_array($v, $station_lists, true)) continue;
  echo '<option value="' . $v . '"' . ($v === $preselect ? ' selected' : '') . '>' . $v . ($v === $detected_state ? ' ' . t('str_from_the_coordinates') : '') . '</option>'; } ?>
      </optgroup>
    </select></label>
    <!-- the same explanations as Settings › Locations (owner 2026-10-10) -->
    <p><small>
            <?php echo t('str_none_every_species_the_models_help'); ?><br>
            <?php echo t('str_station_list_only_the_species_help'); ?><br>
            <?php echo t('str_brazilian_state_only_the_birds_help'); ?><br>
            <?php echo t('str_detect_the_brazilian_state_the_help'); ?>
    </small></p>
    <?php echo more_options('expert'); ?>
    <span title="<?php echo t('str_at_every_boot_the_station_help'); ?>"><label><?php echo t('str_auto_location'); ?> <input type="checkbox" name="location_check" <?php echo ($config['LOCATION_CHECK'] ?? '1') !== '0' ? 'checked' : ''; ?>> <?php echo t('str_enable'); ?></label>
    </span><br>
    <?php echo more_options_end(); ?>
  </td></tr></table><br></section>
  <section class="wizstep" data-step="3"><table class="settingstable"><tr><td>
    <h2><span class="wiznum">3.</span> <?php echo t('str_wiz_how_will_you_use'); ?></h2>
    <?php // an installed station opens on research (every page) so that saving again never hides pages; a new one on home
    $cur_preset = (string)($config['UI_PRESET'] ?? '') ?: ($pending ? 'home' : 'research'); $cur_ctx = explode(',', (string)($config['UI_CONTEXT'] ?? 'home') ?: 'home'); ?>
<?php foreach (array('home' => 'str_wiz_use_home', 'birder' => 'str_wiz_use_birder', 'research' => 'str_wiz_use_research') as $pk => $pl)
  echo '    <label class="wizopt"><input type="radio" name="ui_preset" value="' . $pk . '"' . ($cur_preset === $pk ? ' checked' : '') . '> ' . t($pl) . '</label>' . "\n"; ?>
    <div class="wizresearch">
    <p><label><input type="checkbox" name="multi_location" value="1"<?php echo multi_location() ? ' checked' : ''; ?>> <?php echo t('str_this_station_will_be_used_at_help'); ?></label><br>
      <label><input type="checkbox" name="multi_station" value="1"<?php echo multi_station() ? ' checked' : ''; ?>> <?php echo t('str_i_will_use_more_than_one_help'); ?></label><br>
      <small><?php echo t('str_unticked_the_controls_for_help'); ?></small></p>
    </div>
    <h3><?php echo t('str_wiz_where_will_it_run'); ?></h3>
<?php foreach (array('home' => 'str_wiz_run_home', 'field_phone' => 'str_wiz_run_field_phone', 'field_offline' => 'str_wiz_run_field_offline') as $ck => $cl)
  echo '    <label class="wizopt"><input type="checkbox" name="ctx_' . $ck . '" value="1" class="wizctx"' . (in_array($ck, $cur_ctx, true) ? ' checked' : '') . '> ' . t($cl) . '</label>' . "\n"; ?>
    <div class="wizfield">
    <h3><?php echo t('str_network_in_the_field'); ?></h3>
    <p><small><?php echo t('str_both_can_be_set_later_in_help'); ?> <a href="views.php?view=Network"><?php echo t('str_settings_network'); ?></a>.</small></p>
    <h3><?php echo t('str_in_the_field_with_internet_help'); ?></h3>
    <p><small><?php echo t('str_in_the_field_turn_the_hotspot_help'); ?><br><?php echo t('str_iphone_the_hotspot_name_is_short_help'); ?></small></p>
    <label><?php echo t('str_hotspot_name'); ?> <input name="fleet_ssid" type="text" maxlength="32" autocomplete="off" value="<?php echo $h($p['fleet_ssid'] ?? ''); ?>"></label><br>
    <label><?php echo t('str_password_colon'); ?> <input name="fleet_psk" type="password" minlength="8" maxlength="63" autocomplete="new-password"></label><br>
    <label><?php echo t('str_repeat'); ?> <input name="fleet_psk2" type="password" minlength="8" maxlength="63" autocomplete="new-password"></label><br>
    <h3><?php echo t('str_in_the_field_station_wifi_plan_help'); ?></h3>
    <p><small><?php echo t('str_the_station_wifi_ssid_its_password_help', array('ssid' => $h('BirdSuite' . (station_mac(true) !== '' ? '-' . station_mac(true) : '')))); ?></small></p>
    <label><?php echo t('str_password_colon'); ?> <input name="ap_psk" type="password" minlength="8" maxlength="63" autocomplete="new-password"></label><br>
    <label><?php echo t('str_repeat'); ?> <input name="ap_psk2" type="password" minlength="8" maxlength="63" autocomplete="new-password"></label><br>
    </div>
    <div class="wizshare">
    <h3><?php echo t('str_wiz_share_your_data'); ?></h3>
    <label><?php echo t('str_birdweather_id_optional'); ?> <input name="birdweather_id" type="text" value="<?php echo $h($config['BIRDWEATHER_ID'] ?? ''); ?>"></label><br>
    <small><?php echo t('str_with_a_token_the_recordings_help'); ?></small><br>
    </div>
    <?php echo more_options('expert'); ?>
    <label><?php echo t('str_model_colon'); ?> <select name="model">
      <option value="V3"<?php echo $cur_model === 'V3' ? ' selected' : ''; ?>>BirdNET+ V3.0 (<?php echo t('str_recommended_lc'); ?>)</option>
      <option value="V2.4"<?php echo $cur_model === 'V2.4' ? ' selected' : ''; ?>>BirdNET V2.4</option>
    </select></label><br>
    <?php echo more_options_end(); ?>
  </td></tr></table><br></section>
  <section class="wizstep" data-step="4"><table class="settingstable"><tr><td>
    <h2><span class="wiznum">4.</span> <?php echo t('str_wiz_test_the_microphone'); ?></h2>
    <?php require_once __DIR__ . '/audio_input.php'; if (!mic_devices()) echo '<p>' . t('str_no_microphone_found') . '</p>'; ?>
  <?php // the microphone first (owner 2026-11-03): with two or more, which one records, and its volume; the same controls
  // as Settings › Audio, saved at once (not by this form); a change of microphone reloads this page
  require_once __DIR__ . '/audio_input.php';
  if (mic_devices()) { ?>
    <h2><?php echo t('str_microphones'); ?></h2>
    <?php $mic_return = 'views.php?view=First%20Run&step=4'; include __DIR__ . '/mic_controls.php'; ?>
  <?php } ?>
  </td></tr></table><br></section>
  <section class="wizstep" data-step="5"><table class="settingstable"><tr><td>
    <h2><span class="wiznum">5.</span> <?php echo t('str_wiz_be_told_about_birds'); ?></h2>
<?php foreach (array('later' => 'str_wiz_alerts_later', 'none' => 'str_wiz_alerts_none', 'telegram' => 'str_telegram', 'email' => 'str_e_mail') as $ak => $al)
  echo '    <label class="wizopt"><input type="radio" name="alerts_channel" value="' . $ak . '"' . ($ak === 'later' ? ' checked' : '') . '> ' . t($al) . '</label>' . "\n"; ?>
    <h3><?php echo t('str_wiz_what_to_be_told'); ?></h3>
<?php foreach (array('new' => 'str_wiz_alerts_new', 'rare' => 'str_wiz_alerts_rare', 'first' => 'str_wiz_alerts_first', 'every' => 'str_wiz_alerts_every') as $wk => $wl)
  echo '    <label class="wizopt"><input type="radio" name="alerts_what" value="' . $wk . '"' . ($wk === 'new' ? ' checked' : '') . '> ' . t($wl) . '</label>' . "\n"; ?>
    <small><?php echo t('str_wiz_alerts_setup_after_help'); ?></small>
  </td></tr></table><br></section>
  <section class="wizstep" data-step="6"><table class="settingstable"><tr><td>
    <h2><span class="wiznum">6.</span> <?php echo t('str_wiz_protect_the_station'); ?></h2>
    <p><small><?php echo htmlspecialchars(t('str_letters_digits_and_pct_and_plus_help')); ?> <?php echo t('str_the_user_name_is_it_can_be_help', array('user' => htmlspecialchars(web_user()))); ?> <?php echo web_password_is_factory() ? t('str_the_station_still_has_the_help') : t('str_leave_both_current_password_help'); ?></small></p>
    <label><?php echo t('str_password_colon'); ?> <input name="password" type="password" autocomplete="new-password" pattern="<?php echo htmlspecialchars(web_password_pattern(), ENT_QUOTES); ?>"></label><br>
    <label><?php echo t('str_repeat'); ?> <input name="password2" type="password" autocomplete="new-password" pattern="<?php echo htmlspecialchars(web_password_pattern(), ENT_QUOTES); ?>"></label><br>
    <label class="wizopt"><input type="checkbox" name="open_station" value="1" id="wiz_open"<?php echo web_login_off() ? ' checked' : ''; ?>> <?php echo t('str_wiz_no_password_open_station'); ?></label>
    <div class="small"><?php echo t('str_web_login_off_help'); ?></div>
    <div class="wizprot">
    <label><?php echo t('str_on_your_network'); ?> <select name="lan_trust">
      <option value="everyone"<?php echo ($config['LAN_TRUST'] ?? 'everyone') !== 'household' ? ' selected' : ''; ?>><?php echo t('str_lan_trust_everyone'); ?></option>
      <option value="household"<?php echo ($config['LAN_TRUST'] ?? '') === 'household' ? ' selected' : ''; ?>><?php echo t('str_lan_trust_household'); ?></option>
    </select></label>
    <h3><?php echo t('str_how_will_you_recover_it'); ?></h3>
    <?php // e-mail and Telegram only once their test is confirmed on their page (none is set up yet at the first run) ?>
<?php foreach (array('sdcard' => 'str_recovery_sdcard', 'email' => 'str_recovery_email', 'telegram' => 'str_recovery_telegram') as $rk => $rl) {
  $ok = $rk === 'sdcard' || notify_channel_confirmed($rk);
  echo '    <label class="wizopt"><input type="radio" name="pwd_recovery" value="' . $rk . '"' . ((string)($config['PWD_RECOVERY'] ?? '') === $rk || ($rk === 'sdcard' && (string)($config['PWD_RECOVERY'] ?? '') === '') ? ' checked' : '') . ($ok ? '' : ' disabled') . '> ' . t($rl)
    . ($ok ? '' : ' <small>(' . $h(t('str_wiz_after_its_setup_in', array('page' => menu_path($rk === 'email' ? 'E-mail' : 'Telegram')))) . ')</small>') . '</label>' . "\n"; } ?>
    <small><?php echo t('str_other_ways_back_help'); ?></small>
    </div>
  </td></tr></table><br></section>
  <div class="wiznav"><button type="button" id="wiz_back" onclick="wizGo(-1)">&lsaquo; <?php echo t('str_back'); ?></button>
    <span id="wiz_progress"></span>
    <button type="button" id="wiz_next" onclick="wizGo(1)"><?php echo t('str_next'); ?> &rsaquo;</button>
    <button type="submit" name="wizard_save" value="1" id="wiz_save"><?php echo t('str_save_and_start'); ?></button></div>
  </form>
  <style>
    .wizopt { display: block; margin: 4px 0; } .wiznum { opacity: .6; }
    .wiznav { display: flex; gap: 10px; align-items: center; flex-wrap: wrap; margin: 6px 0 16px; }
    .wizstepping .wizstep { display: none; } .wizstepping .wizstep.cur { display: block; }
  </style>
  <script>
  // six steps on one page, one save at the end (US-53): without script, or with an error to correct, every step shows
  (function () {
    var form = document.querySelector('.settings form'), steps = [].slice.call(document.querySelectorAll('.wizstep')), cur = 0;
    var hasErrors = <?php echo empty($errors) ? 'false' : 'true'; ?>;
    function show() {
      steps.forEach(function (s, i) { s.classList.toggle('cur', i === cur); });
      document.getElementById('wiz_back').style.visibility = cur === 0 ? 'hidden' : 'visible';
      document.getElementById('wiz_next').style.display = cur === steps.length - 1 ? 'none' : '';
      document.getElementById('wiz_save').style.display = cur === steps.length - 1 ? '' : 'none';
      document.getElementById('wiz_progress').textContent = t('str_wiz_step_n_of', {n: cur + 1, total: steps.length});
      window.scrollTo(0, 0);
    }
    window.wizGo = function (d) {
      if (d > 0) {
        // the fields of this step must be right before going on
        var bad = [].slice.call(steps[cur].querySelectorAll('input,select')).filter(function (i) { return !i.disabled && !i.checkValidity(); });
        if (bad.length) { bad[0].reportValidity(); return; }
      }
      cur = Math.max(0, Math.min(steps.length - 1, cur + d)); show();
    };
    // a change of microphone reloads the page (US-53, owner 2026-11-04): the answers given so far are kept in this tab
    // (never the passwords) and the page comes back on the step it was on
    var KEY = 'bs_firstrun_answers';
    var save = function () {
      var d = {};
      [].slice.call(form.elements).forEach(function (e) {
        if (!e.name || e.type === 'password' || e.type === 'hidden' || e.type === 'submit' || e.type === 'button') return;
        if (e.type === 'radio') { if (e.checked) d[e.name] = e.value; }
        else if (e.type === 'checkbox') d[e.name] = e.checked;
        else d[e.name] = e.value;
      });
      try { sessionStorage.setItem(KEY, JSON.stringify(d)); } catch (x) {}
    };
    var back = /[?&]step=(\d)/.exec(location.search);
    if (back) {
      var d = null; try { d = JSON.parse(sessionStorage.getItem(KEY) || 'null'); } catch (x) {}
      if (d) [].slice.call(form.elements).forEach(function (e) {
        if (!(e.name in d) || e.type === 'password') return;
        if (e.type === 'radio') e.checked = e.value === d[e.name];
        else if (e.type === 'checkbox') e.checked = !!d[e.name];
        else e.value = d[e.name];
      });
      cur = Math.max(0, Math.min(steps.length - 1, parseInt(back[1], 10) - 1));
    } else {
      try { sessionStorage.removeItem(KEY); } catch (x) {}
    }
    form.addEventListener('change', save); form.addEventListener('input', save);
    form.addEventListener('submit', function () { try { sessionStorage.removeItem(KEY); } catch (x) {} });
    if (!hasErrors) { form.classList.add('wizstepping'); show(); }
    else { document.getElementById('wiz_next').style.display = 'none'; document.getElementById('wiz_back').style.display = 'none'; }
    // the research answers, the field network and the data sharing show only when they apply
    function apply() {
      var p = (form.querySelector('input[name=ui_preset]:checked') || {}).value || 'home';
      form.querySelector('.wizresearch').style.display = p === 'research' ? '' : 'none';
      form.querySelector('.wizshare').style.display = p === 'home' ? 'none' : '';
      var field = [].slice.call(form.querySelectorAll('.wizctx')).some(function (c) { return c.checked && c.name !== 'ctx_home'; });
      form.querySelector('.wizfield').style.display = field ? '' : 'none';
      var open = document.getElementById('wiz_open').checked;
      form.querySelector('.wizprot').style.display = open ? 'none' : '';
      form.querySelectorAll('input[name=password],input[name=password2]').forEach(function (i) { i.disabled = open; });
    }
    form.addEventListener('change', apply); apply();
  })();
  </script>
<?php } ?>
</div>
