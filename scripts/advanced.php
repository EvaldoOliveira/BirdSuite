<?php
ini_set('display_errors', 0);  // errors to the log, never to the page (2026-10-23 security run)
error_reporting(E_ERROR);

require_once "scripts/common.php";
$home = get_home();
$config = get_config();
$user = get_user();

// Settings › Audio or Settings › Access (views.php sets it; owner 2026-10-25); Access opens without login (it shows no
// secret), Audio does not (RTSP URLs carry camera passwords); a save always asks
$adv_page = (isset($adv_page) && $adv_page === 'access') ? 'access' : 'audio';
if ($adv_page === 'audio' || $_SERVER['REQUEST_METHOD'] === 'POST') ensure_authenticated();

// changes arrive by POST (2026-10-23 test run: sent by GET, the web password landed in the web server log and the
// browser history); the handlers below read $_GET, so the posted fields join it, and a GET keeps only what chooses the
// page (view, the "updated" alert)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $_GET = array_merge((array)$_GET, (array)$_POST);
} else {
  $_GET = array_intersect_key((array)$_GET, array_flip(array('view', 'status')));
}

if (isset($_GET['run_species_count'])) {
   echo "<script>";
   $output = shell_exec(station_prefix() . "".$home."/BirdNET-Pi/scripts/disk_species_count.sh 2>&1");
   $escaped_output = htmlspecialchars($output, ENT_QUOTES | ENT_SUBSTITUTE);
   echo "alert(`$escaped_output`);";
   echo "</script>";
 }

