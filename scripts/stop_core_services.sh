#!/usr/bin/env bash
################################################################################
# scripts/stop_core_services.sh - STOP OF THE CORE SERVICES
#
# PURPOSE:
#   Stops the core services (recording, analysis and the ones around them) and removes the unprocessed audio;
#   the Stop core services button of Status and the steps that need the station quiet.
#
# DEPENDENCIES:
#   - scripts/paths.sh
#
# USAGE:
#   bash scripts/stop_core_services.sh
#   Referenced by homepage/views.php, scripts/backup_data.sh, scripts/cleanup.sh, scripts/disk_check.sh,
#   scripts/status.php
################################################################################

source "$(dirname "$(readlink -f "${BASH_SOURCE[0]}")")/paths.sh"
services=("$BS_SVC_RECORDING.service"
"$BS_SVC_ANALYSIS.service"
"$BS_SVC_SPECTROGRAM.service")

for i in  "${services[@]}";do
	sudo -n "$BS_SVC_HELPER" stop "${i}"
done
rm -rf "${RECS_DIR:?}"/"$(date +%B-%Y/%d-%A)"/*
