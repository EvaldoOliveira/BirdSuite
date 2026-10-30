<?php
/* Purge Files (owner 2026-10-09; named 2026-10-20): detections removed anywhere in the interface ("Remove detection") wait in
 * ~/BirdSongs/Extracted/Removed (files) and deleted_detections (database lines) until they are deleted here for good —
 * all of them or one species. It always asks first, with the number of files per species.
 * A section of Tools › Data above the danger zone (owner 2026-10-24, formerly Species › Purge Files; included by
 * station_backup.php), and still views.php?view=Wipe. */
require_once __DIR__ . '/common.php';
$db = get_db();
$h = function ($s) { return htmlspecialchars((string)$s, ENT_QUOTES); };
// the Deleted column on one line (owner 2026-10-21): "2026-10-21 13:57", one day "2026-10-21 13:57–13:58",
// several days "2026-10-20 19:35 → 10-21 13:57"
$when = function ($a, $b) {
  $a = substr((string)$a, 0, 16); $b = substr((string)$b, 0, 16);
  if ($a === $b || $b === '') return $a;
  if (substr($a, 0, 10) === substr($b, 0, 10)) return $a . '–' . substr($b, 11, 5);
  return $a . ' → ' . (substr($a, 0, 4) === substr($b, 0, 4) ? substr($b, 5) : $b);
};
// one block per location (owner 2026-10-21): its name as the title, its buttons and its table; the removed detections
// outside every location get a block of their own. key = the loc parameter of play.php (static/wipe-deleted.js)
$wblocks = array();
if ($db->querySingle("SELECT 1 FROM sqlite_master WHERE type='table' AND name='deleted_detections'")) {
  $wscopes = array();
  $all = array();
  foreach (locations_load() as $l) {
    $wscopes[] = array('key' => (string)intval($l['id']), 'name' => $l['name'], 'sql' => location_sql($l, true));
    $all[] = '(' . location_sql($l, true) . ')';
  }
  $wscopes[] = array('key' => 'none', 'name' => t('Outside every location'), 'sql' => $all ? 'NOT (' . implode(' OR ', $all) . ')' : '1');
  foreach ($wscopes as $sc) {
    $wrows = array();
    $res = $db->query('SELECT Sci_Name, MAX(Com_Name) AS com, COUNT(*) AS n, MIN(Deleted_At) AS first, MAX(Deleted_At) AS last FROM deleted_detections WHERE '
      . $sc['sql'] . ' GROUP BY Sci_Name ORDER BY n DESC');
    while ($res && ($r = $res->fetchArray(SQLITE3_ASSOC))) $wrows[] = $r;
    // every registered location is listed, also with nothing deleted (owner 2026-10-21); "outside" only when it has rows
    if ($wrows || $sc['key'] !== 'none') $wblocks[] = $sc + array('rows' => $wrows, 'n' => array_sum(array_column($wrows, 'n')));
  }
}
$wtotal = array_sum(array_column($wblocks, 'n'));
// audio to purge (owner 2026-10-22): the detections answered with a cause (Dog, Human, Engine, Noise, Ignore), moved to
// Extracted/Purge by the nightly purge_stage.py, and the excluded species, every location, while their clip is on disk; Purge removes the clip and spectrograms only, the
// database line stays and keeps counting in every statistic
$wcause_rows = array(); $woth_rows = array();
if ($db->querySingle("SELECT 1 FROM sqlite_master WHERE type='table' AND name='detection_reviews'")) {
  $wcause_rows = purge_rows($db->query("SELECT d.Date, d.Com_Name, d.File_Name, d.Sci_Name, r.Reason FROM main.detections d JOIN detection_reviews r ON r.File_Name = d.File_Name
    WHERE r.Verdict = 'no' AND r.Reason IN ('" . implode("','", PURGE_CAUSES) . "')"), null, 'Purge');
}
$excl = excluded_species();
// Others: not the clips answered Yes nor the protected ones (as play.php?purgeaudio=others)
$locked = locked_clips();
// only the excluded species that have detections are read (2026-10-23 test run: every detection went to PHP, 142k rows,
// 1.4M and 7 s at ten times the station's data); the species index lists them and finds their rows
$excl_found = $excl ? excluded_with_detections($db, $excl) : '';
if ($excl_found) $woth_rows = purge_rows($db->query("SELECT Date, Com_Name, File_Name, Sci_Name FROM main.detections WHERE Sci_Name IN ("
  . $excl_found . ") AND File_Name NOT IN (SELECT File_Name FROM detection_reviews WHERE Verdict = 'yes')"),
  function ($r) use ($excl, $locked) { return isset($excl[$r['Sci_Name']]) && !isset($locked[$r['Date'] . '/' . species_folder($r['Sci_Name'] ?? '', $r['Com_Name'], $r['File_Name']) . '/' . $r['File_Name']]); });
$wsize = (int)trim((string)shell_exec('du -sb ' . escapeshellarg(deleted_dir()) . ' 2>/dev/null | cut -f1'));
?>
<style>
.wipe { max-width: 900px; margin: 0; text-align: left; padding: 0; }
.wipe button.wipebtn { width: auto; padding: 4px 12px; border-radius: 12px; border: 1px solid #c62828; background: #fff; color: #c62828; font-weight: 600; cursor: pointer; }
.wipe button.wipebtn:hover { background: #c62828; color: #fff; }
.wipe p.wipestate { font-size: 1.1em; font-weight: 700; margin: 4px 0 12px; }
.wipe a.folderbtn { display: inline-block; margin-top: 8px; padding: 3px 10px; border-radius: 12px; border: 1px solid var(--accent,#2b5e22); background: #fff; color: var(--accent,#2b5e22); font-weight: 600; text-decoration: none; white-space: nowrap; }
.wipe a.folderbtn:hover { background: var(--accent,#2b5e22); color: #fff; }
.wipe button.restorebtn { width: auto; padding: 4px 12px; border-radius: 12px; border: 1px solid var(--accent,#2b5e22); background: #fff; color: var(--accent,#2b5e22); font-weight: 600; cursor: pointer; }
.wipe button.restorebtn:hover { background: var(--accent,#2b5e22); color: #fff; }
.wipe .wipeloc { margin: 26px 0 0; }
.wipe .wipeloc h3 { font-size: 1.15em; font-weight: 700; margin: 0 0 8px; }
.wipe .wipeloc h3 small { font-size: .6em; font-weight: normal; opacity: .75; }
</style>
<div class="wipe">
  <?php // as Move Data (owner 2026-10-24): one table, a line per location with its buttons, the species folded under it ?>
  <p class="wipestate"><?php echo $wtotal ? ($wtotal == 1 ? t('{n} deleted detection', array('n' => fmt_num($wtotal))) : t('{n} deleted detections', array('n' => fmt_num($wtotal)))) . ', ' . fmt_num($wsize / 1048576, 1) . ' MB' : t('Nothing to purge'); ?></p>
  <p class="small"><?php echo t('A deleted detection leaves the lists, charts and statistics.'); ?><br>
    <?php echo t('Its audio and spectrogram are moved to the Removed folder until you <b>Purge</b> them here.'); ?><br>
    <?php echo t('<b>Restore</b> puts a detection back exactly where it was.'); ?><br>
    <?php echo t('<b>Purge</b> erases its audio, spectrogram and database record for good; it cannot be undone.'); ?></p>
  <table class="stdtable" data-nosort>
    <tr><th><?php echo t('Location'); ?></th><th class="num"><?php echo t('Deleted'); ?></th><th><?php echo t('Deleted'); ?></th><th></th></tr>
    <?php foreach ($wblocks as $i => $b) {
      $loc = $h(json_encode($b['key']));
      $first = $b['rows'] ? min(array_column($b['rows'], 'first')) : ''; $last = $b['rows'] ? max(array_column($b['rows'], 'last')) : '';
      echo '<tr><td><b>' . $h($b['name']) . '</b></td><td class="num">' . fmt_num($b['n']) . '</td><td style="white-space:nowrap">' . $h($b['n'] ? $when($first, $last) : '') . '</td><td style="white-space:nowrap">';
      if ($b['n']) echo '<button type="button" class="restorebtn" onclick="restoreRemoved(\'\', this, ' . $loc . ')">' . t('Restore all') . '</button> '
        . '<button type="button" class="wipebtn" onclick="wipeDeleted(\'\', this, ' . $loc . ')">' . t('Purge all') . '</button> '
        . '<button type="button" class="restorebtn" onclick="wipeFold(this, \'wl' . $i . '\')">' . t('Species') . ' &#9662;</button>';
      else echo '<span class="small">' . t('Nothing to purge') . '</span>';
      echo '</td></tr>';
      foreach ($b['rows'] as $r) {
        echo '<tr class="wl' . $i . '" style="display:none"><td style="padding-left:22px"><a href="views.php?view=Bird&amp;sci=' . rawurlencode($r['Sci_Name']) . '" data-com="' . $h($r['com']) . '" data-sci="' . $h($r['Sci_Name']) . '" data-en="' . $h(get_english_name($r['Sci_Name'])) . '">' . $h($r['com']) . '</a></td>'
          . '<td class="num">' . fmt_num($r['n']) . '</td><td style="white-space:nowrap">' . $h($when($r['first'], $r['last'])) . '</td>'
          . '<td style="white-space:nowrap"><button type="button" class="restorebtn" onclick="restoreRemoved(' . $h(json_encode($r['Sci_Name'])) . ', this, ' . $loc . ')">' . t('Restore') . '</button> '
          . '<button type="button" class="wipebtn" onclick="wipeDeleted(' . $h(json_encode($r['Sci_Name'])) . ', this, ' . $loc . ')">' . t('Purge') . '</button></td></tr>';
      }
    } ?>
  </table>
  <?php if ($wcause_rows || $woth_rows) { $cn = array('dog' => t('Dog'), 'human' => t('Human'), 'engine' => t('Engine'), 'noise' => t('Noise'), 'ignore' => t('Ignore')); ?>
  <h3 style="margin:18px 0 4px"><?php echo t('Audio to purge'); ?> <small><?php echo t('every location'); ?></small></h3>
  <p class="small"><?php echo t('<b>Purge audio</b> deletes the clip and its spectrogram for good.'); ?><br><?php echo t('The detection stays in the database and keeps counting in every statistic.'); ?><br><?php echo t('Dog, Human, Engine, Noise and Ignore answers move here in the nightly review.'); ?></p>
  <table class="stdtable" data-nosort>
    <tr><th><?php echo t('Kind'); ?></th><th class="num"><?php echo t('Detections'); ?></th><th></th></tr>
    <?php foreach (array(array($wcause_rows, t('Dog, Human, Engine, Noise, Ignore'), 'causes'), array($woth_rows, t('Others (excluded species)'), 'others')) as $part) { list($rows_p, $title_p, $kind) = $part; if (!$rows_p) continue;
      echo '<tr><td><b>' . $h($title_p) . '</b></td><td class="num">' . fmt_num(array_sum(array_column($rows_p, 'n'))) . '</td><td style="white-space:nowrap">'
        . '<button type="button" class="wipebtn" onclick="purgeAudio(' . $h(json_encode($kind)) . ', \'\', \'\', this)">' . t('Purge all') . '</button> '
        . '<button type="button" class="restorebtn" onclick="wipeFold(this, \'wa' . $kind . '\')">' . t('Species') . ' &#9662;</button></td></tr>';
      foreach ($rows_p as $r) echo '<tr class="wa' . $kind . '" style="display:none"><td style="padding-left:22px"><a href="views.php?view=Bird&amp;sci=' . rawurlencode($r['sci']) . '">' . $h($r['com']) . '</a> <small><i>' . $h($r['sci']) . '</i></small>'
        . ($kind === 'causes' ? ' <small>(' . $h($cn[$r['cause']] ?? $r['cause']) . ')</small>' : '') . '</td><td class="num">' . fmt_num($r['n']) . '</td>'
        . '<td><button type="button" class="wipebtn" onclick="purgeAudio(' . $h(json_encode($kind)) . ', ' . $h(json_encode($r['sci'])) . ', ' . $h(json_encode($r['cause'])) . ', this)">' . t('Purge audio') . '</button></td></tr>';
    } ?>
  </table>
  <?php } ?>
  <script>
  // the species of a line, folded under it
  function wipeFold(btn, cls) {
    var rows = document.querySelectorAll('tr.' + cls), show = rows.length && rows[0].style.display === 'none';
    rows.forEach(function (r) { r.style.display = show ? '' : 'none'; });
    btn.innerHTML = btn.innerHTML.replace(/[▾▴]/, show ? '▴' : '▾');
  }
  // Purge audio: play.php?purgeaudio removes the clips and spectrograms, the detections stay in the database; then reload
  function purgeAudio(kind, sci, reason, btn) {
    askModal(t('Purge the audio of these detections?'), t('The clips and spectrograms are deleted for good and cannot be restored.') + '\n' + t('The detections stay in the database and keep counting in every statistic.'), t('Purge audio'), true).then(function (ok) {
      if (!ok) return;
      btn.disabled = true;
      var x = new XMLHttpRequest();
      x.onload = function () { if (/^OK \d+/.test(this.responseText)) location.reload(); else { btn.disabled = false; alert(this.status === 401 ? t('Log in first.') : this.responseText); } };
      x.open('GET', 'play.php?purgeaudio=' + kind + (sci ? '&sci=' + encodeURIComponent(sci) : '') + (reason ? '&reason=' + encodeURIComponent(reason) : ''), true);
      x.send();
    });
  }
  </script>
  <?php if ($wtotal) { ?>
  <p class="small" style="margin-top:1em"><?php echo t('For specific purge, find deleted files in:'); ?> <code>~/BirdSongs/Extracted/Removed</code></p>
  <?php } ?>
</div>
<script src="static/species-modal.js?v=<?php echo @filemtime(__DIR__ . "/../homepage/static/species-modal.js"); ?>"></script>
<script src="static/wipe-deleted.js?v=<?php echo @filemtime(__DIR__ . "/../homepage/static/wipe-deleted.js"); ?>"></script>
<script src="static/std-table.js?v=<?php echo @filemtime(__DIR__ . "/../homepage/static/std-table.js"); ?>"></script>
<script src="static/name-mode.js?v=<?php echo @filemtime(__DIR__ . "/../homepage/static/name-mode.js"); ?>"></script>
