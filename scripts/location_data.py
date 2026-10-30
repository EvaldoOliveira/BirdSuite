#!/usr/bin/env python3
"""Location data (owner 2026-10-20): the records of one location as a whole — Tools › Location Data
(scripts/location_data.php) runs it as the station user, one job at a time, progress in ~/BirdNET-Pi/state/location_job.json.

  location_data.py remove  <loc_id>                  every detection of the location to the Removed folder: clips and
                                                      spectrograms to ~/BirdSongs/Extracted/Removed/<date>/<species>/,
                                                      lines to deleted_detections (restorable; deleted for good only
                                                      in Species › Purge Files)
  location_data.py backup  <loc_id|0> <folder> [--incremental]
                                                    a copy in <folder>/<yyyy-mm-dd>_<station>[_<location>]/:
                                                      location.json, detections.csv, reviews.csv, By_Date/ (clips + png),
                                                      always by the scientific name; 0 = every location. --incremental
                                                      (owner 2026-10-29): only the detections in no earlier backup of
                                                      this station and location in <folder>, and the reviews new or
                                                      changed since (folder ..._inc); the first one is a full one;
                                                      Import of every folder, in any order, gives the whole
  location_data.py restore <folder> [--to ID|--new] Import: a BirdSuite backup or a BirdNET folder (birds.db, By_Date by
                                                      common name) into this station, by the scientific name: the
                                                      location of the backup (found by its coordinates or created) or the
                                                      location ID given, or new locations (--new); rows already here are
                                                      not added twice
  location_data.py peek <folder>                    what a folder holds (JSON: kind, format, language, detections)
  location_data.py formats                          the format of each location's clips (JSON)
  location_data.py reassign <loc_id> <target_id>     the detections of a location filed under another one (their
                                                      Lat/Lon become the target's), e.g. to merge two places
  location_data.py legacy  <loc_id|0> <lang> <folder> Export to BirdNET (old): birds.db, By_Date by
                                                      common name in <lang>; nothing here changes; 0 = all locations
  location_data.py station <loc_id> <folder>        Move to another station: backup, then remove what reached it
  location_data.py start <action> <args...>          the same, in the background (the page): prints OK once the job
                                                      holds the job lock, else why it cannot start (2026-10-20)
  location_data.py ctl pause|continue|cancel         the running job (owner 2026-10-20): pause holds it between two
                                                      detections with the database committed (the station keeps
                                                      recording); cancel UNDOES what the job did so far — nothing
                                                      changes (a cancelled backup deletes its folder, a cancelled
                                                      Move to another station deletes the backup too); a reassign is
                                                      one database step and cannot be paused or cancelled

A detection belongs to the location whose radius holds its Lat/Lon and whose centre is the nearest (scripts/common.php location_sql).
Moving to another station = backup to a folder both stations reach (the NAS), then restore there.
"""
import csv
import fcntl
import json
import math
import os
import re
import shutil
import signal
import sqlite3
import sys
import time

try:
    from utils.i18n import t  # the page's language (US-122); scripts/ is on sys.path when this runs as a script
except Exception:  # never in the way of a job: the messages stay in English
    def t(text, **values):
        for k, v in values.items():
            text = text.replace('{' + k + '}', str(v))
        return text

from utils import locations as locations_util  # the location tag of the detections (owner 2026-10-21)
from utils.helpers import clip_rest, clip_target, get_language, name_safe, species_folder  # clip names (owner 2026-10-24)

HOME = os.path.expanduser('~')
BASE = os.path.join(HOME, 'BirdNET-Pi')
DB = os.path.join(BASE, 'config', 'data', 'birds.db')
LOCATIONS = os.path.join(BASE, 'config', 'locations.json')
JOB = os.path.join(BASE, 'state', 'location_job.json')
LOCK = os.path.join(BASE, 'state', 'location_job.lock')  # held by the job process for its whole life: one job at a time
EXTRACTED = os.path.join(HOME, 'BirdSongs', 'Extracted')
BY_DATE = os.path.join(EXTRACTED, 'By_Date')
REMOVED = os.path.join(EXTRACTED, 'Removed')
COLS = ['Date', 'Time', 'Sci_Name', 'Com_Name', 'Confidence', 'Lat', 'Lon', 'Cutoff', 'Week', 'Sens', 'Overlap', 'File_Name',
        'Loc_Thresh', 'Rec_Length', 'Sp_Override', 'Location', 'Biome', 'Ecoregion', 'Biome_BR', 'Land_Cover', 'Built_Up', 'Setting', 'Built_Up_500']  # US-27 (2026-10-29)


def job(**kw):
    data = {}
    if os.path.isfile(JOB):
        try:
            with open(JOB, encoding='utf-8') as f:
                data = json.load(f)
        except (OSError, ValueError):
            data = {}
    data.update(kw)
    with open(JOB + '.tmp', 'w', encoding='utf-8') as f:
        json.dump(data, f, ensure_ascii=False)
    os.replace(JOB + '.tmp', JOB)


FLAGS = {'pause': False, 'cancel': False}
CANCELLED = 3  # exit code: a Move to another station does not go on to the remove


def on_signal(signum, frame):
    """USR1 = pause, USR2 = continue, TERM = cancel; acted on between two detections (checkpoint)."""
    if signum == signal.SIGUSR1:
        FLAGS['pause'] = True
    elif signum == signal.SIGUSR2:
        FLAGS['pause'] = False
    else:
        FLAGS['cancel'], FLAGS['pause'] = True, False


