#!/usr/bin/env bash
################################################################################
# scripts/birdnet_changeidentification.sh - CHANGE OF THE IDENTIFICATION OF A DETECTION
#
# PURPOSE:
#   This scripts allows to change the identification of a Birdnet-pi detection
#
# DEPENDENCIES:
#   - scripts/paths.sh
#   - tests/helpers.py
#
# USAGE:
#   bash scripts/birdnet_changeidentification.sh
#   Referenced by homepage/static/review-player.js, scripts/play.php
################################################################################

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

# the analysis writes the same database: every call waits for its lock up to 10 s (owner 2026-11-06)
SQL() { sqlite3 -bail -cmd '.timeout 10000' "$DB_FILE" "$@"; }

# Get the line where the column "File_Name" matches exactly $OLDNAME (and its confidence: the line is put back as it
# was when the files can not be moved, owner 2026-11-06)
IFS='|' read -r OLDNAME_sciname OLDNAME_date OLDNAME_confidence < <(SQL "SELECT Sci_Name, Date, Confidence FROM $DETECTIONS_TABLE WHERE File_Name = '$OLDNAME_SQL' LIMIT 1;")

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

###################################
# EXECUTE : UPDATE DATABASE FILES #
###################################

# The database first, then the files (owner 2026-11-06, Gitea #69): the clip was moved before an UPDATE that could fail
# (database busy with the analysis, no check), which left the line pointing at a file that no longer existed. Now the
# line is changed only when the clip is there, exactly one line in one transaction (the CHECK fails otherwise and
# sqlite3 rolls it back), and a failed move puts the line back.
FILE_PATH="$BS_BY_DATE/$OLDNAME_date/$OLD_folder/$OLDNAME"
if [[ ! -f $FILE_PATH ]]; then
		# nothing renamed: leave the database as it is (owner 2026-10-22: the line was renamed without its clip)
		echo "Error: File $FILE_PATH does not exist"
		exit 1
fi

# Escape single quotes for SQL by doubling them
NEWNAME_sciname_SQL="${NEWNAME_sciname//\'/\'\'}"
NEWNAME_filename_SQL="${NEWNAME_filename//\'/\'\'}"
OLDNAME_sciname_SQL="${OLDNAME_sciname//\'/\'\'}"
# the old confidence as a number (NULL when the line had none)
[[ "$OLDNAME_confidence" =~ ^-?[0-9]+(\.[0-9]+)?([eE][-+]?[0-9]+)?$ ]] || OLDNAME_confidence="NULL"

# one_row: exactly one line changed, or the transaction is rolled back
db_change() {
		local out
		out="$(SQL "CREATE TEMP TABLE one_row (n INTEGER CHECK (n = 1)); BEGIN IMMEDIATE; $1; INSERT INTO one_row SELECT changes(); COMMIT; SELECT n FROM one_row;" 2>&1)"
		[[ $? -eq 0 && "$out" == "1" ]] || { printf '%s' "$out" | tr '\n' ' '; return 1; }
}

if ! DB_ERR="$(db_change "UPDATE $DETECTIONS_TABLE SET Sci_Name = '$NEWNAME_sciname_SQL', Confidence = '0', File_Name = '$NEWNAME_filename_SQL' WHERE File_Name = '$OLDNAME_SQL'")"; then
		echo "Error: database line of $OLDNAME not changed (nothing was moved): $DB_ERR"
		exit 1
fi
[[ "$OUTPUT_TYPE" == "debug" ]] && echo "Database entry changed"

########################
# EXECUTE : MOVE FILES #
########################

NEW_DIR="$BS_BY_DATE/$OLDNAME_date/$NEWNAME_sciname_safe"
MOVED=()
move_failed() {
		# the files already moved go back, then the line (owner 2026-11-06)
		local f
		for f in "${MOVED[@]}"; do mv -f "$NEW_DIR/$NEWNAME_filename${f#"$FILE_PATH"}" "$f" 2>/dev/null; done
		if ! DB_ERR="$(db_change "UPDATE $DETECTIONS_TABLE SET Sci_Name = '$OLDNAME_sciname_SQL', Confidence = $OLDNAME_confidence, File_Name = '$OLDNAME_SQL' WHERE File_Name = '$NEWNAME_filename_SQL'")"; then
				echo "Error: $1, and the database line could not be put back: $DB_ERR"
		else
				echo "Error: $1 (nothing was changed)"
		fi
		exit 1
}

# Ensure the new directory exists
mkdir -p "$NEW_DIR" || move_failed "folder $NEW_DIR not created"

# Move and rename the file (and its spectrograms)
mv "$FILE_PATH" "$NEW_DIR/$NEWNAME_filename" || move_failed "file $FILE_PATH not moved"
MOVED+=("$FILE_PATH")
for ext in png jpg; do
		if [[ -f "$FILE_PATH.$ext" ]]; then
				mv "$FILE_PATH.$ext" "$NEW_DIR/$NEWNAME_filename.$ext" || move_failed "file $FILE_PATH.$ext not moved"
				MOVED+=("$FILE_PATH.$ext")
		fi
done

[[ "$OUTPUT_TYPE" == "debug" ]] && echo "Files moved!"

[[ "$OUTPUT_TYPE" == "debug" ]] && echo "All done!"
exit 0
