#!/usr/bin/env python3
"""The web password is kept only as a hash (2026-11-02 release run 4, SEC-15): a CADDY_PWD in BirdSuite.conf that is
still plain text (an older station, or the first-run answer written before PHP was there) is replaced by its bcrypt
hash ($2b$, read by Caddy and by PHP's password_verify). Empty = the factory password, left empty. The plain value
never leaves this process (passed to php on stdin, not on the command line). Run by the installer and every update.
Usage: web_password_hash.py [conf]   (default: the station's BirdSuite.conf)"""
import contextlib
import os
import re
import subprocess
import sys
from pathlib import Path

sys.path.insert(0, os.path.dirname(os.path.realpath(__file__)))

from utils import core

HASH = re.compile(r'^\$2[aby]\$\d\d\$[./A-Za-z0-9]{53}$')
PHP = 'echo "\\$2b\\$" . substr(password_hash(stream_get_contents(STDIN), PASSWORD_BCRYPT), 4);'


def main(argv):
    conf = argv[1] if len(argv) > 1 else core.BS_CONF
    try:
        lines = Path(conf).read_text(encoding='utf-8').split('\n')
    except OSError:
        return 0
    changed = False
    for i, line in enumerate(lines):
        m = re.match(r'^CADDY_PWD=(.*)$', line)
        if not m:
            continue
        val = m.group(1).strip()
        if len(val) >= 2 and val[0] == val[-1] and val[0] in '"\'':
            val = val[1:-1]
        if val == '' or HASH.match(val):
            continue
        out = subprocess.run(['php', '-r', PHP], input=val, capture_output=True, text=True, timeout=30, check=False).stdout.strip()
        if not HASH.match(out):
            print('web_password_hash: php could not hash the password; left as it is', file=sys.stderr)
            return 1
        lines[i] = "CADDY_PWD='" + out + "'"
        changed = True
    if changed:
        st = os.stat(conf)
        tmp = conf + '.tmp'
        with open(tmp, 'w', encoding='utf-8') as f:
            f.write('\n'.join(lines))
        os.chmod(tmp, st.st_mode & 0o777)
        with contextlib.suppress(PermissionError):
            os.chown(tmp, st.st_uid, st.st_gid)
        os.replace(tmp, conf)
        print('web password kept as a hash')
    return 0


if __name__ == '__main__':
    sys.exit(main(sys.argv))
