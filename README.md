# LoxBerry-Plugin: Sprachsteuerung lokal

Version 0.11.10

Eine **vollständig lokale Sprachsteuerung für Loxone**. Mikrofone verschiedener
Hersteller, Spracherkennung, Deutung und gesprochene Antwort — alles auf dem
LoxBerry. Kein Konto, kein Anbieter, kein Home Assistant, kein Node-RED.

> **Weiterhin 0.x — ungeprüft an echter Hardware.** Gebaut ohne Mikrofon;
> geprüft wurde die ganze Kette gegen Attrappen, die das **Originalpaket** des
> Wyoming-Protokolls benutzen. Was Raumakustik, Nachhall und Nebengeräusche
> daraus machen, entscheidet sich erst bei Ihnen.

---

## Neu in 0.11.10

- **Die Kachel „MQTT" zeigt jetzt, ob dieses Plugin veröffentlicht.** Bis 0.11.9
  stand dort als großer Wert der Autostart des MQTT-Gateways von LoxBerry, und
  „MQTT ein" las sich, als sende das Plugin — auch wenn es im Reiter MQTT
  ausgeschaltet war. Der Autostart des Gateways steht jetzt klein darunter;
  fehlt der MQTT-Abschnitt in der LoxBerry-Konfiguration, heißt er dort
  „nicht feststellbar" statt „aus".
- **Das Ergebnis des letzten Satzes, der Zustand der Verbindungen und die
  Befehle an die Ziele bleiben nicht mehr im Broker stehen.** `ok`, `grund` und
  `antwort` (das Ergebnis des letzten Satzes), `bereit` (Mikrofone mit
  Verbindung zum Dienst), `dienste_ok` (Sprachdienste, die der Dienst erreicht)
  und `ruhe` gingen seit 0.11.5 mit `retain` hinaus. Das sind Aussagen des
  Dienstes über sich selbst — bei `ok=0` auch Ausfälle der eigenen Kette, etwa
  ein Sprachmodell, das nicht antwortet — und `ruhe` wechselt mit der Ruhezeit
  allein durch die Uhr. Stirbt der Dienst, stünde der letzte Wert für immer im
  Broker. Ebenso flüchtig gehen jetzt `<thema>/aktion` und `<thema>/wert` jedes
  Ziels: ein Befehl soll nach einem Neustart von Broker oder Gateway nicht noch
  einmal ankommen (Entscheidung wie beim Stellbefehl der Einspeisebremse).
  **Wer in Loxone Config einen virtuellen Eingang an diese Themen gehängt hat,
  bekommt nach einem Neustart des Miniservers dort erst beim nächsten Wert
  wieder etwas.** In der Anlage des Hausherrn hängt kein Baustein daran
  (Projektdatei vom 11.09.2026 durchsucht).
  Den alten Wert löscht der Dienst mit einer leeren Nutzlast unmittelbar vor dem
  gültigen Wert. **Ob noch etwas zu löschen ist, fragt er den Broker** (mit den
  Zugangsdaten aus der LoxBerry-Konfiguration); erst wenn der Broker bestätigt,
  dass keiner der alten Werte mehr dasteht, legt er den Merker
  `data/plugins/<ordner>/retain_altlast` an. **Grenze:** ist der Broker nicht zu
  fragen (kein Port, Anmeldung abgewiesen, Abonnement abgelehnt), gibt es keinen
  Merker, und jede Sendung löscht unmittelbar vor dem Wert — der UDP-Eingang des
  Gateways bestätigt nichts und verwirft unter Last Datagramme.
  Zurückbehalten bleiben der letzte ausgeführte Satz samt `zeit` und die
  Zählungen aus der Konfiguration (`mikrofone`, `dienste_gesamt`, `regeln`,
  `ziele`).
- **Die Deinstallation räumt die zurückbehaltenen Themen ab und liest nach.**
  Bis 0.11.9 stand in `uninstall/uninstall`, MQTT müsse nicht aufgeräumt
  werden — seit 0.11.5 stimmte das nicht mehr. Jetzt fragt
  `sprachsteuerung_dienst.py --mqtt-leeren` den Broker, welche Themen der Linie
  noch zurückbehalten stehen, löscht genau diese und fragt nach jeder Runde
  wieder, höchstens dreimal; das Protokoll der Deinstallation sagt, was der
  Broker bestätigt hat. Ist er nicht zu fragen, gehen alle Themen dreimal
  hinaus, und das Protokoll sagt, dass nicht nachgelesen wurde. Nur das
  **aktuelle** Präfix ist bekannt; wer es früher geändert hat, löscht die alten
  Themen von Hand. Der Aufruf endet spätestens nach 65 s (`timeout -k 5 60`).
- **Ein Archiv neben der Installation fasst die Anlage nicht mehr an.** Die
  Bibliothek, `bin/dienst.sh`, der Dienst und `hardware.py` arbeiten nur dann
  auf der Anlage, wenn sie dort installiert liegen oder `LBHOMEDIR` **und**
  `LBPPLUGINDIR` gesetzt sind. Gemessen am 25.09.2026 in WSL: aus einem
  ausgepackten Archiv unter der LoxBerry-Wurzel schrieben `--satz` und
  `hardware.py --messen` Protokoll, Verlauf und Messwerte in die Anlage, und mit
  `LBPPLUGINDIR` allein hielten `dienst.sh stop` und der Knopf „Anhalten" den
  Dienst der Anlage an. Knöpfe für Dienst und Container verweigern aus einem
  Archiv heraus.
- **Ein Selbsttest gilt nicht mehr als laufender Dienst.** `dienst.sh`,
  `preupgrade.sh`, `postinstall.sh`, `uninstall/uninstall` und die Oberfläche
  erkennen den Dienst jetzt an **genau zwei** Argumenten. Bis 0.11.9 meldete
  `dienst.sh status` einen `--selbsttest` aus dem Reiter Test als laufenden
  Dienst, und `stop`, `preupgrade.sh`, `postinstall.sh` und `uninstall`
  beendeten ihn.
- **Liegengebliebene Aufträge werden beim Dienststart verworfen.** Ein Befehl
  aus der Warteschlange, der älter als 60 s ist, und ein vorgemerkter Befehl,
  der seit mehr als 60 s fällig ist, stammen aus einer Zeit, in der der Dienst
  nicht lief. Bis 0.11.9 führte der nächste Start sie aus — eine Ansage aus dem
  Nichts oder ein „Licht aus" Stunden später. Das Protokoll nennt, was
  verworfen wurde.
- **Die Zweitschrift wird nach Inhalt behandelt.** `preupgrade.sh` erneuert sie
  nur mit einer Konfiguration, die das Aktionstoken trägt (bis 0.11.9
  überschrieb eine leere Konfiguration die gute Zweitschrift), und
  `postinstall.sh` spielt nur eine Zweitschrift mit Inhalt zurück und meldet
  eine leere nicht mehr als „wiederhergestellt". Eine Konfiguration `{ }` mit
  Leerzeichen gilt jetzt als leer.
- **Die Hakenskripte suchen die Wurzel wie die übrigen Teile.** `postinstall.sh`
  und `postupgrade.sh` fielen ohne Wurzel auf „zwei Ebenen über dem eigenen
  Ablageort" zurück, `uninstall/uninstall` hielt jeden Baum mit
  `config/plugins` und `webfrontend` für eine Wurzel. Jetzt zählt nur ein
  Verzeichnis mit `config/system/general.json`, sonst wird gewarnt und nichts
  getan. `sp_t()` liest ohne Wurzel keine Sprachdatei mehr ab `/`, und
  `htmlauth/index.php` bindet nur noch die eigene Bibliothek ein.

## Neu in 0.11.9

- **Ein bloßes `dienst.sh status` legt keine Ordner mehr an, und die gesetzte
  Umgebung gilt wieder.** `bin/dienst.sh` rechnete die LoxBerry-Wurzel als
  „drei Ebenen über dem eigenen Ablageort" aus, nahm den Ordnernamen aus dem
  Verzeichnisnamen und überschrieb dabei ein gesetztes `$LBHOMEDIR`; gleich
  danach legte es Daten- und Protokollordner an — bei **jedem** Aufruf.
  **Gemessen am 18.09.2026** in WSL mit einem Platzhalter als Dienst: aus
  einem Prüfarchiv unter `<LoxBerry-Wurzel>/pruefung/sprachsteuerung/bin`
  legte `status` in der laufenden Installation `data/plugins/bin` und
  `log/plugins/bin` an; aus einem ausgepackten Archiv heraus, mit `LBHOMEDIR`
  und `LBPPLUGINDIR` auf die Installation gesetzt, meldete es „gestoppt",
  obwohl der Dienst lief; und in der Lücke eines Updates legten `status`, ein
  wegen der Marke abgewiesener Start und der Minutentakt den abgeräumten
  Datenordner wieder an.
  Jetzt kommt die Wurzel zuerst aus `$LBHOMEDIR`, sonst aus einer Suche
  aufwärts nach `config/plugins`, `data/plugins` **und**
  `config/system/general.json`; der Ordnername aus `$LBPPLUGINDIR`, sonst aus
  dem Ablageort. Liegt `dienst.sh` weder in `<LoxBerry-Wurzel>/bin/plugins/<ordner>`
  noch benennt der Aufruf ein eingerichtetes Plugin, endet es mit einer
  Fehlermeldung und legt nichts an. Dienstskript und virtuelle Umgebung kommen
  aus der gelesenen Wurzel. Angelegt wird nur noch beim Start (nach allen
  Abweisungen) und im Minutentakt — dort in der Lücke nur der
  Protokollordner.
