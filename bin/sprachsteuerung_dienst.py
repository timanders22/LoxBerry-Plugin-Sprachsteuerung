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
    sprachsteuerung_dienst.py               Dienst (Dauerbetrieb)
    sprachsteuerung_dienst.py --selbsttest  Pruefungen ohne Mikrofon, Klartext
    sprachsteuerung_dienst.py --satz "..."  einen Satz durch die Kette schicken
    sprachsteuerung_dienst.py --trocken "..."  denselben Satz nur DEUTEN
"""

from __future__ import annotations

import array
import asyncio
import functools
import json
import logging
import os
import re
import secrets
import signal
import socket
import subprocess
import sys
import time
import urllib.error
import urllib.parse
import urllib.request
from logging.handlers import RotatingFileHandler
from pathlib import Path


def lb_wurzel_ermitteln():
    """Den LoxBerry-Wurzelordner ohne festen Systempfad bestimmen.

    Vom eigenen Ablageort aufwaerts, bis ein Verzeichnis gefunden ist, das
    config/plugins UND webfrontend enthaelt. Trifft die uebliche
    Installation genauso wie eine an einem anderen Ort.
    """
    d = os.path.dirname(os.path.abspath(__file__))
    for _ in range(8):
        if os.path.isdir(os.path.join(d, "config", "plugins")) \
                and os.path.isdir(os.path.join(d, "webfrontend")):
            return d
        eltern = os.path.dirname(d)
        if eltern == d:
            break
        d = eltern
    return ""


def mqtt_wert_saeubern(wert):
    """Einen Wert fuer den UDP-Eingang des MQTT-Gateways unschaedlich machen.

    Das Gateway liest zeilenweise. Ein Zeilenumbruch im Wert zerlegt die
    Uebertragung, und aus den Bruchstuecken bildet das Gateway erfundene
    Themen. Ein Tabulator schadet ebenso, weil Leerzeichen Thema und Wert
    trennt.
    """
    text = str(wert)
    for zeichen in ("\r\n", "\r", "\n", "\t"):
        text = text.replace(zeichen, " ")
    while "  " in text:
        text = text.replace("  ", " ")
    return text.strip()


SELF = Path(__file__).resolve().parent
PNAME = SELF.name


def _wurzel_pruefen(k) -> bool:
    """Ist das wirklich eine LoxBerry-Wurzel?

    Bis 0.10.1 stand hier nur 'len(SELF.parents) >= 3'. Das ist auf jedem
    realen Pfad wahr - der else-Zweig lief nie, LBHOMEDIR und
    lb_wurzel_ermitteln() waren tot, und aus dem entpackten Archiv heraus
    ergab die feste Zahl '..' einen Pfad irgendwo im Arbeitsordner.
    Eine Bedingung, die nie zutreffen kann, sieht aus wie Vorsicht.
    """
    try:
        return (k / "config" / "plugins").is_dir() and (k / "webfrontend").is_dir()
    except OSError:
        return False


if len(SELF.parents) >= 3 and _wurzel_pruefen(SELF.parents[2]):
    LBHOME = SELF.parents[2]
else:
    # Die Reihenfolge zaehlt: die Auskunft von LoxBerry selbst, dann die
    # Suche aufwaerts, und erst als LETZTES wieder die feste Zahl "..".
    # lb_wurzel_ermitteln() gibt bei Misserfolg eine LEERE Zeichenkette
    # zurueck - daraus wuerde Path(".") und damit ein Schreibweg in das
    # gerade aktuelle Verzeichnis. Genau das steht in REGELN_1 als
    # "ein Pruefling schreibt dorthin, wo seine Umgebung hinzeigt".
    _kandidat = os.environ.get("LBHOMEDIR") or lb_wurzel_ermitteln()
    LBHOME = Path(_kandidat) if _kandidat else SELF.parents[2]

# Aus dem entpackten Archiv heraus heisst der Ordner ueber bin/ nicht wie
# das Plugin. Dann gilt die Auskunft von LoxBerry, sonst der feste Name -
# dieselbe Regel wie in sp_paths() auf der PHP-Seite.
if PNAME in ("bin", "", ".", "/"):
    PNAME = os.environ.get("LBPPLUGINDIR") or "sprachsteuerung"

PDATA = LBHOME / "data" / "plugins" / PNAME
PLOG = LBHOME / "log" / "plugins" / PNAME
PCONFIG = LBHOME / "config" / "plugins" / PNAME
PTEMPLATES = LBHOME / "templates" / "plugins" / PNAME

def _fassung() -> str:
    """Die Fassungsnummer aus der plugin.cfg - EINE Quelle.

    Bis 0.10.1 stand sie als Zahl im User-Agent und veraltete still:
    hardware.py fuehrte 0.9, waehrend die plugin.cfg 0.10.1 sagte.
    """
    for k in (LBHOME / "config" / "plugins" / PNAME / "plugin.cfg",
              SELF.parent / "plugin.cfg"):
        try:
            for zeile in k.read_text(encoding="utf-8", errors="replace").splitlines():
                if zeile.startswith("VERSION="):
                    return zeile.split("=", 1)[1].strip() or "0"
        except OSError:
            continue
    return "0"


FASSUNG = _fassung()

DATEI_CONFIG = PCONFIG / "sprachsteuerung.json"
DATEI_SAETZE = PCONFIG / "saetze.json"
DATEI_LOXONE = PDATA / "loxone.json"
DATEI_VERLAUF = PDATA / "verlauf.json"
DATEI_ANSAGEN = PDATA / "ansagen.json"
DATEI_RUHE = PDATA / "ruhe.json"
DATEI_MITSCHNITT = PLOG / "mitschnitt.log"
ORDNER_BEFEHLE = PDATA / "befehle"
ORDNER_ANTWORTEN = PDATA / "antworten"
ORDNER_TIMER = PDATA / "timer"
DATEI_LOG = PLOG / "sprachsteuerung.log"

sys.path.insert(0, str(SELF))
try:
    from verstehen import Verstehen, einebnen          # noqa: E402
except ImportError:                                     # pragma: no cover
    Verstehen = None

    def einebnen(text):                                 # noqa: D103
        return (text or "").strip().lower()


# ---------------------------------------------------------------------------
# Vorgaben - EINE Datei fuer beide Sprachen
#
# Bis 0.9.11 stand die Liste zweimal: hier und als sp_vorgaben() in
# webfrontend/html/sp_lib.php. Die Oberflaeche kannte 22 Schluessel, dieser
# Dienst 19. Ueber die Sprachgrenze hinweg gibt es keine gemeinsame Funktion,
# also eine gemeinsame DATEI - templates/vorgaben.json. Der Reiter Test zaehlt
# beide Seiten gegeneinander.
# ---------------------------------------------------------------------------
def vorgabendatei() -> dict:
    for kandidat in (PTEMPLATES / "vorgaben.json",
                     SELF.parent / "templates" / "vorgaben.json"):
        try:
            d = json.loads(kandidat.read_text(encoding="utf-8"))
            if isinstance(d, dict) and isinstance(d.get("vorgaben"), dict):
                return d
        except (OSError, ValueError):
            continue
    return {}


_VD = vorgabendatei()
VORGABEN = _VD.get("vorgaben") or {}
GRENZEN = _VD.get("grenzen") or {}
AUSWAHL = _VD.get("auswahl") or {}
WAKEWORDS = _VD.get("wakewords") or []

TTS_VORLAGE_MS4H = "http://{ip}:{port}/tts?text={text}&zone={zones}&vol={vol}"

_LAUF = True
_LOG = logging.getLogger("sprachsteuerung")
_LETZTE_MELDUNG: dict[str, float] = {}
# Kontext je Mikrofon: was zuletzt gemeint war. Absichtlich nur im Speicher -
# nach einem Neustart soll die Anlage nicht auf einen Satz von gestern
# antworten.
_KONTEXT: dict[str, dict] = {}
# Offene Rueckfragen je Mikrofon (heikle Ziele).
_OFFEN: dict[str, dict] = {}


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


# ---------------------------------------------------------------------------
# Meldung in den Benachrichtigungsbereich des LoxBerry
#
# WARUM UEBER EIN PHP-ZWISCHENSTUECK: notify_ext() gibt es nur in der
# LoxBerry-Bibliothek, und die gibt es nur in Perl und PHP. Dieselbe Bauweise
# benutzen Bewaesserung (bin/bw_notify.php) und Funkwacht (bin/fw_notify.php).
#
# Bis 0.9.11 gingen Stoerungen ausschliesslich ins eigene Protokoll - auf der
# Ramdisk, wo niemand hinsieht, solange das Haus noch reagiert. Ein toter
# Container faellt damit erst auf, wenn jemand davorsteht und redet.
# ---------------------------------------------------------------------------
def melden(schwere: int, text: str, schluessel: str = "", stunden: int = 24) -> None:
    """Eine Meldung ablegen. schwere: 3 = Fehler, 6 = Hinweis.

    Dieselbe Meldung hoechstens einmal je 'stunden' - der Meldebereich soll
    lesbar bleiben.
    """
    schluessel = schluessel or text[:40]
    jetzt = time.time()
    if jetzt - _LETZTE_MELDUNG.get("melden_" + schluessel, 0) < stunden * 3600:
        return
    _LETZTE_MELDUNG["melden_" + schluessel] = jetzt
    skript = SELF / "sp_notify.php"
    if not skript.is_file():
        return
    try:
        # Der Pluginordner wird MITGEGEBEN: dem Dienst koennen die
        # LoxBerry-Umgebungsvariablen fehlen, und bei einer Zweitinstallation
        # heisst der Ordner sprachsteuerung_01. Eine Meldung unter einem
        # Paketnamen, den es nicht gibt, findet niemand.
        aus = subprocess.run(["php", str(skript), str(int(schwere)), text, PNAME],
                             capture_output=True, timeout=15, check=False)
        if aus.returncode != 0:
            # check=False bleibt richtig - eine misslungene Meldung darf den
            # Dienst nicht anhalten. Verschweigen darf man sie trotzdem nicht.
            melde_gebremst("melden_rc",
                           "sp_notify.php endete mit %d: %s"
                           % (aus.returncode,
                              (aus.stderr or b"").decode("utf-8", "replace")[:200]),
                           3600)
    except (OSError, subprocess.SubprocessError) as err:
        # Eine misslungene Meldung darf den Dienst nicht anhalten.
        melde_gebremst("melden_fehler", "Meldung liess sich nicht ablegen: %s" % err, 3600)


# ---------------------------------------------------------------------------
# Mitschnitt - eine FRIST, kein Schalter
#
# Bei diesem Plugin laufen fuenf Gegenstellen: Satellit, ESPHome, Whisper,
# Piper, Sprachmodell. Geht ein Satz unterwegs verloren, zeigt das Protokoll
# nur das Ergebnis, nicht den Weg.
#
# Ein vergessener Mitschnitt schriebe die Ramdisk voll, auf der log/plugins
# liegt. Deshalb eine Frist mit Selbstabschaltung UND eine harte Obergrenze.
# ---------------------------------------------------------------------------
MITSCHNITT_MAX = 2 * 1024 * 1024


def mitschnitt_laeuft(cfg: dict) -> bool:
    try:
        return int(cfg.get("mitschnitt_bis") or 0) > time.time()
    except (TypeError, ValueError):
        return False


def mitschnitt(cfg: dict, richtung: str, text: str) -> None:
    if not mitschnitt_laeuft(cfg):
        return
    try:
        DATEI_MITSCHNITT.parent.mkdir(parents=True, exist_ok=True)
        if DATEI_MITSCHNITT.is_file() and DATEI_MITSCHNITT.stat().st_size > MITSCHNITT_MAX:
            return
        with DATEI_MITSCHNITT.open("a", encoding="utf-8") as f:
            f.write("[%s] %s %s\n" % (time.strftime("%Y-%m-%d %H:%M:%S"),
                                      richtung, str(text)[:2000]))
    except OSError:
        pass


# ---------------------------------------------------------------------------
# Konfiguration
# ---------------------------------------------------------------------------
def _zahl_in_grenzen(wert, feld, vorgabe):
    klein, gross = GRENZEN.get(feld, [None, None])
    try:
        z = int(wert if wert not in (None, "") else 0)
    except (TypeError, ValueError):
        return vorgabe
    if klein is not None:
        z = max(int(klein), min(int(gross), z))
    return z


def config() -> dict:
    c = dict(VORGABEN)
    c.update(json_lesen(DATEI_CONFIG))

    for feld in GRENZEN:
        c[feld] = _zahl_in_grenzen(c.get(feld), feld, VORGABEN.get(feld, 0))

    weg = str(c.get("antwortweg") or "")
    erlaubt = AUSWAHL.get("antwortweg") or ["beide"]
    c["antwortweg"] = weg if weg in erlaubt else "beide"

    # Der TTS-Block wird auf die Vorgaben GELEGT, nicht ersetzt: eine aeltere
    # Konfiguration ohne diesen Block bekommt so vollstaendige Felder, ohne
    # dass irgendwo ein .get() mit Ersatzwert stehen muss.
    t = dict(VORGABEN.get("tts") or {})
    if isinstance(c.get("tts"), dict):
        t.update(c["tts"])
    modi = AUSWAHL.get("tts_mode") or ["musicserver"]
    t["mode"] = t.get("mode") if t.get("mode") in modi else "musicserver"
    for feld, klein, gross in (("port", 1, 65535), ("volume", 1, 100)):
        try:
            t[feld] = max(klein, min(gross, int(t.get(feld) or 0)))
        except (TypeError, ValueError):
            t[feld] = (VORGABEN.get("tts") or {}).get(feld)
    t["ip"] = str(t.get("ip") or "").strip()
    t["zones"] = str(t.get("zones") or "1").strip() or "1"
    t["template"] = str(t.get("template") or "").strip()
    t["stimme"] = str(t.get("stimme") or "").strip()
    sprachkuerzel = "".join(z for z in str(t.get("lang") or "de").lower() if z.isalpha())
    t["lang"] = sprachkuerzel[:5] or "de"
    c["tts"] = t

    r = dict(VORGABEN.get("ruhe") or {})
    if isinstance(c.get("ruhe"), dict):
        r.update(c["ruhe"])
    r["ein"] = 1 if r.get("ein") else 0
    for feld in ("von", "bis"):
        z = str(r.get(feld) or "")
        r[feld] = z if re.fullmatch(r"\d{1,2}:\d{2}", z) else (VORGABEN.get("ruhe") or {}).get(feld, "22:00")
    c["ruhe"] = r
    return c


def cfg_vervollstaendigen() -> list:
    """Fehlende Schluessel EINMAL mit ihrer Vorgabe in die Datei schreiben.

    Ergaenzen heisst: beim Lesen tritt die Vorgabe ein - die Datei bleibt
    lueckenhaft, und 'fehlt' ist von 'steht auf dem Vorgabewert' nicht mehr zu
    unterscheiden. Vervollstaendigen heisst: der fehlende Schluessel wird
    geschrieben. Danach heisst 'fehlt' nie mehr 'gilt als 1'.

    Geprueft wird mit 'in', NICHT mit einer Wahrheitspruefung: ein bewusst
    geleerter Wert wuerde sonst bei jedem Lauf zurueckgeschrieben.
    """
    roh = json_lesen(DATEI_CONFIG)
    fehlten = [k for k in VORGABEN if k not in roh]
    if not fehlten:
        return []
    for k in fehlten:
        roh[k] = VORGABEN[k]
    if json_schreiben(DATEI_CONFIG, roh):
        try:
            os.chmod(DATEI_CONFIG, 0o600)
        except OSError:
            pass
        _LOG.info("Konfiguration ergaenzt: %s", ", ".join(fehlten))
    return fehlten


def verstehen_laden() -> "Verstehen":
    if Verstehen is None:
        return None
    return Verstehen(json_lesen(DATEI_SAETZE) or {"regeln": [], "ziele": {}})


def satellit_eintrag(cfg: dict, name: str) -> dict:
    for e in cfg.get("satelliten") or []:
        if isinstance(e, dict) and str(e.get("name") or e.get("host")) == name:
            return e
    return {}


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
    try:
        fassung = int(m.get("Gatewayversion", m.get("gatewayversion")))
    except (TypeError, ValueError):
        fassung = 0
    return {"gefunden": bool(m),
            "autostart": 1 if str(autostart) in ("1", "true", "True") else 0,
            "udpport": udp,
            "fassung": fassung,
            "broker": str(m.get("Brokerhost", m.get("brokerhost", ""))),
            "brokerport": str(m.get("Brokerport", m.get("brokerport", "")))}


def mqtt_senden(paare: dict, praefix: str, cfg: dict | None = None) -> None:
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
            # None heisst 'kein Wert' und wird nicht gesendet. Eine LEERE
            # Zeichenkette ist etwas anderes: sie loescht. Bis 0.10.1 wurde
            # sie mit uebersprungen - der virtuelle Eingang in Loxone behielt
            # damit den letzten Grund, und in der Visu stand 'verstanden'
            # neben 'kein Muster gefunden'. Ein blanker Wert hinter dem Thema
            # waere fuer den UDP-Eingang des Gateways nicht sauber, deshalb
            # der Strich.
            if v is None:
                continue
            if v == "":
                v = "-"
            # Auch der SCHLUESSEL wird geprueft, nicht nur der Wert: das
            # Gateway trennt Thema und Wert am Leerzeichen. Ein Thema
            # 'wohn zimmer' ergaebe das erfundene Thema '<praefix>/wohn' mit
            # dem Wert 'zimmer/aktion ein'. Abweisen und melden, nicht
            # zurechtbiegen (REGELN_1, Abschnitt 4).
            if not re.match(r"^[A-Za-z0-9_/\-]+$", str(k)):
                melde_gebremst("mqtt_thema",
                               "MQTT: das Thema %r enthaelt unerlaubte Zeichen "
                               "und wurde nicht gesendet." % k)
                continue
            nachricht = f"publish {praefix}/{k} {mqtt_wert_saeubern(v)}".encode("utf-8")
            s.sendto(nachricht, ("127.0.0.1", z["udpport"]))
            if cfg is not None:
                mitschnitt(cfg, "MQTT>", nachricht.decode("utf-8", "ignore"))
    except OSError as err:
        melde_gebremst("mqtt_senden", f"MQTT: Senden fehlgeschlagen ({err}).")
    finally:
        s.close()


def praefix_von(cfg: dict) -> str:
    return str(cfg.get("mqtt_topic") or "sprachsteuerung").strip("/") or "sprachsteuerung"


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


async def wy_senden(schreiber, ereignis, zeit: float = 10.0) -> None:
    """Wie wy_lesen, nur andersherum - und mit derselben Zeitschranke.

    async_write_event endet auf writer.drain(). Das wartet unbegrenzt,
    solange der Schreibpuffer ueber der Hochwassermarke steht: eine
    Gegenstelle, die die Verbindung annimmt und nicht mehr liest, hielte
    den Aufrufer sonst fuer immer fest - und mit ihm die Lesefrist, den
    Ping und den Wiederanlauf dieses Satelliten. Bis 0.10.1 war dies der
    einzige Wyoming-Weg ohne Schranke.
    """
    from wyoming.event import async_write_event
    await asyncio.wait_for(async_write_event(ereignis, schreiber), timeout=zeit)


async def wy_lesen(leser, zeit: float = 60.0):
    from wyoming.event import async_read_event
    return await asyncio.wait_for(async_read_event(leser), timeout=zeit)


async def dienst_befragen(host: str, port: int, zeit: float = 8.0) -> dict:
    """Was meldet ein Wyoming-Dienst ueber sich?

    Bis 0.9.11 wurde 'describe' nur an Satelliten geschickt. Damit konnte die
    Oberflaeche nicht zeigen, WELCHES Modell in einem Container wirklich
    geladen ist - die drei Modellfelder waren Freitext, und ein Vertipper fiel
    erst als Docker-Fehler auf. Gefragt wird jetzt der Dienst selbst.
    """
    from wyoming.info import Describe, Info
    try:
        leser, schreiber = await wy_verbinden(host, port, zeit)
    except (OSError, asyncio.TimeoutError) as err:
        return {"ok": 0, "fehler": fehlertext(err)}
    try:
        await wy_senden(schreiber, Describe().event())
        ende = time.monotonic() + zeit
        while time.monotonic() < ende:
            ereignis = await wy_lesen(leser, max(1.0, ende - time.monotonic()))
            if ereignis is None:
                return {"ok": 0, "fehler": "Verbindung ohne Antwort geschlossen."}
            if Info.is_type(ereignis.type):
                return {"ok": 1, "info": Info.from_event(ereignis).to_dict()}
        return {"ok": 0, "fehler": "Keine Auskunft innerhalb der Frist."}
    except (OSError, asyncio.TimeoutError, ValueError) as err:
        return {"ok": 0, "fehler": fehlertext(err)}
    finally:
        schreiber.close()


def info_namen(info: dict, art: str) -> list:
    """Aus der Wyoming-Auskunft die Namen herausziehen (Modelle, Stimmen, Weckwoerter)."""
    aus = []
    for eintrag in (info.get(art) or []):
        if not isinstance(eintrag, dict):
            continue
        for m in (eintrag.get("models") or eintrag.get("voices") or
                  eintrag.get("wake_words") or []):
            if isinstance(m, dict) and m.get("name"):
                aus.append(str(m["name"]))
    return sorted(set(aus))


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
        mitschnitt(cfg, "ASR>", "%d Bloecke, %d Byte, %d Hz"
                   % (len(rahmen), sum(len(b) for b in rahmen), rate))
        # Gesamtfrist UND Rundenobergrenze, wie in dienst_befragen(). Ohne
        # sie haelt eine Gegenstelle, die alle 179 s irgendetwas schickt,
        # diese Schleife unbegrenzt am Laufen.
        ende = time.monotonic() + 180.0
        for _ in range(2000):
            if time.monotonic() >= ende:
                return {"ok": 0, "fehler": "Spracherkennung: keine Abschrift "
                                           "innerhalb der Frist."}
            ereignis = await wy_lesen(leser, max(1.0, ende - time.monotonic()))
            if ereignis is None:
                return {"ok": 0, "fehler": "Spracherkennung hat die Verbindung geschlossen."}
            if Transcript.is_type(ereignis.type):
                text = Transcript.from_event(ereignis).text or ""
                mitschnitt(cfg, "ASR<", text)
                return {"ok": 1, "text": text.strip(),
                        "sekunden": round(time.monotonic() - t0, 2)}
        return {"ok": 0, "fehler": "Spracherkennung: zu viele Ereignisse "
                                   "ohne Abschrift."}
    except (OSError, asyncio.TimeoutError) as err:
        return {"ok": 0, "fehler": "Spracherkennung: " + fehlertext(err)}
    finally:
        schreiber.close()


async def sprachausgabe(cfg: dict, text: str, stimme: str = "") -> dict:
    """Text -> Audio ueber Piper. Rueckgabe enthaelt die PCM-Bloecke.

    'stimme' waehlt die Piper-Stimme zur Laufzeit. Bis 0.9.11 ging
    Synthesize(text=text) ohne Stimmenangabe hinaus - was gesprochen wurde,
    entschied allein die Aufrufzeile des Containers. Eine Stimme fuers ganze
    Haus, und keine zweite Sprache.
    """
    from wyoming.audio import AudioChunk, AudioStop
    from wyoming.tts import Synthesize
    t0 = time.monotonic()
    try:
        leser, schreiber = await wy_verbinden(cfg["piper_host"], int(cfg["piper_port"]))
    except (OSError, asyncio.TimeoutError) as err:
        return {"ok": 0, "fehler": "Sprachausgabe: " + fehlertext(err)}
    bloecke, rate, breite, kanaele = [], 22050, 2, 1
    try:
        synth = Synthesize(text=text)
        stimme = str(stimme or "").strip()
        if stimme:
            # SynthesizeVoice gibt es erst ab wyoming 1.4. Fehlt es, wird ohne
            # Stimmenangabe gesprochen statt der Aufruf abgebrochen - eine
            # Antwort mit der falschen Stimme ist besser als keine.
            try:
                from wyoming.tts import SynthesizeVoice
                synth.voice = SynthesizeVoice(name=stimme)
            except (ImportError, TypeError, AttributeError):
                melde_gebremst("piper_stimme",
                               "Die Piper-Stimme laesst sich mit dieser Fassung des "
                               "Pakets wyoming nicht je Ansage waehlen - es gilt die "
                               "Stimme aus der Aufrufzeile des Containers.", 86400)
        await wy_senden(schreiber, synth.event())
        mitschnitt(cfg, "TTS>", text)
        # Wie oben - und dazu eine Obergrenze fuer das, was sich hier
        # ansammelt. Ein gesprochener Antwortsatz liegt bei wenigen hundert
        # Kilobyte; 16 MB sind bei 22050 Hz und 16 Bit rund sechs Minuten.
        ende = time.monotonic() + 180.0
        gesamt = 0
        for _ in range(20000):
            if time.monotonic() >= ende:
                return {"ok": 0, "fehler": "Sprachausgabe: kein Abschluss "
                                           "innerhalb der Frist."}
            ereignis = await wy_lesen(leser, max(1.0, ende - time.monotonic()))
            if ereignis is None:
                return {"ok": 0, "fehler": "Sprachausgabe hat die Verbindung geschlossen."}
            if AudioChunk.is_type(ereignis.type):
                block = AudioChunk.from_event(ereignis)
                rate, breite, kanaele = block.rate, block.width, block.channels
                gesamt += len(block.audio)
                if gesamt > 16 * 1024 * 1024:
                    return {"ok": 0, "fehler": "Sprachausgabe: die Antwort ist "
                                               "laenger als 16 MB - abgebrochen."}
                bloecke.append(block.audio)
            elif AudioStop.is_type(ereignis.type):
                return {"ok": 1, "bloecke": bloecke, "rate": rate, "width": breite,
                        "channels": kanaele, "sekunden": round(time.monotonic() - t0, 2)}
        return {"ok": 0, "fehler": "Sprachausgabe: zu viele Ereignisse ohne "
                                   "Abschluss."}
    except (OSError, asyncio.TimeoutError) as err:
        return {"ok": 0, "fehler": "Sprachausgabe: " + fehlertext(err)}
    finally:
        schreiber.close()


def wav_bauen(bloecke: list, rate: int, breite: int, kanaele: int) -> bytes:
    """PCM-Bloecke in eine WAV-Datei fassen - fuer das Probehoeren im Browser."""
    import struct
    daten = b"".join(bloecke)
    kopf = b"RIFF" + struct.pack("<I", 36 + len(daten)) + b"WAVEfmt "
    kopf += struct.pack("<IHHIIHH", 16, 1, kanaele, rate,
                        rate * kanaele * breite, kanaele * breite, breite * 8)
    kopf += b"data" + struct.pack("<I", len(daten))
    return kopf + daten


# ---------------------------------------------------------------------------
# Wortwecker (openWakeWord)
#
# WARUM ES DIESEN ABSCHNITT SEIT 0.10.0 GIBT: bis 0.9.11 wurde der Container
# angelegt, gestartet, im Selbsttest geprueft und mit --preload-model versorgt -
# und NIE angesprochen. Wer ein Mikrofon ohne eigenen Wortwecker anschloss,
# bekam einen gruenen Haken und ein Mikrofon, das nicht reagiert.
#
# Angesprochen wird er genau dann, wenn der Satellit seine Verarbeitung bei
# 'wake' beginnen laesst - dann hat er das Weckwort NICHT selbst erkannt.
# ---------------------------------------------------------------------------
class Wortwecker:
    """Haelt eine Verbindung zum Wortwecker und meldet Treffer."""

    def __init__(self, cfg: dict) -> None:
        self.host = str(cfg.get("wake_host") or "127.0.0.1")
        self.port = int(cfg.get("wake_port") or 10400)
        self.wort = str(cfg.get("wakeword") or "").strip()
        self.leser = None
        self.schreiber = None
        # Der laufende Lesevorgang. Er wird NIE abgebrochen - warum,
        # steht bei _bereit().
        self._lesen = None

    async def oeffnen(self, rate: int) -> bool:
        from wyoming.audio import AudioStart
        from wyoming.wake import Detect
        try:
            self.leser, self.schreiber = await wy_verbinden(self.host, self.port, 5.0)
            await wy_senden(self.schreiber,
                            Detect(names=[self.wort] if self.wort else None).event())
            await wy_senden(self.schreiber,
                            AudioStart(rate=rate, width=2, channels=1).event())
            return True
        except (OSError, asyncio.TimeoutError, ImportError) as err:
            melde_gebremst("wake_offen", "Wortwecker %s:%d: %s"
                           % (self.host, self.port, fehlertext(err)), 900)
            self.leser = self.schreiber = None
            return False

    def offen(self) -> bool:
        return self.schreiber is not None

    async def _bereit(self):
        """(fertig, Ereignis) - OHNE den laufenden Lesevorgang abzubrechen.

        `async_read_event` liest ein Ereignis in bis zu DREI Zuegen:
        die Kopfzeile mit `readline()`, dann `readexactly(data_length)`,
        dann `readexactly(payload_length)`. Bis 0.10.2 stand hier

            ereignis = await wy_lesen(self.leser, 0.001)

        und `wait_for` bricht den Lesevorgang nach einer Millisekunde ab -
        auch mitten zwischen zwei Zuegen. Die Kopfzeile ist dann aus dem
        Puffer verbraucht, der Rumpf steht noch darin, und der naechste
        Lesevorgang beginnt mittendrin. `async_read_event` schluckt den
        ValueError und liefert None; `fuettern` machte daraus ein stilles
        `return False`. Das Weckwort war ab diesem Augenblick tot, das
        Mikrofon speiste weiter Audio hinein, und gemeldet wurde nichts.

        Gemessen am 03.09.2026 gegen wyoming 1.10.2, in beide Richtungen
        geeicht: Pruefung-Sprachsteuerung-0.10.3/wyoming_rahmen_messen.py.

        Deshalb laeuft der Lesevorgang durch und wird nur ANGESEHEN.
        asyncio.wait fasst den Auftrag nicht an - anders als wait_for.
        """
        from wyoming.event import async_read_event
        if self.leser is None:
            return False, None
        if self._lesen is None:
            self._lesen = asyncio.ensure_future(async_read_event(self.leser))
        if not self._lesen.done():
            await asyncio.wait({self._lesen}, timeout=0.001)
        if not self._lesen.done():
            return False, None
        aufgabe, self._lesen = self._lesen, None
        return True, aufgabe.result()

    async def fuettern(self, block: bytes, rate: int) -> bool:
        """Einen Audioblock hineingeben. True, sobald das Weckwort fiel."""
        from wyoming.audio import AudioChunk
        from wyoming.wake import Detection
        if self.schreiber is None:
            return False
        try:
            await wy_senden(self.schreiber, AudioChunk(rate=rate, width=2, channels=1,
                                                       audio=block).event())
            # Nur nachsehen, ob schon etwas dasteht - hier darf nicht gewartet
            # werden, sonst steht der Audiostrom.
            fertig, ereignis = await self._bereit()
            if not fertig:
                return False
            if ereignis is None:
                # Die Gegenstelle hat zugemacht. Bis 0.10.2 war das ein
                # stilles 'return False': das Mikrofon speiste weiter Audio
                # in eine Verbindung, auf der nie wieder ein Treffer kommen
                # konnte. Jetzt wird zugemacht und gesagt - die Schleife in
                # Satellit.lauf() baut sie beim naechsten Block neu auf.
                melde_gebremst("wake_zu",
                               "Der Wortwecker hat die Verbindung geschlossen. "
                               "Sie wird neu aufgebaut.", 900)
                await self.schliessen()
                return False
            if Detection.is_type(ereignis.type):
                return True
        except (OSError, asyncio.TimeoutError) as err:
            melde_gebremst("wake_fuettern", "Wortwecker: " + fehlertext(err), 900)
            await self.schliessen()
        return False

    async def schliessen(self) -> None:
        # HIER darf abgebrochen werden: die Verbindung geht ohnehin weg.
        if self._lesen is not None:
            self._lesen.cancel()
            self._lesen = None
        if self.schreiber is not None:
            try:
                self.schreiber.close()
            except OSError:
                pass
        self.leser = self.schreiber = None


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
                 "User-Agent": "LoxBerry-Sprachsteuerung-Plugin/0.10",
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
    try:
        # Der Aufbau gehoert IN den try: eine Adresse ohne Schema laesst
        # Request() mit ValueError abbrechen, und der stand in keinem der
        # drei except - die Ausnahme verliess _ausfuehren, nachdem ueber
        # MQTT bereits gesendet war (geschaltet, aber nichts gemeldet).
        anfrage = urllib.request.Request(voll, headers={
            "User-Agent": "LoxBerry-Sprachsteuerung-Plugin/" + FASSUNG,
            "Accept": "*/*",
            "Accept-Language": "de", "Accept-Encoding": "identity"})
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
    except ValueError as err:
        return {"ok": 0, "fehler": "Miniserver: die Adresse ist unbrauchbar ("
                                   + str(err) + ")."}
    except OSError as err:
        return {"ok": 0, "fehler": "Miniserver: " + str(err)}


# ---------------------------------------------------------------------------
# Der Lesepfad
#
# WARUM ES IHN SEIT 0.10.0 GIBT: bis 0.9.11 konnte das Plugin ausschliesslich
# schalten. Die mitgelieferte Regel 'wie warm ist es im {ziel}' trug einen
# LEEREN Antworttext - auf die Frage blieb die Anlage stumm, und zwar ohne
# Fehlermeldung. Gleichzeitig las miniserver_rufen() die Antwort des
# Miniservers bereits ein (200 Byte) und warf sie weg.
#
# Ein Ziel bekommt jetzt 'url_lesen'. Was dort geantwortet wird, steht im
# Antworttext als {istwert}.
# ---------------------------------------------------------------------------
_ZAHL_IN_TEXT = re.compile(r"-?\d+(?:[.,]\d+)?")


def istwert_lesen(url: str, ersatz: dict) -> dict:
    """Einen Zustand lesen. Rueckgabe: {'ok':1,'wert':'21,5','roh':...}"""
    if not url:
        return {"ok": -1}
    ruf = miniserver_rufen(url, ersatz)
    if ruf.get("ok") != 1:
        return ruf
    roh = str(ruf.get("text") or "").strip()
    wert = roh
    # Der Miniserver antwortet auf /dev/sps/io/<x>/state mit einem XML-Rumpf,
    # in dem der Wert im Attribut value steht. Alles andere wird als Text
    # genommen - was dort steht, weiss das Geraet besser als wir.
    t = re.search(r'value="([^"]*)"', roh)
    if t:
        wert = t.group(1).strip()
    elif roh.startswith("{") or roh.startswith("["):
        try:
            d = json.loads(roh)
            if isinstance(d, dict):
                for schluessel in ("value", "wert", "state", "temperatur"):
                    if schluessel in d:
                        wert = str(d[schluessel])
                        break
        except ValueError:
            pass
    else:
        t = _ZAHL_IN_TEXT.search(roh)
        if t:
            wert = t.group(0)
    return {"ok": 1, "wert": wert.replace(".", ","), "roh": roh[:120]}


# ---------------------------------------------------------------------------
# Ruhezeit
#
# WARUM DAS HIER STEHT UND NICHT ALS WUNSCH: das Ansageverfahren dieses
# Plugins ist die feldgleiche Uebertragung von awm_tts_url() aus
# LoxBerry-Plugin-AWM-Abfuhr. Uebernommen wurde der Adressbau - NICHT die
# Wache davor. AWM prueft vor jeder Ansage awm_ruhe_aktiv(). Ohne sie kann
# jeder Loxone-Baustein um drei Uhr nachts das Haus reden lassen.
# ---------------------------------------------------------------------------
def _minuten(hhmm: str) -> int:
    t = re.fullmatch(r"(\d{1,2}):(\d{2})", str(hhmm or ""))
    if not t:
        return -1
    return min(23, int(t.group(1))) * 60 + min(59, int(t.group(2)))


def ruhe_aktiv(cfg: dict, jetzt=None) -> tuple[bool, str]:
    """(True, Grund), wenn gerade nicht angesagt werden darf.

    Zwei Quellen: das eingestellte Nachtfenster - und die Stilllegung, die
    Loxone ueber den Endpunkt umlegen kann. Der Merker liegt unter data/, weil
    der unangemeldete Endpunkt nichts schreiben darf; umgelegt wird er vom
    Dienst ueber die Warteschlange.
    """
    if json_lesen(DATEI_RUHE).get("still"):
        return True, "von Loxone stillgelegt"
    r = cfg.get("ruhe") or {}
    if not r.get("ein"):
        return False, ""
    von, bis = _minuten(r.get("von")), _minuten(r.get("bis"))
    if von < 0 or bis < 0 or von == bis:
        return False, ""
    t = time.localtime(jetzt) if jetzt else time.localtime()
    nun = t.tm_hour * 60 + t.tm_min
    # Das Fenster laeuft ueber Mitternacht, wenn 'von' spaeter liegt als 'bis'.
    drin = (von <= nun < bis) if von < bis else (nun >= von or nun < bis)
    if drin:
        return True, "Ruhezeit %s bis %s" % (r.get("von"), r.get("bis"))
    return False, ""


# ---------------------------------------------------------------------------
# Wiederholungsbremse fuer Ansagen
#
# Ein Loxone-Baustein, der in einer Schleife haengt, erzeugte bis 0.9.11
# beliebig viele Ansagen hintereinander. Die einzige Grenze war die
# Textlaenge.
# ---------------------------------------------------------------------------
def ansage_erlaubt(cfg: dict, jetzt=None) -> tuple[bool, str]:
    jetzt = jetzt or time.time()
    abstand = int(cfg.get("ansage_abstand_s") or 0)
    je_tag = int(cfg.get("ansage_je_tag") or 0)
    if abstand <= 0 and je_tag <= 0:
        return True, ""
    d = json_lesen(DATEI_ANSAGEN)
    liste = [float(x) for x in (d.get("zeiten") or []) if isinstance(x, (int, float))]
    if abstand > 0 and liste and jetzt - max(liste) < abstand:
        return False, ("Mindestabstand %d s - die letzte Ansage ist %d s her"
                       % (abstand, int(jetzt - max(liste))))
    if je_tag > 0:
        im_fenster = [x for x in liste if jetzt - x < 86400]
        if len(im_fenster) >= je_tag:
            return False, "Tagesgrenze von %d Ansagen erreicht" % je_tag
    return True, ""


def ansage_vermerken(jetzt=None) -> None:
    jetzt = jetzt or time.time()
    d = json_lesen(DATEI_ANSAGEN)
    liste = [float(x) for x in (d.get("zeiten") or []) if isinstance(x, (int, float))]
    liste.append(jetzt)
    liste = [x for x in liste if jetzt - x < 86400][-500:]
    json_schreiben(DATEI_ANSAGEN, {"zeiten": liste})


# ---------------------------------------------------------------------------
# Der Rueckweg nach Loxone
#
#   MQTT   <praefix>/antwort   der fertige Satz -> Virtueller Texteingang
#          <praefix>/ok        1 verstanden, 0 nicht
#          <praefix>/grund     woran es lag, wenn nicht
#          <praefix>/ansage    der Text fuer den Textgenerator (Audioserver)
#   Audio  Ansage ueber Music Server / Audioserver
# ---------------------------------------------------------------------------
def loxone_tts_url(tts: dict, text: str, zonen: str = ""):
    """Adresse der Ansage bauen.

    None -> Modus 'audioserver'. Der originale Loxone Audioserver kennt keinen
            TTS-Aufruf ueber HTTP; das laeuft ueber Loxone Config
            (Textgenerator am TTS-Eingang) und nicht ueber uns.
    ''   -> es fehlt die Adresse des Servers.

    'zonen' ueberschreibt die eingestellten Zonen fuer diese eine Ansage -
    damit die Antwort in dem Raum ankommt, in dem gefragt wurde.
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
    zonenfeld = str(zonen or tts.get("zones") or "")

    if modus == "musicserver":
        # Zonenliste normalisieren: '2,4,6' plus Lautstaerkefeld ergibt
        # '2~8,4~8,6~8'. Eine im Feld bereits angegebene Lautstaerke
        # ('4~15') hat Vorrang und bleibt stehen.
        zonenliste = []
        for z in zonenfeld.split(","):
            z = z.strip()
            if z == "":
                continue
            zonenliste.append(z if "~" in z else "%s~%d" % (z, laut))
        zonenstr = ",".join(zonenliste) if zonenliste else "1~%d" % laut
        return "http://%s:%d/audio/grouped/tts/%s/%s" % (
            ip, port, zonenstr,
            urllib.parse.quote(sprache + "|" + text, safe=""))

    # ms4h und custom: Vorlage mit Platzhaltern. Die Reihenfolge ist Absicht -
    # {text} kommt zuletzt, damit ein Platzhaltername, der zufaellig im
    # gesprochenen Satz steht, nicht selbst noch ersetzt wird.
    vorlage = str(tts.get("template") or "").strip() or TTS_VORLAGE_MS4H
    for platzhalter, wert in (("{ip}", ip),
                              ("{port}", str(port)),
                              ("{zones}", zonenfeld),
                              ("{vol}", str(laut)),
                              ("{lang}", sprache),
                              ("{text}", urllib.parse.quote(text, safe=""))):
        vorlage = vorlage.replace(platzhalter, wert)
    return vorlage


