#!/usr/bin/env bash
# BirdSuite paths (owner 2026-10-22): every file of a station's own (settings, lists, notifications, locations, the
# database) lives under ~/BirdNET-Pi/config; nothing in /etc. Found from this file's place, so it works for the user,
# for root and through the /usr/local/bin links. Sourced by every script instead of BirdSuite.conf: it loads the
# settings too.
BS_HOME=$(cd "$(dirname "$(readlink -f "${BASH_SOURCE[0]}")")/.." && pwd)
BS_CONFIG=$BS_HOME/config
BS_CONF=$BS_CONFIG/BirdSuite.conf                 # the station's settings
BS_DEFAULTS=$BS_CONFIG/factory_defaults.conf    # the factory defaults (in git)
BS_LISTS=$BS_CONFIG/species_lists
BS_CUSTOM_LISTS=$BS_LISTS/custom
BS_NOTIFY=$BS_CONFIG/notifications
BS_DATA=$BS_CONFIG/data
BS_DB=$BS_DATA/birds.db
BS_STATE=$BS_HOME/state                          # the running state, not settings (outside config/ and the backup)
# shellcheck disable=SC1090
[ -r "$BS_CONF" ] && source "$BS_CONF"
