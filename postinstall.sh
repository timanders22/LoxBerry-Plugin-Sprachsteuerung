#!/bin/bash
# Sprachsteuerung lokal - postinstall
# command <TEMPFOLDER-KENNUNG> <NAME> <FOLDER> <VERSION> <WORKDIR>
# ($1 ist eine zehnstellige Zufallskennung, KEIN Pfad - REGELN_2)
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
# Die Wurzel nach Hausregel (Regeln/06): was der Installer uebergibt oder
# $LBHOMEDIR, wenn darunter config/plugins liegt; sonst vom eigenen Ablageort
# aufwaerts ein Verzeichnis mit config/plugins, data/plugins UND
# config/system/general.json; sonst NICHTS. Bis 0.11.9 stand hier als
# Rueckfall die feste Zahl "$SELF/../..": gemessen am 25.09.2026 in WSL
# (Pruefung-Sprachsteuerung-0.11.10, Fall W3) legte das Skript aus
# <irgendwo>/a/b heraus unter <irgendwo> data/plugins/, log/plugins/ und
# config/plugins/ an.
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
    echo "<FAIL> Es wurde kein LoxBerry-Wurzelverzeichnis gefunden - es wurde nichts eingerichtet."
    echo "<INFO> Weder das Installationsprogramm noch \$LBHOMEDIR nennen eine Wurzel, und oberhalb"
    echo "<INFO> dieses Skripts traegt kein Verzeichnis config/system/general.json."
    exit 1
fi

PBIN="$BASE/bin/plugins/$PFOLDER"
PDATA="$BASE/data/plugins/$PFOLDER"
PLOG="$BASE/log/plugins/$PFOLDER"
PCONFIG="$BASE/config/plugins/$PFOLDER"
VENV="$PBIN/venv"
SPERRE="$BASE/data/plugins/$PFOLDER.upgrade_laeuft"

# ---------- Die Marke "Aktualisierung laeuft" ----------
# preupgrade.sh hat sie als Erstes gelegt; dienst.sh und der Waechter
# starten nicht, solange sie gilt. Hier wird sie ausgewertet und am Ende
# wieder entfernt.
#
# Sie zaehlt nur, wenn sie eine Unixzeit traegt und hoechstens eine Stunde
# alt ist. Eine abgebrochene Installation darf das Plugin nicht fuer immer
# stilllegen; ein Zeitpunkt in der Zukunft ist keine laufende Installation.
#
# Ohne lesbare Uhr gilt eine liegende Marke (auch eine unlesbare) - die
# Pruefung faellt geschlossen aus, wie sperre_gilt() in bin/dienst.sh. Bis
# 0.11.8 stand hier 'MARKE_ALTER=$(( $(date +%s) - MARKE_WERT ))' ohne
# Pruefung der Uhr: lieferte 'date' nichts, galt die Marke nicht, und ein
# Dienst ohne PID-Datei lief waehrend der Installation weiter; eine Ausgabe
# 'a[$(befehl)]' fuehrte die Rechnung aus. Gemessen am 18.09.2026 in WSL
# (Pruefung-Sprachsteuerung-0.11.9, messe_nachtrag2.sh, Faelle Q2-Q5).
MARKE_GILT=""
if [ -f "$SPERRE" ]; then
    MARKE_JETZT=$(date +%s 2>/dev/null)
    MARKE_WERT=$(cat "$SPERRE" 2>/dev/null)
    case "$MARKE_JETZT" in
        ''|*[!0-9]*) MARKE_GILT=ja ;;
        *)
            case "$MARKE_WERT" in
                ''|*[!0-9]*) ;;
                *)
                    MARKE_ALTER=$((MARKE_JETZT - MARKE_WERT))
                    if [ "$MARKE_ALTER" -ge -300 ] && [ "$MARKE_ALTER" -lt 3600 ]; then
                        MARKE_GILT=ja
                    fi
                    ;;
            esac
            ;;
    esac
