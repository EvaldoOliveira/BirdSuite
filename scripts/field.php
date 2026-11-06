<?php
################################################################################
# scripts/field.php - THE FIELD PAGE
#
# PURPOSE:
#   The Field page (US-57, owner 2026-11-04): the station's state for a phone in the field, in large text,
#   reachable over the station WiFi without internet: recording, the last detection, the disk, the power, the
#   clock and how the phone is connected; Start / Stop recording, Raw recording, Shut down safely. Reads the
#   field service's state (/run/birdsuite/field_state.json) and the database; refreshes itself every 30 s. The
#   buttons go through the station's own commands (views.php ?submit=, which asks for the login). Included
#   from views.php.
#
# DEPENDENCIES:
#   - scripts/common.php
#
# USAGE:
#   views.php?view=Field
#   Referenced by homepage/views.php
################################################################################

require_once __DIR__ . '/common.php';
$h = function ($s) { return htmlspecialchars((string)$s, ENT_QUOTES); };
$st = json_decode((string)@file_get_contents('/run/birdsuite/field_state.json'), true) ?: array();
exec('systemctl is-active --quiet ' . escapeshellarg(BS_SVC_RECORDING . '.service'), $o, $rc);
$recording = $rc === 0;
$last = null;
// the name looked up for the one row shown (names are presentation only, owner 2026-11-05)
try {
	$x = get_db()->querySingle("SELECT Sci_Name, Date || ' ' || Time AS t FROM detections ORDER BY Date DESC, Time DESC LIMIT 1", true);
	if ($x) $last = common_name($x['Sci_Name']) . '|' . $x['t'];
} catch (Throwable $e) {}
$free = @disk_free_space(BS_RECS); $total = @disk_total_space(BS_RECS);
$used = $total ? round(100 * (1 - $free / $total)) : null;
$thr = trim((string)shell_exec('sudo -n ' . BS_ROOT_HELPER . ' throttled 2>/dev/null'));
$power = t('str_no_under_voltage_or_throttling');
if (preg_match('/0x([0-9a-f]+)/i', $thr, $m)) {
	$bits = hexdec($m[1]);
	if ($bits & 1) $power = t('str_under_voltage_now');
	elseif (($bits >> 16) & 1) $power = t('str_under_voltage_since_boot');
}
$temp = @file_get_contents('/sys/class/thermal/thermal_zone0/temp');
$conn = array();
foreach ((array)($st['active'] ?? array()) as $a) if (($a['name'] ?? '') !== '') $conn[] = $a['name'];
$ap = !empty($st['ap_on']);
$cmd = function ($c) { return 'views.php?submit=' . rawurlencode($c); };
$rec_on = 'sudo -n ' . BS_SVC_HELPER . ' enable-now ' . BS_SVC_RECORDING . '.service';
$rec_off = 'sudo -n ' . BS_SVC_HELPER . ' disable-now ' . BS_SVC_RECORDING . '.service';
$halt = 'sudo -n ' . BS_ROOT_HELPER . ' shutdown';
$row = function ($label, $value, $bad = false) use ($h) {
	return '<div class="frow"><span class="fl">' . $h($label) . '</span><span class="fv' . ($bad ? ' badchip' : '') . '">' . $value . '</span></div>';
};
?>
<style>
	.field { max-width: 36em; margin: 0 auto; padding: 0 12px; font-size: 18px; }
	.field .frow { display: flex; justify-content: space-between; gap: 12px; padding: 10px 0; border-bottom: 1px solid rgba(0,0,0,.12); }
	.field .fl { opacity: .75; } .field .fv { font-weight: 700; text-align: right; }
	/* a bad value in the shared red chip (style.css .badchip, release run 5: red text was 2.5:1 on Ocean, 3.1:1 in Dark) */
	.field .fbtns { display: flex; flex-wrap: wrap; gap: 10px; margin: 16px 0; }
	.field .fbtns a, .field .fbtns button { font-size: 17px; padding: 10px 16px; border-radius: 14px; border: 1px solid var(--accent, #2b5e22); background: var(--accent, #2b5e22); color: #fff; text-decoration: none; cursor: pointer; }
	.field .fbtns .sec { background: transparent; color: inherit; }
</style>
<div class="field">
<?php
echo $row(t('str_recording'), $recording ? t('str_yes') : t('str_no'), !$recording);
echo $row(t('str_last_detection'), $last ? $h(str_replace('|', ' · ', $last)) : '-');
echo $row(t('str_disk'), $used === null ? '-' : $h(t('str_pct_used_gb_free', array('pct' => $used, 'gb' => round($free / 1073741824)))), $used !== null && $used >= 90);
echo $row(t('str_power'), $h($power), strpos($power, t('str_under_voltage_now')) === 0);
if ($temp !== false) echo $row(t('str_cpu_temperature'), $h(round(intval($temp) / 1000) . ' °C'), intval($temp) >= 80000);
echo $row(t('str_clock'), $h(date('Y-m-d H:i')) . ' ' . (time_confirmed() ? '&#10003;' : '<span class="badchip">' . $h(t('str_not_confirmed')) . '</span>'));
echo $row(t('str_internet'), !empty($st['online']) ? t('str_yes') : t('str_no'));
echo $row(t('str_connected_through'), $h(($ap ? t('str_station_wifi_n_phones', array('n' => intval($st['ap_clients'] ?? 0))) : '') . ($ap && $conn ? ', ' : '') . implode(', ', $conn)) ?: '-');
?>
	<div class="fbtns">
		<?php if ($recording) { ?><a class="sec" href="<?php echo $h($cmd($rec_off)); ?>"><?php echo $h(t('str_stop_recording')); ?></a>
		<?php } else { ?><a href="<?php echo $h($cmd($rec_on)); ?>"><?php echo $h(t('str_start_recording')); ?></a><?php } ?>
		<a class="sec" href="views.php?view=Raw%20Recording"><?php echo $h(t('str_raw_recording_menu')); ?></a>
		<a class="sec" href="views.php?view=Disk%20Space"><?php echo $h(t('str_disk_space')); ?></a>
		<button type="button" class="sec" onclick="askModal(t('str_shut_down_the_station_help'), function () { location.href = <?php echo $h(json_encode($cmd($halt))); ?>; })"><?php echo $h(t('str_shut_down_safely')); ?></button>
	</div>
	<p class="small"><?php echo $h(t('str_field_page_help')); ?></p>
</div>
<script>setTimeout(function () { location.reload(); }, 30000);</script>
