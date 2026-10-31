#!/usr/bin/env bash
set -x
# Update BirdSuite
trap 'exit 1' SIGINT SIGHUP
# the station's own files into ~/BirdNET-Pi/config, once, before anything reads the settings (owner 2026-10-22)
"$(dirname "$(readlink -f "${BASH_SOURCE[0]}")")/migrate_config_layout.sh"
source "$(dirname "$(readlink -f "${BASH_SOURCE[0]}")")/paths.sh"
if [ -n "${BIRDNET_USER}" ]; then
  echo "BIRDNET_USER: ${BIRDNET_USER}"
  USER=${BIRDNET_USER}
  HOME=/home/${BIRDNET_USER}
else
  echo "WARNING: no BIRDNET_USER found"
  USER=$(awk -F: '/1000/ {print $1}' /etc/passwd)
  HOME=$(awk -F: '/1000/ {print $6}' /etc/passwd)
fi
my_dir=$HOME/BirdNET-Pi/scripts
source "$my_dir/install_helpers.sh"

# least privilege helpers (owner 2026-10-23): the named root steps of the station (birdsuite-svc, birdsuite-root), copied
# root-owned on every update so the station user cannot change them; a station installed before keeps its sudo rules
install -d -o root -g root -m 0755 /usr/local/lib/birdsuite
printf '%s\n' "$USER" > /usr/local/lib/birdsuite/station_user
chmod 0644 /usr/local/lib/birdsuite/station_user
install -o root -g root -m 0755 "$my_dir/birdsuite-svc" "$my_dir/birdsuite-root" /usr/local/sbin/
# NetworkManager for the station user (the Network page and the field services run as that user, least privilege)
mkdir -p /etc/polkit-1/rules.d
cat > /etc/polkit-1/rules.d/50-birdsuite-networkmanager.rules << PKEOF
// BirdSuite (least privilege): the station user manages the network without root
polkit.addRule(function(action, subject) {
  if (action.id.indexOf("org.freedesktop.NetworkManager.") == 0 && subject.user == "$USER") {
    return polkit.Result.YES;
  }
});
PKEOF
chmod 0644 /etc/polkit-1/rules.d/50-birdsuite-networkmanager.rules
# the weekly automatic update through the helper (the cron line of the earlier versions called the updater directly)
sed -i 's|/usr/local/bin/update_birdnet.sh -a|sudo -n /usr/local/sbin/birdsuite-root update -a|' /etc/crontab
# the raw recording schedule moved to the station user's crontab
if [ -f /etc/cron.d/birdnet_raw_recording ]; then
  sudo -u "$USER" "$my_dir/raw_recording_cron.sh" > /dev/null 2>&1
  rm -f /etc/cron.d/birdnet_raw_recording
fi

# Sets proper permissions and ownership
find $HOME/Bird* -type f ! -perm -g+wr -exec chmod g+wr {} + 2>/dev/null
find $HOME/Bird* -not -user $USER -execdir sudo -E chown $USER:$USER {} \+
# nothing writable by every account (2026-10-23 security run: every file was a+w, also the scripts two services run as
# root); the web server (caddy) is in the station user's group, so group write is enough for the pages
find $HOME/BirdNET-Pi $HOME/BirdSongs -path "$HOME/BirdNET-Pi/birdnet" -prune -o -perm /o=w ! -type l -exec chmod g+rwX,o-w {} + 2>/dev/null
# the token store (app passwords, bot token) stays private to the station user (owner 2026-10-22)
[ -f "$HOME/BirdNET-Pi/config/notifications/apprise-tokens.txt" ] && chmod u=rw,go= "$HOME/BirdNET-Pi/config/notifications/apprise-tokens.txt"
chmod g+r $HOME

