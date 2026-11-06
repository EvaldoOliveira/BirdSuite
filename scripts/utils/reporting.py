################################################################################
# scripts/utils/reporting.py - REPORTING OF A DETECTION: CLIPS, SPECTROGRAMS, UPLOADS
#
# PURPOSE:
#   Reporting of a detection: the clip extracted with sox, the spectrogram picture, the microphone values, the
#   station time, the upload to BirdWeather and the sound repository (paused when the network fails), the
#   audio loss.
#
# DEPENDENCIES:
#   - scripts/utils/classes.py
#   - scripts/utils/helpers.py
#   - scripts/utils/notifications.py
#   - scripts/spectrogram_png.py
#   - scripts/spectrogram.sh
#   - scripts/spectro_archive.py
#   - scripts/mic_state.sh
#   - scripts/birdnet_recording.sh
#
# USAGE:
#   Referenced by scripts/birdnet_recording.sh, scripts/mic_state.sh, scripts/sharing_page.php,
#   scripts/sound_repo_upload.sh, scripts/spectro_archive.py, scripts/spectrogram.php
################################################################################

import glob
import hashlib
import io
import json
import logging
import os
import re
import shutil
import sqlite3
import subprocess
import tempfile
import uuid
import time
from time import sleep

import requests
import soundfile
from PIL import Image, ImageDraw, ImageFont

from . import core
from . import locations as locations_util
from .classes import Detection, ParseFileName
from .helpers import CONF_PATH, DB_PATH, get_font, get_settings, is_test_location
from .notifications import get_notification_tier, sendAppriseNotifications

# Pillow reads only the formats the station makes (spectrograms: PNG, JPEG) and never a picture larger than a
# spectrogram can be: the decoders of every other format, where Pillow's known vulnerabilities sit, are never reached
# (2026-11-02 release run 4)
Image.MAX_IMAGE_PIXELS = 40_000_000
IMAGE_FORMATS = ('PNG', 'JPEG')

log = logging.getLogger(__name__)

_sound_repo_format_warned = False


def extract(in_file, out_file, start, stop, comments=None):
    # comments: 'KEY=value' tags written into the clip (Vorbis comments of a FLAC/OGG file, sox --comment)
    tags = []
    for i, c in enumerate(comments or []):
        tags += ['--comment' if i == 0 else '--add-comment', c]
    # a time limit (owner 2026-11-06): the analysis waits for the reporting thread, a stuck sox must not hold it
    result = subprocess.run(['sox', '-V1', f'{in_file}', *tags, f'{out_file}', 'trim', f'={start}', f'={stop}'],
                            check=True, capture_output=True, timeout=60)
    ret = result.stdout.decode('utf-8')
    err = result.stderr.decode('utf-8')
    if err:
        raise RuntimeError(f'{ret}:\n {err}')
    return ret


def extract_safe(in_file, out_file, start, stop, comments=None):
    conf = get_settings()
    # This section sets the SPACER that will be used to pad the audio clip with
    # context. If EXTRACTION_LENGTH is 10, for instance, 3 seconds are removed
    # from that value and divided by 2, so that the 3 seconds of the call are
    # within 3.5 seconds of audio context before and after.
    try:
        ex_len = conf.getint('EXTRACTION_LENGTH')
    except ValueError:
        ex_len = 6
    spacer = (ex_len - 3) / 2
    safe_start = max(0, start - spacer)
    safe_stop = min(conf.getint('RECORDING_LENGTH'), stop + spacer)

    extract(in_file, out_file, safe_start, safe_stop, comments)


# US-41: SPECTROGRAM_PALETTE -> sox options (same table as scripts/spectrogram.sh)
PALETTE_SOX_OPTS = {
    'viridis': ['-h', '-p', '5'],
    'ocean': ['-h', '-p', '2'],
    'grayscale': ['-m'],
}   # birdnet / inferno / soxheat / turbo / roseus = sox default colours


def sensitivity_sox_opts(conf):
    """US-42: SPECTROGRAM_FLOOR_DB / SPECTROGRAM_RANGE_DB -> sox -Z (top, dBFS) / -z (range)."""
    try:
        floor = max(-120, min(-40, int(float(conf.get('SPECTROGRAM_FLOOR_DB', -95)))))
    except (TypeError, ValueError):
        floor = -95
    try:
        rng = max(30, min(120, int(float(conf.get('SPECTROGRAM_RANGE_DB', 50)))))
    except (TypeError, ValueError):
        rng = 50
    return ['-Z', str(min(0, floor + rng)), '-z', str(rng)]


