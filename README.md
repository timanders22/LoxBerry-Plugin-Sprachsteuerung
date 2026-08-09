# LoxBerry-Plugin: Sprachsteuerung lokal

Eine **vollständig lokale Sprachsteuerung für Loxone**. Mikrofone verschiedener
Hersteller, Spracherkennung, Deutung und gesprochene Antwort — alles auf dem
LoxBerry. Kein Konto, kein Anbieter, kein Home Assistant, kein Node-RED.

> **Fassung 0.9.2 — ungeprüft an echter Hardware.** Gebaut ohne Mikrofon;
> geprüft wurde die ganze Kette gegen Attrappen, die das **Originalpaket** des
> Wyoming-Protokolls benutzen. Deshalb 0.9.x und nicht 1.0.0.

## Neu in 0.9.2

**Der Plugin-Ordner wird ermittelt, nicht geraten.** `sp_paths()` fiel auf den
festen Namen `sprachsteuerung` zurück, sobald `config/plugins/<ordner>` noch
fehlte — etwa im Augenblick der Installation. Hängt LoxBerry bei einer
Zweitinstallation einen Zähler an (`sprachsteuerung_01`, weil der Name schon
belegt war), zeigten deren Pfade damit auf die **erste** Installation:
gemeinsame Konfiguration, gemeinsame Warteschlange, gemeinsames Protokoll.
Maßgeblich ist jetzt `LBPPLUGINDIR`; der feste Name greift nur noch, wo der
ermittelte nachweislich kein Plugin-Ordner sein kann (aus dem ausgepackten
Archiv heraus heißt er `html`).

**Eine leere Befehlsdatei konnte in die Warteschlange geraten.**
`sp_befehl_senden()` schrieb `json_encode($befehl)` direkt weiter. Gibt
`json_encode` bei ungültigem UTF-8 `false` zurück, macht `file_put_contents`
daraus eine leere Zeichenkette, schreibt null Byte und meldet **Erfolg** — der
Rückgabewert ist `0`, nicht `false`, die Prüfung auf `=== false` greift also
nicht. In der Warteschlange läge dann eine leere Datei, die der Dienst nicht
deuten kann. Jetzt wird zuerst kodiert und der Rückgabewert angesehen, wie es
`sp_json_schreiben()` schon immer tat.

## Neu in 0.9.1: der Rückweg nach Loxone

Bis 0.9.0 sprach Piper ausschließlich in den Lautsprecher des Satelliten, der
die Frage gehört hatte. Wer im Nebenzimmer stand, hörte nichts — und in der
Visualisierung stand auch nichts. Beides holt 0.9.1 nach:

* **Als Text.** Der fertige Antwortsatz geht auf `<präfix>/antwort`, dazu
  `<präfix>/ok` mit `1` für verstanden und `0` für nicht. Ein Virtueller
  Texteingang zeigt damit an, was das Haus geantwortet hat.
* **Als Ansage.** Wahlweise über **Music Server**, **Audioserver**,
  **MS4H** oder eine **frei eingetragene Adresse** — Zone und Lautstärke
  einstellbar.

Der Schalter dafür heißt *Antwortweg* und kennt drei Stellungen: `satellit`
(wie bisher), `loxone` (nur Ansage) und `beide`. **Vorgabe ist `beide`** —
bestehende Anlagen verlieren damit nichts und bekommen den Loxone-Weg erst
dazu, sobald eine Adresse eingetragen ist.

Eine ältere Konfigurationsdatei ohne diesen Abschnitt wird nicht ersetzt,
sondern auf die Vorgaben gelegt: die neuen Felder sind danach vollständig
vorhanden, ohne dass irgendwo im Code ein Ersatzwert stehen muss.

## Der Weg eines Satzes

    Mikrofon ──Wyoming/ESPHome──> Sprachdienst (dieses Plugin)
                                        │
                                  Whisper (Container)   „schalte das Licht
                                        │                im Wohnzimmer ein"
                                  Satzmuster ──> Sprachmodell (nur als Auffanglinie)
                                        │
                                  MQTT / HTTP ──> Miniserver
                                        │
                                  Piper (Container) ──> Antwort ins Mikrofon

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

| Stufe | ab RAM | Whisper | Sprachmodell |
|---|---|---|---|
| groß | 15 GB | `medium-int8` | Qwen2.5 7B |
| mittel | 7 GB | `small-int8` | Qwen2.5 3B |
| klein | 3,5 GB | `base-int8` | Qwen2.5 1.5B |
| winzig | darunter | `tiny-int8` | **keins** |

