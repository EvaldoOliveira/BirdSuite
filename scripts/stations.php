<?php
/* Stations (owner 2026-10-20, US-117): the registry of the owner's stations — a name and one or more addresses each
 * (LAN IP, VPN IP, host name). The station name in the header opens it as a list (name and address): picking another
 * station opens the same page there, through the first of its addresses that answers, so one entry works at home and
 * over the VPN. A station is a device (where the data lives); the location of a recording is a separate thing (US-58).
 * Stored in scripts/stations.json (station data, outside git). Opened as views.php?view=Stations;
 * stations.php?json=1 answers the list for the header (static/station-switch.js). */
require_once __DIR__ . '/common.php';
$file = BS_CONFIG . '/stations.json';

function stations_load($file) {
  $list = is_file($file) ? json_decode((string)file_get_contents($file), true) : array();
  return is_array($list) ? $list : array();
}
// a name and addresses only: host names, IPv4 and IPv6 literals, an optional :port
function stations_clean($in) {
  $out = array();
  foreach (is_array($in) ? $in : array() as $s) {
    $name = trim(preg_replace('/[\x00-\x1F\x7F<>"]/', '', (string)($s['name'] ?? '')));
    $addrs = array();
    foreach ((array)($s['addresses'] ?? array()) as $a) {
      $a = trim((string)$a);
      if ($a !== '' && preg_match('/^(\[[0-9a-fA-F:.]+\]|[A-Za-z0-9.-]+)(:\d{1,5})?$/', $a)) $addrs[] = $a;
    }
    $note = trim(preg_replace('/[\x00-\x1F\x7F<>"]/', '', (string)($s['note'] ?? '')));
    $mac = strtolower(trim((string)($s['mac'] ?? '')));
    if (!preg_match('/^([0-9a-f]{2}:){5}[0-9a-f]{2}$/', $mac)) $mac = '';
    if ($name === '' || !$addrs) continue;
    $e = array('name' => mb_substr($name, 0, 60), 'addresses' => array_values(array_unique($addrs)), 'mac' => $mac, 'note' => mb_substr($note, 0, 120));
    // the last position the station reported (map, owner 2026-10-27): kept through a save of the table
    if (isset($s['lat'], $s['lon']) && is_numeric($s['lat']) && is_numeric($s['lon']) && abs($s['lat']) <= 90 && abs($s['lon']) <= 180) {
      $e['lat'] = round(floatval($s['lat']), 4); $e['lon'] = round(floatval($s['lon']), 4);
      $e['where'] = mb_substr(trim(preg_replace('/[\x00-\x1F\x7F<>"]/', '', (string)($s['where'] ?? ''))), 0, 60);
      $e['seen'] = preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}$/', (string)($s['seen'] ?? '')) ? $s['seen'] : '';
      $e['places'] = stations_clean_places($s['places'] ?? array());
    }
    $out[] = $e;
  }
  return $out;
}

// the other locations of a station, as it reported them (name and coordinates)
function stations_clean_places($in) {
  $out = array();
  foreach (is_array($in) ? array_slice($in, 0, 200) : array() as $l) {
    if (!isset($l['lat'], $l['lon']) || !is_numeric($l['lat']) || !is_numeric($l['lon']) || abs($l['lat']) > 90 || abs($l['lon']) > 180) continue;
    $out[] = array('name' => mb_substr(trim(preg_replace('/[\x00-\x1F\x7F<>"]/', '', (string)($l['name'] ?? ''))), 0, 60),
      'lat' => round(floatval($l['lat']), 4), 'lon' => round(floatval($l['lon']), 4));
  }
  return $out;
}

// this station is always in the list (owner 2026-10-24: the home station, registered by itself, never removed): first,
// with its name, its host name and LAN address and its MAC, when no entry has its MAC (or one of its addresses)
function stations_with_here($list) {
  $mac = station_mac();
  $here = array_filter(array(strtolower(gethostname()) . '.local', station_ip()));
  foreach ($list as $i => $st) {
    if (($mac !== '' && strtolower($st['mac'] ?? '') === strtolower($mac))
        || (empty($st['mac']) && array_intersect(array_map('strtolower', $st['addresses'] ?? array()), $here))) {
      $list[$i]['mac'] = $mac;
      return $list;
    }
  }
  array_unshift($list, array('name' => get_sitename(), 'addresses' => array_values($here), 'mac' => $mac, 'note' => ''));
  return $list;
}

