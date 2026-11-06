<?php
################################################################################
# scripts/lib/species.php - SPECIES: NAMES, LABELS, LISTS, INFORMATION
#
# PURPOSE:
#   The species: names, labels, lists, information: the helpers of the BirdSuite pages on this subject, moved
#   out of common.php (one file per subject, owner 2026-11-06).
#
# DEPENDENCIES:
#   - scripts/common.php (loads this file after core.php and view_registry.php; every lib may call the others)
#
# USAGE:
#   Loaded by common.php on every page; never requested on its own (404 without BS_PROG).
################################################################################

if (!defined('BS_PROG')) { http_response_code(404); exit; }

// the languages of the common names (model/l18n/labels_<code>.json): Model › Common name Language, the export in the old
// model (Backup/Restore); the names are translated with t()
function common_name_languages() {
	return array(
		"af" => "str_afrikaans",
		"ar" => "str_arabic",
		"ca" => "str_catalan",
		"cs" => "str_czech",
		"zh_CN" => "str_chinese_simplified",
		"zh_TW" => "str_chinese_traditional",
		"hr" => "str_croatian",
		"da" => "str_danish",
		"nl" => "str_dutch",
		"en" => "str_english",
		"et" => "str_estonian",
		"fi" => "str_finnish",
		"fr" => "str_french",
		"de" => "str_german",
		"hu" => "str_hungarian",
		"is" => "str_icelandic",
		"id" => "str_indonesia",
		"it" => "str_italian",
		"ja" => "str_japanese",
		"ko" => "str_korean",
		"lv" => "str_latvian",
		"lt" => "str_lithuania",
		"no" => "str_norwegian",
		"pl" => "str_polish",
		"pt_BR" => "str_portuguese_brazil_cbro",
		"pt_PT" => "str_portuguese_portugal",
		"ro" => "str_romanian",
		"ru" => "str_russian",
		"sr" => "str_serbian",
		"sk" => "str_slovak",
		"sl" => "str_slovenian",
		"es" => "str_spanish",
		"sv" => "str_swedish",
		"th" => "str_thai",
		"tr" => "str_turkish",
		"uk" => "str_ukrainian",
		"vi" => "str_vietnamese"
	);
}

// Species names are presentation only (owner 2026-11-05: "one index only"): the database, the analysis, the lists and the
// files know a species by its scientific name alone; the common name (the l18n file of the Common name Language,
// model/l18n/labels_<lang>.json) and the English name (the model's own, get_english_name()) are looked up when a page
// shows them. common_name() falls back on the English name, then on the scientific name.
function common_name($sci) {
	static $map = null;
	if ($map === null) {
		$lang = preg_replace('/[^A-Za-z_]/', '', (string)(get_config()['DATABASE_LANG'] ?? 'en')) ?: 'en';
		$map = json_decode((string)@file_get_contents(BS_MODEL . "/l18n/labels_$lang.json"), true);
		if (!is_array($map)) $map = array();
	}
	$sci = (string)$sci;
	if (isset($map[$sci]) && $map[$sci] !== '') return $map[$sci];
	$en = get_english_name($sci);
	return $en !== '' ? $en : $sci;
}

// the names as SQL functions of a connection: com_name(Sci_Name), en_name(Sci_Name) (deterministic, cheap: a lookup in
// a static map), so a query can show or sort by the name while every table holds the scientific name only
function db_names($db) {
	if (!($db instanceof SQLite3)) return $db;
	@$db->createFunction('com_name', 'common_name', 1, SQLITE3_DETERMINISTIC);
	@$db->createFunction('en_name', 'get_english_name', 1, SQLITE3_DETERMINISTIC);
	return $db;
}

// the English name shown (the name picklist's English, the tables): always the model's own (owner 2026-11-05, the BirdNET
// way), never the l18n file of a language; see get_english_name()
function get_com_en_name($sci_name) {
	$en = get_english_name($sci_name);
	return $en === '' ? null : $en;
}

