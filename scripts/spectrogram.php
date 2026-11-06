<?php
error_reporting(E_ERROR);
ini_set('display_errors', 0);  // errors to the log, never to the page (2026-10-23 security run)

require_once "scripts/common.php";
$home = get_home();
$config = get_config();


if(!empty($config['SPECTROGRAM_HEIGHT']) && is_numeric($config['SPECTROGRAM_HEIGHT'])){
    $SPECTROGRAM_HEIGHT = ($config['SPECTROGRAM_HEIGHT']);
}else{
    $SPECTROGRAM_HEIGHT = 70;
}

// US-41: colour palette of the live waterfall (and of the SoX images, see
// spectrogram.sh / reporting.py). Names must match $SPECTROGRAM_PALETTES.
$SPECTROGRAM_PALETTES = array(
  // grouped by colour (owner 2026-10-27): Turbo (the default, every colour), the dark → warm ones, the blue-green ones,
  // grayscale
  'turbo'     => t('str_turbo_blue_green_red'),
  'inferno'   => t('str_inferno_black_red_yellow'),
  'roseus'    => t('str_roseus_black_violet_yellow'),
  'soxheat'   => t('str_sox_heat_black_purple_orange_white'),
  'birdnet'   => t('str_classic_purple_red_orange'),
  'viridis'   => t('str_viridis_dark_yellow'),
  'ocean'     => t('str_ocean_black_cyan'),
  'grayscale' => t('str_grayscale_black_white'),
);
$SPECTROGRAM_PALETTE = 'turbo';
if(!empty($config['SPECTROGRAM_PALETTE']) && array_key_exists($config['SPECTROGRAM_PALETTE'], $SPECTROGRAM_PALETTES)){
    $SPECTROGRAM_PALETTE = $config['SPECTROGRAM_PALETTE'];
}
// US-42: colour sensitivity — dB floor, dB range and contrast (gamma) of the ramp
$SPECTROGRAM_FLOOR_DB = (isset($config['SPECTROGRAM_FLOOR_DB']) && is_numeric($config['SPECTROGRAM_FLOOR_DB'])) ? max(-120, min(-40, intval($config['SPECTROGRAM_FLOOR_DB']))) : -95;
$SPECTROGRAM_RANGE_DB = (isset($config['SPECTROGRAM_RANGE_DB']) && is_numeric($config['SPECTROGRAM_RANGE_DB'])) ? max(30, min(120, intval($config['SPECTROGRAM_RANGE_DB']))) : 50;
$SPECTROGRAM_CONTRAST = (isset($config['SPECTROGRAM_CONTRAST']) && is_numeric($config['SPECTROGRAM_CONTRAST'])) ? max(0.5, min(2.0, floatval($config['SPECTROGRAM_CONTRAST']))) : 1.7;

// the factory values of the four picture settings (config/factory_defaults.conf), for the Defaults button
$SPECTROGRAM_FACTORY = array('palette' => 'turbo', 'floor' => -95, 'range' => 50, 'contrast' => 1.7);
$fd = @file_get_contents(BS_DEFAULTS);
foreach (array('palette' => 'SPECTROGRAM_PALETTE', 'floor' => 'SPECTROGRAM_FLOOR_DB', 'range' => 'SPECTROGRAM_RANGE_DB', 'contrast' => 'SPECTROGRAM_CONTRAST') as $k => $key) {
  if ($fd !== false && preg_match('/^' . $key . '="?([^"\n]*)"?/m', $fd, $m)) {
    $v = trim($m[1]);
    if ($k == 'palette') { if (array_key_exists($v, $SPECTROGRAM_PALETTES)) $SPECTROGRAM_FACTORY[$k] = $v; }
    elseif (is_numeric($v)) $SPECTROGRAM_FACTORY[$k] = $k == 'contrast' ? floatval($v) : intval($v);
  }
}

// US-41/US-42 save endpoint (owner 2026-09-17: "do not restart the server when
// changing colour or spectrogram — only the spectrogram engine"): writes ONLY
// the spectrogram keys to BirdSuite.conf and restarts the spectrogram service
// (the SoX image engine). Nothing else is touched — no restart_services.sh.
if(isset($_GET['save_spectrogram'])) {
  ensure_authenticated();
  if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); die(); }
  $keys = array(
    'spectrogram_palette'  => array('SPECTROGRAM_PALETTE', 'enum'),
    'spectrogram_height'   => array('SPECTROGRAM_HEIGHT', 'int', array(20, 100)),
    'spectrogram_floor_db' => array('SPECTROGRAM_FLOOR_DB', 'int', array(-120, -40)),
    'spectrogram_range_db' => array('SPECTROGRAM_RANGE_DB', 'int', array(30, 120)),
    'spectrogram_contrast' => array('SPECTROGRAM_CONTRAST', 'float', array(0.5, 2.0)),
    // the defaults the page opens with (owner 2026-10-23, "Set as default"): the audio only, no image to redraw
    'spectrogram_silent'      => array('SPECTROGRAM_SILENT', 'enum'),
    'spectrogram_compression' => array('SPECTROGRAM_COMPRESSION', 'enum'),
  );
  $audio_only = array('SPECTROGRAM_SILENT', 'SPECTROGRAM_COMPRESSION');
  $redraw = false;
  $set = array();
  foreach ($keys as $param => $spec) {
    if (!isset($_GET[$param])) continue;
    $raw = trim($_GET[$param]);
    // numbers clamped into their range; the value itself checked by conf_set_many() (common.php)
    if ($spec[1] == 'enum') $val = $raw;
    elseif (!is_numeric($raw)) continue;
    elseif ($spec[1] == 'int') $val = max($spec[2][0], min($spec[2][1], intval($raw)));
    else $val = max($spec[2][0], min($spec[2][1], round(floatval($raw), 2)));
    if (conf_check($spec[0], $val) === null) continue;
    $set[$spec[0]] = $val;
    if (!in_array($spec[0], $audio_only, true)) $redraw = true;
  }
  if ($set) {
    conf_set_many($set);
    if ($redraw) exec("sudo -n " . BS_SVC_HELPER . " restart " . BS_SVC_SPECTROGRAM . ".service");
  }
  header('Content-Type: text/plain');
  echo $set ? "OK" : "NOCHANGE";
  die();
}

