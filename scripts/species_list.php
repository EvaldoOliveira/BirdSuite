<?php
    error_reporting(E_ALL);
    ini_set('display_errors', 0);  // errors to the log, never to the page (2026-10-23 security run)

    // $title is the view name (the ADD / REMOVE forms post it); $label is what the page shows (menu names, owner 2026-10-09)
    if ($species_list=="include") {
        $title="Included";
        $label=t('Custom Species');
        $message=t('To constrain the analyses to a specific set, add them to the Custom Species table.') . '<br>' . t('Keep this list EMPTY unless you are ONLY interested in detecting specific species.');
        $selectedfilename = BS_LISTS . '/include_species_list.txt';
    } elseif ($species_list=="exclude") {
        $title="Excluded";
        $label=t('Excluded Species');
        $message=t('Exclude species from the analysis by adding them to the Excluded Species table.');
        $selectedfilename = BS_LISTS . '/exclude_species_list.txt';
    } elseif ($species_list=="whitelist") {
        $title="Whitelisted";
        $label=t('Whitelisted Species');
        $message=t('The selected species will be detected even if below the minimum probability of occurrence at the location defined in the settings.') . '<br>' . t('This is not a recommended way of working, as it bypasses seasonality.');
        $selectedfilename = BS_LISTS . '/whitelist_species_list.txt';   
    } elseif ($species_list=="nocturnal") {
        $title="Nocturnal";
        $label=t('Nocturnal Species');
        $message=t('The species that only sing at night: their detections are dropped by day.');
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
    $in_list = array();
    foreach ($eachselected as $l) $in_list[explode('_', trim($l), 2)[0]] = true;
    $eachlabel = array();
    foreach (active_model_labels() as $sci => $name) {
      if (!isset($in_list[$sci])) $eachlabel[] = $sci . '_' . $name;
    }
    sort($eachlabel, SORT_STRING);
    // groups from species_info.csv; Noise and None removed (owner 2026-10-09): the V3 model has no noise labels (only the
    // V2.4 ones, kept in old lists) and every V3 label has a type, so both stayed empty — such labels show under All
    // region (owner 2026-10-09, back with the complete model/species_info.csv): continents with >= 3 % of the GBIF
    // records, Global = four or more continents (matches every region), Ocean = mostly records at sea
    $regions = array('' => t('Any region'), 'Africa' => t('Africa'), 'Asia' => t('Asia'), 'Europe' => t('Europe'), 'North America' => t('North America'),
      'South America' => t('South America'), 'Oceania' => t('Oceania'), 'Antarctica' => t('Antarctica'), 'Ocean' => t('Ocean'));
    $groups = array('' => t('All'), 'birds' => t('Birds'), 'mammals' => t('Mammals'), 'amphibians' => t('Amphibians'), 'insects' => t('Insects'), 'domestic' => t('Domestic'));
    $option = function ($line) {
      $v = htmlspecialchars($line, ENT_QUOTES);
      $sci = explode('_', $line, 2)[0];
      return '<option value="' . $v . '" data-g="' . species_group($sci) . '" data-r="' . htmlspecialchars(species_info($sci)[1], ENT_QUOTES) . '">' . $v . '</option>';
    };
?>


<meta name="viewport" content="width=device-width, initial-scale=1">

<style>
  .splist { width: 90%; margin: 10px auto; }
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
  $cur0 = is_file($selectedfilename) ? file($selectedfilename, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) : array();
  sort($cur0);
  $same0 = array();
  foreach (glob($kdir0 . '/*.txt') ?: array() as $f) { $l = file($f, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES); sort($l); if ($l === $cur0) $same0[] = basename($f, '.txt'); }
  $last0 = trim((string)@file_get_contents($kdir0 . '/.loaded'));
  $active = $same0 ? (in_array($last0, $same0, true) ? $last0 : $same0[0]) : '';
}
// a table that matches no saved list (changed since it was saved or loaded) says so in red (owner 2026-10-21)
$unsaved = $active === '' && count(array_filter($eachselected, 'strlen')) > 0;
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
  <label><?php echo t('Day starts after sunrise (minutes)'); ?> <input type="number" id="noc_dawn" min="0" max="180" step="5" style="width:5em" data-setting="NOCTURNAL_DAWN_MARGIN" value="<?php echo $dawn; ?>"></label><br>
  <label><?php echo t('Day ends before sunset (minutes)'); ?> <input type="number" id="noc_dusk" min="0" max="180" step="5" style="width:5em" data-setting="NOCTURNAL_DUSK_MARGIN" value="<?php echo $dusk; ?>"></label>
  <?php if ($rise !== null && $set !== null) { ?><p style="margin:6px 0 0"><?php echo t('Today: sunrise {rise}, sunset {set}.', array('rise' => date('H:i', $sun['sunrise']), 'set' => date('H:i', $sun['sunset']))); ?><br><?php echo t('Dropped by day from {from} to {to}.', array('from' => '<b id="noc_from"></b>', 'to' => '<b id="noc_to"></b>')); ?></p>
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
<div class="splist-groups nowmodes" title="<?php echo t('Show only one group of species in both lists'); ?>">
  <?php foreach ($groups as $g => $n) echo '<button type="button" data-g="' . $g . '"' . ($g === '' ? ' class="active"' : '') . ' onclick="groupFilter(this.dataset.g)">' . $n . '</button>'; ?>
  <select id="region_filter" onchange="regionFilter(this.value)" title="<?php echo t('Where the species lives (GBIF records: continents with at least 3 % of them); Global species match every region; species without data only with Any region'); ?>">
    <?php foreach ($regions as $r => $n) echo '<option value="' . $r . '">' . $n . '</option>'; ?>
  </select>
</div>
<div class="splist-row">
  <div class="splist-box">
    <form action="views.php?view=<?php echo rawurlencode($title); ?>" method="POST" id="add">
      <h3><?php echo t('Available Species'); ?> <small id="avail_count"></small></h3>
      <input autocomplete="off" type="text" placeholder="<?php echo t('Search Species...'); ?>" id="species_searchterm" name="species_searchterm">
      <select name="species[]" id="species" multiple size="25" aria-label="<?php echo htmlspecialchars(t('Available Species'), ENT_QUOTES); ?>">
        <?php
        foreach ($eachlabel as $lines) echo $option($lines); ?>
      </select>
      <input type="hidden" name="add" value="add"><input type="hidden" name="species_lines">
    </form>
    <div class="smaller">
      <button type="submit" name="view" value=<?php echo "\"$title\"" ?> form="add">&gt;&gt;<?php echo t('ADD'); ?>&gt;&gt;</button>
    </div>
  </div>

  <div class="splist-mid">
    <button type="submit" name="view" value=<?php echo "\"$title\"" ?> form="add">&gt;&gt;<?php echo t('ADD'); ?>&gt;&gt;</button>
    <button type="submit" name="view" value=<?php echo "\"$title\"" ?> form="del"><?php echo t('REMOVE'); ?></button>
  </div>

  <div class="splist-box">
    <form action="views.php?view=<?php echo rawurlencode($title); ?>" method="POST" id="del">
      <h3><?php echo $label; ?><?php if ($active !== '') echo ', ' . htmlspecialchars($active); elseif ($unsaved) echo ', <span style="color:#c62828">' . t('Table not saved') . '</span>'; ?> <small id="list_count"></small></h3>
      <input autocomplete="off" type="text" placeholder="<?php echo t('Search Species...'); ?>" id="list_searchterm">
      <select name="species[]" id="value2" multiple size="25" aria-label="<?php echo htmlspecialchars(strip_tags($label), ENT_QUOTES); ?>">
      <?php
      if (count($eachselected) == 0) echo '<option disabled value="base">' . t('Please Select') . '</option>';
      foreach ($eachselected as $lines) echo $option($lines); ?>
      </select>
      <input type="hidden" name="del" value="del"><input type="hidden" name="species_lines">
    </form>
    <div class="smaller">
      <button type="submit" name="view" value=<?php echo "\"$title\"" ?> form="del"><?php echo t('REMOVE'); ?></button>
    </div>
  </div>
</div>

<div class="splist-note"><?php echo $message ?></div>

<?php if ($species_list == "include") { ?>
<div class="splist-tools">
  <span><?php echo t('Active list:'); ?></span>
  <b><?php echo $active !== '' ? htmlspecialchars($active) : t('none (each location has its own: Settings › Locations)'); ?></b>
  <button type="button" onclick="speciesListAction('save')"><?php echo t('Save current list as…'); ?></button>
  <input type="text" id="save_list_name" placeholder="<?php echo t('name'); ?>" style="width:14em">
  <span><?php echo t('Load this list:'); ?></span>
  <select id="station_lists" onchange="speciesListAction('activate')" title="<?php echo t('Choosing a list makes it the active one'); ?>">
    <?php
    $own = array();
    foreach (glob(BS_CUSTOM_LISTS . '/*.txt') as $f) { $own[] = basename($f, '.txt'); }
    // always starts on "choose": picking ANY list loads it, the active one or an identical one too (owner 2026-10-20)
    echo '<option value="">' . t('(choose)') . '</option>';
    echo "<optgroup label='" . htmlspecialchars(t('Station lists'), ENT_QUOTES) . "'>";
    foreach ($own as $n) {
      echo '<option value="' . htmlspecialchars($n, ENT_QUOTES) . '">' . htmlspecialchars($n) . ($n === $active ? ' ' . t('(active)') : '') . '</option>';
    }
    echo "</optgroup><optgroup label='" . htmlspecialchars(t('Brazilian states (built when loaded)'), ENT_QUOTES) . "'>";
    foreach (glob(dirname(__DIR__) . '/model/include_lists/BR-*.txt') as $f) {
      $n = basename($f, '.txt');
      if (!in_array($n, $own, true)) echo '<option value="' . $n . '">' . $n . '</option>';
    }
    echo "</optgroup>";
    ?>
  </select>
  <button type="button" onclick="speciesListAction('delete')" title="<?php echo t('Deletes a saved station list (not the active one)'); ?>"><?php echo t('Delete saved'); ?></button>
</div>
<script>
  function speciesListAction(action) {
    const fd = new FormData();
    fd.append('action', action);
    if (action === 'save') {
      const n = document.getElementById('save_list_name').value.trim();
      if (!/^[A-Za-z0-9_-]+$/.test(n)) { alert(window.t('Name: letters, digits, - and _ only')); return; }
      fd.append('name', n);
    } else {
      const sel = document.getElementById('station_lists').value;
      if (!sel) return;
      if (action === 'delete' && !confirm(window.t('Delete the saved list "{name}"?', {name: sel}))) return;
      fd.append('name', sel);
    }
    fetch('scripts/species_lists.php', { method: 'POST', body: fd }).then(r => r.text()).then(t => { alert(t); location.reload(); });
  }
</script>
<?php } ?>
<?php if ($species_list == "exclude" || $species_list == "whitelist" || $species_list == "nocturnal") {
  // save the current list under a name, load (copy over) or delete a saved one — as Custom Species (owner 2026-10-20)
  $kdir = BS_CUSTOM_LISTS . '/' . $species_list;
  $cur_text = is_file($selectedfilename) ? file($selectedfilename, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) : array();
  sort($cur_text);
  $saved = array();
  foreach (glob($kdir . '/*.txt') ?: array() as $f) {
    $l = file($f, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES); sort($l);
    $saved[basename($f, '.txt')] = ($l === $cur_text);
  }
  $same = array_keys(array_filter($saved));
  // the list loaded last wins when several saved lists are identical
  $last = trim((string)@file_get_contents($kdir . '/.loaded'));
  if ($last !== '' && in_array($last, $same, true)) $same = array($last); ?>
<div class="splist-tools">
  <span><?php echo t('Current list:'); ?></span>
  <b><?php echo $same ? htmlspecialchars($same[0]) : (count($cur_text) ? t('not saved') : t('empty')); ?></b>
  <button type="button" onclick="kindListAction('save')"><?php echo t('Save current list as…'); ?></button>
  <input type="text" id="save_list_name" placeholder="<?php echo t('name'); ?>" style="width:14em">
  <span><?php echo t('Load this list:'); ?></span>
  <select id="kind_lists" onchange="kindListAction('activate')" title="<?php echo t('Choosing a saved list copies it over the current one (asks first)'); ?>">
    <?php echo '<option value="">' . ($saved ? t('(choose)') : t('(no saved list yet)')) . '</option>';
    foreach ($saved as $n => $is) echo '<option value="' . htmlspecialchars($n, ENT_QUOTES) . '">' . htmlspecialchars($n) . ($same && $same[0] === $n ? ' ' . t('(current)') : '') . '</option>'; ?>
  </select>
  <button type="button" onclick="kindListAction('delete')" title="<?php echo t('Deletes the saved copy only; the current list stays'); ?>"><?php echo t('Delete saved'); ?></button>
</div>
<script>
  function kindListAction(action) {
    const fd = new FormData();
    fd.append('action', action);
    fd.append('kind', <?php echo json_encode($species_list); ?>);
    let n;
    if (action === 'save') {
      n = document.getElementById('save_list_name').value.trim();
      if (!/^[A-Za-z0-9_-]+$/.test(n)) { alert(window.t('Name: letters, digits, - and _ only')); return; }
    } else {
      n = document.getElementById('kind_lists').value;
      if (!n) return;
      if (action === 'activate' && !confirm(window.t('Replace the current list with "{name}"? (Save the current one first if you want to keep it.)', {name: n}))) { location.reload(); return; }
      if (action === 'delete' && !confirm(window.t('Delete the saved list "{name}"? The current list stays as it is.', {name: n}))) return;
    }
    fd.append('name', n);
    fetch('scripts/species_lists.php', { method: 'POST', body: fd }).then(r => r.text()).then(t => { alert(t); location.reload(); });
  }
</script>
<?php } ?>
<div class="splist-note splist-source"><?php echo t('Groups (birds, mammals, amphibians, insects, domestic) and regions come from a formal reference: <a href="https://www.gbif.org" target="_blank">GBIF</a> (Global Biodiversity Information Facility).'); ?><br>
  <?php echo t('The GBIF Backbone Taxonomy gives the class of each label, and GBIF occurrence records give the continents (at least 3 % of the records).'); ?><br><br>
  <?php echo t('Global = four or more continents;'); ?><br>
  <?php echo t('Ocean = mostly records at sea.'); ?><br>
  <?php echo t('Table: model/species_info.csv.'); ?></div>
</div>

<script>
    // the selected species travel as one field (newline-separated, POST): hundreds of them used to exceed the address
    // length of the old GET form and only part of a big selection was added or removed (owner 2026-10-09)
    ['add', 'del'].forEach(function (id) {
      document.getElementById(id).addEventListener('submit', function (event) {
        var sel = this.querySelector('select');
        var picked = Array.prototype.filter.call(sel.options, function (o) { return o.selected && !o.hidden && !o.disabled; }).map(function (o) { return o.value; });
        if (!picked.length) { alert(window.t('Please click the species first.')); document.querySelector('.views').style.opacity = 1; event.preventDefault(); return; }
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
    function applyFilters() {
      var qs = {species: document.getElementById('species_searchterm').value.toUpperCase(),
                value2: document.getElementById('list_searchterm').value.toUpperCase()};
      // the group and region filters act on both lists, each list has its own search box
      [['species', 'avail_count'], ['value2', 'list_count']].forEach(function (x) {
        var q = qs[x[0]];
        var shown = 0, total = 0;
        document.querySelectorAll('#' + x[0] + ' option[data-g]').forEach(function (o) {
          total++;
          var reg = o.dataset.r || '';
          var ok = (!groupNow || o.dataset.g === groupNow) && o.value.toUpperCase().indexOf(q) > -1
            && (!regionNow || reg === 'Global' || reg.split('+').indexOf(regionNow) > -1);
          o.hidden = !ok;
          if (!ok) o.selected = false; else shown++;
        });
        document.getElementById(x[1]).textContent = '(' + (shown === total ? total : window.t('{shown} of {total}', {shown: shown, total: total})) + ')';
      });
    }
    function groupFilter(g) {
      groupNow = g;
      document.querySelectorAll('.splist-groups button').forEach(function (b) { b.classList.toggle('active', b.dataset.g === g); });
      try { localStorage.setItem('splist_group', g); } catch (e) {}
      applyFilters();
    }
    document.getElementById('species_searchterm').addEventListener('input', applyFilters);
    document.getElementById('list_searchterm').addEventListener('input', applyFilters);
    try {
      regionNow = localStorage.getItem('splist_region') || '';
      document.getElementById('region_filter').value = regionNow;
      var g = localStorage.getItem('splist_group') || '';
      groupFilter(document.querySelector('.splist-groups button[data-g="' + g + '"]') ? g : '');
    } catch (e) { applyFilters(); }
</script>
