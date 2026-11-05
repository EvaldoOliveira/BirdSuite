# Installing and updating BirdSuite

This guide covers every way to get BirdSuite onto a Raspberry Pi and keep it current.
For the short version, see [Quick start](../README.md#quick-start) in the README.

- [Requirements](#requirements)
- [New installation on a blank card](#new-installation-on-a-blank-card)
- [Opening the station](#opening-the-station)
- [Unattended installation and fleets](#unattended-installation-and-fleets)
- [Coming from BirdNET-Pi](#coming-from-birdnet-pi)
  - [On a new card, with Import](#on-a-new-card-with-import)
  - [On the same card, with the migration](#on-the-same-card-with-the-migration)
- [Updating](#updating)
- [Uninstalling](#uninstalling)
- [Advanced installer options](#advanced-installer-options)

---

## Requirements

| | |
|---|---|
| **Board** | Raspberry Pi 5 or Raspberry Pi 4B (the reference hardware). Any 64-bit board (aarch64) or x86_64 machine running Debian installs, but is not tested. |
| **Operating system** | **64-bit** Raspberry Pi OS / Debian 13 **Trixie** (the reference, Python 3.13). Bookworm (Python 3.11) also installs. A 32-bit OS or Bullseye (Python 3.9) is refused. |
| **Storage** | A microSD card (or SSD) of 32 GB or more. *Data › Disk Space* later tells you the smallest disk that fits your station in the long term. |
| **Microphone** | Any USB microphone or USB sound card. An RTSP stream can replace it. |
| **Network** | Internet during the installation (Ethernet or WiFi). Afterwards the station also runs offline. |
| **User** | A normal user (any name, e.g. `pi`). The installer refuses to run as root. |

Nothing outside the public repositories and the internet is needed: no key, no GitHub account, no private file.

---

## New installation on a blank card

About 30 to 45 minutes, most of it unattended.

### 1. Prepare the card

On any computer, install [Raspberry Pi Imager](https://www.raspberrypi.com/software/), insert the card and choose:

- **Device**: your Raspberry Pi model.
- **Operating system**: *Raspberry Pi OS (64-bit)*.
  The version with desktop lets you do everything on the Pi itself, with a keyboard, mouse and monitor.
  *Raspberry Pi OS (64-bit) Lite* is enough when you work from another computer over ssh.
- **Storage**: your card.

When the Imager offers **OS customisation**, click **Edit settings** and set the hostname **`birdsuite`** (lower case: the station is then `birdsuite.local`; the installer also renames a stock `raspberrypi` or a `BirdSuite` to it), a username and password, your WiFi (skip it with a cable), your time zone and keyboard layout.
For a card without keyboard and monitor, also turn on **Enable SSH** in the **Services** tab.
Write the card.

### 2. Start the Pi

Connect the USB microphone (and the keyboard, mouse and monitor if you use them), insert the card and power the Pi.
The first start takes a few minutes.

### 3. Install

Open a terminal on the Pi (or `ssh <username>@<hostname>.local` from another computer) and run one command:

```bash
curl -fsSL https://raw.githubusercontent.com/EvaldoOliveira/BirdSuite/main/newinstaller.sh | bash
```

The installer:

- asks your password once when sudo needs one (for the installation only);
- installs `git` and `jq` when missing and clones BirdSuite into **`~/BirdSuite`**;
- installs the system packages, the web server, the services, the database, a Python environment with the inference runtime that matches your CPU (its checksum is verified) and the BirdNET+ V3 model files;
- sets up the first USB microphone as a shared device;
- hardens the system (see [Privacy and security](../README.md#privacy-and-security));
- asks nothing else, then reboots: the station's settings are made in the browser, on the first visit.

Any failed step ends the installer with an error, never with a false success.
The log is written to `installation-<date>.txt` in the folder you ran the command from (passwords are never traced).

### 4. First Run, in the browser

The installer asks no questions: it fills in what the network tells (location, time zone, Brazilian state, species-name language by country), model V3, the web password `birdsuite` and a random live-stream password.
The first visit to the station opens **First Run** (without login until it is saved; afterwards the button **Start the First Run** of *System › All settings* opens it again, with the login, afterwards), which shows these values to check and asks the rest:

1. **Location** on a map (the GPS icon reads the device's position) and **time zone**; the station is named `BirdSuite-<last 4 digits of the MAC>` and the place *Home*, both renamed later in *Station › Locations*.
2. **Model**, **language of the species names** and, in Brazil, the **state list**.
3. **Several locations?** and **several BirdSuite stations?** (default no: the interface stays simple).
4. **Network in the field**: the **phone hotspot** name and password (Plan B, the station joins your phone whenever the hotspot is on) and the **station WiFi password** for `BirdSuite-<last 4 digits of the MAC>` (Plan A, it goes on the QR label, never the web password); both can stay empty.
5. **Theme** and a new **web password** for the user `birdsuite`.

Saving it starts the detections.
Later, in the menus: the field network and the QR label in *Station › Network*, BirdWeather in *Station › Sharing*, notifications in *Alerts › E-mail* and *Alerts › Telegram*.

A seed file (below) sets everything up front instead, and then First Run is not shown.

---

## Opening the station

After the restart, open **`http://localhost`** on the Pi itself, or from any phone, tablet or computer on the same network:

- **`http://birdsuite.local`** when there is one station on the network, or
- **`http://birdsuite-<MAC>.local`**, the station's own name, never shared by two stations (shown on *Station › Network* and on the QR label).

The name follows the station within seconds when it changes network (cable, WiFi, phone hotspot), so a new address never matters.
A device may keep the old address for a minute or two (Windows: `ipconfig /flushdns`).
Names work on iPhone, iPad and Mac, Android 12 or later, Linux with mDNS (nss-mdns) and Windows 10/11 on a *Private* network.
A device that cannot use names finds the IP address on *Station › Network*, on the label and in the "new address" notification.

The station starts on **Now**, the pictures of the birds singing.
The first detections appear within a few minutes.

### http, and https only for the GPS

The station answers on plain **http**, so no certificate warning gets in the way.
Browsers give a page the device's GPS position only over https, so the GPS icon of the location map (*Station › Locations*, *First Run*) first explains what comes, switches https on for a moment and reads the position in a small window; https then goes off again by itself.
The first time on each device that window shows the browser's certificate warning: the station makes its own certificate, because no public authority can certify a device inside your home network.
Click **Advanced** and **Proceed** (Chrome, Edge), **Accept the Risk and Continue** (Firefox) or **Show Details › visit this website** (Safari), then allow the location.
Sharing a detection uses the same short https window.

To use https all the time, set **`HTTPS_ALWAYS`** to 1 in *System › All settings* (section *Web access*).

---

## Unattended installation and fleets

**Answers without typing.**
Copy [`birdnet-setup.conf.example`](birdnet-setup.conf.example) to the card's boot partition as `birdnet-setup.conf`, fill in the values, then install as above; the station then starts set up, without First Run.
The file is read as `KEY=value` lines, never executed; missing keys take the defaults.

**One card for many stations.**
`~/BirdSuite/scripts/prepare_image.sh` turns a working station into a master card: identity, data, logs, WiFi networks, notification secrets and the web password are cleared (unless kept with `--keep-wifi`, `--keep-notifications`, `--keep-password`) and the first-run setup is pending again.
`--dry-run` shows what it would do.
Each clone then comes up as **`BirdSuite-<the last 4 digits of its MAC>`** at *Home* and is set up from a phone through its own WiFi and QR label.

---

## Coming from BirdNET-Pi

BirdSuite reads the database and clips of Nachtzuster's and mcguirepr89's BirdNET-Pi.
There are two ways to move.

### On a new card, with Import

The safe way: the BirdNET-Pi card stays untouched, as your backup and your way back.

1. **Keep the BirdNET-Pi card as it is**, or copy its data to a USB disk or a network share: `~/BirdNET-Pi/scripts/birds.db` and `~/BirdSongs/Extracted/By_Date`.
2. **Install BirdSuite on a new card** ([above](#new-installation-on-a-blank-card)).
3. **Bring the history, if you want it**: open *Data › Backup/Import › Import* and pick the folder with the BirdNET-Pi data (the old card's home in a USB card reader, or the copy).
   - The import finds `birds.db` and the `By_Date` clips by common name, and shows the kind, language and number of detections before it starts.
   - Choose the destination: **Test / Playback** (the default, to check first), a new location, or an existing one.
   - It copies: the source is never changed, and a detection already on the station is never added twice.
   - **Pause**, **Continue** and **Cancel** (Cancel undoes what was imported so far).
4. **Changed your mind?** Put the BirdNET-Pi card back.
   *Data › Backup/Import › Export to BirdNET* writes the detections BirdSuite made meanwhile as `birds.db` plus `By_Date` by common name; merging them into the old card's database is a manual step.
   Reviews, locations and per-species thresholds exist only in BirdSuite.

### On the same card, with the migration

The installer recognises a BirdNET-Pi installation on the card and computes the space it needs (BirdSuite, a copy of the database and clips in BirdSuite's layout, and a margin).

- **Enough space**: it stops the BirdNET-Pi services, renames its program and sound folders to `*.old`, installs BirdSuite and imports the database and clips **as a copy**. The old installation stays whole.
  If the installation fails, BirdNET-Pi is put back as it was.
- **Not enough space**: it asks first (a `y/N` question in the terminal; without a terminal, or with `BIRDNET_UNATTENDED=1`, the answer is No).
  If you accept, it saves the BirdNET-Pi settings in `config/BIRDNET.OLD.gz`, **moves** (not copies) the database and clips into BirdSuite and removes the old program.
  If you refuse, nothing changes and the installation stops.
- **Not enough space even for a move**: nothing changes and the installation stops.

The import itself runs after the first setup, as soon as the station has its location: the detections are filed under the location of their coordinates and named in the species language you chose.
It runs in the background while the station records; *Data › Backup/Import* shows its progress.

**The 30-day test period.**
*System › Status* then shows a **Migration** section above *Services*: what is kept from BirdNET-Pi (the `*.old` folders, `BIRDNET.OLD.gz`) with their sizes, the days left, and two buttons, each asking for confirmation:

- **Remove the BirdNET-Pi leftovers** deletes the `*.old` folders and `BIRDNET.OLD.gz`; the section disappears. It is possible once the import is done.
- **Go back to BirdNET-Pi** stops and switches off BirdSuite, restores the `*.old` folders as BirdNET-Pi (BirdSuite's sound folder is kept as `*.birdsuite`), puts back the system settings the installation changed (cron lines, web server, PHP, stream server, the passwordless sudo rule) and restarts the station. Take the detections made with BirdSuite along first with *Export to BirdNET*.
  After a move there is no old program to put back: the button explains the way back (a new BirdNET-Pi installation, *Export to BirdNET*, and the settings from **Download the BirdNET-Pi settings**).

The section stays until one of them is confirmed, also after the 30 days (a notification says when the period ended).

> Make a full copy of the card before migrating on the same card. With too little space the move is one-way: the copy is then your only way back.

---

## Updating

**From the web page.**
*System › Status* shows the version running ("BirdSuite vX.Y.Z + N commits") and how far it is behind.
When a newer version exists, the button reads **Update to vX.Y.Z** (or *Update to the latest main*).
The update runs on a screen of its own with its live output; recording and analysis stop for about a minute; the app reloads when it is done.

**Automatically.**
Tick **Automatic update** in *System › Status*, then choose the days of the week and the local time.
The update runs within five minutes of that time, only when there is something new, and writes its outcome to the system log (`journalctl -t birdsuite-update`).

**What an update keeps.**
Your settings stay as they are; new settings arrive with their defaults, and a changed factory default replaces your value only while you never changed it.
The species-name labels, the web server configuration, the services and the database indexes are brought to the new version.

**New releases.**
When a new release is out, a badge shows on *System* and *Status* in the menu, and **What's new** lists the notes of every release in between.
Untick the badge with **No update badge in the menu**.

**Rollback.**
To return to an earlier release, set **`UPDATE_BRANCH`** to its tag (e.g. `vX.Y.Z`) in *System › All settings* (section *Updates*) and run the update, from *System › Status* or from a terminal:

```bash
sudo -n /usr/local/sbin/birdsuite-root update
```

Set `UPDATE_BRANCH` back to `main` to follow the development line again.

---

## Uninstalling

```bash
~/BirdSuite/scripts/uninstall.sh && rm -rf ~/BirdSuite
```

`uninstall.sh` removes everything the installation put outside its folder (services, firewall and hardening rules, the udev rule, the audio device, the root helpers and their sudo rule, the cron lines) and gives the first user back their sudo rule.
The recordings in `~/BirdSongs` stay until you delete them.

---

## Advanced installer options

Environment variables of `newinstaller.sh`:

| Variable | Meaning |
|---|---|
| `BIRDNET_BRANCH` | Branch or tag to install (default `main`). |
| `BIRDNET_UPDATE_BRANCH` | Branch the station's updates follow (default `main`). |
| `BIRDNET_REPO` | Repository address (default the public BirdSuite repository). |
| `BIRDNET_UNATTENDED=1` | Nothing asked at all: also the BirdNET-Pi move on a card with too little room is refused instead of asked. |

Pass them in front of `bash`, for example `curl -fsSL …/newinstaller.sh | BIRDNET_BRANCH=vX.Y.Z bash`.

On **x86_64** (mainly for developers): use Debian 12 or 13, with a user that has passwordless sudo.
On Proxmox, one user reported that the inference runtime needs this in `cpu-models.conf`:

```
cpu-model: BirdNet
    flags +sse4.1
    reported-model host
```
