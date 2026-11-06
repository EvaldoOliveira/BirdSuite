<?php
################################################################################
# scripts/lib/themes.php - THEMES: PRESETS, CUSTOM THEMES AND THE COLOURS IN USE
#
# PURPOSE:
#   The themes: presets, custom themes and the colours in use: the helpers of the BirdSuite pages on this
#   subject, moved out of common.php (one file per subject, owner 2026-11-06).
#
# DEPENDENCIES:
#   - scripts/common.php (loads this file after core.php and view_registry.php; every lib may call the others)
#
# USAGE:
#   Loaded by common.php on every page; never requested on its own (404 without BS_PROG).
################################################################################

if (!defined('BS_PROG')) { http_response_code(404); exit; }

// the stylesheet follows the theme in use (owner 2026-10-24: one switch, Light = Forest, Dark = Dark): the dark one for
// the Dark theme, the light one for every other
function get_color_scheme(){
	return theme_name_in_use() === 'dark' ? 'static/dark-style.css' : 'style.css';
}

// App colours (owner 2026-10-09, Settings › Theme): five fixed themes and one custom. The stylesheets and pages use
// var(--bg) page background, var(--menu) side menu and table headers, var(--panel) light panels, var(--accent) buttons,
// links and marks (var(--accent-rgb) for shades) and var(--accent2) the bright buttons, each with the original green as
// fallback; theme_style() sets them for the light colour scheme (the dark scheme keeps its own colours).
// APP_THEME = ocean | dark | forest | blossom | white | red | the id of a custom theme (customN, kept for good).
// A sixth colour, text (owner 2026-10-24): black in the light themes, white in Dark.
function theme_presets() {
	return array(
		// in the owner's order (2026-10-24): Ocean, Dark, Forest, Blossom, White, Red. Ocean is the system default after a
		// clean install (owner 2026-10-24, last word); Sand removed (2026-10-21)
		'ocean' => array('str_ocean', '#7fb3d5', '#a9cce3', '#e3f0fa', '#1f4e79', '#2e86c1', '#000000'),
		// Dark in place of Graphite (owner 2026-10-24): the theme the dark switch puts on the current location; the colours of
		// static/dark-style.css
		'dark' => array('str_dark', '#1a1a1a', '#2b2b2b', '#3b3b3b', '#2b5e22', '#4f7f44', '#ffffff'),
		'forest' => array('str_forest', '#77c487', '#9fe29b', '#dbffeb', '#2b5e22', '#04aa6d', '#000000'),
		'blossom' => array('str_blossom', '#c9a3c7', '#e0c4de', '#f8eef7', '#5e2b5c', '#9c4f96', '#000000'),
		'white' => array('str_white_and_grey', '#ffffff', '#e3e5e8', '#f3f4f6', '#3c4043', '#6b7075', '#000000'),
		'red' => array('str_red', '#e3a5a0', '#f0c6c2', '#fdeeed', '#7b1e1e', '#c0392b', '#000000'),
	);
}

// Each location has its own theme (owner 2026-10-21): 'theme' in locations.json, set in Settings › Locations; empty = the
// station's theme (APP_THEME, Settings › Theme, Ocean by default). Unless another theme was chosen for them, Home is
// Ocean and the test location red.
function theme_valid($name) {
	return isset(theme_presets()[$name]) || custom_theme($name) !== null;
}

function location_theme($l) {
	$name = strtolower(trim((string)($l['theme'] ?? (array('home' => 'ocean', 'test' => 'red')[$l['builtin'] ?? ''] ?? ''))));
	return $name !== '' && theme_valid($name) ? $name : '';
}

// the theme choices of a location's select: the station's theme, the fixed themes, the custom ones
function location_theme_options($cur) {
	$h = function ($x) { return htmlspecialchars((string)$x, ENT_QUOTES); };
	$out = "<option value=''" . ($cur === '' ? ' selected' : '') . '>' . $h(t('str_station_theme')) . '</option>';
	foreach (theme_presets() as $k => $p) $out .= "<option value='$k'" . ($cur === $k ? ' selected' : '') . '>' . $h(t($p[0])) . '</option>';
	foreach (custom_themes_load() as $c) $out .= "<option value='" . $h($c['id']) . "'" . ($cur === $c['id'] ? ' selected' : '') . '>' . $h($c['name']) . '</option>';
	return $out;
}

