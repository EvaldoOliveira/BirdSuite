#!/usr/bin/env bash
# Update BirdSuite's Git Repo
source "$(dirname "$(readlink -f "${BASH_SOURCE[0]}")")/paths.sh"
trap 'exit 1' SIGINT SIGHUP

usage() { echo "Usage: $0 [-r <remote name>] [-b <branch name>] [-a]" 1>&2; exit 1; }

if [ -n "${BIRDNET_USER}" ]; then
  USER=${BIRDNET_USER}
  HOME=/home/${BIRDNET_USER}
else
  USER=$(awk -F: '/1000/ {print $1}' /etc/passwd)
  HOME=$(awk -F: '/1000/ {print $6}' /etc/passwd)
fi
my_dir=$HOME/BirdNET-Pi/scripts

# Defaults
remote="origin"
# Release channel (US-51e): 'stable' = the last released version (what installed stations follow),
# 'main' = the development line (the pilot). UPDATE_BRANCH in BirdSuite.conf; -b overrides it.
branch="${UPDATE_BRANCH:-main}"
auto_update=""

while getopts ":r:b:a" o; do
  case "${o}" in
    r)
      remote=${OPTARG}
      git -C $HOME/BirdNET-Pi remote show $remote > /dev/null 2>&1
      ret_val=$?

      if [ $ret_val -ne 0 ]; then
        echo "Error: remote '$remote' not found. Add the upstream remote to your repository and try again."
        exit 1
      fi
      ;;
    b)
      branch=${OPTARG}
      ;;
    a)
      auto_update=1
      ;;
    *)
      usage
      ;;
  esac
done
shift $((OPTIND-1))

# the public repository has only main, the last release (owner 2026-10-27: during the beta it holds the last closed
# release and the commits of the release in development, nothing else): a station still on 'stable' follows main there
if [ "$branch" = stable ] && ! git -C $HOME/BirdNET-Pi ls-remote --exit-code --heads $remote stable > /dev/null 2>&1 \
    && git -C $HOME/BirdNET-Pi ls-remote --exit-code --heads $remote main > /dev/null 2>&1; then
  branch=main
fi

sudo_with_user () {
  local ret_val
  set -x
  sudo -u $USER "$@"
  # keep the exit status of the command: a trailing "set +x" made this function always succeed,
  # so none of the "|| exit 1" guards below could ever fire
  { ret_val=$?; set +x; } 2> /dev/null
  return $ret_val
}

# the automatic update (cron, -a) throws its output away: its outcome goes to syslog (tag birdsuite-update)
log_auto () {
  [ -n "${auto_update}" ] && logger -t birdsuite-update "$1"
  return 0
}

can_auto_update () {
  if [ -z ${AUTOMATIC_UPDATE} ] || [ "${AUTOMATIC_UPDATE}" == 0 ]; then
    echo "Auto update is not enabled"
    exit 0
  fi
  # the days and the time chosen in Tools › Status (owner 2026-10-25): cron calls this every 5 minutes, local time
  case ",${AUTOMATIC_UPDATE_DAYS:-0}," in *",$(date +%w),"*) ;; *) exit 0 ;; esac
  at=${AUTOMATIC_UPDATE_TIME:-03:00}
  late=$(( 10#$(date +%H) * 60 + 10#$(date +%M) - 10#${at%%:*} * 60 - 10#${at##*:} ))
  { [ "$late" -ge 0 ] && [ "$late" -lt 5 ]; } || exit 0
  sudo_with_user git -C $HOME/BirdNET-Pi fetch --tags --force $remote $branch || { log_auto "fetch of $remote $branch failed (offline?): not updated"; exit 1; }
  # compare with what was just fetched, not with the upstream of the checked-out branch: after a
  # change of UPDATE_BRANCH those differ and "No updates" would be reported forever
  behind_count=$(sudo_with_user git -C $HOME/BirdNET-Pi rev-list --count HEAD..FETCH_HEAD)
  if [ "${behind_count}" -eq 0 ]; then
    echo "No updates"
    log_auto "no updates on $remote/$branch"
    exit 0
  fi
}

[ -n "${auto_update}" ] && can_auto_update

# Get current HEAD hash
commit_hash=$(sudo_with_user git -C $HOME/BirdNET-Pi rev-parse HEAD)

# Reset current HEAD to remove any local changes
sudo_with_user git -C $HOME/BirdNET-Pi reset --hard

# Fetches latest changes
# a single-branch clone only follows its own branch: "switch --track" refuses any other one until the
# clone is told to follow it too (a tag or a commit is not a branch and is simply fetched by name)
if sudo_with_user git -C $HOME/BirdNET-Pi ls-remote --exit-code --heads $remote $branch > /dev/null 2>&1; then
  fetch_specs=$(git -C $HOME/BirdNET-Pi config --get-all remote.$remote.fetch)
  if [[ "$fetch_specs" != *"refs/heads/*"* && "$fetch_specs" != *"refs/heads/$branch:"* ]]; then
    sudo_with_user git -C $HOME/BirdNET-Pi remote set-branches --add $remote $branch || { echo "Error: could not follow '$remote/$branch'"; log_auto "could not follow $remote/$branch: update stopped"; exit 1; }
  fi
fi
sudo_with_user git -C $HOME/BirdNET-Pi fetch --tags --force $remote $branch || { echo "Error: fetch of '$remote $branch' failed"; log_auto "fetch of $remote $branch failed: update stopped"; exit 1; }

# Switches git to specified branch (or checks out a tag/commit detached)
if sudo_with_user git -C $HOME/BirdNET-Pi show-ref --verify --quiet refs/remotes/$remote/$branch; then
  sudo_with_user git -C $HOME/BirdNET-Pi switch -C $branch --track $remote/$branch || { echo "Error: switch to '$remote/$branch' failed"; log_auto "switch to $remote/$branch failed: update stopped"; exit 1; }
else
  sudo_with_user git -C $HOME/BirdNET-Pi -c advice.detachedHead=false checkout $branch || { echo "Error: checkout of '$branch' failed"; log_auto "checkout of $branch failed: update stopped"; exit 1; }
fi

# Prints out changes
sudo_with_user git --no-pager -C $HOME/BirdNET-Pi diff --stat $commit_hash HEAD

$my_dir/pre_update.sh

sudo systemctl daemon-reload
sudo ln -sf $my_dir/* /usr/local/bin/

# The script below handles changes to the host system
# Any additions to the updater should be placed in that file.
sudo $my_dir/update_birdnet_snippets.sh
# as the station user (2026-10-29: git refused the folder to root, "dubious ownership", on a station without a safe.directory)
log_auto "updated from ${commit_hash:0:8} to $(sudo_with_user git -C "$HOME"/BirdNET-Pi rev-parse --short HEAD) ($remote/$branch)"
