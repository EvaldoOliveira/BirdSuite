#!/usr/bin/env bash
source "$(dirname "$(readlink -f "${BASH_SOURCE[0]}")")/core.sh"
journalctl --no-hostname -q -o short -fu "$BS_SVC_ANALYSIS" -u "$BS_SVC_RECORDING" | sed "s/$(date "+%b %d ")//g;s/${HOME//\//\\/}\///g;/Line/d;/find/d;/systemd/d;s/ .*\[.*\]: /---/"