def loxone_ansagen(cfg: dict, text: str, zonen: str = "") -> dict:
    """Die Antwort ueber die Loxone-Audioausgabe ansagen.

    Ruhezeit und Wiederholungsbremse werden HIER geprueft und nicht beim
    Aufrufer: es gibt drei Aufrufer (Satzweg, Warteschlange, Timer), und eine
    Wache, die an drei Stellen steht, fehlt beim vierten Aufrufer.
    """
    dringend = bool(cfg.get("_dringend"))
    if not dringend:
        still, grund = ruhe_aktiv(cfg)
        if still:
            melde_gebremst("tts_ruhe", "Ansage unterdrueckt: " + grund, 3600)
            return {"ok": 0, "grund": "ruhe", "meldung": grund}
        erlaubt, grund = ansage_erlaubt(cfg)
        if not erlaubt:
            melde_gebremst("tts_bremse", "Ansage unterdrueckt: " + grund, 900)
            return {"ok": 0, "grund": "bremse", "meldung": grund}

    url = loxone_tts_url(cfg.get("tts") or {}, text, zonen)
    if url is None:
        # Modus 'Originaler Loxone Audioserver': es gibt keinen Aufruf ueber
        # das Netz. Bis 0.9.11 endete der Weg hier - die Auswahl war eine
        # Sackgasse. Jetzt geht der Text ueber ein eigenes Thema hinaus, das
        # in Loxone Config am Textgenerator haengt.
        if cfg.get("mqtt_ein"):
            mqtt_senden({"ansage": text}, praefix_von(cfg), cfg)
            ansage_vermerken()
            return {"ok": 1, "grund": "audioserver_mqtt"}
        melde_gebremst("tts_audioserver",
                       "Ansage: Modus 'Originaler Loxone Audioserver' und MQTT "
                       "abgeschaltet - der Text erreicht den Textgenerator nicht.",
                       3600)
        return {"ok": 0, "grund": "audioserver"}
    if url == "":
        melde_gebremst("tts_keine_adresse",
                       "Ansage uebersprungen: fuer die Loxone-Audioausgabe ist "
                       "keine Adresse eingetragen.")
        return {"ok": 0, "grund": "keine_adresse"}
    try:
        mitschnitt(cfg, "TTS-URL>", url)
        with urllib.request.urlopen(url, timeout=10) as antwort:
            antwort.read(200)
    except (urllib.error.URLError, OSError) as err:
        melde_gebremst("tts_fehler", "Ansage fehlgeschlagen: " + fehlertext(err))
        melden(3, "Die Ansage ueber die Loxone-Audioausgabe schlaegt fehl: "
                  + fehlertext(err), "tts")
        return {"ok": 0, "fehler": fehlertext(err)}
    ansage_vermerken()
    _LOG.info("Ansage gesendet: %r", text)
    return {"ok": 1}


