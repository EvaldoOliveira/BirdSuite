<?php
################################################################################
# scripts/model_page.php - STATION › MODEL: COMMON NAME LANGUAGE, MODELS AND RECORDING
#
# PURPOSE:
#   The page of Station › Model: the Common name Language (its own Save), the official and shadow models with
#   their thresholds, the privacy filter and the defaults table (Apply = save + the services restart), the
#   recording length and the clip settings (saved at once, settings_set.php). Its own form and keys only
#   (owner 2026-11-06: the one form shared with Sharing and What and when is gone, bugs #85 / #88).
#
# DEPENDENCIES:
#   - scripts/common.php (lib/settings.php: settings_post_merge, settings_dirty, settings_redirect, settings_notice)
#   - scripts/install_language_label.sh, scripts/species.py (the species list tester), scripts/restart_services.sh
#
# USAGE:
#   views.php?view=Model; POST submit=settings (the form), POST set_lang=<code>, GET threshold=<0..1>
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
settings_post_merge(array('view', 'status', 'threshold'));

function syslog_shell_exec($cmd, $sudo_user = null) {
	if ($sudo_user) {
		$cmd = station_prefix() . $cmd;  // the station user (directly when the pages already are that user)
	}
	$output = shell_exec($cmd);

	if (strlen($output) > 0) {
		syslog(LOG_INFO, $output);
	}
}

// the Common name Language saved by its own Save button: the setting and the model's label file (labels.txt). The names
// are presentation only (owner 2026-11-05, "one index only": the database and the lists hold the scientific name), so
// nothing is renamed and no service restarts: the pages look the names up when they show them, and the analysis reads the
// language again when BirdSuite.conf changes (analysis.py reload_settings_if_changed)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_GET['set_lang'])) {
	header('Content-Type: application/json');
	$lang = (string)$_GET['set_lang'];
	if (!isset(common_name_languages()[$lang]) || !is_file(BS_MODEL . "/l18n/labels_$lang.json")) { echo json_encode(array('ok' => false)); die(); }
	if ($lang !== ($config['DATABASE_LANG'] ?? '')) {
		if (!conf_set('DATABASE_LANG', $lang)) { echo json_encode(array('ok' => false)); die(); }
		syslog_shell_exec(BS_SCRIPTS . "/install_language_label.sh", $user);
		syslog(LOG_INFO, "Common name Language changed to '$lang'");
	}
	echo json_encode(array('ok' => true));
	die();
}

if(isset($_GET['threshold'])) {
	$threshold = $_GET['threshold'];
	if (!is_numeric($threshold) || $threshold < 0 || $threshold > 1) {
		die('Invalid threshold value');
	}

	$command = station_prefix() . BS_PYTHON . " " . BS_SCRIPTS . "/species.py --threshold $threshold 2>&1";

	$output = shell_exec($command);

	echo $output;
	die();
}