if(isset($_GET['submit'])) {
  $contents = file_get_contents(BS_CONF);
  $restart_livestream = false;
  $update_caddyfile = false;

  // the page never shows the password (2026-10-23 test run): an empty field keeps it, the box removes it
  if(isset($_GET["caddy_pwd_remove"]) && ($config['CADDY_PWD'] ?? '') !== '') {
    $contents = preg_replace("/CADDY_PWD=.*/", "CADDY_PWD=\"\"", $contents);
    $update_caddyfile = true;
  } elseif(isset($_GET["caddy_pwd"]) && $_GET["caddy_pwd"] !== '' && web_password_ok($_GET["caddy_pwd"])) {
    $caddy_pwd = $_GET["caddy_pwd"];
    if(!hash_equals((string)$config['CADDY_PWD'], $caddy_pwd)) {
      $contents = preg_replace("/CADDY_PWD=.*/", "CADDY_PWD=\"$caddy_pwd\"", $contents);
      $update_caddyfile = true;
    }
  }

  // the user name of the web login (owner 2026-10-25), letters and digits; a station without the key gets it here
  if(isset($_GET["caddy_user"]) && preg_match('/^[A-Za-z0-9]+$/', $_GET["caddy_user"]) && $_GET["caddy_user"] !== web_user()) {
    $contents = preg_match('/^CADDY_USER=/m', $contents)
      ? preg_replace('/^CADDY_USER=.*/m', 'CADDY_USER="' . $_GET["caddy_user"] . '"', $contents)
      : preg_replace('/^(CADDY_PWD=.*)$/m', '$1' . "\nCADDY_USER=\"" . $_GET["caddy_user"] . '"', $contents);
    $update_caddyfile = true;
  }

  if(isset($_GET["ice_pwd"])) {
    $ice_pwd = $_GET["ice_pwd"];
    if(strcmp($ice_pwd,$config['ICE_PWD']) !== 0) {
      $contents = preg_replace("/ICE_PWD=.*/", "ICE_PWD=$ice_pwd", $contents);
      $restart_livestream = true;
    }
  }

  if(isset($_GET["birdnetpi_url"])) {
    $birdnetpi_url = $_GET["birdnetpi_url"];
    // remove trailing slash to prevent conf from becoming broken
    $birdnetpi_url = rtrim(trim($birdnetpi_url), '/');
    // BirdSuite.conf is read by bash and the value becomes a Caddy site address: a bare http(s)://host[:port] only
    // (2026-10-23 run 3, destructive phase NEW-SEC-03)
    if ($birdnetpi_url !== '' && !preg_match('#^https?://[A-Za-z0-9.-]+(:[0-9]{1,5})?$#', $birdnetpi_url)) die(t('Not a web address: {url}', array('url' => htmlspecialchars($birdnetpi_url))));
    if(strcmp($birdnetpi_url,$config['BIRDNETPI_URL']) !== 0) {
      $contents = preg_replace("/BIRDNETPI_URL=.*/", "BIRDNETPI_URL=$birdnetpi_url", $contents);
      $update_caddyfile = true;
    }
  }

  if(isset($_GET["rtsp_stream"])) {
    $rtsp_stream = str_replace(array("\r\n", "\n"), ",", trim($_GET["rtsp_stream"]));
    // BirdSuite.conf is read by bash: only stream URLs, no quote, $, backtick, backslash, ; or space (2026-10-23 run 3)
    foreach (array_filter(explode(",", $rtsp_stream), 'strlen') as $u) {
      if (!preg_match('#^(rtsps?|rtmp|https?)://[^\s"\'`$\\\\;]+$#', trim($u))) die(t('Not a stream URL: {url}', array('url' => htmlspecialchars($u))));
    }
    if(strcmp($rtsp_stream,$config['RTSP_STREAM']) !== 0) {
      $contents = preg_replace("/RTSP_STREAM=.*/", "RTSP_STREAM=\"$rtsp_stream\"", $contents);
      $restart_livestream = True;
    }
  }

  if (isset($_GET["rtsp_stream_to_livestream"])) {
    $rtsp_stream_selected = trim($_GET["rtsp_stream_to_livestream"]);
    if (!preg_match('/^[0-9]{0,3}$/', $rtsp_stream_selected)) die(t('Not a stream URL: {url}', array('url' => htmlspecialchars($rtsp_stream_selected))));

    //Setting exists already, see if the value changed
    if (strcmp($rtsp_stream_selected, $config['RTSP_STREAM_TO_LIVESTREAM']) !== 0) {
      $contents = preg_replace("/RTSP_STREAM_TO_LIVESTREAM=.*/", "RTSP_STREAM_TO_LIVESTREAM=\"$rtsp_stream_selected\"", $contents);
      $restart_livestream = True;
    }
  }

  if (isset($_GET["activate_freqshift_in_livestream"])) {
    $activate_freqshift_in_livestream = trim($_GET["activate_freqshift_in_livestream"]);

    //Setting exists already, see if the value changed
    if (strcmp($activate_freqshift_in_livestream, $config['ACTIVATE_FREQSHIFT_IN_LIVESTREAM']) !== 0) {
      $contents = preg_replace("/ACTIVATE_FREQSHIFT_IN_LIVESTREAM=.*/", "ACTIVATE_FREQSHIFT_IN_LIVESTREAM=\"$activate_freqshift_in_livestream\"", $contents);
      $restart_livestream = True;
    }
  }
  
  if(isset($_GET["freqshift_hi"]) && is_numeric($_GET['freqshift_hi'])) {
    $freqshift_hi = $_GET["freqshift_hi"];
    if(strcmp($freqshift_hi,$config['FREQSHIFT_HI']) !== 0) {
      $contents = preg_replace("/FREQSHIFT_HI=.*/", "FREQSHIFT_HI=$freqshift_hi", $contents);
    }
  }

  if(isset($_GET["freqshift_lo"]) && is_numeric($_GET['freqshift_lo'])) {
    $freqshift_lo = $_GET["freqshift_lo"];
    if(strcmp($freqshift_lo,$config['FREQSHIFT_LO']) !== 0) {
      $contents = preg_replace("/FREQSHIFT_LO=.*/", "FREQSHIFT_LO=$freqshift_lo", $contents);
    }
  }

  if(isset($_GET["freqshift_pitch"]) && is_numeric($_GET['freqshift_pitch'])) {
    $freqshift_pitch = $_GET["freqshift_pitch"];
    if(strcmp($freqshift_pitch,$config['FREQSHIFT_PITCH']) !== 0) {
      $contents = preg_replace("/FREQSHIFT_PITCH=.*/", "FREQSHIFT_PITCH=$freqshift_pitch", $contents);
    }
  }

  if(isset($_GET["freqshift_tool"])) {
    $freqshift_tool = $_GET["freqshift_tool"];
    if(strcmp($freqshift_tool,$config['FREQSHIFT_TOOL']) !== 0) {
      $contents = preg_replace("/FREQSHIFT_TOOL=.*/", "FREQSHIFT_TOOL=$freqshift_tool", $contents);
    }
  }

  if(isset($_GET["freqshift_reconnect_delay"]) && is_numeric($_GET['freqshift_reconnect_delay'])) {
    $freqshift_reconnect_delay = $_GET["freqshift_reconnect_delay"];
    if(strcmp($freqshift_reconnect_delay,$config['FREQSHIFT_RECONNECT_DELAY']) !== 0) {
      $contents = preg_replace("/FREQSHIFT_RECONNECT_DELAY=.*/", "FREQSHIFT_RECONNECT_DELAY=$freqshift_reconnect_delay", $contents);
    }
  }

  if(isset($_GET["spectrogram_height"]) && is_numeric($_GET['spectrogram_height'])) {
    $spectrogram_height = max(20, min(100, intval($_GET['spectrogram_height'])));
    if(strcmp($spectrogram_height,($config['SPECTROGRAM_HEIGHT'] ?? '')) !== 0) {
      if(preg_match("/^SPECTROGRAM_HEIGHT=/m", $contents)) {
        $contents = preg_replace("/SPECTROGRAM_HEIGHT=.*/", "SPECTROGRAM_HEIGHT=$spectrogram_height", $contents);
      } else {
        // Config written before this setting existed - append the new key
        $contents .= "\n## SPECTROGRAM_HEIGHT is the height of the live spectrogram in percent of the page height (vh)\nSPECTROGRAM_HEIGHT=$spectrogram_height\n";
      }
    }
  }

  // US-41: colour palette of the live spectrogram and of the SoX images
  if(isset($_GET["spectrogram_palette"])) {
    $spectrogram_palette = trim($_GET['spectrogram_palette']);
    if(in_array($spectrogram_palette, array('turbo','inferno','roseus','soxheat','birdnet','viridis','ocean','grayscale'), true)
       && strcmp($spectrogram_palette,($config['SPECTROGRAM_PALETTE'] ?? '')) !== 0) {
      if(preg_match("/^SPECTROGRAM_PALETTE=/m", $contents)) {
        $contents = preg_replace("/SPECTROGRAM_PALETTE=.*/", "SPECTROGRAM_PALETTE=$spectrogram_palette", $contents);
      } else {
        $contents .= "\n## SPECTROGRAM_PALETTE is the colour palette of the spectrograms: birdnet, viridis, inferno, ocean, grayscale, soxheat, turbo, roseus\nSPECTROGRAM_PALETTE=$spectrogram_palette\n";
      }
      // the static SoX image follows the palette on its next refresh
      exec("sudo -n /usr/local/sbin/birdsuite-svc restart spectrogram_viewer.service");
    }
  }

  // US-42: colour sensitivity (floor / range / contrast) — same append-if-missing save
  foreach (array('spectrogram_floor_db' => array('SPECTROGRAM_FLOOR_DB', -120, -40, 'int', 'dB floor of the spectrogram colour scale'),
                 'spectrogram_range_db' => array('SPECTROGRAM_RANGE_DB', 30, 120, 'int', 'dB range of the spectrogram colour scale above the floor'),
                 'spectrogram_contrast' => array('SPECTROGRAM_CONTRAST', 0.5, 2.0, 'float', 'contrast (gamma) of the spectrogram colour ramp; 1 = linear')) as $param => $spec) {
    if(isset($_GET[$param]) && is_numeric($_GET[$param])) {
      list($key, $lo, $hi, $type, $desc) = $spec;
      $val = $type == 'int' ? max($lo, min($hi, intval($_GET[$param]))) : max($lo, min($hi, round(floatval($_GET[$param]), 2)));
      if(strcmp((string)$val, (string)($config[$key] ?? '')) !== 0) {
        if(preg_match("/^$key=/m", $contents)) {
          $contents = preg_replace("/$key=.*/", "$key=$val", $contents);
        } else {
          $contents .= "\n## $key is the $desc\n$key=$val\n";
        }
        exec("sudo -n /usr/local/sbin/birdsuite-svc restart spectrogram_viewer.service");
      }
    }
  }

  if(isset($_GET["full_disk"])) {
    $full_disk = $_GET["full_disk"];
    if(strcmp($full_disk,$config['FULL_DISK']) !== 0) {
      $contents = preg_replace("/FULL_DISK=.*/", "FULL_DISK=$full_disk", $contents);
    }
  }

  if (isset($_GET["purge_threshold"])) {
    $purge_threshold = $_GET["purge_threshold"];
    if (strcmp($purge_threshold, $config['PURGE_THRESHOLD']) !== 0) {
        $contents = preg_replace("/PURGE_THRESHOLD=.*/", "PURGE_THRESHOLD=$purge_threshold", $contents);
    }
}

if (isset($_GET["purge_protect_top_n"])) {
    $purge_protect_top_n = max(1, min(100, intval($_GET["purge_protect_top_n"])));
    if (preg_match("/^PURGE_PROTECT_TOP_N=/m", $contents)) {
        $contents = preg_replace("/^PURGE_PROTECT_TOP_N=.*/m", "PURGE_PROTECT_TOP_N=$purge_protect_top_n", $contents);
    } else {
        $contents .= "\n## PURGE_PROTECT_TOP_N: the best N detections of every species are never purged (plus confirmed reviews)\nPURGE_PROTECT_TOP_N=$purge_protect_top_n\n";
    }
}
// the audio of the Ignored answers deleted by itself after N days (owner 2026-10-24; purge_stage.py, nightly)
if (isset($_GET["ignored_purge_days"])) {
    $ignored_purge_days = max(0, min(3650, intval($_GET["ignored_purge_days"])));
    if (preg_match("/^IGNORED_PURGE_DAYS=/m", $contents)) {
        $contents = preg_replace("/^IGNORED_PURGE_DAYS=.*/m", "IGNORED_PURGE_DAYS=$ignored_purge_days", $contents);
    } else {
        $contents .= "\nIGNORED_PURGE_DAYS=$ignored_purge_days\n";
    }
}
// the recent window and the daily cap per species (owner 2026-10-24; disk_species_clean.sh, daily_cap.py)
foreach (array('purge_recent_days' => array('PURGE_RECENT_DAYS', 0, 365), 'max_files_species_day' => array('MAX_FILES_SPECIES_DAY', 0, 100000)) as $param => $k) {
    if (!isset($_GET[$param])) continue;
    $v = max($k[1], min($k[2], intval($_GET[$param])));
    if (preg_match("/^{$k[0]}=/m", $contents)) $contents = preg_replace("/^{$k[0]}=.*/m", "{$k[0]}=$v", $contents);
    else $contents .= "\n{$k[0]}=$v\n";
}
if (isset($_GET["max_files_species"])) {
    $max_files_species = $_GET["max_files_species"];
    if (strcmp($max_files_species, $config['MAX_FILES_SPECIES']) !== 0) {
        $contents = preg_replace("/MAX_FILES_SPECIES=.*/", "MAX_FILES_SPECIES=$max_files_species", $contents);
    }
}
	
  if(isset($_GET["privacy_threshold"])) {
    $privacy_threshold = $_GET["privacy_threshold"];
    if(strcmp($privacy_threshold,$config['PRIVACY_THRESHOLD']) !== 0) {
      $contents = preg_replace("/PRIVACY_THRESHOLD=.*/", "PRIVACY_THRESHOLD=$privacy_threshold", $contents);
    }
  }

  if(isset($_GET["rec_card"])) {
    $rec_card = $_GET["rec_card"];
    if(strcmp($rec_card,$config['REC_CARD']) !== 0) {
      $contents = preg_replace("/REC_CARD=.*/", "REC_CARD=\"$rec_card\"", $contents);
    }
  }

  if(isset($_GET["channels"])) {
    $channels = $_GET["channels"];
    if(strcmp($channels,$config['CHANNELS']) !== 0) {
      $contents = preg_replace("/CHANNELS=.*/", "CHANNELS=$channels", $contents);
    }
  }

  if(isset($_GET["recording_length"])) {
    $recording_length = $_GET["recording_length"];
    if(strcmp($recording_length,$config['RECORDING_LENGTH']) !== 0) {
      $contents = preg_replace("/RECORDING_LENGTH=.*/", "RECORDING_LENGTH=$recording_length", $contents);
    }
  }

  if(isset($_GET["extraction_length"])) {
    $extraction_length = $_GET["extraction_length"];
    if(strcmp($extraction_length,$config['EXTRACTION_LENGTH']) !== 0) {
      $contents = preg_replace("/EXTRACTION_LENGTH=.*/", "EXTRACTION_LENGTH=$extraction_length", $contents);
    }
  }

  if(isset($_GET["audiofmt"])) {
    $audiofmt = $_GET["audiofmt"];
    if(strcmp($audiofmt,$config['AUDIOFMT']) !== 0) {
      $contents = preg_replace("/AUDIOFMT=.*/", "AUDIOFMT=$audiofmt", $contents);
    }
  }
  if(isset($_GET["silence_update_indicator"])) {
    $silence_update_indicator = 1;
    if(strcmp($silence_update_indicator,$config['SILENCE_UPDATE_INDICATOR']) !== 0) {
      $contents = preg_replace("/SILENCE_UPDATE_INDICATOR=.*/", "SILENCE_UPDATE_INDICATOR=$silence_update_indicator", $contents);
    }
  }

  if(isset($_GET["automatic_update"])) {
    $automatic_update = 1;
    if(strcmp($automatic_update,$config['AUTOMATIC_UPDATE']) !== 0) {
      $contents = preg_replace("/AUTOMATIC_UPDATE=.*/", "AUTOMATIC_UPDATE=$automatic_update", $contents);
    }
  }

  if(isset($_GET["raw_spectrogram"])) {
    $raw_spectrogram = 1;
    if(strcmp($raw_spectrogram,$config['RAW_SPECTROGRAM']) !== 0) {
      $contents = preg_replace("/RAW_SPECTROGRAM=.*/", "RAW_SPECTROGRAM=$raw_spectrogram", $contents);
    }
  }

  if(isset($_GET["rare_species_threshold"])) {
    $rare_species_threshold = $_GET["rare_species_threshold"];
    if(strcmp($rare_species_threshold, $config['RARE_SPECIES_THRESHOLD']) !== 0) {
        $contents = preg_replace("/RARE_SPECIES_THRESHOLD=.*/", "RARE_SPECIES_THRESHOLD=$rare_species_threshold", $contents);
    }
  }

  if(isset($_GET["custom_image"])) {
    $custom_image = trim($_GET["custom_image"]);
    // an image file of the station user's home only, with a plain path (bash reads the value; the Latest page shows the
    // file to anyone: 2026-10-23 NEW-SEC-03 / NEW-SEC-05)
    if ($custom_image !== '' && !custom_image_ok($custom_image)) die(t('Not an image of the station: {path}', array('path' => htmlspecialchars($custom_image))));
    if(strcmp($custom_image,$config['CUSTOM_IMAGE']) !== 0) {
      $contents = preg_replace("/CUSTOM_IMAGE=.*/", "CUSTOM_IMAGE=$custom_image", $contents);
    }
  }

  if(isset($_GET["custom_image_label"])) {
    $custom_image_label = mb_substr(str_replace(array('"', '$', '`', '\\', "\r", "\n"), '', $_GET["custom_image_label"]), 0, 80);
    if(strcmp($custom_image_label,$config['CUSTOM_IMAGE_TITLE']) !== 0) {
      $contents = preg_replace("/CUSTOM_IMAGE_TITLE=.*/", "CUSTOM_IMAGE_TITLE=\"$custom_image_label\"", $contents);
    }
  }

  if (isset($_GET["LogLevel_BirdnetRecordingService"])) {
    $birdnet_recording_service_log_level = trim($_GET["LogLevel_BirdnetRecordingService"]);
    if (!in_array($birdnet_recording_service_log_level, array('error', 'warning', 'info', 'debug'), true)) $birdnet_recording_service_log_level = 'error';
	if (strcmp($birdnet_recording_service_log_level, $config['LogLevel_BirdnetRecordingService']) !== 0) {
		$contents = preg_replace("/LogLevel_BirdnetRecordingService=.*/", "LogLevel_BirdnetRecordingService=\"$birdnet_recording_service_log_level\"", $contents);
	}
  }
  if (isset($_GET["LogLevel_SpectrogramViewerService"])) {
    $spectrogram_viewer_service_log_level = trim($_GET["LogLevel_SpectrogramViewerService"]);
    if (!in_array($spectrogram_viewer_service_log_level, array('error', 'warning', 'info', 'debug'), true)) $spectrogram_viewer_service_log_level = 'error';
	if (strcmp($spectrogram_viewer_service_log_level, $config['LogLevel_SpectrogramViewerService']) !== 0) {
		$contents = preg_replace("/LogLevel_SpectrogramViewerService=.*/", "LogLevel_SpectrogramViewerService=\"$spectrogram_viewer_service_log_level\"", $contents);
	}
  }
  if (isset($_GET["LogLevel_LiveAudioStreamService"])) {
    $livestream_audio_service_log_level = trim($_GET["LogLevel_LiveAudioStreamService"]);
    if (!in_array($livestream_audio_service_log_level, array('error', 'warning', 'info', 'debug'), true)) $livestream_audio_service_log_level = 'error';
	if (strcmp($livestream_audio_service_log_level, $config['LogLevel_LiveAudioStreamService']) !== 0) {
		$contents = preg_replace("/LogLevel_LiveAudioStreamService=.*/", "LogLevel_LiveAudioStreamService=\"$livestream_audio_service_log_level\"", $contents);
		$restart_livestream = True;
	}
  }

  //Finally write the data out. some sections do this themselves in order to have the new settings ready for the services that will be restarted
  //but will doubly ensure the settings are saved after any modification
  $fh = fopen(BS_CONF, "w");
  fwrite($fh, $contents);
  $config = get_config($force_reload=true);

  syslog(LOG_INFO, "Restarting Services");
  if ($update_caddyfile){
      exec("sudo -n /usr/local/sbin/birdsuite-root caddyfile > /dev/null 2>&1 &");
  }
  shell_exec(station_prefix() . "restart_services.sh");
  if ($restart_livestream) {
    exec("sudo -n /usr/local/sbin/birdsuite-svc restart livestream.service");
  }
  // the saved form opens again by GET with the "updated" alert (post/redirect/get: a reload does not send it twice);
  // the menu is already printed, so the browser is sent there by script
  echo '<script>location.replace("views.php?view=' . ($adv_page === 'audio' ? 'Audio' : 'Access') . '&status=success");</script>';
  die();
}