def antwort_ausgeben(cfg: dict, erg: dict) -> None:
    """Antworttext nach Loxone - als MQTT-Text und wahlweise als Ansage.

    Laeuft fuer JEDEN Satz, auch fuer einen nicht verstandenen: gerade dann
    will man in der Visu lesen koennen, woran es lag.
    """
    text = str(erg.get("antwort") or "").strip()
    if cfg.get("mqtt_ein"):
        praefix = praefix_von(cfg)
        # Bis 0.9.11 gingen nur 'antwort' und 'ok' hinaus. In der Visu stand
        # damit 'Das habe ich nicht verstanden.' - aber nicht, ob das Muster
        # fehlte, das Ziel unbekannt war oder ein Container schweigt. Das ist
        # der Unterschied zwischen 'Alias nachtragen' und 'Container starten'.
        mqtt_senden({"antwort": text,
                     "ok": int(erg.get("ok") or 0),
                     "grund": str(erg.get("grund") or ""),
                     "quelle": str(erg.get("quelle") or ""),
                     "mikrofon": str(erg.get("mikrofon") or "")},
                    praefix, cfg)
    if text == "":
        return
    if str(cfg.get("antwortweg") or "beide") in ("loxone", "beide"):
        loxone_ansagen(cfg, text, str(erg.get("zone") or ""))


# Genau EIN Satz zur Zeit - siehe satz_im_faden().
_SATZ_SPERRE = None


async def satz_im_faden(*args, **kwargs) -> dict:
    """satz_verarbeiten in einem Arbeitsfaden. Die Schleife bleibt frei.

    WARUM: die Kette unter satz_verarbeiten ist restlos synchron - mit
    `ast` nachgemessen ueber 28 erreichte Funktionen, kein einziges
    `await`. Darin stecken vier Netzabrufe mit zusammen bis zu rund
    153 Sekunden Zeitschranke: llm_fragen (120), miniserver_rufen (8),
    loxone_ansagen (10) und melden (15, ueber ein PHP-Zwischenstueck).
    Bis 0.10.2 wurde das aus `async def` heraus gerufen: solange ein
    Satz lief, wurde KEIN anderes Mikrofon bedient, keine Warteschlange
    gelesen und kein Herzschlag geschickt. Die Lesefrist der uebrigen
    Satelliten steht auf 30 s - deren Verbindungen waeren danach
    abgerissen worden, und der Dienst haette es als Netzstoerung gedeutet.

    WARUM MIT SPERRE: sie haelt die Reihenfolge von vorher. Bisher lief
    genau ein Satz zur Zeit, weil die Schleife nicht weiterkam; ohne
    Sperre wuerden jetzt mehrere Faeden gleichzeitig auf _KONTEXT,
    _SATZSTAND und die Zaehler zugreifen. Nebenlaeufigkeit, die es nie
    gab, waere ein zweiter Umbau und ein zweites Risiko - und dieser
    Befund verlangt sie nicht.

    asyncio.to_thread gibt es ab Python 3.9 (Debian 11, also LB_MINIMUM
    3.0.0). Der Rueckfall darunter kostet nichts.
    """
    global _SATZ_SPERRE
    if _SATZ_SPERRE is None:
        _SATZ_SPERRE = asyncio.Lock()
    async with _SATZ_SPERRE:
        if hasattr(asyncio, "to_thread"):
            return await asyncio.to_thread(satz_verarbeiten, *args, **kwargs)
        schleife = asyncio.get_event_loop()
        return await schleife.run_in_executor(
            None, functools.partial(satz_verarbeiten, *args, **kwargs))


def satz_verarbeiten(satz: str, cfg: dict, v, mikrofon: str = "",
                     raum: str = "", zone: str = "", trocken: bool = False) -> dict:
    """Satz verarbeiten und die Antwort nach Loxone geben.

    Die Trennung in Huelle und Kern hat einen Grund: der Kern verlaesst sich an
    mehreren Stellen vorzeitig - unbekanntes Ziel, Sprachmodell versagt, nichts
    verstanden. Stuende die Ausgabe im Kern, muesste sie an jeder dieser
    Stellen wiederholt werden, und der naechste neue Rueckgabepfad wuerde sie
    vergessen.

    trocken=True deutet den Satz und schaltet NICHT. Es ist derselbe Kern -
    ein Trockenlauf, der einen anderen Weg nimmt, prueft den anderen Weg.
    """
    erg = satz_kern(satz, cfg, v, mikrofon, raum, zone, trocken)
    if trocken:
        return erg
    try:
        antwort_ausgeben(cfg, erg)
    except Exception as err:  # noqa: BLE001
        # Eine misslungene Ansage darf den Befehl nicht nachtraeglich
        # scheitern lassen: geschaltet ist zu diesem Zeitpunkt bereits.
        melde_gebremst("antwortweg", "Antwortweg: " + fehlertext(err))
    return erg


def _abschluss(erg: dict, cfg: dict, trocken: bool) -> dict:
    if not trocken:
        verlauf_anhaengen(dict(erg, ts=int(time.time())), int(cfg["verlauf_zeilen"]))
    return erg


