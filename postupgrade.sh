#!/bin/bash
# Sprachsteuerung lokal - postupgrade
# command <TEMPFOLDER-KENNUNG> <NAME> <FOLDER> <VERSION> <BASEFOLDER> <WORKDIR>
#
# ---------------------------------------------------------------------------
# WARUM HIER FAST NICHTS MEHR STEHT
#
# Bis 0.9.1 rief diese Datei postinstall.sh auf. Das sah nach Sorgfalt aus,
# war aber eine Verdopplung: der LoxBerry-Installer fuehrt postinstall
# OHNE Bedingung aus (sbin/plugininstall.pl, Abschnitt "Executing postinstall
# script" - kein if ($isupgrade) davor) und postupgrade danach ZUSAETZLICH
# beim Upgrade. Nachgestellt mit demselben Ablauf: postinstall lief zweimal.
#
# Das ist nicht bloss unschoen. postinstall.sh legt die virtuelle Umgebung an
# und holt wyoming und aioesphomeapi ueber pip aus dem Netz. Auf einem
# Raspberry Pi dauert das Minuten - und es geschah bei jedem Upgrade doppelt.
#
# Was ein Upgrade zusaetzlich braucht, steht hier. Alles andere hat
# postinstall.sh zu diesem Zeitpunkt bereits erledigt.
# ---------------------------------------------------------------------------

ARGV3=$3
ARGV5=$5
PFOLDER="${ARGV3:-sprachsteuerung}"
BASE="${ARGV5:-$LBHOMEDIR}"
if [ -z "$BASE" ] || [ ! -d "$BASE" ]; then
    SELF=$(cd "$(dirname "$0")" && pwd)
    BASE=$(cd "$SELF/../.." 2>/dev/null && pwd)
fi

PBIN="$BASE/bin/plugins/$PFOLDER"

# Alte Python-Zwischendateien wegraeumen.
#
# Bis 0.9.1 lagen im Paket sogar mitgelieferte .pyc-Dateien (__pycache__ mit
# cpython-310). Die sind jetzt draussen, aber auf bestehenden Installationen
# liegen sie noch - und eine Zwischendatei, die aelter ist als der Quelltext
# daneben, kann Python im ungluecklichen Fall statt des neuen Codes laden.
# Der Ordner wird bei Bedarf neu und passend zur laufenden Python-Fassung
# angelegt.
if [ -d "$PBIN/__pycache__" ]; then
    rm -rf "$PBIN/__pycache__"
    echo "<OK> Alte Python-Zwischendateien entfernt."
fi

# Die Zwischendatei zustand.json ist seit 0.10.0 entfallen.
#
# Sie wurde bei JEDEM gesprochenen Satz geschrieben und von niemandem gelesen -
# ein Schreibvorgang auf die SD-Karte ohne Nutzen. Ihr Inhalt steht jetzt in
# loxone.json, die ohnehin im Sekundentakt entsteht.
PDATA_ALT="$BASE/data/plugins/$PFOLDER"
if [ -f "$PDATA_ALT/zustand.json" ]; then
    rm -f "$PDATA_ALT/zustand.json"
    echo "<OK> Nicht mehr benutzte zustand.json entfernt."
fi

echo "<OK> postupgrade abgeschlossen."
exit 0