# remove world-writable perms
chmod -R o-w ~/BirdNET-Pi/templates/*

APT_UPDATED=0
PIP_UPDATED=0

# helpers
sudo_with_user () {
  sudo -u $USER "$@"
}

ensure_apt_updated () {
  [[ $APT_UPDATED != "UPDATED" ]] && apt-get update && APT_UPDATED="UPDATED"
}

ensure_pip_updated () {
  [[ $PIP_UPDATED != "UPDATED" ]] && sudo_with_user $HOME/BirdNET-Pi/birdnet/bin/pip3 install -U pip && PIP_UPDATED="UPDATED"
}

remove_unit_file() {
  # remove_unit_file pushed_notifications.service $HOME/BirdNET-Pi/templates/pushed_notifications.service
  if systemctl list-unit-files "${1}" &>/dev/null;then
    systemctl disable --now "${1}"
    rm -f "/usr/lib/systemd/system/${1}"
    rm "$HOME/BirdNET-Pi/templates/${1}"
    if [ $# == 2 ]; then
      rm -f "${2}"
    fi
  fi
}

ensure_python_package() {
  # ensure_python_package pytest pytest==7.1.2
  pytest_installation_status=$(~/BirdNET-Pi/birdnet/bin/python3 -c 'import pkgutil; import sys; print("installed" if pkgutil.find_loader(sys.argv[1]) else "not installed")' "$1")
  if [[ "$pytest_installation_status" = "not installed" ]];then
    ensure_pip_updated
    sudo_with_user $HOME/BirdNET-Pi/birdnet/bin/pip3 install "$2"
  fi
}


# update snippets below
SRC="APPRISE_NOTIFICATION_BODY='(.*)'$"
DST='APPRISE_NOTIFICATION_BODY="\1"'
sed -i --follow-symlinks -E "s/$SRC/$DST/" $BS_CONF
# a shorter name (owner 2026-10-25): the station's settings and the saved sets of System > Defaults
for f in $BS_CONF $HOME/BirdNET-Pi/config/settings_profiles/*.conf; do
  [ -f "$f" ] && sed -i --follow-symlinks -e 's/^APPRISE_MINIMUM_SECONDS_BETWEEN_NOTIFICATIONS_PER_SPECIES=/APPRISE_MIN_SEC_PER_SPECIES=/' \
    -e 's/^APPRISE_ONLY_NOTIFY_SPECIES_NAMES_2=/APPRISE_ONLY_SPECIES=/' -e 's/^APPRISE_ONLY_NOTIFY_SPECIES_NAMES=/APPRISE_EXCLUDE_SPECIES=/' "$f"
done

if ! grep -E '^DATA_MODEL_VERSION=' $BS_CONF &>/dev/null;then
    echo "DATA_MODEL_VERSION=1" >> $BS_CONF
fi

if ! grep -E '^SPECTROGRAM_HEIGHT=' $BS_CONF &>/dev/null;then
    echo "SPECTROGRAM_HEIGHT=70" >> $BS_CONF
fi

if ! grep -E '^APPRISE_NOTIFICATION_TITLE_RARE=' $BS_CONF &>/dev/null;then
  echo 'APPRISE_NOTIFICATION_TITLE_RARE="RARE $common_name $conf_pct%"' >> $BS_CONF
fi

# standard notification bodies (created only when missing — user bodies kept)
BNP_DIR=$BS_NOTIFY
if ! [ -s "$BNP_DIR/body.txt" ];then
  cat << 'BODYEOF' > "$BNP_DIR/body.txt"
A $common_name ($scientific_name) was detected at $station
Confidence: $conf_pct%
Reason: $reason
Location: $location
Station: $station

Minimum Confidence: $conf_min_pct
Manual Confidence (override): $conf_manual_pct
Probability of occurrence this week: $occurrence_probability
Minimum Probability of occurrence: $occurrence_probability_min

Sigmoid Sensitivity: $sigmoid_sensitivity
Overlap: $overlap s
Recording Length: $recording_length

Link to the detection: $listen_url
$image
$audio
BODYEOF
fi
if ! [ -f "$BNP_DIR/body-rare.txt" ];then
  cat << 'BODYEOF' > "$BNP_DIR/body-rare.txt"
A RARE $common_name ($scientific_name) was detected at $station
Confidence: $conf_pct%
Reason: $reason
Location: $location
Station: $station

Minimum Confidence: $conf_min_pct
Manual Confidence (override): $conf_manual_pct
Probability of occurrence this week: $occurrence_probability
Minimum Probability of occurrence: $occurrence_probability_min

Sigmoid Sensitivity: $sigmoid_sensitivity
Overlap: $overlap s
Recording Length: $recording_length

Link to the detection: $listen_url
$image
$audio
BODYEOF
fi

# the notification variables renamed after the app's columns (owner 2026-10-22, a rewrite): titles, bodies, Apprise boxes
# and token names converted once; texts already converted are left as they are
sudo_with_user $HOME/BirdNET-Pi/birdnet/bin/python3 $HOME/BirdNET-Pi/scripts/migrate_notify_names.py || true

if ! grep -E '^NOTIFICATION_EMAIL=' $BS_CONF &>/dev/null;then
  echo 'NOTIFICATION_EMAIL=""' >> $BS_CONF
fi

if ! grep -E '^NOTIFICATION_DEFAULT_TIER=' $BS_CONF &>/dev/null;then
    echo "NOTIFICATION_DEFAULT_TIER=normal" >> $BS_CONF
fi

if ! grep -E '^SOUND_REPO_PATH=' $BS_CONF &>/dev/null;then
    echo 'SOUND_REPO_PATH=""' >> $BS_CONF
fi

if ! grep -E '^SOUND_REPO_LINK=' $BS_CONF &>/dev/null;then
    echo 'SOUND_REPO_LINK=""' >> $BS_CONF
fi

# US-40: station-side upload of the sound-repo spool
if ! grep -E '^SOUND_REPO_REMOTE=' $BS_CONF &>/dev/null;then
    echo '## SOUND_REPO_REMOTE is the rclone destination of the central sound repository (remote:path; empty = deposits stay in SOUND_REPO_PATH)' >> $BS_CONF
    echo 'SOUND_REPO_REMOTE=""' >> $BS_CONF
fi

if ! grep -E '^SOUND_REPO_UPLOAD_MINUTES=' $BS_CONF &>/dev/null;then
    echo '## SOUND_REPO_UPLOAD_MINUTES is the interval in minutes between uploads of SOUND_REPO_PATH to SOUND_REPO_REMOTE (0 = never)' >> $BS_CONF
    echo 'SOUND_REPO_UPLOAD_MINUTES=5' >> $BS_CONF
fi

# US-51e: the release channel a station follows. A station that came from another BirdNET-Pi has
# no key yet: keep the line it was just updated to (stable or main), so the next update neither
# jumps to another line nor goes back to an older release.
if ! grep -E '^UPDATE_BRANCH=' $BS_CONF &>/dev/null;then
  current_branch=$(git -C $HOME/BirdNET-Pi rev-parse --abbrev-ref HEAD 2>/dev/null)
  case "$current_branch" in stable|main) ;; *) current_branch=stable ;; esac
  echo "## UPDATE_BRANCH is the release channel the updater follows: stable = released versions, main = development" >> $BS_CONF
  echo "UPDATE_BRANCH=$current_branch" >> $BS_CONF
fi

if ! grep -E '^ACTIVATE_BIRDDB=' $BS_CONF &>/dev/null;then
  echo '## ACTIVATE_BIRDDB: "true" = the BirdDB-Br section is shown in Settings › Repositories, "false" = hidden (default)' >> $BS_CONF
  echo 'ACTIVATE_BIRDDB="false"' >> $BS_CONF
fi

if ! grep -E '^BIRDDB_ENABLED=' $BS_CONF &>/dev/null;then
  echo "## BIRDDB_ENABLED: 1 = contribute this station's clips to BirdDB-Br (sound repository), 0 = off (default)" >> $BS_CONF
  echo "BIRDDB_ENABLED=0" >> $BS_CONF
fi

# Species lists (owner 2026-10-08): station lists live in ~/BirdNET-Pi/config/species_lists/custom/ and SPECIES_LIST picks
# the active one (include_species_list.txt becomes a link to it). A station's own list becomes
# species_lists/custom.txt and stays selected; otherwise a Brazilian state chosen before (INCLUDE_REGION).
if ! grep -qE '^SPECIES_LIST=' $BS_CONF; then
  sudo -u $USER mkdir -p $HOME/BirdNET-Pi/config/species_lists/custom
  selected=$(grep -oE '^INCLUDE_REGION=BR-[A-Z]{2}' $BS_CONF | cut -d= -f2)
  if [ -s $HOME/BirdNET-Pi/config/species_lists/include_species_list.txt ] && ! [ -L $HOME/BirdNET-Pi/config/species_lists/include_species_list.txt ]; then
    sudo -u $USER cp $HOME/BirdNET-Pi/config/species_lists/include_species_list.txt $HOME/BirdNET-Pi/config/species_lists/custom/custom.txt
    selected=custom
  fi
  echo "## SPECIES_LIST is the species list filter: empty = none, BR-<UF> = a Brazilian state, or a list of species_lists/" >> $BS_CONF
  echo "SPECIES_LIST=$selected" >> $BS_CONF
  sudo -u $USER python3 $HOME/BirdNET-Pi/scripts/select_species_list.py || true
  rm -f $HOME/BirdNET-Pi/include_species_list.state
fi

if ! grep -E '^BIRDWEATHER_ENABLED=' $BS_CONF &>/dev/null;then
  echo "## BIRDWEATHER_ENABLED: 1 = upload to BirdWeather (needs BIRDWEATHER_ID), 0 = paused (the token is kept)" >> $BS_CONF
  echo "BIRDWEATHER_ENABLED=1" >> $BS_CONF
fi

# livestream: a stop gives ffmpeg 5 s, then SIGKILL (it may not answer SIGTERM; the default 90 s held a change of
# location on "stopping the recording", owner 2026-10-22)
if [ -f $HOME/BirdNET-Pi/templates/livestream.service ] && ! grep -q '^TimeoutStopSec=' $HOME/BirdNET-Pi/templates/livestream.service; then
  sed -i 's/^RestartSec=3$/RestartSec=3\nTimeoutStopSec=5/' $HOME/BirdNET-Pi/templates/livestream.service
  systemctl daemon-reload
fi
# livestream: systemd must never give up restarting it (a replugged microphone stops ffmpeg repeatedly)
if [ -f $HOME/BirdNET-Pi/templates/livestream.service ] && ! grep -q '^StartLimitIntervalSec=0' $HOME/BirdNET-Pi/templates/livestream.service; then
  sed -i 's/^Requires=network-online.target$/Requires=network-online.target\nStartLimitIntervalSec=0/' $HOME/BirdNET-Pi/templates/livestream.service
  systemctl daemon-reload
fi

# PHP workers: 5 queued the pages that load several parts at once (owner 2026-10-09)
for pool in /etc/php/*/fpm/pool.d/www.conf; do
  if [ -f "$pool" ] && grep -q '^pm.max_children = 5$' "$pool"; then
    sudo sed -i 's/^pm.max_children = .*/pm.max_children = 12/; s/^pm.start_servers = .*/pm.start_servers = 3/; s/^pm.max_spare_servers = .*/pm.max_spare_servers = 6/' "$pool"
    sudo systemctl restart php\*-fpm.service
  fi
