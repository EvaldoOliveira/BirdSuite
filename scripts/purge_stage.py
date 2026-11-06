################################################################################
# scripts/purge_stage.py - NIGHTLY STAGING OF THE AUDIO TO PURGE
#
# PURPOSE:
#   Nightly staging of the audio to purge (owner 2026-10-22).
#
# DEPENDENCIES:
#   - scripts/utils/core.py
#   - scripts/disk_species_clean.sh
#   - tests/helpers.py
#
# USAGE:
#   python3 scripts/purge_stage.py <arguments> (see main)
#   Referenced by scripts/disk_species_clean.sh, scripts/lib/clips.php, scripts/play.php
################################################################################

"""Nightly staging of the audio to purge (owner 2026-10-22).

The detections answered with a cause (Human, Noise, Ignore) keep their line in the
database, so every statistic still counts them; once a day (disk_species_clean.sh, 02:00) this script moves their clip
and spectrograms from By_Date to the data folder's Extracted/Purge/<date>/<species>/, where Species › Purge Files deletes
them for good. A clip whose answer changed since (cleared, Yes, another cause no longer in the list) goes back to By_Date.
Then the staged audio older than IGNORED_PURGE_DAYS days (by the detection's date; default 5, 0 = off; Tools › Data ›
Life cycle of the sounds, owner 2026-10-24) is deleted for good: the detections keep counting.
Usage: purge_stage.py [--print]
"""
import contextlib
import datetime
import os
import re
import shutil
import sqlite3
import sys
import syslog

from utils import core  # the folder layout (core.env)

EXTRACTED = core.BS_EXTRACTED
BY_DATE = os.path.join(EXTRACTED, 'By_Date')
PURGE = os.path.join(EXTRACTED, 'Purge')
DB = core.BS_DB
CAUSES = ('human', 'noise', 'ignore')


def species_dir(sci):
    # the clip's folder: the scientific name (the rule of utils/helpers.py species_folder)
    return sci.replace("'", '').replace(' ', '_')


def move(src_dir, dst_dir, name):
    moved = 0
    for f in (name, name + '.png', name + '.jpg'):
        src = os.path.join(src_dir, f)
        if os.path.exists(src):
            os.makedirs(dst_dir, exist_ok=True)
            shutil.move(src, os.path.join(dst_dir, f))
            moved += f == name
    return moved


def stage_back(wanted):
    """Back to By_Date: clips in Purge whose answer is no longer a cause; returns (moved back, failed)."""
    staged_back = failed = 0
    if os.path.isdir(PURGE):
        for root, _, files in os.walk(PURGE):
            for name in files:
                if name.endswith(('.png', '.jpg')):
                    continue
                rel = os.path.relpath(root, PURGE)
                if os.path.join(rel, name) in wanted:
                    continue
                try:
                    staged_back += move(root, os.path.join(BY_DATE, rel), name)
                except OSError as e:
                    failed += 1
                    print(f'{rel}/{name}: {e}', file=sys.stderr)
        for root, dirs, files in os.walk(PURGE, topdown=False):
            if root != PURGE and not dirs and not files:
                os.rmdir(root)
    return staged_back, failed


def setting(key, default):
    """A value of BirdSuite.conf, else of the factory defaults, else default."""
    val = default
    for f in ('factory_defaults.conf', 'BirdSuite.conf'):
        try:
            with open(os.path.join(core.BS_CONFIG, f), encoding='utf-8') as fh:
                for line in fh:
                    m = re.match(rf'^{key}=["\']?([^"\'\n]*)', line)
                    if m:
                        val = m.group(1).strip()
        except OSError:
            pass
    return val


def purge_old(days):
    """Deletes the staged audio of detections older than days (Purge/<date>/...); returns the files deleted."""
    if days <= 0 or not os.path.isdir(PURGE):
        return 0
    limit = (datetime.date.today() - datetime.timedelta(days=days)).isoformat()
    n = 0
    for date in os.listdir(PURGE):
        if not re.match(r'^\d{4}-\d{2}-\d{2}$', date) or date >= limit:
            continue
        for root, _dirs, files in os.walk(os.path.join(PURGE, date), topdown=False):
            for f in files:
                try:
                    os.remove(os.path.join(root, f))
                    n += 1
                except OSError as e:
                    print(f'{root}/{f}: {e}', file=sys.stderr)
            with contextlib.suppress(OSError):
                os.rmdir(root)
    return n


def main():
    if not os.path.exists(DB):
        return
    con = sqlite3.connect(f'file:{DB}?mode=ro', uri=True, timeout=30)
    try:
        rows = con.execute(f"""SELECT d.Date, d.Sci_Name, d.File_Name FROM detections d JOIN detection_reviews r ON r.File_Name = d.File_Name
            WHERE r.Verdict = 'no' AND r.Reason IN ({','.join('?' * len(CAUSES))})""", CAUSES).fetchall()
        staged = staged_back = failed = 0
        wanted = set()
        # the protected clips (locks, top N; config/scripts/disk_check_exclude.txt) stay where they are
        try:
            with open(core.BS_DISK_EXCLUDE, encoding='utf-8') as f:
                locked = {line.strip() for line in f}
        except OSError:
            locked = set()
        for date, sci, name in rows:
            rel = os.path.join(date, species_dir(sci))
            if os.path.join(rel, name) in locked:
                continue
            wanted.add(os.path.join(rel, name))
            try:
                staged += move(os.path.join(BY_DATE, rel), os.path.join(PURGE, rel), name)
            except OSError as e:
                failed += 1
                print(f'{rel}/{name}: {e}', file=sys.stderr)
        back, back_failed = stage_back(wanted)
        staged_back += back
        failed += back_failed
    finally:
        con.close()
    try:
        days = int(setting('IGNORED_PURGE_DAYS', '5') or 0)
    except ValueError:
        days = 5
    purged = purge_old(days)
    summary = f'{staged} clips moved to Purge, {staged_back} back to By_Date, {failed} failed, {purged} files older than {days} days deleted'
    # cron throws the output away: the outcome goes to syslog (tag birdsuite-purge_stage)
    syslog.openlog('birdsuite-purge_stage')
    syslog.syslog(syslog.LOG_WARNING if failed else syslog.LOG_INFO, summary)
    if '--print' in sys.argv or failed:
        print(summary)


if __name__ == '__main__':
    main()