def spectrogram(in_file, title, comment, raw=0, palette='turbo', sens_opts=None):
    # Same picture as the Overview's "Currently Analyzing": scripts/spectrogram_png.py draws the live
    # Spectrogram page's palette, floor, range and contrast (read from BirdSuite.conf). sox is the fallback.
    # Stored as <clip>.jpg straight away (owner 2026-10-29: no PNG at all; about a quarter of the size, the quality of
    # the nightly archive, scripts/spectro_archive.py); every reader takes the .png first and the .jpg after.
    try:
        import spectrogram_png
        spectrogram_png.render(in_file, f'{in_file}.png', title, bool(int(raw)), comment)
        _to_jpeg(f'{in_file}.png')
        return
    except Exception as e:  # noqa: BLE001  any error here is logged and the station goes on
        log.warning('spectrogram_png failed, using sox: %s', e)
    fd, tmp_file = tempfile.mkstemp(suffix='.png')
    os.close(fd)
    args = ['sox', '-V1', f'{in_file}', '-n', 'remix', '1', 'rate', '24k', 'spectrogram',
            '-t', '', '-c', '', '-o', tmp_file]
    args += PALETTE_SOX_OPTS.get(palette, [])
    args += sens_opts or []
    args += ['-r'] if int(raw) else []

    # a time limit (owner 2026-11-06): the analysis waits for this thread, a stuck sox must not hold it
    result = subprocess.run(args, check=True, capture_output=True, timeout=60)
    ret = result.stdout.decode('utf-8')
    err = result.stderr.decode('utf-8')
    if err:
        raise RuntimeError(f'{ret}:\n {err}')
    img = Image.open(tmp_file, formats=IMAGE_FORMATS)
    height = img.size[1]
    width = img.size[0]
    draw = ImageDraw.Draw(img)
    title_font = ImageFont.truetype(get_font()['path'], 13)
    _, _, w, _ = draw.textbbox((0, 0), title, font=title_font)
    draw.text(((width-w)/2, 6), title, fill="white", font=title_font)

    comment_font = ImageFont.truetype(get_font()['path'], 11)
    _, _, _, h = draw.textbbox((0, 0), comment, font=comment_font)
    draw.text((1, height - (h + 1)), comment, fill="white", font=comment_font)
    img.save(f'{in_file}.png')
    os.remove(tmp_file)
    _to_jpeg(f'{in_file}.png')


def _to_jpeg(png, quality=85):
    """The spectrogram as JPEG (quality 85, as spectro_archive.py), the PNG removed; the PNG stays when it fails."""
    jpg, tmp = png[:-4] + '.jpg', png[:-4] + '.jpg.tmp'
    try:
        with Image.open(png, formats=IMAGE_FORMATS) as img:
            img.convert('RGB').save(tmp, 'JPEG', quality=quality, optimize=True)
        os.replace(tmp, jpg)
        os.remove(png)
    except Exception as e:  # noqa: BLE001  any error here is logged and the station goes on
        log.warning('spectrogram kept as PNG (%s): %s', png, e)
        if os.path.exists(tmp):
            os.remove(tmp)


def microphone():
    """The microphone the station records from, as scripts/mic_state.sh last wrote it (state/microphone.json, owner
    2026-11-04): {'name', 'port', 'gain', ...}; {} when unknown (the analysis is sandboxed and cannot ask the card)."""
    try:
        with open(os.path.join(core.BS_STATE, 'microphone.json'), encoding='utf-8') as f:
            m = json.load(f)
        return m if isinstance(m, dict) else {}
    except (OSError, ValueError):
        return {}


def mic_values(file=None):
    """(name, port, gain %) of the recording's microphone; an RTSP stream has no microphone of the station."""
    if file is not None and getattr(file, 'RTSP_id', ''):
        return ('RTSP ' + str(file.RTSP_id).strip('-'), None, None)
    m = microphone()
    return (m.get('name') or m.get('id') or None, m.get('port') or None, m.get('gain'))


# the scene of every detection, so a study can rebuild it later (owner 2026-11-04): what the database keeps in its own
# columns (Tz, Utc_Offset, Model, Software, Species_List, Audio_Loss_*, Sample_Rate, Channels) and, under Scenario, an
# id of config/data/scenarios/<id>.json with every setting (no secret), the contents of the lists in force, the model,
# the version, the time zone and the microphone; a new id only when one of them changed
_SECRET = re.compile(r'PWD|PASS|TOKEN|SECRET|KEY|APPRISE|EMAIL|BIRDWEATHER_ID|CADDY|RTSP_STREAM|URL|LINK', re.I)
_LISTS = ('include', 'exclude', 'whitelist', 'nocturnal')
_scene = {'at': 0.0, 'value': None}
_software = {'value': None}


