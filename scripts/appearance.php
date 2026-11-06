<?php
################################################################################
# scripts/appearance.php - APPEARANCE
#
# PURPOSE:
#   Appearance (owner 2026-10-09): the app colours — fixed themes and custom themes with a name (owner
#   2026-10-20, no limit since 2026-10-21). A click previews the theme on the whole app at once; Apply saves
#   the choice in BirdSuite.conf (APP_THEME) for every browser — where the "Apply to" picklist says (owner
#   2026-10-24): the current location (its own theme in config/locations/locations.json, the default choice)
#   or the system default (APP_THEME, the theme of the locations without one). Custom themes live in
#   config/custom_themes.json (common.php custom_themes_load()). Opened as views.php?view=Themes; POST
#   scripts/appearance.php?save=1&theme=... picks, POST ?customs=1 stores the custom list. Colours: common.php
#   theme_presets() / theme_style().
#
# DEPENDENCIES:
#   - scripts/common.php
#
# USAGE:
#   views.php?view=Themes
#   Referenced by homepage/views.php, scripts/update_caddyfile.sh
################################################################################

require_once __DIR__ . '/common.php';
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
if (isset($_GET['save'])) {
	ensure_authenticated(t('str_you_must_change_the_settings_help'));
	if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); die(); }
	$theme = strtolower($_GET['theme'] ?? '');
	if (!theme_valid($theme)) { echo t('str_error_unknown_theme'); die(); }
	// Apply and a new custom theme set the current location's theme (owner 2026-10-24: Apply changed the default, which
	// the current location's own theme hides, so nothing changed); for=default ("Apply to" System default) sets APP_THEME
	$loc = location_current();
	if (($_GET['for'] ?? 'location') !== 'default' && $loc && isset($loc['id'])) {
		echo location_theme_write($theme) ? 'OK' : t('str_error_writing', array('file' => basename(locations_file())));
		die();
	}
	echo conf_set('APP_THEME', $theme, $err) ? 'OK' : $err;
	die();
}
// the custom themes as a whole (id, name, six colours): a theme keeps its id, a new one gets the number after the highest
// (ids never shift, US-21: a deleted theme's choices are cleared below); answers OK and the ids in the order sent (JSON)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_GET['customs'])) {
	ensure_authenticated(t('str_you_must_change_the_settings_help'));
	$in = json_decode((string)file_get_contents('php://input'), true);
	$known = array_column(custom_themes_load(), 'id');
	$top = 0;
	foreach (array_merge($known, array_map(function ($t) { return (string)($t['id'] ?? ''); }, is_array($in) ? $in : array())) as $id)
		if (preg_match('/^custom([1-9][0-9]*)$/', $id, $m)) $top = max($top, intval($m[1]));
	$out = array(); $used = array();
	foreach (is_array($in) ? $in : array() as $t) {
		$cols = array_map('strtolower', array_map('trim', (array)($t['colors'] ?? array())));
		if (count($cols) !== 6 || count(preg_grep('/^#[0-9a-f]{6}$/', $cols)) !== 6) { echo t('str_error_six_colours_per_theme'); die(); }
		$id = (string)($t['id'] ?? '');
		if (!in_array($id, $known, true) || isset($used[$id])) $id = 'custom' . (++$top);
		$used[$id] = true;
		$name = mb_substr(trim(preg_replace('/[\x00-\x1F\x7F<>"]/', '', (string)($t['name'] ?? ''))), 0, 30);
		$out[] = array('id' => $id, 'name' => $name !== '' ? $name : $id, 'colors' => $cols);
	}
	$ok = file_put_contents(custom_themes_file(), json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n") !== false;
	@chmod(custom_themes_file(), 0664);
	if ($ok) {
		// a deleted theme leaves no choice behind: the system default goes back to Ocean, a location to the station's theme
		$cur = strtolower(trim(get_config()['APP_THEME'] ?? ''));
		if (preg_match('/^custom[1-9][0-9]*$/', $cur) && !isset($used[$cur])) conf_set('APP_THEME', 'ocean');
		$list = locations_load(); $changed = false;
		foreach ($list as &$l) if (preg_match('/^custom[1-9][0-9]*$/', (string)($l['theme'] ?? '')) && !isset($used[$l['theme']])) { $l['theme'] = ''; $changed = true; }
		unset($l);
		if ($changed) locations_save($list);
	}
	echo $ok ? 'OK ' . json_encode(array_column($out, 'id')) : t('str_error_writing', array('file' => basename(custom_themes_file())));
	die();
}
$c = get_config();
$current = strtolower(trim($c['APP_THEME'] ?? 'ocean')) ?: 'ocean'; // Ocean is the default (owner 2026-10-24)
if (!theme_valid($current)) $current = 'ocean';
$customs = custom_themes_load();
$dark = theme_name_in_use() === 'dark';  // the switch shows Dark when the theme in use is Dark
// the colours named by where they show (owner 2026-10-24: "Panels" and "Buttons" did not say it); style.css:
// --bg page, --menu menu bar and table rows, --panel table cells / boxes / page buttons, --accent most buttons, links
// and marks, --accent2 the selection: the row under the mouse and the page you are on in the menu, a light shade of it
// for the menu item under the mouse (owner 2026-10-24), the slider knobs
// short names under each colour (owner 2026-10-24: one word, no line break), the full one on mouse over
$roles = array(array('str_background', 'str_background'), array('str_menu', 'str_menu_and_table_rows'), array('str_table', 'str_table_cells_boxes_and_page_buttons'),
							 array('str_buttons', 'str_buttons_and_links'), array('str_selection', 'str_selection_the_row_under_the_help'),
							 array('str_text', 'str_text'));
