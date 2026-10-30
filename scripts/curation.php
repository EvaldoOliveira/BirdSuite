<?php
/* Curation (owner 2026-10-09): how far the review of the station's detections has gone — per species the share
 * reviewed, the answers, how often the model was right (precision = yes / (yes + not this bird)), the species threshold
 * and the last review; the causes of the rejections; and the latest reviews with Undo.
 * Opened as views.php?view=Curation. */
require_once __DIR__ . '/common.php';
$db = get_db();
$h = function ($s) { return htmlspecialchars((string)$s, ENT_QUOTES); };
$has = $db->querySingle("SELECT 1 FROM sqlite_master WHERE type='table' AND name='detection_reviews'");
// Latest reviews, a page at a time like Now (owner 2026-10-20): Last 10 / 25 / 50 / 100, Newer / Older; one extra row
// says whether older ones exist. curation.php?recent=1&limit=N&offset=M answers the table alone (the page reloads it)
function recent_reviews_html($db, $limit, $offset) {
  $h = function ($s) { return htmlspecialchars((string)$s, ENT_QUOTES); };
  $hasReason = false;
  $ti = $db->query("PRAGMA table_info(detection_reviews)");
  while ($ti && ($c = $ti->fetchArray(SQLITE3_ASSOC))) if ($c['name'] === 'Reason') $hasReason = true;
  // the reviews of the location the pages show, the deleted detections included (2026-10-20)
  $where = '';
  if (count(locations_load()) >= 2 && ($l = location_shown()) !== null) {
    $hasDeleted = (bool)$db->querySingle("SELECT 1 FROM sqlite_master WHERE type='table' AND name='deleted_detections'");
    if (detections_location_state() === 'all' && intval($l['id'] ?? 0) > 0) {
      // every row tagged (owner 2026-10-21): the review's detection, live or deleted, by its tag — 10 ms instead of 0.7 s
      $join = $hasDeleted ? " LEFT JOIN deleted_detections x ON x.File_Name = r.File_Name" : '';
      $where = $join . " WHERE (d.Location = " . intval($l['id']) . ($hasDeleted ? " OR x.Location = " . intval($l['id']) : '') . ")";
    } else {
      $where = " WHERE r.File_Name IN (SELECT File_Name FROM main.detections WHERE " . location_sql($l)
        . ($hasDeleted ? " UNION SELECT File_Name FROM deleted_detections WHERE " . location_sql($l, true) : '') . ")";
    }
  }
  $res = $db->query("SELECT r.File_Name, r.Sci_Name, r.Com_Name, r.Date, r.Confidence, r.Verdict, r.Reviewed_At" . ($hasReason ? ", r.Reason" : ", '' AS Reason")
    . ", d.Time FROM detection_reviews r LEFT JOIN main.detections d ON d.File_Name = r.File_Name" . $where . " ORDER BY r.Reviewed_At DESC LIMIT " . ($limit + 1) . " OFFSET " . $offset);
  $recent = array();
  while ($res && ($r = $res->fetchArray(SQLITE3_ASSOC))) $recent[] = $r;
  $more = count($recent) > $limit;
  $recent = array_slice($recent, 0, $limit);
  if (!$recent) return '<p>' . t('No reviews yet.') . '<br>' . t('Use the Review button on any detection.') . '</p>';
  $out = '<table class="stdtable"><tr><th>' . t('Reviewed') . '</th><th><select class="namemode" title="' . t('Names shown') . '"><option value="com">' . t('Common name') . '</option><option value="sci">' . t('Scientific name') . '</option><option value="en">' . t('English name') . '</option></select></th>'
    . '<th>' . t('Detection') . '</th><th class="num">' . t('Confidence') . '</th><th>' . t('Answer') . '</th><th data-nosort></th></tr>';
  // cause codes stay English in the database; only their labels are translated
  $causes = array('insect' => t('Insect'), 'dog' => t('Dog'), 'rain' => t('Rain / wind'), 'human' => t('Human'),
    'engine' => t('Engine'), 'noise' => t('Noise'), 'ignore' => t('Ignore'), 'mechanical' => t('Mechanical / noise'), 'other bird' => t('Another bird (unknown)'), 'unknown' => t('Unknown'));
  foreach ($recent as $r) {
    // the answer as the Review button names it (owner 2026-10-22)
    $ans = $r['Verdict'] === 'yes' ? '✓ ' . (($r['Reason'] ?? '') === 'changed' ? t('Changed') : t('Yes'))
      : ($r['Verdict'] === 'no' ? '✗ ' . ($causes[$r['Reason'] ?? ''] ?? t('Change species')) : '? ' . t('Review later'));
    $out .= '<tr><td>' . $h($r['Reviewed_At']) . '</td><td><a href="views.php?view=Bird&amp;sci=' . rawurlencode($r['Sci_Name']) . '" data-com="' . $h($r['Com_Name']) . '" data-sci="' . $h($r['Sci_Name']) . '" data-en="' . $h(get_english_name($r['Sci_Name'])) . '">' . $h($r['Com_Name']) . '</a></td>'
      . '<td>' . $h($r['Date'] . ' ' . ($r['Time'] ?? '')) . '</td><td class="num">' . round($r['Confidence'] * 100) . '%</td>'
      . '<td>' . $h($ans) . '</td>'
      . '<td><button type="button" class="openbtn" onclick="undoReview(' . $h(json_encode($r['File_Name'])) . ', this)" title="' . t('Remove this answer') . '">' . t('Undo') . '</button></td></tr>';
  }
  $out .= '</table>';
  $out .= list_pager(t('Last {select} reviews', array('select' => '{select}')), $offset, count($recent), $limit, $more, 'curRecentCount', 'curRecent');
  return $out;
}
if (isset($_GET['recent'])) {
  $lim = in_array(intval($_GET['limit'] ?? 10), array(10, 25, 50, 100), true) ? intval($_GET['limit']) : 10;
  echo $has ? recent_reviews_html($db, $lim, max(0, intval($_GET['offset'] ?? 0))) : '<p>' . t('No reviews yet.') . '<br>' . t('Use the Review button on any detection.') . '</p>';
  die();
}
// Calibrate thresholds (owner 2026-10-28): every species with reviews, as the species page's Precision reads them —
// [confidence, null, right 1 / false 0] per review (Yes = right; Dog, Human, Engine, Noise, Ignore and the older causes,
// and every detection changed to another species = false), its detections per 5 % band, and the threshold in force;
// static/precision.js computes the suggestions in the page
if (isset($_GET['calib'])) {
  header('Content-Type: application/json');
  session_write_close();
  $own = array();
  foreach (@file(get_home() . '/BirdNET-Pi/config/species_lists/species_confidence.txt', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: array() as $l) {
    $p = explode('=', trim($l), 2);
    if (count($p) === 2) $own[$p[0]] = floatval($p[1]);
  }
  $excl = array_flip(array_map(function ($l) { return explode('_', trim($l), 2)[0]; }, @file(BS_LISTS . '/exclude_species_list.txt', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: array()));
  $sp = array();
  $add = function ($sci, $com, $conf, $ok) use (&$sp) {
    if (!isset($sp[$sci])) $sp[$sci] = array('sci' => $sci, 'com' => $com, 'rows' => array(), 'nconf' => array_fill(0, 21, 0));
    $sp[$sci]['rows'][] = array(floatval($conf), null, $ok);
  };
  if ($has) {
    $hasOrig = (bool)$db->querySingle("SELECT 1 FROM pragma_table_info('detection_reviews') WHERE name = 'Orig_Sci'");
    $res = $db->query("SELECT Sci_Name, Com_Name, Confidence, Verdict, COALESCE(Reason, '') AS Reason" . ($hasOrig ? ", Orig_Sci, Orig_Com" : '') . " FROM detection_reviews");
    while ($res && ($r = $res->fetchArray(SQLITE3_ASSOC))) {
      if ($r['Verdict'] === 'yes' && $r['Reason'] !== 'changed') $add($r['Sci_Name'], $r['Com_Name'], $r['Confidence'], 1);
      elseif ($r['Verdict'] === 'no' && in_array($r['Reason'], array('dog', 'human', 'engine', 'noise', 'ignore', 'insect', 'rain', 'mechanical'), true)) $add($r['Sci_Name'], $r['Com_Name'], $r['Confidence'], 0);
      if (!empty($r['Orig_Sci'])) $add($r['Orig_Sci'], $r['Orig_Com'] ?: $r['Orig_Sci'], $r['Confidence'], 0);
    }
  }
  $sp = array_diff_key($sp, $excl);  // an excluded species is no longer detected: nothing to calibrate
  if ($sp) {
    $in = implode(',', array_map(function ($s) { return "'" . SQLite3::escapeString($s) . "'"; }, array_keys($sp)));
    $res = $db->query("SELECT Sci_Name, MIN(20, CAST(Confidence * 20 AS INTEGER)) AS b, COUNT(*) AS n FROM detections WHERE Sci_Name IN ($in) GROUP BY Sci_Name, b");
    while ($res && ($r = $res->fetchArray(SQLITE3_ASSOC))) $sp[$r['Sci_Name']]['nconf'][max(0, intval($r['b']))] += intval($r['n']);
  }
  foreach ($sp as $sci => $s) { $sp[$sci]['own'] = $own[$sci] ?? null; $sp[$sci]['en'] = get_english_name($sci); }
  echo json_encode(array('global' => floatval(get_config()['CONFIDENCE'] ?? 0.7), 'species' => array_values($sp)), JSON_UNESCAPED_UNICODE);
  die();
}
$over = array();
foreach (@file(get_home() . '/BirdNET-Pi/config/species_lists/species_confidence.txt', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: array() as $l) {
  $p = explode('=', trim($l), 2);
  if (count($p) === 2) $over[$p[0]] = floatval($p[1]);
}
// the species lists, as in Species Pages (owner 2026-10-18: curate the unconfirmed species first)
$list_sci = function ($f) { return array_flip(array_map(function ($l) { return explode('_', trim($l), 2)[0]; }, @file(BS_LISTS . '/' . $f, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: array())); };
$confirmed = $list_sci('confirmed_species_list.txt');
$whitelisted = $list_sci('whitelist_species_list.txt');
$excluded = $list_sci('exclude_species_list.txt');
$tiers = array();
foreach (@file(get_home() . '/BirdNET-Pi/config/species_lists/notification_tiers.txt', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: array() as $l) {
  $p = explode('=', trim($l), 2);
  if (count($p) === 2) $tiers[$p[0]] = strtolower($p[1]);
}
$default_tier = strtolower(get_config()['NOTIFICATION_DEFAULT_TIER'] ?? 'normal');
// the period (owner 2026-10-22, in the filter line as By Hour; Last 24 h instead of Today, owner 2026-10-23): the last 7 days until another one is picked (common.php validation_period); the counts,
// the answers and the work left are those of the detections of the period
list($pfrom, $pto, $psince) = validation_period(true) + array(2 => '');
if ($pfrom !== '' && $pto < $pfrom) { $tmp = $pfrom; $pfrom = $pto; $pto = $tmp; }
$pq = function ($sql) use ($db, $pfrom, $pto, $psince) {
  $st = $db->prepare($sql);
  if ($pfrom !== '') { $st->bindValue(':pf', $pfrom); $st->bindValue(':pt', $pto); }
  if ($psince !== '' && strpos($sql, ':ps') !== false) $st->bindValue(':ps', $psince);
  return $st->execute();
};
// Last 24 h (owner 2026-10-23): the two dates plus the moment, so the index on Date still narrows the rows
$pd = $pfrom !== '' ? ' AND Date BETWEEN :pf AND :pt' . ($psince !== '' ? " AND Date || ' ' || Time >= :ps" : '') : '';
$rows = array();
$res = $pq('SELECT Sci_Name, MAX(Com_Name) AS com, COUNT(*) AS n, MAX(Confidence) AS maxc FROM detections WHERE 1' . $pd . ' GROUP BY Sci_Name');
while ($res && ($r = $res->fetchArray(SQLITE3_ASSOC))) $rows[$r['Sci_Name']] = $r + array('yes' => 0, 'no' => 0, 'unsure' => 0, 'last' => '');
$recent = array();
if ($has) {
  // detections still in the table without any answer (the answers of deleted detections do not count)
  // = the detections of the period minus the answered ones, found through the answers (2026-10-23 test run: NOT EXISTS on
  // every detection cost 1.2 s at ten times the station's data)
  foreach ($rows as $s => $r) $rows[$s]['todo'] = intval($r['n']);
  $res = $pq("SELECT Sci_Name, COUNT(*) AS c FROM detections WHERE File_Name IN (SELECT File_Name FROM detection_reviews)" . $pd . " GROUP BY Sci_Name");
  while ($res && ($r = $res->fetchArray(SQLITE3_ASSOC))) if (isset($rows[$r['Sci_Name']])) $rows[$r['Sci_Name']]['todo'] = max(0, $rows[$r['Sci_Name']]['todo'] - intval($r['c']));
  // the answers of the period: those of its detections (every answer, also of deleted detections, when no period),
  // per species and answer, with the Ignored and Changed ones counted in the same pass (owner 2026-10-29: two reads of
  // the answers were two thirds of the page on a station with 177,000 detections)
  // per species (owner 2026-10-22): 'ign' = answers with a cause (Dog, Human, Engine, Noise, Ignore and the older insect,
  // rain, mechanical), 'chg' = the Yes written by Change species (reason 'changed')
  $hasReasonCol = false;
  $tic = $db->query("PRAGMA table_info(detection_reviews)");
  while ($tic && ($cc = $tic->fetchArray(SQLITE3_ASSOC))) if ($cc['name'] === 'Reason') $hasReasonCol = true;
  $cnt = $hasReasonCol ? ", SUM(CASE WHEN r.Verdict = 'no' AND r.Reason IN ('dog','human','engine','noise','ignore','insect','rain','mechanical') THEN 1 ELSE 0 END) AS ign,
      SUM(CASE WHEN r.Verdict = 'yes' AND r.Reason = 'changed' THEN 1 ELSE 0 END) AS chg" : '';
  $res = $pfrom === '' ? $db->query("SELECT r.Sci_Name, r.Verdict, COUNT(*) AS c, MAX(r.Reviewed_At) AS last$cnt FROM detection_reviews r GROUP BY r.Sci_Name, r.Verdict")
    // the period's files once, then each answer by its file: a join walked the period's detections once per answer when a
    // location is shown (detections is then a view and its File_Name index is not used; 2026-10-29: 5 s per query)
    : $pq("SELECT r.Sci_Name, r.Verdict, COUNT(*) AS c, MAX(r.Reviewed_At) AS last$cnt FROM detection_reviews r WHERE r.File_Name IN (SELECT File_Name FROM detections WHERE 1" . $pd . ") GROUP BY r.Sci_Name, r.Verdict");
  if ($hasReasonCol) foreach ($rows as $s => $r) { $rows[$s]['ign'] = 0; $rows[$s]['chg'] = 0; }
  while ($res && ($r = $res->fetchArray(SQLITE3_ASSOC))) {
    if (!isset($rows[$r['Sci_Name']])) continue;
    $rows[$r['Sci_Name']][$r['Verdict']] = intval($r['c']);
    if ($r['last'] > $rows[$r['Sci_Name']]['last']) $rows[$r['Sci_Name']]['last'] = $r['last'];
    if ($hasReasonCol) { $rows[$r['Sci_Name']]['ign'] += intval($r['ign']); $rows[$r['Sci_Name']]['chg'] += intval($r['chg']); }
  }
}
$total = array_sum(array_column($rows, 'n'));
// the answers as the Review button names them (owner 2026-10-22): Yes, Changed (Change species), Review later (with the older
// "not this species" without a cause or "another bird"), Ignored (Dog, Human, Engine, Noise, Ignore and the older causes)
$rev = 0; $yes = 0; $chg = 0; $uns = 0; $ign = 0;
foreach ($rows as $sci => $r) {
  $rows[$sci]['a_chg'] = $c = intval($r['chg'] ?? 0);
  $rows[$sci]['a_ign'] = $i = intval($r['ign'] ?? 0);
  $rows[$sci]['a_yes'] = $r['yes'] - $c;
  $rows[$sci]['a_unk'] = $r['unsure'] + $r['no'] - $i;
  $rev += $r['yes'] + $r['no'] + $r['unsure']; $yes += $r['yes'] - $c; $chg += $c; $uns += $r['unsure'] + $r['no'] - $i; $ign += $i;
}
// the default order (owner 2026-10-19): first the species not confirmed, with no "yes" / "not this bird" answer and
// still detections without an answer (the work left), then the rest with the unconfirmed ones first; inside each, the location model's probability this
// week (as in Species Pages), least expected first — where a review matters most
$sf_thresh = floatval(get_config()['SF_THRESH'] ?? 0);
// default cut between "Not reviewed yet" and "Low probability" (owner 2026-10-19); Loc. Thresh. cannot be the cut,
// every detected species is above it (the model drops the rest)
$low_default = 0.9;  // 90 % since 2026-10-22 (owner: the factory default)
foreach ($rows as $sci => $r) {
  $rows[$sci]['prob'] = location_probability($sci, date('Y-m-d'));
  $rows[$sci]['conf'] = isset($confirmed[$sci]) ? 1 : 0;
  $rows[$sci]['wl'] = isset($whitelisted[$sci]) ? 1 : 0;
  if (!isset($r['todo'])) $rows[$sci]['todo'] = $has ? 0 : intval($r['n']);
  // leaves the species still to review once a detection is answered Yes (confirmed by ear) or with a cause / another
  // species (already judged), or none is left without an answer; Review later keeps it there
  $rows[$sci]['work'] = (!$rows[$sci]['conf'] && !isset($excluded[$sci]) && $r['yes'] == 0 && $r['no'] == 0 && $rows[$sci]['todo'] > 0) ? 0 : 1;
}
// inside each table (owner 2026-10-19): the excluded species last, the confirmed ones before them, the lowest confidence first
uasort($rows, function ($a, $b) use ($excluded) {
  return ((isset($excluded[$a['Sci_Name']]) ? 1 : 0) - (isset($excluded[$b['Sci_Name']]) ? 1 : 0)) ?: ($a['conf'] - $b['conf']) ?: ($a['maxc'] <=> $b['maxc']);
});
?>
<style>
.cur { display: inline-block; max-width: 100%; box-sizing: border-box; margin: 0 4px; text-align: left; padding: 0 12px; } /* as Species Pages: as wide as its table (owner 2026-10-20) */
.cur .kpis { display: flex; flex-wrap: wrap; gap: 10px; margin: 8px 0 14px; }
.cur .kpi { background: rgba(255,255,255,.55); border-radius: 8px; padding: 8px 14px; }
.cur .kpi b { display: block; font-size: 1.4em; }
.cur .kpi small.kf { display: block; font-size: 11px; opacity: .8; }
.cur .kpi b .ku { display: inline; font-size: .6em; font-weight: normal; } /* the unit on the number's line (owner 2026-10-21) */
.cur .bar { display: inline-block; width: 60px; height: 8px; background: rgba(128,128,128,.25); border-radius: 4px; vertical-align: middle; margin-right: 6px; }
.cur .bar span { display: block; height: 100%; background: #2e7d32; border-radius: 4px; }
.cur h3 { margin: 18px 0 8px; }
.cur .curflag { display: inline-block; width: 12px; height: 12px; border: 1.5px solid #888; border-radius: 50%; cursor: pointer; vertical-align: middle; }
/* five groups in one table (owner 2026-10-19): every row in its group's colour, the legend in the same colours;
   orange Reviewed cell = none of its detections reviewed */
/* phones (owner 2026-10-21, as By Hour): the cards two per row */
@media screen and (max-width: 800px) { .cur .kpis { display: grid !important; grid-template-columns: 1fr 1fr; gap: 8px; } .cur .kpis .kpi { margin: 0 !important; min-width: 0; } }
.cur .curlegend { display: flex; flex-direction: column; align-items: stretch; gap: 6px; margin: 4px 0 8px; }
.cur .curline { display: flex; flex-wrap: wrap; align-items: center; gap: 6px; }
.cur .curline .curright { margin-left: auto; }
/* one line while the filter is closed: the date (always shown, Today by default) and, on the right, the Filter / Sort icons
   (global rule: right edge, Filter then Sort); open, the species search joins the icons and the second line (Low Prob.,
   the chips) appears (owner 2026-10-22) */
.cur .curlegend .curline .curtools { flex: 0 1 auto; margin-left: 0; }
.cur .curlegend .curline .curright + .curtools { margin-left: 0; }
.cur .curlegend:not(.fopen) .curline .curtools { margin-left: auto; }
.cur .curlegend:not(.fopen) .spfilter, .cur .curlegend:not(.fopen) .curline2 { display: none !important; }

.cur .curlegend:not(.sopen) .hmsort > small, .cur .curlegend:not(.sopen) .hmsort > button { display: none !important; }
.cur .curlegend:not(.sopen):not(.fopen) .hmsort { display: none !important; }
.cur .curlegend .curtools .hmsort { display: inline-flex; align-items: center; gap: 4px; }
.cur .dashfilter { cursor: pointer; border-radius: 50%; padding: 3px; display: inline-flex; color: var(--accent,#2b5e22); }
.cur .curlegend.fopen .dashfilter { background: var(--accent,#2b5e22); color: #fff; }
.cur .sortopen { border: 1px solid var(--accent,#2b5e22); background: #fff; color: var(--accent,#2b5e22); cursor: pointer; }
.cur .sortopen.on { background: var(--accent,#2b5e22); color: #fff; }

.cur .curchip { padding: 3px 10px; border-radius: 12px; cursor: pointer; user-select: none; font-size: .9em; }
.cur .curchip.off { opacity: .4; text-decoration: line-through; }
.cur .curlegend .curthr { margin-right: 6px; font-size: .9em; }
.cur .curlegend .curtools { margin-left: auto; display: inline-flex; gap: 10px; align-items: center; flex-wrap: wrap; }
.cur details.currecentbox { margin-top: 18px; }
.cur details.currecentbox > summary { cursor: pointer; }
.cur details.currecentbox > summary h3 { margin: 0; }
.cur .curlegend .curthr select { padding: 3px 6px; font-size: 13px; border-radius: 10px; }
/* named and coloured as the answers of the Review button (owner 2026-10-22): Yes / Changed green, Review later grey,
   Ignored red; Low Prob. orange. Fixed solid colours in the chips' order (owner 2026-10-24: light red, yellow, green,
   orange, blue, then Others light grey and Ignored mid grey, last: owner 2026-10-24), black text, the same in every theme
   and in the dark scheme */
.cur .curchip.g-curlow, .cur #curtable tr.g-curlow td { background: #f8c9c4; }
.cur .curchip.g-curlow, .cur #curtable tr.g-curlow td:not(.pgood):not(.pbad) { color: #000; }  /* light red */
.cur .curchip.g-curtodo, .cur #curtable tr.g-curtodo td { background: #fff2a8; }
.cur .curchip.g-curtodo, .cur #curtable tr.g-curtodo td:not(.pgood):not(.pbad) { color: #000; }  /* light yellow */
.cur .curchip.g-currest, .cur #curtable tr.g-currest td { background: #c8e6c9; }
.cur .curchip.g-currest, .cur #curtable tr.g-currest td:not(.pgood):not(.pbad) { color: #000; }  /* light green */
.cur .curchip.g-curno, .cur #curtable tr.g-curno td { background: #ffd8a8; }
.cur .curchip.g-curno, .cur #curtable tr.g-curno td:not(.pgood):not(.pbad) { color: #000; }  /* light orange */
.cur .curchip.g-curunk, .cur #curtable tr.g-curunk td { background: #cfe3f7; }
.cur .curchip.g-curunk, .cur #curtable tr.g-curunk td:not(.pgood):not(.pbad) { color: #000; }  /* light blue */
.cur .curchip.g-curexcl, .cur #curtable tr.g-curexcl td { background: #e6e6e6; }
.cur .curchip.g-curexcl, .cur #curtable tr.g-curexcl td:not(.pgood):not(.pbad) { color: #000; }  /* light grey */
.cur .curchip.g-curign, .cur #curtable tr.g-curign td { background: #bdbdbd; }
.cur .curchip.g-curign, .cur #curtable tr.g-curign td:not(.pgood):not(.pbad) { color: #000; }  /* mid grey */
.cur #curtable.hide-curlow tr.g-curlow, .cur #curtable.hide-curtodo tr.g-curtodo, .cur #curtable.hide-currest tr.g-currest,
.cur #curtable.hide-curno tr.g-curno, .cur #curtable.hide-curunk tr.g-curunk, .cur #curtable.hide-curign tr.g-curign,
.cur #curtable.hide-curexcl tr.g-curexcl { display: none !important; }
.cur .curtable th { cursor: pointer; }
.cur .curtable td.curgrp { white-space: nowrap; font-size: .9em; }
.cur .currm { white-space: nowrap; padding: 3px 8px; border-radius: 6px; border: 1px solid #c62828; background: #fff; color: #c62828; cursor: pointer; font-size: .85em; }
.cur .currm:hover { background: #c62828; color: #fff; }
/* none of its detections reviewed: the bar's grey (owner 2026-10-24: it was orange), so the empty bar disappears */
.cur .curtable td.cur-none { background: #dfdfdf !important; }
.cur .curtable td.cur-none .bar { background: transparent; }
/* Calibrate thresholds as a modal (owner 2026-10-28), the look of the species modal (static/species-modal.js askModal) */
#curcal { position: fixed; inset: 0; background: rgba(0,0,0,.45); display: flex; align-items: center; justify-content: center; z-index: 1000; }
#curcal[hidden] { display: none; }
#curcal .ccbox { background: #fff; color: #000; border-radius: 10px; width: calc(100% - 32px); max-width: 1100px; max-height: calc(100vh - 32px); display: flex; flex-direction: column; overflow: hidden; box-shadow: 0 6px 24px rgba(0,0,0,.35); text-align: left; font-size: 14px; }
#curcal .cchead { display: flex; align-items: center; gap: 10px; background: var(--accent,#2b5e22); color: #fff; padding: 10px 14px; }
#curcal .cchead h3 { margin: 0; font-size: 16px; color: #fff; flex: 1; }
#curcal .cchead button { background: none; border: none; color: #fff; font-size: 20px; cursor: pointer; width: auto; padding: 0 4px; }
#curcal .ccbody { padding: 12px 16px; overflow: auto; }
#curcal .ccbody p { margin: 0 0 10px; line-height: 1.4; }
#curcal .ccfoot { display: flex; align-items: center; justify-content: flex-end; gap: 8px; padding: 10px 16px; border-top: 1px solid #ddd; }
#curcal .ccfoot #curcalmsg { margin-right: auto; }
#curcal .ccfoot button { min-width: 72px; padding: 6px 14px; border-radius: 14px; border: 1px solid var(--accent,#2b5e22); background: #fff; color: var(--accent,#2b5e22); font-weight: 600; cursor: pointer; width: auto; }
#curcal .ccfoot button.primary { background: var(--accent,#2b5e22); color: #fff; }
#curcal .ccfoot button:disabled { opacity: .5; cursor: default; }
#curcal input.curcalv { width: 5em; }
#curcal td.curcalwhy { font-size: .9em; }
#curcal tr.curcalfine td { color: #555; }
#curcal tr.curcaldone td { opacity: .55; }
.cur .curflag.on { border: none; border-radius: 0; background: url(images/check.svg) center / contain no-repeat; }
</style>
<div class="cur">
  <?php echo location_label(); // the location shown (owner 2026-10-21) ?>
  <div class="kpis">
    <?php // cards set by the owner (2026-10-21): the count with its unit (det. = detections, sp. = species), then "of the
    // whole - %"; the same terms as By Hour and By Week (Confirmed = marked on the species page, Validated = a review Yes).
    // No accuracy card: most wrong detections are deleted, not answered, so it could only read too high
    $n_conf = count(array_filter($rows, function ($r) { return !empty($r['conf']); }));
    $n_valid = count(array_filter($rows, function ($r) { return $r['yes'] > 0; }));
    $n_sp = count($rows);
    $pc = function ($a, $b) { if (!$b) return '0%'; $v = 100 * $a / $b; return ($v > 0 && $v < 1 ? fmt_num($v, 1) : fmt_num($v)) . '%'; };
    $card = function ($title, $value, $unit, $of, $extra = '') use ($pc) {
      return '<div class="kpi"><small>' . $title . '</small><b>' . fmt_num($value) . ' <span class="ku">' . $unit . '</span></b>'
        . '<small class="kf">' . t('of {n}', array('n' => fmt_num($of))) . ' - ' . $pc($value, $of) . '</small>'
        . ($extra !== '' ? '<small class="kf">' . $extra . '</small>' : '') . '</div>';
    };
    echo $card(t('Reviewed'), $rev, t('det.'), $total)
      . $card(t('Sp. confirmed'), $n_conf, t('sp.'), $n_sp)
      . $card(t('Sp. validated'), $n_valid, t('sp.'), $n_sp, t('1 review = Yes'))
      . $card(t('Yes'), $yes, t('det.'), $rev)
      . $card(t('Changed'), $chg, t('det.'), $rev)
      . $card(t('Review later'), $uns, t('det.'), $rev)
      . $card(t('Ignored'), $ign, t('det.'), $rev); ?>
  </div>
  <?php // Start (owner 2026-10-18): the review of the detections without an answer, species by species in the order of the
  // table below (sorted and filtered as shown), from the first species that still has one ?>
  <div style="margin:0 0 12px"><button type="button" id="curstart" class="openbtn" onclick="curationStart()" title="<?php echo t('Reviews the detections without an answer, species by species in the order of the table below'); ?>">&#9654; <?php echo t('Start review'); ?></button>
    <?php // Calibrate thresholds (owner 2026-10-28): the species thresholds of every species whose reviews reach the
    // criteria of the species page's Precision, in a table to tick, edit and apply at once ?>
    <button type="button" id="curcalbtn" class="openbtn" onclick="curCalibrate()" title="<?php echo t('Suggests the threshold of every species whose reviews already reach the criteria of Precision'); ?>">&#9878; <?php echo t('Calibrate thresholds'); ?></button></div>
  <div id="curcal" hidden onclick="if (event.target === this) curCalClose()">
    <div class="ccbox" role="dialog" aria-modal="true" aria-labelledby="curcaltitle">
      <div class="cchead"><h3 id="curcaltitle"><?php echo t('Calibrate thresholds'); ?></h3><button type="button" onclick="curCalClose()" title="<?php echo t('Close'); ?>">&times;</button></div>
      <div class="ccbody">
        <p><?php echo t('The species whose reviews already reach the criteria of Precision on the species page: first the new suggestions, then those already at the threshold.'); ?><br>
          <?php echo t('Suggested = the lowest confidence whose precision reaches the target, with at least {n} reviews above it and 80% of its detections covered by reviews.', array('n' => 20)); ?><br>
          <?php echo t('The new suggestions come ticked; a value changed by hand ticks its species.'); ?><br>
          <?php echo t('A threshold applies to new detections only; the older ones stay in the database.'); ?></p>
        <label><?php echo t('Target:'); ?> <select id="curcaltarget" onchange="curCalDraw()"><option value="0.8">80%</option><option value="0.9" selected>90%</option><option value="0.95">95%</option></select></label>
        <div id="curcalout"><p><?php echo t('Loading...'); ?></p></div>
      </div>
      <div class="ccfoot"><span id="curcalmsg"></span><button type="button" onclick="curCalClose()"><?php echo t('Close'); ?></button><button type="button" class="primary" id="curcalapply" onclick="curCalApply(this)" disabled></button></div>
    </div>
  </div>
  <?php // One table in five groups (owner 2026-10-19): the species still to review (not confirmed, no "yes" / "not this
  // bird", detections without an answer) split by the location model's probability this week at the threshold picked in
  // the legend (default {$low_default}), then the reviewed, the judged and the excluded ones; each group keeps its own
  // default order and its colour on every row; a legend entry hides / shows its group (remembered in this browser) ?>
  <?php $rowhtml = array();
    foreach ($rows as $sci => $r) {
      $rv = $r['yes'] + $r['no'] + $r['unsure'];
      $pct = $r['n'] ? 100 * $rv / $r['n'] : 0;
      $prob = $r['prob'];
      $flag = function ($list, $on, $id) use ($h) {
        return '<td data-v="' . ($on ? 0 : 1) . '"><span class="curflag' . ($on ? ' on' : '') . '" data-list="' . $list . '" data-id="' . $h($id) . '" onclick="curToggle(this)" title="' . ($on ? t('Ticked: click to untick') : t('Click to tick')) . '"></span></td>';
      };
      $tier = $tiers[$sci] ?? $default_tier;
      if (!in_array($tier, array('normal', 'muted', 'rare'), true)) $tier = 'normal';
      $rowhtml[$sci] = '<tr data-work="' . ($r['work'] ? 0 : 1) . '" data-excl="' . (isset($excluded[$sci]) ? 1 : 0) . '" data-maxc="' . floatval($r['maxc']) . '" data-yes="' . intval($r['yes']) . '" data-wl="' . (isset($whitelisted[$sci]) ? 1 : 0) . '" data-n="' . intval($r['n']) . '" data-conf="' . $r['conf'] . '" data-todo="' . intval($r['todo']) . '" data-prob="' . ($prob === null ? -1 : sprintf('%.4f', $prob)) . '" data-tax="' . taxon_order($sci) . '" data-q="' . $h(mb_strtolower($r['com'] . ' ' . $sci . ' ' . get_english_name($sci))) . '"><td style="text-align:center">' . species_card_icon($sci) . '</td><td>' . '<a href="views.php?view=Bird&amp;sci=' . rawurlencode($sci) . '&amp;review=1" data-com="' . $h($r['com']) . '" data-sci="' . $h($sci) . '" data-en="' . $h(get_english_name($sci)) . '">' . $h($r['com']) . '</a>' . '</td>'
        . '<td class="num">' . fmt_num($r['n']) . '</td>'
        . '<td class="num" data-v="' . floatval($r['maxc']) . '">' . round($r['maxc'] * 100) . '%</td>'
        // the species' manual confidence beside the highest one (owner 2026-10-28): set by hand on the species page or by
        // Calibrate thresholds; '-' = none, the species follows the station's
        . (isset($over[$sci]) ? '<td class="num curmin" data-v="' . $over[$sci] . '">' . fmt_num($over[$sci], 2) . '</td>' : '<td class="num curmin" data-v="-1">-</td>')
        . ($prob === null ? '<td class="num" data-v="-1">-</td>'
          : '<td class="num ' . ($prob >= $sf_thresh ? 'pgood' : 'pbad') . '" data-v="' . sprintf('%.4f', $prob) . '">' . fmt_num($prob * 100, 1) . '%</td>')
        . $flag('confirmed', isset($confirmed[$sci]), $sci)
        . $flag('whitelist', isset($whitelisted[$sci]), $sci . '_' . $r['com'])
        . $flag('exclude', isset($excluded[$sci]), $sci . '_' . $r['com'])
        // the species' notification tier beside Excluded (owner 2026-10-29), the picker of Species Cards (species_tools.php settier)
        . '<td data-v="' . array('muted' => 0, 'normal' => 1, 'rare' => 2)[$tier] . '"><select class="curtier" data-was="' . $h($tier) . '" aria-label="' . $h(t('Notification') . ' (' . $r['com'] . ')') . '" onchange="curTier(' . $h(json_encode($sci)) . ', this)">'
          . implode('', array_map(function ($v) use ($tier) { return '<option value="' . $v . '"' . ($v === $tier ? ' selected' : '') . '>' . t(array('normal' => 'Normal', 'muted' => 'Muted', 'rare' => 'Rare')[$v]) . '</option>'; }, array('normal', 'muted', 'rare')))
          . '</select></td>'
        . '<td class="num' . ($r['todo'] >= $r['n'] ? ' cur-none' : '') . '" data-v="' . $pct . '"' . ($r['todo'] >= $r['n'] ? ' title="' . t('None of its detections reviewed yet') . '"' : '') . '><span class="bar"><span style="width:' . min(100, max($rv ? 3 : 0, $pct)) . '%"></span></span>' . $rv . ' (' . ($pct >= 10 ? round($pct) : fmt_num($pct, 1)) . '%)</td>'
        . '<td class="num">' . $r['a_yes'] . '</td><td class="num">' . $r['a_chg'] . '</td><td class="num">' . $r['a_unk'] . '</td><td class="num">' . $r['a_ign'] . '</td>'
        . '<td><button type="button" class="currm" onclick="curRemoveAll(this)" title="' . t('Deletes every detection of the species (to the Removed folder; restorable in Data › Purge)') . '">&#128683; ' . t('Delete') . '</button></td>'
        . '<td><button type="button" class="openbtn" onclick="curationReview(this)" title="' . t('Review its unreviewed detections; at the end the player goes on to the next species of this list') . '">' . t('Review') . '</button></td></tr>';
    }
  // low probability first; then Changed, Review later, Ignored, then the excluded ones (Others)
  // (owner 2026-10-19)
  // seven groups (owner 2026-10-22): Low Prob., Not reviewed, Confirmed, Changed, Unknown, Others (the rest: the
  // excluded species), Ignored; a species answered only "change species" / "unknown bird" goes by its answers: Changed when one
  // answer has a real cause or another species, else Unknown when one "unknown bird", else Ignored
  $groups = array('curlow' => array(), 'curtodo' => array(), 'currest' => array(), 'curno' => array(), 'curunk' => array(), 'curexcl' => array(), 'curign' => array());  // Ignored last (owner 2026-10-24)
  foreach ($rows as $sci => $r) {
    $ign = intval($r['ign'] ?? 0);
    $chg = intval($r['chg'] ?? 0);
    if (isset($excluded[$sci])) $g = 'curexcl';
    elseif (!$r['work']) $g = ($r['prob'] ?? -1) >= $low_default ? 'curtodo' : 'curlow'; // low = below the value (owner 2026-10-20)
    // by the answers (owner 2026-10-22), Yes > Change species > Unknown bird > a cause: Changed when its only Yes came
    // from Change species, Unknown when an Unknown bird (or an older "another bird" / no cause) is left, else Ignored
    elseif (!$r['conf'] && ($r['yes'] - $chg) == 0 && ($r['yes'] + $r['no'] + $r['unsure']) > 0) $g = $chg > 0 ? 'curno' : (($r['unsure'] + $r['no'] - $ign) > 0 ? 'curunk' : 'curign');
    else $g = 'currest';
    $groups[$g][] = $sci;
  }
  $titles = array('curtodo' => array(t('Not reviewed'), t('Not confirmed, no answer yet, detections still without an answer')),
    'curlow' => array(t('Low Prob.'), t('Still to review, and its probability of occurrence at the location this week is below the minimum')),
    'currest' => array(t('Yes'), t('Confirmed, answered Yes once, or nothing left without an answer')),
    'curno' => array(t('Changed'), t('Not confirmed, its detections came from Change species')),
    'curunk' => array(t('Review later'), t('Not confirmed and answered Review later')),
    'curign' => array(t('Ignored'), t('Not confirmed and only answered Dog, Human, Engine, Noise or Ignore (audio purged in Tools › Data › Purge)')),
    'curexcl' => array(t('Others'), t('In the exclude list: the model records no new detections, so these are older ones: from before the species was excluded, imported (a BirdSuite backup or BirdNET-Pi folder) or moved from another location or station. Delete all to remove them, or keep them as history')));
  // each table's default order (owner 2026-10-19), the same keys as data-order for species-sort.js ("Default" button):
  // attribute names, "-" = descending; ties by name
  $orders = array('curlow' => 'prob', 'curtodo' => 'prob,maxc', 'currest' => 'wl,conf,yes', 'curno' => 'maxc', 'curunk' => 'maxc', 'curign' => 'maxc', 'curexcl' => 'maxc');
  $key = array('prob' => function ($r) { return $r['prob'] ?? -1; }, 'maxc' => function ($r) { return floatval($r['maxc']); },
    'conf' => function ($r) { return $r['conf']; }, 'wl' => function ($r) { return $r['wl']; }, 'yes' => function ($r) { return $r['yes']; });
  foreach ($groups as $id => $list) {
    usort($list, function ($a, $b) use ($rows, $orders, $key, $id) {
      foreach (explode(',', $orders[$id]) as $k) { $c = $key[$k]($rows[$a]) <=> $key[$k]($rows[$b]); if ($c) return $c; }
      return strcmp($rows[$a]['com'], $rows[$b]['com']);
    });
    $rank = array_search($id, array_keys($groups));
    $groups[$id] = array_map(function ($sci) use ($rowhtml, $id, $rank, $orders, $titles) {
      $tr = str_replace('<tr data-work=', '<tr class="g-' . $id . '" data-grp="' . $rank . '" data-order="' . $orders[$id] . '" data-work=', $rowhtml[$sci]);
      return str_replace('<td><button type="button" class="currm"', '<td class="curgrp" data-v="' . $rank . '">' . htmlspecialchars($titles[$id][0], ENT_QUOTES) . '</td><td><button type="button" class="currm"', $tr);
    }, $list);
  }
  ?>
  <?php // the filters in two lines (owner 2026-10-22): above, the date (Today by default) with ◀ / ▶ (the day before /
  // after, or the range moved by its length), the date range and the species filter, with Filter / Sort on the right;
  // below, Low Prob. and the group chips. The Filter icon shows / hides both lines (open by default here) ?>
  <div class="curlegend sortbar" data-fdefault="1">
    <?php $ptoday = date('Y-m-d'); $pfirst = (string)$db->querySingle('SELECT MIN(Date) FROM detections') ?: $ptoday;
 ?>
    <div class="curline">
      <?php echo period_picker('views.php?view=Curation', $pfrom, $pto, $pfirst, 'cur_period', array(), $psince); ?>
      <input type="search" class="hmfilter spfilter curright" data-table="curtable" placeholder="<?php echo t('Filter species...'); ?>" title="<?php echo t('Common, scientific or English name (accents ignored)'); ?>">
    <span class="curtools"><span class="dashfilter" role="button" tabindex="0" title="<?php echo t('Filter and search: show / hide'); ?>"
      onclick="this.closest('.curlegend').classList.toggle('fopen')" onkeydown="if (event.key === 'Enter' || event.key === ' ') { event.preventDefault(); this.click(); }"><svg viewBox="0 0 24 24" width="18" height="18" aria-label="<?php echo t('Filter'); ?>" role="img"><path fill="currentColor" d="M3 4h18l-7 8.5V19l-4 2v-8.5z"/></svg></span><?php echo sort_toggle(); ?><span class="hmsort" data-table="curtable" data-store="curation-v7" data-default="cur" data-remember="0"><small><?php echo t('Sort:'); ?></small><button type="button" data-sort="cur" title="<?php echo t('By group, each in its own order: Low Prob. and Not reviewed by probability of occurrence at the location (Not reviewed then by confidence); Yes the unconfirmed first, fewest Yes first, the whitelisted last; Changed, Review later, Ignored and Others by confidence (lowest first)'); ?>"><?php echo t('Default'); ?></button><button type="button" data-sort="prob" title="<?php echo t('Probability of occurrence at the location this week, least expected first'); ?>"><?php echo t('Probability'); ?></button><button type="button" data-sort="n"><?php echo t('Count'); ?></button><button type="button" data-sort="tax" title="<?php echo t('Field-guide order (eBird/Clements taxonomy)'); ?>"><?php echo t('Taxonomy'); ?></button><button type="button" data-sort="az" title="<?php echo t('Alphabetical, by the names shown'); ?>">A-Z</button></span></span>
    </div>
    <div class="curline curline2">
    <label class="curthr" title="<?php echo t('Probability of occurrence at the location this week below which a species still to review counts as low probability'); ?>"><?php echo t('Low Prob.') . ':'; ?> <select id="curlowthr" class="nowbelow" onchange="curRegroup(true)"><?php
      // the same picklist as the Now filters (owner 2026-10-20): < 5 % … < 95 %
      for ($v = 5; $v <= 95; $v += 5) echo '<option value="' . $v . '"' . ($v === intval(round($low_default * 100)) ? ' selected' : '') . '>&lt; ' . $v . '%</option>'; ?></select></label>
    <?php foreach ($groups as $id => $trs) { ?><span class="curchip g-<?php echo $id; ?>" data-group="<?php echo $id; ?>" onclick="curHide(this)" title="<?php echo $h(t('{what}, click to hide / show', array('what' => $titles[$id][1]))); ?>"><?php echo $h($titles[$id][0]); ?> <span class="curcount">(<?php echo count($trs); ?>)</span></span><?php } ?>
    </div>
  </div>
  <script>
  // the title line (Sort, Filter) and the legend line as wide as the table, so they end at its right edge; a table
  // wider than the page scrolls inside its box and the lines end at the box's edge (owner 2026-10-20)
  (function () {
    function fit() {
      var t = document.getElementById('curtable'), box = document.getElementById('curbox');
      if (!t || !box) return;
      var w = Math.min(t.offsetWidth, box.clientWidth) + 'px';
      document.querySelectorAll('.cur .curlegend').forEach(function (el) { el.style.boxSizing = 'border-box'; el.style.width = w; });
    }
    document.addEventListener('DOMContentLoaded', function () {
      fit();
      if (window.ResizeObserver && document.getElementById('curtable')) new ResizeObserver(fit).observe(document.getElementById('curtable'));
    });
    window.addEventListener('resize', fit);
    window.addEventListener('load', fit);
  })();
  </script>
  <div id="curbox" style="overflow-x:auto;max-width:100%">
  <table id="curtable" class="stdtable curtable" data-nosort>
    <?php // the species card first on the left, headed by its icon (owner 2026-10-26); the name opens the review ?>
    <tr><th data-nosort style="width:1%;text-align:center"><?php echo species_card_icon(); ?></th><th><select class="namemode" title="<?php echo t('Names shown'); ?>"><option value="com"><?php echo t('Common name'); ?></option><option value="sci"><?php echo t('Scientific name'); ?></option><option value="en"><?php echo t('English name'); ?></option></select></th><th class="num"><?php echo t('Detections'); ?></th><th class="num" title="<?php echo t('Highest confidence of its detections'); ?>"><?php echo t('Confidence'); ?></th><th class="num" title="<?php echo t('Confidence set by hand for the species (species page or Calibrate thresholds); - = none, the station\'s applies'); ?>"><?php echo t('Manual confidence'); ?></th><th class="num" title="<?php echo t('Probability of occurrence of the species at the location this week (as in Species Cards); green = above the minimum probability of occurrence at the location'); ?>"><?php echo t('Probability'); ?></th><th><?php echo t('Confirmed'); ?></th><th><?php echo t('Whitelist'); ?></th><th title="<?php echo t('Ticked = in the exclude list: no longer detected'); ?>"><?php echo t('Excluded sp.'); ?></th><th title="<?php echo t('Notification of the species: Normal, Muted (never notified) or Rare (Rare channel); the same setting as in Species Cards'); ?>"><?php echo t('Notification'); ?></th><th class="num" title="<?php echo t('Detections of this species answered'); ?>"><?php echo t('Reviewed'); ?></th><th class="num"><?php echo t('Yes'); ?></th><th class="num"><?php echo t('Changed'); ?></th>
      <th class="num"><?php echo t('Review later'); ?></th><th class="num"><?php echo t('Ignored'); ?></th><th title="<?php echo t('The group the species belongs to (the legend above)'); ?>"><?php echo t('Group'); ?></th><th data-nosort></th><th data-nosort></th></tr>
    <?php foreach ($groups as $trs) echo implode("\n", $trs) . "\n"; ?>
  </table>
  </div>
  <?php // folded to its title line on every visit; a click on the title opens / folds it (owner 2026-10-22) ?>
  <details class="currecentbox"><summary><h3 style="display:inline"><?php echo t('Last reviews'); ?></h3></summary>
  <div id="currecent"><?php echo $has ? recent_reviews_html($db, 10, 0) : '<p>' . t('No reviews yet.') . '<br>' . t('Use the Review button on any detection.') . '</p>'; ?></div></details>
  <script>
  // newest review first on every visit (owner 2026-10-20): a column sort of the reviews lasts until the next reload
  try { Object.keys(localStorage).forEach(function (k) { if (/^stdsort:.*:currecent$/.test(k)) localStorage.removeItem(k); }); } catch (e) {}
  (function () {
    // collapsed on every visit (owner 2026-10-22); a click opens it until the page is left
    var d = document.querySelector('details.currecentbox');
    d.open = false;
    try { localStorage.removeItem('cur_recent_open'); } catch (e) {}
  })();
  // Last N reviews, paged like Now (owner 2026-10-20): 10 by default, N remembered per browser
  var curRecentN = 10, curRecentOff = 0;
  function curRecent(offset) {
    curRecentOff = offset;
    var box = document.getElementById('currecent'), x = new XMLHttpRequest();
    box.style.opacity = '.5';
    x.onload = function () {
      box.innerHTML = this.responseText; box.style.opacity = '';
      if (window.stdTables) stdTables(box);
      if (window.applyNameMode) applyNameMode();
    };
    x.open('GET', 'scripts/curation.php?recent=1&limit=' + curRecentN + '&offset=' + offset, true);
    x.send();
  }
  function curRecentCount(v) { curRecentN = parseInt(v, 10) || 10; try { localStorage.setItem('cur_recent_n', String(curRecentN)); } catch (e) {} curRecent(0); }
  (function () {
    var v = null; try { v = parseInt(localStorage.getItem('cur_recent_n') || '', 10); } catch (e) {}
    if ([25, 50, 100].indexOf(v) >= 0) { curRecentN = v; curRecent(0); }
  })();
  </script>
</div>
<script src="static/species-sort.js?v=<?php echo @filemtime(__DIR__ . "/../homepage/static/species-sort.js"); ?>"></script>
<script src="static/std-table.js?v=<?php echo @filemtime(__DIR__ . "/../homepage/static/std-table.js"); ?>"></script>
<script src="static/name-mode.js?v=<?php echo @filemtime(__DIR__ . "/../homepage/static/name-mode.js"); ?>"></script>
<script src="static/species-modal.js?v=<?php echo @filemtime(__DIR__ . "/../homepage/static/species-modal.js"); ?>"></script>
<script src="static/precision.js?v=<?php echo @filemtime(__DIR__ . "/../homepage/static/precision.js"); ?>"></script>
<script>
// Calibrate thresholds (owner 2026-10-28): the reviews of every species (curation.php?calib=1) through the same method
// as the species page's Precision (static/precision.js); listed = a suggestion that differs from the threshold in force,
// raised when the precision there is below the target, lowered when the target is already reached below it
var curCalData = null;
function curCalibrate() {
  var box = document.getElementById('curcal');
  box.hidden = false;
  document.getElementById('curcalmsg').textContent = '';
  document.getElementById('curcalout').innerHTML = '<p>' + t('Loading...') + '</p>';
  var x = new XMLHttpRequest();
  x.onload = function () {
    try { curCalData = JSON.parse(this.responseText); } catch (e) { document.getElementById('curcalout').innerHTML = '<p>' + t('Not loaded') + '</p>'; return; }
    curCalDraw();
  };
  x.open('GET', 'scripts/curation.php?calib=1', true);
  x.send();
}
function curCalClose() { document.getElementById('curcal').hidden = true; }
document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && !document.getElementById('curcal').hidden) curCalClose(); });
function curCalPct(v) { return v === null || v === undefined ? '-' : window.fmtNum(Math.round(v * 100)) + '%'; }
function curCalAt(s, th, target) {
  // the precision line of one threshold: the table's steps plus that value
  var P = window.bsPrecision, steps = P.confSteps().filter(function (v) { return Math.abs(v - th) > 1e-9; }).concat([th]).sort(function (a, b) { return a - b; });
  var c = P.curve(s.rows, 0, steps, P.countConf(s.nconf), target);
  return c.lines.filter(function (l) { return Math.abs(l.th - th) < 1e-9; })[0];
}
function curCalDraw() {
  if (!curCalData) return;
  var P = window.bsPrecision, target = parseFloat(document.getElementById('curcaltarget').value), list = [], waiting = 0;
  curCalData.species.forEach(function (s) {
    var c = P.curve(s.rows, 0, P.confSteps(), P.countConf(s.nconf), target);
    var now = s.own !== null ? s.own : curCalData.global;
    if (c.best === null) { waiting++; return; }
    var atBest = c.lines.filter(function (l) { return l.th === c.best; })[0], atNow = curCalAt(s, now, target), why, fine = Math.abs(c.best - now) < 0.005;
    if (fine) why = t('Already at the threshold: the one in force is the suggested value.');
    else if (c.best > now) why = t('Raise: at the threshold in force the precision is {p}, below the target.', {p: curCalPct(atNow ? atNow.prec : null)});
    else why = t('Lower: the target is already reached from {v}, which keeps about {n} more real detections.', {v: window.fmtNum(c.best, 2), n: window.fmtNum(Math.round(Math.max(0, (atNow ? atNow.lost : 0) - atBest.lost)))});
    why += '<br>' + t('{n} reviews above it, guaranteed {g}.', {n: atBest.y + atBest.f, g: curCalPct(atBest.lb)});
    if (c.lb < target) why += '<br>' + t('Guaranteed below the target: review more to confirm.');
    list.push({s: s, now: now, best: c.best, prec: atBest.prec, why: why, fine: fine});
  });
  // the new suggestions first, then those already at the threshold; by name inside each
  list.sort(function (a, b) { return (a.fine - b.fine) || a.s.com.localeCompare(b.s.com); });
  var out = document.getElementById('curcalout'), h = function (v) { return String(v).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/"/g, '&quot;'); };
  var html = '', nNew = list.filter(function (r) { return !r.fine; }).length;
  if (list.length) {
    html += '<table class="stdtable" id="curcaltable" data-nosort><tr><th data-nosort><input type="checkbox" id="curcalallcb" onclick="curCalAll(this)" title="' + h(t('Tick / untick all')) + '"></th>'
      + '<th><select class="namemode" title="' + h(t('Names shown')) + '"><option value="com">' + t('Common name') + '</option><option value="sci">' + t('Scientific name') + '</option><option value="en">' + t('English name') + '</option></select></th>'
      + '<th class="num">' + t('Right') + '</th><th class="num">' + t('False') + '</th><th class="num">' + t('In force') + '</th><th class="num">' + t('Suggested') + '</th><th class="num">' + t('Precision') + '</th><th>' + t('Why') + '</th></tr>';
    list.forEach(function (r) {
      var y = r.s.rows.filter(function (x) { return x[2]; }).length, f = r.s.rows.length - y;
      html += '<tr data-sci="' + h(r.s.sci) + '"' + (r.fine ? ' class="curcalfine"' : '') + '><td><input type="checkbox" class="curcalon"' + (r.fine ? '' : ' checked') + ' onchange="curCalCount()"></td>'
        + '<td><a href="views.php?view=Bird&amp;sci=' + encodeURIComponent(r.s.sci) + '#precision" data-com="' + h(r.s.com) + '" data-sci="' + h(r.s.sci) + '" data-en="' + h(r.s.en || r.s.com) + '">' + h(r.s.com) + '</a></td>'
        + '<td class="num">' + y + '</td><td class="num">' + f + '</td>'
        + '<td class="num">' + window.fmtNum(r.now, 2) + (r.s.own === null ? ' <small>' + t('(station)') + '</small>' : '') + '</td>'
        + '<td class="num"><input type="number" class="curcalv" min="0.01" max="0.99" step="0.01" value="' + r.best.toFixed(2) + '" data-best="' + r.best.toFixed(2) + '" oninput="curCalEdit(this)"></td>'
        + '<td class="num curcalp">' + curCalPct(r.prec) + '</td><td class="curcalwhy">' + r.why + '</td></tr>';
    });
    html += '</table>';
  } else html += '<p>' + t('No species reaches the criteria yet: review more detections on the species pages.') + '</p>';
  html += '<p><small>' + t('{a} new suggestions, {b} species already at the threshold, {c} species whose reviews do not reach the criteria yet.', {a: nNew, b: list.length - nNew, c: waiting}) + '</small></p>';
  out.innerHTML = html;
  if (window.applyNameMode) applyNameMode();
  curCalCount();
}
function curCalAll(cb) { document.querySelectorAll('#curcaltable input.curcalon:not(:disabled)').forEach(function (c) { c.checked = cb.checked; }); curCalCount(); }
function curCalCount() {
  var b = document.getElementById('curcalapply');
  if (!b) return;
  var n = document.querySelectorAll('#curcaltable input.curcalon:checked:not(:disabled)').length;
  b.textContent = t('Apply the ticked ones ({n})', {n: n});
  b.disabled = !n;
  var all = document.getElementById('curcalallcb'), open = document.querySelectorAll('#curcaltable input.curcalon:not(:disabled)').length;
  if (all) { all.checked = open > 0 && n === open; all.indeterminate = n > 0 && n < open; }
}
// a value changed by hand: its precision from the same reviews, the edited value marked
function curCalEdit(inp) {
  var tr = inp.closest('tr'), sci = tr.dataset.sci, v = parseFloat(String(inp.value).replace(',', '.')), s = curCalData.species.filter(function (x) { return x.sci === sci; })[0];
  var ok = s && v >= 0.01 && v <= 0.99;
  inp.style.outline = ok ? (Math.abs(v - parseFloat(inp.dataset.best)) > 1e-9 ? '2px solid #f9a825' : '') : '2px solid #c62828';
  tr.querySelector('.curcalp').textContent = ok ? curCalPct((curCalAt(s, Math.round(v * 100) / 100, parseFloat(document.getElementById('curcaltarget').value)) || {}).prec) : '-';
  var cb = tr.querySelector('input.curcalon');
  if (ok && !cb.disabled && Math.abs(v - parseFloat(inp.dataset.best)) > 1e-9) cb.checked = true;
  curCalCount();
}
function curCalApply(btn) {
  var set = {}, bad = false, trs = [];
  document.querySelectorAll('#curcaltable tr[data-sci]').forEach(function (tr) {
    var on = tr.querySelector('input.curcalon');
    if (!on.checked || on.disabled) return;
    var v = parseFloat(String(tr.querySelector('input.curcalv').value).replace(',', '.'));
    if (!(v >= 0.01 && v <= 0.99)) { bad = true; return; }
    set[tr.dataset.sci] = Math.round(v * 100) / 100; trs.push(tr);
  });
  if (bad) { alert(t('Invalid value (0.01 - 0.99, empty = global)')); return; }
  if (!trs.length) return;
  btn.disabled = true;
  var x = new XMLHttpRequest();
  x.onload = function () {
    if (this.status === 200 && this.responseText.trim() === 'OK') {
      trs.forEach(function (tr) {
        var v = set[tr.dataset.sci], s = curCalData.species.filter(function (x) { return x.sci === tr.dataset.sci; })[0];
        if (s) s.own = v;
        tr.classList.add('curcaldone');
        tr.cells[4].textContent = window.fmtNum(v, 2);
        tr.querySelector('input.curcalon').disabled = true;
        var a = document.querySelector('#curtable a[data-sci="' + tr.dataset.sci.replace(/"/g, '\\"') + '"]'), c = a && a.closest('tr').querySelector('td.curmin');
        if (c) { c.textContent = window.fmtNum(v, 2); c.dataset.v = v; }
        tr.querySelector('input.curcalv').disabled = true;
      });
      document.getElementById('curcalmsg').textContent = t('Applied to {n} species.', {n: trs.length});
      curCalCount();
      // the modal closes and says what was saved (owner 2026-10-30: the greyed rows alone looked like nothing happened)
      curCalClose();
      askModal(t('Calibrate thresholds'), t('Applied to {n} species.', {n: trs.length}) + '\n' + t('The analysis uses the new thresholds from the next recording on.'), t('OK'), false, '');
    } else { btn.disabled = false; alert(t('Not saved: {reason}', {reason: this.status === 401 ? t('log in first') : this.responseText})); }
  };
  x.onerror = function () { btn.disabled = false; alert(t('Not changed (network error)')); };
  x.open('POST', 'scripts/species_tools.php?setconfs=1', true);
  x.setRequestHeader('Content-Type', 'application/json');
  x.send(JSON.stringify(set));
}
// the species of this table, in the order shown (sorted and filtered), from the given row on: the review player goes
// on to the next one when a species is finished (review-player.js reviewQueueNext, owner 2026-10-18)
function curationQueue(tr) {
  var rows = Array.prototype.slice.call(document.querySelectorAll('.curtable tr[data-n]')).filter(function (r) { return r.style.display !== 'none' && getComputedStyle(r).display !== 'none'; });
  var list = rows.map(function (r) { var a = r.querySelector('a[data-sci]'); return {sci: a.dataset.sci, com: a.dataset.com, conf: r.dataset.conf === '1', todo: parseInt(r.dataset.todo, 10) || 0}; });
  try { sessionStorage.setItem('rp_queue', JSON.stringify(list)); } catch (e) {}
}
// Confirmed / Whitelist / Exclude and the notification tier, the same lists and questions as Species Pages; the row is
// updated in place (no reload: the place in the list is kept)
function curGet(url, done) {
  var x = new XMLHttpRequest();
  x.onload = function () { if (this.responseText.trim() === 'OK') done(); else alert(this.status === 401 ? t('Log in first.') : this.responseText); };
  x.open('GET', url, true);
  x.send();
}
// the notification tier of a species (owner 2026-10-29), saved as in Species Cards: a green outline when saved, red with the
// reason in the tooltip and the old value back when refused (not logged in)
function curTier(sci, sel) {
  var x = new XMLHttpRequest();
  x.onload = function () {
    var r = this.responseText.trim(), ok = this.status === 200 && r === 'OK';
    if (ok) sel.dataset.was = sel.value; else if (sel.dataset.was) sel.value = sel.dataset.was;
    sel.parentNode.dataset.v = {muted: 0, normal: 1, rare: 2}[sel.value];
    sel.style.outline = ok ? '2px solid green' : '2px solid red';
    sel.title = ok ? '' : (this.status === 401 ? t('Log in first.') : r);
    setTimeout(function () { sel.style.outline = ''; }, ok ? 1500 : 4000);
  };
  x.open('GET', 'scripts/species_tools.php?settier=1&species=' + encodeURIComponent(sci) + '&tier=' + encodeURIComponent(sel.value), true);
  x.send();
}
function curToggle(el) {
  var list = el.dataset.list, id = el.dataset.id, action = el.classList.contains('on') ? 'del' : 'add';
  var parts = id.split('_'), tr = el.closest('tr'), a = tr.querySelector('a[data-com]');
  var name = (a ? a.dataset.com : parts.slice(1).join('_')) + ' (' + parts[0] + ')';
  var tx = (SPECIES_TOGGLE_TEXT[list] || {})[action];
  var go = function () {
    curGet('scripts/species_tools.php?toggle=' + list + '&species=' + encodeURIComponent(id) + '&action=' + action, function () {
      var on = action === 'add';
      el.classList.toggle('on', on);
      el.title = on ? t('Ticked: click to untick') : t('Click to tick');
      el.parentNode.dataset.v = on ? 0 : 1;
      if (list === 'confirmed') tr.dataset.conf = on ? 1 : 0;
      if (list === 'exclude') tr.dataset.excl = on ? 1 : 0;
      if (list === 'whitelist') tr.dataset.wl = on ? 1 : 0;
    });
  };
  if (!tx) { go(); return; }
  askModal(tx[0].replace('NAME', name), tx[1], t('Yes'), list === 'whitelist' || list === 'exclude').then(function (ok) { if (ok) go(); });
}
function curTier(sel) {
  curGet('scripts/species_tools.php?settier=1&species=' + encodeURIComponent(sel.dataset.sci) + '&tier=' + encodeURIComponent(sel.value), function () {
    sel.parentNode.dataset.v = sel.value;
    sel.style.outline = '2px solid green';
    setTimeout(function () { sel.style.outline = ''; }, 1200);
  });
}
// Remove all (owner 2026-10-19): every detection of the species to the Removed folder, after a confirmation (No is the
// default); the row leaves the table
function curRemoveAll(btn) {
  var tr = btn.closest('tr'), a = tr.querySelector('a[data-sci]'), n = parseInt(tr.dataset.n, 10);
  askModal(t('Delete all {n} detections of {name}?', {n: window.fmtNum(n), name: a.dataset.com + ' (' + a.dataset.sci + ')'}),
    t('Clips and spectrograms go to the Removed folder and leave every statistic; they can be restored in Tools › Data › Purge until purged there.'),
    t('Delete all'), true).then(function (ok) {
    if (!ok) return;
    btn.disabled = true;
    var x = new XMLHttpRequest();
    x.onload = function () {
      if (/^OK \d+/.test(this.responseText)) {
        tr.remove();
        document.querySelectorAll('.curchip').forEach(function (c) {
          c.querySelector('.curcount').textContent = '(' + document.querySelectorAll('#curtable tr.g-' + c.dataset.group).length + ')';
        });
      } else { btn.disabled = false; alert(this.status === 401 ? t('Log in first.') : this.responseText); }
    };
    x.open('GET', 'play.php?deletespecies=' + encodeURIComponent(a.dataset.sci), true);
    x.send();
  });
}
function curationStart() {
  var rows = Array.prototype.slice.call(document.querySelectorAll('.curtable tr[data-n]')).filter(function (r) { return r.style.display !== 'none' && getComputedStyle(r).display !== 'none'; });
  var first = rows.filter(function (r) { return parseInt(r.dataset.todo, 10) > 0; })[0];
  if (!first) { curationNothingLeft(); return; }
  curationReview(first.querySelector('button.openbtn'));
}
function curationReview(btn) {
  var tr = btn.closest('tr'), a = tr.querySelector('a[data-sci]');
  curationQueue(tr);
  location.href = 'views.php?view=Bird&sci=' + encodeURIComponent(a.dataset.sci) + '&review=1';
}
// the species name opens the same review mode as the Review button (a review started from Curation always goes on to
// the next species when one is finished, owner 2026-10-18)
document.querySelectorAll('.curtable a[data-sci]').forEach(function (a) { a.addEventListener('click', function () { curationQueue(a.closest('tr')); }); });
// nothing left to review in the list shown: a notice (owner 2026-10-30), also when a review comes back at the end of it
function curationNothingLeft() {
  askModal(t('Nothing left to review in this list'), t('Every species of this list has an answer.') + '\n' + t('Other groups of the table may still have detections to review.'), t('OK'), false, '');
}
// back from a review (review-player.js leave()): the page opens at the species just reviewed (owner 2026-10-20)
(function () {
  var sci = null, done = null;
  try { sci = sessionStorage.getItem('cur_return'); sessionStorage.removeItem('cur_return'); done = sessionStorage.getItem('cur_done'); sessionStorage.removeItem('cur_done'); } catch (e) {}
  if (done) curationNothingLeft();
  if (!sci) return;
  var a = Array.prototype.slice.call(document.querySelectorAll('.curtable a[data-sci]')).filter(function (x) { return x.dataset.sci === sci; })[0];
  if (!a) return;
  var tr = a.closest('tr');
  tr.scrollIntoView({block: 'center'});
  tr.style.outline = '2px solid #1f6feb';
  setTimeout(function () { tr.style.outline = ''; }, 2500);
})();
function undoReview(file, btn) {
  var x = new XMLHttpRequest();
  x.onload = function () {
    if (this.responseText == 'OK') { var tr = btn.closest('tr'); tr.style.opacity = .4; btn.textContent = t('Undone'); btn.disabled = true; }
    else alert(this.status === 401 ? t('Log in first.') : this.responseText);
  };
  x.open('GET', 'play.php?review=' + encodeURIComponent(file) + '&verdict=clear', true);
  x.send();
}
// sort a species table by a column (click the header), as the standard tables (2026-10-22: the table was called t, which
// hid t() — every click threw and nothing sorted); the titles look clickable as on the other tables
document.querySelectorAll('.curtable').forEach(function (tbl) {
  Array.prototype.forEach.call(tbl.rows[0].cells, function (th, i) {
    if (th.dataset.nosort !== undefined || !th.textContent.trim()) return;
    th.classList.add('sortable');
    var asc = false;
    th.onclick = function (e) {
      if (e && e.target.closest('select')) return;
      var rows = Array.prototype.slice.call(tbl.rows, 1);
      asc = !asc;
      // the Group column: groups 1 → 5 in their default order, clicked again 5 → 1 (each group keeps its own order)
      if (th.textContent.trim() === t('Group')) {
        var def = document.querySelector('.hmsort button[data-sort="cur"]');
        if (def) def.click();
        if (!asc) {
          rows = Array.prototype.slice.call(tbl.rows, 1);
          rows.sort(function (a, b) { return (parseFloat(b.dataset.grp) - parseFloat(a.dataset.grp)) || (rows.indexOf(a) - rows.indexOf(b)); });
          rows.forEach(function (r) { tbl.tBodies[0].appendChild(r); });
        }
        return;
      }
      rows.sort(function (a, b) {
        var x = a.cells[i].dataset.v || a.cells[i].textContent, y = b.cells[i].dataset.v || b.cells[i].textContent;
        var nx = parseFloat(String(x).replace(/[^0-9.\-]/g, '')), ny = parseFloat(String(y).replace(/[^0-9.\-]/g, ''));
        var c = (!isNaN(nx) && !isNaN(ny)) ? nx - ny : String(x).localeCompare(String(y));
        return asc ? c : -c;
      });
      rows.forEach(function (r) { tbl.tBodies[0].appendChild(r); });
    };
  });
});
// a legend entry hides / shows its group (remembered in this browser)
function curHide(chip, set) {
  var id = chip.dataset.group, on = set === undefined ? !chip.classList.contains('off') : set;
  chip.classList.toggle('off', on);
  document.getElementById('curtable').classList.toggle('hide-' + id, on);
  if (set === undefined) try { localStorage.setItem('curhide:' + id, on ? '1' : '0'); } catch (e) {}
}
document.querySelectorAll('.curchip').forEach(function (c) {
  var on = false;
  try { on = localStorage.getItem('curhide:' + c.dataset.group) === '1'; } catch (e) {}
  if (on) curHide(c, true);
});
// the species still to review are low probability or not reviewed yet by the threshold picked (remembered)
var CUR_ORDERS = <?php echo json_encode($orders); ?>;
var CUR_TITLES = <?php echo json_encode(array_map(function ($x) { return $x[0]; }, $titles)); ?>;
function curRegroup(save) {
  var sel = document.getElementById('curlowthr'), pct = parseFloat(String(sel.value).replace(',', '.').replace('%', ''));
  if (isNaN(pct) || pct < 0 || pct > 100) { sel.style.outline = '2px solid #c62828'; return; }
  sel.style.outline = '';
  sel.value = pct;
  var thr = pct / 100;
  if (save) try { localStorage.setItem('curlowpct', String(pct)); } catch (e) {}
  document.querySelectorAll('#curtable tr[data-work="1"]').forEach(function (r) {
    var g = parseFloat(r.dataset.prob) >= thr ? 'curtodo' : 'curlow';
    r.className = 'g-' + g;
    r.dataset.grp = g === 'curlow' ? 0 : 1;
    r.dataset.order = CUR_ORDERS[g];
    var gc = r.querySelector('td.curgrp');
    if (gc) { gc.textContent = CUR_TITLES[g]; gc.dataset.v = r.dataset.grp; }
  });
  document.querySelectorAll('.curchip').forEach(function (c) {
    c.querySelector('.curcount').textContent = '(' + document.querySelectorAll('#curtable tr.g-' + c.dataset.group).length + ')';
  });
  if (window.speciesSortRefresh) window.speciesSortRefresh();
}
// Low Prob. opens on its default (<?php echo round($low_default * 100); ?> %) every time (owner 2026-10-20); a change applies until the next visit



</script>
