<?php
################################################################################
# scripts/lib/periods.php - PERIODS OF THE PAGES AND OF VALIDATION
#
# PURPOSE:
#   The periods of the pages and of the validation: the helpers of the BirdSuite pages on this subject, moved
#   out of common.php (one file per subject, owner 2026-11-06).
#
# DEPENDENCIES:
#   - scripts/common.php (loads this file after core.php and view_registry.php; every lib may call the others)
#
# USAGE:
#   Loaded by common.php on every page; never requested on its own (404 without BS_PROG).
################################################################################

if (!defined('BS_PROG')) { http_response_code(404); exit; }

// The period of a page (owner 2026-10-22: Validation, then By Hour the same way): $default until another one is picked;
// the choice is kept in a cookie per page (all, today, last7, last30 or from:to). $url: the period of the request
// (&period=all|last7|last30|last24, &from=&to=) wins over the cookie. Returns array(from, to, since); ('', '') = every date;
// since = 'YYYY-MM-DD HH:MM:SS' only for last24 (the last 24 hours, Validation, owner 2026-10-23), else ''.
function page_period($cookie, $url = false, $default = 'today') {
	$valid = function ($d) { return preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$d) ? $d : ''; };
	$mode = $default;  // until a period is picked (owner 2026-10-22: By Hour and Validation open on the last 7 days)
	if ($url && in_array($_GET['period'] ?? '', array('all', 'last7', 'last30', 'today', 'last24'), true)) $mode = $_GET['period'];
	elseif ($url && isset($_GET['from'])) $mode = 'range';
	elseif (preg_match('/^(all|today|last24|last7|last30|\d{4}-\d{2}-\d{2}:\d{4}-\d{2}-\d{2})$/', $_COOKIE[$cookie] ?? '', $m)) $mode = strpos($m[1], ':') ? 'range' : $m[1];
	if ($mode === 'all') return array('', '');
	// the last 7 / 30 days end today, whatever day it is
	if ($mode === 'last7') return array(date('Y-m-d', strtotime('-6 days')), date('Y-m-d'));
	if ($mode === 'last30') return array(date('Y-m-d', strtotime('-29 days')), date('Y-m-d'));
	if ($mode === 'today') return array(date('Y-m-d'), date('Y-m-d'));
	if ($mode === 'last24') return array(date('Y-m-d', time() - 86400), date('Y-m-d'), date('Y-m-d H:i:s', time() - 86400));
	list($f, $t) = ($url && isset($_GET['from'])) ? array($_GET['from'], $_GET['to'] ?? '') : explode(':', $_COOKIE[$cookie]);
	$f = $valid($f); $t = $valid($t) ?: $f;
	if ($f === '') return array('', '');
	return $t < $f ? array($t, $f) : array($f, $t);
}

// Validation's period (the Review button of the species page follows it too)
function validation_period($url = false) { return page_period('cur_period', $url, 'last7'); }

// The review lists exactly what its source passed (owner 2026-11-02: "the review player must list exactly what the
// source table, filtered or not, passed"). Validation counts per species the detections of its period, at its location,
// without an answer; its Review opens the species page in review mode with those filters frozen in the link: rvf / rvt
// the period's dates (both empty = All), rvs the moment of Last 24 h, rvu the moment the table was drawn (a detection
// made later was not counted), loc the location shown (location_filter). The species page lists the same rows; an older
// link without them falls back to Validation's kept period, up to now.
function validation_source() {
	$day = function ($k) { $v = (string)($_GET[$k] ?? ''); return preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) ? $v : ''; };
	$at = function ($k) { $v = (string)($_GET[$k] ?? ''); return preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $v) ? $v : ''; };
	if (isset($_GET['rvf'])) {
		$f = $day('rvf'); $t = $day('rvt') ?: $f;
		if ($t < $f) { $x = $f; $f = $t; $t = $x; }
		return array($f, $f === '' ? '' : $t, $f === '' ? '' : $at('rvs'), $at('rvu'));
	}
	list($f, $t, $s) = validation_period() + array(2 => '');
	return array($f, $t, $s, '');
}

// the SQL condition of those rows (' AND ...'; the values are checked by validation_source)
function validation_source_sql($src) {
	list($f, $t, $s, $u) = $src;
	$w = $f !== '' ? " AND Date BETWEEN '" . $f . "' AND '" . $t . "'" . ($s !== '' ? " AND Date || ' ' || Time >= '" . $s . "'" : '') : '';
	// the date first, so the index on Date still narrows the rows
	if ($u !== '') $w .= " AND Date <= '" . substr($u, 0, 10) . "' AND Date || ' ' || Time <= '" . $u . "'";
	return $w;
}

// the link parameters Validation hands to the review (period, the moment drawn, the location shown)
function validation_source_params($from, $to, $since, $upto) {
	$l = location_shown();
	$loc = location_filter() === 'all' ? 'all' : ($l ? (string)intval($l['id']) : '');
	return '&rvf=' . rawurlencode($from) . '&rvt=' . rawurlencode($to) . ($since !== '' ? '&rvs=' . rawurlencode($since) : '')
		. '&rvu=' . rawurlencode($upto) . ($loc !== '' && locations_with_data() >= 2 ? '&loc=' . $loc : '');
}

