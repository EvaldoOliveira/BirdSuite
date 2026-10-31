#!/usr/bin/env python3
"""Telegram notifications, guided (owner 2026-10-20) — run as the station user by Settings › Telegram
(scripts/telegram_settings.php). One bot, two groups: **Normal** (every notified detection) and **Prio** (the rare tier).

The secrets never enter the notification boxes: apprise.txt gets `tgram://$telegram_bot/$telegram_group_normal`, apprise-rare.txt gets
`tgram://$telegram_bot/$telegram_group_prio`; the bot token and the group ids go to apprise-tokens.txt (mode 600). Other Telegram lines in
the boxes (set by hand) are left as they are.
A station set up another way keeps its own placeholder names (2026-10-20: the Mesh writes `tgram://$token1/$token2` and
`tgram://$token3/$token4`): any `tgram://$name/$name` line is read as the Telegram setting and rewritten with the same
names, so the page shows and edits that configuration instead of adding a second one.

  telegram_setup.py status            JSON: bot set (its name), Normal / Prio group ids and names
  telegram_setup.py bot               bot token on stdin: checked with getMe, then stored
  telegram_setup.py chats             the groups the bot has seen (getUpdates): add the bot to a group and write in it first
  telegram_setup.py groups <n> <p>    the Normal and Prio group ids (p may be "-" = no Prio group: rare goes to Normal)
  telegram_setup.py test normal|prio  a test message to that group now
  telegram_setup.py remove            our lines out of both boxes, our tokens out of the store
  telegram_setup.py on | off         the switch, only for Telegram already confirmed
  telegram_setup.py confirm           the user saw the test arrive: Telegram sends again (until then, after a new setup or a failed send, it is disabled)
"""
import json
import os
import re
import stat
import sys
import tempfile
import urllib.parse
import urllib.request

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
try:
    from utils.i18n import t  # the page's language (US-122)
except Exception:
    def t(text, **values):
        for k, v in values.items():
            text = text.replace('{' + k + '}', str(v))
        return text

BASE = os.path.expanduser('~/BirdNET-Pi')
from utils import token_store  # noqa: E402  the copy that keeps the secrets (owner 2026-10-31)
from utils import notify_channels  # noqa: E402  a channel sends only after the user confirmed a test (2026-10-26)
BOX_NORMAL = os.path.join(BASE, 'config', 'notifications', 'apprise.txt')
BOX_RARE = os.path.join(BASE, 'config', 'notifications', 'apprise-rare.txt')
TOKENS = os.path.join(BASE, 'config', 'notifications', 'apprise-tokens.txt')
NAMES = os.path.join(BASE, 'config', 'notifications', 'telegram_groups.json')  # the group names, for the page (not secret)
OURS = re.compile(r'^\s*tgram://\$\w+/\$\w+\s*$', re.I)  # a Telegram line with placeholders (ours or the Mesh's)
LINE = re.compile(r'^\s*tgram://\$(\w+)/\$(\w+)\s*$', re.I)


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
            try:
                os.fchown(fd, -1, st.st_gid)
            except OSError:
                pass
        except FileNotFoundError:
            mask = os.umask(0)
            os.umask(mask)
            os.fchmod(fd, 0o666 & ~mask)
        with os.fdopen(fd, 'w', encoding='utf-8') as f:
            f.write('\n'.join(lines) + ('\n' if lines else ''))
        os.replace(tmp, path)
    except BaseException:
        try:
            os.remove(tmp)
        except OSError:
            pass
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
    old = os.umask(0o077)
    try:
        write_lines(TOKENS, [f'{k}={v}' for k, v in tok.items()])
    finally:
        os.umask(old)
    os.chmod(TOKENS, 0o600)
    token_store.remember(tok)


def api(token, method, params=None, timeout=10):
    url = f'https://api.telegram.org/bot{token}/{method}'
    data = urllib.parse.urlencode(params).encode() if params else None
    try:
        with urllib.request.urlopen(urllib.request.Request(url, data=data), timeout=timeout) as r:
            return json.load(r)
    except urllib.error.HTTPError as e:
        try:
            return json.load(e)
        except ValueError:
            return {'ok': False, 'description': f'HTTP {e.code}'}
    except OSError as e:
        raise SystemExit(t('no answer from Telegram ({error}): is the station online?', error=e.__class__.__name__))


def keyset():
    """The placeholder names in use: (bot key of the Normal box, normal key, bot key of the Rare box, prio key)."""
    def first(path):
        for ln in read_lines(path):
            m = LINE.match(ln)
            if m:
                return m.group(1), m.group(2)
        return None
    # a new setup uses these names (2026-10-22); a setup made another way keeps its own (the Mesh's $tokenN)
    n = first(BOX_NORMAL) or ('telegram_bot', 'telegram_group_normal')
    r = first(BOX_RARE) or (n[0], 'telegram_group_prio')
    return n[0], n[1], r[0], r[1]


