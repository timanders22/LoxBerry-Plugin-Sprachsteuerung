#!/bin/bash
# Sprachsteuerung lokal - preinstall
# command <TEMPFOLDER-KENNUNG> <NAME> <FOLDER> <VERSION> <WORKDIR>
# ($1 ist eine zehnstellige Zufallskennung, KEIN Pfad - REGELN_2)
#
# Neu im Nachzug G1 vom 02.10.2026 (X-1, Entscheidung 1 vom 29.09.2026;
# Muster: Govee 0.9.24, Abfahrts-Assistent 1.6.16). Der Installer ruft dieses
# Skript bei JEDEM Einbau auf, nach dem Aufraeumen der alten Fassung und VOR
# dem Kopieren von Konfiguration, Cron-Datei und Oberflaeche
# (sbin/plugininstall.pl: preupgrade :846, purge :874, preinstall :877,
# Cron :990, HTML :1066 - Geraet/2026-09-05/08_plugininstall.pl).
#
# Eine Aktualisierung erkennt es allein an der Marke
# data/plugins/<ordner>.upgrade_laeuft, die preupgrade.sh als Erstes anlegt
# (kein Altersvergleich). Dann tut es nichts: Zweitschriften und
# Langzeitsicherung gehoeren postinstall.sh.
#
# Ohne Marke ist es eine NEUINSTALLATION. Was eine fruehere Installation
# hinterlassen hat - <ordner>.backup.sprachsteuerung.json (Konfiguration mit
# Aktionstoken und Miniserver-Zugang), <ordner>.backup.saetze.json (Satzmuster),
# data/plugins/<ordner>.upgrade_sicherung (Verlauf, Messreihe, Ansagezeiten)
# und der Sollmerker data/plugins/<ordner>.soll_laufen (er startete sonst den
# Dienst ungefragt; F3) - geht nach <name>.alt, gemeldet mit genau einer
# <WARNING>. Die heruntergeladenen Modelle (<ordner>.modelle_umzug) bleiben:
# Downloads ohne Zugangsdaten, sie werden weiter benutzt. Dasselbe gilt seit
# 0.12.0 fuer die beiseitegelegte virtuelle Python-Umgebung
# (<ordner>.venv_umzug); postinstall.sh prueft sie vor dem Gebrauch.
#
# Warum schon hier: zwischen dem Kopieren und postinstall.sh ist die
# Oberflaeche schon da. sp_config() und sp_saetze() heilen eine fehlende
# Datei aus der Zweitschrift (webfrontend/html/sp_lib.php), und die neue
# Installation truege danach Token, Zugang und Saetze der frueheren (gemessen
# im Nachzug G1, vb_g1_bau_skripte/sp/proben/x1_*). Die Selbstheilung liest
# .alt nie; die Deinstallation raeumt es ab.

ARGV3=$3
ARGV5=$5
# Rueckfall, falls sudo die Umgebung ausgeraeumt hat (env_reset).
LBHOMEDIR="${LBHOMEDIR:-$5}"
PFOLDER="${ARGV3:-sprachsteuerung}"
BASE="${ARGV5:-$LBHOMEDIR}"

# Wurzelsuche: ohne config/plugins, data/plugins UND config/system/general.json
# wird nichts angefasst (Regeln/06, Raumklima-Vorfall).
if [ -z "$BASE" ] || [ ! -d "$BASE/config/plugins" ] || [ ! -d "$BASE/data/plugins" ] \
   || [ ! -f "$BASE/config/system/general.json" ]; then
    echo "<WARNING> Kein LoxBerry-Wurzelverzeichnis erkannt ('$BASE') - nichts beiseitegelegt."
    exit 0
fi
# Der Ordnername darf keinen Pfadtrenner tragen, sonst griffe mv/rm daneben.
case "$PFOLDER" in
    ''|*/*|*..*) echo "<WARNING> Unzulaessiger Ordnername '$PFOLDER' - nichts beiseitegelegt."; exit 0 ;;
esac

if [ -f "$BASE/data/plugins/$PFOLDER.upgrade_laeuft" ]; then
    # Aktualisierung: nichts zu tun, postinstall.sh spielt zurueck.
    exit 0
fi

# Ein vorhandenes .alt geht vorher weg: ein Verweis nur als Verweis, eine
# Datei ueberschrieben (sie kann Zugangsdaten tragen), ein Ordner geloescht.
sp_alt_weg() {
    if [ -L "$1" ]; then
        rm -f "$1"
    elif [ -d "$1" ]; then
        rm -rf "${1:?}"
    elif [ -f "$1" ]; then
        sp_l=$(stat -c %s "$1" 2>/dev/null || echo 0)
        [ "$sp_l" -gt 0 ] && dd if=/dev/zero of="$1" bs=1 count="$sp_l" conv=notrunc >/dev/null 2>&1
        rm -f "$1"
    fi
}

SP_ALT=""
for z in "$BASE/config/plugins/$PFOLDER.backup.sprachsteuerung.json" \
         "$BASE/config/plugins/$PFOLDER.backup.saetze.json" \
         "$BASE/data/plugins/$PFOLDER.upgrade_sicherung" \
         "$BASE/data/plugins/$PFOLDER.soll_laufen"; do
    [ -e "$z" ] || [ -L "$z" ] || continue
    if [ -e "$z.alt" ] || [ -L "$z.alt" ]; then
        sp_alt_weg "$z.alt"
    fi
    if mv -f "$z" "$z.alt" 2>/dev/null; then
        SP_ALT="$SP_ALT $z.alt"
        [ -f "$z.alt" ] && [ ! -L "$z.alt" ] && chmod 600 "$z.alt" 2>/dev/null
    else
        SP_ALT="$SP_ALT $z (liess sich NICHT verschieben)"
    fi
done
if [ -n "$SP_ALT" ]; then
    echo "<WARNING> Neuinstallation: Einstellungen und Langzeitwerte einer frueheren Installation werden NICHT eingespielt, sondern beiseitegelegt:$SP_ALT (die Deinstallation raeumt sie ab)."
fi
exit 0
