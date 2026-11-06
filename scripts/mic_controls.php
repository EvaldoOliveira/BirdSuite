<?php
################################################################################
# scripts/mic_controls.php - THE MICROPHONE IN USE AND ITS CAPTURE GAIN
#
# PURPOSE:
#   The microphone in use and its capture gain (US-07): the controls of Settings › Audio, also in First Run
#   when a microphone is plugged in (owner 2026-11-03: with two microphones the first run asks which one and
#   its volume). Changed at once through scripts/audio_input.php; $mic_return = the page reloaded after a
#   change of microphone. The live sonogram between the microphone and its gain (owner 2026-11-04): the gain
#   and the microphone are judged on the sound itself, the same on Station › Microphone and in First Run.
#
# DEPENDENCIES:
#   - scripts/audio_input.php
#   - birdsuite-root (sudo helper)
#
# USAGE:
#   Referenced by scripts/microphone_page.php, scripts/setup_wizard.php
################################################################################

?>
			<?php // the microphone (US-07, owner 2026-11-02): which capture device records and its capture volume, read now
			// from /proc/asound and birdsuite-root mic-gain; changed at once by scripts/audio_input.php (not by this form: the
			// controls have no name)
			require_once __DIR__ . '/audio_input.php';
			$mics = mic_devices();
			$mic_active = mic_active_id($mics);
			$mic_gain = mic_gain($mic_active);
			?>
			<?php // every microphone found and the port it is plugged into, before the choice (owner 2026-11-04) ?>
			<?php if ($mics) { ?>
			<table class="stdtable" data-nosort style="margin:0 0 10px"><tr><th><?php echo t('str_microphone'); ?></th><th><?php echo t('str_port'); ?></th><th></th></tr>
			<?php foreach ($mics as $m) {
				$where = $m['port'] !== '' ? 'USB ' . $m['port'] . ($m['speed'] ? ' (' . $m['speed'] . ' Mbit/s)' : '') : t($m['usb'] ? 'str_usb' : 'str_built_in');
				echo '<tr><td>' . htmlspecialchars($m['name']) . '</td><td>' . htmlspecialchars($where) . '</td><td>' . ($m['id'] === $mic_active ? '<b>' . t('str_mic_in_use') . '</b>' : '') . '</td></tr>';
			} ?>
			</table>
			<?php } ?>
			<label for="micsel"><?php echo t('str_microphone_in_use'); ?> </label>
			<?php if (count($mics) > 1) { ?>
			<select id="micsel" class="testbtn" data-was="<?php echo htmlspecialchars($mic_active); ?>">
				<?php foreach ($mics as $m) { ?>
				<option value="<?php echo htmlspecialchars($m['id']); ?>"<?php echo $m['id'] === $mic_active ? ' selected' : ''; ?>><?php echo htmlspecialchars($m['name']); ?></option>
				<?php } ?>
			</select>
			<?php } elseif ($mics) { ?>
			<b id="micsel"><?php echo htmlspecialchars($mics[0]['name']); ?></b>
			<?php } else { ?>
			<span id="micsel"><?php echo t('str_no_microphone_found'); ?></span>
			<?php } ?>
			<span id="micselmsg" class="small"></span><br>
			<?php // the live sonogram between the microphone and its gain (owner 2026-11-04) ?>
			<?php if ($mics) { ?>
			<p class="small" style="margin:0 0 4px"><?php echo t('str_wiz_make_a_sound_help'); ?></p>
			<iframe class="micspectro" title="<?php echo htmlspecialchars(t('str_spectrogram_menu')); ?>" src="spectrogram.php" loading="lazy"
				style="position:static;top:auto;left:auto;width:100%;height:320px;border:0;border-radius:8px"></iframe>
			<style>.sidenav ~ .views iframe.micspectro, iframe.micspectro { position: static; top: auto; left: auto; width: 100%; height: 320px; }</style>
			<?php } ?>
			<br>
			<label for="micgain"><?php echo t('str_microphone_gain'); ?> </label>
			<?php if ($mic_gain) { ?>
			<input id="micgain" type="range" min="0" max="100" step="1" style="vertical-align:middle;width:12em;max-width:50%;height:24px"
				value="<?php echo intval($mic_gain['percent']); ?>" data-db="<?php echo htmlspecialchars((string)$mic_gain['db']); ?>"
				aria-describedby="micgainval">
			<span id="micgainval"></span> <span id="micgainmsg" class="small"></span><br>
			<?php echo t('str_microphone_gain_help'); ?><br><?php echo t('str_microphone_gain_default_help'); ?><br><br>
			<?php } else { ?>
			<span><?php echo t('str_no_adjustable_microphone_found'); ?></span><br><br>
			<?php } ?>
			<script>
			(function () {
				var post = function (body) {
					return fetch('scripts/audio_input.php', {method: 'POST', credentials: 'same-origin',
						headers: {'Content-Type': 'application/x-www-form-urlencoded'}, body: new URLSearchParams(body)})
						.then(function (r) { return r.status === 401 ? {ok: false, error: t('str_log_in_first')} : r.json(); })
						.catch(function () { return {ok: false}; });
				};
				var g = document.getElementById('micgain'), gv = document.getElementById('micgainval'), gm = document.getElementById('micgainmsg');
				var show = function (pct, db) {
					var f = window.fmtNum || function (v, d) { return v.toFixed(d); };
					return pct + ' %' + (db !== null && db !== '' && db !== undefined ? ' (' + f(parseFloat(db), 2) + ' dB)' : '');
				};
				if (g) {
					gv.textContent = show(g.value, g.dataset.db);
					// moving it shows the value, letting go saves it (change fires on release)
					g.addEventListener('input', function () { gv.textContent = g.value + ' %'; gm.textContent = ''; });
					g.addEventListener('change', function () {
						post({gain: g.value}).then(function (r) {
							if (r.ok && r.gain) {
								g.value = r.gain.percent; g.dataset.db = r.gain.db === null ? '' : r.gain.db;
								gv.textContent = show(r.gain.percent, r.gain.db);
								gm.textContent = t('str_saved_value', {value: show(r.gain.percent, r.gain.db)});
							} else {
								bsNotice(r.error || t('str_the_microphone_gain_could_not_be_set'));
							}
						});
					});
				}
				var sel = document.getElementById('micsel');
				if (sel && sel.tagName === 'SELECT') {
					sel.addEventListener('change', function () {
						var name = sel.options[sel.selectedIndex].text, m = document.getElementById('micselmsg');
						askModal(t('str_use_this_microphone', {name: name}), t('str_choosing_another_microphone_help'), t('str_yes')).then(function (ok) {
							if (!ok) { sel.value = sel.dataset.was; return; }
							sel.disabled = true;
							m.textContent = t('str_switching_the_microphone');
							post({mic: sel.value}).then(function (r) {
								if (r.ok) { location.replace(<?php echo json_encode($mic_return ?? 'views.php?view=Microphone'); ?>); return; }
								sel.disabled = false; sel.value = sel.dataset.was; m.textContent = '';
								bsNotice(r.error || t('str_the_microphone_could_not_be_changed'));
							});
						});
					});
				}
			})();
			</script>
