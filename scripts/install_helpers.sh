#!/usr/bin/env bash
# this should only contain functions and assignments, ie source install.sh should not have side effects.

# a systemd unit made in templates/ installed as a root-owned copy (owner 2026-10-23, least privilege): a link to the
# station user's folder would let that user change what systemd runs, and as whom
install_unit () {
  install -o root -g root -m 0644 "$HOME/BirdNET-Pi/templates/$1" "/usr/lib/systemd/system/$1"
}

get_tf_whl () {
  # the wheels come from the birdsuite-assets repository (scripts/fetch_asset.sh, git/ssh); their
  # SHA256 sums ship with this edition (scripts/tflite_wheels.sha256)

  ARCH=$(uname -m)
  PY_VERSION=$(python3 -c "import sys; print(f'{sys.version_info[0]}{sys.version_info[1]}')")
  case "${ARCH}-${PY_VERSION}" in
    aarch64-39)
      WHL=tflite_runtime-2.11.0-cp39-none-linux_aarch64.whl
      ;;
    aarch64-311)
      WHL=tflite_runtime-2.17.1-cp311-cp311-linux_aarch64.whl
      ;;
    aarch64-312)
      WHL=tflite_runtime-2.17.1-cp312-cp312-linux_aarch64.whl
      ;;
    aarch64-313)
      WHL=tflite_runtime-2.17.1-cp313-cp313-linux_aarch64.whl
      ;;
    x86_64-39)
      WHL=tflite_runtime-2.11.0-cp39-cp39-linux_x86_64.whl
      ;;
    x86_64-311)
      WHL=tflite_runtime-2.17.1-cp311-cp311-linux_x86_64.whl
      ;;
    x86_64-312)
      WHL=tflite_runtime-2.17.1-cp312-cp312-linux_x86_64.whl
      ;;
    x86_64-313)
      WHL=tflite_runtime-2.17.1-cp313-cp313-linux_x86_64.whl
      ;;
    *)
      echo "No tflite version found for ${ARCH}-${PY_VERSION}"
      WHL=''
      ;;
  esac
  if [ -n "$WHL" ]; then
    if ! $HOME/BirdNET-Pi/scripts/fetch_asset.sh wheels/$WHL $HOME/BirdNET-Pi; then
      echo "Could not download $WHL"
      return 1
    fi
    SUMS=$(grep " ${WHL}\$" $HOME/BirdNET-Pi/scripts/tflite_wheels.sha256 2>/dev/null || true)
    if [ -n "$SUMS" ] && ! (cd $HOME/BirdNET-Pi && echo "$SUMS" | sha256sum -c --quiet -); then
      echo "Checksum mismatch for $WHL"
      return 1
    fi
    sed "s/tensorflow.*/$WHL/" $HOME/BirdNET-Pi/requirements.txt > requirements_custom.txt
  else
    # no prebuilt tflite_runtime for this platform: fall back to the full tensorflow package
    cp $HOME/BirdNET-Pi/requirements.txt requirements_custom.txt
  fi
}

install_birdnet_mount() {
  TMP_MOUNT=$(systemd-escape -p --suffix=mount "$RECS_DIR/StreamData")
  cat << EOF > $HOME/BirdNET-Pi/templates/$TMP_MOUNT
[Unit]
Description=Birdnet tmpfs for transient files
ConditionPathExists=$RECS_DIR/StreamData

[Mount]
What=tmpfs
Where=$RECS_DIR/StreamData
Type=tmpfs
Options=mode=1777,nosuid,nodev

[Install]
WantedBy=multi-user.target
EOF
  install_unit $TMP_MOUNT
}

# USB microphone hot-plug rule (US-51b). The remove event marks the removal at once (state/.mic_removed, owner
# 2026-10-24): a microphone plugged back within seconds restarted the hot-plug unit before it had written the mark,
# and the station never asked where it is (US-58)
install_mic_udev_rule() {
  cat << EOF > /etc/udev/rules.d/79-birdnet-mic.rules
# BirdSuite USB microphone hot-plug (install_helpers.sh)
ACTION=="add",    SUBSYSTEM=="sound", KERNEL=="card[0-9]*", SUBSYSTEMS=="usb", RUN+="/usr/bin/systemctl --no-block restart birdnet_mic_hotplug.service"
ACTION=="remove", SUBSYSTEM=="sound", KERNEL=="card[0-9]*", ENV{ID_BUS}=="usb", RUN+="/usr/bin/touch $HOME/BirdNET-Pi/state/.mic_removed", RUN+="/usr/bin/systemctl --no-block restart birdnet_mic_hotplug.service"
EOF
  udevadm control --reload-rules
}

