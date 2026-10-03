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
# Seit 0.12.0 mit festen Fassungen aus bin/requirements.txt (Pflicht) und
# bin/requirements-esphome.txt (freiwillig), und pip laeuft nur noch, wenn es
# noetig ist: die venv uebersteht das Update (preupgrade.sh legt sie neben den
# Datenordner, hier kommt sie zurueck), und erst wenn sie fehlt, nicht laedt
# oder die Pruefsumme der Anforderungen sich geaendert hat, wird installiert.
#
# Dieses Skript laeuft als loxberry (plugininstall.pl: "sudo -n -u loxberry"),
# nicht als root - alles, was es anlegt, gehoert damit loxberry.
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
# Sie gilt, wenn sie LIEGT - ohne Altersvergleich (Entscheidung 1 vom
# 29.09.2026; Nachzug G1, 02.10.2026). Bis 0.11.14 galt sie hier nur, wenn
# sie hoechstens eine Stunde alt war: ein Update mit mehr als einer Stunde
# zwischen preupgrade.sh und postinstall.sh galt damit als Neuinstallation.
# Die 3600 s bleiben allein fuer die Startsperre des Dienstes (sperre_gilt()
# in bin/dienst.sh): eine abgebrochene Installation darf den Dienst nicht fuer
# immer stilllegen. Hier entscheidet die Marke, ob ZURUECKGESPIELT wird; sie
# wird am Ende dieses Skripts entfernt (trap unten), auf jedem Ausgang.
MARKE_GILT=""
[ -f "$SPERRE" ] && MARKE_GILT=ja
# Die Marke muss weg, BEVOR der Waechter den Dienst wieder starten darf -
# und zwar auf JEDEM Ausgang dieses Skripts. Es steigt an mehreren Stellen
# mit 'exit 1' aus (Architektur, Python, venv, pip, Anforderungsliste,
# Vorgabenliste); wuerde die Marke nur am Ende entfernt, bliebe der Dienst
# nach einer gescheiterten Installation eine Stunde gesperrt, ohne dass
# irgendwo stuende, warum.
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
        # Auch gegen den physischen Pfad (0.12.0): bin/dienst.sh startet mit
        # 'pwd -P'. Ist die Wurzel ein Verweis (etwa /opt/loxberry), fand
        # der Vergleich mit dem zusammengesetzten Pfad den Dienst nicht.
        [ "$a1" = "$2" ] || { [ -n "$SP_DIENST_P" ] && [ "$a1" = "$SP_DIENST_P" ]; }
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
# (Zeile 41). Dieses Skript laeuft ebenfalls als loxberry (der Installer ruft
# es mit "sudo -n -u loxberry"); bis 0.11.15 stand hier, es laufe als root.
# "id -u loxberry" zuerst bleibt trotzdem richtig: von Hand mit sudo
# aufgerufen, faende "id -u" allein den Dienst nicht. Wo es den Benutzer
# nicht gibt (Pruefstand), gilt der eigene.
SP_DIENST="$PBIN/sprachsteuerung_dienst.py"
# Derselbe Pfad physisch, wie ihn bin/dienst.sh ('pwd -P') dem Dienst
# mitgibt - siehe sp_ist_dienst().
SP_PBIN_P=$(cd "$PBIN" 2>/dev/null && pwd -P)
SP_DIENST_P="${SP_PBIN_P:+$SP_PBIN_P/sprachsteuerung_dienst.py}"
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
# NUR BEI EINER AKTUALISIERUNG (Entscheidung 1; Nachzug G1, 02.10.2026). Bis
# 0.11.14 lief diese Schleife ohne Blick auf die Marke: eine Neuinstallation
# ueber liegengebliebene Zweitschriften holte Aktionstoken, Miniserver-Zugang
# und Saetze einer frueheren Installation zurueck (gemessen,
# vb_g1_bau_skripte/sp/proben/x1_vorher.txt). Ohne Marke hat preinstall.sh
# sie schon nach .alt gelegt; was trotzdem liegt, legt der Rueckfallzweig
# weiter unten (sp_neuinstallation_beiseite) beiseite.
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
sp_neuinstallation_beiseite() {   # $1 Pfad; nur ohne Marke aufgerufen
    [ -e "$1" ] || [ -L "$1" ] || return 0
    if [ -e "$1.alt" ] || [ -L "$1.alt" ]; then
        sp_alt_weg "$1.alt"
    fi
    if mv -f "$1" "$1.alt" 2>/dev/null; then
        SP_ALT="$SP_ALT $1.alt"
        [ -f "$1.alt" ] && [ ! -L "$1.alt" ] && chmod 600 "$1.alt" 2>/dev/null
    else
        SP_ALT="$SP_ALT $1 (liess sich NICHT verschieben)"
    fi
}
if [ -z "$MARKE_GILT" ]; then
    for f in sprachsteuerung.json saetze.json; do
        sp_neuinstallation_beiseite "$BASE/config/plugins/$PFOLDER.backup.$f"
    done
