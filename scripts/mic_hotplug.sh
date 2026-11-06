#!/usr/bin/env bash
################################################################################
# scripts/mic_hotplug.sh - USB MICROPHONE HOT-PLUG
#
# PURPOSE:
#   USB microphone hot-plug (US-51b, product version of US-44). Run by the microphone hot-plug unit (BS_SVC_MIC_HOTPLUG),
#   which a udev rule restarts on every USB sound card add/remove. State-driven: it reads the
#   current state, so duplicate or out-of-order events are harmless.
#     no USB capture card  -> stop recording and livestream at once (no crash loop without a mic); the analysis
#                             keeps running until the recordings already waiting are analysed, then stops too
#     USB capture card     -> install_audio.sh (shared ALSA device, REC_CARD) and start them; with the station running
#                             install_audio.sh still runs, so the device follows the microphone chosen in Settings › Audio
#                             (REC_MIC): back to it when it returns, to the first USB one while it is away (US-07)
#   Notifies through the station's Apprise configuration when the state changes.
#   Runs as the station user (owner 2026-10-23, least privilege): services through birdsuite-svc, the ALSA file is that
#   user's own ~/.asoundrc.
#
# DEPENDENCIES:
#   - scripts/install_audio.sh
#   - scripts/paths.sh
#   - scripts/mic_event.py
#   - scripts/mic_state.sh
#   - scripts/location_check.py
#   - birdsuite-svc (sudo helper)
#
# USAGE:
#   bash scripts/mic_hotplug.sh
#   Referenced by scripts/install_audio.sh, scripts/install_services.sh, scripts/lib/station.php,
#   scripts/location_switch.php, scripts/mic_event.py, scripts/recording_watchdog.py,
#   templates/units/SVC_MIC_HOTPLUG.service
################################################################################

source "$(dirname "$(readlink -f "${BASH_SOURCE[0]}")")/paths.sh"
services="$BS_SVC_RECORDING.service $BS_SVC_ANALYSIS.service $BS_SVC_LIVESTREAM.service"
mic_services="$BS_SVC_RECORDING.service $BS_SVC_LIVESTREAM.service"

# through the station's notification module (2026-10-23 test run): the boxes hold $telegram_bot / $email_* placeholders
# that only it fills from the token store; offline the message is queued and sent later
notify() {
	local py=$BS_PYTHON
	[ -x "$py" ] || return 0
	timeout 90 "$py" -c 'import sys; sys.path.insert(0, sys.argv[1]); from utils import notifications; notifications.notify(sys.argv[2], sys.argv[3], queue=True)' \
		"$BS_SCRIPTS" "$1" "BirdSuite ${SITE_NAME:-$HOSTNAME}" > /dev/null 2>&1 || true
}

# the station's microphone before this event, then, once the station follows the change, the microphones by name and
# what used them for the window of every page and the notification (scripts/mic_event.py, 2026-11-03)
mic_before=$("$BS_PYTHON" "$BS_SCRIPTS/mic_event.py" before 2>/dev/null)
mic_said=
mic_after() {
	local summary
	summary=$("$BS_PYTHON" "$BS_SCRIPTS/mic_event.py" after "$mic_before" 2>/dev/null)
	[ -n "$summary" ] || return 0
	logger -t birdnet-mic "$summary"
	notify "$summary"
	mic_said=1
}

sleep 2 # let the kernel finish adding/removing the card nodes
# the microphone the detections keep (state/microphone.json, owner 2026-11-04), now and again once the device follows
"$BS_SCRIPTS/mic_state.sh" > /dev/null 2>&1 || true
( sleep 20; "$BS_SCRIPTS/mic_state.sh" > /dev/null 2>&1 ) &
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
	if [ -f "$BS_MIC_REMOVED" ]; then
		rm -f "$BS_MIC_REMOVED"
		# ts = seconds since 1970 (owner 2026-10-24: the page read the local "at" as UTC and closed the question at once)
		printf '{"at": "%s", "ts": %s}\n' "$(date '+%Y-%m-%d %H:%M:%S')" "$(date +%s)" > "$BS_MIC_RECONNECTED"
		chmod u=rw,g=rw,o=r "$BS_MIC_RECONNECTED"
		# the network position now, for a new location's coordinates in the question (owner 2026-10-24); in the background
		( timeout 60 "$BS_PYTHON" "$BS_SCRIPTS/location_check.py" > /dev/null 2>&1 & )
	fi
}

if [ -n "$usb_mic" ]; then
	if [ "$active" -eq 3 ]; then
		# another card came or went (US-07): the shared device points at the chosen microphone when it is there, else the
		# first USB one; install_audio.sh restarts recording and livestream only when that changed
		"$BS_SCRIPTS/install_audio.sh" > /dev/null 2>&1 || true
		mic_after
		ask_location; echo "USB microphone present, station running"; exit 0
	fi
	echo "USB microphone plugged in - configuring and starting the station"
	sleep 3 # let ALSA settle
	"$BS_SCRIPTS/install_audio.sh" || echo "install_audio.sh failed - starting anyway"
	sudo -n /usr/local/sbin/birdsuite-svc start $services
	logger -t birdnet-mic "USB microphone plugged in - recording, analysis and livestream started"
	ask_location
	mic_after
	[ -n "$mic_said" ] || notify "USB microphone connected - recording, analysis and livestream running"
else
	[ "$up" -eq 0 ] && { mic_after; echo "No USB microphone, station stopped - nothing to do"; exit 0; }
	# only a station that records from a USB microphone stops: RTSP or a non-USB card keeps running
	if [ -n "${RTSP_STREAM}" ] || [ "${REC_CARD}" != "birdnet_mic" ]; then
		echo "No USB microphone, but the station does not record from one (REC_CARD=${REC_CARD}) - nothing to do"
		mic_after
		exit 0
	fi
	if [ "$mic_active" -gt 0 ]; then
		echo "USB microphone removed - stopping recording and livestream (the analysis finishes the waiting recordings)"
		sudo -n /usr/local/sbin/birdsuite-svc stop $mic_services
		logger -t birdnet-mic "USB microphone removed - recording and livestream stopped, analysis empties the queue"
		mic_after
		[ -n "$mic_said" ] || notify "USB microphone removed - recording and livestream stopped until it is plugged in again; the recordings already waiting are still analysed"
	else
		mic_after
	fi
	# wait while the analysis works through StreamData (a replug restarts this unit); 12 h cap
	for _ in $(seq 1 2880); do
		ls /proc/asound/card*/usbid > /dev/null 2>&1 && grep -qs '^Capture:' /proc/asound/card*/stream* && exit 0
		systemctl is-active --quiet "$BS_SVC_ANALYSIS.service" || break
		compgen -G "$BS_STREAMDATA/*.wav" > /dev/null || break
		sleep 15
	done
	sudo -n "$BS_SVC_HELPER" stop "$BS_SVC_ANALYSIS.service"
	logger -t birdnet-mic "queue empty - analysis stopped until the microphone returns"
fi
