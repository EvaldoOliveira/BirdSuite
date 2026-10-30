<?php
/* First-run setup wizard (US-51c part 2). Shown by views.php instead of the Overview while
 * ~/BirdNET-Pi/state/firstrun_pending exists (an installation nobody answered: no terminal, no seed
 * file), and on demand from Tools -> Setup Wizard. Same answers as the installer questions
 * (scripts/install_firstrun.sh). Included from views.php ($config, $home, $user available). */
require_once 'scripts/common.php';

$pending_file = $home . '/BirdNET-Pi/state/firstrun_pending';
$pending = file_exists($pending_file);
// an unconfigured station without a web password is open on purpose; anything else needs the login
if (!($pending && empty($config['CADDY_PWD']))) {
  ensure_authenticated();
}

$model_v3 = 'BirdNET-Plus_V3.0-preview3.1_Global_10K';
$model_v24 = 'BirdNET_GLOBAL_6K_V2.4_Model_FP16';
$langs = array();
foreach (glob($home . '/BirdNET-Pi/model/l18n/labels_*.json') as $f) {
  $langs[] = preg_replace('/^labels_(.*)\.json$/', '$1', basename($f));
}
sort($langs);
$states = array();
foreach (glob($home . '/BirdNET-Pi/model/include_lists/BR-*.txt') as $f) {
  $states[] = substr(basename($f, '.txt'), 3);
}
sort($states);
$station_lists = array();
foreach (glob($home . '/BirdNET-Pi/config/species_lists/custom/*.txt') as $f) {
  $station_lists[] = basename($f, '.txt');
}
sort($station_lists);
$timezones = DateTimeZone::listIdentifiers();
$current_tz = trim(shell_exec('timedatectl show --value --property=Timezone 2>/dev/null'));

function wizard_set_key($contents, $key, $value) {
  if (preg_match("/^$key=/m", $contents)) {
    return preg_replace("/^$key=.*/m", "$key=" . str_replace('$', '\$', $value), $contents);
  }
  return rtrim($contents, "\n") . "\n$key=$value\n";
}

