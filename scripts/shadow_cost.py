#!/usr/bin/env python3
"""What the shadow model costs (owner 2026-10-25: the price of privacy with BirdNET+ V3, for the README).

Reads the analysis service's log of the last hours: for every recording, the time of the official model ("DONE! Time
X SECONDS") and of the shadow model ("SHADOW DONE! ... time Y SECONDS"), and prints the average of each, the share of
each recording's length the Pi spends on them, and how much the shadow model adds. Run it on the station after the
shadow model has been on for a while:  python3 scripts/shadow_cost.py [hours, default 2]
"""
import os
import re
import subprocess
import sys
from pathlib import Path

sys.path.insert(0, os.path.dirname(os.path.realpath(__file__)))
from utils import core

hours = float(sys.argv[1]) if len(sys.argv) > 1 else 2.0
length = 15.0
try:
    for line in Path(core.BS_CONF).read_text(encoding='utf-8').splitlines(keepends=True):
        if line.startswith('RECORDING_LENGTH='):
            length = float(line.split('=', 1)[1].strip().strip('"') or 15)
except (OSError, ValueError):
    pass
log = subprocess.run(['journalctl', '-u', core.BS_SVC_ANALYSIS, '--since', f'-{int(hours * 60)}min', '-o', 'cat', '--no-pager'],
                     capture_output=True, text=True, check=False).stdout
official = [float(m) for m in re.findall(r'(?<!SHADOW )DONE! Time ([0-9.]+) SECONDS', log)]
shadow = [float(m) for m in re.findall(r'SHADOW DONE! \d+ detections, time ([0-9.]+) SECONDS', log)]
if not official:
    sys.exit(f'No analysis in the log of the last {hours:g} h (journalctl -u {core.BS_SVC_ANALYSIS}); is the service running?')
o = sum(official) / len(official)
print(f'Recordings analysed in the last {hours:g} h: {len(official)} (each {length:g} s long)')
print(f'Official model: {o:.2f} s per recording = {100 * o / length:.0f}% of the recording time')
if not shadow:
    print('Shadow model: not running (Settings > Model > Shadow mode off, or no shadow model chosen)')
else:
    s = sum(shadow) / len(shadow)
    print(f'Shadow model:   {s:.2f} s per recording = {100 * s / length:.0f}% of the recording time ({len(shadow)} recordings)')
    print(f'Together:       {o + s:.2f} s per recording = {100 * (o + s) / length:.0f}% of the recording time')
    print('The shadow model adds %.0f%% to the analysis work' % (100 * s / o))
    if o + s > length * 0.9:
        print('WARNING: the analysis takes about as long as the recordings: it will fall behind (backlog in StreamData)')
try:
    load = Path('/proc/loadavg').read_text().split()[:3]
    print(f'Load average now (1, 5, 15 min): {load[0]}, {load[1]}, {load[2]}  on {os.cpu_count() or 1} CPU core(s)')
except OSError:
    pass
