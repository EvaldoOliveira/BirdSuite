#!/usr/bin/env bash
# First-run settings of a new installation (US-51c). Sourced by install_config.sh before it
# writes BirdSuite.conf, only when there is no BirdSuite.conf yet. Nothing is asked on the terminal (owner
# 2026-11-03: the questions are the browser's, on the first visit — First Run (System › All settings, Start the First Run)); every value comes from, in order:
#   1. a seed file for batch installs, the first that exists of
#      /boot/firmware/birdnet-setup.conf, /boot/birdnet-setup.conf, $HOME/birdnet-setup.conf
#      (KEY=value lines: SITE_NAME LATITUDE LONGITUDE TIMEZONE MODEL LANGUAGE STATE WEB_PASSWORD HOTSPOT_NAME
#       HOTSPOT_PASSWORD STATION_WIFI_PASSWORD STREAM_PASSWORD BIRDWEATHER_ID APPRISE_URL MULTI_LOCATION MULTI_STATION — see
#       docs/birdnet-setup.conf.example)
#   2. a default: the ip-api.com answer of install_config.sh (location, country, state)
# Without a seed file the web interface opens on First Run (System › All settings, Start the First Run) until it is saved.
# Sets FR_* variables for install_config.sh. Secrets are never traced into the install log.

case $- in *x*) fr_xtrace=1 ;; *) fr_xtrace= ;; esac
set +x


# seed file: plain KEY=value lines, read (never sourced), only the known keys
declare -A fr_seed=()
for f in /boot/firmware/birdnet-setup.conf /boot/birdnet-setup.conf "$HOME/birdnet-setup.conf"; do
  [ -r "$f" ] || continue
  echo "First-run answers read from $f"
  while IFS='=' read -r key value; do
    key=$(echo "$key" | tr -d '[:space:]')
    case "$key" in
      SITE_NAME|LATITUDE|LONGITUDE|TIMEZONE|MODEL|LANGUAGE|STATE|WEB_PASSWORD|HOTSPOT_NAME|HOTSPOT_PASSWORD|STATION_WIFI_PASSWORD|STREAM_PASSWORD|BIRDWEATHER_ID|APPRISE_URL|MULTI_LOCATION|MULTI_STATION|UI_PRESET|UI_LEVEL|UI_CONTEXT|LAN_TRUST|WEB_LOGIN|SET_[A-Z]*)
        value="${value%$'\r'}"; value="${value#\"}"; value="${value%\"}"
        fr_seed[$key]="$value"
        ;;
    esac
  done < <(grep -E '^[[:space:]]*[A-Z_]+=' "$f")
  break
done

# fr_val VAR SEED_KEY "default" — the seed file's value, else the default
fr_val() {
  local var=$1 seed_key=$2 default=$3 answer=
  [ -n "$seed_key" ] && answer=${fr_seed[$seed_key]-}
  # no control characters in a value that goes into BirdSuite.conf
  answer=$(printf '%s' "$answer" | tr -d '\000-\037\177')
  printf -v "$var" '%s' "${answer:-$default}"
}

fr_country=; fr_region=; fr_tz=
if [ -n "$json" ] && [ "$(echo "$json" | jq -r .status 2>/dev/null)" = "success" ]; then
  fr_country=$(echo "$json" | jq -r .countryCode)
  fr_region=$(echo "$json" | jq -r .region)
  fr_tz=$(echo "$json" | jq -r .timezone)
fi

# empty = BirdSuite-<last 4 of the MAC> (install_config.sh, get_sitename): the board's own name, so cloned cards and stations made
# from one answer file differ
fr_val FR_SITE_NAME SITE_NAME ""
fr_val FR_LATITUDE LATITUDE "$LATITUDE"
fr_val FR_LONGITUDE LONGITUDE "$LONGITUDE"
if ! [[ "$FR_LATITUDE" =~ ^-?[0-9]+(\.[0-9]+)?$ && "$FR_LONGITUDE" =~ ^-?[0-9]+(\.[0-9]+)?$ ]]; then
  echo "Latitude/longitude of the seed file must be numbers: the network's estimate is used" >&2
  FR_LATITUDE=$LATITUDE; FR_LONGITUDE=$LONGITUDE
fi

