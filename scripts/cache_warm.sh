#!/usr/bin/env bash
################################################################################
# scripts/cache_warm.sh - NIGHTLY PRE-WARM OF THE PER-MONTH CACHES
#
# PURPOSE:
#   Nightly pre-warm of the per-month caches (2026-10-23 run 3): the first visit of By Hour (All), the species
#   list, Seasonality and the busiest species pages after a cache was cleared took 2-11 s on a large database;
#   one local request each, after the nightly clean-up, builds them so the morning's first visit is fast.
#   Light: one page at a time, low priority, a time limit per page.
#
# DEPENDENCIES:
#   - scripts/core.sh
#
# USAGE:
#   bash scripts/cache_warm.sh
#   Referenced by scripts/disk_species_clean.sh
################################################################################

source "$(dirname "$(readlink -f "${BASH_SOURCE[0]}")")/core.sh"
DB="$BS_DB"
[ -f "$DB" ] || exit 0
warm() { nice -n 19 curl -s -o /dev/null --max-time 120 "http://localhost/views.php?$1" || true; }
# Tools › Data: its per-location counts of the days before today are kept until a new day (2026-11-03, SCL-13)
for p in "view=By%20Hour&period=all" "view=By%20Hour&period=last30" "view=By%20Hour&period=last7" "view=Species%20Cards" "view=By%20Week" "view=Data"; do
	warm "$p"
done
# the ten species with the most detections: their pages carry the heaviest per-day and per-hour counts
sqlite3 -readonly -cmd ".timeout 10000" "$DB" "SELECT Sci_Name FROM detections GROUP BY Sci_Name ORDER BY COUNT(*) DESC LIMIT 10;" 2> /dev/null |
	while IFS= read -r sci; do
		[ -n "$sci" ] && warm "view=Species%20Cards&sci=$(printf '%s' "$sci" | sed 's/ /%20/g')"
	done
exit 0
