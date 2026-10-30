#!/usr/bin/env bash
# This script removes all data that has been collected. It is tantamount to
# starting all data-collection from scratch. Only run this if you are sure
# you are okay will losing all the data that you've collected and processed
# so far.
set -x
source "$(dirname "$(readlink -f "${BASH_SOURCE[0]}")")/paths.sh"
USER=${BIRDNET_USER}
HOME=/home/${BIRDNET_USER}
my_dir=${HOME}/BirdNET-Pi/scripts
# as the station user: directly when this runs as that user (the pages, least privilege), through sudo -u when root
asu() { if [ "$(id -un)" = "$USER" ]; then "$@"; else sudo -u "$USER" "$@"; fi; }
echo "Stopping services"
sudo -n /usr/local/sbin/birdsuite-svc stop birdnet_recording.service
sudo -n /usr/local/sbin/birdsuite-svc stop birdnet_analysis.service
echo "Removing all data . . . "
rm -drf "${RECS_DIR:?}"
rm -f "${IDFILE}"
rm -f "$BS_DATA/BirdDB.txt"

echo "Re-creating necessary directories"
[ -d ${EXTRACTED} ] || asu mkdir -p ${EXTRACTED}
[ -d ${EXTRACTED}/By_Date ] || asu mkdir -p ${EXTRACTED}/By_Date
[ -d ${EXTRACTED}/Charts ] || asu mkdir -p ${EXTRACTED}/Charts
[ -d ${PROCESSED} ] || asu mkdir -p ${PROCESSED}

asu ln -fs $(dirname $my_dir)/homepage/* ${EXTRACTED}
asu ln -fs $(dirname $my_dir)/model/labels.txt ${my_dir}
asu ln -fs $my_dir ${EXTRACTED}
asu ln -fs $my_dir/play.php ${EXTRACTED}
asu ln -fs $my_dir/spectrogram.php ${EXTRACTED}
asu ln -fs $my_dir/overview.php ${EXTRACTED}
asu ln -fs $my_dir/stats.php ${EXTRACTED}
asu ln -fs $my_dir/todays_detections.php ${EXTRACTED}
asu ln -fs $my_dir/homepage/images/favicon.ico ${EXTRACTED}
asu ln -fs ${HOME}/phpsysinfo ${EXTRACTED}
asu ln -fs $(dirname $my_dir)/templates/phpsysinfo.ini ${HOME}/phpsysinfo/
asu ln -fs $(dirname $my_dir)/templates/green_bootstrap.css ${HOME}/phpsysinfo/templates/
asu ln -fs $(dirname $my_dir)/templates/index_bootstrap.html ${HOME}/phpsysinfo/templates/html
chmod -R g+rw $my_dir
chmod -R g+rw ${RECS_DIR}


# every table goes (detections, reviews, removed ones): the database file is created anew; the settings stay
# (owner 2026-10-24: Clear ALL data = the sounds and the database; Factory settings is its own button)
echo "Dropping and re-creating database"
asu rm -f "$BS_DB" "$BS_DB-wal" "$BS_DB-shm" "$BS_DB.pre-names"
asu "$my_dir/createdb.sh"
echo "Re-generating BirdDB.txt"
echo "Date;Time;Sci_Name;Com_Name;Confidence;Lat;Lon;Cutoff;Week;Sens;Overlap" > "$BS_DATA/BirdDB.txt"
chown $USER:$USER "$BS_DATA/BirdDB.txt" && chmod g+rw "$BS_DATA/BirdDB.txt"
echo "Restarting services"
restart_services.sh
