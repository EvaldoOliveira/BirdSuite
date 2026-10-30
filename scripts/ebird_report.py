#!/usr/bin/env python3
"""eBird (owner 2026-10-27): the species of a day, a week or a month as a table by day and as eBird checklists, with a
filter (only the species confirmed by ear, every detection from a minimum confidence, or every detection not answered
No), sent by e-mail on request or every week by the schedule. Every species row carries the weather at the hour it was
first heard and every checklist the day's weather (Open-Meteo, no key; N/A without internet).

  ebird_report.py days FROM TO [mode] [min]   JSON of the table for those days (at most 31: a day, a week, a month)
  ebird_report.py csv FROM TO [mode] [min] [skip]   the eBird Record Format file (one checklist per day and location);
                                              skip = scientific names joined by | (the species unticked on the page)
  ebird_report.py clips FROM TO [mode] [min] [skip]  JSON: the best detections of every bird (the page's sounds zip)
  ebird_report.py name FROM TO                the report's name (EBIRD_REPORT_NAME) for those dates, as a file name
  ebird_report.py send [FROM TO [mode] [min] [skip]]   e-mail the report now: those days with that filter (the page's
                                              button), else the scheduled one (the last seven days, the schedule's filter,
                                              the station's species lists)
  ebird_report.py due                         cron: send when this week's EBIRD_REPORT_DAY / EBIRD_REPORT_TIME has passed
                                              and its report has not gone yet
  mode: confirmed (only the species confirmed by ear), minconf (from min, default EBIRD_REPORT_MIN_CONF, and the
  confirmed ones) or all (every detection not answered No)

eBird Record Format (eBird › Submit › Import data, "eBird Record Format (Extended)"): no header line; Common Name,
Genus, Species, Number, Species Comments, Location Name, Latitude, Longitude, Date (MM/DD/YYYY), Start Time (HH:MM),
State/Province, Country Code, Protocol, Number of Observers, Duration (min), All observations reported (Y/N), Effort
Distance Miles, Effort area acres, Submission Comments. The checklists are Stationary, one observer, not complete
(only the species of the filter), the number X (present), the effort from the first to the last detection of the day.
"""
import csv
import datetime
import glob
import io
import json
import os
import re
import sqlite3
import sys
import tempfile
import urllib.request

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
from utils.helpers import get_settings, DB_PATH, BASE_PATH, MODEL_PATH  # noqa: E402
from utils import locations  # noqa: E402
try:
    from utils.i18n import t, fmt_num  # the page's language (US-122)
except Exception:
    def t(text, **values):
        for k, v in values.items():
            text = text.replace('{' + k + '}', str(v))
        return text

    def fmt_num(n, dec=0):
        return f'{n:.{dec}f}'

STATE = os.path.join(BASE_PATH, 'state')
WEATHER_DIR = os.path.join(STATE, 'weather')
SENT = os.path.join(STATE, 'ebird_report.json')
UA = {'User-Agent': 'BirdSuite (https://github.com/EvaldoOliveira/BirdSuite)'}

# WMO weather codes of Open-Meteo, in a few words; the English text goes to eBird, t() of it to the page and the e-mail
WMO = [((0,), 'clear sky'), ((1, 2), 'partly cloudy'), ((3,), 'overcast'), ((45, 48), 'fog'), ((51, 53, 55, 56, 57), 'drizzle'),
       ((61, 63, 66, 80, 81), 'rain'), ((65, 67, 82), 'heavy rain'), ((71, 73, 75, 77, 85, 86), 'snow'),
       ((95, 96, 99), 'thunderstorm')]


def wmo_text(code):
    for codes, text in WMO:
        if code in codes:
            return text
    return ''


def db():
    return sqlite3.connect(f'file:{DB_PATH}?mode=ro', uri=True, timeout=10)


def has_location(con):
    return any(r[1] == 'Location' for r in con.execute('PRAGMA table_info(detections)'))


def english_names():
    try:
        with open(os.path.join(MODEL_PATH, 'l18n', 'labels_en.json'), encoding='utf-8') as f:
            return json.load(f)
    except (OSError, ValueError):
        return {}


