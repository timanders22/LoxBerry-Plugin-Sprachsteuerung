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

# Startsperre (Verbesserungsbau Welle 3, 01.10.2026).
#
# Am Geraet liefen seit dem Systemstart am 28.09.2026 ZWEI Dienste. Beim
# Booten sprang die Uhr (fake-hwclock, dann NTP), cron holte die verpassten
# Minuten nach und startete cron.01min zweimal in derselben Sekunde. Beide
# Waechter fanden "laeuft nicht" und starteten je einen Dienst - zwischen
# Nachsehen und Starten lag nichts, was den zweiten haette aufhalten koennen.
#
# Gesperrt wird auf dieses Skript selbst (flock auf Deskriptor 8), mit
# Warten bis 15 s: der zweite Aufrufer wartet, bis der erste seinen Start
# samt Nachsehen hinter sich hat, und fragt DANACH, ob schon einer laeuft.
# readlink -f, weil LoxBerry das Skript auch ueber einen Verweis unter
# system/daemons/plugins/ aufruft - gesperrt wird immer dieselbe Datei.
# Ein zweites "exec 8<" im selben Lauf wuerde den Deskriptor neu oeffnen
# und die Sperre dabei freigeben - daher der Merker SP_SPERRE_GEHALTEN (der
# Waechter und restart sperren und rufen dann starten()).
# Der Dienst erbt den Deskriptor NICHT (8<&- beim Start): sonst hielte er
# die Sperre, solange er laeuft, und jeder spaetere Start wartete 15 s und
# gaebe dann auf (so gemessen an der Einspeisebremse 0.9.26, dort mit einer
# Sperre im PHP-Dienst, die sich an Kindprozesse vererbte).
# Ohne flock (kein util-linux) bleibt es beim Verhalten bis 0.11.12.
# Bauart: Bewaesserung 0.9.35 (startsperre_nehmen), Chromecast4lox 1.3.13.
SP_SPERRE_GEHALTEN=0
startsperre_nehmen() {
    [ "$SP_SPERRE_GEHALTEN" = "1" ] && return 0
    command -v flock >/dev/null 2>&1 || return 0
    SP_SPERRDATEI=$(readlink -f "$0" 2>/dev/null)
    [ -n "$SP_SPERRDATEI" ] && [ -r "$SP_SPERRDATEI" ] || return 0
    exec 8<"$SP_SPERRDATEI"
    if flock -w 15 8; then
        SP_SPERRE_GEHALTEN=1
        return 0
    fi
    return 1
}

# Angelegt wird erst dort, wo wirklich geschrieben wird - beim Start und im
# Waechter -, nicht bei jedem Aufruf. Bis 0.11.8 stand hier ein unbedingtes
# 'mkdir -p "$PDATA" "$PLOG"' (siehe oben, F6a, F8a, F9a, F12a).
ordner_anlegen() {
    mkdir -p "$PDATA" "$PLOG" 2>/dev/null
}

# Die Startdatei kappen, wenn sie groesser als $1 Bytes ist; es bleiben die
# letzten $2 Bytes. Sie liegt auf einer Ramdisk, und niemand rotiert sie.
#
# IN PLACE, nicht mit mv: der laufende Dienst haelt start.log ueber seine
# Standardausgabe offen (">>", also O_APPEND), ebenso die Cron-Zeile des
# Waechters ueber "2>>". Bis 0.11.15 tauschte starten() die Datei mit mv aus;
# ein Schreiber mit offenem Deskriptor schrieb danach in die geloeschte alte
# Datei weiter - unsichtbar und bis zum naechsten Neustart Platz auf der
# Ramdisk belegend. "cat > f" kuerzt dagegen dieselbe Datei; wer mit O_APPEND
# schreibt, haengt danach am neuen Ende an. Ein Satz, der genau zwischen tail
# und cat geschrieben wird, kann fehlen - das ist hinnehmbar.
startlog_kappen() {   # $1 Grenze in Bytes, $2 was stehen bleibt
    [ -f "$STARTLOG" ] || return 0
    [ "$(wc -c < "$STARTLOG" 2>/dev/null || echo 0)" -gt "$1" ] || return 0
    tail -c "$2" "$STARTLOG" > "$STARTLOG.kappen" 2>/dev/null \
        && cat "$STARTLOG.kappen" > "$STARTLOG" 2>/dev/null
    rm -f "$STARTLOG.kappen" 2>/dev/null
    return 0
}

