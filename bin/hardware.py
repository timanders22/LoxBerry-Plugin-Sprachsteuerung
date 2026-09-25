#!/usr/bin/env python3
"""Hardwareerkennung, Modellempfehlung und Messung.

WARUM MESSEN STATT BEHAUPTEN
----------------------------
Wie schnell Whisper auf einem Raspberry Pi ist, haengt an CPU, Takt, Kuehlung,
Speicherbandbreite und der Frage, ob gerade noch etwas anderes laeuft. Jede
Sekundenangabe in einer Anleitung ist deshalb bestenfalls eine Hausnummer. Diese
Datei enthaelt darum KEINE Geschwindigkeitsangaben - sie misst statt dessen auf
der Maschine, auf der sie laeuft.

Gemessen wird mit einem selbst erzeugten Pruefton fester Laenge. Das Ergebnis
ist die Zeit, die der Weg vom Audio bis zum Text tatsaechlich braucht.

Aufrufe:
    hardware.py              Hardware und Empfehlung als JSON
    hardware.py --klartext   dasselbe als lesbarer Text
    hardware.py --messen     zusaetzlich die Dienste messen (braucht Container)
"""

from __future__ import annotations

import json
import os
import platform
import re
import shutil
import socket
import struct
import subprocess
import sys
import time
from pathlib import Path


def lb_wurzel_ermitteln():
    """Den LoxBerry-Wurzelordner ohne festen Systempfad bestimmen.

    Vom eigenen Ablageort aufwaerts, bis ein Verzeichnis gefunden ist, das
    config/plugins, webfrontend UND config/system/general.json enthaelt.
    Trifft die uebliche Installation genauso wie eine an einem anderen Ort.

    general.json unterscheidet einen LoxBerry von einem Rest aus
    Pruefstaenden: ein LoxBerry hat sie immer, ein solcher Rest nie
    (Regeln/06). Ohne sie galt ein fremder Baum mit config/plugins und
    webfrontend als Wurzel (gemessen am 18.09.2026 in WSL,
    Pruefung-Sprachsteuerung-0.11.9, messe_nachtrag2.sh, Faelle W1-W7).
    """
    d = os.path.dirname(os.path.abspath(__file__))
    for _ in range(8):
        if os.path.isdir(os.path.join(d, "config", "plugins")) \
                and os.path.isdir(os.path.join(d, "webfrontend")) \
                and os.path.isfile(os.path.join(d, "config", "system", "general.json")):
            return d
        eltern = os.path.dirname(d)
        if eltern == d:
            break
        d = eltern
    return ""


SELF = Path(__file__).resolve().parent
PNAME = SELF.name


def _wurzel_pruefen(k) -> bool:
    """Ist das wirklich eine LoxBerry-Wurzel? Siehe sprachsteuerung_dienst.py.

    Bis 0.10.1 war die Bedingung 'len(SELF.parents) >= 3' - auf jedem realen
    Pfad wahr. Aus dem entpackten Archiv heraus ergab das PNAME='bin' und
    eine Konfiguration, die es nicht gibt: gemessen wurde dann wieder gegen
    127.0.0.1, also genau die Fehlmessung, die 0.9.7 abgestellt hatte.
    """
    try:
        return ((k / "config" / "plugins").is_dir() and (k / "webfrontend").is_dir()
                and (k / "config" / "system" / "general.json").is_file())
    except OSError:
        return False


# Die Wurzel wird GELESEN, bevor sie aus dem Ablageort gerechnet wird
# (Regeln/03: Stufe 1 ist $LBHOMEDIR). Bis 0.11.8 stand SELF.parents[2]
# an erster Stelle und gewann, sobald dort config/plugins und webfrontend
# lagen - aus einem Pruefarchiv unter <Wurzel>/pruefung/<plugin>/bin ist das
# die LAUFENDE Installation, gleichgueltig, wohin $LBHOMEDIR zeigt. Gemessen
# am 18.09.2026 in WSL (Pruefung-Sprachsteuerung-0.11.9, Faelle P1 und P4;
# Bauart H1 aus Bestand-2026-09-18/klasse-H).
#
# Die Reihenfolge zaehlt: die Auskunft von LoxBerry selbst, wenn sie eine
# Wurzel bezeichnet; dann der Ablageort, wenn er nachweislich in einer liegt;
# dann die Suche aufwaerts. Jede Stufe verlangt config/system/general.json
# (Regeln/06). Ohne Wurzel wird abgebrochen, nicht geraten: bis 0.11.8
# stand am Ende wieder die feste Zahl "..", SELF.parents[2] - aus einem
# fremden Baum oder einer Kopie heraus genau der Baum, den die Suche gerade
# abgelehnt hatte. Gemessen am 18.09.2026: der Selbsttest des Freigabetors
# legte in der Kettenkopie log/plugins/sprachsteuerung an, in WSL legte
# der Selbsttest-Aufruf aus einem fremden Baum dort log/plugins/... an
# (W1b).
# Bauart: Weissware 0.9.28 (_lbhome_ermitteln).
_umgebung = os.environ.get("LBHOMEDIR") or ""
if _umgebung and _wurzel_pruefen(Path(_umgebung)):
    LBHOME = Path(_umgebung)