- **Dieselbe Rechnung stand in `bin/sprachsteuerung_dienst.py` und
  `bin/hardware.py`.** Beide nahmen die Wurzel drei Ebenen über `bin/`,
  sobald dort eine lag, und fragten `$LBHOMEDIR` erst danach — aus einem
  Prüfarchiv unter der Wurzel arbeiteten sie damit auf der laufenden
  Installation, auch wenn `$LBHOMEDIR` woandershin zeigte (gemessen). Jetzt
  gilt zuerst `$LBHOMEDIR`, wenn es eine Wurzel bezeichnet.
- **Ohne lesbare Uhr startet der Dienst während eines Updates nicht mehr.**
  `dienst.sh` rechnete das Alter der Update-Marke mit der Ausgabe von `date`,
  ohne sie zu prüfen. Lieferte `date` nichts, galt die Marke nicht — der
  Dienst startete mitten in der Aktualisierung, per `start` wie per
  Minutentakt (gemessen am 18.09.2026 in WSL: je **1** Dienst; eine Ausgabe
  der Form `a[$(befehl)]` führte die Schale sogar aus). Jetzt wird die Uhr
  vor der Rechnung als Zahl geprüft, und ohne lesbare Uhr gilt eine liegende
  Marke — die Prüfung fällt geschlossen aus (nachher je **0** Dienste). Ohne
  Marke ändert eine fehlende Uhr nichts. Der Markeninhalt wurde schon vorher
  als Zahl geprüft, der Vorlauf von 300 s galt schon; beides hat jetzt eigene
  Fälle (290 s aus der Zukunft sperrt, 310 s nicht).
- **Dasselbe in `postinstall.sh`.** Dort entscheidet die Marke, ob ein Dienst
  ohne PID-Datei während der Installation angehalten wird; ohne lesbare Uhr
  galt sie nicht, und der Dienst lief weiter (gemessen: der Köder überlebte
  bei leerer Uhr, bei `x` und bei unlesbarer Marke ohne Uhr). Jetzt wird die
  Uhr auch hier zuerst geprüft, und ohne sie gilt die Marke.
- **Die Python-Dateien erkennen eine LoxBerry-Wurzel nur noch mit
  `config/system/general.json` und raten sonst nicht mehr.**
  `sprachsteuerung_dienst.py`, `hardware.py` und `verstehen.py` hielten jedes
  Verzeichnis mit `config/plugins` und `webfrontend` für eine Wurzel, und
  ohne Fund nahmen die beiden ersten zuletzt doch wieder „drei Ebenen über
  `bin/`". Gemessen am 18.09.2026: `--selbsttest` aus einem fremden Baum legte
  dort `log/plugins/sprachsteuerung/` an, `verstehen.py` las dessen
  Satzdatei, und der Selbsttest des Freigabetors schrieb in einer Kopie auf
  dem Bau-Rechner ein Protokoll neben den Prüfling. Jetzt enden alle drei
  ohne Wurzel mit einer Fehlermeldung und Rückgabe 1 und legen nichts an;
  `verstehen.py` fragt im Direktaufruf zuerst `$LBHOMEDIR`. Auf einem
  LoxBerry ändert sich nichts — dort liegt `general.json` immer.
- **Dasselbe in der Oberfläche und in `bin/sp_notify.php`.** Die Bibliothek
  `sp_lib.php` suchte die Wurzel ebenfalls ohne `general.json`, nahm jedes
  gesetzte `LBHOMEDIR`, sobald es ein Verzeichnis war (ein nicht vorhandenes
  blieb sogar stehen), und fiel zuletzt fest auf `/home/loxberry/loxberry`
  zurück. Gemessen am 18.09.2026 in WSL: aus einem ausgepackten Archiv las sie
  die Konfiguration eines fremden Baums, schrieb dort aus dessen Zweitschrift
  eine `sprachsteuerung.json` und lud dessen Sprachdatei; `sp_notify.php` band
  aus einem Archiv unter einem Prüfstand-Rest dessen LoxBerry-Bibliotheken
  ein. Jetzt gilt `LBHOMEDIR` nur, wenn darunter `config/plugins` liegt, die
  Suche verlangt `general.json`, und ohne Wurzel arbeitet die Oberfläche wie
  bisher im Archivmodus auf dem eigenen Ordner; `sp_notify.php` bricht ab.

Gegenproben, vorher wie nachher grün: aus der Installation heraus, aus `/`,
mit und ohne `LBHOMEDIR`, über einen Verweis auf die Wurzel, Neuinstallation,
Minutentakt ohne Protokollordner, die Upgrade-Marke mit ihren 300 s Vorlauf.
Nicht am Gerät gemessen.

## Neu in 0.11.8

- **Ein Update beendet keinen fremden Prozess mehr.** `preupgrade.sh` schickte
  bis 0.11.7 beide Signale — erst das weiche, zwei Sekunden später das harte —
  ungeprüft an die Zahl aus `dienst.pid`. Prozessnummern werden aber
  wiederverwendet: liegt die Datei von einem früheren Lauf herum und trägt ihre
  Zahl inzwischen ein anderes Programm, traf es genau dieses.
  **Gemessen am 18.09.2026** in WSL (Wegwerfbaum unter `/tmp`): ein Prozess
  `sleep 600`, der mit dem Plugin nichts zu tun hat, seine Nummer in
  `dienst.pid` — das Hakenskript meldete `<INFO> Laufender Dienst angehalten.`
  und der fremde Prozess war tot.
  Seit 0.11.8 wird vor **jedem** Signal argumentweise geprüft, wem die Nummer
  gehört: `argv[0]` muss ein Python sein und `argv[1]` genau der eigene
  Dienstpfad. Gehört sie einem anderen, wird nichts beendet, die PID-Datei
  aufgeräumt und das im Protokoll gesagt. Auch vor dem harten Signal wird
  erneut nachgesehen — in den Sekunden davor kann der Dienst enden und seine
  Nummer weitergehen.

- **Dieselbe Prüfung an allen Stellen der Linie.** `uninstall/uninstall`
  verglich nur `argv[1]`; ein `vim <dienstpfad>` oder ein Sicherungslauf mit
  dem Pfad als erstem Argument wäre damit als „der Dienst" durchgegangen.
  `postinstall.sh` suchte mit `pgrep -f` über die **ganze** Befehlszeile —
  gemessen hat das ein `tail -f <dienstpfad>` desselben Benutzers getroffen und
  beendet. Und die Oberfläche entschied mit einem `strpos` über den Dateinamen;
  sie meldete denselben `tail`-Prozess als laufenden Dienst, und mit ihr die
  Statuszeile, der Reiter *Test* und der Endpunkt (`OK=1`). Alle drei prüfen
  jetzt argumentweise. `bin/dienst.sh` tat das schon vorher und ist unverändert.

- **Ein Dienst ohne PID-Datei geht beim Update mit.** Er kann von Hand
  gestartet worden sein oder die Datei verloren haben; bis 0.11.7 lief er durch
  das ganze Update weiter und schrieb in den Datenordner, den der Installer
  gerade abräumt (nachgestellt: hinterher lief noch einer). `preupgrade.sh`
  sucht ihn jetzt über `/proc`, pfadgenau und nur beim Benutzer des Dienstes,
  und meldet, wenn es einen gab. `postinstall.sh` hat diesen Durchgang seit
  0.11.7 — zwischen beiden liegt rund eine Minute, in der bisher nichts prüfte.

Der Prüfstand dazu steht unter `Pruefung-Sprachsteuerung-0.11.8/`: 20 Fälle,
vorher 16 rot, nachher 0 rot, jede Korrektur einzeln zurückgebaut.

## Neu in 0.11.7

- **Verlauf, Messreihe und Ansagezeiten überstehen ein Update jetzt auch dann,
  wenn der Dienst mitten in der Installation schon läuft.** Der LoxBerry legt
  die Cron-Datei des Plugins an, bevor `postinstall.sh` läuft (am Gerät an
  einem anderen Plugin 52 s vorher gemessen). Startet der minütliche Wächter
  in dieser Lücke den Dienst und verarbeitet der einen Satz, legt er
  `verlauf.json` selbst an. Bis 0.11.6 holte `postinstall.sh` eine gesicherte
  Datei nur zurück, wenn die Zieldatei fehlte oder leer war — sie sprang dann
  nicht an, löschte die Sicherung trotzdem, und der Verlauf war fort. In WSL
  nachgestellt (17.09.2026). Seit dieser Fassung verhindert die Sperrmarke
  (nächster Punkt) den Start in der Lücke überhaupt; die bedingungslose
  Rückholung bleibt als zweite Sicherung.
