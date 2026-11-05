#!/usr/bin/env bash
set -x

source "$(dirname "$(readlink -f "${BASH_SOURCE[0]}")")/paths.sh"
used="$(df -h ${EXTRACTED} | tail -n1 | awk '{print $5}')"
purge_threshold="${PURGE_THRESHOLD:-70}"
# cron throws the output away: what this check decides goes to syslog (tag birdsuite-disk_check)
log() { logger -t birdsuite-disk_check "$1"; }
# the day's lowest and highest disk use for Status (US-133), whatever the purge settings
"$(dirname "$(readlink -f "${BASH_SOURCE[0]}")")"/disk_usage_log.sh || true
# a migration from BirdNET-Pi on the same card (US-01): its import once the station has its location, and the
# notification at the end of the test period (scripts/migration.py tick; nothing to do without its record)
[ -f "$BS_MIGRATION" ] && "$BS_PYTHON" "$BS_SCRIPTS"/migration.py tick 2>&1 | logger -t birdsuite-migration
# 0 keeps everything (owner 2026-10-24): no purge and no stop for a full disk
[ "$purge_threshold" = 0 ] && exit 0

if [ "${used//%}" -ge "$purge_threshold" ]; then

  # FULL_DISK=external (US-128, owner 2026-10-29): the oldest sounds are moved to FULL_DISK_PATH instead of deleted
  # (clip_dispose.py); an external folder that cannot be used follows FULL_DISK_FALLBACK: keep stops the recording here,
  # purge deletes them (clip_dispose.py does that itself)
  dispose=("$BS_PYTHON" "$BS_SCRIPTS"/clip_dispose.py)
  if [ "$FULL_DISK" = external ] && ! why=$("${dispose[@]}" --check); then
    log "disk ${used} used (limit ${purge_threshold}%): external backup unavailable ($why), fallback keep - core services stopped"
    /usr/local/bin/stop_core_services.sh
    exit 1
  fi
  case $FULL_DISK in
    purge|external) echo "Removing oldest data"
        # PURGE_PROTECT_TOP_N=0 protects every clip (owner 2026-10-24: 0 keeps everything)
        if [ "${PURGE_PROTECT_TOP_N:-50}" = 0 ]; then
            log "disk ${used} used (limit ${purge_threshold}%): every clip protected (PURGE_PROTECT_TOP_N=0) - nothing deleted"
            exit 0
        fi
        # the loop below deletes */*/* of the current folder: never run it anywhere else
        cd "${EXTRACTED:?}"/By_Date/ || exit 1
        # automatic purge protection (top N per species + confirmed reviews); no fresh list = no purge
        if ! "$BS_PYTHON" "$BS_SCRIPTS"/purge_protection.py; then
            echo "purge protection could not be refreshed - nothing deleted"
            log "disk ${used} used (limit ${purge_threshold}%): purge refused, the protection list could not be refreshed - nothing deleted"
            exit 1
        fi
        if ! grep -qxFe \#\#start "$BS_DISK_EXCLUDE"; then
            log "disk ${used} used (limit ${purge_threshold}%): purge refused, no protection list - nothing deleted"
            exit
        fi
        # the protection list is not rewritten while this purge reads it
        # in the station's state/, shared with play.php and purge_protection.py (2026-10-23 test run: a fixed /tmp name);
        # opened read-only, so a lock the web server created serves as well
        [ -e "$BS_STATE/purge.lock" ] || : > "$BS_STATE/purge.lock"
        exec 8< "$BS_STATE/purge.lock"
        flock 8
        dircount=$(find ${EXTRACTED}/By_Date/* -maxdepth 0 -type d | wc -l)
        if [ "$dircount" -gt 0 ]; then
            filestodelete=$(($(find ${EXTRACTED}/By_Date/* -type f | wc -l) / dircount))
            iter=0
            for i in */*/*; do
                if [ $iter -ge $filestodelete ]; then
                    break
                fi
                if ! grep -qxFe "$i" "$BS_DISK_EXCLUDE"; then
                    printf '%s\n' "$i"
                fi
                ((iter++))
            done | "${dispose[@]}" > "$BS_STATE/disk_check.out" 2>&1
            log "disk ${used} used (limit ${purge_threshold}%, full disk = ${FULL_DISK}): oldest files: $(head -c 300 "$BS_STATE/disk_check.out" | tr '\n' ' ')(protected clips kept)"
        else
            echo "No By_Date directories found, skipping per-directory purge"
            log "disk ${used} used (limit ${purge_threshold}%): nothing to purge in By_Date"
        fi
        find "$BS_RECS"/ -type d -empty -mtime +90 -delete
        find ${EXTRACTED}/By_Date/ -empty -type d -delete;;

       #rm -drfv "$(find ${EXTRACTED}/By_Date/* -maxdepth 1 -type d -prune \
        # | sort -r | tail -n1)";;
    keep) echo "Stopping Core Services"
       log "disk ${used} used (limit ${purge_threshold}%, full disk = keep): core services stopped"
       /usr/local/bin/stop_core_services.sh;;
  esac
fi
sleep 1
used="$(df -h ${EXTRACTED} | tail -n1 | awk '{print $5}')"
if [ "${used//%}" -ge "$purge_threshold" ]; then
  case $FULL_DISK in
    purge|external) echo "Removing more data"
       log "disk still ${used} used after the purge: processed recordings deleted"
       rm -rfv "${PROCESSED:?}"/*;;  # never /* when PROCESSED is unset
    keep) echo "Stopping Core Services"
       log "disk ${used} used (limit ${purge_threshold}%, full disk = keep): core services stopped"
       /usr/local/bin/stop_core_services.sh;;
  esac
fi