if(isset($_GET['ajax_csv'])) {
  $newest_file = '';
  $RECS_DIR = $config["RECS_DIR"];
  $STREAM_DATA_DIR = $RECS_DIR . "/StreamData/";

  if (empty($config['RTSP_STREAM'])) {
    $look_in_directory = $STREAM_DATA_DIR;
    $files = glob($look_in_directory . "*.wav.json");
    if (count($files) !== 0) {
      // glob() returns full paths (unlike the scandir branch below) and sorts
      // alphabetically, i.e. chronologically for these date-prefixed names:
      // take the newest and reduce it to a basename like the RTSP branch does,
      // since $look_in_directory is prepended again when the file is read.
      $newest_file = basename(end($files));
    }
  }
  else {
    $look_in_directory = $STREAM_DATA_DIR;

    //Load the file in the directory
    $files = scandir($look_in_directory, SCANDIR_SORT_ASCENDING);

    //Because there might be more than 1 stream, we can't really assume the file at index 2 is the latest, or even for the stream being listened to
    //Read the RTSP_STREAM_TO_LIVESTREAM setting, then try to find that CSV file
    if(!empty($config['RTSP_STREAM_TO_LIVESTREAM']) && is_numeric($config['RTSP_STREAM_TO_LIVESTREAM'])){
        //The stored setting of RTSP_STREAM_TO_LIVESTREAM is 0 based, but filenames are 1's based, so just add 1 to the config value
        //so we can match up the stream the user is listening to with the appropriate filename
        $RTSP_STREAM_LISTENED_TO = ($config['RTSP_STREAM_TO_LIVESTREAM'] + 1);
    }else{
        //Setting is invalid somehow
        //The stored setting of RTSP_STREAM_TO_LIVESTREAM is 0 based, but filenames are 1's based, so just add 1 to the config value
        //This will be the first stream
        $RTSP_STREAM_LISTENED_TO = 1;
    }

    //The RTSP streams contain 'RTSP_X' in the filename, were X is the stream url index in the comma separated list of RTSP streams
    //We can use this to locate the file for this stream
    foreach ($files as $file_idx => $stream_file_name) {
        //Skip the folder hierarchy entries
        if ($stream_file_name != "." && $stream_file_name != "..") {
            //See if the filename contains the correct RTSP name, also only check .wav.csv files
            if (stripos($stream_file_name, 'RTSP_' . $RTSP_STREAM_LISTENED_TO) !== false && stripos($stream_file_name, '.wav.json') !== false) {
                //Found a match - set it as the newest file
                $newest_file = $stream_file_name;
            }
        }
    }
}


//If the newest file param has been supplied and it's the same as the newest file found
//then stop processing
if (empty($newest_file) || $newest_file === ($_GET['newest_file'] ?? '')) {
  die();
}

$contents = file_get_contents($look_in_directory . $newest_file);
if ($contents !== false) {
  $json = json_decode($contents);
  if ($json != null) {
    $datetime = DateTime::createFromFormat(DateTime::ISO8601, $json->{'timestamp'});
    // total seconds since the recording started: DateInterval's '%s' is only the seconds part
    // (0-59), so an analysis running minutes behind put its labels at a wrong place
    $json->delay = $datetime ? (time() - $datetime->getTimestamp()) : 0;
    echo json_encode($json);
  }
}

//Kill the script so no further processing or output is done
die();
}

//Hold the array of RTSP steams once they are exploded
$RTSP_Stream_Config = array();

//Load the birdnet config so we can read the RTSP setting
// Valid config data
if (is_array($config) && array_key_exists('RTSP_STREAM',$config)) {
	if (is_null($config['RTSP_STREAM']) === false && $config['RTSP_STREAM'] !== "") {
		$RTSP_Stream_Config_Data = explode(",", $config['RTSP_STREAM']);

		//Process the stream further
		//we need to able to ID it (just do this by position), get the hostname to show in the dropdown box
		foreach ($RTSP_Stream_Config_Data as $stream_idx => $stream_url) {
			//$stream_idx is the array position of the the RSP stream URL, idx of 0 is the first, 1 - second etc
			$RTSP_stream_url = parse_url($stream_url);
			$RTSP_Stream_Config[$stream_idx] = $RTSP_stream_url['host'];
		}
	}
}

// opened alone (in a frame: First Run, Station › Microphone) the page has no head of views.php: the station's texts and
// number format (t(), fmtNum) come from here (2026-11-04: the hearing scales stopped with t is not defined)
if (get_included_files()[0] === __FILE__) echo ui_js();
?>
<script src="static/hearing-audio.js?v=<?php echo @filemtime(__DIR__ . '/../homepage/static/hearing-audio.js'); ?>"></script>
<script>  
// CREDITS: https://codepen.io/jakealbaugh/pen/jvQweW

// UPDATE: there is a problem in chrome with starting audio context
//  before a user gesture. This fixes it.
var started = null;
var CTX = null;
var player = null;
var gain = 128;
const ctx = null;
// the frame rate as a running average (2026-11-04 hardening: a list of every frame grew without end and was summed on
// every frame, so after an hour the page spent its time adding 200 000 numbers and the sonogram stuttered and froze)
let avgfps;
// Scroll speed in pixels per second. One column per frame shows only W/fps seconds — about 6 s on a
// phone — while a detection arrives 20-30 s after its sound, so its label fell off the left edge.
// The graph keeps at least MIN_WINDOW_S seconds visible (owner 2026-10-08).
const MIN_WINDOW_S = 45;
let pxPerSec = 60;
const spectrogramOpenedAt = Date.now();
let requestTime;

<?php 
if(isset($_GET['legacy']) && $_GET['legacy'] == "true") {
  echo "var legacy = true;";
} else {
  echo "var legacy = false;";
}
?>

