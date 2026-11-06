#!/usr/bin/env bash
################################################################################
# scripts/birdnet_log.sh - THE ANALYSIS LOG STREAM
#
# PURPOSE:
#   Follows the journal of the analysis and the recording services, the date and the home path stripped, for
#   the Analysis log page (the log service through gotty).
#
# DEPENDENCIES:
#   - scripts/core.sh
#
# USAGE:
#   bash scripts/birdnet_log.sh
#   Referenced by templates/units/SVC_LOG.service
################################################################################

source "$(dirname "$(readlink -f "${BASH_SOURCE[0]}")")/core.sh"
journalctl --no-hostname -q -o short -fu "$BS_SVC_ANALYSIS" -u "$BS_SVC_RECORDING" | sed "s/$(date "+%b %d ")//g;s/${HOME//\//\\/}\///g;/Line/d;/find/d;/systemd/d;s/ .*\[.*\]: /---/"
