#!/usr/bin/env bash
################################################################################
# scripts/birdnet_recording.sh - THE RECORDING SERVICE
#
# PURPOSE:
#   Performs the recording from the specified RTSP stream or soundcard
#
# DEPENDENCIES:
#   - scripts/paths.sh
#   - scripts/install_audio.sh
#   - scripts/utils/reporting.py
#
# USAGE:
#   bash scripts/birdnet_recording.sh
#   Referenced by scripts/recording_watchdog.py, scripts/utils/reporting.py,
#   templates/units/SVC_RECORDING.service
################################################################################

source "$(dirname "$(readlink -f "${BASH_SOURCE[0]}")")/paths.sh"
loop_ffmpeg(){
	while true;do
		if ! ffmpeg -hide_banner -loglevel $LOGGING_LEVEL -nostdin ${1} -i ${2} -vn -map a:0 -acodec $([ "${REC_BITS:-24}" = 24 ] && echo pcm_s24le || echo pcm_s16le) -ac 2 -ar 48000 -f segment -segment_format wav -segment_time ${RECORDING_LENGTH} -strftime 1 ${RECS_DIR}/StreamData/%F_%Hh%Mm%Ss-RTSP_${3}-birdnet.wav
		then
			sleep 1
		fi
	done
}

# the shared microphone device missing from the station user's ALSA file (2026-11-03: the pilot's ~/.asoundrc was gone
# after an update and the recording failed every 3 s with "Unknown PCM birdnet_mic"): written again by install_audio.sh
if [ "${REC_CARD:-}" = birdnet_mic ] && [ -z "${RTSP_STREAM:-}" ] && ! grep -qs "pcm.birdnet_mic" "$BS_ASOUNDRC"; then
	echo "the microphone device birdnet_mic is missing from $BS_ASOUNDRC: install_audio.sh writes it again"
	"$BS_SCRIPTS/install_audio.sh" || true
fi

# Read the logging level from the configuration option
LOGGING_LEVEL="${LogLevel_BirdnetRecordingService}"
# If empty for some reason default to log level of error
[ -z $LOGGING_LEVEL ] && LOGGING_LEVEL='error'
# Additionally if we're at debug or info level then allow printing of script commands and variables
if [ "$LOGGING_LEVEL" == "info" ] || [ "$LOGGING_LEVEL" == "debug" ];then
	# Enable printing of commands/variables etc to terminal for debugging
	set -x
fi

[ -z $RECORDING_LENGTH ] && RECORDING_LENGTH=15
[ -d $RECS_DIR/StreamData ] || mkdir -p $RECS_DIR/StreamData

if [ -n "${RTSP_STREAM}" ];then
	# Explode the RTSP steam setting into an array so we can count the number we have
	RTSP_STREAMS_EXPLODED_ARRAY=(${RTSP_STREAM//,/ })
	FFMPEG_VERSION=$(ffmpeg -version | head -n 1 | cut -d ' ' -f 3 | cut -d '.' -f 1)

	STREAM_COUNT=1
	# Loop over the streams
	for i in "${RTSP_STREAMS_EXPLODED_ARRAY[@]}"
	do
		if [[ "$i" =~ ^rtsps?:// ]]; then
			[ $FFMPEG_VERSION -lt 5 ] && PARAM=-stimeout || PARAM=-timeout
			TIMEOUT_PARAM="$PARAM 10000000"
		elif [[ "$i" =~ ^[a-z]+:// ]]; then
			TIMEOUT_PARAM="-rw_timeout 10000000"
		else
			TIMEOUT_PARAM=""
		fi
		loop_ffmpeg "${TIMEOUT_PARAM}" "${i}" "${STREAM_COUNT}" &
		((STREAM_COUNT += 1))
	done
	wait
else
	# PulseAudio only for the PulseAudio route; an ALSA device (e.g. the shared USB microphone
	# set up by install_audio.sh) must not be grabbed by a daemon started here
	if [ "${REC_CARD}" = "default" ] || [ -z "${REC_CARD}" ];then
		if ! pulseaudio --check;then pulseaudio --start;fi
	fi
	# only the station's own recorder counts (2026-11-03: a Raw Recording session's arecord made this exit at once after a
	# microphone replug, and the station recorded nothing while the session ran)
	if pgrep -u "$(id -u)" -f -- "--use-strftime ${RECS_DIR}/StreamData/" &> /dev/null ;then
		echo "Recording"
	else
		# the audio the recorder loses (owner 2026-11-04): arecord says "overrun!!! (at least N ms long)" when the sound
		# card's buffer was not read in time; each one goes, with its time, to state/audio_loss.log, and every detection
		# keeps the losses of its own recording (utils/reporting.py: Audio_Loss_N, Audio_Loss_Ms); the rest of arecord's
		# messages go to the journal as before
		# the sample depth (owner 2026-11-04, REC_BITS): 24 bits keeps the whole range of the microphone; the plug device
		# converts for a card that gives 16 bits only; the analysis reads any depth (librosa, as floats)
		rec_fmt=S16_LE; [ "${REC_BITS:-24}" = 24 ] && rec_fmt=S24_3LE
		loss_log=$BS_STATE/audio_loss.log
		# the log exists from this recorder's start: an empty one says "no loss", no log says "not known"
		mkdir -p "$BS_STATE"; : >> "$loss_log"
		mark_loss() {
			local l
			while IFS= read -r l; do
				case "$l" in
					*overrun*) printf '%s %s\n' "$(date +%s.%N)" "$(printf '%s' "$l" | sed -n 's/.*at least \([0-9.]*\) ms.*/\1/p')" >> "$loss_log" ;;
					*) printf '%s\n' "$l" >&2 ;;
				esac
			done
		}
		if [ -z ${REC_CARD} ];then
			arecord -f ${rec_fmt} -c${CHANNELS} -r48000 -t wav --max-file-time ${RECORDING_LENGTH}\
												 --use-strftime ${RECS_DIR}/StreamData/%F_%Hh%Mm%Ss-birdnet.wav 2> >(mark_loss)
		else
			arecord -f ${rec_fmt} -c${CHANNELS} -r48000 -t wav --max-file-time ${RECORDING_LENGTH}\
				-D "${REC_CARD}" --use-strftime ${RECS_DIR}/StreamData/%F_%Hh%Mm%Ss-birdnet.wav 2> >(mark_loss)
		fi
	fi
fi
