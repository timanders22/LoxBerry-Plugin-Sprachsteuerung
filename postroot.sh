#!/bin/bash
# Sprachsteuerung lokal - postroot
# command <TEMPFOLDER-KENNUNG> <NAME> <FOLDER> <VERSION> <BASEFOLDER> <WORKDIR>
#
# Laeuft als root, nachdem LoxBerry postinstall ausgefuehrt hat.
#
# ---------------------------------------------------------------------------
# WOFUER DAS GUT IST
#
# Das Plugin betreibt Whisper, Piper, den Wortwecker und das Sprachmodell in
# Containern. Dafuer muss der Benutzer loxberry mit dem Docker-Dienst reden
# duerfen. postinstall.sh konnte das nur feststellen und dem Benutzer sagen,
# er solle von Hand
#     sudo usermod -aG docker loxberry
# eingeben. Das kann hier gleich mit erledigt werden - postroot laeuft als
# root, postinstall nicht.
#
# WAS DAS BEDEUTET - und warum es hier ausdruecklich dasteht:
# Wer in der Gruppe docker ist, kann Container mit beliebigen Rechten
# starten und damit faktisch alles auf diesem Geraet tun. Das ist keine
# Eigenheit dieses Plugins, sondern die Bauweise von Docker; das
# Docker-Plugin fuer LoxBerry setzt dieselbe Gruppe. Wer das nicht will,
# betreibt die Sprachdienste auf einem anderen Rechner und traegt in den
# Einstellungen nur die Adressen ein - dann braucht das Plugin hier gar
# kein Docker.
#
# WICHTIG ZUR WIRKUNG: Eine neue Gruppenzugehoerigkeit gilt erst fuer neu
# gestartete Sitzungen. Der Webserver und damit die Plugin-Oberflaeche
# bekommen sie erst nach einem Neustart des Dienstes oder des Geraets. Das
# wird unten auch so gemeldet, statt zu behaupten, es sei schon erledigt.
# ---------------------------------------------------------------------------

if [ "$(id -u)" != "0" ]; then
    echo "<ERROR> postroot.sh muss als root laufen."
    exit 2
fi

# Die Wurzel nur fuer den Neustart-Hinweis unten (0.12.0). Ohne
# config/system/general.json darunter wird er nicht gesetzt.
ARGV5=$5
BASE="${ARGV5:-$LBHOMEDIR}"

# Den Neustart-Hinweis von LoxBerry setzen (0.12.0). Die neue Gruppe wirkt
# erst nach einem Neustart; bisher stand das nur im Installationsprotokoll,
# das danach niemand mehr liest. LoxBerry hat dafuer eine Schnittstelle:
# reboot_required() in LoxBerry::System (libs/perllib/LoxBerry/System.pm;
# ebenso in libs/phplib/loxberry_system.php). Sie haengt eine Zeile an
# log/system_tmpfs/reboot.required an, und die Oberflaeche zeigt dann den
# Hinweis "Neustart erforderlich" - derselbe Weg, den plugininstall.pl bei
# REBOOT=true in plugin.cfg geht. Hier gezielt, nur wenn die Gruppe wirklich
# neu gesetzt wurde, statt REBOOT=true bei jeder Aktualisierung.
# Erst ueber die Bibliothek; fehlt sie, dieselbe Wirkung von Hand (eine Zeile
# anhaengen, Eigentuemer loxberry, wie in reboot_required()).
sp_neustart_vormerken() {   # $1 Text
    [ -n "$BASE" ] && [ -f "$BASE/config/system/general.json" ] || return 1
    # $ARGV[0] gehoert perl, nicht der Schale - daher die einfachen Anfuehrungszeichen.
    # shellcheck disable=SC2016
    if command -v perl >/dev/null 2>&1 && [ -f "$BASE/libs/perllib/LoxBerry/System.pm" ] \
       && env "LBHOMEDIR=$BASE" "PERL5LIB=$BASE/libs/perllib" timeout -k 5 30 \
              perl -e 'use LoxBerry::System; LoxBerry::System::reboot_required($ARGV[0]);' "$1" \
              >/dev/null 2>&1; then
        return 0
    fi
    [ -d "$BASE/log/system_tmpfs" ] || return 1
    printf '%s\n' "$1" >> "$BASE/log/system_tmpfs/reboot.required" 2>/dev/null || return 1
    chown loxberry:loxberry "$BASE/log/system_tmpfs/reboot.required" 2>/dev/null
    return 0
}

if ! command -v docker >/dev/null 2>&1; then
    echo "<INFO> Docker ist nicht installiert - es gibt keine Gruppe einzurichten."
    echo "<INFO> Ohne Docker kann das Plugin die Sprachdienste nicht selbst betreiben."
    echo "<INFO> Wer sie anderswo betreibt, traegt in den Einstellungen nur die"
    echo "<INFO> Adressen ein. Docker nachruesten: LoxBerry-Plugin Docker NG,"
    echo "<INFO> https://github.com/timanders22/LoxBerry-Plugin-Docker-NG"
    echo "<INFO> (danach den LoxBerry einmal neu starten)."
    exit 0
fi

if ! getent group docker >/dev/null 2>&1; then
    echo "<INFO> Docker ist da, aber es gibt keine Gruppe 'docker'."
    echo "<INFO> Das ist ungewoehnlich - hier wird nichts angelegt."
    exit 0
fi

if id -nG loxberry 2>/dev/null | tr ' ' '\n' | grep -qx docker; then
    echo "<OK> Der Benutzer loxberry ist bereits in der Gruppe docker."
    exit 0
fi

if usermod -aG docker loxberry 2>/dev/null; then
    echo "<OK> Der Benutzer loxberry wurde der Gruppe docker hinzugefuegt."
    echo "<INFO> Das wirkt erst fuer neu gestartete Prozesse. Bis zum naechsten"
    echo "<INFO> Neustart des LoxBerry kann die Plugin-Oberflaeche noch melden,"
    echo "<INFO> Docker antworte nicht - das ist dann kein Fehler."
    echo "<INFO> Wer in der Gruppe docker ist, kann Container mit beliebigen"
    echo "<INFO> Rechten starten. Wer das nicht moechte, nimmt die Zuordnung mit"
    echo "<INFO>   sudo gpasswd -d loxberry docker"
    echo "<INFO> wieder zurueck und betreibt die Sprachdienste auf einem anderen"
    echo "<INFO> Rechner - das Plugin kann das, es braucht dann nur die Adressen."
    if sp_neustart_vormerken "Sprachsteuerung lokal: Der Benutzer loxberry ist neu in der Gruppe docker - das wirkt erst nach einem Neustart."; then
        echo "<INFO> Der Hinweis \"Neustart erforderlich\" ist in der LoxBerry-Oberflaeche gesetzt."
    else
        echo "<INFO> Bitte den LoxBerry bei Gelegenheit neu starten."
    fi
else
    echo "<INFO> Der Benutzer loxberry liess sich der Gruppe docker nicht hinzufuegen."
    echo "<INFO> Von Hand: sudo usermod -aG docker loxberry   (danach neu anmelden)"
fi

exit 0