fi
for f in sprachsteuerung.json saetze.json; do
    [ -n "$MARKE_GILT" ] || continue
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
    # 2>/dev/null VOR der Umleitung: fehlt die Zieldatei (nach purge die
    # Regel), meldet sonst die Schale "No such file" ins Protokoll.
    REST=$(tr -d '[:space:]' 2>/dev/null < "$CF")
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
# ueberstanden.
#
# Entschieden wird an der MARKE, ohne Altersvergleich (Entscheidung 1;
# Nachzug G1, 02.10.2026, vom Koordinator entschieden). Bis 0.11.14 stand hier
# der Zeitpunkt IN der Sicherung mit einer Grenze von 3600 s: eine
# Aktualisierung, die laenger dauerte, spielte die Werte nicht zurueck, und
# die Sicherung blieb liegen. Dass die Sicherung aus DIESEM Vorgang stammt,
# sagt seither preupgrade.sh zu: es legt eine liegengebliebene Sicherung aus
# einem frueheren Vorgang nach .alt, bevor es die neue anlegt.
#
# Mit Marke kommt jede gesicherte Datei zurueck, OHNE nach dem Inhalt der
# Zieldatei zu fragen (bis 0.11.6 nur bei fehlender oder leerer Zieldatei; der
# Waechter kann in der Luecke vor diesem Skript verlauf.json selbst angelegt
# haben - in WSL nachgestellt 17.09.2026). Was der Dienst in der Luecke
# eingetragen hat, geht dabei verloren.
#
# Ohne Marke ist es eine Neuinstallation: preinstall.sh hat die Sicherung
# schon nach .alt gelegt; liegt trotzdem eine da, legt der Rueckfallzweig sie
# beiseite, und nichts davon wird eingespielt.
#
# Die Sicherung verschwindet erst, wenn jede Rueckholung gelungen ist.
# Zurueckgeschrieben wird ueber eine Nebendatei und mv: cp schreibt in die
# Zieldatei hinein, mv tauscht sie in einem Schritt aus.
LANG_SICHER="$BASE/data/plugins/$PFOLDER.upgrade_sicherung"
if [ -z "$MARKE_GILT" ]; then
    sp_neuinstallation_beiseite "$LANG_SICHER"
    # F3: der Sollmerker gehoert ebenso zu einer frueheren Installation.
    sp_neuinstallation_beiseite "$BASE/data/plugins/$PFOLDER.soll_laufen"
elif [ -d "$LANG_SICHER" ]; then
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
# Genau EINE Meldung fuer alles, was der Rueckfallzweig beiseitegelegt hat.
# Nach preinstall.sh findet er nichts mehr und schweigt.
if [ -n "$SP_ALT" ]; then
    echo "<WARNING> Neuinstallation: Einstellungen und Langzeitwerte einer frueheren Installation werden NICHT eingespielt, sondern beiseitegelegt:$SP_ALT (die Deinstallation raeumt sie ab)."
fi

