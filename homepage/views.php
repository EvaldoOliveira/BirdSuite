<?php

/* Prevent XSS input */
require_once 'scripts/sanitize_input.php';
$_GET   = bs_sanitize_input(INPUT_GET);
$_POST  = bs_sanitize_input(INPUT_POST);

session_start(array('cookie_httponly' => true, 'cookie_samesite' => 'Lax'));  // as common.php BS_SESSION_OPTIONS (2026-10-23 test run)

require_once 'scripts/common.php';
$user = get_user();
$home = get_home();
$config = get_config();
$color_scheme = get_color_scheme();
set_timezone();

// Pages that need the login ask for it HERE, before any output (owner 2026-10-10): checked further down, after the menu
// was printed, the 401 header could no longer be sent, so a browser that had not logged in yet only saw "You cannot
// edit the settings" instead of the password dialog. The checks below stay as the second line of defence.
// External Files, Backup/Restore, Adminer, Raw Recording and the Detection Log too (2026-10-23 test run: with a
// password they drew the page and only their buttons asked for it)
// owner 2026-10-25: only the pages that show something sensitive or reach the machine ask when opened: the Tools,
// Notifications › Content and Repositories, Model (all three are the settings form, with the notification URLs and the
// BirdWeather token in it), Audio (RTSP URLs carry camera passwords), Defaults (every key, the Google API key too),
// Locations (exact coordinates), the Detection Log; a change on any page asks (any POST here, and the endpoints)
$protected_views = array('Tools', 'Webterm', 'Files', 'Wipe', 'Defaults', 'Database', 'External', 'LocationData', 'Backup', 'Adminer',
  'Raw Recording', 'View Log', 'Model', 'NotifyRules', 'Locations', 'Stations', 'Advanced', 'Audio', 'Settings');
$view_now = $_GET['view'] ?? '';
if (in_array($view_now, $protected_views, true) || isset($_GET['submit']) || $_SERVER['REQUEST_METHOD'] === 'POST'
    || ($view_now === 'Setup' && !(file_exists($home . '/BirdNET-Pi/state/firstrun_pending') && empty($config['CADDY_PWD'])))) {
  ensure_authenticated();
}

$restore = "cat $home/BirdSongs/restore.log";

if(is_authenticated() && (!isset($_SESSION['behind']) || !isset($_SESSION['behind_time']) || time() > $_SESSION['behind_time'] + 86400)) {
  shell_exec(station_prefix() . "git -C ".$home."/BirdNET-Pi fetch --tags --force > /dev/null 2>/dev/null &");
  $str = trim(shell_exec(station_prefix() . "git -C ".$home."/BirdNET-Pi status"));
  if (preg_match("/behind '.*?' by (\d+) commit(s?)\b/", $str, $matches)) {
    $num_commits_behind = $matches[1];
  }
  if (preg_match('/\b(\d+)\b and \b(\d+)\b different commits each/', $str, $matches)) {
    $num1 = (int) $matches[1];
    $num2 = (int) $matches[2];
    $num_commits_behind = $num1 + $num2;
  }
  if (stripos($str, "Your branch is up to date") !== false) {
    $num_commits_behind = '0';
  }
  $_SESSION['behind'] = $num_commits_behind;
  $_SESSION['behind_time'] = time();
  // release awareness: newest vX.Y.Z tag on the fork vs the newest one this installation contains (once a day)
  $_SESSION['release_installed'] = installed_release()[0];
  $latest = '';
  // the tags the background fetch brought (2026-10-23 test run: ls-remote made the first page of the day wait up to
  // 10 s without a network); a new release shows from the next day's check
  foreach (explode("\n", (string)shell_exec(station_prefix() . "git -C ".$home."/BirdNET-Pi tag -l 'v*' 2>/dev/null")) as $l) {
    if (preg_match('~^(v\d+\.\d+\.\d+)$~', trim($l), $m) && ($latest === '' || version_compare(substr($m[1], 1), substr($latest, 1), '>'))) $latest = $m[1];
  }
  $_SESSION['release_new'] = ($latest !== '' && $_SESSION['release_installed'] !== ''
    && version_compare(substr($latest, 1), substr($_SESSION['release_installed'], 1), '>')) ? $latest : '';
}
if(isset($_SESSION['behind'])&&intval($_SESSION['behind']) >= 99) {?>
  <style>
  .updatenumber { 
    width:30px !important;
  }
  </style>
<?php }
if ($config["LATITUDE"] == "0.000" && $config["LONGITUDE"] == "0.000") {
  echo "<center style='color:red'><b>" . t('WARNING: Your latitude and longitude are not set properly. Please do so now in Settings › Locations.') . "</center></b>";
}
elseif ($config["LATITUDE"] == "0.000") {
  echo "<center style='color:red'><b>" . t('WARNING: Your latitude is not set properly. Please do so now in Settings › Locations.') . "</center></b>";
}
elseif ($config["LONGITUDE"] == "0.000") {
  echo "<center style='color:red'><b>" . t('WARNING: Your longitude is not set properly. Please do so now in Settings › Locations.') . "</center></b>";
}
?>
<!DOCTYPE html>
<html lang="<?php echo str_replace('_', '-', ui_lang()); ?>">
<head>
  <meta charset="UTF-8">
  <?php echo ui_js(); ?>
  <script>window.BS_SFTP = <?php echo json_encode(sftp_base()); ?>;</script>
  <script src="static/location-switch.js?v=<?php echo @filemtime(__DIR__ . '/static/location-switch.js'); ?>"></script>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>BirdSuite</title>
  <link rel="stylesheet" href="<?php echo $color_scheme . '?v=' . filemtime($color_scheme); ?>"><?php echo theme_style(); ?>
</head>
<body>
<script>
// the menu's remembered state at once, before it is drawn (owner 2026-10-21): Simple / Advanced and collapsed
try { if ((localStorage.getItem('nav_mode') || 'simple') !== 'advanced') document.body.classList.add('nav-simple');
      if (localStorage.getItem('sidenav_collapsed') === '1') document.body.classList.add('sidenav-collapsed'); } catch (e) { document.body.classList.add('nav-simple'); }
