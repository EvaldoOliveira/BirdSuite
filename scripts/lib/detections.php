<?php
################################################################################
# scripts/lib/detections.php - DETECTIONS: SUMMARIES, REVIEWS, TABLES AND ACTIONS
#
# PURPOSE:
#   The detections: summaries, reviews, tables and actions: the helpers of the BirdSuite pages on this
#   subject, moved out of common.php (one file per subject, owner 2026-11-06).
#
# DEPENDENCIES:
#   - scripts/common.php (loads this file after core.php and view_registry.php; every lib may call the others)
#
# USAGE:
#   Loaded by common.php on every page; never requested on its own (404 without BS_PROG).
################################################################################

if (!defined('BS_PROG')) { http_response_code(404); exit; }

function get_summary() {
	return cached_value('summary', 60, 'get_summary_now');
}

function get_summary_now() {
	$db = get_db();
	// detections reviewed "not this bird" are left out of every total (owner 2026-10-09)
	$nr = not_rejected_sql();
	// every detection minus the rejected ones: NOT IN on every row cost 1-1.6 s at ten times the station's data, this 0.17 s
	// (2026-10-23 test run)
	$statement = $db->prepare($nr === '' ? 'SELECT COUNT(*) FROM detections WHERE 1'
		: "SELECT (SELECT COUNT(*) FROM detections) - (SELECT COUNT(*) FROM detections WHERE File_Name IN (SELECT File_Name FROM detection_reviews WHERE Verdict = 'no')) AS \"COUNT(*)\"");
	ensure_db_ok($statement);
	$result = $statement->execute();
	$totalcount = $result->fetchArray(SQLITE3_ASSOC);

	$statement2 = $db->prepare('SELECT COUNT(*) FROM detections WHERE Date == DATE(\'now\', \'localtime\')' . $nr);
	ensure_db_ok($statement2);
	$result2 = $statement2->execute();
	$todaycount = $result2->fetchArray(SQLITE3_ASSOC);

	$statement3 = $db->prepare('SELECT COUNT(*) FROM detections WHERE Date == Date(\'now\', \'localtime\') AND TIME >= TIME(\'now\', \'localtime\', \'-1 hour\')' . $nr);
	ensure_db_ok($statement3);
	$result3 = $statement3->execute();
	$hourcount = $result3->fetchArray(SQLITE3_ASSOC);

	$statement5 = $db->prepare('SELECT COUNT(DISTINCT(Sci_Name)) FROM detections WHERE Date == Date(\'now\',\'localtime\')' . $nr);
	ensure_db_ok($statement5);
	$result5 = $statement5->execute();
	$todayspeciestally = $result5->fetchArray(SQLITE3_ASSOC);

	$statement6 = $db->prepare('SELECT COUNT(DISTINCT(Sci_Name)) FROM detections WHERE 1' . $nr);
	ensure_db_ok($statement6);
	$result6 = $statement6->execute();
	$totalspeciestally = $result6->fetchArray(SQLITE3_ASSOC);

	$ret = [
		'totalcount' => $totalcount['COUNT(*)'],
		'todaycount' => $todaycount['COUNT(*)'],
		'hourcount' => $hourcount['COUNT(*)'],
		'speciestally' => $todayspeciestally['COUNT(DISTINCT(Sci_Name))'],
		'totalspeciestally' => $totalspeciestally['COUNT(DISTINCT(Sci_Name))']
	];
	return $ret;
}

// Review loop: verdict of one detection file ('' = not reviewed), and the SQL that leaves rejected
// detections ("not this bird") out of a query on detections
// The answers of this request: 'rows' = File_Name => array(Verdict, Reason, changed), 'only' = null when rows hold the
// whole table, else the set of file names asked by review_verdicts_only() (Gitea #75)
function &review_cache() {
	static $c = array('rows' => null, 'only' => null);
	return $c;
}

// [2]: the detection came from Change species (Orig_Sci kept): clear leaves it Changed (play.php, Gitea #69)
const REVIEW_COLUMNS = "File_Name, Verdict, COALESCE(Reason, ''), COALESCE(Orig_Sci, '') <> ''";

