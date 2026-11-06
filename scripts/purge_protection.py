################################################################################
# scripts/purge_protection.py - AUTOMATIC PURGE PROTECTION
#
# PURPOSE:
#   Automatic purge protection: rewrites the automatic part (##start .. ##end) of disk_check_exclude.txt.
#
# DEPENDENCIES:
#   - scripts/utils/core.py
#   - scripts/disk_check.sh
#   - scripts/disk_species_clean.sh
#   - tests/helpers.py
#
# USAGE:
#   python3 scripts/purge_protection.py <arguments> (see main)
#   Referenced by scripts/daily_cap.py, scripts/disk_check.sh, scripts/disk_species_clean.sh,
#   scripts/doctor.php, scripts/play.php
################################################################################

"""Automatic purge protection: rewrites the automatic part (##start .. ##end) of disk_check_exclude.txt.

Protected automatically, so the disk purge (disk_check.sh) and the per-species trim (disk_species_clean.sh)
never delete them:
  - the PURGE_PROTECT_TOP_N best detections (highest confidence) of every species (default 50, owner 2026-10-24; 0 = every clip protected —
    upstream protected only the best one);
  - every detection reviewed "Yes, this bird" or "Review later" (detection_reviews, Review loop; owner 2026-10-24:
    a detection still to be decided keeps its sound).
Rejected detections ("Not this bird") are never chosen as a species' best ones.
The manual locks (the padlock on a detection card) live after ##end and are kept as they are.

The lock file is shared with the purge scripts (flock), so the list is never rewritten while a purge reads it.
Exit code 0 = list refreshed; anything else = the purge must not run (fail closed).
Usage: purge_protection.py [--print]
"""
import fcntl
import os
import sqlite3
import sys

from utils import core  # the folder layout (core.env)

SCRIPTS = core.BS_SCRIPTS
CONFIG = core.BS_CONFIG
DB = os.path.join(CONFIG, 'data', 'birds.db')
LIST = os.path.join(CONFIG, 'scripts', 'disk_check_exclude.txt')
# in the station's state/, shared with play.php and the purge scripts (2026-10-23 test run: a fixed /tmp name)
LOCK = core.BS_PURGE_LOCK


def conf_value(key, default):
    """A value of BirdSuite.conf, else of the factory defaults, else default."""
    val = default
    for name in ('factory_defaults.conf', 'BirdSuite.conf'):
        try:
            with open(os.path.join(CONFIG, name)) as f:
                for line in f:
                    if line.startswith(key + '='):
                        val = line.split('=', 1)[1].strip().strip('"')
        except OSError:
            pass
    return val


def folder(sci):
    # the clip's folder: the scientific name (the rule of utils/helpers.py species_folder)
    return sci.replace(' ', '_').replace("'", '')


def protected_files(top_n):
    con = sqlite3.connect(f'file:{DB}?mode=ro', uri=True, timeout=30)
    rejected = "AND File_Name NOT IN (SELECT File_Name FROM detection_reviews WHERE Verdict = 'no')"
    rows = con.execute(f"""
        SELECT Date, Sci_Name, File_Name FROM (
          SELECT Date, Sci_Name, File_Name,
                 ROW_NUMBER() OVER (PARTITION BY Sci_Name ORDER BY Confidence DESC, Date DESC, Time DESC) AS rank
          FROM detections WHERE 1 {rejected})
        WHERE rank <= ?""", (top_n,)).fetchall()
    rows += con.execute("SELECT d.Date, d.Sci_Name, d.File_Name FROM detection_reviews r "
                        "JOIN detections d ON d.File_Name = r.File_Name WHERE r.Verdict IN ('yes', 'unsure')").fetchall()
    con.close()
    out = []
    for date, sci, file_name in rows:
        path = f'{date}/{folder(sci)}/{file_name}'
        out += [path, path + '.png', path + '.jpg']  # the spectrogram: .png while fresh, .jpg once archived
    return list(dict.fromkeys(out))


def main():
    try:
        top_n = max(0, int(conf_value('PURGE_PROTECT_TOP_N', '50')))
    except ValueError:
        top_n = 50
    if top_n == 0:
        top_n = 1 << 30  # 0 protects every clip (US-13): every rank is in the list
    # read-only when it exists: whoever created it (station user or web server), flock needs no more
    with open(LOCK, 'r' if os.path.exists(LOCK) else 'a') as lock:
        # disk_species_clean.sh holds the lock already and runs this under it (PURGE_LOCK_HELD=1): a second flock on a
        # new open of the same file waits for that holder, this very job's parent, for ever (2026-10-29: the nightly trim
        # hung from 2026-10-26, each night's run queued behind it, no clip trimmed)
        if os.environ.get('PURGE_LOCK_HELD') != '1':
            fcntl.flock(lock, fcntl.LOCK_EX)
        auto = protected_files(top_n)
        manual = []
        if os.path.isfile(LIST):
            with open(LIST) as f:
                text = f.read()
            manual = text[text.index('##end') + len('##end'):].strip('\n').splitlines() if '##end' in text else \
                [line for line in text.splitlines() if line and not line.startswith('##')]
        # a manual lock covers the clip and its spectrogram, .png or .jpg (locks older than 2026-10-22 list no .jpg)
        manual = list(dict.fromkeys(x for m in manual if m for x in (
            [m] if m.endswith(('.png', '.jpg')) else [m, m + '.png', m + '.jpg'])))
        tmp = LIST + '.tmp'
        with open(tmp, 'w') as f:
            f.write('##start\n' + '\n'.join(auto) + '\n##end\n' + ''.join(line + '\n' for line in manual if line))
        os.replace(tmp, LIST)
    if '--print' in sys.argv:
        print(f'{len(auto) // 3} detections protected automatically (top {top_n} per species + confirmed), '
              f'{len([m for m in manual if m and not m.endswith((".png", ".jpg"))])} manual locks')


if __name__ == '__main__':
    main()
