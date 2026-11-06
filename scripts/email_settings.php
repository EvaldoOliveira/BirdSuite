<?php
################################################################################
# scripts/email_settings.php - E-MAIL
#
# PURPOSE:
#   E-mail (owner 2026-10-20; one guide per scenario 2026-10-22): e-mail notifications through Apprise. A
#   station has no mail server of its own, so the e-mail goes out through a provider's SMTP with the sender
#   account's app password. When e-mail is off the page asks which account sends (a Gmail, a new Gmail for the
#   station, another provider) and walks one step at a time: 2-Step Verification, the app password, the
#   account, then save and test (the test logs in to the server first, so a refused password is said plainly).
#   The secrets go to the station's token store (scripts/email_setup.py, run as the station user); the
#   notification boxes of Settings keep only placeholders. The recipient is NOTIFICATION_EMAIL ($email).
#   Opened as views.php?view=E-mail.
#
# DEPENDENCIES:
#   - scripts/common.php
#   - scripts/email_setup.py
#
# USAGE:
#   views.php?view=E-mail
#   Referenced by homepage/views.php, scripts/email_setup.py, scripts/update_caddyfile.sh
################################################################################

require_once __DIR__ . '/common.php';

function em_run($action, $stdin = '') {
	$cmd = station_prefix() . '' . escapeshellarg(BS_PYTHON) . ' '
		. escapeshellarg(__DIR__ . '/email_setup.py') . ' ' . escapeshellarg($action);
	$p = proc_open($cmd, array(0 => array('pipe', 'r'), 1 => array('pipe', 'w'), 2 => array('pipe', 'w')), $pipes);
	if (!is_resource($p)) return array('ok' => false, 'error' => 'cannot run email_setup.py');
	fwrite($pipes[0], $stdin);
	fclose($pipes[0]);
	$out = stream_get_contents($pipes[1]);
	fclose($pipes[1]); fclose($pipes[2]);
	proc_close($p);
	return json_decode((string)$out, true) ?: array('ok' => false, 'error' => trim((string)$out) ?: t('str_no_answer_lc'));
}

