<?php
/* Model Comparison (owner 2026-10-10): the official model (birds.db) against the shadow model (birds_shadow.db, US-45)
 * on the same recordings. Both models analyse the same 3 s windows, so a detection is matched by its moment (Date +
 * Time = recording start + window offset) and species.
 *   - Species: per species the detections of each model, how many moments both found, and those only one found;
 *   - Records: every detection side by side — both, only the official, only the shadow — with what the other model
 *     heard at that same moment; a row with a clip of the official model opens the review player.
 * Only the time the shadow model was running counts (its first to its last detection in the period), so the official
 * model is not credited with hours the shadow never analysed. Opened as views.php?view=Models. */
require_once __DIR__ . '/common.php';
$h = function ($s) { return htmlspecialchars((string)$s, ENT_QUOTES); };
$config = get_config();
$shadow_path = __ROOT__ . '/scripts/birds_shadow.db';
$short = function ($m) {
  if (strpos($m, 'BirdNET-Plus_V3') === 0) return 'V3';
  if (strpos($m, 'V2.4') !== false) return 'V2.4';
  if ($m === 'BirdNET_6K_GLOBAL_MODEL') return 'V2.1';
  return $m;
};
$valid = function ($d) { return preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$d) ? $d : ''; };
$today = date('Y-m-d');
$base = 'views.php?view=Models';
$mode = ($_GET['show'] ?? '') === 'records' ? 'records' : 'species';
$scope = in_array($_GET['scope'] ?? '', array('both', 'official', 'shadow'), true) ? $_GET['scope'] : 'all';

$db = null; $smodel = ''; $s_first = ''; $s_last = '';
if (is_file($shadow_path)) {
  $db = new SQLite3(BS_DB, SQLITE3_OPEN_READONLY);
  apply_location_filter($db); // the location chosen in the header (US-58)
  $db->busyTimeout(5000);
  $db->exec("ATTACH DATABASE '" . SQLite3::escapeString($shadow_path) . "' AS s");
  if (!$db->querySingle("SELECT 1 FROM s.sqlite_master WHERE type='table' AND name='detections'")) $db = null;
}
// both models limited to the current location (owner 2026-10-21): the official through the temp view `detections`,
// the shadow by its own Location tag (the coordinate rule while it has none)
$sloc = '';
if ($db && count(locations_load()) > 1 && ($lcur = location_shown())) {
  $s_tag = false;
  $r = $db->query('PRAGMA s.table_info(detections)');
  while ($r && ($row = $r->fetchArray(SQLITE3_ASSOC))) if ($row['name'] === 'Location') $s_tag = true;
  $sloc = ' AND ' . ($s_tag && intval($lcur['id'] ?? 0) > 0
    ? '(Location = ' . intval($lcur['id']) . ' OR (Location IS NULL AND ' . location_coords_sql($lcur) . '))' : location_coords_sql($lcur));
}
if ($db) {
  $s_first = (string)$db->querySingle('SELECT MIN(Date) FROM s.detections');
  $s_last = (string)$db->querySingle('SELECT MAX(Date) FROM s.detections');
}
if (!$db || $s_last === '') { ?>
<div class="mdl" style="max-width:900px;margin:0;padding:0 12px;text-align:left">
  <?php // four paragraphs, one sentence per line (owner 2026-10-21) ?>
  <p><?php echo t('No shadow detections yet.'); ?></p>
  <p><?php echo t('The comparison needs a second model analysing the same recordings.'); ?><br>
  <?php echo t('In <a href="views.php?view=Model">Settings › Model</a>, choose a <b>Shadow</b> model (for example the V2.4 beside the V3) and tick <b>Shadow mode: active</b>.'); ?><br>
  <?php echo t('Its detections go to their own database only (no clips, no notifications).'); ?></p>
  <p><?php echo t('This page then compares the two models species by species and record by record.'); ?></p>
</div>
<?php return; }

