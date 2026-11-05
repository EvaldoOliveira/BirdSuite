<?php

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
  $src = BS_BY_DATE . '/' . $file;
  $dst = deleted_dir() . '/' . $parts[0] . '/' . $parts[1];
  $out = array();
  $rc = 0;
  exec(station_prefix() . 'mkdir -p ' . escapeshellarg($dst) . ' && ' . station_prefix() . 'mv -f ' . escapeshellarg($src) . ' '
    . (file_exists($src . '.jpg') && !file_exists($src . '.png') ? escapeshellarg($src . '.jpg') : escapeshellarg($src . '.png')) . ' ' . escapeshellarg($dst . '/') . ' 2>&1; ' . station_prefix() . 'rm -f ' . escapeshellarg(BS_BY_DATE . '/shifted/' . $file) . ' 2>&1', $out, $rc);
  if ($ins->execute() === false || $del->execute() === false || $rw->changes() === 0) {
    echo 'Error - ' . t('str_database_line_not_moved', array('msg' => $rw->lastErrorMsg()));
  } elseif ($rc !== 0 && is_file($src)) {
    echo 'Error - ' . t('str_file_not_moved', array('msg' => implode(', ', $out)));
  } else {
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
  $errors = array();
  if ($loc !== '') {
    $st = $rw->prepare("SELECT Date, Sci_Name, File_Name FROM detections WHERE Sci_Name = :s$loc");
    $st->bindValue(':s', $sci);
    $res = $st->execute();
    while ($res && ($r = $res->fetchArray(SQLITE3_ASSOC))) {
      $rel = $r['Date'] . '/' . species_folder($r['Sci_Name']);
      $src = BS_BY_DATE . '/' . $rel . '/' . $r['File_Name'];
      $dst = deleted_dir() . '/' . $rel;
      if (!file_exists($src)) continue;
      $out = array();
      $rc = 0;
      exec(station_prefix() . 'mkdir -p ' . escapeshellarg($dst) . ' && ' . station_prefix() . 'mv -f ' . escapeshellarg($src) . ' '
        . (file_exists($src . '.png') ? escapeshellarg($src . '.png') . ' ' : '') . (file_exists($src . '.jpg') ? escapeshellarg($src . '.jpg') . ' ' : '') . escapeshellarg($dst . '/') . ' 2>&1; ' . station_prefix() . 'rm -f '
        . escapeshellarg(BS_BY_DATE . '/shifted/' . $rel . '/' . $r['File_Name']) . ' 2>&1', $out, $rc);
      if ($rc !== 0 && file_exists($src)) $errors[] = $rel . '/' . $r['File_Name'] . ': ' . implode(', ', $out);
    }
    $res = false;
  } else {
    $st = $rw->prepare('SELECT DISTINCT Date FROM detections WHERE Sci_Name = :s');
    $st->bindValue(':s', $sci);
    $res = $st->execute();
  }
  // a day's folder of the species (scientific name)
  $rels = array();
  while ($res && ($r = $res->fetchArray(SQLITE3_ASSOC))) {
    $rels[$r['Date'] . '/' . name_safe($sci)] = 1;
  }
  foreach (array_keys($rels) as $rel) {
    $src = BS_BY_DATE . '/' . $rel;
    $dst = deleted_dir() . '/' . $rel;
    if (!is_dir($src)) continue;
    $out = array();
    $rc = 0;
    exec(station_prefix() . 'mkdir -p ' . escapeshellarg($dst) . ' && ' . station_prefix() . 'find ' . escapeshellarg($src)
      . ' -maxdepth 1 -type f -exec mv -f -t ' . escapeshellarg($dst . '/') . ' {} + && ' . station_prefix() . 'rmdir ' . escapeshellarg($src)
      . ' 2>&1; ' . station_prefix() . 'rm -rf ' . escapeshellarg(BS_BY_DATE . '/shifted/' . $rel) . ' 2>&1', $out, $rc);
    if ($rc !== 0 && is_dir($src) && count(glob($src . '/*'))) $errors[] = $rel . ': ' . implode(', ', $out);
  }
  if ($errors) { echo 'Error - ' . t('str_files_not_moved', array('msg' => implode('; ', $errors))); $rw->close(); die(); }
  $rw->exec('BEGIN');
  $ins = $rw->prepare("INSERT INTO deleted_detections (Date, Time, Sci_Name, Confidence, Lat, Lon, Cutoff, Week, Sens, Overlap, File_Name, Deleted_At, Loc_Thresh, Rec_Length, Sp_Override, Location, Mic, Mic_Port, Mic_Gain, Tz, Utc_Offset, Model, Software, Species_List, Audio_Loss_N, Audio_Loss_Ms, Sample_Rate, Channels, Scenario)
    SELECT Date, Time, Sci_Name, Confidence, Lat, Lon, Cutoff, Week, Sens, Overlap, File_Name, datetime('now', 'localtime'), Loc_Thresh, Rec_Length, Sp_Override, Location, Mic, Mic_Port, Mic_Gain, Tz, Utc_Offset, Model, Software, Species_List, Audio_Loss_N, Audio_Loss_Ms, Sample_Rate, Channels, Scenario FROM detections WHERE Sci_Name = :s$loc");
  $ins->bindValue(':s', $sci);
  $del = $rw->prepare("DELETE FROM detections WHERE Sci_Name = :s$loc");
  $del->bindValue(':s', $sci);
  if ($ins->execute() === false || $del->execute() === false) {
    $msg = $rw->lastErrorMsg();
    $rw->exec('ROLLBACK');
    echo 'Error - ' . t('str_database_lines_not_moved', array('msg' => $msg));
  } else {
    $n = $rw->changes();
    $rw->exec('COMMIT');
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
    $locked = locked_clips();
  }
  while ($res && ($r = $res->fetchArray(SQLITE3_ASSOC))) {
    if ($kind === 'others' && (!isset($excl[$r['Sci_Name']]) || ($sci !== '' && $r['Sci_Name'] !== $sci)
        || isset($locked[$r['Date'] . '/' . species_folder($r['Sci_Name']) . '/' . $r['File_Name']]))) continue;
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
  $n = 0;
  foreach ($rows as $r) {
    if (!@$rw->exec('BEGIN IMMEDIATE')) continue;  // busy beyond the timeout: left in the list, not counted
    $ins = $rw->prepare("INSERT INTO detections (Date, Time, Sci_Name, Confidence, Lat, Lon, Cutoff, Week, Sens, Overlap, File_Name, Loc_Thresh, Rec_Length, Sp_Override, Location, Mic, Mic_Port, Mic_Gain, Tz, Utc_Offset, Model, Software, Species_List, Audio_Loss_N, Audio_Loss_Ms, Sample_Rate, Channels, Scenario)
      SELECT Date, Time, Sci_Name, Confidence, $place, Cutoff, Week, Sens, Overlap, File_Name, Loc_Thresh, Rec_Length, Sp_Override, $place_id, Mic, Mic_Port, Mic_Gain, Tz, Utc_Offset, Model, Software, Species_List, Audio_Loss_N, Audio_Loss_Ms, Sample_Rate, Channels, Scenario FROM deleted_detections WHERE File_Name = :f
      AND NOT EXISTS (SELECT 1 FROM detections WHERE File_Name = :f)");
    $ins->bindValue(':f', $r['File_Name']);
    if ($into) {
      $ins->bindValue(':lat', floatval($into['lat']), SQLITE3_FLOAT);
      $ins->bindValue(':lon', floatval($into['lon']), SQLITE3_FLOAT);
      $ins->bindValue(':loc', intval($into['id']), SQLITE3_INTEGER);
    }
    $del = $rw->prepare('DELETE FROM deleted_detections WHERE File_Name = :f');
    $del->bindValue(':f', $r['File_Name']);
    if ($ins && $ins->execute() !== false && $del && $del->execute() !== false && @$rw->exec('COMMIT')) {
      $rel = $r['Date'] . '/' . species_folder($r['Sci_Name']);
      $src = deleted_dir() . '/' . $rel . '/' . $r['File_Name'];
      $dst = BS_BY_DATE . '/' . $rel;
      exec(station_prefix() . 'mkdir -p ' . escapeshellarg($dst) . ' && ' . station_prefix() . 'mv -f '
        . escapeshellarg($src) . ' ' . (file_exists($src . '.jpg') && !file_exists($src . '.png') ? escapeshellarg($src . '.jpg') : escapeshellarg($src . '.png')) . ' ' . escapeshellarg($dst . '/') . ' 2>&1');
      $n++;
    } else {
      @$rw->exec('ROLLBACK');
    }
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
  foreach (array_chunk($paths, 200) as $chunk) {
    exec(station_prefix() . 'rm -f ' . implode(' ', array_map('escapeshellarg', $chunk)) . ' 2>&1');
  }
  exec(station_prefix() . 'find ' . escapeshellarg(deleted_dir()) . ' -mindepth 1 -type d -empty -delete 2>&1');
  $d = $rw->prepare("DELETE FROM deleted_detections$where");
  if ($sci !== '') $d->bindValue(':s', $sci);
  $d->execute();
  $wiped = $rw->changes();
  // their reviews go too (the detections no longer exist)
  if ($names) {
    foreach (array_chunk($names, 400) as $chunk) {
      $rw->exec("DELETE FROM detection_reviews WHERE File_Name IN ('" . implode("','", array_map('SQLite3::escapeString', $chunk)) . "')");
    }
  }
  $rw->close();
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
  if(isset($_GET['exclude_add'])) {
    $myfile = fopen(BS_DISK_EXCLUDE, "a") or die(t('str_unable_to_open_file'));
    $txt = $_GET['excludefile'];
    fwrite($myfile, $txt."\n");
    fwrite($myfile, $txt.".png\n");
    fwrite($myfile, $txt.".jpg\n");
    fclose($myfile);
    echo "OK";
    die();
  } else {
    $lines  = file(BS_DISK_EXCLUDE);
    $search = $_GET['excludefile'];

    $result = '';
    foreach($lines as $line) {
      if(stripos($line, $search) === false && stripos($line, $search.".png") === false) {
        $result .= $line;
      }
    }
    file_put_contents(BS_DISK_EXCLUDE, $result);
    echo "OK";
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
  if ($verdict === 'clear') {
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
  // a positive review also confirms the species (Confirmed list of Species Management), owner 2026-10-08
  if ($ok && $verdict === 'yes') {
    $sci = $rw->querySingle("SELECT Sci_Name FROM detections WHERE File_Name = '" . SQLite3::escapeString($file_name) . "' LIMIT 1");
    $list = BS_LISTS . '/confirmed_species_list.txt';
    $lines = is_file($list) ? file($list, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) : array();
    if ($sci && !in_array($sci, $lines, true)) {
      $lines[] = $sci;
      sort($lines, SORT_STRING);
      file_put_contents($list, implode("\n", $lines) . "\n", LOCK_EX);
    }
  }
  // a clip staged for purge at night goes back at once when its answer is no longer a cause (owner 2026-10-22: it can be
  // listened to again right away)
  if ($ok && !($verdict === 'no' && in_array($reason, PURGE_CAUSES, true))) {
    $d = $rw->querySingle("SELECT Date, Sci_Name, File_Name FROM detections WHERE File_Name = '" . SQLite3::escapeString($file_name) . "' LIMIT 1", true);
    if ($d) {
      $rel = $d['Date'] . '/' . species_folder($d['Sci_Name']);
      $src = BS_EXTRACTED . '/Purge/' . $rel . '/' . $file_name;
      if (file_exists($src)) {
        $dst = BS_BY_DATE . '/' . $rel;
        $files = array_filter(array($src, $src . '.png', $src . '.jpg'), 'file_exists');
        exec(station_prefix() . 'mkdir -p ' . escapeshellarg($dst) . ' && ' . station_prefix() . 'mv -f '
          . implode(' ', array_map('escapeshellarg', $files)) . ' ' . escapeshellarg($dst . '/'));
      }
    }
  }
  $rw->close();
  echo $ok ? "OK" : 'Error - ' . t('str_detection_not_found');
  die();
}

// Rejection in a batch (owner 2026-10-09): every detection of a species in one hour of one day that has no review
// yet becomes "not this bird" with the same reason (human, noise or ignore). Answers how many were marked.
if (isset($_GET['review_batch'], $_GET['sci'], $_GET['date'], $_GET['hour'])) {
  ensure_role('operator', t('str_you_must_review_detections_help'));  // a household device on the LAN reviews without the login (US-53)
  $hour = intval($_GET['hour']);
  if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['date']) || $hour < 0 || $hour > 23) { echo "Error"; die(); }
  $reason = in_array($_GET['reason'] ?? '', PURGE_CAUSES, true) ? $_GET['reason'] : '';
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
  $ok = $st->execute() !== false;
  echo $ok ? 'OK ' . $rw->changes() : 'Error';
  $rw->close();
  die();
}

// Review helper (scripts/review_worker.py): keeps the model loaded and redraws clip spectrograms; started on demand as
// the station user, it stops itself when idle. review_worker_get() starts it when it is not answering.
function review_worker_get($path, $timeout = 30, $wait = true) {
  global $home, $user;
  $url = 'http://127.0.0.1:8099' . $path;
  $ctx = stream_context_create(array('http' => array('timeout' => $timeout, 'ignore_errors' => true)));
  $out = @file_get_contents($url, false, $ctx);
  if ($out === false) {
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
  echo json_encode(array('palette' => $c['SPECTROGRAM_PALETTE'] ?? 'turbo', 'floor' => $c['SPECTROGRAM_FLOOR_DB'] ?? -100,
    'range' => $c['SPECTROGRAM_RANGE_DB'] ?? 70, 'contrast' => $c['SPECTROGRAM_CONTRAST'] ?? 1));
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
  $png = $clip ? review_worker_get('/spectro?' . http_build_query($q)) : false;
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
  $old = get_db()->querySingle("SELECT Sci_Name, Confidence FROM main.detections WHERE File_Name = '" . SQLite3::escapeString($oldname) . "'", true) ?: array();
  // changed before: the species the model gave stays the origin (A to B to C keeps A, with the model's confidence)
  $prev = get_db()->querySingle("SELECT Orig_Sci, Confidence FROM main.detection_reviews WHERE File_Name = '" . SQLite3::escapeString($oldname) . "' AND Orig_Sci IS NOT NULL AND Orig_Sci <> ''", true) ?: array();
  if (!exec(station_prefix() . '' . escapeshellarg(BS_SCRIPTS . '/birdnet_changeidentification.sh') . ' ' . escapeshellarg($oldname) . ' ' . escapeshellarg($newname) . ' log_errors 2>&1', $output)) {
    // Change species is an answer too (owner 2026-10-22): the renamed detection keeps a review Yes with the reason
    // 'changed', so the Review button reads Changed and the statistics know how many were changed (the new file name
    // follows birdnet_changeidentification.sh)
    // the same name birdnet_changeidentification.sh gives: <NewSci> + the old name from its date on
    $new_file = name_safe(explode('_', $newname, 2)[0]) . clip_rest($oldname);
    $rw = new SQLite3(BS_DB, SQLITE3_OPEN_READWRITE);
    $rw->busyTimeout(5000);
    $del = $rw->prepare('DELETE FROM detection_reviews WHERE File_Name = :f');
    $del->bindValue(':f', $oldname);
    $del->execute();
    // the review keeps the confidence the model gave (the script zeroes the detection's) and the species it came from
    $ins = $rw->prepare("INSERT OR REPLACE INTO detection_reviews (File_Name, Sci_Name, Date, Confidence, Verdict, Reviewed_At, Reason, Orig_Sci)
      SELECT File_Name, Sci_Name, Date, :c, 'yes', datetime('now', 'localtime'), 'changed', :os FROM main.detections WHERE File_Name = :f LIMIT 1");
    $ins->bindValue(':f', $new_file);
    $ins->bindValue(':c', floatval($prev ? $prev['Confidence'] : ($old['Confidence'] ?? 0)));
    $ins->bindValue(':os', (string)($prev ? $prev['Orig_Sci'] : ($old['Sci_Name'] ?? '')));
    $ins->execute();
    $rw->close();
    // the species chosen by ear is confirmed, as a Yes confirms it (Confirmed list of Species Management)
    $new_sci = explode('_', $newname, 2)[0];
    $list = BS_LISTS . '/confirmed_species_list.txt';
    $lines = is_file($list) ? file($list, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) : array();
    if ($new_sci !== '' && !in_array($new_sci, $lines, true)) {
      $lines[] = $new_sci;
      sort($lines, SORT_STRING);
      file_put_contents($list, implode("\n", $lines) . "\n", LOCK_EX);
    }
    echo "OK";
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
  $lo = max(100, min($hi, intval($c['FREQSHIFT_LO'] ?? 6000)));
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

// one detection (?filename=, a shared link or a notification); the recordings browser was retired
if (get_included_files()[0] === __FILE__) {
  echo '<!DOCTYPE html>
<html lang="' . str_replace('_', '-', ui_lang()) . '">
  <head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    ' . ui_js() . '
  </head>';
}

?>
<script src="static/custom-audio-player.js?v=<?php echo @filemtime(__DIR__ . "/../homepage/static/custom-audio-player.js"); ?>"></script>
<script src="static/detection-actions.js?v=<?php echo @filemtime(__DIR__ . "/../homepage/static/detection-actions.js"); ?>"></script>
<script src="static/review-player.js?v=<?php echo @filemtime(__DIR__ . "/../homepage/static/review-player.js"); ?>"></script>
<div class="play">
<?php
if (isset($_GET['filename'])) {
  // a bound value, never the request text inside the SQL (2026-10-23 test run)
  $statement2 = $db->prepare("SELECT * FROM detections where File_name == :name ORDER BY Date DESC, Time DESC");
  ensure_db_ok($statement2);
  $statement2->bindValue(':name', html_entity_decode($_GET['filename'], ENT_QUOTES), SQLITE3_TEXT);
  $result2 = $statement2->execute();
  $results = $result2->fetchArray(SQLITE3_ASSOC);
  $sciname = $results['Sci_Name'];
  $result2->reset();
  // the detection's rows are its review list (static/review-player.js rpSourceItems)
  echo "<table data-review-list=\"1\">
    <tr><th>" . species_title($sciname, htmlspecialchars(common_name($sciname))) . "</th></tr>";
  while ($results = $result2->fetchArray(SQLITE3_ASSOC)) {
    $comname = species_folder($results['Sci_Name']);
    $date = $results['Date'];
    $filename_formatted = $date . "/" . $comname . "/" . $results['File_Name'];
    $filename = "/By_Date/" . $filename_formatted;
    $filename_png = clip_spectrogram($filename);
    $values = round((float)round($results['Confidence'], 2) * 100) . '%';
    echo "<tr>
      <td class=\"relative\"" . review_item_attrs($filename_formatted, $date . ' ' . $results['Time'], preg_replace('/ /', '_', $results['Sci_Name'])) . "> " . detection_actions($filename_formatted) . "$date {$results['Time']}<br>$values<br>
<div class='custom-audio-player' data-audio-src='$filename' data-image-src='$filename_png'></div>
</td></tr>";
  }
  echo "</table>";
}
echo "</div>";
if (get_included_files()[0] === __FILE__) {
  echo '</html>';
}
