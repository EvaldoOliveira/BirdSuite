<?php
ini_set('display_errors', 0);  // errors to the log, never to the page (2026-10-23 security run)
error_reporting(E_ERROR);

require_once "scripts/common.php";
$home = get_home();
$config = get_config();
$user = get_user();

// Settings › Audio or Settings › Access (views.php sets it); both ask for the login when a web password is set (US-04:
// RTSP URLs carry camera passwords, Access changes the login itself)
$adv_page = (isset($adv_page) && $adv_page === 'access') ? 'access' : 'audio';
// Access opens with the factory password too, to set the station's own (bug #60)
$adv_page === 'access' ? ensure_password_setup() : ensure_authenticated();

// changes arrive by POST (2026-10-23 test run: sent by GET, the web password landed in the web server log and the
// browser history); the handlers below read $_GET, so the posted fields join it, and a GET keeps only what chooses the
// page (view, the "updated" alert)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $_GET = array_merge((array)$_GET, (array)$_POST);
} else {
  $_GET = array_intersect_key((array)$_GET, array_flip(array('view', 'status')));
}

$adv_error = '';
if(isset($_GET['submit'])) try {
  // only the fields of Settings › Audio and Settings › Access (and the stream choice / pitch switch of the Spectrogram
  // page); every value checked by conf_set_many() (common.php, one rule per key), all or nothing written
  $set = array();
  $restart_livestream = false;
  $update_caddyfile = false;
  // a refused value: nothing written, the page shows again with the reason in a notice (Gitea #85: it died after the
  // menu, leaving a bare sentence and no form)
  $fail = function ($msg) { throw new RuntimeException((string)$msg); };
  // a changed value of these keys: written, and what reads it restarted
  $changed = function ($key, $val, $what = '') use (&$set, &$restart_livestream, &$update_caddyfile, $config) {
    if (strcmp((string)$val, (string)($config[$key] ?? '')) === 0) return;
    $set[$key] = $val;
    if ($what === BS_SVC_LIVESTREAM) $restart_livestream = true;
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
  // the factory password changes only the access (the password, the login, recovery), nothing else
  if (factory_login() && $adv_page !== 'access') $fail(t('str_factory_password_only_sets_help') . ' ' . menu_path('Access'));
  if (isset($set['CADDY_PWD']) && $set['CADDY_PWD'] !== '' && ($set['PWD_RECOVERY'] ?? (string)($config['PWD_RECOVERY'] ?? '')) === '') $fail(t('str_choose_how_to_recover_help'));
  // the user name of the web login (owner 2026-10-25), letters and digits
  if(isset($_GET["caddy_user"]) && $_GET["caddy_user"] !== web_user()) $changed('CADDY_USER', (string)$_GET["caddy_user"], 'caddy');

  if(isset($_GET["birdnetpi_url"])) {
    // no trailing slash; a bare http(s)://host[:port] only, the value becomes a Caddy site address (NEW-SEC-03)
    $birdnetpi_url = rtrim(trim($_GET["birdnetpi_url"]), '/');
    if (conf_check('BIRDNETPI_URL', $birdnetpi_url) === null) $fail(t('str_not_a_web_address', array('url' => $birdnetpi_url)));
    $changed('BIRDNETPI_URL', $birdnetpi_url, 'caddy');
  }

  if(isset($_GET["rtsp_stream"])) {
    // one URL per line or comma, each trimmed, empty ones dropped (Gitea #87: a space after a comma reached the recorder)
    $urls = array_values(array_filter(array_map('trim', preg_split('/[\r\n,]+/', (string)$_GET["rtsp_stream"])), 'strlen'));
    foreach ($urls as $u) {
      if (conf_check('RTSP_STREAM', $u) === null) $fail(t('str_not_a_stream_url', array('url' => $u)));
    }
    $changed('RTSP_STREAM', implode(',', $urls), BS_SVC_LIVESTREAM);
    // the stream heard (Sonogram page) stays inside the list when it shrinks
    if (intval($config['RTSP_STREAM_TO_LIVESTREAM'] ?? 0) >= max(1, count($urls))) $changed('RTSP_STREAM_TO_LIVESTREAM', '0', BS_SVC_LIVESTREAM);
  }
  if (isset($_GET["rtsp_stream_to_livestream"])) {
    // an index of the stream list (the Spectrogram page's choice), out of range = the first stream
    $sel = trim($_GET["rtsp_stream_to_livestream"]);
    $streams = array_values(array_filter(explode(',', (string)($set['RTSP_STREAM'] ?? $config['RTSP_STREAM'] ?? '')), 'strlen'));
    if (!ctype_digit($sel)) $fail(t('str_not_a_stream_url', array('url' => $sel)));
    $changed('RTSP_STREAM_TO_LIVESTREAM', intval($sel) < max(1, count($streams)) ? (string)intval($sel) : '0', BS_SVC_LIVESTREAM);
  }
  foreach (array('freqshift_hi' => 'FREQSHIFT_HI', 'freqshift_lo' => 'FREQSHIFT_LO', 'freqshift_base' => 'FREQSHIFT_BASE',
                 'rec_card' => 'REC_CARD', 'channels' => 'CHANNELS', 'recording_length' => 'RECORDING_LENGTH',
                 'extraction_length' => 'EXTRACTION_LENGTH', 'audiofmt' => 'AUDIOFMT') as $param => $key) {
    if (isset($_GET[$param])) $changed($key, trim((string)$_GET[$param]));
  }
  // the clip is cut from the recording: never longer than it
  $rec_len = intval($set['RECORDING_LENGTH'] ?? $config['RECORDING_LENGTH'] ?? 15);
  if (intval($set['EXTRACTION_LENGTH'] ?? $config['EXTRACTION_LENGTH'] ?? 3) > $rec_len) $set['EXTRACTION_LENGTH'] = $rec_len;

  // nothing changed: nothing written and no service restarted (Gitea #85: every Save restarted recording, analysis and
  // the live stream, a minute of analysis lost for nothing); the page says so
  if (!$set) {
    echo '<script>location.replace("views.php?view=' . ($adv_page === 'audio' ? 'Microphone' : 'Access') . '&status=nochange");</script>';
    die();
  }
  if (!conf_set_many($set, $err)) $fail($err);
  $config = get_config($force_reload=true);

  syslog(LOG_INFO, "Restarting Services");
  if ($update_caddyfile){
      exec("sudo -n " . BS_ROOT_HELPER . " caddyfile > /dev/null 2>&1 &");
  }
  shell_exec(station_prefix() . "restart_services.sh");
  if ($restart_livestream) {
    exec("sudo -n " . BS_SVC_HELPER . " restart " . BS_SVC_LIVESTREAM . ".service");
  }
  // the saved form opens again by GET with the "updated" alert (post/redirect/get: a reload does not send it twice);
  // the menu is already printed, so the browser is sent there by script
  echo '<script>location.replace("views.php?view=' . ($adv_page === 'audio' ? 'Microphone' : 'Access') . '&status=success");</script>';
  die();
} catch (RuntimeException $e) {
  $adv_error = $e->getMessage();
}
?>
<div class="settings">

<?php
$newconfig = get_config();
?>
    <?php // one Save button at the end of each section (owner 2026-11-02), not a floating one under the page; every button
    // sends the page's form, so a change in any section is kept
    $adv_save_btn = function () { ?>
      <div class="row" style="margin-top:10px"><button type="submit" class="stdbtn advsave" onclick="if (window.collectrtspUrls) collectrtspUrls(); if(document.getElementById('advancedform').checkValidity()){this.innerHTML = t('str_updating_please_wait');this.classList.add('disabled')}" name="submit" value="advanced"><?php echo t('str_save'); ?></button></div>
    <?php }; ?>
    <form id="advancedform" action="" method="POST">
      <?php // Settings › Advanced split (owner 2026-10-25): Settings › Audio = the audio input and the live audio pitch shift,
      // Settings › Access = the login and the address; Privacy Threshold in Settings › Model, the update options in
      // System › Status, Minimalist on the Spectrogram page, the Latest page image and rare species in Settings › Themes,
      // the log levels on System › Detection Log ?>
      <?php if ($adv_page === 'audio') { ?>
      <?php // the microphone in a section of its own (owner 2026-11-04): the microphones found with their ports, the one in
      // use, the live sonogram and the gain, saved at once; the recording settings below are saved with the form ?>
      <table class="settingstable"><tr><td>
      <h2><?php echo t('str_microphones'); ?></h2>
      <?php $mic_return = 'views.php?view=Microphone'; include __DIR__ . '/mic_controls.php'; // the microphone and its gain (shared with First Run) ?>
      </td></tr></table><br>
      <table class="settingstable"><tr><td>
      <h2><?php echo t('str_audio_source'); ?></h2>
      <label for="rec_card"><?php echo t('str_audio_card'); ?> </label>
      <input name="rec_card" id="rec_card" type="text" size="12" value="<?php echo htmlspecialchars((string)$newconfig['REC_CARD'], ENT_QUOTES); ?>" required/><br>
      <?php echo t('str_audio_card_birdnet_mic_help'); ?> <?php echo t('str_choose_the_dsnoop_device_if_it_help'); ?><br><br>
      <label for="channels"><?php echo t('str_audio_channels'); ?> </label>
      <input name="channels" id="channels" type="number" style="width:3em;" min="1" max="32" step="1" value="<?php echo intval($newconfig['CHANNELS']); ?>" required/><br>
      <?php echo t('str_set_channels_to_the_number_of_help'); ?> <?php echo t('str_32_max'); ?><br><br>
      <?php // the recording length and the clip's length and format are in Station › Model (owner 2026-11-04) ?>
      <p class="small"><?php echo t('str_moved_recording_settings_help', array('model' => '<a href="views.php?view=Model">' . htmlspecialchars(menu_path('Model')) . '</a>')); ?></p>
      <label id="rtsp_stream_input_label" for="rtsp_stream_url_0"><?php echo t('str_rtsp_stream'); ?> </label>
      <br>
      <?php // the list the page saves (comma-joined by collectrtspUrls): a hidden field, never type=url (Gitea #84: the
      // browser judged "rtsp://a:554,rtsp://b:554" an invalid URL and refused the whole form without a word) ?>
      <input type="hidden" name="rtsp_stream" value="">
      <input style="display: none;" id="rtsp_stream_url_placeholder" name="rtsp_stream_placeholder" type="url" size="60" value="">
        <?php
        //Print out the rtsp urls in their own input fields
		//Explode the stream into an array at the comma
		$rtsp_streams = explode(",", $newconfig['RTSP_STREAM']);
		//Print out existing streams
		foreach ($rtsp_streams as $stream_idx => $stream_url) {
            //For the first input keep the element mostly the same as the original but without styling to align it
			if ($stream_idx === 0) {
				?>
                <input id="rtsp_stream_url_0" name="rtsp_stream_0" type="url" size="60" value="<?php echo htmlspecialchars($stream_url, ENT_QUOTES); ?>">
                <br>
				<?php
			} else {
                //For every other input field, change the id to reflect the URL's index in the array
				?>
                <input id="rtsp_stream_url_<?php echo $stream_idx; ?>" name="rtsp_stream_<?php echo $stream_idx; ?>" type="url" size="60"
                       value="<?php echo htmlspecialchars($stream_url, ENT_QUOTES); ?>">
                <br>
				<?php
			}
		}
        ?>
      <div id="newrtspstream_button_container">
        <br>
        <button type="button" id="newrtspstream" class="netbtn" onclick="addNewrtspInput();"><?php echo t('str_add'); ?></button><br>
      </div>
      <script>
                      //Keep track of how many new input fields were added
                      var number_of_new_rtsp_urls_added = 0;

                      //Function to insert new input fields
                      function addNewrtspInput() {
                          //Find the placeholder input field
                          var url_template_element = document.getElementById('rtsp_stream_url_placeholder');
                          var new_url_input_template = url_template_element.cloneNode();
                          var br_seperator = document.createElement("BR");

                          //Fix up the new element so it's visible, set the style so it's sligned correctly
                          new_url_input_template.setAttribute("id", "rtsp_stream_url_new_" + number_of_new_rtsp_urls_added);
                          new_url_input_template.setAttribute("name", "rtsp_stream_new_" + number_of_new_rtsp_urls_added);
                          new_url_input_template.removeAttribute("style");

                          //Insert the new input field before the button to add new urls
                          var newrtspstream_button = document.getElementById('newrtspstream_button_container');
                          //Insert the new input element before the newrtspstream button
                          newrtspstream_button.parentNode.insertBefore(new_url_input_template, newrtspstream_button);
                          //Add a separator before the button
                          newrtspstream_button.parentNode.insertBefore(br_seperator, newrtspstream_button);

                          //Increment the counter
                          number_of_new_rtsp_urls_added++;
                      }

                      var rtsp_stream_string = "";
                      var rtsp_stream_string_array = [];

                      //Collect all the rtsp urls that have been set, concat them into a single string and set it into the rtsp_stream input field so it gets saved
                      function collectrtspUrls() {
                          //Reset the array and string so we don't get duplicates
                          rtsp_stream_string = "";
                          rtsp_stream_string_array = [];

                          //Get the inputs by name (which is similar across
                          var existing_rtsp_stream_urls = document.querySelectorAll('[name^="rtsp_stream_"]');
                          //Loop over the result and get the values
                          for (let i = 0; i < existing_rtsp_stream_urls.length; i++) {
                              //Only collect results that re not empty and add them to the array
                              if (existing_rtsp_stream_urls[i].value !== 'undefined' && existing_rtsp_stream_urls[i].value !== "") {
                                  rtsp_stream_string_array.push(existing_rtsp_stream_urls[i].value.trim());
                              }
                          }

                          //if the array is not empty, then implode the array joining all the values by a comma
                          if (rtsp_stream_string_array.length !== 0) {
                              rtsp_stream_string = rtsp_stream_string_array.join(',');
                              //Locate the hidden rtsp_stream input field that we'll populate with the full string which will get saved to the config file
                              var rtsp_stream_input = document.querySelector('[name=rtsp_stream]');
                              rtsp_stream_input.setAttribute('value', rtsp_stream_string);
                          }
                      }
      </script>
      <p><?php echo t('str_if_you_place_an_rtsp_stream_url_help'); ?><br><?php echo t('str_multiple_streams_are_allowed_help'); ?><br><?php echo t('str_analyze_ffmpeg_cpu_memory_usage_help'); ?><br><?php echo t('str_to_remove_all_and_use_the_help'); ?></p>
      <?php $adv_save_btn(); ?>
      </td></tr></table><br>
      <?php } else { ?>
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
		
      <?php } ?>
      <?php if ($adv_page === 'audio') { ?>
      <?php // the hearing settings (frequency lowering) are on Watch › Sonogram, beside its button (owner 2026-11-04) ?>
      <?php // the audio the recorder loses, in a section of its own, the last of the page (owner 2026-11-04): what happened in the last 24 h, and
      // whether a detection that lost more than the limit inside its own seconds is dropped; saved at once ?>
      <?php
      $loss_n = 0; $loss_ms = 0.0; $loss_since = time() - 86400;
      foreach (@file(BS_STATE . '/audio_loss.log', FILE_IGNORE_NEW_LINES) ?: array() as $ll) {
        $pp = preg_split('/\s+/', trim($ll));
        if (count($pp) > 1 && floatval($pp[0]) >= $loss_since) { $loss_n++; $loss_ms += floatval($pp[1]); }
      }
      $lcfg = get_config(); ?>
      <table class="settingstable"><tr><td>
      <h2><?php echo t('str_audio_loss'); ?></h2>
      <p><?php echo t('str_audio_loss_help'); ?></p>
      <p><b><?php echo t('str_last_24_hours'); ?>:</b> <?php echo $loss_n === 0 ? t('str_no_audio_lost') : htmlspecialchars(t('str_n_losses_ms', array('n' => $loss_n, 'ms' => fmt_num($loss_ms, 1)))); ?></p>
      <?php // where to check them (owner 2026-11-04): the sonograms of Watch › Latest with the Audio loss filter on ?>
      <p><?php echo t('str_check_the_detections_with_lost_help', array('menu' => menu_path('Latest'))); ?>
        <a class="netbtn" href="views.php?view=Latest&amp;lview=spectrogram&amp;lfilter=loss&amp;lperiod=7d"><?php echo t('str_open_the_detections_with_lost_audio'); ?></a></p>
      <label><input type="checkbox" data-setting="AUDIO_LOSS_DISCARD" data-default-key="AUDIO_LOSS_DISCARD"<?php echo (string)($lcfg['AUDIO_LOSS_DISCARD'] ?? '0') === '1' ? ' checked' : ''; ?>> <?php echo t('str_discard_detections_with_lost_audio'); ?></label><br>
      <label><?php echo t('str_more_than'); ?> <input type="number" min="1" max="1000" step="1" style="width:5em" data-setting="AUDIO_LOSS_MAX_MS" data-default-key="AUDIO_LOSS_MAX_MS" value="<?php echo intval($lcfg['AUDIO_LOSS_MAX_MS'] ?? 20); ?>"> <?php echo t('str_ms_lost_inside_the_detection'); ?></label>
      <p class="small"><?php echo t('str_audio_loss_discard_help'); ?></p>
      </td></tr></table><br>

      <?php } ?>
      <br><br>
      <input type="hidden" name="view" value="<?php echo $adv_page === 'audio' ? 'Microphone' : 'Access'; ?>">
      <input type="hidden" name="advanced_form" value="1">
<?php
if ($adv_error !== '') {
  echo '<script>bsNotice(' . json_encode($adv_error, JSON_HEX_TAG | JSON_UNESCAPED_UNICODE) . ');</script>';
} elseif (isset($_GET['status'])) {
  echo '<script>bsNotice(' . json_encode(t($_GET['status'] === 'nochange' ? 'str_nothing_to_save' : 'str_settings_successfully_updated'), JSON_HEX_TAG | JSON_UNESCAPED_UNICODE) . ');</script>';
}
?>
      </form>
</div>
