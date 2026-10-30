<?php
/* The danger zone commands on a screen of their own, as the update (owner 2026-10-26): ?start (POST, cmd =
 * clear_all_data.sh or factory_settings.sh) runs it detached from the web request (setsid: a restart of the web server
 * would end a request that waited for it), its output in state/web_danger.log; ?log&from=N answers what was added since
 * byte N and, at the end, the exit code (as status.php ?update=log). */
require_once __DIR__ . '/common.php';
ensure_authenticated(t('You must be authenticated to change the settings.'));
header('Content-Type: application/json');
$dir = get_home() . '/BirdNET-Pi/state';
@mkdir($dir, 0775, true);
$log = $dir . '/web_danger.log';
$mark = '__BIRDSUITE_DANGER_EXIT__';
if (isset($_GET['start']) && $_SERVER['REQUEST_METHOD'] === 'POST') {
  $cmd = (string)($_POST['cmd'] ?? '');
  if (!in_array($cmd, array('clear_all_data.sh', 'factory_settings.sh'), true)) { echo json_encode(array('ok' => false)); die(); }
  $txt = (string)@file_get_contents($log);
  if ($txt !== '' && strpos($txt, $mark) === false && filemtime($log) > time() - 1800) { echo json_encode(array('ok' => false, 'busy' => true)); die(); }
  file_put_contents($log, '$ ' . $cmd . "\n");
  shell_exec('setsid -f sh -c ' . escapeshellarg(escapeshellarg(get_home() . '/BirdNET-Pi/scripts/' . $cmd) . '; echo "' . $mark . ' $?"')
    . ' >> ' . escapeshellarg($log) . ' 2>&1 < /dev/null');
  echo json_encode(array('ok' => true));
  die();
}
$from = max(0, intval($_GET['from'] ?? 0));
$txt = (string)@file_get_contents($log);
$new = substr($txt, min($from, strlen($txt)));
$code = preg_match('/' . $mark . ' (\d+)/', $txt, $m) ? intval($m[1]) : null;
$new = preg_replace('/' . $mark . ' \d+\s*/', '', preg_replace('/\e\[[0-9;?]*[A-Za-z]/', '', $new));
echo json_encode(array('text' => $new, 'size' => strlen($txt), 'done' => $code !== null, 'code' => $code));