// every answer of the station (the whole detection_reviews table); a cache filled by review_verdicts_only() is
// completed first
function review_verdicts() {
	$c = &review_cache();
	if ($c['rows'] === null || $c['only'] !== null) {
		$c['rows'] = array();
		$c['only'] = null;
		$res = get_db()->query('SELECT ' . REVIEW_COLUMNS . ' FROM detection_reviews');
		while ($res && ($r = $res->fetchArray(SQLITE3_NUM))) $c['rows'][$r[0]] = array($r[1], $r[2], (bool)$r[3]);
	}
	return $c['rows'];
}

// Contract (owner 2026-11-06, Gitea #75): a page that knows the files it lists calls review_verdicts_only($files)
// once, before its rows; review_verdict(), review_reason() and review_item_attrs() then answer those files from the
// few rows read (one SELECT per 500 names) instead of the whole table. A file outside the set still gets its true
// answer: review_entry() then loads the whole table (review_verdicts()), never "not reviewed" by mistake.
function review_verdicts_only(array $file_names) {
	$c = &review_cache();
	if ($c['rows'] !== null && $c['only'] === null) return;  // the whole table is already read
	if ($c['rows'] === null) { $c['rows'] = array(); $c['only'] = array(); }
	$ask = array();
	foreach ($file_names as $f) {
		$f = (string)$f;
		if ($f === '' || isset($c['only'][$f])) continue;
		$c['only'][$f] = true;
		$ask[] = $f;
	}
	if (!$ask) return;
	$db = get_db();
	foreach (array_chunk($ask, 500) as $chunk) {
		$in = implode(',', array_map(function ($f) { return "'" . SQLite3::escapeString($f) . "'"; }, $chunk));
		$res = $db->query('SELECT ' . REVIEW_COLUMNS . " FROM detection_reviews WHERE File_Name IN ($in)");
		while ($res && ($r = $res->fetchArray(SQLITE3_NUM))) $c['rows'][$r[0]] = array($r[1], $r[2], (bool)$r[3]);
	}
}

// the answer kept for one file, or null when it was not reviewed
function review_entry($file_name) {
	$c = &review_cache();
	if ($c['rows'] !== null && ($c['only'] === null || isset($c['only'][$file_name]))) return $c['rows'][$file_name] ?? null;
	return review_verdicts()[$file_name] ?? null;
}

// The one Review button of the interface: always reviewDetection(this) (static/review-player.js), no local variants
function validate_button($file, $verdict = null, $positioned = false) {
	$reason = review_reason(basename($file));
	if ($verdict === null) $verdict = review_verdict(basename($file));
	// "not this bird" without a cause counts as Review later (Validation): shown so
	if ($verdict === 'no' && $reason === '') $verdict = 'unsure';
	// the button names the answer chosen (owner 2026-10-22): the cause in red, Yes / Changed in green, Unknown bird in grey
	$causes = array('human' => t('str_human'), 'noise' => t('str_noise'), 'ignore' => t('str_no_ignore'));
	$labels = array('' => t('str_review'), 'yes' => '&#10003; ' . ($reason === 'changed' ? t('str_changed') : t('str_yes_state')),
									'no' => isset($causes[$reason]) ? '&#10007; ' . htmlspecialchars($causes[$reason]) : '&#8644; ' . htmlspecialchars(t('str_change_species')), 'unsure' => '? ' . t('str_review_later'));
	$titles = array('' => t('str_is_this_the_bird_review_this_help'), 'yes' => t('str_reviewed_yes_this_bird_species_help'),
									'no' => t('str_reviewed_not_this_species_a_help'), 'unsure' => t('str_reviewed_review_later'));
	return '<button type="button" class="validatebtn v-' . ($verdict ?: 'none') . ($positioned ? ' positioned' : '') . '" title="' . htmlspecialchars($titles[$verdict], ENT_QUOTES)
		. '" data-file="' . htmlspecialchars($file, ENT_QUOTES) . '" onclick="reviewDetection(this)">' . $labels[$verdict] . '</button>';
}

