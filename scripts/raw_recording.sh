#!/usr/bin/env bash
################################################################################
# scripts/raw_recording.sh - RAW RECORDING SESSION
#
# PURPOSE:
#   Raw recording session (Raw Recording page): long unprocessed WAV files of the dawn chorus or any other window, from one
#   microphone while the analysis keeps running. One group of settings per microphone (its ALSA card id; config/
#   raw_recording.json through scripts/raw_groups.py, the RAW_REC_* defaults of BirdSuite.conf for a new one).
#   The station's microphone (the one its shared device records from) is read through that shared device (REC_CARD),
#   any other one on its own (plughw), so two microphones can record at the same time, one session each.
#   One subfolder per session in the group's folder (empty = BS_RAW_REC, ~/raw-recording), named
#   <start>-<station>-<microphone name> (start YYYY-MM-DD-HHhMMmSSs, station = SITE_NAME), holding its WAV files
#   YYYY-MM-DD-<time>-<station>.wav, the group's segment length each, and the session log <folder name>.recording.
#   Started by the station user's crontab (raw_recording_cron.sh) at the group's start; records until its end (next
#   day when the end is earlier than the start).
#   Usage: raw_recording.sh <microphone ALSA id> [--now]   (--now: start immediately, still stopping at the end time)
#
# DEPENDENCIES:
#   - scripts/raw_groups.py
#   - scripts/raw_recording_cron.sh
#   - scripts/paths.sh
#   - scripts/install_audio.sh
#
# USAGE:
#   bash scripts/raw_recording.sh
#   Referenced by scripts/external_files.py, scripts/mic_event.py, scripts/raw_groups.py,
#   scripts/raw_recording.php
################################################################################

source "$(dirname "$(readlink -f "${BASH_SOURCE[0]}")")/paths.sh"
# the sample depth of the station's recordings (owner 2026-11-04, REC_BITS: 24 by default)
raw_fmt=S16_LE; [ "${REC_BITS:-24}" = 24 ] && raw_fmt=S24_3LE
mic="${1:-}"
now="${2:-}"
[[ "$mic" =~ ^[A-Za-z_][A-Za-z0-9_]{0,31}$ ]] || { echo "Usage: raw_recording.sh <microphone ALSA id> [--now]"; exit 2; }
card_no=$(readlink "/proc/asound/$mic" 2> /dev/null); card_no="${card_no#card}"
[ -d "/proc/asound/$mic" ] || { logger -t birdsuite-raw "raw recording not started: microphone $mic not plugged in"; echo "Microphone $mic not plugged in"; exit 1; }
# the group (RR_ENABLED, RR_DAYS, RR_RECURRENT, RR_START, RR_END, RR_SEGMENT, RR_RATE, RR_DIR), every value checked
eval "$(python3 "$BS_SCRIPTS/raw_groups.py" get "$mic")" || exit 1
out_dir="${RR_DIR:-$BS_RAW_REC}"
out_dir="${out_dir%/}"
# a chosen folder must be there and writable; one under /mnt or /media must be on its own mounted drive or share,
# never the empty mount point on the card (the drive or the NAS not mounted)
if [ -n "$RR_DIR" ]; then
	why=
	[ -d "$out_dir" ] && [ -w "$out_dir" ] || why="not found or not writable"
	case "$out_dir" in /mnt/*|/media/*) [ -z "$why" ] && [ "$(findmnt -n -o TARGET -T "$out_dir")" = / ] && why="its drive or share is not mounted" ;; esac
	if [ -n "$why" ]; then
		logger -t birdsuite-raw "raw recording not started: $out_dir $why"
		echo "The raw recording folder $out_dir: $why"
		exit 1
	fi
fi
mkdir -p "$out_dir" || exit 1
name="${SITE_NAME:-$(hostname)}"
name="${name// /_}"
name="${name//[^A-Za-z0-9_-]/}"
[ -n "$name" ] || name=$(hostname)
# the microphone's name in the session folder (owner 2026-11-02): its card name in ASCII, words joined by _ (two cards
# with the same name: the ALSA id is added)
mic_long=$(sed -n "s/^ *${card_no:-x} \[[^]]*\]: [^ ]* - //p" /proc/asound/cards 2> /dev/null | head -n1)
tag=$(printf '%s' "$mic_long" | LC_ALL=C.UTF-8 iconv -f utf-8 -t ascii//TRANSLIT 2> /dev/null | sed 's/[^A-Za-z0-9]\+/_/g; s/^_//; s/_$//')
[ -n "$tag" ] || tag="$mic"
[ "$(grep -cF -- " - $mic_long" /proc/asound/cards)" -gt 1 ] && tag="${tag}_$mic"
# the station's microphone: the card of its shared device (the station user's ALSA file written by install_audio.sh)
station_mic=$(sed -n 's/.*hw:CARD=\([A-Za-z0-9_]*\).*/\1/p' "$BS_ASOUNDRC" 2> /dev/null | head -n1)
if [ "$mic" = "$station_mic" ]; then device="${REC_CARD:-default}"; else device="plughw:CARD=$mic,DEV=0"; fi
rate="$RR_RATE"
channels="${CHANNELS:-1}"
segment_s=$(( RR_SEGMENT * 60 ))
lock="/tmp/birdsuite_raw_recording_$mic.lock"

