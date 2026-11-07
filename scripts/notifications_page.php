<?php
################################################################################
# scripts/notifications_page.php - ALERTS › WHAT AND WHEN: THE NOTIFICATION SETTINGS AND TEXTS
#
# PURPOSE:
#   The page of Alerts › What and when: when a Normal species notifies, which species notify, the variables,
#   the Normal and Rare titles, bodies, Apprise configurations, quiet hours and the test buttons. Its own form
#   and keys only (owner 2026-11-06, formerly a section of the one settings form).
#
# DEPENDENCIES:
#   - scripts/common.php (lib/settings.php helpers)
#   - config/notifications/{apprise,apprise-rare,body,body-rare}.txt
#   - scripts/send_test_notification.py, scripts/restart_services.sh
#
# USAGE:
#   views.php?view=What%20and%20when; POST submit=settings (the form), POST sendtest=true (a test
#   notification)
################################################################################

error_reporting(E_ERROR);
ini_set('display_errors', 0);  // errors to the log, never to the page (2026-10-23 security run)
define('__ROOT__', dirname(dirname(__FILE__)));
require_once(__ROOT__.'/scripts/common.php');

$user = get_user();
$home = get_home();
$config = get_config();
set_timezone();

ensure_authenticated();

// changes arrive by POST (2026-10-23 test run: sent by GET, the tokens and the notification URLs landed in the web
// server log and the browser history); the handler below reads $_GET, so the posted fields join it, and a GET keeps
// only what chooses the page (view, the "updated" alert)
settings_post_merge(array('view', 'status'));

if (file_exists(BS_NOTIFY . "/apprise.txt")) {
	$apprise_config = file_get_contents(BS_NOTIFY . "/apprise.txt");
} else {
	$apprise_config = "";
}
$apprise_config_rare = file_exists(BS_NOTIFY . "/apprise-rare.txt") ? file_get_contents(BS_NOTIFY . "/apprise-rare.txt") : "";
$apprise_notification_body_rare = file_exists(BS_NOTIFY . "/body-rare.txt") ? file_get_contents(BS_NOTIFY . "/body-rare.txt") : "";

if (file_exists(BS_NOTIFY . "/body.txt")) {
	$apprise_notification_body = file_get_contents(BS_NOTIFY . "/body.txt");
} else {
	$apprise_notification_body = "";
}

