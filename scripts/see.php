<?php
################################################################################
# scripts/see.php - NOW: THE BIRDS HEARD IN THE LAST MINUTES
#
# PURPOSE:
#   Menu name Now (view=Now; owner 2026-10-30, US-130): the pictures of the birds singing now. The detections
#   of Now above the confidence chosen in the picklist, one card per species, each shown for the period of the
#   other picklist (1 minute at first); a species that sings again goes to the front and starts its period
#   over. Detections answered No / Ignore, a cause or Review later never show (owner 2026-11-01); after an
#   answer in the player the cards are read again. A click on the picture opens the shared review player on
#   the species' newest detection; closing it comes back to this page as it was (owner 2026-11-01: no buttons
#   on the cards, the player has the species card and the sound); a slider sets the size of the pictures and a
#   checkbox beside it shows the pictures only (rounded, the default) or with the name and time (both kept in
#   the browser). Opened as views.php?view=Now; scripts/see.php?data=1&conf=0.3 gives the cards.
#
# DEPENDENCIES:
#   - scripts/common.php
#   - homepage/static/species-modal.js
#   - homepage/static/custom-audio-player.js
#   - homepage/static/review-player.js
#   - homepage/static/see.js
#
# USAGE:
#   views.php?view=Now
#   Referenced by homepage/static/see.js, homepage/views.php, scripts/locations.php
################################################################################

require_once __DIR__ . '/common.php';
set_timezone();