- **Während einer Installation startet der Dienst nicht mehr.** `preupgrade.sh`
  legt als Erstes die Marke `data/plugins/<ordner>.upgrade_laeuft` mit der
  Unixzeit neben den Datenordner. Solange sie gilt, beenden sich
  `bin/dienst.sh start` und der minütliche Wächter ohne Start;
  `postinstall.sh` hält bei liegender Marke auch einen Dienst an, der keine
  PID-Datei mehr hat (die löscht der Installer mit dem Datenordner), und
  entfernt die Marke, bevor der Wächter wieder starten darf — auch dann, wenn
  die Installation vorher mit einem Fehler abbricht. Älter als eine Stunde
  oder unlesbar gilt sie nicht: eine abgebrochene Installation darf das Plugin
  nicht dauerhaft stilllegen. `uninstall` räumt sie weg. Nachgestellt
  (17.09.2026): ohne Marke liefen nach einem Update zwei Dienste, mit Marke
  genau einer, und ein in der Lücke gesprochener Befehl bleibt in der
  Warteschlange und wird nach der Installation verarbeitet, statt verloren zu
  gehen.
- **`preupgrade.sh` schreibt den Zeitpunkt in die Sicherung.** Ist er höchstens
  eine Stunde alt, stammt die Sicherung aus diesem Vorgang, und
  `postinstall.sh` holt jede Datei daraus zurück, ohne nach dem Inhalt der
  Zieldatei zu fragen.
- **Eine liegengebliebene Sicherung spielt nichts mehr ein.** Fehlt der
  Zeitpunkt, ist er älter als eine Stunde oder liegt er in der Zukunft, stammt
  die Sicherung nicht aus diesem Vorgang — sie kann von einer Deinstallation
  übrig sein, die nicht aufgeräumt hat, oder von einem Update vor Monaten.
  Bis 0.11.6 füllte sie damit noch, was im Datenordner fehlte oder leer war;
  das legte alten Verlauf über eine frische Installation. Jetzt wird daraus
  nichts eingespielt: sie bleibt unberührt liegen, und das
  Installationsprotokoll nennt sie einmal mit `<WARNING>` samt Ablageort.
  `uninstall` entfernt sie, damit sie gar nicht erst liegenbleibt.
- **Die Sicherung bleibt liegen, wenn eine Rückholung scheitert.** Bis 0.11.6
  wurde sie auch dann gelöscht; jetzt nennt das Protokoll die Datei mit
  `<WARNING>` und den Ort der Sicherung, von dem sie sich von Hand
  zurückkopieren lässt.
- **Die Selbstheilung der Einstellungen entscheidet nach Inhalt.** Bis 0.11.6
  sprang sie nur an, wenn `sprachsteuerung.json` fehlte, leer war oder `{}`
  enthielt. Eine abgeschnittene Datei — nicht leer, aber unlesbar — ging daran
  vorbei: die Oberfläche las die blanken Vorgaben, würfelte ein **neues
  Aktionstoken** und schrieb es samt Zweitschrift; das alte Token war weg, und
  jeder virtuelle Eingang im Miniserver bekam HTTP 403. Jetzt gilt eine
  Konfiguration als leer, wenn sie sich nicht als Objekt lesen lässt **oder
  kein Aktionstoken trägt**; geheilt wird nur aus einer Zweitschrift, die
  selbst eines hat, und der vorherige Inhalt bleibt als
  `sprachsteuerung.json.kaputt` (0600) daneben liegen. Dasselbe gilt für die
  Satzdatei: eine Datei ohne `regeln` und ohne `ziele` wird aus der
  Zweitschrift geholt, ein bewusst geleerter Regelsatz bleibt stehen.
- **Die Zweitschrift wird nie durch einen Stand ohne Inhalt ersetzt.**
  Speichern die Einstellungen oder die Sätze einen Stand, dem das
  Aktionstoken beziehungsweise `regeln`/`ziele` fehlt, während die
  Zweitschrift sie führt, wird die Zweitschrift nicht erneuert; gespeichert
  wird trotzdem, und das Protokoll sagt, warum der Rückweg stehen geblieben
  ist. Bauart wie Intercom 2.2.11 und GardenaSmartSystem 1.2.9.

Unverändert bleibt die Rückholung der Einstellungen und der Satzdatei: die
Oberfläche holt beide in der Lücke aus derselben Zweitschrift, die auch
`postinstall.sh` benutzt; nachgestellt ging dabei nichts verloren — weder beim
bloßen Öffnen der Seite noch beim Speichern. Die Seite bleibt deshalb während
einer Installation bedienbar; ein Vergleich mit Intercom, wo sie in derselben
Lage gesperrt wird, ist gemessen und fiel hier anders aus.

## Neu in 0.11.5

- **Zustände bleiben jetzt im Broker stehen (`retain`).** Bis 0.11.4 ging
  jedes Thema flüchtig hinaus. Nach einem Neustart des Miniservers oder des
  MQTT-Gateways standen die virtuellen Eingänge in Loxone deshalb leer, bis
  der nächste Herzschlag kam. Das entspricht nicht dem Hausstandard und war
  auch nicht nötig: **am Gerät gemessen (13.09.2026)** nimmt der UDP-Eingang
  des Gateways das Befehlswort `retain` an — die so gesendeten Themen lagen
  danach zurückbehalten im Broker, die mit `publish` gesendeten nicht.
- **Die Entscheidung fällt je Thema, nicht je Sendevorgang.** Der Herzschlag
  schickt Lebenszeichen *und* Zustände in einem Aufruf; wer am Aufruf
  entscheidet, macht entweder das Lebenszeichen zurückbehalten (falsch) oder
  die Zustände flüchtig (auch falsch). Die Tabelle steht in
  `templates/vorgaben.json` — **einmal** für den Dienst und die Oberfläche,
  aus demselben Grund wie die Vorgabenliste selbst.
  - *zurückbehalten:* der zuletzt verstandene Satz samt Zeitstempel
    (`satz`, `absicht`, `aktion`, `ziel`, `wert`, `einheit`, `quelle`,
    `mikrofon`, `zeit`), sein Ergebnis (`antwort`, `ok`, `grund`), die
    Themen des Ziels (`<Thema>/aktion`, `<Thema>/wert`) und der Zustand der
    Anlage (`mikrofone`, `bereit`, `dienste_ok`, `dienste_gesamt`, `regeln`,
    `ziele`, `ruhe`).
  - *flüchtig:* das Lebenszeichen (`online`, `ts`) — zurückbehalten stünde es
    für immer auf „lebt"; der Messwert `letzter_satz_alter`; und die `ansage`,
    weil der Textgenerator in Loxone sie sonst nach jedem Neuverbinden noch
    einmal vorlesen würde.
  - Ein Thema **ohne** Eintrag geht flüchtig, und ein **leerer** Wert geht
    immer flüchtig: eine leere Nutzlast löscht ein zurückbehaltenes Thema im
    Broker.
- **Die Thementabelle im Reiter MQTT hat eine dritte Spalte „zurückbehalten".**
  Damit steht beim Anlegen eines virtuellen Eingangs daneben, ob der Wert nach
  einem Neustart sofort da ist. Der Vorbehalt „ob das Gateway die Werte behält,
  ist nicht gemessen" ist damit hinfällig und durch das Messergebnis ersetzt.
- **Drei verschluckte Fehlermeldungen im Installationsskript.** Scheiterte der
  Ladeversuch von `wyoming` oder `aioesphomeapi`, stand im Installations-
  protokoll, *dass* es nicht geht — nirgends *warum*; Pythons Meldung ging nach
  `/dev/null`. Sie wird jetzt eingefangen und eingerückt ausgegeben. Dasselbe
  gilt für eine virtuelle Umgebung, die nicht mehr antwortet und deshalb neu
  angelegt wird. Zusätzlich nennt jede geglückte Prüfung, **woher** geladen
  wurde, und die Fassungen aller Pakete stehen am Ende im Protokoll.

Kein Verhalten geändert hat sich für jemanden, der MQTT abgeschaltet hat.

## Neu in 0.11.3

- **Der Reiter Test sagt jetzt, ob die MQTT-Veröffentlichung dieses Plugins
  eingeschaltet ist.** Bis 0.11.2 stand dort nur der Zustand des MQTT-Gateways
  von LoxBerry — das ist eine Aussage über den LoxBerry, nicht über dieses
  Plugin. Wer die Veröffentlichung ausgeschaltet hatte, sah trotzdem einen
  grünen Haken und konnte am Reiter nicht erkennen, dass nichts an den Broker
  geht. Die neue Zeile steht vor der Gateway-Zeile und ist **grau**, wenn
  ausgeschaltet — das ist eine Entscheidung, kein Fehler. Anlass: derselbe
  Befund an BatterieBMS 0.9.17, dort am Gerät gemessen (`Regeln/04`).

Zwei Wörter in den erzeugten Loxone-Vorlagen: „fuer" und „ueber" heißen jetzt
„für" und „über". Der Paketname `aioesphomeapi` in der Hilfe bleibt, wie er
ist — das ist kein deutsches Wort, sondern der Name eines Python-Pakets.

### Der Dienst konnte sein Protokoll verlieren, ohne dass es auffiel

