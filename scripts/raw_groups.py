#!/usr/bin/env python3
"""The raw recording groups (Tools › Raw Recording): one group of settings per microphone, by its ALSA card id (stable
across replugs), in config/raw_recording.json (BS_RAW_REC_FILE). A microphone without a saved group gets the defaults
of BirdSuite.conf (RAW_REC_*, Settings › Defaults).

  raw_groups.py get <id>       the group as shell assignments (RR_ENABLED, RR_DAYS, RR_RECURRENT, RR_START, RR_END,
                               RR_SEGMENT, RR_RATE, RR_DIR), every value checked
  raw_groups.py cron           the crontab lines of the groups switched on with at least one day
  raw_groups.py disable <id>   switches a group off (a one-time schedule after its session)
  raw_groups.py dirs           the folders of every group and the default one, one per line
"""
import json
import os
import re
import shlex
import sys

sys.path.insert(0, os.path.dirname(os.path.realpath(__file__)))
from utils import core

ID = re.compile(r'^[A-Za-z_][A-Za-z0-9_]{0,31}$')
CHECKS = {
    'enabled': (r'^[01]$', '0'),
    'days': (r'^[0-6](,[0-6])*$|^$', ''),
    'recurrent': (r'^[01]$', '0'),
    'start': (r'^([01]\d|2[0-3]):[0-5]\d:[0-5]\d$', '04:00:00'),
    'end': (r'^([01]\d|2[0-3]):[0-5]\d:[0-5]\d$', '10:00:00'),
    'segment': (r'^([1-9]\d?|1\d\d|2[0-3]\d|240)$', '30'),
    'rate': (r'^\d{4,6}$', '48000'),
    'dir': (r'^(/[A-Za-z0-9._ -]+)*$', ''),
}
CONF_KEYS = {'enabled': 'RAW_REC_ENABLED', 'days': 'RAW_REC_DAYS', 'recurrent': 'RAW_REC_RECURRENT', 'start': 'RAW_REC_START',
             'end': 'RAW_REC_END', 'segment': 'RAW_REC_SEGMENT_MIN', 'rate': 'RAW_REC_RATE', 'dir': 'RAW_REC_DIR'}


def conf():
    out = {}
    try:
        with open(core.BS_CONF, encoding='utf-8') as f:
            for line in f:
                m = re.match(r'^([A-Z0-9_]+)=(.*)$', line.strip())
                if m:
                    out[m.group(1)] = m.group(2).strip('"\'')
    except OSError:
        pass
    return out


def load():
    try:
        with open(core.BS_RAW_REC_FILE, encoding='utf-8') as f:
            d = json.load(f)
        return d if isinstance(d, dict) else {}
    except (OSError, ValueError):
        return {}


def group(mic, groups=None, c=None):
    c = conf() if c is None else c
    g = (groups if groups is not None else load()).get(mic) or {}
    out = {}
    for k, (rx, dflt) in CHECKS.items():
        v = str(g.get(k, c.get(CONF_KEYS[k], dflt)))
        out[k] = v if re.match(rx, v) else dflt
    return out


def main(argv):
    cmd = argv[1] if len(argv) > 1 else ''
    if cmd == 'get' and len(argv) == 3 and ID.match(argv[2]):
        for k, v in group(argv[2]).items():
            print(f'RR_{k.upper()}={shlex.quote(v)}')
    elif cmd == 'cron':
        c = conf()
        groups = load()
        for mic in sorted(groups):
            if not ID.match(mic):
                continue
            g = group(mic, groups, c)
            if g['enabled'] == '1' and g['days']:
                hh, mm, _ = g['start'].split(':')
                print(f"{int(mm)} {int(hh)} * * {g['days']} {core.BS_SCRIPTS}/raw_recording.sh {mic} > /dev/null 2>&1 "
                      f"# birdsuite-raw-recording {mic}")
    elif cmd == 'disable' and len(argv) == 3 and ID.match(argv[2]):
        groups = load()
        groups.setdefault(argv[2], {})['enabled'] = '0'
        tmp = core.BS_RAW_REC_FILE + '.tmp'
        with open(tmp, 'w', encoding='utf-8') as f:
            json.dump(groups, f, indent=2, sort_keys=True)
            f.write('\n')
        os.chmod(tmp, 0o664)  # the pages (the web server's user, in the station user's group) write it too
        os.replace(tmp, core.BS_RAW_REC_FILE)
    elif cmd == 'dirs':
        c = conf()
        seen = []
        for mic in load():
            if ID.match(mic):
                d = group(mic, None, c)['dir'] or core.BS_RAW_REC
                if d not in seen:
                    seen.append(d)
        dflt = c.get('RAW_REC_DIR', '') or core.BS_RAW_REC
        for d in [core.BS_RAW_REC, dflt, *seen]:
            if d not in seen:
                seen.append(d)
        print('\n'.join(seen))
    else:
        print(__doc__.strip(), file=sys.stderr)
        return 2
    return 0


if __name__ == '__main__':
    sys.exit(main(sys.argv))
