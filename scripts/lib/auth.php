<?php
################################################################################
# scripts/lib/auth.php - WEB LOGIN, PASSWORDS, USERS AND ROLES
#
# PURPOSE:
#   The web login, the passwords (kept as hashes), the named users and their roles: the helpers of the
#   BirdSuite pages on this subject, moved out of common.php (one file per subject, owner 2026-11-06).
#
# DEPENDENCIES:
#   - scripts/common.php (loads this file after core.php and view_registry.php; every lib may call the others)
#
# USAGE:
#   Loaded by common.php on every page; never requested on its own (404 without BS_PROG).
################################################################################

if (!defined('BS_PROG')) { http_response_code(404); exit; }

// the web password (owner 2026-10-25: symbols too): letters, digits and ! @ # % ^ & * ( ) - _ = + [ ] { } . , : ? ~ /;
// never a space, quote, backtick, $, backslash, ; < > | (BirdSuite.conf is read by bash, PHP and Python, field_ble.py
// splits on ;); the same rule in the page's pattern (web_password_pattern) and in install_firstrun.sh
function web_password_ok($p) { return (bool)preg_match('~^[A-Za-z0-9!@#%^&*()_=+\[\]{}.,:?\~/-]+$~', (string)$p); }

function web_password_pattern() { return '[A-Za-z0-9!@#%\^&*\(\)_=+\[\]\{\}.,:?~\/\-]+'; }

// the user name of the web login (owner 2026-10-25: set in Advanced, default birdsuite)
function web_user() {
	$u = (string)(get_config()['CADDY_USER'] ?? '');
	return preg_match('/^[A-Za-z0-9]+$/', $u) ? $u : 'birdsuite';
}

// the web password in force: the one set in Settings › Access, else the factory one (owner 2026-11-02: a password on
// everything, a station is never open)
// the web password is kept only as a bcrypt hash ($2b$, read by Caddy and password_verify; SEC-15): the hash to store
function web_password_hash($plain) {
	return '$2b$' . substr(password_hash((string)$plain, PASSWORD_BCRYPT), 4);
}

// the given password is the one in force: the stored hash, else the factory password; a plain value left by an older
// station (converted by the next update, scripts/web_password_hash.py) is compared as it is
function web_password_matches($given) {
	$stored = (string)(get_config()['CADDY_PWD'] ?? '');
	if ($stored === '') return hash_equals(BS_WEB_PASSWORD_DEFAULT, (string)$given);
	if (preg_match('/^\$2[aby]\$/', $stored)) return password_verify((string)$given, $stored);
	return hash_equals($stored, (string)$given);
}

// the password in force is the factory one: none set, or the factory password stored as its hash (a new install writes
// it so, 2026-11-05); one bcrypt check per stored hash, kept for the session
function web_password_is_factory() {
	static $memo = array();
	$stored = (string)(get_config()['CADDY_PWD'] ?? '');
	if ($stored === '') return true;
	$k = md5($stored);
	if (!isset($memo[$k])) {
		if (isset($_SESSION['bs_pwd_factory'][$k])) {
			$memo[$k] = (bool)$_SESSION['bs_pwd_factory'][$k];
		} else {
			$memo[$k] = web_password_matches(BS_WEB_PASSWORD_DEFAULT);
			if (session_status() === PHP_SESSION_ACTIVE) $_SESSION['bs_pwd_factory'][$k] = $memo[$k];
		}
	}
	return $memo[$k];
}

// the named users (US-56, owner 2026-11-04): config/users.json, station-own, outside git, 0600: name, role (viewer,
// operator, admin), level ('' = the station's), bcrypt hash. Without the file the station has one user, the web login
// of Settings › Access (CADDY_USER / CADDY_PWD), an admin; the first user added writes the file with that one in it.
define('BS_USERS_FILE', BS_CONFIG . '/users.json');

