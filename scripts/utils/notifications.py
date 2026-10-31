import apprise
import os
import re
import shutil
import tempfile
import unicodedata
import socket
import requests
import html
import time
import logging
import urllib.parse
import datetime

from .db import get_todays_count_for, get_this_weeks_count_for
from .helpers import get_settings
from .i18n import fmt_num, t
from . import locations
from . import notify_channels
from . import token_store
from .rarity import region_rare_reason

userDir = os.path.expanduser('~')
APPRISE_CONFIG = userDir + '/BirdNET-Pi/config/notifications/apprise.txt'
APPRISE_BODY = userDir + '/BirdNET-Pi/config/notifications/body.txt'
log = logging.getLogger(__name__)
NOTIFICATION_TIERS = userDir + '/BirdNET-Pi/config/species_lists/notification_tiers.txt'
APPRISE_CONFIG_RARE = userDir + '/BirdNET-Pi/config/notifications/apprise-rare.txt'
APPRISE_BODY_RARE = userDir + '/BirdNET-Pi/config/notifications/body-rare.txt'
APPRISE_TOKENS = userDir + '/BirdNET-Pi/config/notifications/apprise-tokens.txt'

apobjs = {}
# False only for a test send (send_test_notification.py): the test is what confirms a channel, so the gate below must
# not hold it back (2026-10-26: the e-mail test said sent while nothing left)
CHANNEL_GATE = True
images = {}
species_last_notified = {}



def _min_prob(sci_name, station):
    # the minimum probability in force for the species: the station's, or the species' own when higher
    # (species_min_prob.txt, owner 2026-10-22; the analysis drops a detection below either)
    try:
        station = float(station)
    except (TypeError, ValueError):
        station = 0.0
    try:
        with open(os.path.join(os.path.expanduser('~/BirdNET-Pi/config/species_lists'), 'species_min_prob.txt'), encoding='utf-8') as f:
            for line in f:
                name, _, value = line.strip().partition('=')
                if name == sci_name:
                    return max(station, float(value))
    except (OSError, ValueError):
        pass
    return station

def _has_config(path):
    return os.path.exists(path) and os.path.getsize(path) > 0


def has_channels(tier='normal'):
    # at least one channel that would send now: set up (no $name left unresolved) and confirmed by the user (2026-10-26);
    # none = nothing is sent nor queued
    return bool(_channels(tier)[1])


def _load_apprise_tokens():
    # Station-private token store (name=value per line, mode 600): the apprise
    # config boxes carry only $name placeholders, never the secrets themselves.
    tokens = {}
    # what Settings › Telegram / E-mail saved is put back when anything else changed it (owner 2026-10-31: perpetual)
    restored = token_store.heal()
    if restored:
        log.warning('notification secrets put back from the saved copy: %s', ', '.join(restored))
    try:
        with open(APPRISE_TOKENS) as f:
            for line in f:
                name, _, value = line.strip().partition('=')
                if name and value:
                    tokens[name] = value
    except OSError:
        pass
    return tokens


def _build_apobj(lines):
    # One Apprise object for a list of service URLs (already token-resolved);
    # None when the list is empty.
    if not lines:
        return None
    asset = apprise.AppriseAsset(
        plugin_paths=[
            userDir + "/.apprise/plugins",
            userDir + "/.config/apprise/plugins",
        ]
    )
    apobj = apprise.Apprise(asset=asset)
    config = apprise.AppriseConfig()
    config.add_config("\n".join(lines) + "\n", format='text')
    apobj.add(config)
    return apobj


