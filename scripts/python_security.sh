#!/usr/bin/env bash
################################################################################
# scripts/python_security.sh - THE SECURITY FLOORS OF THE STATION'S PYTHON PACKAGES
#
# PURPOSE:
#   The security floors of the station's Python packages (requirements-security.txt), applied on every update
#   (2026-11-02 release run 4: the pilot's venv kept old pillow, urllib3, certifi, oauthlib and msgpack with
#   known vulnerabilities, because an update only installed what was missing). Run as the station user: 1.
#   every package of the floors file that is installed below its floor is raised to it (pip's only-if-needed:
#   a package already at or above its floor is left alone, one not installed is not added); 2. packages the
#   station no longer uses (left by earlier versions, nothing requires them, no code imports them) are
#   removed, so their vulnerabilities go with them. Exit status: 0, or pip's when the upgrade failed (the
#   update goes on, the next one tries again).
#
# DEPENDENCIES:
#   - scripts/paths.sh
#
# USAGE:
#   bash scripts/python_security.sh
#   Referenced by scripts/update_birdnet_snippets.sh
################################################################################

source "$(dirname "$(readlink -f "${BASH_SOURCE[0]}")")/paths.sh"
floors="$BS_PROG/requirements-security.txt"
retired=(GitPython tornado pytest)
[ -x "$BS_PIP" ] && [ -f "$floors" ] || exit 0

# 1. the floors of the installed packages
specs=()
while read -r spec; do
	spec=${spec%%#*}; spec=${spec//[[:space:]]/}
	[ -n "$spec" ] || continue
	name=${spec%%[<>=!~]*}
	"$BS_PIP" show -q "$name" > /dev/null 2>&1 && specs+=("$spec")
done < "$floors"
rc=0
if [ ${#specs[@]} -gt 0 ]; then
	"$BS_PIP" install -q --disable-pip-version-check --upgrade-strategy only-if-needed "${specs[@]}" || rc=$?
fi

# 2. retired packages, only when nothing installed requires them
for p in "${retired[@]}"; do
	req=$("$BS_PIP" show "$p" 2>/dev/null | sed -n 's/^Required-by: *//p')
	"$BS_PIP" show -q "$p" > /dev/null 2>&1 && [ -z "$req" ] && "$BS_PIP" uninstall -y -q "$p" > /dev/null 2>&1
done
exit $rc
