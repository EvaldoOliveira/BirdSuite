<?php
/* The picture of a species, served from the station (US-22 / US-29): ?sci=<scientific name>. The first request downloads
 * the Wikimedia thumbnail the picture cache (Wikipedia class, scripts/wikipedia.db) names into state/cache/species/,
 * every later one serves that copy, so the pictures show without internet once seen. When the cache names another
 * picture (its details are refreshed every 15-25 days) the copy is downloaded again; the old one stays while that fails.
 * No picture kept and none reachable: 404 (the pages show the BirdSuite logo). */
require_once __DIR__ . '/common.php';
$sci = (string)($_GET['sci'] ?? '');
if (!preg_match("/^[A-Za-z][A-Za-z .()'-]{1,80}$/", $sci)) { http_response_code(404); die(); }
$dir = BS_CACHE . '/species';
$file = $dir . '/' . name_safe($sci);
$src = (new Wikipedia())->source_url($sci);
// the copy and, beside it, the address it came from and its type
$meta = @file($file . '.src', FILE_IGNORE_NEW_LINES) ?: array();
$have = is_file($file) && count($meta) >= 2;
if ($src !== '' && (!$have || $meta[0] !== $src) && preg_match('~^https://upload\.wikimedia\.org/~', $src)) {
  $ctx = stream_context_create(array('http' => array('header' => "User-Agent: BirdSuite", 'timeout' => 8)));
  $data = @file_get_contents($src, false, $ctx);
  $info = $data !== false && strlen($data) < 8 * 1048576 ? @getimagesizefromstring($data) : false;
  if ($info && in_array($info['mime'], array('image/jpeg', 'image/png', 'image/webp', 'image/gif'), true)) {
    if (!is_dir($dir)) @mkdir($dir, 0775, true);
    $tmp = $file . '.tmp' . getmypid();
    if (@file_put_contents($tmp, $data) !== false && @rename($tmp, $file)) {
      @file_put_contents($file . '.src', $src . "\n" . $info['mime'] . "\n");
      $meta = array($src, $info['mime']);
      $have = true;
    } else {
      @unlink($tmp);
    }
  }
}
if (!$have) {
  // not on the station and not downloadable now: the browser may still have it
  if ($src !== '') { header('Location: ' . $src, true, 302); die(); }
  http_response_code(404);
  die();
}
header('Content-Type: ' . $meta[1]);
header('Content-Length: ' . filesize($file));
header('Cache-Control: public, max-age=604800');
readfile($file);
