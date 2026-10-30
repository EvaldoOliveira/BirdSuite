#!/usr/bin/env python3
"""Send the notifications queued while the station was offline (utils/notify_queue.py).
Usage: notify_queue.py flush | notify_queue.py add TITLE BODY (queued, sent by the next flush once there is internet;
nothing is queued while no channel is configured)"""
import os
import sys

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
from utils.notify_queue import enqueue, flush  # noqa: E402
from utils.notifications import has_channels  # noqa: E402

if __name__ == '__main__' and sys.argv[1:] == ['flush']:
    print('sent', flush())
elif __name__ == '__main__' and len(sys.argv) == 4 and sys.argv[1] == 'add' and has_channels():
    enqueue(sys.argv[3], sys.argv[2])
