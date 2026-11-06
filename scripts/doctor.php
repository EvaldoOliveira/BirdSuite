<?php
################################################################################
# scripts/doctor.php - STATION DOCTOR
#
# PURPOSE:
#   Station Doctor (owner 2026-10-08): one page that says whether the station is healthy and, when it is not,
#   what is wrong and the one-click fix. Checks: services, microphone, recording and analysis backlog, latest
#   detection, disk and purge protection, quarantined recordings, model files, the location (region) profile,
#   raw recording, sound-repository spool, version and new release, clock, power and temperature, database;
#   since the 2026-10-23 test run also the recording watchdog's and systemd's restarts, detections stopped in
#   daylight while the services run, the offline notification queue, the web password, world-writable files
#   and the last backup. Shown inside System › Status; scripts/doctor.php?format=json = the same checks as
#   JSON (for monitoring, e.g. the Mesh menu): allowed from the station itself or an authenticated session.
#
# DEPENDENCIES:
#   - scripts/common.php
#   - scripts/recording_watchdog.py
#   - scripts/clip_dispose.py
#   - scripts/purge_protection.py
#   - scripts/utils/notify_queue.py
#   - scripts/backup_data.sh
#
# USAGE:
#   Referenced by scripts/status.php
################################################################################

require_once __DIR__ . '/common.php';
if (session_status() === PHP_SESSION_NONE) @session_start();
set_timezone();
$json = (($_GET['format'] ?? '') === 'json');
if ($json && !in_array($_SERVER['REMOTE_ADDR'] ?? '', array('127.0.0.1', '::1'), true)) {
	ensure_authenticated(t('str_you_must_read_the_station_help'));
}
$home = get_home();
$user = get_user();
$config = get_config();
$checks = array();
function check(&$checks, $group, $name, $status, $detail, $fix = null) {
	$checks[] = array('group' => $group, 'name' => $name, 'status' => $status, 'detail' => $detail, 'fix' => $fix);
}
$sh = function ($cmd) { return trim((string)shell_exec($cmd . ' 2>/dev/null')); };
$age = function ($seconds) {
	if ($seconds < 120) return $seconds . ' s';
	if ($seconds < 7200) return round($seconds / 60) . ' min';
	if ($seconds < 172800) return round($seconds / 3600) . ' h';
	return t('str_n_days', array('n' => round($seconds / 86400)));
};

// services: enabled but not running = failure, with a restart button (the commands views.php allows)
// one systemctl call for every service the checks look at (one process instead of three per service)
$svc_info = array();
// the PHP-FPM unit of this OS (2026-11-06 deep review S3: the name was fixed to one version; Bookworm runs 8.2)
$php_unit = preg_replace('/\.service$/', '', trim((string)$sh("systemctl list-units 'php*-fpm.service' --no-legend --plain | awk '{print \$1}' | head -n1"))) ?: 'php8.4-fpm';
$svc_names = array(BS_SVC_RECORDING, BS_SVC_ANALYSIS, BS_SVC_SPECTROGRAM, BS_SVC_LOG, BS_SVC_LIVESTREAM, BS_SVC_ICECAST, BS_SVC_TERMINAL, 'caddy', $php_unit);
foreach (preg_split('/\n\s*\n/', $sh('systemctl show -p Id,ActiveState,UnitFileState,NRestarts ' . implode(' ', array_map(function ($u) { return escapeshellarg($u . '.service'); }, $svc_names)))) as $blk) {
	$kv = array();
	foreach (explode("\n", trim($blk)) as $l) { $p = strpos($l, '='); if ($p !== false) $kv[substr($l, 0, $p)] = substr($l, $p + 1); }
	if (!empty($kv['Id'])) $svc_info[preg_replace('/\.service$/', '', $kv['Id'])] = $kv;
}
$svc_state = array();
$fixable = array(BS_SVC_ANALYSIS, BS_SVC_RECORDING, BS_SVC_LOG, BS_SVC_SPECTROGRAM, BS_SVC_TERMINAL);
foreach (array(BS_SVC_RECORDING => 'str_recording', BS_SVC_ANALYSIS => 'str_analysis',
							 BS_SVC_SPECTROGRAM => 'str_spectrogram', BS_SVC_LOG => 'str_log_viewer', 
							 BS_SVC_LIVESTREAM => 'str_live_audio', BS_SVC_ICECAST => 'str_live_audio_server', BS_SVC_TERMINAL => 'str_web_term',
							 'caddy' => 'str_web_server', $php_unit => 'str_php') as $svc => $label) {
	$active = $svc_info[$svc]['ActiveState'] ?? 'unknown';
	$svc_state[$svc] = $active;
	$enabled = $svc_info[$svc]['UnitFileState'] ?? '';
	if ($active === 'active') {
		check($checks, 'str_services', $label, 'ok', t('str_running', array('svc' => $svc)));
	} elseif ($enabled === 'enabled') {
		$fix = $svc === BS_SVC_LIVESTREAM || $svc === BS_SVC_ICECAST
			? array(t('str_restart_live_audio'), 'sudo -n ' . BS_SVC_HELPER . ' restart ' . BS_SVC_LIVESTREAM . '.service && sudo -n ' . BS_SVC_HELPER . ' restart ' . BS_SVC_ICECAST . '.service')
			: (in_array($svc, $fixable, true) ? array(t('str_restart'), "sudo -n " . BS_SVC_HELPER . " restart $svc.service") : null);
		check($checks, 'str_services', $label, 'fail', t('str_is_although_enabled', array('svc' => $svc, 'state' => $active)), $fix);
	} else {
		check($checks, 'str_services', $label, 'info', t('str_disabled', array('svc' => $svc)));
	}
}

