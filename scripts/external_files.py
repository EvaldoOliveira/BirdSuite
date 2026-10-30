#!/usr/bin/env python3
"""External files (US-119, owner 2026-10-20): the species in the audio of any folder, with the station's model.

  external_files.py analyze <folder> --lat LAT --lon LON --loc-id ID --loc-name NAME [--resume]
      Every audio file of the folder (not its subfolders) is analysed with the station's model, confidence, species
      thresholds, include/exclude/whitelist lists and the location model at LAT/LON for the week of each file. Long
      files are read in blocks, so a recording of hours does not fill the memory. NOTHING enters the station's base:
      the results are written beside the audio —
        birdnet-external.json             status, progress, model, location, settings (read by the web page)
        birdnet-external-detections.csv   file, start/end in the file, date, time, species, confidence
        birdnet-external-species.csv      per species: count, best confidence, first and last time, files

  external_files.py pause | continue | cancel <folder>
      Pause freezes the analysis (nothing lost); Continue thaws it, or after a restart starts a new one that skips the
      files already done (the results are written after every file); Cancel stops it, keeping what was found.

  external_files.py import <folder> --mode copy|move
      The detections of a finished analysis enter the station's base at the analysis location (its Lat/Lon, so the
      location filter files them there): a clip and its spectrogram per detection in By_Date, like the live ones, with
      EXT in the name (removable as a batch), no notification, no upload. The audio files go to
      ~/BirdSongs/External/<folder name>/ — copied (the folder stays as it is) or moved.

Date and time of a file: from its name (AudioMoth 20251020_063000, BirdNET 2025-10-20-birdnet-06:30:00 /
2025-10-20_06h30m00s, field recorder 2025.10.20_06h30m00s, generic 2025-10-20 06-30-00), else the file's date.
Run by scripts/external_files.php as the station user, at low priority.
"""
import argparse
import csv
import datetime
import hashlib
import json
import os
import re
import shutil
import sqlite3
import subprocess
import sys
import time

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
try:
    from utils.i18n import t  # the page's language (US-122)
except Exception:  # never in the way of an analysis: the messages stay in English
    def t(text, **values):
        for k, v in values.items():
            text = text.replace('{' + k + '}', str(v))
        return text

AUDIO_EXT = ('.wav', '.flac', '.mp3', '.ogg', '.m4a', '.aac', '.opus', '.wma', '.aif', '.aiff')
STATUS = 'birdnet-external.json'
DET_CSV = 'birdnet-external-detections.csv'
SPECIES_CSV = 'birdnet-external-species.csv'
BLOCK_S = 60  # seconds read at a time
DB_PATH = os.path.expanduser('~/BirdNET-Pi/config/data/birds.db')


def audio_files(folder):
    return sorted(f for f in os.listdir(folder) if f.lower().endswith(AUDIO_EXT) and os.path.isfile(os.path.join(folder, f)))


def file_datetime(path):
    name = os.path.splitext(os.path.basename(path))[0]
    pats = [
        (r'(\d{4})(\d{2})(\d{2})[_T-](\d{2})(\d{2})(\d{2})', None),                       # AudioMoth
        (r'(\d{4})[-.](\d{2})[-.](\d{2})[ _T-]+(?:birdnet-)?(\d{2})[:hH_.-](\d{2})[:mM_.-](\d{2})', None),  # BirdNET, recorder, generic
    ]
    for pat, _ in pats:
        m = re.search(pat, name)
        if m:
            try:
                return datetime.datetime(*[int(x) for x in m.groups()]), 'name'
            except ValueError:
                pass
    return datetime.datetime.fromtimestamp(os.path.getmtime(path)), 'file date'


class Status:
    def __init__(self, folder):
        self.path = os.path.join(folder, STATUS)
        self.data = {}
        if os.path.isfile(self.path):
            try:
                with open(self.path, encoding='utf-8') as f:
                    self.data = json.load(f)
            except (OSError, ValueError):
                self.data = {}

    def save(self, **kw):
        self.data.update(kw)
        tmp = self.path + '.tmp'
        with open(tmp, 'w', encoding='utf-8') as f:
            json.dump(self.data, f, ensure_ascii=False, indent=1)
        os.replace(tmp, self.path)


FIELDS = ['file', 'start', 'end', 'date', 'time', 'date_from', 'sci_name', 'com_name', 'confidence']


