#!/usr/bin/env python3
"""Telegram notifications, guided (owner 2026-10-20) — run as the station user by Settings › Telegram
(scripts/telegram_settings.php). One bot, two groups: **Normal** (every notified detection) and **Rare** (the rare tier).

The secrets never enter the notification boxes: apprise.txt gets `tgram://$telegram_bot/$telegram_group_normal`, apprise-rare.txt gets
`tgram://$telegram_bot/$telegram_group_rare`; the bot token and the group ids go to apprise-tokens.txt (mode 600). Other Telegram lines in
the boxes (set by hand) are left as they are.
A station set up another way keeps its own placeholder names (2026-10-20: the Mesh writes `tgram://$token1/$token2` and
`tgram://$token3/$token4`): any `tgram://$name/$name` line is read as the Telegram setting and rewritten with the same
names, so the page shows and edits that configuration instead of adding a second one.

  telegram_setup.py status            JSON: bot set (its name), Normal / Rare group ids and names
  telegram_setup.py bot               bot token on stdin: checked with getMe, then stored
  telegram_setup.py chats             the groups the bot has seen (getUpdates): add the bot to a group and write in it first
  telegram_setup.py groups <n> <p>    the Normal and Rare group ids (p may be "-" = no Rare group: rare goes to Normal)
  telegram_setup.py test normal|rare  a test message to that group now
  telegram_setup.py remove            our lines out of both boxes (the bot token and the groups are kept)
  telegram_setup.py forget            remove, and the bot token and the remembered groups deleted
  telegram_setup.py on | off         the switch, only for Telegram already confirmed
  telegram_setup.py confirm           the user saw the test arrive: Telegram sends again (until then, after a new setup or a failed send, it is disabled)
"""
import contextlib
import json
import os
import re
import stat
import sys
import tempfile
import urllib.parse
import urllib.request
from pathlib import Path

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

BOX_NORMAL = os.path.join(core.BS_NOTIFY, 'apprise.txt')
BOX_RARE = os.path.join(core.BS_NOTIFY, 'apprise-rare.txt')
TOKENS = os.path.join(core.BS_NOTIFY, 'apprise-tokens.txt')
NAMES = os.path.join(core.BS_NOTIFY, 'telegram_groups.json')  # the group names, for the page (not secret)
# every group the bot was ever seen in (owner 2026-10-31: Telegram keeps a bot's messages only 24 hours, so groups joined
# before were never found again); per bot, mode 600 like the token store
SEEN = os.path.join(core.BS_NOTIFY, 'telegram_chats.json')
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
        raise SystemExit(t('str_no_answer_from_telegram_is_the_help', error=e.__class__.__name__)) from e


def keyset():
    """The placeholder names in use: (bot key of the Normal box, normal key, bot key of the Rare box, rare key)."""
    def first(path):
        for ln in read_lines(path):
            m = LINE.match(ln)
            if m:
                return m.group(1), m.group(2)
        return None
    # a new setup uses these names (2026-10-22); a setup made another way keeps its own (the Mesh's $tokenN)
    n = first(BOX_NORMAL) or ('telegram_bot', 'telegram_group_normal')
    r = first(BOX_RARE) or (n[0], 'telegram_group_rare')
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
    normal, rare = tok.get(k[1], ''), tok.get(k[3], '')
    # a configuration made elsewhere has no names yet: ask Telegram once and keep them (not secret)
    if bot and (not n.get('bot') or (normal and not n.get('normal')) or (rare and not n.get('rare'))):
        try:
            if not n.get('bot'):
                r = api(bot, 'getMe', timeout=5)
                if r.get('ok'):
                    n['bot'] = '@' + r['result'].get('username', '')
            for key, gid in (('normal', normal), ('rare', rare)):
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
            'kept_normal': '' if normal else tok.get('telegram_kept_normal', ''), 'kept_rare': '' if rare else tok.get('telegram_kept_rare', ''),
            'rare': rare, 'rare_name': n.get('rare', ''), 'disabled': notify_channels.disabled('telegram') if bot and normal else None,
            'other_lines': any(ln.lower().lstrip().startswith('tgram://') and not OURS.match(ln) for ln in read_lines(BOX_NORMAL) + read_lines(BOX_RARE))}


def set_bot():
    token = sys.stdin.readline().strip()
    if not re.fullmatch(r'\d{5,}:[A-Za-z0-9_-]{30,}', token):
        raise SystemExit(t('str_this_does_not_look_like_a_bot_help'))
    r = api(token, 'getMe')
    if not r.get('ok'):
        raise SystemExit(t('str_telegram_refused_the_token', reason=r.get('description', '')))
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
        raise SystemExit(t('str_set_the_bot_token_first'))
    r = api(token, 'getUpdates', {'timeout': 0, 'allowed_updates': json.dumps(['message', 'my_chat_member', 'channel_post'])})
    if not r.get('ok'):
        if r.get('error_code') == 409:
            # another program reads this bot (2026-10-22: the page says so in any language and opens the typed ids)
            return {'ok': False, 'conflict': True,
                    'error': t('str_telegram_another_program_reads_help',
                               reason=r.get('description', ''))}
        raise SystemExit('Telegram: ' + r.get('description', ''))
    bot_id = token.split(':', 1)[0]
    try:
        known = json.loads(Path(SEEN).read_text(encoding='utf-8'))
    except (OSError, ValueError):
        known = {}
    seen = dict(known.get(bot_id, {}))
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
    # the groups of the tokens already set count as seen too (a setup made before this list existed)
    tok = tokens()
    k = keyset()
    for key, gid in (('normal', tok.get(k[1], '')), ('rare', tok.get(k[3], ''))):
        if gid and gid not in seen:
            seen[gid] = names().get(key, '')
    if seen != known.get(bot_id, {}):
        known[bot_id] = seen
        old = os.umask(0o077)
        try:
            write_lines(SEEN, [json.dumps(known, ensure_ascii=False)])
        finally:
            os.umask(old)
        os.chmod(SEEN, 0o600)
    return {'ok': True, 'chats': [{'id': k, 'title': v} for k, v in seen.items()]}