// microphone
$cards = (string)@file_get_contents('/proc/asound/cards');
$usb = preg_match_all('/USB-Audio|USB Audio/i', $cards);
$rec_card = trim($config['REC_CARD'] ?? '', '"');
if ($usb > 0) check($checks, 'str_audio', 'str_microphone', 'ok', t('str_usb_sound_card_s_present_help', array('n' => $usb, 'device' => ($rec_card ?: t('str_default_lc')))));
else check($checks, 'str_audio', 'str_microphone', 'fail', t('str_no_usb_sound_card_microphone_help'));

// recording and analysis backlog
$recs = rtrim($config['RECS_DIR'] ?? BS_RECS, '/');
$stream = glob($recs . '/StreamData/*.wav') ?: array();
$len = max(1, intval($config['RECORDING_LENGTH'] ?? 15));
if ($stream) {
	$newest = max(array_map('filemtime', $stream));
	$lag = time() - $newest;
	check($checks, 'str_audio', 'str_recording', $lag <= 3 * $len + 30 ? 'ok' : 'fail', t('str_newest_chunk_old', array('age' => $age($lag))));
	$n = count($stream);
	check($checks, 'str_audio', 'str_analysis_backlog', $n <= 10 ? 'ok' : ($n <= 40 ? 'warn' : 'fail'),
				($n > 10 ? t('str_chunks_waiting_of_audio_the_help', array('n' => $n, 'age' => $age($n * $len)))
									: t('str_chunks_waiting_of_audio', array('n' => $n, 'age' => $age($n * $len)))),
				$n > 10 ? array(t('str_restart_analysis'), 'sudo -n ' . BS_SVC_HELPER . ' restart ' . BS_SVC_ANALYSIS . '.service') : null);
} else {
	check($checks, 'str_audio', 'str_recording', 'fail', t('str_no_chunks_in_streamdata_the_help'),
				array(t('str_restart_recording'), 'sudo -n ' . BS_SVC_HELPER . ' restart ' . BS_SVC_RECORDING . '.service'));
}

