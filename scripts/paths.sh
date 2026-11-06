#!/usr/bin/env bash
################################################################################
# scripts/paths.sh - BIRDSUITE PATHS AND SETTINGS
#
# PURPOSE:
#   BirdSuite paths and settings (owner 2026-10-22, core 2026-11-02): the folder layout, service names and
#   ports come from core.env through core.sh (BS_HOME, BS_PROG, BS_CONFIG, BS_CONF, BS_DB, BS_STATE,
#   BS_SVC_*...); this file then loads the station's settings. Sourced by every script instead of
#   BirdSuite.conf. Root (birdsuite-root update runs the root steps from its own copy of the program) sets
#   BS_HOME, the station user's home, before sourcing it.
#
# DEPENDENCIES:
#   - scripts/core.sh
#   - birdsuite-root (sudo helper)
#
# USAGE:
#   bash scripts/paths.sh
#   Referenced by scripts/backup_data.sh, scripts/birdnet_changeidentification.sh,
#   scripts/birdnet_recording.sh, scripts/cleanup.sh, scripts/clear_all_data.sh, scripts/core.sh,
#   scripts/createdb.sh …
################################################################################

# shellcheck disable=SC1091
source "$(dirname "$(readlink -f "${BASH_SOURCE[0]}")")/core.sh"
# root never sources the settings (security 2026-11-02): the station user and the pages write that file, so for root
# its KEY=value lines are read as plain text: no expansion, no command, and never over a variable already set (PATH,
# HOME, IFS...), the station user (BIRDNET_USER: root is told it by birdsuite-root) or one of bash's own (BASH*, CDPATH, PS4...)
if [ "$(id -u)" -eq 0 ]; then
	if [ -r "$BS_CONF" ] && [ ! -L "$BS_CONF" ]; then
		declare -A bs_mine=()
		while IFS= read -r bs_line || [ -n "$bs_line" ]; do
			[[ $bs_line =~ ^([A-Z][A-Z0-9_]*|LogLevel_[A-Za-z]+)=(.*)$ ]] || continue
			bs_k=${BASH_REMATCH[1]}; bs_v=${BASH_REMATCH[2]}
			case "$bs_k" in BASH*|CDPATH|GLOBIGNORE|FIGNORE|EXECIGNORE|POSIXLY_CORRECT|SHELLOPTS|PS[0-9]|PROMPT_COMMAND|ENV|FUNCNEST|TMOUT|HISTFILE|IFS|PATH|HOME|USER|SHELL|LD_*|BIRDNET_USER) continue ;; esac
			# a later line wins over an earlier one, as with source
			[ -n "${!bs_k+x}" ] && [ -z "${bs_mine[$bs_k]:-}" ] && continue
			if [[ $bs_v =~ ^\"(.*)\"[[:space:]]*$ ]] || [[ $bs_v =~ ^\'(.*)\'[[:space:]]*$ ]]; then bs_v=${BASH_REMATCH[1]}; fi
			printf -v "$bs_k" '%s' "$bs_v"
			bs_mine[$bs_k]=1
		done < "$BS_CONF"
		unset bs_line bs_k bs_v bs_mine
	fi
else
	# shellcheck disable=SC1090
	[ -r "$BS_CONF" ] && source "$BS_CONF"
fi