done
if ! grep -E '^PURGE_PROTECT_TOP_N=' $BS_CONF &>/dev/null;then
  echo "## PURGE_PROTECT_TOP_N: the best N detections of every species are never purged (plus confirmed reviews)" >> $BS_CONF
  echo "PURGE_PROTECT_TOP_N=3" >> $BS_CONF
fi
# Review loop: one verdict per detection (yes / no / unsure), additive table
sqlite3 $HOME/BirdNET-Pi/config/data/birds.db "CREATE TABLE IF NOT EXISTS detection_reviews (File_Name VARCHAR(100) PRIMARY KEY, Sci_Name VARCHAR(100), Com_Name VARCHAR(100), Date DATE, Confidence FLOAT, Verdict TEXT NOT NULL CHECK (Verdict IN ('yes','no','unsure')), Reviewed_At TEXT, Reason TEXT);" 2>/dev/null || true
sqlite3 $HOME/BirdNET-Pi/config/data/birds.db "PRAGMA table_info(detection_reviews)" 2>/dev/null | grep -q '|Reason|' || sqlite3 $HOME/BirdNET-Pi/config/data/birds.db "ALTER TABLE detection_reviews ADD COLUMN Reason TEXT;" 2>/dev/null || true
# every detection carries the id of its location (owner 2026-10-21): the Location column, its indexes, and the rows
# written before it tagged once by their coordinates (also done by the analysis on its first write)
python3 $HOME/BirdNET-Pi/scripts/utils/locations.py migrate 2>&1 || true
# the database's folder hands its group to the files SQLite creates in it (2026-10-29: on a station whose web pages run as
# another user, caddy, the -shm and -wal files came out caddy's; the analysis and Optimise, as the station user, got
# "attempt to write a readonly database"); the web user is in the station user's group, so both keep writing
if [ -d "$BS_DATA" ]; then
  chmod g+s "$BS_DATA"
  chgrp "$(stat -c %G "$BS_DATA")" "$BS_DATA"/birds.db* 2>/dev/null || true
  chmod g+rw "$BS_DATA"/birds.db* 2>/dev/null || true
fi
# the locations file is the station user's (2026-10-29: the biome step of the migrate above, as root, had made it root's)
[ -f $HOME/BirdNET-Pi/config/locations.json ] && chown "$(stat -c %U:%G $HOME/BirdNET-Pi/config)" $HOME/BirdNET-Pi/config/locations.json
# indexes (2026-10-23 test run, checked with EXPLAIN QUERY PLAN on a copy of a station's database): the "not this bird"
# list read by almost every count and list (an automatic index was built on each statement), the latest answers of
# Curation and the Purge Files counts get one. detections_Sci_Name (a prefix of detections_Sci_Date_Location) and
# detections_Sci_Date_Loc (the coordinates' covering index of 2026-10-20, replaced by the Location column) go once the
# Location indexes exist: no page query used them, and every detection written updated them (the indexes were 73 % of
# the file). Waits up to 60 s for the analysis' write lock.
sqlite3 -cmd ".timeout 60000" $HOME/BirdNET-Pi/config/data/birds.db "CREATE INDEX IF NOT EXISTS reviews_Verdict ON detection_reviews (Verdict, File_Name); CREATE INDEX IF NOT EXISTS reviews_Reviewed_At ON detection_reviews (Reviewed_At);" 2>/dev/null || true
sqlite3 -cmd ".timeout 60000" $HOME/BirdNET-Pi/config/data/birds.db "CREATE INDEX IF NOT EXISTS deleted_Sci_Name ON deleted_detections (Sci_Name);" 2>/dev/null || true
# the name index with the scientific name, the covering species index and the month change counters (2026-10-23 run 3)
sudo -u "$USER" "$HOME/BirdNET-Pi/scripts/db_indexes.sh" || true
if sqlite3 -cmd ".timeout 60000" $HOME/BirdNET-Pi/config/data/birds.db "SELECT 1 FROM sqlite_master WHERE type='index' AND name='detections_Sci_Date_Location'" 2>/dev/null | grep -q 1; then
  sqlite3 -cmd ".timeout 60000" $HOME/BirdNET-Pi/config/data/birds.db 'DROP INDEX IF EXISTS "detections_Sci_Name"; DROP INDEX IF EXISTS "detections_Sci_Date_Loc"; ANALYZE detection_reviews; ANALYZE deleted_detections;' 2>/dev/null || true
fi
# bird pictures (2026-10-23 test run): the Wikipedia provider stored originals and 3840 px thumbnails, up to 45 MB each,
# downloaded on every Now / Kiosk load. The stored thumbnails become the 330 px ones (a standard Wikimedia width; the
# original is at least as wide); the originals are marked old, so the next view asks Wikipedia for the small one
# (common.php Wikipedia::get_from_source). Idempotent: a 330 px address is left alone.
if [ -f $HOME/BirdNET-Pi/scripts/wikipedia.db ]; then
  python3 - $HOME/BirdNET-Pi/scripts/wikipedia.db << 'PYEOF' || true
import re
import sqlite3
import sys
con = sqlite3.connect(sys.argv[1], timeout=30)
try:
    rows = con.execute('SELECT sci_name, image_url FROM images').fetchall()
except sqlite3.Error:
    rows = []
thumbs = olds = 0
for sci, url in rows:
    plain = url.split('?')[0]
    m = re.match(r'(.*/thumb/.*/)(\d+)px-([^/]+)$', plain)
    if m:
        new = m.group(1) + '330px-' + m.group(3) if int(m.group(2)) > 330 else plain
        if new != url:
            con.execute('UPDATE images SET image_url = ? WHERE sci_name = ?', (new, sci))
            thumbs += 1
    else:
        con.execute("UPDATE images SET date_created = '2000-01-01' WHERE sci_name = ?", (sci,))
        olds += 1
con.commit()
if thumbs or olds:
    print(f'wikipedia.db: {thumbs} pictures now 330 px, {olds} originals to be replaced on their next view')
PYEOF
fi
# settings in force when each detection was made (owner 2026-10-09): location threshold, recording length and the species
# threshold; older rows keep them empty (shown as —)
for table in detections deleted_detections; do
  for column in "Loc_Thresh FLOAT" "Rec_Length INT" "Sp_Override FLOAT"; do
    sqlite3 $HOME/BirdNET-Pi/config/data/birds.db "PRAGMA table_info($table)" 2>/dev/null | grep -q "|${column%% *}|" \
      || sqlite3 $HOME/BirdNET-Pi/config/data/birds.db "ALTER TABLE $table ADD COLUMN $column;" 2>/dev/null || true
  done
done

if ! grep -E '^APPRISE_NOTIFY_REGION_RARE=' $BS_CONF &>/dev/null;then
  echo "## APPRISE_NOTIFY_REGION_RARE: 1 = species the location model does not expect here go to the Rare notification channel" >> $BS_CONF
  echo "APPRISE_NOTIFY_REGION_RARE=1" >> $BS_CONF
fi

if ! grep -E '^BIRDNET_USER=' $BS_CONF &>/dev/null;then
  echo "## BIRDNET_USER is for scripts to easily find where BirdSuite is installed" >> $BS_CONF
  echo "## DO NOT EDIT!" >> $BS_CONF
  echo "BIRDNET_USER=$(awk -F: '/1000/ {print $1}' /etc/passwd)" >> $BS_CONF
