<?php
################################################################################
# scripts/lib/db.php - THE DATABASE: CONNECTION, LOCATION FILTER, CACHES
#
# PURPOSE:
#   The database: the connection, the location filter, the caches: the helpers of the BirdSuite pages on this
#   subject, moved out of common.php (one file per subject, owner 2026-11-06).
#
# DEPENDENCIES:
#   - scripts/common.php (loads this file after core.php and view_registry.php; every lib may call the others)
#
# USAGE:
#   Loaded by common.php on every page; never requested on its own (404 without BS_PROG).
################################################################################

if (!defined('BS_PROG')) { http_response_code(404); exit; }

// a statement SQLite could not prepare (the database busy or locked): 503 first (owner 2026-11-06, Gitea #77), so an
// ajax caller tells it from a page that is empty by right; the text and the refresh stay for a full page (a page
// included after its first output keeps the 200 it already sent, the text and the refresh as before)
function ensure_db_ok($sql_stmt) {
	if ($sql_stmt == False) {
		if (!headers_sent()) {
			http_response_code(503);
			header('Retry-After: 1');
		}
		echo t('str_database_is_busy');
		header("refresh:1;");
		exit;
	}
}

function get_db() {
	static $_db;
	if (!isset($_db)) {
		$_db = new SQLite3(BS_DB, SQLITE3_OPEN_READONLY);
		$_db->busyTimeout(5000);   // the analysis writes every few seconds: wait instead of failing a read
		apply_location_filter($_db);
	}
	return $_db;
}

function apply_location_filter($db) {
	db_names($db); // the species names as SQL functions (presentation only, see common_name())
	if (count(locations_load()) < 2) return; // one location: everything is there
	$l = location_shown();
	if ($l === null) return;
	@$db->exec('CREATE TEMP VIEW IF NOT EXISTS detections AS SELECT * FROM main.detections WHERE ' . location_sql($l));
}

// a value kept for $ttl seconds (2026-10-20): the all-days cards scan every detection, and the Latest page asks for them
// after each new detection; one file per location shown, in the system temp folder
// $global: a value of the whole station, not keyed by the location shown (locations_with_data(), Gitea #75)
function cached_value($key, $ttl, $fn, $global = false) {
	// keyed by the area the pages show (its SQL holds the radius and the neighbouring locations), the day and the
	// database's last change: a new detection or a review shows at once, the cache only spares the repeated polls
	$l = !$global && count(locations_load()) >= 2 ? location_shown() : null;
	clearstatcache(true, BS_DB);
	$f = sys_get_temp_dir() . '/birdsuite-' . md5(__ROOT__ . '|' . $key . '|' . ($l ? location_sql($l) : 'all') . '|' . date('Y-m-d')
		. '|' . @filemtime(BS_DB)) . '.json';
	if (is_file($f) && filemtime($f) > time() - $ttl) {
		$v = json_decode((string)@file_get_contents($f), true);
		if ($v !== null) return $v;
	}
	$v = $fn();
	$tmp = @tempnam(sys_get_temp_dir(), 'birdsuite-');
	if ($tmp !== false && @file_put_contents($tmp, json_encode($v)) !== false) @rename($tmp, $f); else if ($tmp) @unlink($tmp);
	// every database change makes a new file: now and then the old ones go
	if (mt_rand(1, 50) === 1) foreach (glob(sys_get_temp_dir() . '/birdsuite-*') ?: array() as $o) if (@filemtime($o) < time() - 600) @unlink($o);
	return $v;
}

// The change counter of each month before today (table past_changes, kept by triggers on detections: a removal, a
// restore, a species or location change, an import of past recordings; scripts/createdb.sh). 2026-10-23 run 3: a
// whole-history cache was rebuilt every day and after every past change (By Hour 4 s at ten times the station's data).
function past_month_changes() {
	static $m = null;
	if ($m !== null) return $m;
	$m = array();
	$r = get_db()->query('SELECT Month, N FROM past_changes');
	while ($r && ($x = $r->fetchArray(SQLITE3_NUM))) $m[$x[0]] = (int)$x[1];
	return $m;
}

