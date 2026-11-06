#!/usr/bin/env python3
################################################################################
# tools/check_views.py - THE VIEW REGISTRY CHECK
#
# PURPOSE:
#   The view registry test (US-50, owner 2026-11-04).
#
# DEPENDENCIES:
#   - (none beyond the system)
#
# USAGE:
#   python3 tools/check_views.py <arguments> (see main)
################################################################################

"""tools/check_views.py - the view registry test (US-50, owner 2026-11-04).

scripts/view_registry.php is the single list of the station's pages. This test reads it (no PHP needed) and fails when:
  - an entry lacks a valid level (essential, birder, expert) or role (viewer, operator, admin);
  - a label key is missing in lang/en or lang/pt_BR;
  - an alias is used twice or names a view key;
  - views.php dispatches a view the registry does not know;
  - a link in the code (view=<key>) names neither a view nor an alias.
Usage: python3 tools/check_views.py   (from anywhere in the repository; exit 0 = OK, 1 = errors listed)
"""
import json
import os
import re
import subprocess
import sys
from urllib.parse import unquote

ROOT = os.path.normpath(os.path.join(os.path.dirname(os.path.abspath(__file__)), '..'))
LEVELS = ('essential', 'birder', 'expert')
ROLES = ('viewer', 'operator', 'admin')


def read(path):
    with open(os.path.join(ROOT, path), encoding='utf-8') as f:
        return f.read()


def catalogue(lang):
    keys = set()
    d = os.path.join(ROOT, 'lang', lang)
    for n in os.listdir(d):
        if n.endswith('.json'):
            with open(os.path.join(d, n), encoding='utf-8') as f:
                keys.update(json.load(f).keys())
    return keys


def registry():
    src = read('scripts/view_registry.php')
    body = src[src.index('$r = array('):re.search(r'\n\s*\);\n\s*return \$r;', src).start()]
    entries = {}
    # 'Key' => $v(group, 'label', 'level'[, 'open'[, array(extra)]]),
    for m in re.finditer(r"^\s*'([^']+)' => \$v\((.*)\),\s*$", body, re.M):
        key, args = m.group(1), m.group(2)
        extra = {}
        em = re.search(r"array\((.*)\)\s*$", args)
        if em:
            for k, val in re.findall(r"'(\w+)' => ('[^']*'|array\([^)]*\))", em.group(1)):
                extra[k] = re.findall(r"'([^']*)'", val) if val.startswith('array') else val.strip("'")
            args = args[:em.start()]
        parts = [p.strip() for p in args.rstrip(', ').split(',')]
        vals = [None if p == 'null' else p.strip("'") for p in parts]
        e = {'group': vals[0], 'label': vals[1], 'level': vals[2], 'open': vals[3] if len(vals) > 3 else 'viewer',
             'change': extra.get('change', 'admin'), 'alias': extra.get('alias', []), 'when': extra.get('when'),
             'also': extra.get('also')}
        entries[key] = e
    return entries


def main():
    errors = []
    reg = registry()
    if not reg:
        print('check_views: no entry read from scripts/view_registry.php')
        return 1
    en, pt = catalogue('en'), catalogue('pt_BR')
    aliases = {}
    for k, e in reg.items():
        if e['level'] not in LEVELS:
            errors.append(f'{k}: level {e["level"]!r}')
        for r in ('open', 'change'):
            if e[r] not in ROLES:
                errors.append(f'{k}: {r} {e[r]!r}')
        for lk in [e['label']] + ([e['group']] if e['group'] else []):
            if lk not in en:
                errors.append(f'{k}: {lk} missing in lang/en')
            if lk not in pt:
                errors.append(f'{k}: {lk} missing in lang/pt_BR')
        for a in e['alias']:
            if a in reg:
                errors.append(f'{k}: alias {a!r} is a view key')
            if a in aliases:
                errors.append(f'{k}: alias {a!r} also of {aliases[a]}')
            aliases[a] = k
    known = set(reg) | set(aliases)
    views = read('homepage/views.php')
    for v in re.findall(r"\$_GET\['view'\] == \"([^\"]+)\"", views):
        if v not in reg:
            errors.append(f'homepage/views.php dispatches {v!r}, not in the registry')
    files = subprocess.run(['git', 'ls-files', '-co', '--exclude-standard'], cwd=ROOT, capture_output=True, text=True).stdout.split()
    for f in files:
        if f.startswith(('custom/', 'docs/', 'lang/', 'tests/')) or not f.endswith(('.php', '.js', '.py', '.sh', '.html')):
            continue
        try:
            text = read(f)
        except (OSError, UnicodeDecodeError):
            continue
        for m in re.finditer(r"(?<![A-Za-z_])view=([A-Za-z][A-Za-z0-9%/ +-]*?)(?=['\"&#<\s)]|$)", text):
            v = unquote(m.group(1).replace('+', ' ')).strip()
            if v and v not in known:
                errors.append(f'{f}: link to unknown view {v!r}')
    # every {menu:<view>} of the English texts names a view or an alias (US-51)
    for d in (os.path.join(ROOT, 'lang', 'en'),):
        for n in sorted(os.listdir(d)):
            if n.endswith('.json'):
                with open(os.path.join(d, n), encoding='utf-8') as f:
                    for k, txt in json.load(f).items():
                        for v in re.findall(r'\{menu:([^}]+)\}', txt):
                            if v not in known:
                                errors.append(f'lang/en/{n}: {k} names unknown view {v!r}')
    for e in sorted(set(errors)):
        print('check_views:', e)
    print(f'check_views: {len(reg)} views, {len(aliases)} aliases, {len(set(errors))} errors')
    return 1 if errors else 0


if __name__ == '__main__':
    sys.exit(main())