fi
# Die Marke muss weg, BEVOR der Waechter den Dienst wieder starten darf -
# und zwar auf JEDEM Ausgang dieses Skripts. Es steigt an sechs Stellen mit
# 'exit 1' aus (Architektur, Python, venv, pip, Vorgabenliste); wuerde die
# Marke nur am Ende entfernt, bliebe der Dienst nach einer gescheiterten
# Installation eine Stunde gesperrt, ohne dass irgendwo stuende, warum.
# Deshalb ein trap: er laeuft auch dann, wenn weiter unten abgebrochen wird.
trap 'rm -f "$SPERRE" 2>/dev/null' EXIT

# Ein Dienst, der WAEHREND der Installation laeuft, schreibt in denselben
# Datenordner, in den gleich die Sicherung zurueckkommt. Bei liegender Marke
# wird er deshalb angehalten - auch einer OHNE PID-Datei: die hat
# purge_installation mit dem Datenordner geloescht, 'dienst.sh stop' sieht
# ihn dann nicht (Regeln/06, Bauweise Einspeisebremse 0.9.20, Punkt 3).
# Nur die eigene Befehlszeile und nur der eigene Benutzer - sonst traefe es
# den Dienst einer Zweitinstallation.
#
# Bis 0.11.7 stand hier "pgrep -u ... -f 'bin/plugins/<x>/...\.py$'". Das ist
# eine Suche ueber die ganze Befehlszeile, nicht ueber ein Argument: gemessen
# am 18.09.2026 in WSL (Fall postinstall_pfadkoeder) hat sie ein
# "tail -f <dienstpfad>" desselben Benutzers getroffen und beendet. Ein
# Editor mit der Datei offen faellt in dieselbe Klasse. Seit 0.11.8 deshalb
# argumentweise: argv[0] ist ein Python, argv[1] ist genau der Dienstpfad.
# Bauweise wie in preupgrade.sh und uninstall/uninstall dieser Fassung, nach
# LoxBerry-Plugin-APC-UPS-1.2.11 (apc_ist_dienst).
sp_ist_dienst() {   # $1 Prozessnummer, $2 Dienstpfad
    [ -r "/proc/$1/cmdline" ] || return 1
    tr '\0' '\n' 2>/dev/null < "/proc/$1/cmdline" | {
        IFS= read -r a0 || exit 1
        IFS= read -r a1 || exit 1
        # Ein drittes Argument (auch ein leeres) heisst Einmallauf wie
        # --selbsttest, kein Dienst. Bis 0.11.9 fehlte diese Zeile (gemessen
        # am 25.09.2026 in WSL, Pruefung-Sprachsteuerung-0.11.10, Fall D4).
        IFS= read -r a2 && exit 1
        case "${a0##*/}" in python|python3|python3.*) ;; *) exit 1 ;; esac
        [ "$a1" = "$2" ]
    }
}
sp_dienste_suchen() {  # $1 Dienstpfad, $2 Benutzernummer
    for d in /proc/[0-9]*; do
        [ "$(stat -c %u "$d" 2>/dev/null)" = "$2" ] || continue
        sp_ist_dienst "${d#/proc/}" "$1" && echo "${d#/proc/}"
    done
    return 0
}
# Der Dienst gehoert loxberry - bin/dienst.sh steigt dafuer eigens ab
# (Zeile 41). Dieses Skript laeuft als root; "id -u" allein faende den Dienst
# deshalb am Geraet gar nicht.
SP_DIENST="$PBIN/sprachsteuerung_dienst.py"
SP_UID=$(id -u loxberry 2>/dev/null || id -u)
if [ -n "$MARKE_GILT" ]; then
    [ -x "$PBIN/dienst.sh" ] && "$PBIN/dienst.sh" stop >/dev/null 2>&1
    SP_WAISEN=$(sp_dienste_suchen "$SP_DIENST" "$SP_UID")
    if [ -n "$SP_WAISEN" ]; then
        kill $SP_WAISEN 2>/dev/null
        for _ in 1 2 3 4 5 6 7 8 9 10; do
            [ -n "$(sp_dienste_suchen "$SP_DIENST" "$SP_UID")" ] || break
            sleep 1
        done
        # Vor dem harten Signal neu suchen, nicht die alte Liste benutzen:
        # eine Nummer aus der ersten Runde kann inzwischen einem fremden
        # Vorgang gehoeren.
        SP_REST=$(sp_dienste_suchen "$SP_DIENST" "$SP_UID")
        [ -n "$SP_REST" ] && kill -9 $SP_REST 2>/dev/null
        # Die Wirkung pruefen, nicht den Rueckgabewert von kill.
        if [ -n "$(sp_dienste_suchen "$SP_DIENST" "$SP_UID")" ]; then
            echo "<WARNING> Ein Dienst ohne PID-Datei lief waehrend der Installation"
            echo "<INFO> und liess sich nicht beenden. Bitte den LoxBerry neu starten."
        else
            echo "<OK> Ein Dienst ohne PID-Datei lief waehrend der Installation und wurde beendet."
        fi
    fi
