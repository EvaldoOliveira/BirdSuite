<?php
    error_reporting(E_ALL);
    ini_set('display_errors', 0);  // errors to the log, never to the page (2026-10-23 security run)

    // $title is the view name (the ADD / REMOVE forms post it); $label is what the page shows (menu names, owner 2026-10-09)
    if ($species_list=="include") {
        $title="Allowed species";
        $label=t('str_custom_species');
        $message=t('str_to_constrain_the_analyses_to_a_help') . '<br>' . t('str_keep_this_list_empty_unless_you_help');
        $selectedfilename = BS_LISTS . '/include_species_list.txt';
    } elseif ($species_list=="exclude") {
        $title="Never accept";
        $label=t('str_excluded_species');
        $message=t('str_exclude_species_from_the_help');
        $selectedfilename = BS_LISTS . '/exclude_species_list.txt';
    } elseif ($species_list=="whitelist") {
        $title="Always accept";
        $label=t('str_whitelisted_species');
        $message=t('str_the_selected_species_will_be_help') . '<br>' . t('str_this_is_not_a_recommended_way_help');
        $selectedfilename = BS_LISTS . '/whitelist_species_list.txt';   
    } elseif ($species_list=="nocturnal") {
        $title="Night-only species";
        $label=t('str_nocturnal_species');
        $message=t('str_the_species_that_only_sing_at_help');
        $selectedfilename = BS_LISTS . '/nocturnal_species_list.txt';
    }
    

    if (file_exists($selectedfilename)) {
        $eachselected = file($selectedfilename, FILE_IGNORE_NEW_LINES);
    }
    else {
        $eachselected = [];
    }
    // Available Species (owner 2026-10-09): what the active model can detect minus the species already in this list,
    // so a species added moves from the left to the right (and back when removed)
    // a line is the scientific name (owner 2026-11-05, "one index only"); an older "Scientific name_Common name" line
    // counts by the part before the first '_' (as the analysis, loadCustomSpeciesList). A species added is written as
    // its scientific name alone: the option value is the line written, the names shown come from the picklist below
    $line_sci = function ($l) { return trim(explode('_', trim((string)$l), 2)[0]); };
    $in_list = array();
    foreach ($eachselected as $l) $in_list[$line_sci($l)] = true;
    $eachlabel = array();
    foreach (active_model_labels() as $sci => $name) {
      if (!isset($in_list[$sci])) $eachlabel[] = (string)$sci;
    }
    sort($eachlabel, SORT_STRING);
    // groups from species_info.csv; Noise and None removed (owner 2026-10-09): the V3 model has no noise labels (only the
    // V2.4 ones, kept in old lists) and every V3 label has a type, so both stayed empty — such labels show under All
    // region (owner 2026-10-09, back with the complete model/species_info.csv): continents with >= 3 % of the GBIF
    // records, Global = four or more continents (matches every region), Ocean = mostly records at sea
    $regions = array('' => t('str_any_region'), 'Africa' => t('str_africa'), 'Asia' => t('str_asia'), 'Europe' => t('str_europe'), 'North America' => t('str_north_america'),
      'South America' => t('str_south_america'), 'Oceania' => t('str_oceania'), 'Antarctica' => t('str_antarctica'), 'Ocean' => t('str_ocean'));
    $groups = array('' => t('str_all'), 'birds' => t('str_birds'), 'mammals' => t('str_mammals'), 'amphibians' => t('str_amphibians'), 'insects' => t('str_insects'), 'domestic' => t('str_domestic'));
    // each species by the name the picklist above the tables asks for (owner 2026-11-04, as the other species tables):
    // common (the model's name in the station language), scientific or English; a line the model does not know keeps its
    // text in every mode. The value stays the line of the file
    // a name not found in the language shown is in parentheses (owner 2026-11-04): a common name the model has only in
    // English (station language not English), an English name it does not have, a line it does not know at all
    $labels = active_model_labels();
    $en_ui = strpos(ui_lang(), 'en') === 0;
    $option = function ($line) use ($labels, $en_ui, $line_sci) {
      $sci = $line_sci($line);
      $known = isset($labels[$sci]);
      $eng = $known ? (string)get_english_name($sci) : '';
      $com = $known ? $labels[$sci] : '(' . $sci . ')';  // an unknown line by its scientific part (an older line had the common name)
      if ($known && !$en_ui && $eng !== '' && mb_strtolower($com) === mb_strtolower($eng)) $com = '(' . $com . ')';
      $en = $known ? ($eng !== '' ? $eng : '(' . $labels[$sci] . ')') : '(' . $sci . ')';
      $h = function ($x) { return htmlspecialchars((string)$x, ENT_QUOTES); };
      // the common name is the option's text and the scientific name its value (name-mode.js reads them there); a
      // scientific name only when it differs from the line (an unknown line, shown in parentheses)
      $sci_shown = $known ? $sci : '(' . $sci . ')';
      return '<option value="' . $h($line) . '"' . ($sci_shown !== $line ? ' data-sci="' . $h($sci_shown) . '"' : '') . ' data-en="' . $h($en) . '" data-g="' . species_group($sci) . '" data-r="' . $h(species_info($sci)[1]) . '">' . $h($com) . '</option>';
    };