$cfg_error = '';
if(($_GET['submit'] ?? '') === 'settings') try {
	$language = (string)($_GET["language"] ?? ($config['DATABASE_LANG'] ?? ''));
	$model = (string)($_GET["model"] ?? ($config['MODEL'] ?? ''));
	$known_models = array("BirdNET_GLOBAL_6K_V2.4_Model_FP16", "BirdNET_6K_GLOBAL_MODEL", "BirdNET-Plus_V3.0-preview3.1_Global_10K");
	$shadow_model = isset($_GET['shadow_model']) && in_array($_GET['shadow_model'], $known_models) && $_GET['shadow_model'] != $model ? $_GET['shadow_model'] : '';
	$shadow_min_conf = isset($_GET['shadow_min_conf']) && is_numeric($_GET['shadow_min_conf']) ? max(0.01, min(0.99, round(floatval($_GET['shadow_min_conf']), 2))) : ($config['SHADOW_MIN_CONF'] ?? 0.7);
	$sf_thresh = (string)($_GET["sf_thresh"] ?? ($config['SF_THRESH'] ?? ''));
	// US-47 (owner 2026-09-22): both models and their parameters live in one "Models" block.
	// Official: CONFIDENCE / SENSITIVITY / SF_THRESH; shadow: SHADOW_MIN_CONF / SHADOW_SENS /
	// SHADOW_GEO_THRESH; OVERLAP is shared (one recording pipeline, both models see the same windows).
	$confidence = isset($_GET['confidence']) && is_numeric($_GET['confidence']) ? max(0.01, min(0.99, round(floatval($_GET['confidence']), 2))) : $config['CONFIDENCE'];
	$sensitivity = isset($_GET['sensitivity']) && is_numeric($_GET['sensitivity']) ? max(0.5, min(1.5, round(floatval($_GET['sensitivity']), 2))) : $config['SENSITIVITY'];
	$overlap = isset($_GET['overlap']) && is_numeric($_GET['overlap']) ? max(0.0, min(2.9, round(floatval($_GET['overlap']), 1))) : $config['OVERLAP'];
	$shadow_sens = isset($_GET['shadow_sens']) && is_numeric($_GET['shadow_sens']) ? max(0.5, min(1.5, round(floatval($_GET['shadow_sens']), 2))) : ($config['SHADOW_SENS'] ?? 1.25);
	$shadow_geo_thresh = isset($_GET['shadow_geo_thresh']) && is_numeric($_GET['shadow_geo_thresh']) ? round(max(0.0005, min(0.99, floatval($_GET['shadow_geo_thresh']))), 4) : ($config['SHADOW_GEO_THRESH'] ?? 0.99);
	// Shadow on/off flag: unchecked = the shadow model and its parameters stay configured
	// but nothing is analysed by it (only the official model runs). Absent key = off (the default).
	$shadow_enabled = isset($_GET['shadow_enabled']) ? 1 : 0;
	// one species per recording (Settings > Models): only the highest-confidence species of each file is kept
	$avoid_duplicity = isset($_GET['avoid_duplicity']) ? 1 : 0;
	// the privacy filter, from Settings › Advanced (owner 2026-10-25): 0-3 % of the species list; absent = unchanged
	$privacy_threshold = isset($_GET['privacy_threshold']) && is_numeric($_GET['privacy_threshold']) ? max(0, min(3, intval($_GET['privacy_threshold']))) : intval($config['PRIVACY_THRESHOLD'] ?? 1);
	// Swap: the shadow model becomes the official one and vice versa, each keeping its own
	// three parameters (what the owner did by hand on 2026-09-21). Only when a shadow is set.
	if(isset($_GET['swap_models']) && $shadow_model != '') {
		list($model, $shadow_model) = array($shadow_model, $model);
		list($confidence, $shadow_min_conf) = array($shadow_min_conf, $confidence);
		list($sensitivity, $shadow_sens) = array($shadow_sens, $sensitivity);
		list($sf_thresh, $shadow_geo_thresh) = array($shadow_geo_thresh, $sf_thresh);
	}
	$data_model_version = isset($_GET['data_model_version']) ? 2 : 1;
	settings_ntp_on();
	// every key through conf_set_many() (common.php): each value checked against its rule, all or nothing written
	$set = array();
	if ((string)$language !== '') $set['DATABASE_LANG'] = $language;
	$set['MODEL'] = $model;
	$set['CONFIDENCE'] = $confidence;
	$set['SENSITIVITY'] = $sensitivity;
	$set['OVERLAP'] = $overlap;
	$set['SHADOW_MODEL_NAME'] = $shadow_model;
	$set['SHADOW_MIN_CONF'] = $shadow_min_conf;
	$set['SHADOW_SENS'] = $shadow_sens;
	$set['SHADOW_GEO_THRESH'] = $shadow_geo_thresh;
	$set['PRIVACY_THRESHOLD'] = $privacy_threshold;
	$set['AVOID_DUPLICITY'] = $avoid_duplicity;
	$set['SHADOW_ENABLED'] = $shadow_enabled;
	$set['SF_THRESH'] = trim((string)$sf_thresh);
	$set['DATA_MODEL_VERSION'] = $data_model_version;
	// nothing changed: nothing written and no service restarted (Gitea #88); the page says so
	if (!settings_dirty($set, $config)) settings_redirect('Model', 'nochange');
	if (!conf_set_many($set, $err)) throw new RuntimeException((string)$err);
	if ($language != ($config['DATABASE_LANG'] ?? '')) station_numeric_sync((string)($config['UI_LANG'] ?? ''), (string)$language);
	// settings saved here answer the first-run questions too: stop opening the Overview on the wizard
	if (file_exists(BS_FIRSTRUN)) { @unlink(BS_FIRSTRUN); }
	if ($model != $config['MODEL'] || $language != $config['DATABASE_LANG']){
		if(strlen($language) == 2 || strlen($language) == 5){
			syslog_shell_exec(BS_SCRIPTS . "/install_language_label.sh", $user);
			syslog(LOG_INFO, "Successfully changed language to '$language' and model to '$model'");
		}
	}
	syslog(LOG_INFO, "Restarting Services");
	shell_exec(station_prefix() . "restart_services.sh");
	settings_redirect('Model', 'success');
} catch (RuntimeException $e) {
	$cfg_error = $e->getMessage();
}

