#!/bin/bash
# Sprachsteuerung lokal - Start, Stopp und Waechter des Abrufdienstes.
#
# Die Pfade werden aus dem EIGENEN Ablageort abgeleitet, nicht ueber
# LoxBerry::System. Grund: LoxBerry::System leitet den Pluginordner aus dem
# Aufrufort ab; wird dieses Skript aus postinstall.sh oder aus dem Cron
# gestartet, kommt dort ueberall Leerstring zurueck - das Skript werkelt dann
# gegen /-Pfade und meldet trotzdem Erfolg.

# readlink -f loest Symlinks auf, BEVOR das Verzeichnis bestimmt wird.
# LoxBerry legt Daemons als Symlink unter system/daemons/plugins/ ab; von
# dort aufgerufen ergaebe dirname "$0" den Pfad .../system/daemons/plugins,
# der Pluginname waere buchstaeblich "plugins", und PID-Datei, Sollmerker
# und Logdatei landeten neben dem eigenen Ordner statt darin. Die
# Oberflaeche saehe den Dienst dann nie laufen, und der Waechter startete
# ihn im Minutentakt ein zweites Mal.
# Als loxberry laufen, nicht als root.
#
# Der minuetliche Waechter kommt aus dem Cron. Laeuft der als root - und je
# nach Ablage des Cronjobs tut er das -, dann gehoerten PID-Datei, Sollmerker
# und Protokoll danach root. Die Oberflaeche laeuft als loxberry und koennte
# den Dienst anschliessend weder anhalten noch neu starten: sie darf die
# Dateien nicht mehr schreiben. Schlimmer noch, 'dienst.sh stop' meldet dann
# Erfolg - das kill scheitert, aber das rm der PID-Datei gelingt, weil das
# Verzeichnis loxberry gehoert. Der Dienst laeuft weiter und ist nur noch
# ueber die Prozessliste zu finden.
#
# Deshalb setzt sich das Skript selbst herunter, EINMAL und bevor es
# irgendetwas anlegt. exec, damit kein zusaetzlicher Prozess stehen bleibt.
# '-s /bin/bash' ausdruecklich: ohne das nimmt su die Login-Shell aus
# /etc/passwd. Steht dort nologin oder /bin/false, endet dieses Skript hier
# still und ohne Meldung - und weil es 'exec' ist, kaeme nicht einmal ein
# Rueckgabewert zurueck. Auf einem regulaeren LoxBerry ist der Zweig ohnehin
# unerreichbar (der Cron laeuft bereits als loxberry); er greift nur, wenn
# jemand von Hand mit sudo aufruft.
#
# Woertlich uebernommen aus LoxBerry-Plugin-Dashboard-0.9.12, dort seit dem
# 16.08.2026 in Betrieb. Ueber den Bestand gezaehlt am 31.08.2026: 15 von 17
# dienst.sh hatten den Abstieg nicht, obwohl REGELN_2 ihn seit langem
# verlangt.
if [ "$(id -u)" = "0" ] && id loxberry >/dev/null 2>&1; then
    exec su -s /bin/bash loxberry -c "$(printf '%q ' "$0" "$@")"
fi

SELF=$(cd "$(dirname "$(readlink -f "$0")")" && pwd)          # <home>/bin/plugins/<ordner>