window.onload = function(){
  var playersrc =  document.getElementById('playersrc');
  // the stream did not answer: once it played, the watchdog brings it back; before that it is tried again for 20 s
  // (2026-11-04 hardening: one failed answer, e.g. during a restart of the live audio, sent the page to the picture
  // mode for good) and only then the page falls back to the picture mode
  var srcErrors = 0;
  playersrc.onerror = function() {
    if (started) { liveWatch.kick(); return; }
    // the picture mode of this same address (the page alone in a frame, or inside views.php)
    if (++srcErrors > 10) { var u = new URL(window.location.href); u.searchParams.set('legacy', 'true'); window.location.replace(u.toString()); return; }
    setTimeout(function () { var p = document.getElementById('player'); if (p) { p.load(); startPlay(p); } }, 2000);
  };

  // if user agent includes iPhone or Mac use legacy mode
  if(window.navigator.userAgent.includes("iPhone") || legacy == true) {
    document.getElementById("spectrogramimage").style.display="";
    // the live parts go (each only when present: a missing one stopped the picture mode with an error)
    [document.body.querySelector('canvas'), document.getElementById('player'), document.getElementById('loading-h1'),
     document.getElementsByClassName('centered')[0]].forEach(function (e) { if (e) e.remove(); });

    <?php $refresh = max(3, intval($config['RECORDING_LENGTH'] ?? 15)); ?>
    // every $refresh seconds, this loop will run and refresh the spectrogram image
  window.setInterval(function(){
    document.getElementById("spectrogramimage").src = "spectrogram.png?nocache="+Date.now();
  }, <?php echo $refresh; ?>*1000);
  } else {
    document.getElementById("spectrogramimage").remove();

  // its own stream, never the header's Live Audio player (owner 2026-10-10: that one has its own life)
  player = document.getElementById('player');
  player.oncanplay = function() {
    if (started) return;
    started = true;
    initialize();
  };
  startPlay(player);
  
  }
};

// play, and when the browser refuses to start sound without a tap (a page opened without a click, an iframe), say so
// and start on the first tap anywhere on the page (2026-11-04 hardening: it stayed on Loading for ever)
var needTap = false;
function startPlay(p) {
  var pr = p.play();
  if (pr && pr.then) pr.then(function () {
    if (!needTap) return;
    needTap = false; var h = document.getElementById('loading-h1'); if (h && started) h.remove();
  }, function (e) {
    if (!e || e.name !== 'NotAllowedError' || needTap) return;
    needTap = true;
    var h = document.getElementById('loading-h1');
    if (!h) { h = document.createElement('h1'); h.id = 'loading-h1'; document.body.appendChild(h); }
    h.textContent = t('str_tap_to_start_the_live_sound'); h.style.cursor = 'pointer';
    var go = function () { document.removeEventListener('pointerdown', go, true); document.removeEventListener('keydown', go, true); wakeAudio(); startPlay(p); };
    document.addEventListener('pointerdown', go, true); document.addEventListener('keydown', go, true);
  });
}
// the browser suspends the sound engine without a tap and on some phones when the screen sleeps: it is woken on play,
// on a tap and when the page shows again
function wakeAudio() { if (ACTX && ACTX.state === 'suspended') ACTX.resume().catch(function () {}); }
document.addEventListener('pointerdown', wakeAudio, true);
document.addEventListener('visibilitychange', function () { if (!document.hidden) { wakeAudio(); liveWatch.kick(); } });

// the watchdog of the live sound (2026-11-04 hardening): the stream breaks when the live audio restarts on the station
// (a change of microphone, gain, lowering, an update) or the network drops; the player then stops without a word and the
// sonogram froze until the page was reloaded. Every 2 s: when the sound has not moved for 6 s (or the player ended,
// failed or paused by itself) it reconnects, with a growing wait between tries (1, 2, 4 ... up to 30 s)
var liveWatch = (function () {
  var lastT = -1, still = 0, backoff = 0, nextTry = 0, busy = false;
  var ok = function () { still = 0; backoff = 0; nextTry = 0; };
  var fix = function () {
    if (busy || !started) return;
    var now = Date.now(); if (now < nextTry) return;
    busy = true;
    liveReconnect(function (good) {
      busy = false; lastT = -1; still = 0;
      if (good) ok(); else { backoff = Math.min(30000, backoff ? backoff * 2 : 1000); nextTry = Date.now() + backoff; }
    });
  };
  setInterval(function () {
    var p = document.getElementById('player');
    if (!p || !started || busy || needTap) return;
    wakeAudio();
    if (p.ended || p.error || p.paused) { fix(); return; }
    if (p.currentTime !== lastT) { lastT = p.currentTime; ok(); return; }
    still += 2; if (still >= 6) fix();
  }, 2000);
  return { kick: function () { if (!busy) { still = 6; } }, busy: function () { return busy; } };
})();

function applyText(text,x,y,opacity) {
  // Readable on every palette (US-41/42 follow-up): the label sits at mid
  // height, right where the call is painted bright (yellow on Viridis), so
  // plain white text vanished there. Dark outline under a bold white fill,
  // opacity floor raised to 0.6.
  if(opacity < 0.6) {
    opacity = 0.6;
  }
  CTX.textAlign = "center";
  CTX.font = 'bold 11px Roboto Flex';  // was 15px (owner 2026-10-08)
  CTX.lineWidth = 4;
  CTX.lineJoin = "round";
  CTX.strokeStyle = "rgba(0, 0, 0, "+opacity+")";
  CTX.strokeText(text,parseInt(x),y);
  CTX.fillStyle = "rgba(255, 255, 255, "+opacity+")";
  CTX.fillText(text,parseInt(x),y)
  CTX.fillStyle = paletteColor(0);
}

var add=0;
var newest_file;
var detPolling = false;
function loadDetectionIfNewExists() {
  // one question at a time (a slow station piled up a request a second) and none while the page is hidden or the
  // picture is not drawn yet
  if (detPolling || document.hidden || !started || !CTX) return;
  detPolling = true;
  const xhttp = new XMLHttpRequest();
  xhttp.timeout = 10000;
  xhttp.onloadend = function () { detPolling = false; };
  xhttp.onload = function() {
    // if there's a new detection that needs to be updated to the page
    if(this.status === 200 && this.responseText.length > 0 && !this.responseText.includes("Database")) {
      let resp; try { resp = JSON.parse(this.responseText); } catch (e) { return; }
      if (!resp || !Array.isArray(resp.detections)) return;
      newest_file = resp.file_name;
      for (const detection of resp.detections) {
        // only sounds heard since the page opened: older ones would pile up on the still-empty graph
        if (resp.timestamp && Date.parse(resp.timestamp) + detection.start * 1000 < spectrogramOpenedAt) continue;
        const secago = resp.delay - detection.start;
        const x = document.body.querySelector('canvas').width - (secago * pxPerSec);
        const y = (document.body.querySelector('canvas').height * 0.50) + add;
        if(x > document.body.querySelector('canvas').width - (5*pxPerSec) && detection.common_name.length > 8) {
          setTimeout(function (detection, x, y) {
            applyText(detection.common_name, x, y, detection.confidence);
          }, 3*1000, detection, x - (5*pxPerSec), y);
        } else {
          applyText(detection.common_name, x, y, detection.confidence);
        }
        // stagger Y placement
        add+= 12;  // line step for the 11px labels
        if(add >= 48) {
           add = 0;
        }
      }
    }
  };
  xhttp.open("GET", "spectrogram.php?ajax_csv=true&newest_file="+newest_file, true);
  xhttp.send();
}

