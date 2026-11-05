<p align="center">
  <img src="homepage/images/BirdNetBr.png" alt="BirdSuite logo" width="140" />
</p>
<h1 align="center">BirdSuite</h1>
<p align="center"><b>A Raspberry Pi bird observatory that listens day and night, identifies every song, and tells you what matters.</b></p>
<p align="center"><i>The long-term monitoring edition of BirdNET-Pi.</i></p>
<p align="center">
  <a href="https://github.com/EvaldoOliveira/BirdSuite/releases"><img alt="Release" src="https://img.shields.io/github/v/release/EvaldoOliveira/BirdSuite?color=2b5e22"></a>
  <img alt="Raspberry Pi 5 | 4B" src="https://img.shields.io/badge/Raspberry%20Pi-5%20%7C%204B-c51a4a">
  <img alt="64-bit Raspberry Pi OS" src="https://img.shields.io/badge/OS-64--bit%20Trixie%20%7C%20Bookworm-a22846">
  <img alt="Models" src="https://img.shields.io/badge/models-BirdNET%2B%20V3%20%7C%20V2.4%20%7C%206K-2b5e22">
  <img alt="11 languages" src="https://img.shields.io/badge/interface-11%20languages-1f6feb">
  <a href="LICENSE"><img alt="Licence CC BY-NC-SA 4.0" src="https://img.shields.io/badge/licence-CC%20BY--NC--SA%204.0-lightgrey"></a>
</p>
<p align="center">
  <a href="#quick-start"><b>Quick start</b></a> ·
  <a href="#features"><b>Features</b></a> ·
  <a href="#screenshots"><b>Screenshots</b></a> ·
  <a href="docs/user-guide.md"><b>User guide</b></a> ·
  <a href="docs/troubleshooting.md"><b>FAQ</b></a> ·
  <a href="#licence-and-credits"><b>Credits</b></a>
</p>

<p align="center">
  <img src="docs/screenshots/now.png" alt="A BirdSuite station in a web browser" width="900" />
  <br><sub><i>The pilot station in São Paulo: BirdNET+ V3 with Brazilian (CBRO) species names.</i></sub>
</p>

---

**BirdSuite** turns a Raspberry Pi and a USB microphone into a station that records around the clock, identifies birds (and frogs, mammals and insects) with Cornell's **BirdNET** models, and keeps years of trustworthy data.
It keeps everything BirdNET-Pi does (continuous recording, real-time identification, clips, charts, live audio) and adds what a station needs to run for years: the newest model, a review workflow that turns detections into confirmed records, notifications that only interrupt you for what matters, several places and stations, and a station that tells you when something is wrong.

