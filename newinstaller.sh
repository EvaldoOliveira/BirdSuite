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

if ! python3 -c "import sys; sys.exit(sys.version_info < (3, 11))"; then
  echo "### BirdSuite requires a newer OS: use Raspberry Pi OS Trixie (64-bit). ###"
  exit 1
fi

# we require passwordless sudo: the installer and the running station call sudo without a
# terminal. Recent Raspberry Pi OS images (Trixie) ask the first user's password, so set it up
# once here (US-51a): a sudoers.d rule named zz-* so it is the last one to match, checked by visudo
sudo -K
if ! sudo -n true 2>/dev/null; then
  rule_file=/etc/sudoers.d/zz-birdsuite-${USER}-nopasswd
  if (: < /dev/tty) 2>/dev/null; then
    echo "BirdSuite needs passwordless sudo for '${USER}' (the station runs system commands unattended)."
    echo "Enter the password of '${USER}' once to set it up (${rule_file}):"
    if sudo -v < /dev/tty; then
      echo "${USER} ALL=(ALL) NOPASSWD: ALL" | sudo tee "${rule_file}.new" > /dev/null
      if sudo visudo -cf "${rule_file}.new" > /dev/null; then
        sudo chmod u=r,g=r,o= "${rule_file}.new" && sudo mv "${rule_file}.new" "${rule_file}" && rule_made=1
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
# a stopped installation takes the rule away again (2026-11-03: a failed run left sudo without a password for every
# command); the successful one removes it at its end, before the restart
if [ -n "${rule_made:-}" ]; then
  trap 'rc_exit=$?; [ "$rc_exit" -ne 0 ] && sudo -n rm -f "$rule_file"; exit "$rc_exit"' EXIT
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
# cloned beside its place first: the program folder's name comes from the program itself (scripts/core.env BS_PROG_DIR)
clone=$(mktemp -d "${HOME}/.birdsuite-clone.XXXXXX")
git clone -b $branch --depth=1 $repo "$clone" || { echo "The clone of $repo failed."; rm -rf "$clone"; exit 1; }
prog_dir=$(sed -n 's/^BS_PROG_DIR=//p' "$clone/scripts/core.env")
if [ -z "$prog_dir" ] || [ -e "${HOME}/${prog_dir}" ]; then
  echo "${HOME}/${prog_dir} already exists (or the program names no folder): installation stopped."
  rm -rf "$clone"
  exit 1
fi
# BirdNET-Pi on this card (US-01, scripts/migration.py): its program folder with its database and settings. With room
# for a copy it is kept whole (*.old) and its history copied in; with too little room it is moved in after a question
# (no terminal or BIRDNET_UNATTENDED=1 = no answer = refused); with too little room even for that nothing changes
migration=
python3 "$clone/scripts/migration.py" plan
case $? in
  0) ;;
  10)
    migration=copy
    echo "There is room for a copy: BirdNET-Pi is stopped and kept as it is (its folders renamed *.old), BirdSuite is"
    echo "installed and imports its detections and clips as a copy after the first setup. For 30 days System > Status"
    echo "offers to remove the leftovers or to go back to BirdNET-Pi." ;;
  11)
    cat << 'EOF'
There is not enough room to keep BirdNET-Pi and copy its history. BirdSuite can MOVE the history instead:
  - the BirdNET-Pi settings (birdnet.conf, species lists, notification files) are kept in config/BIRDNET.OLD.gz;
  - its database and clips are moved into BirdSuite (not copied) and its program folder is removed;
  - going back to BirdNET-Pi then means a new BirdNET-Pi installation: the detections can be exported back to it
    with Tools > Backup/Import > Export to BirdNET, and the settings are in config/BIRDNET.OLD.gz.
Make a full copy of the card first if you want an easy way back.
EOF
    answer=
    if [ "${BIRDNET_UNATTENDED:-0}" != 1 ] && (: < /dev/tty) 2>/dev/null; then
      read -r -p "Move BirdNET-Pi into BirdSuite? [y/N] " answer < /dev/tty
    else
      echo "No terminal to ask (or BIRDNET_UNATTENDED=1): the move is refused."
    fi
    case "$answer" in
      y|Y|yes|YES) migration=move ;;
      *) echo "Nothing changed: the installation stopped."; rm -rf "$clone"; exit 1 ;;
    esac ;;
  12) echo "Not enough room for BirdSuite even when moving the BirdNET-Pi history: nothing changed, the installation stopped."
    rm -rf "$clone"; exit 1 ;;
  *) echo "The check for a BirdNET-Pi installation failed: nothing changed, the installation stopped."; rm -rf "$clone"; exit 1 ;;
esac
mv "$clone" "${HOME}/${prog_dir}"
# mktemp -d made the clone folder u=rwx,go= and the installer only adds g+rw: the web server (in the station user's
# group) could not enter it, so every picture, stylesheet and script of the pages answered 403 and / answered 404
# (2026-11-03, first new installation since the clone moved to a temporary folder); the mode of a plain git clone
chmod u=rwx,go=rx "${HOME}/${prog_dir}"
if [ -n "$migration" ]; then
  python3 "${HOME}/${prog_dir}/scripts/migration.py" prepare "$migration" > /dev/null \
    || { echo "The BirdNET-Pi migration could not be prepared (above): installation stopped."; exit 1; }
fi

"${HOME}/${prog_dir}/scripts/install_birdnet.sh"
rc=$?
if [ $rc -ne 0 ] && [ "$migration" = copy ]; then
  echo "The installation failed: BirdNET-Pi is put back as it was."
  python3 "${HOME}/${prog_dir}/scripts/migration.py" undo || echo "BirdNET-Pi could not be put back: its folders are the *.old ones in ${HOME}."
elif [ $rc -ne 0 ] && [ "$migration" = move ]; then
  echo "The installation failed. The BirdNET-Pi history is kept: the clips in the *.old data folder of ${HOME},"
  echo "the database in ${HOME}/${prog_dir}/state/migration/, the settings in ${HOME}/${prog_dir}/config/BIRDNET.OLD.gz."
fi
if [ $rc -eq 0 ];then
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
set HTTPS_ALWAYS to 1 in Settings > Defaults.
EOF
  sudo sh -c "rm -f /etc/sudoers.d/zz-birdsuite-${USER}-nopasswd; \
    [ -f /etc/sudoers.d/010_${USER}-nopasswd ] && mv /etc/sudoers.d/010_${USER}-nopasswd /etc/sudoers.d/010_${USER}-nopasswd.birdsuite-disabled; \
    systemctl reboot"
else
  echo "The installation exited unsuccessfully."
  exit 1
fi
