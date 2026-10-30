<?php
?>
<?php
// Today's totals (Latest page, kiosk): indicator cards like By Hour (owner 2026-10-09)
function render_today_stats($totalcount, $todaycount, $totalspeciestally, $todayspeciestally, $kiosk) {
  // the extra cards, kept for a minute like the totals (common.php cached_value)
  $extra = cached_value('today_cards', 60, function () {
    $new_today = 0;
    $stmt_new = get_db()->prepare("SELECT COUNT(DISTINCT Sci_Name) AS n FROM detections WHERE Date = DATE('now', 'localtime')
      AND Sci_Name NOT IN (SELECT DISTINCT Sci_Name FROM detections WHERE Date < DATE('now', 'localtime'))");
    if ($stmt_new !== false && ($res_new = $stmt_new->execute()) !== false) {
      $new_today = (int)($res_new->fetchArray(SQLITE3_ASSOC)['n'] ?? 0);
    }
    return array('new_today' => $new_today,
      'best' => get_db()->querySingle("SELECT MAX(Confidence) FROM detections WHERE Date = DATE('now', 'localtime')" . not_rejected_sql()),
      'first' => (string)get_db()->querySingle('SELECT MIN(Date) FROM detections'));
  });
  $new_today = $extra['new_today'];
  // indicator cards (owner 2026-10-09); a click opens By Hour / Species Pages
  $best = $extra['best'];
  $card = function ($label, $value, $href, $cls = 'kpi') use ($kiosk) {
    $inner = '<small>' . $label . '</small><b>' . $value . '</b>';
    return $kiosk ? '<div class="' . $cls . '">' . $inner . '</div>' : '<a class="' . $cls . '" href="' . $href . '">' . $inner . '</a>';
  };
  // today's cards first (best confidence is today's too), then a wider gap and the all-days cards
  echo '<div class="kpis">'
    . $card(t('Detections today'), fmt_num((int)$todaycount), 'views.php?view=Dashboard')
    . $card(t('Species today'), (int)$todayspeciestally, 'views.php?view=Dashboard')
    . $card(t('New today'), $new_today, 'views.php?view=Dashboard')
    . $card(t('Best confidence'), round(floatval($best) * 100) . '%', 'views.php?view=Dashboard')
    . $card(t('Total (all days)'), fmt_num((int)$totalcount), 'views.php?view=Dashboard&amp;from=' . $extra['first'] . '&amp;to=' . date('Y-m-d'), 'kpi kpi2')
    . $card(t('Species (all days)'), (int)$totalspeciestally, 'views.php?view=Bird')
    . '</div>';
}
?>
<?php

/* Prevent XSS input */
require_once 'scripts/sanitize_input.php';
$_GET   = bs_sanitize_input(INPUT_GET);
$_POST  = bs_sanitize_input(INPUT_POST);

ini_set('session.gc_maxlifetime', 7200);
session_set_cookie_params(7200);
session_start(array('cookie_httponly' => true, 'cookie_samesite' => 'Lax'));  // as common.php BS_SESSION_OPTIONS (2026-10-23 test run)
error_reporting(E_ERROR);
ini_set('display_errors', 0);  // errors to the log, never to the page (2026-10-23 security run)
if (!defined('LEGACY_PAGE')) define('LEGACY_PAGE', 500); // rows per call of the unpaged list (Normal view, search)
if (!defined('NOW_PAGE')) define('NOW_PAGE', true); // the Location picklist of Now may show every location (owner 2026-10-21)
require_once 'scripts/common.php';
$home = get_home();
$config = get_config();
$site_name = get_sitename();
set_timezone();
// the session is only read from here on (2026-10-20): released at once, so the page's parallel requests (charts, list,
// stats, status every 5 s) no longer wait for each other on the session file lock
session_write_close();

if(isset($kiosk) && $kiosk == true) {
    echo "<div style='margin-top:20px' class=\"centered\"><h1><a><span class=\"topimage wordmark\">BirdSuite</span></a></h1></div>
</div><div class=\"centered\"><h3>$site_name</h3></div><hr>";
} else {
  $kiosk = false;
}

$db = new SQLite3(BS_DB, SQLITE3_OPEN_READONLY);
apply_location_filter($db); // the location chosen in the header (US-58)
$db->busyTimeout(1000);

// the totals only where they are shown (kiosk page, today_stats): the table refresh of Now does not need them, and they
// cost a count over every detection on each call (owner 2026-10-21)
if (!isset($_GET['ajax_detections'])) {
  $summary = get_summary();
  $totalcount = $summary['totalcount'];
  $todaycount = $summary['todaycount'];
  $hourcount = $summary['hourcount'];
  $todayspeciestally = $summary['speciestally'];
  $totalspeciestally = $summary['totalspeciestally'];
}

if(isset($_GET['comname'])) {
 $birdName = htmlspecialchars_decode($_GET['comname'], ENT_QUOTES);

// Set default days to 30 if not provided
$days = isset($_GET['days']) ? intval($_GET['days']) : 30;

// Prepare a SQL statement to retrieve the detection data for the specified bird
$stmt = $db->prepare('SELECT Date, COUNT(*) AS Detections FROM detections WHERE Com_Name = :com_name AND Date BETWEEN DATE("now", "-' . $days . ' days") AND DATE("now") GROUP BY Date');

// Bind the bird name parameter to the SQL statement
$stmt->bindValue(':com_name', $birdName);

// Execute the SQL statement and get the result set
$result = $stmt->execute();

// Fetch the result set as an associative array
$data = array();
while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
  $data[$row['Date']] = $row['Detections'];
}

// Create an array of all dates in the last 14 days
$last14Days = array();
for ($i = 0; $i < 31; $i++) {
  $last14Days[] = date('Y-m-d', strtotime("-$i days"));
}

// Merge the data array with the last14Days array
$data = array_merge(array_fill_keys($last14Days, 0), $data);

// Sort the data by date in ascending order
ksort($data);

// Convert the data to an array of objects
$data = array_map(function($date, $count) {
  return array('date' => $date, 'count' => $count);
}, array_keys($data), $data);

// Close the database connection
$db->close();

// Return the data as JSON
echo json_encode($data);
die();

}

