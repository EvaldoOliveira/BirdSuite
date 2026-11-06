#!/usr/bin/env python3
################################################################################
# scripts/migration.py - THE MIGRATION FROM BIRDNET-PI ON THE SAME CARD
#
# PURPOSE:
#   The migration from BirdNET-Pi on the same card (US-01, US-05; owner 2026-11-02). The installer
#   (newinstaller.sh) finds an installation of the original project in the home (its program folder, core.env
#   BS_UPSTREAM_PROG_DIR, with scripts/birds.db and a birdnet.conf, and no core.env of BirdSuite) and counts
#   the space; the pages show the record (System › Status, Migration); the import itself is location_data.py
#   migrate. Runs as the station user.
#
# DEPENDENCIES:
#   - scripts/utils/i18n.py
#   - scripts/utils/notifications.py
#   - scripts/utils/core.py
#   - newinstaller.sh
#   - scripts/location_data.py
#   - scripts/disk_check.sh
#   - birdsuite-root (sudo helper)
#
# USAGE:
#   python3 scripts/migration.py <arguments> (see main)
#   Referenced by newinstaller.sh, scripts/disk_check.sh, scripts/location_data.py, scripts/status.php,
#   tests/migration_test.py
################################################################################

"""The migration from BirdNET-Pi on the same card (US-01, US-05; owner 2026-11-02). The installer (newinstaller.sh) finds
an installation of the original project in the home (its program folder, core.env BS_UPSTREAM_PROG_DIR, with
scripts/birds.db and a birdnet.conf, and no core.env of BirdSuite) and counts the space; the pages show the record
(System › Status, Migration); the import itself is location_data.py migrate. Runs as the station user.

  migration.py plan [--free-kb N] [--json]   what the installer would do: exit 0 = no BirdNET-Pi here, 10 = enough
                                             space (copy), 11 = not enough for a copy (move, asked first), 12 = not
                                             enough even when moving; --free-kb replaces the free space (tests)
  migration.py prepare copy|move [--free-kb N]
                                             before the installation (the new program folder in place, sudo still
                                             open): stops and disables the BirdNET-Pi units, keeps root's record of
                                             them and of the system files the installation changes (copy), then
                                             copy: renames the program and data folders to *.old;
                                             move: settings to config/BIRDNET.OLD.gz, the database aside in
                                             state/migration/, the data folder to *.old, the old program removed;
                                             writes state/migration.json (import pending)
  migration.py undo                          a failed installation in copy mode: BirdNET-Pi put back as it was
  migration.py tick                          every 5 minutes (disk_check.sh): starts the import once the station has
                                             its location (first setup done), and notifies once when the test period
                                             ended
  migration.py requeue                       Import now: a cancelled or failed import is started by the next tick
  migration.py status                        the record as JSON (with the days left)

The import runs after the first setup, not at the end of the installation: the detections are filed under the
station's location (found by their coordinates), named in the species language chosen in that setup, and the long copy
does not lengthen the installation; the station records while it runs (location_data.py, one job at a time)."""
import contextlib
import glob
import json
import os
import shutil
import subprocess
import sys
import tarfile
import time

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))

from utils import core

MB = 1024 * 1024
SUFFIX = core.BS_OLD_SUFFIX
UPSTREAM = os.path.join(core.BS_HOME, core.BS_UPSTREAM_PROG_DIR)
OLD_PROG = UPSTREAM + SUFFIX
OLD_DATA = core.BS_RECS + SUFFIX
# the original project's data folder has the same name as BirdSuite's: its clips by date, by the common name
UPSTREAM_BY_DATE_REL = os.path.relpath(core.BS_BY_DATE, core.BS_RECS)
# the system files the installation of BirdSuite changes, kept by root for Go back (copy mode): cron lines, web server,
# PHP pool user, the web server's sudo rule of the original project, the stream server's passwords
SYSTEM_FILES = ['/etc/crontab', core.BS_CADDYFILE, '/etc/php/*/fpm/pool.d/www.conf', '/etc/sudoers.d/010_caddy-nopasswd',
                '/etc/icecast2/icecast.xml']
UNIT_DIRS = [core.BS_UNIT_DIR, '/etc/systemd/system']
SUDO = ['sudo', '-n']
# exit codes of plan
NONE, COPY, MOVE, NOSPACE = 0, 10, 11, 12


