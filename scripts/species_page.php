<?php
/* Species page (owner 2026-10-08): everything about one bird in one place — names and links, totals, the last
 * year as a calendar, months and hours of activity, the location model's expected season (V3 geo model,
 * region_profile.json), the best clips, the latest detections, the review verdicts with a suggested species
 * threshold, and the Species Settings (editable threshold, notification tier, lists).
 * Opened as views.php?view=Bird&sci=<scientific name>. */
require_once __DIR__ . '/common.php';
$home = get_home();
$config = get_config();
$sci = trim(html_entity_decode($_GET['sci'] ?? '', ENT_QUOTES));
$h = function ($s) { return htmlspecialchars((string)$s, ENT_QUOTES); };
$db = get_db();

if ($sci === '') {
  // the species list is scripts/species_tools.php (Species Pages = the old Species Management, owner 2026-10-09)
  include __DIR__ . '/species_tools.php';
  return;
}
$nr = not_rejected_sql();
$q = function ($sql, $one = false) use ($db, $sci) {
  $st = $db->prepare($sql);
  ensure_db_ok($st);
  $st->bindValue(':sci', $sci);
  $res = $st->execute();
  if ($one) return $res->fetchArray(SQLITE3_ASSOC);
  $rows = array();
  while ($r = $res->fetchArray(SQLITE3_ASSOC)) $rows[] = $r;
  return $rows;
};
// one pass over the species' detections for the summary, the days, the 48 BirdNET weeks and the hours (owner
// 2026-10-23: four queries over the same rows took over a second on a species with 26 000 detections)
$sum = array('n' => 0, 'first' => null, 'last' => null, 'days' => 0, 'maxc' => null, 'avgc' => null, 'minc' => null, 'com' => '');
$per_day = array();
$weeks = array_fill(1, 48, 0);
$hours = array_fill(0, 24, 0);
$all_days = array();
$day_from = date('Y-m-d', strtotime('-370 days'));
$csum = 0.0;
// grouped per day and hour in SQLite from the covering species index, the days before today kept per month (common.php
// cached_past_months), the rejected ones taken off afresh; the extremes by index seeks (2026-10-23 run 3: 300 000 rows
// of a species at ten times the station's data were read one by one into PHP, 3.6 s)
$hour_rows = function ($where, $p) use ($db, $sci) {
  $st = $db->prepare("SELECT Date, CAST(substr(Time, 1, 2) AS INT), COUNT(*), TOTAL(Confidence) FROM detections WHERE Sci_Name = :sci AND $where GROUP BY Date, 2");
  $st->bindValue(':sci', $sci);
  foreach ($p as $k => $v) $st->bindValue($k, $v);
  $out = array();
  $res = $st->execute();
  while ($res && ($r = $res->fetchArray(SQLITE3_NUM))) $out[] = $r;
  return $out;
};
$today = date('Y-m-d');
$months = past_month_changes();
$chunks = $months !== null
  ? cached_past_months('species_hours|' . $sci, '', '', $months, function ($lo, $hi) use ($hour_rows) { return $hour_rows('Date BETWEEN :lo AND :hi', array(':lo' => $lo, ':hi' => $hi)); })
  : array($hour_rows('Date < :today', array(':today' => $today)));
