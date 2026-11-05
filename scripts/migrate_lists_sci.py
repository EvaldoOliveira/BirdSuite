#!/usr/bin/env python3
"""The species lists in one scientific name per line (owner 2026-11-05, one index only, US-59).

A list made before that day holds "Scientific name_Common name" lines; the analysis and the pages read them by the part
before the '_', but the files keep the old common names. This rewrites every list of the station once: the running lists
(include / exclude / whitelist / nocturnal in config/species_lists/) and every saved list (species_lists/custom/*.txt
and its exclude/, whitelist/, nocturnal/ folders; the deleted lists in .deleted/ are left as they are). Each file keeps
its species, now by the scientific name only, sorted, without duplicates; comment lines (#) and empty lines go. A file
without any '_' line is not touched, so running it again changes nothing. The include list is a link to a saved list:
the saved list is rewritten, the link stays.

Usage: migrate_lists_sci.py [--check]   (--check: only say which files would change)
"""
import glob
import os
import sys

from utils import core


def files():
    out = []
    for name in ('exclude', 'whitelist', 'nocturnal', 'include'):
        p = os.path.join(core.BS_LISTS, f'{name}_species_list.txt')
        if os.path.isfile(p) and not os.path.islink(p):
            out.append(p)
    for folder in (core.BS_CUSTOM_LISTS, *(os.path.join(core.BS_CUSTOM_LISTS, k) for k in ('exclude', 'whitelist', 'nocturnal'))):
        out += [p for p in sorted(glob.glob(os.path.join(folder, '*.txt'))) if os.path.isfile(p) and not os.path.islink(p)]
    return out


def migrate(path, check=False):
    with open(path, encoding='utf-8') as f:
        lines = f.read().splitlines()
    if not any('_' in line for line in lines):
        return False
    sci = sorted({line.strip().partition('_')[0].strip() for line in lines if line.strip() and not line.strip().startswith('#')} - {''})
    if check:
        return True
    st = os.stat(path)
    tmp = path + '.tmp'
    with open(tmp, 'w', encoding='utf-8') as f:
        f.writelines(f'{s}\n' for s in sci)
    os.chmod(tmp, st.st_mode & 0o777)
    os.replace(tmp, path)
    return True


def main():
    check = '--check' in sys.argv[1:]
    changed = [p for p in files() if migrate(p, check)]
    if changed:
        print(f'migrate_lists_sci.py: {len(changed)} list(s) {"to rewrite" if check else "rewritten"} with scientific names only')
    return 0


if __name__ == '__main__':
    sys.exit(main())
