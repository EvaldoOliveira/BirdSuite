<?php
################################################################################
# scripts/access_page.php - STATION › ACCESS: THE WEB LOGIN, PASSWORD, USERS AND ADDRESS
#
# PURPOSE:
#   The page of Station › Access: Web login On / Off, who on the LAN opens the pages, the login name and
#   password (kept as a hash), how to recover it, the named users (scripts/users.php) and the custom address.
#   Its own form and keys only (owner 2026-11-06); a save writes the web server configuration again and
#   restarts nothing else.
#
# DEPENDENCIES:
#   - scripts/common.php (lib/auth.php, lib/settings.php helpers)
#   - scripts/users.php, birdsuite-root caddyfile
#
# USAGE:
#   views.php?view=Access; POST submit=advanced with caddy_user, caddy_pwd, web_login, lan_trust,
#   pwd_recovery, birdnetpi_url
################################################################################

error_reporting(E_ERROR);
ini_set('display_errors', 0);  // errors to the log, never to the page (2026-10-23 security run)

require_once "scripts/common.php";
$home = get_home();
$config = get_config();
$user = get_user();


// Access opens with the factory password too, to set the station's own (bug #60)
ensure_password_setup();
// changes arrive by POST (2026-10-23 test run: sent by GET, the web password landed in the web server log and the
// browser history); the handler reads $_GET, so the posted fields join it, and a GET keeps only what chooses the page
settings_post_merge(array('view', 'status'));
$adv_error = '';
if(isset($_GET['submit'])) try {
	// only the fields of Station › Access; every value checked by conf_set_many() (common.php, one rule per key), all or
	// nothing written; a refused value shows the page again with the reason in a notice (Gitea #85)
	$set = array();
	$update_caddyfile = false;
	$fail = function ($msg) { throw new RuntimeException((string)$msg); };
	// a changed value of these keys: written, and the web server's configuration written again
	$changed = function ($key, $val, $what = '') use (&$set, &$update_caddyfile, $config) {
		if (strcmp((string)$val, (string)($config[$key] ?? '')) === 0) return;
		$set[$key] = $val;
		if ($what === 'caddy') $update_caddyfile = true;
	};
	// the page never shows the password (2026-10-23 test run): an empty field keeps it; the factory password is never a
	// login (bug #60), so there is no way back to it here (the SD card file or the root action reset it)
	if(isset($_GET["caddy_pwd"]) && $_GET["caddy_pwd"] !== '') {
		if (!web_password_ok($_GET["caddy_pwd"])) $fail(t('str_the_password_may_contain_help'));
		// the factory password is public: never the station's own (bug #60)
		if (hash_equals(BS_WEB_PASSWORD_DEFAULT, (string)$_GET["caddy_pwd"])) $fail(t('str_web_login_on_needs_a_password_help'));
		// kept only as its hash (SEC-15)
		if(!web_password_matches((string)$_GET["caddy_pwd"])) $changed('CADDY_PWD', web_password_hash((string)$_GET["caddy_pwd"]), 'caddy');
	}
	// Web login On / Off and who on the LAN opens the pages without it (US-53, owner 2026-11-04); the page itself asked for
	// the login, so turning it off asked for the current password once; the kept password is never erased
	if (isset($_GET['web_login']) && in_array($_GET['web_login'], array('on', 'off'), true)) $changed('WEB_LOGIN', $_GET['web_login'], 'caddy');
	if (isset($_GET['lan_trust']) && in_array($_GET['lan_trust'], array('everyone', 'household'), true)) $changed('LAN_TRUST', $_GET['lan_trust'], 'caddy');
	// how a forgotten password comes back (US-53): one way chosen to save a password; e-mail or Telegram only once their
	// test was confirmed on their page, so the recovery is known to work the day it is chosen; the SD card always works
	if (isset($_GET['pwd_recovery']) && in_array($_GET['pwd_recovery'], array('email', 'telegram', 'sdcard'), true)) {
		$rec = $_GET['pwd_recovery'];
		if ($rec !== 'sdcard' && !notify_channel_confirmed($rec))
			$fail(t('str_set_up_and_test_channel_first_help', array('channel' => t($rec === 'email' ? 'str_e_mail' : 'str_telegram'), 'menu' => menu_path($rec === 'email' ? 'E-mail' : 'Telegram'))));
		$changed('PWD_RECOVERY', $rec);
	}
	// Web login On needs a password of the station's own (bug #60, US-53): without one the login stays Off
	// a password of its own after this save: a new one typed here, else the one kept (the factory one counts as none)
	$own_after = array_key_exists('CADDY_PWD', $set) ? (string)$set['CADDY_PWD'] !== '' : !web_password_is_factory();
	$wl_after = strtolower(trim((string)($set['WEB_LOGIN'] ?? ($config['WEB_LOGIN'] ?? 'on')), " \"'"));
	if (!$own_after && $wl_after !== 'off') $fail(t('str_web_login_on_needs_a_password_help'));
	if (isset($set['CADDY_PWD']) && $set['CADDY_PWD'] !== '' && ($set['PWD_RECOVERY'] ?? (string)($config['PWD_RECOVERY'] ?? '')) === '') $fail(t('str_choose_how_to_recover_help'));
	// the user name of the web login (owner 2026-10-25), letters and digits
	if(isset($_GET["caddy_user"]) && $_GET["caddy_user"] !== web_user()) $changed('CADDY_USER', (string)$_GET["caddy_user"], 'caddy');
	if(isset($_GET["birdnetpi_url"])) {
		// no trailing slash; a bare http(s)://host[:port] only, the value becomes a Caddy site address (NEW-SEC-03)
		$birdnetpi_url = rtrim(trim($_GET["birdnetpi_url"]), '/');
		if (conf_check('BIRDNETPI_URL', $birdnetpi_url) === null) $fail(t('str_not_a_web_address', array('url' => $birdnetpi_url)));
		$changed('BIRDNETPI_URL', $birdnetpi_url, 'caddy');
	}
	// nothing changed: nothing written (Gitea #85); the page says so
	if (!$set) settings_redirect('Access', 'nochange');
	if (!conf_set_many($set, $err)) $fail($err);
	$config = get_config($force_reload=true);
	// the login, the address and the users reach the web server through its configuration (birdsuite-root caddyfile);
	// the recording and the analysis have nothing to do with it (owner 2026-11-06: they restarted on every save before)
	if ($update_caddyfile) exec("sudo -n " . BS_ROOT_HELPER . " caddyfile > /dev/null 2>&1 &");
	settings_redirect('Access', 'success');
} catch (RuntimeException $e) {
	$adv_error = $e->getMessage();
}


