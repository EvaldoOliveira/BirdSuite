<?php

define('__ROOT__', dirname(dirname(__FILE__)));
// the station's own files (owner 2026-10-22): settings, lists, notifications, locations and the database, all under
// ~/BirdNET-Pi/config (scripts/paths.sh, utils/helpers.py name the same places)
define('BS_CONFIG', __ROOT__ . '/config');
define('BS_CONF', BS_CONFIG . '/BirdSuite.conf');  // the station's settings
define('BS_DEFAULTS', BS_CONFIG . '/factory_defaults.conf');  // the factory defaults (in git)
define('BS_LISTS', BS_CONFIG . '/species_lists');
// the default radius of a location (owner 2026-10-25: 1 km, was 5: with the device's GPS the position is metres off)
define('LOCATION_RADIUS_KM', 1);
define('BS_CUSTOM_LISTS', BS_LISTS . '/custom');
define('BS_NOTIFY', BS_CONFIG . '/notifications');
define('BS_DB', BS_CONFIG . '/data/birds.db');
// the running state (location jobs, offline notification queue, microphone markers, logs): ~/BirdNET-Pi/state, not
// settings, so outside config/ and the backup (owner 2026-10-22)
define('BS_STATE', __ROOT__ . '/state');

// the session cookie is not readable by scripts and not sent by other sites' requests (2026-10-23 test run); the same
// options go with every session_start() of the pages
define('BS_SESSION_OPTIONS', array('cookie_httponly' => true, 'cookie_samesite' => 'Lax'));
if (session_status() !== PHP_SESSION_ACTIVE)
  session_start(BS_SESSION_OPTIONS);

// ---- Interface language (US-122, owner 2026-10-20) ----
// English is written in the code and is the key: t('English text') answers the translation of the language chosen in
// UI_LANG (empty = follow DATABASE_LANG when it is Portuguese or Spanish), or the English text itself when the
// catalogue lacks it. Catalogues: lang/<code>/*.json (one file per page group, merged). {name} placeholders are filled
// from $vars, so word order can change between languages.
function ui_languages() {
  return array('en' => 'English', 'pt_BR' => 'Português', 'es' => 'Español', 'zh_CN' => '简体中文', 'ja' => '日本語',
    'de' => 'Deutsch', 'fr' => 'Français', 'it' => 'Italiano', 'nl' => 'Nederlands',
    'pl' => 'Polski', 'ru' => 'Русский');
}

// the station's number format follows the BirdSuite language (owner 2026-10-23): UI_LANG, or DATABASE_LANG when empty;
// LC_NUMERIC only (birdsuite-root numeric), the system messages keep their language. Silent on stations without it.
// the Custom Image of Settings › Advanced: an image file (png, jpg, gif, webp) inside the station user's home, with a
// plain path (2026-10-23 NEW-SEC-05: any readable file could be shown to anyone on the network)
function custom_image_ok($path) {
  $home = get_home();
  if (!preg_match('#^/[A-Za-z0-9._/-]+\.(png|jpe?g|gif|webp)$#i', (string)$path)) return false;
  $real = realpath($path);
  return $real !== false && strpos($real, rtrim($home, '/') . '/') === 0 && is_file($real) && @getimagesize($real) !== false;
}
function station_numeric_sync($ui, $db) {
  $l = $ui !== '' ? $ui : $db;
  if (!isset(ui_languages()[$l])) $l = $l === 'pt' ? 'pt_BR' : (isset(ui_languages()[substr($l, 0, 2)]) ? substr($l, 0, 2) : 'en');
  @exec('sudo -n /usr/local/sbin/birdsuite-root numeric ' . escapeshellarg($l) . ' > /dev/null 2>&1 &');
}
function ui_lang() {
  static $l = null;
  if ($l === null) {
    $c = get_config();
    $l = trim((string)($c['UI_LANG'] ?? ''), " \"'");
    if ($l === '') {
      $d = (string)($c['DATABASE_LANG'] ?? 'en');
      // the species-name language picks the interface when there is one for it (pt / pt_PT -> pt_BR, zh_TW -> zh_CN)
      $p = substr($d, 0, 2);
      $l = $p === 'pt' ? 'pt_BR' : ($p === 'zh' ? 'zh_CN' : $p);
    }
    if (!isset(ui_languages()[$l])) $l = 'en';
  }
  return $l;
}
function ui_catalog() {
  static $cat = null;
  if ($cat === null) {
    $cat = array();
    if (ui_lang() !== 'en') {
      foreach (glob(__ROOT__ . '/lang/' . ui_lang() . '/*.json') ?: array() as $f) {
        $j = json_decode((string)file_get_contents($f), true);
        if (is_array($j)) $cat = array_merge($cat, $j);
      }
    }
  }
  return $cat;
}
function t($s, $vars = array()) {
  $c = ui_catalog();
  $r = (isset($c[$s]) && $c[$s] !== '') ? $c[$s] : $s;
  foreach ($vars as $k => $v) $r = str_replace('{' . $k . '}', (string)$v, $r);
  return $r;
}
// the same for the page's JavaScript: window.t('English text', {name: value}); printed in the head of every page
// numbers in the format of the station's own locale (owner 2026-10-23: the Pi's locale, not the interface language;
// 131.105 and 0,2 with pt_BR.UTF-8, 131,105 and 0.2 with en_GB.UTF-8); coordinates keep the point (the usual way to
// write them everywhere). The locale is read from /etc/default/locale (LC_NUMERIC, then LANG), else the environment.
function station_locale() {
  static $loc = null;
  if ($loc !== null) return $loc;
  $vals = array();
  foreach (@file('/etc/default/locale', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: array() as $l) {
    if (preg_match('/^\s*(LC_ALL|LC_NUMERIC|LANG)\s*=\s*"?([^"\s]*)"?/', $l, $m)) $vals[$m[1]] = $m[2];
  }
  foreach (array('LC_ALL', 'LC_NUMERIC', 'LANG') as $k) {
    $v = $vals[$k] ?? (getenv($k) ?: '');
    if ($v !== '' && $v !== 'C' && $v !== 'POSIX' && stripos($v, 'C.') !== 0) return $loc = $v;
  }
  return $loc = 'en_GB.UTF-8';
}
// decimal and thousands separators of that locale: the system's own table when the locale is installed (read, then the
// C locale put back so no sprintf / SQL value of this request changes), else the usual ones per language
function num_separators() {
  static $sep = null;
  if ($sep !== null) return $sep;
  $loc = station_locale();
  $old = setlocale(LC_NUMERIC, 0);
  if (@setlocale(LC_NUMERIC, $loc, preg_replace('/\.utf-?8$/i', '.UTF-8', $loc))) {
    $lc = localeconv();
    setlocale(LC_NUMERIC, $old ?: 'C');
    if (($lc['decimal_point'] ?? '') !== '') return $sep = array($lc['decimal_point'], (string)($lc['thousands_sep'] ?? ''));
  }
  setlocale(LC_NUMERIC, $old ?: 'C');
  $lang = strtolower(substr($loc, 0, 2));
  $map = array('en' => array('.', ','), 'ja' => array('.', ','), 'zh' => array('.', ','), 'ko' => array('.', ','), 'he' => array('.', ','),
               'fr' => array(',', "\u{202F}"), 'pl' => array(',', "\u{00A0}"), 'ru' => array(',', "\u{00A0}"), 'cs' => array(',', "\u{00A0}"),
               'sv' => array(',', "\u{00A0}"), 'fi' => array(',', "\u{00A0}"), 'nb' => array(',', "\u{00A0}"), 'uk' => array(',', "\u{00A0}"));
  if (preg_match('/^(en_DK|da|de_CH)/i', $loc)) return $sep = (stripos($loc, 'de_CH') === 0 ? array('.', "\u{2019}") : array(',', '.'));
  return $sep = $map[$lang] ?? array(',', '.'); // pt, es, de, it, nl, da and most others
}
function fmt_num($n, $dec = 0) {
  $sep = num_separators();
  return number_format((float)$n, intval($dec), $sep[0], $sep[1]);
}
// a number as a setting writes it ("0.7", "0.03", "15") shown in the interface language's format with the same decimals
// (display only: fields the server reads keep the point); anything else is returned as it is
function fmt_setting($v) {
  $s = trim((string)$v);
  if ($s === '' || !is_numeric($s) || stripos($s, 'e') !== false) return $s;
  $dot = strpos($s, '.');
  return fmt_num((float)$s, $dot === false ? 0 : strlen($s) - $dot - 1);
}
// The one pager of every list (owner 2026-10-21: tables, paging and names the same everywhere): one line under the
// table, "Last [N] <things>" on the left (the picklist calls $count_js with the new N), newer / range / older in the
// middle (the buttons call $page_js with the new offset). $label = the text with {select}, e.g. t('Last {select} Detections').
// The Filter icon of the pages (owner 2026-10-21): it opens / folds the filters and the search of its bar ("fopen")
function filter_toggle() {
  return '<span class="dashfilter" role="button" tabindex="0" title="' . htmlspecialchars(t('Filter and search: show / hide'), ENT_QUOTES) . '"'
    . ' onclick="this.closest(\'.sortbar\').classList.toggle(\'fopen\')" onkeydown="if (event.key === \'Enter\' || event.key === \' \') { event.preventDefault(); this.click(); }">'
    . '<svg viewBox="0 0 24 24" width="18" height="18" aria-label="' . htmlspecialchars(t('Filter'), ENT_QUOTES) . '" role="img"><path fill="currentColor" d="M3 4h18l-7 8.5V19l-4 2v-8.5z"/></svg></span>';
}
// The Sort icon of every page with sort buttons (owner 2026-10-21): an icon like the Filter one; it opens / folds the
// sort buttons of the bar it sits in (the bar carries class "sortbar"; static/ui-standard.js bsSortToggle)
function sort_toggle() {
  return '<span class="sorticon" role="button" tabindex="0" title="' . htmlspecialchars(t('Sort'), ENT_QUOTES) . '" onclick="bsSortToggle(this)"'
    . ' onkeydown="if (event.key === \'Enter\' || event.key === \' \') { event.preventDefault(); bsSortToggle(this); }">'
    . '<svg viewBox="0 0 24 24" width="18" height="18" aria-label="' . htmlspecialchars(t('Sort'), ENT_QUOTES) . '" role="img"><path fill="currentColor" d="M7 3l4 5H8v8H6V8H3zm10 18l-4-5h3V8h2v8h3z"/></svg></span>';
}
// CSV of a page's table (owner 2026-10-22, By Hour and By Week): a download icon beside Filter / Sort, and the file itself
function csv_icon($href) {
  return '<a class="csvicon" href="' . htmlspecialchars($href, ENT_QUOTES) . '" download title="' . htmlspecialchars(t('Download the table as CSV'), ENT_QUOTES) . '">'
    . '<svg viewBox="0 0 24 24" width="18" height="18" aria-label="CSV" role="img"><path fill="currentColor" d="M11 3h2v9.2l3.3-3.3 1.4 1.4L12 16l-5.7-5.7 1.4-1.4 3.3 3.3zM4 18h16v2H4z"/></svg></a>';
}
function csv_out($name, $head, $rows) {
  header('Content-Type: text/csv; charset=utf-8');
  header('Content-Disposition: attachment; filename="' . $name . '"');
  $f = fopen('php://output', 'w');
  fwrite($f, "\xEF\xBB\xBF");  // UTF-8 BOM: Excel shows the accents right
  fputcsv($f, $head);
  foreach ($rows as $r) fputcsv($f, $r);
  fclose($f);
  die();
}
function list_pager($label, $offset, $shown, $page, $more, $count_js, $page_js, $count_id = '') {
  $opts = '';
  foreach (array(10, 25, 50, 100) as $c) $opts .= '<option' . ($c == $page ? ' selected' : '') . '>' . $c . '</option>';
  $sel = '<select' . ($count_id !== '' ? ' id="' . $count_id . '"' : '') . ' class="nowcount" onchange="' . $count_js . '(this.value)" title="' . htmlspecialchars(t('How many the list shows'), ENT_QUOTES) . '">' . $opts . '</select>';
  return '<div class="nowpager"><span class="nowpcount">' . str_replace('{select}', $sel, $label) . '</span><span class="nowpnav">'
    . ($offset > 0 ? '<button type="button" class="openbtn" onclick="' . $page_js . '(' . max(0, $offset - $page) . ')">&#9664; ' . t('Newer {n}', array('n' => $page)) . '</button>' : '')
    . '<span class="nowprange">' . ($shown ? ($offset + 1) . '-' . ($offset + $shown) : '') . '</span>'
    . ($more ? '<button type="button" class="openbtn" onclick="' . $page_js . '(' . ($offset + $page) . ')">' . t('Older {n}', array('n' => $page)) . ' &#9654;</button>' : '')
    . '</span><span></span></div>';
}
function ui_js() {
  // window.fmtNum(n, decimals): numbers shown to the user in the station locale's format, the same as fmt_num()
  return '<script>window.BS_NUM = ' . json_encode(array('dec' => num_separators()[0], 'thou' => num_separators()[1]), JSON_UNESCAPED_UNICODE) . ';'
    . ' window.fmtNum = function (n, d) { n = Number(n); if (!isFinite(n)) return String(n); var p = n.toFixed(d || 0).split("."), s = window.BS_NUM;'
    . ' return (p[0].charAt(0) === "-" ? "-" : "") + p[0].replace("-", "").replace(/\\B(?=(\\d{3})+(?!\\d))/g, s.thou) + (p[1] ? s.dec + p[1] : ""); };'
    . ' window.BS_LANG = ' . json_encode(ui_lang()) . '; window.BS_I18N = ' . json_encode((object)ui_catalog(), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG)
    . '; window.t = function (s, v) { var r = (window.BS_I18N[s] || s); if (v) for (var k in v) r = r.split("{" + k + "}").join(v[k]); return r; };'
    // the factory values (factory_defaults.conf, in git, nothing secret): a field that differs is yellow (owner 2026-10-24,
    // static/ui-standard.js)
    . ' window.BS_DEFAULTS = ' . json_encode((object)birdsuite_defaults(), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) . ';</script>'
    // one sentence per line on every page (owner 2026-10-21): static/sentence-lines.js breaks the line after each sentence
    . '<script src="/static/sentence-lines.js?v=' . @filemtime(__ROOT__ . '/homepage/static/sentence-lines.js') . '"></script>'
    // the buttons of one table column with the same width (owner 2026-10-21)
    . '<script src="/static/ui-standard.js?v=' . @filemtime(__ROOT__ . '/homepage/static/ui-standard.js') . '"></script>';
}

function ensure_db_ok($sql_stmt) {
  if ($sql_stmt == False) {
    echo t('Database is busy');
    header("refresh:1;");
    exit;
  }
}

// the station's timezone, read on every request from the /etc/localtime link (2026-10-20): kept in the session it went
// stale for two hours after a location in another timezone was made current, and PHP's "today" then disagreed with
// SQLite's DATE('now', 'localtime')
function set_timezone() {
  $tz = preg_replace('~^.*/zoneinfo/~', '', (string)@readlink('/etc/localtime'));
  if ($tz === '' || !in_array($tz, timezone_identifiers_list(), true)) $tz = trim((string)shell_exec('timedatectl show --value --property=Timezone'));
  if ($tz !== '') date_default_timezone_set($tz);
  $_SESSION['my_timezone'] = $tz;
}

// US-121 (2026-10-20): every default of the program, factory_defaults.conf in the base folder (read-only, in git)
function birdsuite_defaults() {
  static $d = null;
  if ($d === null) {
    $f = BS_DEFAULTS;
    $d = is_file($f) ? (parse_ini_string(preg_replace("~^#+.*$~m", "", file_get_contents($f))) ?: array()) : array();
  }
  return $d;
}

// the station's settings: the defaults, with BirdSuite.conf over them (a key it never set takes its default)
function get_config($force_reload = false) {
  // the content, not the mtime (2026-10-23 run 3: two saves within one second kept the old values in the session)
  $mtime = @md5_file(BS_CONF) . '/' . @filemtime(BS_DEFAULTS);
  if (isset($_SESSION['my_config_version']) && $_SESSION['my_config_version'] !== $mtime) {
    $force_reload = true;
  }
  if (!isset($_SESSION['my_config']) || $force_reload) {
    $source = preg_replace("~^#+.*$~m", "", file_get_contents(BS_CONF));
    $my_config = parse_ini_string($source);
    if ($my_config) {
      // the words off/on/yes/no/true/false/none/null stay words (owner 2026-10-31: FIELD_WIFI_MODE=off read as "", the
      // Network page selected nothing and showed "Always on", so choosing it saved nothing and the station stayed off)
      $raw = parse_ini_string($source, false, INI_SCANNER_RAW);
      foreach ((array)$raw as $k => $v) {
        if (isset($my_config[$k]) && preg_match('/^(off|on|yes|no|true|false|none|null)$/i', (string)$v)) {
          $my_config[$k] = $v;
        }
      }
      $_SESSION['my_config'] = array_merge(birdsuite_defaults(), $my_config);
    } else {
      syslog(LOG_ERR, "Cannot parse config");
    }
    $_SESSION['my_config_version'] = $mtime;
  }
  return $_SESSION['my_config'];
}

function get_user() {
  $config = get_config();
  $user = $config['BIRDNET_USER'];
  return $user;
}

function get_home() {
  $home = '/home/' . get_user();
  return $home;
}

// The period of a page (owner 2026-10-22: Validation, then By Hour the same way): $default until another one is picked;
// the choice is kept in a cookie per page (all, today, last7, last30 or from:to). $url: the period of the request
// (&period=all|last7|last30|last24, &from=&to=) wins over the cookie. Returns array(from, to, since); ('', '') = every date;
// since = 'YYYY-MM-DD HH:MM:SS' only for last24 (the last 24 hours, Validation, owner 2026-10-23), else ''.
function page_period($cookie, $url = false, $default = 'today') {
  $valid = function ($d) { return preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$d) ? $d : ''; };
  $mode = $default;  // until a period is picked (owner 2026-10-22: By Hour and Validation open on the last 7 days)
  if ($url && in_array($_GET['period'] ?? '', array('all', 'last7', 'last30', 'today', 'last24'), true)) $mode = $_GET['period'];
  elseif ($url && isset($_GET['from'])) $mode = 'range';
  elseif (preg_match('/^(all|today|last24|last7|last30|\d{4}-\d{2}-\d{2}:\d{4}-\d{2}-\d{2})$/', $_COOKIE[$cookie] ?? '', $m)) $mode = strpos($m[1], ':') ? 'range' : $m[1];
  if ($mode === 'all') return array('', '');
  // the last 7 / 30 days end today, whatever day it is
  if ($mode === 'last7') return array(date('Y-m-d', strtotime('-6 days')), date('Y-m-d'));
  if ($mode === 'last30') return array(date('Y-m-d', strtotime('-29 days')), date('Y-m-d'));
  if ($mode === 'today') return array(date('Y-m-d'), date('Y-m-d'));
  if ($mode === 'last24') return array(date('Y-m-d', time() - 86400), date('Y-m-d'), date('Y-m-d H:i:s', time() - 86400));
  list($f, $t) = ($url && isset($_GET['from'])) ? array($_GET['from'], $_GET['to'] ?? '') : explode(':', $_COOKIE[$cookie]);
  $f = $valid($f); $t = $valid($t) ?: $f;
  if ($f === '') return array('', '');
  return $t < $f ? array($t, $f) : array($f, $t);
}
// Validation's period (the Review button of the species page follows it too)
function validation_period($url = false) { return page_period('cur_period', $url, 'last7'); }