// Attributes of one item of a review list (review player, static/review-player.js)
// $sci: the scientific name; the player shows "Scientific - English (eBird / Clements)" under the title
// $row: the detection (Confidence, Cutoff, Sens, Overlap, Date): the player lists the analysis settings at the bottom
function review_item_attrs($file, $label, $sci = '', $row = null) {
	static $locked = null;
	$home = get_home();
	if ($locked === null) {
		$list = BS_DISK_EXCLUDE;
		$locked = is_file($list) ? array_flip(file($list, FILE_IGNORE_NEW_LINES)) : array();
	}
	// the clip's folder for the station File Manager (SFTP): its full path, By_Date/<date>/<species>
	$dir = BS_BY_DATE . '/' . dirname($file);
	// data-spec: the stored picture, PNG while the day runs, JPEG once archived (owner 2026-10-22: Calibrate's older
	// samples showed no spectrogram, the player asked for <clip>.png only)
	// data-gone: the clip is no longer on disk (the nightly clean-up keeps N files per species, the line stays); the review
	// player skips it (owner 2026-10-22)
	$gone = !is_file(BS_BY_DATE . '/' . $file);
	// a clip the nightly staging moved to Extracted/Purge (answered with a cause) still plays from there, so its answer
	// can be changed; play.php moves it back at once (2026-10-23 test run)
	$base = '/By_Date/';
	if ($gone && is_file(BS_EXTRACTED . '/Purge/' . $file)) { $gone = false; $base = '/Purge/'; }
	// data-reason: the cause of the answer, so the player's Undo puts it back (owner 2026-10-22)
	$reason = review_reason(basename($file));
	// data-changed: it came from Change species, so clear shows it as Changed again, as the station keeps it (Gitea #69)
	$changed = review_entry(basename($file))[2] ?? false;
	return ' data-ri="1"' . ($gone ? ' data-gone="1"' : '') . ($reason !== '' ? ' data-reason="' . htmlspecialchars($reason, ENT_QUOTES) . '"' : '') . ($changed ? ' data-changed="1"' : '') . ' data-file="' . htmlspecialchars($file, ENT_QUOTES) . '" data-clip="' . htmlspecialchars($base . $file, ENT_QUOTES)
		. '" data-spec="' . htmlspecialchars(clip_spectrogram($base . $file), ENT_QUOTES)
		. '" data-label="' . htmlspecialchars($label, ENT_QUOTES) . '" data-dir="' . htmlspecialchars($dir, ENT_QUOTES) . '"'
		. ($sci !== '' ? ' data-sci="' . htmlspecialchars($sci, ENT_QUOTES) . '" data-en="' . htmlspecialchars(get_english_name($sci), ENT_QUOTES) . '"' : '')
		. ($row !== null && isset($row['Date']) ? ' data-when="' . htmlspecialchars($row['Date'] . (isset($row['Time']) ? ' ' . $row['Time'] : ''), ENT_QUOTES) . '"' : '')
		. ($sci !== '' ? ' data-com="' . htmlspecialchars(common_name($sci), ENT_QUOTES) . '"' : '')
		. ($row !== null ? ' data-params="' . htmlspecialchars(json_encode(review_params($sci, $row)), ENT_QUOTES) . '"' : '')
		. ' data-locked="' . (isset($locked[$file]) ? 1 : 0) . '"';
}

// The standard detection list (owner 2026-10-09): Now (list view) and the species page. $rows: detections with Date,
// Time, Sci_Name, Confidence, Cutoff, Sens, Overlap, File_Name. A click on a row (or its Review button)
// opens the review player there; it goes on down the list. $with_species = false on a species' own page.
// $with_date = false when every row is of the same day (All Detections › Today)
// $simple (Now › Simple, owner 2026-10-20): time, species, confidence, probability and review only
// The species name of a detection in a review list (owner 2026-10-26): the name opens the review player, the species
// card icon beside it the species page; the <a data-com data-sci data-en> keeps the name switch and the sort working
// ($file: the clip, for a row that is not a review item of its own)
// the species card icon (the species page) alone: a table gives it a narrow column of its own, headed by the icon
function species_card_icon($sci = null) {
	$svg = '<svg viewBox="0 0 24 24" width="15" height="15" aria-hidden="true" style="vertical-align:-2px"><rect x="3" y="2" width="18" height="20" rx="2.5" fill="none" stroke="currentColor" stroke-width="2"/><path d="M7 7h10M7 11h10M7 15h6" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>';
	if ($sci === null) return '<span role="img" title="' . t('str_species_card') . '" aria-label="' . t('str_species_card') . '">' . $svg . '</span>';
	return '<a class="spcard" href="views.php?view=Species&amp;sci=' . rawurlencode($sci) . '" title="' . t('str_open_the_species_page') . '" aria-label="' . t('str_species_card') . '" onclick="event.stopPropagation()">' . $svg . '</a>';
}

