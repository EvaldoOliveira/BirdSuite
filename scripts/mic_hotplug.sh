#!/usr/bin/env bash
# USB microphone hot-plug (US-51b, product version of US-44). Run by birdnet_mic_hotplug.service,
# which a udev rule restarts on every USB sound card add/remove. State-driven: it reads the
# current state, so duplicate or out-of-order events are harmless.
#   no USB capture card  -> stop recording and livestream at once (no crash loop without a mic); the analysis
#                           keeps running until the recordings already waiting are analysed, then stops too
#   USB capture card     -> install_audio.sh (shared ALSA device, REC_CARD) and start them
# Notifies through the station's Apprise configuration when the state changes.
# Runs as the station user (owner 2026-10-23, least privilege): services through birdsuite-svc, the ALSA file is that
# user's own ~/.asoundrc.
source "$(dirname "$(readlink -f "${BASH_SOURCE[0]}")")/paths.sh"
services="birdnet_recording.service birdnet_analysis.service livestream.service"
mic_services="birdnet_recording.service livestream.service"
home_dir=$(getent passwd "${BIRDNET_USER}" | cut -d: -f6)

# through the station's notification module (2026-10-23 test run): the boxes hold $telegram_bot / $email_* placeholders
# that only it fills from the token store; offline the message is queued and sent later
notify() {
  local py=$home_dir/BirdNET-Pi/birdnet/bin/python3
  [ -x "$py" ] || return 0
  timeout 90 "$py" -c 'import sys; sys.path.insert(0, sys.argv[1]); from utils import notifications; notifications.notify(sys.argv[2], sys.argv[3], queue=True)' \
    "$home_dir/BirdNET-Pi/scripts" "$1" "BirdSuite ${SITE_NAME:-$HOSTNAME}" > /dev/null 2>&1 || true
}

sleep 2 # let the kernel finish adding/removing the card nodes
usb_mic=
for usbid in /proc/asound/card*/usbid; do
  [ -e "$usbid" ] || continue
  card_dir=$(dirname "$usbid")
  grep -q '^Capture:' "$card_dir"/stream* 2>/dev/null && { usb_mic=1; break; }
done

active=0
for svc in $services; do systemctl is-active --quiet "$svc" && active=$((active + 1)); done
# with the microphone gone, a unit restarting in a loop (arecord failed a moment before the remove event: "activating")
# counts as running too, else it was never stopped (2026-10-26: 270 restarts in 30 min)
running() { case "$(systemctl is-active "$1" 2>/dev/null)" in active|activating|reloading|deactivating) return 0 ;; esac; return 1; }
up=0
for svc in $services; do running "$svc" && up=$((up + 1)); done
mic_active=0
for svc in $mic_services; do running "$svc" && mic_active=$((mic_active + 1)); done

# back after a removal (the udev remove event writes state/.mic_removed at once, owner 2026-10-24): every page asks
# whether the station is still at the same location (US-58), also when the station kept running (a quick replug)
ask_location() {
  if [ -f "$home_dir/BirdNET-Pi/state/.mic_removed" ]; then
    rm -f "$home_dir/BirdNET-Pi/state/.mic_removed"
    # ts = seconds since 1970 (owner 2026-10-24: the page read the local "at" as UTC and closed the question at once)
    printf '{"at": "%s", "ts": %s}\n' "$(date '+%Y-%m-%d %H:%M:%S')" "$(date +%s)" > "$home_dir/BirdNET-Pi/state/mic_reconnected.json"
    chmod u=rw,g=rw,o=r "$home_dir/BirdNET-Pi/state/mic_reconnected.json"
    # the network position now, for a new location's coordinates in the question (owner 2026-10-24); in the background
    ( timeout 60 "$home_dir/BirdNET-Pi/birdnet/bin/python3" "$home_dir/BirdNET-Pi/scripts/location_check.py" > /dev/null 2>&1 & )
  fi
}

if [ -n "$usb_mic" ]; then
  [ "$active" -eq 3 ] && { ask_location; echo "USB microphone present, station running - nothing to do"; exit 0; }
  echo "USB microphone plugged in - configuring and starting the station"
  sleep 3 # let ALSA settle
  "$home_dir/BirdNET-Pi/scripts/install_audio.sh" || echo "install_audio.sh failed - starting anyway"
  sudo -n /usr/local/sbin/birdsuite-svc start $services
  logger -t birdnet-mic "USB microphone plugged in - recording, analysis and livestream started"
  ask_location
  notify "USB microphone connected - recording, analysis and livestream running"
else
  [ "$up" -eq 0 ] && { echo "No USB microphone, station stopped - nothing to do"; exit 0; }
  # only a station that records from a USB microphone stops: RTSP or a non-USB card keeps running
  if [ -n "${RTSP_STREAM}" ] || [ "${REC_CARD}" != "birdnet_mic" ]; then
    echo "No USB microphone, but the station does not record from one (REC_CARD=${REC_CARD}) - nothing to do"
    exit 0
  fi
  if [ "$mic_active" -gt 0 ]; then
    echo "USB microphone removed - stopping recording and livestream (the analysis finishes the waiting recordings)"
    sudo -n /usr/local/sbin/birdsuite-svc stop $mic_services
    logger -t birdnet-mic "USB microphone removed - recording and livestream stopped, analysis empties the queue"
    notify "USB microphone removed - recording and livestream stopped until it is plugged in again; the recordings already waiting are still analysed"
  fi
  # wait while the analysis works through StreamData (a replug restarts this unit); 12 h cap
  for _ in $(seq 1 2880); do
    ls /proc/asound/card*/usbid > /dev/null 2>&1 && grep -qs '^Capture:' /proc/asound/card*/stream* && exit 0
    systemctl is-active --quiet birdnet_analysis.service || break
    compgen -G "$home_dir/BirdSongs/StreamData/*.wav" > /dev/null || break
    sleep 15
  done
  sudo -n /usr/local/sbin/birdsuite-svc stop birdnet_analysis.service
  logger -t birdnet-mic "queue empty - analysis stopped until the microphone returns"
fi
