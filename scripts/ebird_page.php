<?php
################################################################################
# scripts/ebird_page.php - EBIRD
#
# PURPOSE:
#   EBird (US-27): the species of a day, a week or a month, one column per day, with a filter (all detections,
#   only the confirmed ones, or from a minimum confidence), the eBird file of what is shown (eBird Record
#   Format, one checklist per day and location; scripts/ebird_report.py), a button that e-mails it, and the
#   periodic e-mail (daily or weekly) with its own filter. The table on screen asks nothing of the network (no
#   weather, no geocoding): only the file and the e-mail do. Opened as views.php?view=eBird.
#
# DEPENDENCIES:
#   - scripts/common.php
#   - homepage/static/name-mode.js
#   - scripts/ebird_report.py
#
# USAGE:
#   views.php?view=eBird
#   Referenced by homepage/views.php
################################################################################

require_once __DIR__ . '/common.php';
$h = function ($s) { return htmlspecialchars((string)$s, ENT_QUOTES); };
$config = get_config();

function eb_run($args) {
	$cmd = station_prefix() . escapeshellarg(BS_PYTHON) . ' '
		. escapeshellarg(__DIR__ . '/ebird_report.py') . ' ' . implode(' ', array_map('escapeshellarg', $args)) . ' 2>/dev/null';
	return (string)shell_exec($cmd);
}
$valid_date = function ($d) { return is_string($d) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) && strtotime($d) !== false; };
// the filter of a request: mode (all / confirmed / minconf) and the minimum confidence
$req_mode = function ($v) { return in_array($v, array('all', 'confirmed', 'minconf'), true) ? $v : 'confirmed'; };
$req_min = function ($v) { return is_numeric($v) ? round(max(0, min(0.99, floatval($v))), 2) : 0.7; };
// the species unticked in the table (owner 2026-10-27): scientific names joined by |, left out of the file and the e-mail
$req_skip = function ($v) {
	$out = array();
	foreach (explode('|', (string)$v) as $sci) { $sci = trim($sci); if (preg_match('/^[\p{L}][\p{L} .()×\-]{1,80}$/u', $sci)) $out[] = $sci; }
	return implode('|', array_slice(array_unique($out), 0, 2000));
};

// the location name of the eBird file (owner 2026-11-05): one field for every checklist, kept as EBIRD_LOCATION_NAME
// (the last name used; empty = the name of each detection's location); one line, at most 100 characters
$req_name = function ($v) {
	return mb_substr(trim(preg_replace('/[\x00-\x1f\x7f"$\\`|]+/u', ' ', (string)$v)), 0, 100);
};
// the names used before, newest first (EBIRD_LOCATION_NAMES, joined by |, at most 10): the page's picklist
$recent_names = function () use ($config) {
	return array_values(array_filter(array_map('trim', explode('|', trim((string)($config['EBIRD_LOCATION_NAMES'] ?? ''), "\"' ")))));
};
// a file or an e-mail made with a name keeps it as the last one and first in the picklist (owner 2026-11-05)
$keep_name = function ($name) use ($config, $recent_names) {
	$recent = $recent_names();
	if ($name !== '') $recent = array_slice(array_values(array_unique(array_merge(array($name), $recent))), 0, 10);
	$set = array();
	if ($name !== trim((string)($config['EBIRD_LOCATION_NAME'] ?? ''), "\"' ")) $set['EBIRD_LOCATION_NAME'] = $name;
	if ($recent !== $recent_names()) $set['EBIRD_LOCATION_NAMES'] = implode('|', $recent);
	if ($set) conf_set_many($set, $err);
};

// a saved location name wrong or no longer wanted leaves the picklist (owner 2026-11-05); the last one used too
if (isset($_GET['delname'])) {
	ensure_authenticated(t('str_you_must_change_the_settings_help'));
	if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); die(); }
	$name = $req_name($_POST['name'] ?? '');
	$set = array('EBIRD_LOCATION_NAMES' => implode('|', array_values(array_filter($recent_names(), function ($n) use ($name) { return $n !== $name; }))));
	if ($name === trim((string)($config['EBIRD_LOCATION_NAME'] ?? ''), "\"' ")) $set['EBIRD_LOCATION_NAME'] = '';
	header('Content-Type: text/plain');
	echo conf_set_many($set, $err) ? 'OK' : t('str_not_saved_lc');
	die();
}

