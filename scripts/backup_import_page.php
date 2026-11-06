<?php
################################################################################
# scripts/backup_import_page.php - DATA › BACKUP/IMPORT: EXPORT / BACKUP, IMPORT AND THE SETTINGS ARCHIVE
#
# PURPOSE:
#   The page of Data › Backup/Import: Export / Backup of a location or all (BirdSuite or BirdNET format,
#   incremental), Import of a backup or a BirdNET-Pi folder (peek first), the settings archive (backup.php /
#   restore.php) and the archives kept on the station. The jobs run through scripts/location_data.php.
#
# DEPENDENCIES:
#   - scripts/common.php, scripts/location_data.php (ld_rows() and the endpoints), scripts/station_backup.php
#   - static/location-data.js, static/folder-picker.js, static/wipe-deleted.js, static/species-modal.js
#
# USAGE:
#   views.php?view=Backup%2FImport
################################################################################

require_once __DIR__ . '/common.php';
require_once __DIR__ . '/location_data.php';
$ld_mode = 'backup';
extract(ld_rows($ld_mode));
?>
<style>
.ld { max-width: 1000px; margin: 0; text-align: left; padding: 0 12px; }
.ld .box { background: var(--panel, #f4f6f8); border-radius: 10px; padding: 10px 14px; margin: 10px 0; }
.ld table.stdtable td.num { text-align: right; }
.ld .ldbtn { width: auto; padding: 3px 10px; margin: 2px; border-radius: 12px; border: 1px solid var(--accent,#2b5e22); background: #fff; color: var(--accent,#2b5e22); font-weight: 600; cursor: pointer; font-size: .85em; }
.ld a.ldbtn { display: inline-block; text-decoration: none; line-height: 1.3; }
.ld .ldbtn:hover { background: var(--accent,#2b5e22); color: #fff; }
.ld .ldbtn.del { border-color: #c62828; color: #c62828; }
.ld .ldbtn.del:hover { background: #c62828; color: #fff; }
.ld .ldbtn:disabled { opacity: .4; cursor: default; background: #fff; color: var(--accent,#2b5e22); }
.ld .ldbtn.del:disabled { color: #c62828; }
.ld .ldbtn.main { background: var(--accent,#2b5e22); color: #fff; font-size: 1em; padding: 5px 14px; }
.ld .curbadge { display: inline-block; padding: 1px 8px; border-radius: 10px; background: var(--accent,#2b5e22); color: #fff; font-size: .75em; }
.ld .crumbs { font-family: monospace; font-size: 13px; margin: 6px 0; word-break: break-all; }
.ld .crumbs a { cursor: pointer; color: var(--accent, #1f4e79); }
.ld .dirs { display: flex; flex-wrap: wrap; gap: 6px; max-height: 200px; overflow: auto; }
.ld .dirs button { width: auto; padding: 4px 10px; border-radius: 8px; border: 1px solid #ccc; background: #fff; cursor: pointer; font-size: 13px; }
.ld .bar { height: 10px; background: #ddd; border-radius: 5px; overflow: hidden; margin: 6px 0; }
.ld .bar i { display: block; height: 100%; background: var(--accent,#2b5e22); width: 0; }
.ld .small { font-size: 12px; opacity: .8; }
.ld .row { display: flex; flex-wrap: wrap; gap: 10px; align-items: center; margin: 6px 0; }
/* a phone: a table too wide scrolls inside its box, not the page (2026-10-23 test run) */
/* the actions of a location wrap two or three to a line (each label on one line: the global button rule of style.css; owner 2026-11-03: on a phone the
	 narrow cell broke every label word by word and stretched the row to a whole screen) */
.ld td.ldacts { min-width: 19em; }
@media (max-width: 600px) { .ld table.stdtable { display: block; overflow-x: auto; max-width: 100%; } }
/* the life cycle settings on a phone (owner 2026-10-25: three narrow columns): one block per setting, its title and
	 note across the width, the value and the variable on one line under them */
@media (max-width: 700px) {
	.ld table#disktab, .ld #disktab tbody, .ld #disktab tr { display: block; width: 100%; overflow: visible; }
	.ld #disktab tr:first-child { display: none; }
	.ld #disktab tr { padding: 8px 0; border-bottom: 1px solid rgba(0,0,0,.12); }
	.ld #disktab td { display: block; border: 0; padding: 2px 0; background: none !important; color: inherit !important; }
	.ld #disktab td:nth-child(2), .ld #disktab td:nth-child(3) { display: inline-block; vertical-align: middle; margin-right: 10px; }
}
/* the folder trees are drawn after the page (folder-picker.js): their place is kept from the start, so the sections
	 below never move when they appear (release run 5, PERF-56: 0.34 layout shift) */
.ld #bxdirs, .ld #rsdirs { min-height: 440px; }
@media (max-width: 600px) { .ld #bxdirs, .ld #rsdirs { min-height: 490px; } }
</style>
<div class="ld">
	<div class="box" id="ldjob" style="display:none"><div id="ldjobtxt"></div><div class="bar"><i id="ldbar"></i></div>
		<div class="row" id="ldctl" style="display:none"><button type="button" class="ldbtn" id="ldpause" onclick="ldCtl('pause')"><?php echo t('str_pause'); ?></button>
			<button type="button" class="ldbtn" id="ldcont" onclick="ldCtl('continue')" style="display:none"><?php echo t('str_continue'); ?></button>
			<button type="button" class="ldbtn" id="ldcancel" onclick="bsAsk(t('str_cancel_what_this_job_did_so_far_help'), true).then(function (ok) { if (ok) ldCtl('cancel'); })" style="border-color:#c62828;color:#c62828"><?php echo t('str_cancel'); ?></button>
			<span class="small"><?php echo t('str_pause_holds_the_job_and_frees_help'); ?></span></div></div>

	<?php // 2. Export / Backup (owner 2026-10-24): the location and the format ?>
	<table class="settingstable"><tr><td>
	<h2><?php echo t('str_export_backup'); ?></h2>
		<div class="row"><?php echo t('str_location'); ?> <select id="bxloc" aria-label="<?php echo htmlspecialchars(t('str_location'), ENT_QUOTES); ?>"><option value="0" data-n="<?php echo array_sum(array_column($rows, 'n')); ?>"><?php echo t('str_all_locations'); ?></option><?php
			foreach ($rows as $l) if ($l['n']) echo '<option value="' . intval($l['id']) . '" data-n="' . $l['n'] . '">' . $h($l['name']) . '</option>'; ?></select>
			<?php echo t('str_format'); ?> <select id="bxfmt" aria-label="<?php echo htmlspecialchars(t('str_format'), ENT_QUOTES); ?>" onchange="document.getElementById('bxlangrow').style.display = this.value === 'birdnet' ? '' : 'none'; document.getElementById('bxincrow').style.display = this.value === 'birdnet' ? 'none' : ''">
				<option value="birdsuite"><?php echo t('str_birdsuite_scientific_default'); ?></option>
				<option value="birdnet"><?php echo t('str_birdnet_common_names'); ?></option></select>
			<span id="bxlangrow" style="display:none"><?php echo t('str_common_names_in'); ?> <select id="bxlang"><?php
				$db_lang = get_config()['DATABASE_LANG'] ?? 'en';
				foreach (common_name_languages() as $code => $name) {
					if (is_file(BS_MODEL . "/l18n/labels_$code.json")) echo '<option value="' . $h($code) . '"' . ($code === $db_lang ? ' selected' : '') . '>' . $h(t($name)) . '</option>';
				} ?></select></span></div>
		<div class="row small"><?php echo t('str_choose_the_destination_folder'); ?></div>
		<?php // incremental backup (owner 2026-10-29), BirdSuite format only ?>
		<div class="row" id="bxincrow"><label><input type="checkbox" id="bxinc"> <?php echo t('str_incremental_only_what_is_new_help'); ?></label></div>
		<div class="crumbs" id="bxcrumbs"></div><div class="dirs" id="bxdirs"></div>
		<div class="row"><button type="button" class="ldbtn main" onclick="bxGo()"><?php echo t('str_start'); ?></button> <b id="bxmsg"></b></div>
	</td></tr></table><br>

	<?php // 3. Import (formerly Restore a backup, owner 2026-10-24): BirdSuite or BirdNET, brought into the BirdSuite format ?>
	<table class="settingstable"><tr><td>
	<h2><?php echo t('str_import'); ?></h2>
		<div class="row small"><?php echo t('str_select_the_folder_that_holds_help'); ?><br>
			<?php echo t('str_a_backup'); ?></div>
		<div class="crumbs" id="rscrumbs"></div><div class="dirs" id="rsdirs"></div>
		<div class="row" id="rsinfo"></div>
		<div class="row"><label for="rsto" style="font-weight:normal"><?php echo t('str_into'); ?></label> <select id="rsto"><?php
			// Test / Playback, or new locations (owner 2026-10-24): checked there first, then filed under the real one in Move Data
			foreach ($rows as $l) if (($l['builtin'] ?? '') === 'test') echo '<option value="' . intval($l['id']) . '" selected>' . $h($l['name']) . '</option>'; ?>
			<option value="new"><?php echo t('str_new_location_one_per_location_help'); ?></option>
			<?php // an existing location too, below New location (owner 2026-10-24)
			foreach ($rows as $l) if (($l['builtin'] ?? '') !== 'test') echo '<option value="' . intval($l['id']) . '">' . $h($l['name']) . '</option>'; ?></select>
			<button type="button" class="ldbtn main" id="rsgo" onclick="rsGo()" disabled><?php echo t('str_import'); ?></button> <b id="rsmsg"></b></div>
		<div class="row small"><?php echo t('str_best_into_test_playback_or_a_help'); ?><br>
			<?php echo t('str_a_backup_of_several_locations_help'); ?></div>
		<?php // recordings made elsewhere (not a backup): analysed with the station's model, optionally imported (US-15) ?>
		<div class="row small"><?php echo t('str_recordings_made_elsewhere_help'); ?> <a class="ldbtn" href="views.php?view=External%20files"><?php echo t('str_external_files'); ?></a></div>
	</td></tr></table><br>
	<?php include __DIR__ . '/station_backup.php'; ?>
</div>
<script src="static/species-modal.js?v=<?php echo @filemtime(__DIR__ . "/../homepage/static/species-modal.js"); ?>"></script>
<script src="static/wipe-deleted.js?v=<?php echo @filemtime(__DIR__ . "/../homepage/static/wipe-deleted.js"); ?>"></script>
<script src="static/folder-picker.js?v=<?php echo @filemtime(__DIR__ . "/../homepage/static/folder-picker.js"); ?>"></script>
<script src="static/location-data.js?v=<?php echo @filemtime(__DIR__ . "/../homepage/static/location-data.js"); ?>"></script>
<script>
if (document.getElementById('bxcrumbs')) ldBrowse(<?php echo json_encode(BS_RECS); ?>, 'bxcrumbs', 'bxdirs', function (p) { bxFolder = p; });
if (document.getElementById('rscrumbs')) ldBrowse(<?php echo json_encode(get_home()); ?>, 'rscrumbs', 'rsdirs', function (p) {
	rsFolder = p;
	document.getElementById('rsgo').disabled = true;
	document.getElementById('rsinfo').textContent = '';
	if (!p) return;
	ldGetJ('scripts/location_data.php?peek=' + encodeURIComponent(p), function (m) {
		if (!m || m.error) return;
		var fmt = m.birdnet === 0 ? t('str_scientific_birdsuite') : t('str_birdnet', {lang: esc(m.lang_name || t('str_unknown_language'))});
		if (m.birdnet > 0 && m.birdsuite > 0) fmt = t('str_scientific_birdsuite_plus_birdnet', {n: window.fmtNum(m.birdnet), lang: esc(m.lang_name || t('str_unknown_language'))});
		document.getElementById('rsinfo').innerHTML = (m.kind === 'birdnet'
			? t('str_birdnet_folder_detections', {name: '<b>' + esc(m.station) + '</b>', count: window.fmtNum(m.detections || 0)})
			: t('str_backup_of_from_station_detections', {name: esc(m.location), station: esc(m.station), date: esc(m.exported), count: window.fmtNum((m.detections || 0))}))
			+ ' — ' + t('str_format') + ': <b>' + fmt + '</b>' + (m.birdnet > 0 ? ' <span class="small">(' + t('str_converted_to_scientific_help') + ')</span>' : '');
		document.getElementById('rsgo').disabled = false;
	});
});
ldPoll();
</script>
