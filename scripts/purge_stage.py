"""Nightly staging of the audio to purge (owner 2026-10-22).

The detections answered with a cause (Dog, Human, Engine, Noise, Ignore and the older insect, rain, mechanical) keep their line in the
database, so every statistic still counts them; once a day (disk_species_clean.sh, 02:00) this script moves their clip
and spectrograms from By_Date to ~/BirdSongs/Extracted/Purge/<date>/<species>/, where Species › Purge Files deletes
them for good. A clip whose answer changed since (cleared, Yes, another cause no longer in the list) goes back to By_Date.
Then the staged audio older than IGNORED_PURGE_DAYS days (by the detection's date; default 5, 0 = off; Tools › Data ›
Life cycle of the sounds, owner 2026-10-24) is deleted for good: the detections keep counting.
Usage: purge_stage.py [--print]
"""
import datetime
import os
import re
import shutil
import sqlite3
import sys
import syslog

HOME = os.path.expanduser('~')
EXTRACTED = os.path.join(HOME, 'BirdSongs', 'Extracted')
BY_DATE = os.path.join(EXTRACTED, 'By_Date')
PURGE = os.path.join(EXTRACTED, 'Purge')
DB = os.path.join(HOME, 'BirdNET-Pi', 'config', 'data', 'birds.db')
CAUSES = ('dog', 'human', 'engine', 'noise', 'ignore', 'insect', 'rain', 'mechanical')  # with the older causes: all of Ignored


def species_dir(sci, com, file_name):
    # the clip's folder (owner 2026-10-24, the rule of utils/helpers.py species_folder): the new layout
    # <Sci_safe>/<Sci_safe>_<Com_safe>-..., the old one <Com_safe>/<Com_safe>-... until migrated
    s = sci.replace("'", '').replace(' ', '_')
    return s if s and file_name.startswith(s + '-') else com.replace("'", '').replace(' ', '_')


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
            with open(os.path.join(HOME, 'BirdNET-Pi', 'config', f), encoding='utf-8') as fh:
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
        for root, dirs, files in os.walk(os.path.join(PURGE, date), topdown=False):
            for f in files:
                try:
                    os.remove(os.path.join(root, f))
                    n += 1
                except OSError as e:
                    print(f'{root}/{f}: {e}', file=sys.stderr)
            try:
                os.rmdir(root)
            except OSError:
                pass
    return n


def main():
    if not os.path.exists(DB):
        return
    con = sqlite3.connect(f'file:{DB}?mode=ro', uri=True, timeout=30)
    try:
        if not con.execute("SELECT 1 FROM sqlite_master WHERE type='table' AND name='detection_reviews'").fetchone():
            return
        rows = con.execute(f"""SELECT d.Date, d.Sci_Name, d.Com_Name, d.File_Name FROM detections d JOIN detection_reviews r ON r.File_Name = d.File_Name
            WHERE r.Verdict = 'no' AND r.Reason IN ({','.join('?' * len(CAUSES))})""", CAUSES).fetchall()
        staged = staged_back = failed = 0
        wanted = set()
        # the protected clips (locks, top N; config/scripts/disk_check_exclude.txt) stay where they are
        try:
            with open(os.path.join(HOME, 'BirdNET-Pi', 'config', 'scripts', 'disk_check_exclude.txt'), encoding='utf-8') as f:
                locked = {line.strip() for line in f}
        except OSError:
            locked = set()
        for date, sci, com, name in rows:
            rel = os.path.join(date, species_dir(sci, com, name))
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