fi

mkdir -p "$PDATA/befehle" "$PDATA/antworten" "$PDATA/modelle" "$PDATA/timer" \
         "$PLOG" "$PCONFIG" || {
    echo "<FAIL> Ordner konnten nicht angelegt werden."
    exit 1
}
chmod 755 "$PDATA" "$PLOG" "$PCONFIG" 2>/dev/null

# ---------- Zweitschriften ZUERST ----------
# Die Reihenfolge ist hier alles. purge_installation loescht
# config/plugins/<x>/ bei JEDEM Upgrade (plugininstall.pl:886 -> :1631).
# Bis 0.10.1 stand die Vorlagenanlage VOR dieser Schleife: saetze.json war
# danach weder leer noch "{}", die Ruecknahme sprang nicht an, und die
# gepflegten Satzmuster und Ziele waren nach jedem Update fort - waehrend
# die Zweitschrift unversehrt daneben lag.
#
# Nach INHALT, nicht nach Groesse - dieselbe Regel wie sp_config_hat_inhalt()
# und sp_saetze() in sp_lib.php. Bis 0.11.9 entschied hier '[ ! -s ]' oder
# ein woertliches '{}': eine Zieldatei '{ }' blieb stehen, obwohl die
# Zweitschrift das Token trug, und eine Zweitschrift '{}' wurde als
# "wiederhergestellt" gemeldet (gemessen am 25.09.2026 in WSL,
# Pruefung-Sprachsteuerung-0.11.10, Faelle Z3, Z5, Z6). Was vorher in der
# Zieldatei stand, liegt danach als <datei>.kaputt daneben (0600), wie in
# sp_config().
sp_hat_inhalt() {   # $1 Datei, $2 aktionstoken | saetze
    [ -s "$1" ] || return 1
    python3 -c 'import json, sys
try:
    d = json.load(open(sys.argv[1], encoding="utf-8"))
except Exception:
    sys.exit(1)
if not isinstance(d, dict) or not d:
    sys.exit(1)
if sys.argv[2] == "saetze":
    sys.exit(0 if ("regeln" in d or "ziele" in d) else 1)
sys.exit(0 if str(d.get("aktionstoken") or "").strip() else 1)' "$1" "$2" 2>/dev/null
}
for f in sprachsteuerung.json saetze.json; do
    BK="$BASE/config/plugins/$PFOLDER.backup.$f"
    CF="$PCONFIG/$f"
    [ -f "$BK" ] || continue
    MERKMAL=aktionstoken
    [ "$f" = saetze.json ] && MERKMAL=saetze
    sp_hat_inhalt "$CF" "$MERKMAL" && continue
    if ! sp_hat_inhalt "$BK" "$MERKMAL"; then
        echo "<INFO> Die Zweitschrift von $f traegt keinen Inhalt - daraus wird nichts zurueckgespielt."
        continue
    fi
    REST=$(tr -d '[:space:]' < "$CF" 2>/dev/null)
    if [ -n "$REST" ] && [ "$REST" != "{}" ] && [ "$REST" != "[]" ]; then
        cp -p "$CF" "$CF.kaputt" 2>/dev/null && chmod 600 "$CF.kaputt" 2>/dev/null
    fi
    if cp -p "$BK" "$CF" && cmp -s "$BK" "$CF"; then
        echo "<OK> $f aus der Zweitschrift wiederhergestellt."
    else
        echo "<WARNING> $f liess sich nicht aus der Zweitschrift zurueckspielen: $BK"
    fi
