<?php
################################################################################
# scripts/species_page.php - SPECIES PAGE
#
# PURPOSE:
#   Species page (owner 2026-10-08): everything about one bird in one place — names and links, totals, the
#   last year as a calendar, months and hours of activity, the location model's expected season (V3 geo model,
#   region_profile.json), the best clips, the latest detections, the review verdicts with a suggested species
#   threshold, and the Species Settings (editable threshold, notification tier, lists). Opened as
#   views.php?view=Species&sci=<scientific name>.
#
# DEPENDENCIES:
#   - scripts/common.php
#   - scripts/species_tools.php
#   - homepage/static/custom-audio-player.js
#   - homepage/static/detection-actions.js
#   - homepage/static/species-modal.js
#   - homepage/static/spectro-dialog.js
#   - homepage/static/review-player.js
#   - homepage/static/std-table.js
#   - homepage/static/name-mode.js
#   - homepage/static/wipe-deleted.js
#   - homepage/static/precision.js
#   - homepage/static/species-page.js
#   - scripts/utils/rarity.py
#   - scripts/species.py
#
# USAGE:
#   views.php?view=Species&sci=<scientific name>
#   Referenced by homepage/static/review-player.js, homepage/static/species-page.js, homepage/views.php,
#   scripts/lib/images.php, scripts/update_caddyfile.sh
################################################################################

