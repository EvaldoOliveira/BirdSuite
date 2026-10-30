#!/usr/bin/env bash
# Restarts ALL services and removes ALL unprocessed audio

source "$(dirname "$(readlink -f "${BASH_SOURCE[0]}")")/paths.sh"
services=(birdnet_recording.service
custom_recording.service
birdnet_analysis.service
chart_viewer.service
spectrogram_viewer.service)

for i in  "${services[@]}";do
  sudo -n /usr/local/sbin/birdsuite-svc stop "${i}"
done
rm -rf "${RECS_DIR:?}"/"$(date +%B-%Y/%d-%A)"/*
