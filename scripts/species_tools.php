<?php
/* Basic input sanitation */
$_GET  = filter_input_array(INPUT_GET, FILTER_SANITIZE_FULL_SPECIAL_CHARS) ?: [];
$_POST = filter_input_array(INPUT_POST, FILTER_SANITIZE_FULL_SPECIAL_CHARS) ?: [];

require_once __DIR__ . '/common.php';
/* Species Pages (owner 2026-10-09: the species list and Species Management are one page, view=Species): anyone sees the
 * list; every change (tier, threshold, lists) needs the login; removing a species goes through play.php (login there) */
foreach (['settier', 'setconf', 'setconfs', 'setprob', 'setfiles', 'toggle', 'getcounts'] as $action) {
  if (isset($_GET[$action])) ensure_authenticated();
}
// the changes come by POST only (2026-11-02): a link or an image tag of another page can not make one
foreach (['settier', 'setconf', 'setconfs', 'setprob', 'setfiles', 'toggle'] as $action) {
  if (isset($_GET[$action]) && $_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); exit; }
}

$home = get_home();

/* ---------- disk species counts ---------- */
// one pass over By_Date/<date>/<species>/, exact counts per species folder; computed here and written into the table
// (owner 2026-10-22: filled afterwards by the page's script, the column stayed "…" in the owner's browser), kept 5 min
function disk_counts($home) {
    $cache = sys_get_temp_dir() . '/birdsuite_diskcounts.json';
    if (is_file($cache) && time() - filemtime($cache) < 300) {
        $c = json_decode((string)@file_get_contents($cache), true);
        if (is_array($c)) return $c;
    }
    $base = realpath(BS_BY_DATE);
    $counts = [];
    if ($base !== false) {
        $out = (string)@shell_exec('find ' . escapeshellarg($base) . " -mindepth 3 -maxdepth 3 -type f ! -iname '*.png' ! -iname '*.jpg' -printf '%h\\n' 2>/dev/null");
        foreach (explode("\n", $out) as $dir) {
            if ($dir === '') continue;
            $name = str_replace('_', ' ', basename($dir));
            $counts[$name] = ($counts[$name] ?? 0) + 1;
        }
    }
    @file_put_contents($cache, json_encode($counts, JSON_UNESCAPED_UNICODE));
    return $counts;
}
if (isset($_GET['diskcounts'])) {
    header('Content-Type: application/json');
    session_write_close();
    echo json_encode(disk_counts($home), JSON_UNESCAPED_UNICODE);
    exit;
}

/* ---------- DB open (read-only) ---------- */
$db   = new SQLite3(BS_DB, SQLITE3_OPEN_READONLY);
apply_location_filter($db); // the location chosen in the header (US-58)
$db->busyTimeout(1000);

/* Paths / lists */
$base_symlink   = BS_BY_DATE;
$base           = realpath($base_symlink);

$confirm_file   = BS_LISTS . '/confirmed_species_list.txt';
$exclude_file   = BS_LISTS . '/exclude_species_list.txt';
$whitelist_file = BS_LISTS . '/whitelist_species_list.txt';
$tiers_file     = BS_LISTS . '/notification_tiers.txt';
$conf_file      = BS_LISTS . '/species_confidence.txt';

// a missing list is an empty one (nothing is written by opening the page)

/* Notification tiers: 'Sci_Name=tier' per non-normal species (muted/rare) */
$species_tiers = [];
// a species without its own line follows the default tier of Settings (Notifications)
$default_tier_view = strtolower(get_config()['NOTIFICATION_DEFAULT_TIER'] ?? 'normal');
if (!in_array($default_tier_view, ['normal', 'muted', 'rare'], true)) $default_tier_view = 'normal';
foreach (file_exists($tiers_file) ? file($tiers_file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) : [] as $l) {
    [$t_sci, $t_tier] = array_pad(explode('=', trim($l), 2), 2, '');
    if ($t_sci !== '' && $t_tier !== '') $species_tiers[$t_sci] = strtolower($t_tier);
}

/* US-48: per-species minimum confidence, 'Sci_Name=0.60' per listed species */
$species_conf = [];
foreach (file_exists($conf_file) ? file($conf_file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) : [] as $l) {
    [$c_sci, $c_val] = array_pad(explode('=', trim($l), 2), 2, '');
    if ($c_sci !== '' && is_numeric($c_val)) $species_conf[$c_sci] = (float)$c_val;
}

$confirmed_species   = file_exists($confirm_file)   ? file($confirm_file,   FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) : [];
$excluded_species = file_exists($exclude_file) ? array_map(fn($l) => explode('_', trim($l), 2)[0], file($exclude_file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES)) : [];
$whitelisted_species = file_exists($whitelist_file) ? array_map(fn($l) => explode('_', trim($l), 2)[0], file($whitelist_file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES)) : [];

