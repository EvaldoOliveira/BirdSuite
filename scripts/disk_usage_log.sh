#!/usr/bin/env bash
################################################################################
# scripts/disk_usage_log.sh - DISK USED DAY BY DAY
#
# PURPOSE:
#   Disk used day by day (US-133, owner 2026-11-01): one line per day in state/disk-usage.log,
#     YYYY-MM-DD <lowest used> <highest used> <disk size>   (bytes, the station's own disk: the root filesystem)
#   The lowest is the day's value right after the nightly purge, the highest the one right before it. Called by the
#   nightly purge (disk_species_clean.sh: before and after) and by disk_check.sh every 5 minutes, which also catches a
#   threshold purge, an import or a night the station was off at 02:00. The day's line is updated in place; 90 days kept.
#
# DEPENDENCIES:
#   - scripts/disk_species_clean.sh
#   - scripts/disk_check.sh
#   - scripts/paths.sh
#
# USAGE:
#   bash scripts/disk_usage_log.sh
#   Referenced by scripts/disk_check.sh, scripts/disk_species_clean.sh
################################################################################

source "$(dirname "$(readlink -f "${BASH_SOURCE[0]}")")/paths.sh"
log_file="$BS_STATE/disk-usage.log"
keep_days=90

read -r used size < <(df -B1 --output=used,size / 2>/dev/null | tail -n1)
[[ "$used" =~ ^[0-9]+$ && "$size" =~ ^[0-9]+$ ]] || exit 0
# a Pi without a clock battery boots in the past until NTP sets the clock: no sample under a wrong date
[ "$(timedatectl show -p NTPSynchronized --value 2>/dev/null)" = yes ] || exit 0
mkdir -p "$BS_STATE" || exit 0
touch "$log_file" || exit 0
today=$(date +%F)
oldest=$(date -d "-$((keep_days - 1)) days" +%F)

# the purge and the 5-minute check may write at the same moment: one at a time, never a lost line
exec 9>>"$BS_STATE/disk-usage.lock"
flock -w 30 9 || exit 0
tmp=$(mktemp "$BS_STATE/.disk-usage.XXXXXX") || exit 0
awk -v d="$today" -v u="$used" -v s="$size" -v old="$oldest" '
  $1 < old { next }
  $1 == d { lo = ($2 < u) ? $2 : u; hi = ($3 > u) ? $3 : u; print d, lo, hi, s; done = 1; next }
  NF >= 4 { print }
  END { if (!done) print d, u, u, s }
' "$log_file" 2>/dev/null >"$tmp" || awk -v d="$today" -v u="$used" -v s="$size" 'BEGIN { print d, u, u, s }' >"$tmp"
# sorted by date, so a clock stepped back at boot (no RTC) never leaves a line out of order
sort -o "$tmp" "$tmp"
# readable by the web pages (on older stations PHP runs as another user); nothing secret in it
chmod 644 "$tmp"
mv -f "$tmp" "$log_file"
