"""Recording watchdog (owner 2026-10-23): the shared microphone access (ALSA dsnoop) can lock up while recording and
livestream keep running — every clip is an empty 44-byte WAV, nothing is analysed and nobody notices (22:26 to 00:25
on the pilot). Run every minute from cron: when the microphone is present, the recording service runs and the clip
being recorded stays empty (or no clip appears) for two runs in a row, it stops the livestream and the recording,
starts the recording, then the livestream, and tells the station's notification channel. At most one restart every
10 minutes. State in ~/BirdNET-Pi/state/recording_watchdog.json (with the restarts of the last 24 h, read by the
Doctor); what it suspects and does goes to syslog, tag birdsuite-recording_watchdog (2026-10-23 test run).
Usage: recording_watchdog.py [--print]
"""
import glob
import json
import os
import subprocess
import sys
import syslog
import time

sys.path.insert(0, os.path.dirname(os.path.realpath(__file__)))
from utils.helpers import BASE_PATH, get_settings  # noqa: E402

STATE = os.path.join(BASE_PATH, 'state', 'recording_watchdog.json')
EMPTY = 1024          # a WAV header is 44 bytes; a clip with audio grows by ~96 KB a second
QUIET_RESTART = 600   # seconds between two restarts
DAY = 86400


def log(text):
    # cron throws the output away: what the watchdog suspects and does goes to syslog (birdsuite-recording_watchdog)
    syslog.openlog('birdsuite-recording_watchdog')
    syslog.syslog(text)


def active(unit):
    return subprocess.run(['systemctl', 'is-active', '--quiet', unit]).returncode == 0


def capture_card():
    out = subprocess.run(['arecord', '-l'], capture_output=True, text=True).stdout
    return any(line.startswith('card ') for line in out.splitlines())


def load():
    try:
        with open(STATE, encoding='utf-8') as f:
            return json.load(f)
    except (OSError, ValueError):
        return {}


def save(state):
    os.makedirs(os.path.dirname(STATE), exist_ok=True)
    tmp = STATE + '.tmp'
    with open(tmp, 'w', encoding='utf-8') as f:
        json.dump(state, f)
    os.replace(tmp, STATE)


def stuck(conf):
    """True when the recording produces nothing: the newest clip empty although old enough, or no clip lately."""
    length = int(float(conf.get('RECORDING_LENGTH') or 15))
    stream = os.path.join(os.path.expandvars(os.path.expanduser(conf.get('RECS_DIR') or '~/BirdSongs')), 'StreamData')
    wavs = sorted(glob.glob(os.path.join(stream, '*.wav')), key=os.path.getmtime)
    now = time.time()
    if not wavs:
        # the analysis removes the clips it read: no clip at all only means something when the service ran a while
        return False
    newest = wavs[-1]
    age = now - os.path.getmtime(newest)  # a locked-up clip is written once (its header) and never again
    if age > 3 * length + 30:
        return True                       # no new clip for a long time
    return age > 8 and os.path.getsize(newest) <= EMPTY


def restart():
    live = active('livestream')
    if live:
        subprocess.run(['sudo', '-n', '/usr/local/sbin/birdsuite-svc', 'stop', 'livestream'])
    subprocess.run(['sudo', '-n', '/usr/local/sbin/birdsuite-svc', 'stop', 'birdnet_recording'])
    time.sleep(2)
    subprocess.run(['sudo', '-n', '/usr/local/sbin/birdsuite-svc', 'start', 'birdnet_recording'])
    if live:
        time.sleep(2)
        subprocess.run(['sudo', '-n', '/usr/local/sbin/birdsuite-svc', 'start', 'livestream'])


def notify(conf, text):
    try:
        from utils import notifications
        from utils.i18n import t
        site = conf.get('SITE_NAME') or os.uname().nodename
        notifications.notify(t(text), f'BirdSuite {site}', queue=True)
    except Exception as e:  # a notification problem must never stop the watchdog
        print(f'notification failed: {e}', file=sys.stderr)


def main():
    conf = get_settings()
    state = load()
    if not active('birdnet_recording') or not capture_card():
        state['suspect'] = 0
        save(state)
        return
    # a microphone just pulled out or plugged in (udev / mic_hotplug.sh): the gap in the clips is the replug, and the
    # hot-plug service restarts the recording itself (owner 2026-10-25: a replug showed as a watchdog restart)
    marks = [os.path.join(BASE_PATH, 'state', n) for n in ('.mic_removed', 'mic_reconnected.json')]
    if any(os.path.exists(m) and time.time() - os.path.getmtime(m) < QUIET_RESTART for m in marks):
        state['suspect'] = 0
        save(state)
        return
    if not stuck(conf):
        if state.get('suspect'):
            state['suspect'] = 0
            save(state)
        if '--print' in sys.argv:
            print('recording ok')
        return
    state['suspect'] = state.get('suspect', 0) + 1
    if state['suspect'] == 1:
        log('suspect: the clip being recorded is empty or no new clip appeared, checking again in a minute')
    if state['suspect'] >= 2 and time.time() - state.get('last_restart', 0) > QUIET_RESTART:
        restart()
        now = time.time()
        # the restarts of the last 24 h, for the Doctor (Status page)
        history = [t for t in state.get('history', []) if now - t < DAY] + [now]
        state.update(suspect=0, last_restart=now, restarts=state.get('restarts', 0) + 1, history=history)
        log(f'recording produced empty clips: recording and livestream restarted ({len(history)} restart(s) in 24 h)')
        notify(conf, 'The recording was producing empty clips: recording and livestream restarted.')
        print('recording restarted')
    elif state['suspect'] == 2:
        log('recording still produces empty clips: restart held back (at most one every 10 minutes)')
    save(state)


if __name__ == '__main__':
    main()
