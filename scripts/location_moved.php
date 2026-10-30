<?php
/* The station moved? (owner 2026-10-30: on every page, the start page is no longer Latest.) Included by views.php under
 * the page title; shown while location_check.py says so in location_check.json, answered through overview.php?location=. */
$config = get_config();
?>
<?php $loc = json_decode((string)@file_get_contents(BS_STATE . '/location_check.json'), true);
if (is_array($loc) && !empty($loc['moved'])) {
  $where = htmlspecialchars(implode(', ', array_filter(array($loc['city'] ?? '', $loc['region'] ?? '', $loc['country'] ?? ''))));
  $list_note = preg_match('/^BR-[A-Z]{2}$/', $config['SPECIES_LIST'] ?? '') ? t(', the species list of that state') : ''; ?>
<div class="locmoved" id="locmoved">&#128205; <?php echo t('The station seems to be in {where}: about {km} km from its configured location (network position at boot, {checked}).',
    array('where' => '<b>' . $where . '</b>', 'km' => intval($loc['distance_km']), 'checked' => htmlspecialchars($loc['checked'] ?? ''))); ?>
  <?php if (!multi_location()) { // simple mode (owner 2026-10-29, US-125): the place changed for good, or a new place (several locations on) ?>
  <button type="button" onclick="locAnswer('update')" title="<?php echo htmlspecialchars(t('The current location takes coordinates {coords}{list} and timezone {tz}; it stays the only location', array('coords' => round($loc['lat'], 4) . ', ' . round($loc['lon'], 4), 'list' => $list_note, 'tz' => $loc['timezone'] ?? '')), ENT_QUOTES); ?>"><?php echo t('Update this location'); ?></button>
  <button type="button" onclick="locAnswer('use')" title="<?php echo htmlspecialchars(t('A new location here, made current; several locations are switched on, so both show and you can go back'), ENT_QUOTES); ?>"><?php echo t('New location'); ?></button>
  <?php } else { ?>
  <button type="button" onclick="locAnswer('use')" title="<?php echo t('Coordinates {coords}{list} and timezone {tz}', array('coords' => round($loc['lat'], 4) . ', ' . round($loc['lon'], 4), 'list' => $list_note, 'tz' => htmlspecialchars($loc['timezone'] ?? ''))); ?>"><?php echo t('Use this location'); ?></button>
  <?php } ?>
  <button type="button" class="keep" onclick="locAnswer('keep')"><?php echo t('Not moved'); ?></button>
  <?php if (multi_location() && count(locations_load()) > 1) { ?><span class="locpick"><?php echo t('or it is at'); ?> <select id="locmoved_sel"><?php foreach (locations_load() as $l) echo '<option value="' . intval($l['id']) . '">' . htmlspecialchars($l['name']) . '</option>'; ?></select>
    <button type="button" onclick="locAnswer(document.getElementById('locmoved_sel').value)"><?php echo t('Use'); ?></button></span><?php } ?></div>
<script>
function locAnswer(a) {
  var x = new XMLHttpRequest();
  x.onload = function () {
    var r = this.responseText.trim();
    if (r.indexOf('ID:') === 0) {
      var id = r.slice(3).split(':')[0], name = r.slice(4 + id.length);
      document.getElementById('locmoved').remove();
      (window.locationSwitch || window.top.locationSwitch)(parseInt(r.slice(3), 10), name);
    } else if (r === 'OK') document.getElementById('locmoved').remove();
    else alert(this.status === 401 ? t('Log in first.') : this.responseText);
  };
  x.open('GET', 'overview.php?location=' + a, true);
  x.send();
}
</script>
<?php } ?>