def checkpoint(con, done):
    """Between two detections: wait here while paused (database committed, so the station is not blocked);
    True when the job is cancelled (the caller undoes what it did)."""
    if FLAGS['pause'] and not FLAGS['cancel']:
        if con is not None:
            con.commit()
        job(status='paused', done=done, paused_at=time.strftime('%Y-%m-%d %H:%M:%S'))
        while FLAGS['pause'] and not FLAGS['cancel']:
            time.sleep(0.5)
        if not FLAGS['cancel']:
            job(status='running')
    if FLAGS['cancel']:
        if con is not None:
            con.commit()
        job(status='cancelling', done=done)
        return True
    return False


def cancelled(result):
    job(status='cancelled', finished=time.strftime('%Y-%m-%d %H:%M:%S'), result=result)
    sys.exit(CANCELLED)


def control(action):
    """Signal the running job (the pid it recorded) — run as the station user by location_data.php."""
    data = {}
    try:
        with open(JOB, encoding='utf-8') as f:
            data = json.load(f)
    except (OSError, ValueError):
        pass
    pid = int(data.get('pid') or 0)
    try:
        with open(f'/proc/{pid}/cmdline', 'rb') as f:
            ours = b'location_data.py' in f.read()
    except OSError:
        ours = False
    # the pid must still be this script: after a reboot it can belong to another process
    if not pid or data.get('status') not in ('running', 'paused') or not ours:
        raise SystemExit(t('no job running'))
    if data.get('action') == 'reassign':
        # one UPDATE in one transaction: there is no point to pause at or to undo from (2026-10-20)
        raise SystemExit(t('a Move to another location is one database step: it cannot be paused or cancelled'))
    os.kill(pid, {'pause': signal.SIGUSR1, 'continue': signal.SIGUSR2, 'cancel': signal.SIGTERM}[action])
    print('OK')


def take_lock():
    """The job lock (flock, non-blocking), kept open until the process ends; None when another job holds it."""
    fd = os.open(LOCK, os.O_RDWR | os.O_CREAT, 0o600)
    try:
        fcntl.flock(fd, fcntl.LOCK_EX | fcntl.LOCK_NB)
    except BlockingIOError:
        os.close(fd)
        return None
    return fd


def job_start(action, **kw):
    """A new job replaces the previous one; a remove right after a backup (Move to station) keeps the backup folder."""
    prev = {}
    if os.path.isfile(JOB):
        try:
            with open(JOB, encoding='utf-8') as f:
                prev = json.load(f)
        except (OSError, ValueError):
            prev = {}
    data = dict(kw, action=action, status='running', done=0, error='', result='', pid=os.getpid(), started=time.strftime('%Y-%m-%d %H:%M:%S'))
    if action == 'remove' and prev.get('action') == 'backup' and prev.get('status') == 'done':
        data['target'] = prev.get('target')
    with open(JOB + '.tmp', 'w', encoding='utf-8') as f:
        json.dump(data, f, ensure_ascii=False)
    os.replace(JOB + '.tmp', JOB)
    return data


BUILTIN = {'home': 'Home', 'test': 'Test / Playback'}  # built-in locations, shown translated until renamed (common.php)


def locations():
    with open(LOCATIONS, encoding='utf-8') as f:
        return json.load(f)


def shown(loc):
    """The location as the pages show it: a built-in name in the interface language."""
    b = loc.get('builtin', '')
    return dict(loc, name=t(BUILTIN[b])) if b in BUILTIN and loc.get('name') == BUILTIN[b] else loc


def location(loc_id):
    for loc in locations():
        if int(loc['id']) == int(loc_id):
            return shown(loc)
    raise SystemExit(t('unknown location {id}', id=loc_id))


def km(lat1, lon1, lat2, lon2):
    p1, p2 = math.radians(lat1), math.radians(lat2)
    h = math.sin((p2 - p1) / 2) ** 2 + math.cos(p1) * math.cos(p2) * math.sin(math.radians(lon2 - lon1) / 2) ** 2
    return 6371 * 2 * math.asin(min(1, math.sqrt(h)))


def box(loc):
    """The detections of the location: its tag (column Location, owner 2026-10-21), or for rows written before the tag
    existed the coordinate rule (scripts/utils/locations.py, scripts/common.php location_sql)."""
    where, args = locations_util.where(loc, locations())
    return where, tuple(args)


def clip_dir(row):
    # the clip's day folder, new layout (scientific name) or old (common name): utils/helpers.py species_folder
    return os.path.join(row['Date'], species_folder(row['Sci_Name'], row['Com_Name'], row['File_Name']))


def connect():
    con = sqlite3.connect(DB, timeout=30)
    con.row_factory = sqlite3.Row
    con.execute("CREATE TABLE IF NOT EXISTS deleted_detections (Date DATE, Time TIME, Sci_Name VARCHAR(100) NOT NULL, "
                "Com_Name VARCHAR(100) NOT NULL, Confidence FLOAT, Lat FLOAT, Lon FLOAT, Cutoff FLOAT, Week INT, Sens FLOAT, "
                "Overlap FLOAT, File_Name VARCHAR(100) NOT NULL, Deleted_At TEXT, Loc_Thresh FLOAT, Rec_Length INT, Sp_Override FLOAT, "
                "Location INTEGER)")
    locations_util.ensure_column(con)
    return con


def rows_of(con, loc):
    where, args = box(loc)
    return [dict(r) for r in con.execute(f'SELECT {", ".join(COLS)} FROM detections WHERE {where}', args)]