// the settings again after a save, so the page shows them
$config = get_config($force_reload=true);
// the model names as the page shows them (the picklists and the defaults table): the file name without its suffixes
// (Gitea #89: the full names made the picklists 40 characters wide and pushed the number columns off a phone's screen)
$model_short = function ($n) { return str_replace('_', ' ', preg_replace('/_Model_FP16|_Global_10K|-preview3\.1/', '', (string)$n)); };
?>
<div class="settings">
		<form id="basicform" action=""  method="POST">

<script>
	document.addEventListener('DOMContentLoaded', function() {
	document.getElementById('modelsel').addEventListener('change', function() {
		// the species range model belongs to the V2 models only (owner 2026-10-20)
		if(this.value == "BirdNET_GLOBAL_6K_V2.4_Model_FP16"){
			document.getElementById("soft").style.display="unset";
		} else {
			document.getElementById("soft").style.display="none";
		}
	});
}, false);
</script>
			<?php // Common name Language first on the Model page (owner 2026-10-22); on Station › Model every section has its own
						// button (owner 2026-11-05): Save here (it only renames), Apply (the services restart) for Models and Recording ?>
			<table class="settingstable"><tr><td>
			<h2><?php echo t('str_common_name_language'); ?></h2>
			<?php // the Interface language lives in the menu (flags), not here (owner 2026-10-21) ?>
			<?php // since one index only (owner 2026-11-05) the language changes only the names shown, nothing is renamed ?>
			<p><?php echo t('str_changing_it_only_changes_the_names_help'); ?></p>
			<label for="language"><?php echo t('str_common_name_language_colon'); ?> </label>
			<select name="language" id="language" class="testbtn">
			<?php
				$langs = common_name_languages();

				// Create options for each language
				foreach($langs as $langTag => $langName){
					$isSelected = "";
					if($config['DATABASE_LANG'] == $langTag){
						$isSelected = 'selected="selected"';
					}

					echo "<option value='{$langTag}' $isSelected>" . t($langName) . "</option>";
				}
			?>

			</select> <button type="button" class="stdbtn" onclick="cnlSave(document.querySelector('select[name=language]'))"><?php echo t('str_save'); ?></button> <b id="cnlmsg" class="small"></b>
			<script>
			// saved by its own Save button (owner 2026-11-05: every section of Station › Model has its button; this one only
			// changes the names shown, so Save, not Apply; was saved on change since 2026-10-24)
			function cnlSave(sel) {
				if (sel.value === 'not-selected') return;
				var m = document.getElementById('cnlmsg');
				m.textContent = t('str_saving');
				var x = new XMLHttpRequest();
				x.onload = function () {
					var r; try { r = JSON.parse(this.responseText); } catch (e) { r = {}; }
					m.textContent = r.ok ? t('str_saved_names_shown_in_the_new_language') : (this.status === 401 ? t('str_log_in_first') : t('str_not_saved'));
				};
				x.onerror = function () { m.textContent = t('str_not_saved'); };
				x.open('POST', 'scripts/model_page.php', true);
				x.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
				x.send('set_lang=' + encodeURIComponent(sel.value));
			}
			</script>
			</td></tr></table>
			<br>

			<table class="settingstable"><tr><td>
			<h2><?php echo t('str_models'); ?></h2>
			<?php
			// US-47: official and shadow model side by side, each with its own minimum confidence,
			// sigmoid sensitivity and location threshold; the overlap is shared. Config keys unchanged.
			$models = array("BirdNET-Plus_V3.0-preview3.1_Global_10K", "BirdNET_GLOBAL_6K_V2.4_Model_FP16", "BirdNET_6K_GLOBAL_MODEL");
			?>
			<?php // owner's layout (2026-10-20): the table (official, Swap, shadow) with the overlap as its last column; the
						// species range model only with the V2.4 model; shadow mode and avoid duplicity; the models; the thresholds note ?>
			<table class="modelstable">
				<tr><th></th><th><?php echo t('str_model'); ?></th><th><?php echo t('str_min_confidence'); ?><br><small>[<?php echo t('str_min_to_max', array('min' => fmt_setting('0.01'), 'max' => fmt_setting('0.99'))); ?>]</small></th><th><?php echo t('str_sensitivity'); ?><br><small>[<?php echo t('str_min_to_max', array('min' => fmt_setting('0.5'), 'max' => fmt_setting('1.5'))); ?>]</small></th><th><?php echo t('str_min_prob'); ?><br><small>[<?php echo t('str_min_to_max', array('min' => fmt_setting('0.0005'), 'max' => fmt_setting('0.99'))); ?>]</small></th><th><?php echo t('str_overlap'); ?><br><small>[<?php echo t('str_min_to_max', array('min' => fmt_setting('0.0'), 'max' => fmt_setting('2.9'))); ?>]</small></th></tr>
				<tr>
					<td><b><?php echo t('str_official'); ?></b></td>
					<td><select id="modelsel" name="model" class="testbtn">
			<?php
			foreach($models as $modelName){
					$isSelected = ($config['MODEL'] == $modelName) ? 'selected="selected"' : '';
					echo "<option value='{$modelName}' $isSelected>" . htmlspecialchars($model_short($modelName)) . "</option>";
				}
			?>
					</select></td>
					<td><input name="confidence" type="number" style="width:5em;" min="0.01" max="0.99" step="0.01" value="<?php print($config['CONFIDENCE']);?>"/></td>
					<td><input name="sensitivity" type="number" style="width:5em;" min="0.5" max="1.5" step="0.01" value="<?php print($config['SENSITIVITY']);?>"/></td>
					<td><input name="sf_thresh" type="number" style="width:5em;" max="0.99" min="0.0005" step="any" value="<?php print($config['SF_THRESH']);?>"/></td>
					<td><input name="overlap" type="number" style="width:5em;" min="0.0" max="2.9" step="0.1" value="<?php print($config['OVERLAP']);?>" aria-label="<?php echo htmlspecialchars(t('str_overlap'), ENT_QUOTES); ?>" title="<?php echo t('str_shared_the_recording_is_cut_help'); ?>"/> s</td>
				</tr>
				<tr>
					<td></td>
					<td colspan="5"><button type="submit" name="swap_models" value="1" class="stdbtn" <?php if (($config['SHADOW_MODEL_NAME'] ?? '') === '') echo 'disabled title="' . t('str_choose_a_shadow_model_first') . '"'; ?>
						onclick="return bsAskSubmit(this, t('str_swap_the_official_and_the_help') + ' ' + t('str_services_restart'), true);"><?php echo t('str_swap_models'); ?></button></td>
				</tr>
				<tr id="shadowrow" class="<?php if((string)($config['SHADOW_ENABLED'] ?? '0') === '0') echo 'shadow-off'; ?>">
					<td><b><?php echo t('str_shadow'); ?></b></td>
					<td><select name="shadow_model" class="testbtn">
				<option value=""><?php echo t('str_none'); ?></option>
			<?php
			foreach($models as $modelName){
					$isSelected = (($config['SHADOW_MODEL_NAME'] ?? '') == $modelName) ? 'selected="selected"' : '';
					echo "<option value='{$modelName}' $isSelected>" . htmlspecialchars($model_short($modelName)) . "</option>";
				}
			?>
					</select></td>
					<td><input name="shadow_min_conf" type="number" style="width:5em;" max="0.99" min="0.01" step="0.01" value="<?php print($config['SHADOW_MIN_CONF'] ?? '0.7');?>"/></td>
					<td><input name="shadow_sens" type="number" style="width:5em;" min="0.5" max="1.5" step="0.01" value="<?php print($config['SHADOW_SENS'] ?? '1.25');?>"/></td>
					<td><input name="shadow_geo_thresh" type="number" style="width:5em;" max="0.99" min="0.0005" step="any" value="<?php print($config['SHADOW_GEO_THRESH'] ?? '0.99');?>"/></td>
					<td></td><?php // the overlap is shared with the official model (owner 2026-10-21: no "same" label) ?>
				</tr>
			</table>
			<br>
			<?php // the species range model and its threshold belong to the V2 models only ?>
			<span <?php if($config['MODEL'] !== "BirdNET_GLOBAL_6K_V2.4_Model_FP16") { ?>style="display: none"<?php } ?> id="soft">
			<input type="checkbox" name="data_model_version" id="data_model_version" <?php if($config['DATA_MODEL_VERSION'] == 2) { echo "checked"; };?> >
			<label for="data_model_version"><?php echo t('str_species_range_model'); ?> V2.4 - V2</label>  [ <a target="_blank" href="https://github.com/kahst/BirdNET-Analyzer/discussions/234"><?php echo t('str_info_here'); ?></a> ]<br>
			&nbsp;&nbsp;&nbsp;&nbsp;<label><?php echo t('str_minimum_probability_at_the_location_the_help'); ?></label> <span onclick="document.getElementById('sfhelp').style.display='unset'" style="text-decoration:underline;cursor:pointer">[<?php echo t('str_more_info'); ?>]</span>
			<p id="sfhelp" style='display:none'><?php echo t('str_this_value_is_used_by_the_model_help'); ?>
				<?php echo t('str_a_minimum_probability_of_help'); ?>
				<?php echo t('str_so_the_lower_the_minimum_help'); ?><br><img style='max-width:100%;padding-top:5px;padding-bottom:5px' alt="<?php echo t('str_birdsuite_new_model_detection_help'); ?>" title="<?php echo t('str_birdsuite_new_model_detection_help'); ?>" src="images/BirdNET-Pi_nm_flowchart.alpha.png">
				<br><?php if($config['MODEL'] == "BirdNET_6K_GLOBAL_MODEL"){ ?><?php echo t('str_if_youd_please_click_apply_help'); ?><?php } else { ?><?php echo t('str_if_youd_you_can_use_this_help'); ?> <button type="button" class="testbtn" id="openModal"><?php echo t('str_species_list_tester'); ?></button><?php } ?></p>
			<br></span>
			<?php // Shadow mode and Avoid duplicity in one line each (owner 2026-10-21) ?>
			<p><b><?php echo t('str_shadow_mode'); ?></b> &nbsp;<label style="font-weight:normal"><input type="checkbox" id="shadow_enabled" name="shadow_enabled" value="1" onchange="document.getElementById('shadowrow').classList.toggle('shadow-off', !this.checked)" <?php if((string)($config['SHADOW_ENABLED'] ?? '0') !== '0') echo 'checked'; ?>> <?php echo t('str_enables_two_parallel_models_to_help'); ?></label></p>
			<p><b><?php echo t('str_avoid_duplicity'); ?></b> &nbsp;<label style="font-weight:normal"><input type="checkbox" name="avoid_duplicity" value="1" <?php if((string)($config['AVOID_DUPLICITY'] ?? '0') === '1') echo 'checked'; ?>> <?php echo t('str_one_species_per_recording_in_help', array('len' => intval($config['RECORDING_LENGTH'] ?? 15) ?: 15)); ?></label></p>
			<?php // from Settings › Advanced (owner 2026-10-25) ?>
			<?php // BirdNET+ V3 has no human class (owner 2026-10-25): the filter works only through a shadow model V2.4 that has one
			$pv_v3 = strpos($config['MODEL'] ?? '', 'BirdNET-Plus') === 0;
			$pv_shadow = (string)($config['SHADOW_ENABLED'] ?? '0') !== '0' && strpos($config['SHADOW_MODEL_NAME'] ?? '', 'BirdNET-Plus') !== 0 && ($config['SHADOW_MODEL_NAME'] ?? '') !== '';
			$pv_off = $pv_v3 && !$pv_shadow; ?>
			<p><b><?php echo t('str_privacy_threshold'); ?></b> &nbsp;<input type="number" name="privacy_threshold" aria-label="<?php echo htmlspecialchars(t('str_privacy_threshold'), ENT_QUOTES); ?>" min="0" max="3" step="1" style="width:4em<?php echo $pv_off ? ';opacity:.75' : ''; ?>" value="<?php echo intval($config['PRIVACY_THRESHOLD'] ?? 1); ?>"> %
				<?php // one sentence per line, the owner's wording (2026-10-26) ?>
				<?php echo t('str_a_recording_where_a_human_voice_help'); ?>
				<?php // the lines under it indented as the model list below (2em, owner 2026-10-26) ?>
				<span style="display:block;padding-left:2em"><?php echo t('str_0_off_start_with_1'); ?>
				<?php if ($pv_off) { ?><br><span style="color:#c62828"><?php echo t('str_the_birdnet_plus_v3_model_has_help'); ?></span>
					<br><small><?php echo t('str_for_privacy_with_v3_0_turn_on_help'); ?><br><?php echo t('str_v2_4_has_human_voice_detection_help'); ?><br><?php echo t('str_the_pis_processing_load_goes_up_help'); ?></small>
				<?php } elseif ($pv_v3) { ?><br><small style="color:#1b5e20"><?php echo t('str_with_birdnet_plus_v3_it_works_help'); ?></small><?php } ?></span></p>