def bot_token(tok, k=None):
    k = k or keyset()
    return tok.get(k[0]) or tok.get(k[2]) or ''


def names():
    try:
        return json.load(open(NAMES, encoding='utf-8'))
    except (OSError, ValueError):
        return {}


def status():
    tok = tokens()
    n = names()
    k = keyset()
    bot = bot_token(tok, k)
    normal, prio = tok.get(k[1], ''), tok.get(k[3], '')
    # a configuration made elsewhere has no names yet: ask Telegram once and keep them (not secret)
    if bot and (not n.get('bot') or (normal and not n.get('normal')) or (prio and not n.get('prio'))):
        try:
            if not n.get('bot'):
                r = api(bot, 'getMe', timeout=5)
                if r.get('ok'):
                    n['bot'] = '@' + r['result'].get('username', '')
            for key, gid in (('normal', normal), ('prio', prio)):
                if gid and not n.get(key):
                    r = api(bot, 'getChat', {'chat_id': gid}, timeout=5)
                    if r.get('ok'):
                        n[key] = r['result'].get('title', '')
            write_lines(NAMES, [json.dumps(n, ensure_ascii=False)])
        except (SystemExit, OSError):
            pass
    # never the token itself: its public part (the bot's numeric id) and the last 4 characters, to show it is saved
    hint = (bot.split(':', 1)[0] + ':…' + bot[-4:]) if ':' in bot else ''
    return {'bot': bool(bot), 'bot_hint': hint, 'bot_name': n.get('bot', ''), 'normal': normal, 'normal_name': n.get('normal', ''),
            'prio': prio, 'prio_name': n.get('prio', ''), 'disabled': notify_channels.disabled('telegram') if bot and normal else None,
            'other_lines': any(ln.lower().lstrip().startswith('tgram://') and not OURS.match(ln) for ln in read_lines(BOX_NORMAL) + read_lines(BOX_RARE))}


def set_bot():
    token = sys.stdin.readline().strip()
    if not re.fullmatch(r'\d{5,}:[A-Za-z0-9_-]{30,}', token):
        raise SystemExit(t('this does not look like a bot token (it reads like 123456789:AAE…)'))
    r = api(token, 'getMe')
    if not r.get('ok'):
        raise SystemExit(t('Telegram refused the token: {reason}', reason=r.get('description', '')))
    tok = tokens()
    k = keyset()
    tok[k[0]] = token
    tok[k[2]] = token
    save_tokens(tok)
    n = names()
    n['bot'] = '@' + r['result'].get('username', '')
    write_lines(NAMES, [json.dumps(n, ensure_ascii=False)])
    return {'ok': True, 'bot_name': n['bot']}


def chats():
    token = bot_token(tokens())
    if not token:
        raise SystemExit(t('set the bot token first'))
    r = api(token, 'getUpdates', {'timeout': 0, 'allowed_updates': json.dumps(['message', 'my_chat_member', 'channel_post'])})
    if not r.get('ok'):
        if r.get('error_code') == 409:
            # another program reads this bot (2026-10-22: the page says so in any language and opens the typed ids)
            return {'ok': False, 'conflict': True,
                    'error': t('Telegram: {reason} (another program reads this bot: use a bot only for the station, or type the group id)',
                               reason=r.get('description', ''))}
        raise SystemExit('Telegram: ' + r.get('description', ''))
    seen = {}
    for u in r.get('result', []):
        for key in ('message', 'my_chat_member', 'channel_post'):
            c = (u.get(key) or {}).get('chat')
            if c and c.get('type') in ('group', 'supergroup', 'channel'):
                seen[str(c['id'])] = c.get('title', '')
                # the bot removed from a group (2026-10-22): that group is no longer offered
                if key == 'my_chat_member' and (u[key].get('new_chat_member') or {}).get('status') in ('left', 'kicked'):
                    seen.pop(str(c['id']), None)
            m = (u.get(key) or {}).get('migrate_to_chat_id')
            if m:  # a group upgraded to a supergroup gets a new id
                seen.pop(str(c['id']), None)
                seen[str(m)] = c.get('title', '')
    return {'ok': True, 'chats': [{'id': k, 'title': v} for k, v in seen.items()]}


def migrated(r):
    """A group upgraded to a supergroup answers with its new id (2026-10-22): that id, else None."""
    m = (r.get('parameters') or {}).get('migrate_to_chat_id')
    return str(m) if m else None