// from https://stackoverflow.com/questions/2690504/php-producing-relative-date-time-from-timestamps
function relativeTime($ts)
{
    if(!ctype_digit($ts))
        $ts = strtotime($ts);

    $diff = time() - $ts;
    if($diff == 0)
        return t('now');
    elseif($diff > 0)
    {
        $day_diff = floor($diff / 86400);
        if($day_diff == 0)
        {
            if($diff < 60) return t('just now');
            if($diff < 120) return t('1 minute ago');
            if($diff < 3600) return t('{n} minutes ago', array('n' => floor($diff / 60)));
            if($diff < 7200) return t('1 hour ago');
            if($diff < 86400) return t('{n} hours ago', array('n' => floor($diff / 3600)));
        }
        if($day_diff == 1) return t('Yesterday');
        if($day_diff < 7) return t('{n} days ago', array('n' => $day_diff));
        if($day_diff < 31) return t('{n} weeks ago', array('n' => ceil($day_diff / 7)));
        if($day_diff < 60) return t('last month');
        return date('F Y', $ts);
    }
    else
    {
        $diff = abs($diff);
        $day_diff = floor($diff / 86400);
        if($day_diff == 0)
        {
            if($diff < 120) return t('in a minute');
            if($diff < 3600) return t('in {n} minutes', array('n' => floor($diff / 60)));
            if($diff < 7200) return t('in an hour');
            if($diff < 86400) return t('in {n} hours', array('n' => floor($diff / 3600)));
        }
        if($day_diff == 1) return t('Tomorrow');
        if($day_diff < 4) return date('l', $ts);
        if($day_diff < 7 + (7 - date('w'))) return t('next week');
        if(ceil($day_diff / 7) < 4) return t('in {n} weeks', array('n' => ceil($day_diff / 7)));
        if(date('n', $ts) == date('n') + 1) return t('next month');
        return date('F Y', $ts);
    }
}


