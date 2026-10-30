# BirdSuite — Roadmap

*2026-10-18. Goal (owner): **one complete platform to identify birds, record them and build the bird list of a place (garden) or an event (outing)** — live monitoring, surveys, scheduled monitoring and every management and identification task over visual, audio and photo records and lists.*

This file lists:
1. what BirdSuite **already has** (the current station code is the functional reference);
2. what is **worth building** (the core of the platform);
3. what is **optional**, because of high effort or because it moves away from the product's focus.

Each item becomes an issue on the BirdSuite board when picked.

---

## Product in one sentence

**BirdSuite helps a birder identify, record and list every bird of a place or an outing — automatically from sound, assisted from photos, by hand from sightings — and keeps everything curated, organised and shareable.**

### Principles
1. **Every record is an observation** — one data model for all evidence:
   - automatic sound detection;
   - field recording;
   - photo;
   - seen;
   - heard by the birder.
2. **Every observation belongs to an activity** (garden, outing, live session, survey, schedule, test) **and a place**.
3. **The birder decides what counts.**
   - The machine captures wide and suggests.
   - The owner's curation is authoritative.
   - Test and playback never enter the lists.
4. **Lists and trips are the product:** garden, outing, trip, year, life and survey results — always current and exportable.
5. **Local-first and private:** every device recognises on its own; the Pi is the always-on station; devices sync with each other; the cloud is optional.
6. **Taxonomy-correct:** CBRO / Clements / IOC / AviList through one crosswalk backbone.

### Core concepts

| Concept | Meaning |
|---|---|
| **Place** | A geographic unit (boundary/radius) with its own species list: garden, farm, park trail |
| **Activity** | A bounded way of collecting records: Garden (continuous), Outing, Live session, Survey, Scheduled monitoring, Test |
| **Observation** | One species, time and place inside an activity, with one or more pieces of evidence |
| **Evidence** | A sound clip (auto/manual), photo, video, note or sighting |
| **Identification** | Who or what proposed the species, and with what confidence (model, photo model, birder, curator) |
| **Curation state** | Suggested → confirmed / rejected / needs review; counts or does not count |
| **List** | A species set derived by rules: place, checklist, year, life, survey table, targets |
| **Station** | A device producing evidence: Pi station, phone, portable recorder, camera |
| **Taxon** | Reference species mapped across CBRO ↔ Clements ↔ IOC ↔ AviList ↔ Avibase |

### Product decisions (owner, 2026-10-18)

- **A new product, written from scratch.** The ideas built into the owner's station (BirdNET-Pi++) are the functional reference; no code is carried over.
- **Recognition: BirdNET V3 only, to start** — the ONNX audio model (32 kHz, ~9,800 classes) plus its geo model (species expected at this place and week). Behind a recogniser interface so other models can be added later.
- **Platforms: Android, iPhone, macOS, Windows and Raspberry Pi.**
- **Two complementary products:**

  | | **BirdSuite Listen** (simple) | **BirdSuite** (the platform) |
  |---|---|---|
  | **Job** | Hear and identify — uses the model | Use the data — curate, list, plan, report |
  | **Does** | Live listening, recognition with the geo filter, record clips, history of what was heard, Pi station 24/7 | Places and activities, curation (My Garden, review inbox), manual records, lists (garden, outing, year, life), taxonomy, trips, photos, reports, eBird export |
  | **Input** | Microphone (+ BirdNET V3) | Detections from Listen (any device or station) + photos + sightings + imports |
  | **Stands alone?** | Yes — a complete small app | Yes — also works with manual records and imports only |
  | **Together** | Listen sends its detections to BirdSuite through an open detection format/API; BirdSuite sends back the place list and curation (confirmed / rejected / test) so Listen filters better | |

  Listen is small, quick to ship and the entry point; BirdSuite is where the value of the data grows. The open detection format also lets BirdSuite import from other stations and recorders.
- **Proposed architecture (to confirm):**
  - **One codebase:** Flutter for the UI on all five platforms (Linux ARM64 for the Pi).
  - **One core library** (data model, curation, lists, taxonomy, sync), shared by every platform.
  - **On-device recognition** with ONNX Runtime (Android, iOS, macOS, Windows, Linux ARM64). No server needed for identification.
  - **Pi = station mode:** the same core runs headless 24/7 (capture, recognise, store) and serves the phones and computers on the LAN; the Pi screen/browser UI is optional.
  - **Phone and computer:** Listen for live listening; BirdSuite for outings, curation, lists and trips; both sync with the Pi station when at home.
  - **Shared code:** both products use the same core library; Listen ships the recognition module, BirdSuite the data modules.
  - **Storage:** SQLite on every device; sync by observation id (last-writer-wins on curation, append-only on evidence).

