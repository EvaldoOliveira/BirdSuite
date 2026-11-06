#!/usr/bin/env bash
################################################################################
# tests/mic_gain_test.sh - BIRDSUITE-ROOT MIC-GAIN
#
# PURPOSE:
#   Birdsuite-root mic-gain (US-07): the argument check and the amixer / alsactl calls, with both stubbed (no sound card
#   touched, no root needed). The card is any card of this machine named by its ALSA id, so the stubbed mixer is used.
#   Usage: bash tests/mic_gain_test.sh   (exits 1 on a failure)
#
# DEPENDENCIES:
#   - birdsuite-root (sudo helper)
#
# USAGE:
#   bash tests/mic_gain_test.sh
################################################################################

cd "$(dirname "$(readlink -f "$0")")/.." || exit 2
helper=scripts/birdsuite-root
tmp=$(mktemp -d)
trap 'rm -rf "$tmp"' EXIT
log=$tmp/calls
cat > "$tmp/amixer" << 'EOF'
#!/usr/bin/env bash
echo "amixer $*" >> "$STUB_LOG"
case " $* " in
  *" scontents "*)
    printf "%s\n" "Simple mixer control 'PCM',0" "  Capabilities: pvolume pvolume-joined" "  Playback channels: Mono" \
      "Simple mixer control 'Mic',0" "  Capabilities: cvolume cvolume-joined pswitch" "  Capture channels: Mono" \
      "  Limits: Capture 0 - 24" "  Mono: Playback [on] Capture 12 [50%] [12.00dB]" ;;
  *" sget "*) printf "%s\n" "Simple mixer control 'Mic',0" "  Limits: Capture 0 - 24" "  Mono: Playback [on] Capture 12 [50%] [12.00dB]" ;;
esac
exit 0
EOF
printf '#!/usr/bin/env bash\necho "alsactl $*" >> "$STUB_LOG"\n' > "$tmp/alsactl"
chmod u+x "$tmp/amixer" "$tmp/alsactl"
export STUB_LOG=$log PATH="$tmp:$PATH"
fails=0
check() {  # check NAME EXPECTED_RC EXPECTED_STDOUT ARGS...
	local name=$1 rc=$2 want=$3 out got; shift 3
	out=$(bash "$helper" mic-gain "$@" 2>/dev/null); got=$?
	if [ "$got" = "$rc" ] && { [ -z "$want" ] || [ "$out" = "$want" ]; }; then echo "PASS $name"
	else echo "FAIL $name: rc=$got (want $rc) out='$out' (want '$want')"; fails=$((fails + 1)); fi
}
# refused before anything runs (exit 2, the mixer never called)
: > "$log"
for bad in 101 -1 050 1e2 "5%" "a b" "x;id" '$(id)' "1a" "-c" "Mic 50 1"; do
	# shellcheck disable=SC2086
	check "refused: $bad" 2 "" $bad
done
check "refused: three arguments" 2 "" Card 50 7
check "refused: id after the percent" 2 "" 50 Card
[ -s "$log" ] && { echo "FAIL the mixer was called for a refused argument: $(cat "$log")"; fails=$((fails + 1)); } || echo "PASS no mixer call for refused arguments"
# a card id that does not exist: none (reading answers 0, setting 1), nothing set
: > "$log"
check "unknown card: read" 0 none NoSuchCard
check "unknown card: set" 1 none NoSuchCard 40
grep -q sset "$log" && { echo "FAIL a volume was set on an unknown card"; fails=$((fails + 1)); } || echo "PASS nothing set on an unknown card"
# a card of this machine with the stubbed mixer: the Mic control read and set, then kept with alsactl store
card_dir=$(ls -d /proc/asound/card[0-9]* 2>/dev/null | head -1)
if [ -n "$card_dir" ]; then
	n=${card_dir##*card}; id=$(cat "$card_dir/id")
	: > "$log"
	check "read" 0 "card=$n control=Mic percent=50 db=12.00" "$id"
	check "set" 0 "card=$n control=Mic percent=50 db=12.00" "$id" 0
	grep -qxF "amixer -q -c $n sset Mic,0 capture 0%" "$log" && grep -qxF "alsactl store $n" "$log" \
		&& echo "PASS set = amixer sset capture + alsactl store" || { echo "FAIL calls: $(cat "$log")"; fails=$((fails + 1)); }
	check "set 100" 0 "" "$id" 100
else
	echo "SKIP the read / set checks: this machine has no sound card"
fi
[ "$fails" -eq 0 ] && echo "ALL PASSED" || { echo "$fails FAILED"; exit 1; }
