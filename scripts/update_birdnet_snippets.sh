#!/usr/bin/env bash
set -x
# Update BirdSuite
trap 'exit 1' SIGINT SIGHUP
# the station user, named by birdsuite-root update, and its home: the station's data and the code that runs as that
# user are there (core.env layout); root never takes them from the settings
if [ -z "${BIRDNET_USER:-}" ] || ! HOME=$(getent passwd "$BIRDNET_USER" | cut -d: -f6) || [ -z "$HOME" ]; then
  echo "the station user is unknown: run the update with birdsuite-root update"
  exit 1
fi
echo "BIRDNET_USER: ${BIRDNET_USER}"
USER=$BIRDNET_USER
export BS_HOME=$HOME
source "$(dirname "$(readlink -f "${BASH_SOURCE[0]}")")/paths.sh"
# the code root runs is this file's own folder: birdsuite-root update runs it from root's own copy of the program
# (fetched by root), never from the station user's folder (security 2026-11-02)
my_dir=$(dirname "$(readlink -f "${BASH_SOURCE[0]}")")
source "$my_dir/install_helpers.sh"
# root writes into the settings and the station's templates/ below: never through a link the station user could point
# anywhere (a link there is not something BirdSuite makes)
if [ -L "$BS_CONF" ] || [ -L "$BS_CONFIG" ] || [ -L "$BS_TEMPLATES" ] || [ -L "$BS_PROG" ]; then
  echo "the settings, config/, templates/ or the program folder is a link: the update stops (remove the link, then update again)"
  exit 1