// The period control (owner 2026-10-22), the same on Validation and By Hour: ◀ [Today / Last 7 days / Last 30 days /
// Date range / All] ▶ — the arrows move by the period's length, ▶ stops at today; Date range shows the From / to fields
// (open while a range is in use); the choice is written to the page's cookie. $base: the page's address ('views.php?
// view=...'), $keep: query parameters kept by every link and by the range form (e.g. array('sci' => ...)).
// $since: null on pages with Today (By Hour); a string on Validation, which offers Last 24 h instead ('' = not chosen)
function period_picker($base, $from, $to, $first, $cookie, $keep = array(), $since = null) {
  $h = function ($x) { return htmlspecialchars((string)$x, ENT_QUOTES); };
  $q = ''; foreach ($keep as $k => $v) if ($v !== '') $q .= '&amp;' . rawurlencode($k) . '=' . rawurlencode($v);
  $u = function ($extra) use ($base, $q) { return $base . $extra . $q; };
  $today = date('Y-m-d'); $d7 = date('Y-m-d', strtotime('-6 days')); $d30 = date('Y-m-d', strtotime('-29 days'));
  $is24 = $since !== null && $since !== '';
  $is_today = !$is24 && $from === $today && $to === $today; $is7 = $from === $d7 && $to === $today; $is30 = $from === $d30 && $to === $today;
  // another period (a day reached with the arrows, a range) shows as its dates in the closed picklist only (a hidden
  // option, not in the open list); the From / to fields open only when Date range is picked or its form was sent
  // (owner 2026-10-22: a single day is no range)
  $is_other = $from !== '' && !$is24 && !$is_today && !$is7 && !$is30;
  $range_open = isset($_GET['pr']);
  $days = $from === '' ? 0 : (int)round((strtotime($to) - strtotime($from)) / 86400) + 1;
  $o = '<span class="periodpick">';
  if ($is24) {
    // before the last 24 hours: yesterday as a day
    $o .= '<a class="stdbtn pnav" href="' . $u('&amp;from=' . $from . '&amp;to=' . $from) . '" title="' . $h(t('The period before')) . '">&#9664;</a>';
  } elseif ($days) {
    $pf = date('Y-m-d', strtotime($from . " -$days days")); $pt = date('Y-m-d', strtotime($to . " -$days days"));
    $o .= '<a class="stdbtn pnav" href="' . $u('&amp;from=' . $pf . '&amp;to=' . $pt) . '" title="' . $h(t('The period before')) . '">&#9664;</a>';
  }
  $sel = function ($c) { return $c ? ' selected' : ''; };
  $o .= '<select class="dashperiod" aria-label="' . htmlspecialchars(t('Period'), ENT_QUOTES) . '" onchange="if (this.value === \'range\') this.parentNode.querySelector(\'.prange\').style.display = \'\'; else if (this.value) location.href = this.value">'
    . ($since !== null ? '<option value="' . $u('&amp;period=last24') . '"' . $sel($is24) . '>' . t('Last 24 h') . '</option>'
      : '<option value="' . $u('&amp;period=today') . '"' . $sel($is_today) . '>' . t('Today') . '</option>')
    . '<option value="' . $u('&amp;period=last7') . '"' . $sel($is7) . '>' . t('Last 7 days') . '</option>'
    . '<option value="' . $u('&amp;period=last30') . '"' . $sel($is30) . '>' . t('Last 30 days') . '</option>'
    // ascending, as every period picklist (owner 2026-10-22): Today, the last days, the range, then All
    . ($is_other ? '<option value="" selected hidden disabled>' . $h($from === $to ? t(date('D', strtotime($from))) . date(' d/m/Y', strtotime($from)) : date('d/m/Y', strtotime($from)) . ' - ' . date('d/m/Y', strtotime($to))) . '</option>' : '')
    . '<option value="range">' . t('Date range') . '</option>'
    . '<option value="' . $u('&amp;period=all') . '"' . $sel($from === '') . '>' . t('All') . '</option></select>';
  if ($is24) {
    $o .= '<span class="stdbtn pnav off">&#9654;</span>';
  } elseif ($days) {
    $nf = date('Y-m-d', strtotime($from . " +$days days")); $nt = min($today, date('Y-m-d', strtotime($to . " +$days days")));
    $o .= $nf <= $today ? '<a class="stdbtn pnav" href="' . $u('&amp;from=' . $nf . '&amp;to=' . $nt) . '" title="' . $h(t('The period after')) . '">&#9654;</a>'
      : '<span class="stdbtn pnav off">&#9654;</span>';
  }
  parse_str(html_entity_decode(parse_url($base, PHP_URL_QUERY) ?: ''), $bq);
  $hidden = '';
  foreach ($bq + $keep as $k => $v) if ($v !== '') $hidden .= '<input type="hidden" name="' . $h($k) . '" value="' . $h($v) . '">';
  $o .= '<form class="yrange prange" method="GET" action="views.php"' . ($range_open ? '' : ' style="display:none"') . '>' . $hidden . '<input type="hidden" name="pr" value="1">'
    . t('From') . ' <input type="date" name="from" value="' . $h($from ?: $first) . '" max="' . $today . '"> '
    . t('to') . ' <input type="date" name="to" value="' . $h($to ?: $today) . '" max="' . $today . '"> '
    . '<button type="submit">' . t('Filter') . '</button></form></span>';
  $val = $from === '' ? 'all' : ($is24 ? 'last24' : ($is_today ? 'today' : ($is7 ? 'last7' : ($is30 ? 'last30' : $from . ':' . $to))));
  $o .= '<script>try { document.cookie = ' . json_encode($cookie . '=' . $val) . ' + \';path=/;max-age=31536000;SameSite=Lax\'; } catch (e) {}</script>';
  return $o;
}

// The release this installation runs (2026-10-22): a fresh install is a shallow clone (newinstaller.sh --depth=1), where
// git describe finds no tag (Status said "BirdSuite unknown"); then the vX.Y.Z tag on HEAD, else the newest one not newer
// than HEAD. Returns array(tag or '', commits after it: a number, or null when the shallow history cannot count them).
function installed_release() {
  static $r = null;
  if ($r !== null) return $r;
  $git = station_prefix() . 'git -C ' . get_home() . '/BirdNET-Pi ';
  $tag = trim((string)shell_exec($git . "describe --tags --abbrev=0 --match 'v[0-9]*' 2>/dev/null"));
  if ($tag !== '') return $r = array($tag, intval(trim((string)shell_exec($git . 'rev-list --count ' . escapeshellarg($tag) . '..HEAD 2>/dev/null'))));
  $best = ''; $exact = false;
  $head = intval(trim((string)shell_exec($git . 'log -1 --format=%ct HEAD 2>/dev/null')));
  $at = explode("\n", (string)shell_exec($git . "tag --points-at HEAD --list 'v*' 2>/dev/null"));
  foreach (explode("\n", (string)shell_exec($git . "for-each-ref --format='%(refname:short) %(*committerdate:unix)%(committerdate:unix)' 'refs/tags/v*' 2>/dev/null")) as $l) {
    $f = explode(' ', trim($l));
    if (count($f) < 2 || !preg_match('~^v\d+\.\d+\.\d+$~', $f[0]) || intval($f[1]) > $head) continue;
    if ($best === '' || version_compare(substr($f[0], 1), substr($best, 1), '>')) { $best = $f[0]; $exact = in_array($f[0], array_map('trim', $at), true); }
  }
  return $r = array($best, $best === '' ? 0 : ($exact ? 0 : null));
}

// Species folders and clip names by the scientific name only (owner 2026-10-24: the common name depends on the language,
// so changing the language split a species in two). A clip is By_Date/<date>/<Sci_safe>/<Sci_safe>-<date>_<time>-...;
// one of the old layout By_Date/<date>/<Com_safe>/<Com_safe>-... until
// scripts/migrate_names.py renames it. The folder is told from the file name: "<Sci_safe>-" or "<Sci_safe>_" at its start.
// The same rule in scripts/utils/helpers.py (species_folder).
function name_safe($name) {
  return str_replace("'", '', str_replace(' ', '_', (string)$name));
}
function species_folder($sci, $com, $file) {
  $s = name_safe($sci);
  $f = (string)$file;
  return ($s !== '' && strncmp($f, $s . '-', strlen($s) + 1) === 0) ? $s : name_safe($com);
}
// the clip's name without its species ('-2026-10-01_06h00m01s-...'), as helpers.py clip_rest; '' when it has no date
function clip_rest($file) {
  return preg_match('/-(\d{1,3}-)?\d{4}-\d{2}-\d{2}[-_]/', (string)$file, $m, PREG_OFFSET_CAPTURE) ? substr((string)$file, $m[0][1]) : '';
}
// a detection's clip under By_Date (or Purge / Removed): <date>/<folder>/<file>; $r has Date, Sci_Name, Com_Name, File_Name
function clip_rel($r) {
  return $r['Date'] . '/' . species_folder($r['Sci_Name'] ?? '', $r['Com_Name'] ?? '', $r['File_Name']) . '/' . $r['File_Name'];
}
// the languages of the common names (model/l18n/labels_<code>.json): Model › Common name Language, the export in the old
// model (Backup/Restore); the names are translated with t()
function common_name_languages() {
  return array(
    "af" => "Afrikaans",
    "ar" => "Arabic",
    "ca" => "Catalan",
    "cs" => "Czech",
    "zh_CN" => "Chinese (simplified)",
    "zh_TW" => "Chinese (traditional)",
    "hr" => "Croatian",
    "da" => "Danish",
    "nl" => "Dutch",
    "en" => "English",
    "et" => "Estonian",
    "fi" => "Finnish",
    "fr" => "French",
    "de" => "German",
    "hu" => "Hungarian",
    "is" => "Icelandic",
    "id" => "Indonesia",
    "it" => "Italian",
    "ja" => "Japanese",
    "ko" => "Korean",
    "lv" => "Latvian",
    "lt" => "Lithuania",
    "no" => "Norwegian",
    "pl" => "Polish",
    "pt_BR" => "Portuguese Brazil (CBRO)",
    "pt_PT" => "Portuguese Portugal",
    "ro" => "Romanian",
    "ru" => "Russian",
    "sr" => "Serbian",
    "sk" => "Slovak",
    "sl" => "Slovenian",
    "es" => "Spanish",
    "sv" => "Swedish",
    "th" => "Thai",
    "tr" => "Turkish",
    "uk" => "Ukrainian",
    "vi" => "Vietnamese"
  );
}
// Clips of the old layout, or common names not of the Common name Language (owner 2026-10-24: a "Migrar" button when the
// station or an imported backup uses the old names): null when nothing is to do, else array('old' => clips,
// 'relabel' => species, 'running' => bool). config/data/names_migrated says it was checked (migrate_names.py writes
// it; a restore brings a database without it, a location import and a language change remove it), so the database is
// read only when that file is missing, and once per minute at most.
function names_migration_log() {
  return get_home() . '/BirdNET-Pi/state/names_migration.log';
}
function names_migration_running() {
  $log = names_migration_log();
  $txt = (string)@file_get_contents($log);
  return $txt !== '' && strpos($txt, '__BIRDSUITE_NAMES_EXIT__') === false && filemtime($log) > time() - 3600;
}
function names_migration_state() {
  $running = names_migration_running();
  if (!$running && file_exists(get_home() . '/BirdNET-Pi/config/data/names_migrated')) return null;
  if (!$running && isset($_SESSION['names_check']) && $_SESSION['names_check']['at'] > time() - 60) {
    $r = $_SESSION['names_check']['r'];
  } else {
    $home = get_home();
    $r = json_decode((string)shell_exec(station_prefix() . 'timeout 60 ' . escapeshellarg("$home/BirdNET-Pi/birdnet/bin/python3") . ' '
      . escapeshellarg("$home/BirdNET-Pi/scripts/migrate_names.py") . ' --check 2>/dev/null'), true);
    if (!is_array($r)) return $running ? array('old' => 0, 'relabel' => 0, 'running' => true) : null;
    $_SESSION['names_check'] = array('at' => time(), 'r' => $r);
  }
  if (!$running && empty($r['old']) && empty($r['relabel'])) return null;
  return array('old' => intval($r['old'] ?? 0), 'relabel' => intval($r['relabel'] ?? 0), 'running' => $running);
}
// starts scripts/migrate_names.py detached from the web request (as the update on Status), its output in the log
function names_migration_start() {
  if (names_migration_running()) return;
  $home = get_home();
  @mkdir(dirname(names_migration_log()), 0775, true);
  file_put_contents(names_migration_log(), '');
  unset($_SESSION['names_check']);
  shell_exec('setsid -f sh -c ' . escapeshellarg(station_prefix() . escapeshellarg("$home/BirdNET-Pi/birdnet/bin/python3") . ' '
    . escapeshellarg("$home/BirdNET-Pi/scripts/migrate_names.py") . '; echo "__BIRDSUITE_NAMES_EXIT__ $?"')
    . ' >> ' . escapeshellarg(names_migration_log()) . ' 2>&1 < /dev/null');
}
// the marker goes, so the next page looks again (a location import, a language change)
function names_migration_recheck() {
  shell_exec(station_prefix() . 'rm -f ' . escapeshellarg(get_home() . '/BirdNET-Pi/config/data/names_migrated'));
  unset($_SESSION['names_check']);
}
// The two file formats (owner 2026-10-24): BirdSuite (new), folders and files by the scientific name; BirdNET (old),
// by the common name, as BirdNET-Pi keeps them. Status shows a notice with a link when clips are still in the BirdNET
// format; Tools › Backup/Restore shows the format of each location, with Migrate under the table
function names_format_labels() {
  return array('new' => t('BirdSuite (new): scientific name'), 'old' => t('BirdNET (old): common name'));
}
function names_migration_what($st) {
  $what = array();
  if ($st['old'] > 0) $what[] = t('{n} clips are in the BirdNET (old) format', array('n' => number_format($st['old'], 0, '', '.')));
  if ($st['relabel'] > 0) $what[] = t('{n} species are not named in the Common name Language', array('n' => $st['relabel']));
  return $st['running'] ? t('The migration is running.') : implode('; ', $what) . '.';
}
function names_migration_notice() {
  $st = names_migration_state();
  if ($st === null) return;
  echo '<div class="box" style="border-left:4px solid #e0a000">' . htmlspecialchars(names_migration_what($st), ENT_QUOTES)
    . ' <a href="views.php?view=LocationData#ldformats">' . t('Migrate to BirdSuite (new) in Tools › Data') . '</a></div>';
}
// the progress screen of the migration (Tools › Backup/Restore: the button under the locations table calls nmStart)
function names_migration_overlay() {
  ?>
  <div id="nmov" style="display:none;position:fixed;inset:0;z-index:2000;background:rgba(0,0,0,.65);align-items:center;justify-content:center">
    <div style="background:var(--panel,#fff);color:var(--text,#000);border-radius:12px;padding:16px;width:min(760px,calc(100% - 24px));max-height:calc(100% - 24px);display:flex;flex-direction:column;gap:8px">
      <b style="font-size:16px"><?php echo t('Migrating the species names'); ?> <span id="nmtime" style="font-weight:normal">00:00</span></b>
      <div id="nmstate"><?php echo t('Starting the migration…'); ?></div>
      <pre id="nmlog" style="flex:1;min-height:200px;max-height:60vh;overflow:auto;background:#111;color:#ddd;border-radius:8px;padding:8px;font-size:12px;white-space:pre-wrap;margin:0"></pre>
      <div style="text-align:right"><button type="button" class="stdbtn" id="nmclose" style="display:none" onclick="(window.top || window).location.reload()"><?php echo t('Reload'); ?></button></div>
    </div>
  </div>
  <script>
  // the migration on a screen of its own (owner 2026-10-24, as the update): its progress, the page warns before leaving
  var nm = {from: 0, t0: 0, done: false};
  function nmStart(running) {
    if (!running && !confirm(t('Migrate the species names now? It can take a few minutes; the station keeps recording.'))) return;
    document.getElementById('nmov').style.display = 'flex';
    nm.t0 = Date.now();
    window.addEventListener('beforeunload', nmLeave);
    try { window.top.addEventListener('beforeunload', nmLeave); } catch (e) {}
    setInterval(function () {
      if (!nm.done) document.getElementById('nmtime').textContent = new Date(Date.now() - nm.t0).toISOString().substring(14, 19);
    }, 1000);
    if (running) return nmPoll();
    var x = new XMLHttpRequest();
    x.onload = x.onerror = function () { nmPoll(); };
    x.open('POST', 'scripts/migrate_names.php?names=start', true); x.send();
  }
  function nmLeave(e) { if (!nm.done) { e.preventDefault(); e.returnValue = ''; return ''; } }
  function nmPoll() {
    var x = new XMLHttpRequest();
    x.timeout = 10000;
    x.onload = function () {
      var r; try { r = JSON.parse(this.responseText); } catch (e) { return setTimeout(nmPoll, 3000); }
      document.getElementById('nmstate').textContent = t('Migrating, do not close this page…');
      if (r.text) {
        var pre = document.getElementById('nmlog');
        pre.textContent += r.text;
        pre.scrollTop = pre.scrollHeight;
      }
      nm.from = r.size;
      if (r.done) return nmDone(r.code);
      setTimeout(nmPoll, 1500);
    };
    x.onerror = x.ontimeout = function () { setTimeout(nmPoll, 3000); };
    x.open('GET', 'scripts/migrate_names.php?names=log&from=' + nm.from, true); x.send();
  }
  function nmDone(code) {
    nm.done = true;
    var ok = code === 0, st = document.getElementById('nmstate');
    st.textContent = ok ? t('Migration finished. Reloading…') : t('The migration failed (code {code}). See the messages above.', {code: code});
    st.style.color = ok ? '' : '#c62828';
    if (ok) setTimeout(function () { (window.top || window).location.reload(); }, 2500);
    else document.getElementById('nmclose').style.display = '';
  }
  </script>
  <?php
}

// The spectrogram of a clip (owner 2026-10-22): <clip>.png while the day runs, <clip>.jpg once spectro_archive.py
// stored it for the long term. $clip = the clip's web path under Extracted ('/By_Date/...' or 'By_Date/...').
function clip_spectrogram($clip) {
  $disk = get_home() . '/BirdSongs/Extracted/' . ltrim($clip, '/');
  return (!is_file($disk . '.png') && is_file($disk . '.jpg')) ? $clip . '.jpg' : $clip . '.png';
}

// The station's identity, its ID (owner 2026-10-20, 2026-10-27): the MAC of the Pi's own network port (cable), else of its
// own WiFi — never of a USB adapter plugged in — the same on every boot and unique per board, so cloned SD cards tell
// themselves apart; the name is only an alias. Own = on the board's bus, or the LAN chip a Pi 3 / 2 / B+ has on its
// internal USB hub (smsc95xx, lan78xx). Same rule in field_net.py mac(), install_firstrun.sh, utils/notifications.py.
// $plain = without colons, upper case.
function station_mac($plain = false) {
  static $mac = null;
  if ($mac === null) {
    $mac = '';
    $names = array_map('basename', glob('/sys/class/net/*') ?: array());
    sort($names);
    foreach (array(false, true) as $wireless) {
      foreach ($names as $i) {
        $d = "/sys/class/net/$i";
        if (!is_dir("$d/device") || is_dir("$d/wireless") !== $wireless) continue;
        $own = basename((string)@realpath("$d/device/subsystem")) !== 'usb' || in_array(basename((string)@realpath("$d/device/driver")), array('smsc95xx', 'lan78xx'), true);
        $m = trim((string)@file_get_contents("$d/address"));
        if ($own && preg_match('/^([0-9a-f]{2}:){5}[0-9a-f]{2}$/i', $m) && $m !== '00:00:00:00:00:00') { $mac = strtolower($m); break 2; }
      }
    }
  }
  return $plain ? strtoupper(str_replace(':', '', $mac)) : $mac;
}
// the default station name until one is chosen (Settings): BirdSuite-<MAC without colons>
function default_sitename() {
  return 'BirdSuite' . (station_mac(true) !== '' ? '-' . station_mac(true) : '');
}

function get_sitename() {
  $config = get_config();

  if (($config["SITE_NAME"] ?? '') == "") {
    $site_name = default_sitename();
  } else {
    $site_name = $config['SITE_NAME'];
  }
  return $site_name;
}

function get_service_mount_name() {
  $home = get_home();
  $service_mount = trim(shell_exec("systemd-escape -p --suffix=mount " . $home . "/BirdSongs/StreamData"));
  return $service_mount;
}

// the web password (owner 2026-10-25: symbols too): letters, digits and ! @ # % ^ & * ( ) - _ = + [ ] { } . , : ? ~ /;
// never a space, quote, backtick, $, backslash, ; < > | (BirdSuite.conf is read by bash, PHP and Python, field_ble.py
// splits on ;); the same rule in the page's pattern (web_password_pattern) and in install_firstrun.sh
function web_password_ok($p) { return (bool)preg_match('~^[A-Za-z0-9!@#%^&*()_=+\[\]{}.,:?\~/-]+$~', (string)$p); }
function web_password_pattern() { return '[A-Za-z0-9!@#%\^&*\(\)_=+\[\]\{\}.,:?~\/\-]+'; }

// the user name of the web login (owner 2026-10-25: set in Advanced, default birdsuite)
function web_user() {
  $u = (string)(get_config()['CADDY_USER'] ?? '');
  return preg_match('/^[A-Za-z0-9]+$/', $u) ? $u : 'birdsuite';
}

function is_authenticated() {
  $ret = false;
  $config = get_config();
  // no web password set: the station is open, so do not show a login box that only needs Enter
  if (($config['CADDY_PWD'] ?? '') === '') return true;
  if (isset($_SERVER['PHP_AUTH_USER'])) {
    // constant-time and strict (2026-10-23 test run: == compared numeric strings as numbers)
    $ret = (hash_equals((string)$config['CADDY_PWD'], (string)($_SERVER['PHP_AUTH_PW'] ?? '')) && in_array($_SERVER['PHP_AUTH_USER'], array(web_user(), 'birdnet'), true));  // birdnet: the user name before 2026-10-23
  }
  return $ret;
}

