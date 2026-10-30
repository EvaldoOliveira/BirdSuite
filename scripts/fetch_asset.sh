#!/usr/bin/env bash
# Copies one file or directory of the birdsuite-assets repository (tflite wheels, model files) to a
# local directory. The repository is found beside the program's own: birdsuite-assets on the same
# server and owner as the origin of ~/BirdNET-Pi (owner 2026-10-23: wherever the program was
# installed from); BIRDNET_ASSETS_REPO
# overrides the address.
# Usage: fetch_asset.sh <path-in-repo> <destination-dir>
set -e
prog=$(cd "$(dirname "$(readlink -f "$0")")/.." && pwd)
origin=$(git -c safe.directory="$prog" -C "$prog" remote get-url origin 2> /dev/null || true)
repo=${BIRDNET_ASSETS_REPO:-${origin:+${origin%/*}/birdsuite-assets.git}}
# a station installed while the repositories were private (deploy keys, one per repository) has the ssh host alias
# github-birdsuite as origin; its assets come through github-birdsuite-assets
case "$origin" in
  github-birdsuite:*) [ -z "${BIRDNET_ASSETS_REPO:-}" ] && repo="github-birdsuite-assets:${origin#github-birdsuite:}" && repo="${repo%/*}/birdsuite-assets.git" ;;
esac
repo=${repo:-https://github.com/EvaldoOliveira/birdsuite-assets.git}
src=${1:?path in the assets repository}
dest=${2:?destination directory}

tmp=$(mktemp -d)
trap 'rm -rf "$tmp"' EXIT
# shallow + sparse: only the requested path is downloaded, not every wheel and model
git clone -q --depth 1 --filter=blob:none --sparse "$repo" "$tmp/assets"
git -C "$tmp/assets" sparse-checkout set --no-cone "/$src"
mkdir -p "$dest"
if [ -d "$tmp/assets/$src" ]; then
  cp -a "$tmp/assets/$src/." "$dest/"
elif [ -f "$tmp/assets/$src" ]; then
  cp -a "$tmp/assets/$src" "$dest/"
else
  echo "fetch_asset: $src not found in $repo" >&2
  exit 1
fi
