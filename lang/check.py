"""Text keys of the interface (US-122, owner 2026-11-02): the code says t('str_key'), lang/en/*.json holds the English
text of every key (the only reference), every other lang/<code>/*.json translates the keys.

Usage: python3 lang/check.py [--all] [file ...]
  no file    every page and script of the program (homepage/, scripts/, templates/)
  file ...   only the keys used in these files must exist (the hook passes the staged files); the catalogue checks
             and "every key of lang/en is used somewhere" always look at the whole program
  --all      every language must translate every key (a hard release); without it only pt_BR (day to day)

Errors (exit 1): a t() / window.t() call with a literal that is not a key; a key used in the code missing from lang/en;
a key of lang/en used nowhere; a key not matching ^str_[a-z0-9_]+$; a catalogue that is not valid JSON or has a key
twice (two files of one language); a translation of a key that lang/en lacks; a translation whose {placeholders}
differ from the English text; a missing pt_BR translation (all languages with --all); a given file that does not
exist, or no file to check at all. Reported, not errors: one English text under several keys (the _menu, _tier...
splits are meant to be that way) and the count of missing translations of the other languages without --all."""
import glob
import json
import os
import re
import sys

BASE = os.path.dirname(os.path.abspath(__file__))
ROOT = os.path.dirname(BASE)
CODE_DIRS = ('homepage', 'scripts', 'templates')
CODE_EXT = ('.php', '.js', '.py', '.html')
VENDOR = re.compile(r'(\.min\.js$|/Chart\.bundle\.js$|/dialog-polyfill\.js$|/adminer\.php$)')
KEY = re.compile(r'^str_[a-z0-9_]+$')
LIT = r"""(?:'((?:[^'\\]|\\.)*)'|"((?:[^"\\]|\\.)*)")"""
# a call of the translation function with a literal: t('...'), window.t('...') (not $t(, ->t(, .t( of other objects)
CALL = re.compile(r"(?<![\w$>.])(?:window\.)?t\(\s*" + LIT)
# a key anywhere in the code: the calls, the keyed maps (menu, tiers, notes, data names...)
USE = re.compile(r"""['"](str_[a-z0-9_]+)['"]""")
PH = re.compile(r'\{([a-z_0-9]+)(?::[^}]*)?\}')
errors = []


def err(msg):
    errors.append(msg)
    print('ERROR ' + msg)


def program_files():
    out = []
    for d in CODE_DIRS:
        for dirpath, dirnames, filenames in os.walk(os.path.join(ROOT, d)):
            dirnames[:] = sorted(x for x in dirnames if not x.startswith('.'))
            for f in sorted(filenames):
                p = os.path.join(dirpath, f)
                if f.endswith(CODE_EXT) and not VENDOR.search(p):
                    out.append(p)
    return out


def read(p):
    try:
        with open(p, encoding='utf-8') as fh:
            return fh.read()
    except (OSError, UnicodeDecodeError) as e:
        err(f'{rel(p)}: cannot read ({e})')
        return ''


def rel(p):
    return os.path.relpath(p, ROOT)


def load_catalogues():
    cats, origin = {}, {}
    for lang in sorted(d for d in os.listdir(BASE) if os.path.isdir(os.path.join(BASE, d)) and not d.startswith(('_', '.'))):
        files = sorted(glob.glob(os.path.join(BASE, lang, '*.json')))
        if not files:
            continue
        cats[lang], origin[lang] = {}, {}
        for f in files:
            try:
                with open(f, encoding='utf-8') as fh:
                    pairs = json.load(fh, object_pairs_hook=lambda kv: kv)
            except ValueError as e:
                err(f'{rel(f)}: not valid JSON ({e})')
                continue
            for k, v in pairs:
                if k in cats[lang]:
                    err(f'{rel(f)}: key {k} also in {origin[lang][k]}' + ('' if cats[lang][k] == v else ' with another text'))
                cats[lang][k] = v
                origin[lang][k] = os.path.basename(f)
    return cats


