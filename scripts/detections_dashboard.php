<?php
/* By Hour (owner 2026-10-09; started as the Dashboard trial, replaced All Detections): a period filter, then
 *   - indicator cards with a 14-day sparkline and the change against the previous period of the same length;
 *   - the day by half hour: every species × 48 half-hour cells, with detections and species per half hour under it;
 *   - the year by calendar week: detections and species per KW (every date, species filter only), as on Seasonality.
 * Plain SVG drawn here, no chart library. Opened as views.php?view=Dashboard. */
require_once __DIR__ . '/common.php';
$db = get_db();
$h = function ($s) { return htmlspecialchars((string)$s, ENT_QUOTES); };
$valid = function ($d) { return preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$d) ? $d : ''; };
$today = date('Y-m-d');
$first_day = (string)$db->querySingle('SELECT MIN(Date) FROM detections') ?: $today;
// the period as on Validation (owner 2026-10-22, common.php page_period / period_picker): the last 7 days until another
// one is picked, kept in the cookie hour_period; All = from the first detection to today
list($pick_from, $pick_to) = page_period('hour_period', true, 'last7');
$from = $pick_from !== '' ? $pick_from : $first_day;
$to = $pick_from !== '' ? $pick_to : $today;
$sci = trim(html_entity_decode($_GET['sci'] ?? '', ENT_QUOTES));
$nr = not_rejected_sql();
$is_today = ($from === $today && $to === $today);
// a location whose first detection is today: All is the same day, the picklist keeps Today (owner 2026-10-21: it jumped to All)
$is_all = !$is_today && ($from === $first_day && $to === $today);
$days = (int)round((strtotime($to) - strtotime($from)) / 86400) + 1;
$pfrom = date('Y-m-d', strtotime($from . " -$days days"));
$pto = date('Y-m-d', strtotime($from . ' -1 day'));
$spw = $sci !== '' ? ' AND Sci_Name = :s' : '';
$q = function ($sql, $params) use ($db, $sci) {
  $st = $db->prepare($sql);
  foreach ($params as $k => $v) $st->bindValue($k, $v);
  if ($sci !== '' && strpos($sql, ':s') !== false) $st->bindValue(':s', $sci);
  return $st->execute();
};
$one = function ($sql, $params) use ($q) { $r = $q($sql, $params)->fetchArray(SQLITE3_NUM); return $r ? $r[0] : null; };
$base = 'views.php?view=Dashboard';
// the names of the species shown are looked up once the half-hour table is known (common.php species_com_names)
$com_of = array();

// ---- cards: value, change vs the previous period, sparkline of the last 14 days ending at $to ----
// the cards from counts per species and per day without NOT IN on every row, the days before today kept (common.php
// grouped_counts; 2026-10-23 test run: these queries were 1 s of the page on the station)
$sp_param = $sci !== '' ? array(':s' => $sci) : array();
$per_sp = array();
foreach (grouped_counts(array('Sci_Name'), $from, $to, $spw, $sp_param) as $r) $per_sp[$r[0]] = $r[1];
$cur = array(array_sum($per_sp));
$prev = array(array_sum(array_column(grouped_counts(array('Sci_Name'), $pfrom, $pto, $spw, $sp_param), 1)));
// new = a species of the period never detected before it: one index lookup per species
$new = 0;
$before = $db->prepare('SELECT 1 FROM detections WHERE Sci_Name = :s AND Date < :f LIMIT 1');
foreach (array_keys($per_sp) as $s) {
  $before->bindValue(':s', $s); $before->bindValue(':f', $from);
  $r = $before->execute();
  if (!($r && $r->fetchArray(SQLITE3_NUM))) $new++;
  $before->reset();
}
// species of the period with at least one detection validated "yes" (owner 2026-10-21); +Date: found through the
// answers, not by walking every day of the period (2026-10-23 run 3)
$validated = $db->querySingle("SELECT 1 FROM sqlite_master WHERE type='table' AND name='detection_reviews'")
  ? intval($one("SELECT COUNT(DISTINCT Sci_Name) FROM detections WHERE +Date BETWEEN :f AND :t$spw AND File_Name IN (SELECT File_Name FROM detection_reviews WHERE Verdict = 'yes')", array(':f' => $from, ':t' => $to)))
  : 0;