require_once __DIR__ . '/common.php';
$home = get_home();
$config = get_config();
$sci = trim(html_entity_decode($_GET['sci'] ?? '', ENT_QUOTES));
$h = function ($s) { return htmlspecialchars((string)$s, ENT_QUOTES); };
// the best xeno-canto spectrogram, asked by the page after it is drawn (it may take a few seconds the first time)
// the kept copy of a xeno-canto spectrogram (state/xc/<nr>.png, written by xc_best())
if (isset($_GET['xcimg'])) {
	$f = BS_STATE . '/xc/' . preg_replace('/\D/', '', (string)$_GET['xcimg']) . '.png';
	if (!is_file($f)) { http_response_code(404); exit; }
	header('Content-Type: image/png');
	header('Cache-Control: max-age=2592000');
	readfile($f);
	exit;
}
if (isset($_GET['xcbest'])) {
	if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
	header('Content-Type: application/json');
	echo json_encode($sci !== '' ? xc_best($sci) : null);
	exit;
}
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
	if ($one) { $r = $res->fetchArray(SQLITE3_ASSOC); return $r ? $r + array('Com_Name' => common_name($sci)) : $r; }
	$rows = array();
	while ($r = $res->fetchArray(SQLITE3_ASSOC)) $rows[] = $r + array('Com_Name' => common_name($sci));
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
$chunks = cached_past_months('species_hours|' . $sci, '', '', past_month_changes(), function ($lo, $hi) use ($hour_rows) { return $hour_rows('Date BETWEEN :lo AND :hi', array(':lo' => $lo, ':hi' => $hi)); });
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
// the species' folder (scientific name)
$folders = array(name_safe($sci));
// the common name is presentation (owner 2026-11-05), added to every row by $q (in SQL it was worked out for every
// detection of the species before the LIMIT: 1.6 s instead of 0.9 s on a common species)
$best = $q("SELECT Date, Time, Confidence, Cutoff, Sens, Overlap, Loc_Thresh, Rec_Length, Sp_Override, Mic, Mic_Port, Mic_Gain, Model, Audio_Loss_N, Audio_Loss_Ms, Tz, Utc_Offset, File_Name, " . location_columns_sql() . " FROM detections WHERE Sci_Name = :sci $nr ORDER BY Confidence DESC, Date DESC LIMIT 6");
// the newest 30 picked from the species index first, then their rows (owner 2026-11-05: with the full statistics the
// planner sorted all of a common species' rows through the location index, 1 s instead of 3 ms)
$recent = $q("SELECT Date, Time, Sci_Name, Confidence, Cutoff, Sens, Overlap, Loc_Thresh, Rec_Length, Sp_Override, Mic, Mic_Port, Mic_Gain, Model, Audio_Loss_N, Audio_Loss_Ms, Tz, Utc_Offset, File_Name, " . location_columns_sql() . " FROM detections WHERE File_Name IN (SELECT File_Name FROM detections WHERE Sci_Name = :sci ORDER BY Date DESC, Time DESC LIMIT 30) ORDER BY Date DESC, Time DESC");
// review mode (&review=1, the Review button of Curation, owner 2026-10-18): the list holds the species' detections
// without an answer yet, and the review player opens on the first one; at the end it goes on to the next species
$review_mode = !empty($_GET['review']) && $nr !== '';
if ($review_mode) {
	// a species not confirmed yet starts with its most confident detection — the likeliest Yes, which confirms it and
	// moves the review on to the next species (quick triage of new occurrences, owner 2026-10-18); a confirmed species
	// is reviewed newest first
	$is_conf = in_array($sci, @file(BS_LISTS . '/confirmed_species_list.txt', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: array(), true);
	// exactly the rows Validation counted (owner 2026-11-02): its period, its location and the moment it was drawn, handed
	// over in the link (common.php validation_source)
	$rvp = validation_source_sql(validation_source());
	// 500 at a time: the player reloads this page for the rest when the last one has its answer (data-rv-more)
	$ord = $is_conf ? "Date DESC, Time DESC" : "Confidence DESC, Date DESC, Time DESC";
	$recent = $q("SELECT Date, Time, Sci_Name, Confidence, Cutoff, Sens, Overlap, Loc_Thresh, Rec_Length, Sp_Override, Mic, Mic_Port, Mic_Gain, Model, Audio_Loss_N, Audio_Loss_Ms, Tz, Utc_Offset, File_Name, " . location_columns_sql() . " FROM detections WHERE File_Name IN (SELECT File_Name FROM detections
                WHERE Sci_Name = :sci AND File_Name NOT IN (SELECT File_Name FROM detection_reviews)" . $rvp . " ORDER BY $ord LIMIT 501) ORDER BY $ord");
	$rv_more = count($recent) > 500;
	$recent = array_slice($recent, 0, 500);
}

// Reviews and Precision (owner 2026-10-22): the answers of this species, as the Review button names them, and every
// reviewed detection with its confidence, its week's probability of occurrence and whether it was right: Yes = right;
// Human, Noise, Ignore and every detection changed to another
// species = false; Review later and the older "not this bird" without a cause stay out
$reviews = array('yes' => 0, 'chg' => 0, 'later' => 0, 'ign' => 0, 'away' => 0);
$prec = array();
$n_conf = array();
$n_prob = array();
$bands_rev = array();
$critical = null;
if ($nr !== '') {
	foreach ($q("SELECT Date, Confidence, Verdict, COALESCE(Reason, '') AS Reason FROM detection_reviews WHERE Sci_Name = :sci") as $r) {
		$ok = null;
		if ($r['Verdict'] === 'yes' && $r['Reason'] === 'changed') $reviews['chg']++;
		elseif ($r['Verdict'] === 'yes') { $reviews['yes']++; $ok = 1; }
		elseif ($r['Verdict'] === 'no' && in_array($r['Reason'], PURGE_CAUSES, true)) { $reviews['ign']++; $ok = 0; }
		else $reviews['later']++;
		if ($ok !== null) $prec[] = array(floatval($r['Confidence']), location_probability($sci, $r['Date']), $ok);
	}
	foreach ($q("SELECT Date, Confidence FROM detection_reviews WHERE Orig_Sci = :sci") as $r) {
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
	foreach ($folders as $folder) $globbed = array_merge($globbed, glob(BS_BY_DATE . '/*/' . $folder . '/*') ?: array(), glob(BS_BY_DATE . '/shifted/*/' . $folder . '/*') ?: array());
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
	foreach (@file(BS_LISTS . '/species_confidence.txt', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: array() as $l) {
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
		$smp = $smp ? $q("SELECT Date, Time, Sci_Name, Confidence, Cutoff, Sens, Overlap, Loc_Thresh, Rec_Length, Sp_Override, Mic, Mic_Port, Mic_Gain, Model, Audio_Loss_N, Audio_Loss_Ms, Tz, Utc_Offset, File_Name, " . location_columns_sql()
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
$threshold = $read_kv(BS_LISTS . '/species_confidence.txt')[$sci] ?? null;
$min_prob = $read_kv(BS_LISTS . '/species_min_prob.txt')[$sci] ?? null;  // Min. Prob. of the species (owner 2026-10-22)
$max_files = $read_kv(BS_LISTS . '/species_max_files.txt')[$sci] ?? null; // files to keep, empty = global (owner 2026-10-21)
$default_tier = strtolower($config['NOTIFICATION_DEFAULT_TIER'] ?? 'normal');
if (!in_array($default_tier, array('normal', 'muted', 'rare'), true)) $default_tier = 'normal';
$tier = strtolower($read_kv(BS_LISTS . '/notification_tiers.txt')[$sci] ?? $default_tier);
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
	$out = (string)shell_exec(station_prefix() . '' . escapeshellarg(BS_PYTHON) . ' '
		. escapeshellarg(BS_SCRIPTS . '/species.py') . ' --threshold 0 2>/dev/null');
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
.sp .spprec .spprec-blk { flex: 1 1 320px; min-width: 0; overflow-x: auto; } /* its table scrolls inside, never the page (2026-10-23; at every width since release run 4, I18N-11) */
.sp .charts > *, .sp .grid > * { min-width: 0; }
.sp .spprec h4 { margin: 10px 0 4px; }
.sp .spprec tr.spprec-best td { font-weight: 700; background: rgba(46, 125, 50, .18); }
.sp table.calib th, .sp table.calib td { padding: 1px 8px 1px 0; text-align: left; }
.sp .spxc { margin-top: 8px; max-width: 520px; }
.sp .spphoto .spxc { max-width: none; width: 100%; text-align: left; }  /* under the picture, as wide as it */
/* phone: the picture keeps its own width (max 60vh tall), the box shrinks to it and the spectrogram follows it */
@media (max-width: 600px) { .sp .spphoto { display: table; width: auto; margin: 8px auto; } .sp .spphoto .spxc { display: table-caption; caption-side: bottom; } }
.sp .spxc img { display: block; width: 100%; height: auto; aspect-ratio: 486 / 241; border-radius: 8px; background: #fff; }
.sp .spxc small { display: block; margin-top: 2px; }
.sp img.clipspec { display: block; width: 100%; border-radius: 6px; cursor: pointer; margin-top: 4px; transition: filter .15s; }
.sp img.clipspec:hover { filter: brightness(1.15); outline: 2px solid #d97a00; }
</style>
<div class="sp">
	<?php $photo = species_photo($sci);
	// a recording already kept is drawn with the page; only one not looked up yet is asked after the page is drawn
	$xck = xc_best_kept($sci);
	if (is_array($xck)) {
		$spxc_html = '<div class="spxc" id="spxc"><a id="spxclink" href="' . $h($xck['page']) . '" target="_blank" rel="noopener"><img id="spxcimg" width="486" height="241" src="' . $h($xck['local']) . '" alt="' . $h(t('str_spectrogram') . ' XC' . $xck['nr']) . '"></a><small id="spxccap">'
			. $h(t('str_best_recording_on_xeno_canto_xc', array('where' => !empty($xck['country']) ? ' (' . $xck['country'] . ')' : '', 'nr' => $xck['nr'], 'by' => $xck['by'])))
			. (!empty($xck['lic']) ? ', <a href="' . $h($xck['lic']) . '" target="_blank" rel="noopener">' . $h(t('str_licence')) . '</a>' : '') . '</small></div>';
	} else {
		$spxc_html = '<div class="spxc" id="spxc" hidden><a id="spxclink" target="_blank" rel="noopener"><img id="spxcimg" width="486" height="241" alt=""></a><small id="spxccap"></small></div>';
	}
	if ($photo && !empty($photo['image_url'])) { ?>
	<div class="spphoto"><img src="<?php echo $h($photo['image_url']); ?>" alt="<?php echo $h($com); ?>">
		<small><?php echo $h($photo['title'] ?? ''); ?><?php if (!empty($photo['author_url'])) { ?>, <a href="<?php echo $h($photo['author_url']); ?>" target="_blank"><?php echo t('str_author'); ?></a><?php } ?>
		<?php if (!empty($photo['license_url'])) { ?>, <a href="<?php echo $h($photo['license_url']); ?>" target="_blank"><?php echo t('str_licence'); ?></a><?php } ?></small>
		<?php echo $spxc_html; ?></div>
	<?php } ?>
	<?php // the best recording of the species on xeno-canto, as its spectrogram under the picture and as wide as it (owner
	// 2026-11-01), filled after the page is drawn; nothing shows when xeno-canto has none or does not answer. Without a
	// picture it stands alone in the same place.
	if (!($photo && !empty($photo['image_url']))) echo $spxc_html; ?>
	<?php if ($xck === false) { ?><script>
	(function () {
		var x = new XMLHttpRequest();
		x.onload = function () {
			var r; try { r = JSON.parse(this.responseText); } catch (e) { return; }
			if (!r || !r.img) return;
			document.getElementById('spxclink').href = r.page;
			var img = document.getElementById('spxcimg');
			img.src = r.local || r.img; img.alt = window.t('str_spectrogram') + ' XC' + r.nr;
			var cap = document.getElementById('spxccap');
			cap.textContent = window.t('str_best_recording_on_xeno_canto_xc', {where: r.country ? ' (' + r.country + ')' : '', nr: r.nr, by: r.by}) + (r.lic ? ', ' : '');
			if (r.lic) { var a = document.createElement('a'); a.href = r.lic; a.target = '_blank'; a.rel = 'noopener'; a.textContent = window.t('str_licence'); cap.appendChild(a); }
			document.getElementById('spxc').hidden = false;
		};
		x.open('GET', 'scripts/species_page.php?xcbest=1&sci=' + encodeURIComponent(<?php echo json_encode($sci); ?>), true);
		x.send();
	})();
	</script><?php } ?>
	<div style="font-size:1.3em;margin-top:10px"><?php echo species_title($sci, '<b>' . $h($com) . '</b>', '', true); ?></div>
	<?php // the summary one fact per line (owner 2026-10-21) ?>
	<?php if ($none) { ?>
	<p><?php echo t('str_no_detections_of_at_this_station', array('name' => $h($com))); ?></p>
	<?php } else { ?>
	<p><?php $dv = array('n' => fmt_num(intval($sum['n'])), 'days' => intval($sum['days'])); echo intval($sum['days']) == 1 ? t('str_detections_on_day', $dv) : t('str_detections_on_days', $dv); ?><br>
		<?php echo t('str_first_colon'); ?> <?php echo $h($sum['first']); ?><br>
		<?php echo t('str_last_colon'); ?> <?php echo $h($sum['last']); ?><br>
		<?php echo t('str_best_colon'); ?> <?php echo round(floatval($sum['maxc']) * 100); ?>%, <?php echo t('str_mean'); ?> <?php echo round(floatval($sum['avgc']) * 100); ?>%, <?php echo t('str_worst'); ?> <?php echo round(floatval($sum['minc']) * 100); ?>%</p>
	<?php } ?>
	<div class="charts">
		<?php if ($profile) { ?>
		<div class="card"><h3><?php echo t('str_expected_here_location_model_by_week'); ?></h3>
			<?php echo $bar(array_combine(range(1, 48), array_map(function ($v) { return round($v * 100); }, $profile)),
				array_combine(range(1, 48), array_map(function ($w) { return t('str_week_pct', array('n' => $w)); }, range(1, 48))), t('str_occurrence_probability'), $week48); ?>
			<div class="sp-axis"><span><?php echo t('str_jan'); ?></span><span><?php echo t('str_apr'); ?></span><span><?php echo t('str_jul'); ?></span><span><?php echo t('str_oct'); ?></span><span><?php echo t('str_dec'); ?></span></div>
			<small><?php echo t('str_peak_pct_this_week_pct_orange', array('peak' => round(max($profile) * 100), 'now' => round($profile[$week48 - 1] * 100))); ?></small>
		</div>
		<?php } else { ?>
		<div class="card"><h3><?php echo t('str_expected_here_location_model_by_week'); ?></h3><small><?php echo t('str_the_location_profile_is_not_help'); ?></small></div>
		<?php } ?>
		<?php if (!$none) { ?>
		<div class="card"><h3><?php echo t('str_last_12_months'); ?></h3>
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
		<div class="card"><h3><?php echo t('str_weeks_all_years'); ?></h3>
			<?php echo $bar($weeks, array_combine(range(1, 48), array_map(function ($w) { return t('str_week_n_2', array('n' => $w)); }, range(1, 48))), t('str_detections_per_week'), $week48); ?>
			<div class="sp-axis"><span><?php echo t('str_jan'); ?></span><span><?php echo t('str_apr'); ?></span><span><?php echo t('str_jul'); ?></span><span><?php echo t('str_oct'); ?></span><span><?php echo t('str_dec'); ?></span></div>
		</div>
		<div class="card"><h3><?php echo t('str_time_of_day'); ?></h3>
			<?php echo $bar($hours, array_combine(range(0, 23), array_map(function ($x) { return sprintf('%02d h', $x); }, range(0, 23))), t('str_detections_per_hour'), intval(date('G'))); ?>
			<div class="sp-axis"><span>0 h</span><span>6 h</span><span>12 h</span><span>18 h</span><span>23 h</span></div>
		</div>
		<?php } ?>
	</div>
	<div class="grid">
		<!-- Species Settings (owner 2026-10-10): threshold (editable), notifications and the lists, one per line -->
		<div class="card spset"><h3><?php echo t('str_species_settings'); ?></h3>
			<label title="<?php echo $h(t('str_minimum_confidence_empty_help', array('pct' => round(floatval($config['CONFIDENCE'] ?? 0.7) * 100))) . ' ' . t('str_saved_at_once_species_confidence_txt')); ?>"><?php echo t('str_minimum_confidence_of_the_species'); ?>
				<input type="number" id="sp_threshold" min="0.01" max="0.99" step="0.01" placeholder="<?php echo $h(fmt_num(floatval($config['CONFIDENCE'] ?? 0.7), 2)); ?>"
					value="<?php echo $threshold !== null ? $h(sprintf('%.2f', floatval($threshold))) : ''; ?>" onchange="spThreshold(this, <?php echo $h(json_encode($sci)); ?>)">
				<small><?php echo t('str_empty_the_global_minimum_help', array('pct' => round(floatval($config['CONFIDENCE'] ?? 0.7) * 100))); ?></small></label>
			<?php // the location model lines under the species threshold (owner 2026-10-21) ?>
			<div class="splm"><?php echo t('str_probability_of_this_week_help'); ?> <b class="<?php echo ($model_prob !== null && $model_prob >= $sf_thresh) ? 'pgood' : 'pbad'; ?>"><?php echo $model_prob !== null ? fmt_num($model_prob * 100, 1) . '%' : '-'; ?></b>
			<?php if ($sf_thresh > 0) { ?><br><?php echo t('str_station_minimum_probability_of_help', array('pct' => round($sf_thresh * 100))); ?><?php } ?></div>
			<label title="<?php echo $h(t('str_minimum_probability_for_help') . "\n" . t('str_saved_at_once_species_min_prob_help')); ?>"><?php echo t('str_minimum_probability_of_help'); ?>
				<input type="number" id="sp_minprob" min="0.001" max="0.99" step="0.001" placeholder="<?php echo $h(fmt_num($sf_thresh, 3)); ?>"
					value="<?php echo $min_prob !== null ? $h(sprintf('%.3f', floatval($min_prob))) : ''; ?>" onchange="spMinProb(this, <?php echo $h(json_encode($sci)); ?>)">
				<small><?php echo t('str_empty_the_station_minimum_pct', array('pct' => fmt_num($sf_thresh * 100, 1))); ?></small></label>
			<label title="<?php echo $h(t('str_audio_files_kept_for_this_days_help', array('days' => intval($config['PURGE_RECENT_DAYS'] ?? 3)))); ?>"><?php echo t('str_files_to_keep'); ?>
				<input type="number" id="sp_files" min="0" max="100000" step="1" placeholder="<?php echo intval($config['MAX_FILES_SPECIES'] ?? 0); ?>"
					value="<?php echo $max_files !== null ? intval($max_files) : ''; ?>" onchange="spFiles(this, <?php echo $h(json_encode($sci)); ?>)">
				<small><?php echo t('str_empty_global_n', array('n' => intval($config['MAX_FILES_SPECIES'] ?? 0))); ?></small></label>
			<label><?php echo t('str_notifications_colon'); ?> <select onchange="spTier(this, <?php echo $h(json_encode($sci)); ?>)" data-was="<?php echo $h($tier); ?>">
				<?php foreach (array('normal' => t('str_normal_tier'), 'muted' => t('str_muted_tier'), 'rare' => t('str_rare_tier')) as $tv => $tl) {
					echo '<option value="' . $tv . '"' . ($tier === $tv ? ' selected' : '') . '>' . $tl . ($tv === $default_tier ? ' ' . t('str_default_lc_paren') : '') . '</option>';
				} ?>
			</select></label>
			<label title="<?php echo t('str_a_validation_marker_you_have_help'); ?>"><input type="checkbox" <?php echo $in_confirmed ? 'checked' : ''; ?> onchange="spList(this, 'confirmed', <?php echo $h(json_encode($sci)); ?>)"> <?php echo t('str_confirmed'); ?></label>
			<label title="<?php echo t('str_accepted_even_when_its_help'); ?>"><input type="checkbox" <?php echo $in_whitelist ? 'checked' : ''; ?> onchange="spList(this, 'whitelist', <?php echo $h(json_encode($identifier)); ?>)"> <?php echo t('str_whitelist'); ?></label>
			<label title="<?php echo t('str_never_detected_again'); ?>"><input type="checkbox" <?php echo $in_exclude ? 'checked' : ''; ?> onchange="spList(this, 'exclude', <?php echo $h(json_encode($identifier)); ?>)"> <?php echo t('str_excluded_sp'); ?></label>
			<?php $ndel = deleted_count($sci); if ($ndel) { ?>
				<div class="spdel"><?php echo t('str_deleted_detections'); ?> <b><?php echo $ndel; ?></b>
					<button type="button" class="restorebtn" onclick="restoreRemoved(<?php echo $h(json_encode($sci)); ?>, this)"><?php echo t('str_restore'); ?></button>
					<button type="button" class="wipebtn" onclick="wipeDeleted(<?php echo $h(json_encode($sci)); ?>, this)"><?php echo t('str_purge'); ?></button></div>
			<?php } ?>
		</div>
		<div class="card" id="spreviews"><h3><?php echo t('str_reviews'); ?></h3>
			<?php // the answers as the Review button names them (owner 2026-10-22); the thresholds live in Precision below ?>
			<?php echo t('str_yes_state'); ?> = <b><?php echo $reviews['yes']; ?></b><br>
			<?php echo t('str_changed'); ?> = <b><?php echo $reviews['chg']; ?></b> <small><?php echo t('str_changed_to_this_species'); ?></small><br>
			<?php echo t('str_changed_to_another_species'); ?> = <b><?php echo $reviews['away']; ?></b><br>
			<?php echo t('str_review_later'); ?> = <b><?php echo $reviews['later']; ?></b><br>
			<?php echo t('str_ignored'); ?> = <b><?php echo $reviews['ign']; ?></b>
			<?php if (!array_sum($reviews)) { ?><br><small><?php echo t('str_use_the_review_button_on_a_help'); ?></small><?php } ?>
		</div>
	</div>
	<?php if (!$none) { ?>
	<?php // Precision (owner 2026-10-22): from which confidence and which probability of occurrence the detections of this
	// species are real, from every review kept in the database; drawn by spPrecision() below ?>
	<h3 class="section" id="precision"><?php echo t('str_precision'); ?></h3>
	<div class="card spprec" id="spprec" data-sci="<?php echo $h($sci); ?>" data-conf="<?php echo $h(json_encode($prec)); ?>"
		data-cur-conf="<?php echo $h((string)($threshold ?? '')); ?>" data-global-conf="<?php echo floatval($config['CONFIDENCE'] ?? 0.7); ?>"
		data-cur-prob="<?php echo $h((string)($min_prob ?? '')); ?>" data-global-prob="<?php echo $sf_thresh; ?>"
		data-nconf="<?php echo $h(json_encode($n_conf)); ?>" data-nprob="<?php echo $h(json_encode($n_prob)); ?>">
		<p><?php echo t('str_this_section_shows_from_which_help'); ?><br>
			<?php echo t('str_right_answered_yes'); ?><br>
			<?php echo t('str_false_human_noise_ignore_or_help'); ?><br>
			<?php echo t('str_review_later_does_not_count'); ?></p>
		<?php if ($bands_rev) { ?>
		<h4><?php echo t('str_where_to_review'); ?></h4>
		<p><?php echo t('str_each_line_is_a_confidence_band'); ?><br>
			<?php echo t('str_review_the_highlighted_band_help'); ?></p>
		<table class="stdtable spbands">
			<tr><th><?php echo t('str_confidence'); ?></th><th class="num"><?php echo t('str_right'); ?></th><th class="num"><?php echo t('str_false'); ?></th><th class="num"><?php echo t('str_precision'); ?></th><th class="num"><?php echo t('str_without_an_answer'); ?></th><th data-nosort></th></tr>
			<?php foreach ($bands_rev as $i => $b) {
				$n = $b['y'] + $b['f'];
				echo '<tr' . ($i === $critical ? ' class="spprec-best" title="' . $h(t('str_the_most_critical_band_review_help')) . '"' : '') . '><td>' . ($i * 10) . ' - ' . ($i * 10 + 10) . '%' . ($i === $critical ? ' &#9733;' : '') . '</td>'
					. '<td class="num">' . $b['y'] . '</td><td class="num">' . $b['f'] . '</td><td class="num">' . ($n ? round(100 * $b['y'] / $n) . '%' : '-') . '</td><td class="num">' . fmt_num($b['w']) . '</td><td style="text-align:right !important">';
				if ($b['samples']) {
					echo '<button type="button" class="openbtn" onclick="reviewDetection(document.querySelector(\'#band' . $i . ' [data-ri]\'))">' . t('str_review_n', array('n' => count($b['samples']))) . '</button>'
						. '<div id="band' . $i . '" data-review-list="1" style="display:none">';
					foreach ($b['samples'] as $r) {
						$f = $r['Date'] . '/' . species_folder($sci) . '/' . $r['File_Name'];
						echo '<div' . review_item_attrs($f, $r['Com_Name'] . ', ' . $r['Date'] . ' ' . $r['Time'] . ', ' . round($r['Confidence'] * 100) . '%', $sci, $r) . '>' . validate_button($f, '') . '</div>';
					}
					echo '</div>';
				}
				echo '</td></tr>';
			} ?>
		</table>
		<?php } ?>
		<h4><?php echo t('str_threshold'); ?></h4>
		<p><?php echo t('str_each_line_keeps_only_the_help'); ?><br>
			<?php echo t('str_precision_right_divided_by_help'); ?><br>
			<?php echo t('str_guaranteed_the_worst_likely_help'); ?><br>
			<?php echo t('str_real_lost_estimate_of_the_real_help'); ?><br>
			<?php echo t('str_a_threshold_applies_to_new_help'); ?></p>
		<?php // how and why (owner 2026-10-27): the method, the change of that day and the papers, folded under the threshold notes ?>
		<details class="small" style="margin-top:8px"><summary><?php echo t('str_how_the_threshold_is_computed_help'); ?></summary>
			<p><?php echo t('str_the_birdnet_confidence_is_not_a_help'); ?><br>
				<?php echo t('str_that_is_why_each_species_gets_help'); ?><br>
				<?php echo t('str_where_to_review_highlights_the_help'); ?></p>
			<p><b><?php echo t('str_precision'); ?></b><br>
				<?php echo t('str_each_confidence_band_counts_help'); ?><br>
				<?php echo t('str_covered_the_share_of_the_help'); ?><br>
				<?php echo t('str_guaranteed_the_lower_end_of_the_help'); ?></p>
			<p><b><?php echo t('str_logistic'); ?></b><br>
				<?php echo t('str_a_curve_of_the_chance_of_being_help'); ?><br>
				<?php echo t('str_it_needs_fewer_reviews_and_does_help'); ?><br>
				<?php echo t('str_its_value_is_the_confidence_at_help'); ?></p>
			<p><b><?php echo t('str_change_of_2026_10_27'); ?></b><br>
				<?php echo t('str_the_precision_used_to_add_up_help'); ?><br>
				<?php echo t('str_since_the_reviews_follow_the_help'); ?><br>
				<?php echo t('str_now_each_band_is_weighted_by_help'); ?></p>
			<p><b><?php echo t('str_references'); ?></b><br>
				Wood, C.M. &amp; Kahl, S. (2024). <a href="https://doi.org/10.1007/s10336-024-02144-5" target="_blank" rel="noopener">Guidelines for appropriate use of BirdNET scores and other detector outputs</a>. Journal of Ornithology 165: 777–782.<br>
				Kahl, S., Wood, C.M., Eibl, M. &amp; Klinck, H. (2021). <a href="https://doi.org/10.1016/j.ecoinf.2021.101236" target="_blank" rel="noopener">BirdNET: A deep learning solution for avian diversity monitoring</a>. Ecological Informatics 61: 101236.<br>
				Knight, E.C. et al. (2017). <a href="https://doi.org/10.5751/ACE-01114-120214" target="_blank" rel="noopener">Recommendations for acoustic recognizer performance assessment with application to five common automated signal recognition programs</a>. Avian Conservation and Ecology 12(2): 14.<br>
				Cochran, W.G. (1977). Sampling Techniques, 3rd ed., Wiley (stratified estimation).<br>
				Wilson, E.B. (1927). <a href="https://doi.org/10.1080/01621459.1927.10502953" target="_blank" rel="noopener">Probable inference, the law of succession, and statistical inference</a>. Journal of the American Statistical Association 22: 209–212.<br>
				Brown, L.D., Cai, T.T. &amp; DasGupta, A. (2001). <a href="https://doi.org/10.1214/ss/1009213286" target="_blank" rel="noopener">Interval estimation for a binomial proportion</a>. Statistical Science 16: 101–133.</p>
		</details>
		<label><?php echo t('str_target'); ?> <select class="spprec-target"><option value="0.8">80%</option><option value="0.9" selected>90%</option><option value="0.95">95%</option></select>
			<small><?php echo t('str_precision_with_at_least_reviews_help', array('n' => 20)); ?></small></label>
		<div class="spprec-out"></div>
	</div>
	<h3 class="section"><?php echo t('str_best_detections'); ?></h3>
	<div class="clips" data-review-list="1">
	<?php foreach ($best as $b) {
		$file = $b['Date'] . '/' . species_folder($sci) . '/' . $b['File_Name'];
		// the spectrogram picture; a click opens it playing, like "open" in Latest detections
		$label = $com . ', ' . $b['Date'] . ' ' . $b['Time'] . ', ' . round($b['Confidence'] * 100) . '%';
		// the Review button on the line of the date and confidence (owner 2026-10-21)
		echo '<div class="clip"' . review_item_attrs($file, $label, $sci, $b + array('Com_Name' => $com)) . '><div class="cliphead"><span><b>' . $h($b['Date'] . ' ' . $b['Time']) . '</b>, ' . round($b['Confidence'] * 100) . '%</span>' . detection_actions($file, false) . '</div>'
			. '<img class="clipspec" loading="lazy" src="' . $h(clip_spectrogram_thumb('/By_Date/' . $file)) . '" alt="' . $h(t('str_spectrogram')) . '" title="' . $h(t('str_listen_and_review')) . '" onclick="reviewDetection(this)"></div>';
	} ?>
	</div>
	<h3 class="section" id="latest"<?php echo !empty($rv_more) ? ' data-rv-more="1"' : ''; ?>><?php echo $review_mode ? t('str_to_review_without_an_answer', array('n' => count($recent) . (!empty($rv_more) ? '+' : ''))) : t('str_latest_detections'); ?></h3>
	<?php // the standard detection list: a click on a row opens the review player, which goes on down the list
	echo $recent ? str_replace('<table class="list stdtable">', '<table class="list stdtable" data-pager="' . htmlspecialchars(t('str_last_detections'), ENT_QUOTES) . '">', detection_review_table($recent, false)) : '<p>' . t('str_every_detection_of_this_species_help') . '</p>'; ?>
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
			// the review's return point (set by the call that started it), else Validation
			if (!(window.rpReturnGo && rpReturnGo())) location.href = 'views.php?view=Review';
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
<script>window.BS_SPECIES = {title: <?php echo json_encode($com . ' (' . $sci . ')'); ?>};</script>
<script src="static/species-page.js?v=<?php echo @filemtime(__DIR__ . "/../homepage/static/species-page.js"); ?>"></script>
