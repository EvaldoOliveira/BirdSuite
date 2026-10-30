#!/bin/bash

# KEEP ONLY THE NUMBER OF FILES PER SPECIES DEFINED IN THE OPTIONS

source "$(dirname "$(readlink -f "${BASH_SOURCE[0]}")")/paths.sh"
base_dir="$HOME/BirdSongs/Extracted/By_Date"
max_files_species="${MAX_FILES_SPECIES:-100}"
# the clips of the last N days are never trimmed by the per-species limit (owner 2026-10-24: PURGE_RECENT_DAYS, was 7; 3 since 2026-10-29;
# 0 = every day, nothing trimmed)
recent_days="${PURGE_RECENT_DAYS:-3}"
[[ "$recent_days" =~ ^[0-9]+$ ]] || recent_days=7
cd "$base_dir" || exit 1
# cron throws the output away: the outcome goes to syslog (tag birdsuite-disk_species_clean)
log() { logger -t birdsuite-disk_species_clean "$1"; }
count_clips() { find . -type f -not -name "*.png" -not -name "*.jpg" -not -name temp | wc -l; }

# in the station's state/, shared with play.php and purge_protection.py (2026-10-23 test run: a fixed /tmp name);
# opened read-only, so a lock the web server created serves as well
[ -e "$BS_STATE/purge.lock" ] || : > "$BS_STATE/purge.lock"
exec 8< "$BS_STATE/purge.lock"
# no wait for ever on a lock left by a hung run (2026-10-29): this night is skipped, the next one runs
flock -w 3600 8 || { log "skipped: the purge lock is held for more than an hour"; exit 1; }
# the scripts run below under this lock do not take it again (purge_protection.py)
export PURGE_LOCK_HELD=1

# the audio answered with a cause (Dog, Human, Engine, Noise, Ignore) moves to Extracted/Purge, ready to be deleted in
# Species › Purge Files; the detections stay in the database (owner 2026-10-22). It deletes nothing and skips the locked
# clips itself, so it runs even when the protection list below fails (2026-10-23 test run)
"$HOME"/BirdNET-Pi/birdnet/bin/python3 "$HOME"/BirdNET-Pi/scripts/purge_stage.py || true

# the spectrograms of the previous days are kept as JPEG (owner 2026-10-22); the audio clips are not touched
spectro_archive() {
    "$HOME"/BirdNET-Pi/birdnet/bin/python3 "$HOME"/BirdNET-Pi/scripts/spectro_archive.py || true
}

# automatic purge protection (top N per species + confirmed reviews) before anything is deleted; no fresh list =
# nothing deleted (the JPEG archive still runs, it deletes no audio)
if ! "$HOME"/BirdNET-Pi/birdnet/bin/python3 "$HOME"/BirdNET-Pi/scripts/purge_protection.py; then
    log "refused: the purge protection list could not be refreshed - no clip deleted"
    spectro_archive
    exit 1
fi

# FULL_DISK=external (US-128, owner 2026-10-29): the clips past the limits are moved to the external folder instead of
# deleted (clip_dispose.py); when that folder cannot be used nothing is removed tonight
dispose=("$HOME"/BirdNET-Pi/birdnet/bin/python3 "$HOME"/BirdNET-Pi/scripts/clip_dispose.py)
if ! why=$("${dispose[@]}" --check); then
    log "skipped: external backup unavailable ($why) - no clip removed"
    spectro_archive
    exit 1
fi

# the daily cap per species (owner 2026-10-24, MAX_FILES_SPECIES_DAY): in each day, the best N clips of a species stay,
# protected clips aside; run here, after the protection list was refreshed
"$HOME"/BirdNET-Pi/birdnet/bin/python3 "$HOME"/BirdNET-Pi/scripts/daily_cap.py || true

