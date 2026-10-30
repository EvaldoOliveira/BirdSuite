#!/usr/bin/env bash
# Uninstall script to remove everything
#set -x # Uncomment to debug
trap 'rm -f ${TMPFILE}' EXIT
my_dir=$HOME/BirdNET-Pi/scripts
source "$(dirname "$(readlink -f "${BASH_SOURCE[0]}")")/paths.sh"
SCRIPTS=($(ls -1 ${my_dir}) ${HOME}/.gotty)
set -x
TMP_MOUNT=$(systemd-escape -p --suffix=mount "$RECS_DIR/StreamData")
services=($(awk '/service/ && /systemctl/ && !/php/ {print $3}' ${my_dir}/install_services.sh | sort) custom_recording.service avahi-alias@.service $TMP_MOUNT)

remove_services() {
  for i in "${services[@]}"; do
    if [ -L /etc/systemd/system/multi-user.target.wants/"${i}" ];then
      sudo systemctl disable --now "${i}"
    fi
    if [ -L /lib/systemd/system/"${i}" ];then
      sudo rm -f /lib/systemd/system/$i
    fi
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

remove_crons() {
  sudo sed -i '/birdnet/,+1d' /etc/crontab
}

remove_icecast() {
  if [ -f /etc/init.d/icecast2 ];then
    sudo /etc/init.d/icecast2 stop
    sudo systemctl disable --now icecast2
  fi
}

remove_scripts() {
  for i in "${SCRIPTS[@]}";do
    if [ -L "/usr/local/bin/${i}" ];then
      sudo rm -v "/usr/local/bin/${i}"
    fi
  done
}

# what this edition installs outside the repository: the microphone hot-plug (unit + udev rule), the
# shared microphone device, the sound-repository upload timer and the installer's sudoers rules
remove_edition_files() {
  for unit in birdnet_mic_hotplug.service sound_repo_upload.timer sound_repo_upload.service birdsuite-firewall.service; do
    sudo systemctl disable --now "$unit" 2> /dev/null
    sudo rm -f "/usr/lib/systemd/system/$unit" "/etc/systemd/system/$unit"
  done
  sudo systemctl daemon-reload
  # the hardening of a fresh install: firewall rules, security-update and ssh drop-ins (the packages stay)
  sudo rm -f /etc/birdsuite-firewall.nft /etc/apt/apt.conf.d/52birdsuite-unattended /etc/apt/apt.conf.d/53birdsuite-periodic /etc/ssh/sshd_config.d/10-birdsuite.conf
  if [ -f /etc/udev/rules.d/79-birdnet-mic.rules ];then
    sudo rm -f /etc/udev/rules.d/79-birdnet-mic.rules
    sudo udevadm control --reload-rules
  fi
  sudo rm -f /etc/alsa/conf.d/60-birdnet-mic.conf
  # only the file the installer wrote (one line), never a client.conf of the user's own
  if [ "$(cat ${HOME}/.config/pulse/client.conf 2> /dev/null)" = "autospawn = no" ];then
    rm -f ${HOME}/.config/pulse/client.conf
  fi
  sudo rm -f /etc/sudoers.d/010_caddy-nopasswd
  sudo rm -f /etc/cron.d/birdnet_raw_recording
  # the least-privilege pieces (2026-10-23): the two root helpers, their rule, the raw recording line of the crontab;
  # the first user's passwordless rule the installer switched off comes back
  sudo rm -f /usr/local/sbin/birdsuite-svc /usr/local/sbin/birdsuite-root /etc/sudoers.d/020_birdsuite-${USER}
  sudo rm -rf /usr/local/lib/birdsuite
  sudo rm -f /etc/polkit-1/rules.d/50-birdsuite-networkmanager.rules
  crontab -l 2> /dev/null | grep -v 'birdsuite-raw-recording' | crontab - 2> /dev/null
  if [ -f /etc/sudoers.d/010_${USER}-nopasswd.birdsuite-disabled ]; then
    sudo mv /etc/sudoers.d/010_${USER}-nopasswd.birdsuite-disabled /etc/sudoers.d/010_${USER}-nopasswd
  fi
}

remove_services
remove_scripts
remove_edition_files
if [ -d /etc/birdnet ];then sudo rm -drf /etc/birdnet;fi  # left by installs before 2026-10-22
if [ -f ${HOME}/BirdNET-Pi/config/BirdSuite.conf ];then sudo rm -f ${HOME}/BirdNET-Pi/config/BirdSuite.conf;fi
# last: the installer's passwordless-sudo rule for this user (nothing after this needs sudo)
sudo rm -f /etc/sudoers.d/zz-birdnet-${USER}-nopasswd
echo "Uninstall finished. Remove this directory with 'rm -drfv' to finish."
