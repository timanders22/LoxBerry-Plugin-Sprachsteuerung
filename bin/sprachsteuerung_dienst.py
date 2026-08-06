#!REPLACELBPBINDIR/venv/bin/python3
"""Sprachsteuerung lokal - der Dienst.

WAS HIER PASSIERT
-----------------
Der Dienst ist die Vermittlung zwischen vier Beteiligten, die alle im Haus
stehen und von denen keiner ins Netz telefoniert:

    Mikrofon (Satellit) --Audio--> Wortwecker --> Spracherkennung (Whisper)
                                                       |
                                                    Text
                                                       v
                                            Satzmuster, sonst Sprachmodell
                                                       |
                                                    Absicht
                                                       v
                                      MQTT-Gateway --> Miniserver
                                                       |
                                          Sprachausgabe (Piper) --> Mikrofon

DIE REIHENFOLGE IST ABSICHT: erst Satzmuster, dann Sprachmodell. Fuer
'Licht an' braucht ein Sprachmodell auf einem kleinen Rechner Sekunden, wo ein
Mustervergleich Millisekunden braucht - und es kann sich irren, was ein
Mustervergleich nicht kann.

PROTOKOLLE
----------
Wyoming (JSONL + PCM ueber TCP) fuer Satelliten, Whisper, Piper und Wortwecker.
Benutzt wird das Originalpaket 'wyoming', nicht ein Nachbau.
ESPHome-Mikrofone (Atom Echo, Voice PE) sprechen ein anderes Protokoll; dafuer
wird die offizielle Bibliothek aioesphomeapi benutzt.

Aufrufe:
    sprachsteuerung_dienst.py              Dienst (Dauerbetrieb)
    sprachsteuerung_dienst.py --selbsttest Pruefungen ohne Mikrofon, Klartextausgabe
    sprachsteuerung_dienst.py --satz "..."  einen Satz durch die Kette schicken
"""

from __future__ import annotations

import asyncio
import json
import logging
import os
import signal
import socket
import sys
import time
import urllib.error
import urllib.parse
import urllib.request
from logging.handlers import RotatingFileHandler
from pathlib import Path

SELF = Path(__file__).resolve().parent
PNAME = SELF.name
if len(SELF.parents) >= 3:
    LBHOME = SELF.parents[2]
else:
    LBHOME = Path(os.environ.get("LBHOMEDIR") or "/opt/loxberry")

PDATA = LBHOME / "data" / "plugins" / PNAME
PLOG = LBHOME / "log" / "plugins" / PNAME
PCONFIG = LBHOME / "config" / "plugins" / PNAME

DATEI_CONFIG = PCONFIG / "sprachsteuerung.json"
DATEI_SAETZE = PCONFIG / "saetze.json"
DATEI_LOXONE = PDATA / "loxone.json"
DATEI_ZUSTAND = PDATA / "zustand.json"
DATEI_VERLAUF = PDATA / "verlauf.json"
ORDNER_BEFEHLE = PDATA / "befehle"
ORDNER_ANTWORTEN = PDATA / "antworten"
DATEI_LOG = PLOG / "sprachsteuerung.log"

sys.path.insert(0, str(SELF))
try:
    from verstehen import Verstehen, einebnen          # noqa: E402
except ImportError:                                     # pragma: no cover
    Verstehen = None

VORGABEN = {
    "satelliten": [],          # [{"art":"wyoming"|"esphome","name":..,"host":..,"port":..}]
    "whisper_host": "127.0.0.1", "whisper_port": 10300,
    "piper_host": "127.0.0.1",   "piper_port": 10200,
    "wake_host": "127.0.0.1",    "wake_port": 10400,
    "llm_host": "127.0.0.1",     "llm_port": 8080,
    "llm_ein": 0,
    "sprache": "de",
    "wakeword": "ok_nabu",
    "antwort_sprechen": 1,
    # Wohin die Antwort geht. 'satellit' ist das Verhalten bis 0.9.0: Piper
    # spricht in den Lautsprecher des Mikrofons, das die Frage gehoert hat.
    # 'loxone' sagt statt dessen ueber Music Server / Audioserver an,
    # 'beide' macht beides. Vorgabe ist 'beide', damit bestehende Anlagen
    # nichts verlieren und den Loxone-Weg nur dazubekommen, sobald eine
    # Adresse eingetragen ist.
    "antwortweg": "beide",
    "tts": {"mode": "musicserver", "ip": "", "port": 7091,
            "zones": "1", "volume": 8, "lang": "de", "template": ""},
    "mqtt_ein": 1,
    "mqtt_topic": "sprachsteuerung",
    "miniserver_url": "",
    "aktionstoken": "",
    "wartezeit": 10,
    "verlauf_zeilen": 50,
}

ANTWORTWEGE = ("satellit", "loxone", "beide")
TTS_MODI = ("musicserver", "ms4h", "audioserver", "custom")
TTS_VORLAGE_MS4H = "http://{ip}:{port}/tts?text={text}&zone={zones}&vol={vol}"

_LAUF = True
_LOG = logging.getLogger("sprachsteuerung")
_LETZTE_MELDUNG: dict[str, float] = {}


def log_einrichten() -> None:
    PLOG.mkdir(parents=True, exist_ok=True)
    _LOG.setLevel(logging.INFO)
    try:
        h: logging.Handler = RotatingFileHandler(DATEI_LOG, maxBytes=512000,
                                                 backupCount=1, encoding="utf-8")
    except OSError as err:
        h = logging.StreamHandler(sys.stderr)
        print(f"Logdatei nicht beschreibbar ({err}) - schreibe nach stderr.", file=sys.stderr)
    h.setFormatter(logging.Formatter("[%(asctime)s] %(levelname)s %(message)s", "%Y-%m-%d %H:%M:%S"))
    _LOG.handlers = [h]
    _LOG.propagate = False


def melde_gebremst(schluessel: str, text: str, sekunden: int = 900) -> None:
    jetzt = time.time()
    if jetzt - _LETZTE_MELDUNG.get(schluessel, 0) >= sekunden:
        _LETZTE_MELDUNG[schluessel] = jetzt
        _LOG.warning(text)


def json_lesen(pfad: Path) -> dict:
    try:
        d = json.loads(pfad.read_text(encoding="utf-8"))
        return d if isinstance(d, dict) else {}
    except (OSError, ValueError):
        return {}


def json_schreiben(pfad: Path, daten) -> bool:
    try:
        pfad.parent.mkdir(parents=True, exist_ok=True)
        tmp = pfad.with_suffix(pfad.suffix + ".tmp")
        tmp.write_text(json.dumps(daten, ensure_ascii=False, indent=1, default=str),
                       encoding="utf-8")
        os.replace(tmp, pfad)
        return True
    except (OSError, TypeError, ValueError) as err:
        _LOG.error("Datei %s konnte nicht geschrieben werden: %s", pfad, err)
        return False


