#!/usr/bin/env bash
# Factory settings (owner 2026-10-24, Tools › Backup/Restore › Danger zone): the settings go back to the factory
# defaults, after a settings backup (backup_data.sh -m settings) saved in ~/BirdSuite-backups/settings-<time>.tar, which
# Tools › Backup/Restore offers to download and Restore settings takes back. Every key of config/factory_defaults.conf
# takes its default value; the station's own keys stay (name, coordinates, passwords, paths, Common name Language,
# update branch), as do the detections, the recordings, the locations, the species lists and the notifications.
source "$(dirname "$(readlink -f "${BASH_SOURCE[0]}")")/paths.sh"
# as the station user (the page runs it as the web server's user)
if [ "$(id -un)" != "$BIRDNET_USER" ]; then exec sudo -n -u "$BIRDNET_USER" "$(readlink -f "$0")" "$@"; fi
set -e
my_dir=$BS_HOME/scripts
dir=/home/$BIRDNET_USER/BirdSuite-backups
mkdir -p "$dir"
f=$dir/settings-$(date +%Y%m%dT%H%M%S).tar
echo "Saving the settings to $f"
"$my_dir/backup_data.sh" -a backup -m settings -f "$f"
echo "Setting the factory defaults"
python3 - "$BS_DEFAULTS" "$BS_CONF" << 'PY'
import re, sys
defaults, conf = sys.argv[1], sys.argv[2]
key = re.compile(r'^([A-Za-z_][A-Za-z0-9_]*)=')
vals = {}
for line in open(defaults, encoding='utf-8'):
    m = key.match(line)
    if m:
        vals[m.group(1)] = line.rstrip('\n')
out, seen = [], set()
for line in open(conf, encoding='utf-8'):
    m = key.match(line)
    if m and m.group(1) in vals:
        out.append(vals[m.group(1)] + '\n')
        seen.add(m.group(1))
    else:
        out.append(line)
out += [v + '\n' for k, v in vals.items() if k not in seen]
open(conf + '.tmp', 'w', encoding='utf-8').writelines(out)
PY
mv "$BS_CONF.tmp" "$BS_CONF"
echo "Restarting services"
"$my_dir/restart_services.sh" > /dev/null 2>&1 || true
echo "Factory settings done. The former settings: $f"
