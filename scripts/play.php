<?php
################################################################################
# scripts/play.php - THE DETECTION ACTIONS: ENDPOINTS OF THE CLIPS, THE REVIEW AND THE LISTS
#
# PURPOSE:
#   The endpoints behind every detection on every page: delete, delete species, purge audio, restore, wipe,
#   exclude, the review verdicts and batches, the review worker, the alternatives, the spectrogram, the
#   labels, rename, lowered clips. Every action that changes something comes by POST. The page of one
#   detection (?filename=, a shared link or a notification) is scripts/detection.php, included at the end
#   (owner 2026-11-06: it lived here).
#
# DEPENDENCIES:
#   - scripts/common.php, scripts/sanitize_input.php
#   - static/detection-actions.js (the caller of most endpoints), scripts/review_worker.py
#
# USAGE:
#   play.php?<action>=... (see the endpoints below); play.php?filename=<clip> shows the detection
################################################################################

/* Prevent XSS input */
require_once 'scripts/sanitize_input.php';
$_GET   = bs_sanitize_input(INPUT_GET);
$_POST  = bs_sanitize_input(INPUT_POST);

error_reporting(E_ERROR);
ini_set('display_errors', 0);  // errors to the log, never to the page (2026-10-23 security run)
require_once 'scripts/common.php';
$home = get_home();
$config = get_config();
$user = get_user();

$db = new SQLite3(BS_DB, SQLITE3_OPEN_READONLY);
$db->busyTimeout(1000);

// the actions that change something come by POST only (2026-11-02): a link or an image tag of another page can not make
// one; the wipe count (without confirm) and the reads stay GET
foreach (array('deletefile', 'deletespecies', 'purgeaudio', 'restore', 'excludefile', 'review_batch', 'changefile') as $a) {
	if (isset($_GET[$a]) && $_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); die(); }
}
if (((isset($_GET['wipe']) && isset($_GET['confirm'])) || (isset($_GET['review']) && isset($_GET['verdict']))) && $_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); die(); }
// a removal or a restore changes which clips each location holds: the formats of Tools › Data are counted again
// (location_data.py formats keeps them in state/location_formats.json)
if (isset($_GET['deletefile']) || isset($_GET['deletespecies']) || isset($_GET['restore'])) {
	register_shutdown_function(function () { exec(station_prefix() . 'rm -f ' . escapeshellarg(BS_STATE . '/location_formats.json')); });
}

// Moves files (full paths) into the folder $dst as the station user: one mv per folder in chunks of 200, as the purges
// remove them (owner 2026-11-06, Gitea #70: one mv per file was slow). Returns the files that moved (gone from where
// they were and now in $dst); the others did not.
function move_files_to($files, $dst) {
	$moved = array();
	if (!$files) return $moved;
	exec(station_prefix() . 'mkdir -p ' . escapeshellarg($dst) . ' 2>&1');
	foreach (array_chunk($files, 200) as $chunk) {
		$out = array();
		exec(station_prefix() . 'mv -f -t ' . escapeshellarg($dst) . ' ' . implode(' ', array_map('escapeshellarg', $chunk)) . ' 2>&1', $out);
		clearstatcache();
		foreach ($chunk as $f) if (!file_exists($f) && file_exists($dst . '/' . basename($f))) $moved[] = $f;
	}
	return $moved;
}
// puts the files move_files_to() moved into $dst back where they were
function move_files_back($moved, $dst) {
	$by_dir = array();
	foreach ($moved as $f) $by_dir[dirname($f)][] = $dst . '/' . basename($f);
	foreach ($by_dir as $dir => $files) move_files_to($files, $dir);
}
// the clip and its spectrograms that exist
function clip_files($clip) {
	return array_values(array_filter(array($clip, $clip . '.png', $clip . '.jpg'), 'file_exists'));
}
// A clip staged for purge at night (Extracted/Purge, scripts/purge_stage.py) goes back to By_Date with its
// spectrograms; false when it is still staged
function unstage_clip($date, $sci, $file) {
	$rel = $date . '/' . species_folder($sci);
	$src = BS_EXTRACTED . '/Purge/' . $rel . '/' . $file;
	if (!file_exists($src)) return true;
	$moved = move_files_to(clip_files($src), BS_BY_DATE . '/' . $rel);
	return in_array($src, $moved, true);
}
// Adds or removes a species of the Confirmed list of Species Management (confirmed_species_list.txt) under a lock held
// over the whole read and write (owner 2026-11-06, Gitea #69: two answers at once could lose a line, and a failed write
// went unnoticed). $only_if, when given, is asked under the lock (the last Yes of the species). True when the list is
// as asked.
function confirmed_list_set($sci, $add, $only_if = null) {
	if ($sci === '' || $sci === null) return true;
	$fh = @fopen(BS_LISTS . '/confirmed_species_list.txt', 'c+');
	if (!$fh) return false;
	if (!flock($fh, LOCK_EX)) { fclose($fh); return false; }
	$lines = array_values(array_filter(array_map('trim', explode("\n", (string)stream_get_contents($fh))), 'strlen'));
	$ok = true;
	if ($add !== in_array($sci, $lines, true) && ($only_if === null || $only_if())) {
		if ($add) $lines[] = $sci;
		else $lines = array_values(array_diff($lines, array($sci)));
		sort($lines, SORT_STRING);
		$text = $lines ? implode("\n", $lines) . "\n" : '';
		$ok = rewind($fh) && ftruncate($fh, 0) && fwrite($fh, $text) === strlen($text) && fflush($fh);
	}
	flock($fh, LOCK_UN);
	fclose($fh);
	return $ok;
}

