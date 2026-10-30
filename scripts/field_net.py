#!/usr/bin/env python3
"""Field network helper (US-120, owner 2026-10-20) — run as root (the web pages through sudo, the field and Bluetooth
services directly). NetworkManager profiles managed here:

  birdsuite-fleet   the phone hotspot every station of the fleet knows: SSID + password set at image preparation or in
                    Settings › Network; the LOWEST autoconnect priority (any real network wins); DHCP plus a fixed extra
                    address 172.20.10.<n>/28 taken from the MAC, so on an iPhone hotspot (always 172.20.10.x) the station
                    is at a known address (shown on the Network page)
  birdsuite-ap      the station's own WiFi "BirdSuite-<the whole MAC>", WPA2, 10.42.N.1 with N from the MAC (one address
                    per station, owner 2026-10-25; NetworkManager shared mode with dnsmasq: DHCP for the phone, every
                    name answered with the station = captive setup page); never
                    started by NetworkManager itself, only on demand (Bluetooth, the field service, the Network page)

Usage (JSON on stdout; passwords are read from stdin and never printed, except `ap-psk` for the label page):
  field_net.py status
  field_net.py set-fleet <ssid>          (password on stdin)
  field_net.py set-ap                    (password on stdin)
  field_net.py ap-on [reason] | ap-off
  field_net.py add-wifi <ssid>           (password on stdin; a known network for this place)
  field_net.py edit-wifi <profile> <ssid>  (password on stdin, empty = keep it; renames the profile to the new name)
  field_net.py forget-wifi <profile>     a known network (or the phone hotspot) removed
  field_net.py time <epoch seconds>      set the clock from the phone; the time counts as confirmed
  field_net.py ap-psk                    the station WiFi password and the label's addresses (label page only)
  field_net.py fleet-psk                 the saved phone hotspot's password (Network › Plan B, on request)
  field_net.py wifi-psk NAME             a known network's password (Network › 1, on request)
  field_net.py identify                  blink the activity LED for 5 minutes (which station is this, with several in range)
"""
import glob
import json
import os
import re
import shutil
import subprocess
import sys
import time

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
try:
    from utils.i18n import t  # the page's language (US-122)
except Exception:  # never in the way of the network: the messages stay in English
    def t(text, **values):
        for k, v in values.items():
            text = text.replace('{' + k + '}', str(v))
        return text

FLEET = 'birdsuite-fleet'
AP = 'birdsuite-ap'
AP_ADDR = '10.42.0.1'  # before 2026-10-25 every station had this one; ap_addr() is the station's own
STATE_DIR = '/run/birdsuite'
TIME_FLAG = os.path.join(STATE_DIR, 'time_confirmed')
CAPTIVE_CONF = '/etc/NetworkManager/dnsmasq-shared.d/birdsuite-captive.conf'


def sh(*args, input_text=None, check=True):
    r = subprocess.run(list(args), input=input_text, capture_output=True, text=True)
    if check and r.returncode != 0:
        raise RuntimeError((r.stderr or r.stdout).strip()[:300] or '%s failed (exit %d)' % (args[0], r.returncode))
    return r.stdout


def mac():
    """The station's ID: the MAC of the Pi's own network port, else of its own WiFi, never of a USB adapter (as
    common.php station_mac(): own = on the board's bus, or the LAN chip of a Pi 3 / 2 / B+ on its internal USB hub)."""
    names = sorted(os.listdir('/sys/class/net')) if os.path.isdir('/sys/class/net') else []
    for wireless in (False, True):
        for i in names:
            d = f'/sys/class/net/{i}'
            if not os.path.isdir(f'{d}/device') or os.path.isdir(f'{d}/wireless') != wireless:
                continue
            own = (os.path.basename(os.path.realpath(f'{d}/device/subsystem')) != 'usb'
                   or os.path.basename(os.path.realpath(f'{d}/device/driver')) in ('smsc95xx', 'lan78xx'))
            try:
                m = open(f'{d}/address').read().strip().lower()
            except OSError:
                continue
            if own and re.fullmatch(r'([0-9a-f]{2}:){5}[0-9a-f]{2}', m) and m != '00:00:00:00:00:00':
                return m
    return ''


