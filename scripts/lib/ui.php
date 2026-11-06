<?php
################################################################################
# scripts/lib/ui.php - INTERFACE: TEXTS, LANGUAGES, LEVELS, PAGE HELPERS
#
# PURPOSE:
#   The interface: texts, languages, levels, page helpers: the helpers of the BirdSuite pages on this subject,
#   moved out of common.php (one file per subject, owner 2026-11-06).
#
# DEPENDENCIES:
#   - scripts/common.php (loads this file after core.php and view_registry.php; every lib may call the others)
#
# USAGE:
#   Loaded by common.php on every page; never requested on its own (404 without BS_PROG).
################################################################################

if (!defined('BS_PROG')) { http_response_code(404); exit; }

################################################################################
# Interface language (US-122, owner 2026-10-20; text keys 2026-11-02)
################################################################################
// Every text has a key: t('str_upload_an_image') answers the text of the language chosen in UI_LANG (empty = follow
// DATABASE_LANG when there is an interface for it), or the English one (lang/en, the only reference) when that
// catalogue lacks it. Catalogues: lang/<code>/*.json (one file per page group, merged). {name} placeholders are filled
// from $vars, so word order can change between languages. python3 lang/check.py checks the keys.
function ui_languages() {
	return array('en' => 'English', 'pt_BR' => 'Português', 'es' => 'Español', 'zh_CN' => '简体中文', 'ja' => '日本語',
		'de' => 'Deutsch', 'fr' => 'Français', 'it' => 'Italiano', 'nl' => 'Nederlands',
		'pl' => 'Polski', 'ru' => 'Русский');
}

function station_numeric_sync($ui, $db) {
	$l = $ui !== '' ? $ui : $db;
	if (!isset(ui_languages()[$l])) $l = $l === 'pt' ? 'pt_BR' : (isset(ui_languages()[substr($l, 0, 2)]) ? substr($l, 0, 2) : 'en');
	@exec('sudo -n ' . BS_ROOT_HELPER . ' numeric ' . escapeshellarg($l) . ' > /dev/null 2>&1 &');
}

function ui_lang() {
	static $l = null;
	if ($l === null) {
		$c = get_config();
		$l = trim((string)($c['UI_LANG'] ?? ''), " \"'");
		if ($l === '') {
			$d = (string)($c['DATABASE_LANG'] ?? 'en');
			// the species-name language picks the interface when there is one for it (pt / pt_PT -> pt_BR, zh_TW -> zh_CN)
			$p = substr($d, 0, 2);
			$l = $p === 'pt' ? 'pt_BR' : ($p === 'zh' ? 'zh_CN' : $p);
		}
		if (!isset(ui_languages()[$l])) $l = 'en';
	}
	return $l;
}

// English first, the chosen language over it: a key without a translation shows its English text
function ui_catalog() {
	static $cat = null;
	if ($cat === null) {
		$cat = array();
		foreach (array_unique(array('en', ui_lang())) as $lang) {
			foreach (glob(__ROOT__ . '/lang/' . $lang . '/*.json') ?: array() as $f) {
				$j = json_decode((string)file_get_contents($f), true);
				if (is_array($j)) $cat = array_merge($cat, array_filter($j, 'strlen'));
			}
		}
		// a text names a page as {menu:<view key>} (US-51, owner 2026-11-04): it shows the page's current place in the
		// menu ("Station › Location"), so a renamed or moved page never leaves an old path in a help text
		foreach ($cat as $k => $txt) {
			if (strpos($txt, '{menu:') !== false) {
				$cat[$k] = preg_replace_callback('/\{menu:([^}]+)\}/', function ($m) use ($cat) { return menu_path($m[1], $cat); }, $txt);
			}
		}
	}
	return $cat;
}

// the station's experience level (US-52): essential, birder or expert; empty (an updated station) = expert
function ui_level() {
	// a named user with a level of its own (US-56) sees that one
	if (function_exists('login_user') && ($u = login_user()) !== null && $u['level'] !== '') return $u['level'];
	$l = trim((string)(get_config()['UI_LEVEL'] ?? ''), " \"'");
	return isset(ui_levels()[$l]) ? $l : 'expert';
}

// the station runs outdoors (UI_CONTEXT field_phone or field_offline, US-53): the field pages show at every level
function ui_field_context() {
	return (bool)preg_match('/(^|,)field_(phone|offline)(,|$)/', trim((string)(get_config()['UI_CONTEXT'] ?? ''), " \"'"));
}

// the level reaches $level (expert reaches every level)
function ui_at_least($level) {
	$lv = ui_levels();
	return $lv[ui_level()] >= ($lv[$level] ?? 2);
}

