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
    . $card(t('str_detections_today'), fmt_num((int)$todaycount), 'views.php?view=By%20Hour')
    . $card(t('str_species_today'), (int)$todayspeciestally, 'views.php?view=By%20Hour')
    . $card(t('str_new_today'), $new_today, 'views.php?view=By%20Hour')
    . $card(t('str_best_confidence'), round(floatval($best) * 100) . '%', 'views.php?view=By%20Hour')
    . $card(t('str_total_all_days'), fmt_num((int)$totalcount), 'views.php?view=By%20Hour&amp;from=' . $extra['first'] . '&amp;to=' . date('Y-m-d'), 'kpi kpi2')
    . $card(t('str_species_all_days'), (int)$totalspeciestally, 'views.php?view=Species')
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


// from https://stackoverflow.com/questions/2690504/php-producing-relative-date-time-from-timestamps
function relativeTime($ts)
{
    if(!ctype_digit($ts))
        $ts = strtotime($ts);

    $diff = time() - $ts;
    if($diff == 0)
        return t('str_now_lc');
    elseif($diff > 0)
    {
        $day_diff = floor($diff / 86400);
        if($day_diff == 0)
        {
            if($diff < 60) return t('str_just_now');
            if($diff < 120) return t('str_1_minute_ago');
            if($diff < 3600) return t('str_minutes_ago', array('n' => floor($diff / 60)));
            if($diff < 7200) return t('str_1_hour_ago');
            if($diff < 86400) return t('str_hours_ago', array('n' => floor($diff / 3600)));
        }
        if($day_diff == 1) return t('str_yesterday');
        if($day_diff < 7) return t('str_days_ago', array('n' => $day_diff));
        if($day_diff < 31) return t('str_weeks_ago', array('n' => ceil($day_diff / 7)));
        if($day_diff < 60) return t('str_last_month');
        return date('F Y', $ts);
    }
    else
    {
        $diff = abs($diff);
        $day_diff = floor($diff / 86400);
        if($day_diff == 0)
        {
            if($diff < 120) return t('str_in_a_minute');
            if($diff < 3600) return t('str_in_minutes', array('n' => floor($diff / 60)));
            if($diff < 7200) return t('str_in_an_hour');
            if($diff < 86400) return t('str_in_hours', array('n' => floor($diff / 3600)));
        }
        if($day_diff == 1) return t('str_tomorrow');
        if($day_diff < 4) return date('l', $ts);
        if($day_diff < 7 + (7 - date('w'))) return t('str_next_week');
        if(ceil($day_diff / 7) < 4) return t('str_in_weeks', array('n' => ceil($day_diff / 7)));
        if(date('n', $ts) == date('n') + 1) return t('str_next_month');
        return date('F Y', $ts);
    }
}