function species_review_name($sci, $com, $bold = false, $file = null, $card = true) {
	$h = function ($s) { return htmlspecialchars((string)$s, ENT_QUOTES); };
	$call = $file === null ? 'reviewDetection(this)' : 'reviewDetection(' . $h(json_encode($file)) . ', this)';
	// the card first, then the name (owner 2026-10-26)
	return ($card ? species_card_icon($sci) . ' ' : '')
		. '<a href="#" onclick="event.preventDefault(); event.stopPropagation(); ' . $call . '" title="' . t('str_listen_and_review') . '" data-com="' . $h($com)
		. '" data-sci="' . $h($sci) . '" data-en="' . $h(get_english_name($sci)) . '">' . ($bold ? '<b>' . $h($com) . '</b>' : $h($com)) . '</a>';
}

function detection_review_table($rows, $with_species = true, $with_date = true, $simple = false) {
	if ($simple) $with_date = false;
	static $profile = null;
	if ($profile === null) {
		$pj = is_file(BS_SCRIPTS . '/region_profile.json') ? json_decode(file_get_contents(BS_SCRIPTS . '/region_profile.json'), true) : null;
		$profile = $pj['data'] ?? array();
	}
	$h = function ($s) { return htmlspecialchars((string)$s, ENT_QUOTES); };
	// Manual Conf., Min. Prob. and Rec. Length are the values in force when the detection was made (columns Sp_Override,
	// Loc_Thresh, Rec_Length since 2026-10-09); older detections show — (owner: never today's setting on an old detection)
	$sf_now = get_config()['SF_THRESH'] ?? '';
	// the Location column (owner 2026-10-21): right of Rec. Length in the full view, and in every view while the table
	// shows several locations (Now with All locations)
	$with_loc = multi_location() && count(locations_load()) > 1 && !$simple; // never in the Simple view (owner 2026-10-21), nor in simple mode (US-125)
	// columns (owner 2026-10-09, standard order; 2026-10-21 Prob. after every confidence column): Conf., Min. Conf.,
	// Manual Conf., then Prob. and Min. Prob.; the analysis — Sigm. Sens., Overlap, Rec. Length; species picklist column
	// the species card in a narrow column of its own, first on the left, headed by its icon (owner 2026-10-26)
	$out = '<div class="reviewlist" data-review-list="1"><table class="list stdtable"><tr>'
		. ($with_species ? '<th class="spc" data-nosort style="width:1%;text-align:center">' . species_card_icon() . '</th>' : '') . ($with_date ? '<th>' . t('str_date') . '</th>' : '') . '<th>' . t('str_time') . '</th>'
		. ($with_species ? '<th><select class="namemode" title="' . t('str_names_shown') . '"><option value="com">' . t('str_common_name') . '</option><option value="sci">' . t('str_scientific_name') . '</option><option value="en">' . t('str_english_name') . '</option></select></th>' : '')
		// short labels, the explanation on mouse over (owner 2026-10-21)
		. '<th class="num" title="' . t('str_confidence_the_score_the_model_help') . '">' . t('str_conf') . '</th>'
		. ($simple ? '' : '<th class="num" title="' . t('str_minimum_confidence_in_force_help') . '">' . t('str_min_conf') . '</th>'
		. '<th class="num" title="' . t('str_manual_confidence_of_the_help') . '">' . t('str_manual_conf') . '</th>')
		. '<th class="num" title="' . t('str_probability_of_how_likely_help') . '">' . t('str_prob') . '</th>'
		. ($simple ? '' : '<th class="num" title="' . t('str_minimum_probability_at_the_location_in_help') . '">' . t('str_min_prob') . '</th>')
		. ($simple ? '' : '<th class="num" title="' . t('str_sigmoid_sensitivity_of_the_analysis') . '">' . t('str_sigm_sens') . '</th>'
		. '<th class="num" title="' . t('str_overlap_of_the_analysis_s') . '">' . t('str_overlap') . '</th>'
		. '<th class="num" title="' . t('str_recording_length_in_force_when_help') . '">' . t('str_rec_length') . '</th>')
		. ($with_loc ? '<th>' . t('str_location') . '</th>' : '')
		. '<th class="rv" data-nosort>' . t('str_review') . '</th></tr>';
	foreach ($rows as $g) {
		$folder = species_folder($g['Sci_Name']);
		$file = $g['Date'] . '/' . $folder . '/' . $g['File_Name'];
		$t = strtotime($g['Date']);
		$w = min(48, (intval(date('n', $t)) - 1) * 4 + min(4, intdiv(intval(date('j', $t)) - 1, 7) + 1));
		$prob_v = isset($profile[$g['Sci_Name']]) ? floatval($profile[$g['Sci_Name']][$w - 1]) : null;
		$prob = $prob_v !== null ? fmt_num($prob_v * 100, 1) . '%' : '-';
		$label = common_name($g['Sci_Name']) . ', ' . $g['Date'] . ' ' . $g['Time'] . ', ' . round($g['Confidence'] * 100) . '%';
		$card = $with_species ? '<td class="spc" style="text-align:center">' . species_card_icon($g['Sci_Name']) . '</td>' : '';
		$species = $with_species ? '<td>' . species_review_name($g['Sci_Name'], common_name($g['Sci_Name']), false, null, false) . '</td>' : '';
		$out .= '<tr class="rvrow"' . review_item_attrs($file, $label, $g['Sci_Name'], $g) . ' onclick="if (!event.target.closest(\'a,button\')) reviewDetection(this)" title="' . t('str_listen_and_review') . '">' . $card
			. ($with_date ? '<td class="nw">' . $h($g['Date']) . '</td>' : '') . '<td class="nw">' . $h($g['Time']) . '</td>' . $species
			. '<td class="num">' . round($g['Confidence'] * 100) . '%</td>'
			. ($simple ? '' : '<td class="num">' . (($g['Cutoff'] ?? null) !== null ? round($g['Cutoff'] * 100) . '%' : '') . '</td>'
			. '<td class="num">' . (($g['Sp_Override'] ?? null) !== null ? round(floatval($g['Sp_Override']) * 100) . '%' : '-') . '</td>')
			. '<td class="num' . ($prob_v !== null ? ($prob_v >= floatval($g['Loc_Thresh'] ?? $sf_now) ? ' pgood' : ' pbad') : '') . '">' . $prob . '</td>'
			. ($simple ? '' : '<td class="num">' . (($g['Loc_Thresh'] ?? null) !== null ? round(floatval($g['Loc_Thresh']) * 100) . '%' : '-') . '</td>')
			. ($simple ? '' : '<td class="num">' . $h($g['Sens'] ?? '') . '</td>'
			. '<td class="num">' . (($g['Overlap'] ?? '') === '' ? '' : $h($g['Overlap']) . ' s') . '</td>'
			. '<td class="num">' . (($g['Rec_Length'] ?? null) !== null ? intval($g['Rec_Length']) . ' s' : '-') . '</td>')
			. ($with_loc ? '<td class="nw">' . $h(detection_location_name($g)) . '</td>' : '')
			. '<td class="rv">' . validate_button($file) . '</td></tr>';
	}
	return $out . '</table></div>';
}

