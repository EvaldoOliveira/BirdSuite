#!/usr/bin/env bash
# Looks up, in the background, the labels of the station's models that model/species_info.csv does not know yet (a new
# model, a newer labels file) and adds them to model/species_info_local.csv (outside git). Runs at the end of every
# install and update; needs the internet (GBIF). Log: state/species_info_build.log of the program folder
source "$(dirname "$(readlink -f "${BASH_SOURCE[0]}")")/core.sh"
my_dir=$BS_PROG
cd "$my_dir" || exit 1
labels=()
for f in model/*_Labels.csv model/*_Labels.txt model/labels.txt model/l18n/labels_en.json; do
  [ -f "$f" ] || continue
  case "$f" in *_Geo_*) continue ;; esac
  labels+=("$f")
done
[ ${#labels[@]} -gt 0 ] || exit 0
nohup nice python3 scripts/build_species_info.py "${labels[@]}" --base model/species_info.csv --out model/species_info_local.csv \
  > "$BS_STATE/species_info_build.log" 2>&1 < /dev/null &
