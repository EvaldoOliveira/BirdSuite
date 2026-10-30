#!/usr/bin/env bash

if [ "$EUID" == 0 ]
  then echo "Please run as a non-root user."
  exit
fi

# an ssh client sends its own language (e.g. pt_BR.UTF-8) that a Lite card has not generated: every perl and apt
# call then warns "Setting locale failed". For this installation only, fall back to C.UTF-8 (always present)
if locale 2>&1 | grep -q 'Cannot set'; then
  export LC_ALL=C.UTF-8 LANG=C.UTF-8
  unset LANGUAGE
fi

if [ "$(uname -m)" != "aarch64" ] && [ "$(uname -m)" != "x86_64" ];then
  echo "BirdSuite requires a 64-bit OS.
It looks like your operating system is using $(uname -m),
but would need to be aarch64."
  exit 1
fi

PY_VERSION=$(python3 -c "import sys; print(f'{sys.version_info[0]}{sys.version_info[1]}')")
if [ "${PY_VERSION}" == "39" ] ;then
  echo "### BirdSuite requires a newer OS. Bullseye is deprecated, please use Bookworm. ###"
  [ -z "${FORCE_BULLSEYE}" ] && exit
fi

# we require passwordless sudo: the installer and the running station call sudo without a
# terminal. Recent Raspberry Pi OS images (Trixie) ask the first user's password, so set it up
# once here (US-51a): a sudoers.d rule named zz-* so it is the last one to match, checked by visudo
sudo -K
if ! sudo -n true 2>/dev/null; then
  rule_file=/etc/sudoers.d/zz-birdnet-${USER}-nopasswd
  if (: < /dev/tty) 2>/dev/null; then
    echo "BirdSuite needs passwordless sudo for '${USER}' (the station runs system commands unattended)."
    echo "Enter the password of '${USER}' once to set it up (${rule_file}):"
    if sudo -v < /dev/tty; then
      echo "${USER} ALL=(ALL) NOPASSWD: ALL" | sudo tee "${rule_file}.new" > /dev/null
      if sudo visudo -cf "${rule_file}.new" > /dev/null; then
        sudo chmod u=r,g=r,o= "${rule_file}.new" && sudo mv "${rule_file}.new" "${rule_file}"
      else
        sudo rm -f "${rule_file}.new"
      fi
    fi
    sudo -K
  fi
  if ! sudo -n true 2>/dev/null; then
    echo "Passwordless sudo is not working. Aborting"
    echo "Set it up with: echo \"${USER} ALL=(ALL) NOPASSWD: ALL\" | sudo tee ${rule_file}"
    exit 1
  fi
fi

# Simple new installer
HOME=$HOME
USER=$USER

export HOME=$HOME
export USER=$USER

PACKAGES_MISSING=
for cmd in git jq ; do
  if ! which $cmd &> /dev/null;then
      PACKAGES_MISSING="${PACKAGES_MISSING} $cmd"
  fi
done
if [[ ! -z $PACKAGES_MISSING ]] ; then
  sudo apt update
  sudo apt -y install $PACKAGES_MISSING
fi

# A new station installs main of the public repository (owner 2026-10-27: during the beta it holds the last closed
# release and the commits of the release in development; the development history is private, BirdSuiteDev) and its
# Update follows main. BIRDNET_BRANCH picks another branch or tag.
branch=${BIRDNET_BRANCH:-main}
export BIRDNET_UPDATE_BRANCH=${BIRDNET_UPDATE_BRANCH:-main}  # install_config.sh writes it as UPDATE_BRANCH
# BirdSuite's repository on GitHub (owner 2026-10-24: public, cloned over https with no key). When this script runs
# from a clone, the same address it was cloned from; BIRDNET_REPO
# overrides it. For an ssh address GitHub's host key is accepted on first use so the clone does not stop at a question.
here_origin=$(git -C "$(dirname "$(readlink -f "$0")")" remote get-url origin 2>/dev/null || true)
repo=${BIRDNET_REPO:-${here_origin:-https://github.com/EvaldoOliveira/BirdSuite.git}}
case "$repo" in
  git@github.com:*|ssh://git@github.com/*)
    mkdir -p ~/.ssh && chmod u=rwx,go= ~/.ssh
    ssh-keygen -F github.com > /dev/null 2>&1 || ssh-keyscan -t ed25519 github.com >> ~/.ssh/known_hosts 2>/dev/null ;;
esac
git clone -b $branch --depth=1 $repo ${HOME}/BirdNET-Pi &&

$HOME/BirdNET-Pi/scripts/install_birdnet.sh
if [ ${PIPESTATUS[0]} -eq 0 ];then
  # least privilege (owner 2026-10-23): the station keeps only its named sudo rule (birdsuite-svc, birdsuite-root);
  # the full rule of the installation goes, and the system's own passwordless rule of the first user is switched off
  # (renamed: sudo skips file names with a dot; the administrator still uses sudo with the password)
  # one sudo call for the last root steps: once the rule is gone, a later sudo would ask for the password (2026-10-23:
  # the restart after the installation did not happen)
  echo "From now on sudo asks for the password of '${USER}'; the station itself only runs its two named helpers as root."
  echo "Installation completed successfully"
  # how to open the station (owner 2026-10-29, US-126: http by default; https only for a moment to read the GPS)
  station_host="$(hostname).local"
  station_ip="$(hostname -I 2>/dev/null | awk '{print $1}')"
  cat << EOF

After the restart, open the station in a web browser on the same network:
  http://${station_host}   or   http://${station_ip}

The GPS button of the map (Settings > Locations) switches https on for a moment, because
browsers give the device's position only to https pages. The browser then warns once per
device that the station's own certificate is not trusted ("Your connection is not private" /
"Warning: Potential Security Risk"): click "Advanced" and "Proceed" / "Accept the risk and
continue". https goes off again as soon as the position is read. To use https all the time,
set HTTPS_ALWAYS to 1 in Tools > Defaults.
EOF
  sudo sh -c "rm -f /etc/sudoers.d/zz-birdnet-${USER}-nopasswd; \
    [ -f /etc/sudoers.d/010_${USER}-nopasswd ] && mv /etc/sudoers.d/010_${USER}-nopasswd /etc/sudoers.d/010_${USER}-nopasswd.birdsuite-disabled; \
    systemctl reboot"
else
  echo "The installation exited unsuccessfully."
  exit 1
fi