if (isset($_GET['status'])) {
	header('Content-Type: application/json');
	$s = em_run('status');
	$s['recipient'] = get_config()['NOTIFICATION_EMAIL'] ?? '';
	// the accounts used (login names, every saved sender) only for a logged-in reviewer (owner 2026-10-22)
	if (!is_authenticated()) { unset($s['user'], $s['saved_senders']); $s['recipient'] = $s['recipient'] !== '' ? '…' : ''; }
	else $s['draft'] = setup_draft_get('email');
	echo json_encode($s);
	die();
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_GET['do'])) {
	ensure_authenticated(t('str_you_must_change_the_e_help'));
	header('Content-Type: application/json');
	$p = json_decode((string)file_get_contents('php://input'), true) ?: array();
	if ($_GET['do'] === 'set') {
		$to = trim((string)($p['recipient'] ?? ''));
		if (!preg_match('/^[^\s"\'`$\\\\@]+@[^\s"\'`$\\\\@]+\.[^\s"\'`$\\\\@]+$/', $to)) { echo json_encode(array('ok' => false, 'error' => t('str_a_valid_recipient_address_is_needed'))); die(); }
		$r = em_run('set', json_encode(array('host' => $p['host'] ?? '', 'port' => $p['port'] ?? '', 'mode' => $p['mode'] ?? '', 'sender' => $p['sender'] ?? '',
			'user' => $p['user'] ?? '', 'password' => $p['password'] ?? '', 'rare' => !empty($p['rare']))));
		if (!empty($r['ok'])) {
			if (!conf_set('NOTIFICATION_EMAIL', $to)) $r = array('ok' => false, 'error' => t('str_cannot_write_the_recipient_to_help'));
		}
		echo json_encode($r);
	} elseif ($_GET['do'] === 'draft') {
		// the typed boxes, saved as they are filled in (the password never: it is kept per account by Save and test)
		$keep = array();
		foreach (array('emsender', 'emto', 'emhost', 'emport', 'emmode', 'emuser', 'emrare', 'prov', 'scenario') as $k) if (isset($p[$k])) $keep[$k] = is_bool($p[$k]) ? ($p[$k] ? '1' : '') : $p[$k];
		echo json_encode(array('ok' => setup_draft_set('email', $keep)));
	} elseif ($_GET['do'] === 'forget') {
		echo json_encode(em_run('forget', (string)($p['sender'] ?? '')));
	} elseif ($_GET['do'] === 'remove') {
		// what was set up stays in the boxes for a new setup (the app password stays saved for that account)
		$s = em_run('status');
		if (!empty($s['configured']) && empty($s['local'])) setup_draft_set('email', array('emsender' => $s['sender'] ?? '', 'emto' => get_config()['NOTIFICATION_EMAIL'] ?? '',
			'emhost' => $s['host'] ?? '', 'emport' => $s['port'] ?? '', 'emmode' => $s['mode'] ?? '', 'emuser' => $s['user'] ?? '', 'emrare' => !empty($s['rare']) ? '1' : ''));
		echo json_encode(em_run('remove'));
	} elseif ($_GET['do'] === 'test') {
		echo json_encode(em_run('test'));
	} elseif ($_GET['do'] === 'confirm') {
		echo json_encode(em_run('confirm'));
	} elseif ($_GET['do'] === 'on' || $_GET['do'] === 'off') {
		echo json_encode(em_run($_GET['do']));
	} else {
		echo json_encode(array('ok' => false, 'error' => t('str_unknown_action_lc')));
	}
	die();
}
?>
<style>
.em { max-width: 1000px; margin: 0; text-align: left; padding: 0 12px; box-sizing: border-box; }
.em .box { padding: 12px 14px; margin: 10px 0; }
.em h3 { margin: 0 0 6px; font-size: 16px; }
.em ol { margin: 6px 0 6px 18px; padding: 0; font-size: 14px; }
.em ol li { margin: 3px 0; }
.em label { display: block; margin: 8px 0 2px; font-weight: 600; }
.em input, .em select { padding: 5px 8px; border-radius: 6px; border: 1px solid #bbb; width: 100%; max-width: 360px; box-sizing: border-box; }
.em .row { display: flex; flex-wrap: wrap; gap: 8px; align-items: center; margin-top: 10px; }
.em .stdbtn.del { color: #c62828 !important; border-color: #c62828 !important; }
.em .stdbtn.del:hover { background: #c62828 !important; color: #fff !important; }
.em .small { font-size: 12px; opacity: .8; }
.em .msg { font-weight: 600; }
.em .ok { color: #2e7d32; } .em .err { color: #c62828; }
.em .warn { margin: 8px 0; padding: 8px 10px; border-radius: 10px; background: rgba(217,122,0,.12); }
.em .cards { display: flex; flex-wrap: wrap; gap: 10px; margin-top: 8px; }
.em .card { flex: 1 1 200px; min-width: 0; white-space: normal !important; text-align: left; padding: 12px; border-radius: 10px; border: 2px solid rgba(0,0,0,.12); background: transparent; cursor: pointer; color: inherit; font: inherit; font-weight: normal !important; }
.em .card:hover { border-color: var(--accent,#2b5e22); }
.em .card b { display: block; overflow-wrap: anywhere; margin-bottom: 4px; font-size: 15px; }
.em .progress { display: flex; gap: 6px; align-items: center; margin-bottom: 8px; font-size: 13px; opacity: .85; }
.em .dot { width: 10px; height: 10px; border-radius: 50%; background: rgba(0,0,0,.18); }
.em .dot.on { background: var(--accent,#2b5e22); }
.em .nav { display: flex; gap: 8px; margin-top: 14px; }
</style>
<div class="em">
	<div class="box" id="emstate"><?php echo t('str_reading'); ?></div>
	<?php // the guided setup when e-mail is off, after Remove or on "Change the e-mail setup" (owner 2026-10-22: one guide per scenario) ?>
	<div class="box" id="emguide" style="display:none"></div>
</div>
<script>
var esc = function (s) { return String(s == null ? '' : s).replace(/[&<>"]/g, function (c) { return {'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;'}[c]; }); };
function $(id) { return document.getElementById(id); }
function v(id) { var e = $(id); return e ? e.value.trim() : ''; }
var EM_PRESETS = {
	gmail: ['smtp.gmail.com', 587, 'starttls'],
	yahoo: ['smtp.mail.yahoo.com', 465, 'ssl', t('str_yahoo_needs_an_app_password_help')],
	icloud: ['smtp.mail.me.com', 587, 'starttls', t('str_icloud_needs_an_app_specific_help')],
	zoho: ['smtp.zoho.com', 465, 'ssl', t('str_zoho_an_application_specific_help')],
	custom: ['', 587, 'starttls', t('str_the_smtp_server_of_your_help')]
};
// the steps of each scenario (owner 2026-10-22): a Gmail account, a new Gmail only for the station, any other provider. Outlook was
// taken out the same day: personal Outlook.com accounts refuse both the account password and an app password (535 5.7.3)
var EM_STEPS = {gmail: ['twosv', 'apppass', 'account', 'save'], newgmail: ['create', 'twosv', 'apppass', 'account', 'save'], other: ['provider', 'account', 'save']};
var EM = {status: {}, scenario: '', step: 0, acc: {}, prov: 'gmail'};
// a saved app password shows as dots in its box (owner 2026-11-03), never the password itself; left as it is = kept
var EM_MASK = '\u2022\u2022\u2022\u2022\u2022\u2022\u2022\u2022';

function emCall(what, body, done) {
	var x = new XMLHttpRequest();
	x.onload = function () {
		var r; try { r = JSON.parse(this.responseText); } catch (e) { r = {ok: false, error: this.status === 401 ? t('str_log_in_first') : this.responseText}; }
		done(r);
	};
	x.open('POST', 'scripts/email_settings.php?do=' + what, true); x.setRequestHeader('Content-Type', 'application/json'); x.send(JSON.stringify(body || {}));
}
function say(id, ok, text) { var m = $(id); if (m) m.innerHTML = '<span class="' + (ok ? 'ok' : 'err') + '">' + (ok ? '&#10003; ' : '') + text + '</span>'; }
function link(url, text) { return '<a href="' + url + '" target="_blank" rel="noopener">' + (text || url.replace(/^https:\/\//, '')) + '</a>'; }

//##############################################################################
// the picker: which account sends
//##############################################################################
// an account with a saved app password (owner 2026-11-03): shown first, "use it" goes on to the account step filled in,
// "no" shows the choices and the links to make another
function emPicker(keep) {
	EM.scenario = ''; EM.step = 0; if (!keep && !EM.acc.emsender) EM.acc = emDraft();
	$('emguide').style.display = '';
	var saved = savedSenders();
	if (saved.length && !keep) {
		var who = EM.status.sender && saved.indexOf(EM.status.sender) >= 0 ? EM.status.sender : (EM.acc.emsender && saved.indexOf(EM.acc.emsender) >= 0 ? EM.acc.emsender : saved[0]);
		$('emguide').innerHTML = '<h3>' + t('str_an_account_is_already_saved_use_it', {name: '<b>' + esc(who) + '</b>'}) + '</h3>'
			+ '<div class="row"><button type="button" class="stdbtn" onclick="emReuse(' + esc(JSON.stringify(who)).replace(/"/g, '&quot;') + ')">' + t('str_yes_use_it') + '</button>'
			+ '<button type="button" class="stdbtn" onclick="emPicker(true)">' + t('str_no_set_up_another') + '</button></div>';
		return;
	}
	$('emguide').innerHTML = '<h3>' + t('str_which_e_mail_account_will_send_help') + '</h3>'
		+ '<div class="cards">'
		+ '<button type="button" class="card" onclick="emStart(\'gmail\')"><b>' + t('str_i_have_a_gmail_account') + '</b>' + t('str_gmail_or_google_workspace') + '<br>' + t('str_recommended') + '</button>'
		+ '<button type="button" class="card" onclick="emStart(\'newgmail\')"><b>' + t('str_a_new_gmail_for_the_station') + '</b>' + t('str_an_account_used_only_to_send_help') + '</button>'
		+ '<button type="button" class="card" onclick="emStart(\'other\')"><b>' + t('str_another_provider') + '</b>' + t('str_yahoo_icloud_zoho_or_any_smtp_help') + '</button>'
		+ '</div>'
		+ '<div style="margin-top:10px">' + t('str_only_tested_with_gmail') + '<br>' + t('str_feedback_about_other_providers_help') + '</div>'
		+ '<div class="small" style="margin-top:10px">' + t('str_a_station_has_no_mail_server_of_help') + '<br>'
		+ t('str_without_internet_in_a_help') + '</div>';
}
function emReuse(who) {
	var host = EM.acc.emhost || EM.status.host || '', prov = 'gmail';
	Object.keys(EM_PRESETS).forEach(function (p) { if (EM_PRESETS[p][0] === host) prov = p; });
	if (host && !EM_PRESETS[prov]) prov = 'custom';
	if (host && prov === 'gmail' && EM_PRESETS.gmail[0] !== host) prov = 'custom';
	EM.acc.emsender = who;
	if (prov === 'gmail') { EM.scenario = 'gmail'; EM.prov = 'gmail'; } else { EM.scenario = 'other'; EM.prov = prov; }
	EM.step = EM_STEPS[EM.scenario].indexOf('account');
	emShow();
}
// the guide again with everything as it is set up now (owner 2026-11-03: "Restart the setup" instead of Remove)
function emRestart() {
	var s = EM.status;
	EM.acc = {emsender: s.sender || '', emto: s.recipient || '', emhost: s.host || '', emport: s.port || '', emmode: s.mode || '', emuser: s.user || '', emrare: !!s.rare};
	emPicker(false);
}
function emStart(s) { var d = EM.status.draft || {}; EM.scenario = s; EM.step = 0; EM.prov = s === 'other' ? (d.scenario === 'other' && d.prov ? d.prov : 'yahoo') : 'gmail'; emShow(); }
function emNav(d) { emKeep(); EM.step = Math.max(0, EM.step + d); emShow(); }
// the typed values survive Back / Next
function emKeep() {
	['emsender', 'empass', 'emto', 'emhost', 'emport', 'emmode', 'emuser'].forEach(function (id) { if ($(id)) EM.acc[id] = $(id).value; });
	if ($('emrare')) EM.acc.emrare = $('emrare').checked;
	if ($('emprov')) EM.prov = $('emprov').value;
	// on the station's disk too (owner 2026-11-03), all but the password: a reload or another day finds them filled in
	var d = {prov: EM.prov, scenario: EM.scenario};
	Object.keys(EM.acc).forEach(function (k) { if (k !== 'empass') d[k] = EM.acc[k]; });
	emCall('draft', d, function () {});
}
// the boxes saved as they are filled in, and filled in again from what was saved
document.addEventListener('change', function (e) { if (EM.scenario && e.target && e.target.closest && e.target.closest('#emguide') && e.target.id !== 'empass') emKeep(); });
function emDraft() {
	var d = EM.status.draft || {}, a = {};
	['emsender', 'emto', 'emhost', 'emport', 'emmode', 'emuser'].forEach(function (k) { if (d[k]) a[k] = d[k]; });
	if (d.emrare) a.emrare = true;
	return a;
}
// the app password field: in the step where the provider shows it (owner 2026-10-22), in "The account" for other providers
function savedSenders() { return (EM.status.saved_senders || []).concat(EM.status.has_password && EM.status.sender ? [EM.status.sender] : []).filter(function (x, i, a) { return a.indexOf(x) === i; }); }
function passHtml() {
	var gm = EM.prov === 'gmail', saved = savedSenders();
	// an app password the station already has is not asked again (owner 2026-10-22)
	return '<label for="empass">' + (gm ? t('str_app_password_the_16_letters') : t('str_app_password_not_the_account_help')) + '</label><input id="empass" type="password" autocomplete="new-password" onfocus="if (this.value === EM_MASK) this.select()"'
		+ (saved.length ? ' placeholder="' + esc(t('str_empty_deletes_the_saved_one')) + '"' : '') + '>'
		+ (saved.length ? '<div class="small">' + t('str_an_app_password_is_already_help', {list: esc(saved.join(', '))}) + '<br>' + t('str_leave_the_dots_to_use_it_help') + '</div>' : '');
}
function navHtml(next) {
	return '<div class="nav"><button type="button" class="stdbtn" onclick="' + (EM.step ? 'emNav(-1)' : 'emPicker()') + '">' + t('str_back') + '</button>'
		+ (next === false ? '' : '<button type="button" class="stdbtn" id="emnext" onclick="' + (next || 'emNav(1)') + '">' + t('str_next') + '</button>') + '</div>';
}

//##############################################################################
// one step at a time
//##############################################################################
function emShow() {
	var steps = EM_STEPS[EM.scenario], name = steps[EM.step], html = '';
	html += '<div class="progress">' + steps.map(function (s, i) { return '<span class="dot' + (i <= EM.step ? ' on' : '') + '"></span>'; }).join('')
		+ ' <span>' + t('str_step_of', {n: EM.step + 1, total: steps.length}) + '</span></div>';
	if (name === 'create') {
		html += '<h3>' + t('str_create_the_gmail_account') + '</h3><ol>'
			+ '<li>' + t('str_open_and_choose_create_account_help', {link: link('https://accounts.google.com/signup')}) + '</li>'
			+ '<li>' + t('str_pick_an_address_only_for_the_help') + '</li>'
			+ '<li>' + t('str_google_asks_for_a_phone_number_help') + '</li></ol>'
			+ navHtml();
	} else if (name === 'twosv') {
		html += '<h3>' + t('str_turn_on_2_step_verification') + '</h3>'
			+ '<div>' + t('str_google_gives_app_passwords_only_help') + '</div><ol>'
			+ '<li>' + t('str_open_signed_in_with_the_account_help', {link: link('https://myaccount.google.com/signinoptions/twosv')}) + '</li>'
			+ '<li>' + t('str_turn_it_on_and_follow_googles_help') + '</li>'
			+ '<li>' + t('str_already_on_go_to_the_next_step_help') + '</li></ol>'
			+ navHtml();
	} else if (name === 'apppass') {
		html += '<h3>' + t('str_create_the_app_password') + '</h3><ol>'
			+ '<li>' + t('str_open_link', {link: link('https://myaccount.google.com/apppasswords')}) + '</li>'
			+ '<li>' + t('str_type_a_name_e_g_birdsuite_and_help') + '</li>'
			+ '<li>' + t('str_google_shows_16_letters_in_four_help') + '</li></ol>'
			+ '<div class="warn">' + t('str_this_is_not_the_account_password') + '<br>' + t('str_gmail_refuses_the_account_help') + '</div>'
			+ passHtml() + '<div class="row"><span class="msg" id="emmsg"></span></div>'
			+ navHtml('emPass()');
	} else if (name === 'provider') {
		html += '<h3>' + t('str_the_provider') + '</h3>'
			+ '<label for="emprov">' + t('str_e_mail_provider_of_the_sender_help') + '</label><select id="emprov" onchange="emProv()">'
			+ '<option value="yahoo">Yahoo</option><option value="icloud">iCloud</option><option value="zoho">Zoho</option><option value="custom">' + t('str_other_any_smtp_server') + '</option></select>'
			+ '<div id="emcustom"><label for="emhost">' + t('str_smtp_server') + '</label><input id="emhost" placeholder="smtp.example.com">'
			+ '<label for="emport">' + t('str_port') + '</label><input id="emport" type="number" min="1" max="65535" value="587">'
			+ '<label for="emmode">' + t('str_security') + '</label><select id="emmode"><option value="starttls">' + t('str_starttls_usually_port_587') + '</option><option value="ssl">' + t('str_ssl_tls_usually_port_465') + '</option></select>'
			+ '<label for="emuser">' + t('str_user_name_empty_the_sender_address') + '</label><input id="emuser"></div>'
			+ '<div class="warn" id="emprovhelp"></div>'
			+ navHtml();
	} else if (name === 'account') {
		var gm = EM.prov === 'gmail';
		html += '<h3>' + t('str_the_account') + '</h3>'
			+ '<label for="emsender">' + t('str_sender_address_the_account_that_help') + '</label><input id="emsender" type="email" placeholder="' + (gm ? 'station@gmail.com' : 'station@example.com') + '">'
			+ (EM.scenario === 'other' ? passHtml() : '')
			+ '<label for="emto">' + t('str_send_the_notifications_to') + '</label><input id="emto" type="email" placeholder="you@example.com">' + '<div class="small">' + t('str_an_alias_of_the_sender_account_help') + '</div>'
			+ '<label style="font-weight:normal"><input type="checkbox" id="emrare" style="width:auto"> ' + t('str_also_for_rare_species_the_rare_help') + '</label>'
			+ '<div class="row"><span class="msg" id="emmsg"></span></div>'
			+ navHtml('emAccount()');
	} else if (name === 'save') {
		html += '<h3>' + t('str_save_and_test') + '</h3>'
			+ '<div>' + t('str_from_colon') + ' <b>' + esc(EM.acc.emsender) + '</b><br>' + t('str_to') + ' <b>' + esc(EM.acc.emto) + '</b><br>' + t('str_rare_species') + ' ' + (EM.acc.emrare ? t('str_yes_lc') : t('str_no_lc')) + '</div>'
			+ '<div style="margin-top:6px">' + t('str_save_sends_a_test_e_mail_to_the_help') + '<br>' + t('str_notifications_start_only_after_help') + '</div>'
			+ '<div class="row"><button type="button" class="stdbtn" id="emsavebtn" onclick="emSave()">' + t('str_save_and_test') + '</button> <span class="msg" id="emmsg"></span></div>'
			+ '<div id="emarrived" style="display:none;margin-top:12px">' + t('str_did_the_test_e_mail_arrive') + '<br>' + t('str_look_in_the_spam_folder_too')
			+ '<div class="row"><button type="button" class="stdbtn" onclick="emDone()">' + t('str_yes_it_arrived') + '</button>'
			+ '<button type="button" class="stdbtn" onclick="$(\'emnotarrived\').style.display = \'\'">' + t('str_no') + '</button></div>'
			+ '<div id="emnotarrived" class="small" style="display:none;margin-top:8px">' + t('str_wait_a_minute_and_look_in_the_help') + '<br>' + t('str_if_it_is_not_there_go_back_and_help') + '</div></div>'
			+ navHtml(false);
	}
	$('emguide').style.display = '';
	$('emguide').innerHTML = html;
	Object.keys(EM.acc).forEach(function (id) { if ($(id) && id !== 'emrare') $(id).value = EM.acc[id]; });
	// a saved app password shows as dots until the box is changed (emptied = delete it, a new one = replace it)
	if ($('empass') && EM.acc.empass === undefined && savedSenders().length) { EM.acc.empass = EM_MASK; $('empass').value = EM_MASK; }
	if ($('emrare')) $('emrare').checked = !!EM.acc.emrare;
	if (name === 'provider') { $('emprov').value = EM.prov; emProv(true); }
	if (name === 'account' && !v('emto') && EM.status.recipient) $('emto').value = EM.status.recipient;
	if (name === 'account' && !v('emsender') && savedSenders().length) $('emsender').value = EM.status.sender || savedSenders()[0];
}
function emProv(keep) {
	var p = EM_PRESETS[$('emprov').value];
	$('emcustom').style.display = $('emprov').value === 'custom' ? '' : 'none';
	if ($('emprov').value !== 'custom' || !keep) { $('emhost').value = p[0]; $('emport').value = p[1]; $('emmode').value = p[2]; }
	$('emprovhelp').innerHTML = p[3] || '';
}
//##############################################################################
// the app password, checked before going on
//##############################################################################
function passOk() {
	var pw = (EM.acc.empass || '').replace(/\s+/g, '');
	if (pw === EM_MASK) return true;  // the saved one, kept
	if (!pw && !savedSenders().length) { say('emmsg', false, t('str_the_sender_account_password_an_help')); return false; }
	if (EM.prov === 'gmail' && pw && !/^[a-z]{16}$/i.test(pw)) {
		say('emmsg', false, t('str_a_google_app_password_has_16_help'));
		return false;
	}
	return true;
}
function emPass() { emKeep(); if (passOk()) emNav(1); }
//##############################################################################
// step "account": checked here before the last step
//##############################################################################
function emAccount() {
	emKeep();
	var a = EM.acc, mail = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
	if (!mail.test(a.emsender || '')) { say('emmsg', false, t('str_a_valid_sender_address_is_needed')); return; }
	if (EM.scenario === 'other' && !passOk()) return;
	if (!mail.test(a.emto || '')) { say('emmsg', false, t('str_a_valid_recipient_address_is_needed')); return; }
	emNav(1);
}
//##############################################################################
// step "save": saved, then a test e-mail; the server's answer is explained
//##############################################################################
// the app password box emptied and saved (owner 2026-11-03): the saved one of that account deleted, after a question;
// e-mail stops
function emForget(sender) {
	askModal(t('str_delete_the_saved_app_password_of', {name: sender}), t('str_e_mail_notifications_stop'), t('str_delete'), true).then(function (ok) {
		if (ok) emCall('forget', {sender: sender}, function (r) { if (r.ok) { EM.scenario = ''; EM.acc = {}; emLoad(); } else say('emmsg', false, esc(r.error)); });
	});
}
function emSave(after) {
	var a = EM.acc, p = EM.prov === 'custom' ? [a.emhost, a.emport, a.emmode] : EM_PRESETS[EM.prov];
	if ((a.empass || '') === '' && savedSenders().indexOf(a.emsender) >= 0) { emForget(a.emsender); return; }
	$('emsavebtn').disabled = true;
	$('emmsg').textContent = t('str_saving');
	emCall('set', {host: p[0], port: p[1], mode: p[2], user: EM.prov === 'custom' ? (a.emuser || '') : '', sender: a.emsender,
		password: a.empass === EM_MASK ? '' : (a.empass || '').replace(EM.prov === 'gmail' ? /\s+/g : /^$/, ''), recipient: a.emto, rare: !!a.emrare}, function (r) {
		if (!r.ok) { $('emsavebtn').disabled = false; say('emmsg', false, emWhy(r, after)); return; }
		EM.status.has_password = true;
		$('emmsg').textContent = t('str_sending');
		emCall('test', {}, function (x) {
			$('emsavebtn').disabled = false;
			if (x.ok) { say('emmsg', true, t('str_saved_test_e_mail_sent')); if ($('emarrived')) $('emarrived').style.display = ''; if (after) after(); return; }
			say('emmsg', false, emWhy(x, after));
			if (after) emLoad();
		});
	});
}
//##############################################################################
// edit what is saved (owner 2026-10-22: changing one field must not restart the guide)
//##############################################################################
function emEdit() {
	var s = EM.status, prov = Object.keys(EM_PRESETS).filter(function (k) { return EM_PRESETS[k][0] === s.host; })[0] || 'custom';
	EM.scenario = 'edit'; EM.prov = prov;
	$('emguide').style.display = '';
	$('emguide').innerHTML = '<h3>' + t('str_change_the_e_mail_setup') + '</h3>'
		+ '<label for="emprov">' + t('str_e_mail_provider_of_the_sender_help') + '</label><select id="emprov" onchange="emProv(); EM.prov = this.value; emPassLabel()">'
		+ '<option value="gmail">Gmail</option><option value="yahoo">Yahoo</option>'
		+ '<option value="icloud">iCloud</option><option value="zoho">Zoho</option><option value="custom">' + t('str_other_any_smtp_server') + '</option></select>'
		+ '<div id="emcustom"><label for="emhost">' + t('str_smtp_server') + '</label><input id="emhost" placeholder="smtp.example.com">'
		+ '<label for="emport">' + t('str_port') + '</label><input id="emport" type="number" min="1" max="65535" value="587">'
		+ '<label for="emmode">' + t('str_security') + '</label><select id="emmode"><option value="starttls">' + t('str_starttls_usually_port_587') + '</option><option value="ssl">' + t('str_ssl_tls_usually_port_465') + '</option></select>'
		+ '<label for="emuser">' + t('str_user_name_empty_the_sender_address') + '</label><input id="emuser"></div>'
		+ '<div id="emprovhelp" class="small"></div>'
		+ '<label for="emsender">' + t('str_sender_address_the_account_that_help') + '</label><input id="emsender" type="email">'
		+ '<div id="empasswrap">' + passHtml() + '</div>'
		+ '<label for="emto">' + t('str_send_the_notifications_to') + '</label><input id="emto" type="email">' + '<div class="small">' + t('str_an_alias_of_the_sender_account_help') + '</div>'
		+ '<label style="font-weight:normal"><input type="checkbox" id="emrare" style="width:auto"> ' + t('str_also_for_rare_species_the_rare_help') + '</label>'
		+ '<div class="row"><button type="button" class="stdbtn" id="emsavebtn" onclick="emEditSave()">' + t('str_save_and_test') + '</button>'
		+ '<button type="button" class="stdbtn" onclick="EM.scenario = \'\'; $(\'emguide\').style.display = \'none\'">' + t('str_cancel') + '</button> <span class="msg" id="emmsg"></span></div>'
		+ '<div class="small" style="margin-top:10px"><a href="#" onclick="emPicker(); return false">' + t('str_set_it_up_from_zero_with_the_help') + '</a></div>';
	$('emprov').value = prov; emProv(true);
	$('emhost').value = s.host || ''; $('emport').value = s.port || 587; $('emmode').value = s.mode || 'starttls';
	$('emuser').value = prov === 'custom' && s.user !== s.sender ? (s.user || '') : '';
	$('emsender').value = s.sender || ''; $('emto').value = s.recipient || ''; $('emrare').checked = !!s.rare;
}
function emPassLabel() { var keep = $('empass').value; $('empasswrap').innerHTML = passHtml(); $('empass').value = keep; }
function emEditSave() {
	emKeep();
	var a = EM.acc, mail = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
	if (!mail.test(a.emsender || '')) { say('emmsg', false, t('str_a_valid_sender_address_is_needed')); return; }
	if (!passOk()) return;
	if (!mail.test(a.emto || '')) { say('emmsg', false, t('str_a_valid_recipient_address_is_needed')); return; }
	emSave(function () { emLoad(); });
}
// the server's answer, what to do about it, and how to go on
function emWhy(x, after) {
	var hint = x.reason === 'auth' ? (EM.prov === 'gmail' ? t('str_use_the_16_letter_app_password_help')
		: t('str_check_the_user_name_and_the_app_help'))
		: x.reason === 'connect' ? t('str_check_the_server_and_the_port_help') : '';
	return esc(x.error).replace(/\n/g, '<br>') + (hint ? '<br>' + hint : '') + '<br>' + (after ? t('str_fix_it_and_save_again') : t('str_go_back_fix_it_and_save_again_help'));
}
function emDone() { emCall('confirm', {}, function () { EM.scenario = ''; $('emguide').style.display = 'none'; emLoad(); }); }

//##############################################################################
// the box on top: on or off, the test and Remove
//##############################################################################
function emTest() {
	$('emmsg0').textContent = t('str_sending');
	emCall('test', {}, function (r) {
		say('emmsg0', r.ok, r.ok ? t('str_test_sent_check_the_inbox_and_help') : esc(r.error));
		if (r.ok && $('emok0')) $('emok0').style.display = '';
	});
}
// the switch, only for a channel the user already confirmed (owner 2026-10-26); the title: set up or not, active or disabled
function emSwitch(to) { emCall(to, {}, function (r) { if (r.ok) emLoad(); else say('emmsg0', false, esc(r.error)); }); }
// a channel sends only after the user saw a test arrive; never confirmed, set up again or a failed send = disabled (2026-10-26)
function disabledHtml(p, ok) {
	if (p.reason === 'off')
		return '<div class="row" style="margin-top:.8em"><button type="button" class="stdbtn" onclick="emSwitch(\'on\')">' + t('str_turn_notifications_on') + '</button>'
			+ ' <span class="small">' + t('str_turned_off_on', {at: esc(p.at)}) + '</span></div>';
	return '<div class="box" style="margin-top:.8em;border:1px solid #e0a000">'
		+ '<b>' + t('str_disabled_nothing_is_sent_until_help') + '</b><br>'
		+ (p.reason === 'failed' ? t('str_a_send_failed_on', {at: esc(p.at)}) : t('str_the_test_was_not_confirmed_yet')) + '<br>'
		+ t('str_send_a_test_and_answer_when_it_help')
		+ '<div id="' + ok + '" style="display:none;margin-top:8px">' + t('str_did_the_test_arrive')
		+ ' <button type="button" class="stdbtn" onclick="' + (ok === 'emok0' ? 'emDone()' : 'tgDone()') + '">' + t('str_yes_it_arrived') + '</button></div></div>';
}
function emLoad() {
	var x = new XMLHttpRequest();
	x.onload = function () {
		var s; try { s = JSON.parse(this.responseText); } catch (e) { return; }
		EM.status = s;
		var via = s.local ? t('str_this_stations_own_mail_server_help', {port: esc(s.port)}) : esc(s.host) + ':' + esc(s.port) + ' (' + esc(s.mode) + ')';
		$('emstate').innerHTML = s.configured
			? '<span style="font-size:1.1em">&#9993; ' + (s.disabled ? t('str_e_mail_disabled_help') : t('str_e_mail_active_help')) + '</span>'
				+ '<div style="margin-top:.8em">' + t('str_from_colon') + ' <b>' + esc(s.sender) + '</b>'
				+ '<br>' + t('str_to') + ' <b>' + esc(s.recipient) + '</b>'
				+ '<br>' + t('str_via') + ' ' + via
				+ '</div><div style="margin-top:.8em">' + t('str_rare_species') + ' ' + (s.rare ? t('str_yes_lc') : t('str_no_lc')) + '</div>'
				+ '<div class="row" style="margin-top:.8em"><button type="button" class="stdbtn" onclick="emTest()">' + t('str_send_a_test_e_mail') + '</button> <span class="msg" id="emmsg0"></span></div>'
				+ (s.disabled ? disabledHtml(s.disabled, 'emok0') : '<div class="row" style="margin-top:.8em"><button type="button" class="stdbtn" onclick="emSwitch(\'off\')">' + t('str_turn_notifications_off') + '</button></div>')
				+ '<div class="row" style="margin-top:10px"><button type="button" class="stdbtn" onclick="emRestart()" title="' + esc(t('str_restart_the_setup_help')) + '">' + t('str_restart_the_setup') + '</button>'
				+ ' <a href="#" class="small" onclick="emEdit(); return false">' + t('str_change_the_e_mail_setup') + '</a></div>'
				+ '<div class="small" style="margin-top:.8em">' + t('str_e_mail_is_already_set_up_remove_help') + '</div>'
			: '<span style="font-size:1.1em">&#9993; ' + t('str_e_mail_notifications_are_not_help') + '</span>'
				+ (s.other_email_line ? '<div class="small">' + t('str_the_notification_box_of_help') + '</div>' : '');
		// always in sight, set up or not (owner 2026-10-26)
		$('emstate').innerHTML += '<div class="small" style="margin-top:.8em">' + t('str_notifications_start_only_after_help') + '</div>';
		if (s.configured) { if (!EM.scenario) $('emguide').style.display = 'none'; }
		else if (!EM.scenario) emPicker();
	};
	x.open('GET', 'scripts/email_settings.php?status=1', true); x.send();
}
emLoad();
</script>