// this station's LAN address: the web server does not pass SERVER_ADDR, so the first IPv4 address of the host
function station_ip() {
  $ip = $_SERVER['SERVER_ADDR'] ?? '';
  if ($ip === '' || $ip === '127.0.0.1' || $ip === '::1') {
    foreach (preg_split('/\s+/', trim((string)shell_exec('hostname -I 2>/dev/null'))) as $a) {
      if (filter_var($a, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) { $ip = $a; break; }
    }
  }
  return $ip;
}

// where this station is now: the coordinates and the name of its current location
function station_here_position() {
  $c = get_config();
  $cur = location_current();
  // the other locations registered on it (Test / Playback is at the first one's place: left out)
  $places = array();
  foreach (locations_load() as $l) {
    if (($l['builtin'] ?? '') === 'test' || ($cur && intval($l['id']) === intval($cur['id']))) continue;
    if (abs(floatval($l['lat'])) < 0.0001 && abs(floatval($l['lon'])) < 0.0001) continue;
    $places[] = array('name' => $l['name'], 'lat' => round(floatval($l['lat']), 4), 'lon' => round(floatval($l['lon']), 4));
  }
  return array('lat' => round(floatval($c['LATITUDE'] ?? 0), 4), 'lon' => round(floatval($c['LONGITUDE'] ?? 0), 4), 'where' => $cur['name'] ?? '',
    'places' => $places);
}

// what this station tells the others about itself: its ID (MAC), name and position (?json, the map, the network search)
function station_announcement() {
  return array_merge(array('mac' => station_mac(), 'site' => get_sitename()), station_here_position());
}
// the health of this station for the fleet view (US-58): version, recording, last detection, disk, clock, internet
function station_health() {
  exec('systemctl is-active --quiet ' . escapeshellarg(BS_SVC_RECORDING . '.service'), $o, $rc);
  $last = null;
  try { $last = get_db()->querySingle("SELECT Date || ' ' || Time FROM detections ORDER BY Date DESC, Time DESC LIMIT 1"); } catch (Throwable $e) {}
  $free = @disk_free_space(BS_RECS); $total = @disk_total_space(BS_RECS);
  $fs = json_decode((string)@file_get_contents('/run/birdsuite/field_state.json'), true) ?: array();
  return array('version' => (string)(installed_release()[0] ?? ''), 'recording' => $rc === 0, 'last' => (string)$last,
    'disk' => $total ? intval(round(100 * (1 - $free / $total))) : null, 'clock' => time_confirmed(), 'online' => !empty($fs['online']),
    'level' => ui_level(), 'context' => (string)(get_config()['UI_CONTEXT'] ?? ''));
}
// positions are private (owner rule, as location_switch.php): coordinates, location names and the other places only to
// a caller that is logged in (an open station, without a web password, counts as logged in)
function stations_public($a) {
  if (is_authenticated()) return $a;
  unset($a['lat'], $a['lon'], $a['where'], $a['places'], $a['seen'], $a['health']);
  foreach (array('stations') as $k) if (isset($a[$k]) && is_array($a[$k])) $a[$k] = array_map('stations_public', $a[$k]);
  return $a;
}
// the pages of another station of the owner may read ?json=1 (the header's station list, the Stations page): only
// from the address of a registered station, a .local name or a private network address, never any web site
function stations_origin_ok($origin, $list) {
  $host = strtolower((string)parse_url($origin, PHP_URL_HOST));
  if ($host === '') return false;
  foreach ($list as $st) foreach ((array)($st['addresses'] ?? array()) as $a) {
    if (strtolower(preg_replace('/:\d+$/', '', trim($a, '[]'))) === trim($host, '[]')) return true;
  }
  if (preg_match('/^[a-z0-9-]+\.local$/', $host)) return true;
  return filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) && !filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE);
}
// Search the network (owner 2026-10-27): the stations of the local network answer the broadcast of mDNS (avahi, the
// _http._tcp service every station publishes); each address found is asked for its stations.php?json=1, so only
// BirdSuite stations come back, with their ID, name and position, and the unique address birdsuite-<ID>.local. Only the
// same local network: a broadcast does not cross the VPN.
if (isset($_GET['discover'])) {
  header('Content-Type: application/json');
  $ips = array();
  foreach (preg_split('/\n/', (string)shell_exec('timeout 5 avahi-browse -rpt _http._tcp 2>/dev/null')) as $line) {
    $f = explode(';', $line);
    if (($f[0] ?? '') === '=' && ($f[2] ?? '') === 'IPv4' && filter_var($f[7] ?? '', FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) $ips[$f[7]] = intval($f[8] ?? 80);
  }
  $mine = preg_split('/\s+/', trim((string)shell_exec('hostname -I 2>/dev/null')));
  $mh = curl_multi_init(); $hs = array();
  foreach ($ips as $ip => $port) {
    if (in_array($ip, $mine, true) || $ip === '127.0.0.1') continue;
    $h = curl_init('http://' . $ip . ($port && $port !== 80 ? ':' . $port : '') . '/scripts/stations.php?json=1');
    curl_setopt_array($h, array(CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => 3, CURLOPT_TIMEOUT => 4, CURLOPT_FOLLOWLOCATION => false));
    curl_multi_add_handle($mh, $h); $hs[$ip] = $h;
  }
  if ($hs) { do { $st = curl_multi_exec($mh, $run); if ($run) curl_multi_select($mh, 1); } while ($run && $st === CURLM_OK); }
  $out = array(); $seen = array();
  foreach ($hs as $ip => $h) {
    $j = json_decode((string)curl_multi_getcontent($h), true);
    curl_multi_remove_handle($mh, $h); curl_close($h);
    $mac = strtolower((string)($j['mac'] ?? ''));
    if (!is_array($j) || !preg_match('/^([0-9a-f]{2}:){5}[0-9a-f]{2}$/', $mac) || $mac === station_mac() || isset($seen[$mac])) continue;
    $seen[$mac] = true;
    $has = isset($j['lat'], $j['lon']) && is_numeric($j['lat']) && is_numeric($j['lon']) && !(floatval($j['lat']) == 0 && floatval($j['lon']) == 0);
    $out[] = array('name' => (string)($j['site'] ?? ''), 'mac' => $mac, 'addresses' => array('birdsuite-' . str_replace(':', '', $mac) . '.local', $ip),
      'lat' => $has ? floatval($j['lat']) : null, 'lon' => $has ? floatval($j['lon']) : null, 'where' => (string)($j['where'] ?? ''),
      'places' => is_array($j['places'] ?? null) ? $j['places'] : array(), 'seen' => date('Y-m-d H:i'));
  }
  curl_multi_close($mh);
  echo json_encode(stations_public(array('stations' => $out)));
  die();
}

if (isset($_GET['json'])) {
  header('Content-Type: application/json');
  // another station's switch and Stations page read the name, IP and MAC of this one (read-only); its position only
  // with the login (the browser sends the login saved for this station when the page asks with credentials)
  $list = stations_with_here(stations_load($file));
  header('Vary: Origin');
  $origin = (string)($_SERVER['HTTP_ORIGIN'] ?? '');
  if ($origin !== '' && stations_origin_ok($origin, $list)) {
    header('Access-Control-Allow-Origin: ' . $origin);
    header('Access-Control-Allow-Credentials: true');
  }
  $out = array_merge(array('ip' => station_ip()), station_announcement(), array('stations' => $list));
  // the fleet view (US-58): the station's health, to a logged-in caller only (as the positions)
  if (isset($_GET['health']) && is_authenticated()) $out['health'] = station_health();
  echo json_encode(stations_public($out));
  die();
}
// The map of the stations (owner 2026-10-27): this station asks each registered station (its stations.php?json=1, all at
// once, the first address that answers) where it is. Green = it answered: its current location, kept here with the time;
// red = no answer: the last position kept, with the time it was seen; never seen = no position. Yellow: the other
// locations registered on each station, with their names. One layer per station, with its checkbox on the map.
if (isset($_GET['map'])) {
  header('Content-Type: application/json');
  $list = stations_with_here(stations_load($file));
  $mac = station_mac();
  $mh = function_exists('curl_multi_init') ? curl_multi_init() : null;
  $hs = array();
  foreach ($list as $i => $st) {
    if ($mac !== '' && strtolower($st['mac'] ?? '') === $mac) continue;
    foreach ($st['addresses'] ?? array() as $a) {
      if ($mh === null) break;
      $h = curl_init('http://' . $a . '/scripts/stations.php?json=1');
      curl_setopt_array($h, array(CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => 3, CURLOPT_TIMEOUT => 4, CURLOPT_FOLLOWLOCATION => false));
      curl_multi_add_handle($mh, $h);
      $hs[] = array($i, $h);
    }
  }
  if ($mh !== null && $hs) {
    do { $st = curl_multi_exec($mh, $run); if ($run) curl_multi_select($mh, 1); } while ($run && $st === CURLM_OK);
  }
  $now = date('Y-m-d H:i');
  $up = array();
  $changed = false;
  foreach ($hs as $p) {
    list($i, $h) = $p;
    $j = json_decode((string)curl_multi_getcontent($h), true);
    curl_multi_remove_handle($mh, $h);
    curl_close($h);
    if (isset($up[$i]) || !is_array($j) || !isset($j['site'])) continue;
    // an answer from another device at that address (a MAC that is not the registered one) does not count
    if (!empty($list[$i]['mac']) && !empty($j['mac']) && strtolower($j['mac']) !== strtolower($list[$i]['mac'])) continue;
    $up[$i] = true;
    if (isset($j['lat'], $j['lon']) && is_numeric($j['lat']) && is_numeric($j['lon']) && !(floatval($j['lat']) == 0 && floatval($j['lon']) == 0)) {
      $list[$i]['lat'] = round(floatval($j['lat']), 4); $list[$i]['lon'] = round(floatval($j['lon']), 4);
      $list[$i]['where'] = (string)($j['where'] ?? ''); $list[$i]['seen'] = $now;
      $list[$i]['places'] = stations_clean_places($j['places'] ?? array());
      $changed = true;
    }
  }
  if ($mh !== null) curl_multi_close($mh);
  if ($changed && is_file($file)) file_put_contents($file, json_encode(stations_clean($list), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n", LOCK_EX);
  $here = station_here_position();
  $out = array();
  foreach ($list as $i => $st) {
    $is_here = $mac !== '' && strtolower($st['mac'] ?? '') === $mac;
    if ($is_here) $st = array_merge($st, $here, array('seen' => $now));
    $has = isset($st['lat'], $st['lon']) && !(floatval($st['lat']) == 0 && floatval($st['lon']) == 0);
    $out[] = array('name' => $st['name'], 'mac' => $st['mac'] ?? '', 'address' => $st['addresses'][0] ?? '', 'here' => $is_here, 'up' => $is_here || isset($up[$i]),
      'lat' => $has ? $st['lat'] : null, 'lon' => $has ? $st['lon'] : null, 'where' => $st['where'] ?? '', 'seen' => $st['seen'] ?? '',
      'places' => $st['places'] ?? array());
  }
  echo json_encode(stations_public(array('stations' => $out)));
  die();
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_GET['save'])) {
  ensure_authenticated(t('str_you_must_change_the_stations_help'));
  $list = stations_with_here(stations_clean(json_decode((string)file_get_contents('php://input'), true)));
  $ok = file_put_contents($file, json_encode($list, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n", LOCK_EX) !== false;
  echo $ok ? 'OK ' . count($list) : t('str_error_cannot_write', array('file' => basename($file)));
  die();
}
$h = function ($s) { return htmlspecialchars((string)$s, ENT_QUOTES); };
?>
<style>
.stn { max-width: 900px; margin: 0; text-align: left; padding: 0 12px; }
.stn table.stdtable input[type=text] { width: 100%; box-sizing: border-box; padding: 4px 6px; }
.stn table.stdtable td.addr { min-width: 260px; }
.stn table.stdtable tr.here td { background: rgba(var(--accent-rgb,43,94,34), .10); }
.stn .herebadge { font-size: 11px; font-weight: 600; color: inherit; margin-bottom: 2px; }
.stn table.stdtable td.ord { width: 1%; min-width: 8em; }
.stn #stntable td.ord .ordrow { display: flex; gap: 6px; margin: 3px 0; }
.stn #stntable td.ord .ordrow > * { flex: 1 1 0; text-align: center; box-sizing: border-box; }
/* a phone (owner 2026-10-25, as Settings › Locations): one card per station, each field under its title */
@media (max-width: 700px) {
  .stn #stntable, .stn #stntable tbody, .stn #stntable tr, .stn #stntable td { display: block; width: 100%; box-sizing: border-box; }
  .stn #stntable tr:first-child { display: none; }
  .stn #stntable tr { margin: 10px 0; padding: 6px 10px; border: 1px solid rgba(0,0,0,.12); border-radius: 10px; }
  .stn #stntable td { border: 0; padding: 4px 0; min-width: 0; }
  .stn #stntable td::before { content: attr(data-label); display: block; font-size: 12px; font-weight: 600; opacity: .7; margin-bottom: 2px; }
  .stn #stntable td.mac { white-space: normal; }
}
.stn .stnbtn { width: auto; padding: 4px 12px; border-radius: 12px; border: 1px solid var(--accent,#2b5e22); background: #fff; color: var(--accent,#2b5e22); font-weight: 600; cursor: pointer; }
.stn .stnbtn:hover { background: var(--accent,#2b5e22); color: #fff; }
.stn .stnbtn.del { border-color: #c62828; color: #c62828; }
.stn .stnbtn.del:hover { background: #c62828; color: #fff; }
.stn .stnbtn.small { padding: 2px 8px; font-size: .85em; }
.stn .probe { font-size: .85em; margin-top: 3px; }
.stn .probe .up { color: #2e7d32; font-weight: 600; }
.stn .probe .down { color: #9e9e9e; text-decoration: line-through; }
.stn .bar { display: flex; flex-wrap: wrap; gap: 8px; align-items: center; margin: 12px 0; }
.stn #stnmsg { font-weight: 600; }
/* the map and, beside it, the list of the stations (owner 2026-10-27: outside the map, it can grow); under it on a phone */
.stn .stn-map-wrap { display: flex; gap: 10px; max-width: 1100px; margin: 6px 0; }
.stn #stnmap { flex: 1 1 auto; min-width: 0; height: 360px; border-radius: 8px; }
.stn .stn-map-list { flex: 0 0 240px; max-height: 360px; overflow: auto; box-sizing: border-box; padding: 6px 8px; font-size: 13px;
  border-radius: 8px; background: rgba(255,255,255,.55); }
.stn .stn-map-row { display: flex; gap: 6px; align-items: flex-start; padding: 4px 0; border-bottom: 1px solid rgba(0,0,0,.08); }
.stn .stn-map-row:last-child { border-bottom: 0; }
.stn .stn-map-row a { color: inherit; text-decoration: none; display: block; }
.stn .stn-map-row a:hover { text-decoration: underline; }
.stn .stn-map-name { font-weight: 600; }
.stn .stn-map-sub { font-size: 11px; opacity: .75; margin-bottom: 2px; }
.stn .stn-map-place { font-size: 12px; padding-left: 4px; }
/* targets of at least 24 px (WCAG 2.5.8, release run 4) */
.stn .stn-map-row a.stn-map-name, .stn .stn-map-row a.stn-map-place { min-height: 24px; display: flex; align-items: center; gap: 4px; }
.stn .stn-map-row input[type=checkbox] { width: 18px; height: 18px; margin: 3px; }
@media (max-width: 700px) { .stn .stn-map-wrap { flex-direction: column; } .stn .stn-map-list { flex: none; max-height: 220px; } }
</style>
<div class="stn">
  <?php $cfg = get_config(); $tz_now = trim((string)shell_exec('timedatectl show --value --property=Timezone')); ?>
  <?php // owner's layout (2026-10-20): what the list is, the table, its buttons, the notes, Export / Import ?>
  <?php // the map of the stations (owner 2026-10-27, static/station-map.js) ?>
  <div class="stn-map-wrap"><div id="stnmap"></div><div class="stn-map-list"></div></div>
  <div id="stnmapnote" style="font-size:12px;opacity:.85;margin-bottom:6px"></div>
  <div class="bar" style="margin-top:0">
    <button type="button" class="stnbtn" onclick="stationMap(document.getElementById('stnmap'), document.getElementById('stnmapnote'))" title="<?php echo $h(t('str_asks_every_registered_station_help')); ?>"><?php echo t('str_refresh'); ?></button>
    <button type="button" class="stnbtn" onclick="stnDiscover()" title="<?php echo $h(t('str_finds_the_birdsuite_stations_of_help')); ?>"><?php echo t('str_search_the_network'); ?></button>
  </div>
  <div id="stnfound" style="display:none;margin:0 0 12px;padding:8px 10px;border:1px solid rgba(0,0,0,.15);border-radius:8px"></div>
  <p><?php echo t('str_add_here_the_list_of_stations_help'); ?></p>
  <?php // one place for the names (owner 2026-10-20): the row of this station (same MAC) is marked; its name, saved with
        // the list, is also this station's own name (SITE_NAME: header, notifications); empty = BirdSuite-<MAC> ?>
  <table class="stdtable" id="stntable" data-nosort>
    <tr><th><?php echo t('str_name_alias'); ?></th><th><?php echo t('str_addresses'); ?></th><th>ID</th><th><?php echo t('str_note'); ?></th><th><?php echo t('str_order'); ?></th></tr>
  </table>
  <?php // the fleet (US-58): each station's health, read by this browser from the station itself with its own login ?>
  <h3 style="margin:14px 0 4px"><?php echo t('str_fleet'); ?> <button type="button" class="stnbtn small" onclick="stnFleet()"><?php echo t('str_refresh'); ?></button></h3>
  <table class="stdtable" id="stnfleet" data-nosort>
    <tr><th><?php echo t('str_station'); ?></th><th><?php echo t('str_state_lc'); ?></th><th><?php echo t('str_version'); ?></th><th><?php echo t('str_recording'); ?></th><th><?php echo t('str_last_detection'); ?></th><th><?php echo t('str_disk'); ?></th><th><?php echo t('str_clock'); ?></th><th><?php echo t('str_internet'); ?></th></tr>
  </table>
  <p class="small"><?php echo t('str_fleet_help'); ?></p>
  <div class="bar">
    <button type="button" class="stnbtn" onclick="stnAdd()"><?php echo t('str_plus_add_station'); ?></button>
    <button type="button" class="stnbtn" onclick="stnTestAll()"><?php echo t('str_test_addresses'); ?></button>
    <button type="button" class="stnbtn" onclick="stnSync()" title="<?php echo $h(t('str_reads_the_list_of_another_help')); ?>"><?php echo t('str_sync_with_a_station'); ?></button>
    <button type="button" class="stnbtn" onclick="stnSave()"><?php echo t('str_save'); ?></button>
    <span id="stnmsg"></span>
  </div>
  <div id="stnsync" style="display:none;margin:0 0 12px;padding:8px 10px;border:1px solid rgba(0,0,0,.15);border-radius:8px"></div>
  <p><?php echo t('str_if_more_than_one_station_is_help'); ?><br>
    <?php echo t('str_the_timezone_and_the_species_help'); ?><br>
    <?php echo t('str_if_this_station_is_left_empty_help'); ?><br>
    <span style="display:inline-block;padding-left:2em;font-size:.8em"><?php echo t('str_for_example_birdsuite_3f9a1c7b2e04'); ?></span></p>
  <p><?php echo t('str_with_more_than_one_station_help'); ?></p>
  <div class="bar">
    <button type="button" class="stnbtn" onclick="stnExport()" title="<?php echo $h(t('str_downloads_the_list_to_import_it_help')); ?>"><?php echo t('str_export'); ?></button>
    <label class="stnbtn" title="<?php echo $h(t('str_adds_the_stations_of_an_help')); ?>"><?php echo t('str_import'); ?><input type="file" accept="application/json,.json" style="display:none" onchange="stnImport(this)"></label>
  </div>
</div>
<script src="static/species-modal.js?v=<?php echo @filemtime(__DIR__ . '/../homepage/static/species-modal.js'); ?>"></script>
<script src="static/location-picker.js?v=<?php echo @filemtime(__DIR__ . '/../homepage/static/location-picker.js'); ?>"></script>
<script src="static/station-map.js?v=<?php echo @filemtime(__DIR__ . '/../homepage/static/station-map.js'); ?>"></script>
<script src="static/station-switch.js?v=<?php echo @filemtime(__DIR__ . "/../homepage/static/station-switch.js"); ?>"></script>
<script>
var STN_SITE = <?php echo json_encode(get_sitename()); ?>, STN_IP = <?php echo json_encode(station_ip()); ?>, STN_MAC = <?php echo json_encode(station_mac()); ?>;
var stnList = <?php echo json_encode(stations_with_here(stations_load($file))); // the home station always in it ?>;
// this station's own position, so an exported list carries it too (owner 2026-10-27: positions travel with Export / Import)
var STN_HERE_POS = <?php echo json_encode(array_merge(station_here_position(), array('seen' => date('Y-m-d H:i')))); ?>;
var stnHereEl = document.getElementById('stnhere');
if (stnHereEl) stnHereEl.textContent = location.host + (STN_IP && STN_IP !== location.host ? ' (' + STN_IP + ')' : '');
function stnThis(name) {
  var x = new XMLHttpRequest();
  x.onload = function () {
    var t = this.responseText.trim();
    if (t !== 'OK') { document.getElementById('stnmsg').textContent = window.t('str_the_list_is_saved_the_station_help', {reason: (this.status === 401 ? window.t('str_log_in_first_lc_dot') : t)}); return; }
    if (window.top) window.top.location.reload(); else location.reload();
  };
  x.open('POST', 'scripts/locations.php?station=1', true); x.setRequestHeader('Content-Type', 'application/json');
  x.send(JSON.stringify({site_name: name}));
}
var STN_DEFAULT = <?php echo json_encode(default_sitename()); ?>;
// the ID of a station is the MAC of its own network port (the name is an alias, owner 2026-10-27): its WiFi name
// (BirdSuite-<MAC>) on top, the MAC under it
function stnIdCell(td, mac) {
  var w = stationWifi(mac);
  td.textContent = w || mac || '';
  if (w) { var d = document.createElement('div'); d.style.cssText = 'opacity:.7;font-size:11px'; d.textContent = mac; td.appendChild(d); }
}
function stnIsHere(s) { return !!(s.mac && STN_MAC && s.mac.toLowerCase() === STN_MAC.toLowerCase()); }
function stnRender() {
  var t = document.getElementById('stntable');
  while (t.rows.length > 1) t.deleteRow(1);
  stnList.forEach(function (s, i) {
    var tr = t.insertRow(-1);
    // the column titles on each cell for the cards of a phone, the buttons in lines of equal width (owner 2026-10-25: as
    // Settings › Locations)
    var L = function (k) { return ' data-label="' + String(window.t(k)).replace(/"/g, '&quot;') + '"'; };
    tr.innerHTML = '<td' + L('str_name') + '><input type="text" data-k="name"></td><td class="addr"' + L('str_addresses') + '><input type="text" data-k="addresses"><div class="probe"></div></td>'
      + '<td class="mac" data-label="ID" style="font-family:monospace;font-size:12px;white-space:nowrap"></td><td' + L('str_note') + '><input type="text" data-k="note"></td>'
      + '<td class="ord"' + L('str_order') + '><div class="ordrow"><button type="button" class="stnbtn small" title="' + window.t('str_up') + '" data-mv="-1">&#9650;</button><button type="button" class="stnbtn small" title="' + window.t('str_down') + '" data-mv="1">&#9660;</button></div>'
      + '<div class="ordrow"><button type="button" class="stnbtn small" data-t="1">' + window.t('str_test') + '</button></div><div class="ordrow"><button type="button" class="stnbtn small del" data-rm="1">' + window.t('str_remove') + '</button></div></td>';
    tr.querySelector('[data-k=name]').value = s.name || '';
    if (stnIsHere(s)) {
      tr.classList.add('here');
      tr.querySelector('[data-k=name]').placeholder = STN_DEFAULT;
      tr.cells[0].insertAdjacentHTML('afterbegin', '<div class="herebadge">' + window.t('str_home_this_station') + '</div>');
      var rm = tr.querySelector('[data-rm]'); if (rm) rm.parentNode.remove();  // the home station cannot be removed
    }
    tr.querySelector('[data-k=addresses]').value = (s.addresses || []).join(', ');
    tr.querySelector('[data-k=note]').value = s.note || '';
    stnIdCell(tr.querySelector('td.mac'), s.mac);
    tr.querySelectorAll('input').forEach(function (inp) { inp.oninput = function () { stnRead(); }; });
    tr.querySelectorAll('[data-mv]').forEach(function (b) { b.onclick = function () {
      stnRead();
      var j = i + parseInt(b.dataset.mv, 10);
      if (j < 0 || j >= stnList.length) return;
      var x = stnList[i]; stnList[i] = stnList[j]; stnList[j] = x;
      stnRender();
    }; });
    // Remove asks first through the app's modal, No is the default answer (owner 2026-11-01)
    var rmb = tr.querySelector('[data-rm]'); if (rmb) rmb.onclick = function () {
      stnRead();
      var nm = stnList[i].name || (stnList[i].addresses || []).join(', ');
      bsAsk(window.t('str_remove_the_station_from_the_list', {name: nm}), true).then(function (ok) { if (!ok) return; stnRead(); stnList.splice(i, 1); stnRender(); });
    };
    tr.querySelector('[data-t]').onclick = function () { stnRead(); stnTest(tr, stnList[i]); };
  });
}
// the table back into stnList (addresses split on commas / spaces)
function stnRead() {
  var rows = document.getElementById('stntable').rows;
  for (var r = 1; r < rows.length; r++) {
    var s = stnList[r - 1];
    s.name = rows[r].querySelector('[data-k=name]').value.trim();
    s.addresses = rows[r].querySelector('[data-k=addresses]').value.split(/[\s,;]+/).filter(Boolean);
    s.note = rows[r].querySelector('[data-k=note]').value.trim();
  }
}
function stnTest(tr, s) {
  var box = tr.querySelector('.probe');
  box.textContent = t('str_testing');
  var out = [];
  (s.addresses || []).forEach(function (a, k) {
    stationProbe(a, 2500, function (ok) {
      out[k] = '<span class="' + (ok ? 'up' : 'down') + '">' + a.replace(/</g, '') + '</span>';
      box.innerHTML = out.filter(Boolean).join(', ');
    });
  });
  if (!(s.addresses || []).length) box.textContent = t('str_no_address');
  // the station's own name, IP and MAC (its stations.php?json=1 answers other stations)
  (s.addresses || []).forEach(function (a) {
    stationInfo(a, function (j) {
      if (!j || !j.mac) return;
      s.mac = j.mac;
      stnIdCell(tr.querySelector('td.mac'), j.mac);
    });
  });
}
function stnTestAll() {
  stnRead();
  var rows = document.getElementById('stntable').rows;
  for (var r = 1; r < rows.length; r++) stnTest(rows[r], stnList[r - 1]);
}
function stnAdd() { stnRead(); stnList.push({name: '', addresses: [], note: ''}); stnRender(); }
function stnSave() {
  stnRead();
  var msg = document.getElementById('stnmsg');
  stnList.forEach(function (s) { if (stnIsHere(s) && !s.name) s.name = STN_DEFAULT; });
  var bad = stnList.filter(function (s) { return !s.name || !s.addresses.length; }).length;
  if (bad) { msg.textContent = t('str_each_station_needs_a_name_and_help'); return; }
  var x = new XMLHttpRequest();
  x.onload = function () {
    msg.textContent = /^OK/.test(this.responseText) ? t('str_saved_the_list_at_the_top_is_help') : (this.status === 401 ? t('str_log_in_first') : t('str_not_saved_error', {error: this.responseText}));
    if (!/^OK/.test(this.responseText)) return;
    if (window.top && window.top.stationSwitchReload) window.top.stationSwitchReload();
    // the name of this station's row becomes the station's own name (the header changes: the page reloads)
    var here = stnList.filter(stnIsHere)[0];
    if (here && here.name !== STN_SITE) stnThis(here.name === STN_DEFAULT ? '' : here.name);
  };
  x.open('POST', 'scripts/stations.php?save=1', true);
  x.setRequestHeader('Content-Type', 'application/json');
  x.send(JSON.stringify(stnList));
}
function stnExport() {
  stnRead();
  stnList.forEach(function (s) { if (stnIsHere(s)) Object.assign(s, STN_HERE_POS); });
  var a = document.createElement('a');
  a.href = URL.createObjectURL(new Blob([JSON.stringify(stnList, null, 2)], {type: 'application/json'}));
  a.download = 'stations.json';
  a.click();
}
// one station of an exported list or of another station's list into this one: the same station = the same ID (MAC; two
// stations may share a name), without a MAC the same name; the MAC comes along (2026-10-23 test run: it was dropped) and
// the server keeps it only when well formed; the last position too (owner 2026-10-27), when newer than the one known here;
// this station's own entry is never replaced (its position is its own)
function stnMerge(s) {
  if (!s || !s.name) return;
  var m = String(s.mac || '').toLowerCase();
  var k = stnList.findIndex(function (x) { return m ? String(x.mac || '').toLowerCase() === m : (!x.mac && x.name === s.name); });
  var old = k >= 0 ? stnList[k] : null;
  if (old && stnIsHere(old)) return;
  if (m && STN_MAC && m === STN_MAC.toLowerCase()) return;
  var e = {name: String(s.name), addresses: (s.addresses || []).map(String), mac: String(s.mac || ''), note: String(s.note || '')};
  var pos = function (x) { return x && x.lat != null && x.lon != null; };
  var src = pos(s) && (!pos(old) || String(s.seen || '') > String(old.seen || '')) ? s : old;
  if (pos(src)) { e.lat = src.lat; e.lon = src.lon; e.where = String(src.where || ''); e.seen = String(src.seen || ''); e.places = src.places || []; }
  if (k >= 0) stnList[k] = e; else stnList.push(e);
}
// Sync (owner 2026-10-27): the list of another registered station (its stations.php?json=1, first address that answers);
// the stations it has that are not here are offered with a checkbox, Add puts the chosen ones in this list (Save keeps
// them). The other station itself comes with its position.
function stnSync() {
  stnRead();
  var box = document.getElementById('stnsync'), msg = document.getElementById('stnmsg');
  var others = stnList.filter(function (s) { return !stnIsHere(s) && (s.addresses || []).length; });
  if (!others.length) { msg.textContent = t('str_register_another_station_first_help'); return; }
  box.innerHTML = '<b>' + t('str_read_the_list_of') + '</b> ' + others.map(function (s, i) {
    return '<button type="button" class="stnbtn small" data-o="' + i + '">' + stationLabel(s, stnList).replace(/</g, '&lt;') + '</button>';
  }).join(' ') + ' <button type="button" class="stnbtn small del" data-x="1">' + t('str_close') + '</button><div class="syncl"></div>';
  box.style.display = '';
  box.querySelector('[data-x]').onclick = function () { box.style.display = 'none'; };
  box.querySelectorAll('[data-o]').forEach(function (b) { b.onclick = function () {
    var st = others[parseInt(b.dataset.o, 10)], out = box.querySelector('.syncl'), addrs = st.addresses.slice();
    out.textContent = t('str_reading');
    (function next() {
      if (!addrs.length) { out.textContent = t('str_the_station_does_not_answer'); return; }
      stationInfo(addrs.shift(), function (j) {
        if (!j || !j.stations) { next(); return; }
        var have = function (s) { var m = String(s.mac || '').toLowerCase(); return stnList.some(function (x) { return m ? String(x.mac || '').toLowerCase() === m : x.name === s.name; }); };
        var fresh = j.stations.filter(function (s) { return !have(s) && !(s.mac && STN_MAC && s.mac.toLowerCase() === STN_MAC.toLowerCase()); });
        // the other station itself: its own entry in its list takes its position
        j.stations.forEach(function (s) { if (s.mac && j.mac && s.mac.toLowerCase() === j.mac.toLowerCase() && j.lat != null) { s.lat = j.lat; s.lon = j.lon; s.where = j.where; s.places = j.places; s.seen = new Date().toISOString().slice(0, 16).replace('T', ' '); } });
        stnMerge(j.stations.filter(function (s) { return s.mac && j.mac && s.mac.toLowerCase() === j.mac.toLowerCase(); })[0]);
        if (!fresh.length) { out.textContent = t('str_every_station_of_that_list_is_help'); stnRender(); return; }
        out.innerHTML = fresh.map(function (s, i) {
          return '<label style="display:block"><input type="checkbox" data-f="' + i + '" checked> ' + stationLabel(s, j.stations).replace(/</g, '&lt;')
            + ' <small style="opacity:.7">' + (s.mac ? 'ID ' + s.mac : '') + ' · ' + (s.addresses || []).join(', ').replace(/</g, '&lt;') + '</small></label>';
        }).join('') + '<button type="button" class="stnbtn small" data-add="1">' + t('str_add_the_chosen_stations') + '</button>';
        out.querySelector('[data-add]').onclick = function () {
          var n = 0;
          out.querySelectorAll('[data-f]').forEach(function (c) { if (c.checked) { stnMerge(fresh[parseInt(c.dataset.f, 10)]); n++; } });
          stnRender();
          box.style.display = 'none';
          msg.textContent = t('str_added_press_save_to_keep_them', {count: n});
        };
      });
    })();
  }; });
}
// the stations the network search found that are not registered here, each with a checkbox; Add puts the chosen ones in
// the list (Save keeps them), with their position
function stnDiscover() {
  stnRead();
  var box = document.getElementById('stnfound');
  box.style.display = '';
  box.textContent = t('str_searching_the_local_network');
  var x = new XMLHttpRequest();
  x.timeout = 20000;
  x.onload = function () {
    var j = null;
    try { j = JSON.parse(this.responseText); } catch (e) {}
    var have = function (s) { return stnList.some(function (o) { return String(o.mac || '').toLowerCase() === s.mac; }); };
    var fresh = (j && j.stations || []).filter(function (s) { return !have(s); });
    var close = ' <button type="button" class="stnbtn small del" onclick="document.getElementById(\'stnfound\').style.display=\'none\'">' + t('str_close') + '</button>';
    if (!fresh.length) { box.innerHTML = t('str_no_other_station_found_on_this_help') + close; return; }
    box.innerHTML = '<b>' + t('str_found_on_this_network') + '</b>' + fresh.map(function (s, i) {
      return '<label style="display:block"><input type="checkbox" data-f="' + i + '" checked> ' + String(s.name).replace(/</g, '&lt;')
        + ' <small style="opacity:.7">ID ' + s.mac + (s.where ? ' · ' + String(s.where).replace(/</g, '&lt;') : '') + ' · ' + s.addresses.join(', ') + '</small></label>';
    }).join('') + '<button type="button" class="stnbtn small" data-add="1">' + t('str_add_the_chosen_stations') + '</button>' + close;
    box.querySelector('[data-add]').onclick = function () {
      var n = 0;
      box.querySelectorAll('[data-f]').forEach(function (c) { if (c.checked) { stnMerge(fresh[parseInt(c.dataset.f, 10)]); n++; } });
      stnRender();
      box.style.display = 'none';
      document.getElementById('stnmsg').textContent = t('str_added_press_save_to_keep_them', {count: n});
    };
  };
  x.onerror = x.ontimeout = function () { box.textContent = t('str_the_search_did_not_answer'); };
  x.open('GET', 'scripts/stations.php?discover=1', true);
  x.send();
}
function stnImport(inp) {
  var f = inp.files && inp.files[0];
  if (!f) return;
  var r = new FileReader();
  r.onload = function () {
    var got = [];
    try { got = JSON.parse(r.result); } catch (e) {}
    if (!Array.isArray(got)) { document.getElementById('stnmsg').textContent = t('str_not_a_stations_list'); return; }
    stnRead();
    got.forEach(stnMerge);
    stnRender();
    document.getElementById('stnmsg').textContent = t('str_imported_press_save_to_keep_them', {count: got.length});
  };
  r.readAsText(f);
  inp.value = '';
}
stnRender();
// the fleet view: each registered station asked for its health (its stations.php?json=1&health=1, first address that
// answers, with the login this browser saved for it); a station that wants the login says so, with its address
function stnFleet() {
  var ft = document.getElementById('stnfleet');
  while (ft.rows.length > 1) ft.deleteRow(1);
  stnList.forEach(function (st) {
    var tr = ft.insertRow(-1), name = st.name || st.mac || (st.addresses || [])[0] || '';
    // a bad state in the shared red chip (style.css .badchip, release run 5: red text was 2.5:1 in Dark)
    var chip = function (c, txt) { c.textContent = ''; var s = document.createElement('span'); s.className = 'badchip'; s.textContent = txt; c.appendChild(s); };
    var cell = function (txt, bad) { var c = tr.insertCell(-1); if (bad) chip(c, txt); else c.textContent = txt; return c; };
    cell(name);
    var stc = cell(t('str_asking'));
    var addrs = stnIsHere(st) ? [location.host] : (st.addresses || []).slice();
    var next = function () {
      var a = addrs.shift();
      if (!a) { chip(stc, t('str_no_answer_lc')); return; }
      var x = new XMLHttpRequest();
      x.timeout = 4000;
      x.onload = function () {
        if (this.status === 401 || this.status === 403) { stc.innerHTML = ''; var l = document.createElement('a'); l.href = location.protocol + '//' + a + '/'; l.target = '_blank'; l.rel = 'noopener'; l.textContent = t('str_log_in_there'); stc.appendChild(l); return; }
        var j = null; try { j = JSON.parse(this.responseText); } catch (e) {}
        if (!j || !j.health) { next(); return; }
        var hh = j.health;
        stc.textContent = t('str_answered');
        cell(hh.version || '-'); cell(hh.recording ? t('str_yes') : t('str_no'), !hh.recording); cell(hh.last || '-');
        cell(hh.disk == null ? '-' : hh.disk + ' %', hh.disk >= 90); cell(hh.clock ? '\u2713' : t('str_not_confirmed'), !hh.clock); cell(hh.online ? t('str_yes') : t('str_no'));
      };
      x.onerror = x.ontimeout = next;
      x.open('GET', location.protocol + '//' + a + '/scripts/stations.php?json=1&health=1', true);
      x.withCredentials = true;
      x.send();
    };
    next();
  });
}
stnFleet();
stationMap(document.getElementById('stnmap'), document.getElementById('stnmapnote'));
// the MAC of the other stations, asked to them (their stations.php?json=1); Save keeps it
(function () {
  var rows = document.getElementById('stntable').rows;
  stnList.forEach(function (st, i) {
    if (st.mac || !(st.addresses || []).length) return;
    stationInfo(st.addresses[0], function (j) { if (j && j.mac) { st.mac = j.mac; stnIdCell(rows[i + 1].querySelector('td.mac'), j.mac); } });
  });
})();
</script>
