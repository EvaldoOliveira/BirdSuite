<?php
error_reporting(E_ERROR);
ini_set('display_errors', 0);  // errors to the log, never to the page (2026-10-23 security run)
ini_set('session.gc_maxlifetime', 7200);
session_set_cookie_params(7200);
session_start(array('cookie_httponly' => true, 'cookie_samesite' => 'Lax'));  // as common.php BS_SESSION_OPTIONS (2026-10-23 test run)
if (!defined('NOW_PAGE')) define('NOW_PAGE', true); // the Location picklist of Now may show every location (owner 2026-10-21)
require_once 'scripts/common.php';
$home = get_home();
$config = get_config();

set_timezone();
// the session is only read from here on (2026-10-20): released at once, so the page's parallel requests (charts, list,
// stats, status every 5 s) no longer wait for each other on the session file lock
session_write_close();
$myDate = date('Y-m-d');
$chart = "Combo-$myDate.png";

$db = new SQLite3(BS_DB, SQLITE3_OPEN_READONLY);
apply_location_filter($db); // the location chosen in the header (US-58)
$db->busyTimeout(1000);

if(isset($_GET['custom_image'])){
  if(!empty($config["CUSTOM_IMAGE"]) && custom_image_ok($config["CUSTOM_IMAGE"])) {
  ?>
    <br>
    <h3><?php echo htmlspecialchars((string)($config["CUSTOM_IMAGE_TITLE"] ?? '')); ?></h3>
    <?php
    $info = getimagesize($config["CUSTOM_IMAGE"]);
    $image_base64 = base64_encode(file_get_contents($config["CUSTOM_IMAGE"]));
    $img_tag = "<img alt='' src='data:" . htmlspecialchars($info['mime']) . ";base64," . $image_base64 . "'>";
    echo $img_tag;
  }
  die();
}

if(isset($_GET['blacklistimage'])) {
  ensure_authenticated(t('You must be authenticated.'));
  $imageid = $_GET['blacklistimage'];
  $file_handle = fopen($home."/BirdNET-Pi/config/scripts/blacklisted_images.txt", 'a+');
  fwrite($file_handle, $imageid . "\n");
  fclose($file_handle);
  unset($_SESSION['images']);
  die("OK");
}

// Analysis settings from the Latest page: CONFIDENCE, SENSITIVITY, OVERLAP (the analysis reloads them on the next file)
if (isset($_GET['set_analysis'])) {
  ensure_authenticated(t('You must be authenticated to change the settings.'));
  $limits = array('CONFIDENCE' => array('confidence', 0.01, 0.99), 'SENSITIVITY' => array('sensitivity', 0.5, 1.5), 'OVERLAP' => array('overlap', 0.0, 2.9),
                  'SF_THRESH' => array('sf_thresh', 0.0005, 0.99));
  $f = BS_CONF;
  $c = file_get_contents($f);
  foreach ($limits as $key => $l) {
    $v = str_replace(',', '.', trim($_GET[$l[0]] ?? ''));
    if ($v === '' || !is_numeric($v) || floatval($v) < $l[1] || floatval($v) > $l[2]) { echo t('Invalid {name} ({min} - {max})', array('name' => $l[0], 'min' => $l[1], 'max' => $l[2])); die(); }
    $v = rtrim(rtrim(sprintf($key === 'SF_THRESH' ? '%.4f' : '%.2f', floatval($v)), '0'), '.');
    $c = preg_match("/^$key=/m", $c) ? preg_replace("/^$key=.*/m", "$key=$v", $c) : $c . "\n$key=$v\n";
  }
  // recording length (owner 2026-10-09): whole seconds 3–60; the extraction length can not be longer; the recording
  // service is restarted so the next file has the new length
  $len = trim($_GET['recording_length'] ?? '');
  $restart = false;
  if ($len !== '') {
    if (!ctype_digit($len) || intval($len) < 3 || intval($len) > 60) { echo t('Invalid recording length (3 to 60 s)'); die(); }
    $restart = intval($len) !== intval($config['RECORDING_LENGTH'] ?? 15);
    $c = preg_match('/^RECORDING_LENGTH=/m', $c) ? preg_replace('/^RECORDING_LENGTH=.*/m', 'RECORDING_LENGTH=' . intval($len), $c) : $c . "\nRECORDING_LENGTH=" . intval($len) . "\n";
    if (intval($config['EXTRACTION_LENGTH'] ?? 0) > intval($len)) $c = preg_replace('/^EXTRACTION_LENGTH=.*/m', 'EXTRACTION_LENGTH=' . intval($len), $c);
  }
  $ok = file_put_contents($f, $c) !== false;
  if ($ok && $restart) exec('sudo -n /usr/local/sbin/birdsuite-svc restart birdnet_recording.service > /dev/null 2>&1 &');
  echo $ok ? 'OK' : t('Error writing the settings');
  die();
}

// The station moved? (owner 2026-10-10) scripts/location_check.py compares the network position with the coordinates
// at boot and writes location_check.json; every page asks (location_moved.php). location=use: the detected coordinates (4 decimals), the
// Brazilian state species list when the current filter is a state, the detected timezone; location=keep: remembered,
// asked again only after another move beyond the location's radius, at least LOCATION_IP_ERROR_KM (owner 2026-10-21)
if (isset($_GET['location'])) {
  ensure_authenticated(t('You must be authenticated to change the settings.'));
  $f = BS_STATE . '/location_check.json';
  $st = json_decode((string)@file_get_contents($f), true);
  if (!is_array($st) || empty($st['moved'])) { echo t('Nothing to do'); die(); }
  $user = get_user();
  if ($_GET['location'] === 'update') {
    // simple mode (owner 2026-10-29, US-125): the station moved for good, the current location takes the detected
    // coordinates and timezone (the state species list follows them, apply_station_coordinates); no second location
    $cur = location_current();
    if (!$cur) { echo t('Unknown location'); die(); }
    $lat = round(floatval($st['lat']), 4); $lon = round(floatval($st['lon']), 4);
    $list = locations_load();
    foreach ($list as &$x) {
      if (intval($x['id']) !== intval($cur['id'])) continue;
      $x['lat'] = $lat; $x['lon'] = $lon;
      if (in_array((string)($st['timezone'] ?? ''), DateTimeZone::listIdentifiers(), true)) $x['timezone'] = $st['timezone'];
      unset($x['species_list']);  // the list of the state there, as the coordinates give it
    }
    unset($x);
    if (!locations_save($list)) { echo t('Error writing the settings'); die(); }
    $switch_to = intval($cur['id']);
    $st['moved'] = false; $st['configured'] = array('lat' => $lat, 'lon' => $lon); $st['distance_km'] = 0; $st['applied'] = date('Y-m-d H:i:s');
  } elseif ($_GET['location'] === 'use' || ctype_digit((string)$_GET['location'])) {
    // use = the detected position, as a location of its own when no location holds it (named after the city);
    // a location id = the station is at that registered location (US-58, owner 2026-10-20)
    if ($_GET['location'] === 'use') {
      $lat = round(floatval($st['lat']), 4); $lon = round(floatval($st['lon']), 4);
      if (!location_at($lat, $lon)) {
        $list = locations_load();
        $list[] = array('id' => max(array_map('intval', array_column($list, 'id'))) + 1,
          'name' => trim(($st['city'] ?? '') . (($st['region'] ?? '') !== '' ? ', ' . $st['region'] : '')) ?: "$lat, $lon",
          'lat' => $lat, 'lon' => $lon, 'radius_km' => LOCATION_RADIUS_KM);
        // the detected timezone comes with it
        if (in_array((string)($st['timezone'] ?? ''), DateTimeZone::listIdentifiers(), true)) $list[count($list) - 1]['timezone'] = $st['timezone'];
        locations_save($list);
      }
    } else {
      $l = location_by_id($_GET['location']);
      if (!$l) { echo t('Unknown location'); die(); }
      $lat = round(floatval($l['lat']), 4); $lon = round(floatval($l['lon']), 4);
    }
    // the change itself runs through scripts/location_switch.php (owner 2026-10-21): the page starts it with this id
    // (the location chosen by its id: two locations may share coordinates)
    $at = ctype_digit((string)$_GET['location']) ? location_by_id($_GET['location']) : location_at($lat, $lon);
    if (!$at) { echo t('Unknown location'); die(); }
    $switch_to = intval($at['id']);
    // a new place in simple mode (US-125): several locations are switched on, so both show and the old one can come back
    if ($_GET['location'] === 'use' && !multi_location()) {
      $c = (string)file_get_contents(BS_CONF);
      $c = preg_match('/^MULTI_LOCATION=/m', $c) ? preg_replace('/^MULTI_LOCATION=.*/m', 'MULTI_LOCATION=1', $c) : rtrim($c, "\n") . "\nMULTI_LOCATION=1\n";
      if (file_put_contents(BS_CONF, $c) === false) { echo t('Error writing the settings'); die(); }
      get_config(true);
    }
    $st['moved'] = false; $st['configured'] = array('lat' => $lat, 'lon' => $lon); $st['distance_km'] = 0; $st['applied'] = date('Y-m-d H:i:s');
  } else {
    $st['moved'] = false; $st['dismissed'] = array('lat' => $st['lat'], 'lon' => $st['lon']);
  }
  if (file_put_contents($f, json_encode($st, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)) === false) { echo t('Error'); die(); }
  // ID:<id>:<name> (the name for the window of the change; the page has no list of locations in simple mode)
  echo isset($switch_to) ? 'ID:' . $switch_to . ':' . ((location_by_id($switch_to) ?: array())['name'] ?? '') : 'OK';
  die();
}

