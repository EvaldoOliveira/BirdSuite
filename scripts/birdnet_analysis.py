################################################################################
# scripts/birdnet_analysis.py - THE ANALYSIS SERVICE
#
# PURPOSE:
#   The analysis service: takes each recording from the queue, runs the model(s) (utils/analysis.py), writes
#   the detections, extracts the clips and the spectrograms (utils/reporting.py) and sends the notifications
#   (utils/notifications.py); a full disk makes it wait and retry.
#
# DEPENDENCIES:
#   - scripts/utils/analysis.py
#   - scripts/utils/classes.py
#   - scripts/utils/helpers.py
#   - scripts/utils/reporting.py
#   - scripts/utils/shadow.py
#   - scripts/utils/core.py
#   - scripts/utils/analysis as an.py
#   - scripts/select_species_list.py
#
# USAGE:
#   python3 scripts/birdnet_analysis.py <arguments> (see main)
#   Referenced by templates/units/SVC_ANALYSIS.service
################################################################################

import errno
import logging
import os
import shutil
import os.path
import re
import signal
import subprocess
import sys
import threading
from queue import Queue
from subprocess import CalledProcessError

import inotify.adapters
from inotify.constants import IN_CLOSE_WRITE
from utils import core
from utils.analysis import UnreadableRecording, load_global_model, run_analysis
from utils.classes import ParseFileName
from utils.helpers import ANALYZING_NOW, MODEL_PATH, get_model_labels, get_settings, get_wav_files, set_label_file
from utils.reporting import apprise, bird_weather, extract_detection, heartbeat, sound_repo, summary, update_json_file, write_to_db, write_to_file, discard_for_loss
from utils.shadow import run_shadow_analysis

shutdown = False

# recordings whose analysis or report failed for want of space (bug #64, 2026-11-05 test station: the disk at 100% for
# 2.5 minutes, the quarantine move failed too, and the recordings stayed in StreamData until the next restart): kept
# where they are and analysed again, oldest first, once the card has RETRY_MIN_FREE_MB free again
NO_SPACE_RETRY = []
NO_SPACE_LOCK = threading.Lock()
RETRY_MIN_FREE_MB = 100
NO_SPACE_TEXT = re.compile(r'No space left on device|database or disk is full|disk I/O error', re.I)
# a database held by another writer for longer than write_to_db waits (3 x 30 s; a long import, a job): the recording is
# kept the same way and analysed again once the database answers (2026-11-06 second pass A1: it went to the quarantine
# and its detections were lost, though the write is safe to repeat)
DB_BUSY_TEXT = re.compile(r'database is locked|database table is locked', re.I)


def no_space(e):
    # an error of a full disk, anywhere in the chain: the errno, or the text of sox / SQLite / a sub-process
    while e is not None:
        if getattr(e, 'errno', None) == errno.ENOSPC:
            return True
        text = str(e)
        if isinstance(e, CalledProcessError) and e.stderr:
            text += e.stderr.decode('utf-8', 'replace') if isinstance(e.stderr, bytes) else str(e.stderr)
        if NO_SPACE_TEXT.search(text):
            return True
        e = e.__cause__ or e.__context__
    return False


def db_busy(e):
    # the database held by another writer, anywhere in the chain
    while e is not None:
        if DB_BUSY_TEXT.search(str(e)):
            return True
        e = e.__cause__ or e.__context__
    return False


def keep_for_retry(file_name, why='no space left'):
    with NO_SPACE_LOCK:
        if file_name not in NO_SPACE_RETRY:
            NO_SPACE_RETRY.append(file_name)
    log.warning('%s: %s kept in StreamData, analysed again once there is room and the database answers', why, os.path.basename(file_name))