fi

if ! grep -E '^RTSP_STREAM_TO_LIVESTREAM=' $BS_CONF &>/dev/null;then
  echo "RTSP_STREAM_TO_LIVESTREAM=\"0\"" >> $BS_CONF
fi

SRC='^APPRISE_NOTIFICATION_BODY="A \$comname \(\$sciname\)  was just detected with a confidence of \$confidence"$'
DST='APPRISE_NOTIFICATION_BODY="A \$comname (\$sciname)  was just detected with a confidence of \$confidence (\$reason)"'
sed -i --follow-symlinks -E "s/$SRC/$DST/" $BS_CONF

if ! [ -f $HOME/BirdNET-Pi/config/notifications/body.txt ];then
  grep -E '^APPRISE_NOTIFICATION_BODY=".*"' $BS_CONF | cut -d '"' -f 2 | sudo_with_user tee "$HOME/BirdNET-Pi/config/notifications/body.txt"
  chmod g+w "$HOME/BirdNET-Pi/config/notifications/body.txt"
  sed -i --follow-symlinks -E 's/^APPRISE_NOTIFICATION_BODY=/#APPRISE_NOTIFICATION_BODY=/' $BS_CONF
fi

if ! grep -E '^INFO_SITE=' $BS_CONF &>/dev/null;then
  echo "INFO_SITE=\"ALLABOUTBIRDS\"" >> $BS_CONF
fi

if ! grep -E '^COLOR_SCHEME=' $BS_CONF &>/dev/null;then
  echo "COLOR_SCHEME=\"light\"" >> $BS_CONF
fi

if ! grep -E '^PURGE_THRESHOLD=' $BS_CONF &>/dev/null;then
  echo "PURGE_THRESHOLD=95" >> $BS_CONF
fi

if ! grep -E '^MAX_FILES_SPECIES=' $BS_CONF &>/dev/null;then
  echo "MAX_FILES_SPECIES=\"0\"" >> $BS_CONF
fi

if ! grep -E '^AUTOMATIC_UPDATE=' $BS_CONF &>/dev/null;then
  echo "AUTOMATIC_UPDATE=0" >> $BS_CONF
fi

if ! grep -E '^RARE_SPECIES_THRESHOLD=' $BS_CONF &>/dev/null;then
  echo '## RARE_SPECIES_THRESHOLD defines after how many days a species is considered as rare and highlighted on overview page' >> $BS_CONF
  echo "RARE_SPECIES_THRESHOLD=\"30\"" >> $BS_CONF
fi

# Bird photos come from Wikipedia (the Flickr option and the Bird Photo Source settings were retired,
# owner 2026-10-08): stations on FLICKR, None or without the key move to WIKIPEDIA
if grep -qE '^IMAGE_PROVIDER=' $BS_CONF; then
  sed -i --follow-symlinks 's/^IMAGE_PROVIDER=.*/IMAGE_PROVIDER=WIKIPEDIA/' $BS_CONF
else
  echo '## IMAGE_PROVIDER: bird photos from Wikipedia' >> $BS_CONF
  echo "IMAGE_PROVIDER=WIKIPEDIA" >> $BS_CONF
fi

if grep -E '^DATABASE_LANG=zh$' $BS_CONF &>/dev/null;then
  sed -i --follow-symlinks -E 's/^DATABASE_LANG=zh/DATABASE_LANG=zh_CN/' $BS_CONF
  install_language_label.sh
fi
# Portuguese split in two name sets (2026-10-07): labels_pt.json (upstream, Portugal names)
# became labels_pt_PT.json and labels_pt_BR.json carries the CBRO names. A station on the
# old 'pt' keeps exactly the names it had.
if grep -E '^DATABASE_LANG=pt$' $BS_CONF &>/dev/null;then
  sed -i --follow-symlinks -E 's/^DATABASE_LANG=pt$/DATABASE_LANG=pt_PT/' $BS_CONF
fi

[ -d $RECS_DIR/StreamData ] || sudo_with_user mkdir -p $RECS_DIR/StreamData
[ -L ${EXTRACTED}/spectrogram.png ] || sudo_with_user ln -sf ${RECS_DIR}/StreamData/spectrogram.png ${EXTRACTED}/spectrogram.png

if ! which inotifywait &>/dev/null;then
  ensure_apt_updated
  apt-get -y install inotify-tools
fi

apprise_version=$($HOME/BirdNET-Pi/birdnet/bin/python3 -c "import apprise; print(apprise.__version__)")
[[ $apprise_version != "1.13.1" ]] && sudo_with_user $HOME/BirdNET-Pi/birdnet/bin/pip3 install apprise==1.13.1  # CVE fixes (2026-10-23 run 3)
# the Streamlit statistics page (/stats, no menu entry since 2026-10-08) is gone (2026-10-23 test run: streamlit 1.44
# pinned pillow below 12, so the security floor pillow>=13 could not install): its service and packages go once
if [ -e /usr/lib/systemd/system/birdnet_stats.service ] || [ -e $HOME/BirdNET-Pi/templates/birdnet_stats.service ]; then
  systemctl disable --now birdnet_stats.service 2>/dev/null
  rm -f /usr/lib/systemd/system/birdnet_stats.service $HOME/BirdNET-Pi/templates/birdnet_stats.service
  systemctl daemon-reload
  sudo_with_user $HOME/BirdNET-Pi/birdnet/bin/pip3 uninstall -y streamlit plotly pyarrow pydeck altair > /dev/null 2>&1
fi
# pyarrow was put back by every update after that (pin removed, 2026-10-23 run 3): nothing uses it, it carries CVEs
$HOME/BirdNET-Pi/birdnet/bin/python3 -c "import pyarrow" 2> /dev/null \
  && sudo_with_user $HOME/BirdNET-Pi/birdnet/bin/pip3 uninstall -y pyarrow > /dev/null 2>&1
version=$($HOME/BirdNET-Pi/birdnet/bin/python3 -c "import seaborn; print(seaborn.__version__)")
[[ $version != "0.13.2" ]] && sudo_with_user $HOME/BirdNET-Pi/birdnet/bin/pip3 install seaborn==0.13.2
version=$($HOME/BirdNET-Pi/birdnet/bin/python3 -c "import suntime; print(suntime.__version__)")
[[ $version != "1.3.2" ]] && sudo_with_user $HOME/BirdNET-Pi/birdnet/bin/pip3 install suntime==1.3.2

PY_VERSION=$($HOME/BirdNET-Pi/birdnet/bin/python3 -c "import sys; print(f'{sys.version_info[0]}{sys.version_info[1]}')")
tf_version=$($HOME/BirdNET-Pi/birdnet/bin/python3 -c "import tflite_runtime; print(tflite_runtime.__version__)")
if { [ "$PY_VERSION" == 39 ] && [ "$tf_version" != "2.11.0" ]; } || { [ "$PY_VERSION" != 39 ] && [ "$tf_version" != "2.17.1" ]; }; then
  get_tf_whl
  # include our numpy dependants so pip can figure out which numpy version to install
  sudo_with_user $HOME/BirdNET-Pi/birdnet/bin/pip3 install $HOME/BirdNET-Pi/$WHL pandas librosa matplotlib
fi

ensure_python_package inotify inotify
ensure_python_package soundfile soundfile