def wifi_mac():
    """The MAC of the WiFi interface (the one with a wireless/ directory), '' without WiFi."""
    for d in sorted(glob.glob('/sys/class/net/*/wireless')):
        try:
            m = open(os.path.join(os.path.dirname(d), 'address')).read().strip().lower()
        except OSError:
            continue
        if re.fullmatch(r'([0-9a-f]{2}:){5}[0-9a-f]{2}', m) and m != '00:00:00:00:00:00':
            return m
    return ''


# the whole MAC in every name, never a shortened one (owner 2026-10-24)
def ap_ssid():
    return 'BirdSuite-' + mac().replace(':', '').upper()


def mdns_name():
    return 'birdsuite-' + mac().replace(':', '') + '.local'


def ap_addr():
    # the station WiFi's own address (owner 2026-10-25): 10.42.N.1, N = 1..254 from the MAC, so two stations never share
    # one and a browser keeps their certificates and logins apart; the same for a board on every boot
    m = mac().replace(':', '')
    return '10.42.%d.1' % (1 + int(m[-4:] or '0', 16) % 254) if m else AP_ADDR


def ap_sync_addr():
    # an AP profile or captive file of an older version (10.42.0.1) takes the station's own address
    a = ap_addr()
    if AP in profiles() and sh('nmcli', '-g', 'ipv4.addresses', 'connection', 'show', AP, check=False).strip() != a + '/24':
        sh('nmcli', 'connection', 'modify', AP, 'ipv4.addresses', a + '/24')
    want = '# BirdSuite (scripts/field_net.py): on the station WiFi every name is the station = captive setup page\n' + f'address=/#/{a}\n'
    try:
        same = open(CAPTIVE_CONF).read() == want
    except OSError:
        same = False
    if not same:
        try:
            os.makedirs(os.path.dirname(CAPTIVE_CONF), exist_ok=True)
            with open(CAPTIVE_CONF, 'w') as f:
                f.write(want)
        except OSError:  # the station user (least privilege): through the root helper
            as_root('captive', a)
    return a


def fleet_extra_ip():
    # 172.20.10.2 – .14 (the iPhone hotspot /28: .1 is the phone), the same for a board on every boot
    m = mac().replace(':', '')
    return '172.20.10.%d' % (2 + int(m[-4:] or '0', 16) % 13)


def profiles():
    out = {}
    for line in sh('nmcli', '-t', '-f', 'NAME,TYPE,AUTOCONNECT,AUTOCONNECT-PRIORITY', 'connection', 'show').splitlines():
        parts = re.split(r'(?<!\\):', line)
        if len(parts) >= 4:
            out[parts[0].replace('\\:', ':')] = {'type': parts[1], 'autoconnect': parts[2], 'priority': parts[3]}
    return out


def wifi_dev():
    for line in sh('nmcli', '-t', '-f', 'DEVICE,TYPE', 'device').splitlines():
        d, _, kind = line.partition(':')
        if kind == 'wifi':
            return d
    return ''


