"""Locations of the station (US-58, owner 2026-10-21): every detection carries the id of the location it was recorded
at (column Location of detections, deleted_detections and the shadow database), and the pages filter on that tag.
Two locations may share their coordinates (the same building, a microphone at another window): the tag tells them
apart, the coordinates do not.

The current location is LOCATION_ID in BirdSuite.conf (written when the location changes, scripts/common.php
location_activate). Rows without a tag (written before the column existed) are tagged once by their coordinates, as
the old rule did: inside a location's radius and nearer its centre than any other overlapping location's
(scripts/common.php location_sql keeps the same rule for any row still untagged)."""
import json
import math
import os

BASE_PATH = os.path.dirname(os.path.dirname(os.path.dirname(os.path.abspath(__file__))))
LOCATIONS_FILE = os.path.join(BASE_PATH, 'config', 'locations.json')
RADIUS_KM = 1  # the default radius of a location (owner 2026-10-25: 1 km, was 5)


def load():
    try:
        with open(LOCATIONS_FILE, encoding='utf-8') as f:
            locs = json.load(f)
        return [loc for loc in locs if isinstance(loc, dict) and 'id' in loc]
    except (OSError, ValueError):
        return []


def km(lat1, lon1, lat2, lon2):
    p = math.pi / 180
    a = (math.sin((lat2 - lat1) * p / 2) ** 2
         + math.cos(lat1 * p) * math.cos(lat2 * p) * math.sin((lon2 - lon1) * p / 2) ** 2)
    return 12742 * math.asin(math.sqrt(min(1.0, a)))


def at_point(lat, lon, locs=None):
    """The location the old rule files a point in: inside its radius, the nearest centre; None outside all."""
    best = None
    for loc in locs if locs is not None else load():
        try:
            d = km(float(lat), float(lon), float(loc['lat']), float(loc['lon']))
        except (KeyError, TypeError, ValueError):
            continue
        if d <= float(loc.get('radius_km', RADIUS_KM)) and (best is None or d < best[0]):
            best = (d, loc)
    return best[1] if best else None


def current_id(conf):
    """LOCATION_ID of BirdSuite.conf when it names a registered location, else the location holding the coordinates."""
    locs = load()
    try:
        lid = int(str(conf.get('LOCATION_ID') or '').strip().strip('"'))
        if any(int(loc['id']) == lid for loc in locs):
            return lid
    except (ValueError, TypeError):
        pass
    try:
        loc = at_point(float(conf.get('LATITUDE') or 0), float(conf.get('LONGITUDE') or 0), locs)
    except (TypeError, ValueError):
        loc = None
    return int(loc['id']) if loc else None


def current(conf):
    lid = current_id(conf)
    return next((loc for loc in load() if int(loc['id']) == lid), None) if lid is not None else None


def point_sql(loc, locs):
    """SQL condition and arguments of the old coordinate rule for one location (used to tag old rows)."""
    r = float(loc.get('radius_km', RADIUS_KM))
    lat, lon = float(loc['lat']), float(loc['lon'])
    dlat = r / 111.0
    dlon = r / max(0.01, 111.0 * math.cos(math.radians(lat)))
    cond = 'Lat BETWEEN ? AND ? AND Lon BETWEEN ? AND ?'
    args = [lat - dlat, lat + dlat, lon - dlon, lon + dlon]
    c = max(0.01, math.cos(math.radians(lat)))
    d2 = '((Lat - ?) * (Lat - ?) + ((Lon - ?) * ?) * ((Lon - ?) * ?))'
    for o in locs:
        if int(o['id']) == int(loc['id']):
            continue
        if km(lat, lon, float(o['lat']), float(o['lon'])) > r + float(o.get('radius_km', RADIUS_KM)):
            continue
        cond += f' AND {d2} <= {d2}'
        args += [lat, lat, lon, c, lon, c, float(o['lat']), float(o['lat']), float(o['lon']), c, float(o['lon']), c]
    return cond, args


def where(loc, locs=None, con=None):
    """The detections of a location: its tag, or (untagged rows) the coordinate rule. With a connection that has no
    untagged row left, the tag alone (an index search instead of a scan)."""
    locs = locs if locs is not None else load()
    if con is not None:
        try:
            if not con.execute('SELECT 1 FROM detections WHERE Location IS NULL LIMIT 1').fetchone():
                return 'Location = ?', [int(loc['id'])]
        except Exception:
            pass
    cond, args = point_sql(loc, locs)
    return f'(Location = ? OR (Location IS NULL AND {cond}))', [int(loc['id'])] + args


