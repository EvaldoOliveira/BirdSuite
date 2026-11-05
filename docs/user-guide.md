# BirdSuite user guide

A tour of every page, in menu order, with the details the README leaves out.
Pages marked 🔒 ask for the login when they open; every change on any page asks for it.

- [The interface](#the-interface)
- [Now](#now)
- [Latest](#latest)
- [Sonogram](#sonogram)
- [The review player](#the-review-player)
- [Sharing a detection](#sharing-a-detection)
- [Species](#species): [Review](#review), [Species](#species-1), [the species page](#the-species-page)
- [Reports](#reports): [By Hour](#by-hour), [By Week](#by-week), [eBird](#ebird)
- [Station](#station)
- [Alerts](#alerts)
- [Species lists](#species-lists)
- [Field](#field)
- [Data](#data)
- [System](#system)
- [Locations and stations](#locations-and-stations)
- [One place, one station](#one-place-one-station)
- [The life cycle of the sounds](#the-life-cycle-of-the-sounds)
- [Where the station keeps its files](#where-the-station-keeps-its-files)

---

## The interface

**Menu.**
Now · **Watch** (Latest, Sonogram, Analysis log) · Review · **Species** (Species, Allowed species, Never accept, Always accept, Night-only species) · **Reports** (By Hour, By Week, eBird) · **Alerts** (What and when, Telegram, E-mail) · **Station** (Locations, Microphone, Model, Model comparison, Network, Sharing, Themes, Access) · **Field** (Raw Recording) · **Data** (Disk Space, Backup/Import, Data, Database) · **System** (Status, Stations, Kiosk, Terminal, All settings).

- **Level** under the menu: **Essential**, **Birder** or **Expert**, the pages the menu shows on this station (a change asks for the login); **Show all pages** shows every page in this browser.
- Under it, who is using the station, with **Log in**, **Switch user** and **Log out**.
- The **language flags** under it switch the whole interface.
- **«** collapses the menu to a narrow column; on a phone the **☰** button opens it.
- Every page has its own address, `/?view=<English menu name>` (e.g. `/?view=Species`; the old names still open their pages), so pages can be bookmarked and opened in new tabs.

**Header.**
The station logo and name, the BirdSuite wordmark and the **Live** button (▶ / ■ with a volume slider): listen to the microphone on any page while it keeps working.
With several stations the station name opens the [station list](#locations-and-stations); with several locations the location name beside it opens the location list.

**One standard on every page.**
The same sortable tables (numbers and dates largest first, text A to Z, accents ignored), the same pager ("Last 10 / 25 / 50 / 100", Newer, Older), the same period control (◀ picklist ▶), and the **Filter** and **Sort** icons always at the right edge of their line.

- **The Filter icon rule**: folding the filter line switches its filters and search off, so the list shows everything; unfolding brings back exactly the same filters. The icon is filled while the line is open.
- **Set as default** (a pill with a dashed border) appears only while the controls beside it differ from the station default, and saves them.
- A **yellow field** differs from its factory value.
- Every question is the app's own dialog; for anything destructive **No** is the default.
- Pictures are always whole, never cropped (the Now wallpaper is the one exception).
- Everything is reachable with the keyboard (WCAG 2.2 AA).

---

## Now

The start page: the **picture of every bird singing now**, one card per species, the latest singer top left.
A species that sings again moves to the front with a short glow and starts its period over; the page updates by itself.

- **Period** (last 1, 5, 15 minutes, 1, 4, 8 or 12 hours) and **Minimum confidence** (10 to 90 %).
- **Pictures only** (the default) or each card with its name, time and confidence; a **Size** slider (120 to 520 px).
  These four choices are kept in each browser.
- A click on a picture opens the [review player](#the-review-player) on that species' newest detection; closing it returns to Now as it was.
- Detections answered No / Ignore, with a cause (Human, Noise) or Review later never show.
- A species without a picture shows the BirdSuite logo; "Recording stopped." shows while the recording is off.
- **Wallpaper**: the background icon sets a picture of your garden behind the bird pictures, **one per location** (upload, reuse any saved picture, clear, and a **Prominence** slider). With the location picker on *All*, the change goes to every location except Test / Playback.

---

## Latest

The latest detections, the state of the analysis and its settings at hand.

- **Analysis line**, refreshed every 5 s: "hh:mm:ss, Waiting: N, Delay: Ns" (the recording being analysed, recordings waiting, how far behind real time); it turns to the warning colour when the analysis is stopped or falls behind.
- **Views**: **Simple** (time, species, confidence, probability, review), **Full** (adds the settings in force when each detection was made) and **Sonogram** (cards with each clip's sonogram, and **Now Recording**, the picture of the recording being analysed).
- **Filters** (combinable): **Uncommon** (heard at most N times since yesterday), **Rare** (not heard in the N days before today), **Low Conf**, **Low Prob**; period Today / 7 days / All; a search box (common, scientific or folder name, accent-insensitive; `NOT text` leaves matches out).
- **Param.**: Min. Conf., Min. Prob., Sensitivity, Recording length and Overlap, one click away, applied from the next recording.
- **Set as default** saves the view, count, period and filter values for the station.
- When the clock is not confirmed (no internet since the boot), a warning offers **Use this device's time**.

---

## Sonogram

A live waterfall of the microphone, scrolling right to left, with the **name of each new detection written at the moment of its sound**.

- **Palette** (Turbo by default, Inferno, Roseus, SoX heat, Classic, Viridis, Ocean, Grayscale), **Contrast**, **Floor** and **Range**, tuned while it runs; **All settings** puts them back.
  These colours drive **every sonogram picture** of the station: clips, the review player, shares.
- **Height** (% of the page), an **RTSP stream** picker (when the station records streams), **Silent**, **Compression** and **Freq shift** (pitch-shifts the live audio for listeners who do not hear high calls).
- Where the browser cannot draw the stream (iPhone), the picture of the recording being analysed shows instead.

---
- **🦻 (the ear beside Live Audio, at the top of every page)**: for hearing loss, the frequency lowering a hearing aid or a SongFinder does, switched for this device only. On, what goes to its speaker is lowered: the live sound (in the browser) and every clip played (Now, Latest, Review). The recordings and the analysis never change. While it is on, the gear at its left opens two scales: **Real**, with the band of the songs you care about (its bottom cuts wind and traffic below it), and **You hear**, the same band lowered; drag it down and it shrinks with it. Saved when you let go (the band is the station's). The Sonogram and the review player then show the real frequency on the left and **You hear** on the right.

## The review player

One player everywhere: every species name and Review button of every list, the Now pictures, Review and the species pages open it.

- The clip's sonogram with its play bar, looping; **Loop 3 s** repeats only the detected seconds; speeds 0.5× to 2×.
- Tools: **File** (the clip's folder in your own file manager over SFTP), **Palette**, **Audio**, frequency shift, **Share**, delete to the Removed folder, protect from purge.
- Answers: **Yes, this bird**, **Change species** (the station's model proposes the species it hears in the clip, or search any species), **Review later**, **Clear the review**, the quick causes **Human** and **Noise**, and **No / Ignore**.
  A cause can be applied to the other unreviewed detections of the same species in that hour.
- Keys: **Y** yes, **N** change species, **R** review later, **X** no / ignore, **C** clear, **Z** undo, **← →** previous / next, **Space** play / pause, **L** loop. On a phone: swipe right = Yes, left = Change species.
- **Undo** for 5 s after every answer; **Skip reviewed** jumps over answered detections; progress marks show where you are.
- Nothing leaves the database in a review: every answer is kept, and every Review button names it (✓ Yes / Changed, ✗ cause, ? Review later).

---

## Sharing a detection

The **Share** icon of the review player sends the song, its sonogram and a short text (name, date and time, location, confidence, station) through the phone's share sheet: WhatsApp, Telegram, e-mail.

- Where the phone can, the song goes as a **short video** (the sonogram over the audio) with the text as its caption.
- Otherwise the audio, the picture, a WhatsApp text and downloads are offered.
- Firefox on Android shares only text: **Open in Chrome** opens the same window there.
- On an http station the share switches https on for a few minutes in a small window (the browser warns once about the station's own certificate).

---

## Species

### Review

How far the review has gone, species by species, and the way through what is left.

- **Period**: Last 24 h / 7 days (default) / 30 days / Date range / All.
- **Cards**: reviewed detections, species confirmed and validated, Yes, Changed, Review later, Ignored.
- **Table**: per species the detections, highest confidence, manual confidence, the location model's probability this week, Confirmed / Whitelist / Excluded, the **Notification** tier, and the review progress.
- **Seven colour-coded groups**, in order: *Low Prob.*, *Not reviewed*, *Yes*, *Changed*, *Review later*, *Others* (excluded species), *Ignored*. Chips hide or show each.
- **Start review** walks through every unanswered detection of the period, species by species; the first Yes confirms an unconfirmed species.
- **Last reviews** lists your latest answers, each with **Undo**.
- **Calibrate thresholds** suggests a manual confidence for every species whose reviews are enough, at a precision target of 80, 90 or 95 %; tick, adjust and **Apply** them in one go.
- **Delete** moves every detection of a species to the Removed folder (restorable in *Data › Data*).

### Species

Every species detected (or **All** the station can detect), with count, days, clips on disk, confidences, **Manual Conf.**, the probability of occurrence, the **Notification** tier (Normal, Muted, Rare) and Confirmed / Whitelist / Excluded, each editable in place.
Sorted by count, **taxonomy** (field-guide order) or A to Z.

### The species page

Everything about one species in one place:

- its **picture from Wikipedia**, whole, with its author and licence (kept on the station and refreshed every few weeks), and the sonogram of its **best recording on xeno-canto**;
- links to **WikiAves** (with Brazilian names), **Wikipedia**, **eBird**, **Birds of the World** and **xeno-canto** (recordings of the location's country, best first);
- cards: what the location model **expects here by week**, the **last 12 months** as a calendar, the weeks of all years and the **time of day**;
- **Species Settings**: Manual Conf., Min. Prob., **Files to keep**, notification tier, Confirmed / Whitelist / Excluded, and restore or purge of deleted detections;
- the **Reviews** card, the **best detections** and the **latest detections**;
- **Precision**: from which confidence, and which probability of occurrence, the detections are real.
  Right (Yes) against False (the causes and changed species) at every threshold, weighted by how many detections each confidence band really has (a stratified estimate), with a guaranteed value (95 % lower bound) and the real detections each threshold would lose.
  The suggestion is the lowest threshold that reaches the target with at least 20 reviews and 80 % of the detections covered, shown beside the logistic threshold of Wood & Kahl (2024, *Journal of Ornithology*); **Apply** sets it.
  *Where to review* lists the confidence bands still waiting, the most uncertain one starred.

---

## Reports

### By Hour

Every species by **half hour of the day**, as a heat table, over Today / Last 7 days (default) / Last 30 days / a date range / All.

- Four cards: **Detections** (with the change against the previous period and a sparkline), **Confirmed species**, **Validated species**, **New species**.
- A click on a row shows only that species (a chip returns to all); sorted by count, taxonomy or A to Z; **CSV** download.
- Under the table, **Occurrences by calendar week** over every date recorded.

### By Week

Every species by **ISO calendar week** of the year, over This year / Last 2 years / a year range / All years (default): residents, migrants, arrivals and departures at a glance.

- The same four cards and controls as By Hour.
- A cell opens By Hour for that species and that week; weeks without any recording are light grey.

### eBird

Turn detections into eBird checklists.

- The species of a **day, week or month**, filtered by **All**, **Confirmed** (by ear in the review player) or a **Minimum confidence**, one column per day.
- **eBird file**: the eBird Record Format for *eBird › Submit › Import data*, one Stationary checklist per day and location, birds only, with the weather per species from Open-Meteo.
- **Sounds (zip)**: the best clips confirmed by ear of each species.
- **Send by e-mail**, and a **periodic e-mail** (daily or weekly, at a chosen time, named by a template).

---

## Station

- **Themes**: six themes (**Ocean**, Dark, Forest, Blossom, White & Grey, Red) and any number of your own, six colours each with a live preview; apply to the current location or as the system default.
- **Locations** 🔒: the places of the station, with a map, radius, time zone, species list and theme each; **Make current**, order, remove; the boot check of [Did the station move?](#locations-and-stations).
  With one station its **name** is set here too.
- **Model** 🔒: the **Official** and **Shadow** models with their confidence, sensitivity, minimum probability and overlap; **Swap Models**; the **defaults table** (BirdSuite, Cornell, BirdNET-Pi values for each model) with **Use**; **Avoid duplicity**; the **Privacy Threshold**; the Species List Tester (V2.4); the species-name language and the species information site.
- **Model comparison**: the official and shadow models side by side, per species (found by both, only one) and record by record, a click to listen.
- **Microphone** 🔒: the microphones with their USB ports, the one in use, the live sonogram and the gain; the audio source (sound card, channels, **RTSP streams**); the audio loss (the last 24 hours, discarding detections that lost audio, the link to check them). The recording length is in *Model*, the clips' length and format in *Data*.
- **Network**: known WiFi networks, the **station WiFi** (in the field without internet) with its *WiFi in the field* mode (always, cycle, off), the **phone hotspot** (in the field with internet), the printable **QR label**, *Use this device's time*, *Identify (blink LED)*.
- **Sharing** 🔒: [BirdWeather](https://app.birdweather.com) (one token, or one per location) and, when activated, the BirdDB-Br contribution.
- **Access** 🔒: the web user name and password (Show, change, remove) and an optional custom URL.
  **Web login: On / Off** (Off = an open station: no password and no login dialog anywhere), **On your network** (every page asks, or only my household: viewing, reviews and lists without the login) and **How will you recover it?** (a reset link by e-mail or Telegram, or the SD card file `reset-web-password`).

---

## Alerts

- **E-mail** and **Telegram**: guided step by step (a Gmail or a new Gmail for the station; a Telegram bot from zero, your own bot, groups found as soon as the bot joins them).
  A channel sends only after you confirm its test arrived, and has an on / off switch.
- **What and when** 🔒: when a Normal species notifies (each detection with a **repetition limit**, the first of the day, infrequent species; one message with every reason), the default tier, and the texts of the **Normal** and **Rare** messages with their variables (`$common_name`, `$conf_pct`, `$station`, `$location`, …) and their **quiet hours**.
- **Tiers**: each species is **Normal**, **Muted** or **Rare**. Rare species notify every detection to the Rare channel (a second Telegram group, or e-mail); **region-rare alerts** send species the location model does not expect here ("vagrant here", "out of season here") to Rare with the reason.
- Messages carry the **clip** and the species picture. Made offline, they wait and go out with their original time.
- The station also tells you when its **address changes**, when it **moved**, when the **microphone** is removed or reconnected and when the recording watchdog restarted the recording.
- **Test / Playback** never notifies.

---

## Species lists

In the **Species** group. **Allowed species**, **Never accept**, **Always accept** and **Night-only species** share one layout: the species still available beside the list, filtered by group (birds, mammals, amphibians, insects, domestic) and by continent, from [GBIF](https://www.gbif.org).

- **Allowed species**: the include list of the current location: none, one of your saved lists, or a **Brazilian state** built from its WikiAves records with CBRO names.
- **Never accept**: never detected. **Always accept**: detected even when the location model does not expect them.
- **Night-only species**: species that only sing at night are dropped by day, from N minutes after sunrise to N minutes before sunset at the location (30 / 30 by default, today's times shown).
- Every list can be saved under a name and loaded again.

---

## Field

- **Field page**: the station's state for a phone, in large text: recording, the last detection, the disk, the power, the clock and how the phone is connected, with **Start / Stop recording**, **Raw Recording** and **Shut down safely**; it works over the station WiFi without internet and refreshes every 30 seconds.
- Without internet every page says so under its title: maps, species pictures, alerts and updates wait, and the alerts go out later with their original time.
- **Raw Recording**: long unprocessed WAV recordings of the **dawn chorus** on chosen days, once or recurrent, with start, end and segment length, made **while the analysis keeps running**; **Record now**, **Stop recording**, and the 30 newest sessions. Raw files are never deleted automatically.
- The group shows at the **Expert** level, and at every level on a station that runs outdoors (First Run: *Where will it run?*).

---

## Data

- **Disk Space**: what fills the card over the last 90 days and the next 10 projected, a **capacity forecast**, the **disk size needed in the long term** with two columns to simulate other values and the **smallest disk that fits**, and the **logs** (sizes, how long the journal is kept, **Export logs**, **Clean now**).
- **Backup/Import**:
  **Export / Backup** of one location or all, full or **incremental**, to any folder of the folder tree (a NAS share, a USB disk), plus **Export to BirdNET**;
  **Import** of a BirdSuite backup or a BirdNET-Pi folder;
  **Settings**: Backup settings and Restore settings.
  Jobs run in the background with **Pause**, **Continue** and **Cancel** (Cancel undoes them).
- **External files** (linked from Backup/Import): analyse the recordings of a folder (portable recorder, AudioMoth, phone) with the station's model **as recorded at** a location of the station or a new one; the results are saved **in that folder**; listen to the top 50 of each species; **Import** files them at that location if you want.
- **Data**: per location the detections, deleted and ignored ones, with **Delete all**, **Restore**, **Purge Removed**, **Purge Ignored**, **Move to location** and **Move to station**; **Remove by period**; the **life cycle of the sounds** with its settings; the Danger zone (**Clear ALL data**, **Factory settings**).
- **Database**: each database with its size and row counts; **Check**, **Optimise**, **Download** (a consistent copy while the station runs) and the **Advanced editor** for SQL.

---

## System

- **Status**: the version with **Reboot**, **Update**, **Shutdown**, **Automatic update** and **What's new**; the **Services** table (Stop / Restart / Disable / Enable, the experimental RAM drive, Restart Core Services); the **Checks** (microphone, recording, analysis backlog, recording watchdog, privacy filter, detections, disk, model, notifications, web password, file permissions, last backup, clock, power, temperature), each with a one-click fix where possible and also as JSON for monitoring; the **Migration** section after a same-card migration.
- **Stations** 🔒 (with several stations): see [Locations and stations](#locations-and-stations).
- **First Run** (the button **Start the First Run** of *All settings*; a new station opens on it by itself): six steps, one save: your language, where is the station, how you will use BirdSuite (and where it runs), a microphone test on the live sonogram, the alerts, and how the station is protected. A choice writes only settings still at their factory value.
- **Kiosk** (when activated with `ACTIVATE_KIOSK` in *System › All settings › Other*): today's detections for a wall screen in its own tab: six indicator cards, the latest 40 with their pictures and a plain player, **Load 40 More**, no menu, nothing that changes data, no login.
- **Terminal**: a terminal in the page, with a system user's login.
- **Files**: Midnight Commander in the page.
- **All settings** 🔒: every setting with its factory default and this station's value; **Show only changes**, a **Default** button per row, and **named sets** of settings (Save, Load set, Delete, Factory defaults).
  Each group of settings links to the page that sets it.
- **Analysis log** 🔒 (in *Watch*): the live log of the station's services, with log levels for Recording, Sonogram and Live audio.

The night clean-up, the disk check, the watchdog and the automatic update write their outcome to the system log with tags `birdsuite-*` (e.g. `journalctl -t birdsuite-disk_check`).

---

## Locations and stations

**Locations.**
Take the station to the farm or on a trip and each place keeps its own records.
Every detection is tagged with the location it was recorded at, so two locations can even share the same coordinates (a microphone at another window).

- The current location's name sits beside the station name; a click on another one starts the **change of location**: stop the recording, analyse what was already recorded at the old place, switch, start again, each step shown in a window.
- Pages with detections have a **Loc:** picker (the current location, any other, or **All**).
- Each location has its own species list, colour theme and Now wallpaper.
- Two built-in locations: **Home** and **Test / Playback** (red theme, for tests and for playing recordings to the station: nothing is notified or uploaded, and its detections stay apart).
- Each location knows its **biome and ecoregion** (RESOLVE 2017; the IBGE biome in Brazil), its **land cover** (ESA WorldCover) and its **setting** (Urban, Peri-urban, Natural); every detection carries them, so exported data can be filtered by them.

**Did the station move?**
At every boot the station compares its network position (WiFi networks around it, else the public IP) with its coordinates.
After a move farther than the location's radius and the *Minimum distance* (30 km by default), it notifies you and every page asks: **Use this location** or **Not moved** (in simple mode, **Update this location** or **New location**).
Nothing changes without your answer.
Switch the check in *Station › Locations* (*Detect at boot whether the station changed location*).

**Microphone reconnected.**
With several locations, after the USB microphone is plugged back every page asks *Where is the station now?* for five minutes; no answer keeps the same place.

**Stations.**
With more than one station, click the station name at the top of any page: a click on another one opens **the same page on the other station**.
Each station keeps several addresses (home network, VPN, host name) and the first that answers is used.
*System › Stations* registers them (Test, order, **Sync with a station**, **Search the network**, export / import) and shows them on a **map**: green when they answer, red at their last known position, their other locations in yellow (an offline world map without internet).
A station is identified by the MAC of the Pi's own network port, so two stations may share a name.


**Fleet.**
*System › Stations* (with several stations) shows each station's health in the **Fleet** table: whether it answered, its version, recording, last detection, disk, clock and internet, read by your browser from each station with the login saved for it.
New stations are set up the same way with the **answer file** of *Station › Network*: it carries the level, the First Run's answer, where the stations run, the access and, ticked, this station's changed settings as a profile (never a password, token, coordinate or name).
---

## One place, one station

The First Run asks whether you will use several locations and several stations (with *Research or field work*).
With two "no" answers (the default) the interface leaves out what you do not need: the location picker and list, the location in the header and the lists, *System › Stations*, *Move to location* and *Move to station*.
Nothing is lost: *System › All settings* (section *Locations and stations*) or *First Run* switches them on again.

---

## The life cycle of the sounds

Every detection leaves a short clip and its sonogram (stored as JPEG, about a quarter of a PNG) and a database row.
The limits remove only the sound: **the detection keeps counting** in every statistic.

**Never removed automatically**: clips with a padlock, clips answered **Yes** or **Review later**, and the **best N of every species** (`PURGE_PROTECT_TOP_N`, 50 by default).

**Every night (02:00)**:

1. Clips answered Human, Noise or No / Ignore move to a purge folder and are deleted after `IGNORED_PURGE_DAYS` (5); until then they still play, and changing the answer brings them back.
2. Each species keeps its `MAX_FILES_SPECIES_DAY` (50) best clips of each day.
3. Outside the last `PURGE_RECENT_DAYS` (3) days, each species keeps its best `MAX_FILES_SPECIES` (100) clips, or its own **Files to keep**.

**When the disk is full** (`PURGE_THRESHOLD`, 70 %): **Purge** the oldest unprotected clips (the default), **Keep** (nothing removed; a full disk stops the recording) or **External backup** (the clips are moved to a NAS share or USB disk chosen in the folder tree; when that folder cannot be used, the station follows `FULL_DISK_FALLBACK`: Keep or Purge).

**Manual removal** (the review player, Review, Species, *Delete all*, *Remove by period*) moves clips to the Removed folder, restorable in *Data › Data* until **Purge Removed**.

---

## What every detection keeps

The database row and the clip's tags (`metaflac --list`, any player's tag view): species, time, confidence, the settings in force (minimum confidence, manual confidence, sensitivity, overlap, location threshold, recording length), the location with its biome and land cover, the **microphone**, its **USB port** and its **gain**, the **time zone** and UTC offset, the **model**, the **BirdSuite version**, the **species list**, the **audio lost** by the recorder in that recording, sample rate and channels, and a **scenario id**.
The id names `config/data/scenarios/<id>.json`: every setting (never a password, token, key, notification address or share link), the contents of the lists in force, model, version, time zone and microphone, written the first time it is used.
*Station › Microphone › Audio loss* shows the audio lost in the last 24 hours and can drop a detection that lost more than a limit inside its own seconds (off by default, 20 ms).
A raw recording's `.recording` file has the same: device, rate, format, channels, microphone, port, gain, the analysis settings, the species list, the scenario id and, at its end, the audio lost in the session.

## Where the station keeps its files

- **`~/BirdSuite/config/`**: everything that is the station's own: the settings (`BirdSuite.conf`, beside the factory defaults `factory_defaults.conf`), saved settings sets, custom themes, species lists and per-species thresholds, notification texts, locations and their wallpapers, the other stations, the database (`data/birds.db`) and the station layer. Saving this folder (plus the audio, if wanted) saves the whole station.
- **`~/BirdSuite/state/`**: the running state (jobs, the offline notification queue, logs). Not settings, so not backed up.
- **`~/BirdSongs/`**: recordings, clips and spectrograms.

**Settings history.**
Every change of `BirdSuite.conf`, whoever made it (a page, a loaded set, an update, a service, the command line), becomes one line of `~/BirdSuite/state/settings_history.log`: time, setting, old and new value, and the web request that made it.
Passwords, tokens and addresses show as `***`.