def detect():
    """The original program folder when it is here (scripts/birds.db + a birdnet.conf, and neither the core.env nor the
    settings file of a BirdSuite of an older layout)."""
    db = os.path.join(UPSTREAM, 'scripts', 'birds.db')
    conf = os.path.join(UPSTREAM, 'birdnet.conf')
    if os.path.isfile(db) and (os.path.lexists(conf) or os.path.isfile('/etc/birdnet/birdnet.conf')) \
            and not os.path.exists(os.path.join(UPSTREAM, 'scripts', 'core.env')) \
            and not os.path.exists(os.path.join(UPSTREAM, os.path.relpath(core.BS_CONF, core.BS_PROG))):
        return UPSTREAM
    return None


def du(path):
    """The bytes a folder (or file) takes on the disk, symbolic links not followed."""
    total = 0
    try:
        st = os.lstat(path)
    except OSError:
        return 0
    if not os.path.isdir(path) or os.path.islink(path):
        return getattr(st, 'st_blocks', 0) * 512 or st.st_size
    for root, dirs, files in os.walk(path):
        for n in dirs + files:
            try:
                s = os.lstat(os.path.join(root, n))
                total += getattr(s, 'st_blocks', 0) * 512 or s.st_size
            except OSError:
                pass
    return total


def free_bytes(free_kb=None):
    if free_kb is not None:
        return int(free_kb) * 1024
    s = os.statvfs(core.BS_HOME)
    return s.f_bavail * s.f_frsize


def plan(free_kb=None):
    up = detect()
    if not up:
        return {'decision': 'none'}
    db = os.path.join(up, 'scripts', 'birds.db')
    by_date = os.path.join(core.BS_RECS, UPSTREAM_BY_DATE_REL)
    est = int(core.BS_INSTALL_ESTIMATE_MB) * MB
    margin = int(core.BS_MIGRATION_MARGIN_MB) * MB
    p = {'upstream': up, 'db': db, 'db_bytes': du(db), 'clips': by_date, 'clips_bytes': du(by_date), 'prog_bytes': du(up),
         'install_bytes': est, 'margin_bytes': margin, 'free_bytes': free_bytes(free_kb)}
    # a copy: BirdSuite, the database rows again and every clip again, and the margin
    p['need_copy'] = est + p['db_bytes'] + p['clips_bytes'] + margin
    # a move: the clips change folder on the same disk (no space), the rows are written again; the old program goes
    p['need_move'] = est + p['db_bytes'] + margin
    p['freed_move'] = max(0, p['prog_bytes'] - p['db_bytes'])
    if p['free_bytes'] >= p['need_copy']:
        p['decision'] = 'copy'
    elif p['free_bytes'] + p['freed_move'] >= p['need_move']:
        p['decision'] = 'move'
    else:
        p['decision'] = 'nospace'
    return p


def mb(n):
    return f'{n / MB:,.0f} MB'


def load():
    try:
        with open(core.BS_MIGRATION, encoding='utf-8') as f:
            return json.load(f)
    except (OSError, ValueError):
        return None


def save(m):
    os.makedirs(os.path.dirname(core.BS_MIGRATION), exist_ok=True)
    tmp = core.BS_MIGRATION + '.tmp'
    with open(tmp, 'w', encoding='utf-8') as f:
        json.dump(m, f, ensure_ascii=False, indent=1)
    os.replace(tmp, core.BS_MIGRATION)


def update(**kw):
    m = load()
    if m is None:
        return None
    m.update(kw)
    save(m)
    return m


def upstream_units(up):
    """The systemd units of the original project: its unit files are links into its program folder."""
    out = []
    for d in UNIT_DIRS:
        try:
            names = sorted(os.listdir(d))
        except OSError:
            continue
        for n in names:
            p = os.path.join(d, n)
            if not os.path.islink(p) or n in out:
                continue
            target = os.path.normpath(os.path.join(d, os.readlink(p)))
            if target.startswith(up + os.sep):
                out.append(n)
    return out


