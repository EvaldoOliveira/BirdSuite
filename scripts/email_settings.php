<?php
/* E-mail (owner 2026-10-20; one guide per scenario 2026-10-22): e-mail notifications through Apprise. A station has no
 * mail server of its own, so the e-mail goes out through a provider's SMTP with the sender account's app password. When
 * e-mail is off the page asks which account sends (a Gmail, a new Gmail for the station, another provider) and
 * walks one step at a time: 2-Step Verification, the app password, the account, then save and test (the test logs in to
 * the server first, so a refused password is said plainly). The secrets go to the station's token store
 * (scripts/email_setup.py, run as the station user); the notification boxes of Settings keep only placeholders. The
 * recipient is NOTIFICATION_EMAIL ($email). Opened as views.php?view=Email. */
require_once __DIR__ . '/common.php';

function em_run($action, $stdin = '') {
  $cmd = station_prefix() . '' . escapeshellarg(get_home() . '/BirdNET-Pi/birdnet/bin/python3') . ' '
    . escapeshellarg(__DIR__ . '/email_setup.py') . ' ' . escapeshellarg($action);
  $p = proc_open($cmd, array(0 => array('pipe', 'r'), 1 => array('pipe', 'w'), 2 => array('pipe', 'w')), $pipes);
  if (!is_resource($p)) return array('ok' => false, 'error' => 'cannot run email_setup.py');
  fwrite($pipes[0], $stdin);
  fclose($pipes[0]);
  $out = stream_get_contents($pipes[1]);
  fclose($pipes[1]); fclose($pipes[2]);
  proc_close($p);
  return json_decode((string)$out, true) ?: array('ok' => false, 'error' => trim((string)$out) ?: t('no answer'));
}