$cfg_error = '';
if(($_GET['submit'] ?? '') === 'settings') try {
	$apprise_input = (string)($_GET['apprise_input'] ?? '');
	$apprise_input_rare = (string)($_GET['apprise_input_rare'] ?? '');
	$apprise_notification_title = (string)($_GET['apprise_notification_title'] ?? '');
	$apprise_notification_body = htmlspecialchars_decode((string)($_GET['apprise_notification_body'] ?? ''), ENT_QUOTES);
	// quiet hours per tier (owner 2026-10-08): HH:MM to HH:MM, empty = always notify
	$quiet = array();
	foreach (array('quiet_start_normal' => 'APPRISE_QUIET_START_NORMAL', 'quiet_end_normal' => 'APPRISE_QUIET_END_NORMAL',
								 'quiet_start_rare' => 'APPRISE_QUIET_START_RARE', 'quiet_end_rare' => 'APPRISE_QUIET_END_RARE') as $param => $key) {
		if (isset($_GET[$param])) {
			$quiet[$key] = preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $_GET[$param]) ? $_GET[$param] : '';
		}
	}
	// region-rare alerts (Rare block checkbox; the rare title field is always sent with it)
	$notify_region_rare = isset($_GET['notify_region_rare']) ? 1 : 0;
	$apprise_notification_title_rare = (string)($_GET['apprise_notification_title_rare'] ?? '');
	$apprise_notification_body_rare = htmlspecialchars_decode((string)($_GET['apprise_notification_body_rare'] ?? ''), ENT_QUOTES);
	$minimum_time_limit = trim((string)($_GET['minimum_time_limit'] ?? ($config['APPRISE_MIN_SEC_PER_SPECIES'] ?? '0')));
	// written into BirdSuite.conf, which shell scripts source: a plain address only, nothing a shell would expand
	if(isset($_GET['notification_email']) && preg_match('/^[^\s"\'`$\\\\@]+@[^\s"\'`$\\\\@]+$/', trim($_GET['notification_email']))) { $notification_email = trim($_GET['notification_email']); }
	$notification_default_tier = strtolower((string)($_GET['notification_default_tier'] ?? ($config['NOTIFICATION_DEFAULT_TIER'] ?? 'normal')));
	if(!in_array($notification_default_tier, ['muted', 'normal', 'rare'], true)) $notification_default_tier = 'normal';
	$apprise_notify_each_detection = isset($_GET['apprise_notify_each_detection']) ? 1 : 0;
	$apprise_notify_new_species = isset($_GET['apprise_notify_new_species']) ? 1 : 0;
	$apprise_notify_new_species_each_day = isset($_GET['apprise_notify_new_species_each_day']) ? 1 : 0;
	settings_ntp_on();
	// every key through conf_set_many() (common.php): each value checked against its rule, all or nothing written
	$set = array();
	$set['APPRISE_NOTIFICATION_TITLE'] = $apprise_notification_title;
	foreach ($quiet as $key => $val) $set[$key] = $val;
	$set['APPRISE_NOTIFY_REGION_RARE'] = $notify_region_rare;
	$set['APPRISE_NOTIFICATION_TITLE_RARE'] = $apprise_notification_title_rare;
	$set['APPRISE_NOTIFY_EACH_DETECTION'] = $apprise_notify_each_detection;
	$set['APPRISE_NOTIFY_NEW_SPECIES'] = $apprise_notify_new_species;
	$set['APPRISE_NOTIFY_NEW_SPECIES_EACH_DAY'] = $apprise_notify_new_species_each_day;
	$set['APPRISE_MIN_SEC_PER_SPECIES'] = $minimum_time_limit;
	if (isset($notification_email)) $set['NOTIFICATION_EMAIL'] = $notification_email;
	$set['NOTIFICATION_DEFAULT_TIER'] = $notification_default_tier;
	// nothing changed (the values and the four notification files): nothing written, no restart, the page says so (Gitea #88)
	$dirty = settings_dirty($set, $config) || $apprise_input !== $apprise_config || $apprise_input_rare !== $apprise_config_rare
		|| $apprise_notification_body !== (string)@file_get_contents(BS_NOTIFY . "/body.txt") || $apprise_notification_body_rare !== (string)@file_get_contents(BS_NOTIFY . "/body-rare.txt");
	if (!$dirty) settings_redirect('What and when', 'nochange');
	if (!conf_set_many($set, $err)) throw new RuntimeException((string)$err);
	if (file_exists(BS_FIRSTRUN)) { @unlink(BS_FIRSTRUN); }
	// the four notification files beside the settings (the notifier reads them at every send)
	foreach (array('apprise.txt' => $apprise_input, 'apprise-rare.txt' => $apprise_input_rare, 'body.txt' => $apprise_notification_body, 'body-rare.txt' => $apprise_notification_body_rare) as $f => $text) {
		if (@file_put_contents(BS_NOTIFY . '/' . $f, $text) === false) throw new RuntimeException(t('str_error_writing', array('file' => $f)));
		@chmod(BS_NOTIFY . '/' . $f, 0600);  // the station user's only (run 6 observation: they were world-readable)
	}
	syslog(LOG_INFO, "Restarting Services");
	shell_exec(station_prefix() . "restart_services.sh");
	settings_redirect('What and when', 'success');
} catch (RuntimeException $e) {
	$cfg_error = $e->getMessage();
}

