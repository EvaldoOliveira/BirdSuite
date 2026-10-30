<?php
/* Telegram (owner 2026-10-20; one guide per scenario 2026-10-22): the two Telegram groups — Normal (every notified
 * detection) and Prio (the rare tier) — with one bot. When Telegram is off the page asks what the user already has
 * (nothing / a bot / a bot and groups) and walks through the steps one at a time: create the bot at @BotFather, the
 * token (checked with getMe), the groups (the station watches the bot and lists each group as soon as the bot is in it;
 * the ids can be typed instead), then save and test each group. Secrets go to the station's token store through
 * scripts/telegram_setup.py (run as the station user); the notification boxes of Settings keep placeholders only.
 * Opened as views.php?view=Telegram. */
require_once __DIR__ . '/common.php';

function tg_run($args, $stdin = '') {
  $cmd = station_prefix() . '' . escapeshellarg(get_home() . '/BirdNET-Pi/birdnet/bin/python3') . ' ' . escapeshellarg(__DIR__ . '/telegram_setup.py');
  foreach ($args as $a) $cmd .= ' ' . escapeshellarg((string)$a);
  $p = proc_open($cmd, array(0 => array('pipe', 'r'), 1 => array('pipe', 'w'), 2 => array('pipe', 'w')), $pipes);
  if (!is_resource($p)) return array('ok' => false, 'error' => 'cannot run telegram_setup.py');
  fwrite($pipes[0], $stdin);
  fclose($pipes[0]);
  $out = stream_get_contents($pipes[1]);
  fclose($pipes[1]); fclose($pipes[2]);
  proc_close($p);
  return json_decode((string)$out, true) ?: array('ok' => false, 'error' => trim((string)$out) ?: t('no answer'));
}