window.setInterval(function(){
   loadDetectionIfNewExists();
}, 1000);

// US-41: palettes as RGB stops over the 0..1 magnitude; birdnet keeps the
// original HSL ramp (hue 280 → 400, lightness 10 % → 80 %).
const PALETTE_STOPS = {
  viridis:   [[68,1,84],[59,82,139],[33,145,140],[94,201,98],[253,231,37]],
  inferno:   [[0,0,4],[87,16,110],[188,55,84],[249,142,9],[252,255,164]],
  ocean:     [[0,0,0],[0,30,90],[0,110,180],[0,200,230],[220,255,255]],
  grayscale: [[0,0,0],[255,255,255]],
  soxheat:   [[0,0,0],[30,0,90],[120,0,140],[200,40,60],[240,140,0],[255,240,120],[255,255,255]],
  // turbo (Google, the rainbow without false bands) and roseus (Audacity's default), 11 stops of the published maps
  turbo:     [[48,18,59],[69,89,203],[62,155,254],[25,213,205],[70,248,132],[164,252,60],[225,221,55],[254,164,49],[240,91,18],[195,37,3],[122,4,3]],
  roseus:    [[1,1,1],[0,26,45],[27,38,106],[84,32,152],[144,22,159],[195,40,132],[230,75,96],[249,120,71],[249,172,90],[236,220,169],[254,251,249]],
};
var palette = "<?php echo $SPECTROGRAM_PALETTE; ?>";
// US-42: sensitivity — the analyser maps [floor, floor+range] dB onto 0..255;
// the ramp is then bent by the contrast gamma (1 = linear, < 1 lifts faint
// sounds, > 1 keeps only the strong ones).
var specFloor = <?php echo $SPECTROGRAM_FLOOR_DB; ?>;
var specRange = <?php echo $SPECTROGRAM_RANGE_DB; ?>;
var specGamma = <?php echo $SPECTROGRAM_CONTRAST; ?>;
// Silent and Compression start from the station defaults (SPECTROGRAM_SILENT / SPECTROGRAM_COMPRESSION, US-121);
// a browser that changed Silent keeps its own choice
var silentDefault = <?php echo ($config['SPECTROGRAM_SILENT'] ?? '1') !== '0' ? 'true' : 'false'; ?>;
var silentMode = silentDefault;
var compressionDefault = <?php echo ($config['SPECTROGRAM_COMPRESSION'] ?? '0') === '1' ? 'true' : 'false'; ?>;
try { var sv = window.localStorage.getItem('spectrogram_silent'); if (sv !== null) silentMode = sv !== '0'; } catch (e) {}
function applySilent() {
  if (typeof outGain === 'undefined' || !outGain) return;
  outGain.gain.setValueAtTime(silentMode ? 0 : 1, ACTX.currentTime);
}
function applySensitivity() {
  if (typeof ANALYSER === 'undefined' || !ANALYSER) return;
  ANALYSER.minDecibels = specFloor;
  ANALYSER.maxDecibels = Math.min(0, specFloor + specRange);
}
function paletteColor(rat) {
  rat = Math.pow(Math.min(Math.max(rat, 0), 1), specGamma);
  if (!(palette in PALETTE_STOPS)) {
    let hue = Math.round((rat * 120) + 280 % 360);
    return `hsl(${hue}, 100%, ${10 + (70 * rat)}%)`;
  }
  const st = PALETTE_STOPS[palette];
  const pos = Math.min(Math.max(rat, 0), 1) * (st.length - 1);
  const i = Math.min(Math.floor(pos), st.length - 2);
  const f = pos - i;
  const r = Math.round(st[i][0] + (st[i+1][0] - st[i][0]) * f);
  const g = Math.round(st[i][1] + (st[i+1][1] - st[i][1]) * f);
  const b = Math.round(st[i][2] + (st[i+1][2] - st[i][2]) * f);
  return `rgb(${r}, ${g}, ${b})`;
}

// the 256 colours of the current palette and contrast, built once instead of a colour string per band per frame
var _lut = null, _lutKey = '';
function paletteLUT() {
  var k = palette + '|' + specGamma;
  if (_lut && _lutKey === k) return _lut;
  _lut = []; for (var i = 0; i < 256; i++) _lut.push(paletteColor(i / 255));
  _lutKey = k; return _lut;
}

var compressor = undefined;
var SOURCE;
var ACTX;
var ANALYSER;
var gainNode;
var HEAR = null;   // the hearing aid's piece of the graph (static/hearing-audio.js)
var outGain;   // last stage before the speakers, 0 = silent mode (spectrogram only), 1 = audible

function toggleCompression(state) {
  //var biquadFilter = ACTX.createBiquadFilter();
  //biquadFilter.type = "highpass";
 // biquadFilter.frequency.setValueAtTime(13000, ACTX.currentTime);
  if(state == true) {
    SOURCE.disconnect(gainNode)
    gainNode.disconnect(ANALYSER);
    gainNode.disconnect(outGain);
    SOURCE.connect(compressor);
    compressor.connect(ANALYSER);
    ANALYSER.connect(gainNode);
    gainNode.connect(outGain);
    //biquadFilter.connect(ANALYSER);
    //biquadFilter.connect(ACTX.destination);
  } else {
    SOURCE.disconnect(compressor);
    compressor.disconnect(ANALYSER);
    ANALYSER.disconnect(gainNode);
    gainNode.disconnect(outGain);
    SOURCE.connect(gainNode);
    gainNode.connect(ANALYSER);
    gainNode.connect(outGain);
  }
}