function get_label($record, $sort_by, $date=null) {
	$name = common_name($record["Sci_Name"] ?? '');
	if ($sort_by == "confidence") {
		$ret = $name . ' (' . round($record['MaxConfidence'] * 100) . '%)';
	} elseif ($sort_by == "occurrences") {
		$valuescount = $record['Count'];
		if ($valuescount >= 1000) {
			$ret = $name . ' (' . fmt_num($valuescount / 1000, 1) . 'k)';
		} else {
			$ret = $name . ' (' . $valuescount . ')';
		}
	} elseif (($sort_by == "date") && !isset($date)) {
		$ret = $name . ' (' . $record['Date'] . ')';
	} elseif (($sort_by == "date") && isset($date)) {
		$ret = $name . ' (' . $record['Time'] . ')';
	} else {
		$ret = $name;
	}
	return $ret;
}

// the species list filter choices (SPECIES_LIST): none, the station lists of species_lists/, the Brazilian states not
// built yet — the select of Settings › Locations (owner 2026-10-20; was in the retired Basic Settings page)
function species_list_options($cur) {
	$home = get_home();
	$br = array('AC' => 'Acre', 'AL' => 'Alagoas', 'AP' => 'Amapá', 'AM' => 'Amazonas', 'BA' => 'Bahia', 'CE' => 'Ceará', 'DF' => 'Distrito Federal',
		'ES' => 'Espírito Santo', 'GO' => 'Goiás', 'MA' => 'Maranhão', 'MT' => 'Mato Grosso', 'MS' => 'Mato Grosso do Sul', 'MG' => 'Minas Gerais',
		'PA' => 'Pará', 'PB' => 'Paraíba', 'PR' => 'Paraná', 'PE' => 'Pernambuco', 'PI' => 'Piauí', 'RJ' => 'Rio de Janeiro',
		'RN' => 'Rio Grande do Norte', 'RS' => 'Rio Grande do Sul', 'RO' => 'Rondônia', 'RR' => 'Roraima', 'SC' => 'Santa Catarina',
		'SP' => 'São Paulo', 'SE' => 'Sergipe', 'TO' => 'Tocantins');
	$h = function ($x) { return htmlspecialchars((string)$x, ENT_QUOTES); };
	$out = "<option value=''" . ($cur === '' ? ' selected' : '') . ">" . t('str_none_use_the_models_species_help') . "</option>";
	$own = array();
	foreach (glob(BS_CUSTOM_LISTS . '/*.txt') ?: array() as $f) $own[] = basename($f, '.txt');
	sort($own);
	if ($own) {
		$out .= "<optgroup label='" . $h(t('str_station_lists_species_lists_custom')) . "'>";
		foreach ($own as $n) $out .= "<option value='" . $h($n) . "'" . ($cur === $n ? ' selected' : '') . '>' . $h((strpos($n, 'BR-') === 0 && isset($br[substr($n, 3)])) ? "$n: " . $br[substr($n, 3)] : $n) . '</option>';
		$out .= '</optgroup>';
	}
	$out .= "<optgroup label='" . $h(t('str_brazilian_states_wikiaves_help')) . "'>";
	foreach ($br as $uf => $name) {
		if (in_array("BR-$uf", $own, true) || !is_file(BS_MODEL . "/include_lists/BR-$uf.txt")) continue;
		$out .= "<option value='BR-$uf'" . ($cur === "BR-$uf" ? ' selected' : '') . ">$name ($uf)</option>";
	}
	return $out . '</optgroup>';
}

function species_list_valid($list) {
	$home = get_home();
	return $list === '' || (preg_match('/^BR-[A-Z]{2}$/', $list) && is_file(BS_MODEL . "/include_lists/$list.txt"))
		|| (preg_match('/^[A-Za-z0-9_-]+$/', $list) && is_file(BS_CUSTOM_LISTS . "/$list.txt"));
}

