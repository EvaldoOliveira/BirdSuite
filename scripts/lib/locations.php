<?php
################################################################################
# scripts/lib/locations.php - LOCATIONS: THE PLACES OF THE STATION AND THE CURRENT ONE
#
# PURPOSE:
#   The locations: the places of the station and the current one: the helpers of the BirdSuite pages on this
#   subject, moved out of common.php (one file per subject, owner 2026-11-06).
#
# DEPENDENCIES:
#   - scripts/common.php (loads this file after core.php and view_registry.php; every lib may call the others)
#
# USAGE:
#   Loaded by common.php on every page; never requested on its own (404 without BS_PROG).
################################################################################

if (!defined('BS_PROG')) { http_response_code(404); exit; }

################################################################################
# Locations (US-58, owner 2026-10-20)
################################################################################
// A location is a place where the station records: name, coordinates and a radius. Every detection already stores the
// Lat/Lon it was analysed with (BirdSuite.conf at that moment), so it belongs to the location whose radius holds them —
// no column added. The current location is the one holding LATITUDE/LONGITUDE; it is shown on Now and changes only
// when the microphone is reconnected or the boot check finds the station moved. The pages show the current location's
// records (the header picklist was removed, owner 2026-10-20): apply_location_filter() puts a
// TEMP VIEW named detections over main.detections on a read-only connection, which every unqualified query then reads.
// Stored in config/locations/locations.json (station data, outside git; the folder also holds each location's own files,
// e.g. its Now page backgrounds, owner 2026-11-02); seeded with the configured coordinates.
function locations_file() {
	return BS_LOCATIONS . '/locations.json';
}

// Built-in locations (owner 2026-10-20): "Home", the first one, and "Test / Playback", a second one for tests and for
// playing recordings to the station, so those detections stay apart and are deleted at once (Tools › Location Data,
// Delete all, then Species › Purge Files). Stored with their English name and a "builtin" mark; shown translated
// until renamed. The test location takes the coordinates of the first (primary) location (owner 2026-10-20), radius
// 50 m: the same place for the model, while the detections made when it is current carry its id (column Location,
// owner 2026-10-21) and are filed there and nowhere else. Nothing is notified
// or uploaded while it is current (utils/helpers.is_test_location). It is created once the first location has real
// coordinates.
function location_builtin_names() { return array('home' => 'Home', 'test' => 'Test / Playback'); }

// their text keys (locations.json keeps the English names above until a location is renamed)
function location_builtin_keys() { return array('home' => 'str_home', 'test' => 'str_test_playback'); }

