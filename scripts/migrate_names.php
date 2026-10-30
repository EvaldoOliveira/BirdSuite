<?php
/* The species names migration (owner 2026-10-24, common.php names_migration_overlay): ?names=start (POST) runs
 * scripts/migrate_names.py detached from the request, ?names=log&from=N answers what its log gained since byte N and,
 * at the end, the exit code (as the update on Status). */
require_once __DIR__ . '/common.php';
ensure_authenticated(t('You must be authenticated to change this station.'));
header('Content-Type: application/json');
$mark = '__BIRDSUITE_NAMES_EXIT__';
if (($_GET['names'] ?? '') === 'start' && $_SERVER['REQUEST_METHOD'] === 'POST') {
  names_migration_start();
  echo json_encode(array('ok' => true));
  die();
}
$from = max(0, intval($_GET['from'] ?? 0));
$txt = (string)@file_get_contents(names_migration_log());
$new = substr($txt, min($from, strlen($txt)));
$code = preg_match('/' . $mark . ' (\d+)/', $txt, $m) ? intval($m[1]) : null;
$new = preg_replace('/' . $mark . ' \d+\s*/', '', $new);
if ($code !== null) unset($_SESSION['names_check']);
echo json_encode(array('text' => $new, 'size' => strlen($txt), 'done' => $code !== null, 'code' => $code));
