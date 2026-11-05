#!/usr/bin/env bash
# Uninstall script to remove everything
#set -x # Uncomment to debug
trap 'rm -f ${TMPFILE}' EXIT
source "$(dirname "$(readlink -f "${BASH_SOURCE[0]}")")/paths.sh"
my_dir=$BS_SCRIPTS
source "$my_dir/install_helpers.sh"
SCRIPTS=($(ls -1 ${my_dir}) ${HOME}/.gotty)
set -x
# every unit the station has (install_helpers.sh: the templates/units of this version and any older one of the station's)
services=($(bs_unit_names) $(bs_owned_units) birdsuite-firewall.service "$BS_SVC_CONSOLE_SSH.socket" "$BS_SVC_CONSOLE_SSH@.service")

remove_services() {
  for i in "${services[@]}"; do
    if [ -L /etc/systemd/system/multi-user.target.wants/"${i}" ];then
      sudo systemctl disable --now "${i}"
    fi
    sudo systemctl disable --now "${i}" 2> /dev/null
    sudo rm -f "$BS_UNIT_DIR/$i"
    if [ -f /etc/systemd/system/"${i}" ];then
      sudo rm /etc/systemd/system/"${i}"
    fi
    if [ -d /etc/systemd/system/"${i}" ];then
      sudo rm -drf /etc/systemd/system/"${i}"
    fi
    # the sandboxing drop-in (install_helpers.sh hardening_dropin)
    sudo rm -f /etc/systemd/system/"${i}".d/10-birdsuite-hardening.conf
    sudo rmdir /etc/systemd/system/"${i}".d 2> /dev/null
  done
  set +x
  remove_icecast
  remove_crons
  remove_avahi_services
}

remove_avahi_services() {
  sudo rm -v "/etc/avahi/services/http.service"
}

# the station's lines of /etc/crontab (install_helpers.sh: those run as the station user, with their comment)
remove_crons() {
  awk -v u="$USER" -v mode=other -f <(bs_cron_awk) /etc/crontab > /tmp/crontab.birdsuite && sudo sh -c 'cat /tmp/crontab.birdsuite > /etc/crontab'
  rm -f /tmp/crontab.birdsuite
}

remove_icecast() {
  if [ -f /etc/init.d/icecast2 ];then
    sudo /etc/init.d/icecast2 stop
    sudo systemctl disable --now icecast2
  fi
}

remove_scripts() {
  for i in "${SCRIPTS[@]}";do
    if [ -L "$BS_BIN/${i}" ];then
      sudo rm -v "$BS_BIN/${i}"
    fi
  done
}

# what this edition installs outside the repository: the microphone hot-plug (unit + udev rule), the
# shared microphone device, the sound-repository upload timer and the installer's sudoers rules
remove_edition_files() {
  sudo systemctl daemon-reload
  # the hardening of a fresh install: firewall rules, security-update and ssh drop-ins (the packages stay)
  sudo rm -f /etc/birdsuite-firewall.nft /etc/apt/apt.conf.d/52birdsuite-unattended /etc/apt/apt.conf.d/53birdsuite-periodic /etc/ssh/sshd_config.d/10-birdsuite.conf
  # the udev rules of templates/udev (first line: # BirdSuite)
  for f in "$BS_UDEV_DIR"/*.rules; do
    [ -f "$f" ] && head -1 "$f" | grep -q '^# BirdSuite' && sudo rm -f "$f"
  done
  sudo udevadm control --reload-rules
  sudo rm -f /etc/alsa/conf.d/60-birdnet-mic.conf
  # only the file the installer wrote (one line), never a client.conf of the user's own
  if [ "$(cat ${HOME}/.config/pulse/client.conf 2> /dev/null)" = "autospawn = no" ];then
    rm -f ${HOME}/.config/pulse/client.conf
  fi
  # the least-privilege pieces (2026-10-23): the two root helpers, their rule, the raw recording line of the crontab;
  # the first user's passwordless rule the installer switched off comes back
  sudo rm -f "$BS_SVC_HELPER" "$BS_ROOT_HELPER" "$BS_SUDOERS-${USER}"
  sudo rm -rf "$BS_LIB"
  sudo rm -f /etc/polkit-1/rules.d/50-birdsuite-networkmanager.rules
  crontab -l 2> /dev/null | grep -v 'birdsuite-raw-recording' | crontab - 2> /dev/null
  if [ -f /etc/sudoers.d/010_${USER}-nopasswd.birdsuite-disabled ]; then
    sudo mv /etc/sudoers.d/010_${USER}-nopasswd.birdsuite-disabled /etc/sudoers.d/010_${USER}-nopasswd
  fi
}

remove_services
remove_scripts
remove_edition_files
if [ -f "$BS_CONF" ];then sudo rm -f "$BS_CONF";fi
# last: the installer's passwordless-sudo rule for this user (nothing after this needs sudo)
sudo rm -f /etc/sudoers.d/zz-birdsuite-${USER}-nopasswd
echo "Uninstall finished. Remove this directory with 'rm -drfv' to finish."
