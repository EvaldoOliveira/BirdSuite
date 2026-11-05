#!/usr/bin/env python3
"""Disk size needed in the long term (US-133, owner 2026-11-01): what the station's disk will hold once the purge rules
cap every species, so the owner can choose an SSD or see how much stays empty. Written to state/disk-forecast.json after
the nightly purge (disk_species_clean.sh) and shown on System > Status with its premises.

Premises, all shown on the page as the measured column of a table with two simulated columns (static/disk-sim.js
runs the same simulate() on this file):
- the clips kept per species are capped by the purge (MAX_FILES_SPECIES, or the species' own value in
  species_max_files.txt); 0 = no cap;
- each species keeps clips at the pace it has kept them since it first appeared, for the horizon, up to its cap;
- species not seen yet (other seasons) add SPECIES_MARGIN to the species;
- a clip costs what the kept clips cost today on average (audio + spectrogram);
- the database grows by the detections a day (last 30 days) times the bytes a detection costs today (never purged);
- everything else on the disk (system, programs, other files) stays as it is today;
- the day's clips before the 02:00 purge (the daily swing of disk-usage.log) come on top, following the detections.
Standard library only.
"""
import contextlib
import json
import os
import re
import shutil
import sqlite3
import time

from utils import core  # the folder layout (core.env)

CONF = core.BS_CONF
MAX_LIST = os.path.join(core.BS_LISTS, 'species_max_files.txt')
STATE = core.BS_STATE
OUT = os.path.join(STATE, 'disk-forecast.json')
USAGE_LOG = os.path.join(STATE, 'disk-usage.log')
DB = core.BS_DB
BY_DATE = core.BS_BY_DATE
SPECIES_MARGIN = 0.20      # species of other seasons, not seen yet
SIZES_GB = (32, 64, 128, 256, 512, 1024, 2048)
IMAGE = ('.png', '.jpg', '.jpeg')


def conf_int(key, default):
    try:
        with open(CONF, encoding='utf-8') as fh:
            for line in fh:
                m = re.match(rf'^{key}=["\']?(\d+)', line)
                if m:
                    return int(m.group(1))
    except OSError:
        pass
    return default


def species_caps():
    caps = {}
    try:
        with open(MAX_LIST, encoding='utf-8') as fh:
            for line in fh:
                sci, _, n = line.strip().partition('=')
                if sci and n.strip().isdigit():
                    caps[re.sub(r'\W+', '_', sci.strip())] = int(n)
    except OSError:
        pass
    return caps


def simulate(f, v):
    """The disk needed for a set of inputs v (cap, species, rate, det_day, clip_kb, db_gb, years); the page runs the same
    calculation in static/disk-sim.js for the simulated columns."""
    seen = f['species_seen'] or 1
    rate0 = f['measured']['rate'] or 1
    factor = v['rate'] / rate0
    clips = 0.0
    capped = 0
    for n, rate, own_cap in f['sp']:
        c = v['cap'] if v['cap'] != f['measured']['cap'] else own_cap
        p = rate * factor * 365 * v['years']
        if c > 0 and p >= c:
            p = c
            capped += 1
        clips += max(p, n)
    clips *= v['species'] / seen
    clips_gb = clips * v['clip_kb'] / 1e6
    db_gb = v['db_gb'] + v['det_day'] * 365 * v['years'] * f['det_bytes'] / 1e9
    swing_gb = f['swing_gb'] * (v['det_day'] / f['measured']['det_day'] if f['measured']['det_day'] else 1)
    needed = f['rest_gb'] + db_gb + clips_gb + swing_gb
    limit = v['threshold'] / 100 if 0 < v['threshold'] < 100 else 1
    rec = next((s for s in f['sizes_gb'] if needed <= s * f['usable'] * limit), f['sizes_gb'][-1])
    # the ceiling holds on this disk: above it the purge removes the oldest clips (as static/disk-sim.js)
    ceiling = f['disk_gb'] * limit
    trim = min(max(0.0, needed - ceiling), clips_gb)
    used = needed - trim
    return {'clips_proj': round(clips), 'species_capped': capped, 'clips_gb_proj': round(clips_gb, 1),
            'db_gb_proj': round(db_gb, 1), 'needed_gb': round(needed, 1), 'ceiling_gb': round(ceiling, 1),
            'trim_gb': round(trim, 1), 'used_gb': round(used, 1), 'free_gb': round(f['disk_gb'] - used, 1),
            'free_pct': round(100 * (f['disk_gb'] - used) / f['disk_gb']), 'recommend_gb': rec}


def scan_clips(today):
    """The clips kept in By_Date before today: first day and count per species, their bytes and number; today's
    clips (not purged yet: the daily swing) counted apart in bytes."""
    first, count = {}, {}
    clip_bytes = clips = today_bytes = 0
    try:
        days = sorted(d for d in os.listdir(BY_DATE) if re.match(r'^\d{4}-\d\d-\d\d$', d))
    except OSError:
        days = []
    for day in days:
        if day >= today:
            today_bytes += folder_bytes(os.path.join(BY_DATE, day))
            continue
        for sp in os.listdir(os.path.join(BY_DATE, day)):
            path = os.path.join(BY_DATE, day, sp)
            if not os.path.isdir(path):
                continue
            n, size = species_day(path)
            clip_bytes += size
            if n:
                clips += n
                count[sp] = count.get(sp, 0) + n
                first.setdefault(sp, day)
    return first, count, clip_bytes, clips, today_bytes


