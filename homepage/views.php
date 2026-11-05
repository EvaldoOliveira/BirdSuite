<?php

/* Prevent XSS input */
require_once 'scripts/sanitize_input.php';
$_GET   = bs_sanitize_input(INPUT_GET);
$_POST  = bs_sanitize_input(INPUT_POST);

session_start(array('cookie_httponly' => true, 'cookie_samesite' => 'Lax'));  // as common.php BS_SESSION_OPTIONS (2026-10-23 test run)

require_once 'scripts/common.php';
require_once 'scripts/view_registry.php';
$user = get_user();
$home = get_home();
$config = get_config();
$color_scheme = get_color_scheme();
set_timezone();

// Pages that need the login ask for it HERE, before any output (owner 2026-10-10): checked further down, after the menu
// was printed, the 401 header could no longer be sent. The checks below stay as the second line of defence.
// Which pages ask when opened is the registry's (scripts/view_registry.php, US-50): the pages that show something
// sensitive or reach the machine (owner 2026-10-25); a change on any page asks (any POST here, and the endpoints).
// An old view key opens its page (US-50): the address is resolved to the current key before anything else.
if (isset($_GET['view']) && ($resolved = view_resolve($_GET['view'])) !== '') $_GET['view'] = $resolved;
$view_now = $_GET['view'] ?? '';
$view_e = view_entry($view_now);
// the roles (US-53): a page opens with its 'open' role, a change on it needs its 'change' role (viewer = no login,
// operator = a device on the LAN of a household station, admin = the login); the station's commands always the login
// the page that sets the password opens with the factory password too (bug #60), which changes nothing else; First Run
// is open while pending without a password, else it needs the login
$setup_view = $view_now === 'Access';
if (isset($_GET['submit'])) {
  $setup_view ? ensure_password_setup() : ensure_authenticated();
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $setup_view && factory_login() ? ensure_password_setup() : ensure_role($view_e ? $view_e['change'] : 'admin');
} elseif ($view_now === 'First Run') {
  // open while the first run is pending and no password is set, else the login
  if (!(file_exists(BS_FIRSTRUN) && empty($config['CADDY_PWD']))) ensure_authenticated();
} elseif ($view_e) {
  $setup_view && factory_login() ? ensure_password_setup() : ensure_role($view_e['open']);
}

$restore = "cat " . BS_RECS . "/restore.log";

