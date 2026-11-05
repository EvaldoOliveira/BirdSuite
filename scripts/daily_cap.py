"""Daily cap of clips per species (owner 2026-10-24: a station that hears thousands of the same species a day).

MAX_FILES_SPECIES_DAY = N (Tools › Data › Life cycle of the sounds; 0 = off): in every day folder of By_Date, each species
keeps its N clips of highest confidence; the others lose their audio and spectrograms (with FULL_DISK=external the
audio is moved to the external folder instead, clip_dispose.py, US-128). The detections stay in the
database and keep counting. The protected clips (config/scripts/disk_check_exclude.txt: the locks, the best N of every
species and the clips reviewed Yes, refreshed by purge_protection.py right before) are never deleted, and do not take
a place of the N. Run nightly by disk_species_clean.sh.
Usage: daily_cap.py [--print]
"""
import os
import re
import sys
import syslog

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
from clip_dispose import Disposer, ExternalUnavailable
from utils import core

BY_DATE = core.BS_BY_DATE
LOCKS = core.BS_DISK_EXCLUDE
DATE = re.compile(r'^\d{4}-\d{2}-\d{2}$')
CONF = re.compile(r'-conf-(\d+)\.')
LEGACY = re.compile(r'-(\d{1,3})-\d{4}-\d{2}-\d{2}-')


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


def confidence(name):
    """The clip's confidence from its name: -conf-NN. (BirdSuite) or Species-NN-date (BirdNET-Pi); 0 when unknown."""
    m = CONF.search(name) or LEGACY.search(name)
    return int(m.group(1)) if m else 0


def read_cap():
    """MAX_FILES_SPECIES_DAY, 0 when off or not a number; 0 too when PURGE_PROTECT_TOP_N = 0 (keeps everything)."""
    try:
        cap = int(setting('MAX_FILES_SPECIES_DAY', '50') or 0)
    except ValueError:
        cap = 0
    if setting('PURGE_PROTECT_TOP_N', '50').strip() == '0':  # 0 keeps everything (owner 2026-10-24)
        return 0
    return cap


def read_locks():
    try:
        with open(LOCKS, encoding='utf-8') as f:
            return {line.strip() for line in f}
    except OSError:
        return set()


def species_folders():
    """(date, species, folder) of every species folder of By_Date, the days in order."""
    for date in sorted(os.listdir(BY_DATE)):
        day = os.path.join(BY_DATE, date)
        if not DATE.match(date) or not os.path.isdir(day):
            continue
        for sp in os.listdir(day):
            d = os.path.join(day, sp)
            if os.path.isdir(d):
                yield date, sp, d


def over_cap(date, sp, d, cap, locked):
    """The clips of one species folder beyond the cap, the least confident ones (locked clips never counted)."""
    clips = [f for f in os.listdir(d) if not f.endswith(('.png', '.jpg')) and os.path.isfile(os.path.join(d, f))
             and f'{date}/{sp}/{f}' not in locked]
    if len(clips) <= cap:
        return []
    clips.sort(key=lambda f: (confidence(f), f), reverse=True)
    return clips[cap:]


def dispose_clip(disposer, d, f):
    """The clip and its spectrograms disposed of; returns (clips removed, failures)."""
    deleted = failed = 0
    for name in (f, f + '.png', f + '.jpg'):
        p = os.path.join(d, name)
        if os.path.exists(p):
            try:
                disposer.dispose(p)
                deleted += name == f
            except OSError as e:
                failed += 1
                print(f'{p}: {e}', file=sys.stderr)
    return deleted, failed


def main():
    cap = read_cap()
    if cap <= 0 or not os.path.isdir(BY_DATE):
        return
    locked = read_locks()
    syslog.openlog('birdsuite-daily_cap')
    try:
        disposer = Disposer()
    except ExternalUnavailable as e:
        # the external folder is not usable: nothing is removed tonight (US-128)
        syslog.syslog(syslog.LOG_WARNING, f'daily cap {cap} per species: external backup unavailable, nothing removed: {e}')
        if '--print' in sys.argv:
            print(e)
        return
    deleted = failed = 0
    for date, sp, d in species_folders():
        for f in over_cap(date, sp, d, cap, locked):
            n, bad = dispose_clip(disposer, d, f)
            deleted += n
            failed += bad
    where = f'moved to {disposer.root}' if disposer.ext else 'deleted'
    summary = f'daily cap {cap} per species: {disposer.why}{deleted} clips {where}, {failed} failed'
    syslog.syslog(syslog.LOG_WARNING if failed else syslog.LOG_INFO, summary)
    if '--print' in sys.argv or failed:
        print(summary)

if __name__ == '__main__':
    main()
