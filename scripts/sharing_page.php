<?php
################################################################################
# scripts/sharing_page.php - STATION › SHARING: BIRDWEATHER AND BIRDDB-BR
#
# PURPOSE:
#   The page of Station › Sharing: the BirdWeather upload (one token, or one per location) and, when
#   activated, the BirdDB-Br sound repository (rclone remote and interval). Its own form and keys only (owner
#   2026-11-06, formerly a section of the one settings form); the uploads read the settings by themselves,
#   nothing restarts.
#
# DEPENDENCIES:
#   - scripts/common.php (lib/settings.php helpers, lib/locations.php for the per-location tokens)
#
# USAGE:
#   views.php?view=Sharing; POST submit=settings (the form)
################################################################################

error_reporting(E_ERROR);
ini_set('display_errors', 0);  // errors to the log, never to the page (2026-10-23 security run)
define('__ROOT__', dirname(dirname(__FILE__)));
require_once(__ROOT__.'/scripts/common.php');

$user = get_user();
$home = get_home();
$config = get_config();
set_timezone();

ensure_authenticated();

// changes arrive by POST (2026-10-23 test run: sent by GET, the tokens and the notification URLs landed in the web
// server log and the browser history); the handler below reads $_GET, so the posted fields join it, and a GET keeps
// only what chooses the page (view, the "updated" alert)
settings_post_merge(array('view', 'status'));
// the BirdDB-Br section of Sharing: Settings › Defaults › Sharing › ACTIVATE_BIRDDB (owner 2026-10-27: false by default
// until the upload is decided); a station that contributes (BIRDDB_ENABLED=1) always shows it, so it can be turned off
if (!defined('BIRDDB_SECTION_SHOWN')) define('BIRDDB_SECTION_SHOWN', trim((string)($config['ACTIVATE_BIRDDB'] ?? 'false'), "\"' ") === 'true'
	|| trim((string)($config['BIRDDB_ENABLED'] ?? '0'), "\"' ") === '1');


$cfg_error = '';
if(($_GET['submit'] ?? '') === 'settings') try {
	$birdweather_id = trim((string)($_GET["birdweather_id"] ?? ($config['BIRDWEATHER_ID'] ?? '')));
	// BirdWeather upload switch (the token is kept when it is off)
	$birdweather_enabled = isset($_GET['birdweather_enabled']) ? 1 : 0;
	settings_ntp_on();
	// every key through conf_set_many() (common.php): each value checked against its rule, all or nothing written
	$set = array();
	$set['BIRDWEATHER_ID'] = $birdweather_id;
	$set['BIRDWEATHER_ENABLED'] = $birdweather_enabled;
	// BirdWeather: one token for every location, or a token for each location (owner 2026-10-22: a BirdWeather station
	// has fixed coordinates); the tokens of the locations are kept in config/locations/locations.json ('birdweather_id')
	$set['BIRDWEATHER_PER_LOCATION'] = (($_GET['birdweather_mode'] ?? '') === 'location') ? 1 : 0;
	// BirdDB-Br switch (default off), only when the form shows its section (a form without it keeps the setting)
	if (isset($_GET['birddb_shown'])) $set['BIRDDB_ENABLED'] = isset($_GET['birddb_enabled']) ? 1 : 0;
	// US-40: station-side transport to the central sound repository (rclone remote + upload interval)
	if (isset($_GET['sound_repo_upload_minutes'])) {
		$set['SOUND_REPO_UPLOAD_MINUTES'] = is_numeric($_GET['sound_repo_upload_minutes']) ? max(0, min(1440, intval($_GET['sound_repo_upload_minutes']))) : 0;
		$set['SOUND_REPO_REMOTE'] = isset($_GET['sound_repo_remote']) ? trim($_GET['sound_repo_remote']) : '';
	}
	// a per-location BirdWeather token outside the rule refuses the save with the reason (Gitea #98)
	$bw_loc = isset($_GET['bw_loc']) && is_array($_GET['bw_loc']) ? $_GET['bw_loc'] : array();
	foreach ($bw_loc as $bw_t) {
		$bw_t = trim((string)$bw_t);
		if ($bw_t !== '' && !preg_match('/^[A-Za-z0-9_-]{4,64}$/', $bw_t)) throw new RuntimeException(rtrim(t('str_birdweather_token'), ': ') . ': ' . t('str_4_to_64_letters_digits_or'));
	}
	// nothing changed (the values and the locations' tokens): nothing written, the page says so (Gitea #88)
	$dirty = settings_dirty($set, $config);
	if (!$dirty) foreach (locations_load() as $bw_l) {
		if (array_key_exists((string)$bw_l['id'], $bw_loc) && trim((string)$bw_loc[(string)$bw_l['id']]) !== (string)($bw_l['birdweather_id'] ?? '')) { $dirty = true; break; }
	}
	if (!$dirty) settings_redirect('Sharing', 'nochange');
	if (!conf_set_many($set, $err)) throw new RuntimeException((string)$err);
	if ($bw_loc) {
		$bw_list = locations_load();
		foreach ($bw_list as &$bw_l) {
			if (!array_key_exists((string)$bw_l['id'], $bw_loc)) continue;
			$bw_l['birdweather_id'] = trim((string)$bw_loc[(string)$bw_l['id']]);
		}
		unset($bw_l);
		locations_save($bw_list);
	}
	if (file_exists(BS_FIRSTRUN)) { @unlink(BS_FIRSTRUN); }
	// the uploads read the settings again by themselves (utils/reporting.py): no service restarts here (US-18)
	settings_redirect('Sharing', 'success');
} catch (RuntimeException $e) {
	$cfg_error = $e->getMessage();
}

