#!/usr/bin/env bash
################################################################################
# scripts/createdb.sh - THE STATION DATABASE
#
# PURPOSE:
#   The station database (config/data/birds.db): the one schema, every table, column, index and trigger the
#   code uses. Idempotent: what exists is left as it is (the installer and Clear all data run it).
#
# DEPENDENCIES:
#   - scripts/paths.sh
#   - scripts/utils/locations.py
#
# USAGE:
#   bash scripts/createdb.sh
#   Referenced by scripts/backup_data.sh, scripts/clear_all_data.sh, scripts/install_services.sh,
#   scripts/lib/db.php, scripts/update_birdnet_snippets.sh, scripts/utils/locations.py,
#   scripts/utils/shadow.py …
################################################################################

source "$(dirname "$(readlink -f "${BASH_SOURCE[0]}")")/paths.sh"
mkdir -p "$BS_DATA"
# One index only (owner 2026-11-05): a species is its scientific name; the common and English names are presentation,
# looked up by the pages (common.php common_name()) and never stored. A database of an older version loses its
# Com_Name / Orig_Com columns once, after a copy of it (birds.db.before-sci-only): the indexes that held them first,
# then DROP COLUMN (SQLite 3.35+); the indexes are made again without them below.
if [ -f "$BS_DB" ] && sqlite3 -cmd ".timeout 60000" "$BS_DB" "PRAGMA table_info(detections);" | cut -d'|' -f2 | grep -qx Com_Name; then
	[ -f "$BS_DB.before-sci-only" ] || sqlite3 -cmd ".timeout 60000" "$BS_DB" ".backup '$BS_DB.before-sci-only'" || exit 1
	sqlite3 -cmd ".timeout 60000" "$BS_DB" << 'EOF' && echo "createdb.sh: the common names left the database (copy in $BS_DB.before-sci-only)"
DROP INDEX IF EXISTS detections_Com_Name;
DROP INDEX IF EXISTS detections_Location_species;
DROP INDEX IF EXISTS detections_Sci_Date_Location;
ALTER TABLE detections DROP COLUMN Com_Name;
ALTER TABLE deleted_detections DROP COLUMN Com_Name;
ALTER TABLE detection_reviews DROP COLUMN Com_Name;
ALTER TABLE detection_reviews DROP COLUMN Orig_Com;
EOF
	REANALYZE=1
fi
sqlite3 -cmd ".timeout 60000" "$BS_DB" << 'EOF'
-- the detections: the analysis settings in force (Cutoff, Sens, Overlap, Loc_Thresh, Rec_Length, Sp_Override), the
-- location (Location) and its biome and land cover (US-27, written by the triggers below), the microphone it was
-- recorded with, the USB port it was plugged into and its capture gain in % (Mic, Mic_Port, Mic_Gain, owner 2026-11-04),
-- and the rest of the scene, so a study can rebuild it later (owner 2026-11-04): the time zone and UTC offset of Date /
-- Time, the model, the BirdSuite version, the species list in force, the audio lost by the recorder in that recording
-- (count and ms; NULL = not known), sample rate and channels, and Scenario = the id of config/data/scenarios/<id>.json
-- (every setting without secrets, the contents of the lists in force, model, version, time zone, microphone)
CREATE TABLE IF NOT EXISTS detections (
  Date DATE,
  Time TIME,
  Sci_Name VARCHAR(100) NOT NULL,
  Confidence FLOAT,
  Lat FLOAT,
  Lon FLOAT,
  Cutoff FLOAT,
  Week INT,
  Sens FLOAT,
  Overlap FLOAT,
  File_Name VARCHAR(100) NOT NULL,
  Loc_Thresh FLOAT,
  Rec_Length INT,
  Sp_Override FLOAT,
  Location INTEGER,
  Biome TEXT,
  Ecoregion TEXT,
  Biome_BR TEXT,
  Land_Cover TEXT,
  Built_Up INTEGER,
  Setting TEXT,
  Built_Up_500 INTEGER,
  Mic TEXT,
  Mic_Port TEXT,
  Mic_Gain REAL,
  Tz TEXT,
  Utc_Offset TEXT,
  Model TEXT,
  Software TEXT,
  Species_List TEXT,
  Audio_Loss_N INTEGER,
  Audio_Loss_Ms REAL,
  Sample_Rate INTEGER,
  Channels INTEGER,
  Scenario TEXT);
