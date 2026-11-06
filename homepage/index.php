<?php
################################################################################
# homepage/index.php - THE ENTRY PAGE: THE CAPTIVE SETUP, THE LOGIN AND THE PAGE FRAME
#
# PURPOSE:
#   The entry page of the station (/): while the station's own WiFi is up every name lands here and a new
#   station goes to First Run (captive setup, US-120); otherwise the page frame with the menu, the live strip
#   (hearing panel, live audio, station and location switches) and the view chosen, views.php inside it.
#
# DEPENDENCIES:
#   - scripts/core.php
#   - scripts/api.php
#   - scripts/sanitize_input.php
#   - scripts/common.php
#   - homepage/static/location-switch.js
#   - homepage/static/live-audio.js
#   - homepage/static/hearing-audio.js
#   - homepage/static/hearing-panel.js
#   - homepage/static/station-switch.js
#   - scripts/field_net.py
#
# USAGE:
#   Referenced by homepage/views.php, scripts/appearance.php, templates/index_bootstrap.html
################################################################################

$requestUri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

// Captive setup page (US-120, owner 2026-10-20): while the station's own WiFi is on, its DNS answers every name with
// the station (10.42.N.1, its own address: field_net.py ap_addr); a request for another name (the phone's connectivity check after joining) is sent to the station, so the
// phone opens it by itself — the setup on a new station, the home page otherwise
if (is_file('/run/birdsuite/ap_since')) {
	require_once 'scripts/core.php';
	$host = strtolower(preg_replace('/:\d+$/', '', $_SERVER['HTTP_HOST'] ?? ''));
	$fs = json_decode((string)@file_get_contents('/run/birdsuite/field_state.json'), true);
	$ap = is_array($fs) && preg_match('/^10\.42\.\d{1,3}\.1$/', (string)($fs['ap_addr'] ?? '')) ? $fs['ap_addr'] : '10.42.0.1';
	$own = array($ap, 'localhost', '127.0.0.1', strtolower(gethostname()), strtolower(gethostname()) . '.local');
	if ($host !== '' && !in_array($host, $own, true) && !preg_match('/^birdsuite-[0-9a-f]{6,12}\.local$/', $host)
			&& !filter_var($host, FILTER_VALIDATE_IP)) {
		header('Location: http://' . $ap . '/' . (file_exists(BS_FIRSTRUN) ? '?view=First%20Run' : ''), true, 302);
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
	<button type="button" class="topmenu" title="<?php echo htmlspecialchars(t('str_menu')); ?>"
		onclick="var f = document.querySelector('iframe'); try { f.contentWindow.document.body.classList.toggle('sidenav-open'); } catch (e) {}">&#9776;</button>
	<div class="logo">
<?php // the station logo links to the station home
echo "<a href=\"/\" title=\"" . htmlspecialchars(t('str_station_home'), ENT_QUOTES) . "\"><img src=\"images/BirdNetBr.png\" alt=\"" . htmlspecialchars(t('str_station_home'), ENT_QUOTES) . "\"></a>"; ?>
	</div>
	<?php // the station name opens the station list (US-117, owner 2026-10-20): name and address, a click opens the same page on another station ?>
	<?php // simple mode (US-125): the station name without the station list, no location beside it ?>
	<div class="sitename stnwrap"><?php if (multi_station()) { ?><button type="button" class="stnbtn-top" id="station_btn" title="<?php echo htmlspecialchars(t('str_switch_station')); ?>"><span class="hdtext"><?php echo $site_name; ?></span></button><?php } else { ?><span class="stnbtn-top"><span class="hdtext"><?php echo $site_name; ?></span></span><?php } ?><?php
		// the current location beside the station: "Station - Location" (owner 2026-10-20); like the station name, a click
		// opens the list of locations and a click on one changes the current location (owner 2026-10-21, static/location-switch.js)
		$cur_loc = location_current(); if (multi_location()) { ?><span class="curloc"> - <button type="button" class="stnbtn-top" id="loc_btn" title="<?php echo htmlspecialchars(($cur_loc ? $cur_loc['name'] . ': ' : '') . t('str_change_the_current_location')); ?>"><span class="hdtext"><?php echo htmlspecialchars($cur_loc ? $cur_loc['name'] : t('str_location_not_registered')); ?></span></button></span><?php } ?>
		<div class="stnpop" id="loc_pop"></div>
		<div class="stnpop" id="station_pop"></div></div>
	<style>
	.banner .stnwrap { position: relative; }
	.banner .stnbtn-top { all: unset; cursor: pointer; font: inherit; letter-spacing: inherit; }
	/* all: unset also took the focus mark away (release run 5, A11Y-23): the app's ring, in both themes (this style is in the page) */
	.banner button.stnbtn-top:focus-visible { outline: 2px solid #d97a00; outline-offset: 2px; border-radius: 3px; }
	.banner .stnbtn-top.many::after { content: " \25BE"; font-size: .8em; opacity: .7; }
	.banner .stnpop { display: none; position: absolute; top: 100%; left: 0; z-index: 2000; min-width: 260px; margin-top: 6px; padding: 4px; border-radius: 8px;
		background: #fff; color: #222; box-shadow: 0 6px 24px rgba(0,0,0,.25); letter-spacing: 0; font-weight: normal; font-size: 14px; }
	.banner .stnpop.open { display: block; }
	/* the white list keeps dark text in Dark too, whose stylesheet colours every element white (release run 5, NEW-UX-R5-13);
		 its rows show the keyboard focus like the buttons above (all: unset took it away) */
	.banner .stnpop * { color: inherit; }
	.banner .stnpop .stnitem:focus-visible { outline: 2px solid #d97a00; outline-offset: -2px; }
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
// Compact header: the logo and the station name on the left, the BirdSuite wordmark, Live Audio on the right.
// Live Audio (owner 2026-10-10) plays in place (static/live-audio.js, the player first made for Now): no reload, so the
// page below keeps running; the time bar and a speaker with a vertical volume slider appear while it plays.
echo "
  <span class=\"livevol\" id=\"live_vol\" style=\"display:none\"><button type=\"button\" id=\"live_volbtn\" onclick=\"liveVolumeToggle()\" title=\"" . htmlspecialchars(t('str_volume')) . "\">&#128266;</button>
    <span class=\"volpop\" id=\"live_volpop\"><input type=\"range\" id=\"live_volrange\" min=\"0\" max=\"1\" step=\"0.05\" orient=\"vertical\" oninput=\"liveVolume(this.value)\"></span></span>
  <button type=\"button\" class=\"heargear\" id=\"hear_gear\" hidden onclick=\"hearConfig()\" title=\"" . htmlspecialchars(t('str_settings_of_the_frequency_lowering')) . "\" aria-label=\"" . htmlspecialchars(t('str_settings_of_the_frequency_lowering')) . "\"><svg viewBox=\"0 0 24 24\" width=\"18\" height=\"18\" fill=\"none\" stroke=\"currentColor\" stroke-width=\"2\" stroke-linecap=\"round\" stroke-linejoin=\"round\" aria-hidden=\"true\"><circle cx=\"12\" cy=\"12\" r=\"3\"/><path d=\"M19.4 15a1.7 1.7 0 0 0 .3 1.8l.1.1a2 2 0 1 1-2.8 2.8l-.1-.1a1.7 1.7 0 0 0-1.8-.3 1.7 1.7 0 0 0-1 1.5V21a2 2 0 1 1-4 0v-.1a1.7 1.7 0 0 0-1.1-1.5 1.7 1.7 0 0 0-1.8.3l-.1.1a2 2 0 1 1-2.8-2.8l.1-.1a1.7 1.7 0 0 0 .3-1.8 1.7 1.7 0 0 0-1.5-1H3a2 2 0 1 1 0-4h.1a1.7 1.7 0 0 0 1.5-1.1 1.7 1.7 0 0 0-.3-1.8l-.1-.1a2 2 0 1 1 2.8-2.8l.1.1a1.7 1.7 0 0 0 1.8.3H9a1.7 1.7 0 0 0 1-1.5V3a2 2 0 1 1 4 0v.1a1.7 1.7 0 0 0 1 1.5 1.7 1.7 0 0 0 1.8-.3l.1-.1a2 2 0 1 1 2.8 2.8l-.1.1a1.7 1.7 0 0 0-.3 1.8V9a1.7 1.7 0 0 0 1.5 1H21a2 2 0 1 1 0 4h-.1a1.7 1.7 0 0 0-1.5 1z\"/></svg></button>
  <button type=\"button\" class=\"hearbtn\" id=\"hear_btn\" onclick=\"hearToggle()\" aria-pressed=\"false\" title=\"" . htmlspecialchars(t('str_hear_high_songs_lower') . ': ' . t('str_hear_high_songs_lower_help')) . "\" aria-label=\"" . htmlspecialchars(t('str_hear_high_songs_lower')) . "\"><svg viewBox=\"0 0 24 24\" width=\"18\" height=\"18\" fill=\"none\" stroke=\"currentColor\" stroke-width=\"2\" stroke-linecap=\"round\" stroke-linejoin=\"round\" aria-hidden=\"true\"><path d=\"M10.9 7.7A5.8 6.4 0 0 1 22.4 8c0 2.2-1.1 3.4-2.1 4.5-1.1 1.2-1.6 2.2-2.2 3.5-.9 2-2.1 3-3.7 3-1.5 0-2.4-1-2.7-2.4\"/><path d=\"M14.1 8.3A2.6 2.6 0 0 1 19.2 8c0 1.3-.7 2.2-1.4 3-.7.8-1 1.6-1.4 2.6\"/><path d=\"M3.1 2.6Q-.2 8.1 3.1 13.7M6.8 5Q4.6 8.1 6.8 11.2M2.4 21.1h7.3\"/><circle cx=\"6.7\" cy=\"21.1\" r=\"1.5\" fill=\"currentColor\" stroke=\"none\"/></svg></button>
  <button type=\"button\" class=\"liveaudio\" id=\"live_btn\" onclick=\"liveAudio()\" title=\"" . htmlspecialchars(t('str_listen_to_the_stations_help')) . "\">&#9654;</button>
  <audio id=\"live_audio\" preload=\"none\" controls controlslist=\"nodownload noplaybackrate\" style=\"display:none\"></audio>

  <script src=\"static/hearing-audio.js?v=" . @filemtime(__DIR__ . '/static/hearing-audio.js') . "\"></script>
  <script src=\"static/hearing-panel.js?v=" . @filemtime(__DIR__ . '/static/hearing-panel.js') . "\"></script>
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
<iframe class=\"pageframe\" allow=\"autoplay\" title=\"" . htmlspecialchars(t('str_station_page'), ENT_QUOTES) . "\" src=\"views.php?view=Detection&amp;filename=$filename\"></iframe>";
} elseif(isset($_GET['view'])) {
	// a page's own address (/?view=...): the side menu links and the address bar point here
	echo "
<iframe class=\"pageframe\" allow=\"autoplay\" title=\"" . htmlspecialchars(t('str_station_page'), ENT_QUOTES) . "\" src=\"views.php?view=" . rawurlencode($_GET['view'])
	. implode('', array_map(function ($k) { return isset($_GET[$k]) ? '&' . $k . '=' . rawurlencode($_GET[$k]) : ''; }, array('sci', 'from', 'to', 'year', 'scope', 'yfrom', 'yto'))) . "\"></iframe>";
} else {
	echo "
<iframe class=\"pageframe\" allow=\"autoplay\" title=\"" . htmlspecialchars(t('str_station_page'), ENT_QUOTES) . "\" src=\"views.php\"></iframe>";
}
