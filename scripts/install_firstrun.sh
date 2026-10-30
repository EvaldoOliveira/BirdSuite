#!/usr/bin/env bash
# First-run questions of a new installation (US-51c). Sourced by install_config.sh before it
# writes BirdSuite.conf, only when there is no BirdSuite.conf yet. Every answer comes from, in order:
#   1. a seed file for unattended / batch installs, the first that exists of
#      /boot/firmware/birdnet-setup.conf, /boot/birdnet-setup.conf, $HOME/birdnet-setup.conf
#      (KEY=value lines: SITE_NAME LATITUDE LONGITUDE TIMEZONE MODEL LANGUAGE STATE WEB_PASSWORD HOTSPOT_NAME
#       HOTSPOT_PASSWORD STATION_WIFI_PASSWORD STREAM_PASSWORD BIRDWEATHER_ID APPRISE_URL MULTI_LOCATION MULTI_STATION — see
#       docs/birdnet-setup.conf.example)
#   2. a question on the terminal (read from /dev/tty, so `curl ... | bash` works too),
#      skipped when BIRDNET_UNATTENDED=1 or there is no terminal
#   3. a default: the ip-api.com answer of install_config.sh (location, country, state)
# Sets FR_* variables for install_config.sh. Secrets are never traced into the install log.

case $- in *x*) fr_xtrace=1 ;; *) fr_xtrace= ;; esac
set +x

fr_interactive=
if [ -z "${BIRDNET_UNATTENDED}" ] && (: < /dev/tty) 2>/dev/null; then
  fr_interactive=1
fi

# seed file: plain KEY=value lines, read (never sourced), only the known keys
declare -A fr_seed=()
for f in /boot/firmware/birdnet-setup.conf /boot/birdnet-setup.conf "$HOME/birdnet-setup.conf"; do
  [ -r "$f" ] || continue
  echo "First-run answers read from $f"
  while IFS='=' read -r key value; do
    key=$(echo "$key" | tr -d '[:space:]')
    case "$key" in
      SITE_NAME|LATITUDE|LONGITUDE|TIMEZONE|MODEL|LANGUAGE|STATE|WEB_PASSWORD|HOTSPOT_NAME|HOTSPOT_PASSWORD|STATION_WIFI_PASSWORD|STREAM_PASSWORD|BIRDWEATHER_ID|APPRISE_URL|MULTI_LOCATION|MULTI_STATION)
        value="${value%$'\r'}"; value="${value#\"}"; value="${value%\"}"
        fr_seed[$key]="$value"
        ;;
    esac
  done < <(grep -E '^[[:space:]]*[A-Z_]+=' "$f")
  break
done

# fr_ask VAR "question" "default" [secret] — seed value wins, then the terminal, then the default
fr_ask() {
  local var=$1 question=$2 default=$3 secret=$4 seed_key=$5 answer
  if [ -n "$seed_key" ] && [ -n "${fr_seed[$seed_key]+set}" ]; then
    printf -v "$var" '%s' "${fr_seed[$seed_key]}"
    return
  fi
  if [ -n "$fr_interactive" ]; then
    if [ -n "$secret" ]; then
      read -r -s -p "$question: " answer < /dev/tty; echo > /dev/tty
    else
      # -e: line editing, so arrow keys and the like edit the answer instead of ending up in it
      read -e -r -p "$question [${default}]: " answer < /dev/tty
    fi
  fi
  # no control characters (escape sequences, stray keys) in a value that goes into BirdSuite.conf
  answer=$(printf '%s' "$answer" | tr -d '\000-\037\177')
  printf -v "$var" '%s' "${answer:-$default}"
}

fr_country=; fr_region=; fr_tz=
if [ -n "$json" ] && [ "$(echo "$json" | jq -r .status 2>/dev/null)" = "success" ]; then
  fr_country=$(echo "$json" | jq -r .countryCode)
  fr_region=$(echo "$json" | jq -r .region)
  fr_tz=$(echo "$json" | jq -r .timezone)
fi

# drop what was typed (or what the terminal answered) while the packages were installing:
# it would otherwise become the beginning of the first answer
if [ -n "$fr_interactive" ]; then
  while read -r -t 0.1 -n 1000 _ < /dev/tty; do :; done
fi
[ -n "$fr_interactive" ] && echo -e "\n=== BirdSuite first-run settings (Enter keeps the value in brackets) ===" > /dev/tty

