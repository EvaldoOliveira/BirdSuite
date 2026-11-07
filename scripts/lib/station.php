<?php
################################################################################
# scripts/lib/station.php - THE STATION: IDENTITY, TIME, UPDATE, SWITCHES, STATE FILES
#
# PURPOSE:
#   The station: identity, time, update, switches, state files: the helpers of the BirdSuite pages on this
#   subject, moved out of common.php (one file per subject, owner 2026-11-06).
#
# DEPENDENCIES:
#   - scripts/common.php (loads this file after core.php and view_registry.php; every lib may call the others)
#
# USAGE:
#   Loaded by common.php on every page; never requested on its own (404 without BS_PROG).
################################################################################

if (!defined('BS_PROG')) { http_response_code(404); exit; }

// The release this installation runs (2026-10-22): a fresh install is a shallow clone (newinstaller.sh --depth=1), where
// git describe finds no tag (Status said "BirdSuite unknown"); then the vX.Y.Z tag on HEAD, else the newest one not newer
// than HEAD. Returns array(tag or '', commits after it: a number, or null when the shallow history cannot count them).
// how far the installation is behind its update branch, from the last fetch (FETCH_HEAD, as update_birdnet.sh compares;
// the update branch can differ from the checked-out one): the one reader of the menu badge and of Status (US-05)
function update_behind() {
	$n = trim((string)shell_exec(station_prefix() . 'git -C ' . escapeshellarg(BS_PROG) . ' rev-list --count HEAD..FETCH_HEAD 2>/dev/null'));
	return ctype_digit($n) ? $n : '0';
}

// seconds since the last fetch of the update branch (a large number when there was none)
function update_fetch_age() {
	$t = @filemtime(BS_PROG . '/.git/FETCH_HEAD');
	return $t ? time() - $t : PHP_INT_MAX;
}

function installed_release() {
	static $r = null;
	if ($r !== null) return $r;
	$git = station_prefix() . 'git -C ' . BS_PROG . ' ';
	$tag = trim((string)shell_exec($git . "describe --tags --abbrev=0 --match 'v[0-9]*' 2>/dev/null"));
	if ($tag !== '') return $r = array($tag, intval(trim((string)shell_exec($git . 'rev-list --count ' . escapeshellarg($tag) . '..HEAD 2>/dev/null'))));
	$best = ''; $exact = false;
	$head = intval(trim((string)shell_exec($git . 'log -1 --format=%ct HEAD 2>/dev/null')));
	$at = explode("\n", (string)shell_exec($git . "tag --points-at HEAD --list 'v*' 2>/dev/null"));
	foreach (explode("\n", (string)shell_exec($git . "for-each-ref --format='%(refname:short) %(*committerdate:unix)%(committerdate:unix)' 'refs/tags/v*' 2>/dev/null")) as $l) {
		$f = explode(' ', trim($l));
		if (count($f) < 2 || !preg_match('~^v\d+\.\d+\.\d+$~', $f[0]) || intval($f[1]) > $head) continue;
		if ($best === '' || version_compare(substr($f[0], 1), substr($best, 1), '>')) { $best = $f[0]; $exact = in_array($f[0], array_map('trim', $at), true); }
	}
	return $r = array($best, $best === '' ? 0 : ($exact ? 0 : null));
}

// The station's identity, its ID (owner 2026-10-20, 2026-10-27): the MAC of the Pi's own network port (cable), else of its
// own WiFi — never of a USB adapter plugged in — the same on every boot and unique per board, so cloned SD cards tell
// themselves apart; the name is only an alias. Own = on the board's bus, or the LAN chip a Pi 3 / 2 / B+ has on its
// internal USB hub (smsc95xx, lan78xx). Same rule in field_net.py mac(), install_firstrun.sh, utils/notifications.py.
// $plain = without colons, upper case.
function station_mac($plain = false) {
	static $mac = null;
	if ($mac === null) {
		$mac = '';
		$names = array_map('basename', glob('/sys/class/net/*') ?: array());
		sort($names);
		foreach (array(false, true) as $wireless) {
			foreach ($names as $i) {
				$d = "/sys/class/net/$i";
				if (!is_dir("$d/device") || is_dir("$d/wireless") !== $wireless) continue;
				$own = basename((string)@realpath("$d/device/subsystem")) !== 'usb' || in_array(basename((string)@realpath("$d/device/driver")), array('smsc95xx', 'lan78xx'), true);
				$m = trim((string)@file_get_contents("$d/address"));
				if ($own && preg_match('/^([0-9a-f]{2}:){5}[0-9a-f]{2}$/i', $m) && $m !== '00:00:00:00:00:00') { $mac = strtolower($m); break 2; }
			}
		}
	}
	return $plain ? strtoupper(str_replace(':', '', $mac)) : $mac;
}

// the default station name until one is chosen (Settings): BirdSuite-<the last 4 digits of the MAC> (owner 2026-11-05,
// privacy: the name goes out in notifications and is the station WiFi's and Bluetooth's; as field_net.py ap_ssid())
function default_sitename() {
	$m = strtoupper(substr(station_mac(true), -4));
	return 'BirdSuite' . ($m !== '' ? '-' . $m : '');
}

function get_sitename() {
	$config = get_config();

	if (($config["SITE_NAME"] ?? '') == "") {
		$site_name = default_sitename();
	} else {
		$site_name = $config['SITE_NAME'];
	}
	return $site_name;
}

function get_service_mount_name() {
	$home = get_home();
	$service_mount = trim(shell_exec("systemd-escape -p --suffix=mount " . BS_STREAMDATA));
	return $service_mount;
}

