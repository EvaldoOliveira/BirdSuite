"""Long-term storage of the clips' spectrograms as JPEG (owner 2026-10-22).

The app draws every clip's spectrogram as <clip>.png (utils/reporting.py) and keeps it so while the day runs.
Once a day (disk_species_clean.sh, 02:00) this script stores the pictures of the previous days as <clip>.jpg
(quality 85: about a quarter of the PNG, visually the same) and deletes the PNG. The audio clip is never touched:
it stays for a new analysis, and any picture can be drawn again from it.
Every reader takes <clip>.png first and <clip>.jpg after (common.php clip_spectrogram(), custom-audio-player.js).
Usage: spectro_archive.py [--print]
"""
import datetime
import os
import sys

from PIL import Image
from utils import core  # the folder layout (core.env)

# Pillow reads only the formats the station makes (spectrograms: PNG, JPEG) and never a picture larger than a
# spectrogram can be: the decoders of every other format, where Pillow's known vulnerabilities sit, are never reached
# (2026-11-02 release run 4)
Image.MAX_IMAGE_PIXELS = 40_000_000
IMAGE_FORMATS = ('PNG', 'JPEG')

BY_DATE = core.BS_BY_DATE
QUALITY = 85


def archive(path):
    jpg = path[:-4] + '.jpg'
    if not os.path.exists(jpg):
        tmp = jpg + '.tmp'
        try:
            with Image.open(path, formats=IMAGE_FORMATS) as img:
                img.convert('RGB').save(tmp, 'JPEG', quality=QUALITY, optimize=True)
            os.replace(tmp, jpg)
        finally:
            if os.path.exists(tmp):
                os.remove(tmp)
    os.remove(path)


def main():
    today = datetime.date.today().isoformat()
    done = failed = saved = 0
    if not os.path.isdir(BY_DATE):
        return
    for day in sorted(os.listdir(BY_DATE)):
        # only the date folders of the previous days (By_Date/shifted holds audio only)
        if len(day) != 10 or day >= today or not day[:4].isdigit():
            continue
        for root, _, files in os.walk(os.path.join(BY_DATE, day)):
            for name in files:
                if not name.endswith('.png'):
                    continue
                path = os.path.join(root, name)
                try:
                    size = os.path.getsize(path)
                    archive(path)
                    saved += size - os.path.getsize(path[:-4] + '.jpg')
                    done += 1
                except Exception as e:  # noqa: BLE001  any error here is logged and the station goes on
                    failed += 1
                    print(f'{path}: {e}', file=sys.stderr)
    if '--print' in sys.argv or failed:
        print(f'{done} spectrograms stored as JPEG, {saved / 1048576:.0f} MB freed, {failed} failed')


if __name__ == '__main__':
    main()