// the card marked on load is the theme in use: the current location's own theme, else the system default (owner
// 2026-10-21); the line above the cards names the saved system default
$loc_now = location_current();
$in_use = ($loc_now && location_theme($loc_now) !== '') ? location_theme($loc_now) : $current;
$card = function ($key, $name, $col) use ($in_use) {
	return '<button type="button" class="thcard' . ($key === $in_use ? ' on' : '') . '" aria-pressed="' . ($key === $in_use ? 'true' : 'false') . '" data-theme="' . $key . '" data-colors="' . implode(',', $col) . '" data-name="' . htmlspecialchars($name, ENT_QUOTES) . '">'
		. '<span class="mock" style="background:' . $col[0] . '"><span class="mm" style="background:' . $col[1] . '"></span>'
		. '<span class="mp" style="background:' . $col[2] . '"><i style="background:' . $col[3] . '"></i><i style="background:' . $col[4] . '"></i></span></span>'
		. '<b>' . str_replace(' (', '<br>(', htmlspecialchars($name)) . '</b></button>';
};
// the editor's first colours before the script sets those of the theme in use
$start = isset(theme_presets()[$in_use]) ? array_slice(theme_presets()[$in_use], 1)
	: (custom_theme($in_use) !== null ? custom_theme($in_use)['colors'] : array_slice(theme_presets()['ocean'], 1));
$hs = function ($x) { return htmlspecialchars((string)$x, ENT_QUOTES); };
?>
<style>
.app { max-width: 1000px; margin: 0; padding: 0 12px; text-align: left; }
/* the page's text and boxes in the theme's own colours (Gitea #99: fixed greys and a translucent white box were unreadable
	 under a custom dark theme, which keeps the light stylesheet) */