function review_params($sci, $row) {
	$pct = function ($v) { return ($v === null || $v === '') ? '-' : round(floatval($v) * 100) . '%'; };
	$prob = location_probability($sci, $row['Date'] ?? '');
	// the same short names as the detection lists (owner 2026-10-09); the confidences first, then the probability of
	// occurrence at the location (owner 2026-10-21: Prob. after every confidence column), then the analysis
	return array(
		'Conf.' => $pct($row['Confidence'] ?? null),
		'Min. Conf.' => $pct($row['Cutoff'] ?? null),
		'Manual Conf.' => $pct($row['Sp_Override'] ?? null),
		'Prob.' => $prob === null ? '-' : fmt_num($prob * 100, 1) . '%',
		'Min. Prob.' => $pct($row['Loc_Thresh'] ?? null),
		'Sigm. Sens.' => (string)($row['Sens'] ?? '-'),
		'Rec. Length' => ($row['Rec_Length'] ?? null) === null ? '-' : intval($row['Rec_Length']) . ' s',
		'Overlap' => ($row['Overlap'] ?? '') === '' ? '-' : $row['Overlap'] . ' s',
		// the microphone it was recorded with, its USB port and its gain (owner 2026-11-04; '-' before that day)
		'Microphone' => (string)($row['Mic'] ?? '') !== '' ? (string)$row['Mic'] : '-',
		'Port' => (string)($row['Mic_Port'] ?? '') !== '' ? 'USB ' . $row['Mic_Port'] : '-',
		'Gain' => ($row['Mic_Gain'] ?? null) === null || $row['Mic_Gain'] === '' ? '-' : intval($row['Mic_Gain']) . ' %',
		// the scene (owner 2026-11-04): the model, the audio the recorder lost in that recording, the time zone
		'Model' => (string)($row['Model'] ?? '') !== '' ? model_short_name((string)$row['Model']) : '-',
		'Audio loss' => ($row['Audio_Loss_N'] ?? null) === null || $row['Audio_Loss_N'] === '' ? '-'
			: (intval($row['Audio_Loss_N']) === 0 ? t('str_none_lc') : intval($row['Audio_Loss_N']) . ' (' . fmt_num(floatval($row['Audio_Loss_Ms'] ?? 0), 1) . ' ms)'),
		'Time zone' => (string)($row['Tz'] ?? '') !== '' ? $row['Tz'] . ' (UTC' . ($row['Utc_Offset'] ?? '') . ')' : '-',
	);
}

