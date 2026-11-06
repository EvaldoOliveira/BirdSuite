#!/usr/bin/env bash
################################################################################
# scripts/web_console.sh - THE LOGIN OF THE WEB TERMINAL AND THE FILE BROWSER
#
# PURPOSE:
#   The login of the web terminal and the file browser (bug #61, run 5 HRD-01): run by gotty as the
#   station user, it asks the user name and opens an ssh session to the station itself, so the shell (or mc) is an
#   ordinary ssh login with nothing taken away, while the terminal and files services stay sandboxed (no su, no set-uid,
#   no new privileges; install_helpers.sh hardening_dropin console). The ssh is the station's own console sshd on
#   127.0.0.1:BS_PORT_CONSOLE_SSH (install_helpers.sh install_console_ssh), there whether or not ssh is on for the
#   network; the host keys from /etc/ssh, pinned in the service's runtime directory: no ~/.ssh is read or written.
#   Usage: web_console.sh [command]   (e.g. mc; without one the login shell)
#
# DEPENDENCIES:
#   - scripts/install_helpers.sh
#   - scripts/paths.sh
#
# USAGE:
#   bash scripts/web_console.sh
#   Referenced by scripts/install_helpers.sh, templates/units/SVC_FILES.service,
#   templates/units/SVC_TERMINAL.service
################################################################################

set -u
# shellcheck disable=SC1091
source "$(dirname "$(readlink -f "${BASH_SOURCE[0]}")")/paths.sh"
dir=${RUNTIME_DIRECTORY:-}
[ -n "$dir" ] && [ -d "$dir" ] && [ -w "$dir" ] || { echo "web_console.sh: no runtime directory (run by the terminal or files service)" >&2; exit 1; }
: > "$dir/known_hosts"
for k in /etc/ssh/ssh_host_*_key.pub; do
	[ -r "$k" ] && printf 'birdsuite-local %s\n' "$(cut -d' ' -f1,2 "$k")" >> "$dir/known_hosts"
done
read -r -p "Login: " username || exit 1
[[ $username =~ ^[-_.a-z0-9]{1,30}$ ]] || exit 1
systemctl is-active --quiet "$BS_SVC_CONSOLE_SSH.socket" 2> /dev/null \
	|| { echo "The console login is not running (the next update sets it up again: $BS_SVC_CONSOLE_SSH.socket)."; exit 1; }
exec ssh -t -F /dev/null -p "$BS_PORT_CONSOLE_SSH" -l "$username" \
	-o HostKeyAlias=birdsuite-local -o UserKnownHostsFile="$dir/known_hosts" -o GlobalKnownHostsFile=/dev/null \
	-o StrictHostKeyChecking=yes -o PubkeyAuthentication=no -o PreferredAuthentications=keyboard-interactive,password \
	-o LogLevel=ERROR 127.0.0.1 ${1:+"$1"}