// Custom themes with a name (owner 2026-10-20; no limit since 2026-10-21), in config/custom_themes.json (station data, outside git):
// [{"id": "custom3", "name": "...", "colors": [bg, menu, panel, accent, accent2, text]}, ...]. The id is given once and
// never reused nor shifted (US-21): APP_THEME and the locations' themes name it, so deleting a theme changes no other
// choice. A theme stored without an id gets custom<its place> (the first save stores it).
function custom_themes_file() { return BS_CONFIG . '/custom_themes.json'; }

function custom_themes_load() {
	static $cache = null;
	if ($cache !== null) return $cache;
	$list = json_decode((string)@file_get_contents(custom_themes_file()), true);
	$out = array(); $used = array();
	foreach (is_array($list) ? $list : array() as $i => $t) {
		$cols = isset($t['colors']) && is_array($t['colors']) ? array_map('strtolower', $t['colors']) : array();
		if (count($cols) !== 6 || count(preg_grep('/^#[0-9a-f]{6}$/', $cols)) !== 6) continue;
		$id = preg_match('/^custom[1-9][0-9]*$/', (string)($t['id'] ?? '')) ? $t['id'] : 'custom' . ($i + 1);
		if (isset($used[$id])) continue;
		$used[$id] = true;
		$out[] = array('id' => $id, 'name' => mb_substr(trim((string)($t['name'] ?? '')) ?: $id, 0, 30), 'colors' => $cols);
	}
	return $cache = $out;
}

// one custom theme by its id, or null
function custom_theme($id) {
	foreach (custom_themes_load() as $t) if ($t['id'] === $id) return $t;
	return null;
}

// the name of the theme in use: the current location's own, else the system default (APP_THEME, Ocean by default)
function theme_name_in_use() {
	$name = strtolower(trim(get_config()['APP_THEME'] ?? 'ocean')); // Ocean is the default theme (owner 2026-10-24)
	$cur = location_current();
	if ($cur && location_theme($cur) !== '') $name = location_theme($cur); // the current location's own theme
	return $name;
}

function theme_colors() {
	$c = get_config();
	$presets = theme_presets();
	$name = theme_name_in_use();
	if (($ct = custom_theme($name)) !== null) return array_merge(array($ct['name']), $ct['colors']);
	return $presets[$name] ?? $presets['ocean'];
}

// black or white text on a colour, whichever contrasts more (owner 2026-10-24: the text of the selected row was not
// readable on every theme). The WCAG rule: relative luminance L of the colour; contrast with white (1.05)/(L+0.05),
// with black (L+0.05)/0.05
function contrast_text($hex) {
	$l = 0;
	foreach (array(0.2126, 0.7152, 0.0722) as $i => $w) {
		$c = hexdec(substr($hex, 1 + 2 * $i, 2)) / 255;
		$l += $w * ($c <= 0.03928 ? $c / 12.92 : pow(($c + 0.055) / 1.055, 2.4));
	}
	return 1.05 / ($l + 0.05) >= ($l + 0.05) / 0.05 ? '#fff' : '#000';
}

function theme_style() {
	// the theme in use; with Dark (the dark stylesheet) the text is white, always (owner 2026-10-24)
	$dark = theme_name_in_use() === 'dark';
	list(, $bg, $menu, $panel, $accent, $accent2, $text) = array_pad(theme_colors(), 7, '#000000');
	if ($dark) $text = '#ffffff';
	$rgb = implode(',', array_map('hexdec', str_split(substr($accent, 1), 2)));
	return "<style id=\"themeVars\">:root{--bg:$bg;--menu:$menu;--panel:$panel;--accent:$accent;--accent-rgb:$rgb;--accent2:$accent2;--text:$text;--on-accent2:" . contrast_text($accent2) . "}</style>";
}
