"""Interface language for the Python side (US-122, owner 2026-10-20; text keys 2026-11-02): t(key) with the
catalogues of the pages (lang/<code>/*.json, English in lang/en the only reference and the fallback of every other
language), the language from UI_LANG or, when it is empty, from DATABASE_LANG."""
import glob
import json
import os
import re

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
    except Exception:  # noqa: BLE001  any error here is logged and the station goes on
        lang = 'en'
    return lang if lang in LANGS else 'en'


def catalog(lang=None):
    lang = lang or ui_lang()
    if lang not in _cache:
        cat = {}
        for code in dict.fromkeys(('en', lang)):  # English first, the language over it
            for f in sorted(glob.glob(os.path.join(BASE, 'lang', code, '*.json'))):
                try:
                    with open(f, encoding='utf-8') as fh:
                        data = json.load(fh)
                    if isinstance(data, dict):
                        cat.update({k: v for k, v in data.items() if v})
                except (OSError, ValueError):
                    pass
        # a text names a page as {menu:<view key>} (US-51): its place in the menu, read from scripts/view_registry.php
        reg = _registry()
        if reg:
            def path(m):
                e = reg.get(m.group(1)) or reg.get(_aliases(reg).get(m.group(1), ''))
                if not e:
                    return m.group(1)
                label = cat.get(e[1], m.group(1))
                return (cat.get(e[0], e[0]) + ' › ' + label) if e[0] else label
            cat = {k: (_MENU.sub(path, v) if '{menu:' in v else v) for k, v in cat.items()}
        _cache[lang] = cat
    return _cache[lang]


_MENU = re.compile(r'\{menu:([^}]+)\}')
_REG = None


def _registry():
    """The view registry as {view: (group key or '', label key, aliases)}; {} when it cannot be read."""
    global _REG
    if _REG is None:
        _REG = {}
        try:
            with open(os.path.join(BASE, 'scripts', 'view_registry.php'), encoding='utf-8') as fh:
                src = fh.read()
            for m in re.finditer(r"^\s*'([^']+)' => \$v\(('[^']*'|null), '([^']+)'(.*)\),\s*$", src, re.M):
                grp = '' if m.group(2) == 'null' else m.group(2).strip("'")
                al = re.search(r"'alias' => array\(([^)]*)\)", m.group(4))
                _REG[m.group(1)] = (grp, m.group(3), re.findall(r"'([^']*)'", al.group(1)) if al else [])
        except OSError:
            pass
    return _REG


def _aliases(reg):
    return {a: k for k, e in reg.items() for a in e[2]}


def t_lang(lang, key, /, **values):
    out = catalog(lang).get(key) or key
    for k, v in values.items():
        out = out.replace('{' + k + '}', str(v))
    return out


def t(key, **values):
    return t_lang(None, key, **values)


def en(key, **values):
    """The English text of a key (what goes to eBird whatever the interface language)."""
    return t_lang('en', key, **values)


# numbers in the format of the BirdSuite language, the same rule as num_separators() / fmt_num() in scripts/common.php
# (owner 2026-10-23: the number format follows UI_LANG, or DATABASE_LANG when empty)
_SEP = {'en': ('.', ','), 'ja': ('.', ','), 'zh_CN': ('.', ','),
        'fr': (',', '\u202f'), 'pl': (',', '\u00a0'), 'ru': (',', '\u00a0')}


def num_separators():
    return _SEP.get(ui_lang(), (',', '.'))  # pt_BR, es, de, it, nl


def fmt_num(n, dec=0):
    dp, ts = num_separators()
    s = f'{float(n):,.{int(dec)}f}'
    return s.replace(',', '\0').replace('.', dp).replace('\0', ts)