def write_results(folder, rows):
    """The detections and the per-species summary beside the audio; written after every file, so a paused or cancelled
    analysis keeps what it found and Continue (after a restart) skips the files already done."""
    dets = [r for r in rows if 'sci_name' in r]
    tmp = os.path.join(folder, DET_CSV + '.tmp')
    with open(tmp, 'w', newline='', encoding='utf-8') as f:
        w = csv.DictWriter(f, fieldnames=FIELDS)
        w.writeheader()
        w.writerows(dets)
    os.replace(tmp, os.path.join(folder, DET_CSV))
    species = {}
    for r in dets:
        sp = species.setdefault(r['sci_name'], {'sci_name': r['sci_name'], 'com_name': r['com_name'], 'count': 0, 'best': 0.0,
                                                 'first': None, 'last': None, 'files': set()})
        sp['count'] += 1
        sp['best'] = max(sp['best'], float(r['confidence']))
        stamp = r['date'] + ' ' + r['time']
        sp['first'] = min(sp['first'] or stamp, stamp)
        sp['last'] = max(sp['last'] or stamp, stamp)
        sp['files'].add(r['file'])
    tmp = os.path.join(folder, SPECIES_CSV + '.tmp')
    with open(tmp, 'w', newline='', encoding='utf-8') as f:
        w = csv.writer(f)
        w.writerow(['sci_name', 'com_name', 'count', 'best_confidence', 'first', 'last', 'files'])
        for sp in sorted(species.values(), key=lambda x: (-x['count'], x['com_name'])):
            w.writerow([sp['sci_name'], sp['com_name'], sp['count'], sp['best'], sp['first'], sp['last'], len(sp['files'])])
    os.replace(tmp, os.path.join(folder, SPECIES_CSV))
    return dets, species


