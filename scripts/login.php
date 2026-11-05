<?php
// Log in / Log out of the user selector (US-53, owner 2026-11-04). The web login is the browser's Basic login: there
// is no session to end, so
// - ?login=1 asks for the login (the browser's dialog) and goes back to the page it came from;
// - ?logout=1 is called by the page with deliberately wrong credentials: the answer 401 without a new dialog makes Chrome
//   and Firefox forget the saved ones (Safari and some phone browsers keep them until the browser is closed).
require_once __DIR__ . '/common.php';
if (isset($_GET['logout'])) {
  header('HTTP/1.0 401 Unauthorized');
  header('Cache-Control: no-store');
  echo 'OK';
  exit;
}
ensure_authenticated();
$back = (string)($_GET['back'] ?? '/');
// only a page of this station
if (!preg_match('~^/(\?view=[^\r\n]*)?$~', $back)) $back = '/';
header('Location: ' . $back);