def run(cmd, check=False):
    r = subprocess.run(cmd, capture_output=True, text=True, check=False)
    if check and r.returncode != 0:
        raise SystemExit(f'{" ".join(cmd)}: {(r.stderr or r.stdout).strip()}')
    return r


def archive_settings(up, dest):
    """The settings of the original project in a .tar.gz: birdnet.conf (the file it links to), the lists, the
    notification (apprise) files and the other small text files of the program folder and its scripts/."""
    os.makedirs(os.path.dirname(dest), exist_ok=True)
    names = []
    with tarfile.open(dest + '.tmp', 'w:gz') as tar:
        conf = os.path.join(up, 'birdnet.conf')
        for c in (conf, '/etc/birdnet/birdnet.conf'):
            if os.path.isfile(c) and 'birdnet.conf' not in names:
                tar.add(os.path.realpath(c), arcname='birdnet.conf')
                names.append('birdnet.conf')
        for sub in ('', 'scripts'):
            for f in sorted(glob.glob(os.path.join(up, sub, '*'))):
                base = os.path.basename(f)
                if f == conf or not os.path.isfile(f) or not base.endswith(('.txt', '.conf', '.json', '.ini')) \
                        or os.path.getsize(f) > 5 * MB:
                    continue
                arc = os.path.join(sub, base) if sub else base
                tar.add(f, arcname=arc)
                names.append(arc)
    os.replace(dest + '.tmp', dest)
    return names


def root_record(units, mode):
    """root's own record (BS_MIGRATION_ROOT, root-owned): the original units and, in copy mode, the system files the
    installation changes. birdsuite-root migration-back works only from it, never from the station user's files."""
    rec = core.BS_MIGRATION_ROOT
    run([*SUDO, 'install', '-d', '-o', 'root', '-g', 'root', '-m', '0755', rec], check=True)
    p = subprocess.run([*SUDO, 'tee', os.path.join(rec, 'units')], input=''.join(u + '\n' for u in units),
                       capture_output=True, text=True, check=False)
    if p.returncode != 0:
        raise SystemExit('could not write ' + os.path.join(rec, 'units'))
    if mode == 'copy':
        files = [f for pat in SYSTEM_FILES for f in sorted(glob.glob(pat)) if os.path.isfile(f)]
        if files:
            run([*SUDO, 'tar', '-czPf', os.path.join(rec, 'system.tar.gz'), *files], check=True)


def prepare(mode, free_kb=None):
    p = plan(free_kb)
    if p['decision'] == 'none':
        raise SystemExit('No BirdNET-Pi installation found in ' + core.BS_HOME)
    if mode == 'copy' and p['decision'] != 'copy':
        raise SystemExit('Not enough space for a copy: ' + mb(p['need_copy']) + ' needed, ' + mb(p['free_bytes']) + ' free')
    if p['decision'] == 'nospace':
        raise SystemExit('Not enough space even when moving: ' + mb(p['need_move']) + ' needed')
    for x in (OLD_PROG, OLD_DATA, core.BS_MIGRATION):
        if os.path.lexists(x):
            raise SystemExit(x + ' already exists (an earlier migration?): nothing changed')
    up = p['upstream']
    units = upstream_units(up)
    data_bytes = du(core.BS_RECS)
    # 1. the original services stopped and switched off (their unit files point into the folders renamed below)
    if units:
        run([*SUDO, 'systemctl', 'disable', '--now', *units])
    if os.path.ismount(core.BS_STREAMDATA):
        run([*SUDO, 'umount', core.BS_STREAMDATA])
    root_record(units, mode)
    station = core.BS_UPSTREAM_PROG_DIR
    m = {'mode': mode, 'started': time.strftime('%Y-%m-%d'), 'started_at': int(time.time()), 'import': 'pending', 'imported': 0,
         'tries': 0, 'notified': '', 'units': units, 'space': {k: v for k, v in p.items() if k.endswith('_bytes') or k.startswith('need')}}
    if mode == 'copy':
        # 2. the whole installation kept as it is, under *.old
        os.rename(up, OLD_PROG)
        if os.path.isdir(core.BS_RECS):
            os.rename(core.BS_RECS, OLD_DATA)
        m['source'] = {'db': os.path.join(OLD_PROG, 'scripts', 'birds.db'), 'by_date': os.path.join(OLD_DATA, UPSTREAM_BY_DATE_REL), 'station': station}
        m['kept'] = [{'path': OLD_PROG, 'bytes': p['prog_bytes']}]
        if os.path.isdir(OLD_DATA):
            m['kept'].append({'path': OLD_DATA, 'bytes': data_bytes})
    else:
        # 2. the settings archived, the database aside, the clips' folder renamed (the import moves them), the program gone
        names = archive_settings(up, core.BS_UPSTREAM_ARCHIVE)
        os.makedirs(core.BS_MIGRATION_DIR, exist_ok=True)
        db = os.path.join(core.BS_MIGRATION_DIR, 'birds.db')
        shutil.move(p['db'], db)
        if os.path.isdir(core.BS_RECS):
            os.rename(core.BS_RECS, OLD_DATA)
        try:
            shutil.rmtree(up)
        except OSError:
            run([*SUDO, 'rm', '-rf', '--one-file-system', '--', up])
        m['source'] = {'db': db, 'by_date': os.path.join(OLD_DATA, UPSTREAM_BY_DATE_REL), 'station': station}
        m['archive'] = names
        m['kept'] = [{'path': core.BS_UPSTREAM_ARCHIVE, 'bytes': du(core.BS_UPSTREAM_ARCHIVE)}]
        if os.path.isdir(OLD_DATA):
            m['kept'].append({'path': OLD_DATA, 'bytes': data_bytes})
    save(m)
    return m