def config() -> dict:
    c = dict(VORGABEN)
    c.update(json_lesen(DATEI_CONFIG))
    for feld, klein, gross in (("whisper_port", 1, 65535), ("piper_port", 1, 65535),
                               ("wake_port", 1, 65535), ("llm_port", 1, 65535),
                               ("wartezeit", 1, 120), ("verlauf_zeilen", 5, 500)):
        try:
            c[feld] = max(klein, min(gross, int(c.get(feld) or 0)))
        except (TypeError, ValueError):
            c[feld] = VORGABEN[feld]

    weg = str(c.get("antwortweg") or "")
    c["antwortweg"] = weg if weg in ANTWORTWEGE else "beide"

    # Der TTS-Block wird auf die Vorgaben gelegt, nicht ersetzt: eine aeltere
    # Konfiguration ohne diesen Block bekommt so vollstaendige Felder, ohne
    # dass irgendwo ein .get() mit Ersatzwert stehen muss.
    t = dict(VORGABEN["tts"])
    if isinstance(c.get("tts"), dict):
        t.update(c["tts"])
    t["mode"] = t.get("mode") if t.get("mode") in TTS_MODI else "musicserver"
    for feld, klein, gross in (("port", 1, 65535), ("volume", 1, 100)):
        try:
            t[feld] = max(klein, min(gross, int(t.get(feld) or 0)))
        except (TypeError, ValueError):
            t[feld] = VORGABEN["tts"][feld]
    t["ip"] = str(t.get("ip") or "").strip()
    t["zones"] = str(t.get("zones") or "1").strip() or "1"
    t["template"] = str(t.get("template") or "").strip()
    sprachkuerzel = "".join(z for z in str(t.get("lang") or "de").lower() if z.isalpha())
    t["lang"] = sprachkuerzel[:5] or "de"
    c["tts"] = t
    return c


def verstehen_laden() -> "Verstehen":
    if Verstehen is None:
        return None
    return Verstehen(json_lesen(DATEI_SAETZE) or {"regeln": [], "ziele": {}})


# ---------------------------------------------------------------------------
# MQTT ueber das LoxBerry-Gateway
#
# Das Gateway ist seit LoxBerry 3 Bestandteil des Systems, kein Plugin.
# Mqtt.Brokerhost ist ab Werk gesetzt - massgeblich ist Gatewayautostart.
# Gesendet wird ueber den UDP-Eingang: so braucht das Plugin keine
# Broker-Zugangsdaten.
# ---------------------------------------------------------------------------
def mqtt_zustand() -> dict:
    gen = json_lesen(LBHOME / "config" / "system" / "general.json")
    m = gen.get("Mqtt") or gen.get("mqtt") or {}
    autostart = m.get("Gatewayautostart", m.get("gatewayautostart"))
    try:
        udp = int(m.get("Udpinport", m.get("udpinport")))
    except (TypeError, ValueError):
        udp = 0
    return {"gefunden": bool(m),
            "autostart": 1 if str(autostart) in ("1", "true", "True") else 0,
            "udpport": udp,
            "broker": str(m.get("Brokerhost", m.get("brokerhost", ""))),
            "brokerport": str(m.get("Brokerport", m.get("brokerport", "")))}


def mqtt_senden(paare: dict, praefix: str) -> None:
    z = mqtt_zustand()
    if not z["udpport"]:
        melde_gebremst("mqtt_kein_port", "MQTT: kein UDP-Eingangsport in der general.json.")
        return
    if not z["autostart"]:
        melde_gebremst("mqtt_aus", "MQTT: das Gateway ist nicht auf Autostart gestellt "
                                   "(System, MQTT Gateway). Es wird gesendet, aber "
                                   "vermutlich hoert niemand zu.")
    try:
        s = socket.socket(socket.AF_INET, socket.SOCK_DGRAM)
    except OSError as err:
        melde_gebremst("mqtt_socket", f"MQTT: Socket nicht moeglich ({err}).")
        return
    try:
        for k, v in paare.items():
            if v is None or v == "":
                continue
            nachricht = f"publish {praefix}/{k} {v}".encode("utf-8")
            s.sendto(nachricht, ("127.0.0.1", z["udpport"]))
    except OSError as err:
        melde_gebremst("mqtt_senden", f"MQTT: Senden fehlgeschlagen ({err}).")
    finally:
        s.close()


def fehlertext(err: Exception) -> str:
    name = type(err).__name__
    text = str(err) or name
    klein = text.lower()
    errno = getattr(err, "errno", None)
    if isinstance(err, asyncio.TimeoutError) or "timed out" in klein:
        return "Zeitueberlauf: der Dienst hat nicht geantwortet."
    if errno == 111 or "connection refused" in klein:
        return ("Verbindung abgewiesen (ECONNREFUSED): der Rechner ist erreichbar, aber "
                "auf diesem Port lauscht nichts. Laeuft der Container?")
    if errno == 113 or "no route to host" in klein:
        return "Kein Weg zum Ziel (EHOSTUNREACH): Netz und Adresse pruefen."
    if "network is unreachable" in klein:
        return "Netz nicht erreichbar (ENETUNREACH)."
    if "name or service not known" in klein or "getaddrinfo" in klein:
        return "Namensaufloesung fehlgeschlagen: statt des Namens die IP-Adresse eintragen."
    return f"{name}: {text}"


# ---------------------------------------------------------------------------
# Wyoming
#
# Benutzt wird das ORIGINALPAKET wyoming, kein Nachbau. Der einzige von Hand
# gebaute Aufbau steckt in hardware.py - der muss auch ohne die virtuelle
# Umgebung laufen und ist gegen dieses Paket gemessen.
# ---------------------------------------------------------------------------
async def wy_verbinden(host: str, port: int, zeit: float = 10.0):
    leser, schreiber = await asyncio.wait_for(
        asyncio.open_connection(host, port), timeout=zeit)
    return leser, schreiber


async def wy_senden(schreiber, ereignis) -> None:
    from wyoming.event import async_write_event
    await async_write_event(ereignis, schreiber)


async def wy_lesen(leser, zeit: float = 60.0):
    from wyoming.event import async_read_event
    return await asyncio.wait_for(async_read_event(leser), timeout=zeit)


async def spracherkennung(cfg: dict, rahmen: list, rate: int = 16000) -> dict:
    """Audio -> Text ueber Whisper. rahmen ist eine Liste von PCM-Bloecken."""
    from wyoming.asr import Transcribe, Transcript
    from wyoming.audio import AudioChunk, AudioStart, AudioStop
    t0 = time.monotonic()
    try:
        leser, schreiber = await wy_verbinden(cfg["whisper_host"], int(cfg["whisper_port"]))
    except (OSError, asyncio.TimeoutError) as err:
        return {"ok": 0, "fehler": "Spracherkennung: " + fehlertext(err)}
    try:
        await wy_senden(schreiber, Transcribe(language=cfg.get("sprache") or "de").event())
        await wy_senden(schreiber, AudioStart(rate=rate, width=2, channels=1).event())
        for block in rahmen:
            await wy_senden(schreiber, AudioChunk(rate=rate, width=2, channels=1,
                                                  audio=block).event())
        await wy_senden(schreiber, AudioStop().event())
        while True:
            ereignis = await wy_lesen(leser, 180)
            if ereignis is None:
                return {"ok": 0, "fehler": "Spracherkennung hat die Verbindung geschlossen."}
            if Transcript.is_type(ereignis.type):
                text = Transcript.from_event(ereignis).text or ""
                return {"ok": 1, "text": text.strip(),
                        "sekunden": round(time.monotonic() - t0, 2)}
    except (OSError, asyncio.TimeoutError) as err:
        return {"ok": 0, "fehler": "Spracherkennung: " + fehlertext(err)}
    finally:
        schreiber.close()


