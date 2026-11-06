<?php
################################################################################
# scripts/common.php - THE SHARED LIBRARY OF THE PAGES (LOADER)
#
# PURPOSE:
#   Loaded by every page. Sets the constants and the session, then loads the helpers by subject from
#   scripts/lib/ (ui, files, settings, db, periods, station, clips, species, auth, locations, detections,
#   images, themes). The pages keep one include: require_once "scripts/common.php".
#
# DEPENDENCIES:
#   - scripts/core.php (BS_* constants of core.env)
#   - scripts/view_registry.php (the pages, menu places and levels)
#   - scripts/lib/*.php
#
# USAGE:
#   require_once __DIR__ . '/common.php'; (or "scripts/common.php" from the web root)
################################################################################

// the folder layout, service names, ports and root helper paths: the BS_* constants of core.env (scripts/core.php,
// owner 2026-11-02; core.sh and utils/core.py read the same file)
require_once __DIR__ . '/core.php';
// the pages of the station, their menu places and levels (US-50)
require_once __DIR__ . '/view_registry.php';
if (!defined('__ROOT__')) define('__ROOT__', BS_PROG);
// the default radius of a location (owner 2026-10-25: 1 km, was 5: with the device's GPS the position is metres off)
define('LOCATION_RADIUS_KM', 1);

// the session cookie is not readable by scripts and not sent by other sites' requests (2026-10-23 test run); the same
// options go with every session_start() of the pages
define('BS_SESSION_OPTIONS', array('cookie_httponly' => true, 'cookie_samesite' => 'Lax'));
if (session_status() !== PHP_SESSION_ACTIVE)
	session_start(BS_SESSION_OPTIONS);

################################################################################
# The helpers by subject
################################################################################
require_once __DIR__ . '/lib/ui.php';
require_once __DIR__ . '/lib/files.php';
require_once __DIR__ . '/lib/settings.php';
require_once __DIR__ . '/lib/db.php';
require_once __DIR__ . '/lib/periods.php';
require_once __DIR__ . '/lib/station.php';
require_once __DIR__ . '/lib/clips.php';
require_once __DIR__ . '/lib/species.php';
require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/locations.php';
require_once __DIR__ . '/lib/detections.php';
require_once __DIR__ . '/lib/images.php';
require_once __DIR__ . '/lib/themes.php';