# ---------- Wurzel und Ordnername: GELESEN, nicht geraten ----------
#
# Bis 0.11.8 stand hier
#     PNAME=$(basename "$SELF")
#     LBHOMEDIR=$(cd "$SELF/../../.." && pwd)
# und weiter unten ein 'mkdir -p "$PDATA" "$PLOG"' auf oberster Ebene. Der
# eigene Ablageort war damit die EINZIGE Quelle: ein gesetztes $LBHOMEDIR
# wurde ueberschrieben, der Ordnername kam aus dem Verzeichnisnamen, und der
# geratene Pfad wurde bei JEDEM Aufruf angelegt - auch bei 'status'.
# Gemessen am 18.09.2026 in WSL (Pruefung-Sprachsteuerung-0.11.9, rot
# vorher; Bauart H1 aus Bestand-2026-09-18/klasse-H):
#   - 'dienst.sh status' aus einem Pruefarchiv unter
#     <Wurzel>/pruefung/sprachsteuerung/bin legte in der LAUFENDEN
#     Installation data/plugins/bin und log/plugins/bin an (Fall F6a);
#   - aus einem ausgepackten Archiv heraus wurde das gesetzte $LBHOMEDIR
#     uebergangen: "gestoppt", waehrend der Dienst der Installation lief
#     (F5a), und neben dem Archiv entstanden Ordner (F5b);
#   - in der Upgrade-Luecke legten 'status', ein abgewiesener Start und der
#     Waechter den abgeraeumten Datenordner wieder an (F8a, F9a, F12a).
#
# Hausform (Regeln/03 und Regeln/06, lb_wurzel_suchen): Stufe 1 ist die
# gelesene Umgebung, Stufe 2 die Aufwaertssuche nach einem Verzeichnis, das
# nachweislich eine Wurzel IST - config/plugins, data/plugins UND
# config/system/general.json (ohne den dritten Nachweis gilt ein fremder Baum
# mit den beiden Ordnern als Wurzel, Fall F16).
sp_wurzel_taugt() {          # $1 Kandidat
    [ -n "$1" ] && [ -d "$1/config/plugins" ] && [ -d "$1/data/plugins" ]
}
sp_wurzel_suchen() {
    sp_v="$SELF"
    sp_i=0
    while [ -n "$sp_v" ] && [ "$sp_v" != "/" ] && [ "$sp_i" -lt 8 ]; do
        if sp_wurzel_taugt "$sp_v" && [ -f "$sp_v/config/system/general.json" ]; then
            echo "$sp_v"
            return 0
        fi
        sp_v=$(dirname "$sp_v")
        sp_i=$((sp_i + 1))
    done
    return 1
}
# Gemerkt wird, ob die Wurzel aus der Umgebung kam: nur dann nennt der
# Aufrufer die Anlage AUSDRUECKLICH (siehe die Gegenprobe unten).
SP_UMGEBUNG=0
if sp_wurzel_taugt "${LBHOMEDIR:-}"; then
    SP_UMGEBUNG=1
else
    LBHOMEDIR=$(sp_wurzel_suchen)
fi
# Der Ordnername ebenso. $LBPPLUGINDIR steht am Geraet in einer Cron-Schale
# nie (Regeln/03, am 17.09.2026 gemessen) - dann traegt der Ablageort, und
# das ist bei einer regulaeren Installation genau richtig.
if [ -n "${LBPPLUGINDIR:-}" ]; then
    PNAME=$(basename "$LBPPLUGINDIR")
else
    PNAME=$(basename "$SELF")
fi

# Die Gegenprobe steht VOR dem ersten Anlegen. Ein Aufruf, der weder aus
# <Wurzel>/bin/plugins/<ordner> kommt noch ein eingerichtetes Plugin
# benennt, kommt aus einem ausgepackten Archiv oder einem Pruefordner: er
# faellt geschlossen aus und legt nichts an (F6).
if [ -z "$LBHOMEDIR" ] || [ ! -d "$LBHOMEDIR" ]; then
    echo "FEHLER: Es wurde kein LoxBerry-Wurzelverzeichnis gefunden."
    echo "        \$LBHOMEDIR ist nicht gesetzt, und oberhalb von $SELF traegt"
    echo "        kein Verzeichnis config/plugins, data/plugins und"
    echo "        config/system/general.json. Es wurde nichts angelegt."
    exit 1
fi
LBH_R=$(cd "$LBHOMEDIR" 2>/dev/null && pwd -P)
# Die Anlage gilt nur, wenn dieses Skript in ihrem bin-Ordner liegt oder der
# Aufrufer Wurzel UND Ordner ausdruecklich nennt ($LBHOMEDIR und
# $LBPPLUGINDIR, und <ordner> ist dort eingerichtet) - dieselbe Regel wie
# sp_paths() in sp_lib.php. Bis 0.11.9 genuegte, dass config/plugins/<name>
# existierte: mit $LBPPLUGINDIR allein fand ein Archiv unter der Wurzel diese
# per Suche, und 'stop' hielt den Dienst der Anlage an (gemessen am
# 25.09.2026 in WSL, Pruefung-Sprachsteuerung-0.11.10, Fall A5; Bauart
# Spotpreis-Tibber 0.9.19).
SP_AUSDRUECKLICH=0
if [ "$SP_UMGEBUNG" = 1 ] && [ -n "${LBPPLUGINDIR:-}" ] \
   && [ -d "$LBHOMEDIR/config/plugins/$PNAME" ]; then
    SP_AUSDRUECKLICH=1