def confirmed(date_from, date_to):
    """Every detection answered Yes (also changed to this species) between the dates: (sci, com, date, time, conf, loc,
    confirmed = 1)."""
    con = db()
    try:
        if not con.execute("SELECT 1 FROM sqlite_master WHERE type='table' AND name='detection_reviews'").fetchone():
            return []
        loc = 'd.Location' if has_location(con) else 'NULL'
        return con.execute(f"""SELECT r.Sci_Name, r.Com_Name, d.Date, d.Time, d.Confidence, {loc}, 1, d.File_Name
            FROM detection_reviews r JOIN detections d ON d.File_Name = r.File_Name
            WHERE r.Verdict = 'yes' AND d.Date BETWEEN ? AND ? ORDER BY d.Date, d.Time""", (date_from, date_to)).fetchall()
    finally:
        con.close()


def effort(date_from, date_to):
    """{(date, loc): (first time, last time)} of every detection: the station listened at least that long."""
    con = db()
    try:
        loc = 'Location' if has_location(con) else 'NULL'
        return {(r[0], r[1]): (r[2], r[3]) for r in con.execute(
            f'SELECT Date, {loc}, MIN(Time), MAX(Time) FROM detections WHERE Date BETWEEN ? AND ? GROUP BY Date, {loc}',
            (date_from, date_to))}
    finally:
        con.close()


def birds():
    """{sci: English eBird name} of the birds the model knows (owner 2026-10-27: the eBird file holds birds only, an import
    stops at an insect, a frog or a noise): the class column of the V3 labels, else the eBird codes of scripts/ebird.php."""
    out = {}
    for path in glob.glob(os.path.join(MODEL_PATH, '*_Labels.csv')):
        try:
            with open(path, encoding='utf-8') as f:
                for r in csv.DictReader(f, delimiter=';'):
                    if (r.get('class') or '') == 'Aves' and r.get('sci_name'):
                        out[r['sci_name']] = r.get('com_name') or ''
        except (OSError, csv.Error):
            pass
    if not out:
        try:
            with open(os.path.join(os.path.dirname(os.path.abspath(__file__)), 'ebird.php'), encoding='utf-8') as f:
                out = {m.group(1): '' for m in re.finditer(r'"([^"]+)" => "([^"]+)"', f.read()) if m.group(2) != 'null'}
        except OSError:
            pass
    return out


def region(loc, lat, lon):
    """(state, country) codes of a location for eBird (State/Province "SP", Country Code "BR"): from its species list
    when that is a region (BR-SP), else from OpenStreetMap's reverse geocoding (kept in state/geocode.json), else blank."""
    code = str((loc or {}).get('species_list') or '')
    if re.fullmatch(r'[A-Z]{2}-[A-Z0-9]{1,3}', code):
        return code.split('-')[1], code.split('-')[0]
    key = f'{lat:.2f},{lon:.2f}'
    path = os.path.join(STATE, 'geocode.json')
    try:
        with open(path, encoding='utf-8') as f:
            cache = json.load(f)
    except (OSError, ValueError):
        cache = {}
    if key not in cache:
        try:
            url = f'https://nominatim.openstreetmap.org/reverse?format=jsonv2&zoom=5&lat={lat:.4f}&lon={lon:.4f}'
            with urllib.request.urlopen(urllib.request.Request(url, headers=UA), timeout=10) as r:
                a = json.load(r).get('address') or {}
            iso = a.get('ISO3166-2-lvl4') or a.get('ISO3166-2-lvl3') or ''
            cache[key] = [iso.split('-')[1] if '-' in iso else '', (a.get('country_code') or '').upper()]
            os.makedirs(STATE, exist_ok=True)
            with open(path, 'w', encoding='utf-8') as f:
                json.dump(cache, f)
        except Exception:
            return '', ''
    return tuple(cache[key])


def place(loc_id, locs, conf):
    """(name, lat, lon, state, country) of a location id; the station's location when the database has no locations."""
    loc = next((x for x in locs if loc_id is not None and str(x.get('id')) == str(loc_id)), None) or locations.current(conf) or {}
    name = loc.get('name') or conf.get('SITE_NAME') or 'BirdSuite'
    lat, lon = float(loc.get('lat') or conf.get('LATITUDE') or 0), float(loc.get('lon') or conf.get('LONGITUDE') or 0)
    return (name, lat, lon) + region(loc, lat, lon)