function locations_load() {
	static $cache = null;
	if ($cache !== null) return $cache;
	$list = is_file(locations_file()) ? json_decode((string)file_get_contents(locations_file()), true) : null;
	$changed = false;
	if (!is_array($list) || !$list) {
		$c = get_config();
		$list = array(array('id' => 1, 'name' => 'Home', 'builtin' => 'home', 'lat' => round(floatval($c['LATITUDE'] ?? 0), 4),
												'lon' => round(floatval($c['LONGITUDE'] ?? 0), 4), 'radius_km' => LOCATION_RADIUS_KM, 'theme' => 'ocean'));
		$changed = true;
	}
	$has_test = false;
	foreach ($list as $l) if (($l['builtin'] ?? '') === 'test') $has_test = true;
	if (!$has_test && !(abs(floatval($list[0]['lat'])) < 0.0001 && abs(floatval($list[0]['lon'])) < 0.0001)) {
		$list[] = array('id' => max(array_map(function ($l) { return intval($l['id']); }, $list)) + 1, 'name' => 'Test / Playback', 'builtin' => 'test',
										'lat' => round(floatval($list[0]['lat']), 4), 'lon' => round(floatval($list[0]['lon']), 4), 'radius_km' => 0.05,
										'theme' => 'red');
		$changed = true;
	}
	if ($changed) {
		if (!is_dir(BS_LOCATIONS)) @mkdir(BS_LOCATIONS, 02775);
		@file_put_contents(locations_file(), json_encode(array_values($list), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n", LOCK_EX);
		@chmod(locations_file(), 0664); // the station user writes it too (scripts/location_data.py restore)
	}
	// the built-in names in the interface language, as long as they were not renamed
	$names = location_builtin_names();
	$nkeys = location_builtin_keys();
	foreach ($list as &$l) {
		$b = $l['builtin'] ?? '';
		if (isset($names[$b]) && $l['name'] === $names[$b]) $l['name'] = t($nkeys[$b]);
	}
	unset($l);
	// Test / Playback always last in every list (owner 2026-10-22)
	usort($list, function ($x, $y) { return (($x['builtin'] ?? '') === 'test') <=> (($y['builtin'] ?? '') === 'test'); });
	return $cache = $list;
}

// the timezone and species list of each location, filled from the station's when missing (owner 2026-10-20)
function locations_complete($list) {
	$tz = null; $sl = null;
	foreach ($list as &$l) {
		if (!isset($l['timezone'])) { $l['timezone'] = $tz ?? ($tz = station_timezone()); }
		if (!array_key_exists('species_list', $l)) { $l['species_list'] = $sl ?? ($sl = (string)(get_config()['SPECIES_LIST'] ?? '')); }
	}
	unset($l);
	return $list;
}

function locations_save($list) {
	// a built-in location keeps its English name on disk while it shows the translated one (any language)
	$names = location_builtin_names();
	$nkeys = location_builtin_keys();
	// the Now page background of a location (owner 2026-11-02) stays with it as the biome does;
	// the biome found for a location (US-27) stays with it; scripts/utils/locations.py biomes finds it again when the
	// coordinates changed (biome_at) and for a new location, in the background, and writes it on its detections
	$kept = array();
	foreach (locations_load() as $o) $kept[intval($o['id'])] = array_intersect_key($o, array_flip(array('biome', 'ecoregion', 'biome_br', 'biome_at', 'land_cover', 'built_up_pct', 'land_at', 'setting', 'built_up_500_pct', 'now_background', 'now_background_fade')));
	foreach ($list as &$l) {
		$b = $l['builtin'] ?? '';
		if (isset($names[$b]) && in_array($l['name'], array($names[$b], t($nkeys[$b])), true)) $l['name'] = $names[$b];
		if (!isset($l['biome']) && !empty($kept[intval($l['id'] ?? 0)])) $l += $kept[intval($l['id'])];
	}
	unset($l);
	if (!is_dir(BS_LOCATIONS)) @mkdir(BS_LOCATIONS, 02775);
	$ok = file_put_contents(locations_file(), json_encode(array_values($list), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n", LOCK_EX) !== false;
	@chmod(locations_file(), 0664);
	if ($ok) exec(station_prefix() . 'python3 ' . escapeshellarg(__ROOT__ . '/scripts/utils/locations.py') . ' biomes > /dev/null 2>&1 &');
	return $ok;
}

function location_km($lat1, $lon1, $lat2, $lon2) {
	$p1 = deg2rad($lat1); $p2 = deg2rad($lat2);
	$a = sin(($p2 - $p1) / 2) ** 2 + cos($p1) * cos($p2) * sin(deg2rad($lon2 - $lon1) / 2) ** 2;
	return 6371 * 2 * asin(min(1, sqrt($a)));
}

// the location holding these coordinates (the nearest whose radius reaches them), or null
function location_at($lat, $lon) {
	$best = null; $bd = INF;
	foreach (locations_load() as $l) {
		$d = location_km($lat, $lon, $l['lat'], $l['lon']);
		if ($d <= floatval($l['radius_km'] ?? LOCATION_RADIUS_KM) && $d < $bd) { $best = $l; $bd = $d; }
	}
	return $best;
}

// the current location (owner 2026-10-21): LOCATION_ID of BirdSuite.conf, written when the location changes; a station
// that has none yet (updated from before the tag) is at the location holding its coordinates
function location_current() {
	$c = get_config();
	$id = trim((string)($c['LOCATION_ID'] ?? ''), " \"'");
	if (ctype_digit($id) && ($l = location_by_id($id))) return $l;
	$l = location_at(floatval($c['LATITUDE'] ?? 0), floatval($c['LONGITUDE'] ?? 0));
	// a station without the tag (updated from before it, or set up before Gitea #91) gets it from the coordinates once,
	// so two locations sharing coordinates never make the tag ambiguous again
	if ($l) conf_set('LOCATION_ID', intval($l['id']), $err);
	return $l;
}

// The radius is the one distance of a location (owner 2026-10-21): a point within it is this location (the phone's GPS,
// a restored backup, the boot check). The boot check places the station by its public IP, which is 10 to 30 km off in a
// city, so it asks only beyond the radius and never below LOCATION_IP_ERROR_KM (default 30); the phone's GPS uses the
// radius as it is.
function location_ip_error_km() { return max(1, intval(get_config()['LOCATION_IP_ERROR_KM'] ?? 30)); }

function location_boot_km($l) { return max(location_ip_error_km(), (int)ceil(floatval($l['radius_km'] ?? LOCATION_RADIUS_KM))); }

function location_by_id($id) {
	foreach (locations_load() as $l) if (intval($l['id']) === intval($id)) return $l;
	return null;
}

// the pages show the current location's records; the header picklist (cookie loc_filter) was removed (owner 2026-10-20),
// so an old cookie is ignored — no browser stays stuck on another location
// The Latest page may show every location (owner 2026-10-21): its Location picklist keeps the choice in the now_loc cookie,
// read only by the Latest pages (they define NOW_PAGE); every other page shows the current location
// how many locations have detections (owner 2026-10-22: the Location picklist of Now only when more than one has data)
// Kept between pages (owner 2026-11-06, Gitea #75): every page asks it and the count walks the whole table; the key
// holds the database's and its WAL file's change time and size, so a detection at a new location shows at once.
// cached_value(..., true): not keyed by the location shown, which itself asks this count (location_filter()).
function locations_with_data() {
	static $n = null;
	if ($n !== null) return $n;
	clearstatcache(true, BS_DB . '-wal');
	$key = 'locations_with_data|' . @filesize(BS_DB) . '|' . @filemtime(BS_DB . '-wal') . '|' . @filesize(BS_DB . '-wal');
	$n = intval(cached_value($key, 3600, function () {
		return intval(get_db()->querySingle('SELECT COUNT(DISTINCT Location) FROM main.detections'));  // main.: the whole table, not the current location's view
	}, true));
	return $n;
}

function location_filter() {
	// the same data filter on every page, kept per page (owner 2026-10-22): only Now opens on All, the others on the
	// current location, until another choice; empty = the current location, a number = that location (whichever
	// is active); only while more than one location has detections
	if (locations_with_data() < 2) return 'current';
	list($ck, $def) = location_filter_page();
	// a page opened from another one carries that page's choice (?loc=all or a location id: the review of a Validation
	// species shows the detections Validation counted), ahead of its own kept choice
	$v = $_GET['loc'] ?? null;
	if ($v === 'all' || (ctype_digit((string)$v) && location_by_id($v))) return $v === 'all' ? 'all' : (string)intval($v);
	$v = $_COOKIE[$ck] ?? null;
	if ($v === null) return $def;
	if ($v === 'all') return 'all';
	if (ctype_digit((string)$v) && location_by_id($v)) return (string)intval($v);
	return 'current';
}

// the cookie and the default of the page's location filter: only Now (and its lists) on All, every other page on the
// current location (owner 2026-10-22)
function location_filter_page() {
	$view = defined('NOW_PAGE') ? 'Latest' : (string)($_GET['view'] ?? pathinfo($_SERVER['SCRIPT_NAME'] ?? '', PATHINFO_FILENAME));
	if ($view === 'curation') $view = 'Review';  // the table requests of scripts/curation.php share the page's choice
	// a renamed page keeps the cookie of its first name (US-51): its alias, so the location chosen there is not lost
	$e = view_entry(view_resolve($view));
	if ($e !== null && $e['alias']) $view = $e['alias'][0];
	$key = 'loc_' . preg_replace('/[^A-Za-z0-9]/', '', $view);
	// every page on the current location (owner 2026-10-31: Latest and Now show the active location; Latest opened on All
	// since 2026-10-22); All stays one choice away in the picker
	return array($key, 'current');
}

// the locations with detections, by id (owner 2026-10-22: the Location picklist of Now)
function locations_data_ids() {
	static $ids = null;
	if ($ids === null) {
		$ids = array();
		$r = get_db()->query('SELECT DISTINCT Location FROM main.detections');
		while ($r && ($x = $r->fetchArray(SQLITE3_NUM))) $ids[] = intval($x[0]);
	}
	return $ids;
}

// the location a page shows, named above its table or chart (owner 2026-10-21); $chart_current: a chart drawn for the
// current location even while the table shows them all (the Now daily chart)
// Loc: [picklist] (owner 2026-10-22): the data filter of every page, the same as Now — the current location (it follows a
// change of location), every other location with detections, then All; kept per page (location_filter_page); only while more
// than one location has detections. Not the activation of a location (that is the header).
function location_picker() {
	if (!multi_location() || count(locations_load()) < 2 || locations_with_data() < 2) return '';
	$h = function ($x) { return htmlspecialchars((string)$x, ENT_QUOTES); };
	$cur = location_current(); $f = location_filter(); $ids = locations_data_ids();
	$o = '<span class="locpick"><label for="locpick">' . t('str_loc') . '</label> <select id="locpick" class="nowperiod" title="' . $h(t('str_locations_shown_in_the_table_help')) . '"'
		. ' onchange="document.cookie = ' . $h(json_encode(location_filter_page()[0] . '=')) . ' + this.value + \';path=/;max-age=31536000;SameSite=Lax\'; location.reload()">'
		. '<option value=""' . ($f === 'current' || ($cur && $f === (string)intval($cur['id'])) ? ' selected' : '') . '>' . $h($cur ? $cur['name'] : t('str_current_location')) . '</option>';
	foreach (locations_load() as $l) {
		if (($cur && intval($l['id']) === intval($cur['id'])) || !in_array(intval($l['id']), $ids, true)) continue;
		$o .= '<option value="' . intval($l['id']) . '"' . ($f === (string)intval($l['id']) ? ' selected' : '') . '>' . $h($l['name']) . '</option>';
	}
	$o .= '<option value="all"' . ($f === 'all' ? ' selected' : '') . '>' . t('str_all') . '</option></select></span>'
		// the picklist as wide as the chosen name, not the longest one
		. '<script>(function () { var s = document.getElementById(\'locpick\'), c = document.createElement(\'canvas\').getContext(\'2d\'), cs = getComputedStyle(s);'
		. ' c.font = cs.fontWeight + \' \' + cs.fontSize + \' \' + cs.fontFamily; s.style.setProperty(\'width\', Math.ceil(c.measureText(s.options[s.selectedIndex].text).width + 30) + \'px\', \'important\'); })();</script>';
	return $o;
}

function location_label($chart_current = false) {
	// the pages' location line became the shared picker (owner 2026-10-22); the Now chart still names its location
	if (!$chart_current) return location_picker() !== '' ? '<div class="loclabel">' . location_picker() . '</div>' : '';
	if (count(locations_load()) < 2 || locations_with_data() < 2) return '';
	$l = $chart_current ? location_current() : location_shown();
	$name = $l ? $l['name'] : t('str_all_locations');
	return '<div class="loclabel">&#128205; ' . t('str_location_colon') . ' <b>' . htmlspecialchars($name) . '</b></div>';
}

// the location of one detection for the tables (owner 2026-10-21): its tag
function detection_location_name($row) {
	static $names = null;
	if ($names === null) { $names = array(); foreach (locations_load() as $l) $names[intval($l['id'])] = $l['name']; }
	if (isset($row['Location']) && $row['Location'] !== null && $row['Location'] !== '') return $names[intval($row['Location'])] ?? '#' . intval($row['Location']);
	return '-';
}

// the columns the detection tables read for detection_location_name
function location_columns_sql() {
	return 'Lat, Lon, Location';
}

// the detections of a location (owner 2026-10-21): every detection carries the id of the location it was recorded at
// (column Location), so two locations may share their coordinates (the same building, another window)
function location_sql($l) {
	return 'Location = ' . intval($l['id']);
}

// the location the pages show, or null for all of them
function location_shown() {
	$f = location_filter();
	if ($f === 'all') return null;
	if ($f === 'current') return location_current();
	return location_by_id($f) ?: null;
}

// the station's coordinates (BirdSuite.conf LATITUDE/LONGITUDE, 4 decimals) and, when the species list is a Brazilian
// state, the state's list there (scripts/locate_state.py); the analysis reads them from the next recording.
// Used by the Latest page answers (station moved, microphone reconnected) and the Locations page. Returns '' or an error.
function apply_station_coordinates($lat, $lon) {
	$home = get_home();
	$config = get_config();
	$lat = round(floatval($lat), 4); $lon = round(floatval($lon), 4);
	$set = array('LATITUDE' => (string)$lat, 'LONGITUDE' => (string)$lon);
	$new_list = null;
	if (preg_match('/^BR-[A-Z]{2}$/', $config['SPECIES_LIST'] ?? '')) {
		$uf = trim((string)shell_exec('python3 ' . escapeshellarg(BS_SCRIPTS . '/locate_state.py') . ' ' . escapeshellarg((string)$lat) . ' ' . escapeshellarg((string)$lon) . ' 2>/dev/null'));
		if (preg_match('/^[A-Z]{2}$/', $uf) && is_file(BS_MODEL . "/include_lists/BR-$uf.txt")) {
			$new_list = "BR-$uf";
			$set['SPECIES_LIST'] = $new_list;
		}
	}
	if (!conf_set_many($set, $err)) return $err;
	if ($new_list !== null && $new_list !== ($config['SPECIES_LIST'] ?? '')) {
		shell_exec(station_prefix() . 'python3 ' . escapeshellarg(BS_SCRIPTS . '/select_species_list.py') . ' > /dev/null 2>&1');
	}
	return '';
}

// a name no other location carries (Gitea #92: only Add refused a repeated name): the name itself, else "name 2",
// "name 3"...; compared without case, against the names shown and the English names of the built-in locations;
// $except = the id of the location being renamed
function location_unique_name($name, $list, $except = 0) {
	$taken = array();
	// the names as the list shows them (locations_load() gives a built-in place its translated name), never a built-in's
	// stored name (run 6 NEW-R6-92: a station from before bug #91 has its own "Home" beside the built-in one, shown "Casa",
	// and every save of the list was refused as repeated); the callers refuse a name only when it changes
	foreach ($list as $l) {
		if (intval($l['id'] ?? 0) === intval($except)) continue;
		$taken[] = mb_strtolower((string)$l['name']);
	}
	$base = $name; $n = 1;
	while (in_array(mb_strtolower($name), $taken, true)) $name = $base . ' ' . (++$n);
	return $name;
}

// true when another location already carries this name (the same comparison as location_unique_name)
function location_name_taken($name, $list, $except = 0) {
	return location_unique_name($name, $list, $except) !== $name;
}

// the detections a location holds, removed ones included (the whole table, not the location shown)
function location_detection_count($id) {
	$db = new SQLite3(BS_DB, SQLITE3_OPEN_READONLY);
	$db->busyTimeout(5000);
	$n = intval($db->querySingle('SELECT COUNT(*) FROM main.detections WHERE Location = ' . intval($id)))
		+ intval($db->querySingle('SELECT COUNT(*) FROM deleted_detections WHERE Location = ' . intval($id)));
	$db->close();
	return $n;
}

// Settings › Locations and First Run (owner 2026-10-20): the location holding these coordinates takes this name, or a new
// location is made for them when none holds them (name empty = Home, the default location).
// A fresh station (Gitea #91): its only location is the factory Home, made at the network position before the first run
// and empty; the first run's coordinates MOVE it (Test / Playback, which mirrors it, follows) instead of adding a second
// "Home" 14 km away. Either way the location becomes the current one (LOCATION_ID), so the tag never depends on the
// coordinates alone.
function location_set_named($lat, $lon, $name) {
	$name = mb_substr(trim(preg_replace('/[\x00-\x1F\x7F<>"]/', '', (string)$name)), 0, 60);
	$lat = round(floatval($lat), 4); $lon = round(floatval($lon), 4);
	$list = locations_load();
	$l = location_at($lat, $lon);
	$id = 0;
	if ($l) {
		$id = intval($l['id']);
		if ($name !== '' && $name !== $l['name']) {
			$name = location_unique_name($name, $list, $id);
			foreach ($list as &$x) if (intval($x['id']) === $id) $x['name'] = $name;
			unset($x);
			if (!locations_save($list)) return false;
		}
	} else {
		$real = array_values(array_filter($list, function ($x) { return ($x['builtin'] ?? '') !== 'test'; }));
		$fresh = count($real) === 1 && ($real[0]['builtin'] ?? '') === 'home' && location_detection_count($real[0]['id']) === 0;
		if ($fresh) {
			$id = intval($real[0]['id']);
			$old = array($real[0]['lat'], $real[0]['lon']);
			foreach ($list as &$x) {
				if (intval($x['id']) === $id || (($x['builtin'] ?? '') === 'test' && $x['lat'] == $old[0] && $x['lon'] == $old[1])) {
					$x['lat'] = $lat; $x['lon'] = $lon;
					unset($x['biome'], $x['ecoregion'], $x['biome_br'], $x['biome_at'], $x['land_cover'], $x['built_up_pct'], $x['land_at'], $x['setting'], $x['built_up_500_pct']);
				}
				if (intval($x['id']) === $id && $name !== '') $x['name'] = location_unique_name($name, $list, $id);
			}
			unset($x);
		} else {
			$id = max(array_map('intval', array_column($list, 'id'))) + 1;
			$list[] = array('id' => $id, 'name' => location_unique_name($name !== '' ? $name : 'Home', $list),
											'lat' => $lat, 'lon' => $lon, 'radius_km' => LOCATION_RADIUS_KM);
		}
		if (!locations_save($list)) return false;
	}
	if ($id > 0) conf_set('LOCATION_ID', $id, $err);
	return true;
}

// the station is now at this location: its coordinates, then its timezone and species list when it has them
// (Make current, Adjust, the microphone question, the Bluetooth GPS). Returns '' or an error.
function location_activate($l) {
	$err = apply_station_coordinates($l['lat'], $l['lon']);
	if ($err !== '') return $err;
	// the tag written on every new detection (owner 2026-10-21)
	if (!conf_set('LOCATION_ID', intval($l['id']), $err)) return $err;
	if (!empty($l['timezone'])) set_station_timezone($l['timezone']);
	if (array_key_exists('species_list', $l) && $l['species_list'] !== null) set_species_list((string)$l['species_list']);
	return '';
}

// The timezone of a point (owner 2026-10-20: it comes with the coordinates picked on the map, by default):
// in Brazil the state (IBGE outlines shipped, offline: scripts/locate_state.py --offline) gives it; elsewhere the timeapi.io
// lookup when online; without internet the offset of the longitude (Etc/GMT±n). Returns array(timezone, how).
function timezone_for($lat, $lon) {
	$br = array('AC' => 'America/Rio_Branco', 'AM' => 'America/Manaus', 'RO' => 'America/Porto_Velho', 'RR' => 'America/Boa_Vista',
		'MT' => 'America/Cuiaba', 'MS' => 'America/Campo_Grande', 'PA' => 'America/Belem', 'AP' => 'America/Belem', 'TO' => 'America/Araguaina',
		'MA' => 'America/Fortaleza', 'PI' => 'America/Fortaleza', 'CE' => 'America/Fortaleza', 'RN' => 'America/Fortaleza', 'PB' => 'America/Fortaleza',
		'PE' => 'America/Recife', 'AL' => 'America/Maceio', 'SE' => 'America/Maceio', 'BA' => 'America/Bahia');
	$uf = trim((string)shell_exec('python3 ' . escapeshellarg(BS_SCRIPTS . '/locate_state.py') . ' ' . escapeshellarg((string)floatval($lat))
		. ' ' . escapeshellarg((string)floatval($lon)) . ' --offline 2>/dev/null'));
	if (preg_match('/^[A-Z]{2}$/', $uf)) return array($br[$uf] ?? 'America/Sao_Paulo', t('str_brazil', array('uf' => $uf)));
	$ctx = stream_context_create(array('http' => array('timeout' => 4, 'header' => "User-Agent: BirdSuite\r\n")));
	$j = json_decode((string)@file_get_contents('https://timeapi.io/api/TimeZone/coordinate?latitude=' . floatval($lat) . '&longitude=' . floatval($lon), false, $ctx), true);
	if (!empty($j['timeZone']) && in_array($j['timeZone'], DateTimeZone::listIdentifiers(), true)) return array($j['timeZone'], 'timeapi.io');
	$off = (int)round(floatval($lon) / 15);
	return array($off === 0 ? 'Etc/GMT' : 'Etc/GMT' . ($off > 0 ? '-' : '+') . abs($off), t('str_from_the_longitude'));
}

// the biome of a location (US-27, owner 2026-10-29): "Biome: <the RESOLVE biome, translated> · <ecoregion>" and in Brazil
// "IBGE: <the IBGE biome>"; '' while it is not found yet (scripts/utils/locations.py biomes runs after a save)
function location_biome_text($l) {
	if (empty($l['biome']) && empty($l['biome_br'])) return isset($l['biome']) ? t('str_biome_none_sea_or_ice') : '';
	$h = function ($x) { return htmlspecialchars((string)$x, ENT_QUOTES); };
	$o = $l['biome'] ? t('str_biome', array('biome' => $h(t_data($l['biome'])))) . ($l['ecoregion'] ? ' · ' . $h($l['ecoregion']) : '') : '';
	if (!empty($l['biome_br'])) $o .= ($o !== '' ? ' · ' : '') . t('str_ibge', array('biome' => $h(t_data($l['biome_br']))));
	// the land cover within 100 m (ESA WorldCover, online when the location was saved)
	if (!empty($l['land_cover'])) $o .= '<br>' . t('str_land_cover_within_100_m_pct_help', array('cover' => $h(t_data($l['land_cover'])), 'pct' => intval($l['built_up_pct'] ?? 0)));
	// urban / peri-urban / natural from the built-up share within 500 m (owner 2026-10-29)
	if (!empty($l['setting'])) $o .= '<br>' . t('str_setting_pct_built_up_within_500_help', array('setting' => '<b>' . $h(t_data($l['setting'])) . '</b>', 'pct' => intval($l['built_up_500_pct'] ?? 0)));
	return $o;
}

// location model probability (0..1) of a species in the week of a date (region_profile.json, 48 weeks: four per month,
// as utils/classes.py week48); null when the species is not in the profile
// every species of the location profile with its probability in the week of $date (Now › Low Prob in SQL)
function location_profile_week($date) {
	static $pj = false;
	if ($pj === false) $pj = is_file(BS_SCRIPTS . '/region_profile.json') ? json_decode(file_get_contents(BS_SCRIPTS . '/region_profile.json'), true) : null;
	$t = strtotime($date ?: date('Y-m-d'));
	$w = min(48, (intval(date('n', $t)) - 1) * 4 + min(4, intdiv(intval(date('j', $t)) - 1, 7) + 1));
	$out = array();
	foreach (($pj['data'] ?? array()) as $sci => $weeks) $out[$sci] = floatval($weeks[$w - 1] ?? 0);
	return $out;
}

function location_probability($sci, $date) {
	static $profile = null;
	if ($profile === null) {
		$pj = is_file(BS_SCRIPTS . '/region_profile.json') ? json_decode(file_get_contents(BS_SCRIPTS . '/region_profile.json'), true) : null;
		$profile = $pj['data'] ?? array();
	}
	if (!isset($profile[$sci])) return null;
	$t = strtotime($date ?: date('Y-m-d'));
	$w = min(48, (intval(date('n', $t)) - 1) * 4 + min(4, intdiv(intval(date('j', $t)) - 1, 7) + 1));
	return floatval($profile[$sci][$w - 1]);
}

// ISO country code of the current location (owner 2026-11-01, the xeno-canto filter): from its species list when that is
// a region (BR-SP), else from state/geocode.json, the reverse-geocoding cache shared with ebird_report.py (key
// "lat,lon" at 2 decimals -> [state, country]), filled once per place from OpenStreetMap (3 s at most); '' when unknown
function location_country() {
	static $cc = null;
	if ($cc !== null) return $cc;
	$cc = '';
	$l = location_current();
	if (!$l) return $cc;
	if (preg_match('/^([A-Z]{2})-[A-Z0-9]{1,3}$/', (string)($l['species_list'] ?? ''), $m)) return $cc = $m[1];
	$lat = floatval($l['lat'] ?? 0); $lon = floatval($l['lon'] ?? 0);
	if (!$lat && !$lon) return $cc;
	$key = sprintf('%.2f,%.2f', $lat, $lon);
	$path = BS_STATE . '/geocode.json';
	$cache = json_decode((string)@file_get_contents($path), true) ?: array();
	if (!isset($cache[$key])) {
		$ctx = stream_context_create(array('http' => array('timeout' => 3, 'header' => "User-Agent: BirdSuite (station location)\r\n")));
		$j = json_decode((string)@file_get_contents(sprintf('https://nominatim.openstreetmap.org/reverse?format=jsonv2&zoom=5&lat=%.4f&lon=%.4f', $lat, $lon), false, $ctx), true);
		if (!is_array($j)) return $cc;
		$a = $j['address'] ?? array();
		$iso = (string)($a['ISO3166-2-lvl4'] ?? ($a['ISO3166-2-lvl3'] ?? ''));
		$cache[$key] = array(strpos($iso, '-') !== false ? explode('-', $iso, 2)[1] : '', strtoupper((string)($a['country_code'] ?? '')));
		@file_put_contents($path, json_encode($cache), LOCK_EX);
	}
	return $cc = (string)($cache[$key][1] ?? '');
}
