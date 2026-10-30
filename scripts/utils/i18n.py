"""Interface language for the Python side (US-122, owner 2026-10-20): t('English text') with the catalogues of the
pages (lang/<code>/*.json), the language from UI_LANG or, when it is empty, from DATABASE_LANG."""
import glob
import json
import os

from .helpers import get_settings

BASE = os.path.dirname(os.path.dirname(os.path.dirname(os.path.abspath(__file__))))
LANGS = ('en', 'pt_BR', 'es', 'zh_CN', 'ja', 'de', 'fr', 'it', 'nl', 'pl', 'ru')
_cache = {}


def ui_lang():
    # never raises: a station without a readable BirdSuite.conf (or a test with a fake one) speaks English
    try:
        conf = get_settings()
        lang = str(conf.get('UI_LANG') or '').strip().strip('"\'')
        if not lang:
            db = str(conf.get('DATABASE_LANG') or 'en')
            lang = {'pt': 'pt_BR', 'zh': 'zh_CN'}.get(db[:2], db[:2])
    except Exception:
        lang = 'en'
    return lang if lang in LANGS else 'en'


def catalog(lang=None):
    lang = lang or ui_lang()
    if lang not in _cache:
        cat = {}
        if lang != 'en':
            for f in sorted(glob.glob(os.path.join(BASE, 'lang', lang, '*.json'))):
                try:
                    with open(f, encoding='utf-8') as fh:
                        data = json.load(fh)
                    if isinstance(data, dict):
                        cat.update(data)
                except (OSError, ValueError):
                    pass
        _cache[lang] = cat
    return _cache[lang]


def t(text, **values):
    out = catalog().get(text) or text
    for k, v in values.items():
        out = out.replace('{' + k + '}', str(v))
    return out


# numbers in the format of the station's own locale (owner 2026-10-23: the Pi's locale, not the interface language),
# the same rule as fmt_num() in scripts/common.php: /etc/default/locale (LC_ALL, LC_NUMERIC, LANG), else the environment
_SEP = {'en': ('.', ','), 'ja': ('.', ','), 'zh': ('.', ','), 'ko': ('.', ','), 'he': ('.', ','),
        'fr': (',', ' '), 'pl': (',', ' '), 'ru': (',', ' '), 'cs': (',', ' '),
        'sv': (',', ' '), 'fi': (',', ' '), 'nb': (',', ' '), 'uk': (',', ' ')}


def station_locale():
    vals = {}
    try:
        with open('/etc/default/locale') as f:
            for line in f:
                k, _, v = line.strip().partition('=')
                if k in ('LC_ALL', 'LC_NUMERIC', 'LANG'):
                    vals[k] = v.strip().strip('"')
    except OSError:
        pass
    for k in ('LC_ALL', 'LC_NUMERIC', 'LANG'):
        v = vals.get(k) or os.environ.get(k, '')
        if v and v not in ('C', 'POSIX') and not v.startswith('C.'):
            return v
    return 'en_GB.UTF-8'


def num_separators():
    loc = station_locale()
    if loc.lower().startswith('de_ch'):
        return '.', '’'
    if loc.lower().startswith(('en_dk', 'da')):
        return ',', '.'
    return _SEP.get(loc[:2].lower(), (',', '.'))


def fmt_num(n, dec=0):
    dp, ts = num_separators()
    s = f'{float(n):,.{int(dec)}f}'
    return s.replace(',', '\0').replace('.', dp).replace('\0', ts)