def set_groups(normal, prio):
    if not re.fullmatch(r'-?\d{5,}', normal) or not (prio == '-' or re.fullmatch(r'-?\d{5,}', prio)):
        raise SystemExit(t('group ids are numbers (groups start with -)'))
    tok = tokens()
    k = keyset()
    bot = bot_token(tok, k)
    if not bot:
        raise SystemExit(t('set the bot token first'))
    n = names()
    for key, gid in (('normal', normal), ('prio', prio)):
        if gid == '-':
            n[key] = ''
            continue
        r = api(bot, 'getChat', {'chat_id': gid})
        if not r.get('ok') and migrated(r):
            gid = migrated(r)
            normal, prio = (gid, prio) if key == 'normal' else (normal, gid)
            r = api(bot, 'getChat', {'chat_id': gid})
        if not r.get('ok'):
            msg = (t('the bot cannot reach the Normal group ({id}): {reason}; is the bot a member?', id=gid, reason=r.get('description', ''))
                   if key == 'normal' else
                   t('the bot cannot reach the Prio group ({id}): {reason}; is the bot a member?', id=gid, reason=r.get('description', '')))
            raise SystemExit(msg)
        n[key] = r['result'].get('title', '')
    tok[k[0]] = tok[k[2]] = bot
    tok[k[1]] = normal
    if prio == '-':
        tok.pop(k[3], None)
    else:
        tok[k[3]] = prio
    save_tokens(tok)
    write_lines(NAMES, [json.dumps(n, ensure_ascii=False)])
    lines = [ln for ln in read_lines(BOX_NORMAL) if not OURS.match(ln)] + [f'tgram://${k[0]}/${k[1]}']
    write_lines(BOX_NORMAL, lines)
    rare = [ln for ln in read_lines(BOX_RARE) if not OURS.match(ln)]
    if prio != '-':
        rare.append(f'tgram://${k[2]}/${k[3]}')
    write_lines(BOX_RARE, rare)
    notify_channels.disable('telegram', 'setup')
    return {'ok': True, 'normal_name': n.get('normal', ''), 'prio_name': n.get('prio', '')}


def test(which):
    tok = tokens()
    k = keyset()
    gid = tok.get(k[1] if which == 'normal' else k[3])
    bot = bot_token(tok, k)
    if not bot or not gid:
        raise SystemExit(t('the Normal group is not set') if which == 'normal' else t('the Prio group is not set'))
    text = t('BirdSuite: test message for the Normal group ✓') if which == 'normal' else t('BirdSuite: test message for the Prio group ✓')
    r = api(bot, 'sendMessage', {'chat_id': gid, 'text': text})
    if not r.get('ok') and migrated(r):
        # the group became a supergroup: keep its new id and send again
        gid = migrated(r)
        tok[k[1] if which == 'normal' else k[3]] = gid
        save_tokens(tok)
        r = api(bot, 'sendMessage', {'chat_id': gid, 'text': text})
    if not r.get('ok'):
        raise SystemExit('Telegram: ' + r.get('description', ''))
    return {'ok': True}


def remove():
    k = keyset()
    write_lines(BOX_NORMAL, [ln for ln in read_lines(BOX_NORMAL) if not OURS.match(ln)])
    write_lines(BOX_RARE, [ln for ln in read_lines(BOX_RARE) if not OURS.match(ln)])
    save_tokens({key: v for key, v in tokens().items() if key not in set(k)})
    try:
        os.remove(NAMES)
    except OSError:
        pass
    notify_channels.disable('telegram', 'unconfirmed')
    return {'ok': True}


def main():
    a = sys.argv[1:]
    try:
        if a == ['status']:
            res = status()
        elif a == ['bot']:
            res = set_bot()
        elif a == ['chats']:
            res = chats()
        elif a[:1] == ['groups'] and len(a) == 3:
            res = set_groups(a[1], a[2])
        elif a[:1] == ['test'] and len(a) == 2 and a[1] in ('normal', 'prio'):
            res = test(a[1])
        elif a == ['remove']:
            res = remove()
        elif a == ['confirm']:
            notify_channels.confirm('telegram')
            res = {'ok': True}
        elif a in (['on'], ['off']):
            # the switch of a confirmed channel (2026-10-26)
            if not notify_channels.turn('telegram', a == ['on']):
                raise SystemExit(t('Send a test and confirm that it arrived first.'))
            res = {'ok': True}
        else:
            raise SystemExit(__doc__)
    except SystemExit as e:
        print(json.dumps({'ok': False, 'error': str(e)}, ensure_ascii=False))
        sys.exit(1)
    print(json.dumps(res, ensure_ascii=False))


if __name__ == '__main__':
    main()