elif len(SELF.parents) >= 3 and _wurzel_pruefen(SELF.parents[2]):
    LBHOME = SELF.parents[2]
else:
    _kandidat = lb_wurzel_ermitteln()
    if not _kandidat:
        sys.stderr.write(
            "FEHLER: Es wurde kein LoxBerry-Wurzelverzeichnis gefunden. "
            "LBHOMEDIR bezeichnet keine, und oberhalb von %s traegt kein "
            "Verzeichnis config/plugins, webfrontend und "
            "config/system/general.json. Es wurde nichts angelegt.\n" % SELF)
        raise SystemExit(1)
    LBHOME = Path(_kandidat)

# Aus dem entpackten Archiv heraus heisst der Ordner ueber bin/ nicht wie
# das Plugin. Dann gilt die Auskunft von LoxBerry, sonst der feste Name -
# dieselbe Regel wie in sp_paths() auf der PHP-Seite.
if PNAME in ("bin", "", ".", "/"):
    PNAME = os.environ.get("LBPPLUGINDIR") or "sprachsteuerung"

# ---------- Archiv unter einer echten Wurzel ----------
# Die Anlage gilt nur, wenn diese Datei in ihrem bin-Ordner liegt
# (<Wurzel>/bin/plugins/<ordner>, physisch verglichen) oder der Aufrufer
# Wurzel UND Ordner ausdruecklich nennt ($LBHOMEDIR und $LBPPLUGINDIR - so
# arbeiten die Pruefwerkzeuge mit ihrer Attrappe, und so ruft uninstall
# --mqtt-leeren). Dieselbe Regel wie sp_paths() in sp_lib.php und
# bin/dienst.sh. Bis 0.11.9 nahm eine Kopie aus einem ausgepackten Archiv
# unterhalb einer echten Wurzel diese Wurzel und den festen Namen
# 'sprachsteuerung': gemessen am 25.09.2026 in WSL
# (Pruefung-Sprachsteuerung-0.11.10, Faelle A8, A9, A10) schrieben
# '--satz' und 'hardware.py --messen' aus dem Archiv Protokoll, Verlauf und
# Messwerte in die Anlage - ohne Umgebung ebenso wie mit $LBHOMEDIR allein,
# wie es am Geraet in /etc/environment steht. Bauart Spotpreis-Tibber 0.9.19.
def _in_der_anlage() -> bool:
    try:
        if (LBHOME / "bin" / "plugins" / PNAME).resolve() == SELF:
            return True
    except OSError:
        pass
    lbp = os.path.basename((os.environ.get("LBPPLUGINDIR") or "").rstrip("/"))
    if lbp in ("", ".", "/", "html", "bin", "plugins") or not _umgebung:
        return False
    try:
        return Path(_umgebung).resolve() == LBHOME.resolve()
    except OSError:
        return False


if not _in_der_anlage():
    sys.stderr.write(
        "FEHLER: %s liegt nicht in der Installation unter %s (ausgepacktes "
        "Archiv oder Pruefordner). Damit nichts in die Anlage kommt, wurde "
        "nichts angelegt und nichts gesendet. Abhilfe: das Programm aus "
        "<LoxBerry-Wurzel>/bin/plugins/<ordner> aufrufen oder LBHOMEDIR und "
        "LBPPLUGINDIR ausdruecklich setzen.\n" % (SELF, LBHOME))
    raise SystemExit(1)


PTEMPLATES = LBHOME / "templates" / "plugins" / PNAME