// the expert part of a form (US-52): open at the level that reaches it, folded under "More options" below it; never
// removed, so every field stays reachable and saves as before. Close it with more_options_end().
function more_options($level = 'expert') {
	$open = ui_at_least($level);
	return '<details class="moreopts" data-level="' . htmlspecialchars($level, ENT_QUOTES) . '"' . ($open ? ' open' : '') . '>'
		. '<summary' . ($open ? ' class="moreopts-open"' : '') . '>' . htmlspecialchars(t('str_more_options')) . '</summary>';
}

function more_options_end() { return '</details>'; }

// a setting saved as soon as it changes (static/ui-standard.js data-setting, scripts/settings_set.php), yellow when it
// differs from its factory value: a number field or a picklist, its label and its help line (owner 2026-11-04)
// 'apply' => true: the field is not saved on change but by the section's Apply button (bsApplySettings, ui-standard.js),
// together with the section's other such fields (owner 2026-11-05)
function setting_field($key, $label, $opts = array(), $help = '') {
	$v = (string)(get_config()[$key] ?? (birdsuite_defaults()[$key] ?? ''));
	$h = function ($s) { return htmlspecialchars((string)$s, ENT_QUOTES); };
	$attr = !empty($opts['apply']) ? 'data-setting-apply' : 'data-setting';
	if (isset($opts['choices'])) {
		$f = '<select ' . $attr . '="' . $h($key) . '" data-default-key="' . $h($key) . '">';
		foreach ($opts['choices'] as $c) $f .= '<option value="' . $h($c) . '"' . ($c === $v ? ' selected' : '') . '>' . $h($c) . '</option>';
		$f .= '</select>';
	} else {
		$f = '<input type="number" ' . $attr . '="' . $h($key) . '" data-default-key="' . $h($key) . '" value="' . $h($v) . '" style="width:6em"'
			. (isset($opts['min']) ? ' min="' . $h($opts['min']) . '"' : '') . (isset($opts['max']) ? ' max="' . $h($opts['max']) . '"' : '')
			. ' step="' . $h($opts['step'] ?? 1) . '">';
	}
	return '<div style="margin:6px 0"><label><b>' . $h($label) . '</b> ' . $f . (isset($opts['unit']) ? ' ' . $h($opts['unit']) : '') . '</label>'
		. ($help !== '' ? '<div class="small">' . $help . '</div>' : '') . '</div>';
}

// the place of a page in the menu, in the interface language: "Group › Page", or the page alone at the top level
function menu_path($view, $cat = null) {
	if ($cat === null) $cat = ui_catalog();
	$k = view_resolve($view);
	$e = $k === '' ? null : view_entry($k);
	if ($e === null) return (string)$view;
	$label = $cat[$e['label']] ?? $k;
	return (string)$e['group'] !== '' && $e['group'] !== null ? ($cat[$e['group']] ?? $e['group']) . ' › ' . $label : $label;
}

// a text that is not a key (a message already translated, e.g. passed to ensure_authenticated()) comes back as it is
function t($s, $vars = array()) {
	$c = ui_catalog();
	$r = $c[$s] ?? $s;
	foreach ($vars as $k => $v) $r = str_replace('{' . $k . '}', (string)$v, $r);
	return $r;
}

// the same for the page's JavaScript: window.t(key, {name: value}); printed in the head of every page
// numbers in the format of the BirdSuite language (decimal and thousands separators); coordinates keep the point (the
// usual way to write them everywhere); data-sort, value= and input fields keep the point too (the server reads them)
function num_separators() {
	// the BirdSuite language decides (owner 2026-10-23: the number format follows UI_LANG, or DATABASE_LANG when empty;
	// release run 4: reading the system locale left 0.85 on a pt_BR page whenever the Pi's locale was not synced yet)
	static $sep = null;
	if ($sep !== null) return $sep;
	$map = array('en' => array('.', ','), 'ja' => array('.', ','), 'zh_CN' => array('.', ','),
							 'fr' => array(',', "\u{202F}"), 'pl' => array(',', "\u{00A0}"), 'ru' => array(',', "\u{00A0}"));
	return $sep = $map[ui_lang()] ?? array(',', '.'); // pt_BR, es, de, it, nl
}

function fmt_num($n, $dec = 0) {
	$sep = num_separators();
	return number_format((float)$n, intval($dec), $sep[0], $sep[1]);
}

// a number as a setting writes it ("0.7", "0.03", "15") shown in the interface language's format with the same decimals
// (display only: fields the server reads keep the point); anything else is returned as it is
function fmt_setting($v) {
	$s = trim((string)$v);
	if ($s === '' || !is_numeric($s) || stripos($s, 'e') !== false) return $s;
	$dot = strpos($s, '.');
	return fmt_num((float)$s, $dot === false ? 0 : strlen($s) - $dot - 1);
}