// period: as By Hour; by default the last day the shadow model detected something
$from = $valid($_GET['from'] ?? '') ?: $s_last;
$to = $valid($_GET['to'] ?? '') ?: $from;
if ($to < $from) { $t = $from; $from = $to; $to = $t; }
$days = (int)round((strtotime($to) - strtotime($from)) / 86400) + 1;
// the shadow model of the period: the one of its latest detection (a shadow database can hold several over time)
$st = $db->prepare('SELECT Model FROM s.detections WHERE Date BETWEEN :f AND :t ORDER BY Date DESC, Time DESC LIMIT 1');
$st->bindValue(':f', $from); $st->bindValue(':t', $to);
$r = $st->execute()->fetchArray(SQLITE3_NUM);
$smodel = $r ? $r[0] : '';
$omodel = $config['MODEL'] ?? '';
$O = $short($omodel); $S = $short($smodel);
$span = array('', '');
if ($smodel !== '') {
  $st = $db->prepare("SELECT MIN(Date || ' ' || Time), MAX(Date || ' ' || Time) FROM s.detections WHERE Date BETWEEN :f AND :t AND Model = :m");
  $st->bindValue(':f', $from); $st->bindValue(':t', $to); $st->bindValue(':m', $smodel);
  $span = $st->execute()->fetchArray(SQLITE3_NUM);
}
// one row per moment and species for each model, then the union: official (with or without the shadow) + shadow only
$cte = "WITH o AS (SELECT Date, Time, Sci_Name, MAX(Com_Name) AS Com_Name, MAX(Confidence) AS c FROM detections
           WHERE Date BETWEEN :f AND :t AND Date || ' ' || Time BETWEEN :a AND :b GROUP BY Date, Time, Sci_Name),
         sh AS (SELECT Date, Time, Sci_Name, MAX(Com_Name) AS Com_Name, MAX(Confidence) AS c FROM s.detections
           WHERE Date BETWEEN :f AND :t AND Model = :m$sloc GROUP BY Date, Time, Sci_Name),
         u AS (SELECT o.Date, o.Time, o.Sci_Name, o.Com_Name, o.c AS oc, sh.c AS sc FROM o LEFT JOIN sh USING (Date, Time, Sci_Name)
           UNION ALL
           SELECT sh.Date, sh.Time, sh.Sci_Name, sh.Com_Name, NULL, sh.c FROM sh LEFT JOIN o USING (Date, Time, Sci_Name) WHERE o.Sci_Name IS NULL) ";