$chunks[] = $hour_rows('Date >= :today', array(':today' => $today));
$cells = array();
foreach ($chunks as $chunk) foreach ($chunk as $r) {
  $k = $r[0] . '|' . intval($r[1]);
  if (!isset($cells[$k])) $cells[$k] = array($r[0], intval($r[1]), 0, 0.0);
  $cells[$k][2] += intval($r[2]); $cells[$k][3] += floatval($r[3]);
}
if ($nr !== '') foreach ($hour_rows("+Date >= '0' AND File_Name IN (SELECT File_Name FROM detection_reviews WHERE Verdict = 'no')", array()) as $r) {
  $k = $r[0] . '|' . intval($r[1]);
  if (!isset($cells[$k])) continue;
  $cells[$k][2] -= intval($r[2]); $cells[$k][3] -= floatval($r[3]);
  if ($cells[$k][2] <= 0) unset($cells[$k]);
}
foreach ($cells as $r) {
  list($d, $hh, $n) = $r;
  $sum['n'] += $n;
  $csum += $r[3];
  if ($sum['first'] === null || $d < $sum['first']) $sum['first'] = $d;
  if ($sum['last'] === null || $d > $sum['last']) $sum['last'] = $d;
  $all_days[$d] = 1;
  if ($d >= $day_from) $per_day[$d] = ($per_day[$d] ?? 0) + $n;
  $m = intval(substr($d, 5, 2)); $dd = intval(substr($d, 8, 2));
  if ($m >= 1) $weeks[min(48, ($m - 1) * 4 + min(4, intdiv(max(1, $dd) - 1, 7) + 1))] += $n;
  if ($hh >= 0 && $hh < 24) $hours[$hh] += $n;
}
if ($sum['n'] > 0) {
  $ext = function ($dir) use ($db, $sci, $nr) {
    $st = $db->prepare("SELECT Confidence FROM detections WHERE Sci_Name = :sci $nr ORDER BY Confidence $dir LIMIT 1");
    $st->bindValue(':sci', $sci);
    $r = $st->execute();
    $row = $r ? $r->fetchArray(SQLITE3_NUM) : false;
    return $row ? floatval($row[0]) : null;
  };
  $sum['maxc'] = $ext('DESC');
  $sum['minc'] = $ext('ASC');
  $sum['com'] = species_com_names(array($sci))[$sci];
}
$sum['days'] = count($all_days);
$sum['avgc'] = $sum['n'] ? $csum / $sum['n'] : null;
// a species without detections still opens (owner 2026-10-28: Species Cards › All links every species): its names, the
// location model, the settings and the lists; the name from the model's labels
$none = intval($sum['n']) === 0;
if ($none) $sum['com'] = active_model_labels()[$sci] ?? $sci;
$com = $sum['com'];
// the species' folders: the new layout (scientific name) and the old one (common name) until migrated
$folders = array_unique(array(name_safe($sci), name_safe($com)));
$best = $q("SELECT Date, Time, Com_Name, Confidence, Cutoff, Sens, Overlap, Loc_Thresh, Rec_Length, Sp_Override, File_Name, " . location_columns_sql() . " FROM detections WHERE Sci_Name = :sci $nr ORDER BY Confidence DESC, Date DESC LIMIT 6");
$recent = $q("SELECT Date, Time, Com_Name, Sci_Name, Confidence, Cutoff, Sens, Overlap, Loc_Thresh, Rec_Length, Sp_Override, File_Name, " . location_columns_sql() . " FROM detections WHERE Sci_Name = :sci ORDER BY Date DESC, Time DESC LIMIT 30");
// review mode (&review=1, the Review button of Curation, owner 2026-10-18): the list holds the species' detections
// without an answer yet, and the review player opens on the first one; at the end it goes on to the next species
$review_mode = !empty($_GET['review']) && $nr !== '';
if ($review_mode) {
  // a species not confirmed yet starts with its most confident detection — the likeliest Yes, which confirms it and
  // moves the review on to the next species (quick triage of new occurrences, owner 2026-10-18); a confirmed species
  // is reviewed newest first
  $is_conf = in_array($sci, @file(BS_LISTS . '/confirmed_species_list.txt', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: array(), true);
  // only the detections of the period chosen in Validation (owner 2026-10-22: Today until another one is picked)
  list($rvf, $rvt, $rvs) = validation_period() + array(2 => '');
  $rvp = $rvf !== '' ? " AND Date BETWEEN '" . $rvf . "' AND '" . $rvt . "'" . ($rvs !== '' ? " AND Date || ' ' || Time >= '" . $rvs . "'" : '') : '';
  $recent = $q("SELECT Date, Time, Com_Name, Sci_Name, Confidence, Cutoff, Sens, Overlap, Loc_Thresh, Rec_Length, Sp_Override, File_Name, " . location_columns_sql() . " FROM detections
                WHERE Sci_Name = :sci AND File_Name NOT IN (SELECT File_Name FROM detection_reviews)" . $rvp . " ORDER BY "
                . ($is_conf ? "Date DESC, Time DESC" : "Confidence DESC, Date DESC, Time DESC") . " LIMIT 500");
}

// Reviews and Precision (owner 2026-10-22): the answers of this species, as the Review button names them, and every
// reviewed detection with its confidence, its week's probability of occurrence and whether it was right: Yes = right;
// Dog, Human, Engine, Noise, Ignore (and the older insect, rain, mechanical) and every detection changed to another
// species = false; Review later and the older "not this bird" without a cause stay out
$reviews = array('yes' => 0, 'chg' => 0, 'later' => 0, 'ign' => 0, 'away' => 0);
$prec = array();
$n_conf = array();
$n_prob = array();
$bands_rev = array();
$critical = null;
if ($nr !== '') {
  $has_orig = (bool)$db->querySingle("SELECT 1 FROM pragma_table_info('detection_reviews') WHERE name = 'Orig_Sci'");
  foreach ($q("SELECT Date, Confidence, Verdict, COALESCE(Reason, '') AS Reason FROM detection_reviews WHERE Sci_Name = :sci") as $r) {
    $ok = null;
    if ($r['Verdict'] === 'yes' && $r['Reason'] === 'changed') $reviews['chg']++;
    elseif ($r['Verdict'] === 'yes') { $reviews['yes']++; $ok = 1; }
    elseif ($r['Verdict'] === 'no' && in_array($r['Reason'], array('dog', 'human', 'engine', 'noise', 'ignore', 'insect', 'rain', 'mechanical'), true)) { $reviews['ign']++; $ok = 0; }
    else $reviews['later']++;
    if ($ok !== null) $prec[] = array(floatval($r['Confidence']), location_probability($sci, $r['Date']), $ok);
  }
  if ($has_orig) foreach ($q("SELECT Date, Confidence FROM detection_reviews WHERE Orig_Sci = :sci") as $r) {
    $reviews['away']++;
    $prec[] = array(floatval($r['Confidence']), location_probability($sci, $r['Date']), 0);
  }
  // every detection of the species by 5 % confidence band and by its week's probability (owner 2026-10-27): the
  // precision of a threshold weighs the reviews of each band by how many detections the band really has
  if ($prec) {
    $n_conf = array_fill(0, 21, 0);
    foreach ($q("SELECT MIN(20, CAST(Confidence * 20 AS INTEGER)) AS b, COUNT(*) AS n FROM detections WHERE Sci_Name = :sci GROUP BY b") as $r) $n_conf[max(0, intval($r['b']))] += intval($r['n']);
    foreach ($q("SELECT Date, COUNT(*) AS n FROM detections WHERE Sci_Name = :sci GROUP BY Date") as $r) {
      $p = location_probability($sci, $r['Date']);
      if ($p !== null) $n_prob[] = array($p, intval($r['n']));
    }
  }
  // review by band (owner 2026-10-22): 10 % confidence bands with their right / false answers, the detections still
  // without an answer and up to 10 of them at random to review; the critical band is the one whose precision is the
  // most uncertain (widest Wilson interval) among those with detections waiting, the nearest to the threshold in force
  // on a tie
  $wil = function ($k, $n, $up) { if (!$n) return $up ? 1 : 0; $z = 1.96; $p = $k / $n; $d = 1 + $z * $z / $n;
    $m = $p + $z * $z / (2 * $n); $e = $z * sqrt($p * (1 - $p) / $n + $z * $z / (4 * $n * $n)); return max(0, min(1, ($up ? $m + $e : $m - $e) / $d)); };
  // only the detections whose clip is still on disk (owner 2026-10-22: the nightly clean-up keeps N files per species,
  // the lines stay, and a sample without its audio showed an empty player)
  // (one listing of the species' folders instead of a file test per detection: big species took seconds; shifted
  // clips count too)
  $on_disk = array(); $disk_days = array();
  $globbed = array();
  foreach ($folders as $folder) $globbed = array_merge($globbed, glob($home . '/BirdSongs/Extracted/By_Date/*/' . $folder . '/*') ?: array(), glob($home . '/BirdSongs/Extracted/By_Date/shifted/*/' . $folder . '/*') ?: array());
  foreach ($globbed as $f) {
    if (!preg_match('/\.(png|jpg)$/', $f)) { $on_disk[basename($f)] = 1; $disk_days[basename(dirname(dirname($f)))] = 1; }
  }
  $waiting = array(); $pool = array();
  // only the days that still have clips of the species on disk (2026-10-23 run 3: every unanswered detection of a big
  // species was read, 1.4 s at ten times the station's data)
  $disk_in = implode(',', array_map(function ($d) { return "'" . SQLite3::escapeString($d) . "'"; }, array_keys(array_filter($disk_days, function ($d) { return preg_match('/^\d{4}-\d{2}-\d{2}$/', $d); }, ARRAY_FILTER_USE_KEY))));
  foreach ($disk_in === '' ? array() : $q("SELECT File_Name, Confidence FROM detections WHERE Sci_Name = :sci AND Date IN ($disk_in) AND File_Name NOT IN (SELECT File_Name FROM detection_reviews)") as $r) {
    if (!isset($on_disk[$r['File_Name']])) continue;
    $bi = min(9, intval(floatval($r['Confidence']) * 10));
    $waiting[$bi] = ($waiting[$bi] ?? 0) + 1;
    $pool[$bi][] = $r['File_Name'];
  }
  $in_force = floatval($config['CONFIDENCE'] ?? 0.7);  // the species' own threshold when it has one (species_confidence.txt)
  foreach (@file(get_home() . '/BirdNET-Pi/config/species_lists/species_confidence.txt', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: array() as $l) {
    $kv = explode('=', trim($l), 2);
    if ($kv[0] === $sci && isset($kv[1])) $in_force = floatval($kv[1]);
  }
  for ($i = 0; $i < 10; $i++) {
    $y = 0; $f = 0;
    foreach ($prec as $r) if (min(9, intval($r[0] * 10)) === $i) { if ($r[2]) $y++; else $f++; }
    $w = $waiting[$i] ?? 0;
    if (!$y && !$f && !$w) continue;
    $smp = $pool[$i] ?? array();
    shuffle($smp);
    $smp = array_slice($smp, 0, 10);
    // the full rows only for the samples drawn
    $smp = $smp ? $q("SELECT Date, Time, Com_Name, Sci_Name, Confidence, Cutoff, Sens, Overlap, Loc_Thresh, Rec_Length, Sp_Override, File_Name, " . location_columns_sql()
      . " FROM detections WHERE Sci_Name = :sci AND File_Name IN ('" . implode("','", array_map(array('SQLite3', 'escapeString'), $smp)) . "') ORDER BY Confidence") : array();
    $bands_rev[$i] = array('y' => $y, 'f' => $f, 'w' => $w, 'width' => $wil($y, $y + $f, true) - $wil($y, $y + $f, false), 'samples' => $smp);
  }
  $critical = null;
  foreach ($bands_rev ?? array() as $i => $b) {
    if (!$b['w']) continue;
    if ($critical === null || $b['width'] > $bands_rev[$critical]['width'] + 1e-9
        || (abs($b['width'] - $bands_rev[$critical]['width']) < 1e-9 && abs(($i + 0.5) / 10 - $in_force) < abs(($critical + 0.5) / 10 - $in_force))) $critical = $i;
  }
}

// station settings for the species
$read_kv = function ($file) {
  $out = array();
  foreach (is_file($file) ? file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) : array() as $l) {
    $p = explode('=', trim($l), 2);
    if (count($p) === 2) $out[$p[0]] = $p[1];
  }
  return $out;
};
$in_list = function ($file) use ($sci) {
  foreach (is_file($file) ? file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) : array() as $l) {
    if (explode('_', trim($l), 2)[0] === $sci) return true;
  }
  return false;
};
$threshold = $read_kv($home . '/BirdNET-Pi/config/species_lists/species_confidence.txt')[$sci] ?? null;
$min_prob = $read_kv($home . '/BirdNET-Pi/config/species_lists/species_min_prob.txt')[$sci] ?? null;  // Min. Prob. of the species (owner 2026-10-22)
$max_files = $read_kv($home . '/BirdNET-Pi/config/species_lists/species_max_files.txt')[$sci] ?? null; // files to keep, empty = global (owner 2026-10-21)
$default_tier = strtolower($config['NOTIFICATION_DEFAULT_TIER'] ?? 'normal');
if (!in_array($default_tier, array('normal', 'muted', 'rare'), true)) $default_tier = 'normal';
$tier = strtolower($read_kv($home . '/BirdNET-Pi/config/species_lists/notification_tiers.txt')[$sci] ?? $default_tier);
$lists = array();
foreach (array('Confirmed' => 'confirmed_species_list.txt', 'Custom list' => 'include_species_list.txt',
               'Whitelist' => 'whitelist_species_list.txt', 'Excluded' => 'exclude_species_list.txt') as $label => $f) {
  if ($in_list(BS_LISTS . '/' . $f)) $lists[] = $label;
}

