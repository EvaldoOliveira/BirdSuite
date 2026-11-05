#!/usr/bin/env python3
"""Field service (US-120, owner 2026-10-20) — the field service, as root. Decides when the station's own WiFi is on:

- With the cable connected the WiFi stays off (owner 2026-10-25: two addresses on one network, the site stalled on the
  WiFi one); the cable gone, the WiFi joins a known network or the fleet phone hotspot in range at once (a scan
  before the station WiFi may start).
- With no network (no cable, known WiFi or fleet hotspot, LOST_WAIT seconds after it is gone and not before BOOT_WAIT
  after start-up) the station WiFi turns on as FIELD_WIFI_MODE says (owner 2026-10-25):
  always (default): on, with no time limit;
  cycle: on for AP_WINDOW minutes every CYCLE minutes (2 every 15 by default), the station is found in the field
  within CYCLE minutes;
  off: only for AP_WINDOW minutes after start-up; then the WiFi antenna is turned off (no station WiFi, no known
  network, no phone hotspot; the cable still works) until the next start-up.
  A phone on it keeps it on: it goes off IDLE_OFF after the last phone left. While it is on and no phone uses it, the
  station looks for its known networks and the fleet hotspot every SCAN_EVERY seconds and goes back to one in range
  (the chip scans in AP mode); a network that failed is tried again after RETRY_FIRST seconds, doubling, then on the
  round RETRY_SSID of the clock (14:10, 14:20). While the first-run setup is pending (firstrun_pending) it stays on
  until the setup is done.
- Turned on by Bluetooth or the Network page, it turns itself off after IDLE_OFF with no phone connected.
- The mode and the times are settings (FIELD_WIFI_MODE, FIELD_BOOT_WAIT_SEC, FIELD_LOST_WAIT_SEC, FIELD_AP_WINDOW_MIN,
  FIELD_CYCLE_MIN, FIELD_AP_IDLE_MIN in config/factory_defaults.conf, Settings › Defaults), read on every round.
- The time counts as confirmed once NTP synchronised it (or the phone set it: scripts/field_net.py time).
- The fixed mDNS name birdsuite-<the whole MAC>.local is published (avahi), whatever the hostname.
- /run/birdsuite/field_state.json carries the state for the Bluetooth service and the pages: addresses, network, station
  WiFi, time confirmed, recording.
- A new address of the station on a network (the Android hotspot picks a new network each time) is announced by a
  notification with its link, sent once there is internet (owner 2026-10-25); the same address again is not.
- Queued notifications (scripts/utils/notify_queue.py) go out once there is internet (notify_queue.py flush, as the user).
"""
import contextlib
import json
import os
import re
import signal
import socket
import subprocess
import sys
import time
import types
from pathlib import Path

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
import field_net
from utils import core

# seconds; the settings of BirdSuite.conf replace them on every round (times())
BOOT_WAIT = 60
BOOT_WINDOW = 2 * 60
LOST_WAIT = 10
ROUND = 5  # seconds between rounds (owner 2026-10-25: the station WiFi 10 s after the network is gone, was 20 s rounds)
ONLINE_EVERY = 30  # the internet check (up to 9 s offline) only every so often
CYCLE = 15 * 60
IDLE_OFF = 30 * 60
SCAN_EVERY = 60
RETRY_FIRST = 2 * 60
RETRY_SSID = 10 * 60
AUTO = ('boot', 'lost', 'auto')  # the reasons the service itself turned the station WiFi on for (boot, lost: before)
STATE = os.path.join(field_net.STATE_DIR, 'field_state.json')
AP_REASON = os.path.join(field_net.STATE_DIR, 'ap_reason')


def home():
    """The station user's home (core.env: the folder above the program folder)."""
    return core.BS_HOME


def setting(key, default):
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


