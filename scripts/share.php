<?php
################################################################################
# scripts/share.php - SHARE A DETECTION
#
# PURPOSE:
#   Share a detection (owner 2026-11-01, US-143): a small window opened by the Share icon of the review player
#   (static/review-player.js). It shows the detection and a Share button that hands the clip (audio), its
#   spectrogram picture and a text (name, date and time, location, confidence, station) to the device's share
#   sheet: WhatsApp, Telegram, e-mail... Browsers give a page the share sheet only over https, so on an http
#   station the player switches https on for a moment (scripts/https_temp.php) and opens this window on https;
#   it goes off when the window closes. Fallbacks when the sheet is missing or cannot take files: WhatsApp
#   with the text, copy the text, download the audio and the picture. Nothing on the station changes here, so
#   it opens without the login. GET share.php?file=<date>/<folder>/<clip file>
#
# DEPENDENCIES:
#   - scripts/common.php
#   - homepage/static/review-player.js
#
# USAGE:
#   Referenced by homepage/static/review-player.js, scripts/spectrogram_thumb.php
################################################################################

require_once __DIR__ . '/common.php';
$h = function ($s) { return htmlspecialchars((string)$s, ENT_QUOTES); };
$file = (string)($_GET['file'] ?? '');
// only a clip under Extracted/By_Date: <YYYY-MM-DD>/<folder>/<name>, no way out of the folder
$ok = preg_match('#^\d{4}-\d{2}-\d{2}/[^/]+/[^/]+$#', $file) && strpos($file, '..') === false
	&& is_file(BS_BY_DATE . '/' . $file);
