<?php
################################################################################
# scripts/curation.php - CURATION
#
# PURPOSE:
#   Curation (owner 2026-10-09): how far the review of the station's detections has gone — per species the
#   share reviewed, the answers, how often the model was right (precision = yes / (yes + not this bird)), the
#   species threshold and the last review; the causes of the rejections; and the latest reviews with Undo.
#   Opened as views.php?view=Review.
#
# DEPENDENCIES:
#   - scripts/common.php
#   - homepage/static/precision.js
#   - homepage/static/species-modal.js
#   - homepage/static/species-sort.js
#   - homepage/static/std-table.js
#   - homepage/static/name-mode.js
#   - homepage/static/curation.js
#
# USAGE:
#   views.php?view=Review
#   Referenced by homepage/static/curation.js, homepage/static/review-player.js, homepage/views.php,
#   scripts/lib/locations.php, scripts/update_caddyfile.sh
################################################################################

require_once __DIR__ . '/common.php';
$db = get_db();
$h = function ($s) { return htmlspecialchars((string)$s, ENT_QUOTES); };
// Latest reviews, a page at a time like Now (owner 2026-10-20): Last 10 / 25 / 50 / 100, Newer / Older; one extra row
// says whether older ones exist. curation.php?recent=1&limit=N&offset=M answers the table alone (the page reloads it)
function recent_reviews_html($db, $limit, $offset) {
	$h = function ($s) { return htmlspecialchars((string)$s, ENT_QUOTES); };
	// the reviews of the location the pages show, the deleted detections included (2026-10-20)
	$where = '';
	if (count(locations_load()) >= 2 && ($l = location_shown()) !== null) {
		// the review's detection, live or deleted, by its tag (owner 2026-10-21)
		$where = " LEFT JOIN deleted_detections x ON x.File_Name = r.File_Name WHERE (d.Location = " . intval($l['id']) . " OR x.Location = " . intval($l['id']) . ")";
	}
	$res = $db->query("SELECT r.File_Name, r.Sci_Name, r.Date, r.Confidence, r.Verdict, r.Reviewed_At, r.Reason, d.Time FROM detection_reviews r LEFT JOIN main.detections d ON d.File_Name = r.File_Name" . $where . " ORDER BY r.Reviewed_At DESC LIMIT " . ($limit + 1) . " OFFSET " . $offset);
	$recent = array();
	// the common name looked up for the rows shown (names are presentation only, owner 2026-11-05)
	while ($res && ($r = $res->fetchArray(SQLITE3_ASSOC))) $recent[] = $r + array('Com_Name' => common_name($r['Sci_Name']));
	$more = count($recent) > $limit;
	$recent = array_slice($recent, 0, $limit);
	if (!$recent) return '<p>' . t('str_no_reviews_yet') . '<br>' . t('str_use_the_review_button_on_any_help') . '</p>';
	$out = '<table class="stdtable"><tr><th>' . t('str_reviewed') . '</th><th><select class="namemode" title="' . t('str_names_shown') . '"><option value="com">' . t('str_common_name') . '</option><option value="sci">' . t('str_scientific_name') . '</option><option value="en">' . t('str_english_name') . '</option></select></th>'
		. '<th>' . t('str_detection') . '</th><th class="num">' . t('str_confidence') . '</th><th>' . t('str_answer') . '</th><th data-nosort></th></tr>';
	// cause codes stay English in the database; only their labels are translated
	$causes = array('human' => t('str_human'), 'noise' => t('str_noise'), 'ignore' => t('str_no_ignore'));
	foreach ($recent as $r) {
		// the answer as the Review button names it (owner 2026-10-22)
		$ans = $r['Verdict'] === 'yes' ? '✓ ' . (($r['Reason'] ?? '') === 'changed' ? t('str_changed') : t('str_yes_state'))
			: ($r['Verdict'] === 'no' ? (isset($causes[$r['Reason'] ?? '']) ? '✗ ' . $causes[$r['Reason']] : '⇄ ' . t('str_change_species')) : '? ' . t('str_review_later'));
		$out .= '<tr><td>' . $h($r['Reviewed_At']) . '</td><td><a href="views.php?view=Species&amp;sci=' . rawurlencode($r['Sci_Name']) . '" data-com="' . $h($r['Com_Name']) . '" data-sci="' . $h($r['Sci_Name']) . '" data-en="' . $h(get_english_name($r['Sci_Name'])) . '">' . $h($r['Com_Name']) . '</a></td>'
			. '<td>' . $h($r['Date'] . ' ' . ($r['Time'] ?? '')) . '</td><td class="num">' . round($r['Confidence'] * 100) . '%</td>'
			. '<td>' . $h($ans) . '</td>'
			// a Changed answer has no Undo (owner 2026-11-06, Gitea #69): it is the only record of the species the model gave and
			// its confidence, and clear keeps it as it is (play.php); a later answer on it is undone back to Changed
			. '<td>' . ($r['Verdict'] === 'yes' && ($r['Reason'] ?? '') === 'changed' ? ''
				: '<button type="button" class="openbtn" onclick="undoReview(' . $h(json_encode($r['File_Name'])) . ', this)" title="' . t('str_remove_this_answer') . '">' . t('str_undo') . '</button>') . '</td></tr>';
	}
	$out .= '</table>';
	$out .= list_pager(t('str_last_select_reviews', array('select' => '{select}')), $offset, count($recent), $limit, $more, 'curRecentCount', 'curRecent');
	return $out;
}
if (isset($_GET['recent'])) {
	$lim = in_array(intval($_GET['limit'] ?? 10), array(10, 25, 50, 100), true) ? intval($_GET['limit']) : 10;
	echo recent_reviews_html($db, $lim, max(0, intval($_GET['offset'] ?? 0)));
	die();
}
// Calibrate thresholds (owner 2026-10-28): every species with reviews, as the species page's Precision reads them —
// [confidence, null, right 1 / false 0] per review (Yes = right; Human, Noise, Ignore,
// and every detection changed to another species = false), its detections per 5 % band, and the threshold in force;
// static/precision.js computes the suggestions in the page
if (isset($_GET['calib'])) {
	header('Content-Type: application/json');
	session_write_close();
	$own = array();
	foreach (@file(BS_LISTS . '/species_confidence.txt', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: array() as $l) {
		$p = explode('=', trim($l), 2);
		if (count($p) === 2) $own[$p[0]] = floatval($p[1]);
	}
	$excl = array_flip(array_map(function ($l) { return explode('_', trim($l), 2)[0]; }, @file(BS_LISTS . '/exclude_species_list.txt', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: array()));
	$sp = array();
	// the common name looked up once per species (presentation only, owner 2026-11-05)
	$add = function ($sci, $conf, $ok) use (&$sp) {
		if (!isset($sp[$sci])) $sp[$sci] = array('sci' => $sci, 'com' => common_name($sci), 'rows' => array(), 'nconf' => array_fill(0, 21, 0));
		$sp[$sci]['rows'][] = array(floatval($conf), null, $ok);
	};
	$res = $db->query("SELECT Sci_Name, Confidence, Verdict, COALESCE(Reason, '') AS Reason, Orig_Sci FROM detection_reviews");
	while ($res && ($r = $res->fetchArray(SQLITE3_ASSOC))) {
		if ($r['Verdict'] === 'yes' && $r['Reason'] !== 'changed') $add($r['Sci_Name'], $r['Confidence'], 1);
		elseif ($r['Verdict'] === 'no' && in_array($r['Reason'], PURGE_CAUSES, true)) $add($r['Sci_Name'], $r['Confidence'], 0);
		if (!empty($r['Orig_Sci'])) $add($r['Orig_Sci'], $r['Confidence'], 0);
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
foreach (@file(BS_LISTS . '/species_confidence.txt', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: array() as $l) {
	$p = explode('=', trim($l), 2);
	if (count($p) === 2) $over[$p[0]] = floatval($p[1]);
}
// the species lists, as in Species Pages (owner 2026-10-18: curate the unconfirmed species first)
$list_sci = function ($f) { return array_flip(array_map(function ($l) { return explode('_', trim($l), 2)[0]; }, @file(BS_LISTS . '/' . $f, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: array())); };
$confirmed = $list_sci('confirmed_species_list.txt');
$whitelisted = $list_sci('whitelist_species_list.txt');
$excluded = $list_sci('exclude_species_list.txt');
$tiers = array();
foreach (@file(BS_LISTS . '/notification_tiers.txt', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: array() as $l) {
	$p = explode('=', trim($l), 2);
	if (count($p) === 2) $tiers[$p[0]] = strtolower($p[1]);
}
$default_tier = strtolower(get_config()['NOTIFICATION_DEFAULT_TIER'] ?? 'normal');
// the period (owner 2026-10-22, in the filter line as By Hour; Last 24 h instead of Today, owner 2026-10-23): the last 7 days until another one is picked (common.php validation_period); the counts,
// the answers and the work left are those of the detections of the period
list($pfrom, $pto, $psince) = validation_period(true) + array(2 => '');
if ($pfrom !== '' && $pto < $pfrom) { $tmp = $pfrom; $pfrom = $pto; $pto = $tmp; }
// the moment the table is drawn: with the period and the location it travels to the review of a species, which lists
// exactly the rows counted here (common.php validation_source, owner 2026-11-02)
$cur_src = validation_source_params($pfrom, $pto, $psince, date('Y-m-d H:i:s'));
$pq = function ($sql) use ($db, $pfrom, $pto, $psince) {
	$st = $db->prepare($sql);
	if ($pfrom !== '') { $st->bindValue(':pf', $pfrom); $st->bindValue(':pt', $pto); }
	if ($psince !== '' && strpos($sql, ':ps') !== false) $st->bindValue(':ps', $psince);
	return $st->execute();
};
// Last 24 h (owner 2026-10-23): the two dates plus the moment, so the index on Date still narrows the rows
$pd = $pfrom !== '' ? ' AND Date BETWEEN :pf AND :pt' . ($psince !== '' ? " AND Date || ' ' || Time >= :ps" : '') : '';
$rows = array();
// the common name looked up per species after the grouping (presentation only, owner 2026-11-05)
$res = $pq('SELECT Sci_Name, COUNT(*) AS n, MAX(Confidence) AS maxc FROM detections WHERE 1' . $pd . ' GROUP BY Sci_Name');
while ($res && ($r = $res->fetchArray(SQLITE3_ASSOC))) $rows[$r['Sci_Name']] = array('Sci_Name' => $r['Sci_Name'], 'com' => common_name($r['Sci_Name'])) + $r + array('yes' => 0, 'no' => 0, 'unsure' => 0, 'last' => '');
$recent = array();
// detections still in the table without any answer (the answers of deleted detections do not count)
// = the detections of the period minus the answered ones, found through the answers (2026-10-23 test run: NOT EXISTS on
// every detection cost 1.2 s at ten times the station's data)
foreach ($rows as $s => $r) $rows[$s]['todo'] = intval($r['n']);
$res = $pq("SELECT Sci_Name, COUNT(*) AS c FROM detections WHERE File_Name IN (SELECT File_Name FROM detection_reviews)" . $pd . " GROUP BY Sci_Name");
while ($res && ($r = $res->fetchArray(SQLITE3_ASSOC))) if (isset($rows[$r['Sci_Name']])) $rows[$r['Sci_Name']]['todo'] = max(0, $rows[$r['Sci_Name']]['todo'] - intval($r['c']));
// the answers of the period: those of its detections (every answer, also of deleted detections, when no period),
// per species and answer, with the Ignored and Changed ones counted in the same pass (owner 2026-10-29: two reads of
// the answers were two thirds of the page on a station with 177,000 detections)
// per species (owner 2026-10-22): 'ign' = answers with a cause (Human, Noise, Ignore), 'chg' = the Yes written by Change species (reason 'changed')
$cnt = ", SUM(CASE WHEN r.Verdict = 'no' AND r.Reason IN ('human','noise','ignore') THEN 1 ELSE 0 END) AS ign,
    SUM(CASE WHEN r.Verdict = 'yes' AND r.Reason = 'changed' THEN 1 ELSE 0 END) AS chg";
$res = $pfrom === '' ? $db->query("SELECT r.Sci_Name, r.Verdict, COUNT(*) AS c, MAX(r.Reviewed_At) AS last$cnt FROM detection_reviews r GROUP BY r.Sci_Name, r.Verdict")
	// the period's files once, then each answer by its file: a join walked the period's detections once per answer when a
	// location is shown (detections is then a view and its File_Name index is not used; 2026-10-29: 5 s per query)
	: $pq("SELECT r.Sci_Name, r.Verdict, COUNT(*) AS c, MAX(r.Reviewed_At) AS last$cnt FROM detection_reviews r WHERE r.File_Name IN (SELECT File_Name FROM detections WHERE 1" . $pd . ") GROUP BY r.Sci_Name, r.Verdict");
foreach ($rows as $s => $r) { $rows[$s]['ign'] = 0; $rows[$s]['chg'] = 0; }
while ($res && ($r = $res->fetchArray(SQLITE3_ASSOC))) {
	if (!isset($rows[$r['Sci_Name']])) continue;
	$rows[$r['Sci_Name']][$r['Verdict']] = intval($r['c']);
	if ($r['last'] > $rows[$r['Sci_Name']]['last']) $rows[$r['Sci_Name']]['last'] = $r['last'];
	$rows[$r['Sci_Name']]['ign'] += intval($r['ign']); $rows[$r['Sci_Name']]['chg'] += intval($r['chg']);
}
$total = array_sum(array_column($rows, 'n'));
// the answers as the Review button names them (owner 2026-10-22): Yes, Changed (Change species), Review later (with the older
// "not this species" without a cause), Ignored (Human, Noise, Ignore)
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
	if (!isset($r['todo'])) $rows[$sci]['todo'] = 0;
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
				. '<small class="kf">' . t('str_of_n', array('n' => fmt_num($of))) . ' - ' . $pc($value, $of) . '</small>'
				. ($extra !== '' ? '<small class="kf">' . $extra . '</small>' : '') . '</div>';
		};
		echo $card(t('str_reviewed'), $rev, t('str_det'), $total)
			. $card(t('str_sp_confirmed'), $n_conf, t('str_sp'), $n_sp)
			. $card(t('str_sp_validated'), $n_valid, t('str_sp'), $n_sp, t('str_1_review_yes'))
			. $card(t('str_yes_state'), $yes, t('str_det'), $rev)
			. $card(t('str_changed'), $chg, t('str_det'), $rev)
			. $card(t('str_review_later'), $uns, t('str_det'), $rev)
			. $card(t('str_ignored'), $ign, t('str_det'), $rev); ?>
	</div>
	<?php // Start (owner 2026-10-18): the review of the detections without an answer, species by species in the order of the
	// table below (sorted and filtered as shown), from the first species that still has one ?>
	<div style="margin:0 0 12px"><button type="button" id="curstart" class="openbtn" onclick="curationStart()" title="<?php echo t('str_reviews_the_detections_without_help'); ?>">&#9654; <?php echo t('str_start_review'); ?></button>
		<?php // Calibrate thresholds (owner 2026-10-28): the species thresholds of every species whose reviews reach the
		// criteria of the species page's Precision, in a table to tick, edit and apply at once ?>
		<button type="button" id="curcalbtn" class="openbtn" onclick="curCalibrate()" title="<?php echo t('str_suggests_the_threshold_of_every_help'); ?>">&#9878; <?php echo t('str_calibrate_thresholds'); ?></button></div>
	<div id="curcal" hidden onclick="if (event.target === this) curCalClose()">
		<div class="ccbox" role="dialog" aria-modal="true" aria-labelledby="curcaltitle">
			<div class="cchead"><h3 id="curcaltitle"><?php echo t('str_calibrate_thresholds'); ?></h3><button type="button" onclick="curCalClose()" title="<?php echo t('str_close'); ?>">&times;</button></div>
			<div class="ccbody">
				<p><?php echo t('str_the_species_whose_reviews_help'); ?><br>
					<?php echo t('str_suggested_the_lowest_confidence_help', array('n' => 20)); ?><br>
					<?php echo t('str_the_new_suggestions_come_ticked_help'); ?><br>
					<?php echo t('str_a_threshold_applies_to_new_help'); ?></p>
				<label><?php echo t('str_target'); ?> <select id="curcaltarget" onchange="curCalDraw()"><option value="0.8">80%</option><option value="0.9" selected>90%</option><option value="0.95">95%</option></select></label>
				<div id="curcalout"><p><?php echo t('str_loading'); ?></p></div>
			</div>
			<div class="ccfoot"><span id="curcalmsg"></span><button type="button" onclick="curCalClose()"><?php echo t('str_close'); ?></button><button type="button" class="primary" id="curcalapply" onclick="curCalApply(this)" disabled></button></div>
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
				return '<td data-v="' . ($on ? 0 : 1) . '"><span class="curflag' . ($on ? ' on' : '') . '" data-list="' . $list . '" data-id="' . $h($id) . '" onclick="curToggle(this)" title="' . ($on ? t('str_ticked_click_to_untick') : t('str_click_to_tick')) . '"></span></td>';
			};
			$tier = $tiers[$sci] ?? $default_tier;
			if (!in_array($tier, array('normal', 'muted', 'rare'), true)) $tier = 'normal';
			$rowhtml[$sci] = '<tr data-work="' . ($r['work'] ? 0 : 1) . '" data-excl="' . (isset($excluded[$sci]) ? 1 : 0) . '" data-maxc="' . floatval($r['maxc']) . '" data-yes="' . intval($r['yes']) . '" data-wl="' . (isset($whitelisted[$sci]) ? 1 : 0) . '" data-n="' . intval($r['n']) . '" data-conf="' . $r['conf'] . '" data-todo="' . intval($r['todo']) . '" data-prob="' . ($prob === null ? -1 : sprintf('%.4f', $prob)) . '" data-tax="' . taxon_order($sci) . '" data-q="' . $h(mb_strtolower($r['com'] . ' ' . $sci . ' ' . get_english_name($sci))) . '"><td style="text-align:center">' . species_card_icon($sci) . '</td><td>' . '<a href="views.php?view=Species&amp;sci=' . rawurlencode($sci) . '&amp;review=1' . $h($cur_src) . '" data-com="' . $h($r['com']) . '" data-sci="' . $h($sci) . '" data-en="' . $h(get_english_name($sci)) . '">' . $h($r['com']) . '</a>' . '</td>'
				. '<td class="num">' . fmt_num($r['n']) . '</td>'
				. '<td class="num" data-v="' . floatval($r['maxc']) . '">' . round($r['maxc'] * 100) . '%</td>'
				// the species' manual confidence beside the highest one (owner 2026-10-28): set by hand on the species page or by
				// Calibrate thresholds; '-' = none, the species follows the station's
				. (isset($over[$sci]) ? '<td class="num curmin" data-v="' . $over[$sci] . '">' . fmt_num($over[$sci], 2) . '</td>' : '<td class="num curmin" data-v="-1">-</td>')
				. ($prob === null ? '<td class="num" data-v="-1">-</td>'
					: '<td class="num ' . ($prob >= $sf_thresh ? 'pgood' : 'pbad') . '" data-v="' . sprintf('%.4f', $prob) . '">' . fmt_num($prob * 100, 1) . '%</td>')
				. $flag('confirmed', isset($confirmed[$sci]), $sci)
				. $flag('whitelist', isset($whitelisted[$sci]), $sci)
				. $flag('exclude', isset($excluded[$sci]), $sci)
				// the species' notification tier beside Excluded (owner 2026-10-29), the picker of Species Cards (species_tools.php settier)
				. '<td data-v="' . array('muted' => 0, 'normal' => 1, 'rare' => 2)[$tier] . '"><select class="curtier" data-was="' . $h($tier) . '" aria-label="' . $h(t('str_notification') . ' (' . $r['com'] . ')') . '" onchange="curTier(' . $h(json_encode($sci)) . ', this)">'
					. implode('', array_map(function ($v) use ($tier) { return '<option value="' . $v . '"' . ($v === $tier ? ' selected' : '') . '>' . t(array('normal' => 'str_normal_tier', 'muted' => 'str_muted_tier', 'rare' => 'str_rare_tier')[$v]) . '</option>'; }, array('normal', 'muted', 'rare')))
					. '</select></td>'
				. '<td class="num' . ($r['todo'] >= $r['n'] ? ' cur-none' : '') . '" data-v="' . $pct . '"' . ($r['todo'] >= $r['n'] ? ' title="' . t('str_none_of_its_detections_reviewed_yet') . '"' : '') . '><span class="bar"><span style="width:' . min(100, max($rv ? 3 : 0, $pct)) . '%"></span></span>' . $rv . ' (' . ($pct >= 10 ? round($pct) : fmt_num($pct, 1)) . '%)</td>'
				. '<td class="num">' . $r['a_yes'] . '</td><td class="num">' . $r['a_chg'] . '</td><td class="num">' . $r['a_unk'] . '</td><td class="num">' . $r['a_ign'] . '</td>'
				. '<td><button type="button" class="currm" onclick="curRemoveAll(this)" title="' . t('str_deletes_every_detection_of_the_help') . '">&#128683; ' . t('str_delete') . '</button></td>'
				. '<td><button type="button" class="openbtn" onclick="curationReview(this)" title="' . t('str_review_its_unreviewed_help') . '">' . t('str_review') . '</button></td></tr>';
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
	$titles = array('curtodo' => array(t('str_not_reviewed'), t('str_not_confirmed_no_answer_yet_help')),
		'curlow' => array(t('str_low_prob_dot'), t('str_still_to_review_and_its_help')),
		'currest' => array(t('str_yes_state'), t('str_confirmed_answered_yes_once_or_help')),
		'curno' => array(t('str_changed'), t('str_not_confirmed_its_detections_help')),
		'curunk' => array(t('str_review_later'), t('str_not_confirmed_and_answered_help')),
		'curign' => array(t('str_ignored'), t('str_not_confirmed_and_only_answered_help')),
		'curexcl' => array(t('str_others'), t('str_in_the_exclude_list_the_model_help')));
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
			<?php echo period_picker('views.php?view=Review', $pfrom, $pto, $pfirst, 'cur_period', array(), $psince); ?>
			<input type="search" class="hmfilter spfilter curright" data-table="curtable" placeholder="<?php echo t('str_filter_species'); ?>" title="<?php echo t('str_common_scientific_or_english_help'); ?>">
		<span class="curtools"><span class="dashfilter" role="button" tabindex="0" title="<?php echo t('str_filter_and_search_show_hide'); ?>"
			onclick="this.closest('.curlegend').classList.toggle('fopen')" onkeydown="if (event.key === 'Enter' || event.key === ' ') { event.preventDefault(); this.click(); }"><svg viewBox="0 0 24 24" width="18" height="18" aria-label="<?php echo t('str_filter'); ?>" role="img"><path fill="currentColor" d="M3 4h18l-7 8.5V19l-4 2v-8.5z"/></svg></span><?php echo sort_toggle(); ?><span class="hmsort" data-table="curtable" data-store="curation-v7" data-default="cur" data-remember="0"><small><?php echo t('str_sort_colon'); ?></small><button type="button" data-sort="cur" title="<?php echo t('str_by_group_each_in_its_own_order_help'); ?>"><?php echo t('str_default_filter'); ?></button><button type="button" data-sort="prob" title="<?php echo t('str_probability_of_this_week_least_help'); ?>"><?php echo t('str_probability'); ?></button><button type="button" data-sort="n"><?php echo t('str_count'); ?></button><button type="button" data-sort="tax" title="<?php echo t('str_field_guide_order_ebird_help'); ?>"><?php echo t('str_taxonomy'); ?></button><button type="button" data-sort="az" title="<?php echo t('str_alphabetical_by_the_names_shown'); ?>">A-Z</button></span></span>
		</div>
		<div class="curline curline2">
		<label class="curthr" title="<?php echo t('str_probability_of_this_week_below_help'); ?>"><?php echo t('str_low_prob_dot') . ':'; ?> <select id="curlowthr" class="nowbelow" onchange="curRegroup(true)"><?php
			// the same picklist as the Now filters (owner 2026-10-20): < 5 % … < 95 %
			for ($v = 5; $v <= 95; $v += 5) echo '<option value="' . $v . '"' . ($v === intval(round($low_default * 100)) ? ' selected' : '') . '>&lt; ' . $v . '%</option>'; ?></select></label>
		<?php foreach ($groups as $id => $trs) { ?><span class="curchip g-<?php echo $id; ?>" data-group="<?php echo $id; ?>" onclick="curHide(this)" title="<?php echo $h(t('str_click_to_hide_show', array('what' => $titles[$id][1]))); ?>"><?php echo $h($titles[$id][0]); ?> <span class="curcount">(<?php echo count($trs); ?>)</span></span><?php } ?>
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
		<tr><th data-nosort style="width:1%;text-align:center"><?php echo species_card_icon(); ?></th><th><select class="namemode" title="<?php echo t('str_names_shown'); ?>"><option value="com"><?php echo t('str_common_name'); ?></option><option value="sci"><?php echo t('str_scientific_name'); ?></option><option value="en"><?php echo t('str_english_name'); ?></option></select></th><th class="num"><?php echo t('str_detections'); ?></th><th class="num" title="<?php echo t('str_highest_confidence_of_its_detections'); ?>"><?php echo t('str_confidence'); ?></th><th class="num" title="<?php echo t('str_confidence_set_by_hand_for_the_help'); ?>"><?php echo t('str_manual_confidence'); ?></th><th class="num" title="<?php echo t('str_probability_of_as_in_help'); ?>"><?php echo t('str_probability'); ?></th><th><?php echo t('str_confirmed'); ?></th><th><?php echo t('str_whitelist'); ?></th><th title="<?php echo t('str_ticked_in_the_exclude_list_no_help'); ?>"><?php echo t('str_excluded_sp'); ?></th><th title="<?php echo t('str_notification_of_the_species_help'); ?>"><?php echo t('str_notification'); ?></th><th class="num" title="<?php echo t('str_detections_of_this_species_answered'); ?>"><?php echo t('str_reviewed'); ?></th><th class="num"><?php echo t('str_yes_state'); ?></th><th class="num"><?php echo t('str_changed'); ?></th>
			<th class="num"><?php echo t('str_review_later'); ?></th><th class="num"><?php echo t('str_ignored'); ?></th><th title="<?php echo t('str_the_group_the_species_belongs_help'); ?>"><?php echo t('str_group'); ?></th><th data-nosort></th><th data-nosort></th></tr>
		<?php foreach ($groups as $trs) echo implode("\n", $trs) . "\n"; ?>
	</table>
	</div>
	<?php // folded to its title line on every visit; a click on the title opens / folds it (owner 2026-10-22) ?>
	<details class="currecentbox"><summary><h3 style="display:inline"><?php echo t('str_last_reviews'); ?></h3></summary>
	<div id="currecent"><?php echo recent_reviews_html($db, 10, 0); ?></div></details>
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
	var curRecentN = 10, curRecentOff = 0, curRecentSeq = 0;
	function curRecent(offset) {
		curRecentOff = offset;
		// only the answer to the last request, only a 200 one (2026-11-04 hardening: an error page went into the table, a
		// quick Newer / Older let an older answer win) and the table undimmed whatever happens
		var box = document.getElementById('currecent'), x = new XMLHttpRequest(), mine = ++curRecentSeq;
		box.style.opacity = '.5';
		x.timeout = 20000;
		x.onloadend = function () { if (mine === curRecentSeq) box.style.opacity = ''; };
		x.onload = function () {
			if (mine !== curRecentSeq || this.status !== 200) return;
			box.innerHTML = this.responseText;
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
<script>window.BS_CURATION = {src: <?php echo json_encode($cur_src); ?>, orders: <?php echo json_encode($orders); ?>, titles: <?php echo json_encode(array_map(function ($x) { return $x[0]; }, $titles)); ?>};</script>
<script src="static/curation.js?v=<?php echo @filemtime(__DIR__ . "/../homepage/static/curation.js"); ?>"></script>