def undo_remove(con, rows, now):
    """Cancel: the removed detections back where they were (clips, spectrograms and lines)."""
    for k, r in enumerate(rows):
        rel = clip_dir(r)
        for name in (r['File_Name'], r['File_Name'] + '.png', r['File_Name'] + '.jpg'):
            src, dst = os.path.join(REMOVED, rel, name), os.path.join(BY_DATE, rel, name)
            if os.path.exists(src) and not os.path.exists(dst):
                os.makedirs(os.path.dirname(dst), exist_ok=True)
                shutil.move(src, dst)
        for d in (os.path.join(REMOVED, rel), os.path.join(REMOVED, os.path.dirname(rel))):
            try:
                os.rmdir(d)  # the species and the day folder, when empty
            except OSError:
                pass
        con.execute(f'INSERT INTO detections ({", ".join(COLS)}) SELECT {", ".join("?" * len(COLS))} '
                    'WHERE NOT EXISTS (SELECT 1 FROM detections WHERE File_Name = ? AND Date = ? AND Time = ?)',
                    [r[c] for c in COLS] + [r['File_Name'], r['Date'], r['Time']])
        con.execute('DELETE FROM deleted_detections WHERE File_Name = ? AND Date = ? AND Time = ? AND Deleted_At = ?',
                    (r['File_Name'], r['Date'], r['Time'], now))
        if k % 200 == 0:
            con.commit()
            job(done=len(rows) - k)
    con.commit()


def remove(loc_id, after_backup=False):
    loc = location(loc_id)
    con = connect()
    rows = rows_of(con, loc)
    data = job_start('remove', location=loc['name'], total=len(rows))
    # Move to station: only what reached the backup; detections recorded while it was copied stay here
    saved = os.path.join(data.get('target') or '', 'detections.csv')
    if after_backup and data.get('target') and os.path.isfile(saved):
        with open(saved, encoding='utf-8') as f:
            keys = {(r['File_Name'], r['Date'], r['Time']) for r in csv.DictReader(f)}
        rows = [r for r in rows if (r['File_Name'], r['Date'], r['Time']) in keys]
        job(total=len(rows))
    now = time.strftime('%Y-%m-%d %H:%M:%S')
    shifted = []  # the frequency-shifted copies: caches, deleted only once the job is through (a cancel keeps them)
    for k, r in enumerate(rows):
        if checkpoint(con, k):
            undo_remove(con, rows[:k], now)
            if after_backup and data.get('target') and os.path.isdir(data['target']):
                shutil.rmtree(data['target'], ignore_errors=True)  # the Move to another station is undone as a whole
                cancelled(t('Cancelled: the {n} detections already removed were put back and the backup folder deleted: nothing changed', n=k))
            cancelled(t('Cancelled: the {n} detections already removed were put back: nothing changed', n=k))
        rel = clip_dir(r)
        dst = os.path.join(REMOVED, rel)
        for name in (r['File_Name'], r['File_Name'] + '.png', r['File_Name'] + '.jpg'):
            src = os.path.join(BY_DATE, rel, name)
            if os.path.exists(src):
                os.makedirs(dst, exist_ok=True)  # only when a file goes there: no empty folders
                shutil.move(src, os.path.join(dst, name))
            shifted.append(os.path.join(BY_DATE, 'shifted', rel, name))
        con.execute(f'INSERT INTO deleted_detections ({", ".join(COLS)}, Deleted_At) VALUES ({", ".join("?" * len(COLS))}, ?)',
                    [r[c] for c in COLS] + [now])
        con.execute('DELETE FROM detections WHERE File_Name = ? AND Date = ? AND Time = ?', (r['File_Name'], r['Date'], r['Time']))
        if k % 200 == 0:
            con.commit()
            job(done=k + 1)
        try:
            os.rmdir(os.path.join(BY_DATE, rel))  # the species folder of that day, when empty
        except OSError:
            pass
    con.commit()
    for f in shifted:
        try:
            os.remove(f)
        except OSError:
            pass
    job(status='done', done=len(rows), finished=time.strftime('%Y-%m-%d %H:%M:%S'),
        result=t('{n} detections of {location} moved to the Removed folder', n=len(rows), location=loc['name']))


def station_name():
    station = 'station'
    try:
        with open(os.path.join(BASE, 'config', 'BirdSuite.conf'), encoding='utf-8') as f:
            for line in f:
                if line.startswith('SITE_NAME='):
                    station = line.split('=', 1)[1].strip().strip('"') or station
    except OSError:
        pass
    return station


def all_rows(con):
    return [dict(r) for r in con.execute(f'SELECT {", ".join(COLS)} FROM detections')]


def labels_of(lang=None):
    try:
        return get_language(lang)
    except (OSError, ValueError, KeyError):
        return {}


def earlier_backups(folder, station, loc_id):
    """The BirdSuite backups of this station and location already in folder (incremental backup, owner 2026-10-29):
    their folder names, the clip names they hold and the reviews (clip name, time of the answer) they carry."""
    names, files, reviewed = [], set(), set()
    try:
        subs = sorted(os.listdir(folder))
    except OSError:
        return names, files, reviewed
    for d in subs:
        p = os.path.join(folder, d)
        try:
            with open(os.path.join(p, 'location.json'), encoding='utf-8') as f:
                meta = json.load(f)
        except (OSError, ValueError):
            continue
        if meta.get('format') != 'birdsuite' or meta.get('station') != station or int((meta.get('location') or {}).get('id') or 0) != int(loc_id):
            continue
        names.append(d)
        for csv_name, keep in (('detections.csv', lambda r: files.add(r.get('File_Name'))),
                               ('reviews.csv', lambda r: reviewed.add((r.get('File_Name'), r.get('Reviewed_At') or '')))):
            try:
                with open(os.path.join(p, csv_name), newline='', encoding='utf-8') as f:
                    for r in csv.DictReader(f):
                        keep(r)
            except OSError:
                pass
    return names, files, reviewed


