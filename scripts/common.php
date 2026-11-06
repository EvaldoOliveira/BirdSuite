<?php

// the folder layout, service names, ports and root helper paths: the BS_* constants of core.env (scripts/core.php,
// owner 2026-11-02; core.sh and utils/core.py read the same file)
require_once __DIR__ . '/core.php';
// the pages of the station, their menu places and levels (US-50)
require_once __DIR__ . '/view_registry.php';
if (!defined('__ROOT__')) define('__ROOT__', BS_PROG);
// the default radius of a location (owner 2026-10-25: 1 km, was 5: with the device's GPS the position is metres off)
define('LOCATION_RADIUS_KM', 1);

// the session cookie is not readable by scripts and not sent by other sites' requests (2026-10-23 test run); the same
// options go with every session_start() of the pages
define('BS_SESSION_OPTIONS', array('cookie_httponly' => true, 'cookie_samesite' => 'Lax'));
if (session_status() !== PHP_SESSION_ACTIVE)
  session_start(BS_SESSION_OPTIONS);

// ---- Interface language (US-122, owner 2026-10-20; text keys 2026-11-02) ----
// Every text has a key: t('str_upload_an_image') answers the text of the language chosen in UI_LANG (empty = follow
// DATABASE_LANG when there is an interface for it), or the English one (lang/en, the only reference) when that
// catalogue lacks it. Catalogues: lang/<code>/*.json (one file per page group, merged). {name} placeholders are filled
// from $vars, so word order can change between languages. python3 lang/check.py checks the keys.
function ui_languages() {
  return array('en' => 'English', 'pt_BR' => 'Português', 'es' => 'Español', 'zh_CN' => '简体中文', 'ja' => '日本語',
    'de' => 'Deutsch', 'fr' => 'Français', 'it' => 'Italiano', 'nl' => 'Nederlands',
    'pl' => 'Polski', 'ru' => 'Русский');
}