done

[ -f "$PCONFIG/sprachsteuerung.json" ] || echo '{}' > "$PCONFIG/sprachsteuerung.json"
chmod 600 "$PCONFIG/sprachsteuerung.json"
# Die Satzdatei ist Nutzerinhalt: nur anlegen, nie ueberschreiben. Nach der
# Schleife oben greift das nur noch bei einer Neuinstallation.
if [ ! -f "$PCONFIG/saetze.json" ]; then
    # Die Beispielsaetze richten sich nach der Oberflaechensprache des
    # LoxBerry. Bis 0.9.11 lag nur die deutsche Fassung bei; die Oberflaeche
    # war zweisprachig, das Verstehen nicht.
    SPRACHE=$(sed -n 's/.*"Lang"[[:space:]]*:[[:space:]]*"\([a-z][a-z]\)".*/\1/p'               "$BASE/config/system/general.json" 2>/dev/null | head -n 1)
    [ -n "$SPRACHE" ] || SPRACHE=de
    QUELLE="$BASE/templates/plugins/$PFOLDER/saetze_$SPRACHE.json"
    [ -f "$QUELLE" ] || QUELLE="$BASE/templates/plugins/$PFOLDER/saetze_de.json"
    if [ -f "$QUELLE" ]; then
        cp "$QUELLE" "$PCONFIG/saetze.json"
        echo "<OK> Beispielsaetze eingerichtet ($(basename "$QUELLE"))."
    else
        echo '{"regeln":[],"ziele":{}}' > "$PCONFIG/saetze.json"
    fi
fi


