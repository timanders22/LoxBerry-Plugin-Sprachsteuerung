#!/usr/bin/env python3
"""Satzmuster erkennen und in eine Absicht uebersetzen.

WARUM SATZMUSTER UND NICHT GLEICH EIN SPRACHMODELL
--------------------------------------------------
Fuer 'Licht an' ist ein Sprachmodell die schlechtere Wahl: es braucht auf einem
kleinen Rechner Sekunden, wo ein Mustervergleich Millisekunden braucht, und es
kann sich irren. Ein Mustervergleich kann das nicht - er trifft oder er trifft
nicht. Deshalb: erst Muster, und nur was nicht passt, geht an das Sprachmodell.

Genau so macht es auch Home Assistant.

Aufruf zum Ausprobieren:
    verstehen.py "schalte das licht im wohnzimmer ein"
"""

from __future__ import annotations

import os

import json
import re
import sys
import unicodedata
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


# ---------------------------------------------------------------------------
# Einebnen
#
# Gesprochenes kommt aus der Spracherkennung mal mit, mal ohne Umlaute, mit
# Satzzeichen und in wechselnder Gross- und Kleinschreibung. Verglichen wird
# deshalb auf einer eingeebneten Fassung. Der ANGEZEIGTE Text bleibt unberuehrt.
# ---------------------------------------------------------------------------
_UMLAUTE = {"ä": "ae", "ö": "oe", "ü": "ue", "ß": "ss",
            "Ä": "ae", "Ö": "oe", "Ü": "ue"}


def einebnen(text: str) -> str:
    text = (text or "").strip().lower()
    for a, b in _UMLAUTE.items():
        text = text.replace(a, b)
    # Alles, was kein Buchstabe, keine Ziffer und kein Leerzeichen ist, faellt weg.
    text = unicodedata.normalize("NFKD", text)
    text = "".join(c for c in text if not unicodedata.combining(c))
    text = re.sub(r"[^a-z0-9 ]+", " ", text)
    return re.sub(r"\s+", " ", text).strip()


def muster_zu_regex(muster: str) -> re.Pattern:
    """Ein Muster in einen regulaeren Ausdruck uebersetzen.

    [a|b]    -> eine der Alternativen, auch leer wenn eine Alternative leer ist
    {ziel}   -> beliebiger Text (wird spaeter gegen die Zielliste geprueft)
    {wert}   -> eine Zahl
    {rest}   -> beliebiger Text
    """
    teile = []
    for stueck in re.split(r"(\[[^\]]*\]|\{[a-z]+\})", muster):
        if not stueck:
            continue
        if stueck.startswith("[") and stueck.endswith("]"):
            alternativen = [einebnen(a) for a in stueck[1:-1].split("|")]
            leer = any(a == "" for a in alternativen)
            gefuellt = [re.escape(a) for a in alternativen if a]
            if not gefuellt:
                continue
            gruppe = "(?:" + "|".join(gefuellt) + ")"
            teile.append(gruppe + ("?" if leer else ""))
        elif stueck.startswith("{") and stueck.endswith("}"):
            name = stueck[1:-1]
            if name == "wert":
                teile.append(r"(?P<wert>\d{1,4})")
            else:
                teile.append(rf"(?P<{name}>.+?)")
        else:
            teile.append(re.escape(einebnen(stueck)))
    # Zwischen den Teilen darf beliebig viel Leerraum stehen, auch keiner.
    ausdruck = r"\s*".join(t for t in teile if t)
    return re.compile(r"^\s*" + ausdruck + r"\s*$")