# files to keep per species (owner 2026-10-21): species_max_files.txt (Sci_Name=N, set on the species page) overrides
# the global MAX_FILES_SPECIES for that species; keyed here by the species folder name: the scientific name (owner
# 2026-10-24) and, for clips not migrated yet, the common name (both sanitized)
declare -A keep_of
override_file="$HOME/BirdNET-Pi/config/species_lists/species_max_files.txt"
if [ -s "$override_file" ]; then
    while IFS='|' read -r com sci; do
        n=$(grep -m1 "^${sci}=" "$override_file" | cut -d= -f2)
        if [[ "$n" =~ ^[0-9]+$ ]]; then
            keep_of["$(echo "$com" | tr ' ' '_' | tr -d "'" | sed 's/_*$//')"]="$n"
            keep_of["$(echo "$sci" | tr ' ' '_' | tr -d "'" | sed 's/_*$//')"]="$n"
        fi
    done < <(sqlite3 -readonly "$HOME"/BirdNET-Pi/config/data/birds.db "SELECT DISTINCT Com_Name || '|' || Sci_Name FROM detections;")
fi

# 0 keeps everything (owner 2026-10-24): every day protected (PURGE_RECENT_DAYS=0) or every clip protected
# (PURGE_PROTECT_TOP_N=0) = the per-species limit deletes nothing
if [ "$recent_days" = 0 ] || [ "${PURGE_PROTECT_TOP_N:-50}" = 0 ]; then
    log "nothing to do: every clip kept (PURGE_RECENT_DAYS=$recent_days, PURGE_PROTECT_TOP_N=${PURGE_PROTECT_TOP_N:-50})"
    spectro_archive
    exit 0
fi

# If max_files_species is not higher than 1 and no species has its own number, exit
if [[ "$max_files_species" -lt 1 && ${#keep_of[@]} -eq 0 ]]; then
    log "nothing to do: every file of every species is kept"
    spectro_archive
    exit 0
fi

# Get unique species folders: By_Date/<date>/<Sci_safe> (owner 2026-10-24), and <Com_safe> for clips not migrated yet
bird_names=$(
    sqlite3 -readonly "$HOME"/BirdNET-Pi/config/data/birds.db <<EOF
.mode list
.headers off
SELECT DISTINCT Sci_Name FROM detections UNION SELECT DISTINCT Com_Name FROM detections;
.quit
EOF
)

# Sanitize the bird names (remove single quotes and replace spaces with underscores)
sanitized_names="$(echo "$bird_names" | tr ' ' '_' | tr -d "'" | grep '[[:alnum:]]')"
# Remove trailing underscores
sanitized_names=$(echo "$sanitized_names" | sed 's/_*$//')
# Define how date works
dateformat=""
if test "$(date -d "-7 days" '+%Y-%m-%d' 2>/dev/null)"; then
    dateformat=" days"
fi
# the find conditions that leave out the clips of the last recent_days days (and today)
recent_not=()
for ((i = recent_days; i >= 1; i--)); do recent_not+=(-not -name "*$(date -d "-$i$dateformat" '+%Y-%m-%d')*"); done
recent_not+=(-not -name "*$(date '+%Y-%m-%d')*")

# find detail :
# In the base folders (that corresponds to the BirdSongs/By_date)
# Look for all folders that have the correct species names whatever the date
# Look for files that have the correct format (containing a date), and that have an extension
# For all That are not *.png / *.jpg (as the objective is to limit the number of audio files)
# That were not taken in the past PURGE_RECENT_DAYS days (7 by default) (= that don't contain the date from that past 7 days). $dateformat is configured as a different variables, as ubuntu accepts "5 days" while alpine accepts only "5"
# That are not included in the file disk_check_exclude.txt that lists files protected from purge
# If the specie name had a "-" in it, it must be converted to "=" to ensure that we have always the same number of "-" separated fields in the filename
# Sort by confidence level (field 4 separated by -)
# Sort by date (1 for year, 2 for month, 3 for days)
# Remove the top x files, corresponding to the files best matching the criteria of confidence + age ; this corresponds to the number of file to keep (in addition to protected files
# Rename species that had a = in their name to - (we don't need anymore - separated fields)
# Duplicate all lines to append .png and .jpg at the end, to remove the linked spectrogram (rm -f: only one exists)
# Hand them to clip_dispose.py (deleted, or moved to the external folder with FULL_DISK=external), then echo the number
# of remaining files

# Read each line from the variable and echo the species
clips_before=$(count_clips)
while read -r species; do
    keep="${keep_of[$species]:-$max_files_species}"
    # 0 = keep every file of this species
    if [[ "$keep" -lt 1 ]]; then continue; fi
    echo -n "$species : "
    species_san="${species//-/=}"
    find */"$species" -type f -name "*[0-9][0-9][0-9][0-9]-[0-9][0-9]-[0-9][0-9]*.*" \
        -not -name "*.png" -not -name "*.jpg" \
        "${recent_not[@]}" |
        grep -vFf "$HOME/BirdNET-Pi/config/scripts/disk_check_exclude.txt" |
        sed "s|$species|$species_san|g" |
        awk -F'/' '{ n=$NF;
            # confidence: new format carries an explicit -conf-NN. marker;
            # legacy is Species-NN-date... (2nd field of the sanitized basename)
            if (match(n, /-conf-[0-9]+\./)) {
                conf = substr(n, RSTART + 6, RLENGTH - 7) + 0;
            } else {
                split(n, a, "-"); conf = a[2] + 0;
            }
            print conf "\t" $0 }' |
        sort -t"$(printf '\t')" -k1,1nr -k2,2r |
        cut -f2- |
        tail -n +"$((keep + 1))" |
        sed "s|$species_san|$species|g" |
        sed 'p; s/\(\.[^.]*\)$/\1.png/; p; s/\.png$/.jpg/' |
        "${dispose[@]}" > /dev/null && echo "success ($(find */"$species" -type f -name "*[0-9][0-9][0-9][0-9]-[0-9][0-9]-[0-9][0-9]*.*" \
        -not -name "*.png" -not -name "*.jpg" | wc -l)) remaining" || echo "failed ($?)"
done <<<"$sanitized_names"
clips_after=$(count_clips)
log "$((clips_before - clips_after)) clips deleted, ${clips_after} kept (${max_files_species} per species, ${#keep_of[@]} species with their own number; protected clips and the last ${recent_days} days kept)"

spectro_archive

# The sound-repo spool (US-38) only holds clips waiting for the upload: empty it while the upload is off
if [ -n "${SOUND_REPO_PATH:-}" ]; then
  "$HOME"/BirdNET-Pi/scripts/sound_repo_clean.sh
fi

# the per-month page caches built again for the morning (scripts/cache_warm.sh, 2026-10-23 run 3)
"$HOME"/BirdNET-Pi/scripts/cache_warm.sh > /dev/null 2>&1 || true
