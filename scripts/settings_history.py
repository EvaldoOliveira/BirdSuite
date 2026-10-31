#!/usr/bin/env python3
"""Settings history (owner 2026-10-31): every change of config/BirdSuite.conf, whoever wrote it (a page, a saved set of
System > Defaults, the update, a service, the command line), becomes one line of state/settings_history.log:

  2026-10-31 16:54:12 | FIELD_WIFI_MODE | off -> always | POST /scripts/settings_set.php from 192.168.68.101

Run by birdsuite_settings_history.path whenever the file changes. The previous content is kept in
state/settings_history.snapshot; the origin is the last request of the web server's access log
(/var/log/caddy/access.log) in the seconds before the change, else "not from the web". Passwords, tokens and
notification addresses are written as ***. state/ is running state, outside the backup (owner 2026-10-31).
Standard library only.
"""
import json
import os
import re
import sys
import time

HOME = os.path.dirname(os.path.dirname(os.path.realpath(__file__)))
CONF = os.path.join(HOME, 'config', 'BirdSuite.conf')
STATE = os.path.join(HOME, 'state')
LOG = os.path.join(STATE, 'settings_history.log')
SNAPSHOT = os.path.join(STATE, 'settings_history.snapshot')
ACCESS_LOG = '/var/log/caddy/access.log'
MAX_BYTES = 1024 * 1024
ORIGIN_WINDOW = 10  # seconds before the change a web request counts as its origin
SECRET = re.compile(r'PASS|PWD|TOKEN|SECRET|KEY|APPRISE_URL|_URLS?$|EMAIL|TELEGRAM|BIRDWEATHER_ID|ID$', re.I)


def parse(path):
    out = {}
    try:
        with open(path, encoding='utf-8', errors='replace') as fh:
            for line in fh:
                m = re.match(r'^([A-Za-z_][A-Za-z0-9_]*)=(.*)$', line.rstrip('\n'))
                if m:
                    out.setdefault(m.group(1), m.group(2))
    except OSError:
        pass
    return out


def shown(key, value):
    if value is None:
        return '(none)'
    if value.strip('"\'') and SECRET.search(key):
        return '***'
    return value


def origin(changed_at):
    """The last web request before the change (Caddy's JSON access log), else not from the web."""
    try:
        with open(ACCESS_LOG, 'rb') as fh:
            fh.seek(0, os.SEEK_END)
            fh.seek(max(0, fh.tell() - 256 * 1024))
            lines = fh.read().decode('utf-8', 'replace').splitlines()
    except OSError:
        return 'origin unknown (no web server log)'
    best = None
    for line in lines:
        try:
            e = json.loads(line)
            ts = float(e.get('ts', 0))
            req = e.get('request', {})
        except (ValueError, AttributeError, TypeError):
            continue
        uri = req.get('uri', '').split('?')[0]
        if not uri.startswith('/scripts/') and uri not in ('/', '/views.php'):
            continue
        if changed_at - ORIGIN_WINDOW <= ts <= changed_at + 1 and (req.get('method') != 'GET' or '?' in req.get('uri', '')):
            best = '%s %s from %s' % (req.get('method', '?'), uri, req.get('client_ip') or req.get('remote_ip', '?'))
    return best or 'not from the web (update, service or command line)'


def rotate():
    try:
        if os.path.getsize(LOG) > MAX_BYTES:
            os.replace(LOG, LOG + '.1')
    except OSError:
        pass


def main():
    os.makedirs(STATE, exist_ok=True)
    new = parse(CONF)
    if not new:
        return 0  # the file is being rewritten or missing: the next change event compares again
    stamp = time.strftime('%Y-%m-%d %H:%M:%S')
    lines = []
    if not os.path.exists(SNAPSHOT):
        lines.append('%s | (start) | %d settings recorded | settings history started' % (stamp, len(new)))
    else:
        old = parse(SNAPSHOT)
        try:
            changed_at = os.path.getmtime(CONF)
        except OSError:
            changed_at = time.time()
        where = None
        for key in sorted(set(old) | set(new)):
            if old.get(key) != new.get(key):
                where = where or origin(changed_at)
                lines.append('%s | %s | %s -> %s | %s' % (stamp, key, shown(key, old.get(key)), shown(key, new.get(key)), where))
    if lines:
        rotate()
        with open(LOG, 'a', encoding='utf-8') as fh:
            fh.write('\n'.join(lines) + '\n')
    tmp = SNAPSHOT + '.tmp'
    with open(CONF, 'rb') as src, open(tmp, 'wb') as dst:
        dst.write(src.read())
    os.replace(tmp, SNAPSHOT)
    for f in (SNAPSHOT, LOG):  # the snapshot holds the passwords of the settings: the station user only
        try:
            os.chmod(f, 0o600)
        except OSError:
            pass
    return 0


if __name__ == '__main__':
    sys.exit(main())
