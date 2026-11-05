<?php
/* Field commands from the command line (US-120, owner 2026-10-20) — run as root by the Bluetooth service
 * (scripts/field_ble.py), with the same rules as the web pages (scripts/common.php):
 *   php field_cli.php gps <lat> <lon> [name]   the phone's position: the location holding it (renamed when a name is
 *                                               given) or a new one ("Field <date>" without a name) becomes current */
if (PHP_SAPI !== 'cli') { http_response_code(404); die(); }
require_once __DIR__ . '/common.php';
$a = array_slice($argv, 1);
if (($a[0] ?? '') === 'gps' && isset($a[1], $a[2]) && is_numeric($a[1]) && is_numeric($a[2])) {
  $lat = round(floatval($a[1]), 4); $lon = round(floatval($a[2]), 4);
  if (abs($lat) > 90 || abs($lon) > 180 || ($lat == 0 && $lon == 0)) { echo json_encode(array('ok' => false, 'error' => 'invalid coordinates')); exit(1); }
  $name = trim($a[3] ?? '');
  if ($name === '' && !location_at($lat, $lon)) $name = 'Field ' . date('Y-m-d');
  location_set_named($lat, $lon, $name);
  $l = location_at($lat, $lon);
  // a registered place brings its timezone and species list (owner 2026-10-20)
  $err = $l ? location_activate(array_merge(locations_complete(array($l))[0], array('lat' => $lat, 'lon' => $lon))) : apply_station_coordinates($lat, $lon);
  if ($err !== '') { echo json_encode(array('ok' => false, 'error' => $err)); exit(1); }
  $l = location_at($lat, $lon);
  echo json_encode(array('ok' => true, 'location' => $l ? $l['name'] : '', 'lat' => $lat, 'lon' => $lon), JSON_UNESCAPED_UNICODE);
  exit(0);
}
echo "usage: php field_cli.php gps <lat> <lon> [name]\n";
exit(2);