def satz_kern(satz: str, cfg: dict, v, mikrofon: str = "", raum: str = "",
              zone: str = "", trocken: bool = False) -> dict:
    """Der Kern: Satz -> Absicht -> Tat -> Antworttext."""
    beginn = time.monotonic()
    grunddaten = {"satz": satz, "mikrofon": mikrofon, "zone": zone,
                  "trocken": 1 if trocken else 0}

    # ---- Offene Rueckfrage? ----
    offen = _OFFEN.get(mikrofon or "-")
    if offen and time.time() - offen["ts"] <= int(cfg.get("bestaetigung_s") or 0):
        eingeebnet = einebnen(satz)
        if eingeebnet in ("ja", "ja bitte", "bestaetige", "bestaetigt", "mach das",
                          "jawohl", "ok", "okay"):
            _OFFEN.pop(mikrofon or "-", None)
            return _ausfuehren(dict(offen["erg"], bestaetigt=1), cfg, beginn, trocken)
        if eingeebnet in ("nein", "nein danke", "abbrechen", "stopp", "stop", "lass"):
            _OFFEN.pop(mikrofon or "-", None)
            erg = dict(grunddaten, ok=1, quelle="rueckfrage", absicht="abbruch",
                       aktion="", grund="abgebrochen",
                       antwort="Gut, ich lasse es.")
            return _abschluss(erg, cfg, trocken)

    # ---- Kontext: was war zuletzt gemeint? ----
    vorgabe = raum
    kontext = _KONTEXT.get(mikrofon or "-")
    kontext_s = int(cfg.get("kontext_s") or 0)
    if kontext and kontext_s > 0 and time.time() - kontext["ts"] <= kontext_s:
        vorgabe = kontext.get("ziel") or raum

    erkannt = v.erkennen(satz, vorgabe) if v is not None else {"ok": 0, "grund": "keine_regeln"}
    quelle = "muster"

    if not erkannt.get("ok"):
        grund = erkannt.get("grund")
        if grund in ("ziel_unbekannt", "vorgabeziel_unbekannt", "ziel_fehlt",
                     "dauer_unklar", "wert_unklar"):
            texte = {
                "ziel_unbekannt": "Ich kenne kein Geraet mit der Bezeichnung %s."
                                  % erkannt.get("gesucht", ""),
                "vorgabeziel_unbekannt":
                    "Fuer dieses Mikrofon ist der Raum %s eingetragen, den es in "
                    "der Zielliste nicht gibt." % erkannt.get("gesucht", ""),
                "ziel_fehlt": "Welches Geraet meinst du?",
                "dauer_unklar": "Mit der Zeitangabe %s kann ich nichts anfangen."
                                % erkannt.get("gesucht", ""),
                "wert_unklar": "Die Zahl %s verstehe ich nicht."
                               % erkannt.get("gesucht", ""),
            }
            erg = dict(grunddaten, ok=0, quelle="muster", grund=grund,
                       antwort=texte[grund], bekannt=erkannt.get("bekannt", []))
            return _abschluss(erg, cfg, trocken)
        if cfg.get("llm_ein") and grund in ("kein_muster", "keine_regeln"):
            ziele = [z["name"] for z in (v.ziele.values() if v else [])]
            vom_modell = llm_fragen(cfg, satz, ziele)
            if not vom_modell.get("ok"):
                erg = dict(grunddaten, ok=0, quelle="llm", grund="llm_fehler",
                           antwort="Das habe ich nicht verstanden.",
                           fehler=vom_modell.get("fehler"))
                melden(3, "Das Sprachmodell antwortet nicht: %s"
                          % vom_modell.get("fehler"), "llm")
                return _abschluss(erg, cfg, trocken)
            if str(vom_modell.get("absicht") or "unbekannt") == "unbekannt":
                erg = dict(grunddaten, ok=0, quelle="llm", grund="unbekannt",
                           antwort=str(vom_modell.get("antwort")
                                       or "Das habe ich nicht verstanden."))
                return _abschluss(erg, cfg, trocken)
            # Das Modell nennt ein Ziel im Klartext - das wird gegen die
            # bekannte Liste geprueft und NICHT einfach uebernommen.
            ziel = v.ziel_finden(str(vom_modell.get("ziel") or "")) if v else None
            erkannt = {"ok": 1,
                       "absicht": str(vom_modell.get("absicht") or ""),
                       "aktion": str(vom_modell.get("aktion") or ""),
                       "wert": vom_modell.get("wert"),
                       "dauer_s": None,
                       "ziel": ziel["schluessel"] if ziel else None,
                       "zielname": ziel["name"] if ziel else "",
                       "thema": ziel["thema"] if ziel else "",
                       "url": ziel["url"] if ziel else "",
                       "url_lesen": ziel["url_lesen"] if ziel else "",
                       "einheit": ziel["einheit"] if ziel else "",
                       "bestaetigen": ziel["bestaetigen"] if ziel else 0,
                       "antwort": str(vom_modell.get("antwort") or ""),
                       "antwort_vorlage": str(vom_modell.get("antwort") or ""),
                       "satz": satz}
            quelle = "llm"
            if ziel is None and erkannt["absicht"] in ("schalten", "dimmen"):
                erg = dict(grunddaten, ok=0, quelle="llm", grund="ziel_unbekannt",
                           antwort="Ich weiss nicht, welches Geraet gemeint ist.")
                return _abschluss(erg, cfg, trocken)
        else:
            erg = dict(grunddaten, ok=0, quelle="keine", grund=grund,
                       antwort="Das habe ich nicht verstanden.")
            return _abschluss(erg, cfg, trocken)

    erkannt = dict(erkannt, quelle=quelle, mikrofon=mikrofon, zone=zone)

    # ---- Heikles Ziel: erst fragen ----
    if erkannt.get("bestaetigen") and not trocken \
            and int(cfg.get("bestaetigung_s") or 0) > 0:
        _OFFEN[mikrofon or "-"] = {"erg": erkannt, "ts": time.time()}
        erg = dict(grunddaten, ok=1, quelle=quelle, grund="rueckfrage",
                   absicht=erkannt["absicht"], aktion=erkannt["aktion"],
                   ziel=erkannt.get("ziel"), zielname=erkannt.get("zielname", ""),
                   antwort="Soll ich %s wirklich schalten?"
                           % (erkannt.get("zielname") or "das"))
        return _abschluss(erg, cfg, trocken)

    return _ausfuehren(erkannt, cfg, beginn, trocken)


def _ausfuehren(erkannt: dict, cfg: dict, beginn: float, trocken: bool) -> dict:
    """Die Tat: MQTT, wahlweise der unmittelbare Aufruf, wahlweise ein Timer."""
    satz = str(erkannt.get("satz") or "")
    mikrofon = str(erkannt.get("mikrofon") or "")
    quelle = str(erkannt.get("quelle") or "muster")

    # ---- Verzoegerter Befehl ----
    if erkannt.get("dauer_s"):
        if not trocken:
            timer_anlegen(erkannt, int(erkannt["dauer_s"]))
        erg = {"ok": 1, "quelle": quelle, "satz": satz, "mikrofon": mikrofon,
               "zone": erkannt.get("zone", ""), "grund": "vorgemerkt",
               "absicht": erkannt["absicht"], "aktion": erkannt["aktion"],
               "ziel": erkannt.get("ziel"), "zielname": erkannt.get("zielname", ""),
               "wert": erkannt.get("wert"), "thema": erkannt.get("thema", ""),
               "dauer_s": erkannt.get("dauer_s"),
               "antwort": erkannt.get("antwort") or "",
               "trocken": 1 if trocken else 0,
               "sekunden": round(time.monotonic() - beginn, 2)}
        return _abschluss(erg, cfg, trocken)

    paare = {}
    if cfg.get("mqtt_ein"):
        praefix = praefix_von(cfg)
        paare = {
            "satz": satz.replace(" ", "_"),
            "absicht": erkannt["absicht"],
            "aktion": erkannt["aktion"],
            "ziel": erkannt.get("ziel") or "",
            "wert": "" if erkannt.get("wert") is None else erkannt["wert"],
            "einheit": erkannt.get("einheit") or "",
            "quelle": quelle,
            "mikrofon": mikrofon,
            "zeit": int(time.time()),
        }
        if erkannt.get("thema"):
            # Zusaetzlich unter dem Thema des Ziels: so kann ein virtueller
            # Eingang in Loxone genau an einem Thema haengen.
            paare[erkannt["thema"] + "/aktion"] = erkannt["aktion"]
            if erkannt.get("wert") is not None:
                paare[erkannt["thema"] + "/wert"] = erkannt["wert"]
        if not trocken:
            mqtt_senden(paare, praefix, cfg)

    ruf = {"ok": -1}
    if not trocken:
        ruf = miniserver_rufen(erkannt.get("url") or str(cfg.get("miniserver_url") or ""),
                               {"ziel": erkannt.get("ziel") or "",
                                "aktion": erkannt.get("aktion") or "",
                                "wert": erkannt.get("wert")})
        if ruf.get("ok") == 0:
            _LOG.warning("Miniserver-Aufruf fehlgeschlagen: %s", ruf.get("fehler"))
            melden(3, "Der unmittelbare Miniserver-Aufruf schlaegt fehl: %s"
                      % ruf.get("fehler"), "miniserver")

    # ---- Ist-Wert lesen, wenn der Antworttext ihn braucht ----
    istwert = ""
    vorlage = str(erkannt.get("antwort_vorlage") or "")
    if "{istwert}" in vorlage:
        gelesen = istwert_lesen(erkannt.get("url_lesen") or "",
                                {"ziel": erkannt.get("ziel") or "",
                                 "aktion": erkannt.get("aktion") or ""})
        if gelesen.get("ok") == 1:
            istwert = str(gelesen.get("wert") or "")
        else:
            erg = {"ok": 0, "quelle": quelle, "satz": satz, "mikrofon": mikrofon,
                   "zone": erkannt.get("zone", ""), "grund": "istwert_fehlt",
                   "ziel": erkannt.get("ziel"), "zielname": erkannt.get("zielname", ""),
                   "absicht": erkannt.get("absicht", ""), "aktion": erkannt.get("aktion", ""),
                   "antwort": "Ich kann den Wert von %s gerade nicht lesen."
                              % (erkannt.get("zielname") or "diesem Geraet"),
                   "fehler": gelesen.get("fehler", ""),
                   "trocken": 1 if trocken else 0,
                   "sekunden": round(time.monotonic() - beginn, 2)}
            return _abschluss(erg, cfg, trocken)

    antwort = erkannt.get("antwort") or ""
    if vorlage and Verstehen is not None:
        antwort = Verstehen.antwort_fuellen(vorlage, erkannt, istwert)

    erg = {
        "ok": 1, "quelle": quelle, "satz": satz, "mikrofon": mikrofon,
        "zone": erkannt.get("zone", ""),
        "absicht": erkannt["absicht"], "aktion": erkannt["aktion"],
        "ziel": erkannt.get("ziel"), "zielname": erkannt.get("zielname", ""),
        "wert": erkannt.get("wert"), "thema": erkannt.get("thema", ""),
        "einheit": erkannt.get("einheit", ""),
        "istwert": istwert,
        "antwort": antwort,
        "miniserver": ruf,
        "themen": sorted(paare.keys()),
        "trocken": 1 if trocken else 0,
        "sekunden": round(time.monotonic() - beginn, 2),
    }
    if not trocken and erkannt.get("ziel"):
        _KONTEXT[mikrofon or "-"] = {"ziel": erkannt["ziel"], "ts": time.time()}
    _abschluss(erg, cfg, trocken)
    if not trocken:
        _LOG.info("Satz %r [%s] -> %s/%s ziel=%s (%s, %.2f s)", satz,
                  mikrofon or "-", erg["absicht"], erg["aktion"], erg["ziel"],
                  quelle, erg["sekunden"])
    return erg


# ---------------------------------------------------------------------------
# Verzoegerte Befehle
# ---------------------------------------------------------------------------
def timer_anlegen(erkannt: dict, sekunden: int) -> None:
    ORDNER_TIMER.mkdir(parents=True, exist_ok=True)
    kennung = "%d_%s" % (int(time.time() * 1000), os.urandom(3).hex())
    json_schreiben(ORDNER_TIMER / (kennung + ".json"),
                   {"faellig": int(time.time()) + int(sekunden),
                    "angelegt": int(time.time()),
                    "erkannt": dict(erkannt, dauer_s=None)})
    _LOG.info("Vorgemerkt: %s/%s an %s in %d s", erkannt.get("absicht"),
              erkannt.get("aktion"), erkannt.get("ziel"), sekunden)


def timer_faellig(cfg: dict) -> int:
    """Faellige Befehle ausfuehren. Rueckgabe: wie viele."""
    if not ORDNER_TIMER.is_dir():
        return 0
    jetzt = time.time()
    anzahl = 0
    for datei in sorted(ORDNER_TIMER.glob("*.json")):
        d = json_lesen(datei)
        try:
            faellig = float(d.get("faellig") or 0)
        except (TypeError, ValueError):
            faellig = 0
        if faellig <= 0:
            try:
                datei.unlink()
            except OSError:
                pass
            continue
        if faellig > jetzt:
            continue
        try:
            datei.unlink()
        except OSError:
            pass
        erkannt = d.get("erkannt") or {}
        if not isinstance(erkannt, dict):
            continue
        try:
            erg = _ausfuehren(dict(erkannt, dauer_s=None), cfg, time.monotonic(), False)
            antwort_ausgeben(cfg, erg)
            anzahl += 1
        except Exception as err:  # noqa: BLE001
            _LOG.error("Vorgemerkter Befehl: %s", fehlertext(err))
    return anzahl


def timer_liste() -> list:
    aus = []
    if not ORDNER_TIMER.is_dir():
        return aus
    for datei in sorted(ORDNER_TIMER.glob("*.json")):
        d = json_lesen(datei)
        e = d.get("erkannt") or {}
        aus.append({"faellig": int(d.get("faellig") or 0),
                    "ziel": e.get("ziel"), "zielname": e.get("zielname"),
                    "aktion": e.get("aktion")})
    return aus


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
        self.raum = str(eintrag.get("raum") or "")
        self.zone = str(eintrag.get("zone") or "")
        self.cfg = cfg
        self.v = v
        self.info = {}
        self.zustand = "getrennt"
        self.letzter_satz = ""
        self.letzte_meldung = ""
        self.seit = 0.0
        # Der Schreibkanal der offenen Verbindung. Ohne ihn kann eine Ansage
        # aus der Warteschlange den Lautsprecher dieses Mikrofons nicht
        # erreichen - genau daran scheiterte 'aktion=sprechen' bis 0.9.11.
        self.schreiber = None

    def abbild(self) -> dict:
        return {"name": self.name, "art": "wyoming", "host": self.host,
                "port": self.port, "zustand": self.zustand,
                "raum": self.raum, "zone": self.zone,
                "letzter_satz": self.letzter_satz,
                "letzte_meldung": self.letzte_meldung,
                "gemeldet": bool(self.info)}

    async def lauf(self) -> None:
        from wyoming.audio import AudioChunk, AudioStart, AudioStop
        from wyoming.info import Describe, Info
        from wyoming.satellite import RunSatellite
        from wyoming.pipeline import RunPipeline
        try:
            from wyoming.ping import Ping, Pong
        except ImportError:                       # aeltere Fassungen des Pakets
            Ping = Pong = None

        leser, schreiber = await wy_verbinden(self.host, self.port)
        self.zustand = "verbunden"
        self.seit = time.time()
        self.schreiber = schreiber
        wecker = None
        try:
            await wy_senden(schreiber, Describe().event())
            await wy_senden(schreiber, RunSatellite().event())

            rahmen: list = []
            rate = 16000
            sammelt = False
            wartet_auf_weckwort = False
            ohne_regung = 0
            # Bis 0.9.11 stand hier eine Lesefrist von 3600 Sekunden. Bricht
            # ein WLAN-Mikrofon weg, ohne dass TCP es meldet, zeigte die
            # Oberflaeche bis zu einer STUNDE 'verbunden', und der vorhandene
            # Wiederanlauf griff so lange nicht.
            frist = 30.0
            while _LAUF:
                try:
                    ereignis = await wy_lesen(leser, frist)
                except asyncio.TimeoutError:
                    if Ping is None:
                        continue          # ohne Ping bleibt es beim Warten
                    ohne_regung += 1
                    if ohne_regung >= 3:
                        self.letzte_meldung = ("Keine Antwort auf drei Lebenszeichen - "
                                               "Verbindung wird neu aufgebaut.")
                        break
                    await wy_senden(schreiber, Ping().event())
                    continue
                if ereignis is None:
                    break
                ohne_regung = 0
                typ = ereignis.type
                if Pong is not None and Pong.is_type(typ):
                    continue
                if Info.is_type(typ):
                    try:
                        self.info = Info.from_event(ereignis).to_dict()
                    except Exception:  # noqa: BLE001 - Info ist nur Beiwerk
                        self.info = {}
                    _LOG.info("Satellit %s gemeldet.", self.name)
                elif RunPipeline.is_type(typ):
                    # Der Satellit will eine Verarbeitung. Beginnt sie bei
                    # 'wake', hat er das Weckwort NICHT selbst erkannt - dann
                    # muss der Wortwecker ran.
                    p = RunPipeline.from_event(ereignis)
                    beginn = str(getattr(p, "start_stage", "") or "")
                    wartet_auf_weckwort = beginn == "wake"
                    rahmen, sammelt = [], not wartet_auf_weckwort
                    self.zustand = "hoert" if sammelt else "wartet_weckwort"
                    # Die Verbindung zum Wortwecker wird ERST beim ersten
                    # Audioblock geoeffnet: vorher steht die Abtastrate nicht
                    # fest, und der Wortwecker bekommt sie im audio-start.
                    wecker = None
                    _LOG.info("Satellit %s: Verarbeitung angefordert (ab %s).",
                              self.name, beginn or "asr")
                elif AudioStart.is_type(typ):
                    start = AudioStart.from_event(ereignis)
                    rate = start.rate
                    if not wartet_auf_weckwort:
                        rahmen, sammelt = [], True
                        self.zustand = "hoert"
                elif AudioChunk.is_type(typ):
                    block = AudioChunk.from_event(ereignis)
                    rate = block.rate
                    # 'oder nicht mehr offen': hat der Wortwecker die
                    # Verbindung geschlossen, wird sie hier neu aufgebaut,
                    # statt bis zum Ende der Aufnahme wirkungslos zu bleiben.
                    if wartet_auf_weckwort and (wecker is None or not wecker.offen()):
                        wecker = Wortwecker(self.cfg)
                        if not await wecker.oeffnen(rate):
                            # Ohne Wortwecker lieber alles aufnehmen als gar
                            # nichts - und es sagen. Ein Mikrofon, das stumm
                            # bleibt, weil ein Container fehlt, waere die
                            # schlechtere Antwort.
                            melde_gebremst(
                                "wake_aus_" + self.name,
                                "Satellit %s verlangt den Wortwecker, der aber nicht "
                                "antwortet. Es wird ohne Weckwort aufgenommen."
                                % self.name, 900)
                            melden(3, "Der Wortwecker antwortet nicht - das Mikrofon %s "
                                      "nimmt vorlaeufig ohne Weckwort auf." % self.name,
                                   "wake")
                            wecker = None
                            wartet_auf_weckwort = False
                            sammelt = True
                            self.zustand = "hoert"
                    if wartet_auf_weckwort and wecker is not None:
                        if await wecker.fuettern(block.audio, rate):
                            _LOG.info("Satellit %s: Weckwort erkannt.", self.name)
                            await wecker.schliessen()
                            wecker = None
                            wartet_auf_weckwort = False
                            rahmen, sammelt = [], True
                            self.zustand = "hoert"
                        continue
                    if sammelt:
                        rahmen.append(block.audio)
                        # Notbremse: mehr als 30 Sekunden nimmt niemand am Stueck auf.
                        if len(rahmen) * len(block.audio) > rate * 2 * 30:
                            sammelt = False
                            melde_gebremst("zu_lang_" + self.name,
                                           f"Satellit {self.name}: mehr als 30 s Audio am "
                                           "Stueck - abgeschnitten. Erkennt der Satellit das "
                                           "Ende des Sprechens nicht?")
                elif AudioStop.is_type(typ):
                    if wecker is not None:
                        await wecker.schliessen()
                        wecker = None
                    wartet_auf_weckwort = False
                    if rahmen:
                        await self.verarbeiten(schreiber, rahmen, rate)
                    rahmen, sammelt = [], False
                    self.zustand = "wartet"
        finally:
            if wecker is not None:
                await wecker.schliessen()
            self.zustand = "getrennt"
            self.schreiber = None
            schreiber.close()

    async def verarbeiten(self, schreiber, rahmen: list, rate: int) -> None:
        from wyoming.audio import AudioChunk, AudioStart, AudioStop

        cfg = config()
        # Raum und Zone stehen in der Konfiguration und koennen sich geaendert
        # haben, seit dieser Satellit gebaut wurde.
        eintrag = satellit_eintrag(cfg, self.name)
        if eintrag:
            self.raum = str(eintrag.get("raum") or "")
            self.zone = str(eintrag.get("zone") or "")

        erkannt = await spracherkennung(cfg, rahmen, rate)
        if not erkannt.get("ok"):
            self.letzte_meldung = erkannt.get("fehler", "")
            _LOG.error("Satellit %s: %s", self.name, self.letzte_meldung)
            melden(3, "Die Spracherkennung antwortet nicht: " + self.letzte_meldung,
                   "whisper")
            return
        satz = erkannt["text"]
        self.letzter_satz = satz
        if not satz:
            self.letzte_meldung = "Es wurde nichts verstanden (leerer Text)."
            return

        erg = await satz_im_faden(satz, cfg, self.v, self.name, self.raum, self.zone)
        self.letzte_meldung = erg.get("antwort", "")

        # Ab 0.9.1 entscheidet zusaetzlich der Antwortweg. Bei 'loxone' bleibt
        # der Satellit still, weil die Ansage bereits ueber den Music Server
        # gelaufen ist - sonst hoerte man sie im selben Raum zweimal.
        if (not cfg.get("antwort_sprechen") or not erg.get("antwort")
                or str(cfg.get("antwortweg") or "beide") == "loxone"):
            return
        # Die Ruhezeit gilt auch fuer den Lautsprecher des Mikrofons - er steht
        # in aller Regel im selben Zimmer wie ein Bett.
        still, grund = ruhe_aktiv(cfg)
        if still:
            melde_gebremst("sat_ruhe", "Antwort am Mikrofon unterdrueckt: " + grund, 3600)
            return
        gesprochen = await sprachausgabe(cfg, erg["antwort"],
                                         (cfg.get("tts") or {}).get("stimme", ""))
        if not gesprochen.get("ok"):
            _LOG.error("Satellit %s: %s", self.name, gesprochen.get("fehler"))
            melden(3, "Die Sprachausgabe antwortet nicht: %s"
                      % gesprochen.get("fehler"), "piper")
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


