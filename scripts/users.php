<?php
################################################################################
# scripts/users.php - THE NAMED USERS OF SETTINGS › ACCESS
#
# PURPOSE:
#    The named users of Settings › Access (US-56, owner 2026-11-04): POST JSON {action, name, role, level, password}.
#     add      a new user: name (letters and digits, up to 32), role (viewer, operator, admin), level ('' = the station's),
#              password (typed twice on the page; stored only as its hash, SEC-15)
#     update   role, level and, when given, a new password of an existing user
#     delete   a user (never the main one, the web login of Settings › Access, and never the last admin)
#   The main user's name and password stay those of Settings › Access (CADDY_USER / CADDY_PWD). config/users.json is
#   written by the station user only, mode 0600; the web server's configuration is written again after each change.
#   Answers OK or the reason. Only an admin.
#
# DEPENDENCIES:
#   - scripts/common.php
#
# USAGE:
#   Referenced by scripts/access_page.php
################################################################################

require_once __DIR__ . '/common.php';
header('Content-Type: text/plain; charset=utf-8');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { echo 'POST only'; die(); }
ensure_authenticated(t('str_you_must_change_the_settings_help'));
$in = json_decode((string)file_get_contents('php://input'), true);
if (!is_array($in)) { echo t('str_nothing_to_save'); die(); }
$action = (string)($in['action'] ?? '');
$name = (string)($in['name'] ?? '');
$role = (string)($in['role'] ?? 'viewer');
$level = (string)($in['level'] ?? '');
$pwd = (string)($in['password'] ?? '');
if (!preg_match('/^[A-Za-z0-9]{1,32}$/', $name)) { echo t('str_a_user_name_is_letters_and_help'); die(); }
if (!in_array($role, array('viewer', 'operator', 'admin'), true)) $role = 'viewer';
if ($level !== '' && !isset(ui_levels()[$level])) $level = '';
if ($pwd !== '' && !web_password_ok($pwd)) { echo t('str_the_password_may_contain_help'); die(); }

$users = web_users();
$main = web_user();
// the file always holds the main user first (no hash: its password is the one of Settings › Access)
$list = array($main => array('name' => $main, 'role' => 'admin', 'level' => $users[$main]['level'] ?? '', 'hash' => '', 'main' => true));
foreach ($users as $n => $e) if ($n !== $main && empty($e['main'])) $list[$n] = $e;

if ($action === 'add') {
	if (isset($list[$name])) { echo t('str_this_user_exists_already'); die(); }
	if ($pwd === '') { echo t('str_a_new_user_needs_a_password'); die(); }
	$list[$name] = array('name' => $name, 'role' => $role, 'level' => $level, 'hash' => web_password_hash($pwd), 'main' => false);
} elseif ($action === 'update') {
	if (!isset($list[$name])) { echo t('str_unknown_user'); die(); }
	if ($name === $main) {
		// the main user stays an admin; its password is changed above on this page
		$list[$name]['level'] = $level;
	} else {
		$list[$name]['role'] = $role;
		$list[$name]['level'] = $level;
		if ($pwd !== '') $list[$name]['hash'] = web_password_hash($pwd);
	}
} elseif ($action === 'delete') {
	if (!isset($list[$name])) { echo t('str_unknown_user'); die(); }
	if ($name === $main) { echo t('str_the_main_user_stays_help'); die(); }
	unset($list[$name]);
} else {
	echo t('str_unknown_action_lc'); die();
}
$out = array('users' => array());
foreach ($list as $e) $out['users'][] = array('name' => $e['name'], 'role' => $e['role'], 'level' => $e['level'], 'hash' => $e['main'] ? '' : $e['hash'], 'main' => (bool)$e['main']);
$old = umask(0077);
$tmp = BS_USERS_FILE . '.tmp';
$ok = @file_put_contents($tmp, json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n") !== false && @chmod($tmp, 0600) && @rename($tmp, BS_USERS_FILE);
umask($old);
if (!$ok) { @unlink($tmp); echo t('str_error_writing_the_settings'); die(); }
syslog(LOG_NOTICE, 'BirdSuite: web user ' . $name . ' ' . $action . ' (' . $role . ')');
exec('sudo -n ' . BS_ROOT_HELPER . ' caddyfile > /dev/null 2>&1 &');
echo 'OK';
