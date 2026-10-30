"""Import/export variants (owner 2026-10-25; run: python3 tests/import_export_variants_test.py): exports to the old BirdNET format and BirdSuite backups, re-imported into
different locations and modes, on throw-away stations (temporary HOME), with the helpers of tests/import_export_test.py."""
import json
import os
import shutil
import sqlite3
import sys
import tempfile

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
import import_export_test as T  # noqa: E402

CASA, TEST, SITIO = T.CASA, T.TEST, T.SITIO


def by_loc(st):
    out = {}
    for r in st.rows():
        out[r['Location']] = out.get(r['Location'], 0) + 1
    return out


def loc_named(st, lat):
    return [x for x in st.locations() if abs(x['lat'] - lat) < 0.01]


def main():
    root = tempfile.mkdtemp(prefix='bs-variants-')
    out = os.path.join(root, 'nas')
    os.makedirs(out)
    try:
        a = T.Station(root, 'StationA', 'pt_BR', [CASA, TEST, SITIO])
        T.fill_a(a)
        a.run('backup', 0, out)
        b_all = T.newest(out, 'BirdSuite-StationA-')
        a.run('legacy', 0, 'en', out)
        l_all = T.newest(out, 'BirdNET-Pi-StationA-')
        a.run('legacy', 1, 'pt_BR', out)
        l_casa_pt = T.newest(out, 'BirdNET-Pi-StationA-Casa-pt_BR')
        a.run('legacy', 3, 'de', out)
        l_sitio_de = T.newest(out, 'BirdNET-Pi-StationA-Sitio-de')
        print('0. Exports made: BirdSuite all, BirdNET (old) all in English, Casa in Portuguese, Sitio in German')
        T.check(all([b_all, l_all, l_casa_pt, l_sitio_de]), 'four export folders')
        con = sqlite3.connect(os.path.join(l_all, 'birds.db'))
        T.check(con.execute('SELECT COUNT(*) FROM detections').fetchone()[0] == 6, 'old-format export of every location: 6 detections')
        con.close()

        print('1. Old format, every location, imported without a target (each detection to the location of its coordinates)')
        s = T.Station(root, 'S1', 'pt_BR', [dict(CASA), TEST])
        s.run('restore', l_all)
        bl = by_loc(s)
        sit = loc_named(s, SITIO['lat'])
        T.check(bl.get(1) == 4, '4 into the existing Casa')
        T.check(len(sit) == 1 and bl.get(int(sit[0]['id'])) == 2, 'Sitio created from the coordinates, 2 detections in it')
        T.check(all(s.clip_ok(r) for r in s.rows()), 'clips converted to the scientific-name layout')
        T.check({r['Com_Name'] for r in s.rows()} >= {'Bem-te-vi', 'Sabiá-laranjeira'}, 'names in the station language (pt_BR), not the English of the export')

        print('2. Old format, every location, all into one location (--to Test / Playback)')
        s = T.Station(root, 'S2', 'pt_BR', [dict(CASA, lat=-20.0, lon=-44.0), dict(TEST, lat=-20.0, lon=-44.0)])
        s.run('restore', l_all, '--to', 2)
        T.check(by_loc(s) == {2: 6}, '6 detections, all in Test / Playback')
        T.check(all(abs(r['Lat'] - -20.0) < 1e-6 for r in s.rows()), 'coordinates of Test / Playback')

        print('3. Old format, every location, into new locations (--new)')
        s = T.Station(root, 'S3', 'pt_BR', [dict(CASA, lat=-10.0, lon=-40.0), TEST])
        s.run('restore', l_all, '--new')
        names = sorted(x['name'] for x in s.locations())
        bl = by_loc(s)
        T.check(len(s.rows()) == 6 and 1 not in bl and 2 not in bl, '6 detections, none in the existing locations')
        made = [x['name'] for x in s.locations() if int(x['id']) not in (1, 2)]
        T.check(len(made) == 2 and len(set(made)) == 2, 'two new locations, with different names (their coordinates)')
        print('    (locations now: ' + ', '.join(names) + '; per location: ' + json.dumps(bl) + ')')

        print('4. Old format of one location (Portuguese names) into a new location of a station in English')
        s = T.Station(root, 'S4', 'en', [dict(CASA, lat=-10.0, lon=-40.0), TEST])
        s.run('restore', l_casa_pt, '--new')
        T.check(len(s.rows()) == 4 and all(s.clip_ok(r) for r in s.rows()), '4 detections with their clips')
        T.check({r['Com_Name'] for r in s.rows()} >= {'Great Kiskadee', 'Rufous-bellied Thrush'}, 'names in the station language (English)')

        print('5. Old format in German into Test / Playback of a Portuguese station')
        s = T.Station(root, 'S5', 'pt_BR', [dict(CASA), TEST])
        s.run('restore', l_sitio_de, '--to', 2)
        rows = s.rows()
        T.check(len(rows) == 2 and all(r['Location'] == 2 for r in rows), '2 detections, both in Test / Playback')
        T.check({r['Sci_Name'] for r in rows} == {'Turdus rufiventris', 'Columbina talpacoti'}, 'species found from the German names')
        T.check({r['Com_Name'] for r in rows} == {'Sabiá-laranjeira', 'Rolinha-roxa'}, 'names in Portuguese')

        print('6. BirdSuite backup of every location, all into one location (--to Test / Playback)')
        s = T.Station(root, 'S6', 'pt_BR', [dict(CASA, lat=-20.0, lon=-44.0), dict(TEST, lat=-20.0, lon=-44.0)])
        s.run('restore', b_all, '--to', 2)
        T.check(by_loc(s) == {2: 6}, '6 detections, all in Test / Playback')
        T.check(list(s.reviews().values()) == ['yes'], 'the Yes review came along')

        print('7. BirdSuite backup of every location into new locations (--new)')
        s = T.Station(root, 'S7', 'pt_BR', [dict(CASA, lat=-10.0, lon=-40.0), TEST])
        s.run('restore', b_all, '--new')
        bl = by_loc(s)
        T.check(len(s.rows()) == 6 and 1 not in bl and 2 not in bl, '6 detections, none in the existing locations')
        print('    (locations now: ' + ', '.join(sorted(x['name'] for x in s.locations())) + '; per location: ' + json.dumps(bl) + ')')

        print('8. The same old-format folder imported twice into another location: nothing twice')
        s = T.Station(root, 'S8', 'pt_BR', [dict(CASA), TEST])
        s.run('restore', l_all, '--to', 1)
        s.run('restore', l_all, '--to', 2)
        T.check(len(s.rows()) == 6, 'still 6 detections (a detection already on the station is not added under another location)')
        print('    (per location: ' + json.dumps(by_loc(s)) + ')')

        print('9. Old-format export re-imported into another location of the same station')
        a.run('restore', l_casa_pt, '--to', 2)
        T.check(len(a.rows()) == 6, 'station A still 6 detections: its own clips are not duplicated')

        print('10. Reviews through the old format (it has no reviews table)')
        s = T.Station(root, 'S10', 'pt_BR', [dict(CASA), TEST])
        s.run('restore', l_casa_pt, '--to', 1)
        print('    (reviews after an old-format import: ' + json.dumps(s.reviews()) + ')')
        T.check(len(s.rows()) == 4, '4 detections; reviews are not part of the old format')

        print('11. Chain: BirdSuite backup -> import -> old format in English -> import -> BirdSuite backup -> import')
        s1 = T.Station(root, 'C1', 'pt_BR', [dict(CASA, lat=-12.0, lon=-38.0), TEST])
        s1.run('restore', b_all, '--to', 1)
        s1.run('legacy', 1, 'en', out)
        lc = T.newest(out, 'BirdNET-Pi-C1-')
        s2 = T.Station(root, 'C2', 'pt_BR', [dict(CASA, lat=-8.0, lon=-35.0), TEST])
        s2.run('restore', lc, '--to', 2)
        s2.run('backup', 2, out)
        bc = T.newest(out, 'BirdSuite-C2-')
        s3 = T.Station(root, 'C3', 'pt_BR', [dict(CASA, lat=-3.0, lon=-60.0), TEST])
        s3.run('restore', bc, '--new')
        T.check(len(s3.rows()) == 6 and all(s3.clip_ok(r) for r in s3.rows()), '6 detections with their clips after four conversions')
        T.check(sorted(r['Sci_Name'] for r in s3.rows()) == sorted(r['Sci_Name'] for r in a.rows()), 'the same species as the start')
        T.check(sorted(r['Date'] + r['Time'] for r in s3.rows()) == sorted(r['Date'] + r['Time'] for r in a.rows()), 'the same dates and times')

        print('12. Move to another location of this station (reassign Sitio -> Casa)')
        m = T.Station(root, 'M1', 'pt_BR', [CASA, TEST, SITIO])
        T.fill_a(m)
        clips_before = sorted(os.path.join(dp, f) for dp, dn, fn in os.walk(m.by_date) for f in fn)
        m.run('reassign', 3, 1)
        bl = by_loc(m)
        T.check(bl == {1: 6}, 'the 2 Sitio detections now under Casa (6 there, none left in Sitio)')
        T.check(all(abs(r['Lat'] - CASA['lat']) < 1e-6 and abs(r['Lon'] - CASA['lon']) < 1e-6 for r in m.rows()), 'their coordinates are those of Casa')
        T.check(sorted(os.path.join(dp, f) for dp, dn, fn in os.walk(m.by_date) for f in fn) == clips_before, 'no clip moved, copied or lost')
        T.check(list(m.reviews().values()) == ['yes'], 'the review kept')

        print('13. Move to another station (Sitio of station A -> folder -> station B)')
        n1 = T.Station(root, 'N1', 'pt_BR', [CASA, TEST, SITIO])
        T.fill_a(n1)
        mv = os.path.join(root, 'move-nas')
        os.makedirs(mv)
        n1.run('station', 3, mv)
        bm = T.newest(mv, 'BirdSuite-N1-Sitio')
        T.check(bm is not None and by_loc(n1) == {1: 4}, 'a backup of Sitio made and its 2 detections removed from station A (Casa keeps 4)')
        con = sqlite3.connect(n1.db)
        gone = con.execute('SELECT COUNT(*) FROM deleted_detections').fetchone()[0]
        con.close()
        T.check(gone == 2, 'the 2 removed ones restorable in station A (Removed / Purge Files) until purged')
        n2 = T.Station(root, 'N2', 'pt_BR', [dict(CASA, lat=-20.0, lon=-44.0), TEST])
        n2.run('restore', bm)
        sit = loc_named(n2, SITIO['lat'])
        T.check(len(sit) == 1 and by_loc(n2).get(int(sit[0]['id'])) == 2 and all(n2.clip_ok(r) for r in n2.rows()),
                'station B: Sitio created from the backup, its 2 detections and clips there')

        print('14. Copy between two locations of the same station (backup of Casa imported into Sitio)')
        k = T.Station(root, 'K1', 'pt_BR', [CASA, TEST, SITIO])
        T.fill_a(k)
        kb = os.path.join(root, 'copy-nas')
        os.makedirs(kb)
        k.run('backup', 1, kb)
        k.run('restore', T.newest(kb, 'BirdSuite-K1-Casa'), '--to', 3)
        T.check(by_loc(k) == {1: 4, 3: 2}, 'nothing copied: a recording is one detection in one place (Casa 4, Sitio 2 as before)')
    finally:
        shutil.rmtree(root, ignore_errors=True)
    print()
    print('ALL PASSED' if not T.failures else f'{len(T.failures)} FAILED: ' + '; '.join(T.failures))
    sys.exit(1 if T.failures else 0)


if __name__ == '__main__':
    main()