// the recording watchdog (scripts/recording_watchdog.py): its restarts of the last 24 h (2026-10-23 test run)
$wd = json_decode((string)@file_get_contents(BS_STATE . '/recording_watchdog.json'), true);
if (is_array($wd)) {
	$hist = array_filter($wd['history'] ?? array(floatval($wd['last_restart'] ?? 0)), function ($ts) { return time() - $ts < 86400; });
	// one or two restarts a day cost a few minutes of audio (owner 2026-10-25): a warning from three on
	check($checks, 'str_audio', 'str_recording_watchdog', count($hist) >= 3 ? 'warn' : 'ok', $hist
				? t('str_restart_s_of_the_recording_in_help', array('n' => count($hist), 'time' => date('H:i', intval(max($hist)))))
				: t('str_no_restart_of_the_recording_in_help'));
}
// the privacy filter (owner 2026-10-25): what it blanked in 24 h, and whether it can work with the model in use (BirdNET+
// V3 has no human class: only through a shadow model V2.4)
$pt = floatval($config['PRIVACY_THRESHOLD'] ?? 1);
$v3 = strpos($config['MODEL'] ?? '', 'BirdNET-Plus') === 0;
$shadow_ok = (string)($config['SHADOW_ENABLED'] ?? '0') !== '0' && ($config['SHADOW_MODEL_NAME'] ?? '') !== '' && strpos($config['SHADOW_MODEL_NAME'], 'BirdNET-Plus') !== 0;
$pv = json_decode((string)@file_get_contents(BS_STATE . '/privacy.json'), true);
$pm = $ps = $pd = 0;
foreach ((is_array($pv) ? ($pv['hours'] ?? array()) : array()) as $hk => $hv) {
	if (time() - intval($hk) < 86400) { $pm += intval($hv['model'] ?? 0); $ps += intval($hv['shadow'] ?? 0); $pd += intval($hv['dropped'] ?? 0); }
}
$menu_model = menu_path('Model');
if ($v3 && !$shadow_ok) {
	check($checks, 'str_audio', 'str_privacy_filter', $pt > 0 ? 'warn' : 'ok', t('str_not_active_birdnet_plus_v3_has_help', array('menu' => $menu_model)),
				array($menu_model, 'Model', 'view'));
} elseif ($pt <= 0 && $v3) {
	check($checks, 'str_audio', 'str_privacy_filter', 'ok', t('str_off_threshold_0'));
} else {
	check($checks, 'str_audio', 'str_privacy_filter', 'ok', ($v3 ? t('str_through_the_shadow_model') . ': ' : '') . t('str_moment_s_with_a_human_voice_help', array('n' => $pm + $ps))
				. ($pd ? ' (' . t('str_detection_s_removed', array('n' => $pd)) . ')' : ''));
}
// automatic restarts by systemd since the service was last started (a crash loop, e.g. the analysis during the
// microphone lock-up of 2026-10-23, restarted 98 times in a day without any alert)
$nr = array();
foreach (array(BS_SVC_ANALYSIS => 'str_analysis', BS_SVC_RECORDING => 'str_recording') as $svc => $label) {
	$nr[$svc] = array(t($label), intval($svc_info[$svc]['NRestarts'] ?? 0));
}
$nr_max = max(array_column($nr, 1));
check($checks, 'str_audio', 'str_service_restarts', $nr_max >= 5 ? 'warn' : ($nr_max > 0 ? 'info' : 'ok'),
			t('str_automatic_restarts_since_the_help', array('list' => implode(', ', array_map(function ($r) { return $r[0] . ' ' . $r[1]; }, $nr))))
			. ($nr_max >= 5 ? ', ' . t('str_the_service_keeps_stopping_see_help') : ''));

// latest detection
$db = get_db();
$last = $db->querySingle("SELECT Date || ' ' || Time FROM detections ORDER BY Date DESC, Time DESC LIMIT 1");
if ($last) {
	$since = time() - strtotime($last);
	// detections stopped although recording and analysis run: daylight hours (sunrise + 1 h to sunset - 1 h) without a
	// detection, counted from the later of the last detection and this morning's start
	$sun = @date_sun_info(time(), floatval($config['LATITUDE'] ?? 0), floatval($config['LONGITUDE'] ?? 0));
	$running = ($svc_state[BS_SVC_RECORDING] ?? '') === 'active' && ($svc_state[BS_SVC_ANALYSIS] ?? '') === 'active';
	$quiet = 0;
	if ($running && is_array($sun) && is_int($sun['sunrise'] ?? null) && is_int($sun['sunset'] ?? null)
			&& time() > $sun['sunrise'] + 3600 && time() < $sun['sunset'] - 3600) {
		$quiet = time() - max(strtotime($last), $sun['sunrise'] + 3600);
	}
	$stopped = $quiet > 3 * 3600;
	check($checks, 'str_detections', 'str_latest_detection', $since < 86400 && !$stopped ? 'ok' : 'warn', t('str_ago', array('time' => $last, 'age' => $age(max(0, $since))))
				. ($stopped ? ', ' . t('str_no_detection_for_of_daylight_help', array('age' => $age($quiet))) : ''));
} else {
	check($checks, 'str_detections', 'str_latest_detection', 'warn', t('str_no_detections_yet_lc'));
}
$total = intval($db->querySingle('SELECT COUNT(*) FROM detections'));
$dbsize = @filesize(BS_DB);
check($checks, 'str_detections', 'str_database', 'ok', t('str_detections_mb', array('n' => fmt_num($total), 'mb' => fmt_num($dbsize / 1048576, 1))));
$rv = $db->querySingle("SELECT COUNT(*) FROM detection_reviews");
check($checks, 'str_detections', 'str_reviews', 'info', intval($rv) == 1 ? t('str_detection_reviewed', array('n' => 1)) : t('str_detections_reviewed', array('n' => intval($rv))));