def backup(loc_id, folder, incremental=False):
    """A copy in a folder (Export / Backup, owner 2026-10-24): the clips by the scientific name with the common names of
    the Common name Language, whatever the format they are in here (never a folder of mixed formats). loc_id 0 = every
    location (location.json lists them; Import files each detection under its own). incremental: see earlier_backups."""
    if loc_id:
        loc = location(loc_id)
    else:
        loc = {'id': 0, 'name': t('All locations')}
    con = connect()
    rows = rows_of(con, loc) if loc_id else all_rows(con)
    station = station_name()
    names = labels_of()

    def safe(x):
        # one underscore for each run of other characters (owner 2026-10-25: "Teste / Playback" -> Teste_Playback)
        return re.sub(r'_+', '_', ''.join(c if c.isalnum() or c in '-_' else '_' for c in x)).strip('_')
    renamed = {}
    for r in rows:
        renamed[r['File_Name']] = clip_target(r['Sci_Name'], r['Com_Name'], r['File_Name'])[1]
    chain, done_files, done_reviews = earlier_backups(folder, station, loc_id) if incremental else ([], set(), set())
    if chain:
        rows = [r for r in rows if renamed[r['File_Name']] not in done_files]
        try:
            changed = [1 for r in con.execute('SELECT File_Name, Reviewed_At FROM detection_reviews')
                       if r['File_Name'] in renamed and (renamed[r['File_Name']], r['Reviewed_At'] or '') not in done_reviews]
        except sqlite3.OperationalError:
            changed = []
        if not rows and not changed:
            # nothing new since the last backup here: no folder
            job_start('backup', location=loc['name'], total=0, target=folder)
            job(status='done', done=0, finished=time.strftime('%Y-%m-%d %H:%M:%S'),
                result=t('Nothing new since the last backup of {location} in {folder}: no folder made', location=loc['name'], folder=folder))
            return
    # yyyy-mm-dd_station_location, or yyyy-mm-dd_station for every location (owner 2026-10-25); a second one on the same
    # day gets _2, _3 ...; an incremental one ends in _inc
    base = os.path.join(folder, f'{time.strftime("%Y-%m-%d")}_{safe(station)}' + (f'_{safe(loc["name"])}' if loc_id else '') + ('_inc' if chain else ''))
    out, n = base, 1
    while os.path.exists(out):
        n += 1
        out = f'{base}_{n}'
    os.makedirs(out)
    job_start('backup', location=loc['name'], total=len(rows), target=out)
    meta = {'location': loc, 'station': station, 'exported': time.strftime('%Y-%m-%d %H:%M:%S'), 'detections': len(rows),
            'format': 'birdsuite'}
    if not loc_id:
        meta['locations'] = [shown(x) for x in locations()]
    if chain:
        meta['incremental'] = {'after': chain}  # the earlier backups this one completes (Import them all)
    for r in rows:
        r['_src'] = clip_dir(r)
        new = renamed[r['File_Name']]
        r['_dst'] = os.path.join(r['Date'], name_safe(r['Sci_Name']))
        r['_name'] = r['File_Name']
        r['File_Name'] = new
        r['Com_Name'] = names.get(r['Sci_Name']) or r['Com_Name']
    with open(os.path.join(out, 'location.json'), 'w', encoding='utf-8') as f:
        json.dump(meta, f, ensure_ascii=False, indent=1)
    with open(os.path.join(out, 'detections.csv'), 'w', newline='', encoding='utf-8') as f:
        w = csv.DictWriter(f, fieldnames=COLS, extrasaction='ignore')
        w.writeheader()
        w.writerows(rows)
    names_here = {r['_name'] for r in rows}
    try:
        # incremental: also the reviews of the detections of earlier backups that are new or changed since
        reviews = [dict(r) for r in con.execute('SELECT * FROM detection_reviews')
                   if r['File_Name'] in names_here or (chain and r['File_Name'] in renamed
                                                       and (renamed[r['File_Name']], r['Reviewed_At'] or '') not in done_reviews)]
    except sqlite3.OperationalError:
        reviews = []
    for r in reviews:
        if r['File_Name'] in renamed:
            r['File_Name'] = renamed[r['File_Name']]
            r['Com_Name'] = names.get(r.get('Sci_Name')) or r.get('Com_Name')
    if reviews:
        with open(os.path.join(out, 'reviews.csv'), 'w', newline='', encoding='utf-8') as f:
            w = csv.DictWriter(f, fieldnames=list(reviews[0].keys()))
            w.writeheader()
            w.writerows(reviews)
    for k, r in enumerate(rows):
        if checkpoint(None, k):
            shutil.rmtree(out, ignore_errors=True)
            cancelled(t('Cancelled: the unfinished backup folder was deleted: nothing changed'))
        for ext in ('', '.png', '.jpg'):
            src = os.path.join(BY_DATE, r['_src'], r['_name'] + ext)
            if os.path.exists(src):
                os.makedirs(os.path.join(out, 'By_Date', r['_dst']), exist_ok=True)
                shutil.copy2(src, os.path.join(out, 'By_Date', r['_dst'], r['File_Name'] + ext))
        if k % 200 == 0:
            job(done=k + 1)
    job(status='done', done=len(rows), finished=time.strftime('%Y-%m-%d %H:%M:%S'),
        result=(t('Incremental: {n} new detections and {r} reviews of {location} copied to {folder}', n=len(rows), r=len(reviews), location=loc['name'], folder=out)
                if chain else t('{n} detections of {location} copied to {folder}', n=len(rows), location=loc['name'], folder=out)))


LEGACY_COLS = COLS[:12]  # the detections table of BirdNET-Pi (Date ... File_Name)


