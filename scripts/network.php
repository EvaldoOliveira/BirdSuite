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
  return json_decode((string)$out, true) ?: array('ok' => false, 'error' => trim((string)$out) ?: t('no answer'));
}

// the Brazilian state outlines shipped with the station (model/include_lists/BR-states.geojson): detail of the offline map
if (isset($_GET['states'])) { header('Content-Type: application/json'); readfile(__DIR__ . '/../model/include_lists/BR-states.geojson'); die(); }
// the world's countries (Natural Earth 1:110m, public domain, model/maps): the offline map anywhere
if (isset($_GET['world'])) { header('Content-Type: application/json'); header('Cache-Control: max-age=86400'); readfile(__DIR__ . '/../model/maps/world-countries.geojson'); die(); }
if (isset($_GET['status'])) { header('Content-Type: application/json'); echo json_encode(fn_run(array('status'))); die(); }
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_GET['do'])) {
  ensure_authenticated(t('You must be authenticated to change the network.'));
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
    default: echo json_encode(array('ok' => false, 'error' => t('unknown action')));
  }
  die();
}
// the label: QR of the station WiFi + the station's identity as text, to print and stick on the station
if (isset($_GET['label'])) {
  ensure_authenticated(t('You must be authenticated to print the label (it shows the WiFi password).'));
  $ap = fn_run(array('ap-psk'));
  $h = function ($s) { return htmlspecialchars((string)$s, ENT_QUOTES); };
  $esc_wifi = function ($s) { return preg_replace('/([\\\;,:"])/', '\\\\$1', (string)$s); };
  $url_name = station_scheme() . '://' . strtolower($ap['hostname'] ?? 'birdsuite') . '.local/';
  $wifi = 'WIFI:T:WPA;S:' . $esc_wifi($ap['ap_ssid'] ?? '') . ';P:' . $esc_wifi($ap['psk'] ?? '') . ';;';
  ?><!doctype html><html><head><meta charset="utf-8"><title><?php echo $h(t('Label {name}', array('name' => get_sitename()))); ?></title>
  <style>body{font-family:system-ui,sans-serif;margin:20px}.lbl{border:1px dashed #999;padding:14px;width:400px;display:flex;gap:14px;align-items:center}
  .lbl h1{font-size:18px;margin:0 0 4px}.lbl div{font-size:12px;line-height:1.5}.qrs{text-align:center;font-size:11px}.qrs svg{width:110px;height:110px;display:block;margin:0 auto 2px}@media print{button{display:none}}</style></head><body>
  <?php // two QRs (owner 2026-10-25): the camera joins the WiFi with the first and opens the page with the second ?>
  <div class="lbl"><div class="qrs"><div id="qr"></div>1. <?php echo t('Join the WiFi'); ?><div id="qrurl" style="margin-top:8px"></div>2. <?php echo t('Open the page'); ?></div><div>
    <?php // the printed lines, in the owner's order (2026-10-24); the password only inside the WiFi QR ?>
    <b><?php echo t('Station'); ?>:</b> <?php echo $h(get_sitename()); ?><br>
    <b>WiFi:</b> <?php echo $h($ap['ap_ssid'] ?? ''); ?><br>
    <?php // the password as text only on the label "with password" (owner 2026-10-24); it is always inside the QR
    if (!empty($_GET['psk'])) { ?><b><?php echo t('Password'); ?>:</b> <?php echo $h(($ap['psk'] ?? '') !== '' ? $ap['psk'] : t('(not set)')); ?><br><?php } ?>
    <?php // "URL" with the station's scheme (http unless HTTPS_ALWAYS, US-126) ?>
    <b>URL:</b> <?php echo $h(rtrim($url_name, '/')); ?><br>
    <b><?php echo t('URL (WiFi)'); ?>:</b> <?php echo station_scheme(); ?>://<?php echo $h($ap['ap_addr'] ?? '10.42.x.1'); ?><br>
    <b><?php echo t('URL (network)'); ?>:</b> <?php echo station_scheme(); ?>://<?php echo $h($ap['mdns'] ?? ''); ?><br>
    <b><?php echo t('Hostname'); ?>:</b> <?php echo $h($ap['hostname'] ?? ''); ?><br>
    <b><?php echo t('MAC WiFi'); ?>:</b> <?php echo $h(($ap['wifi_mac'] ?? '') !== '' ? $ap['wifi_mac'] : t('(not set)')); ?></div></div>
  <p><button onclick="print()"><?php echo t('Print'); ?></button> <?php echo t('Scan the first QR with the phone camera to join the station WiFi, then the second to open the page.'); ?></p>
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
  <div class="box" id="netstatus"><?php echo t('Reading the network…'); ?></div>
  <?php // one section per situation (owner 2026-10-24: the page repeated itself): a clear title, one sentence, the steps ?>
  <div class="box">
    <div class="row"><b>1. <?php echo t('WiFi network'); ?></b></div>
    <div><?php echo t('Set it up when the station is not on a network through a cable.'); ?></div>
    <div id="knownwifi" style="margin:6px 0 4px"></div>
    <div class="netform"><label for="wssid"><?php echo t('Network name'); ?></label> <input id="wssid" maxlength="32">
      <label for="wpsk"><?php echo t('Password'); ?></label> <input type="password" id="wpsk" minlength="8" maxlength="63"></div>
    <div class="netact"><button type="button" class="netbtn" onclick="netSet('wifi', {ssid: v('wssid'), psk: v('wpsk')})"><?php echo t('Add'); ?></button></div>
  </div>
  <div class="box">
    <div class="row"><b>2. <?php echo t('In the field: station WiFi (Plan A)'); ?></b></div>
    <div><?php echo t('With no network (cable, WiFi or phone hotspot) the station opens its own WiFi, as the mode below says.'); ?></div>
    <div><?php echo t('When a known network is in range again, the station goes back to it and turns its WiFi off (not while a phone is connected).'); ?></div>
    <div id="apsaved" style="margin:6px 0 4px"></div>
    <div class="netform"><label for="appsk"><?php echo t('New password'); ?></label> <input type="password" id="appsk" minlength="8" maxlength="63" placeholder="<?php echo htmlspecialchars(t('8 to 63 characters'), ENT_QUOTES); ?>"></div>
    <div class="netact"><button type="button" class="netbtn" onclick="netSet('ap', {psk: v('appsk')})"><?php echo t('Save'); ?></button></div>
    <div style="margin-top:6px"><?php echo t('How to use it:'); ?><br>
      1. <?php echo t('Turn the station on, or take it away from its networks, and wait 1 minute.'); ?><br>
      2. <?php echo t('Connect the phone or computer to {name}.', array('name' => '<b class="apname">BirdSuite-…</b>')); ?><br>
      3. <?php // each station has its own address (owner 2026-10-25), filled in from the status ?>
      <?php echo t('Open {url} (save it as a bookmark on the phone beforehand).', array('url' => '<b class="apaddr">' . station_scheme() . '://10.42.x.1</b>')); ?></div>
    <div class="netact" style="margin-top:8px"><button type="button" class="netbtn" onclick="netDo('ap-on')"><?php echo t('Test now'); ?></button><button type="button" class="netbtn" onclick="netDo('ap-off')"><?php echo t('Turn off'); ?></button></div>
    <div class="small warn" style="font-weight:normal">&#9888; <?php echo t('While it is on, the station leaves your WiFi network (on a cable it keeps working).'); ?></div>
    <?php // WiFi in the field (owner 2026-10-25): always, cycle (2 every 15 by default) or off; saved at once (ui-standard.js)
    $fc = get_config(); $fm = $fc['FIELD_WIFI_MODE'] ?? 'always'; $fx = intval($fc['FIELD_AP_WINDOW_MIN'] ?? 2); $fy = intval($fc['FIELD_CYCLE_MIN'] ?? 15); ?>
    <div style="margin-top:8px"><label><b><?php echo t('WiFi in the field'); ?>:</b> <select data-setting="FIELD_WIFI_MODE">
      <?php foreach (array('always' => t('Always on'), 'cycle' => t('Cycle: {x} min every {y} min', array('x' => $fx, 'y' => $fy)), 'off' => t('Off (WiFi antenna off)')) as $fk => $fl) echo '<option value="' . $fk . '"' . ($fm === $fk ? ' selected' : '') . '>' . htmlspecialchars($fl) . '</option>'; ?>
    </select></label></div>
    <div class="small"><?php echo t('Always on: on with no time limit while the station has no network.'); ?><br>
      <?php echo t('Cycle: on for {x} minutes every {y} minutes; saves energy, the phone finds the station within {y} minutes.', array('x' => $fx, 'y' => $fy)); ?><br>
      <?php echo t('Off: only for {x} minutes after start-up, then the WiFi antenna turns off: no station WiFi, no WiFi network and no phone hotspot either (the cable still works), until the station is turned on again.', array('x' => $fx)); ?><br>
      <?php echo t('A phone on the station WiFi keeps it on until {i} minutes after it leaves.', array('i' => intval($fc['FIELD_AP_IDLE_MIN'] ?? 30))); ?></div>
    <?php // how the station goes back to a known network (owner 2026-10-25), field_daemon.back_home() ?>
    <details class="small" style="margin-top:6px"><summary><?php echo t('How does it go back to your networks?'); ?></summary>
      <?php echo t('With the cable unplugged, the station first tries its networks in range; the station WiFi comes only when none of them takes it.'); ?><br>
      <?php echo t('While the station WiFi is on and no phone is on it, the station looks for its WiFi networks and the phone hotspot every minute.'); ?><br>
      <?php echo t('When one of them is in range, the station WiFi turns off and the station joins it within seconds.'); ?><br>
      <?php echo t('A network out of range is never tried, so in the field the station WiFi stays on.'); ?><br>
      <?php echo t('If a network in range refuses the station (wrong password, blocked in the router), the station WiFi comes back within seconds.'); ?><br>
      <?php echo t('That network is tried again after 2, 4 and 8 minutes, then every 10 minutes on the clock (14:20, 14:30).'); ?><br>
      <?php echo t('With a phone on the station WiFi it does not look until the phone leaves; with the cable connected the WiFi stays off.'); ?></details>
  </div>
  <div class="box">
    <div class="row"><b>3. <?php echo t('In the field with internet: phone hotspot (Plan B)'); ?></b></div>
    <div><?php echo t('At home: add the phone\'s hotspot.'); ?></div>
    <div><?php echo t('In the field: turn the hotspot on in the phone and the station joins by itself.'); ?></div>
    <div><?php echo t('It uses little power and is always ready to connect.'); ?></div>
    <div><?php echo t('With more than one station, use this plan: they all join the same hotspot, each with its own address, and the station switch at the top goes from one to the other.'); ?></div>
    <div id="hotinfo" style="margin:6px 0 4px"></div>
    <div class="netform"><label for="hssid"><?php echo t('Hotspot name'); ?></label> <input id="hssid" maxlength="32">
      <label for="hpsk"><?php echo t('Password'); ?></label> <input type="password" id="hpsk" minlength="8" maxlength="63"></div>
    <div class="netact"><button type="button" class="netbtn" onclick="netSet('fleet', {ssid: v('hssid'), psk: v('hpsk')})"><?php echo t('Save'); ?></button></div>
    <div style="margin-top:6px"><?php echo t('To open the station:'); ?> <b class="mdnsname"><?php echo station_scheme(); ?>://birdsuite-…local</b><br>
      <?php // the name on any network (owner 2026-10-26); the address only for a device without names ?>
      <span class="small"><?php echo t('A device that does not open names:'); ?>
      iPhone → <b class="iphoneaddr"><?php echo station_scheme(); ?>://172.20.10.x</b>,
      Android → <?php echo t('see the address under "connected devices" of the hotspot.'); ?></span></div>
    <details class="small" style="margin-top:6px"><summary><?php echo t('Where do I find the name and password?'); ?></summary>
      <?php echo t('iPhone: the hotspot name is the phone\'s name (Settings › General › About › Name), the password is in Settings › Personal Hotspot; the station always takes the same address there, shown in the list above.'); ?><br>
      <?php echo t('Android: Settings › Connections › Mobile Hotspot and Tethering › Mobile Hotspot; set the name and password and turn it on.'); ?><br>
      <?php echo t('The station\'s address: tap BirdSuite (once connected), then Connected devices and the Info button (top right).'); ?></details>
  </div>
  <?php // its own section (owner 2026-10-24): finding the station is useful however it is reached ?>
  <div class="box">
    <div class="row"><span><b>4.</b> <?php echo t('<b>Find my station</b>: the station\'s LED blinks for 5 minutes'); ?></span></div>
    <div class="row"><button type="button" class="netbtn" onclick="netDo('identify')"><?php echo t('Identify (blink LED)'); ?></button></div>
    <div class="small"><?php echo t('To locate the station when there is more than one nearby.'); ?></div>
  </div>
  <?php // the label, short (owner 2026-10-24): print it, stick it on, scan it ?>
  <div class="box">
    <div class="row"><b>5. <?php echo t('Batch or field deployment (QR code)'); ?></b></div>
    <div class="row"><?php echo t('Print the label and stick it on the station:'); ?> <a class="netbtn" href="scripts/network.php?label=1" target="_blank" style="text-decoration:none"><?php echo t('Normal'); ?></a>
      <a class="netbtn" href="scripts/network.php?label=1&amp;psk=1" target="_blank" style="text-decoration:none" title="<?php echo htmlspecialchars(t('The WiFi password also printed as text'), ENT_QUOTES); ?>"><?php echo t('With password'); ?></a></div>
    <div><?php echo t('Scan the QR with the phone; the station page opens.'); ?></div>
    <div class="small"><?php echo t('On the Normal label the password is only inside the QR, not written.'); ?></div>
  </div>
  <div class="box">
    <div class="row"><b>6. <?php echo t('Clock'); ?></b> <span id="timeinfo"></span></div>
    <div class="row"><?php echo t('Without internet, the station takes the time from the phone:'); ?> <button type="button" class="netbtn" onclick="netTime()"><?php echo t('Use this device\'s time'); ?></button></div>
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
    var r; try { r = JSON.parse(this.responseText); } catch (e) { r = {ok: false, error: this.status === 401 ? t('Log in first.') : this.responseText}; }
    document.getElementById('netmsg').innerHTML = r.ok ? '&#10003; ' + t('Done') : '<span style="color:#c62828">' + esc(r.error) + '</span>';
    netLoad(); if (done) done(r);
  };
  x.open('POST', 'scripts/network.php?do=' + what, true); x.setRequestHeader('Content-Type', 'application/json'); x.send(JSON.stringify(body || {}));
}
function netDo(what) { netPost(what, {}); }
function netSet(what, body) { netPost(what, body, function (r) { if (r.ok) document.querySelectorAll('.net input[type=password]').forEach(function (i) { i.value = ''; }); }); }
// a known network: new name and / or password in place (empty password = keep it), or removed after a question
function netEdit(btn) {
  var row = btn.closest('.knownnet');
  if (row.querySelector('.netedit')) return;
  var f = document.createElement('div');
  f.className = 'row netedit';
  f.innerHTML = '<label>' + t('Network name') + ' <input class="en" maxlength="32"></label> <label>' + t('Password') + ' <input type="password" class="ep" maxlength="63" placeholder="' + esc(t('empty = keep it')) + '"></label> '
    + '<button type="button" class="netbtn">' + t('Save') + '</button> <button type="button" class="netbtn">' + t('Cancel') + '</button>';
  f.querySelector('.en').value = row.dataset.ssid;
  var b = f.querySelectorAll('button');
  b[0].onclick = function () { netPost('wifi-edit', {name: row.dataset.profile, ssid: f.querySelector('.en').value, psk: f.querySelector('.ep').value}); };
  b[1].onclick = function () { f.remove(); };
  row.appendChild(f);
}
function netForget(btn) {
  var row = btn.closest('.knownnet');
  if (!confirm(t('Delete the network "{name}"? The station will not join it any more.', {name: row.dataset.ssid}))) return;
  netPost('wifi-forget', {name: row.dataset.profile});
}
// one saved entry, the same in the three sections (owner 2026-10-25): icon, name and state with Edit / Delete, then the
// password with Show password; psk = 'ap' for the station's own WiFi (no Edit / Delete: its password is set below)
function netSaved(profile, ssid, html, psk) {
  return '<div class="knownnet" data-profile="' + esc(profile) + '" data-ssid="' + esc(ssid) + '" data-psk="' + psk + '"><div class="kn">' + html
    + (psk === 'ap' ? '' : ' <button type="button" class="netbtn netsm" onclick="netEdit(this)">' + t('Edit') + '</button>'
      + ' <button type="button" class="netbtn netsm" onclick="netForget(this)">' + t('Delete') + '</button>') + '</div>'
    + '<div class="kp">' + t('Password') + ': <span class="rowpsk">••••••••</span> <button type="button" class="netbtn netsm" onclick="netShowRowPsk(this)">' + t('Show password') + '</button></div></div>';
}
// a known network's password, shown on a click and hidden on the next (the same as Plans A and B)
function netShowRowPsk(btn) {
  var row = btn.closest('.knownnet'), el = row.querySelector('.rowpsk');
  if (el.dataset.shown) { el.textContent = '••••••••'; delete el.dataset.shown; btn.textContent = t('Show password'); return; }
  var x = new XMLHttpRequest();
  x.onload = function () {
    var r; try { r = JSON.parse(this.responseText); } catch (e) { r = {ok: false}; }
    if (this.status === 401) { el.textContent = t('Log in first.'); return; }
    el.textContent = r.psk ? r.psk : t('(not set)');
    el.dataset.shown = '1'; btn.textContent = t('Hide password');
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
    var nets = (s.active || []).filter(function (a) { return a.name !== 'birdsuite-ap'; }).map(function (a) { return esc(a.name) + ' (' + esc(a.device) + ')'; }).join(', ') || '<span class="warn">' + t('no network') + '</span>';
    // one item per line, all in the normal (larger) size (owner 2026-10-20)
    document.getElementById('netstatus').innerHTML = '<b>' + t('Connected to') + '</b> ' + nets
      + '<br><b>' + t('Addresses') + '</b> ' + (s.ips || []).map(function (i) { return netLink(NETSCHEME + '://' + i.ip, i.ip); }).join(', ')
      // the address to type, as a link (owner 2026-10-25: "URL", not "Name")
      + '<br><b>URL</b> ' + netLink(NETSCHEME + '://' + (s.hostname || ('birdsuite-' + (s.mac || '').replace(/:/g, ''))) + '.local')
      + '<br><b>MAC</b> ' + esc(s.mac);
    if ((s.errors || []).length) document.getElementById('netstatus').innerHTML += '<br><span class="warn">' + esc(s.errors.join('; ')) + '</span>';
    document.querySelectorAll('.apname').forEach(function (e) { e.textContent = s.ap_ssid || 'BirdSuite-…'; });
    document.querySelectorAll('.apaddr').forEach(function (e) { if (s.ap_addr) e.innerHTML = netLink(NETSCHEME + '://' + s.ap_addr); });
    // the station WiFi's state (owner 2026-10-24): not set up (no password) in bold red, on (phones, address) or off
    document.getElementById('apsaved').innerHTML = netSaved('birdsuite-ap', s.ap_ssid || '', wifiIcon(s.ap_on) + ' <b>' + esc(s.ap_ssid || 'BirdSuite-…') + '</b> '
      + (!s.ap_profile ? '<b style="color:#c62828">' + t('Not set up') + '</b>'
        : (s.ap_on ? '<span class="ok">' + t('on') + '</span>, ' + t('{n} phone(s)', {n: s.ap_clients}) + ', ' + netLink(NETSCHEME + '://' + s.ap_addr) : t('off'))), 'ap');
    // one known network per line (owner 2026-10-20)
    var hot = '';
    // the saved hotspot with the same Edit / Delete as a known network (owner 2026-10-24)
    var hotOn = (s.active || []).some(function (x) { return x.name === 'birdsuite-fleet'; });
    document.getElementById('hotinfo').innerHTML = s.fleet_profile
      ? netSaved('birdsuite-fleet', s.fleet_ssid, wifiIcon(hotOn) + ' <b>' + esc(s.fleet_ssid) + '</b>' + (hotOn ? ' <span class="ok">' + t('connected') + '</span>' : ''), 'wifi')
      : t('No hotspot saved yet.');
    // a link once the address is known (owner 2026-10-25: every URL a hyperlink)
    document.querySelectorAll('.mdnsname').forEach(function (e) { if (s.mac) e.innerHTML = netLink(NETSCHEME + '://birdsuite-' + s.mac.replace(/:/g, '').toLowerCase() + '.local'); });
    document.querySelectorAll('.iphoneaddr').forEach(function (e) { e.innerHTML = s.fleet_extra_ip ? netLink(NETSCHEME + '://' + s.fleet_extra_ip) : NETSCHEME + '://172.20.10.x'; });
    // the known networks (owner 2026-10-24: they disappeared): every WiFi profile but the station's own, the phone
    // hotspot marked, and the cable when it is connected; green = in use, orange = known
    var cable = (s.active || []).filter(function (x) { return x.type === '802-3-ethernet'; }).map(function (x) {
      // an RJ45 jack, not a power plug (owner 2026-10-24)
      return '<div class="knownnet"><div class="kn"><svg viewBox="0 0 24 24" width="16" height="16" style="vertical-align:-3px" aria-hidden="true"><path fill="currentColor" d="M4 4h16a1 1 0 0 1 1 1v11a1 1 0 0 1-1 1h-3v2a1 1 0 0 1-1 1H8a1 1 0 0 1-1-1v-2H4a1 1 0 0 1-1-1V5a1 1 0 0 1 1-1zm1 2v9h4v3h6v-3h4V6H5zm2 1.5h1.2v3H7v-3zm2.2 0h1.2v3H9.2v-3zm2.2 0h1.2v3h-1.2v-3zm2.2 0h1.2v3h-1.2v-3zm2.2 0H17v3h-1.2v-3z"/></svg> ' + t('Cable') + ' (' + esc(x.device) + ') <span class="ok">' + t('connected') + '</span></div></div>';
    }).join('');
    // the phone hotspot is in Plan B only (owner 2026-10-25: listed twice before)
    var nets = (s.known_wifi || []).filter(function (n) { return n !== 'birdsuite-fleet'; }).map(function (n) { return {name: (s.known_ssid || {})[n] || n, profile: n}; });
    document.getElementById('knownwifi').innerHTML = (nets.length || cable)
      ? t('Known networks:') + cable + nets.map(function (n) {
          var on = (s.active || []).some(function (x) { return x.name === n.profile; });
          return netSaved(n.profile, n.name, wifiIcon(on) + ' <b>' + esc(n.name) + '</b>' + (on ? ' <span class="ok">' + t('connected') + '</span>' : ''), 'wifi');
        }).join('')
      : t('No known network yet.');
    document.getElementById('timeinfo').innerHTML = esc(s.time) + ' ' + (s.time_confirmed ? '<span class="ok">' + t('confirmed') + '</span>' : '<span class="warn">' + t('not confirmed') + '</span>');
  };
  x.onerror = x.ontimeout = netLost; x.timeout = 15000;
  x.open('GET', 'scripts/network.php?status=1', true); x.send();
}
// no answer: on the WiFi network, turning the station WiFi on takes the station off that network (one radio)
function netLost() {
  document.getElementById('apinfo').innerHTML = '<span class="warn">' + t('The station does not answer. If you reach it over WiFi, its own WiFi may be on: join BirdSuite-XXXX with the phone, or wait until it turns off (30 minutes without a phone).') + '</span>';
}
netLoad();
</script>
