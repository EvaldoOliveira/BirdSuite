"""One-time conversion of the notification variables to the names of 2026-10-22 (owner: "a rewrite", the names before
are gone): the Normal / Rare titles in BirdSuite.conf, body.txt and body-rare.txt, the Apprise boxes (apprise.txt,
apprise-rare.txt) and the names in the token store (apprise-tokens.txt). A whole $name is replaced, so a text already
converted is left as it is (safe to run on every update). Run by update_birdnet_snippets.sh.
Usage: migrate_notify_names.py [--print]
"""
import os
import re
import sys

BASE = os.path.expanduser('~/BirdNET-Pi')
TEXT = {'comname': 'common_name', 'sciname': 'scientific_name', 'confidencepct': 'conf_pct', 'confidence': 'conf',
        'cutoffpct': 'conf_min_pct', 'cutoff': 'conf_min', 'manualconf': 'conf_manual_pct', 'prob': 'occurrence_probability',
        'minprob': 'occurrence_probability_min', 'sens': 'sigmoid_sensitivity', 'duration': 'recording_length',
        'week': 'calendar_week', 'listenurl': 'listen_url', 'friendlyurl': 'listen_link_markdown', 'flickrimage': 'image'}
BOXES = {'email': 'email_recipient', 'emailuser': 'email_user', 'emailpass': 'email_password', 'emailfrom': 'email_sender',
         'tgbot': 'telegram_bot', 'tgnormal': 'telegram_group_normal', 'tgprio': 'telegram_group_prio'}
NAME = re.compile(r'\$([A-Za-z][A-Za-z0-9_]*)')


def convert(text, names):
    return NAME.sub(lambda m: '$' + names.get(m.group(1), m.group(1)), text)


def rewrite(path, change):
    try:
        with open(path, encoding='utf-8') as f:
            old = f.read()
    except OSError:
        return False
    new = change(old)
    if new == old:
        return False
    st = os.stat(path)
    tmp = path + '.migrate'
    # created with the file's own mode from the start: the token store (600) is never readable by others meanwhile
    fd = os.open(tmp, os.O_CREAT | os.O_WRONLY | os.O_TRUNC, st.st_mode & 0o777)
    with os.fdopen(fd, 'w', encoding='utf-8') as f:
        f.write(new)
    os.chmod(tmp, st.st_mode & 0o7777)
    os.replace(tmp, path)
    return True


def main():
    done = []
    conf = os.path.join(BASE, 'config', 'BirdSuite.conf')
    title = re.compile(r'^(APPRISE_NOTIFICATION_TITLE(?:_RARE)?=.*)$', re.M)
    if rewrite(conf, lambda s: title.sub(lambda m: convert(m.group(1), TEXT), s)):
        done.append('titles')
    for name in ('body.txt', 'body-rare.txt'):
        if rewrite(os.path.join(BASE, 'config', 'notifications', name), lambda s: convert(s, TEXT)):
            done.append(name)
    for name in ('apprise.txt', 'apprise-rare.txt'):
        if rewrite(os.path.join(BASE, 'config', 'notifications', name), lambda s: convert(s, BOXES)):
            done.append(name)
    keys = re.compile(r'^([A-Za-z][A-Za-z0-9_]*)=', re.M)
    if rewrite(os.path.join(BASE, 'config', 'notifications', 'apprise-tokens.txt'), lambda s: keys.sub(lambda m: BOXES.get(m.group(1), m.group(1)) + '=', s)):
        done.append('apprise-tokens.txt')
    if done or '--print' in sys.argv:
        print('notification names converted: ' + (', '.join(done) or 'nothing to do'))


if __name__ == '__main__':
    main()