def legacy(loc_id, lang, folder):
    """Export to BirdNET (old): a copy in the format of BirdNET-Pi (owner 2026-10-24), for a database or program of the BirdNET-Pi kind and a long-term
    archive in any language: <folder>/BirdNET-Pi-<station>-<location>-<lang>-<time>/ with birds.db (its detections
    table) and By_Date/<date>/<Com_safe>/<Com_safe>-<date>_<time>-... with the common names of <lang>. Nothing of this
    station changes. loc_id 0 = every location."""
    loc = location(loc_id) if loc_id else {'name': t('All locations'), 'id': 0}
    con = connect()
    rows = rows_of(con, loc) if loc_id else [dict(r) for r in con.execute(f'SELECT {", ".join(COLS)} FROM detections')]
    names = get_language(lang)

    def safe(x):
        # one underscore for each run of other characters (owner 2026-10-25: "Teste / Playback" -> Teste_Playback)
        return re.sub(r'_+', '_', ''.join(c if c.isalnum() or c in '-_' else '_' for c in x)).strip('_')
    out = os.path.join(folder, f'BirdNET-Pi-{safe(station_name())}-{safe(loc["name"])}-{lang}-{time.strftime("%Y%m%d-%H%M%S")}')
    os.makedirs(out)
    job_start('legacy', location=loc['name'], total=len(rows), target=out)
    db = sqlite3.connect(os.path.join(out, 'birds.db'))
    db.execute('CREATE TABLE detections (Date DATE, Time TIME, Sci_Name VARCHAR(100) NOT NULL, Com_Name VARCHAR(100) NOT NULL, '
               'Confidence FLOAT, Lat FLOAT, Lon FLOAT, Cutoff FLOAT, Week INT, Sens FLOAT, Overlap FLOAT, File_Name VARCHAR(100) NOT NULL)')
    for k, r in enumerate(rows):
        if checkpoint(None, k):
            db.close()
            shutil.rmtree(out, ignore_errors=True)
            cancelled(t('Cancelled: the unfinished export folder was deleted: nothing changed'))
        com = names.get(r['Sci_Name']) or r['Com_Name']
        c = name_safe(com)
        rest = clip_target(r['Sci_Name'], r['Com_Name'], r['File_Name'])[1][len(name_safe(r['Sci_Name'])):]
        old_name = c + rest
        src_dir = os.path.join(BY_DATE, clip_dir(r))
        dst_dir = os.path.join(out, 'By_Date', r['Date'], c)
        for ext in ('', '.png', '.jpg'):
            src = os.path.join(src_dir, r['File_Name'] + ext)
            if os.path.exists(src):
                os.makedirs(dst_dir, exist_ok=True)
                shutil.copy2(src, os.path.join(dst_dir, old_name + ext))
        db.execute(f'INSERT INTO detections ({", ".join(LEGACY_COLS)}) VALUES ({", ".join("?" * len(LEGACY_COLS))})',
                   [com if col == 'Com_Name' else old_name if col == 'File_Name' else r[col] for col in LEGACY_COLS])
        if k % 200 == 0:
            db.commit()
            job(done=k + 1)
    db.execute('CREATE INDEX detections_Com_Name ON detections (Com_Name)')
    db.execute('CREATE INDEX detections_Date_Time ON detections (Date DESC, Time DESC)')
    db.commit()
    db.close()
    with open(os.path.join(out, 'README.txt'), 'w', encoding='utf-8') as f:
        f.write(f'BirdSuite export to BirdNET (old): folders and files by the common name ({time.strftime("%Y-%m-%d %H:%M:%S")})\n'
                f'Station: {station_name()}  Location: {loc["name"]}  Common names: {lang}  Detections: {len(rows)}\n\n'
                'birds.db             the detections table of BirdNET-Pi\n'
                'By_Date/<date>/<Common_name>/<Common_name>-...   the clips and spectrograms\n')
    job(status='done', done=len(rows), finished=time.strftime('%Y-%m-%d %H:%M:%S'),
        result=t('{n} detections of {location} exported to BirdNET (old), common names in {lang}, to {folder}', n=len(rows), location=loc['name'], lang=lang, folder=out))


def restore_target(src_loc, to_id, new=False):
    """The location a restore files into, and whether it was created for it. new (owner 2026-10-24: Import into a new
    location) = always a new one, named after the backup's (+ " (import)"), at its coordinates."""
    created = False
    if to_id:
        loc = location(to_id)
    elif new:
        locs = locations()
        loc = {'id': max(int(x['id']) for x in locs) + 1, 'name': f'{src_loc["name"]} (import)', 'lat': src_loc['lat'], 'lon': src_loc['lon'],
               'radius_km': src_loc.get('radius_km', locations_util.RADIUS_KM)}
        locs.append(loc)
        with open(LOCATIONS, 'w', encoding='utf-8') as f:
            json.dump(locs, f, ensure_ascii=False, indent=4)
        return loc, True
    else:
        # the backup's location: of the registered ones whose radius holds its coordinates the one with the nearest
        # centre (as common.php location_sql files a point), else a new one with its name
        locs = locations()
        loc = None
        best = None
        for cand in locs:
            dist = km(src_loc['lat'], src_loc['lon'], cand['lat'], cand['lon'])
            if dist <= float(cand.get('radius_km', locations_util.RADIUS_KM)) and (best is None or dist < best):
                loc, best = cand, dist
        if loc is None:
            loc = {'id': max(int(x['id']) for x in locs) + 1, 'name': src_loc['name'], 'lat': src_loc['lat'], 'lon': src_loc['lon'],
                   'radius_km': src_loc.get('radius_km', locations_util.RADIUS_KM)}
            locs.append(loc)
            created = True
            with open(LOCATIONS, 'w', encoding='utf-8') as f:
                json.dump(locs, f, ensure_ascii=False, indent=4)
    return loc, created


