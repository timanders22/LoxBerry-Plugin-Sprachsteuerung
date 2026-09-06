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


# ---------------------------------------------------------------------------
# Zahlwoerter
#
# WARUM DAS NOETIG IST: der Ausdruck fuer {wert} nimmt Ziffern. Ob die
# Spracherkennung "50" oder "fuenfzig" liefert, entscheidet aber das
# Whisper-Modell und nicht dieses Plugin - dieselbe Anlage kann nach einem
# Modellwechsel die andere Schreibweise liefern. Ein Muster, das mal greift
# und mal nicht, ohne dass sich am Satz etwas geaendert hat, ist die
# unangenehmste Sorte Fehler.
#
# WO uebersetzt wird, steht weiter unten bei _ZAHLWORT_ALT - und es ist
# ausdruecklich NICHT der ganze Satz.
# ---------------------------------------------------------------------------
_EINER = {"null": 0, "ein": 1, "eins": 1, "eine": 1, "zwei": 2, "drei": 3,
          "vier": 4, "fuenf": 5, "sechs": 6, "sieben": 7, "acht": 8, "neun": 9,
          "zehn": 10, "elf": 11, "zwoelf": 12, "dreizehn": 13, "vierzehn": 14,
          "fuenfzehn": 15, "sechzehn": 16, "siebzehn": 17, "achtzehn": 18,
          "neunzehn": 19}
_ZEHNER = {"zwanzig": 20, "dreissig": 30, "vierzig": 40, "fuenfzig": 50,
           "sechzig": 60, "siebzig": 70, "achtzig": 80, "neunzig": 90}

# 'einundzwanzig' bis 'neunundneunzig' werden ERZEUGT statt aufgezaehlt, damit
# die Liste nicht an einer Stelle eine Luecke bekommt, die niemand bemerkt.
_ZAHLWORTE = dict(_EINER)
_ZAHLWORTE.update(_ZEHNER)
for _z, _zv in _ZEHNER.items():
    for _e, _ev in (("ein", 1), ("zwei", 2), ("drei", 3), ("vier", 4),
                    ("fuenf", 5), ("sechs", 6), ("sieben", 7), ("acht", 8),
                    ("neun", 9)):
        _ZAHLWORTE[_e + "und" + _z] = _zv + _ev
_ZAHLWORTE["hundert"] = 100
_ZAHLWORTE["einhundert"] = 100

# Bruchteile, die im Haus tatsaechlich vorkommen.
_BRUCHTEILE = {"halb": 50, "halbe": 50, "voll": 100, "ganz": 100}

# Beugungen, die NUR vor einer Zeiteinheit vorkommen ('in einer Minute').
_DAUER_EINS = {"einer": 1, "einem": 1, "eine": 1, "ein": 1}

# Englische Zahlwoerter - fuer templates/saetze_en.json. Sie stehen in
# derselben Liste, weil ein Satz entweder deutsch oder englisch ist und sich
# die Woerter nicht ueberschneiden; 'one' ist kein deutsches Wort und
# 'zwei' keines im Englischen.
_ZAHLWORTE.update({
    "zero": 0, "one": 1, "two": 2, "three": 3, "four": 4, "five": 5,
    "six": 6, "seven": 7, "eight": 8, "nine": 9, "ten": 10, "eleven": 11,
    "twelve": 12, "thirteen": 13, "fourteen": 14, "fifteen": 15,
    "sixteen": 16, "seventeen": 17, "eighteen": 18, "nineteen": 19,
    "twenty": 20, "thirty": 30, "forty": 40, "fifty": 50, "sixty": 60,
    "seventy": 70, "eighty": 80, "ninety": 90, "hundred": 100,
})
_BRUCHTEILE.update({"half": 50, "full": 100})