// disk and purge
$extracted = $config['EXTRACTED'] ?? ($recs . '/Extracted');
$total_b = @disk_total_space($extracted);
$free_b = @disk_free_space($extracted);
if ($total_b) {
	$used = round(100 * (1 - $free_b / $total_b));
	$limit = intval($config['PURGE_THRESHOLD'] ?? 95);
	$mode = $config['FULL_DISK'] ?? 'purge';
	check($checks, 'str_disk', 'str_disk_space_lc', $used >= $limit ? 'fail' : ($used >= $limit - 10 ? 'warn' : 'ok'),
				t('str_pct_used_gb_free_at_pct_the_help', array('used' => $used, 'gb' => round($free_b / 1073741824), 'limit' => $limit,
					'action' => ($mode === 'keep' ? t('str_stop_keep') : ($mode === 'external' ? t('str_move_old_sounds_to_the_external_help') : t('str_purge_old_clips'))))));
}
// FULL_DISK=external (US-128): the external folder must be usable before the disk fills up
if (($config['FULL_DISK'] ?? '') === 'external') {
	$why = trim((string)shell_exec(station_prefix() . escapeshellarg(BS_PYTHON) . ' ' . escapeshellarg(BS_SCRIPTS . '/clip_dispose.py') . ' --check 2>&1; echo " rc=$?"'));
	$ok = (bool)preg_match('/ rc=0$/', $why);
	$why = preg_replace('/ rc=\d+$/', '', $why);
	check($checks, 'str_disk', 'str_external_backup', $ok && strpos($why, 'fallback purge') === false ? 'ok' : ($ok ? 'warn' : 'fail'), $why);
}
$excl = BS_DISK_EXCLUDE;
$lines = is_file($excl) ? file($excl, FILE_IGNORE_NEW_LINES) : array();
$s = array_search('##start', $lines, true);
$e = array_search('##end', $lines, true);
if ($s !== false && $e !== false) {
	$auto = intdiv($e - $s - 1, 3);  // clip, .png and .jpg (purge_protection.py)
	$manual = count(array_filter(array_slice($lines, $e + 1), function ($l) { return $l !== '' && !in_array(substr($l, -4), array('.png', '.jpg'), true); }));
	check($checks, 'str_disk', 'str_purge_protection', 'ok', t('str_clips_protected_automatically_help',
				array('auto' => $auto, 'top' => intval($config['PURGE_PROTECT_TOP_N'] ?? 50), 'manual' => $manual, 'date' => date('Y-m-d H:i', filemtime($excl)))));
} else {
	// normal on a new station (owner 2026-10-25: not an error)
	check($checks, 'str_disk', 'str_purge_protection', 'ok', t('str_the_protection_list_has_not_help'));
}
$quar = glob($recs . '/quarantine/*.wav') ?: array();
check($checks, 'str_disk', 'str_quarantine', $quar ? 'warn' : 'ok',
			$quar ? t('str_recordings_failed_to_be_help', array('n' => count($quar), 'dir' => $recs . '/quarantine')) : t('str_no_failed_recordings'));
$raw = array_merge(array(), ...array_map(function ($d) { return glob($d . '/*/*.wav') ?: array(); }, raw_rec_dirs())) ?: array();
// a schedule is on when any microphone group has it on with a day (config/raw_recording.json; RAW_REC_* are only the
// defaults of a new group)
$raw_on = false;
foreach ((json_decode((string)@file_get_contents(BS_RAW_REC_FILE), true) ?: array()) as $g)
	if (is_array($g) && ($g['enabled'] ?? '0') === '1' && trim((string)($g['days'] ?? '')) !== '') $raw_on = true;