<script src="static/dialog-polyfill.js"></script>

<dialog id="modal">
	<div>
		<label for="threshold"><?php echo t('str_min_probability'); ?></label>
		<input type="number" id="threshold" step="0.01" min="0" max="1" value="">
		<button type="button" id="runProcess" class="stdbtn"><?php echo t('str_preview_species_list'); ?></button>
	</div>
	<pre id="output"></pre>
	<button type="button" id="closeModal" class="stdbtn"><?php echo t('str_close'); ?></button>
</dialog>

<style>
#output {
	max-width: 100vw;
	word-wrap: break-word;
	white-space: pre-wrap;
}
#modal {
	max-height: 80vh;
	overflow-y: auto;
}
#modal div {
	display: flex;
	align-items: center;
}

#modal input[type="number"] {
	height: 32px;
}

#modal button {
	height: 32px;
	margin-left: 5px;
	padding: 0 10px;
	box-sizing: border-box;
}
</style>


<script>
// Get the button and modal elements
const openModalBtn = document.getElementById('openModal');
const modal = document.getElementById('modal');
dialogPolyfill.registerDialog(modal);
const output = document.getElementById('output');
const thresholdInput = document.getElementById('threshold');
const runProcessBtn = document.getElementById('runProcess');
const sfThreshInput = document.getElementsByName('sf_thresh')[0];
const closeModalBtn = document.getElementById('closeModal');