def room_again():
    # the data folder (clips) and the database folder both have room, and the database takes a writer at once
    for path in (get_settings()['RECS_DIR'], core.BS_DATA):
        try:
            if shutil.disk_usage(path).free < RETRY_MIN_FREE_MB * 1024 * 1024:
                return False
        except OSError:
            return False
    try:
        import sqlite3
        from utils.helpers import DB_PATH
        con = sqlite3.connect(DB_PATH, timeout=1)
        try:
            con.execute('BEGIN IMMEDIATE')
            con.rollback()
        finally:
            con.close()
    except sqlite3.OperationalError:
        return False
    except Exception:  # noqa: BLE001  anything else: the retry itself will say
        pass
    return True


def retry_no_space(report_queue):
    # the kept recordings, oldest first, before the newest one, so the detections stay in their order
    with NO_SPACE_LOCK:
        if not NO_SPACE_RETRY:
            return
    if not room_again():
        return
    with NO_SPACE_LOCK:
        pending = sorted(NO_SPACE_RETRY)
        NO_SPACE_RETRY.clear()
    log.info('room again: analysing %d recording(s) kept for want of space', len(pending))
    for file_name in pending:
        if shutdown:
            break
        if os.path.exists(file_name):
            process_file(file_name, report_queue)
    # the last one's report is done before the newest recording goes in
    report_queue.join()

log = logging.getLogger(__name__)


def sig_handler(sig_num, curr_stack_frame):
    global shutdown
    log.info('Caught shutdown signal %d', sig_num)
    shutdown = True


def main():
    load_global_model()
    # the label file of the active model (the web pages read it: Change species lists every class of the model, US-30):
    # written when missing (an install without internet fetches BirdNET+ V3 only now) or when it is another model's
    # (MODEL changed: V2.4 has 6,522 classes, V3 9,789)
    try:
        path = os.path.join(MODEL_PATH, 'labels.txt')
        with open(path, encoding='utf-8') as f:
            stale = sum(1 for _ in f) != len(get_model_labels())
    except OSError:
        stale = True
    if stale:
        try:
            set_label_file()
        except Exception as e:  # noqa: BLE001  any error here is logged and the station goes on
            log.warning('could not write model/labels.txt: %s', e)
    # a state species list needs the model's non-bird classes, which a V3 model fetched on first use
    # only brings now: complete it if it was built without them
    try:
        subprocess.run([sys.executable, os.path.join(core.BS_SCRIPTS, 'select_species_list.py'), '--if-needed'],
                       check=False, timeout=120)
    except Exception as e:  # noqa: BLE001  any error here is logged and the station goes on
        log.warning('could not check the species list: %s', e)
    conf = get_settings()
    i = inotify.adapters.Inotify()
    i.add_watch(os.path.join(conf['RECS_DIR'], 'StreamData'), mask=IN_CLOSE_WRITE)

    backlog = get_wav_files()

    report_queue = Queue()
    thread = threading.Thread(target=handle_reporting_queue, args=(report_queue, ))
    thread.start()

    try:
        log.info('backlog is %d', len(backlog))
        for file_name in backlog:
            process_file(file_name, report_queue)
            if shutdown:
                break
        log.info('backlog done')

        empty_count = 0
        quiet_said = False
        for event in i.event_gen():
            if shutdown:
                break

            if event is None:
                retry_no_space(report_queue)
                if empty_count > (conf.getint('RECORDING_LENGTH') * 2 + 30):
                    # recordings arrived without an event: the watch is dead (the RAM drive mounted again), a restart
                    # brings it back; none arrived: the recorder is quiet (stopped, no sound card), keep waiting
                    # (2026-11-06 deep review E2: the service restarted every minute, reloading the model, while the
                    # recording was stopped on purpose)
                    if get_wav_files():
                        log.error('no more notifications: restarting...')
                        break
                    if not quiet_said:
                        log.info('no recording for %d s: the recorder is quiet, waiting', empty_count)
                        quiet_said = True
                    empty_count = 0
                    continue
                empty_count += 1
                continue

            (_, type_names, path, file_name) = event
            if re.search('.wav$', file_name) is None:
                continue
            log.debug("PATH=[%s] FILENAME=[%s] EVENT_TYPES=%s", path, file_name, type_names)

            file_path = os.path.join(path, file_name)
            if file_path in backlog:
                # if we're very lucky, the first event could be for the file in the backlog that finished
                # while running get_wav_files()
                backlog = []
                continue

            retry_no_space(report_queue)
            process_file(file_path, report_queue)
            empty_count = 0
            quiet_said = False
    finally:
        # we're all done - always post the shutdown sentinel so the reporting
        # thread exits even when main() dies on an uncaught exception
        report_queue.put(None)
        thread.join()
        report_queue.join()


