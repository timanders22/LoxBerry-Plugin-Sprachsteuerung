#!/bin/bash
# Sprachsteuerung lokal - preupgrade
# command <TEMPFOLDER-KENNUNG> <NAME> <FOLDER> <VERSION> <WORKDIR>
# ($1 ist eine zehnstellige Zufallskennung, KEIN Pfad - REGELN_2)
#
# Die Container werden NICHT angefasst. Ihr Einhaengepunkt allerdings schon:
# data/plugins/<x>/modelle - und genau den loescht purge_installation bei
# JEDEM Upgrade. Bis 0.10.1 stand hier, die Modelle blieben erhalten; sie
# waren nach jedem Update fort und wurden neu geladen (mehrere Gigabyte).
# Deshalb wandert der Ordner weiter unten NEBEN den Plugin-Ordner.
ARGV3=$3
ARGV5=$5
PFOLDER="${ARGV3:-sprachsteuerung}"
BASE="${ARGV5:-$LBHOMEDIR}"
# Ohne diese Probe liefe das Skript bei leerem $5 und leerem LBHOMEDIR
# gegen /config/plugins/... - und ein 'cp' ins Leere sieht aus wie Erfolg.
if [ -z "$BASE" ] || [ ! -d "$BASE/config/plugins" ]; then
    echo "<INFO> preupgrade: LoxBerry-Wurzel nicht ermittelbar - nichts gesichert."
    exit 0
fi

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

# ---------- Langzeitwerte retten ----------
# der Verlauf der erkannten Befehle, die Messreihe und die Ansagezeiten.
# Die Messreihe ist seit 0.10.0 dabei: ohne sie laesst sich nach einem
# Modellwechsel nicht mehr sagen, ob es schneller geworden ist - und genau
# dafuer misst man.
# Der Installer loescht data/plugins/<x>/ bei JEDEM Update - gemessen an
# sbin/plugininstall.pl (Zweig master, 23.08.2026): &purge_installation steht
# im Upgrade-Zweig (:886), und ihr Rumpf loescht ohne Bedingung (:1631).
# Deshalb NEBEN den Ordner: "rm -rf .../<x>/" trifft den Nachbarn mit dem
# Punkt nicht. postinstall.sh holt ihn zurueck und raeumt ihn weg.
LANG_SICHER="$BASE/data/plugins/$PFOLDER.upgrade_sicherung"
mkdir -p "$LANG_SICHER" 2>/dev/null
chmod 0700 "$LANG_SICHER" 2>/dev/null
for LANG_F in verlauf.json messwerte.json ansagen.json; do
    [ -f "$BASE/data/plugins/$PFOLDER/$LANG_F" ] \
        && cp -p "$BASE/data/plugins/$PFOLDER/$LANG_F" "$LANG_SICHER/$LANG_F" 2>/dev/null
done
# Die Wirkung pruefen, nicht den Rueckgabewert: liegt hinterher etwas da?
if [ -n "$(ls -A "$LANG_SICHER" 2>/dev/null)" ]; then
    echo "<OK> Langzeitwerte gesichert."
fi

# ---------- Der Sollmerker ----------
# Er sagt, ob der Dienst laufen SOLL, und liegt im Datenordner - also in
# dem, den der Installer gleich abraeumt. Ohne ihn startet der Waechter
# nach dem Update nichts mehr, und das faellt niemandem auf.
if [ -e "$BASE/data/plugins/$PFOLDER/soll_laufen" ]; then
    touch "$BASE/data/plugins/$PFOLDER.soll_laufen" 2>/dev/null \
        && echo "<OK> Der Dienst lief - er wird nach dem Update wieder gestartet."
fi

# ---------- Die heruntergeladenen Modelle ----------
# Kein Kopieren: 'mv' auf demselben Dateisystem benennt nur um und laesst
# den Inhalt (mehrere Gigabyte) liegen, wo er ist. Die laufenden Container
# haben ihren Einhaengepunkt bereits geoeffnet und behalten ihn dabei.
# postinstall.sh schiebt den Ordner zurueck.
if [ -d "$BASE/data/plugins/$PFOLDER/modelle" ] \
   && [ ! -e "$BASE/data/plugins/$PFOLDER.modelle_umzug" ]; then
    if mv "$BASE/data/plugins/$PFOLDER/modelle" \
          "$BASE/data/plugins/$PFOLDER.modelle_umzug" 2>/dev/null; then
        echo "<OK> Die heruntergeladenen Modelle sind vor dem Update in Sicherheit."
    else
        echo "<INFO> Der Modellordner liess sich nicht beiseite schieben - die"
        echo "<INFO> Modelle werden nach dem Update neu geladen."
    fi
fi
exit 0