-- the search walks the name pairs; the period cards and charts and the per-species counts read the covering indexes
-- alone; a detection is looked up by its file everywhere (reviews, delete, restore)
CREATE INDEX IF NOT EXISTS detections_Date_Time ON detections (Date DESC, Time DESC);
CREATE INDEX IF NOT EXISTS detections_Location_cover ON detections (Location, Date, Sci_Name, Time, Confidence, File_Name);
CREATE INDEX IF NOT EXISTS detections_Location_species ON detections (Location, Sci_Name, Confidence, File_Name);
CREATE INDEX IF NOT EXISTS detections_Sci_Date_Location ON detections (Sci_Name, Date, Location, Time, Confidence, File_Name);
CREATE INDEX IF NOT EXISTS detections_File_Name ON detections (File_Name);

-- one answer per detection (Review): Reason = the cause of a "not this bird" (human, noise, ignore) or 'changed';
-- Orig_Sci = the species before Change species
CREATE TABLE IF NOT EXISTS detection_reviews (
  File_Name VARCHAR(100) PRIMARY KEY,
  Sci_Name VARCHAR(100),
  Date DATE,
  Confidence FLOAT,
  Verdict TEXT NOT NULL CHECK (Verdict IN ('yes','no','unsure')),
  Reviewed_At TEXT,
  Reason TEXT,
  Orig_Sci VARCHAR(100));
CREATE INDEX IF NOT EXISTS reviews_Verdict ON detection_reviews (Verdict, File_Name);
CREATE INDEX IF NOT EXISTS reviews_Reviewed_At ON detection_reviews (Reviewed_At);

-- the removed detections, restorable until purged
CREATE TABLE IF NOT EXISTS deleted_detections (
  Date DATE,
  Time TIME,
  Sci_Name VARCHAR(100) NOT NULL,
  Confidence FLOAT,
  Lat FLOAT,
  Lon FLOAT,
  Cutoff FLOAT,
  Week INT,
  Sens FLOAT,
  Overlap FLOAT,
  File_Name VARCHAR(100) NOT NULL,
  Deleted_At TEXT,
  Loc_Thresh FLOAT,
  Rec_Length INT,
  Sp_Override FLOAT,
  Location INTEGER,
  Biome TEXT,
  Ecoregion TEXT,
  Biome_BR TEXT,
  Land_Cover TEXT,
  Built_Up INTEGER,
  Setting TEXT,
  Built_Up_500 INTEGER,
  Mic TEXT,
  Mic_Port TEXT,
  Mic_Gain REAL,
  Tz TEXT,
  Utc_Offset TEXT,
  Model TEXT,
  Software TEXT,
  Species_List TEXT,
  Audio_Loss_N INTEGER,
  Audio_Loss_Ms REAL,
  Sample_Rate INTEGER,
  Channels INTEGER,
  Scenario TEXT);
CREATE INDEX IF NOT EXISTS deleted_File_Name ON deleted_detections (File_Name);
CREATE INDEX IF NOT EXISTS deleted_Sci_Name ON deleted_detections (Sci_Name);

-- the biome and land cover of each location (scripts/utils/locations.py biomes); every new detection and every
-- detection moved to another location takes those of its location
CREATE TABLE IF NOT EXISTS location_biomes (
  Location INTEGER PRIMARY KEY,
  Biome TEXT,
  Ecoregion TEXT,
  Biome_BR TEXT,
  Land_Cover TEXT,
  Built_Up INTEGER,
  Setting TEXT,
  Built_Up_500 INTEGER);
CREATE TRIGGER IF NOT EXISTS detections_biome_insert AFTER INSERT ON detections WHEN NEW.Biome IS NULL AND NEW.Location IS NOT NULL BEGIN
  UPDATE detections SET
    Biome = (SELECT Biome FROM location_biomes WHERE Location = NEW.Location),
    Ecoregion = (SELECT Ecoregion FROM location_biomes WHERE Location = NEW.Location),
    Biome_BR = (SELECT Biome_BR FROM location_biomes WHERE Location = NEW.Location),
    Land_Cover = (SELECT Land_Cover FROM location_biomes WHERE Location = NEW.Location),
    Built_Up = (SELECT Built_Up FROM location_biomes WHERE Location = NEW.Location),
    Setting = (SELECT Setting FROM location_biomes WHERE Location = NEW.Location),
    Built_Up_500 = (SELECT Built_Up_500 FROM location_biomes WHERE Location = NEW.Location)
  WHERE rowid = NEW.rowid;