def restore_undo(con, new_rows, new_files, created):
    """Cancel = nothing changes: the rows, clips and the new locations this import added go (does not return)."""
    for n, key in enumerate(new_rows):
        con.execute('DELETE FROM detections WHERE File_Name = ? AND Date = ? AND Time = ?', key)
        if n % 200 == 0:
            con.commit()
    con.commit()
    for f in new_files:
        try:
            os.remove(f)
            os.rmdir(os.path.dirname(f))
        except OSError:
            pass
    if created:
        ids = {int(x['id']) for x in created}
        locs = [x for x in locations() if int(x['id']) not in ids]
        with open(LOCATIONS, 'w', encoding='utf-8') as f:
            json.dump(locs, f, ensure_ascii=False, indent=4)
        cancelled(t('Cancelled: the {n} detections already restored were taken out again and the new location {location} deleted: nothing changed',
                    n=len(new_rows), location=', '.join(x['name'] for x in created)))
    cancelled(t('Cancelled: the {n} detections already restored were taken out again: nothing changed', n=len(new_rows)))


# Import (owner 2026-10-24, formerly Restore): a folder made by Export / Backup (location.json, detections.csv,
# By_Date/), or a BirdNET-Pi installation or export (birds.db and By_Date/ by common name). Either format comes in as
# this station keeps its clips: by the scientific name, with the common names of the Common name Language.
BIRDNET_DB = ('birds.db', 'scripts/birds.db', 'BirdNET-Pi/scripts/birds.db')
BIRDNET_BY_DATE = ('By_Date', 'Extracted/By_Date', 'BirdSongs/Extracted/By_Date')


def first(folder, cands):
    for c in cands:
        if os.path.exists(os.path.join(folder, c)):
            return os.path.join(folder, c)
    return None


def source(folder):
    """What a folder holds: {kind: birdsuite|birdnet, meta, rows, reviews (csv path or None), by_date} or None."""
    if os.path.isfile(os.path.join(folder, 'location.json')):
        with open(os.path.join(folder, 'location.json'), encoding='utf-8') as f:
            meta = json.load(f)
        with open(os.path.join(folder, 'detections.csv'), encoding='utf-8') as f:
            rows = list(csv.DictReader(f))
        rev = os.path.join(folder, 'reviews.csv')
        return {'kind': 'birdsuite', 'meta': meta, 'rows': rows, 'reviews': rev if os.path.isfile(rev) else None,
                'by_date': os.path.join(folder, 'By_Date')}
    db = first(folder, BIRDNET_DB)
    if db:
        c = sqlite3.connect(f'file:{db}?mode=ro', uri=True)
        c.row_factory = sqlite3.Row
        have = {r[1] for r in c.execute('PRAGMA table_info(detections)')}
        rows = [{k: (r[k] if k in have else None) for k in COLS} for r in c.execute('SELECT * FROM detections')]
        c.close()
        for r in rows:
            r['Location'] = None
            for k in ('Date', 'Time', 'Sci_Name', 'Com_Name', 'File_Name'):
                r[k] = str(r[k] or '')
        return {'kind': 'birdnet', 'meta': {'station': os.path.basename(os.path.normpath(folder))}, 'rows': rows,
                'reviews': None, 'by_date': first(folder, BIRDNET_BY_DATE) or os.path.join(folder, 'By_Date')}
    return None


def lang_codes():
    d = os.path.join(BASE, 'model', 'l18n')
    try:
        return sorted(f[7:-5] for f in os.listdir(d) if f.startswith('labels_') and f.endswith('.json'))
    except OSError:
        return []


def detect_format(rows):
    """{'birdsuite': n, 'birdnet': n, 'lang': code or ''}: the clips by the scientific name or by the common name, and the
    language of those common names (from a sample of their file names)."""
    old = [r for r in rows if r['File_Name'] and not str(r['File_Name']).startswith(name_safe(r['Sci_Name']) + '-')]
    out = {'birdsuite': len(rows) - len(old), 'birdnet': len(old), 'lang': ''}
    sample = []
    for r in old[:40]:
        rest = clip_rest(r['File_Name'])
        if rest:
            sample.append((r['Sci_Name'], r['File_Name'][:len(r['File_Name']) - len(rest)]))
    if sample:
        order = lang_codes()
        here = (get_settings_lang() or 'en')
        order = [x for x in (here, 'en') if x in order] + [x for x in order if x not in (here, 'en')]
        best, best_n = '', 0
        for code in order:
            names = labels_of(code)
            n = sum(1 for sci, prefix in sample if name_safe(names.get(sci, '')) == prefix)
            if n > best_n:
                best, best_n = code, n
            if n == len(sample):
                break
        out['lang'] = best
    return out


def get_settings_lang():
    try:
        with open(os.path.join(BASE, 'config', 'BirdSuite.conf'), encoding='utf-8') as f:
            for line in f:
                if line.startswith('DATABASE_LANG='):
                    return line.split('=', 1)[1].strip().strip('"')
    except OSError:
        pass
    return ''


def peek(folder):
    src = source(folder)
    if not src:
        return {'error': t('Not a backup: no location.json (BirdSuite) or birds.db (BirdNET)')}
    meta = src['meta']
    return dict(detect_format(src['rows']), kind=src['kind'], detections=len(src['rows']), station=meta.get('station', ''),
                exported=meta.get('exported', ''), location=(meta.get('location') or {}).get('name', ''),
                locations=len(meta.get('locations') or []))


def formats():
    """Per location id (and '' for detections of no location): the format of its clips (Backup/Restore table)."""
    con = connect()
    out = {}
    by = {}
    for r in con.execute('SELECT Location, Sci_Name, File_Name FROM detections'):
        by.setdefault(str(r['Location'] or ''), []).append({'Sci_Name': r['Sci_Name'], 'File_Name': r['File_Name']})
    for k, rows in by.items():
        out[k] = detect_format(rows)
    return out


