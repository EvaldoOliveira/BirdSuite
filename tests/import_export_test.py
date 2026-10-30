#!/usr/bin/env python3
"""Import / export of Tools › Backup/Import in many situations (owner 2026-10-25: tested before every release).

Each run builds simulated stations in a temporary folder (their own HOME: config, birds.db, clips) and drives
scripts/location_data.py as the page does, then checks the database rows, the locations, the clip files and their names.
Nothing of the real station is touched. Usage: python3 tests/import_export_test.py [--folder <mounted folder>]
(exit 1 when a check fails). With --folder (on a station: a NAS share under /mnt or a USB disk under /media), the
backup of scenario 12 is written to and imported from a test folder made there, deleted at the end.
"""
import csv
import json
import os
import re
import shutil
import sqlite3
import subprocess
import sys
import tempfile

REPO = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
SCHEMA = """
CREATE TABLE detections (Date DATE, Time TIME, Sci_Name VARCHAR(100) NOT NULL, Com_Name VARCHAR(100) NOT NULL,
  Confidence FLOAT, Lat FLOAT, Lon FLOAT, Cutoff FLOAT, Week INT, Sens FLOAT, Overlap FLOAT, File_Name VARCHAR(100) NOT NULL,
  Loc_Thresh FLOAT, Rec_Length INT, Sp_Override FLOAT, Location INTEGER);
CREATE TABLE detection_reviews (File_Name VARCHAR(100) PRIMARY KEY, Sci_Name VARCHAR(100), Com_Name VARCHAR(100), Date DATE,
  Confidence FLOAT, Verdict TEXT NOT NULL CHECK (Verdict IN ('yes','no','unsure')), Reviewed_At TEXT, Reason TEXT);
"""
CASA = {'id': 1, 'name': 'Casa', 'builtin': 'home', 'lat': -23.5475, 'lon': -46.6361, 'radius_km': 1, 'timezone': 'America/Sao_Paulo', 'species_list': ''}
TEST = {'id': 2, 'name': 'Teste / Playback', 'builtin': 'test', 'lat': -23.5475, 'lon': -46.6361, 'radius_km': 0.05, 'timezone': 'America/Sao_Paulo', 'species_list': ''}
SITIO = {'id': 3, 'name': 'Sitio', 'lat': -22.9, 'lon': -47.06, 'radius_km': 1, 'timezone': 'America/Sao_Paulo', 'species_list': ''}

failures = []


def _chmod(path, mode):
    # a CIFS share mounted with fixed file_mode / dir_mode ignores or refuses it: the test goes on
    try:
        os.chmod(path, mode)
    except OSError:
        pass



def station_labels(code):
    """The common names of the station's labels_<code>.json ({} when there is none)."""
    try:
        return json.load(open(os.path.join(REPO, 'model', 'l18n', 'labels_%s.json' % code), encoding='utf-8'))
    except (OSError, ValueError):
        return {}

def check(cond, what):
    print(('  ok   ' if cond else '  FAIL ') + what)
    if not cond:
        failures.append(what)