def ensure_column(con, tables=('detections', 'deleted_detections')):
    """Adds the Location column and its indexes, then tags the untagged rows by their coordinates. Cheap when done."""
    locs = load()
    for table in tables:
        have = {r[1] for r in con.execute(f'PRAGMA table_info({table})')}
        if not have:
            continue
        if 'Location' not in have:
            con.execute(f'ALTER TABLE {table} ADD COLUMN Location INTEGER')
        if table == 'detections':
            # covering indexes of the location's pages (owner 2026-10-21): the period cards and charts (By Hour, Now)
            # and the per-species counts (Validation, Species) are read from the index alone
            con.execute('CREATE INDEX IF NOT EXISTS detections_Location_cover ON detections (Location, Date, Sci_Name, Time, Confidence, File_Name)')
            con.execute('CREATE INDEX IF NOT EXISTS detections_Location_species ON detections (Location, Sci_Name, Confidence, Com_Name, File_Name)')
            con.execute('DROP INDEX IF EXISTS detections_Location')  # a prefix of detections_Location_cover
            con.execute('CREATE INDEX IF NOT EXISTS detections_Sci_Date_Location ON detections (Sci_Name, Date, Location, File_Name)')
            # a detection is looked up by its file everywhere (reviews, delete, restore): without it a join scans the table
            con.execute('CREATE INDEX IF NOT EXISTS detections_File_Name ON detections (File_Name)')
        if table == 'deleted_detections':
            con.execute('CREATE INDEX IF NOT EXISTS deleted_File_Name ON deleted_detections (File_Name)')
        if locs and con.execute(f'SELECT 1 FROM {table} WHERE Location IS NULL LIMIT 1').fetchone():
            for loc in locs:
                cond, args = point_sql(loc, locs)
                con.execute(f'UPDATE {table} SET Location = ? WHERE Location IS NULL AND {cond}', [int(loc['id'])] + args)
    if 'deleted_detections' in tables:  # the station database (birds.db), not the shadow one
        ensure_biome(con, locs)
    con.commit()


# Land_Cover / Built_Up / Setting / Built_Up_500: ESA WorldCover (2026-10-29)
BIOME_COLS = ('Biome', 'Ecoregion', 'Biome_BR', 'Land_Cover', 'Built_Up', 'Setting', 'Built_Up_500')
LOC_KEYS = {'Biome': 'biome', 'Ecoregion': 'ecoregion', 'Biome_BR': 'biome_br', 'Land_Cover': 'land_cover', 'Built_Up': 'built_up_pct',
            'Setting': 'setting', 'Built_Up_500': 'built_up_500_pct'}
INT_COLS = ('Built_Up', 'Built_Up_500')


def ensure_biome(con, locs=None):
    """US-27 (owner 2026-10-29): every detection carries the biome, ecoregion and Brazilian biome of its location and
    its land cover (columns Biome, Ecoregion, Biome_BR, Land_Cover, Built_Up of detections and deleted_detections), for
    filtering the exported data later.
    location_biomes holds them per location (from locations.json, filled by 'locations.py biomes'); triggers write them
    on every new detection whatever wrote it (the analysis, Import, External files, an undone delete) and when a
    detection moves to another location. A detection keeps the values of the moment it was recorded or moved."""
    locs = locs if locs is not None else load()
    for table in ('detections', 'deleted_detections'):
        have = {r[1] for r in con.execute(f'PRAGMA table_info({table})')}
        if not have:
            continue
        for col in BIOME_COLS:
            if col not in have:
                con.execute(f'ALTER TABLE {table} ADD COLUMN {col} {"INTEGER" if col in INT_COLS else "TEXT"}')
    con.execute('CREATE TABLE IF NOT EXISTS location_biomes (Location INTEGER PRIMARY KEY, Biome TEXT, Ecoregion TEXT, Biome_BR TEXT)')
    have = {r[1] for r in con.execute('PRAGMA table_info(location_biomes)')}
    for col in BIOME_COLS[3:]:
        if col not in have:
            con.execute(f'ALTER TABLE location_biomes ADD COLUMN {col} {"INTEGER" if col in INT_COLS else "TEXT"}')
    for loc in locs:
        if 'biome' in loc:
            con.execute(f'INSERT INTO location_biomes (Location, {", ".join(BIOME_COLS)}) VALUES (?{", ?" * len(BIOME_COLS)}) ON CONFLICT(Location) DO UPDATE SET '
                        + ', '.join(f'{c} = excluded.{c}' for c in BIOME_COLS),
                        [int(loc['id'])] + [loc.get(LOC_KEYS[c]) if c in INT_COLS else (loc.get(LOC_KEYS[c]) or '') for c in BIOME_COLS])
    sets = ', '.join(f'{c} = (SELECT {c} FROM location_biomes WHERE Location = NEW.Location)' for c in BIOME_COLS)
    # written again each time: the columns they set grew with Land_Cover / Built_Up
    con.execute('DROP TRIGGER IF EXISTS detections_biome_insert')
    con.execute('DROP TRIGGER IF EXISTS detections_biome_move')
    con.execute(f'CREATE TRIGGER detections_biome_insert AFTER INSERT ON detections WHEN NEW.Biome IS NULL AND NEW.Location IS NOT NULL '
                f'BEGIN UPDATE detections SET {sets} WHERE rowid = NEW.rowid; END')
    con.execute(f'CREATE TRIGGER detections_biome_move AFTER UPDATE OF Location ON detections WHEN NEW.Location IS NOT OLD.Location '
                f'BEGIN UPDATE detections SET {sets} WHERE rowid = NEW.rowid; END')
    # the rows written before (or while a location had no biome yet): once, by their location
    for table in ('detections', 'deleted_detections'):
        if {r[1] for r in con.execute(f'PRAGMA table_info({table})')} >= set(BIOME_COLS):
            con.execute(f'UPDATE {table} SET {", ".join(f"{c} = (SELECT {c} FROM location_biomes b WHERE b.Location = {table}.Location)" for c in BIOME_COLS)} '
                        f'WHERE Biome IS NULL AND Location IN (SELECT Location FROM location_biomes)')
            # the land cover and the setting came later than the biome (2026-10-29): rows with a biome and none yet
            land = BIOME_COLS[3:]
            con.execute(f'UPDATE {table} SET {", ".join(f"{c} = (SELECT {c} FROM location_biomes b WHERE b.Location = {table}.Location)" for c in land)} '
                        f"WHERE Setting IS NULL AND Location IN (SELECT Location FROM location_biomes WHERE COALESCE(Setting, '') != '')")


