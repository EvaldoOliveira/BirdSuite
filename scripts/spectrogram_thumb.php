<?php
/* The small picture of a clip's spectrogram (owner 2026-11-06, Gitea #78): ?clip=<the path clip_spectrogram() answers,
 * '/By_Date/<date>/<species>/<file>.png|.jpg', or '/Purge/...' for a clip staged for the purge>. The Latest page's
 * cards draw the spectrogram about 300 px wide, but the full picture is 1-2 MB of PNG each: 25 cards loaded tens of MB
 * on every page. The first request makes a JPEG 480 px wide (quality 70) in state/cache/spec-thumb/, every later one
 * serves that copy; the clips' folders stay as they are. The copy is named by the clip without the picture's extension,
 * so the nightly archive (spectro_archive.py: the .png becomes a .jpg) does not make it again; a .png drawn again after
 * the copy was made does. The player and the share keep the full picture (data-spec, share.php).
 * The picture is made with PHP GD when PHP has it, else with Pillow of the station's Python (GD is not installed on
 * every station). Missing picture: 404. Could not be made: the full picture (302), so the card still shows.
 * Only core.php is loaded: no session, so the cards' parallel requests never wait for each other on the session lock. */
require_once __DIR__ . '/core.php';

const SPEC_THUMB_WIDTH = 480;
const SPEC_THUMB_QUALITY = 70;

function spec_thumb_fail($code) {
  http_response_code($code);
  die();
}

// the clip's picture, checked as the review clips are (play.php review_clip_path): no '..', only By_Date or Purge
$clip = (string)($_GET['clip'] ?? '');
if ($clip === '' || strpos($clip, '..') !== false || strpos($clip, "\0") !== false || strpos($clip, '\\') !== false) spec_thumb_fail(404);
if (!preg_match('~^/?(By_Date|Purge)/(\d{4}-\d{2}-\d{2})/([^/]+)/([^/]+)\.(png|jpg)$~', $clip, $m)) spec_thumb_fail(404);
$top = $m[1];
$rel = $m[2] . '/' . $m[3] . '/' . $m[4];  // the clip without the picture's extension
$root = realpath(BS_EXTRACTED . '/' . $top);
if ($root === false) spec_thumb_fail(404);
$png = BS_EXTRACTED . '/' . $top . '/' . $rel . '.png';
$jpg = BS_EXTRACTED . '/' . $top . '/' . $rel . '.jpg';
// an empty picture (a drawing that failed) counts as none
$src = @filesize($png) > 0 ? $png : (@filesize($jpg) > 0 ? $jpg : '');
if ($src === '') spec_thumb_fail(404);
$real = realpath($src);
if ($real === false || strpos($real, $root . '/') !== 0) spec_thumb_fail(404);

$dir = BS_CACHE . '/spec-thumb';
$thumb = $dir . '/' . md5($top . '/' . $rel) . '.jpg';
// made again only when a .png is newer than the copy (a .jpg is the archived same picture)
$fresh = is_file($thumb) && !($src === $png && filemtime($png) > filemtime($thumb));

if (!$fresh) {
  if (!is_dir($dir)) @mkdir($dir, 0775, true);
  $tmp = $dir . '/.' . basename($thumb) . '.' . getmypid() . '.tmp';
  $made = false;
  if (function_exists('imagecreatefrompng') && function_exists('imagejpeg')) {
    $im = $src === $png ? @imagecreatefrompng($src) : @imagecreatefromjpeg($src);
    if ($im !== false) {
      $w = imagesx($im);
      $h = imagesy($im);
      if ($w > SPEC_THUMB_WIDTH) {
        $small = imagescale($im, SPEC_THUMB_WIDTH, max(1, (int)round($h * SPEC_THUMB_WIDTH / $w)), IMG_BICUBIC);
        imagedestroy($im);
        $im = $small;
      }
      $made = $im !== false && @imagejpeg($im, $tmp, SPEC_THUMB_QUALITY);
      if ($im !== false) imagedestroy($im);
    }
  } elseif (defined('BS_PYTHON') && is_executable(BS_PYTHON)) {
    // Pillow, as the station draws the spectrograms (spectrogram_png.py); bounded, no shell (the paths are arguments)
    $code = 'import sys' . "\n" . 'from PIL import Image' . "\n"
      . 'im = Image.open(sys.argv[1]); w = int(sys.argv[3])' . "\n"
      . 'if im.width > w: im = im.resize((w, max(1, round(im.height * w / im.width))), Image.LANCZOS)' . "\n"
      . 'im.convert("RGB").save(sys.argv[2], "JPEG", quality=int(sys.argv[4]), optimize=True)' . "\n";
    $p = @proc_open(array('timeout', '20', BS_PYTHON, '-I', '-c', $code, $src, $tmp, (string)SPEC_THUMB_WIDTH, (string)SPEC_THUMB_QUALITY),
      array(0 => array('file', '/dev/null', 'r'), 1 => array('file', '/dev/null', 'w'), 2 => array('file', '/dev/null', 'w')), $pipes);
    $made = $p !== false && proc_close($p) === 0;
  }
  if ($made && is_file($tmp) && filesize($tmp) > 0 && @rename($tmp, $thumb)) {
    @chmod($thumb, 0664);
    // the folder never grows without end: now and then the copies not made again for 30 days go (the cards show the
    // latest detections; an older card seen again is made again)
    if (mt_rand(1, 200) === 1) {
      foreach (glob($dir . '/*.jpg') ?: array() as $old) if (@filemtime($old) < time() - 30 * 86400) @unlink($old);
    }
  } else {
    @unlink($tmp);
    if (!is_file($thumb)) {
      // not made (no GD, no Pillow, a broken picture): the full picture, as the cards showed before
      header('Location: /' . $top . '/' . implode('/', array_map('rawurlencode', explode('/', $rel))) . ($src === $png ? '.png' : '.jpg'), true, 302);
      die();
    }
  }
}

$mtime = filemtime($thumb);
$etag = '"' . md5($thumb) . '-' . $mtime . '"';
header('Cache-Control: public, max-age=604800');
header('ETag: ' . $etag);
header('Last-Modified: ' . gmdate('D, d M Y H:i:s', $mtime) . ' GMT');
$inm = trim((string)($_SERVER['HTTP_IF_NONE_MATCH'] ?? ''));
$ims = strtotime((string)($_SERVER['HTTP_IF_MODIFIED_SINCE'] ?? ''));
if (($inm !== '' && $inm === $etag) || ($inm === '' && $ims !== false && $ims >= $mtime)) {
  http_response_code(304);
  die();
}
header('Content-Type: image/jpeg');
header('Content-Length: ' . filesize($thumb));
readfile($thumb);
