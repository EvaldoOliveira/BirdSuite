#!/usr/bin/env python3
################################################################################
# tests/migration_test.py - THE MIGRATION FROM BIRDNET-PI ON THE SAME CARD
#
# PURPOSE:
#   The migration from BirdNET-Pi on the same card (US-01, US-05; owner 2026-11-02), on simulated homes in a
#   temporary folder: a BirdNET-Pi tree (program folder with scripts/birds.db, birdnet.conf, lists, apprise
#   file, a unit file; data folder with clips by the common name) next to a BirdSuite program folder, then
#   scripts/migration.py (detection, the space decision of both branches with a given free space, prepare copy
#   / move, tick), location_data.py migrate (copy and move) and the root helper's migration-remove /
#   migration-back on the files.
#
# DEPENDENCIES:
#   - scripts/utils/core as corelib.py
#   - scripts/migration.py
#   - scripts/location_data.py
#   - scripts/createdb.sh
#   - scripts/core.sh
#   - birdsuite-root (sudo helper)
#
# USAGE:
#   python3 tests/migration_test.py <arguments> (see main)
################################################################################

"""The migration from BirdNET-Pi on the same card (US-01, US-05; owner 2026-11-02), on simulated homes in a temporary
folder: a BirdNET-Pi tree (program folder with scripts/birds.db, birdnet.conf, lists, apprise file, a unit file; data
folder with clips by the common name) next to a BirdSuite program folder, then scripts/migration.py (detection, the
space decision of both branches with a given free space, prepare copy / move, tick), location_data.py migrate (copy
and move) and the root helper's migration-remove / migration-back on the files.

Nothing of the real station is touched and nothing runs as root: sudo is a stub on PATH that only logs; the root helper
runs from a copy whose fixed paths (root's lib, the station user's home, systemctl, the root-owner checks) point into
the temporary folder. Usage: python3 tests/migration_test.py (exit 1 when a check fails)."""
import json
import os
import shutil
import sqlite3
import subprocess
import sys
import tarfile
import tempfile
import time
from pathlib import Path

REPO = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
sys.path.insert(0, os.path.join(REPO, 'scripts'))
from utils import core as corelib  # noqa: E402

CASA = {'id': 1, 'name': 'Casa', 'builtin': 'home', 'lat': -23.5475, 'lon': -46.6361, 'radius_km': 1, 'timezone': 'America/Sao_Paulo', 'species_list': ''}
TEST = {'id': 2, 'name': 'Test / Playback', 'builtin': 'test', 'lat': -23.5475, 'lon': -46.6361, 'radius_km': 0.05, 'timezone': 'America/Sao_Paulo',
    'species_list': ''}
# three BirdNET-Pi detections (common names in English, as BirdNET-Pi names its clips)
UPSTREAM_ROWS = [
    ('2026-10-01', '06:00:01', 'Turdus rufiventris', 'Rufous-bellied Thrush', 0.91),
    ('2026-10-01', '06:10:00', 'Pitangus sulphuratus', 'Great Kiskadee', 0.82),
    ('2026-10-02', '07:00:00', 'Turdus rufiventris', 'Rufous-bellied Thrush', 0.77),
]
failures = []


def check(cond, what):
    print(('  ok   ' if cond else '  FAIL ') + what)
    if not cond:
        failures.append(what)


def clip_name(com, conf, date, tm):
    return f'{com.replace(" ", "_")}-{int(conf * 100)}-{date}-birdnet-{tm}.mp3'