def folder_bytes(folder):
    total = 0
    for root, _dirs, files in os.walk(folder):
        for f in files:
            with contextlib.suppress(OSError):
                total += os.path.getsize(os.path.join(root, f))
    return total


def species_day(path):
    """(clips, bytes) of one species folder of one day: every file counts in bytes, the pictures not as clips."""
    n = size = 0
    for f in os.listdir(path):
        low = f.lower()
        if low.endswith('.tmp') or low == 'temp':
            continue
        try:
            size += os.path.getsize(os.path.join(path, f))
        except OSError:
            continue
        if not low.endswith(IMAGE):
            n += 1
    return n, size


def database_stats():
    """The database (detections are never purged): its size, detections, detections a day (last 30 days)."""
    db_bytes, det_total, det_day = 0, 0, 0.0
    try:
        db_bytes = os.path.getsize(DB)
        con = sqlite3.connect(f'file:{DB}?mode=ro', uri=True, timeout=10)
        det_total = con.execute('SELECT COUNT(*) FROM detections').fetchone()[0] or 0
        det_day = (con.execute("SELECT COUNT(*) FROM detections WHERE Date >= date('now', 'localtime', '-30 day')")
                   .fetchone()[0] or 0) / 30.0
        con.close()
    except (OSError, sqlite3.Error):
        pass
    return db_bytes, det_total, det_day


def daily_swing(today_bytes):
    """The largest highest - lowest of the last 7 days in disk-usage.log, at least today's clips so far."""
    swing = today_bytes
    try:
        with open(USAGE_LOG, encoding='utf-8') as fh:
            rows = [ln.split() for ln in fh if ln.strip()]
        for r in rows[-7:]:
            if len(r) >= 3:
                swing = max(swing, int(r[2]) - int(r[1]))
    except (OSError, ValueError):
        pass
    return swing


def main():
    today = time.strftime('%Y-%m-%d')
    now = time.time()
    cap = conf_int('MAX_FILES_SPECIES', 100)
    threshold = conf_int('PURGE_THRESHOLD', 70)
    own = species_caps()
    first, count, clip_bytes, clips, today_bytes = scan_clips(today)
    # each species: clips kept so far (n), kept per day since it first appeared (rate), its limit (0 = none)
    sp_rows = []
    for sp, n in count.items():
        age = max(1.0, (now - time.mktime(time.strptime(first[sp], '%Y-%m-%d'))) / 86400)
        sp_rows.append((n, n / age, own.get(sp, cap)))
    clip_avg = clip_bytes / clips if clips else 0
    db_bytes, det_total, det_day = database_stats()
    det_bytes = db_bytes / det_total if det_total else 0
    du = shutil.disk_usage('/')
    swing = daily_swing(today_bytes)
    rest = max(0, du.used - clip_bytes - today_bytes - db_bytes)
    species_proj = len(count) * (1 + SPECIES_MARGIN)
    # the station's clips kept a day: for each species its clips kept / days since it first appeared, summed
    rate_station = sum(r[1] for r in sp_rows)
    # the measured values are the inputs of the same calculation the page simulates (static/disk-sim.js):
    # clips   = sum over species of max(n, min(limit, rate * f * 365 * years)) * species / species seen
    #           (f = clips a day per species simulated / measured; a simulated limit replaces every species' own)
    # disk    = everything else + database + det/day * 365 * years * bytes/det + clips * clip size + daily swing
    # the variables are independent (owner 2026-11-01): the clips a day per species is the station's / the species seen;
    # the station's clips a day is then a result (species x clips a day per species)
    meas = {'cap': cap, 'species': round(species_proj), 'rate': round(rate_station / len(count), 3) if count else 0, 'det_day': round(det_day),
            'clip_kb': round(clip_avg / 1000), 'db_gb': round(db_bytes / 1e9, 2), 'threshold': threshold, 'years': 1}
    out = {
        'date': time.strftime('%Y-%m-%d %H:%M'),
        'species_seen': len(count), 'species_margin_pct': int(SPECIES_MARGIN * 100), 'own_caps': len(own),
        'clips_now': clips, 'clips_gb_now': round(clip_bytes / 1e9, 2),
        'sp': [[n, round(r, 4), c] for n, r, c in sp_rows], 'rate_station': round(rate_station, 1),
        'db_gb': round(db_bytes / 1e9, 3), 'det_total': det_total, 'det_bytes': round(det_bytes),
        'rest_gb': round(rest / 1e9, 2), 'swing_gb': round(swing / 1e9, 2), 'disk_gb': round(du.total / 1e9, 1),
        'threshold': threshold, 'sizes_gb': SIZES_GB, 'usable': 0.93, 'measured': meas,
    }
    out.update(simulate(out, meas))
    os.makedirs(STATE, exist_ok=True)
    tmp = OUT + '.tmp'
    with open(tmp, 'w', encoding='utf-8') as fh:
        json.dump(out, fh)
    os.chmod(tmp, 0o644)
    os.replace(tmp, OUT)
    return 0


if __name__ == '__main__':
    raise SystemExit(main())