# Der Dienst startet mit niedrigerer Rechen- und Plattenprioritaet (0.12.0):
# auf einem Raspberry Pi teilt er sich die Kerne mit dem MQTT-Gateway und der
# Miniserver-Kommunikation anderer Plugins, und die sollen nicht warten, weil
# hier gerade ein Satz verarbeitet wird. Die schwere Arbeit (Whisper, Piper,
# Sprachmodell) laeuft ohnehin in den Containern.
#
# nice und ionice ersetzen sich per exec durch das naechste Programm - der
# Vorgang behaelt die Nummer aus $!, und /proc/<pid>/cmdline zeigt danach
# wieder genau "python3 <dienstpfad>". Die Erkennung (laeuft(), preupgrade.sh,
# postinstall.sh, uninstall) bleibt damit unveraendert gueltig.
#
# ionice bewusst mit "-c2 -n7" (niedrigste Stufe der gewoehnlichen Klasse),
# NICHT "-c3" (nur im Leerlauf): mit dem Planer BFQ bekommt ein Vorgang der
# Klasse 3 keinen Plattenzugriff, solange ein anderer ununterbrochen liest
# oder schreibt - etwa "docker pull" eines Abbilds von mehreren Gigabyte. Der
# Dienst schriebe dann sein Protokoll nicht mehr, und mit ihm stuende die
# Verarbeitung der Mikrofone. Fehlt ionice (kein util-linux), nur nice.
sp_prio_vorsatz() {
    SP_VORSATZ=""
    command -v nice >/dev/null 2>&1 && SP_VORSATZ="nice -n 10"
    if command -v ionice >/dev/null 2>&1 && ionice -c2 -n7 true >/dev/null 2>&1; then
        SP_VORSATZ="$SP_VORSATZ ionice -c2 -n7"
    fi
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
    if ! startsperre_nehmen; then
        echo "Ein anderer Start dieses Plugins laeuft seit ueber 15 Sekunden - jetzt wird nichts gestartet."
        return 0
    fi
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
    # Die Startdatei kappen, bevor etwas dazukommt (startlog_kappen).
    startlog_kappen 65536 16384
    # 8<&-: der Dienst erbt die Startsperre nicht (siehe startsperre_nehmen).
    # $SP_VORSATZ ist absichtlich ungequotet: "nice -n 10 ionice -c2 -n7"
    # sind mehrere Woerter, und leer faellt er ganz weg.
    sp_prio_vorsatz
    # shellcheck disable=SC2086
    nohup $SP_VORSATZ "$PY" "$SKRIPT" >> "$STARTLOG" 2>&1 8<&- &
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
    # Erst die Startsperre, dann alles andere (0.12.0). Bis 0.11.15 hielt
    # 'stop' ohne Sperre an: hatte der Waechter den Sollmerker schon gesehen
    # und stand er gerade in starten(), entfernte stop den Merker, fand noch
    # keinen Dienst ("laeuft nicht") - und eine Sekunde spaeter lief der vom
    # Waechter gestartete. Der Knopf "Anhalten" war dann wirkungslos. Mit der
    # Sperre wartet stop, bis der Waechter fertig ist, und haelt dessen Dienst
    # an. Bekommt stop sie in 15 s nicht, haelt es trotzdem an: ein Stopp, der
    # ausfaellt, ist schlimmer als der seltene Wettlauf. restart haelt die
    # Sperre schon (SP_SPERRE_GEHALTEN), dann ist das hier ohne Wirkung.
    startsperre_nehmen \
        || echo "Ein anderer Start dieses Plugins laeuft seit ueber 15 Sekunden - es wird trotzdem angehalten."
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
    restart)
        # Anhalten und Starten unter EINER Sperre: sonst koennte ein
        # Waechter zwischen beiden einen Dienst starten und restart
        # danach einen zweiten.
        if ! startsperre_nehmen; then
            echo "Ein anderer Start dieses Plugins laeuft seit ueber 15 Sekunden - jetzt wird nichts neu gestartet."
            exit 0
        fi
        anhalten; sleep 1; starten ;;
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
        # Die ganze Frage "laeuft er? sonst starten" steht unter der
        # Startsperre. Zwei Waechter derselben Sekunde laufen damit
        # nacheinander, und der zweite findet den Dienst des ersten.
        # Bekommt ein Waechter die Sperre in 15 s nicht, tut er nichts -
        # der naechste kommt in einer Minute.
        startsperre_nehmen || exit 0
        #
        # start.log waechst auch bei laufendem Dienst: seine Standard- und
        # Fehlerausgabe gehen dorthin, dazu die Fehlerausgabe der Cron-Zeile.
        # starten() kappt nur beim Start - ein Dienst, der wochenlang laeuft
        # und dabei Warnungen ausgibt, fuellte die Ramdisk. Deshalb kappt der
        # Waechter bei jedem Lauf ab 256 KiB auf die letzten 32 KiB, in place
        # (startlog_kappen; unter der Sperre, damit nicht zwei zugleich).
        startlog_kappen 262144 32768
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