// the live audio restarted on the station (the frequency lowering changed): the player reconnects as soon as the stream
// answers again, trying every half second for 15 s, instead of waiting a fixed delay (owner 2026-11-04: the setting
// FREQSHIFT_RECONNECT_DELAY is gone); done(true) when it plays again, done(false) when it never came back
var reconnecting = false;
function liveReconnect(done) {
  var p = document.querySelector('audio#player');
  if (!p || (typeof started !== 'undefined' && !started)) { if (done) done(true); return; }
  // one reconnection at a time: a second caller waits for the running one
  if (reconnecting) { var w = setInterval(function () { if (!reconnecting) { clearInterval(w); if (done) done(!p.paused); } }, 500); return; }
  reconnecting = true;
  var t0 = Date.now(), finished = false, armed = null;
  var stop = function (ok) {
    if (finished) return; finished = true; clearTimeout(armed); reconnecting = false;
    p.removeEventListener('error', retry); p.removeEventListener('stalled', retry); p.removeEventListener('ended', retry); p.removeEventListener('playing', playing);
    if (done) done(ok);
  };
  var attempt = function () {
    if (finished) return;
    if (Date.now() - t0 > 15000) { stop(false); return; }
    p.pause(); p.setAttribute('src', 'stream?r=' + Date.now()); p.load();
    var pr = p.play(); if (pr && pr.catch) pr.catch(function () { retry(); });
  };
  var retry = function () { if (!finished) { clearTimeout(armed); armed = setTimeout(attempt, 500); } };
  // playing for 3 s means the new stream holds (the old one may still answer for a moment before the restart)
  var playing = function () { wakeAudio(); clearTimeout(armed); armed = setTimeout(function () { stop(true); }, 3000); };
  p.addEventListener('error', retry); p.addEventListener('stalled', retry); p.addEventListener('ended', retry); p.addEventListener('playing', playing);
  armed = setTimeout(attempt, 800);
}

function drawFrequencyAxis() {
  // the scales (owner 2026-11-04, as the review player): the picture is the sound as recorded, its real frequency on the
  // left edge; with the hearing aid on, the strip right of the picture says where each row is heard (heard = real x
  // ratio). They mirror the drawing in process(): FFT bin i is drawn at y = H - i*h with h = H/LEN + 0.9, bin i is
  // i * (sampleRate/2) / LEN Hz
  const CVS = document.body.querySelector('canvas');
  const rect = CVS.getBoundingClientRect();
  const LEN = ANALYSER.frequencyBinCount;
  const h = (CVS.height / LEN + 0.9);
  const nyquist = ACTX.sampleRate / 2;
  const scaleY = rect.height / CVS.height;
  const yOf = function (f) { return (CVS.height - (f / nyquist) * LEN * h) * scaleY; };
  ['freqaxis', 'freqaxis2'].forEach(function (id) { var o = document.getElementById(id); if (o) o.remove(); });
  var hs = window.BS_HEARING || {}, on = !!hs.on && hs.r > 0 && hs.r < 1;
  var strip = function (id, left, width) {
    var a = document.createElement('div');
    a.id = id;
    a.style.cssText = 'position:absolute;pointer-events:none;z-index:5;left:' + left + 'px;top:' + (rect.top + window.scrollY) + 'px;width:' + width + 'px;height:' + rect.height + 'px;font:10px sans-serif;color:#fff;';
    document.body.appendChild(a);
    return a;
  };
  var label = function (box, y, txt, bg, bold) {
    var s = document.createElement('span');
    s.style.cssText = 'position:absolute;left:2px;top:' + (y - 6) + 'px;font-size:9px;white-space:nowrap;text-shadow:0 0 3px #000,0 0 3px #000;background:' + bg + ';padding:0 3px;border-radius:3px;' + (bold ? 'font-weight:700;' : '');
    s.textContent = txt;
    box.appendChild(s);
  };
  // real, on the picture's left edge
  var real = strip('freqaxis', rect.left + window.scrollX, 60);
  for (let f = 2000; f < nyquist; f += 2000) { const y = yOf(f); if (y >= 10 && y <= rect.height - 6) label(real, y, '- ' + (f / 1000) + ' kHz', 'rgba(0,0,0,.6)'); }
  if (!on) return;
  // You hear, in the strip right of the picture
  var heard = strip('freqaxis2', rect.right + window.scrollX + 2, 54);
  label(heard, 8, t('str_you_hear'), 'rgba(217,122,0,.9)', true);
  for (let k = 1000; k / hs.r < nyquist; k += 1000) { const y = yOf(k / hs.r); if (y >= 18 && y <= rect.height - 6) label(heard, y, '- ' + (k / 1000) + ' kHz', 'rgba(217,122,0,.9)'); }
}
// the axes again when the lowering or its ratio changes (only once the sonogram runs)
function hearAxes() { if (typeof ANALYSER !== 'undefined' && ANALYSER && typeof ACTX !== 'undefined' && ACTX) drawFrequencyAxis(); }