async def satellit_betreuen(eintrag: dict, cfg: dict, holen_v) -> None:
    """Haelt einen Satelliten dauerhaft verbunden und faengt sich nach
    Ausfaellen wieder - mit wachsendem Abstand, statt dagegen anzurennen.

    holen_v ist eine FUNKTION, keine Deutung. Bis 0.9.11 wurde hier das
    Verstehen-Objekt uebergeben, das beim Start galt; die Schleife frischte
    zwar sat.v auf, aber nach einem Verbindungsabbruch wurde der Satellit mit
    dem alten Objekt neu gebaut. Die Zusage 'der Dienst liest die Satzdatei
    von selbst neu' galt damit nur bis zum ersten Wackler.
    """
    fehler_folge = 0
    name = str(eintrag.get("name") or eintrag.get("host"))
    while _LAUF:
        sat = Satellit(eintrag, cfg, holen_v())
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
        if fehler_folge == 3:
            melden(3, "Das Mikrofon %s ist seit mehreren Versuchen nicht erreichbar."
                      % name, "sat_" + name)
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
# Plugins - und bis 0.9.11 war er gar nicht vorhanden: verbinden, device_info
# holen, dann 'await asyncio.sleep(1)' in einer Schleife. Kein Rueckruf, kein
# Audio, kein Satz. Ein eingetragenes Atom Echo bekam im Selbsttest einen
# gruenen Haken, weil sein Port offen war, und tat nie etwas.
#
# Seit 0.10.0 werden die Rueckrufe der Voice-Assistant-Schnittstelle bedient.
# Ob der Audioweg an einem echten Geraet traegt, ist NICHT gemessen - hier
# steht kein solches Geraet. Die Oberflaeche sagt das auch so; ein gruener
# Haken waere eine Behauptung.
# ---------------------------------------------------------------------------
ESPHOME: dict = {}


class EsphomeMikrofon:
    def __init__(self, eintrag: dict) -> None:
        self.name = str(eintrag.get("name") or eintrag.get("host"))
        self.host = str(eintrag.get("host") or "")
        self.port = int(eintrag.get("port") or 6053)
        self.raum = str(eintrag.get("raum") or "")
        self.zone = str(eintrag.get("zone") or "")
        self.zustand = "getrennt"
        self.letzter_satz = ""
        self.letzte_meldung = ""
        # Die offene Verbindung - ohne sie kann eine Ansage aus der
        # Warteschlange den Lautsprecher dieses Geraets nicht erreichen.
        # Genau das fehlte bis 0.10.3: ansage_ausgeben() kannte nur die
        # Wyoming-Satelliten, ESPHOME stand dort nirgends.
        self.klient = None
        # Was das Geraet selbst ueber sich meldet (SPEAKER, API_AUDIO,
        # ANNOUNCE). Gelesen, nicht angenommen.
        self.merkmale = 0
        # Was der Media Player fuer eine Ansage annimmt - vom Geraet
        # gelesen, nicht angenommen. Leer heisst: es meldet nichts.
        self.ansageformat = {}
        # Wird gesetzt, sobald das Geraet eine Ansage zu Ende gespielt hat.
        self.ansage_fertig = None
        self.gespraech = ""
        self.lauf_offen = False

    def abbild(self) -> dict:
        return {"name": self.name, "art": "esphome", "host": self.host,
                "port": self.port, "zustand": self.zustand,
                "raum": self.raum, "zone": self.zone,
                "letzter_satz": self.letzter_satz,
                "letzte_meldung": self.letzte_meldung,
                "lautsprecher": bool(self.merkmale & 2),
                "gemeldet": self.zustand != "getrennt"}


# Laufende Erkennungen der ESPHome-Mikrofone. Siehe ende() weiter unten.
_ESPHOME_AUFGABEN = set()


def _esphome_fertig(aufgabe) -> None:
    _ESPHOME_AUFGABEN.discard(aufgabe)
    if aufgabe.cancelled():
        return
    fehler = aufgabe.exception()
    if fehler is not None:
        melde_gebremst("esph_aufgabe",
                       "ESPHome: die Verarbeitung eines Satzes ist "
                       "gescheitert: " + fehlertext(fehler), 3600)


# Die Abtastrate, die die ESPHome-Firmware auf dem Rueckweg erwartet.
# VEREINBARUNG, kein gemessener Wert: das API fuehrt dafuer kein Feld. Eine
# falsche Rate ist hoerbar - zu schnell oder zu langsam -, also keine stille
# Falschaussage.
ESPHOME_RATE = 16000


def pcm_umrechnen(daten: bytes, von: int, nach: int) -> bytes:
    """16-Bit-Mono-PCM von einer Abtastrate auf eine andere.

    Piper liefert je nach Stimme 16000 oder 22050 Hz. Lineare Zwischenwerte,
    ohne Filter: fuer Sprache reicht das, und ein ordentlicher Tiefpass waere
    hier neuer Code ohne messbaren Gewinn.
    """
    if von == nach or not daten or von <= 0 or nach <= 0:
        return daten
    quelle = array.array("h")
    quelle.frombytes(daten[:len(daten) - (len(daten) % 2)])
    if sys.byteorder == "big":
        quelle.byteswap()
    n = len(quelle)
    if n < 2:
        return daten
    zahl = max(1, int(n * nach / von))
    ziel = array.array("h", bytes(2 * zahl))
    schritt = (n - 1) / max(1, zahl - 1) if zahl > 1 else 0.0
    for i in range(zahl):
        x = i * schritt
        links = int(x)
        rechts = min(links + 1, n - 1)
        anteil = x - links
        ziel[i] = int(quelle[links] + (quelle[rechts] - quelle[links]) * anteil)
    if sys.byteorder == "big":
        ziel.byteswap()
    return ziel.tobytes()


# Die Firmware liest 16384 Byte Lautsprecherpuffer (16 * RECEIVE_SIZE) - bei
# 16 kHz Mono 16 Bit sind das 0,512 Sekunden. Der Vorlauf begrenzt, wieviel
# davon jemals belegt ist: 0,25 s sind rund 8000 Byte, also die Haelfte.
ESPHOME_VORLAUF_S = 0.25
# So gross sind die Haeppchen, in denen gesendet wird. RECEIVE_SIZE der
# Firmware ist 1024; groessere Haeppchen sind erlaubt, solange die Taktung
# stimmt, aber kleine machen den Vorlauf gleichmaessiger.
ESPHOME_HAPPEN = 1024
# Wie lange ueber die Dauer der Antwort hinaus auf die Rueckmeldung des
# Geraets gewartet wird. Die Firmware meldet spaetestens zwei Sekunden nach
# dem Ende (start_playback_timeout_); der Rest ist Netz und Nachlauf.
ESPHOME_ANSAGE_ZUSCHLAG = 15.0


def wav_bauen(pcm: bytes, rate: int = ESPHOME_RATE) -> bytes:
    """Ein RIFF/WAVE-Kopf um rohes 16-Bit-Mono-PCM.

    Fuer den Ansageweg: ein Geraet mit Media Player holt sich die Antwort
    ueber eine Adresse, und dort muss eine Datei liegen, die ein Abspieler
    lesen kann - rohes PCM ist keine.
    """
    kanaele, breite = 1, 2
    byterate = rate * kanaele * breite
    return (b"RIFF" + (36 + len(pcm)).to_bytes(4, "little") + b"WAVEfmt "
            + (16).to_bytes(4, "little") + (1).to_bytes(2, "little")
            + kanaele.to_bytes(2, "little") + rate.to_bytes(4, "little")
            + byterate.to_bytes(4, "little")
            + (kanaele * breite).to_bytes(2, "little")
            + (breite * 8).to_bytes(2, "little")
            + b"data" + len(pcm).to_bytes(4, "little") + pcm)


def esphome_ansageformat(entitaeten) -> dict:
    """Was nimmt der Media Player dieses Geraets fuer eine Ansage an?

    GELESEN, nicht angenommen: `MediaPlayerInfo.supported_formats` ist die
    einzige Stelle im ganzen API mit einem Ratenfeld, und `purpose` trennt
    dort ANNOUNCEMENT von der normalen Wiedergabe. Bis 0.11.1 schrieb das
    Plugin die WAV immer mit 16000 Hz - das ist `SAMPLE_RATE_HZ` der
    Firmware und gilt fuer den API-STROM, nicht fuer den Media Player.

    Rueckgabe: {'rate', 'kanaele', 'bytes'} - oder {} , wenn das Geraet
    nichts meldet (dann bleibt es bei der Vorgabe) beziehungsweise nur
    Formate meldet, die dieses Plugin nicht erzeugen kann.
    """
    beste = None
    for e in (entitaeten or []):
        for f in (getattr(e, "supported_formats", None) or []):
            art = str(getattr(f, "format", "") or "").lower()
            if art and art not in ("wav", "wave", "pcm"):
                # Das Plugin schreibt WAV. Ein Geraet, das nur flac oder mp3
                # nimmt, bekommt lieber eine Meldung als eine Datei, die es
                # nicht lesen kann.
                continue
            zweck = int(getattr(f, "purpose", 0) or 0)
            # 1 = ANNOUNCEMENT. Der gilt vor der normalen Wiedergabe.
            rang = 0 if zweck == 1 else 1
            if beste is None or rang < beste[0]:
                beste = (rang, f)
    if beste is None:
        return {}
    f = beste[1]
    return {"rate": int(getattr(f, "sample_rate", 0) or 0) or ESPHOME_RATE,
            "kanaele": int(getattr(f, "num_channels", 0) or 0) or 1,
            "bytes": int(getattr(f, "sample_bytes", 0) or 0) or 2}


def ansage_ablegen(pcm: bytes, format_: dict = None) -> tuple:
    """Die Antwort als WAV unter einer abrufbaren Adresse ablegen.

    Rueckgabe (url, pfad) - oder ('', None), wenn der Ort nicht beschreibbar
    ist. Abgelegt wird im UNANGEMELDETEN Baum, weil das Geraet sich nicht
    anmelden kann; geschuetzt ist die Datei durch einen Namen, den niemand
    raten kann, und durch ihre kurze Lebensdauer. Geschrieben wird sie vom
    DIENST - der unangemeldete Endpunkt schreibt weiterhin nichts.

    Alte Dateien werden bei jedem Ablegen mit abgeraeumt; ohne das fuellt
    sich ein Verzeichnis, das niemand ansieht.
    """
    ordner = LBHOME / "webfrontend" / "html" / "plugins" / PNAME / "ansagen"
    try:
        ordner.mkdir(parents=True, exist_ok=True)
        jetzt = time.time()
        for alt in ordner.glob("*.wav"):
            try:
                if jetzt - alt.stat().st_mtime > 300:
                    alt.unlink()
            except OSError:
                pass
        name = secrets.token_hex(16) + ".wav"
        ziel = ordner / name
        # Die Rate kommt vom GERAET, wenn es eine nennt - siehe
        # esphome_ansageformat(). ESPHOME_RATE ist die Vorgabe fuer den
        # API-Strom und nur der Rueckfall fuer diesen Weg.
        ziel.write_bytes(wav_bauen(pcm, int((format_ or {}).get("rate")
                                            or ESPHOME_RATE)))
        return "http://%s/plugins/%s/ansagen/%s" % (eigene_adresse(), PNAME, name), ziel
    except OSError as err:
        melde_gebremst("ansage_ablegen",
                       "Die Antwort liess sich nicht unter einer Adresse ablegen "
                       "(%s). Ein ESPHome-Geraet mit Media Player kann sie damit "
                       "nicht holen." % fehlertext(err), 3600)
        return "", None


def eigene_adresse() -> str:
    """Die Adresse, unter der DAS GERAET diesen LoxBerry erreicht.

    NICHT 127.0.0.1: eine Adresse, die ein Programm auf demselben Rechner
    benutzt, und eine, die ein anderes Geraet anspricht, sind zwei
    verschiedene Dinge (REGELN_1, EVCC-Sitzung). Genommen wird die Adresse
    der Schnittstelle, ueber die der Rechner nach aussen geht.
    """
    global _EIGENE_ADRESSE
    if _EIGENE_ADRESSE:
        return _EIGENE_ADRESSE
    s = None
    try:
        s = socket.socket(socket.AF_INET, socket.SOCK_DGRAM)
        # Es wird nichts gesendet - das Verbinden waehlt nur die Schnittstelle.
        s.connect(("192.0.2.1", 9))
        _EIGENE_ADRESSE = s.getsockname()[0]
    except OSError:
        _EIGENE_ADRESSE = socket.gethostname()
    finally:
        if s is not None:
            s.close()
    return _EIGENE_ADRESSE


_EIGENE_ADRESSE = ""


