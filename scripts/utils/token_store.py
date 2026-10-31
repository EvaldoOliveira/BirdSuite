"""The notification secrets stay (owner 2026-10-31: "o e-mail e o Telegram têm que ser perpétuos"). Settings › Telegram and
Settings › E-mail keep a second copy of the token store in state/apprise-tokens.keep (mode 600) every time they save it;
before each notification the store is checked against that copy and a value that went missing or was replaced by
anything else (2026-10-31: a Mesh start-up hook rewrote the store and the bot and the groups were gone) is put back.
Removing Telegram or e-mail on its page removes it from both. Standard library only.
"""
import os
import tempfile

BASE = os.path.dirname(os.path.dirname(os.path.dirname(os.path.realpath(__file__))))
STORE = os.path.join(BASE, 'config', 'notifications', 'apprise-tokens.txt')
KEEP = os.path.join(BASE, 'state', 'apprise-tokens.keep')


def read(path):
    out = {}
    try:
        with open(path, encoding='utf-8') as f:
            for line in f:
                k, _, v = line.strip().partition('=')
                if k and v:
                    out[k] = v
    except OSError:
        pass
    return out


def _write(path, tok):
    path = os.path.realpath(path)
    os.makedirs(os.path.dirname(path), exist_ok=True)
    old = os.umask(0o077)
    try:
        fd, tmp = tempfile.mkstemp(dir=os.path.dirname(path), prefix='.' + os.path.basename(path) + '.')
        with os.fdopen(fd, 'w', encoding='utf-8') as f:
            f.write(''.join(f'{k}={v}\n' for k, v in tok.items()))
        os.chmod(tmp, 0o600)
        os.replace(tmp, path)
    finally:
        os.umask(old)


def remember(tok):
    """The store as the page saved it: the copy follows it exactly (a removed name goes from the copy too)."""
    try:
        _write(KEEP, tok)
    except OSError:
        pass


def heal():
    """Puts back what the copy has and the store lost or holds differently; returns the names put back."""
    keep = read(KEEP)
    if not keep:
        return []
    store = read(STORE)
    lost = [k for k, v in keep.items() if store.get(k) != v]
    if lost:
        store.update({k: keep[k] for k in lost})
        try:
            _write(STORE, store)
        except OSError:
            return []
    return lost