function set_species_list($list) {
	if (!species_list_valid($list) || $list === (get_config()['SPECIES_LIST'] ?? '')) return;
	if (!conf_set('SPECIES_LIST', $list)) return;
	shell_exec(station_prefix() . 'python3 ' . escapeshellarg(BS_SCRIPTS . '/select_species_list.py') . ' > /dev/null 2>&1');
}

// every species in the database with its common name, of every location (the search picks species with it; the list it
// feeds is filtered by location): a walk over the index that starts with Sci_Name, one seek per species, instead of
// GROUP BY over every detection (2026-10-23 run 3: 7 s at ten times the station's data, the walk some 30 ms); the name is
// presentation (common_name())
function species_name_pairs() {
	$sql = "WITH RECURSIVE c(n) AS (SELECT MIN(Sci_Name) FROM main.detections UNION ALL
         SELECT (SELECT MIN(Sci_Name) FROM main.detections WHERE Sci_Name > c.n) FROM c WHERE c.n IS NOT NULL)
       SELECT n FROM c WHERE n IS NOT NULL";
	$out = array();
	$r = get_db()->query($sql);
	while ($r && ($x = $r->fetchArray(SQLITE3_NUM))) $out[] = array($x[0], common_name($x[0]));
	return $out;
}

// the common name of each species (presentation, common_name())
function species_com_names($scis) {
	$out = array();
	foreach ($scis as $s) $out[$s] = common_name($s);
	return $out;
}