`log/plugins` liegt auf einer Ramdisk (`/dev/zram0`). Wird sie geleert — beim
Neustart, durch LoxBerrys `log_maint`, oder von Hand —, ist die Datei fort. Ein
`RotatingFileHandler`, der sie beim Start **einmal** geöffnet hat, schreibt
danach bis zum nächsten Neustart in einen gelöschten Inode: keine
Fehlermeldung, keine Datei, kein Hinweis. Auch die Rotation greift dann nicht
mehr.

Diese Fassung benutzt deshalb `WachsameRotation` in `bin/sprachsteuerung_dienst.py` — einen
umlaufenden Handler, der vor jeder Zeile Gerätenummer und Inode vergleicht und
nötigenfalls neu öffnet. Die Standardbibliothek hat für den einen Fall den
`WatchedFileHandler` und für den anderen den `RotatingFileHandler`, aber
nichts, was beides kann; deshalb die eigene Klasse.

Auf dem LoxBerry geeicht, vier Prüfungen und in beide Richtungen: schreiben,
nach dem Löschen weiterschreiben, Umlauf bei Überlänge, nach dem Umlauf erneut
löschen. Mit dem alten Handler ist die Zeile nach dem Löschen verloren und
bleibt es, mit dem neuen steht sie in der wieder angelegten Datei. Auf einem
Windows-Arbeitsplatz lässt sich das nicht messen — dort kann eine offene Datei
gar nicht gelöscht werden.

Aufgefallen ist die Bauart am Heimkino-Plugin, dessen Dienst sieben Stunden
ohne Protokolldatei lief, und am laufenden Gerät belegt: der
Midea2Lox-Dienst hielt `midea2lox.log (deleted)` offen, während unter
demselben Namen längst eine neue Datei fortgeschrieben wurde — von außen sah
das Plugin gesund aus. Elf Linien tragen dieselbe Bauart; alle elf sind am
06.09.2026 nachgezogen worden.


## Neu in 0.11.2

Vier Punkte an der ESPHome-Anbindung, alle an den Quellen nachgemessen.
Drei davon haben dieselbe Form: **das Gerät sagt es selbst, und das Plugin
hat angenommen.**

* **Das Tor sperrte ein Gerät aus, für das der Weg gebaut ist.**
  `get_feature_flags()` setzt `SPEAKER` bei einem Lautsprecher und
  `ANNOUNCE` bei einem Media Player — zwei **unabhängige** Bedingungen. Ein
  Gerät mit bloßem Media Player meldet `ANNOUNCE` ohne `SPEAKER` und wurde
  abgewiesen, obwohl der Media-Player-Zweig neunzehn Zeilen tiefer für es
  gearbeitet hätte. An der zweiten Stelle stand außerdem die nackte `2`
  statt `VF.SPEAKER`.
* **Die WAV war auf 16 kHz festgenagelt.** Das ist `SAMPLE_RATE_HZ` der
  Firmware und gilt für den **API-Strom**, nicht für den Media Player. Der
  meldet selbst, was er annimmt: `MediaPlayerInfo.supported_formats` mit
  `format`, `sample_rate`, `num_channels`, `sample_bytes` und `purpose`
  (`ANNOUNCEMENT` oder `DEFAULT`) — die einzige Nachricht im ganzen API mit
  einem Ratenfeld. Das Plugin liest sie jetzt und schreibt die Datei damit;
  meldet das Gerät nur Formate, die sich hier nicht erzeugen lassen, sagt
  es das, statt eine Datei hinzulegen, die niemand lesen kann.
* **Der Media-Player-Weg meldete Erfolg, ohne einen zu haben.** „Die Adresse
  ist rausgegangen" ist keine Auskunft darüber, ob sie gespielt wurde — das
  ist der grüne Haken von 0.9.11, eine Ebene tiefer. Die Firmware sagt es:
  `VoiceAssistantAnnounceFinished`, gesendet aus dem
  `STREAMING_RESPONSE`-Zweig und aus `start_playback_timeout_()`. Der
  zugehörige Rückruf fehlte im `subscribe`-Aufruf; er ist jetzt da, und der
  Weg wartet die Dauer der Antwort plus Zuschlag darauf.
  *Ebenfalls gemessen:* `success` steht an **beiden** Sendestellen fest auf
  `true` — der Wert trägt also keine Auskunft. Das **Ankommen** trägt sie.
* **Die unaufgeforderte Ansage ist besser begründet, als der Kommentar
  sagte.** Der `TTS_END`-Zweig prüft den Zustand **nicht**: er setzt bei
  `local_output_` bedingungslos `STREAMING_RESPONSE`, gleich aus welchem
  Zustand heraus — und `local_output_` wird sowohl von `set_speaker()` als
  auch von `set_media_player()` gesetzt. Ein ruhendes Gerät sollte den Strom
  also annehmen. Gemessen ist das nicht, aber es ist kein blinder Versuch
  mehr.

## Neu in 0.11.1

0.11.0 hat den ESPHome-Weg gegen das **Klientenpaket** gemessen. Das war
die halbe Quelle. Die andere Hälfte liegt in der **Firmware**
(`esphome/components/voice_assistant/voice_assistant.cpp`, gemessen an
2026.8.2) — und dort steht, dass jedes Ereignis bestimmte Werte trägt und
die Firmware bei einem leeren Wert **aussteigt**:

```
STT_END   ohne "text"  -> return
TTS_START ohne "text"  -> return  VOR  speaker_->start()
TTS_END   ohne "url"   -> return  VOR  set_state_(STREAMING_RESPONSE)
```

`STREAMING_RESPONSE` ist der einzige Zustand, in dem der Lautsprecherpuffer
geleert wird. In 0.11.0 gingen die Ereignisse **ohne** Daten hinaus — mit
der Begründung, die Schlüsselnamen seien nicht nachlesbar. Sie sind es, nur
im anderen Haus. Die Folge: das Audio kam an, landete im Puffer, und nichts
davon erreichte je den Lautsprecher.

Drei Dinge sind daran berichtigt:

* **Die Ereignisse tragen ihre Werte** — `text` in `STT_END` und
  `TTS_START`, `url` in `TTS_END`, `conversation_id` in `INTENT_END`,
  `code`/`message` in `ERROR`.
* **Die Reihenfolge ist umgedreht:** `TTS_START` → `TTS_END` → Audio.
  Der Zustandswechsel muss **vor** die Blöcke, sonst füllt sich ein Puffer,
  den niemand leert.
* **Das Audio wird getaktet.** Der Lautsprecherpuffer der Firmware ist
  `16 * RECEIVE_SIZE` = 16384 Byte, bei 16 kHz Mono 16 Bit also 0,512
  Sekunden. Eine ganze Antwort ohne Pause hinterherzuschicken ergibt
  „Cannot receive audio, buffer is full" — man hörte den Anfang eines
  Satzes. Getaktet wird gegen eine Uhr mit festem Vorlauf; damit ist die
  Puffermenge unabhängig von der Länge der Antwort begrenzt. Gemessen:
  höchstens **9017** statt **158842** Byte.

Dazu die Unterscheidung, die das Gerät selbst meldet: `ANNOUNCE` wird genau
dann gesetzt, wenn ein **Media Player** am `voice_assistant` hängt. Dann
holt sich das Gerät die Antwort über die Adresse aus `TTS_END`, und das
Plugin streamt **nicht** zusätzlich — sonst hörte man sie zweimal. Für
diesen Fall legt der Dienst die Antwort als WAV unter
`webfrontend/html/plugins/<ordner>/ansagen/<zufall>.wav` ab; der Name ist
nicht zu erraten, und die Datei wird nach fünf Minuten abgeräumt.
Geschrieben wird sie vom **Dienst** — der unangemeldete Endpunkt schreibt
weiterhin nichts.

**Zum Wyoming-Weg:** die Satelliten*implementierung* `wyoming-satellite`
ist seit dem 27.01.2026 archiviert, das **Protokoll** und die Dienste
(Whisper, Piper, openWakeWord) sind es nicht. Der Wyoming-Weg bleibt
deshalb unangetastet; er wird nur keine neuen Satelliten mehr bekommen.

## Neu in 0.11.0

**Der ESPHome-Weg ist fertig gebaut** — und der Anlass dafür war ein
Befund, der schwerer wiegt als die Lücke, die gesucht wurde. Gemessen am
03.09.2026 gegen das Originalpaket `aioesphomeapi` 46.3.0:

* **Von 0.10.0 bis 0.10.2 konnte dieser Weg überhaupt nichts tun.**
  `subscribe_voice_assistant` reicht das Ergebnis der drei Rückrufe an
  `create_eager_task()` weiter — die verlangt eine Koroutine. Gemessen:
  `create_eager_task(0)` endet mit `TypeError: a coroutine was expected,
  got 0`. Die Rückrufe waren gewöhnliche Funktionen. Dazu nahm `hoeren()`
  ein Argument, während die Bibliothek zwei übergibt. Der Fehler fiel
  *innerhalb* des Nachrichtenrückrufs an, das Gerät bekam nicht einmal die
  Fehlerantwort. Ein eingetragenes Gerät bekam einen grünen Haken, weil sein
  Port offen war.