$raw_size = array_sum(array_map('filesize', $raw));
check($checks, 'str_disk', 'str_raw_recording_lc', 'info', ($raw_on ? t('str_schedule_on') : t('str_schedule_off')) . ', '
			. t('str_files_gb_never_deleted_automatically', array('n' => count($raw), 'gb' => fmt_num($raw_size / 1073741824, 1))));
// the slow file scans (a find over a folder tree) are cached for 10 minutes in state/, so Status draws at once
$cached_count = function ($key, $cmd) use ($sh) {
	$f = BS_STATE . '/doctor_' . $key . '.json';
	$c = @json_decode((string)@file_get_contents($f), true);
	if (is_array($c) && isset($c['n'], $c['t']) && time() - intval($c['t']) < 600) return intval($c['n']);
	$n = intval($sh($cmd));
	@file_put_contents($f, json_encode(array('n' => $n, 't' => time())), LOCK_EX);
	return $n;
};
$spool = trim($config['SOUND_REPO_PATH'] ?? '', '"') ?: BS_SOUND_REPO;  // empty = the station's own spool (US-18)
if (is_dir($spool)) {
	$waiting = $cached_count('spool', 'find ' . escapeshellarg($spool) . ' -type f -mmin +60 | wc -l');
	check($checks, 'str_disk', 'str_sound_repository_spool', $waiting > 50 ? 'warn' : 'ok', t('str_clips_older_than_an_hour_help', array('n' => $waiting)));
}

// model
$model = $config['MODEL'] ?? '';
$model_files = glob(BS_MODEL . '/' . $model . '.*') ?: array();
$is_v3 = stripos($model, 'V3') !== false;
$geo_ok = !$is_v3 || (glob(BS_MODEL . '/' . $model . '_Geo.onnx') ?: false);
check($checks, 'str_model', 'str_detection_model', $model_files && $geo_ok ? 'ok' : 'fail',
			$model . ($model_files ? '' : ', ' . t('str_model_file_missing')) . ($geo_ok ? '' : ', ' . t('str_location_geo_model_missing'))
			. '; ' . t('str_confidence_sensitivity_overlap', array('c' => fmt_setting($config['CONFIDENCE'] ?? '?'), 's' => fmt_setting($config['SENSITIVITY'] ?? '?'), 'o' => fmt_setting($config['OVERLAP'] ?? '?')))
			. ', SF_THRESH ' . fmt_setting($config['SF_THRESH'] ?? '?'));
$pf = __DIR__ . '/region_profile.json';
if (is_file($pf)) {
	$p = json_decode(file_get_contents($pf), true);
	check($checks, 'str_model', 'str_location_profile', ($p['date'] ?? '') >= date('Y-m-d', strtotime('-2 days')) ? 'ok' : 'warn',
				t('str_species_computed_for', array('n' => count($p['data'] ?? array()), 'date' => ($p['date'] ?? '?'), 'lat' => ($p['lat'] ?? '?'), 'lon' => ($p['lon'] ?? '?'))));
} elseif (($config['APPRISE_NOTIFY_REGION_RARE'] ?? '0') == '1') {
	check($checks, 'str_model', 'str_location_profile', 'info', t('str_not_computed_yet_it_is_built_at_help'));
}

// offline notification queue (scripts/utils/notify_queue.py): what waits for the internet, and a failed send (its
// .retry mark; the queue is retried every 5 minutes once online)
$q = BS_STATE . '/notify_queue.jsonl';
$q_lines = is_file($q) ? @file($q, FILE_SKIP_EMPTY_LINES) : array();
if ($q_lines === false) {
	check($checks, 'str_notifications', 'str_notification_queue', 'info', t('str_the_queue_cannot_be_read'));
} else {
	$qn = count($q_lines);
	$retry = is_file($q . '.retry') ? filemtime($q . '.retry') : 0;
	check($checks, 'str_notifications', 'str_notification_queue', ($retry || $qn > 50) ? 'warn' : ($qn > 0 ? 'info' : 'ok'),
				($qn > 0 ? t('str_notifications_waiting_to_be_sent', array('n' => $qn)) : t('str_nothing_waiting_to_be_sent'))
				. ($retry ? ', ' . t('str_the_last_send_failed_at_retried_help', array('time' => date('Y-m-d H:i', $retry))) : ''));
}

