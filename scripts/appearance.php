<?php
/* Appearance (owner 2026-10-09): the app colours — fixed themes and custom themes with a name (owner
 * 2026-10-20, no limit since 2026-10-21). A click previews the theme on the whole app at once; Apply saves the choice in BirdSuite.conf (APP_THEME)
 * for every browser — where the "Apply to" picklist says (owner 2026-10-24): the current location (its own theme in
 * locations.json, the default choice) or the system default (APP_THEME, the theme of the locations without one). Custom themes live in ~/BirdNET-Pi/config/custom_themes.json (common.php custom_themes_load()).
 * Opened as views.php?view=Appearance; scripts/appearance.php?save=1&theme=... picks, POST ?customs=1 stores the custom
 * list. Colours: common.php theme_presets() / theme_style(). */
require_once __DIR__ . '/common.php';
// the colour scheme in BirdSuite.conf (the daily chart follows it)
function scheme_write($v) {
  $c = file_get_contents(BS_CONF);
  $c = preg_match('/^COLOR_SCHEME=/m', $c) ? preg_replace('/^COLOR_SCHEME=.*/m', "COLOR_SCHEME=$v", $c) : $c . "\nCOLOR_SCHEME=$v\n";
  if (file_put_contents(BS_CONF, $c) === false) return false;
  shell_exec('sudo -n /usr/local/sbin/birdsuite-svc restart chart_viewer.service > /dev/null 2>&1 &');
  return true;
}
// the current location's own theme ('' = the station's); false when there is no current location or the write fails
function location_theme_write($theme) {
  $loc = location_current();
  if (!$loc || !isset($loc['id'])) return false;
  $list = locations_load();
  $found = false;
  foreach ($list as &$l) if (intval($l['id']) === intval($loc['id'])) { $l['theme'] = $theme; $found = true; }
  unset($l);
  return $found && locations_save($list);
}
// COLOR_SCHEME follows the theme in use (the daily chart reads it): dark with Dark, light with every other
// $name: the theme just put on the current location (the location list read earlier in this request is not reloaded)
function scheme_sync($name = null) {
  $v = ($name ?? theme_name_in_use()) === 'dark' ? 'dark' : 'light';
  return strtolower(get_config(true)['COLOR_SCHEME'] ?? '') === $v || scheme_write($v);
}
// the Light / Dark switch (owner 2026-10-24: it only changes between Ocean and Dark on the current location, nothing
// more); the stylesheet follows the theme (common.php get_color_scheme)
if (isset($_GET['scheme'])) {
  ensure_authenticated(t('You must be authenticated to change the settings.'));
  $th = $_GET['scheme'] === 'dark' ? 'dark' : 'ocean';
  echo location_theme_write($th) && scheme_sync($th) ? 'OK' : t('Error writing {file}', array('file' => basename(locations_file())));
  die();
}
if (isset($_GET['save'])) {
  ensure_authenticated(t('You must be authenticated to change the settings.'));
  $theme = strtolower($_GET['theme'] ?? '');
  $is_custom = preg_match('/^custom([1-9][0-9]*)$/', $theme, $m) && isset(custom_themes_load()[$m[1] - 1]);
  if (!isset(theme_presets()[$theme]) && !$is_custom) { echo t('Error: unknown theme'); die(); }
  // Apply and a new custom theme set the current location's theme (owner 2026-10-24: Apply changed the default, which
  // the current location's own theme hides, so nothing changed); for=default ("Apply to" System default) sets APP_THEME
  $loc = location_current();
  if (($_GET['for'] ?? 'location') !== 'default' && $loc && isset($loc['id'])) {
    echo location_theme_write($theme) && scheme_sync($theme) ? 'OK' : t('Error writing {file}', array('file' => basename(locations_file())));
    die();
  }
  $f = BS_CONF;
  $c = file_get_contents($f);
  $set = function ($c, $key, $value, $comment) {
    return preg_match("/^$key=/m", $c) ? preg_replace("/^$key=.*/m", "$key=\"$value\"", $c) : $c . "\n## $comment\n$key=\"$value\"\n";
  };
  $c = $set($c, 'APP_THEME', $theme, 'APP_THEME: app colours, Settings > Theme (ocean, dark, forest, blossom, white, red or custom1, custom2...)');
  echo file_put_contents($f, $c) !== false && scheme_sync() ? 'OK' : t('Error writing the settings');
  die();
}
// the custom themes as a whole (name + six colours; a list of five gets black text)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_GET['customs'])) {
  ensure_authenticated(t('You must be authenticated to change the settings.'));
  $in = json_decode((string)file_get_contents('php://input'), true);
  $out = array();
  foreach (is_array($in) ? $in : array() as $t) {
    $cols = array_map('strtolower', array_map('trim', (array)($t['colors'] ?? array())));
    if (count($cols) === 5) $cols[] = '#000000';  // a theme of five colours gets black text
    if (count($cols) !== 6 || count(preg_grep('/^#[0-9a-f]{6}$/', $cols)) !== 6) { echo t('Error: six colours per theme'); die(); }
    $name = mb_substr(trim(preg_replace('/[\x00-\x1F\x7F<>"]/', '', (string)($t['name'] ?? ''))), 0, 30);
    $out[] = array('name' => $name !== '' ? $name : t('Custom {n}', array('n' => count($out) + 1)), 'colors' => $cols);
  }
  $ok = file_put_contents(custom_themes_file(), json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n") !== false;
  @chmod(custom_themes_file(), 0664);
  // the theme in use was a custom one that is gone: back to the default
  $cur = strtolower(trim(get_config()['APP_THEME'] ?? ''));
  if ($ok && preg_match('/^custom([1-9][0-9]*)$/', $cur, $m) && $m[1] > count($out)) {
    $f = BS_CONF;
    file_put_contents($f, preg_replace('/^APP_THEME=.*/m', 'APP_THEME="forest"', file_get_contents($f)));
  }
  echo $ok ? 'OK' : t('Error writing {file}', array('file' => basename(custom_themes_file())));
  die();
}
$c = get_config();
$current = strtolower(trim($c['APP_THEME'] ?? 'ocean')) ?: 'ocean'; // Ocean is the default (owner 2026-10-24)
if ($current === 'custom') $current = 'custom1'; // the single custom theme of before is the first one now
$customs = custom_themes_load();
$dark = theme_name_in_use() === 'dark';  // the switch shows Dark when the theme in use is Dark
// the colours named by where they show (owner 2026-10-24: "Panels" and "Buttons" did not say it); style.css:
// --bg page, --menu menu bar and table rows, --panel table cells / boxes / page buttons, --accent most buttons, links
// and marks, --accent2 the selection: the row under the mouse and the page you are on in the menu, a light shade of it
// for the menu item under the mouse (owner 2026-10-24), the slider knobs
// short names under each colour (owner 2026-10-24: one word, no line break), the full one on mouse over
$roles = array(array('Background', 'Background'), array('Menu', 'Menu and table rows'), array('Table', 'Table cells, boxes and page buttons'),
               array('Buttons', 'Buttons and links'), array('Selection', 'Selection: the row under the mouse, the page you are on (the menu item under the mouse in a light shade)'),
               array('Text', 'Text'));
