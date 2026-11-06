<?php
################################################################################
# scripts/species_lists.php - STATION SPECIES LISTS
#
# PURPOSE:
#   Station species lists (Settings > Location > Species list filter): save the active list under a name, or
#   make a station list (or a Brazilian state, built on first use) the active one (owner 2026-10-08). POST
#   only, authenticated. kind=exclude|whitelist|nocturnal: the same for the other lists (save / load, owner
#   2026-10-20). Save asks for replace=1 before it writes over a saved list (EXISTS otherwise). delete (owner
#   2026-11-05): a saved list wrong or no longer wanted leaves the picklist; the file is moved to .deleted/
#   beside it (not lost), never the active Allowed species list.
#
# DEPENDENCIES:
#   - scripts/common.php
#   - scripts/select_species_list.py
#
# USAGE:
#   Referenced by scripts/species_list.php
################################################################################

error_reporting(E_ERROR);
ini_set('display_errors', 0);
require_once __DIR__ . '/common.php';
ensure_authenticated();

$home = get_home();
$dir = BS_CUSTOM_LISTS;
if (!is_dir($dir)) {
	mkdir($dir, 0775, true);
}
$action = $_POST['action'] ?? '';

// a saved list moved out of the picklist into .deleted/ of its folder, under its name and the time
function delete_saved_list($kdir, $name) {
	if (!is_file("$kdir/$name.txt")) die(t('str_no_such_list'));
	if (!is_dir("$kdir/.deleted")) { mkdir("$kdir/.deleted", 0775, true); @chmod("$kdir/.deleted", 0775); }
	if (!rename("$kdir/$name.txt", "$kdir/.deleted/$name-" . date('Ymd-His') . '.txt')) die(t('str_cannot_write_the_list'));
	if (trim((string)@file_get_contents("$kdir/.loaded")) === $name) @unlink("$kdir/.loaded");
	echo t('str_list_deleted', array('name' => $name));
}

// one scientific name per line (owner 2026-11-05, "one index only": a list knows a species by its scientific name, the
// names shown are looked up by the page), without anything a shell or the config could trip on; an older
// "Scientific name_Common name" line is read as the part before the first '_' (as the analysis, loadCustomSpeciesList)
function clean_list($text) {
	$out = array();
	foreach (preg_split('/\r\n|\r|\n/', (string)$text) as $line) {
		$line = trim(explode('_', preg_replace('/[\x00-\x1F\x7F]/', '', $line), 2)[0]);
		if ($line !== '' && $line[0] !== '#') {
			$out[] = $line;
		}
	}
	$out = array_values(array_unique($out));
	sort($out);
	return $out;
}

// Excluded and Whitelisted (owner 2026-10-20): saved under species_lists/exclude|whitelist/<name>.txt; loading copies the
// saved list over the current one (they are plain files, unlike the include list, which is a link chosen by SPECIES_LIST)
$kind = $_POST['kind'] ?? 'include';
if (in_array($kind, array('exclude', 'whitelist', 'nocturnal'), true)) {
	$kdir = "$dir/$kind";
	if (!is_dir($kdir)) { mkdir($kdir, 0775, true); @chmod($kdir, 0775); }
	$current = BS_LISTS . "/{$kind}_species_list.txt";
	$name = $_POST['name'] ?? '';
	if (!preg_match('/^[A-Za-z0-9_-]{1,40}$/', $name)) die(t('str_invalid_name'));
	if ($action === 'save') {
		$lines = clean_list(is_file($current) ? file_get_contents($current) : '');
		if (empty($lines)) die(t('str_the_current_list_is_empty_help'));
		// an existing list only with replace=1 (the page asks first, owner 2026-11-04)
		if (is_file("$kdir/$name.txt") && ($_POST['replace'] ?? '') !== '1') die('EXISTS');
		if (file_put_contents("$kdir/$name.txt", implode("\n", $lines) . "\n") === false) die(t('str_cannot_write_the_list'));
		@chmod("$kdir/$name.txt", 0664);
		@file_put_contents("$kdir/.loaded", $name);  // the table is this list now
		echo t('str_saved_as_species', array('name' => $name, 'n' => count($lines)));
	} elseif ($action === 'activate') {
		if (!is_file("$kdir/$name.txt")) die(t('str_no_such_list'));
		$lines = clean_list(file_get_contents("$kdir/$name.txt"));
		if (file_put_contents($current, implode("\n", $lines) . "\n") === false) die(t('str_cannot_write_the_list'));
		@file_put_contents("$kdir/.loaded", $name); // the name shown as current, even when another saved list is identical
		echo t('str_loaded_species_it_applies_from_help', array('name' => $name, 'n' => count($lines)));
	} elseif ($action === 'delete') {
		delete_saved_list($kdir, $name);
	} else {
		http_response_code(400);
		echo t('str_unknown_action');
	}
	die();
}

if ($action === 'save') {
	$name = $_POST['name'] ?? '';
	if (!preg_match('/^[A-Za-z0-9_-]{1,40}$/', $name)) die(t('str_invalid_name'));
	$active = BS_LISTS . '/include_species_list.txt';
	$lines = clean_list(is_file($active) ? file_get_contents($active) : '');
	if (empty($lines)) die(t('str_the_current_list_is_empty_help'));
	if (is_file("$dir/$name.txt") && ($_POST['replace'] ?? '') !== '1') die('EXISTS');
	if (file_put_contents("$dir/$name.txt", implode("\n", $lines) . "\n") === false) die(t('str_cannot_write_the_list'));
	@chmod("$dir/$name.txt", 0664); // the station user edits it too, like the other lists
	echo t('str_saved_as_species_an_identical_help', array('name' => $name, 'n' => count($lines)));
} elseif ($action === 'activate') {
	// make a station list the active one: SPECIES_LIST in BirdSuite.conf, then link the active list to it
	$name = $_POST['name'] ?? '';
	$state = preg_match('/^BR-[A-Z]{2}$/', $name) && is_file(BS_MODEL . "/include_lists/$name.txt");
	if (!preg_match('/^[A-Za-z0-9_-]{1,40}$/', $name) || (!is_file("$dir/$name.txt") && !$state)) die(t('str_no_such_list'));
	if (!conf_set('SPECIES_LIST', $name, $err)) die($err);
	shell_exec(station_prefix() . 'python3 ' . escapeshellarg(BS_SCRIPTS . '/select_species_list.py') . ' 2>&1');
	echo t('str_is_now_the_active_species_list', array('name' => $name));
} elseif ($action === 'delete') {
	$name = $_POST['name'] ?? '';
	if (!preg_match('/^[A-Za-z0-9_-]{1,40}$/', $name)) die(t('str_invalid_name'));
	// the active list is what Allowed species is: it stays while it is active
	if ($name === trim((string)(get_config()['SPECIES_LIST'] ?? ''), "\"' ")) die(t('str_the_active_list_cannot_be_deleted', array('name' => $name)));
	delete_saved_list($dir, $name);
} else {
	http_response_code(400);
	echo t('str_unknown_action');
}