async def esphome_ereignis(mikro: "EsphomeMikrofon", art, daten=None) -> None:
    """Ein Ereignis an das Geraet melden - MIT Datenteil.

    Die Schluesselnamen stehen nicht im Paket aioesphomeapi, sondern in der
    FIRMWARE: esphome/components/voice_assistant/voice_assistant.cpp.
    Gemessen an 2026.8.2:

        RUN_START   url
        STT_END     text          - ohne: return
        TTS_START   text          - ohne: return VOR speaker_->start()
        TTS_END     url           - ohne: return VOR STREAMING_RESPONSE
        INTENT_END  conversation_id, continue_conversation
        ERROR       code, message

    In 0.11.0 gingen die Ereignisse ohne Daten hinaus, mit der Begruendung,
    die Namen seien nicht nachlesbar. Sie sind es - nur im anderen Haus.
    Die Folge war, dass das Audio im Lautsprecherpuffer des Geraets liegen
    blieb und nie abgespielt wurde.

    Ein Fehler hier darf den Satz nicht kosten - er darf aber auch nicht
    stumm bleiben.
    """
    klient = getattr(mikro, "klient", None)
    if klient is None:
        return
    try:
        klient.send_voice_assistant_event(art, daten)
    except Exception as err:  # noqa: BLE001
        melde_gebremst("esph_ereignis_" + mikro.name,
                       "ESPHome %s: Ereignis %s liess sich nicht senden: %s"
                       % (mikro.name, getattr(art, "name", art), fehlertext(err)),
                       3600)


async def esphome_lauf_beenden(mikro: "EsphomeMikrofon") -> None:
    """RUN_END - und zwar genau einmal je Lauf.

    Ohne dieses Ereignis haelt sich das Geraet fuer dauerhaft mitten in einer
    Pipeline: der Leuchtring dreht weiter, und es kommt nicht in den
    Ruhezustand zurueck. Bis 0.10.3 ging ueberhaupt kein Ereignis zurueck.
    """
    from aioesphomeapi import VoiceAssistantEventType as VE
    if not getattr(mikro, "lauf_offen", False):
        return
    mikro.lauf_offen = False
    await esphome_ereignis(mikro, VE.VOICE_ASSISTANT_RUN_END)


def esphome_kann(mikro: "EsphomeMikrofon", merkmal) -> bool:
    """Kann das Geraet das? Gelesen aus den Merkmalen, die es selbst meldet."""
    return bool(int(getattr(mikro, "merkmale", 0)) & int(merkmal))


async def esphome_sprechen(mikro: "EsphomeMikrofon", cfg: dict, text: str) -> tuple:
    """Den Antworttext auf dem Lautsprecher des Geraets ausgeben.

    Rueckgabe (ok, Meldung). Der Weg ist der, den das API dafuer vorsieht:
    TTS_STREAM_START, dann die Bloecke ueber send_voice_assistant_audio(),
    dann TTS_STREAM_END.
    """
    from aioesphomeapi import VoiceAssistantEventType as VE
    from aioesphomeapi import VoiceAssistantFeature as VF
    klient = getattr(mikro, "klient", None)
    if klient is None:
        return False, "keine offene Verbindung"
    # SPEAKER **oder** ANNOUNCE: get_feature_flags() setzt das eine bei
    # speaker_ != nullptr, das andere bei media_player_ != nullptr - zwei
    # unabhaengige Bedingungen. Bis 0.11.1 stand hier nur SPEAKER, und ein
    # Geraet mit blossem Media Player wurde abgewiesen, obwohl der
    # Media-Player-Zweig weiter unten genau fuer es gebaut ist.
    if not (esphome_kann(mikro, VF.SPEAKER) or esphome_kann(mikro, VF.ANNOUNCE)):
        return False, ("das Geraet meldet weder Lautsprecher noch Media Player")
    gesprochen = await sprachausgabe(cfg, text, (cfg.get("tts") or {}).get("stimme", ""))
    if not gesprochen.get("ok"):
        return False, str(gesprochen.get("fehler") or "Sprachausgabe")
    if int(gesprochen.get("channels") or 1) != 1 or int(gesprochen.get("width") or 2) != 2:
        return False, ("die Sprachausgabe liefert %d Kanaele mit %d Byte - erwartet "
                       "wird Mono mit 16 Bit"
                       % (gesprochen.get("channels"), gesprochen.get("width")))
    rate = int(gesprochen.get("rate") or ESPHOME_RATE)
    pcm = b"".join(pcm_umrechnen(b, rate, ESPHOME_RATE)
                   for b in gesprochen["bloecke"])

    # Die Adresse muss NICHTLEER sein, sonst steigt die Firmware im
    # TTS_END-Zweig aus - vor dem Zustandswechsel, in dem der
    # Lautsprecherpuffer geleert wird. Ein Geraet MIT Media Player holt
    # sie wirklich ab; eines mit blossem Lautsprecher reicht sie nur an
    # seinen Ausloeser durch.
    hat_spieler = esphome_kann(mikro, VF.ANNOUNCE)
    url, datei = ansage_ablegen(pcm, getattr(mikro, "ansageformat", None))
    if not url:
        if hat_spieler:
            return False, ("das Geraet holt die Antwort ueber eine Adresse, und "
                           "die liess sich nicht ablegen")
        # Ohne Media Player wird die Adresse nie abgerufen - sie muss nur
        # dasein. Das wird gesagt, nicht verschwiegen.
        url = "http://%s/plugins/%s/ansagen/keine.wav" % (eigene_adresse(), PNAME)

    # DIE REIHENFOLGE: erst der Text (startet den Lautsprecher), dann die
    # Adresse (wechselt in STREAMING_RESPONSE), DANN erst das Audio. In
    # 0.11.0 stand TTS_END am Ende - der Zustand wechselte nie, und die
    # Bloecke lagen im Puffer.
    if hat_spieler:
        # Vor dem Absenden scharf machen, nicht danach: sonst kann die
        # Rueckmeldung schneller sein als das Warten darauf.
        mikro.ansage_fertig = asyncio.Event()
    await esphome_ereignis(mikro, VE.VOICE_ASSISTANT_TTS_START, {"text": text[:497]})
    await esphome_ereignis(mikro, VE.VOICE_ASSISTANT_TTS_END, {"url": url})
    if hat_spieler:
        # Der Media Player spielt die Adresse selbst ab. Zusaetzlich zu
        # streamen hiesse, die Antwort zweimal zu hoeren.
        #
        # Aber 'die Adresse ist rausgegangen' ist keine Auskunft darueber,
        # ob sie gespielt wurde - das ist der gruene Haken von 0.9.11, eine
        # Ebene tiefer. Das Geraet sagt es selbst; gewartet wird die Dauer
        # der Antwort plus Zuschlag.
        dauer = len(pcm) / float(ESPHOME_RATE * 2)
        frist = min(120.0, dauer + ESPHOME_ANSAGE_ZUSCHLAG)
        try:
            await asyncio.wait_for(mikro.ansage_fertig.wait(), timeout=frist)
            return True, ""
        except asyncio.TimeoutError:
            return False, ("das Geraet hat die Adresse bekommen, aber innerhalb "
                           "von %d s nicht gemeldet, dass es sie gespielt hat"
                           % int(frist))
        finally:
            mikro.ansage_fertig = None

    await esphome_ereignis(mikro, VE.VOICE_ASSISTANT_TTS_STREAM_START)
    try:
        await esphome_audio_takten(klient, pcm)
    except Exception as err:  # noqa: BLE001
        await esphome_ereignis(mikro, VE.VOICE_ASSISTANT_TTS_STREAM_END)
        return False, fehlertext(err)
    await esphome_ereignis(mikro, VE.VOICE_ASSISTANT_TTS_STREAM_END)
    return True, ""


async def esphome_audio_takten(klient, pcm: bytes) -> None:
    """Die Bloecke gegen eine Uhr schicken, nicht so schnell es geht.

    Der Lautsprecherpuffer der Firmware ist 16 * RECEIVE_SIZE = 16384 Byte,
    bei 16 kHz Mono 16 Bit also 0,512 Sekunden. Wer eine ganze Antwort ohne
    Pause hinterherschickt, bekommt 'Cannot receive audio, buffer is full'
    und hoert den Anfang eines Satzes.

    Getaktet wird gegen eine Uhr statt mit einer festen Pause je Happen:
    damit ist die Puffermenge nach oben begrenzt (Vorlauf mal Byterate,
    also rund 8000 Byte) - unabhaengig davon, wie lang die Antwort ist.
    """
    byterate = float(ESPHOME_RATE * 2)
    beginn = time.monotonic()
    gesendet = 0
    for i in range(0, len(pcm), ESPHOME_HAPPEN):
        happen = pcm[i:i + ESPHOME_HAPPEN]
        klient.send_voice_assistant_audio(happen)
        gesendet += len(happen)
        soll = beginn + gesendet / byterate - ESPHOME_VORLAUF_S
        rest = soll - time.monotonic()
        if rest > 0:
            await asyncio.sleep(rest)


async def esphome_betreuen(eintrag: dict, cfg: dict, holen_v) -> None:
    name = str(eintrag.get("name") or eintrag.get("host"))
    mikro = EsphomeMikrofon(eintrag)
    ESPHOME[name] = mikro
    try:
        from aioesphomeapi import APIClient
    except ImportError:
        mikro.letzte_meldung = "Paket aioesphomeapi fehlt."
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
        puffer: dict = {"rahmen": [], "laeuft": False}

        # ALLE DREI SIND KOROUTINEN. Die Bibliothek reicht ihr Ergebnis an
        # create_eager_task() bzw. _create_background_task() weiter;
        # gemessen gegen aioesphomeapi 46.3.0: create_eager_task(0) endet
        # mit 'TypeError: a coroutine was expected, got 0'. Bis 0.10.3
        # waren es gewoehnliche Funktionen - der Fehler fiel INNERHALB des
        # Nachrichtenrueckrufs an, und das Geraet bekam nicht einmal die
        # Fehlerantwort. Der ESPHome-Weg konnte nie etwas tun.
        async def beginn(gespraech="", flags=0, audio_einstellungen=None,
                         weckwort=None):
            """Das Geraet meldet den Beginn einer Sprachanfrage.

            Die vier Argumente kommen so aus der Bibliothek:
            conversation_id, flags, audio_settings, wake_word_phrase.
            """
            from aioesphomeapi import VoiceAssistantEventType as VE
            puffer["rahmen"] = []
            puffer["laeuft"] = True
            mikro.zustand = "hoert"
            mikro.gespraech = str(gespraech or "")
            mikro.lauf_offen = True
            await esphome_ereignis(mikro, VE.VOICE_ASSISTANT_RUN_START)
            if weckwort:
                # Das Weckwort ist auf dem Geraet gefallen (microWakeWord),
                # nicht bei uns - der Lauf beginnt also NACH dem Weckwort.
                mikro.letzte_meldung = "Weckwort: %s" % weckwort
                await esphome_ereignis(mikro, VE.VOICE_ASSISTANT_WAKE_WORD_END)
            await esphome_ereignis(mikro, VE.VOICE_ASSISTANT_STT_START)
            # 0 heisst: das Audio kommt ueber die API, nicht ueber einen
            # eigenen UDP-Port. None waere ein Fehler - die Bibliothek
            # schickt dem Geraet dann VoiceAssistantResponse(error=True).
            return 0

        async def ansage_fertig(meldung=None):
            """Das Geraet hat eine Ansage zu Ende gespielt.

            Die Firmware schickt VoiceAssistantAnnounceFinished aus zwei
            Stellen: aus dem STREAMING_RESPONSE-Zweig der loop, sobald der
            Media Player FINISHED meldet, und aus start_playback_timeout_().
            An BEIDEN steht `msg.success` fest auf true - der Wert traegt
            also keine Auskunft. Dass die Meldung kommt, traegt sie.
            """
            if mikro.ansage_fertig is not None:
                mikro.ansage_fertig.set()

        async def hoeren(daten: bytes, daten2: bytes = None):
            """Ein Audioblock vom Geraet.

            ZWEI Argumente: die Bibliothek ruft handle_audio(audio.data,
            audio.data2). Der zweite Kanal ist fuer Geraete mit
            MULTI_CHANNEL_AUDIO; Whisper bekommt einen Kanal, also bleibt
            er liegen. Bis 0.10.3 nahm diese Funktion EIN Argument.
            """
            if puffer["laeuft"]:
                puffer["rahmen"].append(bytes(daten))

        async def ende(abbruch: bool = False):
            """Das Geraet hoert auf zu senden.

            Das Argument kommt aus zwei Quellen, im Quelltext der
            Bibliothek nachgelesen: handle_stop(True) bei einer
            Stopp-Anforderung des Geraets - also Abbruch -, und
            handle_stop(False), wenn der Audiostrom regulaer endet. Am
            Geraet ist das nicht gegengeprueft.
            """
            from aioesphomeapi import VoiceAssistantEventType as VE
            puffer["laeuft"] = False
            mikro.zustand = "verbunden"
            rahmen = puffer["rahmen"]
            puffer["rahmen"] = []
            # Der Text steht hier noch nicht fest - er kommt aus Whisper.
            # Die Firmware steigt bei leerem STT_END-Text aus; das kostet
            # nur einen Ausloeser, nicht den Lauf. Das gefuellte STT_END
            # schickt esphome_satz(), sobald der Text da ist.
            if abbruch or not rahmen:
                await esphome_ereignis(mikro, VE.VOICE_ASSISTANT_STT_END,
                                       {"text": ""})
                # Auch ein abgebrochener Lauf wird BEENDET - sonst dreht
                # der Leuchtring weiter.
                await esphome_lauf_beenden(mikro)
                return
            # Der Verweis wird FESTGEHALTEN: eine Aufgabe, auf die
            # niemand zeigt, darf der Muellsammler mitten im Lauf
            # einziehen, und eine Ausnahme darin endet unsichtbar.
            aufgabe = asyncio.ensure_future(esphome_satz(mikro, rahmen, holen_v))
            _ESPHOME_AUFGABEN.add(aufgabe)
            aufgabe.add_done_callback(_esphome_fertig)

        from aioesphomeapi import VoiceAssistantFeature as VF
        try:
            await klient.connect(login=True)
            # In EINEM Zug: Geraeteangaben UND Entitaeten. Aus letzteren
            # kommt das Ansageformat des Media Players (Punkt 2).
            entitaeten = []
            if hasattr(klient, "device_info_and_list_entities"):
                geraet, entitaeten, _dienste = await klient.device_info_and_list_entities()
            else:
                geraet = await klient.device_info()
            mikro.zustand = "verbunden"
            mikro.klient = klient
            # Was das Geraet ueber sich meldet - gelesen, nicht angenommen.
            try:
                mikro.merkmale = int(geraet.voice_assistant_feature_flags_compat(
                    klient.api_version))
            except Exception:  # noqa: BLE001
                mikro.merkmale = int(getattr(geraet, "voice_assistant_feature_flags", 0))
            mikro.ansageformat = esphome_ansageformat(entitaeten)
            if esphome_kann(mikro, VF.ANNOUNCE) and not mikro.ansageformat:
                melde_gebremst(
                    "esph_format_" + name,
                    "ESPHome %s meldet einen Media Player, aber kein Format, das "
                    "dieses Plugin erzeugen kann (es schreibt WAV). Die Ansage "
                    "wird mit %d Hz Mono abgelegt." % (name, ESPHOME_RATE), 86400)
            _LOG.info("ESPHome-Mikrofon %s verbunden: %s (Merkmale %d, Ansageformat %s)",
                      name, getattr(geraet, "name", "?"), mikro.merkmale,
                      mikro.ansageformat or "nicht gemeldet")
            fehler_folge = 0
            if not hasattr(klient, "subscribe_voice_assistant"):
                mikro.letzte_meldung = ("Diese Fassung von aioesphomeapi kennt keine "
                                        "Sprachschnittstelle.")
                melde_gebremst("esph_alt_" + name, mikro.letzte_meldung, 86400)
            else:
                # KEIN Rueckfall auf einen Aufruf ohne handle_audio: die
                # Parameter sind schluesselwort-only (das '*' in der
                # Signatur), ein Aufruf mit Stellungsargumenten kann nie
                # greifen. Und ohne handle_audio setzt die Bibliothek das
                # Merkmal API_AUDIO gar nicht - es kaeme nie ein Ton an.
                klient.subscribe_voice_assistant(
                    handle_start=beginn,
                    handle_stop=ende,
                    handle_audio=hoeren,
                    handle_announcement_finished=ansage_fertig)
                # VF.SPEAKER, nicht die nackte 2: eine Zahl, die jemand beim
                # naechsten Mal nachschlagen muss, ist eine geratene Zahl.
                if not (esphome_kann(mikro, VF.SPEAKER)
                        or esphome_kann(mikro, VF.ANNOUNCE)):
                    mikro.letzte_meldung = ("Das Geraet meldet weder Lautsprecher noch "
                                            "Media Player - die Antwort kommt nur "
                                            "ueber Loxone.")
            while _LAUF and klient.connected:
                await asyncio.sleep(1)
        except Exception as err:  # noqa: BLE001
            fehler_folge += 1
            mikro.zustand = "getrennt"
            mikro.letzte_meldung = fehlertext(err)
            melde_gebremst("esph_" + name, f"ESPHome-Mikrofon {name}: {fehlertext(err)}", 900)
        finally:
            mikro.zustand = "getrennt"
            mikro.klient = None
            mikro.lauf_offen = False
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


