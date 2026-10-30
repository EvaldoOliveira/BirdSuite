#!/usr/bin/env python3
"""Has the station moved? (owner 2026-10-10) Run at every boot by birdnet_location_check.service.

The Pi has no GPS: the network gives an approximate position (geolocation of the public IP: ipapi.co, then
ip-api.com — city level, several km off, so a small threshold can raise false moves). When it is farther than
the current location's radius, at least LOCATION_IP_ERROR_KM (default 30 km, owner 2026-10-21), from LATITUDE/LONGITUDE
the station changes nothing by itself: it records the finding in ~/BirdNET-Pi/state/location_check.json, notifies
through Apprise once, and the Latest page offers "Use this location" (coordinates, Brazilian state species list,
timezone) or "Not moved" (remembered; asked again only after another move of that distance).
LOCATION_CHECK=0 in BirdSuite.conf (Basic Settings) turns it off — the check sends the public IP to those services.
Standard library only. Usage: location_check.py [--force] [--json]
  --force  run even with LOCATION_CHECK=0;  --json  print the result as JSON (the Auto Locate Now button)
"""
import json
import math
import os
import subprocess
import sys
import time
import urllib.error
import urllib.parse
import urllib.request

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
try:
    from utils.i18n import t  # the station's language (US-122); standard library only, like this script
except Exception:
    def t(text, **values):
        for k, v in values.items():
            text = text.replace('{' + k + '}', str(v))
        return text

HOME = os.path.expanduser('~/BirdNET-Pi')
STATE = os.path.join(HOME, 'state', 'location_check.json')
LIMIT_KM = 30  # LOCATION_IP_ERROR_KM, raised to the current location's radius (owner 2026-10-21): the IP position is ~10-30 km off in a big city
UA = {'User-Agent': 'BirdSuite location check (private station)'}


def conf():
    out = {}
    with open(os.path.join(HOME, 'config', 'BirdSuite.conf'), encoding='utf-8') as f:
        for line in f:
            k, sep, v = line.strip().partition('=')
            if sep and not k.startswith('#'):
                out[k] = v.strip().strip('"')
    return out


def km(lat1, lon1, lat2, lon2):
    p1, p2 = math.radians(lat1), math.radians(lat2)
    a = math.sin((p2 - p1) / 2) ** 2 + math.cos(p1) * math.cos(p2) * math.sin(math.radians(lon2 - lon1) / 2) ** 2
    return 6371 * 2 * math.asin(math.sqrt(a))


def get(url):
    with urllib.request.urlopen(urllib.request.Request(url, headers=UA), timeout=8) as r:
        return json.load(r)


WIFI_NOTE = ''  # why the Wi-Fi position was not used (shown with --json)


