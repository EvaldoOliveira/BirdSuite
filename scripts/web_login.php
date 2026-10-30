<?php
/* The web password shown on request (owner 2026-10-25: Settings › Access as Network › Plans A and B, "Show password");
 * POST only, after the login. */
require_once __DIR__ . '/common.php';
header('Content-Type: application/json');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { echo json_encode(array('ok' => false)); die(); }
ensure_authenticated(t('You must be authenticated to change the settings.'));
echo json_encode(array('ok' => true, 'psk' => (string)(get_config()['CADDY_PWD'] ?? '')));