async def sprachausgabe(cfg: dict, text: str) -> dict:
    """Text -> Audio ueber Piper. Rueckgabe enthaelt die PCM-Bloecke."""
    from wyoming.audio import AudioChunk, AudioStop
    from wyoming.tts import Synthesize
    t0 = time.monotonic()
    try:
        leser, schreiber = await wy_verbinden(cfg["piper_host"], int(cfg["piper_port"]))
    except (OSError, asyncio.TimeoutError) as err:
        return {"ok": 0, "fehler": "Sprachausgabe: " + fehlertext(err)}
    bloecke, rate, breite, kanaele = [], 22050, 2, 1
    try:
        await wy_senden(schreiber, Synthesize(text=text).event())
        while True:
            ereignis = await wy_lesen(leser, 180)
            if ereignis is None:
                return {"ok": 0, "fehler": "Sprachausgabe hat die Verbindung geschlossen."}
            if AudioChunk.is_type(ereignis.type):
                block = AudioChunk.from_event(ereignis)
                rate, breite, kanaele = block.rate, block.width, block.channels
                bloecke.append(block.audio)
            elif AudioStop.is_type(ereignis.type):
                return {"ok": 1, "bloecke": bloecke, "rate": rate, "width": breite,
                        "channels": kanaele, "sekunden": round(time.monotonic() - t0, 2)}
    except (OSError, asyncio.TimeoutError) as err:
        return {"ok": 0, "fehler": "Sprachausgabe: " + fehlertext(err)}
    finally:
        schreiber.close()


# ---------------------------------------------------------------------------
# Sprachmodell - nur als Rueckfallebene
# ---------------------------------------------------------------------------
def llm_fragen(cfg: dict, satz: str, ziele: list) -> dict:
    """Fragt das lokale Sprachmodell ueber die OpenAI-vertraegliche
    Schnittstelle von llama.cpp.

    Das Modell darf NICHT frei formulieren, sondern muss eine der bekannten
    Absichten als JSON zurueckgeben. Alles andere waere ein Wuerfelspiel:
    zwischen 'schalte das Licht ein' und 'ich schalte gleich das Licht ein'
    liegt in einem Haus ein Unterschied.
    """
    anweisung = (
        "Du bist Teil einer Hausautomatisierung. Ordne den Satz des Benutzers einer "
        "Absicht zu und antworte AUSSCHLIESSLICH mit einem JSON-Objekt, ohne "
        "Erklaerung und ohne Codeblock.\n"
        "Felder: absicht (schalten|dimmen|frage|unbekannt), aktion (ein|aus|wert|"
        "temperatur|), ziel (genau eine der bekannten Bezeichnungen oder leer), "
        "wert (Zahl oder null), antwort (kurzer deutscher Satz).\n"
        "Bekannte Ziele: " + ", ".join(ziele) + "\n"
        "Passt nichts, setze absicht auf unbekannt."
    )
    koerper = json.dumps({
        "messages": [{"role": "system", "content": anweisung},
                     {"role": "user", "content": satz}],
        "max_tokens": 160, "temperature": 0,
    }).encode("utf-8")
    anfrage = urllib.request.Request(
        f"http://{cfg['llm_host']}:{int(cfg['llm_port'])}/v1/chat/completions",
        data=koerper,
        headers={"Content-Type": "application/json",
                 "User-Agent": "LoxBerry-Sprachsteuerung-Plugin/0.9",
                 "Accept": "application/json",
                 "Accept-Language": "de"})
    t0 = time.monotonic()
    try:
        with urllib.request.urlopen(anfrage, timeout=120) as antwort:
            d = json.loads(antwort.read().decode("utf-8"))
    except urllib.error.HTTPError as err:
        return {"ok": 0, "fehler": f"Sprachmodell antwortete mit HTTP {err.code}."}
    except urllib.error.URLError as err:
        return {"ok": 0, "fehler": "Sprachmodell: " + str(err.reason)}
    except (OSError, ValueError) as err:
        return {"ok": 0, "fehler": "Sprachmodell: " + str(err)}
    try:
        roh = d["choices"][0]["message"]["content"]
    except (KeyError, IndexError, TypeError):
        return {"ok": 0, "fehler": "Sprachmodell hat keine verwertbare Antwort geliefert."}
    # Modelle packen JSON gern in einen Codeblock - das wird abgeschnitten,
    # aber der Inhalt selbst NICHT zurechtgebogen.
    text = roh.strip()
    if text.startswith("```"):
        text = text.strip("`")
        text = text.split("\n", 1)[-1] if "\n" in text else text
        text = text.rsplit("```", 1)[0]
    anfang, ende = text.find("{"), text.rfind("}")
    if anfang < 0 or ende <= anfang:
        return {"ok": 0, "fehler": "Sprachmodell hat kein JSON geliefert, sondern: "
                                   + roh.strip()[:120]}
    try:
        erg = json.loads(text[anfang:ende + 1])
    except ValueError:
        return {"ok": 0, "fehler": "Sprachmodell hat kaputtes JSON geliefert: "
                                   + text[anfang:ende + 1][:120]}
    erg["ok"] = 1
    erg["quelle"] = "llm"
    erg["sekunden"] = round(time.monotonic() - t0, 2)
    return erg


# ---------------------------------------------------------------------------
# Vom Satz zur Tat
# ---------------------------------------------------------------------------
def verlauf_anhaengen(eintrag: dict, grenze: int = 50) -> None:
    """Die letzten Saetze mit ihrem Ergebnis - das ist der wichtigste
    Anhaltspunkt bei 'sie versteht mich nicht'."""
    d = json_lesen(DATEI_VERLAUF)
    liste = d.get("saetze") or []
    liste.insert(0, eintrag)
    json_schreiben(DATEI_VERLAUF, {"saetze": liste[:max(5, grenze)]})


def miniserver_rufen(url: str, ersatz: dict) -> dict:
    """Wahlweise: den Miniserver unmittelbar aufrufen.

    Der Regelweg ist MQTT - dafuer braucht das Plugin keine Zugangsdaten des
    Miniservers. Wer den unmittelbaren Aufruf will, traegt eine Adresse ein.
    """
    if not url:
        return {"ok": -1}
    voll = url
    for schluessel, wert in ersatz.items():
        voll = voll.replace("{" + schluessel + "}", str(wert if wert is not None else ""))
    anfrage = urllib.request.Request(voll, headers={
        "User-Agent": "LoxBerry-Sprachsteuerung-Plugin/0.9", "Accept": "*/*",
        "Accept-Language": "de", "Accept-Encoding": "identity"})
    try:
        with urllib.request.urlopen(anfrage, timeout=8) as antwort:
            return {"ok": 1, "code": antwort.status,
                    "text": antwort.read(200).decode("utf-8", "ignore")}
    except urllib.error.HTTPError as err:
        # Der Miniserver antwortet auf falsche Zugangsdaten mit 401 - das ist
        # etwas anderes als 'nicht erreichbar' und gehoert so gemeldet.
        if err.code == 401:
            return {"ok": 0, "fehler": "Der Miniserver hat die Zugangsdaten abgelehnt (401)."}
        return {"ok": 0, "fehler": f"Der Miniserver antwortete mit HTTP {err.code}."}
    except urllib.error.URLError as err:
        return {"ok": 0, "fehler": "Miniserver: " + str(err.reason)}
    except OSError as err:
        return {"ok": 0, "fehler": "Miniserver: " + str(err)}