def station_tz():
    """The station's time zone (the name /etc/localtime points to), '' when it cannot be read."""
    try:
        p = os.path.realpath('/etc/localtime')
        return p.split('zoneinfo/', 1)[1] if 'zoneinfo/' in p else ''
    except OSError:
        return ''


def utc_offset(date, time_of_day, tz):
    """The UTC offset (+HH:MM) of a local date and time in that zone, '' when unknown."""
    try:
        from zoneinfo import ZoneInfo
        import datetime as _dt
        z = _dt.datetime.strptime(f'{date} {time_of_day}', '%Y-%m-%d %H:%M:%S').replace(tzinfo=ZoneInfo(tz)).strftime('%z')
        return f'{z[:3]}:{z[3:]}' if z else ''
    except Exception:  # noqa: BLE001  an unknown zone is left empty
        return ''


def software_version():
    if _software['value'] is None:
        try:
            _software['value'] = subprocess.run(['git', '-C', core.BS_PROG, 'describe', '--tags', '--always'], capture_output=True,
                                                text=True, timeout=10, check=False).stdout.strip()
        except (OSError, subprocess.SubprocessError):
            _software['value'] = ''
    return _software['value']


def _lines(path):
    try:
        with open(path, encoding='utf-8') as f:
            return sorted(ln.strip() for ln in f if ln.strip())
    except OSError:
        return []


def scenario(conf):
    """{'id', 'tz', 'software', 'model', 'species_list'} of now; the snapshot written once per id (checked once a minute)."""
    now = time.time()
    if _scene['value'] is not None and now - _scene['at'] < 60:
        return _scene['value']
    tz = station_tz()
    data = {
        'software': software_version(), 'model': conf.get('MODEL'), 'time_zone': tz,
        'settings': {k: v for k, v in sorted(conf.items()) if not _SECRET.search(k)},
        'lists': {n: _lines(os.path.join(core.BS_LISTS, f'{n}_species_list.txt')) for n in _LISTS},
        'species_confidence': _lines(os.path.join(core.BS_LISTS, 'species_confidence.txt')),
        'microphone': microphone(),
    }
    blob = json.dumps(data, sort_keys=True, ensure_ascii=False)
    sid = hashlib.sha256(blob.encode('utf-8')).hexdigest()[:16]
    folder = os.path.join(core.BS_DATA, 'scenarios')
    target = os.path.join(folder, sid + '.json')
    if not os.path.exists(target):
        try:
            os.makedirs(folder, exist_ok=True)
            with open(target + '.tmp', 'w', encoding='utf-8') as f:
                json.dump(dict(data, id=sid, first_used=time.strftime('%Y-%m-%dT%H:%M:%S%z')), f, ensure_ascii=False, indent=1, sort_keys=True)
            os.replace(target + '.tmp', target)
        except OSError as e:
            log.warning('scenario %s not written: %s', sid, e)
    _scene.update(at=now, value={'id': sid, 'tz': tz, 'software': data['software'], 'model': conf.get('MODEL'),
                                 'species_list': conf.get('SPECIES_LIST') or ''})
    return _scene['value']


def audio_loss(file, conf):
    """(count, ms) of the audio the recorder lost inside this recording (state/audio_loss.log, birdnet_recording.sh);
    (None, None) when not known: an RTSP stream, or a recorder older than the log."""
    if file is None or getattr(file, 'RTSP_id', ''):
        return (None, None)
    path = os.path.join(core.BS_STATE, 'audio_loss.log')
    if not os.path.exists(path):
        return (None, None)
    start = file.file_date.timestamp()
    try:
        length = float(conf.get('RECORDING_LENGTH') or 15)
    except ValueError:
        length = 15.0
    n, ms = 0, 0.0
    try:
        with open(path, encoding='utf-8') as f:
            for ln in f:
                parts = ln.split()
                if not parts:
                    continue
                t = float(parts[0])
                if start <= t < start + length + 1:
                    n += 1
                    ms += float(parts[1]) if len(parts) > 1 and parts[1] else 0.0
        # a long-running station: the log keeps the last three days
        if os.path.getsize(path) > 512 * 1024:
            keep = time.time() - 3 * 86400
            with open(path, encoding='utf-8') as f:
                rows = [ln for ln in f if ln.split() and float(ln.split()[0]) >= keep]
            with open(path + '.tmp', 'w', encoding='utf-8') as f:
                f.writelines(rows)
            os.replace(path + '.tmp', path)
    except (OSError, ValueError):
        return (None, None)
    return (n, round(ms, 3))