def status():
    # never an error as a whole (owner 2026-10-24: the page lost the station WiFi's name and state when one nmcli call
    # failed): what NetworkManager does not answer stays empty and is named in 'errors'
    errors = []
    try:
        p = profiles()
    except RuntimeError as e:
        p = {}
        errors.append(str(e))
    active = []
    try:
        active_out = sh('nmcli', '-t', '-f', 'NAME,TYPE,DEVICE,STATE', 'connection', 'show', '--active')
    except RuntimeError as e:
        active_out = ''
        errors.append(str(e))
    for line in active_out.splitlines():
        parts = re.split(r'(?<!\\):', line)
        if len(parts) >= 3 and parts[1] != 'loopback':
            # state: activated, or activating while NetworkManager still tries it (a refused password retries for minutes)
            active.append({'name': parts[0].replace('\\:', ':'), 'type': parts[1], 'device': parts[2], 'state': parts[3] if len(parts) > 3 else 'activated'})
    ips = []
    for line in sh('ip', '-4', '-o', 'addr', 'show', check=False).splitlines():
        m = re.search(r'^\d+:\s+(\S+)\s+inet\s+([\d.]+)/', line)
        if m and m.group(1) != 'lo':
            ips.append({'device': m.group(1), 'ip': m.group(2)})
    try:
        dev = wifi_dev()
    except RuntimeError as e:
        dev = ''
        errors.append(str(e))
    clients = 0
    ap_on = any(a['name'] == AP for a in active)
    if ap_on and dev:
        clients = sh('iw', 'dev', dev, 'station', 'dump', check=False).count('Station ')
    fleet_ssid = ''
    if FLEET in p:
        fleet_ssid = sh('nmcli', '-g', '802-11-wireless.ssid', 'connection', 'show', FLEET, check=False).strip()
    known = [n for n, v in p.items() if v['type'] == '802-11-wireless' and n not in (FLEET, AP)]
    known_ssid = {n: sh('nmcli', '-g', '802-11-wireless.ssid', 'connection', 'show', n, check=False).strip() or n for n in known}
    radio = sh('nmcli', 'radio', 'wifi', check=False).strip()
    # the station's real host name (a field image names it birdsuite-<mac>; a Mesh node keeps its own, e.g. appsrv)
    return {'hostname': os.uname().nodename, 'mac': mac(), 'ap_ssid': ap_ssid(), 'ap_profile': AP in p, 'ap_on': ap_on,
            'ap_clients': clients, 'ap_addr': ap_addr(),
            'fleet_profile': FLEET in p, 'fleet_ssid': fleet_ssid, 'fleet_extra_ip': fleet_extra_ip(), 'known_wifi': known, 'known_ssid': known_ssid,
            'active': active, 'ips': ips, 'wifi_device': dev, 'wifi_radio': radio,
            'time_confirmed': os.path.exists(TIME_FLAG) or ntp_synced(), 'time': time.strftime('%Y-%m-%d %H:%M:%S'),
            'errors': sorted(set(errors))}


def ntp_synced():
    return sh('timedatectl', 'show', '-p', 'NTPSynchronized', '--value', check=False).strip() == 'yes'


def read_psk():
    psk = sys.stdin.readline().rstrip('\n')
    if not 8 <= len(psk) <= 63:
        raise SystemExit(t('the password needs 8 to 63 characters'))
    return psk


def set_fleet(ssid):
    if not 1 <= len(ssid) <= 32:
        raise SystemExit(t('the network name needs 1 to 32 characters'))
    psk = read_psk()
    base = ['802-11-wireless.ssid', ssid, '802-11-wireless.mode', 'infrastructure',
            'wifi-sec.key-mgmt', 'wpa-psk', 'wifi-sec.psk', psk,
            'connection.autoconnect', 'yes', 'connection.autoconnect-priority', '-999',
            'ipv4.method', 'auto', 'ipv4.addresses', fleet_extra_ip() + '/28', 'ipv6.method', 'auto']
    if FLEET in profiles():
        sh('nmcli', 'connection', 'modify', FLEET, *base)
    else:
        sh('nmcli', 'connection', 'add', 'type', 'wifi', 'con-name', FLEET, 'ifname', '*', *base)
    return {'ok': True, 'fleet_ssid': ssid, 'fleet_extra_ip': fleet_extra_ip()}


def set_ap():
    psk = read_psk()
    base = ['802-11-wireless.ssid', ap_ssid(), '802-11-wireless.mode', 'ap', '802-11-wireless.band', 'bg',
            'wifi-sec.key-mgmt', 'wpa-psk', 'wifi-sec.psk', psk, 'wifi-sec.proto', 'rsn', 'wifi-sec.pairwise', 'ccmp',
            'connection.autoconnect', 'no', 'ipv4.method', 'shared', 'ipv4.addresses', ap_addr() + '/24', 'ipv6.method', 'disabled']
    if AP in profiles():
        sh('nmcli', 'connection', 'modify', AP, *base)
    else:
        sh('nmcli', 'connection', 'add', 'type', 'wifi', 'con-name', AP, 'ifname', wifi_dev() or '*', *base)
    # captive setup page: the AP's dnsmasq answers every name with the station
    ap_sync_addr()
    return {'ok': True, 'ap_ssid': ap_ssid()}