if(isset($_GET['ajax_detections']) && $_GET['ajax_detections'] == "true"  ) {
  if(isset($_GET['searchterm'])) {
    if(strtolower(explode(" ", $_GET['searchterm'])[0]) == "not") {
      $not = "NOT ";
      $operator = "AND";
      $_GET['searchterm'] =  str_replace("not ", "", $_GET['searchterm']);
      $_GET['searchterm'] =  str_replace("NOT ", "", $_GET['searchterm']);
    } else {
      $not = "";
      $operator = "OR";
    }
    // the text as typed (the input filter turned quotes into entities, so a name with an apostrophe never matched),
    // escaped for SQLite: it is built into several queries below (2026-10-20)
    $term = SQLite3::escapeString(html_entity_decode((string)$_GET['searchterm'], ENT_QUOTES));
    $searchquery = "AND (Com_name ".$not."LIKE '%".$term."%' ".$operator." Sci_name ".$not."LIKE '%".$term."%' ".$operator." Confidence ".$not."LIKE '%".$term."%' ".$operator." File_Name ".$not."LIKE '%".$term."%' ".$operator." Time ".$not."LIKE '%".$term."%')";
    // a name (the text has a letter, 2026-10-23 test run): LIKE on five columns walked the whole history when nothing
    // matched (period All: 4 s at ten times the station's data). The species whose common, scientific or folder name
    // holds the text are picked from the list of names (as LIKE: ASCII letters in either case) and found by the species
    // index; Confidence and Time hold no letters, and the letters of a file name are its common name.
    $raw = trim(html_entity_decode((string)$_GET['searchterm'], ENT_QUOTES));
    if ($not === '' && $raw !== '' && preg_match('/\p{L}/u', $raw)) {
      $hits = array();
      $fr = fold_text($raw);  // accents ignored (2026-10-29)
      foreach (species_name_pairs() as $x) {
        $f0 = fold_text($x[0]); $f1 = fold_text($x[1]);
        if (strpos($f0, $fr) !== false || strpos($f1, $fr) !== false || strpos(str_replace(array(' ', "'"), array('_', ''), $f1), $fr) !== false) $hits[$x[0]] = true;
      }
      $searchquery = $hits ? 'AND Sci_Name IN (' . implode(',', array_map(function ($s) { return "'" . SQLite3::escapeString($s) . "'"; }, array_keys($hits))) . ')' : 'AND 0';
    }
  } else {
    $searchquery = "";
  }
  if(isset($_GET['display_limit']) && is_numeric($_GET['display_limit'])){
    $statement0 = $db->prepare('SELECT Date, Time, Com_Name, Sci_Name, Confidence, Cutoff, Sens, Overlap, Loc_Thresh, Rec_Length, Sp_Override, File_Name, '.location_columns_sql().' FROM detections WHERE Date == Date(\'now\', \'localtime\') '.$searchquery.' ORDER BY Time DESC LIMIT '.(intval($_GET['display_limit'])-40).',40');
  } else {
    // legacy mode
    if(isset($_GET['hard_limit']) && is_numeric($_GET['hard_limit'])) {
      // the Latest page's most recent detections: not only today's, so the list is never empty after midnight
      // gallery pages: offset = how many newer detections are skipped; one extra row tells whether older ones exist
      $g_offset = max(0, intval($_GET['offset'] ?? 0));
      $g_limit = intval($_GET['hard_limit']) + (isset($_GET['gallery']) ? 1 : 0);
      // Now filters (owner 2026-10-09): lowconf = confidence below N %, lowprob = location model probability below N %
      // (or not in the location profile); the probability is not in the database, so lowprob filters while reading
      // uncommon (owner 2026-10-10) = species detected at most N times from yesterday 00:00 until now (species not heard
      // in that time count as 0)
      // several filters at once (owner 2026-10-10): &uncommon=N &lowconf=P &lowprob=P, every one present applies
      $g_conf = isset($_GET['lowconf']) && is_numeric($_GET['lowconf']) ? max(5, min(95, intval($_GET['lowconf']))) / 100 : null;
      $g_prob = isset($_GET['lowprob']) && is_numeric($_GET['lowprob']) ? max(5, min(95, intval($_GET['lowprob']))) / 100 : null;
      $g_most = isset($_GET['uncommon']) && is_numeric($_GET['uncommon']) ? max(1, intval($_GET['uncommon'])) : null;
      // rare (owner 2026-10-25) = species with no detection in the N days before today (never detected before counts too)
      $g_rare = isset($_GET['rare']) && is_numeric($_GET['rare']) ? max(1, min(3650, intval($_GET['rare']))) : null;
      $g_filter = ($g_conf !== null || $g_prob !== null || $g_most !== null || $g_rare !== null) ? 'on' : '';
      // period of a filtered list (owner 2026-10-20): today (default), 7 days or all. The list walks the Date/Time index
      // from the newest row and stops at the page size, so an unfiltered list is instant; a filter that matches few
      // rows walked the whole history (Low Prob 1.7 s, a rare name 0.6 s) — a period bounds that walk
      $g_period = in_array($_GET['period'] ?? '', array('today', '7d', 'all'), true) ? $_GET['period'] : 'today';
      if (($g_filter !== '' || isset($_GET['searchterm'])) && $g_period !== 'all') {
        $searchquery .= $g_period === 'today' ? " AND Date >= DATE('now', 'localtime')" : " AND Date >= DATE('now', 'localtime', '-6 days')";
      }
      if ($g_conf !== null) $searchquery .= ' AND Confidence < ' . $g_conf;
      if ($g_most !== null) {
        // the frequent species since yesterday, counted once a minute (common.php cached_value)
        $frequent = cached_value('uncommon_' . $g_most, 60, function () use ($g_most) {
          $out = array();
          $r = get_db()->query("SELECT Sci_Name FROM detections WHERE Date >= DATE('now', 'localtime', '-1 day') GROUP BY Sci_Name HAVING COUNT(*) > $g_most");
          while ($r && ($x = $r->fetchArray(SQLITE3_NUM))) $out[] = $x[0];
          return $out;
        });
        if ($frequent) $searchquery .= ' AND Sci_Name NOT IN (' . implode(',', array_map(function ($s) { return "'" . SQLite3::escapeString($s) . "'"; }, $frequent)) . ')';
      }
      if ($g_rare !== null) {
        // the species heard in the N days before today, counted once a minute; every other one is rare
        $recent = cached_value('rare_' . $g_rare, 60, function () use ($g_rare) {
          $out = array();
          $r = get_db()->query("SELECT DISTINCT Sci_Name FROM detections WHERE Date < DATE('now', 'localtime') AND Date >= DATE('now', 'localtime', '-$g_rare days')");
          while ($r && ($x = $r->fetchArray(SQLITE3_NUM))) $out[] = $x[0];
          return $out;
        });
        if ($recent) $searchquery .= ' AND Sci_Name NOT IN (' . implode(',', array_map(function ($s) { return "'" . SQLite3::escapeString($s) . "'"; }, $recent)) . ')';
      }
      // Low Prob in SQL for a bounded period: per day of the period, the species the location model expects at or
      // above P that week are left out (species outside the profile stay, as before); "all" keeps the row-by-row check
      if ($g_prob !== null && $g_period !== 'all') {
        $days = array();
        for ($d = 0; $d < ($g_period === 'today' ? 1 : 7); $d++) $days[] = date('Y-m-d', strtotime("-$d days"));
        $parts = array();
        foreach ($days as $day) {
          $likely = array_keys(array_filter(location_profile_week($day), function ($p) use ($g_prob) { return $p >= $g_prob; }));
          $parts[] = "(Date = '$day'" . ($likely ? ' AND Sci_Name NOT IN (' . implode(',', array_map(function ($s) { return "'" . SQLite3::escapeString($s) . "'"; }, $likely)) . ')' : '') . ')';
        }
        $searchquery .= ' AND (' . implode(' OR ', $parts) . ')';
        $g_prob_sql = true;
      }
      $statement0 = $db->prepare('SELECT Date, Time, Com_Name, Sci_Name, Confidence, Cutoff, Sens, Overlap, Loc_Thresh, Rec_Length, Sp_Override, File_Name, '.location_columns_sql().' FROM detections WHERE 1 '.$searchquery.' ORDER BY Date DESC, Time DESC'
        . ($g_prob !== null && empty($g_prob_sql) ? '' : ' LIMIT '.$g_limit.' OFFSET '.$g_offset));
    } else {
      // at most LEGACY_PAGE rows a call, then a button for the next ones (2026-10-23 test run: the whole day, 7,760 rows,
      // took 30 s and 4 MB); one extra row tells whether there are more
      $legacy_offset = max(0, intval($_GET['offset'] ?? 0));
      $statement0 = $db->prepare('SELECT Date, Time, Com_Name, Sci_Name, Confidence, File_Name FROM detections WHERE Date == Date(\'now\', \'localtime\') '.$searchquery.' ORDER BY Time DESC LIMIT ' . (LEGACY_PAGE + 1) . ' OFFSET ' . $legacy_offset);
    }
    
  }
  ensure_db_ok($statement0);
  $result0 = $statement0->execute();

  // Latest page (owner 2026-10-08): the most recent detections as cards like Best Detections — names (common name =
  // species page), time and confidence, delete + Review top right, and the spectrogram picture that opens big and
  // playing (static/spectro-dialog.js)
  if (isset($_GET['gallery'])) {
    // list = Full, simple = the list with time, species, confidence and probability only (owner 2026-10-20)
    $as_list = in_array($_GET['mode'] ?? '', array('list', 'simple'), true);
    // list view = the standard detection list (rows collected first); cards = the gallery
    $list_rows = array();
    if (!$as_list) echo '<div class="spgallery nowgallery" data-review-list="1">';
    $n = 0;
    $page = intval($_GET['hard_limit']);
    $more = false;
    $skip = 0;
    while ($g = $result0->fetchArray(SQLITE3_ASSOC)) {
      if (($g_prob ?? null) !== null && empty($g_prob_sql)) {
        $pr = location_probability($g['Sci_Name'], $g['Date']);
        if ($pr !== null && $pr >= $g_prob) continue;
        if ($skip++ < $g_offset) continue;
      }
      if ($n >= $page) { $more = true; break; }
      $n++;
      if ($as_list) { $list_rows[] = $g; continue; }
      $folder = species_folder($g['Sci_Name'] ?? '', $g['Com_Name'], $g['File_Name']);
      $file = $g['Date'] . '/' . $folder . '/' . $g['File_Name'];
      $clip = '/By_Date/' . $file;
      $when = ($g['Date'] !== date('Y-m-d') ? substr($g['Date'], 5) . ' ' : '') . $g['Time'];
      $fj = htmlspecialchars(json_encode($file), ENT_QUOTES);
      echo '<div class="gcard"' . review_item_attrs($file, $g['Com_Name'] . ', ' . $when . ', ' . round($g['Confidence'] * 100) . '%', $g['Sci_Name'], $g) . '><div class="gbody"><div class="gtop">'
        . '<div class="gnames">' . species_review_name($g['Sci_Name'], $g['Com_Name'], true) . '<br><i>' . htmlspecialchars($g['Sci_Name']) . '</i></div>'
        . '<div class="gacts"><img src="images/delete.svg" title="' . t('Delete Detection') . '" onclick="deleteDetection(' . $fj . ')">' . validate_button($file) . '</div></div>'
        . '<div class="gmeta">' . htmlspecialchars($when) . ', ' . round($g['Confidence'] * 100) . '%</div>'
        . '<img class="gspec" loading="lazy" src="' . htmlspecialchars(clip_spectrogram($clip)) . '" alt="' . t('spectrogram') . '" title="' . t('Listen and review') . '" onclick="reviewDetection(this)"></div></div>';
    }
    echo $as_list ? detection_review_table($list_rows, true, true, ($_GET['mode'] ?? '') === 'simple') : '</div>';
    // a search without a match is "no match" too, not "no detections yet" (2026-10-23 test run)
    if ($n == 0) echo '<h3>' . ((($g_filter ?? '') !== '' || trim((string)($_GET['searchterm'] ?? '')) !== '') ? t('No detections match the filters.') : t('No detections yet.')) . '</h3>';
    // one line under the table (owner 2026-10-21): how many on the left, newer / older in the middle, the range on the right
    if (isset($_GET['gallery'])) {
      echo list_pager(t('Last {select} Detections', array('select' => '{select}')), $g_offset, $n, $page, $more, 'nowSetCount', 'nowPage', 'nowcount');
    }
    die();
  }

  ?> <table>
   <?php

  if(!isset($_SESSION['images'])) {
    $_SESSION['images'] = [];
  }
  $iterations = 0;
  $image_provider = null;

  $legacy_more = false;
  while($todaytable=$result0->fetchArray(SQLITE3_ASSOC))
  {
    if (isset($legacy_offset) && $iterations >= LEGACY_PAGE) { $legacy_more = true; break; }
    $iterations++;

    $comname = species_folder($todaytable['Sci_Name'] ?? '', $todaytable['Com_Name'], $todaytable['File_Name']);
    $comnamegraph = str_replace("'", "\'", $todaytable['Com_Name']);
    $filename = "/By_Date/".$todaytable['Date']."/".$comname."/".$todaytable['File_Name'];
    $filename_formatted = $todaytable['Date']."/".$comname."/".$todaytable['File_Name'];
    $sciname = preg_replace('/ /', '_', $todaytable['Sci_Name']);
    $engname = get_com_en_name($todaytable['Sci_Name']);
    $engname_url = str_replace("'", '', str_replace(' ', '_', $engname));

    $info_url = get_info_url($todaytable['Sci_Name']);
    $url = $info_url['URL'];
    $url_title = $info_url['TITLE'];

    if (!empty($config["IMAGE_PROVIDER"])) {
      if ($image_provider === null) {
        $image_provider = new Wikipedia();
        if ($image_provider->is_reset()) {
          $_SESSION['images'] = [];
        }
      }

      // if we already searched flickr for this species before, use the previous image rather than doing an unneccesary api call
      $key = array_search($comname, array_column($_SESSION['images'], 0));
      if ($key !== false) {
        $image = $_SESSION['images'][$key];
      } else {
        $cached_image = $image_provider->get_image($todaytable['Sci_Name']);
        array_push($_SESSION["images"], array($comname, $cached_image["image_url"], $cached_image["title"], $cached_image["photos_url"], $cached_image["author_url"], $cached_image["license_url"]));
        $image = $_SESSION['images'][count($_SESSION['images']) - 1];
      }
    }
  ?>
        <?php if(isset($_GET['display_limit']) && is_numeric($_GET['display_limit'])){
          // the kiosk (2026-10-23 test run, now on these 40-row pages): relative times, a plain audio control, no review or actions
          $kq = ($_GET['kiosk'] ?? '') === 'true'; ?>
          <tr class="relative" id="<?php echo $iterations; ?>">
          <?php if ($kq) { ?><td class="relative"><?php } else { ?><td class="relative"<?php echo review_item_attrs($filename_formatted, $todaytable['Com_Name'] . ', ' . $todaytable['Time'] . ', ' . round($todaytable['Confidence'] * 100) . '%', $todaytable['Sci_Name'], $todaytable); ?>><?php } ?>
            <?php if (!$kq) echo detection_actions($filename_formatted); ?>
        
            
          <!-- photo on the left, then time, names (common name = link to the species page, scientific, English), links
               and confidence (owner 2026-10-08) -->
          <div class="tdhead">
            <?php if(!empty($config["IMAGE_PROVIDER"]) && strlen($image[2]) > 0) { ?>
              <img onclick='setModalText(<?php echo $iterations; ?>,"<?php echo urlencode($image[2]); ?>", "<?php echo $image[3]; ?>", "<?php echo $image[4]; ?>", "<?php echo $image[1]; ?>", "<?php echo $image[5]; ?>")' src="<?php echo $image[1]; ?>" class="img1">
            <?php } ?>
            <div class="tdinfo">
              <div class="tdtime"><?php echo $kq ? relativeTime(strtotime($todaytable['Time'])) : $todaytable['Time'];?></div>
              <?php // the name opens the review, the card icon the species page (owner 2026-10-26); the kiosk keeps the link
              echo species_title($todaytable['Sci_Name'], $kq ? '<b><a class="a2" href="views.php?view=Bird&amp;sci=' . rawurlencode($todaytable['Sci_Name']) . '" title="' . t('Open the species page') . '">' . $todaytable['Com_Name'] . '</a></b>'
                : species_review_name($todaytable['Sci_Name'], $todaytable['Com_Name'], true),
                '<img class="splink" title="' . t('View species stats') . '" onclick="generateMiniGraph(this, \'' . $comnamegraph . '\')" src="images/chart.svg">', true, false); ?>
              <div><b><?php echo t('Confidence:'); ?></b> <?php echo round((float)round($todaytable['Confidence'],2) * 100 ) . '%';?></div>
            </div>
          </div>
          <?php if ($kq) { ?><audio controls preload="none" src="<?php echo htmlspecialchars($filename); ?>"></audio>
          <?php } else { ?><div class='custom-audio-player' data-audio-src="<?php echo $filename; ?>" data-image-src="<?php echo clip_spectrogram($filename);?>"></div><?php } ?>
          </td>
        <?php } else { //legacy mode ?>
          <tr class="relative" id="<?php echo $iterations; ?>">
          <td><?php if($_GET['kiosk'] == true) { echo relativeTime(strtotime($todaytable['Time'])); } else { echo ($todaytable['Date'] !== date('Y-m-d') ? '<small>' . substr($todaytable['Date'], 5) . '</small> ' : '') . $todaytable['Time']; }?><br></td>
          <td id="recent_detection_middle_td">
          <div>
            <div>
            <?php // the bird photo only in the kiosk view; the Latest page's 5 most recent rows go without it (owner 2026-10-08)
            if(!empty($config["IMAGE_PROVIDER"]) && $_GET['kiosk'] == true && strlen($image[2]) > 0) { ?>
              <img style="float:left;height:75px;" onclick='setModalText(<?php echo $iterations; ?>,"<?php echo urlencode($image[2]); ?>", "<?php echo $image[3]; ?>", "<?php echo $image[4]; ?>", "<?php echo $image[1]; ?>", "<?php echo $image[5]; ?>")' src="<?php echo $image[1]; ?>" id="birdimage" class="img1">
            <?php } ?>
          </div>
            <div>
            <form action="" method="GET">
                    <input type="hidden" name="view" value="Species Stats">
          <?php // just "Common name - Scientific name"; the common name opens the species page (owner 2026-10-08) ?>
          <?php // the name opens the review, the card icon the species page (owner 2026-10-26); the kiosk keeps the link ?>
          <span class="recentname"><?php echo $_GET['kiosk'] == true ? '<a class="a2" href="views.php?view=Bird&amp;sci=' . rawurlencode($todaytable['Sci_Name']) . '" title="' . t('Open the species page') . '"><b>' . $todaytable['Com_Name'] . '</b></a>'
            : species_review_name($todaytable['Sci_Name'], $todaytable['Com_Name'], true, $filename_formatted); ?> - <i><?php echo $todaytable['Sci_Name']; ?></i></span>
	                <br>
	            </div>
            </form>
          </div>
          </td>
          <td><?php if(!isset($_GET['mobile'])) { echo '<b>' . t('Confidence:') . '</b>';} echo round((float)round($todaytable['Confidence'],2) * 100 ) . '%';?><br>
            <?php if(isset($_GET['mobile']) && $_GET['kiosk'] == false) echo '<span class="recentacts">' . '<button type="button" class="openbtn" title="' . t('Open the spectrogram and play') . '" onclick="openSpectrogram(' . htmlspecialchars(json_encode($filename), ENT_QUOTES) . ', ' . htmlspecialchars(json_encode($todaytable['Com_Name'] . ', ' . $todaytable['Time'] . ', ' . round($todaytable['Confidence'] * 100) . '%'), ENT_QUOTES) . ')">&#9654; ' . t('Open') . '</button>' . ' ' . detection_actions($filename_formatted, false, 'height: 1.2em;float:unset;display:inline', 18, false) . ' ' . validate_button($filename_formatted) . '</span>'; ?></td>
          <?php if(!isset($_GET['mobile'])) { ?>
              <!-- the Review button beside the audio controls (owner 2026-10-08) -->
              <td style="white-space:nowrap">
                <?php // Open (the spectrogram playing, like the species page), delete / change species / protect /
                // frequency shift, and Review always last on the right (owner 2026-10-08); the kiosk keeps the audio control
                if($_GET['kiosk'] == true) echo '<audio controls preload="none" src="' . htmlspecialchars($filename) . '"></audio>';
                else echo '<span class="recentacts">' . '<button type="button" class="openbtn" title="' . t('Open the spectrogram and play') . '" onclick="openSpectrogram(' . htmlspecialchars(json_encode($filename), ENT_QUOTES) . ', ' . htmlspecialchars(json_encode($todaytable['Com_Name'] . ', ' . $todaytable['Time'] . ', ' . round($todaytable['Confidence'] * 100) . '%'), ENT_QUOTES) . ')">&#9654; ' . t('Open') . '</button>' . ' ' . detection_actions($filename_formatted, false, 'height: 1.2em;float:unset;display:inline', 18, false) . ' ' . validate_button($filename_formatted) . '</span>'; ?></td>
          <?php } ?>
        <?php } ?>
  <?php }?>
        </tr>
      </table>

  <?php 
  if($iterations == 0) {
    echo '<h3>' . t('No Detections For Today.') . '</h3>';
  }
  
  // don't show the button if there's no more detections to be displayed, we're at the end of the list
  if($iterations >= 40 && isset($_GET['display_limit']) && is_numeric($_GET['display_limit'])) { ?>
  <center>
  <button class="loadmore" onclick="loadDetections(<?php echo $_GET['display_limit'] + 40; ?>, this);" value="Today's Detections"><?php echo t('Load 40 More...'); ?></button>
  </center>
  <?php }
  if ($legacy_more) { ?>
  <center>
  <button class="loadmore" onclick="loadDetections(undefined, this, <?php echo $legacy_offset + LEGACY_PAGE; ?>);"><?php echo t('Load {n} More...', array('n' => LEGACY_PAGE)); ?></button>
  </center>
  <?php }

  die();
}

