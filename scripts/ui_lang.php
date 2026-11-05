<?php
// Interface language from the side menu (US-122, owner 2026-10-20): POST scripts/ui_lang.php?lang=en|pt_BR|es writes
// UI_LANG into BirdSuite.conf; the page then reloads in that language. Answers OK.
require_once __DIR__ . '/common.php';
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); die(); }
ensure_authenticated(t('str_you_must_change_the_settings_help'));
$lang = $_GET['lang'] ?? '';
if (!isset(ui_languages()[$lang])) { echo 'Error'; die(); }
$ok = conf_set('UI_LANG', $lang);
if ($ok) station_numeric_sync($lang, (string)(get_config(true)['DATABASE_LANG'] ?? ''));
echo $ok ? 'OK' : t('str_error_writing_the_settings');