# ---------- Der Sollmerker ----------
# Er liegt unter data/plugins/<x>/ und wird beim Upgrade mitgeloescht.
# preupgrade.sh haelt den Dienst an; startet ihn danach niemand, steht das
# Plugin still, die Installation meldet Erfolg, und in Loxone sieht es aus
# wie ein ruhiges Haus. Der Merker ist eine LEERE Datei - '[ ! -s ]' traefe
# ihn nicht, deshalb '[ ! -e ]'.
# Nur mit Marke (F3; Entscheidung 1, Nachzug G1 02.10.2026). Bis 0.11.14 wurde
# er ohne Blick auf die Marke ausgewertet: ein liegengebliebener Merker startete
# nach einer Neuinstallation den Dienst ungefragt. Ohne Marke liegt er schon
# unter .alt (preinstall.sh bzw. Rueckfallzweig oben).
if [ -n "$MARKE_GILT" ] && [ -e "$BASE/data/plugins/$PFOLDER.soll_laufen" ]; then
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
# Die vier Einhaengeordner der Container vorbeugend als loxberry anlegen
# (0.12.0) - erst HIER, nach der Rueckholung: das rmdir oben verlangt einen
# leeren Ordner. Fehlt der Ordner beim "docker run -v .../modelle/<dienst>",
# legt ihn der Docker-Dienst als root an; was die Container hineinschreiben,
# gehoert ohnehin root. purge_installation loescht als loxberry und liess
# beides bei der Deinstallation liegen - Gigabyte. Ein eigener Ordner
# erlaubt loxberry wenigstens, die Eintraege direkt darin zu entfernen; den
# Rest raeumt uninstall/uninstall als root ab.
for SP_D in whisper piper wakeword llm; do
    mkdir -p "$PDATA/modelle/$SP_D" 2>/dev/null
done

# ---------- Die virtuelle Python-Umgebung zurueckholen ----------
# Gegenstueck zu preupgrade.sh (0.12.0): purge_installation hat
# bin/plugins/<x>/ samt venv geloescht; die venv lag daneben unter
# data/plugins/<x>.venv_umzug. Sie kommt an GENAU denselben Pfad zurueck -
# die Skripte in venv/bin tragen ihn als Shebang. Ob sie taugt, entscheidet
# weiter unten die Ladeprobe; hier wird nur verschoben.
# Wie bei den Modellen auch ohne Marke: eine liegengebliebene venv derselben
# Wurzel und desselben Ordners passt an genau diesen Pfad, und die Ladeprobe
# verwirft sie, wenn nicht.
VENV_UMZUG="$BASE/data/plugins/$PFOLDER.venv_umzug"
if [ -d "$VENV_UMZUG" ] && [ ! -L "$VENV_UMZUG" ]; then
    if [ -e "$VENV" ] || [ -L "$VENV" ]; then
        # Kommt nur vor, wenn hier schon eine venv liegt (das Paket bringt
        # keine mit, purge hat bin/ geleert): sie gilt, die alte geht weg.
        rm -rf "${VENV_UMZUG:?}" 2>/dev/null
        echo "<INFO> Es lag schon eine virtuelle Umgebung im Plugin-Ordner - die beiseitegelegte wurde verworfen."
    elif mv "$VENV_UMZUG" "$VENV" 2>/dev/null; then
        echo "<OK> Die virtuelle Python-Umgebung ist ueber das Update gerettet."
    else
        echo "<INFO> Die virtuelle Python-Umgebung liess sich nicht zurueckholen ($VENV_UMZUG)"
        echo "<INFO> - sie wird neu angelegt."
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

# ---------- Die virtuelle Umgebung ----------
# Seit 0.12.0 stehen die Pakete mit FESTEN Fassungen in bin/requirements.txt
# (Pflicht: wyoming) und bin/requirements-esphome.txt (freiwillig:
# aioesphomeapi). Bis 0.11.15 stand hier "wyoming>=1.5" und
# "aioesphomeapi>=24": jede Installation holte die jeweils neueste Fassung -
# auch eine, gegen die der Dienst nie geprueft wurde.
#
# pip laeuft nur, wenn es noetig ist: die venv fehlt, laedt wyoming nicht,
# oder die Pruefsumme der Anforderungen (Inhalt der Liste plus Python-Fassung)
# weicht von der ab, die nach der letzten erfolgreichen Installation in der
# venv abgelegt wurde. Sonst kein Netz, kein pip - ein Update ohne Netz geht
# damit durch, und eine Selbstaktualisierung laedt nicht jedes Mal neu.
#
# Scheitert pip, waehrend eine funktionierende alte venv da ist, bleibt sie
# in Gebrauch: eine <WARNING>, kein Abbruch. Erst wenn danach wyoming nicht
# laedt, ist es ein <FAIL>. Bis 0.11.15 endete jedes gescheiterte pip mit
# exit 1, und der Dienst starb danach am fehlenden Paket.
REQ="$PBIN/requirements.txt"
REQ_ESP="$PBIN/requirements-esphome.txt"
STEMPEL="$VENV/.sp_anforderungen"
STEMPEL_ESP="$VENV/.sp_anforderungen_esphome"
PYVER=$("$PY" -c 'import sys; print("%d.%d" % sys.version_info[:2])' 2>/dev/null)