fr_ask FR_SITE_NAME "Station name" "$HOSTNAME" "" SITE_NAME
while :; do
  fr_ask FR_LATITUDE "Latitude (4 decimals, e.g. -23.5505)" "$LATITUDE" "" LATITUDE
  fr_ask FR_LONGITUDE "Longitude (4 decimals, e.g. -46.6333)" "$LONGITUDE" "" LONGITUDE
  if [[ "$FR_LATITUDE" =~ ^-?[0-9]+(\.[0-9]+)?$ && "$FR_LONGITUDE" =~ ^-?[0-9]+(\.[0-9]+)?$ ]]; then
    break
  fi
  echo "Latitude/longitude must be numbers" >&2
  [ -n "$fr_interactive" ] || { FR_LATITUDE=$LATITUDE; FR_LONGITUDE=$LONGITUDE; break; }
  unset 'fr_seed[LATITUDE]' 'fr_seed[LONGITUDE]'
done

# timezone: detections are stamped with the system clock — a fresh Raspberry Pi OS is on
# Europe/London (US-51d)
fr_tz_now=$(timedatectl show --value --property=Timezone 2>/dev/null || cat /etc/timezone 2>/dev/null || true)
while :; do
  fr_ask FR_TIMEZONE "Timezone" "${fr_tz:-$fr_tz_now}" "" TIMEZONE
  [ -z "$FR_TIMEZONE" ] || [ -e "/usr/share/zoneinfo/$FR_TIMEZONE" ] && break
  echo "Unknown timezone '$FR_TIMEZONE' (e.g. America/Sao_Paulo)" >&2
  [ -n "$fr_interactive" ] || { FR_TIMEZONE=; break; }
  unset 'fr_seed[TIMEZONE]'
done

fr_ask fr_model "Model: V3 (BirdNET+ V3.0, recommended) or V2.4" "V3" "" MODEL
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
while :; do
  fr_ask FR_LANGUAGE "Species names language (${fr_langs% }; pt_BR = Portuguese Brazil (CBRO), pt_PT = Portuguese Portugal)" "$fr_lang_default" "" LANGUAGE
  [ "$FR_LANGUAGE" = "pt" ] && FR_LANGUAGE=pt_BR
  [ -f "$my_dir/model/l18n/labels_${FR_LANGUAGE}.json" ] && break
  echo "No species names for '$FR_LANGUAGE'" >&2
  [ -n "$fr_interactive" ] || { FR_LANGUAGE=en; break; }
  unset 'fr_seed[LANGUAGE]'
done

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
  while :; do
    fr_ask fr_state "Brazilian state for the include list (UF, e.g. SP; none = no list)" "$fr_state_default" "" STATE
    fr_state=$(echo "$fr_state" | tr '[:lower:]' '[:upper:]' | tr -d ' ')
    fr_state=${fr_state#BR-}
    case "$fr_state" in
      ""|NONE|N|NO) FR_REGION=; break ;;
    esac
    if [ -f "$my_dir/model/include_lists/BR-${fr_state}.txt" ]; then
      FR_REGION="BR-${fr_state}"; break
    fi
    echo "No include list for '$fr_state'" >&2
    [ -n "$fr_interactive" ] || break
    unset 'fr_seed[STATE]'
  done
fi

# simple mode (owner 2026-10-29, US-125): several locations and several stations; no = their controls stay hidden in the
# web interface (switched on later in Tools -> Defaults, Locations and stations)
fr_yes() { case "${1,,}" in y|yes|s|sim|1|true) echo 1 ;; *) echo 0 ;; esac; }
fr_ask fr_multi_loc "Will this station be used at more than one location (home, farm, trips)? (y/n)" "n" "" MULTI_LOCATION
FR_MULTI_LOCATION=$(fr_yes "$fr_multi_loc")
fr_ask fr_multi_sta "Will you use more than one BirdSuite station? (y/n)" "n" "" MULTI_STATION
FR_MULTI_STATION=$(fr_yes "$fr_multi_sta")
unset fr_multi_loc fr_multi_sta

# species info links: eBird has every species and pages in Portuguese; All About Birds (the
# upstream default) is a North American guide without most neotropical species
case "$fr_country:$FR_LANGUAGE" in
  US:*|CA:*) FR_INFO_SITE=ALLABOUTBIRDS ;;
  BR:*|*:pt*) FR_INFO_SITE=WIKIAVES ;;  # the factory default; a species not on WikiAves opens eBird
  *) FR_INFO_SITE=EBIRD ;;
