#!/usr/bin/env bash
# Plan B and the station WiFi address on a real station (owner 2026-10-25: pilot tests before a release).
# The station WiFi (client) is disconnected; within FIELD_LOST_WAIT_SEC (10 s) + a round the field service must turn on
# the station's own WiFi at 10.42.N.1 (field_net.ap_addr(), N from the MAC); with no phone on it, within SCAN_EVERY it
# must go back to the known network by itself (back_home), so a session over ssh reconnects. Everything is written to
# a log, because the ssh connection drops while it runs. Run it detached so it survives that:
#   sudo systemd-run --unit=bs-planb-test bash /home/pi/BirdNET-Pi/tests/field_planb_test.sh
#   (about 5 min later)  cat /home/pi/field_planb_test.log
# Needs: the station only on WiFi (with a cable plugged in it is never "without network"), the AP profile created.
LOG=/home/pi/field_planb_test.log
DIR=/home/pi/BirdNET-Pi/scripts
LIMIT=180
exec > "$LOG" 2>&1
say() { echo "$(date +%T) $*"; }
fail() { say "RESULT: FAIL - $*"; exit 1; }

want=$(python3 -c "import sys; sys.path.insert(0, '$DIR'); import field_net; print(field_net.ap_addr())")
conn=$(nmcli -t -f NAME,DEVICE connection show --active | awk -F: '$2 == "wlan0" {print $1}')
say "expected station WiFi address: $want; WiFi in use: ${conn:-none}"
[ -n "$conn" ] || fail "wlan0 is not connected to a network, nothing to lose"
[ "$conn" != birdsuite-ap ] || fail "the station WiFi is already on"
other=$(nmcli -t -f DEVICE,TYPE,STATE device | awk -F: '$1 != "wlan0" && $2 != "loopback" && $3 == "connected" {print $1}')
[ -z "$other" ] || fail "another connected interface ($other): the station would not be without network"
nmcli -t -f NAME connection show | grep -qx birdsuite-ap || fail "no birdsuite-ap profile (open Settings > Network once)"

restore() {
  nmcli -t -f NAME,DEVICE connection show --active | grep -q "^$conn:wlan0$" && return
  say "restoring: station WiFi off, $conn back"
  python3 "$DIR/field_net.py" ap-off > /dev/null 2>&1
  nmcli connection up "$conn" ifname wlan0 > /dev/null 2>&1
  for _ in $(seq 1 12); do
    sleep 10
    nmcli -t -f NAME,DEVICE connection show --active | grep -q ":wlan0$" && { say "back on: $(nmcli -t -f NAME,DEVICE connection show --active | grep ':wlan0$')"; return; }
  done
  say "WARNING: wlan0 not back after 2 min; the field service brings it back after the station WiFi window"
}
trap restore EXIT

start=$(date +%s)
say "disconnecting wlan0"
nmcli connection down "$conn" || fail "nmcli could not take $conn down"
seen=''
while [ $(( $(date +%s) - start )) -lt $LIMIT ]; do
  sleep 2
  t=$(( $(date +%s) - start ))
  act=$(nmcli -t -f NAME,DEVICE connection show --active | tr '\n' ' ')
  addr=$(ip -4 -o addr show wlan0 2>/dev/null | awk '{print $4}' | tr '\n' ' ')
  say "t=${t}s active: ${act:-none} wlan0: ${addr:-none}"
  if echo "$act" | grep -q "birdsuite-ap:wlan0"; then
    seen=$t
    break
  fi
done
[ -n "$seen" ] || { journalctl -u birdsuite_field --since "@$start" -o cat --no-pager | tail -n 20 | sed 's/^/  field: /'; fail "the station WiFi did not turn on within $((LIMIT / 60)) min"; }
sleep 3
addr=$(ip -4 -o addr show wlan0 | awk '{print $4}' | cut -d/ -f1)
say "station WiFi on after ${seen}s at $addr"
[ "$addr" = "$want" ] || fail "station WiFi at $addr, expected $want"
back=''
while [ $(( $(date +%s) - start )) -lt $((seen + LIMIT)) ]; do
  sleep 5
  nmcli -t -f NAME,DEVICE connection show --active | grep -q "^$conn:wlan0$" && { back=$(( $(date +%s) - start )); break; }
done
journalctl -u birdsuite_field --since "@$start" -o cat --no-pager | tail -n 20 | sed 's/^/  field: /'
[ -n "$back" ] || fail "station WiFi at $want, but the station did not go back to $conn by itself within $((LIMIT / 60)) min"
say "back on $conn by itself after ${back}s"
grep -q "address=/#/$want" /etc/NetworkManager/dnsmasq-shared.d/*.conf 2>/dev/null && say "captive answer points at $want" \
  || say "NOTE: no captive dnsmasq file with $want found"
say "RESULT: PASS - station WiFi after ${seen}s at $want, back on $conn after ${back}s"