// The one pager of every list (owner 2026-10-21: tables, paging and names the same everywhere): one line under the
// table, "Last [N] <things>" on the left (the picklist calls $count_js with the new N), newer / range / older in the
// middle (the buttons call $page_js with the new offset). $label = the text with {select}, e.g. t('str_last_detections').
// The Filter icon of the pages (owner 2026-10-21): it opens / folds the filters and the search of its bar ("fopen")
function filter_toggle() {
	return '<span class="dashfilter" role="button" tabindex="0" title="' . htmlspecialchars(t('str_filter_and_search_show_hide'), ENT_QUOTES) . '"'
		. ' onclick="this.closest(\'.sortbar\').classList.toggle(\'fopen\')" onkeydown="if (event.key === \'Enter\' || event.key === \' \') { event.preventDefault(); this.click(); }">'
		. '<svg viewBox="0 0 24 24" width="18" height="18" aria-label="' . htmlspecialchars(t('str_filter'), ENT_QUOTES) . '" role="img"><path fill="currentColor" d="M3 4h18l-7 8.5V19l-4 2v-8.5z"/></svg></span>';
}

// The Sort icon of every page with sort buttons (owner 2026-10-21): an icon like the Filter one; it opens / folds the
// sort buttons of the bar it sits in (the bar carries class "sortbar"; static/ui-standard.js bsSortToggle)
function sort_toggle() {
	return '<span class="sorticon" role="button" tabindex="0" title="' . htmlspecialchars(t('str_sort'), ENT_QUOTES) . '" onclick="bsSortToggle(this)"'
		. ' onkeydown="if (event.key === \'Enter\' || event.key === \' \') { event.preventDefault(); bsSortToggle(this); }">'
		. '<svg viewBox="0 0 24 24" width="18" height="18" aria-label="' . htmlspecialchars(t('str_sort'), ENT_QUOTES) . '" role="img"><path fill="currentColor" d="M7 3l4 5H8v8H6V8H3zm10 18l-4-5h3V8h2v8h3z"/></svg></span>';
}

// CSV of a page's table (owner 2026-10-22, By Hour and By Week): a download icon beside Filter / Sort, and the file itself
function csv_icon($href) {
	return '<a class="csvicon" href="' . htmlspecialchars($href, ENT_QUOTES) . '" download title="' . htmlspecialchars(t('str_download_the_table_as_csv'), ENT_QUOTES) . '">'
		. '<svg viewBox="0 0 24 24" width="18" height="18" aria-label="CSV" role="img"><path fill="currentColor" d="M11 3h2v9.2l3.3-3.3 1.4 1.4L12 16l-5.7-5.7 1.4-1.4 3.3 3.3zM4 18h16v2H4z"/></svg></a>';
}

function csv_out($name, $head, $rows) {
	header('Content-Type: text/csv; charset=utf-8');
	header('Content-Disposition: attachment; filename="' . $name . '"');
	$f = fopen('php://output', 'w');
	fwrite($f, "\xEF\xBB\xBF");  // UTF-8 BOM: Excel shows the accents right
	fputcsv($f, $head);
	foreach ($rows as $r) fputcsv($f, $r);
	fclose($f);
	die();
}

function list_pager($label, $offset, $shown, $page, $more, $count_js, $page_js, $count_id = '') {
	$opts = '';
	foreach (array(10, 25, 50, 100) as $c) $opts .= '<option' . ($c == $page ? ' selected' : '') . '>' . $c . '</option>';
	$sel = '<select' . ($count_id !== '' ? ' id="' . $count_id . '"' : '') . ' class="nowcount" onchange="' . $count_js . '(this.value)" title="' . htmlspecialchars(t('str_how_many_the_list_shows'), ENT_QUOTES) . '">' . $opts . '</select>';
	return '<div class="nowpager"><span class="nowpcount">' . str_replace('{select}', $sel, $label) . '</span><span class="nowpnav">'
		. ($offset > 0 ? '<button type="button" class="openbtn" onclick="' . $page_js . '(' . max(0, $offset - $page) . ')">&#9664; ' . t('str_newer', array('n' => $page)) . '</button>' : '')
		. '<span class="nowprange">' . ($shown ? ($offset + 1) . '-' . ($offset + $shown) : '') . '</span>'
		. ($more ? '<button type="button" class="openbtn" onclick="' . $page_js . '(' . ($offset + $page) . ')">' . t('str_older', array('n' => $page)) . ' &#9654;</button>' : '')
		. '</span><span></span></div>';
}

