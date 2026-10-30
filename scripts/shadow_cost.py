#!/usr/bin/env python3
"""What the shadow model costs (owner 2026-10-25: the price of privacy with BirdNET+ V3, for the README).

Reads the analysis service's log of the last hours: for every recording, the time of the official model ("DONE! Time
X SECONDS") and of the shadow model ("SHADOW DONE! ... time Y SECONDS"), and prints the average of each, the share of
each recording's length the Pi spends on them, and how much the shadow model adds. Run it on the station after the
shadow model has been on for a while:  python3 ~/BirdNET-Pi/scripts/shadow_cost.py [hours, default 2]
"""
import os
import re
import subprocess
import sys

hours = float(sys.argv[1]) if len(sys.argv) > 1 else 2.0
length = 15.0
try:
    for line in open(os.path.join(os.path.dirname(os.path.dirname(os.path.abspath(__file__))), 'config', 'BirdSuite.conf'), encoding='utf-8'):
        if line.startswith('RECORDING_LENGTH='):
            length = float(line.split('=', 1)[1].strip().strip('"') or 15)
except (OSError, ValueError):
    pass
log = subprocess.run(['journalctl', '-u', 'birdnet_analysis', '--since', f'-{int(hours * 60)}min', '-o', 'cat', '--no-pager'],
                     capture_output=True, text=True).stdout
official = [float(m) for m in re.findall(r'(?<!SHADOW )DONE! Time ([0-9.]+) SECONDS', log)]
shadow = [float(m) for m in re.findall(r'SHADOW DONE! \d+ detections, time ([0-9.]+) SECONDS', log)]
if not official:
    sys.exit('No analysis in the log of the last %g h (journalctl -u birdnet_analysis); is the service running?' % hours)
o = sum(official) / len(official)
print('Recordings analysed in the last %g h: %d (each %g s long)' % (hours, len(official), length))
print('Official model: %.2f s per recording = %.0f%% of the recording time' % (o, 100 * o / length))
if not shadow:
    print('Shadow model: not running (Settings > Model > Shadow mode off, or no shadow model chosen)')
else:
    s = sum(shadow) / len(shadow)
    print('Shadow model:   %.2f s per recording = %.0f%% of the recording time (%d recordings)' % (s, 100 * s / length, len(shadow)))
    print('Together:       %.2f s per recording = %.0f%% of the recording time' % (o + s, 100 * (o + s) / length))
    print('The shadow model adds %.0f%% to the analysis work' % (100 * s / o))
    if o + s > length * 0.9:
        print('WARNING: the analysis takes about as long as the recordings: it will fall behind (backlog in StreamData)')
try:
    load = open('/proc/loadavg').read().split()[:3]
    print('Load average now (1, 5, 15 min): %s, %s, %s  on %d CPU core(s)' % (load[0], load[1], load[2], os.cpu_count() or 1))
except OSError:
    pass
