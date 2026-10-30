#!/usr/bin/env python3
"""The land cover around a latitude/longitude (owner 2026-10-29): is the place a city, a forest, a field?

ESA WorldCover 2021 v200 (10 m, Zanaga et al. 2022, CC BY 4.0), read online from its public cloud copy (AWS Open
Data, no account): only the 1024 x 1024 block holding the point is fetched (a few hundred KB), never the 3 x 3 degree
file. Classes: Tree cover, Shrubland, Grassland, Cropland, Built-up, Bare / sparse vegetation, Snow and ice, Permanent
water bodies, Herbaceous wetland, Mangroves, Moss and lichen. One 10 m pixel can be a garden tree in a city, so the
answer is the dominant class within RADIUS_M and the share of built-up land there. The setting (owner 2026-10-29) comes
from the built-up share within SETTING_RADIUS_M: Urban from URBAN_PCT %, Peri-urban from PERI_PCT %, else Natural (the
usual limits of the urban-gradient studies of birds; the share is kept, so the data can be cut again). Standard
library only.

Usage: land_cover.py <latitude> <longitude>
Prints JSON {"land_cover": <dominant class within 100 m>, "built_up_pct": <0-100>, "at_point": <class of the pixel>,
"setting": Urban | Peri-urban | Natural, "built_up_500_pct": <0-100>}
or {"error": ...} (no internet, sea outside every block); exit 0 either way.
"""
import json
import math
import struct
import sys
import urllib.request
import zlib

URL = 'https://esa-worldcover.s3.eu-central-1.amazonaws.com/v200/2021/map/ESA_WorldCover_10m_2021_v200_{tile}_Map.tif'
CLASSES = {10: 'Tree cover', 20: 'Shrubland', 30: 'Grassland', 40: 'Cropland', 50: 'Built-up', 60: 'Bare / sparse vegetation',
           70: 'Snow and ice', 80: 'Permanent water bodies', 90: 'Herbaceous wetland', 95: 'Mangroves', 100: 'Moss and lichen'}
RADIUS_M = 100
SETTING_RADIUS_M = 500
URBAN_PCT, PERI_PCT = 50, 10
UA = {'User-Agent': 'BirdSuite land cover (private station)'}


def fetch(url, start, end):
    req = urllib.request.Request(url, headers=dict(UA, Range=f'bytes={start}-{end}'))
    with urllib.request.urlopen(req, timeout=20) as r:
        return r.read()


def header(url):
    """The few tags needed of a tiled, deflate-compressed, 8-bit GeoTIFF (classic TIFF, as WorldCover is)."""
    h = fetch(url, 0, 65535)
    bo = '<' if h[:2] == b'II' else '>'
    if struct.unpack(bo + 'H', h[2:4])[0] != 42:
        raise ValueError('not a classic TIFF')
    off = struct.unpack(bo + 'I', h[4:8])[0]
    n = struct.unpack(bo + 'H', h[off:off + 2])[0]
    size = {1: 1, 2: 1, 3: 2, 4: 4, 12: 8}
    fmt = {1: 'B', 2: 'c', 3: 'H', 4: 'I', 12: 'd'}
    tags = {}
    for i in range(n):
        e = h[off + 2 + i * 12: off + 14 + i * 12]
        tag, typ, cnt = struct.unpack(bo + 'HHI', e[:8])
        nbytes = size.get(typ, 1) * cnt
        raw = e[8:8 + nbytes] if nbytes <= 4 else None
        if raw is None:
            p = struct.unpack(bo + 'I', e[8:12])[0]
            raw = h[p:p + nbytes] if p + nbytes <= len(h) else fetch(url, p, p + nbytes - 1)
        if typ in fmt and typ != 2:
            tags[tag] = struct.unpack(bo + fmt[typ] * cnt, raw)
    if tags.get(259, (1,))[0] != 8 or tags.get(317, (1,))[0] != 1:
        raise ValueError('unexpected compression')
    return {'w': tags[256][0], 'h': tags[257][0], 'tw': tags[322][0], 'th': tags[323][0],
            'offsets': tags[324], 'counts': tags[325], 'scale': tags[33550], 'tie': tags[33922]}


def land_cover(lat, lon):
    tlat = math.floor(lat / 3) * 3
    tlon = math.floor(lon / 3) * 3
    tile = f"{'S' if tlat < 0 else 'N'}{abs(tlat):02d}{'W' if tlon < 0 else 'E'}{abs(tlon):03d}"
    url = URL.format(tile=tile)
    try:
        g = header(url)
    except urllib.error.HTTPError as e:
        if e.code in (403, 404):
            return {'error': 'no land cover here (sea)'}
        raise
    sx, sy = g['scale'][0], g['scale'][1]
    x0, y0 = g['tie'][3], g['tie'][4]
    col, row = int((lon - x0) / sx), int((y0 - lat) / sy)
    r = max(1, int(round(RADIUS_M / (sy * 111320))))  # pixels in the radius (a pixel is ~10 m)
    tiles_across = (g['w'] + g['tw'] - 1) // g['tw']
    blocks = {}

    def pixel(c, rr):
        if not (0 <= c < g['w'] and 0 <= rr < g['h']):
            return None
        k = (rr // g['th']) * tiles_across + c // g['tw']
        if k not in blocks:
            o, n = g['offsets'][k], g['counts'][k]
            blocks[k] = zlib.decompress(fetch(url, o, o + n - 1))
        return blocks[k][(rr % g['th']) * g['tw'] + c % g['tw']]
    def count(radius):
        out = {}
        for dr in range(-radius, radius + 1):
            for dc in range(-radius, radius + 1):
                if dr * dr + dc * dc > radius * radius:
                    continue
                v = pixel(col + dc, row + dr)
                if v in CLASSES:
                    out[v] = out.get(v, 0) + 1
        return out
    counts = count(r)
    if not counts:
        return {'error': 'no land cover here'}
    total = sum(counts.values())
    top = max(counts, key=counts.get)
    at = pixel(col, row)
    wide = count(max(1, int(round(SETTING_RADIUS_M / (sy * 111320)))))
    built = round(100 * wide.get(50, 0) / max(1, sum(wide.values())))
    return {'land_cover': CLASSES[top], 'built_up_pct': round(100 * counts.get(50, 0) / total), 'at_point': CLASSES.get(at, ''),
            'setting': 'Urban' if built >= URBAN_PCT else 'Peri-urban' if built >= PERI_PCT else 'Natural', 'built_up_500_pct': built}


if __name__ == '__main__':
    try:
        print(json.dumps(land_cover(float(sys.argv[1]), float(sys.argv[2]))))
    except (IndexError, ValueError) as e:
        print(json.dumps({'error': str(e) or 'usage: land_cover.py <latitude> <longitude>'}))
    except Exception as e:  # no internet, the service down: the page says so
        print(json.dumps({'error': f'no answer from ESA WorldCover ({e.__class__.__name__})'}))
