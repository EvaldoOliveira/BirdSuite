import glob
import json
import os
import re
import subprocess
from collections import OrderedDict
from configparser import ConfigParser
from itertools import chain

from . import core

_settings = None

# the folder layout: core.env through utils/core.py (owner 2026-11-02; core.sh and core.php read the same file)
BASE_PATH = core.BS_PROG
CONFIG_DIR = core.BS_CONFIG
CONF_PATH = core.BS_CONF
LISTS_DIR = core.BS_LISTS
CUSTOM_LISTS_DIR = core.BS_CUSTOM_LISTS
NOTIFY_DIR = core.BS_NOTIFY
DB_PATH = core.BS_DB
MODEL_PATH = core.BS_MODEL
FONT_DIR = os.path.join(core.BS_HOMEPAGE, 'static')
ANALYZING_NOW = core.BS_ANALYZING_NOW

def get_font():
    conf = get_settings()
    if conf['DATABASE_LANG'] == 'ar':
        ret = {'font.family': 'Noto Sans Arabic', 'path': os.path.join(FONT_DIR, 'NotoSansArabic-Regular.ttf')}
    elif conf['DATABASE_LANG'] in ['ja', 'zh_CN', 'zh_TW']:
        ret = {'font.family': 'Noto Sans JP', 'path': os.path.join(FONT_DIR, 'NotoSansJP-Regular.ttf')}
    elif conf['DATABASE_LANG'] == 'ko':
        ret = {'font.family': 'Noto Sans KR', 'path': os.path.join(FONT_DIR, 'NotoSansKR-Regular.ttf')}
    elif conf['DATABASE_LANG'] == 'th':
        ret = {'font.family': 'Noto Sans Thai', 'path': os.path.join(FONT_DIR, 'NotoSansThai-Regular.ttf')}
    else:
        ret = {'font.family': 'Roboto Flex', 'path': os.path.join(FONT_DIR, 'RobotoFlex-Regular.ttf')}
    return ret


class PHPConfigParser(ConfigParser):
    def get(self, section, option, *, raw=False, vars=None, fallback=None):
        value = super().get(section, option, raw=raw, vars=vars, fallback=fallback)
        if raw:
            return value
        else:
            return value.strip('"') if value is not None else value


# US-121 (2026-10-20): every default of the program, in the base folder beside BirdSuite.conf
DEFAULTS_PATH = core.BS_DEFAULTS


def _load_settings(settings_path=CONF_PATH, force_reload=False):
    global _settings
    if _settings is None or force_reload:
        parser = PHPConfigParser(interpolation=None)
        # preserve case
        parser.optionxform = lambda option: option
        # the defaults first, the station's BirdSuite.conf over them: a key the station never set takes its default
        if os.path.isfile(DEFAULTS_PATH):
            with open(DEFAULTS_PATH) as f:
                parser.read_file(chain(("[top]",), f))
        with open(settings_path) as f:
            lines = chain(("[top]",), f)
            parser.read_file(lines)
        _settings = parser['top']
    return _settings


def get_settings(settings_path=CONF_PATH, force_reload=False):
    settings = _load_settings(settings_path, force_reload)
    return settings


def is_test_location(conf=None):
    """The station is at the built-in Test / Playback location (owner 2026-10-20; config/locations/locations.json, "builtin":
    "test"): nothing is notified or uploaded, so playing recordings to the station leaves only its own records behind."""
    from . import locations  # the current location: LOCATION_ID, else the one holding the coordinates (owner 2026-10-21)
    loc = locations.current(conf or get_settings())
    return bool(loc) and loc.get('builtin') == 'test'


def get_open_files_in_dir(dir_name):
    result = subprocess.run(['lsof', '-w', '-Fn', '+D', f'{dir_name}'], check=False, capture_output=True)
    ret = result.stdout.decode('utf-8')
    err = result.stderr.decode('utf-8')
    if err:
        raise RuntimeError(f'{ret}:\n {err}')
    names = [line.lstrip('n') for line in ret.splitlines() if line.startswith('n')]
    return names


def get_wav_files():
    conf = get_settings()
    files = (glob.glob(os.path.join(conf['RECS_DIR'], '*/*/*.wav')) +
             glob.glob(os.path.join(conf['RECS_DIR'], 'StreamData/*.wav')))
    files.sort()
    files = [os.path.join(conf['RECS_DIR'], file) for file in files]
    rec_dir = os.path.join(conf['RECS_DIR'], 'StreamData')
    open_recs = get_open_files_in_dir(rec_dir)
    files = [file for file in files if file not in open_recs]
    return files


def get_language(language=None):
    if language is None:
        language = get_settings()['DATABASE_LANG']
    file_name = os.path.join(MODEL_PATH, f'l18n/labels_{language}.json')
    with open(file_name) as f:
        ret = json.loads(f.read())
    return ret