function initialize() {
  var lh = document.getElementById('loading-h1'); if (lh && !needTap) lh.remove();
  const CVS = document.body.querySelector('canvas');
  CTX = CVS.getContext('2d');
  // Match the drawing buffer to the CSS-laid-out size so the spectrogram is
  // neither stretched nor taller than the visible area.
  let W = CVS.width = CVS.clientWidth;
  let H = CVS.height = CVS.clientHeight;

  ACTX = new AudioContext();
  ANALYSER = ACTX.createAnalyser();

  ANALYSER.fftSize = 2048;  
  applySensitivity();
  drawFrequencyAxis();
  
  try{
    process();
  } catch(e) {
    console.log(e)
    window.location.reload();
  }



  function process() {
    SOURCE = ACTX.createMediaElementSource(player);
    

    compressor = ACTX.createDynamicsCompressor();
    compressor.threshold.setValueAtTime(-50, ACTX.currentTime);
    compressor.knee.setValueAtTime(40, ACTX.currentTime);
    compressor.ratio.setValueAtTime(12, ACTX.currentTime);
    compressor.attack.setValueAtTime(0, ACTX.currentTime);
    compressor.release.setValueAtTime(0.25, ACTX.currentTime);
    gainNode = ACTX.createGain();
    gainNode.gain = 1;
    // Silent mode (owner 2026-09-17): the analyser keeps drawing while the
    // speakers get nothing — outGain sits between the graph and the output.
    outGain = ACTX.createGain();
    // the hearing aid between the sound and the speaker (static/hearing-audio.js): the analyser draws the real sound
    HEAR = bsHearAudio(ACTX);
    outGain.connect(HEAR.input);
    HEAR.output.connect(ACTX.destination);
    applySilent();
    SOURCE.connect(gainNode);
    gainNode.connect(ANALYSER);
    gainNode.connect(outGain);

    document.getElementById("compression").removeAttribute("disabled");
    if (compressionDefault) { document.getElementById("compression").checked = true; toggleCompression(true); }

    const DATA = new Uint8Array(ANALYSER.frequencyBinCount);
    const LEN = DATA.length;
    let h = (H / LEN + 0.9);
    const x = W - 1;
    CTX.fillStyle = paletteColor(0);
    CTX.fillRect(0, 0, W, H);

    let lastTime = null, acc = 0;
    loop();

    function loop(time) {
      window.requestAnimationFrame(loop);
      if (requestTime && time && time > requestTime && time - requestTime < 250) {
        var fpsval = 1000 / (time - requestTime);
        avgfps = avgfps ? avgfps * 0.98 + fpsval * 0.02 : fpsval;
      }
      requestTime = time;
      // the picture follows a resize (a phone turned, the page laid out again): the drawing buffer takes the new size
      // and keeps what was drawn, instead of stretching
      if (CVS.clientWidth > 0 && CVS.clientHeight > 0 && (CVS.clientWidth !== W || CVS.clientHeight !== H)) {
        var old = document.createElement('canvas'); old.width = W; old.height = H; old.getContext('2d').drawImage(CVS, 0, 0);
        W = CVS.width = CVS.clientWidth; H = CVS.height = CVS.clientHeight; h = H / LEN + 0.9;
        CTX.fillStyle = paletteColor(0); CTX.fillRect(0, 0, W, H);
        CTX.drawImage(old, 0, 0, old.width, old.height, Math.max(0, W - old.width), 0, Math.min(W, old.width), H);
        drawFrequencyAxis();
      }
      // px per second: one per frame on wide screens, slower on narrow ones (MIN_WINDOW_S visible)
      pxPerSec = Math.min(avgfps || 60, W / MIN_WINDOW_S);
      // after a pause of the drawing (hidden tab, busy device) the gap is not filled with one wide block of the
      // current sound: the picture goes on from now
      if (lastTime !== null && time) acc += pxPerSec * Math.min(time - lastTime, 100) / 1000;
      lastTime = time || lastTime;
      let n = Math.floor(acc);
      if (n < 1) return;
      acc -= n;
      n = Math.min(n, W - 1);
      // the picture moves left by copying the canvas onto itself (the image read-back of every frame was slow)
      CTX.drawImage(CVS, n, 0, W - n, H, 0, 0, W - n, H);
      ANALYSER.getByteFrequencyData(DATA);
      const lut = paletteLUT();
      for (let i = 0; i < LEN; i++) {
        CTX.fillStyle = lut[DATA[i]];
        CTX.fillRect(W - n, H - (i * h + h), n, h);
      }
    }
  }
}

</script>
<style>
html, body {
  height: 100%;
}

/* SPECTROGRAM_HEIGHT (BirdSuite.conf, in vh): the live graph takes that share of
   the pane height, leaving room for the topnav and the controls bar above it. */
canvas {
  display: block;
  height: <?php echo $SPECTROGRAM_HEIGHT; ?>vh;
  /* the whole width; with the hearing aid on, a strip on the right for the You hear scale (owner 2026-11-04: over the
     picture it hid the newest sound) */
  width: 100%;
}

html.hearon canvas { width: calc(100% - 56px); }
#spectrogramimage {
  height: <?php echo $SPECTROGRAM_HEIGHT; ?>vh;
}

h1 {
  position: absolute;
  top: 50%;
  left: 50%;
  transform: translate(-50%, -50%);
  margin: 0;
}
  #specopts .specrow { margin: 3px 0; }
  #specopts label { display: inline-block; min-width: 4.5em; }
  /* longer texts (another language) wrap on a phone instead of widening the page (release run 5) */
  #specopts > span, #specopts > label { max-width: 100%; }
  #specopts select { max-width: 100%; }
</style>

<img id="spectrogramimage" style="width:100%;display:none" src="spectrogram.png?nocache=<?php echo $time;?>">

<!-- above the spectrogram: stream choice and Silent on the left, Height on the right (owner 2026-10-08) -->
<div class="centered" style="display:flex;flex-wrap:wrap;gap:4px 12px;justify-content:space-between;align-items:center;padding:2px 8px;font-size:12px;max-width:100%;box-sizing:border-box;">
	<?php
	if (isset($RTSP_Stream_Config) && !empty($RTSP_Stream_Config)) {
		?>
        <div style="display:inline" id="RTSP_streams">
            <label><?php echo t('str_rtsp_stream'); ?> </label>
            <select id="rtsp_stream_select" class="testbtn" name="RTSP Streams">
				<?php
				// a saved stream index beyond the list (the list was edited) shows the first stream; only shown, never written
				// here (a page view changes nothing; Settings › Audio keeps the index in range when it saves)
				$sel_stream = array_key_exists($config['RTSP_STREAM_TO_LIVESTREAM'], $RTSP_Stream_Config) ? $config['RTSP_STREAM_TO_LIVESTREAM'] : 0;
				foreach ($RTSP_Stream_Config as $stream_id => $stream_host) {
					$isSelected = ($sel_stream == $stream_id) ? 'selected="selected"' : '';
					echo "<option value=" . intval($stream_id) . " $isSelected >" . htmlspecialchars($stream_host) . "</option>";
				}

				?>
            </select>
        </div>
        |
		<?php
	}
	?>
  <div style="display:inline" id="silent" >
    <label for="silent_input" title="<?php echo t('str_draw_the_spectrogram_without_help'); ?>"><?php echo t('str_silent'); ?> </label>
    <input name="silent" type="checkbox" id="silent_input">
    <button type="button" class="setdefault" id="silent_default" style="display:none" onclick="specSetDefault('silent')" title="<?php echo t('str_open_the_silent_setting_help'); ?>"><?php echo t('str_set_as_default'); ?></button>
  </div>
  <span id="specheight" style="margin-left:auto;">
    <label for="height_input"><?php echo t('str_height_pct_of_page'); ?> </label>
    <input id="height_input" type="number" min="20" max="100" step="1" style="width:4.5em;" value="<?php echo $SPECTROGRAM_HEIGHT; ?>">
  </span>