async def esphome_satz(mikro: "EsphomeMikrofon", rahmen: list, holen_v) -> None:
    """Audio -> Text -> Absicht -> Antwort, und dabei das Geraet mitnehmen.

    Das RUN_END steht im finally. Ohne es haelt sich das Geraet fuer
    dauerhaft mitten in einer Pipeline: der Leuchtring dreht weiter, und
    es kommt nicht in den Ruhezustand zurueck - auch dann nicht, wenn hier
    etwas schiefgeht. Bis 0.10.3 ging ueberhaupt kein Ereignis zurueck.
    """
    from aioesphomeapi import VoiceAssistantEventType as VE
    cfg = config()
    try:
        erkannt = await spracherkennung(cfg, rahmen, 16000)
        if not erkannt.get("ok"):
            mikro.letzte_meldung = erkannt.get("fehler", "")
            _LOG.error("ESPHome %s: %s", mikro.name, mikro.letzte_meldung)
            await esphome_ereignis(
                mikro, VE.VOICE_ASSISTANT_ERROR,
                {"code": "stt-failed",
                 "message": str(mikro.letzte_meldung or "Spracherkennung")[:200]})
            return
        satz = erkannt["text"]
        mikro.letzter_satz = satz
        # Jetzt erst steht der Text fest - die Firmware braucht ihn.
        await esphome_ereignis(mikro, VE.VOICE_ASSISTANT_STT_END, {"text": satz})
        if not satz:
            mikro.letzte_meldung = "Es wurde nichts verstanden (leerer Text)."
            return
        await esphome_ereignis(mikro, VE.VOICE_ASSISTANT_INTENT_START)
        erg = await satz_im_faden(satz, cfg, holen_v(), mikro.name,
                                  mikro.raum, mikro.zone)
        await esphome_ereignis(
            mikro, VE.VOICE_ASSISTANT_INTENT_END,
            {"conversation_id": str(getattr(mikro, "gespraech", "") or ""),
             "continue_conversation": "0"})
        mikro.letzte_meldung = erg.get("antwort", "")

        # Die Antwort kommt aus dem Geraet, in das hineingesprochen wurde.
        # Bis 0.10.3 kannte ansage_ausgeben() nur die Wyoming-Satelliten;
        # ein ESPHome-Geraet mit Lautsprecher bekam nie einen Ton.
        text = str(erg.get("antwort") or "").strip()
        if (text and cfg.get("antwort_sprechen")
                and str(cfg.get("antwortweg") or "beide") != "loxone"):
            still, grund = ruhe_aktiv(cfg)
            if still:
                melde_gebremst("esph_ruhe",
                               "Antwort am ESPHome-Mikrofon unterdrueckt: " + grund,
                               3600)
            else:
                ok, meldung = await esphome_sprechen(mikro, cfg, text)
                if not ok and meldung:
                    melde_gebremst("esph_tts_" + mikro.name,
                                   "ESPHome %s: die Antwort blieb stumm (%s)."
                                   % (mikro.name, meldung), 3600)
    finally:
        await esphome_lauf_beenden(mikro)


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


async def ansage_ausgeben(cfg: dict, text: str, zonen: str = "",
                          mikrofon: str = "", dringend: bool = False) -> dict:
    """Einen Text wirklich hoerbar machen.

    BIS 0.9.11 GESCHAH HIER NICHTS HOERBARES: die Warteschlange rief Piper auf,
    rechnete aus der Antwort die Dauer aus - und warf die Audiobloecke weg. Es
    ging weder etwas an einen Satelliten noch an den Music Server. Loxone bekam
    'SET;OK=1;...Sprachausgabe erzeugt: 1,80 s Audio' und im Haus blieb es
    still. Eine Luecke, die Erfolg meldet.
    """
    from wyoming.audio import AudioChunk, AudioStart, AudioStop

    wege = []
    fehler = []
    cfg = dict(cfg, _dringend=dringend)
    weg = str(cfg.get("antwortweg") or "beide")

    # 1. Ueber Loxone (Music Server, MS4H, eigene Vorlage oder MQTT-Thema)
    if weg in ("loxone", "beide"):
        erg = loxone_ansagen(cfg, text, zonen)
        if erg.get("ok"):
            wege.append("Loxone-Audioausgabe")
        else:
            fehler.append(str(erg.get("meldung") or erg.get("fehler")
                              or erg.get("grund") or "Loxone-Audioausgabe"))

    # 2. Ueber den Lautsprecher eines Satelliten
    if weg in ("satellit", "beide") and cfg.get("antwort_sprechen"):
        still, grund = ruhe_aktiv(cfg)
        if still and not dringend:
            fehler.append(grund)
        else:
            ziel = SATELLITEN.get(mikrofon) if mikrofon else None
            # Ein benanntes Mikrofon kann auch ein ESPHome-Geraet sein.
            # Bis 0.10.3 wurde nur SATELLITEN durchsucht - eine Voice PE
            # war damit unerreichbar, und ein Name, den es sehr wohl gab,
            # wurde als 'nicht eingetragen' gemeldet.
            esph_ziel = ESPHOME.get(mikrofon) if mikrofon else None
            if mikrofon and ziel is None and esph_ziel is None:
                # Ein benanntes Mikrofon, das es nicht gibt, wird BENANNT und
                # nicht durch 'dann eben alle' ersetzt - sonst spricht das
                # ganze Haus, weil sich jemand vertippt hat.
                kandidaten = []
                fehler.append("Mikrofon %r ist nicht eingetragen oder nicht verbunden"
                              % mikrofon)
            elif ziel is None:
                # Ohne Angabe: jeder verbundene Satellit. Eine Ansage 'Das
                # Garagentor steht offen' will man ueberall hoeren.
                kandidaten = [s for s in SATELLITEN.values() if s.zustand != "getrennt"]
            else:
                kandidaten = [ziel] if ziel is not None else []
            # Dieselbe Regel fuer die ESPHome-Familie: ein benanntes Geraet,
            # sonst alle verbundenen mit Lautsprecher.
            if esph_ziel is not None:
                esph_kandidaten = [esph_ziel]
            elif mikrofon:
                esph_kandidaten = []
            else:
                esph_kandidaten = [e for e in ESPHOME.values()
                                   if e.zustand != "getrennt"]
            if not kandidaten and not esph_kandidaten:
                if not mikrofon:
                    fehler.append("kein verbundenes Mikrofon")
            else:
                gesprochen = await sprachausgabe(cfg, text,
                                                 (cfg.get("tts") or {}).get("stimme", ""))
                if not gesprochen.get("ok"):
                    fehler.append(str(gesprochen.get("fehler")))
                else:
                    for sat in kandidaten:
                        schreiber = getattr(sat, "schreiber", None)
                        if schreiber is None:
                            fehler.append("%s: keine offene Verbindung" % sat.name)
                            continue
                        try:
                            await wy_senden(schreiber, AudioStart(
                                rate=gesprochen["rate"], width=gesprochen["width"],
                                channels=gesprochen["channels"]).event())
                            for block in gesprochen["bloecke"]:
                                await wy_senden(schreiber, AudioChunk(
                                    rate=gesprochen["rate"], width=gesprochen["width"],
                                    channels=gesprochen["channels"], audio=block).event())
                            await wy_senden(schreiber, AudioStop().event())
                            wege.append("Mikrofon " + sat.name)
                        except (OSError, asyncio.TimeoutError) as err:
                            fehler.append("%s: %s" % (sat.name, fehlertext(err)))
                    for esph in esph_kandidaten:
                        # Auch AUSSERHALB eines Laufs sollte das tragen, und
                        # das ist keine Hoffnung, sondern im Quelltext der
                        # Firmware begruendet: der TTS_END-Zweig prueft den
                        # Zustand NICHT. Er setzt bei local_output_
                        # bedingungslos STREAMING_RESPONSE, gleich aus welchem
                        # Zustand heraus - und local_output_ wird sowohl von
                        # set_speaker() als auch von set_media_player()
                        # gesetzt. RESPONSE_FINISHED bringt das Geraet danach
                        # von selbst zurueck.
                        # AM GERAET gemessen ist es nicht; hier steht keines.
                        ok, meldung = await esphome_sprechen(esph, cfg, text)
                        if ok:
                            wege.append("Mikrofon " + esph.name)
                        else:
                            fehler.append("%s: %s" % (esph.name, meldung))

    if wege:
        return {"ok": 1, "wege": wege, "fehler": fehler}
    return {"ok": 0, "wege": [], "fehler": fehler or ["kein Ausgabeweg"]}


async def warteschlange(cfg: dict, holen_v) -> None:
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
            if aktion in ("satz", "trocken"):
                satz = str(b.get("satz") or "").strip()
                if not satz:
                    antwort_schreiben(kennung, 0, "Es wurde kein Satz uebergeben.")
                    continue
                erg = await satz_im_faden(satz, cfg, holen_v(),
                                          str(b.get("mikrofon") or ""),
                                          str(b.get("raum") or ""),
                                          str(b.get("zone") or ""),
                                          trocken=(aktion == "trocken"))
                antwort_schreiben(kennung, 1 if erg.get("ok") else 0,
                                  erg.get("antwort") or "", {"ergebnis": erg})
            elif aktion == "sprechen":
                text = str(b.get("text") or "").strip()
                if not text:
                    antwort_schreiben(kennung, 0, "Es wurde kein Text uebergeben.")
                    continue
                erg = await ansage_ausgeben(cfg, text,
                                            str(b.get("zone") or ""),
                                            str(b.get("mikrofon") or ""),
                                            bool(b.get("dringend")))
                if erg.get("ok"):
                    antwort_schreiben(kennung, 1,
                                      "Angesagt ueber: " + ", ".join(erg["wege"]),
                                      {"wege": erg["wege"], "fehler": erg["fehler"]})
                else:
                    antwort_schreiben(kennung, 0,
                                      "Nicht angesagt: " + "; ".join(erg["fehler"]),
                                      {"fehler": erg["fehler"]})
            elif aktion == "neu_laden":
                # Bis 0.9.11 meldete dieser Zweig 'wird beim naechsten Satz neu
                # gelesen' und tat nichts - und niemand setzte ihn ab. Jetzt
                # laedt er wirklich neu und sagt, was dabei herauskam.
                v = verstehen_laden()
                if v is None:
                    antwort_schreiben(kennung, 0, "verstehen.py liess sich nicht laden.")
                else:
                    _SATZSTAND["v"] = v
                    _SATZSTAND["stempel"] = None
                    beanstandungen = v.pruefen()
                    for sat in SATELLITEN.values():
                        sat.v = v
                    antwort_schreiben(
                        kennung, 0 if beanstandungen else 1,
                        ("Satzdatei neu gelesen: %d Regeln, %d Ziele."
                         % (len(v.regeln), len(v.ziele)))
                        + ("" if not beanstandungen
                           else " Beanstandungen: " + " | ".join(beanstandungen)),
                        {"beanstandungen": beanstandungen})
            elif aktion == "ruhe":
                # Loxone legt die Ansagen stille oder gibt sie wieder frei.
                # Geschrieben wird HIER, nicht im Endpunkt - der darf das
                # nicht (Hausregel: der unangemeldete Endpunkt schreibt nicht).
                still = 1 if int(b.get("wert") or 0) else 0
                json_schreiben(DATEI_RUHE, {"still": still, "ts": int(time.time())})
                _LOG.info("Ansagen %s (ueber den Endpunkt).",
                          "stillgelegt" if still else "wieder freigegeben")
                antwort_schreiben(kennung, 1,
                                  "Ansagen sind jetzt stillgelegt."
                                  if still else "Ansagen sind wieder freigegeben.")
            elif aktion == "dienste":
                erg = {}
                for schluessel, bezeichnung in (("whisper", "Spracherkennung"),
                                                ("piper", "Sprachausgabe"),
                                                ("wake", "Wortwecker")):
                    d = await dienst_befragen(str(cfg[schluessel + "_host"]),
                                              int(cfg[schluessel + "_port"]))
                    if d.get("ok"):
                        info = d["info"]
                        erg[schluessel] = {
                            "ok": 1,
                            "modelle": info_namen(info, "asr") + info_namen(info, "tts")
                                       + info_namen(info, "wake"),
                        }
                    else:
                        erg[schluessel] = {"ok": 0, "fehler": d.get("fehler", "")}
                antwort_schreiben(kennung, 1, "Dienste befragt.", {"dienste": erg})
            elif aktion == "probe":
                # Eine Stimme probehoeren, ohne einen Container anzulegen.
                text = str(b.get("text") or "Die Sprachsteuerung ist bereit.").strip()
                erg = await sprachausgabe(cfg, text, str(b.get("stimme") or ""))
                if not erg.get("ok"):
                    antwort_schreiben(kennung, 0, erg.get("fehler", ""))
                else:
                    ziel = PDATA / "probe.wav"
                    try:
                        ziel.write_bytes(wav_bauen(erg["bloecke"], erg["rate"],
                                                   erg["width"], erg["channels"]))
                        antwort_schreiben(kennung, 1, "Probe erzeugt.",
                                          {"datei": str(ziel),
                                           "sekunden": erg["sekunden"]})
                    except OSError as err:
                        antwort_schreiben(kennung, 0, str(err))
            else:
                antwort_schreiben(kennung, 0, "Unbekannte Aktion: " + aktion)
        except Exception as err:  # noqa: BLE001
            antwort_schreiben(kennung, 0, fehlertext(err))


_DIENSTSTAND: dict = {"ts": 0.0, "wert": (0, 0)}


def dienste_erreichbar(cfg: dict, hoechstens_alt: float = 30.0) -> tuple[int, int]:
    """(erreichbar, geprueft) fuer Whisper, Piper und wahlweise das Modell.

    ZWISCHENGESPEICHERT, und das mit Absicht: die Hauptschleife laeuft im
    Sekundentakt. Ohne den Zwischenspeicher baute der Dienst jede Sekunde zwei
    bis drei TCP-Verbindungen auf, nur um eine Zahl fuer das Abbild zu haben -
    eine Pruefung, die etwas kostet, gehoert zwischengespeichert.
    """
    jetzt = time.monotonic()
    if jetzt - _DIENSTSTAND["ts"] < hoechstens_alt:
        return _DIENSTSTAND["wert"]
    gepruef = erreichbar = 0
    liste = ["whisper", "piper"]
    if cfg.get("llm_ein"):
        liste.append("llm")
    for schluessel in liste:
        gepruef += 1
        ok, _ = dienst_erreichbar(str(cfg[schluessel + "_host"]),
                                  int(cfg[schluessel + "_port"]), 2.0)
        if ok:
            erreichbar += 1
    _DIENSTSTAND["ts"] = jetzt
    _DIENSTSTAND["wert"] = (erreichbar, gepruef)
    if gepruef and erreichbar < gepruef:
        melden(3, "Von %d Sprachdiensten antworten nur %d. Die Sprachsteuerung "
                  "kann so nicht arbeiten." % (gepruef, erreichbar), "dienste")
    return _DIENSTSTAND["wert"]


def mikrofone_abbild() -> dict:
    sats = {}
    for name, sat in SATELLITEN.items():
        sats[name] = sat.abbild()
    for name, mikro in ESPHOME.items():
        sats[name] = mikro.abbild()
    return sats


# Merkt sich, was zuletzt in loxone.json stand - siehe abbild_schreiben().
_ABBILD_STAND = {}


def abbild_schreiben(cfg: dict) -> dict:
    saetze = json_lesen(DATEI_SAETZE)
    sats = mikrofone_abbild()
    bereit = sum(1 for s in sats.values() if s["zustand"] != "getrennt")
    erreichbar, gepruef = dienste_erreichbar(cfg)
    verlauf = (json_lesen(DATEI_VERLAUF).get("saetze") or [])
    letzter = verlauf[0] if verlauf else {}
    letzter_ts = int(letzter.get("ts") or 0)
    daten = {
        # OK sagt: der DIENST lebt. Bis 0.9.11 stand hier
        # any(zustand != getrennt) - eine Anlage ohne Mikrofon (die es geben
        # darf, der Reiter Test schickt Saetze auch so durch) meldete damit
        # dauerhaft Stoerung.
        "ok": 1,
        "ts": int(time.time()),
        "pid": os.getpid(),
        "satelliten": sats,
        "anzahl_mikrofone": len(sats),
        "bereit": bereit,
        "dienste_ok": erreichbar,
        "dienste_gesamt": gepruef,
        "anzahl_regeln": len(saetze.get("regeln") or []),
        "anzahl_ziele": len(saetze.get("ziele") or {}),
        "ruhe": 1 if ruhe_aktiv(cfg)[0] else 0,
        "mitschnitt": 1 if mitschnitt_laeuft(cfg) else 0,
        "timer": timer_liste(),
        "letzter_satz": str(letzter.get("satz") or ""),
        "letztes_ergebnis": letzter,
        "letzter_satz_alter": (int(time.time()) - letzter_ts) if letzter_ts else -1,
        "ziele": {k: {"name": (z.get("name", k) if isinstance(z, dict) else k),
                      "thema": (z.get("thema", k) if isinstance(z, dict) else str(z))}
                  for k, z in (saetze.get("ziele") or {}).items()},
        "verlauf": verlauf[:10],
    }
    # Nur schreiben, wenn sich etwas geaendert hat - oder wenn der letzte
    # Schreibvorgang lange her ist. Bei einem Takt von 1 s waeren es sonst
    # 86 400 Schreibvorgaenge am Tag auf die SD-Karte (data/plugins liegt
    # dort, nur log/plugins ist eine Ramdisk). Der Zwangsdurchgang haelt
    # den Zeitstempel frisch, den die Oberflaeche fuer ihre Altersanzeige
    # liest - ohne ihn saehe ein ruhiges Haus aus wie ein toter Dienst.
    fingerabdruck = json.dumps({k: v for k, v in daten.items() if k != "ts"},
                               sort_keys=True, default=str)
    jetzt = time.monotonic()
    if (fingerabdruck != _ABBILD_STAND.get("fingerabdruck")
            or jetzt - _ABBILD_STAND.get("zeit", 0.0) >= 15.0):
        _ABBILD_STAND["fingerabdruck"] = fingerabdruck
        _ABBILD_STAND["zeit"] = jetzt
        json_schreiben(DATEI_LOXONE, daten)
    return daten