if (isset($_GET['status'])) { header('Content-Type: application/json'); echo json_encode(tg_run(array('status'))); die(); }
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_GET['do'])) {
  ensure_authenticated(t('You must be authenticated to change the Telegram settings.'));
  header('Content-Type: application/json');
  $p = json_decode((string)file_get_contents('php://input'), true) ?: array();
  switch ($_GET['do']) {
    case 'bot': echo json_encode(tg_run(array('bot'), trim((string)($p['token'] ?? '')) . "\n")); break;
    case 'chats': echo json_encode(tg_run(array('chats'))); break;
    case 'groups': echo json_encode(tg_run(array('groups', (string)($p['normal'] ?? ''), (string)(($p['prio'] ?? '') !== '' ? $p['prio'] : '-')))); break;
    case 'test': echo json_encode(tg_run(array('test', ($p['which'] ?? '') === 'prio' ? 'prio' : 'normal'))); break;
    case 'remove': echo json_encode(tg_run(array('remove'))); break;
    case 'confirm': echo json_encode(tg_run(array('confirm'))); break;
    case 'on': case 'off': echo json_encode(tg_run(array($_GET['do']))); break;
    default: echo json_encode(array('ok' => false, 'error' => t('unknown action')));
  }
  die();
}
?>
<style>
.tg { max-width: 1000px; margin: 0; text-align: left; padding: 0 12px; box-sizing: border-box; }
.tg .box { padding: 12px 14px; margin: 10px 0; }
.tg h3 { margin: 0 0 6px; font-size: 16px; }
.tg ol { margin: 6px 0 6px 18px; padding: 0; font-size: 14px; }
.tg ol li { margin: 3px 0; }
.tg input, .tg select { padding: 5px 8px; border-radius: 6px; border: 1px solid #bbb; width: 100%; max-width: 420px; box-sizing: border-box; }
.tg label { display: block; margin: 8px 0 2px; font-weight: 600; }
.tg .row { display: flex; flex-wrap: wrap; gap: 8px; align-items: center; margin-top: 10px; }
.tg .stdbtn.del { color: #c62828 !important; border-color: #c62828 !important; }
.tg .stdbtn.del:hover { background: #c62828 !important; color: #fff !important; }
.tg .small { font-size: 12px; opacity: .8; }
.tg .msg { font-weight: 600; }
.tg .ok { color: #2e7d32; } .tg .err { color: #c62828; }
.tg .cards { display: flex; flex-wrap: wrap; gap: 10px; margin-top: 8px; }
.tg .card { flex: 1 1 220px; min-width: 0; white-space: normal !important; text-align: left; padding: 12px; border-radius: 10px; border: 2px solid rgba(0,0,0,.12); background: transparent; cursor: pointer; color: inherit; font: inherit; font-weight: normal !important; }
.tg .card:hover { border-color: var(--accent,#2b5e22); }
.tg .card b { display: block; overflow-wrap: anywhere; margin-bottom: 4px; font-size: 15px; }
.tg .progress { display: flex; gap: 6px; align-items: center; margin-bottom: 8px; font-size: 13px; opacity: .85; }
.tg .dot { width: 10px; height: 10px; border-radius: 50%; background: rgba(0,0,0,.18); }
.tg .dot.on { background: var(--accent,#2b5e22); }
.tg .found { margin: 8px 0; padding: 0; list-style: none; }
.tg .found li { margin: 3px 0; }
.tg .watch { margin-top: 8px; font-size: 14px; }
.tg .nav { display: flex; gap: 8px; margin-top: 14px; }
</style>
<div class="tg">
  <div class="box" id="tgstate"><?php echo t('Reading…'); ?></div>
  <?php // the guided setup when Telegram is off, after Remove or on "Change the bot or the groups" (owner 2026-10-22: one guide per scenario) ?>
  <div class="box" id="tgguide" style="display:none"></div>
</div>
<script>
var esc = function (s) { return String(s == null ? '' : s).replace(/[&<>"]/g, function (c) { return {'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;'}[c]; }); };
function $(id) { return document.getElementById(id); }
function v(id) { var e = $(id); return e ? e.value.trim() : ''; }
var TG = {status: {}, scenario: '', step: 0, chats: [], watch: null, conflict: false};
// the steps of each scenario (owner 2026-10-22): from zero, a bot of one's own with new groups, a bot with groups that exist
var TG_STEPS = {zero: ['create', 'token', 'groups', 'save'], bot: ['token', 'groups', 'save'], have: ['token', 'groups', 'save']};

function tgCall(what, body, done) {
  var x = new XMLHttpRequest();
  x.onload = function () {
    var r; try { r = JSON.parse(this.responseText); } catch (e) { r = {ok: false, error: this.status === 401 ? t('Log in first.') : this.responseText}; }
    done(r);
  };
  x.open('POST', 'scripts/telegram_settings.php?do=' + what, true); x.setRequestHeader('Content-Type', 'application/json'); x.send(JSON.stringify(body || {}));
}
function say(id, ok, text) { var m = $(id); if (m) m.innerHTML = '<span class="' + (ok ? 'ok' : 'err') + '">' + (ok ? '&#10003; ' : '') + text + '</span>'; }
function botName() { return TG.status.bot_name || ''; }
function botUser() { return botName().replace(/^@/, ''); }

// ---- the picker: what the user already has ----
function tgPicker() {
  tgStopWatch();
  TG.scenario = ''; TG.step = 0;
  $('tgguide').style.display = '';
  $('tgguide').innerHTML = '<h3>' + t('How do you want to set up Telegram?') + '</h3>'
    + '<div class="cards">'
    + '<button type="button" class="card" onclick="tgStart(\'zero\')"><b>' + t('Start from zero') + '</b>' + t('I need a bot and groups.') + '<br>' + t('Recommended for a new station.') + '</button>'
    + '<button type="button" class="card" onclick="tgStart(\'bot\')"><b>' + t('I have a bot') + '</b>' + t('I have its token and will create new groups.') + '</button>'
    + '<button type="button" class="card" onclick="tgStart(\'have\')"><b>' + t('I have a bot and groups') + '</b>' + t('I will connect the station to groups that already exist.') + '</button>'
    + '</div>'
    + '<div class="small" style="margin-top:10px">' + t('Normal gets the clip and the text of each notified detection; Prio gets the rare tier (Settings › notification tiers).') + '<br>'
    + t('Without internet the notifications wait and go out when the station is online.') + '</div>';
}
function tgStart(s) { TG.scenario = s; TG.step = 0; TG.conflict = false; tgShow(); }
function tgNav(d) { TG.step = Math.max(0, TG.step + d); tgShow(); }

// ---- one step at a time, with Back / Next ----
function tgShow() {
  tgStopWatch();
  var steps = TG_STEPS[TG.scenario], name = steps[TG.step], n = steps.length, html = '';
  html += '<div class="progress">' + steps.map(function (s, i) { return '<span class="dot' + (i <= TG.step ? ' on' : '') + '"></span>'; }).join('')
    + ' <span>' + t('Step {n} of {total}', {n: TG.step + 1, total: n}) + '</span></div>';
  if (name === 'create') {
    html += '<h3>' + t('Create the bot') + '</h3><ol>'
      + '<li>' + t('Open <a href="https://t.me/BotFather" target="_blank" rel="noopener"><b>@BotFather</b></a> in Telegram (on the phone the link opens the app).') + '</li>'
      + '<li>' + t('Send <code>/newbot</code>.') + '</li>'
      + '<li>' + t('Choose a name, e.g. <i>BirdSuite Home</i>.') + '</li>'
      + '<li>' + t('Choose a user name ending in <i>bot</i>, e.g. <i>birdsuite_home_bot</i>.') + '</li>'
      + '<li>' + t('BotFather answers with a <b>token</b> (it reads like <code>123456789:AAE…</code>): copy it.') + '</li></ol>'
      + '<div class="small">' + t('Use a bot only for the station: another program reading the same bot keeps the station from seeing the groups.') + '</div>'
      + tgNavHtml(true);
  } else if (name === 'token') {
    var saved = TG.status.bot;
    html += '<h3>' + t('The bot token') + '</h3>'
      + '<div>' + (TG.scenario === 'zero' ? t('Paste the token BotFather sent you.') : t('Paste your bot\'s token.') + '<br>' + t('Lost it? @BotFather › /mybots › your bot › API Token.')) + '</div>'
      + (saved ? '<div style="margin-top:6px">' + t('Bot <b>{name}</b> is saved.', {name: esc(botName() || TG.status.bot_hint)}) + '<br>' + t('Type a new token only to replace it.') + '</div>' : '')
      + '<label for="tgtoken">' + t('Bot token') + '</label><input id="tgtoken" type="password" autocomplete="off" placeholder="123456789:AAE…" style="min-width:26em">'
      + '<div class="row"><button type="button" class="stdbtn" onclick="tgToken()">' + t('Check and save') + '</button> <span class="msg" id="tgmsg"></span></div>'
      + '<div id="tgstart" style="margin-top:8px' + (saved && TG.scenario !== 'zero' ? '' : ';display:none') + '">' + (TG.scenario !== 'zero' ? tgStartHtml() : '') + '</div>'
      + (TG.scenario === 'have' ? '<div class="small" style="margin-top:8px">' + t('A bot that another program also reads (another station, a bot service) still sends the notifications; the groups are then typed by their ids in the next step.') + '</div>' : '')
      + tgNavHtml(saved, 'tgnext');
  } else if (name === 'groups') {
    var b = '<b>' + esc(botName()) + '</b>';
    html += TG.scenario === 'have'
      ? '<h3>' + t('Add the bot to your groups') + '</h3><ol>'
        + '<li>' + t('Open the group › tap its name › <b>Add members</b> › {bot}.', {bot: b}) + '</li>'
        + '<li>' + t('If the bot is already in the group, send <code>/start@{user}</code> there.', {user: esc(botUser())}) + '</li>'
        + '<li>' + t('Do the same in the Prio group, if you have one.') + '</li></ol>'
      : '<h3>' + t('Create the groups') + '</h3><ol>'
        + '<li>' + t('In Telegram, tap the pencil (new message) › <b>New Group</b>.') + '</li>'
        + '<li>' + t('Add the bot {bot} as a member.', {bot: b}) + '</li>'
        + '<li>' + t('Name it, e.g. <b>Birds</b> (every notified detection), and create it.') + '</li>'
        + '<li>' + t('Optional: a second group, e.g. <b>Birds Prio</b>, for rare species only.') + '</li></ol>';
    html += '<div class="watch" id="tgwatch"></div><ul class="found" id="tgfound"></ul>'
      + '<label for="tgnormal">' + t('Normal group (every notified detection)') + '</label><select id="tgnormal" onchange="tgCheck()"><option value="">' + t('(waiting for the groups)') + '</option></select>'
      + '<label for="tgprio">' + t('Prio group (rare species)') + '</label><select id="tgprio" onchange="tgCheck()"><option value="">' + t('none: rare species go to the Normal group') + '</option></select>'
      + '<details class="small" id="tgmanual" style="margin-top:10px"><summary>' + t('The groups do not appear?') + '</summary>'
      + '<div id="tgwhy" style="margin:6px 0"></div>'
      + '<div style="margin:6px 0">' + t('Send <code>/start@{user}</code> in the group: the station sees it in a few seconds.', {user: esc(botUser())}) + '<br>' + t('The bot must be a member of the group.') + '</div>'
      + '<div style="margin:6px 0">' + t('Or type the group ids.') + '<br>' + t('Find a group id on the phone or the computer:')
      + '<ol style="margin:4px 0 6px 18px;padding:0"><li>' + t('open <a href="https://web.telegram.org/a/" target="_blank" rel="noopener">web.telegram.org/a</a> in the browser and sign in (the code arrives in the Telegram app);') + '</li><li>' + t('tap the group: the address ends in <b>#-100…</b>, and that number, with the minus sign, is the id;') + '</li><li>' + t('do the same for the Prio group, then paste both below.') + '</li></ol></div>'
      + '<label for="tgnormalid">' + t('Normal group id') + '</label><input id="tgnormalid" placeholder="-1001234567890" oninput="tgCheck()">'
      + '<label for="tgprioid">' + t('Prio group id') + '</label><input id="tgprioid" placeholder="-1009876543210"></details>'
      + tgNavHtml(false, 'tgnext');
  } else if (name === 'save') {
    html += '<h3>' + t('Save and test') + '</h3>'
      + '<div>' + t('Normal group:') + ' <b>' + esc(TG.pick.normalName) + '</b><br>' + t('Prio group:') + ' ' + (TG.pick.prio ? '<b>' + esc(TG.pick.prioName) + '</b>' : t('none (rare species go to the Normal group)')) + '</div>'
      + '<div style="margin-top:6px">' + t('Save sends a test message to each group.') + '<br>' + t('Notifications start only after you answer that the test arrived.') + '</div>'
      + '<div class="row"><button type="button" class="stdbtn" id="tgsavebtn" onclick="tgSave()">' + t('Save and test') + '</button> <span class="msg" id="tgmsg"></span></div>'
      + '<div id="tgarrived" style="display:none;margin-top:12px">' + t('Did the test message arrive in each group?')
      + '<div class="row"><button type="button" class="stdbtn" onclick="tgDone()">' + t('Yes, it arrived') + '</button>'
      + '<button type="button" class="stdbtn" onclick="$(\'tgnotarrived\').style.display = \'\'">' + t('No') + '</button></div>'
      + '<div id="tgnotarrived" class="small" style="display:none;margin-top:8px">' + t('Check that the bot is a member of the group and that the group was not deleted.') + '<br>' + t('Then go back and pick the groups again.') + '</div></div>'
      + '<div class="nav"><button type="button" class="stdbtn" onclick="tgNav(-1)">' + t('Back') + '</button></div>';
  }
  $('tgguide').style.display = '';
  $('tgguide').innerHTML = html;
  if (name === 'groups') { tgFill(); tgWatch(); tgCheck(); }
}
function tgNavHtml(canNext, id) {
  return '<div class="nav"><button type="button" class="stdbtn" onclick="' + (TG.step ? 'tgNav(-1)' : 'tgPicker()') + '">' + t('Back') + '</button>'
    + '<button type="button" class="stdbtn"' + (id ? ' id="' + id + '"' : '') + (canNext ? '' : ' disabled') + ' onclick="tgNext()">' + t('Next') + '</button></div>';
}
// once the bot is known: open it and tap Start (owner 2026-10-22)
function tgStartHtml() {
  return botUser() ? t('Open your bot <a href="{link}" target="_blank" rel="noopener"><b>{bot}</b></a> in Telegram and tap <b>Start</b>.', {link: 'https://t.me/' + esc(botUser()), bot: esc(botName())}) : '';
}
function tgNext() {
  if (TG_STEPS[TG.scenario][TG.step] === 'groups') {
    var normal = v('tgnormalid') || v('tgnormal'), prio = v('tgprioid') || v('tgprio');
    TG.pick = {normal: normal, prio: prio, normalName: tgTitle(normal), prioName: tgTitle(prio)};
  }
  tgNav(1);
}
function tgTitle(id) { var c = TG.chats.filter(function (x) { return x.id === id; })[0]; return c ? c.title + ' (' + id + ')' : id; }

// ---- step "token": checked with Telegram before it is saved ----
function tgToken() {
  var tok = v('tgtoken');
  if (!tok && TG.status.bot) { tgNav(1); return; }
  $('tgmsg').textContent = '…';
  tgCall('bot', {token: tok}, function (r) {
    $('tgtoken').value = '';
    if (!r.ok) { say('tgmsg', false, esc(r.error)); return; }
    TG.status.bot = true; TG.status.bot_name = r.bot_name;
    say('tgmsg', true, t('Bot {name}', {name: esc(r.bot_name)}));
    $('tgstart').innerHTML = tgStartHtml(); $('tgstart').style.display = '';
    $('tgnext').disabled = false;
  });
}

// ---- step "groups": the station watches the bot and lists each group as soon as the bot is in it ----
function tgWatch() {
  var poll = function () {
    tgCall('chats', {}, function (r) {
      if (TG_STEPS[TG.scenario] === undefined || TG_STEPS[TG.scenario][TG.step] !== 'groups') return;
      if (!r.ok) {
        tgStopWatch();
        TG.conflict = !!r.conflict;
        $('tgwatch').innerHTML = TG.conflict ? '' : '<span class="err">' + esc(r.error) + '</span>';
        $('tgwhy').innerHTML = TG.conflict ? '<b>' + t('Another program reads this bot, so the station cannot see the groups.') + '</b><br>' + t('The notifications still work: type the group ids below.') : '';
        $('tgmanual').open = true;
        return;
      }
      var known = TG.chats.map(function (c) { return c.id; }).join();
      TG.chats = r.chats;
      if (TG.chats.map(function (c) { return c.id; }).join() !== known) tgFill();
      $('tgwatch').innerHTML = TG.chats.length
        ? t('{n} group(s) found.', {n: TG.chats.length}) + ' <span class="small">' + t('Still watching for more groups…') + '</span>'
        : '&#8987; ' + t('Waiting for the groups: each group appears here as soon as the bot is in it.');
    });
  };
  // nothing seen after about 30 s: open the help (a bot another program also reads may show no conflict, only no groups)
  var tries = 0, inner = poll;
  poll = function () {
    inner();
    if (++tries === 8 && !TG.chats.length && $('tgmanual') && !$('tgmanual').open) {
      $('tgwhy').innerHTML = '<b>' + t('No group yet after 30 seconds.') + '</b><br>' + t('If another program also reads this bot, the station never sees the groups: type the group ids below.');
      $('tgmanual').open = true;
    }
  };
  poll();
  TG.watch = setInterval(poll, 4000);
}
function tgStopWatch() { if (TG.watch) { clearInterval(TG.watch); TG.watch = null; } }
function tgFill() {
  var ul = $('tgfound'), sn = $('tgnormal'), sp = $('tgprio');
  if (!ul) return;
  ul.innerHTML = TG.chats.map(function (c) { return '<li class="ok">&#10003; ' + esc(c.title) + ' <span class="small">(' + esc(c.id) + ')</span></li>'; }).join('');
  var cn = sn.value, cp = sp.value;
  var opts = TG.chats.map(function (c) { return '<option value="' + esc(c.id) + '">' + esc(c.title) + ' (' + esc(c.id) + ')</option>'; }).join('');
  sn.innerHTML = '<option value="">' + (TG.chats.length ? t('choose the Normal group') : t('(waiting for the groups)')) + '</option>' + opts;
  sp.innerHTML = '<option value="">' + t('none: rare species go to the Normal group') + '</option>' + opts;
  // a first guess: the group whose name says "prio" is the Prio group, the other one the Normal group
  var prio = TG.chats.filter(function (c) { return /prio|rar/i.test(c.title); })[0], normal = TG.chats.filter(function (c) { return c !== prio; })[0];
  sn.value = cn || (normal ? normal.id : '');
  sp.value = cp || (prio && TG.chats.length > 1 ? prio.id : '');
  tgCheck();
}
function tgCheck() {
  var n = $('tgnext');
  if (!n || TG_STEPS[TG.scenario][TG.step] !== 'groups') return;
  var normal = v('tgnormalid') || v('tgnormal'), prio = v('tgprioid') || v('tgprio');
  n.disabled = !normal || normal === prio;
}

// ---- step "save": the groups saved, then a test message to each one ----
function tgSave(after) {
  $('tgsavebtn').disabled = true;
  $('tgmsg').textContent = t('Saving…');
  tgCall('groups', {normal: TG.pick.normal, prio: TG.pick.prio}, function (r) {
    if (!r.ok) { $('tgsavebtn').disabled = false; say('tgmsg', false, esc(r.error)); return; }
    $('tgmsg').textContent = t('Sending the tests…');
    tgCall('test', {which: 'normal'}, function (a) {
      var after = function (b) {
        var bad = [a, b].filter(function (x) { return x && !x.ok; });
        $('tgsavebtn').disabled = false;
        if (bad.length) { say('tgmsg', false, esc(bad.map(function (x) { return x.error; }).join(' / '))); if (after) tgLoad(); return; }
        say('tgmsg', true, t('Saved, test messages sent') + (after ? ': ' + t('look at the groups') : ''));
        if ($('tgarrived')) $('tgarrived').style.display = '';
        if (after) after();
      };
      if (TG.pick.prio) tgCall('test', {which: 'prio'}, after); else after(null);
    });
  });
}
// ---- edit what is saved (owner 2026-10-22: changing the groups must not restart the guide) ----
function tgEdit() {
  var s = TG.status;
  tgStopWatch();
  TG.scenario = 'edit';
  // the saved groups are in the lists from the start; Find my groups adds the others the bot has seen
  TG.chats = [];
  if (s.normal) TG.chats.push({id: s.normal, title: s.normal_name || s.normal});
  if (s.prio) TG.chats.push({id: s.prio, title: s.prio_name || s.prio});
  $('tgguide').style.display = '';
  $('tgguide').innerHTML = '<h3>' + t('Change the bot or the groups') + '</h3>'
    + '<div>' + t('Bot:') + ' <b id="tgbotname">' + esc(s.bot_name || s.bot_hint) + '</b></div>'
    + '<label for="tgtoken">' + t('Bot token') + '</label><input id="tgtoken" type="password" autocomplete="off" placeholder="' + esc(t('Type a new token only to replace it.')) + '" style="min-width:26em">'
    + '<div class="row"><button type="button" class="stdbtn" onclick="tgEditToken()">' + t('Check and save') + '</button> <span class="msg" id="tgmsgb"></span></div>'
    + '<div class="row" style="margin-top:14px"><button type="button" class="stdbtn" onclick="tgEditFind()">' + t('Find my groups') + '</button> <span class="msg" id="tgmsgf"></span></div>'
    + '<ul class="found" id="tgfound"></ul>'
    + '<label for="tgnormal">' + t('Normal group (every notified detection)') + '</label><select id="tgnormal"></select>'
    + '<label for="tgprio">' + t('Prio group (rare species)') + '</label><select id="tgprio"></select>'
    + '<details class="small" style="margin-top:10px"><summary>' + t('Type the group ids instead') + '</summary>'
    + '<label for="tgnormalid">' + t('Normal group id') + '</label><input id="tgnormalid" placeholder="-1001234567890">'
    + '<label for="tgprioid">' + t('Prio group id') + '</label><input id="tgprioid" placeholder="-1009876543210"></details>'
    + '<div class="row"><button type="button" class="stdbtn" id="tgsavebtn" onclick="tgEditSave()">' + t('Save and test') + '</button>'
    + '<button type="button" class="stdbtn" onclick="TG.scenario = \'\'; $(\'tgguide\').style.display = \'none\'">' + t('Cancel') + '</button> <span class="msg" id="tgmsg"></span></div>'
    + '<div class="small" style="margin-top:10px"><a href="#" onclick="tgPicker(); return false">' + t('Set it up from zero with the guide') + '</a></div>';
  tgFill();
  $('tgnormal').value = s.normal || ''; $('tgprio').value = s.prio || '';
}
function tgEditToken() {
  var tok = v('tgtoken');
  if (!tok) return;
  $('tgmsgb').textContent = '…';
  tgCall('bot', {token: tok}, function (r) {
    $('tgtoken').value = '';
    if (!r.ok) { say('tgmsgb', false, esc(r.error)); return; }
    TG.status.bot_name = r.bot_name; $('tgbotname').textContent = r.bot_name;
    say('tgmsgb', true, t('Bot {name}', {name: esc(r.bot_name)}) + '<br>' + tgStartHtml());
  });
}
function tgEditFind() {
  $('tgmsgf').textContent = '…';
  tgCall('chats', {}, function (r) {
    if (!r.ok) { say('tgmsgf', false, esc(r.error)); return; }
    var have = TG.chats.map(function (c) { return c.id; });
    r.chats.forEach(function (c) { if (have.indexOf(c.id) < 0) TG.chats.push(c); });
    var n = $('tgnormal').value, p = $('tgprio').value;
    tgFill(); $('tgnormal').value = n; $('tgprio').value = p;
    say('tgmsgf', true, t('{n} group(s) found.', {n: r.chats.length}));
  });
}
function tgEditSave() {
  var normal = v('tgnormalid') || v('tgnormal'), prio = v('tgprioid') || v('tgprio');
  if (!normal) { say('tgmsg', false, t('Choose the Normal group.')); return; }
  if (normal === prio) { say('tgmsg', false, t('choose the Normal group')); return; }
  TG.pick = {normal: normal, prio: prio};
  tgSave(function () { tgLoad(); });
}
function tgDone() { tgCall('confirm', {}, function () { $('tgguide').style.display = 'none'; tgLoad(); }); }

// ---- the box on top: on or off, the tests and Remove ----
function tgTest(which) {
  $('tgmsg0').textContent = '…';
  tgCall('test', {which: which}, function (r) {
    say('tgmsg0', r.ok, r.ok ? t('Sent: look at the group') : esc(r.error));
    if (r.ok && $('tgok0')) $('tgok0').style.display = '';
  });
}
// the switch, only for a channel the user already confirmed (owner 2026-10-26); the title: set up or not, active or disabled
function tgSwitch(to) { tgCall(to, {}, function (r) { if (r.ok) tgLoad(); else say('tgmsg0', false, esc(r.error)); }); }
// a channel sends only after the user saw a test arrive; never confirmed, set up again or a failed send = disabled (2026-10-26)
function disabledHtml(p, ok) {
  if (p.reason === 'off')
    return '<div class="row" style="margin-top:.8em"><button type="button" class="stdbtn" onclick="tgSwitch(\'on\')">' + t('Turn notifications on') + '</button>'
      + ' <span class="small">' + t('Turned off on {at}.', {at: esc(p.at)}) + '</span></div>';
  return '<div class="box" style="margin-top:.8em;border:1px solid #e0a000">'
    + '<b>' + t('Disabled: nothing is sent until you confirm a test.') + '</b><br>'
    + (p.reason === 'failed' ? t('A send failed on {at}.', {at: esc(p.at)}) : t('The test was not confirmed yet.')) + '<br>'
    + t('Send a test and answer when it arrives.')
    + '<div id="' + ok + '" style="display:none;margin-top:8px">' + t('Did the test arrive?')
    + ' <button type="button" class="stdbtn" onclick="' + (ok === 'emok0' ? 'emDone()' : 'tgDone()') + '">' + t('Yes, it arrived') + '</button></div></div>';
}
function tgLoad() {
  var x = new XMLHttpRequest();
  x.onload = function () {
    var s; try { s = JSON.parse(this.responseText); } catch (e) { return; }
    TG.status = s;
    var on = s.bot && s.normal;
    $('tgstate').innerHTML = (on
      ? '<span style="font-size:1.1em">&#9992; ' + (s.disabled ? t('Telegram notifications are <b>set up</b> and <b>disabled</b>') : t('Telegram notifications are <b>set up</b> and <b>active</b>')) + '</span>'
        + '<div style="margin-top:.8em">' + t('Bot:') + ' <b>' + esc(s.bot_name || s.bot_hint || t('set')) + '</b>'
        + '<br>' + t('Normal group:') + ' <b>' + esc(s.normal_name || s.normal) + '</b>'
        + '<br>' + t('Prio group:') + ' ' + (s.prio ? '<b>' + esc(s.prio_name || s.prio) + '</b>' : t('none (rare species go to the Normal group)')) + '</div>'
        + '<div class="row" style="margin-top:.8em"><button type="button" class="stdbtn" onclick="tgTest(\'normal\')">' + t('Test Normal') + '</button>'
        + (s.prio ? '<button type="button" class="stdbtn" onclick="tgTest(\'prio\')">' + t('Test Prio') + '</button>' : '')
        + ' <span class="msg" id="tgmsg0"></span></div>'
        + (s.disabled ? disabledHtml(s.disabled, 'tgok0') : '<div class="row" style="margin-top:.8em"><button type="button" class="stdbtn" onclick="tgSwitch(\'off\')">' + t('Turn notifications off') + '</button></div>')
        + '<div class="row" style="margin-top:10px"><button type="button" class="stdbtn del" onclick="tgRemove()">' + t('Remove') + '</button>'
        + ' <a href="#" class="small" onclick="tgEdit(); return false">' + t('Change the bot or the groups') + '</a></div>'
        + '<div class="small" style="margin-top:.8em">' + t('Telegram is already set up: Remove only if it stopped working; then set it up again with the steps that appear.') + '</div>'
      : '<span style="font-size:1.1em">&#9992; ' + t('Telegram notifications are <b>not set up</b>') + '</span>'
        + (s.bot ? '<br>' + t('Bot <b>{name}</b> saved; choose the groups below.', {name: esc(s.bot_name || t('set'))}) : ''))
      + (s.other_lines ? '<div class="small">' + t('The notification boxes of Settings also have Telegram lines set by hand; they are kept as they are.') + '</div>' : '')
      // always in sight, set up or not (owner 2026-10-26)
      + '<div class="small" style="margin-top:.8em">' + t('Notifications start only after you answer that the test arrived.') + '</div>';
    if (on) { if (!TG.scenario) $('tgguide').style.display = 'none'; }
    else if (!TG.scenario) tgPicker();
  };
  x.open('GET', 'scripts/telegram_settings.php?status=1', true); x.send();
}
function tgRemove() {
  if (confirm(t('Telegram is set up and working.') + '\n' + t('Remove it only if it stopped working: the notifications stop until it is set up again.')))
    tgCall('remove', {}, function (r) { if (r.ok) { TG.scenario = ''; tgLoad(); } else say('tgmsg0', false, esc(r.error)); });
}
tgLoad();
</script>
