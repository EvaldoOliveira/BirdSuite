"""Offline notification queue (US-120, owner 2026-10-20): a notification made while the station has no internet is kept
in ~/BirdNET-Pi/state/notify_queue.jsonl instead of being lost; the field service (scripts/field_daemon.py) runs
`notify_queue.py flush` as the station user once there is internet, and every queued one goes out with its original time."""
import fcntl
import json
import os
import socket
import time
from contextlib import contextmanager

QUEUE = os.path.expanduser('~/BirdNET-Pi/state/notify_queue.jsonl')
MAX_ITEMS = 500
# a failed send stays queued; the next try waits RETRY_SECONDS and an item is dropped after MAX_TRIES (2026-10-20)
RETRY_SECONDS = 300
MAX_TRIES = 12
# a flush stops sending after this and keeps the rest (the field service waits 300 s for it)
FLUSH_SECONDS = 240
ONLINE_TTL = 60

_online = {'at': 0.0, 'value': False}


def online(timeout=1.5, fresh=False):
    # any answer = online (a network may block one of them); IP literals only, so no DNS lookup can hang the
    # reporting path, and the answer is kept for a minute (offline every probe costs the timeouts) (2026-10-20)
    now = time.monotonic()
    if not fresh and _online['at'] and now - _online['at'] < ONLINE_TTL:
        return _online['value']
    value = False
    for host, port in (('1.1.1.1', 443), ('8.8.8.8', 53)):
        try:
            socket.create_connection((host, port), timeout=timeout).close()
            value = True
            break
        except OSError:
            continue
    _online.update(at=time.monotonic(), value=value)
    return value


@contextmanager
def _locked(suffix='.lock', blocking=True):
    # every read-modify-write of the queue under one flock, so an enqueue running beside a flush is never lost
    fd = os.open(QUEUE + suffix, os.O_RDWR | os.O_CREAT, 0o600)
    try:
        try:
            fcntl.flock(fd, fcntl.LOCK_EX | (0 if blocking else fcntl.LOCK_NB))
        except BlockingIOError:
            yield False
            return
        yield True
    finally:
        os.close(fd)


def _read():
    items = []
    try:
        with open(QUEUE, encoding='utf-8') as f:
            for line in f:
                try:
                    items.append(json.loads(line))
                except ValueError:
                    continue
    except OSError:
        pass
    return items


def _write(items):
    items = items[-MAX_ITEMS:]
    if not items:
        try:
            os.remove(QUEUE)
        except OSError:
            pass
        return
    tmp = QUEUE + '.tmp'
    with open(tmp, 'w', encoding='utf-8') as f:
        f.writelines(json.dumps(it) + '\n' for it in items)
    os.replace(tmp, QUEUE)


def enqueue(body, title, attached='', tier='normal'):
    if isinstance(attached, (list, tuple)):
        attached = ','.join(str(a) for a in attached)
    with _locked():
        _write(_read() + [{'body': body, 'title': title, 'attached': attached, 'tier': tier, 'at': time.strftime('%Y-%m-%d %H:%M')}])


def flush():
    if not os.path.isfile(QUEUE):
        return 0
    retry = QUEUE + '.retry'
    try:
        if time.time() - os.path.getmtime(retry) < RETRY_SECONDS:
            return 0
    except OSError:
        pass
    if not online(fresh=True):
        return 0
    from .notifications import notify
    # one flush at a time; the queue lock is held only to take the items and to put the failed ones back
    with _locked('.flush', blocking=False) as got:
        if not got:
            return 0
        with _locked():
            items = _read()
            _write([])
        sent = 0
        failed = []
        deadline = time.monotonic() + FLUSH_SECONDS
        for k, it in enumerate(items):
            if time.monotonic() > deadline:
                failed.extend(items[k:])
                break
            attached = it.get('attached') or ''
            parts = attached if isinstance(attached, list) else str(attached).split(',')
            # a temporary clip copy may be gone by now: sent without it (an image URL stays)
            attached = [p for p in parts if p and (p.startswith(('http://', 'https://')) or os.path.exists(p))]
            try:
                ok = notify(it['body'], '(%s) %s' % (it.get('at', ''), it['title']), attached, tier=it.get('tier', 'normal'), queue=False)
            except Exception:
                ok = False
            if ok is False:
                # kept as it was (its original time); dropped after MAX_TRIES
                it['tries'] = it.get('tries', 0) + 1
                if it['tries'] < MAX_TRIES:
                    failed.append(it)
            else:
                sent += 1
        if failed:
            with _locked():
                # the failed ones first, then what was queued meanwhile: the order stays by time
                _write(failed + _read())
            with open(retry, 'w'):
                pass
        else:
            try:
                os.remove(retry)
            except OSError:
                pass
    return sent