---

## 1. Already have

These already work in the current station code (BirdNET-Pi based, running on the pilot station). For a clean-room build, the code is a functional reference only.

**Recording and recognition**
- 24/7 recording with a USB microphone via ALSA (no PulseAudio), on Debian 13 / Python 3.13.
- Microphone hot-plug: the station stops when the mic is unplugged and restarts when it returns.
- Sound recognition with two models side by side: official + shadow (BirdNET V2.4 and BirdNET+ V3.0 ONNX).
- One settings block per model: confidence, sensitivity, location threshold, overlap, and a Swap button.
- **Model Comparison page:** the two models matched by moment and species, with agreement and "only one" views.
- Parallel field recorder on the same microphone (audio "fork").

**Lists, regions and names**
- Regional include lists by Brazilian state (WikiAves occurrence + CBRO names), selectable in Basic Settings.
- Two Portuguese locales: pt_BR (CBRO + station additions) and pt_PT.
- Per-species confidence overrides (species_confidence) and exclude/whitelist lists.

**Notifications**
- Species tiers normal / notify / rare, each with its own notification policy.
- New-species alert.
- E-mail with the clip attached and the recording path.
- Telegram through Apprise.

**Visualisation**
- Live audio from the header of every page.
- Live spectrogram that fits the page.
- Spectrogram palette, height and colour sensitivity controls (floor, range, contrast).
- Overview / By Hour / By Week pages.
- Species pages with a standard sortable table and a species picklist.
- Detection review player.

**Curation (first level)**
- Confirmed species list.
- Per-recording review: yes / no / unsure.
- "Remove detection" to a Removed area with restore.
- **Purge Removed** (delete for good, all or per species).
- Species delete (all records of one species).
- Curation page.

**Data and distribution**
- Own sound repository, fed at detection time in a training-ready format, with a transport to a central repository (rclone).
- BirdWeather upload (off by default).
- One-command standalone install on a stock Raspberry Pi OS.
- Release model main/stable, with System Controls showing the version and updating to the next release.

**Outside the station (separate tools to merge)**
- **birdlog:** static site of the owner's records from the eBird export + photo XMP/EXIF.
- **Taxonomy workbooks:** CBRO releases, AviList × Clements × CBRO, V3 × V2.4 × CBRO.

---

## 2. Worth building (core platform)

### 2.1 Foundation — data model and curation
- **Places and activities:** Garden, Outing, Live session, Survey, Scheduled, Test.
  - Every observation filed under an activity and a place.
  - A selector for the active context (name, place, test).
  - Each activity is counted in the base or not.
- **Capture wide, count narrow:** store everything above a low capture floor; the "what counts" thresholds (confidence, per species, lists, geo, place list) are applied when reading, so changing them re-classifies the history with no re-analysis.
- **Unified review inbox**, with tabs:
  - All;
  - Below threshold;
  - New for this place;
  - Suspicious (out of range, odd hour, model disagreement, single hit);
  - Spurious / rejected.
- **Bulk actions by criteria:**
  - **Filter by:** any mix of date/time range, species, place, activity, confidence band, source and state.
  - **Actions:**
    - confirm or reject (with reason);
    - change species;
    - re-file to another activity or place;
    - mark as test;
    - move to Removed (reversible);
    - export.
  - A preview with counts before applying.
- **My Garden (place list):** the official species of a place.
  - New, strange or suspicious species go to a queue: add or reject.
  - Bound to the GPS base: move the station and the garden changes; the old one is kept.
- **Test mode:** playback and recognition tests go into a test activity, with auto-off and retroactive re-filing. They are never counted or uploaded.
- **Manual observations** (seen / heard, no audio): species picker, count, evidence type, note, photo/link; badge "manual"; bulk checklist entry.
- **Audit trail** of curation changes.
- **Retention by class:** best confirmed clips kept, unreviewed low-confidence clips short.

### 2.2 Lists
- **List center:** place, outing/event checklist, day/month/year and life lists, plus **target list** (expected this season but not yet recorded).
- **Evidence badges** per species (recorded / photographed / seen / heard), with the owner's best photo and best clip.
- **Exports:**
  - eBird checklist (CSV import);
  - PDF/HTML report;
  - CSV.