# ---------------------------------------------------------------------------
# Der Rueckweg nach Loxone
#
# Piper spricht in den Lautsprecher des Satelliten, der die Frage gehoert hat.
# Wer im Nebenzimmer steht, hoert nichts - und in der Visu steht nichts. Beides
# holt dieser Abschnitt nach:
#
#   MQTT   <praefix>/antwort   der fertige Satz -> Virtueller Texteingang
#          <praefix>/ok        1 verstanden, 0 nicht
#   Audio  Ansage ueber Music Server / Audioserver
#
# Die Adressform ist die feldgleiche Uebertragung von awm_tts_url() aus
# LoxBerry-Plugin-AWM-Abfuhr und abfahrt_tts_url() aus dem Abfahrtsassistenten:
# gleiche Modi, gleiche Feldnamen, gleiche Adresse. Im Haus soll eine Bedienung
# gelten und nicht drei. Nur die Sprache ist eine andere - dort PHP, hier
# Python.
# ---------------------------------------------------------------------------

def loxone_tts_url(tts: dict, text: str):
    """Adresse der Ansage bauen.

    None -> Modus 'audioserver'. Der originale Loxone Audioserver kennt keinen
            TTS-Aufruf ueber HTTP; das laeuft ueber Loxone Config
            (Textgenerator am TTS-Eingang) und nicht ueber uns.
    ''   -> es fehlt die Adresse des Servers.
    """
    modus = str(tts.get("mode") or "musicserver")
    if modus == "audioserver":
        return None
    ip = str(tts.get("ip") or "").strip()
    if ip == "":
        return ""
    port = int(tts.get("port") or 7091)
    sprache = str(tts.get("lang") or "de")
    try:
        laut = max(1, min(100, int(tts.get("volume") or 8)))
    except (TypeError, ValueError):
        laut = 8

    if modus == "musicserver":
        # Zonenliste normalisieren: '2,4,6' plus Lautstaerkefeld ergibt
        # '2~8,4~8,6~8'. Eine im Feld bereits angegebene Lautstaerke
        # ('4~15') hat Vorrang und bleibt stehen.
        zonen = []
        for z in str(tts.get("zones") or "").split(","):
            z = z.strip()
            if z == "":
                continue
            zonen.append(z if "~" in z else "%s~%d" % (z, laut))
        zonenstr = ",".join(zonen) if zonen else "1~%d" % laut
        return "http://%s:%d/audio/grouped/tts/%s/%s" % (
            ip, port, zonenstr,
            urllib.parse.quote(sprache + "|" + text, safe=""))

    # ms4h und custom: Vorlage mit Platzhaltern. Die Reihenfolge ist Absicht -
    # {text} kommt zuletzt, damit ein Platzhaltername, der zufaellig im
    # gesprochenen Satz steht, nicht selbst noch ersetzt wird.
    vorlage = str(tts.get("template") or "").strip() or TTS_VORLAGE_MS4H
    for platzhalter, wert in (("{ip}", ip),
                              ("{port}", str(port)),
                              ("{zones}", str(tts.get("zones") or "")),
                              ("{vol}", str(laut)),
                              ("{lang}", sprache),
                              ("{text}", urllib.parse.quote(text, safe=""))):
        vorlage = vorlage.replace(platzhalter, wert)
    return vorlage


def loxone_ansagen(cfg: dict, text: str) -> dict:
    """Die Antwort ueber die Loxone-Audioausgabe ansagen."""
    url = loxone_tts_url(cfg.get("tts") or {}, text)
    if url is None:
        melde_gebremst("tts_audioserver",
                       "Ansage: Modus 'Originaler Loxone Audioserver' - die "
                       "Sprachausgabe laeuft ueber Loxone Config "
                       "(Textgenerator am TTS-Eingang), nicht ueber das Plugin.",
                       3600)
        return {"ok": 0, "grund": "audioserver"}
    if url == "":
        melde_gebremst("tts_keine_adresse",
                       "Ansage uebersprungen: fuer die Loxone-Audioausgabe ist "
                       "keine Adresse eingetragen.")
        return {"ok": 0, "grund": "keine_adresse"}
    try:
        with urllib.request.urlopen(url, timeout=10) as antwort:
            antwort.read(200)
    except (urllib.error.URLError, OSError) as err:
        melde_gebremst("tts_fehler", "Ansage fehlgeschlagen: " + fehlertext(err))
        return {"ok": 0, "fehler": fehlertext(err)}
    _LOG.info("Ansage gesendet: %r", text)
    return {"ok": 1}


def antwort_ausgeben(cfg: dict, erg: dict) -> None:
    """Antworttext nach Loxone - als MQTT-Text und wahlweise als Ansage.

    Laeuft fuer JEDEN Satz, auch fuer einen nicht verstandenen: gerade dann
    will man in der Visu lesen koennen, woran es lag.
    """
    text = str(erg.get("antwort") or "").strip()
    if text == "":
        return
    if cfg.get("mqtt_ein"):
        praefix = str(cfg.get("mqtt_topic") or "sprachsteuerung").strip("/") or "sprachsteuerung"
        # Der Text behaelt seine Leerzeichen - er soll in der Visu lesbar sein.
        # Das Gateway nimmt alles hinter dem Thema als Nutzlast. Das Thema
        # 'satz' weiter oben ersetzt Leerzeichen dagegen weiterhin durch
        # Unterstriche; das bleibt so, damit bestehende Bausteine passen.
        mqtt_senden({"antwort": text, "ok": int(erg.get("ok") or 0)}, praefix)
    if str(cfg.get("antwortweg") or "beide") in ("loxone", "beide"):
        loxone_ansagen(cfg, text)


def satz_verarbeiten(satz: str, cfg: dict, v) -> dict:
    """Satz verarbeiten und die Antwort nach Loxone geben.

    Die Trennung in Huelle und Kern hat einen Grund: der Kern verlaesst sich an
    mehreren Stellen vorzeitig - unbekanntes Ziel, Sprachmodell versagt, nichts
    verstanden. Stuende die Ausgabe im Kern, muesste sie an jeder dieser
    Stellen wiederholt werden, und der naechste neue Rueckgabepfad wuerde sie
    vergessen.
    """
    erg = satz_kern(satz, cfg, v)
    try:
        antwort_ausgeben(cfg, erg)
    except Exception as err:  # noqa: BLE001
        # Eine misslungene Ansage darf den Befehl nicht nachtraeglich
        # scheitern lassen: geschaltet ist zu diesem Zeitpunkt bereits.
        melde_gebremst("antwortweg", "Antwortweg: " + fehlertext(err))
    return erg