// the MP3 and the MP4 are made once per clip and kept in state/share (keyed on the clip, the picture and their times),
// one encode at a time (2026-11-02): the window opens without the login, so repeated requests can not keep ffmpeg busy
function share_cached($kind, $src, $img, $build) {
	$dir = BS_STATE . '/share';
	if (!is_dir($dir)) @mkdir($dir, 0775, true);
	$out = $dir . '/' . sha1($src . '|' . @filemtime($src) . '|' . $img . '|' . ($img !== '' ? @filemtime($img) : '')) . '.' . $kind;
	if (is_file($out) && filesize($out) > 0) return $out;
	$lock = @fopen($dir . '/encode.lock', 'c');
	if (!$lock) return false;
	// another encode running: wait for it a little (it may be this very clip), then give up
	for ($t = time(); !flock($lock, LOCK_EX | LOCK_NB); usleep(200000)) {
		if (time() - $t > 15) { fclose($lock); return false; }
	}
	clearstatcache();
	if (!is_file($out) || filesize($out) === 0) {
		$tmp = $out . '.tmp' . getmypid();
		if ($build($tmp) && is_file($tmp) && filesize($tmp) > 0) rename($tmp, $out);
		@unlink($tmp);
		// the newest 40 kept, none older than a week
		$all = glob($dir . '/*.{mp3,mp4}', GLOB_BRACE) ?: array();
		usort($all, function ($a, $b) { return filemtime($b) - filemtime($a); });
		foreach ($all as $i => $f) if ($i >= 40 || filemtime($f) < time() - 7 * 86400) @unlink($f);
	}
	flock($lock, LOCK_UN);
	fclose($lock);
	clearstatcache();
	return is_file($out) ? $out : false;
}
function share_send($path, $type, $file, $ext) {
	if ($path === false) { http_response_code(503); header('Retry-After: 5'); die(); }
	header('Content-Type: ' . $type);
	header('Content-Length: ' . filesize($path));
	header('Content-Disposition: inline; filename="' . preg_replace('/[^A-Za-z0-9_.-]/', '_', pathinfo($file, PATHINFO_FILENAME)) . '.' . $ext . '"');
	readfile($path);
	die();
}
// the audio as MP3 (WhatsApp and most apps play it inline; a FLAC or WAV clip would go as a document)
if ($ok && isset($_GET['mp3'])) {
	session_write_close();
	$src = BS_BY_DATE . '/' . $file;
	share_send(share_cached('mp3', $src, '', function ($tmp) use ($src) {
		exec('ffmpeg -nostdin -loglevel error -y -i ' . escapeshellarg($src) . ' -vn -codec:a libmp3lame -q:a 4 -f mp3 ' . escapeshellarg($tmp) . ' 2>/dev/null', $o, $rc);
		return $rc === 0;
	}), 'audio/mpeg', $file, 'mp3');
}
// the clip as a short video, the spectrogram as a still picture over the audio (owner 2026-11-01: the audio and the text
// together): WhatsApp drops the text of an audio (an audio has no caption) but keeps it as the caption of a video, so one
// message carries the audio, the picture and the text. About a second on a Pi 5 for a clip of a few seconds.
if ($ok && isset($_GET['mp4'])) {
	session_write_close();
	$src = BS_BY_DATE . '/' . $file;
	$img = BS_EXTRACTED . clip_spectrogram('/By_Date/' . $file);
	share_send(share_cached('mp4', $src, $img, function ($tmp) use ($src, $img) {
		exec('ffmpeg -nostdin -loglevel error -y -loop 1 -i ' . escapeshellarg($img) . ' -i ' . escapeshellarg($src)
			. ' -vf "scale=trunc(iw/2)*2:trunc(ih/2)*2,format=yuv420p" -c:v libx264 -preset veryfast -tune stillimage -r 2'
			. ' -c:a aac -b:a 128k -shortest -movflags +faststart -f mp4 ' . escapeshellarg($tmp) . ' 2>/dev/null', $o, $rc);
		return $rc === 0;
	}), 'video/mp4', $file, 'mp4');
}
$det = null;
if ($ok) {
	$db = get_db();
	$st = $db->prepare('SELECT Date, Time, Sci_Name, com_name(Sci_Name) AS Com_Name, Confidence, Location FROM detections WHERE File_Name = :f LIMIT 1');
	$st->bindValue(':f', basename($file));
	$r = $st->execute();
	$det = $r ? $r->fetchArray(SQLITE3_ASSOC) : null;
}
$text = '';
$title = t('str_share_a_detection');
if ($det) {
	$loc = '';
	foreach (locations_load() as $l) if ((string)($l['id'] ?? '') === (string)($det['Location'] ?? '')) $loc = (string)$l['name'];
	$title = $det['Com_Name'];
	$text = $det['Com_Name'] . ' (' . $det['Sci_Name'] . ')' . "\n" . $det['Date'] . ' ' . substr((string)$det['Time'], 0, 5)
		. ($loc !== '' ? ' · ' . $loc : '') . ' · ' . t('str_confidence_pct', array('p' => round((float)$det['Confidence'] * 100)))
		. "\n" . t('str_recorded_by_the_birdsuite_station', array('name' => get_sitename()));
}
$clip = '/By_Date/' . implode('/', array_map('rawurlencode', explode('/', $file)));
// clip_spectrogram() reads a path under Extracted (the picture is .png, or .jpg on older clips)
$spec = implode('/', array_map('rawurlencode', explode('/', clip_spectrogram('/By_Date/' . $file))));
// what is shared and downloaded: the MP3 made above
$mp3 = 'share.php?mp3=1&file=' . rawurlencode($file);
$mp4 = 'share.php?mp4=1&file=' . rawurlencode($file);
?><!DOCTYPE html>
<html lang="<?php echo $h(str_replace('_', '-', ui_lang())); ?>"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title><?php echo $h($title); ?></title>
<style>
	body { font-family: sans-serif; margin: 14px; line-height: 1.4; max-width: 560px; }
	h3 { margin: 0 0 6px; }
	img.spec { display: block; width: 100%; object-fit: contain; border-radius: 8px; background: #000; margin: 8px 0; }
	audio { width: 100%; margin: 4px 0 8px; }
	pre { white-space: pre-wrap; font-family: inherit; background: rgba(0,0,0,.06); padding: 8px; border-radius: 8px; margin: 6px 0; }
	.btns { display: flex; flex-wrap: wrap; gap: 8px; margin-top: 10px; }
	.btns a, .btns button { padding: 8px 14px; border-radius: 16px; border: 1px solid #2b5e22; background: #fff; color: #2b5e22; font-weight: 600; text-decoration: none; cursor: pointer; font-size: 14px; }
	.btns .main { background: #2b5e22; color: #fff; }
	#msg { margin-top: 8px; font-size: 13px; }
</style>
</head><body>
<?php if (!$ok) { ?>
	<p><?php echo $h(t('str_this_recording_is_not_on_the_help')); ?></p>
<?php } else { ?>
	<h3><?php echo $h($title); ?></h3>
	<img class="spec" src="<?php echo $h($spec); ?>" alt="<?php echo $h(t('str_spectrogram_lc')); ?>">
	<audio controls preload="metadata" src="<?php echo $h($clip); ?>"></audio>
	<pre id="txt"><?php echo $h($text); ?></pre>
	<div class="btns">
		<button type="button" class="main" id="sharevid" hidden><?php echo $h(t('str_share_audio')); ?></button>
		<button type="button" id="share" hidden><?php echo $h(t('str_share_the_audio')); ?></button>
		<button type="button" id="sharepic" hidden><?php echo $h(t('str_share_the_picture')); ?></button>
		<a id="wa" target="_blank" rel="noopener" href="https://wa.me/?text=<?php echo rawurlencode($text); ?>"><?php echo $h(t('str_whatsapp_text')); ?></a>
		<button type="button" id="copy"><?php echo $h(t('str_copy_the_text')); ?></button>
		<a href="<?php echo $h($mp3); ?>" download="<?php echo $h(pathinfo(basename($file), PATHINFO_FILENAME)); ?>.mp3"><?php echo $h(t('str_download_the_audio')); ?></a>
		<a id="chrome" hidden><?php echo $h(t('str_open_in_chrome')); ?></a>
		<a href="<?php echo $h($spec); ?>" download><?php echo $h(t('str_download_the_picture')); ?></a>
	</div>
	<div id="msg"></div>
	<script>
	(function () {
		var T = <?php echo json_encode(array('nohttps' => t('str_direct_sharing_needs_https_use_help'),
			'nofiles' => t('str_this_browser_shares_only_the_help'),
			'firefox' => t('str_firefox_on_android_shares_only_help'), 'copied' => t('str_text_copied'),
			'shared' => t('str_shared'), 'pasted' => t('str_shared_the_text_is_on_the_help'), 'sharetext' => t('str_share_the_text'), 'reading' => t('str_preparing_the_audio_and_the_picture')), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG); ?>;
		var text = document.getElementById('txt').textContent, msg = document.getElementById('msg'), btn = document.getElementById('share');
		var title = <?php echo json_encode($title, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG); ?>;
		var files = null, picfile = null, vidfile = null, vbtn = document.getElementById('sharevid');
		document.getElementById('copy').onclick = function () {
			(navigator.clipboard ? navigator.clipboard.writeText(text) : Promise.reject()).then(function () { msg.textContent = T.copied; }, function () {
				var r = document.createRange(); r.selectNodeContents(document.getElementById('txt'));
				var s = getSelection(); s.removeAllRanges(); s.addRange(r); document.execCommand('copy'); msg.textContent = T.copied;
			});
		};
		if (!window.isSecureContext || !navigator.share) { msg.textContent = T.nohttps; return; }
		// Firefox on Android hands only text to the share sheet: the same page in Chrome (Android intent) shares the files
		var ffa = /Android/.test(navigator.userAgent) && /Firefox\//.test(navigator.userAgent);
		if (ffa) {
			var c = document.getElementById('chrome');
			c.href = 'intent://' + location.host + location.pathname + location.search + '#Intent;scheme=https;package=com.android.chrome;end';
			c.hidden = false;
		}
		btn.hidden = false;
		// the files are read when the window opens, so the tap on Share hands them over at once (the share sheet needs
		// the tap itself)
		msg.textContent = T.reading;
		var name = <?php echo json_encode(pathinfo(basename($file), PATHINFO_FILENAME)); ?>;
		Promise.all([fetch(<?php echo json_encode($mp3); ?>).then(function (r) { return r.blob(); }),
								 fetch(<?php echo json_encode($spec); ?>).then(function (r) { return r.blob(); }),
								 fetch(<?php echo json_encode($mp4); ?>).then(function (r) { return r.ok ? r.blob() : null; }, function () { return null; })]).then(function (b) {
			// the picture keeps its real type and extension (older clips have a JPG; Chrome refuses a file whose name and type
			// disagree); as the BirdNET Live app, the audio alone is the fallback when the pair cannot be shared
			var pt = b[1].type || 'image/png', pe = /jpe?g/.test(pt) ? '.jpg' : '.png';
			var audio = new File([b[0]], name + '.mp3', {type: 'audio/mpeg'}), pic = new File([b[1]], name + pe, {type: pt});
			var can = function (f) { try { return !!(navigator.canShare && navigator.canShare({files: f})); } catch (e) { return false; } };
			// the audio first and alone (owner 2026-11-01: "the audio has to go"; the BirdNET Live app shares the audio file
			// alone too); the picture has its own button
			files = can([audio]) ? [audio] : null;
			picfile = can([pic]) ? [pic] : null;
			// the video carries all three in one message (WhatsApp keeps the text as its caption): the main button when it can go
			vidfile = b[2] && can([new File([b[2]], name + '.mp4', {type: 'video/mp4'})]) ? [new File([b[2]], name + '.mp4', {type: 'video/mp4'})] : null;
			vbtn.hidden = !vidfile;
			// with the video only it, the picture and the downloads stay (owner 2026-11-01); the audio alone, WhatsApp (text) and
			// Copy the text remain as the fallback of a browser that cannot share the video
			if (vidfile) ['share', 'wa', 'copy'].forEach(function (id) { document.getElementById(id).hidden = true; });
			else if (files) btn.classList.add('main');
			document.getElementById('sharepic').hidden = !picfile;
			noFiles();
			msg.textContent = files ? '' : (ffa ? T.firefox : T.nofiles);
		}, function () { noFiles(); msg.textContent = ffa ? T.firefox : T.nofiles; });
		// a browser that shares only text (Firefox for Android) gets different buttons (owner 2026-11-01): the main one says
		// what it really sends, the text, and the way to send the audio (Open in Chrome) is the highlighted one
		function noFiles() {
			if (files) return;
			btn.textContent = T.sharetext;
			btn.classList.remove('main');
			var c = document.getElementById('chrome');
			if (!c.hidden) { c.classList.add('main'); c.parentNode.insertBefore(c, c.parentNode.firstChild); }
		}
		vbtn.onclick = function () {
			if (vidfile) navigator.share({files: vidfile, title: title, text: text}).then(function () { msg.textContent = T.shared; }, function (e) {
				if (e && e.name === 'AbortError') return;
				navigator.share({files: vidfile}).then(function () { msg.textContent = T.shared; }, function (e2) { if (e2 && e2.name !== 'AbortError') msg.textContent = e2.message; });
			});
		};
		document.getElementById('sharepic').onclick = function () {
			if (picfile) navigator.share({files: picfile, title: title, text: text}).then(function () { msg.textContent = T.shared; }, function (e) { if (e && e.name !== 'AbortError') msg.textContent = e.message; });
		};
		btn.onclick = function () {
			var data = files ? {files: files, title: title, text: text} : {title: title, text: text};
			// an audio goes without its text on WhatsApp (no caption on an audio): the text waits on the clipboard to be pasted
			var clip = files && navigator.clipboard ? navigator.clipboard.writeText(text).then(function () { return true; }, function () { return false; }) : Promise.resolve(false);
			navigator.share(data).then(function () { clip.then(function (c) { msg.textContent = c ? T.pasted : T.shared; }); }, function (e) {
				if (e && e.name === 'AbortError') return;
				// a target that refuses files with a text: the files alone (the text stays in Copy the text)
				if (files) navigator.share({files: files}).then(function () { msg.textContent = T.shared; }, function (e2) { if (e2 && e2.name !== 'AbortError') msg.textContent = e2.message; });
				else msg.textContent = e.message;
			});
		};
	})();
	</script>
<?php } ?>
</body></html>