### 2.3 Taxonomy backbone
- **SQLite reference database:**
  - one table per list version (CBRO, Clements/eBird, IOC, AviList);
  - Avibase concept as the pivot;
  - crosswalk equal / split / lump / partial;
  - the recognition model's labels mapped to it.
- **Settings:** display list, counting list and language for common names; the counts follow the chosen list.
- **Taxonomy update assistant:** a new list version shows the splits and lumps that affect the owner's records and proposes re-assignment.
- **Seed:** the owner's CBRO and AviList × Clements × CBRO workbooks.

### 2.4 Garden and live monitoring
- **Live sessions** (start/stop, with their own list and summary) and a **watchlist** (target species → push/Telegram alert).
- **Phenology:** first/last date per species per year, arrivals and departures, dawn-chorus timing, year-over-year comparison.
- **Weather and astronomy overlay** (temperature, rain, wind, sunrise, moon).
- **Monthly/yearly report** by e-mail/Telegram.
- **Rarity score** from regional frequency + own history, feeding the rare tier.

### 2.5 Phone companion and outings
- **Phone app (native, Android/iOS — same Flutter codebase):**
  - outing with GPS track;
  - quick tap-to-add sightings with counts;
  - sound recognition in the field;
  - photos;
  - offline;
  - sync with the Pi station at home.
- **Outing → draft checklist:** sound detections + photos + sightings merged and de-duplicated per species; review; export to eBird.
- **Remote live listening and swipe curation** (accept/reject) from the phone.

### 2.6 Photos and media archive (birdlog merge)
- Photo import from the phone/NAS: EXIF/XMP, GPS → place and activity, burst de-duplication, ratings, tags.
- **Best photo / best clip per species** on species pages and lists.
- **Double evidence:** a photo + a sound of the same species close in time and place = a strong record.

### 2.7 Trip management (add-on module)
A **Trip** is a planned journey: one or more destinations and days, grouping several outings. It is built as an **add-on** on top of places, activities and lists, so the core works without it.
- **Plan:**
  - destination(s) and dates;
  - **target species** per destination (regional frequency + WikiAves occurrence + own history);
  - lifers possible for the owner;
  - hotspots and trails.
- **Itinerary per day:**
  - places, outings, guide, lodging, transport, notes;
  - linked to the calendar.
- **Offline field pack:** species list of the destination with own/reference photos and sounds, for use without signal.
- **During the trip:**
  - every outing, sighting, recording and photo is filed under the trip day;
  - a running trip list with **lifers** and targets ticked.
- **After the trip:**
  - automatic **trip report** (HTML/PDF): daily lists, maps, best photos and sounds, lifers, totals per site;
  - export of eBird checklists per site/day;
  - share with companions.
- **Optional:** costs and budget, documents/permits, group trips with shared lists.

### 2.8 Station operations
- **IP announcement:** mDNS name plus an e-mail/Telegram message with IP, URL and network after boot and on every IP change.
- **Guided notification setup:** Telegram, e-mail and others, without raw Apprise URLs.
- **First-run wizard:** place on a map, place name, list/taxonomy, language, notifications.
- **Health page:** mic level, disk, temperature, last detection, uploads, IP.
- **Backup/restore** in the UI (database + curated media) to USB/NAS.
- **Updates with rollback** and post-deploy verification.

---

## 3. Optional (high effort or diverges from the focus)