def analyze(folder, lat, lon, loc_id, loc_name, resume=False):
    import librosa
    from utils.analysis import load_global_model, loadCustomSpeciesList, loadSpeciesConfidence, splitSignal, filter_humans
    from utils.classes import week48
    from utils.helpers import get_settings, get_language

    st = Status(folder)
    files = audio_files(folder)
    conf = get_settings()
    # Continue after a restart (owner 2026-10-20): the files already done and their detections are kept
    done_files, rows, seconds_done = [], [], 0
    if resume:
        done_files = [f for f in st.data.get('done_files', []) if f in files]
        if os.path.isfile(os.path.join(folder, DET_CSV)):
            with open(os.path.join(folder, DET_CSV), encoding='utf-8') as f:
                rows = [r for r in csv.DictReader(f) if r.get('file') in done_files]
        rows += [e for e in st.data.get('errors', []) if e.get('file') in done_files]
        seconds_done = int((st.data.get('progress') or {}).get('seconds_done', 0))
        lat, lon = lat if lat is not None else st.data['location']['lat'], lon if lon is not None else st.data['location']['lon']
        loc_id, loc_name = loc_id or st.data['location']['id'], loc_name or st.data['location']['name']
    st.save(status='running', pid=os.getpid(), finished='', error='', imported=None, done_files=done_files,
            started=st.data.get('started') if resume and st.data.get('started') else time.strftime('%Y-%m-%d %H:%M:%S'),
            location={'id': loc_id, 'name': loc_name, 'lat': lat, 'lon': lon}, model=conf.get('MODEL'),
            settings={'confidence': conf.get('CONFIDENCE'), 'sensitivity': conf.get('SENSITIVITY'),
                      'overlap': conf.get('OVERLAP'), 'location_threshold': conf.get('SF_THRESH')},
            progress={'files_done': 0, 'files_total': len(files), 'current': '', 'seconds_done': 0})
    model = load_global_model()
    names = get_language(conf['DATABASE_LANG'])
    include = loadCustomSpeciesList(os.path.expanduser('~/BirdNET-Pi/config/species_lists/include_species_list.txt'))
    exclude = loadCustomSpeciesList(os.path.expanduser('~/BirdNET-Pi/config/species_lists/exclude_species_list.txt'))
    white = loadCustomSpeciesList(os.path.expanduser('~/BirdNET-Pi/config/species_lists/whitelist_species_list.txt'))
    thresholds = loadSpeciesConfidence(os.path.expanduser('~/BirdNET-Pi/config/species_lists/species_confidence.txt'))
    min_conf = conf.getfloat('CONFIDENCE')
    overlap = conf.getfloat('OVERLAP')
    step = model.chunk_duration - overlap
    for k, fname in enumerate(files):
        if fname in done_files:
            continue
        path = os.path.join(folder, fname)
        st.save(progress={'files_done': k, 'files_total': len(files), 'current': fname, 'seconds_done': seconds_done})
        try:
            start_dt, date_src = file_datetime(path)
            duration = librosa.get_duration(path=path)
        except Exception as e:  # unreadable file: noted, the others go on
            rows.append({'file': fname, 'error': str(e)[:200]})
            continue
        model.set_meta_data(lat, lon, week48(start_dt))
        expected = model.get_species_list()
        off = 0.0
        while off < duration:
            try:
                sig, rate = librosa.load(path, sr=model.sample_rate, mono=True, offset=off,
                                         duration=BLOCK_S + model.chunk_duration, res_type='soxr_hq')
            except Exception as e:
                rows.append({'file': fname, 'error': str(e)[:200]})
                break
            chunks = splitSignal(sig, rate, overlap, seconds=model.chunk_duration)
            # windows starting inside this block only (the extra seconds read finish the last window)
            n = sum(1 for i in range(len(chunks)) if i * step < BLOCK_S)
            preds = filter_humans([model.predict(c) for c in chunks[:n]])
            for i, entries in enumerate(preds):
                t0 = off + i * step
                for sci, score in entries:
                    if sci == 'Human_Human' or score < thresholds.get(sci, min_conf):
                        continue
                    if (include and sci not in include) or sci in exclude or (expected and sci not in expected and sci not in white):
                        continue
                    when = start_dt + datetime.timedelta(seconds=t0)
                    com = names.get(sci) or getattr(model, 'common_names', {}).get(sci, sci)
                    rows.append({'file': fname, 'start': round(t0, 2), 'end': round(t0 + model.chunk_duration, 2),
                                 'date': when.strftime('%Y-%m-%d'), 'time': when.strftime('%H:%M:%S'), 'date_from': date_src,
                                 'sci_name': sci, 'com_name': com, 'confidence': round(float(score), 4)})
            seconds_done += min(BLOCK_S, duration - off)
            off += BLOCK_S
            st.save(progress={'files_done': k, 'files_total': len(files), 'current': fname, 'seconds_done': round(seconds_done)})
        # checkpoint: this file is done
        done_files.append(fname)
        dets, _ = write_results(folder, rows)
        st.save(done_files=done_files, detections=len(dets), errors=[r for r in rows if 'error' in r][:20])
    dets = [r for r in rows if 'sci_name' in r]
    errors = [r for r in rows if 'error' in r]
    dets, species = write_results(folder, rows)
    st.save(status='done', finished=time.strftime('%Y-%m-%d %H:%M:%S'), detections=len(dets), species=len(species),
            errors=errors[:20], progress={'files_done': len(files), 'files_total': len(files), 'current': '', 'seconds_done': round(seconds_done)})


IMPORT_FLAGS = {'pause': False, 'cancel': False}


def import_signal(signum, frame):
    """Import control (owner 2026-10-20): USR1 = pause, USR2 = continue, TERM = cancel — acted on between two detections."""
    import signal
    if signum == signal.SIGUSR1:
        IMPORT_FLAGS['pause'] = True
    elif signum == signal.SIGUSR2:
        IMPORT_FLAGS['pause'] = False
    else:
        IMPORT_FLAGS['cancel'], IMPORT_FLAGS['pause'] = True, False


def import_checkpoint(st, con):
    """Wait here while paused (database committed: the station keeps writing); True when cancelled."""
    if IMPORT_FLAGS['pause'] and not IMPORT_FLAGS['cancel']:
        con.commit()
        st.save(status='import_paused', paused_at=time.strftime('%Y-%m-%d %H:%M:%S'))
        while IMPORT_FLAGS['pause'] and not IMPORT_FLAGS['cancel']:
            time.sleep(0.5)
        if not IMPORT_FLAGS['cancel']:
            st.save(status='importing')
    if IMPORT_FLAGS['cancel']:
        con.commit()
        st.save(status='import_cancelling')
        return True
    return False


