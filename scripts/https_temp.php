<?php
################################################################################
# scripts/https_temp.php - HTTPS FOR A MOMENT
#
# PURPOSE:
#    https for a moment (owner 2026-10-29, US-126): the station runs on http; browsers give a page the device's GPS only over
#   https, so the GPS button of the location map switches https on here, reads the position in a small https window
#   (scripts/gps.php) and switches it off again. POST only, after the login.
#     POST https_temp.php?on=1   https on for 10 minutes at most (state/https_temp = the time it ends), when it answers
#     POST https_temp.php?off=1  https off (nothing changes with HTTPS_ALWAYS = 1)
#   The field service (field_daemon.py) switches it off when the time is over and the page did not.
#
# DEPENDENCIES:
#   - scripts/common.php
#   - scripts/field_daemon.py
#
# USAGE:
#   Referenced by homepage/static/location-picker.js, homepage/static/review-player.js,
#   scripts/field_daemon.py, scripts/lib/station.php, scripts/share.php, scripts/update_caddyfile.sh
################################################################################

require_once __DIR__ . '/common.php';
header('Content-Type: application/json');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { echo json_encode(array('ok' => false)); die(); }
ensure_authenticated(t('str_you_must_change_the_settings_help'));
session_write_close();  // the window's other requests are not held while the web server reloads
$flag = BS_HTTPS_TEMP;
$always = station_scheme() === 'https';
$caddy = function () { exec('sudo -n ' . BS_ROOT_HELPER . ' caddyfile > /dev/null 2>&1', $o, $rc); return $rc === 0; };
if (isset($_GET['on'])) {
	if (!$always) {
		if (@file_put_contents($flag, (string)(time() + 600)) === false) { echo json_encode(array('ok' => false, 'error' => t('str_cannot_switch_https_on'))); die(); }
		if (!$caddy()) { @unlink($flag); echo json_encode(array('ok' => false, 'error' => t('str_cannot_switch_https_on'))); die(); }
	}
	// ready when the https side answers (the reload takes a moment)
	$ok = false;
	for ($i = 0; $i < 20 && !$ok; $i++) {
		$s = @stream_socket_client('tcp://127.0.0.1:443', $en, $es, 1);
		if ($s) { fclose($s); $ok = true; } else usleep(500000);
	}
	echo json_encode(array('ok' => $ok, 'error' => $ok ? '' : t('str_cannot_switch_https_on')));
} elseif (isset($_GET['off'])) {
	if (!$always && is_file($flag)) { @unlink($flag); $caddy(); }
	echo json_encode(array('ok' => true));
} else {
	echo json_encode(array('ok' => false));
}
