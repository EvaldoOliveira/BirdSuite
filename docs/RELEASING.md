# Releasing BirdSuite

Branch model (decided 2026-10-07, US-51e):

| Branch | Role | Who follows it |
|---|---|---|
| `main` | development line; every change lands here directly (owner 2026-10-24: no PRs) | every installed station's Update (`UPDATE_BRANCH=main`, the default since 2026-10-24) |
| `stable` | the last released version: a snapshot of `main`, moved only when the owner asks for a release | the installer (`newinstaller.sh` clones `stable`) and stations set to `UPDATE_BRANCH=stable` |

Versions are tags `vMAJOR.MINOR.PATCH` on `main` (`v0.x` = test releases, marked *pre-release* on GitHub).

## Two repositories (owner 2026-10-27, during the beta)

- **BirdSuiteDev** (GitHub, private) is where the development happens: every commit, branch, tag and release, the
  workflows; the Gitea copy `evaldo/BirdSuite` holds the same. The pilot station updates from the public main, like every station.
- **BirdSuite** (GitHub, public) is what installs and stations use, and holds only `main`: one commit with the last
  closed release, then the commits of the release in development (one public commit for each, author and message
  kept). When a release closes, its history starts again from one commit with the new release, and only that tag and
  GitHub release are kept. `tools/publish_public.sh` does both (`release vX.Y.Z`, `sync`); the files only the
  development needs (the release workflow, the script) stay out.
- Every push of `main` to BirdSuiteDev goes on to the Gitea and is published with `tools/publish_public.sh sync`.

## Soft and hard releases (owner 2026-10-29)

- **Soft release** (owner 2026-10-30): a milestone for rollback, isolating the next changes in case they turn out bad,
  published on GitHub's main without the release work. An annotated tag `vX.Y.Z` on `main` with a one-line message
  (`vX.Y.Z - soft release (no notes, not published)`), pushed to the Gitea and to BirdSuiteDev (`git push gitea vX.Y.Z &&
  git push origin vX.Y.Z`), with `main` pushed to both and `publish_public.sh sync` run, so the public main has it. No
  The docs are brought up to date (README, this file and the other documents; `custom/docs`, the project log). No notes
  file in `docs/releases/` (the file is what releases), no screenshots, no gate or extensive tests, no GitHub release, no
  `publish_public.sh release`, no tag on the public repository, `stable` does not move. Stations keep showing the last
  hard release (they read the public tags); stations on `main` get the code. The owner gives the number. First one: v0.16.1.
  Rollback to it: `git diff --stat vX.Y.Z main` shows what came after; `git revert --no-edit vX.Y.Z..main` (or only the
  bad commits), then the normal deploy (push origin + gitea, `publish_public.sh sync`, pilot update). Never reset or
  force-push main: the public main is built forward from it, and the stations update from the public main, which has
  no soft tag (`update_birdnet.sh -b vX.Y.Z` does not reach it).
- **Hard release**: everything below (*Cutting a release*): the gate and the tests before closing, the notes file,
  the workflow's tag and GitHub release, `stable`, `publish_public.sh release`, README and screenshots, the acceptance
  install. Its notes cover every change since the last hard release, the soft releases in between included.
- **Translations** (owner 2026-10-29): day to day a new or changed text gets English (the key) and pt_BR only
  (`python3 lang/check.py <files>` checks pt_BR); a hard release translates the other catalogues (de, es, fr, it, ja,
  nl, pl, ru, zh_CN) and `python3 lang/check.py --all <every page and script>` must say `missing 0 conflicts 0`. A
  missing translation shows the English text until then.
- A release asked for without saying soft or hard: ask which one first, never assume.

## Cutting a release

1. Everything for the release is merged into `main` and has run on the pilot (services active, web UI 200, new detections with the right names, no tracebacks).
2. **Gate (owner 2026-10-25: never without it)** — before writing the notes:
   - `python3 tests/import_export_test.py` passes (Export / Backup of one and of every location, Export to BirdNET, peek,
     Import into Test / Playback, a new location, an existing one with part of the data, every location to its own,
     a BirdNET folder, twice without duplicates, a round trip, a folder with spaces and accents read-only as a NAS
     share, a folder that is not a backup). The release workflow runs it again and creates no tag when it fails;
   - the items of *Tests before closing the release* below that the change touched have run on the pilot, above all
     **Mounted folders** (import / export through a real NAS mount) — the owner asked for it on 2026-10-24.