$newconfig = get_config();
?>
<div class="settings">
		<?php // one Save button at the end of each section (owner 2026-11-02), not a floating one under the page; every button
		// sends the page's form, so a change in any section is kept
		$adv_save_btn = function () { ?>
			<div class="row" style="margin-top:10px"><button type="submit" class="stdbtn advsave" onclick="if (window.collectrtspUrls) collectrtspUrls(); if(document.getElementById('advancedform').checkValidity()){this.innerHTML = t('str_updating_please_wait');this.classList.add('disabled')}" name="submit" value="advanced"><?php echo t('str_save'); ?></button></div>
		<?php }; ?>
		<form id="advancedform" action="" method="POST">
			<table class="settingstable"><tr><td>
			<h2><?php echo t('str_birdsuite_password'); ?></h2>
			<p><?php echo t('str_the_login_protects_the_pages_of_help'); ?></p>
			<p><?php echo t('str_leave_the_password_empty_to_help'); ?></p>
			<p><?php echo t('str_accepted_characters'); ?> <code>A-Z a-z 0-9 ! @ # % ^ &amp; * ( ) - _ = + [ ] { } . , : ? ~ /</code><br><?php echo t('str_not_accepted_spaces_quotes_and_help'); ?></p>
			<?php // the same pattern as Network (owner 2026-10-25): the login in use with Show password, then the fields in two
			// columns ?>
			<style>
				.acc .knownnet { margin: 6px 0 10px; } .acc .kn, .acc .kp { display: flex; flex-wrap: wrap; gap: 6px; align-items: center; } .acc .kp { margin: 4px 0 0 22px; }
				.acc .netform { display: grid; grid-template-columns: max-content minmax(0, 1fr); gap: 8px 10px; align-items: center; margin: 8px 0 4px; max-width: 32em; }
				.acc .netform input { width: 100%; box-sizing: border-box; }
				.acc .netbtn { width: auto; padding: 1px 9px; font-size: 12px; border-radius: 12px; border: 1px solid var(--accent,#2b5e22); background: #fff; color: var(--accent,#2b5e22); cursor: pointer; }
			</style>
			<div class="acc">
			<?php // Web login On / Off (owner 2026-11-04, US-53): off = an open station, no login and no dialog anywhere
			$wl = web_login_off() ? 'off' : 'on'; $lt = (string)($newconfig['LAN_TRUST'] ?? 'everyone'); ?>
			<div class="knownnet"><b><?php echo t('str_web_login'); ?></b>
				<label><input type="radio" name="web_login" value="on"<?php echo $wl === 'on' ? ' checked' : ''; ?>> <?php echo t('str_web_login_on'); ?></label>
				<label><input type="radio" name="web_login" value="off"<?php echo $wl === 'off' ? ' checked' : ''; ?>> <?php echo t('str_off_open_station'); ?></label>
				<div class="small"><?php echo t('str_web_login_off_help'); ?></div>
				<?php if ($wl === 'off') { ?><div class="small" style="color:#c62828;font-weight:600"><?php echo t('str_open_station_warning'); ?></div><?php } ?>
			</div>
			<div class="knownnet"><label><b><?php echo t('str_on_your_network'); ?></b> <select name="lan_trust">
				<option value="everyone"<?php echo $lt !== 'household' ? ' selected' : ''; ?>><?php echo t('str_lan_trust_everyone'); ?></option>
				<option value="household"<?php echo $lt === 'household' ? ' selected' : ''; ?>><?php echo t('str_lan_trust_household'); ?></option>
			</select></label>
				<div class="small"><?php echo t('str_lan_trust_help'); ?></div></div>
			<?php $factory = web_password_is_factory(); ?>
			<div class="knownnet"><div class="kn">&#128274; <b><?php echo htmlspecialchars(web_user()); ?></b> <?php echo $factory ? '<b style="color:#c62828">' . t('str_factory_password_change_it') . '</b>' : ''; ?></div></div>
			<div class="netform"><label for="caddy_user"><?php echo t('str_user_name'); ?></label>
				<input name="caddy_user" id="caddy_user" type="text" pattern="[A-Za-z0-9]+" required value="<?php echo htmlspecialchars(web_user(), ENT_QUOTES); ?>" autocomplete="username" />
				<label for="caddy_pwd"><?php echo t('str_new_password'); ?></label>
				<input name="caddy_pwd" id="caddy_pwd" type="password" pattern="<?php echo htmlspecialchars(web_password_pattern(), ENT_QUOTES); ?>" title="<?php echo htmlspecialchars(t('str_letters_digits_and_pct_and_plus'), ENT_QUOTES); ?>" value="" autocomplete="new-password" placeholder="<?php echo htmlspecialchars(t('str_empty_keep_it'), ENT_QUOTES); ?>" /></div>
			<div class="small"><?php echo t('str_every_page_asks_for_this_login_help'); ?></div>
			<?php $rc = (string)($newconfig['PWD_RECOVERY'] ?? ''); ?>
			<div class="knownnet"><b><?php echo t('str_how_will_you_recover_it'); ?></b><br>
				<?php foreach (array('email' => 'str_recovery_email', 'telegram' => 'str_recovery_telegram', 'sdcard' => 'str_recovery_sdcard') as $rk => $rl) { ?>
				<label><input type="radio" name="pwd_recovery" value="<?php echo $rk; ?>"<?php echo $rc === $rk ? ' checked' : ''; ?>> <?php echo t($rl); ?></label><br>
				<?php } ?>
				<?php if ($rc === 'sdcard') { ?><div class="small"><?php echo t('str_recovery_sdcard_only_help'); ?></div><?php } ?>
				<div class="small"><?php echo t('str_other_ways_back_help'); ?></div></div>
			<?php // the named users (US-56): each with a role and a level of its own; the first row is the login above ?>
			<div class="knownnet"><b><?php echo t('str_users'); ?></b> <small><?php echo t('str_users_help'); ?></small>
			<table class="stdtable" id="usertable" style="margin-top:6px"><tr><th><?php echo t('str_user_name'); ?></th><th><?php echo t('str_role'); ?></th><th><?php echo t('str_level'); ?></th><th><?php echo t('str_new_password'); ?></th><th></th></tr>
<?php
$role_opts = array('viewer' => 'str_role_viewer', 'operator' => 'str_role_operator', 'admin' => 'str_role_admin');
$level_opts = array('' => 'str_level_of_the_station', 'essential' => 'str_level_essential', 'birder' => 'str_level_birder', 'expert' => 'str_level_expert');
$sel = function ($name, $opts, $cur, $dis = false) {
	$o = '<select data-u="' . $name . '"' . ($dis ? ' disabled' : '') . '>';
	foreach ($opts as $k => $l) $o .= '<option value="' . htmlspecialchars($k) . '"' . ($k === $cur ? ' selected' : '') . '>' . htmlspecialchars(t($l)) . '</option>';
	return $o . '</select>';
};
foreach (web_users() as $u) {
	$main = !empty($u['main']) || $u['name'] === web_user();
	echo '<tr data-name="' . htmlspecialchars($u['name'], ENT_QUOTES) . '"><td>' . htmlspecialchars($u['name']) . ($main ? ' <small>(' . t('str_this_login') . ')</small>' : '') . '</td>'
		. '<td>' . $sel('role', $role_opts, $main ? 'admin' : $u['role'], $main) . '</td><td>' . $sel('level', $level_opts, $u['level']) . '</td>'
		. '<td>' . ($main ? '' : '<input type="password" data-u="password" autocomplete="new-password" pattern="' . htmlspecialchars(web_password_pattern(), ENT_QUOTES) . '" style="width:9em">') . '</td>'
		. '<td><button type="button" class="netbtn" onclick="userSave(this, \'update\')">' . t('str_save') . '</button>'
		. ($main ? '' : ' <button type="button" class="netbtn" onclick="userSave(this, \'delete\')">' . t('str_remove') . '</button>') . '</td></tr>';
}
echo '<tr data-name=""><td><input type="text" data-u="name" maxlength="32" pattern="[A-Za-z0-9]+" style="width:8em" placeholder="' . htmlspecialchars(t('str_new_user')) . '" aria-label="' . htmlspecialchars(t('str_new_user'), ENT_QUOTES) . '"></td>'
	. '<td>' . $sel('role', $role_opts, 'viewer') . '</td><td>' . $sel('level', $level_opts, '') . '</td>'
	. '<td><input type="password" data-u="password" autocomplete="new-password" pattern="' . htmlspecialchars(web_password_pattern(), ENT_QUOTES) . '" style="width:9em"></td>'
	. '<td><button type="button" class="netbtn" onclick="userSave(this, \'add\')">' . t('str_add') . '</button></td></tr>';
?>
			</table><span id="usermsg" style="font-weight:600"></span></div>
			<script>
			function userSave(b, action) {
				var tr = b.closest('tr'), v = function (k) { var e = tr.querySelector('[data-u=' + k + ']'); return e ? e.value : ''; };
				var body = {action: action, name: tr.dataset.name || v('name'), role: v('role'), level: v('level'), password: v('password')};
				var go = function () {
					var x = new XMLHttpRequest(), m = document.getElementById('usermsg');
					x.onload = function () { var ok = this.responseText.trim() === 'OK'; m.textContent = ok ? t('str_saved') : (this.status === 401 ? t('str_log_in_first') : this.responseText.trim()); if (ok) setTimeout(function () { location.reload(); }, 700); };
					x.open('POST', 'scripts/users.php', true); x.setRequestHeader('Content-Type', 'application/json'); x.send(JSON.stringify(body));
				};
				if (action === 'delete') askModal(t('str_remove_this_user', {user: body.name}), go); else go();
			}
			</script>
			</div>
			<?php $adv_save_btn(); ?>
			</td></tr></table><br>
			<table class="settingstable"><tr><td>
			<h2><?php echo t('str_custom_url'); ?></h2>
			<p><?php echo t('str_when_you_update_the_url_below_help'); ?></p>
			<label for="birdnetpi_url"><?php echo t('str_birdsuite_url'); ?> </label>
			<input style="width:40ch;" name="birdnetpi_url" type="url" value="<?php print($newconfig['BIRDNETPI_URL']);?>" /><br>
			<?php // what is in use (owner 2026-10-25): the saved URL, else every address the station answers on
			$addrs = array(strtolower(gethostname()) . '.local');
			foreach (preg_split('/\s+/', trim((string)shell_exec('hostname -I 2>/dev/null'))) as $a) if (filter_var($a, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) $addrs[] = $a;
			$sch = station_scheme();  // http unless HTTPS_ALWAYS (US-126)
			$links = implode(', ', array_map(function ($a) use ($sch) { return '<a href="' . $sch . '://' . htmlspecialchars($a) . '/">' . $sch . '://' . htmlspecialchars($a) . '</a>'; }, $addrs)); ?>
			<p><?php echo ($newconfig['BIRDNETPI_URL'] ?? '') !== ''
				? t('str_in_use', array('url' => '<b>' . htmlspecialchars($newconfig['BIRDNETPI_URL']) . '</b>')) . '<br>' . t('str_the_station_also_answers_on', array('list' => $links))
				: t('str_in_use_none_the_station_answers_help', array('list' => $links)); ?>
				<br><?php echo t('str_opened_now_as', array('host' => '<b>' . htmlspecialchars(($_SERVER['HTTPS'] ?? '') !== '' ? 'https://' : 'http://') . htmlspecialchars((string)($_SERVER['HTTP_HOST'] ?? '')) . '</b>')); ?></p>
			<p><?php echo t('str_the_birdsuite_url_is_how_the_help'); ?> <?php echo t('str_if_you_want_your_installation_help'); ?><br><?php echo t('str_example_for_ip'); ?> <i>http://192.168.0.109</i><br><?php echo t('str_example_if_you_own_your_own_help'); ?> <i>https://virginia.birdnetpi.com</i></p>
			<?php $adv_save_btn(); ?>
			</td></tr></table><br>
		
			<br><br>
			<input type="hidden" name="view" value="Access">
			<input type="hidden" name="advanced_form" value="1">
<?php settings_notice($adv_error); ?>
			</form>
</div>