// Add an event listener to the button to open the modal
openModalBtn.addEventListener('click', () => {

	// Set the initial value of the threshold input element
	thresholdInput.value = sfThreshInput.value;

// Show the modal
	modal.showModal();
});

// Add an event listener to the "Preview Species List" button
runProcessBtn.addEventListener('click', () => {

	runProcess();
});

// Add an event listener to the "Close" button
closeModalBtn.addEventListener('click', () => {
	modal.close();
});

// Function to run the process
function runProcess() {
	// Get the value of the threshold input element
	const threshold = thresholdInput.value;

 // Set the output to "Loading..."
	output.textContent = t('str_loading');

	// Make the AJAX request
	const xhr = new XMLHttpRequest();
	xhr.onreadystatechange = function() {
		if (xhr.readyState === XMLHttpRequest.DONE) {
			// the script's text as it is (Gitea #89: it went in as HTML, an error of the script included)
			output.textContent = xhr.responseText;
		}
	};
	xhr.open('GET', `scripts/model_page.php?threshold=${threshold}`);
	xhr.send();
}
</script>

			<div style="font-size:.85em;margin:14px 0">
				<?php // the defaults of each model and of its variants as a table (owner 2026-10-22): the BirdSuite factory values
					// (factory_defaults.conf), Cornell's own (BirdNET+ V3 analyze / web demo, the BirdNET Live app, BirdNET-Analyzer) and
					// the original BirdNET-Pi project's; '-' = the tool has no such setting
					$bd = birdsuite_defaults();
					$defrows = array(
						array('BirdNET-Plus_V3.0-preview3.1_Global_10K', t('str_birdsuite_factory'), $bd['CONFIDENCE'] ?? '-', isset($bd['SENSITIVITY']) && is_numeric($bd['SENSITIVITY']) ? number_format((float)$bd['SENSITIVITY'], max(1, strlen(substr(strrchr($bd['SENSITIVITY'], '.') ?: '', 1)))) : '-', $bd['SF_THRESH'] ?? '-', $bd['OVERLAP'] ?? '-'),
						array('BirdNET-Plus_V3.0-preview3.1_Global_10K', t('str_cornell_birdnet_live_app'), '0.5', '1.0', '0.03', '0'),
						array('BirdNET-Plus_V3.0-preview3.1_Global_10K', t('str_cornell_analysis_script'), '0.25', '-', '-', '0'),
						array('BirdNET_GLOBAL_6K_V2.4_Model_FP16', t('str_cornell_birdnet_analyzer'), '0.25', '1.0', '0.03', '0'),
						array('BirdNET_GLOBAL_6K_V2.4_Model_FP16', t('str_original_project_birdnet_pi'), '0.7', '1.25', '0.03', '0'),
						array('BirdNET_6K_GLOBAL_MODEL', t('str_legacy_6k_model'), '0.7', '1.25', '-', '0'),
					); ?>
				<table class="stdtable" style="width:auto;margin-top:6px" data-nosort>
					<tr><th><?php echo t('str_model'); ?></th><th><?php echo t('str_defaults_of'); ?></th><th class="num"><?php echo t('str_min_conf'); ?></th><th class="num"><?php echo t('str_sigm_sens'); ?></th><th class="num"><?php echo t('str_min_prob'); ?></th><th class="num"><?php echo t('str_overlap'); ?></th><th data-nosort></th></tr>
					<?php // Use: these values into the official model's fields (saved with the form), only for the model selected above
					// a thicker line between the model generations (V3, V2.4, 6K), owner 2026-10-22
					$prevm = null;
					foreach ($defrows as $r) { $gen = $prevm !== null && $prevm !== $r[0] ? ' class="gensep"' : ''; $prevm = $r[0]; echo '<tr' . $gen . '><td>' . htmlspecialchars($model_short($r[0])) . '</td><td>' . htmlspecialchars($r[1]) . '</td><td class="num">' . htmlspecialchars(fmt_setting($r[2])) . '</td><td class="num">' . htmlspecialchars(fmt_setting($r[3])) . '</td><td class="num">' . htmlspecialchars(fmt_setting($r[4])) . '</td><td class="num">' . htmlspecialchars($r[5] === '-' ? '-' : fmt_setting($r[5]) . ' s') . '</td>'
						. '<td><button type="button" class="stdbtn defuse" data-model="' . htmlspecialchars($r[0]) . '" data-v="' . htmlspecialchars(json_encode(array_slice($r, 2, 4))) . '" onclick="defUse(this)">' . t('str_use') . '</button></td></tr>'; } ?>
				</table>
				<div class="small" id="defusemsg" style="font-weight:600;margin-top:4px"></div>
				<style>.defuse:disabled { opacity: .4; cursor: default; } tr.gensep td { border-top: 3px solid rgba(0,0,0,.35) !important; } .modellist span b { font-size: inherit; }
					/* what each value does, in the page's normal size throughout (owner 2026-10-22: no small lines between) */
					.paramhelp { font-size: calc(1em / 0.85); } .paramhelp span { font-size: inherit !important; }</style>
				<script>
				// a row's values into the official model's fields; the button only for the model selected above (owner 2026-10-22)
				function defRefresh() {
					var m = document.getElementById('modelsel').value;
					document.querySelectorAll('.defuse').forEach(function (b) { b.disabled = b.dataset.model !== m; b.title = b.disabled ? t('str_only_for_the_model_selected_above') : ''; });
				}
				function defUse(b) {
					if (b.disabled) return;
					var v = JSON.parse(b.dataset.v), names = ['confidence', 'sensitivity', 'sf_thresh', 'overlap'];
					names.forEach(function (n, i) {
						var f = document.querySelector('input[name="' + n + '"]');
						if (f && v[i] !== '-') { f.value = v[i]; f.dispatchEvent(new Event('input', {bubbles: true})); f.dispatchEvent(new Event('change', {bubbles: true})); }
					});
					document.getElementById('defusemsg').textContent = t('str_values_filled_in_above_save_the_help');
				}
				document.getElementById('modelsel').addEventListener('change', defRefresh);
				defRefresh();
				</script>
				<p style="margin:8px 0 0"><b><?php echo t('str_thresholds_are_not_comparable_help'); ?></b></p>
				<?php // what changing each value does (owner 2026-10-22), right under the table ?>
				<div class="modellist paramhelp" style="margin-top:8px">
					<p><b><?php echo t('str_what_changing_each_value_does'); ?></b></p>
					<p><b><?php echo t('str_min_conf'); ?></b><br><span><?php echo t('str_higher_fewer_detections_and_help'); ?><br><?php echo t('str_lower_more_detections_and_more_help'); ?></span></p>
					<p><b><?php echo t('str_sigm_sens'); ?></b><br><span><?php echo t('str_higher_uncertain_sounds_get_a_help'); ?><br><?php echo t('str_lower_only_clear_songs_pass'); ?></span></p>
					<p><b><?php echo t('str_min_prob'); ?></b><br><span><?php echo t('str_higher_a_shorter_species_list_help'); ?><br><?php echo t('str_lower_a_longer_list_vagrants_help'); ?></span></p>
					<p><b><?php echo t('str_overlap'); ?></b><br><span><?php echo t('str_the_3_second_analysis_windows_help'); ?><br><?php echo t('str_more_more_windows_per_recording_help'); ?><br><?php echo t('str_0_the_fastest_but_a_call_split_help'); ?></span></p>
				</div>
