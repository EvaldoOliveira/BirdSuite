#!/usr/bin/env bash
# This installs the services that have been selected
set -x # Uncomment to enable debugging
trap 'rm -f ${tmpfile}' EXIT
trap 'exit 1' SIGINT SIGHUP
tmpfile=$(mktemp)

config_file=$my_dir/config/BirdSuite.conf  # the station's settings (config/ since 2026-10-22; the 2026-10-23 clean install found the old path)
export USER=$USER
export HOME=$HOME

export PYTHON_VIRTUAL_ENV="$HOME/BirdNET-Pi/birdnet/bin/python3"

install_depends() {
  apt install -y debian-keyring debian-archive-keyring apt-transport-https
  curl -1sLf 'https://dl.cloudsmith.io/public/caddy/stable/gpg.key' | sudo gpg --yes --dearmor -o /usr/share/keyrings/caddy-stable-archive-keyring.gpg
  curl -1sLf 'https://dl.cloudsmith.io/public/caddy/stable/debian.deb.txt' | sudo tee /etc/apt/sources.list.d/caddy-stable.list
  apt -qqq update && apt -qqy upgrade
  echo "icecast2 icecast2/icecast-setup boolean false" | debconf-set-selections
  apt install --no-install-recommends -qqy caddy sqlite3 php-sqlite3 php-fpm php-curl php-xml php-zip php-mbstring php icecast2 \
    pulseaudio avahi-utils sox libsox-fmt-mp3 alsa-utils ffmpeg \
    wget curl unzip bc \
    python3-pip python3-venv lsof net-tools inotify-tools \
    network-manager python3-dbus python3-gi iw bluez mc
}

set_hostname() {
  if [ "$(hostname)" == "raspberrypi" ];then
    hostnamectl set-hostname birdsuite
    sed -i 's/raspberrypi/birdsuite/g' /etc/hosts
  fi
}

update_etc_hosts() {
  sed -i -e "s/$(hostname).local/$(hostname).local ${BIRDNETPI_URL//https:\/\/} ${WEBTERMINAL_URL//https:\/\/} ${BIRDNETLOG_URL//https:\/\/}/g" /etc/hosts
}

