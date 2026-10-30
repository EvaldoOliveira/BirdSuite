<?php
/* Seasonality (owner 2026-10-09): when in the year each species is heard — every species detected at the station,
 * one row each, one cell per calendar week (KW1–KW53, the week belongs to the month of its Thursday), darker = more
 * detections. Every date recorded. A filter box narrows the rows; a click on a name opens the species page.
 * Opened as views.php?view=Seasonality. */
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
  $head = array(t('Scientific name'), t('Common name'), t('Total'));
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
  if (!$pspan) return '<small class="dl">' . t('every year together') . '</small>';
  $label = $pspan === 1 ? t('vs the year before') : t('vs previous {n} years', array('n' => $pspan));
  if (!$p) return '<small class="dl">' . t('no data {period}', array('period' => $label)) . '</small>';
  $pct = round(100 * ($c - $p) / $p);
  return '<small class="dl ' . ($pct > 0 ? 'up' : ($pct < 0 ? 'down' : '')) . '">' . ($pct > 0 ? '▲ ' : ($pct < 0 ? '▼ ' : '')) . abs($pct) . '% ' . $label . '</small>';
};
$names = array(1 => t('Jan'), t('Feb'), t('Mar'), t('Apr'), t('May'), t('Jun'), t('Jul'), t('Aug'), t('Sep'), t('Oct'), t('Nov'), t('Dec'));
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
.sea .note { font-size: 12px; color: #222; margin-top: 6px; }
</style>
<div class="sea">
  <?php echo location_label(); // the location shown (owner 2026-10-21) ?>
  <?php // the cards as By Hour (owner 2026-10-21): Detections, Confirmed, Validated, New species, all in the period shown,
  // with the same footers as By Hour (the change against the previous range under Detections)
  $p_from = $yfrom ? $yfrom . '-01-01' : '0000-01-01'; $p_to = $yfrom ? $yto . '-12-31' : '9999-12-31';
  $conf_list = array_flip(array_map('trim', @file(BS_LISTS . '/confirmed_species_list.txt', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: array()));
  $n_conf = count(array_intersect_key($tot, $conf_list));
  $n_valid = 0;
  if ($db->querySingle("SELECT 1 FROM sqlite_master WHERE type='table' AND name='detection_reviews'")) {
    $st = $db->prepare("SELECT COUNT(DISTINCT Sci_Name) FROM detections WHERE Date BETWEEN :f AND :t AND File_Name IN (SELECT File_Name FROM detection_reviews WHERE Verdict = 'yes')");
    $st->bindValue(':f', $p_from); $st->bindValue(':t', $p_to);
    $rv = $st->execute(); $n_valid = $rv ? intval($rv->fetchArray(SQLITE3_NUM)[0]) : 0;
  }
  $n_new = 0; foreach ($tot as $sci => $x) if (($first_seen[$sci] ?? '') >= $p_from) $n_new++; ?>
  <div class="cards">
    <div class="card"><small class="t"><?php echo t('Detections'); ?></small><b><?php echo fmt_num(array_sum($wk_n)); ?></b><?php echo $delta(array_sum($wk_n), $p_n) . $sparkline($wk_n); ?></div>
    <div class="card"><small class="t"><?php echo t('Confirmed species'); ?></small><b><?php echo $n_conf; ?></b><small class="dl"><?php echo t('Confirmed on the species page'); ?></small></div>
    <div class="card"><small class="t"><?php echo t('Validated species'); ?></small><b><?php echo $n_valid; ?></b><small class="dl"><?php echo t('1 review = Yes'); ?></small></div>
    <div class="card"><small class="t"><?php echo t('New species'); ?></small><b><?php echo $n_new; ?></b><small class="dl"><?php echo t('First detection in the period'); ?></small></div>
  </div>
  <div class="top sortbar"<?php $cf_rf = get_config(); echo ' data-fphone="' . (($cf_rf['REPORTS_FILTERS_PHONE'] ?? 'closed') === 'open' ? 1 : 0) . '" data-fdesktop="' . (($cf_rf['REPORTS_FILTERS_DESKTOP'] ?? 'open') !== 'closed' ? 1 : 0) . '"'; // REPORTS_FILTERS_* (owner 2026-10-25) ?>><?php // filter line open by default (owner 2026-10-22), last state kept ?>
    <?php // the period control as By Hour, in years (owner 2026-10-22): ◀ [This year / Last 2 years / Year range / All years] ▶,
      // ascending; the arrows move by the span (▶ stops at this year); Year range shows the From / to years; always shown
      $yurl2 = function ($f, $t) { return 'views.php?view=Seasonality&amp;yfrom=' . $f . '&amp;yto=' . $t; };
      $span = $yfrom ? $yto - $yfrom + 1 : 0;
      $yis_this = $yfrom === $this_year && $yto === $this_year; $yis2 = $yfrom === $this_year - 1 && $yto === $this_year;
      $yis_range = $yfrom && !$yis_this && !$yis2; ?>
    <span class="periodpick">
      <?php if ($span) { ?><a class="stdbtn pnav" href="<?php echo $yurl2($yfrom - $span, $yto - $span); ?>" title="<?php echo t('The period before'); ?>">&#9664;</a><?php } ?>
      <select class="dashperiod" aria-label="<?php echo htmlspecialchars(t('Period'), ENT_QUOTES); ?>" onchange="if (this.value === 'range') this.parentNode.querySelector('.prange').style.display = ''; else if (this.value) location.href = this.value">
        <option value="views.php?view=Seasonality&amp;yperiod=this"<?php echo $yis_this ? ' selected' : ''; ?>><?php echo t('This year'); ?></option>
        <option value="views.php?view=Seasonality&amp;yperiod=last2"<?php echo $yis2 ? ' selected' : ''; ?>><?php echo t('Last 2 years'); ?></option>
        <?php // another span (a year reached with the arrows, a range) shows in the closed picklist only (owner 2026-10-22)
          if ($yis_range) { ?><option value="" selected hidden disabled><?php echo $h($yfrom === $yto ? (string)$yfrom : $yfrom . ' - ' . $yto); ?></option><?php } ?>
        <option value="range"><?php echo t('Year range'); ?></option>
        <option value="views.php?view=Seasonality&amp;yperiod=all"<?php echo !$yfrom ? ' selected' : ''; ?>><?php echo t('All years'); ?></option>
      </select>
      <?php if ($span) { if ($yfrom + $span <= $this_year) { ?><a class="stdbtn pnav" href="<?php echo $yurl2($yfrom + $span, min($this_year, $yto + $span)); ?>" title="<?php echo t('The period after'); ?>">&#9654;</a><?php } else { ?><span class="stdbtn pnav off">&#9654;</span><?php } } ?>
      <form class="yrange prange" method="GET" action="views.php"<?php echo isset($_GET['pr']) ? '' : ' style="display:none"'; ?>><input type="hidden" name="view" value="Seasonality"><input type="hidden" name="pr" value="1">
        <?php echo t('From'); ?> <select name="yfrom"><?php for ($y = $first_year; $y <= $this_year; $y++) echo '<option' . ($y === ($yfrom ?: $first_year) ? ' selected' : '') . '>' . $y . '</option>'; ?></select>
        <?php echo t('to'); ?> <select name="yto"><?php for ($y = $first_year; $y <= $this_year; $y++) echo '<option' . ($y === ($yto ?: $this_year) ? ' selected' : '') . '>' . $y . '</option>'; ?></select>
        <button type="submit"><?php echo t('Filter'); ?></button></form>
    </span>
    <script>try { document.cookie = 'week_period=' + <?php echo json_encode(!$yfrom ? 'all' : ($yis_this ? 'this' : ($yis2 ? 'last2' : $yfrom . ':' . $yto))); ?> + ';path=/;max-age=31536000;SameSite=Lax'; } catch (e) {}</script>
    <span class="right"><span class="dashfilter" role="button" tabindex="0" title="<?php echo t('Filter and search: show / hide'); ?>"
      onclick="this.closest('.top').classList.toggle('fopen')" onkeydown="if (event.key === 'Enter' || event.key === ' ') { event.preventDefault(); this.click(); }"><svg viewBox="0 0 24 24" width="18" height="18" aria-label="<?php echo t('Filter'); ?>" role="img"><path fill="currentColor" d="M3 4h18l-7 8.5V19l-4 2v-8.5z"/></svg></span><?php echo sort_toggle() . csv_icon('scripts/seasonality.php?csv=1' . ($yfrom ? '&yfrom=' . $yfrom . '&yto=' . $yto : '')); ?><span class="hmsort" data-table="seatable" data-store="byweek" data-default="n" data-remember="0" title="<?php echo t('Opens on Count, most first; a click on the button already on reverses it'); ?>"><small><?php echo t('Sort:'); ?></small><button type="button" data-sort="n"><?php echo t('Count'); ?></button><button type="button" data-sort="tax" title="<?php echo t('Field-guide order (eBird/Clements taxonomy)'); ?>"><?php echo t('Taxonomy'); ?></button><button type="button" data-sort="az" title="<?php echo t('Alphabetical, by the names shown'); ?>">A-Z</button></span>
      <input type="search" class="spfilter" data-table="seatable" placeholder="<?php echo t('Filter species...'); ?>"></span>
  </div>
  <?php if (!$tot) { echo '<div class="wrap"><p>' . ($year ? t('No detections in {year}.', array('year' => $year)) : t('No detections in any year.')) . '</p></div>'; } else { ?>
  <div class="wrap"><table id="seatable" class="hmsorted">
    <tr><th class="nm spcount"><?php echo t('{n} species', array('n' => count($tot))); ?></th><?php
      for ($k = 1; $k <= 53; ) {
        $span = 1;
        while ($k + $span <= 53 && $mon[$k + $span] === $mon[$k]) $span++;
        echo '<th class="m" colspan="' . $span . '">' . ($names[$mon[$k]] ?? '') . '</th>';
        $k += $span;
      } ?><th></th></tr>
    <tr><th class="nm"><select class="namemode" title="<?php echo t('Names shown'); ?>"><option value="com"><?php echo t('Common name'); ?></option><option value="sci"><?php echo t('Scientific name'); ?></option><option value="en"><?php echo t('English name'); ?></option></select></th><?php for ($k = 1; $k <= 53; $k++) echo '<th class="k">' . ($k % 4 === 1 ? $k : '') . '</th>'; ?><th class="t"><?php echo t('Count'); ?></th></tr>
    <?php foreach ($tot as $sci => $n) {
      echo '<tr data-n="' . intval($n) . '" data-tax="' . taxon_order($sci) . '" data-q="' . $h(mb_strtolower($com[$sci] . ' ' . $sci . ' ' . get_english_name($sci))) . '"><td class="nm"><a href="views.php?view=Bird&amp;sci=' . rawurlencode($sci) . '" title="' . $h($sci) . '" data-com="' . $h($com[$sci]) . '" data-sci="' . $h($sci) . '" data-en="' . $h(get_english_name($sci)) . '">' . $h($com[$sci]) . '</a></td>';
      for ($k = 1; $k <= 53; $k++) {
        $v = $by[$sci][$k] ?? 0;
        $a = $v ? 0.15 + 0.85 * log(1 + $v) / log(1 + $max) : 0;
        // a week the station recorded nothing at all stays light grey; a recorded week without this species is white
        $mon_d = (new DateTime())->setISODate($yr, $k, 1)->format('Y-m-d');
        $dl = $v ? ' data-drill="views.php?view=Dashboard&amp;from=' . $mon_d . '&amp;to=' . date('Y-m-d', strtotime($mon_d . ' +6 days')) . '&amp;sci=' . rawurlencode($sci) . '"' : '';
        echo '<td' . $dl . ' class="c' . ($wk_n[$k] ? '' : ' norec') . ($k === $cur ? ' now' : '') . '" title="' . $h($com[$sci] . ', KW' . $k . ': ' . $v) . '"' . ($v ? ' style="background:rgba(var(--accent-rgb,43,94,34),' . round($a, 2) . ') !important"' : '') . '></td>';
      }
      echo '<td class="t">' . fmt_num($n) . '</td></tr>'; // the count on the right of the weeks (owner 2026-10-21, as By Hour)
    } ?>
    <tr class="chart"><td class="nm"><b><?php echo t('Detections'); ?></b><br><small><?php echo t('{n} max', array('n' => fmt_num($wk_nmax))); ?></small></td><?php
      for ($k = 1; $k <= 53; $k++) {
        echo '<td class="b" title="' . t('KW{k}: {n} detections', array('k' => $k, 'n' => fmt_num($wk_n[$k]))) . '"><div class="bar' . ($k === $cur ? ' now' : '') . '" style="height:' . ($wk_n[$k] ? max(2, round(70 * $wk_n[$k] / $wk_nmax)) : 0) . 'px"></div></td>';
      } ?><td class="t"><?php echo fmt_num(array_sum($wk_n)); ?></td></tr>
    <tr class="chart"><td class="nm"><b><?php echo t('Species'); ?></b><br><small><?php echo t('{n} max', array('n' => $wk_spmax)); ?></small></td><?php
      for ($k = 1; $k <= 53; $k++) {
        echo '<td class="b" title="' . t('KW{k}: {n} species', array('k' => $k, 'n' => $wk_sp[$k])) . '"><div class="bar sp" style="height:' . ($wk_sp[$k] ? max(2, round(50 * $wk_sp[$k] / $wk_spmax)) : 0) . 'px"></div></td>';
      } ?><td class="t"><?php echo count($tot); ?></td></tr>
  </table></div>
  <?php } ?>
  <?php // owner's wording (2026-10-21), one sentence per line ?>
  <div class="note"><?php echo t('Darker = more detections.'); ?><br><?php echo t('White = recorded but not heard.'); ?><br><?php echo t('Light grey = a week without any recording.'); ?><br><?php echo t('This week highlighted in orange.'); ?></div>
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