def undo():
    """A failed installation in copy mode (sudo still open): BirdNET-Pi back as it was. Move mode cannot be undone."""
    m = load()
    if not m or m.get('mode') != 'copy' or not os.path.isdir(OLD_PROG) or os.path.lexists(UPSTREAM):
        raise SystemExit('Nothing to put back (no copy-mode migration recorded, or the folders changed)')
    services = [getattr(core, k) + '.service' for k in dir(core) if k.startswith('BS_SVC_') and k not in ('BS_SVC_HELPER', 'BS_SVC_WEB', 'BS_SVC_ICECAST')]
    run([*SUDO, 'systemctl', 'disable', '--now', *services])
    if os.path.isdir(OLD_DATA):
        if os.path.lexists(core.BS_RECS):
            os.rename(core.BS_RECS, core.BS_RECS + '.birdsuite')
        os.rename(OLD_DATA, core.BS_RECS)
    os.rename(OLD_PROG, UPSTREAM)
    system = os.path.join(core.BS_MIGRATION_ROOT, 'system.tar.gz')
    run([*SUDO, 'tar', '-xzPf', system])
    run([*SUDO, 'systemctl', 'daemon-reload'])
    if m.get('units'):
        run(SUDO + ['systemctl', 'enable', '--now'] + m['units'])
    run([*SUDO, 'rm', '-rf', '--', core.BS_MIGRATION_ROOT])
    os.remove(core.BS_MIGRATION)


