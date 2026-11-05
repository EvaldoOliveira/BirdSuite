<?php
/* A forgotten web password (US-53, owner 2026-11-04). The station keeps only the password's hash (SEC-15), so it can
 * never be sent: a one-time reset link is, through the channel chosen in Settings › Access (PWD_RECOVERY: e-mail or
 * Telegram), to the address or group already set up there.
 * - asked only from a private network (the LAN, the station WiFi, the VPN), at most 3 times an hour;
 * - the link holds a random token, kept only as its hash, valid 15 minutes and once;
 * - the new password is typed twice, stored as its hash, the web login switched on, the web server written again, and
 *   the change announced on the same channel.
 * The other ways back, always possible: the file reset-web-password on the card's boot partition (the factory password
 * at the next start) and `sudo birdsuite-root webpassword-reset` on the console.
 * Opens without the login (the Caddyfile lets a private network reach this page); no file of the station's pages is
 * loaded, so the browser never shows a login dialog here. */
require_once __DIR__ . '/common.php';
header('Cache-Control: no-store');
header('Referrer-Policy: no-referrer');

$state = BS_STATE . '/pwreset.json';
$read = function () use ($state) {
  $j = json_decode((string)@file_get_contents($state), true);
  return is_array($j) ? $j : array();
};
$write = function ($d) use ($state) {
  $tmp = $state . '.tmp';
  $old = umask(0077);
  $ok = @file_put_contents($tmp, json_encode($d)) !== false && @rename($tmp, $state);
  umask($old);
  return $ok;
};
$channel = trim((string)(get_config()['PWD_RECOVERY'] ?? ''), " \"'");
$msg = '';
$bad = false;
$form = 'request';
$token = preg_replace('/[^a-f0-9]/', '', (string)($_POST['token'] ?? $_GET['token'] ?? ''));

$valid_token = function ($token) use ($read) {
  $d = $read();
  return $token !== '' && isset($d['hash'], $d['exp']) && time() <= intval($d['exp'])
    && hash_equals((string)$d['hash'], hash('sha256', $token));
};