def fill_biomes():
    """The biome of every location of locations.json whose coordinates have none yet or changed (scripts/locate_biome.py);
    True when the file changed."""
    import sys
    sys.path.insert(0, os.path.join(BASE_PATH, 'scripts'))
    from locate_biome import biome_of
    from land_cover import land_cover
    try:
        with open(LOCATIONS_FILE, encoding='utf-8') as f:
            locs = json.load(f)
    except (OSError, ValueError):
        return False
    changed = False
    for loc in locs:
        if not isinstance(loc, dict) or 'lat' not in loc:
            continue
        at = f"{float(loc['lat']):.4f},{float(loc['lon']):.4f}"
        if loc.get('biome_at') != at or 'biome' not in loc:
            loc.update(biome_of(float(loc['lat']), float(loc['lon'])), biome_at=at)
            loc.pop('land_at', None)
            changed = True
        # the land cover (ESA WorldCover, online): asked again at the next run while it got no answer (no internet)
        if (loc.get('land_at') != at or 'setting' not in loc) and not os.environ.get('BIRDSUITE_OFFLINE'):
            lc = land_cover(float(loc['lat']), float(loc['lon']))
            if 'error' not in lc:
                loc.update(land_cover=lc['land_cover'], built_up_pct=lc['built_up_pct'], setting=lc['setting'],
                           built_up_500_pct=lc['built_up_500_pct'], land_at=at)
                changed = True
    if changed:
        tmp = LOCATIONS_FILE + '.tmp'
        with open(tmp, 'w', encoding='utf-8') as f:
            f.write(json.dumps(locs, indent=4, ensure_ascii=False) + '\n')
        os.chmod(tmp, 0o664)
        if os.geteuid() == 0:
            # run by the updater as root (2026-10-29: the file became root's and the pages could no longer save the
            # locations): the owner of config/, the station user
            st = os.stat(os.path.dirname(LOCATIONS_FILE))
            os.chown(tmp, st.st_uid, st.st_gid)
        os.replace(tmp, LOCATIONS_FILE)
    return changed


if __name__ == '__main__':
    # python3 scripts/utils/locations.py migrate: the column, the indexes and the tags (run by the updater)
    import sqlite3
    import sys
    if sys.argv[1:] in (['migrate'], ['biomes']):
        # biomes (US-27): the biome of each location (after a save of the locations), then the columns and tables
        fill_biomes()
        db = os.path.join(BASE_PATH, 'config', 'data', 'birds.db')
        if os.path.exists(db):
            c = sqlite3.connect(db, timeout=60)
            ensure_column(c)
            n = c.execute('SELECT COUNT(*) FROM detections WHERE Location IS NULL').fetchone()[0]
            c.close()
            print(f'locations: tags in place, {n} detections outside every location')
        shadow = os.path.join(BASE_PATH, 'scripts', 'birds_shadow.db')
        if os.path.exists(shadow):
            c = sqlite3.connect(shadow, timeout=60)
            ensure_column(c, ('detections',))
            c.close()
    else:
        print('usage: python3 locations.py migrate|biomes')
        sys.exit(2)
