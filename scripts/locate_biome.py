#!/usr/bin/env python3
"""The biome and ecoregion of a latitude/longitude (US-27, owner 2026-10-29), offline.

Worldwide: RESOLVE Ecoregions 2017 (Dinerstein et al. 2017, BioScience 67:534, CC BY 4.0), 14 biomes and 846
ecoregions, simplified to about 3 km in model/maps/ecoregions.json.gz. In Brazil also the IBGE biome (Biomas do
Brasil 1:250.000, model/maps/br-biomes.json.gz): Amazon, Caatinga, Cerrado, Atlantic Forest, Pampa, Pantanal.
A point just outside every outline (a coast, the simplification) takes the outline of a point around it, up to ~5 km.
Standard library only, like locate_state.py. The names are English (the pages translate the biomes).

Usage: locate_biome.py <latitude> <longitude>
Prints JSON {"biome": ..., "ecoregion": ..., "biome_br": ...} (empty strings where none); exit 0 either way.
"""
import gzip
import json
import os
import sys

MAPS = os.path.join(os.path.dirname(os.path.abspath(__file__)), '..', 'model', 'maps')


def load(name):
    try:
        with gzip.open(os.path.join(MAPS, name), 'rt', encoding='utf-8') as f:
            return json.load(f)
    except (OSError, ValueError):
        return None


def inside(x, y, ring):
    hit = False
    j = len(ring) - 1
    for i in range(len(ring)):
        xi, yi = ring[i]
        xj, yj = ring[j]
        if (yi > y) != (yj > y) and x < (xj - xi) * (y - yi) / ((yj - yi) or 1e-12) + xi:
            hit = not hit
        j = i
    return hit


def find(data, lat, lon):
    if not data:
        return None
    for e in data['ecoregions']:
        b = e['bb']
        if not (b[0] <= lon <= b[2] and b[1] <= lat <= b[3]):
            continue
        for poly in e['p']:
            if inside(lon, lat, poly[0]) and not any(inside(lon, lat, h) for h in poly[1:]):
                return e
    return None


def find_near(data, lat, lon):
    hit = find(data, lat, lon)
    d = 0.045  # ~5 km
    for dy, dx in ((0, d), (0, -d), (d, 0), (-d, 0), (d, d), (d, -d), (-d, d), (-d, -d)):
        if hit:
            break
        hit = find(data, lat + dy, lon + dx)
    return hit


def biome_of(lat, lon):
    out = {'biome': '', 'ecoregion': '', 'biome_br': ''}
    world = load('ecoregions.json.gz')
    e = find_near(world, lat, lon)
    if e:
        out['biome'] = world['biomes'].get(str(e['b']), '')
        out['ecoregion'] = e['e']
    # Brazil only: its bounding box first, the file is read only there
    if -34.0 <= lat <= 5.5 and -74.0 <= lon <= -28.6:
        br = find_near(load('br-biomes.json.gz'), lat, lon)
        if br:
            out['biome_br'] = br['e']
    return out


if __name__ == '__main__':
    try:
        print(json.dumps(biome_of(float(sys.argv[1]), float(sys.argv[2])), ensure_ascii=False))
    except (IndexError, ValueError):
        print(__doc__.strip().splitlines()[-2], file=sys.stderr)
        print(json.dumps({'biome': '', 'ecoregion': '', 'biome_br': ''}))