class Home:
    """A home with BirdNET-Pi and the BirdSuite program folder as the installer leaves it before prepare."""

    def __init__(self, root, name, upstream=True):
        self.home = os.path.join(root, name)
        self.L = corelib.load(home=self.home)
        self.up = os.path.join(self.home, self.L['BS_UPSTREAM_PROG_DIR'])
        self.old_prog = self.up + self.L['BS_OLD_SUFFIX']
        self.recs = self.L['BS_RECS']
        self.old_data = self.recs + self.L['BS_OLD_SUFFIX']
        self.scripts = self.L['BS_SCRIPTS']
        self.state = self.L['BS_STATE']
        os.makedirs(self.home)
        if upstream:
            self.make_upstream()
        # the program folder of BirdSuite (the clone moved in place): scripts copied, model and catalogues linked
        shutil.copytree(os.path.join(REPO, 'scripts'), self.scripts,
                        ignore=shutil.ignore_patterns('__pycache__', 'gotty*', '*.whl', '*.db', '*.sha256'))
        os.symlink(os.path.join(REPO, 'model'), self.L['BS_MODEL'])
        os.symlink(os.path.join(REPO, 'lang'), os.path.join(self.L['BS_PROG'], 'lang'))
        os.makedirs(self.L['BS_CONFIG'])
        shutil.copy(os.path.join(REPO, 'config', 'factory_defaults.conf'), self.L['BS_CONFIG'])

    def make_upstream(self):
        os.makedirs(os.path.join(self.up, 'scripts'))
        os.makedirs(os.path.join(self.up, 'templates'))
        with open(os.path.join(self.up, 'birdnet.conf'), 'w') as f:
            f.write('LATITUDE=-23.5475\nLONGITUDE=-46.6361\nDATABASE_LANG=en\n')
        with open(os.path.join(self.up, 'apprise.txt'), 'w') as f:
            f.write('mailto://example\n')
        with open(os.path.join(self.up, 'scripts', 'include_species_list.txt'), 'w') as f:
            f.write('Turdus rufiventris_Rufous-bellied Thrush\n')
        with open(os.path.join(self.up, 'scripts', 'server.py'), 'w') as f:
            f.write('# code, not a setting\n')
        with open(os.path.join(self.up, 'templates', 'chart_viewer.service'), 'w') as f:
            f.write('[Service]\n')
        con = sqlite3.connect(os.path.join(self.up, 'scripts', 'birds.db'))
        con.execute('CREATE TABLE detections (Date DATE, Time TIME, Sci_Name VARCHAR(100) NOT NULL, Com_Name VARCHAR(100) NOT NULL, '
                    'Confidence FLOAT, Lat FLOAT, Lon FLOAT, Cutoff FLOAT, Week INT, Sens FLOAT, Overlap FLOAT, File_Name VARCHAR(100) NOT NULL)')
        for date, tm, sci, com, conf in UPSTREAM_ROWS:
            name = clip_name(com, conf, date, tm)
            con.execute('INSERT INTO detections VALUES (?,?,?,?,?,?,?,?,?,?,?,?)', (date, tm, sci, com, conf, CASA['lat'], CASA['lon'], 0.7, 40, 1.25,
                0.0, name))
            d = os.path.join(self.L['BS_BY_DATE'], date, com.replace(' ', '_'))
            os.makedirs(d, exist_ok=True)
            for ext in ('', '.png'):
                with open(os.path.join(d, name + ext), 'w') as f:
                    f.write(name + ext + ' ' * 4000)
        con.commit()
        con.close()

    def finish_install(self):
        """What the installation makes after prepare: settings, locations, a fresh data folder, the database."""
        L = self.L
        for d in (L['BS_DATA'], L['BS_STATE'], L['BS_BY_DATE'], L['BS_LOCATIONS']):
            os.makedirs(d, exist_ok=True)
        with open(L['BS_CONF'], 'w') as f:
            f.write('SITE_NAME="Mig"\nUI_LANG=en\nDATABASE_LANG=en\nLATITUDE=-23.5475\nLONGITUDE=-46.6361\n')
        with open(L['BS_LOCATIONS_FILE'], 'w') as f:
            json.dump([CASA, TEST], f)
        subprocess.run(['bash', os.path.join(self.scripts, 'createdb.sh')], env=dict(self.env()), check=True, capture_output=True)

    def env(self, **kw):
        e = {k: v for k, v in os.environ.items() if k != 'BS_HOME'}
        e.update(HOME=self.home, PATH=STUB_BIN + os.pathsep + os.environ.get('PATH', ''))
        e.update(kw)
        return e

    def py(self, script, *args):
        return subprocess.run([sys.executable, os.path.join(self.scripts, script)] + [str(a) for a in args],
                              env=self.env(), capture_output=True, text=True, cwd=self.scripts, check=False)

    def record(self):
        try:
            return json.load(open(self.L['BS_MIGRATION']))
        except (OSError, ValueError):
            return None

    def rows(self):
        con = sqlite3.connect(self.L['BS_DB'])
        con.row_factory = sqlite3.Row
        out = [dict(r) for r in con.execute('SELECT * FROM detections ORDER BY Date, Time')]
        con.close()
        return out

    def clip_ok(self, r):
        s = r['Sci_Name'].replace(' ', '_')
        d = os.path.join(self.L['BS_BY_DATE'], r['Date'], s)
        return r['File_Name'].startswith(s + '-') and all(os.path.isfile(os.path.join(d, r['File_Name'] + e)) for e in ('', '.png'))

    def upstream_clips(self, base):
        return sorted(os.path.relpath(os.path.join(r, f), base) for r, _, fs in os.walk(base) for f in fs)