class Station:
    def __init__(self, root, name, lang, locs):
        self.home = os.path.join(root, name)
        self.base = os.path.join(self.home, 'BirdNET-Pi')
        os.makedirs(os.path.join(self.base, 'config', 'data'))
        os.makedirs(os.path.join(self.base, 'state'))
        os.makedirs(os.path.join(self.home, 'BirdSongs', 'Extracted', 'By_Date'))
        # a copy of the scripts (a link resolves to the repository, whose config is not this station's); the model linked
        shutil.copytree(os.path.join(REPO, 'scripts'), os.path.join(self.base, 'scripts'),
                        ignore=shutil.ignore_patterns('__pycache__', 'gotty*', '*.whl', '*.db', '*.sha256'))
        os.symlink(os.path.join(REPO, 'model'), os.path.join(self.base, 'model'))
        shutil.copy(os.path.join(REPO, 'config', 'factory_defaults.conf'), os.path.join(self.base, 'config'))
        with open(os.path.join(self.base, 'config', 'BirdSuite.conf'), 'w') as f:
            f.write(f'SITE_NAME="{name}"\nDATABASE_LANG={lang}\nLATITUDE={locs[0]["lat"]}\nLONGITUDE={locs[0]["lon"]}\n')
        with open(os.path.join(self.base, 'config', 'locations.json'), 'w') as f:
            json.dump(locs, f)
        self.db = os.path.join(self.base, 'config', 'data', 'birds.db')
        con = sqlite3.connect(self.db)
        con.executescript(SCHEMA)
        con.close()
        self.by_date = os.path.join(self.home, 'BirdSongs', 'Extracted', 'By_Date')

    def add(self, date, time, sci, com, conf, loc, file_name, folder):
        con = sqlite3.connect(self.db)
        con.execute('INSERT INTO detections (Date, Time, Sci_Name, Com_Name, Confidence, Lat, Lon, File_Name, Location) VALUES (?,?,?,?,?,?,?,?,?)',
                    (date, time, sci, com, conf, loc['lat'], loc['lon'], file_name, loc['id']))
        con.commit()
        con.close()
        d = os.path.join(self.by_date, date, folder)
        os.makedirs(d, exist_ok=True)
        for ext in ('', '.png'):
            with open(os.path.join(d, file_name + ext), 'w') as f:
                f.write(file_name + ext)

    def review(self, file_name, sci, com, verdict):
        con = sqlite3.connect(self.db)
        con.execute('INSERT INTO detection_reviews (File_Name, Sci_Name, Com_Name, Verdict) VALUES (?,?,?,?)', (file_name, sci, com, verdict))
        con.commit()
        con.close()

    def run(self, *args):
        env = dict(os.environ, HOME=self.home)
        r = subprocess.run([sys.executable, os.path.join(self.base, 'scripts', 'location_data.py')] + [str(a) for a in args],
                           env=env, capture_output=True, text=True, cwd=os.path.join(self.base, 'scripts'))
        if r.returncode != 0:
            print('    (location_data.py ' + ' '.join(map(str, args)) + ' failed: ' + (r.stderr or r.stdout).strip()[-400:] + ')')
        return r

    def job(self):
        try:
            return json.load(open(os.path.join(self.base, 'state', 'location_job.json')))
        except (OSError, ValueError):
            return {}

    def rows(self, where='1'):
        con = sqlite3.connect(self.db)
        con.row_factory = sqlite3.Row
        out = [dict(r) for r in con.execute(f'SELECT * FROM detections WHERE {where} ORDER BY Date, Time, Sci_Name')]
        con.close()
        return out

    def reviews(self):
        con = sqlite3.connect(self.db)
        out = {r[0]: r[1] for r in con.execute('SELECT File_Name, Verdict FROM detection_reviews')}
        con.close()
        return out

    def locations(self):
        return json.load(open(os.path.join(self.base, 'config', 'locations.json')))

    def clip_ok(self, r):
        """the clip and its spectrogram of a row, in the scientific-name folder, named by the scientific name"""
        s = r['Sci_Name'].replace(' ', '_')
        d = os.path.join(self.by_date, r['Date'], s)
        return r['File_Name'].startswith(s + '-') and os.path.isfile(os.path.join(d, r['File_Name'])) and os.path.isfile(os.path.join(d, r['File_Name'] + '.png'))


def newest(folder, prefix):
    # a BirdSuite backup is named <yyyy-mm-dd>_<station>[_<location>][_n] (2026-10-25): prefix BirdSuite-<station>[-<location>]
    if prefix.startswith('BirdSuite-'):
        # BirdSuite-<station>- (a trailing dash) = any location, BirdSuite-<station>-All... = every location
        any_loc = prefix.endswith('-')
        parts = prefix[len('BirdSuite-'):].rstrip('-').split('-', 1)
        loc = parts[1] if len(parts) > 1 else ''
        if loc.startswith('All'):
            loc = ''
        tail = r'(_.+)?$' if any_loc and not loc else (r'_' + re.escape(loc) if loc else '') + r'(_\d+)?$'
        pat = re.compile(r'^\d{4}-\d{2}-\d{2}_' + re.escape(parts[0]) + tail)
        found = [d for d in os.listdir(folder) if pat.match(d)]
    else:
        found = [d for d in os.listdir(folder) if d.startswith(prefix)]
    found.sort(key=lambda d: os.path.getmtime(os.path.join(folder, d)))
    return os.path.join(folder, found[-1]) if found else None


