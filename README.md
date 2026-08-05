# LoxBerry-Plugin: Sprachsteuerung lokal

Eine **vollständig lokale Sprachsteuerung für Loxone**. Mikrofone verschiedener
Hersteller, Spracherkennung, Deutung und gesprochene Antwort — alles auf dem
LoxBerry. Kein Konto, kein Anbieter, kein Home Assistant, kein Node-RED.

> **Fassung 0.9.0 — ungeprüft an echter Hardware.** Gebaut ohne Mikrofon;
> geprüft wurde die ganze Kette gegen Attrappen, die das **Originalpaket** des
> Wyoming-Protokolls benutzen. Deshalb 0.9.0 und nicht 1.0.0.

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

    bin/sprache_dienst.py     Sprachdienst: Wyoming-Client, Pipeline,
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

## Grundlage

Wyoming-Protokoll und Container-Abbilder aus dem Rhasspy-Projekt (MIT),
Sprachmodell aus llama.cpp (MIT), ESPHome-Anbindung über `aioesphomeapi`
(MIT). Die Protokollangaben wurden gegen das veröffentlichte Paket `wyoming`
gemessen.