def write_stub_bin(d):
    """sudo and systemctl that only log (a sudo'd tee reads its input; nothing runs as root)."""
    os.makedirs(d)
    with open(os.path.join(d, 'sudo'), 'w') as f:
        f.write('#!/bin/sh\necho "sudo $*" >> "$STUB_LOG"\n[ "$1" = -n ] && shift\n[ "$1" = tee ] && cat > /dev/null\nexit 0\n')
    with open(os.path.join(d, 'systemctl'), 'w') as f:
        f.write('#!/bin/sh\necho "systemctl $*" >> "$STUB_LOG"\nexit 0\n')
    for n in ('sudo', 'systemctl'):
        os.chmod(os.path.join(d, n), 0o755)


def root_helper(root, home):
    """The root helper with its fixed paths moved into the temporary folder: root's lib (its core definitions with the
    lib, bin and unit folders here too), the station user's home, systemctl, and the root-owner checks (this user)."""
    lib = os.path.join(root, 'lib-' + os.path.basename(home.home))
    os.makedirs(lib)
    for k in ('bin', 'units'):
        os.makedirs(os.path.join(lib, k))
    env_txt = Path(os.path.join(REPO, 'scripts', 'core.env')).read_text()
    for key, val in (('BS_LIB', lib), ('BS_BIN', os.path.join(lib, 'bin')), ('BS_UNIT_DIR', os.path.join(lib, 'units'))):
        env_txt = env_txt.replace(f'\n{key}=', f'\n{key}={val}\n#{key}=', 1)
    Path(os.path.join(lib, 'core.env')).write_text(env_txt)
    shutil.copy(os.path.join(REPO, 'scripts', 'core.sh'), lib)
    Path(os.path.join(lib, 'station_user')).write_text(os.environ.get('USER', 'pi') + '\n')
    src = Path(os.path.join(REPO, 'scripts', 'birdsuite-root')).read_text()
    src = src.replace('/usr/local/lib/birdsuite', lib)
    src = src.replace('home=$(getent passwd "$station_user" | cut -d: -f6)', f'home={home.home}')
    src = src.replace('/usr/bin/systemctl', os.path.join(STUB_BIN, 'systemctl'))
    src = src.replace('" = 0 ]', '" = "$(id -u)" ]')
    path = os.path.join(lib, 'birdsuite-root')
    Path(path).write_text(src)
    os.chmod(path, 0o755)
    return lib, path


def run_root(home, helper, task):
    return subprocess.run(['bash', helper, task], env=home.env(), capture_output=True, text=True, check=False)


def stub_log():
    try:
        return open(os.environ['STUB_LOG']).read()
    except OSError:
        return ''


def plan(home, free_kb):
    r = home.py('migration.py', 'plan', '--json', '--free-kb', free_kb)
    return r.returncode, (json.loads(r.stdout) if r.stdout.strip() else {})


def wait_job(home, secs=60):
    end = time.time() + secs
    while time.time() < end:
        try:
            j = json.loads(Path(os.path.join(home.state, 'location_job.json')).read_text())
            if j.get('status') in ('done', 'error', 'cancelled'):
                return j
        except (OSError, ValueError):
            pass
        time.sleep(0.3)
    return {}