fi
find "$BS_TEMPLATES" -maxdepth 1 -type l -delete 2>/dev/null
# the unit files older installers made in the station's templates/ (the units come from templates/units now); a file
# this version ships there (the avahi service) stays
for t in "$BS_TEMPLATES"/*.service "$BS_TEMPLATES"/*.mount "$BS_TEMPLATES"/*.timer "$BS_TEMPLATES"/*.path; do
  [ -f "$t" ] && [ ! -e "$BS_TPL/$(basename "$t")" ] && rm -f "$t"
done

# least privilege helpers (owner 2026-10-23): the named root steps of the station (birdsuite-svc, birdsuite-root) and
# the core definitions they read, copied root-owned on every update so the station user cannot change them; the sudo rule
install_root_helpers
# the pages as the station user and no sudo rule for the web server (a station installed before 2026-10-23 is brought in line)
install_php_pool
# ssh made harder to abuse (password login stays: a distributed station has no owner key) and Debian's security updates
install_ssh_hardening
install_extra_hardening
install_unattended_upgrades
# the web password kept only as its hash (SEC-15): a plain one left by an older station is replaced
"$BS_PYTHON" "$BS_SCRIPTS/web_password_hash.py" 2>/dev/null || python3 "$BS_SCRIPTS/web_password_hash.py" || true
# the station user's tools by name for its cron lines and units (run as that user; root never calls them by these links);
# a link left by an older version that points to nothing in the station user's home goes
ln -sf "$BS_SCRIPTS"/* "$BS_BIN"/
for l in "$BS_BIN"/*; do
  [ -L "$l" ] && [ ! -e "$l" ] && [[ $(readlink "$l") == "$BS_HOME"/* ]] && rm -f "$l"
done
systemctl daemon-reload
install_polkit_rule
# Sets proper permissions and ownership
find "$BS_PROG" "$BS_RECS" -type f ! -perm -g+wr -exec chmod g+wr {} + 2>/dev/null
find "$BS_PROG" "$BS_RECS" -not -user $USER -execdir sudo -E chown $USER:$USER {} \+
# nothing writable by every account (2026-10-23 security run: every file was a+w, also the scripts two services run as
# root); the web server (caddy) is in the station user's group, so group write is enough for the pages
find "$BS_PROG" "$BS_RECS" -path "$BS_VENV" -prune -o -perm /o=w ! -type l -exec chmod g+rwX,o-w {} + 2>/dev/null
# the token store (app passwords, bot token) stays private to the station user (owner 2026-10-22)
[ -f "$BS_NOTIFY/apprise-tokens.txt" ] && chmod u=rw,go= "$BS_NOTIFY/apprise-tokens.txt"
chmod g+r $HOME
# the program folder entered by the web server (a station installed on 2026-11-03 got it u=rwx,g=rw: pages without
# pictures, stylesheets or scripts; newinstaller.sh)
chmod g+rx "$BS_PROG"

# remove world-writable perms
chmod -R o-w "$BS_TEMPLATES"/*

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
  [[ $PIP_UPDATED != "UPDATED" ]] && sudo_with_user "$BS_PIP" install -U pip && PIP_UPDATED="UPDATED"
}

ensure_python_package() {
  # ensure_python_package pytest pytest==7.1.2
  pytest_installation_status=$("$BS_PYTHON" -c 'import pkgutil; import sys; print("installed" if pkgutil.find_loader(sys.argv[1]) else "not installed")' "$1")
  if [[ "$pytest_installation_status" = "not installed" ]];then
    ensure_pip_updated
    sudo_with_user "$BS_PIP" install "$2"
  fi
}


# update snippets below
# the database's folder hands its group to the files SQLite creates in it (2026-10-29: on a station whose web pages run as
# another user, caddy, the -shm and -wal files came out caddy's; the analysis and Optimise, as the station user, got
# "attempt to write a readonly database"); the web user is in the station user's group, so both keep writing
if [ -d "$BS_DATA" ]; then
  chmod g+s "$BS_DATA"
  chgrp "$(stat -c %G "$BS_DATA")" "$BS_DATA"/birds.db* 2>/dev/null || true
  chmod g+rw "$BS_DATA"/birds.db* 2>/dev/null || true
fi
# the locations file is the station user's
[ -d "$BS_LOCATIONS" ] && chown -R "$(stat -c %U:%G "$BS_CONFIG")" "$BS_LOCATIONS"
[ -d "$BS_STREAMDATA" ] || sudo_with_user mkdir -p "$BS_STREAMDATA"
[ -L "$BS_EXTRACTED/spectrogram.png" ] || sudo_with_user ln -sf "$BS_STREAMDATA/spectrogram.png" "$BS_EXTRACTED/spectrogram.png"
# the links of the pages and tools to the program folder (install_helpers.sh), made again: they follow the program folder
install_links

if ! which inotifywait &>/dev/null;then
  ensure_apt_updated
  apt-get -y install inotify-tools
fi

# the station's Python packages are the station user's files: only that user imports them
apprise_version=$(sudo_with_user "$BS_PYTHON" -c "import apprise; print(apprise.__version__)")
[[ $apprise_version != "1.13.1" ]] && sudo_with_user "$BS_PIP" install apprise==1.13.1  # CVE fixes (2026-10-23 run 3)
version=$(sudo_with_user "$BS_PYTHON" -c "import suntime; print(suntime.__version__)")
[[ $version != "1.3.2" ]] && sudo_with_user "$BS_PIP" install suntime==1.3.2

tf_version=$(sudo_with_user "$BS_PYTHON" -c "import tflite_runtime; print(tflite_runtime.__version__)")
if [ "$tf_version" != "2.17.1" ]; then
  get_tf_whl
  # include our numpy dependants so pip can figure out which numpy version to install
  sudo_with_user "$BS_PIP" install "$BS_PROG/$WHL" librosa
fi

ensure_python_package inotify inotify
ensure_python_package soundfile soundfile
# the security floors of the Python packages (requirements-security.txt) and the retired packages removed, every update
sudo_with_user "$BS_SCRIPTS/python_security.sh" || echo "the Python security floors could not all be applied: the next update tries again"
[ -z "${SOUND_REPO_PATH}" ] || [ -d "${SOUND_REPO_PATH}" ] || sudo_with_user mkdir -p "${SOUND_REPO_PATH}"

if ! [ -L /etc/avahi/services/http.service ];then
  # symbolic link does not work here, so just copy
  cp -f "$BS_TPL/http.service" /etc/avahi/services/
  systemctl restart avahi-daemon.service
fi

# the station's cron lines = the templates/*.cron of this version (install_helpers.sh install_cron)
install_cron

# the Caddyfile written again on every update, checked by Caddy before it is installed (birdsuite-root caddyfile)
set +x
"$BS_ROOT_HELPER" caddyfile > /dev/null 2>&1 || "$my_dir/update_caddyfile.sh" > /dev/null 2>&1
set -x

# the common names of model/labels.txt come from model/l18n/labels_<language>.json: written again on every update, so
# a name fixed in the fork reaches the station (2026-10-28); a station layer's own labels.txt is put back below
(cd "$BS_SCRIPTS" && sudo_with_user "$BS_PYTHON" -c 'from utils.helpers import set_label_file; set_label_file()') \
  || echo "model/labels.txt could not be written again"

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
    dst="$BS_MODEL/${src#"$layer_model"/}"
    # model/labels.txt may be a language symlink on stock installs - leave those alone
    [ -L "$dst" ] && continue
    if ! cmp -s "$src" "$dst"; then
      install -m 664 -o "$USER" -g "$USER" "$src" "$dst" \
        && echo "station layer ${STATION_LAYER}: re-applied ${dst#"$BS_PROG"/}"
    fi
  done
fi

# the field services run their code from start-up: restarted when the update brought a newer one (2026-10-29: the https
# time-out of US-126 never ran, the service still had the code of three days before)
for svc in "$BS_SVC_FIELD":field_daemon "$BS_SVC_BLE":field_ble; do
  unit=${svc%%:*}.service
  systemctl is-active --quiet "$unit" || continue
  since=$(date -d "$(systemctl show -p ActiveEnterTimestamp --value "$unit")" +%s 2>/dev/null) || continue
  newest=$(stat -c %Y "$BS_SCRIPTS/${svc#*:}.py" "$BS_SCRIPTS/field_net.py" 2>/dev/null | sort -n | tail -1)
  [ -n "$newest" ] && [ "$newest" -gt "$since" ] && systemctl restart "$unit"
done

# a changed default reaches the stations (owner 2026-10-25): config/.factory_defaults.applied keeps the defaults of the
# last update; a key whose value in BirdSuite.conf is still that old default takes the new one, a value the owner set
# is left alone
BS_APPLIED=$BS_CONFIG/.factory_defaults.applied
if [ -f $BS_CONFIG/factory_defaults.conf ]; then
  [ -f $BS_APPLIED ] || cp $BS_CONFIG/factory_defaults.conf $BS_APPLIED
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

# US-121 (2026-10-20): every key of factory_defaults.conf (the defaults) that BirdSuite.conf lacks is added with its default and
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
sudo -u "$USER" -H "$BS_SCRIPTS/update_species_info.sh" || true
# the database schema of this version: tables, indexes, triggers and the columns added since (createdb.sh, idempotent;
# owner 2026-11-04: the microphone columns Mic, Mic_Port, Mic_Gain)
# the analysis of the old version stopped while the schema changes (2026-11-05: it kept writing a column the
# migration had just dropped until restart_services.sh below, and 24 detections were not written); started again there
analysis_unit=$BS_SVC_ANALYSIS.service
systemctl is-active --quiet "$analysis_unit" && systemctl stop "$analysis_unit" || true
sudo -u "$USER" -H "$BS_SCRIPTS/createdb.sh" || true
# the species lists in one scientific name per line (one index only, 2026-11-05; once, then nothing to do)
sudo -u "$USER" -H "$BS_PYTHON" "$BS_SCRIPTS/migrate_lists_sci.py" || true
# the microphone the detections keep, written once now (the field service keeps it current)
sudo -u "$USER" -H "$BS_SCRIPTS/mic_state.sh" > /dev/null 2>&1 || true

# the system in step with what this version ships (owner 2026-11-02, install_helpers.sh): the station's systemd units
# are the templates/units of this version (a unit no longer shipped is stopped, disabled and removed, a new one
# installed; a renamed unit keeps the state of the one it replaces), root-owned copies; the udev rules of templates/udev
install_units --start || true
apply_ramdisk_policy
install_udev_rules
# the services' systemd sandboxing (install_helpers.sh, 2026-10-23 run 3): written or refreshed; the reload and the
# restart below apply it
install_unit_hardening && echo "systemd: service sandboxing updated"

# the log policy (owner 2026-11-01, System › Disk Space): journal LOG_RETENTION_DAYS days and 200 MB, rotated logs
# compressed, applied again with the current setting
if [ -x "$BS_ROOT_HELPER" ]; then
  log_days=$(sed -n 's/^LOG_RETENTION_DAYS=//p' $BS_CONF | tr -d '"')
  "$BS_ROOT_HELPER" logs "${log_days:-30}" 2>/dev/null || "$BS_ROOT_HELPER" logs 30 || true
fi

# update snippets above

systemctl daemon-reload
"$my_dir/restart_services.sh"