// inside the station page frame the header carries the phone menu button: the frame's own one never shows
try { if (window.top !== window && window.top.location.host === location.host) document.body.classList.add('in-frame'); } catch (e) {}
</script>
<?php
// Side menu (owner 2026-10-08): groups like webmin, one real link per page (/?view=...), so a page opens
// in another tab and the address bar shows it. The page itself still loads in this frame.
$current_view = $_GET['view'] ?? 'See';
if ($current_view === 'Species Management') $current_view = 'Bird';
// old addresses that open another page: the menu marks the page shown (2026-10-23 test run)
$nav_view = array('System Controls' => 'Status', 'Services' => 'Status', 'Doctor' => 'Status', 'Overview' => 'Now',
  'Todays Detections' => 'Now', 'Species Stats' => 'Bird', 'All Detections' => 'Dashboard', 'Weekly Report' => 'Dashboard',
  'Streamlit' => 'Dashboard', 'Daily Charts' => 'Dashboard')[$current_view] ?? $current_view;
$update_badge = (isset($_SESSION['behind']) && intval($_SESSION['behind']) >= 50 && ($config['SILENCE_UPDATE_INDICATOR'] ?? 0) != 1)
  ? ' <span class="updatenumber">' . $_SESSION['behind'] . '</span>' : '';
