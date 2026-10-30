#!/usr/bin/env bash
# Prepare this station's SD card to be cloned for a fleet (US-120, owner 2026-10-20).
#
# Run on a working station, as its user, then shut down and copy the card. Every clone starts as a new station:
#   - identity cleared: station name empty (each board shows BirdSuite-<its MAC>), location list reset (Home), hostname
#     'birdsuite' (each board renames itself birdsuite-<its whole MAC>), SSH host keys and machine-id regenerated
#   - data cleared: detections, reviews, removed detections, clips, recordings, station list, queues, logs
#   - the first-run setup pending again (firstrun_pending): the station WiFi comes up until the setup is done
#   - field access set: the fleet phone hotspot (name + password asked here, lowest priority) and the station WiFi
#     password (each board names it BirdSuite-<its whole MAC>); then print the QR label on each station
#   - kept: the model, the settings (thresholds, species lists, include/exclude/whitelist), unless asked otherwise
# Options: --dry-run (show, change nothing)  --yes (no confirmation)  --keep-wifi (keep this card's known WiFis)
#          --keep-notifications (keep apprise targets)  --keep-password (keep the web password)
set -e
DRY=0; YES=0; KEEP_WIFI=0; KEEP_NOTIF=0; KEEP_PWD=0
for a in "$@"; do
  case "$a" in
    --dry-run) DRY=1 ;; --yes) YES=1 ;; --keep-wifi) KEEP_WIFI=1 ;; --keep-notifications) KEEP_NOTIF=1 ;; --keep-password) KEEP_PWD=1 ;;
    *) sed -n '2,16p' "$0"; exit 2 ;;
  esac
done
HERE="$HOME/BirdNET-Pi"
CONF="$HERE/config/BirdSuite.conf"
run() { if [ "$DRY" = 1 ]; then echo "  would: $*"; else eval "$@"; fi; }

echo "Preparing $(hostname) for cloning$( [ "$DRY" = 1 ] && echo ' (dry run: nothing changes)')"
if [ "$DRY" = 0 ] && [ "$YES" = 0 ]; then
  echo "This ERASES every detection, clip and recording of this station and its identity."
  read -r -p "Type PREPARE to go on: " ok
  [ "$ok" = "PREPARE" ] || { echo "Stopped."; exit 1; }
fi

# field access for the fleet
if [ "$DRY" = 0 ]; then
  read -r -p "Fleet phone hotspot name [BirdSuite]: " FLEET_SSID; FLEET_SSID=${FLEET_SSID:-BirdSuite}
  read -r -s -p "Fleet phone hotspot password (8-63 characters): " FLEET_PSK; echo
  read -r -s -p "Station WiFi password (8-63, empty = the same): " AP_PSK; echo
  AP_PSK=${AP_PSK:-$FLEET_PSK}
  printf '%s\n' "$FLEET_PSK" | sudo python3 "$HERE/scripts/field_net.py" set-fleet "$FLEET_SSID" > /dev/null
  printf '%s\n' "$AP_PSK" | sudo python3 "$HERE/scripts/field_net.py" set-ap > /dev/null
  echo "  fleet hotspot '$FLEET_SSID' and station WiFi password set"
else
  echo "  would: ask the fleet hotspot name/password and the station WiFi password (scripts/field_net.py set-fleet / set-ap)"
fi
if [ "$KEEP_WIFI" = 0 ]; then
  nmcli -t -f NAME,TYPE connection show | while IFS=: read -r name type; do
    [ "$type" = "802-11-wireless" ] || continue
    case "$name" in birdsuite-fleet|birdsuite-ap) continue ;; esac
    run "sudo nmcli connection delete '$name' > /dev/null"
  done
fi

echo "Stopping the station"
for s in birdnet_recording birdnet_analysis chart_viewer spectrogram_viewer livestream birdsuite_ble birdsuite_field; do
  run "sudo systemctl stop $s.service 2> /dev/null || true"