def _channels(tier):
    """(stamp, {channel: [resolved service lines]}) of the box in force for the tier: a line whose $names are not all
    known (e-mail without a recipient, Telegram without its token) is not set up and left out, and so is a disabled
    channel (utils/notify_channels.py: sends only after the user confirmed a test; an error disables it) (2026-10-26)."""
    # Rare species use their own channel file when it is configured;
    # an empty/missing apprise-rare.txt falls back to the normal channels.
    config_path = APPRISE_CONFIG
    if tier == 'rare' and _has_config(APPRISE_CONFIG_RARE):
        config_path = APPRISE_CONFIG_RARE
    # rebuilt when the box, the tokens or the channel states change (the E-mail and Telegram pages rewrite them while this process runs)
    def mtime(path):
        try:
            return os.path.getmtime(path)
        except OSError:
            return 0
    # $email comes from BirdSuite.conf, so a new address rebuilds too (2026-10-20)
    email = (get_settings().get('NOTIFICATION_EMAIL') or '').strip()
    stamp = (config_path, mtime(config_path), mtime(APPRISE_TOKENS), mtime(notify_channels.STATE), email, CHANNEL_GATE)
    try:
        with open(config_path) as f:
            content = f.read()
    except OSError:
        content = ''
    tokens = _load_apprise_tokens()
    # one pass over the $names (2026-10-22): a whole name is replaced, so $token2 never clips $token20;
    # $email_recipient resolves from the settings (NOTIFICATION_EMAIL: an address is
    # configuration, not a secret), URL-encoded as it sits in a URL (user+tag@x or an & would change the query);
    # $user / $hostname keep e.g. from=$user@$hostname.local generic per station
    names = dict(tokens)
    if email:
        names['email_recipient'] = urllib.parse.quote(email, safe='@')
    names.setdefault('hostname', socket.gethostname())
    names.setdefault('user', os.path.basename(userDir))
    out = {}
    for ln in content.splitlines():
        if not ln.strip() or ln.lstrip().startswith('#'):
            continue
        ln = re.sub(r'\$([A-Za-z][A-Za-z0-9_]*)', lambda m: names.get(m.group(1), m.group(0)), ln.rstrip())
        ch = notify_channels.channel_of(ln)
        if re.search(r'\$[A-Za-z]', ln) or (CHANNEL_GATE and not notify_channels.enabled(ch)):
            continue
        out.setdefault(ch, []).append(ln)
    return stamp, out


def notify(body, title, attached="", tier='normal', queue=True):
    stamp, chans = _channels(tier)
    # nothing set up, or no channel confirmed by the user: nothing is sent and nothing is queued (owner 2026-10-26)
    if not chans:
        return
    # offline (no internet, e.g. in the field): kept and sent later by the field service (US-120, owner 2026-10-20)
    if queue:
        from .notify_queue import online, enqueue
        if not online():
            enqueue(body, title, attached, tier)
            return
    cached = apobjs.get(tier)
    objs = cached[1] if cached and cached[0] == stamp else None
    if objs is None:
        objs = {ch: _build_apobj(lines) for ch, lines in chans.items()}
        apobjs[tier] = (stamp, objs)

    # True when every service took it: apprise returns False on a failed send, it does not raise
    ok = True
    failed = []
    for ch, apobj in objs.items():
        if apobj is None:
            continue
        if ch == 'telegram':
            # Telegram is driven apart from the other services (e-mail, ...): with
            # attachments, apprise turns the text into the caption of the FIRST
            # attachment and the last message in the chat is a bare photo, so the
            # phone pop-up read "Photo" instead of the bird. Telegram therefore
            # gets the media first and the text as its own LAST message, whose
            # first line is the title (species + confidence). Owner 2026-09-30.
            # a media file that did not go (2026-10-26: a connection error on the clip while the text went out) does
            # not disable the channel: the notification reached the user; only the text decides
            if attached:
                apobj.notify(body='', title='', attach=attached)
            good = bool(apobj.notify(body=body, title=title))
        elif attached:
            good = bool(apobj.notify(body=body, title=title, attach=attached))
        else:
            good = bool(apobj.notify(body=body, title=title))
        if not good:
            failed.append(ch)
            ok = False
    if failed:
        from .notify_queue import online, enqueue
        if not online(fresh=True):
            # the connection went down after the online check (cached for a minute): kept for later
            if queue:
                enqueue(body, title, attached, tier)
        else:
            # a failure while online is a service or setting problem: that channel is disabled until the user tests
            # it on its page and confirms it arrived (owner 2026-10-26)
            for ch in failed:
                notify_channels.disable(ch, 'failed')
    return ok