def check_targets(given, every):
    """The files to check: the ones named, else every page and script."""
    for a in given:
        if a.startswith('-'):
            err(f'unknown option {a}')
    given = [a for a in given if not a.startswith('-')]
    if not every:
        err('no page or script found under ' + ', '.join(CODE_DIRS))
    targets = [os.path.abspath(a) for a in given] if given else every
    for p in targets:
        if not os.path.isfile(p):
            err(f'{p}: no such file')
    targets = [p for p in targets if os.path.isfile(p)]
    if not targets:
        err('no file to check')
    return targets


def check_english(cats):
    """lang/en: the reference of every key, each key well formed with an English text."""
    en = cats.get('en')
    if not en:
        err('lang/en has no catalogue: English is the reference of every key')
        en = {}
    for k, v in en.items():
        if not KEY.match(k):
            err(f'lang/en: key {k!r} does not match ^str_[a-z0-9_]+$')
        if not isinstance(v, str) or not v.strip():
            err(f'lang/en: {k} has no English text')
    return en


def check_uses(targets, every, en):
    """The keys used in the files to check exist, literals of t() are keys, and every English key is used somewhere."""
    for p in targets:
        src = read(p)
        for m in CALL.finditer(src):
            raw = m.group(1) if m.group(1) is not None else m.group(2)
            line = src.count('\n', 0, m.start()) + 1
            if not KEY.match(raw):
                err(f'{rel(p)}:{line}: t() with a text that is not a key: {raw[:70]!r}')
        for m in USE.finditer(src):
            if m.group(1) not in en:
                err(f'{rel(p)}:{src.count(chr(10), 0, m.start()) + 1}: key {m.group(1)} is not in lang/en')
    used = set()
    for p in every:
        used.update(USE.findall(read(p)))
    for k in sorted(set(en) - used):
        err(f'lang/en: key {k} is used nowhere in the program')


stale_paths = {}


def check_translation(lang, cat, en, check_all):
    """One translation: only English keys, texts, the same placeholders; missing ones are errors for pt_BR (and for
    every language with --all), else counted."""
    for k, v in cat.items():
        if k not in en:
            err(f'lang/{lang}: key {k} is not in lang/en')
        elif not isinstance(v, str):
            err(f'lang/{lang}: {k} is not a text')
        elif v and sorted(set(PH.findall(v))) != sorted(set(PH.findall(en[k]))):
            # a {menu:<view>} page path (US-51) still written out in a translation is stale but readable: an error for
            # pt_BR and with --all (a hard release), else counted
            ph_v, ph_en = set(PH.findall(v)), set(PH.findall(en[k]))
            if lang != 'pt_BR' and not check_all and ph_v ^ ph_en == {'menu'}:
                stale_paths[lang] = stale_paths.get(lang, 0) + 1
            else:
                err(f'lang/{lang}: {k}: placeholders {sorted(ph_v)} differ from English {sorted(ph_en)}')
    missing = [k for k in en if not cat.get(k)]
    if lang == 'pt_BR' or check_all:
        for k in missing:
            err(f'lang/{lang}: missing translation of {k} ({en[k][:60]!r})')
    elif missing:
        print(f'[{lang}] {len(missing)} keys not translated yet (checked with --all)')
    if stale_paths.get(lang):
        print(f'[{lang}] {stale_paths[lang]} texts still write a page path instead of {{menu:...}} (checked with --all)')


def report_splits(en):
    """One English text under several keys: reported only."""
    by_text = {}
    for k, v in en.items():
        by_text.setdefault(v, []).append(k)
    dup = {v: ks for v, ks in by_text.items() if len(ks) > 1}
    if dup:
        print(f'{len(dup)} English texts under several keys (meaning splits): '
              + '; '.join(f'{v[:24]!r}={",".join(ks)}' for v, ks in sorted(dup.items())[:8]) + ('…' if len(dup) > 8 else ''))


def main():
    args = sys.argv[1:]
    check_all = '--all' in args
    every = program_files()
    targets = check_targets([a for a in args if a != '--all'], every)
    cats = load_catalogues()
    en = check_english(cats)
    check_uses(targets, every, en)
    for lang, cat in cats.items():
        if lang != 'en':
            check_translation(lang, cat, en, check_all)
    report_splits(en)
    print(f'{len(targets)} files checked, {len(en)} keys, {len(errors)} errors')
    return 1 if errors else 0

if __name__ == '__main__':
    sys.exit(main())