END;
CREATE TRIGGER IF NOT EXISTS detections_biome_move AFTER UPDATE OF Location ON detections WHEN NEW.Location IS NOT OLD.Location BEGIN
  UPDATE detections SET
    Biome = (SELECT Biome FROM location_biomes WHERE Location = NEW.Location),
    Ecoregion = (SELECT Ecoregion FROM location_biomes WHERE Location = NEW.Location),
    Biome_BR = (SELECT Biome_BR FROM location_biomes WHERE Location = NEW.Location),
    Land_Cover = (SELECT Land_Cover FROM location_biomes WHERE Location = NEW.Location),
    Built_Up = (SELECT Built_Up FROM location_biomes WHERE Location = NEW.Location),
    Setting = (SELECT Setting FROM location_biomes WHERE Location = NEW.Location),
    Built_Up_500 = (SELECT Built_Up_500 FROM location_biomes WHERE Location = NEW.Location)
  WHERE rowid = NEW.rowid;
END;

-- a counter per month, raised when a detection of a day before today is removed, restored, imported or changed
-- (species, location): the pages keep their counts per month and compute again only the months whose counter moved
-- (common.php cached_past_months)
CREATE TABLE IF NOT EXISTS past_changes (Month TEXT PRIMARY KEY, N INTEGER NOT NULL DEFAULT 0);
CREATE TRIGGER IF NOT EXISTS past_changes_ins AFTER INSERT ON detections WHEN NEW.Date < date('now', 'localtime') BEGIN
  INSERT INTO past_changes (Month, N) VALUES (substr(NEW.Date, 1, 7), 1) ON CONFLICT(Month) DO UPDATE SET N = N + 1;
END;
CREATE TRIGGER IF NOT EXISTS past_changes_del AFTER DELETE ON detections WHEN OLD.Date < date('now', 'localtime') BEGIN
  INSERT INTO past_changes (Month, N) VALUES (substr(OLD.Date, 1, 7), 1) ON CONFLICT(Month) DO UPDATE SET N = N + 1;
END;
CREATE TRIGGER IF NOT EXISTS past_changes_upd AFTER UPDATE ON detections
  WHEN OLD.Date < date('now', 'localtime') OR NEW.Date < date('now', 'localtime') BEGIN
  INSERT INTO past_changes (Month, N) VALUES (substr(OLD.Date, 1, 7), 1) ON CONFLICT(Month) DO UPDATE SET N = N + 1;
  INSERT INTO past_changes (Month, N) VALUES (substr(NEW.Date, 1, 7), 1) ON CONFLICT(Month) DO UPDATE SET N = N + 1;
END;
EOF
# a database made by an older version gets the columns added since (CREATE TABLE IF NOT EXISTS leaves an existing
# table as it is): each one once, empty on the old rows
for tb in detections deleted_detections; do
 for cd in Mic:TEXT Mic_Port:TEXT Mic_Gain:REAL Tz:TEXT Utc_Offset:TEXT Model:TEXT Software:TEXT Species_List:TEXT \
					 Audio_Loss_N:INTEGER Audio_Loss_Ms:REAL Sample_Rate:INTEGER Channels:INTEGER Scenario:TEXT; do
	tc=$tb:$cd
	IFS=: read -r tb col typ <<< "$tc"
	if ! sqlite3 -cmd ".timeout 60000" "$BS_DB" "PRAGMA table_info($tb);" | cut -d'|' -f2 | grep -qx "$col"; then
		sqlite3 -cmd ".timeout 60000" "$BS_DB" "ALTER TABLE $tb ADD COLUMN $col $typ;" && echo "createdb.sh: $tb.$col added"
	fi
 done
done
# the planner's statistics of the indexes made again after the migration (without them the pages took 4 to 20 s on the
# pilot, 2026-11-05); on every other update only the tables that changed enough are analysed (PRAGMA optimize)
if [ -n "${REANALYZE:-}" ]; then
	sqlite3 -cmd ".timeout 60000" "$BS_DB" "ANALYZE;" > /dev/null || true
else
	sqlite3 -cmd ".timeout 60000" "$BS_DB" "PRAGMA optimize;" > /dev/null || true
fi
# write-ahead log: the pages read while the analysis writes, without "database is locked"
sqlite3 -cmd ".timeout 10000" "$BS_DB" "PRAGMA journal_mode=WAL;" > /dev/null || true
if [ "$(id -u)" -eq 0 ]; then chown "$USER:$USER" "$BS_DB"; fi
chmod g+w "$BS_DB"