if(isset($_GET['sendtest']) && $_GET['sendtest'] == "true") {
	$conf = $_GET['apprise_config'];
	$title = $_GET['apprise_notification_title'];
	$body = $_GET['apprise_notification_body'];

	$temp_conf = tmpfile();
	$t_conf_path = stream_get_meta_data($temp_conf)['uri'];
	chmod($t_conf_path, 0644);
	fwrite($temp_conf, $conf);

	$temp_body = tmpfile();
	$t_body_path = stream_get_meta_data($temp_body)['uri'];
	chmod($t_body_path, 0644);
	fwrite($temp_body, $body);

	$cmd = station_prefix() . BS_PYTHON . " " . BS_SCRIPTS . "/send_test_notification.py --body $t_body_path --config $t_conf_path --title " . escapeshellarg($title) . " 2>&1";  // escapeshellarg (2026-10-22): escapeshellcmd inside quotes left \$comname in the title
	$ret = shell_exec($cmd);
	echo "<pre class=\"bash\">".$ret."</pre>";
	fclose($temp_conf);
	fclose($temp_body);

	die();
}

// the settings again after a save, so the page shows them
$config = get_config($force_reload=true);
?>
<div class="settings">
		<form id="basicform" action=""  method="POST">

<script>
function sendTestNotification(e, which, msgspan, titlefield, bodyfield) {
	which = which || "apprise_input";
	msgspan = msgspan || "testsuccessmsg";
	document.getElementById(msgspan).innerHTML = "";
	e.classList.add("disabled");

	var apprise_notification_title = (titlefield && document.getElementsByName(titlefield)[0].value)
		|| document.getElementsByName("apprise_notification_title")[0].value;
	var apprise_notification_body = encodeURIComponent((bodyfield && document.getElementsByName(bodyfield)[0].value)
		|| document.getElementsByName("apprise_notification_body")[0].value);
	var apprise_config = encodeURIComponent(document.getElementsByName(which)[0].value);

	var xmlHttp = new XMLHttpRequest();
		xmlHttp.onreadystatechange = function() { 
				if (xmlHttp.readyState == 4 && xmlHttp.status == 200) {
						document.getElementById(msgspan).innerHTML = this.responseText + " " + t('str_test_sent') + " " + t('str_make_sure_to_update_settings_below')
						e.classList.remove("disabled");
				}
		}
		// POST (2026-10-23 test run): the notification URLs carry tokens, which a GET left in the web server log
		xmlHttp.open("POST", "scripts/notifications_page.php", true); // true for asynchronous
		xmlHttp.setRequestHeader("Content-Type", "application/x-www-form-urlencoded");
		xmlHttp.send("sendtest=true"+"&apprise_notification_body="+apprise_notification_body+"&apprise_config="+apprise_config+"&apprise_notification_title="+encodeURIComponent(apprise_notification_title));
}
</script>
			<table class="settingstable" style="width:100%"><tr><td>
			<h2 id="notifications" ><?php echo t('str_notifications_global'); ?></h2>
			<style>h3.vartitle { font-size: 1.2em; font-weight: 700; margin: 16px 0 6px; }</style>
			<p><?php echo t('str_apprise_notifications_can_be_help'); ?><br><?php echo t('str_each_service_should_be_on_its_help'); ?></p>
			<?php // the global options by theme (owner 2026-10-22): when a Normal species notifies (the Repetition limit under
			// "each new detection"), which species notify; the e-mail recipient lives only in Notifications › E-mail ?>
			<h3 class="vartitle"><?php echo t('str_when_a_normal_species_is_notified'); ?></h3>
			<?php // the boxes show what BirdSuite.conf says, also with no channel set up yet (2026-10-23 test run: an empty
			// Apprise box showed them off, and the next Update saved them off) ?>
			<input type="checkbox" name="apprise_notify_each_detection" <?php if($config['APPRISE_NOTIFY_EACH_DETECTION'] == 1) { echo "checked"; };?> >
			<label for="apprise_notify_each_detection"><?php echo t('str_notify_each_new_detection'); ?></label><br>
			<div style="margin:4px 0 8px 2em">
				<label for="minimum_time_limit"><?php echo t('str_repetition_limit_seconds_before_help'); ?></label>
				<input type="number" id="minimum_time_limit" name="minimum_time_limit" value="<?php echo $config['APPRISE_MIN_SEC_PER_SPECIES'];?>" style="width:6em;" min="0"> <?php echo t('str_0_no_limit'); ?><br>
				<small><?php echo t('str_also_applies_to_the_region_rare_help'); ?></small>
			</div>
			<input type="checkbox" name="apprise_notify_new_species_each_day" <?php if($config['APPRISE_NOTIFY_NEW_SPECIES_EACH_DAY'] == 1) { echo "checked"; };?> >
			<label for="apprise_notify_new_species_each_day"><?php echo t('str_notify_each_species_first_help'); ?></label><br>
			<input type="checkbox" name="apprise_notify_new_species" <?php if($config['APPRISE_NOTIFY_NEW_SPECIES'] == 1) { echo "checked"; };?> >
			<label for="apprise_notify_new_species"><?php echo t('str_notify_each_infrequent_species_help'); ?></label><br>
			<small><?php echo t('str_when_more_than_one_applies_one_help'); ?></small>
			<h3 class="vartitle"><?php echo t('str_which_species_are_notified'); ?></h3>
			<label for="notification_default_tier"><?php echo t('str_default_notification_tier_for_help'); ?></label>
			<select name="notification_default_tier" id="notification_default_tier" style="width:12em;">
				<?php $ndt = strtolower($config['NOTIFICATION_DEFAULT_TIER'] ?? 'normal');
				foreach (['muted' => 'str_none_muted', 'normal' => 'str_normal_tier', 'rare' => 'str_rare_tier'] as $t_val => $t_label) {
					$t_sel = $ndt === $t_val ? " selected" : "";
					echo "<option value='{$t_val}'{$t_sel}>" . t($t_label) . "</option>";
				} ?>
			</select><br>
			<label><?php echo t('str_per_species_notification_policy'); ?> </label>
			<a href="views.php?view=Species"><b><?php echo t('str_edit_the_species_list_in_help'); ?></b></a>:<br>
			&nbsp;&nbsp;<?php echo t('str_set_a_species_to_muted_to_help'); ?><br>
			&nbsp;&nbsp;<?php echo t('str_to_notify_only_selected_species_help'); ?>
