#!/usr/bin/env bash
# Restarts ALL services and removes ALL unprocessed audio
source "$(dirname "$(readlink -f "${BASH_SOURCE[0]}")")/paths.sh"
set -x
my_dir=$BS_SCRIPTS


sudo -n "$BS_SVC_HELPER" stop "$BS_SVC_RECORDING.service"

services=("$BS_SVC_SPECTROGRAM.service"
  "$BS_SVC_ICECAST.service"
  "$BS_SVC_RECORDING.service"
  "$BS_SVC_ANALYSIS.service"
  "$BS_SVC_LOG.service")

for i in  "${services[@]}";do
  sudo -n "$BS_SVC_HELPER" restart "${i}"
done

for i in {1..5}; do
  # We want to loop here (5*5seconds) until the analysis service is running
  systemctl is-active --quiet "$BS_SVC_ANALYSIS.service" \
	  && logger "[$0] $BS_SVC_ANALYSIS.service is running" \
	  && break

  sleep 5
done