$config    = get_config();
$sf_thresh = isset($config['SF_THRESH']) ? (float)$config['SF_THRESH'] : 0.0;
$global_conf = isset($config['CONFIDENCE']) ? (float)$config['CONFIDENCE'] : 0.7;

/* ---------- helpers ---------- */
function join_path(...$parts): string { return preg_replace('#/+#', '/', implode('/', $parts)); }
function can_unlink(string $p): bool { return is_link($p) || is_file($p); }

/* Detections and clips of a species (the delete question): the number from the database, the clips from the per-folder
 * counts of disk_counts() (one find, kept 5 min) instead of two file checks per detection (2026-10-23 test run: 1-1.4 s
 * for the commonest species, about 10 s at ten times the station's data) */
function species_counts(SQLite3 $db, string $species, string $home): array {
  $stmt = $db->prepare('SELECT COUNT(*) FROM detections WHERE Sci_Name = :name');
  ensure_db_ok($stmt);
  $stmt->bindValue(':name', $species, SQLITE3_TEXT);
  $row = $stmt->execute()->fetchArray(SQLITE3_NUM);
  // the species folder (scientific name)
  $files = disk_counts($home)[str_replace("'", '', $species)] ?? 0;
  return ['count' => (int)($row[0] ?? 0), 'files' => $files];
}

/* ---------- set notification tier (Muted/Normal/Rare) ---------- */
if (isset($_GET['settier'], $_GET['species'], $_GET['tier'])) {
  $species = htmlspecialchars_decode($_GET['species'], ENT_QUOTES);
  if ($species === '' || strpbrk($species, "=\n\r") !== false) { header('Content-Type: text/plain'); echo t('str_invalid_species'); exit; }
  $tier    = strtolower($_GET['tier']);
  if (!in_array($tier, ['normal', 'muted', 'rare'], true)) { header('Content-Type: text/plain'); echo t('str_invalid_tier'); exit; }
  $lines = file_exists($tiers_file) ? file($tiers_file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) : [];
  $lines = array_values(array_filter($lines, fn($l) => explode('=', trim($l), 2)[0] !== $species));
  // a line only when the tier differs from the default one (NOTIFICATION_DEFAULT_TIER); "normal" on a station
  // whose default is muted must be written, or the species would stay muted
  $default_tier = strtolower(get_config()['NOTIFICATION_DEFAULT_TIER'] ?? 'normal');
  if (!in_array($default_tier, ['normal', 'muted', 'rare'], true)) $default_tier = 'normal';
  if ($tier !== $default_tier) $lines[] = $species . '=' . $tier;
  sort($lines, SORT_STRING);
  file_put_contents($tiers_file, implode("\n", $lines) . (empty($lines) ? "" : "\n"), LOCK_EX);
  header('Content-Type: text/plain'); echo 'OK'; exit;
}

/* ---------- set per-species minimum confidence (US-48) ---------- */
if (isset($_GET['setconf'], $_GET['species'], $_GET['value'])) {
  header('Content-Type: text/plain');
  $species = htmlspecialchars_decode($_GET['species'], ENT_QUOTES);
  if ($species === '' || strpbrk($species, "=\n\r") !== false) { echo t('str_invalid_species'); exit; }
  $value = trim(str_replace(',', '.', $_GET['value']));
  if ($value !== '' && (!is_numeric($value) || (float)$value < 0.01 || (float)$value > 0.99)) { echo t('str_invalid_value_0_01_0_99_empty_help'); exit; }
  $lines = file_exists($conf_file) ? file($conf_file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) : [];
  $lines = array_values(array_filter($lines, fn($l) => explode('=', trim($l), 2)[0] !== $species));
  if ($value !== '') $lines[] = $species . '=' . sprintf('%.2f', round((float)$value, 2));
  sort($lines, SORT_STRING);
  file_put_contents($conf_file, implode("\n", $lines) . (empty($lines) ? "" : "\n"), LOCK_EX);
  echo 'OK'; exit;
}

/* ---------- several species thresholds at once (owner 2026-10-28, Validation › Calibrate thresholds): POST body
   {"Sci_Name": 0.55, ...}, checked like setconf, written in one pass ---------- */
if (isset($_GET['setconfs']) && $_SERVER['REQUEST_METHOD'] === 'POST') {
  header('Content-Type: text/plain');
  $set = json_decode((string)file_get_contents('php://input'), true);
  if (!is_array($set) || !$set) { echo t('str_nothing_to_save'); exit; }
  foreach ($set as $species => $value) {
    if (!is_string($species) || $species === '' || strpbrk($species, "=\n\r") !== false) { echo t('str_invalid_species'); exit; }
    if (!is_numeric($value) || (float)$value < 0.01 || (float)$value > 0.99) { echo t('str_invalid_value_0_01_0_99_empty_help'); exit; }
  }
  $lines = file_exists($conf_file) ? file($conf_file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) : [];
  $lines = array_values(array_filter($lines, fn($l) => !array_key_exists(explode('=', trim($l), 2)[0], $set)));
  foreach ($set as $species => $value) $lines[] = $species . '=' . sprintf('%.2f', round((float)$value, 2));
  sort($lines, SORT_STRING);
  file_put_contents($conf_file, implode("\n", $lines) . "\n", LOCK_EX);
  echo 'OK'; exit;
}