def _fassung() -> str:
    """Die Fassungsnummer - zuerst aus der Auskunft von LoxBerry selbst.

    Bis 0.10.1 stand sie als Zahl im User-Agent und veraltete still:
    hardware.py fuehrte 0.9, waehrend die plugin.cfg 0.10.1 sagte. Der
    Rueckfall auf die plugin.cfg hat das aber nicht behoben - er hat es nur
    verdeckt: BERICHTIGT 07.09.2026, am Geraet gemessen.

    plugininstall.pl liest die plugin.cfg aus dem Auspackordner und loescht
    sie danach; installiert wird sie NIRGENDWOHIN. Auf der Anlage meldeten
    beide Dateien deshalb FASSUNG=0, waehrend die Datenbank 0.11.3 fuehrte -
    und die 0 ging in den User-Agent. Eine erfundene Nummer ist schlimmer als
    keine: sie sieht richtig aus.

    In PHP beantwortet das LBSystem::pluginversion(); Python hat das nicht,
    also wird dieselbe Datei gelesen - ueber den ORDNERNAMEN, nie ueber den
    MD5-Schluessel (der entsteht aus Autorenname, E-Mail und Plugin-Name und
    aendert sich bei jedem Fork). Die plugin.cfg bleibt als zweiter Weg
    stehen: sie traegt den Auspackordner, also den Pruefstand.
    """
    try:
        import json as _json
        _d = _json.loads((LBHOME / "data" / "system" / "plugindatabase.json")
                         .read_text(encoding="utf-8", errors="replace"))
        _liste = _d.get("plugins", _d)
        if isinstance(_liste, dict):
            _liste = list(_liste.values())
        for _e in _liste or ():
            if isinstance(_e, dict) and str(_e.get("folder", "")) == PNAME:
                _v = str(_e.get("version", "")).strip()
                if _v:
                    return _v
    except (OSError, ValueError, TypeError):
        pass
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


def tabelle() -> dict:
    # SELF ist bereits bin/; SELF.parent ist die Pluginwurzel im Archiv.
    # Ein dritter Kandidat eine Ebene darueber traf nie etwas - weder
    # installiert noch im Archiv.
    for kandidat in (PTEMPLATES / "modelle.json",
                     SELF.parent / "templates" / "modelle.json"):
        if kandidat.is_file():
            try:
                return json.loads(kandidat.read_text(encoding="utf-8"))
            except ValueError:
                pass
    return {"stufen": [], "dienste": {}}


# ---------------------------------------------------------------------------
# Hardware erkennen
# ---------------------------------------------------------------------------
def speicher_mb() -> tuple[int, int]:
    """(gesamt, verfuegbar) in Megabyte. Verfuegbar ist die ehrlichere Zahl:
    was das Betriebssystem noch hergeben kann, ohne zu swappen."""
    gesamt = verfuegbar = 0
    try:
        for zeile in Path("/proc/meminfo").read_text().splitlines():
            if zeile.startswith("MemTotal:"):
                gesamt = int(zeile.split()[1]) // 1024
            elif zeile.startswith("MemAvailable:"):
                verfuegbar = int(zeile.split()[1]) // 1024
    except (OSError, ValueError, IndexError):
        pass
    return gesamt, verfuegbar


def cpu_name() -> str:
    try:
        text = Path("/proc/cpuinfo").read_text()
    except OSError:
        return platform.processor() or "unbekannt"
    for schluessel in ("model name", "Model", "Hardware"):
        treffer = re.search(rf"^{schluessel}\s*:\s*(.+)$", text, re.M)
        if treffer:
            return treffer.group(1).strip()
    return platform.processor() or "unbekannt"


def pi_modell() -> str:
    """Der Raspberry Pi verraet sein Modell im Geraetebaum."""
    for pfad in ("/proc/device-tree/model", "/sys/firmware/devicetree/base/model"):
        try:
            return Path(pfad).read_bytes().decode("utf-8", "ignore").strip("\x00 \n")
        except OSError:
            continue
    return ""


def gpu() -> str:
    """Nur was sich ohne Rateanteil feststellen laesst."""
    if shutil.which("nvidia-smi"):
        try:
            aus = subprocess.run(["nvidia-smi", "--query-gpu=name",
                                  "--format=csv,noheader"],
                                 capture_output=True, text=True, timeout=8)
            if aus.returncode == 0 and aus.stdout.strip():
                return "NVIDIA: " + aus.stdout.strip().splitlines()[0]
        except (OSError, subprocess.SubprocessError):
            pass
    if Path("/dev/kfd").exists():
        return "AMD ROCm"
    return ""