if (isset($_GET['status'])) {
  header('Content-Type: application/json');
  $s = em_run('status');
  $s['recipient'] = get_config()['NOTIFICATION_EMAIL'] ?? '';
  // the accounts used (login names, every saved sender) only for a logged-in reviewer (owner 2026-10-22)
  if (!is_authenticated()) { unset($s['user'], $s['saved_senders']); $s['recipient'] = $s['recipient'] !== '' ? '…' : ''; }
  echo json_encode($s);
  die();
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_GET['do'])) {
  ensure_authenticated(t('You must be authenticated to change the e-mail settings.'));
  header('Content-Type: application/json');
  $p = json_decode((string)file_get_contents('php://input'), true) ?: array();
  if ($_GET['do'] === 'set') {
    $to = trim((string)($p['recipient'] ?? ''));
    if (!preg_match('/^[^\s"\'`$\\\\@]+@[^\s"\'`$\\\\@]+\.[^\s"\'`$\\\\@]+$/', $to)) { echo json_encode(array('ok' => false, 'error' => t('a valid recipient address is needed'))); die(); }
    $r = em_run('set', json_encode(array('host' => $p['host'] ?? '', 'port' => $p['port'] ?? '', 'mode' => $p['mode'] ?? '', 'sender' => $p['sender'] ?? '',
      'user' => $p['user'] ?? '', 'password' => $p['password'] ?? '', 'rare' => !empty($p['rare']))));
    if (!empty($r['ok'])) {
      $cf = BS_CONF;
      $c = file_get_contents($cf);
      $c = preg_match('/^NOTIFICATION_EMAIL=/m', $c) ? preg_replace('/^NOTIFICATION_EMAIL=.*/m', 'NOTIFICATION_EMAIL="' . $to . '"', $c) : $c . "\nNOTIFICATION_EMAIL=\"$to\"\n";
      if (file_put_contents($cf, $c) === false) $r = array('ok' => false, 'error' => t('cannot write the recipient to BirdSuite.conf'));
    }
    echo json_encode($r);
  } elseif ($_GET['do'] === 'remove') {
    echo json_encode(em_run('remove'));
  } elseif ($_GET['do'] === 'test') {
    echo json_encode(em_run('test'));
  } elseif ($_GET['do'] === 'confirm') {
    echo json_encode(em_run('confirm'));
  } elseif ($_GET['do'] === 'on' || $_GET['do'] === 'off') {
    echo json_encode(em_run($_GET['do']));
  } else {
    echo json_encode(array('ok' => false, 'error' => t('unknown action')));
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
  <div class="box" id="emstate"><?php echo t('Reading…'); ?></div>
  <?php // the guided setup when e-mail is off, after Remove or on "Change the e-mail setup" (owner 2026-10-22: one guide per scenario) ?>
  <div class="box" id="emguide" style="display:none"></div>
</div>
<script>
var esc = function (s) { return String(s == null ? '' : s).replace(/[&<>"]/g, function (c) { return {'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;'}[c]; }); };
function $(id) { return document.getElementById(id); }
function v(id) { var e = $(id); return e ? e.value.trim() : ''; }
var EM_PRESETS = {
  gmail: ['smtp.gmail.com', 587, 'starttls'],
  yahoo: ['smtp.mail.yahoo.com', 465, 'ssl', t('Yahoo needs an <b>app password</b> (Account Security › Generate app password).')],
  icloud: ['smtp.mail.me.com', 587, 'starttls', t('iCloud needs an <b>app-specific password</b> (appleid.apple.com › Sign-In and Security).')],
  zoho: ['smtp.zoho.com', 465, 'ssl', t('Zoho: an application-specific password when 2-factor is on.')],
  custom: ['', 587, 'starttls', t('The SMTP server of your provider or organisation.')]
};
// the steps of each scenario (owner 2026-10-22): a Gmail account, a new Gmail only for the station, any other provider. Outlook was
// taken out the same day: personal Outlook.com accounts refuse both the account password and an app password (535 5.7.3)
var EM_STEPS = {gmail: ['twosv', 'apppass', 'account', 'save'], newgmail: ['create', 'twosv', 'apppass', 'account', 'save'], other: ['provider', 'account', 'save']};
var EM = {status: {}, scenario: '', step: 0, acc: {}, prov: 'gmail'};

function emCall(what, body, done) {
  var x = new XMLHttpRequest();
  x.onload = function () {
    var r; try { r = JSON.parse(this.responseText); } catch (e) { r = {ok: false, error: this.status === 401 ? t('Log in first.') : this.responseText}; }
    done(r);
  };
  x.open('POST', 'scripts/email_settings.php?do=' + what, true); x.setRequestHeader('Content-Type', 'application/json'); x.send(JSON.stringify(body || {}));
}
function say(id, ok, text) { var m = $(id); if (m) m.innerHTML = '<span class="' + (ok ? 'ok' : 'err') + '">' + (ok ? '&#10003; ' : '') + text + '</span>'; }
function link(url, text) { return '<a href="' + url + '" target="_blank" rel="noopener">' + (text || url.replace(/^https:\/\//, '')) + '</a>'; }

// ---- the picker: which account sends ----
function emPicker() {
  EM.scenario = ''; EM.step = 0; EM.acc = {};
  $('emguide').style.display = '';
  $('emguide').innerHTML = '<h3>' + t('Which e-mail account will send the notifications?') + '</h3>'
    + '<div class="cards">'
    + '<button type="button" class="card" onclick="emStart(\'gmail\')"><b>' + t('I have a Gmail account') + '</b>' + t('Gmail or Google Workspace.') + '<br>' + t('Recommended.') + '</button>'
    + '<button type="button" class="card" onclick="emStart(\'newgmail\')"><b>' + t('A new Gmail for the station') + '</b>' + t('An account used only to send the station\'s e-mails.') + '</button>'
    + '<button type="button" class="card" onclick="emStart(\'other\')"><b>' + t('Another provider') + '</b>' + t('Yahoo, iCloud, Zoho or any SMTP server.') + '</button>'
    + '</div>'
    + '<div style="margin-top:10px">' + t('<b>Only tested with Gmail.</b>') + '<br>' + t('Feedback about other providers is welcome.') + '</div>'
    + '<div class="small" style="margin-top:10px">' + t('A station has no mail server of its own: the e-mails go out through the account you choose here.') + '<br>'
    + t('Without internet the notifications wait in a queue and go out when the station is online again.') + '</div>';
}
function emStart(s) { EM.scenario = s; EM.step = 0; EM.prov = s === 'other' ? 'yahoo' : 'gmail'; emShow(); }
function emNav(d) { emKeep(); EM.step = Math.max(0, EM.step + d); emShow(); }
// the typed values survive Back / Next
function emKeep() {
  ['emsender', 'empass', 'emto', 'emhost', 'emport', 'emmode', 'emuser'].forEach(function (id) { if ($(id)) EM.acc[id] = $(id).value; });
  if ($('emrare')) EM.acc.emrare = $('emrare').checked;
  if ($('emprov')) EM.prov = $('emprov').value;
}
// the app password field: in the step where the provider shows it (owner 2026-10-22), in "The account" for other providers
function savedSenders() { return (EM.status.saved_senders || []).concat(EM.status.has_password && EM.status.sender ? [EM.status.sender] : []).filter(function (x, i, a) { return a.indexOf(x) === i; }); }
function passHtml() {
  var gm = EM.prov === 'gmail', saved = savedSenders();
  // an app password the station already has is not asked again (owner 2026-10-22)
  return '<label for="empass">' + (gm ? t('App password: the 16 letters') : t('App password (not the account password)')) + '</label><input id="empass" type="password" autocomplete="new-password"'
    + (saved.length ? ' placeholder="' + esc(t('leave empty to keep the saved one')) + '"' : '') + '>'
    + (saved.length ? '<div class="small">' + t('An app password is already saved for: {list}.', {list: esc(saved.join(', '))}) + '<br>' + t('Leave the field empty to use it.') + '</div>' : '');
}
function navHtml(next) {
  return '<div class="nav"><button type="button" class="stdbtn" onclick="' + (EM.step ? 'emNav(-1)' : 'emPicker()') + '">' + t('Back') + '</button>'
    + (next === false ? '' : '<button type="button" class="stdbtn" id="emnext" onclick="' + (next || 'emNav(1)') + '">' + t('Next') + '</button>') + '</div>';
}

// ---- one step at a time ----
function emShow() {
  var steps = EM_STEPS[EM.scenario], name = steps[EM.step], html = '';
  html += '<div class="progress">' + steps.map(function (s, i) { return '<span class="dot' + (i <= EM.step ? ' on' : '') + '"></span>'; }).join('')
    + ' <span>' + t('Step {n} of {total}', {n: EM.step + 1, total: steps.length}) + '</span></div>';
  if (name === 'create') {
    html += '<h3>' + t('Create the Gmail account') + '</h3><ol>'
      + '<li>' + t('Open {link} and choose <b>Create account</b> › <b>For my personal use</b>.', {link: link('https://accounts.google.com/signup')}) + '</li>'
      + '<li>' + t('Pick an address only for the station, e.g. <i>birdsuite.home@gmail.com</i>.') + '</li>'
      + '<li>' + t('Google asks for a phone number: it is needed for the next step too.') + '</li></ol>'
      + navHtml();
  } else if (name === 'twosv') {
    html += '<h3>' + t('Turn on 2-Step Verification') + '</h3>'
      + '<div>' + t('Google gives app passwords only to accounts with 2-Step Verification on.') + '</div><ol>'
      + '<li>' + t('Open {link} signed in with the account that will send.', {link: link('https://myaccount.google.com/signinoptions/twosv')}) + '</li>'
      + '<li>' + t('Turn it on and follow Google\'s steps (a code by SMS or the Google app).') + '</li>'
      + '<li>' + t('Already on? Go to the next step.') + '</li></ol>'
      + navHtml();
  } else if (name === 'apppass') {
    html += '<h3>' + t('Create the app password') + '</h3><ol>'
      + '<li>' + t('Open {link}.', {link: link('https://myaccount.google.com/apppasswords')}) + '</li>'
      + '<li>' + t('Type a name, e.g. <i>BirdSuite</i>, and press <b>Create</b>.') + '</li>'
      + '<li>' + t('Google shows <b>16 letters</b> in four groups: copy them (the spaces do not matter).') + '</li></ol>'
      + '<div class="warn">' + t('This is <b>not</b> the account password.') + '<br>' + t('Gmail refuses the account password here; only the 16 letters work.') + '</div>'
      + passHtml() + '<div class="row"><span class="msg" id="emmsg"></span></div>'
      + navHtml('emPass()');
  } else if (name === 'provider') {
    html += '<h3>' + t('The provider') + '</h3>'
      + '<label for="emprov">' + t('E-mail provider of the sender account') + '</label><select id="emprov" onchange="emProv()">'
      + '<option value="yahoo">Yahoo</option><option value="icloud">iCloud</option><option value="zoho">Zoho</option><option value="custom">' + t('Other (any SMTP server)') + '</option></select>'
      + '<div id="emcustom"><label for="emhost">' + t('SMTP server') + '</label><input id="emhost" placeholder="smtp.example.com">'
      + '<label for="emport">' + t('Port') + '</label><input id="emport" type="number" min="1" max="65535" value="587">'
      + '<label for="emmode">' + t('Security') + '</label><select id="emmode"><option value="starttls">' + t('STARTTLS (usually port 587)') + '</option><option value="ssl">' + t('SSL/TLS (usually port 465)') + '</option></select>'
      + '<label for="emuser">' + t('User name (empty = the sender address)') + '</label><input id="emuser"></div>'
      + '<div class="warn" id="emprovhelp"></div>'
      + navHtml();
  } else if (name === 'account') {
    var gm = EM.prov === 'gmail';
    html += '<h3>' + t('The account') + '</h3>'
      + '<label for="emsender">' + t('Sender address (the account that sends)') + '</label><input id="emsender" type="email" placeholder="' + (gm ? 'station@gmail.com' : 'station@example.com') + '">'
      + (EM.scenario === 'other' ? passHtml() : '')
      + '<label for="emto">' + t('Send the notifications to') + '</label><input id="emto" type="email" placeholder="you@example.com">' + '<div class="small">' + t('An alias of the sender account may never reach the inbox: Gmail keeps such an e-mail in Sent only.') + '</div>'
      + '<label style="font-weight:normal"><input type="checkbox" id="emrare" style="width:auto"> ' + t('also for rare species (the Prio notification box)') + '</label>'
      + '<div class="row"><span class="msg" id="emmsg"></span></div>'
      + navHtml('emAccount()');
  } else if (name === 'save') {
    html += '<h3>' + t('Save and test') + '</h3>'
      + '<div>' + t('From:') + ' <b>' + esc(EM.acc.emsender) + '</b><br>' + t('To:') + ' <b>' + esc(EM.acc.emto) + '</b><br>' + t('Rare species:') + ' ' + (EM.acc.emrare ? t('yes') : t('no')) + '</div>'
      + '<div style="margin-top:6px">' + t('Save sends a test e-mail to the address above.') + '<br>' + t('Notifications start only after you answer that the test arrived.') + '</div>'
      + '<div class="row"><button type="button" class="stdbtn" id="emsavebtn" onclick="emSave()">' + t('Save and test') + '</button> <span class="msg" id="emmsg"></span></div>'
      + '<div id="emarrived" style="display:none;margin-top:12px">' + t('Did the test e-mail arrive?') + '<br>' + t('Look in the spam folder too.')
      + '<div class="row"><button type="button" class="stdbtn" onclick="emDone()">' + t('Yes, it arrived') + '</button>'
      + '<button type="button" class="stdbtn" onclick="$(\'emnotarrived\').style.display = \'\'">' + t('No') + '</button></div>'
      + '<div id="emnotarrived" class="small" style="display:none;margin-top:8px">' + t('Wait a minute and look in the spam folder.') + '<br>' + t('If it is not there, go back and check the recipient address.') + '</div></div>'
      + navHtml(false);
  }
  $('emguide').style.display = '';
  $('emguide').innerHTML = html;
  Object.keys(EM.acc).forEach(function (id) { if ($(id) && id !== 'emrare') $(id).value = EM.acc[id]; });
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
// ---- the app password, checked before going on ----
function passOk() {
  var pw = (EM.acc.empass || '').replace(/\s+/g, '');
  if (!pw && !savedSenders().length) { say('emmsg', false, t('the sender account password (an app password) is needed')); return false; }
  if (EM.prov === 'gmail' && pw && !/^[a-z]{16}$/i.test(pw)) {
    say('emmsg', false, t('A Google app password has 16 letters; this looks like the account password. Create one at myaccount.google.com/apppasswords and paste it.'));
    return false;
  }
  return true;
}
function emPass() { emKeep(); if (passOk()) emNav(1); }
// ---- step "account": checked here before the last step ----
function emAccount() {
  emKeep();
  var a = EM.acc, mail = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
  if (!mail.test(a.emsender || '')) { say('emmsg', false, t('a valid sender address is needed')); return; }
  if (EM.scenario === 'other' && !passOk()) return;
  if (!mail.test(a.emto || '')) { say('emmsg', false, t('a valid recipient address is needed')); return; }
  emNav(1);
}
// ---- step "save": saved, then a test e-mail; the server's answer is explained ----
function emSave(after) {
  var a = EM.acc, p = EM.prov === 'custom' ? [a.emhost, a.emport, a.emmode] : EM_PRESETS[EM.prov];
  $('emsavebtn').disabled = true;
  $('emmsg').textContent = t('Saving…');
  emCall('set', {host: p[0], port: p[1], mode: p[2], user: EM.prov === 'custom' ? (a.emuser || '') : '', sender: a.emsender,
    password: (a.empass || '').replace(EM.prov === 'gmail' ? /\s+/g : /^$/, ''), recipient: a.emto, rare: !!a.emrare}, function (r) {
    if (!r.ok) { $('emsavebtn').disabled = false; say('emmsg', false, emWhy(r, after)); return; }
    EM.status.has_password = true;
    $('emmsg').textContent = t('Sending…');
    emCall('test', {}, function (x) {
      $('emsavebtn').disabled = false;
      if (x.ok) { say('emmsg', true, t('Saved, test e-mail sent')); if ($('emarrived')) $('emarrived').style.display = ''; if (after) after(); return; }
      say('emmsg', false, emWhy(x, after));
      if (after) emLoad();
    });
  });
}
// ---- edit what is saved (owner 2026-10-22: changing one field must not restart the guide) ----
function emEdit() {
  var s = EM.status, prov = Object.keys(EM_PRESETS).filter(function (k) { return EM_PRESETS[k][0] === s.host; })[0] || 'custom';
  EM.scenario = 'edit'; EM.prov = prov;
  $('emguide').style.display = '';
  $('emguide').innerHTML = '<h3>' + t('Change the e-mail setup') + '</h3>'
    + '<label for="emprov">' + t('E-mail provider of the sender account') + '</label><select id="emprov" onchange="emProv(); EM.prov = this.value; emPassLabel()">'
    + '<option value="gmail">Gmail</option><option value="yahoo">Yahoo</option>'
    + '<option value="icloud">iCloud</option><option value="zoho">Zoho</option><option value="custom">' + t('Other (any SMTP server)') + '</option></select>'
    + '<div id="emcustom"><label for="emhost">' + t('SMTP server') + '</label><input id="emhost" placeholder="smtp.example.com">'
    + '<label for="emport">' + t('Port') + '</label><input id="emport" type="number" min="1" max="65535" value="587">'
    + '<label for="emmode">' + t('Security') + '</label><select id="emmode"><option value="starttls">' + t('STARTTLS (usually port 587)') + '</option><option value="ssl">' + t('SSL/TLS (usually port 465)') + '</option></select>'
    + '<label for="emuser">' + t('User name (empty = the sender address)') + '</label><input id="emuser"></div>'
    + '<div id="emprovhelp" class="small"></div>'
    + '<label for="emsender">' + t('Sender address (the account that sends)') + '</label><input id="emsender" type="email">'
    + '<div id="empasswrap">' + passHtml() + '</div>'
    + '<label for="emto">' + t('Send the notifications to') + '</label><input id="emto" type="email">' + '<div class="small">' + t('An alias of the sender account may never reach the inbox: Gmail keeps such an e-mail in Sent only.') + '</div>'
    + '<label style="font-weight:normal"><input type="checkbox" id="emrare" style="width:auto"> ' + t('also for rare species (the Prio notification box)') + '</label>'
    + '<div class="row"><button type="button" class="stdbtn" id="emsavebtn" onclick="emEditSave()">' + t('Save and test') + '</button>'
    + '<button type="button" class="stdbtn" onclick="EM.scenario = \'\'; $(\'emguide\').style.display = \'none\'">' + t('Cancel') + '</button> <span class="msg" id="emmsg"></span></div>'
    + '<div class="small" style="margin-top:10px"><a href="#" onclick="emPicker(); return false">' + t('Set it up from zero with the guide') + '</a></div>';
  $('emprov').value = prov; emProv(true);
  $('emhost').value = s.host || ''; $('emport').value = s.port || 587; $('emmode').value = s.mode || 'starttls';
  $('emuser').value = prov === 'custom' && s.user !== s.sender ? (s.user || '') : '';
  $('emsender').value = s.sender || ''; $('emto').value = s.recipient || ''; $('emrare').checked = !!s.rare;
}
function emPassLabel() { var keep = $('empass').value; $('empasswrap').innerHTML = passHtml(); $('empass').value = keep; }
function emEditSave() {
  emKeep();
  var a = EM.acc, mail = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
  if (!mail.test(a.emsender || '')) { say('emmsg', false, t('a valid sender address is needed')); return; }
  if (!passOk()) return;
  if (!mail.test(a.emto || '')) { say('emmsg', false, t('a valid recipient address is needed')); return; }
  emSave(function () { emLoad(); });
}
// the server's answer, what to do about it, and how to go on
function emWhy(x, after) {
  var hint = x.reason === 'auth' ? (EM.prov === 'gmail' ? t('Use the 16-letter app password, not the account password.')
    : t('Check the user name and the app password.'))
    : x.reason === 'connect' ? t('Check the server and the port, and that the station is online.') : '';
  return esc(x.error).replace(/\n/g, '<br>') + (hint ? '<br>' + hint : '') + '<br>' + (after ? t('Fix it and save again.') : t('Go back, fix it and save again.'));
}
function emDone() { emCall('confirm', {}, function () { EM.scenario = ''; $('emguide').style.display = 'none'; emLoad(); }); }

// ---- the box on top: on or off, the test and Remove ----
function emTest() {
  $('emmsg0').textContent = t('Sending…');
  emCall('test', {}, function (r) {
    say('emmsg0', r.ok, r.ok ? t('Test sent: check the inbox (and the spam folder)') : esc(r.error));
    if (r.ok && $('emok0')) $('emok0').style.display = '';
  });
}
// the switch, only for a channel the user already confirmed (owner 2026-10-26); the title: set up or not, active or disabled
function emSwitch(to) { emCall(to, {}, function (r) { if (r.ok) emLoad(); else say('emmsg0', false, esc(r.error)); }); }
// a channel sends only after the user saw a test arrive; never confirmed, set up again or a failed send = disabled (2026-10-26)
function disabledHtml(p, ok) {
  if (p.reason === 'off')
    return '<div class="row" style="margin-top:.8em"><button type="button" class="stdbtn" onclick="emSwitch(\'on\')">' + t('Turn notifications on') + '</button>'
      + ' <span class="small">' + t('Turned off on {at}.', {at: esc(p.at)}) + '</span></div>';
  return '<div class="box" style="margin-top:.8em;border:1px solid #e0a000">'
    + '<b>' + t('Disabled: nothing is sent until you confirm a test.') + '</b><br>'
    + (p.reason === 'failed' ? t('A send failed on {at}.', {at: esc(p.at)}) : t('The test was not confirmed yet.')) + '<br>'
    + t('Send a test and answer when it arrives.')
    + '<div id="' + ok + '" style="display:none;margin-top:8px">' + t('Did the test arrive?')
    + ' <button type="button" class="stdbtn" onclick="' + (ok === 'emok0' ? 'emDone()' : 'tgDone()') + '">' + t('Yes, it arrived') + '</button></div></div>';
}
function emLoad() {
  var x = new XMLHttpRequest();
  x.onload = function () {
    var s; try { s = JSON.parse(this.responseText); } catch (e) { return; }
    EM.status = s;
    var via = s.local ? t('this station\'s own mail server (localhost:{port}, set up by the system)', {port: esc(s.port)}) : esc(s.host) + ':' + esc(s.port) + ' (' + esc(s.mode) + ')';
    $('emstate').innerHTML = s.configured
      ? '<span style="font-size:1.1em">&#9993; ' + (s.disabled ? t('E-mail notifications are <b>set up</b> and <b>disabled</b>') : t('E-mail notifications are <b>set up</b> and <b>active</b>')) + '</span>'
        + '<div style="margin-top:.8em">' + t('From:') + ' <b>' + esc(s.sender) + '</b>'
        + '<br>' + t('To:') + ' <b>' + esc(s.recipient) + '</b>'
        + '<br>' + t('Via:') + ' ' + via
        + '</div><div style="margin-top:.8em">' + t('Rare species:') + ' ' + (s.rare ? t('yes') : t('no')) + '</div>'
        + '<div class="row" style="margin-top:.8em"><button type="button" class="stdbtn" onclick="emTest()">' + t('Send a test e-mail') + '</button> <span class="msg" id="emmsg0"></span></div>'
        + (s.disabled ? disabledHtml(s.disabled, 'emok0') : '<div class="row" style="margin-top:.8em"><button type="button" class="stdbtn" onclick="emSwitch(\'off\')">' + t('Turn notifications off') + '</button></div>')
        + '<div class="row" style="margin-top:10px"><button type="button" class="stdbtn del" onclick="emRemove()">' + t('Remove') + '</button>'
        + ' <a href="#" class="small" onclick="emEdit(); return false">' + t('Change the e-mail setup') + '</a></div>'
        + '<div class="small" style="margin-top:.8em">' + t('E-mail is already set up: Remove only if it stopped working; then set it up again in the form that appears.') + '</div>'
      : '<span style="font-size:1.1em">&#9993; ' + t('E-mail notifications are <b>not set up</b>') + '</span>'
        + (s.other_email_line ? '<div class="small">' + t('The notification box of Settings has its own e-mail line, which is kept as it is.') + '</div>' : '');
    // always in sight, set up or not (owner 2026-10-26)
    $('emstate').innerHTML += '<div class="small" style="margin-top:.8em">' + t('Notifications start only after you answer that the test arrived.') + '</div>';
    if (s.configured) { if (!EM.scenario) $('emguide').style.display = 'none'; }
    else if (!EM.scenario) emPicker();
  };
  x.open('GET', 'scripts/email_settings.php?status=1', true); x.send();
}
function emRemove() {
  if (confirm(t('E-mail is set up and working.') + '\n' + t('Remove it only if it stopped working: the e-mails stop until it is set up again.')))
    emCall('remove', {}, function (r) { if (r.ok) { EM.scenario = ''; emLoad(); } else say('emmsg0', false, esc(r.error)); });
}
emLoad();
</script>