def in_quiet_hours(tier, now=None):
    # APPRISE_QUIET_START_<TIER> / _END_<TIER> (HH:MM, Settings > Notifications): no notification of that tier inside
    # the window, which may cross midnight (22:00 - 06:00); empty or equal times = no quiet hours
    conf = get_settings()
    suffix = 'RARE' if tier == 'rare' else 'NORMAL'
    start = (conf.get(f'APPRISE_QUIET_START_{suffix}') or '').strip('" ')
    end = (conf.get(f'APPRISE_QUIET_END_{suffix}') or '').strip('" ')
    try:
        s_h, s_m = (int(x) for x in start.split(':'))
        e_h, e_m = (int(x) for x in end.split(':'))
    except ValueError:
        return False
    s_min, e_min = s_h * 60 + s_m, e_h * 60 + e_m
    if s_min == e_min:
        return False
    lt = now or time.localtime()
    cur = lt.tm_hour * 60 + lt.tm_min
    return s_min <= cur < e_min if s_min < e_min else (cur >= s_min or cur < e_min)


def tier_listed(sci_name):
    # True when the species has its own line in the tiers file (an explicit choice on Species Management)
    try:
        with open(NOTIFICATION_TIERS) as f:
            return any(line.strip().partition('=')[0] == sci_name for line in f)
    except OSError:
        return False


def get_notification_tier(sci_name):
    # Species tiers set on the Species Management page: one 'Sci_Name=tier' line
    # per non-normal species (muted/rare); a species not listed gets the
    # NOTIFICATION_DEFAULT_TIER setting (normal when absent/invalid).
    try:
        with open(NOTIFICATION_TIERS) as f:
            for line in f:
                name, _, tier = line.strip().partition('=')
                if name == sci_name:
                    return tier.lower() or 'normal'
    except OSError:
        pass
    default = (get_settings().get('NOTIFICATION_DEFAULT_TIER') or 'normal').lower()
    return default if default in ('muted', 'normal', 'rare') else 'normal'


def _location_name():
    """The name of the current location, as $location shows it (the built-in ones translated)."""
    loc = locations.current(get_settings())
    loc_name = ''
    if loc:
        builtin = {'home': 'Home', 'test': 'Test / Playback'}.get(loc.get('builtin', ''))
        loc_name = t(builtin) if builtin and loc.get('name') == builtin else str(loc.get('name', ''))
    return loc_name


def _station_name():
    """SITE_NAME, else BirdSuite-<board's MAC> as the header shows."""
    station_name = get_settings().get('SITE_NAME') or ''
    if not station_name:
        # the station's ID: its own network port's MAC, never a USB adapter's (as common.php station_mac())
        mac = ''
        names = sorted(os.listdir('/sys/class/net')) if os.path.isdir('/sys/class/net') else []
        for wireless in (False, True):
            for i in names:
                d = f'/sys/class/net/{i}'
                if mac or not os.path.isdir(f'{d}/device') or os.path.isdir(f'{d}/wireless') != wireless:
                    continue
                own = (os.path.basename(os.path.realpath(f'{d}/device/subsystem')) != 'usb'
                       or os.path.basename(os.path.realpath(f'{d}/device/driver')) in ('smsc95xx', 'lan78xx'))
                try:
                    with open(f'{d}/address') as f:
                        m = f.read().strip()
                except OSError:
                    continue
                if own and m and m != '00:00:00:00:00:00':
                    mac = m.replace(':', '').upper()
        station_name = 'BirdSuite' + ('-' + mac if mac else '')
    return station_name


def _pct(v, digits=0):
    try:
        return fmt_num(float(v) * 100, digits) + '%'  # the station locale's decimal separator (owner 2026-10-23)
    except (TypeError, ValueError):
        return '-'


def _manual_confidence(sci_name):
    """The species' own threshold from species_confidence.txt, else None."""
    manual = None
    try:
        with open(os.path.expanduser('~/BirdNET-Pi/config/species_lists/species_confidence.txt'), encoding='utf-8') as f:
            for line in f:
                s_name, _, value = line.strip().partition('=')
                if s_name == sci_name and value:
                    manual = float(value)
    except (OSError, ValueError):
        pass
    return manual