| Item | Why optional |
|---|---|
| **Photo identification model** (on-device, top-5 suggestions) | Needs a vision model and training data; manual ID + place-list picker covers most of the value |
| **Video ID** (frames + audio track) | High effort, few users |
| **Survey protocols** (point count, transect, timed count, MacKinnon, nocturnal, playback survey) with effort-corrected results | Scientific niche; valuable for NGOs/researchers, large UI and data work |
| **Scheduled monitoring of several units** (sunrise-relative schedules, duty cycles, AudioMoth import, batch analysis, multi-unit dashboard, acoustic indices) | Turns the product into an ARU platform; big scope |
| **Event / group mode** (shared list, teams, leaderboard, bird race) | Needs multi-user and real-time sync |
| **Multi-user roles** (owner / curator / observer / viewer) | Auth and permissions everywhere |
| **Per-station fine-tuned classifier** from confirmed clips | ML pipeline, compute, validation |
| **Similarity clustering / individual song dialects** | Research-grade |
| **Model ensemble on the station** (more than two models, disagreement scoring) | CPU on the Pi; two-model comparison already exists |
| **Noise / rain / wind profiling** with automatic suppression | Signal-processing effort; manual test periods cover part of it |
| **Nocturnal flight-call mode** | Specific model and thresholds |
| **Call vs song vs alarm classification** | Needs labelled data |
| **Connectors**: iNaturalist (API), Xeno-canto (API), WikiAves (assisted export, no API), BirdWeather filtered by context | One integration each, maintenance when their APIs change; eBird export comes first |
| **Darwin Core export** (GBIF / SiBBr) | Only for scientific users |
| **Central multi-station sound base** ("super base" per state/biome) | Server side, data governance |
| **Public place page** (read-only list, no exact GPS) | Hosting and privacy work |
| **Quiz / learning mode** from own recordings, confusion-pair guides | Nice to have, not core |
| **Home Assistant / MQTT, calendar integration, Telegram commands** | Small each, but outside identification and lists |
| **Travel hotspot mode** (station creates its own Wi-Fi in the field) | Network edge cases |
| **Biodiversity indices** (richness, Shannon, ACI/NDSI) per place | Mostly for the farm/lodge/research audience |
| **Hardware kits** (solar/battery, GPS, enclosures) | Product and support effort outside software |

---

## 4. Market scan and positioning

| Product | Strength | What BirdSuite adds |
|---|---|---|
| Merlin / BirdNET app | Excellent phone sound/photo ID | Persistent records, places, activities, curation, 24/7 station |
| eBird | Checklists, community data, targets | Automatic evidence from stations, media in one place, local curation |
| BirdNET-Pi / birdnet-go | Open 24/7 station | Activities, places, curation workflow, photos, sightings, list management, taxonomy choice |
| BirdWeather / PUC, Haikubox | Plug-and-play station + cloud | Local-first, curation, lists, outings |
| Bird Buddy | Feeder camera ID | Sound + photo + sightings, any place, no subscription |
| WikiAves / Xeno-canto / Macaulay | Archives and occurrence | A personal workflow that feeds them correctly |
| Chirpity / Raven / Kaleidoscope | Batch acoustic analysis | Integrated with places, curation and lists |

**Positioning:** *everything a birder does — identify, record, list, monitor — in one curated platform that runs at home and in the field.*

**Audiences, in order:**
1. Serious birders: garden + outings + life list.
2. Clubs and courses.
3. Farms, lodges, ecotourism (monitoring and reports).
4. Researchers/NGOs (surveys, multi-unit monitoring).

---

## 5. Suggested phases

0. **BirdSuite Listen first** (small, quick to ship, the entry point):
   - live listening with BirdNET V3 + geo filter on Android, iPhone, macOS, Windows;
   - Pi station mode (24/7 capture and recognition);
   - clips and history of what was heard;
   - the open detection format/API (export now, BirdSuite sync later).
1. **Foundation:**
   - places + activities + observations/evidence;
   - capture wide / count narrow;
   - manual observations;
   - bulk actions by criteria;
   - test mode;
   - review inbox.
2. **Garden complete:**
   - My Garden queue;
   - phenology;
   - reports;
   - watchlist;
   - IP announcement;
   - guided notifications;
   - first-run wizard.
3. **Taxonomy backbone:** crosswalk database, list switch, update assistant.
4. **Phone and outings:** native app (Android/iOS), tracks, quick sightings, field recognition, draft checklist → eBird.
5. **Trips (add-on):** planning with targets, itinerary, offline field pack, trip list with lifers, trip report.
6. **Media archive:** photo import, best media, double evidence (birdlog merged).
7. **Optional tracks**, as the audience requires: surveys, multi-unit monitoring, connectors, multi-user, photo ID model.

---

## 6. Open decisions (owner)

- **Architecture:** confirm Flutter + shared core + ONNX Runtime (see Product decisions).
- **Taxonomy defaults:** display CBRO? counting Clements (eBird)?
- **First audience:** personal and clubs, or monitoring for farms and lodges early?
- **First connectors** after eBird.

---

## 7. Backlog (to check)

Small items the owner asked to keep, checked when there is time.

- **Saracura-do-mato confirmed at Prédio** (owner 2026-10-25): *Aramides saracura*, a rail of forest edges and
  marshes, shows in the Yes (confirmed) group of Validation at the location Prédio (a building). Check which
  detections hold the Yes (who answered, when, the clips), whether they belong to another location or to a playback
  test, and whether the confirmation should be undone.