# ---------- Das Rueckgabefenster ----------
# Alles, was ueber das Update gerettet wurde, kommt HIER zurueck - noch
# vor Architekturpruefung, venv und Docker. Bis 0.10.2 stand dieser Teil
# am Dateiende: waere die venv gescheitert, haette postinstall.sh vorher
# mit exit 1 aufgehoert, und die geretteten Werte waeren im Nachbarordner
# liegengeblieben - gerettet und nie zurueckgegeben.
# ---------- Langzeitwerte zurueckholen ----------
# Gegenstueck zu preupgrade.sh. Zwischen beiden Skripten hat der Installer
# data/plugins/<x>/ vollstaendig geloescht; der Nachbar mit dem Punkt hat es
# ueberstanden. Eine Neuinstallation findet keine Sicherung vor und faengt
# sauber bei null an.
#
# Traegt die Sicherung den Zeitpunkt aus preupgrade.sh und ist er hoechstens
# eine Stunde alt, ist sie die von eben: dann kommt jede gesicherte Datei
# zurueck, OHNE nach dem Inhalt der Zieldatei zu fragen. Bis 0.11.6 kam sie
# nur zurueck, wenn die Zieldatei fehlte oder leer war. Der Installer legt
# die Cron-Datei aber vor diesem Skript an (am Geraet 52 s vorher gemessen,
# Einspeisebremse 08.09.2026). In WSL nachgestellt (17.09.2026): startet der
# Waechter in dieser Luecke den Dienst und verarbeitet der einen Satz, legt
# er verlauf.json selbst an - die Rueckholung sprang nicht an, die Sicherung
# wurde trotzdem geloescht, der Verlauf war fort. Laeuft der Dienst danach
# weiter, schadet das nicht: er liest die Datei vor jedem Eintrag neu und
# schreibt an die zurueckgeholte an. Was er in der Luecke eingetragen hat,
# geht dabei verloren.
#
# Entschieden wird am Zeitpunkt IN der Sicherung, nicht an der Marke oben:
# preupgrade.sh schreibt beide im selben Durchlauf, aber der Zeitpunkt
# gehoert zur Sicherung und liegt bei ihr. Er beantwortet deshalb auch den
# Fall, in dem die Marke fehlt (Erstinstallation dieser Fassung ueber eine
# aeltere hinweg, von Hand entfernt) - und er beantwortet ihn fuer GENAU
# diese Sicherung, waehrend die Marke nur sagt, dass irgendetwas laeuft.
#
# Ohne Zeitpunkt, mit einem aelteren oder einem in der Zukunft stammt die
# Sicherung NICHT aus diesem Vorgang. Dann wird daraus NICHTS eingespielt.
# Bis 0.11.6 fuellte sie noch, was im Datenordner fehlte oder leer war -
# das ist falsch: eine solche Sicherung kann von einer Deinstallation
# stammen, die nicht aufgeraeumt hat, oder von einem Update vor Monaten.
# Was sie traegt, ist dann aelter als alles, was jetzt dasteht, und das
# Einspielen legte alten Verlauf ueber eine frische Installation
# (Entscheidung des Hausherrn, 17.09.2026). Sie bleibt liegen und wird
# EINMAL gemeldet; wer sie doch will, kopiert sie von Hand. Damit sie gar
# nicht erst liegenbleibt, raeumt uninstall/uninstall sie weg.
#
# Die Sicherung verschwindet erst, wenn jede Rueckholung gelungen ist.
# Zurueckgeschrieben wird ueber eine Nebendatei und mv: cp schreibt in die
# Zieldatei hinein, mv tauscht sie in einem Schritt aus.
LANG_SICHER="$BASE/data/plugins/$PFOLDER.upgrade_sicherung"
if [ -d "$LANG_SICHER" ]; then
    LANG_JETZT=$(date +%s)
    LANG_ANGELEGT=$(cat "$LANG_SICHER/angelegt" 2>/dev/null)
    LANG_FRISCH=""
    case "$LANG_ANGELEGT" in
        ''|*[!0-9]*) ;;
        *)
            if [ "$LANG_JETZT" -ge "$LANG_ANGELEGT" ] \
               && [ $((LANG_JETZT - LANG_ANGELEGT)) -le 3600 ]; then
                LANG_FRISCH=ja
            fi
            ;;
    esac
    if [ -z "$LANG_FRISCH" ]; then
        LANG_WANN="unbekannt"
        [ -n "$LANG_ANGELEGT" ] && LANG_WANN=$(date -d "@$LANG_ANGELEGT" '+%Y-%m-%d %H:%M:%S' 2>/dev/null || echo "$LANG_ANGELEGT")
        echo "<WARNING> Die Sicherung der Langzeitwerte stammt nicht aus diesem Vorgang (angelegt: $LANG_WANN)."
        echo "<INFO> Daraus wird nichts eingespielt - sie koennte aelter sein als das,"
        echo "<INFO> was jetzt im Datenordner steht. Sie bleibt unberuehrt liegen:"
        echo "<INFO> $LANG_SICHER"
        echo "<INFO> Wer sie doch will, kopiert die Dateien von dort nach $PDATA/."
    else
        LANG_FEHLER=0
        for LANG_F in verlauf.json messwerte.json ansagen.json; do
            [ -f "$LANG_SICHER/$LANG_F" ] || continue
            LANG_ZIEL="$PDATA/$LANG_F"
            if cp -p "$LANG_SICHER/$LANG_F" "$LANG_ZIEL.rueckholung" 2>/dev/null \
               && mv -f "$LANG_ZIEL.rueckholung" "$LANG_ZIEL" 2>/dev/null; then
                echo "<OK> $LANG_F ueber das Update gerettet."
            else
                rm -f "$LANG_ZIEL.rueckholung" 2>/dev/null
                LANG_FEHLER=$((LANG_FEHLER + 1))
                echo "<WARNING> $LANG_F liess sich nicht aus der Sicherung zurueckholen."
            fi
        done
        if [ "$LANG_FEHLER" -eq 0 ]; then
            rm -rf "$LANG_SICHER" 2>/dev/null
            [ -d "$LANG_SICHER" ] && echo "<INFO> Die Sicherung liess sich nicht entfernen: $LANG_SICHER"
        else
            echo "<INFO> Die Sicherung bleibt deshalb liegen: $LANG_SICHER"
            echo "<INFO> Von dort laesst sich die Datei von Hand nach $PDATA/ kopieren."
        fi
    fi