def _import_undo(con, st, mode, new_names, new_files, audio_done, prev_status, prev_loc):
    """Cancel = nothing changes: the rows and clips this import added go, the audio goes back, the analysis stays."""
    for n, nm in enumerate(new_names):
        con.execute('DELETE FROM detections WHERE File_Name = ?', (nm,))
        if n % 200 == 0:
            con.commit()
    con.commit()
    con.close()
    for f in new_files:
        for x in (f, f + '.png', f + '.jpg'):
            try:
                os.remove(x)
            except OSError:
                pass
        try:
            os.rmdir(os.path.dirname(f))
        except OSError:
            pass
    for src, target in audio_done:
        try:
            if mode == 'move':
                shutil.move(target, src)
            else:
                os.remove(target)
        except OSError:
            pass
    st.save(status=prev_status, location=prev_loc, import_pid=None,
            import_note=t('Import cancelled {time}: the {n} detections already added were taken out again; nothing changed',
                          time=time.strftime('%Y-%m-%d %H:%M:%S'), n=len(new_names)))
    sys.exit(0)


def _clip_name(d, r, conf, claimed):
    """The clip name of an imported detection (claimed: clip name -> the source file it was cut from)."""
    name = f'{d.clip_prefix}-{d.date}_{d.time_safe}-EXT-birdnet-conf-{d.confidence_pct}.{conf["AUDIOFMT"]}'
    # two recorders in one folder, same species, second and confidence: the second one gets a short tag of its
    # source file (in the RTSP_id slot, -conf-NN. stays last), so it is not skipped as a duplicate; the CSV order
    # decides who keeps the plain name, so a re-import maps every detection to the same name (2026-10-20)
    if claimed.setdefault(name, r['file']) != r['file']:
        tag = hashlib.sha1(r['file'].encode('utf-8')).hexdigest()[:6]
        name = f'{d.clip_prefix}-{d.date}_{d.time_safe}-EXT-{tag}-birdnet-conf-{d.confidence_pct}.{conf["AUDIOFMT"]}'
    return name


def _cut_clip(src, out, start, stop, spacer, d, conf, loc):
    """Cut one detection clip from its source file, with its tags and spectrogram."""
    from utils.reporting import clip_tags, spectrogram, sensitivity_sox_opts
    os.makedirs(os.path.dirname(out), exist_ok=True)
    cmd = ['ffmpeg', '-v', 'error', '-y', '-ss', str(max(0.0, start - spacer)), '-t', str(stop - start + 2 * spacer), '-i', src, '-ac', '1']
    for tag in clip_tags(d, conf, loc):
        cmd += ['-metadata', tag]
    subprocess.run(cmd + [out], check=True)
    spectrogram(out, d.common_name, out.replace(os.path.expanduser('~/'), ''), conf['RAW_SPECTROGRAM'],
                conf.get('SPECTROGRAM_PALETTE', 'turbo'), sensitivity_sox_opts(conf))


