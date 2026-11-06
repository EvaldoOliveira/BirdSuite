<?php
################################################################################
# scripts/lib/files.php - FILES AND FOLDERS AS THE STATION USER
#
# PURPOSE:
#   Files and folders seen as the station user: the helpers of the BirdSuite pages on this subject, moved out
#   of common.php (one file per subject, owner 2026-11-06).
#
# DEPENDENCIES:
#   - scripts/common.php (loads this file after core.php and view_registry.php; every lib may call the others)
#
# USAGE:
#   Loaded by common.php on every page; never requested on its own (404 without BS_PROG).
################################################################################

if (!defined('BS_PROG')) { http_response_code(404); exit; }

// the station's number format follows the BirdSuite language (owner 2026-10-23): UI_LANG, or DATABASE_LANG when empty;
// LC_NUMERIC only (birdsuite-root numeric), the system messages keep their language. Silent on stations without it.
// a picture path of a setting (NOW_BACKGROUND, the Now page background): an image file (png, jpg, gif, webp) inside the
// station user's home, with a plain path (2026-10-23 NEW-SEC-05: any readable file could be shown to anyone on the network)
function custom_image_ok($path) {
	$home = get_home();
	if (!preg_match('#^/[A-Za-z0-9._/-]+\.(png|jpe?g|gif|webp)$#i', (string)$path)) return false;
	$real = realpath($path);
	return $real !== false && strpos($real, rtrim($home, '/') . '/') === 0 && is_file($real) && @getimagesize($real) !== false;
}

// The station's folders open in the viewer's own file manager over SFTP (owner 2026-10-23: no file manager inside the web
// pages, the most conservative choice): sftp://<station user>@<this host>:<SSH port><path>. Dolphin / Kate open it through
// KIO, WinSCP on Windows; the SSH login protects it, nothing is added to the web surface.
function station_ssh_port() {
	foreach (array_merge(array('/etc/ssh/sshd_config'), glob('/etc/ssh/sshd_config.d/*.conf') ?: array()) as $f) {
		foreach (@file($f, FILE_IGNORE_NEW_LINES) ?: array() as $l) {
			if (preg_match('/^\s*Port\s+(\d+)/i', $l, $m)) return intval($m[1]);
		}
	}
	return 22;
}

function sftp_base() {
	$host = preg_replace('/:\d+$/', '', $_SERVER['HTTP_HOST'] ?? '') ?: gethostname();
	return 'sftp://' . rawurlencode(get_user()) . '@' . $host . ':' . station_ssh_port();
}

function sftp_url($path) {
	return sftp_base() . implode('/', array_map('rawurlencode', explode('/', $path)));
}

// Folders and files of the station seen with the station user's own rights (owner 2026-10-23: the pages may open any
// folder, mounted drives included, but only with the visibility the station user has, never with the web server's or
// root's). Every look runs as that user through sudo -u; what the user cannot read does not exist for the pages.
function station_user_cmd($cmd) {
	return as_station($cmd);
}

// a command run as the station user: directly when the pages already run as that user (fresh installs, owner
// 2026-10-23: least privilege), through sudo -u on an older station whose pages still run as the web server
function as_station($cmd) {
	return station_prefix() . $cmd;
}

// the start of a shell command run as the station user: nothing when the pages are that user, else sudo -n -u <user>
function station_prefix() {
	static $prefix = null;
	if ($prefix === null) {
		$pw = function_exists('posix_getpwuid') ? posix_getpwuid(posix_geteuid()) : false;
		$prefix = ($pw !== false && $pw['name'] === get_user()) ? '' : 'sudo -n -u ' . escapeshellarg(get_user()) . ' ';
	}
	return $prefix;
}

// the physical path of a folder the station user can enter, else false
// The folders a page may browse from (owner 2026-10-24: an external disk or share mounted under /mnt or /media must be
// seen): the station user's home and every mount point under /mnt and /media (from /proc/mounts, any depth: /media/usb
// as well as /media/pi/DISK and /mnt/nas/backup), plus the folders right under them. Each with ok = the station user
// can enter it (a share mounted for root only is listed, greyed, so the reason can be told)
function folder_roots() {
	static $roots = null;
	if ($roots !== null) return $roots;
	$cands = array(get_home());
	$mounts = array();
	foreach (@file('/proc/mounts', FILE_IGNORE_NEW_LINES) ?: array() as $l) {
		$f = explode(' ', $l);
		$mp = str_replace(array('\\040', '\\011'), array(' ', "\t"), $f[1] ?? '');
		if (preg_match('~^/(mnt|media)/.+~', $mp)) { $cands[] = $mp; $mounts[] = $mp; }
	}
	foreach (array_merge(glob('/mnt/*', GLOB_ONLYDIR) ?: array(), glob('/media/*', GLOB_ONLYDIR) ?: array(), glob('/media/*/*', GLOB_ONLYDIR) ?: array()) as $d) $cands[] = $d;
	$roots = array();
	foreach (array_unique($cands) as $c) {
		// /media/<user> itself is only the parent of that user's disks
		if (preg_match('~^/media/[^/]+$~', $c) && glob($c . '/*', GLOB_ONLYDIR) && !in_array($c, $mounts, true)) continue;
		$roots[] = array('path' => $c, 'ok' => station_user_dir($c) !== false);
	}
	return $roots;
}

// a folder of the station the station user can enter: its real path, else false (owner 2026-10-29: the whole tree, not
// only the home and the mounted shares; folder_roots() are the shortcuts); the kernel's pseudo file systems stay out
function root_folder($p) {
	$r = station_user_dir($p);
	if ($r === false || preg_match('~^/(proc|sys|dev|run)(/|$)~', $r)) return false;
	return $r;
}

function station_user_dir($p) {
	$p = (string)$p;
	if ($p === '' || strpos($p, "\0") !== false) return false;
	$out = shell_exec(station_user_cmd('sh -c ' . escapeshellarg('cd -- "$1" 2>/dev/null && pwd -P') . ' _ ' . escapeshellarg($p)));
	$r = rtrim((string)$out, "\n");
	return ($r !== '' && $r[0] === '/') ? $r : false;
}

// the entries of a folder as the station user sees them: array('dirs' => [...], 'files' => [...]), hidden ones left out
function station_user_ls($dir) {
	$out = (string)shell_exec(station_user_cmd('find ' . escapeshellarg($dir) . ' -mindepth 1 -maxdepth 1 ! -name ' . escapeshellarg('.*') . ' -printf ' . escapeshellarg('%Y\t%f\0') . ' 2>/dev/null'));
	$r = array('dirs' => array(), 'files' => array());
	foreach (explode("\0", $out) as $e) {
		if ($e === '' || strpos($e, "\t") === false) continue;
		list($type, $name) = explode("\t", $e, 2);
		if ($type === 'd') $r['dirs'][] = $name; elseif ($type === 'f') $r['files'][] = $name;
	}
	natcasesort($r['dirs']); natcasesort($r['files']);
	$r['dirs'] = array_values($r['dirs']); $r['files'] = array_values($r['files']);
	return $r;
}

function station_user_is_file($f) {
	exec(station_user_cmd('test -f ' . escapeshellarg($f) . ' -a -r ' . escapeshellarg($f)), $o, $rc);
	return $rc === 0;
}

// a small file (JSON, CSV) read as the station user; false when it cannot read it
function station_user_read($f, $max = 52428800) {
	if (!station_user_is_file($f)) return false;
	return (string)shell_exec(station_user_cmd('head -c ' . intval($max) . ' -- ' . escapeshellarg($f)));
}