esac

# the web password is required (owner 2026-10-24): typed twice on the terminal, never empty, no "none"; with nobody to
# ask (seed file without it, unattended) the factory password birdsuite; changed later in Settings -> Access
while :; do
  fr_ask FR_CADDY_PWD "Web password for Tools / Settings, user birdsuite (required)" "" secret WEB_PASSWORD
  [ "$FR_CADDY_PWD" = none ] && FR_CADDY_PWD=
  if [ -z "$fr_interactive" ] || [ -n "${fr_seed[WEB_PASSWORD]+set}" ]; then
    [ -n "$FR_CADDY_PWD" ] && break
    [ -n "$fr_interactive" ] && { unset 'fr_seed[WEB_PASSWORD]'; echo "WEB_PASSWORD in the seed file is empty: type one" >&2; continue; }
    FR_CADDY_PWD=birdsuite; break
  fi
  if [ -z "$FR_CADDY_PWD" ]; then
    echo -e "\033[33mThe web password cannot be empty.\033[0m" > /dev/tty; continue
  fi
  # the characters BirdSuite.conf can hold (owner 2026-10-25; web_password_ok in scripts/common.php)
  if ! [[ "$FR_CADDY_PWD" =~ ^[][A-Za-z0-9!@#%^\&*()_=+{}.,:?~/-]+$ ]]; then
    echo -e "\033[33mThe password may contain letters, digits and ! @ # % ^ & * ( ) - _ = + [ ] { } . , : ? ~ / only.\033[0m" > /dev/tty; continue
  fi
  fr_ask fr_pwd_again "Web password again" "" secret
  [ "$FR_CADDY_PWD" = "$fr_pwd_again" ] && break
  echo -e "\033[33mThe two passwords are different, try again.\033[0m" > /dev/tty
done
unset fr_pwd_again
if [ "$FR_CADDY_PWD" = birdsuite ]; then
  echo -e "\033[33mWeb password: the factory one (user birdsuite, password birdsuite). Change it in Settings -> Access.\033[0m"
fi

# the phone hotspot (owner 2026-10-24, plan A): asked instead of the station WiFi password. The station joins the
# hotspot by itself whenever it is on and no other known network is in range; install_config.sh stores it with
# field_net.py set-fleet. Empty name = later in Settings -> Network
if [ -n "$fr_interactive" ] && [ -z "${fr_seed[HOTSPOT_NAME]+set}" ]; then
  if [[ "$FR_LANGUAGE" == pt* ]]; then
    echo -e "\n--- Hotspot do celular: a estação se conecta sozinha ao celular, sem rede externa (Plano A) ---" > /dev/tty
  else
    echo -e "\n--- Phone hotspot: the station joins your phone by itself, with no other network (Plan A) ---" > /dev/tty
  fi
fi
FR_FLEET_PSK=
while :; do
  fr_ask FR_FLEET_SSID "Phone hotspot name (iPhone: the phone's name; Enter = later, in Settings -> Network)" "" "" HOTSPOT_NAME
  [ -z "$FR_FLEET_SSID" ] && break
  if [ ${#FR_FLEET_SSID} -gt 32 ]; then
    echo "The hotspot name has at most 32 characters" >&2
    [ -n "$fr_interactive" ] || { FR_FLEET_SSID=; break; }
    unset 'fr_seed[HOTSPOT_NAME]'; continue
  fi
  fr_ask FR_FLEET_PSK "Phone hotspot password" "" secret HOTSPOT_PASSWORD
  if [ ${#FR_FLEET_PSK} -lt 8 ] || [ ${#FR_FLEET_PSK} -gt 63 ]; then
    echo "The hotspot password needs 8 to 63 characters" >&2
    [ -n "$fr_interactive" ] || { FR_FLEET_SSID=; FR_FLEET_PSK=; break; }
    unset 'fr_seed[HOTSPOT_PASSWORD]'; continue
  fi
  [ -n "${fr_seed[HOTSPOT_PASSWORD]+set}" ] && break
  fr_ask fr_pwd_again "Phone hotspot password again" "" secret
  [ "$FR_FLEET_PSK" = "$fr_pwd_again" ] && break
  echo -e "\033[33mThe two passwords are different, try again.\033[0m" > /dev/tty
done
unset fr_pwd_again

# the station's own WiFi (plan B): always asked (owner 2026-10-24: no "batch installation?" question any more); its
# password goes into the label QR. Enter = later in Settings -> Network; a seed file can carry it too
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
fr_ap_ssid=BirdSuite-${fr_ap_mac^^}  # as field_net.py ap_ssid(): the whole MAC
unset fr_ap_mac
FR_AP_PSK=
if :; then
  if [ -n "$fr_interactive" ] && [ -z "${fr_seed[STATION_WIFI_PASSWORD]+set}" ]; then
    if [[ "$FR_LANGUAGE" == pt* ]]; then
      echo -e "--- WiFi da estação: $fr_ap_ssid, para conectar o celular direto na estação, sem rede (Plano B) ---" > /dev/tty
    else
      echo -e "--- Station WiFi: $fr_ap_ssid, to connect the phone straight to the station, with no network (Plan B) ---" > /dev/tty
    fi
  fi
  while :; do
    fr_ask FR_AP_PSK "Station WiFi password for $fr_ap_ssid (8 to 63 characters, not the web password; Enter = later)" "" secret STATION_WIFI_PASSWORD
    [ -z "$FR_AP_PSK" ] && break
    if [ ${#FR_AP_PSK} -lt 8 ] || [ ${#FR_AP_PSK} -gt 63 ]; then
      echo "The station WiFi password needs 8 to 63 characters" >&2
      [ -n "$fr_interactive" ] || { FR_AP_PSK=; break; }
      unset 'fr_seed[STATION_WIFI_PASSWORD]'; continue
    fi
    if [ "$FR_AP_PSK" = "$FR_CADDY_PWD" ]; then
      echo "The station WiFi password is in the label QR: it must not be the web password" >&2
      [ -n "$fr_interactive" ] || { FR_AP_PSK=; break; }
      unset 'fr_seed[STATION_WIFI_PASSWORD]'; continue
    fi
    [ -n "${fr_seed[STATION_WIFI_PASSWORD]+set}" ] && break
    fr_ask fr_pwd_again "Station WiFi password again" "" secret
    [ "$FR_AP_PSK" = "$fr_pwd_again" ] && break
    echo -e "\033[33mThe two passwords are different, try again.\033[0m" > /dev/tty
  done
  unset fr_pwd_again
fi
fr_ask FR_ICE_PWD "Live stream (icecast) password (empty = random)" "" secret STREAM_PASSWORD
[ -z "$FR_ICE_PWD" ] && FR_ICE_PWD=$(od -An -N8 -tx1 /dev/urandom | tr -d ' \n')
fr_ask FR_BIRDWEATHER_ID "BirdWeather ID (empty = none)" "" "" BIRDWEATHER_ID
# notifications (Telegram etc.) are not asked on the terminal (owner 2026-10-10): set them later in Settings ›
# Notifications; a seed file can still carry APPRISE_URL
FR_APPRISE_URL="${fr_seed[APPRISE_URL]:-}"

echo "First-run settings: site '${FR_SITE_NAME}', ${FR_LATITUDE}/${FR_LONGITUDE}, timezone ${FR_TIMEZONE:-unchanged}, info ${FR_INFO_SITE}, model ${FR_MODEL}, language ${FR_LANGUAGE}, state list ${FR_REGION:-none}, several locations $([ "$FR_MULTI_LOCATION" = 1 ] && echo yes || echo no), several stations $([ "$FR_MULTI_STATION" = 1 ] && echo yes || echo no), web password set, phone hotspot $([ -n "$FR_FLEET_SSID" ] && echo "'$FR_FLEET_SSID'" || echo 'not set'), station WiFi ${fr_ap_ssid} $([ -n "$FR_AP_PSK" ] && echo 'password set' || echo 'no password yet'), BirdWeather $([ -n "$FR_BIRDWEATHER_ID" ] && echo set || echo none), notifications $([ -n "$FR_APPRISE_URL" ] && echo set || echo none)"

# nobody answered (no terminal, no seed file): the web interface opens on the setup wizard
# until it is saved (scripts/setup_wizard.php, US-51c part 2)
FR_WIZARD=
[ -z "$fr_interactive" ] && [ ${#fr_seed[@]} -eq 0 ] && FR_WIZARD=1
unset fr_seed
[ -n "$fr_xtrace" ] && set -x
true