* **Es ging kein einziges Ereignis zurück.** Ein ESPHome-Gerät erwartet
  nach dem Weckwort `run_start`, `stt_start`, `stt_end`, `intent_start`,
  `intent_end`, `tts_start`, `tts_end`, `run_end`. Ohne sie hält es sich für
  dauerhaft mitten in einer Anfrage: der Leuchtring dreht weiter, und es
  kommt nicht in den Ruhezustand zurück. Das `run_end` steht jetzt im
  `finally` — auch ein Abbruch und ein Fehler beenden den Lauf sauber.
* **Die Antwort kam nicht aus dem Gerät.** `ansage_ausgeben()` kannte nur
  die Wyoming-Satelliten; ein Gerät mit Lautsprecher bekam nie einen Ton.
  Jetzt geht die Antwort über `tts_stream_start` → Audio →
  `tts_stream_end` auf den Lautsprecher des Geräts, in das hineingesprochen
  wurde — und eine Ansage von außen erreicht es ebenso.
* **Ob das Gerät einen Lautsprecher hat, wird gelesen, nicht angenommen:**
  aus `voice_assistant_feature_flags_compat()`. Meldet es keinen, sagt das
  Plugin das, statt ins Leere zu senden.

Dazu die zwei Befunde, die für 0.10.3 vorbereitet waren und hier mit
einfließen: der **lautlose Ausfall des Weckworts** (ein abgebrochener
Lesevorgang ließ den Wyoming-Strom entgleisen) und die **Blockade der
Ereignisschleife** während der Satzverarbeitung. Beides steht unten.

**Was weiterhin niemand gemessen hat:** ob eine echte Voice PE die
Ereignisfolge so annimmt, ob sie den zurückgeschickten Ton abspielt, und ob
16 kHz die Rate ist, die ihre Firmware erwartet. Für die Datenfelder der
Ereignisse gibt es im Paket keine einzige festgelegte Bezeichnung — sie
gehen deshalb **ohne** Daten hinaus; aus dem Wartezustand holt das Gerät die
Reihenfolge, nicht der Inhalt.

## Neu in 0.10.3 (in 0.11.0 enthalten, nie einzeln veröffentlicht)

Zwei Befunde, die 0.10.2 bewusst offen gelassen hatte, weil sie hier nicht
messbar waren. Seit dem 03.09.2026 sind sie es — das Originalpaket
`wyoming` liegt jetzt in einer eigenen Prüf-venv, und damit lassen sich
beide Fälle nachstellen, ohne dass ein Mikrofon im Raum steht.

* **Das Weckwort konnte lautlos ausfallen.** `Wortwecker.fuettern()` sah
  nach jedem Audioblock mit einer Frist von einer Millisekunde nach, ob
  schon ein Treffer dasteht. `async_read_event` liest ein Ereignis aber in
  bis zu **drei** Zügen (Kopfzeile, `data`, `payload`) — gemessen am
  Quelltext von wyoming 1.10.2. Fiel der Abbruch dazwischen, war die
  Kopfzeile aus dem Puffer verbraucht und der Rumpf stand noch darin: der
  nächste Lesevorgang begann mittendrin, `async_read_event` schluckte den
  Fehler und lieferte `None`, und daraus wurde ein stilles `return False`.
  Das Weckwort war ab diesem Augenblick tot, das Mikrofon speiste weiter
  Audio hinein, und gemeldet wurde nichts. Gegen einen Wortwecker aus
  Attrappe, der das Ereignis geteilt schickt, wird es in 0.10.2 **nie**
  erkannt und in 0.10.3 immer. Der Lesevorgang läuft jetzt durch und wird
  nur angesehen; bricht die Gegenstelle ab, wird die Verbindung neu
  aufgebaut statt wirkungslos offen gehalten.
* **Die Ereignisschleife blockierte während der Satzverarbeitung.** Die
  Kette unter `satz_verarbeiten` ist restlos synchron — mit `ast` über 28
  erreichte Funktionen nachgemessen — und enthält vier Netzabrufe mit
  zusammen bis zu rund 153 Sekunden Zeitschranke. Solange ein Satz lief,
  wurde kein anderes Mikrofon bedient, keine Warteschlange gelesen und kein
  Herzschlag geschickt; die Lesefrist der übrigen Satelliten steht auf 30 s.
  Die Verarbeitung läuft jetzt in einem Arbeitsfaden, unter einer Sperre,
  die die bisherige Reihenfolge erhält: weiterhin genau ein Satz zur Zeit.
  Gemessen mit einem mitzählenden Herzschlag — vorher 0 Schläge während
  der Verarbeitung, nachher 35 von 40.

Beide Prüfstücke liegen unter `Pruefung-Sprachsteuerung-0.10.3/` und sind
gegen 0.10.2 geeicht.

## Neu in 0.10.2

Eine Durchsicht Zeile für Zeile, mit vier kritischen Zuarbeiten. Das
Freigabetor war vorher grün (14 Prüfungen, 0 Beanstandungen) — grün heißt
nicht fehlerfrei. Die schwersten vier standen dort, wo kein Werkzeug
hinsieht:

* **Der Reiter Einstellungen speicherte gar nichts.** Er führt zwei
  Formulare, beide mit demselben Merkmal `speichern`; der Speichern-Knopf
  sitzt im zweiten. Die Felder des ersten kamen nie mit, der Handler
  behandelte „fehlt" wie „leer" und wies neun davon ab — und weil er nur
  speichert, wenn die Beanstandungsliste leer ist, blieb **alles** stehen.
  Jedes Formular trägt jetzt ein Kennzeichen, und der Handler fasst nur an,
  was wirklich mitgeschickt wurde.
* **Die Reiterumschaltung ohne Neuladen war tot.** Der Reitername ging
  maskiert in den Skriptblock; in einem `<script>`-Element löst der Browser
  keine Entitäten auf. Das war ein Syntaxfehler, und er nahm den einzigen
  Skriptblock der Seite mit. Aufgefallen ist es nie, weil der Server
  `sm-active` selbst setzt und die Seite deshalb bedienbar blieb.
* **Die Satzmuster und Ziele überlebten kein Update.** Die Zweitschrift lag
  richtig neben dem Plugin-Ordner — nur legte `postinstall.sh` die Vorlage
  an, *bevor* es zurückspielte, und danach war die Datei weder leer noch
  `{}`. Ebenso verloren gingen der Sollmerker (der Dienst blieb nach jedem
  Update stehen, ohne dass irgendetwas es meldete) und die
  heruntergeladenen Modelle, mehrere Gigabyte.
* **Der Reiter Test brach unter PHP 8 mitten in der Tabelle ab.** Ein
  `sprintf` bekam ein Argument zu wenig; unter PHP 7.4 stand dort ein Haken
  ohne Text, unter PHP 8 ein `ArgumentCountError`.

Dazu drei Prüfzeilen, die auf jeder Installation ein Kreuz zeigten, ohne
dass etwas falsch war; ein Steuerzeichen in `postinstall.sh`, das die
englischen Beispielsätze unerreichbar machte; ein Platzhalter, der den an
Loxone gesendeten Befehl überschreiben konnte; und die Baustein-Liste, die
das MQTT-Präfix als festen Text führte, obwohl es einstellbar ist.

Die Prüfstücke dazu liegen unter `Pruefung-Sprachsteuerung-0.10.2/` und
sind gegen den kaputten Stand geeicht: jedes wird rot, wenn man die
Korrektur zurückbaut.

## Neu in 0.10.1

Kein eigener Durchgang, sondern der hausweite vom 31.08.2026: `bin/dienst.sh`
steigt selbst von root ab (kein `sudo` mehr in der Anleitung), und der Text
zum MQTT-Abo unterscheidet jetzt Gateway V1 und V2, statt den V1-Satz
unbedingt hinzuschreiben.

## Neu in 0.10.0

0.10.0 ist zum größeren Teil **keine Erweiterung, sondern das Fertigbauen von
Dingen, die schon versprochen waren.** Vier davon meldeten Erfolg und taten
nichts — die unangenehmste Sorte Lücke.

### Was versprochen war und jetzt wirkt

**`aktion=sprechen` erzeugt keine Stille mehr.** Bis 0.9.11 rief die
Warteschlange Piper auf, rechnete aus der Antwort die Audiodauer aus — und warf
die Audioblöcke weg. Es ging weder etwas an einen Satelliten noch an den Music
Server. Loxone bekam `SET;OK=1;…Sprachausgabe erzeugt: 1,80 s Audio`, und im
Haus blieb es still. Die Ansage geht jetzt über den eingestellten Antwortweg
hinaus, wahlweise in eine bestimmte Zone (`&zone=4`) oder an ein bestimmtes
Mikrofon (`&mikrofon=Küche`).

**Der Wortwecker wird angesprochen.** Der Container wurde angelegt, gestartet,
im Selbsttest geprüft und mit `--preload-model` versorgt — und nie befragt. Wer
ein Mikrofon ohne eigenen Wortwecker anschloss, bekam einen grünen Haken und ein
Mikrofon, das nicht reagiert. Verlangt ein Satellit die Verarbeitung ab der
Stufe `wake`, läuft sein Audio jetzt durch openWakeWord, und erst ein Treffer
startet die Aufnahme. Antwortet der Wortwecker nicht, wird ohne Weckwort
aufgenommen **und das gesagt** — ein stummes Mikrofon wäre die schlechtere
Antwort.

