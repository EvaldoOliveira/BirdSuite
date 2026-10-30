"""Notification channels are enabled only by the user (owner 2026-10-26): an e-mail or Telegram channel sends only after
the user sent a test from its page (Settings > E-mail / Telegram) and answered that it arrived. Never confirmed, set up
again, or a send that failed while the station was online = disabled, until the next confirmed test; nothing is sent
nor queued for a disabled channel, and its page says so.
A confirmed channel can be turned off and on again from its page without a new test ("off"); the switch exists only
for a channel the user already confirmed.
State: ~/BirdNET-Pi/state/notify_channels.json = {channel: {"state": "confirmed" | "off" | "setup" | "failed", "at": "YYYY-MM-DD HH:MM"}}."""
import json
import os
import time

STATE = os.path.expanduser('~/BirdNET-Pi/state/notify_channels.json')
# the channels with a page that tests and confirms them; other service lines (set by hand) are not gated
GATED = ('email', 'telegram')


def channel_of(line):
    s = line.lstrip().lower()
    if s.startswith('tgram://'):
        return 'telegram'
    if s.startswith(('mailto://', 'mailtos://')):
        return 'email'
    return 'other'


def _read():
    try:
        with open(STATE, encoding='utf-8') as f:
            d = json.load(f)
        return d if isinstance(d, dict) else {}
    except (OSError, ValueError):
        return {}


def _set(channel, state):
    d = _read()
    d[channel] = {'state': state, 'at': time.strftime('%Y-%m-%d %H:%M')}
    os.makedirs(os.path.dirname(STATE), exist_ok=True)
    tmp = STATE + '.tmp'
    with open(tmp, 'w', encoding='utf-8') as f:
        json.dump(d, f, ensure_ascii=False)
    os.replace(tmp, STATE)


def enabled(channel):
    return channel not in GATED or _read().get(channel, {}).get('state') == 'confirmed'


def disabled(channel):
    """None when the channel sends; else why not: {'reason': 'off' | 'unconfirmed' | 'setup' | 'failed', 'at': ...}."""
    if enabled(channel):
        return None
    e = _read().get(channel) or {}
    return {'reason': e.get('state') or 'unconfirmed', 'at': e.get('at', '')}


def confirm(channel):
    _set(channel, 'confirmed')


def turn(channel, on):
    """The page's switch: only between confirmed and off; False when the channel was not confirmed (test it first)."""
    state = _read().get(channel, {}).get('state')
    if on and state == 'off':
        _set(channel, 'confirmed')
    elif not on and state == 'confirmed':
        _set(channel, 'off')
    return state in ('confirmed', 'off')


def disable(channel, reason):
    if channel in GATED:
        _set(channel, reason)