if(is_authenticated() && (!isset($_SESSION['behind']) || !isset($_SESSION['behind_time']) || time() > $_SESSION['behind_time'] + 86400)) {
  // the update branch fetched in the background (the next day's check sees it); the count from the last fetch
  shell_exec(station_prefix() . "git -C " . escapeshellarg(BS_PROG) . " fetch --tags --force origin " . escapeshellarg($config['UPDATE_BRANCH'] ?? 'main') . " > /dev/null 2>/dev/null &");
  $_SESSION['behind'] = update_behind();
  $_SESSION['behind_time'] = time();
  // release awareness: newest vX.Y.Z tag on the fork vs the newest one this installation contains (once a day)
  $_SESSION['release_installed'] = installed_release()[0];
  $latest = '';
  // the tags the background fetch brought (2026-10-23 test run: ls-remote made the first page of the day wait up to
  // 10 s without a network); a new release shows from the next day's check
  foreach (explode("\n", (string)shell_exec(station_prefix() . "git -C " . BS_PROG . " tag -l 'v*' 2>/dev/null")) as $l) {
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
  echo "<center style='color:red'><b>" . t('str_warning_your_latitude_and_help') . "</center></b>";
}
elseif ($config["LATITUDE"] == "0.000") {
  echo "<center style='color:red'><b>" . t('str_warning_your_latitude_is_not_help') . "</center></b>";
}
elseif ($config["LONGITUDE"] == "0.000") {
  echo "<center style='color:red'><b>" . t('str_warning_your_longitude_is_not_help') . "</center></b>";
}
?>
<!DOCTYPE html>
<html lang="<?php echo str_replace('_', '-', ui_lang()); ?>">
<head>
  <meta charset="UTF-8">
  <?php echo ui_js(); ?>
  <script>window.BS_SFTP = <?php echo json_encode(sftp_base()); ?>; window.BS_BY_DATE = <?php echo json_encode(BS_BY_DATE); ?>;</script>
  <script src="static/location-switch.js?v=<?php echo @filemtime(__DIR__ . '/static/location-switch.js'); ?>"></script>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>BirdSuite</title>
  <link rel="stylesheet" href="<?php echo $color_scheme . '?v=' . filemtime($color_scheme); ?>"><?php echo theme_style(); ?>
</head>
<body class="lvl-<?php echo ui_level(); ?><?php echo ui_field_context() ? ' ctx-field' : ''; ?>">
<script>
// the menu's remembered state at once, before it is drawn (owner 2026-10-21): Show all pages and collapsed; a browser
// that had the Advanced menu (nav_mode, before US-52) keeps seeing every page
try { if (localStorage.getItem('show_all') === null && localStorage.getItem('nav_mode') === 'advanced') localStorage.setItem('show_all', '1');
      if (localStorage.getItem('show_all') === '1') document.body.classList.add('show-all');
      if (localStorage.getItem('sidenav_collapsed') === '1') document.body.classList.add('sidenav-collapsed'); } catch (e) {}
// inside the station page frame the header carries the phone menu button: the frame's own one never shows
try { if (window.top !== window && window.top.location.host === location.host) document.body.classList.add('in-frame'); } catch (e) {}
</script>
<?php
// Side menu (owner 2026-10-08): groups like webmin, one real link per page (/?view=...), so a page opens
// in another tab and the address bar shows it. The page itself still loads in this frame.
$current_view = $_GET['view'] ?? 'Now';
$nav_view = $current_view;
$update_badge = (isset($_SESSION['behind']) && intval($_SESSION['behind']) >= 50 && ($config['SILENCE_UPDATE_INDICATOR'] ?? 0) != 1)
  ? ' <span class="updatenumber">' . $_SESSION['behind'] . '</span>' : '';
if (($_SESSION['release_new'] ?? '') !== '' && ($config['SILENCE_UPDATE_INDICATOR'] ?? 0) != 1) {
  // a new release outranks the commits-behind count
  $update_badge = ' <span class="updatenumber" title="' . htmlspecialchars(t('str_new_release_v_available', array('v' => $_SESSION['release_new']))) . '">1</span>';
}
// the side menu (owner 2026-10-25 order) comes from the registry (US-50): groups, pages, the switches (Stations only
// with several stations, Kiosk only with ACTIVATE_KIOSK)
$menu = views_for_menu();
// the menu shows the pages of the station's level (US-52, replaces the Simple / Advanced menu of 2026-10-20): each item
// and group carries its level as a class, the body the station's level; Show all pages (per browser) shows everything
function nav_link($view, $label, $current, $badge = '') {
  $e = view_entry($view);
  $cls = ($view === $current ? 'navitem active' : 'navitem') . ' navlvl-' . ($e ? $e['level'] : 'expert') . ($e && $e['also'] === 'field' ? ' navfield' : '');
  // Kiosk has no menu of its own (a wall screen): it opens in a new tab, the station stays in this one
  $tab = $view === 'Kiosk' ? ' target="_blank" rel="noopener"' : '';
  return '<a class="' . $cls . '" data-view="' . htmlspecialchars($view, ENT_QUOTES) . '" href="/?view=' . rawurlencode($view) . '"' . $tab . '>'
    . htmlspecialchars(t($label)) . $badge . '</a>';
}
?>
<form action="views.php" method="GET" id="views"></form>
<?php if ($current_view !== 'Kiosk') { ?>
<button type="button" class="sidenav-toggle" onclick="document.body.classList.toggle('sidenav-open')" title="<?php echo htmlspecialchars(t('str_menu')); ?>">&#9776;</button>
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
  /* the pages above the station's level (US-52); Show all pages shows them */
  body.lvl-essential:not(.show-all) .sidenav .navlvl-birder, body.lvl-essential:not(.show-all) .sidenav .navlvl-expert,
  body.lvl-birder:not(.show-all) .sidenav .navlvl-expert { display: none; }
  /* the field pages also below their level on a station that runs outdoors (US-55 / US-57) */
  body.ctx-field.ctx-field .sidenav details.navfield.navfield { display: block; }
  body.ctx-field.ctx-field .sidenav a.navfield.navfield { display: block; }
  .sidenav .navmode { display: flex; flex-direction: column; gap: 6px; margin: 14px 8px 10px; font-size: 12px; }
  .sidenav .navmode select { width: 100%; padding: 3px 6px; border: 1px solid var(--accent,#2b5e22); border-radius: 12px; background: transparent; color: inherit; font-size: 12px; }
  .sidenav .navmode label { display: flex; align-items: center; gap: 6px; cursor: pointer; }
  body.sidenav-collapsed .sidenav .navmode { display: none; }
  .sidenav .navuser { display: flex; flex-wrap: wrap; gap: 4px 6px; align-items: center; margin: 0 8px 12px; font-size: 12px; }
  .sidenav .navuser button { padding: 1px 8px; border: 1px solid var(--accent,#2b5e22); border-radius: 12px; background: transparent; color: inherit; cursor: pointer; font-size: 12px; }
  body.sidenav-collapsed .sidenav .navuser { display: none; }
</style>
<nav class="sidenav" id="sidenav">
<button type="button" class="sidenav-collapse" id="sidenav_collapse" title="<?php echo htmlspecialchars(t('str_collapse_expand_the_menu')); ?>"
  onclick="toggleSidenav()">&laquo;</button>
<?php
foreach ($menu as $entry) {
  if (!is_array($entry[1])) {
    echo nav_link($entry[1], $entry[0], $nav_view);
    continue;
  }
  // every group expanded by default; a click on a title folds or unfolds it
  // a group shows from the lowest level of its pages
  $open = ' open';
  $field_grp = count(array_filter(array_keys($entry[1]), function ($v) { $e = view_entry($v); return $e && $e['also'] === 'field'; })) > 0;
  echo '<details class="navgroup navlvl-' . view_group_level($entry[0]) . ($field_grp ? ' navfield' : '') . '"' . $open . '><summary>' . htmlspecialchars(t($entry[0]))
    . ($entry[0] === 'str_system_menu' ? $update_badge : '') . '</summary>';
  foreach ($entry[1] as $view => $label) {
    echo nav_link($view, $label, $nav_view, $view === 'Status' ? $update_badge : '');
  }
  echo '</details>';
}
?>
<div class="navmode" role="group" aria-label="<?php echo htmlspecialchars(t('str_level')); ?>">
  <select id="ui_level" onchange="setUiLevel(this.value)" title="<?php echo htmlspecialchars(t('str_level_help')); ?>" aria-label="<?php echo htmlspecialchars(t('str_level')); ?>"><?php
  foreach (array('essential' => 'str_level_essential', 'birder' => 'str_level_birder', 'expert' => 'str_level_expert') as $lk => $ll)
    echo '<option value="' . $lk . '"' . (ui_level() === $lk ? ' selected' : '') . '>' . htmlspecialchars(t($ll)) . '</option>'; ?></select>
  <label title="<?php echo htmlspecialchars(t('str_show_all_pages_help')); ?>"><input type="checkbox" id="show_all" onchange="setShowAll(this.checked)"> <?php echo htmlspecialchars(t('str_show_all_pages')); ?></label>
</div>
<?php // who is using the station (US-53): the user and the level, Log in / Switch user / Log out; nothing on an open station
$who_role = current_role(); ?>
<div class="navuser" id="navuser">
<?php if (web_login_off()) { ?>
  <span title="<?php echo htmlspecialchars(t('str_open_station_warning')); ?>">&#128275; <?php echo htmlspecialchars(t('str_open_station')); ?></span>
<?php } elseif (login_user() !== null) { ?>
  <span>&#128100; <?php echo htmlspecialchars((string)login_user()['name']); ?></span>
  <button type="button" onclick="bsLogout(true)"><?php echo htmlspecialchars(t('str_switch_user')); ?></button>
  <button type="button" onclick="bsLogout(false)"><?php echo htmlspecialchars(t('str_log_out')); ?></button>
<?php } elseif ($who_role !== 'admin') { ?>
  <span>&#128100; <?php echo htmlspecialchars(t($who_role === 'operator' ? 'str_household_device' : 'str_viewer')); ?></span>
  <button type="button" onclick="bsLogin()"><?php echo htmlspecialchars(t('str_log_in')); ?></button>
<?php } ?>
</div>
<?php // interface language (US-122, owner 2026-10-20): a picklist with the flags under Simple / Advanced; drawn flags,
      // since Windows shows emoji flags as two letters
$ui_flags = array('en' => '<svg viewBox="0 0 30 20" width="24" height="16" aria-hidden="true"><rect width="30" height="20" fill="#b22234"/><g fill="#fff"><rect y="1.54" width="30" height="1.54"/><rect y="4.62" width="30" height="1.54"/><rect y="7.69" width="30" height="1.54"/><rect y="10.77" width="30" height="1.54"/><rect y="13.85" width="30" height="1.54"/><rect y="16.92" width="30" height="1.54"/></g><rect width="13" height="10.77" fill="#3c3b6e"/></svg>', 'pt_BR' => '<svg viewBox="0 0 30 20" width="24" height="16" aria-hidden="true"><rect width="30" height="20" fill="#009c3b"/><path d="M15 2.5 27 10 15 17.5 3 10z" fill="#ffdf00"/><circle cx="15" cy="10" r="4.6" fill="#002776"/></svg>', 'es' => '<svg viewBox="0 0 30 20" width="24" height="16" aria-hidden="true"><rect width="30" height="20" fill="#aa151b"/><rect y="5" width="30" height="10" fill="#f1bf00"/></svg>', 'zh_CN' => '<svg viewBox="0 0 30 20" width="24" height="16" aria-hidden="true"><rect width="30" height="20" fill="#de2910"/><path d="M5 2.5l1.1 3.4H9.7L6.8 8l1.1 3.4L5 9.3 2.1 11.4 3.2 8 .3 5.9h3.6z" fill="#ffde00"/><g fill="#ffde00"><circle cx="10" cy="2" r=".8"/><circle cx="12" cy="4" r=".8"/><circle cx="12" cy="7" r=".8"/><circle cx="10" cy="9" r=".8"/></g></svg>', 'ja' => '<svg viewBox="0 0 30 20" width="24" height="16" aria-hidden="true"><rect width="30" height="20" fill="#fff"/><circle cx="15" cy="10" r="6" fill="#bc002d"/></svg>', 'de' => '<svg viewBox="0 0 30 20" width="24" height="16" aria-hidden="true"><rect width="30" height="6.67" fill="#000"/><rect y="6.67" width="30" height="6.67" fill="#dd0000"/><rect y="13.33" width="30" height="6.67" fill="#ffce00"/></svg>', 'fr' => '<svg viewBox="0 0 30 20" width="24" height="16" aria-hidden="true"><rect width="10" height="20" fill="#002395"/><rect x="10" width="10" height="20" fill="#fff"/><rect x="20" width="10" height="20" fill="#ed2939"/></svg>', 'it' => '<svg viewBox="0 0 30 20" width="24" height="16" aria-hidden="true"><rect width="10" height="20" fill="#009246"/><rect x="10" width="10" height="20" fill="#fff"/><rect x="20" width="10" height="20" fill="#ce2b37"/></svg>', 'nl' => '<svg viewBox="0 0 30 20" width="24" height="16" aria-hidden="true"><rect width="30" height="6.67" fill="#ae1c28"/><rect y="6.67" width="30" height="6.67" fill="#fff"/><rect y="13.33" width="30" height="6.67" fill="#21468b"/></svg>', 'pl' => '<svg viewBox="0 0 30 20" width="24" height="16" aria-hidden="true"><rect width="30" height="10" fill="#fff"/><rect y="10" width="30" height="10" fill="#dc143c"/></svg>', 'ru' => '<svg viewBox="0 0 30 20" width="24" height="16" aria-hidden="true"><rect width="30" height="6.67" fill="#fff"/><rect y="6.67" width="30" height="6.67" fill="#0039a6"/><rect y="13.33" width="30" height="6.67" fill="#d52b1e"/></svg>'); ?>
<div class="navlang" id="navlang">
  <button type="button" class="navlang-cur" onclick="document.getElementById('navlang').classList.toggle('open')" title="<?php echo htmlspecialchars(t('str_interface_language')); ?>"><?php echo $ui_flags[ui_lang()]; ?> <span><?php echo htmlspecialchars(ui_languages()[ui_lang()]); ?></span> &#9662;</button>
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
  // the user selector (US-53): log in = the browser's login dialog, then back here; log out = wrong credentials sent on
  // purpose, so the browser forgets the saved ones; switch user = log out, then log in
  function bsLogin() { (window.top || window).location.href = 'scripts/login.php?login=1&back=' + encodeURIComponent((window.top || window).location.pathname + (window.top || window).location.search); }
  function bsLogout(again) {
    var x = new XMLHttpRequest();
    x.onloadend = function () { if (again) bsLogin(); else (window.top || window).location.reload(); };
    x.open('GET', 'scripts/login.php?logout=1', true, 'logout', 'logout-' + Date.now());
    x.send();
  }
  // Show all pages, remembered per browser (US-52): every page whatever the station's level
  function setShowAll(on) {
    document.body.classList.toggle('show-all', on);
    try { window.localStorage.setItem('show_all', on ? '1' : '0'); } catch (e) {}
  }
  (function () { var c = document.getElementById('show_all'); if (c) c.checked = document.body.classList.contains('show-all'); })();
  // the station's level (UI_LEVEL, a setting: asks for the login), then the page reloads with its menu
  // refused (no login, an error): the message and the level of the station back in the picklist (release run 5,
  // NEW-UX-R5-12: it kept the refused one); the station's level is the option the page was drawn with
  function setUiLevel(l) {
    var back = function () {
      var s = document.getElementById('ui_level');
      if (s) Array.prototype.forEach.call(s.options, function (o) { if (o.defaultSelected) s.value = o.value; });
    };
    var x = new XMLHttpRequest();
    x.onload = function () {
      if (this.responseText.trim() === 'OK') { (window.top || window).location.reload(); return; }
      back();
      bsNotice(this.status === 401 ? t('str_log_in_first') : (this.responseText.trim() || t('str_not_saved_reason', {reason: 'HTTP ' + this.status})));
    };
    x.onerror = function () { back(); bsNotice(t('str_not_changed_network_error')); };
    x.open('POST', 'scripts/ui_level.php?level=' + encodeURIComponent(l), true);
    x.send();
  }
  // the interface language for the whole station (UI_LANG), then the page reloads in it, header included (US-122)
  // refused: the message, the list closed and the station's language still the one marked (release run 5, NEW-UX-R5-12)
  function setUiLang(l) {
    var back = function () {
      var n = document.getElementById('navlang');
      if (!n) return;
      n.classList.remove('open');
      n.querySelectorAll('.navlang-list button').forEach(function (b) { b.classList.toggle('active', b.dataset.lang === window.BS_LANG); });
      var c = n.querySelector('.navlang-cur');
      if (c) c.focus();
    };
    var x = new XMLHttpRequest();
    x.onload = function () {
      if (this.responseText.trim() === 'OK') { (window.top || window).location.reload(); return; }
      back();
      bsNotice(this.status === 401 ? t('str_log_in_first') : (this.responseText.trim() || t('str_not_saved_reason', {reason: 'HTTP ' + this.status})));
    };
    x.onerror = function () { back(); bsNotice(t('str_not_changed_network_error')); };
    x.open('POST', 'scripts/ui_lang.php?lang=' + encodeURIComponent(l), true);
    x.send();
  }
  document.addEventListener('click', function (e) { var n = document.getElementById('navlang'); if (n && !n.contains(e.target)) n.classList.remove('open'); });
  // a normal click loads the page in this frame (the header, live audio etc. stay); middle click, Ctrl/Cmd
  // click and "open in new tab" use the real link
  document.querySelectorAll('#sidenav a.navitem').forEach(function (a) {
    a.addEventListener('click', function (e) {
      if (e.button !== 0 || e.ctrlKey || e.metaKey || e.shiftKey || e.altKey || a.target === '_blank') return;
      e.preventDefault();
      var v = document.getElementsByClassName('views')[0];
      if (v) v.style.opacity = '0.5';
      window.location.href = 'views.php?view=' + encodeURIComponent(a.dataset.view);
    });
  });
</script>
<?php } ?>
<script type="text/javascript" src="static/plupload.full.min.js"></script>
<script>
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
  elem.innerHTML = t('str_copied');
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
// First-run setup wizard (US-51c): an installation nobody answered opens on the wizard
if(file_exists(BS_FIRSTRUN) && (!isset($_GET['view']) || in_array($_GET['view'], array("Latest", "Now"), true))){
  $_GET['view'] = "First Run";
}
// every page opens with its title, the name in the menu (owner 2026-10-25); a few pages get a title and subtitle of
// their own; the kiosk and a species page (its species is the title) have none
$page_titles = array('Model comparison' => array('str_model_comparison', 'str_official_versus_shadow'));
$pv = ($_GET['view'] ?? '') === 'First Run' ? 'First Run' : $nav_view;
$pt = $page_titles[$pv] ?? null;
// First Run is outside the menu (owner 2026-11-04: All settings starts it): its title from the registry
if ($pt === null && $pv === 'First Run') $pt = array(view_entry('First Run')['label']);
if ($pt === null && !in_array($pv, array('Kiosk'), true) && !($pv === 'Species' && trim($_GET['sci'] ?? '') !== '')) {
  foreach ($menu as $e) {
    if (is_array($e[1]) ? isset($e[1][$pv]) : $e[1] === $pv) { $pt = array(is_array($e[1]) ? $e[1][$pv] : $e[0]); break; }
  }
}
if ($pt) {
  echo '<div class="pagehead" style="padding:0 12px;margin:4px 0 8px"><h2 style="margin:0">' . htmlspecialchars(t($pt[0])) . '</h2>'
    . (isset($pt[1]) ? '<div class="small">' . htmlspecialchars(t($pt[1])) . '</div>' : '') . '</div>';
}
if (!in_array($pv, array('First Run', 'Kiosk'), true)) include('scripts/location_moved.php');
// no password set yet with the login on (bug #60): said on every page, the factory password changes nothing but the password
if (web_password_unset() && !in_array($pv, array('First Run', 'Kiosk'), true)) {
  echo '<div class="small" style="margin:0 12px 8px;padding:6px 10px;border-radius:8px;background:#fff3cd;color:#000">&#128274; ' . htmlspecialchars(t('str_factory_password_only_sets_help')) . ' <a href="/views.php?view=Access">' . htmlspecialchars(menu_path('Access')) . '</a>' . '</div>';
}
// offline (US-57): the field service found no internet for a while; said once under the title, nothing blocks
$fs = json_decode((string)@file_get_contents('/run/birdsuite/field_state.json'), true);
if (is_array($fs) && array_key_exists('online', $fs) && !$fs['online'] && time() - intval($fs['updated'] ?? 0) < 900 && $pv !== 'Kiosk') {
  echo '<div class="small" style="margin:0 12px 8px;padding:6px 10px;border-radius:8px;background:#fff3cd;color:#000">&#128225; ' . htmlspecialchars(t('str_no_internet_banner_help')) . '</div>';
}
if(isset($_GET['view'])){
  if($_GET['view'] == "First Run"){include('scripts/setup_wizard.php');}
  if($_GET['view'] == "Hardware details"){echo "<iframe title='" . t('str_hardware_details') . "' src='phpsysinfo/index.php'></iframe>";}
  if($_GET['view'] == "Status"){include('scripts/status.php');}  // opens without login, its buttons ask (owner 2026-10-25)
  if($_GET['view'] == "Disk Space"){include('scripts/disk_space.php');}  // read only, opens without login (US-133)
  // the database: sizes, check, optimise, download; the Adminer editor behind it (owner 2026-10-21)
  if($_GET['view'] == "Database"){ensure_authenticated(); include('scripts/database.php');}
  if($_GET['view'] == "Now"){include('scripts/see.php');}
  if($_GET['view'] == "Sonogram"){include('spectrogram.php');}
  if($_GET['view'] == "Raw Recording"){include('scripts/raw_recording.php');}
  // the station's state for a phone in the field (US-57)
  if($_GET['view'] == "Field"){include('scripts/field.php');}
  // Species = the species list or, with sci=, one species
  if($_GET['view'] == "Species"){include(trim($_GET['sci'] ?? '') === '' ? 'scripts/species_tools.php' : 'scripts/species_page.php');}
  if($_GET['view'] == "Review"){include('scripts/curation.php');}
  // the owner's stations, switched from the station name in the header (US-117, owner 2026-10-20)
  if($_GET['view'] == "Stations"){include('scripts/stations.php');}
  // the places the station recorded at, with the current one (US-58, owner 2026-10-20)
  if($_GET['view'] == "Locations"){include('scripts/locations.php');}
  if($_GET['view'] == "External files"){include('scripts/external_files.php');}
  // field access: station WiFi, fleet phone hotspot, WiFi of the place, clock, Identify, QR label (US-120)
  if($_GET['view'] == "Network"){include('scripts/network.php');}
  // e-mail notifications through a provider's SMTP, set up in a few fields (owner 2026-10-20)
  if($_GET['view'] == "E-mail"){include('scripts/email_settings.php');}
  // guided Telegram setup: one bot, the Normal and Rare groups (owner 2026-10-20)
  if($_GET['view'] == "Telegram"){include('scripts/telegram_settings.php');}
  // Content = the notification sections of the settings form
  if($_GET['view'] == "What and when"){$config_section = 'notifications'; include('scripts/config.php');}
  // a location's records as a whole: remove, backup, move, restore (owner 2026-10-20)
  if($_GET['view'] == "Data"){$ld_mode = 'data'; include('scripts/location_data.php');}
  if($_GET['view'] == "Backup/Import"){$ld_mode = 'backup'; include('scripts/location_data.php');}
  if($_GET['view'] == "By Hour"){include('scripts/detections_dashboard.php');}
  if($_GET['view'] == "By Week"){include('scripts/seasonality.php');}
  if($_GET['view'] == "eBird"){include('scripts/ebird_page.php');}
  // official model against the shadow model, by species and record by record (owner 2026-10-10)
  if($_GET['view'] == "Model comparison"){include('scripts/models.php');}
  // every default beside the station's value (US-121)
  if($_GET['view'] == "All settings"){ensure_authenticated(); include('scripts/defaults.php');}
  if($_GET['view'] == "Themes"){include('scripts/appearance.php');}
  // a page that frames one of the station's services (log, terminal, files): with the service off, say so and where to
  // turn it on, instead of an empty frame (2026-11-03 destructive phase, LOG-02 / CON-02: the frame stayed blank on a 502)
  $svc_frame = function ($unit, $title, $src, $attrs = '') {
    exec('systemctl is-active --quiet ' . escapeshellarg($unit . '.service'), $o, $rc);
    if ($rc !== 0) {
      echo '<div class="centered" style="padding:24px">' . htmlspecialchars(t('str_the_service_of_this_page_is_off', array('name' => $title))) . '<br>'
        . t('str_turn_it_on_in') . ' <a href="views.php?view=Status">' . htmlspecialchars(menu_path('Status')) . '</a>.</div>';
      return;
    }
    echo '<iframe title="' . htmlspecialchars($title, ENT_QUOTES) . '"' . $attrs . ' src="' . $src . '"></iframe>';
  };
  if($_GET['view'] == "Analysis log"){
    // the log levels: saved as soon as one is chosen, the service restarts
    $lv = function ($key, $label) use ($config) {
      $cur = $config[$key] ?? 'error';
      $o = '';
      foreach (array('error' => t('str_errors_only'), 'warning' => t('str_warning'), 'info' => t('str_info'), 'debug' => t('str_debug')) as $v => $l) $o .= '<option value="' . $v . '"' . ($cur === $v ? ' selected' : '') . '>' . htmlspecialchars($l) . '</option>';
      return '<label style="margin-right:14px">' . htmlspecialchars($label) . ' <select data-setting="' . $key . '">' . $o . '</select></label>';
    };
    echo '<div style="padding:4px 8px;font-size:13px"><b>' . htmlspecialchars(t('str_log_level')) . ':</b> ' . $lv('LogLevel_BirdnetRecordingService', t('str_recording')) . $lv('LogLevel_SpectrogramViewerService', t('str_spectrogram')) . $lv('LogLevel_LiveAudioStreamService', t('str_live_audio'))
      . '<small style="opacity:.75">' . htmlspecialchars(t('str_errors_only_is_recommended_not_help')) . '</small></div>';
    echo '<body style="scroll:no;overflow-x:hidden;">';
    $svc_frame(BS_SVC_LOG, t('str_detection_log'), 'log', ' style="width:calc( 100% + 1em);"');
    echo '</body>';
  }
  if($_GET['view'] == "Latest"){include('overview.php');}
  if($_GET['view'] == "Kiosk"){
    if (kiosk_active()) { $kiosk = true; include('todays_detections.php'); }
    else echo '<div class="centered" style="padding:24px">' . htmlspecialchars(t('str_the_kiosk_is_off_turn_it_on_in_help')) . '</div>';
  }
  // one detection (?filename=, a shared link or a notification)
  if($_GET['view'] == "Detection"){include('play.php');}
  // one settings form, three menu entries: Sharing, Model, Notifications › What and when
  if($_GET['view'] == "Sharing"){$config_section = 'basic'; include('scripts/config.php');}
  if($_GET['view'] == "Model"){$config_section = 'model'; include('scripts/config.php');}
  if($_GET['view'] == "Microphone"){$adv_page = 'audio'; include('scripts/advanced.php');}
  if($_GET['view'] == "Access"){$adv_page = 'access'; include('scripts/advanced.php');}
  // the species lists open without login, a change posts here and asks (owner 2026-10-25)
  $list_files = array('Allowed species' => 'include', 'Never accept' => 'exclude', 'Always accept' => 'whitelist', 'Night-only species' => 'nocturnal');
  if (isset($list_files[$_GET['view']])) {
    $species_list = $list_files[$_GET['view']];
    if(isset($_POST['species_lines']) && (isset($_POST['add']) or isset($_POST['del']))){
        update_species_list(BS_LISTS . '/' . $species_list . '_species_list.txt', explode("\n", $_POST['species_lines']), isset($_POST['add']));
        // a reload of this page must not send the form again
        echo '<script>if (history.replaceState) history.replaceState(null, "", location.href);</script>';
    }
    include('./scripts/species_list.php');
  }
  if($_GET['view'] == "Adminer"){
    echo "<iframe src='scripts/adminer.php'></iframe>";
  }
  if($_GET['view'] == "Terminal"){
    ensure_authenticated(t('str_you_cannot_access_the_web_term'));
    $svc_frame(BS_SVC_TERMINAL, t('str_console_login'), 'terminal');
  }
  // Midnight Commander after a login with the password (owner 2026-10-24: a real file manager, no keys)
  if($_GET['view'] == "Files"){
    ensure_authenticated(t('str_you_cannot_access_the_web_term'));
    $svc_frame(BS_SVC_FILES, t('str_browse_files'), 'files');
  }
} elseif(isset($_GET['submit'])) {
  ensure_authenticated();
  $allowedCommands = array('sudo -n ' . BS_SVC_HELPER . ' stop ' . BS_SVC_LIVESTREAM . '.service && sudo -n ' . BS_SVC_HELPER . ' stop ' . BS_SVC_ICECAST . '.service',
                     'sudo -n ' . BS_SVC_HELPER . ' restart ' . BS_SVC_LIVESTREAM . '.service && sudo -n ' . BS_SVC_HELPER . ' restart ' . BS_SVC_ICECAST . '.service',
                     'sudo -n ' . BS_SVC_HELPER . ' disable-now ' . BS_SVC_LIVESTREAM . '.service && sudo -n ' . BS_SVC_HELPER . ' disable ' . BS_SVC_ICECAST . ' && sudo -n ' . BS_SVC_HELPER . ' stop ' . BS_SVC_ICECAST . '.service',
                     'sudo -n ' . BS_SVC_HELPER . ' enable ' . BS_SVC_ICECAST . ' && sudo -n ' . BS_SVC_HELPER . ' start ' . BS_SVC_ICECAST . '.service && sudo -n ' . BS_SVC_HELPER . ' enable-now ' . BS_SVC_LIVESTREAM . '.service',
                     'sudo -n ' . BS_SVC_HELPER . ' stop ' . BS_SVC_TERMINAL . '.service',
                     'sudo -n ' . BS_SVC_HELPER . ' restart ' . BS_SVC_TERMINAL . '.service',
                     'sudo -n ' . BS_SVC_HELPER . ' disable-now ' . BS_SVC_TERMINAL . '.service',
                     'sudo -n ' . BS_SVC_HELPER . ' enable-now ' . BS_SVC_TERMINAL . '.service',
                     'sudo -n ' . BS_SVC_HELPER . ' stop ' . BS_SVC_LOG . '.service',
                     'sudo -n ' . BS_SVC_HELPER . ' restart ' . BS_SVC_LOG . '.service',
                     'sudo -n ' . BS_SVC_HELPER . ' disable-now ' . BS_SVC_LOG . '.service',
                     'sudo -n ' . BS_SVC_HELPER . ' enable-now ' . BS_SVC_LOG . '.service',
                     'sudo -n ' . BS_SVC_HELPER . ' stop ' . BS_SVC_ANALYSIS . '.service',
                     'sudo -n ' . BS_SVC_HELPER . ' restart ' . BS_SVC_ANALYSIS . '.service',
                     'sudo -n ' . BS_SVC_HELPER . ' disable-now ' . BS_SVC_ANALYSIS . '.service',
                     'sudo -n ' . BS_SVC_HELPER . ' enable-now ' . BS_SVC_ANALYSIS . '.service',
                     'sudo -n ' . BS_SVC_HELPER . ' stop ' . BS_SVC_RECORDING . '.service',
                     'sudo -n ' . BS_SVC_HELPER . ' restart ' . BS_SVC_RECORDING . '.service',
                     'sudo -n ' . BS_SVC_HELPER . ' disable-now ' . BS_SVC_RECORDING . '.service',
                     'sudo -n ' . BS_SVC_HELPER . ' enable-now ' . BS_SVC_RECORDING . '.service',
                     'sudo -n ' . BS_SVC_HELPER . ' stop ' . BS_SVC_SPECTROGRAM . '.service',
                     'sudo -n ' . BS_SVC_HELPER . ' restart ' . BS_SVC_SPECTROGRAM . '.service',
                     'sudo -n ' . BS_SVC_HELPER . ' disable-now ' . BS_SVC_SPECTROGRAM . '.service',
                     'sudo -n ' . BS_SVC_HELPER . ' enable-now ' . BS_SVC_SPECTROGRAM . '.service',
                     'echo on > ' . BS_RAMDISK_CHOICE . ' && sudo -n ' . BS_SVC_HELPER . ' enable '.get_service_mount_name().' && sudo -n ' . BS_ROOT_HELPER . ' reboot',
                     'echo off > ' . BS_RAMDISK_CHOICE . ' && sudo -n ' . BS_SVC_HELPER . ' disable '.get_service_mount_name().' && sudo -n ' . BS_ROOT_HELPER . ' reboot',
                     'stop_core_services.sh',
                     'restart_services.sh',
                     'sudo -n ' . BS_ROOT_HELPER . ' reboot',
                     'sudo -n ' . BS_ROOT_HELPER . ' update',
                     'sudo -n ' . BS_ROOT_HELPER . ' shutdown',
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

          $command .= " & sleep 3;sudo -n " . BS_SVC_HELPER . " status " . $service_names;
        }
        if($initcommand == "sudo -n " . BS_ROOT_HELPER . " update") {
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
          $results = t('str_this_command_has_no_output');
        }
        echo "<table style='min-width:70%;'><tr class='relative'><th>".t('str_output_of_command')."`".$initcommand."`<button class='copyimage' style='right:40px' onclick='copyOutput(this);'>".t('str_copy')."</button></th></tr><tr><td style='padding-left: 0px;padding-right: 0px;padding-bottom: 0px;padding-top: 0px;'><pre class='bash' style='text-align:left;margin:0px'>$results</pre></td></tr></table>"; 
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
</script>
</div>
</body>