done

echo "Clearing data"
run "sqlite3 '$HERE/config/data/birds.db' 'DELETE FROM detections; VACUUM;'"
for t in detection_reviews deleted_detections; do
  run "sqlite3 '$HERE/config/data/birds.db' \"DELETE FROM $t;\" 2> /dev/null || true"
done
[ -f "$HERE/scripts/birds_shadow.db" ] && run "sqlite3 '$HERE/scripts/birds_shadow.db' 'DELETE FROM detections; VACUUM;' 2> /dev/null || true"
for d in "$HOME/BirdSongs/Extracted/By_Date" "$HOME/BirdSongs/Extracted/Removed" "$HOME/BirdSongs/Extracted/Charts" "$HOME/BirdSongs/StreamData" \
         "$HOME/BirdSongs/Processed" "$HOME/BirdSongs/External"; do
  [ -d "$d" ] && run "find '$d' -mindepth 1 -delete"
done
for f in config/stations.json config/locations.json state/location_job.json state/location_switch.json state/notify_queue.jsonl \
         state/mic_reconnected.json state/.mic_removed state/location_check.json state/last_backup state/recording_watchdog.json \
         analyzing_now.txt; do
  [ -e "$HERE/$f" ] && run "rm -f '$HERE/$f'"
done
# the confirmed species are this station's: emptied
[ -f "$HERE/config/species_lists/confirmed_species_list.txt" ] && run ": > '$HERE/config/species_lists/confirmed_species_list.txt'"
[ "$KEEP_NOTIF" = 0 ] && for f in apprise.txt apprise-rare.txt apprise-tokens.txt telegram_groups.json; do [ -e "$HERE/config/notifications/$f" ] && run ": > '$HERE/config/notifications/$f'"; done  # the bot token and the app password too

echo "Clearing the identity"
run "sudo sed -i 's/^SITE_NAME=.*/SITE_NAME=\"\"/' $CONF"
[ "$KEEP_PWD" = 0 ] && run "sudo sed -i 's/^CADDY_PWD=.*/CADDY_PWD=\"\"/' $CONF"
run "mkdir -p '$HERE/state' && touch '$HERE/state/firstrun_pending'"
run "sudo hostnamectl set-hostname birdsuite"
run "sudo sed -i 's/\\b$(hostname)\\b/birdsuite/g' /etc/hosts"
run "sudo rm -f /etc/ssh/ssh_host_*"
if systemctl list-unit-files regenerate_ssh_host_keys.service > /dev/null 2>&1; then
  run "sudo systemctl enable regenerate_ssh_host_keys.service"
else
  # no Raspberry Pi OS helper: new keys at the next boot
  run "printf '[Unit]\nDescription=New SSH host keys (cloned card)\nBefore=ssh.service\nConditionPathExistsGlob=!/etc/ssh/ssh_host_*_key\n[Service]\nType=oneshot\nExecStart=/usr/bin/ssh-keygen -A\n[Install]\nWantedBy=multi-user.target\n' | sudo tee /etc/systemd/system/birdsuite-sshkeys.service > /dev/null && sudo systemctl enable birdsuite-sshkeys.service"
fi
run "sudo truncate -s 0 /etc/machine-id && sudo rm -f /var/lib/dbus/machine-id"

echo "Clearing logs"
run "sudo journalctl --rotate > /dev/null 2>&1 && sudo journalctl --vacuum-time=1s > /dev/null 2>&1 || true"
run "rm -f '$HERE'/*.log 2> /dev/null || true"
run "history -c 2> /dev/null || true; rm -f '$HOME/.bash_history'"

echo
echo "Done$( [ "$DRY" = 1 ] && echo ' (dry run)'). Now: sudo shutdown -h now — then copy this card. Each clone comes up as"
echo "BirdSuite-<its MAC> at Home, with its station WiFi on until the setup is done; print its QR label in Settings › Network."