fi
if [ "$SELF" != "$LBH_R/bin/plugins/$PNAME" ] && [ "$SP_AUSDRUECKLICH" != 1 ]; then
    echo "FEHLER: $SELF ist nicht der bin-Ordner von '$PNAME' unter $LBHOMEDIR,"
    echo "        und LBHOMEDIR und LBPPLUGINDIR nennen die Anlage nicht beide."
    echo "        Der Aufruf kommt offenbar aus einem ausgepackten Archiv oder"
    echo "        einem Pruefordner. Es wurde nichts angelegt."
    echo "        Abhilfe: LBHOMEDIR und LBPPLUGINDIR setzen oder dienst.sh aus"
    echo "        <LoxBerry-Wurzel>/bin/plugins/<ordner> aufrufen."
    exit 1
fi
# Dienstskript und venv kommen aus der gelesenen Wurzel, nicht aus dem
# Ablageort - sonst verwaltete eine Datei aus dem Archiv den Dienst des
# ARCHIVS (F5a). 'pwd -P' wie bei SELF: aus der Installation heraus ist das
# zeichengenau derselbe Pfad wie bisher "$SELF", ein von einer frueheren
# Fassung gestarteter Dienst bleibt erkannt - auch ueber einen Verweis auf
# die Wurzel (F13).
PBIN=$(cd "$LBHOMEDIR/bin/plugins/$PNAME" 2>/dev/null && pwd -P)
[ -n "$PBIN" ] || PBIN="$LBHOMEDIR/bin/plugins/$PNAME"
PDATA="$LBHOMEDIR/data/plugins/$PNAME"
PLOG="$LBHOMEDIR/log/plugins/$PNAME"
PCONFIG="$LBHOMEDIR/config/plugins/$PNAME"
PID="$PDATA/dienst.pid"
SOLL="$PDATA/soll_laufen"
# Von preupgrade.sh gelegt, von postinstall.sh entfernt. Sie liegt NEBEN dem
# Datenordner, weil purge_installation den Ordner selbst loescht. Juenger als
# eine Stunde heisst: eine Installation laeuft gerade, jetzt wird nichts
# gestartet - der Installer legt die Cron-Datei rund eine Minute vor
# postinstall.sh an, und ein in dieser Luecke gestarteter Dienst schreibt mit
# leerem Datenordner los (Regeln/06, am Geraet 08.09.2026 gemessen).
# Aelter oder unlesbar: sie gilt nicht, sonst legte eine abgebrochene
# Installation den Dienst fuer immer still.
SPERRE="$LBHOMEDIR/data/plugins/$PNAME.upgrade_laeuft"
# ZWEI Dateien, mit Absicht: sprachsteuerung.log gehoert dem Python-Dienst
# und wird von ihm rotiert. Wuerde die Schale mit ">>" dieselbe Datei
# fuehren, schriebe ihr Deskriptor nach der ersten Rotation in die
# umbenannte Datei weiter - unsichtbar fuer die Oberflaeche und auf einer
# Ramdisk. Der Starttext (auch ein Absturz-Rueckverfolgungsprotokoll)
# gehoert deshalb in eine eigene.
LOGDATEI="$PLOG/sprachsteuerung.log"
STARTLOG="$PLOG/start.log"
SKRIPT="$PBIN/sprachsteuerung_dienst.py"
PY="$PBIN/venv/bin/python3"

# Angelegt wird erst dort, wo wirklich geschrieben wird - beim Start und im
# Waechter -, nicht bei jedem Aufruf. Bis 0.11.8 stand hier ein unbedingtes
# 'mkdir -p "$PDATA" "$PLOG"' (siehe oben, F6a, F8a, F9a, F12a).
ordner_anlegen() {
    mkdir -p "$PDATA" "$PLOG" 2>/dev/null
}