def _occurrence_probability(sci_name, date):
    """The location model's probability of the species in the date's week, else None."""
    prob = None
    try:
        from .rarity import get_profile
        d = datetime.datetime.strptime(str(date), '%Y-%m-%d')
        w48 = min(48, (d.month - 1) * 4 + min(4, (d.day - 1) // 7 + 1))  # the 48-week index of the location model
        series = ((get_profile() or {}).get('data') or {}).get(sci_name)
        prob = series[w48 - 1] if series else None
    except Exception:
        prob = None
    return prob


def _notification_tier(sci_name, com_name, date):
    """(tier, region-rare reason) of a detection that is to be notified, None when it is not."""
    tier = get_notification_tier(sci_name)
    # muted on purpose (own line in the tiers file) = never; muted only by NOTIFICATION_DEFAULT_TIER may still be
    # promoted by region-rare below — an unexpected species is exactly the one nobody listed
    if tier == 'muted' and tier_listed(sci_name):
        return None
    # region-rare (APPRISE_NOTIFY_REGION_RARE): a species the location model does not expect here — in no week
    # (vagrant) or not now (out of season) — goes to the Rare channel with that reason, once per Repetition limit
    region_reason = None
    if tier != 'rare' and get_settings().get('APPRISE_NOTIFY_REGION_RARE') == '1':
        try:
            region_reason = region_rare_reason(sci_name, datetime.date.fromisoformat(str(date)))
        except Exception as e:
            log.warning('region rarity not available: %s', e)
        if region_reason:
            if too_soon(com_name):
                return None
            tier = 'rare'
    if tier == 'muted':
        return None
    if in_quiet_hours(tier):
        log.info('quiet hours (%s): no notification for %s', tier, com_name)
        return None

    if tier == 'rare':
        # Rare species notify on every recording file with a detection (upstream
        # apprise() dedups per file), with the audio clip attached, bypassing
        # the name filters and the per-species rate limit.
        if not (_has_config(APPRISE_CONFIG) or _has_config(APPRISE_CONFIG_RARE)):
            return None
    elif not should_notify(com_name):
        return None
    return tier, region_reason


def _image_url(sci_name, com_name):
    """The species photo address from the station's image API (remembered per species)."""
    if com_name not in images:
        try:
            url = f"http://localhost/api/v1/image/{sci_name}"
            resp = requests.get(url=url, timeout=10).json()
            images[com_name] = resp['data']['image_url']
        except Exception as e:
            print("IMAGE API ERROR:", e)
    return images.get(com_name, "")


def _attach_audio(file_path, com_name, attachments, temp_attachments):
    # Attach a temp COPY named send-timestamp + species: the raw
    # extraction name carries colons, which mail clients mangle
    # (apprise's ?name= override proved unreliable). The copy is
    # removed in the finally block of sendAppriseNotifications.
    ext = os.path.splitext(file_path)[1] or '.flac'
    stamp = time.strftime('%Y-%m-%d_%Hh%Mm%Ss')
    # ASCII-only: apprise's email plugin RFC2047-encodes the whole
    # Content-Disposition header on non-ASCII names (accented species
    # like "Pica-pau-de-cabeça-amarela") and Gmail then shows "noname".
    safe_name = unicodedata.normalize('NFKD', com_name).encode('ascii', 'ignore').decode('ascii')
    safe_name = safe_name.replace(' ', '_').replace("'", '')
    named_copy = os.path.join(tempfile.gettempdir(), stamp + '_' + safe_name + ext)
    try:
        shutil.copy2(file_path, named_copy)
        temp_attachments.append(named_copy)
        attachments.append(named_copy)
    except OSError:
        attachments.append(file_path)


def _attach_photo(image_url, attachments, temp_attachments):
    # the photo downloaded here and attached as a file (2026-10-22): handed over as a link, Apprise encoded the
    # address again (%2C -> %252C) and Wikimedia answered 404, so the picture never came
    try:
        r = requests.get(image_url, timeout=15, stream=True, headers={'User-Agent': 'BirdSuite station (https://github.com/Nachtzuster/BirdNET-Pi)'})
        r.raise_for_status()
        kind = (r.headers.get('Content-Type') or '').split(';')[0].strip().lower()
        ext = {'image/jpeg': '.jpg', 'image/png': '.png', 'image/gif': '.gif', 'image/webp': '.webp'}.get(kind, '')
        if ext:
            fd, photo = tempfile.mkstemp(suffix=ext, prefix='birdsuite_photo_')
            size = 0
            with os.fdopen(fd, 'wb') as f:
                for chunk in r.iter_content(65536):
                    size += len(chunk)
                    if size > 10 * 1024 * 1024:  # a photo larger than 10 MB is left out
                        break
                    f.write(chunk)
            temp_attachments.append(photo)
            if size <= 10 * 1024 * 1024:
                attachments.append(photo)
    except Exception as e:
        log.warning('photo not attached (%s): %s', image_url, e)


def _normal_reasons(settings_dict, sci_name):
    """Every reason a Normal-tier detection is notified for (empty: not notified)."""
    reasons = []
    if settings_dict.get('APPRISE_NOTIFY_NEW_SPECIES_EACH_DAY') == "1" and get_todays_count_for(sci_name) == 1:
        reasons.append(t('first time today'))
    if settings_dict.get('APPRISE_NOTIFY_NEW_SPECIES') == "1":
        numberDetections = get_this_weeks_count_for(sci_name)
        if 0 < numberDetections <= 5:
            reasons.append(t('only seen {n} times in last 7d', n=numberDetections))
    if not reasons and settings_dict.get('APPRISE_NOTIFY_EACH_DETECTION') == "1":
        reasons.append(t('detection'))
    return reasons


def sendAppriseNotifications(sci_name, com_name, confidence, confidencepct, path, date, time_of_day, week, latitude, longitude,
                             cutoff, sens, overlap, file_path=""):
    # the location the detection was recorded at (owner 2026-10-21): $location in the texts; a text without the tag
    # gets a Location line under its first line, so every message names the place
    loc_name = _location_name()

    # the station's name (owner 2026-10-22): $station in the texts; SITE_NAME, else BirdSuite-<board's MAC> as the header shows
    station_name = _station_name()

    # the thresholds of this detection, written as on the detection lists (owner 2026-10-22): Min. Conf., Manual Conf.
    # (species_confidence.txt), Prob. (the location model's probability of occurrence this week), Min. Prob. (SF_THRESH),
    # the recording length
    conf_now = get_settings()
    pct = _pct
    manual = _manual_confidence(sci_name)
    prob = _occurrence_probability(sci_name, date)
    extra = {'conf_min_pct': pct(cutoff), 'conf_manual_pct': pct(manual) if manual is not None else t('none'),
             'occurrence_probability': pct(prob, 1) if prob is not None else '-',
             'occurrence_probability_min': pct(_min_prob(sci_name, conf_now.get('SF_THRESH'))),
             'recording_length': f"{conf_now.get('RECORDING_LENGTH') or '-'} s"}

    def render_template(template, reason="", title=False):
        if loc_name and not title and "$location" not in template and template.strip():
            first, sep, rest = template.partition("\n")
            template = first + "\n" + t('Location: {name}', name="$location") + (sep + rest if sep else "")
        # one pass over the $names (owner 2026-10-22, the names of the app's columns): a whole name is replaced, so a short
        # one never clips a longer one ($conf inside $conf_min); texts with the names before are converted by
        # scripts/migrate_notify_names.py at the update
        img = image_url if "{" in body else ""
        values = {
            'common_name': com_name, 'scientific_name': sci_name,
            'conf_pct': str(confidencepct), 'conf': str(confidence),
            'conf_min_pct': extra['conf_min_pct'], 'conf_min': str(cutoff),
            'conf_manual_pct': extra['conf_manual_pct'],
            'occurrence_probability': extra['occurrence_probability'],
            'occurrence_probability_min': extra['occurrence_probability_min'],
            'sigmoid_sensitivity': str(sens), 'overlap': str(overlap), 'recording_length': extra['recording_length'],
            'station': station_name, 'location': loc_name,
            'date': str(date), 'time': str(time_of_day), 'calendar_week': str(week),
            'latitude': str(latitude), 'longitude': str(longitude), 'reason': reason,
            'listen_url': listenurl, 'listen_link_markdown': friendlyurl,
            'image': img, 'audio': '',
        }
        ret = re.sub(r'\$([A-Za-z][A-Za-z0-9_]*)', lambda m: values.get(m.group(1), m.group(0)), template)
        return ret

    decided = _notification_tier(sci_name, com_name, date)
    if decided is None:
        return
    tier, region_reason = decided

    settings_dict = get_settings()
    title = html.unescape(settings_dict.get('APPRISE_NOTIFICATION_TITLE') or '')
    with open(APPRISE_BODY, 'r') as f:
        body = f.read()

    if tier == 'rare':
        # Rare species may carry their own title/body; empty falls back to Normal
        rare_title = settings_dict.get('APPRISE_NOTIFICATION_TITLE_RARE')
        if rare_title:
            title = html.unescape(rare_title)
        if _has_config(APPRISE_BODY_RARE):
            with open(APPRISE_BODY_RARE, 'r') as f:
                body = f.read()

    websiteurl = settings_dict.get('BIRDNETPI_URL')
    if websiteurl is None or len(websiteurl) == 0:
        # http unless HTTPS_ALWAYS=1 (US-126, owner 2026-10-29; https everywhere from 2026-10-25)
        websiteurl = f"{'https' if str(settings_dict.get('HTTPS_ALWAYS', '0')) == '1' else 'http'}://{socket.gethostname()}.local"

    listenurl = f"{websiteurl}?filename={path}"
    friendlyurl = f"[{t('Listen here')}]({listenurl})"

    image_url = _image_url(sci_name, com_name) if "$image" in body else ""

    # The $audio tag in the body template is the ONLY thing that attaches the
    # detection clip — identical behavior on every tier (owner rule 2026-09-02).
    attach_audio = "$audio" in body
    temp_attachments = []

    def build_attachments():
        attachments = []
        if attach_audio and file_path and os.path.isfile(file_path):
            _attach_audio(file_path, com_name, attachments, temp_attachments)
        if image_url:
            _attach_photo(image_url, attachments, temp_attachments)
        return attachments

    try:
        if tier == 'rare':
            reason = region_reason or t('rare species')
            notify_body = render_template(body, reason)
            notify_title = render_template(title, reason, title=True)
            notify(notify_body, notify_title, build_attachments(), tier='rare')
            species_last_notified[com_name] = int(time.time())
            return

        # one message per detection with every reason that applies (owner 2026-10-22: with "each detection" on, the
        # first of the day and the infrequent ones came two or three times)
        reasons = _normal_reasons(settings_dict, sci_name)
        if reasons:
            reason = ', '.join(reasons)
            notify_body = render_template(body, reason)
            notify_title = render_template(title, reason, title=True)
            notify(notify_body, notify_title, build_attachments())
            species_last_notified[com_name] = int(time.time())

    finally:
        for tmp in temp_attachments:
            try:
                os.remove(tmp)
            except OSError:
                pass

def too_soon(com_name):
    # the Repetition limit (APPRISE_MIN_SEC_PER_SPECIES) on its own
    limit = get_settings().get('APPRISE_MIN_SEC_PER_SPECIES') or "0"
    try:
        return limit != "0" and com_name in species_last_notified and int(time.time()) - species_last_notified[com_name] < int(limit)
    except ValueError:
        return False


def should_notify(com_name):
    settings_dict = get_settings()
    if not (os.path.exists(APPRISE_CONFIG) and os.path.getsize(APPRISE_CONFIG) > 0):
        return False

    # check if this is an excluded species
    APPRISE_EXCLUDE_SPECIES = settings_dict.get('APPRISE_EXCLUDE_SPECIES')
    if APPRISE_EXCLUDE_SPECIES is not None and APPRISE_EXCLUDE_SPECIES.strip() != "":
        excluded_species = [bird.lower().replace(" ", "") for bird in APPRISE_EXCLUDE_SPECIES.split(",")]
        if com_name.lower().replace(" ", "") in excluded_species:
            return False

    # check if this is an included species
    APPRISE_ONLY_SPECIES = settings_dict.get('APPRISE_ONLY_SPECIES')
    if APPRISE_ONLY_SPECIES is not None and APPRISE_ONLY_SPECIES.strip() != "":
        included_species = [bird.lower().replace(" ", "") for bird in APPRISE_ONLY_SPECIES.split(",")]
        if com_name.lower().replace(" ", "") not in included_species:
            return False

    # is it still too soon?
    APPRISE_MIN_SEC_PER_SPECIES = settings_dict.get('APPRISE_MIN_SEC_PER_SPECIES')
    if APPRISE_MIN_SEC_PER_SPECIES != "0":
        if species_last_notified.get(com_name) is not None:
            try:
                if int(time.time()) - species_last_notified[com_name] < int(APPRISE_MIN_SEC_PER_SPECIES):
                    return False
            except Exception as e:
                print("APPRISE NOTIFICATION EXCEPTION: " + str(e))
                return False

    return True


if __name__ == "__main__":
    print("notfications")