def weather_day(lat, lon, date):
    """Hourly weather of a day at a point ({'temperature_2m': [...24], ...}) or None (no internet, no answer). A past
    day is kept in state/weather; today is asked again each time."""
    key = f'{lat:.2f}_{lon:.2f}_{date}.json'
    path = os.path.join(WEATHER_DIR, key)
    today = datetime.date.today().isoformat()
    if date < today:
        try:
            with open(path, encoding='utf-8') as f:
                return json.load(f)
        except (OSError, ValueError):
            pass
    age = (datetime.date.today() - datetime.date.fromisoformat(date)).days
    # the forecast API keeps about three months back; older days come from the archive
    host = 'api.open-meteo.com/v1/forecast' if age <= 80 else 'archive-api.open-meteo.com/v1/archive'
    url = (f'https://{host}?latitude={lat:.4f}&longitude={lon:.4f}&start_date={date}&end_date={date}&timezone=auto'
           '&hourly=temperature_2m,precipitation,weather_code,wind_speed_10m,cloud_cover')
    try:
        with urllib.request.urlopen(urllib.request.Request(url, headers=UA), timeout=10) as r:
            hourly = json.load(r).get('hourly') or None
    except Exception:
        return None
    if hourly and date < today:
        try:
            os.makedirs(WEATHER_DIR, exist_ok=True)
            with open(path, 'w', encoding='utf-8') as f:
                json.dump(hourly, f)
        except OSError:
            pass
    return hourly


def _val(h, name, i):
    try:
        v = h[name][i]
        return None if v is None else float(v)
    except (KeyError, IndexError, TypeError, ValueError):
        return None


def weather_hour(h, hhmm, english=False):
    """'18 °C, light rain, wind 9 km/h' at an hour, or N/A."""
    tr = (lambda s, **k: s.format(**k)) if english else t
    if not h:
        return 'N/A'
    i = min(23, max(0, int(hhmm[:2])))
    temp, code, wind = _val(h, 'temperature_2m', i), _val(h, 'weather_code', i), _val(h, 'wind_speed_10m', i)
    if temp is None:
        return 'N/A'
    parts = [f'{temp:.0f} °C']
    sky = wmo_text(int(code)) if code is not None else ''
    if sky:
        parts.append(tr(sky))
    if wind is not None:
        parts.append(tr('wind {v} km/h', v=f'{wind:.0f}'))
    return ', '.join(parts)


def weather_summary(h, english=False):
    """The day: '14–23 °C, rain 3 mm, mostly partly cloudy', or N/A."""
    tr = (lambda s, **k: s.format(**k)) if english else t
    if not h:
        return 'N/A'
    temps = [v for v in (h.get('temperature_2m') or []) if v is not None]
    if not temps:
        return 'N/A'
    parts = [f'{min(temps):.0f}–{max(temps):.0f} °C']
    rain = sum(v for v in (h.get('precipitation') or []) if v)
    parts.append(tr('rain {v} mm', v=f'{rain:.1f}' if english else fmt_num(rain, 1)) if rain >= 0.1 else tr('no rain'))
    codes = [wmo_text(int(c)) for c in (h.get('weather_code') or [])[6:19] if c is not None]  # daylight hours
    codes = [c for c in codes if c]
    if codes:
        parts.append(tr(max(set(codes), key=codes.count)))
    return ', '.join(parts)


def min_conf():
    try:
        return max(0.0, min(0.99, float(str(get_settings().get('EBIRD_REPORT_MIN_CONF') or 0.7).strip('"\' '))))
    except ValueError:
        return 0.7


MODES = ('confirmed', 'minconf', 'all')


def setting_filter():
    """The filter of the scheduled e-mail (owner 2026-10-27): (mode, minimum confidence); mode confirmed = only the species
    confirmed by ear, minconf = every detection from the minimum confidence and the confirmed ones, all = every detection
    not answered No."""
    mode = str(get_settings().get('EBIRD_REPORT_SPECIES') or 'confirmed').strip('"\' ')
    return (mode if mode in MODES else 'confirmed', min_conf())


