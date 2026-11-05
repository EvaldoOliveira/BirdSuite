<?php
/* Seasonality (owner 2026-10-09): when in the year each species is heard — every species detected at the station,
 * one row each, one cell per calendar week (KW1–KW53, the week belongs to the month of its Thursday), darker = more
 * detections. Every date recorded. A filter box narrows the rows; a click on a name opens the species page.
 * Opened as views.php?view=By%20Week. */
require_once __DIR__ . '/common.php';
$db = get_db();
$h = function ($s) { return htmlspecialchars((string)$s, ENT_QUOTES); };
$nr = not_rejected_sql();
// year: every year together (default) or one ISO year; a calendar week belongs to the year of its Thursday
$this_year = intval(date('o'));
// the years as the period of By Hour (owner 2026-10-22): All years until another choice, kept in the cookie week_period
// (this, last2, all or Y1:Y2); &yperiod=this|last2|all and &yfrom=&yto= of the request win over the cookie
$ymode = 'all';  // every year until another choice (owner 2026-10-22)
if (in_array($_GET['yperiod'] ?? '', array('this', 'last2', 'all'), true)) $ymode = $_GET['yperiod'];
elseif (isset($_GET['yfrom']) || isset($_GET['year'])) $ymode = 'range';
elseif (preg_match('/^(this|last2|all|\d{4}:\d{4})$/', $_COOKIE['week_period'] ?? '', $ym)) $ymode = strpos($ym[1], ':') ? 'range' : $ym[1];
$year = 0; $yfrom = $yto = 0;
if ($ymode === 'this') $yfrom = $yto = $this_year;
elseif ($ymode === 'last2') { $yfrom = $this_year - 1; $yto = $this_year; }
elseif ($ymode === 'range') {
  if (isset($_GET['yfrom']) || isset($_GET['year'])) {
    $yfrom = preg_match('/^\d{4}$/', $_GET['yfrom'] ?? $_GET['year'] ?? '') ? intval($_GET['yfrom'] ?? $_GET['year']) : 0;
    $yto = preg_match('/^\d{4}$/', $_GET['yto'] ?? '') ? intval($_GET['yto']) : $yfrom;
  } else { list($yfrom, $yto) = array_map('intval', explode(':', $_COOKIE['week_period'])); }
}
if ($yfrom && $yto < $yfrom) { $t = $yfrom; $yfrom = $yto; $yto = $t; }
if ($yfrom && $yfrom === $yto) $year = $yfrom; elseif ($yfrom) $year = 0;
$first_year = intval(date('o', strtotime((string)$db->querySingle('SELECT MIN(Date) FROM detections') ?: date('Y-m-d'))));
$by = array(); $tot = array(); $com = array();
// the cards (as By Hour): the previous range of the same length, for the change
$pspan = $yfrom ? $yto - $yfrom + 1 : 0; $p_n = 0; $p_sp = array();
// counts per species and day from the covering index, the days before today kept, the names looked up per species
// afterwards (common.php grouped_counts / species_com_names; 2026-10-23 test run: MAX(Com_Name) read every row, 4.9 s
// at ten times the station's data)
$first_seen = array(); // the first date of each species, for the New species card
foreach (grouped_counts(array('Sci_Name', 'Date')) as $g) {
  $r = array('Sci_Name' => $g[0], 'Date' => $g[1], 'n' => $g[2]);
  $t = strtotime($r['Date']);
  $iy = intval(date('o', $t));
  if (!isset($first_seen[$r['Sci_Name']]) || $r['Date'] < $first_seen[$r['Sci_Name']]) $first_seen[$r['Sci_Name']] = $r['Date'];
  if ($pspan && $iy >= $yfrom - $pspan && $iy <= $yto - $pspan) { $p_n += $r['n']; $p_sp[$r['Sci_Name']] = 1; }
  if ($yfrom && ($iy < $yfrom || $iy > $yto)) continue;
  $k = intval(date('W', $t));
  $by[$r['Sci_Name']][$k] = ($by[$r['Sci_Name']][$k] ?? 0) + $r['n'];
  $tot[$r['Sci_Name']] = ($tot[$r['Sci_Name']] ?? 0) + $r['n'];
}
arsort($tot);
$com = species_com_names(array_keys($tot));
// CSV of the week table (owner 2026-10-22): the years of the page, every species
if (isset($_GET['csv'])) {
  $head = array(t('str_scientific_name'), t('str_common_name'), t('str_total'));
  for ($k = 1; $k <= 53; $k++) $head[] = 'KW' . $k;
  $rows = array();
  foreach ($tot as $s => $n) { $r = array($s, $com[$s], $n); for ($k = 1; $k <= 53; $k++) $r[] = $by[$s][$k] ?? 0; $rows[] = $r; }
  csv_out('by-week-' . ($yfrom ? $yfrom . ($yto !== $yfrom ? '_' . $yto : '') : 'all-years') . '.csv', $head, $rows);
}
// totals per week for the chart under the table (aligned with the week columns)
$wk_n = array_fill(1, 53, 0); $wk_sp = array_fill(1, 53, 0);
foreach ($by as $sci => $weeks) foreach ($weeks as $k => $v) { $wk_n[$k] += $v; $wk_sp[$k]++; }
$wk_nmax = max(1, max($wk_n)); $wk_spmax = max(1, max($wk_sp));
$max = 1; foreach ($by as $x) $max = max($max, max($x));
$cur = (!$yfrom || $yto === $this_year) ? intval(date('W')) : 0;
$yr = $yfrom ? $yto : $this_year;
$mon = array();
for ($k = 1; $k <= 53; $k++) {
  $thu = new DateTime(); $thu->setISODate($yr, $k, 4);
  $mon[$k] = intval($thu->format('Y')) === $yr ? intval($thu->format('n')) : ($k > 40 ? 13 : 0);
}
// cards: value, change vs the previous range, sparkline of the weeks (the same helpers as By Hour)
$sparkline = function ($vals) {
  $vals = array_values($vals); $w = 120; $hgt = 28; $mx = max(1, max($vals)); $n = count($vals);
  $pts = array();
  foreach ($vals as $i => $v) $pts[] = round($i * $w / max(1, $n - 1), 1) . ',' . round($hgt - 2 - ($hgt - 4) * $v / $mx, 1);
  return '<svg class="spark" viewBox="0 0 ' . $w . ' ' . $hgt . '" preserveAspectRatio="none"><polygon points="0,' . $hgt . ' ' . implode(' ', $pts) . ' ' . $w . ',' . $hgt . '" class="sa"/><polyline points="' . implode(' ', $pts) . '" class="sl"/></svg>';
};
$delta = function ($c, $p) use ($pspan) {
  if (!$pspan) return '<small class="dl">' . t('str_every_year_together') . '</small>';
  $label = $pspan === 1 ? t('str_vs_the_year_before') : t('str_vs_previous_years', array('n' => $pspan));
  if (!$p) return '<small class="dl">' . t('str_no_data', array('period' => $label)) . '</small>';
  $pct = round(100 * ($c - $p) / $p);
  return '<small class="dl ' . ($pct > 0 ? 'up' : ($pct < 0 ? 'down' : '')) . '">' . ($pct > 0 ? '▲ ' : ($pct < 0 ? '▼ ' : '')) . abs($pct) . '% ' . $label . '</small>';
};
$names = array(1 => t('str_jan'), t('str_feb'), t('str_mar'), t('str_apr'), t('str_may'), t('str_jun'), t('str_jul'), t('str_aug'), t('str_sep'), t('str_oct'), t('str_nov'), t('str_dec'));
?>
<style>
.sea .cards { display: grid; grid-template-columns: repeat(auto-fill, minmax(190px, 1fr)); gap: 12px; margin: 0 0 16px; max-width: 1040px; }
.sea .card { background: rgba(255,255,255,.65); border-radius: 12px; padding: 10px 14px 6px; box-shadow: 0 1px 3px rgba(0,0,0,.08); text-align: left; }
.sea .card small.t { color: #555; } .sea .card b { display: block; font-size: 1.7em; line-height: 1.2; }
.sea .dl { font-size: 11px; color: #444; } .sea .dl.up { color: #1b5e20; } .sea .dl.down { color: #c62828; }
.sea svg.spark { width: 100%; height: 28px; display: block; margin-top: 4px; }
.sea svg.spark .sl { fill: none; stroke: var(--accent,#2b5e22); stroke-width: 1.6; vector-effect: non-scaling-stroke; } .sea svg.spark .sa { fill: rgba(var(--accent-rgb,43,94,34),.15); }
.sea { max-width: 1300px; margin: 0 auto; padding: 0 12px; text-align: left; }
@media screen and (max-width: 800px) { .sea .card svg.spark { display: none; } .sea .cards { display: grid !important; grid-template-columns: 1fr 1fr; gap: 8px; } .sea .cards .card { margin: 0 !important; min-width: 0; } }
.sea #seatable td.nm a { display: block; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.sea .top:not(.fopen) .spfilter { display: none !important; }
.sea .top:not(.sopen) .hmsort > small, .sea .top:not(.sopen) .hmsort > button { display: none !important; }
.sea .top:not(.sopen):not(.fopen) .hmsort { display: none !important; }
.sea .top .right .hmsort { flex: 0 0 auto; display: inline-flex; align-items: center; gap: 4px; }
.sea .dashperiod { width: auto; font-size: 13px; padding: 3px 6px; } .sea .dashdates { font-size: 13px; opacity: .85; }
.sea .dashfilter { cursor: pointer; border-radius: 50%; padding: 3px; display: inline-flex; color: var(--accent,#2b5e22); }
.sea .top.fopen .dashfilter { background: var(--accent,#2b5e22); color: #fff; }
.sea .sortopen { border: 1px solid var(--accent,#2b5e22); background: #fff; color: var(--accent,#2b5e22); cursor: pointer; }
.sea .sortopen.on { background: var(--accent,#2b5e22); color: #fff; }
.sea .top { display: flex; justify-content: space-between; align-items: center; gap: 8px; flex-wrap: wrap; margin: 6px 0 10px; }
.sea .top input { padding: 5px 8px; font-size: 14px; }
/* title, then the period control and the year range on the left (as By Hour), sort + filter on the right */
.sea .top { justify-content: flex-start; gap: 8px 18px; } .sea .top > h2, .sea .top .periodnav, .sea .top .yrange { flex: 0 0 auto; } .sea .top > .right { flex: 1 1 auto; justify-content: flex-end; }
.sea .top .right { display: flex; justify-content: flex-end; align-items: center; gap: 10px; } .sea th.spcount { font-weight: normal; text-align: left; white-space: nowrap; } .sea .top .right input { width: 220px; max-width: 100%; }
.sea .wrap { overflow-x: auto; background: rgba(255,255,255,.65); border-radius: 12px; padding: 8px 10px; box-shadow: 0 1px 3px rgba(0,0,0,.08); }
.sea table { border-collapse: separate; border-spacing: 2px; font-size: 12px; }
.sea th, .sea td { padding: 0; }
.sea td.nm, .sea th.nm { text-align: left !important; white-space: nowrap; padding-right: 8px; position: sticky; left: 0; background: var(--panel,#e8f5ea); z-index: 1; }
.sea td.nm i { color: #555; font-size: 11px; }
.sea td.c { width: 14px; min-width: 14px; height: 16px; border-radius: 2px; background: #fff !important; border: none !important; }
.sea td.c.norec { background: #e9ecea !important; }
.sea td.c.now { outline: 1px solid #d97a00; }
.sea td.c[data-drill] { cursor: pointer; } .sea td.c[data-drill]:hover { box-shadow: inset 0 0 0 2px #d97a00; }
.sea th.m { text-align: left !important; font-size: 11px; font-weight: 600; border-left: 2px solid rgba(var(--accent-rgb,43,94,34),.45); padding-left: 2px; }
.sea th.k { font-size: 9px; font-weight: normal; color: #333; text-align: left !important; }
.sea tr.chart td { border: none !important; background: transparent !important; vertical-align: bottom; }
.sea tr.chart td.nm { background: var(--panel,#e8f5ea) !important; vertical-align: bottom; padding-top: 10px; }
.sea tr.chart td.b { height: 74px; padding-top: 10px; }
.sea .bar { width: 100%; background: rgba(var(--accent-rgb,43,94,34),.8); border-radius: 2px 2px 0 0; }
.sea .bar.now { background: #d97a00; } .sea .bar.sp { background: #1565c0; }
.sea td.t, .sea th.t { text-align: right !important; padding-left: 8px; padding-right: 8px; white-space: nowrap; }
/* the Count title may wrap between its words, never inside one (release run 5, I18N-17); the numbers stay on one line */
.sea th.t { white-space: normal; overflow-wrap: normal; word-break: normal; }
.sea .note { font-size: 12px; color: #222; margin-top: 6px; }
</style>
<div class="sea">
  <?php echo location_label(); // the location shown (owner 2026-10-21) ?>
  <?php // the cards as By Hour (owner 2026-10-21): Detections, Confirmed, Validated, New species, all in the period shown,
  // with the same footers as By Hour (the change against the previous range under Detections)
  // the ISO week-years of the table (week 1 starts on the Monday of the week with 4 January, week 52/53 ends the Sunday
  // before the next one), so the cards count the same days as the weeks shown
  $iso_start = function ($y) { $d = new DateTime(); $d->setISODate(intval($y), 1, 1); return $d->format('Y-m-d'); };
  $p_from = $yfrom ? $iso_start($yfrom) : '0000-01-01';
  $p_to = $yfrom ? date('Y-m-d', strtotime($iso_start(intval($yto) + 1) . ' -1 day')) : '9999-12-31';
  $conf_list = array_flip(array_map('trim', @file(BS_LISTS . '/confirmed_species_list.txt', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: array()));
  $n_conf = count(array_intersect_key($tot, $conf_list));
  $st = $db->prepare("SELECT COUNT(DISTINCT Sci_Name) FROM detections WHERE Date BETWEEN :f AND :t AND File_Name IN (SELECT File_Name FROM detection_reviews WHERE Verdict = 'yes')");
  $st->bindValue(':f', $p_from); $st->bindValue(':t', $p_to);
  $rv = $st->execute(); $n_valid = $rv ? intval($rv->fetchArray(SQLITE3_NUM)[0]) : 0;
  $n_new = 0; foreach ($tot as $sci => $x) if (($first_seen[$sci] ?? '') >= $p_from) $n_new++; ?>
  <div class="cards">
    <div class="card"><small class="t"><?php echo t('str_detections'); ?></small><b><?php echo fmt_num(array_sum($wk_n)); ?></b><?php echo $delta(array_sum($wk_n), $p_n) . $sparkline($wk_n); ?></div>
    <div class="card"><small class="t"><?php echo t('str_confirmed_species'); ?></small><b><?php echo $n_conf; ?></b><small class="dl"><?php echo t('str_confirmed_on_the_species_page'); ?></small></div>
    <div class="card"><small class="t"><?php echo t('str_validated_species'); ?></small><b><?php echo $n_valid; ?></b><small class="dl"><?php echo t('str_1_review_yes'); ?></small></div>
    <div class="card"><small class="t"><?php echo t('str_new_species'); ?></small><b><?php echo $n_new; ?></b><small class="dl"><?php echo t('str_first_detection_in_the_period'); ?></small></div>
  </div>
  <div class="top sortbar"<?php $cf_rf = get_config(); echo ' data-fphone="' . (($cf_rf['REPORTS_FILTERS_PHONE'] ?? 'closed') === 'open' ? 1 : 0) . '" data-fdesktop="' . (($cf_rf['REPORTS_FILTERS_DESKTOP'] ?? 'open') !== 'closed' ? 1 : 0) . '"'; // REPORTS_FILTERS_* (owner 2026-10-25) ?>><?php // filter line open by default (owner 2026-10-22), last state kept ?>
    <?php // the period control as By Hour, in years (owner 2026-10-22): ◀ [This year / Last 2 years / Year range / All years] ▶,
      // ascending; the arrows move by the span (▶ stops at this year); Year range shows the From / to years; always shown
      $yurl2 = function ($f, $t) { return 'views.php?view=By%20Week&amp;yfrom=' . $f . '&amp;yto=' . $t; };
      $span = $yfrom ? $yto - $yfrom + 1 : 0;
      $yis_this = $yfrom === $this_year && $yto === $this_year; $yis2 = $yfrom === $this_year - 1 && $yto === $this_year;
      $yis_range = $yfrom && !$yis_this && !$yis2; ?>
    <span class="periodpick">
      <?php if ($span) { ?><a class="stdbtn pnav" href="<?php echo $yurl2($yfrom - $span, $yto - $span); ?>" title="<?php echo t('str_the_period_before'); ?>">&#9664;</a><?php } ?>
      <select class="dashperiod" aria-label="<?php echo htmlspecialchars(t('str_period'), ENT_QUOTES); ?>" onchange="if (this.value === 'range') this.parentNode.querySelector('.prange').style.display = ''; else if (this.value) location.href = this.value">
        <option value="views.php?view=By%20Week&amp;yperiod=this"<?php echo $yis_this ? ' selected' : ''; ?>><?php echo t('str_this_year'); ?></option>
        <option value="views.php?view=By%20Week&amp;yperiod=last2"<?php echo $yis2 ? ' selected' : ''; ?>><?php echo t('str_last_2_years'); ?></option>
        <?php // another span (a year reached with the arrows, a range) shows in the closed picklist only (owner 2026-10-22)
          if ($yis_range) { ?><option value="" selected hidden disabled><?php echo $h($yfrom === $yto ? (string)$yfrom : $yfrom . ' - ' . $yto); ?></option><?php } ?>
        <option value="range"><?php echo t('str_year_range'); ?></option>
        <option value="views.php?view=By%20Week&amp;yperiod=all"<?php echo !$yfrom ? ' selected' : ''; ?>><?php echo t('str_all_years'); ?></option>
      </select>
      <?php if ($span) { if ($yfrom + $span <= $this_year) { ?><a class="stdbtn pnav" href="<?php echo $yurl2($yfrom + $span, min($this_year, $yto + $span)); ?>" title="<?php echo t('str_the_period_after'); ?>">&#9654;</a><?php } else { ?><span class="stdbtn pnav off">&#9654;</span><?php } } ?>
      <form class="yrange prange" method="GET" action="views.php"<?php echo isset($_GET['pr']) ? '' : ' style="display:none"'; ?>><input type="hidden" name="view" value="By Week"><input type="hidden" name="pr" value="1">
        <?php echo t('str_from'); ?> <select name="yfrom"><?php for ($y = $first_year; $y <= $this_year; $y++) echo '<option' . ($y === ($yfrom ?: $first_year) ? ' selected' : '') . '>' . $y . '</option>'; ?></select>
        <?php echo t('str_to_lc'); ?> <select name="yto"><?php for ($y = $first_year; $y <= $this_year; $y++) echo '<option' . ($y === ($yto ?: $this_year) ? ' selected' : '') . '>' . $y . '</option>'; ?></select>
        <button type="submit"><?php echo t('str_filter'); ?></button></form>
    </span>
    <script>try { document.cookie = 'week_period=' + <?php echo json_encode(!$yfrom ? 'all' : ($yis_this ? 'this' : ($yis2 ? 'last2' : $yfrom . ':' . $yto))); ?> + ';path=/;max-age=31536000;SameSite=Lax'; } catch (e) {}</script>
    <span class="right"><span class="dashfilter" role="button" tabindex="0" title="<?php echo t('str_filter_and_search_show_hide'); ?>"
      onclick="this.closest('.top').classList.toggle('fopen')" onkeydown="if (event.key === 'Enter' || event.key === ' ') { event.preventDefault(); this.click(); }"><svg viewBox="0 0 24 24" width="18" height="18" aria-label="<?php echo t('str_filter'); ?>" role="img"><path fill="currentColor" d="M3 4h18l-7 8.5V19l-4 2v-8.5z"/></svg></span><?php echo sort_toggle() . csv_icon('scripts/seasonality.php?csv=1' . ($yfrom ? '&yfrom=' . $yfrom . '&yto=' . $yto : '')); ?><span class="hmsort" data-table="seatable" data-store="byweek" data-default="n" data-remember="0" title="<?php echo t('str_opens_on_count_most_first_a_help'); ?>"><small><?php echo t('str_sort_colon'); ?></small><button type="button" data-sort="n"><?php echo t('str_count'); ?></button><button type="button" data-sort="tax" title="<?php echo t('str_field_guide_order_ebird_help'); ?>"><?php echo t('str_taxonomy'); ?></button><button type="button" data-sort="az" title="<?php echo t('str_alphabetical_by_the_names_shown'); ?>">A-Z</button></span>
      <input type="search" class="spfilter" data-table="seatable" placeholder="<?php echo t('str_filter_species'); ?>"></span>
  </div>
  <?php if (!$tot) { echo '<div class="wrap"><p>' . ($year ? t('str_no_detections_in', array('year' => $year)) : t('str_no_detections_in_any_year')) . '</p></div>'; } else { ?>
  <div class="wrap"><table id="seatable" class="hmsorted">
    <tr><th class="nm spcount"><?php echo t('str_n_species', array('n' => count($tot))); ?></th><?php
      for ($k = 1; $k <= 53; ) {
        $span = 1;
        while ($k + $span <= 53 && $mon[$k + $span] === $mon[$k]) $span++;
        echo '<th class="m" colspan="' . $span . '">' . ($names[$mon[$k]] ?? '') . '</th>';
        $k += $span;
      } ?><th></th></tr>
    <tr><th class="nm"><select class="namemode" title="<?php echo t('str_names_shown'); ?>"><option value="com"><?php echo t('str_common_name'); ?></option><option value="sci"><?php echo t('str_scientific_name'); ?></option><option value="en"><?php echo t('str_english_name'); ?></option></select></th><?php for ($k = 1; $k <= 53; $k++) echo '<th class="k">' . ($k % 4 === 1 ? $k : '') . '</th>'; ?><th class="t"><?php echo t('str_count'); ?></th></tr>
    <?php foreach ($tot as $sci => $n) {
      echo '<tr data-n="' . intval($n) . '" data-tax="' . taxon_order($sci) . '" data-q="' . $h(mb_strtolower($com[$sci] . ' ' . $sci . ' ' . get_english_name($sci))) . '"><td class="nm"><a href="views.php?view=Species&amp;sci=' . rawurlencode($sci) . '" title="' . $h($sci) . '" data-com="' . $h($com[$sci]) . '" data-sci="' . $h($sci) . '" data-en="' . $h(get_english_name($sci)) . '">' . $h($com[$sci]) . '</a></td>';
      for ($k = 1; $k <= 53; $k++) {
        $v = $by[$sci][$k] ?? 0;
        $a = $v ? 0.15 + 0.85 * log(1 + $v) / log(1 + $max) : 0;
        // a week the station recorded nothing at all stays light grey; a recorded week without this species is white
        $mon_d = (new DateTime())->setISODate($yr, $k, 1)->format('Y-m-d');
        $dl = $v ? ' data-drill="views.php?view=By%20Hour&amp;from=' . $mon_d . '&amp;to=' . date('Y-m-d', strtotime($mon_d . ' +6 days')) . '&amp;sci=' . rawurlencode($sci) . '"' : '';
        echo '<td' . $dl . ' class="c' . ($wk_n[$k] ? '' : ' norec') . ($k === $cur ? ' now' : '') . '" title="' . $h($com[$sci] . ', KW' . $k . ': ' . $v) . '"' . ($v ? ' style="background:rgba(var(--accent-rgb,43,94,34),' . round($a, 2) . ') !important"' : '') . '></td>';
      }
      echo '<td class="t">' . fmt_num($n) . '</td></tr>'; // the count on the right of the weeks (owner 2026-10-21, as By Hour)
    } ?>
    <tr class="chart"><td class="nm"><b><?php echo t('str_detections'); ?></b><br><small><?php echo t('str_max', array('n' => fmt_num($wk_nmax))); ?></small></td><?php
      for ($k = 1; $k <= 53; $k++) {
        echo '<td class="b" title="' . t('str_kw_detections', array('k' => $k, 'n' => fmt_num($wk_n[$k]))) . '"><div class="bar' . ($k === $cur ? ' now' : '') . '" style="height:' . ($wk_n[$k] ? max(2, round(70 * $wk_n[$k] / $wk_nmax)) : 0) . 'px"></div></td>';
      } ?><td class="t"><?php echo fmt_num(array_sum($wk_n)); ?></td></tr>
    <tr class="chart"><td class="nm"><b><?php echo t('str_species'); ?></b><br><small><?php echo t('str_max', array('n' => $wk_spmax)); ?></small></td><?php
      for ($k = 1; $k <= 53; $k++) {
        echo '<td class="b" title="' . t('str_kw_species', array('k' => $k, 'n' => $wk_sp[$k])) . '"><div class="bar sp" style="height:' . ($wk_sp[$k] ? max(2, round(50 * $wk_sp[$k] / $wk_spmax)) : 0) . 'px"></div></td>';
      } ?><td class="t"><?php echo count($tot); ?></td></tr>
  </table></div>
  <?php } ?>
  <?php // owner's wording (2026-10-21), one sentence per line ?>
  <div class="note"><?php echo t('str_darker_more_detections'); ?><br><?php echo t('str_white_recorded_but_not_heard'); ?><br><?php echo t('str_light_grey_a_week_without_any_help'); ?><br><?php echo t('str_this_week_highlighted_in_orange'); ?></div>
</div>
<script src="static/species-sort.js?v=<?php echo @filemtime(__DIR__ . "/../homepage/static/species-sort.js"); ?>"></script>
<script src="static/name-mode.js?v=<?php echo @filemtime(__DIR__ . "/../homepage/static/name-mode.js"); ?>"></script>
<script>
// the species names 30 % narrower than the longest one (owner 2026-10-21, as By Hour); the full name on mouse over
(function () {
  var t = document.getElementById('seatable');
  if (!t) return;
  var w = 0;
  t.querySelectorAll('td.nm a').forEach(function (a) { w = Math.max(w, a.scrollWidth); a.title = a.textContent; });
  if (w) t.querySelectorAll('td.nm a').forEach(function (a) { a.style.maxWidth = Math.round(w * 0.7) + 'px'; });
})();
</script>
<script>
// a cell with detections opens the dashboard for that species and that week; the name opens the species page
document.querySelectorAll('td.c[data-drill]').forEach(function (td) {
  td.addEventListener('click', function () { location.href = td.dataset.drill.replace(/&amp;/g, '&'); });
});
</script>