if(isset($_GET['today_stats'])) {
  render_today_stats($totalcount, $todaycount, $totalspeciestally, $todayspeciestally, $kiosk);
  ?>
<?php   
die(); 
}

if (get_included_files()[0] === __FILE__) {
  echo '<!DOCTYPE html>
<html lang="' . str_replace('_', '-', ui_lang()) . '">
<head>
' . ui_js() . '
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>BirdSuite</title>
</head>';
}
?>
<div class="viewdb">
  <dialog style="margin-top: 5px;max-height: 95vh;
  overflow-y: auto;overscroll-behavior:contain" id="attribution-dialog">
    <h1 id="modalHeading"></h1>
    <p id="modalText"></p>
    <button style="font-weight:bold;color:blue" onclick="hideDialog()"><?php echo t('Close'); ?></button>
    <button style="font-weight:bold;color:blue" onclick="if(confirm(t('Are you sure you want to blacklist this image?'))) { blacklistImage(); }" <?php if($config["IMAGE_PROVIDER"] === 'WIKIPEDIA'){ echo 'hidden';} ?> ><?php echo t('Blacklist this image'); ?></button>
  </dialog>
  <script src="static/dialog-polyfill.js"></script>
  <script src="static/detection-actions.js?v=<?php echo @filemtime(__DIR__ . "/../homepage/static/detection-actions.js"); ?>"></script>
  <script src="static/review-player.js?v=<?php echo @filemtime(__DIR__ . "/../homepage/static/review-player.js"); ?>"></script>
  <script src="static/Chart.bundle.js"></script>
  <script src="static/chartjs-plugin-trendline.min.js"></script>
  
  <script>
    var last_photo_link;
  var dialog = document.querySelector('dialog');
  dialogPolyfill.registerDialog(dialog);

  function showDialog() {
    document.getElementById('attribution-dialog').showModal();
  }

  function hideDialog() {
    document.getElementById('attribution-dialog').close();
  }

  function blacklistImage() {
    const match = last_photo_link.match(/\d+$/); // match one or more digits
    const result = match ? match[0] : null; // extract the first match or return null if no match is found
    console.log(last_photo_link)
    const xhttp = new XMLHttpRequest();
    xhttp.onload = function() {
      if(this.responseText.length > 0) {
       location.reload();
      }
    }
    xhttp.open("GET", "overview.php?blacklistimage="+result, true);
    xhttp.send();

  }

  function shorten(u) {
    if (u.length < 48) {
      return u;
    }
    uend = u.slice(u.length - 16);
    ustart = u.substr(0, 32);
    var shorter = ustart + '...' + uend;
    return shorter;
  }

  function setModalText(iter, title, text, authorlink, photolink, licenseurl) {
    let text_display = shorten(text);
    let authorlink_display = shorten(authorlink);
    let licenseurl_display = shorten(licenseurl);
    document.getElementById('modalHeading').innerHTML = t('Photo: "{title}" Attribution', {title: decodeURIComponent(title.replaceAll("+"," "))});
    document.getElementById('modalText').innerHTML = "<div><img style='border-radius:5px;max-height: calc(100vh - 15rem);display: block;margin: 0 auto;' src='"+photolink+"'></div><br><div style='white-space:nowrap'>"+t('Image link:')+" <a target='_blank' href="+text+">"+text_display+"</a><br>"+t('Author link:')+" <a target='_blank' href="+authorlink+">"+authorlink_display+"</a><br>"+t('License URL:')+" <a href="+licenseurl+" target='_blank'>"+licenseurl_display+"</a></div>";
    last_photo_link = text;
    showDialog();
  }
  </script>  
    <div class="todaybar" style="display:flex;justify-content:space-between;align-items:center;gap:10px;flex-wrap:wrap;margin:4px 0;">
      <div id="todaystats" class="overview"><?php render_today_stats($totalcount, $todaycount, $totalspeciestally, $todayspeciestally, $kiosk); ?></div>
      <div><b><?php echo t('Today\'s Detections'); ?></b> <?php if($kiosk == false) { ?>, <input autocomplete="off" size="18" type="text" placeholder="<?php echo htmlspecialchars(t('Search...')); ?>" id="searchterm" name="searchterm"><?php } ?></div>
    </div>

    <div style="padding-bottom:10px" id="detections_table"><h3 data-loading="1"><?php echo t('Loading...'); ?></h3></div>
    <script>
      // the totals / search bar spans exactly the width of the detection cards below it; the last width is kept in this
      // browser and applied at once, so the cards do not wrap again (and move the list) when the first list arrives
      // (2026-10-23 test run: layout shift 1.3 on the Kiosk)
      (function () {
        var bar = document.querySelector('.todaybar'), box = document.getElementById('detections_table');
        if (!bar || !box || !window.ResizeObserver) return;
        try { var w0 = localStorage.getItem('today_bar_w'); if (w0) bar.style.maxWidth = w0; } catch (e) {}
        new ResizeObserver(function () {
          var t = box.querySelector('table');
          if (!t && box.querySelector('[data-loading]')) return; // still loading: the kept width stays
          // never narrower than the cards in one row (2026-10-23 run 3: on a first visit a narrow list made the six
          // cards wrap into three rows and pushed the list down, layout shift 0.82): the width they need, one row
          // (measured with the bar unbounded for a moment, in the same frame: nothing is drawn in between)
          bar.style.maxWidth = 'none';
          var stats = document.getElementById('todaystats'), lab = bar.lastElementChild;
          var row = (stats ? stats.getBoundingClientRect().width : 0) + (lab && lab !== stats ? lab.getBoundingClientRect().width + 10 : 0) + 2;
          bar.style.maxWidth = t ? Math.max(t.offsetWidth, Math.ceil(row)) + 'px' : '';
          try { if (t) localStorage.setItem('today_bar_w', bar.style.maxWidth); } catch (e) {}
        }).observe(box);
      })();
    </script>

    <?php if($kiosk == false) { ?>
    <button onclick="switchViews(this);" class="legacyview"><?php echo t('Legacy view'); ?></button>
    <?php } ?>

