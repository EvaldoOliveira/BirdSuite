<?php
/* Tools › Backup/Restore, the settings and the danger zone (owner 2026-10-24). Settings: Backup settings downloads
 * config/ without the database (scripts/backup.php?mode=settings), Restore settings uploads a settings archive
 * (scripts/restore.php, restore.log shown after; an older archive with the database or the audio is taken as well), and
 * the settings kept by Factory settings are offered for download. Danger zone: Clear ALL data (the sounds and the
 * database; the settings stay), Delete a location's detections (to the Removed folder, restorable) and Factory settings
 * (a settings backup kept first). Included by location_data.php; the commands go through views.php?submit= and its
 * allow list, as before. */
$sb_home = get_home();
$sb_restore = "cat $sb_home/BirdSongs/restore.log";
$sb_max_upload = floor(disk_free_space("$sb_home/BirdNET-Pi/") / 1.001);
$sb_saved = array_reverse(array_filter(array_map('trim', explode("\n", (string)shell_exec(station_prefix() . 'ls -1 ' . escapeshellarg("$sb_home/BirdSuite-backups") . ' 2>/dev/null'))),
  function ($f) { return (bool)preg_match('/^settings-[0-9T-]+\.tar$/', $f); }));
$sb_h = function ($s) { return htmlspecialchars((string)$s, ENT_QUOTES); };
?>
<?php if ($ld_mode === 'backup') { ?>
  <table class="settingstable"><tr><td>
  <h2><?php echo t('Settings (BirdSuite)'); ?></h2>
    <?php // one line each, in a plain block (.row is a flex line: it cut the text and the link into pieces); owner 2026-10-27:
          // the station list has its own sync, station by station ?>
    <div class="small" style="margin:6px 0">
      <div><?php echo t('Settings, species lists, notifications, locations and themes, without the detections (those go by Export / Backup).'); ?></div>
      <div><?php echo t('The list of stations can also be synced with another station in:'); ?></div>
      <div><a href="views.php?view=Stations"><?php echo t('Settings') . ' › ' . t('Stations') . ' (' . t('Sync with a station') . ')'; ?></a></div>
    </div>
    <div class="row" id="sbcontainer">
      <?php // links styled as the buttons, no button inside a link (2026-10-23 run 3: the nested button hid the link's target) ?>
      <a href="scripts/backup.php?mode=settings" download class="ldbtn" role="button"><?php echo t('Backup settings'); ?></a>
      <button type="button" class="ldbtn" id="pickfile"><?php echo t('Restore settings'); ?></button>
    </div>
    <?php if ($sb_saved) { ?>
      <div class="row small"><?php echo t('Kept on the station (saved before Factory settings):'); ?>
        <?php foreach (array_slice($sb_saved, 0, 5) as $f) echo '<a href="scripts/backup.php?saved=' . rawurlencode($f) . '" download>' . $sb_h($f) . '</a> '; ?></div>
    <?php } ?>
    <pre id="console" style="margin:0"></pre>
  </td></tr></table><br>
<?php } else { ?>
  <table class="settingstable"><tr><td>
  <h2 style="color:#c62828"><?php echo t('Danger zone'); ?></h2>
    <form action="views.php" method="GET" class="row" style="margin:0">
      <button type="button" class="ldbtn del" data-cmd="clear_all_data.sh" data-what="<?php echo $sb_h(t('Clear ALL data')); ?>" onclick="confirm(<?php echo $sb_h(json_encode(t('Clear ALL data? Every recording and detection of this station is deleted; the settings stay. This cannot be undone and takes up to 90 seconds.'))); ?>) && dzRun(this)"><?php echo t('Clear ALL data'); ?></button>
      <span class="small"><?php echo t('Deletes every recording and the database (detections, reviews, removed ones); the settings stay.'); ?></span>
    </form>
    <form action="views.php" method="GET" class="row" style="margin:0">
      <button type="button" class="ldbtn del" data-cmd="factory_settings.sh" data-what="<?php echo $sb_h(t('Factory settings')); ?>" onclick="confirm(<?php echo $sb_h(json_encode(t('Back to the factory settings? The current settings are saved first (offered above for download); the detections, recordings, locations, species lists and notifications stay.'))); ?>) && dzRun(this)"><?php echo t('Factory settings'); ?></button>
      <span class="small"><?php echo t('Saves the settings, then sets every setting to its factory default (name, coordinates, passwords, network and language stay).'); ?></span>
    </form>
  </td></tr></table><br>
  <?php // the command on a screen of its own, as the update (owner 2026-10-26): the output while it runs, no way out
  // until it ends, then Close reloads the page ?>
  <div id="dzov" style="display:none;position:fixed;inset:0;z-index:2000;background:rgba(0,0,0,.65);align-items:center;justify-content:center">
    <div style="background:var(--panel,#fff);color:var(--text,#000);border-radius:12px;padding:16px;width:min(760px,calc(100% - 24px));max-height:calc(100% - 24px);display:flex;flex-direction:column;gap:8px">
      <b style="font-size:16px" id="dztitle"></b>
      <div id="dzstate"></div>
      <pre id="dzlog" style="flex:1;min-height:160px;max-height:60vh;overflow:auto;background:#111;color:#ddd;border-radius:8px;padding:8px;font-size:12px;white-space:pre-wrap;margin:0"></pre>
      <div style="text-align:right"><button type="button" class="stdbtn" id="dzclose" style="display:none" onclick="location.reload()"><?php echo t('Close'); ?></button></div>
    </div>
  </div>
  <script>
  var dz = {from: 0, done: false};
  function dzLeave(e) { if (!dz.done) { e.preventDefault(); e.returnValue = ''; return ''; } }
  function dzRun(btn) {
    dz.from = 0; dz.done = false;
    document.getElementById('dztitle').textContent = btn.dataset.what;
    document.getElementById('dzlog').textContent = '';
    document.getElementById('dzstate').textContent = t('Running, do not close this page…');
    document.getElementById('dzstate').style.color = '';
    document.getElementById('dzclose').style.display = 'none';
    document.getElementById('dzov').style.display = 'flex';
    window.addEventListener('beforeunload', dzLeave);
    try { window.top.addEventListener('beforeunload', dzLeave); } catch (e) {}
    var x = new XMLHttpRequest();
    x.onload = function () {
      var r; try { r = JSON.parse(this.responseText); } catch (e) { r = {ok: false}; }
      if (r.ok) return dzPoll();
      dz.done = true;
      document.getElementById('dzstate').textContent = r.busy ? t('Another service command is still running. Try again in a moment.') : (this.status === 401 ? t('Log in first.') : t('This command is not allowed.'));
      document.getElementById('dzclose').style.display = '';
    };
    x.onerror = function () { setTimeout(dzPoll, 2000); };
    x.open('POST', 'scripts/danger_run.php?start=1', true);
    x.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
    x.send('cmd=' + encodeURIComponent(btn.dataset.cmd));
  }
  function dzPoll() {
    var x = new XMLHttpRequest();
    x.timeout = 10000;
    x.onload = function () {
      var r; try { r = JSON.parse(this.responseText); } catch (e) { return setTimeout(dzPoll, 2000); }
      if (r.text) { var pre = document.getElementById('dzlog'); pre.textContent += r.text; pre.scrollTop = pre.scrollHeight; }
      dz.from = r.size;
      if (!r.done) return setTimeout(dzPoll, 1000);
      dz.done = true;
      var st = document.getElementById('dzstate');
      st.textContent = r.code === 0 ? t('Done.') : t('Finished with an error (code {code}). See the messages above.', {code: r.code});
      st.style.color = r.code === 0 ? '' : '#c62828';
      document.getElementById('dzclose').style.display = '';
    };
    // the command restarts services (the web server among them): keep asking
    x.onerror = x.ontimeout = function () { document.getElementById('dzstate').textContent = t('The station is restarting its web server, waiting…'); setTimeout(dzPoll, 2000); };
    x.open('GET', 'scripts/danger_run.php?log=1&from=' + dz.from, true); x.send();
  }
  </script>
<?php } ?>
<?php if ($ld_mode === 'backup') { ?>
<script>
(function () {
  var seconds = 0;
  var uploader = new plupload.Uploader({
    runtimes: 'html5', browse_button: 'pickfile', container: document.getElementById('sbcontainer'),
    url: 'scripts/restore.php', chunk_size: '2mb', multi_selection: false,
    filters: {max_file_size: '<?php echo $sb_max_upload; ?>', mime_types: [{title: t('Tar files'), extensions: 'tar'}]},
    init: {
      FilesAdded: function () { uploader.start(); },
      UploadProgress: function (up, file) {
        if (file.percent !== 100) document.getElementById('pickfile').textContent = t('Uploading:') + ' ' + String(file.percent).padStart(2, '0') + '%';
        else setInterval(function () { seconds += 1; document.getElementById('pickfile').textContent = t('Restoring:') + ' ' + new Date(seconds * 1000).toISOString().substring(14, 19); }, 1000);
      },
      FileUploaded: function () {
        var x = new XMLHttpRequest();
        x.onload = function () { if (this.responseText.length > 0) document.body.innerHTML = this.responseText; };
        x.open('GET', 'views.php?submit=<?php echo rawurlencode($sb_restore); ?>');
        x.send();
      },
      Error: function (up, err) {
        document.getElementById('console').appendChild(document.createTextNode('\n' + t('Error #{code}: {message}', {code: err.code, message: err.message})));
      }
    }
  });
  uploader.init();
})();
</script>
<?php } ?>
