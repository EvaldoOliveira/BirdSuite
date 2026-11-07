#!/usr/bin/env python3
################################################################################
# tests/cross_page_test.py - CROSS-PAGE CONSISTENCY TEST
#
# PURPOSE:
#   Cross-page consistency test (US-06, US-19): a source list and the page it opens show the same rows.
#
# DEPENDENCIES:
#   - (none beyond the system)
#
# USAGE:
#   python3 tests/cross_page_test.py  [--base] [--max]
################################################################################

"""Cross-page consistency test (US-06, US-19): a source list and the page it opens show the same rows.

Read only (GET requests, no answer, no setting): safe on a live station. For every location of the Validation
picker (the current one, each location, All), with the location cookie set as a browser keeps it:
  1. the page's own location (window.BS_LOC) is the one asked;
  2. every species of Validation with detections still to review opens its review (the Review button's link:
     the species page with &review=1 and the frozen source CUR_SRC) with exactly that many rows (at most 500 a page);
  3. the species page the name links to (the location carried as &loc=, US-19) is not empty for those species;
  4. ui-standard.js carries the location to the species-page links and reloads a page shown again by Back.

Usage: python3 tests/cross_page_test.py [--base http://192.168.68.11] [--max N]   (N species per location, 0 = all)
Exit status 0 when every check passes, 1 otherwise.
"""
import argparse
import html
import re
import sys
import urllib.parse
import urllib.request

ROW = re.compile(r'<tr class="(g-cur[a-z]+)"([^>]*)>(.*?)</tr>', re.S)


def get(base, path, cookie=''):
    req = urllib.request.Request(base + path, headers={'Cookie': cookie} if cookie else {})
    with urllib.request.urlopen(req, timeout=60) as r:
        return r.read().decode('utf-8', 'replace')


def attr(s, name):
    m = re.search(r'\b' + name + r'="([^"]*)"', s)
    return html.unescape(m.group(1)) if m else ''


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument('--base', default='http://192.168.68.11')
    ap.add_argument('--max', type=int, default=15)
    a = ap.parse_args()
    base = a.base.rstrip('/') + '/'
    fails, checks = [], 0

    def check(ok, what):
        nonlocal checks
        checks += 1
        if not ok:
            fails.append(what)
            print('FAIL', what)

    first = get(base, 'views.php?view=Validation')
    pick = re.search(r'<select id="locpick".*?</select>', first, re.S)
    locs = re.findall(r'<option value="([0-9a-z]*)"', pick.group(0)) if pick else ['']
    print('locations:', ', '.join(v or 'current' for v in locs))

    js = get(base, 'static/ui-standard.js')
    check('BS_LOC' in js and "searchParams.set('loc'" in js, 'ui-standard.js carries the location to page links')
    check("'pageshow'" in js and 'bs_answered' in js, 'ui-standard.js reloads a page restored by Back after an answer')

    for loc in locs:
        cookie = 'loc_Validation=' + loc if loc else ''
        name = loc or 'current'
        page = get(base, 'views.php?view=Validation', cookie)
        m = re.search(r'window\.BS_LOC = ("[^"]*")', page)
        check(m is not None and m.group(1).strip('"') == ('' if loc in ('', ) else loc), f'[{name}] BS_LOC is the location shown')
        m = re.search(r'(?:var CUR_SRC = |window.BS_CURATION = {src: )"([^"]*)"', page)
        src = m.group(1).replace('\\/', '/') if m else ''
        check(bool(src), f'[{name}] Validation hands its source to the review (CUR_SRC)')
        todo = []
        for grp, attrs, body in ROW.findall(page):
            n = int(attr(attrs, 'data-todo') or 0)
            sci = attr(body, 'data-sci')
            if n > 0 and sci:
                todo.append((sci, n, grp))
        sample = todo if a.max == 0 else todo[:a.max]
        print(f'[{name}] {len(todo)} species with detections to review, checking {len(sample)}')
        carried = '&loc=' + loc if loc else ''
        for sci, n, grp in sample:
            q = 'views.php?view=Species%20Cards&sci=' + urllib.parse.quote(sci)
            rev = get(base, q + '&review=1' + src, cookie)
            rows = rev.count('class="rvrow"')
            check(rows == min(n, 500), f'[{name}] {sci} ({grp}): Validation counts {n} to review, the review lists {rows}')
            plain = get(base, q + carried)
            check('<p>No detections of' not in plain, f'[{name}] {sci}: the species page linked from Validation is empty')

    print(f'{checks} checks, {len(fails)} failed')
    return 1 if fails else 0


if __name__ == '__main__':
    sys.exit(main())