</div>

<?php if($kiosk == true) { ?>
  <script>
    const scrollToTop = () => {
  const c = document.documentElement.scrollTop || document.body.scrollTop;
  if (c > 0) {
    window.requestAnimationFrame(scrollToTop);
    window.scrollTo(0, c - c / 8);
  }
};
</script>
<button onclick="scrollToTop();" style="background-color: var(--panel,#dbffeb);padding: 20px;position: fixed;bottom: 5%;right: 5%;transition:box-shadow 280ms cubic-bezier(0.4, 0, 0.2, 1);box-shadow:0px 3px 1px -2px rgb(0 0 0 / 20%), 0px 2px 2px 0px rgb(0 0 0 / 14%), 0px 1px 5px 0px rgb(0 0 0 / 12%);"><?php echo t('Scroll To Top'); ?></button>
<?php } ?>

<script>

var timer = '';
searchterm = "";

<?php if($kiosk == false) { ?>
document.getElementById("searchterm").onkeydown = (function(e) {
  if (e.key === "Enter") {
      clearTimeout(timer);
      searchDetections(document.getElementById("searchterm").value);
      document.getElementById("searchterm").blur();
  } else {
     /*
     clearTimeout(timer);
     timer = setTimeout(function() {
        searchDetections(document.getElementById("searchterm").value);

        setTimeout(function() {
            // search auto submitted and now the user is probably scrolling, get the keyboard out of the way & prevent browser from jumping to the top when a video is played
            document.getElementById("searchterm").blur();
        }, 2000);
     }, 1000);
     */
  }
});
<?php } ?>