</div>
			<p><b><?php echo t('str_models_colon'); ?></b></p>
			<div class="modellist">
				<p><b>BirdNET-Plus_V3.0-preview3.1_Global_10K (2026)</b><br>
					<span><?php echo t('str_developer_preview_of_the_next_help'); ?></span></p>
				<p><b>BirdNET_GLOBAL_6K_V2.4_Model_FP16 (2023)</b><br>
					<span><?php echo t('str_this_is_the_birdnet_analyzer_help'); ?><br><?php echo t('str_currently_it_supports_over_6_help'); ?></span></p>
				<p><b>BirdNET_6K_GLOBAL_MODEL (2020)</b><br>
					<span><?php echo t('str_this_is_the_birdnet_lite_model_help'); ?><br>
					<?php echo t('str_this_has_generally_worse_help'); ?></span></p>
				<p><?php echo t('str_in_depth_technical_write_up_on_help'); ?></p>
			</div>
			<style>.modellist p { margin: 0 0 10px; } .modellist span { display: inline-block; padding-left: 2em; font-size: .85em; }</style>
			<?php // the section's own Apply (owner 2026-11-05): the whole form is sent, as Update Settings did, and the services restart ?>
			<p style="margin-top:12px"><button type="submit" class="stdbtn" name="view" value="Model" title="<?php echo htmlspecialchars(t('str_services_restart'), ENT_QUOTES); ?>"
				onclick="if(document.getElementById('basicform').checkValidity()){this.innerHTML = t('str_updating_please_wait');this.classList.add('disabled')}"><?php echo t('str_apply'); ?></button></p>

			</td></tr></table><br>
			<?php // the recording length, a setting of the analysis (owner 2026-11-04: was on Station › Microphone); the three fields are
						// sent together by the section's Apply (owner 2026-11-05; were saved at once): settings_set.php restarts recording and analysis ?>
			<table class="settingstable"><tr><td>
			<h2><?php echo t('str_recording'); ?></h2>
			<?php // one sentence per line, an explicit <br>: the first help ends in a number ("60."), which static/sentence-lines.js does not take for the end of a sentence (release run 5, NEW-UX-R5-15) ?>
			<?php echo setting_field('RECORDING_LENGTH', t('str_recording_length'), array('min' => 3, 'max' => 60, 'unit' => 's', 'apply' => true),
				t('str_set_recording_length_in_seconds_help') . '<br>' . t('str_multiples_of_3_are_recommended_help')); ?>
			<?php // the clip cut from it, its length and format, beside the recording length (owner 2026-11-04) ?>
			<?php echo setting_field('EXTRACTION_LENGTH', t('str_extraction_length'), array('min' => 3, 'max' => 60, 'unit' => 's', 'apply' => true),
				t('str_set_extraction_length_to_help') . ' ' . t('str_min_3_max_recording_length')); ?>
			<?php echo setting_field('AUDIOFMT', t('str_extractions_audio_format'), array('apply' => true, 'choices' => array("8svx", "aif", "aifc", "aiff", "aiffc", "al", "amb", "amr-nb", "amr-wb", "anb", "au", "avr", "awb", "caf", "cdda", "cdr", "cvs", "cvsd", "cvu", "dat", "dvms", "f32", "f4", "f64", "f8", "fap", "flac", "fssd", "gsm", "gsrt", "hcom", "htk", "ima", "ircam", "la", "lpc", "lpc10", "lu", "mat", "mat4", "mat5", "maud", "mp2", "mp3", "nist", "ogg", "paf", "prc", "pvf", "raw", "s1", "s16", "s2", "s24", "s3", "s32", "s4", "s8", "sb", "sd2", "sds", "sf", "sl", "sln", "smp", "snd", "sndfile", "sndr", "sndt", "sou", "sox", "sph", "sw", "txw", "u1", "u16", "u2", "u24", "u3", "u32", "u4", "u8", "ub", "ul", "uw", "vms", "voc", "vorbis", "vox", "w64", "wav", "wavpcm", "wv", "wve", "xa", "xi"))); ?>
			<p style="margin-top:12px"><button type="button" class="stdbtn" onclick="bsApplySettings(this)" title="<?php echo htmlspecialchars(t('str_services_restart'), ENT_QUOTES); ?>"><?php echo t('str_apply'); ?></button></p>
			</td></tr></table><br>

			<input type="hidden" name="status" value="success">
			<input type="hidden" name="submit" value="settings">
<?php settings_notice($cfg_error); ?>
			</form>
</div>
