<?php
/* System › Disk Space (US-133, owner 2026-11-01; was a section of Status): the station's disk day by day over 90 days
 * (state/disk-usage.log: the day's lowest after the nightly purge in green, the highest before it in red), the capacity
 * forecast from the trend of the daily lowest, and the disk size needed in the long term with its premises
 * (state/disk-forecast.json, scripts/disk_forecast.py after each nightly purge), and the logs (sizes, retention, export,
 * clean now). Opens without login; the log actions and the retention ask for the password. */
require_once __DIR__ . '/common.php';
$home = get_home();
// the logs (owner 2026-11-01): ?logs=export downloads them (journal of the retention days as text, /var/log, the
// station's logs) as a .tar.gz; ?logs=clean (POST) applies the retention now; both behind the password
if (isset($_GET['logs'])) {
  ensure_authenticated(t('str_you_must_manage_the_logs_help'));
  $days = in_array((string)(get_config()['LOG_RETENTION_DAYS'] ?? '30'), array('0', '7', '30', '90', '365'), true) ? (string)get_config()['LOG_RETENTION_DAYS'] : '30';
  if ($_GET['logs'] === 'export') {
    header('Content-Type: application/gzip');
    header('Content-Disposition: attachment; filename="birdsuite-logs-' . gethostname() . '-' . date('Y-m-d_His') . '.tar.gz"');
    passthru('sudo -n ' . BS_ROOT_HELPER . ' logs-export ' . escapeshellarg($days));
    die();
  }
  if ($_GET['logs'] === 'clean' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    exec('sudo -n ' . BS_ROOT_HELPER . ' logs-clean ' . escapeshellarg($days) . ' 2>&1', $o, $rc);
    echo $rc === 0 ? 'OK' : t('str_the_logs_could_not_be_cleaned');
    die();
  }
  http_response_code(400);
  die();
}
?>
<style>
.diskspace { max-width: 1000px; margin: 0; text-align: left; padding: 0 12px; box-sizing: border-box; }
.diskspace .box { padding: 10px 14px; margin: 10px 0; }
.diskspace h3 { margin: 18px 0 6px; }
.diskspace .del { border-color: #c62828 !important; color: #c62828 !important; }
.diskspace a.stdbtn { text-decoration: none; display: inline-block; }
@media (max-width: 600px) { .diskspace table.stdtable { display: block; overflow-x: auto; max-width: 100%; } }
.diskspace table.disksim td:not(:first-child), .diskspace table.disksim th:not(:first-child) { text-align: right; white-space: nowrap; }
.diskspace table.disksim td small { white-space: normal; }
</style>
<div class="diskspace">
  <?php // the station's disk day by day (US-133, owner 2026-11-01): state/disk-usage.log, written by the nightly purge
  // (before / after) and disk_check.sh every 5 minutes; green = the day's lowest (after the purge), red = the highest
  // (before it); a day without samples is a gap; the axis runs from 0 to the disk size, so the chart shows how full it is
  $du_rows = array();
  foreach (@file(BS_DISK_USAGE_LOG, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: array() as $l) {
    $p = preg_split('/\s+/', trim($l));
    if (count($p) >= 4 && preg_match('/^\d{4}-\d\d-\d\d$/', $p[0])) $du_rows[$p[0]] = array((float)$p[1], (float)$p[2], (float)$p[3]);
  }
  $du = array('days' => array(), 'lo' => array(), 'hi' => array(), 'size' => 0);
  for ($i = 89; $i >= 0; $i--) {
    $d = date('Y-m-d', strtotime("-$i days"));
    $r = $du_rows[$d] ?? null;
    $du['days'][] = $d;
    $du['lo'][] = $r ? round($r[0] / 1e9, 2) : null;
    $du['hi'][] = $r ? round($r[1] / 1e9, 2) : null;
    if ($r) $du['size'] = round($r[2] / 1e9, 1);
  }
  // what fills the card (owner 2026-11-01): a stacked line per part, from state/disk-parts.log (disk_parts.sh after
  // the nightly purge); the top of the stack is the card's total
  $dp = array('days' => $du['days'], 'parts' => array(), 'card' => 0);
  $dp_rows = array();
  foreach (@file(BS_DISK_PARTS_LOG, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: array() as $l) {
    $p = preg_split('/\s+/', trim($l));
    if (count($p) >= 8 && preg_match('/^\d{4}-\d\d-\d\d$/', $p[0])) $dp_rows[$p[0]] = $p;
  }
  // the parts in the stack's order and their column in the log line (the raw recordings are the 9th column, 0 when absent)
  $dp_names = array(t('str_recordings_and_clips', array('dir' => BS_DATA_DIR)), t('str_birdsuite_program_settings_database'),
    t('str_raw_recordings_folder', array('dir' => basename(BS_RAW_REC))), t('str_other_files_in_home'),
    t('str_system_logs_var_log'), t('str_other_on_the_root_filesystem_help'), t('str_other_partitions_of_the_card'));
  $dp_cols = array(1, 2, 8, 3, 4, 5, 6);
  foreach ($dp_rows as $d => $r) if (!isset($r[8])) $dp_rows[$d][8] = 0;
  foreach ($dp_names as $i => $name) {
    $vals = array();
    foreach ($du['days'] as $d) $vals[] = isset($dp_rows[$d]) ? round($dp_rows[$d][$dp_cols[$i]] / 1e9, 2) : null;
    $dp['parts'][] = array('label' => $name, 'data' => $vals);
  }
  foreach ($dp_rows as $r) $dp['card'] = round($r[7] / 1e9, 1);
  // the next 10 days projected (owner 2026-11-01), drawn lighter: from the last measured day, the data folder grows by the
  // station's clips kept a day x the average clip and BirdSuite by the detections a day x the bytes a detection
  // (disk-forecast.json); the other parts stay as they are
  $dp['proj'] = array();
  $fc0 = json_decode((string)@file_get_contents(BS_DISK_FORECAST), true);
  if ($dp_rows) {
    $last = end($dp_rows);
    $grow = array(0, 0, 0, 0, 0, 0, 0);
    if (is_array($fc0) && isset($fc0['measured'])) {
      $grow[0] = ($fc0['rate_station'] ?? 0) * $fc0['measured']['clip_kb'] * 1000 / 1e9;  // the station's clips a day
      $grow[1] = $fc0['measured']['det_day'] * $fc0['det_bytes'] / 1e9;
    }
    $n_past = count($dp['days']);
    for ($k = 1; $k <= 10; $k++) $dp['days'][] = date('Y-m-d', strtotime($last[0] . " +$k days"));
    $lastIdx = array_search($last[0], $dp['days']);
    foreach ($dp['parts'] as $i => $part) {
      $vals = array_fill(0, count($dp['days']), null);
      $vals[$lastIdx] = round($last[$dp_cols[$i]] / 1e9, 2);  // joined to the last measured point
      for ($k = 1; $k <= 10; $k++) $vals[$n_past - 1 + $k] = round($last[$dp_cols[$i]] / 1e9 + $grow[$i] * $k, 2);
      $dp['proj'][] = $vals;
      $dp['parts'][$i]['data'] = array_merge($part['data'], array_fill(0, 10, null));
    }
  }
  // the axis starts on the first measured day (up to 90 back), so a young station's chart is not a strip at the edge
  $first = null;
  foreach ($dp['parts'] as $part) foreach ($part['data'] as $k => $v) if ($v !== null && ($first === null || $k < $first)) $first = $k;
  if ($first) {
    $dp['days'] = array_slice($dp['days'], $first);
    foreach ($dp['parts'] as $i => $part) $dp['parts'][$i]['data'] = array_slice($part['data'], $first);
    foreach ($dp['proj'] as $i => $v) $dp['proj'][$i] = array_slice($v, $first);
  }
  // capacity forecast (owner 2026-11-01): the trend of the daily lowest (what stays after each purge = the long-term
  // occupation), least squares over the days with data, from 2 days on (owner 2026-11-01: yesterday and today already
  // tell; marked preliminary under 7 days); under 0.05 GB a day = stable; otherwise when
  // it reaches the purge limit (PURGE_THRESHOLD, when the station purges) and the full disk
  $du_fc = '';
  $pts = array();
  foreach ($du['lo'] as $i => $v) if ($v !== null) $pts[] = array($i, $v);
  if ($du_rows && count($pts) < 2) {
    $du_fc = t('str_capacity_forecast_from_the_help');
  } elseif ($du_rows) {
    $n = count($pts); $sx = $sy = $sxy = $sxx = 0;
    foreach ($pts as $q) { $sx += $q[0]; $sy += $q[1]; $sxy += $q[0] * $q[1]; $sxx += $q[0] * $q[0]; }
    $slope = ($n * $sxx - $sx * $sx) ? ($n * $sxy - $sx * $sy) / ($n * $sxx - $sx * $sx) : 0;
    $last = end($pts)[1];
    $prelim = count($pts) < 7 ? ' ' . t('str_preliminary_days_of_data', array('n' => count($pts))) : '';
    if ($slope < 0.05) {
      $du_fc = t('str_long_term_stable_gb_a_day_after_help', array('gb' => fmt_num($slope, 2)));
    } else {
      $cf_du = get_config();
      $thr = (int)($cf_du['PURGE_THRESHOLD'] ?? 70);
      $days_full = max(0, ($du['size'] - $last) / $slope);
      $du_fc = t('str_long_term_growing_gb_a_day_help',
        array('gb' => fmt_num($slope, 2), 'n' => fmt_num(round($days_full)), 'date' => date('Y-m-d', strtotime('+' . round($days_full) . ' days'))));
      if ($thr > 0 && $thr < 100) {
        $days_thr = ($du['size'] * $thr / 100 - $last) / $slope;
        $du_fc .= '<br>' . ($days_thr > 0
          ? t('str_the_purge_limit_of_pct_is_help', array('p' => $thr, 'n' => fmt_num(round($days_thr))))
          : t('str_above_the_purge_limit_of_pct_help', array('p' => $thr)));
      }
    }
    $du_fc .= $prelim;
  } ?>
  <h3><?php echo t('str_disk_used'); ?></h3>
  <div class="box">
    <?php if (!$dp_rows) { ?>
    <div class="small"><?php echo t('str_no_data_yet'); ?><br><?php echo t('str_the_disk_is_measured_every_5_help'); ?></div>
    <?php } else { ?>
    <div class="small"><?php echo t('str_up_to_the_last_90_days_one_help'); ?><br><?php echo t("str_each_band_is_one_part_of_the_help"); ?></div>
    <div style="position:relative;height:340px;margin-top:6px"><canvas id="diskchart"></canvas></div>
    <div class="small" style="margin-top:6px"><b><?php echo $du_fc; ?></b></div>
    <?php } ?>
  </div>
  <?php // the disk size needed in the long term (owner 2026-11-01): the measured variables first, two columns to simulate
  // other values beside them, the results of the three below in the same table, then the calculation; computed by
  // scripts/disk_forecast.py after each nightly purge (state/disk-forecast.json), simulated by static/disk-sim.js
  $fc = json_decode((string)@file_get_contents(BS_DISK_FORECAST), true);
  if (is_array($fc) && isset($fc['measured'])) {
    $m = $fc['measured'];
    $inp = function ($k, $col, $step) use ($m, $fc) {
      return '<input type="number" min="0" step="' . $step . '" data-sim="' . $k . '" data-col="' . $col . '" value="' . htmlspecialchars((string)($m[$k] ?? $fc[$k] ?? ''), ENT_QUOTES) . '" style="width:6.5em">';
    };
    // each variable with where it comes from, in small type under its name (owner 2026-11-01)
    $vars = array(
      // the setting's own row in Settings › Defaults, as a link (owner 2026-11-01)
      array('cap', t('str_limit_of_clips_per_species'), t('str_setting_max_files_species') . ' (<a href="/?view=Defaults#dfl-MAX_FILES_SPECIES">' . menu_path('All settings') . '</a>)', fmt_num($m['cap']), '1'),
      array('species', t('str_species_identified_forecast'), t('str_species_with_clips_kept_plus_help', array('p' => $fc['species_margin_pct'])), fmt_num($m['species']) . '<br><small>' . t('str_seen', array('n' => fmt_num($fc['species_seen']))) . '</small>', '1'),
      array('det_day', t('str_detections_a_day_records_in_the_help'), t('str_daily_average_of_the_last_30_help'), fmt_num($m['det_day']), '100'),
      array('clip_kb', t('str_average_clip_audio_plus_help'), t('str_average_of_the_clips_kept'), fmt_num($m['clip_kb']), '10'),
      array('db_gb', t('str_database_size_today_gb'), t('str_detections_bytes_each', array('n' => fmt_num($fc['det_total']), 'b' => fmt_num($fc['det_bytes']))), fmt_num($m['db_gb'], 2), '0.1'),
      // the purge ceiling (owner 2026-11-01): from this share of the disk on, the station purges the oldest clips
      array('threshold', t('str_most_of_the_disk_used_purge_help'), t('str_setting_purge_threshold') . ' (<a href="/?view=Defaults#dfl-PURGE_THRESHOLD">' . menu_path('All settings') . '</a>)', fmt_num($m['threshold'] ?? $fc['threshold']) . '%<br><small>' . fmt_num($fc['disk_gb'] * ($m['threshold'] ?? $fc['threshold']) / 100, 0) . ' GB</small>', '5'),
      array('years', t('str_horizon_years'), t('str_how_far_ahead'), fmt_num($m['years']), '1'),
    );
    $res = array(
      // the clips a day per species is not a variable of its own (owner 2026-11-01): it is the measured pace of each
      // species; the station's clips a day follows from the species
      array('stationRate', t("str_the_stations_clips_kept_a_day_help")), array('clips', t('str_clips_kept')), array('capped', t('str_species_at_the_limit')), array('clipsGb', t('str_clips')),
      array('dbGb', t('str_database')), array('rest', t('str_everything_else_system_programs_help')),
      array('swing', t("str_the_days_clips_before_the_help")), array('needed', '<b>' . t('str_disk_needed_at_the_peak') . '</b>'),
      array('ceiling', t('str_purge_ceiling_on_this_disk')), array('fits', t('str_the_peak_stays_under_the_ceiling')),
      array('used', '<b>' . t('str_used_on_this_disk_at_the_peak_help') . '</b>'),
      array('free', t('str_left_empty_on_this_disk', array('size' => fmt_num($fc['disk_gb'], 0) . ' GB'))),
      array('rec', '<b>' . t('str_smallest_disk_that_fits') . '</b>'),
    ); ?>
  <h3><?php echo t('str_disk_size_needed_in_the_long_help'); ?></h3>
  <div class="box">
    <table class="stdtable disksim" data-nosort>
      <tr><th><?php echo t('str_variable'); ?></th><th><?php echo t('str_measured'); ?></th><th><?php echo t('str_simulation_a'); ?></th><th><?php echo t('str_simulation_b'); ?></th></tr>
      <?php foreach ($vars as $v) { ?>
      <tr><td><?php echo $v[1]; ?><br><small style="opacity:.75"><?php echo $v[2]; ?></small></td><td><?php echo $v[3]; ?></td><td><?php echo $inp($v[0], 'a', $v[4]); ?></td><td><?php echo $inp($v[0], 'b', $v[4]); ?></td></tr>
      <?php } ?>
      <tr><th><?php echo t('str_result'); ?></th><th></th><th></th><th></th></tr>
      <?php foreach ($res as $r) {
        // the answer on a band of its own, in bold (owner 2026-11-01)
        $rec = $r[0] === 'rec';
        if ($rec) { ?><tr><th colspan="4"></th></tr><?php } ?>
      <tr<?php echo $rec ? ' style="font-weight:bold;font-size:1.1em"' : ''; ?>><td><?php echo $r[1]; ?></td><?php foreach (array('m', 'a', 'b') as $col) { ?><td data-res="<?php echo $r[0]; ?>" data-col="<?php echo $col; ?>"></td><?php } ?></tr>
      <?php } ?>
    </table>
    <div class="small" style="margin-top:4px"><?php echo t('str_measured_after_the_nightly_help', array('date' => $fc['date'])); ?></div>
    <details class="small" style="margin-top:8px" open><summary><b><?php echo t('str_the_calculation'); ?></b></summary>
      <?php echo t("str_clips_kept_for_each_species_help"); ?><br>
      <?php echo t('str_the_sum_of_the_species_is_help'); ?><br>
      <?php echo t('str_clips_gb_clips_kept_x_average_help'); ?><br>
      <?php echo t('str_database_gb_the_database_today_help', array('b' => fmt_num($fc['det_bytes']))); ?><br>
      <?php echo t("str_the_days_clips_before_the_purge_help"); ?><br>
      <?php echo t('str_disk_needed_at_the_peak_help'); ?><br>
      <?php echo t('str_smallest_disk_that_fits_the_help', array('p' => $fc['threshold'])); ?><br>
      <?php echo t('str_on_this_disk_the_purge_ceiling_help'); ?><br>
      <?php echo t('str_the_clips_stop_growing_once_the_help'); ?>
    </details>
  </div>
  <script>window.DISK_FC = <?php echo json_encode($fc); ?>;</script>
  <script src="static/disk-sim.js?v=<?php echo @filemtime(__DIR__ . '/../homepage/static/disk-sim.js'); ?>"></script>
  <?php } ?>

  <?php // the logs (owner 2026-11-01): sizes, the retention (journal days and 200 MB, rotated logs compressed; applied
  // by birdsuite-root logs at install, on every update and when changed here), export and clean now
  $ls = array();
  foreach (explode("\n", (string)shell_exec('sudo -n ' . BS_ROOT_HELPER . ' logs-sizes 2>/dev/null')) as $l) {
    $p = explode(' ', trim($l)); if (count($p) === 2) $ls[$p[0]] = (float)$p[1];
  }
  $st_logs = 0;
  foreach (glob(BS_STATE . '/*.log*') ?: array() as $f) $st_logs += (float)@filesize($f);
  $mb = function ($b) { return fmt_num($b / 1e6, 1) . ' MB'; };
  $ret = (string)(get_config()['LOG_RETENTION_DAYS'] ?? '30'); ?>
  <h3><?php echo t('str_logs'); ?></h3>
  <div class="box">
    <table class="stdtable" style="max-width:520px">
      <tr><th><?php echo t('str_log'); ?></th><th><?php echo t('str_size'); ?></th></tr>
      <tr><td><?php echo t('str_system_journal'); ?></td><td><?php echo isset($ls['journal']) ? $mb($ls['journal']) : '?'; ?></td></tr>
      <tr><td><?php echo t('str_system_log_files_var_log'); ?></td><td><?php echo isset($ls['varlog']) ? $mb($ls['varlog']) : '?'; ?></td></tr>
      <tr><td><?php echo t("str_the_stations_own_logs"); ?></td><td><?php echo $mb($st_logs); ?></td></tr>
    </table>
    <div style="margin-top:8px"><label><?php echo t('str_keep_the_system_journal_for'); ?>
      <select data-setting="LOG_RETENTION_DAYS">
        <?php // a year and manual (owner 2026-11-01): manual = no time limit, cleaned only by Clean now, named "Until I clean"
        foreach (array('7' => t('str_n_days', array('n' => 7)), '30' => t('str_n_days', array('n' => 30)), '90' => t('str_n_days', array('n' => 90)),
                       '365' => t('str_a_year'), '0' => t('str_until_i_clean')) as $d => $lbl)
          echo '<option value="' . $d . '"' . ($ret === (string)$d ? ' selected' : '') . '>' . $lbl . '</option>'; ?>
      </select></label></div>
    <div class="small" style="margin-top:4px"><?php echo t('str_the_journal_never_passes_200_mb_help'); ?><br><?php echo t('str_older_entries_are_removed_help'); ?></div>
    <div style="display:flex;gap:8px;flex-wrap:wrap;margin-top:8px">
      <a class="stdbtn" href="scripts/disk_space.php?logs=export" download><?php echo t('str_export_logs'); ?></a>
      <button type="button" class="stdbtn del" id="logsclean"><?php echo t('str_clean_now'); ?></button>
      <span id="logsmsg" class="small"></span>
    </div>
  </div>
  <script src="static/species-modal.js?v=<?php echo @filemtime(__DIR__ . '/../homepage/static/species-modal.js'); ?>"></script>
  <script>
  document.getElementById('logsclean').addEventListener('click', function () {
    var msg = document.getElementById('logsmsg');
    askModal(t('str_clean_the_logs_now'), t('str_the_system_journal_is_cut_to_help'), t('str_clean_now'), true).then(function (ok) {
      if (!ok) return;
      msg.textContent = t('str_cleaning');
      fetch('scripts/disk_space.php?logs=clean', {method: 'POST', credentials: 'same-origin'})
        .then(function (r) { return r.status === 401 ? t('str_log_in_first') : r.text(); })
        .then(function (r) { if (String(r).trim() === 'OK') location.reload(); else msg.textContent = r; });
    });
  });
  </script>
</div>
<?php if ($dp_rows) { ?>
<script src="static/Chart.bundle.js"></script>
<script>
(function () {
  var dp = <?php echo json_encode($dp); ?>, el = document.getElementById('diskchart');
  if (!el || typeof Chart === 'undefined') return;
  var ink = getComputedStyle(document.body).color || '#333';
  var gb = function (v) { return (window.fmtNum ? window.fmtNum(v, 0) : v.toFixed(0)) + ' GB'; };
  var gb1 = function (v) { return (window.fmtNum ? window.fmtNum(v, 1) : v.toFixed(1)) + ' GB'; };
  var colors = ['#1e88e5', '#43a047', '#e53935', '#fb8c00', '#8e24aa', '#757575', '#c0ca33'];
  // one fixed scale for both stacks: 0 to the card size, rounded up to 50 GB
  var ymax = Math.ceil((dp.card || 100) / 50) * 50;
  // the card's size written at the left, just above its black line (owner 2026-11-01)
  var sizeLabel = {afterDatasetsDraw: function (c) {
    if (!dp.card || !c.scales.size) return;
    var x = c.chartArea.left + 4, y = c.scales.size.getPixelForValue(dp.card) - 4, g = c.ctx;
    g.save(); g.fillStyle = ink; g.font = 'bold 12px sans-serif'; g.textAlign = 'left'; g.textBaseline = 'bottom';
    g.fillText(t('str_card_size') + ': ' + gb(dp.card), x, y); g.restore();
  }};
  new Chart(el.getContext('2d'), {
    type: 'line',
    plugins: [sizeLabel],
    data: {
      labels: dp.days,
      // the measured days stack on the 'past' axis, the projection on its own 'proj' axis (same scale), lighter and dashed
      datasets: dp.parts.map(function (p, i) {
        // a stacked 2-D area as in Excel (owner 2026-11-01): solid bands, no points
        return {label: p.label, data: p.data, yAxisID: 'past', borderColor: colors[i], backgroundColor: colors[i] + 'dd', fill: i ? '-1' : 'origin',
          borderWidth: 1, pointRadius: 0, pointHitRadius: 6, lineTension: 0, spanGaps: false};
      }).concat(dp.proj.map(function (d, i) {
        return {label: dp.parts[i].label + ' ' + t('str_projection'), data: d, yAxisID: 'proj', borderColor: colors[i] + '99', backgroundColor: colors[i] + '66',
          fill: i ? '-1' : 'origin', borderWidth: 1, borderDash: [4, 3], pointRadius: 0, pointHitRadius: 6, lineTension: 0, spanGaps: false, proj: true};
      })).concat(dp.card ? [{
        // the card's size as a black line (owner 2026-11-01), on an axis of its own so it is not stacked
        label: t('str_card_size'), data: dp.days.map(function () { return dp.card; }), yAxisID: 'size', borderColor: ink,
        backgroundColor: ink, fill: false, borderWidth: 2, pointRadius: 0, pointHitRadius: 0, lineTension: 0, size: true}] : [])
    },
    options: {
      maintainAspectRatio: false,
      legend: {position: 'bottom', labels: {fontColor: ink, boxWidth: 12, filter: function (it, data) { return !data.datasets[it.datasetIndex].proj; }}},  // the size line keeps its legend entry
      // one set per day: the measured parts, or the projection on a future day (the last measured day carries both)
      tooltips: {mode: 'index', intersect: false, filter: function (it, data) {
        if (it.yLabel === null || it.yLabel === undefined || isNaN(it.yLabel)) return false;
        if (data.datasets[it.datasetIndex].size) return false;
        return it.datasetIndex < dp.parts.length || dp.parts[0].data[it.index] === null;
      }, callbacks: {
        label: function (it, data) { return data.datasets[it.datasetIndex].label + ': ' + gb1(it.yLabel); },
        // the total of the measured stack, or of the projection on a future day (the last measured day has both)
        footer: function (items) { var s = 0;
          items.forEach(function (it) { s += it.yLabel || 0; }); return t('str_card_total') + ': ' + gb1(s) + (dp.card ? ' / ' + gb(dp.card) + ' (' + (window.fmtNum ? window.fmtNum(100 * s / dp.card, 0) : Math.round(100 * s / dp.card)) + '%)' : ''); }
      }},
      scales: {
        xAxes: [{ticks: {fontColor: ink, autoSkip: true, maxTicksLimit: 8, maxRotation: 0, callback: function (v) { return v.substring(8) + '/' + v.substring(5, 7); }}, gridLines: {display: false}}],
        yAxes: [{id: 'past', stacked: true, ticks: {fontColor: ink, maxTicksLimit: 6, min: 0, max: ymax, callback: function (v) { return gb(v); }}, gridLines: {color: 'rgba(128,128,128,.2)'}},
                {id: 'proj', stacked: true, display: false, ticks: {min: 0, max: ymax}},
                {id: 'size', stacked: false, display: false, ticks: {min: 0, max: ymax}}]
      }
    }
  });
})();
</script>
<?php } ?>