laeuft() {
    [ -f "$PID" ] || return 1
    P=$(cat "$PID" 2>/dev/null)
    [ -n "$P" ] || return 1
    kill -0 "$P" 2>/dev/null || return 1
    # Nummernrecycling ausschliessen: der Prozess muss GENAU unser Skript
    # sein. Ein grep ueber cmdline traefe auch einen Editor, der die Datei
    # offen hat, und bei einer Zweitinstallation den Nachbarn (REGELN_2).
    ARGS=$(tr '\0' '\n' < "/proc/$P/cmdline" 2>/dev/null)
    [ "$(printf '%s\n' "$ARGS" | sed -n '2p')" = "$SKRIPT" ] || return 1
    printf '%s\n' "$ARGS" | sed -n '1p' | grep -qE '(^|/)python3?[0-9.]*$' || return 1
    # Genau zwei Argumente: ein drittes (--selbsttest, --satz, --trocken,
    # --mqtt-leeren) ist ein Einmallauf, kein Dienst. Gezaehlt werden die
    # Nullbytes, nicht die Zeilen - ein leeres drittes Argument zaehlt mit.
    # Bis 0.11.9 fehlte das: 'status' meldete einen Selbsttest aus dem Reiter
    # Test als laufenden Dienst, und 'stop' beendete ihn (gemessen am
    # 25.09.2026 in WSL, Pruefung-Sprachsteuerung-0.11.10, Faelle D1, D2).
    [ "$(tr -cd '\0' < "/proc/$P/cmdline" 2>/dev/null | wc -c)" = 2 ] || return 1
    return 0
}

# Gilt die Marke aus preupgrade.sh? Ein Zeitpunkt in der Zukunft zaehlt
# nicht als frisch - eine vorgestellte Uhr sperrte den Dienst sonst bis zu
# dem Zeitpunkt, den sie nennt. Ein paar Minuten Vorlauf sind aber eine
# nachgestellte Uhr und kein Grund, die Sperre zu verwerfen.
#
# Ohne lesbare Uhr faellt die Pruefung GESCHLOSSEN aus: eine liegende Marke
# gilt dann, auch eine unlesbare. Bis 0.11.8 stand hier
#     ALTER=$(( $(date +%s) - SEIT ))
# ohne Pruefung der Uhr. Lieferte 'date' nichts, war das Alter -SEIT, die
# Marke galt nicht, und der Dienst startete mitten in der Aktualisierung;
# eine Ausgabe wie 'a[$(befehl)]' fuehrte die Rechnung sogar aus (Klasse M,
# Bestand-2026-09-18/klasse-M). Gemessen am 18.09.2026 in WSL
# (Pruefung-Sprachsteuerung-0.11.9, Faelle U1-U3, U9, U10: vorher 1 Dienst
# bzw. Befehl ausgefuehrt, nachher 0). Ohne Marke aendert die fehlende Uhr
# nichts (U4). Bauart: Bewaesserung 0.9.31 marke_sperrt().
# Beide Zahlen werden VOR der Rechnung als Zahl geprueft - bash wertet in
# $(( )) den Inhalt aus (U5, U9).
sperre_gilt() {
    [ -f "$SPERRE" ] || return 1
    JETZT=$(date +%s 2>/dev/null)
    case "$JETZT" in ''|*[!0-9]*) return 0 ;; esac
    SEIT=$(cat "$SPERRE" 2>/dev/null)
    case "$SEIT" in ''|*[!0-9]*) return 1 ;; esac
    ALTER=$((JETZT - SEIT))
    [ "$ALTER" -ge -300 ] && [ "$ALTER" -lt 3600 ]
}