// A change asked by another web site (2026-10-20): any page open in a browser on the LAN could fire
// <img src="http://station/play.php?wipe=1&confirm=1"> or a form POST at the station, and the browser adds the saved
// login. Refused unless the request comes from the station's own pages; a link followed from elsewhere (another
// station, a notification) still opens a page, since the browser marks it as a navigation the user made.
function is_same_origin_request() {
  $site = $_SERVER['HTTP_SEC_FETCH_SITE'] ?? '';
  if ($site !== '') {
    if ($site === 'same-origin' || $site === 'none') return true;
    if ($_SERVER['REQUEST_METHOD'] !== 'GET' || ($_SERVER['HTTP_SEC_FETCH_MODE'] ?? '') !== 'navigate'
      || ($_SERVER['HTTP_SEC_FETCH_DEST'] ?? '') !== 'document') return false;
    // such a link may open a page, never act (2026-10-23 test run: play.php?deletefile=, ?wipe=1&confirm=1 and
    // ?shiftfile=..&doshift fired from a link on another site): only the keys that choose what a page shows pass
    parse_str((string)($_SERVER['QUERY_STRING'] ?? ''), $query);
    return !array_diff(array_keys($query), array('view', 'sci', 'filename', 'date', 'from', 'to', 'species', 'sort', 'status'));
  }
  // older browsers: the Origin (sent with POST) or the Referer must name this host
  $host = strtolower(preg_replace('/:\d+$/', '', $_SERVER['HTTP_HOST'] ?? ''));
  foreach (array('HTTP_ORIGIN', 'HTTP_REFERER') as $h) {
    if (!empty($_SERVER[$h])) return strtolower((string)parse_url($_SERVER[$h], PHP_URL_HOST)) === $host;
  }
  return true;
}

function ensure_authenticated($error_message = 'You cannot edit the settings for this installation') {
  if (!is_same_origin_request()) {
    header('HTTP/1.0 403 Forbidden');
    echo t('Refused: the request came from another web site');
    exit;
  }
  if (!is_authenticated()) {
    header('WWW-Authenticate: Basic realm="BirdSuite - user name: ' . web_user() . '"');
    header('HTTP/1.0 401 Unauthorized');
    // the callers' messages are English keys; t() of an already translated text answers it unchanged
    echo '<table><tr><td>' . t($error_message) . '</td></tr></table>';
    exit;
  }
}

function debug_log($message) {
  if (is_bool($message)) {
    $message = $message ? 'true' : 'false';
  }
  error_log($message . "\n", 3, $_SERVER['DOCUMENT_ROOT'] . "/debug_log.log");
}

function get_com_en_name($sci_name) {
  // read once per request (2026-10-23 test run: without static the 330 KB file was read and decoded for every row of a
  // detection list, about 20 ms a row)
  static $_labels_flickr = null;
  if ($_labels_flickr === null) {
    $_labels_flickr = json_decode(file_get_contents(get_home() . "/BirdNET-Pi/model/l18n/labels_en.json"), true) ?: array();
  }
  $engname = $_labels_flickr[$sci_name] ?? null;
  return $engname;
}

function get_label($record, $sort_by, $date=null) {
  $name = $record["Com_Name"];
  if ($sort_by == "confidence") {
    $ret = $name . ' (' . round($record['MaxConfidence'] * 100) . '%)';
  } elseif ($sort_by == "occurrences") {
    $valuescount = $record['Count'];
    if ($valuescount >= 1000) {
      $ret = $name . ' (' . fmt_num($valuescount / 1000, 1) . 'k)';
    } else {
      $ret = $name . ' (' . $valuescount . ')';
    }
  } elseif (($sort_by == "date") && !isset($date)) {
    $ret = $name . ' (' . $record['Date'] . ')';
  } elseif (($sort_by == "date") && isset($date)) {
    $ret = $name . ' (' . $record['Time'] . ')';
  } else {
    $ret = $name;
  }
  return $ret;
}

function get_db() {
  static $_db;
  if (!isset($_db)) {
    $_db = new SQLite3(BS_DB, SQLITE3_OPEN_READONLY);
    $_db->busyTimeout(5000);   // the analysis writes every few seconds: wait instead of failing a read
    apply_location_filter($_db);
  }
  return $_db;
}