PRIVACY_STATE = os.path.join(core.BS_STATE, 'privacy.json')


def privacy_count(model_windows, shadow_windows, dropped):
    """The windows with a human voice the privacy filter blanked, per hour of the last 24 h (owner 2026-10-25: shown on
    System › Status), by the official model itself or through the shadow model."""
    if not (model_windows or shadow_windows):
        return
    import json
    import time
    now = int(time.time())
    try:
        with open(PRIVACY_STATE, encoding='utf-8') as f:
            st = json.load(f)
    except (OSError, ValueError):
        st = {}
    hours = {k: v for k, v in (st.get('hours') or {}).items() if now - int(k) < 86400}
    h = str(now - now % 3600)
    v = hours.get(h) or {'model': 0, 'shadow': 0, 'dropped': 0}
    v['model'] += model_windows
    v['shadow'] += shadow_windows
    v['dropped'] += dropped
    hours[h] = v
    try:
        os.makedirs(os.path.dirname(PRIVACY_STATE), exist_ok=True)
        with open(PRIVACY_STATE + '.tmp', 'w', encoding='utf-8') as f:
            json.dump({'hours': hours, 'last': now}, f)
        os.replace(PRIVACY_STATE + '.tmp', PRIVACY_STATE)
    except OSError as e:
        log.warning('privacy count not written: %s', e)


def process_file(file_name, report_queue):
    try:
        if os.path.getsize(file_name) == 0:
            os.remove(file_name)
            return
        log.info('Analyzing %s', file_name)
        with open(ANALYZING_NOW, 'w') as analyzing:
            analyzing.write(file_name)
        try:
            file = ParseFileName(file_name)
        except Exception as e:  # noqa: BLE001  a name that is not the recorder's: not a recording of the station
            raise UnreadableRecording(f'not a recording of the station: {os.path.basename(file_name)} ({e})') from e
        detections = run_analysis(file)
        try:
            # SHADOW_MODEL_NAME set: a second model analyses the same file into its own database.
            # It runs here because the reporting thread deletes the recording when it is done with it.
            run_shadow_analysis(file)
        except Exception as e:  # noqa: BLE001  any error here is logged and the station goes on
            log.error('Shadow analysis failed: %s', e)
        # privacy through the shadow model (owner 2026-10-25): its human windows drop the official detections there
        from utils import analysis as an
        dropped = 0
        if an.SHADOW_HUMAN_SLOTS:
            kept = [d for d in detections if not any(d.start < e and d.stop > s for s, e in an.SHADOW_HUMAN_SLOTS)]
            dropped = len(detections) - len(kept)
            if dropped:
                log.info('Privacy (shadow model): %d detection(s) dropped in %d human window(s)', dropped, len(an.SHADOW_HUMAN_SLOTS))
            detections = kept
        privacy_count(len(an.LAST_HUMAN_SLOTS), len(an.SHADOW_HUMAN_SLOTS), dropped)
        # we join() to make sure te reporting queue does not get behind
        if not report_queue.empty():
            log.warning('reporting queue not yet empty')
        report_queue.join()
        report_queue.put((file, detections))
    except UnreadableRecording as e:
        # kept aside as a recording whose report failed (2026-11-06 deep review E1): it failed again at every start, from
        # the backlog, and held its place on the RAM drive
        log.error('%s: moved to the quarantine', e)
        quarantine(file_name)
    except BaseException as e:
        if no_space(e) and os.path.exists(file_name):
            keep_for_retry(file_name)
            return
        stderr = e.stderr.decode('utf-8') if isinstance(e, CalledProcessError) else ""
        log.exception(f'Unexpected error: {stderr}', exc_info=e)


