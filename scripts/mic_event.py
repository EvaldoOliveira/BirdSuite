#!/usr/bin/env python3
################################################################################
# scripts/mic_event.py - THE MICROPHONES BY NAME, FOR THE HOT-PLUG WINDOW
#
# PURPOSE:
#   The microphones by name, for the hot-plug window (2026-11-03, owner: "the window names the microphone, or
#   both when two were unplugged, and what used it"). Run by mic_hotplug.sh as the station user.
#
# DEPENDENCIES:
#   - scripts/utils/core.py
#   - scripts/mic_hotplug.sh
#   - scripts/raw_recording.sh
#
# USAGE:
#   python3 scripts/mic_event.py <arguments> (see main)
#   Referenced by scripts/location_switch.php, scripts/mic_hotplug.sh
################################################################################

"""The microphones by name, for the hot-plug window (2026-11-03, owner: "the window names the microphone, or both when
two were unplugged, and what used it"). Run by mic_hotplug.sh as the station user.

  mic_event.py before            prints the station microphone's card id now (the shared device's card), for 'after'
  mic_event.py after <before_id> compares the USB capture cards with the last ones seen (state/mics_seen.json) and, when
                                 one went or came, writes state/mic_event.json for the window of every page
                                 (scripts/location_switch.php ?mic): removed / added, each with its name and what used
                                 it (the station's analysis, a Raw Recording session), and the microphone the station
                                 records with now; an event not yet seen is merged (two unplugged one after the other show
                                 together). Prints a one-line summary for the notification.
"""
import glob
import json
import os
import re
import subprocess
import sys
import time

sys.path.insert(0, os.path.dirname(os.path.realpath(__file__)))
from utils import core  # noqa: E402


def usb_mics():
    """{card id: long name} of the USB cards that can record."""
    out = {}
    names = {}
    try:
        with open('/proc/asound/cards', encoding='utf-8', errors='replace') as f:
            for line in f:
                m = re.match(r'^\s*(\d+) \[[^]]*\]: [^ ]+ - (.+)$', line)
                if m:
                    names[m.group(1)] = m.group(2).strip()
    except OSError:
        pass
    for usbid in glob.glob('/proc/asound/card*/usbid'):
        d = os.path.dirname(usbid)
        if not any(open(s, errors='replace').read().find('Capture:') >= 0 for s in glob.glob(d + '/stream*')):
            continue
        try:
            cid = open(d + '/id').read().strip()
        except OSError:
            continue
        out[cid] = names.get(d.rsplit('card', 1)[1], cid)
    return out


def station_card():
    try:
        m = re.search(r'hw:CARD=([A-Za-z0-9_]+)', open(core.BS_ASOUNDRC).read())
        return m.group(1) if m else ''
    except OSError:
        return ''


def raw_cards():
    """the card ids that a Raw Recording session is recording now (raw_recording.sh <id>)."""
    try:
        out = subprocess.run(['pgrep', '-u', str(os.getuid()), '-af', 'raw_recording.sh'], capture_output=True, text=True).stdout
    except OSError:
        return set()
    return {m.group(1) for m in re.finditer(r'raw_recording\.sh (\S+)', out)}


def load(path, default):
    try:
        with open(path, encoding='utf-8') as f:
            return json.load(f)
    except (OSError, ValueError):
        return default


def save(path, data):
    tmp = path + '.tmp'
    with open(tmp, 'w', encoding='utf-8') as f:
        json.dump(data, f, ensure_ascii=False)
    os.chmod(tmp, 0o664)  # the pages (the web server's user, in the station user's group) delete it when it is seen
    os.replace(tmp, path)


def main(argv):
    if argv[1:2] == ['before']:
        print(station_card())
        return 0
    if argv[1:2] != ['after'] or len(argv) != 3:
        print(__doc__.strip(), file=sys.stderr)
        return 2
    before = argv[2]
    now = usb_mics()
    seen = load(core.BS_MIC_SEEN, None)
    save(core.BS_MIC_SEEN, now)
    if not isinstance(seen, dict):
        return 0  # the first look: nothing to compare with
    raw = raw_cards()
    station = station_card() if now else ''
    removed = [{'id': k, 'name': v, 'station': k == before, 'raw': k in raw} for k, v in seen.items() if k not in now]
    added = [{'id': k, 'name': v, 'station': k == station and station != before} for k, v in now.items() if k not in seen]
    if not removed and not added:
        return 0
    ev = load(core.BS_MIC_EVENT, {})
    if isinstance(ev, dict) and ev.get('ts') and time.time() - int(ev['ts']) < 86400:
        # not seen yet: merged, a microphone that went and came back counts once as reconnected
        old_r = {x['id']: x for x in ev.get('removed', [])}
        old_a = {x['id']: x for x in ev.get('added', [])}
        for x in removed:
            if x['id'] in old_a:
                del old_a[x['id']]
            else:
                old_r[x['id']] = x
        for x in added:
            if x['id'] in old_r:
                x['back'] = True
                del old_r[x['id']]
            old_a[x['id']] = x
        removed, added = list(old_r.values()), list(old_a.values())
    name_now = now.get(station, '') if station else ''
    save(core.BS_MIC_EVENT, {'ts': int(time.time()), 'at': time.strftime('%Y-%m-%d %H:%M:%S'), 'removed': removed,
                             'added': added, 'recording': name_now, 'raw_waiting': [x['name'] for x in removed if x['raw']]})
    parts = []
    if removed:
        parts.append('microphone removed: ' + ', '.join(x['name'] for x in removed))
    if added:
        parts.append('microphone connected: ' + ', '.join(x['name'] for x in added))
    parts.append('the station records with ' + name_now if name_now else 'the station records nothing (no microphone)')
    print('USB ' + '; '.join(parts))
    return 0


if __name__ == '__main__':
    sys.exit(main(sys.argv))
