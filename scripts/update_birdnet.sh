#!/usr/bin/env bash
################################################################################
# scripts/update_birdnet.sh - UPDATE BIRDSUITE'S GIT REPO
#
# PURPOSE:
#   Update BirdSuite's Git Repo: the station user's working copy only (fetch, switch, reset). Run as the
#   station user by birdsuite-root update, which then runs the system steps (update_birdnet_snippets.sh) from
#   root's own copy of the program; nothing here runs as root (security 2026-11-02: root never runs code the
#   station user can edit).
#
# DEPENDENCIES:
#   - scripts/update_birdnet_snippets.sh
#   - scripts/paths.sh
#   - scripts/pre_update.sh
#   - birdsuite-root (sudo helper)
#
# USAGE:
#   bash scripts/update_birdnet.sh
#   Referenced by scripts/lib/station.php, scripts/update_birdnet_snippets.sh
################################################################################

source "$(dirname "$(readlink -f "${BASH_SOURCE[0]}")")/paths.sh"
trap 'exit 1' SIGINT SIGHUP

usage() { echo "Usage: $0 [-r <remote name>] [-b <branch name>] [-a]" 1>&2; exit 1; }

if [ "$(id -u)" -eq 0 ]; then
	echo "Run the update with: sudo $BS_ROOT_HELPER update (this script runs as the station user)"
	exit 1
fi
USER=$(id -un)
HOME=$(getent passwd "$USER" | cut -d: -f6)
my_dir=$BS_SCRIPTS

# Defaults
remote="origin"
# the branch the updater follows: UPDATE_BRANCH in BirdSuite.conf (main); -b overrides it
branch="${UPDATE_BRANCH:-main}"
auto_update=""

while getopts ":r:b:a" o; do
	case "${o}" in
		r)
			remote=${OPTARG}
			git -C $BS_PROG remote show $remote > /dev/null 2>&1
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

# the station user itself runs every step (kept as a function: the trace of each git step)
sudo_with_user () {
	local ret_val
	set -x
	"$@"
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
	sudo_with_user git -C $BS_PROG fetch --tags --force $remote $branch || { log_auto "fetch of $remote $branch failed (offline?): not updated"; exit 1; }
	# compare with what was just fetched, not with the upstream of the checked-out branch: after a
	# change of UPDATE_BRANCH those differ and "No updates" would be reported forever
	behind_count=$(sudo_with_user git -C $BS_PROG rev-list --count HEAD..FETCH_HEAD)
	if [ "${behind_count}" -eq 0 ]; then
		echo "No updates"
		log_auto "no updates on $remote/$branch"
		exit 0
	fi
}

[ -n "${auto_update}" ] && can_auto_update

# Get current HEAD hash
commit_hash=$(sudo_with_user git -C $BS_PROG rev-parse HEAD)

# Reset current HEAD to remove any local changes
sudo_with_user git -C $BS_PROG reset --hard

# Fetches latest changes
# a single-branch clone only follows its own branch: "switch --track" refuses any other one until the
# clone is told to follow it too (a tag or a commit is not a branch and is simply fetched by name)
if sudo_with_user git -C $BS_PROG ls-remote --exit-code --heads $remote $branch > /dev/null 2>&1; then
	fetch_specs=$(git -C $BS_PROG config --get-all remote.$remote.fetch)
	if [[ "$fetch_specs" != *"refs/heads/*"* && "$fetch_specs" != *"refs/heads/$branch:"* ]]; then
		sudo_with_user git -C $BS_PROG remote set-branches --add $remote $branch || { echo "Error: could not follow '$remote/$branch'"; log_auto "could not follow $remote/$branch: update stopped"; exit 1; }
	fi
fi
sudo_with_user git -C $BS_PROG fetch --tags --force $remote $branch || { echo "Error: fetch of '$remote $branch' failed"; log_auto "fetch of $remote $branch failed: update stopped"; exit 1; }

# Switches git to specified branch (or checks out a tag/commit detached)
if sudo_with_user git -C $BS_PROG show-ref --verify --quiet refs/remotes/$remote/$branch; then
	sudo_with_user git -C $BS_PROG switch -C $branch --track $remote/$branch || { echo "Error: switch to '$remote/$branch' failed"; log_auto "switch to $remote/$branch failed: update stopped"; exit 1; }
else
	sudo_with_user git -C $BS_PROG -c advice.detachedHead=false checkout $branch || { echo "Error: checkout of '$branch' failed"; log_auto "checkout of $branch failed: update stopped"; exit 1; }
fi

# Prints out changes
sudo_with_user git --no-pager -C $BS_PROG diff --stat $commit_hash HEAD

$my_dir/pre_update.sh

# the system steps (units, packages, web server, links in /usr/local/bin: update_birdnet_snippets.sh) follow in
# birdsuite-root update, from root's own copy of the program
log_auto "updated from ${commit_hash:0:8} to $(sudo_with_user git -C "$BS_PROG" rev-parse --short HEAD) ($remote/$branch)"
