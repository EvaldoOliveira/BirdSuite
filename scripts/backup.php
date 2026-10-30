<?php

define('__ROOT__', dirname(dirname(__FILE__)));
require_once(__ROOT__.'/scripts/common.php');

$user = get_user();
$home = get_home();
ensure_authenticated(t('You must be authenticated to download backup files.'));

set_timezone();
$date = new DateTime();
$date_str = $date->format("Ymd\\THis");

header("Content-Description: File Transfer");
header("Content-Type: application/octet-stream");
// ?saved=<name>: a settings backup kept on the station (owner 2026-10-24: Factory settings saves one first), from
// ~/BirdSuite-backups
if (isset($_GET['saved'])) {
  $f = "$home/BirdSuite-backups/" . basename((string)$_GET['saved']);
  if (!preg_match('/^settings-[0-9T-]+\.tar$/', basename($f)) || !station_user_is_file($f)) { http_response_code(404); die(); }
  header('Content-Disposition: attachment; filename="' . basename($f) . '"');
  passthru(station_prefix() . 'cat ' . escapeshellarg($f));
  exit();
}
// ?mode=settings: the settings, lists, notifications and locations without the database (owner 2026-10-24); ?mode=config
// with the database; else everything with the audio (owner 2026-10-22)
$mode = in_array($_GET['mode'] ?? '', array('config', 'settings'), true) ? $_GET['mode'] : 'full';
header("Content-Disposition: attachment; filename=\"backup-" . ($mode !== 'full' ? $mode . '-' : '') . "$date_str.tar\"");

$err=null;
set_time_limit(0);
ignore_user_abort(true);  // the end of this script still runs (lock removed, outcome logged) when the browser stops
// while the archive streams, php-fpm and caddy are not restarted (birdsuite-svc defers it): a restart cut the download
// and the browser kept a truncated archive without a word (2026-10-23 destructive phase NEW-REL-01)
$state = "$home/BirdNET-Pi/state";
@file_put_contents("$state/backup_running", getmypid() . "\n" . date('c') . "\n");
passthru(station_prefix() . "$home/BirdNET-Pi/scripts/backup_data.sh -a backup -f - -m $mode", $err);
@unlink("$state/backup_running");
$outcome = ($err === 0 && !connection_aborted()) ? 'complete' : ('INTERRUPTED (exit ' . intval($err) . (connection_aborted() ? ', browser disconnected' : '') . ')');
@file_put_contents("$state/backup_last.txt", date('c') . " $mode backup $outcome\n");
syslog($err === 0 ? LOG_INFO : LOG_ERR, "BirdSuite $mode backup download $outcome");
// the restarts asked while it streamed
$deferred = "$state/backup_deferred";
if (is_file($deferred)) {
  $lines = array_unique(array_filter(array_map('trim', file($deferred))));
  @unlink($deferred);
  foreach ($lines as $l) {
    if (preg_match('/^(restart|stop|disable-now)( [A-Za-z0-9@._-]+)+$/', $l)) exec('sudo -n /usr/local/sbin/birdsuite-svc ' . $l . ' > /dev/null 2>&1 &');
  }
}
exit();