def loss_in_clip(file, detection):
    """The audio (ms) the recorder lost inside the detection's own seconds of the recording (its start .. stop), from
    state/audio_loss.log; None when not known."""
    if file is None or getattr(file, 'RTSP_id', ''):
        return None
    path = os.path.join(core.BS_STATE, 'audio_loss.log')
    if not os.path.exists(path):
        return None
    t0 = file.file_date.timestamp() + float(detection.start)
    t1 = file.file_date.timestamp() + float(detection.stop)
    ms = 0.0
    try:
        with open(path, encoding='utf-8') as f:
            for ln in f:
                p = ln.split()
                if len(p) > 1 and p[1] and t0 <= float(p[0]) < t1 + 0.5:
                    ms += float(p[1])
    except (OSError, ValueError):
        return None
    return ms


def discard_for_loss(file, detection, conf):
    """True when the detection is dropped for the audio lost inside it (owner 2026-11-04, Station › Microphone):
    AUDIO_LOSS_DISCARD=1 and more than AUDIO_LOSS_MAX_MS ms lost in its own seconds."""
    if str(conf.get('AUDIO_LOSS_DISCARD', '0')).strip('"\'') != '1':
        return False
    try:
        limit = float(conf.get('AUDIO_LOSS_MAX_MS') or 20)
    except ValueError:
        limit = 20.0
    ms = loss_in_clip(file, detection)
    return ms is not None and ms > limit


def audio_format(path, conf):
    """(sample rate, channels) of the recording, from its WAV header; the settings' channels when it cannot be read."""
    try:
        import wave
        with wave.open(path, 'rb') as w:
            return (w.getframerate(), w.getnchannels())
    except Exception:  # noqa: BLE001  not a readable WAV
        try:
            return (None, int(conf.get('CHANNELS') or 0) or None)
        except ValueError:
            return (None, None)


def scene_values(file, detection, conf, mic=None, tz=None):
    """The values of the scene columns of one detection, in the order of SCENE_COLS."""
    sc = scenario(conf)
    zone = tz or sc['tz']
    n, ms = audio_loss(file, conf) if mic is None else (None, None)
    rate, ch = audio_format(file.file_name, conf) if file is not None else (None, None)
    return [zone, utc_offset(detection.date, detection.time, zone), sc['model'], sc['software'], sc['species_list'], n, ms, rate, ch, sc['id']]


SCENE_COLS = ['Tz', 'Utc_Offset', 'Model', 'Software', 'Species_List', 'Audio_Loss_N', 'Audio_Loss_Ms', 'Sample_Rate', 'Channels', 'Scenario']


def clip_tags(detection, conf, location=None, file=None, mic=None):
    # what was detected and the settings in force, inside the clip itself (owner 2026-10-09): the same values the
    # database keeps per detection, readable by any audio tool (metaflac --list, sox --i, players' tag views)
    if conf.get('AUDIOFMT', '').lower() not in ('flac', 'ogg', 'opus'):
        return []
    override = species_override(detection.scientific_name)
    tags = {
        'TITLE': f'{detection.common_name} ({detection.scientific_name})',
        'DATE': f'{detection.date}T{detection.time}',
        'BIRDNET_SCI_NAME': detection.scientific_name,
        'BIRDNET_COM_NAME': detection.common_name,
        'BIRDNET_CONFIDENCE': f'{detection.confidence:.4f}',
        'BIRDNET_START_S': f'{detection.start:.1f}',
        'BIRDNET_STOP_S': f'{detection.stop:.1f}',
        'BIRDNET_LATITUDE': conf.get('LATITUDE'),
        'BIRDNET_LONGITUDE': conf.get('LONGITUDE'),
        'BIRDNET_MODEL': conf.get('MODEL'),
        'BIRDNET_MIN_CONF': conf.get('CONFIDENCE'),
        'BIRDNET_SP_OVERRIDE': '' if override is None else override,
        'BIRDNET_SIGM_SENS': conf.get('SENSITIVITY'),
        'BIRDNET_OVERLAP': conf.get('OVERLAP'),
        'BIRDNET_LOC_THRESH': conf.get('SF_THRESH'),
        'BIRDNET_REC_LENGTH': conf.get('RECORDING_LENGTH'),
        'BIRDNET_EXTRACTION_LENGTH': conf.get('EXTRACTION_LENGTH'),
        'BIRDNET_STATION': conf.get('SITE_NAME'),
    }
    # the microphone, its port and its gain (owner 2026-11-04), as the database's Mic, Mic_Port, Mic_Gain
    # an imported file gives its own (a raw session of this station) or none (another recorder), never today's one
    imported = mic is not None
    mic, port, gain = mic if imported else mic_values(file)
    tags['BIRDNET_MICROPHONE'] = mic
    tags['BIRDNET_MIC_PORT'] = '' if port is None else 'USB ' + port
    m = {} if imported else microphone()
    tags['BIRDNET_MIC_GAIN'] = '' if gain is None else f"{gain} %" + (f" ({m['gain_db']:.2f} dB)" if m.get('gain_db') is not None else '')
    # the scene (owner 2026-11-04), as the database's columns
    zone, off, _model, soft, slist, n, ms, rate, ch, sid = scene_values(file, detection, conf, mic=(mic, port, gain) if imported else None)
    tags.update({'BIRDNET_TIME_ZONE': zone, 'BIRDNET_UTC_OFFSET': off, 'BIRDNET_SOFTWARE': soft, 'BIRDNET_SPECIES_LIST': slist,
                 'BIRDNET_AUDIO_LOSS': '' if n is None else f'{n} ({ms} ms)', 'BIRDNET_SCENARIO': sid})
    # the location the clip was recorded at (owner 2026-10-21), the same tag as the database's Location column
    loc = location if location is not None else locations_util.current(conf)
    if loc:
        tags['BIRDNET_LOCATION_ID'] = loc.get('id')
        tags['BIRDNET_LOCATION'] = loc.get('name')
    return [f'{k}={v}' for k, v in tags.items() if v not in (None, '')]