// expected season at this location (rarity.py profile, 48 BirdNET weeks)
$profile = null;
$pf = __DIR__ . '/region_profile.json';
if (is_file($pf)) {
  $p = json_decode(file_get_contents($pf), true);
  if (isset($p['data'][$sci])) $profile = $p['data'][$sci];
}
$week48 = min(48, (intval(date('n')) - 1) * 4 + min(4, intdiv(intval(date('j')) - 1, 7) + 1));
// the location model's probability for this week (same value as the Probability column of the Species Pages list)
$model_prob = $profile ? floatval($profile[$week48 - 1]) : null;
if ($model_prob === null) {
  $out = (string)shell_exec(station_prefix() . '' . escapeshellarg($home . '/BirdNET-Pi/birdnet/bin/python3') . ' '
    . escapeshellarg($home . '/BirdNET-Pi/scripts/species.py') . ' --threshold 0 2>/dev/null');
  foreach (explode("\n", $out) as $l) {
    if (preg_match('/^(.*)\s-\s([0-9.]+)\s*$/', trim($l), $m) && explode('_', trim($m[1]), 2)[0] === $sci) { $model_prob = floatval($m[2]); break; }
  }
}
$sf_thresh = floatval($config['SF_THRESH'] ?? 0);
// list membership for the switches above the best detections
$identifier = $sci . '_' . $com;
$in_confirmed = $in_list(BS_LISTS . '/confirmed_species_list.txt');
$in_whitelist = $in_list(BS_LISTS . '/whitelist_species_list.txt');
$in_exclude = $in_list(BS_LISTS . '/exclude_species_list.txt');