Eine erkannte Grafikkarte hebt die Stufe um eins. Ohne 64 Bit oder mit weniger
als zwei Kernen gibt es keine Empfehlung für ein Sprachmodell.

**Es stehen nirgends Geschwindigkeitsangaben** — weder in der Oberfläche noch
hier. Sie wären ohne Ihre Hardware geraten. Der Knopf *Messen* im Reiter
*Dienste* misst stattdessen: er schickt drei Sekunden Prüfton durch Whisper,
einen Satz durch Piper, eine Frage an das Sprachmodell und nennt die Zeiten.

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

## Wie gedeutet wird

Zuerst Muster (`templates/saetze_de.json`):

    [schalte|mach] {ziel} [an|ein]
    [dimme|stelle] {ziel} auf {wert} [prozent|]

Eckige Klammern = Alternativen (eine leere Alternative heißt: darf fehlen),
geschweifte Klammern = Platzhalter: `{ziel}` wird gegen die Zielliste samt
Aliasnamen aufgelöst, `{wert}` nimmt eine Zahl, `{rest}` beliebigen Text. Der
längste passende Zielname gewinnt, damit „wohnzimmer" nicht „wohnzimmer decke"
verdrängt. Umlaute, Groß-/Kleinschreibung und Satzzeichen sind egal.

Passt kein Muster **und** ist das Sprachmodell eingeschaltet, wird es gefragt.
Es muss reines JSON antworten und darf nur Ziele nennen, die in der Liste
stehen — erfundene werden verworfen. Die Muster gehen immer vor: sie sind
schneller und liefern immer dasselbe Ergebnis.

## Loxone spricht auch zurück

Ein virtueller Ausgang kann die Anlage etwas ansagen lassen
(`aktion=sprechen`) oder ihr einen Satz unterschieben, als hätte ihn jemand
gesprochen (`aktion=satz`). Damit meldet sich das Haus von selbst — „Das
Garagentor steht seit einer Stunde offen", im richtigen Raum gesprochen,
erreicht mehr als jede Meldung auf einem Bildschirm.

## Aufbau

    bin/sprachsteuerung_dienst.py     Sprachdienst: Wyoming-Client, Pipeline,
                              ESPHome, MQTT, Warteschlange, Selbsttest
    bin/hardware.py           Hardware erkennen, empfehlen, messen
                              (läuft ohne venv)
    bin/verstehen.py          Satzmuster: Deutung und Prüfung
    bin/dienst.sh             Start, Stopp, Wächter
    cron/cron.01min           minütlicher Wächter
    templates/modelle.json    Stufen und Container — EINE Datei für
                              Dienst und Oberfläche
    templates/saetze_de.json  Satzmuster und Ziele
    webfrontend/htmlauth/     Oberfläche (sieben Reiter)
    webfrontend/html/         Endpunkt für den Miniserver + Bibliothek

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

- Zugangsdaten stehen in einer **eigenen Datei mit 0600**, nie in der
  Konfiguration, die die Oberfläche anzeigt, und nie im Loxone-Projekt.
- Keine Zugangsdaten auf der Kommandozeile — sie stünden in der Prozessliste.
- Der Endpunkt im unangemeldeten Bereich hat eine **Positivliste** erlaubter
  Aktionen; das Token wird mit `hash_equals` verglichen.
- Eingaben, die nicht zum Muster passen, werden **abgelehnt und benannt**, nie
  stillschweigend zurechtgebogen.
- Die Container-Ports hören nur auf `127.0.0.1`.

## Was ungeprüft bleibt

Ob ein bestimmtes Mikrofon Audio liefert, das Whisper versteht; ob das Weckwort
in Ihrem Raum anspricht; ob ESPHome-Mikrofone den Audioweg tragen. Das zeigt
nur echte Hardware. Alles davor — Wyoming-Aufbau, Pipeline, Satzdeutung,
Oberfläche, Endpunkt — ist gemessen, nicht behauptet.

## Fassung 0.9.2 — nachgemessen und korrigiert

Zwölf Punkte aus einer Durchsicht. Sechs trafen zu, drei teilweise, drei
nicht. Alles wurde am Code nachgestellt, bevor etwas geändert wurde.

### postinstall lief bei jedem Upgrade zweimal

Der schwerste Fund, und keiner aus der Liste. `postupgrade.sh` rief
`postinstall.sh` auf. Das sah nach Sorgfalt aus, war aber eine Verdopplung:
der LoxBerry-Installer führt `postinstall` **ohne Bedingung** aus
(`plugininstall.pl`, kein `if ($isupgrade)` davor) und `postupgrade` danach
zusätzlich. Mit demselben Ablauf nachgestellt: **zwei Durchläufe**.