$count = 6000;
?>
<meta name="viewport" content="width=device-width, initial-scale=1">
<style>
  </style>
  </head>
<div class="settings">

<?php
$newconfig = get_config();
?>
    <form id="advancedform" action="" method="POST">
      <?php // Settings › Advanced split (owner 2026-10-25): Settings › Audio = the audio input and the live audio pitch shift,
      // Settings › Access = the login and the address; Privacy Threshold in Settings › Model, the update options in
      // System › Status, Minimalist on the Spectrogram page, the Latest page image and rare species in Settings › Themes,
      // the log levels on System › Detection Log ?>
      <?php if ($adv_page === 'audio') { ?>
      <table class="settingstable"><tr><td>
      <?php // no heading of its own: the page title Audio is above (owner 2026-10-25) ?>
      <label for="rec_card"><?php echo t('Audio Card:'); ?> </label>
      <input name="rec_card" type="text" size="12" value="<?php print($newconfig['REC_CARD']);?>" required/><br>
      <?php echo t('Set Audio Card to \'default\' to use PulseAudio (always recommended), or an ALSA recognized sound card device from the output of `arecord -L`.'); ?> <?php echo t('Choose the `dsnoop` device if it is available.'); ?><br><br>
      <label for="channels"><?php echo t('Audio Channels:'); ?> </label>
      <input name="channels" type="number" style="width:3em;" min="1" max="32" step="1" value="<?php print($newconfig['CHANNELS']);?>" required/><br>
      <?php echo t('Set Channels to the number of channels supported by your sound card.'); ?> <?php echo t('32 max.'); ?><br><br>
      <label for="recording_length"><?php echo t('Recording Length:'); ?> </label>
      <input name="recording_length" oninput="document.getElementsByName('extraction_length')[0].setAttribute('max', this.value);" type="number" style="width:3em;" min="3" max="60" step="1" value="<?php print($newconfig['RECORDING_LENGTH']);?>" required/><br>
      <?php echo t('Set Recording Length in seconds between 3 and 60.'); ?> <?php echo t('Multiples of 3 are recommended, as BirdNET analyzes in 3-second chunks.'); ?><br><br> 
      <label for="extraction_length"><?php echo t('Extraction Length:'); ?> </label>
      <input name="extraction_length" oninput="this.setAttribute('max', document.getElementsByName('recording_length')[0].value);" type="number" style="width:3em;" min="3" value="<?php print($newconfig['EXTRACTION_LENGTH']);?>" /><br>
      <?php echo t('Set Extraction Length to something less than your Recording Length.'); ?> <?php echo t('Min=3 Max=Recording Length'); ?><br><br>
      <label for="audiofmt"><?php echo t('Extractions Audio Format'); ?></label>
      <select name="audiofmt" class="testbtn">
      <option selected="<?php print($newconfig['AUDIOFMT']);?>"><?php print($newconfig['AUDIOFMT']);?></option>
<?php
  $formats = array("8svx", "aif", "aifc", "aiff", "aiffc", "al", "amb", "amr-nb", "amr-wb", "anb", "au", "avr", "awb", "caf", "cdda", "cdr", "cvs", "cvsd", "cvu", "dat", "dvms", "f32", "f4", "f64", "f8", "fap", "flac", "fssd", "gsm", "gsrt", "hcom", "htk", "ima", "ircam", "la", "lpc", "lpc10", "lu", "mat", "mat4", "mat5", "maud", "mp2", "mp3", "nist", "ogg", "paf", "prc", "pvf", "raw", "s1", "s16", "s2", "s24", "s3", "s32", "s4", "s8", "sb", "sd2", "sds", "sf", "sl", "sln", "smp", "snd", "sndfile", "sndr", "sndt", "sou", "sox", "sph", "sw", "txw", "u1", "u16", "u2", "u24", "u3", "u32", "u4", "u8", "ub", "ul", "uw", "vms", "voc", "vorbis", "vox", "w64", "wav", "wavpcm", "wv", "wve", "xa", "xi");
foreach($formats as $format){
  echo "<option value='$format'>$format</option>";
}
?>
      </select>
      <br><br>
      
      <label id="rtsp_stream_input_label" for="rtsp_stream_url_0"><?php echo t('RTSP Stream:'); ?> </label>
      <br>
      <input style="display: none;" name="rtsp_stream" type="url" value="">
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
                <input id="rtsp_stream_url_0" name="rtsp_stream_0" type="url" size="60" value="<?php echo $stream_url; ?>">
                <br>
				<?php
			} else {
                //For every other input field, change the id to reflect the URL's index in the array
				?>
                <input id="rtsp_stream_url_<?php echo $stream_idx; ?>" name="rtsp_stream_<?php echo $stream_idx; ?>" type="url" size="60"
                       value="<?php echo $stream_url; ?>">
                <br>
				<?php
			}
		}
        ?>
      <div id="newrtspstream_button_container">
        <br>
        <span id="newrtspstream" onclick="addNewrtspInput();"><?php echo t('Add'); ?></span><br>
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
      <p><?php echo t('If you place an RTSP stream URL here, BirdSuite will use that as its audio source.'); ?><br><?php echo t('Multiple streams are allowed but may have a impact on rPi performance.'); ?><br><?php echo t('Analyze ffmpeg CPU/Memory usage with <b>top</b> or <b>htop</b> if necessary.'); ?><br><?php echo t('To remove all and use the soundcard again, just delete the RTSP entries and click Save at the bottom.'); ?></p>
      </td></tr></table><br>
      <?php } else { ?>
      <table class="settingstable"><tr><td>
      <h2><?php echo t('BirdSuite Password'); ?></h2>
      <p><?php echo t('The login protects the pages of the Advanced menu (the Tools, Model, Audio, Locations, Defaults, Stations, Notifications › Content, Repositories, the Detection Log), the live audio and every change; the pages of the Simple menu open without it.'); ?></p>
      <p><?php echo t('Leave the password empty to keep the current one; tick Remove the password to open the station without login.'); ?></p>
      <p><?php echo t('Accepted characters:'); ?> <code>A-Z a-z 0-9 ! @ # % ^ &amp; * ( ) - _ = + [ ] { } . , : ? ~ /</code><br><?php echo t('Not accepted: spaces, quotes, ` $ \\ ; &lt; &gt; |'); ?></p>
      <?php // the same pattern as Network (owner 2026-10-25): the login in use with Show password, then the fields in two
      // columns ?>
      <style>
        .acc .knownnet { margin: 6px 0 10px; } .acc .kn, .acc .kp { display: flex; flex-wrap: wrap; gap: 6px; align-items: center; } .acc .kp { margin: 4px 0 0 22px; }
        .acc .netform { display: grid; grid-template-columns: max-content minmax(0, 1fr); gap: 8px 10px; align-items: center; margin: 8px 0 4px; max-width: 32em; }
        .acc .netform input { width: 100%; box-sizing: border-box; }
        .acc .netbtn { width: auto; padding: 1px 9px; font-size: 12px; border-radius: 12px; border: 1px solid var(--accent,#2b5e22); background: #fff; color: var(--accent,#2b5e22); cursor: pointer; }
      </style>
      <div class="acc">
      <?php $has_pwd = ($newconfig['CADDY_PWD'] ?? '') !== ''; ?>
      <div class="knownnet"><div class="kn">&#128274; <b><?php echo htmlspecialchars(web_user()); ?></b> <?php echo $has_pwd ? '' : '<b style="color:#c62828">' . t('no password') . '</b>'; ?></div>
        <?php if ($has_pwd) { ?><div class="kp"><?php echo t('Password'); ?>: <span id="accpsk">••••••••</span> <button type="button" class="netbtn" id="accpskbtn" onclick="accShowPsk()"><?php echo t('Show password'); ?></button></div><?php } ?></div>
      <div class="netform"><label for="caddy_user"><?php echo t('User name'); ?></label>
        <input name="caddy_user" id="caddy_user" type="text" pattern="[A-Za-z0-9]+" required value="<?php echo htmlspecialchars(web_user(), ENT_QUOTES); ?>" autocomplete="username" />
        <label for="caddy_pwd"><?php echo t('New password'); ?></label>
        <input name="caddy_pwd" id="caddy_pwd" type="password" pattern="<?php echo htmlspecialchars(web_password_pattern(), ENT_QUOTES); ?>" title="<?php echo htmlspecialchars(t('Letters, digits and ! @ # % ^ & * ( ) - _ = + [ ] { } . , : ? ~ /'), ENT_QUOTES); ?>" value="" autocomplete="new-password" placeholder="<?php echo htmlspecialchars($has_pwd ? t('empty = keep it') : t('no password'), ENT_QUOTES); ?>" /></div>
      <?php if ($has_pwd) { ?><label><input type="checkbox" name="caddy_pwd_remove" value="1"> <?php echo t('Remove the password (the station opens without login)'); ?></label><br><?php } ?>
      </div>
      <script>
      // the password in use, shown on a click and hidden on the next (as Network)
      function accShowPsk() {
        var el = document.getElementById('accpsk'), btn = document.getElementById('accpskbtn');
        if (el.dataset.shown) { el.textContent = '••••••••'; delete el.dataset.shown; btn.textContent = t('Show password'); return; }
        fetch('scripts/web_login.php', {method: 'POST', credentials: 'same-origin'}).then(function (r) { return r.status === 401 ? {psk: null} : r.json(); })
          .then(function (r) { el.textContent = r.psk === null ? t('Log in first.') : (r.psk || t('(not set)')); if (r.psk !== null) { el.dataset.shown = '1'; btn.textContent = t('Hide password'); } });
      }
      </script>
      </td></tr></table><br>
      <table class="settingstable"><tr><td>
      <h2><?php echo t('Custom URL'); ?></h2>
      <p><?php echo t('When you update the URL below, the web server will reload, so be sure to wait at least 30 seconds and then go to your new URL.'); ?></p>
      <label for="birdnetpi_url"><?php echo t('BirdSuite URL:'); ?> </label>
      <input style="width:40ch;" name="birdnetpi_url" type="url" value="<?php print($newconfig['BIRDNETPI_URL']);?>" /><br>
      <?php // what is in use (owner 2026-10-25): the saved URL, else every address the station answers on
      $addrs = array(strtolower(gethostname()) . '.local');
      foreach (preg_split('/\s+/', trim((string)shell_exec('hostname -I 2>/dev/null'))) as $a) if (filter_var($a, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) $addrs[] = $a;
      $sch = station_scheme();  // http unless HTTPS_ALWAYS (US-126)
      $links = implode(', ', array_map(function ($a) use ($sch) { return '<a href="' . $sch . '://' . htmlspecialchars($a) . '/">' . $sch . '://' . htmlspecialchars($a) . '</a>'; }, $addrs)); ?>
      <p><?php echo ($newconfig['BIRDNETPI_URL'] ?? '') !== ''
        ? t('In use: {url}', array('url' => '<b>' . htmlspecialchars($newconfig['BIRDNETPI_URL']) . '</b>')) . '<br>' . t('The station also answers on: {list}', array('list' => $links))
        : t('In use: none, the station answers on every address: {list}', array('list' => $links)); ?>
        <br><?php echo t('Opened now as: {host}', array('host' => '<b>' . htmlspecialchars(($_SERVER['HTTPS'] ?? '') !== '' ? 'https://' : 'http://') . htmlspecialchars((string)($_SERVER['HTTP_HOST'] ?? '')) . '</b>')); ?></p>
      <p><?php echo t('The BirdSuite URL is how the main page will be reached.'); ?> <?php echo t('If you want your installation to respond to an IP address, place that here, but be sure to indicate "<i>http://</i>".'); ?><br><?php echo t('Example for IP:'); ?> <i>http://192.168.0.109</i><br><?php echo t('Example if you own your own domain:'); ?> <i>https://virginia.birdnetpi.com</i></p>
      </td></tr></table><br>
		
      <?php } ?>
      <?php if ($adv_page === 'audio') { ?>
      <table class="settingstable"><tr><td>
      <h2><?php echo t('Accessibility Settings'); ?></h2>

      <p><?php echo t('Birdsongs Frequency shifting configuration:'); ?><br>
        <?php echo t('This can be useful for hearing impaired people.'); ?> <br><?php echo t('Note: audio files will only be pitch shifted when the "FREQ SHIFT" button is manually clicked for a detection on the "Detections by..." page.'); ?> <br><?php echo t('The frequency shifting can also be activated for the realtime audio livestream, accessible in the SPECTROGRAM tab of BirdSuite.'); ?> <?php echo t('Once it has been activated, it will be made available for the Live Audio feature as well.'); ?><br><?php echo t('Livestream is using ffmpeg for streaming its audio data, so the pitch shifter in that case will use this tool too.'); ?> <?php echo t('If you choose sox as the tool for freq shifting recorded audio files, then you must configure both sox and ffmpeg parameters: sox for recordings, and ffmpeg for livestream.'); ?><br>

        <p style="margin-left: 40px">

      <label for="freqshift_tool"><?php echo t('Shifting tool:'); ?> </label>
      <select name="freqshift_tool" class="testbtn">
            <option selected="<?php print($newconfig['FREQSHIFT_TOOL']);?>"><?php print($newconfig['FREQSHIFT_TOOL']);?></option>
      <?php
        $formats = array("sox","ffmpeg");

        $formats = array_diff($formats, array($newconfig['FREQSHIFT_TOOL']));
      foreach($formats as $format){
        echo "<option value='$format'>$format</option>";
      }
      ?>
      </select>

        <?php echo t('Choose the shifting tool here.'); ?><br>
        </p>

        <p style="margin-left: 40px">
        <?php echo t('Using ffmpeg:'); ?>
        <?php echo t('e.g. origin=6000, target=4000, performs a shift of 2000 Hz down.'); ?><br>
        <label for="freqshift_hi"><?php echo t('Origin [Hz]:'); ?> </label>
        <input name="freqshift_hi" type="number" style="width:5em;" min="0" max="20000" step="1" value="<?php print($newconfig['FREQSHIFT_HI']);?>" required/><br>
        <label for="freqshift_lo"><?php echo t('Target [Hz]:'); ?> </label>
        <input name="freqshift_lo" type="number" style="width:5em;" min="0" max="20000" step="1" value="<?php print($newconfig['FREQSHIFT_LO']);?>" required/>
        </p>
        <p style="margin-left: 40px">
        <label for="freqshift_reconnect_delay"><?php echo t('Livestream reconnection delay (in ms):'); ?> </label>
        <input name="freqshift_reconnect_delay" type="number" style="width:5em;" min="1000" max="10000" step="100" value="<?php print($newconfig['FREQSHIFT_RECONNECT_DELAY']);?>" required/>
        </p>
        <!-- Spectrogram height and palette moved to Basic Settings > "Spectrogram and colours" (owner 2026-09-17); the GET handlers above still serve the Spectrogram page -->
        <p style="margin-left: 40px">
        <?php echo t('Using sox:'); ?>
        <?php echo t('e.g. shiftPitch=-1200 performs a shift of 1 octave down.'); ?> <?php echo t('This value is in 100ths of a semitone.'); ?><br>
        <label for="freqshift_pitch"><?php echo t('Pitch shift:'); ?> </label>
        <input name="freqshift_pitch" type="number" style="width:6em;" min="-4000" max="4000" step="1" value="<?php print($newconfig['FREQSHIFT_PITCH']);?>" required/><br>
        </p>
		</td></tr></table><br>

      <?php } ?>
      <br><br>
      <input type="hidden" name="view" value="<?php echo $adv_page === 'audio' ? 'Audio' : 'Access'; ?>">
      <input type="hidden" name="advanced_form" value="1">
<div class="float">
      <button type="submit" id="advancedformsubmit" onclick="if (window.collectrtspUrls) collectrtspUrls(); if(document.getElementById('advancedform').checkValidity()){this.innerHTML = t('Updating... please wait.');this.classList.add('disabled')}" name="submit" value="advanced">
<?php
if(isset($_GET['status'])){
  echo '<script>alert(' . json_encode(t('Settings successfully updated'), JSON_HEX_TAG | JSON_UNESCAPED_UNICODE) . ');</script>';
}
echo t('Update Settings');
?>
      </button></div>
      </form>
</div>
