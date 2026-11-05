# FAQ and troubleshooting

Start with **System › Status**: its **Checks** test the microphone, recording, analysis, disk, model, clock, power and more, and offer a one-click fix for most problems.
The same checks are available as JSON (the **JSON** link) for monitoring.

- [Questions](#questions)
- [Problems](#problems)
- [Logs](#logs)
- [Getting help](#getting-help)

---

## Questions

**What is the default login?**
User `birdsuite`; the password is the one you typed at installation (the factory password is `birdsuite` when nobody answered).
Change it in *Station › Access*; *System › Status* warns while the factory password is in use.

**Which pages need the login?**
By default every page asks for it on another device (*Station › Access*, On your network: every page asks for the login).
With **only my household**, a device on your own network (the LAN, the station WiFi, the VPN) opens the pages and answers the reviews and the lists without it; the settings, the log, the terminal, the files, the live audio and the database still ask.
With **Web login: Off** the station is open: no password and no login dialog anywhere.
Forgot the password? The login page links to a reset by e-mail or Telegram (when chosen in *Station › Access*); the file `reset-web-password` on the card's boot partition brings back the factory password at the next start; `sudo birdsuite-root webpassword-reset` sets one on the console.

**V3 or V2.4?**
BirdNET+ V3 (the default) knows about 9,800 classes (birds, amphibians, mammals and insects) and has its own location model.
V2.4 has a human-voice class, which V3 lacks: run V2.4 as the **shadow model** to keep the privacy filter working with V3.
Thresholds are not comparable between model generations: after a model change, review the per-species values.

**How do I reduce false positives for one species?**
Give it a **Manual Conf.** on its species page or in Species, or let *Review › Calibrate thresholds* suggest one from your reviews.
A species that only sings at night goes on *Species › Night-only species*.

**Can I change the language of the species names later?**
Yes, in *Station › Model*, although it is best chosen at installation.
The station renames the species in the database and the lists in the background (progress in *Data › Data*); clips and folders use the scientific name, so nothing is split.

**Does anything leave the station?**
Only what you switch on: notifications, BirdWeather, the BirdDB-Br contribution, the eBird e-mail.
See [Privacy and security](../README.md#privacy-and-security).

**Can I go back to BirdNET-Pi?**
Yes. See [Coming from BirdNET-Pi](installation.md#coming-from-birdnet-pi).

---

## Problems

**The station does not open at `birdsuite.local`.**
Try `http://birdsuite-<MAC>.local` (on the QR label and *Station › Network*) or the IP address (on the label and in the "new address" notification).
On Windows the network must be *Private*; `ipconfig /flushdns` forgets an old address.
Android 11 and older do not resolve `.local` names: use the IP address.

**No detections.**
Check *System › Status*: Microphone, Recording, Analysis backlog.
The microphone must be a USB card present at start-up or plugged in later (the station follows hot-plug).
Check the **Latest** analysis line: "analysis stopped" or a growing *Waiting* count points to the analysis service.
A *Custom* list or a high **Min. Conf.** may also filter everything out: see *Station › Model* and *Lists*.

**The clips are silent or empty.**
The **recording watchdog** restarts the recording when the clips come out empty and tells you; its restarts in the last 24 h show in *System › Status*.
Restarting the core services (*System › Status › Restart Core Services*) solves many odd states.

**The clock is wrong.**
A Pi has no battery clock: without internet it keeps the time of its last shutdown.
**Use this device's time** (on *Latest*, *Station › Network* and *First Run*) takes the time from your phone or computer.

**The browser warns that the connection is not private.**
That is the station's own certificate, used only for a moment to read the GPS or to share a detection.
Click **Advanced** and **Proceed** once per device. See [http, and https only for the GPS](installation.md#http-and-https-only-for-the-gps).

**I cannot reach the station in the field.**
Plan A: join the station's own WiFi `BirdSuite-<MAC>` (scan the QR label); the phone then opens the station by itself.
Plan B: turn on your phone hotspot; the station joins it by itself.
With *WiFi in the field* set to *Cycle* or *Off*, the station WiFi is on only part of the time (*Station › Network*).

**The disk is filling up.**
*Data › Disk Space* shows what fills it, when it will be full and the smallest disk that fits.
Adjust the limits in *Data › Data* (*Life cycle of the sounds*) or move old sounds to an external disk.

**Notifications do not arrive.**
A channel sends only after you confirmed its test arrived, and a failed send while online disables it until the next confirmed test: its page says so.
Check quiet hours and the species tier (Muted never notifies) in *Alerts › What and when*.

**An update failed.**
*System › Status* shows the error code and **Reload**.
Save the output and open an issue; meanwhile the station can return to an earlier release (see [Rollback](installation.md#updating)).
The update can also be run from a terminal with `sudo -n /usr/local/sbin/birdsuite-root update`.

---

## Logs

- *Watch › Analysis log*: the live log of the station's services.
- *Data › Disk Space › Logs*: sizes, retention, **Export logs** (a `.tar.gz` to attach to an issue).
- The system journal: `journalctl -t birdsuite-update`, `-t birdsuite-disk_check`, and the other `birdsuite-*` tags.
- The installation log: `installation-<date>.txt` in the folder the installer ran from.
- Settings changes: `~/BirdSuite/state/settings_history.log`.

---

## Getting help

See [Contributing](../README.md#contributing) for where to report a problem.
Please attach the exported logs and the version shown in *System › Status*.