$run = function ($sql) use ($db, $from, $to, $span, $smodel) {
  $st = $db->prepare($sql);
  $st->bindValue(':f', $from); $st->bindValue(':t', $to); $st->bindValue(':m', $smodel);
  $st->bindValue(':a', (string)$span[0]); $st->bindValue(':b', (string)$span[1]);
  return $st->execute();
};
$sp = array(); $tot = array('o' => 0, 's' => 0, 'b' => 0, 'oo' => 0, 'so' => 0);
$res = $run($cte . "SELECT Sci_Name, MAX(Com_Name), SUM(oc IS NOT NULL), SUM(sc IS NOT NULL), SUM(oc IS NOT NULL AND sc IS NOT NULL), AVG(oc), AVG(sc)
  FROM u GROUP BY Sci_Name");
while ($res && ($r = $res->fetchArray(SQLITE3_NUM))) {
  $sp[$r[0]] = array('com' => $r[1], 'o' => intval($r[2]), 's' => intval($r[3]), 'b' => intval($r[4]), 'oc' => $r[5], 'sc' => $r[6]);
  $tot['o'] += $r[2]; $tot['s'] += $r[3]; $tot['b'] += $r[4];
}
// the official model's name of a species wins (the shadow's label file can be in another language)
$res = $db->query('SELECT Sci_Name, MAX(Com_Name) FROM detections GROUP BY Sci_Name');
$com_of = array();
while ($res && ($r = $res->fetchArray(SQLITE3_NUM))) $com_of[$r[0]] = $r[1];
foreach ($sp as $k => $v) if (isset($com_of[$k])) $sp[$k]['com'] = $com_of[$k];
$tot['oo'] = $tot['o'] - $tot['b']; $tot['so'] = $tot['s'] - $tot['b'];
$n_o = count(array_filter($sp, function ($v) { return $v['o'] > 0; }));
$n_s = count(array_filter($sp, function ($v) { return $v['s'] > 0; }));
$n_os = count(array_filter($sp, function ($v) { return $v['s'] === 0; }));
$n_ss = count(array_filter($sp, function ($v) { return $v['o'] === 0; }));
$union = $tot['o'] + $tot['so'];
uasort($sp, function ($a, $b) { return max($b['o'], $b['s']) - max($a['o'], $a['s']); });

$records = array(); $limit = 1000; $more = false;
if ($mode === 'records') {
  $where = array('all' => '1', 'both' => 'oc IS NOT NULL AND sc IS NOT NULL', 'official' => 'sc IS NULL', 'shadow' => 'oc IS NULL')[$scope];
  $res = $run($cte . "SELECT Date, Time, Sci_Name, Com_Name, oc, sc FROM u WHERE $where ORDER BY Date DESC, Time DESC, Sci_Name LIMIT " . ($limit + 1));
  while ($res && ($r = $res->fetchArray(SQLITE3_ASSOC))) $records[] = $r;
  if (count($records) > $limit) { $more = true; array_pop($records); }
  // what each model heard at every moment shown, and the official clips of that moment (index Date/Time on both)
  $oq = $db->prepare('SELECT * FROM detections WHERE Date = :d AND Time = :t');
  $sq = $db->prepare('SELECT Sci_Name, MAX(Com_Name) AS Com_Name, MAX(Confidence) AS c FROM s.detections WHERE Date = :d AND Time = :t AND Model = :m' . $sloc . ' GROUP BY Sci_Name');
  $moment = array();
  foreach ($records as $rec) {
    $k = $rec['Date'] . ' ' . $rec['Time'];
    if (isset($moment[$k])) continue;
    $moment[$k] = array('o' => array(), 's' => array());
    $oq->bindValue(':d', $rec['Date']); $oq->bindValue(':t', $rec['Time']);
    $res = $oq->execute();
    while ($res && ($x = $res->fetchArray(SQLITE3_ASSOC))) $moment[$k]['o'][$x['Sci_Name']] = $x;
    $sq->bindValue(':d', $rec['Date']); $sq->bindValue(':t', $rec['Time']); $sq->bindValue(':m', $smodel);
    $res = $sq->execute();
    while ($res && ($x = $res->fetchArray(SQLITE3_ASSOC))) $moment[$k]['s'][$x['Sci_Name']] = $x;
  }
}
$purl = function ($f, $t, $extra = array()) use ($base, $mode, $scope) {
  $q = array('from' => $f, 'to' => $t) + $extra + array('show' => $mode, 'scope' => $scope);
  if ($q['show'] === 'species') unset($q['show'], $q['scope']); elseif ($q['scope'] === 'all') unset($q['scope']);
  return $base . '&amp;' . http_build_query($q, '', '&amp;');
};
$pct = function ($c) { return $c === null ? '-' : round($c * 100) . '%'; };
$namesel = '<select class="namemode" title="' . $h(t('Names shown')) . '"><option value="com">' . $h(t('Common name')) . '</option><option value="sci">' . $h(t('Scientific name')) . '</option><option value="en">' . $h(t('English name')) . '</option></select>';
$spname = function ($sci, $com) use ($h) {
  return '<a href="views.php?view=Bird&amp;sci=' . rawurlencode($sci) . '" title="' . $h(t('Species page')) . '" data-com="' . $h($com) . '" data-sci="' . $h($sci) . '" data-en="' . $h(get_english_name($sci)) . '">' . $h($com) . '</a>';
};
?>
<style>
.mdl { max-width: 1300px; margin: 0; padding: 0 12px; text-align: left; }
.mdl .cards { display: grid; grid-template-columns: repeat(auto-fill, minmax(190px, 1fr)); gap: 12px; margin: 0 0 16px; }
.mdl .card { background: rgba(255,255,255,.65); border-radius: 12px; padding: 10px 14px 8px; box-shadow: 0 1px 3px rgba(0,0,0,.08); }
.mdl .card small.t { color: #555; } .mdl .card b { display: block; font-size: 1.7em; line-height: 1.2; } .mdl .card small.dl { font-size: 11px; color: #444; }
.mdl .bar { display: flex; justify-content: flex-start; align-items: center; gap: 8px 18px; flex-wrap: wrap; margin: 6px 0 10px; font-size: 13px; }
.mdl .bar > span { display: inline-flex; gap: 0; align-items: center; flex-wrap: wrap; }
.mdl .bar > .right { flex: 1 1 auto; justify-content: flex-end; gap: 10px; } .mdl .bar .right input { width: 220px; max-width: 100%; }
.mdl .bar .yrange button { width: auto; padding: 4px 12px; border-radius: 12px; border: 1px solid var(--accent,#2b5e22); background: var(--accent,#2b5e22); color: #fff; font-weight: 600; cursor: pointer; }
.mdl .hmsort { gap: 0; }
.mdl td.both { color: #1b5e20; } .mdl td.oonly { color: #8a4b00; } .mdl td.sonly { color: #0d47a1; }
/* the difference at a glance (owner 2026-10-25): each row tinted by its result, the agreement bar from red to green,
   the zeros muted, the higher mean confidence in bold; a legend above the table */
.mdl td.det { background: rgba(46,125,50,.22); } .mdl td.nodet { background: rgba(198,40,40,.22); }
.mdl td.zero { opacity: .35; } .mdl td.hi { font-weight: 700; }
.mdl .legend { display: flex; flex-wrap: wrap; gap: 6px 14px; font-size: 12px; margin: 6px 0; }
.mdl .legend span::before { content: ''; display: inline-block; width: 12px; height: 12px; border-radius: 3px; margin-right: 5px; vertical-align: -2px; }
.mdl .legend .l-det::before { background: rgba(46,125,50,.5); } .mdl .legend .l-nodet::before { background: rgba(198,40,40,.5); }
.mdl .chip { display: inline-block; padding: 1px 8px; border-radius: 10px; font-size: 12px; font-weight: 600; color: #fff; }
.mdl .chip.both { background: #2e7d32; } .mdl .chip.oonly { background: #e65100; } .mdl .chip.sonly { background: #1565c0; }
.mdl .mbar { display: inline-block; width: 60px; height: 8px; background: rgba(128,128,128,.25); border-radius: 4px; vertical-align: middle; margin-right: 6px; }
.mdl .mbar span { display: block; height: 100%; background: #2e7d32; border-radius: 4px; }
.mdl tr.rvrow { cursor: pointer; }
.mdl .note { font-size: 12px; color: #555; margin-top: 6px; }
.mdl .bar:not(.fopen) .yrange, .mdl .bar:not(.fopen) .mscope, .mdl .bar:not(.fopen) .spfilter { display: none !important; }
.mdl .dashperiod { width: auto; font-size: 13px; padding: 3px 6px; } .mdl .dashdates { font-size: 13px; opacity: .85; }
.mdl .dashfilter { cursor: pointer; border-radius: 50%; padding: 3px; display: inline-flex; color: var(--accent,#2b5e22); }
.mdl .bar.fopen .dashfilter { background: var(--accent,#2b5e22); color: #fff; }
.mdl .bar .right { margin-left: auto; }
@media screen and (max-width: 800px) { .mdl .cards { display: grid !important; grid-template-columns: 1fr 1fr; gap: 8px; } .mdl .cards .card { margin: 0 !important; min-width: 0; } }
</style>
<div class="mdl">
  <?php echo location_label(); // the location shown (owner 2026-10-21) ?>
  <div class="cards">
    <div class="card"><small class="t"><?php echo $h(t('Detections {a}, {b}', array('a' => $O, 'b' => $S))); ?></small><b><?php echo fmt_num($tot['o']) . ', ' . fmt_num($tot['s']); ?></b><small class="dl"><?php echo t('official, shadow'); ?></small></div>
    <div class="card"><small class="t"><?php echo $h(t('Species {a}, {b}', array('a' => $O, 'b' => $S))); ?></small><b><?php echo $n_o . ', ' . $n_s; ?></b><small class="dl"><?php echo $h(t('{n} only {model}', array('n' => $n_os, 'model' => $O))); ?>, <?php echo $h(t('{n} only {model}', array('n' => $n_ss, 'model' => $S))); ?></small></div>
    <div class="card"><small class="t"><?php echo t('Found by both'); ?></small><b><?php echo fmt_num($tot['b']); ?></b><small class="dl"><?php echo t('{pct}% of every detection of the two', array('pct' => $union ? round(100 * $tot['b'] / $union) : 0)); ?></small></div>
    <div class="card"><small class="t"><?php echo $h(t('Only {model}', array('model' => $O))); ?></small><b><?php echo fmt_num($tot['oo']); ?></b><small class="dl"><?php echo $h(t('{pct}% of the {model} detections', array('pct' => $tot['o'] ? round(100 * $tot['oo'] / $tot['o']) : 0, 'model' => $O))); ?></small></div>
    <div class="card"><small class="t"><?php echo $h(t('Only {model}', array('model' => $S))); ?></small><b><?php echo fmt_num($tot['so']); ?></b><small class="dl"><?php echo $h(t('{pct}% of the {model} detections', array('pct' => $tot['s'] ? round(100 * $tot['so'] / $tot['s']) : 0, 'model' => $S))); ?></small></div>
  </div>
  <?php // the bar as By Hour (owner 2026-10-21): Species / Records, the period picklist (Last day / All, a filtered range as a
  // third choice), the Filter icon (date range, which records, species search) and Sort on the right edge
  $ficon = '<span class="dashfilter" role="button" tabindex="0" title="' . t('Filter and search: show / hide') . '" onclick="this.closest(\'.bar\').classList.toggle(\'fopen\')" onkeydown="if (event.key === \'Enter\' || event.key === \' \') { event.preventDefault(); this.click(); }"><svg viewBox="0 0 24 24" width="18" height="18" aria-label="' . t('Filter') . '" role="img"><path fill="currentColor" d="M3 4h18l-7 8.5V19l-4 2v-8.5z"/></svg></span>'; ?>
  <div class="bar top sortbar">
    <span class="nowmodes"><button type="button" class="<?php echo $mode === 'species' ? 'active' : ''; ?>" onclick="location.href='<?php echo $purl($from, $to, array('show' => 'species')); ?>'"><?php echo t('Species'); ?></button><button type="button" class="<?php echo $mode === 'records' ? 'active' : ''; ?>" onclick="location.href='<?php echo $purl($from, $to, array('show' => 'records')); ?>'"><?php echo t('Records'); ?></button></span>
    <?php $prev_f = date('Y-m-d', strtotime($from . " -$days days")); $prev_t = date('Y-m-d', strtotime($to . " -$days days"));
      $next_f = date('Y-m-d', strtotime($from . " +$days days")); $next_t = date('Y-m-d', strtotime($to . " +$days days"));
      $label = $from === $to ? ($from === $today ? t('Today') : t(date('D', strtotime($from))) . date(' d/m/Y', strtotime($from))) : date('d/m/Y', strtotime($from)) . ' - ' . date('d/m/Y', strtotime($to)); ?>
    <?php $is_last = ($from === $to && $from === $s_last); $is_allp = ($from === $s_first && $to === $s_last) && !$is_last; ?>
    <select class="dashperiod" aria-label="<?php echo htmlspecialchars(t('Period'), ENT_QUOTES); ?>" onchange="if (this.value) location.href = this.value.replace(/&amp;/g, '&')">
      <option value="<?php echo $purl($s_last, $s_last); ?>"<?php echo $is_last ? ' selected' : ''; ?>><?php echo t('Last day'); ?></option>
      <option value="<?php echo $purl($s_first, $s_last); ?>"<?php echo $is_allp ? ' selected' : ''; ?>><?php echo t('All'); ?></option>
      <?php if (!$is_last && !$is_allp) { ?><option value="" selected><?php echo $h($label); ?></option><?php } ?>
    </select>
    <span class="dashdates"><?php echo $h($is_allp ? date('d/m/Y', strtotime($s_first)) . ' - ' . date('d/m/Y', strtotime($s_last)) : ($is_last ? date('d/m/Y', strtotime($s_last)) : '')); ?></span>
    <form class="yrange" method="GET" action="views.php"><input type="hidden" name="view" value="Models">
      <?php if ($mode === 'records') { ?><input type="hidden" name="show" value="records"><?php if ($scope !== 'all') { ?><input type="hidden" name="scope" value="<?php echo $h($scope); ?>"><?php } } ?>
      <?php echo t('From'); ?> <input type="date" name="from" value="<?php echo $h($from); ?>" min="<?php echo $h($s_first); ?>" max="<?php echo $h($s_last); ?>">
      <?php echo t('to'); ?> <input type="date" name="to" value="<?php echo $h($to); ?>" min="<?php echo $h($s_first); ?>" max="<?php echo $h($s_last); ?>">
      <button type="submit"><?php echo t('Filter'); ?></button></form>
    <?php if ($mode === 'records') { ?>
    <span class="periodnav mscope">
      <?php foreach (array('all' => t('All'), 'both' => t('Both'), 'official' => t('Only {model}', array('model' => $O)), 'shadow' => t('Only {model}', array('model' => $S))) as $k => $lbl)
        echo '<a class="' . ($scope === $k ? 'on' : '') . '" href="' . $base . '&amp;' . http_build_query(array('from' => $from, 'to' => $to, 'show' => 'records') + ($k === 'all' ? array() : array('scope' => $k)), '', '&amp;') . '">' . $h($lbl) . '</a>'; ?>
    </span>
    <span class="right"><?php echo $ficon; ?><input type="search" class="spfilter" data-table="mdlrec" placeholder="<?php echo t('Filter species...'); ?>" title="<?php echo t('Common, scientific or English name (accents ignored)'); ?>"></span>
    <?php } else { ?>
    <span class="right"><?php echo $ficon . sort_toggle(); ?><span class="hmsort" data-table="mdlsp" data-store="models"><small><?php echo t('Sort:'); ?></small><button type="button" data-sort="n"><?php echo t('Count'); ?></button><button type="button" data-sort="tax" title="<?php echo t('Field-guide order (eBird/Clements taxonomy)'); ?>"><?php echo t('Taxonomy'); ?></button><button type="button" data-sort="az" title="<?php echo t('Alphabetical, by the names shown'); ?>">A-Z</button></span>
      <input type="search" class="spfilter" data-table="mdlsp" placeholder="<?php echo t('Filter species...'); ?>" title="<?php echo t('Common, scientific or English name (accents ignored)'); ?>"></span>
    <?php } ?>
  </div>
  <?php if (!$sp) { echo '<p class="note">' . t('No detections of either model in this period.') . '</p>'; }
  elseif ($mode === 'species') { ?>
  <div class="legend"><span class="l-det"><?php echo t('detected'); ?></span><span class="l-nodet"><?php echo t('not detected'); ?></span></div>
  <table id="mdlsp" class="stdtable">
    <tr><th><?php echo $namesel; ?></th><th class="num"><?php echo $h($O); ?></th><th class="num"><?php echo $h($S); ?></th>
      <th class="num" title="<?php echo t('Moments (same 3 s window) both models gave this species'); ?>"><?php echo t('Both'); ?></th><th class="num" title="<?php echo $h(t('Share of this species\' moments that both models found')); ?>"><?php echo t('Agreement'); ?></th>
      <th class="num"><?php echo $h(t('Only {model}', array('model' => $O))); ?></th><th class="num"><?php echo $h(t('Only {model}', array('model' => $S))); ?></th>
      <th class="num" title="<?php echo t('Mean confidence of the official model'); ?>"><?php echo $h(t('Conf. {model}', array('model' => $O))); ?></th><th class="num" title="<?php echo t('Mean confidence of the shadow model'); ?>"><?php echo $h(t('Conf. {model}', array('model' => $S))); ?></th></tr>
    <?php foreach ($sp as $sci => $v) {
      $all = $v['o'] + $v['s'] - $v['b']; $ag = $all ? 100 * $v['b'] / $all : 0;
      $z = function ($n) { return $n ? '' : ' zero'; };
      $hi = function ($a, $b) { return ($a !== null && ($b === null || $a > $b + 0.0001)) ? ' hi' : ''; };
      echo '<tr data-n="' . max($v['o'], $v['s']) . '" data-tax="' . taxon_order($sci) . '" data-q="' . $h(mb_strtolower($v['com'] . ' ' . $sci . ' ' . get_english_name($sci))) . '">'
        . '<td>' . $spname($sci, $v['com']) . '</td>'
        // the side that detected the species in green, the one that did not in red (owner 2026-10-25)
        . '<td class="num ' . ($v['o'] ? 'det' : 'nodet') . '">' . fmt_num($v['o']) . '</td><td class="num ' . ($v['s'] ? 'det' : 'nodet') . '">' . fmt_num($v['s']) . '</td>'
        . '<td class="num both' . $z($v['b']) . '">' . fmt_num($v['b']) . '</td>'
        . '<td class="num" data-sort="' . round($ag, 1) . '"><span class="mbar"><span style="width:' . round($ag) . '%;background:hsl(' . round($ag * 1.2) . ',65%,38%)"></span></span>' . round($ag) . '%</td>'
        // Only V3 / Only V2.4 the same way (owner 2026-10-25): some = green, none = red
        . '<td class="num ' . ($v['o'] - $v['b'] ? 'det' : 'nodet') . '">' . fmt_num($v['o'] - $v['b']) . '</td><td class="num ' . ($v['s'] - $v['b'] ? 'det' : 'nodet') . '">' . fmt_num($v['s'] - $v['b']) . '</td>'
        . '<td class="num' . $hi($v['oc'], $v['sc']) . '">' . $pct($v['oc']) . '</td><td class="num' . $hi($v['sc'], $v['oc']) . '">' . $pct($v['sc']) . '</td></tr>';
    } ?>
  </table>
  <?php } else { ?>
  <div class="legend"><span class="l-det"><?php echo t('detected'); ?></span><span class="l-nodet"><?php echo t('not detected'); ?></span></div>
  <table id="mdlrec" class="stdtable">
    <tr><th><?php echo t('Date'); ?></th><th><?php echo t('Time'); ?></th><th><?php echo $namesel; ?></th><th class="num"><?php echo $h($O); ?></th><th class="num"><?php echo $h($S); ?></th><th><?php echo t('Result'); ?></th>
      <th title="<?php echo t('What the other model gave at this same moment'); ?>"><?php echo t('The other model heard'); ?></th><th class="rv" data-nosort><?php echo t('Review'); ?></th></tr>
    <?php foreach ($records as $rec) {
      $k = $rec['Date'] . ' ' . $rec['Time']; $m = $moment[$k]; $sci = $rec['Sci_Name'];
      $com = $com_of[$sci] ?? $rec['Com_Name'];
      if ($rec['oc'] !== null && $rec['sc'] !== null) { $res_t = t('Both'); $cls = 'both'; $other = array(); }
      elseif ($rec['oc'] !== null) { $res_t = t('Only {model}', array('model' => $O)); $cls = 'oonly'; $other = $m['s']; }
      else { $res_t = t('Only {model}', array('model' => $S)); $cls = 'sonly'; $other = $m['o']; }
      $heard = array();
      foreach ($other as $osci => $x) if ($osci !== $sci) $heard[] = ($com_of[$osci] ?? $x['Com_Name']) . ' ' . round(($x['c'] ?? $x['Confidence']) * 100) . '%';
      $heard_t = $cls === 'both' ? '' : ($heard ? implode(', ', $heard) : t('nothing'));
      // the clip: the official detection of this species at this moment, else another official clip of the same window
      $clip = $m['o'][$sci] ?? (reset($m['o']) ?: null);
      $attrs = ''; $rv = '';
      if ($clip) {
        $file = $clip['Date'] . '/' . species_folder($clip['Sci_Name'] ?? '', $clip['Com_Name'], $clip['File_Name']) . '/' . $clip['File_Name'];
        $attrs = ' class="rvrow"' . review_item_attrs($file, $clip['Com_Name'] . ', ' . $k . ', ' . round($clip['Confidence'] * 100) . '%', $clip['Sci_Name'], $clip)
          . ' onclick="if (!event.target.closest(\'a,button\')) reviewDetection(this)" title="' . ($clip['Sci_Name'] === $sci ? $h(t('Listen and review')) : $h(t('Listen to this moment (clip of {name})', array('name' => $clip['Com_Name'])))) . '"';
        if ($clip['Sci_Name'] === $sci) $rv = validate_button($file);
      }
      echo '<tr data-n="1" data-q="' . $h(mb_strtolower($com . ' ' . $sci . ' ' . get_english_name($sci))) . '"' . $attrs . '>'
        . '<td class="nw">' . $h($rec['Date']) . '</td><td class="nw">' . $h($rec['Time']) . '</td><td>' . $spname($sci, $com) . '</td>'
        . '<td class="num ' . ($rec['oc'] !== null ? 'det' : 'nodet') . '">' . $pct($rec['oc']) . '</td><td class="num ' . ($rec['sc'] !== null ? 'det' : 'nodet') . '">' . $pct($rec['sc']) . '</td>'
        . '<td><span class="chip ' . $cls . '">' . $h($res_t) . '</span></td><td>' . $h($heard_t) . '</td><td class="rv">' . $rv . '</td></tr>';
    } ?>
  </table>
  <?php if ($more) echo '<p class="note">' . t('The latest {n} records are shown: narrow the period or pick Both / Only to see the others.', array('n' => fmt_num($limit))) . '</p>'; ?>
  <?php } ?>
  <?php // the long explanation under the table left the page (owner 2026-10-21) ?>
</div>
<script src="static/species-sort.js?v=<?php echo @filemtime(__DIR__ . "/../homepage/static/species-sort.js"); ?>"></script>
<script src="static/std-table.js?v=<?php echo @filemtime(__DIR__ . "/../homepage/static/std-table.js"); ?>"></script>
<script src="static/name-mode.js?v=<?php echo @filemtime(__DIR__ . "/../homepage/static/name-mode.js"); ?>"></script>
<script src="static/review-player.js?v=<?php echo @filemtime(__DIR__ . "/../homepage/static/review-player.js"); ?>"></script>
