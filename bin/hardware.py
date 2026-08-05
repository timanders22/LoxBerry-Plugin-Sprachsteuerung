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

SELF = Path(__file__).resolve().parent
PNAME = SELF.name
if len(SELF.parents) >= 3:
    LBHOME = SELF.parents[2]
else:
    LBHOME = Path(os.environ.get("LBHOMEDIR") or "/opt/loxberry")
PTEMPLATES = LBHOME / "templates" / "plugins" / PNAME


def tabelle() -> dict:
    for kandidat in (PTEMPLATES / "modelle.json",
                     SELF.parent.parent / "templates" / "modelle.json",
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
                 "User-Agent": "LoxBerry-Sprachsteuerung-Plugin/0.9",
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
        pass
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
        erg["messung"] = {
            "whisper": messen_whisper("127.0.0.1", int(d.get("whisper", {}).get("port") or 10300)),
            "piper": messen_piper("127.0.0.1", int(d.get("piper", {}).get("port") or 10200)),
            "llm": messen_llm("127.0.0.1", int(d.get("llm", {}).get("port") or 8080)),
        }
    print(json.dumps(erg, ensure_ascii=False, indent=1))
    return 0


if __name__ == "__main__":
    sys.exit(main())
