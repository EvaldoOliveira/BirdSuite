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

// Delete = remove detection (owner 2026-10-09): the clip and its spectrogram leave the BirdNET folders for
// ~/BirdSongs/Extracted/Removed/<date>/<species>/, the database line goes to deleted_detections (out of every statistic);
// nothing is lost until it is deleted by hand (Species › Purge Files, or the species page)
if(isset($_GET['deletefile'])) {
  ensure_authenticated(t('You must be authenticated to delete files.'));
  $file = $_GET['deletefile'];
  $parts = explode('/', $file);
  if (strpos($file, '..') !== false || count($parts) !== 3) { echo "Error"; die(); }
  $rw = new SQLite3(BS_DB, SQLITE3_OPEN_READWRITE);
  $rw->busyTimeout(5000);
  deleted_table($rw);
  $ins = $rw->prepare("INSERT INTO deleted_detections (Date, Time, Sci_Name, Com_Name, Confidence, Lat, Lon, Cutoff, Week, Sens, Overlap, File_Name, Deleted_At, Loc_Thresh, Rec_Length, Sp_Override, Location)
    SELECT Date, Time, Sci_Name, Com_Name, Confidence, Lat, Lon, Cutoff, Week, Sens, Overlap, File_Name, datetime('now', 'localtime'), Loc_Thresh, Rec_Length, Sp_Override, Location FROM detections WHERE File_Name = :f");
  $ins->bindValue(':f', $parts[2]);
  $del = $rw->prepare('DELETE FROM detections WHERE File_Name = :f');
  $del->bindValue(':f', $parts[2]);
  $src = $home . '/BirdSongs/Extracted/By_Date/' . $file;
  $dst = deleted_dir() . '/' . $parts[0] . '/' . $parts[1];
  $out = array();
  $rc = 0;
  exec(station_prefix() . 'mkdir -p ' . escapeshellarg($dst) . ' && ' . station_prefix() . 'mv -f ' . escapeshellarg($src) . ' '
    . (file_exists($src . '.jpg') && !file_exists($src . '.png') ? escapeshellarg($src . '.jpg') : escapeshellarg($src . '.png')) . ' ' . escapeshellarg($dst . '/') . ' 2>&1; ' . station_prefix() . 'rm -f ' . escapeshellarg($home . '/BirdSongs/Extracted/By_Date/shifted/' . $file) . ' 2>&1', $out, $rc);
  if ($ins->execute() === false || $del->execute() === false || $rw->changes() === 0) {
    echo 'Error - ' . t('database line not moved: {msg}', array('msg' => $rw->lastErrorMsg()));
  } elseif ($rc !== 0 && is_file($src)) {
    echo 'Error - ' . t('file not moved: {msg}', array('msg' => implode(', ', $out)));
  } else {
    echo "OK";
  }
  $rw->close();
  die();
}

// Remove a whole species (owner 2026-10-19, Curation's Remove all): ?deletespecies=<scientific name> moves every clip
// and spectrogram of the species to ~/BirdSongs/Extracted/Removed/<date>/<species>/ (one move per day folder) and its
// database lines to deleted_detections, like Delete does for one detection; ?restore=1&sci=<name> brings them back.
// Answers OK <count>.
if (isset($_GET['deletespecies'])) {
  ensure_authenticated(t('You must be authenticated to delete files.'));
  $sci = html_entity_decode($_GET['deletespecies'], ENT_QUOTES);
  if ($sci === '' || strpos($sci, '/') !== false || strpos($sci, '..') !== false) { echo "Error"; die(); }
  $rw = new SQLite3(BS_DB, SQLITE3_OPEN_READWRITE);
  $rw->busyTimeout(5000);
  deleted_table($rw);
  // only the location the pages show, as the count the dialog gave (with several locations a day folder can hold
  // clips of more than one place, so the clips move one by one)
  $loc = (count(locations_load()) >= 2 && ($l = location_shown()) !== null) ? ' AND ' . location_sql($l) : '';
  $errors = array();
  if ($loc !== '') {
    $st = $rw->prepare("SELECT Date, Sci_Name, Com_Name, File_Name FROM detections WHERE Sci_Name = :s$loc");
    $st->bindValue(':s', $sci);
    $res = $st->execute();
    while ($res && ($r = $res->fetchArray(SQLITE3_ASSOC))) {
      $rel = $r['Date'] . '/' . species_folder($r['Sci_Name'] ?? '', $r['Com_Name'], $r['File_Name']);
      $src = $home . '/BirdSongs/Extracted/By_Date/' . $rel . '/' . $r['File_Name'];
      $dst = deleted_dir() . '/' . $rel;
      if (!file_exists($src)) continue;
      $out = array();
      $rc = 0;
      exec(station_prefix() . 'mkdir -p ' . escapeshellarg($dst) . ' && ' . station_prefix() . 'mv -f ' . escapeshellarg($src) . ' '
        . (file_exists($src . '.png') ? escapeshellarg($src . '.png') . ' ' : '') . (file_exists($src . '.jpg') ? escapeshellarg($src . '.jpg') . ' ' : '') . escapeshellarg($dst . '/') . ' 2>&1; ' . station_prefix() . 'rm -f '
        . escapeshellarg($home . '/BirdSongs/Extracted/By_Date/shifted/' . $rel . '/' . $r['File_Name']) . ' 2>&1', $out, $rc);
      if ($rc !== 0 && file_exists($src)) $errors[] = $rel . '/' . $r['File_Name'] . ': ' . implode(', ', $out);
    }
    $res = false;
  } else {
    $st = $rw->prepare('SELECT DISTINCT Date, Com_Name FROM detections WHERE Sci_Name = :s');
    $st->bindValue(':s', $sci);
    $res = $st->execute();
  }
  // a day's folder of the species: the new layout (scientific name) and the old one (common name) until migrated
  $rels = array();
  while ($res && ($r = $res->fetchArray(SQLITE3_ASSOC))) {
    $rels[$r['Date'] . '/' . name_safe($sci)] = 1;
    $rels[$r['Date'] . '/' . name_safe($r['Com_Name'])] = 1;
  }
  foreach (array_keys($rels) as $rel) {
    $src = $home . '/BirdSongs/Extracted/By_Date/' . $rel;
    $dst = deleted_dir() . '/' . $rel;
    if (!is_dir($src)) continue;
    $out = array();
    $rc = 0;
    exec(station_prefix() . 'mkdir -p ' . escapeshellarg($dst) . ' && ' . station_prefix() . 'find ' . escapeshellarg($src)
      . ' -maxdepth 1 -type f -exec mv -f -t ' . escapeshellarg($dst . '/') . ' {} + && ' . station_prefix() . 'rmdir ' . escapeshellarg($src)
      . ' 2>&1; ' . station_prefix() . 'rm -rf ' . escapeshellarg($home . '/BirdSongs/Extracted/By_Date/shifted/' . $rel) . ' 2>&1', $out, $rc);
    if ($rc !== 0 && is_dir($src) && count(glob($src . '/*'))) $errors[] = $rel . ': ' . implode(', ', $out);
  }
  if ($errors) { echo 'Error - ' . t('files not moved: {msg}', array('msg' => implode('; ', $errors))); $rw->close(); die(); }
  $rw->exec('BEGIN');
  $ins = $rw->prepare("INSERT INTO deleted_detections (Date, Time, Sci_Name, Com_Name, Confidence, Lat, Lon, Cutoff, Week, Sens, Overlap, File_Name, Deleted_At, Loc_Thresh, Rec_Length, Sp_Override, Location)
    SELECT Date, Time, Sci_Name, Com_Name, Confidence, Lat, Lon, Cutoff, Week, Sens, Overlap, File_Name, datetime('now', 'localtime'), Loc_Thresh, Rec_Length, Sp_Override, Location FROM detections WHERE Sci_Name = :s$loc");
  $ins->bindValue(':s', $sci);
  $del = $rw->prepare("DELETE FROM detections WHERE Sci_Name = :s$loc");
  $del->bindValue(':s', $sci);
  if ($ins->execute() === false || $del->execute() === false) {
    $msg = $rw->lastErrorMsg();
    $rw->exec('ROLLBACK');
    echo 'Error - ' . t('database lines not moved: {msg}', array('msg' => $msg));
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
// ?purgeaudio=causes [&reason=<cause>] [&sci=<name>] = answered "not this bird" with Dog, Human, Engine, Noise or Ignore;
// ?purgeaudio=others [&sci=<name>] = the excluded species. Every location, or &loc=<id>. Answers OK <files removed>.
if (isset($_GET['purgeaudio'])) {
  ensure_authenticated(t('You must be authenticated to delete files.'));
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
    $st = $db_r->prepare("SELECT d.Date, d.Sci_Name, d.Com_Name, d.File_Name FROM main.detections d JOIN detection_reviews r ON r.File_Name = d.File_Name
      WHERE r.Verdict = 'no' AND r.Reason IN ('" . implode("','", $causes) . "')" . ($sci !== '' ? ' AND d.Sci_Name = :s' : '') . $ploc);
    if ($sci !== '') $st->bindValue(':s', $sci);
    $res = $st->execute();
  } else {
    $excl = excluded_species();
    if ($sci !== '' && !isset($excl[$sci])) die('Error - ' . t('not an excluded species'));
    // the clips answered Yes and the protected ones (locks, top N) are kept (owner 2026-10-22)
    // only the excluded species that have detections are read, as Purge Files lists them (2026-10-23 test run: every
    // detection went to PHP)
    $excl_found = $excl ? excluded_with_detections($db_r, $excl) : '';
    $res = $excl_found !== '' ? $db_r->query("SELECT Date, Com_Name, File_Name, Sci_Name FROM main.detections WHERE Sci_Name IN ("
      . $excl_found . ") AND File_Name NOT IN (SELECT File_Name FROM detection_reviews WHERE Verdict = 'yes')" . $ploc) : false;
    $locked = locked_clips();
  }
  while ($res && ($r = $res->fetchArray(SQLITE3_ASSOC))) {
    if ($kind === 'others' && (!isset($excl[$r['Sci_Name']]) || ($sci !== '' && $r['Sci_Name'] !== $sci)
        || isset($locked[$r['Date'] . '/' . species_folder($r['Sci_Name'] ?? '', $r['Com_Name'], $r['File_Name']) . '/' . $r['File_Name']]))) continue;
    // the causes are deleted from Extracted/Purge (staged at night by purge_stage.py), the excluded species from By_Date
    $src = $home . '/BirdSongs/Extracted/' . ($kind === 'causes' ? 'Purge' : 'By_Date') . '/' . $r['Date'] . '/' . species_folder($r['Sci_Name'] ?? '', $r['Com_Name'], $r['File_Name']) . '/' . $r['File_Name'];
    foreach (array($src, $src . '.png', $src . '.jpg') as $f) if (file_exists($f)) $files[] = $f;
  }
  $db_r->close();
  $n = 0; $errors = array();
  foreach (array_chunk($files, 200) as $chunk) {
    $out = array(); $rc = 0;
    exec(station_prefix() . 'rm -f ' . implode(' ', array_map('escapeshellarg', $chunk)) . ' 2>&1', $out, $rc);
    foreach ($chunk as $f) { if (file_exists($f)) $errors[] = basename($f); else $n++; }
  }
  echo $errors ? 'Error - ' . t('files not removed: {msg}', array('msg' => implode(', ', array_slice($errors, 0, 5)))) : 'OK ' . $n;
  die();
}

// Restore removed detections (owner 2026-10-09): ?restore=1 [&sci=<scientific name>] [&file=<file name>] moves the files
// back to By_Date and the database lines back to detections; every species when both are empty (file = the review
// player's Undo). Answers OK <count>.
// Purge Files per location (owner 2026-10-21): &loc=<id> limits restore / wipe to that location's removed detections,
// &loc=none to those outside every location; no loc = all of them. Returns '' or an SQL condition.
function removed_loc_sql($loc) {
  if ($loc === 'none') {
    $all = array_map(function ($l) { return '(' . location_sql($l, true) . ')'; }, locations_load());
    return $all ? 'NOT (' . implode(' OR ', $all) . ')' : '';
  }
  if (ctype_digit((string)$loc) && ($l = location_by_id($loc))) return location_sql($l, true);
  return '';
}

if (isset($_GET['restore'])) {
  ensure_authenticated(t('You must be authenticated to restore detections.'));
  $rw = new SQLite3(BS_DB, SQLITE3_OPEN_READWRITE);
  $rw->busyTimeout(5000);
  deleted_table($rw);
  $sci = html_entity_decode($_GET['sci'] ?? '', ENT_QUOTES);
  $one = basename($_GET['file'] ?? '');
  $where = $one !== '' ? ' WHERE File_Name = :f' : ($sci !== '' ? ' WHERE Sci_Name = :s' : '');
  $lw = $one !== '' ? '' : removed_loc_sql($_GET['loc'] ?? '');
  if ($lw !== '') $where .= ($where === '' ? ' WHERE ' : ' AND ') . $lw;
  $st = $rw->prepare("SELECT Date, Sci_Name, Com_Name, File_Name FROM deleted_detections$where");
  if ($one !== '') $st->bindValue(':f', $one);
  elseif ($sci !== '') $st->bindValue(':s', $sci);
  // the list read in full first (2026-10-23 run 3: writing while this SELECT was still open made every write fail once the
  // analysis had committed, the files moved and the count grew anyway: 2,192 answered, 63 restored); then each detection
  // in its own transaction, its clip moved only when the database took it, and only those counted
  $res = $st->execute();
  $rows = array();
  while ($res && ($r = $res->fetchArray(SQLITE3_ASSOC))) $rows[] = $r;
  if ($res) $res->finalize();
  $n = 0;
  foreach ($rows as $r) {
    if (!@$rw->exec('BEGIN IMMEDIATE')) continue;  // busy beyond the timeout: left in the list, not counted
    $ins = $rw->prepare("INSERT INTO detections (Date, Time, Sci_Name, Com_Name, Confidence, Lat, Lon, Cutoff, Week, Sens, Overlap, File_Name, Loc_Thresh, Rec_Length, Sp_Override, Location)
      SELECT Date, Time, Sci_Name, Com_Name, Confidence, Lat, Lon, Cutoff, Week, Sens, Overlap, File_Name, Loc_Thresh, Rec_Length, Sp_Override, Location FROM deleted_detections WHERE File_Name = :f
      AND NOT EXISTS (SELECT 1 FROM detections WHERE File_Name = :f)");
    $ins->bindValue(':f', $r['File_Name']);
    $del = $rw->prepare('DELETE FROM deleted_detections WHERE File_Name = :f');
    $del->bindValue(':f', $r['File_Name']);
    if ($ins && $ins->execute() !== false && $del && $del->execute() !== false && @$rw->exec('COMMIT')) {
      $rel = $r['Date'] . '/' . species_folder($r['Sci_Name'] ?? '', $r['Com_Name'], $r['File_Name']);
      $src = deleted_dir() . '/' . $rel . '/' . $r['File_Name'];
      $dst = $home . '/BirdSongs/Extracted/By_Date/' . $rel;
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
  ensure_authenticated(t('You must be authenticated to wipe deleted detections.'));
  $rw = new SQLite3(BS_DB, SQLITE3_OPEN_READWRITE);
  $rw->busyTimeout(5000);
  deleted_table($rw);
  $sci = html_entity_decode($_GET['sci'] ?? '', ENT_QUOTES);
  $where = $sci !== '' ? ' WHERE Sci_Name = :s' : '';
  $lw = removed_loc_sql($_GET['loc'] ?? '');
  if ($lw !== '') $where .= ($where === '' ? ' WHERE ' : ' AND ') . $lw;
  if (!isset($_GET['confirm'])) {
    header('Content-Type: application/json');
    $st = $rw->prepare("SELECT Sci_Name, MAX(Com_Name) AS Com_Name, COUNT(*) AS n FROM deleted_detections$where GROUP BY Sci_Name ORDER BY n DESC");
    if ($sci !== '') $st->bindValue(':s', $sci);
    $res = $st->execute();
    $rows = array();
    while ($res && ($r = $res->fetchArray(SQLITE3_ASSOC))) $rows[] = $r;
    echo json_encode($rows);
    die();
  }
  $st = $rw->prepare("SELECT Date, Sci_Name, Com_Name, File_Name FROM deleted_detections$where");
  if ($sci !== '') $st->bindValue(':s', $sci);
  $res = $st->execute();
  $paths = array();
  $names = array();
  while ($res && ($r = $res->fetchArray(SQLITE3_ASSOC))) {
    $p = deleted_dir() . '/' . $r['Date'] . '/' . species_folder($r['Sci_Name'] ?? '', $r['Com_Name'], $r['File_Name']) . '/' . $r['File_Name'];
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
  if ($names && $rw->querySingle("SELECT 1 FROM sqlite_master WHERE type='table' AND name='detection_reviews'")) {
    foreach (array_chunk($names, 400) as $chunk) {
      $rw->exec("DELETE FROM detection_reviews WHERE File_Name IN ('" . implode("','", array_map('SQLite3::escapeString', $chunk)) . "')");
    }
  }
  $rw->close();
  echo 'OK ' . $wiped;
  die();
}

if(isset($_GET['excludefile'])) {
  ensure_authenticated(t('You must be authenticated to change the protection of files.'));
  if(!file_exists($home."/BirdNET-Pi/config/scripts/disk_check_exclude.txt")) {
    file_put_contents($home."/BirdNET-Pi/config/scripts/disk_check_exclude.txt", "##start\n##end\n");
  }
  // shared with purge_protection.py and the purge scripts: never edit the list while they use it
  // in the station's state/ (2026-10-23 test run: in /tmp, with protected_regular, the web server could not open the
  // lock the station user had created, and a fixed /tmp name can be planted); opened read-only, flock needs no more
  $purge_lock = @fopen(BS_STATE . '/purge.lock', 'r') ?: @fopen(BS_STATE . '/purge.lock', 'c');
  if ($purge_lock) flock($purge_lock, LOCK_EX);
  if(isset($_GET['exclude_add'])) {
    $myfile = fopen($home."/BirdNET-Pi/config/scripts/disk_check_exclude.txt", "a") or die(t('Unable to open file!'));
    $txt = $_GET['excludefile'];
    fwrite($myfile, $txt."\n");
    fwrite($myfile, $txt.".png\n");
    fwrite($myfile, $txt.".jpg\n");
    fclose($myfile);
    echo "OK";
    die();
  } else {
    $lines  = file($home."/BirdNET-Pi/config/scripts/disk_check_exclude.txt");
    $search = $_GET['excludefile'];

    $result = '';
    foreach($lines as $line) {
      if(stripos($line, $search) === false && stripos($line, $search.".png") === false) {
        $result .= $line;
      }
    }
    file_put_contents($home."/BirdNET-Pi/config/scripts/disk_check_exclude.txt", $result);
    echo "OK";
    die();
  }
}

// Review loop: one verdict per detection — yes (confirmed: protected from purge), no (not this bird: left out of
// the best detections and the species page counts), unsure; "clear" removes it
if(isset($_GET['review']) && isset($_GET['verdict'])) {
  ensure_authenticated(t('You must be authenticated to review detections.'));
  $file_name = basename($_GET['review']);
  $verdict = $_GET['verdict'];
  if (!in_array($verdict, array('yes', 'no', 'unsure', 'clear'), true)) { echo "Error"; die(); }
  $rw = new SQLite3(BS_DB, SQLITE3_OPEN_READWRITE);
  $rw->busyTimeout(5000);
  review_table($rw);
  // why a detection is "not this bird" (owner 2026-10-09; dog instead of frog 2026-10-20): insect, dog, rain, human,
  // engine, noise (they replaced "mechanical / noise" 2026-10-22), other bird, unknown
  $reason = preg_match('/^[a-z ]{0,20}$/', $_GET['reason'] ?? '') ? ($_GET['reason'] ?? '') : '';
  if ($verdict === 'clear') {
    $st = $rw->prepare('DELETE FROM detection_reviews WHERE File_Name = :f');
    $st->bindValue(':f', $file_name);
  } else {
    // a detection that came from Change species keeps where it came from (Orig_Sci / Orig_Com, the model's confidence)
    // whatever it is answered later, and a Yes on it stays 'changed' (owner 2026-10-22: the original species' Precision)
    $st = $rw->prepare("INSERT OR REPLACE INTO detection_reviews (File_Name, Sci_Name, Com_Name, Date, Confidence, Verdict, Reviewed_At, Reason, Orig_Sci, Orig_Com)
      SELECT d.File_Name, d.Sci_Name, d.Com_Name, d.Date, CASE WHEN o.Orig_Sci IS NOT NULL AND o.Orig_Sci <> '' THEN o.Confidence ELSE d.Confidence END,
        :v, datetime('now', 'localtime'),
        CASE WHEN :v = 'no' THEN :r WHEN :v = 'yes' AND o.Orig_Sci IS NOT NULL AND o.Orig_Sci <> '' THEN 'changed' ELSE '' END, o.Orig_Sci, o.Orig_Com
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
    $d = $rw->querySingle("SELECT Date, Sci_Name, Com_Name, File_Name FROM detections WHERE File_Name = '" . SQLite3::escapeString($file_name) . "' LIMIT 1", true);
    if ($d) {
      $rel = $d['Date'] . '/' . species_folder($d['Sci_Name'] ?? '', $d['Com_Name'], $d['File_Name']);
      $src = $home . '/BirdSongs/Extracted/Purge/' . $rel . '/' . $file_name;
      if (file_exists($src)) {
        $dst = $home . '/BirdSongs/Extracted/By_Date/' . $rel;
        $files = array_filter(array($src, $src . '.png', $src . '.jpg'), 'file_exists');
        exec(station_prefix() . 'mkdir -p ' . escapeshellarg($dst) . ' && ' . station_prefix() . 'mv -f '
          . implode(' ', array_map('escapeshellarg', $files)) . ' ' . escapeshellarg($dst . '/'));
      }
    }
  }
  $rw->close();
  echo $ok ? "OK" : 'Error - ' . t('detection not found');
  die();
}

// Rejection in a batch (owner 2026-10-09): every detection of a species in one hour of one day that has no review
// yet becomes "not this bird" with the same reason (rain, dogs, insects...). Answers how many were marked.
if (isset($_GET['review_batch'], $_GET['sci'], $_GET['date'], $_GET['hour'])) {
  ensure_authenticated(t('You must be authenticated to review detections.'));
  $hour = intval($_GET['hour']);
  if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['date']) || $hour < 0 || $hour > 23) { echo "Error"; die(); }
  $reason = preg_match('/^[a-z ]{0,20}$/', $_GET['reason'] ?? '') ? ($_GET['reason'] ?? '') : '';
  $rw = new SQLite3(BS_DB, SQLITE3_OPEN_READWRITE);
  $rw->busyTimeout(5000);
  review_table($rw);
  // only the clips the player listed and counted for the user (files, one per line; 2026-10-23 run 3: without it the
  // whole hour of every location was marked, 103 where the label said 26)
  $files = array_slice(array_values(array_filter(array_map(function ($f) { return basename(trim($f)); }, explode("\n", $_GET['files'] ?? '')), 'strlen')), 0, 500);
  if (!$files) { echo 'OK 0'; die(); }
  $in = implode(',', array_map(function ($i) { return ':f' . $i; }, array_keys($files)));
  $st = $rw->prepare("INSERT OR IGNORE INTO detection_reviews (File_Name, Sci_Name, Com_Name, Date, Confidence, Verdict, Reviewed_At, Reason)
    SELECT File_Name, Sci_Name, Com_Name, Date, Confidence, 'no', datetime('now', 'localtime'), :r FROM detections
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
    exec(station_prefix() . 'nohup ' . escapeshellarg($home . '/BirdNET-Pi/birdnet/bin/python3') . ' '
      . escapeshellarg($home . '/BirdNET-Pi/scripts/review_worker.py') . ' > /dev/null 2>&1 &');
    for ($i = 0; $wait && $i < 60 && $out === false; $i++) { usleep(250000); $out = @file_get_contents($url, false, $ctx); }
  }
  $ok = isset($http_response_header[0]) && strpos($http_response_header[0], ' 200') !== false;
  return $ok ? $out : false;
}
function review_clip_path($f) {
  global $home;
  if (strpos($f, '..') !== false) return false;
  $clip = $home . '/BirdSongs/Extracted/By_Date/' . $f;
  if (is_file($clip)) return $clip;
  // a clip staged for the purge (answered with a cause) is drawn from there (2026-10-23)
  $staged = $home . '/BirdSongs/Extracted/Purge/' . $f;
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
  ensure_authenticated(t('You must be authenticated to review detections.'));
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
    $labels = file('./scripts/labels.txt', FILE_IGNORE_NEW_LINES);
    echo json_encode($labels);
    die();
}

if(isset($_GET['changefile']) && isset($_GET['newname'])) {
  ensure_authenticated(t('You must be authenticated to delete files.'));
  if (preg_match('~^.*(\.\.\/).+$~', $_GET['changefile'])) {
    echo "Error";
    die();
  }
  // the sanitize filter of this file turned quotes into entities: undo it, and both names reach the shell escaped (owner
  // 2026-10-22: a name with an apostrophe failed, and a crafted one could run commands)
  $oldname = basename(html_entity_decode($_GET['changefile'], ENT_QUOTES));
  $newname = html_entity_decode($_GET['newname'], ENT_QUOTES);
  $old = get_db()->querySingle("SELECT Sci_Name, Com_Name, Confidence FROM main.detections WHERE File_Name = '" . SQLite3::escapeString($oldname) . "'", true) ?: array();
  $old_com = (string)($old['Com_Name'] ?? '');
  // changed before: the species the model gave stays the origin (A to B to C keeps A, with the model's confidence)
  $prev = get_db()->querySingle("SELECT Orig_Sci, Orig_Com, Confidence FROM main.detection_reviews WHERE File_Name = '" . SQLite3::escapeString($oldname) . "' AND Orig_Sci IS NOT NULL AND Orig_Sci <> ''", true) ?: array();
  if (!exec(station_prefix() . '' . escapeshellarg($home . '/BirdNET-Pi/scripts/birdnet_changeidentification.sh') . ' ' . escapeshellarg($oldname) . ' ' . escapeshellarg($newname) . ' log_errors 2>&1', $output)) {
    // Change species is an answer too (owner 2026-10-22): the renamed detection keeps a review Yes with the reason
    // 'changed', so the Review button reads Changed and the statistics know how many were changed (the new file name
    // follows birdnet_changeidentification.sh)
    // the same name birdnet_changeidentification.sh gives: <NewSci> + the old name from its date on
    $new_file = name_safe(explode('_', $newname, 2)[0]) . clip_rest($oldname);
    $rw = new SQLite3(BS_DB, SQLITE3_OPEN_READWRITE);
    $rw->busyTimeout(5000);
    review_table($rw);
    $del = $rw->prepare('DELETE FROM detection_reviews WHERE File_Name = :f');
    $del->bindValue(':f', $oldname);
    $del->execute();
    // the review keeps the confidence the model gave (the script zeroes the detection's) and the species it came from
    $ins = $rw->prepare("INSERT OR REPLACE INTO detection_reviews (File_Name, Sci_Name, Com_Name, Date, Confidence, Verdict, Reviewed_At, Reason, Orig_Sci, Orig_Com)
      SELECT File_Name, Sci_Name, Com_Name, Date, :c, 'yes', datetime('now', 'localtime'), 'changed', :os, :oc FROM main.detections WHERE File_Name = :f LIMIT 1");
    $ins->bindValue(':f', $new_file);
    $ins->bindValue(':c', floatval($prev ? $prev['Confidence'] : ($old['Confidence'] ?? 0)));
    $ins->bindValue(':os', (string)($prev ? $prev['Orig_Sci'] : ($old['Sci_Name'] ?? '')));
    $ins->bindValue(':oc', (string)($prev ? $prev['Orig_Com'] : $old_com));
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

$shifted_path = $home."/BirdSongs/Extracted/By_Date/shifted/";

if(isset($_GET['shiftfile'])) {
  ensure_authenticated(t('You cannot shift files for this installation'));

    // a clip of By_Date only: <date>/<species folder>/<file>, no "..", and every shell argument quoted (2026-10-23
    // security run: the folder part went unquoted into a root shell)
    $filename = html_entity_decode((string)$_GET['shiftfile'], ENT_QUOTES);
    if (strpos($filename, '..') !== false || !preg_match('~^\d{4}-\d{2}-\d{2}/[^/]+/[^/]+$~', $filename)
        || !is_file($home . "/BirdSongs/Extracted/By_Date/" . $filename)) { http_response_code(400); die(t('Invalid file')); }
    $pp = pathinfo($filename);
    $dir = $pp['dirname'];
    $fn  = $pp['filename'];
    $ext = $pp['extension'];
    $pi = $home."/BirdSongs/Extracted/By_Date/";

    if(isset($_GET['doshift'])) {
  $freqshift_tool = $config['FREQSHIFT_TOOL'];

  if ($freqshift_tool == "ffmpeg") {
    // the shifted copy is the station user's file like the clip itself (no root, owner 2026-10-23)
    $cmd = station_prefix() . "/usr/bin/nohup /usr/bin/ffmpeg -y -i ".escapeshellarg($pi.$filename)." -af ".escapeshellarg("rubberband=pitch=".floatval($config['FREQSHIFT_LO'])."/".floatval($config['FREQSHIFT_HI']))." ".escapeshellarg($shifted_path.$filename)."";
    shell_exec(station_prefix() . "mkdir -p ".escapeshellarg($shifted_path.$dir)." && ".$cmd);

  } else if ($freqshift_tool == "sox") {
    //linux.die.net/man/1/sox
    $soxopt = "-q";
    $soxpitch = $config['FREQSHIFT_PITCH'];
    $cmd = station_prefix() . "/usr/bin/nohup /usr/bin/sox ".escapeshellarg($pi.$filename)." ".escapeshellarg($shifted_path.$filename)." pitch ".$soxopt." ".intval($soxpitch);
   shell_exec(station_prefix() . "mkdir -p ".escapeshellarg($shifted_path.$dir)." && ".$cmd);
  }
    } else {
     $cmd = station_prefix() . "rm -f " . escapeshellarg($shifted_path.$filename);
     shell_exec($cmd);
    }

    echo "OK";
    die();
}

if(isset($_GET['bydate'])){
  $statement = $db->prepare('SELECT DISTINCT(Date) FROM detections GROUP BY Date ORDER BY Date DESC');
  ensure_db_ok($statement);
  $result = $statement->execute();
  $view = "bydate";

  #Specific Date
} elseif(isset($_GET['date'])) {
  $date = $_GET['date'];
  session_start();
  $_SESSION['date'] = $date;
  $result = fetch_species_array($_GET['sort'], $date);
  $view = "date";

  #By Species
} elseif(isset($_GET['byspecies'])) {
  $result = fetch_species_array($_GET['sort']);
  $view = "byspecies";

  #Specific Species
} elseif(isset($_GET['species'])) {
  $species = htmlspecialchars_decode($_GET['species'], ENT_QUOTES);
  session_start();
  $_SESSION['species'] = $species;
  $result2 = fetch_all_detections($species, $_GET['sort'], $_SESSION['date']);
  $view = "species";
} else {
  // no choice made: open by species (owner 2026-10-08); a single button switches to by date
  unset($_SESSION['species']);
  unset($_SESSION['date']);
  $result = fetch_species_array($_GET['sort']);
  $view = "byspecies";
}

if (get_included_files()[0] === __FILE__) {
  echo '<!DOCTYPE html>
<html lang="en">
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

<?php
#If no specific species
if(!isset($_GET['species']) && !isset($_GET['filename'])){
?>
<div class="play">
<?php if(in_array($view, array("byspecies", "bydate", "date"), true)) {
  // top left: only the button to the other way of browsing
  // two buttons, By Species / By Date: the current way of browsing highlighted, the other greyed
  $by_date = $view == "bydate"; ?>
<form action="views.php" method="GET" style="float:left;margin:6px 8px;">
  <input type="hidden" name="view" value="Recordings">
  <button type="submit" name="byspecies" value="byspecies" class="sortbutton modebutton<?php echo $by_date ? ' inactive' : ' active'; ?>"><?php echo t('By Species'); ?></button>
  <button type="submit" name="bydate" value="bydate" class="sortbutton modebutton<?php echo $by_date ? ' active' : ' inactive'; ?>"><?php echo t('By Date'); ?></button>
</form>
<?php } ?>
<?php if($view == "byspecies" || $view == "date") { ?>
<div class="sortbar" style="width: auto; text-align: right; margin: 0 8px;">
   <form action="views.php" method="GET">
      <input type="hidden" name="view" value="Recordings">
      <input type="hidden" name="<?php echo $view; ?>" value="<?php echo $_GET['date']; ?>">
      <span class="sortlabel"><?php echo t('Sort by:'); ?></span>
      <button <?php if(!isset($_GET['sort']) || $_GET['sort'] == "alphabetical"){ echo "class='sortbutton active'";} else { echo "class='sortbutton'"; }?> type="submit" name="sort" value="alphabetical">
         <span title="<?php echo t('Sort by alphabetical'); ?>"><?php echo t('Name'); ?></span>
      </button>
      <button <?php if(isset($_GET['sort']) && $_GET['sort'] == "occurrences"){ echo "class='sortbutton active'";} else { echo "class='sortbutton'"; }?> type="submit" name="sort" value="occurrences">
         <span title="<?php echo t('Sort by occurrences'); ?>"><?php echo t('Occur.'); ?></span>
      </button>
      <button <?php if(isset($_GET['sort']) && $_GET['sort'] == "confidence"){ echo "class='sortbutton active'";} else { echo "class='sortbutton'"; }?> type="submit" name="sort" value="confidence">
         <span title="<?php echo t('Sort by confidence'); ?>"><?php echo t('Conf.'); ?></span>
      </button>
      <button <?php if(isset($_GET['sort']) && $_GET['sort'] == "date"){ echo "class='sortbutton active'";} else { echo "class='sortbutton'"; }?> type="submit" name="sort" value="date">
         <span title="<?php echo t('Sort by date'); ?>"><?php echo t('Date'); ?></span>
      </button>
   </form>
</div>
<?php } ?>
<div style="clear:both"></div>
<form action="views.php" method="GET">
<input type="hidden" name="view" value="Recordings">
<table>
<?php
  #By Date
  if($view == "bydate") {
    while($results=$result->fetchArray(SQLITE3_ASSOC)){
      $date = $results['Date'];
      if(realpath($home."/BirdSongs/Extracted/By_Date/".$date) !== false){
        echo "<td>
          <button action=\"submit\" name=\"date\" value=\"$date\">".($date == date('Y-m-d') ? t('Today') : $date)."</button></td></tr>";}}

          #By Species
  } elseif($view == "byspecies") {
    $birds = array();
    $values = array();
    while($results=$result->fetchArray(SQLITE3_ASSOC))
    {
      $birds[] = $results['Sci_Name'];
      $values[] = get_label($results, $_GET['sort']);
    }

    if(count($birds) > 45) {
      $num_cols = 3;
    } else {
      $num_cols = 1;
    }
    $num_rows = ceil(count($birds) / $num_cols);

    for ($row = 0; $row < $num_rows; $row++) {
      echo "<tr>";

      for ($col = 0; $col < $num_cols; $col++) {
        $index = $row + $col * $num_rows;

        if ($index < count($birds)) {
          ?>
          <td class="spec">
              <button type="submit" name="species" value="<?php echo $birds[$index];?>"><?php echo $values[$index];?></button>
          </td>
          <?php
        } else {
          echo "<td></td>";
        }
      }

      echo "</tr>";
    }
  } elseif($view == "date") {
    $birds = array();
    $values = array();
while($results=$result->fetchArray(SQLITE3_ASSOC))
{
  // the day's folder of the species, new layout (scientific name) or old (common name)
  $day = $home."/BirdSongs/Extracted/By_Date/".$date."/";
  if(is_dir($day . name_safe($results['Sci_Name'])) || is_dir($day . name_safe($results['Com_Name']))){
    $birds[] = $results['Sci_Name'];
    $values[] = get_label($results, $_GET['sort'], $_GET['date']);
  }
}

if(count($birds) > 45) {
  $num_cols = 3;
} else {
  $num_cols = 1;
}
$num_rows = ceil(count($birds) / $num_cols);

for ($row = 0; $row < $num_rows; $row++) {
  echo "<tr>";

  for ($col = 0; $col < $num_cols; $col++) {
    $index = $row + $col * $num_rows;

    if ($index < count($birds)) {
      ?>
      <td class="spec">
          <button type="submit" name="species" value="<?php echo $birds[$index];?>"><?php echo $values[$index];?></button>
      </td>
      <?php
    } else {
      echo "<td></td>";
    }
  }

  echo "</tr>";
}

    #Choose
  } else {
    echo "<td>
      <button action=\"submit\" name=\"byspecies\" value=\"byspecies\">" . t('By Species') . "</button></td></tr>
      <tr><td><button action=\"submit\" name=\"bydate\" value=\"bydate\">" . t('By Date') . "</button></td>";
  } 

  echo "</table></form>";
}

#Specific Species
if(isset($_GET['species'])){ ?>
<div style="width: auto;
   text-align: center">
   <form action="views.php" method="GET">
      <input type="hidden" name="view" value="Recordings">
      <input type="hidden" name="species" value="<?php echo $_GET['species']; ?>">
      <input type="hidden" name="sort" value="<?php echo $_GET['sort']; ?>">
      <span class="sortlabel"><?php echo t('Sort by:'); ?></span>
      <button <?php if(!isset($_GET['sort']) || $_GET['sort'] == "" || $_GET['sort'] == "date"){ echo "class='sortbutton active'";} else { echo "class='sortbutton'"; }?> type="submit" name="sort" value="date">
         <span title="<?php echo t('Sort by date'); ?>"><?php echo t('Date'); ?></span>
      </button>
      <button <?php if(isset($_GET['sort']) && $_GET['sort'] == "confidence"){ echo "class='sortbutton active'";} else { echo "class='sortbutton'"; }?> type="submit" name="sort" value="confidence">
         <span title="<?php echo t('Sort by confidence'); ?>"><?php echo t('Conf.'); ?></span>
      </button><br>
      <label style="cursor: pointer; margin-top: 10px; margin-bottom: 10px;font-weight: normal; display: inline-flex; align-items: center; justify-content: center;">
        <input type="checkbox" name="only_excluded" <?= isset($_GET['only_excluded']) ? 'checked' : '' ?> onchange="submit()" style="display:none;">
        <span style="width: 40px; height: 20px; background: <?= isset($_GET['only_excluded']) ? '#555555' : 'rgba(85, 85, 85, 0.3)' ?>; border: 1px solid #777777; border-radius: 20px; display: inline-block; position: relative; margin-right: 8px; transition: background 0.4s, border 0.4s; box-sizing: border-box;">
        <span style="width: 16px; height: 16px; background: white; border-radius: 50%; position: absolute; top: 1.5px; left: 2px; transition: 0.4s; display: flex; align-items: center; justify-content: center; font-size: 14px; color: black; <?= isset($_GET['only_excluded']) ? 'transform: translateX(20px);' : '' ?>">
        <?= isset($_GET['only_excluded']) ? '✓' : '' ?>
      </span></span><?php echo t('Only Show Purge Excluded'); ?></label>
   </form>
</div>
<?php
  // add disk_check_exclude.txt lines into an array for grepping
  $fp = @fopen($home."/BirdNET-Pi/config/scripts/disk_check_exclude.txt", 'r'); 
if ($fp) {
  $disk_check_exclude_arr = explode("\n", fread($fp, filesize($home."/BirdNET-Pi/config/scripts/disk_check_exclude.txt")));
} else {
  $disk_check_exclude_arr = [];
}

$name = htmlspecialchars_decode($_GET['species'], ENT_QUOTES);
$limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 40;

$result2 = fetch_all_detections($name, $_GET['sort'], $_SESSION['date']);
$results=$result2->fetchArray(SQLITE3_ASSOC);
$com_name = $results['Com_Name'];
$result2->reset(); // reset the pointer to the beginning of the result set
$sciname = $name;
$info_url = get_info_url($sciname);
$url = $info_url['URL'];
echo "<table>
  <tr><th>" . species_title($sciname, $com_name) . "</th></tr>";
  $iter=0;
  while($results=$result2->fetchArray(SQLITE3_ASSOC))
  {
    $comname = species_folder($results['Sci_Name'] ?? '', $results['Com_Name'], $results['File_Name']);
    $date = $results['Date'];
    $filename = "/By_Date/".$date."/".$comname."/".$results['File_Name'];
    $filename_shifted = "/By_Date/shifted/".$date."/".$comname."/".$results['File_Name'];
    $filename_png = clip_spectrogram($filename);
    $sciname = preg_replace('/ /', '_', $results['Sci_Name']);
    $sci_name = $results['Sci_Name'];
    $time = $results['Time'];
    $values = round((float)round($results['Confidence'],2) * 100 ) . '%';
    $filename_formatted = $date."/".$comname."/".$results['File_Name'];

    // file was deleted by disk check, no need to show the detection in recordings
    if(!file_exists($home."/BirdSongs/Extracted/".$filename)) {
      continue;
    }
    if(!in_array($filename_formatted, $disk_check_exclude_arr) && isset($_GET['only_excluded'])) {
      continue;
    }
    $iter++;
    if($iter > $limit) {
      $iter_additional=true;
      break;
    }

    if($iter < 100){
      $imageelem = "<div class='custom-audio-player' data-audio-src=\"$filename\" data-image-src=\"$filename_png\"></div>";
    } else {
      $imageelem = "<a href=\"$filename\"><img src=\"$filename_png\"></a>";
    }

      if(!in_array($filename_formatted, $disk_check_exclude_arr)) {
        $imageicon = "images/unlock.svg";
        $title = "This file will be deleted when disk space needs to be freed (>95% usage).";
        $type = "add";
      } else {
        $imageicon = "images/lock.svg";
        $title = "This file is excluded from being purged.";
        $type = "del";
      }

      if(file_exists($shifted_path.$filename_formatted)) {
        $shiftImageIcon = "images/unshift.svg";
        $shiftTitle = "This file has been shifted down in frequency."; 
        $shiftAction = "unshift";
  $filename = $filename_shifted;
      } else {
        $shiftImageIcon = "images/shift.svg";
        $shiftTitle = "This file is not shifted in frequency.";
        $shiftAction = "shift";
      }

      echo "<tr>
  <td class=\"relative\"" . review_item_attrs($filename_formatted, $date . ' ' . $time, $sciname ?? '') . "> " . detection_actions($filename_formatted) . "$date $time<br>$values<br>

        ".$imageelem."
        </td>
        </tr>";

  }if($iter == 0){ echo "<tr><td><b>" . t('No detections were found.') . "</b><br><br><span style='font-size:medium'>" . t('They may have been deleted to make space for new detections. You can prevent this from happening in the future by clicking the {icon} icon in the top right of a detection.', array('icon' => "<img src='images/unlock.svg' style='width:20px'>")) . "<br>" . t('You can also modify this behavior globally under "Full Disk Behavior" <a href=\'views.php?view=Advanced\'>here.</a>') . "</span></td></tr>";}echo "</table>";}

  if ($iter_additional) {
    echo "<div style='text-align:center'>";
    echo "<form action='views.php' method='GET' style='display:inline'>";
    echo "<input type='hidden' name='view' value='Recordings'>";
    echo "<input type='hidden' name='species' value=\"" . htmlspecialchars($_GET['species'], ENT_QUOTES) . "\">";
    if(isset($_GET['sort'])) {
      echo "<input type='hidden' name='sort' value=\"" . htmlspecialchars($_GET['sort'], ENT_QUOTES) . "\">";
    }
    if(isset($_GET['only_excluded'])) {
      echo "<input type='hidden' name='only_excluded' value='" . $_GET['only_excluded'] . "'>";
    }
    if(isset($_SESSION['date'])) {
      echo "<input type='hidden' name='date' value='" . $_SESSION['date'] . "'>";
    }
    echo "<input type='hidden' name='limit' value='" . ($limit + 40) . "'>";
    echo "<button type='submit' class='loadmore'>" . t('Load 40 more...') . "</button>";
    echo "</form>";
    echo "</div>";
  }

  if(isset($_GET['filename'])){
    $name = $_GET['filename'];
    // a bound value, never the request text inside the SQL (2026-10-23 test run)
    $statement2 = $db->prepare("SELECT * FROM detections where File_name == :name ORDER BY Date DESC, Time DESC");
    ensure_db_ok($statement2);
    $statement2->bindValue(':name', html_entity_decode($name, ENT_QUOTES), SQLITE3_TEXT);
    $result2 = $statement2->execute();
    $results = $result2->fetchArray(SQLITE3_ASSOC);
    $sciname = $results['Sci_Name'];
    $result2->reset();
    $info_url = get_info_url($sciname);
    $url = $info_url['URL'];
    echo "<table>
      <tr><th>" . species_title($sciname, htmlspecialchars($results['Com_Name'])) . "</th></tr>";
      while($results=$result2->fetchArray(SQLITE3_ASSOC))
      {
        $comname = species_folder($results['Sci_Name'] ?? '', $results['Com_Name'], $results['File_Name']);
        $date = $results['Date'];
        $filename = "/By_Date/".$date."/".$comname."/".$results['File_Name'];
        $filename_shifted = "/By_Date/shifted/".$date."/".$comname."/".$results['File_Name'];
        $filename_png = clip_spectrogram($filename);
        $sciname = preg_replace('/ /', '_', $results['Sci_Name']);
        $sci_name = $results['Sci_Name'];
        $time = $results['Time'];
        $values = round((float)round($results['Confidence'],2) * 100 ) . '%';
        $filename_formatted = $date."/".$comname."/".$results['File_Name'];

        // add disk_check_exclude.txt lines into an array for grepping
        $fp = @fopen($home."/BirdNET-Pi/config/scripts/disk_check_exclude.txt", 'r');
        if ($fp) {
          $disk_check_exclude_arr = explode("\n", fread($fp, filesize($home."/BirdNET-Pi/config/scripts/disk_check_exclude.txt")));
        } else {
          $disk_check_exclude_arr = [];
        }

          if(!in_array($filename_formatted, $disk_check_exclude_arr)) {
            $imageicon = "images/unlock.svg";
            $title = "This file will be deleted when disk space needs to be freed (>95% usage).";
            $type = "add";
          } else {
            $imageicon = "images/lock.svg";
            $title = "This file is excluded from being purged.";
            $type = "del";
          }

      if(file_exists($shifted_path.$filename_formatted)) {
        $shiftImageIcon = "images/unshift.svg";
        $shiftTitle = "This file has been shifted down in frequency."; 
        $shiftAction = "unshift";
  $filename = $filename_shifted;
      } else {
        $shiftImageIcon = "images/shift.svg";
        $shiftTitle = "This file is not shifted in frequency.";
        $shiftAction = "shift";
      }

          echo "<tr>
      <td class=\"relative\"" . review_item_attrs($filename_formatted, $date . ' ' . $time, $sciname ?? '') . "> " . detection_actions($filename_formatted) . "$date $time<br>$values<br>

<div class='custom-audio-player' data-audio-src='$filename' data-image-src='$filename_png'></div>
</td></tr>";

      }echo "</table>";}
      echo "</div>";
if (get_included_files()[0] === __FILE__) {
  echo '</html>';
}
