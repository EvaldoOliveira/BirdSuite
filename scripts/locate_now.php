<?php
################################################################################
# scripts/locate_now.php - AUTO LOCATE NOW
#
# PURPOSE:
#   Auto Locate Now (US-16): runs scripts/location_check.py --probe at once (as the station user, even when
#   the boot check is off) and answers its result as JSON — network position, place, distance to the saved
#   coordinates. It only reads: no state, no notification, no question on the pages. The pages put the
#   position in their Latitude / Longitude fields; saving stays yours.
#
# DEPENDENCIES:
#   - scripts/common.php
#   - scripts/location_check.py
#
# USAGE:
#   Referenced by scripts/locations.php, scripts/setup_wizard.php
################################################################################

require_once __DIR__ . '/common.php';
ensure_authenticated();
header('Content-Type: application/json');
$out = shell_exec(station_prefix() . 'python3 ' . escapeshellarg(BS_SCRIPTS . '/location_check.py')
	. ' --probe 2>/dev/null');
$line = trim((string)$out);
echo $line !== '' ? substr($line, strrpos("\n" . $line, "\n")) : json_encode(array('error' => t('str_no_answer_lc')));
