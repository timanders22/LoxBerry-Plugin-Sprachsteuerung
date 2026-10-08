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
# Dieselbe Wurzelregel wie in postinstall.sh (Regeln/06). Bis 0.11.9 stand
# hier der feste Rueckfall "$SELF/../..": gemessen am 25.09.2026 in WSL
# (Pruefung-Sprachsteuerung-0.11.10, Fall W4) loeschte das Skript aus
# <irgendwo>/a/b heraus unter <irgendwo> bin/plugins/<ordner>/__pycache__
# und data/plugins/<ordner>/zustand.json.
sp_wurzel_suchen() {
    v=$(cd "$(dirname "$(readlink -f "$0")")" 2>/dev/null && pwd)
    i=0
    while [ -n "$v" ] && [ "$v" != "/" ] && [ $i -lt 8 ]; do
        if [ -d "$v/config/plugins" ] && [ -d "$v/data/plugins" ] \
           && [ -f "$v/config/system/general.json" ]; then
            printf '%s\n' "$v"; return 0
        fi
        v=$(dirname "$v"); i=$((i + 1))
    done
    return 1
}
if [ -z "$BASE" ] || [ ! -d "$BASE/config/plugins" ]; then
    BASE=$(sp_wurzel_suchen)
fi
if [ -z "$BASE" ] || [ ! -d "$BASE/config/plugins" ]; then
    echo "<WARNING> Es wurde kein LoxBerry-Wurzelverzeichnis gefunden - es wurde nichts geaendert."
    exit 1
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

# ---------- Kurzer Selbsttest nach dem Update (0.12.0) ----------
# Laedt die venv wyoming, und ist der Dienst als Python lesbar? Seit 0.12.0
# uebersteht die venv das Update (preupgrade.sh/postinstall.sh); scheitert
# trotzdem etwas, startet der Waechter danach einen Dienst, der sofort am
# fehlenden Paket stirbt - und das Installationsprotokoll liest nach einer
# Selbstaktualisierung niemand. Deshalb zusaetzlich eine Benachrichtigung im
# LoxBerry (bin/sp_notify.php, Aufruf: <Schwere> <Text> <Ordner>; 3 = Fehler).
# Bewusst KEIN --selbsttest des Dienstes: der fragt auch Sprachdienste und
# Mikrofone ab, und deren Lage hat mit dem Update nichts zu tun.
# Der Dienst wird nur gelesen (ast.parse), nicht geladen - beim Laden liefe
# sein Hauptteil an.
SP_PY="$PBIN/venv/bin/python3"
SP_TEST=""
if [ ! -x "$SP_PY" ]; then
    SP_TEST="Die virtuelle Python-Umgebung fehlt ($PBIN/venv)."
elif ! SP_AUS=$(PYTHONDONTWRITEBYTECODE=1 timeout -k 5 60 "$SP_PY" -c 'import wyoming' 2>&1); then
    SP_TEST="wyoming laesst sich nicht laden: $(printf '%s\n' "$SP_AUS" | tail -n 1 | cut -c1-200)"
elif [ ! -f "$PBIN/sprachsteuerung_dienst.py" ]; then
    SP_TEST="bin/sprachsteuerung_dienst.py fehlt."
elif ! SP_AUS=$(PYTHONDONTWRITEBYTECODE=1 timeout -k 5 60 "$SP_PY" -c 'import ast, sys
ast.parse(open(sys.argv[1], encoding="utf-8").read(), sys.argv[1])' "$PBIN/sprachsteuerung_dienst.py" 2>&1); then
    SP_TEST="bin/sprachsteuerung_dienst.py ist nicht lesbar: $(printf '%s\n' "$SP_AUS" | tail -n 1 | cut -c1-200)"
fi
if [ -z "$SP_TEST" ]; then
    echo "<OK> Selbsttest nach dem Update: die virtuelle Umgebung laedt wyoming, der Dienst ist lesbar."
else
    echo "<WARNING> Selbsttest nach dem Update gescheitert: $SP_TEST"
    echo "<INFO> Der Dienst kann so nicht starten. Abhilfe: das Plugin mit Internetverbindung erneut installieren."
    if command -v php >/dev/null 2>&1 && [ -f "$PBIN/sp_notify.php" ]; then
        if env "LBHOMEDIR=$BASE" "LBPPLUGINDIR=$PFOLDER" timeout -k 5 30 \
               php "$PBIN/sp_notify.php" 3 \
               "Nach dem Update kann der Sprachdienst nicht starten: $SP_TEST - Bitte das Plugin mit Internetverbindung erneut installieren." \
               "$PFOLDER" < /dev/null >/dev/null 2>&1; then
            echo "<INFO> Eine Benachrichtigung im LoxBerry wurde abgelegt."
        else
            echo "<INFO> Die Benachrichtigung im LoxBerry liess sich nicht ablegen."
        fi
    fi
fi

echo "<OK> postupgrade abgeschlossen."
exit 0