def herzschlag(cfg: dict, abbild: dict) -> None:
    """Dieselben Werte wie die Statuszeile, aber ueber MQTT - und ohne Anlass.

    Bis 0.9.11 ging ueber MQTT nur etwas hinaus, wenn jemand sprach. Wer der
    Hausempfehlung folgt und MQTT als Regelweg nimmt, verlor damit die
    komplette Ausfallerkennung: ein totes Mikrofon war von einem stillen Haus
    nicht zu unterscheiden.
    """
    if not cfg.get("mqtt_ein"):
        return
    mqtt_senden({
        "online": 1,
        "ts": abbild["ts"],
        "mikrofone": abbild["anzahl_mikrofone"],
        "bereit": abbild["bereit"],
        "dienste_ok": abbild["dienste_ok"],
        "dienste_gesamt": abbild["dienste_gesamt"],
        "regeln": abbild["anzahl_regeln"],
        "ziele": abbild["anzahl_ziele"],
        "ruhe": abbild["ruhe"],
        "letzter_satz_alter": abbild["letzter_satz_alter"],
    }, praefix_von(cfg), cfg)


# ---------------------------------------------------------------------------
# Dienst
# ---------------------------------------------------------------------------
def signal_behandeln(*_):
    global _LAUF
    _LAUF = False
    _LOG.info("Beendigungssignal erhalten - Dienst haelt an.")


_SATZSTAND: dict = {"v": None, "stempel": None}


def satzstand_pruefen():
    """Die Satzdatei neu lesen, sobald sie sich geaendert hat."""
    try:
        stempel = DATEI_SAETZE.stat().st_mtime
    except OSError:
        stempel = 0
    if stempel != _SATZSTAND["stempel"]:
        _SATZSTAND["stempel"] = stempel
        _SATZSTAND["v"] = verstehen_laden()
        for sat in SATELLITEN.values():
            sat.v = _SATZSTAND["v"]
    return _SATZSTAND["v"]


def satelliten_schluessel(cfg: dict) -> str:
    """Ein Fingerabdruck der Mikrofonliste - fuer 'hat sich etwas geaendert?'."""
    return json.dumps(cfg.get("satelliten") or [], sort_keys=True, ensure_ascii=False)


async def dienst() -> int:
    cfg = config()
    fehlten = cfg_vervollstaendigen()
    if fehlten:
        cfg = config()
    v = satzstand_pruefen()
    if v is not None:
        for beanstandung in v.pruefen():
            _LOG.warning("Satzdatei: %s", beanstandung)
    _LOG.info("Dienst startet: %d Mikrofon(e), Sprachmodell %s, Antwort %s.",
              len(cfg.get("satelliten") or []),
              "ein" if cfg.get("llm_ein") else "aus",
              "gesprochen" if cfg.get("antwort_sprechen") else "still")

    aufgaben: list = []
    letzte_mikros = None

    def mikrofone_aufsetzen(c):
        """Aufgaben fuer die eingetragenen Mikrofone anlegen."""
        for a in aufgaben:
            a.cancel()
        aufgaben.clear()
        SATELLITEN.clear()
        ESPHOME.clear()
        for eintrag in c.get("satelliten") or []:
            if not isinstance(eintrag, dict) or not eintrag.get("host"):
                continue
            if str(eintrag.get("art") or "wyoming") == "esphome":
                aufgaben.append(asyncio.ensure_future(
                    esphome_betreuen(eintrag, c, lambda: _SATZSTAND["v"])))
            else:
                aufgaben.append(asyncio.ensure_future(
                    satellit_betreuen(eintrag, c, lambda: _SATZSTAND["v"])))
        if not aufgaben:
            _LOG.warning("Es ist kein Mikrofon eingetragen. Der Dienst laeuft trotzdem - "
                         "der Reiter Test kann Saetze auch ohne Mikrofon durchschicken.")

    letzter_herzschlag = 0.0
    while _LAUF:
        if not (PDATA / "soll_laufen").is_file():
            _LOG.info("Der Merker soll_laufen ist weg - Dienst haelt an.")
            break
        cfg = config()
        v = satzstand_pruefen()

        # Mikrofone ohne Neustart uebernehmen. Bis 0.9.11 stand in der
        # Oberflaeche 'Der Dienst uebernimmt sie beim naechsten Neustart' -
        # obwohl die Schleife die Konfiguration ohnehin jede Sekunde liest.
        schluessel = satelliten_schluessel(cfg)
        if schluessel != letzte_mikros:
            if letzte_mikros is not None:
                _LOG.info("Die Mikrofonliste hat sich geaendert - Verbindungen neu.")
            letzte_mikros = schluessel
            mikrofone_aufsetzen(cfg)

        try:
            await warteschlange(cfg, lambda: _SATZSTAND["v"])
        except Exception as err:  # noqa: BLE001
            _LOG.error("Warteschlange: %s", fehlertext(err))
        try:
            timer_faellig(cfg)
        except Exception as err:  # noqa: BLE001
            _LOG.error("Vorgemerkte Befehle: %s", fehlertext(err))

        abbild = abbild_schreiben(cfg)
        takt = int(cfg.get("herzschlag_s") or 0)
        if takt > 0 and time.time() - letzter_herzschlag >= takt:
            letzter_herzschlag = time.time()
            try:
                herzschlag(cfg, abbild)
            except Exception as err:  # noqa: BLE001
                melde_gebremst("herzschlag", "Herzschlag: " + fehlertext(err))
        await asyncio.sleep(1)

    for aufgabe in aufgaben:
        aufgabe.cancel()
    # Abwarten, nicht nur abbrechen: sonst endet asyncio.run, waehrend die
    # finally-Bloecke der Satelliten noch laufen - und die schliessen die
    # Verbindungen.
    if aufgaben:
        try:
            await asyncio.wait_for(
                asyncio.gather(*aufgaben, return_exceptions=True), timeout=10)
        except asyncio.TimeoutError:
            _LOG.warning("Nicht alle Aufgaben haben binnen 10 s aufgehoert.")
    if config().get("mqtt_ein"):
        # Ein Abschied, damit ein bewusst angehaltener Dienst nicht wie ein
        # abgestuerzter aussieht.
        try:
            mqtt_senden({"online": 0, "ts": int(time.time())}, praefix_von(config()))
        except Exception:  # noqa: BLE001
            pass
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

    # Vorgabenliste: EINE Datei, und beide Seiten muessen sie finden.
    if not VORGABEN:
        fehler += 1
        zeilen.append("[FEHL] templates/vorgaben.json wurde nicht gefunden - ohne sie "
                      "kennt der Dienst keine Vorgabewerte.")
    else:
        roh = json_lesen(DATEI_CONFIG)
        fehlend = [k for k in VORGABEN if k not in roh]
        if fehlend:
            zeilen.append("[INFO] Konfiguration unvollstaendig: %d von %d Schluesseln, "
                          "es fehlen %s (gelesen wird die Vorgabe; beim naechsten "
                          "Dienststart werden sie ergaenzt)."
                          % (len(VORGABEN) - len(fehlend), len(VORGABEN),
                             ", ".join(sorted(fehlend)[:8])))
        else:
            zeilen.append("[OK]   Konfiguration vollstaendig: %d von %d Schluesseln"
                          % (len(VORGABEN), len(VORGABEN)))

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
            name = eintrag.get('name') or host
            if art == "esphome":
                # Ein offener Port ist bei ESPHome KEIN Beleg dafuer, dass der
                # Audioweg traegt - das ist der ungepruefte Teil des Plugins.
                # Bis 0.9.11 stand hier ein gruener Haken.
                zeilen.append(("[INFO] " if ok else "[FEHL] ")
                              + f"Mikrofon {name} (esphome) auf {host}:{port}"
                              + (" antwortet - ob der Audioweg traegt, ist damit NICHT "
                                 "gesagt (ungeprueft)" if ok else f" - {grund}"))
            else:
                zeilen.append(("[OK]   " if ok else "[FEHL] ")
                              + f"Mikrofon {name} (wyoming) auf {host}:{port}"
                              + ("" if ok else f" - {grund}"))
            if not ok:
                fehler += 1
            if str(eintrag.get("raum") or "") and v is not None:
                if v.ziel_finden(str(eintrag["raum"])) is None:
                    fehler += 1
                    zeilen.append("[FEHL] Mikrofon %s: der eingetragene Raum %r steht "
                                  "nicht in der Zielliste - 'mach an' geht dort ins Leere."
                                  % (name, eintrag["raum"]))

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
        # Satzproben: die Muster gegen Beispielsaetze fahren, ohne zu schalten.
        proben = satzproben(v)
        if proben["fehl"]:
            fehler += len(proben["fehl"])
            for p in proben["fehl"]:
                zeilen.append("[FEHL] Satzprobe: " + p)
        else:
            zeilen.append("[OK]   Satzproben: %d von %d getroffen"
                          % (proben["ok"], proben["gesamt"]))

    m = mqtt_zustand()
    if not m["gefunden"]:
        fehler += 1
        zeilen.append("[FEHL] Kein MQTT-Abschnitt in der general.json des LoxBerry")
    elif m["autostart"]:
        zeilen.append("[OK]   MQTT-Gateway auf Autostart, UDP-Eingang %d, Fassung %s"
                      % (m["udpport"], m["fassung"] or "unbekannt"))
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
        praefix = praefix_von(cfg)
        zeilen.append("[OK]   Antworttext geht nach %s/antwort, das Ergebnis nach %s/ok, "
                      "der Grund nach %s/grund" % (praefix, praefix, praefix))
        takt = int(cfg.get("herzschlag_s") or 0)
        if takt > 0:
            zeilen.append("[OK]   Herzschlag alle %d s nach %s/online" % (takt, praefix))
        else:
            zeilen.append("[INFO] Der Herzschlag ist abgeschaltet - ein toter Dienst ist "
                          "ueber MQTT dann nicht von einem stillen Haus zu unterscheiden.")
    else:
        zeilen.append("[INFO] MQTT ist abgeschaltet - der Antworttext erreicht die Visu nicht.")

    still, grund = ruhe_aktiv(cfg)
    if cfg.get("ruhe", {}).get("ein"):
        zeilen.append(("[INFO] Ruhezeit %s bis %s - gerade %s."
                       % (cfg["ruhe"]["von"], cfg["ruhe"]["bis"],
                          "AKTIV, es wird nichts angesagt" if still else "nicht aktiv")))
    else:
        zeilen.append("[INFO] Keine Ruhezeit eingestellt - eine Ansage kann zu jeder "
                      "Tages- und Nachtzeit kommen.")
    if int(cfg.get("ansage_abstand_s") or 0) or int(cfg.get("ansage_je_tag") or 0):
        zeilen.append("[OK]   Wiederholungsbremse: mindestens %d s Abstand, hoechstens "
                      "%s Ansagen am Tag"
                      % (int(cfg.get("ansage_abstand_s") or 0),
                         int(cfg.get("ansage_je_tag") or 0) or "unbegrenzt"))
    else:
        zeilen.append("[INFO] Keine Wiederholungsbremse - ein Loxone-Baustein in einer "
                      "Schleife kann beliebig viele Ansagen ausloesen.")

    if weg in ("loxone", "beide"):
        tts = cfg.get("tts") or {}
        probe = "Ich habe das Licht im Wohnzimmer auf 50 Prozent gestellt."
        url = loxone_tts_url(tts, probe)
        if url is None:
            if cfg.get("mqtt_ein"):
                zeilen.append("[OK]   Ansage im Modus 'Originaler Loxone Audioserver': der "
                              "Text geht nach %s/ansage. In Loxone Config an den "
                              "Textgenerator am TTS-Eingang legen." % praefix_von(cfg))
            else:
                fehler += 1
                zeilen.append("[FEHL] Modus 'Originaler Loxone Audioserver' und MQTT "
                              "abgeschaltet - der Text erreicht niemanden.")
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

    if mitschnitt_laeuft(cfg):
        zeilen.append("[INFO] Der Mitschnitt laeuft noch %d s und schaltet sich dann "
                      "selbst ab: %s"
                      % (int(cfg["mitschnitt_bis"] - time.time()), DATEI_MITSCHNITT))

    zeilen.append("")
    zeilen.append("Nicht geprueft, weil dafuer echte Hardware noetig ist:")
    zeilen.append("  - ob ein Mikrofon Audio liefert, das Whisper versteht")
    zeilen.append("  - ob das Weckwort in Ihrem Raum zuverlaessig anspricht")
    zeilen.append("  - ob ESPHome-Mikrofone den Audioweg tragen (der am wenigsten")
    zeilen.append("    gepruefte Teil dieses Plugins)")
    print("\n".join(zeilen))
    return 1 if fehler else 0


def satzproben(v) -> dict:
    """Jede Regel gegen einen erzeugten Beispielsatz fahren.

    Es wird NICHTS geschaltet. Geprueft wird zweierlei: ob der Satz ueberhaupt
    trifft - und ob DIESELBE Regel trifft, aus der er gebaut wurde.

    Der zweite Teil ist der wichtigere, und er fehlte im ersten Anlauf. Beim
    Probelauf am 24.08.2026 verschluckte
        [schalte|mach] {ziel} [aus|ab]
    den Satz 'mach das wohnzimmer in 10 minuten aus', weil {ziel} auch
    'das wohnzimmer in 10 minuten' fassen kann und ziel_finden darin
    'wohnzimmer' findet. Der vorgemerkte Befehl wurde damit SOFORT
    ausgefuehrt - und die Probe war trotzdem gruen, weil irgendetwas getroffen
    hatte. Eine Pruefung, die nur 'es trifft' misst, beruhigt.
    """
    ok = 0
    fehl = []
    gesamt = 0
    beispielziel = next((z for z in v.ziele.values() if z["namen"]), None)
    for _, regel in v.regeln:
        muster = str(regel.get("muster") or "")
        if not muster:
            continue
        gesamt += 1
        satz = muster
        # Aus dem Muster einen Satz bauen: erste Alternative, Platzhalter mit
        # brauchbaren Werten.
        satz = re.sub(r"\[([^\]]*)\]", lambda t: t.group(1).split("|")[0], satz)
        satz = satz.replace("{ziel}", beispielziel["namen"][0] if beispielziel else "x")
        satz = satz.replace("{wert}", "50").replace("{dauer}", "10 minuten")
        satz = satz.replace("{rest}", "irgendwas")
        satz = re.sub(r"\{[a-z]+\}", "irgendwas", satz)
        satz = re.sub(r"\s+", " ", satz).strip()
        if not satz:
            continue
        erg = v.erkennen(satz)
        if not (erg.get("ok") or erg.get("grund") in ("ziel_unbekannt", "ziel_fehlt")):
            fehl.append("%r ergibt %r -> %s" % (muster, satz, erg.get("grund")))
            continue
        getroffen = str(erg.get("muster") or "")
        if getroffen and getroffen != muster:
            fehl.append("%r wird von %r verdeckt (Probesatz %r) - die Regel kommt "
                        "nie zum Zug. Das genauere Muster gehoert nach OBEN."
                        % (muster, getroffen, satz))
            continue
        ok += 1
    return {"ok": ok, "gesamt": gesamt, "fehl": fehl}


def main() -> int:
    log_einrichten()
    if "--selbsttest" in sys.argv:
        return selbsttest()
    for schalter, trocken in (("--satz", False), ("--trocken", True)):
        if schalter in sys.argv:
            i = sys.argv.index(schalter)
            satz = sys.argv[i + 1] if len(sys.argv) > i + 1 else ""
            raum = ""
            if "--raum" in sys.argv:
                j = sys.argv.index("--raum")
                raum = sys.argv[j + 1] if len(sys.argv) > j + 1 else ""
            _SATZSTAND["v"] = verstehen_laden()
            erg = satz_verarbeiten(satz, config(), _SATZSTAND["v"],
                                   "", raum, "", trocken)
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
