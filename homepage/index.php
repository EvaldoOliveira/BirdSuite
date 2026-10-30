<?php

$requestUri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

// Captive setup page (US-120, owner 2026-10-20): while the station's own WiFi is on, its DNS answers every name with
// the station (10.42.N.1, its own address: field_net.py ap_addr); a request for another name (the phone's connectivity check after joining) is sent to the station, so the
// phone opens it by itself — the setup on a new station, the home page otherwise
if (is_file('/run/birdsuite/ap_since')) {
  $host = strtolower(preg_replace('/:\d+$/', '', $_SERVER['HTTP_HOST'] ?? ''));
  $fs = json_decode((string)@file_get_contents('/run/birdsuite/field_state.json'), true);
  $ap = is_array($fs) && preg_match('/^10\.42\.\d{1,3}\.1$/', (string)($fs['ap_addr'] ?? '')) ? $fs['ap_addr'] : '10.42.0.1';
  $own = array($ap, 'localhost', '127.0.0.1', strtolower(gethostname()), strtolower(gethostname()) . '.local');
  if ($host !== '' && !in_array($host, $own, true) && !preg_match('/^birdsuite-[0-9a-f]{6,12}\.local$/', $host)
      && !filter_var($host, FILTER_VALIDATE_IP)) {
    header('Location: http://' . $ap . '/' . (glob('/home/*/BirdNET-Pi/state/firstrun_pending') ? '?view=Setup' : ''), true, 302);
    die();
  }
}

if (strpos($requestUri, '/api/v1/') === 0) {
  include_once 'scripts/api.php';
  die();
}

/* Prevent XSS input */
require_once 'scripts/sanitize_input.php';
$_GET   = bs_sanitize_input(INPUT_GET);
$_POST  = bs_sanitize_input(INPUT_POST);
require_once 'scripts/common.php';
$config = get_config();
$site_name = get_sitename();
$color_scheme = get_color_scheme();
set_timezone();
?>
<!DOCTYPE html>
<html lang="<?php echo str_replace('_', '-', ui_lang()); ?>">
<title><?php echo $site_name; ?></title>
<?php echo ui_js(); ?>
<script>
// the station page opened inside its own page frame (a link or a form that went to "/", owner 2026-10-21: the header
// showed twice): load only the page part there
try { if (window.top !== window && window.top.location.host === location.host) location.replace('views.php' + location.search); } catch (e) {}
</script>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link id="iconLink" rel="icon" type="image/x-icon" href="images/BirdNetBr_full.ico" />
<link rel="stylesheet" href="<?php echo $color_scheme . '?v=' . filemtime($color_scheme); ?>"><?php echo theme_style(); ?>
<link rel="stylesheet" type="text/css" href="static/dialog-polyfill.css" />
<body>
<div class="banner">
  <?php // the menu button of phones sits in the header (owner 2026-10-21): it opens the side menu of the page below ?>
  <button type="button" class="topmenu" title="<?php echo htmlspecialchars(t('Menu')); ?>"
    onclick="var f = document.querySelector('iframe'); try { f.contentWindow.document.body.classList.toggle('sidenav-open'); } catch (e) {}">&#9776;</button>
  <div class="logo">