// The rows of $fn($lo, $hi) for the days between $from (or the first detection) and $to (or yesterday), computed per
// calendar month and kept in the station's state folder (it survives a restart and a new day): a month is computed
// again only when its counter in $months changes (or, for the current month, when a day passes). Returns one row list
// per month, for the caller to add up.
function cached_past_months($key, $from, $to, $months, $fn) {
	$yesterday = date('Y-m-d', strtotime('-1 day'));
	$hi_all = ($to !== '' && $to < $yesterday) ? $to : $yesterday;
	$lo_all = $from;
	if ($lo_all === '') {
		$lo_all = (string)get_db()->querySingle('SELECT MIN(Date) FROM detections');
		if ($lo_all === '') return array();
	}
	if ($lo_all > $hi_all) return array();
	$l = count(locations_load()) >= 2 ? location_shown() : null;
	$dir = BS_STATE . '/cache';
	if (!is_dir($dir)) @mkdir($dir, 0775, true);
	$f = $dir . '/past-' . md5(__ROOT__ . '|' . $key . '|' . ($l ? location_sql($l) : 'all')) . '.json';
	$old = is_file($f) ? (json_decode((string)@file_get_contents($f), true) ?: array()) : array();
	$new = array();
	$out = array();
	$changed = false;
	for ($m = substr($lo_all, 0, 7); $m <= substr($hi_all, 0, 7); $m = date('Y-m', strtotime($m . '-01 +1 month'))) {
		$lo = max($lo_all, $m . '-01');
		$hi = min($hi_all, date('Y-m-t', strtotime($m . '-01')));
		$ck = $m . '|' . $lo . '|' . $hi . '|' . ($months[$m] ?? 0);
		if (isset($old[$ck])) {
			$new[$ck] = $old[$ck];
		} else {
			$new[$ck] = $fn($lo, $hi);
			$changed = true;
		}
		$out[] = $new[$ck];
	}
	// the files of periods no page asks for any more (a "last 7 days" key changes every day) go after two weeks
	if (mt_rand(1, 50) === 1) foreach (glob($dir . '/past-*.json') ?: array() as $o) if (@filemtime($o) < time() - 14 * 86400) @unlink($o);
	if ($changed || count($new) !== count($old)) {
		$tmp = @tempnam($dir, 'past-');
		if ($tmp !== false && @file_put_contents($tmp, json_encode($new)) !== false) {
			@chmod($tmp, 0664);
			@rename($tmp, $f);
		} else if ($tmp) @unlink($tmp);
	}
	return $out;
}

// COUNT(*) per group of the detections between $from and $to ('' = no bound), rejected ones ("not this bird") left out.
// $keys = the SQL expressions grouped by; returns rows array(key 1, ..., key n, count). Two cheap queries instead of
// NOT IN on every row (0.5 µs per detection, the larger part of these charts): every detection counted from the
// covering indexes, the days before today kept per month (cached_past_months), then the rejected ones (a few hundred, found through
// the reviews) taken off.
function grouped_counts($keys, $from = '', $to = '', $extra = '', $params = array()) {
	$db = get_db();
	$today = date('Y-m-d');
	$n = count($keys);
	$sel = implode(', ', array_map(function ($e, $i) { return "$e AS k$i"; }, $keys, array_keys($keys)));
	$grp = implode(', ', array_map(function ($i) { return "k$i"; }, array_keys($keys)));
	$rows = function ($where, $p) use ($db, $sel, $grp, $extra, $params) {
		$st = $db->prepare("SELECT $sel, COUNT(*) FROM detections WHERE $where$extra GROUP BY $grp");
		if ($st === false) return array();
		foreach ($params + $p as $k => $v) $st->bindValue($k, $v);
		$out = array();
		$res = $st->execute();
		while ($res && ($r = $res->fetchArray(SQLITE3_NUM))) $out[] = $r;
		return $out;
	};
	$range = ($from !== '' ? 'Date >= :f' : '1') . ($to !== '' ? ' AND Date <= :t' : '');
	$rp = ($from !== '' ? array(':f' => $from) : array()) + ($to !== '' ? array(':t' => $to) : array());
	$parts = array();
	if ($from === '' || $from < $today) {
		$gkey = 'grouped|' . implode('|', $keys) . '|' . $from . '|' . $to . '|' . $extra . '|' . json_encode($params);
		// per calendar month, each kept until a detection of that month changes (2026-10-23 run 3)
		$parts = array_merge($parts, cached_past_months($gkey, $from, $to, past_month_changes(), function ($lo, $hi) use ($rows, $range, $rp) {
			return $rows($range . ' AND Date BETWEEN :lo AND :hi', $rp + array(':lo' => $lo, ':hi' => $hi));
		}));
	}
	// today's rows with today as the only lower bound: with the period's start beside it the planner walked every day of
	// the species index (2026-10-23 run 3: 0.65 s instead of 20 ms at ten times the station's data)
	if ($to === '' || $to >= $today) $parts[] = $rows('Date >= :today' . ($to !== '' ? ' AND Date <= :t' : ''), ($to !== '' ? array(':t' => $to) : array()) + array(':today' => $today));
	$out = array();
	foreach ($parts as $part) foreach ($part as $r) {
		$k = implode("\x1f", array_slice($r, 0, $n));
		if (isset($out[$k])) $out[$k][$n] += $r[$n]; else $out[$k] = $r;
	}
	// +Date: the few rejected detections are found through the reviews, not by walking every day of the period
	// (2026-10-23 run 3: 0.6 s for By Hour "All" at ten times the station's data)
	foreach ($rows(str_replace('Date ', '+Date ', $range) . " AND File_Name IN (SELECT File_Name FROM detection_reviews WHERE Verdict = 'no')", $rp) as $r) {
		$k = implode("\x1f", array_slice($r, 0, $n));
		if (isset($out[$k]) && ($out[$k][$n] -= $r[$n]) <= 0) unset($out[$k]);
	}
	ksort($out, SORT_STRING); // in the order of GROUP BY, as one query gave them
	return array_values($out);
}
