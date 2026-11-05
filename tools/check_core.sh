#!/usr/bin/env bash
# tools/check_core.sh - the folder names, data folder, home paths, venv path and service names live only in
# scripts/core.env and its readers (owner 2026-11-02). Lists every other code line that writes one literally and
# exits 1 when there is any. Usage: bash tools/check_core.sh (from anywhere in the repository)
cd "$(dirname "$(readlink -f "$0")")/.." || exit 2
allowed='^(scripts/core\.env|scripts/core\.sh|scripts/core\.php|scripts/utils/core\.py|tools/check_core\.sh)$'
# not code: documentation, catalogues, licence, issue forms, test data, the station's private folder
skip='^(docs/|custom/|lang/[^/]+/|tests/testdata/|\.github/|LICENSE$)|\.md$'
olds='birdnet_(analysis|recording|log|location_check|mic_hotplug)(?![.](py|sh))|livestream[.]service|spectrogram_viewer|web_terminal|web_files|sound_repo_upload[.](service|timer)'
news='birdsuite_(analysis|recording|log|location_check|livestream|spectrogram|terminal|files|settings_history|field|ble|mic_hotplug|sound_upload)(?![.](py|sh))'
patterns=(
  '[~/]BirdNET-Pi(?![A-Za-z0-9_-])|BirdNET-Pi/|['"'"'"]BirdNET-Pi['"'"'"]'
  '[~/]BirdSuite(?=[/'"'"'")[:space:]]|$)|join\([^)]*['"'"'"]BirdSuite['"'"'"]'
  'BirdSongs'
  '/home/'
  'birdnet/bin/|['"'"'"]birdnet['"'"'"],\s*['"'"'"]bin|venv/bin'
  "$olds|$news"
  "['\"]livestream['\"]"
)
re=$(IFS='|'; echo "${patterns[*]}")
found=0
while IFS= read -r f; do
  [[ $f =~ $allowed ]] && continue
  [[ $f =~ $skip ]] && continue
  [ -f "$f" ] || continue
  # the upstream project and the repositories by their web address are names, not folders
  out=$(grep -nIP -- "$re" "$f" 2>/dev/null | grep -vE 'github\.com|Nachtzuster|mcguirepr89')
  [ -n "$out" ] && { printf '%s\n' "$out" | sed "s|^|$f:|"; found=1; }
done < <(git ls-files -co --exclude-standard)
if [ "$found" = 1 ]; then
  echo "check_core: literal folder, home or service names outside scripts/core.env and its readers (above)" >&2
  exit 1
fi
echo "check_core: clean"