// the card marked on load is the theme in use: the current location's own theme, else the system default (owner
// 2026-10-21); the line above the cards names the saved system default
$loc_now = location_current();
$in_use = ($loc_now && location_theme($loc_now) !== '') ? location_theme($loc_now) : $current;
$card = function ($key, $name, $col) use ($in_use) {
  return '<button type="button" class="thcard' . ($key === $in_use ? ' on' : '') . '" data-theme="' . $key . '" data-colors="' . implode(',', $col) . '" data-name="' . htmlspecialchars($name, ENT_QUOTES) . '">'
    . '<span class="mock" style="background:' . $col[0] . '"><span class="mm" style="background:' . $col[1] . '"></span>'
    . '<span class="mp" style="background:' . $col[2] . '"><i style="background:' . $col[3] . '"></i><i style="background:' . $col[4] . '"></i></span></span>'
    . '<b>' . str_replace(' (', '<br>(', htmlspecialchars($name)) . '</b></button>';
};
// the editor's first colours before the script sets those of the theme in use
$start = isset(theme_presets()[$in_use]) ? array_slice(theme_presets()[$in_use], 1)
  : (preg_match('/^custom([1-9][0-9]*)$/', $in_use, $sm) && isset($customs[$sm[1] - 1]) ? $customs[$sm[1] - 1]['colors'] : array_slice(theme_presets()['ocean'], 1));