// a notification channel (email, telegram) sends: set up and its test confirmed by the owner (utils/notify_channels.py)
function notify_channel_confirmed($channel) {
	return notify_channel_state($channel) === 'confirmed';
}

// its state: confirmed | off (switched off on its page) | setup | failed, '' when never tested
function notify_channel_state($channel) {
	$j = json_decode((string)@file_get_contents(BS_STATE . '/notify_channels.json'), true);
	return is_array($j) ? (string)($j[$channel]['state'] ?? '') : '';
}

function debug_log($message) {
	if (is_bool($message)) {
		$message = $message ? 'true' : 'false';
	}
	error_log($message . "\n", 3, $_SERVER['DOCUMENT_ROOT'] . "/debug_log.log");
}

// Is the clock right? (US-120, owner 2026-10-20) The Pi has no battery clock: it is right once NTP synchronised it or
// the phone set it (scripts/field_net.py time writes /run/birdsuite/time_confirmed). Detections are stamped with it.
function time_confirmed() {
	if (is_file('/run/birdsuite/time_confirmed')) return true;
	return trim((string)shell_exec('timedatectl show -p NTPSynchronized --value 2>/dev/null')) === 'yes';
}

// the phone's clock (epoch seconds) when the station's is not confirmed; '' or an error
function set_time_from_client($epoch) {
	$epoch = intval($epoch);
	if ($epoch < 1735689600 || time_confirmed()) return '';
	$out = json_decode((string)shell_exec(station_prefix() . 'python3 ' . escapeshellarg(BS_SCRIPTS . '/field_net.py') . ' time ' . $epoch . ' 2>/dev/null'), true);
	return ($out['ok'] ?? false) ? '' : ($out['error'] ?? t('str_the_clock_was_not_set'));
}

// A location carries its own timezone and default species list (owner 2026-10-20); missing = the station's current ones.
function station_timezone() { return trim((string)shell_exec('timedatectl show --value --property=Timezone 2>/dev/null')); }

function set_station_timezone($tz) {
	if ($tz === '' || !in_array($tz, DateTimeZone::listIdentifiers(), true) || $tz === station_timezone()) return;
	// the root steps of a timezone change: timedatectl and /etc/timezone, in birdsuite-root (least privilege)
	shell_exec("sudo -n " . BS_ROOT_HELPER . " timezone " . escapeshellarg($tz));
}

// the microphone was plugged in again after being removed (scripts/mic_hotplug.sh): the Latest page asks where the
// station is; answered = the file is removed
function mic_reconnected_file() { return BS_STATE . '/mic_reconnected.json'; }

// what was typed in a setup guide, never a secret (owner 2026-11-03: "every box filled in is saved and shown again"):
// state/setup_drafts.json, private to the station user; the token and passwords stay in the token store
function setup_draft_get($name) {
	$d = json_decode((string)@file_get_contents(BS_STATE . '/setup_drafts.json'), true);
	return is_array($d) && is_array($d[$name] ?? null) ? $d[$name] : array();
}

function setup_draft_set($name, $values) {
	$f = BS_STATE . '/setup_drafts.json';
	$d = json_decode((string)@file_get_contents($f), true);
	if (!is_array($d)) $d = array();
	$clean = array();
	foreach ((array)$values as $k => $v) if (preg_match('/^[a-z_]{1,20}$/', (string)$k) && is_scalar($v)) $clean[$k] = mb_substr((string)$v, 0, 200);
	$d[$name] = $clean;
	$old = umask(0077);
	$ok = @file_put_contents($f . '.tmp', json_encode($d, JSON_UNESCAPED_UNICODE)) !== false && @rename($f . '.tmp', $f);
	umask($old);
	return $ok;
}

// the folders of the raw recordings (Tools › Raw Recording): ~/raw-recording (BS_RAW_REC), the default RAW_REC_DIR and
// the folder of every microphone's group (config/raw_recording.json)
function raw_rec_dirs() {
	$out = array(BS_RAW_REC);
	$d = trim((string)(get_config()['RAW_REC_DIR'] ?? ''), " \"'");
	if ($d !== '') $out[] = rtrim($d, '/');
	$g = json_decode((string)@file_get_contents(BS_RAW_REC_FILE), true);
	if (is_array($g)) foreach ($g as $x) if (!empty($x['dir'])) $out[] = rtrim((string)$x['dir'], '/');
	return array_values(array_unique($out));
}

// simple mode (owner 2026-10-29, US-125): without several locations (MULTI_LOCATION) or several stations (MULTI_STATION)
// their controls are hidden (asked at the installation, switched in Settings › Defaults); the data stays the same
function multi_location() { return (string)(get_config()['MULTI_LOCATION'] ?? '0') === '1'; }

// the Kiosk (System › Kiosk, view=Kiosk) only with ACTIVATE_KIOSK=true (owner 2026-11-01, false by default)
function kiosk_active() {
	return trim((string)(get_config()['ACTIVATE_KIOSK'] ?? 'false'), "\"' ") === 'true';
}

function multi_station() { return (string)(get_config()['MULTI_STATION'] ?? '0') === '1'; }

// the scheme of the station's own links (owner 2026-10-29, US-126): http unless HTTPS_ALWAYS = 1 (Settings › Defaults); the
// https side is only switched on for a moment to read the device's GPS (scripts/https_temp.php)
function station_scheme() { return (string)(get_config()['HTTPS_ALWAYS'] ?? '0') === '1' ? 'https' : 'http'; }
