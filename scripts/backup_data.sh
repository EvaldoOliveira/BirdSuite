#!/usr/bin/env bash
# Backup and restore a BirdSuite station (owner 2026-10-22): the archive holds the folder config/ (settings, species
# lists, notifications, locations, the database, the station layer) and, in full mode, the audio
# (BirdSongs/Extracted/By_Date and Charts). -m settings (owner 2026-10-24, Tools › Backup/Restore › Settings): config/
# without the database (the detections go by Backup / Export). Not saved: state/ (running state) and
# config/factory_defaults.conf (it ships with the program). A restore replaces config/ (and the audio when the archive
# has it); an archive without the database keeps the station's own.

source "$(dirname "$(readlink -f "${BASH_SOURCE[0]}")")/paths.sh"
my_dir=$BS_HOME/scripts

if [ "$EUID" == 0 ]
  then echo "Please run as a non-root user."
  exit
fi

usage() { echo "Usage: $0 -a backup|restore|size -f <backup_file> [-m full|config|settings]" 1>&2; exit 1; }

unset -v ACTION
unset -v ARCHIVE
unset -v QUIET
MODE=full
while getopts "a:f:m:" o; do
  case "${o}" in
    a)
      ACTION=${OPTARG}
      [ "$ACTION" == "backup" ] || [ "$ACTION" == "restore" ] || [ "$ACTION" == "size" ] || usage
      ;;
    f)
      ARCHIVE=${OPTARG}
      ;;
    m)
      MODE=${OPTARG}
      [ "$MODE" == "full" ] || [ "$MODE" == "config" ] || [ "$MODE" == "settings" ] || usage
      ;;
    *)
      usage
      ;;
  esac
done

[ -z "$ACTION" ] && usage && exit 1
if [ "$ACTION" != "size" ]; then
  [ -z "$ARCHIVE" ] && usage && exit 1
  [ "$ARCHIVE" == '-' ] && [ "$ACTION" == "backup" ] && QUIET=1
fi

MEG=1048576
UNPACK="/home/$BIRDNET_USER/BirdSongs/tmp"
EXTRACTED_DIR="/home/$BIRDNET_USER/BirdSongs/Extracted"
AUDIO=(By_Date Charts)

log() {
  [ -z "$QUIET" ] && echo "$1"
}

# the parts of the archive: config/ always, the audio in full mode
parts() {
  echo "$BS_CONFIG"
  if [ "$MODE" == "full" ]; then
    for a in "${AUDIO[@]}"; do [ -e "$EXTRACTED_DIR/$a" ] && echo "$EXTRACTED_DIR/$a"; done
  fi
}

backup_check() {
  [ -f "$BS_CONF" ] || { echo "No settings file $BS_CONF: nothing to back up"; exit 1; }
  if [ "$ARCHIVE" != '-' ]; then
    [ -f "$ARCHIVE" ] && echo "$ARCHIVE already exists" && exit 1
    estimated_backup_size
    AVAILABLE=$(df --output=avail --block-size=1 "$(dirname "$ARCHIVE")" | grep '[[:digit:]]')
    log "Estimated space needed: $((ESTIMATED / MEG))M ($ESTIMATED), space available: $((AVAILABLE / MEG))M ($AVAILABLE)"
    [ "$ESTIMATED" -gt "$AVAILABLE" ] && echo "Not enough space available on $(dirname "$ARCHIVE")" && exit 1
  fi
}

backup() {
  log "Starting backup ($MODE), this might take a while"
  # the database through SQLite's own backup: a consistent copy while the station runs
  local snap
  snap=$(mktemp -d)
  # messages to stderr: with -f - the archive itself goes to stdout (the web download)
  if [ "$MODE" == "settings" ]; then
    : > "$snap/.settings"
  else
    sqlite3 "$BS_DB" ".backup '$snap/birds.db'" || { rm -rf "$snap"; echo "Database copy failed" >&2; exit 1; }
  fi
  # --hard-dereference: files are stored as themselves, never as hard links (2026-10-23 test restore: a clip written while
  # the backup ran reused the inode of a settings file rewritten meanwhile, tar stored it as a link to that file and the
  # clip was lost on a partial restore)
  local args=(--create --hard-dereference -f "$ARCHIVE" --exclude=config/factory_defaults.conf --exclude='config/data/birds.db*')
  if [ "$MODE" == "settings" ]; then
    args+=(--exclude='config/data' -C "$BS_HOME" config)
  else
    args+=(-C "$BS_HOME" config -C "$snap" --transform 's|^birds\.db$|config/data/birds.db|' birds.db)
  fi
  if [ "$MODE" == "full" ]; then
    for a in "${AUDIO[@]}"; do [ -e "$EXTRACTED_DIR/$a" ] && args+=(-C "$EXTRACTED_DIR" "$a"); done
  fi
  tar "${args[@]}"
  local rc=$?
  rm -rf "$snap"
  # 1 = a file changed while read (BirdDB.txt, new clips: the station keeps running): the archive is complete
  [ $rc -le 1 ] || { echo "Backup failed" >&2; logger -t birdsuite-backup "backup ($MODE) failed"; exit 1; }
  # when and what: the Doctor (System › Status) shows the age of the last backup
  mkdir -p "$BS_STATE" && date '+%Y-%m-%d %H:%M:%S '"$MODE" > "$BS_STATE/last_backup"
  logger -t birdsuite-backup "backup ($MODE) done"
  log "Backup done"
}

estimated_backup_size() {
  ESTIMATED=$(du -s -c -b $(parts) 2> /dev/null | grep total | cut -f 1)
}

