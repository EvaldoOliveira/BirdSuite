"""Locations of the station (US-58, owner 2026-10-21): every detection carries the id of the location it was recorded
at (column Location of detections, deleted_detections and the shadow database), and the pages filter on that tag.
Two locations may share their coordinates (the same building, a microphone at another window): the tag tells them
apart, the coordinates do not.

The current location is LOCATION_ID in BirdSuite.conf (written when the location changes, scripts/common.php
location_activate). The schema (columns, location_biomes and its triggers) is scripts/createdb.sh."""
import json
import math
import os

try:
    from . import core
except ImportError:  # run as a script: python3 utils/locations.py biomes
    import core

BASE_PATH = core.BS_PROG
# config/locations/ holds locations.json and each location's own files (owner 2026-11-02)
LOCATIONS_FILE = core.BS_LOCATIONS_FILE
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
    """The location holding a point: inside its radius, the nearest centre; None outside all."""
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
    if loc is None and locs:
        # nothing holds the coordinates (a fresh station before its first run set them): the first location, never a
        # detection without a place (Gitea #91: the first 23 minutes of a new station belonged to no location)
        loc = next((l for l in locs if l.get('builtin') != 'test'), locs[0])
    return int(loc['id']) if loc else None


def assign_orphans(con, lid, locs=None):
    """Files the detections that belong to no location (an empty tag, or the id of a location that no longer exists)
    under location lid (Station > Locations, Gitea #93); returns how many."""
    ids = [int(l['id']) for l in (locs if locs is not None else load())]
    if int(lid) not in ids:
        return 0
    cond = "Location IS NULL OR TRIM(CAST(Location AS TEXT)) = '' OR CAST(Location AS INTEGER) NOT IN (%s)" % ','.join(str(i) for i in ids)
    n = con.execute('UPDATE detections SET Location = ? WHERE ' + cond, (int(lid),)).rowcount
    con.execute('UPDATE deleted_detections SET Location = ? WHERE ' + cond, (int(lid),))
    con.commit()
    return n


def current(conf):
    lid = current_id(conf)
    return next((loc for loc in load() if int(loc['id']) == lid), None) if lid is not None else None


# Land_Cover / Built_Up / Setting / Built_Up_500: ESA WorldCover (2026-10-29)
BIOME_COLS = ('Biome', 'Ecoregion', 'Biome_BR', 'Land_Cover', 'Built_Up', 'Setting', 'Built_Up_500')
LOC_KEYS = {'Biome': 'biome', 'Ecoregion': 'ecoregion', 'Biome_BR': 'biome_br', 'Land_Cover': 'land_cover', 'Built_Up': 'built_up_pct',
            'Setting': 'setting', 'Built_Up_500': 'built_up_500_pct'}
INT_COLS = ('Built_Up', 'Built_Up_500')


def biome_rows(con, locs=None):
    """US-27 (owner 2026-10-29): every detection carries the biome, ecoregion and Brazilian biome of its location and
    its land cover (columns of detections and deleted_detections, scripts/createdb.sh), for filtering the exported
    data later. location_biomes holds them per location (from locations.json, filled by 'locations.py biomes'); the
    triggers of createdb.sh write them on every new detection and when a detection moves to another location. The
    detections recorded while their location had no biome or land cover yet get them here."""
    locs = locs if locs is not None else load()
    for loc in locs:
        if 'biome' in loc:
            con.execute(f'INSERT INTO location_biomes (Location, {", ".join(BIOME_COLS)}) VALUES (?{", ?" * len(BIOME_COLS)}) '
                        'ON CONFLICT(Location) DO UPDATE SET '
                        + ', '.join(f'{c} = excluded.{c}' for c in BIOME_COLS),
                        [int(loc['id'])] + [loc.get(LOC_KEYS[c]) if c in INT_COLS else (loc.get(LOC_KEYS[c]) or '') for c in BIOME_COLS])
    for table in ('detections', 'deleted_detections'):
        con.execute(f'UPDATE {table} SET {", ".join(f"{c} = (SELECT {c} FROM location_biomes b WHERE b.Location = {table}.Location)" for c in BIOME_COLS)} '
                    f'WHERE Biome IS NULL AND Location IN (SELECT Location FROM location_biomes)')
        # the land cover is an online lookup: it may come after the biome
        land = BIOME_COLS[3:]
        con.execute(f'UPDATE {table} SET {", ".join(f"{c} = (SELECT {c} FROM location_biomes b WHERE b.Location = {table}.Location)" for c in land)} '
                    f"WHERE Setting IS NULL AND Location IN (SELECT Location FROM location_biomes WHERE COALESCE(Setting, '') != '')")
    con.commit()


def fill_biomes():
    """The biome of every location of locations.json whose coordinates have none yet or changed (scripts/locate_biome.py);
    True when the file changed."""
    import sys
    sys.path.insert(0, os.path.join(BASE_PATH, 'scripts'))
    from land_cover import land_cover
    from locate_biome import biome_of
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
        os.makedirs(os.path.dirname(LOCATIONS_FILE), exist_ok=True)
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
    # python3 scripts/utils/locations.py biomes: the biome of each location (after a save of the locations), then on
    # their detections
    import sqlite3
    import sys
    if sys.argv[1:] == ['biomes']:
        fill_biomes()
        db = core.BS_DB
        if os.path.exists(db):
            c = sqlite3.connect(db, timeout=60)
            biome_rows(c)
            c.close()
    elif len(sys.argv) == 3 and sys.argv[1] == 'assign-orphans' and sys.argv[2].isdigit():
        # python3 locations.py assign-orphans <id>: the detections without a location filed under that one; prints the count
        c = sqlite3.connect(core.BS_DB, timeout=60)
        n = assign_orphans(c, int(sys.argv[2]))
        biome_rows(c)   # their biome columns follow the location
        c.close()
        print(n)
    else:
        print('usage: python3 locations.py biomes | assign-orphans <location id>')
        sys.exit(2)