def hardware() -> dict:
    gesamt, verfuegbar = speicher_mb()
    return {
        "architektur": platform.machine(),
        "64bit": platform.machine() in ("x86_64", "aarch64", "arm64"),
        "kerne": os.cpu_count() or 1,
        "cpu": cpu_name(),
        "pi": pi_modell(),
        "speicher_mb": gesamt,
        "frei_mb": verfuegbar,
        "gpu": gpu(),
        "kernel": platform.release(),
    }


def empfehlung(hw: dict | None = None, tab: dict | None = None) -> dict:
    """Die passende Stufe waehlen.

    Massgeblich ist der GESAMTE Speicher, nicht der freie: der freie schwankt,
    und ein Modell laedt man einmal. Ein GPU hebt eine Stufe an, weil das Modell
    dann nicht im Arbeitsspeicher rechnet.
    """
    hw = hw or hardware()
    tab = tab or tabelle()
    stufen = sorted(tab.get("stufen", []), key=lambda s: -int(s.get("ab_mb", 0)))
    if not stufen:
        return {}
    mb = int(hw.get("speicher_mb") or 0)
    gewaehlt = stufen[-1]
    for i, stufe in enumerate(stufen):
        if mb >= int(stufe.get("ab_mb", 0)):
            gewaehlt = stufe
            # Mit GPU eine Stufe hoeher, sofern es eine gibt.
            if hw.get("gpu") and i > 0:
                gewaehlt = stufen[i - 1]
            break
    erg = dict(gewaehlt)
    erg["begruendung"] = {
        "speicher_mb": mb,
        "schwelle_mb": int(gewaehlt.get("ab_mb", 0)),
        "gpu_beruecksichtigt": bool(hw.get("gpu")),
    }
    # Ohne 64 Bit laeuft kein Sprachmodell sinnvoll, und faster-whisper auch nicht.
    if not hw.get("64bit"):
        erg["warnung"] = "nicht64"
        erg["llm"] = None
    # Weniger als zwei Kerne: kein Sprachmodell vorschlagen.
    if int(hw.get("kerne") or 1) < 2:
        erg["llm"] = None
    return erg


# ---------------------------------------------------------------------------
# Messen
#
# Der Wyoming-Aufbau ist bewusst von Hand geschrieben und nicht aus dem
# Dienstmodul geholt: diese Datei soll auch dann noch laufen, wenn die
# virtuelle Umgebung fehlt. Das Format ist die JSONL-Kopfzeile aus der
# Wyoming-Spezifikation, gefolgt von den Nutzdaten.
# ---------------------------------------------------------------------------
def wy_senden(sock: socket.socket, typ: str, daten: dict | None = None,
              nutzlast: bytes | None = None) -> None:
    kopf = {"type": typ}
    if daten:
        kopf["data"] = daten
    if nutzlast:
        kopf["payload_length"] = len(nutzlast)
    sock.sendall((json.dumps(kopf) + "\n").encode("utf-8"))
    if nutzlast:
        sock.sendall(nutzlast)


def wy_lesen(datei) -> dict | None:
    zeile = datei.readline()
    if not zeile:
        return None
    kopf = json.loads(zeile.decode("utf-8"))
    laenge = int(kopf.get("data_length") or 0)
    if laenge:
        zusatz = json.loads(datei.read(laenge).decode("utf-8"))
        kopf.setdefault("data", {}).update(zusatz)
    nutz = int(kopf.get("payload_length") or 0)
    kopf["_payload"] = datei.read(nutz) if nutz else b""
    return kopf


def pruefton(sekunden: float = 3.0, rate: int = 16000) -> bytes:
    """Ein leiser Pruefton fester Laenge, 16 Bit, ein Kanal.

    Bewusst KEINE echte Sprache: was dabei herauskommt, ist gleichgueltig -
    gemessen wird die Zeit, nicht die Erkennungsguete. Ein Aufnahmeschnipsel im
    Plugin waere ausserdem eine Stimme, die niemand um Erlaubnis gefragt hat.
    """
    import math
    rahmen = int(rate * sekunden)
    daten = bytearray()
    for i in range(rahmen):
        # Zwei ueberlagerte Toene in Sprachlage, damit es nicht nur Stille ist
        wert = int(2500 * math.sin(2 * math.pi * 220 * i / rate)
                   + 1200 * math.sin(2 * math.pi * 480 * i / rate))
        daten += struct.pack("<h", max(-32768, min(32767, wert)))
    return bytes(daten)


