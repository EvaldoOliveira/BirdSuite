#!/usr/bin/env python3
"""E-mail notifications through Apprise (owner 2026-10-20) — run as the station user by Settings › E-mail
(scripts/email_settings.php). A station has no mail server of its own: the e-mail goes out through the SMTP of a
provider (Gmail, Outlook, Yahoo, iCloud, Zoho, or any server), with the sender account's app password.

The secrets never enter the notification boxes: apprise.txt (and apprise-rare.txt when asked) get ONE e-mail line with
placeholders — mailtos://$email_user:$email_password@<domain>?smtp=…&port=…&mode=…&from=$email_sender&to=$email_recipient&name=BirdSuite —
and the values go to apprise-tokens.txt (mode 600; scripts/utils/notifications.py replaces them when sending). $email
is the recipient, NOTIFICATION_EMAIL in BirdSuite.conf (written by the page), URL-encoded when it is put in (2026-10-20).

  email_setup.py status           JSON: configured, provider host/port/mode, sender, in the rare box too (no password)
  email_setup.py set              JSON on stdin: host, port, mode (starttls|ssl), sender, password (empty = keep), rare
  email_setup.py remove           the e-mail line out of both boxes, the e-mail tokens out of the store
  email_setup.py test             send a test e-mail now (the latest detection, like the Settings test button)
  email_setup.py on | off         the switch, only for e-mail already confirmed
  email_setup.py confirm          the user saw the test arrive: e-mail sends again (until then, after a new setup or a failed send, it is disabled)
"""
import contextlib
import json
import os
import re
import stat
import subprocess
import sys
import tempfile
import urllib.parse

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
try:
    from utils.i18n import t  # the page's language (US-122)
except Exception:  # noqa: BLE001  without the language helper the messages stay in English
    def t(text, **values):
        for k, v in values.items():
            text = text.replace('{' + k + '}', str(v))
        return text

from utils import (
    core,
    notify_channels,
    token_store,
)

BOXES = {'normal': os.path.join(core.BS_NOTIFY, 'apprise.txt'), 'rare': os.path.join(core.BS_NOTIFY, 'apprise-rare.txt')}
TOKENS = os.path.join(core.BS_NOTIFY, 'apprise-tokens.txt')
TOKEN_NAMES = ('email_user', 'email_password', 'email_sender')


def tok_get(tok, name):
    return tok.get(name, '')


def is_ours(ln):
    return IS_EMAIL_LINE.match(ln) and '$email_user' in ln
# the app password of every sender account used, kept (owner 2026-10-22: going back to an account, or setting e-mail up
# again after Remove, must not ask for its app password again): saved_<sender, URL-encoded>=<password, URL-encoded>
SAVED = 'saved_'


def saved_key(sender):
    return SAVED + urllib.parse.quote(sender.strip().lower(), safe='')
IS_EMAIL_LINE = re.compile(r'^\s*mailtos?://', re.I)
# a station with a mail server of its own (2026-10-20: the Mesh's postfix relay) has mailto://localhost:<port>?from=…
LOCAL_LINE = re.compile(r'^\s*mailto://localhost(?::(\d+))?[?/&]', re.I)


def read_lines(path):
    try:
        with open(path, encoding='utf-8') as f:
            return f.read().splitlines()
    except OSError:
        return []


def write_lines(path, lines):
    # atomic (2026-10-20): a temp file in the same folder, then os.replace, so a crash or a full disk never leaves a
    # truncated box or token store; the mode (and group) of the old file stay
    path = os.path.realpath(path)
    fd, tmp = tempfile.mkstemp(dir=os.path.dirname(path), prefix='.' + os.path.basename(path) + '.')
    try:
        try:
            st = os.stat(path)
            os.fchmod(fd, stat.S_IMODE(st.st_mode))
            with contextlib.suppress(OSError):
                os.fchown(fd, -1, st.st_gid)
        except FileNotFoundError:
            mask = os.umask(0)
            os.umask(mask)
            os.fchmod(fd, 0o666 & ~mask)
        with os.fdopen(fd, 'w', encoding='utf-8') as f:
            f.write('\n'.join(lines) + ('\n' if lines else ''))
        os.replace(tmp, path)
    except BaseException:
        with contextlib.suppress(OSError):
            os.remove(tmp)
        raise


def tokens():
    token_store.heal()  # what this page saved comes back when anything else changed it (owner 2026-10-31)
    out = {}
    for line in read_lines(TOKENS):
        k, _, v = line.partition('=')
        if k and v:
            out[k] = v
    return out


def save_tokens(tok):
    old_mask = os.umask(0o077)
    try:
        write_lines(TOKENS, [f'{k}={v}' for k, v in tok.items()])
    finally:
        os.umask(old_mask)
    os.chmod(TOKENS, 0o600)
    token_store.remember(tok)


def our_line(path):
    return next((ln for ln in read_lines(path) if is_ours(ln)), None)


def local_line(path):
    return next((ln for ln in read_lines(path) if LOCAL_LINE.match(ln)), None)