// Microphone reconnected (US-58, owner 2026-10-20): scripts/mic_hotplug.sh writes state/mic_reconnected.json when the
// microphone comes back after a removal; the Latest page asks where the station is. micloc=same: it did not move;
// micloc=<id>: at that registered location; micloc=new (name, lat, lon): a new location, made current
if (isset($_GET['micloc'])) {
  ensure_authenticated(t('You must be authenticated to change the settings.'));
  $a = $_GET['micloc'];
  if ($a === 'new') {
    $name = trim(preg_replace('/[\x00-\x1F\x7F<>"]/', '', (string)($_GET['name'] ?? '')));
    $lat = floatval($_GET['lat'] ?? ''); $lon = floatval($_GET['lon'] ?? '');
    if ($name === '' || abs($lat) > 90 || abs($lon) > 180 || ($lat == 0 && $lon == 0)) { echo t('A name and valid coordinates are needed'); die(); }
    $list = locations_load();
    $id = max(array_map('intval', array_column($list, 'id'))) + 1;
    $list[] = array('id' => $id, 'name' => mb_substr($name, 0, 60), 'lat' => round($lat, 4), 'lon' => round($lon, 4), 'radius_km' => LOCATION_RADIUS_KM,
                    'timezone' => station_timezone(), 'species_list' => (string)(get_config()['SPECIES_LIST'] ?? ''));
    if (!locations_save($list)) { echo t('Error writing the locations'); die(); }
    // the change itself runs through scripts/location_switch.php (owner 2026-10-21): the page starts it with this id
    echo 'ID:' . $id;
    die();
  } elseif ($a !== 'same') { echo t('Error'); die(); }
  @unlink(mic_reconnected_file());
  echo 'OK';
  die();
}

// Currently Analyzing status (owner 2026-10-09): recordings waiting for the analysis (StreamData *.wav minus the one
// being recorded), how far behind real time that is, and the recording the spectrogram shows (analyzing_now.txt)
if (isset($_GET['analysis_status'])) {
  header('Content-Type: application/json');
  $sd = rtrim($config['RECS_DIR'] ?? ($home . '/BirdSongs'), '/') . '/StreamData';
  $waiting = max(0, count(glob($sd . '/*.wav') ?: array()) - 1);
  $now = trim((string)@file_get_contents($sd . '/analyzing_now.txt'));
  $shown = preg_match('/(\d{4}-\d{2}-\d{2})_(\d{2})h(\d{2})m(\d{2})s/', basename($now), $m) ? "$m[2]:$m[3]:$m[4]" : '';
  // behind = age of the recording being analysed (its name carries when it started); without it, waiting × length
  $behind = $shown !== '' ? max(0, time() - strtotime("$m[1] $shown")) : $waiting * max(1, intval($config['RECORDING_LENGTH'] ?? 15));
  $running = trim((string)shell_exec('systemctl is-active birdnet_analysis.service 2>/dev/null')) === 'active';
  echo json_encode(array('waiting' => $waiting, 'behind' => $behind, 'shown' => $shown, 'running' => $running));
  die();
}

// "Set as default" of Currently Analyzing: NOW_ANALYZING = show | hide
if (isset($_GET['set_now_analyzing'])) {
  ensure_authenticated(t('You must be authenticated to change the settings.'));
  $v = $_GET['set_now_analyzing'] === 'hide' ? 'hide' : 'show';
  $f = BS_CONF;
  $c = file_get_contents($f);
  $c = preg_match('/^NOW_ANALYZING=/m', $c) ? preg_replace('/^NOW_ANALYZING=.*/m', "NOW_ANALYZING=$v", $c)
    : $c . "\n## NOW_ANALYZING: the Latest page opens with the Currently Analyzing spectrogram shown (show) or hidden (hide)\nNOW_ANALYZING=$v\n";
  echo (file_put_contents($f, $c) !== false) ? 'OK' : t('Error writing the settings');
  die();
}

// "Set as default" of the Latest page: NOW_VIEW = simple | list (Full) | spectrogram, and with it (owner 2026-10-23) how many
// detections (NOW_COUNT), the period of a filtered list (NOW_PERIOD) and the values the filters start with (NOW_LOWPROB,
// NOW_LOWCONF, NOW_UNCOMMON); each one optional
if (isset($_GET['set_now_view'])) {
  ensure_authenticated(t('You must be authenticated to change the settings.'));
  $set = array('NOW_VIEW' => in_array($_GET['set_now_view'], array('simple', 'list'), true) ? $_GET['set_now_view'] : 'spectrogram');
  if (isset($_GET['count']) && in_array(intval($_GET['count']), array(10, 25, 50, 100), true)) $set['NOW_COUNT'] = intval($_GET['count']);
  if (isset($_GET['period']) && in_array($_GET['period'], array('today', '7d', 'all'), true)) $set['NOW_PERIOD'] = $_GET['period'];
  foreach (array('lowprob' => 'NOW_LOWPROB', 'lowconf' => 'NOW_LOWCONF') as $p => $k) {
    if (isset($_GET[$p]) && ctype_digit($_GET[$p]) && intval($_GET[$p]) >= 5 && intval($_GET[$p]) <= 95) $set[$k] = intval($_GET[$p]);
  }
  if (isset($_GET['uncommon']) && ctype_digit($_GET['uncommon']) && intval($_GET['uncommon']) >= 1 && intval($_GET['uncommon']) <= 100000) $set['NOW_UNCOMMON'] = intval($_GET['uncommon']);
  // the Rare filter's days are the rare species threshold (owner 2026-10-25: moved here from Settings › Themes)
  if (isset($_GET['rare']) && ctype_digit($_GET['rare']) && intval($_GET['rare']) >= 1 && intval($_GET['rare']) <= 3650) $set['RARE_SPECIES_THRESHOLD'] = intval($_GET['rare']);
  $f = BS_CONF;
  $c = file_get_contents($f);
  foreach ($set as $k => $v) {
    $c = preg_match('/^' . $k . '=/m', $c) ? preg_replace('/^' . $k . '=.*/m', "$k=$v", $c) : $c . "\n$k=$v\n";
  }
  echo (file_put_contents($f, $c) !== false) ? 'OK' : t('Error writing the settings');
  die();
}

if(isset($_GET['fetch_chart_string']) && $_GET['fetch_chart_string'] == "true") {
  $myDate = date('Y-m-d');
  $chart = "Combo-$myDate.png";
  echo $chart;
  die();
}

if(isset($_GET['ajax_detections']) && $_GET['ajax_detections'] == "true" && isset($_GET['previous_detection_identifier'])) {

  $statement4 = $db->prepare('SELECT Com_Name, Sci_Name, Date, Time, Confidence, File_Name FROM detections ORDER BY Date DESC, Time DESC LIMIT 15');
  ensure_db_ok($statement4);
  $result4 = $statement4->execute();
  if(!isset($_SESSION['images'])) {
    $_SESSION['images'] = [];
  }
  $iterations = 0;
  $image_provider = null;

  // hopefully one of the 5 most recent detections has an image that is valid, we'll use that one as the most recent detection until the newer ones get their images created
  while($mostrecent = $result4->fetchArray(SQLITE3_ASSOC)) {
    $comname = species_folder($mostrecent['Sci_Name'] ?? '', $mostrecent['Com_Name'], $mostrecent['File_Name']);
    $sciname = preg_replace('/ /', '_', $mostrecent['Sci_Name']);
    $comnamegraph = str_replace("'", "\'", $mostrecent['Com_Name']);
    $filename = "By_Date/".$mostrecent['Date']."/".$comname."/".$mostrecent['File_Name'];

    // check to make sure the image actually exists, sometimes it takes a minute to be created\
    if(file_exists($home."/BirdSongs/Extracted/".$filename.".png") || file_exists($home."/BirdSongs/Extracted/".$filename.".jpg")){
      if($_GET['previous_detection_identifier'] == $filename) { die(); }
      if($_GET['only_name'] == "true") { echo $comname.",".$filename;die(); }

      $iterations++;

      if (!empty($config["IMAGE_PROVIDER"])) {
        if ($image_provider === null) {
          $image_provider = new Wikipedia();
          if ($image_provider->is_reset()) {
            $_SESSION['images'] = [];
          }
        }

        // if we already searched for this species before, use the previous image rather than doing an unneccesary api call
        $key = array_search($comname, array_column($_SESSION['images'], 0));
        if ($key !== false) {
          $image = $_SESSION['images'][$key];
        } else {
          $cached_image = $image_provider->get_image($mostrecent['Sci_Name']);
          array_push($_SESSION["images"], array($comname, $cached_image["image_url"], $cached_image["title"], $cached_image["photos_url"], $cached_image["author_url"], $cached_image["license_url"]));
          $image = $_SESSION['images'][count($_SESSION['images']) - 1];
        }
      }
    ?>
        <style>
        .fade-in {
          opacity: 1;
          animation-name: fadeInOpacity;
          animation-iteration-count: 1;
          animation-timing-function: ease-in;
          animation-duration: 1s;
        }

        @keyframes fadeInOpacity {
          0% {
            opacity: 0;
          }
          100% {
            opacity: 1;
          }
        }
        </style>
        <table class="<?php echo ($_GET['previous_detection_identifier'] == 'undefined') ? '' : 'fade-in';  ?>">
          <h3><?php echo t('Most Recent Detection:'); ?> <span style="font-weight: normal;"><?php echo $mostrecent['Date']." ".$mostrecent['Time'];?></span></h3>
          <tr>
            <td class="relative"><?php echo detection_actions($mostrecent['Date'] . '/' . str_replace(array("'", ' '), array('', '_'), $mostrecent['Com_Name']) . '/' . $mostrecent['File_Name']); ?>
            <!-- photo on the left, then names (common name = link to the species page, scientific, English), links and
                 confidence: like Today's Detections (owner 2026-10-08) -->
            <div class="tdhead">
              <?php if(!empty($config["IMAGE_PROVIDER"]) && strlen($image[2]) > 0) { ?>
                <img onclick='setModalText(<?php echo $iterations; ?>,"<?php echo urlencode($image[2]); ?>", "<?php echo $image[3]; ?>", "<?php echo $image[4]; ?>", "<?php echo $image[1]; ?>", "<?php echo $image[5]; ?>")' src="<?php echo $image[1]; ?>" class="img1">
              <?php } ?>
              <div class="tdinfo">
                <?php echo species_title($mostrecent['Sci_Name'], '<b><a class="a2" href="views.php?view=Bird&amp;sci=' . rawurlencode($mostrecent['Sci_Name']) . '" title="' . t('Open the species page') . '">' . $mostrecent['Com_Name'] . '</a></b>',
                  '<img class="splink" title="' . t('View species stats') . '" onclick="generateMiniGraph(this, \'' . $comnamegraph . '\')" src="images/chart.svg">', true, false); ?>
                <div><?php echo t('Confidence:'); ?> <?php echo $percent = round((float)round($mostrecent['Confidence'],2) * 100 ) . '%';?></div>
              </div>
            </div>
            <div class='custom-audio-player' data-audio-src="<?php echo $filename; ?>" data-image-src="<?php echo clip_spectrogram($filename);?>"></div>
            </td>
          </tr>
        </table> <?php break;
      }
  }
  if($iterations == 0) {
    $statement2 = $db->prepare('SELECT COUNT(*) FROM detections WHERE Date == DATE(\'now\', \'localtime\')');
    ensure_db_ok($statement2);
    $result2 = $statement2->execute();
    $todaycount = $result2->fetchArray(SQLITE3_ASSOC);
    if($todaycount['COUNT(*)'] > 0) {
      echo '<h3>' . t('Your system is currently processing a backlog of audio. This can take several hours before normal functionality of your BirdSuite resumes.') . '</h3>';
    } else {
      echo "<h3>No Detections For Today.</h3>";
    }
  }
  die();
}

