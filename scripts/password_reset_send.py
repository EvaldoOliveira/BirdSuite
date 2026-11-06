################################################################################
# scripts/password_reset_send.py - THE RESET LINK OF A FORGOTTEN WEB PASSWORD
#
# PURPOSE:
#   The reset link of a forgotten web password (US-53, owner 2026-11-04): sent only through the channel the
#   owner chose (PWD_RECOVERY: email or telegram), to the address or group already set up there, never queued
#   (the link lasts 15 minutes). Run as the station user by scripts/password_reset.php; the link comes on
#   stdin, never on the command line. Exit 0 = sent, 1 = the channel is not set up or the send failed.
#
# DEPENDENCIES:
#   - scripts/utils/helpers.py
#   - scripts/utils/i18n.py
#   - scripts/utils/notifications.py
#
# USAGE:
#   python3 scripts/password_reset_send.py <arguments> (see main)
#   Referenced by scripts/password_reset.php
################################################################################

"""The reset link of a forgotten web password (US-53, owner 2026-11-04): sent only through the channel the owner chose
(PWD_RECOVERY: email or telegram), to the address or group already set up there, never queued (the link lasts 15
minutes). Run as the station user by scripts/password_reset.php; the link comes on stdin, never on the command line.
Exit 0 = sent, 1 = the channel is not set up or the send failed."""
import sys

from utils import notifications
from utils.helpers import get_settings
from utils.i18n import t


def main():
    channel = (get_settings().get('PWD_RECOVERY') or '').strip().strip('"\'')
    if channel not in ('email', 'telegram'):
        return 1
    link = sys.stdin.read().strip()
    if not link:
        return 1
    # the confirmed channels of the normal box, only the chosen one
    _, chans = notifications._channels('normal')
    lines = chans.get(channel) or []
    apobj = notifications._build_apobj(lines)
    if apobj is None:
        return 1
    ok = apobj.notify(title=t('str_birdsuite_web_password_reset'), body=t('str_reset_link_body', link=link))
    return 0 if ok else 1


if __name__ == '__main__':
    sys.exit(main())