if (($_SESSION['release_new'] ?? '') !== '' && ($config['SILENCE_UPDATE_INDICATOR'] ?? 0) != 1) {
  // a new release outranks the commits-behind count
  $update_badge = ' <span class="updatenumber" title="' . htmlspecialchars(t('New release {v} available', array('v' => $_SESSION['release_new']))) . '">1</span>';
}
$updatediv = $update_badge;
$menu = array(
  // main order (owner 2026-10-25): daily use first (Now, Spectrogram, Species, Reports), then the settings, what leaves
  // the station, the Lists, the tools and the system
  // view names kept, the labels are the owner's (2026-10-30): view=See is Now, view=Now is Latest
  array('Now', 'See'),  // the pictures of the birds singing now, first and the start page (owner 2026-10-30, US-130)
  array('Latest', 'Now'),
  array('Spectrogram', 'Spectrogram'),
  // order and names set by the owner (2026-10-09); Species Pages and Species Management are one page (view=Bird)
  // Species before Detections, Validation (a table per species) under Species Pages (owner 2026-10-22)
  array('Species', array('Curation' => 'Validation', 'Bird' => 'Species Cards')),  // Purge Files moved to Tools › Data (owner 2026-10-24)  // Validation first (owner 2026-10-22)
  // Detections renamed Reports (owner 2026-10-22; not "Analyses", which reads as the analysis service)
  // Model Comparison moved to Tools, named Shadow (owner 2026-10-25)
  array('Reports', array('Dashboard' => 'By Hour', 'Seasonality' => 'By Week', 'eBird' => 'eBird')),  // eBird (owner 2026-10-27)
  // Station Setup only while the first-run questions are unanswered; afterwards everything is in Settings
  // Basic Settings then Advanced Settings at the bottom (owner 2026-10-20)
  array('Settings', (file_exists($home . '/BirdNET-Pi/state/firstrun_pending') ? array('Setup' => 'Station Setup') : array())
                    // in the order a station is set up (owner 2026-10-25): looks, where, what it analyses, how it records,
                    // network, the sound repositories (BirdWeather, BirdDB-Br; below Network, owner 2026-10-25), access,
                    // then the defaults
                    + array('Appearance' => 'Themes', 'Locations' => 'Locations', 'Model' => 'Model', 'Audio' => 'Audio', 'Network' => 'Network',
                            'Settings' => 'Repositories', 'Access' => 'Access', 'Defaults' => 'Defaults')),
  // what leaves the station: the notifications (named Notifications, owner 2026-10-25)
  array('Notifications', array('NotifyRules' => 'Content', 'Email' => 'E-mail', 'Telegram' => 'Telegram')),
  // Lists below Notifications (owner 2026-10-25)
  array('Lists', array('Included' => 'Custom', 'Excluded' => 'Excluded', 'Whitelisted' => 'Whitelisted', 'Nocturnal' => 'Nocturnal')),
  // the audio of any folder analysed with the station's model, optional import (US-119, owner 2026-10-20)
  // System = the health and control of the station; the admin tools in Tools; Defaults in Settings (owner 2026-10-21)
  // no File Manager page (owner 2026-10-23): folders open over SFTP in the viewer's own file manager
  // External Files out of the menu, the page stays (owner 2026-10-24): views.php?view=External
  // the most used first; Raw Recording here, the Scheduling group gone (owner 2026-10-25)
  array('Tools', array('LocationData' => 'Data', 'Backup' => 'Backup/Import', 'Raw Recording' => 'Raw Recording',
                       'Models' => 'Shadow', 'Database' => 'Database', 'Files' => 'Browse files', 'Webterm' => 'Console Login')),
  // the stations of the network with the station's own health (owner 2026-10-25)
  // Stations only with several stations (US-125, simple mode)
  array('System', array('Status' => 'Status') + (multi_station() ? array('Stations' => 'Stations') : array()) + array('View Log' => 'Detection Log')),
);
// Simple / Advanced menu (owner 2026-10-20): Simple hides these groups and pages, Advanced shows everything; order =
// Now, Spectrogram, Species, Reports, Settings, Notifications, Lists, Tools, System
// views and groups are checked apart: the view 'Settings' (Repositories) shares its key with the Settings group
// System › Status also in the Simple menu (owner 2026-10-24: the update and the station's health); its Detection Log
// stays an Advanced page
// owner 2026-10-25: the pages that open without login are in the Simple menu, the ones that ask only in Advanced (the
// Spectrogram stays Simple: only its live audio asks)
$nav_advanced = array('Wipe', 'Model', 'Audio', 'Stations', 'Locations', 'Settings', 'Defaults', 'View Log', 'NotifyRules');
$nav_advanced_groups = array('Tools');
function nav_link($view, $label, $current, $badge = '') {
  global $nav_advanced;
  $cls = ($view === $current ? 'navitem active' : 'navitem') . (in_array($view, $nav_advanced, true) ? ' navadv' : '');
  return '<a class="' . $cls . '" data-view="' . htmlspecialchars($view, ENT_QUOTES) . '" href="/?view=' . rawurlencode($view) . '">'
    . htmlspecialchars(t($label)) . $badge . '</a>';
}
?>
<form action="views.php" method="GET" id="views"></form>
<?php if ($current_view !== 'Kiosk') { ?>
<button type="button" class="sidenav-toggle" onclick="document.body.classList.toggle('sidenav-open')" title="<?php echo htmlspecialchars(t('Menu')); ?>">&#9776;</button>
<?php // the menu styles come before the menu (owner 2026-10-21: it showed every page and the flags for a moment) ?>
<style>
  .sidenav .navlang { position: relative; margin: 0 8px 12px; font-size: 12px; }
  .sidenav .navlang button { display: flex; align-items: center; gap: 6px; width: 100%; padding: 4px 8px; border: 1px solid var(--accent,#2b5e22); border-radius: 12px; background: transparent; color: inherit; cursor: pointer; font-size: 12px; text-align: left; }
  .sidenav .navlang button svg { flex: 0 0 auto; border-radius: 2px; box-shadow: 0 0 0 1px rgba(0,0,0,.15); }
  .sidenav .navlang-cur span { flex: 1; }
  .sidenav .navlang-list { display: none; position: absolute; left: 0; right: 0; bottom: 100%; margin-bottom: 4px; background: var(--bg,#fff); border: 1px solid var(--accent,#2b5e22); border-radius: 10px; padding: 4px; z-index: 20; box-shadow: 0 2px 8px rgba(0,0,0,.2); }
  .sidenav .navlang.open .navlang-list { display: block; }
  .sidenav .navlang-list button { border: 0; border-radius: 6px; margin: 1px 0; }
  .sidenav .navlang-list button.active, .sidenav .navlang-list button:hover { background: var(--accent,#2b5e22); color: #fff; }
  body.sidenav-collapsed .sidenav .navlang span { display: none; }
</style>
<style>
  body.nav-simple .sidenav .navadv { display: none; }
  .sidenav .navmode { display: flex; gap: 4px; margin: 14px 8px 10px; }
  .sidenav .navmode button { flex: 1; padding: 4px 0; border: 1px solid var(--accent,#2b5e22); border-radius: 12px; background: transparent; color: inherit; cursor: pointer; font-size: 12px; }
  .sidenav .navmode button.active { background: var(--accent,#2b5e22); color: #fff; }
</style>
<nav class="sidenav" id="sidenav">
<button type="button" class="sidenav-collapse" id="sidenav_collapse" title="<?php echo htmlspecialchars(t('Collapse / expand the menu')); ?>"
  onclick="toggleSidenav()">&laquo;</button>
<?php
foreach ($menu as $entry) {
  if (!is_array($entry[1])) {
    echo nav_link($entry[1], $entry[0], $nav_view);
    continue;
  }
  // only Detections and Species expanded by default (owner 2026-10-21), plus the group of the page shown;
  // a click on a title folds or unfolds it
  $adv = in_array($entry[0], $nav_advanced_groups, true) ? ' navadv' : '';
  $open = ' open';  // every group expanded by default (owner 2026-10-22)
  echo '<details class="navgroup' . $adv . '"' . $open . '><summary>' . htmlspecialchars(t($entry[0]))
    . ($entry[0] === 'System' ? $update_badge : '') . '</summary>';
  foreach ($entry[1] as $view => $label) {
    echo nav_link($view, $label, $nav_view, $view === 'Status' ? $update_badge : '');
  }
  echo '</details>';
}
?>
<div class="navmode" role="group" aria-label="<?php echo htmlspecialchars(t('Menu')); ?>"><button type="button" data-navmode="simple" onclick="setNavMode('simple')" title="<?php echo htmlspecialchars(t('The everyday pages')); ?>"><?php echo htmlspecialchars(t('Simple')); ?></button><button type="button" data-navmode="advanced" onclick="setNavMode('advanced')" title="<?php echo htmlspecialchars(t('Every page: also the ones that ask for the login (Notifications › Content and Repositories, Model, Audio, Locations, Defaults, Tools, Stations, Detection Log)')); ?>"><?php echo htmlspecialchars(t('Advanced')); ?></button></div>
<?php // interface language (US-122, owner 2026-10-20): a picklist with the flags under Simple / Advanced; drawn flags,
      // since Windows shows emoji flags as two letters
$ui_flags = array('en' => '<svg viewBox="0 0 30 20" width="24" height="16" aria-hidden="true"><rect width="30" height="20" fill="#b22234"/><g fill="#fff"><rect y="1.54" width="30" height="1.54"/><rect y="4.62" width="30" height="1.54"/><rect y="7.69" width="30" height="1.54"/><rect y="10.77" width="30" height="1.54"/><rect y="13.85" width="30" height="1.54"/><rect y="16.92" width="30" height="1.54"/></g><rect width="13" height="10.77" fill="#3c3b6e"/></svg>', 'pt_BR' => '<svg viewBox="0 0 30 20" width="24" height="16" aria-hidden="true"><rect width="30" height="20" fill="#009c3b"/><path d="M15 2.5 27 10 15 17.5 3 10z" fill="#ffdf00"/><circle cx="15" cy="10" r="4.6" fill="#002776"/></svg>', 'es' => '<svg viewBox="0 0 30 20" width="24" height="16" aria-hidden="true"><rect width="30" height="20" fill="#aa151b"/><rect y="5" width="30" height="10" fill="#f1bf00"/></svg>', 'zh_CN' => '<svg viewBox="0 0 30 20" width="24" height="16" aria-hidden="true"><rect width="30" height="20" fill="#de2910"/><path d="M5 2.5l1.1 3.4H9.7L6.8 8l1.1 3.4L5 9.3 2.1 11.4 3.2 8 .3 5.9h3.6z" fill="#ffde00"/><g fill="#ffde00"><circle cx="10" cy="2" r=".8"/><circle cx="12" cy="4" r=".8"/><circle cx="12" cy="7" r=".8"/><circle cx="10" cy="9" r=".8"/></g></svg>', 'ja' => '<svg viewBox="0 0 30 20" width="24" height="16" aria-hidden="true"><rect width="30" height="20" fill="#fff"/><circle cx="15" cy="10" r="6" fill="#bc002d"/></svg>', 'de' => '<svg viewBox="0 0 30 20" width="24" height="16" aria-hidden="true"><rect width="30" height="6.67" fill="#000"/><rect y="6.67" width="30" height="6.67" fill="#dd0000"/><rect y="13.33" width="30" height="6.67" fill="#ffce00"/></svg>', 'fr' => '<svg viewBox="0 0 30 20" width="24" height="16" aria-hidden="true"><rect width="10" height="20" fill="#002395"/><rect x="10" width="10" height="20" fill="#fff"/><rect x="20" width="10" height="20" fill="#ed2939"/></svg>', 'it' => '<svg viewBox="0 0 30 20" width="24" height="16" aria-hidden="true"><rect width="10" height="20" fill="#009246"/><rect x="10" width="10" height="20" fill="#fff"/><rect x="20" width="10" height="20" fill="#ce2b37"/></svg>', 'nl' => '<svg viewBox="0 0 30 20" width="24" height="16" aria-hidden="true"><rect width="30" height="6.67" fill="#ae1c28"/><rect y="6.67" width="30" height="6.67" fill="#fff"/><rect y="13.33" width="30" height="6.67" fill="#21468b"/></svg>', 'pl' => '<svg viewBox="0 0 30 20" width="24" height="16" aria-hidden="true"><rect width="30" height="10" fill="#fff"/><rect y="10" width="30" height="10" fill="#dc143c"/></svg>', 'ru' => '<svg viewBox="0 0 30 20" width="24" height="16" aria-hidden="true"><rect width="30" height="6.67" fill="#fff"/><rect y="6.67" width="30" height="6.67" fill="#0039a6"/><rect y="13.33" width="30" height="6.67" fill="#d52b1e"/></svg>'); ?>
<div class="navlang" id="navlang">
  <button type="button" class="navlang-cur" onclick="document.getElementById('navlang').classList.toggle('open')" title="<?php echo htmlspecialchars(t('Interface language')); ?>"><?php echo $ui_flags[ui_lang()]; ?> <span><?php echo htmlspecialchars(ui_languages()[ui_lang()]); ?></span> &#9662;</button>
  <div class="navlang-list"><?php foreach (ui_languages() as $code => $name) echo '<button type="button" data-lang="' . $code . '"' . ($code === ui_lang() ? ' class="active"' : '') . ' onclick="setUiLang(this.dataset.lang)">' . $ui_flags[$code] . ' <span>' . htmlspecialchars($name) . '</span></button>'; ?></div>
</div>
</nav>
<script>
  // collapse the side menu to a narrow column (☰ expands it again), remembered per browser (owner 2026-10-08)
  function applySidenav(collapsed) {
    document.body.classList.toggle('sidenav-collapsed', collapsed);
    var b = document.getElementById('sidenav_collapse');
    if (b) b.innerHTML = collapsed ? '&#9776;' : '&laquo;';
  }
  function toggleSidenav() {
    var collapsed = !document.body.classList.contains('sidenav-collapsed');
    try { window.localStorage.setItem('sidenav_collapsed', collapsed ? '1' : '0'); } catch (e) {}
    applySidenav(collapsed);
  }
  try { applySidenav(window.localStorage.getItem('sidenav_collapsed') === '1'); } catch (e) {}
  // Simple (default) / Advanced menu, remembered per browser (owner 2026-10-20): Simple hides the groups and pages entirely
  function setNavMode(m) {
    document.body.classList.toggle('nav-simple', m !== 'advanced');
    document.querySelectorAll('#sidenav .navmode button').forEach(function (b) { b.classList.toggle('active', b.dataset.navmode === (m === 'advanced' ? 'advanced' : 'simple')); });
    try { window.localStorage.setItem('nav_mode', m); } catch (e) {}
  }
  (function () { var m = 'simple'; try { m = window.localStorage.getItem('nav_mode') || 'simple'; } catch (e) {} setNavMode(m); })();
  // the interface language for the whole station (UI_LANG), then the page reloads in it, header included (US-122)
  function setUiLang(l) {
    var x = new XMLHttpRequest();
    x.onload = function () {
      if (this.responseText.trim() === 'OK') { (window.top || window).location.reload(); return; }
      alert(this.status === 401 ? t('Log in first.') : (this.responseText.trim() || t('Not saved: {reason}', {reason: 'HTTP ' + this.status})));
    };
    x.open('POST', 'scripts/ui_lang.php?lang=' + encodeURIComponent(l), true);
    x.send();
  }
  document.addEventListener('click', function (e) { var n = document.getElementById('navlang'); if (n && !n.contains(e.target)) n.classList.remove('open'); });
  // a normal click loads the page in this frame (the header, live audio etc. stay); middle click, Ctrl/Cmd
  // click and "open in new tab" use the real link
  document.querySelectorAll('#sidenav a.navitem').forEach(function (a) {
    a.addEventListener('click', function (e) {
      if (e.button !== 0 || e.ctrlKey || e.metaKey || e.shiftKey || e.altKey) return;
      e.preventDefault();
      var v = document.getElementsByClassName('views')[0];
      if (v) v.style.opacity = '0.5';
      window.location.href = 'views.php?view=' + encodeURIComponent(a.dataset.view);
    });
  });
</script>
<?php } ?>
<script type="text/javascript" src="static/plupload.full.min.js"></script>
<!--<script type="text/javascript" src="static/moxie.js"></script>
<script type="text/javascript" src="static/plupload.dev.js"></script>-->
<script>
window.onload = function() {
  var elements = document.querySelectorAll("button[name=view]");

  var setViewsOpacity = function() {
      document.getElementsByClassName("views")[0].style.opacity = "0.5";
  };

  for (var i = 0; i < elements.length; i++) {
      elements[i].addEventListener('click', setViewsOpacity, false);
  }
};
// inside the station page frame the header carries the phone menu button (owner 2026-10-21)
try { if (window.top !== window && window.top.location.host === window.location.host) document.documentElement.classList.add('in-frame-pending'); } catch (e) {}
document.addEventListener('DOMContentLoaded', function () { if (document.documentElement.classList.contains('in-frame-pending')) document.body.classList.add('in-frame'); });
// keep the address bar of the station page on the page shown here (/?view=...), so reload and bookmarks work
try {
  if (window.top !== window && window.top.location.host === window.location.host) {
    window.top.history.replaceState(null, '', '/?view=' + encodeURIComponent(<?php echo json_encode($current_view); ?>)
      + <?php echo json_encode(implode('', array_map(function ($k) { return isset($_GET[$k]) ? '&' . $k . '=' . rawurlencode(html_entity_decode($_GET[$k], ENT_QUOTES)) : ''; }, array('sci', 'from', 'to', 'year', 'scope', 'yfrom', 'yto', 'filename')))); ?>);
  }
} catch (e) {}
function copyOutput(elem) {
  elem.innerHTML = t('Copied!');
  const copyText = document.getElementsByTagName("pre")[0].textContent;
  const textArea = document.createElement('textarea');
  textArea.style.position = 'absolute';
  textArea.style.left = '-100%';
  textArea.textContent = copyText;
  document.body.append(textArea);
  textArea.select();
  document.execCommand("copy");
}
</script>

<div class="views">
<?php
// Custom Species / Excluded / Whitelisted lists (owner 2026-10-09): one line per species, 'Scientific name_Common name';
// add / remove by scientific name (a name contained in another no longer matches), the file kept sorted
function update_species_list($filename, $species, $add) {
    $key = function ($line) { return explode('_', trim($line), 2)[0]; };
    $lines = array();
    foreach (@file($filename, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: array() as $l) {
        if (trim($l) !== '') $lines[$key($l)] = trim($l);
    }
    foreach ($species as $s) {
        $s = trim(htmlspecialchars_decode($s, ENT_QUOTES));
        if ($s === '') continue;
        if ($add) { if (!isset($lines[$key($s)])) $lines[$key($s)] = $s; }
        else unset($lines[$key($s)]);
    }
    ksort($lines, SORT_STRING);
    $text = $lines ? implode("\n", $lines) . "\n" : '';
    // a table linked to a saved list (Custom Species) is changed as a copy of its own (owner 2026-10-21): the saved list
    // stays as it was and the table shows "Table not saved" until it is saved under a name
    // (scripts/include_species_list.txt -> include_species_list.txt -> species_lists/<name>.txt: the last link is replaced)
    $target = $filename;
    if ($text !== (string)@file_get_contents($filename)) {
      for ($f = $filename, $i = 0; is_link($f) && $i < 5; $i++) {
        $next = readlink($f);
        if ($next !== '' && $next[0] !== '/') $next = dirname($f) . '/' . $next;
        if (strpos($next, '/species_lists/custom/') !== false && !is_link($next)) { @unlink($f); $target = $f; break; }
        $f = $next;
      }
    }
    file_put_contents($target, $text, LOCK_EX);
}

// First-run setup wizard (US-51c): an installation nobody answered opens on the wizard
if(file_exists($home.'/BirdNET-Pi/state/firstrun_pending') && (!isset($_GET['view']) || in_array($_GET['view'], array("Overview", "Now"), true))){
  $_GET['view'] = "Setup";
}
// every page opens with its title, the name in the menu (owner 2026-10-25); a few pages get a title and subtitle of
// their own; the setup wizard, the kiosk and a species page (its species is the title) have none
$page_titles = array('Models' => array('Model Comparison', 'Official versus Shadow'));
$pv = ($_GET['view'] ?? '') === 'Setup' ? 'Setup' : $nav_view;
$pt = $page_titles[$pv] ?? null;
if ($pt === null && !in_array($pv, array('Setup', 'Kiosk'), true) && !($pv === 'Bird' && trim($_GET['sci'] ?? '') !== '')) {
  foreach ($menu as $e) {
    if (is_array($e[1]) ? isset($e[1][$pv]) : $e[1] === $pv) { $pt = array(is_array($e[1]) ? $e[1][$pv] : $e[0]); break; }
  }
}
if ($pt) {
  echo '<div class="pagehead" style="padding:0 12px;margin:4px 0 8px"><h2 style="margin:0">' . htmlspecialchars(t($pt[0])) . '</h2>'
    . (isset($pt[1]) ? '<div class="small">' . htmlspecialchars(t($pt[1])) . '</div>' : '') . '</div>';
}
if (!in_array($pv, array('Setup', 'Kiosk'), true)) include('scripts/location_moved.php');
if(isset($_GET['view'])){
  if($_GET['view'] == "Setup"){include('scripts/setup_wizard.php');}
  if($_GET['view'] == "System Info"){echo "<iframe src='phpsysinfo/index.php'></iframe>";}
  // System Controls, Services and Station Doctor became System › Status (owner 2026-10-21); their old links open it
  if(in_array($_GET['view'], array("System Controls", "Services", "Doctor"), true)){$_GET['view'] = "Status";}
  if($_GET['view'] == "Status"){include('scripts/status.php');}  // opens without login, its buttons ask (owner 2026-10-25)
  // the database: sizes, check, optimise, download; the Adminer editor behind it (owner 2026-10-21)
  if($_GET['view'] == "Database"){ensure_authenticated(); include('scripts/database.php');}
  if($_GET['view'] == "See"){include('scripts/see.php');}
  if($_GET['view'] == "Spectrogram"){include('spectrogram.php');}
  if($_GET['view'] == "Raw Recording"){include('scripts/raw_recording.php');}
  // Species Pages = the species list (scripts/species_tools.php, also the old view=Species Management) or, with sci=, one species
  if($_GET['view'] == "Species Management"){$_GET['view'] = 'Bird';}
  if($_GET['view'] == "Bird"){include(trim($_GET['sci'] ?? '') === '' ? 'scripts/species_tools.php' : 'scripts/species_page.php');}
  if($_GET['view'] == "Curation"){include('scripts/curation.php');}
  // the owner's stations, switched from the station name in the header (US-117, owner 2026-10-20)
  if($_GET['view'] == "Stations"){include('scripts/stations.php');}
  // the places the station recorded at, with the current one (US-58, owner 2026-10-20)
  if($_GET['view'] == "Locations"){include('scripts/locations.php');}
  if($_GET['view'] == "External"){include('scripts/external_files.php');}
  // field access: station WiFi, fleet phone hotspot, WiFi of the place, clock, Identify, QR label (US-120)
  if($_GET['view'] == "Network"){include('scripts/network.php');}
  // e-mail notifications through a provider's SMTP, set up in a few fields (owner 2026-10-20)
  if($_GET['view'] == "Email"){include('scripts/email_settings.php');}
  // guided Telegram setup: one bot, the Normal and Prio groups (owner 2026-10-20)
  if($_GET['view'] == "Telegram"){include('scripts/telegram_settings.php');}
  // Content = the notification sections of the settings form
  if($_GET['view'] == "NotifyRules"){$config_section = 'notifications'; include('scripts/config.php');}
  // a location's records as a whole: remove, backup, move, restore (owner 2026-10-20)
  if($_GET['view'] == "LocationData"){$ld_mode = 'data'; include('scripts/location_data.php');}
  if($_GET['view'] == "Backup"){$ld_mode = 'backup'; include('scripts/location_data.php');}
  // By Hour (the former Dashboard trial) replaced All Detections (owner 2026-10-09); old All Detections and
  // Detections by... links open it with their period
  if($_GET['view'] == "All Detections" || $_GET['view'] == "Recordings" && !isset($_GET['filename'])) {
    if (isset($_GET['date'])) { $_GET['from'] = $_GET['to'] = $_GET['date']; }
    $_GET['view'] = 'Dashboard';
  }
  if($_GET['view'] == "Dashboard"){include('scripts/detections_dashboard.php');}
  if($_GET['view'] == "Seasonality"){include('scripts/seasonality.php');}
  if($_GET['view'] == "eBird"){include('scripts/ebird_page.php');}
  // official model against the shadow model, by species and record by record (owner 2026-10-10)
  if($_GET['view'] == "Models"){include('scripts/models.php');}
  if($_GET['view'] == "Wipe"){ensure_authenticated(); include('scripts/wipe_deleted.php');}
  // every default beside the station's value (US-121)
  if($_GET['view'] == "Defaults"){ensure_authenticated(); include('scripts/defaults.php');}
  if($_GET['view'] == "Appearance"){include('scripts/appearance.php');}
  if($_GET['view'] == "View Log"){
    // the log levels, from Settings › Advanced (owner 2026-10-25): saved as soon as one is chosen, the service restarts
    $lv = function ($key, $label) use ($config) {
      $cur = $config[$key] ?? 'error';
      $o = '';
      foreach (array('error' => t('Errors Only'), 'warning' => t('Warning'), 'info' => t('Info'), 'debug' => t('Debug')) as $v => $l) $o .= '<option value="' . $v . '"' . ($cur === $v ? ' selected' : '') . '>' . htmlspecialchars($l) . '</option>';
      return '<label style="margin-right:14px">' . htmlspecialchars($label) . ' <select data-setting="' . $key . '">' . $o . '</select></label>';
    };
    echo '<div style="padding:4px 8px;font-size:13px"><b>' . htmlspecialchars(t('Log level')) . ':</b> ' . $lv('LogLevel_BirdnetRecordingService', t('Recording')) . $lv('LogLevel_SpectrogramViewerService', t('Spectrogram')) . $lv('LogLevel_LiveAudioStreamService', t('Live audio'))
      . '<small style="opacity:.75">' . htmlspecialchars(t('Errors Only is recommended; not every service uses it.')) . '</small></div>';
    echo "<body style=\"scroll:no;overflow-x:hidden;\"><iframe style=\"width:calc( 100% + 1em);\" src=\"log\"></iframe></body>";
  }
  // the Overview is split in two pages (owner 2026-10-08): Now (default) = most recent detection, 5 most
  // recent, currently analysing; All Detections = the totals and today's chart. "Overview" = Now.
  if($_GET['view'] == "Overview" || $_GET['view'] == "Now"){$overview_part = 'now'; include('overview.php');}
  // Today's Detections was merged into Now (owner 2026-10-09): its totals and search sit above the most recent detections
  if($_GET['view'] == "Todays Detections"){$overview_part = 'now'; include('overview.php');}
  if($_GET['view'] == "Kiosk"){$kiosk = true;include('todays_detections.php');}
  // Best Detections and Detections by... were retired (owner 2026-10-09): Species Pages and By Hour cover them;
  // old links land there (a shared single-detection link, ?filename=, still opens play.php)
  if($_GET['view'] == "Species Stats"){$_GET['view'] = 'Bird'; include('scripts/species_page.php');}
  // Weekly Report and the whole Statistics menu were retired (owner 2026-10-09)
  if($_GET['view'] == "Weekly Report"){include('scripts/detections_dashboard.php');}
  // Species Stats (Streamlit) was retired from the menu (owner 2026-10-09): By Hour, By Week and the species pages cover it
  if($_GET['view'] == "Streamlit"){include('scripts/detections_dashboard.php');}
  // Daily Charts was retired (owner 2026-10-09): By Hour shows the same day better; old links open it on their date
  if($_GET['view'] == "Daily Charts"){
    if (isset($_GET['date'])) { $_GET['from'] = $_GET['to'] = $_GET['date']; }
    include('scripts/detections_dashboard.php');
  }
  if($_GET['view'] == "Tools"){
    ensure_authenticated();
    $url = $_SERVER['SERVER_NAME']."/scripts/adminer.php";
    echo "<div class=\"centered\">
      <form action=\"views.php\" method=\"GET\" id=\"views\">
      <button type=\"submit\" name=\"view\" value=\"Settings\" form=\"views\">".t('Settings')."</button>
      <button type=\"submit\" name=\"view\" value=\"Status\" form=\"views\">".t('Status').$updatediv."</button>
      <button type=\"submit\" name=\"view\" value=\"Database\" form=\"views\">".t('Database')."</button>
      <button type=\"submit\" name=\"view\" value=\"Webterm\" form=\"views\">".t('Console Login')."</button>
      <button type=\"submit\" name=\"view\" value=\"Included\" form=\"views\">".t('Custom Species List')."</button>
      <button type=\"submit\" name=\"view\" value=\"Excluded\" form=\"views\">".t('Excluded Species List')."</button>
      <button type=\"submit\" name=\"view\" value=\"Whitelisted\" form=\"views\">".t('Whitelist Species List')."</button>
      <button type=\"submit\" name=\"view\" value=\"Bird\" form=\"views\">".t('Species Cards')."</button>
      <button type=\"submit\" name=\"view\" value=\"Setup\" form=\"views\">".t('Setup Wizard')."</button>
      </form>
      </div>";
  }
  if($_GET['view'] == "Recordings"){include('play.php');}   // only a shared single-detection link (?filename=) reaches it
  // one settings form, three menu entries (owner 2026-10-20): Basic Settings, Model, Notifications › Content
  if($_GET['view'] == "Settings"){$config_section = 'basic'; include('scripts/config.php');}
  if($_GET['view'] == "Model"){$config_section = 'model'; include('scripts/config.php');}
  // Settings › Advanced split in two pages (owner 2026-10-25); an old Advanced link opens Audio
  if($_GET['view'] == "Advanced" || $_GET['view'] == "Audio"){$adv_page = 'audio'; include('scripts/advanced.php');}
  if($_GET['view'] == "Access"){$adv_page = 'access'; include('scripts/advanced.php');}
  if($_GET['view'] == "Included"){
    if(isset($_POST['species_lines']) && (isset($_POST['add']) or isset($_POST['del']))){
        update_species_list(BS_LISTS . '/include_species_list.txt', explode("\n", $_POST['species_lines']), isset($_POST['add']));
        // a reload of this page must not send the form again (2026-10-23 run 3: Load after ADD / REMOVE was undone)
        echo '<script>if (history.replaceState) history.replaceState(null, "", location.href);</script>';
    }
    $species_list="include";
    include('./scripts/species_list.php');
  }
  if($_GET['view'] == "Excluded"){
    if(isset($_POST['species_lines']) && (isset($_POST['add']) or isset($_POST['del']))){
        update_species_list(BS_LISTS . '/exclude_species_list.txt', explode("\n", $_POST['species_lines']), isset($_POST['add']));
        // a reload of this page must not send the form again (2026-10-23 run 3: Load after ADD / REMOVE was undone)
        echo '<script>if (history.replaceState) history.replaceState(null, "", location.href);</script>';
    }
    $species_list="exclude";
    include('./scripts/species_list.php');
  }
  if($_GET['view'] == "Whitelisted"){  // the lists open without login, a change posts here and asks (owner 2026-10-25)
    if(isset($_POST['species_lines']) && (isset($_POST['add']) or isset($_POST['del']))){
        update_species_list(BS_LISTS . '/whitelist_species_list.txt', explode("\n", $_POST['species_lines']), isset($_POST['add']));
        // a reload of this page must not send the form again (2026-10-23 run 3: Load after ADD / REMOVE was undone)
        echo '<script>if (history.replaceState) history.replaceState(null, "", location.href);</script>';
    }
    $species_list="whitelist";
    include('./scripts/species_list.php');
  }
  if($_GET['view'] == "Nocturnal"){  // dropped by day by the analysis (owner 2026-10-30, US-131)
    if(isset($_POST['species_lines']) && (isset($_POST['add']) or isset($_POST['del']))){
        update_species_list(BS_LISTS . '/nocturnal_species_list.txt', explode("\n", $_POST['species_lines']), isset($_POST['add']));
        echo '<script>if (history.replaceState) history.replaceState(null, "", location.href);</script>';
    }
    $species_list="nocturnal";
    include('./scripts/species_list.php');
  }
  if($_GET['view'] == "Adminer"){
    echo "<iframe src='scripts/adminer.php'></iframe>";
  }
  if($_GET['view'] == "Webterm"){
    ensure_authenticated(t('You cannot access the web terminal'));
    echo "<iframe src='terminal'></iframe>";
  }
  // Midnight Commander after a login with the password (owner 2026-10-24: a real file manager, no keys)
  if($_GET['view'] == "Files"){
    ensure_authenticated(t('You cannot access the web terminal'));
    echo "<iframe src='files'></iframe>";
  }
} elseif(isset($_GET['submit'])) {
  ensure_authenticated();
  $allowedCommands = array('sudo -n /usr/local/sbin/birdsuite-svc stop livestream.service && sudo -n /usr/local/sbin/birdsuite-svc stop icecast2.service',
                     'sudo -n /usr/local/sbin/birdsuite-svc restart livestream.service && sudo -n /usr/local/sbin/birdsuite-svc restart icecast2.service',
                     'sudo -n /usr/local/sbin/birdsuite-svc disable-now livestream.service && sudo -n /usr/local/sbin/birdsuite-svc disable icecast2 && sudo -n /usr/local/sbin/birdsuite-svc stop icecast2.service',
                     'sudo -n /usr/local/sbin/birdsuite-svc enable icecast2 && sudo -n /usr/local/sbin/birdsuite-svc start icecast2.service && sudo -n /usr/local/sbin/birdsuite-svc enable-now livestream.service',
                     'sudo -n /usr/local/sbin/birdsuite-svc stop web_terminal.service',
                     'sudo -n /usr/local/sbin/birdsuite-svc restart web_terminal.service',
                     'sudo -n /usr/local/sbin/birdsuite-svc disable-now web_terminal.service',
                     'sudo -n /usr/local/sbin/birdsuite-svc enable-now web_terminal.service',
                     'sudo -n /usr/local/sbin/birdsuite-svc stop birdnet_log.service',
                     'sudo -n /usr/local/sbin/birdsuite-svc restart birdnet_log.service',
                     'sudo -n /usr/local/sbin/birdsuite-svc disable-now birdnet_log.service',
                     'sudo -n /usr/local/sbin/birdsuite-svc enable-now birdnet_log.service',
                     'sudo -n /usr/local/sbin/birdsuite-svc stop birdnet_analysis.service',
                     'sudo -n /usr/local/sbin/birdsuite-svc restart birdnet_analysis.service',
                     'sudo -n /usr/local/sbin/birdsuite-svc disable-now birdnet_analysis.service',
                     'sudo -n /usr/local/sbin/birdsuite-svc enable-now birdnet_analysis.service',
                     'sudo -n /usr/local/sbin/birdsuite-svc stop birdnet_recording.service',
                     'sudo -n /usr/local/sbin/birdsuite-svc restart birdnet_recording.service',
                     'sudo -n /usr/local/sbin/birdsuite-svc disable-now birdnet_recording.service',
                     'sudo -n /usr/local/sbin/birdsuite-svc enable-now birdnet_recording.service',
                     'sudo -n /usr/local/sbin/birdsuite-svc stop chart_viewer.service',
                     'sudo -n /usr/local/sbin/birdsuite-svc restart chart_viewer.service',
                     'sudo -n /usr/local/sbin/birdsuite-svc disable-now chart_viewer.service',
                     'sudo -n /usr/local/sbin/birdsuite-svc enable-now chart_viewer.service',
                     'sudo -n /usr/local/sbin/birdsuite-svc stop spectrogram_viewer.service',
                     'sudo -n /usr/local/sbin/birdsuite-svc restart spectrogram_viewer.service',
                     'sudo -n /usr/local/sbin/birdsuite-svc disable-now spectrogram_viewer.service',
                     'sudo -n /usr/local/sbin/birdsuite-svc enable-now spectrogram_viewer.service',
                     'sudo -n /usr/local/sbin/birdsuite-svc enable '.get_service_mount_name().' && sudo -n /usr/local/sbin/birdsuite-root reboot',
                     'sudo -n /usr/local/sbin/birdsuite-svc disable '.get_service_mount_name().' && sudo -n /usr/local/sbin/birdsuite-root reboot',
                     'stop_core_services.sh',
                     'restart_services.sh',
                     'sudo -n /usr/local/sbin/birdsuite-root reboot',
                     'sudo -n /usr/local/sbin/birdsuite-root update',
                     'sudo -n /usr/local/sbin/birdsuite-root shutdown',
                     'clear_all_data.sh',
                     'factory_settings.sh',
                     "$restore");
    $command = $_GET['submit'];
    if(in_array($command,$allowedCommands)){
      if(isset($command)){
        $initcommand = $command;
		  if (strpos($command, "systemctl") !== false) {
			  //If there more than one command to execute, processes then separately
			  //currently only livestream service uses multiple commands to interact with the required services
			  if (strpos($command, " && ") !== false) {
				  $separate_commands = explode("&&", trim($command));
				  $new_multiservice_status_command = "";
				  foreach ($separate_commands as $indiv_service_command) {
					  //explode the string by " " space so we can get each individual component of the command
					  //and eventually the service name at the end
					  $separate_command_tmp = explode(" ", trim($indiv_service_command));
					  //get the service names
					  $new_multiservice_status_command .= " " . trim(end($separate_command_tmp));
				  }

				  $service_names = $new_multiservice_status_command;
			  } else {
                  //only one service needs restarting so we only need to query the status of one service
				  $tmp = explode(" ", trim($command));
				  $service_names = end($tmp);
			  }

          $command .= " & sleep 3;sudo -n /usr/local/sbin/birdsuite-svc status " . $service_names;
        }
        if($initcommand == "sudo -n /usr/local/sbin/birdsuite-root update") {
          session_unset();
        }
        $results = shell_exec("$command 2>&1");
        $results = str_replace("FAILURE", "<span style='color:red'>FAILURE</span>", $results);
        $results = str_replace("failed", "<span style='color:red'>failed</span>",$results);
        $results = str_replace("active (running)", "<span style='color:green'><b>active (running)</b></span>",$results);
        $results = str_replace("Your branch is up to date", "<span style='color:limegreen'><b>Your branch is up to date</b></span>",$results);

        $results = str_replace("(+)", "(<span style='color:lime;font-weight:bold'>+</span>)",$results);
        $results = str_replace("(-)", "(<span style='color:red;font-weight:bold'>-</span>)",$results);

        // split the input string into lines
        $lines = explode("\n", $results);

        // iterate over each line
        foreach ($lines as &$line) {
            // check if the line matches the pattern
            if (preg_match('/^(.+?)\s*\|\s*(\d+)\s*([\+\- ]+)(\d+)?$/', $line, $matches)) {
                // extract the filename, count, and indicator letters
                $filename = $matches[1];
                $count = $matches[2];
                $diff = $matches[3];
                $delta = $matches[4] ?? '';
                // determine the indicator letters
                $diff_array = str_split($diff);
                $indicators = array_map(function ($d) use ($delta) {
                    if ($d === '+') {
                        return "<span style='color:lime;'><b>+</b></span>";
                    } elseif ($d === '-') {
                        return "<span style='color:red;'><b>-</b></span>";
                    } elseif ($d === ' ') {
                        if ($delta !== '') {
                            return 'A';
                        } else {
                            return ' ';
                        }
                    }
                }, $diff_array);
                // modify the line with the new indicator letters
                $line = sprintf('%-35s|%3d %s%s', $filename, $count, implode('', $indicators), $delta);
            }
        }

        // rejoin the modified lines into a string
        $output = implode("\n", $lines);
        $results = $output;

        // remove script tags (xss)
        $results = preg_replace('#<script(.*?)>(.*?)</script>#is', '', $results);
        if(strlen($results) == 0) {
          $results = t('This command has no output.');
        }
        echo "<table style='min-width:70%;'><tr class='relative'><th>".t('Output of command:')."`".$initcommand."`<button class='copyimage' style='right:40px' onclick='copyOutput(this);'>".t('Copy')."</button></th></tr><tr><td style='padding-left: 0px;padding-right: 0px;padding-bottom: 0px;padding-top: 0px;'><pre class='bash' style='text-align:left;margin:0px'>$results</pre></td></tr></table>"; 
      }
    }
  ob_end_flush();
} else {include('scripts/see.php');}
?>
<script>
function setLiveStreamVolume(vol) {
  var audioElements = document.querySelectorAll(".custom-audio-player audio");
  audioElements.forEach(audioEl => {
    if (audioEl) {
      audioEl.volume = vol;
    }
  });
}
window.onbeforeunload = function(event) {
  // if the user is playing a video and then navigates away mid-play, the live stream audio should be unmuted again
  var audioElements = document.querySelectorAll(".custom-audio-player audio");
  audioElements.forEach(audioEl => {
    if (audioEl) {
      audioEl.volume = 1;
    }
  });
}

function getTheDate(increment) {
  var theDate = "<?php if (isset($theDate)) echo $theDate;?>";

  d = new Date(theDate);
  d.setDate(d.getDate(theDate) + increment);
  yyyy = d.getFullYear();
  mm = d.getMonth() + 1; if (mm < 10) mm = "0" + mm;
  dd = d.getDate(); if (dd < 10) dd = "0" + dd;

  document.getElementById("SwipeSpinner").hidden = false;
  
  window.location = "/views.php?date="+yyyy+"-"+mm+"-"+dd+"&view=Daily+Charts";
}

function installKeyAndSwipeEventHandler() {
  // (was keyed on the old top menu's highlighted button)
  for (var i = 0; i < 1; i++) {
    if (<?php echo json_encode($current_view); ?> === "Daily Charts") {

      document.onkeydown = function(event) {
        switch (event.keyCode) {
          case 37: //Left key
            getTheDate(-1);
            break;
          case 39: //Right key
            getTheDate(+1);
            break;
        }
      };

      // https://stackoverflow.com/questions/2264072/detect-a-finger-swipe-through-javascript-on-the-iphone-and-android
      let touchstartX = 0;
      let diffX = 0;
      let touchstartY = 0;
      let diffY = 0;
      let startTime = 0;
      let diffTime = 0;
    
      function checkDirection() {
        if (Math.abs(diffX) > Math.abs(diffY) && diffTime < 350) {
          if (diffX > 20) getTheDate(+1);
          if (diffX < -20) getTheDate(-1);
        }
      }

      document.addEventListener('touchstart', e => {
        touchstartX = e.changedTouches[0].screenX;
        touchstartY = e.changedTouches[0].screenY;
        startTime = Date.now();
      });

      document.addEventListener('touchend', e => {
        diffX = touchstartX - e.changedTouches[0].screenX;
        diffY = touchstartY - e.changedTouches[0].screenY;
        diffTime = Date.now() - startTime;
        checkDirection();
      });
    }
  }
}

installKeyAndSwipeEventHandler();
</script>
</div>
</body>