QUARANTINE_KEEP = 200


def quarantine(file_name):
    # a recording whose reporting failed is kept aside instead of staying in StreamData (where every restart would
    # analyse it again) or being lost; the newest QUARANTINE_KEEP files are kept, older ones are removed
    try:
        if not os.path.exists(file_name):
            return
        qdir = os.path.join(get_settings()['RECS_DIR'], 'quarantine')
        os.makedirs(qdir, exist_ok=True)
        # a move, not a rename: StreamData is the RAM drive, the quarantine is on the card (2026-11-04: "Invalid
        # cross-device link", the bad recording stayed in StreamData)
        shutil.move(file_name, os.path.join(qdir, os.path.basename(file_name)))
        log.warning('reporting failed: %s moved to %s', os.path.basename(file_name), qdir)
        kept = sorted((os.path.join(qdir, f) for f in os.listdir(qdir)), key=os.path.getmtime, reverse=True)
        for old in kept[QUARANTINE_KEEP:]:
            os.remove(old)
    except Exception as e:  # noqa: BLE001  any error here is logged and the station goes on
        log.error('could not quarantine %s: %s', file_name, e)


def handle_reporting_queue(queue):
    while True:
        msg = queue.get()
        # check for signal that we are done
        if msg is None:
            break

        file, detections = msg
        try:
            # a detection whose own seconds lost more audio than allowed is dropped before anything is written (owner
            # 2026-11-04, Station › Microphone: AUDIO_LOSS_DISCARD, AUDIO_LOSS_MAX_MS)
            conf_now = get_settings()
            kept = []
            for detection in detections:
                if discard_for_loss(file, detection, conf_now):
                    log.warning('dropped for lost audio (over %s ms): %s', conf_now.get('AUDIO_LOSS_MAX_MS'), summary(file, detection))
                else:
                    kept.append(detection)
            detections = kept
            update_json_file(file, detections)
            for detection in detections:
                detection.file_name_extr = extract_detection(file, detection)
                log.info('%s;%s', summary(file, detection), os.path.basename(detection.file_name_extr))
                write_to_file(file, detection)
                write_to_db(file, detection)
            apprise(file, detections)
            bird_weather(file, detections)
            sound_repo(file, detections)
            heartbeat()
            os.remove(file.file_name)
        except BaseException as e:
            stderr = e.stderr.decode('utf-8') if isinstance(e, CalledProcessError) else ""
            if shutdown:
                # stopped in the middle (a service restart, 2026-10-23: the recording landed in quarantine): it stays in
                # StreamData and the next start analyses it again
                log.warning('reporting interrupted by the shutdown: %s left for the next start', os.path.basename(file.file_name))
            elif no_space(e) or db_busy(e):
                # not a bad recording: the card is full (bug #64) or the database held by another writer (A1); the rows
                # already written are not repeated by the next try (write_to_db: WHERE NOT EXISTS)
                keep_for_retry(file.file_name, 'no space left' if no_space(e) else 'database busy')
            else:
                log.exception(f'Unexpected error: {stderr}', exc_info=e)
                quarantine(file.file_name)

        queue.task_done()

    # mark the 'None' signal as processed
    queue.task_done()
    log.info('handle_reporting_queue done')


def setup_logging():
    logger = logging.getLogger()
    formatter = logging.Formatter("[%(name)s][%(levelname)s] %(message)s")
    handler = logging.StreamHandler(stream=sys.stdout)
    handler.setFormatter(formatter)
    logger.addHandler(handler)
    logger.setLevel(logging.INFO)
    global log
    log = logging.getLogger(core.BS_SVC_ANALYSIS)


if __name__ == '__main__':
    signal.signal(signal.SIGINT, sig_handler)
    signal.signal(signal.SIGTERM, sig_handler)

    setup_logging()

    main()
