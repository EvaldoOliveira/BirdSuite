<?php
################################################################################
# scripts/telegram_settings.php - TELEGRAM
#
# PURPOSE:
#   Telegram (owner 2026-10-20; one guide per scenario 2026-10-22): the two Telegram groups — Normal (every
#   notified detection) and Rare (the rare tier) — with one bot. When Telegram is off the page asks what the
#   user already has (nothing / a bot / a bot and groups) and walks through the steps one at a time: create
#   the bot at @BotFather, the token (checked with getMe), the groups (the station watches the bot and lists
#   each group as soon as the bot is in it; the ids can be typed instead), then save and test each group.
#   Secrets go to the station's token store through scripts/telegram_setup.py (run as the station user); the
#   notification boxes of Settings keep placeholders only. Opened as views.php?view=Telegram.
#
# DEPENDENCIES:
#   - scripts/common.php
#   - scripts/telegram_setup.py
#
# USAGE:
#   views.php?view=Telegram
#   Referenced by homepage/views.php, scripts/telegram_setup.py, scripts/update_caddyfile.sh
################################################################################

require_once __DIR__ . '/common.php';

function tg_run($args, $stdin = '') {
	$cmd = station_prefix() . '' . escapeshellarg(BS_PYTHON) . ' ' . escapeshellarg(__DIR__ . '/telegram_setup.py');
	foreach ($args as $a) $cmd .= ' ' . escapeshellarg((string)$a);
	$p = proc_open($cmd, array(0 => array('pipe', 'r'), 1 => array('pipe', 'w'), 2 => array('pipe', 'w')), $pipes);
	if (!is_resource($p)) return array('ok' => false, 'error' => 'cannot run telegram_setup.py');
	fwrite($pipes[0], $stdin);
	fclose($pipes[0]);
	$out = stream_get_contents($pipes[1]);
	fclose($pipes[1]); fclose($pipes[2]);
	proc_close($p);
	return json_decode((string)$out, true) ?: array('ok' => false, 'error' => trim((string)$out) ?: t('str_no_answer_lc'));
}

