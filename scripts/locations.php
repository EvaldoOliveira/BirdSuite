<?php
/* Locations (US-58, owner 2026-10-20): the places where the station has recorded — name, coordinates, radius — with
 * the detections of each (every detection carries the id of the location it was recorded at, column Location).
 * The current location is marked; it changes only on the Latest page, when the microphone is reconnected or the boot
 * check finds the station moved. Here the names and radii are edited, and an unused location can be removed.
 * Opened as views.php?view=Locations; locations.php?json=1 answers the list (Location Data, the map pickers). */
require_once __DIR__ . '/common.php';

// detections per location, counted on the whole table (main.detections: the header filter does not apply here)
function locations_with_counts() {
  $db = new SQLite3(BS_DB, SQLITE3_OPEN_READONLY);
  $db->busyTimeout(5000);
  // and its removed detections (run 5 RV2, PUR-03: a location with none left but removed ones could be deleted, and
  // they were then shown nowhere): one pass over deleted_detections, a small table
  $removed = array();
  $res = $db->query('SELECT Location, COUNT(*) AS n FROM deleted_detections GROUP BY Location');
  while ($res && ($r = $res->fetchArray(SQLITE3_ASSOC))) $removed[intval($r['Location'])] = intval($r['n']);
  $out = array();
  foreach (locations_complete(locations_load()) as $l) {
    $l['n'] = intval($db->querySingle('SELECT COUNT(*) FROM main.detections WHERE ' . location_sql($l)));
    $l['removed'] = $removed[intval($l['id'])] ?? 0;
    $out[] = $l;
  }
  $db->close();
  return $out;
}