// The period control (owner 2026-10-22), the same on Validation and By Hour: ◀ [Today / Last 7 days / Last 30 days /
// Date range / All] ▶ — the arrows move by the period's length, ▶ stops at today; Date range shows the From / to fields
// (open while a range is in use); the choice is written to the page's cookie. $base: the page's address ('views.php?
// view=...'), $keep: query parameters kept by every link and by the range form (e.g. array('sci' => ...)).
// $since: null on pages with Today (By Hour); a string on Validation, which offers Last 24 h instead ('' = not chosen)
function period_picker($base, $from, $to, $first, $cookie, $keep = array(), $since = null) {
	$h = function ($x) { return htmlspecialchars((string)$x, ENT_QUOTES); };
	$q = ''; foreach ($keep as $k => $v) if ($v !== '') $q .= '&amp;' . rawurlencode($k) . '=' . rawurlencode($v);
	$u = function ($extra) use ($base, $q) { return $base . $extra . $q; };
	$today = date('Y-m-d'); $d7 = date('Y-m-d', strtotime('-6 days')); $d30 = date('Y-m-d', strtotime('-29 days'));
	$is24 = $since !== null && $since !== '';
	$is_today = !$is24 && $from === $today && $to === $today; $is7 = $from === $d7 && $to === $today; $is30 = $from === $d30 && $to === $today;
	// another period (a day reached with the arrows, a range) shows as its dates in the closed picklist only (a hidden
	// option, not in the open list); the From / to fields open only when Date range is picked or its form was sent
	// (owner 2026-10-22: a single day is no range)
	$is_other = $from !== '' && !$is24 && !$is_today && !$is7 && !$is30;
	$range_open = isset($_GET['pr']);
	$days = $from === '' ? 0 : (int)round((strtotime($to) - strtotime($from)) / 86400) + 1;
	$o = '<span class="periodpick">';
	if ($is24) {
		// before the last 24 hours: yesterday as a day
		$o .= '<a class="stdbtn pnav" href="' . $u('&amp;from=' . $from . '&amp;to=' . $from) . '" title="' . $h(t('str_the_period_before')) . '">&#9664;</a>';
	} elseif ($days) {
		$pf = date('Y-m-d', strtotime($from . " -$days days")); $pt = date('Y-m-d', strtotime($to . " -$days days"));
		$o .= '<a class="stdbtn pnav" href="' . $u('&amp;from=' . $pf . '&amp;to=' . $pt) . '" title="' . $h(t('str_the_period_before')) . '">&#9664;</a>';
	}
	$sel = function ($c) { return $c ? ' selected' : ''; };
	$o .= '<select class="dashperiod" aria-label="' . htmlspecialchars(t('str_period'), ENT_QUOTES) . '" onchange="if (this.value === \'range\') this.parentNode.querySelector(\'.prange\').style.display = \'\'; else if (this.value) location.href = this.value">'
		. ($since !== null ? '<option value="' . $u('&amp;period=last24') . '"' . $sel($is24) . '>' . t('str_last_24_h') . '</option>'
			: '<option value="' . $u('&amp;period=today') . '"' . $sel($is_today) . '>' . t('str_today') . '</option>')
		. '<option value="' . $u('&amp;period=last7') . '"' . $sel($is7) . '>' . t('str_last_7_days') . '</option>'
		. '<option value="' . $u('&amp;period=last30') . '"' . $sel($is30) . '>' . t('str_last_30_days') . '</option>'
		// ascending, as every period picklist (owner 2026-10-22): Today, the last days, the range, then All
		. ($is_other ? '<option value="" selected hidden disabled>' . $h($from === $to ? t(weekday_key($from)) . date(' d/m/Y', strtotime($from)) : date('d/m/Y', strtotime($from)) . ' - ' . date('d/m/Y', strtotime($to))) . '</option>' : '')
		. '<option value="range">' . t('str_date_range') . '</option>'
		. '<option value="' . $u('&amp;period=all') . '"' . $sel($from === '') . '>' . t('str_all') . '</option></select>';
	if ($is24) {
		$o .= '<span class="stdbtn pnav off">&#9654;</span>';
	} elseif ($days) {
		$nf = date('Y-m-d', strtotime($from . " +$days days")); $nt = min($today, date('Y-m-d', strtotime($to . " +$days days")));
		$o .= $nf <= $today ? '<a class="stdbtn pnav" href="' . $u('&amp;from=' . $nf . '&amp;to=' . $nt) . '" title="' . $h(t('str_the_period_after')) . '">&#9654;</a>'
			: '<span class="stdbtn pnav off">&#9654;</span>';
	}
	parse_str(html_entity_decode(parse_url($base, PHP_URL_QUERY) ?: ''), $bq);
	$hidden = '';
	foreach ($bq + $keep as $k => $v) if ($v !== '') $hidden .= '<input type="hidden" name="' . $h($k) . '" value="' . $h($v) . '">';
	$o .= '<form class="yrange prange" method="GET" action="views.php"' . ($range_open ? '' : ' style="display:none"') . '>' . $hidden . '<input type="hidden" name="pr" value="1">'
		. t('str_from') . ' <input type="date" name="from" value="' . $h($from ?: $first) . '" max="' . $today . '"> '
		. t('str_to_lc') . ' <input type="date" name="to" value="' . $h($to ?: $today) . '" max="' . $today . '"> '
		. '<button type="submit">' . t('str_filter') . '</button></form></span>';
	$val = $from === '' ? 'all' : ($is24 ? 'last24' : ($is_today ? 'today' : ($is7 ? 'last7' : ($is30 ? 'last30' : $from . ':' . $to))));
	$o .= '<script>try { document.cookie = ' . json_encode($cookie . '=' . $val) . ' + \';path=/;max-age=31536000;SameSite=Lax\'; } catch (e) {}</script>';
	return $o;
}
