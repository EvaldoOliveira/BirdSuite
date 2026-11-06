<?php
################################################################################
# scripts/data_page.php - DATA › DATA: THE LOCATIONS DATA, REMOVE BY PERIOD, THE LIFE CYCLE OF THE SOUNDS AND THE DANGER ZONE
#
# PURPOSE:
#   The page of Data › Data: one row per location (detections, removed, ignored, first and last day, format)
#   with Delete all, Restore, Purge Removed, Purge Ignored and Move; Remove by period with its preview; the
#   life cycle of the sounds and its settings; the danger zone (scripts/station_backup.php). The jobs run
#   through scripts/location_data.php.
#
# DEPENDENCIES:
#   - scripts/common.php, scripts/location_data.php (ld_rows() and the endpoints), scripts/station_backup.php
#   - static/location-data.js, static/folder-picker.js, static/wipe-deleted.js, static/species-modal.js
#
# USAGE:
#   views.php?view=Data
################################################################################

require_once __DIR__ . '/common.php';
require_once __DIR__ . '/location_data.php';
$ld_mode = 'data';
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

	<?php // 1. the locations: their records, the format of their clips, Move (owner 2026-10-24: Backup and Delete all in the sections below) ?>
	<?php // every section a panel with its title inside, as Settings › Model (owner 2026-10-24: one look in the whole app) ?>
	<table class="settingstable"><tr><td>
	<h2 id="ldformats"><?php echo t('str_locations_data'); ?></h2>
	<table class="stdtable">
		<tr><th><?php echo t('str_location'); ?></th><th><?php echo t('str_detections'); ?></th><th title="<?php echo $h(t('str_removed_detections_out_of_the_help')); ?>"><?php echo t('str_deleted'); ?></th><th title="<?php echo $h(t('str_answered_human_noise_or_ignore_help')); ?>"><?php echo t('str_ignored_col'); ?></th><th><?php echo t('str_first'); ?></th><th><?php echo t('str_last'); ?></th><th><?php echo t('str_format'); ?></th><th></th></tr>
		<?php foreach ($rows as $l) {
			$is_cur = $cur && intval($cur['id']) === intval($l['id']);
			echo '<tr data-id="' . intval($l['id']) . '" data-name="' . $h($l['name']) . '" data-n="' . $l['n'] . '"><td><b>' . $h($l['name']) . '</b> ' . ($is_cur ? '<span class="curbadge">' . t('str_current') . '</span>' : '') . '</td>'
				. '<td class="num">' . fmt_num($l['n']) . '</td><td class="num">' . fmt_num($l['removed']) . '</td><td class="num">' . fmt_num($l['ignored']) . '</td><td>' . $h($l['first']) . '</td><td>' . $h($l['last']) . '</td>'
				. '<td>' . $h($fmt_text($fmts[(string)$l['id']] ?? null, $l['n'])) . '</td><td class="ldacts">'
				// Move to location / to station only with several of them (simple mode, US-125)
				. ($l['n'] ? (multi_location() ? '<button type="button" class="ldbtn" onclick="ldPick(this,\'reassign\')">' . t('str_move_to_location') . '</button>' : '')
					. (multi_station() ? '<button type="button" class="ldbtn" onclick="ldPick(this,\'station\')">' . t('str_move_to_station') . '</button>' : '')
					// every detection of the location to the Removed folder (owner 2026-10-28: lost in the 2026-10-24 reorganisation)
					. '<button type="button" class="ldbtn del" onclick="ldRemove(this)">' . t('str_delete_all') . '</button>' : '')
				// the removed detections back (US-13): all of them or one species, listed under the line
				. '<button type="button" class="ldbtn" onclick="ldRestoreFold(this, \'rs' . intval($l['id']) . '\')"' . ($l['removed'] ? '' : ' disabled title="' . $h(t('str_nothing_to_restore')) . '"') . '>' . t('str_restore') . ' &#9662;</button>'
				// the deleted detections of the location for good (owner 2026-10-24: in place of the section Purge Removed Sounds)
				. '<button type="button" class="ldbtn del" onclick="wipeDeleted(\'\', this, ' . $h(json_encode((string)intval($l['id']))) . ')"' . ($l['removed'] ? '' : ' disabled title="' . $h(t('str_nothing_to_purge')) . '"') . '>' . t('str_purge_removed') . '</button>'
				. '<button type="button" class="ldbtn del" onclick="purgeAudio(' . $h(json_encode((string)intval($l['id']))) . ', this)"' . ($l['ignored'] ? '' : ' disabled title="' . $h(t('str_nothing_to_purge')) . '"') . '>' . t('str_purge_ignored') . '</button>'
				. '</td></tr>';
			if ($l['removed']) {
				$loc = $h(json_encode((string)intval($l['id'])));
				echo '<tr class="rs' . intval($l['id']) . '" style="display:none"><td style="padding-left:22px"><i>' . t('str_all_species') . '</i></td><td></td><td class="num">' . fmt_num($l['removed']) . '</td><td colspan="4"></td>'
					. '<td><button type="button" class="ldbtn" onclick="ldRestore(\'\', this, ' . $loc . ', ' . intval($l['removed']) . ')">' . t('str_restore_all') . '</button></td></tr>';
				foreach ($removed_sp[intval($l['id'])] as $r)
					echo '<tr class="rs' . intval($l['id']) . '" style="display:none"><td style="padding-left:22px">' . $h($r['com']) . ' <small><i>' . $h($r['Sci_Name']) . '</i></small></td><td></td><td class="num">' . fmt_num($r['n']) . '</td><td colspan="4"></td>'
						. '<td><button type="button" class="ldbtn" onclick="ldRestore(' . $h(json_encode($r['Sci_Name'])) . ', this, ' . $loc . ', ' . intval($r['n']) . ')">' . t('str_restore') . '</button></td></tr>';
			}
		}
		// outside every location (run 5 RV2): only Restore and Purge Removed, the species folded under it as for a location
		if ($orphans['n']) {
			$loc = $h(json_encode('none'));
			sort($orphans['ids']);
			echo '<tr><td><b>' . t('str_outside_every_location') . '</b> <small>('
				. $h(implode(', ', array_map(function ($i) { return $i ? '#' . $i : '-'; }, $orphans['ids']))) . ')</small></td>'
				. '<td class="num">' . fmt_num(0) . '</td><td class="num">' . fmt_num($orphans['n']) . '</td><td class="num">' . fmt_num(0) . '</td><td></td><td></td><td></td><td class="ldacts">'
				. '<button type="button" class="ldbtn" onclick="ldRestoreFold(this, \'rsnone\')">' . t('str_restore') . ' &#9662;</button>'
				. '<button type="button" class="ldbtn del" onclick="wipeDeleted(\'\', this, ' . $loc . ')">' . t('str_purge_removed') . '</button></td></tr>';
			echo '<tr class="rsnone" style="display:none"><td style="padding-left:22px"><i>' . t('str_all_species') . '</i></td><td></td><td class="num">' . fmt_num($orphans['n']) . '</td><td colspan="4"></td>'
				. '<td><button type="button" class="ldbtn" onclick="ldRestore(\'\', this, ' . $loc . ', ' . intval($orphans['n']) . ')">' . t('str_restore_all') . '</button></td></tr>';
			foreach ($orphans['sp'] as $r)
				echo '<tr class="rsnone" style="display:none"><td style="padding-left:22px">' . $h($r['com']) . ' <small><i>' . $h($r['Sci_Name']) . '</i></small></td><td></td><td class="num">' . fmt_num($r['n']) . '</td><td colspan="4"></td>'
					. '<td><button type="button" class="ldbtn" onclick="ldRestore(' . $h(json_encode($r['Sci_Name'])) . ', this, ' . $loc . ', ' . intval($r['n']) . ')">' . t('str_restore') . '</button></td></tr>';
		} ?>
	</table>
	<p class="small" style="margin:6px 0 0"><?php echo t('str_deleted_the_detections_you_help'); ?><br>
		<?php echo t('str_ignored_answered_human_noise_or_help'); ?><br>
		<?php echo t('str_delete_all_every_detection_of_help'); ?><br>
		<?php echo t('str_restore_puts_a_detection_back_help'); ?><br>
		<?php if ($orphans['n']) { ?><?php echo t('str_outside_every_location_the_help'); ?><br><?php } ?>
		<?php if (multi_location()) { ?><?php echo t('str_move_to_location_the_detections_help'); ?><br><?php } ?>
		<?php if (multi_station()) { ?><?php echo t('str_move_to_station_a_backup_then_help'); ?><?php } ?></p>
	<p class="small" style="margin:6px 0 0"><b><?php echo t('str_format_colon'); ?></b><br>
		<?php echo t('str_birdsuite_the_database_uses_the_help'); ?><br>
		<?php echo t('str_birdnet_the_database_uses_the_help'); ?></p>
	<div class="box" id="ldpanel" style="display:none">
		<div class="row"><b id="ldtitle"></b></div>
		<div id="ldto" class="row" style="display:none"><?php echo t('str_move_to_location_colon'); ?> <select id="ldtosel"><?php foreach ($rows as $l) echo '<option value="' . intval($l['id']) . '">' . $h($l['name']) . '</option>'; ?></select></div>
		<div id="ldst" class="row" style="display:none"><?php echo t('str_station'); ?> <select id="ldstsel"><?php foreach ($stations as $s) {
			$a = $s['addresses'][0] ?? '';
			if ($a !== '' && strcasecmp($s['name'], get_sitename()) !== 0) echo '<option value="' . $h($a) . '">' . $h($s['name'] . ' (' . $a . ')') . '</option>';
		} ?></select> <span class="small"><?php echo t('str_the_folder_below_must_be_help'); ?></span></div>
		<div id="ldfold" style="display:none"><div class="small"><?php echo t('str_folder'); ?></div><div class="crumbs" id="ldcrumbs"></div><div class="dirs" id="lddirs"></div></div>
		<div class="row"><button type="button" class="ldbtn main" id="ldgo" onclick="ldGo()"><?php echo t('str_start'); ?></button> <button type="button" class="ldbtn" onclick="document.getElementById('ldpanel').style.display='none'"><?php echo t('str_cancel'); ?></button> <b id="ldmsg"></b></div>
	</div>
	</td></tr></table><br>

	<?php // Remove by period (US-13): from date + time to date + time, every species or one; a preview with the counts, then
	// the confirmation; the detections go to the Removed folder (restorable above until Purge Removed) ?>
	<table class="settingstable"><tr><td>
	<h2><?php echo t('str_remove_by_period'); ?></h2>
	<div class="row"><label><?php echo t('str_period_from'); ?> <input type="datetime-local" id="rpfrom" step="60"></label>
		<label><?php echo t('str_period_to'); ?> <input type="datetime-local" id="rpto" step="60"></label>
		<label><?php echo t('str_species'); ?> <select id="rpsci"><option value=""><?php echo t('str_all_species'); ?></option><?php
			foreach ($period_sp as $r) echo '<option value="' . $h($r['Sci_Name']) . '">' . $h($r['com'] . ' (' . $r['Sci_Name'] . ')') . '</option>'; ?></select></label>
		<button type="button" class="ldbtn main" onclick="rpPreview()"><?php echo t('str_preview'); ?></button> <b id="rpmsg"></b></div>
	<div id="rpres"></div>
	<p class="small" style="margin:6px 0 0"><?php echo t('str_remove_by_period_moves_the_help'); ?><br><?php echo t('str_lists_tiers_and_confirmed_help'); ?></p>
	</td></tr></table><br>

	<?php // the life cycle of the sounds (owner 2026-10-24, text B), with this station's own numbers
	$cf = get_config();
	$lc = array('day' => intval($cf['MAX_FILES_SPECIES_DAY'] ?? 50), 'recent' => intval($cf['PURGE_RECENT_DAYS'] ?? 3),
		'total' => intval($cf['MAX_FILES_SPECIES'] ?? 100), 'disk' => intval($cf['PURGE_THRESHOLD'] ?? 70),
		'ignored' => intval($cf['IGNORED_PURGE_DAYS'] ?? 5), 'top' => intval($cf['PURGE_PROTECT_TOP_N'] ?? 50)); ?>
	<table class="settingstable"><tr><td>
	<h2><?php echo t('str_life_cycle_of_the_sounds'); ?></h2>
	<div style="font-weight:700;margin-top:10px"><?php echo t('str_detection_dot'); ?></div>
	<p style="margin-top:2px"><?php echo t('str_each_detection_records_a_sound_help'); ?><br>
		<?php echo t('str_the_record_goes_to_the_database_help'); ?></p>
	<div style="font-weight:700;margin-top:10px"><?php echo t('str_automatic_limits_every_night'); ?></div>
	<p style="margin-top:2px"><?php // 0 = no limit (owner 2026-10-24): the sentence says so
		echo $lc['day'] ? t('str_in_each_day_a_species_keeps_help', array('n' => $lc['day'] . ' (MAX_FILES_SPECIES_DAY)')) : t('str_no_daily_limit_a_species_keeps_help'); ?><br>
		<?php echo ($lc['total'] && $lc['recent']) ? t('str_outside_the_last_days_each_help', array('days' => $lc['recent'] . ' (PURGE_RECENT_DAYS)', 'n' => $lc['total'] . ' (MAX_FILES_SPECIES)'))
			: t('str_no_limit_per_species_every_help', array('var' => $lc['total'] ? 'PURGE_RECENT_DAYS' : 'MAX_FILES_SPECIES')); ?><br>
		<?php echo $lc['disk'] ? t('str_if_the_disk_goes_over_the_help', array('pct' => $lc['disk'] . '% (PURGE_THRESHOLD)')) : t('str_no_disk_limit_nothing_is_help'); ?><br>
		<?php echo t('str_these_limits_delete_only_the_help'); ?></p>
	<div style="font-weight:700;margin-top:10px"><?php echo t('str_ignored_dot'); ?></div>
	<p style="margin-top:2px"><?php echo t('str_the_answers_human_noise_or_help'); ?><br>
		<?php echo $lc['ignored'] ? t('str_the_sound_is_deleted_help', array('days' => $lc['ignored'] . ' (IGNORED_PURGE_DAYS)')) : t('str_the_sound_stays_there_help'); ?></p>
	<div style="font-weight:700;margin-top:10px"><?php echo t('str_removed'); ?></div>
	<p style="margin-top:2px"><?php echo t('str_removing_a_detection_takes_it_help'); ?><br>
		<?php echo t('str_until_purge_removed_it_can_be_help'); ?></p>
	<div style="font-weight:700;margin-top:10px"><?php echo t('str_protected'); ?></div>
	<p style="margin-top:2px"><?php echo $lc['top'] ? t('str_sounds_with_the_lock_confirmed_help', array('n' => $lc['top'] . ' (PURGE_PROTECT_TOP_N)')) : t('str_every_sound_is_protected_no_help'); ?></p>
	<?php // the settings behind the numbers, edited here (owner 2026-10-24: one place; their defaults in Settings › Defaults) ?>
	<table class="stdtable" data-nosort id="disktab" style="margin-top:14px">
		<tr><th><?php echo t('str_setting'); ?></th><th><?php echo t('str_value'); ?></th><th><?php echo t('str_variable'); ?></th></tr>
		<?php // the owner's order (2026-10-24); 0 keeps everything in every one of them
		foreach (array(
			array(t('str_maximum_clips_per_species_per_day'), t('str_in_each_day_a_species_keeps_its_help'), 'MAX_FILES_SPECIES_DAY', $lc['day'], 100000),
			array(t('str_number_of_files_to_keep_for_help'), t('str_outside_the_recent_days_the_n_help'), 'MAX_FILES_SPECIES', $lc['total'], 100000),
			array(t('str_protect_the_clips_of_the_last_help'), t('str_the_limit_per_species_leaves_help'), 'PURGE_RECENT_DAYS', $lc['recent'], 365),
			array(t('str_purge_the_audio_of_ignored_help'), t('str_after_this_the_sound_of_an_help'), 'IGNORED_PURGE_DAYS', $lc['ignored'], 3650),
			array(t('str_always_protect_the_best_help'), t('str_besides_the_locks_yes_and_help'), 'PURGE_PROTECT_TOP_N', $lc['top'], 100)) as $row)
			echo '<tr><td><b>' . $h(rtrim($row[0], ': ')) . '</b><br><small>' . $h($row[1]) . ' ' . $h(t('str_0_keep_everything')) . '</small></td><td><input type="number" name="' . $row[2] . '" value="' . intval($row[3])
				. '" min="0" max="' . $row[4] . '" step="1" style="width:6.5em"></td><td><code>' . $row[2] . '</code></td></tr>'; ?>
		<?php $fdm = in_array($cf['FULL_DISK'] ?? '', array('keep', 'external'), true) ? $cf['FULL_DISK'] : 'purge'; ?>
		<tr><td><b><?php echo t('str_when_the_disk_is_full'); ?></b><br><small><?php echo t('str_purge_deletes_the_oldest_sounds_help'); ?><br><?php echo t('str_external_backup_moves_the_old_help'); ?></small></td>
			<td style="white-space:nowrap"><label><input type="radio" name="FULL_DISK" value="purge" onchange="diskExt()"<?php echo $fdm === 'purge' ? ' checked' : ''; ?>> <?php echo t('str_purge'); ?></label>
				<label><input type="radio" name="FULL_DISK" value="keep" onchange="diskExt()"<?php echo $fdm === 'keep' ? ' checked' : ''; ?>> <?php echo t('str_keep'); ?></label>
				<label><input type="radio" name="FULL_DISK" value="external" onchange="diskExt()"<?php echo $fdm === 'external' ? ' checked' : ''; ?>> <?php echo t('str_external_backup'); ?></label></td><td><code>FULL_DISK</code></td></tr>
		<?php // US-128 (owner 2026-10-29): the destination folder and what happens when it cannot be used, shown with External backup ?>
		<tr class="diskext"<?php echo $fdm === 'external' ? '' : ' style="display:none"'; ?>><td colspan="2"><b><?php echo t('str_destination_folder'); ?></b><br><small><?php echo t('str_the_old_sounds_are_moved_here_help'); ?></small>
			<div id="diskextdirs" data-path="<?php echo $h($cf['FULL_DISK_PATH'] ?? ''); ?>"></div></td><td><code>FULL_DISK_PATH</code></td></tr>
		<tr class="diskext"<?php echo $fdm === 'external' ? '' : ' style="display:none"'; ?>><td><b><?php echo t('str_if_the_backup_is_not_possible'); ?></b><br><small><?php echo t('str_folder_not_mounted_not_writable_help'); ?></small></td>
			<td style="white-space:nowrap"><label><input type="radio" name="FULL_DISK_FALLBACK" value="keep"<?php echo ($cf['FULL_DISK_FALLBACK'] ?? 'keep') !== 'purge' ? ' checked' : ''; ?>> <?php echo t('str_stop_the_recording'); ?></label>
				<label><input type="radio" name="FULL_DISK_FALLBACK" value="purge"<?php echo ($cf['FULL_DISK_FALLBACK'] ?? '') === 'purge' ? ' checked' : ''; ?>> <?php echo t('str_delete_the_oldest'); ?></label></td><td><code>FULL_DISK_FALLBACK</code></td></tr>
		<tr><td><b><?php echo t('str_purge_threshold_disk_used_pct'); ?></b><br><small><?php echo $h(t('str_how_full_the_disk_may_get_help') . ' ' . t('str_0_keep_everything')); ?></small></td>
			<td><input type="number" name="PURGE_THRESHOLD" value="<?php echo intval($lc['disk']); ?>" min="0" max="99" step="1" style="width:6.5em"></td><td><code>PURGE_THRESHOLD</code></td></tr>
	</table>
	<div class="row"><button type="button" class="ldbtn main" onclick="diskSave(this)"><?php echo t('str_save'); ?></button> <b id="diskmsg"></b>
		<button type="button" class="ldbtn" onclick="diskCount(this)"><?php echo t('str_disk_usage_summary'); ?></button></div>
	<pre id="diskcount" style="display:none;max-height:40vh;overflow:auto;background:#111;color:#ddd;border-radius:8px;padding:8px;font-size:12px;white-space:pre-wrap"></pre>
	</td></tr></table><br>
	<?php include __DIR__ . '/station_backup.php'; ?>
</div>
<script src="static/species-modal.js?v=<?php echo @filemtime(__DIR__ . "/../homepage/static/species-modal.js"); ?>"></script>
<script src="static/wipe-deleted.js?v=<?php echo @filemtime(__DIR__ . "/../homepage/static/wipe-deleted.js"); ?>"></script>
<script src="static/folder-picker.js?v=<?php echo @filemtime(__DIR__ . "/../homepage/static/folder-picker.js"); ?>"></script>
<script src="static/location-data.js?v=<?php echo @filemtime(__DIR__ . "/../homepage/static/location-data.js"); ?>"></script>
<script>
ldPoll();
</script>