// the hearing aid as the pages need it: on, the ratio heard / real, and a stamp of the settings (a new lowered clip
// after a change)
function hearing_state() {
	$c = get_config();
	$hi = max(500, intval($c['FREQSHIFT_HI'] ?? 12000));
	$lo = max(100, intval($c['FREQSHIFT_LO'] ?? 5000));
	$base = max(0, intval($c['FREQSHIFT_BASE'] ?? 0));
	return array('on' => false, 'r' => max(0.2, min(1, $lo / $hi)), 'base' => $base, 'v' => substr(md5("$hi|$lo|$base"), 0, 8));
}

function ui_js() {
	// window.fmtNum(n, decimals): numbers shown to the user in the station locale's format, the same as fmt_num()
	return '<script>window.BS_NUM = ' . json_encode(array('dec' => num_separators()[0], 'thou' => num_separators()[1]), JSON_UNESCAPED_UNICODE) . ';'
		. ' window.fmtNum = function (n, d) { n = Number(n); if (!isFinite(n)) return String(n); var p = n.toFixed(d || 0).split("."), s = window.BS_NUM;'
		. ' return (p[0].charAt(0) === "-" ? "-" : "") + p[0].replace("-", "").replace(/\\B(?=(\\d{3})+(?!\\d))/g, s.thou) + (p[1] ? s.dec + p[1] : ""); };'
		. ' window.BS_LANG = ' . json_encode(ui_lang()) . '; window.BS_I18N = ' . json_encode((object)ui_catalog(), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG)
		. '; window.t = function (s, v) { var r = (window.BS_I18N[s] || s); if (v) for (var k in v) r = r.split("{" + k + "}").join(v[k]); return r; };'
		// the factory values (factory_defaults.conf, in git, nothing secret): a field that differs is yellow (owner 2026-10-24,
		// static/ui-standard.js)
		. ' window.BS_DEFAULTS = ' . json_encode((object)birdsuite_defaults(), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) . ';'
		// the location this page shows ('' = the current one): a link to another page with detections carries it (ui-standard.js)
		. ' window.BS_LOC = ' . json_encode(($lf = location_filter()) === 'current' ? '' : $lf) . ';'
		// the hearing aid (owner 2026-11-04): switched per device by the header's Live Audio (kept in the browser, bs_hearing);
		// on = what goes to the speaker is lowered: the live sound in the browser (static/hearing-audio.js), a clip through
		// play.php?lowered= (nothing saved); the settings are the station's. The header sends every change down to the pages
		// and their frames (event bs:hearing); another tab follows through the storage event
		. ' window.BS_HEARING = ' . json_encode(hearing_state()) . '; try { window.BS_HEARING.on = localStorage.getItem("bs_hearing") === "1"; } catch (e) {}'
		. ' window.addEventListener("storage", function (e) { if (e.key !== "bs_hearing" || window.top !== window) return; window.BS_HEARING.on = e.newValue === "1"; if (window.hearSend) window.hearSend(); });'
		. ' window.addEventListener("message", function (e) { if (e.origin !== location.origin || !e.data || !e.data.bsHearing) return; window.BS_HEARING = e.data.bsHearing;'
		. ' document.dispatchEvent(new CustomEvent("bs:hearing", {detail: e.data.bsHearing})); [].forEach.call(document.querySelectorAll("iframe"), function (f) { try { f.contentWindow.postMessage(e.data, location.origin); } catch (x) {} }); });'
		. ' window.bsClipSrc = function (u) { var h = window.BS_HEARING, m = h && h.on ? String(u || "").match(/^\/?(By_Date|Purge)\/([^?]+)/) : null;'
		. ' return m ? "play.php?lowered=" + encodeURIComponent(m[1] + "/" + decodeURIComponent(m[2])) + "&v=" + h.v : u; };</script>'
		// one sentence per line on every page (owner 2026-10-21): static/sentence-lines.js breaks the line after each sentence
		. '<script src="/static/sentence-lines.js?v=' . @filemtime(__ROOT__ . '/homepage/static/sentence-lines.js') . '"></script>'
		// the buttons of one table column with the same width (owner 2026-10-21)
		. '<script src="/static/ui-standard.js?v=' . @filemtime(__ROOT__ . '/homepage/static/ui-standard.js') . '"></script>'
		// every stdtable sorts by a click on a column title, on every page (2026-11-01: Data, Backup, Database, External
		// Files, Status, Disk Space did not load it)
		. '<script src="/static/std-table.js?v=' . @filemtime(__ROOT__ . '/homepage/static/std-table.js') . '"></script>'
		// the shared dialog (askModal, bsNotice, bsPrompt) on every page: every question and notice goes through it, never the browser's dialogs
		// (2026-11-01, one look and the safe answer as the default everywhere)
		. '<script src="/static/species-modal.js?v=' . @filemtime(__ROOT__ . '/homepage/static/species-modal.js') . '"></script>';
}