def status():
    line = our_line(BOXES['normal']) or our_line(BOXES['rare'])
    loc = local_line(BOXES['normal']) or local_line(BOXES['rare'])
    if not line and loc:
        # sent through this station's own mail server: no account, no password here
        import socket
        q = urllib.parse.parse_qs(urllib.parse.urlsplit(loc).query)
        sender = q.get('from', [''])[0].replace('$user', os.path.basename(os.path.expanduser('~'))).replace('$hostname', socket.gethostname())
        return {'configured': True, 'local': True, 'disabled': notify_channels.disabled('email'), 'rare': bool(local_line(BOXES['rare'])),
            'other_email_line': False,
                'sender': sender, 'has_password': False, 'host': 'localhost', 'port': LOCAL_LINE.match(loc).group(1) or '25', 'mode': 'local'}
    other = next((ln for ln in read_lines(BOXES['normal']) if IS_EMAIL_LINE.match(ln) and not is_ours(ln)), None)
    tok = tokens()
    out = {'configured': bool(line), 'rare': bool(our_line(BOXES['rare'])), 'other_email_line': bool(other),
           'sender': urllib.parse.unquote(tok_get(tok, 'email_sender')), 'user': urllib.parse.unquote(tok_get(tok, 'email_user')),
           'has_password': bool(tok_get(tok, 'email_password')),
           'saved_senders': [urllib.parse.unquote(k[len(SAVED):]) for k in tok if k.startswith(SAVED)]}
    out['disabled'] = notify_channels.disabled('email') if line else None
    if line:
        q = urllib.parse.parse_qs(urllib.parse.urlsplit(line.replace('$', '')).query)
        out.update({'host': q.get('smtp', [''])[0], 'port': q.get('port', [''])[0], 'mode': q.get('mode', [''])[0]})
    return out


def set_email(p):
    host = str(p.get('host', '')).strip()
    port = int(p.get('port') or 0)
    mode = p.get('mode') if p.get('mode') in ('starttls', 'ssl') else 'starttls'
    sender = str(p.get('sender', '')).strip()
    if not re.fullmatch(r'[A-Za-z0-9.-]+', host) or not 1 <= port <= 65535:
        raise SystemExit(t('str_a_valid_smtp_server_and_port_help'))
    if not re.fullmatch(r'[^@\s]+@[^@\s]+\.[^@\s]+', sender):
        raise SystemExit(t('str_a_valid_sender_address_is_needed'))
    tok = tokens()
    password = str(p.get('password', ''))
    if not password:
        # no new password: the one saved for this sender (or the one in use, for the same sender)
        same = urllib.parse.unquote(tok_get(tok, 'email_sender')).lower() == sender.lower() and tok_get(tok, 'email_password')
        kept = tok.get(saved_key(sender)) or (tok_get(tok, 'email_password') if same else '')
        if not kept:
            raise SystemExit(t('str_no_app_password_is_saved_for_help', sender=sender))
        password = urllib.parse.unquote(kept)
    tok['email_password'] = urllib.parse.quote(password, safe='')
    user = str(p.get('user', '')).strip() or sender
    # the new account is tried before it is saved (owner 2026-10-22: a refused Outlook replaced the Gmail that worked):
    # a refused password or an unreachable server changes nothing; offline, it is saved as before
    from utils.notify_queue import online as _online
    if _online():
        bad = smtp_login(host, port, mode, user, password)
        if bad and bad[0] == 'auth':
            return {'ok': False, 'reason': 'auth', 'error': t('str_the_e_mail_server_refused_the_help', detail=bad[1])
                    + '\n' + t('str_nothing_was_saved_the_setup_help')}
        if bad and bad[0] == 'connect':
            return {'ok': False, 'reason': 'connect', 'error': t('str_the_e_mail_server_did_not_help', detail=bad[1])
                    + '\n' + t('str_nothing_was_saved_the_setup_help')}
    tok['email_user'] = urllib.parse.quote(user, safe='')
    tok['email_sender'] = urllib.parse.quote(sender, safe='')
    tok[saved_key(sender)] = tok['email_password']
    save_tokens(tok)
    domain = sender.split('@', 1)[1]
    line = (f'mailtos://$email_user:$email_password@{domain}?smtp={host}&port={port}&mode={mode}'
            f'&from=$email_sender&to=$email_recipient&name=BirdSuite&format=text')
    for tier, path in BOXES.items():
        lines = [ln for ln in read_lines(path) if not is_ours(ln) and not LOCAL_LINE.match(ln)]
        if tier == 'normal' or p.get('rare'):
            lines.append(line)
        write_lines(path, lines)
    notify_channels.disable('email', 'setup')
    return {'ok': True}


def remove():
    for path in BOXES.values():
        write_lines(path, [ln for ln in read_lines(path) if not is_ours(ln) and not LOCAL_LINE.match(ln)])
    tok = {k: v for k, v in tokens().items() if k not in TOKEN_NAMES}  # the saved_* passwords stay for a new setup
    save_tokens(tok)
    notify_channels.disable('email', 'unconfirmed')
    return {'ok': True}


