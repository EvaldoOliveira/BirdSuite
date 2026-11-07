#!/usr/bin/env bash
################################################################################
# scripts/spectrogram.sh - OVERVIEW SPECTROGRAM OF THE RECORDING BEING ANALYSED
#
# PURPOSE:
#   Overview spectrogram of the recording being analysed (scripts/spectrogram_png.py, sox as fallback)
#
# DEPENDENCIES:
#   - scripts/spectrogram_png.py
#   - scripts/paths.sh
#
# USAGE:
#   bash scripts/spectrogram.sh
#   Referenced by scripts/spectrogram.php, scripts/utils/reporting.py, templates/units/SVC_SPECTROGRAM.service
################################################################################

source "$(dirname "$(readlink -f "${BASH_SOURCE[0]}")")/paths.sh"
# Read the logging level from the configuration option
LOGGING_LEVEL="${LogLevel_SpectrogramViewerService}"
# If empty for some reason default to log level of error
[ -z $LOGGING_LEVEL ] && LOGGING_LEVEL='error'
# Additionally if we're at debug or info level then allow printing of script commands and variables
if [ "$LOGGING_LEVEL" == "info" ] || [ "$LOGGING_LEVEL" == "debug" ];then
	# Enable printing of commands/variables etc to terminal for debugging
	set -x
fi

next=0
looptime=$(( RECORDING_LENGTH * 2 / 3 ))

# US-41: SPECTROGRAM_PALETTE (BirdSuite.conf) -> sox spectrogram options. The
# live canvas draws its own gradients; these are the closest sox equivalents.
case "${SPECTROGRAM_PALETTE:-turbo}" in
	viridis)   PALETTE_OPTS="-h -p 5" ;;
	ocean)     PALETTE_OPTS="-h -p 2" ;;
	grayscale) PALETTE_OPTS="-m" ;;
	*)         PALETTE_OPTS="" ;;   # birdnet / inferno / soxheat / turbo / roseus = sox default colours
esac
# US-42: colour sensitivity — floor and range (dB) become the sox Z-axis
# (-Z = top of the scale in dBFS, -z = range below it). Contrast has no sox
# equivalent (canvas only).
_floor=${SPECTROGRAM_FLOOR_DB:--95}; _range=${SPECTROGRAM_RANGE_DB:-50}
[[ "$_floor" =~ ^-?[0-9]+$ ]] || _floor=-95
[[ "$_range" =~ ^[0-9]+$ ]] || _range=50
(( _floor < -120 )) && _floor=-120; (( _floor > -40 )) && _floor=-40
(( _range < 30 )) && _range=30; (( _range > 120 )) && _range=120
_top=$(( _floor + _range )); (( _top > 0 )) && _top=0
PALETTE_OPTS="$PALETTE_OPTS -Z $_top -z $_range"

touch "$BS_ANALYZING_NOW"
# Continuously loop generating a spectrogram
inotifywait -m -e close_write "$BS_ANALYZING_NOW" |
while read; do
	now=$(date +%s)
	if (( now > next )); then
		analyzing_now="$(<"$BS_ANALYZING_NOW")"

		if [ -n "${analyzing_now}" ] && [ -f "${analyzing_now}" ]; then
			spectrogram_png=${EXTRACTED}/spectrogram.png
				raw_opt=""; [ "$RAW_SPECTROGRAM" == "1" ] && raw_opt="-r"
				# drawn with the live Spectrogram page's palette, floor, range and contrast; sox stays the fallback
				# each drawing has a time limit (owner 2026-11-06): a stuck drawing never freezes this loop
				# a failure of the drawing tool is said in the journal, its last line (Gitea #80: a crash on every file below
				# 24 kHz went unseen for weeks behind the sox fallback, which draws other colours)
				if ! err=$(timeout 30 "$BS_PYTHON" "$BS_SCRIPTS/spectrogram_png.py" "${analyzing_now}" "${spectrogram_png}" \
						"${analyzing_now//$HOME\//}" ${raw_opt:+--raw} 2>&1 > /dev/null); then
					echo "spectrogram_png.py failed on ${analyzing_now##*/}: ${err##*$'\n'}" >&2
					# drawn beside the picture and moved into place (2026-11-06 second pass S1: a browser could read a half-written
					# file); Extracted/spectrogram.png is a link into StreamData, the file behind it is replaced
					real_png=$(readlink -f "${spectrogram_png}" 2> /dev/null || echo "${spectrogram_png}")
					if timeout 30 sox -V1 "${analyzing_now}" -n remix 1 rate 24k spectrogram $PALETTE_OPTS -c "${analyzing_now//$HOME\//}" -o "${real_png}.tmp.png" $raw_opt; then
						mv -f "${real_png}.tmp.png" "${real_png}"
					else
						rm -f "${real_png}.tmp.png"
						echo "spectrogram not drawn: ${analyzing_now##*/}" >&2
					fi
				fi
		fi
		next=$(( now + looptime ))
	fi
done
