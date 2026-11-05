#!/usr/bin/env bash
source "$(dirname "$(readlink -f "${BASH_SOURCE[0]}")")/core.sh"
OLDIFS=$IFS
IFS=\|
most_recent_results=($(sqlite3 "$BS_DB" \
  'SELECT Sci_Name, Time, Date FROM detections
   ORDER BY Date DESC, Time DESC
   LIMIT 1'))
# the database keeps the scientific name only: the common name is looked up (utils/helpers.py common_name)
most_recent_results[0]=$(cd "$BS_SCRIPTS" && "$BS_PYTHON" -c 'import sys; from utils.helpers import common_name; print(common_name(sys.argv[1]))' "${most_recent_results[0]}" 2>/dev/null || echo "${most_recent_results[0]}")
today=$(date +%F)
yesterday=$(date --date="yesterday" +%F)
two_days_ago=$(date --date="2 days ago" +%F)

echo -n The most recent detection was

if [[ "${most_recent_results[0]}" =~ ^[AEIOU].* ]];then
  echo -n " an ${most_recent_results[0]} at "
else
  echo -n " a ${most_recent_results[0]} at "
fi
most_recent_results[1]=$(date --date="${most_recent_results[1]}" +%l:%M%p)
echo -n ${most_recent_results[1]}
if [[ ${most_recent_results[2]} == $today ]];then
  echo " today."
elif [[ ${most_recent_results[2]} == $yesterday ]];then
  echo " yesterday."
elif [[ ${most_recent_results[2]} == $two_days_ago ]];then
  echo " two days ago."
else
  echo " on ${most_recent_results[2]}."
fi

IFS=$OLDIFS