Das ist nicht bloß unschön — `postinstall.sh` legt die virtuelle Umgebung an
und holt `wyoming` und `aioesphomeapi` über pip aus dem Netz. Auf einem
Raspberry Pi dauert das Minuten, und es geschah doppelt. `postupgrade.sh`
enthält jetzt nur noch das, was ein Upgrade zusätzlich braucht.

### Der Befehl wartete 20 Sekunden auf einen toten Dienst

Trifft zu. Gemessen mit gestopptem Dienst:

| | Dauer |
|---|---|
| bisher | **20,06 s** |
| jetzt, Dienst läuft nicht | **0,00 s** |

Es wird dabei auch kein Befehl mehr eingereiht — er läge sonst herum, bis der
Dienst irgendwann startet, und würde dann verspätet ausgeführt. Bei einer
Sprachausgabe ist das keine Kleinigkeit, sondern eine Stimme aus dem Nichts.

Die Wartezeit selbst liegt jetzt bei 12 Sekunden statt 20. Zur Beanstandung,
der Test-Reiter übergebe 30 bzw. 60 Sekunden: das stimmt so nicht — die
Funktion stutzte schon vorher auf 20. Die Zahlen im Aufruf kamen nie zur
Wirkung und waren damit irreführend; sie sind entfallen.

### Antwortdateien blieben liegen

Trifft zu. Nach dem Lesen fehlte das `unlink`. Der Dienst räumt sie nach
900 Sekunden weg — bis dahin sammeln sie sich bei einem gesprächigen Loxone
an, und jedes Aufräumen muss sie alle durchgehen. Jetzt wird die Datei sofort
nach dem Lesen entfernt.

### Umlaute galten als zwei Zeichen

Trifft zu. `strlen()` zählt Bytes:

| Eingabe | Zeichen | Bytes | bisher | jetzt |
|---|---|---|---|---|
| 201 × `ü` | 201 | 402 | **abgewiesen** | angenommen |
| normaler deutscher Satz, 347 Zeichen | 347 | 365 | angenommen | angenommen |

Gezählt wird jetzt mit PCRE (`/./us`), **nicht** mit `mb_strlen`. Das war
Absicht: mbstring ist eine eigene Erweiterung, dieses Plugin bringt keine
`dpkg/apt`-Liste mit und benutzt mbstring sonst nirgends. Ein
„Call to undefined function" wäre hier ein toter Endpunkt — Loxone bekäme auf
jeden Satz eine leere Antwort. PCRE kann das ohne zusätzliches Paket.

### `\S` ließ Steuerzeichen durch — `filter_var` hätte das Plugin zerlegt

Befund richtig, vorgeschlagene Abhilfe falsch. `\S` schließt nur Leerraum
aus; `\x01` kam durch. Aber `filter_var(FILTER_VALIDATE_URL)` hätte beide
Felder unbrauchbar gemacht, denn beide arbeiten mit Platzhaltern in
geschweiften Klammern. Gemessen, 7.4 und 8.1 gleich:

| Eingabe | bisher | `filter_var` | jetzt |
|---|---|---|---|
| `http://{ip}:{port}/tts?text={text}` (der Platzhaltertext des Feldes!) | angenommen | **abgewiesen** | angenommen |
| `http://192.168.1.10/dev/sps/io/{ziel}/{aktion}` | angenommen | abgewiesen | angenommen |
| `http://192.168.1.10/x<01>y` | **angenommen** | abgewiesen | abgewiesen |
| `http://a` | abgewiesen | **angenommen** | abgewiesen |
| `file:///etc/passwd` | abgewiesen | abgewiesen | abgewiesen |

Zum SSRF-Argument: die Miniserver-Adresse *soll* auf ein internes Gerät
zeigen — das ist der Zweck des Feldes, nicht sein Fehler.

### Das Protokoll wurde ganz eingelesen

Befund richtig, `tail` ist der falsche Weg — zum sechsten Mal in dieser
Plugin-Reihe nachgemessen, an einer Datei an der Rotationsgrenze:

| Verfahren | Zeit | Speicherspitze |
|---|---|---|
| `file()` + `array_reverse` | 0,3 ms | ~1,4 MB |
| `exec("tail -n 400")` | 1,9 ms | ~75 kB |
| rückwärts mit `fseek` | **0,05 ms** | ~125 kB |

Ein Prozessstart kostet mehr, als das Einlesen je gespart hat. Anzeige *und*
Rotation laufen jetzt über dieselbe `fseek`-Funktion.

### Steuerzeichen in der Satzdatei

