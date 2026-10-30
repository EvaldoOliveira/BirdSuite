#!/usr/bin/env python3
"""Bluetooth field service (US-120, owner 2026-10-20) — birdsuite_ble.service, as root, through BlueZ over D-Bus
(python3-dbus + GLib, both on Raspberry Pi OS). A phone (the field companion, static/companion) finds the station by
the name it advertises, BirdSuite-<the whole MAC>, with or without any network:

  info    (read, notify)  JSON: station, location, mac, ip list, network, station WiFi on/clients, time confirmed,
                          recording, mDNS name, the result of the last command — from /run/birdsuite/field_state.json
  command (write)         "time:<epoch>"            the phone's clock (scripts/field_net.py time)
                          "gps:<lat>,<lon>[,<name>]" the phone's position: that location becomes the current one
                                                     (scripts/field_cli.php gps)
                          "ap:on" / "ap:off"        the station WiFi (then the phone joins it and opens the site)
                          "identify"                blink the activity LED (several stations in range)
                          With a station password (CADDY_PWD) every command starts with "pw:<password>;".
The classic Bluetooth audio of the station (a speaker) is not affected.
"""
import json
import os
import subprocess
import sys

import dbus
import dbus.exceptions
import dbus.mainloop.glib
import dbus.service
from gi.repository import GLib

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
import field_net  # noqa: E402

BLUEZ = 'org.bluez'
GATT_MANAGER = 'org.bluez.GattManager1'
ADV_MANAGER = 'org.bluez.LEAdvertisingManager1'
DBUS_OM = 'org.freedesktop.DBus.ObjectManager'
DBUS_PROP = 'org.freedesktop.DBus.Properties'
SERVICE_UUID = 'f00d0001-b5d1-4e7a-9a2b-3c4d5e6f7a80'
INFO_UUID = 'f00d0002-b5d1-4e7a-9a2b-3c4d5e6f7a80'
CMD_UUID = 'f00d0003-b5d1-4e7a-9a2b-3c4d5e6f7a80'
STATE = os.path.join(field_net.STATE_DIR, 'field_state.json')
HERE = os.path.dirname(os.path.abspath(__file__))
last_result = {'cmd': '', 'ok': None, 'msg': ''}


def conf_value(key):
    try:
        for line in open(os.path.join(os.path.dirname(os.path.dirname(os.path.realpath(__file__))), 'config', 'BirdSuite.conf'), encoding='utf-8'):
            k, sep, v = line.strip().partition('=')
            if sep and k == key:
                return v.strip().strip('"')
    except OSError:
        pass
    return ''


def info_json():
    try:
        st = json.load(open(STATE, encoding='utf-8'))
    except (OSError, ValueError):
        st = {}
    lan = [i['ip'] for i in st.get('ips', [])]
    net = [a['name'] for a in st.get('active', []) if a.get('name') != field_net.AP]
    data = {'s': st.get('station', ''), 'l': st.get('location', ''), 'mac': st.get('mac', field_net.mac()), 'ip': lan,
            'net': net, 'ap': st.get('ap_on', False), 'apc': st.get('ap_clients', 0), 'ssid': st.get('ap_ssid', field_net.ap_ssid()),
            't': st.get('time_confirmed', False), 'now': st.get('time', ''), 'rec': st.get('recording', False),
            'mdns': st.get('mdns', ''), 'pw': bool(conf_value('CADDY_PWD')), 'res': last_result}
    raw = json.dumps(data, ensure_ascii=False, separators=(',', ':'))
    return raw.encode('utf-8')[:512]


def run_command(text):
    global last_result
    pwd = conf_value('CADDY_PWD')
    if pwd:
        if not text.startswith('pw:') or ';' not in text or text[3:text.index(';')] != pwd:
            last_result = {'cmd': text.split(';')[-1].split(':')[0], 'ok': False, 'msg': field_net.t('wrong or missing station password')}
            return
        text = text[text.index(';') + 1:]
    verb, _, arg = text.partition(':')
    try:
        if verb == 'time':
            out = subprocess.run([sys.executable, os.path.join(HERE, 'field_net.py'), 'time', arg], capture_output=True, text=True, timeout=30)
        elif verb == 'gps':
            parts = arg.split(',', 2)
            lat, lon = float(parts[0]), float(parts[1])
            name = parts[2] if len(parts) > 2 else ''
            out = subprocess.run(['php', os.path.join(HERE, 'field_cli.php'), 'gps', str(lat), str(lon), name], capture_output=True, text=True, timeout=60)
        elif verb == 'identify':
            out = subprocess.run([sys.executable, os.path.join(HERE, 'field_net.py'), 'identify'], capture_output=True, text=True, timeout=10)
        elif verb == 'ap' and arg in ('on', 'off'):
            out = subprocess.run([sys.executable, os.path.join(HERE, 'field_net.py'), 'ap-' + arg] + (['bluetooth'] if arg == 'on' else []),
                                 capture_output=True, text=True, timeout=60)
        else:
            last_result = {'cmd': verb, 'ok': False, 'msg': field_net.t('unknown command')}
            return
        msg = (out.stdout or out.stderr).strip()[:200]
        last_result = {'cmd': verb, 'ok': out.returncode == 0, 'msg': msg}
    except (ValueError, subprocess.TimeoutExpired, OSError) as e:
        last_result = {'cmd': verb, 'ok': False, 'msg': str(e)[:200]}