<br>
			<h3 class="vartitle"><?php echo t('str_where_they_go'); ?></h3>
			<?php echo t('str_the_e_mail_recipient_is_set_in_help', array('email' => '<a style="text-decoration:underline" href="views.php?view=E-mail">' . menu_path('E-mail') . '</a>')); ?><br>
			<?php echo t('str_the_telegram_bot_and_groups_are_help', array('telegram' => '<a style="text-decoration:underline" href="views.php?view=Telegram">' . menu_path('Telegram') . '</a>')); ?><br>
			</td></tr></table><br>
			<?php // the variables in a section of their own, after the global options (owner 2026-10-22) ?>
			<table class="settingstable" style="width:100%"><tr><td>
			<h2><?php echo t('str_notification_variables'); ?></h2>
			<?php // the variables as a table, named after the app's columns (owner 2026-10-22, a rewrite: the names before are gone);
			// personal data never as an example (the e-mail address and the coordinates are generic)
			// the newest by Date, Time (run 5 RV2, NTC-06: detections is a view of the current location, it has no rowid)
			$ex_sci = (string)get_db()->querySingle('SELECT Sci_Name FROM detections ORDER BY Date DESC, Time DESC LIMIT 1') ?: 'Pitangus sulphuratus';
			$ex = array('Com_Name' => common_name($ex_sci), 'Sci_Name' => $ex_sci);
			$vrow = function ($names, $example, $desc) {
				return '<tr><td><code>' . implode('</code>, <code>', array_map('htmlspecialchars', (array)$names)) . '</code></td><td>' . htmlspecialchars($example) . '</td><td>' . $desc . '</td></tr>';
			};
			$vhead = '<tr><th>' . t('str_variable') . '</th><th>' . t('str_example') . '</th><th>' . t('str_description') . '</th></tr>'; ?>
			<p><?php echo t('str_each_name_in_the_title_or_the_help'); ?><br>
				<?php echo t('str_the_names_follow_the_columns_of_help'); ?></p>
			<style>.vartable td:first-child { white-space: nowrap; } .vartable td { vertical-align: top; text-align: left; } .vartable { margin-bottom: 8px; }
				h3.vartitle { font-size: 1.2em; font-weight: 700; margin: 16px 0 6px; } p.varnote { margin-bottom: 1em; }</style>
			<h3 class="vartitle"><?php echo t('str_title_and_body'); ?></h3>
			<table class="stdtable vartable" data-nosort>
				<?php echo $vhead
					. $vrow('$common_name', $ex['Com_Name'], t('str_common_name'))
					. $vrow('$scientific_name', $ex['Sci_Name'], t('str_scientific_name'))
					. $vrow('$reason', t('str_rare_species_lc'), t('str_the_reason_a_notification_was_sent'))
					. $vrow('$conf_pct', '91', t('str_confidence_conf_as_a_percentage'))
					. $vrow('$conf', '0.91', t('str_confidence_conf_as_a_number'))
					. $vrow('$conf_min_pct', '20%', t('str_minimum_confidence_percentage_help'))
					. $vrow('$conf_min', '0.2', t('str_minimum_confidence_number_help'))
					. $vrow('$conf_manual_pct', '60%', t('str_the_species_manual_confidence_help'))
					. $vrow('$occurrence_probability', '12.5%', t('str_probability_of_prob_help'))
					. $vrow('$occurrence_probability_min', '3%', t('str_minimum_probability_at_the_location_min_help'))
					. $vrow('$station', get_sitename(), t('str_the_stations_name_settings_stations'))
					. $vrow('$location', (string)((location_current() ?: array())['name'] ?? ''), t('str_the_location_the_detection_was_help') . '<br>' . t('str_a_text_without_this_tag_gets_a_help'))
					. $vrow('$date', date('Y-m-d'), t('str_date'))
					. $vrow('$time', date('H:i:s'), t('str_time'))
					. $vrow('$calendar_week', date('W'), t('str_iso_calendar_week'))
					. $vrow('$latitude', '-23.55', t('str_latitude'))
					. $vrow('$longitude', '-46.63', t('str_longitude'))
					. $vrow('$sigmoid_sensitivity', '1.0', t('str_sigmoid_sensitivity_sigm_sens'))
					. $vrow('$overlap', '1.2', t('str_overlap_in_seconds'))
					. $vrow('$recording_length', '15 s', t('str_recording_length_rec_length'))
					. $vrow('$listen_url', 'https://…?filename=…', t('str_a_link_to_the_detection'))
					. $vrow('$listen_link_markdown', '[' . t('str_listen_here') . '](…)', t('str_a_masked_link_to_the_detection') . '<br>' . t('str_only_useful_for_services_that_help'))
					. $vrow('$image', '', t('str_a_photo_of_the_species_attached_help'))
					. $vrow('$audio', '', t('str_attaches_the_detections_audio_help')); ?>
			</table>
			<h3 class="vartitle"><?php echo t('str_only_in_the_apprise_help'); ?></h3>
			<p class="varnote"><?php echo t('str_never_in_the_notification_title_help'); ?> <?php echo t('str_secrets_never_appear_on_this_page'); ?></p>
			<table class="stdtable vartable" data-nosort>
				<?php echo $vhead
					. $vrow(array('$user', '$hostname'), get_user() . ', ' . gethostname(), t('str_this_stations_user_and_hostname_help', array('example' => '<code>from=$user@$hostname.local</code>')))
					. $vrow('$email_recipient', 'you@example.com', t('str_the_recipient_set_in_help'))
					. $vrow(array('$email_user', '$email_password', '$email_sender'), '', t('str_the_sender_account_set_in_help'))
					. $vrow(array('$telegram_bot', '$telegram_group_normal', '$telegram_group_rare'), '', t('str_the_bot_and_the_groups_set_in_help'))
					. $vrow('$token1 … $token20', '', t('str_other_secret_values_from_the_help')); ?>
			</table>
			</td></tr></table><br>
			<table class="settingstable" style="width:100%"><tr><td>
			<h2><?php echo t('str_notifications_normal'); ?></h2>
			<label for="apprise_notification_title"><?php echo t('str_normal_notification_title'); ?> </label>
			<input name="apprise_notification_title" style="width: 100%" type="text" value="<?php print($config['APPRISE_NOTIFICATION_TITLE']);?>" /><br><br>
			<label for="apprise_notification_body"><?php echo t('str_normal_notification_body'); ?> </label>
			<textarea class="testbtn" name="apprise_notification_body" rows="18" style="width:100%; margin-top:0" type="text" ><?php print($apprise_notification_body);?></textarea><br><br>
			<?php // the two default channels, on or off, set up on their own pages (owner 2026-10-22: Telegram and e-mail always in sight)
			// on = set up and its test confirmed, as the channel's page and the sender decide (notify_channel_confirmed,
			// utils/notify_channels.py); set up but off or not confirmed yet = off, with the link to turn it on or test it there;
			// not set up = off, set up (run 5 RV2, NTC-07: an e-mail switched off was shown on)
			$channels = function ($text) {
				$one = function ($name, $line, $channel, $view) {
					$st = notify_channel_state($channel);
					if ($line && $st === 'confirmed') return $name . ' <b>' . t('str_on') . '</b>';
					$todo = !$line ? t('str_set_up') : ($st === 'off' ? t('str_turn_on_lc') : t('str_test_it_lc'));
					return $name . ' ' . t('str_off_lc') . ' (<a style="text-decoration:underline" href="views.php?view=' . rawurlencode($view) . '">' . $todo . '</a>)';
				};
				return '<div style="margin:4px 0 6px">' . t('str_channels') . ' ' . $one('Telegram', preg_match('~^\s*tgram://~mi', (string)$text), 'telegram', 'Telegram')
					. ' &middot; ' . $one(t('str_e_mail'), preg_match('~^\s*mailtos?://~mi', (string)$text), 'email', 'E-mail') . '</div>';
			};
			echo $channels($apprise_config ?? ''); ?>
			<label for="apprise_input"><?php echo t('str_apprise_notifications_configuration'); ?> </label>
			<textarea placeholder="tgram://$token1/$token2   (<?php echo t('str_token1_token20_secrets_help'); ?>)