// species of the period marked Confirmed (confirmed_species_list.txt, species page), owner 2026-10-21
$conf_list = array_flip(array_map('trim', @file(BS_LISTS . '/confirmed_species_list.txt', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: array()));
$confirmed = count(array_intersect_key($per_sp, $conf_list));
// the sparkline of the Detections card: detections per day of the last 14 days
$spark = array('n' => array());
$sstart = date('Y-m-d', strtotime($to . ' -13 days'));
$byday = array();
foreach (grouped_counts(array('Date'), $sstart, $to, $spw, $sp_param) as $r) $byday[$r[0]] = $r;
for ($i = 13; $i >= 0; $i--) {
  $d = date('Y-m-d', strtotime($to . " -$i days"));
  $spark['n'][] = intval($byday[$d][1] ?? 0);
}
$sparkline = function ($vals) {
  $w = 120; $hgt = 28; $max = max(1, max($vals)); $n = count($vals);
  $pts = array();
  foreach ($vals as $i => $v) $pts[] = round($i * $w / max(1, $n - 1), 1) . ',' . round($hgt - 2 - ($hgt - 4) * $v / $max, 1);
  $area = '0,' . $hgt . ' ' . implode(' ', $pts) . ' ' . $w . ',' . $hgt;
  return '<svg class="spark" viewBox="0 0 ' . $w . ' ' . $hgt . '" preserveAspectRatio="none"><polygon points="' . $area . '" class="sa"/><polyline points="' . implode(' ', $pts) . '" class="sl"/></svg>';
};
$delta = function ($c, $p, $label) {
  if ($p === null || floatval($p) == 0) return '<small class="dl">' . t('no data {period}', array('period' => $label)) . '</small>';
  $pct = round(100 * (floatval($c) - floatval($p)) / floatval($p));
  $cls = $pct > 0 ? 'up' : ($pct < 0 ? 'down' : '');
  return '<small class="dl ' . $cls . '">' . ($pct > 0 ? '▲ ' : ($pct < 0 ? '▼ ' : '')) . abs($pct) . '% ' . $label . '</small>';
};
$vs = $is_today ? t('vs yesterday') : ($days === 1 ? t('vs the day before') : t('vs previous {n} days', array('n' => $days)));

// ---- the day by half hour: every species of the period × 48 half-hour cells (owner 2026-10-09) ----
// counted without NOT IN on every row and with the days before today kept (common.php grouped_counts, 2026-10-23 test
// run: 2.5 s per load, 7 s for All at ten times the station's data)
$hb = array(); $htot = array(); $hb_n = array_fill(0, 48, 0); $hb_sp = array_fill(0, 48, 0);
foreach (grouped_counts(array('Sci_Name', 'CAST(substr(Time, 1, 2) AS INT) * 2 + (CAST(substr(Time, 4, 2) AS INT) >= 30)'), $is_all ? '' : $from, $to, $spw, $sp_param) as $r) {
  $hb[$r[0]][$r[1]] = $r[2]; $htot[$r[0]] = ($htot[$r[0]] ?? 0) + $r[2]; $hb_n[$r[1]] += $r[2]; $hb_sp[$r[1]]++;
}
arsort($htot);
$com_of = species_com_names(array_unique(array_merge(array_keys($htot), $sci !== '' ? array($sci) : array())));
$hmax = 1; foreach ($hb as $x) $hmax = max($hmax, max($x));
$hb_nmax = max(1, max($hb_n)); $hb_spmax = max(1, max($hb_sp));
// CSV of the half-hour table (owner 2026-10-22): the period and the species filter of the page
if (isset($_GET['csv'])) {
  $head = array(t('Scientific name'), t('Common name'), t('Total'));
  for ($b = 0; $b < 48; $b++) $head[] = sprintf('%02d:%02d', intdiv($b, 2), ($b % 2) * 30);
  $rows = array();
  foreach ($htot as $s => $n) { $r = array($s, $com_of[$s] ?? $s, $n); for ($b = 0; $b < 48; $b++) $r[] = $hb[$s][$b] ?? 0; $rows[] = $r; }
  csv_out('by-hour-' . $from . ($to !== $from ? '_' . $to : '') . '.csv', $head, $rows);
}
$now_bin = $is_today ? intval(date('G')) * 2 + (intval(date('i')) >= 30 ? 1 : 0) : -1;

// ---- the year by calendar week (KW1–KW53): every date recorded, species filter only — as on Seasonality ----
$kw_n = array_fill(1, 53, 0); $kw_spset = array_fill(1, 53, array());
foreach (grouped_counts(array('Sci_Name', 'Date'), '', '', $spw, $sp_param) as $r) { $k = intval(date('W', strtotime($r[1]))); $kw_n[$k] += $r[2]; $kw_spset[$k][$r[0]] = true; }
$kw_sp = array_map('count', $kw_spset);
$kw_nmax = max(1, max($kw_n)); $kw_spmax = max(1, max($kw_sp));
$cur_kw = intval(date('W'));
$yr = intval(date('o')); $kmon = array();
for ($k = 1; $k <= 53; $k++) { $thu = new DateTime(); $thu->setISODate($yr, $k, 4); $kmon[$k] = intval($thu->format('Y')) === $yr ? intval($thu->format('n')) : ($k > 40 ? 13 : 0); }
$mnames = array(1 => t('Jan'), t('Feb'), t('Mar'), t('Apr'), t('May'), t('Jun'), t('Jul'), t('Aug'), t('Sep'), t('Oct'), t('Nov'), t('Dec'));
$cellbar = function ($v, $max, $px, $cls, $title) {
  return '<td class="b" title="' . $title . '"><div class="bar ' . $cls . '" style="height:' . ($v ? max(2, round($px * $v / $max)) : 0) . 'px"></div></td>';
};
$heatcell = function ($v, $max, $cls, $title) {
  $a = $v ? 0.15 + 0.85 * log(1 + $v) / log(1 + $max) : 0;
  return '<td class="c' . $cls . '" title="' . $title . '"' . ($v ? ' style="background:rgba(var(--accent-rgb,43,94,34),' . round($a, 2) . ') !important"' : '') . '></td>';
};
?>
<style>
.dash { max-width: 1200px; margin: 0 auto; padding: 0 12px; text-align: left; }
.dash .bar { display: flex; justify-content: space-between; align-items: center; gap: 8px; flex-wrap: wrap; margin: 6px 0 10px; font-size: 13px; }
.dash .bar.top { justify-content: flex-start; gap: 8px 18px; } .dash .bar.top > .right { flex: 1 1 auto; display: flex; justify-content: flex-end; } .dash .bar .right input { width: 220px; max-width: 100%; }
.dash table.hm .spcount { font-weight: normal; font-size: 14px; } .dash .bar span.hmsort { gap: 0; }
.dash .bar span { display: inline-flex; gap: 8px; align-items: center; flex-wrap: wrap; }
.dash .bar button:not(.hmsort button), .dash .bar a.btn { width: auto; padding: 4px 12px; border-radius: 12px; border: 1px solid var(--accent,#2b5e22); background: #fff; color: var(--accent,#2b5e22); font-weight: 600; cursor: pointer; text-decoration: none; }
.dash .bar a.btn.on, .dash .bar button.on:not(.hmsort button) { background: var(--accent,#2b5e22); color: #fff; }
.dash .cards { display: grid; grid-template-columns: repeat(auto-fill, minmax(190px, 1fr)); gap: 12px; margin-bottom: 16px; }
.dash .card { background: rgba(255,255,255,.65); border-radius: 12px; padding: 10px 14px 6px; box-shadow: 0 1px 3px rgba(0,0,0,.08); }
.dash .card small.t { color: #555; } .dash .card b { display: block; font-size: 1.7em; line-height: 1.2; }
.dash .dl { font-size: 11px; color: #444; } .dash .dl.up { color: #1b5e20; } .dash .dl.down { color: #c62828; }
.dash svg.spark { width: 100%; height: 28px; display: block; margin-top: 4px; }
.dash svg.spark .sl { fill: none; stroke: var(--accent,#2b5e22); stroke-width: 1.6; vector-effect: non-scaling-stroke; } .dash svg.spark .sa { fill: rgba(var(--accent-rgb,43,94,34),.15); }
.dash .panel { background: rgba(255,255,255,.65); border-radius: 12px; padding: 10px 14px; margin-bottom: 16px; box-shadow: 0 1px 3px rgba(0,0,0,.08); overflow-x: auto; }
.dash .panel h3 { margin: 0 0 6px; font-size: 1.05em; }
.dash svg .ridge polygon { fill: rgba(74,143,60,.55); stroke: none; } .dash svg .ridge polyline { fill: none; stroke: #1f4a19; stroke-width: 1.2; }
.dash svg .ridge:hover polygon { fill: rgba(217,122,0,.6); }
.dash svg text { font-size: 11px; fill: #222; } .dash svg .ax { font-size: 10px; fill: #555; } .dash svg .grid { stroke: rgba(0,0,0,.08); }
.dash svg rect.off { fill: rgba(128,128,128,.15); } .dash svg rect.on { fill: var(--accent,#2b5e22); } .dash svg a:hover rect { stroke: #d97a00; stroke-width: 2; }
.dash .note { font-size: 12px; color: #555; }
.dash .hmwrap { overflow-x: auto; }
.dash table.hm { border-collapse: separate; border-spacing: 2px; font-size: 12px; }
.dash table.hm th, .dash table.hm td { padding: 0; border: none !important; }
.dash table.hm td.nm, .dash table.hm th.nm { text-align: left !important; white-space: nowrap; padding-right: 8px; position: sticky; left: 0; background: var(--panel,#e8f5ea) !important; z-index: 1; }
.dash table.hm td.c { width: 14px; min-width: 14px; height: 16px; border-radius: 2px; background: #fff !important; }
.dash table.hm td.c.now { outline: 1px solid #d97a00; }
.dash table.hm tr.drill td.c { cursor: pointer; } .dash table.hm tr.drill:hover td.c { box-shadow: inset 0 0 0 1px #d97a00; }
.dash table.hm th.m { text-align: left !important; font-size: 11px; font-weight: 600; border-left: 2px solid rgba(var(--accent-rgb,43,94,34),.45) !important; padding-left: 2px; }
.dash table.hm th.k { font-size: 9px; font-weight: normal; color: #333; text-align: left !important; }
.dash table.hm td.t, .dash table.hm th.t { text-align: right !important; padding-left: 8px; padding-right: 8px; white-space: nowrap; }
.dash table.hm tr.chart td { background: transparent !important; vertical-align: bottom; }
.dash table.hm tr.chart td.nm { vertical-align: bottom; padding-top: 10px; }
.dash table.hm tr.chart td.b { height: 74px; padding-top: 10px; min-width: 14px; }
.dash table.hm .bar { width: 100%; background: rgba(var(--accent-rgb,43,94,34),.8); border-radius: 2px 2px 0 0; }
.dash table.hm .bar.now { background: #d97a00; } .dash table.hm .bar.sp { background: #1565c0; }
.dash svg rect.kwb { fill: rgba(var(--accent-rgb,43,94,34),.75); } .dash svg rect.kwb.now { fill: #d97a00; } .dash svg rect.kwb:hover { fill: #1f4a19; }
.dash svg .spl { fill: none; stroke: #1565c0; stroke-width: 1.6; } .dash svg .spd { fill: #1565c0; }
</style>
<style>
.dash .bar:not(.fopen) .yrange, .dash .bar:not(.fopen) .spfilter { display: none !important; }
.dash .dashperiod { width: auto; font-size: 13px; padding: 3px 6px; }
.dash .bar:not(.sopen) .hmsort > small, .dash .bar:not(.sopen) .hmsort > button { display: none !important; }
/* closed, the Sort button sits on the right edge, over the table's right border; open, it stays left of the sort buttons */
.dash .bar:not(.sopen):not(.fopen) .hmsort { display: none !important; }
.dash .bar .right { align-items: center; gap: 6px; } .dash .bar .right .hmsort { flex: 0 0 auto; margin-left: 0; }
.dash .sortopen { border: 1px solid var(--accent,#2b5e22); background: #fff; color: var(--accent,#2b5e22); cursor: pointer; }
.dash .sortopen.on { background: var(--accent,#2b5e22); color: #fff; }
.dash .dashdates { font-size: 13px; opacity: .85; }
.dash .dashfilter { cursor: pointer; border-radius: 50%; padding: 3px; display: inline-flex; color: var(--accent,#2b5e22); }
.dash .bar.fopen .dashfilter { background: var(--accent,#2b5e22); color: #fff; }
/* the small charts of the cards only where there is room (owner 2026-10-21) */
@media screen and (max-width: 800px) { .dash .card svg.spark { display: none; } .dash .cards { display: grid !important; grid-template-columns: 1fr 1fr; gap: 8px; } .dash .cards .card { margin: 0 !important; min-width: 0; } }
.dash table.hm td.nm a { display: block; overflow: hidden; text-overflow: ellipsis; }
</style>
<div class="dash">
  <?php echo location_label(); // the location shown (owner 2026-10-21) ?>
  <div class="cards">
    <?php // the cards and their wording set by the owner (2026-10-21): Detections, Confirmed, Validated, New species ?>
    <div class="card"><small class="t"><?php echo t('Detections'); ?></small><b><?php echo fmt_num(intval($cur[0])); ?></b><?php echo $delta($cur[0], $prev[0], $vs) . $sparkline($spark['n']); ?></div>
    <div class="card"><small class="t"><?php echo t('Confirmed species'); ?></small><b><?php echo $confirmed; ?></b><small class="dl"><?php echo t('Confirmed on the species page'); ?></small></div>
    <div class="card"><small class="t"><?php echo t('Validated species'); ?></small><b><?php echo $validated; ?></b><small class="dl"><?php echo t('1 review = Yes'); ?></small></div>
    <div class="card"><small class="t"><?php echo t('New species'); ?></small><b><?php echo intval($new); ?></b><small class="dl"><?php echo t('First detection in the period'); ?></small></div>
  </div>
  <div class="bar top sortbar"<?php $cf_rf = get_config(); echo ' data-fphone="' . (($cf_rf['REPORTS_FILTERS_PHONE'] ?? 'closed') === 'open' ? 1 : 0) . '" data-fdesktop="' . (($cf_rf['REPORTS_FILTERS_DESKTOP'] ?? 'open') !== 'closed' ? 1 : 0) . '"'; // REPORTS_FILTERS_* (owner 2026-10-25) ?>><?php // filter line open by default (owner 2026-10-22), last state kept ?>
    <?php // the period control shared with Validation (owner 2026-10-22): ◀ Today / Last 7 / Last 30 / All / Date range ▶,
      // always shown (not part of the filter line) ?>
    <?php echo period_picker('views.php?view=Dashboard', $pick_from, $pick_to, $first_day, 'hour_period', array('sci' => $sci)); ?>
    <span>
      <?php if ($sci !== '') { ?><a class="btn on" href="<?php echo $base . '&amp;from=' . $from . '&amp;to=' . $to; ?>" title="<?php echo t('Show every species'); ?>">&times; <?php echo $h($com_of[$sci] ?? $sci); ?></a><?php } ?></span>
    <!-- species order (owner 2026-10-10): count (most detections first), taxonomy (eBird/Clements field-guide order), A–Z
         (by the names shown); remembered in this browser. One top line, laid out as By Week -->
    <?php // a Sort button on the right opens the sort buttons; open, it stays on their left as › to fold them (owner 2026-10-21) ?>
    <span class="right"><span class="dashfilter" role="button" tabindex="0" title="<?php echo t('Filter and search: show / hide'); ?>"
      onclick="this.closest('.bar').classList.toggle('fopen')" onkeydown="if (event.key === 'Enter' || event.key === ' ') { event.preventDefault(); this.click(); }"><svg viewBox="0 0 24 24" width="18" height="18" aria-label="<?php echo t('Filter'); ?>" role="img"><path fill="currentColor" d="M3 4h18l-7 8.5V19l-4 2v-8.5z"/></svg></span><?php echo sort_toggle() . csv_icon('scripts/detections_dashboard.php?csv=1&from=' . $from . '&to=' . $to . ($sci !== '' ? '&sci=' . rawurlencode($sci) : '')); ?><span class="hmsort" data-table="hmday" data-store="byhour" data-default="n" data-remember="0" title="<?php echo t('Opens on Count, most first; a click on the button already on reverses it'); ?>"><small><?php echo t('Sort:'); ?></small><button type="button" data-sort="n"><?php echo t('Count'); ?></button><button type="button" data-sort="tax" title="<?php echo t('Field-guide order (eBird/Clements taxonomy)'); ?>"><?php echo t('Taxonomy'); ?></button><button type="button" data-sort="az" title="<?php echo t('Alphabetical, by the names shown'); ?>">A-Z</button></span>
      <input type="search" class="hmfilter spfilter" data-table="hmday" placeholder="<?php echo t('Filter species...'); ?>" title="<?php echo t('Common, scientific or English name (accents ignored)'); ?>"></span>
  </div>
  <div class="panel">
    <?php if (!$htot) { echo '<p class="note">' . t('No detections in this period.') . '</p>'; } else { ?>
    <div class="hmwrap"><table class="hm" id="hmday">
      <tr><th class="nm"><span class="spcount"><?php echo t('{n} species', array('n' => count($htot))); ?></span><br><select class="namemode" title="<?php echo t('Names shown'); ?>"><option value="com"><?php echo t('Common name'); ?></option><option value="sci"><?php echo t('Scientific name'); ?></option><option value="en"><?php echo t('English name'); ?></option></select></th><?php for ($hh = 0; $hh < 24; $hh++) echo '<th class="m" colspan="2">' . sprintf('%02d', $hh) . '</th>'; ?><th class="t"><?php echo t('Count'); ?></th></tr>
      <?php foreach ($htot as $s => $n) {
        // the name opens the species page; a click on the cells drills down to this species in the dashboard
        echo '<tr class="drill" data-n="' . intval($n) . '" data-tax="' . taxon_order($s) . '" data-q="' . $h(mb_strtolower(($com_of[$s] ?? $s) . ' ' . $s . ' ' . get_english_name($s))) . '" data-drill="' . $base . '&amp;from=' . $from . '&amp;to=' . $to . '&amp;sci=' . rawurlencode($s) . '"><td class="nm"><a href="views.php?view=Bird&amp;sci=' . rawurlencode($s) . '" title="' . t('Species page') . '" data-com="' . $h($com_of[$s] ?? $s) . '" data-sci="' . $h($s) . '" data-en="' . $h(get_english_name($s)) . '">' . $h($com_of[$s] ?? $s) . '</a></td>';
        for ($b2 = 0; $b2 < 48; $b2++) echo $heatcell($hb[$s][$b2] ?? 0, $hmax, $b2 === $now_bin ? ' now' : '', $h(($com_of[$s] ?? $s) . ', ' . sprintf('%02d:%02d', intdiv($b2, 2), ($b2 % 2) * 30) . ': ' . ($hb[$s][$b2] ?? 0)));
        echo '<td class="t">' . fmt_num($n) . '</td></tr>'; // the count on the right of the hours (owner 2026-10-21)
      } ?>
      <?php // the hours again above the bar charts, readable after scrolling down a long species list (owner 2026-10-21) ?>
      <tr class="chart chead"><th class="nm"></th><?php for ($hh = 0; $hh < 24; $hh++) echo '<th class="m" colspan="2">' . sprintf('%02d', $hh) . '</th>'; ?><th class="t"></th></tr>
      <tr class="chart"><td class="nm"><b><?php echo t('Detections'); ?></b><br><small><?php echo t('{n} max', array('n' => fmt_num($hb_nmax))); ?></small></td><?php
        for ($b2 = 0; $b2 < 48; $b2++) echo $cellbar($hb_n[$b2], $hb_nmax, 70, $b2 === $now_bin ? 'now' : '', t('{time}: {n} detections', array('time' => sprintf('%02d:%02d', intdiv($b2, 2), ($b2 % 2) * 30), 'n' => fmt_num($hb_n[$b2])))); ?><td class="t"><?php echo fmt_num(array_sum($hb_n)); ?></td></tr>
      <tr class="chart"><td class="nm"><b><?php echo t('Species'); ?></b><br><small><?php echo t('{n} max', array('n' => $hb_spmax)); ?></small></td><?php
        for ($b2 = 0; $b2 < 48; $b2++) echo $cellbar($hb_sp[$b2], $hb_spmax, 50, 'sp', t('{time}: {n} species', array('time' => sprintf('%02d:%02d', intdiv($b2, 2), ($b2 % 2) * 30), 'n' => $hb_sp[$b2]))); ?><td class="t"><?php echo count($htot); ?></td></tr>
    </table></div>
    <?php // owner's wording (2026-10-21) ?>
    <div class="note"><?php echo t('One cell = half an hour;'); ?><br><?php echo t('Darker = more detections.'); ?><br>
      <?php echo t('Hover for the numbers; click a name for the species page.'); ?><br><?php echo t('Click the cells to see only that species in this panel.'); ?></div>
    <?php } ?>
  </div>
  <div class="panel"><h3><?php echo t('Occurrences by calendar week'); ?><?php echo $sci !== '' ? ', ' . $h($com_of[$sci] ?? $sci) : ''; ?></h3>
    <div class="hmwrap"><table class="hm">
      <tr><th class="nm"></th><?php
        for ($k = 1; $k <= 53; ) { $span = 1; while ($k + $span <= 53 && $kmon[$k + $span] === $kmon[$k]) $span++; echo '<th class="m" colspan="' . $span . '">' . ($mnames[$kmon[$k]] ?? '') . '</th>'; $k += $span; } ?><th></th></tr>
      <tr><th class="nm"></th><?php for ($k = 1; $k <= 53; $k++) echo '<th class="k">' . ($k % 4 === 1 ? $k : '') . '</th>'; ?><th class="t"><?php echo t('Total'); ?></th></tr>
      <tr class="chart"><td class="nm"><b><?php echo t('Detections'); ?></b><br><small><?php echo t('{n} max', array('n' => fmt_num($kw_nmax))); ?></small></td><?php
        for ($k = 1; $k <= 53; $k++) echo $cellbar($kw_n[$k], $kw_nmax, 70, $k === $cur_kw ? 'now' : '', t('KW{k}: {n} detections', array('k' => $k, 'n' => fmt_num($kw_n[$k])))); ?><td class="t"><?php echo fmt_num(array_sum($kw_n)); ?></td></tr>
      <tr class="chart"><td class="nm"><b><?php echo t('Species'); ?></b><br><small><?php echo t('{n} max', array('n' => $kw_spmax)); ?></small></td><?php
        for ($k = 1; $k <= 53; $k++) echo $cellbar($kw_sp[$k], $kw_spmax, 50, 'sp', t('KW{k}: {n} species', array('k' => $k, 'n' => $kw_sp[$k]))); ?><td class="t"><?php $allsp = array(); foreach ($kw_spset as $x) $allsp += $x; echo count($allsp); ?></td></tr>
    </table></div>
    <div class="note"><?php echo t('This week in orange.'); ?><br><?php echo t('Mouse over for the values.'); ?></div>
  </div>
</div>
<script>
// the species names 30 % narrower than the longest one (owner 2026-10-21); the full name on mouse over
(function () {
  var t = document.getElementById('hmday');
  if (!t) return;
  var w = 0;
  t.querySelectorAll('td.nm a').forEach(function (a) { w = Math.max(w, a.scrollWidth); a.title = a.textContent; });
  if (w) t.querySelectorAll('td.nm a').forEach(function (a) { a.style.maxWidth = Math.round(w * 0.7) + 'px'; });
})();
</script>
<script src="static/species-sort.js?v=<?php echo @filemtime(__DIR__ . "/../homepage/static/species-sort.js"); ?>"></script>
<script src="static/name-mode.js?v=<?php echo @filemtime(__DIR__ . "/../homepage/static/name-mode.js"); ?>"></script>
<script>
document.querySelectorAll('tr.drill').forEach(function (tr) {
  tr.addEventListener('click', function (e) { if (e.target.closest('td.c')) location.href = tr.dataset.drill.replace(/&amp;/g, '&'); });
});

</script>