def messen_whisper(host: str, port: int, sekunden: float = 3.0) -> dict:
    """Misst den Weg Audio -> Text. Rueckgabe: Zeit in Sekunden oder Fehler."""
    audio = pruefton(sekunden)
    schnipsel = 1024 * 2 * 2      # 2048 Rahmen zu je 2 Byte
    try:
        with socket.create_connection((host, port), timeout=10) as s:
            datei = s.makefile("rb")
            t0 = time.monotonic()
            wy_senden(s, "transcribe", {"language": "de"})
            wy_senden(s, "audio-start", {"rate": 16000, "width": 2, "channels": 1})
            for i in range(0, len(audio), schnipsel):
                wy_senden(s, "audio-chunk",
                          {"rate": 16000, "width": 2, "channels": 1},
                          audio[i:i + schnipsel])
            wy_senden(s, "audio-stop", {})
            s.settimeout(180)
            while True:
                ereignis = wy_lesen(datei)
                if ereignis is None:
                    return {"ok": 0, "fehler": "Verbindung wurde ohne Antwort geschlossen."}
                if ereignis.get("type") == "transcript":
                    return {"ok": 1, "sekunden": round(time.monotonic() - t0, 2),
                            "audio_sekunden": sekunden,
                            "text": (ereignis.get("data") or {}).get("text", "")}
    except OSError as err:
        return {"ok": 0, "fehler": str(err)}
    except ValueError as err:
        return {"ok": 0, "fehler": "Antwort war kein gueltiges JSON: " + str(err)}


def messen_piper(host: str, port: int, text: str = "Das Licht im Wohnzimmer ist eingeschaltet.") -> dict:
    """Misst den Weg Text -> Audio."""
    try:
        with socket.create_connection((host, port), timeout=10) as s:
            datei = s.makefile("rb")
            t0 = time.monotonic()
            wy_senden(s, "synthesize", {"text": text})
            s.settimeout(180)
            bytes_gesamt = 0
            rate = 22050
            while True:
                ereignis = wy_lesen(datei)
                if ereignis is None:
                    return {"ok": 0, "fehler": "Verbindung wurde ohne Antwort geschlossen."}
                typ = ereignis.get("type")
                if typ == "audio-start":
                    rate = int((ereignis.get("data") or {}).get("rate") or 22050)
                elif typ == "audio-chunk":
                    bytes_gesamt += len(ereignis.get("_payload") or b"")
                elif typ == "audio-stop":
                    dauer = bytes_gesamt / (rate * 2) if rate else 0
                    return {"ok": 1, "sekunden": round(time.monotonic() - t0, 2),
                            "audio_sekunden": round(dauer, 2), "zeichen": len(text)}
    except OSError as err:
        return {"ok": 0, "fehler": str(err)}
    except ValueError as err:
        return {"ok": 0, "fehler": "Antwort war kein gueltiges JSON: " + str(err)}


def messen_llm(host: str, port: int, frage: str = "Antworte mit genau einem Wort: ja") -> dict:
    """Misst den Weg Frage -> Antwort ueber die OpenAI-vertraegliche
    Schnittstelle von llama.cpp."""
    import urllib.error
    import urllib.request
    koerper = json.dumps({
        "messages": [{"role": "user", "content": frage}],
        "max_tokens": 24, "temperature": 0,
    }).encode("utf-8")
    anfrage = urllib.request.Request(
        f"http://{host}:{port}/v1/chat/completions", data=koerper,
        headers={"Content-Type": "application/json",
                 "User-Agent": "LoxBerry-Sprachsteuerung-Plugin/" + FASSUNG,
                 "Accept": "application/json"})
    t0 = time.monotonic()
    try:
        with urllib.request.urlopen(anfrage, timeout=180) as antwort:
            d = json.loads(antwort.read().decode("utf-8"))
    except urllib.error.URLError as err:
        return {"ok": 0, "fehler": str(err.reason)}
    except (OSError, ValueError) as err:
        return {"ok": 0, "fehler": str(err)}
    dauer = round(time.monotonic() - t0, 2)
    text = ""
    try:
        text = d["choices"][0]["message"]["content"]
    except (KeyError, IndexError, TypeError):
        # Eine Antwort ist kein Lebenszeichen. Bis 0.10.1 meldete diese
        # Funktion ok=1 mit leerem Text und 0 Tokens, wenn die Gegenstelle
        # etwas anderes als eine Chat-Antwort schickte - ein gruener Haken
        # fuer einen Dienst, der nicht antwortet.
        return {"ok": 0, "fehler": "Die Antwort enthaelt keinen Text "
                                   "(choices[0].message.content fehlt)."}
    tokens = ((d.get("usage") or {}).get("completion_tokens")) or 0
    return {"ok": 1, "sekunden": dauer, "tokens": tokens,
            "tokens_je_sekunde": round(tokens / dauer, 1) if dauer > 0 and tokens else None,
            "text": text.strip()[:80]}