if (isset($_GET['status'])) {
	header('Content-Type: application/json');
	$s = tg_run(array('status'));
	if (is_authenticated()) $s['draft'] = setup_draft_get('telegram');
	echo json_encode($s);
	die();
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_GET['do'])) {
	ensure_authenticated(t('str_you_must_change_the_telegram_help'));
	header('Content-Type: application/json');
	$p = json_decode((string)file_get_contents('php://input'), true) ?: array();
	switch ($_GET['do']) {
		case 'bot': echo json_encode(tg_run(array('bot'), trim((string)($p['token'] ?? '')) . "\n")); break;
		case 'chats': echo json_encode(tg_run(array('chats'))); break;
		case 'groups': echo json_encode(tg_run(array('groups', (string)($p['normal'] ?? ''), (string)(($p['rare'] ?? '') !== '' ? $p['rare'] : '-')))); break;
		case 'test': echo json_encode(tg_run(array('test', ($p['which'] ?? '') === 'rare' ? 'rare' : 'normal'))); break;
		case 'remove': echo json_encode(tg_run(array('remove'))); break;
		case 'forget': echo json_encode(tg_run(array('forget'))); break;
		// the typed group ids, saved as they are filled in (owner 2026-11-03)
		case 'draft': echo json_encode(array('ok' => setup_draft_set('telegram', array('normal' => preg_replace('/[^0-9-]/', '', (string)($p['normal'] ?? '')), 'rare' => preg_replace('/[^0-9-]/', '', (string)($p['rare'] ?? '')))))); break;
		case 'confirm': echo json_encode(tg_run(array('confirm'))); break;
		case 'on': case 'off': echo json_encode(tg_run(array($_GET['do']))); break;
		default: echo json_encode(array('ok' => false, 'error' => t('str_unknown_action_lc')));
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
	<div class="box" id="tgstate"><?php echo t('str_reading'); ?></div>
	<?php // the guided setup when Telegram is off, after Remove or on "Change the bot or the groups" (owner 2026-10-22: one guide per scenario) ?>
	<div class="box" id="tgguide" style="display:none"></div>
</div>
<script>
var esc = function (s) { return String(s == null ? '' : s).replace(/[&<>"]/g, function (c) { return {'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;'}[c]; }); };
function $(id) { return document.getElementById(id); }
function v(id) { var e = $(id); return e ? e.value.trim() : ''; }
var TG = {status: {}, scenario: '', step: 0, chats: [], watch: null, conflict: false};
// a saved bot token shows as dots in its box (owner 2026-11-03), never the token itself; left as it is = kept
var TG_MASK = '\u2022\u2022\u2022\u2022\u2022\u2022\u2022\u2022';
// the steps of each scenario (owner 2026-10-22): from zero, a bot of one's own with new groups, a bot with groups that exist
var TG_STEPS = {zero: ['create', 'token', 'groups', 'save'], bot: ['token', 'groups', 'save'], have: ['token', 'groups', 'save']};

function tgCall(what, body, done) {
	var x = new XMLHttpRequest();
	x.onload = function () {
		var r; try { r = JSON.parse(this.responseText); } catch (e) { r = {ok: false, error: this.status === 401 ? t('str_log_in_first') : this.responseText}; }
		done(r);
	};
	x.open('POST', 'scripts/telegram_settings.php?do=' + what, true); x.setRequestHeader('Content-Type', 'application/json'); x.send(JSON.stringify(body || {}));
}
function say(id, ok, text) { var m = $(id); if (m) m.innerHTML = '<span class="' + (ok ? 'ok' : 'err') + '">' + (ok ? '&#10003; ' : '') + text + '</span>'; }
function botName() { return TG.status.bot_name || ''; }
function botUser() { return botName().replace(/^@/, ''); }

//##############################################################################
// the picker: what the user already has
//##############################################################################
// a bot already saved (owner 2026-11-03): shown first, "use it" goes on to the groups filled in, "no" shows the choices
// and the links to make another
function tgPicker(other) {
	tgStopWatch();
	TG.scenario = ''; TG.step = 0;
	$('tgguide').style.display = '';
	if (TG.status.bot && !other) {
		$('tgguide').innerHTML = '<h3>' + t('str_a_bot_is_already_saved_use_it', {name: '<b>' + esc(botName() || TG.status.bot_hint) + '</b>'}) + '</h3>'
			+ '<div class="row"><button type="button" class="stdbtn" onclick="tgReuse()">' + t('str_yes_use_it') + '</button>'
			+ '<button type="button" class="stdbtn" onclick="tgPicker(true)">' + t('str_no_set_up_another') + '</button></div>';
		return;
	}
	$('tgguide').innerHTML = '<h3>' + t('str_how_do_you_want_to_set_up_help') + '</h3>'
		+ '<div class="cards">'
		+ '<button type="button" class="card" onclick="tgStart(\'zero\')"><b>' + t('str_start_from_zero') + '</b>' + t('str_i_need_a_bot_and_groups') + '<br>' + t('str_recommended_for_a_new_station') + '</button>'
		+ '<button type="button" class="card" onclick="tgStart(\'bot\')"><b>' + t('str_i_have_a_bot') + '</b>' + t('str_i_have_its_token_and_will_help') + '</button>'
		+ '<button type="button" class="card" onclick="tgStart(\'have\')"><b>' + t('str_i_have_a_bot_and_groups') + '</b>' + t('str_i_will_connect_the_station_to_help') + '</button>'
		+ '</div>'
		+ '<div class="small" style="margin-top:10px">' + t('str_normal_gets_the_clip_and_the_help') + '<br>'
		+ t('str_without_internet_and_help') + '</div>';
}
function tgReuse() { TG.scenario = 'have'; TG.step = TG_STEPS.have.indexOf('groups'); TG.conflict = false; tgShow(); }
// the token box emptied and saved (owner 2026-11-03): the saved bot deleted, after a question; Telegram stops
function tgForget() {
	askModal(t('str_delete_the_saved_bot', {name: botName() || TG.status.bot_hint}), t('str_telegram_notifications_stop'), t('str_delete'), true).then(function (ok) {
		if (ok) tgCall('forget', {}, function (r) { if (r.ok) { TG.scenario = ''; tgLoad(); } else say('tgmsg', false, esc(r.error)); });
	});
}
function tgStart(s) { TG.scenario = s; TG.step = 0; TG.conflict = false; tgShow(); }
function tgNav(d) { TG.step = Math.max(0, TG.step + d); tgShow(); }

//##############################################################################
// one step at a time, with Back / Next
//##############################################################################
function tgShow() {
	tgStopWatch();
	var steps = TG_STEPS[TG.scenario], name = steps[TG.step], n = steps.length, html = '';
	html += '<div class="progress">' + steps.map(function (s, i) { return '<span class="dot' + (i <= TG.step ? ' on' : '') + '"></span>'; }).join('')
		+ ' <span>' + t('str_step_of', {n: TG.step + 1, total: n}) + '</span></div>';
	if (name === 'create') {
		html += '<h3>' + t('str_create_the_bot') + '</h3><ol>'
			+ '<li>' + t('str_open_botfather_in_telegram_on_help') + '</li>'
			+ '<li>' + t('str_send_newbot') + '</li>'
			+ '<li>' + t('str_choose_a_name_e_g_birdsuite_help') + '</li>'
			+ '<li>' + t('str_choose_a_user_name_ending_in_help') + '</li>'
			+ '<li>' + t('str_botfather_answers_with_a_token_help') + '</li></ol>'
			+ '<div class="small">' + t('str_use_a_bot_only_for_the_station_help') + '</div>'
			+ tgNavHtml(true);
	} else if (name === 'token') {
		var saved = TG.status.bot;
		html += '<h3>' + t('str_the_bot_token') + '</h3>'
			+ '<div>' + (TG.scenario === 'zero' ? t('str_paste_the_token_botfather_sent_you') : t('str_paste_your_bots_token') + '<br>' + t('str_lost_it_botfather_mybots_your_help')) + '</div>'
			+ (saved ? '<div style="margin-top:6px">' + t('str_bot_is_saved', {name: esc(botName() || TG.status.bot_hint)}) + '<br>' + t('str_type_a_new_token_only_to_help') + '</div>' : '')
			+ '<label for="tgtoken">' + t('str_bot_token') + '</label><input id="tgtoken" type="password" autocomplete="off" placeholder="123456789:AAE…" style="min-width:26em"' + (saved ? ' value="' + TG_MASK + '" onfocus="if (this.value === TG_MASK) this.select()"' : '') + '>'
			+ '<div class="row"><button type="button" class="stdbtn" onclick="tgToken()">' + t('str_check_and_save') + '</button> <span class="msg" id="tgmsg"></span></div>'
			+ '<div id="tgstart" style="margin-top:8px' + (saved && TG.scenario !== 'zero' ? '' : ';display:none') + '">' + (TG.scenario !== 'zero' ? tgStartHtml() : '') + '</div>'
			+ (TG.scenario === 'have' ? '<div class="small" style="margin-top:8px">' + t('str_a_bot_that_another_program_also_help') + '</div>' : '')
			+ tgNavHtml(saved, 'tgnext');
	} else if (name === 'groups') {
		var b = '<b>' + esc(botName()) + '</b>';
		html += TG.scenario === 'have'
			? '<h3>' + t('str_add_the_bot_to_your_groups') + '</h3><ol>'
				+ '<li>' + t('str_open_the_group_tap_its_name_add_help', {bot: b}) + '</li>'
				+ '<li>' + t('str_if_the_bot_is_already_in_the_help', {user: esc(botUser())}) + '</li>'
				+ '<li>' + t('str_do_the_same_in_the_rare_group_help') + '</li></ol>'
			: '<h3>' + t('str_create_the_groups') + '</h3><ol>'
				+ '<li>' + t('str_in_telegram_tap_the_pencil_new_help') + '</li>'
				+ '<li>' + t('str_add_the_bot_as_a_member', {bot: b}) + '</li>'
				+ '<li>' + t('str_name_it_e_g_birds_every_help') + '</li>'
				+ '<li>' + t('str_optional_a_second_group_e_g_help') + '</li></ol>';
		html += '<div class="watch" id="tgwatch"></div><ul class="found" id="tgfound"></ul>'
			+ '<label for="tgnormal">' + t('str_normal_group_every_notified_help') + '</label><select id="tgnormal" onchange="tgCheck()"><option value="">' + t('str_waiting_for_the_groups') + '</option></select>'
			+ '<label for="tgrare">' + t('str_rare_group_rare_species') + '</label><select id="tgrare" onchange="tgCheck()"><option value="">' + t('str_none_rare_species_go_to_the_help') + '</option></select>'
			+ '<details class="small" id="tgmanual" style="margin-top:10px"><summary>' + t('str_the_groups_do_not_appear') + '</summary>'
			+ '<div id="tgwhy" style="margin:6px 0"></div>'
			+ '<div style="margin:6px 0">' + t('str_send_start_in_the_group_the_help', {user: esc(botUser())}) + '<br>' + t('str_the_bot_must_be_a_member_of_the_help') + '</div>'
			+ '<div style="margin:6px 0">' + t('str_or_type_the_group_ids') + '<br>' + t('str_find_a_group_id_on_the_phone_or_help')
			+ '<ol style="margin:4px 0 6px 18px;padding:0"><li>' + t('str_open_web_telegram_org_a_in_the_help') + '</li><li>' + t('str_tap_the_group_the_address_ends_help') + '</li><li>' + t('str_do_the_same_for_the_rare_group_help') + '</li></ol></div>'
			+ '<label for="tgnormalid">' + t('str_normal_group_id') + '</label><input id="tgnormalid" placeholder="-1001234567890" oninput="tgCheck()">'
			+ '<label for="tgrareid">' + t('str_rare_group_id') + '</label><input id="tgrareid" placeholder="-1009876543210"></details>'
			+ tgNavHtml(false, 'tgnext');
	} else if (name === 'save') {
		html += '<h3>' + t('str_save_and_test') + '</h3>'
			+ '<div>' + t('str_normal_group') + ' <b>' + esc(TG.pick.normalName) + '</b><br>' + t('str_rare_group') + ' ' + (TG.pick.rare ? '<b>' + esc(TG.pick.rareName) + '</b>' : t('str_none_rare_species_go_to_the_2_help')) + '</div>'
			+ '<div style="margin-top:6px">' + t('str_save_sends_a_test_message_to_help') + '<br>' + t('str_notifications_start_only_after_help') + '</div>'
			+ '<div class="row"><button type="button" class="stdbtn" id="tgsavebtn" onclick="tgSave()">' + t('str_save_and_test') + '</button> <span class="msg" id="tgmsg"></span></div>'
			+ '<div id="tgarrived" style="display:none;margin-top:12px">' + t('str_did_the_test_message_arrive_in_help')
			+ '<div class="row"><button type="button" class="stdbtn" onclick="tgDone()">' + t('str_yes_it_arrived') + '</button>'
			+ '<button type="button" class="stdbtn" onclick="$(\'tgnotarrived\').style.display = \'\'">' + t('str_no') + '</button></div>'
			+ '<div id="tgnotarrived" class="small" style="display:none;margin-top:8px">' + t('str_check_that_the_bot_is_a_member_help') + '<br>' + t('str_then_go_back_and_pick_the_help') + '</div></div>'
			+ '<div class="nav"><button type="button" class="stdbtn" onclick="tgNav(-1)">' + t('str_back') + '</button></div>';
	}
	$('tgguide').style.display = '';
	$('tgguide').innerHTML = html;
	if (name === 'groups') {
		// the groups of a removed setup, remembered (owner 2026-11-03): filled in, the typed ids open
		var dn = TG.status.normal || (TG.status.draft || {}).normal || TG.status.kept_normal, dr = TG.status.normal ? TG.status.rare : ((TG.status.draft || {}).rare || TG.status.kept_rare);
		if (dn && !v('tgnormalid')) { $('tgnormalid').value = dn; $('tgrareid').value = dr || ''; $('tgmanual').open = true; }
		tgFill(); tgWatch(); tgCheck();
	}
}
function tgNavHtml(canNext, id) {
	return '<div class="nav"><button type="button" class="stdbtn" onclick="' + (TG.step ? 'tgNav(-1)' : 'tgPicker()') + '">' + t('str_back') + '</button>'
		+ '<button type="button" class="stdbtn"' + (id ? ' id="' + id + '"' : '') + (canNext ? '' : ' disabled') + ' onclick="tgNext()">' + t('str_next') + '</button></div>';
}
// once the bot is known: open it and tap Start (owner 2026-10-22)
function tgStartHtml() {
	return botUser() ? t('str_open_your_bot_in_telegram_and_help', {link: 'https://t.me/' + esc(botUser()), bot: esc(botName())}) : '';
}
function tgNext() {
	if (TG_STEPS[TG.scenario][TG.step] === 'groups') {
		var normal = v('tgnormalid') || v('tgnormal'), rare = v('tgrareid') || v('tgrare');
		TG.pick = {normal: normal, rare: rare, normalName: tgTitle(normal), rareName: tgTitle(rare)};
	}
	tgNav(1);
}
function tgTitle(id) { var c = TG.chats.filter(function (x) { return x.id === id; })[0]; return c ? c.title + ' (' + id + ')' : id; }

//##############################################################################
// step "token": checked with Telegram before it is saved
//##############################################################################
function tgToken() {
	var tok = v('tgtoken');
	if (tok === TG_MASK) { tgNav(1); return; }  // the saved bot, kept
	if (!tok && TG.status.bot) { tgForget(); return; }  // the box emptied: the saved bot deleted, after a question
	$('tgmsg').textContent = '…';
	tgCall('bot', {token: tok}, function (r) {
		$('tgtoken').value = '';
		if (!r.ok) { say('tgmsg', false, esc(r.error)); return; }
		TG.status.bot = true; TG.status.bot_name = r.bot_name;
		say('tgmsg', true, t('str_bot_name', {name: esc(r.bot_name)}));
		$('tgstart').innerHTML = tgStartHtml(); $('tgstart').style.display = '';
		$('tgnext').disabled = false;
	});
}

//##############################################################################
// step "groups": the station watches the bot and lists each group as soon as the bot is in it
//##############################################################################
function tgWatch() {
	var poll = function () {
		tgCall('chats', {}, function (r) {
			if (TG_STEPS[TG.scenario] === undefined || TG_STEPS[TG.scenario][TG.step] !== 'groups') return;
			if (!r.ok) {
				tgStopWatch();
				TG.conflict = !!r.conflict;
				$('tgwatch').innerHTML = TG.conflict ? '' : '<span class="err">' + esc(r.error) + '</span>';
				$('tgwhy').innerHTML = TG.conflict ? '<b>' + t('str_another_program_reads_this_bot_help') + '</b><br>' + t('str_the_notifications_still_work_help') : '';
				$('tgmanual').open = true;
				return;
			}
			var known = TG.chats.map(function (c) { return c.id; }).join();
			TG.chats = r.chats;
			if (TG.chats.map(function (c) { return c.id; }).join() !== known) tgFill();
			$('tgwatch').innerHTML = TG.chats.length
				? t('str_group_s_found', {n: TG.chats.length}) + ' <span class="small">' + t('str_still_watching_for_more_groups') + '</span>'
				: '&#8987; ' + t('str_waiting_for_the_groups_each_help');
		});
	};
	// nothing seen after about 30 s: open the help (a bot another program also reads may show no conflict, only no groups)
	var tries = 0, inner = poll;
	poll = function () {
		inner();
		if (++tries === 8 && !TG.chats.length && $('tgmanual') && !$('tgmanual').open) {
			$('tgwhy').innerHTML = '<b>' + t('str_no_group_yet_after_30_seconds') + '</b><br>' + t('str_if_another_program_also_reads_help');
			$('tgmanual').open = true;
		}
	};
	poll();
	TG.watch = setInterval(poll, 4000);
}
// the typed group ids saved on the station as they are filled in (owner 2026-11-03)
document.addEventListener('change', function (e) {
	if (e.target && (e.target.id === 'tgnormalid' || e.target.id === 'tgrareid')) {
		TG.status.draft = {normal: v('tgnormalid'), rare: v('tgrareid')};
		tgCall('draft', TG.status.draft, function () {});
	}
});
function tgStopWatch() { if (TG.watch) { clearInterval(TG.watch); TG.watch = null; } }
function tgFill() {
	var ul = $('tgfound'), sn = $('tgnormal'), sp = $('tgrare');
	if (!ul) return;
	ul.innerHTML = TG.chats.map(function (c) { return '<li class="ok">&#10003; ' + esc(c.title) + ' <span class="small">(' + esc(c.id) + ')</span></li>'; }).join('');
	var cn = sn.value, cp = sp.value;
	var opts = TG.chats.map(function (c) { return '<option value="' + esc(c.id) + '">' + esc(c.title) + ' (' + esc(c.id) + ')</option>'; }).join('');
	sn.innerHTML = '<option value="">' + (TG.chats.length ? t('str_choose_the_normal_group_lc') : t('str_waiting_for_the_groups')) + '</option>' + opts;
	sp.innerHTML = '<option value="">' + t('str_none_rare_species_go_to_the_help') + '</option>' + opts;
	// a first guess: the group whose name says "rare" (or "prio") is the Rare group, the other one the Normal group
	var rare = TG.chats.filter(function (c) { return /rar|prio/i.test(c.title); })[0], normal = TG.chats.filter(function (c) { return c !== rare; })[0];
	sn.value = cn || (normal ? normal.id : '');
	sp.value = cp || (rare && TG.chats.length > 1 ? rare.id : '');
	tgCheck();
}
function tgCheck() {
	var n = $('tgnext');
	if (!n || TG_STEPS[TG.scenario][TG.step] !== 'groups') return;
	var normal = v('tgnormalid') || v('tgnormal'), rare = v('tgrareid') || v('tgrare');
	n.disabled = !normal || normal === rare;
}

//##############################################################################
// step "save": the groups saved, then a test message to each one
//##############################################################################
function tgSave(after) {
	$('tgsavebtn').disabled = true;
	$('tgmsg').textContent = t('str_saving');
	tgCall('groups', {normal: TG.pick.normal, rare: TG.pick.rare}, function (r) {
		if (!r.ok) { $('tgsavebtn').disabled = false; say('tgmsg', false, esc(r.error)); return; }
		$('tgmsg').textContent = t('str_sending_the_tests');
		tgCall('test', {which: 'normal'}, function (a) {
			var after = function (b) {
				var bad = [a, b].filter(function (x) { return x && !x.ok; });
				$('tgsavebtn').disabled = false;
				if (bad.length) { say('tgmsg', false, esc(bad.map(function (x) { return x.error; }).join(' / '))); if (after) tgLoad(); return; }
				say('tgmsg', true, t('str_saved_test_messages_sent') + (after ? ': ' + t('str_look_at_the_groups') : ''));
				if ($('tgarrived')) $('tgarrived').style.display = '';
				if (after) after();
			};
			if (TG.pick.rare) tgCall('test', {which: 'rare'}, after); else after(null);
		});
	});
}
//##############################################################################
// edit what is saved (owner 2026-10-22: changing the groups must not restart the guide)
//##############################################################################
function tgEdit() {
	var s = TG.status;
	tgStopWatch();
	TG.scenario = 'edit';
	// the saved groups are in the lists from the start; Find my groups adds the others the bot has seen
	TG.chats = [];
	if (s.normal) TG.chats.push({id: s.normal, title: s.normal_name || s.normal});
	if (s.rare) TG.chats.push({id: s.rare, title: s.rare_name || s.rare});
	$('tgguide').style.display = '';
	$('tgguide').innerHTML = '<h3>' + t('str_change_the_bot_or_the_groups') + '</h3>'
		+ '<div>' + t('str_bot') + ' <b id="tgbotname">' + esc(s.bot_name || s.bot_hint) + '</b></div>'
		+ '<label for="tgtoken">' + t('str_bot_token') + '</label><input id="tgtoken" type="password" autocomplete="off" placeholder="' + esc(t('str_type_a_new_token_only_to_help')) + '" style="min-width:26em"' + (s.bot ? ' value="' + TG_MASK + '" onfocus="if (this.value === TG_MASK) this.select()"' : '') + '>'
		+ '<div class="row"><button type="button" class="stdbtn" onclick="tgEditToken()">' + t('str_check_and_save') + '</button> <span class="msg" id="tgmsgb"></span></div>'
		+ '<div class="row" style="margin-top:14px"><button type="button" class="stdbtn" onclick="tgEditFind()">' + t('str_find_my_groups') + '</button> <span class="msg" id="tgmsgf"></span></div>'
		+ '<ul class="found" id="tgfound"></ul>'
		+ '<label for="tgnormal">' + t('str_normal_group_every_notified_help') + '</label><select id="tgnormal"></select>'
		+ '<label for="tgrare">' + t('str_rare_group_rare_species') + '</label><select id="tgrare"></select>'
		+ '<details class="small" style="margin-top:10px"><summary>' + t('str_type_the_group_ids_instead') + '</summary>'
		+ '<label for="tgnormalid">' + t('str_normal_group_id') + '</label><input id="tgnormalid" placeholder="-1001234567890">'
		+ '<label for="tgrareid">' + t('str_rare_group_id') + '</label><input id="tgrareid" placeholder="-1009876543210"></details>'
		+ '<div class="row"><button type="button" class="stdbtn" id="tgsavebtn" onclick="tgEditSave()">' + t('str_save_and_test') + '</button>'
		+ '<button type="button" class="stdbtn" onclick="TG.scenario = \'\'; $(\'tgguide\').style.display = \'none\'">' + t('str_cancel') + '</button> <span class="msg" id="tgmsg"></span></div>'
		+ '<div class="small" style="margin-top:10px"><a href="#" onclick="tgPicker(); return false">' + t('str_set_it_up_from_zero_with_the_help') + '</a></div>';
	tgFill();
	$('tgnormal').value = s.normal || ''; $('tgrare').value = s.rare || '';
}
function tgEditToken() {
	var tok = v('tgtoken');
	if (tok === TG_MASK) return;
	if (!tok) { if (TG.status.bot) tgForget(); return; }
	$('tgmsgb').textContent = '…';
	tgCall('bot', {token: tok}, function (r) {
		$('tgtoken').value = '';
		if (!r.ok) { say('tgmsgb', false, esc(r.error)); return; }
		TG.status.bot_name = r.bot_name; $('tgbotname').textContent = r.bot_name;
		say('tgmsgb', true, t('str_bot_name', {name: esc(r.bot_name)}) + '<br>' + tgStartHtml());
	});
}
function tgEditFind() {
	$('tgmsgf').textContent = '…';
	tgCall('chats', {}, function (r) {
		if (!r.ok) { say('tgmsgf', false, esc(r.error)); return; }
		var have = TG.chats.map(function (c) { return c.id; });
		r.chats.forEach(function (c) { if (have.indexOf(c.id) < 0) TG.chats.push(c); });
		var n = $('tgnormal').value, p = $('tgrare').value;
		tgFill(); $('tgnormal').value = n; $('tgrare').value = p;
		say('tgmsgf', true, t('str_group_s_found', {n: r.chats.length}));
		// none: the same help as the guide (another program reading this bot takes its messages), the typed ids open
		if (!r.chats.length) {
			$('tgmsgf').innerHTML += '<br><span class="small">' + t('str_if_another_program_also_reads_help') + '</span>';
			var d = $('tgguide').querySelector('details'); if (d) d.open = true;
		}
	});
}
function tgEditSave() {
	var normal = v('tgnormalid') || v('tgnormal'), rare = v('tgrareid') || v('tgrare');
	if (!normal) { say('tgmsg', false, t('str_choose_the_normal_group')); return; }
	if (normal === rare) { say('tgmsg', false, t('str_choose_the_normal_group_lc')); return; }
	TG.pick = {normal: normal, rare: rare};
	tgSave(function () { tgLoad(); });
}
function tgDone() { tgCall('confirm', {}, function () { $('tgguide').style.display = 'none'; tgLoad(); }); }

//##############################################################################
// the box on top: on or off, the tests and Remove
//##############################################################################
function tgTest(which) {
	$('tgmsg0').textContent = '…';
	tgCall('test', {which: which}, function (r) {
		say('tgmsg0', r.ok, r.ok ? t('str_sent_look_at_the_group') : esc(r.error));
		if (r.ok && $('tgok0')) $('tgok0').style.display = '';
	});
}
// the switch, only for a channel the user already confirmed (owner 2026-10-26); the title: set up or not, active or disabled
function tgSwitch(to) { tgCall(to, {}, function (r) { if (r.ok) tgLoad(); else say('tgmsg0', false, esc(r.error)); }); }
// a channel sends only after the user saw a test arrive; never confirmed, set up again or a failed send = disabled (2026-10-26)
function disabledHtml(p, ok) {
	if (p.reason === 'off')
		return '<div class="row" style="margin-top:.8em"><button type="button" class="stdbtn" onclick="tgSwitch(\'on\')">' + t('str_turn_notifications_on') + '</button>'
			+ ' <span class="small">' + t('str_turned_off_on', {at: esc(p.at)}) + '</span></div>';
	return '<div class="box" style="margin-top:.8em;border:1px solid #e0a000">'
		+ '<b>' + t('str_disabled_nothing_is_sent_until_help') + '</b><br>'
		+ (p.reason === 'failed' ? t('str_a_send_failed_on', {at: esc(p.at)}) : t('str_the_test_was_not_confirmed_yet')) + '<br>'
		+ t('str_send_a_test_and_answer_when_it_help')
		+ '<div id="' + ok + '" style="display:none;margin-top:8px">' + t('str_did_the_test_arrive')
		+ ' <button type="button" class="stdbtn" onclick="' + (ok === 'emok0' ? 'emDone()' : 'tgDone()') + '">' + t('str_yes_it_arrived') + '</button></div></div>';
}
function tgLoad() {
	var x = new XMLHttpRequest();
	x.onload = function () {
		var s; try { s = JSON.parse(this.responseText); } catch (e) { return; }
		TG.status = s;
		var on = s.bot && s.normal;
		$('tgstate').innerHTML = (on
			? '<span style="font-size:1.1em">&#9992; ' + (s.disabled ? t('str_telegram_notifications_disabled_help') : t('str_telegram_notifications_active_help')) + '</span>'
				+ '<div style="margin-top:.8em">' + t('str_bot') + ' <b>' + esc(s.bot_name || s.bot_hint || t('str_set')) + '</b>'
				+ '<br>' + t('str_normal_group') + ' <b>' + esc(s.normal_name || s.normal) + '</b>'
				+ '<br>' + t('str_rare_group') + ' ' + (s.rare ? '<b>' + esc(s.rare_name || s.rare) + '</b>' : t('str_none_rare_species_go_to_the_2_help')) + '</div>'
				+ '<div class="row" style="margin-top:.8em"><button type="button" class="stdbtn" onclick="tgTest(\'normal\')">' + t('str_test_normal') + '</button>'
				+ (s.rare ? '<button type="button" class="stdbtn" onclick="tgTest(\'rare\')">' + t('str_test_rare') + '</button>' : '')
				+ ' <span class="msg" id="tgmsg0"></span></div>'
				+ (s.disabled ? disabledHtml(s.disabled, 'tgok0') : '<div class="row" style="margin-top:.8em"><button type="button" class="stdbtn" onclick="tgSwitch(\'off\')">' + t('str_turn_notifications_off') + '</button></div>')
				+ '<div class="row" style="margin-top:10px"><button type="button" class="stdbtn" onclick="tgPicker()" title="' + esc(t('str_restart_the_setup_help')) + '">' + t('str_restart_the_setup') + '</button>'
				+ ' <a href="#" class="small" onclick="tgEdit(); return false">' + t('str_change_the_bot_or_the_groups') + '</a></div>'
				+ '<div class="small" style="margin-top:.8em">' + t('str_telegram_is_already_set_up_help') + '</div>'
			: '<span style="font-size:1.1em">&#9992; ' + t('str_telegram_notifications_are_not_help') + '</span>'
				+ (s.bot ? '<br>' + t('str_bot_saved_choose_the_groups_below', {name: esc(s.bot_name || t('str_set'))}) : ''))
			+ (s.other_lines ? '<div class="small">' + t('str_the_notification_boxes_of_help') + '</div>' : '')
			// always in sight, set up or not (owner 2026-10-26)
			+ '<div class="small" style="margin-top:.8em">' + t('str_notifications_start_only_after_help') + '</div>';
		if (on) { if (!TG.scenario) $('tgguide').style.display = 'none'; }
		// the bot is saved but no group yet (a reload or Back in the middle of the guide, owner 2026-11-03: the bot had to be
		// found again): the guide goes on at the groups step
		else if (!TG.scenario && s.bot) { TG.scenario = 'have'; TG.step = TG_STEPS.have.indexOf('groups'); TG.conflict = false; tgShow(); }
		else if (!TG.scenario) tgPicker();
	};
	x.open('GET', 'scripts/telegram_settings.php?status=1', true); x.send();
}
tgLoad();
</script>