function web_users() {
	static $u = null;
	if ($u !== null) return $u;
	$u = array();
	$j = is_file(BS_USERS_FILE) ? json_decode((string)@file_get_contents(BS_USERS_FILE), true) : null;
	$main = web_user();
	if (is_array($j) && !empty($j['users'])) {
		foreach ($j['users'] as $e) {
			if (!is_array($e) || !preg_match('/^[A-Za-z0-9]{1,32}$/', (string)($e['name'] ?? ''))) continue;
			// the main entry is the web login of Station › Access, whatever name the file kept for it (Gitea #101: the login
			// renamed there was not found here, and every page refused the right password while Caddy accepted it)
			$name = !empty($e['main']) ? $main : $e['name'];
			if (isset($u[$name]) && empty($e['main'])) continue;   // a named user with the login's name never shadows it
			$u[$name] = array('name' => $name, 'role' => !empty($e['main']) ? 'admin' : (in_array($e['role'] ?? '', array('viewer', 'operator', 'admin'), true) ? $e['role'] : 'viewer'),
				'level' => isset(ui_levels()[$e['level'] ?? '']) ? $e['level'] : '', 'hash' => !empty($e['main']) ? '' : (string)($e['hash'] ?? ''), 'main' => !empty($e['main']));
		}
	}
	// the login always has its entry (a file without one, or none at all)
	if (!isset($u[$main]) || empty($u[$main]['main'])) $u = array($main => array('name' => $main, 'role' => 'admin', 'level' => $u[$main]['level'] ?? '', 'hash' => '', 'main' => true)) + $u;
	return $u;
}

// the user this request logged in as (Basic login), or null
function login_user() {
	static $done = false, $who = null;
	if ($done) return $who;
	$done = true;
	if (!isset($_SERVER['PHP_AUTH_USER'])) return null;
	$e = web_users()[(string)$_SERVER['PHP_AUTH_USER']] ?? null;
	if ($e === null) return null;
	$given = (string)($_SERVER['PHP_AUTH_PW'] ?? '');
	// the main user is the web login of Settings › Access: its password in force (the factory one while none is set)
	$ok = $e['main'] ? web_password_matches($given) : ($e['hash'] !== '' && password_verify($given, $e['hash']));
	if (!$ok) return $who = null;
	// the factory password (public in the program) is never a login (bug #60, run 5 NEW-SEC-R5-02): while no password is
	// set it only opens the page that sets one (Station › Access); everywhere else it is a viewer
	if ($e['main'] && !web_login_off() && hash_equals(BS_WEB_PASSWORD_DEFAULT, $given)) { $e['role'] = 'viewer'; $e['factory'] = true; }
	return $who = $e;
}

// no password of the station's own yet: the factory password is in force (none set, or the factory one stored), with
// the login on
function web_password_unset() {
	return !web_login_off() && web_password_is_factory();
}

// this request logged in with the factory password
function factory_login() {
	$u = login_user();
	return $u !== null && !empty($u['factory']);
}

// the page that sets the password (Station › Access): an admin, or the factory password, which may only
// leave the factory state there (a password of its own, or the login Off)
function ensure_password_setup() {
	if (factory_login()) {
		if (!is_same_origin_request()) { header('HTTP/1.0 403 Forbidden'); echo t('str_refused_the_request_came_from_help'); exit; }
		return;
	}
	ensure_authenticated();
}

// admin access: the station's own call, an open station, or a logged-in admin (what every page's check asks)
function is_authenticated() {
	// the station's own calls (localhost) do not log in, as in the Caddyfile
	if (in_array($_SERVER['REMOTE_ADDR'] ?? '', array('127.0.0.1', '::1'), true)) return true;
	// an open station (WEB_LOGIN=off, owner 2026-11-04): nobody logs in, no dialog anywhere
	if (web_login_off()) return true;
	$u = login_user();
	return $u !== null && $u['role'] === 'admin';
}

// A change asked by another web site (2026-10-20): any page open in a browser on the LAN could fire
// <img src="http://station/play.php?wipe=1&confirm=1"> or a form POST at the station, and the browser adds the saved
// login. Refused unless the request comes from the station's own pages; a link followed from elsewhere (another
// station, a notification) still opens a page, since the browser marks it as a navigation the user made.
function is_same_origin_request() {
	$site = $_SERVER['HTTP_SEC_FETCH_SITE'] ?? '';
	if ($site !== '') {
		if ($site === 'same-origin' || $site === 'none') return true;
		if ($_SERVER['REQUEST_METHOD'] !== 'GET' || ($_SERVER['HTTP_SEC_FETCH_MODE'] ?? '') !== 'navigate'
			|| ($_SERVER['HTTP_SEC_FETCH_DEST'] ?? '') !== 'document') return false;
		// such a link may open a page, never act (2026-10-23 test run: play.php?deletefile=, ?wipe=1&confirm=1 and
		// ?shiftfile=..&doshift fired from a link on another site): only the keys that choose what a page shows pass
		parse_str((string)($_SERVER['QUERY_STRING'] ?? ''), $query);
		return !array_diff(array_keys($query), array('view', 'sci', 'filename', 'date', 'from', 'to', 'species', 'sort', 'status'));
	}
	// older browsers: the Origin (sent with POST) or the Referer must name this host
	$host = strtolower(preg_replace('/:\d+$/', '', $_SERVER['HTTP_HOST'] ?? ''));
	foreach (array('HTTP_ORIGIN', 'HTTP_REFERER') as $h) {
		if (!empty($_SERVER[$h])) return strtolower((string)parse_url($_SERVER[$h], PHP_URL_HOST)) === $host;
	}
	return true;
}