if (isset($_GET['json'])) {
  header('Content-Type: application/json');
  $cur = location_current();
  echo json_encode(array('current' => $cur ? intval($cur['id']) : 0, 'filter' => location_filter(),
    'locations' => array_map(function ($l) { return array('id' => intval($l['id']), 'name' => $l['name']); }, locations_load())));
  die();
}
// what is under the point of the map while it is fine-tuned, before Save (owner 2026-10-29): the biome, ecoregion and
// IBGE biome (offline) and the land cover and setting (ESA WorldCover, online), as location_biome_text shows them
if (isset($_GET['biome'])) {
  header('Content-Type: application/json');
  session_write_close();
  $lat = floatval($_GET['lat'] ?? 0); $lon = floatval($_GET['lon'] ?? 0);
  if (abs($lat) > 90 || abs($lon) > 180) { echo json_encode(array('html' => '')); die(); }
  $run = function ($script) use ($lat, $lon) {
    return json_decode((string)shell_exec(station_prefix() . 'python3 ' . escapeshellarg(__ROOT__ . '/scripts/' . $script) . ' ' . escapeshellarg((string)$lat) . ' ' . escapeshellarg((string)$lon) . ' 2>/dev/null'), true) ?: array();
  };
  $b = $run('locate_biome.py');
  $lc = $run('land_cover.py');
  if (isset($lc['error'])) $lc = array();
  $html = location_biome_text($b + $lc);
  echo json_encode(array('html' => $html !== '' ? $html : t('str_biome_none_sea_or_ice'), 'land' => !empty($lc)));
  die();
}
// a new location (name, coordinates, radius) — owner 2026-10-20: locations are also made here, not only on Now
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_GET['add'])) {
  ensure_authenticated(t('str_you_must_change_the_locations_help'));
  $p = json_decode((string)file_get_contents('php://input'), true) ?: array();
  $name = mb_substr(trim(preg_replace('/[\x00-\x1F\x7F<>"]/', '', (string)($p['name'] ?? ''))), 0, 60);
  $lat = floatval($p['lat'] ?? ''); $lon = floatval($p['lon'] ?? ''); $r = max(0.01, min(50, round(floatval($p['radius_km'] ?? LOCATION_RADIUS_KM), 2)));
  if ($name === '' || abs($lat) > 90 || abs($lon) > 180 || ($lat == 0 && $lon == 0)) { echo t('str_a_name_and_valid_coordinates_help'); die(); }
  $list = locations_load();
  // close to another location is fine — each detection belongs to the nearest centre (owner 2026-10-20); same name is not
  foreach ($list as $x) if (mb_strtolower($x['name']) === mb_strtolower($name)) { echo t('str_a_location_named_exists_already', array('name' => $x['name'])); die(); }
  // the same coordinates as another location are fine (owner 2026-10-21): the detections carry the location's id
  $tz = in_array($p['timezone'] ?? '', DateTimeZone::listIdentifiers(), true) ? $p['timezone'] : station_timezone();
  $sl = isset($p['species_list']) && species_list_valid((string)$p['species_list']) ? (string)$p['species_list'] : (string)(get_config()['SPECIES_LIST'] ?? '');
  $list[] = array('id' => max(array_map('intval', array_column($list, 'id'))) + 1, 'name' => $name, 'lat' => round($lat, 4), 'lon' => round($lon, 4), 'radius_km' => $r,
                  'timezone' => $tz, 'species_list' => $sl);
  echo locations_save($list) ? 'OK' : t('str_error_cannot_write', array('file' => basename(locations_file())));
  die();
}
// is the station recording? (owner 2026-10-20: stop the recording before changing the location)
function loc_recording() {
  return trim((string)shell_exec('systemctl is-active ' . BS_SVC_RECORDING . '.service 2>/dev/null')) === 'active';
}
// the timezone of coordinates picked on the map (filled in by default)
if (isset($_GET['tzfor'])) {
  header('Content-Type: application/json');
  $ll = explode(',', (string)$_GET['tzfor']);
  if (count($ll) !== 2 || !is_numeric($ll[0]) || !is_numeric($ll[1])) { echo json_encode(array('error' => 'coordinates')); die(); }
  list($tz, $how) = timezone_for($ll[0], $ll[1]);
  echo json_encode(array('timezone' => $tz, 'how' => $how));
  die();
}
if (isset($_GET['recording'])) { header('Content-Type: application/json'); echo json_encode(array('recording' => loc_recording())); die(); }
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_GET['stoprec'])) {
  ensure_authenticated(t('str_you_must_stop_the_recording_help'));
  shell_exec('sudo -n ' . BS_SVC_HELPER . ' stop ' . BS_SVC_RECORDING . '.service ' . BS_SVC_LIVESTREAM . '.service 2>&1');
  echo loc_recording() ? t('str_the_recording_did_not_stop') : 'OK';
  die();
}
// the recording again after the coordinates were saved (owner 2026-10-29: Save stops and resumes it)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_GET['startrec'])) {
  ensure_authenticated(t('str_you_must_stop_the_recording_help'));
  shell_exec('sudo -n ' . BS_SVC_HELPER . ' start ' . BS_SVC_RECORDING . '.service ' . BS_SVC_LIVESTREAM . '.service 2>&1');
  echo loc_recording() ? 'OK' : t('str_the_recording_did_not_start_help');
  die();
}
// moved from Basic Settings (owner 2026-10-20): station name and timezone (System › Stations), species list filter (here)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_GET['station'])) {
  ensure_authenticated(t('str_you_must_change_the_settings_help'));
  $p = json_decode((string)file_get_contents('php://input'), true) ?: array();
  $conf = get_config();
  // only the fields sent change (Settings › Stations sends name + timezone, Settings › Locations the species list)
  $name = array_key_exists('site_name', $p) ? trim(str_replace(array('"', "'", '\\', '$', '`'), '', preg_replace('/[\x00-\x1F\x7F]/', '', (string)$p['site_name']))) : ($conf['SITE_NAME'] ?? '');
  $list = (string)($p['species_list'] ?? ($conf['SPECIES_LIST'] ?? ''));
  $home = get_home();
  if (!species_list_valid($list)) { echo t('str_unknown_species_list'); die(); }
  if (!conf_set_many(array('SITE_NAME' => $name, 'SPECIES_LIST' => $list), $err)) { echo $err; die(); }
  if ($list !== ($conf['SPECIES_LIST'] ?? '')) shell_exec(station_prefix() . 'python3 ' . escapeshellarg(BS_SCRIPTS . "/select_species_list.py") . ' > /dev/null 2>&1');
  $tz = (string)($p['timezone'] ?? '');
  if ($tz !== '' && in_array($tz, DateTimeZone::listIdentifiers(), true) && $tz !== trim((string)shell_exec('timedatectl show --value --property=Timezone'))) {
    set_station_timezone($tz);
  }
  echo 'OK';
  die();
}
// the current location panel (owner 2026-10-20: the location settings of Basic Settings live here now): its name, the
// station's coordinates, the boot check and its move distance. New coordinates = a move: the recording must be stopped.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_GET['setcur'])) {
  ensure_authenticated(t('str_you_must_change_the_location_help'));
  $p = json_decode((string)file_get_contents('php://input'), true) ?: array();
  $lat = round(floatval($p['lat'] ?? ''), 4); $lon = round(floatval($p['lon'] ?? ''), 4);
  if (abs($lat) > 90 || abs($lon) > 180 || ($lat == 0 && $lon == 0)) { echo t('str_valid_coordinates_are_needed'); die(); }
  $conf = get_config();
  $moved = abs($lat - floatval($conf['LATITUDE'] ?? 0)) > 0.00005 || abs($lon - floatval($conf['LONGITUDE'] ?? 0)) > 0.00005;
  if ($moved && loc_recording()) { echo t('str_the_station_is_recording_stop_help'); die(); }
  // Adjust edits the current location itself (owner 2026-10-21: locations are told by their tag, so two of them may
  // share coordinates); without a current location the coordinates name or make one
  $here = location_current();
  if (!$here) { location_set_named($lat, $lon, (string)($p['name'] ?? '')); $here = location_at($lat, $lon); }
  $name = mb_substr(trim(preg_replace('/[\x00-\x1F\x7F<>"]/', '', (string)($p['name'] ?? ''))), 0, 60);
  // the location's own timezone and species list (owner 2026-10-20), stored with it, then applied with the coordinates
  $list = locations_complete(locations_load());
  foreach ($list as &$x) if ($here && intval($x['id']) === intval($here['id'])) {
    $x['lat'] = $lat; $x['lon'] = $lon;
    if ($name !== '') $x['name'] = $name;
    if (in_array($p['timezone'] ?? '', DateTimeZone::listIdentifiers(), true)) $x['timezone'] = $p['timezone'];
    if (isset($p['species_list']) && species_list_valid((string)$p['species_list'])) $x['species_list'] = (string)$p['species_list'];
    $here = $x;
  }
  unset($x);
  locations_save($list);
  $err = $here ? location_activate(array_merge($here, array('lat' => $lat, 'lon' => $lon))) : apply_station_coordinates($lat, $lon);
  if ($err !== '') { echo $err; die(); }
  if ($moved) @unlink(mic_reconnected_file());
  $set = array('LOCATION_CHECK' => !empty($p['check']) ? '1' : '0');
  // the error of the network position (owner 2026-10-23: editable here, 1 to 500 km)
  if (isset($p['ipkm']) && is_numeric($p['ipkm'])) $set['LOCATION_IP_ERROR_KM'] = max(1, min(500, intval($p['ipkm'])));
  echo conf_set_many($set, $err) ? 'OK' : $err;
  die();
}
// the station is now at this location: its coordinates become the station's (and the state species list, as on Now)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_GET['current'])) {
  ensure_authenticated(t('str_you_must_change_the_current_help'));
  $l = location_by_id($_GET['current']);
  if (!$l) { echo t('str_unknown_location'); die(); }
  if (loc_recording()) { echo t('str_the_station_is_recording_stop_help'); die(); }
  $err = location_activate(locations_complete(array($l))[0]);
  if ($err === '') @unlink(mic_reconnected_file()); // the question of the Latest page is answered too
  echo $err === '' ? 'OK' : $err;
  die();
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_GET['save'])) {
  ensure_authenticated(t('str_you_must_change_the_locations_help'));
  $in = json_decode((string)file_get_contents('php://input'), true);
  $cur = location_current();
  $old = array();
  foreach (locations_load() as $l) $old[intval($l['id'])] = $l;
  $counts = array();
  foreach (locations_with_counts() as $l) $counts[intval($l['id'])] = $l['n'] + $l['removed'];
  $new = array();
  foreach (is_array($in) ? $in : array() as $l) {
    $id = intval($l['id'] ?? 0);
    if (!isset($old[$id])) continue; // locations are created on the Latest page (or by an import), not here
    $name = trim(preg_replace('/[\x00-\x1F\x7F<>"]/', '', (string)($l['name'] ?? '')));
    $r = floatval($l['radius_km'] ?? LOCATION_RADIUS_KM);
    $o = locations_complete(array($old[$id]))[0];
    $new[$id] = array('id' => $id, 'name' => mb_substr($name !== '' ? $name : $old[$id]['name'], 0, 60),
      'lat' => $old[$id]['lat'], 'lon' => $old[$id]['lon'], 'radius_km' => max(0.01, min(50, round($r, 2))),  // 0.01: the factory Test / Playback is 0.05 (2026-10-23 run 3)
      'timezone' => in_array($l['timezone'] ?? '', DateTimeZone::listIdentifiers(), true) ? $l['timezone'] : $o['timezone'],
      'species_list' => isset($l['species_list']) && species_list_valid((string)$l['species_list']) ? (string)$l['species_list'] : $o['species_list']);
    if (isset($old[$id]['builtin'])) $new[$id]['builtin'] = $old[$id]['builtin']; // Home / Test / Playback stay built-in
    // its theme (owner 2026-10-21); '' = the station's theme, kept as '' so the test location does not turn red again
    $th = strtolower(trim((string)($l['theme'] ?? location_theme($old[$id]))));
    $new[$id]['theme'] = $th === '' || theme_valid($th) ? $th : location_theme($old[$id]);
  }
  // the test location is built in: it is kept even when the list sent leaves it out
  foreach ($old as $id => $l) if (!isset($new[$id]) && ($l['builtin'] ?? '') === 'test') $new[$id] = $l;
  // a location with detections (removed ones too: they keep its id until restored or purged), or the current one,
  // cannot be removed
  foreach ($old as $id => $l) {
    if (!isset($new[$id]) && (($counts[$id] ?? 0) > 0 || ($cur && intval($cur['id']) === $id))) $new[$id] = $l;
  }
  // the order of the table is the order of the list (owner 2026-10-21: Order column as in Stations); the rows kept
  // above (test, with detections, current) go last when the list left them out
  $ok = locations_save(array_values($new));
  if ($ok && $cur && isset($new[intval($cur['id'])])) {
    set_station_timezone($new[intval($cur['id'])]['timezone']);
    set_species_list((string)$new[intval($cur['id'])]['species_list']);
  }
  echo $ok ? 'OK' : t('str_error_cannot_write', array('file' => basename(locations_file())));
  die();
}
$h = function ($s) { return htmlspecialchars((string)$s, ENT_QUOTES); };
$cur = location_current();
$rows = locations_with_counts();
?>
<style>
.locs { max-width: 900px; margin: 0; text-align: left; padding: 0 12px; }
.locs table.stdtable input[type=text], .locs table.stdtable input[type=number] { width: 100%; box-sizing: border-box; padding: 4px 6px; }
.locs table.stdtable td.num { text-align: right; white-space: nowrap; }
.locs #loctable input[data-k=name], .locs #loctable select { width: auto; } /* as wide as their text (owner 2026-10-21) */
.locs .locbtn { width: auto; padding: 4px 12px; border-radius: 12px; border: 1px solid var(--accent,#2b5e22); background: #fff; color: var(--accent,#2b5e22); font-weight: 600; cursor: pointer; }
.locs .locbtn:hover { background: var(--accent,#2b5e22); color: #fff; }
.locs .locbtn.del { border-color: #c62828; color: #c62828; }
.locs .locbtn.del:hover { background: #c62828; color: #fff; }
/* current: the app's standard button size, filled (owner 2026-10-21: it was smaller than the buttons) */
.locs .curbadge { display: inline-block; padding: 4px 12px; border-radius: 12px; border: 1px solid var(--accent,#2b5e22); background: var(--accent,#2b5e22); color: #fff; font-size: 13px; font-weight: 600; line-height: 1.3; }
@media screen and (max-width: 800px) { .locs .curbadge { font-size: 12px; padding: 3px 9px; } }
.locs #locmsg { font-weight: 600; margin-left: 8px; }
.locs #loctable td.ord { white-space: normal; width: 1%; min-width: 9em; }
.locs #loctable { width: 100%; }
.locs #loctable select { max-width: 100%; margin-top: 4px; }
.locs #loctable td .small { font-size: 12px; opacity: .8; margin-top: 3px; }
.locs #loctable td.ord .ordrow { display: flex; gap: 6px; margin: 3px 0; }
.locs #loctable td.ord .ordrow > * { flex: 1 1 0; text-align: center; box-sizing: border-box; }
/* a phone (owner 2026-10-25: the table did not fit): one card per location, each field under its title */
@media (max-width: 700px) {
  .locs #loctable, .locs #loctable tbody, .locs #loctable tr, .locs #loctable td { display: block; width: 100%; box-sizing: border-box; }
  .locs #loctable tr:first-child { display: none; }
  .locs #loctable tr { margin: 10px 0; padding: 6px 10px; border: 1px solid rgba(0,0,0,.12); border-radius: 10px; }
  .locs #loctable td { border: 0; padding: 4px 0; text-align: left !important; }
  .locs #loctable td::before { content: attr(data-label); display: block; font-size: 12px; font-weight: 600; opacity: .7; margin-bottom: 2px; }
  .locs #loctable td.ord { width: 100%; min-width: 0; }
  .locs #loctable td.ord .ordrow { max-width: 22em; }
  .locs #loctable select, .locs #loctable input[data-k=name] { max-width: 100%; }
}
</style>
<div class="locs">
  <?php // the table first, the current location under it (owner 2026-10-26); each Save inside its own section ?>
  <?php // one location only (simple mode, US-125): the list of locations and New location stay hidden, the table is still
  // saved with the current location ?>
  <div class="box" style="padding:10px 14px;margin:0 0 14px<?php echo multi_location() ? '' : ';display:none'; ?>">
<?php
// the Now page background of a location (US-22): a small whole picture, whose it is (its own, the station default or none)
// and its Prominence
function loc_bg_cell($l) {
  $own = array_key_exists('now_background', $l);
  $p = $own ? (string)$l['now_background'] : trim((string)(get_config()[($l['builtin'] ?? '') === 'test' ? 'NOW_BACKGROUND_TEST' : 'NOW_BACKGROUND'] ?? ''), '" ');
  if ($p !== '' && strpos($p, '/') === false) $p = BS_LOCATIONS . '/' . $p;
  $h = function ($x) { return htmlspecialchars((string)$x, ENT_QUOTES); };
  if ($p === '' || !custom_image_ok($p)) return '<span class="small">' . t('str_no_background') . '</span>';
  $src = 'scripts/see.php?bg=img&loc=' . intval($l['id']) . '&v=' . filemtime($p);
  $fade = max(0, min(90, intval($l['now_background_fade'] ?? 0)));
  return '<img class="locbg" src="' . $h($src) . '" alt="" title="' . $h(basename($p)) . '">'
    . '<div class="small">' . ($own ? t('str_own_picture') : t('str_station_default_background')) . '<br>' . t('str_prominence') . ' ' . (100 - $fade) . '%</div>';
}
?>
<style>#loctable img.locbg { width: 96px; height: 64px; object-fit: contain; background: rgba(0,0,0,.08); border-radius: 6px; display: block; }</style>
    <b><?php echo t('str_locations'); ?></b>
  <table class="stdtable" id="loctable">
    <?php // two fields per cell, one above the other, so the table fits the page (owner 2026-10-24: no horizontal scroll) ?>
    <tr><th><?php echo t('str_name'); ?> / <?php echo t('str_coordinates'); ?></th><th title="<?php echo $h(t('str_a_point_within_this_distance_of_help', array('ip' => location_ip_error_km()))); ?>"><?php echo t('str_radius_km'); ?> / <?php echo t('str_timezone'); ?></th><th><?php echo t('str_species_list'); ?> / <?php echo t('str_theme'); ?></th><th><?php echo t('str_now_background'); ?></th><th class="num"><?php echo t('str_detections'); ?></th><th><?php echo t('str_order'); ?></th></tr>
    <?php // the column titles again on each cell, shown when the table becomes cards on a phone (owner 2026-10-25)
    $lab = array(t('str_name') . ' / ' . t('str_coordinates'), t('str_radius_km') . ' / ' . t('str_timezone'), t('str_species_list') . ' / ' . t('str_theme'), t('str_now_background'), t('str_detections'), t('str_order'));
    // the order buttons only when there is something to order: two locations besides Test / Playback (owner 2026-10-26)
    $orderable = count(array_filter($rows, function ($l) { return ($l['builtin'] ?? '') !== 'test'; })) > 1;
    foreach ($rows as $l) {
      $is_cur = $cur && intval($cur['id']) === intval($l['id']);
      echo '<tr data-id="' . intval($l['id']) . '"' . (($l['builtin'] ?? '') === 'test' ? ' data-test="1"' : '') . ' data-n="' . intval($l['n']) . '" data-removed="' . intval($l['removed']) . '" data-cur="' . ($is_cur ? 1 : 0) . '" data-theme="' . $h(location_theme($l)) . '">'
        . '<td data-label="' . $h($lab[0]) . '"><input type="text" data-k="name" value="' . $h($l['name']) . '"><div class="small">' . $h(number_format($l['lat'], 4)) . ', ' . $h(number_format($l['lon'], 4)) . (($bt = location_biome_text($l)) !== '' ? '<br>' . $bt : '') . '</div></td>'
        . '<td data-label="' . $h($lab[1]) . '"><input type="number" data-k="radius_km" min="0.01" max="50" step="0.01" value="' . $h($l['radius_km'] ?? LOCATION_RADIUS_KM) . '" style="width:6em">'
          . '<br><select data-k="timezone">' . implode('', array_map(function ($tz) use ($l, $h) { return '<option' . ($tz === $l['timezone'] ? ' selected' : '') . '>' . $h($tz) . '</option>'; }, DateTimeZone::listIdentifiers(DateTimeZone::ALL))) . '</select></td>'
        . '<td data-label="' . $h($lab[2]) . '"><select data-k="species_list">' . species_list_options((string)$l['species_list']) . '</select>'
          . '<br><select data-k="theme">' . location_theme_options(location_theme($l)) . '</select></td>'
        . '<td data-label="' . $h($lab[3]) . '">' . loc_bg_cell($l) . '</td>'
        . '<td class="num" data-label="' . $h($lab[4]) . '">' . fmt_num($l['n']) . '</td>'
        // Test / Playback stays last (owner 2026-10-22): no order buttons on its row
        // one line each, the same width (owner 2026-10-25: ▲ was as wide as Make current): ▲ ▼, then current or Make
        // current, then Remove
        . '<td class="ord" data-label="' . $h($lab[5]) . '">' . (($l['builtin'] ?? '') === 'test' || !$orderable ? '' : '<div class="ordrow"><button type="button" class="locbtn" title="' . $h(t('str_up')) . '" onclick="locMove(this, -1)">&#9650;</button><button type="button" class="locbtn" title="' . $h(t('str_down')) . '" onclick="locMove(this, 1)">&#9660;</button></div>')
        . '<div class="ordrow">' . ($is_cur ? '<span class="curbadge">' . t('str_current') . '</span>'
          : '<button type="button" class="locbtn" onclick="locCurrent(' . intval($l['id']) . ', this)">' . t('str_make_current') . '</button>') . '</div>'
        // Remove on every location but Test / Playback (owner 2026-10-28); one with detections or the current one only says
        // what to do first (locRemove), the server keeps them anyway
        . (($l['builtin'] ?? '') === 'test' ? '' : '<div class="ordrow"><button type="button" class="locbtn del" onclick="locRemove(this)">' . t('str_remove') . '</button></div>') . '</td></tr>';
    } ?>
  </table>
  <?php // the test location note under the table, not in its name cell (owner 2026-10-21) ?>
  <p style="font-size:.85em;margin:6px 0 0"><?php echo t('str_the_test_playback_location_help'); ?></p>
  <p style="margin:8px 0 0"><button type="button" class="locbtn" onclick="locSave()"><?php echo t('str_save'); ?></button><span id="locmsg"></span></p>
  </div>
<?php $cfg = get_config(); ?>
  <?php // the station's name: here with one station, in System › Stations with several (US-54, owner 2026-11-04) ?>
  <?php if (!multi_station()) { ?>
  <div class="box" style="padding:10px 14px;margin:0 0 14px">
    <label><b><?php echo t('str_station_name'); ?></b> <input type="text" id="stname" maxlength="60" style="width:16em" value="<?php echo $h((string)($cfg['SITE_NAME'] ?? '')); ?>" placeholder="<?php echo $h(default_sitename()); ?>"></label>
    <button type="button" class="locbtn" onclick="locStationName(this)"><?php echo t('str_save'); ?></button> <span id="stmsg" style="font-weight:600"></span>
  </div>
  <script>
  function locStationName(b) {
    var x = new XMLHttpRequest(), m = document.getElementById('stmsg');
    b.disabled = true;
    x.onload = function () { b.disabled = false; var ok = this.responseText.trim() === 'OK'; m.textContent = ok ? t('str_saved') : (this.status === 401 ? t('str_log_in_first') : this.responseText.trim()); if (ok) setTimeout(function () { (window.top || window).location.reload(); }, 600); };
    x.open('POST', 'scripts/locations.php?station=1', true);
    x.setRequestHeader('Content-Type', 'application/json');
    x.send(JSON.stringify({site_name: document.getElementById('stname').value}));
  }
  </script>
  <?php } ?>
  <div class="box" style="padding:10px 14px;margin:0 0 14px">
    <b><?php echo t('str_current_location'); ?></b> <small><?php echo t('str_where_new_detections_are_filed_help'); ?></small>
    <div style="display:flex;flex-wrap:wrap;gap:8px;align-items:center;margin-top:8px">
      <label><?php echo t('str_name'); ?> <input type="text" id="curname" class="curf" disabled maxlength="60" value="<?php echo $h($cur ? $cur['name'] : ''); ?>" style="width:14em"></label>
      <label><?php echo t('str_latitude'); ?> <input type="text" id="curlat" class="curf" disabled value="<?php echo $h($cfg['LATITUDE'] ?? ''); ?>" style="width:8em"></label>
      <label><?php echo t('str_longitude'); ?> <input type="text" id="curlon" class="curf" disabled value="<?php echo $h($cfg['LONGITUDE'] ?? ''); ?>" style="width:8em"></label>
<?php $curc = $cur ? locations_complete(array($cur))[0] : array('timezone' => station_timezone(), 'species_list' => (string)($cfg['SPECIES_LIST'] ?? '')); ?>
      <label><?php echo t('str_timezone'); ?> <select id="curtz" class="curf" disabled><?php foreach (DateTimeZone::listIdentifiers(DateTimeZone::ALL) as $tzid) echo '<option' . ($tzid === $curc['timezone'] ? ' selected' : '') . '>' . $h($tzid) . '</option>'; ?></select></label>
      <label><?php echo t('str_species_list'); ?> <select id="curlist" class="curf" disabled><?php echo species_list_options($curc['species_list']); ?></select></label>
      <button type="button" class="locbtn" id="curadjust" onclick="locAdjust(true)"><?php echo t('str_adjust'); ?></button>
      <button type="button" class="locbtn curedit" style="display:none" onclick="locAutoLocate(this)" title="<?php echo $h(t('str_the_devices_gps_position_help')); ?>"><?php echo t('str_auto_locate_now'); ?></button>
      <button type="button" id="curmapbtn" style="display:none"></button>
    </div>
    <?php // the map's room kept from the first paint (the map is 320 px, opened just after load; 2026-10-23 test run: the
    // table under it moved, layout shift 0.17) ?>
    <div id="curmapbox" style="min-height:350px"></div>
    <?php // the boot check and its distance are expert settings (US-52): folded under More options below Expert ?>
    <?php echo more_options('expert'); ?>
    <div style="display:flex;flex-wrap:wrap;gap:12px;align-items:center;margin-top:8px">
      <label><input type="checkbox" id="curcheck" class="curf" disabled <?php echo ($cfg['LOCATION_CHECK'] ?? '1') !== '0' ? 'checked' : ''; ?>> <?php echo t('str_detect_at_boot_whether_the_help'); ?></label>
      <label title="<?php echo $h(t('str_the_network_position_is_about_help')); ?>"><?php echo t('str_minimum_distance_km'); ?> <input type="number" id="curipkm" class="curf" disabled min="1" max="500" step="1" style="width:5em" value="<?php echo intval(location_ip_error_km()); ?>"></label>
    </div>
    <?php // owner's layout (2026-10-20): the boot check under the map, then the table ?>
    <?php // shorter, and what the GPS needs (owner 2026-10-24) ?>
    <?php // the owner's wording (2026-10-26) ?>
    <p style="margin:10px 0 0"><?php echo t('str_at_every_boot_the_station_asks_help', array('km' => $cur ? location_boot_km($cur) : location_ip_error_km())); ?><br>
      <?php echo t('str_for_the_exact_position_use_the_help'); ?><br>
      <span style="display:inline-block;margin-left:1.5em"><?php echo t('str_the_station_moment_and_help'); ?></span></p>
    <?php echo more_options_end(); ?>
    <div style="display:flex;flex-wrap:wrap;gap:12px;align-items:center;margin-top:8px">
      <button type="button" class="locbtn curedit" style="display:none;background:var(--accent,#2b5e22);color:#fff" onclick="locSetCur()"><?php echo t('str_save'); ?></button>
      <button type="button" class="locbtn curedit" style="display:none" onclick="location.reload()"><?php echo t('str_cancel'); ?></button> <span id="curmsg" style="font-weight:600"></span>
    </div>
  </div>
  <div class="box" style="padding:10px 14px;margin:12px 0<?php echo multi_location() ? '' : ';display:none'; ?>">
    <b><?php echo t('str_new_location'); ?></b>
    <div style="display:flex;flex-wrap:wrap;gap:8px;align-items:center;margin-top:6px">
      <?php // each field with its title, as in Current location (owner 2026-10-26) ?>
      <label><?php echo t('str_name'); ?> <input type="text" id="newname" placeholder="<?php echo $h(t('str_name_e_g_farm')); ?>" maxlength="60" style="width:14em"></label>
      <label><?php echo t('str_latitude'); ?> <input type="text" id="newlat" style="width:8em"></label> <label><?php echo t('str_longitude'); ?> <input type="text" id="newlon" style="width:8em"></label>
      <label><?php echo t('str_radius_km'); ?> <input type="number" id="newr" value="<?php echo LOCATION_RADIUS_KM; ?>" min="0.01" max="50" step="0.01" style="width:5em"></label>
      <label title="<?php echo $h(t('str_timezone_follows_the_coordinates')); ?>"><?php echo t('str_timezone'); ?> <select id="newtz" style="max-width:12em"><?php $tzn = station_timezone(); foreach (DateTimeZone::listIdentifiers(DateTimeZone::ALL) as $tzid) echo '<option' . ($tzid === $tzn ? ' selected' : '') . '>' . $h($tzid) . '</option>'; ?></select></label>
      <button type="button" class="locbtn" id="newmapbtn" title="<?php echo $h(t('str_pick_the_place_on_a_map')); ?>">&#128506; <?php echo t('str_map'); ?></button>
      <button type="button" class="locbtn" onclick="locAdd()"><?php echo t('str_add'); ?></button> <span id="newmsg" style="font-weight:600"></span></div>
    <div id="newmapbox"></div>
  </div>
  <?php // the sources of the biome, ecoregion and land cover (owner 2026-10-29), folded as the species page's references ?>
  <details class="small" style="margin:10px 0 0"><summary><?php echo t('str_references_biome_ecoregion_and_help'); ?></summary>
    <p><?php echo t('str_the_biome_and_ecoregion_come_help'); ?><br>
      <?php echo t('str_every_detection_keeps_the_help'); ?><br>
      <?php echo t('str_setting_urban_from_50_pct_built_help'); ?></p>
    <p><b><?php echo t('str_references'); ?></b><br>
      Dinerstein, E. et al. (2017). <a href="https://doi.org/10.1093/biosci/bix014" target="_blank" rel="noopener">An ecoregion-based approach to protecting half the terrestrial realm</a>. BioScience 67: 534–545. <?php echo t('str_data_colon'); ?> <a href="https://ecoregions.appspot.com/" target="_blank" rel="noopener">RESOLVE Ecoregions 2017</a> (CC BY 4.0).<br>
      Olson, D.M. et al. (2001). <a href="https://doi.org/10.1641/0006-3568(2001)051[0933:TEOTWA]2.0.CO;2" target="_blank" rel="noopener">Terrestrial ecoregions of the world: a new map of life on Earth</a>. BioScience 51: 933–938.<br>
      IBGE (2019). <a href="https://www.ibge.gov.br/geociencias/informacoes-ambientais/estudos-ambientais/15842-biomas.html" target="_blank" rel="noopener">Biomas e sistema costeiro-marinho do Brasil: compatível com a escala 1:250 000</a>. Relatórios Metodológicos 45. Rio de Janeiro: IBGE.<br>
      Zanaga, D. et al. (2022). <a href="https://doi.org/10.5281/zenodo.7254221" target="_blank" rel="noopener">ESA WorldCover 10 m 2021 v200</a>. Zenodo. <?php echo t('str_data_colon'); ?> <a href="https://esa-worldcover.org/" target="_blank" rel="noopener">esa-worldcover.org</a> (CC BY 4.0).</p>
  </details>
<script src="static/species-modal.js?v=<?php echo @filemtime(__DIR__ . '/../homepage/static/species-modal.js'); ?>"></script>
<script src="static/location-picker.js?v=<?php echo @filemtime(__DIR__ . '/../homepage/static/location-picker.js'); ?>"></script>
</div>
<script>
// Remove (owner 2026-10-28): only a location without detections that is not the current one goes, saved at once after
// a confirmation; otherwise the window says what to do first. Removed detections hold it too, until restored or purged
// in Data (run 5 RV2: they were left outside every location)
function locRemove(b) {
  var tr = b.closest('tr'), n = parseInt(tr.dataset.n, 10) || 0, rm = parseInt(tr.dataset.removed, 10) || 0;
  if (n > 0) {
    askModal(t('str_remove'), t('str_this_location_has_detections', {n: fmtNum(n, 0)}) + '\n' + t('str_delete_them_or_move_them_to_help'),
      t('str_open_tools_data'), false, t('str_close')).then(function (ok) { if (ok) location.href = 'views.php?view=Data'; });
  } else if (rm > 0) {
    askModal(t('str_remove'), t('str_this_location_has_removed_detections', {n: fmtNum(rm, 0)}) + '\n' + t('str_restore_them_or_purge_them_in_help'),
      t('str_open_tools_data'), false, t('str_close')).then(function (ok) { if (ok) location.href = 'views.php?view=Data'; });
  } else if (tr.dataset.cur === '1') {
    askModal(t('str_remove'), t('str_this_is_the_current_location') + '\n' + t('str_make_another_location_current_help'), t('str_close'), false, '');
  } else {
    // removed and saved at once (owner 2026-10-28: the row went, a reload brought it back until Save was pressed)
    var name = tr.querySelector('[data-k=name]').value;
    askModal(t('str_remove'), t('str_remove_the_location', {name: name}), t('str_remove'), true)
      .then(function (ok) { if (ok) { tr.remove(); locSave(); } });
  }
}
function locPost(url, body, done) {
  var x = new XMLHttpRequest();
  x.onload = function () { done(this.responseText.trim() === 'OK' ? '' : (this.status === 401 ? t('str_log_in_first') : this.responseText)); };
  x.open('POST', url, true); x.setRequestHeader('Content-Type', 'application/json'); x.send(JSON.stringify(body || {}));
}
function locSetCur() {
  var b = {name: document.getElementById('curname').value, lat: document.getElementById('curlat').value.replace(',', '.'), lon: document.getElementById('curlon').value.replace(',', '.'),
    check: document.getElementById('curcheck').checked,
    ipkm: document.getElementById('curipkm').value,
    timezone: document.getElementById('curtz').value, species_list: document.getElementById('curlist').value};
  var reload = function () { if (window.top) window.top.location.reload(); else location.reload(); };
  // resume: the recording stopped for the save starts again at once (owner 2026-10-29: Save stops and resumes it)
  var go = function (resume) {
    locPost('scripts/locations.php?setcur=1', b, function (err) {
      if (err) {
        document.getElementById('curmsg').innerHTML = '<span style="color:#c62828">' + err.replace(/</g, '&lt;') + '</span>';
        if (resume) locPost('scripts/locations.php?startrec=1', {}, function () {});
        return;
      }
      if (!resume) { reload(); return; }
      locPost('scripts/locations.php?startrec=1', {}, function (err2) { if (err2) bsNotice(err2); reload(); });
    });
  };
  // the coordinates are checked before anything is asked (2026-10-23 test run: 0,0 asked to stop the recording, then the
  // server refused them); the server checks them again
  var la = parseFloat(b.lat), lo = parseFloat(b.lon);
  if (isNaN(la) || isNaN(lo) || Math.abs(la) > 90 || Math.abs(lo) > 180 || (la === 0 && lo === 0)) {
    document.getElementById('curmsg').innerHTML = '<span style="color:#c62828">' + t('str_valid_coordinates_are_needed') + '</span>';
    return;
  }
  var moved = Math.abs(parseFloat(b.lat) - <?php echo floatval($cfg['LATITUDE'] ?? 0); ?>) > 0.00005 || Math.abs(parseFloat(b.lon) - <?php echo floatval($cfg['LONGITUDE'] ?? 0); ?>) > 0.00005;
  if (!moved) { go(); return; }
  var x = new XMLHttpRequest();
  x.onload = function () {
    var rec = false; try { rec = JSON.parse(this.responseText).recording; } catch (e) {}
    if (rec) {
      askModal(t('str_save_the_coordinates'), t('str_the_recording_stops_for_a_help') + '\n' + t('str_new_detections_are_filed_there'), t('str_save'), false, t('str_cancel'))
        .then(function (ok) { if (ok) locPost('scripts/locations.php?stoprec=1', {}, function (err) { if (err) { bsNotice(err); return; } go(true); }); });
    } else bsAsk(t('str_move_the_station_to_these_help')).then(function (ok) { if (ok) go(); });
  };
  x.open('GET', 'scripts/locations.php?recording=1', true); x.send();
}
// Auto Locate Now (owner 2026-10-29): the device's GPS first, as the map's GPS icon (on http with https for a moment);
// the network position (city level) when no GPS position came
function locAutoLocate(btn) {
  if (curPicker.gps) { curPicker.gps(function () { locNetLocate(btn); }); return; }
  locNetLocate(btn);
}
function locNetLocate(btn) {
  var msg = document.getElementById('curmsg');
  msg.textContent = t('str_locating'); btn.disabled = true;
  var x = new XMLHttpRequest();
  x.onload = function () {
    btn.disabled = false;
    var r; try { r = JSON.parse(this.responseText); } catch (e) { r = {error: this.status === 401 ? t('str_log_in_first_lc') : t('str_no_answer_lc')}; }
    if (r.error) { msg.textContent = t('str_not_located', {error: r.error}); return; }
    document.getElementById('curlat').value = Number(r.lat).toFixed(4); document.getElementById('curlon').value = Number(r.lon).toFixed(4);
    if (curPicker.refresh) curPicker.refresh();
    msg.textContent = t('str_km_from_network_position_help', {where: r.where, km: r.distance_km});
  };
  x.open('GET', 'scripts/locate_now.php', true); x.send();
}
// the current location: map always open, fields locked until Adjust (owner 2026-10-20)
var curEditing = false;
var curPicker = {lat: document.getElementById('curlat'), lon: document.getElementById('curlon'), button: document.getElementById('curmapbtn'),
  box: document.getElementById('curmapbox'), home: [<?php echo floatval($cfg['LATITUDE'] ?? 0) . ', ' . floatval($cfg['LONGITUDE'] ?? 0); ?>],
  open: true, preview: true, locked: function () { return !curEditing; }, unlock: function () { locAdjust(true); },
  // the GPS position arrived (owner 2026-11-03): update this location now? Yes saves (after the timezone has followed
  // the coordinates), No leaves the fields filled in to check and save
  onGps: function (lat, lon, acc) {
    var name = document.getElementById('curname').value;
    askModal(t('str_update_the_location_with_this_gps_position', {name: name, m: Math.round(acc)}), t('str_no_leaves_the_coordinates_filled_in_help'), t('str_update')).then(function (ok) {
      if (ok) setTimeout(locSetCur, 1500);
    });
  },
  // one biome line only, under the map (owner 2026-10-29): the saved point's at once, the point being adjusted after
  previewSaved: <?php echo json_encode($cur ? location_biome_text($cur) : ''); ?>};
locationPicker(curPicker);
// the timezone follows the coordinates by default (owner 2026-10-20); the picklist can still be changed afterwards
var tzTimer = null;
function locTzFollow(latEl, lonEl, selEl, msgEl) {
  clearTimeout(tzTimer);
  tzTimer = setTimeout(function () {
    var lat = parseFloat(latEl.value.replace(',', '.')), lon = parseFloat(lonEl.value.replace(',', '.'));
    if (!isFinite(lat) || !isFinite(lon)) return;
    var x = new XMLHttpRequest();
    x.onload = function () {
      var r = {}; try { r = JSON.parse(this.responseText); } catch (e) {}
      if (!r.timezone) return;
      if (!Array.prototype.some.call(selEl.options, function (o) { return o.value === r.timezone || o.text === r.timezone; })) selEl.add(new Option(r.timezone, r.timezone));
      selEl.value = r.timezone;
      if (msgEl) msgEl.textContent = t('str_timezone_timezone_how', {timezone: r.timezone, how: r.how});
    };
    x.open('GET', 'scripts/locations.php?tzfor=' + lat + ',' + lon, true); x.send();
  }, 600);
}
['curlat', 'curlon'].forEach(function (id) {
  ['input', 'change'].forEach(function (ev) { document.getElementById(id).addEventListener(ev, function () {
    if (curEditing) locTzFollow(document.getElementById('curlat'), document.getElementById('curlon'), document.getElementById('curtz'), document.getElementById('curmsg'));
  }); });
});
function locAdjust(on) {
  curEditing = on;
  document.querySelectorAll('.curf').forEach(function (i) { i.disabled = !on; });
  document.querySelectorAll('.curedit').forEach(function (b) { b.style.display = on ? '' : 'none'; });
  document.getElementById('curadjust').style.display = on ? 'none' : '';
  document.getElementById('curmsg').textContent = on ? t('str_click_the_map_drag_the_pin_use_help') : '';
  if (curPicker.refresh) curPicker.refresh();
}
function locAdd() {
  var b = {name: document.getElementById('newname').value, lat: document.getElementById('newlat').value.replace(',', '.'),
    lon: document.getElementById('newlon').value.replace(',', '.'), radius_km: document.getElementById('newr').value, timezone: document.getElementById('newtz').value};
  locPost('scripts/locations.php?add=1', b, function (err) {
    if (err) { document.getElementById('newmsg').innerHTML = '<span style="color:#c62828">' + err.replace(/</g, '&lt;') + '</span>'; return; }
    location.reload();
  });
}
function locCurrent(id, btn) {
  // the change runs on the station with its steps in a window (owner 2026-10-21, static/location-switch.js)
  (window.locationSwitch || window.top.locationSwitch)(id, btn.closest('tr').querySelector('[data-k=name]').value);
}
['newlat', 'newlon'].forEach(function (id) {
  ['input', 'change'].forEach(function (ev) { document.getElementById(id).addEventListener(ev, function () {
    locTzFollow(document.getElementById('newlat'), document.getElementById('newlon'), document.getElementById('newtz'), document.getElementById('newmsg'));
  }); });
});
locationPicker({preview: true, lat: document.getElementById('newlat'), lon: document.getElementById('newlon'), button: document.getElementById('newmapbtn'),
  box: document.getElementById('newmapbox'), home: [<?php $c = get_config(); echo floatval($c['LATITUDE'] ?? 0) . ', ' . floatval($c['LONGITUDE'] ?? 0); ?>]});
// every field of the table as wide as its text (owner 2026-10-21): names, timezone, species list, theme
function locFit(el) {
  var c = locFit.c || (locFit.c = document.createElement('canvas').getContext('2d'));
  var cs = getComputedStyle(el);
  c.font = cs.fontWeight + ' ' + cs.fontSize + ' ' + cs.fontFamily;
  var text = el.tagName === 'SELECT' ? (el.options[el.selectedIndex] || {text: ''}).text : (el.value || el.placeholder || '');
  el.style.width = Math.ceil(c.measureText(text).width + (el.tagName === 'SELECT' ? 34 : 18)) + 'px';
}
document.addEventListener('DOMContentLoaded', function () {
  document.querySelectorAll('#loctable input[data-k=name], #loctable select').forEach(function (el) {
    locFit(el);
    el.addEventListener(el.tagName === 'SELECT' ? 'change' : 'input', function () { locFit(el); });
  });
});
// Order (owner 2026-10-21, as in Stations): moves the row; Save keeps the order
function locMove(b, d) {
  var tr = b.closest('tr'), o = d < 0 ? tr.previousElementSibling : tr.nextElementSibling;
  if (!o || !o.dataset.id || o.dataset.test) return;  // nothing goes below Test / Playback
  if (d < 0) o.before(tr); else o.after(tr);
}
function locSave() {
  var list = Array.prototype.slice.call(document.querySelectorAll('#loctable tr[data-id]')).map(function (tr) {
    return {id: parseInt(tr.dataset.id, 10), name: tr.querySelector('[data-k=name]').value, radius_km: parseFloat(tr.querySelector('[data-k=radius_km]').value),
      timezone: tr.querySelector('[data-k=timezone]').value, species_list: tr.querySelector('[data-k=species_list]').value,
      theme: tr.querySelector('[data-k=theme]').value};
  });
  var x = new XMLHttpRequest();
  x.onload = function () {
    // a new theme of the current location shows after a reload
    if (this.responseText.trim() === 'OK' && list.some(function (l) { var tr = document.querySelector('#loctable tr[data-id="' + l.id + '"]'); return tr && tr.dataset.cur === '1' && tr.dataset.theme !== l.theme; })) { (window.top || window).location.reload(); return; }
    document.getElementById('locmsg').textContent = this.responseText.trim() === 'OK' ? t('str_saved') : (this.status === 401 ? t('str_log_in_first') : t('str_not_saved_error', {error: this.responseText}));
  };
  x.open('POST', 'scripts/locations.php?save=1', true);
  x.setRequestHeader('Content-Type', 'application/json');
  x.send(JSON.stringify(list));
}
</script>