def extract_detection(file: ParseFileName, detection: Detection):
    conf = get_settings()
    new_file_name = (f'{detection.clip_prefix}-{detection.date}_{detection.time_safe}-{file.RTSP_id}'
                     f'birdnet-conf-{detection.confidence_pct}.{conf["AUDIOFMT"]}')
    new_dir = os.path.join(conf['EXTRACTED'], 'By_Date', f'{detection.date}', f'{detection.clip_folder}')
    new_file = os.path.join(new_dir, new_file_name)
    if os.path.isfile(new_file):
        log.warning('Extraction exists. Moving on: %s', new_file)
    else:
        os.makedirs(new_dir, exist_ok=True)
        extract_safe(file.file_name, new_file, detection.start, detection.stop, clip_tags(detection, conf, file=file))
        # the picture is optional, the detection is not (owner 2026-11-06): a spectrogram that cannot be drawn is logged
        # and the clip is kept without it (it can be drawn again from the clip); before, the error failed the whole
        # recording's reporting and sent it to quarantine with every detection in it unsaved
        try:
            spectrogram(new_file, detection.common_name, new_file.replace(os.path.expanduser('~/'), ''), conf['RAW_SPECTROGRAM'],
                        conf.get('SPECTROGRAM_PALETTE', 'turbo'), sensitivity_sox_opts(conf))
        except Exception as e:  # noqa: BLE001  any error here is logged and the station goes on
            log.error('spectrogram not drawn for %s: %s', os.path.basename(new_file), e)
    return new_file


def species_override(sci_name):
    # the species threshold (species_confidence.txt, US-48) in force for this species, None when it has none
    try:
        with open(os.path.join(core.BS_LISTS, 'species_confidence.txt'), encoding='utf-8') as f:
            for line in f:
                sci, _, value = line.strip().partition('=')
                if sci == sci_name and value:
                    return float(value)
    except (OSError, ValueError):
        pass
    return None


def write_to_db(file: ParseFileName, detection: Detection):
    conf = get_settings()
    last_error = None
    # Connect to SQLite Database
    for _attempt_number in range(3):
        con = None
        try:
            con = sqlite3.connect(DB_PATH, timeout=30)  # a page job (import, restore) may hold the lock for a moment
            cur = con.cursor()
            # safe to repeat: a recording analysed again (restart after an error) does not add the same row twice
            file_name = os.path.basename(detection.file_name_extr)
            mic, port, gain = mic_values(file)
            scene = scene_values(file, detection, conf)
            cur.execute("INSERT INTO detections (Date, Time, Sci_Name, Confidence, Lat, Lon, Cutoff, Week, Sens, Overlap, "
                        "File_Name, Loc_Thresh, Rec_Length, Sp_Override, Location, Mic, Mic_Port, Mic_Gain, " + ', '.join(SCENE_COLS) + ") "
                        "SELECT ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ? "
                        "WHERE NOT EXISTS (SELECT 1 FROM detections WHERE Date = ? AND Time = ? AND Sci_Name = ? AND File_Name = ?)",
                        (detection.date, detection.time, detection.scientific_name, detection.confidence,
                         conf['LATITUDE'], conf['LONGITUDE'], conf['CONFIDENCE'], str(detection.week), conf['SENSITIVITY'],
                         conf['OVERLAP'], file_name, conf.get('SF_THRESH'), conf.get('RECORDING_LENGTH'),
                         species_override(detection.scientific_name), locations_util.current_id(conf), mic, port, gain, *scene,
                         detection.date, detection.time, detection.scientific_name, file_name))
            # the species by its scientific name only (owner 2026-11-05): the names are presentation, never stored

            con.commit()
            break
        except BaseException as e:  # noqa: BLE001  any error here is logged and retried
            last_error = e
            log.warning("Database busy: %s", e)
            sleep(2)
        finally:
            if con is not None:
                con.close()
    else:
        log.error("Giving up writing detection to db: %s %s %s",
                  detection.date, detection.time, os.path.basename(detection.file_name_extr))
        # the report fails instead of going on (bug #64: with a full disk the detection was lost and its recording
        # deleted): the recording is analysed again (no space) or kept in the quarantine (any other error)
        raise RuntimeError(f'detection not written to the database: {last_error}') from last_error