if [ ! -f "$REQ" ]; then
    echo "<FAIL> bin/requirements.txt fehlt nach der Installation."
    echo "<INFO> Ohne diese Liste ist nicht festgelegt, welche Pakete der Dienst braucht."
    echo "<INFO> Das Plugin bitte erneut installieren."
    exit 1
fi

# Die Pruefsumme einer Anforderungsliste. Die Python-Fassung gehoert dazu:
# eine venv fuer 3.11 traegt ihre Pakete unter lib/python3.11 und ist fuer
# 3.13 leer. Leer zurueck, wenn sha256sum fehlt - dann gilt nichts als
# unveraendert (siehe die Vergleiche unten: nur eine NICHT leere Summe zaehlt).
sp_pruefsumme() {   # $1 Datei
    { cat "$1" 2>/dev/null; echo "python=$PYVER"; } | sha256sum 2>/dev/null | cut -d' ' -f1
}

# Taugt die vorhandene venv zum System-Python? pyvenv.cfg nennt die Fassung,
# mit der sie angelegt wurde. Nach einem Wechsel des Debian (12 -> 13,
# Python 3.11 -> 3.13) zeigt venv/bin/python3 auf das NEUE Python, waehrend
# die Pakete unter lib/python3.11 liegen - der Aufruf gelaenge, die Pakete
# fehlten. Deshalb wird die Fassung verglichen, nicht nur der Aufruf probiert.
VENVFEHLER=""
VENV_TAUGT=""
if [ -x "$VENV/bin/python3" ]; then
    if VENVFEHLER=$("$VENV/bin/python3" -c 'import sys' 2>&1); then
        VENV_CFGVER=$(sed -n 's/^version[[:space:]]*=[[:space:]]*\([0-9][0-9]*\.[0-9][0-9]*\).*/\1/p' \
                      "$VENV/pyvenv.cfg" 2>/dev/null | head -n 1)
        if [ -n "$VENV_CFGVER" ] && [ -n "$PYVER" ] && [ "$VENV_CFGVER" != "$PYVER" ]; then
            echo "<INFO> Die vorhandene virtuelle Umgebung gehoert zu Python $VENV_CFGVER, das System hat"
            echo "<INFO> jetzt Python $PYVER - sie wird neu angelegt."
        else
            VENV_TAUGT=ja
        fi
    fi
fi
if [ -z "$VENV_TAUGT" ]; then
    if [ -n "$VENVFEHLER" ]; then
        echo "<INFO> Die vorhandene virtuelle Umgebung antwortet nicht und wird neu angelegt:"
        echo "$VENVFEHLER" | sed 's/^/<INFO>     /'
    fi
    rm -rf "$VENV"
    # Auf Debian fehlt ohne das Paket python3-venv (genauer python3.11-venv
    # bzw. python3.13-venv) das Modul ensurepip: "python3 -m venv" bricht ab
    # und hinterlaesst eine venv ohne pip. Die Meldung nennt deshalb das
    # Paket passend zur Python-Fassung. Seit 0.12.0 installiert LoxBerry es
    # ueber dpkg/apt vor diesem Skript mit.
    if ! VENVAUSGABE=$("$PY" -m venv "$VENV" 2>&1); then
        echo "$VENVAUSGABE" | tail -n 5 | sed 's/^/<INFO>     /'
        echo "<FAIL> Virtuelle Umgebung konnte nicht angelegt werden ($VENV)."
        echo "<INFO> Fehlt das Paket python3-venv? (apt install python3-venv python$PYVER-venv)"
        rm -rf "$VENV"
        exit 1
    fi
    echo "<OK> Virtuelle Umgebung angelegt: $VENV"
fi