// WikiAves page of a Brazilian bird, from its CBRO name (pt_BR): "Sanhaço-cinzento" -> sanhaco-cinzento.
// Only for species of the Brazilian state lists (model/include_lists/BR-*.txt): the pt_BR file also
// names non-Brazilian birds (Portugal or English names), which have no WikiAves page.
function get_wikiaves_url($sciname) {
	static $brazilian = null, $cbro = null;
	if ($brazilian === null) {
		$brazilian = array();
		foreach (glob(__ROOT__ . '/model/include_lists/BR-*.txt') as $list) {
			foreach (file($list, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
				$brazilian[explode('_', $line)[0]] = true;
			}
		}
		$cbro = json_decode((string)@file_get_contents(__ROOT__ . '/model/l18n/labels_pt_BR.json'), true) ?: array();
	}
	if (!isset($brazilian[$sciname]) || empty($cbro[$sciname])) return '';
	$slug = strtr(mb_strtolower($cbro[$sciname], 'UTF-8'), array('á' => 'a', 'à' => 'a', 'â' => 'a', 'ã' => 'a', 'ä' => 'a',
		'é' => 'e', 'ê' => 'e', 'è' => 'e', 'í' => 'i', 'ï' => 'i', 'ó' => 'o', 'ô' => 'o', 'õ' => 'o', 'ö' => 'o',
		'ú' => 'u', 'ü' => 'u', 'ç' => 'c', 'ñ' => 'n'));
	$slug = trim(preg_replace('/[^a-z0-9]+/', '-', $slug), '-');
	return $slug === '' ? '' : "https://www.wikiaves.com.br/wiki/$slug";
}

// The excluded species that have detections (every location), as a quoted SQL list ('' when none): the species
// index lists the detected species at once, and an IN of the few found is searched by it (an IN of the whole exclude
// list, thousands of names, made SQLite read every detection)
function excluded_with_detections($db, $excl) {
	$found = array();
	$res = $db->query('SELECT DISTINCT Sci_Name FROM main.detections');
	while ($res && ($r = $res->fetchArray(SQLITE3_NUM))) if (isset($excl[$r[0]])) $found[] = "'" . SQLite3::escapeString($r[0]) . "'";
	return implode(',', $found);
}

// Add or remove species lines (Sci_Com) in a species list file: one line per scientific name, sorted (the lists and the
// species cards' toggles, US-10)
function update_species_list($filename, $species, $add) {
	$key = function ($line) { return explode('_', trim($line), 2)[0]; };
	$lines = array();
	// one scientific name per line (owner 2026-11-05: one index only); an old "Sci_Common" line is read by its first part
	// and written back as the scientific name alone
	foreach (@file($filename, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: array() as $l) {
		if (trim($l) !== '' && trim($l)[0] !== '#') $lines[$key($l)] = $key($l);
	}
	foreach ($species as $s) {
		$s = $key(htmlspecialchars_decode($s, ENT_QUOTES));
		if ($s === '') continue;
		if ($add) $lines[$s] = $s;
		else unset($lines[$s]);
	}
	ksort($lines, SORT_STRING);
	$text = $lines ? implode("\n", $lines) . "\n" : '';
	// a table linked to a saved list (Custom Species) is changed as a copy of its own (owner 2026-10-21): the saved list
	// stays as it was and the table shows "Table not saved" until it is saved under a name
	// (scripts/include_species_list.txt -> include_species_list.txt -> species_lists/<name>.txt: the last link is replaced)
	$target = $filename;
	if ($text !== (string)@file_get_contents($filename)) {
		for ($f = $filename, $i = 0; is_link($f) && $i < 5; $i++) {
			$next = readlink($f);
			if ($next !== '' && $next[0] !== '/') $next = dirname($f) . '/' . $next;
			if (strpos($next, '/species_lists/custom/') !== false && !is_link($next)) { @unlink($f); $target = $f; break; }
			$f = $next;
		}
	}
	file_put_contents($target, $text, LOCK_EX);
}

// The excluded species (exclude_species_list.txt) as a set of scientific names
function excluded_species() {
	$lines = @file(BS_LISTS . '/exclude_species_list.txt', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: array();
	return array_flip(array_map(function ($l) { return explode('_', trim($l), 2)[0]; }, $lines));
}

// Analysis settings of one detection for the review player: confidence, the minimum confidence it was detected
// with, the species override (US-48), sigmoid sensitivity, overlap, the location threshold (station setting, current)
// and the location model probability of the species in that week
// the species the active model can detect: [scientific name => common name in DATABASE_LANG] (BirdNET-Plus models:
// <MODEL>_Labels.txt, the others model/labels.txt; names from model/l18n/labels_<lang>.json, else the label's own)
function active_model_labels() {
	static $out = null;
	if ($out !== null) return $out;
	$cfg = get_config();
	$dir = BS_MODEL . '/';
	$file = (strpos($cfg['MODEL'] ?? '', 'BirdNET-Plus') === 0 && is_file($dir . $cfg['MODEL'] . '_Labels.txt'))
		? $dir . $cfg['MODEL'] . '_Labels.txt' : $dir . 'labels.txt';
	$lang = json_decode((string)@file_get_contents($dir . 'l18n/labels_' . ($cfg['DATABASE_LANG'] ?? 'en') . '.json'), true) ?: array();
	$out = array();
	foreach (@file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: array() as $l) {
		$p = explode('_', trim($l), 2);
		$out[$p[0]] = $lang[$p[0]] ?? ($p[1] ?? $p[0]);
	}
	return $out;
}

// what a label is and where it lives (model/species_info.csv, built once from GBIF by scripts/build_species_info.py,
// plus model/species_info_local.csv, the labels the station looked up itself):
// array(type, region) — type bird / mammal / amphibian / reptile / insect / other_animal / domestic / noise / unknown,
// region 'Global' or continents '+' separated (+ 'Ocean'); without the table: the class column of the BirdNET-Plus
// labels CSV (else the genus' class) and no region
function species_info($sci) {
	static $info = null, $cls = null, $genus = null;
	if ($info === null) {
		$info = array();
		// the shipped table, then the station's own additions (scripts/update_species_info.sh)
		foreach (array('species_info.csv', 'species_info_local.csv') as $f) {
			if (($h = @fopen(BS_MODEL . '/' . $f, 'r')) === false) continue;
			fgetcsv($h, 0, ';', '"', '');
			while (($r = fgetcsv($h, 0, ';', '"', '')) !== false) if (isset($r[2])) $info[$r[0]] = array($r[1], $r[2]);
			fclose($h);
		}
	}
	if (isset($info[$sci])) return $info[$sci];
	if ($cls === null) {
		$cls = array(); $genus = array();
		foreach (glob(BS_MODEL . '/BirdNET-Plus_*_Labels.csv') ?: array() as $f) {
			if (($h = fopen($f, 'r')) === false) continue;
			$head = array_map(function ($x) { return trim($x, "\xEF\xBB\xBF \t"); }, fgetcsv($h, 0, ';', '"', '') ?: array());
			$si = array_search('sci_name', $head); $ci = array_search('class', $head);
			while ($si !== false && $ci !== false && ($r = fgetcsv($h, 0, ';', '"', '')) !== false) {
				if (!isset($r[$si], $r[$ci])) continue;
				$cls[$r[$si]] = $r[$ci];
				$genus[strtok($r[$si], ' ')] = $genus[strtok($r[$si], ' ')] ?? $r[$ci];
			}
			fclose($h);
		}
	}
	$c = $cls[$sci] ?? ($genus[strtok($sci, ' ')] ?? '');
	return array(array('Aves' => 'bird', 'Mammalia' => 'mammal', 'Amphibia' => 'amphibian', 'Insecta' => 'insect')[$c] ?? 'unknown', '');
}

// group of a species for the list filters (owner 2026-10-09): birds, mammals, amphibians, insects, domestic, noise or
// others (reptiles, other animals, unknown)
function species_group($sci) {
	$t = species_info($sci)[0];
	return array('bird' => 'birds', 'mammal' => 'mammals', 'amphibian' => 'amphibians', 'insect' => 'insects',
		'domestic' => 'domestic', 'noise' => 'noise')[$t] ?? 'others';
}

// field-guide position of a species (model/taxonomy_order.csv, eBird/Clements TAXON_ORDER, scripts/build_taxonomy_order.py);
// non-birds and unknown names after every bird
function taxon_order($sci) {
	static $t = null;
	if ($t === null) {
		$t = array();
		if (($h = @fopen(BS_MODEL . '/taxonomy_order.csv', 'r')) !== false) {
			fgetcsv($h, 0, ';', '"', '');
			while (($r = fgetcsv($h, 0, ';', '"', '')) !== false) if (isset($r[1])) $t[$r[0]] = floatval($r[1]);
			fclose($h);
		}
	}
	return $t[$sci] ?? 999999;
}

// a model's name in short: BirdNET+ V3.0 or BirdNET V2.4 (the file name otherwise)
function model_short_name($m) {
	if (stripos($m, 'V3') !== false) return 'BirdNET+ V3.0';
	if (stripos($m, 'V2.4') !== false) return 'BirdNET V2.4';
	return $m;
}

// Species title block (owner 2026-10-08): [common name / scientific name] [links], the
// WikiAves / eBird / Birds of the World / Wikipedia icons beside the names at the height of both lines.
// $name_html = the common name as the page renders it (a link, a button...); $extra = more icons for the link row.
// $english = true: a third line with the English (eBird/Clements) name when the station shows names in another language
function species_title($sciname, $name_html, $extra = '', $english = false, $icon = true) {
	$en = '';
	if ($english && (get_config()['DATABASE_LANG'] ?? 'en') !== 'en') {
		$en_name = get_english_name($sciname);
		if ($en_name !== '' && mb_strtolower($en_name) !== mb_strtolower(trim(strip_tags($name_html)))) {
			$en = '<br><span class="spen" title="' . t('str_english_name_ebird_clements') . '">' . htmlspecialchars($en_name) . '</span>';
		}
	}
	// the species-page icon before the names left the app (owner 2026-10-21); $icon is kept for the callers
	return '<div class="sptitle"><div class="spnames">' . $name_html . '<br><i>'
		. htmlspecialchars($sciname) . '</i>' . $en . '</div><div class="splinks">' . species_links($sciname, '', 0) . $extra . '</div></div>';
}

// English name of a species, the model's own (owner 2026-11-05, the BirdNET way): the V3 model labels ("Sci_English",
// always English, whatever the station language), then BirdNET's English l18n file for the V2.4 / 6K species (their
// labels hold only the scientific name); '' when unknown. With English as the common-name language the common name (l18n) and this one may
// differ, and that is fine
function get_english_name($sciname) {
	static $map = null;
	if ($map === null) {
		$map = array();
		$model = BS_MODEL . '/';
		foreach (glob($model . 'BirdNET-Plus_*_Labels.txt') ?: array() as $f) {
			if (strpos($f, '_Geo_') !== false) continue;
			foreach (file($f, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $l) {
				$p = explode('_', $l, 2);
				if (count($p) === 2 && !isset($map[$p[0]])) $map[$p[0]] = $p[1];
			}
		}
		// the V2.4 / 6K labels hold only the scientific name: BirdNET's English for them is its l18n English file
		$en = json_decode((string)@file_get_contents($model . 'l18n/labels_en.json'), true);
		if (is_array($en)) $map += $en;
	}
	return $map[$sciname] ?? '';
}

function species_links($sciname, $style = '', $width = 20) {
	static $ebirds = null;
	if ($ebirds === null) {
		require __ROOT__ . '/scripts/ebird.php';
	}
	$config = get_config();
	$lang = $config['DATABASE_LANG'] ?? 'en';
	// the order of US-29: WikiAves (first, only with the CBRO names: pt_BR), eBird, Birds of the World, Wikipedia, xeno-canto
	$links = array();
	if ($lang === 'pt_BR' && ($wikiaves = get_wikiaves_url($sciname)) !== '') {
		$links[] = array($wikiaves, 'WikiAves', 'images/wikiaves.png');
	}
	$code = $ebirds[$sciname] ?? '';
	if ($code !== '') {
		$links[] = array("https://ebird.org/species/$code?siteLanguage=$lang", 'eBird', 'images/ebird.png');
		$links[] = array("https://birdsoftheworld.org/bow/species/$code/cur/introduction", 'Birds of the World', 'images/bow.png');
	}
	$wiki_lang = explode('_', $lang)[0];
	$links[] = array("https://$wiki_lang.wikipedia.org/wiki/" . str_replace(' ', '_', $sciname), 'Wikipedia', 'images/wiki.png');
	// the species' recordings on xeno-canto, in the sonograms view (owner 2026-11-01); only those of the current
	// location's country when it is known (cnt:BR)
	$cnt = location_country();
	// best first: sorted by quality, A to E (order=qual, owner 2026-11-01)
	$links[] = array('https://xeno-canto.org/explore?query=' . rawurlencode($sciname . ($cnt !== '' ? ' cnt:' . $cnt : '')) . '&view=3&order=qual&dir=0',
		$cnt !== '' ? t('str_xeno_canto_recordings_from_as_help', array('country' => $cnt)) : t('str_xeno_canto_recordings_as_help'), 'images/xenocanto.png');
	$html = '';
	foreach ($links as $l) {
		$html .= '<a href="' . htmlspecialchars($l[0], ENT_QUOTES) . '" target="_blank"><img style="' . htmlspecialchars($style, ENT_QUOTES)
			. '" title="' . $l[1] . '" alt="' . $l[1] . '" src="' . $l[2] . '"' . ($width ? ' width="' . intval($width) . '"' : '') . '></a> ';
	}
	return $html;
}