// the settings again after a save, so the page shows them
$config = get_config($force_reload=true);
?>
<div class="settings">
		<form id="basicform" action=""  method="POST">

			<table class="settingstable"><tr><td>
			<h2>BirdWeather</h2>
			<label for="birdweather_enabled"><?php echo t('str_upload_to_birdweather'); ?> </label>
			<?php $bwe = ($config['BIRDWEATHER_ENABLED'] ?? '0') != '0'; ?>
			<input type="checkbox" name="birdweather_enabled" id="birdweather_enabled" <?php echo $bwe ? 'checked' : ''; ?> onchange="birdweatherToggle(this.checked)">
<br>
			<?php // off = the rest of the section greyed, as BirdDB-Br (owner 2026-10-21); the token is read-only, not disabled, so
			// the form still sends it and it is kept ?>
			<div id="bw_box" style="margin-top:1em;<?php echo $bwe ? '' : 'opacity:0.7;'; ?>"><?php // a blank line between the switch and the token (owner 2026-10-22) ?>
			<?php // one token, or one per location (owner 2026-10-22): a BirdWeather station has its own coordinates, so a station
				// that moves between places uploads each place to its own BirdWeather station; Test / Playback never uploads
				$bw_pl = ($config['BIRDWEATHER_PER_LOCATION'] ?? '0') === '1'; ?>
			<label style="font-weight:normal"><input type="radio" name="birdweather_mode" value="one" <?php echo $bw_pl ? '' : 'checked'; ?> onchange="bwMode()"> <?php echo t('str_one_token_for_every_location'); ?></label><br>
			<label style="font-weight:normal"><input type="radio" name="birdweather_mode" value="location" <?php echo $bw_pl ? 'checked' : ''; ?> onchange="bwMode()"> <?php echo t('str_a_token_for_each_location'); ?></label>
			<div id="bw_one" style="margin-top:8px<?php echo $bw_pl ? ';display:none' : ''; ?>">
			<label for="birdweather_id"><?php echo t('str_birdweather_token'); ?> </label>
			<?php // the same rule as the per-location tokens (Gitea #98): 4 to 64 letters, digits, _ or - ?>
			<input name="birdweather_id" id="birdweather_id" type="text" value="<?php echo htmlspecialchars((string)$config['BIRDWEATHER_ID'], ENT_QUOTES); ?>" pattern="[A-Za-z0-9_\-]{4,64}" maxlength="64" title="<?php echo htmlspecialchars(t('str_4_to_64_letters_digits_or'), ENT_QUOTES); ?>" <?php echo $bwe ? '' : 'readonly'; ?>/>
			</div>
			<div id="bw_loc" style="margin-top:8px<?php echo $bw_pl ? '' : ';display:none'; ?>">
			<table class="stdtable" style="width:auto"><tr><th><?php echo t('str_location'); ?></th><th data-nosort><?php echo t('str_birdweather_token'); ?></th></tr>
			<?php foreach (locations_load() as $bw_l) {
				echo '<tr><td>' . htmlspecialchars($bw_l['name']) . '</td><td>'
					. (($bw_l['builtin'] ?? '') === 'test' ? '<small>' . t('str_never_uploads_tests_and_playback') . '</small>'
						: '<input type="text" class="bwtok" name="bw_loc[' . intval($bw_l['id']) . ']" value="' . htmlspecialchars($bw_l['birdweather_id'] ?? '') . '" placeholder="' . htmlspecialchars(t('str_empty_this_location_does_not_upload')) . '" pattern="[A-Za-z0-9_\\-]{4,64}" title="' . htmlspecialchars(t('str_4_to_64_letters_digits_or')) . '" style="width:22em"' . ($bwe ? '' : ' readonly') . '>')
					. '</td></tr>';
			} ?>
			</table>
			<small><?php echo t('str_each_location_uploads_with_its_help'); ?></small>
			</div><br>
					 <p><?php echo t('str_birdweather_com_is_a_weather_help'); ?>
				<?php echo t('str_stations_around_the_world_help'); ?>
				<?php echo t('str_birdweather_catalogues_the_bird_help'); ?> <br><br>
				<?php echo t('str_to_request_a_birdweather_token_help'); ?> <a href="https://app.birdweather.com/login" target="_blank">https://app.birdweather.com/</a><br>
				<?php echo t('str_once_thats_done_you_can_go_to_help'); ?> <a href="https://app.birdweather.com/account/stations" target="_blank">https://app.birdweather.com/account/stations</a><br>
				<?php echo t('str_make_sure_that_the_latitude_and_help'); ?>
				<br><br>
				<span style="display:block"><?php echo t('str_note_by_using_your_birdweather_help'); ?></span></p>
			</div>
			<script>
				function bwMode() {
					var loc = document.querySelector('input[name="birdweather_mode"][value="location"]').checked;
					document.getElementById('bw_one').style.display = loc ? 'none' : '';
					document.getElementById('bw_loc').style.display = loc ? '' : 'none';
				}
				function birdweatherToggle(on) {
					document.getElementById('bw_box').style.opacity = on ? '' : '0.7';
					document.getElementById('birdweather_id').readOnly = !on;
					document.querySelectorAll('.bwtok').forEach(function (i) { i.readOnly = !on; });
				}
			</script>
			</td></tr></table><br>
			<?php // BirdDB-Br hidden (owner 2026-10-27) until the upload without an account per station is decided: the code stays,
						// the destination will come from birdbr-upload.txt in the birdsuite-assets repository
			if (BIRDDB_SECTION_SHOWN) { ?>
			<table class="settingstable" style="width:100%"><tr><td>
			<h2>BirdDB-Br</h2>
			<input type="hidden" name="birddb_shown" value="1">
			<?php $bde = (string)($config['BIRDDB_ENABLED'] ?? '0') === '1'; ?>
			<label><input type="checkbox" name="birddb_enabled" id="birddb_enabled" value="1" <?php echo $bde ? 'checked' : ''; ?>
				onchange="birddbToggle(this.checked)"> <?php echo t('str_contribute_to_birddb_br_central_help'); ?></label>
			<small><?php echo t('str_off_default_no_clip_is_copied_help'); ?></small><br>
			<div id="birddb_box" style="margin-top:1em;<?php echo $bde ? '' : 'opacity:0.7;'; ?>"><?php // a paragraph before rclone (owner 2026-10-21) ?>
			<?php $srm = $config['SOUND_REPO_REMOTE'] ?? ''; $sru = $config['SOUND_REPO_UPLOAD_MINUTES'] ?? '0'; ?>
			<table class="settingstable plaintable">
				<tr>
					<td><label for="sound_repo_remote"><?php echo t('str_rclone_remote_remote_path'); ?></label></td>
					<td><input name="sound_repo_remote" class="birddb_field" type="text" style="width:14em;" value="<?php print(htmlspecialchars($srm, ENT_QUOTES));?>" pattern="[A-Za-z0-9_\-]+:.*" placeholder="birddb:" <?php echo $bde ? '' : 'disabled'; ?>/></td>
					<td>(<?php echo t('str_empty_no_upload_deposits_stay_help'); ?>)</td>
				</tr>
				<tr>
					<td><label for="sound_repo_upload_minutes"><?php echo t('str_upload_every'); ?></label></td>
					<td><input name="sound_repo_upload_minutes" class="birddb_field" type="number" style="width:5em;" min="0" max="1440" step="1" value="<?php print(intval($sru));?>" <?php echo $bde ? '' : 'disabled'; ?>/> <?php echo t('str_minutes'); ?></td>
					<td>(<?php echo t('str_0_never_the_spool_is_moved_to_help'); ?>)</td>
				</tr>
			</table>
			</div>
			<script>
				function birddbToggle(on) {
					document.getElementById('birddb_box').style.opacity = on ? '' : '0.7';
					document.querySelectorAll('.birddb_field').forEach(function (f) { f.disabled = !on; });
				}
			</script>
			</td></tr></table><br>
			<?php } ?>

			<input type="hidden" name="status" value="success">
			<input type="hidden" name="submit" value="settings">
<div class="float">
			<button type="submit" id="basicformsubmit" onclick="if(document.getElementById('basicform').checkValidity()){this.innerHTML = t('str_updating_please_wait');this.classList.add('disabled')}" name="view" value="Sharing"><?php echo t('str_update_settings'); ?></button></div>
<?php settings_notice($cfg_error); ?>
			</form>
</div>
