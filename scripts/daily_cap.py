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
from clip_dispose import Disposer, ExternalUnavailable  # noqa: E402

HOME = os.path.expanduser('~')
BY_DATE = os.path.join(HOME, 'BirdSongs', 'Extracted', 'By_Date')
LOCKS = os.path.join(HOME, 'BirdNET-Pi', 'config', 'scripts', 'disk_check_exclude.txt')
DATE = re.compile(r'^\d{4}-\d{2}-\d{2}$')
CONF = re.compile(r'-conf-(\d+)\.')
LEGACY = re.compile(r'-(\d{1,3})-\d{4}-\d{2}-\d{2}-')


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


def confidence(name):
    """The clip's confidence from its name: -conf-NN. (BirdSuite) or Species-NN-date (BirdNET-Pi); 0 when unknown."""
    m = CONF.search(name) or LEGACY.search(name)
    return int(m.group(1)) if m else 0


def main():
    try:
        cap = int(setting('MAX_FILES_SPECIES_DAY', '50') or 0)
    except ValueError:
        cap = 0
    if cap <= 0 or not os.path.isdir(BY_DATE):
        return
    if setting('PURGE_PROTECT_TOP_N', '50').strip() == '0':  # 0 keeps everything (owner 2026-10-24)
        return
    try:
        with open(LOCKS, encoding='utf-8') as f:
            locked = {line.strip() for line in f}
    except OSError:
        locked = set()
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
    for date in sorted(os.listdir(BY_DATE)):
        day = os.path.join(BY_DATE, date)
        if not DATE.match(date) or not os.path.isdir(day):
            continue
        for sp in os.listdir(day):
            d = os.path.join(day, sp)
            if not os.path.isdir(d):
                continue
            clips = [f for f in os.listdir(d) if not f.endswith(('.png', '.jpg')) and os.path.isfile(os.path.join(d, f))
                     and f'{date}/{sp}/{f}' not in locked]
            if len(clips) <= cap:
                continue
            clips.sort(key=lambda f: (confidence(f), f), reverse=True)
            for f in clips[cap:]:
                for name in (f, f + '.png', f + '.jpg'):
                    p = os.path.join(d, name)
                    if os.path.exists(p):
                        try:
                            disposer.dispose(p)
                            deleted += name == f
                        except OSError as e:
                            failed += 1
                            print(f'{p}: {e}', file=sys.stderr)
    summary = f'daily cap {cap} per species: {disposer.why}{deleted} clips ' + (f'moved to {disposer.root}' if disposer.ext else 'deleted') + f', {failed} failed'
    syslog.syslog(syslog.LOG_WARNING if failed else syslog.LOG_INFO, summary)
    if '--print' in sys.argv or failed:
        print(summary)


if __name__ == '__main__':
    main()
