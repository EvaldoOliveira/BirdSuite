<?php
/* Network (US-120, owner 2026-10-20): how the station is reached in the field — the networks it is on, its fixed name,
 * the fleet phone hotspot (lowest priority: any real network wins), the station's own WiFi (on demand: Bluetooth, this
 * page, or automatically at boot without a network), a WiFi for this place, the clock, Identify (the LED blinks) and the
 * printable QR label. Passwords go to scripts/field_net.py on stdin and are never shown here (except on the label).
 * Opened as views.php?view=Network; the label is scripts/network.php?label=1. */
require_once __DIR__ . '/common.php';

function fn_run($args, $stdin = null) {
  // as the station user: NetworkManager through its polkit rule, the clock and the LED through birdsuite-root (2026-10-23)
  $cmd = station_prefix() . 'python3 ' . escapeshellarg(__DIR__ . '/field_net.py');
  foreach ($args as $a) $cmd .= ' ' . escapeshellarg((string)$a);
  $p = proc_open($cmd, array(0 => array('pipe', 'r'), 1 => array('pipe', 'w'), 2 => array('pipe', 'w')), $pipes);
  if (!is_resource($p)) return array('ok' => false, 'error' => 'cannot run field_net.py');
  if ($stdin !== null) fwrite($pipes[0], $stdin . "\n");
  fclose($pipes[0]);
  $out = stream_get_contents($pipes[1]);
  fclose($pipes[1]); fclose($pipes[2]);
  proc_close($p);
  return json_decode((string)$out, true) ?: array('ok' => false, 'error' => trim((string)$out) ?: t('str_no_answer_lc'));
}

// the answer file of new stations (owner 2026-11-03): birdnet-setup.conf with this station's settings, read by the
// installer of a new card (scripts/install_firstrun.sh, docs/birdnet-setup.conf.example), which then starts set up,
// without First Run. Each station keeps its own name (BirdSuite-<MAC>), BirdWeather ID and live-stream password. The web
// password is kept only as its hash, so the one of the new stations is typed here (empty = the factory one); the
// phone hotspot and the station WiFi password are this station's, when asked for
function seed_file($p) {
  $c = get_config();
  $pwd = (string)($p['web_password'] ?? '');
  if ($pwd !== '' && !web_password_ok($pwd)) return array('ok' => false, 'error' => t('str_the_password_may_contain_help'));
  if ($pwd !== (string)($p['web_password2'] ?? '')) return array('ok' => false, 'error' => t('str_the_two_passwords_differ'));
  $list = (string)($c['SPECIES_LIST'] ?? '');
  $tz = trim((string)shell_exec('timedatectl show --value --property=Timezone 2>/dev/null'));
  $v = array(
    'LATITUDE' => (string)($c['LATITUDE'] ?? ''), 'LONGITUDE' => (string)($c['LONGITUDE'] ?? ''),
    'TIMEZONE' => preg_match('#^[A-Za-z0-9_+/-]+$#', $tz) ? $tz : '',
    'MODEL' => strpos((string)($c['MODEL'] ?? ''), 'V2.4') !== false ? 'V2.4' : 'V3',
    'LANGUAGE' => (string)($c['DATABASE_LANG'] ?? 'en'),
    'STATE' => preg_match('/^BR-([A-Z]{2})$/', $list, $m) ? $m[1] : 'none',
    'WEB_PASSWORD' => $pwd, 'HOTSPOT_NAME' => '', 'HOTSPOT_PASSWORD' => '', 'STATION_WIFI_PASSWORD' => '',
    'MULTI_LOCATION' => ($c['MULTI_LOCATION'] ?? '0') === '1' ? 'y' : 'n',
    'MULTI_STATION' => ($c['MULTI_STATION'] ?? '0') === '1' ? 'y' : 'n',
    // the fleet (US-58): the same level, preset, context and access as this station
    'UI_PRESET' => (string)($c['UI_PRESET'] ?? ''), 'UI_LEVEL' => (string)($c['UI_LEVEL'] ?? ''), 'UI_CONTEXT' => (string)($c['UI_CONTEXT'] ?? ''),
    'LAN_TRUST' => (string)($c['LAN_TRUST'] ?? ''), 'WEB_LOGIN' => (string)($c['WEB_LOGIN'] ?? ''),
  );
  // the profile (US-58): this station's settings that differ from the factory ones, as SET_<KEY> lines; never a secret,
  // a coordinate or a name, only plain values (the installer applies the same rule, install_firstrun.sh)
  if (!empty($p['profile'])) {
    foreach (birdsuite_defaults() as $k => $d) {
      $cur = trim((string)($c[$k] ?? $d), " \"'");
      if ($cur === trim((string)$d, " \"'") || isset($v[$k]) || preg_match('/PWD|PASS|TOKEN|SECRET|KEY|APPRISE|EMAIL|BIRDWEATHER_ID|LATITUDE|LONGITUDE|CADDY|SITE_NAME|RTSP|^UI_|LAN_TRUST|WEB_LOGIN|MULTI_/', $k)) continue;
      if (preg_match('/^[A-Za-z0-9._,:\/@%+-]*$/', $cur)) $v['SET_' . $k] = $cur;
    }
  }
  if (!empty($p['hotspot'])) {
    $s = fn_run(array('status'));
    $v['HOTSPOT_NAME'] = (string)($s['fleet_ssid'] ?? '');
    if ($v['HOTSPOT_NAME'] !== '') { $r = fn_run(array('fleet-psk')); $v['HOTSPOT_PASSWORD'] = (string)($r['psk'] ?? ''); }
  }
  if (!empty($p['station_wifi'])) {
    $r = fn_run(array('ap-psk'));
    $v['STATION_WIFI_PASSWORD'] = (string)($r['psk'] ?? '');
    if ($v['STATION_WIFI_PASSWORD'] !== '' && $v['STATION_WIFI_PASSWORD'] === $pwd) return array('ok' => false, 'error' => t('str_the_station_wifi_password_is_not_help'));
  }
  $out = "# birdnet-setup.conf - answers for a new BirdSuite station, made by " . get_sitename() . ' on ' . date('Y-m-d H:i') . "\n"
    . "# Copy to the boot partition of the new card (/boot/firmware/birdnet-setup.conf) before installing: the station\n"
    . "# starts set up, without First Run. Every key: docs/birdnet-setup.conf.example. It may hold passwords: keep it private.\n";
  foreach ($v as $k => $x) $out .= $k . '=' . preg_replace('/[\x00-\x1f\x7f]/', '', $x) . "\n";
  return array('ok' => true, 'text' => $out);
}