def import_folder(folder, mode, loc_override=None):
    from utils.classes import Detection
    from utils.helpers import get_settings
    from utils.reporting import ensure_db_columns, species_override

    st = Status(folder)
    if st.data.get('status') not in ('done', 'imported'):
        raise SystemExit(t('the analysis of this folder is not finished'))
    conf = get_settings()
    prev_status, prev_loc = st.data.get('status'), st.data.get('location')
    loc = st.data.get('location') or {}
    if loc_override:  # the location chosen at import (owner 2026-10-20), kept as the analysis location too
        loc = loc_override
        st.save(location=loc)
    with open(os.path.join(folder, DET_CSV), encoding='utf-8') as f:
        dets = list(csv.DictReader(f))
    st.save(status='importing', import_pid=os.getpid(), import_progress={'done': 0, 'total': len(dets)}, import_error='', import_note='')
    new_names, new_files, audio_done = [], [], []  # what this import added, undone by a cancel

    def undo():
        _import_undo(con, st, mode, new_names, new_files, audio_done, prev_status, prev_loc)

    try:
        ex_len = conf.getint('EXTRACTION_LENGTH')
    except ValueError:
        ex_len = 6
    spacer = max(0.0, (ex_len - 3) / 2)
    con = sqlite3.connect(DB_PATH, timeout=30)
    ensure_db_columns(con)
    added = 0
    claimed = {}  # clip name -> the source file it was cut from
    for k, r in enumerate(dets):
        if import_checkpoint(st, con):
            undo()
        src = os.path.join(folder, r['file'])
        start, stop = float(r['start']), float(r['end'])
        when = datetime.datetime.strptime(r['date'] + ' ' + r['time'], '%Y-%m-%d %H:%M:%S')
        d = Detection(when, 0, stop - start, r['sci_name'], r['com_name'], r['confidence'])
        name = _clip_name(d, r, conf, claimed)
        out = os.path.join(conf['EXTRACTED'], 'By_Date', d.date, d.clip_folder, name)
        if not os.path.isfile(out):
            _cut_clip(src, out, start, stop, spacer, d, conf, loc)
            new_files.append(out)
        cur = con.execute(
            "INSERT INTO detections (Date, Time, Sci_Name, Com_Name, Confidence, Lat, Lon, Cutoff, Week, Sens, Overlap, File_Name, "
            "Loc_Thresh, Rec_Length, Sp_Override, Location) SELECT ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ? "
            "WHERE NOT EXISTS (SELECT 1 FROM detections WHERE File_Name = ?)",
            (d.date, d.time, d.scientific_name, d.common_name, d.confidence, loc.get('lat'), loc.get('lon'), conf['CONFIDENCE'],
             str(d.week), conf['SENSITIVITY'], conf['OVERLAP'], name, conf.get('SF_THRESH'), conf.get('RECORDING_LENGTH'),
             species_override(d.scientific_name), int(loc['id']) if str(loc.get('id') or '').isdigit() else None, name))
        added += cur.rowcount
        if cur.rowcount:
            new_names.append(name)
        # committed at once: the next clip is cut (ffmpeg, maybe from the NAS) with no write lock held, so the live
        # analysis can always write its detections
        con.commit()
        if k % 20 == 0:
            st.save(import_progress={'done': k + 1, 'total': len(dets)})
    con.commit()
    # the audio files into the station: ~/BirdSongs/External/<folder name>/
    dest = os.path.join(os.path.dirname(conf['EXTRACTED'].rstrip('/')), 'External', os.path.basename(os.path.normpath(folder)))
    os.makedirs(dest, exist_ok=True)
    for fname in audio_files(folder):
        if import_checkpoint(st, con):
            undo()
        target = os.path.join(dest, fname)
        if mode == 'move':
            shutil.move(os.path.join(folder, fname), target)
            audio_done.append((os.path.join(folder, fname), target))
        elif not os.path.exists(target):
            shutil.copy2(os.path.join(folder, fname), target)
            audio_done.append((os.path.join(folder, fname), target))
    con.close()
    st.save(status='imported', import_pid=None, imported={'at': time.strftime('%Y-%m-%d %H:%M:%S'), 'added': added, 'mode': mode, 'audio_to': dest},
            import_progress={'done': len(dets), 'total': len(dets)})


def job_pid(folder, st):
    """The process analysing this folder: the pid it recorded, else found by its command line (older analyses)."""
    pid = st.data.get('pid')
    try:
        # the recorded pid only while it is still this script (after a reboot it can be another process)
        if pid and b'external_files.py' in open(f'/proc/{pid}/cmdline', 'rb').read():
            return int(pid)
    except OSError:
        pass
    for d in os.listdir('/proc'):
        if not d.isdigit():
            continue
        try:
            cmd = open(f'/proc/{d}/cmdline', 'rb').read().split(b'\0')
        except OSError:
            continue
        args = [c.decode('utf-8', 'replace') for c in cmd]
        # the Python process itself (not the sudo / nohup that started it, which belong to another user)
        if args and 'python' in os.path.basename(args[0]) and any(a_.endswith('external_files.py') for a_ in args) and 'analyze' in args \
                and folder in [os.path.realpath(x) for x in args if x.startswith('/')]:
            return int(d)
    return None