# pip in der venv sicherstellen - nur wenn wirklich installiert wird.
sp_pip_bereit() {
    "$VENV/bin/python3" -m pip --version >/dev/null 2>&1 && return 0
    "$VENV/bin/python3" -m ensurepip --upgrade >/dev/null 2>&1 \
        && "$VENV/bin/python3" -m pip --version >/dev/null 2>&1
}
SP_PIP_AKTUELL=""
sp_pip_aktualisieren() {
    [ -n "$SP_PIP_AKTUELL" ] && return 0
    SP_PIP_AKTUELL=ja
    "$VENV/bin/python3" -m pip install --upgrade pip >/dev/null 2>&1 || \
        echo "<INFO> pip liess sich nicht aktualisieren - weiter mit der vorhandenen Fassung."
}

SOLL=$(sp_pruefsumme "$REQ")
IST=$(cat "$STEMPEL" 2>/dev/null)
WY_DA=""
"$VENV/bin/python3" -c 'import wyoming' >/dev/null 2>&1 && WY_DA=ja
if [ -n "$WY_DA" ] && [ -n "$SOLL" ] && [ "$IST" = "$SOLL" ]; then
    echo "<OK> Die Pflichtpakete der virtuellen Umgebung sind vollstaendig und unveraendert - pip wird nicht gebraucht."
else
    if [ -z "$WY_DA" ]; then
        echo "<INFO> In der virtuellen Umgebung fehlt wyoming - es wird installiert."
    else
        echo "<INFO> bin/requirements.txt hat sich geaendert - die Pakete werden angeglichen."
    fi
    if ! sp_pip_bereit; then
        echo "<FAIL> In der virtuellen Umgebung fehlt pip, und ensurepip konnte es nicht nachinstallieren."
        echo "<INFO> Fehlt das Paket python3-venv? (apt install python3-venv python$PYVER-venv)"
        exit 1
    fi
    sp_pip_aktualisieren
    echo "<INFO> Installiere die Pakete aus bin/requirements.txt (benoetigt eine Internetverbindung) ..."
    if PIPAUSGABE=$("$VENV/bin/python3" -m pip install --no-cache-dir -r "$REQ" 2>&1); then
        printf '%s\n' "$SOLL" > "$STEMPEL" 2>/dev/null
    elif [ -n "$WY_DA" ] && "$VENV/bin/python3" -c 'import wyoming' >/dev/null 2>&1; then
        echo "<WARNING> Die Pakete aus bin/requirements.txt liessen sich nicht installieren - die bisherige virtuelle Umgebung bleibt in Gebrauch:"
        echo "$PIPAUSGABE" | tail -n 5 | sed 's/^/<INFO>     /'
        echo "<INFO> Beim naechsten Update wird es erneut versucht (pip braucht dafuer eine Internetverbindung)."
    else
        echo "$PIPAUSGABE" | tail -n 8 | sed 's/^/<INFO>     /'
        echo "<FAIL> Das Paket wyoming liess sich nicht installieren."
        echo "<INFO> Ohne dieses Paket kann der Dienst nicht mit den Sprachdiensten reden."
        echo "<INFO> pip braucht dafuer eine Internetverbindung - bitte pruefen und erneut installieren."
        exit 1
    fi
fi
if ! laden_pruefen wyoming "wyoming geladen."; then
    echo "<FAIL> wyoming ist installiert, laesst sich aber nicht laden."
    echo "<INFO> Die Meldung von Python steht in den Zeilen darueber."
    exit 1
fi

# aioesphomeapi ist NUR fuer ESPHome-Mikrofone noetig. Fehlt es, laufen die
# Wyoming-Satelliten trotzdem - deshalb hier kein Abbruch. Die festgelegte
# Fassung verlangt Python 3.11 (Requires-Python auf PyPI); darunter wird sie
# gar nicht erst versucht, statt pip mit "no matching distribution"
# scheitern zu lassen.
AIO=0
if ! "$PY" -c 'import sys; sys.exit(0 if sys.version_info >= (3, 11) else 1)'; then
    echo "<INFO> aioesphomeapi (nur fuer ESPHome-Mikrofone) braucht Python 3.11 oder neuer,"
    echo "<INFO> hier laeuft Python $PYVER - es wird nicht installiert."
    AIO=1
elif [ ! -f "$REQ_ESP" ]; then
    echo "<INFO> bin/requirements-esphome.txt fehlt - aioesphomeapi wird nicht installiert."
    AIO=1