def satz_kern(satz: str, cfg: dict, v) -> dict:
    """Der Kern: Satz -> Absicht -> Tat -> Antworttext.

    Rueckgabe enthaelt immer 'antwort' (was gesprochen werden soll) und
    'quelle' (muster, llm oder keine).
    """
    beginn = time.monotonic()
    erkannt = v.erkennen(satz) if v is not None else {"ok": 0, "grund": "keine_regeln"}
    quelle = "muster"

    if not erkannt.get("ok"):
        grund = erkannt.get("grund")
        # Ein unbekanntes ZIEL ist kein Fall fuers Sprachmodell: das Muster hat
        # ja gepasst. Hier hilft nur, das Ziel einzutragen - und genau das wird
        # gesagt, statt zu raten.
        if grund == "ziel_unbekannt":
            antwort = ("Ich kenne kein Geraet mit der Bezeichnung %s."
                       % erkannt.get("gesucht", ""))
            erg = {"ok": 0, "quelle": "muster", "grund": grund, "antwort": antwort,
                   "satz": satz, "bekannt": erkannt.get("bekannt", [])}
            verlauf_anhaengen(dict(erg, ts=int(time.time())), int(cfg["verlauf_zeilen"]))
            return erg
        if cfg.get("llm_ein") and grund in ("kein_muster", "keine_regeln"):
            ziele = [z["name"] for z in (v.ziele.values() if v else [])]
            vom_modell = llm_fragen(cfg, satz, ziele)
            if not vom_modell.get("ok"):
                erg = {"ok": 0, "quelle": "llm", "grund": "llm_fehler",
                       "antwort": "Das habe ich nicht verstanden.",
                       "fehler": vom_modell.get("fehler"), "satz": satz}
                verlauf_anhaengen(dict(erg, ts=int(time.time())), int(cfg["verlauf_zeilen"]))
                return erg
            if str(vom_modell.get("absicht") or "unbekannt") == "unbekannt":
                erg = {"ok": 0, "quelle": "llm", "grund": "unbekannt",
                       "antwort": str(vom_modell.get("antwort") or "Das habe ich nicht verstanden."),
                       "satz": satz}
                verlauf_anhaengen(dict(erg, ts=int(time.time())), int(cfg["verlauf_zeilen"]))
                return erg
            # Das Modell nennt ein Ziel im Klartext - das wird gegen die
            # bekannte Liste geprueft und NICHT einfach uebernommen.
            ziel = v.ziel_finden(str(vom_modell.get("ziel") or "")) if v else None
            erkannt = {"ok": 1,
                       "absicht": str(vom_modell.get("absicht") or ""),
                       "aktion": str(vom_modell.get("aktion") or ""),
                       "wert": vom_modell.get("wert"),
                       "ziel": ziel["schluessel"] if ziel else None,
                       "zielname": ziel["name"] if ziel else "",
                       "thema": ziel["thema"] if ziel else "",
                       "url": ziel["url"] if ziel else "",
                       "antwort": str(vom_modell.get("antwort") or ""),
                       "satz": satz}
            quelle = "llm"
            if ziel is None and erkannt["absicht"] in ("schalten", "dimmen"):
                erg = {"ok": 0, "quelle": "llm", "grund": "ziel_unbekannt",
                       "antwort": "Ich weiss nicht, welches Geraet gemeint ist.",
                       "satz": satz}
                verlauf_anhaengen(dict(erg, ts=int(time.time())), int(cfg["verlauf_zeilen"]))
                return erg
        else:
            erg = {"ok": 0, "quelle": "keine", "grund": grund,
                   "antwort": "Das habe ich nicht verstanden.", "satz": satz}
            verlauf_anhaengen(dict(erg, ts=int(time.time())), int(cfg["verlauf_zeilen"]))
            return erg

    # ---- Tat: MQTT und wahlweise der unmittelbare Aufruf ----
    if cfg.get("mqtt_ein"):
        praefix = str(cfg.get("mqtt_topic") or "sprachsteuerung").strip("/") or "sprachsteuerung"
        paare = {
            "satz": satz.replace(" ", "_"),
            "absicht": erkannt["absicht"],
            "aktion": erkannt["aktion"],
            "ziel": erkannt.get("ziel") or "",
            "wert": "" if erkannt.get("wert") is None else erkannt["wert"],
            "quelle": quelle,
            "zeit": int(time.time()),
        }
        if erkannt.get("thema"):
            # Zusaetzlich unter dem Thema des Ziels: so kann ein virtueller
            # Eingang in Loxone genau an einem Thema haengen.
            paare[erkannt["thema"] + "/aktion"] = erkannt["aktion"]
            if erkannt.get("wert") is not None:
                paare[erkannt["thema"] + "/wert"] = erkannt["wert"]
        mqtt_senden(paare, praefix)

    ruf = miniserver_rufen(erkannt.get("url") or str(cfg.get("miniserver_url") or ""),
                           {"ziel": erkannt.get("ziel") or "",
                            "aktion": erkannt.get("aktion") or "",
                            "wert": erkannt.get("wert")})
    if ruf.get("ok") == 0:
        _LOG.warning("Miniserver-Aufruf fehlgeschlagen: %s", ruf.get("fehler"))

    erg = {
        "ok": 1, "quelle": quelle, "satz": satz,
        "absicht": erkannt["absicht"], "aktion": erkannt["aktion"],
        "ziel": erkannt.get("ziel"), "zielname": erkannt.get("zielname", ""),
        "wert": erkannt.get("wert"), "thema": erkannt.get("thema", ""),
        "antwort": erkannt.get("antwort") or "",
        "miniserver": ruf,
        "sekunden": round(time.monotonic() - beginn, 2),
    }
    verlauf_anhaengen(dict(erg, ts=int(time.time())), int(cfg["verlauf_zeilen"]))
    _LOG.info("Satz %r -> %s/%s ziel=%s (%s, %.2f s)", satz, erg["absicht"],
              erg["aktion"], erg["ziel"], quelle, erg["sekunden"])
    return erg