def days(m, now=None):
    """(days since the start, days left of the test period: 0 when it ended)."""
    gone = int(((now or time.time()) - int(m.get('started_at') or 0)) // 86400)
    return gone, max(0, int(core.BS_MIGRATION_DAYS) - gone)


def has_location():
    try:
        with open(core.BS_LOCATIONS_FILE, encoding='utf-8') as f:
            return bool(json.load(f))
    except (OSError, ValueError):
        return False


def job_status():
    try:
        with open(os.path.join(core.BS_STATE, 'location_job.json'), encoding='utf-8') as f:
            return json.load(f)
    except (OSError, ValueError):
        return {}


def tick(now=None, notify=None):
    m = load()
    if not m:
        return 'none'
    out = []
    j = job_status()
    if m.get('import') == 'running' and j.get('action') == 'migrate' and j.get('status') in ('error', 'cancelled'):
        # the import stopped: an error is tried again (an import never adds a detection twice), three times at most; a
        # cancel by the user waits for Import now on System › Status (requeue)
        failed = j.get('status') == 'error'
        m = update(**{'import': ('pending' if int(m.get('tries') or 0) < 3 else 'error') if failed else 'cancelled',
                      'error': j.get('error') or j.get('result') or ''})
    if m.get('import') == 'pending' and not os.path.exists(core.BS_FIRSTRUN) and has_location():
        r = subprocess.run([sys.executable, os.path.join(core.BS_SCRIPTS, 'location_data.py'), 'start', 'migrate'],
                           capture_output=True, text=True, cwd=core.BS_SCRIPTS, timeout=120, check=False)
        out.append('import started' if r.stdout.strip() == 'OK' else 'import not started: ' + (r.stderr or r.stdout).strip()[-200:])
    _gone, left = days(m, now)
    if left == 0 and not m.get('notified'):
        try:
            from utils.i18n import t
            title = t('str_birdnet_pi_test_period_ended')
            body = t('str_the_test_period_of_birdsuite_after_help', n=core.BS_MIGRATION_DAYS)
            (notify or _notify)(body, title)
        except Exception as e:  # noqa: BLE001  never in the way of the check; the Status page shows the end of the period anyway
            out.append(f'notification not sent: {e}')
        update(notified=time.strftime('%Y-%m-%d'))
        out.append('test period ended: notified')
    return '; '.join(out) or 'nothing to do'


def _notify(body, title):
    from utils.notifications import notify
    notify(body, title)


def import_done(added, moved):
    """After the import (location_data.py migrate): the record updated; in move mode the database copy aside goes and
    the emptied day folders of the old clips with it; the kept sizes counted again."""
    m = load()
    if not m:
        return
    if moved:
        try:
            os.remove(os.path.join(core.BS_MIGRATION_DIR, 'birds.db'))
            os.rmdir(core.BS_MIGRATION_DIR)
        except OSError:
            pass
        by_date = m.get('source', {}).get('by_date', '')
        if by_date.startswith(OLD_DATA + os.sep) and os.path.isdir(by_date):
            for root, _dirs, _files in os.walk(by_date, topdown=False):
                if root != by_date and not os.listdir(root):
                    with contextlib.suppress(OSError):
                        os.rmdir(root)
    for k in m.get('kept', []):
        k['bytes'] = du(k['path'])
    m.update({'import': 'done', 'imported': int(m.get('imported') or 0) + added, 'imported_on': time.strftime('%Y-%m-%d'), 'kept': m.get('kept', [])})
    save(m)


def requeue():
    """Import now (System › Status): a cancelled or failed import waits for the next tick again."""
    m = load()
    if not m or m.get('import') not in ('cancelled', 'error'):
        return False
    update(**{'import': 'pending', 'tries': 0, 'error': ''})
    return True


def status():
    m = load()
    if not m:
        return None
    gone, left = days(m)
    return dict(m, days_gone=gone, days_left=left, period=int(core.BS_MIGRATION_DAYS))


def main():
    a = sys.argv[1:]
    free_kb = None
    if '--free-kb' in a:
        i = a.index('--free-kb')
        free_kb = int(a[i + 1])
        del a[i:i + 2]
    if a[:1] == ['plan']:
        p = plan(free_kb)
        if '--json' in a:
            print(json.dumps(p))
        elif p['decision'] != 'none':
            print(f'BirdNET-Pi found in {p["upstream"]}: database {mb(p["db_bytes"])}, clips {mb(p["clips_bytes"])}, '
                  f'program {mb(p["prog_bytes"])}; free {mb(p["free_bytes"])}.')
            print(f'A copy needs {mb(p["need_copy"])} (BirdSuite {mb(p["install_bytes"])} + database + clips + margin '
                  f'{mb(p["margin_bytes"])}); a move needs {mb(p["need_move"])} and frees {mb(p["freed_move"])}.')
        sys.exit({'none': NONE, 'copy': COPY, 'move': MOVE, 'nospace': NOSPACE}[p['decision']])
    elif a[:1] == ['prepare'] and len(a) == 2 and a[1] in ('copy', 'move'):
        m = prepare(a[1], free_kb)
        print(json.dumps(m, ensure_ascii=False))
    elif a == ['undo']:
        undo()
        print('BirdNET-Pi put back')
    elif a == ['tick']:
        print(tick())
    elif a == ['requeue']:
        print('OK' if requeue() else 'nothing to import again')
    elif a == ['status']:
        print(json.dumps(status(), ensure_ascii=False))
    else:
        raise SystemExit(__doc__)


if __name__ == '__main__':
    main()