def control(action, folder):
    """Pause (the process is frozen: nothing lost), continue (thawed; after a restart a new process skips the files
    already done), cancel (stopped; the results of the files done stay)."""
    import signal
    st = Status(folder)
    if st.data.get('status') in ('importing', 'import_paused'):  # an import: the process acts on the signal itself
        pid = int(st.data.get('import_pid') or 0)
        try:
            ours = pid and b'external_files.py' in open(f'/proc/{pid}/cmdline', 'rb').read()
        except OSError:
            ours = False
        if not ours:
            raise SystemExit(t('the import is not running'))
        os.kill(pid, {'pause': signal.SIGUSR1, 'continue': signal.SIGUSR2, 'cancel': signal.SIGTERM}[action])
        print(json.dumps({'ok': True, 'status': st.data.get('status')}))
        return
    pid = job_pid(folder, st)
    if action == 'pause':
        if not pid:
            raise SystemExit(t('no analysis running in this folder'))
        os.kill(pid, signal.SIGSTOP)
        st.save(status='paused', pid=pid, paused_at=time.strftime('%Y-%m-%d %H:%M:%S'))
    elif action == 'continue':
        if pid:
            os.kill(pid, signal.SIGCONT)
            st.save(status='running', pid=pid)
        else:
            subprocess.Popen([sys.executable, os.path.abspath(__file__), 'analyze', folder, '--resume'], start_new_session=True,
                             stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL, preexec_fn=lambda: os.nice(19))
            st.save(status='running')
    elif action == 'cancel':
        if pid:
            os.kill(pid, signal.SIGTERM)
            os.kill(pid, signal.SIGCONT)  # a frozen process handles the TERM once thawed
            for _ in range(20):
                if not os.path.exists(f'/proc/{pid}'):
                    break
                time.sleep(0.5)
        if Status(folder).data.get('status') in ('running', 'paused'):
            Status(folder).save(status='cancelled', finished=time.strftime('%Y-%m-%d %H:%M:%S'))
    print(json.dumps({'ok': True, 'status': Status(folder).data.get('status')}))


def main():
    import signal
    # Cancel = SIGTERM: the results of the files already done stay; the page shows the analysis as cancelled
    def cancelled(signum, frame):
        raise SystemExit('cancelled (SIGTERM)')
    signal.signal(signal.SIGTERM, cancelled)
    ap = argparse.ArgumentParser()
    ap.add_argument('action', choices=['analyze', 'import', 'pause', 'continue', 'cancel'])
    ap.add_argument('folder')
    ap.add_argument('--lat', type=float)
    ap.add_argument('--lon', type=float)
    ap.add_argument('--loc-id', type=int, default=0)
    ap.add_argument('--loc-name', default='')
    ap.add_argument('--mode', choices=['copy', 'move'], default='copy')
    ap.add_argument('--resume', action='store_true', help='continue an analysis: skip the files already done')
    a = ap.parse_args()
    folder = os.path.realpath(a.folder)
    if a.action == 'import':
        for sig in (signal.SIGUSR1, signal.SIGUSR2, signal.SIGTERM):
            signal.signal(sig, import_signal)
    if a.action in ('pause', 'continue', 'cancel'):
        try:
            control(a.action, folder)
        except (SystemExit, OSError) as e:
            print(json.dumps({'ok': False, 'error': str(e)}))
            sys.exit(1)
        return
    try:
        if a.action == 'analyze':
            analyze(folder, a.lat, a.lon, a.loc_id, a.loc_name, resume=a.resume)
        else:
            override = {'id': a.loc_id, 'name': a.loc_name, 'lat': a.lat, 'lon': a.lon} if a.lat is not None and a.lon is not None else None
            import_folder(folder, a.mode, override)
    except SystemExit as e:
        if a.action == 'import' and not e.code:
            return  # a cancelled import, undone
        if a.action == 'analyze' and 'SIGTERM' in str(e.code):
            # Cancel: recorded here, the page does not have to see the process end
            Status(folder).save(status='cancelled', finished=time.strftime('%Y-%m-%d %H:%M:%S'))
            return
        raise
    except BaseException as e:
        msg = f'{type(e).__name__}: {e}'[:500]
        if a.action == 'analyze' and 'SIGTERM' in msg:
            Status(folder).save(status='cancelled', finished=time.strftime('%Y-%m-%d %H:%M:%S'))
            return
        if a.action == 'analyze':
            Status(folder).save(status='error', error=msg, finished=time.strftime('%Y-%m-%d %H:%M:%S'))
        else:  # a failed import keeps the analysis: it can be imported again (rows already in are not added twice)
            Status(folder).save(status='done', import_error=msg)
        raise


if __name__ == '__main__':
    main()