# ---------------------------------------------------------------------------
# Ein Wyoming-Satellit
#
# Ablauf laut Spezifikation: der Server verbindet sich ZUM Satelliten, fragt
# ihn mit 'describe' ab und sagt ihm mit 'run-satellite', dass er bereit ist.
# Der Satellit meldet sich danach mit 'run-pipeline' und schickt Audio.
# ---------------------------------------------------------------------------
class Satellit:
    def __init__(self, eintrag: dict, cfg: dict, v) -> None:
        self.name = str(eintrag.get("name") or eintrag.get("host") or "Satellit")
        self.host = str(eintrag.get("host") or "")
        self.port = int(eintrag.get("port") or 10700)
        self.cfg = cfg
        self.v = v
        self.info = {}
        self.zustand = "getrennt"
        self.letzter_satz = ""
        self.letzte_meldung = ""

    async def lauf(self) -> None:
        from wyoming.audio import AudioChunk, AudioStart, AudioStop
        from wyoming.info import Describe, Info
        from wyoming.satellite import RunSatellite
        from wyoming.pipeline import RunPipeline

        leser, schreiber = await wy_verbinden(self.host, self.port)
        self.zustand = "verbunden"
        try:
            await wy_senden(schreiber, Describe().event())
            await wy_senden(schreiber, RunSatellite().event())

            rahmen: list = []
            rate = 16000
            sammelt = False
            while _LAUF:
                ereignis = await wy_lesen(leser, 3600)
                if ereignis is None:
                    break
                typ = ereignis.type
                if Info.is_type(typ):
                    try:
                        self.info = Info.from_event(ereignis).to_dict()
                    except Exception:  # noqa: BLE001 - Info ist nur Beiwerk
                        self.info = {}
                    _LOG.info("Satellit %s gemeldet.", self.name)
                elif RunPipeline.is_type(typ):
                    # Der Satellit will eine Verarbeitung. Startet er bei 'asr',
                    # hat er das Weckwort selbst erkannt.
                    rahmen, sammelt = [], True
                    self.zustand = "hoert"
                    _LOG.info("Satellit %s: Verarbeitung angefordert.", self.name)
                elif AudioStart.is_type(typ):
                    start = AudioStart.from_event(ereignis)
                    rate = start.rate
                    rahmen, sammelt = [], True
                    self.zustand = "hoert"
                elif AudioChunk.is_type(typ):
                    if sammelt:
                        block = AudioChunk.from_event(ereignis)
                        rate = block.rate
                        rahmen.append(block.audio)
                        # Notbremse: mehr als 30 Sekunden nimmt niemand am Stueck auf.
                        if len(rahmen) * len(block.audio) > rate * 2 * 30:
                            sammelt = False
                            melde_gebremst("zu_lang_" + self.name,
                                           f"Satellit {self.name}: mehr als 30 s Audio am "
                                           "Stueck - abgeschnitten. Erkennt der Satellit das "
                                           "Ende des Sprechens nicht?")
                elif AudioStop.is_type(typ):
                    if rahmen:
                        await self.verarbeiten(schreiber, rahmen, rate)
                    rahmen, sammelt = [], False
                    self.zustand = "wartet"
        finally:
            self.zustand = "getrennt"
            schreiber.close()

    async def verarbeiten(self, schreiber, rahmen: list, rate: int) -> None:
        from wyoming.audio import AudioChunk, AudioStart, AudioStop

        cfg = config()
        erkannt = await spracherkennung(cfg, rahmen, rate)
        if not erkannt.get("ok"):
            self.letzte_meldung = erkannt.get("fehler", "")
            _LOG.error("Satellit %s: %s", self.name, self.letzte_meldung)
            return
        satz = erkannt["text"]
        self.letzter_satz = satz
        if not satz:
            self.letzte_meldung = "Es wurde nichts verstanden (leerer Text)."
            return

        erg = satz_verarbeiten(satz, cfg, self.v)
        self.letzte_meldung = erg.get("antwort", "")
        json_schreiben(DATEI_ZUSTAND, {"ts": int(time.time()), "pid": os.getpid(),
                                       "letzter_satz": satz, "letztes_ergebnis": erg})

        # Ab 0.9.1 entscheidet zusaetzlich der Antwortweg. Bei 'loxone' bleibt
        # der Satellit still, weil die Ansage bereits ueber den Music Server
        # gelaufen ist - sonst hoerte man sie im selben Raum zweimal.
        if (not cfg.get("antwort_sprechen") or not erg.get("antwort")
                or str(cfg.get("antwortweg") or "beide") == "loxone"):
            return
        gesprochen = await sprachausgabe(cfg, erg["antwort"])
        if not gesprochen.get("ok"):
            _LOG.error("Satellit %s: %s", self.name, gesprochen.get("fehler"))
            return
        await wy_senden(schreiber, AudioStart(rate=gesprochen["rate"],
                                              width=gesprochen["width"],
                                              channels=gesprochen["channels"]).event())
        for block in gesprochen["bloecke"]:
            await wy_senden(schreiber, AudioChunk(rate=gesprochen["rate"],
                                                  width=gesprochen["width"],
                                                  channels=gesprochen["channels"],
                                                  audio=block).event())
        await wy_senden(schreiber, AudioStop().event())


async def satellit_betreuen(eintrag: dict, cfg: dict, v) -> None:
    """Haelt einen Satelliten dauerhaft verbunden und faengt sich nach
    Ausfaellen wieder - mit wachsendem Abstand, statt dagegen anzurennen."""
    fehler_folge = 0
    name = str(eintrag.get("name") or eintrag.get("host"))
    while _LAUF:
        sat = Satellit(eintrag, cfg, v)
        SATELLITEN[name] = sat
        try:
            await sat.lauf()
            fehler_folge = 0
        except (OSError, asyncio.TimeoutError) as err:
            fehler_folge += 1
            melde_gebremst("sat_" + name,
                           f"Satellit {name}: {fehlertext(err)}", 900)
        except Exception as err:  # noqa: BLE001
            fehler_folge += 1
            melde_gebremst("sat_" + name, f"Satellit {name}: {fehlertext(err)}", 900)
        if not _LAUF:
            break
        pause = min(300, 5 * max(1, fehler_folge))
        for _ in range(pause):
            if not _LAUF:
                break
            await asyncio.sleep(1)


SATELLITEN: dict = {}


# ---------------------------------------------------------------------------
# ESPHome-Mikrofone
#
# EHRLICHE EINORDNUNG: Dieser Weg ist der am wenigsten gepruefte Teil des
# Plugins. Das ESPHome-Protokoll ist verschluesselt und wird hier ueber die
# offizielle Bibliothek aioesphomeapi bedient; ohne ein solches Geraet laesst
# sich nicht nachmessen, ob der Audioweg traegt. Ein Fehler hier darf deshalb
# die Wyoming-Satelliten NICHT mitreissen - jeder laeuft in seiner eigenen
# Aufgabe.
# ---------------------------------------------------------------------------
async def esphome_betreuen(eintrag: dict, cfg: dict, v) -> None:
    name = str(eintrag.get("name") or eintrag.get("host"))
    try:
        from aioesphomeapi import APIClient
    except ImportError:
        melde_gebremst("esphome_fehlt",
                       "Fuer ESPHome-Mikrofone fehlt das Paket aioesphomeapi. "
                       "Die Wyoming-Satelliten laufen weiter. Nachinstallieren: "
                       "venv/bin/pip install aioesphomeapi", 86400)
        return
    fehler_folge = 0
    while _LAUF:
        klient = APIClient(str(eintrag.get("host") or ""),
                          int(eintrag.get("port") or 6053),
                          str(eintrag.get("passwort") or "") or None,
                          noise_psk=str(eintrag.get("schluessel") or "") or None)
        try:
            await klient.connect(login=True)
            geraet = await klient.device_info()
            _LOG.info("ESPHome-Mikrofon %s verbunden: %s", name, getattr(geraet, "name", "?"))
            fehler_folge = 0
            # Die Bibliothek meldet Sprachanfragen ueber Rueckrufe. Das Audio
            # kommt bei aktuellen Fassungen ueber eine eigene Verbindung; was
            # hier passiert, ist deshalb bewusst schlank gehalten und im
            # Uebergabetext als ungeprueft benannt.
            while _LAUF and klient.connected:
                await asyncio.sleep(1)
        except Exception as err:  # noqa: BLE001
            fehler_folge += 1
            melde_gebremst("esph_" + name, f"ESPHome-Mikrofon {name}: {fehlertext(err)}", 900)
        finally:
            try:
                await klient.disconnect()
            except Exception:  # noqa: BLE001
                pass
        if not _LAUF:
            break
        pause = min(300, 5 * max(1, fehler_folge))
        for _ in range(pause):
            if not _LAUF:
                break
            await asyncio.sleep(1)