function review_verdict($file_name) {
	return review_entry($file_name)[0] ?? '';
}

// the cause of a "not this bird" answer, or 'changed' on the Yes written by Change species (owner 2026-10-22)
function review_reason($file_name) {
	return review_entry($file_name)[1] ?? '';
}

function not_rejected_sql() {
	return " AND File_Name NOT IN (SELECT File_Name FROM detection_reviews WHERE Verdict = 'no')";
}

// Action icons of one detection (delete, change species, protect from purge, frequency shift), the same
// ones the Recordings page shows, so a card does not need the "open in new tab" detour (owner 2026-10-08).
// $file = "<date>/<common name>/<file>"; $positioned = the absolute top-right layout of the cards.
function detection_actions($file, $positioned = true, $style = '', $width = 25, $with_review = true) {
	static $locked = null;
	$home = get_home();
	if ($locked === null) {
		$list = BS_DISK_EXCLUDE;
		$locked = is_file($list) ? array_flip(file($list, FILE_IGNORE_NEW_LINES)) : array();
	}
	$verdict = review_verdict(basename($file));
	$f = htmlspecialchars(json_encode($file), ENT_QUOTES);
	$lock = isset($locked[$file])
		? array('del', 'images/lock.svg', t('str_this_file_is_excluded_from_help'))
		: array('add', 'images/unlock.svg', t('str_this_file_95_pct_usage_help'));
	$icons = array(
		array("deleteDetection($f)", 'images/delete.svg', t('str_delete_detection'), '190px'),
		array("toggleLock($f, &quot;$lock[0]&quot;, this)", $lock[1], $lock[2], '115px'),
	);
	// Review loop: a narrow "Review" button (owner 2026-10-08), its label and colour show the verdict; the last
	// button on the right of the action row. The icons (delete, protect from purge, frequency shift; the change-species icon is gone since 2026-10-22, Change species is an answer)
	// live only in the review player since 2026-10-21 (owner): the pages show the Review button alone
	$icons = array();
	$html = '';
	foreach ($icons as $i) {
		$css = 'cursor:pointer;' . ($positioned && $i[3] !== '' ? "right:$i[3];" : '') . $style;
		$html .= "<img style=\"$css\" src=\"$i[1]\" onclick=\"$i[0]\"" . ($positioned ? ' class="copyimage"' : '')
			. " width=\"$width\" title=\"" . htmlspecialchars($i[2], ENT_QUOTES) . '"> ';
	}
	return $html . ($with_review ? validate_button($file, $verdict, $positioned) : '');
}

// for the birds eBird knows, and Wikipedia in the station language. $style/$width are the page's icon style.
