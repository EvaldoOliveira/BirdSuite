<?php
################################################################################
# scripts/location_moved.php - THE STATION MOVED?
#
# PURPOSE:
#   The station moved? (owner 2026-10-30: on every page, the start page is no longer Latest.) Included by
#   views.php under the page title; shown while location_check.py says so in location_check.json, answered
#   through POST overview.php?location=.
#
# DEPENDENCIES:
#   - scripts/location_check.py
#
# USAGE:
#   Referenced by homepage/views.php, scripts/overview.php
################################################################################

$config = get_config();
?>
<?php $loc = json_decode((string)@file_get_contents(BS_STATE . '/location_check.json'), true);
if (is_array($loc) && !empty($loc['moved'])) {
	$where = htmlspecialchars(implode(', ', array_filter(array($loc['city'] ?? '', $loc['region'] ?? '', $loc['country'] ?? ''))));
	$list_note = preg_match('/^BR-[A-Z]{2}$/', $config['SPECIES_LIST'] ?? '') ? t('str_the_species_list_of_that_state') : ''; ?>
<div class="locmoved" id="locmoved">&#128205; <?php echo t('str_the_station_network_position_help',
		array('where' => '<b>' . $where . '</b>', 'km' => intval($loc['distance_km']), 'checked' => htmlspecialchars($loc['checked'] ?? ''))); ?>
	<?php if (!multi_location()) { // simple mode (owner 2026-10-29, US-125): the place changed for good, or a new place (several locations on) ?>
	<button type="button" onclick="locAnswer('update')" title="<?php echo htmlspecialchars(t('str_the_current_location_takes_help', array('coords' => round($loc['lat'], 4) . ', ' . round($loc['lon'], 4), 'list' => $list_note, 'tz' => $loc['timezone'] ?? '')), ENT_QUOTES); ?>"><?php echo t('str_update_this_location'); ?></button>
	<button type="button" onclick="locAnswer('use')" title="<?php echo htmlspecialchars(t('str_a_new_location_here_made_help'), ENT_QUOTES); ?>"><?php echo t('str_new_location'); ?></button>
	<?php } else { ?>
	<button type="button" onclick="locAnswer('use')" title="<?php echo t('str_coordinates_and_timezone', array('coords' => round($loc['lat'], 4) . ', ' . round($loc['lon'], 4), 'list' => $list_note, 'tz' => htmlspecialchars($loc['timezone'] ?? ''))); ?>"><?php echo t('str_use_this_location'); ?></button>
	<?php } ?>
	<button type="button" class="keep" onclick="locAnswer('keep')"><?php echo t('str_not_moved'); ?></button>
	<?php if (multi_location() && count(locations_load()) > 1) { ?><span class="locpick"><?php echo t('str_or_it_is_at'); ?> <select id="locmoved_sel"><?php foreach (locations_load() as $l) echo '<option value="' . intval($l['id']) . '">' . htmlspecialchars($l['name']) . '</option>'; ?></select>
		<button type="button" onclick="locAnswer(document.getElementById('locmoved_sel').value)"><?php echo t('str_use'); ?></button></span><?php } ?></div>
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
		else bsNotice(this.status === 401 ? t('str_log_in_first') : this.responseText);
	};
	x.open('POST', 'overview.php?location=' + encodeURIComponent(a), true);
	x.send();
}
</script>
<?php } ?>
<?php
// the clock not confirmed by the network (no internet since the start, e.g. in the field): on every page (owner
// 2026-11-03: it showed on Latest only, and every detection takes this clock), with the device's time in one tap
if (!time_confirmed()) { ?>
<div class="locmoved" id="timewarn">&#9200; <?php echo t('str_the_stations_since_the_help', array('time' => '<b>' . date('Y-m-d H:i') . '</b>')); ?>
	<button type="button" onclick="useDeviceTime()"><?php echo t('str_use_this_devices_time'); ?></button></div>
<script>
function useDeviceTime() {
	var x = new XMLHttpRequest();
	x.onload = function () { var r = {}; try { r = JSON.parse(this.responseText); } catch (e) {} if (r.ok) location.reload(); else bsNotice(this.status === 401 ? t('str_log_in_first') : (r.error || this.responseText)); };
	x.open('POST', 'scripts/network.php?do=time', true); x.setRequestHeader('Content-Type', 'application/json');
	x.send(JSON.stringify({epoch: Math.floor(Date.now() / 1000)}));
}
</script>
<?php } ?>