# timezone: detections are stamped with the system clock — a fresh Raspberry Pi OS is on
# Europe/London (US-51d)
fr_tz_now=$(timedatectl show --value --property=Timezone 2>/dev/null || cat /etc/timezone 2>/dev/null || true)
fr_val FR_TIMEZONE TIMEZONE "${fr_tz:-$fr_tz_now}"
if [ -n "$FR_TIMEZONE" ] && [ ! -e "/usr/share/zoneinfo/$FR_TIMEZONE" ]; then
  echo "Unknown timezone '$FR_TIMEZONE' (e.g. America/Sao_Paulo): the clock's timezone stays" >&2
  FR_TIMEZONE=
fi

fr_val fr_model MODEL "V3"
case "${fr_model,,}" in
  v2*|2*|*v2.4*)
    FR_MODEL=BirdNET_GLOBAL_6K_V2.4_Model_FP16; FR_CONFIDENCE=0.7; FR_SENSITIVITY=1.25; FR_SF_THRESH=0.03; FR_OVERLAP=0.0 ;;
  *)
    # V3: the values of factory_defaults.conf as they are (2026-10-29: the installer wrote the 2026-10-22 values 0.2 / 0.03
    # over the calibrated defaults of v0.16.0); empty = not replaced (install_config.sh set_default)
    FR_MODEL=BirdNET-Plus_V3.0-preview3.1_Global_10K; FR_CONFIDENCE=; FR_SENSITIVITY=; FR_SF_THRESH=; FR_OVERLAP= ;;
esac

case "$fr_country" in
  BR) fr_lang_default=pt_BR ;;
  PT) fr_lang_default=pt_PT ;;
  *) fr_lang_default=en ;;
esac
fr_langs=$(ls "$my_dir"/model/l18n/labels_*.json 2>/dev/null | sed -E 's/.*labels_(.*)\.json/\1/' | tr '\n' ' ')
fr_val FR_LANGUAGE LANGUAGE "$fr_lang_default"
if [ ! -f "$my_dir/model/l18n/labels_${FR_LANGUAGE}.json" ]; then
  echo "No species names for '$FR_LANGUAGE' (${fr_langs% }): English" >&2
  FR_LANGUAGE=en
fi

FR_REGION=
# the state of the CONFIRMED coordinates (OpenStreetMap online, the shipped IBGE boundaries
# offline) — the network's guess (ip-api region) only when that finds nothing
fr_located=$(python3 "$my_dir/scripts/locate_state.py" "$FR_LATITUDE" "$FR_LONGITUDE" 2>/dev/null || true)
if [ -n "$fr_located" ] || [ "$fr_country" = "BR" ] || [ "$FR_LANGUAGE" = "pt_BR" ] || [ -n "${fr_seed[STATE]+set}" ]; then
  if [ -n "$fr_located" ]; then
    fr_state_default=$fr_located
  elif [ "$fr_country" = "BR" ] && [ "$FR_LATITUDE" = "$LATITUDE" ] && [ "$FR_LONGITUDE" = "$LONGITUDE" ]; then
    fr_state_default=$fr_region
  else
    fr_state_default=none
  fi
  fr_val fr_state STATE "$fr_state_default"
  fr_state=$(echo "$fr_state" | tr '[:lower:]' '[:upper:]' | tr -d ' ')
  fr_state=${fr_state#BR-}
  case "$fr_state" in
    ""|NONE|N|NO) ;;
    *) if [ -f "$my_dir/model/include_lists/BR-${fr_state}.txt" ]; then FR_REGION="BR-${fr_state}"
       else echo "No include list for '$fr_state'" >&2; fi ;;
  esac
fi

# simple mode (owner 2026-10-29, US-125): several locations and several stations; no = their controls stay hidden in the
# web interface (switched on later in Tools -> Defaults, Locations and stations)
fr_yes() { case "${1,,}" in y|yes|s|sim|1|true) echo 1 ;; *) echo 0 ;; esac; }
fr_val fr_multi_loc MULTI_LOCATION "n"
FR_MULTI_LOCATION=$(fr_yes "$fr_multi_loc")
fr_val fr_multi_sta MULTI_STATION "n"
FR_MULTI_STATION=$(fr_yes "$fr_multi_sta")
unset fr_multi_loc fr_multi_sta

