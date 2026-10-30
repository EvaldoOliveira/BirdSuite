"""Species names of the clips and the database (owner 2026-10-24: clips and folders by the scientific name only).

The layout is By_Date/<date>/<Sci_safe>/<Sci_safe>-<date>_<time>-..., the old one By_Date/<date>/<Com_safe>/<Com_safe>-...
(utils/helpers.py species_folder, clip_target). This script
  - renames the clips of the old layout (and their .png/.jpg) in By_Date, By_Date/shifted, Purge and Removed,
  - gives every species the common name of the Common name Language (model/l18n/labels_<lang>.json, by Sci_Name), so the
    language can change at any time (only the database and the lists: no file name holds the common name),
  - updates File_Name and Com_Name in detections, detection_reviews and deleted_detections, the manual purge locks of
    config/scripts/disk_check_exclude.txt and the common names of the species lists.
It can be stopped and run again: what is already done is skipped. The database is copied first (birds.db.pre-names, the
copy of the last run that changed something).
When nothing is left to do it writes config/data/names_migrated, so the pages do not look again (common.php
names_migration_state); a restore brings the database without it and a location import removes it.

  migrate_names.py           migrate, with progress on the output
  migrate_names.py --check   answers {"old": <clips to rename>, "relabel": <species to rename>} (JSON), no change
"""
import json
import os
import sqlite3
import sys
import time

from utils.helpers import CONFIG_DIR, DB_PATH, LISTS_DIR, CUSTOM_LISTS_DIR, clip_target, get_language, name_safe

HOME = os.path.expanduser('~')
EXTRACTED = os.path.join(HOME, 'BirdSongs', 'Extracted')
ROOTS = [os.path.join(EXTRACTED, 'By_Date'), os.path.join(EXTRACTED, 'By_Date', 'shifted'),
         os.path.join(EXTRACTED, 'Purge'), os.path.join(EXTRACTED, 'Removed')]
LOCKS = os.path.join(CONFIG_DIR, 'scripts', 'disk_check_exclude.txt')
MARKER = os.path.join(CONFIG_DIR, 'data', 'names_migrated')
TABLES = ('detections', 'detection_reviews', 'deleted_detections')


def labels():
    try:
        return get_language()
    except (OSError, ValueError, KeyError):
        return {}


def tables(con):
    have = {r[0] for r in con.execute("SELECT name FROM sqlite_master WHERE type='table'")}
    return [t for t in TABLES if t in have]


def plan(con, names):
    """{(date, file, sci, com): (old folder, new folder, new file, new com)} for every row that changes."""
    out, files = {}, {}
    for t in tables(con):  # detections first: a clip has one new name, also where a review kept another common name
        for date, sci, com, f in con.execute(f'SELECT DISTINCT Date, Sci_Name, Com_Name, File_Name FROM {t}'):
            if not f or not sci:
                continue
            new_com = names.get(sci) or com
            if (date, f) not in files:
                folder, new_file = clip_target(sci, com, f)
                files[(date, f)] = (folder, name_safe(sci), new_file)
            folder, new_folder, new_file = files[(date, f)]
            if new_file != f or new_com != com:
                out[(date, f, sci, com)] = (folder, new_folder, new_file, new_com)
    return out


def check():
    try:
        con = sqlite3.connect(f'file:{DB_PATH}?mode=ro', uri=True)
        todo = plan(con, labels())
        con.close()
    except sqlite3.Error:  # no database yet (a new station): nothing to migrate, asked again later
        print(json.dumps({'old': 0, 'relabel': 0}))
        return
    old = sum(1 for (d, f, s, c), v in todo.items() if v[2] != f)
    relabel = len({(s, c) for (d, f, s, c), v in todo.items() if v[3] != c})
    if not todo:
        try:
            open(MARKER, 'w').close()
        except OSError:
            pass
    print(json.dumps({'old': old, 'relabel': relabel}))


def move(src, dst):
    if not os.path.exists(src) or os.path.exists(dst):
        return 0
    os.makedirs(os.path.dirname(dst), exist_ok=True)
    os.rename(src, dst)
    return 1


def rename_files(todo):
    moved, renamed = 0, {}
    total = len(todo)
    for i, ((date, f, sci, com), (folder, new_folder, new_file, new_com)) in enumerate(todo.items(), 1):
        if new_file != f:
            renamed[f'{date}/{folder}/{f}'] = f'{date}/{new_folder}/{new_file}'
            for root in ROOTS:
                src_dir, dst_dir = os.path.join(root, str(date), folder), os.path.join(root, str(date), new_folder)
                for ext in ('', '.png', '.jpg'):
                    moved += move(os.path.join(src_dir, f + ext), os.path.join(dst_dir, new_file + ext))
        if i % 500 == 0 or i == total:
            print(f'  {i}/{total} detections, {moved} files renamed', flush=True)
    return moved, renamed