// the station's number format follows the BirdSuite language (owner 2026-10-23): UI_LANG, or DATABASE_LANG when empty;
// LC_NUMERIC only (birdsuite-root numeric), the system messages keep their language. Silent on stations without it.
// a picture path of a setting (NOW_BACKGROUND, the Now page background): an image file (png, jpg, gif, webp) inside the
// station user's home, with a plain path (2026-10-23 NEW-SEC-05: any readable file could be shown to anyone on the network)
function custom_image_ok($path) {
  $home = get_home();
  if (!preg_match('#^/[A-Za-z0-9._/-]+\.(png|jpe?g|gif|webp)$#i', (string)$path)) return false;
  $real = realpath($path);
  return $real !== false && strpos($real, rtrim($home, '/') . '/') === 0 && is_file($real) && @getimagesize($real) !== false;
}
function station_numeric_sync($ui, $db) {
  $l = $ui !== '' ? $ui : $db;
  if (!isset(ui_languages()[$l])) $l = $l === 'pt' ? 'pt_BR' : (isset(ui_languages()[substr($l, 0, 2)]) ? substr($l, 0, 2) : 'en');
  @exec('sudo -n ' . BS_ROOT_HELPER . ' numeric ' . escapeshellarg($l) . ' > /dev/null 2>&1 &');
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
// English first, the chosen language over it: a key without a translation shows its English text
function ui_catalog() {
  static $cat = null;
  if ($cat === null) {
    $cat = array();
    foreach (array_unique(array('en', ui_lang())) as $lang) {
      foreach (glob(__ROOT__ . '/lang/' . $lang . '/*.json') ?: array() as $f) {
        $j = json_decode((string)file_get_contents($f), true);
        if (is_array($j)) $cat = array_merge($cat, array_filter($j, 'strlen'));
      }
    }
    // a text names a page as {menu:<view key>} (US-51, owner 2026-11-04): it shows the page's current place in the
    // menu ("Station › Location"), so a renamed or moved page never leaves an old path in a help text
    foreach ($cat as $k => $txt) {
      if (strpos($txt, '{menu:') !== false) {
        $cat[$k] = preg_replace_callback('/\{menu:([^}]+)\}/', function ($m) use ($cat) { return menu_path($m[1], $cat); }, $txt);
      }
    }
  }
  return $cat;
}
// the station's experience level (US-52): essential, birder or expert; empty (an updated station) = expert
function ui_level() {
  // a named user with a level of its own (US-56) sees that one
  if (function_exists('login_user') && ($u = login_user()) !== null && $u['level'] !== '') return $u['level'];
  $l = trim((string)(get_config()['UI_LEVEL'] ?? ''), " \"'");
  return isset(ui_levels()[$l]) ? $l : 'expert';
}
// the station runs outdoors (UI_CONTEXT field_phone or field_offline, US-53): the field pages show at every level
function ui_field_context() {
  return (bool)preg_match('/(^|,)field_(phone|offline)(,|$)/', trim((string)(get_config()['UI_CONTEXT'] ?? ''), " \"'"));
}
// the level reaches $level (expert reaches every level)
function ui_at_least($level) {
  $lv = ui_levels();
  return $lv[ui_level()] >= ($lv[$level] ?? 2);
}
// the expert part of a form (US-52): open at the level that reaches it, folded under "More options" below it; never
// removed, so every field stays reachable and saves as before. Close it with more_options_end().
function more_options($level = 'expert') {
  $open = ui_at_least($level);
  return '<details class="moreopts" data-level="' . htmlspecialchars($level, ENT_QUOTES) . '"' . ($open ? ' open' : '') . '>'
    . '<summary' . ($open ? ' class="moreopts-open"' : '') . '>' . htmlspecialchars(t('str_more_options')) . '</summary>';
}
function more_options_end() { return '</details>'; }
// a setting saved as soon as it changes (static/ui-standard.js data-setting, scripts/settings_set.php), yellow when it
// differs from its factory value: a number field or a picklist, its label and its help line (owner 2026-11-04)
// 'apply' => true: the field is not saved on change but by the section's Apply button (bsApplySettings, ui-standard.js),
// together with the section's other such fields (owner 2026-11-05)
function setting_field($key, $label, $opts = array(), $help = '') {
  $v = (string)(get_config()[$key] ?? (birdsuite_defaults()[$key] ?? ''));
  $h = function ($s) { return htmlspecialchars((string)$s, ENT_QUOTES); };
  $attr = !empty($opts['apply']) ? 'data-setting-apply' : 'data-setting';
  if (isset($opts['choices'])) {
    $f = '<select ' . $attr . '="' . $h($key) . '" data-default-key="' . $h($key) . '">';
    foreach ($opts['choices'] as $c) $f .= '<option value="' . $h($c) . '"' . ($c === $v ? ' selected' : '') . '>' . $h($c) . '</option>';
    $f .= '</select>';
  } else {
    $f = '<input type="number" ' . $attr . '="' . $h($key) . '" data-default-key="' . $h($key) . '" value="' . $h($v) . '" style="width:6em"'
      . (isset($opts['min']) ? ' min="' . $h($opts['min']) . '"' : '') . (isset($opts['max']) ? ' max="' . $h($opts['max']) . '"' : '')
      . ' step="' . $h($opts['step'] ?? 1) . '">';
  }
  return '<div style="margin:6px 0"><label><b>' . $h($label) . '</b> ' . $f . (isset($opts['unit']) ? ' ' . $h($opts['unit']) : '') . '</label>'
    . ($help !== '' ? '<div class="small">' . $help . '</div>' : '') . '</div>';
}
// the place of a page in the menu, in the interface language: "Group › Page", or the page alone at the top level
function menu_path($view, $cat = null) {
  if ($cat === null) $cat = ui_catalog();
  $k = view_resolve($view);
  $e = $k === '' ? null : view_entry($k);
  if ($e === null) return (string)$view;
  $label = $cat[$e['label']] ?? $k;
  return (string)$e['group'] !== '' && $e['group'] !== null ? ($cat[$e['group']] ?? $e['group']) . ' › ' . $label : $label;
}
// a text that is not a key (a message already translated, e.g. passed to ensure_authenticated()) comes back as it is
function t($s, $vars = array()) {
  $c = ui_catalog();
  $r = $c[$s] ?? $s;
  foreach ($vars as $k => $v) $r = str_replace('{' . $k . '}', (string)$v, $r);
  return $r;
}
// the same for the page's JavaScript: window.t(key, {name: value}); printed in the head of every page
// numbers in the format of the BirdSuite language (decimal and thousands separators); coordinates keep the point (the
// usual way to write them everywhere); data-sort, value= and input fields keep the point too (the server reads them)
function num_separators() {
  // the BirdSuite language decides (owner 2026-10-23: the number format follows UI_LANG, or DATABASE_LANG when empty;
  // release run 4: reading the system locale left 0.85 on a pt_BR page whenever the Pi's locale was not synced yet)
  static $sep = null;
  if ($sep !== null) return $sep;
  $map = array('en' => array('.', ','), 'ja' => array('.', ','), 'zh_CN' => array('.', ','),
               'fr' => array(',', "\u{202F}"), 'pl' => array(',', "\u{00A0}"), 'ru' => array(',', "\u{00A0}"));
  return $sep = $map[ui_lang()] ?? array(',', '.'); // pt_BR, es, de, it, nl
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
// middle (the buttons call $page_js with the new offset). $label = the text with {select}, e.g. t('str_last_detections').
// The Filter icon of the pages (owner 2026-10-21): it opens / folds the filters and the search of its bar ("fopen")
function filter_toggle() {
  return '<span class="dashfilter" role="button" tabindex="0" title="' . htmlspecialchars(t('str_filter_and_search_show_hide'), ENT_QUOTES) . '"'
    . ' onclick="this.closest(\'.sortbar\').classList.toggle(\'fopen\')" onkeydown="if (event.key === \'Enter\' || event.key === \' \') { event.preventDefault(); this.click(); }">'
    . '<svg viewBox="0 0 24 24" width="18" height="18" aria-label="' . htmlspecialchars(t('str_filter'), ENT_QUOTES) . '" role="img"><path fill="currentColor" d="M3 4h18l-7 8.5V19l-4 2v-8.5z"/></svg></span>';
}
// The Sort icon of every page with sort buttons (owner 2026-10-21): an icon like the Filter one; it opens / folds the
// sort buttons of the bar it sits in (the bar carries class "sortbar"; static/ui-standard.js bsSortToggle)
function sort_toggle() {
  return '<span class="sorticon" role="button" tabindex="0" title="' . htmlspecialchars(t('str_sort'), ENT_QUOTES) . '" onclick="bsSortToggle(this)"'
    . ' onkeydown="if (event.key === \'Enter\' || event.key === \' \') { event.preventDefault(); bsSortToggle(this); }">'
    . '<svg viewBox="0 0 24 24" width="18" height="18" aria-label="' . htmlspecialchars(t('str_sort'), ENT_QUOTES) . '" role="img"><path fill="currentColor" d="M7 3l4 5H8v8H6V8H3zm10 18l-4-5h3V8h2v8h3z"/></svg></span>';
}
// CSV of a page's table (owner 2026-10-22, By Hour and By Week): a download icon beside Filter / Sort, and the file itself
function csv_icon($href) {
  return '<a class="csvicon" href="' . htmlspecialchars($href, ENT_QUOTES) . '" download title="' . htmlspecialchars(t('str_download_the_table_as_csv'), ENT_QUOTES) . '">'
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
  $sel = '<select' . ($count_id !== '' ? ' id="' . $count_id . '"' : '') . ' class="nowcount" onchange="' . $count_js . '(this.value)" title="' . htmlspecialchars(t('str_how_many_the_list_shows'), ENT_QUOTES) . '">' . $opts . '</select>';
  return '<div class="nowpager"><span class="nowpcount">' . str_replace('{select}', $sel, $label) . '</span><span class="nowpnav">'
    . ($offset > 0 ? '<button type="button" class="openbtn" onclick="' . $page_js . '(' . max(0, $offset - $page) . ')">&#9664; ' . t('str_newer', array('n' => $page)) . '</button>' : '')
    . '<span class="nowprange">' . ($shown ? ($offset + 1) . '-' . ($offset + $shown) : '') . '</span>'
    . ($more ? '<button type="button" class="openbtn" onclick="' . $page_js . '(' . ($offset + $page) . ')">' . t('str_older', array('n' => $page)) . ' &#9654;</button>' : '')
    . '</span><span></span></div>';
}
// the hearing aid as the pages need it: on, the ratio heard / real, and a stamp of the settings (a new lowered clip
// after a change)
function hearing_state() {
  $c = get_config();
  $hi = max(500, intval($c['FREQSHIFT_HI'] ?? 12000));
  $lo = max(100, intval($c['FREQSHIFT_LO'] ?? 5000));
  $base = max(0, intval($c['FREQSHIFT_BASE'] ?? 0));
  return array('on' => false, 'r' => max(0.2, min(1, $lo / $hi)), 'base' => $base, 'v' => substr(md5("$hi|$lo|$base"), 0, 8));
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
    . ' window.BS_DEFAULTS = ' . json_encode((object)birdsuite_defaults(), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) . ';'
    // the location this page shows ('' = the current one): a link to another page with detections carries it (ui-standard.js)
    . ' window.BS_LOC = ' . json_encode(($lf = location_filter()) === 'current' ? '' : $lf) . ';'
    // the hearing aid (owner 2026-11-04): switched per device by the header's Live Audio (kept in the browser, bs_hearing);
    // on = what goes to the speaker is lowered: the live sound in the browser (static/hearing-audio.js), a clip through
    // play.php?lowered= (nothing saved); the settings are the station's. The header sends every change down to the pages
    // and their frames (event bs:hearing); another tab follows through the storage event
    . ' window.BS_HEARING = ' . json_encode(hearing_state()) . '; try { window.BS_HEARING.on = localStorage.getItem("bs_hearing") === "1"; } catch (e) {}'
    . ' window.addEventListener("storage", function (e) { if (e.key !== "bs_hearing" || window.top !== window) return; window.BS_HEARING.on = e.newValue === "1"; if (window.hearSend) window.hearSend(); });'
    . ' window.addEventListener("message", function (e) { if (e.origin !== location.origin || !e.data || !e.data.bsHearing) return; window.BS_HEARING = e.data.bsHearing;'
    . ' document.dispatchEvent(new CustomEvent("bs:hearing", {detail: e.data.bsHearing})); [].forEach.call(document.querySelectorAll("iframe"), function (f) { try { f.contentWindow.postMessage(e.data, location.origin); } catch (x) {} }); });'
    . ' window.bsClipSrc = function (u) { var h = window.BS_HEARING, m = h && h.on ? String(u || "").match(/^\/?(By_Date|Purge)\/([^?]+)/) : null;'
    . ' return m ? "play.php?lowered=" + encodeURIComponent(m[1] + "/" + decodeURIComponent(m[2])) + "&v=" + h.v : u; };</script>'
    // one sentence per line on every page (owner 2026-10-21): static/sentence-lines.js breaks the line after each sentence
    . '<script src="/static/sentence-lines.js?v=' . @filemtime(__ROOT__ . '/homepage/static/sentence-lines.js') . '"></script>'
    // the buttons of one table column with the same width (owner 2026-10-21)
    . '<script src="/static/ui-standard.js?v=' . @filemtime(__ROOT__ . '/homepage/static/ui-standard.js') . '"></script>'
    // every stdtable sorts by a click on a column title, on every page (2026-11-01: Data, Backup, Database, External
    // Files, Status, Disk Space did not load it)
    . '<script src="/static/std-table.js?v=' . @filemtime(__ROOT__ . '/homepage/static/std-table.js') . '"></script>'
    // the shared dialog (askModal, bsNotice, bsPrompt) on every page: every question and notice goes through it, never the browser's dialogs
    // (2026-11-01, one look and the safe answer as the default everywhere)
    . '<script src="/static/species-modal.js?v=' . @filemtime(__ROOT__ . '/homepage/static/species-modal.js') . '"></script>';
}

function ensure_db_ok($sql_stmt) {
  if ($sql_stmt == False) {
    echo t('str_database_is_busy');
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

// ---- Writing BirdSuite.conf (security 2026-11-02) ----
// The file is sourced by the station's bash scripts and read by PHP and Python, so every page writes it through
// conf_set() / conf_set_many() only: each key has a rule below (a key without one is refused), the value must pass it
// and may hold nothing the shell acts on ($ ` " \ ; | & < > or a line break). The quoted kinds (text, tpl, or 'q') may
// hold ; | & < > and spaces, which are inert inside the double quotes the value is then written in; tpl also allows
// $name (the notification variables). The file is rewritten whole, atomically (rename), under a lock.
// Rules: enum (values), int (min, max), num (min, max), re (pattern), text (max length), tpl (max length), fn (check).
function conf_rules() {
  static $r = null;
  if ($r !== null) return $r;
  $b = array('enum', array('0', '1'));
  $tf = array('enum', array('true', 'false'));
  $hm = array('re', '/^([01]\d|2[0-3]):[0-5]\d$/');
  $hm0 = array('re', '/^(([01]\d|2[0-3]):[0-5]\d)?$/');
  $hms = array('re', '/^([01]\d|2[0-3]):[0-5]\d:[0-5]\d$/');
  $days = array('re', '/^([0-6](,[0-6]){0,6})?$/');
  $level = array('enum', array('error', 'warning', 'info', 'debug'));
  $oc = array('enum', array('open', 'closed'));
  $models = array('BirdNET_GLOBAL_6K_V2.4_Model_FP16', 'BirdNET_6K_GLOBAL_MODEL', 'BirdNET-Plus_V3.0-preview3.1_Global_10K');
  $img = array('fn', function ($v) { return $v === '' || custom_image_ok($v); });
  $r = array(
    // station and location
    'SITE_NAME' => array('text', 60),
    'LATITUDE' => array('num', -90, 90),
    'LONGITUDE' => array('num', -180, 180),
    'LOCATION_ID' => array('int', 0, 999999),
    'LOCATION_CHECK' => $b,
    'LOCATION_IP_ERROR_KM' => array('int', 1, 500),
    'GEOLOCATION_API_KEY' => array('re', '/^[A-Za-z0-9_-]{0,100}$/'),
    'MULTI_LOCATION' => $b,
    'MULTI_STATION' => $b,
    'SPECIES_LIST' => array('fn', 'species_list_valid'),
    // model and analysis
    'MODEL' => array('enum', $models),
    'SF_THRESH' => array('num', 0.0005, 0.99),
    'CONFIDENCE' => array('num', 0.01, 0.99),
    'SENSITIVITY' => array('num', 0.5, 1.5),
    'OVERLAP' => array('num', 0, 2.9),
    'AVOID_DUPLICITY' => $b,
    'PRIVACY_THRESHOLD' => array('int', 0, 3),
    'DATA_MODEL_VERSION' => array('enum', array('1', '2')),
    'DATABASE_LANG' => array('fn', function ($v) { return isset(common_name_languages()[$v]); }),
    'SHADOW_MODEL_NAME' => array('enum', array_merge(array(''), $models)),
    'SHADOW_ENABLED' => $b,
    'SHADOW_MIN_CONF' => array('num', 0.01, 0.99),
    'SHADOW_SENS' => array('num', 0.5, 1.5),
    'SHADOW_GEO_THRESH' => array('num', 0.0005, 0.99),
    'NOCTURNAL_DAWN_MARGIN' => array('int', 0, 180),
    'NOCTURNAL_DUSK_MARGIN' => array('int', 0, 180),
    // audio
    'REC_CARD' => array('re', '/^[A-Za-z0-9_:,.=-]{1,100}$/'),
    // the chosen microphone by its ALSA card id (US-07), empty = the first USB one
    'REC_MIC' => array('re', '/^([A-Za-z_][A-Za-z0-9_]{0,31})?$/'),
    'CHANNELS' => array('int', 1, 32),
    'RECORDING_LENGTH' => array('int', 3, 60),
    'EXTRACTION_LENGTH' => array('int', 3, 60),
    'AUDIOFMT' => array('re', '/^[a-z0-9-]{1,10}$/'),
    'RTSP_STREAM' => array('fn', function ($v) {
      foreach (array_filter(explode(',', $v), 'strlen') as $u) if (!preg_match('#^(rtsps?|rtmp|https?)://[^\s"\'`$\\\\;|&<>]+$#', $u)) return false;
      return true;
    }),
    'RTSP_STREAM_TO_LIVESTREAM' => array('int', 0, 999),
    'FREQSHIFT_HI' => array('int', 0, 20000),
    'FREQSHIFT_LO' => array('int', 0, 20000),
    'FREQSHIFT_BASE' => array('int', 0, 10000),
    'LogLevel_BirdnetRecordingService' => $level,
    'LogLevel_SpectrogramViewerService' => $level,
    'LogLevel_LiveAudioStreamService' => $level,
    'RAW_REC_ENABLED' => $b,
    'RAW_REC_DAYS' => $days,
    'RAW_REC_RECURRENT' => $b,
    'RAW_REC_START' => $hms,
    'RAW_REC_END' => $hms,
    'RAW_REC_SEGMENT_MIN' => array('int', 1, 240),
    'RAW_REC_RATE' => array('int', 8000, 384000),
    'RAW_REC_DIR' => array('re', '#^(/[A-Za-z0-9._ -]+)*$#'),
    // access
    'CADDY_USER' => array('re', '/^[A-Za-z0-9]{1,32}$/'),
    // the web password is stored only as its bcrypt hash (SEC-15, 2026-11-02), in single quotes so neither PHP nor a shell
    // that sources the file reads its $ signs; empty = the factory password
    'CADDY_PWD' => array('re', '/^(\$2[aby]\$\d\d\$[.\/A-Za-z0-9]{53})?$/', 'sq' => true),
    'BIRDNETPI_URL' => array('re', '#^(https?://[A-Za-z0-9.-]+(:[0-9]{1,5})?)?$#'),
    'HTTPS_ALWAYS' => $b,
    'WEB_LOGIN' => array('enum', array('on', 'off')),
    'AUDIO_LOSS_DISCARD' => $b,
    'REC_BITS' => array('enum', array('16', '24')),
    'AUDIO_LOSS_MAX_MS' => array('int', 1, 1000),
    'LAN_TRUST' => array('enum', array('everyone', 'household')),
    'UI_PRESET' => array('enum', array('', 'home', 'birder', 'research')),
    'UI_CONTEXT' => array('fn', function ($v) { return $v === '' || (bool)preg_match('/^(home|field_phone|field_offline)(,(home|field_phone|field_offline))*$/', $v); }),
    'PWD_RECOVERY' => array('enum', array('', 'email', 'telegram', 'sdcard')),
    // notifications
    'APPRISE_NOTIFICATION_TITLE' => array('tpl', 200),
    'APPRISE_NOTIFICATION_TITLE_RARE' => array('tpl', 200),
    'APPRISE_NOTIFY_EACH_DETECTION' => $b,
    'APPRISE_NOTIFY_NEW_SPECIES' => $b,
    'APPRISE_NOTIFY_NEW_SPECIES_EACH_DAY' => $b,
    'APPRISE_NOTIFY_REGION_RARE' => $b,
    'APPRISE_MIN_SEC_PER_SPECIES' => array('int', 0, 86400),
    'APPRISE_QUIET_START_NORMAL' => $hm0,
    'APPRISE_QUIET_END_NORMAL' => $hm0,
    'APPRISE_QUIET_START_RARE' => $hm0,
    'APPRISE_QUIET_END_RARE' => $hm0,
    'NOTIFICATION_DEFAULT_TIER' => array('enum', array('muted', 'normal', 'rare')),
    'NOTIFICATION_EMAIL' => array('re', '/^([^\s"\'`$\\\\@;|&<>]+@[^\s"\'`$\\\\@;|&<>]+)?$/'),
    // repositories
    'BIRDWEATHER_ID' => array('re', '/^([A-Za-z0-9_-]{4,64})?$/'),
    'BIRDWEATHER_ENABLED' => $b,
    'BIRDWEATHER_PER_LOCATION' => $b,
    'ACTIVATE_BIRDDB' => $tf,
    'BIRDDB_ENABLED' => $b,
    'SOUND_REPO_REMOTE' => array('re', '/^([A-Za-z0-9_-]+:[^\s"\'`$\\\\]*)?$/', 'q' => true),
    'SOUND_REPO_UPLOAD_MINUTES' => array('int', 0, 1440),
    // disk
    'FULL_DISK' => array('enum', array('purge', 'keep', 'external')),
    'FULL_DISK_PATH' => array('re', '#^(/[^\x00-\x1F"`$\\\\]*)?$#', 'q' => true),
    'FULL_DISK_FALLBACK' => array('enum', array('keep', 'purge')),
    'PURGE_THRESHOLD' => array('int', 0, 99),
    'PURGE_PROTECT_TOP_N' => array('int', 0, 100),
    'PURGE_RECENT_DAYS' => array('int', 0, 365),
    'IGNORED_PURGE_DAYS' => array('int', 0, 3650),
    'MAX_FILES_SPECIES' => array('int', 0, 100000),
    'MAX_FILES_SPECIES_DAY' => array('int', 0, 100000),
    'LOG_RETENTION_DAYS' => array('enum', array('0', '7', '30', '90', '365')),
    // pages and look
    'APP_THEME' => array('fn', 'theme_valid'),
    'UI_LANG' => array('fn', function ($v) { return $v === '' || isset(ui_languages()[$v]); }),
    'UI_LEVEL' => array('enum', array('', 'essential', 'birder', 'expert')),
    'RARE_SPECIES_THRESHOLD' => array('int', 1, 3650),
    'NOW_BACKGROUND' => $img,
    'NOW_BACKGROUND_TEST' => $img,
    'NOW_PERIOD' => array('enum', array('1', '5', '15', '60', '240', '480', '720')),
    'NOW_CONF' => array('enum', array('10', '20', '30', '40', '50', '60', '70', '80', '90')),
    'LATEST_VIEW' => array('enum', array('simple', 'list', 'spectrogram')),
    'LATEST_COUNT' => array('enum', array('10', '25', '50', '100')),
    'LATEST_LOWPROB' => array('int', 5, 95),
    'LATEST_LOWCONF' => array('int', 5, 95),
    'LATEST_UNCOMMON' => array('int', 1, 100000),
    'LATEST_PERIOD' => array('enum', array('today', '7d', 'all')),
    'LATEST_FILTERS_PHONE' => $oc,
    'LATEST_FILTERS_DESKTOP' => $oc,
    'LATEST_PARAMS_PHONE' => $oc,
    'LATEST_PARAMS_DESKTOP' => $oc,
    'REPORTS_FILTERS_PHONE' => $oc,
    'REPORTS_FILTERS_DESKTOP' => $oc,
    'SPECTROGRAM_HEIGHT' => array('int', 20, 100),
    'SPECTROGRAM_PALETTE' => array('enum', array('turbo', 'inferno', 'roseus', 'soxheat', 'birdnet', 'viridis', 'ocean', 'grayscale')),
    'SPECTROGRAM_SILENT' => $b,
    'SPECTROGRAM_COMPRESSION' => $b,
    'SPECTROGRAM_FLOOR_DB' => array('int', -120, -40),
    'SPECTROGRAM_RANGE_DB' => array('int', 30, 120),
    'SPECTROGRAM_CONTRAST' => array('num', 0.5, 2),
    'RAW_SPECTROGRAM' => $b,
    'ACTIVATE_KIOSK' => $tf,
    // updates
    'SILENCE_UPDATE_INDICATOR' => $b,
    'AUTOMATIC_UPDATE' => $b,
    'AUTOMATIC_UPDATE_DAYS' => $days,
    'AUTOMATIC_UPDATE_TIME' => $hm,
    // field access
    'FIELD_WIFI_MODE' => array('enum', array('always', 'cycle', 'off')),
    'FIELD_BOOT_WAIT_SEC' => array('int', 0, 3600),
    'FIELD_LOST_WAIT_SEC' => array('int', 0, 3600),
    'FIELD_AP_WINDOW_MIN' => array('int', 1, 120),
    'FIELD_CYCLE_MIN' => array('int', 1, 1440),
    'FIELD_AP_IDLE_MIN' => array('int', 1, 1440),
    // eBird
    'EBIRD_REPORT_ENABLED' => $b,
    'EBIRD_REPORT_FREQ' => array('enum', array('daily', 'weekly')),
    'EBIRD_REPORT_DAY' => array('int', 1, 7),
    'EBIRD_REPORT_TIME' => $hm,
    'EBIRD_REPORT_NAME' => array('text', 100),
    'EBIRD_REPORT_SPECIES' => array('enum', array('all', 'confirmed', 'minconf')),
    'EBIRD_REPORT_MIN_CONF' => array('num', 0, 0.99),
    'EBIRD_PAGE_MODE' => array('enum', array('all', 'confirmed', 'minconf')),
    'EBIRD_PAGE_MIN_CONF' => array('num', 0, 0.99),
    'EBIRD_LOCATION_NAME' => array('text', 100),
    'EBIRD_LOCATION_NAMES' => array('text', 1010),
  );
  return $r;
}
// the value to write for this key, or null when the key is unknown or the value breaks its rule
function conf_check($key, $value) {
  $rules = conf_rules();
  if (!is_string($key) || !isset($rules[$key]) || !is_scalar($value)) return null;
  $rule = $rules[$key];
  $v = trim((string)$value);
  if (preg_match('/[\x00-\x1F\x7F]/', $v)) return null;
  // a single-quoted key (the web password's bcrypt hash, $2b$12$...): inside single quotes nothing expands, only a
  // single quote could end it (2026-11-03: the '$' check below refused every hash, so no page could change the password)
  if (!empty($rule['sq'])) {
    if (strpos($v, "'") !== false) return null;
  } else {
    $quoted = $rule[0] === 'text' || $rule[0] === 'tpl' || !empty($rule['q']);
    if (preg_match($quoted ? '/[`"\\\\]/' : '/[$`"\\\\;|&<>]/', $v)) return null;
    // inside double quotes only $name expands (to nothing in a script that does not set it); never $( or ${
    if ($quoted && preg_match($rule[0] === 'tpl' ? '/\$(?![A-Za-z_])/' : '/\$/', $v)) return null;
  }
  switch ($rule[0]) {
    case 'enum': return in_array($v, $rule[1], true) ? $v : null;
    case 'int': return preg_match('/^-?\d{1,9}$/', $v) && intval($v) >= $rule[1] && intval($v) <= $rule[2] ? (string)intval($v) : null;
    case 'num': return preg_match('/^-?\d{1,9}(\.\d{1,8})?$/', $v) && floatval($v) >= $rule[1] && floatval($v) <= $rule[2] ? $v : null;
    case 're': return preg_match($rule[1], $v) ? $v : null;
    case 'text': case 'tpl': return mb_strlen($v) <= $rule[1] ? $v : null;
    case 'fn': return call_user_func($rule[1], $v) ? $v : null;
  }
  return null;
}
// write one key; $err gets the reason when it answers false
function conf_set($key, $value, &$err = null) {
  return conf_set_many(array($key => $value), $err);
}
// write several keys at once: all of them pass their rule or nothing is written
function conf_set_many($pairs, &$err = null) {
  $err = '';
  $clean = array();
  foreach ((array)$pairs as $k => $v) {
    $c = conf_check($k, $v);
    if ($c === null) { $err = t('str_not_a_valid_value_for', array('key' => $k)); return false; }
    $clean[$k] = $c;
  }
  if (!$clean) return true;
  $target = realpath(BS_CONF) ?: BS_CONF;
  if (!is_dir(BS_STATE)) @mkdir(BS_STATE, 0775, true);
  $lock = @fopen(BS_STATE . '/settings.lock', 'c');
  if (!$lock || !flock($lock, LOCK_EX)) { $err = t('str_error_writing_the_settings'); return false; }
  $c = (string)@file_get_contents($target);
  foreach ($clean as $k => $v) {
    $re = '/^' . preg_quote($k, '/') . '=(.*)$/m';
    $was_quoted = preg_match($re, $c, $m) && strpos($m[1], '"') === 0;
    $rule = conf_rules()[$k] ?? array();
    if (!empty($rule['sq'])) $line = $k . "='" . $v . "'";
    else $line = $k . '=' . ($was_quoted || !preg_match('/^[A-Za-z0-9._:,\/+@%=-]*$/', $v) ? '"' . $v . '"' : $v);
    if (preg_match($re, $c)) $c = preg_replace_callback($re, function () use ($line) { return $line; }, $c);
    else $c = rtrim($c, "\n") . "\n" . $line . "\n";
  }
  // a new file beside it, renamed over it: a reader never sees half a file; the mode and the group of the old one kept
  // (the pages may run as another user than the station's, in its group)
  $tmp = $target . '.tmp' . getmypid();
  $ok = file_put_contents($tmp, $c) !== false;
  if ($ok) {
    $mode = @fileperms($target);
    @chmod($tmp, $mode !== false ? ($mode & 0777) : 0664);
    $grp = @filegroup($target);
    if ($grp !== false) @chgrp($tmp, $grp);
    $ok = rename($tmp, $target);
  }
  if (!$ok) { @unlink($tmp); $err = t('str_error_writing_the_settings'); }
  flock($lock, LOCK_UN);
  fclose($lock);
  if ($ok) get_config(true);
  return $ok;
}

function get_user() {
  $config = get_config();
  $user = $config['BIRDNET_USER'];
  return $user;
}

// the station user's home (core.php: the folder above the program folder)
function get_home() {
  return BS_HOME;
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
// The review lists exactly what its source passed (owner 2026-11-02: "the review player must list exactly what the
// source table, filtered or not, passed"). Validation counts per species the detections of its period, at its location,
// without an answer; its Review opens the species page in review mode with those filters frozen in the link: rvf / rvt
// the period's dates (both empty = All), rvs the moment of Last 24 h, rvu the moment the table was drawn (a detection
// made later was not counted), loc the location shown (location_filter). The species page lists the same rows; an older
// link without them falls back to Validation's kept period, up to now.
function validation_source() {
  $day = function ($k) { $v = (string)($_GET[$k] ?? ''); return preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) ? $v : ''; };
  $at = function ($k) { $v = (string)($_GET[$k] ?? ''); return preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $v) ? $v : ''; };
  if (isset($_GET['rvf'])) {
    $f = $day('rvf'); $t = $day('rvt') ?: $f;
    if ($t < $f) { $x = $f; $f = $t; $t = $x; }
    return array($f, $f === '' ? '' : $t, $f === '' ? '' : $at('rvs'), $at('rvu'));
  }
  list($f, $t, $s) = validation_period() + array(2 => '');
  return array($f, $t, $s, '');
}
// the SQL condition of those rows (' AND ...'; the values are checked by validation_source)
function validation_source_sql($src) {
  list($f, $t, $s, $u) = $src;
  $w = $f !== '' ? " AND Date BETWEEN '" . $f . "' AND '" . $t . "'" . ($s !== '' ? " AND Date || ' ' || Time >= '" . $s . "'" : '') : '';
  // the date first, so the index on Date still narrows the rows
  if ($u !== '') $w .= " AND Date <= '" . substr($u, 0, 10) . "' AND Date || ' ' || Time <= '" . $u . "'";
  return $w;
}
// the link parameters Validation hands to the review (period, the moment drawn, the location shown)
function validation_source_params($from, $to, $since, $upto) {
  $l = location_shown();
  $loc = location_filter() === 'all' ? 'all' : ($l ? (string)intval($l['id']) : '');
  return '&rvf=' . rawurlencode($from) . '&rvt=' . rawurlencode($to) . ($since !== '' ? '&rvs=' . rawurlencode($since) : '')
    . '&rvu=' . rawurlencode($upto) . ($loc !== '' && locations_with_data() >= 2 ? '&loc=' . $loc : '');
}

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
    $o .= '<a class="stdbtn pnav" href="' . $u('&amp;from=' . $from . '&amp;to=' . $from) . '" title="' . $h(t('str_the_period_before')) . '">&#9664;</a>';
  } elseif ($days) {
    $pf = date('Y-m-d', strtotime($from . " -$days days")); $pt = date('Y-m-d', strtotime($to . " -$days days"));
    $o .= '<a class="stdbtn pnav" href="' . $u('&amp;from=' . $pf . '&amp;to=' . $pt) . '" title="' . $h(t('str_the_period_before')) . '">&#9664;</a>';
  }
  $sel = function ($c) { return $c ? ' selected' : ''; };
  $o .= '<select class="dashperiod" aria-label="' . htmlspecialchars(t('str_period'), ENT_QUOTES) . '" onchange="if (this.value === \'range\') this.parentNode.querySelector(\'.prange\').style.display = \'\'; else if (this.value) location.href = this.value">'
    . ($since !== null ? '<option value="' . $u('&amp;period=last24') . '"' . $sel($is24) . '>' . t('str_last_24_h') . '</option>'
      : '<option value="' . $u('&amp;period=today') . '"' . $sel($is_today) . '>' . t('str_today') . '</option>')
    . '<option value="' . $u('&amp;period=last7') . '"' . $sel($is7) . '>' . t('str_last_7_days') . '</option>'
    . '<option value="' . $u('&amp;period=last30') . '"' . $sel($is30) . '>' . t('str_last_30_days') . '</option>'
    // ascending, as every period picklist (owner 2026-10-22): Today, the last days, the range, then All
    . ($is_other ? '<option value="" selected hidden disabled>' . $h($from === $to ? t(weekday_key($from)) . date(' d/m/Y', strtotime($from)) : date('d/m/Y', strtotime($from)) . ' - ' . date('d/m/Y', strtotime($to))) . '</option>' : '')
    . '<option value="range">' . t('str_date_range') . '</option>'
    . '<option value="' . $u('&amp;period=all') . '"' . $sel($from === '') . '>' . t('str_all') . '</option></select>';
  if ($is24) {
    $o .= '<span class="stdbtn pnav off">&#9654;</span>';
  } elseif ($days) {
    $nf = date('Y-m-d', strtotime($from . " +$days days")); $nt = min($today, date('Y-m-d', strtotime($to . " +$days days")));
    $o .= $nf <= $today ? '<a class="stdbtn pnav" href="' . $u('&amp;from=' . $nf . '&amp;to=' . $nt) . '" title="' . $h(t('str_the_period_after')) . '">&#9654;</a>'
      : '<span class="stdbtn pnav off">&#9654;</span>';
  }
  parse_str(html_entity_decode(parse_url($base, PHP_URL_QUERY) ?: ''), $bq);
  $hidden = '';
  foreach ($bq + $keep as $k => $v) if ($v !== '') $hidden .= '<input type="hidden" name="' . $h($k) . '" value="' . $h($v) . '">';
  $o .= '<form class="yrange prange" method="GET" action="views.php"' . ($range_open ? '' : ' style="display:none"') . '>' . $hidden . '<input type="hidden" name="pr" value="1">'
    . t('str_from') . ' <input type="date" name="from" value="' . $h($from ?: $first) . '" max="' . $today . '"> '
    . t('str_to_lc') . ' <input type="date" name="to" value="' . $h($to ?: $today) . '" max="' . $today . '"> '
    . '<button type="submit">' . t('str_filter') . '</button></form></span>';
  $val = $from === '' ? 'all' : ($is24 ? 'last24' : ($is_today ? 'today' : ($is7 ? 'last7' : ($is30 ? 'last30' : $from . ':' . $to))));
  $o .= '<script>try { document.cookie = ' . json_encode($cookie . '=' . $val) . ' + \';path=/;max-age=31536000;SameSite=Lax\'; } catch (e) {}</script>';
  return $o;
}

// The release this installation runs (2026-10-22): a fresh install is a shallow clone (newinstaller.sh --depth=1), where
// git describe finds no tag (Status said "BirdSuite unknown"); then the vX.Y.Z tag on HEAD, else the newest one not newer
// than HEAD. Returns array(tag or '', commits after it: a number, or null when the shallow history cannot count them).
// how far the installation is behind its update branch, from the last fetch (FETCH_HEAD, as update_birdnet.sh compares;
// the update branch can differ from the checked-out one): the one reader of the menu badge and of Status (US-05)
function update_behind() {
  $n = trim((string)shell_exec(station_prefix() . 'git -C ' . escapeshellarg(BS_PROG) . ' rev-list --count HEAD..FETCH_HEAD 2>/dev/null'));
  return ctype_digit($n) ? $n : '0';
}
// seconds since the last fetch of the update branch (a large number when there was none)
function update_fetch_age() {
  $t = @filemtime(BS_PROG . '/.git/FETCH_HEAD');
  return $t ? time() - $t : PHP_INT_MAX;
}
function installed_release() {
  static $r = null;
  if ($r !== null) return $r;
  $git = station_prefix() . 'git -C ' . BS_PROG . ' ';
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
// so changing the language split a species in two). A clip is By_Date/<date>/<Sci_safe>/<Sci_safe>-<date>_<time>-...
// The same rule in scripts/utils/helpers.py (species_folder).
function name_safe($name) {
  return str_replace("'", '', str_replace(' ', '_', (string)$name));
}
function species_folder($sci) {
  return name_safe($sci);
}
// the clip's name without its species ('-2026-10-01_06h00m01s-...'), as helpers.py clip_rest; '' when it has no date
function clip_rest($file) {
  return preg_match('/-(\d{1,3}-)?\d{4}-\d{2}-\d{2}[-_]/', (string)$file, $m, PREG_OFFSET_CAPTURE) ? substr((string)$file, $m[0][1]) : '';
}
// a detection's clip under By_Date (or Purge / Removed): <date>/<folder>/<file>; $r has Date, Sci_Name, File_Name
function clip_rel($r) {
  return $r['Date'] . '/' . species_folder($r['Sci_Name']) . '/' . $r['File_Name'];
}
// the languages of the common names (model/l18n/labels_<code>.json): Model › Common name Language, the export in the old
// model (Backup/Restore); the names are translated with t()
function common_name_languages() {
  return array(
    "af" => "str_afrikaans",
    "ar" => "str_arabic",
    "ca" => "str_catalan",
    "cs" => "str_czech",
    "zh_CN" => "str_chinese_simplified",
    "zh_TW" => "str_chinese_traditional",
    "hr" => "str_croatian",
    "da" => "str_danish",
    "nl" => "str_dutch",
    "en" => "str_english",
    "et" => "str_estonian",
    "fi" => "str_finnish",
    "fr" => "str_french",
    "de" => "str_german",
    "hu" => "str_hungarian",
    "is" => "str_icelandic",
    "id" => "str_indonesia",
    "it" => "str_italian",
    "ja" => "str_japanese",
    "ko" => "str_korean",
    "lv" => "str_latvian",
    "lt" => "str_lithuania",
    "no" => "str_norwegian",
    "pl" => "str_polish",
    "pt_BR" => "str_portuguese_brazil_cbro",
    "pt_PT" => "str_portuguese_portugal",
    "ro" => "str_romanian",
    "ru" => "str_russian",
    "sr" => "str_serbian",
    "sk" => "str_slovak",
    "sl" => "str_slovenian",
    "es" => "str_spanish",
    "sv" => "str_swedish",
    "th" => "str_thai",
    "tr" => "str_turkish",
    "uk" => "str_ukrainian",
    "vi" => "str_vietnamese"
  );
}
// The spectrogram of a clip (owner 2026-10-22): <clip>.png while the day runs, <clip>.jpg once spectro_archive.py
// stored it for the long term. $clip = the clip's web path under Extracted ('/By_Date/...' or 'By_Date/...').
function clip_spectrogram($clip) {
  $disk = BS_EXTRACTED . '/' . ltrim($clip, '/');
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
// the default station name until one is chosen (Settings): BirdSuite-<the last 4 digits of the MAC> (owner 2026-11-05,
// privacy: the name goes out in notifications and is the station WiFi's and Bluetooth's; as field_net.py ap_ssid())
function default_sitename() {
  $m = strtoupper(substr(station_mac(true), -4));
  return 'BirdSuite' . ($m !== '' ? '-' . $m : '');
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
  $service_mount = trim(shell_exec("systemd-escape -p --suffix=mount " . BS_STREAMDATA));
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

// the web password in force: the one set in Settings › Access, else the factory one (owner 2026-11-02: a password on
// everything, a station is never open)
// the web password is kept only as a bcrypt hash ($2b$, read by Caddy and password_verify; SEC-15): the hash to store
function web_password_hash($plain) {
  return '$2b$' . substr(password_hash((string)$plain, PASSWORD_BCRYPT), 4);
}
// the given password is the one in force: the stored hash, else the factory password; a plain value left by an older
// station (converted by the next update, scripts/web_password_hash.py) is compared as it is
function web_password_matches($given) {
  $stored = (string)(get_config()['CADDY_PWD'] ?? '');
  if ($stored === '') return hash_equals(BS_WEB_PASSWORD_DEFAULT, (string)$given);
  if (preg_match('/^\$2[aby]\$/', $stored)) return password_verify((string)$given, $stored);
  return hash_equals($stored, (string)$given);
}
// the password in force is the factory one: none set, or the factory password stored as its hash (a new install writes
// it so, 2026-11-05); one bcrypt check per stored hash, kept for the session
function web_password_is_factory() {
  static $memo = array();
  $stored = (string)(get_config()['CADDY_PWD'] ?? '');
  if ($stored === '') return true;
  $k = md5($stored);
  if (!isset($memo[$k])) {
    if (isset($_SESSION['bs_pwd_factory'][$k])) {
      $memo[$k] = (bool)$_SESSION['bs_pwd_factory'][$k];
    } else {
      $memo[$k] = web_password_matches(BS_WEB_PASSWORD_DEFAULT);
      if (session_status() === PHP_SESSION_ACTIVE) $_SESSION['bs_pwd_factory'][$k] = $memo[$k];
    }
  }
  return $memo[$k];
}

// the named users (US-56, owner 2026-11-04): config/users.json, station-own, outside git, 0600: name, role (viewer,
// operator, admin), level ('' = the station's), bcrypt hash. Without the file the station has one user, the web login
// of Settings › Access (CADDY_USER / CADDY_PWD), an admin; the first user added writes the file with that one in it.
define('BS_USERS_FILE', BS_CONFIG . '/users.json');
function web_users() {
  static $u = null;
  if ($u !== null) return $u;
  $u = array();
  $j = is_file(BS_USERS_FILE) ? json_decode((string)@file_get_contents(BS_USERS_FILE), true) : null;
  if (is_array($j) && !empty($j['users'])) {
    foreach ($j['users'] as $e) {
      if (!is_array($e) || !preg_match('/^[A-Za-z0-9]{1,32}$/', (string)($e['name'] ?? ''))) continue;
      $u[$e['name']] = array('name' => $e['name'], 'role' => in_array($e['role'] ?? '', array('viewer', 'operator', 'admin'), true) ? $e['role'] : 'viewer',
        'level' => isset(ui_levels()[$e['level'] ?? '']) ? $e['level'] : '', 'hash' => (string)($e['hash'] ?? ''), 'main' => !empty($e['main']));
    }
  }
  if (!$u) $u[web_user()] = array('name' => web_user(), 'role' => 'admin', 'level' => '', 'hash' => (string)(get_config()['CADDY_PWD'] ?? ''), 'main' => true);
  return $u;
}
// the user this request logged in as (Basic login), or null
function login_user() {
  static $done = false, $who = null;
  if ($done) return $who;
  $done = true;
  if (!isset($_SERVER['PHP_AUTH_USER'])) return null;
  $e = web_users()[(string)$_SERVER['PHP_AUTH_USER']] ?? null;
  if ($e === null) return null;
  $given = (string)($_SERVER['PHP_AUTH_PW'] ?? '');
  // the main user is the web login of Settings › Access: its password in force (the factory one while none is set)
  $ok = $e['main'] ? web_password_matches($given) : ($e['hash'] !== '' && password_verify($given, $e['hash']));
  if (!$ok) return $who = null;
  // the factory password (public in the program) is never a login (bug #60, run 5 NEW-SEC-R5-02): while no password is
  // set it only opens the page that sets one (Station › Access); everywhere else it is a viewer
  if ($e['main'] && !web_login_off() && hash_equals(BS_WEB_PASSWORD_DEFAULT, $given)) { $e['role'] = 'viewer'; $e['factory'] = true; }
  return $who = $e;
}
// no password of the station's own yet: the factory password is in force (none set, or the factory one stored), with
// the login on
function web_password_unset() {
  return !web_login_off() && web_password_is_factory();
}
// this request logged in with the factory password
function factory_login() {
  $u = login_user();
  return $u !== null && !empty($u['factory']);
}
// the page that sets the password (Station › Access): an admin, or the factory password, which may only
// leave the factory state there (a password of its own, or the login Off)
function ensure_password_setup() {
  if (factory_login()) {
    if (!is_same_origin_request()) { header('HTTP/1.0 403 Forbidden'); echo t('str_refused_the_request_came_from_help'); exit; }
    return;
  }
  ensure_authenticated();
}
// admin access: the station's own call, an open station, or a logged-in admin (what every page's check asks)
function is_authenticated() {
  // the station's own calls (localhost) do not log in, as in the Caddyfile
  if (in_array($_SERVER['REMOTE_ADDR'] ?? '', array('127.0.0.1', '::1'), true)) return true;
  // an open station (WEB_LOGIN=off, owner 2026-11-04): nobody logs in, no dialog anywhere
  if (web_login_off()) return true;
  $u = login_user();
  return $u !== null && $u['role'] === 'admin';
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

// the station is open: no web login at all (US-53)
function web_login_off() { return strtolower(trim((string)(get_config()['WEB_LOGIN'] ?? 'on'), " \"'")) === 'off'; }
// a device on a private network: the LAN, the station WiFi, the VPN (the Caddyfile's private_ranges)
function is_private_client() {
  $ip = (string)($_SERVER['REMOTE_ADDR'] ?? '');
  if ($ip === '' || in_array($ip, array('127.0.0.1', '::1'), true)) return $ip !== '';
  // 10/8, 172.16/12, 192.168/16, fc00::/7: what PHP calls private, as Caddy's private_ranges
  return filter_var($ip, FILTER_VALIDATE_IP) !== false && filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE) === false;
}
// the role of this request (US-53): admin = logged in, the station's own call or an open station; operator = a device on
// a private network of a household station (LAN_TRUST=household: reviews and lists without the login); else viewer
function current_role() {
  if (is_authenticated()) return 'admin';
  $r = ($u = login_user()) !== null ? $u['role'] : 'viewer';
  if (role_rank($r) < 1 && strtolower(trim((string)(get_config()['LAN_TRUST'] ?? 'everyone'), " \"'")) === 'household' && is_private_client()) $r = 'operator';
  return $r;
}
function role_rank($r) { return array('viewer' => 0, 'operator' => 1, 'admin' => 2)[$r] ?? 2; }
// this request has the role, or the login is asked (the same answers as ensure_authenticated)
function ensure_role($role, $error_message = 'str_you_cannot_edit_the_settings_help') {
  if ($role === 'viewer') return;
  if (role_rank(current_role()) >= role_rank($role)) {
    if (!is_same_origin_request()) { header('HTTP/1.0 403 Forbidden'); echo t('str_refused_the_request_came_from_help'); exit; }
    return;
  }
  ensure_authenticated($error_message);
}
// a logged-in user whose role is not enough: refused, no new login dialog (the selector switches user)
function refuse_role() {
  header('HTTP/1.0 403 Forbidden');
  echo '<table><tr><td>' . htmlspecialchars(t('str_this_user_may_not_do_this_help', array('user' => (string)($_SERVER['PHP_AUTH_USER'] ?? '')))) . '</td></tr></table>';
  exit;
}
// a notification channel (email, telegram) sends: set up and its test confirmed by the owner (utils/notify_channels.py)
function notify_channel_confirmed($channel) {
  return notify_channel_state($channel) === 'confirmed';
}
// its state: confirmed | off (switched off on its page) | setup | failed, '' when never tested
function notify_channel_state($channel) {
  $j = json_decode((string)@file_get_contents(BS_STATE . '/notify_channels.json'), true);
  return is_array($j) ? (string)($j[$channel]['state'] ?? '') : '';
}
function ensure_authenticated($error_message = 'str_you_cannot_edit_the_settings_help') {
  if (!is_same_origin_request()) {
    header('HTTP/1.0 403 Forbidden');
    echo t('str_refused_the_request_came_from_help');
    exit;
  }
  if (!is_authenticated()) {
    if (factory_login()) {
      header('HTTP/1.0 403 Forbidden');
      // a page gets the reason with the link; a request of a page's script (a save, a switch) gets plain text, which
      // the script shows as it is (2026-11-05: the hearing window showed the table's markup)
      if (($_SERVER['HTTP_SEC_FETCH_DEST'] ?? 'document') !== 'document' || ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
        header('Content-Type: text/plain; charset=utf-8');
        echo t('str_factory_password_only_sets_help') . ' ' . menu_path('Access');
      } else {
        echo '<table><tr><td>' . htmlspecialchars(t('str_factory_password_only_sets_help')) . ' <a href="/views.php?view=Access">' . htmlspecialchars(menu_path('Access')) . '</a>' . '</td></tr></table>';
      }
      exit;
    }
    if (login_user() !== null) refuse_role();
    header('WWW-Authenticate: Basic realm="BirdSuite - user name: ' . web_user() . '"');
    header('HTTP/1.0 401 Unauthorized');
    // the callers' messages are English keys; t() of an already translated text answers it unchanged
    echo '<table><tr><td>' . t($error_message)
      // a forgotten password (US-53): the reset page, from a private network
      . (is_private_client() ? '<br><a href="/scripts/password_reset.php">' . htmlspecialchars(t('str_forgot_the_password')) . '</a>' : '')
      . '</td></tr></table>';
    exit;
  }
}

function debug_log($message) {
  if (is_bool($message)) {
    $message = $message ? 'true' : 'false';
  }
  error_log($message . "\n", 3, $_SERVER['DOCUMENT_ROOT'] . "/debug_log.log");
}

// Species names are presentation only (owner 2026-11-05: "one index only"): the database, the analysis, the lists and the
// files know a species by its scientific name alone; the common name (the l18n file of the Common name Language,
// model/l18n/labels_<lang>.json) and the English name (the model's own, get_english_name()) are looked up when a page
// shows them. common_name() falls back on the English name, then on the scientific name.
function common_name($sci) {
  static $map = null;
  if ($map === null) {
    $lang = preg_replace('/[^A-Za-z_]/', '', (string)(get_config()['DATABASE_LANG'] ?? 'en')) ?: 'en';
    $map = json_decode((string)@file_get_contents(BS_MODEL . "/l18n/labels_$lang.json"), true);
    if (!is_array($map)) $map = array();
  }
  $sci = (string)$sci;
  if (isset($map[$sci]) && $map[$sci] !== '') return $map[$sci];
  $en = get_english_name($sci);
  return $en !== '' ? $en : $sci;
}
// the names as SQL functions of a connection: com_name(Sci_Name), en_name(Sci_Name) (deterministic, cheap: a lookup in
// a static map), so a query can show or sort by the name while every table holds the scientific name only
function db_names($db) {
  if (!($db instanceof SQLite3)) return $db;
  @$db->createFunction('com_name', 'common_name', 1, SQLITE3_DETERMINISTIC);
  @$db->createFunction('en_name', 'get_english_name', 1, SQLITE3_DETERMINISTIC);
  return $db;
}

// the English name shown (the name picklist's English, the tables): always the model's own (owner 2026-11-05, the BirdNET
// way), never the l18n file of a language; see get_english_name()
function get_com_en_name($sci_name) {
  $en = get_english_name($sci_name);
  return $en === '' ? null : $en;
}

function get_label($record, $sort_by, $date=null) {
  $name = common_name($record["Sci_Name"] ?? '');
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
// Stored in config/locations/locations.json (station data, outside git; the folder also holds each location's own files,
// e.g. its Now page backgrounds, owner 2026-11-02); seeded with the configured coordinates.
function locations_file() {
  return BS_LOCATIONS . '/locations.json';
}
// Built-in locations (owner 2026-10-20): "Home", the first one, and "Test / Playback", a second one for tests and for
// playing recordings to the station, so those detections stay apart and are deleted at once (Tools › Location Data,
// Delete all, then Species › Purge Files). Stored with their English name and a "builtin" mark; shown translated
// until renamed. The test location takes the coordinates of the first (primary) location (owner 2026-10-20), radius
// 50 m: the same place for the model, while the detections made when it is current carry its id (column Location,
// owner 2026-10-21) and are filed there and nowhere else. Nothing is notified
// or uploaded while it is current (utils/helpers.is_test_location). It is created once the first location has real
// coordinates.
function location_builtin_names() { return array('home' => 'Home', 'test' => 'Test / Playback'); }
// their text keys (locations.json keeps the English names above until a location is renamed)
function location_builtin_keys() { return array('home' => 'str_home', 'test' => 'str_test_playback'); }
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
    if (!is_dir(BS_LOCATIONS)) @mkdir(BS_LOCATIONS, 02775);
    @file_put_contents(locations_file(), json_encode(array_values($list), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n", LOCK_EX);
    @chmod(locations_file(), 0664); // the station user writes it too (scripts/location_data.py restore)
  }
  // the built-in names in the interface language, as long as they were not renamed
  $names = location_builtin_names();
  $nkeys = location_builtin_keys();
  foreach ($list as &$l) {
    $b = $l['builtin'] ?? '';
    if (isset($names[$b]) && $l['name'] === $names[$b]) $l['name'] = t($nkeys[$b]);
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
  $nkeys = location_builtin_keys();
  // the Now page background of a location (owner 2026-11-02) stays with it as the biome does;
  // the biome found for a location (US-27) stays with it; scripts/utils/locations.py biomes finds it again when the
  // coordinates changed (biome_at) and for a new location, in the background, and writes it on its detections
  $kept = array();
  foreach (locations_load() as $o) $kept[intval($o['id'])] = array_intersect_key($o, array_flip(array('biome', 'ecoregion', 'biome_br', 'biome_at', 'land_cover', 'built_up_pct', 'land_at', 'setting', 'built_up_500_pct', 'now_background', 'now_background_fade')));
  foreach ($list as &$l) {
    $b = $l['builtin'] ?? '';
    if (isset($names[$b]) && in_array($l['name'], array($names[$b], t($nkeys[$b])), true)) $l['name'] = $names[$b];
    if (!isset($l['biome']) && !empty($kept[intval($l['id'] ?? 0)])) $l += $kept[intval($l['id'])];
  }
  unset($l);
  if (!is_dir(BS_LOCATIONS)) @mkdir(BS_LOCATIONS, 02775);
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
  if ($n === null) $n = intval(get_db()->querySingle('SELECT COUNT(DISTINCT Location) FROM main.detections'));  // main.: the whole table, not the current location's view
  return $n;
}
function location_filter() {
  // the same data filter on every page, kept per page (owner 2026-10-22): only Now opens on All, the others on the
  // current location, until another choice; empty = the current location, a number = that location (whichever
  // is active); only while more than one location has detections
  if (locations_with_data() < 2) return 'current';
  list($ck, $def) = location_filter_page();
  // a page opened from another one carries that page's choice (?loc=all or a location id: the review of a Validation
  // species shows the detections Validation counted), ahead of its own kept choice
  $v = $_GET['loc'] ?? null;
  if ($v === 'all' || (ctype_digit((string)$v) && location_by_id($v))) return $v === 'all' ? 'all' : (string)intval($v);
  $v = $_COOKIE[$ck] ?? null;
  if ($v === null) return $def;
  if ($v === 'all') return 'all';
  if (ctype_digit((string)$v) && location_by_id($v)) return (string)intval($v);
  return 'current';
}
// the cookie and the default of the page's location filter: only Now (and its lists) on All, every other page on the
// current location (owner 2026-10-22)
function location_filter_page() {
  $view = defined('NOW_PAGE') ? 'Latest' : (string)($_GET['view'] ?? pathinfo($_SERVER['SCRIPT_NAME'] ?? '', PATHINFO_FILENAME));
  if ($view === 'curation') $view = 'Review';  // the table requests of scripts/curation.php share the page's choice
  // a renamed page keeps the cookie of its first name (US-51): its alias, so the location chosen there is not lost
  $e = view_entry(view_resolve($view));
  if ($e !== null && $e['alias']) $view = $e['alias'][0];
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
    $r = get_db()->query('SELECT DISTINCT Location FROM main.detections');
    while ($r && ($x = $r->fetchArray(SQLITE3_NUM))) $ids[] = intval($x[0]);
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
  $o = '<span class="locpick"><label for="locpick">' . t('str_loc') . '</label> <select id="locpick" class="nowperiod" title="' . $h(t('str_locations_shown_in_the_table_help')) . '"'
    . ' onchange="document.cookie = ' . $h(json_encode(location_filter_page()[0] . '=')) . ' + this.value + \';path=/;max-age=31536000;SameSite=Lax\'; location.reload()">'
    . '<option value=""' . ($f === 'current' || ($cur && $f === (string)intval($cur['id'])) ? ' selected' : '') . '>' . $h($cur ? $cur['name'] : t('str_current_location')) . '</option>';
  foreach (locations_load() as $l) {
    if (($cur && intval($l['id']) === intval($cur['id'])) || !in_array(intval($l['id']), $ids, true)) continue;
    $o .= '<option value="' . intval($l['id']) . '"' . ($f === (string)intval($l['id']) ? ' selected' : '') . '>' . $h($l['name']) . '</option>';
  }
  $o .= '<option value="all"' . ($f === 'all' ? ' selected' : '') . '>' . t('str_all') . '</option></select></span>'
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
  $name = $l ? $l['name'] : t('str_all_locations');
  return '<div class="loclabel">&#128205; ' . t('str_location_colon') . ' <b>' . htmlspecialchars($name) . '</b></div>';
}
// the location of one detection for the tables (owner 2026-10-21): its tag
function detection_location_name($row) {
  static $names = null;
  if ($names === null) { $names = array(); foreach (locations_load() as $l) $names[intval($l['id'])] = $l['name']; }
  if (isset($row['Location']) && $row['Location'] !== null && $row['Location'] !== '') return $names[intval($row['Location'])] ?? '#' . intval($row['Location']);
  return '-';
}
// the columns the detection tables read for detection_location_name
function location_columns_sql() {
  return 'Lat, Lon, Location';
}
// the detections of a location (owner 2026-10-21): every detection carries the id of the location it was recorded at
// (column Location), so two locations may share their coordinates (the same building, another window)
function location_sql($l) {
  return 'Location = ' . intval($l['id']);
}
// the location the pages show, or null for all of them
function location_shown() {
  $f = location_filter();
  if ($f === 'all') return null;
  if ($f === 'current') return location_current();
  return location_by_id($f) ?: null;
}
// the station's coordinates (BirdSuite.conf LATITUDE/LONGITUDE, 4 decimals) and, when the species list is a Brazilian
// state, the state's list there (scripts/locate_state.py); the analysis reads them from the next recording.
// Used by the Latest page answers (station moved, microphone reconnected) and the Locations page. Returns '' or an error.
function apply_station_coordinates($lat, $lon) {
  $home = get_home();
  $config = get_config();
  $lat = round(floatval($lat), 4); $lon = round(floatval($lon), 4);
  $set = array('LATITUDE' => (string)$lat, 'LONGITUDE' => (string)$lon);
  $new_list = null;
  if (preg_match('/^BR-[A-Z]{2}$/', $config['SPECIES_LIST'] ?? '')) {
    $uf = trim((string)shell_exec('python3 ' . escapeshellarg(BS_SCRIPTS . '/locate_state.py') . ' ' . escapeshellarg((string)$lat) . ' ' . escapeshellarg((string)$lon) . ' 2>/dev/null'));
    if (preg_match('/^[A-Z]{2}$/', $uf) && is_file(BS_MODEL . "/include_lists/BR-$uf.txt")) {
      $new_list = "BR-$uf";
      $set['SPECIES_LIST'] = $new_list;
    }
  }
  if (!conf_set_many($set, $err)) return $err;
  if ($new_list !== null && $new_list !== ($config['SPECIES_LIST'] ?? '')) {
    shell_exec(station_prefix() . 'python3 ' . escapeshellarg(BS_SCRIPTS . '/select_species_list.py') . ' > /dev/null 2>&1');
  }
  return '';
}
// Settings › Locations and First Run (owner 2026-10-20): the location holding these coordinates takes this name, or a new
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
  return ($out['ok'] ?? false) ? '' : ($out['error'] ?? t('str_the_clock_was_not_set'));
}

// the species list filter choices (SPECIES_LIST): none, the station lists of species_lists/, the Brazilian states not
// built yet — the select of Settings › Locations (owner 2026-10-20; was in the retired Basic Settings page)
function species_list_options($cur) {
  $home = get_home();
  $br = array('AC' => 'Acre', 'AL' => 'Alagoas', 'AP' => 'Amapá', 'AM' => 'Amazonas', 'BA' => 'Bahia', 'CE' => 'Ceará', 'DF' => 'Distrito Federal',
    'ES' => 'Espírito Santo', 'GO' => 'Goiás', 'MA' => 'Maranhão', 'MT' => 'Mato Grosso', 'MS' => 'Mato Grosso do Sul', 'MG' => 'Minas Gerais',
    'PA' => 'Pará', 'PB' => 'Paraíba', 'PR' => 'Paraná', 'PE' => 'Pernambuco', 'PI' => 'Piauí', 'RJ' => 'Rio de Janeiro',
    'RN' => 'Rio Grande do Norte', 'RS' => 'Rio Grande do Sul', 'RO' => 'Rondônia', 'RR' => 'Roraima', 'SC' => 'Santa Catarina',
    'SP' => 'São Paulo', 'SE' => 'Sergipe', 'TO' => 'Tocantins');
  $h = function ($x) { return htmlspecialchars((string)$x, ENT_QUOTES); };
  $out = "<option value=''" . ($cur === '' ? ' selected' : '') . ">" . t('str_none_use_the_models_species_help') . "</option>";
  $own = array();
  foreach (glob(BS_CUSTOM_LISTS . '/*.txt') ?: array() as $f) $own[] = basename($f, '.txt');
  sort($own);
  if ($own) {
    $out .= "<optgroup label='" . $h(t('str_station_lists_species_lists_custom')) . "'>";
    foreach ($own as $n) $out .= "<option value='" . $h($n) . "'" . ($cur === $n ? ' selected' : '') . '>' . $h((strpos($n, 'BR-') === 0 && isset($br[substr($n, 3)])) ? "$n: " . $br[substr($n, 3)] : $n) . '</option>';
    $out .= '</optgroup>';
  }
  $out .= "<optgroup label='" . $h(t('str_brazilian_states_wikiaves_help')) . "'>";
  foreach ($br as $uf => $name) {
    if (in_array("BR-$uf", $own, true) || !is_file(BS_MODEL . "/include_lists/BR-$uf.txt")) continue;
    $out .= "<option value='BR-$uf'" . ($cur === "BR-$uf" ? ' selected' : '') . ">$name ($uf)</option>";
  }
  return $out . '</optgroup>';
}

// A location carries its own timezone and default species list (owner 2026-10-20); missing = the station's current ones.
function station_timezone() { return trim((string)shell_exec('timedatectl show --value --property=Timezone 2>/dev/null')); }
function set_station_timezone($tz) {
  if ($tz === '' || !in_array($tz, DateTimeZone::listIdentifiers(), true) || $tz === station_timezone()) return;
  // the root steps of a timezone change: timedatectl and /etc/timezone, in birdsuite-root (least privilege)
  shell_exec("sudo -n " . BS_ROOT_HELPER . " timezone " . escapeshellarg($tz));
}
function species_list_valid($list) {
  $home = get_home();
  return $list === '' || (preg_match('/^BR-[A-Z]{2}$/', $list) && is_file(BS_MODEL . "/include_lists/$list.txt"))
    || (preg_match('/^[A-Za-z0-9_-]+$/', $list) && is_file(BS_CUSTOM_LISTS . "/$list.txt"));
}
function set_species_list($list) {
  if (!species_list_valid($list) || $list === (get_config()['SPECIES_LIST'] ?? '')) return;
  if (!conf_set('SPECIES_LIST', $list)) return;
  shell_exec(station_prefix() . 'python3 ' . escapeshellarg(BS_SCRIPTS . '/select_species_list.py') . ' > /dev/null 2>&1');
}
// the station is now at this location: its coordinates, then its timezone and species list when it has them
// (Make current, Adjust, the microphone question, the Bluetooth GPS). Returns '' or an error.
function location_activate($l) {
  $err = apply_station_coordinates($l['lat'], $l['lon']);
  if ($err !== '') return $err;
  // the tag written on every new detection (owner 2026-10-21)
  if (!conf_set('LOCATION_ID', intval($l['id']), $err)) return $err;
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
  $uf = trim((string)shell_exec('python3 ' . escapeshellarg(BS_SCRIPTS . '/locate_state.py') . ' ' . escapeshellarg((string)floatval($lat))
    . ' ' . escapeshellarg((string)floatval($lon)) . ' --offline 2>/dev/null'));
  if (preg_match('/^[A-Z]{2}$/', $uf)) return array($br[$uf] ?? 'America/Sao_Paulo', t('str_brazil', array('uf' => $uf)));
  $ctx = stream_context_create(array('http' => array('timeout' => 4, 'header' => "User-Agent: BirdSuite\r\n")));
  $j = json_decode((string)@file_get_contents('https://timeapi.io/api/TimeZone/coordinate?latitude=' . floatval($lat) . '&longitude=' . floatval($lon), false, $ctx), true);
  if (!empty($j['timeZone']) && in_array($j['timeZone'], DateTimeZone::listIdentifiers(), true)) return array($j['timeZone'], 'timeapi.io');
  $off = (int)round(floatval($lon) / 15);
  return array($off === 0 ? 'Etc/GMT' : 'Etc/GMT' . ($off > 0 ? '-' : '+') . abs($off), t('str_from_the_longitude'));
}

// the microphone was plugged in again after being removed (scripts/mic_hotplug.sh): the Latest page asks where the
// station is; answered = the file is removed
function mic_reconnected_file() { return BS_STATE . '/mic_reconnected.json'; }
// what was typed in a setup guide, never a secret (owner 2026-11-03: "every box filled in is saved and shown again"):
// state/setup_drafts.json, private to the station user; the token and passwords stay in the token store
function setup_draft_get($name) {
  $d = json_decode((string)@file_get_contents(BS_STATE . '/setup_drafts.json'), true);
  return is_array($d) && is_array($d[$name] ?? null) ? $d[$name] : array();
}
function setup_draft_set($name, $values) {
  $f = BS_STATE . '/setup_drafts.json';
  $d = json_decode((string)@file_get_contents($f), true);
  if (!is_array($d)) $d = array();
  $clean = array();
  foreach ((array)$values as $k => $v) if (preg_match('/^[a-z_]{1,20}$/', (string)$k) && is_scalar($v)) $clean[$k] = mb_substr((string)$v, 0, 200);
  $d[$name] = $clean;
  $old = umask(0077);
  $ok = @file_put_contents($f . '.tmp', json_encode($d, JSON_UNESCAPED_UNICODE)) !== false && @rename($f . '.tmp', $f);
  umask($old);
  return $ok;
}

// the folders of the raw recordings (Tools › Raw Recording): ~/raw-recording (BS_RAW_REC), the default RAW_REC_DIR and
// the folder of every microphone's group (config/raw_recording.json)
function raw_rec_dirs() {
  $out = array(BS_RAW_REC);
  $d = trim((string)(get_config()['RAW_REC_DIR'] ?? ''), " \"'");
  if ($d !== '') $out[] = rtrim($d, '/');
  $g = json_decode((string)@file_get_contents(BS_RAW_REC_FILE), true);
  if (is_array($g)) foreach ($g as $x) if (!empty($x['dir'])) $out[] = rtrim((string)$x['dir'], '/');
  return array_values(array_unique($out));
}

function apply_location_filter($db) {
  db_names($db); // the species names as SQL functions (presentation only, see common_name())
  if (count(locations_load()) < 2) return; // one location: everything is there
  $l = location_shown();
  if ($l === null) return;
  @$db->exec('CREATE TEMP VIEW IF NOT EXISTS detections AS SELECT * FROM main.detections WHERE ' . location_sql($l));
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

// The change counter of each month before today (table past_changes, kept by triggers on detections: a removal, a
// restore, a species or location change, an import of past recordings; scripts/createdb.sh). 2026-10-23 run 3: a
// whole-history cache was rebuilt every day and after every past change (By Hour 4 s at ten times the station's data).
function past_month_changes() {
  static $m = null;
  if ($m !== null) return $m;
  $m = array();
  $r = get_db()->query('SELECT Month, N FROM past_changes');
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
// COUNT(*) per group of the detections between $from and $to ('' = no bound), rejected ones ("not this bird") left out.
// $keys = the SQL expressions grouped by; returns rows array(key 1, ..., key n, count). Two cheap queries instead of
// NOT IN on every row (0.5 µs per detection, the larger part of these charts): every detection counted from the
// covering indexes, the days before today kept per month (cached_past_months), then the rejected ones (a few hundred, found through
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
    // per calendar month, each kept until a detection of that month changes (2026-10-23 run 3)
    $parts = array_merge($parts, cached_past_months($gkey, $from, $to, past_month_changes(), function ($lo, $hi) use ($rows, $range, $rp) {
      return $rows($range . ' AND Date BETWEEN :lo AND :hi', $rp + array(':lo' => $lo, ':hi' => $hi));
    }));
  }
  // today's rows with today as the only lower bound: with the period's start beside it the planner walked every day of
  // the species index (2026-10-23 run 3: 0.65 s instead of 20 ms at ten times the station's data)
  if ($to === '' || $to >= $today) $parts[] = $rows('Date >= :today' . ($to !== '' ? ' AND Date <= :t' : ''), ($to !== '' ? array(':t' => $to) : array()) + array(':today' => $today));
  $out = array();
  foreach ($parts as $part) foreach ($part as $r) {
    $k = implode("\x1f", array_slice($r, 0, $n));
    if (isset($out[$k])) $out[$k][$n] += $r[$n]; else $out[$k] = $r;
  }
  // +Date: the few rejected detections are found through the reviews, not by walking every day of the period
  // (2026-10-23 run 3: 0.6 s for By Hour "All" at ten times the station's data)
  foreach ($rows(str_replace('Date ', '+Date ', $range) . " AND File_Name IN (SELECT File_Name FROM detection_reviews WHERE Verdict = 'no')", $rp) as $r) {
    $k = implode("\x1f", array_slice($r, 0, $n));
    if (isset($out[$k]) && ($out[$k][$n] -= $r[$n]) <= 0) unset($out[$k]);
  }
  ksort($out, SORT_STRING); // in the order of GROUP BY, as one query gave them
  return array_values($out);
}
// simple mode (owner 2026-10-29, US-125): without several locations (MULTI_LOCATION) or several stations (MULTI_STATION)
// their controls are hidden (asked at the installation, switched in Settings › Defaults); the data stays the same
function multi_location() { return (string)(get_config()['MULTI_LOCATION'] ?? '0') === '1'; }
// the Kiosk (System › Kiosk, view=Kiosk) only with ACTIVATE_KIOSK=true (owner 2026-11-01, false by default)
function kiosk_active() {
  return trim((string)(get_config()['ACTIVATE_KIOSK'] ?? 'false'), "\"' ") === 'true';
}

function multi_station() { return (string)(get_config()['MULTI_STATION'] ?? '0') === '1'; }
// the names the location data is written with (English: RESOLVE and IBGE biomes, ESA WorldCover classes of
// scripts/land_cover.py, its urban / peri-urban / natural setting) and their text keys; t_data() shows one translated,
// a name not listed as it is
function data_terms() {
  return array(
    'Tropical & Subtropical Moist Broadleaf Forests' => 'str_biome_tropical_moist_broadleaf',
    'Tropical & Subtropical Dry Broadleaf Forests' => 'str_biome_tropical_dry_broadleaf',
    'Tropical & Subtropical Coniferous Forests' => 'str_biome_tropical_coniferous',
    'Temperate Broadleaf & Mixed Forests' => 'str_biome_temperate_broadleaf_mixed',
    'Temperate Conifer Forests' => 'str_temperate_conifer_forests', 'Boreal Forests/Taiga' => 'str_boreal_forests_taiga',
    'Tropical & Subtropical Grasslands, Savannas & Shrublands' => 'str_biome_tropical_grasslands',
    'Temperate Grasslands, Savannas & Shrublands' => 'str_biome_temperate_grasslands',
    'Flooded Grasslands & Savannas' => 'str_flooded_grasslands_and_savannas',
    'Montane Grasslands & Shrublands' => 'str_montane_grasslands_and_shrublands', 'Tundra' => 'str_tundra',
    'Mediterranean Forests, Woodlands & Scrub' => 'str_biome_mediterranean_forests',
    'Deserts & Xeric Shrublands' => 'str_deserts_and_xeric_shrublands', 'Mangroves' => 'str_mangroves',
    'Amazon' => 'str_amazon', 'Caatinga' => 'str_caatinga', 'Cerrado' => 'str_cerrado', 'Atlantic Forest' => 'str_atlantic_forest',
    'Pampa' => 'str_pampa', 'Pantanal' => 'str_pantanal',
    'Tree cover' => 'str_tree_cover', 'Shrubland' => 'str_shrubland', 'Grassland' => 'str_grassland', 'Cropland' => 'str_cropland',
    'Built-up' => 'str_built_up', 'Bare / sparse vegetation' => 'str_bare_sparse_vegetation', 'Snow and ice' => 'str_snow_and_ice',
    'Permanent water bodies' => 'str_permanent_water_bodies', 'Herbaceous wetland' => 'str_herbaceous_wetland',
    'Moss and lichen' => 'str_moss_and_lichen',
    'Urban' => 'str_urban', 'Peri-urban' => 'str_peri_urban', 'Natural' => 'str_natural',
  );
}
function t_data($name) {
  $k = data_terms()[(string)$name] ?? '';
  return $k !== '' ? t($k) : (string)$name;
}
// the day of the week (Mon..Sun) of a date (a 'Y-m-d' text or a timestamp) and the month of a timestamp, as keys
function weekday_key($date) {
  return array('str_sun', 'str_mon', 'str_tue', 'str_wed', 'str_thu', 'str_fri', 'str_sat')[intval(date('w', is_int($date) ? $date : strtotime($date)))];
}
function month_key($ts) {
  return array('str_january', 'str_february', 'str_march', 'str_april', 'str_may', 'str_june', 'str_july', 'str_august',
    'str_september', 'str_october', 'str_november', 'str_december')[intval(date('n', $ts)) - 1];
}
// the biome of a location (US-27, owner 2026-10-29): "Biome: <the RESOLVE biome, translated> · <ecoregion>" and in Brazil
// "IBGE: <the IBGE biome>"; '' while it is not found yet (scripts/utils/locations.py biomes runs after a save)
function location_biome_text($l) {
  if (empty($l['biome']) && empty($l['biome_br'])) return isset($l['biome']) ? t('str_biome_none_sea_or_ice') : '';
  $h = function ($x) { return htmlspecialchars((string)$x, ENT_QUOTES); };
  $o = $l['biome'] ? t('str_biome', array('biome' => $h(t_data($l['biome'])))) . ($l['ecoregion'] ? ' · ' . $h($l['ecoregion']) : '') : '';
  if (!empty($l['biome_br'])) $o .= ($o !== '' ? ' · ' : '') . t('str_ibge', array('biome' => $h(t_data($l['biome_br']))));
  // the land cover within 100 m (ESA WorldCover, online when the location was saved)
  if (!empty($l['land_cover'])) $o .= '<br>' . t('str_land_cover_within_100_m_pct_help', array('cover' => $h(t_data($l['land_cover'])), 'pct' => intval($l['built_up_pct'] ?? 0)));
  // urban / peri-urban / natural from the built-up share within 500 m (owner 2026-10-29)
  if (!empty($l['setting'])) $o .= '<br>' . t('str_setting_pct_built_up_within_500_help', array('setting' => '<b>' . $h(t_data($l['setting'])) . '</b>', 'pct' => intval($l['built_up_500_pct'] ?? 0)));
  return $o;
}
// the scheme of the station's own links (owner 2026-10-29, US-126): http unless HTTPS_ALWAYS = 1 (Settings › Defaults); the
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
// every species in the database with its common name, of every location (the search picks species with it; the list it
// feeds is filtered by location): a walk over the index that starts with Sci_Name, one seek per species, instead of
// GROUP BY over every detection (2026-10-23 run 3: 7 s at ten times the station's data, the walk some 30 ms); the name is
// presentation (common_name())
function species_name_pairs() {
  $sql = "WITH RECURSIVE c(n) AS (SELECT MIN(Sci_Name) FROM main.detections UNION ALL
         SELECT (SELECT MIN(Sci_Name) FROM main.detections WHERE Sci_Name > c.n) FROM c WHERE c.n IS NOT NULL)
       SELECT n FROM c WHERE n IS NOT NULL";
  $out = array();
  $r = get_db()->query($sql);
  while ($r && ($x = $r->fetchArray(SQLITE3_NUM))) $out[] = array($x[0], common_name($x[0]));
  return $out;
}
// the common name of each species (presentation, common_name())
function species_com_names($scis) {
  $out = array();
  foreach ($scis as $s) $out[$s] = common_name($s);
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
    // the pages show the station's own copy (scripts/species_image.php: downloaded once, then served locally, so the
    // pictures also show offline, US-22 / US-29); the Wikimedia address stays in source_url
    $image['source_url'] = $image['image_url'];
    $image['image_url'] = '/scripts/species_image.php?sci=' . rawurlencode($sci_name) . '&v=' . rawurlencode((string)$image['date_created']);
    return $image;
  }

  // the Wikimedia address of the species' picture as kept (no lookup), or ''
  public function source_url($sci_name) {
    $row = $this->get_image_from_db($sci_name);
    return $row ? (string)$row['image_url'] : '';
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
// detections ("not this bird") out of a query on detections
function review_verdicts() {
  static $verdicts = null;
  if ($verdicts === null) {
    $verdicts = array();
    $db = get_db();
    // [2]: the detection came from Change species (Orig_Sci kept): clear leaves it Changed (play.php, Gitea #69)
    $res = $db->query("SELECT File_Name, Verdict, COALESCE(Reason, ''), COALESCE(Orig_Sci, '') <> '' FROM detection_reviews");
    while ($res && ($r = $res->fetchArray(SQLITE3_NUM))) $verdicts[$r[0]] = array($r[1], $r[2], (bool)$r[3]);
  }
  return $verdicts;
}
// The one Review button of the interface: always reviewDetection(this) (static/review-player.js), no local variants
function validate_button($file, $verdict = null, $positioned = false) {
  $reason = review_reason(basename($file));
  if ($verdict === null) $verdict = review_verdict(basename($file));
  // "not this bird" without a cause counts as Review later (Validation): shown so
  if ($verdict === 'no' && $reason === '') $verdict = 'unsure';
  // the button names the answer chosen (owner 2026-10-22): the cause in red, Yes / Changed in green, Unknown bird in grey
  $causes = array('human' => t('str_human'), 'noise' => t('str_noise'), 'ignore' => t('str_no_ignore'));
  $labels = array('' => t('str_review'), 'yes' => '&#10003; ' . ($reason === 'changed' ? t('str_changed') : t('str_yes_state')),
                  'no' => isset($causes[$reason]) ? '&#10007; ' . htmlspecialchars($causes[$reason]) : '&#8644; ' . htmlspecialchars(t('str_change_species')), 'unsure' => '? ' . t('str_review_later'));
  $titles = array('' => t('str_is_this_the_bird_review_this_help'), 'yes' => t('str_reviewed_yes_this_bird_species_help'),
                  'no' => t('str_reviewed_not_this_species_a_help'), 'unsure' => t('str_reviewed_review_later'));
  return '<button type="button" class="validatebtn v-' . ($verdict ?: 'none') . ($positioned ? ' positioned' : '') . '" title="' . htmlspecialchars($titles[$verdict], ENT_QUOTES)
    . '" data-file="' . htmlspecialchars($file, ENT_QUOTES) . '" onclick="reviewDetection(this)">' . $labels[$verdict] . '</button>';
}
// Removed detections ("Remove detection", owner 2026-10-09): files in the Removed folder of the clips, Removed/<date>/<species>/,
// lines in deleted_detections, until they are deleted by hand in Species › Purge Files
// The review causes whose audio Purge Files may remove while the detection keeps counting (owner 2026-10-22)
const PURGE_CAUSES = array('human', 'noise', 'ignore');

// The clips protected from every purge (config/scripts/disk_check_exclude.txt: <date>/<species>/<file> lines, the
// automatic top N and confirmed ones plus the manual locks) as a set
function locked_clips() {
  static $set = null;
  if ($set === null) $set = array_flip(array_map('trim', @file(BS_DISK_EXCLUDE, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: array()));
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

// Add or remove species lines (Sci_Com) in a species list file: one line per scientific name, sorted (the lists and the
// species cards' toggles, US-10)
function update_species_list($filename, $species, $add) {
  $key = function ($line) { return explode('_', trim($line), 2)[0]; };
  $lines = array();
  // one scientific name per line (owner 2026-11-05: one index only); an old "Sci_Common" line is read by its first part
  // and written back as the scientific name alone
  foreach (@file($filename, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: array() as $l) {
    if (trim($l) !== '' && trim($l)[0] !== '#') $lines[$key($l)] = $key($l);
  }
  foreach ($species as $s) {
    $s = $key(htmlspecialchars_decode($s, ENT_QUOTES));
    if ($s === '') continue;
    if ($add) $lines[$s] = $s;
    else unset($lines[$s]);
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

// The excluded species (exclude_species_list.txt) as a set of scientific names
function excluded_species() {
  $lines = @file(BS_LISTS . '/exclude_species_list.txt', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: array();
  return array_flip(array_map(function ($l) { return explode('_', trim($l), 2)[0]; }, $lines));
}

// Detections of a query whose clip is still on disk under Extracted/<base> (Purge Files lists only what can still be
// purged: By_Date for Others, Purge for the causes staged by purge_stage.py), as
// [key => [com, sci, cause, n]]; the rows carry Date, File_Name, Sci_Name and optionally Reason
function purge_rows($res, $keep = null, $base = 'By_Date') {
  $home = get_home(); $out = array();
  while ($res && ($r = $res->fetchArray(SQLITE3_ASSOC))) {
    if ($keep && !$keep($r)) continue;
    $f = BS_EXTRACTED . '/' . $base . '/' . $r['Date'] . '/' . species_folder($r['Sci_Name']) . '/' . $r['File_Name'];
    if (!file_exists($f)) continue;
    $k = ($r['Reason'] ?? '') . '|' . $r['Sci_Name'];
    if (!isset($out[$k])) $out[$k] = array('com' => common_name($r['Sci_Name']), 'sci' => $r['Sci_Name'], 'cause' => $r['Reason'] ?? '', 'n' => 0);
    $out[$k]['n']++;
  }
  uasort($out, function ($a, $b) { return $b['n'] - $a['n']; });
  return $out;
}

// The audio waiting to be purged of the detections a location keeps counting (owner 2026-10-24, the Ignored column of
// Tools › Data): answered Human, Noise or Ignore (moved to Extracted/Purge at night) and the excluded
// species (in By_Date; not the clips answered Yes nor the protected ones). $lw = an SQL condition on detections or ''.
function purge_audio_rows($db, $lw = '') {
  $and = $lw !== '' ? ' AND ' . $lw : '';
  $out = array('causes' => array(), 'others' => array());
  $out['causes'] = purge_rows($db->query("SELECT d.Date, d.File_Name, d.Sci_Name, r.Reason FROM main.detections d JOIN detection_reviews r ON r.File_Name = d.File_Name
    WHERE r.Verdict = 'no' AND r.Reason IN ('" . implode("','", PURGE_CAUSES) . "')" . $and), null, 'Purge');
  $excl = excluded_species();
  $excl_found = $excl ? excluded_with_detections($db, $excl) : '';
  if ($excl_found) {
    $locked = locked_clips();
    $out['others'] = purge_rows($db->query("SELECT Date, File_Name, Sci_Name FROM main.detections WHERE Sci_Name IN ("
      . $excl_found . ") AND File_Name NOT IN (SELECT File_Name FROM detection_reviews WHERE Verdict = 'yes')" . $and),
      function ($r) use ($excl, $locked) { return isset($excl[$r['Sci_Name']]) && !isset($locked[$r['Date'] . '/' . species_folder($r['Sci_Name']) . '/' . $r['File_Name']]); });
  }
  return $out;
}

function deleted_dir() {
  return BS_REMOVED;
}
// how many deleted detections wait for a wipe: one species, or every species when $sci is null
function deleted_count($sci = null) {
  $db = get_db();
  if ($sci === null) return intval($db->querySingle('SELECT COUNT(*) FROM deleted_detections'));
  $st = $db->prepare('SELECT COUNT(*) FROM deleted_detections WHERE Sci_Name = :s');
  $st->bindValue(':s', $sci);
  $r = $st->execute()->fetchArray(SQLITE3_NUM);
  return intval($r[0]);
}

// Attributes of one item of a review list (review player, static/review-player.js)
// $sci: the scientific name; the player shows "Scientific - English (eBird / Clements)" under the title
// $row: the detection (Confidence, Cutoff, Sens, Overlap, Date): the player lists the analysis settings at the bottom
function review_item_attrs($file, $label, $sci = '', $row = null) {
  static $locked = null;
  $home = get_home();
  if ($locked === null) {
    $list = BS_DISK_EXCLUDE;
    $locked = is_file($list) ? array_flip(file($list, FILE_IGNORE_NEW_LINES)) : array();
  }
  // the clip's folder for the station File Manager (SFTP): its full path, By_Date/<date>/<species>
  $dir = BS_BY_DATE . '/' . dirname($file);
  // data-spec: the stored picture, PNG while the day runs, JPEG once archived (owner 2026-10-22: Calibrate's older
  // samples showed no spectrogram, the player asked for <clip>.png only)
  // data-gone: the clip is no longer on disk (the nightly clean-up keeps N files per species, the line stays); the review
  // player skips it (owner 2026-10-22)
  $gone = !is_file(BS_BY_DATE . '/' . $file);
  // a clip the nightly staging moved to Extracted/Purge (answered with a cause) still plays from there, so its answer
  // can be changed; play.php moves it back at once (2026-10-23 test run)
  $base = '/By_Date/';
  if ($gone && is_file(BS_EXTRACTED . '/Purge/' . $file)) { $gone = false; $base = '/Purge/'; }
  // data-reason: the cause of the answer, so the player's Undo puts it back (owner 2026-10-22)
  $reason = review_reason(basename($file));
  // data-changed: it came from Change species, so clear shows it as Changed again, as the station keeps it (Gitea #69)
  $changed = review_verdicts()[basename($file)][2] ?? false;
  return ' data-ri="1"' . ($gone ? ' data-gone="1"' : '') . ($reason !== '' ? ' data-reason="' . htmlspecialchars($reason, ENT_QUOTES) . '"' : '') . ($changed ? ' data-changed="1"' : '') . ' data-file="' . htmlspecialchars($file, ENT_QUOTES) . '" data-clip="' . htmlspecialchars($base . $file, ENT_QUOTES)
    . '" data-spec="' . htmlspecialchars(clip_spectrogram($base . $file), ENT_QUOTES)
    . '" data-label="' . htmlspecialchars($label, ENT_QUOTES) . '" data-dir="' . htmlspecialchars($dir, ENT_QUOTES) . '"'
    . ($sci !== '' ? ' data-sci="' . htmlspecialchars($sci, ENT_QUOTES) . '" data-en="' . htmlspecialchars(get_english_name($sci), ENT_QUOTES) . '"' : '')
    . ($row !== null && isset($row['Date']) ? ' data-when="' . htmlspecialchars($row['Date'] . (isset($row['Time']) ? ' ' . $row['Time'] : ''), ENT_QUOTES) . '"' : '')
    . ($sci !== '' ? ' data-com="' . htmlspecialchars(common_name($sci), ENT_QUOTES) . '"' : '')
    . ($row !== null ? ' data-params="' . htmlspecialchars(json_encode(review_params($sci, $row)), ENT_QUOTES) . '"' : '')
    . ' data-locked="' . (isset($locked[$file]) ? 1 : 0) . '"';
}

// The standard detection list (owner 2026-10-09): Now (list view) and the species page. $rows: detections with Date,
// Time, Sci_Name, Confidence, Cutoff, Sens, Overlap, File_Name. A click on a row (or its Review button)
// opens the review player there; it goes on down the list. $with_species = false on a species' own page.
// $with_date = false when every row is of the same day (All Detections › Today)
// $simple (Now › Simple, owner 2026-10-20): time, species, confidence, probability and review only
// The species name of a detection in a review list (owner 2026-10-26): the name opens the review player, the species
// card icon beside it the species page; the <a data-com data-sci data-en> keeps the name switch and the sort working
// ($file: the clip, for a row that is not a review item of its own)
// the species card icon (the species page) alone: a table gives it a narrow column of its own, headed by the icon
function species_card_icon($sci = null) {
  $svg = '<svg viewBox="0 0 24 24" width="15" height="15" aria-hidden="true" style="vertical-align:-2px"><rect x="3" y="2" width="18" height="20" rx="2.5" fill="none" stroke="currentColor" stroke-width="2"/><path d="M7 7h10M7 11h10M7 15h6" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>';
  if ($sci === null) return '<span role="img" title="' . t('str_species_card') . '" aria-label="' . t('str_species_card') . '">' . $svg . '</span>';
  return '<a class="spcard" href="views.php?view=Species&amp;sci=' . rawurlencode($sci) . '" title="' . t('str_open_the_species_page') . '" aria-label="' . t('str_species_card') . '" onclick="event.stopPropagation()">' . $svg . '</a>';
}

function species_review_name($sci, $com, $bold = false, $file = null, $card = true) {
  $h = function ($s) { return htmlspecialchars((string)$s, ENT_QUOTES); };
  $call = $file === null ? 'reviewDetection(this)' : 'reviewDetection(' . $h(json_encode($file)) . ', this)';
  // the card first, then the name (owner 2026-10-26)
  return ($card ? species_card_icon($sci) . ' ' : '')
    . '<a href="#" onclick="event.preventDefault(); event.stopPropagation(); ' . $call . '" title="' . t('str_listen_and_review') . '" data-com="' . $h($com)
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
    . ($with_species ? '<th class="spc" data-nosort style="width:1%;text-align:center">' . species_card_icon() . '</th>' : '') . ($with_date ? '<th>' . t('str_date') . '</th>' : '') . '<th>' . t('str_time') . '</th>'
    . ($with_species ? '<th><select class="namemode" title="' . t('str_names_shown') . '"><option value="com">' . t('str_common_name') . '</option><option value="sci">' . t('str_scientific_name') . '</option><option value="en">' . t('str_english_name') . '</option></select></th>' : '')
    // short labels, the explanation on mouse over (owner 2026-10-21)
    . '<th class="num" title="' . t('str_confidence_the_score_the_model_help') . '">' . t('str_conf') . '</th>'
    . ($simple ? '' : '<th class="num" title="' . t('str_minimum_confidence_in_force_help') . '">' . t('str_min_conf') . '</th>'
    . '<th class="num" title="' . t('str_manual_confidence_of_the_help') . '">' . t('str_manual_conf') . '</th>')
    . '<th class="num" title="' . t('str_probability_of_how_likely_help') . '">' . t('str_prob') . '</th>'
    . ($simple ? '' : '<th class="num" title="' . t('str_minimum_probability_at_the_location_in_help') . '">' . t('str_min_prob') . '</th>')
    . ($simple ? '' : '<th class="num" title="' . t('str_sigmoid_sensitivity_of_the_analysis') . '">' . t('str_sigm_sens') . '</th>'
    . '<th class="num" title="' . t('str_overlap_of_the_analysis_s') . '">' . t('str_overlap') . '</th>'
    . '<th class="num" title="' . t('str_recording_length_in_force_when_help') . '">' . t('str_rec_length') . '</th>')
    . ($with_loc ? '<th>' . t('str_location') . '</th>' : '')
    . '<th class="rv" data-nosort>' . t('str_review') . '</th></tr>';
  foreach ($rows as $g) {
    $folder = species_folder($g['Sci_Name']);
    $file = $g['Date'] . '/' . $folder . '/' . $g['File_Name'];
    $t = strtotime($g['Date']);
    $w = min(48, (intval(date('n', $t)) - 1) * 4 + min(4, intdiv(intval(date('j', $t)) - 1, 7) + 1));
    $prob_v = isset($profile[$g['Sci_Name']]) ? floatval($profile[$g['Sci_Name']][$w - 1]) : null;
    $prob = $prob_v !== null ? fmt_num($prob_v * 100, 1) . '%' : '-';
    $label = common_name($g['Sci_Name']) . ', ' . $g['Date'] . ' ' . $g['Time'] . ', ' . round($g['Confidence'] * 100) . '%';
    $card = $with_species ? '<td class="spc" style="text-align:center">' . species_card_icon($g['Sci_Name']) . '</td>' : '';
    $species = $with_species ? '<td>' . species_review_name($g['Sci_Name'], common_name($g['Sci_Name']), false, null, false) . '</td>' : '';
    $out .= '<tr class="rvrow"' . review_item_attrs($file, $label, $g['Sci_Name'], $g) . ' onclick="if (!event.target.closest(\'a,button\')) reviewDetection(this)" title="' . t('str_listen_and_review') . '">' . $card
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
  $dir = BS_MODEL . '/';
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
      if (($h = @fopen(BS_MODEL . '/' . $f, 'r')) === false) continue;
      fgetcsv($h, 0, ';', '"', '');
      while (($r = fgetcsv($h, 0, ';', '"', '')) !== false) if (isset($r[2])) $info[$r[0]] = array($r[1], $r[2]);
      fclose($h);
    }
  }
  if (isset($info[$sci])) return $info[$sci];
  if ($cls === null) {
    $cls = array(); $genus = array();
    foreach (glob(BS_MODEL . '/BirdNET-Plus_*_Labels.csv') ?: array() as $f) {
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
    if (($h = @fopen(BS_MODEL . '/taxonomy_order.csv', 'r')) !== false) {
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

// a model's name in short: BirdNET+ V3.0 or BirdNET V2.4 (the file name otherwise)
function model_short_name($m) {
  if (stripos($m, 'V3') !== false) return 'BirdNET+ V3.0';
  if (stripos($m, 'V2.4') !== false) return 'BirdNET V2.4';
  return $m;
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
    // the microphone it was recorded with, its USB port and its gain (owner 2026-11-04; '-' before that day)
    'Microphone' => (string)($row['Mic'] ?? '') !== '' ? (string)$row['Mic'] : '-',
    'Port' => (string)($row['Mic_Port'] ?? '') !== '' ? 'USB ' . $row['Mic_Port'] : '-',
    'Gain' => ($row['Mic_Gain'] ?? null) === null || $row['Mic_Gain'] === '' ? '-' : intval($row['Mic_Gain']) . ' %',
    // the scene (owner 2026-11-04): the model, the audio the recorder lost in that recording, the time zone
    'Model' => (string)($row['Model'] ?? '') !== '' ? model_short_name((string)$row['Model']) : '-',
    'Audio loss' => ($row['Audio_Loss_N'] ?? null) === null || $row['Audio_Loss_N'] === '' ? '-'
      : (intval($row['Audio_Loss_N']) === 0 ? t('str_none_lc') : intval($row['Audio_Loss_N']) . ' (' . fmt_num(floatval($row['Audio_Loss_Ms'] ?? 0), 1) . ' ms)'),
    'Time zone' => (string)($row['Tz'] ?? '') !== '' ? $row['Tz'] . ' (UTC' . ($row['Utc_Offset'] ?? '') . ')' : '-',
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
  return " AND File_Name NOT IN (SELECT File_Name FROM detection_reviews WHERE Verdict = 'no')";
}

// Action icons of one detection (delete, change species, protect from purge, frequency shift), the same
// ones the Recordings page shows, so a card does not need the "open in new tab" detour (owner 2026-10-08).
// $file = "<date>/<common name>/<file>"; $positioned = the absolute top-right layout of the cards.
function detection_actions($file, $positioned = true, $style = '', $width = 25, $with_review = true) {
  static $locked = null;
  $home = get_home();
  if ($locked === null) {
    $list = BS_DISK_EXCLUDE;
    $locked = is_file($list) ? array_flip(file($list, FILE_IGNORE_NEW_LINES)) : array();
  }
  $verdict = review_verdict(basename($file));
  $f = htmlspecialchars(json_encode($file), ENT_QUOTES);
  $lock = isset($locked[$file])
    ? array('del', 'images/lock.svg', t('str_this_file_is_excluded_from_help'))
    : array('add', 'images/unlock.svg', t('str_this_file_95_pct_usage_help'));
  $icons = array(
    array("deleteDetection($f)", 'images/delete.svg', t('str_delete_detection'), '190px'),
    array("toggleLock($f, &quot;$lock[0]&quot;, this)", $lock[1], $lock[2], '115px'),
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
      $en = '<br><span class="spen" title="' . t('str_english_name_ebird_clements') . '">' . htmlspecialchars($en_name) . '</span>';
    }
  }
  // the species-page icon before the names left the app (owner 2026-10-21); $icon is kept for the callers
  return '<div class="sptitle"><div class="spnames">' . $name_html . '<br><i>'
    . htmlspecialchars($sciname) . '</i>' . $en . '</div><div class="splinks">' . species_links($sciname, '', 0) . $extra . '</div></div>';
}

// English name of a species, the model's own (owner 2026-11-05, the BirdNET way): the V3 model labels ("Sci_English",
// always English, whatever the station language), then BirdNET's English l18n file for the V2.4 / 6K species (their
// labels hold only the scientific name); '' when unknown. With English as the common-name language the common name (l18n) and this one may
// differ, and that is fine
function get_english_name($sciname) {
  static $map = null;
  if ($map === null) {
    $map = array();
    $model = BS_MODEL . '/';
    foreach (glob($model . 'BirdNET-Plus_*_Labels.txt') ?: array() as $f) {
      if (strpos($f, '_Geo_') !== false) continue;
      foreach (file($f, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $l) {
        $p = explode('_', $l, 2);
        if (count($p) === 2 && !isset($map[$p[0]])) $map[$p[0]] = $p[1];
      }
    }
    // the V2.4 / 6K labels hold only the scientific name: BirdNET's English for them is its l18n English file
    $en = json_decode((string)@file_get_contents($model . 'l18n/labels_en.json'), true);
    if (is_array($en)) $map += $en;
  }
  return $map[$sciname] ?? '';
}

// Photo of a species from Wikipedia (cached in its own database); false when none
function species_photo($sciname) {
  static $provider = null;
  if ($provider === null) $provider = new Wikipedia();
  try { return $provider->get_image($sciname); } catch (Throwable $e) { return false; }
}

// ISO country code of the current location (owner 2026-11-01, the xeno-canto filter): from its species list when that is
// a region (BR-SP), else from state/geocode.json, the reverse-geocoding cache shared with ebird_report.py (key
// "lat,lon" at 2 decimals -> [state, country]), filled once per place from OpenStreetMap (3 s at most); '' when unknown
function location_country() {
  static $cc = null;
  if ($cc !== null) return $cc;
  $cc = '';
  $l = location_current();
  if (!$l) return $cc;
  if (preg_match('/^([A-Z]{2})-[A-Z0-9]{1,3}$/', (string)($l['species_list'] ?? ''), $m)) return $cc = $m[1];
  $lat = floatval($l['lat'] ?? 0); $lon = floatval($l['lon'] ?? 0);
  if (!$lat && !$lon) return $cc;
  $key = sprintf('%.2f,%.2f', $lat, $lon);
  $path = BS_STATE . '/geocode.json';
  $cache = json_decode((string)@file_get_contents($path), true) ?: array();
  if (!isset($cache[$key])) {
    $ctx = stream_context_create(array('http' => array('timeout' => 3, 'header' => "User-Agent: BirdSuite (station location)\r\n")));
    $j = json_decode((string)@file_get_contents(sprintf('https://nominatim.openstreetmap.org/reverse?format=jsonv2&zoom=5&lat=%.4f&lon=%.4f', $lat, $lon), false, $ctx), true);
    if (!is_array($j)) return $cc;
    $a = $j['address'] ?? array();
    $iso = (string)($a['ISO3166-2-lvl4'] ?? ($a['ISO3166-2-lvl3'] ?? ''));
    $cache[$key] = array(strpos($iso, '-') !== false ? explode('-', $iso, 2)[1] : '', strtoupper((string)($a['country_code'] ?? '')));
    @file_put_contents($path, json_encode($cache), LOCK_EX);
  }
  return $cc = (string)($cache[$key][1] ?? '');
}

// The best xeno-canto recording of a species (owner 2026-11-01: its spectrogram under the picture of the species page):
// the first of the sonograms view sorted by quality (A first), of the current location's country when known, else of
// anywhere. Kept 30 days in state/xc_best.json (by species and country; a miss is kept a day). Returns nr, by (recordist),
// lic (licence URL), img (grey-medium spectrogram), page, or null. Reads xeno-canto's public pages (API v2 is gone,
// v3 needs a key), 5 s at most.
// the same, from the kept list only (no network): the entry, null for a kept miss, false when nothing is kept yet; the
// species page draws a kept one at once, so the box never pushes the page down after it is drawn (release run 4, PERF-44)
function xc_best_kept($sci) {
  $cache = json_decode((string)@file_get_contents(BS_STATE . '/xc_best.json'), true) ?: array();
  $e = $cache[$sci . '|' . location_country()] ?? null;
  if (!is_array($e) || time() - intval($e['at'] ?? 0) >= (empty($e['nr']) ? 86400 : 30 * 86400)) return false;
  if (empty($e['nr'])) return null;
  return !empty($e['local']) ? $e : false;
}
function xc_best($sci) {
  $cnt = location_country();
  $key = $sci . '|' . $cnt;
  $path = BS_STATE . '/xc_best.json';
  $cache = json_decode((string)@file_get_contents($path), true) ?: array();
  // a found recording without its kept spectrogram (an entry from before the copy) is looked up again
  if (isset($cache[$key]) && (empty($cache[$key]['nr']) || !empty($cache[$key]['local']))
      && time() - intval($cache[$key]['at'] ?? 0) < (empty($cache[$key]['nr']) ? 86400 : 30 * 86400)) {
    return empty($cache[$key]['nr']) ? null : $cache[$key];
  }
  $ctx = stream_context_create(array('http' => array('timeout' => 5, 'header' => "User-Agent: BirdSuite (species page)\r\n")));
  $best = null;
  foreach ($cnt !== '' ? array($sci . ' cnt:' . $cnt, $sci) : array($sci) as $query) {
    $html = (string)@file_get_contents('https://xeno-canto.org/explore?query=' . rawurlencode($query) . '&view=3&order=qual&dir=0', false, $ctx);
    if (!preg_match("~<a rel='sono' title='XC(\d+): [^']* by ([^']*)' href='[^']*'>\s*<img class='spectro-image' src='(https://xeno-canto\.org/sounds/spectrograms/[^']+?)grey-small\.png'~", $html, $m)) continue;
    $lic = preg_match("~<a href='(https://creativecommons\.org/licenses/[^']+)'~", substr($html, max(0, strpos($html, 'xc_audio_' . $m[1]) ?: 0), 4000), $l) ? $l[1] : '';
    $best = array('nr' => $m[1], 'by' => html_entity_decode($m[2], ENT_QUOTES), 'lic' => $lic, 'img' => $m[3] . 'grey-medium.png',
      'page' => 'https://xeno-canto.org/' . $m[1], 'country' => strpos($query, ' cnt:') !== false ? $cnt : '');
    break;
  }
  // the spectrogram kept on the station (state/xc/<nr>.png) and served from it: a page never loads it from xeno-canto
  if ($best) {
    $dir = BS_STATE . '/xc';
    $file = $dir . '/' . $best['nr'] . '.png';
    if (!is_file($file)) {
      @mkdir($dir, 0775, true);
      $png = @file_get_contents($best['img'], false, $ctx);
      if ($png !== false && strncmp($png, "\x89PNG", 4) === 0 && @file_put_contents($file, $png, LOCK_EX)) @chmod($file, 0664);
    }
    if (is_file($file)) $best['local'] = 'scripts/species_page.php?xcimg=' . $best['nr'];
  }
  $cache[$key] = ($best ?: array()) + array('at' => time());
  if (@file_put_contents($path, json_encode($cache), LOCK_EX)) @chmod($path, 0664);  // the web server and the station user
  return $best;
}

function species_links($sciname, $style = '', $width = 20) {
  static $ebirds = null;
  if ($ebirds === null) {
    require __ROOT__ . '/scripts/ebird.php';
  }
  $config = get_config();
  $lang = $config['DATABASE_LANG'] ?? 'en';
  // the order of US-29: WikiAves (first, only with the CBRO names: pt_BR), eBird, Birds of the World, Wikipedia, xeno-canto
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
  // the species' recordings on xeno-canto, in the sonograms view (owner 2026-11-01); only those of the current
  // location's country when it is known (cnt:BR)
  $cnt = location_country();
  // best first: sorted by quality, A to E (order=qual, owner 2026-11-01)
  $links[] = array('https://xeno-canto.org/explore?query=' . rawurlencode($sciname . ($cnt !== '' ? ' cnt:' . $cnt : '')) . '&view=3&order=qual&dir=0',
    $cnt !== '' ? t('str_xeno_canto_recordings_from_as_help', array('country' => $cnt)) : t('str_xeno_canto_recordings_as_help'), 'images/xenocanto.png');
  $html = '';
  foreach ($links as $l) {
    $html .= '<a href="' . htmlspecialchars($l[0], ENT_QUOTES) . '" target="_blank"><img style="' . htmlspecialchars($style, ENT_QUOTES)
      . '" title="' . $l[1] . '" alt="' . $l[1] . '" src="' . $l[2] . '"' . ($width ? ' width="' . intval($width) . '"' : '') . '></a> ';
  }
  return $html;
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
// APP_THEME = ocean | dark | forest | blossom | white | red | the id of a custom theme (customN, kept for good).
// A sixth colour, text (owner 2026-10-24): black in the light themes, white in Dark.
function theme_presets() {
  return array(
    // in the owner's order (2026-10-24): Ocean, Dark, Forest, Blossom, White, Red. Ocean is the system default after a
    // clean install (owner 2026-10-24, last word); Sand removed (2026-10-21)
    'ocean' => array('str_ocean', '#7fb3d5', '#a9cce3', '#e3f0fa', '#1f4e79', '#2e86c1', '#000000'),
    // Dark in place of Graphite (owner 2026-10-24): the theme the dark switch puts on the current location; the colours of
    // static/dark-style.css
    'dark' => array('str_dark', '#1a1a1a', '#2b2b2b', '#3b3b3b', '#2b5e22', '#4f7f44', '#ffffff'),
    'forest' => array('str_forest', '#77c487', '#9fe29b', '#dbffeb', '#2b5e22', '#04aa6d', '#000000'),
    'blossom' => array('str_blossom', '#c9a3c7', '#e0c4de', '#f8eef7', '#5e2b5c', '#9c4f96', '#000000'),
    'white' => array('str_white_and_grey', '#ffffff', '#e3e5e8', '#f3f4f6', '#3c4043', '#6b7075', '#000000'),
    'red' => array('str_red', '#e3a5a0', '#f0c6c2', '#fdeeed', '#7b1e1e', '#c0392b', '#000000'),
  );
}
// Each location has its own theme (owner 2026-10-21): 'theme' in locations.json, set in Settings › Locations; empty = the
// station's theme (APP_THEME, Settings › Theme, Ocean by default). Unless another theme was chosen for them, Home is
// Ocean and the test location red.
function theme_valid($name) {
  return isset(theme_presets()[$name]) || custom_theme($name) !== null;
}
function location_theme($l) {
  $name = strtolower(trim((string)($l['theme'] ?? (array('home' => 'ocean', 'test' => 'red')[$l['builtin'] ?? ''] ?? ''))));
  return $name !== '' && theme_valid($name) ? $name : '';
}
// the theme choices of a location's select: the station's theme, the fixed themes, the custom ones
function location_theme_options($cur) {
  $h = function ($x) { return htmlspecialchars((string)$x, ENT_QUOTES); };
  $out = "<option value=''" . ($cur === '' ? ' selected' : '') . '>' . $h(t('str_station_theme')) . '</option>';
  foreach (theme_presets() as $k => $p) $out .= "<option value='$k'" . ($cur === $k ? ' selected' : '') . '>' . $h(t($p[0])) . '</option>';
  foreach (custom_themes_load() as $c) $out .= "<option value='" . $h($c['id']) . "'" . ($cur === $c['id'] ? ' selected' : '') . '>' . $h($c['name']) . '</option>';
  return $out;
}
// Custom themes with a name (owner 2026-10-20; no limit since 2026-10-21), in config/custom_themes.json (station data, outside git):
// [{"id": "custom3", "name": "...", "colors": [bg, menu, panel, accent, accent2, text]}, ...]. The id is given once and
// never reused nor shifted (US-21): APP_THEME and the locations' themes name it, so deleting a theme changes no other
// choice. A theme stored without an id gets custom<its place> (the first save stores it).
function custom_themes_file() { return BS_CONFIG . '/custom_themes.json'; }
function custom_themes_load() {
  static $cache = null;
  if ($cache !== null) return $cache;
  $list = json_decode((string)@file_get_contents(custom_themes_file()), true);
  $out = array(); $used = array();
  foreach (is_array($list) ? $list : array() as $i => $t) {
    $cols = isset($t['colors']) && is_array($t['colors']) ? array_map('strtolower', $t['colors']) : array();
    if (count($cols) !== 6 || count(preg_grep('/^#[0-9a-f]{6}$/', $cols)) !== 6) continue;
    $id = preg_match('/^custom[1-9][0-9]*$/', (string)($t['id'] ?? '')) ? $t['id'] : 'custom' . ($i + 1);
    if (isset($used[$id])) continue;
    $used[$id] = true;
    $out[] = array('id' => $id, 'name' => mb_substr(trim((string)($t['name'] ?? '')) ?: $id, 0, 30), 'colors' => $cols);
  }
  return $cache = $out;
}
// one custom theme by its id, or null
function custom_theme($id) {
  foreach (custom_themes_load() as $t) if ($t['id'] === $id) return $t;
  return null;
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
  if (($ct = custom_theme($name)) !== null) return array_merge(array($ct['name']), $ct['colors']);
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