?>


<meta name="viewport" content="width=device-width, initial-scale=1">

<style>
  /* as wide as the names need (owner 2026-11-04: with the names alone the full width was mostly empty); a phone keeps
     the whole width */
  .splist { width: 90%; max-width: 880px; margin: 10px auto; }
  .splist-row { display: flex; align-items: center; gap: 2%; }
  .splist-box { flex: 1 1 0; min-width: 0; }
  .splist-box form { width: 100%; }
  .splist-box select { width: 100%; height: 60vh; }
  .splist-box input[type=text] { width: 100%; box-sizing: border-box; }
  .splist-mid { flex: 0 0 auto; display: flex; flex-direction: column; gap: 20px; }
  .splist button { padding: 12px; background-color: var(--panel,#dbffeb); }
  .splist-note { margin-top: 14px; text-align: left; }
  .splist-tools { margin-top: 14px; display: grid; grid-template-columns: max-content max-content; justify-content: start; gap: 8px 10px; align-items: center; }
  .splist-tools button { padding: 6px 12px; text-align: left; }
  .splist .smaller { display: none; }
  .splist-source { font-size: 12px; opacity: .8; }
  .splist-groups { margin: 4px 0 8px; display: flex; gap: 6px; flex-wrap: wrap; }
  .splist .splist-groups button { padding: 4px 12px; background: #fff; }
  .splist .splist-groups select { padding: 3px 6px; border-radius: 10px; font-size: 13px; width: auto; height: auto; }
  .splist .splist-groups button.active { background: var(--accent,#2b5e22); color: #fff; }
  .splist h3 small { font-weight: normal; font-size: 12px; color: #222; }
  /* a phone (2026-10-23 test run): the list tools one under the other, the picklist never wider than the screen */
  .splist-tools select, .splist-tools input[type=text] { max-width: 100%; min-width: 0; box-sizing: border-box; }
  .splist-tools b { min-width: 0; overflow-wrap: anywhere; }
  @media screen and (max-width: 600px) { .splist-tools { grid-template-columns: minmax(0, 1fr); } }
  @media screen and (max-width: 1000px) {
    .splist-mid { display: none; }
    .splist .smaller { display: block; margin-top: 6px; }
  }
</style>
<?php
$active = '';
if ($species_list == "include") {
  // station species lists (owner 2026-10-08): which one is active, save it under a name or load another
  $real = (string)realpath($selectedfilename);
  $active = strpos($real, '/species_lists/custom/') !== false ? basename($real, '.txt') : '';
} else {
  // Excluded / Whitelisted (owner 2026-10-21, as Custom Species): the name of the saved list the table equals
  $kdir0 = BS_CUSTOM_LISTS . '/' . $species_list;
  // compared by the scientific names (a list saved before 2026-11-05 has "Scientific name_Common name" lines, one saved
  // since the scientific name alone: the same species are the same list)
  $sci_set = function ($lines) use ($line_sci) {
    $s = array();
    foreach ($lines as $l) { $k = $line_sci($l); if ($k !== '' && $k[0] !== '#') $s[$k] = true; }
    $s = array_keys($s);
    sort($s, SORT_STRING);
    return $s;
  };
  $cur0 = $sci_set(is_file($selectedfilename) ? file($selectedfilename, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) : array());
  $same0 = array();
  foreach (glob($kdir0 . '/*.txt') ?: array() as $f) { if ($sci_set(file($f, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES)) === $cur0) $same0[] = basename($f, '.txt'); }
  $last0 = trim((string)@file_get_contents($kdir0 . '/.loaded'));
  $active = $same0 ? (in_array($last0, $same0, true) ? $last0 : $same0[0]) : '';
}
?>
<div class="splist">
<?php if ($species_list == "nocturnal") {
  // the limits of the day (owner 2026-10-30): the margins are saved as they change, the times of today follow them
  $cf = get_config();
  $dawn = intval($cf['NOCTURNAL_DAWN_MARGIN'] ?? 30); $dusk = intval($cf['NOCTURNAL_DUSK_MARGIN'] ?? 30);
  $sun = date_sun_info(time(), floatval($cf['LATITUDE'] ?? 0), floatval($cf['LONGITUDE'] ?? 0));
  $rise = is_int($sun['sunrise']) ? intval(date('G', $sun['sunrise'])) * 60 + intval(date('i', $sun['sunrise'])) : null;
  $set = is_int($sun['sunset']) ? intval(date('G', $sun['sunset'])) * 60 + intval(date('i', $sun['sunset'])) : null; ?>
<div class="splist-note" id="nocday" data-rise="<?php echo $rise; ?>" data-set="<?php echo $set; ?>">
  <label><?php echo t('str_day_starts_after_sunrise_minutes'); ?> <input type="number" id="noc_dawn" min="0" max="180" step="5" style="width:5em" data-setting="NOCTURNAL_DAWN_MARGIN" value="<?php echo $dawn; ?>"></label><br>
  <label><?php echo t('str_day_ends_before_sunset_minutes'); ?> <input type="number" id="noc_dusk" min="0" max="180" step="5" style="width:5em" data-setting="NOCTURNAL_DUSK_MARGIN" value="<?php echo $dusk; ?>"></label>
  <?php if ($rise !== null && $set !== null) { ?><p style="margin:6px 0 0"><?php echo t('str_today_sunrise_sunset', array('rise' => date('H:i', $sun['sunrise']), 'set' => date('H:i', $sun['sunset']))); ?><br><?php echo t('str_dropped_by_day_from_to', array('from' => '<b id="noc_from"></b>', 'to' => '<b id="noc_to"></b>')); ?></p>
  <script>(function () {
    var b = document.getElementById('nocday'), hm = function (m) { return ('0' + Math.floor(m / 60)).slice(-2) + ':' + ('0' + m % 60).slice(-2); };
    var draw = function () {
      document.getElementById('noc_from').textContent = hm(parseInt(b.dataset.rise, 10) + (parseInt(document.getElementById('noc_dawn').value, 10) || 0));
      document.getElementById('noc_to').textContent = hm(parseInt(b.dataset.set, 10) - (parseInt(document.getElementById('noc_dusk').value, 10) || 0));
    };
    b.addEventListener('input', draw); draw();
  })();</script><?php } ?>
</div>
<?php } ?>
<div class="splist-groups nowmodes" title="<?php echo t('str_show_only_one_group_of_species_help'); ?>">
  <select class="namemode" title="<?php echo t('str_names_shown'); ?>"><option value="com"><?php echo t('str_common_name'); ?></option><option value="sci"><?php echo t('str_scientific_name'); ?></option><option value="en"><?php echo t('str_english_name'); ?></option></select>
  <?php foreach ($groups as $g => $n) echo '<button type="button" data-g="' . $g . '"' . ($g === '' ? ' class="active"' : '') . ' onclick="groupFilter(this.dataset.g)">' . $n . '</button>'; ?>
  <select id="region_filter" onchange="regionFilter(this.value)" title="<?php echo t('str_where_the_species_lives_gbif_help'); ?>">
    <?php foreach ($regions as $r => $n) echo '<option value="' . $r . '">' . $n . '</option>'; ?>
  </select>
</div>
<div class="splist-row">
  <div class="splist-box">
    <form action="views.php?view=<?php echo rawurlencode($title); ?>" method="POST" id="add">
      <h3><?php echo t('str_available_species'); ?> <small id="avail_count"></small></h3>
      <input autocomplete="off" type="text" placeholder="<?php echo t('str_search_species'); ?>" id="species_searchterm" name="species_searchterm">
      <select name="species[]" id="species" multiple size="25" aria-label="<?php echo htmlspecialchars(t('str_available_species'), ENT_QUOTES); ?>">
        <?php
        foreach ($eachlabel as $lines) echo $option($lines); ?>
      </select>
      <input type="hidden" name="add" value="add"><input type="hidden" name="species_lines">
    </form>
    <div class="smaller">
      <button type="submit" name="view" value=<?php echo "\"$title\"" ?> form="add">&gt;&gt;<?php echo t('str_add_caps'); ?>&gt;&gt;</button>
    </div>
  </div>

  <div class="splist-mid">
    <button type="submit" name="view" value=<?php echo "\"$title\"" ?> form="add">&gt;&gt;<?php echo t('str_add_caps'); ?>&gt;&gt;</button>
    <button type="submit" name="view" value=<?php echo "\"$title\"" ?> form="del"><?php echo t('str_remove_caps'); ?></button>
  </div>

  <div class="splist-box">
    <form action="views.php?view=<?php echo rawurlencode($title); ?>" method="POST" id="del">
      <h3><?php echo $label; ?><?php if ($active !== '') echo ', ' . htmlspecialchars($active); ?> <small id="list_count"></small></h3>
      <input autocomplete="off" type="text" placeholder="<?php echo t('str_search_species'); ?>" id="list_searchterm">
      <select name="species[]" id="value2" multiple size="25" aria-label="<?php echo htmlspecialchars(strip_tags($label), ENT_QUOTES); ?>">
      <?php
      if (count($eachselected) == 0) echo '<option disabled value="base">' . t('str_please_select') . '</option>';
      foreach ($eachselected as $lines) echo $option($lines); ?>
      </select>
      <input type="hidden" name="del" value="del"><input type="hidden" name="species_lines">
    </form>
    <div class="smaller">
      <button type="submit" name="view" value=<?php echo "\"$title\"" ?> form="del"><?php echo t('str_remove_caps'); ?></button>
    </div>
  </div>
</div>

<div class="splist-note"><?php echo $message ?></div>

<?php
// the list line under the table (owner 2026-11-04), the same on the four list pages: List [name], changed, Save, Save as.
// Choosing a list loads it (asked only when the table has changes not saved); Save writes over the current list in one
// click; Save as asks a name and asks again before it replaces one. Below, Delete a saved list (owner 2026-11-05).
// Allowed species is a link to the active saved list: every change goes straight into it, so it has no Save.
$kind_dir = $species_list == "include" ? BS_CUSTOM_LISTS : BS_CUSTOM_LISTS . '/' . $species_list;
$names = array();
foreach (glob($kind_dir . '/*.txt') ?: array() as $f) $names[] = basename($f, '.txt');
sort($names, SORT_NATURAL | SORT_FLAG_CASE);
$live = $species_list == "include";
if ($live) {
  $cur_name = $active;
  $changed = false;
} else {
  $cur_text = is_file($selectedfilename) ? file($selectedfilename, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) : array();
  $last = trim((string)@file_get_contents($kind_dir . '/.loaded'));
  // the list the table equals, else the one loaded or saved last (the table changed since)
  $cur_name = $active !== '' ? $active : (in_array($last, $names, true) ? $last : '');
  $changed = $active === '' && count($cur_text) > 0;
}
?>
<div class="splist-tools splist-line">
  <label for="list_pick"><?php echo t('str_current_list'); ?></label>
  <span class="splist-ctl">
  <select id="list_pick" onchange="listLoad(this)" title="<?php echo t($live ? 'str_choosing_a_list_makes_it_the_help' : 'str_choosing_a_saved_list_copies_it_help'); ?>">
    <?php if ($cur_name === '') echo '<option value="" selected>' . ($live ? t('str_none_each_location_has_its_own_help') : ($names ? t('str_choose') : t('str_no_saved_list_yet'))) . '</option>';
    if ($live) echo "<optgroup label='" . htmlspecialchars(t('str_station_lists'), ENT_QUOTES) . "'>";
    foreach ($names as $n) echo '<option value="' . htmlspecialchars($n, ENT_QUOTES) . '"' . ($n === $cur_name ? ' selected' : '') . '>' . htmlspecialchars($n) . '</option>';
    if ($live) {
      echo "</optgroup><optgroup label='" . htmlspecialchars(t('str_brazilian_states_built_when_loaded'), ENT_QUOTES) . "'>";
      foreach (glob(dirname(__DIR__) . '/model/include_lists/BR-*.txt') ?: array() as $f) {
        $n = basename($f, '.txt');
        if (!in_array($n, $names, true)) echo '<option value="' . $n . '">' . $n . '</option>';
      }
      echo '</optgroup>';
    } ?>
  </select>
  <?php if ($changed) echo '<span class="splist-changed">' . t('str_list_changed') . '</span>'; ?>
  <?php if ($live && $cur_name !== '') echo '<small>' . t('str_changes_go_straight_into', array('name' => htmlspecialchars($cur_name))) . '</small>'; ?>
  <?php if ($changed && $cur_name !== '') { ?><button type="button" class="primary" onclick="listSave(<?php echo htmlspecialchars(json_encode($cur_name), ENT_QUOTES); ?>, true)" title="<?php echo t('str_save_over_the_list', array('name' => htmlspecialchars($cur_name))); ?>"><?php echo t('str_save'); ?></button><?php } ?>
  <button type="button" onclick="listSaveAs()"><?php echo t('str_save_as_dots'); ?></button>
  </span>
  <?php $deletable = array_values(array_filter($names, function ($n) use ($live, $cur_name) { return !($live && $n === $cur_name); }));
  // below the picklist, in the controls column of the same grid, a button like Save as (owner 2026-11-05)
  if ($deletable) { ?><span></span><span class="splist-ctl"><button type="button" onclick="listDelete()"><?php echo t('str_delete_a_saved_list_dots'); ?></button></span><?php } ?>
</div>
<style>
  .splist-line .splist-ctl { display: inline-flex; flex-wrap: wrap; align-items: center; gap: 8px; }
  .splist-line .splist-changed { color: #b71c1c; font-weight: 700; white-space: nowrap; }
  .splist-line button.primary { background: var(--accent, #2b5e22); color: #fff; }
</style>
<script>
  var LIST_KIND = <?php echo json_encode($species_list); ?>, LIST_NAMES = <?php echo json_encode($names); ?>, LIST_CHANGED = <?php echo $changed ? 'true' : 'false'; ?>;
  var LIST_CUR = <?php echo json_encode($cur_name); ?>, LIST_DELETABLE = <?php echo json_encode($deletable); ?>;
  // a saved list wrong or no longer wanted: picked, asked again, moved out of the picklist (not the active Allowed species)
  function listDelete() {
    bsPick(window.t('str_delete_which_saved_list'), LIST_DELETABLE, window.t('str_delete')).then(n => {
      if (!n) return;
      bsAsk(window.t('str_delete_the_saved_list_q', {name: n}), true, window.t('str_delete')).then(ok => { if (ok) listPost('delete', n).then(listDone); });
    });
  }
  function listPost(action, name, replace) {
    const fd = new FormData();
    fd.append('action', action); fd.append('kind', LIST_KIND); fd.append('name', name);
    if (replace) fd.append('replace', '1');
    return fetch('scripts/species_lists.php', {method: 'POST', body: fd, credentials: 'same-origin'})
      .then(r => r.text().then(txt => ({ok: r.ok, status: r.status, txt: txt.trim()})))
      .catch(() => ({ok: false, txt: window.t('str_not_changed_network_error')}));
  }
  function listDone(r) {
    if (r.status === 401) { bsNotice(window.t('str_log_in_first')); return; }
    bsNotice(r.txt).then(() => location.reload());
  }
  // a list chosen: loaded; changes not saved are asked about first
  function listLoad(sel) {
    const n = sel.value;
    if (!n) return;
    const go = () => listPost('activate', n).then(listDone);
    if (!LIST_CHANGED) { go(); return; }
    bsAsk(window.t('str_discard_changes_and_load', {name: n}), true).then(ok => { if (ok) go(); else sel.value = LIST_CUR; });
  }
  // Save: over the current list, no name to type
  function listSave(n, replace) {
    listPost('save', n, replace).then(r => {
      if (r.txt === 'EXISTS') {
        bsAsk(window.t('str_list_exists_replace', {name: n}), true, window.t('str_replace')).then(ok => { if (ok) listSave(n, true); });
        return;
      }
      listDone(r);
    });
  }
  // Save as: a name (the current list's to start with), or one of the saved lists picked from the box (a table that came
  // from elsewhere updates the list chosen there, owner 2026-11-04); asked again before an existing list is replaced
  function listSaveAs() {
    const p = bsPrompt(window.t('str_name_of_the_list'), LIST_CUR);
    const inp = document.querySelector('#spModalText input');
    // a picklist of the saved lists above the name (owner 2026-11-04): one picked = its name in the box (that list is
    // updated, after the question), or a new name typed in the box
    if (inp && LIST_NAMES.length) {
      const pick = document.createElement('select');
      pick.style.cssText = 'width:100%;box-sizing:border-box;margin-bottom:6px';
      pick.setAttribute('aria-label', window.t('str_name_of_the_list'));  // a name for the picklist (run 5, axe select-name)
      pick.innerHTML = '<option value="">' + window.t('str_new_name_below') + '</option>'
        + LIST_NAMES.map(n => '<option' + (n === LIST_CUR ? ' selected' : '') + '>' + n.replace(/</g, '&lt;') + '</option>').join('');
      pick.onchange = () => { inp.value = pick.value; if (!pick.value) inp.focus(); };
      inp.oninput = () => { pick.value = LIST_NAMES.indexOf(inp.value.trim()) >= 0 ? inp.value.trim() : ''; };
      inp.parentNode.insertBefore(pick, inp);
    }
    p.then(n => {
      if (n === null) return;
      n = n.trim();
      if (!/^[A-Za-z0-9_-]{1,40}$/.test(n)) { bsNotice(window.t('str_name_letters_digits_and_only')); return; }
      listSave(n, false);
    });
  }
</script>
<div class="splist-note splist-source"><?php echo t('str_names_in_parentheses_help'); ?><br><br>
  <?php echo t('str_groups_birds_mammals_amphibians_help'); ?><br>
  <?php echo t('str_the_gbif_backbone_taxonomy_help'); ?><br><br>
  <?php echo t('str_global_four_or_more_continents'); ?><br>
  <?php echo t('str_ocean_mostly_records_at_sea'); ?><br>
  <?php echo t('str_table_model_species_info_csv'); ?></div>
</div>

<script src="static/name-mode.js?v=<?php echo @filemtime(__DIR__ . '/../homepage/static/name-mode.js'); ?>"></script>
<script>
    // the selected species travel as one field (newline-separated, POST): hundreds of them used to exceed the address
    // length of the old GET form and only part of a big selection was added or removed (owner 2026-10-09)
    ['add', 'del'].forEach(function (id) {
      document.getElementById(id).addEventListener('submit', function (event) {
        var sel = this.querySelector('select');
        var picked = Array.prototype.filter.call(sel.options, function (o) { return o.selected && !o.hidden && !o.disabled; }).map(function (o) { return o.value; });
        if (!picked.length) { bsNotice(window.t('str_please_click_the_species_first')); document.querySelector('.views').style.opacity = 1; event.preventDefault(); return; }
        this.querySelector('input[name=species_lines]').value = picked.join('\n');
        sel.disabled = true;   // not sent as species[] any more
      });
    });

    // the search box (left list) and the group buttons (both lists) hide the options that do not match
    var groupNow = '', regionNow = '';
    function regionFilter(r) {
      regionNow = r;
      try { localStorage.setItem('splist_region', r); } catch (e) {}
      applyFilters();
    }
    // accents ignored, as every species filter (US-19)
    function plainUp(v) { return (v || '').normalize('NFD').replace(/[\u0300-\u036f]/g, '').toUpperCase(); }
    function applyFilters() {
      var qs = {species: plainUp(document.getElementById('species_searchterm').value),
                value2: plainUp(document.getElementById('list_searchterm').value)};
      // the group and region filters act on both lists, each list has its own search box
      [['species', 'avail_count'], ['value2', 'list_count']].forEach(function (x) {
        var q = qs[x[0]];
        var shown = 0, total = 0;
        document.querySelectorAll('#' + x[0] + ' option[data-g]').forEach(function (o) {
          total++;
          var reg = o.dataset.r || '';
          // the search finds every name of the species, whichever one is shown
          var ok = (!groupNow || o.dataset.g === groupNow) && plainUp(o.value + ' ' + (o._com !== undefined ? o._com : o.textContent) + ' ' + (o.dataset.en || '')).indexOf(q) > -1
            && (!regionNow || reg === 'Global' || reg.split('+').indexOf(regionNow) > -1);
          o.hidden = !ok;
          if (!ok) o.selected = false; else shown++;
        });
        document.getElementById(x[1]).textContent = '(' + (shown === total ? total : window.t('str_shown_of_total', {shown: shown, total: total})) + ')';
      });
    }
    function groupFilter(g) {
      groupNow = g;
      document.querySelectorAll('.splist-groups button').forEach(function (b) { b.classList.toggle('active', b.dataset.g === g); });
      try { localStorage.setItem('splist_group', g); } catch (e) {}
      applyFilters();
    }
    // both tables in the order of the names shown (after name-mode.js has written them)
    function sortByName() {
      ['species', 'value2'].forEach(function (id) {
        var sel = document.getElementById(id), opts = Array.prototype.slice.call(sel.querySelectorAll('option[data-en]'));
        // a name in parentheses (not found in the language shown) sorts as if it had none; one collator and the keys
        // computed once (run 5: localeCompare over 9,800 options was a long task of the page)
        var coll = new Intl.Collator(), keyed = opts.map(function (o) { return [o.textContent.replace(/^\(|\)$/g, ''), o]; });
        keyed.sort(function (a, b) { return coll.compare(a[0], b[0]); });
        var frag = document.createDocumentFragment();
        keyed.forEach(function (k) { frag.appendChild(k[1]); });
        sel.appendChild(frag);
      });
    }
    document.querySelectorAll('select.namemode').forEach(function (s) { s.addEventListener('change', function () { setTimeout(sortByName, 0); }); });
    sortByName();
    document.getElementById('species_searchterm').addEventListener('input', applyFilters);
    document.getElementById('list_searchterm').addEventListener('input', applyFilters);
    try {
      regionNow = localStorage.getItem('splist_region') || '';
      document.getElementById('region_filter').value = regionNow;
      var g = localStorage.getItem('splist_group') || '';
      groupFilter(document.querySelector('.splist-groups button[data-g="' + g + '"]') ? g : '');
    } catch (e) { applyFilters(); }
</script>