# the web password is required (owner 2026-10-24): the seed file's (never empty, no "none", only the characters
# BirdSuite.conf can hold: web_password_ok in scripts/common.php), else the factory password birdsuite; First Run (System › All settings, Start the First Run)
# asks for a new one on the first visit, and Settings -> Access changes it later
fr_val FR_CADDY_PWD WEB_PASSWORD ""
[ "$FR_CADDY_PWD" = none ] && FR_CADDY_PWD=
if [ -n "$FR_CADDY_PWD" ] && ! [[ "$FR_CADDY_PWD" =~ ^[][A-Za-z0-9!@#%^\&*()_=+{}.,:?~/-]+$ ]]; then
  echo "WEB_PASSWORD of the seed file has characters BirdSuite cannot keep: the factory password is used" >&2
  FR_CADDY_PWD=
fi
[ -z "$FR_CADDY_PWD" ] && FR_CADDY_PWD=birdsuite
if [ "$FR_CADDY_PWD" = birdsuite ]; then
  echo -e "\033[33mWeb password: the factory one (user birdsuite, password birdsuite). Change it in Settings -> Access.\033[0m"
fi

# the phone hotspot (owner 2026-10-24, plan B): only from the seed file; the station joins the hotspot by itself
# whenever it is on and no other known network is in range; install_config.sh stores it with field_net.py set-fleet.
# Without it: Settings -> Network
fr_val FR_FLEET_SSID HOTSPOT_NAME ""
fr_val FR_FLEET_PSK HOTSPOT_PASSWORD ""
if [ -n "$FR_FLEET_SSID" ] && { [ ${#FR_FLEET_SSID} -gt 32 ] || [ ${#FR_FLEET_PSK} -lt 8 ] || [ ${#FR_FLEET_PSK} -gt 63 ]; }; then
  echo "The phone hotspot of the seed file needs a name of at most 32 characters and a password of 8 to 63: not set" >&2
  FR_FLEET_SSID=; FR_FLEET_PSK=
fi
[ -z "$FR_FLEET_SSID" ] && FR_FLEET_PSK=

# the station's own WiFi (plan A): its password from the seed file, else set later in Settings -> Network
# the station's ID: its own network port's MAC, else its own WiFi's, never a USB adapter's (as common.php station_mac())
fr_ap_mac=
for fr_wl in 0 1; do
  for d in /sys/class/net/*; do
    [ -d "$d/device" ] || continue
    [ -d "$d/wireless" ] && [ $fr_wl = 0 ] && continue
    [ ! -d "$d/wireless" ] && [ $fr_wl = 1 ] && continue
    fr_sub=$(basename "$(readlink -f "$d/device/subsystem")"); fr_drv=$(basename "$(readlink -f "$d/device/driver")")
    [ "$fr_sub" != usb ] || [ "$fr_drv" = smsc95xx ] || [ "$fr_drv" = lan78xx ] || continue
    fr_ap_mac=$(tr -d ':\n' < "$d/address" 2>/dev/null || true)
    [[ "$fr_ap_mac" =~ ^[0-9a-f]{12}$ && "$fr_ap_mac" != 000000000000 ]] && break 2
    fr_ap_mac=
  done
done
unset fr_wl fr_sub fr_drv
fr_ap_mac=${fr_ap_mac: -4}
fr_ap_ssid=BirdSuite${fr_ap_mac:+-${fr_ap_mac^^}}  # as field_net.py ap_ssid(): the last 4 digits of the MAC (privacy)
unset fr_ap_mac
# its password goes into the label QR: 8 to 63 characters and never the web password; without it Settings -> Network
fr_val FR_AP_PSK STATION_WIFI_PASSWORD ""
if [ -n "$FR_AP_PSK" ] && { [ ${#FR_AP_PSK} -lt 8 ] || [ ${#FR_AP_PSK} -gt 63 ] || [ "$FR_AP_PSK" = "$FR_CADDY_PWD" ]; }; then
  echo "STATION_WIFI_PASSWORD of the seed file needs 8 to 63 characters and must not be the web password: not set" >&2
  FR_AP_PSK=
fi
fr_val FR_ICE_PWD STREAM_PASSWORD ""
[ -z "$FR_ICE_PWD" ] && FR_ICE_PWD=$(od -An -N8 -tx1 /dev/urandom | tr -d ' \n')
fr_val FR_BIRDWEATHER_ID BIRDWEATHER_ID ""
# notifications (Telegram etc.): Settings › Notifications; a seed file can carry APPRISE_URL
FR_APPRISE_URL="${fr_seed[APPRISE_URL]:-}"

echo "First-run settings: site '${FR_SITE_NAME:-BirdSuite-<last 4 of the MAC>}', ${FR_LATITUDE}/${FR_LONGITUDE}, timezone ${FR_TIMEZONE:-unchanged}, model ${FR_MODEL}, language ${FR_LANGUAGE}, state list ${FR_REGION:-none}, several locations $([ "$FR_MULTI_LOCATION" = 1 ] && echo yes || echo no), several stations $([ "$FR_MULTI_STATION" = 1 ] && echo yes || echo no), web password set, phone hotspot $([ -n "$FR_FLEET_SSID" ] && echo "'$FR_FLEET_SSID'" || echo 'not set'), station WiFi ${fr_ap_ssid} $([ -n "$FR_AP_PSK" ] && echo 'password set' || echo 'no password yet'), BirdWeather $([ -n "$FR_BIRDWEATHER_ID" ] && echo set || echo none), notifications $([ -n "$FR_APPRISE_URL" ] && echo set || echo none)"

# the fleet (US-58, owner 2026-11-04): the level, preset, context and access of the station, and a profile of settings,
# SET_<KEY>=value lines made by Station › Network (Answer file, with this station's settings): only keys of
# factory_defaults.conf, never a secret (passwords, tokens, keys, notification addresses, coordinates), a plain value
fr_val FR_UI_PRESET UI_PRESET ""; [[ $FR_UI_PRESET =~ ^(home|birder|research)?$ ]] || FR_UI_PRESET=
fr_val FR_UI_LEVEL UI_LEVEL ""; [[ $FR_UI_LEVEL =~ ^(essential|birder|expert)?$ ]] || FR_UI_LEVEL=
fr_val FR_UI_CONTEXT UI_CONTEXT ""; [[ $FR_UI_CONTEXT =~ ^((home|field_phone|field_offline)(,(home|field_phone|field_offline))*)?$ ]] || FR_UI_CONTEXT=
fr_val FR_LAN_TRUST LAN_TRUST ""; [[ $FR_LAN_TRUST =~ ^(everyone|household)?$ ]] || FR_LAN_TRUST=
fr_val FR_WEB_LOGIN WEB_LOGIN ""; [[ $FR_WEB_LOGIN =~ ^(on|off)?$ ]] || FR_WEB_LOGIN=
FR_PROFILE=()
for fr_k in "${!fr_seed[@]}"; do
  [[ $fr_k == SET_* ]] || continue
  fr_key=${fr_k#SET_}; fr_v=${fr_seed[$fr_k]}
  [[ $fr_key =~ (PWD|PASS|TOKEN|SECRET|KEY|APPRISE|EMAIL|BIRDWEATHER_ID|LATITUDE|LONGITUDE|CADDY|SITE_NAME|RTSP) ]] && continue
  grep -q "^${fr_key}=" "$BS_DEFAULTS" 2>/dev/null || continue
  # a plain value only (BirdSuite.conf is sourced by the station's scripts: no space, quote or $ in it)
  [[ $fr_v =~ ^[A-Za-z0-9._,:/@%+-]*$ ]] || { echo "Seed file: $fr_k is not a plain value (letters, digits, . _ , : / @ % + -): not applied" >&2; continue; }
  FR_PROFILE+=("$fr_key=$fr_v")
done
unset fr_k fr_key fr_v
[ ${#FR_PROFILE[@]} -gt 0 ] && echo "Seed file: ${#FR_PROFILE[@]} settings of the profile applied"

# no seed file: the web interface opens on the setup wizard until it is saved (scripts/setup_wizard.php, US-51c part 2)
FR_WIZARD=
[ ${#fr_seed[@]} -eq 0 ] && FR_WIZARD=1
unset fr_seed
[ -n "$fr_xtrace" ] && set -x
true
