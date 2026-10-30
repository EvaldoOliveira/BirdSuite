#!/usr/bin/env bash
# Writes the raw recording schedule from the Raw Recording settings in BirdSuite.conf (RAW_REC_ENABLED, RAW_REC_DAYS =
# cron days of the week 0-6 with 0 = Sunday, RAW_REC_START = HH:MM[:SS]) into the station user's own crontab, one line
# tagged "# birdsuite-raw-recording" (owner 2026-10-23, least privilege: no root, no /etc/cron.d file any more).
# Disabled or no day chosen: the line is removed. Run as the station user (or as root for that user).
source "$(dirname "$(readlink -f "${BASH_SOURCE[0]}")")/paths.sh"
tag='# birdsuite-raw-recording'
user="${BIRDNET_USER:-$(awk -F: '/1000/ {print $1}' /etc/passwd)}"
days=$(echo "${RAW_REC_DAYS:-}" | tr -cd '0-6,')
# crontab of the station user: its own when run as that user, -u when run as root
ct() { if [ "$(id -un)" = "$user" ]; then crontab "$@"; else crontab -u "$user" "$@"; fi; }

others=$(ct -l 2>/dev/null | grep -vF "$tag")
# the system file of the earlier versions goes when root runs this (the updater does)
[ -w /etc/cron.d ] && rm -f /etc/cron.d/birdnet_raw_recording

if [ "${RAW_REC_ENABLED:-0}" != "1" ] || [ -z "$days" ]; then
  if [ -n "$others" ]; then printf '%s\n' "$others" | ct -; else ct -r 2>/dev/null; fi
  echo "Raw recording schedule off"
  exit 0
fi

IFS=: read -r hh mm _ <<< "${RAW_REC_START:-04:00:00}"
hh=$((10#${hh:-4})); mm=$((10#${mm:-0}))
line="$mm $hh * * $days $BS_HOME/scripts/raw_recording.sh > /dev/null 2>&1 $tag"
{ [ -n "$others" ] && printf '%s\n' "$others"; printf '%s\n' "$line"; } | ct -
echo "Raw recording scheduled: $(printf '%02d:%02d' $hh $mm) on days $days"
