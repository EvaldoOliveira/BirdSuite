#!/usr/bin/env bash
# Creates and installs config/BirdSuite.conf, the station settings (owner 2026-10-22: no copy in /etc)
set -x # Uncomment to enable debugging
set -e
trap 'exit 1' SIGINT SIGHUP

echo "Beginning $0"
# the station's own files live in config/ (owner 2026-10-22); nothing in /etc
mkdir -p $my_dir/config/species_lists/custom $my_dir/config/scripts $my_dir/config/notifications $my_dir/config/data $my_dir/config/settings_profiles $my_dir/config/custom $my_dir/state
birdnet_conf=$my_dir/config/BirdSuite.conf

# Retrieve latitude and longitude from web
json=$(curl -s4 http://ip-api.com/json || true)
if [ -n "$json" ] && [ "$(echo "$json" | jq -r .status)" = "success" ]; then
  LATITUDE=$(echo "$json" | jq .lat)
  LONGITUDE=$(echo "$json" | jq .lon)
else
  echo -e "\033[33mCouldn't set latitude and longitude automatically, you will need to do this manually from the web interface by navigating to Settings -> Locations.\033[0m"
  LATITUDE=0.0000
  LONGITUDE=0.0000
fi

install_config() {
  cat << EOF > $birdnet_conf
################################################################################
#                    Configuration settings for BirdSuite                     #
################################################################################
# Optional: station name. Empty = BirdSuite-<MAC of the Pi> (the board's own identity, so cloned SD cards differ),
# written here by Station Setup on the first run

SITE_NAME="${FR_SITE_NAME:-}"

#--------------------- Required: Latitude, and Longitude ----------------------#

## The shell substitution below guesses these based on your network. THESE NEED
## TO BE CHANGED TO STATIC VALUES
## Please only go to 4 decimal places. Example:43.3984

LATITUDE=${FR_LATITUDE:-$LATITUDE}
LONGITUDE=${FR_LONGITUDE:-$LONGITUDE}
DATA_MODEL_VERSION=1
## SPECIES_LIST is the species list filter (Settings > Location): empty = none (the model's species
## distribution decides), BR-<UF> = a Brazilian state, or the name of a list in ~/BirdNET-Pi/config/species_lists/custom/
SPECIES_LIST=${FR_REGION}

#---------------------  BirdWeather Station Information -----------------------#
#______________The variable below can be set to have your BirdSuite______________#
#__________________also act as a BirdWeather listening station_________________#

BIRDWEATHER_ID=${FR_BIRDWEATHER_ID}

#-----------------------  Web Interface User Password  ------------------------#
#____________________The variable below sets the 'birdnet'_____________________#
#___________________user password for the Live Audio Stream,___________________#
#_________________Tools, System Links, and the Processed files ________________#

## CADDY_PWD is the plaintext password (that will be hashed) and used to access
## certain parts of the web interface

CADDY_PWD="${FR_CADDY_PWD}"
## CADDY_USER: the user name of the web login (Settings › Advanced)
CADDY_USER=birdsuite

#-------------------------  Live Audio Stream  --------------------------------#
#_____________The variable below configures/enables the live___________________#
#_____________________________audio stream.____________________________________#

## ICE_PWD is the password that icecast2 will use to authenticate ffmpeg as a
## trusted source for the stream. You will never need to enter this manually
## anywhere other than here and it stays on 'localhost.'

ICE_PWD=${FR_ICE_PWD:-birdnetpi}

#-----------------------  Web-hosting/Caddy File-server -----------------------#
#_______The three variables below can be set to enable internet access_________#
#____________to your data,(e.g., extractions, raw data, live___________________#
#______________audio stream, BirdNET.selection.txt files)______________________#

## BIRDNETPI_URL is the URL where the extractions, data-set, and live-stream
## will be web-hosted. If you do not own a domain, or would just prefer to keep
## the BirdSuite on your local network, keep this EMPTY.

BIRDNETPI_URL=

#----------------------------  RTSP Stream URL  -------------------------------#

## If RTSP_STREAM is set, the system will use the RTSP stream as its audio
## source instead of recording its own audio. If this variable is kept empty,
## BirdSuite will default to recording its own audio.

RTSP_STREAM=
SOUND_REPO_PATH=""
## SOUND_REPO_LINK is the shareable URL of the central sound repository (informational)
SOUND_REPO_LINK=""
## SOUND_REPO_REMOTE is the rclone destination of the central sound repository (remote:path,
## a remote the station owner configured with their own account; empty = deposits stay in SOUND_REPO_PATH)
SOUND_REPO_REMOTE=""

## NOTIFICATION_EMAIL is the owner's e-mail, exposed as $email inside the Apprise boxes
NOTIFICATION_EMAIL=""

#----------------------  Flickr Images API Configuration -----------------------#
## If FLICKR_API_KEY is set, the web interface will try and display bird images 
## for each detection. If FLICKR_FILTER_EMAIL is set, the images will only be 
## displayed from a particular Flickr user (e.g. yourself).

FLICKR_API_KEY=
FLICKR_FILTER_EMAIL=

################################################################################
#--------------------------------  Defaults  ----------------------------------#
################################################################################

## BIRDNET_USER is for scripts to easily find where BirdSuite is installed
## DO NOT EDIT!

BIRDNET_USER=$USER

## RECS_DIR is the location birdnet_analysis.service will look for the data-set
## it needs to analyze. Be sure this directory is readable and writable for
## the BIRDNET_USER.

RECS_DIR=$HOME/BirdSongs

## REC_CARD is the sound card you would want the birdnet_recording.service to
## use. Leave this as "default" to use PulseAudio (recommended), or use
## the output from "aplay -L" to specify an ALSA device.

REC_CARD=default

## PROCESSED is the directory where the formerly 'Analyzed' files are moved
## after extractions have been made from them. This includes both WAVE and
## BirdNET.selection.txt files.

PROCESSED=$HOME/BirdSongs/Processed

## EXTRACTED is the directory where the extracted audio selections are moved.

EXTRACTED=$HOME/BirdSongs/Extracted

## STATION_LAYER is the folder under custom/ whose model files (labels.txt, l18n/labels_*.json)
## are re-applied after every update - leave empty on a stock install
STATION_LAYER=

## DATABASE_LANG is the language used for the bird species database
DATABASE_LANG=${FR_LANGUAGE:-en}

## HEARTBEAT_URL is a location to ping every time some analysis is done
## no information is sent to the the URL, its a heart beat to show that the
## analysis is continuing

HEARTBEAT_URL=

## UPDATE_BRANCH is the branch the updater follows: main = the latest commit (the default; a new station is
## installed from the last release and updates from main, owner 2026-10-24), stable = only the released versions

UPDATE_BRANCH=${BIRDNET_UPDATE_BRANCH:-main}

## CUSTOM_IMAGE and CUSTOM_IMAGE_TITLE allow you to show a custom image on the
## Overview page of your BirdSuite. This can be used to show a dynamically 
## updating picture of your garden, for example.

CUSTOM_IMAGE=
CUSTOM_IMAGE_TITLE=""

## These are just for debugging
LAST_RUN=
THIS_RUN=
IDFILE=$HOME/BirdNET-Pi/IdentifiedSoFar.txt

################################################################################
#   Everything below: the defaults of factory_defaults.conf (US-121), with the answers  #
#   of the first run; change them in the web interface                           #
################################################################################
EOF
  # US-121 (2026-10-20): the behaviour settings come from factory_defaults.conf, the single file of defaults (its header
  # banner left out), then the first-run answers replace their keys
  awk 'BEGIN { banner = 0 } banner < 3 && /^#####/ { banner++; next } banner >= 3' $my_dir/config/factory_defaults.conf >> $birdnet_conf
  set_default() { [ -n "$2" ] && sed -i "s|^$1=.*|$1=$2|" $birdnet_conf; return 0; }
  set_default MODEL "${FR_MODEL}"
  set_default SF_THRESH "${FR_SF_THRESH}"
  set_default OVERLAP "${FR_OVERLAP}"
  set_default CONFIDENCE "${FR_CONFIDENCE}"
  set_default SENSITIVITY "${FR_SENSITIVITY}"
  [ -n "${FR_INFO_SITE}" ] && set_default INFO_SITE "\"${FR_INFO_SITE}\""
  set_default MULTI_LOCATION "${FR_MULTI_LOCATION}"
  set_default MULTI_STATION "${FR_MULTI_STATION}"
  return 0
}

# Checks for a BirdSuite.conf file; a new installation answers the first-run questions first (US-51c)
if ! [ -f ${birdnet_conf} ];then
  source $my_dir/scripts/install_firstrun.sh
  { set +x; } 2>/dev/null
  install_config
  [ -n "${FR_WIZARD}" ] && touch $my_dir/state/firstrun_pending
  if [ -n "${FR_TIMEZONE}" ] && [ "${FR_TIMEZONE}" != "$(timedatectl show --value --property=Timezone 2>/dev/null || true)" ]; then
    sudo timedatectl set-timezone "${FR_TIMEZONE}" && echo "Timezone set to ${FR_TIMEZONE}" || echo "Could not set the timezone - set it with: sudo timedatectl set-timezone ${FR_TIMEZONE}"
  fi
  # the phone hotspot (owner 2026-10-24, plan B since 2026-10-25) and, from a seed file, the station's own WiFi password; tracing is off
  if [ -n "${FR_FLEET_SSID}" ]; then
    if command -v nmcli > /dev/null && printf '%s\n' "${FR_FLEET_PSK}" | sudo python3 $my_dir/scripts/field_net.py set-fleet "${FR_FLEET_SSID}" > /dev/null; then
      echo "Phone hotspot '${FR_FLEET_SSID}' set: the station joins it whenever it is on and no other known network is in range"
    else
      echo -e "\033[33mCould not store the phone hotspot - set it later in Settings -> Network.\033[0m"
    fi
  fi
  if [ -n "${FR_AP_PSK}" ]; then
    if command -v nmcli > /dev/null && printf '%s\n' "${FR_AP_PSK}" | sudo python3 $my_dir/scripts/field_net.py set-ap > /dev/null; then
      echo "Station WiFi ${fr_ap_ssid} has its password (off until no known network is in reach)"
    else
      echo -e "\033[33mCould not store the station WiFi password - set it later in Settings -> Network.\033[0m"
    fi
  fi
  # tested while tracing is still off: the URL carries the notification token
  if [ -n "${FR_APPRISE_URL}" ]; then
    printf '%s\n' "${FR_APPRISE_URL}" > $my_dir/config/notifications/apprise.txt
  fi
  set -x
fi
chmod g+w ${birdnet_conf}
grep -ve '^#' -e '^$' $birdnet_conf > $my_dir/config/firstrun.ini

# not traced: BirdSuite.conf carries the web / stream passwords
{ set +x; } 2>/dev/null
source "$(dirname "$(readlink -f "${BASH_SOURCE[0]}")")/paths.sh"
set -x
# notification bodies (English, whatever the species-names language — owner 2026-10-07); kept on a re-install
if ! [ -s "$HOME/BirdNET-Pi/config/notifications/body.txt" ]; then
  # the station's own texts as the default (owner 2026-10-21; thresholds, probability and duration 2026-10-22)
  body='A $common_name ($scientific_name) was detected at $station\nConfidence: $conf_pct%\nReason: $reason\nLocation: $location\nStation: $station\n\nMinimum Confidence: $conf_min_pct\nManual Confidence (override): $conf_manual_pct\nProbability of occurrence this week: $occurrence_probability\nMinimum Probability of occurrence: $occurrence_probability_min\n\nSigmoid Sensitivity: $sigmoid_sensitivity\nOverlap: $overlap s\nRecording Length: $recording_length\n\nLink to the detection: $listen_url\n$image\n$audio'
  body_rare='A RARE $common_name ($scientific_name) was detected at $station\nConfidence: $conf_pct%\nReason: $reason\nLocation: $location\nStation: $station\n\nMinimum Confidence: $conf_min_pct\nManual Confidence (override): $conf_manual_pct\nProbability of occurrence this week: $occurrence_probability\nMinimum Probability of occurrence: $occurrence_probability_min\n\nSigmoid Sensitivity: $sigmoid_sensitivity\nOverlap: $overlap s\nRecording Length: $recording_length\n\nLink to the detection: $listen_url\n$image\n$audio'
  printf '%b\n' "$body" | sudo -u $BIRDNET_USER tee "$HOME/BirdNET-Pi/config/notifications/body.txt" > /dev/null
  printf '%b\n' "$body_rare" | sudo -u $BIRDNET_USER tee "$HOME/BirdNET-Pi/config/notifications/body-rare.txt" > /dev/null
fi
chmod g+w "$HOME/BirdNET-Pi/config/notifications/body.txt"
[ -f "$HOME/BirdNET-Pi/config/notifications/body-rare.txt" ] && chmod g+w "$HOME/BirdNET-Pi/config/notifications/body-rare.txt"
