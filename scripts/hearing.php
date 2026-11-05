<?php
/* The frequency lowering's settings for the shared hearing window (static/hearing-panel.js, owner 2026-11-04: the
 * Sonogram and the Review open the same window from the gear beside the ear): the band (FREQSHIFT_HI, FREQSHIFT_LO), the
 * cut below it (FREQSHIFT_BASE) and the factory values of the Defaults button. Read only, no
 * login: nothing here is private; the window saves through scripts/settings_set.php, which asks for it. */
require_once __DIR__ . '/common.php';
if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
header('Content-Type: application/json');
header('Cache-Control: no-store');
$c = get_config();
$d = birdsuite_defaults();
$hi = max(500, intval($c['FREQSHIFT_HI'] ?? 12000));
$dhi = max(500, intval($d['FREQSHIFT_HI'] ?? 12000));
echo json_encode(array(
  'hi' => $hi,
  'r' => max(0.2, min(1, intval($c['FREQSHIFT_LO'] ?? 6000) / $hi)),
  'base' => max(0, intval($c['FREQSHIFT_BASE'] ?? 0)),
  'def' => array('hi' => $dhi, 'r' => max(0.2, min(1, intval($d['FREQSHIFT_LO'] ?? 6000) / $dhi)), 'base' => max(0, intval($d['FREQSHIFT_BASE'] ?? 0))),
));