// the names the location data is written with (English: RESOLVE and IBGE biomes, ESA WorldCover classes of
// scripts/land_cover.py, its urban / peri-urban / natural setting) and their text keys; t_data() shows one translated,
// a name not listed as it is
function data_terms() {
	return array(
		'Tropical & Subtropical Moist Broadleaf Forests' => 'str_biome_tropical_moist_broadleaf',
		'Tropical & Subtropical Dry Broadleaf Forests' => 'str_biome_tropical_dry_broadleaf',
		'Tropical & Subtropical Coniferous Forests' => 'str_biome_tropical_coniferous',
		'Temperate Broadleaf & Mixed Forests' => 'str_biome_temperate_broadleaf_mixed',
		'Temperate Conifer Forests' => 'str_temperate_conifer_forests', 'Boreal Forests/Taiga' => 'str_boreal_forests_taiga',
		'Tropical & Subtropical Grasslands, Savannas & Shrublands' => 'str_biome_tropical_grasslands',
		'Temperate Grasslands, Savannas & Shrublands' => 'str_biome_temperate_grasslands',
		'Flooded Grasslands & Savannas' => 'str_flooded_grasslands_and_savannas',
		'Montane Grasslands & Shrublands' => 'str_montane_grasslands_and_shrublands', 'Tundra' => 'str_tundra',
		'Mediterranean Forests, Woodlands & Scrub' => 'str_biome_mediterranean_forests',
		'Deserts & Xeric Shrublands' => 'str_deserts_and_xeric_shrublands', 'Mangroves' => 'str_mangroves',
		'Amazon' => 'str_amazon', 'Caatinga' => 'str_caatinga', 'Cerrado' => 'str_cerrado', 'Atlantic Forest' => 'str_atlantic_forest',
		'Pampa' => 'str_pampa', 'Pantanal' => 'str_pantanal',
		'Tree cover' => 'str_tree_cover', 'Shrubland' => 'str_shrubland', 'Grassland' => 'str_grassland', 'Cropland' => 'str_cropland',
		'Built-up' => 'str_built_up', 'Bare / sparse vegetation' => 'str_bare_sparse_vegetation', 'Snow and ice' => 'str_snow_and_ice',
		'Permanent water bodies' => 'str_permanent_water_bodies', 'Herbaceous wetland' => 'str_herbaceous_wetland',
		'Moss and lichen' => 'str_moss_and_lichen',
		'Urban' => 'str_urban', 'Peri-urban' => 'str_peri_urban', 'Natural' => 'str_natural',
	);
}

function t_data($name) {
	$k = data_terms()[(string)$name] ?? '';
	return $k !== '' ? t($k) : (string)$name;
}

// the day of the week (Mon..Sun) of a date (a 'Y-m-d' text or a timestamp) and the month of a timestamp, as keys
function weekday_key($date) {
	return array('str_sun', 'str_mon', 'str_tue', 'str_wed', 'str_thu', 'str_fri', 'str_sat')[intval(date('w', is_int($date) ? $date : strtotime($date)))];
}

function month_key($ts) {
	return array('str_january', 'str_february', 'str_march', 'str_april', 'str_may', 'str_june', 'str_july', 'str_august',
		'str_september', 'str_october', 'str_november', 'str_december')[intval(date('n', $ts)) - 1];
}

// a text without accents and in lower case, for the name searches (2026-10-29: "sabia" finds Sabiá, as the page
// filters do); no intl on the stations, so a table of the Latin letters with marks
function fold_text($s) {
	static $map = null;
	if ($map === null) {
		$map = array();
		foreach (array('a' => 'àáâãäåā', 'c' => 'çćč', 'e' => 'èéêëēė', 'i' => 'ìíîïī', 'n' => 'ñń', 'o' => 'òóôõöøō', 'u' => 'ùúûüū', 'y' => 'ýÿ', 's' => 'śš', 'z' => 'źżž', 'l' => 'ł') as $b => $chars) {
			foreach (preg_split('//u', $chars, -1, PREG_SPLIT_NO_EMPTY) as $c) { $map[$c] = $b; $map[mb_strtoupper($c, 'UTF-8')] = $b; }
		}
	}
	return mb_strtolower(strtr((string)$s, $map), 'UTF-8');
}