def ap_on(reason=''):
    if AP not in profiles():
        raise SystemExit(t('the station WiFi has no password yet (Settings › Network)'))
    ap_sync_addr()
    sh('nmcli', 'radio', 'wifi', 'on')
    time.sleep(2)
    sh('nmcli', 'connection', 'up', AP)
    os.makedirs(STATE_DIR, exist_ok=True)
    with open(os.path.join(STATE_DIR, 'ap_since'), 'w') as f:
        f.write(str(int(time.time())))
    if reason:  # who turned it on (boot / setup / bluetooth / page): the field service decides when it goes off
        with open(os.path.join(STATE_DIR, 'ap_reason'), 'w') as f:
            f.write(reason)
    return {'ok': True, 'ap_ssid': ap_ssid(), 'ap_addr': ap_addr()}


def ap_off():
    sh('nmcli', 'connection', 'down', AP, check=False)
    try:
        os.remove(os.path.join(STATE_DIR, 'ap_since'))
    except OSError:
        pass
    return {'ok': True}


def add_wifi(ssid):
    if not 1 <= len(ssid) <= 32:
        raise SystemExit(t('the network name needs 1 to 32 characters'))
    psk = read_psk()
    name = 'wifi-' + re.sub(r'[^A-Za-z0-9_.-]', '_', ssid)
    base = ['802-11-wireless.ssid', ssid, 'wifi-sec.key-mgmt', 'wpa-psk', 'wifi-sec.psk', psk,
            'connection.autoconnect', 'yes', 'connection.autoconnect-priority', '10']
    if name in profiles():
        sh('nmcli', 'connection', 'modify', name, *base)
    else:
        sh('nmcli', 'connection', 'add', 'type', 'wifi', 'con-name', name, 'ifname', '*', *base)
    return {'ok': True, 'name': name}


def known_profile(name):
    """A known WiFi network profile the pages may change: WiFi, and never the station's own (owner 2026-10-24)."""
    p = profiles()
    if name == AP or name not in p or p[name]['type'] != '802-11-wireless':
        raise SystemExit(t('unknown network'))
    return name


def edit_wifi(name, ssid):
    """A known network with a new name and / or password (an empty password keeps the old one)."""
    known_profile(name)
    if not 1 <= len(ssid) <= 32:
        raise SystemExit(t('the network name needs 1 to 32 characters'))
    psk = sys.stdin.readline().rstrip('\n')
    if psk and not 8 <= len(psk) <= 63:
        raise SystemExit(t('the password needs 8 to 63 characters'))
    base = ['802-11-wireless.ssid', ssid] + (['wifi-sec.key-mgmt', 'wpa-psk', 'wifi-sec.psk', psk] if psk else [])
    new = name if name == FLEET else 'wifi-' + re.sub(r'[^A-Za-z0-9_.-]', '_', ssid)
    if new != name and new in profiles():
        raise SystemExit(t('a network with that name is already known'))
    if new != name:
        base += ['connection.id', new]
    sh('nmcli', 'connection', 'modify', name, *base)
    return {'ok': True, 'name': new}


def forget_wifi(name):
    sh('nmcli', 'connection', 'delete', known_profile(name))
    return {'ok': True}


def as_root(*args):
    """A root step through the root-owned helper when this runs as the station user (least privilege, 2026-10-23)."""
    r = subprocess.run(['sudo', '-n', '/usr/local/sbin/birdsuite-root'] + [str(a) for a in args], capture_output=True, text=True)
    if r.returncode != 0:
        raise SystemExit((r.stderr or r.stdout).strip() or 'birdsuite-root ' + str(args[0]) + ' failed')
    return r.stdout