**ESPHome-Mikrofone haben einen Audioweg.** Bis 0.9.11 wurde verbunden,
`device_info()` geholt und dann in einer Schleife eine Sekunde geschlafen. Kein
Rückruf, kein Audio, kein Satz. Jetzt werden die Rückrufe der
Voice-Assistant-Schnittstelle bedient. **Ob der Audioweg an einem echten Gerät
trägt, ist mangels Gerät nicht gemessen** — der Selbsttest sagt das jetzt auch
so, statt einen grünen Haken zu zeigen, weil ein Port offen ist.

**Die Anlage kann Fragen beantworten.** Die mitgelieferte Regel „wie warm ist es
im …" trug einen **leeren** Antworttext; auf die Frage blieb die Anlage stumm,
und zwar ohne Fehlermeldung. Gleichzeitig las `miniserver_rufen()` die Antwort
des Miniservers bereits ein und warf sie weg. Ein Ziel kann jetzt ein Feld
`url_lesen` tragen; was dort steht, setzt der Platzhalter `{istwert}` in den
Antworttext ein.

**`{rest}` kommt an, `neu_laden` tut etwas.** Der Platzhalter war an drei
Stellen angekündigt, wurde vom Ausdruck aufgesammelt und dann verworfen — ein
Muster wie `sag mir {rest}` griff und kam leer an. Jetzt wird **jede** benannte
Gruppe durchgereicht und steht im Antworttext zur Verfügung. Die
Warteschlangen-Aktion `neu_laden` meldete „wird beim nächsten Satz neu gelesen"
und tat nichts; niemand setzte sie ab. Jetzt lädt sie wirklich neu und nennt die
Beanstandungen. Die Datei `zustand.json` wurde bei jedem Satz geschrieben und
von niemandem gelesen — sie ist entfallen.

**Der Modus „Originaler Loxone Audioserver" ist keine Sackgasse mehr.** Er hatte
keinen Ausgabeweg; wer ihn wählte, hatte den Loxone-Antwortweg faktisch
abgeschaltet. Der Text geht jetzt über das Thema `<präfix>/ansage` hinaus, das
in Loxone Config am Textgenerator hängt.

### Die Wache vor der Stimme

**Ruhezeit.** Das Ansageverfahren dieses Plugins ist die feldgleiche Übernahme
aus dem Abfuhrkalender — übernommen wurde der Adressbau, **nicht die Wache
davor.** Ohne sie konnte jeder Loxone-Baustein um drei Uhr nachts das Haus reden
lassen. Es gibt jetzt ein Nachtfenster, das auch für den Lautsprecher des
Mikrofons gilt; ein Alarm übergeht es mit `&dringend=1`, und Loxone kann die
Ansagen über `aktion=ruhe&wert=1` jederzeit stilllegen.

**Wiederholungsbremse.** Ein Loxone-Baustein in einer Schleife erzeugte beliebig
viele Ansagen hintereinander; die einzige Grenze war die Textlänge. Jetzt gibt
es einen Mindestabstand und eine Tagesgrenze.

**Formular-Merkmal.** `htmlauth/` schützt gegen den unangemeldeten Aufruf, nicht
dagegen, dass der Browser eines angemeldeten Bedieners ein Formular abschickt,
das auf einer fremden Seite steht. Dieses Plugin hat genau die Knöpfe, an denen
das bei Docker NG zugeschlagen hat — *Token neu würfeln* und *Logdatei leeren* —
und hatte den Schutz nicht.

### Was dazugekommen ist

**Der Raum, in dem gesprochen wurde.** Ein Mikrofon kann einen *Raum* (ein
Vorgabeziel) und eine *Zone* tragen. Damit wird aus „mach das Licht im
Wohnzimmer an" schlicht **„mach an"**, gesprochen im Wohnzimmer — und die
Antwort kommt in dem Raum an, in dem gefragt wurde, statt im ganzen Haus. Wer
zugehört hat, steht außerdem in `<präfix>/mikrofon`.

**Kontext über zwei Sätze.** „Licht im Wohnzimmer an" — „heller" — „aus". Ein
genanntes Ziel gilt für eine einstellbare Zeit weiter.

**Verzögerte Befehle.** `mach das Licht in zehn Minuten aus`. Der Befehl wird
vorgemerkt und später ausgeführt.

**Rückfrage bei heiklen Zielen.** Ein Ziel mit `bestaetigen` wird nicht sofort
geschaltet — die Anlage fragt zurück, und erst ein „ja" löst aus. Gedacht für
Tore, Schlösser und alles, was man nicht versehentlich auslöst.

**Zahlwörter und Einheiten.** „auf fünfzig Prozent" trifft jetzt genauso wie
„auf 50". Übersetzt wird nur dort, wo das Muster eine Zahl erwartet — beim
ersten Anlauf wurde global übersetzt, und damit war „schalte das Licht **ein**"
zu „schalte das Licht **1**" geworden.

**Meldungen im Benachrichtigungsbereich.** Störungen gingen ausschließlich in
die eigene Logdatei — auf der Ramdisk, wo niemand hinsieht, solange das Haus noch
reagiert. Ein toter Container fiel erst auf, wenn jemand davorstand und redete.

**Herzschlag.** Über MQTT ging bis 0.9.11 nur etwas hinaus, wenn jemand sprach.
Wer der Hausempfehlung folgt und MQTT als Regelweg nimmt, verlor damit die
komplette Ausfallerkennung: ein totes Mikrofon war von einem stillen Haus nicht
zu unterscheiden.

**Trockenlauf.** *Nur deuten, nicht schalten* — zeigt, welche Regel greift,
welches Ziel getroffen wäre und welche MQTT-Themen geschrieben würden, ohne dass
das Licht angeht. Er braucht keinen laufenden Dienst.

**Sichern und zurückspielen.** Die Satzdatei ist der eigentliche Wert dieses
Plugins und war die einzige Datei ohne Rückfallebene: `sp_saetze()` griff — im
Gegensatz zu `sp_config()` — nie auf die Zweitschrift zurück, obwohl sie brav
angelegt wurde. Dazu ein Herunterladen und Einspielen als Datei; Token,
Miniserver-Adresse und Mikrofon-Schlüssel bleiben absichtlich draußen.

**Ziele aus Loxone übernehmen.** Der Miniserver kennt die Geräteliste
bereits — jeden Baustein mit Raum und Anzeigenamen. Statt sie abzutippen, holt
das Plugin die Strukturdatei einmal ab und legt eine Vorschlagsliste zum
Anhaken vor. Drei Dinge dazu, und sie stehen auch in der Oberfläche: es bleibt
ein *Vorschlag*; die Zugangsdaten werden **einmal benutzt und nicht
gespeichert**; und die Satzmuster bleiben unberührt — es kommen nur Ziele dazu.

**Eine Stimme probehören**, ohne einen Container anzulegen: das Plugin lässt
Piper einen Satz sprechen und liefert das Ergebnis als WAV-Datei aus.