install_scripts() {
  ln -sf ${my_dir}/scripts/* /usr/local/bin/
}

install_birdnet_analysis() {
  cat << EOF > $HOME/BirdNET-Pi/templates/birdnet_analysis.service
[Unit]
Description=BirdNET Analysis
[Service]
Restart=always
Type=simple
RestartSec=2
User=${USER}
ExecStart=$PYTHON_VIRTUAL_ENV /usr/local/bin/birdnet_analysis.py
[Install]
WantedBy=multi-user.target
EOF
  install_unit birdnet_analysis.service
  systemctl enable birdnet_analysis.service
}

create_necessary_dirs() {
  echo "Creating necessary directories"
  [ -d ${EXTRACTED} ] || sudo -u ${USER} mkdir -p ${EXTRACTED}
  [ -d ${EXTRACTED}/By_Date ] || sudo -u ${USER} mkdir -p ${EXTRACTED}/By_Date
  [ -d ${EXTRACTED}/Charts ] || sudo -u ${USER} mkdir -p ${EXTRACTED}/Charts
  [ -d ${PROCESSED} ] || sudo -u ${USER} mkdir -p ${PROCESSED}
  [ -d $RECS_DIR/StreamData ] || sudo -u ${USER} mkdir -p $RECS_DIR/StreamData
  # US-40: local spool of the central sound repository (empty = feature off)
  [ -z "${SOUND_REPO_PATH}" ] || [ -d "${SOUND_REPO_PATH}" ] || sudo -u ${USER} mkdir -p "${SOUND_REPO_PATH}"
  [ -L ${EXTRACTED}/spectrogram.png ] || sudo -u ${USER} ln -sf ${RECS_DIR}/StreamData/spectrogram.png ${EXTRACTED}/spectrogram.png

  # the station's own files (owner 2026-10-22): ~/BirdNET-Pi/config, the running species lists in config/species_lists
  sudo -u ${USER} mkdir -p $my_dir/config/species_lists/custom $my_dir/config/scripts $my_dir/config/notifications $my_dir/config/data $my_dir/config/settings_profiles $my_dir/config/custom $my_dir/state
  for f in exclude confirmed include whitelist; do [ -e $my_dir/config/species_lists/${f}_species_list.txt ] || sudo -u ${USER} touch $my_dir/config/species_lists/${f}_species_list.txt; done
  sudo -u ${USER} ln -fs $my_dir/homepage/* ${EXTRACTED}
  sudo -u ${USER} ln -fs $my_dir/model/labels.txt ${my_dir}/scripts
  sudo -u ${USER} ln -fs $my_dir/scripts ${EXTRACTED}
  sudo -u ${USER} ln -fs $my_dir/scripts/play.php ${EXTRACTED}
  sudo -u ${USER} ln -fs $my_dir/scripts/spectrogram.php ${EXTRACTED}
  sudo -u ${USER} ln -fs $my_dir/scripts/overview.php ${EXTRACTED}
  sudo -u ${USER} ln -fs $my_dir/scripts/stats.php ${EXTRACTED}
  sudo -u ${USER} ln -fs $my_dir/scripts/todays_detections.php ${EXTRACTED}
  sudo -u ${USER} ln -fs $my_dir/homepage/images/favicon.ico ${EXTRACTED}
  sudo -u ${USER} ln -fs ${HOME}/phpsysinfo ${EXTRACTED}
  sudo -u ${USER} ln -fs $my_dir/templates/phpsysinfo.ini ${HOME}/phpsysinfo/
  sudo -u ${USER} ln -fs $my_dir/templates/green_bootstrap.css ${HOME}/phpsysinfo/templates/
  sudo -u ${USER} ln -fs $my_dir/templates/index_bootstrap.html ${HOME}/phpsysinfo/templates/html
  sudo -u ${USER} ln -sf $my_dir/model/labels_nm/labels_en.txt $my_dir/model/labels_flickr.txt
  chmod -R g+rw $my_dir
  chmod -R g+rw ${RECS_DIR}
}

generate_BirdDB() {
  echo "Generating BirdDB.txt"
  local db_txt=$my_dir/config/data/BirdDB.txt
  if ! [ -f $db_txt ];then
    sudo -u ${USER} touch $db_txt
    echo "Date;Time;Sci_Name;Com_Name;Confidence;Lat;Lon;Cutoff;Week;Sens;Overlap" | sudo -u ${USER} tee -a $db_txt
  elif ! grep Date $db_txt;then
    sudo -u ${USER} sed -i '1 i\Date;Time;Sci_Name;Com_Name;Confidence;Lat;Lon;Cutoff;Week;Sens;Overlap' $db_txt
  fi
  chown $USER:$USER $db_txt && chmod g+rw $db_txt
}

set_login() {
  if ! [ -d /etc/lightdm ];then
    systemctl set-default multi-user.target
    ln -fs /lib/systemd/system/getty@.service /etc/systemd/system/getty.target.wants/getty@tty1.service
    if ! [ -d /etc/systemd/system/getty@tty1.service.d ];then
      mkdir /etc/systemd/system/getty@tty1.service.d
    fi
    cat > /etc/systemd/system/getty@tty1.service.d/autologin.conf << EOF
[Service]
ExecStart=
ExecStart=-/sbin/agetty --autologin $USER --noclear %I \$TERM
EOF
  fi
}

install_recording_service() {
  echo "Installing birdnet_recording.service"
  cat << EOF > $HOME/BirdNET-Pi/templates/birdnet_recording.service
[Unit]
Description=BirdNET Recording
[Service]
Restart=always
Type=simple
RestartSec=3
User=${USER}
ExecStart=/usr/local/bin/birdnet_recording.sh
[Install]
WantedBy=multi-user.target
EOF
  install_unit birdnet_recording.service
  systemctl enable birdnet_recording.service
}

install_custom_recording_service() {
  echo "Installing custom_recording.service"
  cat << EOF > $HOME/BirdNET-Pi/templates/custom_recording.service
[Unit]
Description=BirdNET Custom Recording
[Service]
Restart=always
Type=simple
RestartSec=3
User=${USER}
ExecStart=/usr/local/bin/custom_recording.sh
[Install]
WantedBy=multi-user.target
EOF
  install_unit custom_recording.service
}

install_Caddyfile() {
  # one generator for the installation and the updates (2026-10-23 test run: this copy had fallen behind - no
  # protection of /log, no security headers); it keeps the plaintext password out of the trace
  ${my_dir}/scripts/update_caddyfile.sh

  systemctl enable caddy
  # the web server reads the public files of the station (clips, charts, the page files) through the station user's
  # group; it runs no command and needs no other group (least privilege, 2026-10-23)
  usermod -aG $USER caddy
  chmod g+r+x $HOME
}

install_avahi_aliases() {
  # no avahi-alias@<host>.local unit any more (2026-10-25): avahi-daemon publishes the host name itself and the field
  # service the station's own name, both with the address in use; the alias kept the address of the start-up
  # symbolic link does not work here, so just copy
  cp -f $HOME/BirdNET-Pi/templates/http.service /etc/avahi/services/
  systemctl restart avahi-daemon.service
}

install_spectrogram_service() {
  cat << EOF > $HOME/BirdNET-Pi/templates/spectrogram_viewer.service
[Unit]
Description=BirdSuite Spectrogram Viewer
[Service]
Restart=always
RestartSec=10
Type=simple
User=${USER}
ExecStart=/usr/local/bin/spectrogram.sh
[Install]
WantedBy=multi-user.target
EOF
  install_unit spectrogram_viewer.service
  systemctl enable spectrogram_viewer.service
}

install_chart_viewer_service() {
  echo "Installing the chart_viewer.service"
  cat << EOF > $HOME/BirdNET-Pi/templates/chart_viewer.service
[Unit]
Description=BirdSuite Chart Viewer Service
[Service]
Restart=always
RestartSec=120
Type=simple
User=$USER
ExecStart=$PYTHON_VIRTUAL_ENV /usr/local/bin/daily_plot.py --daemon --sleep 2
[Install]
WantedBy=multi-user.target
EOF
  install_unit chart_viewer.service
  systemctl enable chart_viewer.service
}

install_gotty_logs() {
  sudo -u ${USER} ln -sf $my_dir/templates/gotty \
    ${HOME}/.gotty
  sudo -u ${USER} ln -sf $my_dir/templates/bashrc \
    ${HOME}/.bashrc
  cat << EOF > $HOME/BirdNET-Pi/templates/birdnet_log.service
[Unit]
Description=BirdNET Analysis Log
[Service]
Restart=on-failure
RestartSec=3
Type=simple
User=${USER}
Environment=TERM=xterm-256color
ExecStart=/usr/local/bin/gotty --address localhost -p 8080 --path log --title-format "BirdSuite Log" birdnet_log.sh
[Install]
WantedBy=multi-user.target
EOF
  install_unit birdnet_log.service
  systemctl enable birdnet_log.service
  cat << EOF > $HOME/BirdNET-Pi/templates/web_terminal.service
[Unit]
Description=BirdSuite Web Terminal
[Service]
Restart=on-failure
RestartSec=3
Type=simple
User=${USER}
Environment=TERM=xterm-256color
ExecStart=/usr/local/bin/gotty --address localhost -w -p 8888 --path terminal --title-format "BirdSuite Terminal" bash -c 'read -p "Login: " username && [[ "\$username" =~ ^[-_.a-z0-9]{1,30}$ ]] && su --pty -l \$username'
[Install]
WantedBy=multi-user.target
EOF
  install_unit web_terminal.service
  systemctl enable web_terminal.service
  install_web_files_service
}

configure_caddy_php() {
  echo "Configuring PHP for Caddy"
  # the pages run as the station user (owner 2026-10-23, least privilege): they see what that user sees and nothing more;
  # the socket stays Caddy's, so the web server can hand the requests over without any right on the station's files
  for pool in /etc/php/*/fpm/pool.d/www.conf; do
    sed -i -e "s/^user = .*/user = $USER/" -e "s/^group = .*/group = $USER/" \
      -e 's/^listen.owner = .*/listen.owner = caddy/' -e 's/^listen.group = .*/listen.group = caddy/' \
      -e 's/^;\?listen.mode = .*/listen.mode = 0660/' "$pool"
  done
  # the pages load several parts at once (lists, charts, review player): 5 PHP workers queue them (owner 2026-10-09)
  sed -i 's/^pm.max_children = .*/pm.max_children = 12/; s/^pm.start_servers = .*/pm.start_servers = 3/; s/^pm.max_spare_servers = .*/pm.max_spare_servers = 6/' /etc/php/*/fpm/pool.d/www.conf
  systemctl restart php\*-fpm.service
  # no sudo for the web server any more (owner 2026-10-23, least privilege): the pages run as the station user
  rm -f /etc/sudoers.d/010_caddy-nopasswd
}

# least privilege (owner 2026-10-23): the only root steps of the station are the two named helpers, installed
# root-owned (copies, so the station user cannot change them); the station user's sudo rule allows only these two
install_least_privilege() {
  install -d -o root -g root -m 0755 /usr/local/lib/birdsuite
  printf '%s\n' "$USER" > /usr/local/lib/birdsuite/station_user
  chmod 0644 /usr/local/lib/birdsuite/station_user
  install -o root -g root -m 0755 $HOME/BirdNET-Pi/scripts/birdsuite-svc $HOME/BirdNET-Pi/scripts/birdsuite-root /usr/local/sbin/
  local rule=/etc/sudoers.d/020_birdsuite-$USER
  printf '%s ALL=(root) NOPASSWD: /usr/local/sbin/birdsuite-svc, /usr/local/sbin/birdsuite-root\n' "$USER" > $rule.new
  if visudo -cf $rule.new > /dev/null; then
    chmod 0440 $rule.new && mv $rule.new $rule
  else
    rm -f $rule.new
    echo "the BirdSuite sudo rule did not pass visudo"
    exit 1
  fi
}

install_phpsysinfo() {
  # from phpsysinfo's own repository (owner 2026-10-23: a customer installs from public sources, never the station
  # owner's backup); a shallow clone, BIRDNET_PHPSYSINFO_REPO overrides it
  sudo -u ${USER} git clone --depth 1 ${BIRDNET_PHPSYSINFO_REPO:-https://github.com/phpsysinfo/phpsysinfo.git} \
    ${HOME}/phpsysinfo
}

config_icecast() {
  if [ -f /etc/icecast2/icecast.xml ];then
    cp /etc/icecast2/icecast.xml{,.prebirdnetpi}
  fi
  sed -i 's/>admin</>birdnet</g' /etc/icecast2/icecast.xml
  { set +x; } 2>/dev/null # the stream password must not reach the install log
  ICE_PWD_ESC=$(printf '%s' "${ICE_PWD}" | sed -e 's/[\/&\\]/\\&/g')
  passwords=("source-" "relay-" "admin-" "master-" "")
  for i in "${passwords[@]}";do
  sed -i "s/<${i}password>.*<\/${i}password>/<${i}password>${ICE_PWD_ESC}<\/${i}password>/g" /etc/icecast2/icecast.xml
  done
  set -x
  sed -i 's|<!-- <bind-address>.*|<bind-address>127.0.0.1</bind-address>|;s|<!-- <shoutcast-mount>.*|<shoutcast-mount>/stream</shoutcast-mount>|' /etc/icecast2/icecast.xml

  systemctl enable icecast2.service
}

install_livestream_service() {
  cat << EOF > $HOME/BirdNET-Pi/templates/livestream.service
[Unit]
Description=BirdSuite Live Stream
After=network-online.target
Requires=network-online.target
# keep restarting however often ffmpeg stops (a busy or replugged microphone), never give up
StartLimitIntervalSec=0
[Service]
Restart=always
Type=simple
RestartSec=3
# ffmpeg may not answer SIGTERM while it reads the microphone: a stop waited the default 90 s (a change of location
# stood still on "stopping the recording", owner 2026-10-22); SIGKILL after 5 s
TimeoutStopSec=5
User=${USER}
ExecStart=/usr/local/bin/livestream.sh
[Install]
WantedBy=multi-user.target
EOF
  install_unit livestream.service
  systemctl enable livestream.service
}

# the station's lines are removed before they are written, so a second installer run does not double them (2026-10-23
# run 3: every job ran twice, the recording watchdog included)
install_cleanup_cron() {
  sed -i -e '/^#birdnet$/d' -e '\|recording_watchdog.py|d' -e '\|/usr/local/bin/disk_check.sh|d' \
    -e '\|/usr/local/bin/disk_species_clean.sh|d' -e '\|/usr/local/bin/cleanup.sh|d' -e '\|birdsuite-root update -a|d' /etc/crontab
  sed "s/\$USER/$USER/g" $my_dir/templates/cleanup.cron >> /etc/crontab
}

install_automatic_update_cron() {
  sed -i -e '\|birdsuite-root update -a|d' /etc/crontab
  sed "s/\$USER/$USER/g" $my_dir/templates/automatic_update.cron >> /etc/crontab
}

install_mic_hotplug() {
  # USB microphone hot-plug (US-51b): udev restarts a state-driven oneshot on every USB sound
  # card add/remove; mic_hotplug.sh stops or (re)configures and starts the station. The rule
  # sorts after 78-sound-card.rules, which sets ID_BUS (needed on "remove", sysfs is gone then).
  cat << EOF > $HOME/BirdNET-Pi/templates/birdnet_mic_hotplug.service
[Unit]
Description=BirdSuite USB microphone hot-plug (state-driven)
After=sound.target
[Service]
Type=oneshot
# the unplug path waits until the analysis has emptied the queue
TimeoutStartSec=infinity
User=$USER
SupplementaryGroups=audio
ExecStart=/usr/local/bin/mic_hotplug.sh
EOF
  install_unit birdnet_mic_hotplug.service
  install_mic_udev_rule
  systemctl daemon-reload
}

install_location_check() {
  # at every boot: compare the network position with the coordinates; the Latest page asks when the station moved
  # farther than the location's radius, at least LOCATION_IP_ERROR_KM (scripts/location_check.py, owner 2026-10-21)
  cat << EOF > $HOME/BirdNET-Pi/templates/birdnet_location_check.service
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
  install_unit birdnet_location_check.service
  systemctl daemon-reload
  systemctl enable birdnet_location_check.service
}

install_field_services() {
  # field access (US-120, owner 2026-10-20): the station WiFi on demand and at boot without a network, the fixed mDNS
  # name, the clock, the offline notification queue (birdsuite_field) and the Bluetooth service a phone uses to find
  # and set up the station (birdsuite_ble); both as the station user (owner 2026-10-23, least privilege): NetworkManager
  # through a polkit rule for that user, Bluetooth through its group, the clock and the LED through birdsuite-root
  cat << EOF > $HOME/BirdNET-Pi/templates/birdsuite_field.service
[Unit]
Description=BirdSuite field access (station WiFi on demand, mDNS name, clock, offline queue)
After=NetworkManager.service avahi-daemon.service
[Service]
User=$USER
SupplementaryGroups=netdev bluetooth
# /run/birdsuite (the station WiFi state, read by the pages) made for the station user by systemd
RuntimeDirectory=birdsuite
RuntimeDirectoryMode=0755
RuntimeDirectoryPreserve=yes
Restart=always
RestartSec=10
ExecStart=/usr/bin/python3 $HOME/BirdNET-Pi/scripts/field_daemon.py
[Install]
WantedBy=multi-user.target
EOF
  cat << EOF > $HOME/BirdNET-Pi/templates/birdsuite_ble.service
[Unit]
Description=BirdSuite Bluetooth field service (find and set up the station from a phone)
After=bluetooth.service birdsuite_field.service
Requires=bluetooth.service
[Service]
User=$USER
SupplementaryGroups=bluetooth netdev
# the radio unblocked first, the only root step of this service (the unit is a root-owned copy)
ExecStartPre=+/usr/sbin/rfkill unblock bluetooth
Restart=always
RestartSec=10
ExecStart=/usr/bin/python3 $HOME/BirdNET-Pi/scripts/field_ble.py
[Install]
WantedBy=multi-user.target
EOF
  # NetworkManager for the station user only (WiFi networks, the station's own WiFi, its password)
  mkdir -p /etc/polkit-1/rules.d
  cat << EOF > /etc/polkit-1/rules.d/50-birdsuite-networkmanager.rules
// BirdSuite (least privilege): the station user manages the network without root
polkit.addRule(function(action, subject) {
  if (action.id.indexOf("org.freedesktop.NetworkManager.") == 0 && subject.user == "$USER") {
    return polkit.Result.YES;
  }
});
EOF
  chmod 0644 /etc/polkit-1/rules.d/50-birdsuite-networkmanager.rules
  install_unit birdsuite_field.service
  install_unit birdsuite_ble.service
  systemctl daemon-reload
  systemctl enable birdsuite_field.service birdsuite_ble.service
}

chown_things() {
  chown -R $USER:$USER $HOME/Bird*
}

increase_caddy_timeout() {
  mkdir /etc/systemd/system/caddy.service.d
  cat << EOF > /etc/systemd/system/caddy.service.d/override.conf
[Service]
TimeoutSec=300s
EOF
  systemctl daemon-reload
}

install_services() {
  set_hostname
  update_etc_hosts
  set_login
  install_tmp_mount

  install_depends
  install_scripts
  install_Caddyfile
  install_avahi_aliases
  install_birdnet_analysis
  install_recording_service
  install_custom_recording_service # But does not enable
  install_spectrogram_service
  install_chart_viewer_service
  install_gotty_logs
  install_phpsysinfo
  install_livestream_service
  install_birdnet_mount
  install_sound_repo_upload_service
  install_mic_hotplug
  install_location_check
  install_field_services
  install_cleanup_cron
  install_automatic_update_cron
  increase_caddy_timeout

  create_necessary_dirs
  generate_BirdDB
  configure_caddy_php
  install_least_privilege
  # number format of the station = the BirdSuite language chosen (owner 2026-10-23; LC_NUMERIC only)
  bs_lang=$(sed -n 's/^UI_LANG=//p' "$my_dir/config/BirdSuite.conf" | tr -d '"'); [ -z "$bs_lang" ] && bs_lang=$(sed -n 's/^DATABASE_LANG=//p' "$my_dir/config/BirdSuite.conf" | tr -d '"')
  [ "$bs_lang" = pt ] && bs_lang=pt_BR
  /usr/local/sbin/birdsuite-root numeric "${bs_lang:-en}" 2>/dev/null || /usr/local/sbin/birdsuite-root numeric en || true
  install_unit_hardening; systemctl daemon-reload  # systemd sandboxing of the services (install_helpers.sh)
  # inbound firewall, automatic security updates, no root ssh login (2026-10-23; fresh installs only)
  ${my_dir}/scripts/install_hardening.sh || echo "System hardening not applied (scripts/install_hardening.sh)" >&2
  config_icecast
  USER=$USER HOME=$HOME ${my_dir}/scripts/createdb.sh
}

if [ -f ${config_file} ];then
  { set +x; } 2>/dev/null # not traced: BirdSuite.conf carries the web / stream passwords
  source ${config_file}
  set -x
  source install_helpers.sh
  install_services
  chown_things
else
  echo "Unable to find a configuration file. Please make sure that $config_file exists."
  # a failure, not a silent success (2026-10-23: the installation went on and reported success with nothing installed)
  exit 1
fi