// the eBird file of the days shown (scripts/ebird_page.php?csv=1&from=&to=&mode=&min=, outside the page frame)
if (isset($_GET['csv'])) {
	ensure_authenticated(t('str_you_must_download_the_ebird_help'));  // it carries the coordinates
	$from = $_GET['from'] ?? ''; $to = $_GET['to'] ?? $from;
	if (!$valid_date($from) || !$valid_date($to)) { http_response_code(400); die('bad dates'); }
	$loc_name = $req_name($_GET['place'] ?? '');
	if (isset($_GET['place'])) $keep_name($loc_name);  // only a request from the page's box changes the saved name
	$args = array($from, $to, $req_mode($_GET['mode'] ?? ''), (string)$req_min($_GET['min'] ?? ''), $req_skip($_GET['skip'] ?? ''), $loc_name);
	$fname = trim(eb_run(array('name', $from, $to)));
	// a week or a month: one eBird file per day, in a zip (owner 2026-11-05)
	if ($from !== $to) {
		header('Content-Type: application/zip');
		header('Content-Disposition: attachment; filename="' . (preg_match('/^[\w.\- ]+\.csv$/u', $fname) ? substr($fname, 0, -4) : 'ebird-' . $from) . '.zip"');
		echo eb_run(array_merge(array('zip'), $args));
		die();
	}
	header('Content-Type: text/csv; charset=utf-8');
	header('Content-Disposition: attachment; filename="' . (preg_match('/^[\w.\- ]+\.csv$/u', $fname) ? $fname : 'ebird-' . $from . '.csv') . '"');
	echo eb_run(array_merge(array('csv'), $args));
	die();
}
// the sounds for eBird (owner 2026-10-27): a zip with the n best clips (asked, 5 by default) confirmed by ear of every bird of the days shown
// (whatever the filter), named
// date_time_common_scientific_confidence, to add to the imported checklists with eBird's Add media
if (isset($_GET['sounds'])) {
	ensure_authenticated(t('str_you_must_download_the_ebird_help'));
	$from = $_GET['from'] ?? ''; $to = $_GET['to'] ?? $from;
	if (!$valid_date($from) || !$valid_date($to)) { http_response_code(400); die('bad dates'); }
	$list = json_decode(eb_run(array('clips', $from, $to, $req_mode($_GET['mode'] ?? ''), (string)$req_min($_GET['min'] ?? ''), $req_skip($_GET['skip'] ?? ''))), true) ?: array();
	$root = BS_BY_DATE . '/';
	$tmp = tempnam(sys_get_temp_dir(), 'ebsounds');
	$zip = new ZipArchive();
	if ($zip->open($tmp, ZipArchive::OVERWRITE) !== true) { http_response_code(500); die('zip'); }
	$count = array(); $added = 0; $wavs = array();
	$per = max(1, min(20, intval($_GET['n'] ?? 5)));  // asked when the button is pressed (owner 2026-10-27)
	foreach ($list as $c) {
		if (($count[$c['sci']] ?? 0) >= $per) continue;
		$rel = $c['date'] . '/' . species_folder($c['sci']) . '/' . $c['file'];
		$path = is_file($root . $rel) ? $root . $rel : (is_file($root . 'shifted/' . $rel) ? $root . 'shifted/' . $rel : '');
		if ($path === '') continue;  // cleaned up by the nightly purge
		// WAV, the format the Macaulay Library takes for sure (the clips are FLAC or MP3); the clip as it is if sox fails
		$name = $c['date'] . '_' . str_replace(':', 'h', substr($c['time'], 0, 5)) . '_' . name_safe($c['com']) . '_' . name_safe($c['sci']) . '_'
			. sprintf('%.2f', $c['conf']);
		$wav = tempnam(sys_get_temp_dir(), 'ebwav') . '.wav';
		exec('sox ' . escapeshellarg($path) . ' -b 16 ' . escapeshellarg($wav) . ' 2>/dev/null', $o, $rc);
		if ($rc === 0 && is_file($wav)) { $wavs[] = $wav; $name .= '.wav'; $zip->addFile($wav, $name); }
		else { $name .= '.' . pathinfo($c['file'], PATHINFO_EXTENSION); $zip->addFile($path, $name); }
		$zip->setCompressionName($name, ZipArchive::CM_STORE);  // audio does not compress
		$count[$c['sci']] = ($count[$c['sci']] ?? 0) + 1; $added++;
	}
	if (!$added) $zip->addFromString('README.txt', t('str_no_sound_confirmed_by_ear_is_help'));
	$zip->close();
	foreach ($wavs as $w) { @unlink($w); @unlink(substr($w, 0, -4)); }
	$fname = preg_replace('/\.csv$/', '', trim(eb_run(array('name', $from, $to))));
	header('Content-Type: application/zip');
	header('Content-Disposition: attachment; filename="' . (preg_match('/^[\w.\- ]+$/u', $fname) ? $fname : 'ebird-' . $from) . '_sounds.zip"');
	header('Content-Length: ' . filesize($tmp));
	readfile($tmp);
	@unlink($tmp);
	die();
}
// the periodic e-mail: on/off, daily or weekly, day, time, its filter and the report's name
if (isset($_GET['save'])) {
	ensure_authenticated(t('str_you_must_change_the_settings_help'));
	if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); die(); }
	$name = trim(str_replace(array('"', "\r", "\n", '$', '\\', '`'), '', (string)($_GET['name'] ?? '')));
	header('Content-Type: text/plain');
	echo conf_set_many(array('EBIRD_REPORT_ENABLED' => !empty($_GET['on']) ? '1' : '0', 'EBIRD_REPORT_FREQ' => ($_GET['freq'] ?? '') === 'daily' ? 'daily' : 'weekly',
		'EBIRD_REPORT_DAY' => max(1, min(7, intval($_GET['day'] ?? 1))),
		'EBIRD_REPORT_TIME' => (string)($_GET['time'] ?? ''), 'EBIRD_REPORT_SPECIES' => $req_mode($_GET['mode'] ?? ''),
		'EBIRD_REPORT_MIN_CONF' => (string)$req_min($_GET['min'] ?? ''), 'EBIRD_REPORT_NAME' => $name), $err) ? 'OK' : t('str_not_saved_lc') . ': ' . $err;
	die();
}
// "Set as default" (owner 2026-10-27, as on the Latest page): the filter and the minimum confidence the page opens with
if (isset($_GET['pagedefault'])) {
	ensure_authenticated(t('str_you_must_change_the_settings_help'));
	if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); die(); }
	header('Content-Type: text/plain');
	echo conf_set_many(array('EBIRD_PAGE_MODE' => $req_mode($_GET['mode'] ?? ''), 'EBIRD_PAGE_MIN_CONF' => (string)$req_min($_GET['min'] ?? '')), $err) ? 'OK' : t('str_not_saved_lc');
	die();
}
// "Send by e-mail": the report on screen, with its filter
if (isset($_GET['send'])) {
	ensure_authenticated(t('str_you_must_change_the_settings_help'));
	$from = $_GET['from'] ?? ''; $to = $_GET['to'] ?? $from;
	header('Content-Type: text/plain');
	if (!$valid_date($from) || !$valid_date($to)) { echo 'bad dates'; die(); }
	$loc_name = $req_name($_POST['place'] ?? '');
	if (isset($_POST['place'])) $keep_name($loc_name);
	echo trim(eb_run(array('send', $from, $to, $req_mode($_GET['mode'] ?? ''), (string)$req_min($_GET['min'] ?? ''), $req_skip($_POST['skip'] ?? $_GET['skip'] ?? ''), $loc_name))) ?: t('str_no_answer_lc');
	die();
}