// the Brazilian state outlines shipped with the station (model/include_lists/BR-states.geojson): detail of the offline map
if (isset($_GET['states'])) { header('Content-Type: application/json'); readfile(__DIR__ . '/../model/include_lists/BR-states.geojson'); die(); }
// the world's countries (Natural Earth 1:110m, public domain, model/maps): the offline map anywhere
if (isset($_GET['world'])) { header('Content-Type: application/json'); header('Cache-Control: max-age=86400'); readfile(__DIR__ . '/../model/maps/world-countries.geojson'); die(); }
if (isset($_GET['status'])) { header('Content-Type: application/json'); echo json_encode(fn_run(array('status'))); die(); }
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_GET['do'])) {
  ensure_authenticated(t('str_you_must_change_the_network_help'));
  header('Content-Type: application/json');
  $p = json_decode((string)file_get_contents('php://input'), true) ?: array();
  switch ($_GET['do']) {
    case 'fleet': echo json_encode(fn_run(array('set-fleet', (string)($p['ssid'] ?? '')), (string)($p['psk'] ?? ''))); break;
    case 'ap': echo json_encode(fn_run(array('set-ap'), (string)($p['psk'] ?? ''))); break;
    case 'wifi': echo json_encode(fn_run(array('add-wifi', (string)($p['ssid'] ?? '')), (string)($p['psk'] ?? ''))); break;
    case 'wifi-edit': echo json_encode(fn_run(array('edit-wifi', (string)($p['name'] ?? ''), (string)($p['ssid'] ?? '')), (string)($p['psk'] ?? ''))); break;
    // the station WiFi password, shown on request (owner 2026-10-24: "see password" in the station WiFi, now Plan A); the label shows it too
    case 'ap-psk': $r = fn_run(array('ap-psk')); echo json_encode(array('ok' => true, 'psk' => (string)($r['psk'] ?? ''))); break;
    // the phone hotspot's password the same way (owner 2026-10-25)
    case 'wifi-psk': $r = fn_run(array('wifi-psk', (string)($p['name'] ?? ''))); echo json_encode(array('ok' => true, 'psk' => (string)($r['psk'] ?? ''))); break;
    case 'fleet-psk': $r = fn_run(array('fleet-psk')); echo json_encode(array('ok' => true, 'psk' => (string)($r['psk'] ?? ''))); break;
    case 'wifi-forget': echo json_encode(fn_run(array('forget-wifi', (string)($p['name'] ?? '')))); break;
    case 'ap-on': echo json_encode(fn_run(array('ap-on', 'page'))); break;
    case 'ap-off': echo json_encode(fn_run(array('ap-off'))); break;
    case 'identify': echo json_encode(fn_run(array('identify'))); break;
    case 'time': echo json_encode(fn_run(array('time', intval($p['epoch'] ?? 0)))); break;
    case 'seed': echo json_encode(seed_file($p)); break;
    default: echo json_encode(array('ok' => false, 'error' => t('str_unknown_action_lc')));
  }
  die();
}
// the label: QR of the station WiFi + the station's identity as text, to print and stick on the station
if (isset($_GET['label'])) {
  ensure_authenticated(t('str_you_must_print_the_label_help'));
  $ap = fn_run(array('ap-psk'));
  $h = function ($s) { return htmlspecialchars((string)$s, ENT_QUOTES); };
  $esc_wifi = function ($s) { return preg_replace('/([\\\;,:"])/', '\\\\$1', (string)$s); };
  $url_name = station_scheme() . '://' . strtolower($ap['hostname'] ?? 'birdsuite') . '.local/';
  $wifi = 'WIFI:T:WPA;S:' . $esc_wifi($ap['ap_ssid'] ?? '') . ';P:' . $esc_wifi($ap['psk'] ?? '') . ';;';
  ?><!doctype html><html><head><meta charset="utf-8"><title><?php echo $h(t('str_label', array('name' => get_sitename()))); ?></title>
  <style>body{font-family:system-ui,sans-serif;margin:20px}.lbl{border:1px dashed #999;padding:14px;width:400px;display:flex;gap:14px;align-items:center}
  .lbl h1{font-size:18px;margin:0 0 4px}.lbl div{font-size:12px;line-height:1.5}.qrs{text-align:center;font-size:11px}.qrs svg{width:110px;height:110px;display:block;margin:0 auto 2px}@media print{button{display:none}}</style></head><body>
  <?php // two QRs (owner 2026-10-25): the camera joins the WiFi with the first and opens the page with the second ?>
  <div class="lbl"><div class="qrs"><div id="qr"></div>1. <?php echo t('str_join_the_wifi'); ?><div id="qrurl" style="margin-top:8px"></div>2. <?php echo t('str_open_the_page'); ?></div><div>
    <?php // the printed lines, in the owner's order (2026-10-24); the password only inside the WiFi QR ?>
    <b><?php echo t('str_station'); ?>:</b> <?php echo $h(get_sitename()); ?><br>
    <b>WiFi:</b> <?php echo $h($ap['ap_ssid'] ?? ''); ?><br>
    <?php // the password as text only on the label "with password" (owner 2026-10-24); it is always inside the QR
    if (!empty($_GET['psk'])) { ?><b><?php echo t('str_password'); ?>:</b> <?php echo $h(($ap['psk'] ?? '') !== '' ? $ap['psk'] : t('str_not_set')); ?><br><?php } ?>
    <?php // "URL" with the station's scheme (http unless HTTPS_ALWAYS, US-126) ?>
    <b>URL:</b> <?php echo $h(rtrim($url_name, '/')); ?><br>
    <b><?php echo t('str_url_wifi'); ?>:</b> <?php echo station_scheme(); ?>://<?php echo $h($ap['ap_addr'] ?? '10.42.x.1'); ?><br>
    <b><?php echo t('str_url_network'); ?>:</b> <?php echo station_scheme(); ?>://<?php echo $h($ap['mdns'] ?? ''); ?><br>
    <b><?php echo t('str_hostname'); ?>:</b> <?php echo $h($ap['hostname'] ?? ''); ?><br>
    <b><?php echo t('str_mac_wifi'); ?>:</b> <?php echo $h(($ap['wifi_mac'] ?? '') !== '' ? $ap['wifi_mac'] : t('str_not_set')); ?></div></div>
  <p><button onclick="print()"><?php echo t('str_print'); ?></button> <?php echo t('str_scan_the_first_qr_with_the_help'); ?></p>
  <script src="../static/qrcode.min.js"></script>
  <script>
  qrcode.stringToBytes = qrcode.stringToBytesFuncs['UTF-8'];  // a password with accents
  function qrSvg(id, text) { var q = qrcode(0, 'M'); q.addData(text); q.make(); document.getElementById(id).innerHTML = q.createSvgTag({cellSize: 4, margin: 0}); }
  qrSvg('qr', <?php echo json_encode($wifi); ?>);
  <?php // the name, as everywhere (owner 2026-10-26: "birdsuite"): the station's host name, birdsuite on a standard install ?>
  qrSvg('qrurl', <?php echo json_encode($url_name); ?>);
  </script>
  </body></html><?php
  die();
}
?>
<style>
.net { max-width: 900px; margin: 0; text-align: left; padding: 0 12px; }
.net #netstatus { font-size: 1.1em; line-height: 1.6; }
.net .usecases p { margin: 6px 0; font-size: .95em; }
.net .box { background: var(--panel, #f4f6f8); border-radius: 10px; padding: 10px 14px; margin: 10px 0; }
.net .row { display: flex; flex-wrap: wrap; gap: 8px; align-items: center; margin: 6px 0; }
.net .row > label { font-weight: normal; }
.net .netbtn { width: auto; padding: 4px 12px; border-radius: 12px; border: 1px solid var(--accent,#2b5e22); background: #fff; color: var(--accent,#2b5e22); font-weight: 600; cursor: pointer; }
.net .netbtn:hover { background: var(--accent,#2b5e22); color: #fff; }
.net input { padding: 4px 6px; border-radius: 6px; border: 1px solid #bbb; }
.net .small { font-size: 12px; opacity: .8; }
.net .ok { color: #1b5e20; font-weight: 600; } .net .warn { color: #8e0000; font-weight: 600; }
.net code { font-size: 12px; }
.net .netbtn.netsm { padding: 1px 9px; font-size: 12px; font-weight: normal; }
/* the three sections alike (owner 2026-10-25): the saved entries (name, state, Edit / Delete, then the password with
   Show password), then the fields in two columns, then the button */
.net .knownnet { margin: 6px 0 10px; }
.net .knownnet .kn { display: flex; flex-wrap: wrap; gap: 6px; align-items: center; }
.net .knownnet .kp { display: flex; flex-wrap: wrap; gap: 6px; align-items: center; margin: 4px 0 0 22px; }
.net .netform { display: grid; grid-template-columns: max-content minmax(0, 1fr); gap: 8px 10px; align-items: center; margin: 8px 0 4px; max-width: 32em; }
.net .netform input { width: 100%; box-sizing: border-box; }
.net .netact { display: flex; flex-wrap: wrap; gap: 8px; margin: 6px 0; }
</style>
<div class="net">
  <?php // owner's layout (2026-10-20): the network now, the WiFi it joins, its own WiFi, the phone hotspot, the clock;
        // one sentence per line ?>
  <div class="box" id="netstatus"><?php echo t('str_reading_the_network'); ?></div>
  <?php // one section per situation (owner 2026-10-24: the page repeated itself): a clear title, one sentence, the steps ?>
  <div class="box">
    <div class="row"><b>1. <?php echo t('str_wifi_network'); ?></b></div>
    <div><?php echo t('str_set_it_up_when_the_station_is_help'); ?></div>
    <div id="knownwifi" style="margin:6px 0 4px"></div>
    <div class="netform"><label for="wssid"><?php echo t('str_network_name'); ?></label> <input id="wssid" maxlength="32">
      <label for="wpsk"><?php echo t('str_password'); ?></label> <input type="password" id="wpsk" minlength="8" maxlength="63"></div>
    <div class="netact"><button type="button" class="netbtn" onclick="netSet('wifi', {ssid: v('wssid'), psk: v('wpsk')})"><?php echo t('str_add'); ?></button></div>
  </div>
  <div class="box">
    <div class="row"><b>2. <?php echo t('str_in_the_field_station_wifi_plan_help'); ?></b></div>
    <div><?php echo t('str_with_no_network_cable_wifi_or_help'); ?></div>
    <div><?php echo t('str_when_a_known_network_is_in_help'); ?></div>
    <div id="apsaved" style="margin:6px 0 4px"></div>
    <div class="netform"><label for="appsk"><?php echo t('str_new_password'); ?></label> <input type="password" id="appsk" minlength="8" maxlength="63" placeholder="<?php echo htmlspecialchars(t('str_8_to_63_characters'), ENT_QUOTES); ?>"></div>
    <div class="netact"><button type="button" class="netbtn" onclick="netSet('ap', {psk: v('appsk')})"><?php echo t('str_save'); ?></button></div>
    <div style="margin-top:6px"><?php echo t('str_how_to_use_it'); ?><br>
      1. <?php echo t('str_turn_the_station_on_or_take_it_help'); ?><br>
      2. <?php echo t('str_connect_the_phone_or_computer_to', array('name' => '<b class="apname">BirdSuite-…</b>')); ?><br>
      3. <?php // each station has its own address (owner 2026-10-25), filled in from the status ?>
      <?php echo t('str_open_save_it_as_a_bookmark_on_help', array('url' => '<b class="apaddr">' . station_scheme() . '://10.42.x.1</b>')); ?></div>
    <div class="netact" style="margin-top:8px"><button type="button" class="netbtn" onclick="netDo('ap-on')"><?php echo t('str_test_now'); ?></button><button type="button" class="netbtn" onclick="netDo('ap-off')"><?php echo t('str_turn_off'); ?></button></div>
    <div class="small warn" style="font-weight:normal">&#9888; <?php echo t('str_while_it_is_on_the_station_help'); ?></div>
    <?php // WiFi in the field (owner 2026-10-25): always, cycle (2 every 15 by default) or off; saved at once (ui-standard.js)
    $fc = get_config(); $fm = $fc['FIELD_WIFI_MODE'] ?? 'always'; $fx = intval($fc['FIELD_AP_WINDOW_MIN'] ?? 2); $fy = intval($fc['FIELD_CYCLE_MIN'] ?? 15); ?>
    <div style="margin-top:8px"><label><b><?php echo t('str_wifi_in_the_field'); ?>:</b> <select data-setting="FIELD_WIFI_MODE">
      <?php foreach (array('always' => t('str_always_on'), 'cycle' => t('str_cycle_min_every_min', array('x' => $fx, 'y' => $fy)), 'off' => t('str_off_wifi_antenna_off')) as $fk => $fl) echo '<option value="' . $fk . '"' . ($fm === $fk ? ' selected' : '') . '>' . htmlspecialchars($fl) . '</option>'; ?>
    </select></label></div>
    <div class="small"><?php echo t('str_always_on_on_with_no_time_limit_help'); ?><br>
      <?php echo t('str_cycle_on_for_minutes_every_help', array('x' => $fx, 'y' => $fy)); ?><br>
      <?php echo t('str_off_only_for_minutes_after_help', array('x' => $fx)); ?><br>
      <?php echo t('str_a_phone_on_the_station_wifi_help', array('i' => intval($fc['FIELD_AP_IDLE_MIN'] ?? 30))); ?></div>
    <?php // how the station goes back to a known network (owner 2026-10-25), field_daemon.back_home() ?>
    <details class="small" style="margin-top:6px"><summary><?php echo t('str_how_does_it_go_back_to_your_help'); ?></summary>
      <?php echo t('str_with_the_cable_unplugged_the_help'); ?><br>
      <?php echo t('str_while_the_station_wifi_is_on_help'); ?><br>
      <?php echo t('str_when_one_of_them_is_in_range_help'); ?><br>
      <?php echo t('str_a_network_out_of_range_is_never_help'); ?><br>
      <?php echo t('str_if_a_network_in_range_refuses_help'); ?><br>
      <?php echo t('str_that_network_is_tried_again_help'); ?><br>
      <?php echo t('str_with_a_phone_on_the_station_help'); ?></details>
  </div>
  <div class="box">
    <div class="row"><b>3. <?php echo t('str_in_the_field_with_internet_help'); ?></b></div>
    <div><?php echo t('str_at_home_add_the_phones_hotspot'); ?></div>
    <div><?php echo t('str_in_the_field_turn_the_hotspot_help'); ?></div>
    <div><?php echo t('str_it_uses_little_power_and_is_help'); ?></div>
    <div><?php echo t('str_with_more_than_one_station_use_help'); ?></div>
    <div id="hotinfo" style="margin:6px 0 4px"></div>
    <div class="netform"><label for="hssid"><?php echo t('str_hotspot_name'); ?></label> <input id="hssid" maxlength="32">
      <label for="hpsk"><?php echo t('str_password'); ?></label> <input type="password" id="hpsk" minlength="8" maxlength="63"></div>
    <div class="netact"><button type="button" class="netbtn" onclick="netSet('fleet', {ssid: v('hssid'), psk: v('hpsk')})"><?php echo t('str_save'); ?></button></div>
    <div style="margin-top:6px"><?php echo t('str_to_open_the_station'); ?> <b class="mdnsname"><?php echo station_scheme(); ?>://birdsuite-…local</b><br>
      <?php // the name on any network (owner 2026-10-26); the address only for a device without names ?>
      <span class="small"><?php echo t('str_a_device_that_does_not_open_help'); ?>
      iPhone → <b class="iphoneaddr"><?php echo station_scheme(); ?>://172.20.10.x</b>,
      Android → <?php echo t('str_see_the_address_under_connected_help'); ?></span></div>
    <details class="small" style="margin-top:6px"><summary><?php echo t('str_where_do_i_find_the_name_and_help'); ?></summary>
      <?php echo t('str_iphone_the_hotspot_name_is_the_help'); ?><br>
      <?php echo t('str_android_settings_connections_help'); ?><br>
      <?php echo t('str_the_stations_address_tap_help'); ?></details>
  </div>
  <?php // its own section (owner 2026-10-24): finding the station is useful however it is reached ?>
  <div class="box">
    <div class="row"><span><b>4.</b> <?php echo t('str_find_my_station_the_stations_help'); ?></span></div>
    <div class="row"><button type="button" class="netbtn" onclick="netDo('identify')"><?php echo t('str_identify_blink_led'); ?></button></div>
    <div class="small"><?php echo t('str_to_locate_the_station_when_help'); ?></div>
  </div>
  <?php // the label, short (owner 2026-10-24): print it, stick it on, scan it ?>
  <?php // the QR label and the answer file for new stations are for many stations (US-52): folded below Expert ?>
  <?php echo more_options('expert'); ?>
  <div class="box">
    <div class="row"><b>5. <?php echo t('str_batch_or_field_deployment_qr_code'); ?></b></div>
    <div class="row"><?php echo t('str_print_the_label_and_stick_it_on_help'); ?> <a class="netbtn" href="scripts/network.php?label=1" target="_blank" style="text-decoration:none"><?php echo t('str_normal_button'); ?></a>
      <a class="netbtn" href="scripts/network.php?label=1&amp;psk=1" target="_blank" style="text-decoration:none" title="<?php echo htmlspecialchars(t('str_the_wifi_password_also_printed_help'), ENT_QUOTES); ?>"><?php echo t('str_with_password'); ?></a></div>
    <div><?php echo t('str_scan_the_qr_with_the_phone_the_help'); ?></div>
    <div class="small"><?php echo t('str_on_the_normal_label_the_help'); ?></div>
  </div>
  <?php // the answer file of new stations (owner 2026-11-03), beside the label: both are for setting up a fleet ?>
  <div class="box">
    <div class="row"><b>6. <?php echo t('str_answer_file_for_new_stations'); ?></b></div>
    <div><?php echo t('str_the_file_copies_this_stations_help'); ?></div>
    <div><?php echo t('str_put_it_on_the_boot_partition_help'); ?></div>
    <div class="small"><?php echo t('str_the_web_password_is_kept_only_help'); ?></div>
    <div class="netform"><label for="sdpwd"><?php echo t('str_web_password'); ?></label> <input type="password" id="sdpwd" autocomplete="new-password" pattern="<?php echo htmlspecialchars(web_password_pattern(), ENT_QUOTES); ?>">
      <label for="sdpwd2"><?php echo t('str_repeat'); ?></label> <input type="password" id="sdpwd2" autocomplete="new-password"></div>
    <div class="row"><label><input type="checkbox" id="sdhot" checked> <?php echo t('str_with_this_stations_phone_hotspot'); ?></label></div>
    <div class="row"><label><input type="checkbox" id="sdap"> <?php echo t('str_with_this_stations_wifi_password'); ?></label></div>
    <div class="row"><label><input type="checkbox" id="sdprof" checked> <?php echo t('str_with_this_stations_settings_no_help'); ?></label></div>
    <div class="netact"><button type="button" class="netbtn" onclick="netSeed()"><?php echo t('str_download_birdnet_setup_conf'); ?></button> <span id="sdmsg"></span></div>
  </div>
  <?php echo more_options_end(); ?>
  <div class="box">
    <div class="row"><b>7. <?php echo t('str_clock'); ?></b> <span id="timeinfo"></span></div>
    <div class="row"><?php echo t('str_without_internet_the_station_help'); ?> <button type="button" class="netbtn" onclick="netTime()"><?php echo t('str_use_this_devices_time'); ?></button></div>
  </div>
  <div id="netmsg" style="font-weight:600"></div>
</div>
<script>
// the scheme of the station's links (US-126): http unless HTTPS_ALWAYS
var NETSCHEME = <?php echo json_encode(station_scheme()); ?>;
function v(id) { return document.getElementById(id).value; }
var esc = function (s) { return String(s == null ? '' : s).replace(/[&<>"]/g, function (c) { return {'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;'}[c]; }); };
function netPost(what, body, done) {
  var x = new XMLHttpRequest();
  x.onload = function () {
    var r; try { r = JSON.parse(this.responseText); } catch (e) { r = {ok: false, error: this.status === 401 ? t('str_log_in_first') : this.responseText}; }
    document.getElementById('netmsg').innerHTML = r.ok ? '&#10003; ' + t('str_done') : '<span style="color:#c62828">' + esc(r.error) + '</span>';
    netLoad(); if (done) done(r);
  };
  x.open('POST', 'scripts/network.php?do=' + what, true); x.setRequestHeader('Content-Type', 'application/json'); x.send(JSON.stringify(body || {}));
}
function netDo(what) { netPost(what, {}); }
// the answer file: built by the station (its settings and, when asked, its WiFi passwords), saved by the browser
function netSeed() {
  var m = document.getElementById('sdmsg'), x = new XMLHttpRequest();
  x.onload = function () {
    var r; try { r = JSON.parse(this.responseText); } catch (e) { r = {ok: false, error: this.status === 401 ? t('str_log_in_first') : this.responseText}; }
    if (!r.ok) { m.innerHTML = '<span style="color:#c62828">' + esc(r.error) + '</span>'; return; }
    var a = document.createElement('a');
    a.href = URL.createObjectURL(new Blob([r.text], {type: 'text/plain'}));
    a.download = 'birdnet-setup.conf';
    document.body.appendChild(a); a.click(); a.remove();
    m.innerHTML = '&#10003; ' + t('str_done');
    document.getElementById('sdpwd').value = ''; document.getElementById('sdpwd2').value = '';
  };
  x.open('POST', 'scripts/network.php?do=seed', true); x.setRequestHeader('Content-Type', 'application/json');
  x.send(JSON.stringify({web_password: v('sdpwd'), web_password2: v('sdpwd2'), profile: document.getElementById('sdprof').checked, hotspot: document.getElementById('sdhot').checked, station_wifi: document.getElementById('sdap').checked}));
}
function netSet(what, body) { netPost(what, body, function (r) { if (r.ok) document.querySelectorAll('.net input[type=password]').forEach(function (i) { i.value = ''; }); }); }
// a known network: new name and / or password in place (empty password = keep it), or removed after a question
function netEdit(btn) {
  var row = btn.closest('.knownnet');
  if (row.querySelector('.netedit')) return;
  var f = document.createElement('div');
  f.className = 'row netedit';
  f.innerHTML = '<label>' + t('str_network_name') + ' <input class="en" maxlength="32"></label> <label>' + t('str_password') + ' <input type="password" class="ep" maxlength="63" placeholder="' + esc(t('str_empty_keep_it')) + '"></label> '
    + '<button type="button" class="netbtn">' + t('str_save') + '</button> <button type="button" class="netbtn">' + t('str_cancel') + '</button>';
  f.querySelector('.en').value = row.dataset.ssid;
  var b = f.querySelectorAll('button');
  b[0].onclick = function () { netPost('wifi-edit', {name: row.dataset.profile, ssid: f.querySelector('.en').value, psk: f.querySelector('.ep').value}); };
  b[1].onclick = function () { f.remove(); };
  row.appendChild(f);
}
function netForget(btn) {
  var row = btn.closest('.knownnet');
  bsAsk(t('str_delete_the_network_the_station_help', {name: row.dataset.ssid}), true).then(function (ok) {
    if (ok) netPost('wifi-forget', {name: row.dataset.profile});
  });
}
// one saved entry, the same in the three sections (owner 2026-10-25): icon, name and state with Edit / Delete, then the
// password with Show password; psk = 'ap' for the station's own WiFi (no Edit / Delete: its password is set below)
function netSaved(profile, ssid, html, psk) {
  return '<div class="knownnet" data-profile="' + esc(profile) + '" data-ssid="' + esc(ssid) + '" data-psk="' + psk + '"><div class="kn">' + html
    + (psk === 'ap' ? '' : ' <button type="button" class="netbtn netsm" onclick="netEdit(this)">' + t('str_edit') + '</button>'
      + ' <button type="button" class="netbtn netsm" onclick="netForget(this)">' + t('str_delete') + '</button>') + '</div>'
    + '<div class="kp">' + t('str_password') + ': <span class="rowpsk">••••••••</span> <button type="button" class="netbtn netsm" onclick="netShowRowPsk(this)">' + t('str_show_password') + '</button></div></div>';
}
// a known network's password, shown on a click and hidden on the next (the same as Plans A and B)
function netShowRowPsk(btn) {
  var row = btn.closest('.knownnet'), el = row.querySelector('.rowpsk');
  if (el.dataset.shown) { el.textContent = '••••••••'; delete el.dataset.shown; btn.textContent = t('str_show_password'); return; }
  var x = new XMLHttpRequest();
  x.onload = function () {
    var r; try { r = JSON.parse(this.responseText); } catch (e) { r = {ok: false}; }
    if (this.status === 401) { el.textContent = t('str_log_in_first'); return; }
    el.textContent = r.psk ? r.psk : t('str_not_set');
    el.dataset.shown = '1'; btn.textContent = t('str_hide_password');
  };
  x.open('POST', 'scripts/network.php?do=' + (row.dataset.psk === 'ap' ? 'ap-psk' : 'wifi-psk'), true); x.setRequestHeader('Content-Type', 'application/json'); x.send(JSON.stringify({name: row.dataset.profile}));
}
// an address as a link that opens the station there
function netLink(u, label) { return '<a href="' + esc(u) + '/">' + esc(label || u) + '</a>'; }
function netTime() { netPost('time', {epoch: Math.floor(Date.now() / 1000)}); }
// a WiFi icon in the colour of its state: green = connected, orange = known
function wifiIcon(on) {
  return '<svg viewBox="0 0 24 24" width="15" height="15" style="vertical-align:-2px" aria-hidden="true"><path fill="' + (on ? '#2e7d32' : '#e08a00')
    + '" d="M12 21l3.6-4.8a6 6 0 0 0-7.2 0zm0-18A17.9 17.9 0 0 0 1 6.8l2.1 2.8a14.4 14.4 0 0 1 17.8 0L23 6.8A17.9 17.9 0 0 0 12 3zm0 6.5a11.4 11.4 0 0 0-6.9 2.3l2.1 2.8a8 8 0 0 1 9.6 0l2.1-2.8A11.4 11.4 0 0 0 12 9.5z"/></svg>';
}
function netLoad() {
  var x = new XMLHttpRequest();
  x.onload = function () {
    var s; try { s = JSON.parse(this.responseText); } catch (e) { netLost(); return; }
    if (s.ok === false) { document.getElementById('netstatus').textContent = s.error; document.getElementById('apsaved').textContent = s.error; return; }
    var nets = (s.active || []).filter(function (a) { return a.name !== 'birdsuite-ap'; }).map(function (a) { return esc(a.name) + ' (' + esc(a.device) + ')'; }).join(', ') || '<span class="warn">' + t('str_no_network') + '</span>';
    // one item per line, all in the normal (larger) size (owner 2026-10-20)
    document.getElementById('netstatus').innerHTML = '<b>' + t('str_connected_to') + '</b> ' + nets
      + '<br><b>' + t('str_addresses') + '</b> ' + (s.ips || []).map(function (i) { return netLink(NETSCHEME + '://' + i.ip, i.ip); }).join(', ')
      // the address to type, as a link (owner 2026-10-25: "URL", not "Name")
      + '<br><b>URL</b> ' + netLink(NETSCHEME + '://' + (s.hostname || ('birdsuite-' + (s.mac || '').replace(/:/g, ''))) + '.local')
      + '<br><b>MAC</b> ' + esc(s.mac);
    if ((s.errors || []).length) document.getElementById('netstatus').innerHTML += '<br><span class="warn">' + esc(s.errors.join('; ')) + '</span>';
    document.querySelectorAll('.apname').forEach(function (e) { e.textContent = s.ap_ssid || 'BirdSuite-…'; });
    document.querySelectorAll('.apaddr').forEach(function (e) { if (s.ap_addr) e.innerHTML = netLink(NETSCHEME + '://' + s.ap_addr); });
    // the station WiFi's state (owner 2026-10-24): not set up (no password) in bold red, on (phones, address) or off
    document.getElementById('apsaved').innerHTML = netSaved('birdsuite-ap', s.ap_ssid || '', wifiIcon(s.ap_on) + ' <b>' + esc(s.ap_ssid || 'BirdSuite-…') + '</b> '
      + (!s.ap_profile ? '<b style="color:#c62828">' + t('str_not_set_up') + '</b>'
        : (s.ap_on ? '<span class="ok">' + t('str_on') + '</span>, ' + t('str_phone_s', {n: s.ap_clients}) + ', ' + netLink(NETSCHEME + '://' + s.ap_addr) : t('str_off_lc'))), 'ap');
    // one known network per line (owner 2026-10-20)
    var hot = '';
    // the saved hotspot with the same Edit / Delete as a known network (owner 2026-10-24)
    var hotOn = (s.active || []).some(function (x) { return x.name === 'birdsuite-fleet'; });
    document.getElementById('hotinfo').innerHTML = s.fleet_profile
      ? netSaved('birdsuite-fleet', s.fleet_ssid, wifiIcon(hotOn) + ' <b>' + esc(s.fleet_ssid) + '</b>' + (hotOn ? ' <span class="ok">' + t('str_connected_lc') + '</span>' : ''), 'wifi')
      : t('str_no_hotspot_saved_yet');
    // a link once the address is known (owner 2026-10-25: every URL a hyperlink)
    document.querySelectorAll('.mdnsname').forEach(function (e) { if (s.mac) e.innerHTML = netLink(NETSCHEME + '://birdsuite-' + s.mac.replace(/:/g, '').toLowerCase() + '.local'); });
    document.querySelectorAll('.iphoneaddr').forEach(function (e) { e.innerHTML = s.fleet_extra_ip ? netLink(NETSCHEME + '://' + s.fleet_extra_ip) : NETSCHEME + '://172.20.10.x'; });
    // the known networks (owner 2026-10-24: they disappeared): every WiFi profile but the station's own, the phone
    // hotspot marked, and the cable when it is connected; green = in use, orange = known
    var cable = (s.active || []).filter(function (x) { return x.type === '802-3-ethernet'; }).map(function (x) {
      // an RJ45 jack, not a power plug (owner 2026-10-24)
      return '<div class="knownnet"><div class="kn"><svg viewBox="0 0 24 24" width="16" height="16" style="vertical-align:-3px" aria-hidden="true"><path fill="currentColor" d="M4 4h16a1 1 0 0 1 1 1v11a1 1 0 0 1-1 1h-3v2a1 1 0 0 1-1 1H8a1 1 0 0 1-1-1v-2H4a1 1 0 0 1-1-1V5a1 1 0 0 1 1-1zm1 2v9h4v3h6v-3h4V6H5zm2 1.5h1.2v3H7v-3zm2.2 0h1.2v3H9.2v-3zm2.2 0h1.2v3h-1.2v-3zm2.2 0h1.2v3h-1.2v-3zm2.2 0H17v3h-1.2v-3z"/></svg> ' + t('str_cable') + ' (' + esc(x.device) + ') <span class="ok">' + t('str_connected_lc') + '</span></div></div>';
    }).join('');
    // the phone hotspot is in Plan B only (owner 2026-10-25: listed twice before)
    var nets = (s.known_wifi || []).filter(function (n) { return n !== 'birdsuite-fleet'; }).map(function (n) { return {name: (s.known_ssid || {})[n] || n, profile: n}; });
    document.getElementById('knownwifi').innerHTML = (nets.length || cable)
      ? t('str_known_networks') + cable + nets.map(function (n) {
          var on = (s.active || []).some(function (x) { return x.name === n.profile; });
          return netSaved(n.profile, n.name, wifiIcon(on) + ' <b>' + esc(n.name) + '</b>' + (on ? ' <span class="ok">' + t('str_connected_lc') + '</span>' : ''), 'wifi');
        }).join('')
      : t('str_no_known_network_yet');
    document.getElementById('timeinfo').innerHTML = esc(s.time) + ' ' + (s.time_confirmed ? '<span class="ok">' + t('str_confirmed_lc') + '</span>' : '<span class="warn">' + t('str_not_confirmed') + '</span>');
  };
  x.onerror = x.ontimeout = netLost; x.timeout = 15000;
  x.open('GET', 'scripts/network.php?status=1', true); x.send();
}
// no answer: on the WiFi network, turning the station WiFi on takes the station off that network (one radio)
function netLost() {
  document.getElementById('netstatus').innerHTML = '<span class="warn">' + t('str_the_station_does_not_answer_if_help') + '</span>';
}
netLoad();
</script>