if ! which inotifywait &>/dev/null;then
  ensure_apt_updated
  apt-get -y install inotify-tools
fi

install_tmp_mount
remove_unit_file birdnet_server.service /usr/local/bin/server.py
remove_unit_file extraction.service /usr/local/bin/extract_new_birdsounds.sh

if ! grep 'daemon' $HOME/BirdNET-Pi/templates/chart_viewer.service &>/dev/null;then
  sed -i "s|daily_plot.py.*|daily_plot.py --daemon --sleep 2|" ~/BirdNET-Pi/templates/chart_viewer.service
  systemctl daemon-reload && restart_services.sh
fi

if grep -q 'birdnet_server.service' "$HOME/BirdNET-Pi/templates/birdnet_analysis.service" &>/dev/null; then
    sed -i '/After=.*/d' "$HOME/BirdNET-Pi/templates/birdnet_analysis.service"
    sed -i '/Requires=.*/d' "$HOME/BirdNET-Pi/templates/birdnet_analysis.service"
    sed -i '/RuntimeMaxSec=.*/d' "$HOME/BirdNET-Pi/templates/birdnet_analysis.service"
    sed -i "s|ExecStart=.*|ExecStart=$HOME/BirdNET-Pi/birdnet/bin/python3 /usr/local/bin/birdnet_analysis.py|" "$HOME/BirdNET-Pi/templates/birdnet_analysis.service"
    systemctl daemon-reload && restart_services.sh
fi

TMP_MOUNT=$(systemd-escape -p --suffix=mount "$RECS_DIR/StreamData")
if ! [ -f "$HOME/BirdNET-Pi/templates/$TMP_MOUNT" ]; then
   install_birdnet_mount
   chown $USER:$USER "$HOME/BirdNET-Pi/templates/$TMP_MOUNT"
fi

# US-40: sound-repo upload timer (new unit on an existing station)
if ! [ -f "$HOME/BirdNET-Pi/templates/sound_repo_upload.timer" ]; then
  install_sound_repo_upload_service
  chown $USER:$USER "$HOME/BirdNET-Pi/templates/sound_repo_upload.service" "$HOME/BirdNET-Pi/templates/sound_repo_upload.timer"
  systemctl daemon-reload && systemctl start sound_repo_upload.timer
fi
# the hourly position announcement was dropped the same day (owner 2026-10-27: synchronous only, the map asks)
if [ -f /usr/lib/systemd/system/stations_announce.timer ]; then
  systemctl disable --now stations_announce.timer 2>/dev/null || true
  rm -f /usr/lib/systemd/system/stations_announce.timer /usr/lib/systemd/system/stations_announce.service \
    "$HOME/BirdNET-Pi/templates/stations_announce.timer" "$HOME/BirdNET-Pi/templates/stations_announce.service"
  systemctl daemon-reload
fi
[ -z "${SOUND_REPO_PATH}" ] || [ -d "${SOUND_REPO_PATH}" ] || sudo_with_user mkdir -p "${SOUND_REPO_PATH}"

if grep -q -e '-P log' $HOME/BirdNET-Pi/templates/birdnet_log.service ; then
  sed -i "s/-P log/--path log/" ~/BirdNET-Pi/templates/birdnet_log.service
  systemctl daemon-reload && restart_services.sh
fi

if grep -q -e '-P terminal' $HOME/BirdNET-Pi/templates/web_terminal.service ; then
  sed -i "s/-P terminal/--path terminal/" ~/BirdNET-Pi/templates/web_terminal.service
  systemctl daemon-reload && systemctl restart web_terminal.service
fi

if grep -q -e ' login' $HOME/BirdNET-Pi/templates/web_terminal.service ; then
  sed -i "s/ login/ bash -c 'read -p \"Login: \" username \&\& [[ \"\$username\" =~ ^[-_.a-z0-9]{1,30}\$ ]] \&\& su --pty -l \$username'/" ~/BirdNET-Pi/templates/web_terminal.service
  sed -i "/\[Service\]/a User=$BIRDNET_USER" ~/BirdNET-Pi/templates/web_terminal.service
  systemctl daemon-reload && systemctl restart web_terminal.service
fi

if grep -q -e 'Environment=XDG_RUNTIME_DIR=/run/user/' $HOME/BirdNET-Pi/templates/birdnet_recording.service; then
  sed -i '/^Environment=XDG_RUNTIME_DIR=\/run\/user\/[0-9]\+/d' $HOME/BirdNET-Pi/templates/birdnet_recording.service
  systemctl daemon-reload && restart_services.sh
fi

if grep -q -e 'Environment=XDG_RUNTIME_DIR=/run/user/' $HOME/BirdNET-Pi/templates/custom_recording.service; then
  sed -i '/^Environment=XDG_RUNTIME_DIR=\/run\/user\/[0-9]\+/d' $HOME/BirdNET-Pi/templates/custom_recording.service
  systemctl daemon-reload && restart_services.sh
fi

if grep -q -e 'Environment=XDG_RUNTIME_DIR=/run/user/' $HOME/BirdNET-Pi/templates/livestream.service; then
  sed -i '/^Environment=XDG_RUNTIME_DIR=\/run\/user\/[0-9]\+/d' $HOME/BirdNET-Pi/templates/livestream.service
  systemctl daemon-reload && restart_services.sh
fi

if grep -q 'php7.4-' /etc/caddy/Caddyfile &>/dev/null; then
  sed -i 's/php7.4-/php-/' /etc/caddy/Caddyfile
fi

if ! [ -L /etc/avahi/services/http.service ];then
  # symbolic link does not work here, so just copy
  cp -f $HOME/BirdNET-Pi/templates/http.service /etc/avahi/services/
  systemctl restart avahi-daemon.service
fi

if [ -L /usr/local/bin/analyze.py ];then
  rm -f /usr/local/bin/analyze.py
fi

if [ -L /usr/local/bin/birdnet_analysis.sh ];then
  rm -f /usr/local/bin/birdnet_analysis.sh
fi

# the weekly report was retired (owner 2026-10-09): its cron line goes
if grep -q '/usr/local/bin/weekly_report.sh' /etc/crontab; then
  # the #birdnet marker line before it goes too
  sudo sed -i -e '/^#birdnet$/{N;/weekly_report\.sh/d}' -e '\|/usr/local/bin/weekly_report.sh|d' /etc/crontab