def times():
    for name, key, unit in (('BOOT_WAIT', 'FIELD_BOOT_WAIT_SEC', 1), ('LOST_WAIT', 'FIELD_LOST_WAIT_SEC', 1),
                            ('BOOT_WINDOW', 'FIELD_AP_WINDOW_MIN', 60), ('CYCLE', 'FIELD_CYCLE_MIN', 60),
                            ('IDLE_OFF', 'FIELD_AP_IDLE_MIN', 60)):
        with contextlib.suppress(ValueError):
            globals()[name] = max(5, int(float(setting(key, globals()[name] // unit)) * unit))


def wifi_mode():
    """always, cycle or off; a station of the first days with FIELD_LOW_ENERGY=1 runs the cycle."""
    mode = setting('FIELD_WIFI_MODE', '').strip().lower()
    if mode in ('always', 'cycle', 'off'):
        return mode
    return 'cycle' if setting('FIELD_LOW_ENERGY', '0').strip() == '1' else 'always'


def cable_rule(st, reason, held):
    """Cable connected: the WiFi off (and the station WiFi opened at boot or on a lost network); cable gone: the WiFi
    joins its known networks or the fleet hotspot again. Returns whether the WiFi is held off and whether it acted."""
    dev = st['wifi_device']
    cable = any(a['type'] == '802-3-ethernet' and a.get('state') == 'activated' for a in st['active'])
    acted = False
    if not dev:
        return held, acted
    if cable:
        if any(a['type'] == '802-11-wireless' and a['name'] != field_net.AP for a in st['active']):
            field_net.sh('nmcli', 'device', 'disconnect', dev, check=False)  # also stops the device's autoconnect
            print('cable connected: WiFi off', flush=True)
            acted = True
        if st['ap_on'] and reason in AUTO:
            field_net.ap_off()
            with contextlib.suppress(OSError):
                os.remove(AP_REASON)
            print('cable connected: station WiFi off', flush=True)
            acted = True
        return True, acted
    if held is not False and not any(a['device'] == dev for a in st['active']):
        # the device's autoconnect on again: NetworkManager starts the best known network (never the station WiFi, whose
        # profile does not autoconnect; "device connect" could pick it)
        field_net.sh('nmcli', 'device', 'set', dev, 'autoconnect', 'yes', check=False)
        if held:
            print('cable gone: WiFi on', flush=True)
    return False, acted


def scheme():
    """The scheme of the station's links (US-126): http unless HTTPS_ALWAYS=1."""
    return 'https' if setting('HTTPS_ALWAYS', '0') == '1' else 'http'


def https_temp_check():
    """The https side switched on for a moment to read a device's GPS (scripts/https_temp.php) goes off by itself when its
    time is over, if the page did not switch it off (the window closed, the phone left)."""
    path = core.BS_HTTPS_TEMP
    try:
        until = int(Path(path).read_text().strip() or 0)
    except (OSError, ValueError):
        return
    if until > time.time():
        return
    os.remove(path)
    cmd = [core.BS_ROOT_HELPER, 'caddyfile']
    subprocess.run(cmd if os.geteuid() == 0 else ['sudo', '-n', *cmd], capture_output=True, timeout=60, check=False)
    print('https for the GPS: time over, off', flush=True)


def announce(st, lan):
    """A notification with the link when the station's network address changed (queued, sent by the flush below)."""
    if not lan:
        return
    path = core.BS_ANNOUNCED
    try:
        if Path(path).read_text().strip() == lan[0]:
            return
    except OSError:
        pass
    net = next((a['name'] for a in st['active'] if a['name'] != field_net.AP and a['type'] != 'loopback'), '')
    ssid = st['fleet_ssid'] if net == field_net.FLEET else st['known_ssid'].get(net, net)
    station = setting('SITE_NAME', '') or field_net.ap_ssid()
    user = os.path.basename(home())
    prefix = ['runuser', '-u', user, '--'] if os.geteuid() == 0 else []
    r = subprocess.run([*prefix, core.BS_PYTHON,
                       os.path.join(core.BS_SCRIPTS, 'notify_queue.py'), 'add',
                       field_net.t('str_new_address', station=station),
                       # the name first: it follows the station to any network; the address for a device without names (2026-10-26)
                       field_net.t('str_open_the_station_or_network', name=scheme() + '://' + field_net.mdns_name() + '/',
                                   url=scheme() + '://' + lan[0] + '/', net=ssid or '?')],
                       capture_output=True, timeout=60, check=False)
    if r.returncode == 0:
        Path(path).write_text(lan[0])
        print(f'new address {lan[0]} announced', flush=True)


def back_home(st, tried):
    """Station WiFi on (by the service) and no phone on it: when a known network or the fleet hotspot is in range, the
    station WiFi goes off and the station joins it. tried: SSID -> (when it may be tried again, the last
    wait); a network that refused (blocked, wrong password) waits RETRY_FIRST, doubling (2, 4, 8 min), then is tried on the
    round RETRY_SSID of the local clock (14:20, 14:30), and a failed
    attempt is aborted at once so the station WiFi comes back in LOST_WAIT. Returns True when it switched."""
    wanted = set(v for v in st['known_ssid'].values() if v)
    if st['fleet_ssid']:
        wanted.add(st['fleet_ssid'])
    if not wanted or st['ap_clients'] or not st['wifi_device']:
        return False
    field_net.sh('nmcli', 'device', 'wifi', 'rescan', check=False)
    time.sleep(5)
    seen = set()
    for line in field_net.sh('nmcli', '-t', '-f', 'SSID,SIGNAL', 'device', 'wifi', 'list', check=False).splitlines():
        ssid, _, signal = line.rpartition(':')
        if signal.isdigit() and int(signal) >= 25:
            seen.add(ssid.replace('\\:', ':'))
    now = time.time()
    found = sorted(x for x in wanted & seen if now >= tried.get(x, (0, 0))[0])
    if not found:
        return False
    field_net.ap_off()
    with contextlib.suppress(OSError):
        os.remove(AP_REASON)
    # the profile of the network found, by name: "device connect" picked the station WiFi again (2026-10-25 test)
    names = {v: k for k, v in st['known_ssid'].items()}
    if st['fleet_ssid']:
        names.setdefault(st['fleet_ssid'], field_net.FLEET)
    for x in found:
        r = subprocess.run(['nmcli', '-w', '30', 'connection', 'up', names.get(x, x), 'ifname', st['wifi_device']], capture_output=True, text=True, check=False)
        print('known network in range ({}): station WiFi off, {}'.format(x, 'joined' if r.returncode == 0 else 'not joined'), flush=True)
        if r.returncode == 0:
            tried.pop(x, None)
            break
        # NetworkManager would keep trying it for over a minute with the station WiFi off
        field_net.sh('nmcli', 'connection', 'down', names.get(x, x), check=False)
        wait = min(RETRY_SSID, max(RETRY_FIRST, tried.get(x, (0, 0))[1] * 2))
        now = time.time()
        if wait < RETRY_SSID:
            tried[x] = (now + wait, wait)
        else:
            local = now + time.localtime(now).tm_gmtoff
            tried[x] = (now + RETRY_SSID - local % RETRY_SSID, wait)
    return True


def uptime():
    return float(open('/proc/uptime').read().split()[0])


def online():
    for host, port in (('1.1.1.1', 53), ('8.8.8.8', 53), ('api.telegram.org', 443)):
        try:
            socket.create_connection((host, port), timeout=3).close()
            return True
        except OSError:
            continue
    return False


def write_state(st, extra):
    conf = {}
    try:
        for line in Path(core.BS_CONF).read_text(encoding='utf-8').splitlines(keepends=True):
            k, sep, v = line.strip().partition('=')
            if sep and not k.startswith('#'):
                conf[k] = v.strip().strip('"')
    except OSError:
        pass
    rec = subprocess.run(['systemctl', 'is-active', core.BS_SVC_RECORDING + '.service'], capture_output=True, text=True, check=False).stdout.strip() == 'active'
    data = dict(st, **extra)
    data.update({'station': conf.get('SITE_NAME') or field_net.ap_ssid(),
                 'lat': conf.get('LATITUDE'), 'lon': conf.get('LONGITUDE'), 'recording': rec, 'updated': int(time.time())})
    try:
        locs = json.loads(Path(core.BS_LOCATIONS_FILE).read_text(encoding='utf-8'))
        lat, lon = float(conf.get('LATITUDE', 0)), float(conf.get('LONGITUDE', 0))
        here = [loc for loc in locs if abs(loc['lat'] - lat) < 0.05 and abs(loc['lon'] - lon) < 0.05]
        data['location'] = here[0]['name'] if here else ''
    except (OSError, ValueError, KeyError):
        data['location'] = ''
    os.makedirs(field_net.STATE_DIR, exist_ok=True)
    with open(STATE + '.tmp', 'w', encoding='utf-8') as f:
        json.dump(data, f, ensure_ascii=False)
    os.replace(STATE + '.tmp', STATE)
    os.chmod(STATE, 0o644)


class Alias:
    """avahi-publish of the fixed name, restarted when the address changes"""
    def __init__(self):
        self.proc, self.ip = None, None

    def keep(self, name, ip):
        if ip == self.ip and self.proc and self.proc.poll() is None:
            return
        if self.proc and self.proc.poll() is None:
            self.proc.terminate()
        self.proc, self.ip = None, ip
        if ip:
            try:
                self.proc = subprocess.Popen(['avahi-publish', '-a', '-R', name, ip], stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
            except OSError:
                self.proc = None


def adopt_board():
    """A cloned SD card carries the profiles of the board it was prepared on: the station WiFi name and the fleet hotspot
    extra address follow THIS board's MAC, and the generic hostname 'birdsuite' becomes birdsuite-<the whole MAC>."""
    try:
        p = field_net.profiles()
        if field_net.AP in p:
            ssid = field_net.sh('nmcli', '-g', '802-11-wireless.ssid', 'connection', 'show', field_net.AP, check=False).strip()
            if ssid != field_net.ap_ssid():
                field_net.sh('nmcli', 'connection', 'modify', field_net.AP, '802-11-wireless.ssid', field_net.ap_ssid())
        if field_net.FLEET in p:
            addr = field_net.sh('nmcli', '-g', 'ipv4.addresses', 'connection', 'show', field_net.FLEET, check=False).strip()
            if addr != field_net.fleet_extra_ip() + '/28':
                field_net.sh('nmcli', 'connection', 'modify', field_net.FLEET, 'ipv4.addresses', field_net.fleet_extra_ip() + '/28')
        if socket.gethostname() in ('birdsuite', 'birdnetpi', 'raspberrypi'):
            name = field_net.mdns_name()[:-len('.local')]
            field_net.sh('hostnamectl', 'set-hostname', name, check=False)
            field_net.sh('sed', '-i', 's/\\b\\(birdsuite\\|birdnetpi\\|raspberrypi\\)\\b/' + name + '/g', '/etc/hosts', check=False)
    except Exception as e:  # noqa: BLE001  any error here is logged and the station goes on
        print('adopt board:', e, flush=True)


def ap_idle_check(st, reason, firstrun, last_client, mode):
    """The station WiFi goes off after its time without a phone; returns when a phone was last seen. In the always mode
    the station WiFi the service turned on has no time limit (it goes off when a known network is back, back_home())."""
    if st['ap_clients']:
        last_client = time.time()
    since_file = os.path.join(field_net.STATE_DIR, 'ap_since')
    since = float(Path(since_file).read_text()) if os.path.exists(since_file) else time.time()
    limit = None
    if (reason == 'setup' and os.path.exists(firstrun)) or (reason in AUTO and mode == 'always'):
        limit = None
    elif reason in AUTO:
        limit = BOOT_WINDOW if not st['ap_clients'] and last_client <= since + 5 else IDLE_OFF
    else:
        limit = IDLE_OFF
    idle = time.time() - max(last_client, since)
    if limit is not None and not st['ap_clients'] and idle >= limit:
        field_net.ap_off()
        with contextlib.suppress(OSError):
            os.remove(AP_REASON)
        print(f'station WiFi off after {int(idle // 60)} min without a phone', flush=True)
    return last_client


def ap_reason():
    return Path(AP_REASON).read_text().strip() if os.path.exists(AP_REASON) else ''


def antenna_rule(st, mode):
    """The antenna: off in the off mode once its window after start-up is over and no phone is on the station WiFi
    (no station WiFi, no known network, no phone hotspot); on again in the other modes, and in the off mode during its
    window after start-up (owner 2026-10-31: the antenna stayed off from the last run, NetworkManager keeps it off
    across a restart, so the window never opened and the cable gone left the station with no network at all)."""
    in_window = uptime() <= BOOT_WAIT + BOOT_WINDOW
    if mode == 'off' and not in_window and not st['ap_on'] and st['wifi_radio'] == 'enabled':
        field_net.sh('nmcli', 'radio', 'wifi', 'off', check=False)
        st['wifi_radio'] = 'disabled'
        print('off mode: WiFi antenna off until the next start-up', flush=True)
    elif (mode != 'off' or in_window) and st['wifi_device'] and st['wifi_radio'] == 'disabled':
        field_net.sh('nmcli', 'radio', 'wifi', 'on', check=False)
        print(f'WiFi antenna on ({mode} mode)', flush=True)


def no_network_rule(st, real, mode, now, loop, reason, firstrun):
    """No network: the station WiFi, as the mode says. Returns the reason in force (the one the WiFi was opened for)."""
    if real or st['ap_on'] or st['wifi_radio'] != 'enabled' or not st['ap_profile']:
        loop.no_net_since = None
        return reason
    loop.no_net_since = loop.no_net_since or now
    allowed = {'always': True, 'cycle': loop.cycle_start is None or now - loop.cycle_start >= CYCLE,
               'off': not loop.opened and uptime() <= BOOT_WAIT + BOOT_WINDOW}[mode]
    if allowed and uptime() >= BOOT_WAIT and now - loop.no_net_since >= LOST_WAIT:
        try:
            reason = 'setup' if os.path.exists(firstrun) else 'auto'
            field_net.ap_on(reason)
            loop.last_client = loop.last_scan = loop.cycle_start = now
            loop.opened = True
            window = '' if mode == 'always' else f', {int(BOOT_WINDOW // 60)} min'
            print(f'no network: station WiFi on ({mode} mode{window})', flush=True)
        except SystemExit as e:
            print('station WiFi not started:', e, flush=True)
        loop.no_net_since = None
    return reason


def round_tasks(st, loop, alias, alias_name, reason):
    """The rest of a round: the time confirmed once NTP synchronised it, the published name, the address notice, the
    internet check, the state file, the temporary https, the queued notifications sent once online."""
    if not os.path.exists(field_net.TIME_FLAG) and field_net.ntp_synced():
        Path(field_net.TIME_FLAG).write_text(str(int(time.time())))
    lan = [i['ip'] for i in st['ips'] if not i['ip'].startswith('10.42.')]
    alias.keep(alias_name, lan[0] if lan else (field_net.ap_addr() if st['ap_on'] else None))
    try:
        announce(st, lan)
    except Exception as e:  # noqa: BLE001  never in the way of the network
        print('announce:', e, flush=True)
    if time.time() - loop.online_at >= ONLINE_EVERY:
        loop.is_online, loop.online_at = online(), time.time()
    write_state(st, {'online': loop.is_online, 'mdns': alias_name, 'ap_reason': reason})
    # the microphone, its port and its gain for the detections (state/microphone.json, owner 2026-11-04): once a minute
    if time.time() - getattr(loop, 'mic_at', 0) >= 60:
        loop.mic_at = time.time()
        user = os.path.basename(home())
        prefix = ['runuser', '-u', user, '--'] if os.geteuid() == 0 else []
        try:
            subprocess.run([*prefix, os.path.join(core.BS_SCRIPTS, 'mic_state.sh')], capture_output=True, timeout=30, check=False)
        except (OSError, subprocess.SubprocessError) as e:
            print('mic_state:', e, flush=True)
    try:
        https_temp_check()
    except Exception as e:  # noqa: BLE001  never in the way of the network
        print('https_temp:', e, flush=True)
    if loop.is_online and os.path.exists(core.BS_NOTIFY_QUEUE):
        user = os.path.basename(home())
        # as the station user: this service is that user on a least-privilege install, root on an older one
        prefix = ['runuser', '-u', user, '--'] if os.geteuid() == 0 else []
        subprocess.run([*prefix, core.BS_PYTHON, os.path.join(core.BS_SCRIPTS, 'notify_queue.py'), 'flush'], capture_output=True, timeout=300, check=False)


def main():
    os.makedirs(field_net.STATE_DIR, exist_ok=True)
    adopt_board()
    firstrun = core.BS_FIRSTRUN
    alias = Alias()

    def stop(signum, frame):  # the published name goes with the service
        if alias.proc and alias.proc.poll() is None:
            alias.proc.terminate()
        sys.exit(0)
    signal.signal(signal.SIGTERM, stop)
    signal.signal(signal.SIGINT, stop)
    alias_name = field_net.mdns_name()
    times()
    # the state carried from round to round
    loop = types.SimpleNamespace(
        opened=uptime() > BOOT_WAIT + BOOT_WINDOW,  # off mode: its one window after start-up (not again on a restart)
        cycle_start=None, last_client=time.time(), no_net_since=None, last_scan=0, online_at=0, is_online=False)
    held = None
    tried = {}
    while True:
        times()
        mode = wifi_mode()
        try:
            st = field_net.status()
        except Exception as e:  # noqa: BLE001  NetworkManager restarting: try again
            print('status:', e, flush=True)
            time.sleep(20)
            continue
        reason = ap_reason()
        was_held = held
        held, acted = cable_rule(st, reason, held)
        if was_held and held is False and back_home(st, tried):
            # cable gone: the known networks in range first, the station WiFi only when none of them takes the station
            # (owner 2026-10-26: it opened its own WiFi and joined Tangara a minute later, on the next scan)
            acted = True
        if acted:
            st = field_net.status()
            reason = ap_reason()
        # a network only once connected: one NetworkManager is still trying (activating) is no network (owner 2026-10-25:
        # with the WiFi blocked in the router the password was refused again and again, and the station WiFi never came)
        real = [a for a in st['active'] if a['name'] != field_net.AP and a.get('state') == 'activated'
                and a['type'] in ('802-3-ethernet', '802-11-wireless', 'gsm', 'bluetooth')]
        now = time.time()
        if real:
            loop.cycle_start = None
        antenna_rule(st, mode)
        reason = no_network_rule(st, real, mode, now, loop, reason, firstrun)
        # station WiFi on by the service, no phone on it: back to a known network in range
        if st['ap_on'] and reason in AUTO and now - loop.last_scan >= SCAN_EVERY:
            loop.last_scan = now
            if back_home(st, tried):
                st = field_net.status()
                reason = ''
        if st['ap_on']:
            loop.last_client = ap_idle_check(st, reason, firstrun, loop.last_client, mode)
        round_tasks(st, loop, alias, alias_name, reason)
        time.sleep(ROUND)

if __name__ == '__main__':
    main()