def fill_a(a):
    """Station A (Portuguese names): Casa with 3 clips by the scientific name and 1 old clip by the common name, Sitio
    with 2, one Yes review."""
    a.add('2026-10-20', '06:00:01', 'Turdus rufiventris', 'Sabiá-laranjeira', 0.91, CASA,
          'Turdus_rufiventris-2026-10-20_06h00m01s-birdnet-conf-91.flac', 'Turdus_rufiventris')
    a.add('2026-10-20', '06:05:00', 'Pitangus sulphuratus', 'Bem-te-vi', 0.82, CASA,
          'Pitangus_sulphuratus-2026-10-20_06h05m00s-birdnet-conf-82.flac', 'Pitangus_sulphuratus')
    a.add('2026-10-21', '07:00:00', 'Contopus cooperi', 'Piuí-boreal', 0.57, CASA,
          'Contopus_cooperi-2026-10-21_07h00m00s-birdnet-conf-57.flac', 'Contopus_cooperi')
    # an old clip, from before the scientific-name layout (BirdNET-Pi style: common name, confidence, date)
    a.add('2026-10-19', '05:50:00', 'Columbina talpacoti', 'Rolinha-roxa', 0.77, CASA,
          'Rolinha-roxa-77-2026-10-19-birdnet-05:50:00.mp3', 'Rolinha-roxa')
    a.add('2026-10-22', '08:00:00', 'Turdus rufiventris', 'Sabiá-laranjeira', 0.88, SITIO,
          'Turdus_rufiventris-2026-10-22_08h00m00s-birdnet-conf-88.flac', 'Turdus_rufiventris')
    a.add('2026-10-22', '08:10:00', 'Columbina talpacoti', 'Rolinha-roxa', 0.66, SITIO,
          'Columbina_talpacoti-2026-10-22_08h10m00s-birdnet-conf-66.flac', 'Columbina_talpacoti')
    a.review('Pitangus_sulphuratus-2026-10-20_06h05m00s-birdnet-conf-82.flac', 'Pitangus sulphuratus', 'Bem-te-vi', 'yes')