def restore(folder, to_id=None, new=False):
    src = source(folder)
    if not src:
        raise SystemExit(t('Not a backup: no location.json (BirdSuite) or birds.db (BirdNET)'))
    meta, rows = src['meta'], src['rows']
    created = []
    targets = {}  # where each row goes: by the backup's location (or coordinates for BirdNET), or the location chosen

    def target(r):
        if to_id:
            key = 'to'
        elif src['kind'] == 'birdnet':
            key = (round(float(r['Lat'] or 0), 3), round(float(r['Lon'] or 0), 3))
        elif meta.get('locations'):
            key = str(r.get('Location') or '')
        else:
            key = 'meta'
        if key not in targets:
            if key == 'to':
                loc, made = restore_target(None, to_id)
            elif src['kind'] == 'birdnet':
                # one location per place of the old folder, told apart by its coordinates (2026-10-25: an export of
                # every location gave two new locations with the same name)
                loc, made = restore_target({'name': f'BirdNET {meta["station"]} ({key[0]:.3f}, {key[1]:.3f})', 'lat': key[0], 'lon': key[1],
                                            'radius_km': locations_util.RADIUS_KM}, None, new)
            elif key != 'meta':
                m = [x for x in meta['locations'] if str(x['id']) == key] or [meta['location']]
                loc, made = restore_target(m[0], None, new)
            else:
                loc, made = restore_target(meta['location'], None, new)
            targets[key] = loc
            if made:
                created.append(loc)
        return targets[key]
    con = connect()
    shown_name = location(to_id)['name'] if to_id else (meta.get('location') or {}).get('name') or f'BirdNET {meta.get("station", "")}'
    job_start('restore', location=shown_name, total=len(rows), source=folder)
    added = 0
    new_rows, new_files = [], []  # what this import added, undone by a cancel
    names = labels_of()
    renamed = {}
    # US-129 (owner 2026-10-29, 38,713 detections at 7 a second on an SD card, 4 to 9 from a network share): the
    # detections already here are known before the loop (one query per block of days) and skipped without a look at a
    # file; each folder of the backup and of the station is listed once instead of one check per file; the database is
    # committed in batches (every BATCH rows or BATCH_SEC seconds), still short enough for the live analysis to write
    # between two, and before a pause or a cancel (checkpoint)
    days = sorted({r['Date'] for r in rows})
    have_file, have_sci = set(), set()
    for i in range(0, len(days), 200):
        part = days[i:i + 200]
        for x in con.execute(f'SELECT File_Name, Sci_Name, Date, Time FROM detections WHERE Date IN ({", ".join("?" * len(part))})', part):
            have_file.add((x['File_Name'], x['Date'], x['Time']))
            have_sci.add((x['Sci_Name'], x['Date'], x['Time']))
    listed = {}

    def in_dir(d, name):
        if d not in listed:
            try:
                listed[d] = set(os.listdir(d))
            except OSError:
                listed[d] = set()
        return name in listed[d]
    BATCH, BATCH_SEC = 500, 2.0
    pending, last_commit = 0, time.time()
    for k, r in enumerate(rows):
        if checkpoint(con, k):
            restore_undo(con, new_rows, new_files, created)
        src_folder, new_file = clip_target(r['Sci_Name'], r['Com_Name'], r['File_Name'])
        renamed[r['File_Name']] = new_file
        if (new_file, r['Date'], r['Time']) in have_file or (r['Sci_Name'], r['Date'], r['Time']) in have_sci:
            if k % 200 == 0:
                job(done=k + 1)
            continue  # already here (also when renamed here): nothing to look at
        loc = target(r)
        src_dir = os.path.join(src['by_date'], r['Date'], src_folder)
        if not in_dir(src_dir, r['File_Name']):
            src_dir = os.path.join(src['by_date'], clip_dir(r))
        dst_dir = os.path.join(BY_DATE, r['Date'], name_safe(r['Sci_Name']))
        for ext in ('', '.png', '.jpg'):
            f_src = os.path.join(src_dir, r['File_Name'] + ext)
            dst = os.path.join(dst_dir, new_file + ext)
            if in_dir(src_dir, r['File_Name'] + ext) and not in_dir(dst_dir, new_file + ext):
                os.makedirs(dst_dir, exist_ok=True)
                shutil.copy2(f_src, dst)
                listed.setdefault(dst_dir, set()).add(new_file + ext)
                new_files.append(dst)
        r = dict(r, File_Name=new_file, Com_Name=names.get(r['Sci_Name']) or r['Com_Name'])
        vals = [r.get(c) if r.get(c) != '' else None for c in COLS]
        vals[COLS.index('Lat')], vals[COLS.index('Lon')] = loc['lat'], loc['lon']
        vals[COLS.index('Location')] = int(loc['id'])
        for c in ('Biome', 'Ecoregion', 'Biome_BR', 'Land_Cover', 'Built_Up', 'Setting', 'Built_Up_500'):  # those of the location it is filed under here (the trigger, US-27)
            vals[COLS.index(c)] = None
        # a detection already here is not added twice, also when it was renamed here (its Sci_Name, Date, Time); the
        # check stays in the statement: the live analysis may have written the same one meanwhile
        cur = con.execute(f'INSERT INTO detections ({", ".join(COLS)}) SELECT {", ".join("?" * len(COLS))} '
                          'WHERE NOT EXISTS (SELECT 1 FROM detections WHERE (File_Name = ? OR Sci_Name = ?) AND Date = ? AND Time = ?)',
                          vals + [r['File_Name'], r['Sci_Name'], r['Date'], r['Time']])
        added += cur.rowcount
        have_file.add((r['File_Name'], r['Date'], r['Time']))
        have_sci.add((r['Sci_Name'], r['Date'], r['Time']))
        if cur.rowcount:
            new_rows.append((r['File_Name'], r['Date'], r['Time']))
            pending += 1
        if pending >= BATCH or (pending and time.time() - last_commit >= BATCH_SEC):
            con.commit()
            pending, last_commit = 0, time.time()
        if k % 200 == 0:
            job(done=k + 1)
    con.commit()
    if src['reviews']:
        with open(src['reviews'], encoding='utf-8') as f:
            for r in csv.DictReader(f):
                try:
                    # the newest answer wins (owner 2026-10-29: incremental backups carry the answers changed since)
                    con.execute('INSERT INTO detection_reviews (File_Name, Sci_Name, Com_Name, Date, Confidence, Verdict, Reviewed_At, Reason) '
                                'VALUES (?, ?, ?, ?, ?, ?, ?, ?) ON CONFLICT(File_Name) DO UPDATE SET Verdict = excluded.Verdict, '
                                'Reviewed_At = excluded.Reviewed_At, Reason = excluded.Reason, Sci_Name = excluded.Sci_Name, Com_Name = excluded.Com_Name '
                                'WHERE COALESCE(excluded.Reviewed_At, \'\') > COALESCE(detection_reviews.Reviewed_At, \'\')', (renamed.get(r.get('File_Name'), r.get('File_Name')), r.get('Sci_Name'),
                                                                   names.get(r.get('Sci_Name')) or r.get('Com_Name'), r.get('Date'),
                                                                   r.get('Confidence'), r.get('Verdict'), r.get('Reviewed_At'), r.get('Reason')))
                except sqlite3.OperationalError:
                    break
    con.commit()
    where = ', '.join(sorted({x['name'] for x in targets.values()})) or shown_name
    job(status='done', done=len(rows), finished=time.strftime('%Y-%m-%d %H:%M:%S'),
        result=t('{n} detections restored into {location} ({m} were already here)', n=added, location=where, m=len(rows) - added))