# 'twenty one' bis 'ninety nine' - im Englischen ZWEITEILIG, wo das Deutsche
# ein Wort bildet. Gemessen am 24.08.2026: ohne diese Ergaenzung traf
# 'dim the kitchen to seventy five percent' kein Muster, waehrend
# 'fuenfundsiebzig' laengst ging. Erzeugt statt aufgezaehlt, damit die Liste
# keine Luecke bekommt; der Bindestrich ist mitgedacht, weil die
# Spracherkennung ihn manchmal setzt (einebnen() macht daraus ein Leerzeichen).
for _z, _zv in (("twenty", 20), ("thirty", 30), ("forty", 40), ("fifty", 50),
                ("sixty", 60), ("seventy", 70), ("eighty", 80), ("ninety", 90)):
    for _e, _ev in (("one", 1), ("two", 2), ("three", 3), ("four", 4),
                    ("five", 5), ("six", 6), ("seven", 7), ("eight", 8),
                    ("nine", 9)):
        _ZAHLWORTE[_z + " " + _e] = _zv + _ev

# WARUM NICHT DER GANZE SATZ UEBERSETZT WIRD - eine gemessene Lehre.
#
# Der erste Anlauf ersetzte Zahlwoerter im ganzen eingeebneten Satz. Beim
# ersten Probelauf fiel auf: 'ein' IST ein Zahlwort, und damit wurde aus
#     schalte das licht im wohnzimmer ein
#     schalte das licht im wohnzimmer 1
# und der haeufigste Befehl des ganzen Hauses traf kein Muster mehr. Dasselbe
# gilt fuer 'eine', 'eins', 'acht' ('gib acht') und 'sieben' (das Verb).
#
# Uebersetzt wird deshalb NUR an der Stelle, an der das Muster eine Zahl
# erwartet - {wert} und {dauer} nehmen wahlweise Ziffern oder ein Zahlwort,
# und umgerechnet wird erst nach dem Treffer. Ausserhalb dieser Stellen bleibt
# jedes Wort, wie es gesprochen wurde.
#
# Die laengsten zuerst: sonst schlaegt 'zwei' in 'zweiundzwanzig' zu.
_ZAHLWORT_ALT = "|".join(sorted(set(list(_ZAHLWORTE) + list(_BRUCHTEILE)),
                                key=len, reverse=True))
_DAUER_ZAHL_ALT = "|".join(sorted(set(list(_ZAHLWORTE) + list(_DAUER_EINS)),
                                  key=len, reverse=True))


def zahl_lesen(text):
    """Ziffern oder ein einzelnes Zahlwort in eine Zahl. None, wenn keins."""
    t = (text or "").strip()
    if re.fullmatch(r"\d{1,4}", t):
        return int(t)
    if t in _ZAHLWORTE:
        return _ZAHLWORTE[t]
    if t in _BRUCHTEILE:
        return _BRUCHTEILE[t]
    if t in _DAUER_EINS:
        return _DAUER_EINS[t]
    return None


# ---------------------------------------------------------------------------
# Dauer
# ---------------------------------------------------------------------------
_DAUER_EINHEIT = {"sekunde": 1, "sekunden": 1, "minute": 60, "minuten": 60,
                  "stunde": 3600, "stunden": 3600,
                  "second": 1, "seconds": 1, "minutes": 60,
                  "hour": 3600, "hours": 3600}


def dauer_in_sekunden(text: str):
    """'10 minuten' oder 'einer minute' -> Sekunden. None bei Unbekanntem.

    Die Einheit wird VOM ENDE HER abgetrennt, der Rest ist die Zahl. Bis
    0.10.1 verlangte der Ausdruck fuer die Zahl genau EIN Wort ohne
    Leerzeichen. Damit griff das Muster bei
    'turn kitchen off in twenty five minutes' (der Ausdruck kennt die
    zweiwortigen englischen Zahlwoerter seit dem 24.08.2026), und die
    Umrechnung scheiterte danach mit 'dauer_unklar'. Deutsch war nie
    betroffen, weil es ein Wort bildet: fuenfundzwanzig.
    """
    t = re.match(r"^\s*(.+?)\s+([a-z]+)\s*$", (text or "").strip())
    if not t:
        return None
    faktor = _DAUER_EINHEIT.get(t.group(2))
    zahl = zahl_lesen(t.group(1))
    if faktor is None or zahl is None:
        return None
    return zahl * faktor