def klartext(hw: dict, emp: dict) -> str:
    z = []
    z.append("Erkannte Hardware")
    z.append("  Architektur : %s%s" % (hw["architektur"], "" if hw["64bit"] else "  (NICHT 64 Bit)"))
    if hw["pi"]:
        z.append("  Modell      : %s" % hw["pi"])
    z.append("  CPU         : %s (%d Kerne)" % (hw["cpu"], hw["kerne"]))
    z.append("  Speicher    : %d MB gesamt, %d MB frei" % (hw["speicher_mb"], hw["frei_mb"]))
    z.append("  Grafik      : %s" % (hw["gpu"] or "keine erkannt"))
    z.append("")
    if not emp:
        z.append("Keine Empfehlungstabelle gefunden.")
        return "\n".join(z)
    z.append("Vorgeschlagene Stufe: %s" % emp.get("name"))
    z.append("  Spracherkennung : Whisper %s  (%d MB)"
             % (emp["whisper"]["modell"], emp["whisper"]["datei_mb"]))
    z.append("  Sprachausgabe   : Piper %s  (%d MB)"
             % (emp["piper"]["stimme"], emp["piper"]["datei_mb"]))
    if emp.get("llm"):
        z.append("  Sprachmodell    : %s  (%d MB)"
                 % (emp["llm"]["modell"], emp["llm"]["datei_mb"]))
    else:
        z.append("  Sprachmodell    : keines - diese Maschine ist dafuer zu klein.")
        z.append("                    Das ist kein Mangel: fuer 'Licht an' sind Satzmuster")
        z.append("                    schneller und verlaesslicher als jedes Sprachmodell.")
    z.append("")
    z.append("Wie schnell das auf DIESER Maschine ist, sagt keine Tabelle - das misst")
    z.append("der Reiter Test, sobald die Dienste laufen.")
    return "\n".join(z)


def konfiguration() -> dict:
    """Die Plugin-Konfiguration lesen, soweit fuer das Messen noetig.

    WARUM UEBERHAUPT
    ----------------
    Bis 0.9.7 mass diese Datei fest gegen 127.0.0.1. Wer Whisper oder das
    Sprachmodell auf einen kraeftigeren Rechner ausgelagert hatte - wofuer die
    Felder whisper_host, piper_host und llm_host ausdruecklich da sind -, bekam
    hier eine Fehlmessung des LEEREN LoxBerry statt einer Messung des Dienstes,
    den er tatsaechlich benutzt. Gemessen wird jetzt dort, wo der Dienst laeuft.
    """
    pfad = LBHOME / "config" / "plugins" / PNAME / "sprachsteuerung.json"
    try:
        d = json.loads(pfad.read_text(encoding="utf-8"))
        return d if isinstance(d, dict) else {}
    except (OSError, ValueError):
        return {}


def ziel(cfg: dict, dienst: str, vorgabe_port: int) -> tuple[str, int]:
    """Adresse und Port eines Dienstes. Der Wortwecker heisst in der
    Konfiguration 'wake', nicht 'wakeword' - dieselbe Abbildung wie in
    sp_dienst_ziel() in webfrontend/html/sp_lib.php."""
    schluessel = "wake" if dienst == "wakeword" else dienst
    host = str(cfg.get(schluessel + "_host") or "").strip() or "127.0.0.1"
    try:
        port = int(cfg.get(schluessel + "_port") or 0)
    except (TypeError, ValueError):
        port = 0
    if not 1 <= port <= 65535:
        port = vorgabe_port
    return host, port