// ---- Locations (US-58, owner 2026-10-20) ----
// A location is a place where the station records: name, coordinates and a radius. Every detection already stores the
// Lat/Lon it was analysed with (BirdSuite.conf at that moment), so it belongs to the location whose radius holds them —
// no column added. The current location is the one holding LATITUDE/LONGITUDE; it is shown on Now and changes only
// when the microphone is reconnected or the boot check finds the station moved. The pages show the current location's
// records (the header picklist was removed, owner 2026-10-20): apply_location_filter() puts a
// TEMP VIEW named detections over main.detections on a read-only connection, which every unqualified query then reads.
// Stored in scripts/locations.json (station data, outside git); seeded with the configured coordinates.
function locations_file() { return BS_CONFIG . '/locations.json'; }
// Built-in locations (owner 2026-10-20): "Home", the first one, and "Test / Playback", a second one for tests and for
// playing recordings to the station, so those detections stay apart and are deleted at once (Tools › Location Data,
// Delete all, then Species › Purge Files). Stored with their English name and a "builtin" mark; shown translated
// until renamed. The test location takes the coordinates of the first (primary) location (owner 2026-10-20), radius
// 50 m: the same place for the model, while the detections made when it is current carry its id (column Location,
// owner 2026-10-21) and are filed there and nowhere else. Nothing is notified
// or uploaded while it is current (utils/helpers.is_test_location). It is created once the first location has real
// coordinates.
function location_builtin_names() { return array('home' => 'Home', 'test' => 'Test / Playback'); }
function locations_load() {
  static $cache = null;
  if ($cache !== null) return $cache;
  $list = is_file(locations_file()) ? json_decode((string)file_get_contents(locations_file()), true) : null;
  $changed = false;
  if (!is_array($list) || !$list) {
    $c = get_config();
    $list = array(array('id' => 1, 'name' => 'Home', 'builtin' => 'home', 'lat' => round(floatval($c['LATITUDE'] ?? 0), 4),
                        'lon' => round(floatval($c['LONGITUDE'] ?? 0), 4), 'radius_km' => LOCATION_RADIUS_KM, 'theme' => 'ocean'));
    $changed = true;
  }
  $has_test = false;
  foreach ($list as $l) if (($l['builtin'] ?? '') === 'test') $has_test = true;
  if (!$has_test && !(abs(floatval($list[0]['lat'])) < 0.0001 && abs(floatval($list[0]['lon'])) < 0.0001)) {
    $list[] = array('id' => max(array_map(function ($l) { return intval($l['id']); }, $list)) + 1, 'name' => 'Test / Playback', 'builtin' => 'test',
                    'lat' => round(floatval($list[0]['lat']), 4), 'lon' => round(floatval($list[0]['lon']), 4), 'radius_km' => 0.05,
                    'theme' => 'red');
    $changed = true;
  }
  if ($changed) {
    @file_put_contents(locations_file(), json_encode(array_values($list), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n", LOCK_EX);
    @chmod(locations_file(), 0664); // the station user writes it too (scripts/location_data.py restore)
  }
  // the built-in names in the interface language, as long as they were not renamed
  $names = location_builtin_names();
  foreach ($list as &$l) {
    $b = $l['builtin'] ?? '';
    if (isset($names[$b]) && $l['name'] === $names[$b]) $l['name'] = t($names[$b]);
  }
  unset($l);
  // Test / Playback always last in every list (owner 2026-10-22)
  usort($list, function ($x, $y) { return (($x['builtin'] ?? '') === 'test') <=> (($y['builtin'] ?? '') === 'test'); });
  return $cache = $list;
}
// the timezone and species list of each location, filled from the station's when missing (owner 2026-10-20)
function locations_complete($list) {
  $tz = null; $sl = null;
  foreach ($list as &$l) {
    if (!isset($l['timezone'])) { $l['timezone'] = $tz ?? ($tz = station_timezone()); }
    if (!array_key_exists('species_list', $l)) { $l['species_list'] = $sl ?? ($sl = (string)(get_config()['SPECIES_LIST'] ?? '')); }
  }
  unset($l);
  return $list;
}
function locations_save($list) {
  // a built-in location keeps its English name on disk while it shows the translated one (any language)
  $names = location_builtin_names();
  // the biome found for a location (US-27) stays with it; scripts/utils/locations.py biomes finds it again when the
  // coordinates changed (biome_at) and for a new location, in the background, and writes it on its detections
  $kept = array();
  foreach (locations_load() as $o) $kept[intval($o['id'])] = array_intersect_key($o, array_flip(array('biome', 'ecoregion', 'biome_br', 'biome_at', 'land_cover', 'built_up_pct', 'land_at', 'setting', 'built_up_500_pct')));
  foreach ($list as &$l) {
    $b = $l['builtin'] ?? '';
    if (isset($names[$b]) && in_array($l['name'], array($names[$b], t($names[$b])), true)) $l['name'] = $names[$b];
    if (!isset($l['biome']) && !empty($kept[intval($l['id'] ?? 0)])) $l += $kept[intval($l['id'])];
  }
  unset($l);
  $ok = file_put_contents(locations_file(), json_encode(array_values($list), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n", LOCK_EX) !== false;
  @chmod(locations_file(), 0664);
  if ($ok) exec(station_prefix() . 'python3 ' . escapeshellarg(__ROOT__ . '/scripts/utils/locations.py') . ' biomes > /dev/null 2>&1 &');
  return $ok;
}
function location_km($lat1, $lon1, $lat2, $lon2) {
  $p1 = deg2rad($lat1); $p2 = deg2rad($lat2);
  $a = sin(($p2 - $p1) / 2) ** 2 + cos($p1) * cos($p2) * sin(deg2rad($lon2 - $lon1) / 2) ** 2;
  return 6371 * 2 * asin(min(1, sqrt($a)));
}
// the location holding these coordinates (the nearest whose radius reaches them), or null
function location_at($lat, $lon) {
  $best = null; $bd = INF;
  foreach (locations_load() as $l) {
    $d = location_km($lat, $lon, $l['lat'], $l['lon']);
    if ($d <= floatval($l['radius_km'] ?? LOCATION_RADIUS_KM) && $d < $bd) { $best = $l; $bd = $d; }
  }
  return $best;
}
// the current location (owner 2026-10-21): LOCATION_ID of BirdSuite.conf, written when the location changes; a station
// that has none yet (updated from before the tag) is at the location holding its coordinates
function location_current() {
  $c = get_config();
  $id = trim((string)($c['LOCATION_ID'] ?? ''), " \"'");
  if (ctype_digit($id) && ($l = location_by_id($id))) return $l;
  return location_at(floatval($c['LATITUDE'] ?? 0), floatval($c['LONGITUDE'] ?? 0));
}
// The radius is the one distance of a location (owner 2026-10-21): a point within it is this location (the phone's GPS,
// a restored backup, the boot check). The boot check places the station by its public IP, which is 10 to 30 km off in a
// city, so it asks only beyond the radius and never below LOCATION_IP_ERROR_KM (default 30); the phone's GPS uses the
// radius as it is.
function location_ip_error_km() { return max(1, intval(get_config()['LOCATION_IP_ERROR_KM'] ?? 30)); }
function location_boot_km($l) { return max(location_ip_error_km(), (int)ceil(floatval($l['radius_km'] ?? LOCATION_RADIUS_KM))); }
// does the database tag its detections with their location yet? (column Location, scripts/utils/locations.py migrate)
function detections_have_location() {
  return detections_location_state() !== 'none';
}
// 'none' = no Location column yet, 'all' = every detection tagged, 'some' = untagged rows left (they need the
// coordinate rule, which costs a scan: 0.2 s per query against 1 ms on the tag alone, owner 2026-10-21)
function detections_location_state() {
  static $state = null;
  if ($state === null) {
    $state = 'none';
    try {
      $db = new SQLite3(BS_DB, SQLITE3_OPEN_READONLY);
      $db->busyTimeout(2000);
      $r = $db->query('PRAGMA table_info(detections)');
      while ($r && ($row = $r->fetchArray(SQLITE3_ASSOC))) if ($row['name'] === 'Location') $state = 'all';
      if ($state === 'all') {
        $untagged = $db->querySingle('SELECT 1 FROM detections WHERE Location IS NULL LIMIT 1')
          || ($db->querySingle("SELECT 1 FROM sqlite_master WHERE type='table' AND name='deleted_detections'")
              && $db->querySingle('SELECT 1 FROM deleted_detections WHERE Location IS NULL LIMIT 1'));
        if ($untagged) $state = 'some';
      }
      $db->close();
    } catch (Exception $e) {}
  }
  return $state;
}
function location_by_id($id) {
  foreach (locations_load() as $l) if (intval($l['id']) === intval($id)) return $l;
  return null;
}
// the pages show the current location's records; the header picklist (cookie loc_filter) was removed (owner 2026-10-20),
// so an old cookie is ignored — no browser stays stuck on another location
// The Latest page may show every location (owner 2026-10-21): its Location picklist keeps the choice in the now_loc cookie,
// read only by the Latest pages (they define NOW_PAGE); every other page shows the current location
// how many locations have detections (owner 2026-10-22: the Location picklist of Now only when more than one has data)
function locations_with_data() {
  static $n = null;
  if ($n === null) $n = detections_have_location() ? intval(get_db()->querySingle('SELECT COUNT(DISTINCT Location) FROM main.detections WHERE Location IS NOT NULL')) : 0;  // main.: the whole table, not the current location's view
  return $n;
}
function location_filter() {
  // the same data filter on every page, kept per page (owner 2026-10-22): only Now opens on All, the others on the
  // current location, until another choice; empty = the current location, a number = that location (whichever
  // is active); only while more than one location has detections
  if (locations_with_data() < 2) return 'current';
  list($ck, $def) = location_filter_page();
  $v = $_COOKIE[$ck] ?? null;
  if ($v === null) return $def;
  if ($v === 'all') return 'all';
  if (ctype_digit((string)$v) && location_by_id($v)) return (string)intval($v);
  return 'current';
}
// the cookie and the default of the page's location filter: only Now (and its lists) on All, every other page on the
// current location (owner 2026-10-22)
function location_filter_page() {
  $view = defined('NOW_PAGE') ? 'Now' : (string)($_GET['view'] ?? pathinfo($_SERVER['SCRIPT_NAME'] ?? '', PATHINFO_FILENAME));
  if ($view === 'curation') $view = 'Curation';
  $key = 'loc_' . preg_replace('/[^A-Za-z0-9]/', '', $view);
  // every page on the current location (owner 2026-10-31: Latest and Now show the active location; Latest opened on All
  // since 2026-10-22); All stays one choice away in the picker
  return array($key, 'current');
}
// the locations with detections, by id (owner 2026-10-22: the Location picklist of Now)
function locations_data_ids() {
  static $ids = null;
  if ($ids === null) {
    $ids = array();
    if (detections_have_location()) {
      $r = get_db()->query('SELECT DISTINCT Location FROM main.detections WHERE Location IS NOT NULL');
      while ($r && ($x = $r->fetchArray(SQLITE3_NUM))) $ids[] = intval($x[0]);
    }
  }
  return $ids;
}
// the location a page shows, named above its table or chart (owner 2026-10-21); $chart_current: a chart drawn for the
// current location even while the table shows them all (the Now daily chart)
// Loc: [picklist] (owner 2026-10-22): the data filter of every page, the same as Now — the current location (it follows a
// change of location), every other location with detections, then All; kept per page (location_filter_page); only while more
// than one location has detections. Not the activation of a location (that is the header).
function location_picker() {
  if (!multi_location() || count(locations_load()) < 2 || locations_with_data() < 2) return '';
  $h = function ($x) { return htmlspecialchars((string)$x, ENT_QUOTES); };
  $cur = location_current(); $f = location_filter(); $ids = locations_data_ids();
  $o = '<span class="locpick"><label for="locpick">' . t('Loc:') . '</label> <select id="locpick" class="nowperiod" title="' . $h(t('Locations shown in the table and the cards')) . '"'
    . ' onchange="document.cookie = ' . $h(json_encode(location_filter_page()[0] . '=')) . ' + this.value + \';path=/;max-age=31536000;SameSite=Lax\'; location.reload()">'
    . '<option value=""' . ($f === 'current' || ($cur && $f === (string)intval($cur['id'])) ? ' selected' : '') . '>' . $h($cur ? $cur['name'] : t('Current location')) . '</option>';
  foreach (locations_load() as $l) {
    if (($cur && intval($l['id']) === intval($cur['id'])) || !in_array(intval($l['id']), $ids, true)) continue;
    $o .= '<option value="' . intval($l['id']) . '"' . ($f === (string)intval($l['id']) ? ' selected' : '') . '>' . $h($l['name']) . '</option>';
  }
  $o .= '<option value="all"' . ($f === 'all' ? ' selected' : '') . '>' . t('All') . '</option></select></span>'
    // the picklist as wide as the chosen name, not the longest one
    . '<script>(function () { var s = document.getElementById(\'locpick\'), c = document.createElement(\'canvas\').getContext(\'2d\'), cs = getComputedStyle(s);'
    . ' c.font = cs.fontWeight + \' \' + cs.fontSize + \' \' + cs.fontFamily; s.style.setProperty(\'width\', Math.ceil(c.measureText(s.options[s.selectedIndex].text).width + 30) + \'px\', \'important\'); })();</script>';
  return $o;
}
function location_label($chart_current = false) {
  // the pages' location line became the shared picker (owner 2026-10-22); the Now chart still names its location
  if (!$chart_current) return location_picker() !== '' ? '<div class="loclabel">' . location_picker() . '</div>' : '';
  if (count(locations_load()) < 2 || locations_with_data() < 2) return '';
  $l = $chart_current ? location_current() : location_shown();
  $name = $l ? $l['name'] : t('All locations');
  return '<div class="loclabel">&#128205; ' . t('Location:') . ' <b>' . htmlspecialchars($name) . '</b></div>';
}
// the location of one detection for the tables (owner 2026-10-21): its tag, else the location holding its coordinates
function detection_location_name($row) {
  static $names = null;
  if ($names === null) { $names = array(); foreach (locations_load() as $l) $names[intval($l['id'])] = $l['name']; }
  if (isset($row['Location']) && $row['Location'] !== null && $row['Location'] !== '') return $names[intval($row['Location'])] ?? '#' . intval($row['Location']);
  if (isset($row['Lat'], $row['Lon']) && ($l = location_at(floatval($row['Lat']), floatval($row['Lon'])))) return $l['name'];
  return '-';
}
// the columns the detection tables read for detection_location_name (Location only once the database has it)
function location_columns_sql() {
  return 'Lat, Lon' . (detections_have_location() ? ', Location' : '');
}
// SQL condition: the coordinates inside a location's radius (a box first, cheap; the radius is small)
function location_box_sql($l) {
  $r = floatval($l['radius_km'] ?? LOCATION_RADIUS_KM);
  $dlat = $r / 111.0;
  $dlon = $r / max(0.01, 111.0 * cos(deg2rad($l['lat'])));
  return sprintf('Lat BETWEEN %.6F AND %.6F AND Lon BETWEEN %.6F AND %.6F', $l['lat'] - $dlat, $l['lat'] + $dlat, $l['lon'] - $dlon, $l['lon'] + $dlon);
}
// the detections of a location (owner 2026-10-21): every detection carries the id of the location it was recorded at
// (column Location), so two locations may share their coordinates (the same building, another window). A row without
// the tag (before the column existed) falls back to the coordinate rule below.
function location_sql($l, $removed = false) {
  $coords = location_coords_sql($l);
  if (intval($l['id'] ?? 0) <= 0 || !detections_have_location()) return $coords;
  // every detection tagged: the index alone; not for deleted_detections ($removed, owner 2026-10-24: lines removed before
  // the tag existed have none, and the Deleted column of Tools › Data showed 0)
  if (!$removed && detections_location_state() === 'all') return 'Location = ' . intval($l['id']);
  return '(Location = ' . intval($l['id']) . ' OR (Location IS NULL AND ' . $coords . '))';
}
// the coordinate rule (owner 2026-10-20): inside its radius AND nearer its centre than any other location's — close
// locations (the garage next to the office) each keep their own records
function location_coords_sql($l) {
  $cond = location_box_sql($l);
  $c = max(0.01, cos(deg2rad($l['lat'])));
  $d2 = function ($lat, $lon) use ($c) { return sprintf('((Lat - %.6F) * (Lat - %.6F) + ((Lon - %.6F) * %.6F) * ((Lon - %.6F) * %.6F))', $lat, $lat, $lon, $c, $lon, $c); };
  foreach (locations_load() as $o) {
    if (intval($o['id']) === intval($l['id'] ?? -1)) continue;
    if (location_km($l['lat'], $l['lon'], $o['lat'], $o['lon']) > floatval($l['radius_km'] ?? LOCATION_RADIUS_KM) + floatval($o['radius_km'] ?? LOCATION_RADIUS_KM)) continue;
    $cond .= ' AND ' . $d2($l['lat'], $l['lon']) . ' <= ' . $d2($o['lat'], $o['lon']);
  }
  return $cond;
}
// the location the pages show, or null for all of them
function location_shown() {
  $f = location_filter();
  if ($f === 'all') return null;
  if ($f === 'current') {
    $cur = location_current();
    if ($cur) return $cur;
    // coordinates outside every location: the configured point with the default radius
    $c = get_config();
    return array('id' => 0, 'name' => t('Current coordinates'), 'lat' => floatval($c['LATITUDE'] ?? 0), 'lon' => floatval($c['LONGITUDE'] ?? 0), 'radius_km' => LOCATION_RADIUS_KM);
  }
  return location_by_id($f) ?: null;
}
// the station's coordinates (BirdSuite.conf LATITUDE/LONGITUDE, 4 decimals) and, when the species list is a Brazilian
// state, the state's list there (scripts/locate_state.py); the analysis reads them from the next recording.
// Used by the Latest page answers (station moved, microphone reconnected) and the Locations page. Returns '' or an error.
function apply_station_coordinates($lat, $lon) {
  $home = get_home();
  $config = get_config();
  $lat = round(floatval($lat), 4); $lon = round(floatval($lon), 4);
  $cf = BS_CONF;
  $c = file_get_contents($cf);
  $c = preg_replace('/^LATITUDE=.*/m', "LATITUDE=$lat", $c);
  $c = preg_replace('/^LONGITUDE=.*/m', "LONGITUDE=$lon", $c);
  $new_list = null;
  if (preg_match('/^BR-[A-Z]{2}$/', $config['SPECIES_LIST'] ?? '')) {
    $uf = trim((string)shell_exec('python3 ' . escapeshellarg($home . '/BirdNET-Pi/scripts/locate_state.py') . ' ' . escapeshellarg((string)$lat) . ' ' . escapeshellarg((string)$lon) . ' 2>/dev/null'));
    if (preg_match('/^[A-Z]{2}$/', $uf) && is_file($home . "/BirdNET-Pi/model/include_lists/BR-$uf.txt")) {
      $new_list = "BR-$uf";
      $c = preg_replace('/^SPECIES_LIST=.*/m', "SPECIES_LIST=$new_list", $c);
    }
  }
  if (file_put_contents($cf, $c) === false) return t('Error writing the settings');
  if ($new_list !== null && $new_list !== ($config['SPECIES_LIST'] ?? '')) {
    shell_exec(station_prefix() . 'python3 ' . escapeshellarg($home . '/BirdNET-Pi/scripts/select_species_list.py') . ' > /dev/null 2>&1');
  }
  get_config(true);
  return '';
}
// Basic Settings and Station Setup (owner 2026-10-20): the location holding these coordinates takes this name, or a new
// location is made for them when none holds them (name empty = Home, the default location)
function location_set_named($lat, $lon, $name) {
  $name = mb_substr(trim(preg_replace('/[\x00-\x1F\x7F<>"]/', '', (string)$name)), 0, 60);
  $lat = round(floatval($lat), 4); $lon = round(floatval($lon), 4);
  $list = locations_load();
  $l = location_at($lat, $lon);
  if ($l) {
    if ($name === '' || $name === $l['name']) return true;
    foreach ($list as &$x) if (intval($x['id']) === intval($l['id'])) $x['name'] = $name;
    unset($x);
  } else {
    $list[] = array('id' => max(array_map('intval', array_column($list, 'id'))) + 1, 'name' => $name !== '' ? $name : 'Home',
                    'lat' => $lat, 'lon' => $lon, 'radius_km' => LOCATION_RADIUS_KM);
  }
  return locations_save($list);
}
// Is the clock right? (US-120, owner 2026-10-20) The Pi has no battery clock: it is right once NTP synchronised it or
// the phone set it (scripts/field_net.py time writes /run/birdsuite/time_confirmed). Detections are stamped with it.
function time_confirmed() {
  if (is_file('/run/birdsuite/time_confirmed')) return true;
  return trim((string)shell_exec('timedatectl show -p NTPSynchronized --value 2>/dev/null')) === 'yes';
}
// the phone's clock (epoch seconds) when the station's is not confirmed; '' or an error
function set_time_from_client($epoch) {
  $epoch = intval($epoch);
  if ($epoch < 1735689600 || time_confirmed()) return '';
  $out = json_decode((string)shell_exec(station_prefix() . 'python3 ' . escapeshellarg(__DIR__ . '/field_net.py') . ' time ' . $epoch . ' 2>/dev/null'), true);
  return ($out['ok'] ?? false) ? '' : ($out['error'] ?? t('the clock was not set'));
}

// the species list filter choices (SPECIES_LIST): none, the station lists of species_lists/, the Brazilian states not
// built yet — the select of Settings › Locations (owner 2026-10-20; was in Basic Settings)
function species_list_options($cur) {
  $home = get_home();
  $br = array('AC' => 'Acre', 'AL' => 'Alagoas', 'AP' => 'Amapá', 'AM' => 'Amazonas', 'BA' => 'Bahia', 'CE' => 'Ceará', 'DF' => 'Distrito Federal',
    'ES' => 'Espírito Santo', 'GO' => 'Goiás', 'MA' => 'Maranhão', 'MT' => 'Mato Grosso', 'MS' => 'Mato Grosso do Sul', 'MG' => 'Minas Gerais',
    'PA' => 'Pará', 'PB' => 'Paraíba', 'PR' => 'Paraná', 'PE' => 'Pernambuco', 'PI' => 'Piauí', 'RJ' => 'Rio de Janeiro',
    'RN' => 'Rio Grande do Norte', 'RS' => 'Rio Grande do Sul', 'RO' => 'Rondônia', 'RR' => 'Roraima', 'SC' => 'Santa Catarina',
    'SP' => 'São Paulo', 'SE' => 'Sergipe', 'TO' => 'Tocantins');
  $h = function ($x) { return htmlspecialchars((string)$x, ENT_QUOTES); };
  $out = "<option value=''" . ($cur === '' ? ' selected' : '') . ">" . t('None: use the model\'s species distribution') . "</option>";
  $own = array();
  foreach (glob($home . '/BirdNET-Pi/config/species_lists/custom/*.txt') ?: array() as $f) $own[] = basename($f, '.txt');
  sort($own);
  if ($own) {
    $out .= "<optgroup label='" . $h(t('Station lists (species_lists/custom/)')) . "'>";
    foreach ($own as $n) $out .= "<option value='" . $h($n) . "'" . ($cur === $n ? ' selected' : '') . '>' . $h((strpos($n, 'BR-') === 0 && isset($br[substr($n, 3)])) ? "$n: " . $br[substr($n, 3)] : $n) . '</option>';
    $out .= '</optgroup>';
  }
  $out .= "<optgroup label='" . $h(t('Brazilian states (WikiAves records, CBRO names)')) . "'>";
  foreach ($br as $uf => $name) {
    if (in_array("BR-$uf", $own, true) || !is_file($home . "/BirdNET-Pi/model/include_lists/BR-$uf.txt")) continue;
    $out .= "<option value='BR-$uf'" . ($cur === "BR-$uf" ? ' selected' : '') . ">$name ($uf)</option>";
  }
  return $out . '</optgroup>';
}

// A location carries its own timezone and default species list (owner 2026-10-20); missing = the station's current ones.
function station_timezone() { return trim((string)shell_exec('timedatectl show --value --property=Timezone 2>/dev/null')); }
function set_station_timezone($tz) {
  if ($tz === '' || !in_array($tz, DateTimeZone::listIdentifiers(), true) || $tz === station_timezone()) return;
  // the root steps of a timezone change: timedatectl and /etc/timezone, in birdsuite-root (least privilege)
  shell_exec("sudo -n /usr/local/sbin/birdsuite-root timezone " . escapeshellarg($tz));
}
function species_list_valid($list) {
  $home = get_home();
  return $list === '' || (preg_match('/^BR-[A-Z]{2}$/', $list) && is_file("$home/BirdNET-Pi/model/include_lists/$list.txt"))
    || (preg_match('/^[A-Za-z0-9_-]+$/', $list) && is_file("$home/BirdNET-Pi/config/species_lists/custom/$list.txt"));
}
function set_species_list($list) {
  if (!species_list_valid($list) || $list === (get_config()['SPECIES_LIST'] ?? '')) return;
  $f = BS_CONF;
  $c = file_get_contents($f);
  $c = preg_match('/^SPECIES_LIST=/m', $c) ? preg_replace('/^SPECIES_LIST=.*/m', "SPECIES_LIST=$list", $c) : $c . "\nSPECIES_LIST=$list\n";
  file_put_contents($f, $c);
  get_config(true);
  shell_exec(station_prefix() . 'python3 ' . escapeshellarg(get_home() . '/BirdNET-Pi/scripts/select_species_list.py') . ' > /dev/null 2>&1');
}
// the station is now at this location: its coordinates, then its timezone and species list when it has them
// (Make current, Adjust, the microphone question, the Bluetooth GPS). Returns '' or an error.
function location_activate($l) {
  $err = apply_station_coordinates($l['lat'], $l['lon']);
  if ($err !== '') return $err;
  // the tag written on every new detection (owner 2026-10-21)
  $cf = BS_CONF;
  $c = file_get_contents($cf);
  $line = 'LOCATION_ID=' . intval($l['id']);
  $c = preg_match('/^LOCATION_ID=/m', $c) ? preg_replace('/^LOCATION_ID=.*/m', $line, $c) : rtrim($c, "\n") . "\n## LOCATION_ID: the current location (Settings › Locations), written on every new detection\n$line\n";
  if (file_put_contents($cf, $c) === false) return t('Error writing the settings');
  get_config(true);
  if (!empty($l['timezone'])) set_station_timezone($l['timezone']);
  if (array_key_exists('species_list', $l) && $l['species_list'] !== null) set_species_list((string)$l['species_list']);
  return '';
}

// The timezone of a point (owner 2026-10-20: it comes with the coordinates picked on the map, by default):
// in Brazil the state (IBGE outlines shipped, offline: scripts/locate_state.py --offline) gives it; elsewhere the timeapi.io
// lookup when online; without internet the offset of the longitude (Etc/GMT±n). Returns array(timezone, how).
function timezone_for($lat, $lon) {
  $br = array('AC' => 'America/Rio_Branco', 'AM' => 'America/Manaus', 'RO' => 'America/Porto_Velho', 'RR' => 'America/Boa_Vista',
    'MT' => 'America/Cuiaba', 'MS' => 'America/Campo_Grande', 'PA' => 'America/Belem', 'AP' => 'America/Belem', 'TO' => 'America/Araguaina',
    'MA' => 'America/Fortaleza', 'PI' => 'America/Fortaleza', 'CE' => 'America/Fortaleza', 'RN' => 'America/Fortaleza', 'PB' => 'America/Fortaleza',
    'PE' => 'America/Recife', 'AL' => 'America/Maceio', 'SE' => 'America/Maceio', 'BA' => 'America/Bahia');
  $uf = trim((string)shell_exec('python3 ' . escapeshellarg(get_home() . '/BirdNET-Pi/scripts/locate_state.py') . ' ' . escapeshellarg((string)floatval($lat))
    . ' ' . escapeshellarg((string)floatval($lon)) . ' --offline 2>/dev/null'));
  if (preg_match('/^[A-Z]{2}$/', $uf)) return array($br[$uf] ?? 'America/Sao_Paulo', t('Brazil, {uf}', array('uf' => $uf)));
  $ctx = stream_context_create(array('http' => array('timeout' => 4, 'header' => "User-Agent: BirdSuite\r\n")));
  $j = json_decode((string)@file_get_contents('https://timeapi.io/api/TimeZone/coordinate?latitude=' . floatval($lat) . '&longitude=' . floatval($lon), false, $ctx), true);
  if (!empty($j['timeZone']) && in_array($j['timeZone'], DateTimeZone::listIdentifiers(), true)) return array($j['timeZone'], 'timeapi.io');
  $off = (int)round(floatval($lon) / 15);
  return array($off === 0 ? 'Etc/GMT' : 'Etc/GMT' . ($off > 0 ? '-' : '+') . abs($off), t('from the longitude'));
}

// the microphone was plugged in again after being removed (scripts/mic_hotplug.sh): the Latest page asks where the
// station is; answered = the file is removed
function mic_reconnected_file() { return BS_STATE . '/mic_reconnected.json'; }

function apply_location_filter($db) {
  if (count(locations_load()) < 2) return; // one location: everything is there
  $l = location_shown();
  if ($l === null) return;
  @$db->exec('CREATE TEMP VIEW IF NOT EXISTS detections AS SELECT * FROM main.detections WHERE ' . location_sql($l));
}

// the request values are bound, never written into the SQL (2026-10-23 test run)
function fetch_species_array($sort_by, $date=null) {
  $db = get_db();
  // rejected detections (Review: "not this bird") are not a species' best detection
  $where = "WHERE 1" . ((isset($date)) ? " AND Date == :date" : "") . not_rejected_sql();
  $order = array('occurrences' => 'COUNT(*) DESC', 'confidence' => 'MAX(Confidence) DESC', 'date' => 'MIN(Date) DESC, Time DESC')[$sort_by] ?? 'Com_Name ASC';
  if (not_rejected_sql() !== '') {
    // the counts from the covering index minus the rejected ones, and the best detection of each species looked up by
    // the index on (species, confidence): NOT IN on every row cost 1.5 s at ten times the station's data, this 0.3 s
    // (2026-10-23 test run); the same columns and rows as before
    $w = isset($date) ? 'Date == :date' : '1';
    $order = array('occurrences' => 'Count DESC', 'confidence' => 'MaxConfidence DESC', 'date' => 'c.first DESC, b.Time DESC')[$sort_by] ?? 'b.Com_Name ASC';
    // every day: the counts per species come from grouped_counts() (kept per month), the first day of each species by
    // one index seek; the statement gets them as a list of values (2026-10-23 run 3: 1.5 s, and 29 s sorted by date, at
    // ten times the station's data)
    $counts = isset($date) ? array() : grouped_counts(array('Sci_Name'));
    if ($counts) {
      $first = $db->prepare('SELECT MIN(Date) FROM detections WHERE Sci_Name = :s');
      $vals = array();
      foreach ($counts as $c) {
        $f = '';
        if ($sort_by === 'date') {
          $first->bindValue(':s', $c[0]);
          $r = $first->execute();
          $f = (string)($r ? $r->fetchArray(SQLITE3_NUM)[0] : '');
          $first->reset();
        }
        $vals[] = "('" . SQLite3::escapeString($c[0]) . "', " . intval($c[1]) . ", '" . SQLite3::escapeString($f) . "')";
      }
      $statement = $db->prepare("WITH c(Sci_Name, n, first) AS (VALUES " . implode(', ', $vals) . ")
        SELECT b.Date, b.Time, b.File_Name, b.Com_Name, c.Sci_Name, c.n AS Count, b.Confidence AS MaxConfidence FROM c
        JOIN detections b ON b.File_Name = (SELECT File_Name FROM detections WHERE Sci_Name = c.Sci_Name" . not_rejected_sql() . " ORDER BY Confidence DESC LIMIT 1)
        GROUP BY c.Sci_Name ORDER BY $order");
      ensure_db_ok($statement);
      return $statement->execute();
    }
    $statement = $db->prepare("WITH c AS (SELECT Sci_Name, COUNT(*) AS n" . ($sort_by === 'date' ? ', MIN(Date) AS first' : '') . " FROM detections WHERE $w GROUP BY Sci_Name),
      r AS (SELECT Sci_Name, COUNT(*) AS n FROM detections WHERE $w AND File_Name IN (SELECT File_Name FROM detection_reviews WHERE Verdict = 'no') GROUP BY Sci_Name)
      SELECT b.Date, b.Time, b.File_Name, b.Com_Name, c.Sci_Name, c.n - IFNULL(r.n, 0) AS Count, b.Confidence AS MaxConfidence
      FROM c LEFT JOIN r ON r.Sci_Name = c.Sci_Name
      JOIN detections b ON b.File_Name = (SELECT File_Name FROM detections WHERE Sci_Name = c.Sci_Name AND $w" . not_rejected_sql() . " ORDER BY Confidence DESC LIMIT 1)
      WHERE c.n - IFNULL(r.n, 0) > 0 GROUP BY c.Sci_Name ORDER BY $order");
  } else {
    $statement = $db->prepare("SELECT Date, Time, File_Name, Com_Name, Sci_Name, COUNT(*) as Count, MAX(Confidence) as MaxConfidence FROM detections $where GROUP BY Sci_Name ORDER BY $order");
  }
  ensure_db_ok($statement);
  if (isset($date)) $statement->bindValue(':date', $date, SQLITE3_TEXT);
  $result = $statement->execute();
  return $result;
}

function fetch_best_detection($com_name) {
  $db = get_db();
  $statement = $db->prepare("SELECT Com_Name, Sci_Name, COUNT(*), MAX(Confidence), File_Name, Date, Time from detections WHERE Com_Name = :com" . not_rejected_sql());
  ensure_db_ok($statement);
  $statement->bindValue(':com', $com_name, SQLITE3_TEXT);
  $result = $statement->execute();
  return $result;
}

function fetch_all_detections($sci_name, $sort_by, $date=null) {
  $db = get_db();
  $filter = (isset($date)) ? "AND Date == :date" : "";
  if ($sort_by === "occurrences") {
    $order = "COUNT(*) DESC";
  } elseif ($sort_by === "confidence") {
    $order = "Confidence DESC";
  } else {
    $order = (isset($date)) ? "Time DESC" : "Date DESC, Time DESC";
  }
  $statement = $db->prepare("SELECT * FROM detections WHERE Sci_Name == :sci $filter ORDER BY $order");
  ensure_db_ok($statement);
  $statement->bindValue(':sci', $sci_name, SQLITE3_TEXT);
  if (isset($date)) $statement->bindValue(':date', $date, SQLITE3_TEXT);
  $result = $statement->execute();
  return $result;
}

// a value kept for $ttl seconds (2026-10-20): the all-days cards scan every detection, and the Latest page asks for them
// after each new detection; one file per location shown, in the system temp folder
function cached_value($key, $ttl, $fn) {
  // keyed by the area the pages show (its SQL holds the radius and the neighbouring locations), the day and the
  // database's last change: a new detection or a review shows at once, the cache only spares the repeated polls
  $l = count(locations_load()) >= 2 ? location_shown() : null;
  clearstatcache(true, BS_DB);
  $f = sys_get_temp_dir() . '/birdsuite-' . md5(__ROOT__ . '|' . $key . '|' . ($l ? location_sql($l) : 'all') . '|' . date('Y-m-d')
    . '|' . @filemtime(BS_DB)) . '.json';
  if (is_file($f) && filemtime($f) > time() - $ttl) {
    $v = json_decode((string)@file_get_contents($f), true);
    if ($v !== null) return $v;
  }
  $v = $fn();
  $tmp = @tempnam(sys_get_temp_dir(), 'birdsuite-');
  if ($tmp !== false && @file_put_contents($tmp, json_encode($v)) !== false) @rename($tmp, $f); else if ($tmp) @unlink($tmp);
  // every database change makes a new file: now and then the old ones go
  if (mt_rand(1, 50) === 1) foreach (glob(sys_get_temp_dir() . '/birdsuite-*') ?: array() as $o) if (@filemtime($o) < time() - 600) @unlink($o);
  return $v;
}

// A value computed over the days before today, kept until those days change (2026-10-23 test run: By Week, Seasonality
// and By Hour over every day cost 1-7 s at ten times the station's data). $sig = past_signature(): it changes when a past
// detection is removed, restored, moved to another location or given another species; the day is part of the key, so
// today's detections never enter it. One file per key: a new signature replaces the old file.
function cached_past($key, $sig, $fn) {
  $l = count(locations_load()) >= 2 ? location_shown() : null;
  $base = sys_get_temp_dir() . '/birdsuite_past-' . md5(__ROOT__ . '|' . $key . '|' . ($l ? location_sql($l) : 'all')) . '-';
  $f = $base . md5(date('Y-m-d') . '|' . $sig) . '.json';
  if (is_file($f)) {
    $v = json_decode((string)@file_get_contents($f), true);
    if ($v !== null) return $v;
  }
  $v = $fn();
  $tmp = @tempnam(sys_get_temp_dir(), 'birdsuite_past-');
  if ($tmp !== false && @file_put_contents($tmp, json_encode($v)) !== false) {
    foreach (glob($base . '*.json') ?: array() as $o) if ($o !== $f) @unlink($o);
    @rename($tmp, $f);
  } else if ($tmp) @unlink($tmp);
  return $v;
}
// The change counter of each month before today (table past_changes, kept by triggers on detections: a removal, a
// restore, a species or location change, an import of past recordings; createdb.sh and the update make them), or null
// on a database without it (cached_past() and past_signature() then). 2026-10-23 run 3: the whole-history cache was
// rebuilt every day and after every past change (By Hour 4 s, the species names 7 s at ten times the station's data).
function past_month_changes() {
  static $m = false;
  if ($m !== false) return $m;
  $db = get_db();
  $m = null;
  if (!$db->querySingle("SELECT 1 FROM sqlite_master WHERE type='trigger' AND name='past_changes_upd'")) return $m;
  $m = array();
  $r = $db->query('SELECT Month, N FROM past_changes');
  while ($r && ($x = $r->fetchArray(SQLITE3_NUM))) $m[$x[0]] = (int)$x[1];
  return $m;
}
// The rows of $fn($lo, $hi) for the days between $from (or the first detection) and $to (or yesterday), computed per
// calendar month and kept in the station's state folder (it survives a restart and a new day): a month is computed
// again only when its counter in $months changes (or, for the current month, when a day passes). Returns one row list
// per month, for the caller to add up.
function cached_past_months($key, $from, $to, $months, $fn) {
  $yesterday = date('Y-m-d', strtotime('-1 day'));
  $hi_all = ($to !== '' && $to < $yesterday) ? $to : $yesterday;
  $lo_all = $from;
  if ($lo_all === '') {
    $lo_all = (string)get_db()->querySingle('SELECT MIN(Date) FROM detections');
    if ($lo_all === '') return array();
  }
  if ($lo_all > $hi_all) return array();
  $l = count(locations_load()) >= 2 ? location_shown() : null;
  $dir = BS_STATE . '/cache';
  if (!is_dir($dir)) @mkdir($dir, 0775, true);
  $f = $dir . '/past-' . md5(__ROOT__ . '|' . $key . '|' . ($l ? location_sql($l) : 'all')) . '.json';
  $old = is_file($f) ? (json_decode((string)@file_get_contents($f), true) ?: array()) : array();
  $new = array();
  $out = array();
  $changed = false;
  for ($m = substr($lo_all, 0, 7); $m <= substr($hi_all, 0, 7); $m = date('Y-m', strtotime($m . '-01 +1 month'))) {
    $lo = max($lo_all, $m . '-01');
    $hi = min($hi_all, date('Y-m-t', strtotime($m . '-01')));
    $ck = $m . '|' . $lo . '|' . $hi . '|' . ($months[$m] ?? 0);
    if (isset($old[$ck])) {
      $new[$ck] = $old[$ck];
    } else {
      $new[$ck] = $fn($lo, $hi);
      $changed = true;
    }
    $out[] = $new[$ck];
  }
  // the files of periods no page asks for any more (a "last 7 days" key changes every day) go after two weeks
  if (mt_rand(1, 50) === 1) foreach (glob($dir . '/past-*.json') ?: array() as $o) if (@filemtime($o) < time() - 14 * 86400) @unlink($o);
  if ($changed || count($new) !== count($old)) {
    $tmp = @tempnam($dir, 'past-');
    if ($tmp !== false && @file_put_contents($tmp, json_encode($new)) !== false) {
      @chmod($tmp, 0664);
      @rename($tmp, $f);
    } else if ($tmp) @unlink($tmp);
  }
  return $out;
}
// what changes when the detections before today change: their number (a removal, a restore, a location change), the
// removed ones and the species changes (Change species rewrites the detection in place and records a review 'changed').
// The "not this bird" answers are not in it: grouped_counts() takes the rejected ones off afresh on every call.
function past_signature() {
  static $sig = null;
  if ($sig !== null) return $sig;
  $db = get_db();
  $st = $db->prepare('SELECT COUNT(*) FROM detections WHERE Date < :d');
  $st->bindValue(':d', date('Y-m-d'));
  $r = $st->execute();
  $sig = (string)($r ? $r->fetchArray(SQLITE3_NUM)[0] : '');
  if ($db->querySingle("SELECT 1 FROM sqlite_master WHERE type='table' AND name='deleted_detections'"))
    $sig .= '|' . implode('|', (array)$db->querySingle('SELECT COUNT(*), MAX(Deleted_At) FROM deleted_detections', true));
  if ($db->querySingle("SELECT 1 FROM pragma_table_info('detection_reviews') WHERE name = 'Reason'"))
    $sig .= '|' . implode('|', (array)$db->querySingle("SELECT COUNT(*), MAX(Reviewed_At) FROM detection_reviews WHERE Reason = 'changed'", true));
  return $sig;
}
// COUNT(*) per group of the detections between $from and $to ('' = no bound), rejected ones ("not this bird") left out.
// $keys = the SQL expressions grouped by; returns rows array(key 1, ..., key n, count). Two cheap queries instead of
// NOT IN on every row (0.5 µs per detection, the larger part of these charts): every detection counted from the
// covering indexes, the days before today kept by cached_past(), then the rejected ones (a few hundred, found through
// the reviews) taken off.
function grouped_counts($keys, $from = '', $to = '', $extra = '', $params = array()) {
  $db = get_db();
  $today = date('Y-m-d');
  $n = count($keys);
  $sel = implode(', ', array_map(function ($e, $i) { return "$e AS k$i"; }, $keys, array_keys($keys)));
  $grp = implode(', ', array_map(function ($i) { return "k$i"; }, array_keys($keys)));
  $rows = function ($where, $p) use ($db, $sel, $grp, $extra, $params) {
    $st = $db->prepare("SELECT $sel, COUNT(*) FROM detections WHERE $where$extra GROUP BY $grp");
    if ($st === false) return array();
    foreach ($params + $p as $k => $v) $st->bindValue($k, $v);
    $out = array();
    $res = $st->execute();
    while ($res && ($r = $res->fetchArray(SQLITE3_NUM))) $out[] = $r;
    return $out;
  };
  $range = ($from !== '' ? 'Date >= :f' : '1') . ($to !== '' ? ' AND Date <= :t' : '');
  $rp = ($from !== '' ? array(':f' => $from) : array()) + ($to !== '' ? array(':t' => $to) : array());
  $parts = array();
  if ($from === '' || $from < $today) {
    $gkey = 'grouped|' . implode('|', $keys) . '|' . $from . '|' . $to . '|' . $extra . '|' . json_encode($params);
    $months = past_month_changes();
    if ($months !== null) {
      // per calendar month, each kept until a detection of that month changes (2026-10-23 run 3)
      $parts = array_merge($parts, cached_past_months($gkey, $from, $to, $months, function ($lo, $hi) use ($rows, $range, $rp) {
        return $rows($range . ' AND Date BETWEEN :lo AND :hi', $rp + array(':lo' => $lo, ':hi' => $hi));
      }));
    } else {
      $parts[] = cached_past($gkey, past_signature(),
        function () use ($rows, $range, $rp, $today) { return $rows($range . ' AND Date < :today', $rp + array(':today' => $today)); });
    }
  }
  // today's rows with today as the only lower bound: with the period's start beside it the planner walked every day of
  // the species index (2026-10-23 run 3: 0.65 s instead of 20 ms at ten times the station's data)
  if ($to === '' || $to >= $today) $parts[] = $rows('Date >= :today' . ($to !== '' ? ' AND Date <= :t' : ''), ($to !== '' ? array(':t' => $to) : array()) + array(':today' => $today));
  $out = array();
  foreach ($parts as $part) foreach ($part as $r) {
    $k = implode("\x1f", array_slice($r, 0, $n));
    if (isset($out[$k])) $out[$k][$n] += $r[$n]; else $out[$k] = $r;
  }
  if (not_rejected_sql() !== '') {
    // +Date: the few rejected detections are found through the reviews, not by walking every day of the period
    // (2026-10-23 run 3: 0.6 s for By Hour "All" at ten times the station's data)
    foreach ($rows(str_replace('Date ', '+Date ', $range) . " AND File_Name IN (SELECT File_Name FROM detection_reviews WHERE Verdict = 'no')", $rp) as $r) {
      $k = implode("\x1f", array_slice($r, 0, $n));
      if (isset($out[$k]) && ($out[$k][$n] -= $r[$n]) <= 0) unset($out[$k]);
    }
  }
  ksort($out, SORT_STRING); // in the order of GROUP BY, as one query gave them
  return array_values($out);
}
// simple mode (owner 2026-10-29, US-125): without several locations (MULTI_LOCATION) or several stations (MULTI_STATION)
// their controls are hidden (asked at the installation, switched in Tools › Defaults); the data stays the same
function multi_location() { return (string)(get_config()['MULTI_LOCATION'] ?? '0') === '1'; }
function multi_station() { return (string)(get_config()['MULTI_STATION'] ?? '0') === '1'; }
// the biome of a location (US-27, owner 2026-10-29): "Biome: <the RESOLVE biome, translated> · <ecoregion>" and in Brazil
// "IBGE: <the IBGE biome>"; '' while it is not found yet (scripts/utils/locations.py biomes runs after a save)
function location_biome_text($l) {
  if (empty($l['biome']) && empty($l['biome_br'])) return isset($l['biome']) ? t('Biome: none (sea or ice)') : '';
  $h = function ($x) { return htmlspecialchars((string)$x, ENT_QUOTES); };
  $o = $l['biome'] ? t('Biome: {biome}', array('biome' => $h(t($l['biome'])))) . ($l['ecoregion'] ? ' · ' . $h($l['ecoregion']) : '') : '';
  if (!empty($l['biome_br'])) $o .= ($o !== '' ? ' · ' : '') . t('IBGE: {biome}', array('biome' => $h(t($l['biome_br']))));
  // the land cover within 100 m (ESA WorldCover, online when the location was saved)
  if (!empty($l['land_cover'])) $o .= '<br>' . t('Land cover within 100 m: {cover}, {pct}% built-up', array('cover' => $h(t($l['land_cover'])), 'pct' => intval($l['built_up_pct'] ?? 0)));
  // urban / peri-urban / natural from the built-up share within 500 m (owner 2026-10-29)
  if (!empty($l['setting'])) $o .= '<br>' . t('Setting: {setting} ({pct}% built-up within 500 m)', array('setting' => '<b>' . $h(t($l['setting'])) . '</b>', 'pct' => intval($l['built_up_500_pct'] ?? 0)));
  return $o;
}
// the scheme of the station's own links (owner 2026-10-29, US-126): http unless HTTPS_ALWAYS = 1 (Tools › Defaults); the
// https side is only switched on for a moment to read the device's GPS (scripts/https_temp.php)
function station_scheme() { return (string)(get_config()['HTTPS_ALWAYS'] ?? '0') === '1' ? 'https' : 'http'; }
// a text without accents and in lower case, for the name searches (2026-10-29: "sabia" finds Sabiá, as the page
// filters do); no intl on the stations, so a table of the Latin letters with marks
function fold_text($s) {
  static $map = null;
  if ($map === null) {
    $map = array();
    foreach (array('a' => 'àáâãäåā', 'c' => 'çćč', 'e' => 'èéêëēė', 'i' => 'ìíîïī', 'n' => 'ñń', 'o' => 'òóôõöøō', 'u' => 'ùúûüū', 'y' => 'ýÿ', 's' => 'śš', 'z' => 'źżž', 'l' => 'ł') as $b => $chars) {
      foreach (preg_split('//u', $chars, -1, PREG_SPLIT_NO_EMPTY) as $c) { $map[$c] = $b; $map[mb_strtoupper($c, 'UTF-8')] = $b; }
    }
  }
  return mb_strtolower(strtr((string)$s, $map), 'UTF-8');
}
// every (scientific name, common name) pair in the database, of every location (the search picks species with it; the
// list it feeds is filtered by location): a walk over the index on (Com_Name, Sci_Name), one seek per pair, instead of
// GROUP BY over every detection (2026-10-23 run 3: 7 s at ten times the station's data, now some 30 ms)
function species_name_pairs() {
  $db = get_db();
  $cols = array();
  $r = $db->query("PRAGMA main.index_info('detections_Com_Name')");
  while ($r && ($x = $r->fetchArray(SQLITE3_ASSOC))) $cols[] = $x['name'];
  $sql = $cols === array('Com_Name', 'Sci_Name')
    ? "WITH RECURSIVE c(n) AS (SELECT MIN(Com_Name) FROM main.detections UNION ALL
         SELECT (SELECT MIN(Com_Name) FROM main.detections WHERE Com_Name > c.n) FROM c WHERE c.n IS NOT NULL),
       p(c, s) AS (SELECT n, (SELECT MIN(Sci_Name) FROM main.detections WHERE Com_Name = n) FROM c WHERE n IS NOT NULL UNION ALL
         SELECT p.c, (SELECT MIN(Sci_Name) FROM main.detections WHERE Com_Name = p.c AND Sci_Name > p.s) FROM p WHERE p.s IS NOT NULL)
       SELECT s, c FROM p WHERE s IS NOT NULL"
    : 'SELECT Sci_Name, Com_Name FROM main.detections GROUP BY Sci_Name, Com_Name';
  $out = array();
  $r = $db->query($sql);
  while ($r && ($x = $r->fetchArray(SQLITE3_NUM))) $out[] = $x;
  return $out;
}
// the common name of each species: the newest detection's (a label change shows at once), one index lookup per species
// instead of MAX(Com_Name) over every detection (2026-10-23 test run: 0.4 s at ten times the station's data)
function species_com_names($scis) {
  $st = get_db()->prepare('SELECT Com_Name FROM detections WHERE Sci_Name = :s ORDER BY Date DESC LIMIT 1');
  $out = array();
  foreach ($scis as $s) {
    $st->bindValue(':s', $s);
    $r = $st->execute();
    $row = $r ? $r->fetchArray(SQLITE3_NUM) : false;
    $out[$s] = $row ? $row[0] : $s;
    $st->reset();
  }
  return $out;
}

function get_summary() {
  return cached_value('summary', 60, 'get_summary_now');
}
function get_summary_now() {
  $db = get_db();
  // detections reviewed "not this bird" are left out of every total (owner 2026-10-09)
  $nr = not_rejected_sql();
  // every detection minus the rejected ones: NOT IN on every row cost 1-1.6 s at ten times the station's data, this 0.17 s
  // (2026-10-23 test run)
  $statement = $db->prepare($nr === '' ? 'SELECT COUNT(*) FROM detections WHERE 1'
    : "SELECT (SELECT COUNT(*) FROM detections) - (SELECT COUNT(*) FROM detections WHERE File_Name IN (SELECT File_Name FROM detection_reviews WHERE Verdict = 'no')) AS \"COUNT(*)\"");
  ensure_db_ok($statement);
  $result = $statement->execute();
  $totalcount = $result->fetchArray(SQLITE3_ASSOC);

  $statement2 = $db->prepare('SELECT COUNT(*) FROM detections WHERE Date == DATE(\'now\', \'localtime\')' . $nr);
  ensure_db_ok($statement2);
  $result2 = $statement2->execute();
  $todaycount = $result2->fetchArray(SQLITE3_ASSOC);

  $statement3 = $db->prepare('SELECT COUNT(*) FROM detections WHERE Date == Date(\'now\', \'localtime\') AND TIME >= TIME(\'now\', \'localtime\', \'-1 hour\')' . $nr);
  ensure_db_ok($statement3);
  $result3 = $statement3->execute();
  $hourcount = $result3->fetchArray(SQLITE3_ASSOC);

  $statement5 = $db->prepare('SELECT COUNT(DISTINCT(Sci_Name)) FROM detections WHERE Date == Date(\'now\',\'localtime\')' . $nr);
  ensure_db_ok($statement5);
  $result5 = $statement5->execute();
  $todayspeciestally = $result5->fetchArray(SQLITE3_ASSOC);

  $statement6 = $db->prepare('SELECT COUNT(DISTINCT(Sci_Name)) FROM detections WHERE 1' . $nr);
  ensure_db_ok($statement6);
  $result6 = $statement6->execute();
  $totalspeciestally = $result6->fetchArray(SQLITE3_ASSOC);

  $ret = [
    'totalcount' => $totalcount['COUNT(*)'],
    'todaycount' => $todaycount['COUNT(*)'],
    'hourcount' => $hourcount['COUNT(*)'],
    'speciestally' => $todayspeciestally['COUNT(DISTINCT(Sci_Name))'],
    'totalspeciestally' => $totalspeciestally['COUNT(DISTINCT(Sci_Name))']
  ];
  return $ret;
}

class ImageProvider {

  protected $db = null;
  protected $db_path = null;
  protected $db_reset = false;
  protected $context = null;

  public function __construct() {
    $this->set_db();
    // short timeout: a station in the field has no internet and the page waits on each species picture
    $opts = ['http' => ['header' => "User-Agent: BirdSuite", 'timeout' => 5]];
    $this->context = stream_context_create($opts);
  }

  public function get_image($sci_name) {
    $image = $old = $this->get_image_from_db($sci_name);
    if ($image !== false) {
      $now = new DateTime();
      $datetime = DateTime::createFromFormat("Y-m-d", $image['date_created']);
      $interval = $now->diff($datetime);
      $expire_days = rand(15, 25);
      if ($interval->days > $expire_days) {
        $image = false;
      }
    }
    if ($image === false) {
      $this->get_from_source($sci_name);
      $image = $this->get_image_from_db($sci_name);
      // no answer (a station without internet): the old picture is kept for another period instead of asking again
      // on every page load, each time waiting for the timeout
      if ($old !== false && $image !== false && $image['date_created'] === $old['date_created'] && $image['image_url'] === $old['image_url']) {
        $st = $this->db->prepare('UPDATE images SET date_created = DATE("now") WHERE sci_name == :sci_name');
        $st->bindValue(':sci_name', $sci_name);
        @$st->execute();
      }
    }
    return $image;
  }

  public function is_reset() {
    return $this->db_reset;
  }

  protected function get_json($url) {
    return json_decode(file_get_contents($url, false, $this->context), true);
  }

  protected function set_db() {
    try {
      if ($this->db === null) {
        $db = new SQLite3($this->db_path, SQLITE3_OPEN_READWRITE);
        $this->db = $db;
      }
    } catch (Exception $ex) {
      $this->create_tables();
    }
    $this->db->busyTimeout(1000);
  }

  protected function create_tables() {
    $tbl_def = "CREATE TABLE images (sci_name VARCHAR(63) NOT NULL PRIMARY KEY, com_en_name VARCHAR(63) NOT NULL, image_url TEXT NOT NULL, title TEXT NOT NULL, id TEXT NOT NULL UNIQUE, author_url TEXT NOT NULL, license_url TEXT NOT NULL, date_created DATE)";
    $db = new SQLite3($this->db_path);
    $db->exec($tbl_def);
    $db->exec('CREATE TABLE source (ID INTEGER PRIMARY KEY, email VARCHAR(63), uid VARCHAR(63), date_created DATE)');
    $this->db_reset = true;
    $this->db = $db;
  }

  protected function delete_image_from_db($sci_name) {
    $statement0 = $this->db->prepare('DELETE FROM images WHERE sci_name == :sci_name');
    $statement0->bindValue(':sci_name', $sci_name);
    $statement0->execute();
  }

  protected function get_image_from_db($sci_name) {
    $statement0 = $this->db->prepare('SELECT sci_name, com_en_name, image_url, title, id, author_url, license_url, date_created FROM images WHERE sci_name == :sci_name');
    $statement0->bindValue(':sci_name', $sci_name);
    $result = $statement0->execute();
    $row = $result->fetchArray(SQLITE3_ASSOC);
    return $row;
  }

  protected function set_image_in_db($sci_name, $com_en_name, $image_url, $title, $id, $author_url, $license_url) {
    $statement0 = $this->db->prepare("INSERT OR REPLACE INTO images VALUES (:sci_name, :com_en_name, :image_url, :title, :id, :author_url, :license_url, DATE(\"now\"))");
    $statement0->bindValue(':sci_name', $sci_name);
    $statement0->bindValue(':com_en_name', $com_en_name);
    $statement0->bindValue(':image_url', $image_url);
    $statement0->bindValue(':title', $title);
    $statement0->bindValue(':id', $id);
    $statement0->bindValue(':author_url', $author_url);
    $statement0->bindValue(':license_url', $license_url);
    $statement0->execute();
  }
}

class Flickr extends ImageProvider {

  protected $db_path = __ROOT__ . '/scripts/flickr.db';

  private $flickr_api_key = null;
  private $args = "&license=2%2C3%2C4%2C5%2C6%2C9&orientation=square,portrait";
  private $blacklisted_ids = [];
  private $licenses_urls = [];
  private $flickr_email = null;
  private $comnameprefix = "%20bird";

  public function __construct() {
    $this->set_db();
    // the Flickr calls wait at most 10 s (offline or a slow network must not hang the page for the default 60 s)
    $this->context = stream_context_create(['http' => ['header' => "User-Agent: BirdSuite", 'timeout' => 10]]);

    $blacklisted = get_home() . "/BirdNET-Pi/config/scripts/blacklisted_images.txt";
    if (file_exists($blacklisted)) {
      $blacklisted_file = file($blacklisted);
      if ($blacklisted_file) {
        $this->blacklisted_ids = array_map('trim', $blacklisted_file);
      }
    }
    $this->flickr_api_key = get_config()["FLICKR_API_KEY"];
    $this->flickr_email = get_config()["FLICKR_FILTER_EMAIL"];
    $source = $this->get_uid_from_db();
    if ($source['email'] !== $this->flickr_email) {
      // reset the DB
      $this->db->exec("DROP TABLE images;");
      $this->create_tables();
      if (!empty($this->flickr_email)) {
        $source = $this->get_uid_from_db();
        if ($source['email'] !== $this->flickr_email) {
          $this->get_uid_from_flickr();
          $source = $this->get_uid_from_db();
        }
      } else {
        $this->set_uid_in_db("");
      }
    }
    if (!empty($this->flickr_email)) {
      $this->args = "&user_id=" . $source['uid'];
      $this->comnameprefix = "";
    }
  }

  public function get_image($sci_name) {
    $image = parent::get_image_from_db($sci_name);
    if ($image !== false && in_array($image['id'], $this->blacklisted_ids)) {
      $image = false;
      $this->delete_image_from_db($sci_name);
    }
    if ($image === false) {
      $this->get_from_source($sci_name);
      $image = $this->get_image_from_db($sci_name);
    }
    if ($image === false)
      return false;
    // external link to photo
    $photos_url = str_replace('/people/', '/photos/', $image['author_url'] . '/' . $image['id']);
    $image['photos_url'] = $photos_url;
    return $image;
  }

  private function get_from_source($sci_name) {
    $engname = get_com_en_name($sci_name);

    $flickrjson = json_decode(file_get_contents("https://www.flickr.com/services/rest/?method=flickr.photos.search&api_key=" . $this->flickr_api_key . "&text=" . str_replace(" ", "%20", $engname) . $this->comnameprefix . "&sort=relevance" . $this->args . "&per_page=5&media=photos&format=json&nojsoncallback=1", false, $this->context), true)["photos"]["photo"];
    // could be null!!
    // Find the first photo that is not blacklisted or is not the specific blacklisted id
    $photo = null;
    foreach ($flickrjson as $flickrphoto) {
      if ($flickrphoto["id"] !== "4892923285" && !in_array($flickrphoto["id"], $this->blacklisted_ids)) {
        $photo = $flickrphoto;
        break;
      }
    }

    if ($photo === null)
      return;

    $license_response = $this->get_json("https://api.flickr.com/services/rest/?method=flickr.photos.getInfo&api_key=" . $this->flickr_api_key . "&photo_id=" . $photo["id"] . "&format=json&nojsoncallback=1");
    $license_id = $license_response["photo"]["license"];
    $license_url = $this->get_license_url($license_id);

    $authorlink = "https://flickr.com/people/" . $photo["owner"];
    $imageurl = 'https://farm' . $photo["farm"] . '.static.flickr.com/' . $photo["server"] . '/' . $photo["id"] . '_' . $photo["secret"] . '.jpg';

    $this->set_image_in_db($sci_name, $engname, $imageurl, $photo["title"], $photo["id"], $authorlink, $license_url);
  }

  private function get_license_url($id) {
    if (empty($this->licenses_urls)) {
      $licenses_url = "https://api.flickr.com/services/rest/?method=flickr.photos.licenses.getInfo&api_key=" . $this->flickr_api_key . "&format=json&nojsoncallback=1";
      $licenses_response = $this->get_json($licenses_url);
      $licenses_data = $licenses_response["licenses"]["license"];
      foreach ($licenses_data as $license) {
        $license_id = $license["id"];
        $license_url = $license["url"];
        $this->licenses_urls[$license_id] = $license_url;
      }
    }
    return $this->licenses_urls[$id];
  }

  public function get_uid_from_db() {
    $statement0 = $this->db->prepare('SELECT email, uid, date_created FROM source');
    $result = $statement0->execute();
    $row = $result->fetchArray(SQLITE3_ASSOC);
    return $row;
  }

  private function set_uid_in_db($uid) {
    $statement0 = $this->db->prepare("INSERT OR REPLACE INTO source VALUES (1, :email, :uid, DATE(\"now\"))");
    $statement0->bindValue(':email', $this->flickr_email);
    $statement0->bindValue(':uid', $uid);
    $result = $statement0->execute();
    $row = $result->fetchArray(SQLITE3_ASSOC);
    return $row;
  }

  private function get_uid_from_flickr() {
    $uid = json_decode(file_get_contents("https://www.flickr.com/services/rest/?method=flickr.people.findByEmail&api_key=" . $this->flickr_api_key . "&find_email=" . $this->flickr_email . "&format=json&nojsoncallback=1", false, $this->context), true)["user"]["nsid"];
    $this->set_uid_in_db($uid);
  }
}

class Wikipedia extends ImageProvider {

  protected $db_path = __ROOT__ . '/scripts/wikipedia.db';
  // a standard Wikimedia thumbnail width (other widths answer 400 since 2026); the pictures are shown at 90-300 px
  const THUMB_WIDTH = 330;

  protected function get_from_source($sci_name) {
    $page_title = str_replace(' ', '_', $sci_name);
    $data = $this->get_json("https://en.wikipedia.org/api/rest_v1/page/summary/$page_title");
    if ($data == false or !isset($data['originalimage']))
      return;

    // the file name without the "?utm_..." query; "originalimage" may itself be a thumbnail (.../thumb/<file>/3840px-<file>)
    $image_url = strtok($data['originalimage']['source'], '?');
    $image_name = substr($image_url, strrpos($image_url, '/') + 1);
    if (strpos($image_url, '/thumb/') !== false) {
      $parts = explode('/', $image_url);
      $image_name = $parts[count($parts) - 2];
    }
    // iiurlwidth: Commons answers with the URL of a small thumbnail (2026-10-23 test run: the stored originals and
    // 3840 px thumbnails were up to 45 MB, downloaded on every Now / Kiosk load for a 90 px picture)
    $metadata = $this->get_json("https://commons.wikimedia.org/w/api.php?action=query&titles=File:$image_name&prop=imageinfo&iiprop=extmetadata|size|url&iiurlwidth=" . self::THUMB_WIDTH . "&format=json");
    if ($metadata == false or !isset($metadata['query']['pages']))
      return;

    $title = $data['title'];

    foreach ($metadata['query']['pages'] as $page) {
      $details = $page['imageinfo']['0']['extmetadata'];
      $author = $details['Artist']['value'];
      $matches = [];
      if (preg_match('/href="(http\S*)"/', $author, $matches)) {
        $author_url = $matches[1];
      } else {
        $author_url = $this->get_external_link($image_url);
      }
      if (isset($details['LicenseUrl'])) {
        $license_url = $details['LicenseUrl']['value'];
      } else {
        $license_url = $this->get_external_link($image_url);
      }
      if (!empty($page['imageinfo'][0]['thumburl'])) {
        $image_url = strtok($page['imageinfo'][0]['thumburl'], '?');
      } elseif (isset($data['thumbnail']['source'])) {
        $image_url = strtok($data['thumbnail']['source'], '?');
      }
    }

    // a species the model's English labels do not have (a CBRO name such as Troglodytes musculus): the title of its
    // Wikipedia page; the column is NOT NULL, a null lost the picture without a word (owner 2026-10-30)
    $engname = get_com_en_name($sci_name) ?? $title;

    //                     $sci_name, $com_en_name, $image_url, $title, $id, $author_url, $license_url
    $this->set_image_in_db($sci_name, $engname, $image_url, $title, $sci_name, $author_url, $license_url);
  }

  public function get_image($sci_name) {
    $image = parent::get_image($sci_name);
    if ($image === false)
      return false;

    $image['photos_url'] = $this->get_external_link($image['image_url']);
    return $image;
  }

  private function get_external_link($image_url) {
    if (strpos($image_url, '/commons/thumb/') !== false) {
      $parts = explode('/', $image_url);
      $image_name = $parts[count($parts) - 2];
    } else {
      $image_name = substr($image_url, strrpos($image_url, '/') + 1);
    }
    $photo_url = "https://en.wikipedia.org/wiki/File:$image_name";
    return $photo_url;
  }
}

// WikiAves page of a Brazilian bird, from its CBRO name (pt_BR): "Sanhaço-cinzento" -> sanhaco-cinzento.
// Only for species of the Brazilian state lists (model/include_lists/BR-*.txt): the pt_BR file also
// names non-Brazilian birds (Portugal or English names), which have no WikiAves page.
function get_wikiaves_url($sciname) {
  static $brazilian = null, $cbro = null;
  if ($brazilian === null) {
    $brazilian = array();
    foreach (glob(__ROOT__ . '/model/include_lists/BR-*.txt') as $list) {
      foreach (file($list, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $brazilian[explode('_', $line)[0]] = true;
      }
    }
    $cbro = json_decode((string)@file_get_contents(__ROOT__ . '/model/l18n/labels_pt_BR.json'), true) ?: array();
  }
  if (!isset($brazilian[$sciname]) || empty($cbro[$sciname])) return '';
  $slug = strtr(mb_strtolower($cbro[$sciname], 'UTF-8'), array('á' => 'a', 'à' => 'a', 'â' => 'a', 'ã' => 'a', 'ä' => 'a',
    'é' => 'e', 'ê' => 'e', 'è' => 'e', 'í' => 'i', 'ï' => 'i', 'ó' => 'o', 'ô' => 'o', 'õ' => 'o', 'ö' => 'o',
    'ú' => 'u', 'ü' => 'u', 'ç' => 'c', 'ñ' => 'n'));
  $slug = trim(preg_replace('/[^a-z0-9]+/', '-', $slug), '-');
  return $slug === '' ? '' : "https://www.wikiaves.com.br/wiki/$slug";
}

// Review loop: verdict of one detection file ('' = not reviewed), and the SQL that leaves rejected
// detections ("not this bird") out of a query on detections — empty while the table does not exist yet
function review_verdicts() {
  static $verdicts = null;
  if ($verdicts === null) {
    $verdicts = array();
    $db = get_db();
    if ($db->querySingle("SELECT 1 FROM sqlite_master WHERE type='table' AND name='detection_reviews'")) {
      $hasReason = (bool)$db->querySingle("SELECT 1 FROM pragma_table_info('detection_reviews') WHERE name = 'Reason'");
      $res = $db->query('SELECT File_Name, Verdict' . ($hasReason ? ", COALESCE(Reason, '')" : ", ''") . ' FROM detection_reviews');
      while ($res && ($r = $res->fetchArray(SQLITE3_NUM))) $verdicts[$r[0]] = array($r[1], $r[2]);
    }
  }
  return $verdicts;
}
// The one Review button of the interface: always reviewDetection(this) (static/review-player.js), no local variants
function validate_button($file, $verdict = null, $positioned = false) {
  $reason = review_reason(basename($file));
  if ($verdict === null) $verdict = review_verdict(basename($file));
  // the older "not this bird" without a cause, "another bird" or "unknown" count as Review later (Validation): shown so
  if ($verdict === 'no' && in_array($reason, array('', 'other bird', 'unknown'), true)) $verdict = 'unsure';
  // the button names the answer chosen (owner 2026-10-22): the cause in red, Yes / Changed in green, Unknown bird in grey
  $causes = array('dog' => t('Dog'), 'human' => t('Human'), 'engine' => t('Engine'), 'noise' => t('Noise'), 'ignore' => t('Ignore'), 'insect' => t('Insect'),
                  'rain' => t('Rain / wind'), 'other bird' => t('Another bird (unknown)'), 'unknown' => t('Unknown'), 'mechanical' => t('Mechanical / noise'));
  $labels = array('' => t('Review'), 'yes' => '&#10003; ' . ($reason === 'changed' ? t('Changed') : t('Yes')),
                  'no' => '&#10007; ' . htmlspecialchars($causes[$reason] ?? t('Change species')), 'unsure' => '? ' . t('Review later'));
  $titles = array('' => t('Is this the bird? Review this detection'), 'yes' => t('Reviewed: yes, this bird (species confirmed, clip protected from purge)'),
                  'no' => t('Reviewed: not this species (a cause or another species)'), 'unsure' => t('Reviewed: review later'));
  return '<button type="button" class="validatebtn v-' . ($verdict ?: 'none') . ($positioned ? ' positioned' : '') . '" title="' . htmlspecialchars($titles[$verdict], ENT_QUOTES)
    . '" data-file="' . htmlspecialchars($file, ENT_QUOTES) . '" onclick="reviewDetection(this)">' . $labels[$verdict] . '</button>';
}
// Removed detections ("Remove detection", owner 2026-10-09): files in ~/BirdSongs/Extracted/Removed/<date>/<species>/,
// lines in deleted_detections, until they are deleted by hand in Species › Purge Files
// The review causes whose audio Purge Files may remove while the detection keeps counting (owner 2026-10-22)
const PURGE_CAUSES = array('dog', 'human', 'engine', 'noise', 'ignore', 'insect', 'rain', 'mechanical');  // with the older causes: all of Ignored

// The clips protected from every purge (config/scripts/disk_check_exclude.txt: <date>/<species>/<file> lines, the
// automatic top N and confirmed ones plus the manual locks) as a set
function locked_clips() {
  static $set = null;
  if ($set === null) $set = array_flip(array_map('trim', @file(BS_CONFIG . '/scripts/disk_check_exclude.txt', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: array()));
  return $set;
}

// The excluded species that have detections (every location), as a quoted SQL list ('' when none): the species
// index lists the detected species at once, and an IN of the few found is searched by it (an IN of the whole exclude
// list, thousands of names, made SQLite read every detection)
function excluded_with_detections($db, $excl) {
  $found = array();
  $res = $db->query('SELECT DISTINCT Sci_Name FROM main.detections');
  while ($res && ($r = $res->fetchArray(SQLITE3_NUM))) if (isset($excl[$r[0]])) $found[] = "'" . SQLite3::escapeString($r[0]) . "'";
  return implode(',', $found);
}

// The excluded species (exclude_species_list.txt) as a set of scientific names
function excluded_species() {
  $lines = @file(BS_LISTS . '/exclude_species_list.txt', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: array();
  return array_flip(array_map(function ($l) { return explode('_', trim($l), 2)[0]; }, $lines));
}

// Detections of a query whose clip is still on disk under Extracted/<base> (Purge Files lists only what can still be
// purged: By_Date for Others, Purge for the causes staged by purge_stage.py), as
// [key => [com, sci, cause, n]]; the rows carry Date, Com_Name, File_Name, Sci_Name and optionally Reason
function purge_rows($res, $keep = null, $base = 'By_Date') {
  $home = get_home(); $out = array();
  while ($res && ($r = $res->fetchArray(SQLITE3_ASSOC))) {
    if ($keep && !$keep($r)) continue;
    $f = $home . '/BirdSongs/Extracted/' . $base . '/' . $r['Date'] . '/' . species_folder($r['Sci_Name'] ?? '', $r['Com_Name'], $r['File_Name']) . '/' . $r['File_Name'];
    if (!file_exists($f)) continue;
    $k = ($r['Reason'] ?? '') . '|' . $r['Sci_Name'];
    if (!isset($out[$k])) $out[$k] = array('com' => $r['Com_Name'], 'sci' => $r['Sci_Name'], 'cause' => $r['Reason'] ?? '', 'n' => 0);
    $out[$k]['n']++;
  }
  uasort($out, function ($a, $b) { return $b['n'] - $a['n']; });
  return $out;
}

// The audio waiting to be purged of the detections a location keeps counting (owner 2026-10-24, the Ignored column of
// Tools › Data): answered Dog, Human, Engine, Noise or Ignore (moved to Extracted/Purge at night) and the excluded
// species (in By_Date; not the clips answered Yes nor the protected ones). $lw = an SQL condition on detections or ''.
function purge_audio_rows($db, $lw = '') {
  $and = $lw !== '' ? ' AND ' . $lw : '';
  $out = array('causes' => array(), 'others' => array());
  if (!$db->querySingle("SELECT 1 FROM sqlite_master WHERE type='table' AND name='detection_reviews'")) return $out;
  $out['causes'] = purge_rows($db->query("SELECT d.Date, d.Com_Name, d.File_Name, d.Sci_Name, r.Reason FROM main.detections d JOIN detection_reviews r ON r.File_Name = d.File_Name
    WHERE r.Verdict = 'no' AND r.Reason IN ('" . implode("','", PURGE_CAUSES) . "')" . $and), null, 'Purge');
  $excl = excluded_species();
  $excl_found = $excl ? excluded_with_detections($db, $excl) : '';
  if ($excl_found) {
    $locked = locked_clips();
    $out['others'] = purge_rows($db->query("SELECT Date, Com_Name, File_Name, Sci_Name FROM main.detections WHERE Sci_Name IN ("
      . $excl_found . ") AND File_Name NOT IN (SELECT File_Name FROM detection_reviews WHERE Verdict = 'yes')" . $and),
      function ($r) use ($excl, $locked) { return isset($excl[$r['Sci_Name']]) && !isset($locked[$r['Date'] . '/' . species_folder($r['Sci_Name'] ?? '', $r['Com_Name'], $r['File_Name']) . '/' . $r['File_Name']]); });
  }
  return $out;
}

function deleted_dir() {
  return get_home() . '/BirdSongs/Extracted/Removed';
}
function deleted_table($rw) {
  $rw->exec("CREATE TABLE IF NOT EXISTS deleted_detections (Date DATE, Time TIME, Sci_Name VARCHAR(100) NOT NULL, Com_Name VARCHAR(100) NOT NULL,
    Confidence FLOAT, Lat FLOAT, Lon FLOAT, Cutoff FLOAT, Week INT, Sens FLOAT, Overlap FLOAT, File_Name VARCHAR(100) NOT NULL, Deleted_At TEXT,
    Loc_Thresh FLOAT, Rec_Length INT, Sp_Override FLOAT, Location INTEGER)");
  // settings in force at detection time (owner 2026-10-09), also on a deleted_detections table made before them
  $have = array();
  $res = $rw->query('PRAGMA table_info(deleted_detections)');
  while ($res && ($c = $res->fetchArray(SQLITE3_ASSOC))) $have[$c['name']] = true;
  foreach (array('Loc_Thresh' => 'FLOAT', 'Rec_Length' => 'INT', 'Sp_Override' => 'FLOAT', 'Location' => 'INTEGER') as $col => $kind) {
    if (!isset($have[$col])) $rw->exec("ALTER TABLE deleted_detections ADD COLUMN $col $kind");
  }
  // Purge Files counts per species (2026-10-23 test run)
  $rw->exec('CREATE INDEX IF NOT EXISTS deleted_Sci_Name ON deleted_detections (Sci_Name)');
}
// how many deleted detections wait for a wipe: one species, or every species when $sci is null
function deleted_count($sci = null) {
  $db = get_db();
  if (!$db->querySingle("SELECT 1 FROM sqlite_master WHERE type='table' AND name='deleted_detections'")) return 0;
  if ($sci === null) return intval($db->querySingle('SELECT COUNT(*) FROM deleted_detections'));
  $st = $db->prepare('SELECT COUNT(*) FROM deleted_detections WHERE Sci_Name = :s');
  $st->bindValue(':s', $sci);
  $r = $st->execute()->fetchArray(SQLITE3_NUM);
  return intval($r[0]);
}

// The reviews table (created on first use; Reason added 2026-10-09 for the "not this bird" causes)
function review_table($rw) {
  $rw->exec("CREATE TABLE IF NOT EXISTS detection_reviews (File_Name VARCHAR(100) PRIMARY KEY, Sci_Name VARCHAR(100), Com_Name VARCHAR(100), Date DATE, Confidence FLOAT, Verdict TEXT NOT NULL CHECK (Verdict IN ('yes','no','unsure')), Reviewed_At TEXT, Reason TEXT)");
  $has = false;
  $res = $rw->query("PRAGMA table_info(detection_reviews)");
  while ($res && ($c = $res->fetchArray(SQLITE3_ASSOC))) if ($c['name'] === 'Reason') $has = true;
  if (!$has) $rw->exec("ALTER TABLE detection_reviews ADD COLUMN Reason TEXT");
  // Change species (owner 2026-10-22): the species and confidence the detection had before, so the precision of that
  // species counts it as a false detection (species page, Precision)
  $cols = array();
  $res = $rw->query("PRAGMA table_info(detection_reviews)");
  while ($res && ($c = $res->fetchArray(SQLITE3_ASSOC))) $cols[$c['name']] = 1;
  if (!isset($cols['Orig_Sci'])) $rw->exec("ALTER TABLE detection_reviews ADD COLUMN Orig_Sci VARCHAR(100)");
  if (!isset($cols['Orig_Com'])) $rw->exec("ALTER TABLE detection_reviews ADD COLUMN Orig_Com VARCHAR(100)");
  // the "not this bird" list read by almost every count and list (an automatic index was built on each statement) and
  // the latest answers of Curation (2026-10-23 test run)
  $rw->exec('CREATE INDEX IF NOT EXISTS reviews_Verdict ON detection_reviews (Verdict, File_Name)');
  $rw->exec('CREATE INDEX IF NOT EXISTS reviews_Reviewed_At ON detection_reviews (Reviewed_At)');
}

// Attributes of one item of a review list (review player, static/review-player.js)
// $sci: the scientific name; the player shows "Scientific - English (eBird / Clements)" under the title
// $row: the detection (Confidence, Cutoff, Sens, Overlap, Date): the player lists the analysis settings at the bottom
function review_item_attrs($file, $label, $sci = '', $row = null) {
  static $locked = null;
  $home = get_home();
  if ($locked === null) {
    $list = $home . '/BirdNET-Pi/config/scripts/disk_check_exclude.txt';
    $locked = is_file($list) ? array_flip(file($list, FILE_IGNORE_NEW_LINES)) : array();
  }
  $shifted = file_exists($home . '/BirdSongs/Extracted/By_Date/shifted/' . $file);
  // the clip's folder for the station File Manager (its root is /home): <user>/BirdSongs/Extracted/By_Date/<date>/<species>
  $dir = basename($home) . '/BirdSongs/Extracted/By_Date/' . dirname($file);
  // data-spec: the stored picture, PNG while the day runs, JPEG once archived (owner 2026-10-22: Calibrate's older
  // samples showed no spectrogram, the player asked for <clip>.png only)
  // data-gone: the clip is no longer on disk (the nightly clean-up keeps N files per species, the line stays); the review
  // player skips it (owner 2026-10-22)
  $gone = !$shifted && !is_file($home . '/BirdSongs/Extracted/By_Date/' . $file);
  // a clip the nightly staging moved to Extracted/Purge (answered with a cause) still plays from there, so its answer
  // can be changed; play.php moves it back at once (2026-10-23 test run)
  $base = '/By_Date/';
  if ($gone && is_file($home . '/BirdSongs/Extracted/Purge/' . $file)) { $gone = false; $base = '/Purge/'; }
  // data-reason: the cause of the answer, so the player's Undo puts it back (owner 2026-10-22)
  $reason = review_reason(basename($file));
  return ' data-ri="1"' . ($gone ? ' data-gone="1"' : '') . ($reason !== '' ? ' data-reason="' . htmlspecialchars($reason, ENT_QUOTES) . '"' : '') . ' data-file="' . htmlspecialchars($file, ENT_QUOTES) . '" data-clip="' . htmlspecialchars(($shifted ? '/By_Date/shifted/' : $base) . $file, ENT_QUOTES)
    . '" data-spec="' . htmlspecialchars(clip_spectrogram($base . $file), ENT_QUOTES)
    . '" data-label="' . htmlspecialchars($label, ENT_QUOTES) . '" data-dir="' . htmlspecialchars($dir, ENT_QUOTES) . '"'
    . ($sci !== '' ? ' data-sci="' . htmlspecialchars($sci, ENT_QUOTES) . '" data-en="' . htmlspecialchars(get_english_name($sci), ENT_QUOTES) . '"' : '')
    . ($row !== null && isset($row['Date']) ? ' data-when="' . htmlspecialchars($row['Date'] . (isset($row['Time']) ? ' ' . $row['Time'] : ''), ENT_QUOTES) . '"' : '')
    . ($row !== null && isset($row['Com_Name']) ? ' data-com="' . htmlspecialchars($row['Com_Name'], ENT_QUOTES) . '"' : '')
    . ($row !== null ? ' data-params="' . htmlspecialchars(json_encode(review_params($sci, $row)), ENT_QUOTES) . '"' : '')
    . ' data-locked="' . (isset($locked[$file]) ? 1 : 0) . '" data-shifted="' . ($shifted ? 1 : 0) . '"';
}

// The standard detection list (owner 2026-10-09): Now (list view) and the species page. $rows: detections with Date,
// Time, Com_Name, Sci_Name, Confidence, Cutoff, Sens, Overlap, File_Name. A click on a row (or its Review button)
// opens the review player there; it goes on down the list. $with_species = false on a species' own page.
// $with_date = false when every row is of the same day (All Detections › Today)
// $simple (Now › Simple, owner 2026-10-20): time, species, confidence, probability and review only
// The species name of a detection in a review list (owner 2026-10-26): the name opens the review player, the species
// card icon beside it the species page; the <a data-com data-sci data-en> keeps the name switch and the sort working
// ($file: the clip, for a row that is not a review item of its own)
// the species card icon (the species page) alone: a table gives it a narrow column of its own, headed by the icon
function species_card_icon($sci = null) {
  $svg = '<svg viewBox="0 0 24 24" width="15" height="15" aria-hidden="true" style="vertical-align:-2px"><rect x="3" y="2" width="18" height="20" rx="2.5" fill="none" stroke="currentColor" stroke-width="2"/><path d="M7 7h10M7 11h10M7 15h6" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>';
  if ($sci === null) return '<span title="' . t('Species card') . '" aria-label="' . t('Species card') . '">' . $svg . '</span>';
  return '<a class="spcard" href="views.php?view=Bird&amp;sci=' . rawurlencode($sci) . '" title="' . t('Open the species page') . '" aria-label="' . t('Species card') . '" onclick="event.stopPropagation()">' . $svg . '</a>';
}

function species_review_name($sci, $com, $bold = false, $file = null, $card = true) {
  $h = function ($s) { return htmlspecialchars((string)$s, ENT_QUOTES); };
  $call = $file === null ? 'reviewDetection(this)' : 'reviewDetection(' . $h(json_encode($file)) . ', this)';
  // the card first, then the name (owner 2026-10-26)
  return ($card ? species_card_icon($sci) . ' ' : '')
    . '<a href="#" onclick="event.preventDefault(); event.stopPropagation(); ' . $call . '" title="' . t('Listen and review') . '" data-com="' . $h($com)
    . '" data-sci="' . $h($sci) . '" data-en="' . $h(get_english_name($sci)) . '">' . ($bold ? '<b>' . $h($com) . '</b>' : $h($com)) . '</a>';
}

function detection_review_table($rows, $with_species = true, $with_date = true, $simple = false) {
  if ($simple) $with_date = false;
  static $profile = null;
  if ($profile === null) {
    $pj = is_file(__DIR__ . '/region_profile.json') ? json_decode(file_get_contents(__DIR__ . '/region_profile.json'), true) : null;
    $profile = $pj['data'] ?? array();
  }
  $h = function ($s) { return htmlspecialchars((string)$s, ENT_QUOTES); };
  // Manual Conf., Min. Prob. and Rec. Length are the values in force when the detection was made (columns Sp_Override,
  // Loc_Thresh, Rec_Length since 2026-10-09); older detections show — (owner: never today's setting on an old detection)
  $sf_now = get_config()['SF_THRESH'] ?? '';
  // the Location column (owner 2026-10-21): right of Rec. Length in the full view, and in every view while the table
  // shows several locations (Now with All locations)
  $with_loc = multi_location() && count(locations_load()) > 1 && !$simple; // never in the Simple view (owner 2026-10-21), nor in simple mode (US-125)
  // columns (owner 2026-10-09, standard order; 2026-10-21 Prob. after every confidence column): Conf., Min. Conf.,
  // Manual Conf., then Prob. and Min. Prob.; the analysis — Sigm. Sens., Overlap, Rec. Length; species picklist column
  // the species card in a narrow column of its own, first on the left, headed by its icon (owner 2026-10-26)
  $out = '<div class="reviewlist" data-review-list="1"><table class="list stdtable"><tr>'
    . ($with_species ? '<th class="spc" data-nosort style="width:1%;text-align:center">' . species_card_icon() . '</th>' : '') . ($with_date ? '<th>' . t('Date') . '</th>' : '') . '<th>' . t('Time') . '</th>'
    . ($with_species ? '<th><select class="namemode" title="' . t('Names shown') . '"><option value="com">' . t('Common name') . '</option><option value="sci">' . t('Scientific name') . '</option><option value="en">' . t('English name') . '</option></select></th>' : '')
    // short labels, the explanation on mouse over (owner 2026-10-21)
    . '<th class="num" title="' . t('Confidence: the score the model gave this sound for the species, 0 to 100%. It is kept when it reaches at least the minimum confidence (or the manual confidence of the species).') . '">' . t('Conf.') . '</th>'
    . ($simple ? '' : '<th class="num" title="' . t('Minimum confidence in force when it was detected') . '">' . t('Min. Conf.') . '</th>'
    . '<th class="num" title="' . t('Manual confidence of the species that replaced the minimum confidence when it was detected') . '">' . t('Manual Conf.') . '</th>')
    . '<th class="num" title="' . t('Probability of occurrence at the location: how likely the location model finds this species here in the week of the detection (as in Species Cards). Green when above the minimum probability of occurrence at the location, red when below.') . '">' . t('Prob.') . '</th>'
    . ($simple ? '' : '<th class="num" title="' . t('Minimum probability of occurrence at the location in force when it was detected') . '">' . t('Min. Prob.') . '</th>')
    . ($simple ? '' : '<th class="num" title="' . t('Sigmoid sensitivity of the analysis') . '">' . t('Sigm. Sens.') . '</th>'
    . '<th class="num" title="' . t('Overlap of the analysis (s)') . '">' . t('Overlap') . '</th>'
    . '<th class="num" title="' . t('Recording length in force when it was detected') . '">' . t('Rec. Length') . '</th>')
    . ($with_loc ? '<th>' . t('Location') . '</th>' : '')
    . '<th class="rv" data-nosort>' . t('Review') . '</th></tr>';
  foreach ($rows as $g) {
    $folder = species_folder($g['Sci_Name'] ?? '', $g['Com_Name'], $g['File_Name']);
    $file = $g['Date'] . '/' . $folder . '/' . $g['File_Name'];
    $t = strtotime($g['Date']);
    $w = min(48, (intval(date('n', $t)) - 1) * 4 + min(4, intdiv(intval(date('j', $t)) - 1, 7) + 1));
    $prob_v = isset($profile[$g['Sci_Name']]) ? floatval($profile[$g['Sci_Name']][$w - 1]) : null;
    $prob = $prob_v !== null ? fmt_num($prob_v * 100, 1) . '%' : '-';
    $label = $g['Com_Name'] . ', ' . $g['Date'] . ' ' . $g['Time'] . ', ' . round($g['Confidence'] * 100) . '%';
    $card = $with_species ? '<td class="spc" style="text-align:center">' . species_card_icon($g['Sci_Name']) . '</td>' : '';
    $species = $with_species ? '<td>' . species_review_name($g['Sci_Name'], $g['Com_Name'], false, null, false) . '</td>' : '';
    $out .= '<tr class="rvrow"' . review_item_attrs($file, $label, $g['Sci_Name'], $g) . ' onclick="if (!event.target.closest(\'a,button\')) reviewDetection(this)" title="' . t('Listen and review') . '">' . $card
      . ($with_date ? '<td class="nw">' . $h($g['Date']) . '</td>' : '') . '<td class="nw">' . $h($g['Time']) . '</td>' . $species
      . '<td class="num">' . round($g['Confidence'] * 100) . '%</td>'
      . ($simple ? '' : '<td class="num">' . (($g['Cutoff'] ?? null) !== null ? round($g['Cutoff'] * 100) . '%' : '') . '</td>'
      . '<td class="num">' . (($g['Sp_Override'] ?? null) !== null ? round(floatval($g['Sp_Override']) * 100) . '%' : '-') . '</td>')
      . '<td class="num' . ($prob_v !== null ? ($prob_v >= floatval($g['Loc_Thresh'] ?? $sf_now) ? ' pgood' : ' pbad') : '') . '">' . $prob . '</td>'
      . ($simple ? '' : '<td class="num">' . (($g['Loc_Thresh'] ?? null) !== null ? round(floatval($g['Loc_Thresh']) * 100) . '%' : '-') . '</td>')
      . ($simple ? '' : '<td class="num">' . $h($g['Sens'] ?? '') . '</td>'
      . '<td class="num">' . (($g['Overlap'] ?? '') === '' ? '' : $h($g['Overlap']) . ' s') . '</td>'
      . '<td class="num">' . (($g['Rec_Length'] ?? null) !== null ? intval($g['Rec_Length']) . ' s' : '-') . '</td>')
      . ($with_loc ? '<td class="nw">' . $h(detection_location_name($g)) . '</td>' : '')
      . '<td class="rv">' . validate_button($file) . '</td></tr>';
  }
  return $out . '</table></div>';
}

// Analysis settings of one detection for the review player: confidence, the minimum confidence it was detected
// with, the species override (US-48), sigmoid sensitivity, overlap, the location threshold (station setting, current)
// and the location model probability of the species in that week
// the species the active model can detect: [scientific name => common name in DATABASE_LANG] (BirdNET-Plus models:
// <MODEL>_Labels.txt, the others model/labels.txt; names from model/l18n/labels_<lang>.json, else the label's own)
function active_model_labels() {
  static $out = null;
  if ($out !== null) return $out;
  $cfg = get_config();
  $dir = get_home() . '/BirdNET-Pi/model/';
  $file = (strpos($cfg['MODEL'] ?? '', 'BirdNET-Plus') === 0 && is_file($dir . $cfg['MODEL'] . '_Labels.txt'))
    ? $dir . $cfg['MODEL'] . '_Labels.txt' : $dir . 'labels.txt';
  $lang = json_decode((string)@file_get_contents($dir . 'l18n/labels_' . ($cfg['DATABASE_LANG'] ?? 'en') . '.json'), true) ?: array();
  $out = array();
  foreach (@file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: array() as $l) {
    $p = explode('_', trim($l), 2);
    $out[$p[0]] = $lang[$p[0]] ?? ($p[1] ?? $p[0]);
  }
  return $out;
}

// what a label is and where it lives (model/species_info.csv, built once from GBIF by scripts/build_species_info.py,
// plus model/species_info_local.csv, the labels the station looked up itself):
// array(type, region) — type bird / mammal / amphibian / reptile / insect / other_animal / domestic / noise / unknown,
// region 'Global' or continents '+' separated (+ 'Ocean'); without the table: the class column of the BirdNET-Plus
// labels CSV (else the genus' class) and no region
function species_info($sci) {
  static $info = null, $cls = null, $genus = null;
  if ($info === null) {
    $info = array();
    // the shipped table, then the station's own additions (scripts/update_species_info.sh)
    foreach (array('species_info.csv', 'species_info_local.csv') as $f) {
      if (($h = @fopen(get_home() . '/BirdNET-Pi/model/' . $f, 'r')) === false) continue;
      fgetcsv($h, 0, ';', '"', '');
      while (($r = fgetcsv($h, 0, ';', '"', '')) !== false) if (isset($r[2])) $info[$r[0]] = array($r[1], $r[2]);
      fclose($h);
    }
  }
  if (isset($info[$sci])) return $info[$sci];
  if ($cls === null) {
    $cls = array(); $genus = array();
    foreach (glob(get_home() . '/BirdNET-Pi/model/BirdNET-Plus_*_Labels.csv') ?: array() as $f) {
      if (($h = fopen($f, 'r')) === false) continue;
      $head = array_map(function ($x) { return trim($x, "\xEF\xBB\xBF \t"); }, fgetcsv($h, 0, ';', '"', '') ?: array());
      $si = array_search('sci_name', $head); $ci = array_search('class', $head);
      while ($si !== false && $ci !== false && ($r = fgetcsv($h, 0, ';', '"', '')) !== false) {
        if (!isset($r[$si], $r[$ci])) continue;
        $cls[$r[$si]] = $r[$ci];
        $genus[strtok($r[$si], ' ')] = $genus[strtok($r[$si], ' ')] ?? $r[$ci];
      }
      fclose($h);
    }
  }
  $c = $cls[$sci] ?? ($genus[strtok($sci, ' ')] ?? '');
  return array(array('Aves' => 'bird', 'Mammalia' => 'mammal', 'Amphibia' => 'amphibian', 'Insecta' => 'insect')[$c] ?? 'unknown', '');
}

// group of a species for the list filters (owner 2026-10-09): birds, mammals, amphibians, insects, domestic, noise or
// others (reptiles, other animals, unknown)
function species_group($sci) {
  $t = species_info($sci)[0];
  return array('bird' => 'birds', 'mammal' => 'mammals', 'amphibian' => 'amphibians', 'insect' => 'insects',
    'domestic' => 'domestic', 'noise' => 'noise')[$t] ?? 'others';
}

// field-guide position of a species (model/taxonomy_order.csv, eBird/Clements TAXON_ORDER, scripts/build_taxonomy_order.py);
// non-birds and unknown names after every bird
function taxon_order($sci) {
  static $t = null;
  if ($t === null) {
    $t = array();
    if (($h = @fopen(get_home() . '/BirdNET-Pi/model/taxonomy_order.csv', 'r')) !== false) {
      fgetcsv($h, 0, ';', '"', '');
      while (($r = fgetcsv($h, 0, ';', '"', '')) !== false) if (isset($r[1])) $t[$r[0]] = floatval($r[1]);
      fclose($h);
    }
  }
  return $t[$sci] ?? 999999;
}

// location model probability (0..1) of a species in the week of a date (region_profile.json, 48 weeks: four per month,
// as utils/classes.py week48); null when the species is not in the profile
// every species of the location profile with its probability in the week of $date (Now › Low Prob in SQL)
function location_profile_week($date) {
  static $pj = false;
  if ($pj === false) $pj = is_file(__DIR__ . '/region_profile.json') ? json_decode(file_get_contents(__DIR__ . '/region_profile.json'), true) : null;
  $t = strtotime($date ?: date('Y-m-d'));
  $w = min(48, (intval(date('n', $t)) - 1) * 4 + min(4, intdiv(intval(date('j', $t)) - 1, 7) + 1));
  $out = array();
  foreach (($pj['data'] ?? array()) as $sci => $weeks) $out[$sci] = floatval($weeks[$w - 1] ?? 0);
  return $out;
}

function location_probability($sci, $date) {
  static $profile = null;
  if ($profile === null) {
    $pj = is_file(__DIR__ . '/region_profile.json') ? json_decode(file_get_contents(__DIR__ . '/region_profile.json'), true) : null;
    $profile = $pj['data'] ?? array();
  }
  if (!isset($profile[$sci])) return null;
  $t = strtotime($date ?: date('Y-m-d'));
  $w = min(48, (intval(date('n', $t)) - 1) * 4 + min(4, intdiv(intval(date('j', $t)) - 1, 7) + 1));
  return floatval($profile[$sci][$w - 1]);
}

function review_params($sci, $row) {
  $pct = function ($v) { return ($v === null || $v === '') ? '-' : round(floatval($v) * 100) . '%'; };
  $prob = location_probability($sci, $row['Date'] ?? '');
  // the same short names as the detection lists (owner 2026-10-09); the confidences first, then the probability of
  // occurrence at the location (owner 2026-10-21: Prob. after every confidence column), then the analysis
  return array(
    'Conf.' => $pct($row['Confidence'] ?? null),
    'Min. Conf.' => $pct($row['Cutoff'] ?? null),
    'Manual Conf.' => $pct($row['Sp_Override'] ?? null),
    'Prob.' => $prob === null ? '-' : fmt_num($prob * 100, 1) . '%',
    'Min. Prob.' => $pct($row['Loc_Thresh'] ?? null),
    'Sigm. Sens.' => (string)($row['Sens'] ?? '-'),
    'Rec. Length' => ($row['Rec_Length'] ?? null) === null ? '-' : intval($row['Rec_Length']) . ' s',
    'Overlap' => ($row['Overlap'] ?? '') === '' ? '-' : $row['Overlap'] . ' s',
  );
}

function review_verdict($file_name) {
  return review_verdicts()[$file_name][0] ?? '';
}
// the cause of a "not this bird" answer, or 'changed' on the Yes written by Change species (owner 2026-10-22)
function review_reason($file_name) {
  return review_verdicts()[$file_name][1] ?? '';
}
function not_rejected_sql() {
  static $sql = null;
  if ($sql === null) {
    $sql = get_db()->querySingle("SELECT 1 FROM sqlite_master WHERE type='table' AND name='detection_reviews'")
      ? " AND File_Name NOT IN (SELECT File_Name FROM detection_reviews WHERE Verdict = 'no')" : '';
  }
  return $sql;
}

// Species links shown next to every scientific name (owner 2026-10-08): WikiAves first when the names are
// CBRO (Portuguese Brazil) and the bird is Brazilian, then eBird and Birds of the World
// Action icons of one detection (delete, change species, protect from purge, frequency shift), the same
// ones the Recordings page shows, so a card does not need the "open in new tab" detour (owner 2026-10-08).
// $file = "<date>/<common name>/<file>"; $positioned = the absolute top-right layout of the cards.
function detection_actions($file, $positioned = true, $style = '', $width = 25, $with_review = true) {
  static $locked = null;
  $home = get_home();
  if ($locked === null) {
    $list = $home . '/BirdNET-Pi/config/scripts/disk_check_exclude.txt';
    $locked = is_file($list) ? array_flip(file($list, FILE_IGNORE_NEW_LINES)) : array();
  }
  $verdict = review_verdict(basename($file));
  $f = htmlspecialchars(json_encode($file), ENT_QUOTES);
  $lock = isset($locked[$file])
    ? array('del', 'images/lock.svg', t('This file is excluded from being purged.'))
    : array('add', 'images/unlock.svg', t('This file will be deleted when disk space needs to be freed (>95% usage).'));
  $shift = file_exists($home . '/BirdSongs/Extracted/By_Date/shifted/' . $file)
    ? array('unshift', 'images/unshift.svg', t('This file has been shifted down in frequency.'))
    : array('shift', 'images/shift.svg', t('This file is not shifted in frequency.'));
  $icons = array(
    array("deleteDetection($f)", 'images/delete.svg', t('Delete Detection'), '190px'),
    array("toggleLock($f, &quot;$lock[0]&quot;, this)", $lock[1], $lock[2], '115px'),
    array("toggleShiftFreq($f, &quot;$shift[0]&quot;, this)", $shift[1], $shift[2], '80px'),
  );
  // Review loop: a narrow "Review" button (owner 2026-10-08), its label and colour show the verdict; the last
  // button on the right of the action row. The icons (delete, protect from purge, frequency shift; the change-species icon is gone since 2026-10-22, Change species is an answer)
  // live only in the review player since 2026-10-21 (owner): the pages show the Review button alone
  $icons = array();
  $html = '';
  foreach ($icons as $i) {
    $css = 'cursor:pointer;' . ($positioned && $i[3] !== '' ? "right:$i[3];" : '') . $style;
    $html .= "<img style=\"$css\" src=\"$i[1]\" onclick=\"$i[0]\"" . ($positioned ? ' class="copyimage"' : '')
      . " width=\"$width\" title=\"" . htmlspecialchars($i[2], ENT_QUOTES) . '"> ';
  }
  return $html . ($with_review ? validate_button($file, $verdict, $positioned) : '');
}

// for the birds eBird knows, and Wikipedia in the station language. $style/$width are the page's icon style.

// Species title block (owner 2026-10-08): [common name / scientific name] [links], the
// WikiAves / eBird / Birds of the World / Wikipedia icons beside the names at the height of both lines.
// $name_html = the common name as the page renders it (a link, a button...); $extra = more icons for the link row.
// $english = true: a third line with the English (eBird/Clements) name when the station shows names in another language
function species_title($sciname, $name_html, $extra = '', $english = false, $icon = true) {
  $en = '';
  if ($english && (get_config()['DATABASE_LANG'] ?? 'en') !== 'en') {
    $en_name = get_english_name($sciname);
    if ($en_name !== '' && mb_strtolower($en_name) !== mb_strtolower(trim(strip_tags($name_html)))) {
      $en = '<br><span class="spen" title="' . t('English name (eBird / Clements)') . '">' . htmlspecialchars($en_name) . '</span>';
    }
  }
  // the species-page icon before the names left the app (owner 2026-10-21); $icon is kept for the callers
  return '<div class="sptitle"><div class="spnames">' . $name_html . '<br><i>'
    . htmlspecialchars($sciname) . '</i>' . $en . '</div><div class="splinks">' . species_links($sciname, '', 0) . $extra . '</div></div>';
}

// English (eBird / Clements) name of a species: the V3 model labels ("Sci_English", always English, whatever the
// station language), else the upstream English labels; '' when unknown
function get_english_name($sciname) {
  static $map = null;
  if ($map === null) {
    $map = array();
    $model = get_home() . '/BirdNET-Pi/model/';
    foreach (glob($model . 'BirdNET-Plus_*_Labels.txt') ?: array() as $f) {
      if (strpos($f, '_Geo_') !== false) continue;
      foreach (file($f, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $l) {
        $p = explode('_', $l, 2);
        if (count($p) === 2 && !isset($map[$p[0]])) $map[$p[0]] = $p[1];
      }
    }
    if (!$map) {
      $en = json_decode((string)@file_get_contents($model . 'l18n/labels_en.json'), true);
      if (is_array($en)) $map = $en;
    }
  }
  return $map[$sciname] ?? '';
}

// Photo of a species from the image provider of the Settings (cached in its own database); false when none
function species_photo($sciname) {
  static $provider = null;
  $config = get_config();
  if (empty($config['IMAGE_PROVIDER'])) return false;
  if ($provider === null) $provider = ($config['IMAGE_PROVIDER'] === 'FLICKR') ? new Flickr() : new Wikipedia();
  try { return $provider->get_image($sciname); } catch (Throwable $e) { return false; }
}

function species_links($sciname, $style = '', $width = 20) {
  static $ebirds = null;
  if ($ebirds === null) {
    require __ROOT__ . '/scripts/ebird.php';
  }
  $config = get_config();
  $lang = $config['DATABASE_LANG'] ?? 'en';
  $links = array();
  if ($lang === 'pt_BR' && ($wikiaves = get_wikiaves_url($sciname)) !== '') {
    $links[] = array($wikiaves, 'WikiAves', 'images/wikiaves.png');
  }
  $code = $ebirds[$sciname] ?? '';
  if ($code !== '') {
    $links[] = array("https://ebird.org/species/$code?siteLanguage=$lang", 'eBird', 'images/ebird.png');
    $links[] = array("https://birdsoftheworld.org/bow/species/$code/cur/introduction", 'Birds of the World', 'images/bow.png');
  }
  $wiki_lang = explode('_', $lang)[0];
  $links[] = array("https://$wiki_lang.wikipedia.org/wiki/" . str_replace(' ', '_', $sciname), 'Wikipedia', 'images/wiki.png');
  $html = '';
  foreach ($links as $l) {
    $html .= '<a href="' . htmlspecialchars($l[0], ENT_QUOTES) . '" target="_blank"><img style="' . htmlspecialchars($style, ENT_QUOTES)
      . '" title="' . $l[1] . '" alt="' . $l[1] . '" src="' . $l[2] . '"' . ($width ? ' width="' . intval($width) . '"' : '') . '></a> ';
  }
  return $html;
}

function get_info_url($sciname){
  $engname = get_com_en_name($sciname);
  $config = get_config();
  // WikiAves is offered only with CBRO names (Portuguese Brazil); other species fall back to eBird
  if ($config['INFO_SITE'] === 'WIKIAVES' && ($config['DATABASE_LANG'] ?? '') === 'pt_BR'
      && ($wikiaves = get_wikiaves_url($sciname)) !== '') {
    return array('URL' => $wikiaves, 'TITLE' => 'WikiAves');
  }
  if ($config['INFO_SITE'] === 'EBIRD' || $config['INFO_SITE'] === 'WIKIAVES'){
    // the 360 KB table read once per request, as species_links() does (2026-10-23 test run: once per row before)
    static $ebirds = null;
    if ($ebirds === null) require __ROOT__ . '/scripts/ebird.php';
    $ebird = $ebirds[$sciname] ?? '';
    $language = $config['DATABASE_LANG'];
    if ($ebird !== '') {
      $url = "https://ebird.org/species/$ebird?siteLanguage=$language";
      $url_title = "eBirds";
    } else {
      // not a bird on eBird (V3 also detects frogs, insects, mammals): Wikipedia in the station language
      $wiki_lang = explode('_', $language)[0];
      $url = "https://$wiki_lang.wikipedia.org/wiki/" . str_replace(' ', '_', $sciname);
      $url_title = "Wikipedia";
    }
  } else {
    $engname_url = str_replace("'", '', str_replace(' ', '_', $engname));
    $url = "https://allaboutbirds.org/guide/$engname_url";
    $url_title = "All About Birds";
  }
  $ret = array(
      'URL' => $url,
      'TITLE' => $url_title
          );
  return $ret;
}

// the stylesheet follows the theme in use (owner 2026-10-24: one switch, Light = Forest, Dark = Dark): the dark one for
// the Dark theme, the light one for every other
function get_color_scheme(){
  return theme_name_in_use() === 'dark' ? 'static/dark-style.css' : 'style.css';
}

// App colours (owner 2026-10-09, Settings › Theme): five fixed themes and one custom. The stylesheets and pages use
// var(--bg) page background, var(--menu) side menu and table headers, var(--panel) light panels, var(--accent) buttons,
// links and marks (var(--accent-rgb) for shades) and var(--accent2) the bright buttons, each with the original green as
// fallback; theme_style() sets them for the light colour scheme (the dark scheme keeps its own colours).
// APP_THEME = ocean | forest | blossom | white | red | customN; APP_THEME_CUSTOM = "bg,menu,panel,accent,accent2".
// A sixth colour, text (owner 2026-10-24): black in the light themes, white in Dark; a theme of five gets black.
function theme_presets() {
  return array(
    // in the owner's order (2026-10-24): Ocean, Dark, Forest, Blossom, White, Red. Ocean is the system default after a
    // clean install (owner 2026-10-24, last word); Sand removed (2026-10-21)
    'ocean' => array('Ocean', '#7fb3d5', '#a9cce3', '#e3f0fa', '#1f4e79', '#2e86c1', '#000000'),
    // Dark in place of Graphite (owner 2026-10-24): the theme the dark switch puts on the current location; the colours of
    // static/dark-style.css
    'dark' => array('Dark', '#1a1a1a', '#2b2b2b', '#3b3b3b', '#2b5e22', '#4f7f44', '#ffffff'),
    'forest' => array('Forest', '#77c487', '#9fe29b', '#dbffeb', '#2b5e22', '#04aa6d', '#000000'),
    'blossom' => array('Blossom', '#c9a3c7', '#e0c4de', '#f8eef7', '#5e2b5c', '#9c4f96', '#000000'),
    'white' => array('White & Grey', '#ffffff', '#e3e5e8', '#f3f4f6', '#3c4043', '#6b7075', '#000000'),
    'red' => array('Red', '#e3a5a0', '#f0c6c2', '#fdeeed', '#7b1e1e', '#c0392b', '#000000'),
  );
}
// Each location has its own theme (owner 2026-10-21): 'theme' in locations.json, set in Settings › Locations; empty = the
// station's theme (APP_THEME, Settings › Theme, Ocean by default). Unless another theme was chosen for them, Home is
// Ocean and the test location red.
function theme_valid($name) {
  if (isset(theme_presets()[$name])) return true;
  return preg_match('/^custom([1-9][0-9]*)$/', $name, $m) && isset(custom_themes_load()[$m[1] - 1]);
}
function location_theme($l) {
  $name = strtolower(trim((string)($l['theme'] ?? (array('home' => 'ocean', 'test' => 'red')[$l['builtin'] ?? ''] ?? ''))));
  return $name !== '' && theme_valid($name) ? $name : '';
}
// the theme choices of a location's select: the station's theme, the fixed themes, the custom ones
function location_theme_options($cur) {
  $h = function ($x) { return htmlspecialchars((string)$x, ENT_QUOTES); };
  $out = "<option value=''" . ($cur === '' ? ' selected' : '') . '>' . $h(t('Station theme')) . '</option>';
  foreach (theme_presets() as $k => $p) $out .= "<option value='$k'" . ($cur === $k ? ' selected' : '') . '>' . $h(t($p[0])) . '</option>';
  foreach (custom_themes_load() as $i => $c) $out .= "<option value='custom" . ($i + 1) . "'" . ($cur === 'custom' . ($i + 1) ? ' selected' : '') . '>' . $h($c['name']) . '</option>';
  return $out;
}
// Custom themes with a name (owner 2026-10-20; no limit since 2026-10-21), in ~/BirdNET-Pi/config/custom_themes.json (station data, outside git):
// [{"name": "...", "colors": [bg, menu, panel, accent, accent2]}, ...]; APP_THEME = custom1, custom2 … picks one. The single
// custom theme of before (APP_THEME_CUSTOM) becomes the first one.
function custom_themes_file() { return get_home() . '/BirdNET-Pi/config/custom_themes.json'; }
function custom_themes_load() {
  $ok = function ($cols) { return is_array($cols) && in_array(count($cols), array(5, 6), true) && count(preg_grep('/^#[0-9a-fA-F]{6}$/', $cols)) === count($cols); };
  $list = json_decode((string)@file_get_contents(custom_themes_file()), true);
  if (!is_array($list)) {
    $list = array();
    $v = array_map('trim', explode(',', get_config()['APP_THEME_CUSTOM'] ?? ''));
    if ($ok($v)) $list[] = array('name' => 'Custom', 'colors' => array_pad(array_map('strtolower', $v), 6, '#000000'));
  }
  $out = array();
  foreach ($list as $t) if (isset($t['colors']) && $ok($t['colors']))
    $out[] = array('name' => mb_substr(trim((string)($t['name'] ?? '')) ?: 'Custom ' . (count($out) + 1), 0, 30), 'colors' => array_pad(array_map('strtolower', $t['colors']), 6, '#000000'));
  return $out;
}
// the name of the theme in use: the current location's own, else the system default (APP_THEME, Ocean by default)
function theme_name_in_use() {
  $name = strtolower(trim(get_config()['APP_THEME'] ?? 'ocean')); // Ocean is the default theme (owner 2026-10-24)
  $cur = location_current();
  if ($cur && location_theme($cur) !== '') $name = location_theme($cur); // the current location's own theme
  return $name;
}
function theme_colors() {
  $c = get_config();
  $presets = theme_presets();
  $name = theme_name_in_use();
  if (preg_match('/^custom([1-9][0-9]*)$/', $name, $m)) {
    $list = custom_themes_load();
    if (isset($list[$m[1] - 1])) return array_merge(array($list[$m[1] - 1]['name']), $list[$m[1] - 1]['colors']);
    $name = 'ocean';
  }
  if ($name === 'custom') {
    $v = array_map('trim', explode(',', $c['APP_THEME_CUSTOM'] ?? ''));
    if (count($v) === 5 && count(preg_grep('/^#[0-9a-fA-F]{6}$/', $v)) === 5) return array_merge(array('Custom'), $v, array('#000000'));
    $name = 'ocean';
  }
  return $presets[$name] ?? $presets['ocean'];
}
// black or white text on a colour, whichever contrasts more (owner 2026-10-24: the text of the selected row was not
// readable on every theme). The WCAG rule: relative luminance L of the colour; contrast with white (1.05)/(L+0.05),
// with black (L+0.05)/0.05
function contrast_text($hex) {
  $l = 0;
  foreach (array(0.2126, 0.7152, 0.0722) as $i => $w) {
    $c = hexdec(substr($hex, 1 + 2 * $i, 2)) / 255;
    $l += $w * ($c <= 0.03928 ? $c / 12.92 : pow(($c + 0.055) / 1.055, 2.4));
  }
  return 1.05 / ($l + 0.05) >= ($l + 0.05) / 0.05 ? '#fff' : '#000';
}
function theme_style() {
  // the theme in use; with Dark (the dark stylesheet) the text is white, always (owner 2026-10-24)
  $dark = theme_name_in_use() === 'dark';
  list(, $bg, $menu, $panel, $accent, $accent2, $text) = array_pad(theme_colors(), 7, '#000000');
  if ($dark) $text = '#ffffff';
  $rgb = implode(',', array_map('hexdec', str_split(substr($accent, 1), 2)));
  return "<style id=\"themeVars\">:root{--bg:$bg;--menu:$menu;--panel:$panel;--accent:$accent;--accent-rgb:$rgb;--accent2:$accent2;--text:$text;--on-accent2:" . contrast_text($accent2) . "}</style>";
}

// The station's folders open in the viewer's own file manager over SFTP (owner 2026-10-23: no file manager inside the web
// pages, the most conservative choice): sftp://<station user>@<this host>:<SSH port><path>. Dolphin / Kate open it through
// KIO, WinSCP on Windows; the SSH login protects it, nothing is added to the web surface.
function station_ssh_port() {
  foreach (array_merge(array('/etc/ssh/sshd_config'), glob('/etc/ssh/sshd_config.d/*.conf') ?: array()) as $f) {
    foreach (@file($f, FILE_IGNORE_NEW_LINES) ?: array() as $l) {
      if (preg_match('/^\s*Port\s+(\d+)/i', $l, $m)) return intval($m[1]);
    }
  }
  return 22;
}
function sftp_base() {
  $host = preg_replace('/:\d+$/', '', $_SERVER['HTTP_HOST'] ?? '') ?: gethostname();
  return 'sftp://' . rawurlencode(get_user()) . '@' . $host . ':' . station_ssh_port();
}
function sftp_url($path) {
  return sftp_base() . implode('/', array_map('rawurlencode', explode('/', $path)));
}

// Folders and files of the station seen with the station user's own rights (owner 2026-10-23: the pages may open any
// folder, mounted drives included, but only with the visibility the station user has, never with the web server's or
// root's). Every look runs as that user through sudo -u; what the user cannot read does not exist for the pages.
function station_user_cmd($cmd) {
  return as_station($cmd);
}
// a command run as the station user: directly when the pages already run as that user (fresh installs, owner
// 2026-10-23: least privilege), through sudo -u on an older station whose pages still run as the web server
function as_station($cmd) {
  return station_prefix() . $cmd;
}
// the start of a shell command run as the station user: nothing when the pages are that user, else sudo -n -u <user>
function station_prefix() {
  static $prefix = null;
  if ($prefix === null) {
    $pw = function_exists('posix_getpwuid') ? posix_getpwuid(posix_geteuid()) : false;
    $prefix = ($pw !== false && $pw['name'] === get_user()) ? '' : 'sudo -n -u ' . escapeshellarg(get_user()) . ' ';
  }
  return $prefix;
}
// the physical path of a folder the station user can enter, else false
// The folders a page may browse from (owner 2026-10-24: an external disk or share mounted under /mnt or /media must be
// seen): the station user's home and every mount point under /mnt and /media (from /proc/mounts, any depth: /media/usb
// as well as /media/pi/DISK and /mnt/nas/backup), plus the folders right under them. Each with ok = the station user
// can enter it (a share mounted for root only is listed, greyed, so the reason can be told)
function folder_roots() {
  static $roots = null;
  if ($roots !== null) return $roots;
  $cands = array(get_home());
  $mounts = array();
  foreach (@file('/proc/mounts', FILE_IGNORE_NEW_LINES) ?: array() as $l) {
    $f = explode(' ', $l);
    $mp = str_replace(array('\\040', '\\011'), array(' ', "\t"), $f[1] ?? '');
    if (preg_match('~^/(mnt|media)/.+~', $mp)) { $cands[] = $mp; $mounts[] = $mp; }
  }
  foreach (array_merge(glob('/mnt/*', GLOB_ONLYDIR) ?: array(), glob('/media/*', GLOB_ONLYDIR) ?: array(), glob('/media/*/*', GLOB_ONLYDIR) ?: array()) as $d) $cands[] = $d;
  $roots = array();
  foreach (array_unique($cands) as $c) {
    // /media/<user> itself is only the parent of that user's disks
    if (preg_match('~^/media/[^/]+$~', $c) && glob($c . '/*', GLOB_ONLYDIR) && !in_array($c, $mounts, true)) continue;
    $roots[] = array('path' => $c, 'ok' => station_user_dir($c) !== false);
  }
  return $roots;
}
// a folder of the station the station user can enter: its real path, else false (owner 2026-10-29: the whole tree, not
// only the home and the mounted shares; folder_roots() are the shortcuts); the kernel's pseudo file systems stay out
function root_folder($p) {
  $r = station_user_dir($p);
  if ($r === false || preg_match('~^/(proc|sys|dev|run)(/|$)~', $r)) return false;
  return $r;
}
function station_user_dir($p) {
  $p = (string)$p;
  if ($p === '' || strpos($p, "\0") !== false) return false;
  $out = shell_exec(station_user_cmd('sh -c ' . escapeshellarg('cd -- "$1" 2>/dev/null && pwd -P') . ' _ ' . escapeshellarg($p)));
  $r = rtrim((string)$out, "\n");
  return ($r !== '' && $r[0] === '/') ? $r : false;
}
// the entries of a folder as the station user sees them: array('dirs' => [...], 'files' => [...]), hidden ones left out
function station_user_ls($dir) {
  $out = (string)shell_exec(station_user_cmd('find ' . escapeshellarg($dir) . ' -mindepth 1 -maxdepth 1 ! -name ' . escapeshellarg('.*') . ' -printf ' . escapeshellarg('%Y\t%f\0') . ' 2>/dev/null'));
  $r = array('dirs' => array(), 'files' => array());
  foreach (explode("\0", $out) as $e) {
    if ($e === '' || strpos($e, "\t") === false) continue;
    list($type, $name) = explode("\t", $e, 2);
    if ($type === 'd') $r['dirs'][] = $name; elseif ($type === 'f') $r['files'][] = $name;
  }
  natcasesort($r['dirs']); natcasesort($r['files']);
  $r['dirs'] = array_values($r['dirs']); $r['files'] = array_values($r['files']);
  return $r;
}
function station_user_is_file($f) {
  exec(station_user_cmd('test -f ' . escapeshellarg($f) . ' -a -r ' . escapeshellarg($f)), $o, $rc);
  return $rc === 0;
}
// a small file (JSON, CSV) read as the station user; false when it cannot read it
function station_user_read($f, $max = 52428800) {
  if (!station_user_is_file($f)) return false;
  return (string)shell_exec(station_user_cmd('head -c ' . intval($max) . ' -- ' . escapeshellarg($f)));
}
