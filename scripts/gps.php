<?php
/* The device's GPS position over https (owner 2026-10-29, US-126): opened in a small window by the GPS button of the
 * location map while the station runs on http (static/location-picker.js); reads the position, hands it to the http page
 * that opened it (postMessage) and closes. No setting is read or changed here, so it opens without the login. */
require_once __DIR__ . '/common.php';
$h = function ($s) { return htmlspecialchars((string)$s, ENT_QUOTES); };
?><!DOCTYPE html>
<html lang="<?php echo $h(str_replace('_', '-', ui_lang())); ?>"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title><?php echo $h(t('str_position_gps')); ?></title>
<style>body { font-family: sans-serif; margin: 16px; line-height: 1.4; } b { font-size: 1.1em; } button { margin-top: 10px; padding: 6px 14px; }</style>
</head><body>
<h3><?php echo $h(t('str_position_gps')); ?></h3>
<div id="gpsmsg"><?php echo $h(t('str_reading_the_position')); ?></div>
<button type="button" id="gpsclose" onclick="window.close()" hidden><?php echo $h(t('str_close')); ?></button>
<script>
(function () {
  var T = <?php echo json_encode(array('allow' => t('str_allow_the_browser_to_use_your_help'), 'sent' => t('str_position_sent_to_the_station_page'),
    'none' => t('str_no_position'), 'copy' => t('str_type_these_coordinates_on_the_help'), 'nogps' => t('str_this_browser_gives_no_position')), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG); ?>;
  var msg = document.getElementById('gpsmsg'), back = 'http://' + location.host;
  function done(text) { msg.textContent = text; document.getElementById('gpsclose').hidden = false; }
  if (!navigator.geolocation) { done(T.nogps); return; }
  msg.textContent = T.allow;
  navigator.geolocation.getCurrentPosition(function (p) {
    var c = p.coords, data = {bsgps: [c.latitude, c.longitude, Math.round(c.accuracy)]};
    if (window.opener) {
      try { window.opener.postMessage(data, back); } catch (e) {}
      done(T.sent);
      setTimeout(function () { window.close(); }, 800);
    } else {
      done(T.copy + ' ' + c.latitude.toFixed(6) + ', ' + c.longitude.toFixed(6) + ' (±' + Math.round(c.accuracy) + ' m)');
    }
  }, function (e) { done(T.none.replace('{error}', e.message)); }, {enableHighAccuracy: true, timeout: 30000});
})();
</script>
</body></html>