// the background of the bird pictures (owner 2026-11-02): one per location, 'now_background' in locations.json (the
// absolute path of an image file in the station user's home; '' = none); a location without its own uses the station's
// NOW_BACKGROUND, Test / Playback its own NOW_BACKGROUND_TEST (Settings › Defaults, Now page). The location is the one
// the page's picker shows, else the current one; in All a change goes to every location except Test / Playback (the
// page warns first), and the picture shown is the current location's. The periodic refresh of the cards brings the
// background along (header X-See-Bg), so it follows a change of location without a reload. The picture icon beside Size opens the pictures saved on the station (config/locations/now-background-*,
// beside locations.json): a click uses one for this location, Upload adds one (made smaller in the browser, saved as
// now-background-<location name>-<date time>.<ext>), Clear sets the location to none. Every picture stays in the
// folder, so the backgrounds can be swapped back and forth (owner 2026-11-02). ?bg=img serves the location's picture,
// ?bg=list the saved ones, ?bg=file&name= one of them
// what the page's picker shows: 'all', a location id, or 'cur' (the current location, whichever it is at the time)
// a copy of picture $f at most $w px wide and high, JPEG, made once (Pillow, the formats an upload may have) and kept in
// state/now-bg/ by picture, its date and the size; the picture itself when it is small already or the copy fails
function see_bg_scaled($f, $w) {
	$info = @getimagesize($f);
	if ($info && $info[0] <= $w && $info[1] <= $w && filesize($f) < 300000) return $f;
	$dir = BS_STATE . '/now-bg';
	if (!is_dir($dir)) @mkdir($dir, 0775, true);
	$out = $dir . '/' . md5($f . '|' . filemtime($f) . '|' . $w) . '.jpg';
	if (!is_file($out)) {
		$py = "import sys\nfrom PIL import Image, ImageOps\nImage.MAX_IMAGE_PIXELS = 60000000\n"
			. "im = ImageOps.exif_transpose(Image.open(sys.argv[1], formats=('JPEG', 'PNG', 'WEBP', 'GIF'))).convert('RGB')\n"
			. "im.thumbnail((int(sys.argv[3]), int(sys.argv[3])))\nim.save(sys.argv[2], 'JPEG', quality=72, optimize=True, progressive=True)\n";
		shell_exec(escapeshellarg(BS_PYTHON) . ' -c ' . escapeshellarg($py) . ' ' . escapeshellarg($f) . ' ' . escapeshellarg($out . '.tmp')
			. ' ' . intval($w) . ' 2>/dev/null && mv -f ' . escapeshellarg($out . '.tmp') . ' ' . escapeshellarg($out));
	}
	return is_file($out) ? $out : $f;
}
function see_bg_mode() {
	$q = (string)($_GET['loc'] ?? '');
	if ($q === 'all' || $q === 'cur' || ctype_digit($q)) return $q;
	$f = (string)location_filter();
	return $f === 'all' ? 'all' : (ctype_digit($f) ? $f : 'cur');
}
function see_bg_loc($mode) {
	if (ctype_digit($mode) && ($l = location_by_id($mode))) return $l;
	return location_current();
}
function see_bg_is_test($l) { return $l && ($l['builtin'] ?? '') === 'test'; }
function see_bg_path($l) {
	$p = $l && array_key_exists('now_background', $l) ? (string)$l['now_background']
		: trim((string)(get_config()[see_bg_is_test($l) ? 'NOW_BACKGROUND_TEST' : 'NOW_BACKGROUND'] ?? ''), '" ');
	// a picture saved here is kept by its name (config/locations/, so locations.json follows a move or a restore)
	if ($p !== '' && strpos($p, '/') === false) $p = BS_LOCATIONS . '/' . $p;
	return $p !== '' && custom_image_ok($p) ? $p : '';
}
// the locations a change goes to: All = every location except Test / Playback
function see_bg_targets($mode) {
	if ($mode !== 'all') { $l = see_bg_loc($mode); return $l ? array($l) : array(); }
	return array_values(array_filter(locations_load(), function ($l) { return !see_bg_is_test($l); }));
}
// the picture and veil the page shows for this choice of the picker (its first paint and its periodic check)
function see_bg_state($mode) {
	$l = see_bg_loc($mode);
	$p = see_bg_path($l);
	return array('url' => $p !== '' ? 'scripts/see.php?bg=img&loc=' . intval($l['id'] ?? 0) . '&v=' . filemtime($p) : '', 'fade' => $l ? see_bg_fade($l) : 0);
}
function see_bg_base($l) {
	$slug = function ($n) {
		$a = function_exists('iconv') ? (string)@iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', (string)$n) : (string)$n;
		return trim(preg_replace('/[^a-z0-9]+/', '-', strtolower($a)), '-');
	};
	if (!$l) return BS_LOCATIONS . '/now-background-all-' . date('Ymd-His');  // set in All
	$mine = $slug($l['name'] ?? '');
	$clash = $mine === '';
	foreach (locations_load() as $o) if (intval($o['id']) !== intval($l['id']) && $slug($o['name'] ?? '') === $mine) $clash = true;
	return BS_LOCATIONS . '/now-background-' . ($mine === '' ? '' : $mine . ($clash ? '-' : '')) . ($clash ? intval($l['id']) : '') . '-' . date('Ymd-His');
}
// the pictures saved by the icon (config/now-background-*.<ext>), newest first
function see_bg_saved() {
	$out = array();
	foreach (glob(BS_LOCATIONS . '/now-background-*.{jpg,png,webp,gif}', GLOB_BRACE) ?: array() as $f) $out[basename($f)] = filemtime($f);
	arsort($out);
	return $out;
}
// how much of the page colour veils the picture (owner 2026-11-02: the Prominence slider of the window), 0-90 %
function see_bg_fade($l) { return max(0, min(90, intval($l['now_background_fade'] ?? 0))); }
function see_bg_store($targets, $path, $key = 'now_background') {
	$ids = array_map(function ($l) { return intval($l['id']); }, $targets);
	$list = locations_load();
	foreach ($list as &$x) if (in_array(intval($x['id']), $ids, true)) $x[$key] = $path;
	unset($x);
	return locations_save($list);
}
if (isset($_GET['bg'])) {
	if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
	$types = array('image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif');
	$mode = see_bg_mode();
	$loc = see_bg_loc($mode);
	if ($_GET['bg'] === 'state') {
		header('Content-Type: application/json');
		echo json_encode(see_bg_state($mode));
		exit;
	}
	if ($_GET['bg'] === 'list') {
		header('Content-Type: application/json');
		$cur = basename(see_bg_path($loc));
		$rows = array();
		foreach (see_bg_saved() as $n => $m) $rows[] = array('name' => $n, 'url' => 'scripts/see.php?bg=file&name=' . rawurlencode($n) . '&v=' . $m, 'current' => $n === $cur);
		echo json_encode($rows);
		exit;
	}
	if ($_GET['bg'] === 'img' || $_GET['bg'] === 'file') {
		$f = $_GET['bg'] === 'img' ? see_bg_path($loc) : '';
		if ($_GET['bg'] === 'file') { $n = basename((string)($_GET['name'] ?? '')); if (isset(see_bg_saved()[$n])) $f = BS_LOCATIONS . '/' . $n; }
		if ($f === '') { http_response_code(404); exit; }
		// the wallpaper at most 1600 px, the gallery's small pictures 480 px (release run 4, PERF-61: the wallpaper was sent
		// whole, 786 KB; behind its veil the copy, about 390 KB, looks the same): a smaller copy made once per picture and size, kept in state/now-bg/; the picture itself stays as it is
		$f = see_bg_scaled($f, $_GET['bg'] === 'file' ? 480 : 1600);
		$info = @getimagesize($f);
		header('Content-Type: ' . ($info['mime'] ?? 'image/jpeg'));
		header('Cache-Control: max-age=31536000, immutable');  // the page asks with ?v=<mtime>
		readfile($f);
		exit;
	}
	ensure_authenticated(t('str_you_must_change_the_settings_help'));
	if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); exit; }
	$targets = see_bg_targets($mode);
	if (!$targets) { http_response_code(400); echo t('str_unknown_location'); exit; }
	$base = see_bg_base($mode === 'all' ? null : $targets[0]);
	if ($_GET['bg'] === 'set') {
		$data = (string)file_get_contents('php://input', false, null, 0, 6 * 1024 * 1024 + 1);
		$info = $data !== '' && strlen($data) <= 6 * 1024 * 1024 ? @getimagesizefromstring($data) : false;
		if (!$info || !isset($types[$info['mime']])) { http_response_code(400); echo t('str_not_an_image_jpeg_png_or_webp_help'); exit; }
		if (file_put_contents($base . '.tmp', $data) === false) { http_response_code(500); echo t('str_could_not_save_the_image'); exit; }
		$dest = $base . '.' . $types[$info['mime']];
		rename($base . '.tmp', $dest);
		if (!see_bg_store($targets, basename($dest))) { http_response_code(500); echo t('str_error_writing', array('file' => basename(locations_file()))); exit; }
	} elseif ($_GET['bg'] === 'use') {
		$n = basename((string)($_GET['name'] ?? ''));
		if (!isset(see_bg_saved()[$n])) { http_response_code(400); echo t('str_no_such_picture'); exit; }
		if (!see_bg_store($targets, $n)) { http_response_code(500); echo t('str_error_writing', array('file' => basename(locations_file()))); exit; }
	} elseif ($_GET['bg'] === 'fade') {
		$v = max(0, min(90, intval($_GET['v'] ?? 0)));
		if (!see_bg_store($targets, $v, 'now_background_fade')) { http_response_code(500); echo t('str_error_writing', array('file' => basename(locations_file()))); exit; }
	} elseif ($_GET['bg'] === 'clear') {
		if (!see_bg_store($targets, '')) { http_response_code(500); echo t('str_error_writing', array('file' => basename(locations_file()))); exit; }
	} else { http_response_code(400); exit; }
	echo 'OK';
	exit;
}