def summary(file: ParseFileName, detection: Detection):
    # Date;Time;Sci_Name;Com_Name;Confidence;Lat;Lon;Cutoff;Week;Sens;Overlap
    # 2023-03-03;12:48:01;Phleocryptes melanops;Wren-like Rushbird;0.76950216;-1;-1;0.7;9;1.25;0.0
    conf = get_settings()
    s = (f'{detection.date};{detection.time};{detection.scientific_name};{detection.common_name};'
         f'{detection.confidence};'
         f'{conf["LATITUDE"]};{conf["LONGITUDE"]};{conf["CONFIDENCE"]};{detection.week};{conf["SENSITIVITY"]};'
         f'{conf["OVERLAP"]}')
    return s


def write_to_file(file: ParseFileName, detection: Detection):
    with open(os.path.join(core.BS_DATA, 'BirdDB.txt'), 'a') as rfile:
        rfile.write(f'{summary(file, detection)}\n')


def update_json_file(file: ParseFileName, detections: [Detection]):
    mask = f'{os.path.dirname(file.file_name)}/*.json' if file.RTSP_id is None else f'{os.path.dirname(file.file_name)}/*{file.RTSP_id}*.json'
    for f in glob.glob(mask):
        log.debug(f'deleting {f}')
        os.remove(f)
    write_to_json_file(file, detections)


def write_to_json_file(file: ParseFileName, detections: [Detection]):
    conf = get_settings()
    json_file = f'{file.file_name}.json'
    log.debug(f'WRITING RESULTS TO {json_file}')
    dets = {'file_name': os.path.basename(json_file), 'timestamp': file.iso8601, 'delay': conf['RECORDING_LENGTH'],
            'detections': [{"start": det.start, "common_name": det.common_name, "confidence": det.confidence} for det in
                           detections]}
    with open(json_file, 'w') as rfile:
        rfile.write(json.dumps(dets))
    log.debug(f'DONE! WROTE {len(detections)} RESULTS.')


def apprise(file: ParseFileName, detections: [Detection]):
    species_apprised_this_run = []
    conf = get_settings()
    if is_test_location(conf):
        return  # Test / Playback: recordings played to the station are not notified

    for detection in detections:
        # Apprise of detection if not already alerted this run.
        if detection.species not in species_apprised_this_run:
            try:
                sendAppriseNotifications(detection.scientific_name, detection.common_name, str(detection.confidence), str(detection.confidence_pct),
                                         os.path.basename(detection.file_name_extr), detection.date, detection.time, str(detection.week),
                                         conf['LATITUDE'], conf['LONGITUDE'], conf['CONFIDENCE'], conf['SENSITIVITY'], conf['OVERLAP'],
                                         file_path=detection.file_name_extr)

            except BaseException as e:
                log.exception('Error during Apprise:', exc_info=e)

            species_apprised_this_run.append(detection.species)


def sound_repo_upload_active(conf):
    # Same gate as the sound repository upload script: BirdDB-Br switched on (default off), a remote and a cadence > 0
    if str(conf.get('BIRDDB_ENABLED') or '0').strip() != '1':
        return False
    minutes = str(conf.get('SOUND_REPO_UPLOAD_MINUTES') or '0').strip()
    return bool(conf.get('SOUND_REPO_REMOTE')) and minutes.isdigit() and int(minutes) > 0


_conf_mtime = None


def _settings_if_changed(path=CONF_PATH):
    # re-read BirdSuite.conf only when the settings page has changed it: a reload per recording raced
    # with the page writing the file and could leave half a config as the global settings
    global _conf_mtime
    try:
        mtime = os.path.getmtime(path)
        if mtime != _conf_mtime:
            conf = get_settings(force_reload=True)
            _conf_mtime = mtime
            return conf
    except Exception as e:  # noqa: BLE001  any error here is logged and the station goes on
        log.warning('could not re-read %s: %s', path, e)
    return get_settings()