def remove_empty_dirs():
    for root in ROOTS:
        if not os.path.isdir(root):
            continue
        for date in os.listdir(root):
            d = os.path.join(root, date)
            if not os.path.isdir(d) or date == 'shifted':
                continue
            for sp in os.listdir(d):
                p = os.path.join(d, sp)
                if os.path.isdir(p) and not os.listdir(p):
                    os.rmdir(p)


def update_db(con, todo):
    rows = [(v[2], v[3], f, date, sci, com) for (date, f, sci, com), v in todo.items()]
    with con:
        for t in tables(con):
            if t == 'detection_reviews':
                # one answer per clip name (File_Name is its key): when the new name already has an answer (2026-10-29:
                # an import had brought it under the new name), the newer of the two stays, as Import keeps it
                con.executemany('DELETE FROM detection_reviews WHERE File_Name = ? AND COALESCE(Reviewed_At, \'\') <= '
                                '(SELECT COALESCE(MAX(Reviewed_At), \'\') FROM detection_reviews WHERE File_Name = ? AND Date = ? AND Sci_Name = ? AND Com_Name = ?)'
                                ' AND EXISTS (SELECT 1 FROM detection_reviews WHERE File_Name = ? AND Date = ? AND Sci_Name = ? AND Com_Name = ?)',
                                [(n, f, d, s_, c, f, d, s_, c) for n, _, f, d, s_, c in rows if n != f])
                con.executemany('UPDATE OR IGNORE detection_reviews SET File_Name = ?, Com_Name = ? WHERE File_Name = ? AND Date = ? AND Sci_Name = ? AND Com_Name = ?', rows)
                con.executemany('DELETE FROM detection_reviews WHERE File_Name = ? AND Date = ? AND Sci_Name = ? AND Com_Name = ? '
                                'AND EXISTS (SELECT 1 FROM detection_reviews WHERE File_Name = ?)',
                                [(f, d, s_, c, n) for n, _, f, d, s_, c in rows if n != f])
                continue
            con.executemany(f'UPDATE {t} SET File_Name = ?, Com_Name = ? WHERE File_Name = ? AND Date = ? AND Sci_Name = ? AND Com_Name = ?', rows)


def update_locks(renamed):
    if not renamed or not os.path.exists(LOCKS):
        return 0
    with open(LOCKS, encoding='utf-8') as fh:
        lines = fh.read().split('\n')
    n = 0
    for i, line in enumerate(lines):
        if line in renamed:
            lines[i] = renamed[line]
            n += 1
    if n:
        tmp = LOCKS + '.tmp'
        with open(tmp, 'w', encoding='utf-8') as fh:
            fh.write('\n'.join(lines))
        os.replace(tmp, LOCKS)
    return n


def update_lists(names):
    """The species lists hold <Sci>_<Com> lines and are matched by the scientific name: only the text changes."""
    n = 0
    for d in (LISTS_DIR, CUSTOM_LISTS_DIR):
        if not os.path.isdir(d):
            continue
        for fn in os.listdir(d):
            p = os.path.join(d, fn)
            if not fn.endswith('.txt') or not os.path.isfile(p):
                continue
            with open(p, encoding='utf-8') as fh:
                lines = fh.read().split('\n')
            changed = False
            for i, line in enumerate(lines):
                sci, sep, com = line.partition('_')
                if sep and sci in names and names[sci] != com:
                    lines[i] = f'{sci}_{names[sci]}'
                    changed = True
            if changed:
                with open(p + '.tmp', 'w', encoding='utf-8') as fh:
                    fh.write('\n'.join(lines))
                os.replace(p + '.tmp', p)
                n += 1
    return n


def migrate():
    t0 = time.time()
    names = labels()
    print(f'Common name Language: {len(names)} species names', flush=True)
    con = sqlite3.connect(DB_PATH, timeout=60)
    todo = plan(con, names)
    print(f'{len(todo)} detections to update', flush=True)
    if todo:
        print('Copying the database to birds.db.pre-names', flush=True)
        dst = sqlite3.connect(DB_PATH + '.pre-names')
        con.backup(dst)
        dst.close()
        print('Renaming the clips', flush=True)
        moved, renamed = rename_files(todo)
        print('Updating the database', flush=True)
        update_db(con, todo)
        locks = update_locks(renamed)
        print(f'{moved} files renamed, {locks} purge locks updated', flush=True)
        remove_empty_dirs()
    lists = update_lists(names)
    print(f'{lists} species lists updated', flush=True)
    left = plan(con, names)
    con.close()
    if left:
        print(f'{len(left)} detections could not be updated', flush=True)
        return 1
    open(MARKER, 'w').close()
    print(f'Done in {int(time.time() - t0)} s', flush=True)
    return 0


if __name__ == '__main__':
    if '--check' in sys.argv:
        check()
    else:
        sys.exit(migrate())