fi
sudo rm -f /usr/local/bin/weekly_report.sh "$HOME"/BirdSongs/Extracted/weekly_report.php "$HOME"/BirdSongs/Extracted/history.php
# the automatic update on the chosen days and time (owner 2026-10-25): cron every 5 minutes instead of Sunday 03:00
sudo sed -i 's|^0 3 \* \* 0 \(.*birdsuite-root update -a.*\)$|*/5 * * * * \1|' /etc/crontab
# Clean state and update cron if all scripts are not installed
# 6 since 2026-10-23: the recording watchdog (scripts/recording_watchdog.py, every minute); 7 since 2026-10-27: the
# weekly eBird e-mail (scripts/ebird_report.py due, every 15 minutes)
# (also when there are more: an installer run twice appended the block twice, 2026-10-23 run 3)
if [ "$(grep -o "#birdnet" /etc/crontab | wc -l)" -ne 7 ]; then
  sudo sed -i -e '/^#birdnet$/d' \
    -e '\|recording_watchdog.py|d' \
    -e '\|ebird_report.py|d' \
    -e '\|/usr/local/bin/disk_check.sh|d' \
    -e '\|/usr/local/bin/disk_species_clean.sh|d' \
    -e '\|/usr/local/bin/cleanup.sh|d' \
    -e '\|/usr/local/bin/weekly_report.sh|d' \
    -e '\|/usr/local/bin/update_birdnet.sh|d' \
    -e '\|birdsuite-root update -a|d' /etc/crontab
  sed "s/\$USER/$USER/g" "$HOME"/BirdNET-Pi/templates/cleanup.cron >> /etc/crontab
  sed "s/\$USER/$USER/g" "$HOME"/BirdNET-Pi/templates/automatic_update.cron >> /etc/crontab
fi

set +x
# written again when the password is set and the file lacks the log protection (2026-10-23)
# and, with or without a password, when it lacks the security headers or the 404 for the raw files under /scripts, or
# still lists folders (2026-10-23 test run)
# the microphone rule that marks a removal at once (owner 2026-10-24: a quick replug never asked for the location)
if [ -f /etc/udev/rules.d/79-birdnet-mic.rules ] && ! grep -q 'mic_removed' /etc/udev/rules.d/79-birdnet-mic.rules; then
  install_mic_udev_rule
fi
# Tools > Browse files (owner 2026-10-24): Midnight Commander and its web terminal on an existing station
command -v mc > /dev/null || apt-get -y install mc
if ! [ -f "$HOME/BirdNET-Pi/templates/web_files.service" ]; then
  install_web_files_service
  chown $USER:$USER "$HOME/BirdNET-Pi/templates/web_files.service"
  systemctl daemon-reload && systemctl start web_files.service
fi
AUTH=$(grep -E 'basic_?auth /log' /etc/caddy/Caddyfile)
if { [ -n "${CADDY_PWD}" ] && [ -z "${AUTH}" ]; } \
  || { [ -n "${CADDY_PWD}" ] && ! grep -q '@scripts_private' /etc/caddy/Caddyfile; } \
  || { [ -n "${CADDY_PWD}" ] && { ! grep -q '/scripts/network.php' /etc/caddy/Caddyfile || grep -q '/scripts/locations.php' /etc/caddy/Caddyfile; }; } \
  || ! grep -q 'reverse_proxy /files' /etc/caddy/Caddyfile \
  || { [ -n "${CADDY_PWD}" ] && ! grep -q '/scripts/gps.php' /etc/caddy/Caddyfile; } \
  || { [ "${HTTPS_ALWAYS:-0}" != 1 ] && [ ! -s "$BS_STATE/https_temp" ] && grep -q 'on_demand_tls' /etc/caddy/Caddyfile; } \
  || { [ "${HTTPS_ALWAYS:-0}" = 1 ] && ! grep -q 'redir https' /etc/caddy/Caddyfile; } \
  || ! grep -q 'X-Content-Type-Options' /etc/caddy/Caddyfile \
  || ! grep -q 'respond @internal' /etc/caddy/Caddyfile \
  || ! grep -q 'redir @captive' /etc/caddy/Caddyfile \
  || ! grep -q 'birdsuite-(root|svc)' /etc/caddy/Caddyfile \
  || ! grep -q 'access.log' /etc/caddy/Caddyfile \
  || grep -q 'file_server browse' /etc/caddy/Caddyfile; then
  # checked by Caddy before it is installed (birdsuite-root caddyfile); http only unless HTTPS_ALWAYS since 2026-10-29 (US-126)
  /usr/local/sbin/birdsuite-root caddyfile > /dev/null 2>&1 || sudo /usr/local/bin/update_caddyfile.sh > /dev/null 2>&1
fi
set -x

if [ -L $HOME/BirdNET-Pi/model/labels_flickr.txt ]; then
  rm $HOME/BirdNET-Pi/model/labels_flickr.txt
fi
if [ -L $HOME/BirdNET-Pi/model/labels.txt ]; then
  sudo_with_user install_language_label.sh
else
  # the common names of model/labels.txt come from model/l18n/labels_<language>.json: written again on every update, so
  # a name fixed in the fork reaches the station (2026-10-28: a clean install kept "Southern Yellowthroat" after
  # Geothlypis velata got its Brazilian name); a station layer's own labels.txt is put back below
  (cd $HOME/BirdNET-Pi/scripts && sudo_with_user $HOME/BirdNET-Pi/birdnet/bin/python3 -c 'from utils.helpers import set_label_file; set_label_file()') \
    || echo "model/labels.txt could not be written again"
fi

# detections_Sci_Name is not created again here: a prefix of detections_Sci_Date_Location, dropped above (2026-10-23)

# Station layer (fork): update_birdnet.sh resets the tree to the branch, which puts
# the upstream model files back over the station's own (custom/<layer>/model). The
# analyzer loads them at start, so re-apply them before restart_services.sh below;
# otherwise the detections get the upstream common names (2026-09-17: a CBRO station
# wrote 36 detections with Portugal-Portuguese names after a webui deploy).
# STATION_LAYER comes from BirdSuite.conf; empty or missing = no layer, nothing to do.
if [ -n "${STATION_LAYER}" ] && [ -d "$BS_CONFIG/custom/${STATION_LAYER}/model" ]; then
  layer_model="$BS_CONFIG/custom/${STATION_LAYER}/model"
  for src in "$layer_model"/labels.txt "$layer_model"/l18n/labels_*.json; do
    [ -f "$src" ] || continue
    dst="$HOME/BirdNET-Pi/model/${src#"$layer_model"/}"
    # model/labels.txt may be a language symlink on stock installs - leave those alone
    [ -L "$dst" ] && continue
    if ! cmp -s "$src" "$dst"; then
      install -m 664 -o "$USER" -g "$USER" "$src" "$dst" \
        && echo "station layer ${STATION_LAYER}: re-applied ${dst#"$HOME"/BirdNET-Pi/}"
    fi
  done
fi

# boot location check (owner 2026-10-10): the setting and the service on stations installed before it
grep -q '^LOCATION_CHECK=' $BS_CONF || printf '\n## LOCATION_CHECK: 1 = at boot, compare the network position with the coordinates and ask when it is outside the radius of the current location (at least LOCATION_IP_ERROR_KM)\nLOCATION_CHECK=1\n' >> $BS_CONF
# the unit of an older install still names 100 km (2026-10-23): the description only
lc_unit=$HOME/BirdNET-Pi/templates/birdnet_location_check.service
if [ -f "$lc_unit" ] && grep -q '100 km' "$lc_unit"; then
  sed -i --follow-symlinks 's/^Description=.*/Description=BirdSuite location check (did the station move?)/' "$lc_unit"
  sudo systemctl daemon-reload
fi
if [ ! -f "$HOME/BirdNET-Pi/templates/birdnet_location_check.service" ]; then
  cat << EOF > "$HOME/BirdNET-Pi/templates/birdnet_location_check.service"
[Unit]
Description=BirdSuite location check (did the station move?)
Wants=network-online.target
After=network-online.target
[Service]
Type=oneshot
User=$USER
ExecStart=/usr/bin/python3 $HOME/BirdNET-Pi/scripts/location_check.py
[Install]
WantedBy=multi-user.target
EOF
  chown "$USER:$USER" "$HOME/BirdNET-Pi/templates/birdnet_location_check.service"
  install_unit birdnet_location_check.service
  systemctl daemon-reload
  systemctl enable birdnet_location_check.service
fi

# field access (US-120, owner 2026-10-20): the field and Bluetooth services on stations installed before them.
# A station managed by other means opts out with FIELD_SERVICES=0 in BirdSuite.conf.
if ! grep -q '^FIELD_SERVICES=0' $BS_CONF && [ ! -f "$HOME/BirdNET-Pi/templates/birdsuite_field.service" ]; then
  cat << EOF > "$HOME/BirdNET-Pi/templates/birdsuite_field.service"
[Unit]
Description=BirdSuite field access (station WiFi on demand, mDNS name, clock, offline queue)
After=NetworkManager.service avahi-daemon.service
[Service]
Restart=always
RestartSec=10
ExecStart=/usr/bin/python3 $HOME/BirdNET-Pi/scripts/field_daemon.py
[Install]
WantedBy=multi-user.target
EOF
  cat << EOF > "$HOME/BirdNET-Pi/templates/birdsuite_ble.service"
[Unit]
Description=BirdSuite Bluetooth field service (find and set up the station from a phone)
After=bluetooth.service birdsuite_field.service
Requires=bluetooth.service
[Service]
Restart=always
RestartSec=10
ExecStart=/usr/bin/python3 $HOME/BirdNET-Pi/scripts/field_ble.py
[Install]
WantedBy=multi-user.target
EOF
  chown "$USER:$USER" "$HOME/BirdNET-Pi/templates/birdsuite_field.service" "$HOME/BirdNET-Pi/templates/birdsuite_ble.service"
  install_unit birdsuite_field.service
  install_unit birdsuite_ble.service
  systemctl daemon-reload
  systemctl enable --now birdsuite_field.service birdsuite_ble.service || true
fi

# the field services run their code from start-up: restarted when the update brought a newer one (2026-10-29: the https
# time-out of US-126 never ran, the service still had the code of three days before)
for svc in birdsuite_field:field_daemon birdsuite_ble:field_ble; do
  unit=${svc%%:*}.service
  systemctl is-active --quiet "$unit" || continue
  since=$(date -d "$(systemctl show -p ActiveEnterTimestamp --value "$unit")" +%s 2>/dev/null) || continue
  newest=$(stat -c %Y "$HOME/BirdNET-Pi/scripts/${svc#*:}.py" "$HOME/BirdNET-Pi/scripts/field_net.py" 2>/dev/null | sort -n | tail -1)
  [ -n "$newest" ] && [ "$newest" -gt "$since" ] && systemctl restart "$unit"
done

# the settings history (owner 2026-10-31, install_services.sh install_settings_history): every change of BirdSuite.conf
# in state/settings_history.log, with the origin from the web server's access log
if [ ! -f "$HOME/BirdNET-Pi/templates/birdsuite_settings_history.path" ]; then
  cat << EOF > "$HOME/BirdNET-Pi/templates/birdsuite_settings_history.service"
[Unit]
Description=BirdSuite settings history (what changed in BirdSuite.conf, when and from where)
[Service]
Type=oneshot
User=$USER
ExecStart=/usr/bin/python3 $HOME/BirdNET-Pi/scripts/settings_history.py
EOF
  cat << EOF > "$HOME/BirdNET-Pi/templates/birdsuite_settings_history.path"
[Unit]
Description=BirdSuite settings history (watches BirdSuite.conf)
[Path]
PathChanged=$HOME/BirdNET-Pi/config/BirdSuite.conf
[Install]
WantedBy=multi-user.target
EOF
  chown "$USER:$USER" "$HOME"/BirdNET-Pi/templates/birdsuite_settings_history.*
  getent group caddy > /dev/null && usermod -aG caddy "$USER"
  install_unit birdsuite_settings_history.service
  install_unit birdsuite_settings_history.path
  systemctl daemon-reload
  systemctl enable --now birdsuite_settings_history.path || true
  systemctl start birdsuite_settings_history.service || true
fi

# retired settings (owner 2026-10-23): APPRISE_WEEKLY_REPORT (the weekly report is gone) and INCLUDE_REGION (replaced
# by SPECIES_LIST, read above only to migrate an old station); each line goes with the ## comment lines right above it
if grep -qE '^(APPRISE_WEEKLY_REPORT|INCLUDE_REGION|EXTERNAL_FOLDERS)=' $BS_CONF; then
  awk '/^##/ { c = c $0 "\n"; next } /^(APPRISE_WEEKLY_REPORT|INCLUDE_REGION|EXTERNAL_FOLDERS)=/ { c = ""; next } { printf "%s%s\n", c, $0; c = "" } END { printf "%s", c }' \
    $BS_CONF > $BS_CONF.tmp && cat $BS_CONF.tmp > $BS_CONF
  rm -f $BS_CONF.tmp
fi

# simple mode (owner 2026-10-29, US-125): a station updated from before keeps the controls it already uses, so
# MULTI_LOCATION / MULTI_STATION are written as 1 when it has more than one place of its own (Test / Playback does not
# count) or more than one registered station; else the default 0 is added below
if ! grep -q '^MULTI_LOCATION=' $BS_CONF; then
  if python3 -c "import json,sys; l=json.load(open(sys.argv[1])); l=l if isinstance(l,list) else l.get('locations',[]); sys.exit(0 if len([x for x in l if x.get('builtin')!='test'])>1 else 1)" "$BS_CONFIG/locations.json" 2>/dev/null; then
    printf '%s\n' '## MULTI_LOCATION: set by the update (US-125): this station already has several locations' 'MULTI_LOCATION=1' >> $BS_CONF
  fi
fi
if ! grep -q '^MULTI_STATION=' $BS_CONF; then
  if python3 -c "import json,sys; s=json.load(open(sys.argv[1])); s=s if isinstance(s,list) else s.get('stations',[]); sys.exit(0 if len(s)>1 else 1)" "$BS_CONFIG/stations.json" 2>/dev/null; then
    printf '%s\n' '## MULTI_STATION: set by the update (US-125): this station already knows other stations' 'MULTI_STATION=1' >> $BS_CONF
  fi
fi

# a changed default reaches the stations (owner 2026-10-25): config/.factory_defaults.applied keeps the defaults of the
# last update; a key whose value in BirdSuite.conf is still that old default takes the new one, a value the owner set
# is left alone (a station without the copy: the field-access times of before 2026-10-25, then the copy from now on)
BS_APPLIED=$BS_CONFIG/.factory_defaults.applied
if [ -f $BS_CONFIG/factory_defaults.conf ]; then
  if [ ! -f $BS_APPLIED ]; then
    printf '%s\n' FIELD_BOOT_WAIT_SEC=90 FIELD_LOST_WAIT_SEC=60 FIELD_AP_WINDOW_MIN=15 > $BS_APPLIED
  fi
  changed=''
  while IFS= read -r line; do
    case "$line" in [A-Z]*=*) ;; *) continue ;; esac
    key=${line%%=*}
    old=$(grep -m1 "^${key}=" $BS_APPLIED) || continue
    [ "$old" = "$line" ] && continue
    [ "$(grep -m1 "^${key}=" $BS_CONF)" = "$old" ] || continue
    NEW_LINE="$line" awk -v k="$key=" 'index($0, k) == 1 && !done { print ENVIRON["NEW_LINE"]; done = 1; next } { print }' \
      $BS_CONF > $BS_CONF.tmp && cat $BS_CONF.tmp > $BS_CONF
    rm -f $BS_CONF.tmp
    changed="${changed} ${key}"
  done < $BS_CONFIG/factory_defaults.conf
  [ -n "$changed" ] && echo "BirdSuite.conf: the new default of${changed}"
  cp $BS_CONFIG/factory_defaults.conf $BS_APPLIED
  chown --reference=$BS_CONF $BS_APPLIED 2>/dev/null