/* ---------- minimum probability of occurrence for one species (owner 2026-10-22): species_min_prob.txt, Sci_Name=0.05,
   empty = the station Min. Prob.; utils/analysis.py drops the detections while the week's probability is below it ---------- */
if (isset($_GET['setprob'], $_GET['species'], $_GET['value'])) {
  header('Content-Type: text/plain');
  $species = htmlspecialchars_decode($_GET['species'], ENT_QUOTES);
  if ($species === '' || strpbrk($species, "=\n\r") !== false) { echo t('str_invalid_species'); exit; }
  $value = trim(str_replace(',', '.', $_GET['value']));
  if ($value !== '' && (!is_numeric($value) || (float)$value < 0.001 || (float)$value > 0.99)) { echo t('str_invalid_value_0_001_0_99_empty_help'); exit; }
  $file = BS_LISTS . '/species_min_prob.txt';
  $lines = file_exists($file) ? file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) : [];
  $lines = array_values(array_filter($lines, fn($l) => explode('=', trim($l), 2)[0] !== $species));
  if ($value !== '') $lines[] = $species . '=' . sprintf('%.3f', round((float)$value, 3));
  sort($lines, SORT_STRING);
  file_put_contents($file, implode("\n", $lines) . (empty($lines) ? "" : "\n"), LOCK_EX);
  echo 'OK'; exit;
}

/* ---------- files to keep for one species (owner 2026-10-21): species_max_files.txt, Sci_Name=N, empty = global ---------- */
if (isset($_GET['setfiles'], $_GET['species'], $_GET['value'])) {
  header('Content-Type: text/plain');
  $species = htmlspecialchars_decode($_GET['species'], ENT_QUOTES);
  if ($species === '' || strpbrk($species, "=\n\r") !== false) { echo t('str_invalid_species'); exit; }
  $value = trim($_GET['value']);
  if ($value !== '' && (!ctype_digit($value) || (int)$value > 100000)) { echo t('str_invalid_value_0_100000_empty_global'); exit; }
  $file = BS_LISTS . '/species_max_files.txt';
  $lines = file_exists($file) ? file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) : [];
  $lines = array_values(array_filter($lines, fn($l) => explode('=', trim($l), 2)[0] !== $species));
  if ($value !== '') $lines[] = $species . '=' . (int)$value;
  sort($lines, SORT_STRING);
  file_put_contents($file, implode("\n", $lines) . (empty($lines) ? "" : "\n"), LOCK_EX);
  echo 'OK'; exit;
}

/* ---------- toggle exclude/whitelist/confirmed ---------- */
if (isset($_GET['toggle'], $_GET['species'], $_GET['action'])) {
  $list    = $_GET['toggle'];
  $species = htmlspecialchars_decode($_GET['species'], ENT_QUOTES);

  if     ($list === 'exclude')   { $file = $exclude_file; }
  elseif ($list === 'whitelist') { $file = $whitelist_file; }
  elseif ($list === 'confirmed') { $file = $confirm_file; }
  else { header('Content-Type: text/plain'); echo t('str_invalid_list_type'); exit; }

  // by the scientific name, the list kept sorted (a renamed common name still finds its line)
  update_species_list($file, array($species), $_GET['action'] === 'add');
  header('Content-Type: text/plain'); echo 'OK'; exit;
}

/* ---------- count ---------- */
if (isset($_GET['getcounts'])) {
  header('Content-Type: application/json');
  if ($base === false) { http_response_code(500); exit(json_encode(['error' => 'Base directory not found'])); }
  $species = htmlspecialchars_decode($_GET['getcounts'], ENT_QUOTES);
  echo json_encode(species_counts($db, $species, $home)); exit;
}

/* ---------- delete ----------
 * gone (owner 2026-10-19): the species' delete icon removes like Curation's Remove all — play.php?deletespecies= moves
 * clips and lines to the Removed folder / deleted_detections; nothing is deleted for good outside Species › Purge Files */