def set_time(epoch):
    when = int(float(epoch))
    if when < 1735689600:  # before 2025: not a real clock
        raise SystemExit(t('not a valid time'))
    if os.geteuid() != 0:
        as_root('settime', when)
        os.makedirs(STATE_DIR, exist_ok=True)
        open(TIME_FLAG, 'w').write(str(when))
        return {'ok': True, 'time': time.strftime('%Y-%m-%d %H:%M:%S')}
    sh('timedatectl', 'set-ntp', 'false', check=False)
    try:
        sh('date', '-s', '@%d' % when)
        # a Pi without a battery clock has no hwclock (2026-10-22: its absence threw, the button answered nothing and NTP
        # stayed off, so the clock never synchronised again)
        if shutil.which('hwclock'):
            sh('hwclock', '-w', check=False)
    finally:
        sh('timedatectl', 'set-ntp', 'true', check=False)  # NTP back on, whatever happened
    os.makedirs(STATE_DIR, exist_ok=True)
    open(TIME_FLAG, 'w').write(str(when))
    return {'ok': True, 'time': time.strftime('%Y-%m-%d %H:%M:%S')}


def identify(seconds=300):
    """Blink the board's activity LED so the station in hand can be told apart from others in range."""
    if os.geteuid() != 0:
        as_root('identify', int(seconds))
        return {'ok': True, 'seconds': int(seconds)}
    led = next((d for d in ('/sys/class/leds/ACT', '/sys/class/leds/led0') if os.path.isdir(d)), None)
    if not led:
        raise SystemExit(t('no activity LED on this board'))
    try:
        old = re.search(r'\[([^\]]+)\]', open(led + '/trigger').read()).group(1)
    except (OSError, AttributeError):
        old = 'mmc0'
    subprocess.Popen(['sh', '-c', f'echo timer > {led}/trigger; echo 100 > {led}/delay_on; echo 100 > {led}/delay_off; '
                      f'sleep {int(seconds)}; echo {old} > {led}/trigger'])
    return {'ok': True, 'seconds': int(seconds)}


def ap_psk():
    # everything the label prints (owner 2026-10-24: complete even when NetworkManager does not answer `status`):
    # all but the password come from the MAC alone
    out = sh('nmcli', '-s', '-g', '802-11-wireless-security.psk', 'connection', 'show', AP, check=False).strip()
    m = mac()
    return {'ap_ssid': ap_ssid(), 'psk': out, 'ap_addr': ap_addr(), 'mac': m, 'wifi_mac': wifi_mac(),
            'fleet_extra_ip': fleet_extra_ip(), 'mdns': mdns_name(),
            'hostname': os.uname().nodename}


def wifi_psk(name):
    # a known network's password, shown on request (owner 2026-10-25: "see password" in section 1 as in Plans A and B)
    return {'psk': sh('nmcli', '-s', '-g', '802-11-wireless-security.psk', 'connection', 'show', known_profile(name), check=False).strip()}


def fleet_psk():
    # the saved phone hotspot's password, shown on request in Plan B (owner 2026-10-25: "see password" as in the station WiFi)
    return {'psk': sh('nmcli', '-s', '-g', '802-11-wireless-security.psk', 'connection', 'show', FLEET, check=False).strip()}


def main():
    a = sys.argv[1:]
    cmds = {'status': lambda: status(), 'set-ap': lambda: set_ap(), 'ap-on': lambda: ap_on(), 'ap-off': lambda: ap_off(),
            'ap-psk': lambda: ap_psk(), 'fleet-psk': lambda: fleet_psk(), 'identify': lambda: identify()}
    try:
        if a and a[0] in cmds and len(a) == 1:
            res = cmds[a[0]]()
        elif a[:1] == ['ap-on'] and len(a) == 2:
            res = ap_on(a[1])
        elif a[:1] == ['set-fleet'] and len(a) == 2:
            res = set_fleet(a[1])
        elif a[:1] == ['edit-wifi'] and len(a) == 3:
            res = edit_wifi(a[1], a[2])
        elif a[:1] == ['wifi-psk'] and len(a) == 2:
            res = wifi_psk(a[1])
        elif a[:1] == ['forget-wifi'] and len(a) == 2:
            res = forget_wifi(a[1])
        elif a[:1] == ['add-wifi'] and len(a) == 2:
            res = add_wifi(a[1])
        elif a[:1] == ['time'] and len(a) == 2:
            res = set_time(a[1])
        else:
            raise SystemExit(__doc__)
    except (RuntimeError, SystemExit) as e:
        print(json.dumps({'ok': False, 'error': str(e)}))
        sys.exit(1)
    print(json.dumps(res, ensure_ascii=False))


if __name__ == '__main__':
    main()
