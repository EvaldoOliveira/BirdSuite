import argparse
import datetime
import logging
import os
import sys

from utils import core, notifications
from utils.db import get_latest
from utils.helpers import clip_rel, common_name, get_settings

if __name__ == "__main__":
    parser = argparse.ArgumentParser()
    parser.add_argument('--config', help='path to config', required=True)
    parser.add_argument('--title', help='title', required=True)
    parser.add_argument('--body', help='path to body template', required=True)
    args = parser.parse_args()

    conf = get_settings()
    conf['APPRISE_NOTIFICATION_TITLE'] = args.title
    # A test must exercise exactly the box that was clicked: neutralize the
    # rare-tier redirects so config/title/body all come from the test inputs.
    conf['APPRISE_NOTIFICATION_TITLE_RARE'] = ''
    conf['APPRISE_NOTIFY_EACH_DETECTION'] = '1'
    conf['APPRISE_NOTIFY_NEW_SPECIES_EACH_DAY'] = '0'
    conf['APPRISE_NOTIFY_NEW_SPECIES'] = '0'

    # the test confirms a channel: it is sent although the channel is not confirmed yet
    notifications.CHANNEL_GATE = False
    notifications.APPRISE_CONFIG = args.config
    notifications.APPRISE_CONFIG_RARE = args.config
    notifications.APPRISE_BODY = args.body
    notifications.APPRISE_BODY_RARE = args.body
    # A test exercises the clicked box's channels — tier policy must not gate it
    # (a muted latest detection made the test button silently do nothing).
    notifications.get_notification_tier = lambda sci_name: 'normal'

    logger = logging.getLogger()
    formatter = logging.Formatter("[%(name)s][%(levelname)s] %(message)s")
    handler = logging.StreamHandler(stream=sys.stdout)
    handler.setFormatter(formatter)
    logger.addHandler(handler)
    logger.setLevel(logging.INFO)

    d = get_latest()
    if not d:
        now = datetime.datetime.now()
        d = {'Sci_Name': 'Aptenodytes patagonicus', 'Confidence': 0.84, 'File_Name': 'this_is_not_a_file.mp3',
             'Date': now.strftime('%Y-%m-%d'), 'Time': now.strftime("%H:%M:%S"), 'Week': now.isocalendar()[1],
             'Lat': conf.getfloat('LATITUDE'), 'Lon': conf.getfloat('LONGITUDE'), 'Cutoff': conf.getfloat('CONFIDENCE'),
             'Sens': conf.getfloat('SENSITIVITY'), 'Overlap': conf.getfloat('OVERLAP')}

    # locate the detection's extracted clip so $audio works in tests too
    clip_path = os.path.join(core.BS_BY_DATE, clip_rel(d['Date'], d['Sci_Name'], d['File_Name']))
    if not os.path.isfile(clip_path):
        clip_path = ''
    # the common name is looked up from the scientific name (never stored)
    notifications.sendAppriseNotifications(d['Sci_Name'], common_name(d['Sci_Name']), d['Confidence'], round(d['Confidence'] * 100),
                                           d['File_Name'], d['Date'], d['Time'],
                                           d['Week'], d['Lat'], d['Lon'], d['Cutoff'], d['Sens'], d['Overlap'],
                                           file_path=clip_path)