/* ---------- query species aggregates ---------- */
// Detected (default) = the species with detections; All (owner 2026-10-09) = also every species the station can detect:
// the active model's labels, limited to the include list (Custom Species List) when it has species, minus the exclude
// list — the location threshold is ignored. Both are sorted from the most to the least recorded.
$scope = ($_GET['scope'] ?? '') === 'all' ? 'all' : 'detected';
$rows = [];
// the rows per species and day kept per month (common.php cached_past_months), today's read afresh, then added up
// here (2026-10-23 run 3: 3.2 s at ten times the station's data on every view)
$day_rows = function ($where, $p) use ($db) {
  $st = $db->prepare("SELECT Sci_Name, Date, COUNT(*), MIN(Confidence), TOTAL(Confidence), COUNT(Confidence), MAX(Confidence) FROM detections WHERE $where GROUP BY Sci_Name, Date");
  foreach ($p as $k => $v) $st->bindValue($k, $v);
  $out = [];
  $res = $st->execute();
  while ($res && ($x = $res->fetchArray(SQLITE3_NUM))) $out[] = $x;
  return $out;
};
$chunks = cached_past_months('species_tools_days', '', '', past_month_changes(), function ($lo, $hi) use ($day_rows) { return $day_rows('Date BETWEEN :lo AND :hi', [':lo' => $lo, ':hi' => $hi]); });
$chunks[] = $day_rows('Date >= :today', [':today' => date('Y-m-d')]);
$acc = [];
foreach ($chunks as $chunk) foreach ($chunk as $x) {
  list($s, $d, $n, $mn, $tot, $cn, $mx) = $x;
  if (!isset($acc[$s])) $acc[$s] = ['Sci_Name' => $s, 'Count' => 0, 'MinConfidence' => null, 's' => 0.0, 'c' => 0, 'MaxConfidence' => null, 'LastSeen' => $d, 'FirstSeen' => $d, 'Days' => 0];
  $a = &$acc[$s];
  $a['Count'] += $n; $a['s'] += $tot; $a['c'] += $cn; $a['Days']++;
  if ($mn !== null && ($a['MinConfidence'] === null || $mn < $a['MinConfidence'])) $a['MinConfidence'] = $mn;
  if ($mx !== null && ($a['MaxConfidence'] === null || $mx > $a['MaxConfidence'])) $a['MaxConfidence'] = $mx;
  if ($d > $a['LastSeen']) $a['LastSeen'] = $d;
  if ($d < $a['FirstSeen']) $a['FirstSeen'] = $d;
  unset($a);
}
foreach ($acc as $s => $a) {
  $a['AvgConfidence'] = $a['c'] ? $a['s'] / $a['c'] : null;
  unset($a['s'], $a['c']);
  $rows[$s] = $a;
}
foreach (species_com_names(array_keys($rows)) as $s_sci => $s_com) $rows[$s_sci] = array('Com_Name' => $s_com) + $rows[$s_sci];
$confirmed_set = array_flip($confirmed_species);
$excluded_set = array_flip($excluded_species);
$whitelisted_set = array_flip($whitelisted_species);
if ($scope === 'all') {
  $label_name = active_model_labels();
  $include = [];
  foreach (@file(BS_LISTS . '/include_species_list.txt', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $l) {
    $include[explode('_', trim($l), 2)[0]] = true;
  }
  foreach ($label_name as $sci => $name) {
    if (isset($rows[$sci]) || isset($excluded_set[$sci]) || ($include && !isset($include[$sci]))) continue;
    $rows[$sci] = ['Com_Name' => common_name($sci), 'Sci_Name' => $sci, 'Count' => 0, 'MaxConfidence' => null, 'LastSeen' => '', 'FirstSeen' => '', 'Days' => 0];
  }
}
uasort($rows, fn($a, $b) => ($b['Count'] <=> $a['Count']) ?: strcmp($a['Com_Name'], $b['Com_Name']));
?>
<style>
  .circle-icon{display:inline-block;width:12px;height:12px;border:1px solid #777;border-radius:50%;cursor:pointer;}
  /* left-aligned beside the side menu, smaller type (owner 2026-10-08) */
  .centered{max-width:none;margin:0 4px}
  /* never wider than the window (2026-10-23 test run): the headers and the names wrap (the sticky header keeps working);
     below 1000 px the table scrolls inside its box instead of the page */
  #speciesTable th{white-space:normal !important;min-width:3.5em}
  #speciesTable td:first-child{white-space:normal !important;min-width:10em}
  /* the table scrolls inside its box at every width when longer texts (another language) make it wider than the window
     (release run 5): the page itself never scrolls sideways */
  .centered{overflow-x:auto}
  /* the table standard (stdtable + std-table.js + species name picklist), compact type (owner 2026-10-09) */
  #speciesTable{font-size:12px;width:auto}
  #speciesTable th,#speciesTable td{padding:3px 6px}
  #speciesTable select,#speciesTable input{font-size:12px}
  /* table standard: list buttons on the left, the filter on the right, both on the table's width */
  .spm{display:inline-block;max-width:100%}
  .toolbar{display:flex;gap:8px;align-items:center;justify-content:space-between;margin:8px 0;flex-wrap:wrap}
  .toolbar .right{display:flex;gap:8px;align-items:center}
  /* the search behind the Filter icon, as the other pages (owner 2026-10-21) */
  .toolbar:not(.fopen) #q, .toolbar:not(.fopen) #matchCount { display: none !important; }
  .toolbar .dashfilter { cursor: pointer; border-radius: 50%; padding: 3px; display: inline-flex; color: var(--accent,#2b5e22); }
  .toolbar.fopen .dashfilter { background: var(--accent,#2b5e22); color: #fff; }
  @media screen and (max-width: 800px) { .toolbar.fopen #q { order: 100; flex: 1 1 100%; width: auto; } .toolbar.fopen #matchCount { order: 97; } }
  .toolbar input[type="text"]{padding:5px 8px;width:260px;max-width:100%;font-size:14px}
  .toolbar .nowmodes button{padding:4px 12px;font-size:12px;border-radius:12px;height:auto;line-height:normal;margin:0}
  .toolbar .hmsort button{padding:3px 12px;font-size:12px;height:auto;line-height:normal}
  #speciesTable td{white-space:nowrap}
  .spnew{font-size:11px;background:#a35400;color:#fff;border-radius:8px;padding:0 6px;margin-left:4px}
</style>

<div class="centered"><div class="spm">
  <?php echo location_label(); // the location shown (owner 2026-10-21) ?>
  <div class="toolbar sortbar">
    <span class="nowmodes"><button type="button" class="<?php echo $scope === 'detected' ? 'active' : ''; ?>" onclick="location.href='views.php?view=Species'" title="<?php echo t('str_species_with_detections'); ?>"><?php echo t('str_detected'); ?></button><button type="button" class="<?php echo $scope === 'all' ? 'active' : ''; ?>" onclick="location.href='views.php?view=Species&amp;scope=all'" title="<?php echo t('str_every_species_the_station_can_help'); ?>"><?php echo t('str_all'); ?></button></span>
    <span class="right"><?php echo filter_toggle() . sort_toggle(); ?><span class="hmsort" data-table="speciesTable" data-store="speciespages" data-default="n" data-remember="0" title="<?php echo t('str_opens_on_count_most_first_a_help'); ?>"><small><?php echo t('str_sort_colon'); ?></small><button type="button" data-sort="n"><?php echo t('str_count'); ?></button><button type="button" data-sort="tax" title="<?php echo t('str_field_guide_order_ebird_help'); ?>"><?php echo t('str_taxonomy'); ?></button><button type="button" data-sort="az" title="<?php echo t('str_alphabetical_by_the_names_shown'); ?>">A-Z</button></span><small id="matchCount"></small><input id="q" type="search" class="hmfilter spfilter" data-table="speciesTable" placeholder="<?php echo t('str_filter_species_common_help'); ?>" title="<?php echo t('str_type_to_filter_persists_across_help'); ?>"></span>
  </div>

  <?php // the standard pager (owner 2026-11-03, release run 7): a sort or a filter still works on every species, only one
  // page of rows is drawn, so a column click stays fast with hundreds of species ?>
  <table id="speciesTable" class="stdtable" data-pager="<?php echo htmlspecialchars(t('str_select_species_per_page', array('select' => '{select}')), ENT_QUOTES); ?>">
    <thead>
      <tr>
        <th><select class="namemode" data-remember="0" data-default="com" title="<?php echo t('str_names_shown_opens_on_common_name'); ?>"><option value="com"><?php echo t('str_common_name'); ?></option><option value="sci"><?php echo t('str_scientific_name'); ?></option><option value="en"><?php echo t('str_english_name'); ?></option></select></th>
        <th class="r"><?php echo t('str_count'); ?></th>
        <th class="r" title="<?php echo t('str_days_with_detections'); ?>"><?php echo t('str_days'); ?></th>
        <?php // Files right after Count / Days, in sight without scrolling (owner 2026-10-22: the column at the far right looked empty) ?>
        <th class="r" title="<?php echo t('str_clip_files_on_disk'); ?>"><?php echo t('str_files'); ?></th>
        <th class="r" title="<?php echo t('str_lowest_confidence_of_its_detections'); ?>"><?php echo t('str_lowest_conf'); ?></th>
        <th class="r" title="<?php echo t('str_average_confidence_of_its_detections'); ?>"><?php echo t('str_avg_conf'); ?></th>
        <th class="r" title="<?php echo t('str_highest_confidence_of_its_detections'); ?>"><?php echo t('str_max_conf'); ?></th>
        <th class="r" title="<?php echo htmlspecialchars(t('str_minimum_confidence_manual_help', array('value' => fmt_num($global_conf, 2))), ENT_QUOTES); ?>"><?php echo t('str_manual_conf'); ?></th>
        <th class="r"><?php echo t('str_probability'); ?></th>
        <th><?php echo t('str_notification'); ?></th>
        <th><?php echo t('str_confirmed'); ?></th>
        <th><?php echo t('str_whitelist'); ?></th>
        <th title="<?php echo t('str_ticked_in_its_past_detections_help'); ?>"><?php echo t('str_excluded_sp'); ?></th>
        <th data-nosort><?php echo t('str_delete_title'); ?></th>
      </tr>
    </thead>
    <tbody>
<?php $diskcounts = disk_counts($home);
foreach ($rows as $row) {
  $common = $row['Com_Name'];
  $scient = $row['Sci_Name'];
  $count  = (int)$row['Count'];
  $files  = (int)($diskcounts[str_replace("'", '', $row['Sci_Name'])] ?? 0);
  $max_confidence = $row['MaxConfidence'] === null ? '' : round((float)$row['MaxConfidence'] * 100, 1);
  // the lowest and the average confidence of its detections (owner 2026-10-22); "lowest", not "Min. Conf." (the threshold)
  $low_confidence = ($row['MinConfidence'] ?? null) === null ? '' : round((float)$row['MinConfidence'] * 100, 1);
  $avg_confidence = ($row['AvgConfidence'] ?? null) === null ? '' : round((float)$row['AvgConfidence'] * 100, 1);
  $identifier = $row['Sci_Name']; // the lists hold the scientific name only (owner 2026-11-05)
  $identifier_sci = $row['Sci_Name'];

  $lastSeen = $row['LastSeen'] ?? '';
  $lastSeenSort = $lastSeen ? (strtotime($lastSeen) ?: 0) : 0;

  $english = get_english_name($row['Sci_Name']);
  $common_link = "<a href='views.php?view=Species&amp;sci=" . rawurlencode($row['Sci_Name']) . "' title='" . htmlspecialchars(t('str_open_the_species_page'), ENT_QUOTES) . "' data-com=\"" . htmlspecialchars($common, ENT_QUOTES)
    . "\" data-sci=\"" . htmlspecialchars($scient, ENT_QUOTES) . "\" data-en=\"" . htmlspecialchars($english, ENT_QUOTES) . "\">{$common}</a>";

  $is_confirmed   = isset($confirmed_set[$identifier_sci]);
  $is_excluded    = isset($excluded_set[$identifier_sci]);
  $is_whitelisted = isset($whitelisted_set[$identifier_sci]);


  $identifier_js = addslashes($identifier);
  $identifier_sci_js = addslashes($identifier_sci);

  // the tick icons are named for the keyboard and screen readers (2026-10-23 test run; static/ui-standard.js makes them
  // buttons): what the column is, ticked or not, what a click does
  $tick_name = function ($what, $on) use ($common) {
    return htmlspecialchars(($on ? t('str_what_ticked_click_to_untick', array('what' => $what)) : t('str_not_ticked_click_to_tick', array('what' => $what))) . ' (' . $common . ')', ENT_QUOTES);
  };
  $confirm_cell = $is_confirmed
    ? "<img style='cursor:pointer;max-width:12px;max-height:12px' src='images/check.svg' alt='" . $tick_name(t('str_confirmed'), true) . "' title='" . $tick_name(t('str_confirmed'), true) . "' onclick=\"toggleSpecies('confirmed','{$identifier_sci_js}','del')\">"
    : "<span class='circle-icon' aria-label='" . $tick_name(t('str_confirmed'), false) . "' title='" . $tick_name(t('str_confirmed'), false) . "' onclick=\"toggleSpecies('confirmed','{$identifier_sci_js}','add')\"></span>";

  $excl_cell = $is_excluded
    ? "<img style='cursor:pointer;max-width:12px;max-height:12px' src='images/check.svg' alt='" . $tick_name(t('str_excluded_sp'), true) . "' title='" . $tick_name(t('str_excluded_sp'), true) . "' onclick=\"toggleSpecies('exclude','{$identifier_js}','del')\">"
    : "<span class='circle-icon' aria-label='" . $tick_name(t('str_excluded_sp'), false) . "' title='" . $tick_name(t('str_excluded_sp'), false) . "' onclick=\"toggleSpecies('exclude','{$identifier_js}','add')\"></span>";

  $white_cell = $is_whitelisted
    ? "<img style='cursor:pointer;max-width:12px;max-height:12px' src='images/check.svg' alt='" . $tick_name(t('str_whitelist'), true) . "' title='" . $tick_name(t('str_whitelist'), true) . "' onclick=\"toggleSpecies('whitelist','{$identifier_js}','del')\">"
    : "<span class='circle-icon' aria-label='" . $tick_name(t('str_whitelist'), false) . "' title='" . $tick_name(t('str_whitelist'), false) . "' onclick=\"toggleSpecies('whitelist','{$identifier_js}','add')\"></span>";

  $species_tier = $species_tiers[$identifier_sci] ?? $default_tier_view;
  $tier_cell = "<select data-was=\"" . htmlspecialchars($species_tier, ENT_QUOTES) . "\" aria-label=\"" . htmlspecialchars(t('str_notification') . ' (' . $common . ')', ENT_QUOTES) . "\" onchange=\"setTier('{$identifier_sci_js}', this)\">";
  foreach (['normal' => t('str_normal_tier'), 'muted' => t('str_muted_tier'), 'rare' => t('str_rare_tier')] as $t_val => $t_label) {
    $t_sel = $species_tier === $t_val ? " selected" : "";
    $tier_cell .= "<option value='{$t_val}'{$t_sel}>{$t_label}</option>";
  }
  $tier_cell .= "</select>";

  $own_conf  = $species_conf[$identifier_sci] ?? null;
  $conf_sort = sprintf('%.2f', $own_conf ?? $global_conf);
  $conf_val  = $own_conf === null ? '' : sprintf('%.2f', $own_conf);
  $conf_cell = "<input type='number' class='minconf' min='0.01' max='0.99' step='0.01' style='width:5em'"
             . " placeholder='" . fmt_num($global_conf, 2) . "' data-global='" . sprintf('%.2f', $global_conf) . "' value='{$conf_val}'"
             . " title='" . htmlspecialchars(t('str_empty_global_value', array('value' => fmt_num($global_conf, 2))), ENT_QUOTES) . "'"
             . " onchange=\"setConf('{$identifier_sci_js}', this)\">";

  // location model probability this week (region_profile.json, as in the detection lists) — computed here, so the
  // column needs no second request (that one needed the login and stayed 0.0000 for visitors, owner 2026-10-09)
  $prob = location_probability($scient, date('Y-m-d'));
  $prob_cell = $prob === null ? "<td class='r' data-sort='-1'>-</td>"
    : "<td class='r " . ($prob >= $sf_thresh ? 'pgood' : 'pbad') . "' data-sort='" . sprintf('%.4f', $prob) . "'>" . fmt_num($prob * 100, 1) . "%</td>";  // a percentage, as on every page
  echo "<tr data-n=\"{$count}\" data-tax=\"" . taxon_order($scient) . "\" data-comname=\"{$common}\" data-sciname=\"{$scient}\" data-q=\"" . htmlspecialchars(mb_strtolower($common . ' ' . $scient . ' ' . $english), ENT_QUOTES) . "\">"
     . "<td style='white-space:nowrap'>{$common_link}" . (($row['FirstSeen'] ?? '') === date('Y-m-d') ? "<span class='spnew'>" . t('str_new_today_lc') . "</span>" : '') . "</td>"
     . "<td class='r'>{$count}</td>"
     . "<td class='r'>" . (int)($row['Days'] ?? 0) . "</td>"
     . "<td class='r diskcount' data-sort='{$files}'>{$files}</td>"
     . "<td class='r' data-sort='" . ($low_confidence === '' ? -1 : $low_confidence) . "'>" . ($low_confidence === '' ? '-' : fmt_num($low_confidence, 1) . '%') . "</td>"
     . "<td class='r' data-sort='" . ($avg_confidence === '' ? -1 : $avg_confidence) . "'>" . ($avg_confidence === '' ? '-' : fmt_num($avg_confidence, 1) . '%') . "</td>"
     . "<td class='r' data-sort='" . ($max_confidence === '' ? -1 : $max_confidence) . "'>" . ($max_confidence === '' ? '-' : fmt_num($max_confidence, 1) . '%') . "</td>"
     . "<td class='r' data-sort='{$conf_sort}'>".$conf_cell."</td>"
     . $prob_cell
     . "<td data-sort='{$species_tier}'>".$tier_cell."</td>"
     . "<td data-sort='".($is_confirmed?0:1)."'>".$confirm_cell."</td>"
     . "<td data-sort='".($is_whitelisted?0:1)."'>".$white_cell."</td>"
     . "<td data-sort='".($is_excluded?0:1)."'>".$excl_cell."</td>"
     . "<td>" . ($count ? "<img style='cursor:pointer;max-width:20px' src='images/delete.svg' alt='" . htmlspecialchars(t('str_delete') . ' ' . $common, ENT_QUOTES) . "' title='" . htmlspecialchars(t('str_delete') . ' ' . $common, ENT_QUOTES) . "' onclick=\"deleteSpecies('".addslashes($row['Sci_Name'])." + ".addslashes($row['Com_Name'])."')\">" : '') . "</td>"
     . "</tr>";
} ?>
    </tbody>
  </table>
</div></div>
<script src="static/species-sort.js?v=<?php echo @filemtime(__DIR__ . "/../homepage/static/species-sort.js"); ?>"></script>
<script src="static/std-table.js?v=<?php echo @filemtime(__DIR__ . "/../homepage/static/std-table.js"); ?>"></script>
<script src="static/name-mode.js?v=<?php echo @filemtime(__DIR__ . "/../homepage/static/name-mode.js"); ?>"></script>
<script src="static/species-modal.js?v=<?php echo @filemtime(__DIR__ . "/../homepage/static/species-modal.js"); ?>"></script>
<script>
const scriptsBase = 'scripts/';
const get = (url) => fetch(url, {cache:'no-store'}).then(r => r.text());
const post = (url) => fetch(url, {method:'POST', cache:'no-store'}).then(r => r.text());

/* ---------- toggles / delete ---------- */
// saved: a green outline; refused (not logged in, invalid): red, the old value back and the reason in the tooltip, like the
// Manual Conf. field (2026-10-23 test run: the picker showed nothing either way)
function setTier(species, sel) {
  post(scriptsBase + 'species_tools.php?settier=1&species=' + encodeURIComponent(species) + '&tier=' + encodeURIComponent(sel.value))
    .then(t => {
      const ok = t.trim() === 'OK';
      if (ok) sel.dataset.was = sel.value; else if (sel.dataset.was) sel.value = sel.dataset.was;
      sel.style.outline = ok ? '2px solid green' : '2px solid red';
      sel.title = ok ? '' : t.trim();
      setTimeout(() => { sel.style.outline = ''; }, ok ? 1500 : 4000);
    })
    .catch(() => { sel.style.outline = '2px solid red'; if (sel.dataset.was) sel.value = sel.dataset.was; });
}
function setConf(species, input) {
  const value = input.value.trim();
  post(scriptsBase + 'species_tools.php?setconf=1&species=' + encodeURIComponent(species) + '&value=' + encodeURIComponent(value))
    .then(t => {
      const ok = t.trim() === 'OK';
      input.style.outline = ok ? '2px solid green' : '2px solid red';
      input.title = ok ? (value === '' ? window.t('str_global_value_in_use') : window.t('str_saved_value', {value: window.fmtNum(parseFloat(value), 2)})) : t.trim();
      if (ok) input.parentElement.dataset.sort = value === '' ? input.dataset.global : parseFloat(value).toFixed(2);
      setTimeout(() => { input.style.outline = ''; }, 1500);
    });
}
/* confirmation modal for the Confirmed / Whitelist / Exclude columns (owner 2026-10-08) */
// askModal() and SPECIES_TOGGLE_TEXT live in static/species-modal.js (shared with the species page)
function toggleSpecies(list, species, action) {
  const parts = species.split('_');   // "Scientific name_Common name"
  const name = parts.length > 1 ? parts.slice(1).join('_') + ' (' + parts[0] + ')' : species;
  const t = (SPECIES_TOGGLE_TEXT[list] || {})[action];
  const go = () => post(scriptsBase + 'species_tools.php?toggle=' + list + '&species=' + encodeURIComponent(species) + '&action=' + action)
    .then(r => { if (r.trim() === 'OK') location.reload(); });
  if (!t) { go(); return; }
  // whitelist and exclude change what is detected: No is the default there
  askModal(t[0].replace('NAME', name), t[1], window.t('str_yes'), list === 'whitelist' || list === 'exclude').then(ok => { if (ok) go(); });
}
// Remove a whole species (owner 2026-10-19: same as Curation's Remove all): clips and lines go to the Removed folder,
// deleted for good only in Species › Purge Files; No is the default
function deleteSpecies(species) {
  let parts = species.split(' + '); let sci_species = parts[0]; let com_species = parts[1];
  get(scriptsBase + 'species_tools.php?getcounts=' + encodeURIComponent(sci_species)).then(t => {
    let info; try { info = JSON.parse(t); } catch { bsNotice(window.t('str_could_not_parse_count_response')); return; }
    askModal(window.t('str_delete_all_detections_of', {n: window.fmtNum(Number(info.count)), name: com_species + ' (' + sci_species + ')'}),
      window.t('str_clips_and_spectrograms_go_to_help'),
      window.t('str_delete_all'), true).then(ok => {
      if (!ok) return;
      post('play.php?deletespecies=' + encodeURIComponent(sci_species)).then(t2 => {
        if (!/^OK \d+/.test(t2)) { bsNotice(t2); return; }
        location.reload();
      });
    });
  });
}

</script>
<script>
/* ---------- Search with persistence ---------- */
// the rows are filtered by the shared species filter (static/species-sort.js, input.spfilter, as on Validation); this
// block only keeps the text across reloads and shows the count, in its own script and scope so nothing above can stop
// it (2026-11-01: the filter did nothing on the owner's phone while Validation's worked)
(function () {
  var q = document.getElementById('q'), matchCount = document.getElementById('matchCount');
  if (!q) return;
  function count() {
    var rows = document.querySelectorAll('#speciesTable tbody tr'), shown = 0;
    rows.forEach(function (tr) { if (tr.style.display !== 'none') shown++; });
    if (matchCount) matchCount.textContent = rows.length ? shown + ' / ' + rows.length : '';
    try { localStorage.setItem('speciesFilter', q.value); } catch (e) {}
  }
  // after the shared listener (registered first, species-sort.js loads above): the rows are already filtered
  q.addEventListener('input', function () { setTimeout(count, 0); });
  function boot() {
    try { var saved = localStorage.getItem('speciesFilter'); if (saved !== null) q.value = saved; } catch (e) {}
    // a kept filter shows its line open, so a short list never hides why
    if (q.value.trim()) { var bar = q.closest('.sortbar'); if (bar) bar.classList.add('fopen'); }
    q.dispatchEvent(new Event('input'));
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot); else boot();
})();
</script>