class Application(dbus.service.Object):
    def __init__(self, bus):
        self.path = '/org/birdsuite'
        self.services = []
        dbus.service.Object.__init__(self, bus, self.path)

    @dbus.service.method(DBUS_OM, out_signature='a{oa{sa{sv}}}')
    def GetManagedObjects(self):
        out = {}
        for s in self.services:
            out[s.get_path()] = s.get_properties()
            for c in s.characteristics:
                out[c.get_path()] = c.get_properties()
        return out


class Service(dbus.service.Object):
    def __init__(self, bus, index, uuid):
        self.path = '/org/birdsuite/service%d' % index
        self.uuid = uuid
        self.characteristics = []
        dbus.service.Object.__init__(self, bus, self.path)

    def get_path(self):
        return dbus.ObjectPath(self.path)

    def get_properties(self):
        return {'org.bluez.GattService1': {'UUID': self.uuid, 'Primary': True,
                                           'Characteristics': dbus.Array([c.get_path() for c in self.characteristics], signature='o')}}


class Characteristic(dbus.service.Object):
    def __init__(self, bus, index, uuid, flags, service):
        self.path = service.path + '/char%d' % index
        self.uuid, self.flags, self.service = uuid, flags, service
        self.notifying = False
        dbus.service.Object.__init__(self, bus, self.path)

    def get_path(self):
        return dbus.ObjectPath(self.path)

    def get_properties(self):
        return {'org.bluez.GattCharacteristic1': {'Service': self.service.get_path(), 'UUID': self.uuid,
                                                  'Flags': self.flags, 'Descriptors': dbus.Array([], signature='o')}}

    @dbus.service.method(DBUS_PROP, in_signature='s', out_signature='a{sv}')
    def GetAll(self, interface):
        return self.get_properties()['org.bluez.GattCharacteristic1']

    @dbus.service.signal(DBUS_PROP, signature='sa{sv}as')
    def PropertiesChanged(self, interface, changed, invalidated):
        pass


class InfoCharacteristic(Characteristic):
    def __init__(self, bus, index, service):
        Characteristic.__init__(self, bus, index, INFO_UUID, ['read', 'notify'], service)
        self.last = b''
        GLib.timeout_add_seconds(5, self.tick)

    @dbus.service.method('org.bluez.GattCharacteristic1', in_signature='a{sv}', out_signature='ay')
    def ReadValue(self, options):
        offset = int(options.get('offset', 0))
        return dbus.Array(info_json()[offset:], signature='y')

    @dbus.service.method('org.bluez.GattCharacteristic1')
    def StartNotify(self):
        self.notifying = True

    @dbus.service.method('org.bluez.GattCharacteristic1')
    def StopNotify(self):
        self.notifying = False

    def tick(self):
        v = info_json()
        if self.notifying and v != self.last:
            self.PropertiesChanged('org.bluez.GattCharacteristic1', {'Value': dbus.Array(v, signature='y')}, [])
        self.last = v
        return True


class CommandCharacteristic(Characteristic):
    def __init__(self, bus, index, service):
        Characteristic.__init__(self, bus, index, CMD_UUID, ['write'], service)

    @dbus.service.method('org.bluez.GattCharacteristic1', in_signature='aya{sv}')
    def WriteValue(self, value, options):
        text = bytes(value).decode('utf-8', 'replace').strip()
        # the work runs after the reply, so the phone does not wait on it
        GLib.idle_add(lambda: (run_command(text), False)[1])


class Advertisement(dbus.service.Object):
    def __init__(self, bus):
        self.path = '/org/birdsuite/advertisement0'
        dbus.service.Object.__init__(self, bus, self.path)

    def get_path(self):
        return dbus.ObjectPath(self.path)

    @dbus.service.method(DBUS_PROP, in_signature='s', out_signature='a{sv}')
    def GetAll(self, interface):
        return {'Type': 'peripheral', 'ServiceUUIDs': dbus.Array([SERVICE_UUID], signature='s'),
                'LocalName': dbus.String(field_net.ap_ssid()), 'Includes': dbus.Array(['tx-power'], signature='s')}

    @dbus.service.method('org.bluez.LEAdvertisement1')
    def Release(self):
        pass


def adapter_path(bus):
    om = dbus.Interface(bus.get_object(BLUEZ, '/'), DBUS_OM)
    for path, ifaces in om.GetManagedObjects().items():
        if GATT_MANAGER in ifaces and ADV_MANAGER in ifaces:
            return path
    raise SystemExit('no Bluetooth adapter with BLE peripheral support')


def main():
    dbus.mainloop.glib.DBusGMainLoop(set_as_default=True)
    bus = dbus.SystemBus()
    path = adapter_path(bus)
    adapter_props = dbus.Interface(bus.get_object(BLUEZ, path), DBUS_PROP)
    adapter_props.Set('org.bluez.Adapter1', 'Powered', dbus.Boolean(1))
    app = Application(bus)
    svc = Service(bus, 0, SERVICE_UUID)
    svc.characteristics = [InfoCharacteristic(bus, 0, svc), CommandCharacteristic(bus, 1, svc)]
    app.services = [svc]
    adv = Advertisement(bus)
    loop = GLib.MainLoop()
    dbus.Interface(bus.get_object(BLUEZ, path), GATT_MANAGER).RegisterApplication(
        app.path, {}, reply_handler=lambda: print('GATT service registered', flush=True),
        error_handler=lambda e: (print('GATT registration failed:', e, flush=True), loop.quit()))
    dbus.Interface(bus.get_object(BLUEZ, path), ADV_MANAGER).RegisterAdvertisement(
        adv.get_path(), {}, reply_handler=lambda: print('advertising as', field_net.ap_ssid(), flush=True),
        error_handler=lambda e: (print('advertisement failed:', e, flush=True), loop.quit()))
    loop.run()


if __name__ == '__main__':
    main()