def sound_repo(file: ParseFileName, detections: [Detection]):
    # US-38: beside the BirdWeather upload, write every detection to the
    # owner's central sound repository (SOUND_REPO_PATH, empty = off) as the
    # extracted clip plus a sidecar JSON in a training-ready layout:
    # <repo>/<station>/<yyyy-mm-dd>/<Sci_name>/<clip> . Failures are logged,
    # never raised - this must not block the reporting queue.
    # The spool only exists to feed the upload: nothing is written while the
    # upload is off (no remote, or interval 0 = paused). Re-read the file so a
    # pause in the settings page applies without restarting the analysis.
    conf = _settings_if_changed()
    if not sound_repo_upload_active(conf) or is_test_location(conf):
        return
    # SOUND_REPO_PATH empty = the station's own spool (core.env BS_SOUND_REPO), made when missing; a folder set by hand
    # (a mounted disk) must exist: it is never created, a mount that is down would fill the mount point
    repo = conf.get('SOUND_REPO_PATH') or core.BS_SOUND_REPO
    if repo == core.BS_SOUND_REPO:
        os.makedirs(repo, exist_ok=True)
    for detection in detections:
        try:
            _sound_repo_write(conf, repo, detection)
        except BaseException as e:
            log.exception('Error during sound repo write:', exc_info=e)


def _sound_repo_write(conf, repo, detection):
    global _sound_repo_format_warned
    if not os.path.isdir(repo):
        raise RuntimeError(f'sound repo root missing (mount down?): {repo}')
    clip = detection.file_name_extr
    if not clip.endswith('.flac'):
        if not _sound_repo_format_warned:
            log.warning('sound repo requires AUDIOFMT=flac; skipping non-FLAC clips (this is logged once)')
            _sound_repo_format_warned = True
        return
    station = (conf.get('SITE_NAME') or 'station').replace(' ', '_')
    sci_dir = detection.scientific_name.replace(' ', '_')
    dest_dir = os.path.join(repo, station, str(detection.date), sci_dir)
    os.makedirs(dest_dir, exist_ok=True)
    # HH:MM:SS -> HHhMMmSSs in the repo copy: readable, and legal on SMB/Windows
    # (a colon is forbidden there and gets mangled by CIFS name mapping)
    dest_name = re.sub(r'(\d{2}):(\d{2}):(\d{2})', r'\1h\2m\3s', os.path.basename(clip))
    dest = os.path.join(dest_dir, dest_name)
    shutil.copy2(clip, dest)
    sha = hashlib.sha256()
    with open(clip, 'rb') as f:
        for chunk in iter(lambda: f.read(65536), b''):
            sha.update(chunk)
    meta = {
        'id': str(uuid.uuid4()),
        'station': conf.get('SITE_NAME'),
        'latitude': conf.get('LATITUDE'),
        'longitude': conf.get('LONGITUDE'),
        'date': str(detection.date),
        'time': str(detection.time),
        'timestamp': detection.iso8601,
        'week': detection.week,
        'scientific_name': detection.scientific_name,
        'common_name': detection.common_name,
        'confidence': detection.confidence,
        'model': conf.get('MODEL'),
        'sensitivity': conf.get('SENSITIVITY'),
        'overlap': conf.get('OVERLAP'),
        'cutoff': conf.get('CONFIDENCE'),
        'audio_format': conf.get('AUDIOFMT'),
        'extraction_length': conf.get('EXTRACTION_LENGTH'),
        'notification_tier': get_notification_tier(detection.scientific_name),
        'file': dest_name,
        'sha256': sha.hexdigest(),
    }
    # the biome, ecoregion and Brazilian biome of the current location (US-27, owner 2026-10-29)
    try:
        cur = locations_util.current(conf) or {}
    except Exception:  # noqa: BLE001  any error here is logged and the station goes on
        cur = {}
    meta.update({'biome': cur.get('biome', ''), 'ecoregion': cur.get('ecoregion', ''), 'biome_br': cur.get('biome_br', ''),
                 'land_cover': cur.get('land_cover', ''), 'built_up_pct': cur.get('built_up_pct'),
                 'setting': cur.get('setting', ''), 'built_up_500_pct': cur.get('built_up_500_pct')})
    with open(dest + '.json', 'w') as f:
        f.write(json.dumps(meta))
    log.info('sound repo: stored %s', os.path.basename(clip))


