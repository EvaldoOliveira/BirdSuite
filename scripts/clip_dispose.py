################################################################################
# scripts/clip_dispose.py - WHAT BECOMES OF A CLIP THE STATION REMOVES BY ITSELF
#
# PURPOSE:
#   What becomes of a clip the station removes by itself (US-128, owner 2026-10-29).
#
# DEPENDENCIES:
#   - scripts/utils/i18n.py
#   - scripts/utils/core.py
#   - scripts/utils/notifications.py
#   - scripts/disk_check.sh
#   - scripts/disk_species_clean.sh
#   - scripts/daily_cap.py
#
# USAGE:
#   python3 scripts/clip_dispose.py <arguments> (see main)
#   Referenced by scripts/daily_cap.py, scripts/disk_check.sh, scripts/disk_species_clean.sh,
#   scripts/doctor.php, scripts/location_data.php
################################################################################

"""What becomes of a clip the station removes by itself (US-128, owner 2026-10-29).

The full-disk purge (disk_check.sh), the per-species limit (disk_species_clean.sh) and the daily cap (daily_cap.py)
hand their clips here. FULL_DISK=purge or keep: the clip and its spectrogram are deleted, as before. FULL_DISK=external:
the audio is MOVED to FULL_DISK_PATH/<station>/By_Date/<date>/<species>/<clip> (only the sounds: the spectrogram is
deleted, it is drawn again from the audio), so a station that runs for years keeps every sound off its own disk.
The detections stay in the database in every case.

The external folder must exist, be writable by the station user, have at least 1 GB free and sit on another disk than
the station's recordings (moving onto the same disk frees nothing). When it cannot be used, FULL_DISK_FALLBACK decides
(asked beside the folder): keep (the default) = nothing is removed and a full disk stops the recording; purge = the clips
are deleted as with FULL_DISK=purge. The station notifies (US-13) when the folder stops being usable and when it is
usable again, once per change (state/external_backup.json).
Usage: clip_dispose.py [--check]   reads the clips (paths relative to By_Date, or absolute) one per line on stdin;
       --check says what will happen: exit 0 = clips can be removed (moved, or deleted), exit 1 = nothing may be removed
       (external folder unusable and fallback keep), with the reason.
"""
import json
import os
import re
import shutil
import sys

from utils import core

BY_DATE = core.BS_BY_DATE
STATE = os.path.join(core.BS_STATE, 'external_backup.json')


class ExternalUnavailable(Exception):
    pass


def setting(key, default=''):
    """A value of BirdSuite.conf, else of the factory defaults, else default."""
    val = default
    for f in ('factory_defaults.conf', 'BirdSuite.conf'):
        try:
            with open(os.path.join(core.BS_CONFIG, f), encoding='utf-8') as fh:
                for line in fh:
                    m = re.match(rf'^{key}=["\']?([^"\'\n]*)', line)
                    if m:
                        val = m.group(1).strip()
        except OSError:
            pass
    return val


def external():
    """True when FULL_DISK is external."""
    return setting('FULL_DISK', 'purge') == 'external'


def target_root():
    """FULL_DISK_PATH/<station>/By_Date, checked: raises ExternalUnavailable with the reason."""
    base = setting('FULL_DISK_PATH', '').rstrip('/')
    if not base:
        raise ExternalUnavailable('no external folder set (FULL_DISK_PATH)')
    if not os.path.isdir(base):
        raise ExternalUnavailable(f'{base} is not there (not mounted?)')
    if not os.access(base, os.W_OK | os.X_OK):
        raise ExternalUnavailable(f'{base} is not writable by the station user')
    if shutil.disk_usage(base).free < 1 << 30:
        raise ExternalUnavailable(f'{base} is full (less than 1 GB free)')
    try:
        if os.stat(base).st_dev == os.stat(BY_DATE).st_dev:
            raise ExternalUnavailable(f'{base} is on the station\'s own disk: moving there frees nothing')
    except OSError as e:
        raise ExternalUnavailable(str(e)) from e
    station = re.sub(r'[^\w.-]+', '_', setting('SITE_NAME', '') or os.uname().nodename).strip('_') or 'station'
    return os.path.join(base, station, 'By_Date')


def report(ok, why=''):
    """A notification when the external folder becomes unusable or usable again (not on every run): the state of the
    last check is kept in state/external_backup.json."""
    try:
        with open(STATE, encoding='utf-8') as f:
            was_ok = bool(json.load(f).get('ok', True))
    except (OSError, ValueError):
        was_ok = True
    if was_ok == ok:
        return
    try:
        with open(STATE + '.tmp', 'w', encoding='utf-8') as f:
            json.dump({'ok': ok, 'why': why}, f)
        os.replace(STATE + '.tmp', STATE)
    except OSError:
        return  # no state, no notification: it would repeat on every run
    try:
        from utils import notifications
        from utils.i18n import t
        if ok:
            text = t('str_the_external_backup_folder_can_help')
        elif setting('FULL_DISK_FALLBACK', 'keep') == 'purge':
            text = t('str_the_external_backup_folder_cannot_purge_help', why=why)
        else:
            text = t('str_the_external_backup_folder_cannot_keep_help', why=why)
        notifications.notify(text, f"BirdSuite {setting('SITE_NAME', '') or os.uname().nodename}", queue=True)
    except Exception as e:  # noqa: BLE001  never in the way of the purge
        print(f'external backup: notification not sent ({e})', file=sys.stderr)


class Disposer:
    """dispose(path) for many clips: the external folder checked once; unusable = ExternalUnavailable with the fallback
    keep, plain deletion with the fallback purge (self.why says why)."""

    def __init__(self):
        self.ext, self.root, self.why = external(), None, ''
        if self.ext:
            try:
                self.root = target_root()
                report(True)
            except ExternalUnavailable as e:
                report(False, str(e))
                if setting('FULL_DISK_FALLBACK', 'keep') != 'purge':
                    raise
                self.ext, self.why = False, f'external backup unavailable ({e}), fallback purge: '
        self.moved = self.deleted = 0

    def dispose(self, path):
        """Removes one clip (audio, or its .png/.jpg) from the station; True when it is gone from there."""
        p = path if os.path.isabs(path) else os.path.join(BY_DATE, path)
        if not os.path.lexists(p):
            return True
        if self.ext and not p.endswith(('.png', '.jpg')):
            rel = os.path.relpath(p, BY_DATE)
            if rel.startswith('..'):
                rel = os.path.basename(p)
            dst = os.path.join(self.root, rel)
            os.makedirs(os.path.dirname(dst), exist_ok=True)
            shutil.move(p, dst)
            self.moved += 1
        else:
            os.remove(p)
            self.deleted += not p.endswith(('.png', '.jpg'))
        return True

    def summary(self):
        return f'{self.moved} moved to {self.root}' if self.ext else f'{self.why}{self.deleted} deleted'


def main():
    if '--check' in sys.argv:
        try:
            d = Disposer()
            print(d.root or d.why or 'not external')
            return 0
        except ExternalUnavailable as e:
            print(e)
            return 1
    try:
        d = Disposer()
    except ExternalUnavailable as e:
        print(f'external backup unavailable, nothing removed: {e}', file=sys.stderr)
        return 3
    failed = 0
    for raw in sys.stdin:
        line = raw.rstrip('\n')
        if not line or line == 'temp':
            continue
        try:
            d.dispose(line)
        except OSError as e:
            failed += 1
            print(f'{line}: {e}', file=sys.stderr)
    print(d.summary() + (f', {failed} failed' if failed else ''))
    return 1 if failed else 0


if __name__ == '__main__':
    sys.exit(main())