$hs = function ($x) { return htmlspecialchars((string)$x, ENT_QUOTES); };
?>
<style>
.app { max-width: 1000px; margin: 0; padding: 0 12px; text-align: left; }
.app h2 { margin: 8px 0 4px; font-size: 1.3em; } .app p.note { font-size: 13px; color: #333; margin: 0 0 12px; }
.app h3 { margin: 16px 0 6px; font-size: 1.05em; }
.app .grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(150px, 1fr)); gap: 12px; }
.app .thcard { width: auto; display: flex; flex-direction: column; gap: 6px; align-items: stretch; padding: 8px; border-radius: 12px; border: 2px solid rgba(0,0,0,.08); background: rgba(255,255,255,.6); cursor: pointer; color: #000; }
.app .thcard:hover { border-color: #d97a00; } .app .thcard.on { border-color: var(--accent, #2b5e22); box-shadow: 0 0 0 2px var(--accent, #2b5e22); }
.app .thcard.new { justify-content: center; align-items: center; min-height: 110px; font-size: 14px; border-style: dashed; }
.app .mock { display: flex; height: 74px; border-radius: 8px; overflow: hidden; padding: 6px; gap: 6px; }
.app .mm { width: 26%; border-radius: 4px; } .app .mp { flex: 1; border-radius: 4px; display: flex; align-items: flex-end; gap: 4px; padding: 6px; }
.app .mp i { display: block; width: 28px; height: 12px; border-radius: 6px; }
.app .custom { margin-top: 16px; border: 1px solid rgba(0,0,0,.08); background: rgba(255,255,255,.6); border-radius: 12px; padding: 10px 12px; }
.app .custom label { display: inline-flex; flex-direction: column; font-size: 12px; margin: 6px 12px 0 0; }
.app .custom label.clr { width: 64px; align-items: center; text-align: center; font-size: 11px; line-height: 1.2; gap: 2px; }
.app .custom input[type=color] { width: 64px; height: 34px; padding: 0; border: 1px solid #888; border-radius: 6px; background: none; cursor: pointer; }
.app .custom input[type=text] { padding: 5px 8px; border-radius: 6px; border: 1px solid #888; width: 14em; }
.app .actions { margin-top: 14px; display: flex; flex-wrap: wrap; gap: 10px; align-items: center; }
.app .actions button, .app .custom button { width: auto; padding: 6px 18px; border-radius: 14px; font-weight: 600; cursor: pointer; border: 1px solid var(--accent, #2b5e22); background: #fff; color: var(--accent, #2b5e22); }
.app .actions .apply { background: var(--accent, #2b5e22); color: #fff; }
.app .custom .del { border-color: #c62828; color: #c62828; }
.app #thmsg { font-size: 13px; }
</style>
<div class="app">
  <?php // the system default of this installation (owner 2026-10-21): the theme of every location without its own one;
  // changed with "Apply to" › System default below
  $def_name = isset(theme_presets()[$current]) ? t(theme_presets()[$current][0]) : (preg_match('/^custom([1-9][0-9]*)$/', $current, $dm) && isset($customs[$dm[1] - 1]) ? $customs[$dm[1] - 1]['name'] : $current); ?>
  <p class="note"><?php echo t('System default:'); ?> <b><?php echo $hs($def_name); ?></b></p>
  <?php // no Light / Dark buttons (owner 2026-10-25): the scheme follows the theme applied (scheme_sync) ?>
  <div class="grid">
    <?php foreach (theme_presets() as $k => $p) echo $card($k, t($p[0]), array_slice($p, 1)); ?>
  </div>
  <h3><?php echo t('Custom themes'); ?></h3>
  <div class="grid" id="customgrid">
    <?php foreach ($customs as $i => $t) echo $card('custom' . ($i + 1), $t['name'], $t['colors']);
      echo '<button type="button" class="thcard new" id="newcard">+ ' . t('New custom theme') . '</button>'; ?>
  </div>
  <div class="custom">
    <b id="edtitle"><?php echo t('Edit'); ?></b>: <?php echo t('name and the six colours (shown at once):'); ?><br>
    <label><?php echo t('Name'); ?><input type="text" id="cname" maxlength="30" placeholder="<?php echo t('My theme'); ?>"></label>
    <?php // shown in this order (owner 2026-10-24: Text after Background, Buttons last); data-i is the colour's place in the stored list
    foreach (array(0, 5, 1, 2, 4, 3) as $i) { $r = $roles[$i]; echo '<label class="clr" title="' . $hs(t($r[1])) . '"><input type="color" data-i="' . $i . '" value="' . htmlspecialchars($start[$i]) . '"><span>' . $hs(t($r[0])) . '</span></label>'; } ?>
    <div style="margin-top:10px;display:flex;gap:8px;flex-wrap:wrap">
      <button type="button" id="btnnew" onclick="cuSave('new')"><?php echo t('Save as a new custom theme'); ?></button>
      <button type="button" id="btnupd" onclick="cuSave('update')"><?php echo t('Update this custom theme'); ?></button>
      <button type="button" id="btndel" class="del" onclick="cuSave('delete')"><?php echo t('Delete this custom theme'); ?></button>
    </div>
  </div>
  <?php // where Apply saves the theme (owner 2026-10-24): the current location first (the default), the system default below ?>
  <div class="actions"><label><?php echo t('Apply to'); ?> <select id="thtarget">
      <?php if ($loc_now) echo '<option value="location" selected>' . $hs(t('Current location ({name})', array('name' => $loc_now['name'] ?? ''))) . '</option>'; ?>
      <option value="default"><?php echo t('System default'); ?></option></select></label>
    <button type="button" class="apply" onclick="thApply()"><?php echo t('Apply'); ?></button>
    <button type="button" onclick="location.reload()"><?php echo t('Back to the saved theme'); ?></button><span id="thmsg"></span></div>
  <p class="note" style="margin-top:1.4em"><?php echo t('Change the theme of each location in <a href="views.php?view=Locations">Settings › Locations</a>.'); ?></p>
  <?php // from Settings › Advanced (owner 2026-10-25): the image the Latest page shows; each field saved when it is left (the rare
  // species days are the Rare filter of the Latest page) ?>
  <?php $cf_now = get_config(); ?>
  <h3><?php echo t('Latest page'); ?></h3>
  <div class="custom">
    <label style="display:flex;width:100%;margin-right:0"><?php echo t('Custom image (absolute path of an image file in the station user\'s home):'); ?>
      <input type="text" style="width:100%;box-sizing:border-box" data-setting="CUSTOM_IMAGE" value="<?php echo $hs($cf_now['CUSTOM_IMAGE'] ?? ''); ?>"></label>
    <label style="display:flex;width:100%;margin-right:0"><?php echo t('Custom image label:'); ?>
      <input type="text" maxlength="80" style="width:100%;max-width:28em;box-sizing:border-box" data-setting="CUSTOM_IMAGE_TITLE" value="<?php echo $hs($cf_now['CUSTOM_IMAGE_TITLE'] ?? ''); ?>"></label>
    <p class="note" style="margin:6px 0 0"><?php echo t('The image shows on the Latest page, e.g. a picture of the garden that a camera refreshes.'); ?></p>
  </div>
</div>
<script>
// the theme selected = the one marked on load, the theme in use here (owner 2026-10-24: it was the system default, so
// Apply right after a reload put Forest back on the location)
var thTheme = <?php echo json_encode($in_use); ?>;
// after a save the whole app reloads, header and menu included, back on this page (owner 2026-10-21)
// a fresh load of the whole app (owner 2026-10-24: a menu item sometimes kept the old colours): a new address each time,
// so the browser takes nothing from its cache
function thReload() {
  var url = 'index.php?view=Appearance&t=' + Date.now();
  if (window.top !== window) window.top.location.replace(url); else location.replace(url);
}
var thCustoms = <?php echo json_encode($customs); ?>;
var thVars = ['--bg', '--menu', '--panel', '--accent', '--accent2', '--text'];
var edIndex = -1; // the custom theme being edited (-1 = a new one)
// the theme on this page and on the frame around it (header and menu), until Apply or reload
// Dark has its own stylesheet (static/dark-style.css, common.php get_color_scheme): the preview swaps it too, else the
// text kept the light sheet's colours (owner 2026-10-26)
function thShow(colors, theme) {
  var dark = (theme || thTheme) === 'dark';
  [document, window.parent && window.parent.document].forEach(function (doc) {
    if (!doc) return;
    doc.querySelectorAll('link[rel=stylesheet]').forEach(function (l) {
      var h = l.getAttribute('href') || '';
      if (/^(static\/dark-style|style)\.css/.test(h)) {
        var want = dark ? 'static/dark-style.css' : 'style.css';
        if (h.split('?')[0] !== want) l.setAttribute('href', want + '?v=' + Date.now());
      }
    });
    var st = doc.documentElement.style;
    colors.forEach(function (c, i) { st.setProperty(thVars[i], c); });
    st.setProperty('--accent-rgb', [1, 3, 5].map(function (j) { return parseInt(colors[3].substr(j, 2), 16); }).join(','));
    st.setProperty('--on-accent2', contrastText(colors[4]));
  });
}
// black or white on a colour, as common.php contrast_text() (the WCAG contrast rule)
function contrastText(hex) {
  var l = [0.2126, 0.7152, 0.0722].reduce(function (s, w, i) {
    var c = parseInt(hex.substr(1 + 2 * i, 2), 16) / 255;
    return s + w * (c <= 0.03928 ? c / 12.92 : Math.pow((c + 0.055) / 1.055, 2.4));
  }, 0);
  return 1.05 / (l + 0.05) >= (l + 0.05) / 0.05 ? '#fff' : '#000';
}
// the six colours in their stored order (bg, menu, panel, accent, accent2, text), whatever the order on the page
function colorsNow() { var c = []; document.querySelectorAll('.custom input[type=color]').forEach(function (x) { c[+x.dataset.i] = x.value; }); return c; }
function setEditor(name, colors, index) {
  edIndex = index;
  document.getElementById('cname').value = name;
  document.querySelectorAll('.custom input[type=color]').forEach(function (x) { x.value = colors[+x.dataset.i]; });
  document.getElementById('edtitle').textContent = index >= 0 ? t('Edit "{name}"', {name: name}) : t('New custom theme');
  document.getElementById('btnupd').style.display = index >= 0 ? '' : 'none';
  document.getElementById('btndel').style.display = index >= 0 ? '' : 'none';
}
function thPick(card) {
  document.querySelectorAll('.thcard').forEach(function (c) { c.classList.toggle('on', c === card); });
  thTheme = card.dataset.theme;
  var cols = card.dataset.colors.split(',');
  thShow(cols, thTheme);
  var m = /^custom(\d+)$/.exec(thTheme);
  // a fixed theme is a starting point for a new custom one; a custom one opens for editing
  if (m) setEditor(card.dataset.name, cols, parseInt(m[1], 10) - 1); else setEditor('', cols, -1);
  document.getElementById('thmsg').textContent = t('Preview: Apply to keep it.');
}
document.querySelectorAll('.thcard:not(.new)').forEach(function (c) { c.onclick = function () { thPick(c); }; });
var nc = document.getElementById('newcard');
// + starts a new custom theme from the colours of the theme selected (owner 2026-10-24)
if (nc) nc.onclick = function () {
  var sel = document.querySelector('.thcard[data-theme="' + thTheme + '"]');
  var cols = sel ? sel.dataset.colors.split(',') : colorsNow();
  setEditor('', cols, -1); thShow(cols, "custom"); document.getElementById('cname').focus();
};
document.querySelectorAll('.custom input[type=color]').forEach(function (inp) {
  inp.oninput = function () {
    var cols = colorsNow();
    thShow(cols, "custom");
    if (edIndex >= 0) {
      var card = document.querySelector('.thcard[data-theme=custom' + (edIndex + 1) + ']');
      card.dataset.colors = cols.join(',');
      var sw = card.querySelectorAll('.mock, .mm, .mp, .mp i');
      [0, 1, 2, 3, 4].forEach(function (i) { sw[i].style.background = cols[i]; });
    }
    document.getElementById('thmsg').textContent = t('Preview: save the custom theme, then Apply.');
  };
});
function cuSave(what) {
  var list = thCustoms.slice(), name = document.getElementById('cname').value.trim(), cols = colorsNow();
  if (what === 'new') {
    list.push({name: name || t('Custom {n}', {n: list.length + 1}), colors: cols});
  } else if (what === 'update') {
    list[edIndex] = {name: name || list[edIndex].name, colors: cols};
  } else {
    if (!confirm(t('Delete the custom theme "{name}"?', {name: list[edIndex].name}))) return;
    list.splice(edIndex, 1);
  }
  var x = new XMLHttpRequest();
  x.onload = function () {
    if (this.responseText.trim() !== 'OK') { document.getElementById('thmsg').textContent = this.status === 401 ? t('Log in first.') : this.responseText; return; }
    // a new or updated theme is applied at once where "Apply to" says (owner 2026-10-24: after Update the page came back
    // on the theme applied before, Forest, as if the edit were lost)
    var n = what === 'new' ? list.length : (what === 'update' ? edIndex + 1 : 0);
    if (n) {
      var q = 'scripts/appearance.php?save=1&for=' + thTarget() + '&theme=custom' + n, y = new XMLHttpRequest();
      // a refused save is shown, not hidden behind a reload (the page would come back on the old theme)
      y.onload = function () { if (this.responseText.trim() === 'OK') thReload(); else document.getElementById('thmsg').textContent = this.status === 401 ? t('Log in first.') : this.responseText; };
      y.open('GET', q, true); y.send();
    }
    else thReload();
  };
  x.open('POST', 'scripts/appearance.php?customs=1', true); x.setRequestHeader('Content-Type', 'application/json'); x.send(JSON.stringify(list));
}
function thTarget() { var sel = document.getElementById('thtarget'); return sel ? sel.value : 'location'; }
function thApply() {
  var x = new XMLHttpRequest();
  x.onload = function () {
    document.getElementById('thmsg').textContent = this.responseText == 'OK' ? t('Saved.') : (this.status === 401 ? t('Log in first.') : this.responseText);
    if (this.responseText == 'OK') setTimeout(thReload, 600);
  };
  x.open('GET', 'scripts/appearance.php?save=1&for=' + thTarget() + '&theme=' + encodeURIComponent(thTheme), true);
  x.send();
}
// the editor opens on the theme in use (owner 2026-10-24: with Ocean applied it showed the first custom theme's colours):
// a custom one for editing, a fixed one as the start of a new custom theme
(function () { var on = document.querySelector('.thcard.on'); var m = on && /^custom(\d+)$/.exec(on.dataset.theme);
  if (m) setEditor(on.dataset.name, on.dataset.colors.split(','), parseInt(m[1], 10) - 1);
  else setEditor('', on ? on.dataset.colors.split(',') : colorsNow(), -1); })();
</script>