_bw_pause_until = 0.0   # after a network failure the uploads pause (Gitea #97); 0 = not paused
BW_PAUSE_SEC = 300
BW_CONNECT_SEC = 5


def _bw_network_failed(what, e):
    """A connection error or timeout: the uploads pause for BW_PAUSE_SEC (Gitea #97: the analysis hands the next
    recording over only after this reporting ended, and a stuck network cost 30 s plus 20 s per detection on every
    recording, so the analysis fell behind without end). Logged once per pause."""
    global _bw_pause_until
    _bw_pause_until = time.time() + BW_PAUSE_SEC
    log.error('Cannot POST %s: %s - BirdWeather uploads paused for %d min', what, e, BW_PAUSE_SEC // 60)


def bird_weather(file: ParseFileName, detections: [Detection]):
    global _bw_pause_until
    # BIRDWEATHER_ENABLED=0 pauses the uploads and keeps the token (absent = off, as factory_defaults.conf); re-read when the
    # settings page changed the file, so the switch applies without restarting the analysis
    conf = _settings_if_changed()
    if _bw_pause_until and time.time() < _bw_pause_until:
        return
    # one token for every location, or the token of the location the recording was made at (BIRDWEATHER_PER_LOCATION,
    # owner 2026-10-22: a BirdWeather station has fixed coordinates); a location without a token does not upload
    token = conf['BIRDWEATHER_ID']
    if (conf.get('BIRDWEATHER_PER_LOCATION') or '0').strip() == '1':
        loc = locations_util.current(conf)
        token = str((loc or {}).get('birdweather_id') or '').strip()
    if token == "" or (conf.get('BIRDWEATHER_ENABLED') or '0').strip() == '0' or is_test_location(conf):
        return
    if detections:
        try:
            data, samplerate = soundfile.read(file.file_name)
            buf = io.BytesIO()
            soundfile.write(buf, data, samplerate, format='FLAC')
            flac_data = buf.getvalue()
        except Exception as e:  # noqa: BLE001  any error here is logged and the station goes on
            log.error("Error during FLAC conversion: %s", e)
            return

        # POST soundscape to server
        soundscape_url = (f'https://app.birdweather.com/api/v1/stations/'
                          f'{token}/soundscapes?timestamp={file.iso8601}')

        try:
            response = requests.post(url=soundscape_url, data=flac_data, timeout=(BW_CONNECT_SEC, 30),
                                     headers={'Content-Type': 'audio/flac'})
            log.info("Soundscape POST Response Status - %d", response.status_code)
            sdata = response.json()
        except (requests.exceptions.ConnectionError, requests.exceptions.Timeout) as e:
            _bw_network_failed('soundscape', e)
            return
        except BaseException as e:  # noqa: BLE001  a service error of any kind: noted, the station goes on
            log.error("Cannot POST soundscape: %s", e)
            return
        if not sdata.get('success'):
            log.error(sdata.get('message'))
            return
        soundscape_id = sdata['soundscape']['id']
        _bw_pause_until = 0.0   # the service answered: a pause, if any, is over

        for detection in detections:
            # POST detection to server
            detection_url = f'https://app.birdweather.com/api/v1/stations/{token}/detections'

            data = {'timestamp': detection.iso8601, 'lat': conf['LATITUDE'], 'lon': conf['LONGITUDE'],
                    'soundscapeId': soundscape_id,
                    'soundscapeStartTime': detection.start, 'soundscapeEndTime': detection.stop,
                    'commonName': detection.common_name, 'scientificName': detection.scientific_name,
                    'algorithm': '2p4' if conf['MODEL'] == 'BirdNET_GLOBAL_6K_V2.4_Model_FP16' else 'alpha',
                    'confidence': detection.confidence}

            log.debug(data)
            try:
                response = requests.post(detection_url, json=data, timeout=(BW_CONNECT_SEC, 20))
                log.info("Detection POST Response Status - %d", response.status_code)
            except (requests.exceptions.ConnectionError, requests.exceptions.Timeout) as e:
                _bw_network_failed('detection', e)
                return   # the rest of this recording's detections wait with the pause
            except BaseException as e:  # noqa: BLE001  a service error of any kind: noted, the station goes on
                log.error("Cannot POST detection: %s", e)


def heartbeat():
    conf = get_settings()
    if conf['HEARTBEAT_URL']:
        try:
            result = requests.get(url=conf['HEARTBEAT_URL'], timeout=10)
            log.info('Heartbeat: %s', result.text)
        except BaseException as e:  # noqa: BLE001  a network or service error of any kind: noted, the station goes on
            log.error('Error during heartbeat: %s', e)