# ---------------------------------------------------------------------------
# Warteschlange und Abbild
# ---------------------------------------------------------------------------
def antwort_schreiben(kennung: str, ok: int, meldung: str, zusatz: dict | None = None) -> None:
    ORDNER_ANTWORTEN.mkdir(parents=True, exist_ok=True)
    d = {"ok": int(ok), "meldung": str(meldung), "ts": int(time.time())}
    if zusatz:
        d.update(zusatz)
    json_schreiben(ORDNER_ANTWORTEN / f"{kennung}.json", d)
    grenze = time.time() - 900
    for alt in ORDNER_ANTWORTEN.glob("*.json"):
        try:
            if alt.stat().st_mtime < grenze:
                alt.unlink()
        except OSError:
            pass


async def warteschlange(cfg: dict, v) -> None:
    ORDNER_BEFEHLE.mkdir(parents=True, exist_ok=True)
    for datei in sorted(ORDNER_BEFEHLE.glob("*.json")):
        kennung = datei.stem
        b = json_lesen(datei)
        try:
            datei.unlink()
        except OSError:
            pass
        if not b:
            antwort_schreiben(kennung, 0, "Befehlsdatei war leer oder unlesbar.")
            continue
        aktion = str(b.get("aktion") or "")
        try:
            if aktion == "satz":
                satz = str(b.get("satz") or "").strip()
                if not satz:
                    antwort_schreiben(kennung, 0, "Es wurde kein Satz uebergeben.")
                    continue
                erg = satz_verarbeiten(satz, cfg, v)
                antwort_schreiben(kennung, 1 if erg.get("ok") else 0,
                                  erg.get("antwort") or "", {"ergebnis": erg})
            elif aktion == "sprechen":
                text = str(b.get("text") or "").strip()
                if not text:
                    antwort_schreiben(kennung, 0, "Es wurde kein Text uebergeben.")
                    continue
                erg = await sprachausgabe(cfg, text)
                if erg.get("ok"):
                    dauer = sum(len(x) for x in erg["bloecke"]) / (erg["rate"] * erg["width"])
                    antwort_schreiben(kennung, 1,
                                      "Sprachausgabe erzeugt: %.2f s Audio in %.2f s."
                                      % (dauer, erg["sekunden"]))
                else:
                    antwort_schreiben(kennung, 0, erg.get("fehler", ""))
            elif aktion == "neu_laden":
                antwort_schreiben(kennung, 1, "Saetze werden beim naechsten Satz neu gelesen.")
            else:
                antwort_schreiben(kennung, 0, "Unbekannte Aktion: " + aktion)
        except Exception as err:  # noqa: BLE001
            antwort_schreiben(kennung, 0, fehlertext(err))


def abbild_schreiben(cfg: dict, v) -> None:
    saetze = json_lesen(DATEI_SAETZE)
    sats = {}
    for name, sat in SATELLITEN.items():
        sats[name] = {"name": sat.name, "art": "wyoming", "host": sat.host,
                      "port": sat.port, "zustand": sat.zustand,
                      "letzter_satz": sat.letzter_satz,
                      "letzte_meldung": sat.letzte_meldung}
    json_schreiben(DATEI_LOXONE, {
        "ok": 1 if any(s["zustand"] != "getrennt" for s in sats.values()) else 0,
        "ts": int(time.time()),
        "satelliten": sats,
        "anzahl_regeln": len(saetze.get("regeln") or []),
        "anzahl_ziele": len(saetze.get("ziele") or {}),
        "ziele": {k: {"name": z.get("name", k), "thema": z.get("thema", k)}
                  for k, z in (saetze.get("ziele") or {}).items()},
        "verlauf": (json_lesen(DATEI_VERLAUF).get("saetze") or [])[:10],
    })


# ---------------------------------------------------------------------------
# Dienst
# ---------------------------------------------------------------------------
def signal_behandeln(*_):
    global _LAUF
    _LAUF = False
    _LOG.info("Beendigungssignal erhalten - Dienst haelt an.")


async def dienst() -> int:
    cfg = config()
    v = verstehen_laden()
    if v is not None:
        for beanstandung in v.pruefen():
            _LOG.warning("Satzdatei: %s", beanstandung)
    _LOG.info("Dienst startet: %d Satellit(en), Sprachmodell %s, Antwort %s.",
              len(cfg.get("satelliten") or []),
              "ein" if cfg.get("llm_ein") else "aus",
              "gesprochen" if cfg.get("antwort_sprechen") else "still")

    aufgaben = []
    for eintrag in cfg.get("satelliten") or []:
        if not isinstance(eintrag, dict) or not eintrag.get("host"):
            continue
        if str(eintrag.get("art") or "wyoming") == "esphome":
            aufgaben.append(asyncio.ensure_future(esphome_betreuen(eintrag, cfg, v)))
        else:
            aufgaben.append(asyncio.ensure_future(satellit_betreuen(eintrag, cfg, v)))
    if not aufgaben:
        _LOG.warning("Es ist kein Mikrofon eingetragen. Der Dienst laeuft trotzdem - "
                     "der Reiter Test kann Saetze auch ohne Mikrofon durchschicken.")

    letzte_saetze = None
    while _LAUF:
        if not (PDATA / "soll_laufen").is_file():
            _LOG.info("Der Merker soll_laufen ist weg - Dienst haelt an.")
            break
        cfg = config()
        # Saetze ohne Neustart uebernehmen, sobald sich die Datei aendert.
        try:
            stempel = DATEI_SAETZE.stat().st_mtime
        except OSError:
            stempel = 0
        if stempel != letzte_saetze:
            letzte_saetze = stempel
            v = verstehen_laden()
            for sat in SATELLITEN.values():
                sat.v = v
        try:
            await warteschlange(cfg, v)
        except Exception as err:  # noqa: BLE001
            _LOG.error("Warteschlange: %s", fehlertext(err))
        abbild_schreiben(cfg, v)
        await asyncio.sleep(1)

    for aufgabe in aufgaben:
        aufgabe.cancel()
    _LOG.info("Dienst beendet.")
    return 0


# ---------------------------------------------------------------------------
# Selbsttest
# ---------------------------------------------------------------------------
def dienst_erreichbar(host: str, port: int, zeit: float = 3.0) -> tuple[bool, str]:
    try:
        with socket.create_connection((host, int(port)), timeout=zeit):
            return True, ""
    except OSError as err:
        return False, str(err)