if(isset($_GET['ajax_detections']) && $_GET['ajax_detections'] == "true"  ) {
  if(isset($_GET['searchterm'])) {
    if(strtolower(explode(" ", $_GET['searchterm'])[0]) == "not") {
      $not = "NOT ";
      $operator = "AND";
      // only the leading word, in any case ("Not sabiá"; a "not" inside the name stays)
      $_GET['searchterm'] = preg_replace('/^\s*not\s+/i', '', $_GET['searchterm']);
    } else {
      $not = "";
      $operator = "OR";
    }
    // the text as typed (the input filter turned quotes into entities, so a name with an apostrophe never matched),
    // escaped for SQLite: it is built into several queries below (2026-10-20)
    $term = SQLite3::escapeString(html_entity_decode((string)$_GET['searchterm'], ENT_QUOTES));
    // a name (the text has a letter, 2026-10-23 test run): LIKE on five columns walked the whole history when nothing
    // matched (period All: 4 s at ten times the station's data). The species whose common, English, scientific or folder
    // name holds the text are picked from the list of names (accents ignored) and found by the species index; Confidence
    // and Time hold no letters. The names are presentation only (owner 2026-11-05): the database holds the scientific name,
    // so a name is matched here, in PHP, and the query filters by the matching Sci_Name set.
    $raw = trim(html_entity_decode((string)$_GET['searchterm'], ENT_QUOTES));
    $hits = null;
    if ($raw !== '' && preg_match('/\p{L}/u', $raw)) {
      $hits = array();
      $fr = fold_text($raw);  // accents ignored (2026-10-29)
      foreach (species_name_pairs() as $x) {
        $f0 = fold_text($x[0]); $f1 = fold_text($x[1]); $f2 = fold_text(get_english_name($x[0]));
        if (strpos($f0, $fr) !== false || strpos($f1, $fr) !== false || ($f2 !== '' && strpos($f2, $fr) !== false)
          || strpos(str_replace(array(' ', "'"), array('_', ''), $f1), $fr) !== false || strpos(str_replace(' ', '_', $f0), $fr) !== false) $hits[$x[0]] = true;
      }
    }
    $in = $hits ? implode(',', array_map(function ($s) { return "'" . SQLite3::escapeString($s) . "'"; }, array_keys($hits))) : '';
    if ($hits !== null && $not === '') {
      $searchquery = $in !== '' ? 'AND Sci_Name IN (' . $in . ')' : 'AND 0';
    } else {
      // "not <text>" or a text without letters: every column but the name, and the species named by the text left out
      $searchquery = "AND (" . ($hits !== null ? ($in !== '' ? "Sci_Name NOT IN ($in) " : '1 ') . $operator . ' ' : '')
        . "Sci_name ".$not."LIKE '%".$term."%' ".$operator." Confidence ".$not."LIKE '%".$term."%' ".$operator." File_Name ".$not."LIKE '%".$term."%' ".$operator." Time ".$not."LIKE '%".$term."%')";
    }
  } else {
    $searchquery = "";
  }
  if(isset($_GET['display_limit']) && is_numeric($_GET['display_limit'])){
    // the Kiosk: today's detections, 40 a page
    $statement0 = $db->prepare('SELECT Date, Time, Sci_Name, Confidence, File_Name FROM detections WHERE Date == Date(\'now\', \'localtime\') ORDER BY Time DESC LIMIT '.max(0, intval($_GET['display_limit'])-40).',40');
  } elseif(isset($_GET['hard_limit']) && is_numeric($_GET['hard_limit'])) {
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
    // loss (owner 2026-11-04) = the recording lost audio, an xrun (the recorder's overruns): any (0) or more than N ms
    $g_loss = isset($_GET['loss']) && is_numeric($_GET['loss']) ? max(0, min(10000, floatval($_GET['loss']))) : null;
    $g_filter = ($g_conf !== null || $g_prob !== null || $g_most !== null || $g_rare !== null || $g_loss !== null) ? 'on' : '';
    // period of a filtered list (owner 2026-10-20): today (default), 7 days or all. The list walks the Date/Time index
    // from the newest row and stops at the page size, so an unfiltered list is instant; a filter that matches few
    // rows walked the whole history (Low Prob 1.7 s, a rare name 0.6 s) — a period bounds that walk
    $g_period = in_array($_GET['period'] ?? '', array('today', '7d', 'all'), true) ? $_GET['period'] : 'today';
    if (($g_filter !== '' || isset($_GET['searchterm'])) && $g_period !== 'all') {
      $searchquery .= $g_period === 'today' ? " AND Date >= DATE('now', 'localtime')" : " AND Date >= DATE('now', 'localtime', '-6 days')";
    }
    if ($g_conf !== null) $searchquery .= ' AND Confidence < ' . $g_conf;
    if ($g_loss !== null) $searchquery .= ' AND Audio_Loss_N > 0' . ($g_loss > 0 ? ' AND Audio_Loss_Ms > ' . $g_loss : '');
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
    $statement0 = $db->prepare('SELECT Date, Time, Sci_Name, Confidence, Cutoff, Sens, Overlap, Loc_Thresh, Rec_Length, Sp_Override, Mic, Mic_Port, Mic_Gain, Model, Audio_Loss_N, Audio_Loss_Ms, Tz, Utc_Offset, File_Name, '.location_columns_sql().' FROM detections WHERE 1 '.$searchquery.' ORDER BY Date DESC, Time DESC'
      . ($g_prob !== null && empty($g_prob_sql) ? '' : ' LIMIT '.$g_limit.' OFFSET '.$g_offset));
  } else {
    die();
  }
  ensure_db_ok($statement0);
  $result0 = $statement0->execute();

  // Latest page (owner 2026-10-08): the most recent detections as cards — names (common name =
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
    // the common name looked up for the rows shown, never in the SQL: a sort before the LIMIT would look it up on every
    // row read (names are presentation only, owner 2026-11-05)
    while ($g = $result0->fetchArray(SQLITE3_ASSOC)) {
      $g['Com_Name'] = common_name($g['Sci_Name']);
      if (($g_prob ?? null) !== null && empty($g_prob_sql)) {
        $pr = location_probability($g['Sci_Name'], $g['Date']);
        if ($pr !== null && $pr >= $g_prob) continue;
        if ($skip++ < $g_offset) continue;
      }
      if ($n >= $page) { $more = true; break; }
      $n++;
      if ($as_list) { $list_rows[] = $g; continue; }
      $folder = species_folder($g['Sci_Name']);
      $file = $g['Date'] . '/' . $folder . '/' . $g['File_Name'];
      $clip = '/By_Date/' . $file;
      $when = ($g['Date'] !== date('Y-m-d') ? substr($g['Date'], 5) . ' ' : '') . $g['Time'];
      $fj = htmlspecialchars(json_encode($file), ENT_QUOTES);
      echo '<div class="gcard"' . review_item_attrs($file, $g['Com_Name'] . ', ' . $when . ', ' . round($g['Confidence'] * 100) . '%', $g['Sci_Name'], $g) . '><div class="gbody"><div class="gtop">'
        . '<div class="gnames">' . species_review_name($g['Sci_Name'], $g['Com_Name'], true) . '<br><i>' . htmlspecialchars($g['Sci_Name']) . '</i></div>'
        . '<div class="gacts">' . validate_button($file) . '</div></div>'
        . '<div class="gmeta">' . htmlspecialchars($when) . ', ' . round($g['Confidence'] * 100) . '%</div>'
        . '<img class="gspec" loading="lazy" src="' . htmlspecialchars(clip_spectrogram($clip)) . '" alt="' . t('str_spectrogram_lc') . '" title="' . t('str_listen_and_review') . '" onclick="reviewDetection(this)"></div></div>';
    }
    echo $as_list ? detection_review_table($list_rows, true, true, ($_GET['mode'] ?? '') === 'simple') : '</div>';
    // a search without a match is "no match" too, not "no detections yet" (2026-10-23 test run)
    if ($n == 0) echo '<h3>' . ((($g_filter ?? '') !== '' || trim((string)($_GET['searchterm'] ?? '')) !== '') ? t('str_no_detections_match_the_filters') : t('str_no_detections_yet')) . '</h3>';
    // one line under the table (owner 2026-10-21): how many on the left, newer / older in the middle, the range on the right
    if (isset($_GET['gallery'])) {
      echo list_pager(t('str_last_detections', array('select' => '{select}')), $g_offset, $n, $page, $more, 'nowSetCount', 'nowPage', 'nowcount');
    }
    die();
  }

  // the Kiosk list: relative times, the species photo, a plain audio control, no review or actions
  echo '<table>';
  $iterations = 0;
  $image_provider = new Wikipedia();
  if (!isset($_SESSION['images']) || $image_provider->is_reset()) $_SESSION['images'] = [];
  while ($todaytable = $result0->fetchArray(SQLITE3_ASSOC)) {
    $todaytable['Com_Name'] = common_name($todaytable['Sci_Name']);  // presentation only (owner 2026-11-05)
    $iterations++;
    $folder = species_folder($todaytable['Sci_Name']);
    $filename = "/By_Date/" . $todaytable['Date'] . "/" . $folder . "/" . $todaytable['File_Name'];
    // a species looked up before keeps its picture for the session
    $key = array_search($folder, array_column($_SESSION['images'], 0));
    if ($key !== false) {
      $image = $_SESSION['images'][$key];
    } else {
      $cached_image = $image_provider->get_image($todaytable['Sci_Name']) ?: array();
      $image = array($folder, $cached_image["image_url"] ?? '', $cached_image["title"] ?? '', $cached_image["photos_url"] ?? '', $cached_image["author_url"] ?? '', $cached_image["license_url"] ?? '');
      $_SESSION['images'][] = $image;
    } ?>
    <tr class="relative" id="<?php echo $iterations; ?>"><td class="relative">
      <div class="tdhead">
        <?php if (strlen((string)$image[2]) > 0) { ?>
          <?php // escaped (2026-11-04 hardening): the picture's data comes from the internet ?>
          <img onclick='setModalText(<?php echo $iterations; ?>, <?php echo htmlspecialchars(json_encode(urlencode((string)$image[2])), ENT_QUOTES); ?>, <?php echo htmlspecialchars(json_encode((string)$image[3]), ENT_QUOTES); ?>, <?php echo htmlspecialchars(json_encode((string)$image[4]), ENT_QUOTES); ?>, <?php echo htmlspecialchars(json_encode((string)$image[1]), ENT_QUOTES); ?>, <?php echo htmlspecialchars(json_encode((string)$image[5]), ENT_QUOTES); ?>)' src="<?php echo htmlspecialchars((string)$image[1], ENT_QUOTES); ?>" class="img1">
        <?php } ?>
        <div class="tdinfo">
          <div class="tdtime"><?php echo relativeTime(strtotime($todaytable['Time'])); ?></div>
          <?php echo species_title($todaytable['Sci_Name'], '<b><a class="a2" href="views.php?view=Species&amp;sci=' . rawurlencode($todaytable['Sci_Name']) . '" title="' . t('str_open_the_species_page') . '">' . htmlspecialchars($todaytable['Com_Name']) . '</a></b>', '', true, false); ?>
          <div><b><?php echo t('str_confidence_colon'); ?></b> <?php echo round((float)round($todaytable['Confidence'], 2) * 100) . '%'; ?></div>
        </div>
      </div>
      <audio controls preload="none" src="<?php echo htmlspecialchars($filename); ?>"></audio>
    </td></tr>
  <?php }
  echo '</table>';
  if ($iterations == 0) echo '<h3>' . t('str_no_detections_for_today') . '</h3>';
  // the button only while more detections may follow
  if ($iterations >= 40) { ?>
  <center>
  <button class="loadmore" onclick="loadDetections(<?php echo intval($_GET['display_limit']) + 40; ?>, this);"><?php echo t('str_load_40_more'); ?></button>
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

// the page itself is the Kiosk only (views.php sets $kiosk when ACTIVATE_KIOSK is on)
if (!$kiosk) { http_response_code(404); die(); }

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
    <button style="font-weight:bold;color:blue" onclick="hideDialog()"><?php echo t('str_close'); ?></button>
  </dialog>
  <script src="static/dialog-polyfill.js"></script>
  <script>
  var dialog = document.querySelector('dialog');
  dialogPolyfill.registerDialog(dialog);

  function showDialog() {
    document.getElementById('attribution-dialog').showModal();
  }

  function hideDialog() {
    document.getElementById('attribution-dialog').close();
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
    document.getElementById('modalHeading').innerHTML = t('str_photo_attribution', {title: decodeURIComponent(title.replaceAll("+"," "))});
    document.getElementById('modalText').innerHTML = "<div><img style='border-radius:5px;max-height: calc(100vh - 15rem);display: block;margin: 0 auto;' src='"+photolink+"'></div><br><div style='white-space:nowrap'>"+t('str_image_link')+" <a target='_blank' href="+text+">"+text_display+"</a><br>"+t('str_author_link')+" <a target='_blank' href="+authorlink+">"+authorlink_display+"</a><br>"+t('str_license_url')+" <a href="+licenseurl+" target='_blank'>"+licenseurl_display+"</a></div>";
    showDialog();
  }
  </script>  
    <div class="todaybar" style="display:flex;justify-content:space-between;align-items:center;gap:10px;flex-wrap:wrap;margin:4px 0;">
      <div id="todaystats" class="overview"><?php render_today_stats($totalcount, $todaycount, $totalspeciestally, $todayspeciestally, $kiosk); ?></div>
      <div><b><?php echo t('str_todays_detections'); ?></b></div>
    </div>

    <div style="padding-bottom:10px" id="detections_table"><h3 data-loading="1"><?php echo t('str_loading'); ?></h3></div>
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

</div>

  <script>
    const scrollToTop = () => {
  const c = document.documentElement.scrollTop || document.body.scrollTop;
  if (c > 0) {
    window.requestAnimationFrame(scrollToTop);
    window.scrollTo(0, c - c / 8);
  }
};
</script>
<button onclick="scrollToTop();" style="background-color: var(--panel,#dbffeb);padding: 20px;position: fixed;bottom: 5%;right: 5%;transition:box-shadow 280ms cubic-bezier(0.4, 0, 0.2, 1);box-shadow:0px 3px 1px -2px rgb(0 0 0 / 20%), 0px 2px 2px 0px rgb(0 0 0 / 14%), 0px 1px 5px 0px rgb(0 0 0 / 12%);"><?php echo t('str_scroll_to_top'); ?></button>

<script>

function loadDetections(detections_limit, element) {
  const xhttp = new XMLHttpRequest();
  xhttp.onload = function() {
    if (typeof element !== "undefined") {
      element.remove();
      document.getElementById("detections_table").innerHTML += this.responseText;
    } else {
      document.getElementById("detections_table").innerHTML = this.responseText;
    }
  }
  xhttp.open("GET", "todays_detections.php?ajax_detections=true&display_limit=" + detections_limit + "&kiosk=true", true);
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
    var topnav = document.getElementById("myTopnav"); if (topnav) topnav.remove();  // the side menu is not drawn in kiosk mode
    // the latest 40, with "Load 40 More" (2026-10-23 test run: the whole day timed out after ~2100 rows on a busy day)
    loadDetections(40);
    refreshTodayStats();
    // refresh the kiosk detection list every minute
    setInterval(function() {
        loadDetections(40);
        refreshTodayStats();
    }, 60000);
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

