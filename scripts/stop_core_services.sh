#!/usr/bin/env bash
# Restarts ALL services and removes ALL unprocessed audio

source "$(dirname "$(readlink -f "${BASH_SOURCE[0]}")")/paths.sh"
services=("$BS_SVC_RECORDING.service"
"$BS_SVC_ANALYSIS.service"
"$BS_SVC_SPECTROGRAM.service")

for i in  "${services[@]}";do
  sudo -n "$BS_SVC_HELPER" stop "${i}"
done
rm -rf "${RECS_DIR:?}"/"$(date +%B-%Y/%d-%A)"/*
