<?php
/* Tools › Database (owner 2026-10-21): the station's databases in the app's look — size and rows per table, a quick
 * integrity check, Optimise (VACUUM + ANALYZE, run as the station's user so the files keep their owner) and a
 * download of a consistent copy (SQLite online backup); the Adminer editor stays behind "Advanced editor" for SQL.
 * Opened as views.php?view=Database; scripts/database.php?action=check|optimise&db=KEY (POST) and ?download=KEY. */
require_once __DIR__ . '/common.php';
if (session_status() === PHP_SESSION_NONE) @session_start();

$db_files = array('birds' => array(t('Detections'), BS_DB));
if (is_file(__ROOT__ . '/scripts/birds_shadow.db')) $db_files['shadow'] = array(t('Shadow model'), __ROOT__ . '/scripts/birds_shadow.db');

if (isset($_GET['download'])) {
  ensure_authenticated(t('You must be authenticated to download the database.'));
  $k = (string)$_GET['download'];
  if (!isset($db_files[$k])) { http_response_code(404); die(); }
  session_write_close();
  $tmp = tempnam(sys_get_temp_dir(), 'bsdb');
  $src = new SQLite3($db_files[$k][1], SQLITE3_OPEN_READONLY);
  $src->busyTimeout(10000);
  $dst = new SQLite3($tmp);
  $ok = $src->backup($dst);
  $dst->close(); $src->close();
  if (!$ok) { @unlink($tmp); http_response_code(500); echo t('The copy failed'); die(); }
  header('Content-Type: application/vnd.sqlite3');
  header('Content-Disposition: attachment; filename="' . basename($db_files[$k][1], '.db') . '-' . date('Y-m-d') . '.db"');
  header('Content-Length: ' . filesize($tmp));
  readfile($tmp);
  @unlink($tmp);
  die();
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_GET['action'])) {
  ensure_authenticated(t('You must be authenticated to change the database.'));
  session_write_close();
  $k = (string)($_GET['db'] ?? '');
  if (!isset($db_files[$k])) { echo t('Unknown database'); die(); }
  $file = $db_files[$k][1];
  if ($_GET['action'] === 'check') {
    $db = new SQLite3($file, SQLITE3_OPEN_READONLY);
    $db->busyTimeout(10000);
    $r = (string)$db->querySingle('PRAGMA quick_check');
    echo $r === 'ok' ? t('No problem found.') : t('Problems found: {result}', array('result' => $r));
  } elseif ($_GET['action'] === 'optimise') {
    $before = filesize($file);
    $out = trim((string)shell_exec(station_prefix() . 'sqlite3 -cmd ' . escapeshellarg('.timeout 30000')
      . ' ' . escapeshellarg($file) . ' ' . escapeshellarg('VACUUM; ANALYZE;') . ' 2>&1'));
    clearstatcache();
    echo $out === '' ? t('Optimised: {before} MB to {after} MB.', array('before' => fmt_num($before / 1048576, 1), 'after' => fmt_num(filesize($file) / 1048576, 1)))
      : t('Not optimised: {error}', array('error' => $out));
  }
  die();
}

$h = function ($s) { return htmlspecialchars((string)$s, ENT_QUOTES); };
$info = array();
foreach ($db_files as $k => $f) {
  $db = new SQLite3($f[1], SQLITE3_OPEN_READONLY);
  $db->busyTimeout(5000);
  $tables = array();
  $res = $db->query("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%' ORDER BY name");
  while ($row = $res->fetchArray(SQLITE3_ASSOC)) {
    $tables[$row['name']] = intval($db->querySingle('SELECT COUNT(*) FROM "' . str_replace('"', '""', $row['name']) . '"'));
  }
  $indexes = intval($db->querySingle("SELECT COUNT(*) FROM sqlite_master WHERE type='index'"));
  $free = intval($db->querySingle('PRAGMA freelist_count')) * intval($db->querySingle('PRAGMA page_size'));
  $db->close();
  $info[$k] = array('label' => $f[0], 'file' => $f[1], 'size' => filesize($f[1]), 'free' => $free, 'tables' => $tables, 'indexes' => $indexes);
}
?>
<style>
.dbm { max-width: 1000px; margin: 0; text-align: left; padding: 0 12px; box-sizing: border-box; }
.dbm .box { padding: 10px 14px; margin: 10px 0; }
.dbm .row { display: flex; flex-wrap: wrap; gap: 8px; align-items: center; margin: 6px 0; }
.dbm .small { font-size: 12px; opacity: .8; }
.dbm table.stdtable { width: auto; min-width: 320px; }
.dbm .msg { font-weight: 600; }
</style>
<div class="dbm">
  <?php foreach ($info as $k => $d) { ?>
  <div class="box">
    <div class="row"><b><?php echo $h($d['label']); ?></b> <span class="small"><?php echo $h($d['file']); ?></span></div>
    <div class="row"><?php echo t('{mb} MB, {n} indexes', array('mb' => fmt_num($d['size'] / 1048576, 1), 'n' => $d['indexes'])); ?><?php
      if ($d['free'] > 1048576) echo ', ' . t('{mb} MB free inside the file (Optimise gives it back)', array('mb' => fmt_num($d['free'] / 1048576, 1))); ?></div>
    <table class="stdtable">
      <tr><th><?php echo t('Table'); ?></th><th class="num"><?php echo t('Rows'); ?></th></tr>
      <?php foreach ($d['tables'] as $name => $n) echo '<tr><td>' . $h($name) . '</td><td class="num">' . fmt_num($n) . '</td></tr>'; ?>
    </table>
    <div class="row">
      <button type="button" class="stdbtn" onclick="dbAct(this, 'check', '<?php echo $k; ?>')"><?php echo t('Check'); ?></button>
      <button type="button" class="stdbtn" onclick="dbAct(this, 'optimise', '<?php echo $k; ?>')"><?php echo t('Optimise'); ?></button>
      <a href="scripts/database.php?download=<?php echo $k; ?>"><button type="button" class="stdbtn"><?php echo t('Download'); ?></button></a>
      <span class="msg" id="dbmsg_<?php echo $k; ?>"></span>
    </div>
  </div>
  <?php } ?>
  <p><?php echo t('Optimise rebuilds the file without its empty space; the analysis waits a few seconds meanwhile.'); ?><br>
    <?php echo t('For SQL queries and editing rows, the <a href="views.php?view=Adminer">Advanced editor</a> (Adminer).'); ?></p>
</div>
<script>
function dbAct(b, action, db) {
  if (action === 'optimise' && !confirm(t('Optimise the database now?'))) return;
  var m = document.getElementById('dbmsg_' + db);
  m.textContent = t('Working…');
  b.disabled = true;
  var x = new XMLHttpRequest();
  x.onload = function () {
    b.disabled = false;
    m.textContent = this.status === 401 ? t('Log in first.') : this.responseText;
    if (action === 'optimise' && this.status === 200) setTimeout(function () { location.reload(); }, 1500);
  };
  x.open('POST', 'scripts/database.php?action=' + action + '&db=' + db, true);
  x.send();
}
</script>