# one session at a time for this microphone
exec 9> "$lock"
if ! flock -n 9; then
	echo "A raw recording session is already running"
	exit 0
fi

to_s() { local IFS=:; set -- $1; echo $(( 10#${1:-0} * 3600 + 10#${2:-0} * 60 + 10#${3:-0} )); }
start_s=$(to_s "$RR_START")
end_s=$(to_s "$RR_END")
today=$(date -d "today 00:00" +%s)

if [ "$now" != "--now" ]; then
	# cron starts on the minute: wait for the configured second
	target=$(( today + start_s ))
	now=$(date +%s)
	[ $target -gt $now ] && [ $(( target - now )) -lt 120 ] && sleep $(( target - now ))
fi
end=$(( today + end_s ))
[ $end_s -le $start_s ] && end=$(( end + 86400 ))
# "Record now" after today's end time (2026-10-23 test run: it recorded until tomorrow's end, about 23 h): for as long as
# the scheduled window lasts, from now
if [ "$now" = "--now" ] && [ $end -le $(date +%s) ]; then
	end=$(( $(date +%s) + ( (end_s - start_s + 86400) % 86400 ) ))
fi
[ $end -le $(date +%s) ] && { echo "The end time has already passed"; exit 0; }

session=$(date +%Y-%m-%d-%Hh%Mm%Ss)
out_dir="$out_dir/${session}-${name}-${tag}"
mkdir -p "$out_dir" || exit 1
log_file="$out_dir/${session}-${name}-${tag}.recording"
{
	echo "############## Start ##############"
	echo "Recording until:     $(date -d "@$end" +%Y-%m-%d-%Hh%Mm%Ss) in blocks of $segment_s seconds"
	echo "Start of recording:  $session"
	echo "----"
	echo "TARGET_PATH          $out_dir"
	echo "DEVICE_NAME          $name"
	echo "DEVICE               $device"
	echo "RATE                 $rate"
	echo "Format               ${raw_fmt}"
	echo "Channels             $channels"
	echo "----"
	echo "SEGMENT_MINUTES      $RR_SEGMENT"
	echo "SCHEDULE             $RR_START to $RR_END, days $RR_DAYS (0 = Sunday), recurrent $RR_RECURRENT, started by $([ "$now" = "--now" ] && echo "Record now" || echo "the schedule")"
	echo "----"
	echo "STATION              ${SITE_NAME:-$(hostname)}"
	echo "HOSTNAME             $(hostname)"
	echo "LATITUDE             $LATITUDE"
	echo "LONGITUDE            $LONGITUDE"
	echo "TIMEZONE             $(timedatectl show -p Timezone --value 2> /dev/null) (UTC$(date +%:z))"
	echo "SOFTWARE             BirdSuite $(git -C "$BS_PROG" describe --tags --always 2> /dev/null)"
	echo "MODEL                ${MODEL:-}"
	# the analysis settings in force and the scene's id (owner 2026-11-04): the same as every detection keeps, so the
	# session can be analysed later exactly as the station would have; config/data/scenarios/<id>.json has the rest
	echo "MIN_CONFIDENCE       ${CONFIDENCE:-}"
	echo "SENSITIVITY          ${SENSITIVITY:-}"
	echo "OVERLAP              ${OVERLAP:-}"
	echo "LOCATION_THRESHOLD   ${SF_THRESH:-}"
	echo "SPECIES_LIST         ${SPECIES_LIST:-}"
	echo "SCENARIO             $("$BS_PYTHON" -c 'import sys; sys.path.insert(0, sys.argv[1])
from utils.helpers import get_settings
from utils.reporting import scenario
print(scenario(get_settings())["id"])' "$BS_SCRIPTS" 2> /dev/null)"
	echo "OS                   $(. /etc/os-release 2> /dev/null && echo "$PRETTY_NAME"), kernel $(uname -r)"
	echo "----"
	# the microphone: the card the device names, its name and the capture volume (the root helper reads the mixer)
	echo "MICROPHONE           $mic$([ "$mic" = "$station_mic" ] && echo " (the station's, shared)" || echo " (on its own)")"
	gain=$(sudo -n "$BS_ROOT_HELPER" mic-gain "$mic" 2> /dev/null)
	cardno=$(echo "$gain" | sed -n 's/^card=\([0-9]*\) .*/\1/p')
	[ -n "$cardno" ] && echo "MICROPHONE_NAME      $(sed -n "s/^ *$cardno \[[^]]*\]: [^ ]* - //p" /proc/asound/cards 2> /dev/null | head -n1)"
	# the USB port it is plugged into and its speed (owner 2026-11-04), as the detections' Mic_Port
	if [ -n "$cardno" ]; then
		mdev=$(readlink -f "/sys/class/sound/card$cardno/device" 2> /dev/null)
		if [[ $mdev =~ /usb[0-9]+/.*/([0-9]+-[0-9.]+):[0-9.]+$ ]]; then
			echo "MICROPHONE_PORT      USB ${BASH_REMATCH[1]} ($(cat "$(dirname "$mdev")/speed" 2> /dev/null) Mbit/s)"
		else
			echo "MICROPHONE_PORT      built-in"
		fi
	fi
	echo "MICROPHONE_GAIN      ${gain:-unknown}"
	echo "----"
	# the location the station is at (Tools › Locations): every field of its record but the page's own looks
	python3 - "$BS_LOCATIONS_FILE" "${LOCATION_ID:-}" "${LATITUDE:-0}" "${LONGITUDE:-0}" << 'PY' 2> /dev/null || echo "LOCATION             unknown"
import json, math, sys
locs = json.load(open(sys.argv[1]))
want, lat, lon = sys.argv[2].strip('"\''), float(sys.argv[3] or 0), float(sys.argv[4] or 0)
loc = next((l for l in locs if str(l.get('id')) == want), None)
if loc is None:
    near = [(math.hypot(l['lat'] - lat, (l['lon'] - lon) * math.cos(math.radians(lat))) * 111.2, l) for l in locs if 'lat' in l and 'lon' in l]
    near = [n for n in near if n[0] <= float(n[1].get('radius_km') or 0)]
    loc = min(near, key=lambda n: n[0])[1] if near else None
if loc is None:
    print('LOCATION             none (no location covers these coordinates)')
else:
    for k, v in loc.items():
        if k == 'theme' or k.startswith('now_background'):
            continue
        print('%-20s %s' % ('LOCATION_' + k.upper(), json.dumps(v, ensure_ascii=False) if isinstance(v, (dict, list)) else v))
PY
} > "$log_file"

stopping=
waiting=
trap 'stopping=1; kill "$rec" 2> /dev/null' TERM INT
while [ "$(date +%s)" -lt "$end" ] && [ -z "$stopping" ]; do
	left=$(( end - $(date +%s) ))
	length=$(( left < segment_s ? left : segment_s ))
	[ $length -lt 1 ] && break
	# this session's own microphone only (2026-11-03 RAW-09: with the station's microphone unplugged its shared device
	# moved to the other microphone and the session went on recording that one under this name): wait while the card is
	# away; each part takes the shared device while it points at this microphone (the station records it too), else the
	# microphone itself
	if [ ! -d "/proc/asound/$mic" ]; then
		[ -n "$waiting" ] || echo "$(date +%Y-%m-%d-%Hh%Mm%Ss) microphone $mic away - waiting for it" >> "$log_file"
		waiting=1
		sleep 10
		continue
	fi
	[ -n "$waiting" ] && echo "$(date +%Y-%m-%d-%Hh%Mm%Ss) microphone $mic back - recording again" >> "$log_file"
	waiting=
	if grep -qs "hw:CARD=$mic," "$BS_ASOUNDRC"; then device="${REC_CARD:-default}"; else device="plughw:CARD=$mic,DEV=0"; fi
	stamp=$(date +%Y-%m-%d-%Hh%Mm%Ss)
	wav="$out_dir/${stamp}-${name}.wav"
	# in the background so that Stop recording (TERM to this script) ends the WAV being written at once
	arecord -q -D "$device" -f "$raw_fmt" -c "$channels" -r "$rate" -d "$length" "$wav" 2>> "$log_file" &
	rec=$!
	if ! wait "$rec"; then
		[ -n "$stopping" ] && break
		echo "$(date +%Y-%m-%d-%Hh%Mm%Ss) recording error (microphone unplugged?) - retrying in 10 s" >> "$log_file"
		rm -f "$wav"
		sleep 10
	fi
done
# stopped: let arecord close the WAV (it writes the real length into the header)
[ -n "$stopping" ] && wait "$rec" 2> /dev/null
# the WAV cut short keeps the planned length in its header: write the real sizes (RIFF and data chunk of arecord's
# 44-byte header) so every player shows and plays the real length
[ -n "$stopping" ] && [ -f "$wav" ] && python3 - "$wav" << 'PY' 2> /dev/null
import os, struct, sys
f = sys.argv[1]
n = os.path.getsize(f)
with open(f, 'r+b') as w:
    h = w.read(44)
    if n > 44 and h[:4] == b'RIFF' and h[36:40] == b'data':
        w.seek(4); w.write(struct.pack('<I', n - 8))
        w.seek(40); w.write(struct.pack('<I', n - 44))
PY
trap - TERM INT
# the audio arecord lost in the session (owner 2026-11-04): its "overrun" lines above, counted and summed
loss_n=$(grep -c 'overrun' "$log_file" 2> /dev/null)
loss_ms=$(sed -n 's/.*overrun.*at least \([0-9.]*\) ms.*/\1/p' "$log_file" 2> /dev/null | awk '{s += $1} END {printf "%.3f", s}')
echo "AUDIO_LOSS           ${loss_n:-0} events, ${loss_ms:-0} ms" >> "$log_file"
echo "############## End $(date +%Y-%m-%d-%Hh%Mm%Ss) ##############" >> "$log_file"
# stopped by hand: the schedule stays as it is
[ -n "$stopping" ] && exit 0

# a one-time schedule switches itself off after its session
if [ "$RR_RECURRENT" = "0" ] && [ "$now" != "--now" ]; then
	python3 "$BS_SCRIPTS/raw_groups.py" disable "$mic"
	"$BS_SCRIPTS/raw_recording_cron.sh"
fi
