# LoxBerry-Plugin: Sprachsteuerung lokal

Version 0.10.0

Eine **vollständig lokale Sprachsteuerung für Loxone**. Mikrofone verschiedener
Hersteller, Spracherkennung, Deutung und gesprochene Antwort — alles auf dem
LoxBerry. Kein Konto, kein Anbieter, kein Home Assistant, kein Node-RED.

> **Weiterhin 0.x — ungeprüft an echter Hardware.** Gebaut ohne Mikrofon;
> geprüft wurde die ganze Kette gegen Attrappen, die das **Originalpaket** des
> Wyoming-Protokolls benutzen. Was Raumakustik, Nachhall und Nebengeräusche
> daraus machen, entscheidet sich erst bei Ihnen.

---

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

**Es stehen nirgends Geschwindigkeitsangaben** — weder in der Oberfläche noch
hier. Sie wären ohne Ihre Hardware geraten. Der Knopf *Messen* im Reiter
*Dienste* misst stattdessen und behält die letzten zwanzig Messungen.

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
nebeneinander, und schreibt beide Dateien. Bei 531 Schlüsseln je Sprache laufen
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
zu bedienen sind zweierlei.

## Grundlage

Wyoming-Protokoll und Container-Abbilder aus dem Rhasspy-Projekt (MIT),
Sprachmodell aus llama.cpp (MIT), ESPHome-Anbindung über `aioesphomeapi`
(MIT). Die Protokollangaben wurden gegen das veröffentlichte Paket `wyoming`
gemessen.