if(isset($_GET['ajax_left_chart']) && $_GET['ajax_left_chart'] == "true") {

  $chart_data = get_summary();
  // species detected today for the first time (never before today)
  $new_today = 0;
  $stmt_new = get_db()->prepare("SELECT COUNT(DISTINCT Sci_Name) AS n FROM detections WHERE Date = DATE('now', 'localtime')
    AND Sci_Name NOT IN (SELECT DISTINCT Sci_Name FROM detections WHERE Date < DATE('now', 'localtime'))");
  if ($stmt_new !== false && ($res_new = $stmt_new->execute()) !== false) {
    $new_today = (int)($res_new->fetchArray(SQLITE3_ASSOC)['n'] ?? 0);
  }
?>
<table class="totals">
  <tr>
    <th><?php echo t('#Total'); ?></th>
    <th><?php echo t('#Today'); ?></th>
    <th><?php echo t('Sp. Total'); ?></th>
    <th><?php echo t('Sp. Today'); ?></th>
    <th><?php echo t('New Today'); ?></th>
  </tr>
  <tr>
    <td><?php echo $chart_data['totalcount'];?></td>
    <td><form action="" method="GET"><button type="submit" name="view" value="Now"><?php echo $chart_data['todaycount'];?></button></form></td>
    <td><form action="" method="GET"><button type="submit" name="view" value="Species Stats"><?php echo $chart_data['totalspeciestally'];?></button></form></td>
    <td><form action="" method="GET"><input type="hidden" name="view" value="Recordings"><button type="submit" name="date" value="<?php echo date('Y-m-d');?>"><?php echo $chart_data['speciestally'];?></button></form></td>
    <td><?php echo $new_today; ?></td>
  </tr>
</table>
<?php
  die();
}

if(isset($_GET['ajax_center_chart']) && $_GET['ajax_center_chart'] == "true") {

  // the summary is cached for a minute (get_summary), so it is read again instead of passed through the session
  $chart_data = get_summary();
?>
  <table><tr>
  <th><?php echo t('Total'); ?></th>
  <th><?php echo t('Today'); ?></th>
  <th><?php echo t('Last Hour'); ?></th>
  <th><?php echo t('Species Total'); ?></th>
  <th><?php echo t('Species Today'); ?></th>
      </tr>
      <tr>
      <td><?php echo $chart_data['totalcount'];?></td>
      <td><form action="" method="GET"><input type="hidden" name="view" value="Now"><?php echo $chart_data['todaycount'];?></td></form>
      <td><?php echo $chart_data['hourcount'];?></td>
      <td><form action="" method="GET"><button type="submit" name="view" value="Species Stats"><?php echo $chart_data['totalspeciestally'];?></button></td></form>
      <td><form action="" method="GET"><input type="hidden" name="view" value="Recordings"><button type="submit" name="date" value="<?php echo date('Y-m-d');?>"><?php echo $chart_data['speciestally'];?></button></td></form>
  </tr>
  </table>

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
  <title>Overview</title>
</head>';
}
?>
<div class="overview">
  <dialog style="margin-top: 5px;max-height: 95vh;
  overflow-y: auto;overscroll-behavior:contain" id="attribution-dialog">
    <h1 id="modalHeading"></h1>
    <p id="modalText"></p>
    <button onclick="hideDialog()"><?php echo t('Close'); ?></button>
    <button style="font-weight:bold;color:blue" onclick="if(confirm(t('Are you sure you want to blacklist this image?'))) { blacklistImage(); }" <?php if($config["IMAGE_PROVIDER"] === 'WIKIPEDIA'){ echo 'hidden';} ?> ><?php echo t('Blacklist this image'); ?></button>
  </dialog>
  <script src="static/dialog-polyfill.js"></script>
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
<div class="overview-stats part-<?php echo ($overview_part ?? 'now') === 'records' ? 'records' : 'now'; ?>">
<div class="left-column">
</div>
<div class="right-column">
<div class="center-column">
</div>
<?php
// the new / rare species lists and the totals column are hidden on Now (style.css .part-now): their queries run only
// for the records part (owner 2026-10-21, Now load time)
$now_part = ($overview_part ?? 'now') !== 'records';
$new_species = [];
$rare_species = [];
if (!$now_part) {
$statement = $db->prepare("
SELECT d_today.Com_Name, d_today.Sci_Name, d_today.Date, d_today.Time, d_today.Confidence, d_today.File_Name, 
       MAX(d_today.Confidence) as MaxConfidence,
       (SELECT MAX(Date) FROM detections d_prev WHERE d_prev.Sci_Name = d_today.Sci_Name AND d_prev.Date < DATE('now', 'localtime')) as LastSeenDate,
       (SELECT COUNT(*) FROM detections d_occ WHERE d_occ.Sci_Name = d_today.Sci_Name AND d_occ.Date = DATE('now', 'localtime')) as OccurrenceCount
FROM detections d_today
WHERE d_today.Date = DATE('now', 'localtime')
GROUP BY d_today.Sci_Name
");
ensure_db_ok($statement);
$result = $statement->execute();

$rare_species_threshold = isset($config['RARE_SPECIES_THRESHOLD']) ? $config['RARE_SPECIES_THRESHOLD'] : 30;
while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
    $last_seen_date = $row['LastSeenDate'];
    if ($last_seen_date === NULL) {
        $new_species[] = $row;
    } else {
        $date1 = new DateTime($last_seen_date);
        $date2 = new DateTime('now');
        $interval = $date1->diff($date2);
        $days_ago = $interval->days;
        if ($days_ago > $rare_species_threshold) {
            $row['DaysAgo'] = $days_ago;
            $rare_species[] = $row;
        }
    }
}
}

if (!isset($_SESSION['images'])) {
    $_SESSION['images'] = [];
}

function display_species($species_list, $title, $show_last_seen=false) {
    global $config, $_SESSION, $image_provider;
    $species_count = count($species_list);
    if ($species_count > 0): ?>
        <div class="<?php echo strtolower(str_replace(' ', '_', $title)); ?>">
            <?php if ($title !== 'New Species') { // the new species count is in the totals (New Sp. Today) ?>
            <h2 style="text-align:center;"><?php echo $title === 'Rare Species' ? t('{n} rare species detected today!', array('n' => $species_count)) : $species_count . ' ' . strtolower($title) . ' detected today!'; ?></h2>
            <?php } ?>
            <?php if ($species_count > 5 && $title === 'New Species'): // no recordings button for new species (owner 2026-10-08) ?>
            <?php elseif ($species_count > 5): ?>
                <table><tr><td style="text-align:center;"><form action="" method="GET"><input type="hidden" name="view" value="Recordings"><button type="submit" name="date" value="<?php echo date('Y-m-d');?>"><?php echo t('Open Today\'s detections page'); ?></button></form></td></tr></table>
            <?php else: ?>
                <table>
                    <?php
                    $iterations = 0;
                    foreach($species_list as $todaytable):
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

                        $image_url = ""; // Default empty image URL
                        
                        if (!empty($config["IMAGE_PROVIDER"])) {
                          if ($image_provider === null) {
                            $image_provider = new Wikipedia();
                            if ($image_provider->is_reset()) {
                              $_SESSION['images'] = [];
                            }
                          }

                            // Check if the image has been cached in the session
                            $key = array_search($comname, array_column($_SESSION['images'], 0));
                            if ($key !== false) {
                                $image = $_SESSION['images'][$key];
                            } else {
                                // Retrieve the image from Flickr API and cache it
                                $cached_image = $image_provider->get_image($todaytable['Sci_Name']);
                                array_push($_SESSION["images"], array($comname, $cached_image["image_url"], $cached_image["title"], $cached_image["photos_url"], $cached_image["author_url"], $cached_image["license_url"]));
                                $image = $_SESSION['images'][count($_SESSION['images']) - 1];
                            }
                            $image_url = $image[1] ?? ""; // Get the image URL if available
                        }

                        $last_seen_text = "";
                        if ($show_last_seen && isset($todaytable['DaysAgo'])) {
                            $days_ago = $todaytable['DaysAgo'];
                            if ($days_ago > 30) {
                                $months_ago = floor($days_ago / 30);
                                $last_seen_text = "<br><i><span class='text left'>" . t('Last seen: ') . "</span>" . t('{n}mo ago', array('n' => $months_ago)) . "</i>";
                            } else {
                                $last_seen_text = "<br><i><span class='text left'>" . t('Last seen: ') . "</span>" . t('{n}d ago', array('n' => $days_ago)) . "</i>";
                            }
                        }

                        $occurrence_text = "";
                        if (isset($todaytable['OccurrenceCount']) && $todaytable['OccurrenceCount'] > 1) {
                            $occurrence_text = " ({$todaytable['OccurrenceCount']}x)";
                        }
                    ?>
                    <tr class="relative" id="<?php echo $iterations; ?>">
                        <td><?php if (!empty($image_url)): ?>
                          <img onclick='setModalText(<?php echo $iterations; ?>,"<?php echo urlencode($image[2]); ?>", "<?php echo $image[3]; ?>", "<?php echo $image[4]; ?>", "<?php echo $image[1]; ?>", "<?php echo $image[5]; ?>")' src="<?php echo $image_url; ?>" style="max-width: none; height: 50px; width: 50px; border-radius: 5px; cursor: pointer;" class="img1" title="<?php echo t('Image from Flickr'); ?>" />
                        <?php endif; ?></td>
                        <td id="recent_detection_middle_td">
                            <div><form action="" method="GET">
                                    <input type="hidden" name="view" value="Species Stats">
                                    <?php echo species_title($todaytable['Sci_Name'], '<b><a class="a2" href="views.php?view=Bird&amp;sci=' . rawurlencode($todaytable['Sci_Name']) . '" title="' . t('Open the species page') . '">' . $todaytable['Com_Name'] . '</a></b>', '', true, false); ?>
                                    <i>
                                        <?php if ($show_last_seen): ?>
                                            <img style="height: 1em;cursor:pointer;float:unset;display:inline" title="<?php echo t('View species stats'); ?>" onclick="generateMiniGraph(this, '<?php echo $comnamegraph; ?>', 160)" width="25" src="images/chart.svg">
                                        <?php endif; ?>
                                        <?php echo detection_actions($filename_formatted, false, 'height: 1em;float:unset;display:inline', 16); ?>
                                    </i>
                            </form></div>
                        </td>
                        <td style="white-space: nowrap;"><?php
                                echo '<span class="text left">' . t('Max confidence: ') . '</span>' . round($todaytable['Confidence'] * 100 ) . '%' . $occurrence_text;
                                echo "<br><span class='text left'>" . t('First detection: ') . "</span>{$todaytable['Time']}";
                                echo $last_seen_text;
                        ?></td>
                      </tr>
                    <?php endforeach; ?>
                </table>
            <?php endif; ?>
        </div>
    <?php endif;
}

display_species($new_species, 'New Species');
display_species($rare_species, 'Rare Species', true);
?>
<script src="static/chart-rows.js"></script>
<div class="chart">
<script>
  // the totals table starts where the chart's bars start: daily_plot.py draws the bar axes from 12.5 % of
  // the image width (subplots_adjust left=0.125), the species labels sit before that (owner 2026-10-08)
  function alignTotalsToChart() {
    var img = document.getElementById('chart'), col = document.querySelector('.overview-stats .left-column');
    var t = col ? col.querySelector('table') : null;
    if (!img || !t || !img.offsetWidth) return;
    col.style.justifyContent = 'flex-start';
    var x = img.getBoundingClientRect().left + img.offsetWidth * 0.125 - col.getBoundingClientRect().left;
    t.style.setProperty('margin-left', Math.max(0, Math.round(x)) + 'px', 'important');
  }
  window.addEventListener('resize', alignTotalsToChart);
  new MutationObserver(alignTotalsToChart).observe(document.body, { childList: true, subtree: true });
</script>
<?php
$refresh = $config['RECORDING_LENGTH'];
$dividedrefresh = $refresh/4;
if($dividedrefresh < 1) { 
  $dividedrefresh = 1;
}
$time = time();
if (file_exists('./Charts/'.$chart)) {
  // every species row of the chart opens its species page (row map written by daily_plot.py)
  echo location_label(true); // the chart is drawn for the current location (owner 2026-10-21)
  echo "<div class='chartwrap'><img id='chart' src=\"Charts/$chart?nocache=$time\" onload='chartRowLinks(this)'></div>";
} 
?>
</div>

<?php if (!time_confirmed()) { ?>
<div class="now-only locmoved" id="timewarn">&#9200; <?php echo t('The station\'s clock is not confirmed (no internet since the boot): it shows {time} and detections are stamped with it.', array('time' => '<b>' . date('Y-m-d H:i') . '</b>')); ?>
  <button type="button" onclick="useDeviceTime()"><?php echo t('Use this device\'s time'); ?></button></div>
<script>
function useDeviceTime() {
  var x = new XMLHttpRequest();
  x.onload = function () { var r = {}; try { r = JSON.parse(this.responseText); } catch (e) {} if (r.ok) location.reload(); else alert(this.status === 401 ? t('Log in first.') : (r.error || this.responseText)); };
  x.open('POST', 'scripts/network.php?do=time', true); x.setRequestHeader('Content-Type', 'application/json');
  x.send(JSON.stringify({epoch: Math.floor(Date.now() / 1000)}));
}
</script>
<?php } ?>
<?php $curloc = location_current(); // the station's current location: its line is at the bottom of the page (owner 2026-10-20) ?>
<?php // the microphone question is a window on every page now (owner 2026-10-24, static/location-switch.js) ?>
<!-- today's totals and the search, formerly the top of Today's Detections (owner 2026-10-09) -->
<?php // the question "did the station move?" is on every page now (owner 2026-10-30, scripts/location_moved.php) ?>
<!-- Latest page (owner 2026-10-08): Currently Analyzing first, then the 30 most recent detections as cards. The most recent
     detection card is no longer shown; it is still loaded (hidden) because the page watches it to notice a new
     detection and refresh the cards (30, like Best Detections). -->
<?php // first line (owner 2026-10-21): the analysis state ?>
<h3 class="now-only nowsec nowanalyzing">
  <span id="analysis_status" class="nowstatus" title="<?php echo t('Recordings waiting for the analysis (behind real time), and the time of the recording the spectrogram shows'); ?>"></span>
</h3>
<?php // the Live Spectro button on its own line, on the left, right above the live spectrogram (owner 2026-10-21) ?>
<div class="now-only nowmodes nowlivespec"><button type="button" id="analyzing_toggle" onclick="toggleAnalyzing()" title="<?php echo t('Show or hide the live spectrogram'); ?>"><?php echo t('Live Spectro'); ?></button></div>
<style>
/* one line, also on a phone (owner 2026-10-21): the state shrinks, the Live Spectro button stays on the right */
h3.nowsec.nowanalyzing { display: flex; align-items: center; gap: 8px; flex-wrap: nowrap !important; margin: 0 0 2px !important; min-height: 1.5em; padding-top: 0; } /* one line kept while the state loads (2026-10-23 test run: it moved the page when filled) */
.nowanalyzing .nowstatus { margin: 0; flex: 1 1 auto; min-width: 0; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.nowlivespec { display: block; text-align: left; margin: 0 0 4px; }
</style>
<?php
$refresh = $config['RECORDING_LENGTH'];
$time = time();
echo "<img id=\"spectrogramimage\" src=\"spectrogram.png?nocache=$time\">";
$now_view_cfg = in_array($config['NOW_VIEW'] ?? 'list', array('simple', 'spectrogram'), true) ? $config['NOW_VIEW'] : 'list';

?>
<?php // the parts of the view shown or hidden before the first paint (2026-10-23 test run: the live spectrogram and its
// button went away at DOMContentLoaded and moved the list, layout shift 0.13-0.25); the rules of nowView / applyAnalyzing
// below, which keep them up to date afterwards ?>
<script>(function () {
  var spectro = window.innerWidth > 500 && <?php echo json_encode($now_view_cfg); ?> === 'spectrogram';
  var img = document.getElementById('spectrogramimage'), b = document.getElementById('analyzing_toggle');
  if (img) img.style.display = spectro ? '' : 'none';
  if (b) b.parentNode.style.display = spectro ? '' : 'none';
})();</script>
<div id="most_recent_detection" style="display:none"></div>
<?php // the bar over the table (owner 2026-10-21): location, view, then Param. and the filter on the right; the count
// of detections shown is under the table ?>
<h3 class="now-only now-cards nowhead nowsec">
  <?php echo location_picker(); // Loc: the data filter shared by every page (owner 2026-10-22) ?>
  <span class="nowmodes"><button type="button" data-mode="simple" onclick="nowMode('simple')" title="<?php echo t('Time, species, confidence and probability of occurrence at the location'); ?>"><?php echo t('Simple'); ?></button><button type="button" data-mode="list" onclick="nowMode('list')" title="<?php echo t('Every column: date, minimums and analysis settings'); ?>"><?php echo t('Full'); ?></button><button type="button" data-mode="spectrogram" onclick="nowMode('spectrogram')"><?php echo t('Spectrogram'); ?></button>
  <button type="button" class="nowdefault" id="nowview_default" onclick="nowSetDefault()" title="<?php echo t('Open the Latest page with this view, count, period and filter values'); ?>"><?php echo t('Set as default'); ?></button></span>
  <!-- the filter buttons and the search open and close with the Filter icon: closed on a phone, open on a wider screen (owner 2026-10-20);
       the icon is filled while a filter or a search applies -->
  <style>
  .nowsearch.nfclosed .nowfilters > :not(.nowfilterlabel), .nowsearch.nfclosed > input, .nowsearch.nfclosed > select { display: none; }
  .nowhead .nowright { margin-left: auto; display: flex; align-items: center; gap: 6px; font-weight: normal; }
  .nowhead .nowright .nowsearch { margin-left: 0; }
  .nowfilters .nowfilterlabel { cursor: pointer; border-radius: 50%; padding: 3px; }
  .nowfilters .nowfilterlabel.nfon { background: var(--accent,#2b5e22); color: #fff; }
  </style>
  <span class="nowright">
  <button type="button" class="naopen" id="na_open" onclick="naShow(true)" title="<?php echo t('Analysis settings (Min. Conf., Min. Prob., Sigm. Sens., Rec. Length, Overlap)'); ?>"><?php echo t('Param.'); ?></button>
  <span class="nowsearch nfclosed" id="nowsearch"><span class="nowmodes nowfilters" title="<?php echo t('Only the detections worth a look: confidence or probability of occurrence at the location below the value'); ?>">
    <span class="nowfilterlabel" id="nowfiltericon" title="<?php echo t('Filter and search: show / hide'); ?>" role="button" tabindex="0" onclick="nowFilterPanel()" onkeydown="if (event.key === 'Enter' || event.key === ' ') { event.preventDefault(); nowFilterPanel(); }"><svg viewBox="0 0 24 24" width="18" height="18" aria-label="<?php echo t('Filter'); ?>" role="img"><path fill="currentColor" d="M3 4h18l-7 8.5V19l-4 2v-8.5z"/></svg></span><button type="button" data-filter="" onclick="nowFilter('')"><?php echo t('All'); ?></button><button type="button" data-filter="uncommon" onclick="nowFilter('uncommon')" title="<?php echo t('Only species detected at most N times from yesterday 00:00 until now'); ?>"><?php echo t('Uncommon'); ?></button><button type="button" data-filter="rare" onclick="nowFilter('rare')" title="<?php echo t('Only species with no detection in the N days before today (the rare species of the Latest page)'); ?>"><?php echo t('Rare'); ?></button><button type="button" data-filter="lowconf" onclick="nowFilter('lowconf')"><?php echo t('Low Conf'); ?></button><button type="button" data-filter="lowprob" onclick="nowFilter('lowprob')"><?php echo t('Low Prob'); ?></button>
    </span>
    <?php // Uncommon picklist: from the largest species count since yesterday 00:00 down to 1, in round steps
      $most = intval(get_db()->querySingle("SELECT MAX(n) FROM (SELECT COUNT(*) AS n FROM detections WHERE Date >= DATE('now', 'localtime', '-1 day') GROUP BY Sci_Name)"));
      $steps = array();
      foreach (array(1, 2, 3, 5, 10, 20, 30, 50, 100, 200, 300, 500, 1000, 2000, 3000, 5000, 10000, 20000, 50000) as $v) if ($v < $most) $steps[] = $v;
      if ($most > 0) $steps[] = $most;
      rsort($steps); ?>
    <script>var NOW_UNCOMMON_STEPS = <?php echo json_encode($steps ?: array(1)); ?>;</script>
    <select id="nowperiod" class="nowperiod" onchange="nowSetPeriod(this.value)" title="<?php echo t('Period searched by the filters and the search (the list without them is always the latest detections)'); ?>"><option value="today"><?php echo t('Today'); ?></option><option value="7d"><?php echo t('7 days'); ?></option><option value="all"><?php echo t('All'); ?></option></select>
    <input autocomplete="off" size="22" type="search" placeholder="<?php echo htmlspecialchars(t('Search detections...')); ?>" id="searchterm"
    title="<?php echo htmlspecialchars(t('Common or scientific name, time, confidence; start with NOT to leave matches out')); ?>" oninput="nowSearchTyped(this.value)"></span></span></h3>
<?php // the today / all-days cards left Now (owner 2026-10-21): the day's are in Detections › By Hour, the all-days ones in
// By Week; the analysis settings open under the bar from its Param. button (Full and Spectrogram views only) ?>
<div class="now-only nowtoday" id="na_wrap">
  <!-- analysis settings, applied from the next recording (owner 2026-10-09) -->
  <!-- folded to one Param. button (owner 2026-10-10: the box takes too much room on a phone); open / closed is remembered
       per browser: closed by default on a phone, open on a wider screen -->
  <div class="nacontrols"><div class="nablock">
  <form class="nowanalysis" id="na_form" onsubmit="saveAnalysis(event)" title="<?php echo t('Applied from the next recording (about {s} s), no restart', array('s' => intval($config['RECORDING_LENGTH'] ?? 15))); ?>">
    <!-- Default: the BirdSuite defaults (factory_defaults.conf, US-121) with the BirdNET+ V3 model; the V2.4 generation keeps
         its own scale (Loc. Thresh. 0.03), then saved like Apply -->
    <?php $is_v3 = strpos($config['MODEL'] ?? '', 'BirdNET-Plus') === 0;
      $bd = birdsuite_defaults();
      $na_defaults = $is_v3 ? array('na_conf' => (string)($bd['CONFIDENCE'] ?? '0.4'), 'na_sf' => (string)($bd['SF_THRESH'] ?? '0.05'), 'na_sens' => (string)($bd['SENSITIVITY'] ?? '1'),
                                    'na_len' => (string)($bd['RECORDING_LENGTH'] ?? '15'), 'na_over' => (string)($bd['OVERLAP'] ?? '0.5'))
                            : array('na_conf' => '0.7', 'na_sf' => '0.03', 'na_sens' => '1.25', 'na_len' => '15', 'na_over' => '0'); ?>
    <button type="button" class="nadefault" onclick="naDefaults()" data-defaults="<?php echo htmlspecialchars(json_encode($na_defaults), ENT_QUOTES); ?>"
      title="<?php echo t('Back to the defaults ({list}) and save', array('list' => $is_v3 ? t('BirdSuite: Min. Conf. {conf}, Min. Prob. {sf}, Sigm. Sens. {sens}, Rec. Length {len} s, Overlap {over}', array('conf' => fmt_setting($na_defaults['na_conf']), 'sf' => fmt_setting($na_defaults['na_sf']), 'sens' => fmt_setting($na_defaults['na_sens']), 'len' => fmt_setting($na_defaults['na_len']), 'over' => fmt_setting($na_defaults['na_over']))) : t('BirdNET V2.4 model: Min. Conf. 0.7, Min. Prob. 0.03, Sigm. Sens. 1.25, Rec. Length 15 s, Overlap 0'))); ?>"><?php echo t('Default'); ?></button>
    <!-- two groups (owner 2026-10-09): the thresholds, then the analysis settings -->
    <span class="nagroup" title="<?php echo t('Minimums'); ?>">
      <label title="<?php echo t('Min. Conf.: the minimum confidence, the lowest score a detection needs to be kept (CONFIDENCE).&#10;Default BirdSuite (V3 model): {def}&#10;Default BirdNET V2.4: 0.70&#10;Range: 0.01 to 0.99&#10;Higher: fewer detections, more reliable; quiet or distant birds are missed.&#10;Lower: more detections, more of them wrong (the manual confidence of the species replaces it for that species).', array('def' => htmlspecialchars(fmt_setting($bd['CONFIDENCE'] ?? '')))); ?>"><?php echo t('Min. Conf.'); ?> <input type="number" id="na_conf" min="0.01" max="0.99" step="any" value="<?php echo htmlspecialchars($config['CONFIDENCE'] ?? ''); ?>"></label>
      <label title="<?php echo t('Min. Prob.: the minimum probability of occurrence at the location; species below it this week are left out (SF_THRESH).&#10;Default BirdSuite (V3 model): {def}&#10;Default BirdNET V2.4: 0.03&#10;Range: 0.0005 to 0.99&#10;Higher: only the species expected here (the Whitelist bypasses it).&#10;Lower: more species allowed, rare and vagrant ones too, with more errors.', array('def' => htmlspecialchars(fmt_setting($bd['SF_THRESH'] ?? '')))); ?>"><?php echo t('Min. Prob.'); ?> <input type="number" id="na_sf" min="0.0005" max="0.99" step="any" value="<?php echo htmlspecialchars($config['SF_THRESH'] ?? ''); ?>"></label>
    </span>
    <span class="nagroup" title="<?php echo t('Analysis'); ?>">
      <label title="<?php echo t('Sigm. Sens.: bends the model&#x27;s scores before the minimum confidence is applied (SENSITIVITY).&#10;Default BirdSuite (V3 model): {def}&#10;Default BirdNET V2.4: 1.25&#10;Range: 0.5 to 1.5&#10;Higher: scores rise, so more detections and more false ones.&#10;Lower: scores fall, so fewer detections.', array('def' => htmlspecialchars(fmt_setting($bd['SENSITIVITY'] ?? '')))); ?>"><?php echo t('Sigm. Sens.'); ?> <input type="number" id="na_sens" min="0.5" max="1.5" step="any" value="<?php echo htmlspecialchars($config['SENSITIVITY'] ?? ''); ?>"></label>
      <label title="<?php echo t('Rec. Length: seconds of each recorded and analysed file (RECORDING_LENGTH); a change restarts the recording service.&#10;Default BirdSuite (V3 model): {def} s&#10;Default BirdNET V2.4: 15 s&#10;Range: 3 to 60 s&#10;Higher: fewer files; a detection shows up later.&#10;Lower: detections appear sooner; more files, a little more work per minute.', array('def' => htmlspecialchars(fmt_setting($bd['RECORDING_LENGTH'] ?? '15')))); ?>"><?php echo t('Rec. Length'); ?> <input type="number" id="na_len" min="3" max="60" step="any" value="<?php echo htmlspecialchars($config['RECORDING_LENGTH'] ?? '15'); ?>"></label>
      <label title="<?php echo t('Overlap: seconds the 3 s analysis windows overlap (OVERLAP).&#10;Default BirdSuite (V3 model): {def} s&#10;Default BirdNET V2.4: 0 s&#10;Range: 0 to 2.9 s&#10;Higher: calls on a window edge are caught; more CPU and repeated detections of one call.&#10;Lower: fewer windows; 0 = side by side, the fastest.', array('def' => htmlspecialchars(fmt_setting($bd['OVERLAP'] ?? '')))); ?>"><?php echo t('Overlap'); ?> <input type="number" id="na_over" min="0" max="2.9" step="any" value="<?php echo htmlspecialchars($config['OVERLAP'] ?? ''); ?>"></label>
    </span>
    <span class="nabuttons"><button type="button" class="naclose" onclick="naShow(false)" title="<?php echo t('Fold the settings away'); ?>"><?php echo t('Close'); ?></button><button type="submit" id="na_apply"><?php echo t('Apply'); ?></button></span>
  </form>
  </div>
  </div>
</div>
<?php // the analysis settings open or closed before the first paint, as naShow / naApply do at DOMContentLoaded (2026-10-23
// test run: the box folding away then moved the list) ?>
<script>(function () {
  var simple = window.innerWidth <= 500 || <?php echo json_encode($now_view_cfg); ?> === 'simple';
  var s = null; try { s = localStorage.getItem('now_params'); } catch (e) {}
  var open = s ? s === 'open' : <?php echo json_encode(array(($config['NOW_PARAMS_PHONE'] ?? 'closed') === 'open', ($config['NOW_PARAMS_DESKTOP'] ?? 'open') !== 'closed')); ?>[window.innerWidth > 800 ? 1 : 0];  // NOW_PARAMS_* (owner 2026-10-25)
  var f = document.getElementById('na_form'), b = document.getElementById('na_open'), w = document.getElementById('na_wrap');
  if (f) f.style.display = open ? '' : 'none';
  if (b) b.style.display = (simple || open) ? 'none' : '';
  if (w) w.style.display = simple ? 'none' : '';
})();</script>
<div style="padding-bottom:10px;" id="detections_table"><h3><?php echo t('Loading...'); ?></h3></div>
<?php // the location line under the table left Now (owner 2026-10-21): the header and the picklist name it ?>

<div id="customimage"></div>
<br>

</div>
</div>
</div>
<script>
// we're passing a unique ID of the currently displayed detection to our script, which checks the database to see if the newest detection entry is that ID, or not. If the IDs don't match, it must mean we have a new detection and it's loaded onto the page
function loadDetectionIfNewExists(previous_detection_identifier=undefined) {
  const xhttp = new XMLHttpRequest();
  xhttp.onload = function() {
    // if there's a new detection that needs to be updated to the page
    if(this.responseText.length > 0 && !this.responseText.includes("Database is busy") && !this.responseText.includes("No Detections")) {
      document.getElementById("most_recent_detection").innerHTML = this.responseText;

      // only going to load left chart & the recent cards if there's a new detection (cards: newest page only)
      if (!document.querySelector('.overview-stats.part-now')) loadLeftChart(); else loadCenterChart(); // the totals column is hidden on Now
      if (nowOffset === 0 || previous_detection_identifier == undefined) loadFiveMostRecentDetections();
      refreshTodayStats();
      refreshTopTen();

      // Now that new HTML is inserted, re-run player init:
      initCustomAudioPlayers();
    }
  }
  xhttp.open("GET", "overview.php?ajax_detections=true&previous_detection_identifier="+previous_detection_identifier, true);
  xhttp.send();
}
function loadLeftChart() {
  const xhttp = new XMLHttpRequest();
  xhttp.onload = function() {
    if(this.responseText.length > 0 && !this.responseText.includes("Database is busy")) {
      document.getElementsByClassName("left-column")[0].innerHTML = this.responseText;
      loadCenterChart();
    }
  }
  xhttp.open("GET", "overview.php?ajax_left_chart=true", true);
  xhttp.send();
}
function loadCenterChart() {
  const xhttp = new XMLHttpRequest();
  xhttp.onload = function() {
    if(this.responseText.length > 0 && !this.responseText.includes("Database is busy")) {
      document.getElementsByClassName("center-column")[0].innerHTML = this.responseText;
    }
  }
  xhttp.open("GET", "overview.php?ajax_center_chart=true", true);
  xhttp.send();
}
function refreshTopTen() {
  const xhttp = new XMLHttpRequest();
  xhttp.onload = function() {
  if(this.responseText.length > 0 && !this.responseText.includes("Database is busy") && !this.responseText.includes("No Detections")) {
    if (document.getElementById("chart")) {document.getElementById("chart").src = "Charts/"+this.responseText+"?nocache="+Date.now();}
  }
  }
  xhttp.open("GET", "overview.php?fetch_chart_string=true", true);
  xhttp.send();
}
function refreshDetection() {
  if (!document.hidden) {
    const audioPlayers = document.querySelectorAll(".custom-audio-player");
    // If no custom-audio-player elements are found, refresh
    if (audioPlayers.length === 0) {
      loadDetectionIfNewExists();
      return;
    }
    // Check if any custom audio player is currently playing
    let isPlaying = false;
    audioPlayers.forEach((player) => {
      const audioEl = player.querySelector("audio");
      if (audioEl && audioEl.currentTime > 0 && !audioEl.paused && !audioEl.ended && audioEl.readyState > 2) {
        isPlaying = true;
      }
    });
    // nor an audio of the Now list
    document.querySelectorAll('#detections_table audio').forEach(function (a) { if (!a.paused && !a.ended) isPlaying = true; });
    // If none are playing, refresh detections
    if (!isPlaying) {
      const currentIdentifier = audioPlayers[0]?.dataset.audioSrc || undefined;
      loadDetectionIfNewExists(currentIdentifier);
    }
  }
}
// The live spectrogram belongs to the Spectrogram view (owner 2026-10-21): there it is on by default and its button
// on the first line hides it; Simple and Full show neither the spectrogram nor the button
var analyzingOn = true;
function applyAnalyzing() {
  var spectro = typeof nowView !== 'undefined' && nowView === 'spectrogram';
  var img = document.getElementById('spectrogramimage'), b = document.getElementById('analyzing_toggle');
  if (img) img.style.display = (spectro && analyzingOn) ? '' : 'none';
  if (b) { b.parentNode.style.display = spectro ? '' : 'none'; b.classList.toggle('active', analyzingOn); }
}
function toggleAnalyzing() {
  analyzingOn = !analyzingOn;
  applyAnalyzing();
  if (analyzingOn) document.getElementById('spectrogramimage').src = 'spectrogram.png?nocache=' + Date.now();
}
document.addEventListener('DOMContentLoaded', applyAnalyzing);
function refreshAnalysisStatus() {
  var x = new XMLHttpRequest();
  x.onload = function () {
    var st; try { st = JSON.parse(this.responseText); } catch (e) { return; }
    var el = document.getElementById('analysis_status');
    if (!el) return;
    var behind = st.behind >= 60 ? Math.round(st.behind / 60) + 'min' : (st.behind || 0) + 's';
    // "12:48:30, Waiting: 2, Delay: 16s" (owner 2026-10-21): the time of the recording shown first
    el.textContent = (st.shown ? st.shown + ', ' : '') + (!st.running ? t('analysis stopped') + ', ' : '')
      + t('Waiting: {n}', {n: st.waiting}) + ', ' + t('Delay: {time}', {time: st.waiting === 0 ? '0s' : behind});
    el.classList.toggle('late', !st.running || st.waiting > 10);
  };
  x.open('GET', 'overview.php?analysis_status=1', true);
  x.send();
}
document.addEventListener('DOMContentLoaded', function () { refreshAnalysisStatus(); setInterval(refreshAnalysisStatus, 5000); });
// the Now cards page through the detections 50 at a time; only the newest page follows new detections
var nowOffset = 0;
var nowTerm = '';
// any value is accepted while typing; leaving a field rounds it to its precision and keeps it inside its limits
// (owner 2026-10-09: the browser refused 0.5 for Loc. Thresh., whose min 0.0005 + step 0.01 allowed only 0.0105, 0.0205...)
// a field whose value is not the model's default turns light yellow (owner 2026-10-10)
function naMarkDefaults() {
  var b = document.querySelector('.nadefault');
  if (!b) return;
  var d = JSON.parse(b.dataset.defaults);
  Object.keys(d).forEach(function (id) {
    var inp = document.getElementById(id);
    if (inp) inp.classList.toggle('nondefault', parseFloat(inp.value) !== parseFloat(d[id]));
  });
}
document.addEventListener('DOMContentLoaded', function () {
  naMarkDefaults();
  ['na_conf', 'na_sf', 'na_sens', 'na_len', 'na_over'].forEach(function (id) { var inp = document.getElementById(id); if (inp) inp.addEventListener('input', naMarkDefaults); });
});
function naDefaults() {
  var d = JSON.parse(document.querySelector('.nadefault').dataset.defaults);
  Object.keys(d).forEach(function (id) { var inp = document.getElementById(id); if (inp) inp.value = d[id]; });
  naMarkDefaults();
  document.getElementById('na_form').requestSubmit();
}
var naOpen = false;
function naShow(open) {
  naOpen = open;
  try { localStorage.setItem('now_params', open ? 'open' : 'closed'); } catch (e) {}
  naApply();
}
// the analysis settings belong to the Full and Spectrogram views (owner 2026-10-21): hidden in Simple
function naApply() {
  var simple = typeof nowView !== 'undefined' && nowView === 'simple';
  var f = document.getElementById('na_form'), b = document.getElementById('na_open'), w = document.getElementById('na_wrap');
  if (f) f.style.display = naOpen ? '' : 'none';
  if (b) b.style.display = (simple || naOpen) ? 'none' : '';
  if (w) w.style.display = simple ? 'none' : '';
}
document.addEventListener('DOMContentLoaded', function () {
  var saved = null;
  try { saved = localStorage.getItem('now_params'); } catch (e) {}
  naShow(saved ? saved === 'open' : NOW_OPEN_DEFAULTS.params[window.innerWidth > 800 ? 1 : 0]);
});
var NA_DECIMALS = {na_conf: 2, na_sf: 4, na_sens: 2, na_over: 1, na_len: 0};
function naRound(inp) {
  var v = parseFloat(String(inp.value).replace(',', '.'));
  if (isNaN(v)) return;
  v = Math.min(parseFloat(inp.max), Math.max(parseFloat(inp.min), v));
  inp.value = String(parseFloat(v.toFixed(NA_DECIMALS[inp.id])));
}
document.addEventListener('DOMContentLoaded', function () {
  Object.keys(NA_DECIMALS).forEach(function (id) {
    var inp = document.getElementById(id);
    if (inp) inp.addEventListener('change', function () { naRound(inp); naMarkDefaults(); });
  });
});
function saveAnalysis(e) {
  Object.keys(NA_DECIMALS).forEach(function (id) { var inp = document.getElementById(id); if (inp) naRound(inp); });
  e.preventDefault();
  // no message beside the button (owner 2026-10-09: it pushed the line); the button itself says Saved for a moment,
  // an error opens a dialog
  var b = document.getElementById('na_apply');
  var x = new XMLHttpRequest();
  x.onload = function () {
    if (this.status === 200 && this.responseText.trim() === 'OK') {
      b.textContent = '\u2713';
      b.title = t('Saved: applied from the next recording');
      setTimeout(function () { b.textContent = t('Apply'); }, 1500);
    } else alert(t('Not saved: {reason}', {reason: this.status === 401 ? t('log in first') : this.responseText}));
  };
  x.open('GET', 'overview.php?set_analysis=1&confidence=' + encodeURIComponent(document.getElementById('na_conf').value)
    + '&sensitivity=' + encodeURIComponent(document.getElementById('na_sens').value) + '&overlap=' + encodeURIComponent(document.getElementById('na_over').value)
    + '&sf_thresh=' + encodeURIComponent(document.getElementById('na_sf').value) + '&recording_length=' + encodeURIComponent(document.getElementById('na_len').value), true);
  x.send();
}
// filters (owner 2026-10-09/10): Uncommon (species detected at most N times since yesterday 00:00), Low Conf (confidence
// below P %), Low Prob (location probability below P %) — each button switches its filter on/off, several apply together,
// each active one keeps its picklist open beside its button; All switches them all off. Remembered in this browser.
var nowFilters = {};
function nowStore(k, v) { try { if (v === undefined) return localStorage.getItem(k); localStorage.setItem(k, v); } catch (e) {} return null; }
// the values a filter starts with (NOW_LOWPROB / NOW_LOWCONF / NOW_UNCOMMON of factory_defaults.conf, US-121)
var NOW_FILTER_DEFAULTS = <?php echo json_encode(array('lowprob' => intval($config['NOW_LOWPROB'] ?? 40), 'lowconf' => intval($config['NOW_LOWCONF'] ?? 60), 'uncommon' => intval($config['NOW_UNCOMMON'] ?? 10), 'rare' => max(1, intval($config['RARE_SPECIES_THRESHOLD'] ?? 30)))); ?>;
// the days of the Rare filter, with the station's own value among them
var NOW_RARE_STEPS = [7, 14, 30, 60, 90, 180, 365].concat([NOW_FILTER_DEFAULTS.rare]).filter(function (v, i, a) { return a.indexOf(v) === i; }).sort(function (a, b) { return a - b; });
function nowDefaultValue(kind) {
  if (kind === 'rare') return String(NOW_FILTER_DEFAULTS.rare);
  if (kind === 'uncommon') return String(NOW_UNCOMMON_STEPS.filter(function (x) { return x <= NOW_FILTER_DEFAULTS.uncommon; })[0] || NOW_UNCOMMON_STEPS[NOW_UNCOMMON_STEPS.length - 1]);
  return String(Math.round(NOW_FILTER_DEFAULTS[kind] / 5) * 5 || 50);
}
function nowFilterOptions(kind) {
  if (kind === 'rare') return NOW_RARE_STEPS.map(function (v) { return [v, '\u2265 ' + t('{n} days without', {n: v})]; });
  return kind === 'uncommon'
    ? NOW_UNCOMMON_STEPS.map(function (v) { return [v, '\u2264 ' + t('{n} since yesterday', {n: v})]; })
    : Array.apply(null, Array(19)).map(function (_, i) { var v = (i + 1) * 5; return [v, '< ' + v + '%']; });
}
function nowFilterButtons() {
  var any = Object.keys(nowFilters).length > 0;
  document.querySelectorAll('.nowfilters button[data-filter]').forEach(function (b) {
    var k = b.dataset.filter;
    b.classList.toggle('active', k === '' ? !any : nowFilters[k] !== undefined);
    if (k === '') return;
    var sel = document.getElementById('nowbelow_' + k);
    if (nowFilters[k] === undefined) { if (sel) sel.remove(); return; }
    if (!sel) {
      sel = document.createElement('select');
      sel.id = 'nowbelow_' + k;
      sel.className = 'nowbelow';
      sel.innerHTML = nowFilterOptions(k).map(function (o) { return '<option value="' + o[0] + '">' + o[1] + '</option>'; }).join('');
      // the chosen value is kept as this filter's default: switched off and on again, it comes back with it
      sel.onchange = function () { nowFilters[k] = sel.value; nowStore('now_below_' + k, sel.value); nowSaveFilters(); if (typeof nowDefaultMark === 'function') nowDefaultMark(); nowOffset = 0; loadFiveMostRecentDetections(); };
      b.after(sel);
    }
    if (![].some.call(sel.options, function (o) { return o.value === String(nowFilters[k]); })) nowFilters[k] = nowDefaultValue(k);
    sel.value = nowFilters[k];
  });
  nowFilterMark();
}
// closing the panel switches the filters and the search off (like All); opening it again brings back the last ones
// (owner 2026-10-20) — kept in nowParked meanwhile, and in the browser for the next visit
var nowParked = null;
function nowFilterPanel() {
  var closed = document.getElementById('nowsearch').classList.toggle('nfclosed');
  if (closed) {
    nowParked = {filters: nowFilters, term: nowTerm};
    nowFilters = {};
    nowTerm = '';
  } else if (nowParked) {
    nowFilters = nowParked.filters;
    nowTerm = document.getElementById('searchterm').value.trim();
    nowParked = null;
  }
  nowFilterButtons();
  nowOffset = 0;
  loadFiveMostRecentDetections();
}
// the icon shows that something filters the list, also while the buttons are hidden
// the panels open or closed by default, phone / wider screen (owner 2026-10-25: Settings › Defaults, Latest page)
var NOW_OPEN_DEFAULTS = <?php echo json_encode(array('filters' => array(($config['NOW_FILTERS_PHONE'] ?? 'closed') === 'open', ($config['NOW_FILTERS_DESKTOP'] ?? 'open') !== 'closed'),
  'params' => array(($config['NOW_PARAMS_PHONE'] ?? 'closed') === 'open', ($config['NOW_PARAMS_DESKTOP'] ?? 'open') !== 'closed'))); ?>;
function nowFilterMark() {
  var i = document.getElementById('nowfiltericon');
  if (i) i.classList.toggle('nfon', Object.keys(nowFilters).length > 0 || nowTerm !== '');
}
function nowSaveFilters() { if (!nowParked) nowStore('now_filters', JSON.stringify(nowFilters)); }
function nowFilter(kind) {
  if (kind === '') nowFilters = {};
  else if (nowFilters[kind] !== undefined) delete nowFilters[kind];
  else nowFilters[kind] = nowStore('now_below_' + kind) || nowDefaultValue(kind);
  nowFilterButtons();
  Object.keys(nowFilters).forEach(function (k) { nowStore('now_below_' + k, nowFilters[k]); });
  nowSaveFilters();
  nowOffset = 0;
  loadFiveMostRecentDetections();
}
document.addEventListener('DOMContentLoaded', function () {
  try { nowFilters = JSON.parse(nowStore('now_filters') || '{}') || {}; } catch (e) { nowFilters = {}; }
  Object.keys(nowFilters).forEach(function (k) { if (['uncommon', 'rare', 'lowconf', 'lowprob'].indexOf(k) < 0) delete nowFilters[k]; });
  // the page always opens with the filter off (owner 2026-10-21): the saved filters wait for the Filter icon
  nowParked = {filters: nowFilters, term: ''}; nowFilters = {};
  nowFilterButtons();
  // open by default when the station says so for this kind of screen (NOW_FILTERS_PHONE / NOW_FILTERS_DESKTOP)
  if (NOW_OPEN_DEFAULTS.filters[window.innerWidth > 500 ? 1 : 0]) { nowFilterPanel(); return; }
  if (Object.keys(nowFilters).length) loadFiveMostRecentDetections();
});
// period of the filters and the search (owner 2026-10-20): Today by default — a filter that matches few detections
// otherwise walks the whole history; remembered per browser
var nowPeriod = (function () { var v = nowStore('now_period'); return ['today', '7d', 'all'].indexOf(v) >= 0 ? v : <?php echo json_encode(in_array($config['NOW_PERIOD'] ?? 'today', array('today', '7d', 'all'), true) ? $config['NOW_PERIOD'] : 'today'); ?>; })();
document.addEventListener('DOMContentLoaded', function () { var p = document.getElementById('nowperiod'); if (p) p.value = nowPeriod; });
function nowSetPeriod(v) { nowPeriod = v; nowStore('now_period', v); if (typeof nowDefaultMark === 'function') nowDefaultMark(); nowOffset = 0; if (Object.keys(nowFilters).length || nowTerm) loadFiveMostRecentDetections(); }
function nowFilterQuery() { return Object.keys(nowFilters).map(function (k) { return '&' + k + '=' + encodeURIComponent(nowFilters[k]); }).join('') + '&period=' + nowPeriod; }
// every page load starts sorted by time, newest first (owner 2026-10-20); a column click sorts until the next reload
try { Object.keys(localStorage).forEach(function (k) { if (/^stdsort:.*:detections_table$/.test(k)) localStorage.removeItem(k); }); } catch (e) {}
function nowSearch(v) { nowTerm = v.trim(); nowOffset = 0; nowFilterMark(); loadFiveMostRecentDetections(); }
// search as you type (owner 2026-10-09): 300 ms after the last key; an empty box shows every detection again
var nowSearchTimer = null;
function nowSearchTyped(v) {
  clearTimeout(nowSearchTimer);
  nowSearchTimer = setTimeout(function () { if (v.trim() !== nowTerm) nowSearch(v); }, v.trim() === '' ? 0 : 300);
}
function refreshTodayStats() {
  if (!document.getElementById('todaystats')) return; // no cards on Now any more (owner 2026-10-21)
  var x = new XMLHttpRequest();
  x.onload = function () { if (this.responseText.length > 0 && !this.responseText.includes('Database is busy')) document.getElementById('todaystats').innerHTML = this.responseText; };
  x.open('GET', 'todays_detections.php?today_stats=true', true);
  x.send();
}
document.addEventListener('DOMContentLoaded', refreshTodayStats);
// spectrogram cards or list (owner 2026-10-09): NOW_VIEW of Basic Settings is the default (list when unset, owner
// 2026-10-10), the buttons switch it
var nowView = <?php echo json_encode(in_array($config['NOW_VIEW'] ?? 'list', array('simple', 'spectrogram'), true) ? $config['NOW_VIEW'] : 'list'); ?>;
// a phone opens in Simple with the filters closed; a wider screen opens in the default view (Full unless set) with the
// filters open (owner 2026-10-20); the buttons and the Filter icon still switch
var nowPhone = window.innerWidth <= 500;
var nowDefault = nowView; // the saved default, before the phone override, so "Set as default" compares with it
if (nowPhone) nowView = 'simple';
function nowModeButtons() {
  document.querySelectorAll('.nowmodes button[data-mode]').forEach(function (b) { b.classList.toggle('active', b.dataset.mode === nowView); });
  var d = document.getElementById('nowview_default');
  if (d) nowDefaultMark();
  if (typeof naApply === 'function') naApply();
}
// how many detections the list shows (owner 2026-10-20): 10 / 25 / 50 / 100, default 50, remembered per browser
var NOW_COUNT_DEFAULT = <?php echo json_encode(in_array(intval($config['NOW_COUNT'] ?? 25), array(10, 25, 50, 100), true) ? intval($config['NOW_COUNT'] ?? 25) : 25); ?>;
var nowCount = (function () { var v = parseInt(nowStore('now_count') || String(NOW_COUNT_DEFAULT), 10); return [10, 25, 50, 100].indexOf(v) >= 0 ? v : NOW_COUNT_DEFAULT; })();
document.addEventListener('DOMContentLoaded', function () { var c = document.getElementById('nowcount'); if (c) c.value = String(nowCount); });
function nowSetCount(v) {
  nowCount = parseInt(v, 10) || NOW_COUNT_DEFAULT;
  nowStore('now_count', String(nowCount));
  nowDefaultMark();
  nowOffset = 0;
  // a refresh at once: the cards on top and the list with the new size
  document.getElementById('detections_table').style.opacity = '.5';
  refreshTodayStats();
  loadFiveMostRecentDetections();
}
function nowMode(mode) {
  if (mode === 'spectrogram' && nowView !== 'spectrogram') analyzingOn = true; // on each time the view is entered
  nowView = mode; nowModeButtons(); applyAnalyzing(); loadFiveMostRecentDetections();
}
// the station defaults the button compares with (owner 2026-10-23: view, count, period and the filter values together)
var nowSaved = {view: nowDefault, count: String(NOW_COUNT_DEFAULT), period: <?php echo json_encode(in_array($config['NOW_PERIOD'] ?? 'today', array('today', '7d', 'all'), true) ? $config['NOW_PERIOD'] : 'today'); ?>,
                lowprob: nowDefaultValue('lowprob'), lowconf: nowDefaultValue('lowconf'), uncommon: nowDefaultValue('uncommon'), rare: nowDefaultValue('rare')};
function nowCurrent() {
  var cur = {view: nowView, count: String(nowCount), period: nowPeriod};
  ['lowprob', 'lowconf', 'uncommon', 'rare'].forEach(function (k) {
    cur[k] = String(nowFilters[k] !== undefined ? nowFilters[k] : (nowStore('now_below_' + k) || nowSaved[k]));
  });
  return cur;
}
function nowDefaultMark() {
  var d = document.getElementById('nowview_default');
  if (!d) return;
  var cur = nowCurrent();
  // a phone opens in Simple by itself: that alone is no reason to offer the button
  var differs = Object.keys(nowSaved).some(function (k) { return k !== 'view' && cur[k] !== nowSaved[k]; })
    || (cur.view !== nowSaved.view && !nowPhone);
  d.style.display = differs ? '' : 'none'; // hidden takes no room, so the bar fits a phone line
}
function nowSetDefault() {
  var cur = nowCurrent();
  var x = new XMLHttpRequest();
  x.onload = function () {
    if (this.status === 200 && this.responseText.trim() === 'OK') { nowSaved = cur; nowDefault = cur.view; nowDefaultMark(); }
    else alert(t('Not saved: {reason}', {reason: this.status === 401 ? t('log in first') : this.responseText}));
  };
  x.open('GET', 'overview.php?set_now_view=' + cur.view + '&count=' + cur.count + '&period=' + cur.period
    + '&lowprob=' + cur.lowprob + '&lowconf=' + cur.lowconf + '&uncommon=' + cur.uncommon + '&rare=' + cur.rare, true);
  x.send();
}
document.addEventListener('DOMContentLoaded', nowModeButtons);
function nowPage(offset) {
  nowOffset = offset;
  loadFiveMostRecentDetections();
  var h = document.querySelector('h3.now-cards');
  if (h) h.scrollIntoView({behavior: 'smooth'});
}
// only the answer to the latest request is shown (owner 2026-10-10): a filtered request is slower, and switching a filter
// off quickly let its late answer overwrite the unfiltered list, so the filter looked still on
// (2026-10-20) only an answer to ANOTHER query (filter, search, page, view) is dropped: with a detection every few seconds
// each one asks again, and dropping every older answer to the same query left the table on the old list while busy
var nowListQuery = '';
function loadFiveMostRecentDetections() {
  const xhttp = new XMLHttpRequest();
  const mine = nowView + '|' + nowCount + '|' + nowOffset + '|' + nowTerm + '|' + nowFilterQuery() + '|' + (window.innerWidth > 500);
  nowListQuery = mine;
  xhttp.onload = function() {
    if (mine !== nowListQuery) return;
    if(this.responseText.length > 0 && !this.responseText.includes("Database is busy")) {
      document.getElementById("detections_table").innerHTML= this.responseText;
      document.getElementById("detections_table").style.opacity = '';
      if (window.stdTables) stdTables(document.getElementById("detections_table"));
      if (window.applyNameMode) applyNameMode();
    }
  }
  if (window.innerWidth > 500) {
    xhttp.open("GET", "todays_detections.php?ajax_detections=true&display_limit=undefined&hard_limit=" + nowCount + "&gallery=1&mode=" + nowView + "&offset=" + nowOffset + (nowTerm ? "&searchterm=" + encodeURIComponent(nowTerm) : "") + nowFilterQuery(), true);
  } else {
    xhttp.open("GET", "todays_detections.php?ajax_detections=true&display_limit=undefined&hard_limit=" + nowCount + "&gallery=1&mobile=true&mode=" + nowView + "&offset=" + nowOffset + (nowTerm ? "&searchterm=" + encodeURIComponent(nowTerm) : "") + nowFilterQuery(), true);
  }
  xhttp.send();
}
function refreshCustomImage(){
  // Find the customimage element
  var customimage = document.getElementById("customimage");

  function updateCustomImage() {
    var xhr = new XMLHttpRequest();
    xhr.open("GET", "overview.php?custom_image=true", true);
    xhr.onload = function() {
      customimage.innerHTML = xhr.responseText;
    }
    xhr.send();
  }
  updateCustomImage();
}
function startAutoRefresh() {
    i_fn1 = window.setInterval(function(){
                    // a hidden spectrogram is not downloaded again
                    if (document.getElementById("spectrogramimage").style.display === "none") return;
                    document.getElementById("spectrogramimage").src = "spectrogram.png?nocache="+Date.now();
                    }, <?php echo $refresh; ?>*1000);
    i_fn2 = window.setInterval(refreshDetection, <?php echo intval($dividedrefresh); ?>*1000);
    if (customImage) i_fn3 = window.setInterval(refreshCustomImage, 1000);
}
<?php if(isset($config["CUSTOM_IMAGE"]) && strlen($config["CUSTOM_IMAGE"]) > 2){?>
customImage = true;
<?php } else { ?>
customImage = false;
<?php } ?>
window.addEventListener("load", function(){
  loadDetectionIfNewExists();
});
document.addEventListener("visibilitychange", function() {
  console.log(document.visibilityState);
  console.log(document.hidden);
  if (document.hidden) {
    clearInterval(i_fn1);
    clearInterval(i_fn2);
    if (customImage) clearInterval(i_fn3);
  } else {
    loadDetectionIfNewExists();
    startAutoRefresh();
  }
});
startAutoRefresh();
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
<script src="static/spectro-dialog.js?v=<?php echo @filemtime(__DIR__ . "/../homepage/static/spectro-dialog.js"); ?>"></script>
<script src="static/review-player.js?v=<?php echo @filemtime(__DIR__ . "/../homepage/static/review-player.js"); ?>"></script>
<script src="static/std-table.js?v=<?php echo @filemtime(__DIR__ . "/../homepage/static/std-table.js"); ?>"></script>
<script src="static/name-mode.js?v=<?php echo @filemtime(__DIR__ . "/../homepage/static/name-mode.js"); ?>"></script>
<script src="static/detection-actions.js?v=<?php echo @filemtime(__DIR__ . "/../homepage/static/detection-actions.js"); ?>"></script>
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
