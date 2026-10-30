#!/usr/bin/env bash
# Restarts ALL services and removes ALL unprocessed audio
source "$(dirname "$(readlink -f "${BASH_SOURCE[0]}")")/paths.sh"
set -x
my_dir=$HOME/BirdNET-Pi/scripts


sudo -n /usr/local/sbin/birdsuite-svc stop birdnet_recording.service

services=(chart_viewer.service
  spectrogram_viewer.service
  icecast2.service
  birdnet_recording.service
  birdnet_analysis.service
  birdnet_log.service)

for i in  "${services[@]}";do
  sudo -n /usr/local/sbin/birdsuite-svc restart "${i}"
done

for i in {1..5}; do
  # We want to loop here (5*5seconds) until the birdnet_analysis.service is running
  systemctl is-active --quiet birdnet_analysis.service \
	  && logger "[$0] birdnet_analysis.service is running" \
	  && break

  sleep 5
done
