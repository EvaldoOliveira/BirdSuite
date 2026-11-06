#!/usr/bin/env bash
################################################################################
# scripts/raw_recording_cron.sh - WRITES THE RAW RECORDING SCHEDULES
#
# PURPOSE:
#   Writes the raw recording schedules (one group per microphone, config/raw_recording.json,
#   scripts/raw_groups.py) into the station user's own crontab: one line per group switched on with at least
#   one day, each tagged "# birdsuite-raw-recording <microphone>" (least privilege: no root, no /etc/cron.d
#   file). The tagged lines are rewritten together; nothing on = none. Run as the station user (or as root for
#   that user).
#
# DEPENDENCIES:
#   - scripts/raw_groups.py
#   - scripts/paths.sh
#
# USAGE:
#   bash scripts/raw_recording_cron.sh
#   Referenced by scripts/raw_recording.php, scripts/raw_recording.sh
################################################################################

source "$(dirname "$(readlink -f "${BASH_SOURCE[0]}")")/paths.sh"
tag='# birdsuite-raw-recording'
user="${BIRDNET_USER:-$(awk -F: '/1000/ {print $1}' /etc/passwd)}"
# crontab of the station user: its own when run as that user, -u when run as root
ct() { if [ "$(id -un)" = "$user" ]; then crontab "$@"; else crontab -u "$user" "$@"; fi; }

others=$(ct -l 2>/dev/null | grep -vF "$tag")
lines=$(python3 "$BS_SCRIPTS/raw_groups.py" cron)
if [ -z "$others" ] && [ -z "$lines" ]; then
	ct -r 2>/dev/null
else
	{ [ -n "$others" ] && printf '%s\n' "$others"; [ -n "$lines" ] && printf '%s\n' "$lines"; } | ct -
fi
if [ -n "$lines" ]; then
	echo "Raw recording scheduled for $(printf '%s\n' "$lines" | wc -l) microphone(s)"
else
	echo "Raw recording schedule off"
fi