$wd = array(1 => t('str_monday'), 2 => t('str_tuesday'), 3 => t('str_wednesday'), 4 => t('str_thursday'), 5 => t('str_friday'), 6 => t('str_saturday'), 7 => t('str_sunday'));
// the page's filter, in the address (&period= &date= &mode= &min=); it starts from the page's defaults
$period = in_array($_GET['period'] ?? '', array('day', 'week', 'month'), true) ? $_GET['period'] : 'day';  // by day unless asked (owner 2026-11-05)
$s_mode = $req_mode(trim((string)($config['EBIRD_REPORT_SPECIES'] ?? ''), "\"' "));
$s_min = $req_min(trim((string)($config['EBIRD_REPORT_MIN_CONF'] ?? ''), "\"' "));
$p_mode = $req_mode(trim((string)($config['EBIRD_PAGE_MODE'] ?? ''), "\"' "));
$p_min = $req_min(trim((string)($config['EBIRD_PAGE_MIN_CONF'] ?? ''), "\"' "));
$mode = isset($_GET['mode']) ? $req_mode($_GET['mode']) : $p_mode;
$min = isset($_GET['min']) ? $req_min($_GET['min']) : $p_min;
$any = $_GET['date'] ?? date('Y-m-d');
if (!$valid_date($any)) $any = date('Y-m-d');
$t0 = strtotime($any);
if ($period === 'day') {
	$first = $last = date('Y-m-d', $t0);
	$title = $wd[intval(date('N', $t0))] . ', ' . date('d/m/Y', $t0);
	$prev = date('Y-m-d', strtotime($first . ' -1 day')); $next = date('Y-m-d', strtotime($first . ' +1 day'));
} elseif ($period === 'month') {
	$first = date('Y-m-01', $t0); $last = date('Y-m-t', $t0);
	$title = t(month_key($t0)) . ' ' . date('Y', $t0);
	$prev = date('Y-m-d', strtotime($first . ' -1 month')); $next = date('Y-m-d', strtotime($first . ' +1 month'));
} else {
	$first = date('Y-m-d', strtotime(date('Y-m-d', $t0) . ' -' . (intval(date('N', $t0)) - 1) . ' days'));
	$last = date('Y-m-d', strtotime($first . ' +6 days'));
	$title = t('str_week_n', array('n' => date('W', strtotime($first)))) . ' · ' . date('d/m', strtotime($first)) . ' – ' . date('d/m/Y', strtotime($last));
	$prev = date('Y-m-d', strtotime($first . ' -7 days')); $next = date('Y-m-d', strtotime($first . ' +7 days'));
}
$data = json_decode(eb_run(array('days', $first, $last, $mode, (string)$min)), true);
$days = $data['days'] ?? array();
$rows = $data['rows'] ?? array();
$places = $data['places'] ?? array();
$q = '&amp;mode=' . $mode . '&amp;min=' . $min;
$nav = function ($date) use ($period, $q) { return 'views.php?view=eBird&amp;period=' . $period . '&amp;date=' . $date . $q; };
$many = count($days) > 1;
$on = trim((string)($config['EBIRD_REPORT_ENABLED'] ?? '0'), "\"' ") === '1';
$rname = trim((string)($config['EBIRD_REPORT_NAME'] ?? ''), "\"' ") ?: '{start}_{end}_BirdSuite_eBird_{id}_{location}';
$rtime = preg_match('/^\d{2}:\d{2}$/', trim((string)($config['EBIRD_REPORT_TIME'] ?? ''), "\"' ")) ? trim($config['EBIRD_REPORT_TIME'], "\"' ") : '08:00';
$modes = array('all' => t('str_all'), 'confirmed' => t('str_confirmed'), 'minconf' => t('str_minimum_confidence'));
$pct = function ($v) { return rtrim(rtrim(sprintf('%.2f', $v), '0'), '.'); };
$rday = max(1, min(7, intval($config['EBIRD_REPORT_DAY'] ?? 1)));
$rfreq = trim((string)($config['EBIRD_REPORT_FREQ'] ?? 'weekly'), "\"' ") === 'daily' ? 'daily' : 'weekly';
// the minimum confidence as a picklist, 0.10 to 0.95 (the value in use stays in it)
$conf_opts = function ($cur) use ($pct) {
	$vals = array(); for ($v = 10; $v <= 95; $v += 5) $vals[] = $v / 100;
	if (!in_array(round($cur, 2), array_map(function ($x) { return round($x, 2); }, $vals))) { $vals[] = $cur; sort($vals); }
	$o = ''; foreach ($vals as $v) $o .= '<option value="' . $pct($v) . '"' . (abs($v - $cur) < 1e-6 ? ' selected' : '') . '>' . fmt_setting($pct($v)) . '</option>';  // the value keeps the point, the label the page's format
	return $o;
};
?>
<style>
/* the layout of By Hour / By Week (owner 2026-10-27: the same left edge, period control and panels) */
.ebp { max-width: 1200px; margin: 0 auto; padding: 0 12px; text-align: left; }
.ebp .ebrow { display: flex; align-items: center; gap: 8px 18px; flex-wrap: wrap; margin: 6px 0 10px; font-size: 13px; }
.ebp .ebrow.ebplacegrid { display: grid; grid-template-columns: max-content minmax(0, max-content); justify-content: start; gap: 8px 10px; }
.ebp .ebplacectl { display: inline-flex; flex-wrap: wrap; align-items: center; gap: 8px; min-width: 0; }
.ebp .ebplacectl input[type=text] { max-width: 100%; box-sizing: border-box; }
@media screen and (max-width: 600px) { .ebp .ebrow.ebplacegrid { grid-template-columns: minmax(0, 1fr); } }
.ebp .dashperiod { width: auto; font-size: 13px; padding: 3px 6px; }
.ebp .panel { background: rgba(255,255,255,.65); border-radius: 12px; padding: 10px 14px; margin-bottom: 16px; box-shadow: 0 1px 3px rgba(0,0,0,.08); overflow-x: auto; }
.ebp .panel h3 { margin: 0 0 6px; font-size: 1.05em; }
.ebp table.stdtable td.c, .ebp table.stdtable th.c { text-align: center; }
.ebp .note { font-size: 12px; color: #555; }
.ebp .ebtable { overflow-x: auto; }
.ebp #ebsched.off { opacity: .6; }  /* dimmed while off, still readable (WCAG 1.4.3, release run 4) */
</style>
<div class="ebp">
	<?php // two sections (owner 2026-10-27): the report on screen, then the weekly schedule ?>
	<div class="panel"><h3><?php echo t('str_report'); ?></h3>
	<div class="ebrow">
		<span class="periodpick"><a class="stdbtn pnav" href="<?php echo $nav($prev); ?>" title="<?php echo t('str_the_period_before'); ?>">&#9664;</a>
			<select id="ebperiod" class="dashperiod" onchange="ebView(true)" aria-label="<?php echo t('str_period'); ?>">
				<option value="day"<?php echo $period === 'day' ? ' selected' : ''; ?>><?php echo t('str_by_day'); ?></option>
				<option value="week"<?php echo $period === 'week' ? ' selected' : ''; ?>><?php echo t('str_by_week_lc'); ?></option>
				<option value="month"<?php echo $period === 'month' ? ' selected' : ''; ?>><?php echo t('str_by_month'); ?></option></select>
			<a class="stdbtn pnav" href="<?php echo $nav($next); ?>" title="<?php echo t('str_the_period_after'); ?>">&#9654;</a></span>
		<b><?php echo $h($title); ?></b>
	</div>
	<div class="ebrow">
		<select id="ebmode" class="dashperiod" onchange="ebView()" title="<?php echo t('str_species_in_the_table_the_ebird_help'); ?>">
			<?php foreach ($modes as $k => $v) echo '<option value="' . $k . '"' . ($k === $mode ? ' selected' : '') . '>' . $h($v) . '</option>'; ?></select>
		<label><?php echo t('str_minimum_confidence_colon'); ?> <select id="ebmin" class="dashperiod" onchange="ebView()"<?php echo $mode === 'minconf' ? '' : ' disabled'; ?>><?php echo $conf_opts($min); ?></select></label>
		<?php if ($mode !== $p_mode || abs($min - $p_min) > 1e-6) { ?><button type="button" class="setdefault" onclick="ebPageDefault(this)" title="<?php echo t('str_open_the_ebird_page_with_this_help'); ?>"><?php echo t('str_set_as_default'); ?></button><?php } ?>
	</div>
	<div class="ebtable">
	<table class="stdtable" data-nosort>
		<tr><th class="c"><input type="checkbox" id="eball" checked onchange="document.querySelectorAll('.ebsel').forEach(function (c) { c.checked = this.checked; }, this)" title="<?php echo t('str_in_the_ebird_file_and_the_e_help'); ?>"></th><th class="nm"><select class="namemode" title="<?php echo t('str_names_shown'); ?>"><option value="com"><?php echo t('str_common_name'); ?></option><option value="sci"><?php echo t('str_scientific_name'); ?></option><option value="en"><?php echo t('str_english_name'); ?></option></select></th>
		<?php foreach ($days as $d) {
			echo '<th class="c">' . ($period === 'month' ? date('d', strtotime($d)) : $h($wd[intval(date('N', strtotime($d)))]) . '<br>' . date('d/m', strtotime($d))) . '</th>';
		} ?><?php if ($many) { ?><th class="c"><?php echo t('str_total'); ?></th><?php } ?><th class="c"><?php echo t('str_lowest_confidence'); ?></th></tr>
		<?php if (!$rows) { ?><tr><td colspan="<?php echo count($days) + 4; ?>"><?php echo t('str_no_species_in_this_period_with_help'); ?></td></tr><?php }
		foreach ($rows as $r) {
			echo '<tr><td class="c"><input type="checkbox" class="ebsel" checked data-sci="' . $h($r['sci']) . '" aria-label="' . $h($r['com']) . '"></td><td class="nm"><a href="views.php?view=Species&amp;sci=' . rawurlencode($r['sci']) . '" title="' . $h($r['sci']) . '" data-com="' . $h($r['com']) . '" data-sci="' . $h($r['sci']) . '" data-en="' . $h($r['en']) . '">' . $h($r['com']) . '</a></td>';
			$tot = 0;
			foreach ($days as $d) { $n = intval($r['days'][$d] ?? 0); $tot += $n; echo '<td class="c">' . ($n ? fmt_num($n) : '') . '</td>'; }
			echo ($many ? '<td class="c"><b>' . fmt_num($tot) . '</b></td>' : '') . '<td class="c">' . round(100 * floatval($r['low'] ?? 0)) . '%</td></tr>';
		} ?>
	</table>
	</div>
	<p class="note"><?php echo t('str_numbers_detections_the_e_mail_help'); ?><br>
		<?php echo t('str_an_unticked_species_stays_out_help'); ?></p>
	<?php // one location name for every checklist of the eBird file and its e-mail, shown and editable before they are
	// made, the last one used kept (owner 2026-11-05); empty = the name of each location (the placeholder shows it)
	// as the lists' Save as: the last name in the box (else the location's own name), above it a picklist of the names
	// used before and the locations of the period, or a new name typed in the box
	$own_names = array_map(function ($p) { return $p['name']; }, $places);
	$saved_place = trim((string)($config['EBIRD_LOCATION_NAME'] ?? ''), "\"' ") ?: ($own_names[0] ?? '');
	$pick_names = array_values(array_unique(array_merge($recent_names(), $own_names)));
	// the line layout of the list pages (owner 2026-11-05): label | controls, and below the picklist, in the controls
	// column, Delete a saved name with the page's own button look
	if ($places) { ?><div class="ebrow ebplacegrid"><label for="<?php echo $pick_names ? "ebplacepick" : "ebplace"; ?>"><?php echo t('str_location_in_the_ebird_file'); ?></label>
		<span class="ebplacectl"><?php if ($pick_names) { ?><select id="ebplacepick" class="dashperiod" autocomplete="off" aria-label="<?php echo t('str_location_in_the_ebird_file'); ?>"><option value=""><?php echo t('str_new_name_in_the_box'); ?></option><?php
			foreach ($pick_names as $n) echo '<option' . ($n === $saved_place ? ' selected' : '') . '>' . $h($n) . '</option>'; ?></select><?php } ?>
		<input type="text" id="ebplace" maxlength="100" size="36" autocomplete="off" value="<?php echo $h($saved_place); ?>" aria-label="<?php echo t('str_location_in_the_ebird_file'); ?>" title="<?php echo t('str_location_name_of_the_ebird_help'); ?>"></span>
		<?php if ($recent_names()) { ?><span></span><span class="ebplacectl"><button type="button" class="stdbtn" onclick="ebDeleteName()"><?php echo t('str_delete_a_saved_name_dots'); ?></button></span><?php } ?></div>
	<?php } ?>
	<p class="ebrow">
		<button type="button" class="stdbtn" onclick="ebSend(this)"><?php echo t('str_send_by_e_mail'); ?></button>
		<a class="stdbtn" href="scripts/ebird_page.php?csv=1&amp;from=<?php echo $first; ?>&amp;to=<?php echo $last . $q; ?>" onclick="this.href = this.href.split('&skip=')[0] + '&skip=' + encodeURIComponent(ebSkip()) + '&place=' + encodeURIComponent(ebPlace())" title="<?php echo t('str_to_import_the_ebird_file_help'); ?>">&#11015; <?php echo $many ? t('str_ebird_files_one_per_day_zip') : t('str_ebird_file'); ?></a>
		<a class="stdbtn" href="scripts/ebird_page.php?sounds=1&amp;from=<?php echo $first; ?>&amp;to=<?php echo $last . $q; ?>" onclick="return ebSounds(this)" title="<?php echo t('str_the_best_sounds_confirmed_by_help'); ?>">&#11015; <?php echo t('str_sounds_zip'); ?></a>
		<span id="ebsent" style="font-weight:600"></span></p>
	<p class="note"><?php echo t('str_to_import_the_ebird_file_help'); ?><?php if ($many) { ?><br><?php echo t('str_one_ebird_file_per_day_help'); ?><?php } ?></p>
	</div>

	<div class="panel"><h3><?php echo t('str_periodic_e_mail'); ?></h3>
		<div class="ebrow"><label><?php echo t('str_active'); ?> <input type="checkbox" id="ebon" <?php echo $on ? 'checked' : ''; ?> onchange="ebActive()"></label></div>
		<?php // greyed while the schedule is off (owner 2026-10-27) ?>
		<div id="ebsched"<?php echo $on ? '' : ' class="off"'; ?>>
			<div class="ebrow">
				<select id="ebsmode" class="dashperiod" aria-label="<?php echo htmlspecialchars(t('str_species'), ENT_QUOTES); ?>" onchange="document.getElementById('ebsmin').disabled = this.value !== 'minconf'">
					<?php foreach ($modes as $k => $v) echo '<option value="' . $k . '"' . ($k === $s_mode ? ' selected' : '') . '>' . $h($v) . '</option>'; ?></select>
				<label><?php echo t('str_minimum_confidence_colon'); ?> <select id="ebsmin" class="dashperiod"<?php echo $s_mode === 'minconf' ? '' : ' disabled'; ?>><?php echo $conf_opts($s_min); ?></select></label>
			</div>
			<div class="ebrow">
				<label><?php echo t('str_frequency'); ?> <select id="ebfreq" class="dashperiod" onchange="ebActive()">
					<option value="daily"<?php echo $rfreq === 'daily' ? ' selected' : ''; ?>><?php echo t('str_daily_previous_day'); ?></option>
					<option value="weekly"<?php echo $rfreq === 'weekly' ? ' selected' : ''; ?>><?php echo t('str_weekly_last_seven_days'); ?></option></select></label>
				<label id="ebdaylbl"><?php echo t('str_day'); ?> <select id="ebday" class="dashperiod"><?php foreach ($wd as $k => $v) echo '<option value="' . $k . '"' . ($k === $rday ? ' selected' : '') . '>' . $h($v) . '</option>'; ?></select></label>
				<label><?php echo t('str_time_colon'); ?> <input type="time" id="ebtime" value="<?php echo $h($rtime); ?>"></label>
			</div>
			<div><?php echo t('str_the_e_mail_subject_and_the_file_help'); ?></div>
			<div class="ebrow"><input type="text" id="ebname" maxlength="100" size="44" value="<?php echo $h($rname); ?>" aria-label="<?php echo t('str_the_e_mail_subject_and_the_file_help'); ?>"></div>
			<small><?php echo t('str_fields_and_yyyy_mm_dd_any_date_help'); ?></small>
		</div>
		<div class="ebrow"><button type="button" class="stdbtn" onclick="ebSave()"><?php echo t('str_save'); ?></button> <span id="ebmsg" style="font-weight:600"></span></div>
	</div>
</div>
<script>
// a new period opens on today (today, this week, this month; owner 2026-10-27); the filters keep the period shown
function ebView(today) {
	location.href = 'views.php?view=eBird&period=' + document.getElementById('ebperiod').value + (today ? '' : '&date=<?php echo $first; ?>') + '&mode=' + document.getElementById('ebmode').value
		+ '&min=' + encodeURIComponent(document.getElementById('ebmin').value);
}
function ebPageDefault(btn) {
	var x = new XMLHttpRequest();
	x.onload = function () { if (this.status === 200 && this.responseText.trim() === 'OK') btn.remove(); else document.getElementById('ebsent').textContent = this.status === 401 ? t('str_log_in_first') : t('str_not_saved_lc'); };
	x.open('POST', 'scripts/ebird_page.php?pagedefault=1&mode=<?php echo $mode; ?>&min=<?php echo $min; ?>');
	x.send();
}
function ebActive() {
	var on = document.getElementById('ebon').checked, box = document.getElementById('ebsched');
	box.classList.toggle('off', !on);
	box.querySelectorAll('select, input').forEach(function (e) { e.disabled = !on || (e.id === 'ebsmin' && document.getElementById('ebsmode').value !== 'minconf'); });
	// the day only for a weekly e-mail
	document.getElementById('ebdaylbl').style.display = document.getElementById('ebfreq').value === 'weekly' ? '' : 'none';
}
document.addEventListener('DOMContentLoaded', ebActive);
function ebSave() {
	var m = document.getElementById('ebmsg'), x = new XMLHttpRequest();
	m.textContent = t('str_saving_lc_ellipsis');
	x.onload = function () { m.textContent = this.status === 200 && this.responseText.trim() === 'OK' ? t('str_saved_lc') : (this.status === 401 ? t('str_log_in_first') : t('str_not_saved_lc')); };
	x.open('POST', 'scripts/ebird_page.php?save=1&on=' + (document.getElementById('ebon').checked ? 1 : 0) + '&freq=' + document.getElementById('ebfreq').value + '&day=' + document.getElementById('ebday').value + '&time=' + encodeURIComponent(document.getElementById('ebtime').value)
		+ '&mode=' + document.getElementById('ebsmode').value + '&min=' + encodeURIComponent(document.getElementById('ebsmin').value)
		+ '&name=' + encodeURIComponent(document.getElementById('ebname').value));
	x.send();
}
// how many confirmed sounds of each species go in the zip: asked each time, 1 to 20 (owner 2026-10-27)
function ebSounds(a) {
	bsPrompt(t('str_how_many_sounds_confirmed_by_help'), '5').then(function (n) {
		if (n === null) return;
		n = parseInt(n, 10);
		if (!(n >= 1 && n <= 20)) { bsNotice(t('str_a_number_from_1_to_20')); return; }
		location.href = a.href.split('&skip=')[0].split('&n=')[0] + '&n=' + n + '&skip=' + encodeURIComponent(ebSkip());
	});
	return false;
}
// the species unticked in the table, joined by |
function ebSkip() {
	return Array.prototype.filter.call(document.querySelectorAll('.ebsel'), function (c) { return !c.checked; }).map(function (c) { return c.dataset.sci; }).join('|');
}
function ebSend(btn) {
	var m = document.getElementById('ebsent'), x = new XMLHttpRequest();
	btn.disabled = true; m.textContent = t('str_sending_lc_ellipsis');
	x.onload = function () { btn.disabled = false; var r = this.responseText.trim(); m.textContent = this.status === 401 ? t('str_log_in_first') : (r === 'OK' ? t('str_sent') : r); };
	x.onerror = function () { btn.disabled = false; m.textContent = t('str_not_sent'); };
	x.open('POST', 'scripts/ebird_page.php?send=1&from=<?php echo $first; ?>&to=<?php echo $last; ?>&mode=<?php echo $mode; ?>&min=<?php echo $min; ?>');
	x.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
	x.send('skip=' + encodeURIComponent(ebSkip()) + '&place=' + encodeURIComponent(ebPlace()));
}
// the location name typed for the eBird file ('' = each location's own name); the station keeps the last one used
// the name of the eBird file is the one in the box (owner 2026-11-05): the picklist opens on the last name used and the
// box holds it too, to be kept or completed; a name picked fills the box ("New name (in the box)" empties it), a name
// typed selects its option when there is one. A name used goes first in the picklist and selected, as the station keeps
// it, without reloading the page
// a saved name wrong or no longer wanted: picked, asked again, out of the picklist
var EB_NAMES = <?php echo json_encode($recent_names(), JSON_UNESCAPED_UNICODE); ?>;
function ebDeleteName() {
	bsPick(t('str_delete_which_saved_name'), EB_NAMES, t('str_delete')).then(function (n) {
		if (!n) return;
		bsAsk(t('str_delete_the_saved_name_q', {name: n}), true, t('str_delete')).then(function (ok) {
			if (!ok) return;
			var x = new XMLHttpRequest(), fd = new FormData();
			fd.append('name', n);
			x.onload = function () { if (this.status === 200 && this.responseText.trim() === 'OK') location.reload(); else bsNotice(this.status === 401 ? t('str_log_in_first') : t('str_not_saved_lc')); };
			x.open('POST', 'scripts/ebird_page.php?delname=1');
			x.send(fd);
		});
	});
}
function ebPlace() {
	var i = document.getElementById('ebplace'), pick = document.getElementById('ebplacepick'), v = i ? i.value.trim() : '';
	if (pick && v) {
		Array.prototype.slice.call(pick.options).forEach(function (o) { if (o.value === v) o.remove(); });
		var o = document.createElement('option'); o.textContent = v;
		pick.insertBefore(o, pick.options[1] || null);
		pick.value = v;
	}
	return v;
}
document.addEventListener('DOMContentLoaded', function () {
	var pick = document.getElementById('ebplacepick'), inp = document.getElementById('ebplace');
	if (!pick || !inp) return;
	// the last name used, also after the browser restored an older choice of the form
	var sel = Array.prototype.filter.call(pick.options, function (o) { return o.defaultSelected; })[0];
	pick.value = sel ? sel.value : '';
	inp.value = inp.defaultValue;
	pick.onchange = function () { inp.value = pick.value; inp.focus(); };
	inp.oninput = function () {
		var v = inp.value.trim();
		pick.value = Array.prototype.some.call(pick.options, function (o) { return o.value && o.value === v; }) ? v : '';
	};
});
</script>
<script src="static/name-mode.js?v=<?php echo @filemtime(__DIR__ . "/../homepage/static/name-mode.js"); ?>"></script>