</div>

<audio style="display:none" controls="" crossorigin="anonymous" id='player' preload="none"><source id="playersrc" src="stream"></audio>
<h1 id="loading-h1"><?php echo t('str_loading'); ?></h1>
<canvas></canvas>
<!-- below the spectrogram, every control on one line (owner 2026-10-10): Palette, Contrast, Floor, Range, Compression,
     Freq shift -->
<div id="specopts" style="text-align:left;padding:4px 8px;font-size:12px;display:flex;flex-wrap:wrap;align-items:center;gap:6px 16px;">
  <span><label for="palette_select"><?php echo t('str_palette_colon'); ?> </label>
  <select id="palette_select" class="testbtn">
    <?php foreach ($SPECTROGRAM_PALETTES as $key => $label) {
      echo '<option value="' . $key . '"' . ($key == $SPECTROGRAM_PALETTE ? ' selected="selected"' : '') . '>' . $label . '</option>';
    } ?>
  </select></span>
  <span><label for="contrast_input" title="<?php echo t('str_gamma_of_the_colour_ramp_below_help'); ?>"><?php echo t('str_contrast'); ?> </label>
  <input id="contrast_input" type="range" min="0.5" max="2" step="0.1" style="width:90px;vertical-align:middle;" value="<?php echo $SPECTROGRAM_CONTRAST; ?>">
  <span id="contrast_value" style="display:inline-block;width:2.5em;"><?php echo fmt_num($SPECTROGRAM_CONTRAST, 1); ?></span></span>
  <span><label for="floor_input" title="<?php echo t('str_signal_at_or_below_this_level_help'); ?>"><?php echo t('str_floor'); ?> </label>
  <input id="floor_input" type="range" min="-120" max="-40" step="5" style="width:90px;vertical-align:middle;" value="<?php echo $SPECTROGRAM_FLOOR_DB; ?>">
  <span id="floor_value" style="display:inline-block;width:4em;"><?php echo $SPECTROGRAM_FLOOR_DB; ?> dB</span></span>
  <span><label for="range_input" title="<?php echo t('str_width_of_the_colour_scale_above_help'); ?>"><?php echo t('str_range'); ?> </label>
  <input id="range_input" type="range" min="30" max="120" step="5" style="width:90px;vertical-align:middle;" value="<?php echo $SPECTROGRAM_RANGE_DB; ?>">
  <span id="range_value" style="display:inline-block;width:4em;"><?php echo $SPECTROGRAM_RANGE_DB; ?> dB</span></span>
  <span id="comp"><label><?php echo t('str_compression'); ?> </label>
    <input name="compression" type="checkbox" id="compression" disabled>
    <button type="button" class="setdefault" id="compression_default" style="display:none" onclick="specSetDefault('compression')" title="<?php echo t('str_open_the_compression_help'); ?>"><?php echo t('str_set_as_default'); ?></button></span>
  <span id="specopts_status" style="color:#9f9;"></span>
  <button type="button" class="setdefault" id="spec_factory" style="margin-left:auto;order:98;" onclick="specFactory()" title="<?php echo t('str_back_to_the_factory_defaults_help'); ?>">&#8634; <?php echo t('str_defaults'); ?></button>

</div>
<script>
  // the hearing aid switched or set in the header (owner 2026-11-04): the sound and the scales follow at once; the strip
  // of the You hear scale only while it is on
  var hearStrip = function () { document.documentElement.classList.toggle('hearon', !!(window.BS_HEARING || {}).on); };
  hearStrip();
  document.addEventListener('bs:hearing', function () { hearStrip(); if (window.HEAR) HEAR.update(); hearAxes(); });
</script>

<script>
var rtsp_stream_select = document.getElementById("rtsp_stream_select");
if (typeof (rtsp_stream_select) !== 'undefined' && rtsp_stream_select !== null) {
    //When the dropdown selection is changed set the new value is settings, then restart the livestream service so it broadcasts newly selected RTSP stream
    rtsp_stream_select.onchange = function () {
        if (this.value !== 'undefined') {
            // Get the audio player element
            var audio_player = document.querySelector('audio#player');
            var central_controls_element = document.getElementsByClassName('centered')[0];

            //Create the loading header again as a placeholder while we're waiting to reload the stream
            var h1_loading = document.createElement("H1");
            var h1_loading_text = document.createTextNode(t('str_loading'));
            h1_loading.setAttribute("id", "loading-h1");
            h1_loading.setAttribute("style", "font-size:48px; font-weight: bolder; color: #FFF");
            h1_loading.appendChild(h1_loading_text);

            // Create the XMLHttpRequest object.
            const xhr = new XMLHttpRequest();
            // Initialize the request
            // POST (2026-10-23 test run): Advanced takes changes by POST only
            xhr.open("POST", 'views.php?view=Microphone');
            xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
            // Send the request
            xhr.send('rtsp_stream_to_livestream=' + encodeURIComponent(this.value) + '&submit=advanced');
            // Fired once the request completes successfully
            xhr.onload = function (e) {
                // Check if the request was a success
                if (this.readyState === XMLHttpRequest.DONE && this.status === 200) {
                    // Restart the audio player in case it stopped working while the livestream service was restarted
                    if (audio_player !== 'undefined') {
                        central_controls_element.appendChild(h1_loading);
                        //Wait 5 seconds before restarting the stream
                        // reconnect as soon as the stream answers again (liveReconnect), not after a fixed 10 s
                        liveReconnect(function () { var l = document.getElementById('loading-h1'); if (l) l.remove(); });
                    }
                }
            }
        }
    }
}

var silentBox = document.getElementById("silent_input");
silentBox.checked = silentMode;
silentBox.onclick = function() {
  silentMode = this.checked;
  try { window.localStorage.setItem('spectrogram_silent', silentMode ? '1' : '0'); } catch (e) {}
  applySilent();
  specDefaultMark();
}

var compression = document.getElementById("compression");
compression.onclick = function() {
  toggleCompression(this.checked);
  specDefaultMark();
}