def save_language(labels, language):
    file_name = os.path.join(MODEL_PATH, f'l18n/labels_{language}.json')
    with open(file_name, 'w') as f:
        f.write(json.dumps(OrderedDict(sorted(labels.items())), indent=2, ensure_ascii=False))


# Species names are presentation only (owner 2026-11-05, "one index only"): the database, the analysis, the lists and
# the files know a species by its scientific name alone; the English name (the model's own: the V3 labels, then
# BirdNET's English l18n file for the V2.4 / 6K species) and the common name (the l18n file of the Common name Language,
# falling back on the English one, then on the scientific name) are looked up when something is shown or sent. Same
# rule as common.php get_english_name() / common_name(). The maps follow a change of language or labels (mtime).
_name_maps = {}


def _name_map(key, paths, parse):
    stamp = tuple((p, os.path.getmtime(p)) for p in paths if os.path.exists(p))
    cached = _name_maps.get(key)
    if cached and cached[0] == stamp:
        return cached[1]
    names = {}
    for path, _ in stamp:
        try:
            for sci, name in parse(path):
                if sci and name:
                    names.setdefault(sci, name)
        except (OSError, ValueError, AttributeError):
            pass
    _name_maps[key] = (stamp, names)
    return names


def _label_lines(path):
    with open(path, encoding='utf-8') as f:
        for line in f:
            sci, _, name = line.strip().partition('_')
            yield sci, name


def _json_items(path):
    with open(path, encoding='utf-8') as f:
        return list(json.load(f).items())


def english_name(sci):
    labels = sorted(p for p in glob.glob(os.path.join(MODEL_PATH, 'BirdNET-Plus_*_Labels.txt')) if '_Geo_' not in p)
    names = _name_map('en', labels + [os.path.join(MODEL_PATH, 'l18n', 'labels_en.json')],
                      lambda p: _json_items(p) if p.endswith('.json') else _label_lines(p))
    return names.get(sci or '', '')


def common_name(sci, language=None):
    if language is None:
        try:
            language = get_settings()['DATABASE_LANG'] or 'en'
        except Exception:
            language = 'en'
    language = re.sub(r'[^A-Za-z_]', '', language) or 'en'
    names = _name_map('com_' + language, [os.path.join(MODEL_PATH, 'l18n', f'labels_{language}.json')], _json_items)
    return names.get(sci or '') or english_name(sci) or (sci or '')


def db_names(con):
    """com_name(Sci_Name) and en_name(Sci_Name) as SQL functions of a sqlite3 connection (as common.php db_names())."""
    con.create_function('com_name', 1, common_name, deterministic=True)
    con.create_function('en_name', 1, english_name, deterministic=True)
    return con


def get_model_labels(model=None):
    if model is None:
        model = get_settings()['MODEL']
    file_name = os.path.join(MODEL_PATH, f'{model}_Labels.txt')
    with open(file_name) as f:
        labels = [line.strip() for line in f.readlines()]
    if labels and labels[0].count('_') == 1:
        labels = [re.sub(r'_.+$', '', label) for label in labels]
    return labels


# Species folders and clip names by the scientific name only (owner 2026-10-24), the same rule as common.php
# species_folder(): By_Date/<date>/<Sci_safe>/<Sci_safe>-<date>_<time>-...
def name_safe(name):
    return str(name or '').replace("'", '').replace(' ', '_')


def species_folder(sci):
    return name_safe(sci)


# what follows the species in a clip's name: -<date>_<time>-... (BirdSuite) or -<confidence>-<date>-... (BirdNET-Pi)
CLIP_TAIL = re.compile(r'-(\d{1,3}-)?\d{4}-\d{2}-\d{2}[-_]')


def clip_rest(file_name):
    """The clip's name without its species: '-2026-10-01_06h00m01s-...'; '' when it has no date in it."""
    m = CLIP_TAIL.search(str(file_name))
    return str(file_name)[m.start():] if m else ''


def clip_target(sci, com, file_name):
    """(the folder a clip is in, its name in the scientific-name layout), for the imports of BirdNET-Pi clips."""
    s, c, f = name_safe(sci), name_safe(com), str(file_name)
    m = CLIP_TAIL.search(f)
    if s and f.startswith(s + '-'):
        folder = s
    elif m and not (c and f.startswith(c + m.group(0))):
        folder = f[:m.start()]  # an old clip named in another language: its folder is the start of its name
    else:
        folder = c
    if m:
        return folder, s + f[m.start():]
    for p in (s, c):  # no date in the name: only the species part is replaced
        if p and f.startswith(p):
            return folder, s + f[len(p):]
    return folder, f'{s}-{f}'


def clip_rel(date, sci, file_name):
    return os.path.join(str(date), species_folder(sci), file_name)



def set_label_file():
    lang = get_language()
    labels = [f'{label}_{lang.get(label, label)}\n' for label in get_model_labels()]
    file_name = os.path.join(MODEL_PATH, 'labels.txt')
    if os.path.islink(file_name):
        os.remove(file_name)
    with open(file_name, 'w') as f:
        f.writelines(labels)