def messen_wake(host: str, port: int) -> dict:
    """Antwortet der Wortwecker, und welche Weckwoerter kennt er?

    Gemessen wird hier bewusst KEINE Erkennungsguete - ob ein Weckwort in
    einem bestimmten Raum anspringt, entscheidet die Raumakustik und nicht
    dieses Skript. Gemessen wird, ob der Dienst antwortet, wie lange er dafuer
    braucht und welche Modelle er fuehrt. Letzteres beantwortet die Frage, an
    der ein Vertipper im Weckwort bisher unbemerkt blieb.

    Bis 0.9.11 wurde der Wortwecker gar nicht gemessen: drei von vier
    verwalteten Diensten standen im Ergebnis, der vierte fehlte.
    """
    try:
        with socket.create_connection((host, port), timeout=10) as s:
            datei = s.makefile("rb")
            t0 = time.monotonic()
            wy_senden(s, "describe", {})
            s.settimeout(30)
            while True:
                ereignis = wy_lesen(datei)
                if ereignis is None:
                    return {"ok": 0, "fehler": "Verbindung wurde ohne Antwort geschlossen."}
                if ereignis.get("type") == "info":
                    daten = ereignis.get("data") or {}
                    namen = []
                    for eintrag in (daten.get("wake") or []):
                        if not isinstance(eintrag, dict):
                            continue
                        for m in (eintrag.get("models") or []):
                            if isinstance(m, dict) and m.get("name"):
                                namen.append(str(m["name"]))
                    return {"ok": 1, "sekunden": round(time.monotonic() - t0, 2),
                            "weckwoerter": sorted(set(namen))}
    except OSError as err:
        return {"ok": 0, "fehler": str(err)}
    except ValueError as err:
        return {"ok": 0, "fehler": "Antwort war kein gueltiges JSON: " + str(err)}


def messwerte_ablegen(messung: dict) -> str:
    """Die Messung behalten, damit sich vorher und nachher vergleichen laesst.

    Bis 0.9.11 wurde das Ergebnis angezeigt und dann verworfen. Nach einem
    Modellwechsel liess sich nicht mehr sagen, ob es schneller geworden ist -
    und genau dafuer misst man.
    """
    pfad = LBHOME / "data" / "plugins" / PNAME / "messwerte.json"
    try:
        pfad.parent.mkdir(parents=True, exist_ok=True)
        alt = {}
        if pfad.is_file():
            try:
                alt = json.loads(pfad.read_text(encoding="utf-8"))
            except ValueError:
                alt = {}
        liste = alt.get("messungen") if isinstance(alt.get("messungen"), list) else []
        liste.insert(0, {"ts": int(time.time()), "messung": messung})
        tmp = pfad.with_suffix(".json.tmp.%d" % os.getpid())
        tmp.write_text(json.dumps({"messungen": liste[:20]},
                                  ensure_ascii=False, indent=1), encoding="utf-8")
        os.replace(tmp, pfad)
        return str(pfad)
    except OSError as err:
        return "nicht abgelegt: %s" % err


def main() -> int:
    hw = hardware()
    emp = empfehlung(hw)
    if "--klartext" in sys.argv:
        print(klartext(hw, emp))
        return 0
    erg = {"hardware": hw, "empfehlung": emp}
    if "--messen" in sys.argv:
        tab = tabelle()
        d = tab.get("dienste", {})
        cfg = konfiguration()
        messung = {}
        for dienst, messer, vorgabe in (("whisper", messen_whisper, 10300),
                                        ("piper", messen_piper, 10200),
                                        ("wakeword", messen_wake, 10400),
                                        ("llm", messen_llm, 8080)):
            host, port = ziel(cfg, dienst, int(d.get(dienst, {}).get("port") or vorgabe))
            wert = messer(host, port)
            # Wo gemessen wurde, gehoert ins Ergebnis: sonst sieht eine
            # Messung von einem anderen Rechner genauso aus wie eine hiesige.
            wert["host"] = host
            wert["port"] = port
            wert["ausgelagert"] = host not in ("127.0.0.1", "localhost", "::1", "0.0.0.0")
            messung[dienst] = wert
        erg["messung"] = messung
        erg["abgelegt"] = messwerte_ablegen(messung)
    print(json.dumps(erg, ensure_ascii=False, indent=1))
    return 0


if __name__ == "__main__":
    sys.exit(main())