def forget(sender):
    """The app password box emptied and saved (owner 2026-11-03: "to delete, the user enters the key empty"): e-mail
    off and the saved password of that account deleted."""
    remove()
    tok = tokens()
    tok.pop(saved_key(sender), None)
    save_tokens(tok)
    return {'ok': True}


def smtp_check(line):
    """Log in to the provider's SMTP server first (2026-10-22): a plain reason (password refused, server not reached)
    instead of Apprise's 'error while submitting email'. None = fine; else (code, message)."""
    q = urllib.parse.parse_qs(urllib.parse.urlsplit(line.replace('$', '')).query)
    tok = tokens()
    return smtp_login(q.get('smtp', [''])[0], int(q.get('port', ['587'])[0] or 587), q.get('mode', ['starttls'])[0],
                      urllib.parse.unquote(tok_get(tok, 'email_user')), urllib.parse.unquote(tok_get(tok, 'email_password')))


def smtp_login(host, port, mode, user, password):
    import smtplib
    import socket
    import ssl
    try:
        if mode == 'ssl':
            s = smtplib.SMTP_SSL(host, port, timeout=20, context=ssl.create_default_context())
        else:
            s = smtplib.SMTP(host, port, timeout=20)
            s.starttls(context=ssl.create_default_context())
        try:
            s.login(user, password)
        finally:
            with contextlib.suppress(Exception):
                s.quit()
    except smtplib.SMTPAuthenticationError as e:
        return 'auth', f'{e.smtp_code} {e.smtp_error.decode(errors="replace")[:160]}'
    except (TimeoutError, socket.gaierror, ConnectionError, OSError) as e:
        return 'connect', f'{host}:{port} ({e.__class__.__name__})'
    except smtplib.SMTPException as e:
        return 'other', str(e)[:200]
    return None


def test():
    line = our_line(BOXES['normal']) or local_line(BOXES['normal'])
    if not line:
        raise SystemExit(t('str_e_mail_is_not_set_up_yet_help'))
    if not LOCAL_LINE.match(line):
        from utils.notify_queue import online as _online
        if _online():
            bad = smtp_check(line)
            if bad and bad[0] == 'auth':
                return {'ok': False, 'reason': 'auth', 'error': t('str_the_e_mail_server_refused_the_help', detail=bad[1])}
            if bad and bad[0] == 'connect':
                return {'ok': False, 'reason': 'connect', 'error': t('str_the_e_mail_server_did_not_help', detail=bad[1])}
            if bad:
                return {'ok': False, 'reason': 'other', 'error': bad[1]}
    sys.path.insert(0, core.BS_SCRIPTS)
    from utils.notify_queue import online
    if not online():
        raise SystemExit(t('str_no_internet_the_test_cannot_be_help'))
    with tempfile.NamedTemporaryFile('w', suffix='.txt', delete=False) as c, tempfile.NamedTemporaryFile('w', suffix='.txt', delete=False) as b:
        c.write(line + '\n')
        b.write(t('str_test_e_mail_from_your_birdsuite_help') + '\n')
    try:
        r = subprocess.run([core.BS_PYTHON, os.path.join(core.BS_SCRIPTS, 'send_test_notification.py'),
                            '--config', c.name, '--body', b.name, '--title', t('str_birdsuite_e_mail_test')],
                           cwd=core.BS_SCRIPTS, capture_output=True, text=True, timeout=90, check=False)
    finally:
        os.remove(c.name)
        os.remove(b.name)
    log = (r.stdout + r.stderr)
    failed = re.search(r'(Failed to send|Error|Exception|authentication|refused|timed out)[^\n]*', log, re.I)
    if failed:
        return {'ok': False, 'error': failed.group(0)[:300]}
    # ok only when Apprise says it sent the e-mail (2026-10-26: a test held back sent nothing and still said ok)
    if not re.search(r'Sent\s+e-?mail', log, re.I):
        return {'ok': False, 'error': t('str_nothing_was_sent_no_answer_from_help')}
    return {'ok': True}


def main():
    a = sys.argv[1:]
    try:
        if a == ['status']:
            res = status()
        elif a == ['set']:
            res = set_email(json.loads(sys.stdin.read() or '{}'))
        elif a == ['remove']:
            res = remove()
        elif a == ['forget']:
            res = forget(sys.stdin.read().strip())
        elif a == ['test']:
            res = test()
        elif a == ['confirm']:
            notify_channels.confirm('email')
            res = {'ok': True}
        elif a in (['on'], ['off']):
            # the switch of a confirmed channel (2026-10-26)
            if not notify_channels.turn('email', a == ['on']):
                raise SystemExit(t('str_send_a_test_and_confirm_that_it_help'))
            res = {'ok': True}
        else:
            raise SystemExit(__doc__)
    except SystemExit as e:
        print(json.dumps({'ok': False, 'error': str(e)}))
        sys.exit(1)
    print(json.dumps(res))


if __name__ == '__main__':
    main()