def selbsttest() -> int:
    cfg = config()
    v = verstehen_laden()
    zeilen, fehler = [], 0

    zeilen.append(f"[OK]   Python {sys.version.split()[0]}")
    for paket, pflicht in (("wyoming", True), ("aioesphomeapi", False)):
        try:
            __import__(paket)
            zeilen.append(f"[OK]   Paket {paket} geladen")
        except ImportError:
            if pflicht:
                fehler += 1
                zeilen.append(f"[FEHL] Paket {paket} fehlt - ohne das geht nichts")
            else:
                zeilen.append(f"[INFO] Paket {paket} fehlt - nur ESPHome-Mikrofone "
                              "betroffen, Wyoming laeuft")

    for name, pfad in (("Konfiguration", PCONFIG), ("Daten", PDATA), ("Log", PLOG)):
        ok = pfad.is_dir() and os.access(pfad, os.W_OK)
        zeilen.append(("[OK]   " if ok else "[FEHL] ") + f"Ordner {name} beschreibbar: {pfad}")
        if not ok:
            fehler += 1

    for schluessel, bezeichnung in (("whisper", "Spracherkennung (Whisper)"),
                                    ("piper", "Sprachausgabe (Piper)"),
                                    ("wake", "Wortwecker (openWakeWord)")):
        host = cfg[f"{schluessel}_host"]
        port = int(cfg[f"{schluessel}_port"])
        ok, grund = dienst_erreichbar(host, port)
        if ok:
            zeilen.append(f"[OK]   {bezeichnung} antwortet auf {host}:{port}")
        else:
            fehler += 1
            zeilen.append(f"[FEHL] {bezeichnung} antwortet nicht auf {host}:{port} ({grund}). "
                          "Laeuft der Container? Reiter Dienste.")
    if cfg.get("llm_ein"):
        ok, grund = dienst_erreichbar(cfg["llm_host"], int(cfg["llm_port"]))
        if ok:
            zeilen.append("[OK]   Sprachmodell antwortet auf %s:%s"
                          % (cfg["llm_host"], cfg["llm_port"]))
        else:
            fehler += 1
            zeilen.append("[FEHL] Sprachmodell antwortet nicht auf %s:%s (%s)."
                          % (cfg["llm_host"], cfg["llm_port"], grund))
    else:
        zeilen.append("[INFO] Sprachmodell ist abgeschaltet - es gelten nur die Satzmuster. "
                      "Fuer 'Licht an' ist das die schnellere und verlaesslichere Wahl.")

    sats = cfg.get("satelliten") or []
    if not sats:
        zeilen.append("[INFO] Es ist kein Mikrofon eingetragen. Saetze lassen sich im "
                      "Reiter Test trotzdem durchschicken.")
    else:
        for eintrag in sats:
            art = str(eintrag.get("art") or "wyoming")
            host = str(eintrag.get("host") or "")
            port = int(eintrag.get("port") or (6053 if art == "esphome" else 10700))
            ok, grund = dienst_erreichbar(host, port)
            zeilen.append(("[OK]   " if ok else "[FEHL] ")
                          + f"Mikrofon {eintrag.get('name') or host} ({art}) auf {host}:{port}"
                          + ("" if ok else f" - {grund}"))
            if not ok:
                fehler += 1

    if v is None:
        fehler += 1
        zeilen.append("[FEHL] verstehen.py liess sich nicht laden")
    else:
        beanstandungen = v.pruefen()
        if beanstandungen:
            fehler += len(beanstandungen)
            for b in beanstandungen:
                zeilen.append("[FEHL] Satzdatei: " + b)
        zeilen.append("[OK]   Satzdatei: %d Regeln, %d Ziele"
                      % (len(v.regeln), len(v.ziele)))

    m = mqtt_zustand()
    if not m["gefunden"]:
        fehler += 1
        zeilen.append("[FEHL] Kein MQTT-Abschnitt in der general.json des LoxBerry")
    elif m["autostart"]:
        zeilen.append("[OK]   MQTT-Gateway auf Autostart, UDP-Eingang %d" % m["udpport"])
    else:
        fehler += 1
        zeilen.append("[FEHL] Das MQTT-Gateway ist nicht auf Autostart gestellt "
                      "(System, MQTT Gateway). Ohne das kommt am Miniserver nichts an.")

    # ---- Rueckweg nach Loxone ----
    weg = str(cfg.get("antwortweg") or "beide")
    beschreibung = {"satellit": "nur der Lautsprecher des Mikrofons",
                    "loxone": "nur Music Server / Audioserver",
                    "beide": "Mikrofon und Music Server / Audioserver"}
    zeilen.append("[OK]   Antwortweg: %s (%s)" % (weg, beschreibung.get(weg, "?")))
    if cfg.get("mqtt_ein"):
        praefix = str(cfg.get("mqtt_topic") or "sprachsteuerung").strip("/") or "sprachsteuerung"
        zeilen.append("[OK]   Antworttext geht nach %s/antwort, das Ergebnis nach %s/ok"
                      % (praefix, praefix))
    else:
        zeilen.append("[INFO] MQTT ist abgeschaltet - der Antworttext erreicht die Visu nicht.")

    if weg in ("loxone", "beide"):
        tts = cfg.get("tts") or {}
        probe = "Ich habe das Licht im Wohnzimmer auf 50 Prozent gestellt."
        url = loxone_tts_url(tts, probe)
        if url is None:
            zeilen.append("[INFO] Ansage im Modus 'Originaler Loxone Audioserver': die "
                          "Sprachausgabe wird in Loxone Config eingerichtet "
                          "(Textgenerator am TTS-Eingang), nicht hier.")
        elif url == "":
            fehler += 1
            zeilen.append("[FEHL] Antwortweg steht auf '%s', aber es ist keine Adresse fuer "
                          "die Loxone-Audioausgabe eingetragen." % weg)
        else:
            ok, grund = dienst_erreichbar(str(tts.get("ip")), int(tts.get("port") or 7091))
            if ok:
                zeilen.append("[OK]   Loxone-Audioausgabe antwortet auf %s:%s"
                              % (tts.get("ip"), tts.get("port")))
            else:
                fehler += 1
                zeilen.append("[FEHL] Loxone-Audioausgabe antwortet nicht auf %s:%s (%s)."
                              % (tts.get("ip"), tts.get("port"), grund))
            # Die fertige Adresse mit ausgeben: im Browser aufgerufen sagt sie
            # sofort, ob Zonen und Lautstaerke stimmen - ohne Mikrofon.
            zeilen.append("       Probeansage: " + url)
    else:
        zeilen.append("[INFO] Antwortweg 'satellit': Loxone bekommt den Text, aber keine "
                      "Ansage. Der Satz steht trotzdem im Thema /antwort.")

    zeilen.append("")
    zeilen.append("Nicht geprueft, weil dafuer echte Hardware noetig ist:")
    zeilen.append("  - ob ein Mikrofon Audio liefert, das Whisper versteht")
    zeilen.append("  - ob das Weckwort in Ihrem Raum zuverlaessig anspricht")
    zeilen.append("  - ob ESPHome-Mikrofone den Audioweg tragen (der am wenigsten")
    zeilen.append("    gepruefte Teil dieses Plugins)")
    print("\n".join(zeilen))
    return 1 if fehler else 0


def main() -> int:
    log_einrichten()
    if "--selbsttest" in sys.argv:
        return selbsttest()
    if "--satz" in sys.argv:
        i = sys.argv.index("--satz")
        satz = sys.argv[i + 1] if len(sys.argv) > i + 1 else ""
        erg = satz_verarbeiten(satz, config(), verstehen_laden())
        print(json.dumps(erg, ensure_ascii=False, indent=1))
        return 0 if erg.get("ok") else 1
    signal.signal(signal.SIGTERM, signal_behandeln)
    signal.signal(signal.SIGINT, signal_behandeln)
    try:
        return asyncio.run(dienst())
    except KeyboardInterrupt:
        return 0
    except Exception as err:  # noqa: BLE001
        _LOG.error("Dienst abgebrochen: %s", fehlertext(err))
        return 1


if __name__ == "__main__":
    sys.exit(main())