fi

# US-121 (2026-10-20): last of the migrations above, so their per-key values for existing stations win;
# every key of factory_defaults.conf (the defaults) that BirdSuite.conf lacks is added with its default and
# its comment, so the shell scripts that source BirdSuite.conf see a new setting too; keys already there are left alone
if [ -f $BS_CONFIG/factory_defaults.conf ]; then
  awk 'BEGIN { banner = 0 } banner < 3 && /^#####/ { banner++; next } banner >= 3' $BS_CONFIG/factory_defaults.conf | {
    comments=''
    added=''
    while IFS= read -r line; do
      case "$line" in
        '##'*) comments="${comments}${line}"$'\n' ;;
        [A-Z]*=*)
          key=${line%%=*}
          if ! grep -q "^${key}=" $BS_CONF; then
            [ -z "$added" ] && printf '\n## Added by an update from factory_defaults.conf (the defaults, US-121)\n' >> $BS_CONF
            printf '%s%s\n' "$comments" "$line" >> $BS_CONF
            added="${added} ${key}"
          fi
          comments='' ;;
        *) comments='' ;;
      esac
    done
    [ -n "$added" ] && echo "BirdSuite.conf: added the default of${added}"
  } || true
fi

# species table: look up, in the background, the labels missing from model/species_info.csv (a new model)
sudo -u "$USER" -H "$HOME/BirdNET-Pi/scripts/update_species_info.sh" || true