3. **Only when the owner asks to close a release and gives its number** (owner 2026-10-25), write the notes to `docs/releases/vX.Y.Z.txt` and push it to `main`: first line `vX.Y.Z - <one line>` (plain ASCII: a terminal that pastes it may mangle other characters), blank line, then the notes in English (new since the last release, fixes, notes/caveats). Never add a notes file on your own: the file is what releases.
4. The workflow `.github/workflows/release.yml` (Release from notes) then creates the annotated tag `vX.Y.Z` on that commit with the file as its message (stations show it under *What's new* in System › Status, read from the fetched tag), the GitHub release (v0.x = pre-release), and moves `stable` forward to it. A tag that already exists is never rewritten (a station that has it refuses a changed one); editing its file only updates the GitHub release text. Binary files the installer needs (tflite wheels, model files) go into the public `EvaldoOliveira/birdsuite-assets` repository, fetched over https by `scripts/fetch_asset.sh`.
4b. **Publish**: `tools/publish_public.sh release vX.Y.Z` (the public main starts again from the release, older public
   tags and releases deleted), then `tools/publish_public.sh sync` for any commit of main after the tag.
5. **Acceptance install**: flash a clean Raspberry Pi OS (64-bit), log in as `pi`, run `BIRDNET_BRANCH=main` (or the tag) install, answer the first-run questions; it must record, detect, show the configured language names and serve the web UI. No hand-copied file, no access to the maintainer's network.
6. The channel moves with step 4 (`stable` only ever moves forward to a newer release tag); by hand only if the workflow could not: `git branch -f stable vX.Y.Z && git push --force-with-lease origin stable`.
7. New installations get it; stations set to `UPDATE_BRANCH=stable` pick it up with their next update (UI *Update*, weekly auto-update, or `update_birdnet.sh`). Stations on `main` already run it.

## Tests before closing the release

Run on the pilot before step 3 (the notes). Items added while the change was only tested in simulation:

- **Mounted folders** (2026-10-24): a share mounted under `/mnt` (CIFS with `uid=`/`gid=` of the station user, e.g.
  `sudo mount -t cifs //<server>/<share> /mnt/data -o username=<user>,uid=$(id -u),gid=$(id -g),file_mode=0664,dir_mode=0775,vers=3.0`)
  and a USB disk under `/media`: both appear in the *Start:* row of Tools › Data, Backup/Import and External Files;
  Export / Backup writes into the share, Import reads a backup from it; a share mounted for root only shows greyed with
  the lock and the reason. Note: the Samba server needs the user in `pdbedit -L` (`smbpasswd -a <user>`), else
  `STATUS_LOGON_FAILURE`.
- **Species names** (2026-10-24): Migrate on a station with old (common-name) clips; a Common name Language change
  renames the species in the database only; Import of a BirdNET-Pi folder and of a BirdSuite backup of several
  locations (into Test / Playback and into New location); Export to BirdNET in another language.
- **Tools › Data / Backup/Import** (2026-10-24): Purge Removed per location, Audio to purge, Backup / Restore settings
  (a restore keeps the detections), Factory settings (the settings saved in `~/BirdSuite-backups` first), Clear ALL data.
- **System › Status** (2026-10-24): each service button opens the terminal screen and ends with the units' state;
  Restart Core Services survives the web server restart.
- **Tools › Browse files** (2026-10-24): `mc` opens after the password login (fresh install and after an update).
- **http by default, https for the GPS** (US-126, 2026-10-29; replaces the https test of 2026-10-24): the station answers
  on http only (`curl -k https://<station>` refused); Settings › Locations › map › GPS shows the notice, switches https
  on, the small window shows the certificate warning once per device, reads the position and closes, the position fills
  the map and https is off again (Caddyfile without `on_demand_tls`); a window closed without a position switches it off
  too, and a forgotten one goes off by itself after 10 minutes (field_daemon.py); HTTPS_ALWAYS = 1 in Tools › Defaults
  serves https only and http redirects there; 0 again goes back to http (phone and notebook).
- **Phone hotspot, plan A** (2026-10-24, not tested yet): with the phone hotspot set in Settings › Network and on, the
  station joins it by itself; iPhone: the station answers at `http://172.20.10.14` (and https); Android: its address is
  in the hotspot's "connected devices"; the label's addresses match.
- **No network, station WiFi, plan B** (2026-10-24, not tested yet): with no known network in reach the station turns on
  its own WiFi `BirdSuite-<MAC>` with the password set; a phone joins it with the label's QR code and opens
  `https://10.42.N.1` (the station's own address, Network page; GPS on the map); Find my station blinks the LEDs; the station goes back to the known
  network when it is in reach again.
- **Station WiFi (Plan A since 2026-10-25) after a lost network** (2026-10-25, not tested yet): with the station on a WiFi or the phone hotspot, turn
  that network off: after about 5 minutes the station WiFi comes on (Network shows its own `10.42.N.1`, two stations
  have different ones) and goes off after 15 minutes without a phone; the station then joins the network again if it
  is back. An older station's WiFi moves from 10.42.0.1 to its own address at the next start of the station WiFi.
  On a station on WiFi only, `tests/field_planb_test.sh` does it unattended (run with `sudo systemd-run
  --unit=bs-planb-test bash ~/BirdNET-Pi/tests/field_planb_test.sh`, read `~/field_planb_test.log` 5 min later).
- **Microphone reconnected** (2026-10-24, tested on the pilot): unplug and plug the USB microphone back (also within a
  few seconds) with any page open: the window shows within 30 s with the countdown, the location list (current one
  selected) and New location… with coordinates filled in; Confirm, Close, the × and Escape all close it.

Rollback: point `stable` back to the previous tag (stations follow on their next update), or on one station `update_birdnet.sh -b vX.Y.Z`.