// Delete = remove detection (owner 2026-10-09): the clip and its spectrogram leave the BirdNET folders for
// the Removed folder of the clips, Removed/<date>/<species>/, the database line goes to deleted_detections (out of every statistic);
// nothing is lost until it is deleted by hand (Species › Purge Files, or the species page)
if(isset($_GET['deletefile'])) {
	ensure_authenticated(t('str_you_must_delete_files_help'));
	$file = $_GET['deletefile'];
	$parts = explode('/', $file);
	if (strpos($file, '..') !== false || count($parts) !== 3) { echo "Error"; die(); }
	$rw = new SQLite3(BS_DB, SQLITE3_OPEN_READWRITE);
	$rw->busyTimeout(5000);
	$ins = $rw->prepare("INSERT INTO deleted_detections (Date, Time, Sci_Name, Confidence, Lat, Lon, Cutoff, Week, Sens, Overlap, File_Name, Deleted_At, Loc_Thresh, Rec_Length, Sp_Override, Location, Mic, Mic_Port, Mic_Gain, Tz, Utc_Offset, Model, Software, Species_List, Audio_Loss_N, Audio_Loss_Ms, Sample_Rate, Channels, Scenario)
    SELECT Date, Time, Sci_Name, Confidence, Lat, Lon, Cutoff, Week, Sens, Overlap, File_Name, datetime('now', 'localtime'), Loc_Thresh, Rec_Length, Sp_Override, Location, Mic, Mic_Port, Mic_Gain, Tz, Utc_Offset, Model, Software, Species_List, Audio_Loss_N, Audio_Loss_Ms, Sample_Rate, Channels, Scenario FROM detections WHERE File_Name = :f");
	$ins->bindValue(':f', $parts[2]);
	$del = $rw->prepare('DELETE FROM detections WHERE File_Name = :f');
	$del->bindValue(':f', $parts[2]);
	// the database first, in one transaction held while the clip moves (owner 2026-11-06, Gitea #70: the clip moved
	// first and a busy database left the line counting with its clip in Removed): a failed move rolls the line back, a
	// failed write leaves the files where they are; a clip staged for the purge (Extracted/Purge) moves from there, and
	// both spectrograms (.png and the archived .jpg) when both exist
	$clip = review_clip_path($file);
	$files = $clip ? clip_files($clip) : array();
	$dst = deleted_dir() . '/' . $parts[0] . '/' . $parts[1];
	if (!@$rw->exec('BEGIN IMMEDIATE') || $ins->execute() === false || $rw->changes() !== 1 || $del->execute() === false || $rw->changes() !== 1) {
		$msg = $rw->lastErrorMsg();
		@$rw->exec('ROLLBACK');
		echo 'Error - ' . t('str_database_line_not_moved', array('msg' => $msg));
	} elseif (count($moved = move_files_to($files, $dst)) !== count($files)) {
		move_files_back($moved, $dst);
		@$rw->exec('ROLLBACK');
		echo 'Error - ' . t('str_file_not_moved', array('msg' => implode(', ', array_map('basename', array_diff($files, $moved)))));
	} elseif (!@$rw->exec('COMMIT')) {
		$msg = $rw->lastErrorMsg();
		move_files_back($moved, $dst);
		@$rw->exec('ROLLBACK');
		echo 'Error - ' . t('str_database_line_not_moved', array('msg' => $msg));
	} else {
		exec(station_prefix() . 'rm -f ' . escapeshellarg(BS_BY_DATE . '/shifted/' . $file) . ' 2>&1');
		echo "OK";
	}
	$rw->close();
	die();
}

// Remove a whole species (owner 2026-10-19, Curation's Remove all): ?deletespecies=<scientific name> moves every clip
// and spectrogram of the species to the Removed folder of the clips, Removed/<date>/<species>/ (one move per day folder) and its
// database lines to deleted_detections, like Delete does for one detection; ?restore=1&sci=<name> brings them back.
// Answers OK <count>.
if (isset($_GET['deletespecies'])) {
	ensure_authenticated(t('str_you_must_delete_files_help'));
	$sci = html_entity_decode($_GET['deletespecies'], ENT_QUOTES);
	if ($sci === '' || strpos($sci, '/') !== false || strpos($sci, '..') !== false) { echo "Error"; die(); }
	$rw = new SQLite3(BS_DB, SQLITE3_OPEN_READWRITE);
	$rw->busyTimeout(5000);
	// only the location the pages show, as the count the dialog gave (with several locations a day folder can hold
	// clips of more than one place, so the clips move one by one)
	$loc = (count(locations_load()) >= 2 && ($l = location_shown()) !== null) ? ' AND ' . location_sql($l) : '';
	// Files first, then the database (owner 2026-11-06, Gitea #70): the files of a whole species can be thousands, too many
	// to move while the transaction holds the database the analysis writes to, so they move first (one mv per folder) and
	// go back when a move fails or the database does not take the change; the lines move in one transaction, only those
	// read now (a detection the analysis adds meanwhile is not removed without its clip). Staged clips (Extracted/Purge)
	// move too.
	$max = intval($rw->querySingle('SELECT MAX(rowid) FROM detections'));
	$cond = "Sci_Name = :s AND rowid <= $max$loc";
	$groups = array();  // the Removed folder => the files that go there
	$cleanup = array(); // emptied folders and the shifted copies, removed once the database took the change
	if ($loc !== '') {
		$st = $rw->prepare("SELECT Date, Sci_Name, File_Name FROM detections WHERE $cond");
		$st->bindValue(':s', $sci);
		$res = $st->execute();
		while ($res && ($r = $res->fetchArray(SQLITE3_ASSOC))) {
			$rel = $r['Date'] . '/' . species_folder($r['Sci_Name']);
			$clip = review_clip_path($rel . '/' . $r['File_Name']);
			if ($clip) foreach (clip_files($clip) as $f) $groups[deleted_dir() . '/' . $rel][] = $f;
			$cleanup[] = BS_BY_DATE . '/shifted/' . $rel . '/' . $r['File_Name'];
		}
	} else {
		$st = $rw->prepare("SELECT DISTINCT Date FROM detections WHERE $cond");
		$st->bindValue(':s', $sci);
		$res = $st->execute();
		// a day's folder of the species (scientific name), in By_Date and staged for the purge
		while ($res && ($r = $res->fetchArray(SQLITE3_ASSOC))) {
			$rel = $r['Date'] . '/' . name_safe($sci);
			foreach (array(BS_BY_DATE, BS_EXTRACTED . '/Purge') as $root) {
				$dir = $root . '/' . $rel;
				if (!is_dir($dir)) continue;
				foreach (array_diff(scandir($dir) ?: array(), array('.', '..')) as $n) if (is_file($dir . '/' . $n)) $groups[deleted_dir() . '/' . $rel][] = $dir . '/' . $n;
			}
			$cleanup[] = BS_BY_DATE . '/shifted/' . $rel;
		}
	}
	if ($res) $res->finalize();
	$moved = array();
	$failed = array();
	foreach ($groups as $dst => $files) {
		$moved[$dst] = move_files_to($files, $dst);
		foreach (array_diff($files, $moved[$dst]) as $f) $failed[] = basename($f);
	}
	$undo = function () use ($moved) { foreach ($moved as $dst => $m) move_files_back($m, $dst); };
	if ($failed) {
		$undo();
		echo 'Error - ' . t('str_files_not_moved', array('msg' => implode(', ', array_slice($failed, 0, 5))));
		$rw->close();
		die();
	}
	$ins = $rw->prepare("INSERT INTO deleted_detections (Date, Time, Sci_Name, Confidence, Lat, Lon, Cutoff, Week, Sens, Overlap, File_Name, Deleted_At, Loc_Thresh, Rec_Length, Sp_Override, Location, Mic, Mic_Port, Mic_Gain, Tz, Utc_Offset, Model, Software, Species_List, Audio_Loss_N, Audio_Loss_Ms, Sample_Rate, Channels, Scenario)
    SELECT Date, Time, Sci_Name, Confidence, Lat, Lon, Cutoff, Week, Sens, Overlap, File_Name, datetime('now', 'localtime'), Loc_Thresh, Rec_Length, Sp_Override, Location, Mic, Mic_Port, Mic_Gain, Tz, Utc_Offset, Model, Software, Species_List, Audio_Loss_N, Audio_Loss_Ms, Sample_Rate, Channels, Scenario FROM detections WHERE $cond");
	$ins->bindValue(':s', $sci);
	$del = $rw->prepare("DELETE FROM detections WHERE $cond");
	$del->bindValue(':s', $sci);
	$n = -1;
	if (!@$rw->exec('BEGIN IMMEDIATE') || $ins->execute() === false || ($n = $rw->changes()) < 0 || $del->execute() === false
			|| $rw->changes() !== $n || !@$rw->exec('COMMIT')) {
		$msg = $rw->lastErrorMsg();
		@$rw->exec('ROLLBACK');
		$undo();
		echo 'Error - ' . t('str_database_lines_not_moved', array('msg' => $msg));
	} else {
		// the emptied day folders go (as before), the others stay
		$dirs = array();
		foreach ($moved as $m) foreach ($m as $f) $dirs[dirname($f)] = 1;
		foreach (array_chunk(array_keys($dirs), 200) as $chunk) exec(station_prefix() . 'rmdir ' . implode(' ', array_map('escapeshellarg', $chunk)) . ' 2>/dev/null');
		foreach (array_chunk($cleanup, 200) as $chunk) exec(station_prefix() . 'rm -rf ' . implode(' ', array_map('escapeshellarg', $chunk)) . ' 2>&1');
		echo "OK " . $n;
	}
	$rw->close();
	die();
}

// Purge the audio of the detections not worth keeping (owner 2026-10-22, Species › Purge Files): the database line stays,
// so every statistic keeps counting it; only the clip and its spectrograms leave the storage.
// ?purgeaudio=causes [&reason=<cause>] [&sci=<name>] = answered "not this bird" with Human, Noise or Ignore;
// ?purgeaudio=others [&sci=<name>] = the excluded species. Every location, or &loc=<id>. Answers OK <files removed>.
if (isset($_GET['purgeaudio'])) {
	ensure_authenticated(t('str_you_must_delete_files_help'));
	$kind = $_GET['purgeaudio'] === 'others' ? 'others' : 'causes';
	$sci = html_entity_decode((string)($_GET['sci'] ?? ''), ENT_QUOTES);
	$reason = (string)($_GET['reason'] ?? '');
	// &loc=<id>: only that location's detections (owner 2026-10-24, Purge Ignored in Tools › Data)
	$ploc = (ctype_digit((string)($_GET['loc'] ?? '')) && ($pl = location_by_id($_GET['loc']))) ? ' AND ' . location_sql($pl) : '';
	$db_r = new SQLite3(BS_DB, SQLITE3_OPEN_READONLY);
	$db_r->busyTimeout(5000);
	$files = array();
	if ($kind === 'causes') {
		$causes = in_array($reason, PURGE_CAUSES, true) ? array($reason) : PURGE_CAUSES;
		$st = $db_r->prepare("SELECT d.Date, d.Sci_Name, d.File_Name FROM main.detections d JOIN detection_reviews r ON r.File_Name = d.File_Name
      WHERE r.Verdict = 'no' AND r.Reason IN ('" . implode("','", $causes) . "')" . ($sci !== '' ? ' AND d.Sci_Name = :s' : '') . $ploc);
		if ($sci !== '') $st->bindValue(':s', $sci);
		$res = $st->execute();
	} else {
		$excl = excluded_species();
		if ($sci !== '' && !isset($excl[$sci])) die('Error - ' . t('str_not_an_excluded_species'));
		// the clips answered Yes and the protected ones (locks, top N) are kept (owner 2026-10-22)
		// only the excluded species that have detections are read, as Purge Files lists them (2026-10-23 test run: every
		// detection went to PHP)
		$excl_found = $excl ? excluded_with_detections($db_r, $excl) : '';
		$res = $excl_found !== '' ? $db_r->query("SELECT Date, File_Name, Sci_Name FROM main.detections WHERE Sci_Name IN ("
			. $excl_found . ") AND File_Name NOT IN (SELECT File_Name FROM detection_reviews WHERE Verdict = 'yes')" . $ploc) : false;
	}
	// the protected clips (locks, top N) are kept by both kinds (owner 2026-11-06, Gitea #71: the causes removed a locked
	// clip; purge_stage.py never stages one, but a lock set after the staging was not seen)
	$locked = locked_clips();
	while ($res && ($r = $res->fetchArray(SQLITE3_ASSOC))) {
		if (isset($locked[$r['Date'] . '/' . species_folder($r['Sci_Name']) . '/' . $r['File_Name']])) continue;
		if ($kind === 'others' && (!isset($excl[$r['Sci_Name']]) || ($sci !== '' && $r['Sci_Name'] !== $sci))) continue;
		// the causes are deleted from Extracted/Purge (staged at night by purge_stage.py), the excluded species from By_Date
		$src = BS_EXTRACTED . '/' . ($kind === 'causes' ? 'Purge' : 'By_Date') . '/' . $r['Date'] . '/' . species_folder($r['Sci_Name']) . '/' . $r['File_Name'];
		foreach (array($src, $src . '.png', $src . '.jpg') as $f) if (file_exists($f)) $files[] = $f;
	}
	$db_r->close();
	$n = 0; $errors = array();
	foreach (array_chunk($files, 200) as $chunk) {
		$out = array(); $rc = 0;
		exec(station_prefix() . 'rm -f ' . implode(' ', array_map('escapeshellarg', $chunk)) . ' 2>&1', $out, $rc);
		foreach ($chunk as $f) { if (file_exists($f)) $errors[] = basename($f); else $n++; }
	}
	echo $errors ? 'Error - ' . t('str_files_not_removed', array('msg' => implode(', ', array_slice($errors, 0, 5)))) : 'OK ' . $n;
	die();
}

// Restore removed detections (owner 2026-10-09): ?restore=1 [&sci=<scientific name>] [&file=<file name>] moves the files
// back to By_Date and the database lines back to detections; every species when both are empty (file = the review
// player's Undo). Answers OK <count>.
// Purge Files per location (owner 2026-10-21): &loc=<id> limits restore / wipe to that location's removed detections,
// &loc=none to those outside every location; no loc = all of them. Returns '' or an SQL condition.
// Outside every location = the location was deleted, or the line has none (run 5 RV2, PUR-01: 225 removed detections of
// a deleted location were shown nowhere); NOT (Location = 1 OR ...) left out the lines without a Location
function removed_loc_sql($loc) {
	if ($loc === 'none') {
		$all = array_map(function ($l) { return '(' . location_sql($l) . ')'; }, locations_load());
		return $all ? '(Location IS NULL OR NOT (' . implode(' OR ', $all) . '))' : '';
	}
	if (ctype_digit((string)$loc) && ($l = location_by_id($loc))) return location_sql($l);
	return '';
}

if (isset($_GET['restore'])) {
	ensure_authenticated(t('str_you_must_restore_detections_help'));
	$rw = new SQLite3(BS_DB, SQLITE3_OPEN_READWRITE);
	$rw->busyTimeout(5000);
	$sci = html_entity_decode($_GET['sci'] ?? '', ENT_QUOTES);
	$one = basename($_GET['file'] ?? '');
	$where = $one !== '' ? ' WHERE File_Name = :f' : ($sci !== '' ? ' WHERE Sci_Name = :s' : '');
	$lw = $one !== '' ? '' : removed_loc_sql($_GET['loc'] ?? '');
	if ($lw !== '') $where .= ($where === '' ? ' WHERE ' : ' AND ') . $lw;
	$st = $rw->prepare("SELECT Date, Sci_Name, File_Name FROM deleted_detections$where");
	if ($one !== '') $st->bindValue(':f', $one);
	elseif ($sci !== '') $st->bindValue(':s', $sci);
	// the list read in full first (2026-10-23 run 3: writing while this SELECT was still open made every write fail once the
	// analysis had committed, the files moved and the count grew anyway: 2,192 answered, 63 restored); then each detection
	// in its own transaction, its clip moved only when the database took it, and only those counted
	$res = $st->execute();
	$rows = array();
	while ($res && ($r = $res->fetchArray(SQLITE3_ASSOC))) $rows[] = $r;
	if ($res) $res->finalize();
	// the removed detections of a deleted location (loc=none) cannot go back where they were: they are restored into the
	// current location, filed there as Move to location files them (location_data.py reassign: its coordinates and id;
	// run 5 RV2, PUR-03); every other restore keeps the detection's own location
	$into = ($_GET['loc'] ?? '') === 'none' && $one === '' ? location_current() : null;
	$place = $into ? ':lat, :lon' : 'Lat, Lon';
	$place_id = $into ? ':loc' : 'Location';
	// the two statements prepared once, not per detection (owner 2026-11-06, Gitea #70)
	$ins = $rw->prepare("INSERT INTO detections (Date, Time, Sci_Name, Confidence, Lat, Lon, Cutoff, Week, Sens, Overlap, File_Name, Loc_Thresh, Rec_Length, Sp_Override, Location, Mic, Mic_Port, Mic_Gain, Tz, Utc_Offset, Model, Software, Species_List, Audio_Loss_N, Audio_Loss_Ms, Sample_Rate, Channels, Scenario)
    SELECT Date, Time, Sci_Name, Confidence, $place, Cutoff, Week, Sens, Overlap, File_Name, Loc_Thresh, Rec_Length, Sp_Override, $place_id, Mic, Mic_Port, Mic_Gain, Tz, Utc_Offset, Model, Software, Species_List, Audio_Loss_N, Audio_Loss_Ms, Sample_Rate, Channels, Scenario FROM deleted_detections WHERE File_Name = :f
    AND NOT EXISTS (SELECT 1 FROM detections WHERE File_Name = :f)");
	$del = $rw->prepare('DELETE FROM deleted_detections WHERE File_Name = :f');
	if (!$ins || !$del) { echo 'Error - ' . t('str_database_lines_not_moved', array('msg' => $rw->lastErrorMsg())); $rw->close(); die(); }
	if ($into) {
		$ins->bindValue(':lat', floatval($into['lat']), SQLITE3_FLOAT);
		$ins->bindValue(':lon', floatval($into['lon']), SQLITE3_FLOAT);
		$ins->bindValue(':loc', intval($into['id']), SQLITE3_INTEGER);
	}
	// the clips of the detections the database took, by the By_Date folder they go back to (one mv per folder)
	$groups = array();
	$n = 0;
	foreach ($rows as $r) {
		if (!@$rw->exec('BEGIN IMMEDIATE')) continue;  // busy beyond the timeout: left in the list, not counted
		$ins->bindValue(':f', $r['File_Name']);
		$del->bindValue(':f', $r['File_Name']);
		$ok = $ins->execute() !== false && $del->execute() !== false && @$rw->exec('COMMIT');
		$ins->reset();
		$del->reset();
		if ($ok) {
			$rel = $r['Date'] . '/' . species_folder($r['Sci_Name']);
			$src = deleted_dir() . '/' . $rel . '/' . $r['File_Name'];
			// a detection without its clip in Removed has nothing to move: restored all the same (as before)
			if (file_exists($src)) $groups[BS_BY_DATE . '/' . $rel][$src] = clip_files($src);
			else $n++;
		} else {
			@$rw->exec('ROLLBACK');
		}
	}
	// a detection whose clip did not move back is not counted (its line is in detections, its clip still in Removed): the
	// answer says "OK <moved> of <asked>"
	foreach ($groups as $dst => $clips) {
		$moved = move_files_to(array_merge(...array_values($clips)), $dst);
		foreach (array_keys($clips) as $src) if (in_array($src, $moved, true)) $n++;
	}
	exec(station_prefix() . 'find ' . escapeshellarg(deleted_dir()) . ' -mindepth 1 -type d -empty -delete 2>&1');
	$rw->close();
	echo 'OK ' . $n . (count($rows) > $n ? ' of ' . count($rows) : '');
	die();
}

// Delete the removed detections for good (owner 2026-10-09): ?wipe=1 [&sci=<scientific name>] answers the files per species (JSON);
// with &confirm=1 the files and their database lines are removed for good. Every species when sci is empty.
if (isset($_GET['wipe'])) {
	ensure_authenticated(t('str_you_must_wipe_deleted_help'));
	$rw = new SQLite3(BS_DB, SQLITE3_OPEN_READWRITE);
	$rw->busyTimeout(5000);
	db_names($rw); // com_name() for the per-species count
	$sci = html_entity_decode($_GET['sci'] ?? '', ENT_QUOTES);
	$where = $sci !== '' ? ' WHERE Sci_Name = :s' : '';
	$lw = removed_loc_sql($_GET['loc'] ?? '');
	if ($lw !== '') $where .= ($where === '' ? ' WHERE ' : ' AND ') . $lw;
	if (!isset($_GET['confirm'])) {
		header('Content-Type: application/json');
		$st = $rw->prepare("SELECT Sci_Name, com_name(Sci_Name) AS Com_Name, COUNT(*) AS n FROM deleted_detections$where GROUP BY Sci_Name ORDER BY n DESC");
		if ($sci !== '') $st->bindValue(':s', $sci);
		$res = $st->execute();
		$rows = array();
		while ($res && ($r = $res->fetchArray(SQLITE3_ASSOC))) $rows[] = $r;
		echo json_encode($rows);
		die();
	}
	// the database lines first, in one transaction, and the files only once it took them (owner 2026-11-06, Gitea #70:
	// the files went first and a failed delete kept the lines, unrestorable without their clips); the list is read inside
	// the transaction, so it is exactly the lines deleted
	if (!@$rw->exec('BEGIN IMMEDIATE')) { echo 'Error - ' . t('str_database_lines_not_deleted', array('msg' => $rw->lastErrorMsg())); $rw->close(); die(); }
	$st = $rw->prepare("SELECT Date, Sci_Name, File_Name FROM deleted_detections$where");
	if ($sci !== '') $st->bindValue(':s', $sci);
	$res = $st->execute();
	$paths = array();
	$names = array();
	while ($res && ($r = $res->fetchArray(SQLITE3_ASSOC))) {
		$p = deleted_dir() . '/' . $r['Date'] . '/' . species_folder($r['Sci_Name']) . '/' . $r['File_Name'];
		$paths[] = $p;
		$paths[] = $p . '.png';
		$paths[] = $p . '.jpg';
		$names[] = $r['File_Name'];
	}
	if ($res) $res->finalize();
	$d = $rw->prepare("DELETE FROM deleted_detections$where");
	if ($d && $sci !== '') $d->bindValue(':s', $sci);
	$ok = $res !== false && $d && $d->execute() !== false;
	$wiped = $rw->changes();
	// their reviews go too (the detections no longer exist)
	foreach (array_chunk($names, 400) as $chunk) {
		if (!$ok) break;
		$ok = $rw->exec("DELETE FROM detection_reviews WHERE File_Name IN ('" . implode("','", array_map('SQLite3::escapeString', $chunk)) . "')");
	}
	if (!$ok || !@$rw->exec('COMMIT')) {
		$msg = $rw->lastErrorMsg();
		@$rw->exec('ROLLBACK');
		$rw->close();
		echo 'Error - ' . t('str_database_lines_not_deleted', array('msg' => $msg));
		die();
	}
	$rw->close();
	foreach (array_chunk($paths, 200) as $chunk) {
		exec(station_prefix() . 'rm -f ' . implode(' ', array_map('escapeshellarg', $chunk)) . ' 2>&1');
	}
	exec(station_prefix() . 'find ' . escapeshellarg(deleted_dir()) . ' -mindepth 1 -type d -empty -delete 2>&1');
	echo 'OK ' . $wiped;
	die();
}

if(isset($_GET['excludefile'])) {
	ensure_authenticated(t('str_you_must_change_the_protection_help'));
	if(!file_exists(BS_DISK_EXCLUDE)) {
		file_put_contents(BS_DISK_EXCLUDE, "##start\n##end\n");
	}
	// shared with purge_protection.py and the purge scripts: never edit the list while they use it
	// in the station's state/ (2026-10-23 test run: in /tmp, with protected_regular, the web server could not open the
	// lock the station user had created, and a fixed /tmp name can be planted); opened read-only, flock needs no more
	$purge_lock = @fopen(BS_STATE . '/purge.lock', 'r') ?: @fopen(BS_STATE . '/purge.lock', 'c');
	if ($purge_lock) flock($purge_lock, LOCK_EX);
	// one clip, <date>/<species folder>/<file> (owner 2026-11-06, Gitea #71: an empty or partial value unlocked every line
	// containing it, a value with a newline added lines of its own)
	$txt = html_entity_decode((string)$_GET['excludefile'], ENT_QUOTES);
	if (strpos($txt, '..') !== false || !preg_match('~^\d{4}-\d{2}-\d{2}/[^/\r\n]+/[^/\r\n]+$~D', $txt)) { echo "Error"; die(); }
	if(isset($_GET['exclude_add'])) {
		$myfile = fopen(BS_DISK_EXCLUDE, "a") or die(t('str_unable_to_open_file'));
		$add = $txt . "\n" . $txt . ".png\n" . $txt . ".jpg\n";
		$ok = fwrite($myfile, $add) === strlen($add);
		fclose($myfile);
		echo $ok ? "OK" : 'Error - ' . t('str_unable_to_open_file');
		die();
	} else {
		$lines  = file(BS_DISK_EXCLUDE);
		if ($lines === false) { echo 'Error - ' . t('str_unable_to_open_file'); die(); }
		// only this clip's own lines (##start / ##end and every other clip stay)
		$mine = array($txt, $txt . '.png', $txt . '.jpg');
		$result = '';
		foreach($lines as $line) {
			if (!in_array(trim($line), $mine, true)) {
				$result .= $line;
			}
		}
		echo file_put_contents(BS_DISK_EXCLUDE, $result) !== false ? "OK" : 'Error - ' . t('str_unable_to_open_file');
		die();
	}
}

// Review loop: one verdict per detection — yes (confirmed: protected from purge), no (not this bird: left out of
// the best detections and the species page counts), unsure; "clear" removes it
if(isset($_GET['review']) && isset($_GET['verdict'])) {
	ensure_role('operator', t('str_you_must_review_detections_help'));  // a household device on the LAN reviews without the login (US-53)
	$file_name = basename($_GET['review']);
	$verdict = $_GET['verdict'];
	if (!in_array($verdict, array('yes', 'no', 'unsure', 'clear'), true)) { echo "Error"; die(); }
	$rw = new SQLite3(BS_DB, SQLITE3_OPEN_READWRITE);
	$rw->busyTimeout(5000);
	// why a detection is "not this bird": human, noise or ignore (none = another species, Change species)
	$reason = in_array($_GET['reason'] ?? '', PURGE_CAUSES, true) ? $_GET['reason'] : '';
	// the answer it had (owner 2026-11-06, Gitea #69): clear on a detection that came from Change species keeps its row,
	// the only place of the species the model gave and its confidence, back as Change species wrote it (Yes, 'changed');
	// clear of a Yes takes the species off the Confirmed list when it was its last Yes
	// the answer it had, for every verdict (2026-11-06 deep review R1: a Yes answered again as a cause kept the species confirmed)
	$was = $rw->querySingle("SELECT r.Verdict, r.Reason, r.Orig_Sci, COALESCE(d.Sci_Name, r.Sci_Name) AS Sci FROM detection_reviews r
    LEFT JOIN detections d ON d.File_Name = r.File_Name WHERE r.File_Name = '" . SQLite3::escapeString($file_name) . "' LIMIT 1", true) ?: array();
	// no other Yes of the species left: it leaves the Confirmed list (the Undo of a Yes, and a Yes answered again)
	$last = function () use ($rw, $was) {
		$c = $rw->prepare("SELECT COUNT(*) FROM detection_reviews WHERE Verdict = 'yes' AND Sci_Name = :s");
		$c->bindValue(':s', $was['Sci'] ?? '');
		$res = $c ? $c->execute() : false;
		$row = $res ? $res->fetchArray(SQLITE3_NUM) : false;
		return $row !== false && intval($row[0]) === 0;
	};
	if ($verdict === 'clear' && !empty($was['Orig_Sci'])) {
		$st = $rw->prepare("UPDATE detection_reviews SET Verdict = 'yes', Reason = 'changed' WHERE File_Name = :f");
		$st->bindValue(':f', $file_name);
	} elseif ($verdict === 'clear') {
		$st = $rw->prepare('DELETE FROM detection_reviews WHERE File_Name = :f');
		$st->bindValue(':f', $file_name);
	} else {
		// a detection that came from Change species keeps where it came from (Orig_Sci, the model's confidence)
		// whatever it is answered later, and a Yes on it stays 'changed' (owner 2026-10-22: the original species' Precision)
		$st = $rw->prepare("INSERT OR REPLACE INTO detection_reviews (File_Name, Sci_Name, Date, Confidence, Verdict, Reviewed_At, Reason, Orig_Sci)
      SELECT d.File_Name, d.Sci_Name, d.Date, CASE WHEN o.Orig_Sci IS NOT NULL AND o.Orig_Sci <> '' THEN o.Confidence ELSE d.Confidence END,
        :v, datetime('now', 'localtime'),
        CASE WHEN :v = 'no' THEN :r WHEN :v = 'yes' AND o.Orig_Sci IS NOT NULL AND o.Orig_Sci <> '' THEN 'changed' ELSE '' END, o.Orig_Sci
      FROM detections d LEFT JOIN detection_reviews o ON o.File_Name = d.File_Name WHERE d.File_Name = :f LIMIT 1");
		$st->bindValue(':f', $file_name);
		$st->bindValue(':v', $verdict);
		$st->bindValue(':r', $verdict === 'no' ? $reason : '');
	}
	$ok = $st->execute() !== false && ($verdict === 'clear' || $rw->changes() > 0);
	// the database held by another writer for the 5 s waited (2026-11-06 second pass R2: it read "detection not found")
	$busy = !$ok && in_array($rw->lastErrorCode(), array(5, 6), true);
	// a positive review also confirms the species (Confirmed list of Species Management), owner 2026-10-08; the list under
	// a lock (confirmed_list_set); a list that could not be written leaves the answer saved and goes to the web server's log
	if ($ok && $verdict === 'yes') {
		$sci = $rw->querySingle("SELECT Sci_Name FROM detections WHERE File_Name = '" . SQLite3::escapeString($file_name) . "' LIMIT 1");
		if ($sci && !confirmed_list_set($sci, true)) error_log('BirdSuite: confirmed_species_list.txt not written (' . $sci . ')');
	}
	// the Undo of a Yes (the player sends unconfirm=1 only then, never on a plain Clear, so a species confirmed by hand in
	// Species Management is never dropped by a Clear): the species leaves the Confirmed list only when no other Yes of it
	// remains (a Yes written by Change species is a Yes too)
	if ($ok && $verdict === 'clear' && isset($_GET['unconfirm']) && ($was['Verdict'] ?? '') === 'yes' && empty($was['Orig_Sci']) && !empty($was['Sci'])) {
		if (!confirmed_list_set($was['Sci'], false, $last)) error_log('BirdSuite: confirmed_species_list.txt not written (' . $was['Sci'] . ')');
	}
	// a Yes answered again as a cause or Review later (2026-11-06 deep review R1): the species leaves the Confirmed list
	// when no other Yes of it remains, as the Undo of that Yes does (a Yes written by Change species confirms nothing)
	if ($ok && in_array($verdict, array('no', 'unsure'), true) && ($was['Verdict'] ?? '') === 'yes' && ($was['Reason'] ?? '') !== 'changed' && empty($was['Orig_Sci']) && !empty($was['Sci'])) {
		if (!confirmed_list_set($was['Sci'], false, $last)) error_log('BirdSuite: confirmed_species_list.txt not written (' . $was['Sci'] . ')');
	}
	// a clip staged for purge at night goes back at once when its answer is no longer a cause (owner 2026-10-22: it can be
	// listened to again right away)
	if ($ok && !($verdict === 'no' && in_array($reason, PURGE_CAUSES, true))) {
		$d = $rw->querySingle("SELECT Date, Sci_Name, File_Name FROM detections WHERE File_Name = '" . SQLite3::escapeString($file_name) . "' LIMIT 1", true);
		if ($d) unstage_clip($d['Date'], $d['Sci_Name'], $file_name);
	}
	$rw->close();
	echo $ok ? "OK" : 'Error - ' . t($busy ? 'str_the_station_is_busy_try_again' : 'str_detection_not_found');
	die();
}

// Rejection in a batch (owner 2026-10-09): every detection of a species in one hour of one day that has no review
// yet becomes "not this bird" with the same reason (human, noise or ignore). Answers how many were marked.
if (isset($_GET['review_batch'], $_GET['sci'], $_GET['date'], $_GET['hour'])) {
	ensure_role('operator', t('str_you_must_review_detections_help'));  // a household device on the LAN reviews without the login (US-53)
	$hour = intval($_GET['hour']);
	if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['date']) || $hour < 0 || $hour > 23) { echo "Error"; die(); }
	// a batch is always a cause (owner 2026-11-06, Gitea #69: without one the hour was marked "not this bird" with no
	// reason, read as Change species)
	if (!in_array($_GET['reason'] ?? '', PURGE_CAUSES, true)) { echo 'Error - ' . t('str_reason_missing_or_unknown'); die(); }
	$reason = $_GET['reason'];
	$rw = new SQLite3(BS_DB, SQLITE3_OPEN_READWRITE);
	$rw->busyTimeout(5000);
	// only the clips the player listed and counted for the user (files, one per line; 2026-10-23 run 3: without it the
	// whole hour of every location was marked, 103 where the label said 26)
	$files = array_slice(array_values(array_filter(array_map(function ($f) { return basename(trim($f)); }, explode("\n", $_GET['files'] ?? '')), 'strlen')), 0, 500);
	if (!$files) { echo 'OK 0'; die(); }
	$in = implode(',', array_map(function ($i) { return ':f' . $i; }, array_keys($files)));
	$st = $rw->prepare("INSERT OR IGNORE INTO detection_reviews (File_Name, Sci_Name, Date, Confidence, Verdict, Reviewed_At, Reason)
    SELECT File_Name, Sci_Name, Date, Confidence, 'no', datetime('now', 'localtime'), :r FROM detections
    WHERE Sci_Name = :s AND Date = :d AND CAST(substr(Time, 1, 2) AS INT) = :h AND File_Name IN ($in)");
	foreach ($files as $i => $f) $st->bindValue(':f' . $i, $f);
	$st->bindValue(':s', html_entity_decode($_GET['sci'], ENT_QUOTES));
	$st->bindValue(':d', $_GET['date']);
	$st->bindValue(':h', $hour);
	$st->bindValue(':r', $reason);
	// the answer is the number of detections really marked (at most 500 per request; those with an answer already and
	// those outside the hour are not counted)
	$ok = $st->execute() !== false;
	echo $ok ? 'OK ' . $rw->changes() : 'Error - ' . $rw->lastErrorMsg();
	$rw->close();
	die();
}

// Review helper (scripts/review_worker.py): keeps the model loaded and redraws clip spectrograms; started on demand as
// the station user, it stops itself when idle. review_worker_get() starts it when it is not answering.
// $start = false: only asked when it is already running (a viewer never starts it, owner 2026-11-06)
function review_worker_get($path, $timeout = 30, $wait = true, $start = true) {
	global $home, $user;
	$url = 'http://127.0.0.1:8099' . $path;
	$ctx = stream_context_create(array('http' => array('timeout' => $timeout, 'ignore_errors' => true)));
	$out = @file_get_contents($url, false, $ctx);
	if ($out === false && $start) {
		exec(station_prefix() . 'nohup ' . escapeshellarg(BS_PYTHON) . ' '
			. escapeshellarg(BS_SCRIPTS . '/review_worker.py') . ' > /dev/null 2>&1 &');
		for ($i = 0; $wait && $i < 60 && $out === false; $i++) { usleep(250000); $out = @file_get_contents($url, false, $ctx); }
	}
	$ok = isset($http_response_header[0]) && strpos($http_response_header[0], ' 200') !== false;
	return $ok ? $out : false;
}
function review_clip_path($f) {
	global $home;
	if (strpos($f, '..') !== false) return false;
	$clip = BS_BY_DATE . '/' . $f;
	if (is_file($clip)) return $clip;
	// a clip staged for the purge (answered with a cause) is drawn from there (2026-10-23)
	$staged = BS_EXTRACTED . '/Purge/' . $f;
	return is_file($staged) ? $staged : false;
}

// the review player opens: warm the helper up (authenticated reviewers) and answer the station's spectrogram settings,
// the starting values of the player's palette / floor / range / contrast fields
if (isset($_GET['review_worker'])) {
	$c = get_config();
	if (is_authenticated()) review_worker_get('/ping', 1, false);
	header('Content-Type: application/json');
	echo json_encode(array('palette' => $c['SPECTROGRAM_PALETTE'] ?? 'turbo', 'floor' => $c['SPECTROGRAM_FLOOR_DB'] ?? -95,
		'range' => $c['SPECTROGRAM_RANGE_DB'] ?? 50, 'contrast' => $c['SPECTROGRAM_CONTRAST'] ?? 1.7));
	die();
}

// Which bird was it? The station's model re-analyses the clip (clip_alternatives.py through the review helper)
if (isset($_GET['alternatives'])) {
	ensure_role('operator', t('str_you_must_review_detections_help'));  // a household device on the LAN reviews without the login (US-53)
	header('Content-Type: application/json');
	$clip = review_clip_path($_GET['alternatives']);
	$sci = $clip ? $db->querySingle("SELECT Sci_Name FROM detections WHERE File_Name = '" . SQLite3::escapeString(basename($clip)) . "' LIMIT 1") : null;
	if (!$clip || !$sci) { echo '[]'; die(); }
	$out = review_worker_get('/alternatives?' . http_build_query(array('clip' => $clip, 'sci' => $sci, 'n' => 6)));
	echo $out !== false && trim($out) !== '' ? trim($out) : '[]';
	die();
}

// the clip's spectrogram without the title, in the palette / floor / range / contrast chosen in the review player
if (isset($_GET['spectro'])) {
	$clip = review_clip_path($_GET['spectro']);
	$q = array('clip' => $clip);
	if (isset($_GET['palette']) && preg_match('/^[a-z]+$/', $_GET['palette'])) $q['palette'] = $_GET['palette'];
	foreach (array('floor', 'range', 'contrast') as $k) if (isset($_GET[$k]) && is_numeric($_GET[$k])) $q[$k] = $_GET[$k];
	// the helper is started for a reviewer (operator or more, as Which bird was it?); a viewer gets the redrawn picture
	// while it is running, else the stored one (owner 2026-11-06: an anonymous caller started the model on the station)
	$start = role_rank(current_role()) >= role_rank('operator');
	$png = $clip ? review_worker_get('/spectro?' . http_build_query($q), 30, true, $start) : false;
	if ($png === false && $clip && !$start) {
		foreach (array('png' => 'image/png', 'jpg' => 'image/jpeg') as $ext => $type) {
			if (!is_file($clip . '.' . $ext)) continue;
			header('Content-Type: ' . $type);
			header('Cache-Control: private, max-age=60');
			readfile($clip . '.' . $ext);
			die();
		}
	}
	if ($png === false) { http_response_code(404); die(); }
	header('Content-Type: image/png');
	header('Cache-Control: private, max-age=3600');
	echo $png;
	die();
}

if(isset($_GET['getlabels'])) {
		// every class of the active model in the station's language (model/labels.txt, rebuilt by the analysis when MODEL
		// changes), US-30
		$labels = file(BS_MODEL . '/labels.txt', FILE_IGNORE_NEW_LINES);
		echo json_encode($labels);
		die();
}

if(isset($_GET['changefile']) && isset($_GET['newname'])) {
	ensure_authenticated(t('str_you_must_delete_files_help'));
	if (preg_match('~^.*(\.\.\/).+$~', $_GET['changefile'])) {
		echo "Error";
		die();
	}
	// the sanitize filter of this file turned quotes into entities: undo it, and both names reach the shell escaped (owner
	// 2026-10-22: a name with an apostrophe failed, and a crafted one could run commands)
	$oldname = basename(html_entity_decode($_GET['changefile'], ENT_QUOTES));
	$newname = html_entity_decode($_GET['newname'], ENT_QUOTES);
	$old = get_db()->querySingle("SELECT Sci_Name, Date, Confidence FROM main.detections WHERE File_Name = '" . SQLite3::escapeString($oldname) . "'", true) ?: array();
	// changed before: the species the model gave stays the origin (A to B to C keeps A, with the model's confidence)
	$prev = get_db()->querySingle("SELECT Orig_Sci, Confidence FROM main.detection_reviews WHERE File_Name = '" . SQLite3::escapeString($oldname) . "' AND Orig_Sci IS NOT NULL AND Orig_Sci <> ''", true) ?: array();
	// a clip staged for the purge (answered with a cause) comes back to By_Date first, where the script renames it, as an
	// answer that is no longer a cause brings it back (owner 2026-11-06, Gitea #69: it failed with "File does not exist")
	if ($old && !unstage_clip($old['Date'], $old['Sci_Name'], $oldname)) {
		echo 'Error - ' . t('str_file_not_moved', array('msg' => $oldname));
		die();
	}
	// the script's exit status tells (owner 2026-11-06): 0 = the line and the files changed, else nothing changed and its
	// last line is the reason
	$output = array();
	$rc = 1;
	exec(station_prefix() . '' . escapeshellarg(BS_SCRIPTS . '/birdnet_changeidentification.sh') . ' ' . escapeshellarg($oldname) . ' ' . escapeshellarg($newname) . ' log_errors 2>&1', $output, $rc);
	if ($rc === 0) {
		// Change species is an answer too (owner 2026-10-22): the renamed detection keeps a review Yes with the reason
		// 'changed', so the Review button reads Changed and the statistics know how many were changed (the new file name
		// follows birdnet_changeidentification.sh)
		// the same name birdnet_changeidentification.sh gives: <NewSci> + the old name from its date on
		$new_file = name_safe(explode('_', $newname, 2)[0]) . clip_rest($oldname);
		$rw = new SQLite3(BS_DB, SQLITE3_OPEN_READWRITE);
		$rw->busyTimeout(5000);
		// the old answer out and the new one in, in one transaction (owner 2026-11-06, Gitea #69: a busy database could drop
		// the old row and never write the new one, losing the species the model gave)
		$saved = (bool)@$rw->exec('BEGIN IMMEDIATE');
		if ($saved) {
			$del = $rw->prepare('DELETE FROM detection_reviews WHERE File_Name = :f');
			$del->bindValue(':f', $oldname);
			$saved = $del->execute() !== false;
		}
		if ($saved) {
			// the review keeps the confidence the model gave (the script zeroes the detection's) and the species it came from
			$ins = $rw->prepare("INSERT OR REPLACE INTO detection_reviews (File_Name, Sci_Name, Date, Confidence, Verdict, Reviewed_At, Reason, Orig_Sci)
        SELECT File_Name, Sci_Name, Date, :c, 'yes', datetime('now', 'localtime'), 'changed', :os FROM main.detections WHERE File_Name = :f LIMIT 1");
			$ins->bindValue(':f', $new_file);
			$ins->bindValue(':c', floatval($prev ? $prev['Confidence'] : ($old['Confidence'] ?? 0)));
			$ins->bindValue(':os', (string)($prev ? $prev['Orig_Sci'] : ($old['Sci_Name'] ?? '')));
			$saved = $ins->execute() !== false && $rw->changes() === 1;
		}
		if ($saved) $saved = (bool)@$rw->exec('COMMIT');
		$msg = $rw->lastErrorMsg();
		if (!$saved) @$rw->exec('ROLLBACK');
		$rw->close();
		// the species chosen by ear is confirmed, as a Yes confirms it (Confirmed list of Species Management), under the
		// list's lock (confirmed_list_set)
		$new_sci = explode('_', $newname, 2)[0];
		if ($new_sci !== '' && !confirmed_list_set($new_sci, true)) error_log('BirdSuite: confirmed_species_list.txt not written (' . $new_sci . ')');
		echo $saved ? "OK" : 'Error - ' . t('str_species_changed_answer_not_saved', array('msg' => $msg));
	} else {
		echo "Error : " . implode(", ", $output) . "<br>";
	}
	die();
}

// The hearing aid's sound of a clip (owner 2026-11-04): ?lowered=By_Date|Purge/<date>/<species folder>/<file> gives the clip
// lowered with the station's settings (the cut below FREQSHIFT_BASE, then FREQSHIFT_LO / FREQSHIFT_HI, as the live audio)
// for the speaker only: the clip itself is never touched and nothing is saved beside it. The sound is made once per clip
// and settings in the web server's private temporary folder and dropped after an hour; Range requests let the player
// seek (Loop 3 s, the play bar).
if (isset($_GET['lowered'])) {
	if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
	$rel = html_entity_decode((string)$_GET['lowered'], ENT_QUOTES);
	if (strpos($rel, '..') !== false || !preg_match('~^(By_Date|Purge)/(\d{4}-\d{2}-\d{2}/[^/]+/[^/]+)$~', $rel, $m)) { http_response_code(400); die(); }
	$src = ($m[1] === 'Purge' ? BS_EXTRACTED . '/Purge/' : BS_BY_DATE . '/') . $m[2];
	if (!is_file($src)) { http_response_code(404); die(); }
	$c = get_config();
	$hi = max(500, intval($c['FREQSHIFT_HI'] ?? 12000));
	$lo = max(100, min($hi, intval($c['FREQSHIFT_LO'] ?? 5000)));
	$base = max(0, intval($c['FREQSHIFT_BASE'] ?? 0));
	$dir = sys_get_temp_dir() . '/birdsuite-lowered';
	if (!is_dir($dir)) @mkdir($dir, 0700, true);
	$out = $dir . '/' . md5($src . '|' . filemtime($src) . "|$hi|$lo|$base") . '.wav';
	if (!is_file($out)) {
		$af = ($base > 0 ? "highpass=f=$base,highpass=f=$base," : '') . "rubberband=pitch=$lo/$hi";
		exec('/usr/bin/ffmpeg -nostdin -loglevel error -y -i ' . escapeshellarg($src) . ' -af ' . escapeshellarg($af) . ' -c:a pcm_s16le '
			. escapeshellarg($out . '.tmp.wav') . ' 2>&1 && mv -f ' . escapeshellarg($out . '.tmp.wav') . ' ' . escapeshellarg($out), $o, $rc);
		if ($rc !== 0 || !is_file($out)) { @unlink($out . '.tmp.wav'); http_response_code(500); die(); }
		// now and then the sounds older than an hour go
		if (mt_rand(1, 20) === 1) foreach (glob($dir . '/*.wav') ?: array() as $old) if (@filemtime($old) < time() - 3600) @unlink($old);
	}
	$size = filesize($out);
	$from = 0; $to = $size - 1;
	header('Content-Type: audio/wav');
	header('Accept-Ranges: bytes');
	header('Cache-Control: private, max-age=3600');
	if (preg_match('/^bytes=(\d*)-(\d*)$/', (string)($_SERVER['HTTP_RANGE'] ?? ''), $r)) {
		if ($r[1] === '' && $r[2] !== '') { $from = max(0, $size - intval($r[2])); }
		else { $from = intval($r[1]); if ($r[2] !== '') $to = min($size - 1, intval($r[2])); }
		if ($from > $to || $from >= $size) { http_response_code(416); header("Content-Range: bytes */$size"); die(); }
		http_response_code(206);
		header("Content-Range: bytes $from-$to/$size");
	}
	header('Content-Length: ' . ($to - $from + 1));
	$fh = fopen($out, 'rb');
	fseek($fh, $from);
	$left = $to - $from + 1;
	while ($left > 0 && !feof($fh)) { $chunk = fread($fh, min(65536, $left)); echo $chunk; $left -= strlen($chunk); }
	fclose($fh);
	die();
}

// one detection (?filename=, a shared link or a notification): the page part, scripts/detection.php
if (isset($_GET['filename'])) include __DIR__ . '/detection.php';
