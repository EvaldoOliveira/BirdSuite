"""The Python reader of core.env (owner 2026-11-02): every BS_* key of that file as a module attribute, read as text
(only ${KEY} of an earlier key is replaced). Seeds: BS_HOME from the environment, else the program folder is the one
above scripts/ and BS_HOME the folder above that. load(home=...) gives the layout of another home (the tests)."""
import os
import re

_ENV = os.path.join(os.path.dirname(os.path.dirname(os.path.realpath(__file__))), 'core.env')


def load(home=None, prog=None):
    """The core keys as a dict, for this program folder or for the given home."""
    if home is None and prog is None:
        if os.environ.get('BS_HOME'):
            home = os.environ['BS_HOME']
        else:
            prog = os.path.dirname(os.path.dirname(_ENV))
    v = {'BS_HOME': home if home is not None else os.path.dirname(prog)}
    if prog is not None and home is None:
        v['BS_PROG'] = prog
    with open(_ENV, encoding='utf-8') as f:
        for line in f:
            m = re.match(r'^(BS_[A-Z0-9_]+)=(.*)$', line.rstrip('\n'))
            if m and m.group(1) not in v:
                v[m.group(1)] = re.sub(r'\$\{(BS_[A-Z0-9_]+)\}', lambda r: v.get(r.group(1), ''), m.group(2))
    return v


globals().update(load())
