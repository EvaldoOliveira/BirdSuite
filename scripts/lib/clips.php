<?php
################################################################################
# scripts/lib/clips.php - CLIPS: NAMES, FOLDERS, SPECTROGRAMS, PURGES AND LOCKS
#
# PURPOSE:
#   The clips: names, folders, spectrograms, purges and locks: the helpers of the BirdSuite pages on this
#   subject, moved out of common.php (one file per subject, owner 2026-11-06).
#
# DEPENDENCIES:
#   - scripts/common.php (loads this file after core.php and view_registry.php; every lib may call the others)
#
# USAGE:
#   Loaded by common.php on every page; never requested on its own (404 without BS_PROG).
################################################################################

if (!defined('BS_PROG')) { http_response_code(404); exit; }

// Species folders and clip names by the scientific name only (owner 2026-10-24: the common name depends on the language,
// so changing the language split a species in two). A clip is By_Date/<date>/<Sci_safe>/<Sci_safe>-<date>_<time>-...
// The same rule in scripts/utils/helpers.py (species_folder).
function name_safe($name) {
	return str_replace("'", '', str_replace(' ', '_', (string)$name));
}

function species_folder($sci) {
	return name_safe($sci);
}

// the clip's name without its species ('-2026-10-01_06h00m01s-...'), as helpers.py clip_rest; '' when it has no date
function clip_rest($file) {
	return preg_match('/-(\d{1,3}-)?\d{4}-\d{2}-\d{2}[-_]/', (string)$file, $m, PREG_OFFSET_CAPTURE) ? substr((string)$file, $m[0][1]) : '';
}

// a detection's clip under By_Date (or Purge / Removed): <date>/<folder>/<file>; $r has Date, Sci_Name, File_Name
function clip_rel($r) {
	return $r['Date'] . '/' . species_folder($r['Sci_Name']) . '/' . $r['File_Name'];
}

// The spectrogram of a clip (owner 2026-10-22): <clip>.png while the day runs, <clip>.jpg once spectro_archive.py
// stored it for the long term. $clip = the clip's web path under Extracted ('/By_Date/...' or 'By_Date/...').
function clip_spectrogram($clip) {
	$disk = BS_EXTRACTED . '/' . ltrim($clip, '/');
	return (!is_file($disk . '.png') && is_file($disk . '.jpg')) ? $clip . '.jpg' : $clip . '.png';
}

// The small copy of that picture for a card drawn under 500 px wide (owner 2026-11-06, Gitea #78,
// scripts/spectrogram_thumb.php: a JPEG 480 px wide, made once); the player and the share keep the full picture.
// v = the time of the picture, so a picture drawn again is fetched again despite the week the browser keeps it.
function clip_spectrogram_thumb($clip) {
	$spec = clip_spectrogram($clip);
	$v = @filemtime(BS_EXTRACTED . '/' . ltrim($spec, '/'));
	return '/scripts/spectrogram_thumb.php?clip=' . rawurlencode('/' . ltrim($spec, '/')) . ($v ? '&v=' . $v : '');
}

// Removed detections ("Remove detection", owner 2026-10-09): files in the Removed folder of the clips, Removed/<date>/<species>/,
// lines in deleted_detections, until they are deleted by hand in Species › Purge Files
// The review causes whose audio Purge Files may remove while the detection keeps counting (owner 2026-10-22)
const PURGE_CAUSES = array('human', 'noise', 'ignore');

// The clips protected from every purge (config/scripts/disk_check_exclude.txt: <date>/<species>/<file> lines, the
// automatic top N and confirmed ones plus the manual locks) as a set
function locked_clips() {
	static $set = null;
	if ($set === null) $set = array_flip(array_map('trim', @file(BS_DISK_EXCLUDE, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: array()));
	return $set;
}

// Detections of a query whose clip is still on disk under Extracted/<base> (Purge Files lists only what can still be
// purged: By_Date for Others, Purge for the causes staged by purge_stage.py), as
// [key => [com, sci, cause, n]]; the rows carry Date, File_Name, Sci_Name and optionally Reason
function purge_rows($res, $keep = null, $base = 'By_Date') {
	$home = get_home(); $out = array();
	while ($res && ($r = $res->fetchArray(SQLITE3_ASSOC))) {
		if ($keep && !$keep($r)) continue;
		$f = BS_EXTRACTED . '/' . $base . '/' . $r['Date'] . '/' . species_folder($r['Sci_Name']) . '/' . $r['File_Name'];
		if (!file_exists($f)) continue;
		$k = ($r['Reason'] ?? '') . '|' . $r['Sci_Name'];
		if (!isset($out[$k])) $out[$k] = array('com' => common_name($r['Sci_Name']), 'sci' => $r['Sci_Name'], 'cause' => $r['Reason'] ?? '', 'n' => 0);
		$out[$k]['n']++;
	}
	uasort($out, function ($a, $b) { return $b['n'] - $a['n']; });
	return $out;
}

// The audio waiting to be purged of the detections a location keeps counting (owner 2026-10-24, the Ignored column of
// Tools › Data): answered Human, Noise or Ignore (moved to Extracted/Purge at night) and the excluded
// species (in By_Date; not the clips answered Yes nor the protected ones). $lw = an SQL condition on detections or ''.
function purge_audio_rows($db, $lw = '') {
	$and = $lw !== '' ? ' AND ' . $lw : '';
	$out = array('causes' => array(), 'others' => array());
	$out['causes'] = purge_rows($db->query("SELECT d.Date, d.File_Name, d.Sci_Name, r.Reason FROM main.detections d JOIN detection_reviews r ON r.File_Name = d.File_Name
    WHERE r.Verdict = 'no' AND r.Reason IN ('" . implode("','", PURGE_CAUSES) . "')" . $and), null, 'Purge');
	$excl = excluded_species();
	$excl_found = $excl ? excluded_with_detections($db, $excl) : '';
	if ($excl_found) {
		$locked = locked_clips();
		$out['others'] = purge_rows($db->query("SELECT Date, File_Name, Sci_Name FROM main.detections WHERE Sci_Name IN ("
			. $excl_found . ") AND File_Name NOT IN (SELECT File_Name FROM detection_reviews WHERE Verdict = 'yes')" . $and),
			function ($r) use ($excl, $locked) { return isset($excl[$r['Sci_Name']]) && !isset($locked[$r['Date'] . '/' . species_folder($r['Sci_Name']) . '/' . $r['File_Name']]); });
	}
	return $out;
}

function deleted_dir() {
	return BS_REMOVED;
}

// how many deleted detections wait for a wipe: one species, or every species when $sci is null
function deleted_count($sci = null) {
	$db = get_db();
	if ($sci === null) return intval($db->querySingle('SELECT COUNT(*) FROM deleted_detections'));
	$st = $db->prepare('SELECT COUNT(*) FROM deleted_detections WHERE Sci_Name = :s');
	$st->bindValue(':s', $sci);
	$r = $st->execute()->fetchArray(SQLITE3_NUM);
	return intval($r[0]);
}