// the recording state for X-See-Rec, read from systemctl at most every 5 s (Gitea #75: every poll of every open page
// spawned systemctl); kept in state/now-rec, '1' or '0'
function see_rec_active() {
	$f = BS_STATE . '/now-rec';
	$m = @filemtime($f);
	if ($m !== false && abs(time() - $m) < 5) return trim((string)@file_get_contents($f)) === '1';
	$on = trim((string)shell_exec('systemctl is-active ' . BS_SVC_RECORDING . '.service 2>/dev/null')) === 'active';
	if (!is_dir(BS_STATE)) @mkdir(BS_STATE, 0775, true);
	$tmp = $f . '.' . getmypid() . '.tmp';
	if (@file_put_contents($tmp, $on ? '1' : '0') !== false) @rename($tmp, $f);
	return $on;
}
// the database did not answer (Gitea #74): a 503 with the reason, so the page keeps its cards and says the station is
// not answering, instead of the 200 + text of ensure_db_ok() that it read as no bird at all
function see_data_fail() {
	http_response_code(503);
	echo json_encode(array('error' => t('str_database_is_busy')));
	exit;
}
if (isset($_GET['data'])) {
	if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
	header('Content-Type: application/json');
	// the browser keeps the cards with their ETag and asks again every time (Gitea #75): the session's no-store would forbid it
	header('Cache-Control: no-cache');
	header_remove('Pragma');
	header_remove('Expires');
	header('X-See-Bg: ' . json_encode(see_bg_state(see_bg_mode())));  // the background follows a change of location
	// the page says so while the recording is stopped (owner 2026-11-02, US-130 scope)
	header('X-See-Rec: ' . (see_rec_active() ? '1' : '0'));
	$conf = is_numeric($_GET['conf'] ?? '') ? max(0, min(1, (float)$_GET['conf'])) : 0.3;
	$min = intval($_GET['min'] ?? 60);
	if (!in_array($min, array(1, 5, 15, 60, 240, 480, 720), true)) $min = 60;
	// a clip reaches the database some 15 s after its time: two minutes more, the page decides what is still in its period
	$now = time();
	// the station's time, so the page works out each card's age (Gitea #75: an age in the body changed it every second,
	// and the ETag never matched)
	header('X-See-Now: ' . $now);
	$since = $now - $min * 60 - 120;
	try { $db = get_db(); } catch (Throwable $e) { see_data_fail(); }
	apply_location_filter($db); // the active location, as every page (owner 2026-10-31)
	// the columns of the review item too (Cutoff ... Sp_Override: the settings the review player lists); with MAX() SQLite
	// takes the bare columns from the newest row
	$st = $db->prepare("SELECT Sci_Name, com_name(Sci_Name) AS Com_Name, Date, Time, Confidence, File_Name, Cutoff, Sens, Overlap, Loc_Thresh, Rec_Length, Sp_Override, Mic, Mic_Port, Mic_Gain, Model, Audio_Loss_N, Audio_Loss_Ms, Tz, Utc_Offset,
                      MAX(Date || ' ' || Time) AS last FROM detections
                      WHERE Date BETWEEN :d0 AND :d1 AND Date || ' ' || Time >= :since AND Confidence >= :conf" . not_rejected_sql()
											// answered Review later leaves Now too (owner 2026-11-01), as the rejected ones (No / Ignore, the causes):
											// a species shows its newest detection that is neither
											. " AND File_Name NOT IN (SELECT File_Name FROM detection_reviews WHERE Verdict = 'unsure')"
											. ' GROUP BY Sci_Name');
	if (!$st) see_data_fail();
	$st->bindValue(':d0', date('Y-m-d', $since));
	$st->bindValue(':d1', date('Y-m-d', $now));
	$st->bindValue(':since', date('Y-m-d H:i:s', $since));
	$st->bindValue(':conf', $conf);
	$res = $st->execute();
	if (!$res) see_data_fail();
	$found = array();
	while ($r = $res->fetchArray(SQLITE3_ASSOC)) $found[] = $r;
	// the answers of only these detections for the review items (Gitea #75: not the whole review table every 5 s)
	if (function_exists('review_verdicts_only')) review_verdicts_only(array_column($found, 'File_Name'));
	$rows = array();
	foreach ($found as $r) {
		$photo = species_photo($r['Sci_Name']);
		$rows[] = array('sci' => $r['Sci_Name'], 'com' => $r['Com_Name'], 'time' => $r['Time'], 'conf' => round($r['Confidence'] * 100),
			'clip' => 'By_Date/' . rawurlencode($r['Date']) . '/' . rawurlencode(species_folder($r['Sci_Name'])) . '/' . rawurlencode($r['File_Name']),
			'file' => ($f = $r['Date'] . '/' . species_folder($r['Sci_Name']) . '/' . $r['File_Name']),
			// the review item's attributes from the shared helper (data-file, data-clip, data-spec, data-params ...)
			'ri' => review_item_attrs($f, $r['Com_Name'] . ', ' . $r['Date'], $r['Sci_Name'], $r),
			'key' => $r['File_Name'], 'last' => $r['last'], 'ts' => strtotime($r['last']), 'img' => $photo ? (string)($photo['image_url'] ?? '') : '');
	}
	$body = json_encode($rows, JSON_UNESCAPED_UNICODE);
	// the same cards as the last answer: 304, the browser gives the page its kept copy (Gitea #75)
	$etag = '"' . md5($body) . '"';
	header('ETag: ' . $etag);
	// a compressing web server may mark it weak (W/) or add its encoding ("...-gzip"): the md5 alone is compared
	$inm = (string)($_SERVER['HTTP_IF_NONE_MATCH'] ?? '');
	if ($inm !== '' && preg_match('/^(?:W\/)?"([0-9a-f]{32})(?:-[a-z]+)?"$/', trim($inm), $m) && $m[1] === md5($body)) { http_response_code(304); exit; }
	echo $body;
	exit;
}
?>
<style>
.see { padding: 0 12px 12px; }
.see .seeline { display: flex; flex-wrap: wrap; align-items: center; gap: 8px; margin-bottom: 10px; }
.see .seegrid { display: grid; grid-template-columns: repeat(auto-fill, minmax(min(var(--seesize, 220px), 100%), 1fr)); gap: 12px; align-content: start; align-items: start; }
/* every card a square (owner 2026-11-02): the whole picture fills what the name and time leave */
.see .seecard { border-radius: 10px; overflow: hidden; background: var(--bg,#fff); box-shadow: 0 1px 5px rgba(0,0,0,.3); aspect-ratio: 1 / 1; display: flex; flex-direction: column; }
.see .seecard img { display: block; width: 100%; flex: 1 1 auto; min-height: 0; object-fit: contain; background: rgba(0,0,0,.08); }
.see .seecard b { display: block; padding: 8px 10px 0; font-size: 16px; flex: none; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.see .seecard small { display: block; padding: 2px 10px 8px; flex: none; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
/* small cards keep the name readable (Gitea #75: at size 80 on a phone the time and confidence wrapped over it): below
	 140 px the time and confidence leave, the page sets seesmall from the Size slider */
.see.seesmall .seecard small { display: none; }
.see #seesize { min-height: 24px; }  /* a target of at least 24 px (WCAG 2.5.8) */
.see .seecard img { cursor: pointer; }
/* Clean (owner 2026-11-01, the default): only the pictures, rounded; the checkbox beside the slider brings the name and time back */
.see.seeclean .seecard { background: transparent; box-shadow: none; border-radius: 14px; }
.see.seeclean .seecard img { border-radius: 14px; background: rgba(0,0,0,.08); aspect-ratio: 1 / 1; height: auto; object-fit: contain; }  /* every picture whole, never cropped, inside squares of one size (owner 2026-11-01) */
.see.seeclean .seecard b, .see.seeclean .seecard small { display: none; }
.see.seeclean .seecard.seenew img { animation: seenewimg 1.5s ease-out; }
.see.seeclean .seecard.seenew { animation: none; }
@keyframes seenewimg { from { box-shadow: 0 0 0 5px var(--accent,#2b5e22); } to { box-shadow: none; } }
/* two lines, each with one control at the left and one at the right (owner 2026-11-02): Period | Minimum confidence, Pictures only | Size */
.see .seesize { display: flex; flex: 1 1 100%; align-items: center; gap: 6px; }
.see .seegrp { display: inline-flex; align-items: center; gap: 6px; }
.see .seeszr { display: inline-flex; align-items: center; gap: 6px; margin-left: auto; }
/* the background picture (owner 2026-11-02): it fills the whole area of the bird pictures (an explicit exception of the
	 owner to the whole-picture rule: a background covers its area), the controls stay on the page colour */
.see.seebg .seegrid { background-image: linear-gradient(var(--seebgveil, transparent), var(--seebgveil, transparent)), var(--seebgimg); background-size: cover; background-position: center; background-repeat: no-repeat;
	min-height: calc(100vh - 170px); padding: 12px; border-radius: 12px; box-sizing: border-box; }
.see .seerec, .see .seeoff { margin: 8px 0; padding: 6px 10px; border-radius: 8px; background: #fff3cd; color: #7a4b00; font-weight: 600; }
.see .seebgicon { cursor: pointer; border-radius: 50%; padding: 3px; display: inline-flex; color: var(--accent,#2b5e22); vertical-align: middle; }
.see.seebg .seebgicon { background: var(--accent,#2b5e22); color: #fff; }  /* filled while a background is set */
/* the pictures saved on the station, in the look of the shared askModal (static/species-modal.js) */
#seeBgModal { position: fixed; inset: 0; background: rgba(0,0,0,.45); display: none; align-items: center; justify-content: center; z-index: 1000; }
#seeBgModal .spm-box { background: var(--panel,#fff); color: var(--text,#000); border-radius: 10px; max-width: 560px; width: calc(100% - 32px); max-height: calc(100vh - 32px); display: flex; flex-direction: column; overflow: hidden; box-shadow: 0 6px 24px rgba(0,0,0,.35); font-size: 14px; }
#seeBgModal .spm-head { background: var(--accent,#2b5e22); color: #fff; padding: 10px 14px; }
#seeBgModal h3 { margin: 0; font-size: 16px; color: #fff; text-align: left; }
#seeBgModal p { margin: 0; padding: 12px 16px 6px; line-height: 1.4; }
#seeBgModal .seebglist { display: grid; grid-template-columns: repeat(auto-fill, minmax(110px, 1fr)); gap: 8px; padding: 8px 16px; overflow-y: auto; }
#seeBgModal .seebglist img { width: 100%; aspect-ratio: 1 / 1; object-fit: contain; background: rgba(0,0,0,.08); border-radius: 8px; cursor: pointer; border: 3px solid transparent; box-sizing: border-box; }
#seeBgModal .seebglist img.cur { border-color: var(--accent,#2b5e22); }
#seeBgModal .seebglist em { grid-column: 1 / -1; opacity: .7; }  /* the page's text a little lighter, on both themes (Gitea #75) */
#seeBgModal p.seebgall { padding-top: 0; color: #b45309; font-weight: 600; }
#seeBgModal .seebgfade { display: flex; align-items: center; gap: 8px; padding: 6px 16px 0; }
#seeBgModal .seebgfade input { flex: 1; }
#seeBgModal .spm-buttons { display: flex; justify-content: flex-end; gap: 8px; padding: 10px 16px 14px; flex-wrap: wrap; }
#seeBgModal .spm-buttons button { min-width: 72px; padding: 6px 14px; border-radius: 14px; border: 1px solid var(--accent,#2b5e22); background: #fff; color: var(--accent,#2b5e22); font-weight: 600; cursor: pointer; width: auto; }
#seeBgModal .spm-buttons button.primary { background: var(--accent,#2b5e22); color: #fff; }
#seeBgModal .spm-buttons #seebgclear { margin-right: auto; }
.see .seecard.seenew { animation: seenew 1.5s ease-out; }
@keyframes seenew { from { box-shadow: 0 0 0 5px var(--accent,#2b5e22); } to { box-shadow: 0 1px 5px rgba(0,0,0,.3); } }
@media (prefers-reduced-motion: reduce) { .see .seecard.seenew, .see.seeclean .seecard.seenew img { animation: none; } }
</style>
<?php $see_mode = see_bg_mode(); $see_st = see_bg_state($see_mode); $see_bg_url = $see_st['url']; $see_fade = $see_st['fade']; ?>
<div data-loc="<?php echo htmlspecialchars($see_mode, ENT_QUOTES); ?>" data-bg="<?php echo htmlspecialchars($see_bg_url, ENT_QUOTES); ?>" data-fade="<?php echo $see_fade; ?>" class="see<?php echo $see_bg_url !== '' ? ' seebg' : ''; ?>"<?php echo $see_bg_url !== '' ? ' style="--seebgimg:url(\'' . htmlspecialchars($see_bg_url, ENT_QUOTES) . '\')"' : ''; ?>>
	<div class="seeline"><?php // Period at the left, Minimum confidence at the right (owner 2026-11-02) ?>
		<?php // the station's defaults (owner 2026-11-04: All settings › Now page), a browser that picked another keeps it
			$now_cfg = get_config();
			$now_per = in_array((string)($now_cfg['NOW_PERIOD'] ?? '60'), array('1', '5', '15', '60', '240', '480', '720'), true) ? intval($now_cfg['NOW_PERIOD']) : 60;
			$now_conf = in_array((string)($now_cfg['NOW_CONF'] ?? '30'), array('10', '20', '30', '40', '50', '60', '70', '80', '90'), true) ? intval($now_cfg['NOW_CONF']) : 30; ?>
		<span class="seegrp"><label for="seeperiod"><?php echo t('str_period'); ?></label>
			<select id="seeperiod"><?php foreach (array(1 => 'str_last_minute', 5 => 'str_last_5_minutes', 15 => 'str_last_15_minutes', 60 => 'str_last_hour', 240 => 'str_last_4_hours', 480 => 'str_last_8_hours', 720 => 'str_last_12_hours') as $v => $l) echo '<option value="' . $v . '"' . ($v === $now_per ? ' selected' : '') . '>' . t($l) . '</option>'; ?></select></span>
		<span class="seegrp seeszr"><label for="seeconf"><?php echo t('str_minimum_confidence'); ?></label>
			<select id="seeconf"><?php foreach (array(10, 20, 30, 40, 50, 60, 70, 80, 90) as $v) echo '<option value="' . $v . '"' . ($v === $now_conf ? ' selected' : '') . '>' . $v . '%</option>'; ?></select></span>
		<?php // the size of the pictures (owner 2026-11-01), kept in this browser ?>
		<span class="seesize"><label><input type="checkbox" id="seeclean" checked> <?php echo t('str_pictures_only'); ?></label>
			<span class="seeszr"><label for="seesize"><?php echo t('str_size'); ?></label> <input type="range" id="seesize" min="80" max="520" step="20" value="220">
				<span class="seebgicon" id="seebgicon" role="button" tabindex="0" title="<?php echo t('str_background_picture'); ?>"><svg viewBox="0 0 24 24" width="18" height="18" aria-label="<?php echo t('str_background_picture'); ?>" role="img"><path fill="currentColor" d="M3 5h18v14H3zm2 2v8.6l4-4.1 3 3 2.5-2.5L19 16.5V7zm10 1.5a1.5 1.5 0 1 1 0 3 1.5 1.5 0 0 1 0-3z"/></svg></span></span></span></div>
	<input type="file" id="seebgfile" accept="image/jpeg,image/png,image/webp" hidden>
	<div id="seeBgModal" role="dialog" aria-modal="true" aria-labelledby="seebgtitle"><div class="spm-box">
		<div class="spm-head"><h3 id="seebgtitle"><?php echo t('str_background_picture'); ?></h3></div>
		<p><?php echo t('str_the_pictures_saved_on_the_help'); ?></p>
		<?php if ($see_mode === 'all') echo '<p class="seebgall">' . t('str_all_a_change_goes_to_every_help') . '</p>'; ?>
		<div class="seebglist" id="seebglist"></div>
		<label class="seebgfade"><?php echo t('str_prominence'); ?> <input type="range" id="seebgfade" min="10" max="100" step="5" value="<?php echo 100 - $see_fade; ?>"> <span id="seebgfadev"><?php echo 100 - $see_fade; ?>%</span></label>
		<div class="spm-buttons"><button type="button" id="seebgclear"><?php echo t('str_clear_the_background'); ?></button>
			<button type="button" id="seebgclose"><?php echo t('str_close'); ?></button><button type="button" class="primary" id="seebgupload"><?php echo t('str_upload_an_image'); ?></button></div>
	</div></div>
	<?php // the notes above the cards (Gitea #75); the station not answering keeps the cards as they are (Gitea #74) ?>
	<p id="seerec" class="seerec" hidden><?php echo t('str_recording_stopped'); ?></p>
	<p id="seeoff" class="seeoff" role="status" hidden><?php echo t('str_the_station_is_not_answering_help'); ?></p>
	<p id="seeempty" hidden><?php echo t('str_no_bird_heard_in_this_period_at_help'); ?></p>
	<div class="seegrid" id="seegrid" aria-live="polite"></div>
</div>
<script src="static/custom-audio-player.js?v=<?php echo @filemtime(__DIR__ . '/../homepage/static/custom-audio-player.js'); ?>"></script>
<script src="static/review-player.js?v=<?php echo @filemtime(__DIR__ . '/../homepage/static/review-player.js'); ?>"></script>
<script src="static/see.js?v=<?php echo @filemtime(__DIR__ . "/../homepage/static/see.js"); ?>"></script>
