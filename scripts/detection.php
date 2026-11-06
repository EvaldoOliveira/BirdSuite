<?php
################################################################################
# scripts/detection.php - ONE DETECTION: THE CLIP, ITS SPECTROGRAM AND ITS ACTIONS
#
# PURPOSE:
#   The page of one detection (views.php?view=Detection, play.php?filename=<clip> from a shared link or a
#   notification, and the pages that embed it): the rows of the clip with the player, the review attributes
#   and the actions. Moved out of play.php, which keeps the endpoints (owner 2026-11-06).
#
# DEPENDENCIES:
#   - scripts/common.php, scripts/play.php (the actions), static/custom-audio-player.js, static/detection-actions.js, static/review-player.js
#
# USAGE:
#   views.php?view=Detection&filename=<clip>; play.php?filename=<clip> (whole page)
################################################################################

require_once __DIR__ . '/common.php';
// whole page when the request is play.php itself (a shared link, a notification); a fragment inside views.php
$det_standalone = basename((string)(get_included_files()[0] ?? '')) === 'play.php';
$det_db = new SQLite3(BS_DB, SQLITE3_OPEN_READONLY);
$det_db->busyTimeout(1000);

if ($det_standalone) {
	echo '<!DOCTYPE html>
<html lang="' . str_replace('_', '-', ui_lang()) . '">
  <head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    ' . ui_js() . '
  </head>';
}

?>
<script src="static/custom-audio-player.js?v=<?php echo @filemtime(__DIR__ . "/../homepage/static/custom-audio-player.js"); ?>"></script>
<script src="static/detection-actions.js?v=<?php echo @filemtime(__DIR__ . "/../homepage/static/detection-actions.js"); ?>"></script>
<script src="static/review-player.js?v=<?php echo @filemtime(__DIR__ . "/../homepage/static/review-player.js"); ?>"></script>
<div class="play">
<?php
if (isset($_GET['filename'])) {
	// a bound value, never the request text inside the SQL (2026-10-23 test run)
	$statement2 = $det_db->prepare("SELECT * FROM detections where File_name == :name ORDER BY Date DESC, Time DESC");
	ensure_db_ok($statement2);
	$statement2->bindValue(':name', html_entity_decode($_GET['filename'], ENT_QUOTES), SQLITE3_TEXT);
	$result2 = $statement2->execute();
	$results = $result2->fetchArray(SQLITE3_ASSOC);
	$sciname = $results['Sci_Name'];
	$result2->reset();
	// the detection's rows are its review list (static/review-player.js rpSourceItems)
	echo "<table data-review-list=\"1\">
    <tr><th>" . species_title($sciname, htmlspecialchars(common_name($sciname))) . "</th></tr>";
	while ($results = $result2->fetchArray(SQLITE3_ASSOC)) {
		$comname = species_folder($results['Sci_Name']);
		$date = $results['Date'];
		$filename_formatted = $date . "/" . $comname . "/" . $results['File_Name'];
		$filename = "/By_Date/" . $filename_formatted;
		$filename_png = clip_spectrogram($filename);
		$values = round((float)round($results['Confidence'], 2) * 100) . '%';
		echo "<tr>
      <td class=\"relative\"" . review_item_attrs($filename_formatted, $date . ' ' . $results['Time'], preg_replace('/ /', '_', $results['Sci_Name'])) . "> " . detection_actions($filename_formatted) . "$date {$results['Time']}<br>$values<br>
<div class='custom-audio-player' data-audio-src='$filename' data-image-src='$filename_png'></div>
</td></tr>";
	}
	echo "</table>";
}
echo "</div>";
if ($det_standalone) {
	echo '</html>';
}
