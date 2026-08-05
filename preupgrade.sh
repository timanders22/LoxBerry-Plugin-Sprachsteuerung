#!/bin/bash
# Sprachsteuerung lokal - preupgrade
# command <TEMPFOLDER> <NAME> <FOLDER> <VERSION> <BASEFOLDER>
#
# Die Container werden NICHT angefasst: in ihrem Datenordner liegen die
# heruntergeladenen Modelle. Wer den loescht, laedt Gigabyte erneut.
ARGV3=$3
ARGV5=$5
PFOLDER="${ARGV3:-sprachsteuerung}"
BASE="${ARGV5:-$LBHOMEDIR}"

PID="$BASE/data/plugins/$PFOLDER/dienst.pid"
if [ -f "$PID" ]; then
    kill "$(cat "$PID")" 2>/dev/null || true
    sleep 2
    kill -9 "$(cat "$PID")" 2>/dev/null || true
    rm -f "$PID"
    echo "<INFO> Laufender Dienst angehalten."
fi

for f in sprachsteuerung.json saetze.json; do
    CF="$BASE/config/plugins/$PFOLDER/$f"
    [ -f "$CF" ] && cp -p "$CF" "$BASE/config/plugins/$PFOLDER.backup.$f"
done
CF=""
chmod 600 "$BASE/config/plugins/$PFOLDER.backup.sprachsteuerung.json" 2>/dev/null
echo "<OK> preupgrade abgeschlossen. Die Container bleiben unberuehrt."
exit 0
