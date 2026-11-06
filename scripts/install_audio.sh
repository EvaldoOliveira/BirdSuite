#!/usr/bin/env bash
################################################################################
# scripts/install_audio.sh - USB MICROPHONE SETUP
#
# PURPOSE:
#   USB microphone setup (US-51b, product version of the pilot's RØDE helper).
#   Finds the microphone chosen in Settings › Audio (REC_MIC, its ALSA card id) when it is plugged in, else the first USB
#   capture card, gives it a shared ALSA device (dsnoop + plug, so
#   recording, livestream and any other recorder can read the same microphone at once)
#   in the station user's ~/.asoundrc, points REC_CARD / CHANNELS of BirdSuite.conf
#   at it and keeps PulseAudio from grabbing the card. Without a USB microphone nothing
#   changes (REC_CARD stays as it is, "default" = PulseAudio).
#   Usage: install_audio.sh [-n]   (-n: dry run, only print what would change)
#   Called by install_birdnet.sh and by mic_hotplug.sh (when a USB microphone is plugged in).
#
# DEPENDENCIES:
#   - scripts/install_birdnet.sh
#   - scripts/mic_hotplug.sh
#   - scripts/paths.sh
#
# USAGE:
#   bash scripts/install_audio.sh
#   Referenced by scripts/audio_input.php, scripts/birdnet_recording.sh, scripts/install_birdnet.sh,
#   scripts/install_config.sh, scripts/mic_hotplug.sh, scripts/raw_recording.sh
################################################################################

source "$(dirname "$(readlink -f "${BASH_SOURCE[0]}")")/paths.sh"
dry_run=
[ "$1" = "-n" ] && dry_run=1
# the station user's own ALSA file (owner 2026-10-23, least privilege): recording and live stream run as that user, so
# no root is needed
user_home=$(getent passwd "${BIRDNET_USER}" | cut -d: -f6)
conf_file=$BS_ASOUNDRC
device=birdnet_mic

# the chosen microphone (REC_MIC = its ALSA card id, stable across replugs, US-07) when present, else the one the station
# has been using (BS_MIC_PREFERRED: kept while it is away, so a replug of two microphones in any order gives the
# analysis the same one back, 2026-11-03), else the first USB capture card: card number from `arecord -l`, USB-ness
# from /proc/asound/cardN/usbid
card=
cards=$(arecord -l 2>/dev/null | grep '^card')
preferred=$(cat "$BS_MIC_PREFERRED" 2>/dev/null)
for want in chosen preferred usb; do
	[ "$want" = chosen ] && [ -z "${REC_MIC:-}" ] && continue
	[ "$want" = preferred ] && { [ -n "${REC_MIC:-}" ] || [ -z "$preferred" ]; } && continue
	while read -r line; do
		n=$(echo "$line" | sed -nE 's/^card ([0-9]+):.*/\1/p')
		[ -n "$n" ] || continue
		if [ "$want" = chosen ]; then [ "$(cat /proc/asound/card${n}/id 2>/dev/null)" = "${REC_MIC}" ] || continue
		elif [ "$want" = preferred ]; then [ "$(cat /proc/asound/card${n}/id 2>/dev/null)" = "$preferred" ] || continue
		else [ -e /proc/asound/card${n}/usbid ] || continue; fi
		card=$n; card_id=$(cat /proc/asound/card${n}/id 2>/dev/null); name=$(echo "$line" | sed -E 's/^card [0-9]+: ([^,]*),.*/\1/')
		break
	done <<< "$cards"
	[ -n "$card" ] && break
done

if [ -z "$card" ]; then
	echo "No USB microphone found - REC_CARD left as '${REC_CARD}'"
	exit 0
fi
# the first microphone the station uses becomes the one it comes back to (only without a choice in Settings › Audio)
[ -z "${REC_MIC:-}" ] && [ -z "$preferred" ] && [ -n "$card_id" ] && printf '%s\n' "$card_id" > "$BS_MIC_PREFERRED"

# channels: mono when the card can only do mono, else 2 (the plug device converts anyway).
# USB audio lists its capture formats in /proc/asound/cardN/stream0, readable while the
# card is busy; arecord's hw params are the fallback
channels=2
max_ch=$(sed -n '/^Capture:/,$p' /proc/asound/card${card}/stream0 2>/dev/null | sed -nE 's/^ *Channels: *([0-9]+).*/\1/p' | sort -n | tail -1)
if [ -z "$max_ch" ]; then
	max_ch=$(timeout 5 arecord -D hw:${card},0 --dump-hw-params -d 1 /dev/null 2>&1 | sed -nE 's/^CHANNELS: *\[?[0-9]+ ?([0-9]*)\]?.*/\1/p')
fi
[ "$max_ch" = "1" ] && channels=1

new_conf="# Managed by BirdSuite scripts/install_audio.sh - USB microphone: ${name} (card ${card})
pcm.birdnet_shared {
  type dsnoop
  ipc_key 7340032
  slave.pcm \"hw:CARD=${card_id:-$card},DEV=0\"
}
pcm.${device} {
  type plug
  slave.pcm \"birdnet_shared\"
}"

echo "USB microphone: ${name} (card ${card}), ${channels} channel(s) -> REC_CARD=${device}"
if [ -n "$dry_run" ]; then
	echo "--- would write ${conf_file}:"; echo "$new_conf"
	echo "--- would set REC_CARD=${device} CHANNELS=${channels} (now REC_CARD=${REC_CARD} CHANNELS=${CHANNELS})"
	exit 0
fi

# as the station user: directly when this runs as that user (the hot-plug service), through sudo -u when root (installer)
asu() { if [ "$(id -un)" = "${BIRDNET_USER}" ]; then "$@"; else sudo -u "${BIRDNET_USER}" "$@"; fi; }
changed=
if [ "$(cat $conf_file 2>/dev/null)" != "$new_conf" ]; then
	echo "$new_conf" | asu tee $conf_file > /dev/null
	changed=1
fi
if [ "${REC_CARD}" != "$device" ] || [ "${CHANNELS}" != "$channels" ]; then
	asu sed -i --follow-symlinks -E "s/^REC_CARD=.*/REC_CARD=${device}/; s/^CHANNELS=.*/CHANNELS=${channels}/" $BS_CONF
	changed=1
fi

# PulseAudio must not take the card: no autospawn for the station user
if [ -n "$user_home" ]; then
	asu mkdir -p "$user_home/.config/pulse"
	printf 'autospawn = no\n' | asu tee "$user_home/.config/pulse/client.conf" > /dev/null
fi

if [ -n "$changed" ] && systemctl is-active --quiet "$BS_SVC_RECORDING.service"; then
	sudo -n "$BS_SVC_HELPER" restart "$BS_SVC_RECORDING.service" "$BS_SVC_LIVESTREAM.service"
fi
echo "Microphone configured"