Trifft zu, und mit Folgen: ein Steuerzeichen in einem Alias oder Thema landete
unbesehen in der `saetze.json` — und von dort in ein MQTT-Thema oder in einen
Text, der vorgelesen wird. Gereinigt wird jetzt rekursiv, **einschließlich der
Schlüssel** (sie werden zu MQTT-Themen), und zwar erst nach den Prüfungen:
die sollen sehen, was eingegeben wurde. Zeilenumbrüche werden zu Leerzeichen
statt gelöscht, damit ein mehrzeiliger Antworttext nicht zusammenklebt.

### Docker-Gruppe

Umgesetzt, mit einem neuen `postroot.sh`. Es fügt `loxberry` der Gruppe
`docker` hinzu, wenn Docker da ist und die Zuordnung fehlt. Was dabei
ausdrücklich im Installationsprotokoll steht: wer in dieser Gruppe ist, kann
Container mit beliebigen Rechten starten und damit faktisch alles auf dem
Gerät tun. Das ist die Bauweise von Docker, nicht eine Eigenheit dieses
Plugins — aber es gehört gesagt, samt dem Weg zurück
(`sudo gpasswd -d loxberry docker`) und dem Hinweis, dass das Plugin die
Sprachdienste auch auf einem anderen Rechner nutzen kann.

Ebenfalls im Protokoll: eine neue Gruppenzugehörigkeit wirkt erst für neu
gestartete Prozesse. Bis zum nächsten Neustart kann die Oberfläche weiter
melden, Docker antworte nicht — das ist dann kein Fehler.

### Mehrfache `<FAIL>`-Zeilen

Umgesetzt. Eine Fehlerlage bekommt genau ein `<FAIL>`, die erklärenden Sätze
danach sind `<INFO>` — fünf Zeilen umgestellt.

### Was nicht zutraf

**Der Sicherungsort in `preupgrade.sh`.** Beanstandet war, die Sicherung lande
„direkt im Konfigurationsordner des Plugins". Sie landet **daneben**:
`config/plugins/<ordner>.backup.<datei>` ist ein Geschwister des Ordners, kein
Kind. Genau deshalb übersteht sie eine Neuinstallation, bei der LoxBerry
`config/plugins/<ordner>/` löscht — das ist der Zweck.

Bei der Prüfung fiel allerdings etwas anderes auf: **es gab kein
Uninstall-Skript.** Die Sicherung mit der Miniserver-Adresse samt
Zugangsdaten und dem Noise-Schlüssel des Mikrofons wäre nach dem
Deinstallieren für immer auf der Karte liegen geblieben — die Datei ist nicht
umsonst mit 0600 angelegt. `uninstall/uninstall` gibt es jetzt: es hält den
Dienst an (über die Befehlszeile, argumentweise geprüft), überschreibt die
beiden Sicherungen und entfernt sie. Die Container bleiben absichtlich
unberührt: dort liegen die heruntergeladenen Modelle, mehrere Gigabyte.

**`sp_sauber` filtere Freitext.** Durch diese Funktion geht kein Freitext.
Geprüft wurde jedes Feld: Sprache, Weckwort, MQTT-Thema, Antwortweg,
TTS-Modus, TTS-Adresse, Anschluss, Lautstärke, Zonen, TTS-Sprache, die
Modellnamen sowie Rechner und Anschluss der Dienste — alles Kennungen, Hosts,
Anschlussnummern und Auswahlwerte, bei denen Anführungszeichen zu entfernen
richtig ist. Die TTS-Vorlage läuft ausdrücklich nicht hierdurch.

Ein echtes Freitextfeld gibt es aber doch, nur ein anderes als genannt: die
**Bezeichnung eines Mikrofons**. Sie wurde von einer eigenen Hilfsfunktion
ebenfalls von Anführungszeichen befreit. Eine Bezeichnung wie
`Küche "oben"` bleibt jetzt stehen — der Wert landet in JSON und im
Protokoll, nie in einer Shell.

**Nebenbefund:** Im Paket lagen übersetzte Python-Zwischendateien
(`bin/__pycache__/*.cpython-310.pyc`). Die sind draußen; `postupgrade.sh`
räumt sie auf bestehenden Installationen weg. Eine Zwischendatei, die älter
ist als der Quelltext daneben, kann im unglücklichen Fall statt des neuen
Codes geladen werden.

## Grundlage

Wyoming-Protokoll und Container-Abbilder aus dem Rhasspy-Projekt (MIT),
Sprachmodell aus llama.cpp (MIT), ESPHome-Anbindung über `aioesphomeapi`
(MIT). Die Protokollangaben wurden gegen das veröffentlichte Paket `wyoming`
gemessen.