// "Set as default" (owner 2026-10-23): one button beside each checkbox, shown while that checkbox differs from the
// station's default (SPECTROGRAM_SILENT / SPECTROGRAM_COMPRESSION); each saves only its own setting
function specDefaultMark() {
  var bs = document.getElementById('silent_default'), bc = document.getElementById('compression_default');
  if (bs) bs.style.display = silentMode !== silentDefault ? '' : 'none';
  if (bc) bc.style.display = (compression.disabled ? compressionDefault : compression.checked) !== compressionDefault ? '' : 'none';
}
function specSetDefault(which) {
  var status = document.getElementById('specopts_status');
  var v = which === 'silent' ? silentMode : compression.checked;
  const xhr = new XMLHttpRequest();
  xhr.open("POST", 'spectrogram.php?save_spectrogram=1&spectrogram_' + which + '=' + (v ? 1 : 0));
  xhr.onload = function () {
    if (this.status === 200) {
      if (which === 'silent') silentDefault = v; else compressionDefault = v;
      specDefaultMark();
      status.textContent = t('str_saved_lc');
      setTimeout(function(){ status.textContent = ''; }, 2000);
    } else {
      status.textContent = t('str_not_saved_login');
    }
  };
  xhr.onerror = function () { status.textContent = t('str_not_saved_lc'); };
  xhr.send();
}
specDefaultMark();


// US-41/US-42: persist through the page's own endpoint (save_spectrogram),
// which writes only the spectrogram keys and restarts only the SoX image
// engine — the audio stream and the analysis are never touched, so the live
// canvas keeps running (owner 2026-09-17: "o rendering atual, mais nada").
// Palette / floor / range / contrast apply live; only the height needs a
// reload (the drawing buffer is sized once at initialize()).
function saveSpectrogramSetting(param, value) {
  var status = document.getElementById('specopts_status');
  status.textContent = t('str_saving_lc_ellipsis');
  const xhr = new XMLHttpRequest();
  xhr.open("POST", 'spectrogram.php?save_spectrogram=1&' + param + '=' + encodeURIComponent(value));
  xhr.onload = function () {
    if (this.status === 200) {
      if (param === 'spectrogram_height') {
        status.textContent = t('str_saved_reloading');
        window.location = "views.php?view=Sonogram";
      } else {
        status.textContent = t('str_saved_lc');
        setTimeout(function(){ status.textContent = ''; }, 2000);
      }
    } else {
      status.textContent = t('str_not_saved_login');
    }
  };
  xhr.onerror = function () { status.textContent = t('str_not_saved_lc'); };
  xhr.send();
}
document.getElementById("palette_select").onchange = function() {
  palette = this.value;   // live preview until the reload
  saveSpectrogramSetting('spectrogram_palette', this.value);
};
document.getElementById("height_input").onchange = function() {
  var v = Math.max(20, Math.min(100, parseInt(this.value) || <?php echo intval($config['SPECTROGRAM_HEIGHT'] ?? 70) ?: 70; ?>));
  this.value = v;
  saveSpectrogramSetting('spectrogram_height', v);
};
// US-42: sensitivity sliders — live preview while dragging ('input'), saved
// on release ('change'); nothing else on the page is touched.
var floorS = document.getElementById("floor_input"), rangeS = document.getElementById("range_input"), contrastS = document.getElementById("contrast_input");
floorS.oninput = function() { specFloor = parseInt(this.value); document.getElementById("floor_value").textContent = specFloor + " dB"; applySensitivity(); };
rangeS.oninput = function() { specRange = parseInt(this.value); document.getElementById("range_value").textContent = specRange + " dB"; applySensitivity(); };
contrastS.oninput = function() { specGamma = parseFloat(this.value); document.getElementById("contrast_value").textContent = window.fmtNum(specGamma, 1); };
floorS.onchange = function() { saveSpectrogramSetting('spectrogram_floor_db', parseInt(this.value)); };
rangeS.onchange = function() { saveSpectrogramSetting('spectrogram_range_db', parseInt(this.value)); };
contrastS.onchange = function() { saveSpectrogramSetting('spectrogram_contrast', parseFloat(this.value)); };
// Defaults (owner 2026-10-27): palette, contrast, floor and range back to the factory values, shown at once and saved
// in one request
var SPEC_FACTORY = <?php echo json_encode($SPECTROGRAM_FACTORY); ?>;
// the Defaults button only while one of these is off its factory value (owner 2026-11-04, the Watch pages)
function specFactoryMark() {
  var f = SPEC_FACTORY, b = document.getElementById('spec_factory');
  if (!b) return;
  var off = document.getElementById('palette_select').value !== String(f.palette) || parseFloat(floorS.value) !== parseFloat(f.floor)
    || parseFloat(rangeS.value) !== parseFloat(f.range) || parseFloat(contrastS.value) !== parseFloat(f.contrast);
  b.style.display = off ? '' : 'none';
}
['palette_select', 'floor_input', 'range_input', 'contrast_input'].forEach(function (id) {
  var e = document.getElementById(id); if (e) { e.addEventListener('input', specFactoryMark); e.addEventListener('change', specFactoryMark); }
});
document.addEventListener('DOMContentLoaded', specFactoryMark);
setTimeout(specFactoryMark, 0);
function specFactory() {
  var f = SPEC_FACTORY, status = document.getElementById('specopts_status');
  palette = f.palette; document.getElementById('palette_select').value = f.palette;
  specFloor = f.floor; floorS.value = f.floor; document.getElementById("floor_value").textContent = f.floor + " dB";
  specRange = f.range; rangeS.value = f.range; document.getElementById("range_value").textContent = f.range + " dB";
  specGamma = f.contrast; contrastS.value = f.contrast; document.getElementById("contrast_value").textContent = window.fmtNum(f.contrast, 1);
  applySensitivity();
  specFactoryMark();
  status.textContent = t('str_saving_lc_ellipsis');
  var xhr = new XMLHttpRequest();
  xhr.open("POST", 'spectrogram.php?save_spectrogram=1&spectrogram_palette=' + encodeURIComponent(f.palette) + '&spectrogram_floor_db=' + f.floor
    + '&spectrogram_range_db=' + f.range + '&spectrogram_contrast=' + f.contrast);
  xhr.onload = function () {
    status.textContent = this.status === 200 ? t('str_saved_lc') : t('str_not_saved_login');
    if (this.status === 200) setTimeout(function(){ status.textContent = ''; }, 2000);
  };
  xhr.onerror = function () { status.textContent = t('str_not_saved_lc'); };
  xhr.send();
}
</script>