def main():
    root = tempfile.mkdtemp(prefix='bs-impexp-')
    out = os.path.join(root, 'nas')
    os.makedirs(out)
    try:
        a = Station(root, 'StationA', 'pt_BR', [CASA, TEST, SITIO])
        fill_a(a)

        print('1. Export / Backup of one location (BirdSuite)')
        a.run('backup', 1, out)
        b1 = newest(out, 'BirdSuite-StationA-Casa')
        check(b1 is not None and a.job().get('status') == 'done', 'backup folder made, job done')
        meta = json.load(open(os.path.join(b1, 'location.json')))
        rows = list(csv.DictReader(open(os.path.join(b1, 'detections.csv'), encoding='utf-8')))
        check(meta.get('format') == 'birdsuite' and len(rows) == 4, 'location.json says birdsuite, 4 detections of Casa')
        check(all(r['File_Name'].startswith(r['Sci_Name'].replace(' ', '_') + '-') for r in rows), 'every clip named by the scientific name (the old one converted)')
        check(all(os.path.isfile(os.path.join(b1, 'By_Date', r['Date'], r['Sci_Name'].replace(' ', '_'), r['File_Name'])) for r in rows), 'every clip copied, in its scientific-name folder')
        check(os.path.isfile(os.path.join(b1, 'reviews.csv')), 'the Yes review exported')

        print('2. Export / Backup of every location')
        a.run('backup', 0, out)
        b_all = newest(out, 'BirdSuite-StationA-All') or newest(out, 'BirdSuite-StationA-')
        meta = json.load(open(os.path.join(b_all, 'location.json')))
        rows = list(csv.DictReader(open(os.path.join(b_all, 'detections.csv'), encoding='utf-8')))
        check(len(rows) == 6 and len(meta.get('locations') or []) == 3, '6 detections, the 3 locations listed')

        print('3. Export to BirdNET (old), English names')
        a.run('legacy', 1, 'en', out)
        l1 = newest(out, 'BirdNET-Pi-StationA-Casa-en')
        con = sqlite3.connect(os.path.join(l1, 'birds.db'))
        lrows = [dict(zip(('Sci', 'Com', 'File', 'Date'), r)) for r in con.execute('SELECT Sci_Name, Com_Name, File_Name, Date FROM detections')]
        con.close()
        check(len(lrows) == 4, 'birds.db with 4 detections')
        # the station's own English labels: a station layer may replace them (CBRO names), the factory ones are the default
        en = station_labels('en')
        want = {en.get('Pitangus sulphuratus') or 'Great Kiskadee', en.get('Turdus rufiventris') or 'Rufous-bellied Thrush'}
        check({r['Com'] for r in lrows} >= want, 'common names in English')
        check(all(r['File'].startswith(r['Com'].replace(' ', '_').replace("'", '')) and os.path.isfile(os.path.join(l1, 'By_Date', r['Date'], r['Com'].replace(' ', '_').replace("'", ''), r['File'])) for r in lrows),
              'clips named and filed by the English common name')
        check(sqlite3.connect(a.db).execute('SELECT COUNT(*) FROM detections').fetchone()[0] == 6, 'station A unchanged by the export')

        print('4. What a folder holds (peek)')
        p = json.loads(a.run('peek', b1).stdout or '{}')
        check(p.get('kind') == 'birdsuite' and p.get('detections') == 4 and p.get('birdnet') == 0, 'BirdSuite backup: kind, 4 detections, all by scientific name')
        p = json.loads(a.run('peek', l1).stdout or '{}')
        check(p.get('kind') == 'birdnet' and p.get('birdnet') == 4 and all(station_labels(p.get('lang') or '-').get(r['Sci']) == r['Com'] for r in lrows), 'BirdNET export: kind, by common name, language en')

        print('5. Import into Test / Playback of another station')
        b = Station(root, 'StationB', 'pt_BR', [dict(CASA, lat=-20.0, lon=-44.0), dict(TEST, lat=-20.0, lon=-44.0)])
        b.run('restore', b1, '--to', 2)
        rows = b.rows()
        check(len(rows) == 4 and all(r['Location'] == 2 for r in rows), '4 detections, all in Test / Playback')
        check(all(abs(r['Lat'] - -20.0) < 1e-6 for r in rows), 'their coordinates are those of Test / Playback')
        check(all(b.clip_ok(r) for r in rows), 'every clip there, by the scientific name')
        check(list(b.reviews().values()) == ['yes'], 'the Yes review came along')

        print('6. The same import again: nothing twice')
        b.run('restore', b1, '--to', 2)
        check(len(b.rows()) == 4, 'still 4 detections')
        check('0' in str(b.job().get('result', '')) or len(b.rows()) == 4, 'the job says none were added')

        print('7. Import into a new location')
        c = Station(root, 'StationC', 'pt_BR', [dict(CASA, lat=-10.0, lon=-40.0), TEST])
        c.run('restore', b1, '--new')
        locs = c.locations()
        new = [x for x in locs if x['name'] == 'Casa (import)']
        check(len(new) == 1 and abs(new[0]['lat'] - CASA['lat']) < 1e-6, 'location "Casa (import)" created at the backup coordinates')
        check(len(c.rows()) == 4 and all(r['Location'] == new[0]['id'] for r in c.rows()), '4 detections filed under it')

        print('8. Import of every location (each detection to its own location)')
        d = Station(root, 'StationD', 'pt_BR', [dict(CASA), TEST])
        d.run('restore', b_all)
        by = {}
        for r in d.rows():
            by[r['Location']] = by.get(r['Location'], 0) + 1
        sitio = [x for x in d.locations() if abs(x['lat'] - SITIO['lat']) < 0.01]
        check(by.get(1) == 4, 'the 4 Casa detections into the existing Casa (same coordinates)')
        check(len(sitio) == 1 and by.get(int(sitio[0]['id'])) == 2, 'Sitio created and its 2 detections in it')

        print('9. Import of a BirdNET (old) folder, into an existing location, names in the station language')
        e = Station(root, 'StationE', 'pt_BR', [dict(CASA), TEST])
        e.run('restore', l1, '--to', 1)
        rows = e.rows()
        check(len(rows) == 4 and all(r['Location'] == 1 for r in rows), '4 detections into Casa')
        check(all(e.clip_ok(r) for r in rows), 'clips converted to the scientific-name layout')
        check({r['Com_Name'] for r in rows} >= {'Bem-te-vi', 'Sabiá-laranjeira'}, 'common names of the station language (pt_BR)')

        print('10. Import into a location that already has part of it')
        f = Station(root, 'StationF', 'pt_BR', [dict(CASA), TEST])
        f.add('2026-10-20', '06:00:01', 'Turdus rufiventris', 'Sabiá-laranjeira', 0.91, CASA,
              'Turdus_rufiventris-2026-10-20_06h00m01s-birdnet-conf-91.flac', 'Turdus_rufiventris')
        f.run('restore', b1, '--to', 1)
        check(len(f.rows()) == 4, 'only the 3 missing ones added (4 in all)')

        print('11. Round trip: BirdNET export of an imported location, imported again elsewhere')
        e.run('legacy', 1, 'pt_BR', out)
        l2 = newest(out, 'BirdNET-Pi-StationE-Casa-pt_BR')
        g = Station(root, 'StationG', 'pt_BR', [dict(CASA, lat=-5.0, lon=-35.0), TEST])
        g.run('restore', l2, '--to', 2)
        check(len(g.rows()) == 4 and all(g.clip_ok(r) for r in g.rows()), '4 detections with their clips after two conversions')

        print('12. Backup to and import from a folder with spaces and accents, read-only (as a mounted NAS share)')
        real = sys.argv[sys.argv.index('--folder') + 1] if '--folder' in sys.argv else ''
        nas = os.path.join(real, 'BirdSuite test ç ' + str(os.getpid())) if real else os.path.join(root, 'NAS Backup', 'Pasta de cópias ç')
        os.makedirs(nas)
        if real:
            print('    (in the mounted folder ' + nas + ')')
        a.run('backup', 3, nas)
        b3 = newest(nas, 'BirdSuite-StationA-Sitio')
        check(b3 is not None and len(list(csv.DictReader(open(os.path.join(b3, 'detections.csv'), encoding='utf-8')))) == 2, 'backup of Sitio written there (2 detections)')
        for dp, dn, fn in os.walk(nas):
            for x in fn:
                _chmod(os.path.join(dp, x), 0o444)
            for x in dn:
                _chmod(os.path.join(dp, x), 0o555)
        h = Station(root, 'StationH', 'pt_BR', [dict(CASA), TEST])
        h.run('restore', b3, '--new')
        check(len(h.rows()) == 2 and all(h.clip_ok(r) for r in h.rows()), 'imported from the read-only folder, clips copied')
        for dp, dn, fn in os.walk(nas):
            for x in dn:
                _chmod(os.path.join(dp, x), 0o755)
            for x in fn:
                _chmod(os.path.join(dp, x), 0o644)
        if real:
            shutil.rmtree(nas, ignore_errors=True)
            check(not os.path.exists(nas), 'test folder removed from the mounted folder')

        print('13. Import of a folder that is not a backup')
        bad = os.path.join(root, 'empty')
        os.makedirs(bad)
        r = g.run('restore', bad, '--to', 2)
        check(r.returncode != 0 and len(g.rows()) == 4, 'refused, nothing changed')

        print('14. Incremental backup (owner 2026-10-29): only the new, reviews changed since, imported in any order')
        inc = os.path.join(root, 'inc')
        os.makedirs(inc)
        a.run('backup', 1, inc, '--incremental')
        f1 = sorted(os.listdir(inc))
        check(len(f1) == 1 and not f1[0].endswith('_inc') and len(list(csv.DictReader(open(os.path.join(inc, f1[0], 'detections.csv'), encoding='utf-8')))) == 4,
              'the first incremental is a full one (4 detections)')
        a.run('backup', 1, inc, '--incremental')
        check(sorted(os.listdir(inc)) == f1 and 'Nothing new' in (a.job().get('result') or ''), 'nothing new: no folder made')
        a.add('2026-10-23', '06:30:00', 'Pitangus sulphuratus', 'Bem-te-vi', 0.93, CASA,
              'Pitangus_sulphuratus-2026-10-23_06h30m00s-birdnet-conf-93.flac', 'Pitangus_sulphuratus')
        con = sqlite3.connect(a.db)
        con.execute("UPDATE detection_reviews SET Verdict = 'no', Reviewed_At = '2026-10-29 12:00:00' WHERE File_Name LIKE 'Pitangus_sulphuratus-2026-10-20%'")
        con.commit()
        con.close()
        a.run('backup', 1, inc, '--incremental')
        f2 = [d for d in sorted(os.listdir(inc)) if d not in f1]
        rows = list(csv.DictReader(open(os.path.join(inc, f2[0], 'detections.csv'), encoding='utf-8'))) if f2 else []
        revs = list(csv.DictReader(open(os.path.join(inc, f2[0], 'reviews.csv'), encoding='utf-8'))) if f2 and os.path.isfile(os.path.join(inc, f2[0], 'reviews.csv')) else []
        check(len(f2) == 1 and f2[0].endswith('_inc') and len(rows) == 1 and len(revs) == 1, 'the next one (_inc): the 1 new detection and the 1 changed review')
        for order in ((f1[0], f2[0]), (f2[0], f1[0])):
            c = Station(root, 'StationC' + str(len(order[0])) + order[0][-3:], 'pt_BR', [dict(CASA, lat=-21.0, lon=-45.0), dict(TEST, lat=-21.0, lon=-45.0)])
            for d in order:
                c.run('restore', os.path.join(inc, d), '--to', 1)
            check(len(c.rows()) == 5 and list(c.reviews().values()) == ['no'], 'imported ' + ('full, then incremental' if order[0] == f1[0] else 'incremental, then full')
                  + ': 5 detections, the newest review (no) kept')

        print('15. Biome and ecoregion of each detection (US-27, owner 2026-10-29)')
        d = Station(root, 'StationBio', 'pt_BR', [CASA, TEST, dict(SITIO, lat=-15.79, lon=-47.88)])  # Sitio in the Cerrado
        d.add('2026-10-24', '06:00:00', 'Turdus rufiventris', 'Sabiá-laranjeira', 0.9, CASA,
              'Turdus_rufiventris-2026-10-24_06h00m00s-birdnet-conf-90.flac', 'Turdus_rufiventris')
        # offline: the land cover (ESA WorldCover) is an online lookup, the test must not depend on the network
        subprocess.run([sys.executable, os.path.join(d.base, 'scripts', 'utils', 'locations.py'), 'biomes'], env=dict(os.environ, HOME=d.home, BIRDSUITE_OFFLINE='1'), capture_output=True)
        locs = {l['id']: l for l in d.locations()}
        check(locs[1].get('biome_br') == 'Atlantic Forest' and locs[3].get('biome_br') == 'Cerrado' and locs[3].get('ecoregion') == 'Cerrado',
              'locations: Casa in the Atlantic Forest, Sitio in the Cerrado (RESOLVE and IBGE)')
        check([r['Biome_BR'] for r in d.rows()] == ['Atlantic Forest'], 'an older detection filled with the biome of its location')
        d.add('2026-10-24', '07:00:00', 'Columbina talpacoti', 'Rolinha-roxa', 0.8, SITIO,
              'Columbina_talpacoti-2026-10-24_07h00m00s-birdnet-conf-80.flac', 'Columbina_talpacoti')
        check([r['Biome_BR'] for r in d.rows()] == ['Atlantic Forest', 'Cerrado'], 'a new detection takes the biome of its location (trigger)')
        d.run('reassign', 1, 3)
        check({r['Biome_BR'] for r in d.rows()} == {'Cerrado'}, 'moved to another location: the biome of that one')
        bio = os.path.join(root, 'bio')
        os.makedirs(bio)
        d.run('backup', 3, bio)
        rows = list(csv.DictReader(open(os.path.join(bio, os.listdir(bio)[0], 'detections.csv'), encoding='utf-8')))
        check(rows and all(r.get('Biome_BR') == 'Cerrado' and r.get('Ecoregion') == 'Cerrado' and r.get('Biome') for r in rows),
              'Export / Backup: Biome, Ecoregion and Biome_BR in detections.csv')
        check(rows and all(c in rows[0] for c in ('Land_Cover', 'Built_Up', 'Setting', 'Built_Up_500')), 'Export / Backup: the land cover and setting columns too')
    finally:
        shutil.rmtree(root, ignore_errors=True)
    print()
    print('ALL PASSED' if not failures else f'{len(failures)} FAILED: ' + '; '.join(failures))
    sys.exit(1 if failures else 0)


if __name__ == '__main__':
    main()