starten() {
    if laeuft; then
        echo "laeuft bereits (PID $(cat "$PID"))"
        return 0
    fi
    # VOR dem Sollmerker: was in der Luecke gestartet wuerde, schriebe in
    # einen Datenordner, den der Installer gleich abraeumt.
    if sperre_gilt; then
        echo "Eine Installation laeuft - der Dienst wird danach gestartet."
        return 0
    fi
    if [ ! -x "$PY" ]; then
        echo "FEHLER: virtuelle Python-Umgebung fehlt ($PY). Plugin neu installieren."
        return 1
    fi
    if [ ! -f "$SKRIPT" ]; then
        echo "FEHLER: $SKRIPT fehlt. Plugin neu installieren."
        return 1
    fi
    if [ ! -f "$PCONFIG/sprachsteuerung.json" ]; then
        echo "FEHLER: Konfiguration fehlt ($PCONFIG/sprachsteuerung.json). Erst die Oberflaeche oeffnen."
        return 1
    fi
    # Erst hier anlegen: alle Abweisungen stehen davor und schreiben nichts
    # (F9a: ein wegen der Marke abgewiesener Start legte bis 0.11.8 den
    # Datenordner in der Upgrade-Luecke wieder an).
    ordner_anlegen
    touch "$SOLL"
    # Ausgabe geht in die Logdatei. Das Python-Skript protokolliert deshalb
    # NICHT zusaetzlich nach stdout - sonst stuende jede Zeile doppelt darin.
    # Die Startdatei kappen, bevor etwas dazukommt: sie liegt auf einer
    # Ramdisk und niemand rotiert sie.
    if [ -f "$STARTLOG" ] && [ "$(wc -c < "$STARTLOG" 2>/dev/null || echo 0)" -gt 65536 ]; then
        tail -c 16384 "$STARTLOG" > "$STARTLOG.neu" 2>/dev/null \
            && mv "$STARTLOG.neu" "$STARTLOG"
    fi
    nohup "$PY" "$SKRIPT" >> "$STARTLOG" 2>&1 &
    echo $! > "$PID"
    sleep 1
    if laeuft; then
        echo "gestartet (PID $(cat "$PID"))"
        return 0
    fi
    echo "FEHLER: Start fehlgeschlagen - siehe $STARTLOG und $LOGDATEI"
    tail -n 5 "$STARTLOG" 2>/dev/null | sed "s/^/  /"
    rm -f "$PID"
    # 2 | Der Sollmerker darf einen gescheiterten Start NICHT ueberleben.
    #     Sonst versucht der minuetliche Waechter es 1440-mal am Tag und
    #     schreibt je Lauf zwei Zeilen auf die Ramdisk, waehrend die
    #     Oberflaeche "gestoppt" zeigt. REGELN_2, Dashboard-Sitzung.
    rm -f "$SOLL"
    return 1
}

anhalten() {
    rm -f "$SOLL"
    if ! laeuft; then
        rm -f "$PID"
        echo "laeuft nicht"
        return 0
    fi
    P=$(cat "$PID")
    kill "$P" 2>/dev/null
    for i in 1 2 3 4 5 6 7 8 9 10; do
        laeuft || break
        sleep 1
    done
    if laeuft; then
        kill -9 "$P" 2>/dev/null
        sleep 1
    fi
    rm -f "$PID"
    echo "angehalten"
    return 0
}

case "$1" in
    start)   starten ;;
    stop)    anhalten ;;
    restart) anhalten; sleep 1; starten ;;
    status)
        if laeuft; then
            echo "laeuft $(cat "$PID")"
            exit 0
        fi
        echo "gestoppt"
        exit 1
        ;;
    waechter)
        # Nur neu starten, wenn der Dienst laufen SOLL. Ein bewusst
        # angehaltener Dienst bleibt angehalten.
        #
        # Die Marke wird HIER noch einmal geprueft, obwohl starten() sie
        # ebenfalls prueft: sonst schriebe der Waechter waehrend jeder
        # Installation im Minutentakt seine Zeile auf die Ramdisk und das
        # Protokoll behauptete Startversuche, die keine sind.
        if sperre_gilt; then
            # Nur den Protokollordner: der Datenordner ist in der Luecke von
            # purge_installation abgeraeumt und bleibt es, bis postinstall.sh
            # ihn zurueckholt (F12a).
            mkdir -p "$PLOG" 2>/dev/null
            echo "[$(date '+%Y-%m-%d %H:%M:%S')] Waechter: eine Installation laeuft - kein Start." >> "$STARTLOG"
            exit 0
        fi
        if [ -f "$SOLL" ] && ! laeuft; then
            # log/ liegt auf einer RAM-Platte (Regeln/06); fehlt der Ordner,
            # scheitert die Umleitung nach start.log - und mit ihr der Start
            # selbst (F11).
            ordner_anlegen
            echo "[$(date '+%Y-%m-%d %H:%M:%S')] Waechter: Dienst lief nicht, wird neu gestartet." >> "$STARTLOG"
            starten >> "$STARTLOG" 2>&1
        fi
        ;;
    *)
        echo "Aufruf: $0 {start|stop|restart|status|waechter}"
        exit 2
        ;;
esac