def migrated(r):
    """A group upgraded to a supergroup answers with its new id (2026-10-22): that id, else None."""
    m = (r.get('parameters') or {}).get('migrate_to_chat_id')
    return str(m) if m else None


def set_groups(normal, rare):
    if not re.fullmatch(r'-?\d{5,}', normal) or not (rare == '-' or re.fullmatch(r'-?\d{5,}', rare)):
        raise SystemExit(t('str_group_ids_are_numbers_groups_help'))
    tok = tokens()
    k = keyset()
    bot = bot_token(tok, k)
    if not bot:
        raise SystemExit(t('str_set_the_bot_token_first'))
    n = names()
    for key, gid in (('normal', normal), ('rare', rare)):
        if gid == '-':
            n[key] = ''
            continue
        r = api(bot, 'getChat', {'chat_id': gid})
        if not r.get('ok') and migrated(r):
            gid = migrated(r)  # noqa: PLW2901  a group that became a supergroup goes on under its new id
            normal, rare = (gid, rare) if key == 'normal' else (normal, gid)
            r = api(bot, 'getChat', {'chat_id': gid})
        if not r.get('ok'):
            msg = (t('str_the_bot_cannot_reach_the_normal_help', id=gid, reason=r.get('description', ''))
                   if key == 'normal' else
                   t('str_the_bot_cannot_reach_the_rare_help', id=gid, reason=r.get('description', '')))
            raise SystemExit(msg)
        n[key] = r['result'].get('title', '')
    tok[k[0]] = tok[k[2]] = bot
    tok[k[1]] = normal
    if rare == '-':
        tok.pop(k[3], None)
    else:
        tok[k[3]] = rare
    save_tokens(tok)
    write_lines(NAMES, [json.dumps(n, ensure_ascii=False)])
    lines = [ln for ln in read_lines(BOX_NORMAL) if not OURS.match(ln)] + [f'tgram://${k[0]}/${k[1]}']
    write_lines(BOX_NORMAL, lines)
    rare = [ln for ln in read_lines(BOX_RARE) if not OURS.match(ln)]
    if rare != '-':
        rare.append(f'tgram://${k[2]}/${k[3]}')
    write_lines(BOX_RARE, rare)
    notify_channels.disable('telegram', 'setup')
    return {'ok': True, 'normal_name': n.get('normal', ''), 'rare_name': n.get('rare', '')}


def test(which):
    tok = tokens()
    k = keyset()
    gid = tok.get(k[1] if which == 'normal' else k[3])
    bot = bot_token(tok, k)
    if not bot or not gid:
        raise SystemExit(t('str_the_normal_group_is_not_set') if which == 'normal' else t('str_the_rare_group_is_not_set'))
    text = t('str_birdsuite_test_normal_help') if which == 'normal' else t('str_birdsuite_test_rare_group_help')
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
    """Telegram off: our lines out of both boxes, so nothing is sent. The bot token stays in the store and the two
    group ids are remembered (owner 2026-11-03: as the e-mail keeps its app passwords, a new setup finds the bot saved
    and the groups filled in, no new bot or token to look for)."""
    k = keyset()
    tok = tokens()
    bot, normal, rare = bot_token(tok, k), tok.get(k[1], ''), tok.get(k[3], '')
    write_lines(BOX_NORMAL, [ln for ln in read_lines(BOX_NORMAL) if not OURS.match(ln)])
    write_lines(BOX_RARE, [ln for ln in read_lines(BOX_RARE) if not OURS.match(ln)])
    keep = {key: v for key, v in tok.items() if key not in set(k)}
    if bot:
        keep['telegram_bot'] = bot  # the name a new setup uses (keyset() once the boxes are empty)
    if normal:
        keep['telegram_kept_normal'] = normal
    if rare:
        keep['telegram_kept_rare'] = rare
    save_tokens(keep)
    n = names()
    n.pop('normal', None)
    n.pop('rare', None)
    write_lines(NAMES, [json.dumps(n, ensure_ascii=False)])
    notify_channels.disable('telegram', 'unconfirmed')
    return {'ok': True}


def forget():
    """The token box emptied and saved (owner 2026-11-03): Telegram off and the bot token and the remembered groups
    deleted."""
    remove()
    tok = tokens()
    for key in ('telegram_bot', 'telegram_kept_normal', 'telegram_kept_rare'):
        tok.pop(key, None)
    save_tokens(tok)
    with contextlib.suppress(OSError):
        os.remove(NAMES)
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
        elif a[:1] == ['test'] and len(a) == 2 and a[1] in ('normal', 'rare'):
            res = test(a[1])
        elif a == ['remove']:
            res = remove()
        elif a == ['forget']:
            res = forget()
        elif a == ['confirm']:
            notify_channels.confirm('telegram')
            res = {'ok': True}
        elif a in (['on'], ['off']):
            # the switch of a confirmed channel (2026-10-26)
            if not notify_channels.turn('telegram', a == ['on']):
                raise SystemExit(t('str_send_a_test_and_confirm_that_it_help'))
            res = {'ok': True}
        else:
            raise SystemExit(__doc__)
    except SystemExit as e:
        print(json.dumps({'ok': False, 'error': str(e)}, ensure_ascii=False))
        sys.exit(1)
    print(json.dumps(res, ensure_ascii=False))


if __name__ == '__main__':
    main()