def reassign(loc_id, target_id):
    loc, target = location(loc_id), location(target_id)
    con = connect()
    where, args = box(loc)
    job_start('reassign', location=loc['name'], total=0)
    vals = (target['lat'], target['lon'], int(target['id']))
    n = con.execute(f'UPDATE detections SET Lat = ?, Lon = ?, Location = ? WHERE {where}', vals + args).rowcount
    con.execute(f'UPDATE deleted_detections SET Lat = ?, Lon = ?, Location = ? WHERE {where}', vals + args)
    con.commit()
    job(status='done', done=n, total=n, finished=time.strftime('%Y-%m-%d %H:%M:%S'),
        result=t('{n} detections of {location} filed under {target}', n=n, location=loc['name'], target=target['name']))


def detach():
    """The page's start: the parent prints OK and ends, the child (holding the lock) runs the job on its own."""
    sys.stdout.flush()
    signal.signal(signal.SIGHUP, signal.SIG_IGN)  # as nohup did: the caller's session may end first
    r, w = os.pipe()
    if os.fork():
        os.close(w)
        os.read(r, 1)  # OK only once the child is on its own (new session, stdio closed)
        print('OK', flush=True)
        os._exit(0)
    os.close(r)
    os.setsid()
    null = os.open(os.devnull, os.O_RDWR)
    for fd in (0, 1, 2):
        os.dup2(null, fd)
    os.close(null)
    os.nice(19)
    os.write(w, b'1')
    os.close(w)


def main():
    a = sys.argv[1:]
    if a[:1] == ['ctl'] and len(a) == 2 and a[1] in ('pause', 'continue', 'cancel'):
        control(a[1])
        return
    background = a[:1] == ['start']
    if background:
        a = a[1:]
    if a[:1] == ['peek'] and len(a) == 2:
        print(json.dumps(peek(a[1]), ensure_ascii=False))
        return
    if a == ['formats']:
        print(json.dumps(formats(), ensure_ascii=False))
        return
    if a[:1] not in (['remove'], ['backup'], ['station'], ['restore'], ['reassign'], ['legacy']):
        raise SystemExit(__doc__)
    # one job at a time (2026-10-20): two quick starts both passed the page's status check; the lock is the real
    # gate, and a refused start leaves the running job's file alone
    if take_lock() is None:
        raise SystemExit(t('Another job is running or paused: continue or cancel it first'))
    if background:
        detach()
    for sig in (signal.SIGUSR1, signal.SIGUSR2, signal.SIGTERM):
        signal.signal(sig, on_signal)
    try:
        if a[:1] == ['remove'] and len(a) in (2, 3):
            remove(int(a[1]), after_backup=a[2:] == ['--after-backup'])
        elif a[:1] == ['backup'] and len(a) in (3, 4) and a[3:] in ([], ['--incremental']):
            backup(int(a[1]), a[2], incremental=a[3:] == ['--incremental'])
        elif a[:1] == ['station'] and len(a) == 3:
            # Move to another station in one process, so the lock is not let go between the backup and the remove
            backup(int(a[1]), a[2])
            remove(int(a[1]), after_backup=True)
        elif a[:1] == ['restore'] and len(a) == 3 and a[2] == '--new':
            restore(a[1], None, new=True)
        elif a[:1] == ['restore'] and len(a) in (2, 4):
            restore(a[1], int(a[3]) if len(a) == 4 and a[2] == '--to' else None)
        elif a[:1] == ['reassign'] and len(a) == 3:
            reassign(int(a[1]), int(a[2]))
        elif a[:1] == ['legacy'] and len(a) == 4:
            legacy(int(a[1]), a[2], a[3])
        else:
            raise SystemExit(__doc__)
    except SystemExit as e:
        if e.code == CANCELLED:
            raise
        job(status='error', error=f'{type(e).__name__}: {e}'[:500], finished=time.strftime('%Y-%m-%d %H:%M:%S'))
        raise
    except BaseException as e:
        job(status='error', error=f'{type(e).__name__}: {e}'[:500], finished=time.strftime('%Y-%m-%d %H:%M:%S'))
        raise


if __name__ == '__main__':
    main()