fi

# ---------- Der Sollmerker ----------
# Er liegt unter data/plugins/<x>/ und wird beim Upgrade mitgeloescht.
# preupgrade.sh haelt den Dienst an; startet ihn danach niemand, steht das
# Plugin still, die Installation meldet Erfolg, und in Loxone sieht es aus
# wie ein ruhiges Haus. Der Merker ist eine LEERE Datei - '[ ! -s ]' traefe
# ihn nicht, deshalb '[ ! -e ]'.
if [ -e "$BASE/data/plugins/$PFOLDER.soll_laufen" ]; then
    if [ ! -e "$PDATA/soll_laufen" ]; then
        touch "$PDATA/soll_laufen" \
            && echo "<OK> Der Dienst lief vor dem Update - der Waechter holt ihn"
        echo "<INFO> binnen einer Minute zurueck."
    fi
    rm -f "$BASE/data/plugins/$PFOLDER.soll_laufen" 2>/dev/null
fi

# ---------- Die heruntergeladenen Modelle ----------
# Sie liegen unter data/plugins/<x>/modelle und werden von den Containern
# eingehaengt. preupgrade.sh hat den Ordner NEBEN den Plugin-Ordner
# verschoben (mv auf demselben Dateisystem, also ohne die Gigabyte zu
# kopieren); hier kommt er zurueck an seinen Platz.
UMZUG="$BASE/data/plugins/$PFOLDER.modelle_umzug"
if [ -d "$UMZUG" ]; then
    # Der frisch angelegte leere Ordner muss weg, bevor der alte zurueckkann.
    # rmdir schlaegt fehl, wenn doch etwas darin liegt - das ist gewollt.
    rmdir "$PDATA/modelle" 2>/dev/null
    if [ ! -e "$PDATA/modelle" ] && mv "$UMZUG" "$PDATA/modelle" 2>/dev/null; then
        echo "<OK> Die heruntergeladenen Modelle sind ueber das Update gerettet."
    else
        echo "<INFO> Die Modelle liegen unter $UMZUG und mussten dort bleiben."
        echo "<INFO> Bitte den Ordner von Hand nach $PDATA/modelle verschieben."
    fi
fi

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

# Eine Ladepruefung, die Pythons Meldung nach /dev/null schickt, verschweigt
# die Ursache: im Installationsprotokoll stuende, DASS es nicht geht, nirgends
# WARUM. Deshalb wird die Meldung eingefangen und eingerueckt ausgegeben.
# (Hausregel seit 12.09.2026, Anlass Anker SOLIX 0.9.14.)
laden_pruefen() {
    # $1 = Modul, $2 = was ausgegeben wird, wenn es klappt
    if LADEAUSGABE=$("$VENV/bin/python3" -c "import sys, $1; print(sys.modules['$1'].__file__)" 2>&1); then
        # Nicht nur DASS geladen wurde, sondern WOHER: ein gewoehnlicher
        # Paketname kann von einer gleichnamigen Datei neben dem Dienstskript
        # lautlos verdeckt werden, und dann laedt der Dienst etwas anderes,
        # als hier geprueft wurde.
        echo "<OK> $2"
        echo "<INFO>     geladen aus: $LADEAUSGABE"
        return 0
    fi
    echo "$LADEAUSGABE" | sed 's/^/<FAIL>     /'
    return 1
}