# Tools > Browse files (owner 2026-10-24): Midnight Commander in the browser (gotty on localhost:8889, /files behind
# Caddy), after a login with the user's password as Console Login: a real file manager instead of a page of our own
install_web_files_service() {
  cat << EOF > $HOME/BirdNET-Pi/templates/web_files.service
[Unit]
Description=BirdSuite Browse files (Midnight Commander)
[Service]
Restart=on-failure
RestartSec=3
Type=simple
User=${USER}
Environment=TERM=xterm-256color
ExecStart=/usr/local/bin/gotty --address localhost -w -p 8889 --path files --title-format "BirdSuite Files" bash -c 'read -p "Login: " username && [[ "\$username" =~ ^[-_.a-z0-9]{1,30}$ ]] && su --pty -l \$username -c mc'
[Install]
WantedBy=multi-user.target
EOF
  install_unit web_files.service
  systemctl enable web_files.service
}

# US-40: station-side upload of the sound-repo spool to the central repository.
# The timer ticks every minute; the interval itself (SOUND_REPO_UPLOAD_MINUTES)
# lives in BirdSuite.conf and is applied by the script, so a settings change
# needs no unit reload.
install_sound_repo_upload_service() {
  cat << EOF > $HOME/BirdNET-Pi/templates/sound_repo_upload.service
[Unit]
Description=BirdNET sound repository upload (US-40)
After=network-online.target

[Service]
Type=oneshot
User=${USER}
Environment=HOME=${HOME}
ExecStart=$HOME/BirdNET-Pi/scripts/sound_repo_upload.sh
EOF
  cat << EOF > $HOME/BirdNET-Pi/templates/sound_repo_upload.timer
[Unit]
Description=BirdNET sound repository upload timer (US-40)

[Timer]
OnBootSec=2min
OnUnitActiveSec=1min
AccuracySec=10s
Unit=sound_repo_upload.service

[Install]
WantedBy=timers.target
EOF
  install_unit sound_repo_upload.service
  install_unit sound_repo_upload.timer
  systemctl enable sound_repo_upload.timer
}

install_tmp_mount() {
  STATE=$(systemctl is-enabled tmp.mount 2>&1 | grep -E '(enabled|disabled|static)')
  ! [ -f /usr/share/systemd/tmp.mount ] && echo "Warning: no /usr/share/systemd/tmp.mount found"
  if [ -z $STATE ]; then
    cp -f /usr/share/systemd/tmp.mount /etc/systemd/system/tmp.mount
    systemctl daemon-reload
    systemctl enable tmp.mount
  else
    echo "tmp.mount is $STATE, skipping"
  fi
}

# systemd sandboxing of the station's services (2026-10-23 run 3, HRD-01): one root-owned drop-in per unit, written by
# the installer and refreshed by every update. Conservative on purpose:
# - the services that never use sudo get the full set (no new privileges, read-only system, private /tmp, kernel and
#   clock protected, no set-uid, no namespaces); without a sound device when they do not record or stream
# - the ones that start services or set the clock through sudo (birdsuite-svc / birdsuite-root: microphone hot-plug,
#   field network, Bluetooth set-up) only the protections a root helper does not need
# - the web terminal is a login shell for the administrator (su, sudo): left as it is
hardening_dropin () {
  local unit=$1 level=$2 dir="/etc/systemd/system/$1.d"
  local common="ProtectKernelModules=yes
ProtectKernelLogs=yes
LockPersonality=yes
SystemCallArchitectures=native"
  local body
  case "$level" in
    full|full-devices)
      body="NoNewPrivileges=yes
RestrictSUIDSGID=yes
PrivateTmp=yes
ProtectSystem=full
ProtectKernelTunables=yes
ProtectControlGroups=yes
ProtectClock=yes
ProtectHostname=yes
RestrictNamespaces=yes
RestrictAddressFamilies=AF_UNIX AF_NETLINK AF_INET AF_INET6
$common"
      [ "$level" = full ] && body="$body
PrivateDevices=yes
RestrictRealtime=yes" ;;
    sudo)
      body="ProtectControlGroups=yes
RestrictRealtime=yes
$common" ;;
    *) return 1 ;;
  esac
  mkdir -p "$dir"
  printf '# written by BirdSuite (install_helpers.sh hardening_dropin), refreshed by every update\n[Service]\n%s\n' "$body" > "$dir/10-birdsuite-hardening.conf.new"
  chown root:root "$dir/10-birdsuite-hardening.conf.new"; chmod 0644 "$dir/10-birdsuite-hardening.conf.new"
  if cmp -s "$dir/10-birdsuite-hardening.conf.new" "$dir/10-birdsuite-hardening.conf"; then
    rm -f "$dir/10-birdsuite-hardening.conf.new"; return 1
  fi
  mv -f "$dir/10-birdsuite-hardening.conf.new" "$dir/10-birdsuite-hardening.conf"
}

# returns 0 when a drop-in changed (the caller reloads systemd and restarts what runs)
install_unit_hardening () {
  local changed=1 u
  for u in birdnet_analysis chart_viewer spectrogram_viewer birdnet_log birdnet_location_check sound_repo_upload; do
    hardening_dropin "$u.service" full && changed=0
  done
  for u in birdnet_recording custom_recording livestream; do
    hardening_dropin "$u.service" full-devices && changed=0
  done
  for u in birdnet_mic_hotplug birdsuite_field birdsuite_ble; do
    hardening_dropin "$u.service" sudo && changed=0
  done
  return $changed
}
