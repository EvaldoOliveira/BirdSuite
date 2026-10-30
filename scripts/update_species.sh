#!/usr/bin/env bash
# Update the species list
#set -x
source "$(dirname "$(readlink -f "${BASH_SOURCE[0]}")")/paths.sh"
if [ -f $HOME/BirdNET-Pi/config/data/birds.db ];then
sqlite3 $HOME/BirdNET-Pi/config/data/birds.db "SELECT DISTINCT(Com_Name) FROM detections" | sort >  ${IDFILE}
fi