if [ ! -x "$VENV/bin/python3" ] || ! VENVFEHLER=$("$VENV/bin/python3" -c 'import sys' 2>&1); then
    if [ -n "${VENVFEHLER:-}" ]; then
        echo "<INFO> Die vorhandene virtuelle Umgebung antwortet nicht und wird neu angelegt:"
        echo "$VENVFEHLER" | sed 's/^/<INFO>     /'
    fi
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
if ! laden_pruefen wyoming "wyoming geladen."; then
    echo "<FAIL> wyoming ist installiert, laesst sich aber nicht laden."
    echo "<INFO> Die Meldung von Python steht in den Zeilen darueber."
    exit 1
fi

# aioesphomeapi ist NUR fuer ESPHome-Mikrofone noetig. Fehlt es, laufen die
# Wyoming-Satelliten trotzdem - deshalb hier kein Abbruch.
echo "<INFO> Installiere aioesphomeapi (nur fuer ESPHome-Mikrofone) ..."
if ! PIPFEHLER=$("$VENV/bin/python3" -m pip install --no-cache-dir "aioesphomeapi>=24" 2>&1); then
    echo "<INFO> aioesphomeapi liess sich nicht installieren:"
    echo "$PIPFEHLER" | tail -n 5 | sed 's/^/<INFO>     /'
    AIO=1
elif ! laden_pruefen aioesphomeapi "aioesphomeapi geladen."; then
    # pip meldet Erfolg auch dann, wenn kein einziges abhaengiges Paket
    # mitgekommen ist (Hausregel 12.09.2026: Wheel ohne Requires-Dist). Erst
    # der Ladeversuch beantwortet die Frage, und seine Meldung nennt, welches
    # Paket fehlt.
    echo "<INFO> aioesphomeapi ist installiert, laesst sich aber nicht laden."
    AIO=1
else
    AIO=0
fi
if [ "$AIO" != "0" ]; then
    echo "<INFO> Wyoming-Satelliten laufen trotzdem. ESPHome-Mikrofone (Atom Echo,"
    echo "<INFO> Voice PE, ESP32-S3-BOX) bleiben dann aussen vor."
fi

# Welche Fassungen wirklich liegen. Ohne diese Zeilen ist im Nachhinein nicht
# mehr festzustellen, womit eine Anlage gelaufen ist - und die Frage kommt
# immer dann, wenn etwas nicht geht (Hausregel 12.09.2026).
echo "<INFO> Installierte Pakete in der virtuellen Umgebung:"
"$VENV/bin/python3" -m pip list --format=freeze 2>/dev/null | sed 's/^/<INFO>     /'

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

# ---------- Die gemeinsame Vorgabenliste ----------
# Seit 0.10.0 lesen BEIDE Seiten - Oberflaeche und Dienst - ihre Vorgabewerte
# aus templates/vorgaben.json. Fehlt die Datei, kennt keiner von beiden einen
# Vorgabewert; das ist kein stiller Fehler, sondern einer, der gemeldet gehoert.
if [ -f "$BASE/templates/plugins/$PFOLDER/vorgaben.json" ]; then
    echo "<OK> Vorgabenliste eingerichtet."
else
    echo "<FAIL> templates/vorgaben.json fehlt nach der Installation."
    echo "<INFO> Ohne diese Datei kennen weder Oberflaeche noch Dienst ihre"
    echo "<INFO> Vorgabewerte. Das Plugin bitte erneut installieren."
    # Ein <FAIL>, nach dem 'Installation abgeschlossen' folgt, ist keines.
    exit 1
fi

chmod 755 "$PBIN/dienst.sh" "$PBIN/sprachsteuerung_dienst.py" "$PBIN/hardware.py" 2>/dev/null
chown -R loxberry:loxberry "$PBIN" "$PDATA" "$PLOG" "$PCONFIG" 2>/dev/null
chmod 600 "$PCONFIG/sprachsteuerung.json"

echo "<OK> Installation abgeschlossen."
echo "<INFO> Weiter in der Plugin-Oberflaeche, Reiter Dienste: dort stehen der"
echo "<INFO> Vorschlag fuer diese Hardware und die Knoepfe, die Container anzulegen."
exit 0