<?php if(isset($_GET['logo'])) {
// Station logo (owner 2026-09-22): BirdNetBr.png, the edition's own mark; it links to the station home
// (owner 2026-10-18: no links to the repository server in the UI).
// The BirdSuite wordmark is centred (owner 2026-10-20: the product is BirdSuite; a text wordmark until the new logo).
echo "<a href=\"/\" title=\"" . htmlspecialchars(t('Station home'), ENT_QUOTES) . "\"><img style=\"width:60;height:60;\" src=\"images/BirdNetBr.png\" alt=\"" . htmlspecialchars(t('Station home'), ENT_QUOTES) . "\"></a>";
} else {
echo "<a href=\"/\" title=\"" . htmlspecialchars(t('Station home'), ENT_QUOTES) . "\"><img src=\"images/BirdNetBr.png\" alt=\"" . htmlspecialchars(t('Station home'), ENT_QUOTES) . "\"></a>";
}?>
  </div>
  <?php // the station name opens the station list (US-117, owner 2026-10-20): name and address, a click opens the same page on another station ?>
  <?php // simple mode (US-125): the station name without the station list, no location beside it ?>
  <div class="sitename stnwrap"><?php if (multi_station()) { ?><button type="button" class="stnbtn-top" id="station_btn" title="<?php echo htmlspecialchars(t('Switch station')); ?>"><span class="hdtext"><?php echo $site_name; ?></span></button><?php } else { ?><span class="stnbtn-top"><span class="hdtext"><?php echo $site_name; ?></span></span><?php } ?><?php
    // the current location beside the station: "Station - Location" (owner 2026-10-20); like the station name, a click
    // opens the list of locations and a click on one changes the current location (owner 2026-10-21, static/location-switch.js)
    $cur_loc = location_current(); if (multi_location()) { ?><span class="curloc"> - <button type="button" class="stnbtn-top" id="loc_btn" title="<?php echo htmlspecialchars(($cur_loc ? $cur_loc['name'] . ': ' : '') . t('Change the current location')); ?>"><span class="hdtext"><?php echo htmlspecialchars($cur_loc ? $cur_loc['name'] : t('location not registered')); ?></span></button></span><?php } ?>
    <div class="stnpop" id="loc_pop"></div>
    <div class="stnpop" id="station_pop"></div></div>
  <style>
  .banner .stnwrap { position: relative; }
  .banner .stnbtn-top { all: unset; cursor: pointer; font: inherit; letter-spacing: inherit; }
  .banner .stnbtn-top.many::after { content: " \25BE"; font-size: .8em; opacity: .7; }
  .banner .stnpop { display: none; position: absolute; top: 100%; left: 0; z-index: 2000; min-width: 260px; margin-top: 6px; padding: 4px; border-radius: 8px;
    background: #fff; color: #222; box-shadow: 0 6px 24px rgba(0,0,0,.25); letter-spacing: 0; font-weight: normal; font-size: 14px; }
  .banner .stnpop.open { display: block; }
  /* station and location share the room left in the header (owner 2026-10-21): each name is cut with … on its own,
     the ▾ always shows; the theme colour of the location also tells it apart (the test location is red) */
  .banner .stnwrap { display: inline-flex; align-items: baseline; min-width: 0; }
  .banner .stnwrap > .stnbtn-top, .banner .curloc .stnbtn-top { display: inline-flex; align-items: baseline; min-width: 0; max-width: 100%; }
  .banner .hdtext { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; min-width: 0; }
  .banner .curloc { display: inline-flex; align-items: baseline; min-width: 0; white-space: pre; }
  @media screen and (max-width: 800px) {
    .banner .sitename.stnwrap { overflow: visible !important; max-width: calc(100vw - 175px) !important; }
    .banner .stnwrap > .stnbtn-top { flex: 0 1 auto; min-width: 4.5em; }
    .banner .curloc { flex: 0 1 auto; min-width: 4em; }
    .banner .liveaudio { white-space: nowrap; }
  }
  /* phones (owner 2026-10-21): the station name box cuts what passes its edge (long names end in …), so the station and
     location lists open over the page, under the header, instead of inside that box */
  @media screen and (max-width: 800px) {
    .banner .stnpop { position: fixed; top: 52px; left: 8px; right: 8px; min-width: 0; margin-top: 0; max-height: 70vh; overflow-y: auto; }
  }
  .banner .stnpop .stnitem { all: unset; box-sizing: border-box; display: flex; justify-content: space-between; gap: 14px; width: 100%; padding: 8px 10px; border-radius: 6px; cursor: pointer; }
  .banner .stnpop .stnitem:hover { background: rgba(0,0,0,.07); }
  .banner .stnpop .stnitem.cur { cursor: default; font-weight: 600; }
  .banner .stnpop .stnitem.down { opacity: .45; }
  .banner .stnpop .stnaddr { color: #666; font-family: monospace; font-size: 12px; align-self: center; }
  .banner .stnpop .stnitem.up .stnaddr { color: #2e7d32; }
  .banner .stnpop .stnmac { color: #888; font-size: 11px; }
  .banner .stnpop .stnaddr { text-align: right; }
  .banner .curloc { font-weight: normal; letter-spacing: 0; opacity: .85; }
  .banner .curloc .stnbtn-top::after { content: " \25BE"; font-size: .8em; opacity: .7; }
  .banner .stnpop .stnmanage { display: block; padding: 7px 10px; border-top: 1px solid #eee; margin-top: 4px; color: #1f6feb; text-decoration: none; font-size: 13px; }
  </style>
  <h1><a href="/"><span class="topimage wordmark">BirdSuite</span></a></h1>
  <div class="stream">
<?php
// Compact header (owner 2026-09-17): station name beside the small logo on the
// left, BirdSuite logo centred, Live Audio on the right — one line, no h3 below.
// Live Audio (owner 2026-10-10) plays in place (static/live-audio.js, the player first made for Now): no reload, so the
// page below keeps running; the time bar and a speaker with a vertical volume slider appear while it plays.
echo "
  <span class=\"livevol\" id=\"live_vol\" style=\"display:none\"><button type=\"button\" id=\"live_volbtn\" onclick=\"liveVolumeToggle()\" title=\"" . htmlspecialchars(t('Volume')) . "\">&#128266;</button>
    <span class=\"volpop\" id=\"live_volpop\"><input type=\"range\" id=\"live_volrange\" min=\"0\" max=\"1\" step=\"0.05\" orient=\"vertical\" oninput=\"liveVolume(this.value)\"></span></span>
  <button type=\"button\" class=\"liveaudio\" id=\"live_btn\" onclick=\"liveAudio()\" title=\"" . htmlspecialchars(t('Listen to the station\'s microphone live')) . "\">&#9654;</button>
  <audio id=\"live_audio\" preload=\"none\" controls controlslist=\"nodownload noplaybackrate\" style=\"display:none\"></audio>

  <script src=\"static/live-audio.js?v=" . @filemtime(__DIR__ . '/static/live-audio.js') . "\"></script>
  <script src=\"static/station-switch.js?v=" . @filemtime(__DIR__ . '/static/station-switch.js') . "\"></script>
  <script src=\"static/location-switch.js?v=" . @filemtime(__DIR__ . '/static/location-switch.js') . "\"></script>";
echo "
  </div>
</div>";
if(isset($_GET['filename'])) {
  // encoded, never raw request text in the page (2026-10-23 test run)
  $filename = htmlspecialchars(rawurlencode((string)$_GET['filename']), ENT_QUOTES);
echo "
<iframe allow=\"autoplay\" title=\"" . htmlspecialchars(t('Station page'), ENT_QUOTES) . "\" src=\"views.php?view=Recordings&amp;filename=$filename\"></iframe>";
} elseif(isset($_GET['view'])) {
  // a page's own address (/?view=...): the side menu links and the address bar point here
  echo "
<iframe allow=\"autoplay\" title=\"" . htmlspecialchars(t('Station page'), ENT_QUOTES) . "\" src=\"views.php?view=" . rawurlencode($_GET['view'])
  . implode('', array_map(function ($k) { return isset($_GET[$k]) ? '&' . $k . '=' . rawurlencode($_GET[$k]) : ''; }, array('sci', 'from', 'to', 'year', 'scope', 'yfrom', 'yto'))) . "\"></iframe>";
} else {
  echo "
<iframe allow=\"autoplay\" title=\"" . htmlspecialchars(t('Station page'), ENT_QUOTES) . "\" src=\"views.php\"></iframe>";
}
