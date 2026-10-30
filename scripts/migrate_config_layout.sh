#!/usr/bin/env bash
# One-time move of a station's own files into ~/BirdNET-Pi/config (owner 2026-10-22): settings, lists, notifications,
# locations and the database leave the install root, scripts/ and /etc/birdnet. Run by update_birdnet_snippets.sh
# (as root) before anything reads the settings; safe to run again (a file already in config/ stays as it is).
#   config/BirdSuite.conf (the station's settings, birdnet.conf before), factory_defaults.conf (in git),
#   settings_profiles/, custom_themes.json, locations.json, stations.json
#   config/species_lists/  the running lists (include / exclude / whitelist / confirmed, species_confidence,
#                          species_min_prob, species_max_files, notification_tiers); custom/ = the station's lists
#   config/scripts/        disk_check_exclude.txt, blacklisted_images.txt
#   config/notifications/  apprise*.txt, body*.txt, telegram_groups.json
#   config/data/           birds.db, BirdDB.txt
#   config/custom/         the station layer (a folder, or a link to it)
#   config/firstrun.ini    the first-run answers
#   state/ (beside config/) the running state: location jobs, notification queue, microphone markers, logs
B=$(cd "$(dirname "$(readlink -f "${BASH_SOURCE[0]}")")/.." && pwd)
C=$B/config
owner=$(stat -c %U "$B")
moved=0

# mv_one <old> <new>: moves a file, folder or link unless the new place already holds one
mv_one() {
  if { [ -e "$1" ] || [ -L "$1" ]; } && ! { [ -e "$2" ] || [ -L "$2" ]; }; then
    mkdir -p "$(dirname "$2")"
    mv "$1" "$2" && moved=$((moved + 1))
  fi
}

mkdir -p "$C/species_lists/custom" "$C/scripts" "$C/notifications" "$C/data" "$B/state"

# settings, named BirdSuite.conf (owner 2026-10-22: no birdnet.conf any more): a separate /etc copy was the one the
# pages wrote (sed -i had broken the link), so it wins
mv_one "$C/birdnet.conf" "$C/BirdSuite.conf"
if [ -f /etc/birdnet/birdnet.conf ] && [ ! -L /etc/birdnet/birdnet.conf ] && [ ! -e "$C/BirdSuite.conf" ]; then
  cp -p /etc/birdnet/birdnet.conf "$C/BirdSuite.conf" && moved=$((moved + 1))
  rm -f "$B/birdnet.conf"
fi
mv_one "$B/birdnet.conf" "$C/BirdSuite.conf"
[ -e "$C/BirdSuite.conf" ] && rm -rf /etc/birdnet
mv_one "$B/settings_profiles" "$C/settings_profiles"
mv_one "$B/custom_themes.json" "$C/custom_themes.json"

# the station's own lists, then the running ones (the active list was a link into species_lists/)
if [ -d "$B/species_lists" ] && [ ! -L "$B/species_lists" ]; then
  for f in "$B"/species_lists/* "$B"/species_lists/.[!.]*; do
    [ -e "$f" ] && mv_one "$f" "$C/species_lists/custom/$(basename "$f")"
  done
  rmdir "$B/species_lists" 2> /dev/null
fi
if [ -L "$B/include_species_list.txt" ]; then
  target=$(readlink "$B/include_species_list.txt")
  if [ ! -e "$C/species_lists/include_species_list.txt" ]; then
    case "$target" in
      */species_lists/*) ln -s "$C/species_lists/custom/$(basename "$target")" "$C/species_lists/include_species_list.txt" ;;
      *) cp "$(readlink -f "$B/include_species_list.txt")" "$C/species_lists/include_species_list.txt" 2> /dev/null ;;
    esac
    moved=$((moved + 1))
  fi
  rm -f "$B/include_species_list.txt"
fi
for f in include_species_list.txt exclude_species_list.txt whitelist_species_list.txt confirmed_species_list.txt \
         species_confidence.txt species_min_prob.txt species_max_files.txt notification_tiers.txt; do
  mv_one "$B/$f" "$C/species_lists/$f"
done
# the old links of scripts/ to the lists and to BirdDB.txt
for f in include_species_list.txt exclude_species_list.txt whitelist_species_list.txt confirmed_species_list.txt BirdDB.txt; do
  [ -L "$B/scripts/$f" ] && rm -f "$B/scripts/$f"
done

mv_one "$B/scripts/disk_check_exclude.txt" "$C/scripts/disk_check_exclude.txt"
mv_one "$B/scripts/blacklisted_images.txt" "$C/scripts/blacklisted_images.txt"
for f in apprise.txt apprise-rare.txt apprise-tokens.txt body.txt body-rare.txt telegram_groups.json; do
  mv_one "$B/$f" "$C/notifications/$f"
done
# the first-run answers in config/; the running state in state/ beside it (not settings, left out of the backup;
# a config/state of the first version of this layout moves there too)
mv_one "$B/firstrun.ini" "$C/firstrun.ini"
mv_one "$B/firstrun_pending" "$B/state/firstrun_pending"
for f in location_check.json location_job.json location_job.lock location_switch.json species_info_build.log \
         notify_queue.jsonl notify_queue.jsonl.lock notify_queue.jsonl.flush mic_reconnected.json .mic_removed; do
  mv_one "$B/$f" "$B/state/$f"
  mv_one "$C/state/$f" "$B/state/$f"
done
mv_one "$B/scripts/locations.json" "$C/locations.json"
mv_one "$B/scripts/stations.json" "$C/stations.json"

# the database with the services that write it stopped (its -wal / -shm files move with it)
if [ -e "$B/scripts/birds.db" ] && [ ! -e "$C/data/birds.db" ]; then
  running=""
  for s in birdnet_analysis birdnet_recording; do
    systemctl is-active --quiet "$s" && running="$running $s"
  done
  [ -n "$running" ] && systemctl stop $running
  for f in birds.db birds.db-wal birds.db-shm; do mv_one "$B/scripts/$f" "$C/data/$f"; done
  [ -n "$running" ] && systemctl start $running
fi
mv_one "$B/BirdDB.txt" "$C/data/BirdDB.txt"

# the station layer: a folder, or a link to it (the owner's stations keep it on the NAS-backed Documents)
if { [ -e "$B/custom" ] || [ -L "$B/custom" ]; } && [ ! -L "$C/custom" ]; then
  rmdir "$C/custom" 2> /dev/null
  mv_one "$B/custom" "$C/custom"
fi
# a root link left when the station layer was already linked in config/ (do-setup22 ran first)
[ -L "$B/custom" ] && { [ -e "$C/custom" ] || [ -L "$C/custom" ]; } && rm -f "$B/custom"
mkdir -p "$C/custom" "$C/settings_profiles"

rmdir "$C/state" 2> /dev/null
chown -R "$owner:$owner" "$C" "$B/state"
# the web pages run as caddy (in the station user's group): every folder group-writable, as the install root was;
# SQLite creates its -wal / -journal files beside birds.db
chmod -R g+rwX "$C" "$B/state"
[ -f "$C/notifications/apprise-tokens.txt" ] && chmod u=rw,go= "$C/notifications/apprise-tokens.txt"  # secrets stay private
[ "$moved" -gt 0 ] && echo "Station files moved to $C: $moved"
exit 0
