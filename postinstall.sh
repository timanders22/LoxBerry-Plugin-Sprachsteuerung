#!/bin/bash
# Sprachsteuerung lokal - postinstall
# command <TEMPFOLDER> <NAME> <FOLDER> <VERSION> <BASEFOLDER>
#
# Das Plugin ist die Vermittlung: es verbindet Mikrofone, Spracherkennung,
# Sprachausgabe und Loxone. Die schweren Teile (Whisper, Piper, Wortwecker,
# Sprachmodell) laufen in Containern.
#
# In die eigene venv kommen nur zwei Pakete: wyoming (das Protokoll der
# Sprachdienste) und aioesphomeapi (fuer ESPHome-Mikrofone). PEP 668 laesst
# ein systemweites pip3 install auf Debian 12/13 nicht zu - deshalb die venv.
# JEDER Rueckgabewert wird geprueft.
#
# ZU DEN MELDUNGSTAGS: Eine Fehlerlage bekommt GENAU EIN <FAIL>; die Saetze
# danach, die erklaeren, was zu tun ist, sind <INFO>. Mehrere <FAIL> in Folge
# sind fuer den Betrachter kein staerkeres Signal, sondern nur laenger - und
# der Log-Leser des Installers stellt die Folgezeilen nicht zuverlaessig dar.

ARGV3=$3
ARGV5=$5
PFOLDER="${ARGV3:-sprachsteuerung}"
BASE="${ARGV5:-$LBHOMEDIR}"
if [ -z "$BASE" ] || [ ! -d "$BASE" ]; then
    SELF=$(cd "$(dirname "$0")" && pwd)
    BASE=$(cd "$SELF/../.." 2>/dev/null && pwd)
fi

PBIN="$BASE/bin/plugins/$PFOLDER"
PDATA="$BASE/data/plugins/$PFOLDER"
PLOG="$BASE/log/plugins/$PFOLDER"
PCONFIG="$BASE/config/plugins/$PFOLDER"
VENV="$PBIN/venv"

mkdir -p "$PDATA/befehle" "$PDATA/antworten" "$PDATA/modelle" "$PDATA/verlauf" \
         "$PLOG" "$PCONFIG" || {
    echo "<FAIL> Ordner konnten nicht angelegt werden."
    exit 1
}
chmod 755 "$PDATA" "$PLOG" "$PCONFIG" 2>/dev/null

[ -f "$PCONFIG/sprachsteuerung.json" ] || echo '{}' > "$PCONFIG/sprachsteuerung.json"
chmod 600 "$PCONFIG/sprachsteuerung.json"
# Die Satzdatei ist Nutzerinhalt: nur anlegen, nie ueberschreiben.
if [ ! -f "$PCONFIG/saetze.json" ]; then
    if [ -f "$BASE/templates/plugins/$PFOLDER/saetze_de.json" ]; then
        cp "$BASE/templates/plugins/$PFOLDER/saetze_de.json" "$PCONFIG/saetze.json"
        echo "<OK> Beispielsaetze eingerichtet."
    else
        echo '{"regeln":[],"ziele":{}}' > "$PCONFIG/saetze.json"
    fi
fi

for f in sprachsteuerung.json saetze.json; do
    BK="$BASE/config/plugins/$PFOLDER.backup.$f"
    CF="$PCONFIG/$f"
    if [ -f "$BK" ]; then
        INHALT=$(cat "$CF" 2>/dev/null)
        if [ ! -s "$CF" ] || [ "$INHALT" = "{}" ]; then
            cp -p "$BK" "$CF" && echo "<OK> $f aus Sicherung wiederhergestellt."
        fi
    fi
done
chmod 600 "$PCONFIG/sprachsteuerung.json"

# ---------- Architektur ----------
ARCH=$(uname -m)
case "$ARCH" in
    x86_64|aarch64|arm64) echo "<OK> Architektur $ARCH ist 64 Bit." ;;
    *)
        echo "<FAIL> Architektur $ARCH ist nicht 64 Bit."
        echo "<INFO> Die Container fuer Whisper, Piper und das Sprachmodell gibt es"
        echo "<INFO> nur fuer 64 Bit. Auf einem 32-Bit-Raspberry-Pi-OS hilft nur ein"
        echo "<INFO> Neuaufsetzen mit einem 64-Bit-Abbild."
        exit 1 ;;
esac

# ---------- Python ----------
if command -v python3 >/dev/null 2>&1 && \
   python3 -c 'import sys; sys.exit(0 if sys.version_info >= (3,9) else 1)'; then
    PY=python3
