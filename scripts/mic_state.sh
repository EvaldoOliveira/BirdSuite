#!/usr/bin/env bash
# mic_state.sh - the microphone the station records from, written to state/microphone.json (owner 2026-11-04): its
# ALSA id, its name, the USB port it is plugged into and its speed, and the capture gain. Every detection keeps these
# (scripts/utils/reporting.py: the database columns Mic, Mic_Port, Mic_Gain and the clip's tags): the analysis runs
# sandboxed and cannot read the sound card itself. Run by the field service once a minute, by the microphone hot-plug
# and by the pages after a change of microphone or gain. As the station user; the gain through birdsuite-root
# mic-gain (read only). Writes only when something changed.
source "$(dirname "$(readlink -f "${BASH_SOURCE[0]}")")/paths.sh"
out=$BS_STATE/microphone.json
id=$(sed -n 's/.*hw:CARD=\([A-Za-z0-9_]*\).*/\1/p' "$BS_ASOUNDRC" 2> /dev/null | head -n1)
n=
for d in /proc/asound/card[0-9]*; do
  [ -r "$d/id" ] || continue
  [ "$(cat "$d/id")" = "$id" ] && n=${d##*card} && break
done
name= port= speed= pct= db=
if [ -n "$n" ]; then
  name=$(sed -n "s/^ *$n \[[^]]*\]: [^ ]* - //p" /proc/asound/cards 2> /dev/null | head -n1)
  dev=$(readlink -f "/sys/class/sound/card$n/device" 2> /dev/null)
  if [[ $dev =~ /usb[0-9]+/.*/([0-9]+-[0-9.]+):[0-9.]+$ ]]; then
    port=${BASH_REMATCH[1]}
    speed=$(cat "$(dirname "$dev")/speed" 2> /dev/null)
  fi
  g=$(sudo -n "$BS_ROOT_HELPER" mic-gain "$id" 2> /dev/null)
  [[ $g =~ percent=([0-9]+) ]] && pct=${BASH_REMATCH[1]}
  [[ $g =~ db=(-?[0-9.]+) ]] && db=${BASH_REMATCH[1]}
fi
new=$(python3 -c 'import json, sys
a = sys.argv[1:]
print(json.dumps({"id": a[0], "name": a[1], "port": a[2], "speed": int(a[3]) if a[3].isdigit() else None,
                  "gain": int(a[4]) if a[4].isdigit() else None, "gain_db": float(a[5]) if a[5] else None}, ensure_ascii=False))' \
  "$id" "$name" "$port" "$speed" "$pct" "$db")
[ -f "$out" ] && [ "$(cat "$out" 2> /dev/null)" = "$new" ] && exit 0
mkdir -p "$BS_STATE"
printf '%s\n' "$new" > "$out.tmp" && mv -f "$out.tmp" "$out"