$bar = function ($values, $labels, $title, $now = null) use ($h) {
  $max = max(1, max($values));
  $html = '<div class="sp-bars" title="' . $h($title) . '">';
  foreach ($values as $k => $v) {
    $pct = $v > 0 ? max(3, round(100 * $v / $max)) : 0;
    $html .= '<div class="sp-bar' . ($k === $now ? ' now' : '') . '" title="' . $h($labels[$k] . ': ' . $v) . '"><span style="height:' . $pct . '%"></span></div>';
  }
  return $html . '</div>';
};
?>
<style>
.sp { max-width: 1000px; margin: 0 auto; text-align: left; padding: 0 12px; }
.sp h2 { margin-bottom: 0; }
.sp .grid { display: flex; flex-wrap: wrap; gap: 12px; margin-bottom: 12px; }
.sp .charts { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; margin-bottom: 12px; }
@media (max-width: 700px) { .sp .charts { grid-template-columns: 1fr; } }
.sp h3.section { font-size: 1.3em; margin: 22px 0 12px; }
.sp .spset label { display: flex; align-items: center; gap: 6px; margin: 5px 0; cursor: pointer; font-weight: normal; } /* only the title bold (owner 2026-10-21) */
.sp .spset input[type=checkbox] { width: 18px; height: 18px; cursor: pointer; margin: 3px; }  /* 24 px between targets (WCAG 2.5.8) */
.sp .spset #sp_threshold, .sp .spset #sp_files { width: 5em; padding: 2px 5px; }
.sp .spset .splm { margin: 2px 0 6px; }
.sp .spset .spdel { margin-top: 8px; padding-top: 6px; border-top: 1px solid rgba(128,128,128,.3); }
.sp .card { min-width: 0; flex: 1 1 280px; border: 1px solid rgba(128,128,128,.35); border-radius: 8px; padding: 10px 12px; }
.sp .card h3 { margin: 0 0 8px; font-size: 1em; }
.sp-bars { display: flex; align-items: flex-end; gap: 2px; height: 70px; }
.sp-bar { flex: 1; height: 100%; display: flex; align-items: flex-end; background: rgba(128,128,128,.08); }
.sp-bar span { display: block; width: 100%; background: #4a8f3c; }
.sp-bar.now span { background: #d97a00; }
.sp-axis { display: flex; justify-content: space-between; font-size: 10px; opacity: .7; }
.sp-cal { display: grid; grid-template-rows: repeat(7, 10px); grid-auto-flow: column; grid-auto-columns: 10px; gap: 2px; overflow-x: auto; padding-bottom: 4px; }
.sp-cal i { display: block; width: 10px; height: 10px; border-radius: 2px; background: rgba(128,128,128,.15); }
.sp-cal i.l1 { background: #b7dfa5; } .sp-cal i.l2 { background: #7fc263; } .sp-cal i.l3 { background: #4a8f3c; } .sp-cal i.l4 { background: var(--accent,#2b5e22); }
.sp .clips { display: grid; grid-template-columns: repeat(auto-fill, minmax(300px, 1fr)); gap: 12px; }
.sp .clip { position: relative; }
.sp .clip .cliphead { display: flex; align-items: center; justify-content: space-between; gap: 8px; margin-bottom: 4px; }
.sp button.wipebtn { width: auto; padding: 3px 10px; border-radius: 12px; border: 1px solid #c62828; background: #fff; color: #c62828; font-weight: 600; cursor: pointer; }
.sp button.wipebtn:hover { background: #c62828; color: #fff; }
.sp button.restorebtn { width: auto; padding: 3px 10px; border-radius: 12px; border: 1px solid var(--accent,#2b5e22); background: #fff; color: var(--accent,#2b5e22); font-weight: 600; cursor: pointer; }
.sp button.restorebtn:hover { background: var(--accent,#2b5e22); color: #fff; }
.sp table.calib { border-collapse: collapse; margin: 6px 0; font-size: 12px; }
.sp .spprec .spprec-out { display: flex; flex-wrap: wrap; gap: 18px; }
.sp .spprec .spprec-blk { flex: 1 1 320px; min-width: 0; } /* a phone: its table scrolls inside, not the page (2026-10-23) */
.sp .charts > *, .sp .grid > * { min-width: 0; }
.sp .spprec h4 { margin: 10px 0 4px; }
.sp .spprec tr.spprec-best td { font-weight: 700; background: rgba(46, 125, 50, .18); }
.sp table.calib th, .sp table.calib td { padding: 1px 8px 1px 0; text-align: left; }
.sp img.clipspec { display: block; width: 100%; border-radius: 6px; cursor: pointer; margin-top: 4px; transition: filter .15s; }
.sp img.clipspec:hover { filter: brightness(1.15); outline: 2px solid #d97a00; }
</style>
<div class="sp">
  <?php $photo = species_photo($sci);
  if ($photo && !empty($photo['image_url'])) { ?>
  <div class="spphoto"><img src="<?php echo $h($photo['image_url']); ?>" alt="<?php echo $h($com); ?>">
    <small><?php echo $h($photo['title'] ?? ''); ?><?php if (!empty($photo['author_url'])) { ?>, <a href="<?php echo $h($photo['author_url']); ?>" target="_blank"><?php echo t('author'); ?></a><?php } ?>
    <?php if (!empty($photo['license_url'])) { ?>, <a href="<?php echo $h($photo['license_url']); ?>" target="_blank"><?php echo t('licence'); ?></a><?php } ?></small></div>
  <?php } ?>
  <div style="font-size:1.3em;margin-top:10px"><?php echo species_title($sci, '<b>' . $h($com) . '</b>', '', true); ?></div>
  <?php // the summary one fact per line (owner 2026-10-21) ?>
  <?php if ($none) { ?>
  <p><?php echo t('No detections of <i>{name}</i> at this station.', array('name' => $h($com))); ?></p>
  <?php } else { ?>
  <p><?php $dv = array('n' => fmt_num(intval($sum['n'])), 'days' => intval($sum['days'])); echo intval($sum['days']) == 1 ? t('<b>{n}</b> detections on <b>{days}</b> day', $dv) : t('<b>{n}</b> detections on <b>{days}</b> days', $dv); ?><br>
    <?php echo t('First:'); ?> <?php echo $h($sum['first']); ?><br>
    <?php echo t('Last:'); ?> <?php echo $h($sum['last']); ?><br>
    <?php echo t('Best:'); ?> <?php echo round(floatval($sum['maxc']) * 100); ?>%, <?php echo t('Mean:'); ?> <?php echo round(floatval($sum['avgc']) * 100); ?>%, <?php echo t('Worst:'); ?> <?php echo round(floatval($sum['minc']) * 100); ?>%</p>
  <?php } ?>
  <div class="charts">
    <?php if ($profile) { ?>
    <div class="card"><h3><?php echo t('Expected here (location model, by week)'); ?></h3>
      <?php echo $bar(array_combine(range(1, 48), array_map(function ($v) { return round($v * 100); }, $profile)),
        array_combine(range(1, 48), array_map(function ($w) { return t('week {n} (%)', array('n' => $w)); }, range(1, 48))), t('occurrence probability'), $week48); ?>
      <div class="sp-axis"><span><?php echo t('Jan'); ?></span><span><?php echo t('Apr'); ?></span><span><?php echo t('Jul'); ?></span><span><?php echo t('Oct'); ?></span><span><?php echo t('Dec'); ?></span></div>
      <small><?php echo t('Peak {peak}%, this week {now}% (orange).', array('peak' => round(max($profile) * 100), 'now' => round($profile[$week48 - 1] * 100))); ?></small>
    </div>
    <?php } else { ?>
    <div class="card"><h3><?php echo t('Expected here (location model, by week)'); ?></h3><small><?php echo t('The location profile is not computed yet (it is built with the first notification of the day).'); ?></small></div>
    <?php } ?>
    <?php if (!$none) { ?>
    <div class="card"><h3><?php echo t('Last 12 months'); ?></h3>
      <div class="sp-cal"><?php
        $start = strtotime('-364 days', strtotime(date('Y-m-d')));
        $start = strtotime('-' . intval(date('w', $start)) . ' days', $start);  // the grid starts on a Sunday
        for ($t = $start; $t <= time(); $t = strtotime('+1 day', $t)) {
          $d = date('Y-m-d', $t);
          $n = $per_day[$d] ?? 0;
          $lvl = $n === 0 ? '' : ($n < 3 ? 'l1' : ($n < 10 ? 'l2' : ($n < 30 ? 'l3' : 'l4')));
          echo '<i class="' . $lvl . '" title="' . $d . ': ' . $n . '"></i>';
        }
      ?></div>
    </div>
    <!-- by week, on the same 48 weeks as "Expected here" (owner 2026-10-10: it used to be by month) -->
    <div class="card"><h3><?php echo t('Weeks (all years)'); ?></h3>
      <?php echo $bar($weeks, array_combine(range(1, 48), array_map(function ($w) { return t('week {n}', array('n' => $w)); }, range(1, 48))), t('detections per week'), $week48); ?>
      <div class="sp-axis"><span><?php echo t('Jan'); ?></span><span><?php echo t('Apr'); ?></span><span><?php echo t('Jul'); ?></span><span><?php echo t('Oct'); ?></span><span><?php echo t('Dec'); ?></span></div>
    </div>
    <div class="card"><h3><?php echo t('Time of day'); ?></h3>
      <?php echo $bar($hours, array_combine(range(0, 23), array_map(function ($x) { return sprintf('%02d h', $x); }, range(0, 23))), t('detections per hour'), intval(date('G'))); ?>
      <div class="sp-axis"><span>0 h</span><span>6 h</span><span>12 h</span><span>18 h</span><span>23 h</span></div>
    </div>
    <?php } ?>
  </div>
  <div class="grid">
    <!-- Species Settings (owner 2026-10-10): threshold (editable), notifications and the lists, one per line -->
    <div class="card spset"><h3><?php echo t('Species Settings'); ?></h3>
      <label title="<?php echo $h(t('Minimum confidence for this species; empty = the global minimum confidence ({pct}%).', array('pct' => round(floatval($config['CONFIDENCE'] ?? 0.7) * 100))) . ' ' . t('Saved at once (species_confidence.txt).')); ?>"><?php echo t('Minimum confidence of the species:'); ?>
        <input type="number" id="sp_threshold" min="0.01" max="0.99" step="0.01" placeholder="<?php echo $h(fmt_num(floatval($config['CONFIDENCE'] ?? 0.7), 2)); ?>"
          value="<?php echo $threshold !== null ? $h(sprintf('%.2f', floatval($threshold))) : ''; ?>" onchange="spThreshold(this, <?php echo $h(json_encode($sci)); ?>)">
        <small><?php echo t('empty = the global minimum confidence ({pct}%)', array('pct' => round(floatval($config['CONFIDENCE'] ?? 0.7) * 100))); ?></small></label>
      <?php // the location model lines under the species threshold (owner 2026-10-21) ?>
      <div class="splm"><?php echo t('Probability of occurrence at the location this week:'); ?> <b class="<?php echo ($model_prob !== null && $model_prob >= $sf_thresh) ? 'pgood' : 'pbad'; ?>"><?php echo $model_prob !== null ? fmt_num($model_prob * 100, 1) . '%' : '-'; ?></b>
      <?php if ($sf_thresh > 0) { ?><br><?php echo t('Station minimum probability of occurrence at the location: {pct}% (below it the species is left out)', array('pct' => round($sf_thresh * 100))); ?><?php } ?></div>
      <label title="<?php echo $h(t('Minimum probability of occurrence for this species, in force when higher than the station minimum: while the week\'s probability is below it, its detections are left out, also for a whitelisted species.') . "\n" . t('Saved at once (species_min_prob.txt).')); ?>"><?php echo t('Minimum probability of occurrence of the species:'); ?>
        <input type="number" id="sp_minprob" min="0.001" max="0.99" step="0.001" placeholder="<?php echo $h(fmt_num($sf_thresh, 3)); ?>"
          value="<?php echo $min_prob !== null ? $h(sprintf('%.3f', floatval($min_prob))) : ''; ?>" onchange="spMinProb(this, <?php echo $h(json_encode($sci)); ?>)">
        <small><?php echo t('empty = the station minimum ({pct}%)', array('pct' => fmt_num($sf_thresh * 100, 1))); ?></small></label>
      <label title="<?php echo $h(t('Audio files kept for this species by the nightly clean-up (the last 7 days and the protected ones always stay); 0 = keep all; empty = the global number.')); ?>"><?php echo t('Files to keep:'); ?>
        <input type="number" id="sp_files" min="0" max="100000" step="1" placeholder="<?php echo intval($config['MAX_FILES_SPECIES'] ?? 0); ?>"
          value="<?php echo $max_files !== null ? intval($max_files) : ''; ?>" onchange="spFiles(this, <?php echo $h(json_encode($sci)); ?>)">
        <small><?php echo t('empty = global ({n})', array('n' => intval($config['MAX_FILES_SPECIES'] ?? 0))); ?></small></label>
      <label><?php echo t('Notifications:'); ?> <select onchange="spTier(this, <?php echo $h(json_encode($sci)); ?>)" data-was="<?php echo $h($tier); ?>">
        <?php foreach (array('muted' => t('Muted'), 'normal' => t('Normal'), 'rare' => t('Prio')) as $tv => $tl) {
          echo '<option value="' . $tv . '"' . ($tier === $tv ? ' selected' : '') . '>' . $tl . ($tv === $default_tier ? ' ' . t('(default)') : '') . '</option>';
        } ?>
      </select></label>
      <label title="<?php echo t('A validation marker: you have checked that the species occurs here'); ?>"><input type="checkbox" <?php echo $in_confirmed ? 'checked' : ''; ?> onchange="spList(this, 'confirmed', <?php echo $h(json_encode($sci)); ?>)"> <?php echo t('Confirmed'); ?></label>
      <label title="<?php echo t('Accepted even when its probability of occurrence at the location is below the minimum now'); ?>"><input type="checkbox" <?php echo $in_whitelist ? 'checked' : ''; ?> onchange="spList(this, 'whitelist', <?php echo $h(json_encode($identifier)); ?>)"> <?php echo t('Whitelist'); ?></label>
      <label title="<?php echo t('Never detected again'); ?>"><input type="checkbox" <?php echo $in_exclude ? 'checked' : ''; ?> onchange="spList(this, 'exclude', <?php echo $h(json_encode($identifier)); ?>)"> <?php echo t('Excluded sp.'); ?></label>
      <?php $ndel = deleted_count($sci); if ($ndel) { ?>
        <div class="spdel"><?php echo t('Deleted detections:'); ?> <b><?php echo $ndel; ?></b>
          <button type="button" class="restorebtn" onclick="restoreRemoved(<?php echo $h(json_encode($sci)); ?>, this)"><?php echo t('Restore'); ?></button>
          <button type="button" class="wipebtn" onclick="wipeDeleted(<?php echo $h(json_encode($sci)); ?>, this)"><?php echo t('Purge'); ?></button></div>
      <?php } ?>
    </div>
    <div class="card" id="spreviews"><h3><?php echo t('Reviews'); ?></h3>
      <?php // the answers as the Review button names them (owner 2026-10-22); the thresholds live in Precision below ?>
      <?php echo t('Yes'); ?> = <b><?php echo $reviews['yes']; ?></b><br>
      <?php echo t('Changed'); ?> = <b><?php echo $reviews['chg']; ?></b> <small><?php echo t('(changed to this species)'); ?></small><br>
      <?php echo t('Changed to another species'); ?> = <b><?php echo $reviews['away']; ?></b><br>
      <?php echo t('Review later'); ?> = <b><?php echo $reviews['later']; ?></b><br>
      <?php echo t('Ignored'); ?> = <b><?php echo $reviews['ign']; ?></b>
      <?php if (!array_sum($reviews)) { ?><br><small><?php echo t('Use the <b>Review</b> button on a detection to review it.'); ?></small><?php } ?>
    </div>
  </div>
  <?php if (!$none) { ?>
  <?php // Precision (owner 2026-10-22): from which confidence and which probability of occurrence the detections of this
  // species are real, from every review kept in the database; drawn by spPrecision() below ?>
  <h3 class="section" id="precision"><?php echo t('Precision'); ?></h3>
  <div class="card spprec" id="spprec" data-sci="<?php echo $h($sci); ?>" data-conf="<?php echo $h(json_encode($prec)); ?>"
    data-cur-conf="<?php echo $h((string)($threshold ?? '')); ?>" data-global-conf="<?php echo floatval($config['CONFIDENCE'] ?? 0.7); ?>"
    data-cur-prob="<?php echo $h((string)($min_prob ?? '')); ?>" data-global-prob="<?php echo $sf_thresh; ?>"
    data-nconf="<?php echo $h(json_encode($n_conf)); ?>" data-nprob="<?php echo $h(json_encode($n_prob)); ?>">
    <p><?php echo t('This section shows from which confidence the detections of this species are real, from your reviews.'); ?><br>
      <?php echo t('Right = answered Yes.'); ?><br>
      <?php echo t('False = Dog, Human, Engine, Noise, Ignore or changed to another species.'); ?><br>
      <?php echo t('Review later does not count.'); ?></p>
    <?php if ($bands_rev) { ?>
    <h4><?php echo t('Where to review'); ?></h4>
    <p><?php echo t('Each line is a confidence band.'); ?><br>
      <?php echo t('Review the highlighted band first: there your answers are the most uncertain and detections are waiting.'); ?></p>
    <table class="stdtable spbands">
      <tr><th><?php echo t('Confidence'); ?></th><th class="num"><?php echo t('Right'); ?></th><th class="num"><?php echo t('False'); ?></th><th class="num"><?php echo t('Precision'); ?></th><th class="num"><?php echo t('Without an answer'); ?></th><th data-nosort></th></tr>
      <?php foreach ($bands_rev as $i => $b) {
        $n = $b['y'] + $b['f'];
        echo '<tr' . ($i === $critical ? ' class="spprec-best" title="' . $h(t('The most critical band: review it first')) . '"' : '') . '><td>' . ($i * 10) . ' - ' . ($i * 10 + 10) . '%' . ($i === $critical ? ' &#9733;' : '') . '</td>'
          . '<td class="num">' . $b['y'] . '</td><td class="num">' . $b['f'] . '</td><td class="num">' . ($n ? round(100 * $b['y'] / $n) . '%' : '-') . '</td><td class="num">' . fmt_num($b['w']) . '</td><td style="text-align:right !important">';
        if ($b['samples']) {
          echo '<button type="button" class="openbtn" onclick="reviewDetection(document.querySelector(\'#band' . $i . ' [data-ri]\'))">' . t('Review {n}', array('n' => count($b['samples']))) . '</button>'
            . '<div id="band' . $i . '" data-review-list="1" style="display:none">';
          foreach ($b['samples'] as $r) {
            $f = $r['Date'] . '/' . species_folder($sci, $r['Com_Name'] ?? $com, $r['File_Name']) . '/' . $r['File_Name'];
            echo '<div' . review_item_attrs($f, $r['Com_Name'] . ', ' . $r['Date'] . ' ' . $r['Time'] . ', ' . round($r['Confidence'] * 100) . '%', $sci, $r) . '>' . validate_button($f, '') . '</div>';
          }
          echo '</div>';
        }
        echo '</td></tr>';
      } ?>
    </table>
    <?php } ?>
    <h4><?php echo t('Threshold'); ?></h4>
    <p><?php echo t('Each line keeps only the detections at or above that value.'); ?><br>
      <?php echo t('Precision = right divided by right plus false, each band weighted by its number of detections.'); ?><br>
      <?php echo t('Guaranteed = the worst likely precision with the reviews you have; it rises as you review more.'); ?><br>
      <?php echo t('Real lost = estimate of the real detections the threshold would leave out.'); ?><br>
      <?php echo t('A threshold applies to new detections only; the older ones stay in the database.'); ?></p>
    <?php // how and why (owner 2026-10-27): the method, the change of that day and the papers, folded under the threshold notes ?>
    <details class="small" style="margin-top:8px"><summary><?php echo t('How the threshold is computed, and why'); ?></summary>
      <p><?php echo t('The BirdNET confidence is not a probability, and the same value means different things for different species.'); ?><br>
        <?php echo t('That is why each species gets its own threshold, set from a sample of its detections checked by ear.'); ?><br>
        <?php echo t('Where to review highlights the band whose precision is the most uncertain (the widest 95% Wilson interval) among those with detections waiting.'); ?></p>
      <p><b><?php echo t('Precision'); ?></b><br>
        <?php echo t('Each confidence band counts with the number of detections it really has, not with the number you reviewed.'); ?><br>
        <?php echo t('Covered = the share of the detections above the threshold that are in bands with reviews; a suggestion needs at least 80%.'); ?><br>
        <?php echo t('Guaranteed = the lower end of the 95% interval of that precision.'); ?></p>
      <p><b><?php echo t('Logistic'); ?></b><br>
        <?php echo t('A curve of the chance of being right against the confidence, fitted to every review, as Wood and Kahl recommend.'); ?><br>
        <?php echo t('It needs fewer reviews and does not depend on which bands you reviewed more.'); ?><br>
        <?php echo t('Its value is the confidence at which a single detection reaches the target, so it is stricter than the precision, which averages every detection above the threshold.'); ?></p>
      <p><b><?php echo t('Change of 2026-10-27'); ?></b><br>
        <?php echo t('The precision used to add up the reviews as they were, so the bands reviewed most weighed more than they should.'); ?><br>
        <?php echo t('Since the reviews follow the bands you choose and not the detections, the suggestion could come out too high or too low.'); ?><br>
        <?php echo t('Now each band is weighted by its detections, and the logistic curve is shown beside it.'); ?></p>
      <p><b><?php echo t('References'); ?></b><br>
        Wood, C.M. &amp; Kahl, S. (2024). <a href="https://doi.org/10.1007/s10336-024-02144-5" target="_blank" rel="noopener">Guidelines for appropriate use of BirdNET scores and other detector outputs</a>. Journal of Ornithology 165: 777–782.<br>
        Kahl, S., Wood, C.M., Eibl, M. &amp; Klinck, H. (2021). <a href="https://doi.org/10.1016/j.ecoinf.2021.101236" target="_blank" rel="noopener">BirdNET: A deep learning solution for avian diversity monitoring</a>. Ecological Informatics 61: 101236.<br>
        Knight, E.C. et al. (2017). <a href="https://doi.org/10.5751/ACE-01114-120214" target="_blank" rel="noopener">Recommendations for acoustic recognizer performance assessment with application to five common automated signal recognition programs</a>. Avian Conservation and Ecology 12(2): 14.<br>
        Cochran, W.G. (1977). Sampling Techniques, 3rd ed., Wiley (stratified estimation).<br>
        Wilson, E.B. (1927). <a href="https://doi.org/10.1080/01621459.1927.10502953" target="_blank" rel="noopener">Probable inference, the law of succession, and statistical inference</a>. Journal of the American Statistical Association 22: 209–212.<br>
        Brown, L.D., Cai, T.T. &amp; DasGupta, A. (2001). <a href="https://doi.org/10.1214/ss/1009213286" target="_blank" rel="noopener">Interval estimation for a binomial proportion</a>. Statistical Science 16: 101–133.</p>
    </details>
    <label><?php echo t('Target:'); ?> <select class="spprec-target"><option value="0.8">80%</option><option value="0.9" selected>90%</option><option value="0.95">95%</option></select>
      <small><?php echo t('precision, with at least {n} reviews above the threshold', array('n' => 20)); ?></small></label>
    <div class="spprec-out"></div>
  </div>
  <h3 class="section"><?php echo t('Best detections'); ?></h3>
  <div class="clips" data-review-list="1">
  <?php foreach ($best as $b) {
    $file = $b['Date'] . '/' . species_folder($sci, $b['Com_Name'] ?? $com, $b['File_Name']) . '/' . $b['File_Name'];
    // the spectrogram picture; a click opens it playing, like "open" in Latest detections
    $label = $com . ', ' . $b['Date'] . ' ' . $b['Time'] . ', ' . round($b['Confidence'] * 100) . '%';
    // the Review button on the line of the date and confidence (owner 2026-10-21)
    echo '<div class="clip"' . review_item_attrs($file, $label, $sci, $b + array('Com_Name' => $com)) . '><div class="cliphead"><span><b>' . $h($b['Date'] . ' ' . $b['Time']) . '</b>, ' . round($b['Confidence'] * 100) . '%</span>' . detection_actions($file, false) . '</div>'
      . '<img class="clipspec" loading="lazy" src="' . $h(clip_spectrogram('/By_Date/' . $file)) . '" alt="' . $h(t('Spectrogram')) . '" title="' . $h(t('Listen and review')) . '" onclick="reviewDetection(this)"></div>';
  } ?>
  </div>
  <h3 class="section" id="latest"><?php echo $review_mode ? t('To review ({n} without an answer)', array('n' => count($recent) . (count($recent) >= 500 ? '+' : ''))) : t('Latest detections'); ?></h3>
  <?php // the standard detection list: a click on a row opens the review player, which goes on down the list
  echo $recent ? str_replace('<table class="list stdtable">', '<table class="list stdtable" data-pager="' . htmlspecialchars(t('Last {select} Detections'), ENT_QUOTES) . '">', detection_review_table($recent, false)) : '<p>' . t('Every detection of this species has an answer.') . '</p>'; ?>
  <?php } ?>
</div>
<?php if ($review_mode) { ?>
<script>
// review mode: the player starts on the first unreviewed detection; none left = straight to the next species of Curation
// DOMContentLoaded, not load: the player must not wait for every spectrogram picture of the page
document.addEventListener('DOMContentLoaded', function () {
  var first = document.querySelector('#latest + .reviewlist [data-ri]');
  if (first) openReviewPlayer(first);
  else if (window.reviewQueueNext) {
    reviewQueueDone(<?php echo json_encode($sci); ?>);
    // no species left in the queue: back to Validation, never left on this page (owner 2026-10-30)
    if (!reviewQueueNext(<?php echo json_encode($sci); ?>, 0, null, /[?&]dir=-1/.test(location.search) ? -1 : 1)) {
      try { sessionStorage.setItem('cur_return', <?php echo json_encode($sci); ?>); sessionStorage.setItem('cur_done', '1'); } catch (e) {}
      location.href = 'views.php?view=Curation';
    }
  }
});
</script>
<?php } ?>
<script>document.querySelectorAll('.sp-cal').forEach(function (c) { c.scrollLeft = c.scrollWidth; });</script>
<script src="static/custom-audio-player.js?v=<?php echo @filemtime(__DIR__ . "/../homepage/static/custom-audio-player.js"); ?>"></script>
<script src="static/detection-actions.js?v=<?php echo @filemtime(__DIR__ . "/../homepage/static/detection-actions.js"); ?>"></script>
<script src="static/species-modal.js?v=<?php echo @filemtime(__DIR__ . "/../homepage/static/species-modal.js"); ?>"></script>
<script src="static/spectro-dialog.js?v=<?php echo @filemtime(__DIR__ . "/../homepage/static/spectro-dialog.js"); ?>"></script>
<script src="static/review-player.js?v=<?php echo @filemtime(__DIR__ . "/../homepage/static/review-player.js"); ?>"></script>
<script src="static/std-table.js?v=<?php echo @filemtime(__DIR__ . "/../homepage/static/std-table.js"); ?>"></script>
<script src="static/name-mode.js?v=<?php echo @filemtime(__DIR__ . "/../homepage/static/name-mode.js"); ?>"></script>
<script src="static/wipe-deleted.js?v=<?php echo @filemtime(__DIR__ . "/../homepage/static/wipe-deleted.js"); ?>"></script>
<script src="static/precision.js?v=<?php echo @filemtime(__DIR__ . "/../homepage/static/precision.js"); ?>"></script>
<script>
// list switches: the same modal and texts as the Species Pages list (static/species-modal.js); whitelist and exclude
// default to No
function spList(box, list, species) {
  var action = box.checked ? 'add' : 'del';
  box.checked = !box.checked;   // only changes once confirmed and saved
  var t = SPECIES_TOGGLE_TEXT[list][action];
  askModal(t[0].replace('NAME', <?php echo json_encode($com . ' (' . $sci . ')'); ?>), t[1], window.t('Yes'), list === 'whitelist' || list === 'exclude').then(function (ok) {
    if (!ok) return;
    var x = new XMLHttpRequest();
    x.onload = function () {
      if (this.status === 200 && this.responseText.trim() === 'OK') box.checked = (action === 'add');
      else alert(window.t('Not changed: {reason}', {reason: this.status === 401 ? window.t('log in first') : this.responseText}));
    };
    x.onerror = function () { alert(window.t('Not changed (network error)')); };
    x.open('GET', 'scripts/species_tools.php?toggle=' + list + '&species=' + encodeURIComponent(species) + '&action=' + action, true);
    x.send();
  });
}
// species threshold typed in Species Settings: saved at once (empty = back to the global Min. Conf.)
function spThreshold(inp, sci) {
  var v = inp.value.trim();
  if (v !== '') { v = Math.min(0.99, Math.max(0.01, parseFloat(v.replace(',', '.')))); if (isNaN(v)) { inp.value = ''; return; } v = v.toFixed(2); inp.value = v; }
  var x = new XMLHttpRequest();
  x.onload = function () {
    var ok = this.status === 200 && this.responseText.trim() === 'OK';
    inp.style.outline = ok ? '2px solid #2e7d32' : '2px solid #c62828';
    setTimeout(function () { inp.style.outline = ''; }, 1500);
    if (!ok) alert(window.t('Not saved: {reason}', {reason: this.status === 401 ? window.t('log in first') : this.responseText}));
  };
  x.open('GET', 'scripts/species_tools.php?setconf=1&species=' + encodeURIComponent(sci) + '&value=' + encodeURIComponent(v), true);
  x.send();
}
// files to keep for this species (owner 2026-10-21): saved at once, empty = the global number
function spFiles(inp, sci) {
  var v = inp.value.trim();
  if (v !== '') { v = Math.max(0, Math.min(100000, parseInt(v, 10))); if (isNaN(v)) { inp.value = ''; return; } inp.value = v; }
  var x = new XMLHttpRequest();
  x.onload = function () {
    var ok = this.status === 200 && this.responseText.trim() === 'OK';
    inp.style.outline = ok ? '2px solid #2e7d32' : '2px solid #c62828';
    setTimeout(function () { inp.style.outline = ''; }, 1500);
    if (!ok) alert(window.t('Not saved: {reason}', {reason: this.status === 401 ? window.t('log in first') : this.responseText}));
  };
  x.open('GET', 'scripts/species_tools.php?setfiles=1&species=' + encodeURIComponent(sci) + '&value=' + encodeURIComponent(v), true);
  x.send();
}
// calibrated threshold: saved as the species threshold (same endpoint as the Species Pages list)
// Precision (owner 2026-10-22): for each threshold, the right and false reviewed detections at or above it, the
// precision, the guaranteed precision (Wilson lower bound, 95 %) and the right ones that would be lost below it; the
// suggestion is the lowest threshold whose precision reaches the target with at least 20 reviews above it (owner
// 2026-10-22: the guaranteed precision asked too much of a few dozen reviews; it is shown as a warning only)
function spPrecision() {
  var box = document.getElementById('spprec');
  if (!box) return;
  var rows = JSON.parse(box.dataset.conf || '[]'), out = box.querySelector('.spprec-out'), target = parseFloat(box.querySelector('.spprec-target').value);
  var P = window.bsPrecision, MIN_N = P.MIN_N, pct = function (v, d) { var r = +(v * 100).toFixed(d || 0); return window.fmtNum(r, (String(r).split('.')[1] || '').length) + '%'; };  // station locale, at most d decimals
  if (!rows.length) { out.innerHTML = '<p>' + window.t('No reviewed detection with Yes or a cause yet.') + '</p>'; return; }
  // detections of the species per 5 % confidence band and per week probability: the weights of the bands
  var nConf = JSON.parse(box.dataset.nconf || '[]'), nProb = JSON.parse(box.dataset.nprob || '[]');
  var countConf = P.countConf(nConf);
  var countProb = function (lo, hi) { var s = 0; nProb.forEach(function (e) { if (e[0] >= lo && e[0] < hi) s += e[1]; }); return s; };
  // the stratified precision above each threshold (static/precision.js, shared with Validation › Calibrate thresholds)
  function curve(idx, steps, digits, count) {
    var c = P.curve(rows, idx, steps, count, target), html = '';
    c.lines.forEach(function (l) {
      html += '<tr' + (c.best === l.th ? ' class="spprec-best"' : '') + '><td>' + pct(l.th, digits) + '</td><td class="num">' + l.y + '</td><td class="num">' + l.f + '</td><td class="num">' + (l.prec !== null ? pct(l.prec) : '-')
        + '</td><td class="num">' + (l.lb !== null ? pct(l.lb) : '-') + '</td><td class="num">' + (l.all ? pct(l.share) : '-') + '</td><td class="num">' + window.fmtNum(Math.round(l.lost)) + '</td></tr>';
    });
    return {best: c.best, lb: c.lb, html: html, none: c.none};
  }
  // Wood & Kahl (2024): the confidence where one detection is right with the target probability
  function logisticLine() {
    var r = P.logisticThreshold(rows, target), t = r.t;
    if (r.why === 'few') return '<p>' + window.t('Logistic: needs at least {n} reviews with both answers.', {n: MIN_N}) + '</p>';
    if (r.why === 'flat') return '<p>' + window.t('Logistic: for this species the confidence does not tell right from false.') + '</p>';
    if (r.why === 'none') return '<p>' + window.t('Logistic: no confidence reaches the target.') + '</p>';
    return '<p>' + window.t('Logistic (Wood and Kahl): at {v} a detection is right with a {p} chance.', {v: '<b>' + pct(t) + '</b>', p: pct(target)})
      + ' <button type="button" class="openbtn" onclick="applyThreshold(' + JSON.stringify(box.dataset.sci).replace(/"/g, '&quot;') + ', ' + t + ', this)">' + window.t('Apply') + '</button></p>';
  }
  function block(title, c, cur, glob, kind, digits, extra) {
    var head = '<tr><th>' + title + ' &ge;</th><th class="num">' + window.t('Right') + '</th><th class="num">' + window.t('False') + '</th><th class="num">' + window.t('Precision') + '</th><th class="num">' + window.t('Guaranteed') + '</th><th class="num">' + window.t('Covered') + '</th><th class="num">' + window.t('Real lost') + '</th></tr>';
    // the Prob. in force is the higher of the species' own and the station's (the analysis drops below either)
    var now = cur !== '' ? (kind === 'prob' ? Math.max(parseFloat(cur), glob) : parseFloat(cur)) : glob, sug;
    if (c.none) sug = window.t('No probability of occurrence for this species at the location: no threshold of probability of occurrence can be set.');
    else if (c.best === null) sug = window.t('No threshold reaches the target yet: review the highlighted band in Where to review.');
    else if (c.best <= now) sug = window.t('The threshold in force ({now}) already reaches the target.', {now: pct(now, digits)});
    else sug = window.t('Suggested: {v}', {v: '<b>' + pct(c.best, digits) + '</b>'}) + (c.lb < target ? ' <small>(' + window.t('guaranteed {g}: review more to confirm', {g: pct(c.lb)}) + ')</small>' : '') + ' <button type="button" class="openbtn" onclick="' + (kind === 'conf' ? 'applyThreshold' : 'applyMinProb') + '(' + JSON.stringify(box.dataset.sci).replace(/"/g, '&quot;') + ', ' + c.best + ', this)">' + window.t('Apply') + '</button>';
    return '<div class="spprec-blk"><h4>' + title + ' <small>(' + window.t('in force: {v}', {v: pct(now, digits) + (cur === '' || (kind === 'prob' && parseFloat(cur) < glob) ? ' ' + window.t('(station)') : '')}) + ')</small></h4><table class="stdtable">' + head + c.html + '</table><p>' + sug + '</p>' + (extra || '') + '</div>';
  }
  var cs = P.confSteps(), ps = [0, 0.005, 0.01, 0.02, 0.03, 0.05, 0.1, 0.2, 0.3, 0.5, 0.7];
  out.innerHTML = block(window.t('Confidence'), curve(0, cs, 0, countConf), box.dataset.curConf, parseFloat(box.dataset.globalConf), 'conf', 0, logisticLine())
    + block(window.t('Probability of occurrence at the location'), curve(1, ps, 1, countProb), box.dataset.curProb, parseFloat(box.dataset.globalProb), 'prob', 1);
}
function spPrecisionBind() {
  var sel = document.querySelector('#spprec .spprec-target');
  if (sel) { sel.onchange = spPrecision; spPrecision(); }
}
document.addEventListener('DOMContentLoaded', spPrecisionBind);
// back from the review player with answers (owner 2026-10-22): Precision and the Reviews card are drawn again from a
// fresh copy of the page, the target chosen is kept
document.addEventListener('rp:closed', function (e) {
  if (!e.detail || !e.detail.answered || !document.getElementById('spprec')) return;
  var target = document.querySelector('#spprec .spprec-target').value;
  fetch(location.href, {credentials: 'same-origin'}).then(function (r) { return r.text(); }).then(function (html) {
    var doc = new DOMParser().parseFromString(html, 'text/html');
    var fresh = doc.getElementById('spprec'), old = document.getElementById('spprec');
    if (fresh && old) { old.replaceWith(fresh); fresh.querySelector('.spprec-target').value = target; spPrecisionBind(); }
    var fr = doc.getElementById('spreviews'), or = document.getElementById('spreviews');
    if (fr && or) or.replaceWith(fr);
  }).catch(function () {});
});
// the species Min. Prob. (species_min_prob.txt), from the Precision suggestion
function applyMinProb(sci, value, btn) {
  var x = new XMLHttpRequest();
  x.onload = function () {
    if (this.status === 200 && this.responseText.trim() === 'OK') { btn.textContent = window.t('Applied'); btn.disabled = true; var i = document.getElementById('sp_minprob'); if (i) i.value = value; }
    else alert(window.t('Not saved: {reason}', {reason: this.status === 401 ? window.t('log in first') : this.responseText}));
  };
  x.open('GET', 'scripts/species_tools.php?setprob=1&species=' + encodeURIComponent(sci) + '&value=' + value, true);
  x.send();
}
function spMinProb(inp, sci) {
  var x = new XMLHttpRequest();
  x.onload = function () {
    if (this.status === 200 && this.responseText.trim() === 'OK') { inp.style.outline = '2px solid #2e7d32'; setTimeout(function () { inp.style.outline = ''; }, 1500); }
    else alert(window.t('Not saved: {reason}', {reason: this.status === 401 ? window.t('log in first') : this.responseText}));
  };
  x.open('GET', 'scripts/species_tools.php?setprob=1&species=' + encodeURIComponent(sci) + '&value=' + encodeURIComponent(inp.value), true);
  x.send();
}
function applyThreshold(sci, value, btn) {
  var x = new XMLHttpRequest();
  x.onload = function () {
    if (this.status === 200 && this.responseText.trim() === 'OK') { btn.textContent = window.t('Applied'); btn.disabled = true; var i = document.getElementById('sp_threshold'); if (i) i.value = Number(value).toFixed(2); }
    else alert(window.t('Not saved: {reason}', {reason: this.status === 401 ? window.t('log in first') : this.responseText}));
  };
  x.open('GET', 'scripts/species_tools.php?setconf=1&species=' + encodeURIComponent(sci) + '&value=' + value, true);
  x.send();
}
// notification tier of the species (same file and endpoint as the Species Pages list), saved at once
function spTier(sel, sci) {
  var was = sel.dataset.was;
  var x = new XMLHttpRequest();
  x.onload = function () {
    if (this.status === 200 && this.responseText.trim() === 'OK') { sel.dataset.was = sel.value; sel.style.outline = '2px solid #2e7d32'; setTimeout(function () { sel.style.outline = ''; }, 1200); }
    else { sel.value = was; alert(window.t('Not changed: {reason}', {reason: this.status === 401 ? window.t('log in first') : this.responseText})); }
  };
  x.onerror = function () { sel.value = was; alert(window.t('Not changed (network error)')); };
  x.open('GET', 'scripts/species_tools.php?settier=1&species=' + encodeURIComponent(sci) + '&tier=' + encodeURIComponent(sel.value), true);
  x.send();
}
document.addEventListener('play', function (e) { document.querySelectorAll('.sp audio').forEach(function (a) { if (a !== e.target) a.pause(); }); }, true);
</script>