// security: the web password and the files anyone on the device could change
$menu_pwd = menu_path('Access');
if (web_login_off()) {
	// an open station (owner 2026-11-04, US-53): chosen by the owner, said once here, never a dialog
	check($checks, 'str_security', 'str_web_password', 'warn', t('str_open_station_warning'), array($menu_pwd, 'Access', 'view'));
} elseif (web_password_is_factory()) {
	// the factory password of a new station (owner 2026-10-23): known to everyone who has read the manual
	check($checks, 'str_security', 'str_web_password', 'warn', is_authenticated() ? t('str_still_the_birdsuite_change_help', array('menu' => $menu_pwd))
				: t('str_still_the_change_it_in_help', array('menu' => $menu_pwd)),
				array($menu_pwd, 'Access', 'view'));
} else {
	check($checks, 'str_security', 'str_web_password', 'ok', t('str_set'));
}
$ww = $cached_count('perms', 'find ' . escapeshellarg(BS_PROG) . ' -xdev -perm -o+w ! -type l | wc -l');
check($checks, 'str_security', 'str_file_permissions', $ww > 0 ? 'warn' : 'ok', $ww > 0
			? t('str_files_or_folders_under_can_be_help', array('n' => $ww, 'dir' => BS_PROG))
			: t('str_no_program_file_can_be_changed_help'));

// the last backup (scripts/backup_data.sh writes state/last_backup)
$menu_bkp = menu_path('Data');
$lb = BS_STATE . '/last_backup';
if (is_file($lb)) {
	$lb_age = time() - filemtime($lb);
	check($checks, 'str_system', 'str_last_backup', $lb_age > 30 * 86400 ? 'warn' : 'ok', t('str_ago', array('time' => date('Y-m-d H:i', filemtime($lb)), 'age' => $age(max(0, $lb_age))))
				. ($lb_age > 30 * 86400 ? ', ' . t('str_make_a_new_one_in', array('menu' => $menu_bkp)) : ''));
} else {
	check($checks, 'str_system', 'str_last_backup', 'info', t('str_no_backup_made_on_this_station_help', array('menu' => $menu_bkp)));
}

// version
$branch =$sh(station_prefix() . "git -C " . BS_PROG . " rev-parse --abbrev-ref HEAD");
$commit = $sh(station_prefix() . "git -C " . BS_PROG . " log -1 --format='%h %cs'");
list($tag, $ahead) = installed_release();
$tag = $tag === '' ? t('str_unknown_lc') : $tag . ($ahead === null ? '+' : ($ahead > 0 ? '+' . $ahead : ''));
$new = $_SESSION['release_new'] ?? '';
check($checks, 'str_system', 'str_version', $new !== '' ? 'warn' : 'ok', t('str_tag_on_branch_commit', array('tag' => $tag, 'branch' => $branch, 'commit' => $commit))
			. ($new !== '' ? ', ' . t('str_new_release_v_available_2', array('v' => $new)) : ''), $new !== '' ? array(t('str_update'), '', 'update') : null);

// clock, power, temperature, load
$ntp = $sh('timedatectl show -p NTPSynchronized --value');
check($checks, 'str_system', 'str_clock', $ntp === 'yes' ? 'ok' : 'warn', $ntp === 'yes' ? t('str_synchronised_ntp') : t('str_not_synchronised_detection_help'));
$thr = $sh('sudo -n ' . BS_ROOT_HELPER . ' throttled');
if (preg_match('/0x([0-9a-f]+)/i', $thr, $m)) {
	$bits = hexdec($m[1]);
	$now = $bits & 0xF;
	$past = ($bits >> 16) & 0xF;
	$txt = array();
	if ($now & 1) $txt[] = t('str_under_voltage_now');
	if ($now & 4) $txt[] = t('str_throttled_now');
	if ($past & 1) $txt[] = t('str_under_voltage_since_boot');
	if ($past & 4) $txt[] = t('str_throttled_since_boot');
	check($checks, 'str_system', 'str_power', $now ? 'fail' : ($past ? 'warn' : 'ok'), $txt ? implode(', ', $txt) . ', ' . t('str_check_the_power_supply') : t('str_no_under_voltage_or_throttling'));
}
$temp = @file_get_contents('/sys/class/thermal/thermal_zone0/temp');
if ($temp !== false) {
	$c = round(intval($temp) / 1000);
	check($checks, 'str_system', 'str_cpu_temperature', $c >= 80 ? 'fail' : ($c >= 70 ? 'warn' : 'ok'), "$c °C");
}
$load = sys_getloadavg();
$cores = max(1, intval($sh('nproc')));
check($checks, 'str_system', 'str_load', $load[1] > $cores ? 'warn' : 'ok', t('str_5_min_on_cores', array('load' => fmt_num($load[1], 2), 'n' => $cores)));
$up = intval(explode(' ', (string)@file_get_contents('/proc/uptime'))[0]);
check($checks, 'str_system', 'str_uptime', 'info', $age($up));