def arg_filter(argv, i):
    """(mode, minimum confidence) from the command line: ... [mode] [min]; the page's own filter."""
    mode = argv[i] if len(argv) > i and argv[i] in MODES else 'confirmed'
    try:
        minc = max(0.0, min(0.99, float(argv[i + 1]))) if len(argv) > i + 1 else min_conf()
    except ValueError:
        minc = min_conf()
    return (mode, minc)


def detected(date_from, date_to, minc=0.0):
    """Every detection not answered No between the dates with at least minc of confidence, or confirmed by ear at any
    confidence (owner 2026-10-27): (sci, com, date, time, conf, loc, confirmed 0/1)."""
    con = db()
    try:
        loc = 'Location' if has_location(con) else 'NULL'
        reviews = con.execute("SELECT 1 FROM sqlite_master WHERE type='table' AND name='detection_reviews'").fetchone()
        nr = " AND File_Name NOT IN (SELECT File_Name FROM detection_reviews WHERE Verdict = 'no')" if reviews else ''
        # a detection confirmed by ear counts whatever its confidence
        ok = "File_Name IN (SELECT File_Name FROM detection_reviews WHERE Verdict = 'yes')" if reviews else '0'
        return con.execute(f'SELECT Sci_Name, Com_Name, Date, Time, Confidence, {loc}, {ok}, File_Name FROM detections'
                           f' WHERE Date BETWEEN ? AND ? AND (Confidence >= ? OR {ok}){nr} ORDER BY Date, Time',
                           (date_from, date_to, minc)).fetchall()
    finally:
        con.close()


def list_filter():
    """The station's species lists as the analysis uses them (utils/analysis.py run_analysis): a species counts when the
    active list (include) is empty or has it, or the whitelist has it, and the exclude list does not."""
    from utils.analysis import loadCustomSpeciesList
    base = os.path.join(BASE_PATH, 'config', 'species_lists')
    inc, exc, white = (set(loadCustomSpeciesList(os.path.join(base, f'{n}_species_list.txt'))) - {''}
                       for n in ('include', 'exclude', 'whitelist'))
    return lambda sci: (not inc or sci in inc or sci in white) and sci not in exc


def gather(date_from, date_to, with_weather=True, flt=('confirmed', 0.7), skip=(), lists=False):
    """The species per day and location, with the weather: {(date, loc): {'species': {sci: {...}}, ...}}, of the filter
    (mode, minimum confidence), without the species of skip (unticked on the page, owner 2026-10-27) and, for the
    scheduled report (lists=True), without those the station's species lists leave out."""
    mode, minc = flt
    skip = set(skip)
    allowed = list_filter() if lists else (lambda sci: True)
    rows = confirmed(date_from, date_to) if mode == 'confirmed' else detected(date_from, date_to, minc if mode == 'minconf' else 0.0)
    conf = get_settings()
    locs = locations.load()
    en = english_names()
    out = {}
    for sci, com, date, time, confidence, loc, ok, _ in rows:
        if sci in skip or not allowed(sci):
            continue
        c = out.setdefault((date, loc), {'species': {}})
        s = c['species'].setdefault(sci, {'com': com, 'en': en.get(sci) or com, 'first': time, 'n': 0, 'ok': 0, 'best': 0.0, 'low': 1.0})
        s['n'] += 1
        s['ok'] += 1 if ok else 0
        s['best'] = max(s['best'], float(confidence or 0))
        s['low'] = min(s['low'], float(confidence or 0))
        s['first'] = min(s['first'], time)
    eff = effort(date_from, date_to)
    for (date, loc), c in out.items():
        c['place'] = place(loc, locs, conf)
        c['start'], c['end'] = eff.get((date, loc), (min(s['first'] for s in c['species'].values()),) * 2)
        c['weather'] = weather_day(c['place'][1], c['place'][2], date) if with_weather else None
    return out