BirdSuite is an independent edition of [BirdNET-Pi](#lineage), a hard fork of [Nachtzuster/BirdNET-Pi](https://github.com/Nachtzuster/BirdNET-Pi), itself the maintained continuation of [mcguirepr89/BirdNET-Pi](https://github.com/mcguirepr89/BirdNET-Pi).

> [!IMPORTANT]
> BirdSuite, like BirdNET-Pi and the BirdNET models, is licensed **CC BY-NC-SA 4.0**: **non-commercial use only**. [Review the licence](LICENSE) before you build on it.

## Contents

[Features](#features) · [Screenshots](#screenshots) · [Quick start](#quick-start) · [Installation](#installation) · [Updating](#updating) · [Using BirdSuite](#using-birdsuite) · [Configuration](#configuration) · [Privacy and security](#privacy-and-security) · [Languages](#languages) · [Hardware](#hardware) · [FAQ and troubleshooting](#faq-and-troubleshooting) · [Contributing](#contributing) · [Licence and credits](#licence-and-credits)

---

## Features

### 🧠 Analysis and capture

- **Three BirdNET models**: **BirdNET+ V3** (about 9,800 classes of birds, amphibians, mammals and insects, with its own location model), **V2.4** and **BirdNET 6K**. A **defaults table** offers the BirdSuite, Cornell and BirdNET-Pi thresholds of each model with one click.
- **Shadow model**: a second model analyses every recording into its own database, so you can compare models for weeks before switching. *Station › Model comparison* puts them side by side; **Swap Models** promotes the shadow in one click.
- **Per-species thresholds**: a **manual confidence**, a **minimum probability of occurrence** and the **number of clips to keep**, species by species, suggested from your own reviews.
- **One index**: inside, a species is only its scientific name (database, analysis, lists, imports and exports); the common and English names are looked up when shown, so a language change renames nothing.
- **Every detection keeps its scene** (for a study years later): the settings in force, the microphone with its USB port and gain, the time zone and UTC offset, the model, the BirdSuite version, the species list, the audio the recorder lost in that recording, sample rate and channels, in the database and in the clip's own tags; a scenario id points to a snapshot of every setting and the lists' contents (`config/data/scenarios/`). Raw recordings carry the same in their `.recording` file.
- **Species lists**: the model's location filter, your own saved lists, or a **Brazilian state** built from its WikiAves records with CBRO names; **Never accept**, **Always accept**, and **Night-only species** species dropped by day. Under each table one line: the current list, **Save** over it in one click, **Save as** with a picklist of the saved lists, **Delete a saved list** below it; the lists hold scientific names, shown in common, scientific or English, a name the language lacks in parentheses.
- **Any USB microphone**, set up by itself, shared by recording, live stream and field recorder, followed when it is **unplugged and plugged back**, with a window on every page that names each microphone and what used it; a **recording watchdog** restarts the recording when clips come out empty. RTSP streams work too.
- **Field recorder**: long raw WAV recordings of the **dawn chorus** on a schedule, **while the analysis keeps running**.
- **24-bit recordings** (or 16), and an **audio loss** watch: the losses of the last 24 hours on *Station › Microphone*, detections whose own seconds lost audio can be discarded, and *Watch › Latest* filters by them.
- **Settings kept with each detection**: confidence, thresholds, sensitivity, length and overlap in force are stored with every detection and in the clip's tags, so old records never show today's settings.

### ✅ Review and curated data

- **One review player everywhere**: the clip's sonogram, looping, with **Yes**, **Change species** (the model proposes what it hears in the clip), **Review later**, the causes **Human** and **Noise**, and **No / Ignore**. Keyboard shortcuts, swipe gestures and 5-second **Undo**.
- **Review**: how far the review has gone per species, in seven colour-coded groups, with **Start review** to walk through what is left.
- **Precision per species**: from which confidence the detections are real, with a stratified estimate, a guaranteed lower bound and a suggested threshold; **Calibrate thresholds** applies them to every species at once.
- **A life cycle for the sounds**: confirmed clips, clips to review later and the best of every species are never purged; rejected clips leave the disk while the detections keep counting; a full disk can **move old sounds to a NAS or USB disk**; sonograms are stored as compact JPEG.
- **Backup, import and moves**: full or **incremental** backups per location to any folder, **import of BirdSuite backups and BirdNET-Pi data**, settings backup and restore, every job with Pause and Cancel.
- **External files**: analyse the recordings of a portable recorder, an AudioMoth or a phone with the station's model, at the place they were made, and import them if you want.

### 📍 Places, stations and sharing

- **Locations**: take the station to the farm or on a trip; each place keeps its own records, species list, theme and wallpaper, and knows its **biome, ecoregion and land cover**. The station notices **when it moved** and asks.
- **Several stations**: click the station name to open the **same page on another station**; see them all on a **map**.
- **Notifications with sound**: Telegram, e-mail or any Apprise service, **with the clip attached**, guided step by step. **Normal**, **Muted** and **Rare** species, **quiet hours**, a repetition limit and **region-rare alerts** for species the location model does not expect.
- **Share a detection** to WhatsApp, Telegram or e-mail as a short video: the sonogram over the song.
- **eBird**: complete checklists in the eBird Record Format, matched by the scientific name in any eBird language, one file per day (a week or a month as a zip), one location name for every checklist picked from the names used before, the weather of each species, the confirmed sounds as a zip, and a periodic e-mail.
- **Sound repositories**: [BirdWeather](https://app.birdweather.com) uploads (one station per location) and, opt-in, contribution of reviewed clips to the BirdDB-Br training repository.

### 🖥️ Interface

- **Now**: the **pictures of the birds singing now**, from Wikipedia, over a wallpaper of your garden.
- **Reports** by half hour and by calendar week, a **species page** for every species, a **live sonogram** with the names of the birds written on it.
- **Hearing aid** for high songs: a switch beside *Live Audio*, kept per device, lowers what goes to the speaker (the recordings never change); the sonogram and the review player show the real scale beside the heard one. Its gear sets the band (the station's); the factory band, for older listeners, is 500 - 12,000 Hz heard up to 5,000 Hz.
- **One standard on every page**: the same tables, filters, pagers and dialogs; works on a phone; keyboard accessible (WCAG 2.2 AA).
- **Eleven interface languages** and **37 species-name languages**, CBRO names for Brazil.
- **Six colour themes** plus your own, one per location.
- **Simple when you need less**: one station at one place hides everything about locations and stations.

### 🛠️ Platform

- **One-command installation** on a stock Raspberry Pi OS, no questions in the terminal: **First Run** asks everything in the browser; an **answer file** made by a working station sets up new ones; **automatic updates** on chosen days.
- **Coming from BirdNET-Pi**: a new card with **Import**, or the **same card** with a 30-day way back.
- **In the field**: the station joins your known WiFi or **your phone hotspot**, or opens **its own WiFi** with a QR label; works offline and catches up when the internet returns.
- **Least privilege and hardening**: no web page runs as root, a firewall, daily security updates.
- **A station that looks after itself**: health checks with one-click fixes, a disk forecast that tells you the disk you need, the logs at hand.
- **Gentle on the SD card**: the recordings waiting for the analysis live in a **RAM drive** on a Pi with 2 GB or more (at most 512 MB on 2 to 4 GB, 1 GB on 4 GB and more), so the card is not rewritten every 15 seconds; *System › Status* switches it and warns when it fills up.
- **Every setting with its factory default** in one file, named sets of settings, and a history of every change.

---

## Screenshots



<table>
<tr>
<td width="50%" align="center"><b>Latest: the detections as they happen</b><br><img src="docs/screenshots/latest.png" alt="Latest page"></td>
<td width="50%" align="center"><b>Review player: is this the bird?</b><br><img src="docs/screenshots/review.png" alt="Review player"></td>
</tr>
<tr>
<td align="center"><b>By Hour: every species by half hour</b><br><img src="docs/screenshots/by-hour.png" alt="By Hour report"></td>
<td align="center"><b>By Week: the year by calendar week</b><br><img src="docs/screenshots/by-week.png" alt="By Week report"></td>
</tr>
<tr>
<td align="center"><b>Species: every species, its settings and tier</b><br><img src="docs/screenshots/species-pages.png" alt="Species"></td>
<td align="center"><b>A page for every species</b><br><img src="docs/screenshots/species-page.png" alt="Species page"></td>
</tr>
<tr>
<td align="center"><b>Lists: available species by group and region</b><br><img src="docs/screenshots/lists.png" alt="Species lists"></td>
<td align="center"><b>Themes: six colour themes plus your own</b><br><img src="docs/screenshots/appearance.png" alt="Themes"></td>
</tr>
<tr>
<td align="center"><b>System › Status: version, services and checks</b><br><img src="docs/screenshots/station-doctor.png" alt="System Status"></td>
<td align="center"><b>Live sonogram with the birds' names</b><br><img src="docs/screenshots/live-spectrogram.png" alt="Live sonogram"></td>
</tr>
<tr>
<td align="center"><b>Station › Model: official and shadow models</b><br><img src="docs/screenshots/settings-models.png" alt="Model settings"></td>
<td align="center"><b>Notifications: Normal and Rare, quiet hours, region-rare alerts</b><br><img src="docs/screenshots/notifications.png" alt="Notifications"></td>
</tr>
<tr>
<td align="center"><b>Review: is every detection right?</b><br><img src="docs/screenshots/validation.png" alt="Review"></td>
<td align="center"><b>Data › Disk Space: what fills the card, day by day</b><br><img src="docs/screenshots/disk-space.png" alt="Disk Space"></td>
</tr>
<tr>
<td colspan="2" align="center"><b>System › All settings: every setting, its factory value and its meaning</b><br><img src="docs/screenshots/defaults.png" alt="System › All settings"></td>
</tr>
<tr>
<td colspan="2" align="center"><b>eBird: the species of a day, week or month, the eBird file and the confirmed sounds</b><br><img src="docs/screenshots/ebird.png" alt="eBird report"></td>
</tr>
<tr>
<td colspan="2" align="center"><b>Stations: every station and its locations on a map</b> (example stations)<br><img src="docs/screenshots/stations-map.png" alt="Stations map"></td>
</tr>
</table>

On a phone (the menu opens from ☰):

<table>
<tr>
<td align="center"><b>Now</b><br><img src="docs/screenshots/mobile-now.png" alt="Now on a phone" width="220"></td>
<td align="center"><b>Latest</b><br><img src="docs/screenshots/mobile-latest.png" alt="Latest on a phone" width="220"></td>
<td align="center"><b>Species page</b><br><img src="docs/screenshots/mobile-species-page.png" alt="Species page on a phone" width="220"></td>
<td align="center"><b>Stations map</b> (example)<br><img src="docs/screenshots/mobile-stations-map.png" alt="Stations map on a phone" width="220"></td>
</tr>
</table>

---

## Quick start

1. Write **Raspberry Pi OS (64-bit)** to a microSD card with [Raspberry Pi Imager](https://www.raspberrypi.com/software/) (set a user, your WiFi and time zone in its settings).
2. Plug a **USB microphone** into a Raspberry Pi 5 or 4B, insert the card and power it.
3. In a terminal on the Pi (or over ssh), run:

   ```bash
   curl -fsSL https://raw.githubusercontent.com/EvaldoOliveira/BirdSuite/main/newinstaller.sh | bash
   ```

4. Answer a few questions (Enter keeps the suggestion). The Pi reboots by itself.
5. Open **`http://birdsuite.local`** from any device on the same network. The first birds appear within minutes.

---

## Installation

The complete guide is **[docs/installation.md](docs/installation.md)**.

| You have | Do this |
|---|---|
| **A blank card** | [New installation](docs/installation.md#new-installation-on-a-blank-card): one command, about 30 to 45 minutes, no questions: the first visit in the browser opens First Run (station name, location on a map, model, species-name language and list, theme, web password). |
| **BirdNET-Pi, and a spare card** | [New card with Import](docs/installation.md#on-a-new-card-with-import): install BirdSuite on the new card and bring the BirdNET-Pi history with *Data › Backup/Import › Import*. The old card stays untouched as your backup and your way back. |
| **BirdNET-Pi, on the same card** | [Same card with the migration](docs/installation.md#on-the-same-card-with-the-migration): the installer keeps BirdNET-Pi aside (or, short of space, moves its data in), and *System › Status* offers **Go back to BirdNET-Pi** or **Remove the leftovers** during a **30-day test period**. |
| **Many stations to prepare** | [Unattended installation and fleets](docs/installation.md#unattended-installation-and-fleets): a seed file with the answers, and one master card cloned for every station. |

BirdSuite installs into **`~/BirdSuite`**, records into **`~/BirdSongs`** and runs its services as `birdsuite_*` units under the user that installed it.
The repositories are public: no key and no GitHub account are needed, now or for the updates.

---

## Updating

- **One click**: *System › Status* shows the version running and, when there is a newer one, **Update to vX.Y.Z**. The update runs on its own screen with its live output; recording and analysis pause for about a minute.
- **Automatic**: tick **Automatic update** in *System › Status* and choose the days and the time.
- **What's new**: when a release is out, a badge shows on *System* and *Status*, and **What's new** lists the notes of every release in between.
- **Your settings stay**: new settings arrive with their defaults; values you changed are never touched.
- **Rollback**: set `UPDATE_BRANCH` to a release tag in *System › All settings* and update, to return to that release.

Details in [docs/installation.md › Updating](docs/installation.md#updating).

---

## Using BirdSuite

Open the station by its name, **`http://birdsuite.local`** (or `http://birdsuite-<MAC>.local`, the station's own name), from any browser on the network.
The menu shows the pages of the station's **level** (picked at the foot of the menu): **Essential** (watch and hear the birds, the alerts, where the station is, its access and health), **Birder** (+ review, lists, eBird, sharing, backup) or **Expert** (every page); **Show all pages** shows everything in one browser.
The login: on by default, **Station › Access** can let your household in without it, or open the station completely (no password, no dialog).

| Menu | What you find there |
|---|---|
| **Now** | The pictures of the birds singing now. A click plays the song. |
| **Watch** | **Latest** (the latest detections with filters: Uncommon, Rare, Uncertain, Unlikely here), the live **Sonogram** with the names of the birds written on it, and the **Analysis log**. |
| **Review** | Species by species: is every detection right? |
| **Species** | Every species, its thresholds and tier, and its page; the lists **Allowed species**, **Never accept**, **Always accept** and **Night-only species**. |
| **Reports** | **By Hour**, **By Week** and **eBird**. |
| **Alerts** | **What and when**, **Telegram**, **E-mail**. |
| **Station** | Locations, Microphone, Model, Model comparison, Network, Sharing, Themes, Access. |
| **Field** | The **Field page** (the station's state for a phone, without internet) and **Raw Recording** (long recordings for the field). |
| **Data** | Disk Space, Backup/Import, Data, Database. |
| **System** | Status, Stations, Kiosk, Terminal, All settings (with **Start the First Run**). |

The **Live** button at the top of every page plays the microphone while you browse.
Every species name opens the **review player**; every species card icon opens the **species page**.

The **[user guide](docs/user-guide.md)** walks through every page.

---

## Configuration

- **Pages first.** Everything is set from the web pages; you never need to edit a file.
- **System › All settings** lists every setting with its factory default and this station's value, shows only the changed ones at first, puts any of them back with one click, and saves the settings in use as **named sets** to load again later.
- **Files.** The factory defaults live in `factory_defaults.conf` (one line per setting with what it does and its range), the station's own values in `~/BirdSuite/config/BirdSuite.conf`. Everything that is the station's own (settings, lists, locations, notifications, themes, the database) lives in **`~/BirdSuite/config/`**: saving that folder saves the station.
- **History.** Every change of a setting, from a page, an update or the command line, is logged with its old and new value and its origin (secrets masked) in `~/BirdSuite/state/settings_history.log`.
- **Unattended.** An answer file ([`birdnet-setup.conf`](docs/birdnet-setup.conf.example)) on the card sets the new station up without First Run; *Station › Network* downloads one with this station's settings.

---

## Privacy and security

**Nothing leaves the station unless you switch it on**: notifications, BirdWeather, BirdDB-Br and the eBird e-mail are all off until you set them up.

- **Human voices are dropped.** The privacy filter discards the moments where a human voice is heard, with their neighbours. BirdNET+ V3 has no voice class, so the station uses a V2.4 shadow model for it, and *System › Status* says whether the filter works.
- **Test / Playback** never notifies and never uploads.
- **A login on every page.** The web user is `birdsuite`; from any other device every page asks for it (only the station's own calls do not). *System › Status* warns while the factory password is in use.
- **Least privilege.** Recording, analysis and the web pages run as the station user, never as root. The few root steps go through two small root-owned helpers with a closed list of actions; every service runs in a systemd sandbox.
- **Hardened by default.** A firewall that opens only what the station serves, Debian security updates installed daily (never an automatic reboot), ssh limited (no root login, 3 tries, no forwarding beyond the station itself) with repeated failures banned for a while (fail2ban), unused network protocols kept out of the kernel, security headers on every page, and requests from other web sites refused.
- **http, with https only when needed.** The station runs on plain http inside your network, with no certificate warning; https switches on for a moment to read a device's GPS or share a detection, or always with `HTTPS_ALWAYS`.
- **Secrets stay put.** Notification tokens and passwords live in the station's own store, are never shown in pages or logs, and are masked in the settings history.

---

## Languages

- **Interface**: English, Português (Brasil), Español, 简体中文, 日本語, Deutsch, Français, Italiano, Nederlands, Polski and Русский, picked from the **flags under the menu**. The pages, dialogs, notifications and background messages are all translated, and the number format follows the language.
- **Species names**: 37 languages, among them **Portuguese Brazil (CBRO)** and Portuguese Portugal (*Station › Model*). See [species-name coverage](docs/translations.md).
- **Contribute a language**: translations live in `lang/<language>/*.json` with a shared glossary (`lang/GLOSSARY.md`), so a new language is one more folder.

---

## Hardware

| Part | Recommendation |
|---|---|
| **Board** | Raspberry Pi 5 or 4B (the reference hardware). |
| **OS** | 64-bit Raspberry Pi OS: Trixie (reference) or Bookworm. |
| **Storage** | 32 GB or more; *Data › Disk Space* computes the disk your station needs in the long term. |
| **Microphone** | Any USB microphone or USB sound card; an RTSP stream also works. |
| **Power** | The official power supply. *System › Status* warns on under-voltage and heat. |
| **In the field** | With internet: your phone as hotspot; without internet: the station's own WiFi and QR label. |

Useful reading from the BirdNET-Pi community: [suggested USB microphones](https://github.com/mcguirepr89/BirdNET-Pi/discussions/39) and [building your own low-noise microphone](https://github.com/DD4WH/SASS/wiki/Stereo--(Mono)-recording-low-noise-low-cost-system).

---

## FAQ and troubleshooting

- **Something is wrong?** Open *System › Status*: its checks find most problems and fix many with one click.
- **The station does not open by name?** Use `http://birdsuite-<MAC>.local` or the IP address on the QR label and *Station › Network*.
- **Too many false positives for one species?** Give it a manual confidence, or let *Review › Calibrate thresholds* suggest one from your reviews.
- **A certificate warning?** That is the station's own certificate, used only for a moment for the GPS or a share. Click *Advanced › Proceed* once per device.

More questions and answers: **[docs/troubleshooting.md](docs/troubleshooting.md)**.

---

## Contributing

- **Report a problem or propose an idea**: open an [issue in this repository](https://github.com/EvaldoOliveira/BirdSuite/issues/new/choose) (user story, defect or epic). Please attach the logs from *Data › Disk Space › Export logs* and the version shown in *System › Status*.
- **Behaviour that also occurs on a standard BirdNET-Pi** belongs to [Nachtzuster's tracker](https://github.com/Nachtzuster/BirdNET-Pi/issues).
- **Classification performance** of the BirdNET models is a matter for the [BirdNET team](https://github.com/birdnet-team): neither project adjudicates detections.
- **Translations** are welcome: see [Languages](#languages).
- **Share your station** on [BirdWeather](https://app.birdweather.com) and tell the community about your setup.

---

## Licence and credits

[**CC BY-NC-SA 4.0**](LICENSE), inherited from BirdNET-Pi and from the BirdNET models: **non-commercial use only**, share alike, with attribution.
BirdSuite stands on the shoulders of a remarkable line of open work.

### Lineage

| | Project | Contribution |
|---|---|---|
| 🎓 | **[BirdNET](https://birdnet.cornell.edu)** by the [BirdNET team](https://github.com/birdnet-team): the K. Lisa Yang Center for Conservation Bioacoustics at the Cornell Lab of Ornithology and Chemnitz University of Technology | The models that identify the birds: BirdNET V2.4, BirdNET 6K and BirdNET+ V3 (the model of the BirdNET Live app). |
| 🔬 | **[BirdNET-Analyzer](https://github.com/kahst/BirdNET-Analyzer)** by [**@kahst**](https://github.com/kahst) (Stefan Kahl) | The analysis framework BirdNET-Pi was built on. |
| 🐦 | **[BirdNET-Pi](https://github.com/mcguirepr89/BirdNET-Pi)** by [**@mcguirepr89**](https://github.com/mcguirepr89) | The original idea and implementation: a Raspberry Pi that records, identifies and shares bird songs around the clock, with its web interface, live stream and charts. |
| 🛠️ | **[BirdNET-Pi](https://github.com/Nachtzuster/BirdNET-Pi)** by [**@Nachtzuster**](https://github.com/Nachtzuster) | The maintained continuation BirdSuite forked from (upstream commit `88985a3`, v0.11 line, 2026-08-29). |
| 🌿 | **BirdSuite** | This edition: long-term monitoring, review and curation, locations and stations, BirdNET+ V3. |

<details>
<summary><b>Nachtzuster's improvements over the original BirdNET-Pi</b> (all inherited by BirdSuite)</summary>

- Backup and restore.
- A much more responsive web interface.
- Daily charts with every species, not only the top and bottom ten; swipe events on them (by [@croisez](https://github.com/croisez)).
- A newer Apprise, so more notification services.
- Support for the species range model V2.4 - V2.
- Bookworm and Trixie support.
- Transient files on tmpfs (experimental).
- Analysis, server and extraction consolidated into one, more robust and slightly more efficient, especially with many recordings.
- `tflite_runtime` 2.17.1, which is faster.
- The chart viewer running as a daemon, avoiding its expensive start-up.
- Lots of fixes and clean-ups.

</details>

### Built with

- [TensorFlow Lite](https://www.tensorflow.org/lite) runtime builds by [**@PINTO0309**](https://github.com/PINTO0309) ([TensorflowLite-bin](https://github.com/PINTO0309/TensorflowLite-bin)).
- Localised species names thanks to [**@patlevin**](https://github.com/patlevin) and Wikipedia ([coverage per language](docs/translations.md)).
- [Caddy](https://caddyserver.com) (web server), PHP, [SQLite](https://sqlite.org), [Icecast](https://icecast.org), [SoX](https://sourceforge.net/projects/sox/) and [FFmpeg](https://ffmpeg.org) (audio), [Apprise](https://github.com/caronc/apprise) (90+ notification services), [GoTTY](https://github.com/yudai/gotty) ([x86 fork](https://github.com/sorenisanerd/gotty)) and [Midnight Commander](https://midnight-commander.org) (web terminal and file browser), [Adminer](https://www.adminer.org) (database editor), [phpSysInfo](https://github.com/phpsysinfo/phpsysinfo) (hardware details), [Chart.js](https://www.chartjs.org) (charts) and [Leaflet](https://leafletjs.com) (maps).

### Data sources

<details>
<summary><b>Where the station's reference data comes from</b></summary>

- **Species pictures**: [Wikipedia](https://www.wikipedia.org) (the only source), shown with each picture's author and licence.
- **Reference recordings**: [xeno-canto](https://xeno-canto.org), the best recording of each species and the links to its recordings, with recordist and licence.
- **Species type and region** of the list filters: [GBIF.org](https://www.gbif.org): the GBIF Backbone Taxonomy (class of each label) and GBIF occurrence records (share of records per continent; *Global* = four or more continents, *Ocean* = mostly records at sea), queried through the GBIF API by `scripts/build_species_info.py`. GBIF data is published under CC0 / CC BY licences by its data publishers.
- **Field-guide order** of the species tables: the [eBird/Clements taxonomy](https://www.birds.cornell.edu/clementschecklist/) (Cornell Lab of Ornithology), its order and family sequence (`scripts/build_taxonomy_order.py` → `model/taxonomy_order.csv`).
- **Brazilian state lists and names**: [WikiAves](https://www.wikiaves.com.br) records per state, with the names of the [CBRO](https://www.cbro.org.br) checklist (Comitê Brasileiro de Registros Ornitológicos).
- **Biome and ecoregion** of each location: [RESOLVE Ecoregions 2017](https://ecoregions.appspot.com/) (Dinerstein et al. 2017, *An Ecoregion-Based Approach to Protecting Half the Terrestrial Realm*, BioScience 67:534), CC BY 4.0; in Brazil also the IBGE [Biomas do Brasil](https://www.ibge.gov.br/geociencias/informacoes-ambientais/estudos-ambientais/15842-biomas.html) 1:250,000 (2019). Both simplified and shipped with the station (`model/maps/`).
- **Land cover** of each location: [ESA WorldCover 2021 v200](https://esa-worldcover.org/) (Zanaga et al. 2022, 10 m, CC BY 4.0), read online from its public AWS Open Data copy (`scripts/land_cover.py`).
- **Weather** in the eBird checklists: [Open-Meteo](https://open-meteo.com).
- **Did the station move?**: the position from the WiFi networks around (Google Geolocation with a key, else [BeaconDB](https://beacondb.net)) or the public IP ([ipapi.co](https://ipapi.co), then [ip-api.com](https://ip-api.com)); place names and countries from [OpenStreetMap](https://www.openstreetmap.org) reverse geocoding.
- **Maps**: [OpenStreetMap](https://www.openstreetmap.org), OpenTopoMap and Esri imagery online; offline, the [Natural Earth](https://www.naturalearthdata.com) 1:110m countries (public domain).
- **Precision thresholds**: the logistic threshold method of Wood & Kahl (2024, *Journal of Ornithology*).

</details>

### From the BirdNET-Pi community

- [BirdWeather](https://app.birdweather.com): birds heard by stations around the world.
- The BirdNET-Pi [wiki](https://github.com/mcguirepr89/BirdNET-Pi/wiki) and [discussions](https://github.com/mcguirepr89/BirdNET-Pi/discussions): [some theory on classification](https://github.com/mcguirepr89/BirdNET-Pi/wiki/BirdNET-Pi:-some-theory-on-classification-&-some-practical-hints), [sharing your installation](https://github.com/mcguirepr89/BirdNET-Pi/wiki/Sharing-Your-BirdNET-Pi), [adjusting your sound card](https://github.com/mcguirepr89/BirdNET-Pi/wiki/Adjusting-your-sound-card), [privacy concerns and options](https://github.com/mcguirepr89/BirdNET-Pi/discussions/166) and [setups shared by users](https://github.com/mcguirepr89/BirdNET-Pi/discussions/69).
- BirdNET-Pi as a [Home Assistant add-on](https://github.com/alexbelgium/hassio-addons/blob/master/birdnet-pi/README.md) and a [Docker container](https://github.com/alexbelgium/hassio-addons/blob/master/birdnet-pi/README_standalone.md), by [@alexbelgium](https://github.com/alexbelgium) (they run BirdNET-Pi, not BirdSuite).
- In the wild: [Marie Lelouche's *Out of Spaces*](https://www.lestanneries.fr/exposition/marie-lelouche-out-of-spaces/), BirdNET-Pi in post-sculpture VR ([press kit](https://github.com/mcguirepr89/BirdNET-Pi-assets/blob/main/dp_out_of_spaces_marie_lelouche_digital_05_01_22.pdf));
  [research on networked BirdNET-Pi stations for farming](https://github.com/mcguirepr89/BirdNET-Pi-assets/blob/main/G23_Report_ModelBasedSysEngineering_FarmMarkBirdDetector_V1__Copy_.pdf);
  build guides from [PixCams](https://pixcams.com/building-a-birdnet-pi-real-time-acoustic-bird-id-station/) and [Core Electronics](https://core-electronics.com.au/projects/bird-calls-raspberry-pi);
  the [Raspberry Pi blog](https://www.raspberrypi.com/news/classify-birds-acoustically-with-birdnet-pi/) and [The MagPi issue 119](https://magpi.raspberrypi.com/issues/119/pdf).

---

<p align="center"><sub>A lucky ducky with a spare Raspberry Pi? <a href="https://foldingathome.org/">Try Folding@home!</a></sub></p>