$worst = 'ok';
foreach ($checks as $c) {
	if ($c['status'] === 'fail') { $worst = 'fail'; break; }
	if ($c['status'] === 'warn') $worst = 'warn';
}

if ($json) {
	header('Content-Type: application/json');
	echo json_encode(array('station' => get_sitename(), 'time' => date('c'), 'status' => $worst,
		'checks' => array_map(function ($c) { return array('group' => t($c['group']), 'name' => t($c['name']), 'status' => $c['status'], 'detail' => $c['detail']); }, $checks)),
		JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
	exit;
}
$h = function ($s) { return htmlspecialchars((string)$s, ENT_QUOTES); };
$icon = array('ok' => '✅', 'warn' => '⚠️', 'fail' => '❌', 'info' => 'ℹ️');
?>
<style>
.doctor { max-width: 900px; margin: 0 auto; text-align: left; padding: 0 12px; }
.doctor table { width: 100%; border-collapse: collapse; }
.doctor td { text-align: left !important; padding: 5px 6px; border-top: 1px solid rgba(128,128,128,.25); vertical-align: top; }
.doctor td.st { width: 1.6em; }
.doctor td.nm { width: 30%; font-weight: bold; }
.doctor tr.grp td { font-weight: bold; border-top: none; padding-top: 14px; font-size: 1.05em; opacity: .8; }
.doctor .summary { padding: 10px 12px; border-radius: 8px; margin: 10px 0; }
.doctor .summary.ok { background: rgba(46,125,50,.15); } .doctor .summary.warn { background: rgba(217,122,0,.18); } .doctor .summary.fail { background: rgba(198,40,40,.18); }
</style>
<?php // only a part of System › Status (and the JSON above) ?>
<div class="doctor">
	<div class="summary <?php echo $worst; ?>"><?php echo $icon[$worst] . ' ' . ($worst === 'ok' ? t('str_everything_looks_healthy') : ($worst === 'warn' ? t('str_the_station_works_with_warnings_help') : t('str_something_needs_attention_see_help'))); ?>
		<small style="float:right"><?php echo date('Y-m-d H:i:s'); ?>, <a href="views.php?view=Status"><?php echo t('str_check_again'); ?></a>, <a href="scripts/doctor.php?format=json" target="_blank">JSON</a></small></div>
	<?php // every line always, no pages (owner 2026-10-24) ?>
	<table>
	<?php $g = '';
	foreach ($checks as $c) {
		// in Status the services have their own table below, with the buttons: their checks only count in the summary
		if ($c['group'] === 'Services' && $c['status'] !== 'fail') continue;
		if ($c['group'] !== $g) { $g = $c['group']; echo '<tr class="grp"><td colspan="3">' . $h(t($g)) . '</td></tr>'; }
		$fix = '';
		if ($c['fix'] && ($c['fix'][2] ?? '') === 'update') {
			// the update screen of Status (its question, progress and reload), never a bare command
			$fix = ' <button type="button" onclick="if (window.stUpdate) stUpdate(); else location.href = \'views.php?view=Status\'">' . $h($c['fix'][0]) . '</button>';
		} elseif ($c['fix'] && ($c['fix'][2] ?? '') === 'view') {
			// a page to open (the setting is changed there), not a command
			$fix = ' <form action="views.php" method="GET" style="display:inline"><button type="submit" name="view" value="' . $h($c['fix'][1]) . '">' . $h($c['fix'][0]) . '</button></form>';
		} elseif ($c['fix']) {
			$fix = ' <form action="views.php" method="POST" style="display:inline"><button type="submit" name="submit" value="' . $h($c['fix'][1]) . '">' . $h($c['fix'][0]) . '</button></form>';
		}
		echo '<tr><td class="st">' . $icon[$c['status']] . '</td><td class="nm">' . $h(t($c['name'])) . '</td><td>' . $h($c['detail']) . $fix . '</td></tr>';
	} ?>
	</table>
</div>