class Verstehen:
    """Haelt Regeln und Ziele und beantwortet Saetze."""

    def __init__(self, saetze: dict) -> None:
        self.regeln = []
        for regel in saetze.get("regeln", []) or []:
            muster = str(regel.get("muster") or "")
            if not muster:
                continue
            try:
                self.regeln.append((muster_zu_regex(muster), regel))
            except re.error as err:
                # Ein kaputtes Muster darf nicht alle anderen mitreissen.
                self.regeln.append((None, dict(regel, _fehler=str(err))))
        self.ziele = {}
        for schluessel, ziel in (saetze.get("ziele") or {}).items():
            # Kurzschreibweise zulassen: "wohnzimmer": "wohnzimmer/licht".
            # Ein Dienst darf an einer von Hand bearbeiteten Datei nicht
            # sterben - er muss sie verstehen oder benennen, was fehlt.
            if isinstance(ziel, str):
                ziel = {"thema": ziel}
            elif not isinstance(ziel, dict):
                ziel = {}
            namen = [einebnen(schluessel), einebnen(str(ziel.get("name") or ""))]
            namen += [einebnen(a) for a in (ziel.get("alias") or [])]
            self.ziele[schluessel] = {
                "schluessel": schluessel,
                "name": str(ziel.get("name") or schluessel),
                "thema": str(ziel.get("thema") or schluessel),
                "url": str(ziel.get("url") or ""),
                "namen": [n for n in namen if n],
            }

    def ziel_finden(self, text: str):
        """Das Ziel mit der laengsten passenden Bezeichnung gewinnt.

        Ohne diese Regel wuerde 'wohnzimmer' auch dann greifen, wenn
        'wohnzimmer decke' gemeint war - die laengere Uebereinstimmung ist die
        genauere.
        """
        gesucht = einebnen(text)
        if not gesucht:
            return None
        bester = None
        beste_laenge = -1
        for ziel in self.ziele.values():
            for name in ziel["namen"]:
                if name == gesucht and len(name) > beste_laenge:
                    bester, beste_laenge = ziel, len(name)
        if bester is not None:
            return bester
        # Kein genauer Treffer: enthaltene Bezeichnung zulassen, wieder die laengste.
        for ziel in self.ziele.values():
            for name in ziel["namen"]:
                if name and name in gesucht and len(name) > beste_laenge:
                    bester, beste_laenge = ziel, len(name)
        return bester

    def erkennen(self, satz: str) -> dict:
        """Rueckgabe: {'ok':1, 'absicht':..., 'ziel':..., ...} oder {'ok':0, 'grund':...}"""
        eingeebnet = einebnen(satz)
        if not eingeebnet:
            return {"ok": 0, "grund": "leer"}
        for ausdruck, regel in self.regeln:
            if ausdruck is None:
                continue
            treffer = ausdruck.match(eingeebnet)
            if not treffer:
                continue
            felder = treffer.groupdict()
            erg = {
                "ok": 1,
                "absicht": str(regel.get("absicht") or ""),
                "aktion": str(regel.get("aktion") or ""),
                "muster": str(regel.get("muster") or ""),
                "satz": satz,
                "wert": None,
                "ziel": None,
                "zielname": "",
                "thema": "",
                "url": str(regel.get("url") or ""),
            }
            if felder.get("wert") is not None:
                erg["wert"] = int(felder["wert"])
            if felder.get("ziel") is not None:
                ziel = self.ziel_finden(felder["ziel"])
                if ziel is None:
                    # Muster passt, Ziel nicht - das ist ein anderer Fall als
                    # 'nicht verstanden' und wird auch anders gemeldet.
                    return {"ok": 0, "grund": "ziel_unbekannt",
                            "gesucht": felder["ziel"].strip(),
                            "bekannt": sorted(z["name"] for z in self.ziele.values())}
                erg["ziel"] = ziel["schluessel"]
                erg["zielname"] = ziel["name"]
                erg["thema"] = ziel["thema"]
                if not erg["url"]:
                    erg["url"] = ziel["url"]
            antwort = str(regel.get("antwort") or "")
            erg["antwort"] = (antwort
                              .replace("{zielname}", erg["zielname"])
                              .replace("{wert}", "" if erg["wert"] is None else str(erg["wert"])))
            return erg
        return {"ok": 0, "grund": "kein_muster"}

    def pruefen(self) -> list:
        """Beanstandungen an den Regeln - fuer den Reiter Saetze."""
        meldungen = []
        for ausdruck, regel in self.regeln:
            if ausdruck is None:
                meldungen.append("Muster %r ist kein gueltiger Ausdruck: %s"
                                 % (regel.get("muster"), regel.get("_fehler")))
        for ziel in self.ziele.values():
            if not ziel["namen"]:
                meldungen.append("Ziel %r hat keine einzige Bezeichnung." % ziel["schluessel"])
        # Zwei Ziele mit derselben Bezeichnung: dann entscheidet der Zufall.
        gesehen = {}
        for ziel in self.ziele.values():
            for name in ziel["namen"]:
                if name in gesehen and gesehen[name] != ziel["schluessel"]:
                    meldungen.append("Die Bezeichnung %r gehoert zu zwei Zielen (%s und %s)."
                                     % (name, gesehen[name], ziel["schluessel"]))
                gesehen[name] = ziel["schluessel"]
        return meldungen


def laden(pfad: Path) -> Verstehen:
    try:
        return Verstehen(json.loads(pfad.read_text(encoding="utf-8")))
    except (OSError, ValueError):
        return Verstehen({"regeln": [], "ziele": {}})


if __name__ == "__main__":
    kandidaten = [Path(p) for p in (
        lb_wurzel_ermitteln() + "/config/plugins/sprachsteuerung/saetze.json",
        str(Path(__file__).resolve().parent.parent / "templates" / "saetze_de.json"),
    )]
    quelle = next((k for k in kandidaten if k.is_file()), kandidaten[-1])
    v = laden(quelle)
    for beanstandung in v.pruefen():
        print("[FEHL]", beanstandung)
    for satz in sys.argv[1:]:
        print(json.dumps(v.erkennen(satz), ensure_ascii=False))