mailto://{user}:{password}@gmail.com
twitter://{ConsumerKey}/{ConsumerSecret}/{AccessToken}/{AccessSecret}
https://discordapp.com/api/webhooks/{WebhookID}/{WebhookToken}
..." style="vertical-align: top; width:100%; margin-top:0" class="testbtn" name="apprise_input" rows="3" type="text" ><?php print($apprise_config);?></textarea><br>

			<br>

			<label><?php echo t('str_quiet_hours_normal_from'); ?> <input type="time" name="quiet_start_normal" value="<?php echo htmlspecialchars(trim($config['APPRISE_QUIET_START_NORMAL'] ?? '', '"')); ?>">
			<?php echo t('str_to_lc'); ?> <input type="time" name="quiet_end_normal" value="<?php echo htmlspecialchars(trim($config['APPRISE_QUIET_END_NORMAL'] ?? '', '"')); ?>"></label>
			<small><?php echo t('str_no_normal_notifications_in_this_help'); ?></small><br><br>
			<button type="button" class="stdbtn" onclick="sendTestNotification(this)"><?php echo t('str_send_test_notification'); ?></button><br>
			<span id="testsuccessmsg"></span>
			</td></tr></table><br>
			<table class="settingstable" style="width:100%"><tr><td>
			<h2><?php echo t('str_notifications_rare'); ?></h2>
			<?php // Region-rare alerts first; off = the rest of the box greyed, as BirdWeather (owner 2026-10-22); the fields stay
			// editable and are sent with the form ?>
			<?php $rre = ($config['APPRISE_NOTIFY_REGION_RARE'] ?? '1') == '1'; ?>
			<label><input type="checkbox" name="notify_region_rare" id="notify_region_rare" <?php echo $rre ? 'checked' : ''; ?> onchange="document.getElementById('rare_box').style.opacity = this.checked ? '' : '0.7'"> <?php echo t('str_region_rare_alerts'); ?></label>
			<small><?php echo t('str_any_species_the_location_model_help'); ?></small>
			<div id="rare_box" style="margin-top:1em;<?php echo $rre ? '' : 'opacity:0.7;'; ?>">
			<label for="apprise_notification_title_rare"><?php echo t('str_rare_notification_title'); ?> </label>
			<input name="apprise_notification_title_rare" style="width: 100%" type="text" placeholder="<?php echo t('str_empty_use_the_normal_title'); ?>" value="<?php print($config['APPRISE_NOTIFICATION_TITLE_RARE'] ?? '');?>" /><br><br>
			<label for="apprise_notification_body_rare"><?php echo t('str_rare_notification_body'); ?> </label>
			<textarea class="testbtn" name="apprise_notification_body_rare" rows="18" style="width:100%; margin-top:0" type="text" placeholder="<?php echo t('str_empty_use_the_normal_body'); ?>"><?php print($apprise_notification_body_rare);?></textarea><br><br>
			<?php echo $channels($apprise_config_rare ?? ''); ?>
			<label for="apprise_input_rare"><?php echo t('str_apprise_configuration_for_rare_help'); ?> </label>
			<textarea placeholder="tgram://$token1/$token3   (<?php echo t('str_token1_token20_placeholders_help'); ?>)