def muster_zu_regex(muster: str) -> re.Pattern:
    """Ein Muster in einen regulaeren Ausdruck uebersetzen.

    [a|b]    -> eine der Alternativen, auch leer wenn eine Alternative leer ist
    {ziel}   -> beliebiger Text (wird spaeter gegen die Zielliste geprueft)
    {wert}   -> eine Zahl
    {dauer}  -> Zahl samt Zeiteinheit ('10 minuten')
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
                teile.append(r"(?P<wert>\d{1,4}|" + _ZAHLWORT_ALT + r")")
            elif name == "dauer":
                teile.append(r"(?P<dauer>(?:\d{1,4}|" + _DAUER_ZAHL_ALT
                             + r")\s*(?:sekunden?|minuten?|stunden?|seconds?|minutes?|hours?))")
            elif name == "ziel":
                # Mindestens ein Zeichen, das KEIN Leerraum ist - und das
                # Ganze weglassbar.
                #
                # Bis 0.9.11 stand hier '.+?'. Damit traf 'mach an' das Muster
                # '[schalte|mach] {ziel} [an|ein]' mit ziel=' ' - einem
                # einzelnen Leerzeichen. Die Anlage antwortete daraufhin
                # 'Ich kenne kein Geraet mit der Bezeichnung .', statt zu
                # merken, dass gar kein Ziel genannt wurde.
                #
                # Seit 0.10.0 darf das Ziel FEHLEN. Dann gilt der Raum, in dem
                # das Mikrofon steht (siehe Vorgabeziel in erkennen()) - 'mach
                # an' ist in der Kueche etwas anderes als im Wohnzimmer, und
                # das Mikrofon weiss, wo es steht. Steht kein Raum am Mikrofon,
                # wird 'ziel_fehlt' gemeldet und nichts geschaltet.
                teile.append(r"(?P<ziel>\S+(?:\s+\S+)*?)?")
            else:
                teile.append(rf"(?P<{name}>\S+(?:\s+\S+)*?)")
        else:
            teile.append(re.escape(einebnen(stueck)))
    # Zwischen den Teilen darf beliebig viel Leerraum stehen, auch keiner.
    ausdruck = r"\s*".join(t for t in teile if t)
    return re.compile(r"^\s*" + ausdruck + r"\s*$")


# Namen, die ein Platzhalter NICHT tragen darf: sie sind Ergebnisfelder von
# erkennen() und wuerden von gesprochenem Text ueberschrieben. 'wert',
# 'ziel' und 'dauer' fehlen hier mit Absicht - genau die sind gemeint.
# Alles in geschweiften Klammern, das muster_zu_regex NICHT als
# Platzhalter liest - also alles ausser {kleinbuchstaben}.
_UNGEDEUTET = re.compile(r"\{(?![a-z]+\})[^{}]*\}")

GESPERRTE_PLATZHALTER = (
    "ok", "grund", "absicht", "aktion", "muster", "satz", "dauer_s",
    "zielname", "thema", "einheit", "url", "url_lesen", "bestaetigen",
    "gesucht",
)


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
                continue
            # Ein Platzhalter, den muster_zu_regex nicht erkennt, wird zu
            # Literaltext: aus '{Ziel}' mit grossem Z wurde bis 0.10.1 der
            # Text 'ziel', die Regel war wirkungslos, und nichts wurde rot.
            uebrig = _UNGEDEUTET.findall(muster)
            if uebrig:
                # ausdruck=None: die Regel greift nicht mehr UND pruefen()
                # meldet sie. Bis 0.10.1 wurde aus '{Ziel}' der Literaltext
                # 'ziel', die Regel war damit wirkungslos - und nichts wurde
                # rot. Eine Regel, die etwas anderes tut als sie sagt, ist
                # schlimmer als eine, die abgewiesen wird.
                self.regeln[-1] = (None,
                                   dict(regel, _fehler=(
                                       "unbekannter Platzhalter %s - bekannt sind "
                                       "{ziel}, {wert}, {dauer} und beliebige "
                                       "kleingeschriebene Namen"
                                       % ", ".join(uebrig))))
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
                # Seit 0.10.0: der Lesepfad. Ohne ihn kann eine Frage nach
                # einem Zustand nicht beantwortet werden.
                "url_lesen": str(ziel.get("url_lesen") or ""),
                "einheit": str(ziel.get("einheit") or ""),
                # Seit 0.10.0: heikle Ziele fragen zurueck, statt zu schalten.
                "bestaetigen": 1 if ziel.get("bestaetigen") else 0,
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

    def erkennen(self, satz: str, vorgabeziel: str = "") -> dict:
        """Rueckgabe: {'ok':1, 'absicht':..., 'ziel':..., ...} oder {'ok':0, 'grund':...}

        vorgabeziel ist das Ziel, das gilt, wenn der Satz KEINES nennt - in der
        Regel der Raum, in dem das Mikrofon steht. 'Mach das Licht an' bedeutet
        im Wohnzimmer etwas anderes als in der Kueche, und das Mikrofon weiss,
        wo es steht. Nennt der Satz ein Ziel, gewinnt der Satz.
        """
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
                "dauer_s": None,
                "ziel": None,
                "zielname": "",
                "thema": "",
                "einheit": str(regel.get("einheit") or ""),
                "url": str(regel.get("url") or ""),
                "url_lesen": "",
                "bestaetigen": 0,
            }
            if felder.get("wert") is not None:
                erg["wert"] = zahl_lesen(felder["wert"])
                if erg["wert"] is None:
                    return {"ok": 0, "grund": "wert_unklar",
                            "gesucht": str(felder["wert"]).strip()}
            if felder.get("dauer") is not None:
                erg["dauer_s"] = dauer_in_sekunden(felder["dauer"])
                if erg["dauer_s"] is None:
                    # Das Muster hat gegriffen, die Einheit ist aber keine, die
                    # sich umrechnen laesst. Abweisen und benennen, nicht raten.
                    return {"ok": 0, "grund": "dauer_unklar",
                            "gesucht": felder["dauer"].strip()}
            # Alle uebrigen benannten Gruppen unveraendert durchreichen.
            #
            # Bis 0.9.11 wurden nur 'wert' und 'ziel' gelesen. {rest} war in
            # drei Dateien angekuendigt, wurde vom Ausdruck auch aufgesammelt -
            # und dann verworfen. Ein Muster wie 'sag mir {rest}' griff damit
            # und kam leer an.
            #
            # Bis 0.10.1 durfte ein Platzhalter dabei ein ERGEBNISFELD
            # ueberschreiben. Gemessen: 'setze {aktion}' mit dem Satz 'setze
            # kaputt' ergab aktion='kaputt' - und die Aktion geht unveraendert
            # an den Miniserver und ins MQTT-Thema. Gesprochenes bestimmte den
            # gesendeten Befehl. Ein Platzhalter, der so heisst wie ein
            # Ergebnisfeld, ist ein MUSTERFEHLER und wird gemeldet, nicht
            # stillschweigend angenommen (REGELN_1, Abschnitt 4).
            for name, wert in felder.items():
                if name in ("wert", "ziel", "dauer") or wert is None:
                    continue
                if name in GESPERRTE_PLATZHALTER:
                    return {"ok": 0, "grund": "muster_fehler",
                            "gesucht": name,
                            "muster": str(regel.get("muster") or "")}
                erg[name] = wert.strip()
            gesucht_ziel = felder.get("ziel")
            # Ein Fang, der nach dem Einebnen nichts uebrig laesst, ist kein
            # genanntes Ziel - dann gilt die Vorgabe des Mikrofons.
            if gesucht_ziel is not None and einebnen(gesucht_ziel) == "":
                gesucht_ziel = None
            aus_vorgabe = False
            if gesucht_ziel is None and vorgabeziel:
                gesucht_ziel = vorgabeziel
                aus_vorgabe = True
            if gesucht_ziel is None and "ziel" in felder:
                # Das Muster verlangt ein Ziel, der Satz nennt keines, und es
                # gibt keine Vorgabe. Ohne diesen Zweig ginge ein Schaltbefehl
                # mit leerem Ziel nach Loxone hinaus.
                return {"ok": 0, "grund": "ziel_fehlt",
                        "bekannt": sorted(z["name"] for z in self.ziele.values())}
            if gesucht_ziel is not None:
                ziel = self.ziel_finden(gesucht_ziel)
                if ziel is None:
                    if aus_vorgabe:
                        # Nicht dem Sprecher anlasten, was in der Mikrofonzeile
                        # steht: er hat kein Ziel genannt, die VORGABE ist
                        # falsch eingetragen.
                        return {"ok": 0, "grund": "vorgabeziel_unbekannt",
                                "gesucht": str(gesucht_ziel).strip(),
                                "bekannt": sorted(z["name"] for z in self.ziele.values())}
                    # Muster passt, Ziel nicht - das ist ein anderer Fall als
                    # 'nicht verstanden' und wird auch anders gemeldet.
                    return {"ok": 0, "grund": "ziel_unbekannt",
                            "gesucht": str(gesucht_ziel).strip(),
                            "bekannt": sorted(z["name"] for z in self.ziele.values())}
                erg["ziel"] = ziel["schluessel"]
                erg["zielname"] = ziel["name"]
                erg["thema"] = ziel["thema"]
                erg["url_lesen"] = ziel["url_lesen"]
                erg["bestaetigen"] = ziel["bestaetigen"]
                erg["ziel_aus_vorgabe"] = 1 if aus_vorgabe else 0
                # Die Einheit des Ziels gilt nur dort, wo ueberhaupt eine Zahl
                # im Spiel ist. Sonst stuende an einem reinen Schaltbefehl
                # 'Grad', weil das Ziel ein Thermostat ist.
                if not erg["einheit"] and (erg["wert"] is not None
                                           or erg["absicht"] == "frage"):
                    erg["einheit"] = ziel["einheit"]
                if not erg["url"]:
                    erg["url"] = ziel["url"]
            erg["antwort_vorlage"] = str(regel.get("antwort") or "")
            erg["antwort"] = self.antwort_fuellen(erg["antwort_vorlage"], erg)
            return erg
        return {"ok": 0, "grund": "kein_muster"}

    @staticmethod
    def antwort_fuellen(vorlage: str, erg: dict, istwert: str = "") -> str:
        """Platzhalter im Antworttext ersetzen.

        Ersetzt wird JEDER Schluessel des Ergebnisses, nicht nur zwei fest
        verdrahtete. Damit traegt {rest} genauso wie {zielname} - und wer ein
        Muster mit {ort} baut, bekommt {ort} im Antworttext, ohne dass hier
        eine Zeile dazukommt.
        """
        text = str(vorlage or "")
        if not text:
            return ""
        werte = dict(erg)
        werte["istwert"] = istwert
        for name, wert in werte.items():
            if name.startswith("_") or isinstance(wert, (dict, list)):
                continue
            text = text.replace("{" + name + "}", "" if wert is None else str(wert))
        return text.strip()

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
        # Eine Regel, die nach einem Zustand fragt, braucht einen Weg, ihn zu
        # lesen. Ohne den bleibt die Anlage auf die Frage stumm - genau das war
        # bis 0.9.11 die Lage der mitgelieferten Temperaturregel.
        for _, regel in self.regeln:
            if str(regel.get("absicht") or "") != "frage":
                continue
            vorlage = str(regel.get("antwort") or "")
            if vorlage == "":
                meldungen.append(
                    "Die Frage-Regel %r hat keinen Antworttext - auf diese Frage "
                    "bleibt die Anlage stumm." % regel.get("muster"))
            elif "{istwert}" in vorlage and not any(z["url_lesen"]
                                                    for z in self.ziele.values()):
                # Beanstandet wird nur der Fall, der HEUTE schiefgeht: die
                # Regel will einen Ist-Wert einsetzen, und es gibt kein
                # einziges Ziel, aus dem sich einer lesen liesse. Dass ein
                # bestimmtes Ziel keinen Lesepfad hat, ist dagegen normal -
                # eine Lampe wird nicht nach ihrer Temperatur gefragt.
                meldungen.append(
                    "Die Regel %r setzt {istwert} ein, aber kein einziges Ziel hat "
                    "ein Feld 'url_lesen' - auf diese Frage bleibt die Anlage stumm."
                    % regel.get("muster"))
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
    # --raum=<ziel> setzt das Vorgabeziel, wie es ein Mikrofon mitbringt.
    raum = ""
    saetze = []
    for a in sys.argv[1:]:
        if a.startswith("--raum="):
            raum = a[7:]
        else:
            saetze.append(a)
    for satz in saetze:
        print(json.dumps(v.erkennen(satz, raum), ensure_ascii=False))