function switchViews(element) {
  if(searchterm == ""){
    document.getElementById("detections_table").innerHTML = "<h3>" + t('Loading {n} detections...', {n: <?php echo json_encode((string)$todaycount); ?>}) + "</h3>";
  } else {
    document.getElementById("detections_table").innerHTML = "<h3>" + t('Loading...') + "</h3>";
  }
  if(element.innerHTML == t("Legacy view")) {
    element.innerHTML = t("Normal view");
    loadDetections(undefined);
  } else if(element.innerHTML == t("Normal view")) {
    element.innerHTML = t("Legacy view");
    loadDetections(40);
  }
}
function searchDetections(searchvalue) {
    document.getElementById("detections_table").innerHTML = "<h3>" + t('Loading...') + "</h3>";
    searchterm = searchvalue;
    if(document.getElementsByClassName('legacyview')[0].innerHTML == t("Normal view")) {
      loadDetections(undefined,undefined);  
    } else {
      loadDetections(40,undefined);
    }
}
function loadDetections(detections_limit, element=undefined, offset=0) {
  const xhttp = new XMLHttpRequest();
  xhttp.onload = function() {
    <?php if($kiosk == false) { ?>
      document.getElementsByClassName("legacyview")[0].style.display="unset";
    <?php } ?>
    if(typeof element !== "undefined")
    {
     element.remove();
     document.getElementById("detections_table").innerHTML+= this.responseText;
    } else {
     document.getElementById("detections_table").innerHTML= this.responseText;
    }
    // Reinitialize custom audio players for newly loaded elements
    initCustomAudioPlayers();    
  }
  // the unpaged list comes LEGACY_PAGE rows at a time: offset = rows already shown
  var more = offset ? "&offset=" + offset : "";
  if(searchterm != ""){
    xhttp.open("GET", "todays_detections.php?ajax_detections=true&display_limit="+detections_limit+"&searchterm="+searchterm+more, true);
  } else {
    <?php if($kiosk == true) { ?>
      xhttp.open("GET", "todays_detections.php?ajax_detections=true&display_limit="+detections_limit+"&kiosk=true"+more, true);
    <?php } else { ?>
      xhttp.open("GET", "todays_detections.php?ajax_detections=true&display_limit="+detections_limit+more, true);
    <?php } ?>
  }
  xhttp.send();
}
function refreshTodayStats() {
  const xhttp = new XMLHttpRequest();
  xhttp.onload = function() {
    if(this.responseText.length > 0 && !this.responseText.includes("Database is busy")) {
      document.getElementById("todaystats").innerHTML = this.responseText;
    }
  }
  xhttp.open("GET", "todays_detections.php?today_stats=true", true);
  xhttp.send();
}
window.addEventListener("load", function(){
  <?php if($kiosk == true) { ?>
    var topnav = document.getElementById("myTopnav"); if (topnav) topnav.remove();  // the side menu is not drawn in kiosk mode
    // the latest 40, with "Load 40 More" (2026-10-23 test run: the whole day timed out after ~2100 rows on a busy day)
    loadDetections(40);
    refreshTodayStats();
    // refresh the kiosk detection list every minute
    setInterval(function() {
        loadDetections(40);
        refreshTodayStats();
    }, 60000);
  <?php } else { ?>
    loadDetections(40);
  <?php } ?>
});
</script>

<style>
  .tooltip {
  background-color: white;
  border: 1px solid #ccc;
  box-shadow: 0 0 10px rgba(0, 0, 0, 0.5);
  padding: 10px;
  transition: opacity 0.2s ease-in-out;
}
</style>

<script src="static/custom-audio-player.js?v=<?php echo @filemtime(__DIR__ . "/../homepage/static/custom-audio-player.js"); ?>"></script>
<script src="static/generateMiniGraph.js"></script>
<script>
// Listen for the scroll event on the window object
window.addEventListener('scroll', function() {
  // Get all chart elements
  var charts = document.querySelectorAll('.chartdiv');
  
  // Loop through all chart elements and remove them
  charts.forEach(function(chart) {
    chart.parentNode.removeChild(chart);
    window.chartWindow = undefined;
  });
});

</script>
