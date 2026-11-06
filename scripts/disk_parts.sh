#!/usr/bin/env bash
################################################################################
# scripts/disk_parts.sh - WHAT FILLS THE STATION'S CARD, DAY BY DAY
#
# PURPOSE:
#   What fills the station's card, day by day (US-133, owner 2026-11-01): one line per day in state/disk-parts.log,
#     YYYY-MM-DD <data folder> <program folder> <other home> <system logs> <other root> <other partitions> <card size> <raw recordings>   (bytes)
#   the data folder (BS_RECS), the program folder (BS_PROG) and the raw recordings (every folder of the microphone groups) are their folders with every subfolder;
#   other home = the home folder without them; system logs = /var/log; other root = the rest of the root filesystem (df used minus the above; mounted drives
#   never count); other partitions = the card's other partitions (used when mounted, their size when not). Their sum is
#   the card's total. Measured after the nightly purge (disk_species_clean.sh), the day's lowest; the day's line is
#   replaced, 90 days kept.
#
# DEPENDENCIES:
#   - scripts/disk_species_clean.sh
#   - scripts/paths.sh
#   - scripts/raw_groups.py
#   - birdsuite-root (sudo helper)
#
# USAGE:
#   bash scripts/disk_parts.sh
#   Referenced by scripts/disk_space.php, scripts/disk_species_clean.sh
################################################################################

source "$(dirname "$(readlink -f "${BASH_SOURCE[0]}")")/paths.sh"
log_file="$BS_STATE/disk-parts.log"
keep_days=90
[ "$(timedatectl show -p NTPSynchronized --value 2>/dev/null)" = yes ] || exit 0
mkdir -p "$BS_STATE" || exit 0

root_dev=$(stat -c %d /)
# a folder counts only when it lives on the root filesystem (the data folder on an external drive is not the card)
size_of() { [ -d "$1" ] && [ "$(stat -c %d "$1")" = "$root_dev" ] && du -sxb "$1" 2>/dev/null | cut -f1 || echo 0; }
songs=$(size_of "$BS_RECS")
suite=$(size_of "$BS_PROG")
# the raw recordings: every folder of the microphone groups (raw_groups.py dirs) that lives on the card
raw=0
while IFS= read -r d; do [ -n "$d" ] && raw=$(( raw + $(size_of "$d") )); done < <(python3 "$BS_SCRIPTS/raw_groups.py" dirs 2> /dev/null)
home_all=$(size_of "$HOME")
home_other=$(( home_all - songs - suite - raw )); [ "$home_other" -lt 0 ] && home_other=0
# /var/log needs root to be read whole (the journal): birdsuite-root logs-sizes
varlog=$(sudo -n /usr/local/sbin/birdsuite-root logs-sizes 2>/dev/null | awk '{s+=$2} END {print s+0}')
[ "$varlog" -gt 0 ] 2>/dev/null || varlog=$(du -sxb /var/log 2>/dev/null | cut -f1)
root_used=$(df -B1 --output=used / | tail -n1 | tr -d ' ')
root_other=$(( root_used - songs - suite - raw - home_other - varlog )); [ "$root_other" -lt 0 ] && root_other=0
# the card: the disk the root filesystem is on, and its other partitions
root_src=$(findmnt -no SOURCE /)
disk=$(lsblk -no PKNAME "$root_src" 2>/dev/null | head -n1)
parts=0; card=$root_used
if [ -n "$disk" ]; then
	card=$(lsblk -bdno SIZE "/dev/$disk")
	while read -r name size mnt; do
		[ "/dev/$name" = "$root_src" ] && continue
		if [ -n "$mnt" ] && [ "$mnt" != "[SWAP]" ]; then used=$(df -B1 --output=used "$mnt" 2>/dev/null | tail -n1 | tr -d ' ')
		else used=$size; fi
		parts=$(( parts + ${used:-0} ))
	done < <(lsblk -lnbo NAME,TYPE,SIZE,MOUNTPOINT "/dev/$disk" | awk '$2 == "part" {print $1, $3, $4}')
fi

today=$(date +%F)
oldest=$(date -d "-$((keep_days - 1)) days" +%F)
exec 9>>"$BS_STATE/disk-usage.lock"
flock -w 30 9 || exit 0
touch "$log_file" || exit 0
tmp=$(mktemp "$BS_STATE/.disk-parts.XXXXXX") || exit 0
{ awk -v d="$today" -v old="$oldest" '$1 >= old && $1 != d && NF >= 8' "$log_file"
	echo "$today $songs $suite $home_other $varlog $root_other $parts $card $raw"; } | sort > "$tmp"
chmod 644 "$tmp"
mv -f "$tmp" "$log_file"