restore_check() {
  if [ "$ARCHIVE" != '-' ]; then
    [ ! -f "$ARCHIVE" ] && echo "$ARCHIVE" not found && exit 1
    AVAILABLE=$(df --output=avail --block-size=1 "/home/$BIRDNET_USER/" | grep '[[:digit:]]')
    ESTIMATED=$(( $(du -s -b "$ARCHIVE" | cut -f 1) * 1005 / 1000 ))  # integers: the locale may use a decimal comma
    log "Estimated space needed: $((ESTIMATED / MEG))M ($ESTIMATED), space available: $((AVAILABLE / MEG))M ($AVAILABLE)"
    [ "$ESTIMATED" -gt "$AVAILABLE" ] && echo "Not enough space available on /home/$BIRDNET_USER/" && exit 1
    log "Checking backup file"
    tar --list -f "$ARCHIVE" config/BirdSuite.conf &> /dev/null \
      || { echo "Missing config/BirdSuite.conf: not a BirdSuite backup?"; exit 1; }
  fi
}

late_restore_check() {
  [ -e "${UNPACK}/config/BirdSuite.conf" ] || { echo "Missing 'config/BirdSuite.conf': not a BirdSuite backup?"; rm -rf "${UNPACK}"; exit 1; }
}

unpack() {
  log "Starting unpacking, this might take a while"
  rm -fr "${UNPACK}"
  mkdir "${UNPACK}"
  tar --extract -p -f "$ARCHIVE" -C "${UNPACK}"
}

restore() {
  log "Starting restore"
  # config/: the archive's, with this installation's factory defaults; the station layer of this installation stays
  # when the archive has none
  cp -p "$BS_CONFIG/factory_defaults.conf" "${UNPACK}/config/" 2> /dev/null
  # (also when the archive only has a link to a layer that is not on this device)
  if { ! [ -e "${UNPACK}/config/custom" ]; } && { [ -e "$BS_CONFIG/custom" ] || [ -L "$BS_CONFIG/custom" ]; }; then
    rm -f "${UNPACK}/config/custom" 2> /dev/null
    mv "$BS_CONFIG/custom" "${UNPACK}/config/custom"
  fi
  # a settings archive has no database: the station keeps its own (the detections, config/data)
  if [ ! -e "${UNPACK}/config/data/birds.db" ] && [ -d "$BS_DATA" ]; then
    log "Settings only: the detections of this station stay"
    rm -rf "${UNPACK}/config/data"
    mv "$BS_DATA" "${UNPACK}/config/data"
  fi
  # the old folder aside until the new one is in place (a failed move must not leave the station without config/)
  rm -rf "$BS_CONFIG.old"
  mv "$BS_CONFIG" "$BS_CONFIG.old" || { echo "Cannot set the old config/ aside" >&2; exit 1; }
  if ! mv "${UNPACK}/config" "$BS_CONFIG"; then
    mv "$BS_CONFIG.old" "$BS_CONFIG"
    echo "Restore failed: config/ left as it was" >&2
    exit 1
  fi
  rm -rf "$BS_CONFIG.old"
  for a in "${AUDIO[@]}"; do
    if [ -e "${UNPACK}/$a" ]; then
      rm -rf "${EXTRACTED_DIR:?}/$a"
      mv "${UNPACK}/$a" "$EXTRACTED_DIR/"
    fi
  done
  log "Fixing up the settings for this installation"
  local old_user
  old_user=$(sed -n 's/^BIRDNET_USER=//p' "$BS_CONF" | tr -d '"')
  if [ -n "$old_user" ] && [ "$old_user" != "$BIRDNET_USER" ]; then
    sed -i "s/^BIRDNET_USER=.*/BIRDNET_USER=$BIRDNET_USER/" "$BS_CONF"
    sed -i "s|/home/$old_user/|/home/$BIRDNET_USER/|g" "$BS_CONF"
  fi
  # the list in force is a link into species_lists/custom: point it at this installation
  local active="$BS_LISTS/include_species_list.txt"
  if [ -L "$active" ]; then
    ln -sfn "$BS_CUSTOM_LISTS/$(basename "$(readlink "$active")")" "$active"
  fi
  chmod -R g+rwX "$BS_CONFIG"
  "$my_dir/install_language_label.sh"
  # a backup of the old names (folders by common name) or of another Common name Language takes this station's layout
  # at once: clips by the scientific name only (owner 2026-10-24, migrate_names.py); if it fails, Status offers Migrate
  log "Species names: clips by the scientific name"
  (cd "$my_dir" && "$HOME/BirdNET-Pi/birdnet/bin/python3" ./migrate_names.py) || log "Species names not migrated: see Status"
  rm -fr "${UNPACK}"
  log "Restore done"
}

[ "$ACTION" == "backup" ] && backup_check
[ "$ACTION" == "restore" ] && restore_check
if [ "$ACTION" == "size" ]; then
  estimated_backup_size
  echo "$ESTIMATED"
  exit
fi

cleanup() {
  rm -fr "${UNPACK}"
  "$my_dir/restart_services.sh" &> /dev/null
  exit
}
trap cleanup SIGINT SIGTERM SIGABRT

[ "$ACTION" == "restore" ] && unpack
[ "$ACTION" == "restore" ] && late_restore_check

if [ "$ACTION" == "backup" ]; then
  backup
else
  log "Stopping services"
  "$my_dir/stop_core_services.sh"
  restore
  log "Restarting services"
  "$my_dir/restart_services.sh" &> /dev/null
fi
