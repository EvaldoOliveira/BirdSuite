#!/usr/bin/env bash
source "$(dirname "$(readlink -f "${BASH_SOURCE[0]}")")/paths.sh"
sqlite3 $HOME/BirdNET-Pi/config/data/birds.db << EOF
DROP TABLE IF EXISTS detections;
CREATE TABLE IF NOT EXISTS detections (
  Date DATE,
  Time TIME,
  Sci_Name VARCHAR(100) NOT NULL,
  Com_Name VARCHAR(100) NOT NULL,
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
  Location INTEGER);
CREATE INDEX "detections_Com_Name" ON "detections" ("Com_Name", "Sci_Name");
CREATE INDEX "detections_Date_Time" ON "detections" ("Date" DESC, "Time" DESC);
CREATE INDEX "detections_Location_cover" ON "detections" ("Location", "Date", "Sci_Name", "Time", "Confidence", "File_Name");
CREATE INDEX "detections_Location_species" ON "detections" ("Location", "Sci_Name", "Confidence", "Com_Name", "File_Name");
CREATE INDEX "detections_Sci_Date_Location" ON "detections" ("Sci_Name", "Date", "Location", "Time", "Confidence", "File_Name", "Com_Name");
CREATE INDEX "detections_File_Name" ON "detections" ("File_Name");
CREATE TABLE IF NOT EXISTS detection_reviews (
  File_Name VARCHAR(100) PRIMARY KEY,
  Sci_Name VARCHAR(100),
  Com_Name VARCHAR(100),
  Date DATE,
  Confidence FLOAT,
  Verdict TEXT NOT NULL CHECK (Verdict IN ('yes','no','unsure')),
  Reviewed_At TEXT,
  Reason TEXT);
CREATE INDEX IF NOT EXISTS reviews_Verdict ON detection_reviews (Verdict, File_Name);
CREATE INDEX IF NOT EXISTS reviews_Reviewed_At ON detection_reviews (Reviewed_At);
CREATE TABLE IF NOT EXISTS deleted_detections (Date DATE, Time TIME, Sci_Name VARCHAR(100) NOT NULL, Com_Name VARCHAR(100) NOT NULL,
  Confidence FLOAT, Lat FLOAT, Lon FLOAT, Cutoff FLOAT, Week INT, Sens FLOAT, Overlap FLOAT, File_Name VARCHAR(100) NOT NULL, Deleted_At TEXT,
  Loc_Thresh FLOAT, Rec_Length INT, Sp_Override FLOAT, Location INTEGER);
CREATE INDEX IF NOT EXISTS deleted_File_Name ON deleted_detections (File_Name);
CREATE INDEX IF NOT EXISTS deleted_Sci_Name ON deleted_detections (Sci_Name);
EOF
"$HOME/BirdNET-Pi/scripts/db_indexes.sh"
chown $USER:$USER $HOME/BirdNET-Pi/config/data/birds.db
chmod g+w $HOME/BirdNET-Pi/config/data/birds.db
# write-ahead log (2026-10-23 test run 3): the pages read while the analysis writes, without "database is locked"
sqlite3 -cmd ".timeout 10000" $HOME/BirdNET-Pi/config/data/birds.db "PRAGMA journal_mode=WAL;" > /dev/null || true