else
    SOLL_ESP=$(sp_pruefsumme "$REQ_ESP")
    IST_ESP=$(cat "$STEMPEL_ESP" 2>/dev/null)
    AIO_DA=""
    "$VENV/bin/python3" -c 'import aioesphomeapi' >/dev/null 2>&1 && AIO_DA=ja
    if [ -n "$AIO_DA" ] && [ -n "$SOLL_ESP" ] && [ "$IST_ESP" = "$SOLL_ESP" ]; then
        echo "<OK> aioesphomeapi ist vollstaendig und unveraendert - pip wird nicht gebraucht."
    elif ! sp_pip_bereit; then
        echo "<INFO> In der virtuellen Umgebung fehlt pip - aioesphomeapi wird nicht installiert."
        [ -n "$AIO_DA" ] || AIO=1
    else
        sp_pip_aktualisieren
        echo "<INFO> Installiere aioesphomeapi (nur fuer ESPHome-Mikrofone) ..."
        if PIPFEHLER=$("$VENV/bin/python3" -m pip install --no-cache-dir -r "$REQ_ESP" 2>&1); then
            printf '%s\n' "$SOLL_ESP" > "$STEMPEL_ESP" 2>/dev/null
        elif [ -n "$AIO_DA" ]; then
            echo "<INFO> aioesphomeapi liess sich nicht angleichen - die bisherige Fassung bleibt:"
            echo "$PIPFEHLER" | tail -n 5 | sed 's/^/<INFO>     /'
        else
            echo "<INFO> aioesphomeapi liess sich nicht installieren:"
            echo "$PIPFEHLER" | tail -n 5 | sed 's/^/<INFO>     /'
            AIO=1
        fi
    fi
    if [ "$AIO" = "0" ] && ! laden_pruefen aioesphomeapi "aioesphomeapi geladen."; then
        # pip meldet Erfolg auch dann, wenn kein einziges abhaengiges Paket
        # mitgekommen ist (Hausregel 12.09.2026: Wheel ohne Requires-Dist). Erst
        # der Ladeversuch beantwortet die Frage, und seine Meldung nennt, welches
        # Paket fehlt. Die Pruefsumme gilt dann nicht.
        echo "<INFO> aioesphomeapi ist installiert, laesst sich aber nicht laden."
        rm -f "$STEMPEL_ESP" 2>/dev/null
        AIO=1
    fi
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
# Mit Zeitgrenze (0.12.0): haengt der Docker-Dienst, haengt "docker info"
# ohne Ende - und mit ihm die ganze Installation, bis jemand den Vorgang
# abbricht (dieselbe Regel wie fuer jeden docker-Aufruf in sp_lib.php).
if command -v docker >/dev/null 2>&1 && timeout -k 5 20 docker info >/dev/null 2>&1; then
    echo "<OK> Docker vorhanden und ansprechbar: $(timeout -k 5 10 docker --version 2>/dev/null)"
else
    echo "<INFO> Docker ist nicht installiert oder antwortet nicht."
    echo "<INFO> Ohne Docker kann das Plugin die Sprachdienste nicht selbst betreiben."
    echo "<INFO> Wer sie anderswo betreibt, traegt in den Einstellungen nur die"
    echo "<INFO> Adressen ein. Docker nachruesten: LoxBerry-Plugin Docker NG,"
    echo "<INFO> https://github.com/timanders22/LoxBerry-Plugin-Docker-NG"
    echo "<INFO> (danach den LoxBerry einmal neu starten)."
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
# Bis 0.11.15 stand hier "chown -R loxberry:loxberry" ueber Plugin-, Daten-,
# Protokoll- und Konfigurationsordner. Das Skript laeuft aber als loxberry:
# was es anlegt, gehoert loxberry ohnehin, und was root gehoert (etwa von
# Docker angelegte Modellordner), darf loxberry nicht umschreiben - der
# Aufruf scheiterte dort still. Den Modellordner raeumt deshalb
# uninstall/uninstall als root ab, und die Einhaengeordner legt dieses
# Skript vorher selbst an (siehe oben).
chmod 600 "$PCONFIG/sprachsteuerung.json"

echo "<OK> Installation abgeschlossen."
echo "<INFO> Weiter in der Plugin-Oberflaeche, Reiter Dienste: dort stehen der"
echo "<INFO> Vorschlag fuer diese Hardware und die Knoepfe, die Container anzulegen."
exit 0