def main():
    global STUB_BIN
    root = tempfile.mkdtemp(prefix='birdsuite-migration-')
    STUB_BIN = os.path.join(root, 'stub-bin')
    write_stub_bin(STUB_BIN)
    os.environ['STUB_LOG'] = os.path.join(root, 'stub.log')
    try:
        print('1. Detection')
        none = Home(root, 'none', upstream=False)
        rc, p = plan(none, 10 ** 9)
        check(rc == 0 and p.get('decision') == 'none', 'a home without BirdNET-Pi: no migration (exit 0)')
        c = Home(root, 'copy')
        rc, p = plan(c, 10 ** 9)
        check(rc == 10 and p.get('upstream') == c.up and p.get('db_bytes', 0) > 0 and p.get('clips_bytes', 0) > 0,
            'BirdNET-Pi found: its folder, database and clips counted')
        os.makedirs(os.path.join(none.home, none.L['BS_UPSTREAM_PROG_DIR'], 'scripts'))
        shutil.copy(os.path.join(c.up, 'scripts', 'birds.db'), os.path.join(none.home, none.L['BS_UPSTREAM_PROG_DIR'], 'scripts'))
        rc, p = plan(none, 10 ** 9)
        check(rc == 0, 'a database without birdnet.conf is not BirdNET-Pi')
        open(os.path.join(none.home, none.L['BS_UPSTREAM_PROG_DIR'], 'birdnet.conf'), 'w').close()
        open(os.path.join(none.home, none.L['BS_UPSTREAM_PROG_DIR'], 'scripts', 'core.env'), 'w').close()
        rc, p = plan(none, 10 ** 9)
        check(rc == 0, 'a folder with core.env (BirdSuite of an older layout) is not BirdNET-Pi')
        # the units of BirdNET-Pi: links into its program folder (a units folder of the test in place of the system's)
        units = os.path.join(root, 'units')
        os.makedirs(units)
        os.symlink(os.path.join(c.up, 'templates', 'chart_viewer.service'), os.path.join(units, 'chart_viewer.service'))
        open(os.path.join(units, 'other.service'), 'w').close()
        r = subprocess.run([sys.executable, '-c', 'import sys; sys.path.insert(0, sys.argv[1]); import migration; migration.UNIT_DIRS = [sys.argv[2]]; '
                            'print(",".join(migration.upstream_units(migration.UPSTREAM)))', c.scripts, units],
                           env=c.env(), capture_output=True, text=True, check=False)
        check(r.stdout.strip() == 'chart_viewer.service', 'the BirdNET-Pi units found by their link into its folder: ' + r.stdout.strip())

        print('2. Space decision')
        rc, p = plan(c, 0)
        need_copy, need_move, freed = p['need_copy'], p['need_move'], p['freed_move']
        check(need_copy == p['install_bytes'] + p['db_bytes'] + p['clips_bytes'] + p['margin_bytes'], 'copy = BirdSuite + database + clips + margin')
        rc, p = plan(c, need_copy // 1024 + 1)
        check(rc == 10 and p['decision'] == 'copy', 'enough free space: copy (exit 10)')
        rc, p = plan(c, (need_move - freed) // 1024 + 1)
        check(rc == 11 and p['decision'] == 'move', 'too little for a copy, enough when moving: move, asked (exit 11)')
        rc, p = plan(c, 0)
        check(rc == 12 and p['decision'] == 'nospace', 'too little even when moving: refused (exit 12)')
        r = c.py('migration.py', 'prepare', 'copy', '--free-kb', 0)
        check(r.returncode != 0 and os.path.isdir(c.up) and c.record() is None, 'prepare copy without the space: refused, nothing changed')

        print('3. Copy: prepare, import after the first setup, remove')
        before = c.upstream_clips(c.L['BS_BY_DATE'])
        r = c.py('migration.py', 'prepare', 'copy', '--free-kb', need_copy // 1024 + 1)
        m = c.record() or {}
        check(r.returncode == 0 and os.path.isdir(c.old_prog) and os.path.isdir(c.old_data) and not os.path.exists(c.up) and not os.path.exists(c.recs),
              'BirdNET-Pi kept whole as *.old, the new data folder free')
        check(m.get('mode') == 'copy' and m.get('import') == 'pending' and [k['path'] for k in m.get('kept', [])] == [c.old_prog, c.old_data]
              and all(k['bytes'] > 0 for k in m['kept']) and m.get('started') == time.strftime('%Y-%m-%d'),
                  'record: copy, pending, the kept folders and sizes, the date')
        check('tar -czPf' in stub_log() and 'system.tar.gz' in stub_log(), "root's record of the system files made (sudo tar, stubbed)")
        check(not os.path.exists(c.L['BS_UPSTREAM_ARCHIVE']), 'no settings archive in copy mode')
        c.finish_install()
        open(c.L['BS_FIRSTRUN'], 'w').close()
        r = c.py('migration.py', 'tick')
        check(r.stdout.strip() == 'nothing to do' and c.record()['import'] == 'pending', 'first setup pending: the import waits')
        os.remove(c.L['BS_FIRSTRUN'])
        r = c.py('migration.py', 'tick')
        j = wait_job(c)
        rows = c.rows()
        check('import started' in r.stdout and j.get('status') == 'done' and j.get('action') == 'migrate',
            'tick started the import in the background: ' + (r.stdout + str(j.get('error', ''))).strip())
        check(len(rows) == 3 and all(x['Location'] == 1 and c.clip_ok(x) for x in rows), 'three detections in Casa, clips by the scientific name')
        check(rows and 'Com_Name' not in rows[0], 'the common names of BirdNET-Pi not imported (the species by its scientific name)')
        check(c.upstream_clips(os.path.join(c.old_data, os.path.relpath(c.L['BS_BY_DATE'], c.recs))) == before,
            'copy: the BirdNET-Pi clips are all still there')
        m = c.record()
        check(m['import'] == 'done' and m['imported'] == 3, 'record: import done, 3 imported')
        r = c.py('location_data.py', 'migrate')
        check(r.returncode != 0 and len(c.rows()) == 3, 'a second import is refused, nothing added twice')
        lib, helper = root_helper(root, c)
        r = run_root(c, helper, 'migration-back')
        check(r.returncode != 0 and os.path.isdir(c.old_prog), "Go back without root's record: refused")
        r = run_root(c, helper, 'migration-remove')
        check(r.returncode == 0 and not os.path.exists(c.old_prog) and not os.path.exists(c.old_data) and c.record() is None
              and os.path.isdir(c.L['BS_BY_DATE']) and len(c.rows()) == 3, 'Remove: the *.old folders and the record gone, BirdSuite untouched')

        print('4. Copy, then Go back')
        b = Home(root, 'back')
        rc, p = plan(b, 10 ** 9)
        r = b.py('migration.py', 'prepare', 'copy', '--free-kb', 10 ** 9)
        b.finish_install()
        lib, helper = root_helper(root, b)
        # root's record as prepare makes it on a station: the units, and the system files (here one file of the test)
        rec = os.path.join(lib, 'migration')
        os.makedirs(rec)
        Path(os.path.join(rec, 'units')).write_text('chart_viewer.service\nbad name;rm\n')
        os.symlink(os.path.join(b.up, 'templates', 'chart_viewer.service'), os.path.join(lib, 'units', 'chart_viewer.service'))
        crontab = os.path.join(root, 'etc-crontab')
        Path(crontab).write_text('upstream lines\n')
        # with absolute names, as prepare makes it (tar -P)
        subprocess.run(['tar', '-czPf', os.path.join(rec, 'system.tar.gz'), crontab], check=True)
        Path(crontab).write_text('birdsuite lines\n')
        open(os.path.join(root, 'stub.log'), 'w').close()
        r = run_root(b, helper, 'migration-back')
        log = stub_log()
        check(r.returncode == 0, 'Go back ran: ' + (r.stderr or r.stdout).strip()[-200:])
        check(os.path.isfile(os.path.join(b.up, 'scripts', 'birds.db')) and not os.path.exists(b.old_prog), 'BirdNET-Pi program folder back in place')
        check(os.path.isdir(os.path.join(b.L['BS_BY_DATE'], '2026-10-01', 'Rufous-bellied_Thrush')) and os.path.isdir(b.recs + '.birdsuite'),
              'the data folder of BirdNET-Pi back, the one of BirdSuite kept as *.birdsuite')
        check(Path(crontab).read_text() == 'upstream lines\n', "the system files restored from root's record")
        check('disable --now' in log and b.L['BS_SVC_ANALYSIS'] + '.service' in log and b.L['BS_SVC_WEB'] + '.service' not in log,
              'BirdSuite services disabled (not the web server)')
        check('enable chart_viewer.service' in log and 'bad name' not in log, 'the BirdNET-Pi unit enabled again, a bad name ignored')
        check(b.record() is None and not os.path.exists(rec), 'the records removed')
        time.sleep(6)
        check('reboot' in stub_log(), 'the station restarts')

        print('5. Move: prepare (settings archive), remove refused before the import, import moves, remove')
        mv = Home(root, 'move')
        rc, p = plan(mv, 0)
        free = (p['need_move'] - p['freed_move']) // 1024 + 1
        before = mv.upstream_clips(mv.L['BS_BY_DATE'])
        open(os.path.join(root, 'stub.log'), 'w').close()
        r = mv.py('migration.py', 'prepare', 'move', '--free-kb', free)
        m = mv.record() or {}
        check(r.returncode == 0 and not os.path.exists(mv.up) and os.path.isdir(mv.old_data) and m.get('mode') == 'move',
            'the old program removed, the data folder kept as *.old')
        with tarfile.open(mv.L['BS_UPSTREAM_ARCHIVE']) as t:
            names = sorted(t.getnames())
        check(names == ['apprise.txt', 'birdnet.conf', 'scripts/include_species_list.txt'],
            'BIRDNET.OLD.gz: birdnet.conf, the lists, the apprise file (no code): ' + ', '.join(names))
        check(os.path.isfile(os.path.join(mv.L['BS_MIGRATION_DIR'], 'birds.db')) and m['source']['db'].startswith(mv.L['BS_MIGRATION_DIR']),
            'the database kept aside in state/migration/')
        check([k['path'] for k in m['kept']] == [mv.L['BS_UPSTREAM_ARCHIVE'], mv.old_data], 'record: the archive and the old data folder kept')
        check('tar -czPf' not in stub_log(), 'no system files kept in move mode (Go back is not possible)')
        lib, helper = root_helper(root, mv)
        r = run_root(mv, helper, 'migration-remove')
        check(r.returncode != 0 and os.path.isdir(mv.old_data) and mv.record() is not None, 'Remove before the import: refused, nothing deleted')
        r = run_root(mv, helper, 'migration-back')
        check(r.returncode != 0 and mv.record() is not None, 'Go back after a move: refused')
        mv.finish_install()
        r = mv.py('location_data.py', 'migrate')
        rows = mv.rows()
        old_by_date = os.path.join(mv.old_data, os.path.relpath(mv.L['BS_BY_DATE'], mv.recs))
        check(r.returncode == 0 and len(rows) == 3 and all(x['Location'] == 1 and mv.clip_ok(x) for x in rows),
            'three detections in Casa, clips by the scientific name')
        check(len(before) == 6 and mv.upstream_clips(old_by_date) == [], 'move: the clips left the old folder (and its emptied day folders)')
        m = mv.record()
        check(m['import'] == 'done' and m['imported'] == 3 and not os.path.exists(mv.L['BS_MIGRATION_DIR']), 'record: done; the database aside deleted')
        r = run_root(mv, helper, 'migration-remove')
        check(r.returncode == 0 and not os.path.exists(mv.old_data) and not os.path.exists(mv.L['BS_UPSTREAM_ARCHIVE']) and mv.record() is None,
              'Remove: the old data folder, BIRDNET.OLD.gz and the record gone')

        print('6. The end of the test period')
        t = Home(root, 'period')
        t.py('migration.py', 'prepare', 'copy', '--free-kb', 10 ** 9)
        rec = t.record()
        rec.update(started_at=int(time.time()) - 31 * 86400, **{'import': 'done'})
        Path(t.L['BS_MIGRATION']).write_text(json.dumps(rec))
        r1 = t.py('migration.py', 'tick')
        r2 = t.py('migration.py', 'tick')
        s = json.loads(t.py('migration.py', 'status').stdout)
        check('test period ended' in r1.stdout and t.record().get('notified') == time.strftime('%Y-%m-%d'), 'after 30 days: notified')
        check('test period ended' not in r2.stdout, 'notified once only')
        check(s['days_left'] == 0 and s['days_gone'] == 31, 'status: 0 days left, 31 gone')
    finally:
        shutil.rmtree(root, ignore_errors=True)
    print('\n' + ('ALL PASSED' if not failures else f'{len(failures)} FAILED: ' + '; '.join(failures)))
    sys.exit(1 if failures else 0)


STUB_BIN = ''

if __name__ == '__main__':
    main()
