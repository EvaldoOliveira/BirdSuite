#!/usr/bin/env bash
# The database's search structures, made idempotent for createdb.sh and the update (2026-10-23 test run 3, measured on a
# copy of a station's database with ten times its detections):
# - detections_Com_Name on (Com_Name, Sci_Name): the search walks the name pairs through it, one seek each (7 s -> 30 ms)
# - detections_Sci_Date_Location also holding Time, Confidence and Com_Name: a species page and the per-hour counts read
#   the index alone, without a table read per detection (8 s -> 5 s, the rest is kept by the month counters below)
# - past_changes + triggers: a counter per month, raised when a detection of a day before today is removed, restored,
#   imported or changed (species, location); the pages keep their counts per month and compute again only the months
#   whose counter moved (common.php cached_past_months)
# Waits up to 60 s for the analysis' write lock. A rebuilt index takes some seconds per 100 000 detections.
DB="${1:-$HOME/BirdNET-Pi/config/data/birds.db}"
[ -f "$DB" ] || exit 0
q() { sqlite3 -cmd ".timeout 60000" "$DB" "$@"; }
cols() { q "SELECT group_concat(name, ',') FROM (SELECT name FROM pragma_index_info('$1') ORDER BY seqno)"; }

if [ "$(cols detections_Com_Name)" != "Com_Name,Sci_Name" ]; then
  q 'DROP INDEX IF EXISTS detections_Com_Name; CREATE INDEX detections_Com_Name ON detections (Com_Name, Sci_Name);' \
    && echo "birds.db: detections_Com_Name holds the scientific name"
fi
if [ "$(cols detections_Sci_Date_Location)" != "Sci_Name,Date,Location,Time,Confidence,File_Name,Com_Name" ] \
  && q "SELECT 1 FROM pragma_table_info('detections') WHERE name = 'Location'" | grep -q 1; then
  q 'DROP INDEX IF EXISTS detections_Sci_Date_Location;
     CREATE INDEX detections_Sci_Date_Location ON detections (Sci_Name, Date, Location, Time, Confidence, File_Name, Com_Name);
     ANALYZE detections;' && echo "birds.db: detections_Sci_Date_Location covers the species pages"
fi

q "CREATE TABLE IF NOT EXISTS past_changes (Month TEXT PRIMARY KEY, N INTEGER NOT NULL DEFAULT 0);
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
END;" || echo "birds.db: month change counters not set (busy), next update" >&2
exit 0