$errors = array();
$saved = false;
if (isset($_POST['wizard_save'])) {
  $p = array_map(function ($v) { return is_string($v) ? trim(htmlspecialchars_decode($v, ENT_QUOTES)) : $v; }, $_POST);
  // control characters too: a newline would leave a second, executable line in BirdSuite.conf
  // no station or location name asked at the first run (owner 2026-10-20): BirdSuite-<MAC> and Home, changed later in
  // Basic Settings; a name already set is kept
  $site_name = ($config['SITE_NAME'] ?? '') !== '' ? str_replace(array('"', "'", '\\', '$', '`'), '', $config['SITE_NAME']) : default_sitename();
  $lat = $p['latitude'] ?? '';
  $lon = $p['longitude'] ?? '';
  if (!is_numeric($lat) || $lat < -90 || $lat > 90) $errors[] = t('Latitude must be a number between -90 and 90');
  if (!is_numeric($lon) || $lon < -180 || $lon > 180) $errors[] = t('Longitude must be a number between -180 and 180');
  $tz = $p['timezone'] ?? '';
  if (!in_array($tz, $timezones, true)) $errors[] = t('Unknown timezone');
  $model = ($p['model'] ?? '') === 'V2.4' ? $model_v24 : $model_v3;
  $lang = $p['language'] ?? 'en';
  if ($lang === 'pt') $lang = 'pt_BR';
  if (!in_array($lang, $langs, true)) $errors[] = t('No species names for that language');
  // species list filter, the same choice as Settings: '' none, a station list, BR-<UF>, or AUTO (the state
  // of the coordinates: OpenStreetMap online, the shipped IBGE boundaries offline)
  $sel = (string)($p['species_list'] ?? '');
  $state_detected = false;
  if ($sel === 'AUTO' && empty($errors)) {
    $uf = trim((string)shell_exec('python3 ' . escapeshellarg($home . '/BirdNET-Pi/scripts/locate_state.py') . ' '
      . escapeshellarg((string)(float)$lat) . ' ' . escapeshellarg((string)(float)$lon) . ' 2>/dev/null'));
    $sel = in_array($uf, $states, true) ? "BR-$uf" : '';
    $state_detected = true;
  }
  if ($sel === 'AUTO') $sel = '';
  $is_state = preg_match('/^BR-[A-Z]{2}$/', $sel) && in_array(substr($sel, 3), $states, true);
  if ($sel !== '' && !$is_state && !in_array($sel, $station_lists, true)) $errors[] = t('No such species list');
  $state = $is_state ? substr($sel, 3) : '';
  $pwd = $p['password'] ?? '';
  if ($pwd !== '' && !web_password_ok($pwd)) $errors[] = t('The password may contain letters, digits and ! @ # % ^ & * ( ) - _ = + [ ] { } . , : ? ~ /');
  if ($pwd !== ($p['password2'] ?? '')) $errors[] = t('The two passwords differ');
  // boot location check (owner 2026-10-10): on/off; the distance is the location's radius (owner 2026-10-21)
  $loc_check = isset($p['location_check']) ? '1' : '0';
  // app colours (Settings › Theme; the custom theme is set later in Settings › Theme)
  $theme = strtolower($p['app_theme'] ?? 'ocean');
  if (!isset(theme_presets()[$theme])) $errors[] = t('Unknown colour theme');
  $bw = $p['birdweather_id'] ?? '';
  if ($bw !== '' && !preg_match('/^[A-Za-z0-9]+$/', $bw)) $errors[] = t('Invalid BirdWeather ID');

  if (empty($errors)) {
    $contents = file_get_contents(BS_CONF);
    $old_model = $config['MODEL'] ?? '';
    $old_lang = $config['DATABASE_LANG'] ?? '';
    $contents = wizard_set_key($contents, 'SITE_NAME', "\"$site_name\"");
    $contents = wizard_set_key($contents, 'LATITUDE', round((float)$lat, 4));
    $contents = wizard_set_key($contents, 'LONGITUDE', round((float)$lon, 4));
    $contents = wizard_set_key($contents, 'MODEL', $model);
    if ($model !== $old_model) {
      // per-generation defaults: V3 = the values of factory_defaults.conf (2026-10-29: 0.2 / 0.03 of 2026-10-22 were
      // written over the calibrated defaults of v0.16.0); V2.4 = upstream 0.7 / 1.25 / 0.03 / 0.0
      $fd = birdsuite_defaults();
      $contents = wizard_set_key($contents, 'CONFIDENCE', $model === $model_v3 ? (string)$fd['CONFIDENCE'] : '0.7');
      $contents = wizard_set_key($contents, 'SENSITIVITY', $model === $model_v3 ? (string)$fd['SENSITIVITY'] : '1.25');
      $contents = wizard_set_key($contents, 'SF_THRESH', $model === $model_v3 ? (string)$fd['SF_THRESH'] : '0.03');
      $contents = wizard_set_key($contents, 'OVERLAP', $model === $model_v3 ? (string)$fd['OVERLAP'] : '0.0');
    }
    $contents = wizard_set_key($contents, 'DATABASE_LANG', $lang);
    $contents = wizard_set_key($contents, 'SPECIES_LIST', $sel);
    // Brazil: WikiAves, the factory default (2026-10-23 run 3: the wizard, the first-run questions and the defaults disagreed)
    if (strpos($lang, 'pt') === 0 || $state !== '') $contents = wizard_set_key($contents, 'INFO_SITE', '"WIKIAVES"');
    $contents = wizard_set_key($contents, 'BIRDWEATHER_ID', $bw);
    $contents = wizard_set_key($contents, 'APP_THEME', "\"$theme\"");
    $contents = wizard_set_key($contents, 'LOCATION_CHECK', $loc_check);
    // simple mode (owner 2026-10-29, US-125): several locations / stations, else their controls stay hidden
    $contents = wizard_set_key($contents, 'MULTI_LOCATION', isset($p['multi_location']) ? '1' : '0');
    $contents = wizard_set_key($contents, 'MULTI_STATION', isset($p['multi_station']) ? '1' : '0');
    $update_caddy = false;
    if ($pwd !== '' && $pwd !== ($config['CADDY_PWD'] ?? '')) {
      $contents = wizard_set_key($contents, 'CADDY_PWD', "\"$pwd\"");
      $update_caddy = true;
    }
    // notification titles and bodies stay English (owner 2026-10-07)
    file_put_contents(BS_CONF, $contents);
    // the place of these coordinates, named (owner 2026-10-20: station name and location name at the first run)
    location_set_named($lat, $lon, '');
    // without internet the clock comes from the phone that does the setup (US-120)
    set_time_from_client($p['client_epoch'] ?? 0);

    if ($tz !== $current_tz) {
      set_station_timezone($tz);
    }
    // another species list: a state is built once (its birds + the model's non-bird classes), then linked
    if ($sel !== ($config['SPECIES_LIST'] ?? '')) {
      shell_exec(station_prefix() . 'python3 ' . escapeshellarg($home . '/BirdNET-Pi/scripts/select_species_list.py') . ' > /dev/null 2>&1');
    }
    if ($model !== $old_model || $lang !== $old_lang) {
      shell_exec(station_prefix() . '' . escapeshellarg($home . '/BirdNET-Pi/scripts/install_language_label.sh') . ' > /dev/null 2>&1');
    }
    if ($update_caddy) exec("sudo -n /usr/local/sbin/birdsuite-root caddyfile > /dev/null 2>&1 &");
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
  $uf = trim((string)shell_exec('python3 ' . escapeshellarg($home . '/BirdNET-Pi/scripts/locate_state.py') . ' '
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
    <h2><?php echo t('Saved'); ?></h2>
    <p><?php echo t('The station restarts its services with the new settings.'); ?>
    <?php echo $update_caddy ? t('Detections start appearing on the <a href="views.php?view=Now">Latest</a> in a few minutes; Tools and Settings now ask for the user <b>birdsuite</b> and your password.')
      : t('Detections start appearing on the <a href="views.php?view=Now">Latest</a> in a few minutes.'); ?></p>
    <?php if ($state_detected) { echo '<p>' . t('Species list from the coordinates:') . ' <b>' . ($state === '' ? t('none (outside Brazil)') : 'BR-' . $h($state)) . '</b>.</p>'; } ?>
  </td></tr></table>
<?php } else { ?>
<?php if ($pending) { ?>
  <p><?php echo t('Welcome!'); ?><br><?php echo t('This station was installed without answering the first-run questions.'); ?><br>
  <?php echo t('Check the values below (the location, timezone and state were guessed from the network) and save to start detecting.'); ?></p>
<?php } ?>
<?php foreach ($errors as $e) { echo '<p style="color:red">' . $h($e) . '</p>'; } ?>
  <form method="POST" action="views.php?view=Setup" onsubmit="this.client_epoch.value = Math.floor(Date.now() / 1000)">
  <input type="hidden" name="client_epoch" value="">
  <?php if (!time_confirmed()) { ?><p style="background:#fff3cd;padding:8px;border-radius:8px">&#9200; <?php echo t('The station\'s clock is not confirmed (no internet): saving takes the time of this device ({time}).', array('time' => '<span id="devtime"></span>')); ?></p>
  <script>document.getElementById('devtime').textContent = new Date().toLocaleString(document.documentElement.lang || undefined);</script><?php } ?>
  <p><small><?php echo t('Network in the field (station WiFi, the fleet phone hotspot, a WiFi of this place, the QR label):'); ?> <a href="views.php?view=Network"><?php echo t('Settings › Network'); ?></a>.</small></p>
  <table class="settingstable"><tr><td>
    <h2><?php echo t('Station'); ?></h2>
    <?php $loc_here = location_current(); ?>
    <p><?php echo t('This station is <b>{station}</b> at <b>{location}</b>', array('station' => $h(get_sitename()), 'location' => $h($loc_here ? $loc_here['name'] : t('Home')))); ?>
      <small>(MAC <?php echo $h(station_mac()); ?>): <?php echo t('names can be changed later in Settings › Stations and Settings › Locations'); ?></small></p>
    <?php // simple mode (owner 2026-10-29, US-125): unticked = the controls of locations / stations hidden, Tools › Defaults switches them later ?>
    <p><label><input type="checkbox" name="multi_location" value="1"<?php echo multi_location() ? ' checked' : ''; ?>> <?php echo t('This station will be used at more than one location (home, farm, trips)'); ?></label><br>
      <label><input type="checkbox" name="multi_station" value="1"<?php echo multi_station() ? ' checked' : ''; ?>> <?php echo t('I will use more than one BirdSuite station'); ?></label><br>
      <small><?php echo t('Unticked, the controls for several locations or stations stay hidden; switch them on later in Tools › Defaults.'); ?></small></p>
    <span title="<?php echo t('At every boot the station compares its approximate network position (public IP geolocation: ipapi.co / ip-api.com) with these coordinates; after a move of more than the move distance (Settings › Locations, default 50 km) the Latest page asks whether to use the new location (coordinates, Brazilian state species list, timezone). Nothing changes without your answer.'); ?>"><label><?php echo t('Auto location:'); ?> <input type="checkbox" name="location_check" <?php echo ($config['LOCATION_CHECK'] ?? '1') !== '0' ? 'checked' : ''; ?>> <?php echo t('Enable'); ?></label>
    </span><br>
    <button type="button" class="autolocate" onclick="autoLocateNow(this)" title="<?php echo t('Find the station\'s network position now and put it in Latitude / Longitude'); ?>"><?php echo t('Auto Locate Now'); ?></button> <small id="autolocate_msg"></small><br>
    <script>
    // Auto Locate Now: the network position into the Latitude / Longitude fields of this form (save to keep it)
    function autoLocateNow(btn) {
      // the device's GPS first (owner 2026-10-29, as the map's GPS icon); the network position when no GPS position came
      if (window.bsLocPicker && window.bsLocPicker.gps) { window.bsLocPicker.gps(function () { autoLocateNet(btn); }); return; }
      autoLocateNet(btn);
    }
    function autoLocateNet(btn) {
      var msg = document.getElementById('autolocate_msg');
      msg.textContent = t('Locating…');
      btn.disabled = true;
      var x = new XMLHttpRequest();
      x.onload = function () {
        btn.disabled = false;
        var r; try { r = JSON.parse(this.responseText); } catch (e) { r = {error: this.status === 401 ? t('log in first') : t('no answer')}; }
        if (r.error) { msg.textContent = t('Not located: {error}', {error: r.error}); return; }
        var lat = document.querySelector('input[name=latitude]'), lon = document.querySelector('input[name=longitude]');
        lat.value = Number(r.lat).toFixed(4); lon.value = Number(r.lon).toFixed(4);
        [lat, lon].forEach(function (i) { i.style.background = '#fff8c4'; });
        msg.textContent = t('{where}: {km} km from the saved coordinates; filled in above, save to keep it', {where: r.where, km: r.distance_km})
          + (r.timezone ? ' ' + t('(timezone there: {tz})', {tz: r.timezone}) : '');
      };
      x.open('GET', 'scripts/locate_now.php', true);
      x.send();
    }
    </script>
    <p><small><?php echo t('Get coordinates with <b>Auto Locate Now</b> or on <a href="https://latlong.net" target="_blank">latlong.net</a>; detections are stamped with the timezone below.'); ?></small></p>
    <label><?php echo t('Latitude:'); ?> <input name="latitude" type="number" step="0.0001" min="-90" max="90" style="width:9em" value="<?php echo $h($config['LATITUDE'] ?? ''); ?>" required></label>
    <label><?php echo t('Longitude:'); ?> <input name="longitude" type="number" step="0.0001" min="-180" max="180" style="width:9em" value="<?php echo $h($config['LONGITUDE'] ?? ''); ?>" required></label>
    <button type="button" class="autolocate" id="lp_btn" title="<?php echo t('Pick the place on a map: a click (or dragging the pin) fills latitude and longitude'); ?>">&#128506; <?php echo t('Map'); ?></button><br>
    <div id="lp_box"></div>
    <script src="static/species-modal.js?v=<?php echo @filemtime(__DIR__ . '/../homepage/static/species-modal.js'); ?>"></script>
    <script src="static/location-picker.js?v=<?php echo @filemtime(__DIR__ . '/../homepage/static/location-picker.js'); ?>"></script>
    <script>locationPicker(window.bsLocPicker = {lat: document.querySelector('input[name=latitude]'), lon: document.querySelector('input[name=longitude]'),
      button: document.getElementById('lp_btn'), box: document.getElementById('lp_box'), home: [<?php echo floatval($config['LATITUDE'] ?? 0) . ', ' . floatval($config['LONGITUDE'] ?? 0); ?>]});</script>
    <label><?php echo t('Timezone:'); ?> <select name="timezone">
<?php foreach ($timezones as $tz) { echo '<option' . ($tz === $current_tz ? ' selected' : '') . '>' . $h($tz) . '</option>'; } ?>
    </select></label><br>
    <br>
    <label><?php echo t('BirdWeather ID (optional):'); ?> <input name="birdweather_id" type="text" value="<?php echo $h($config['BIRDWEATHER_ID'] ?? ''); ?>"></label><br>
  </td></tr></table><br>
  <table class="settingstable"><tr><td>
    <h2><?php echo t('Detection'); ?></h2>
    <label><?php echo t('Model:'); ?> <select name="model">
      <option value="V3"<?php echo $cur_model === 'V3' ? ' selected' : ''; ?>>BirdNET+ V3.0 (<?php echo t('recommended'); ?>)</option>
      <option value="V2.4"<?php echo $cur_model === 'V2.4' ? ' selected' : ''; ?>>BirdNET V2.4</option>
    </select></label><br>
    <label><?php echo t('Species names:'); ?> <select name="language" id="wiz_lang">
<?php
$lang_names = array('pt_BR' => t('Portuguese Brazil (CBRO)'), 'pt_PT' => t('Portuguese Portugal'));
foreach ($langs as $l) {
  $sel = ($l === $cur_lang || ($l === 'pt_BR' && $cur_lang === 'pt')) ? ' selected' : '';
  echo '<option value="' . $h($l) . '"' . $sel . '>' . $h($lang_names[$l] ?? $l) . '</option>';
}
?>
    </select></label>
    <br>
    <label><?php echo t('Species list filter:'); ?> <select name="species_list">
      <option value=""<?php echo $preselect === '' ? ' selected' : ''; ?>><?php echo t('None: use the model\'s species distribution'); ?></option>
      <option value="AUTO"><?php echo t('Detect the Brazilian state from the coordinates'); ?></option>
<?php if ($station_lists) { echo '<optgroup label="' . $h(t('Station lists')) . '">';
  foreach ($station_lists as $n) { echo '<option value="' . $h($n) . '"' . ($n === $preselect ? ' selected' : '') . '>' . $h($n) . '</option>'; }
  echo '</optgroup>'; } ?>
      <optgroup label="<?php echo $h(t('Brazilian states (WikiAves records, CBRO names)')); ?>">
<?php foreach ($states as $uf) { $v = "BR-$uf"; if (in_array($v, $station_lists, true)) continue;
  echo '<option value="' . $v . '"' . ($v === $preselect ? ' selected' : '') . '>' . $v . ($v === $detected_state ? ' ' . t('(from the coordinates)') : '') . '</option>'; } ?>
      </optgroup>
    </select></label>
    <!-- the same explanations as Basic Settings › Location (owner 2026-10-10) -->
    <p><small>
            <?php echo t('<b>None</b>: every species the model\'s location filter allows for this place and week; use it outside Brazil or to detect species not yet registered in your area.'); ?><br>
            <?php echo t('<b>Station list</b>: only the species of that list; edit, save and load lists in Lists › Custom Species.'); ?><br>
            <?php echo t('<b>Brazilian state</b>: only the birds with WikiAves records in the state (CBRO names) plus the model\'s non-bird classes; built once, then kept as a station list you can edit.'); ?><br>
            <?php echo t('<b>Detect the Brazilian state</b>: the state of the latitude / longitude above (OpenStreetMap online, the IBGE boundaries offline).'); ?>
    </small></p>
  </td></tr></table><br>
  <table class="settingstable"><tr><td>
    <h2><?php echo t('Theme'); ?></h2>
    <div class="wizthemes">
<?php $cur_theme = strtolower(trim($config['APP_THEME'] ?? 'ocean')); if (!isset(theme_presets()[$cur_theme])) $cur_theme = 'ocean';
  foreach (theme_presets() as $k => $t) {
    echo '<label class="wiztheme"><input type="radio" name="app_theme" value="' . $k . '"' . ($k === $cur_theme ? ' checked' : '') . ' data-colors="' . implode(',', array_slice($t, 1)) . '">'
      . '<span class="mock" style="background:' . $t[1] . '"><span class="mm" style="background:' . $t[2] . '"></span><span class="mp" style="background:' . $t[3] . '"><i style="background:' . $t[4] . '"></i><i style="background:' . $t[5] . '"></i></span></span>'
      . '<b>' . str_replace(' (', '<br>(', $h(t($t[0]))) . '</b></label>';
  } ?>
    </div>
    <small><?php echo t('Colours of the app, shown at once; a custom theme can be made later in Settings › Theme.'); ?></small>
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
  <table class="settingstable"><tr><td>
    <h2><?php echo t('Web Access Password'); ?></h2>
    <p><small><?php echo htmlspecialchars(t('Letters, digits and ! @ # % ^ & * ( ) - _ = + [ ] { } . , : ? ~ / (no spaces, quotes, ` $ \\ ; < > |).')); ?> <?php echo t('The user name is <b>{user}</b> (it can be changed in Settings › Access).', array('user' => htmlspecialchars(web_user()))); ?> <?php echo empty($config['CADDY_PWD']) ? t('Leave both empty to keep the station without a password.') : (($config['CADDY_PWD'] ?? '') === 'birdsuite' ? t('The station still has the factory password birdsuite: set your own here.') : t('Leave both empty to keep the current password.')); ?></small></p>
    <label><?php echo t('Password:'); ?> <input name="password" type="password" autocomplete="new-password" pattern="<?php echo htmlspecialchars(web_password_pattern(), ENT_QUOTES); ?>"></label><br>
    <label><?php echo t('Repeat:'); ?> <input name="password2" type="password" autocomplete="new-password" pattern="<?php echo htmlspecialchars(web_password_pattern(), ENT_QUOTES); ?>"></label><br>
  </td></tr></table><br>
  <button type="submit" name="wizard_save" value="1"><?php echo t('Save and start'); ?></button>
  </form>
<?php } ?>
</div>