.app h2 { margin: 8px 0 4px; font-size: 1.3em; } .app p.note { font-size: 13px; color: var(--text, #000); opacity: .85; margin: 0 0 12px; }
.app h3 { margin: 16px 0 6px; font-size: 1.05em; }
.app .grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(150px, 1fr)); gap: 12px; }
.app .thcard { width: auto; display: flex; flex-direction: column; gap: 6px; align-items: stretch; padding: 8px; border-radius: 12px; border: 2px solid rgba(0,0,0,.08); background: rgba(255,255,255,.6); cursor: pointer; color: #000; }
.app .thcard:hover { border-color: #d97a00; } .app .thcard.on { border-color: var(--accent, #2b5e22); box-shadow: 0 0 0 2px var(--accent, #2b5e22); }
.app .thcard.new { justify-content: center; align-items: center; min-height: 110px; font-size: 14px; border-style: dashed; }
.app .mock { display: flex; height: 74px; border-radius: 8px; overflow: hidden; padding: 6px; gap: 6px; }
.app .mm { width: 26%; border-radius: 4px; } .app .mp { flex: 1; border-radius: 4px; display: flex; align-items: flex-end; gap: 4px; padding: 6px; }
.app .mp i { display: block; width: 28px; height: 12px; border-radius: 6px; }
.app .custom { margin-top: 16px; border: 1px solid rgba(0,0,0,.08); background: var(--panel, #f4f6f8); color: var(--text, #000); border-radius: 12px; padding: 10px 12px; }
.app #cwarn { font-size: 12px; margin-top: 8px; color: var(--text, #000); font-weight: 600; }  /* in the theme's text colour: red was unreadable on a dark panel */
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
	$def_name = isset(theme_presets()[$current]) ? t(theme_presets()[$current][0]) : (custom_theme($current) !== null ? custom_theme($current)['name'] : $current); ?>
	<p class="note"><?php echo t('str_system_default_colon'); ?> <b><?php echo $hs($def_name); ?></b></p>
	<?php // no Light / Dark buttons (owner 2026-10-25): the dark stylesheet follows the Dark theme (get_color_scheme) ?>
	<div class="grid">
		<?php foreach (theme_presets() as $k => $p) echo $card($k, t($p[0]), array_slice($p, 1)); ?>
	</div>
	<h3><?php echo t('str_custom_themes'); ?></h3>
	<div class="grid" id="customgrid">
		<?php foreach ($customs as $t) echo $card($t['id'], $t['name'], $t['colors']);
			echo '<button type="button" class="thcard new" id="newcard">+ ' . t('str_new_custom_theme') . '</button>'; ?>
	</div>
	<div class="custom">
		<b id="edtitle"><?php echo t('str_edit_title'); ?></b>: <?php echo t('str_name_and_the_six_colours_shown_help'); ?><br>
		<label><?php echo t('str_name'); ?><input type="text" id="cname" maxlength="30" placeholder="<?php echo t('str_my_theme'); ?>"></label>
		<?php // shown in this order (owner 2026-10-24: Text after Background, Buttons last); data-i is the colour's place in the stored list
		foreach (array(0, 5, 1, 2, 4, 3) as $i) { $r = $roles[$i]; echo '<label class="clr" title="' . $hs(t($r[1])) . '"><input type="color" data-i="' . $i . '" value="' . htmlspecialchars($start[$i]) . '"><span>' . $hs(t($r[0])) . '</span></label>'; } ?>
		<?php // the pairs that cannot be read (Gitea #100): a warning, the save still allowed ?>
		<div id="cwarn" aria-live="polite"></div>
		<div style="margin-top:10px;display:flex;gap:8px;flex-wrap:wrap">
			<button type="button" id="btnnew" onclick="cuSave('new')"><?php echo t('str_save_as_a_new_custom_theme'); ?></button>
			<button type="button" id="btnupd" onclick="cuSave('update')"><?php echo t('str_update_this_custom_theme'); ?></button>
			<button type="button" id="btndel" class="del" onclick="cuSave('delete')"><?php echo t('str_delete_this_custom_theme'); ?></button>
		</div>
	</div>
	<?php // where Apply saves the theme (owner 2026-10-24): the current location first (the default), the system default below ?>
	<div class="actions"><label><?php echo t('str_apply_to'); ?> <select id="thtarget">
			<?php if ($loc_now) echo '<option value="location" selected>' . $hs(t('str_current_location_name', array('name' => $loc_now['name'] ?? ''))) . '</option>'; ?>
			<option value="default"><?php echo t('str_system_default'); ?></option></select></label>
		<button type="button" class="apply" onclick="thApply()"><?php echo t('str_apply'); ?></button>
		<button type="button" onclick="location.reload()"><?php echo t('str_back_to_the_saved_theme'); ?></button><span id="thmsg"></span></div>
	<p class="note" style="margin-top:1.4em"><?php echo t('str_change_the_theme_of_each_help'); ?></p>
</div>
<script>
// the theme selected = the one marked on load, the theme in use here (owner 2026-10-24: it was the system default, so
// Apply right after a reload put Forest back on the location)
var thTheme = <?php echo json_encode($in_use); ?>;
// after a save the whole app reloads, header and menu included, back on this page (owner 2026-10-21)
// a fresh load of the whole app (owner 2026-10-24: a menu item sometimes kept the old colours): a new address each time,
// so the browser takes nothing from its cache
function thReload() {
	var url = 'index.php?view=Themes&t=' + Date.now();
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
// the relative luminance of a colour, the WCAG way (as common.php contrast_text())
function lumOf(hex) {
	return [0.2126, 0.7152, 0.0722].reduce(function (s, w, i) {
		var c = parseInt(hex.substr(1 + 2 * i, 2), 16) / 255;
		return s + w * (c <= 0.03928 ? c / 12.92 : Math.pow((c + 0.055) / 1.055, 2.4));
	}, 0);
}
// black or white on a colour, whichever contrasts more
function contrastText(hex) {
	var l = lumOf(hex);
	return 1.05 / (l + 0.05) >= (l + 0.05) / 0.05 ? '#fff' : '#000';
}
// the contrast ratio of two colours (1 to 21)
function contrastRatio(a, b) {
	var l1 = lumOf(a), l2 = lumOf(b);
	return (Math.max(l1, l2) + 0.05) / (Math.min(l1, l2) + 0.05);
}
// the pairs of the editor's colours that cannot be read (under 4.5:1, WCAG AA; Gitea #100): Text on Background, Menu
// and Table, Buttons on Table; the stored order is bg, menu, panel, accent, accent2, text
function cuWarn(cols, show) {
	// only for a custom theme, new or edited (a fixed theme's own colours are drawn by its stylesheet and cannot be changed here)
	if (show === false) { document.getElementById('cwarn').textContent = ''; return; }
	var names = {0: t('str_background'), 1: t('str_menu'), 2: t('str_table'), 3: t('str_buttons'), 5: t('str_text')};
	var bad = [[5, 0], [5, 1], [5, 2], [3, 2]].filter(function (p) { return contrastRatio(cols[p[0]], cols[p[1]]) < 4.5; })
		.map(function (p) { return t('str_a_on_b', {a: names[p[0]], b: names[p[1]]}) + ' (' + fmtNum(contrastRatio(cols[p[0]], cols[p[1]]), 1) + ':1)'; });
	document.getElementById('cwarn').textContent = bad.length ? '\u26a0 ' + t('str_low_contrast_pairs_help', {pairs: bad.join(', ')}) : '';
}
// the six colours in their stored order (bg, menu, panel, accent, accent2, text), whatever the order on the page
function colorsNow() { var c = []; document.querySelectorAll('.custom input[type=color]').forEach(function (x) { c[+x.dataset.i] = x.value; }); return c; }
function setEditor(name, colors, index) {
	edIndex = index;
	document.getElementById('cname').value = name;
	document.querySelectorAll('.custom input[type=color]').forEach(function (x) { x.value = colors[+x.dataset.i]; });
	document.getElementById('edtitle').textContent = index >= 0 ? t('str_edit_name', {name: name}) : t('str_new_custom_theme');
	document.getElementById('btnupd').style.display = index >= 0 ? '' : 'none';
	document.getElementById('btndel').style.display = index >= 0 ? '' : 'none';
	cuWarn(colors, index >= 0);
}
function thPick(card) {
	document.querySelectorAll('.thcard').forEach(function (c) { c.classList.toggle('on', c === card); c.setAttribute('aria-pressed', c === card ? 'true' : 'false'); });
	thTheme = card.dataset.theme;
	var cols = card.dataset.colors.split(',');
	thShow(cols, thTheme);
	// a fixed theme is a starting point for a new custom one; a custom one opens for editing
	setEditor(cuIndex(thTheme) >= 0 ? card.dataset.name : '', cols, cuIndex(thTheme));
	document.getElementById('thmsg').textContent = t('str_preview_apply_to_keep_it');
}
document.querySelectorAll('.thcard:not(.new)').forEach(function (c) { c.onclick = function () { thPick(c); }; });
var nc = document.getElementById('newcard');
// + starts a new custom theme from the colours of the theme selected (owner 2026-10-24)
if (nc) nc.onclick = function () {
	var sel = document.querySelector('.thcard[data-theme="' + thTheme + '"]');
	var cols = sel ? sel.dataset.colors.split(',') : colorsNow();
	setEditor('', cols, -1); thShow(cols, "custom"); cuWarn(cols, true); document.getElementById('cname').focus();
};
document.querySelectorAll('.custom input[type=color]').forEach(function (inp) {
	inp.oninput = function () {
		var cols = colorsNow();
		thShow(cols, "custom");
		cuWarn(cols, true);
		if (edIndex >= 0) {
			var card = document.querySelector('.thcard[data-theme="' + thCustoms[edIndex].id + '"]');
			card.dataset.colors = cols.join(',');
			var sw = card.querySelectorAll('.mock, .mm, .mp, .mp i');
			[0, 1, 2, 3, 4].forEach(function (i) { sw[i].style.background = cols[i]; });
		}
		document.getElementById('thmsg').textContent = t('str_preview_save_the_custom_theme_help');
	};
});
function cuSave(what, asked) {
	var list = thCustoms.slice(), name = document.getElementById('cname').value.trim(), cols = colorsNow();
	if (what === 'new') {
		list.push({name: name || t('str_custom_n', {n: list.length + 1}), colors: cols});
	} else if (what === 'update') {
		list[edIndex] = {id: list[edIndex].id, name: name || list[edIndex].name, colors: cols};
	} else {
		// asked through the app's modal first; on Yes it comes back here with asked = true
		if (!asked) { bsAsk(t('str_delete_the_custom_theme', {name: list[edIndex].name}), true).then(function (ok) { if (ok) cuSave(what, true); }); return; }
		list.splice(edIndex, 1);
	}
	var x = new XMLHttpRequest();
	x.onload = function () {
		var r = this.responseText.trim(), ids = null;
		if (r.indexOf('OK ') === 0) { try { ids = JSON.parse(r.slice(3)); } catch (e) {} }
		if (!ids) { document.getElementById('thmsg').textContent = this.status === 401 ? t('str_log_in_first') : r; return; }
		// a new or updated theme is applied at once where "Apply to" says (owner 2026-10-24: after Update the page came back
		// on the theme applied before, Forest, as if the edit were lost); the server gave each theme its id
		var id = what === 'new' ? ids[ids.length - 1] : (what === 'update' ? ids[edIndex] : '');
		if (id) {
			var q = 'scripts/appearance.php?save=1&for=' + thTarget() + '&theme=' + encodeURIComponent(id), y = new XMLHttpRequest();
			// a refused save is shown, not hidden behind a reload (the page would come back on the old theme)
			y.onload = function () { if (this.responseText.trim() === 'OK') thReload(); else document.getElementById('thmsg').textContent = this.status === 401 ? t('str_log_in_first') : this.responseText; };
			y.open('POST', q, true); y.send();
		}
		else thReload();
	};
	x.open('POST', 'scripts/appearance.php?customs=1', true); x.setRequestHeader('Content-Type', 'application/json'); x.send(JSON.stringify(list));
}
// the place of a custom theme in the list by its id (-1 = not a custom theme)
function cuIndex(id) { for (var i = 0; i < thCustoms.length; i++) if (thCustoms[i].id === id) return i; return -1; }
function thTarget() { var sel = document.getElementById('thtarget'); return sel ? sel.value : 'location'; }
function thApply() {
	var x = new XMLHttpRequest();
	x.onload = function () {
		document.getElementById('thmsg').textContent = this.responseText == 'OK' ? t('str_saved_dot') : (this.status === 401 ? t('str_log_in_first') : this.responseText);
		if (this.responseText == 'OK') setTimeout(thReload, 600);
	};
	x.open('POST', 'scripts/appearance.php?save=1&for=' + thTarget() + '&theme=' + encodeURIComponent(thTheme), true);
	x.send();
}
// the editor opens on the theme in use (owner 2026-10-24: with Ocean applied it showed the first custom theme's colours):
// a custom one for editing, a fixed one as the start of a new custom theme
(function () { var on = document.querySelector('.thcard.on'), i = on ? cuIndex(on.dataset.theme) : -1;
	if (i >= 0) setEditor(on.dataset.name, on.dataset.colors.split(','), i);
	else setEditor('', on ? on.dataset.colors.split(',') : colorsNow(), -1); })();
</script>