mailto://{user}:{password}@gmail.com
..." style="vertical-align: top; width:100%; margin-top:0" class="testbtn" name="apprise_input_rare" rows="3" type="text" ><?php print($apprise_config_rare);?></textarea><br>
			&nbsp;&nbsp;<?php echo t('str_empty_rare_apprise_title_body_help'); ?><br><br>
			<label><?php echo t('str_quiet_hours_rare_from'); ?> <input type="time" name="quiet_start_rare" value="<?php echo htmlspecialchars(trim($config['APPRISE_QUIET_START_RARE'] ?? '', '"')); ?>">
			<?php echo t('str_to_lc'); ?> <input type="time" name="quiet_end_rare" value="<?php echo htmlspecialchars(trim($config['APPRISE_QUIET_END_RARE'] ?? '', '"')); ?>"></label>
			<small><?php echo t('str_no_rare_notifications_in_this_help'); ?></small><br><br>
			<button type="button" class="stdbtn" onclick="sendTestNotification(this, 'apprise_input_rare', 'testsuccessmsgrare', 'apprise_notification_title_rare', 'apprise_notification_body_rare')"><?php echo t('str_send_test_notification_rare'); ?></button><br>
			<span id="testsuccessmsgrare"></span>
			</div>
			</td></tr></table><br>

			<input type="hidden" name="status" value="success">
			<input type="hidden" name="submit" value="settings">
<div class="float">
			<button type="submit" id="basicformsubmit" onclick="if(document.getElementById('basicform').checkValidity()){this.innerHTML = t('str_updating_please_wait');this.classList.add('disabled')}" name="view" value="What and when"><?php echo t('str_update_settings'); ?></button></div>
<?php settings_notice($cfg_error); ?>
			</form>
</div>