// the station is open: no web login at all (US-53)
function web_login_off() { return strtolower(trim((string)(get_config()['WEB_LOGIN'] ?? 'on'), " \"'")) === 'off'; }

// a device on a private network: the LAN, the station WiFi, the VPN (the Caddyfile's private_ranges)
function is_private_client() {
	$ip = (string)($_SERVER['REMOTE_ADDR'] ?? '');
	if ($ip === '' || in_array($ip, array('127.0.0.1', '::1'), true)) return $ip !== '';
	// 10/8, 172.16/12, 192.168/16, fc00::/7: what PHP calls private, as Caddy's private_ranges
	return filter_var($ip, FILTER_VALIDATE_IP) !== false && filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE) === false;
}

// the role of this request (US-53): admin = logged in, the station's own call or an open station; operator = a device on
// a private network of a household station (LAN_TRUST=household: reviews and lists without the login); else viewer
function current_role() {
	if (is_authenticated()) return 'admin';
	$r = ($u = login_user()) !== null ? $u['role'] : 'viewer';
	if (role_rank($r) < 1 && strtolower(trim((string)(get_config()['LAN_TRUST'] ?? 'everyone'), " \"'")) === 'household' && is_private_client()) $r = 'operator';
	return $r;
}

function role_rank($r) { return array('viewer' => 0, 'operator' => 1, 'admin' => 2)[$r] ?? 2; }

// this request has the role, or the login is asked (the same answers as ensure_authenticated)
function ensure_role($role, $error_message = 'str_you_cannot_edit_the_settings_help') {
	if ($role === 'viewer') return;
	if (role_rank(current_role()) >= role_rank($role)) {
		if (!is_same_origin_request()) { header('HTTP/1.0 403 Forbidden'); echo t('str_refused_the_request_came_from_help'); exit; }
		return;
	}
	ensure_authenticated($error_message);
}

// a logged-in user whose role is not enough: refused, no new login dialog (the selector switches user)
function refuse_role() {
	header('HTTP/1.0 403 Forbidden');
	echo '<table><tr><td>' . htmlspecialchars(t('str_this_user_may_not_do_this_help', array('user' => (string)($_SERVER['PHP_AUTH_USER'] ?? '')))) . '</td></tr></table>';
	exit;
}

function ensure_authenticated($error_message = 'str_you_cannot_edit_the_settings_help') {
	if (!is_same_origin_request()) {
		header('HTTP/1.0 403 Forbidden');
		echo t('str_refused_the_request_came_from_help');
		exit;
	}
	if (!is_authenticated()) {
		if (factory_login()) {
			header('HTTP/1.0 403 Forbidden');
			// a page gets the reason with the link; a request of a page's script (a save, a switch) gets plain text, which
			// the script shows as it is (2026-11-05: the hearing window showed the table's markup)
			if (($_SERVER['HTTP_SEC_FETCH_DEST'] ?? 'document') !== 'document' || ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
				header('Content-Type: text/plain; charset=utf-8');
				echo t('str_factory_password_only_sets_help') . ' ' . menu_path('Access');
			} else {
				echo '<table><tr><td>' . htmlspecialchars(t('str_factory_password_only_sets_help')) . ' <a href="/views.php?view=Access">' . htmlspecialchars(menu_path('Access')) . '</a>' . '</td></tr></table>';
			}
			exit;
		}
		if (login_user() !== null) refuse_role();
		header('WWW-Authenticate: Basic realm="BirdSuite - user name: ' . web_user() . '"');
		header('HTTP/1.0 401 Unauthorized');
		// the callers' messages are English keys; t() of an already translated text answers it unchanged
		echo '<table><tr><td>' . t($error_message)
			// a forgotten password (US-53): the reset page, from a private network
			. (is_private_client() ? '<br><a href="/scripts/password_reset.php">' . htmlspecialchars(t('str_forgot_the_password')) . '</a>' : '')
			. '</td></tr></table>';
		exit;
	}
}
