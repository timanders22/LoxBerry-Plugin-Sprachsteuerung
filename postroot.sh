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

if ! command -v docker >/dev/null 2>&1; then
    echo "<INFO> Docker ist nicht installiert - es gibt keine Gruppe einzurichten."
    echo "<INFO> Ohne Docker kann das Plugin die Sprachdienste nicht selbst betreiben."
    echo "<INFO> Wer sie anderswo betreibt, traegt in den Einstellungen nur die"
    echo "<INFO> Adressen ein. Docker nachruesten: LoxBerry-Plugin Docker."
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
else
    echo "<INFO> Der Benutzer loxberry liess sich der Gruppe docker nicht hinzufuegen."
    echo "<INFO> Von Hand: sudo usermod -aG docker loxberry   (danach neu anmelden)"
fi

exit 0