# the live stream password still the factory one (2026-10-23 test run): a random one, the same way the first-run
# questions make it, in BirdSuite.conf and icecast.xml; icecast and the stream start again with it. Not traced.
{ set +x; } 2>/dev/null
if grep -q '^ICE_PWD=birdnetpi$' $BS_CONF 2>/dev/null; then
  NEW_ICE_PWD=$(od -An -N8 -tx1 /dev/urandom | tr -d ' \n')
  sed -i --follow-symlinks "s/^ICE_PWD=birdnetpi\$/ICE_PWD=${NEW_ICE_PWD}/" $BS_CONF
  if [ -f /etc/icecast2/icecast.xml ]; then
    for i in source- relay- admin- master- ""; do
      sed -i "s/<${i}password>.*<\/${i}password>/<${i}password>${NEW_ICE_PWD}<\/${i}password>/g" /etc/icecast2/icecast.xml
    done
    systemctl is-active --quiet icecast2.service && systemctl restart icecast2.service
  fi
  systemctl is-active --quiet livestream.service && systemctl restart livestream.service
  unset NEW_ICE_PWD
  echo "BirdSuite.conf: the live stream password is no longer the factory one"
fi
set -x

# systemd units as root-owned copies of templates/ (owner 2026-10-23, least privilege): the links of the earlier versions
# let the station user change what systemd runs, and as whom; refreshed on every update, after the edits above
units_changed=
for t in $HOME/BirdNET-Pi/templates/*.service $HOME/BirdNET-Pi/templates/*.mount $HOME/BirdNET-Pi/templates/*.timer; do
  [ -f "$t" ] || continue
  u=/usr/lib/systemd/system/$(basename "$t")
  if [ -L "$u" ] || { [ -f "$u" ] && ! cmp -s "$t" "$u"; }; then
    rm -f "$u"
    install -o root -g root -m 0644 "$t" "$u"
    units_changed=1
  fi
done
[ -n "$units_changed" ] && systemctl daemon-reload
# "linked" units of the earlier versions: a link /etc/systemd/system/<unit> -> templates/ wins over the copy in
# /usr/lib/systemd/system, so systemd kept loading the station user's file (2026-10-23 test run 3); removed when the
# root-owned copy is there
for l in /etc/systemd/system/*; do
  [ -L "$l" ] || continue
  case "$(readlink "$l")" in
    $HOME/BirdNET-Pi/templates/*)
      t=$(basename "$(readlink "$l")")
      [ -f "/usr/lib/systemd/system/$t" ] && ! [ -L "/usr/lib/systemd/system/$t" ] && rm -f "$l" && units_changed=1 ;;
  esac
done
# the enablement links made while the units were links still point into templates/ (systemd would load the unit from
# there): enabled again, so they point at the root-owned copy
for w in /etc/systemd/system/*.wants/*; do
  [ -L "$w" ] || continue
  case "$(readlink "$w")" in
    $HOME/BirdNET-Pi/templates/*)
      # n = the enabled name (an instance like avahi-alias@host.local.service), t = its unit file (avahi-alias@.service)
      n=$(basename "$w"); t=$(basename "$(readlink "$w")")
      ln -sf "/usr/lib/systemd/system/$t" "$w"
      units_changed=1 ;;
  esac
done
# the avahi-alias@<host>.local unit (upstream) published the host's own name a second time with the address of the
# start-up (2026-10-25: on the cable, birdsuite.local still answered with the old WiFi address); avahi-daemon publishes
# the host name itself and the field service the station's own name, both with the address in use
for w in /etc/systemd/system/*.wants/avahi-alias@*.service; do
  [ -L "$w" ] || continue
  systemctl disable --now "$(basename "$w")" > /dev/null 2>&1
  rm -f "$w"
  units_changed=1
done
if [ -f /usr/lib/systemd/system/avahi-alias@.service ]; then
  rm -f /usr/lib/systemd/system/avahi-alias@.service $HOME/BirdNET-Pi/templates/avahi-alias@.service
  units_changed=1
fi
[ -n "$units_changed" ] && systemctl daemon-reload

# the database in write-ahead-log mode (2026-10-23 test run 3): with the rollback journal the pages and the backup waited
# for the analysis writer ("database is locked", Dashboard 24-35 s); the mode stays in the file, set once
if [ -f "$HOME/BirdNET-Pi/config/data/birds.db" ] \
  && [ "$(sudo -u "$USER" sqlite3 -cmd '.timeout 10000' "$HOME/BirdNET-Pi/config/data/birds.db" 'PRAGMA journal_mode;' 2>/dev/null)" != wal ]; then
  sudo -u "$USER" sqlite3 -cmd '.timeout 10000' "$HOME/BirdNET-Pi/config/data/birds.db" 'PRAGMA journal_mode=WAL;' > /dev/null \
    && echo "birds.db: write-ahead log on" || echo "birds.db: write-ahead log not set (busy), next update" >&2
fi

# the services' systemd sandboxing (install_helpers.sh, 2026-10-23 run 3): written or refreshed; the reload and the
# restart below apply it
install_unit_hardening && echo "systemd: service sandboxing updated"

# the number format follows the BirdSuite language (owner 2026-10-23): set once where LC_NUMERIC is still unset
if ! grep -q '^LC_NUMERIC=' /etc/default/locale 2>/dev/null && [ -x /usr/local/sbin/birdsuite-root ]; then
  bs_lang=$(sed -n 's/^UI_LANG=//p' $BS_CONF | tr -d '"'); [ -z "$bs_lang" ] && bs_lang=$(sed -n 's/^DATABASE_LANG=//p' $BS_CONF | tr -d '"')
  [ "$bs_lang" = pt ] && bs_lang=pt_BR
  /usr/local/sbin/birdsuite-root numeric "${bs_lang:-en}" 2>/dev/null || /usr/local/sbin/birdsuite-root numeric en || true
fi

# update snippets above

systemctl daemon-reload
restart_services.sh
