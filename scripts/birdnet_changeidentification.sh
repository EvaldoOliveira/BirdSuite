#!/usr/bin/env bash

# This scripts allows to change the identification of a Birdnet-pi detection

#################
# SET VARIABLES #
#################

# shellcheck disable=SC1091
source "$(dirname "$(readlink -f "${BASH_SOURCE[0]}")")/paths.sh"
# Get arguments
OLDNAME="$1" #OLDNAME="Mésange_charbonnière-78-2024-05-02-birdnet-RTSP_1-18:14:08.mp3"
NEWNAME="$2" #NEWNAME="Lapinus atricapilla" (a labels.txt line "Lapinus atricapilla_Lapinu à tête noire" works too)

# Set log level
OUTPUT_TYPE="${3:-debug}" # Set 3rd argument to debug to have all outputs

# Ask for user input if no arguments
if [ -z "$OLDNAME" ]; then read -r -p 'OLDNAME (finishing by file extension): ' OLDNAME; fi
if [ -z "$NEWNAME" ]; then read -r -p 'NEWNAME (scientific name): ' NEWNAME; fi

# Fixed values
LABELS_FILE="$BS_MODEL/labels.txt"
DB_FILE="$BS_DB"
DETECTIONS_TABLE="detections"

###################
# VALIDITY CHECKS #
###################

# Check if files exist
if [ ! -f "$LABELS_FILE" ]; then echo "$LABELS_FILE doesn't exist, exiting" && exit 1; fi
if [ ! -f "$DB_FILE" ]; then echo "$DB_FILE doesn't exist, exiting" && exit 1; fi

# Check if inputs are valid
if [[ "$OLDNAME" != *"."* ]]; then
  echo "The first argument should be a filename finishing by the file extension!"
  echo "Instead, it is : $OLDNAME"
  exit 1
fi

# the species is its scientific name only (owner 2026-11-05, one index only): the part before the '_' of a
# "scientific name_common name" label line, the common name is never stored
NEWNAME_sciname="${NEWNAME%%_*}"

# Check if the species is a label of the model (the part before the '_' of a $LABELS_FILE line)
if ! cut -d'_' -f1 "$LABELS_FILE" | grep -qxF -- "$NEWNAME_sciname"; then
    echo "Error: $NEWNAME_sciname not found in $LABELS_FILE"
    exit 1
fi

##################
# EXECUTE SCRIPT #
##################

# Intro
[[ "$OUTPUT_TYPE" == "debug" ]] && echo "Starting to modify $OLDNAME to $NEWNAME"

# Escape single quotes for SQL by doubling them
OLDNAME_SQL="${OLDNAME//\'/\'\'}"

# Get the line where the column "File_Name" matches exactly $OLDNAME
IFS='|' read -r OLDNAME_sciname OLDNAME_date < <(sqlite3 "$DB_FILE" "SELECT Sci_Name, Date FROM $DETECTIONS_TABLE WHERE File_Name = '$OLDNAME_SQL' LIMIT 1;")

if [[ -z "$OLDNAME_sciname" ]]; then
    echo "Error: No line matching $OLDNAME in $DB_FILE"
    exit 1
fi

# Check if the species is the same as before
if [[ "$OLDNAME_sciname" == "$NEWNAME_sciname" ]]; then
    echo "Error: $OLDNAME is already $NEWNAME_sciname"
    exit 1
fi

# Replace spaces with underscores, and ' with "" (same logic as helpers.py name_safe)
safe() { printf '%s' "$1" | tr -d "'" | tr ' ' '_'; }
NEWNAME_sciname_safe="$(safe "$NEWNAME_sciname")"
OLDNAME_sciname_safe="$(safe "$OLDNAME_sciname")"

# the clip's folder (helpers.py species_folder): <date>/<Sci>/<Sci>-...; the renamed clip takes the scientific name:
# <NewSci> + the old name from its date on (helpers.py clip_rest)
OLD_folder="$OLDNAME_sciname_safe"
OLD_rest="$(printf '%s' "$OLDNAME" | grep -oE -- '-([0-9]{1,3}-)?[0-9]{4}-[0-9]{2}-[0-9]{2}[-_].*$' | head -1)"
if [[ -z "$OLD_rest" ]]; then echo "Error: no date in the file name $OLDNAME" && exit 1; fi
NEWNAME_filename="${NEWNAME_sciname_safe}${OLD_rest}"

[[ "$OUTPUT_TYPE" == "debug" ]] && echo "This script will change the identification $OLDNAME from $OLDNAME_sciname to $NEWNAME_sciname"

########################
# EXECUTE : MOVE FILES #
########################

# Check if the file exists
FILE_PATH="$BS_BY_DATE/$OLDNAME_date/$OLD_folder/$OLDNAME"
if [[ -f $FILE_PATH ]]; then
    # Ensure the new directory exists
    NEW_DIR="$BS_BY_DATE/$OLDNAME_date/$NEWNAME_sciname_safe"
    mkdir -p "$NEW_DIR"
    
    # Move and rename the file
    mv "$FILE_PATH" "$NEW_DIR/$NEWNAME_filename"
    for ext in png jpg; do
        [[ -f "$FILE_PATH.$ext" ]] && mv "$FILE_PATH.$ext" "$NEW_DIR/$NEWNAME_filename.$ext"
    done
    
    [[ "$OUTPUT_TYPE" == "debug" ]] && echo "Files moved!"
else
    # nothing renamed: leave the database as it is (owner 2026-10-22: the line was renamed without its clip)
    echo "Error: File $FILE_PATH does not exist"
    exit 1
fi

###################################
# EXECUTE : UPDATE DATABASE FILES #
###################################

# Escape single quotes for SQL by doubling them
NEWNAME_sciname_SQL="${NEWNAME_sciname//\'/\'\'}"
NEWNAME_filename_SQL="${NEWNAME_filename//\'/\'\'}"

# Update the database
sqlite3 "$DB_FILE" "UPDATE $DETECTIONS_TABLE SET Sci_Name = '$NEWNAME_sciname_SQL', Confidence = '0', File_Name = '$NEWNAME_filename_SQL' WHERE File_Name = '$OLDNAME_SQL';"

[[ "$OUTPUT_TYPE" == "debug" ]] && echo "Database entry removed"

[[ "$OUTPUT_TYPE" == "debug" ]] && echo "All done!"