else
    echo "<FAIL> Es wurde kein Python 3.9 oder neuer gefunden ($(python3 -V 2>&1))."
    exit 1
fi
echo "<INFO> Verwendetes Python: $($PY -V 2>&1)"

if [ ! -x "$VENV/bin/python3" ] || ! "$VENV/bin/python3" -c 'import sys' 2>/dev/null; then
    rm -rf "$VENV"
    if ! "$PY" -m venv "$VENV"; then
        echo "<FAIL> Virtuelle Umgebung konnte nicht angelegt werden ($VENV)."
        echo "<INFO> Fehlt das Paket python3-venv? (apt install python3-venv)"
        exit 1
    fi
    echo "<OK> Virtuelle Umgebung angelegt: $VENV"
fi
"$VENV/bin/python3" -m pip install --upgrade pip >/dev/null 2>&1 || \
    echo "<INFO> pip liess sich nicht aktualisieren - weiter mit der vorhandenen Fassung."

echo "<INFO> Installiere wyoming (benoetigt eine Internetverbindung) ..."
if ! "$VENV/bin/python3" -m pip install --no-cache-dir "wyoming>=1.5"; then
    echo "<FAIL> Das Paket wyoming liess sich nicht installieren."
    echo "<INFO> Ohne dieses Paket kann der Dienst nicht mit den Sprachdiensten reden."
    exit 1
fi
if ! "$VENV/bin/python3" -c 'import wyoming' 2>/dev/null; then
    echo "<FAIL> wyoming ist installiert, laesst sich aber nicht laden."
    exit 1
fi
echo "<OK> wyoming geladen."

# aioesphomeapi ist NUR fuer ESPHome-Mikrofone noetig. Fehlt es, laufen die
# Wyoming-Satelliten trotzdem - deshalb hier kein Abbruch.
echo "<INFO> Installiere aioesphomeapi (nur fuer ESPHome-Mikrofone) ..."
if "$VENV/bin/python3" -m pip install --no-cache-dir "aioesphomeapi>=24" >/dev/null 2>&1 \
   && "$VENV/bin/python3" -c 'import aioesphomeapi' 2>/dev/null; then
    echo "<OK> aioesphomeapi geladen."
else
    echo "<INFO> aioesphomeapi liess sich nicht installieren."
    echo "<INFO> Wyoming-Satelliten laufen trotzdem. ESPHome-Mikrofone (Atom Echo,"
    echo "<INFO> Voice PE, ESP32-S3-BOX) bleiben dann aussen vor."
fi

# ---------- Docker ----------
if command -v docker >/dev/null 2>&1 && docker info >/dev/null 2>&1; then
    echo "<OK> Docker vorhanden und ansprechbar: $(docker --version 2>/dev/null)"
else
    echo "<INFO> Docker ist nicht installiert oder antwortet nicht."
    echo "<INFO> Ohne Docker kann das Plugin die Sprachdienste nicht selbst betreiben."
    echo "<INFO> Wer sie anderswo betreibt, traegt in den Einstellungen nur die"
    echo "<INFO> Adressen ein. Docker nachruesten: LoxBerry-Plugin Docker."
    echo "<INFO> Antwortet Docker nicht, fehlt meist nur die Gruppe - darum"
    echo "<INFO> kuemmert sich postroot.sh gleich im Anschluss. Die neue"
    echo "<INFO> Gruppenzugehoerigkeit wirkt erst nach einem Neustart."
fi

# ---------- Empfehlung gleich ausgeben ----------
if [ -x "$PBIN/hardware.py" ]; then
    echo "<INFO> ----- Vorschlag fuer diese Maschine -----"
    "$VENV/bin/python3" "$PBIN/hardware.py" --klartext 2>/dev/null | sed 's/^/<INFO> /'
fi

chmod 755 "$PBIN/dienst.sh" "$PBIN/sprachsteuerung_dienst.py" "$PBIN/hardware.py" 2>/dev/null
chown -R loxberry:loxberry "$PBIN" "$PDATA" "$PLOG" "$PCONFIG" 2>/dev/null
chmod 600 "$PCONFIG/sprachsteuerung.json"

echo "<OK> Installation abgeschlossen."
echo "<INFO> Weiter in der Plugin-Oberflaeche, Reiter Dienste: dort stehen der"
echo "<INFO> Vorschlag fuer diese Hardware und die Knoepfe, die Container anzulegen."
exit 0