if (!is_private_client()) {
  http_response_code(403);
  $msg = t('str_the_reset_only_from_your_network_help');
  $bad = true;
  $form = '';
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && !is_same_origin_request()) {
  http_response_code(403);
  $msg = t('str_refused_the_request_came_from_help');
  $bad = true;
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'request') {
  $d = $read();
  $recent = array_values(array_filter((array)($d['requests'] ?? array()), function ($t) { return intval($t) > time() - 3600; }));
  if (count($recent) >= 3) {
    $msg = t('str_three_reset_links_this_hour_help');
    $bad = true;
  } elseif (!in_array($channel, array('email', 'telegram'), true)) {
    $msg = t('str_no_reset_channel_help');
    $bad = true;
  } else {
    $tok = bin2hex(random_bytes(24));
    $recent[] = time();
    $write(array('hash' => hash('sha256', $tok), 'exp' => time() + 900, 'requests' => $recent));
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    // the link points at the station itself, never at whatever Host the request named (run 5, NEW-SEC-R5-01: a client of
    // the network could ask for a reset with its own address as Host and catch the token): the Host is kept only when
    // it is one of the station's own names or addresses, else the station's .local name
    $host = preg_replace('/[^A-Za-z0-9.:\[\]-]/', '', (string)($_SERVER['HTTP_HOST'] ?? ''));
    // the name or address without the port ([v6]:port or name:port)
    $bare = strtolower($host[0] ?? '') === '[' ? strtolower(substr($host, 1, max(0, (int)strpos($host, ']') - 1))) : strtolower(preg_replace('/:\d+$/', '', $host));
    $own_name = strtolower(gethostname() ?: 'birdsuite');
    $own = array($own_name, $own_name . '.local', 'localhost', '127.0.0.1', '::1');
    foreach (preg_split('/\s+/', trim((string)@shell_exec('hostname -I 2>/dev/null'))) as $ip) if ($ip !== '') $own[] = strtolower($ip);
    // the link takes the matched name (never the request's own string) and the port the request came to
    $port = preg_match('/:(\d+)$/', $host, $m) ? ':' . $m[1] : '';
    $host = in_array($bare, $own, true) ? (strpos($bare, ':') !== false ? '[' . $bare . ']' : $bare) . $port : $own_name . '.local';
    $link = $scheme . '://' . $host . '/scripts/password_reset.php?token=' . $tok;
    $p = proc_open(station_prefix() . escapeshellarg(BS_PYTHON) . ' ' . escapeshellarg(BS_SCRIPTS . '/password_reset_send.py'),
      array(0 => array('pipe', 'r'), 1 => array('pipe', 'w'), 2 => array('pipe', 'w')), $pipes, BS_SCRIPTS);
    $rc = 1;
    if (is_resource($p)) {
      fwrite($pipes[0], $link . "\n"); fclose($pipes[0]);
      stream_get_contents($pipes[1]); fclose($pipes[1]); fclose($pipes[2]);
      $rc = proc_close($p);
    }
    unset($tok, $link);
    $msg = $rc === 0 ? t('str_the_reset_link_was_sent_help', array('channel' => t($channel === 'email' ? 'str_e_mail' : 'str_telegram')))
                     : t('str_the_reset_link_could_not_be_sent_help');
    $bad = $rc !== 0;
    $form = '';
  }
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'set') {
  $p1 = (string)($_POST['password'] ?? '');
  $p2 = (string)($_POST['password2'] ?? '');
  $form = 'set';
  if (!$valid_token($token)) {
    $msg = t('str_the_reset_link_is_no_longer_help'); $bad = true; $form = 'request';
  } elseif ($p1 === '' || $p1 !== $p2) {
    $msg = t('str_the_two_passwords_differ'); $bad = true;
  } elseif (!web_password_ok($p1)) {
    $msg = t('str_the_password_may_contain_help'); $bad = true;
  } elseif (!conf_set_many(array('CADDY_PWD' => web_password_hash($p1), 'WEB_LOGIN' => 'on'), $err)) {
    $msg = $err; $bad = true;
  } else {
    $d = $read();
    unset($d['hash'], $d['exp']);
    $write($d);
    exec('sudo -n ' . BS_ROOT_HELPER . ' caddyfile > /dev/null 2>&1 &');
    syslog(LOG_NOTICE, 'BirdSuite: web password changed through a reset link');
    $msg = t('str_the_web_password_is_changed_help');
    $form = '';
  }
  unset($p1, $p2);
} elseif ($token !== '') {
  if ($valid_token($token)) $form = 'set';
  else { $msg = t('str_the_reset_link_is_no_longer_help'); $bad = true; }
}
$h = function ($s) { return htmlspecialchars((string)$s, ENT_QUOTES); };
?><!DOCTYPE html>
<html lang="<?php echo $h(str_replace('_', '-', ui_lang())); ?>"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title><?php echo $h(t('str_forgot_the_password')); ?> - BirdSuite</title>
<style>
  body { font-family: system-ui, sans-serif; max-width: 34em; margin: 24px auto; padding: 0 16px; color: #1b1b1b; background: #fff; }
  h1 { font-size: 20px; } p { line-height: 1.45; } .bad { color: #c62828; font-weight: 600; } .good { color: #2b5e22; font-weight: 600; }
  label { display: block; margin: 8px 0 2px; } input { width: 100%; box-sizing: border-box; padding: 6px; font-size: 15px; }
  button { margin-top: 12px; padding: 6px 16px; border: 1px solid #2b5e22; border-radius: 12px; background: #2b5e22; color: #fff; font-size: 14px; cursor: pointer; }
  .small { font-size: 13px; color: #555; }
</style></head><body>
<h1><?php echo $h(t('str_forgot_the_password')); ?></h1>
<?php if ($msg !== '') echo '<p class="' . ($bad ? 'bad' : 'good') . '">' . $h($msg) . '</p>'; ?>
<?php if ($form === 'request') { ?>
  <?php if (in_array($channel, array('email', 'telegram'), true)) { ?>
  <p><?php echo $h(t('str_a_reset_link_goes_to_help', array('channel' => t($channel === 'email' ? 'str_e_mail' : 'str_telegram')))); ?></p>
  <form method="POST"><input type="hidden" name="action" value="request"><button type="submit"><?php echo $h(t('str_send_the_reset_link')); ?></button></form>
  <?php } else { ?>
  <p><?php echo $h(t('str_no_reset_channel_help')); ?></p>
  <?php } ?>
<?php } elseif ($form === 'set') { ?>
  <form method="POST" autocomplete="off"><input type="hidden" name="action" value="set"><input type="hidden" name="token" value="<?php echo $h($token); ?>">
    <label for="pw1"><?php echo $h(t('str_new_password')); ?></label><input id="pw1" name="password" type="password" autocomplete="new-password" pattern="<?php echo $h(web_password_pattern()); ?>" required>
    <label for="pw2"><?php echo $h(t('str_repeat')); ?></label><input id="pw2" name="password2" type="password" autocomplete="new-password" pattern="<?php echo $h(web_password_pattern()); ?>" required>
    <button type="submit"><?php echo $h(t('str_save')); ?></button></form>
<?php } ?>
<p class="small"><?php echo $h(t('str_other_ways_back_help')); ?></p>
<p><a href="/"><?php echo $h(t('str_back_to_the_station')); ?></a></p>
</body></html>
