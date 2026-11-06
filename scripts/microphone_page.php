<?php
################################################################################
# scripts/microphone_page.php - STATION › MICROPHONE: THE MICROPHONES, THE AUDIO SOURCE AND THE AUDIO LOSS
#
# PURPOSE:
#   The page of Station › Microphone: the microphones found and the one in use with its gain (changed at once,
#   scripts/audio_input.php, shared with First Run), the audio source (card, channels, RTSP streams) saved by
#   the form, and the audio the recorder loses with its saved-at-once settings. Its own form and keys only
#   (owner 2026-11-06: advanced.php served Microphone and Access with one handler).
#
# DEPENDENCIES:
#   - scripts/common.php (lib/settings.php: settings_post_merge, settings_redirect, settings_notice)
#   - scripts/mic_controls.php, scripts/audio_input.php, scripts/restart_services.sh, birdsuite-svc
#
# USAGE:
#   views.php?view=Microphone; POST submit=advanced with rec_card, channels, rtsp_stream,
#   rtsp_stream_to_livestream
################################################################################

error_reporting(E_ERROR);
ini_set('display_errors', 0);  // errors to the log, never to the page (2026-10-23 security run)

require_once "scripts/common.php";
$home = get_home();
$config = get_config();
$user = get_user();

ensure_authenticated();

// changes arrive by POST (2026-10-23 test run: the handler reads $_GET, so the posted fields join it, and a GET keeps
// only what chooses the page); the Sonogram page's RTSP choice posts here too (rtsp_stream_to_livestream, submit=advanced)
settings_post_merge(array('view', 'status'));
$adv_error = '';
if(isset($_GET['submit'])) try {
	// only the fields of Station › Microphone; every value checked by conf_set_many() (common.php, one rule per key),
	// all or nothing written; a refused value shows the page again with the reason in a notice (Gitea #85)
	$set = array();
	$restart_livestream = false;
	$fail = function ($msg) { throw new RuntimeException((string)$msg); };
	// a changed value of these keys: written, and what reads it restarted
	$changed = function ($key, $val, $what = '') use (&$set, &$restart_livestream, $config) {
		if (strcmp((string)$val, (string)($config[$key] ?? '')) === 0) return;
		$set[$key] = $val;
		if ($what === BS_SVC_LIVESTREAM) $restart_livestream = true;
	};
	// the factory password changes only the access (the password, the login, recovery), nothing else
	if (factory_login()) $fail(t('str_factory_password_only_sets_help') . ' ' . menu_path('Access'));
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
	// nothing changed: nothing written and no service restarted (Gitea #85); the page says so
	if (!$set) settings_redirect('Microphone', 'nochange');
	if (!conf_set_many($set, $err)) $fail($err);
	$config = get_config($force_reload=true);
	syslog(LOG_INFO, "Restarting Services");
	shell_exec(station_prefix() . "restart_services.sh");
	if ($restart_livestream) {
		exec("sudo -n " . BS_SVC_HELPER . " restart " . BS_SVC_LIVESTREAM . ".service");
	}
	settings_redirect('Microphone', 'success');
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

			<br><br>
			<input type="hidden" name="view" value="Microphone">
			<input type="hidden" name="advanced_form" value="1">
<?php settings_notice($adv_error); ?>
			</form>
</div>