def wifi_networks():
    """The Wi-Fi access points the station sees (nmcli; BSSID and signal), for a position like a phone's."""
    try:
        out = subprocess.run(['nmcli', '-t', '-f', 'BSSID,SIGNAL', 'device', 'wifi', 'list', '--rescan', 'auto'],
                             capture_output=True, text=True, timeout=20).stdout
    except Exception:
        return []
    aps = []
    for line in out.splitlines():
        parts = line.replace('\\:', '-').split(':')
        if len(parts) >= 2 and parts[1].strip().isdigit():
            # nmcli gives the signal in %; the services want dBm (about % / 2 - 100)
            aps.append({'macAddress': parts[0].replace('-', ':').lower(), 'signalStrength': int(parts[1]) // 2 - 100})
    return aps


def wifi_position(c):
    """By the Wi-Fi around the station, as Google Maps does on a phone (owner 2026-10-24: the IP position was kilometres
    off): Google's Geolocation API when GEOLOCATION_API_KEY is set, else BeaconDB (free, no key). None when it fails."""
    global WIFI_NOTE
    aps = wifi_networks()
    if len(aps) < 2:
        WIFI_NOTE = f'{len(aps)} Wi-Fi network(s) seen, 2 needed'
        return None
    key = (c.get('GEOLOCATION_API_KEY') or '').strip()
    urls = ([('https://www.googleapis.com/geolocation/v1/geolocate?key=' + urllib.parse.quote(key), 'google')] if key else []) \
        + [('https://api.beacondb.net/v1/geolocate', 'beacondb')]
    body = json.dumps({'considerIp': False, 'wifiAccessPoints': aps[:20]}).encode()
    notes = []
    for url, name in urls:
        try:
            req = urllib.request.Request(url, data=body, headers=dict(UA, **{'Content-Type': 'application/json'}))
            with urllib.request.urlopen(req, timeout=10) as r:
                j = json.load(r)
            loc = j.get('location') or {}
            if loc.get('lat') is not None and float(j.get('accuracy') or 99999) <= 5000:
                return {'lat': float(loc['lat']), 'lon': float(loc['lng']), 'accuracy_m': round(float(j.get('accuracy') or 0)), 'source': f'wifi ({name})'}
            notes.append(f'{name}: no position')
        except urllib.error.HTTPError as e:
            notes.append(f'{name}: HTTP {e.code}' + (' (these networks are not in its database)' if e.code == 404 else ''))
        except Exception as e:
            notes.append(f'{name}: {type(e).__name__}')
    WIFI_NOTE = f'{len(aps)} Wi-Fi networks seen; ' + '; '.join(notes)
    return None


def network_position():
    try:
        j = get('https://ipapi.co/json/')
        if j.get('latitude') is not None:
            return {'lat': float(j['latitude']), 'lon': float(j['longitude']), 'city': j.get('city', ''),
                    'region': j.get('region', ''), 'country': j.get('country_code', ''), 'timezone': j.get('timezone', ''),
                    'source': 'ipapi.co'}
    except Exception:
        pass
    j = get('http://ip-api.com/json/?fields=status,lat,lon,city,regionName,countryCode,timezone')
    if j.get('status') != 'success':
        raise RuntimeError('no network position')
    return {'lat': float(j['lat']), 'lon': float(j['lon']), 'city': j.get('city', ''), 'region': j.get('regionName', ''),
            'country': j.get('countryCode', ''), 'timezone': j.get('timezone', ''), 'source': 'ip-api.com'}


def notify(c, text):
    # through the station's notification module (owner 2026-10-23): the boxes hold $telegram_bot / $email_* placeholders
    # that only it resolves from the token store; this script runs with the system python, the module needs the venv's
    py = os.path.join(HOME, 'birdnet', 'bin', 'python3')
    if not os.access(py, os.X_OK):
        return
    code = ('import sys; sys.path.insert(0, sys.argv[1]); from utils import notifications; '
            'notifications.notify(sys.argv[2], sys.argv[3], queue=True)')
    subprocess.run([py, '-c', code, os.path.join(HOME, 'scripts'), text, f"BirdSuite {c.get('SITE_NAME') or os.uname().nodename}"],
                   stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL, timeout=90)


def main():
    c = conf()
    if c.get('LOCATION_CHECK', '1') == '0' and '--force' not in sys.argv:
        return
    global LIMIT_KM
    radius = 1  # the default radius of a location (owner 2026-10-25: 1 km, was 5)
    # beyond the current location's radius, never below the error of the IP position (owner 2026-10-21:
    # LOCATION_IP_ERROR_KM, default 30; the phone's GPS uses the radius as it is, scripts/field_cli.php)
    try:
        LIMIT_KM = max(1, int(float(c.get('LOCATION_IP_ERROR_KM') or 30)))
    except ValueError:
        LIMIT_KM = 30
    try:
        from utils import locations
        cur = locations.current(c)
        if cur:
            radius = math.ceil(float(cur.get('radius_km') or 1))
            LIMIT_KM = max(LIMIT_KM, radius)
    except Exception:
        pass
    try:
        lat, lon = float(c['LATITUDE']), float(c['LONGITUDE'])
    except (KeyError, ValueError):
        return
    pos = None
    for _ in range(12):  # the network may need a while after boot (Wi-Fi, hotspot)
        try:
            pos = network_position()
            break
        except Exception:
            time.sleep(10)
    # the Wi-Fi position, much closer than the IP one; the IP lookup still gives the place names and the timezone
    wifi = wifi_position(c)
    if wifi:
        pos = dict(pos or {'city': '', 'region': '', 'country': '', 'timezone': ''}, **wifi)
        # a Wi-Fi position is close: the location's radius (plus its accuracy) decides, not the error of an IP position
        LIMIT_KM = max(1, radius + math.ceil(wifi['accuracy_m'] / 1000))
    if pos is None:
        print(json.dumps({'error': t('no network position')}, ensure_ascii=False) if '--json' in sys.argv else 'location check: no network position')
        return
    try:
        with open(STATE, encoding='utf-8') as f:
            old = json.load(f)
    except (OSError, ValueError):
        old = {}
    dist = round(km(lat, lon, pos['lat'], pos['lon']))
    dismissed = old.get('dismissed')  # the network position the owner said "Not moved" to
    moved = dist > LIMIT_KM and not (dismissed and km(dismissed['lat'], dismissed['lon'], pos['lat'], pos['lon']) <= LIMIT_KM)
    state = dict(pos, checked=time.strftime('%Y-%m-%d %H:%M:%S'), distance_km=dist, moved=moved,
                 configured={'lat': lat, 'lon': lon}, dismissed=dismissed,
                 notified=old.get('notified') if moved else None)
    where = ', '.join(x for x in (pos['city'], pos['region'], pos['country']) if x)
    if moved and not (old.get('notified') and km(old['notified']['lat'], old['notified']['lon'], pos['lat'], pos['lon']) <= LIMIT_KM):
        notify(c, t('The station seems to be in {place}, about {km} km from its configured location.', place=where, km=dist) + ' '
               + t('Open the Latest page to use this location (coordinates, species list, timezone) or keep the current one.'))
        state['notified'] = {'lat': pos['lat'], 'lon': pos['lon']}
    tmp = STATE + '.tmp'
    with open(tmp, 'w', encoding='utf-8') as f:
        json.dump(state, f, ensure_ascii=False, indent=1)
    os.chmod(tmp, 0o664)  # the web pages (caddy, in the station user's group) record the answer in it
    os.replace(tmp, STATE)
    if '--json' in sys.argv:
        print(json.dumps(dict(pos, distance_km=dist, moved=moved, where=where, limit_km=LIMIT_KM, **({'wifi_note': WIFI_NOTE} if WIFI_NOTE else {})), ensure_ascii=False))
    else:
        print(f"location check: {where} ({pos['source']}), {dist} km from the configured location, moved={moved}")


if __name__ == '__main__':
    main()