def ebird_csv(date_from, date_to, flt=('confirmed', 0.7), skip=(), lists=False):
    conf = get_settings()
    model = (conf.get('MODEL') or 'BirdNET').replace('_', ' ')
    aves = birds()
    buf = io.StringIO()
    w = csv.writer(buf, lineterminator='\n')
    for (date, loc), c in sorted(gather(date_from, date_to, flt=flt, skip=skip, lists=lists).items(), key=lambda x: (x[0][0], str(x[0][1]))):
        name, lat, lon, state, country = c['place']
        d = datetime.date.fromisoformat(date)
        t0 = datetime.datetime.strptime(c['start'][:5], '%H:%M')
        t1 = datetime.datetime.strptime(c['end'][:5], '%H:%M')
        minutes = max(1, min(1440, int((t1 - t0).total_seconds() // 60)))
        day = weather_summary(c['weather'], english=True)
        which = {'confirmed': 'only the species confirmed by ear are listed',
                 'minconf': f'species detected automatically from {round(100 * flt[1])}% confidence or confirmed by ear',
                 'all': 'every species detected automatically, at any confidence, or confirmed by ear'}[flt[0]]
        comments = (f'Acoustic monitoring station (BirdSuite, {model}); {which}. '
                    f'Weather: {day} (Open-Meteo).')
        for sci, s in sorted(c['species'].items(), key=lambda x: x[1]['first']):
            if sci not in aves:
                continue  # not a bird: eBird would refuse the whole import
            words = sci.split()
            sp_comment = (f"Heard first at {s['first'][:5]} ({weather_hour(c['weather'], s['first'], english=True)}); "
                          + (f"{s['n']} detection(s), {s['ok']} confirmed by ear" if flt[0] != 'confirmed' else f"{s['n']} detection(s) confirmed by ear")
                          + f", best confidence {round(100 * s['best'])}%.")
            w.writerow([aves[sci] or s['en'], words[0] if words else '', ' '.join(words[1:]), 'X', sp_comment, name,
                        f'{lat:.5f}', f'{lon:.5f}', d.strftime('%m/%d/%Y'), c['start'][:5], state, country, 'Stationary', 1,
                        minutes, 'N', '', '', comments])
    return buf.getvalue()


def week_days(any_date):
    d = datetime.date.fromisoformat(any_date)
    monday = d - datetime.timedelta(days=d.weekday())
    return [(monday + datetime.timedelta(days=i)).isoformat() for i in range(7)]


def table(days, flt=('confirmed', 0.7), skip=(), lists=False):
    """Rows of the table: every species of the filter with its detections per day, and the day's weather."""
    data = gather(days[0], days[-1], flt=flt, skip=skip, lists=lists)
    rows = {}
    weather = {d: [] for d in days}
    for (date, loc), c in data.items():
        weather[date].append(weather_summary(c['weather']))
        for sci, s in c['species'].items():
            r = rows.setdefault(sci, {'sci': sci, 'com': s['com'], 'en': s['en'], 'days': {d: 0 for d in days}, 'low': 1.0})
            r['days'][date] += s['n']
            r['low'] = min(r['low'], s['low'])
    return {'days': days, 'rows': sorted(rows.values(), key=lambda r: r['com'].lower()),
            'weather': {d: ' · '.join(sorted(set(v))) if v else '' for d, v in weather.items()}}


def html_report(days, tab, flt=('confirmed', 0.7)):
    head = ''.join(f'<th style="padding:3px 6px">{datetime.date.fromisoformat(d).strftime("%d/%m")}</th>' for d in days)
    body = ''
    for r in tab['rows']:
        cells = ''.join(f'<td style="text-align:center;padding:3px 6px">{r["days"][d] or ""}</td>' for d in days)
        body += (f'<tr><td style="padding:3px 6px">{r["com"]}<br><i style="color:#666">{r["sci"]}</i></td>{cells}'
                 f'<td style="text-align:center;padding:3px 6px"><b>{sum(r["days"].values())}</b></td></tr>')
    wrow = ''.join(f'<td style="font-size:11px;color:#555;padding:3px 6px">{tab["weather"][d] or "N/A"}</td>' for d in days)
    n = len(tab['rows'])
    intro = {'confirmed': t('Species confirmed by ear from {a} to {b}: {n}.', a=days[0], b=days[-1], n=n),
             'minconf': t('Species detected from {a} to {b}: {n}, from {pct}% of confidence or confirmed by ear.', a=days[0], b=days[-1], n=n, pct=round(100 * flt[1])),
             'all': t('Species detected from {a} to {b}: {n}, every detection not answered No.', a=days[0], b=days[-1], n=n)}[flt[0]]
    return (f'<p>{intro}<br>'
            f'{t("The eBird file is attached: eBird › Submit › Import data, eBird Record Format (Extended).")}</p>'
            f'<table border="1" cellspacing="0" style="border-collapse:collapse;font-family:sans-serif;font-size:13px">'
            f'<tr><th style="padding:3px 6px">{t("Species")}</th>{head}<th style="padding:3px 6px">{t("Total")}</th></tr>'
            f'{body}<tr><td style="padding:3px 6px"><b>{t("Weather")}</b></td>{wrow}<td></td></tr></table>')


def report_name(date_from, date_to, conf=None):
    """EBIRD_REPORT_NAME with its fields (owner 2026-10-27): {start} and {end} (YYYY-MM-DD, or {start:YYYYMMDD} and any
    pattern of YYYY / YY / MM / DD), {week} (the week of the year of the start), {year}, {station} (its name), {id} (the
    station's ID, the MAC of the Pi's own port, as its WiFi name BirdSuite-<ID>), {location} (the current location); the
    e-mail subject and, made safe, the name of the attached file and of the downloads."""
    conf = conf or get_settings()
    tpl = str(conf.get('EBIRD_REPORT_NAME') or '').strip().strip('"\'') or '{start}_{end}_BirdSuite_eBird_{id}_{location}'
    a, b = datetime.date.fromisoformat(date_from), datetime.date.fromisoformat(date_to)

    def fmt(d, pattern):
        return (pattern.replace('YYYY', f'{d.year:04d}').replace('YY', f'{d.year % 100:02d}').replace('MM', f'{d.month:02d}')
                .replace('DD', f'{d.day:02d}'))

    def field(m):
        name, pattern = m.group(1), m.group(2) or 'YYYY-MM-DD'
        if name in ('start', 'end'):
            return fmt(a if name == 'start' else b, pattern)
        if name == 'id':
            try:
                from field_net import mac
                return mac().replace(':', '').upper() or 'BirdSuite'
            except Exception:
                return 'BirdSuite'
        if name == 'location':
            return str((locations.current(conf) or {}).get('name') or '')
        return {'week': f'{a.isocalendar()[1]:02d}', 'year': str(a.isocalendar()[0]),
                'station': str(conf.get('SITE_NAME') or 'BirdSuite').strip('"\' ')}.get(name, m.group(0))
    return re.sub(r'\{(start|end|week|year|station|id|location)(?::([^}]*))?\}', field, tpl)


def file_name(date_from, date_to):
    return (re.sub(r'[^\w.\- ]+', '_', report_name(date_from, date_to)).strip(' ._') or 'ebird') + '.csv'


def send(days, flt=None, skip=()):
    from utils.notifications import _channels, _build_apobj
    import apprise
    _, chans = _channels('normal')
    lines = chans.get('email')
    if not lines:
        return t('E-mail is not set up or not confirmed: Settings › E-mail.')
    # the scheduled report (no filter given): the schedule's filter and the station's species lists
    lists = flt is None
    flt = flt or setting_filter()
    tab = table(days, flt, skip, lists)
    fd, path = tempfile.mkstemp(prefix='ebird-', suffix='.csv')
    try:
        with os.fdopen(fd, 'w', encoding='utf-8') as f:
            f.write(ebird_csv(days[0], days[-1], flt, skip, lists))
        named = os.path.join(tempfile.mkdtemp(prefix='ebird-'), file_name(days[0], days[-1]))
        os.replace(path, named)
        path = named
        ok = _build_apobj(lines).notify(title=report_name(days[0], days[-1]),
                                        body=html_report(days, tab, flt), body_format=apprise.NotifyFormat.HTML, attach=path)
    finally:
        try:
            os.remove(path)
            os.rmdir(os.path.dirname(path))
        except OSError:
            pass
    return 'OK' if ok else t('The e-mail did not go out: check Settings › E-mail.')


def last_full_week(now=None):
    today = (now or datetime.datetime.now()).date()
    return [(today - datetime.timedelta(days=7 - i)).isoformat() for i in range(7)]


def daily():
    """EBIRD_REPORT_FREQ (owner 2026-10-27): daily = every day the previous day, weekly = on its day the last seven days."""
    return str(get_settings().get('EBIRD_REPORT_FREQ') or 'weekly').strip('"\' ') == 'daily'


def period(now=None):
    today = (now or datetime.datetime.now()).date()
    return [(today - datetime.timedelta(days=1)).isoformat()] if daily() else last_full_week(now)


def due(now=None):
    conf = get_settings()
    if str(conf.get('EBIRD_REPORT_ENABLED', '0')).strip('"\' ') != '1':
        return
    now = now or datetime.datetime.now()
    try:
        day = int(conf.get('EBIRD_REPORT_DAY', 1))  # 1 = Monday ... 7 = Sunday
        hh, mm = (int(x) for x in str(conf.get('EBIRD_REPORT_TIME', '08:00')).strip('"\' ').split(':')[:2])
    except ValueError:
        return
    if daily():
        at = datetime.datetime.combine(now.date(), datetime.time(hh, mm))
    else:
        monday = now.date() - datetime.timedelta(days=now.weekday())
        at = datetime.datetime.combine(monday + datetime.timedelta(days=max(1, min(7, day)) - 1), datetime.time(hh, mm))
    if now < at:
        return
    try:
        with open(SENT, encoding='utf-8') as f:
            last = json.load(f).get('at', '')
    except (OSError, ValueError):
        last = ''
    if last >= at.isoformat(timespec='minutes'):
        return
    result = send(period(now))
    if result == 'OK':
        os.makedirs(STATE, exist_ok=True)
        with open(SENT, 'w', encoding='utf-8') as f:
            json.dump({'at': at.isoformat(timespec='minutes'), 'sent': now.isoformat(timespec='seconds')}, f)
    print(result)


def clips(date_from, date_to, flt=('confirmed', 0.7), skip=(), per=20):
    """The best detections confirmed by ear of every bird (owner 2026-10-27: the sounds for eBird's Add media; only a sound
    someone checked, whatever the page's filter), highest confidence first, up to per a species: [{sci, com, date, time,
    conf, file}]; the page keeps the first 5 still on disk."""
    rows = confirmed(date_from, date_to)
    aves, skip, best = birds(), set(skip), {}
    for sci, com, date, time, confidence, loc, ok, fname in rows:
        if sci in aves and sci not in skip:
            best.setdefault(sci, []).append({'sci': sci, 'com': com, 'date': date, 'time': time, 'conf': float(confidence or 0), 'file': fname})
    return [c for v in best.values() for c in sorted(v, key=lambda c: -c['conf'])[:per]]


def arg_skip(argv, i):
    """The species unticked on the page: scientific names joined by |."""
    return {x.strip() for x in argv[i].split('|') if x.strip()} if len(argv) > i else set()


def span(a, b):
    a, b = datetime.date.fromisoformat(a), datetime.date.fromisoformat(b)
    return [(a + datetime.timedelta(days=i)).isoformat() for i in range(max(1, min(31, (b - a).days + 1)))]


if __name__ == '__main__':
    cmd = sys.argv[1] if len(sys.argv) > 1 else ''
    if cmd == 'days' and len(sys.argv) > 3:
        print(json.dumps(table(span(sys.argv[2], sys.argv[3]), arg_filter(sys.argv, 4))))
    elif cmd == 'csv' and len(sys.argv) > 3:
        sys.stdout.write(ebird_csv(sys.argv[2], sys.argv[3], arg_filter(sys.argv, 4), arg_skip(sys.argv, 6)))
    elif cmd == 'clips' and len(sys.argv) > 3:
        print(json.dumps(clips(sys.argv[2], sys.argv[3], arg_filter(sys.argv, 4), arg_skip(sys.argv, 6))))
    elif cmd == 'name' and len(sys.argv) > 3:
        print(file_name(sys.argv[2], sys.argv[3]))
    elif cmd == 'send' and len(sys.argv) > 3:
        print(send(span(sys.argv[2], sys.argv[3]), arg_filter(sys.argv, 4), arg_skip(sys.argv, 6)))
    elif cmd == 'send':
        print(send(period()))
    elif cmd == 'due':
        due()
    else:
        print(__doc__)
        sys.exit(2)
