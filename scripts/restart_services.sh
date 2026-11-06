#!/usr/bin/env bash
################################################################################
# scripts/restart_services.sh - RESTART OF ALL SERVICES
#
# PURPOSE:
#   Restarts ALL services and removes ALL unprocessed audio
#
# DEPENDENCIES:
#   - scripts/paths.sh
#
# USAGE:
#   bash scripts/restart_services.sh
#   Referenced by homepage/views.php, scripts/backup_data.sh, scripts/clear_all_data.sh,
#   scripts/factory_settings.sh, scripts/microphone_page.php, scripts/model_page.php,
#   scripts/notifications_page.php …
################################################################################

source "$(dirname "$(readlink -f "${BASH_SOURCE[0]}")")/paths.sh"
set -x
my_dir=$BS_SCRIPTS


sudo -n "$BS_SVC_HELPER" stop "$BS_SVC_RECORDING.service"

services=("$BS_SVC_SPECTROGRAM.service"
	"$BS_SVC_ICECAST.service"
	"$BS_SVC_RECORDING.service"
	"$BS_SVC_ANALYSIS.service"
	"$BS_SVC_LOG.service")

for i in  "${services[@]}";do
	sudo -n "$BS_SVC_HELPER" restart "${i}"
done

for i in {1..5}; do
	# We want to loop here (5*5seconds) until the analysis service is running
	systemctl is-active --quiet "$BS_SVC_ANALYSIS.service" \
		&& logger "[$0] $BS_SVC_ANALYSIS.service is running" \
		&& break

	sleep 5
done
