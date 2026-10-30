#!/usr/bin/env bash
# Nightly pre-warm of the per-month caches (2026-10-23 run 3): the first visit of By Hour (All), the species list,
# Seasonality and the busiest species pages after a cache was cleared took 2-11 s on a large database; one local request
# each, after the nightly clean-up, builds them so the morning's first visit is fast. Light: one page at a time,
# low priority, a time limit per page.
DB="$HOME/BirdNET-Pi/config/data/birds.db"
[ -f "$DB" ] || exit 0
warm() { nice -n 19 curl -s -o /dev/null --max-time 120 "http://localhost/views.php?$1" || true; }
for p in "view=Dashboard&period=all" "view=Dashboard&period=last30" "view=Dashboard&period=last7" "view=Bird" "view=Seasonality"; do
  warm "$p"
done
# the ten species with the most detections: their pages carry the heaviest per-day and per-hour counts
sqlite3 -readonly -cmd ".timeout 10000" "$DB" "SELECT Sci_Name FROM detections GROUP BY Sci_Name ORDER BY COUNT(*) DESC LIMIT 10;" 2> /dev/null |
  while IFS= read -r sci; do
    [ -n "$sci" ] && warm "view=Bird&sci=$(printf '%s' "$sci" | sed 's/ /%20/g')"
  done
exit 0