**Englische Beispielsätze.** `templates/saetze_en.json` liegt bei, und
`postinstall.sh` wählt nach der Oberflächensprache des LoxBerry. Zahlwörter
versteht die Deutung jetzt in beiden Sprachen, einschließlich der englischen
Zweiwortform („seventy five"). Die Oberfläche war seit jeher zweisprachig, das
Verstehen nicht.

**Eine Maske für die Ziele.** Bis 0.9.11 war der Inhalt, den man am häufigsten
anfasst, ein einziges JSON-Textfeld. Der Rohtext bleibt als Expertenweg
darunter stehen.

**Was regelmäßig nicht verstanden wird**, steht jetzt als gezählte Liste im
Reiter Test — mit einem Knopf, der die gehörte Bezeichnung als Alias beim
gewählten Ziel nachträgt.

**Ein befristeter Mitschnitt.** Fünf Gegenstellen, und bei einem verlorenen Satz
zeigte das Protokoll nur das Ergebnis, nicht den Weg. Als Frist, nicht als
Schalter: er schaltet sich selbst ab, weil `log/plugins` auf einer Ramdisk liegt.

**Auswahllisten statt Freitext** für Whisper-Modell, Piper-Stimme, Sprachmodell
und Weckwort. Ein Vertipper wurde bisher gespeichert und schlug erst als
Docker-Fehler auf. Der Knopf *Dienste befragen* zeigt, welche Modelle,
Stimmen und Weckwörter die Container **wirklich** geladen haben.

### Loxone-Anbindung

**`?selftest=1`.** Ein Token muss sich prüfen lassen, **ohne dass etwas
passiert** — bei diesem Plugin wäre die Alternative, das Haus zum Reden zu
bringen oder das Licht zu schalten.

**`?aktion=diag`** liefert einen Klartextbefund samt nummerierten Handgriffen.

**Drei Importdateien statt einer.** Neu sind die Vorlage für den virtuellen
**Ausgang** (Ansage, Satz, Ruhe) und eine Vorlage mit **einem Texteingang je
Ziel**. Bisher standen die Ausgangsbefehle nur zum Abtippen da — die längsten
und fehleranfälligsten Zeichenketten der ganzen Oberfläche.

**Statuszeile und Vorlage haben eine Quelle.** `sp_status_felder()` kannte vier
Felder, die Zeile lieferte sechs: `REGELN` und `ZIELE` kamen in Loxone nie an.
Jetzt sind es neun Felder, und Zeile, Tabelle und Vorlage entstehen aus
derselben Liste. Dazu **realistische Grenzen** je Feld statt pauschal
±2147483647, `MinVal="-1"` überall dort, wo −1 „nicht bekannt" heißt, und die
Attribute, die Loxone Config selbst schreibt: `HintText`, `<Info templateType…>`
und `Unit`.

**Suchmuster mit Semikolon** (`\i;NAME=\i\v`), aus einer Funktion — Loxone nimmt
die erste Fundstelle, und ein Feldname, der Endstück eines anderen ist, würde
sonst vom längeren getroffen.

**Der MQTT-Reiter ist vollständig**: Gateway-Zustand, das einzutragende Abo und
die gesamte Themenliste stehen jetzt dort und nicht mehr verstreut. Und der Satz
„Ohne diesen Eintrag kommt am Miniserver nichts an" hängt an
`Mqtt.Gatewayversion` — unter Gateway V2 gibt es das Eingabefeld nicht mehr, und
der unbedingte Satz schickte jeden V2-Anwender zu einem Feld, das es nicht gibt.

### Kleinigkeiten mit Biss

* **Lebenszeichen zum Satelliten.** Die Lesefrist lag bei 3600 Sekunden. Bricht
  ein WLAN-Mikrofon weg, ohne dass TCP es meldet, zeigte die Oberfläche bis zu
  einer **Stunde** „verbunden". Jetzt 30 Sekunden plus Ping.
* **Nach einem Verbindungsabbruch galten wieder die alten Sätze.** Die Zusage
  „der Dienst liest die Datei von selbst neu" galt nur bis zum ersten Wackler.
* **Mikrofone werden ohne Neustart übernommen.**
* **`OK` und `BEREIT` sind zwei Dinge.** `OK` stand auf „irgendein Mikrofon ist
  verbunden" — eine Anlage ohne Mikrofon, die es geben darf, meldete damit
  dauerhaft Störung.
* **Das Feld „Wartezeit" ging bis 120 und wirkte bis 12.** Die Grenze steht
  jetzt an einer Stelle und im Formular.
* **Die Miniserver-Adresse wird maskiert angezeigt**, und ein versehentlich
  geleertes Feld löscht sie nicht mehr.
* **`{ziel}` fing auch ein reines Leerzeichen.** „mach an" traf damit das Muster
  mit `ziel=" "`, und die Anlage antwortete „Ich kenne kein Gerät mit der
  Bezeichnung .".
* **`hardware.py` misst jetzt alle vier Dienste** und behält die Messreihe —
  ohne sie ließ sich nach einem Modellwechsel nicht sagen, ob es schneller wurde.
* **`templates/modelle.json` beschrieb die eigene Schwelle falsch** („freier"
  statt gesamter Speicher, „unterschritten" statt erreicht).
* **Die Selbstprüfung lief bei jedem Seitenaufbau mit.** Gemessen unter PHP 8.4
  gegen die SDK-Attrappe: **6,36 s je Seite, jetzt 0,08 s.** Die Prüfungen
  laufen nur noch, wenn ihr Reiter offen ist — dort dann vollständig.

### Eine Datei für die Vorgaben

Die Vorgabewerte standen zweimal: als `VORGABEN` im Dienst und als
`sp_vorgaben()` in der Oberfläche. Die Oberfläche kannte 22 Schlüssel, der
Dienst 19. Über die Sprachgrenze hinweg gibt es keine gemeinsame Funktion —
also eine gemeinsame **Datei**, `templates/vorgaben.json`. Der Reiter Test zählt
beide Seiten gegeneinander, und beim Speichern wird die Konfiguration
**vervollständigt**: danach heißt „fehlt" nie mehr „gilt als Vorgabewert".

---

## Der Weg eines Satzes

    Mikrofon ──Wyoming/ESPHome──> Sprachdienst (dieses Plugin)
                                        │
                                  Wortwecker (nur wenn der Satellit keinen hat)
                                        │
                                  Whisper (Container)   „schalte das Licht
                                        │                im Wohnzimmer ein"
                                  Satzmuster ──> Sprachmodell (nur als Auffanglinie)
                                        │
                                  MQTT / HTTP ──> Miniserver
                                        │
                                  Piper (Container) ──> Antwort ins Mikrofon
                                                   └──> Ansage über Music Server

Das Plugin **entscheidet nichts**. Es sagt Loxone, WAS gemeint war —
`aktion=ein`, `ziel=wohnzimmer/licht`. Was daraus wird, macht der Miniserver.
So bleibt die Logik dort, wo sie hingehört, und die Sprachsteuerung ist
austauschbar.

## Was das Plugin an Containern verwaltet

| Container | Abbild | Port | nötig? |
|---|---|---|---|
| Spracherkennung | `rhasspy/wyoming-whisper` | 10300 | ja |
| Sprachausgabe | `rhasspy/wyoming-piper` | 10200 | für Antworten |
| Wortwecker | `rhasspy/wyoming-openwakeword` | 10400 | nur für Mikrofone ohne eigenen |
| Sprachmodell | `ghcr.io/ggml-org/llama.cpp:server` | 8080 | nein |

Anlegen, starten, stoppen, entfernen und Logdatei ansehen erledigt der Reiter
*Dienste*. Die Container werden **ohne** `--network=host` angelegt und binden
ihren Port ausdrücklich auf `127.0.0.1` — sie sind damit aus dem Heimnetz nicht
erreichbar.

**Docker muss vorhanden sein.** Das Plugin installiert es nicht.
`postinstall.sh` sagt es, wenn es fehlt, statt später stillschweigend zu
scheitern.

## Welches Modell auf welche Hardware

`bin/hardware.py` liest Architektur, Kerne, Arbeitsspeicher und Grafikkarte aus
und schlägt vier Stufen vor (`templates/modelle.json`):

| Stufe | ab RAM | Whisper | Piper | Sprachmodell |
|---|---|---|---|---|
| groß | 15 GB | `small-int8` | `de_DE-thorsten-medium` | Qwen2.5 7B (4,7 GB) |
| mittel | 7 GB | `base-int8` | `de_DE-thorsten-medium` | Qwen2.5 3B (2,0 GB) |
| klein | 3,5 GB | `base-int8` | `de_DE-thorsten-low` | Qwen2.5 1.5B (1,1 GB) |
| winzig | darunter | `tiny-int8` | `de_DE-thorsten-low` | **keins** |

Maßgeblich ist `templates/modelle.json`, nicht diese Tabelle — sie ist eine
Abschrift. Weichen beide ab, gilt die Datei. Gemeint ist der **gesamte**
Arbeitsspeicher, und es gilt die erste Stufe, deren Schwelle **erreicht** ist.

Eine erkannte Grafikkarte hebt die Stufe um eins; erkannt wird sie über
`nvidia-smi`, AMD und Intel zählen also nicht. Ohne 64 Bit oder mit weniger
als zwei Kernen gibt es keine Empfehlung für ein Sprachmodell.

### Die Dienste auf einem anderen Rechner betreiben

Das lohnt sich, sobald das Sprachmodell mitspielen soll: auf einem Raspberry
Pi rechnet es auf der CPU, auf einem x86-Rechner mit NVIDIA-Karte nicht.

Das Plugin bleibt dabei auf dem LoxBerry, nur die Container ziehen um. Vier
Felder im Reiter *Einstellungen* entscheiden darüber — `whisper_host`,
`piper_host`, `wake_host`, `llm_host`. Steht dort etwas anderes als
`127.0.0.1`, gilt der Dienst als ausgelagert, und das Plugin

* fasst ihn **nicht** mit Docker an (der Befehl träfe sonst den LoxBerry),
* zeigt statt des Containerzustands, ob unter der Adresse jemand antwortet,
* zeigt die passende Aufrufzeile für den anderen Rechner an,
* und misst mit *Jetzt messen* gegen diese Adresse.

Auf dem anderen Rechner brauchen Sie nur Docker. Wichtig ist die
Portbindung: die hiesigen Container binden bewusst auf `127.0.0.1` und sind
darum von außen unerreichbar — die angezeigte Zeile für den ausgelagerten
Betrieb bindet deshalb ans Netz. Sichern Sie diesen Rechner entsprechend ab;
die Wyoming-Dienste kennen keine Anmeldung.

**Wie schnell die Modelle auf Ihrer Maschine laufen, steht nirgends** — weder
in der Oberfläche noch hier. Solche Zahlen hängen an CPU, Takt, Kühlung und
Speicherbandbreite und wären ohne Ihre Hardware geraten. Der Knopf *Messen*
im Reiter *Dienste* misst stattdessen und behält die letzten zwanzig
Messungen. (Die Zahlen, die weiter oben stehen, sind etwas anderes: sie
messen die Oberfläche gegen die Prüfattrappe, nicht die Sprachdienste.)

## Mikrofone

Zwei Familien, gemischt und gleichzeitig, acht Zeilen in der Tabelle:

- **Wyoming-Satelliten** — das offene Protokoll hinter Home Assistant Voice.
  Alles, was einen Satelliten spricht: die Voice-PE-Hardware, ein Raspberry Pi
  mit `wyoming-satellite`, ein selbstgebautes Mikrofon. Adresse und Port
  genügen.
- **ESPHome-Mikrofone** — direkt über die native API (Port 6053) mit
  Verschlüsselungsschlüssel.

Der ESPHome-Weg ist der **am wenigsten erprobte** Teil. Er läuft deshalb
getrennt, damit ein Fehler dort die Wyoming-Mikrofone nicht mitreißt.

**Raum und Zone lohnen sich.** Im Feld *Raum* steht ein Ziel aus der Zielliste;
es gilt, wenn der Satz selbst keines nennt. Im Feld *Zone* steht die
Music-Server-Zone dieses Raums.

## Wie gedeutet wird

Zuerst Muster (`templates/saetze_de.json`):

    [schalte|mach] {ziel} [an|ein]
    [dimme|stelle] {ziel} auf {wert} [prozent|]
    [schalte|mach] {ziel} in {dauer} [aus|ab]

Eckige Klammern = Alternativen (eine leere Alternative heißt: darf fehlen),
geschweifte Klammern = Platzhalter: `{ziel}` wird gegen die Zielliste samt
Aliasnamen aufgelöst und **darf fehlen** (dann gilt der Raum des Mikrofons),
`{wert}` nimmt eine Zahl als Ziffern oder als Wort, `{dauer}` eine Zeitangabe,
`{rest}` beliebigen Text. Der längste passende Zielname gewinnt, damit
„wohnzimmer" nicht „wohnzimmer decke" verdrängt. Umlaute, Groß-/Kleinschreibung
und Satzzeichen sind egal.

**Die Reihenfolge entscheidet:** es gilt die erste Regel, die passt. Genauere
Muster gehören nach oben — `[schalte|mach] {ziel} [aus|ab]` passt auch auf „mach
das Wohnzimmer in 10 Minuten aus", und der Befehl wäre dann sofort ausgeführt
statt vorgemerkt. Der Selbsttest prüft das und nennt die verdeckte Regel beim
Namen.

Passt kein Muster **und** ist das Sprachmodell eingeschaltet, wird es gefragt.
Es muss reines JSON antworten und darf nur Ziele nennen, die in der Liste
stehen — erfundene werden verworfen. Die Muster gehen immer vor: sie sind
schneller und liefern immer dasselbe Ergebnis.

## Loxone spricht auch zurück

Ein virtueller Ausgang kann die Anlage etwas ansagen lassen
(`aktion=sprechen`), ihr einen Satz unterschieben, als hätte ihn jemand
gesprochen (`aktion=satz`), oder die Ansagen stilllegen (`aktion=ruhe`). Die
Vorlage im Reiter *Einbindung in Loxone* baut den Ausgang fertig.

## Aufbau

    bin/sprachsteuerung_dienst.py  Sprachdienst: Wyoming-Client, Wortwecker,
                              Pipeline, ESPHome, MQTT, Warteschlange, Timer,
                              Selbsttest
    bin/hardware.py           Hardware erkennen, empfehlen, messen
                              (läuft ohne venv)
    bin/verstehen.py          Satzmuster: Deutung und Prüfung
    bin/sp_notify.php         Meldung in den Benachrichtigungsbereich
    bin/dienst.sh             Start, Stopp, Wächter
    cron/cron.01min           minütlicher Wächter
    templates/vorgaben.json   Vorgabewerte und Grenzen — EINE Datei für
                              Dienst und Oberfläche
    templates/modelle.json    Stufen und Container — EINE Datei für
                              Dienst und Oberfläche
    templates/saetze_de.json  Satzmuster und Ziele, deutsch
    templates/saetze_en.json  dasselbe auf englisch - postinstall.sh waehlt
                              nach der Oberflaechensprache
    webfrontend/htmlauth/     Oberfläche (acht Reiter)
    webfrontend/html/         Endpunkt für den Miniserver + Bibliothek

Die beiden Sprachdateien werden **erzeugt**, nicht von Hand gepflegt:
`Werkzeuge/sp_sprache_erzeugen.py` hält jeden Text einmal, deutsch und englisch
nebeneinander, und schreibt beide Dateien. Bei 566 Schlüsseln je Sprache laufen
zwei handgepflegte Dateien sonst auseinander.

Im venv liegen zwei Pakete: **`wyoming`** (Pflicht — das offizielle Paket,
nicht nachgebaut) und `aioesphomeapi` (freiwillig, nur für ESPHome-Mikrofone).

**Zu den Python-Fassungen**, nachgesehen am 06.08.2026: `wyoming` verlangt 3.8
oder neuer, `aioesphomeapi` dagegen **3.11 oder neuer**. Das Plugin selbst
begnügt sich mit 3.9. Auf einem älteren System installiert sich deshalb der
Wyoming-Teil sauber und der ESPHome-Teil nicht — `postinstall.sh` fängt genau
diesen Fall ab und meldet ihn, statt die Installation scheitern zu lassen. In
der Praxis tritt er kaum auf: LoxBerry 3 setzt Debian 12 voraus, und das
liefert 3.11.

## Sicherheit

- Zugangsdaten stehen in einer Konfiguration mit **0600** und werden in der
  Oberfläche **maskiert** angezeigt — Länge zeigen, Inhalt nicht.
- Keine Zugangsdaten auf der Kommandozeile — sie stünden in der Prozessliste.
- Der Endpunkt im unangemeldeten Bereich hat eine **Positivliste** erlaubter
  Aktionen; das Token wird mit `hash_equals` verglichen, und `?selftest=1`
  beantwortet die Tokenfrage, ohne etwas auszulösen.
- **Der Endpunkt schreibt nichts.** Auch ein abgewiesener Aufruf legt keine
  Datei an; alles Schreibende macht der Dienst über die Warteschlange.
- Jedes Formular der Oberfläche trägt ein **Merkmal gegen fremde Absender**,
  abgeleitet aus dem Aktionstoken. Eine Prüfzeile im Reiter Test zählt nach, ob
  wirklich jedes es hat.
- Eingaben, die nicht zum Muster passen, werden **abgelehnt und benannt**, nie
  stillschweigend zurechtgebogen.
- Die Container-Ports hören nur auf `127.0.0.1`.
- Die Sicherungsdatei enthält **weder Token noch Miniserver-Adresse noch
  Mikrofon-Schlüssel**.

## Was ungeprüft bleibt

Ob ein bestimmtes Mikrofon Audio liefert, das Whisper versteht; ob das Weckwort
in Ihrem Raum anspricht; ob ESPHome-Mikrofone den Audioweg tragen. Das zeigt
nur echte Hardware. Alles davor — Wyoming-Aufbau, Pipeline, Satzdeutung,
Oberfläche, Endpunkt — ist gemessen, nicht behauptet.

Der Wortwecker-Weg (1.2) und der ESPHome-Audioweg (1.3) sind in 0.10.0 **neu
gebaut und nicht an Gerät gemessen.** Sie sind gegen das Protokoll geschrieben,
nicht gegen eine Erinnerung — aber ein Protokoll richtig zu lesen und ein Gerät
zu bedienen sind zweierlei. In 0.10.2 hat sich daran nichts geändert: hier
steht weiterhin kein Mikrofon und kein Container.

**Ebenfalls ungemessen, und zwar ausdrücklich:**

* **Das Mithören fremder Themen am Broker.** Das Plugin abonniert nichts;
  gemessen ist das nicht.
* **Die Blockade der Ereignisschleife ist in 0.10.3 behoben** und gemessen
  (siehe oben). Was bleibt: `dienste_erreichbar()` baut alle 30 Sekunden
  bis zu drei TCP-Verbindungen mit je 2 s Zeitschranke auf und tut das
  weiterhin in der Schleife. Das sind höchstens rund 6 Sekunden alle 30,
  und nur dann, wenn ein Sprachdienst nicht antwortet — in diesem Zustand
  kann das Plugin ohnehin nicht arbeiten. Bewusst nicht mit umgebaut: ein
  zweiter Faden für eine gedeckelte Wartezeit wäre ein zweites Risiko ohne
  zweiten Nutzen.

**Seit 0.11.5 nicht mehr auf dieser Liste:** `retain` am laufenden
MQTT-Gateway. Am Gerät gemessen am 13.09.2026 — der UDP-Eingang nimmt das
Befehlswort `retain` an, die so gesendeten Themen lagen danach zurückbehalten
im Broker, die mit `publish` gesendeten nicht. Gemessen wurde am Gateway
**Version 1**; für Version 2 liegt keine eigene Messung vor.

## Grundlage

Wyoming-Protokoll und Container-Abbilder aus dem Rhasspy-Projekt (MIT),
Sprachmodell aus llama.cpp (MIT), ESPHome-Anbindung über `aioesphomeapi`
(MIT). Die Protokollangaben wurden gegen das veröffentlichte Paket `wyoming`
gemessen.
