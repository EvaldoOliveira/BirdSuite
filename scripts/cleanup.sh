#!/usr/bin/env bash
################################################################################
# scripts/cleanup.sh - REMOVAL OF THE EMPTY RECORDINGS
#
# PURPOSE:
#   Removes the empty recordings (57 bytes, a WAV header alone) left in the processed folder; run by cron
#   (templates/cleanup.cron).
#
# DEPENDENCIES:
#   - scripts/paths.sh
#   - scripts/stop_core_services.sh
#
# USAGE:
#   bash scripts/cleanup.sh
#   Referenced by templates/cleanup.cron
################################################################################

source "$(dirname "$(readlink -f "${BASH_SOURCE[0]}")")/paths.sh"
set -x

cd "${PROCESSED}" || exit 1
empties=($(find ${PROCESSED} -size 57c))
for i in "${empties[@]}";do
	rm -f "${i}"
	rm -f "${i/.csv/}"
done

if [[ "$(find ${PROCESSED} | wc -l)" -ge 100 ]];then
	ls -1t . | tail -n +100 | xargs -r rm -vv
fi

#accumulated_files=$(find $RECS_DIR -path $PROCESSED -prune -o -path $EXTRACTED -prune -o -type f -print | wc -l)
#[ $accumulated_files -ge 10 ] && stop_core_services.sh
#echo "$(date "+%b  %e %I:%M:%S") Stopped Core Services -- It looks like analysis stopped. Check raw recordings in $RECS_DIR and check the analysis service \"journalctl -eu $BS_SVC_ANALYSIS\"" | sudo tee -a /var/log/syslog
