<?php
################################################################################
# scripts/ui_level.php - THE STATION'S EXPERIENCE LEVEL FROM THE SIDE MENU
#
# PURPOSE:
#   The station's experience level from the side menu (US-52, owner 2026-11-04): POST
#   scripts/ui_level.php?level=essential| birder|expert writes UI_LEVEL into BirdSuite.conf; the page then
#   reloads with the menu of that level. Answers OK.
#
# DEPENDENCIES:
#   - scripts/common.php
#
# USAGE:
#   Referenced by homepage/views.php
################################################################################

require_once __DIR__ . '/common.php';
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); die(); }
ensure_authenticated(t('str_you_must_change_the_settings_help'));
$level = $_GET['level'] ?? '';
if (!isset(ui_levels()[$level])) { echo 'Error'; die(); }
echo conf_set('UI_LEVEL', $level) ? 'OK' : t('str_error_writing_the_settings');
