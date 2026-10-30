<?php
// Interface language from the side menu (US-122, owner 2026-10-20): POST scripts/ui_lang.php?lang=en|pt_BR|es writes
// UI_LANG into BirdSuite.conf; the page then reloads in that language. Answers OK.
require_once __DIR__ . '/common.php';
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); die(); }
ensure_authenticated(t('You must be authenticated to change the settings.'));
$lang = $_GET['lang'] ?? '';
if (!isset(ui_languages()[$lang])) { echo 'Error'; die(); }
$f = BS_CONF;
$c = (string)file_get_contents($f);
$c = preg_match('/^UI_LANG=/m', $c) ? preg_replace('/^UI_LANG=.*/m', "UI_LANG=$lang", $c)
  : $c . "\n## UI_LANG: language of the interface (US-122): en, pt_BR or es; empty = follow DATABASE_LANG\nUI_LANG=$lang\n";
$ok = file_put_contents($f, $c) !== false;
if ($ok) station_numeric_sync($lang, (string)(get_config(true)['DATABASE_LANG'] ?? ''));
echo $ok ? 'OK' : t('Error writing the settings');
