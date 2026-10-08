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

import datetime
import json
import re
import sys
import time
import unicodedata
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
    # NFC ZUERST: ein 'ü' kann als EIN Zeichen kommen oder als 'u' mit einem
    # nachgestellten Trema (NFD - so liefern es manche Tastaturen und macOS).
    # Ohne diesen Schritt griff die Umlauttabelle nicht, das Trema fiel
    # weiter unten als Beizeichen weg, und aus 'Küche' wurde 'kuche' statt
    # 'kueche' (gemessen am 03.10.2026, Befund N3).
    text = unicodedata.normalize("NFC", text or "").strip().lower()
    for a, b in _UMLAUTE.items():
        text = text.replace(a, b)
    text = unicodedata.normalize("NFKD", text)
    text = "".join(c for c in text if not unicodedata.combining(c))
    # Dezimalkomma und Uhrzeit schuetzen, BEVOR die Satzzeichen fallen: bis
    # 0.11.15 wurde aus '21,5' der Text '21 5' und aus '7:30' der Text '7 30'
    # - das Muster '{wert} prozent' traf dann nicht mehr, und die Uhrzeit war
    # nicht mehr von zwei Zahlen zu unterscheiden. Zwischen zwei Ziffern
    # bleibt deshalb ein Punkt (Komma wird Punkt) bzw. ein Doppelpunkt stehen.
    text = re.sub(r"(?<=\d),(?=\d)", ".", text)
    # Alles, was kein Buchstabe, keine Ziffer und kein Leerzeichen ist, faellt weg.
    text = re.sub(r"[^a-z0-9 .:]+", " ", text)
    text = re.sub(r"(?<!\d)[.:]|[.:](?!\d)", " ", text)
    return re.sub(r"\s+", " ", text).strip()


def _alt(woerter) -> str:
    """Woerter als Alternativen eines Ausdrucks - die laengsten zuerst."""
    return "|".join(re.escape(w) for w in sorted(set(woerter), key=len, reverse=True))


def _grenze(text: str) -> str:
    """Festen Text in Wortgrenzen fassen.

    Bis 0.11.15 wurden feste Woerter und Alternativen mit '\\s*' verbunden,
    also auch OHNE Leerzeichen. Damit traf '[an|ein]' das Wortende von
    'klein' und 'fein': 'mach das wohnzimmer klein' schaltete ein (Befund
    H4). Die Grenze gilt fuer Buchstaben; zwischen Ziffer und Buchstabe
    ('50prozent') darf weiter kein Leerzeichen stehen.
    """
    if not text:
        return ""
    vorne = "(?<![a-z])" if text[0].isalpha() else ("(?<![0-9])" if text[0].isdigit() else "")
    hinten = "(?![a-z])" if text[-1].isalpha() else ("(?![0-9])" if text[-1].isdigit() else "")
    return vorne + re.escape(text) + hinten


def _enthaelt_wort(name: str, text: str) -> bool:
    """Steht name als ganzes Wort (bzw. ganze Wortfolge) in text?"""
    return re.search(r"(?<![a-z0-9])" + re.escape(name) + r"(?![a-z0-9])", text) is not None


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
# WO uebersetzt wird, steht weiter unten bei _ZAHL - und es ist
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
_DAUER_EINS = {"einer": 1, "einem": 1, "eine": 1, "ein": 1, "einen": 1}

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
# Seit 0.12.0 bis 999 und mit Nachkommastellen: 'einhundertzwanzig',
# 'one hundred and twenty', 'einundzwanzig komma fuenf', 'twenty one point
# five', '21,5' (Befund M12). Die Hunderter werden als AUFBAU beschrieben
# statt als Liste aller 900 Woerter.
# Die Wortliste steht in jedem Ausdruck nur EINMAL: sie ist lang, und jede
# Kopie verlaengert das Uebersetzen der Muster beim Laden der Satzdatei.
_UNTER_HUNDERT = _alt(k for k, v in _ZAHLWORTE.items() if v < 100)
_HUNDERTER_AUSDRUCK = (r"(?:(?:(?:ein|zwei|drei|vier|fuenf|sechs|sieben|acht|neun)\s?)?hundert"
                       r"|(?:(?:one|a|two|three|four|five|six|seven|eight|nine)\s)?hundred)")
_ZAHL_GANZ = (r"(?:\d{1,4}"
              r"|(?:" + _HUNDERTER_AUSDRUCK + r"(?:\s?(?:und|and)\s?|\s)?)?(?:" + _UNTER_HUNDERT + r")"
              r"|" + _HUNDERTER_AUSDRUCK + r")")
# Nach dem Komma weitere Stellen nur als einzelne Ziffern ('point two five').
_ZIFFERWORT = (r"(?:null|eins|zwei|drei|vier|fuenf|sechs|sieben|acht|neun"
               r"|zero|one|two|three|four|five|six|seven|eight|nine)")
_NACHKOMMA = (r"(?:\d{1,3}|(?:" + _UNTER_HUNDERT + r")(?:\s" + _ZIFFERWORT + r"){0,2})")
_ZAHL = (r"(?:\d{1,4}\.\d{1,3}|" + _ZAHL_GANZ
         + r"(?:\s(?:komma|point)\s" + _NACHKOMMA + r")?)")
_WERT_AUSDRUCK = r"(?:" + _ZAHL + r"|" + _alt(_BRUCHTEILE) + r")"

_HUNDERTER = (
    re.compile(r"(?:(ein|zwei|drei|vier|fuenf|sechs|sieben|acht|neun) ?)?hundert"
               r"(?: ?(?:und ?)?(.+))?"),
    re.compile(r"(?:(one|a|two|three|four|five|six|seven|eight|nine) )?hundred"
               r"(?: (?:and )?(.+))?"),
)


def _runden(zahl):
    """Ganze Zahlen als int, sonst auf zwei Stellen - '50' bleibt '50', nicht '50.0'."""
    zahl = round(float(zahl), 2)
    return int(zahl) if zahl == int(zahl) else zahl


def _ganzzahl_lesen(t):
    if re.fullmatch(r"\d{1,4}", t):
        return int(t)
    if t in _ZAHLWORTE:
        return _ZAHLWORTE[t]
    for aufbau in _HUNDERTER:
        m = aufbau.fullmatch(t)
        if not m:
            continue
        hunderter = 1 if m.group(1) in (None, "a") else _ZAHLWORTE.get(m.group(1))
        rest = 0
        if m.group(2):
            rest = _ZAHLWORTE.get(m.group(2))
            if rest is None or rest >= 100:
                return None
        return hunderter * 100 + rest
    return None


def _nachkomma_lesen(t):
    """'5' -> 0.5, 'fuenfundzwanzig' -> 0.25, 'two five' -> 0.25."""
    if re.fullmatch(r"\d{1,3}", t):
        return float("0." + t)
    ziffern = ""
    woerter = t.split()
    i = 0
    while i < len(woerter):
        # 'twenty five' ist EIN Zahlwort, 'two five' sind zwei Ziffern.
        paar = " ".join(woerter[i:i + 2])
        if i + 1 < len(woerter) and paar in _ZAHLWORTE:
            z, i = _ZAHLWORTE[paar], i + 2
        else:
            z, i = _ZAHLWORTE.get(woerter[i]), i + 1
        if z is None or z >= 100:
            return None
        ziffern += str(z)
    return float("0." + ziffern) if ziffern else None


def zahl_lesen(text):
    """Ziffern oder ein Zahlwort in eine Zahl. None, wenn keins.

    Ganze Zahlen kommen als int zurueck, Nachkommastellen als float.
    """
    t = re.sub(r"\s+", " ", (text or "").strip())
    if re.fullmatch(r"\d{1,4}\.\d{1,3}", t):
        return _runden(t)
    m = re.fullmatch(r"(.+?) (?:komma|point) (.+)", t)
    if m:
        ganz = _ganzzahl_lesen(m.group(1))
        nach = _nachkomma_lesen(m.group(2))
        if ganz is None or nach is None:
            return None
        return _runden(ganz + nach)
    ganz = _ganzzahl_lesen(t)
    if ganz is not None:
        return ganz
    if t in _BRUCHTEILE:
        return _BRUCHTEILE[t]
    if t in _DAUER_EINS:
        return _DAUER_EINS[t]
    return None


# ---------------------------------------------------------------------------
# Dauer und Uhrzeit
#
# WARUM SO AUSFUEHRLICH: bis 0.11.15 kannte {dauer} nur 'Zahl Einheit'. Alles
# andere - 'eineinhalb stunden', 'einer halben stunde', '1,5 stunden', '10
# min', 'um 22 uhr', 'in half an hour' - traf das Dauer-Muster nicht, fiel
# auf das allgemeine Schaltmuster durch, {ziel} schluckte die Zeitangabe mit,
# ziel_finden fand darin das Zimmer, und der Befehl lief SOFORT (Befund H1).
# Ein verschlucktes 'in einer halben Stunde' ist schlimmer als ein 'nicht
# verstanden': das Licht geht aus, waehrend man noch liest.
# ---------------------------------------------------------------------------
_DAUER_EINHEIT = {
    "sekunde": 1, "sekunden": 1, "sek": 1, "sec": 1, "secs": 1,
    "second": 1, "seconds": 1,
    "minute": 60, "minuten": 60, "min": 60, "mins": 60, "minutes": 60,
    "stunde": 3600, "stunden": 3600, "std": 3600, "h": 3600,
    "hour": 3600, "hours": 3600, "hr": 3600, "hrs": 3600,
    "viertelstunde": 900, "viertelstunden": 900,
    "dreiviertelstunde": 2700, "dreiviertelstunden": 2700,
    "tag": 86400, "tage": 86400, "tagen": 86400, "day": 86400, "days": 86400,
}
_BIS_ZWOELF_DE = "ein|zwei|drei|vier|fuenf|sechs|sieben|acht|neun|zehn|elf|zwoelf"
_BIS_ZWOELF_EN = "one|two|three|four|five|six|seven|eight|nine|ten|eleven|twelve"

# Die Menge vor der Einheit. Die Sonderformen stehen VOR den Zahlwoertern,
# damit 'one and a half' nicht als 'one' gelesen wird.
_MENGE = (r"(?:"
          r"(?:einer|eine|einen|einem|ein)\s(?:halben|halbe|dreiviertel|viertel)"
          r"|halben|halbe|dreiviertel|viertel"
          r"|anderthalb|(?:" + _BIS_ZWOELF_DE + r")\s?einhalb"
          r"|(?:a|one|three)\squarters?\sof\s(?:an|a)|quarter\sof\s(?:an|a)"
          r"|a\squarter|three\squarters"
          r"|half\s(?:an|a)|a\shalf"
          r"|(?:\d{1,2}|" + _BIS_ZWOELF_EN + r"|an|a)\sand\sa\shalf"
          r"|\d{1,4}(?:\.\d{1,3})?|" + _ZAHL_GANZ + r"|einer|einem|einen|eine|ein|an|a"
          r")")
_EINHEIT = _alt(_DAUER_EINHEIT)
# Zwischen Ziffer und Einheit darf das Leerzeichen fehlen ('10min'), sonst
# nicht - aus 'a' und 'h' wird kein 'ah'.
_DAUER_TEIL = (_MENGE + r"(?:(?<=\d)\s?|\s)(?:" + _EINHEIT + r")(?![a-z])"
               r"(?:\sand\sa\shalf)?")
# '2 stunden und 30 minuten', 'one hour 15 minutes'
_SPANNE = r"(?:" + _DAUER_TEIL + r"(?:(?:\s(?:und|and))?\s" + _DAUER_TEIL + r"){0,2})"

_STUNDE_WORT = _alt(k for k, v in _ZAHLWORTE.items() if 0 <= v <= 24)
_MINUTE_WORT = _alt(k for k, v in _ZAHLWORTE.items() if 1 <= v < 60)
_STD = r"(?:\d{1,2}|" + _STUNDE_WORT + r")"
_TAG = r"(?:uebermorgen|morgen|heute|tomorrow|today|tonight)"
_TAGESZEIT = (r"(?:frueh|morgen|vormittag|mittag|nachmittag|abend|nacht"
              r"|morning|afternoon|evening|night)")
_TAGESZEIT_ADV = (r"(?:morgens|frueh|vormittags|mittags|nachmittags|abends|nachts"
                  r"|in\sthe\s(?:morning|afternoon|evening)|at\snight|tonight)")
_UHR = (r"(?:"
        r"(?:halb|viertel\snach|viertel\svor|dreiviertel|viertel)\s" + _STD
        + r"|(?:half|quarter)\s(?:past|to)\s" + _STD
        + r"|mitternacht|midnight|noon|mittag"
        r"|(?:\d{1,2}[:.]\d{2}|" + _STD + r")"
        r"(?:\s?uhr(?:\s(?:\d{1,2}|" + _MINUTE_WORT + r"))?|\s(?:\d{2}|" + _MINUTE_WORT + r"))?"
        r"(?:\so\sclock)?(?:\s?(?:am|pm|a\sm|p\sm))?"
        r")")
# Ein Zeitpunkt traegt sein Verhaeltniswort selbst ('um 22 uhr', 'at 10 pm',
# 'morgen frueh um 7', 'tomorrow at 7').
_ZEITPUNKT = (r"(?:" + _TAG + r"(?:\s" + _TAGESZEIT + r")?\s)?(?:um|at|gegen|around)\s" + _UHR
              + r"(?:\s" + _TAGESZEIT_ADV + r")?(?:\s" + _TAG + r"(?:\s" + _TAGESZEIT + r")?)?")

_DAUER_PRAEP = r"(?:innerhalb\svon|innerhalb|binnen|nach|in|after|within)"
# Woerter, nach denen im MUSTER eine Spanne auch ohne eigenes Verhaeltniswort
# stehen darf: 'in {dauer}', 'nach {dauer}', 'timer fuer {dauer}'.
_DAUER_PRAEP_WOERTER = frozenset(("in", "nach", "binnen", "innerhalb", "von", "fuer",
                                  "auf", "ueber", "after", "within", "for", "of"))
# {dauer} hinter einem solchen Wort: die Spanne darf nackt stehen.
_DAUER_LOSE = r"(?:(?:" + _DAUER_PRAEP + r"\s)?" + _SPANNE + r"|" + _ZEITPUNKT + r")"
# {dauer} hinter einem Platzhalter oder am Satzanfang: die Spanne braucht ihr
# Verhaeltniswort. Sonst wuerde '[schalte|mach] {ziel} {dauer} [aus|ab]' auch
# 'schalte das wohnzimmer zehn minuten aus' vormerken - und das kann ebenso
# 'fuer zehn Minuten aus' heissen. Ohne Verhaeltniswort fragt die Anlage nach
# (dauer_unklar), statt zu raten.
_DAUER_STRENG = r"(?:" + _DAUER_PRAEP + r"\s" + _SPANNE + r"|" + _ZEITPUNKT + r")"

_DAUER_TEIL_RE = re.compile(r"(?P<menge>" + _MENGE + r")(?:(?<=\d)\s?|\s)(?P<einheit>"
                            + _EINHEIT + r")(?![a-z])(?P<halb>\sand\sa\shalf)?")
_ZEITPUNKT_RE = re.compile(_ZEITPUNKT)
_DAUER_PRAEP_RE = re.compile(r"^" + _DAUER_PRAEP + r"\s")
_UHR_RE = re.compile(
    r"(?:(?P<bruch>halb|viertel\snach|viertel\svor|dreiviertel|viertel|half\spast"
    r"|quarter\spast|quarter\sto)\s(?P<bstd>" + _STD + r")"
    r"|(?P<fest>mitternacht|midnight|noon|mittag)"
    r"|(?:(?P<hh>\d{1,2})[:.](?P<mm>\d{2})|(?P<std>" + _STD + r"))"
    r"(?:\s?uhr(?:\s(?P<min1>\d{1,2}|" + _MINUTE_WORT + r"))?|\s(?P<min2>\d{2}|"
    + _MINUTE_WORT + r"))?)")

_MENGE_FEST = {"halbe": 0.5, "halben": 0.5, "viertel": 0.25, "dreiviertel": 0.75,
               "anderthalb": 1.5, "half an": 0.5, "half a": 0.5, "a half": 0.5,
               "a quarter": 0.25, "three quarters": 0.75, "an": 1, "a": 1}


def _menge_lesen(text):
    t = re.sub(r"\s+", " ", (text or "").strip())
    t = re.sub(r"^(?:einer|eine|einen|einem|ein) (?=halb|viertel|dreiviertel)", "", t)
    if t in _MENGE_FEST:
        return _MENGE_FEST[t]
    m = re.fullmatch(r"([a-z]+?) ?einhalb", t)
    if m:
        z = zahl_lesen(m.group(1))
        return None if z is None else z + 0.5
    m = re.fullmatch(r"(.+) and a half", t)
    if m:
        z = 1 if m.group(1) in ("a", "an") else zahl_lesen(m.group(1))
        return None if z is None else z + 0.5
    m = re.fullmatch(r"(?:(a|one|three) )?quarters? of (?:an|a)", t)
    if m:
        return 0.75 if m.group(1) == "three" else 0.25
    return zahl_lesen(t)


def uhrzeit_in_sekunden(text: str, jetzt=None):
    """'um 22 uhr', 'morgen um 7:30', 'at 10 pm' -> Sekunden bis dahin.

    Gerechnet wird in Ortszeit bis zum NAECHSTEN Eintreten. jetzt laesst sich
    fuer Pruefungen vorgeben (datetime ohne Zeitzone). None, wenn die Uhrzeit
    nicht lesbar ist oder ausdruecklich in der Vergangenheit liegt ('heute
    um 7' um 23 Uhr).

    Ohne Tageszeit ist '8' im Gesprochenen zweideutig - 'um 8' heisst am
    Nachmittag fast immer 20 Uhr. Genommen wird dann das naechste Eintreten
    von 8 Uhr ODER 20 Uhr. Mit 'morgens', 'abends', 'am', 'pm' oder einer
    Stunde ueber 12 gilt die Angabe, wie sie gesprochen wurde.
    """
    t = einebnen(text)
    m = re.search(r"(?:^|\s)(?:um|at|gegen|around)\s(.+)$", t)
    if not m:
        return None
    u = _UHR_RE.match(m.group(1))
    if not u:
        return None
    minute = 0
    ausdruecklich = False
    if u.group("bruch"):
        stunde = zahl_lesen(u.group("bstd"))
        if not isinstance(stunde, int):
            return None
        bruch = u.group("bruch").replace("  ", " ")
        if bruch in ("viertel nach", "quarter past"):
            minute = 15
        elif bruch == "half past":
            minute = 30
        else:
            # 'halb acht' ist 7:30, 'viertel vor acht' und 'dreiviertel acht'
            # sind 7:45, 'viertel acht' (Osten, Sueden) ist 7:15.
            minute = {"halb": 30, "viertel vor": 45, "quarter to": 45,
                      "dreiviertel": 45, "viertel": 15}[bruch]
            stunde = (stunde - 1) % 24
            if stunde == 0:
                stunde = 12     # 'halb eins' ist 12:30 (oder 0:30, siehe unten)
    elif u.group("fest"):
        stunde = 0 if u.group("fest") in ("mitternacht", "midnight") else 12
        ausdruecklich = True
    else:
        if u.group("hh") is not None:
            stunde, minute = int(u.group("hh")), int(u.group("mm"))
        else:
            stunde = zahl_lesen(u.group("std"))
            mt = u.group("min1") or u.group("min2")
            if mt:
                minute = zahl_lesen(mt)
        if not isinstance(stunde, int) or not isinstance(minute, int):
            return None
    if stunde == 24 and minute == 0:
        stunde = 0
    if not (0 <= stunde <= 23 and 0 <= minute <= 59):
        return None
    if stunde == 0 or stunde > 12:
        ausdruecklich = True

    tag = None
    if re.search(r"\buebermorgen\b", t):
        tag = 2
    elif re.search(r"\btomorrow\b", t) or re.search(r"(?<!heute )\bmorgen\b", t):
        tag = 1
    elif re.search(r"\b(?:heute|today|tonight)\b", t):
        tag = 0
    teil = ""
    if re.search(r"\b(?:pm|p m|nachmittags?|abends?|evening|afternoon|tonight)\b", t):
        teil = "pm"
    elif re.search(r"\b(?:nachts|nacht|night)\b", t):
        teil = "nacht"
    elif re.search(r"\bmittags\b", t):
        teil = "mittag"
    elif re.search(r"\b(?:am|a m|morgens|frueh|vormittags?|morning)\b", t) \
            or "heute morgen" in t:
        teil = "am"

    if teil == "pm":
        stunden = [stunde + 12 if stunde < 12 else stunde]
    elif teil == "am":
        stunden = [0 if stunde == 12 else stunde]
    elif teil == "nacht":
        stunden = [stunde + 12 if 6 <= stunde < 12 else (0 if stunde == 12 else stunde)]
    elif teil == "mittag":
        stunden = [stunde + 12 if stunde < 6 else stunde]
    elif ausdruecklich or (tag is not None and tag > 0):
        stunden = [stunde]
    else:
        stunden = [stunde, (stunde + 12) % 24]

    jetzt = jetzt or datetime.datetime.now()
    basis = jetzt.date() + datetime.timedelta(days=tag or 0)
    bester = None
    for st in stunden:
        ziel = datetime.datetime.combine(basis, datetime.time(st, minute))
        if tag is None and ziel <= jetzt:
            ziel += datetime.timedelta(days=1)
        if ziel <= jetzt:
            continue
        if bester is None or ziel < bester:
            bester = ziel
    if bester is None:
        return None
    # mktime statt Differenz zweier datetime: so stimmt die Rechnung auch in
    # der Nacht der Zeitumstellung.
    sekunden = int(round(time.mktime(bester.timetuple()) - time.mktime(jetzt.timetuple())))
    return sekunden if sekunden > 0 else None


def dauer_in_sekunden(text: str, jetzt=None):
    """'10 minuten', 'einer halben stunde', '2 stunden und 30 minuten',
    'um 22 uhr' -> Sekunden. None bei Unbekanntem, 0 bei einer Dauer von null.

    Die Teile werden von links gelesen; was zwischen ihnen steht, darf nur
    'und'/'and' sein. Bis 0.10.1 verlangte der Ausdruck fuer die Zahl genau EIN
    Wort ohne Leerzeichen. Damit griff das Muster bei
    'turn kitchen off in twenty five minutes', und die Umrechnung scheiterte
    danach mit 'dauer_unklar'. Deutsch war nie betroffen, weil es ein Wort
    bildet: fuenfundzwanzig.
    """
    t = einebnen(text)
    if not t:
        return None
    if _ZEITPUNKT_RE.fullmatch(t):
        return uhrzeit_in_sekunden(t, jetzt)
    t = _DAUER_PRAEP_RE.sub("", t, count=1)
    summe = 0.0
    pos = 0
    gefunden = False
    for m in _DAUER_TEIL_RE.finditer(t):
        # Vor dem ersten Teil darf nichts stehen, zwischen zwei Teilen nur
        # 'und'/'and'.
        luecke = t[pos:m.start()]
        if not re.fullmatch(r"\s*(?:(?:und|and)\s*)?" if gefunden else r"\s*", luecke):
            return None
        menge = _menge_lesen(m.group("menge"))
        if menge is None:
            return None
        if m.group("halb"):
            menge += 0.5
        summe += menge * _DAUER_EINHEIT[m.group("einheit")]
        pos = m.end()
        gefunden = True
    if not gefunden or t[pos:].strip():
        return None
    return int(round(summe))


# ---------------------------------------------------------------------------
# Was im Ziel-Fang NICHT stehen darf
#
# {ziel} faengt beliebigen Text, und ziel_finden sucht darin ein Ziel. Alles,
# was dabei UEBRIG bleibt, hat das Muster nicht vorgesehen. Bis 0.11.15 fiel
# es stillschweigend weg - auch 'nicht', 'in einer halben stunde' und 'und
# das wohnzimmer an'. Die Wachen pruefen den Rest, nachdem der gefundene
# Zielname herausgenommen ist: ein Ziel, das 'Haus und Hof' heisst, ist kein
# zweiter Befehl.
# ---------------------------------------------------------------------------
_ZEIT_SPUR = re.compile(
    r"(?<![a-z0-9])(?:" + _ZEITPUNKT + r"|(?:" + _DAUER_PRAEP + r"\s)?" + _SPANNE
    + r"|sekunden?|minuten?|stunden?|viertelstunden?|dreiviertelstunden?"
    r"|seconds?|minutes?|hours?"
    r"|uhr|o\sclock|uebermorgen|morgen|morgens|abends|nachts|spaeter|nachher"
    r"|mitternacht|heute\s(?:abend|nacht|frueh)|tomorrow|tonight|later|midnight|noon"
    r"|\d{1,2}:\d{2}|\d{1,2}\s?(?:am|pm)"
    r")(?![a-z0-9])")
_VERNEINUNG = re.compile(r"(?<![a-z])(?:nicht|nichts|kein|keine|keinen|keinem|keiner"
                         r"|keines|nie|niemals|not|never|dont|don\st|doesn\st)(?![a-z])")
_UND = re.compile(r"(?<![a-z])(?:und|and)(?![a-z])")

# Woerter, die allein kein Ziel benennen. 'mach das licht an' nennt keinen
# Raum - es meint den, in dem das Mikrofon steht (Befund M2: bis 0.11.15 kam
# 'Ich kenne kein Geraet mit der Bezeichnung das licht').
_FUELLWOERTER = frozenset((
    "der", "die", "das", "den", "dem", "des", "ein", "eine", "einen", "einem", "einer",
    "im", "in", "am", "an", "auf", "bei", "beim", "vom", "von", "zum", "zur",
    "licht", "lichter", "lampe", "lampen", "leuchte", "leuchten", "beleuchtung",
    "bitte", "mal", "doch", "jetzt", "sofort", "mir", "hier", "wieder",
    "the", "a", "on", "at", "of", "light", "lights", "lamp", "lamps",
    "please", "now", "here", "my", "again",
))


def _nur_fuellwoerter(text: str) -> bool:
    woerter = einebnen(text).split()
    return bool(woerter) and all(w in _FUELLWOERTER for w in woerter)


def _hat_inhalt(text: str) -> bool:
    return any(w not in _FUELLWOERTER for w in text.split())


# Laenger spricht niemand einen Befehl. Die Begrenzung schuetzt vor
# Rueckverfolgung im Ausdruck: ein Muster mit vier freien Platzhaltern
# brauchte bei 200 Zeichen rund 2 Sekunden (Befund N6) - in dieser Zeit
# bedient der Dienst kein anderes Mikrofon.
SATZ_HOECHSTLAENGE = 200


def muster_zu_regex(muster: str) -> re.Pattern:
    """Ein Muster in einen regulaeren Ausdruck uebersetzen.

    [a|b]    -> eine der Alternativen, auch leer wenn eine Alternative leer ist
    {ziel}   -> beliebiger Text (wird spaeter gegen die Zielliste geprueft)
    {wert}   -> eine Zahl (auch '21,5', 'einhundertzwanzig', 'komma fuenf')
    {dauer}  -> Zeitspanne ('10 minuten', 'eineinhalb stunden') oder
                Zeitpunkt ('um 22 uhr', 'at 7:30')
    {rest}   -> beliebiger Text

    Feste Woerter stehen in Wortgrenzen (siehe _grenze), freie Platzhalter
    beginnen und enden an einer Wortgrenze. Dass ein Platzhalter mitten im
    Wort aufhoeren durfte, war die Ursache der Rueckverfolgung aus N6: jeder
    Satz liess sich auf exponentiell viele Arten zerlegen.
    """
    teile = []
    vorher_praep = False
    for stueck in re.split(r"(\[[^\]]*\]|\{[a-z]+\})", muster):
        if not stueck:
            continue
        if stueck.startswith("[") and stueck.endswith("]"):
            alternativen = [einebnen(a) for a in stueck[1:-1].split("|")]
            leer = any(a == "" for a in alternativen)
            gefuellt = [a for a in alternativen if a]
            if not gefuellt:
                continue
            gruppe = "(?:" + "|".join(_grenze(a) for a in gefuellt) + ")"
            teile.append(gruppe + ("?" if leer else ""))
            vorher_praep = (not leer) and all(a.split()[-1] in _DAUER_PRAEP_WOERTER
                                              for a in gefuellt)
        elif stueck.startswith("{") and stueck.endswith("}"):
            name = stueck[1:-1]
            if name == "wert":
                teile.append(r"(?P<wert>(?<![a-z0-9.])" + _WERT_AUSDRUCK + r"(?![0-9]))")
            elif name == "dauer":
                teile.append(r"(?P<dauer>(?<![a-z0-9])"
                             + (_DAUER_LOSE if vorher_praep else _DAUER_STRENG) + r")")
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
                teile.append(r"(?P<ziel>(?<!\S)\S+(?:\s+\S+)*?(?!\S))?")
            else:
                teile.append(rf"(?P<{name}>(?<!\S)\S+(?:\s+\S+)*?(?!\S))")
            vorher_praep = False
        else:
            fest = einebnen(stueck)
            if not fest:
                continue
            teile.append(_grenze(fest))
            vorher_praep = fest.split()[-1] in _DAUER_PRAEP_WOERTER
    # Zwischen den Teilen darf beliebig viel Leerraum stehen, auch keiner -
    # die Wortgrenzen stehen in den Teilen selbst.
    ausdruck = r"\s*".join(t for t in teile if t)
    return re.compile(r"^\s*" + ausdruck + r"\s*$")


# ---------------------------------------------------------------------------
# Teilsaetze - seit 0.12.0 im Dienst verdrahtet (_mehrteilig() in
# sprachsteuerung_dienst.py); erkennen() selbst meldet weiter 'mehrteilig'
# ---------------------------------------------------------------------------
_VERBEN = frozenset(("schalte", "schalt", "mach", "mache", "dimme", "dimm", "stelle",
                     "stell", "setze", "setz", "dreh", "drehe", "turn", "switch",
                     "dim", "set"))
_PARTIKEL = frozenset(("an", "aus", "ein", "ab", "auf", "zu", "on", "off", "up", "down"))
_GRENZWOERTER = frozenset(("der", "die", "das", "den", "dem", "des", "ein", "eine",
                           "einen", "einem", "einer", "im", "in", "am", "auf", "zum",
                           "zur", "the", "a", "in", "at"))
_TEILER = re.compile(r"(?<![a-z])(?:und dann|and then|und|and|sowie|dann|then)(?![a-z])")


def teilsaetze(satz: str, schutz=()) -> list:
    """Einen Satz mit mehreren Befehlen in Einzelbefehle zerlegen.

    'mach die kueche aus und das wohnzimmer an'
        -> ['mach die kueche aus', 'mach das wohnzimmer an']
    'licht im wohnzimmer und in der kueche an'
        -> ['licht im wohnzimmer an', 'licht in der kueche an']

    Der Anfang des ersten Teils (Verb und Partikel bis vor das erste Artikel-
    oder Verhaeltniswort) wird an die folgenden Teile weitergegeben, die
    Schlusspartikel des letzten Teils an die vorderen, denen eine fehlt.
    schutz sind Zielnamen; ein Name, der selbst 'und' enthaelt, wird nicht
    zerschnitten. Zurueck kommen eingeebnete Saetze, die erkennen() so nimmt.

    erkennen() meldet einen solchen Satz weiter als 'mehrteilig'; der Dienst
    fuehrt ihn aus, wenn JEDER Teilsatz fuer sich eindeutig erkannt wird.
    Bis 0.12.0 hiess es hier: erkennen() meldet einen solchen Satz als
    'mehrteilig' und schaltet nichts.
    """
    text = einebnen(satz)
    if not text:
        return []
    masken = {}
    for i, name in enumerate(sorted({einebnen(str(n)) for n in schutz if n},
                                    key=len, reverse=True)):
        if name and _UND.search(name) and _enthaelt_wort(name, text):
            platz = "\x00%d\x00" % i
            text = re.sub(r"(?<![a-z0-9])" + re.escape(name) + r"(?![a-z0-9])", platz, text)
            masken[platz] = name

    def zurueck(s):
        for platz, name in masken.items():
            s = s.replace(platz, name)
        return s

    stuecke = [s.strip() for s in _TEILER.split(text) if s.strip()]
    if len(stuecke) < 2:
        return [zurueck(text)]
    woerter = stuecke[0].split()
    anfang = []
    for w in woerter[:2]:
        if w in _GRENZWOERTER or "\x00" in w:
            break
        anfang.append(w)
    if len(anfang) == 2 and anfang[1] not in _PARTIKEL:
        anfang = anfang[:1]
    schluss = stuecke[-1].split()[-1]
    schluss = schluss if schluss in _PARTIKEL else ""
    erg = []
    for i, s in enumerate(stuecke):
        w = s.split()
        if i > 0 and anfang and w[0] != anfang[0] and w[0] not in _VERBEN:
            s = " ".join(anfang) + " " + s
        if i < len(stuecke) - 1 and schluss and w[-1] not in _PARTIKEL:
            s = s + " " + schluss
        erg.append(zurueck(s))
    return erg


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


def _zahl_oder_nichts(wert):
    if wert is None or isinstance(wert, bool) or str(wert).strip() == "":
        return None
    try:
        return float(str(wert).replace(",", "."))
    except ValueError:
        return None


def ziel_thema(schluessel: str, thema) -> str:
    """Das MQTT-Thema eines Ziels - dieselbe Regel wie sp_ziel_thema() in
    sp_lib.php: leer (auch nur '/' und Leerraum) heisst der Schluessel, und
    Schraegstriche am Rand und doppelte fallen weg.

    Bis 0.12.0 ging "thema": "/wz/licht/" unveraendert hinaus, und der Dienst
    sendete <praefix>//wz/licht//aktion - ein anderes Thema als das, das die
    Oberflaeche in der Loxone-Vorlage nennt (A1).
    """
    t = thema if isinstance(thema, (str, int, float)) and not isinstance(thema, bool) else ""
    t = str(t)
    if t.strip("/ \t") == "":
        t = str(schluessel)
    return "/".join(teil for teil in t.split("/") if teil != "")


def original_stueck(satz: str, eben: str) -> str:
    """Den Teil des URSPRUENGLICHEN Satzes, der eingeebnet 'eben' ergibt.

    Fuer Erinnerungen: verglichen wird auf dem eingeebneten Satz, gesprochen
    werden soll aber das Wort mit Umlaut und nicht "waesche" - Piper liest die
    Ersatzschreibung so, wie sie dasteht. Gesucht wird eine Folge ganzer
    Woerter des Originals, die eingeebnet genau 'eben' ergibt; ohne Treffer
    bleibt es beim eingeebneten Text.
    """
    ziel = einebnen(eben)
    if not ziel:
        return ""
    woerter = str(satz or "").split()
    flach = [einebnen(w) for w in woerter]
    for anfang in range(len(woerter)):
        stuecke = []
        for ende in range(anfang, len(woerter)):
            if flach[ende]:
                stuecke.append(flach[ende])
            zusammen = " ".join(stuecke)
            if zusammen == ziel:
                text = " ".join(woerter[anfang:ende + 1])
                return text.strip(" ,.;:!?\"'„“”")
            if len(zusammen) > len(ziel):
                break
    return ziel


# ---------------------------------------------------------------------------
# Eingebaute Regeln (seit 0.12.0)
#
# WARUM EINGEBAUT UND NICHT NUR IN DER SATZDATEI: eine Aktualisierung
# ueberschreibt config/plugins/<ordner>/saetze.json NIE. Was nur in
# templates/saetze_*.json stuende, kaeme bei einer bestehenden Anlage nie an.
# Die eingebauten Regeln gelten NACH den Regeln der Satzdatei: was der
# Benutzer selbst formuliert hat, geht vor. Sie stehen nicht in self.regeln -
# pruefen(), die Satzproben und die Zahl der Regeln bleiben die der Datei.
#
# Gruppen: timer (Timer nennen und abbrechen), erinnerung ("erinnere mich in
# 10 minuten an ..."), relativ ("mach das licht heller", "zwei grad waermer"),
# musik (Music-Server-Zone des Mikrofons; nur, wenn der Dienst sie anfragt -
# ab Werk aus, Schalter musik_steuern).
#
# 'ziel_pflicht': die Regel gilt nur mit einem GENANNTEN Ziel. "mach lauter"
# im Wohnzimmer meint die Musik, nicht das Licht, das am Mikrofon als Raum
# eingetragen ist.
# ---------------------------------------------------------------------------
_V_DE = "[mach|mache|stelle|stell|dreh|drehe|schalte]"
_V_EN = "[make|turn|set]"


def _r(gruppe, muster, absicht, aktion, **zusatz):
    regel = {"gruppe": gruppe, "muster": muster, "absicht": absicht, "aktion": aktion}
    pflicht = zusatz.pop("ziel_pflicht", False)
    if pflicht:
        regel["ziel_pflicht"] = 1
    if "einheit" in zusatz:
        regel["einheit"] = zusatz.pop("einheit")
    regel["_zusatz"] = zusatz
    return regel


EINGEBAUTE_REGELN = (
    # ---- Timer ----
    _r("timer", "[welche|was fuer] timer [laufen gerade|laufen|sind aktiv|sind gestellt|gibt es]",
       "timer", "liste"),
    _r("timer", "[wie viele|wieviele] timer [laufen|sind aktiv|gibt es]", "timer", "liste"),
    _r("timer", "[zeig|nenn|sag] mir [die|alle|meine] timer", "timer", "liste"),
    _r("timer", "[which|what] timers [are running|are active|are set|do i have|are there]",
       "timer", "liste"),
    _r("timer", "how many timers [are running|are active|are there|do i have]", "timer", "liste"),
    _r("timer", "[list|show] [all my|my|all|the|] timers", "timer", "liste"),
    _r("timer", "brich den timer [fuer|für|von] {ziel} ab", "timer", "abbrechen", umfang="ziel"),
    _r("timer", "[loesche|lösche|stoppe|beende|entferne] den timer [fuer|für|von] {ziel}",
       "timer", "abbrechen", umfang="ziel"),
    _r("timer", "[den |]timer [fuer|für|von] {ziel} [abbrechen|loeschen|löschen|stoppen|beenden]",
       "timer", "abbrechen", umfang="ziel"),
    _r("timer", "[cancel|delete|stop|clear|remove] the timer for {ziel}", "timer", "abbrechen",
       umfang="ziel"),
    _r("timer", "[loesche|lösche|stoppe|beende|entferne] [alle|saemtliche|sämtliche|alle meine] timer",
       "timer", "abbrechen", umfang="alle"),
    _r("timer", "[alle|alle meine|saemtliche|sämtliche] timer [abbrechen|loeschen|löschen|stoppen|beenden]",
       "timer", "abbrechen", umfang="alle"),
    _r("timer", "brich alle timer ab", "timer", "abbrechen", umfang="alle"),
    _r("timer", "[cancel|delete|stop|clear|remove] [all the|all my|all] timers", "timer",
       "abbrechen", umfang="alle"),
    _r("timer", "[loesche|lösche|stoppe|beende|entferne] [den|meinen] timer", "timer",
       "abbrechen", umfang="einer"),
    _r("timer", "brich [den|meinen] timer ab", "timer", "abbrechen", umfang="einer"),
    _r("timer", "[den |]timer [abbrechen|loeschen|löschen|stoppen|beenden]", "timer", "abbrechen",
       umfang="einer"),
    _r("timer", "[cancel|delete|stop|clear|remove] [the|my] timer", "timer", "abbrechen",
       umfang="einer"),
    # ---- Erinnerungen ----
    _r("erinnerung", "erinnere mich in {dauer} [an|daran|dass|das] {rest}", "erinnerung", "ansage"),
    _r("erinnerung", "erinnere mich {dauer} [an|daran|dass|das] {rest}", "erinnerung", "ansage"),
    _r("erinnerung", "erinnere mich [an|daran|dass|das] {rest} in {dauer}", "erinnerung", "ansage"),
    _r("erinnerung", "erinnere mich [an|daran|dass|das] {rest} {dauer}", "erinnerung", "ansage"),
    _r("erinnerung", "remind me in {dauer} [to|about|of|that] {rest}", "erinnerung", "ansage"),
    _r("erinnerung", "remind me {dauer} [to|about|of|that] {rest}", "erinnerung", "ansage"),
    _r("erinnerung", "remind me [to|about|of|that] {rest} in {dauer}", "erinnerung", "ansage"),
    _r("erinnerung", "remind me [to|about|of|that] {rest} {dauer}", "erinnerung", "ansage"),
    # ---- Musik (nur auf Anfrage des Dienstes) ----
    _r("musik", "[mach|mache|dreh|drehe|stelle|stell|] [die musik|musik|es|] lauter", "musik", "lauter"),
    _r("musik", "[mach|mache|dreh|drehe|stelle|stell|] [die musik|musik|es|] leiser", "musik", "leiser"),
    _r("musik", "[pause|musik pause|mach pause|pausiere|pausieren|pausiere die musik"
                "|halte die musik an|musik anhalten|musik stoppen|stoppe die musik]", "musik", "pause"),
    _r("musik", "[weiter|musik weiter|weiterspielen|spiel weiter|mach weiter|fortsetzen"
                "|musik fortsetzen|setze die musik fort]", "musik", "weiter"),
    _r("musik", "[naechster titel|nächster titel|naechstes lied|nächstes lied|naechster song"
                "|nächster song|naechstes stueck|nächstes stück|titel weiter|lied weiter"
                "|ueberspringen|überspringen]", "musik", "naechster"),
    _r("musik", "[vorheriger titel|voriger titel|vorheriges lied|voriges lied|letzter titel"
                "|letztes lied|titel zurueck|titel zurück|lied zurueck|lied zurück]",
       "musik", "vorheriger"),
    _r("musik", "[turn it up|turn up the music|turn the music up|louder|volume up|make it louder]",
       "musik", "lauter"),
    _r("musik", "[turn it down|turn down the music|turn the music down|quieter|volume down"
                "|make it quieter]", "musik", "leiser"),
    _r("musik", "[pause|pause the music|pause music|stop the music|stop music]", "musik", "pause"),
    _r("musik", "[resume|resume the music|resume playback|continue playing|unpause]",
       "musik", "weiter"),
    _r("musik", "[next|next song|next track|skip|skip song|skip this song]", "musik", "naechster"),
    _r("musik", "[previous|previous song|previous track|last song]", "musik", "vorheriger"),
    # ---- Relative Befehle ----
    _r("relativ", _V_DE + " [es|] {ziel} [um|] {wert} grad [waermer|wärmer|hoeher|höher|rauf|hoch]",
       "relativ", "hoch", art="temperatur", einheit="Grad"),
    _r("relativ", _V_DE + " [es|] {ziel} [um|] {wert} grad [kaelter|kälter|kuehler|kühler|niedriger|runter]",
       "relativ", "runter", art="temperatur", einheit="Grad"),
    _r("relativ", "[mach|mache|stelle|stell|dreh|drehe|] [es|] {wert} grad [waermer|wärmer|hoeher|höher] {ziel}",
       "relativ", "hoch", art="temperatur", einheit="Grad"),
    _r("relativ", "[mach|mache|stelle|stell|dreh|drehe|] [es|] {wert} grad [kaelter|kälter|kuehler|kühler|niedriger] {ziel}",
       "relativ", "runter", art="temperatur", einheit="Grad"),
    _r("relativ", _V_DE + " [es|] {ziel} [waermer|wärmer]", "relativ", "hoch", art="temperatur"),
    _r("relativ", _V_DE + " [es|] {ziel} [kaelter|kälter|kuehler|kühler]", "relativ", "runter",
       art="temperatur"),
    _r("relativ", _V_DE + " [es|] {ziel} heller", "relativ", "hoch", art="licht"),
    _r("relativ", _V_DE + " [es|] {ziel} dunkler", "relativ", "runter", art="licht"),
    _r("relativ", "[dimme|dimm] [es|] {ziel} [hoch|rauf|herauf]", "relativ", "hoch", art="licht"),
    _r("relativ", "[dimme|dimm] [es|] {ziel} [runter|herunter]", "relativ", "runter", art="licht"),
    _r("relativ", _V_DE + " {ziel} lauter", "relativ", "hoch", art="laut", ziel_pflicht=True),
    _r("relativ", _V_DE + " {ziel} leiser", "relativ", "runter", art="laut", ziel_pflicht=True),
    _r("relativ", _V_EN + " [it|] {ziel} {wert} [degrees|degree] [warmer|higher]", "relativ", "hoch",
       art="temperatur", einheit="degrees"),
    _r("relativ", _V_EN + " [it|] {ziel} {wert} [degrees|degree] [colder|cooler|lower]", "relativ",
       "runter", art="temperatur", einheit="degrees"),
    _r("relativ", "[make|turn|set|] [it|] {wert} [degrees|degree] [warmer|higher] {ziel}", "relativ",
       "hoch", art="temperatur", einheit="degrees"),
    _r("relativ", "[make|turn|set|] [it|] {wert} [degrees|degree] [colder|cooler|lower] {ziel}",
       "relativ", "runter", art="temperatur", einheit="degrees"),
    _r("relativ", _V_EN + " [it|] {ziel} warmer", "relativ", "hoch", art="temperatur"),
    _r("relativ", _V_EN + " [it|] {ziel} [colder|cooler]", "relativ", "runter", art="temperatur"),
    _r("relativ", _V_EN + " [it|] {ziel} brighter", "relativ", "hoch", art="licht"),
    _r("relativ", _V_EN + " [it|] {ziel} [darker|dimmer]", "relativ", "runter", art="licht"),
    _r("relativ", "[make|turn] {ziel} louder", "relativ", "hoch", art="laut", ziel_pflicht=True),
    _r("relativ", "[make|turn] {ziel} [quieter|softer]", "relativ", "runter", art="laut",
       ziel_pflicht=True),
)
# Bewusst NICHT eingebaut: "turn {ziel} up/down". 'down' ist in den
# englischen Satzdateien ausdruecklich kein Aus-Wort (saetze_en.json), und
# "turn the kitchen down" soll weiter gar nichts tun, statt zu raten, ob
# gedimmt, leiser oder kuehler gemeint ist.
# Ohne Angabe des Dienstes: alle Gruppen ausser der Musik (die braucht einen
# Schalter und eine Zone, siehe EINGEBAUTE_REGELN oben).
EINGEBAUT_VORGABE = ("timer", "erinnerung", "relativ")
# Die Reihenfolge der Gruppen: Musik vor den relativen Befehlen - "mach
# lauter" ohne Ziel gehoert der Musik.
_GRUPPEN_FOLGE = ("timer", "erinnerung", "musik", "relativ")


class Verstehen:
    """Haelt Regeln und Ziele und beantwortet Saetze."""

    def __init__(self, saetze: dict) -> None:
        self.regeln = []
        # Die eingebauten Regeln (siehe EINGEBAUTE_REGELN) - getrennt von den
        # Regeln der Satzdatei, damit pruefen() und die Satzproben nur die
        # Datei des Benutzers beurteilen.
        self.eingebaut = []
        for regel in EINGEBAUTE_REGELN:
            try:
                self.eingebaut.append((muster_zu_regex(regel["muster"]), dict(regel, eingebaut=1)))
            except re.error:                          # pragma: no cover - feste Muster
                continue
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
            alias = ziel.get("alias") or []
            # "alias": "tor" statt ["tor"]: bis 0.11.15 wurde der Text
            # Buchstabe fuer Buchstabe durchlaufen, und das Ziel hiess danach
            # 't', 'o' und 'r' (Befund M4). Ein einzelner Text ist EIN Alias.
            alias_als_text = isinstance(alias, str)
            if alias_als_text:
                alias = [alias]
            elif not isinstance(alias, (list, tuple)):
                alias = []
            namen = [einebnen(schluessel), einebnen(str(ziel.get("name") or ""))]
            namen += [einebnen(str(a)) for a in alias]
            self.ziele[schluessel] = {
                "schluessel": schluessel,
                "name": str(ziel.get("name") or schluessel),
                # Rand- und doppelte Schraegstriche weg, wie in sp_ziel_thema()
                # (A1) - sonst entstand <praefix>//wz/licht//aktion.
                "thema": ziel_thema(schluessel, ziel.get("thema")),
                "url": str(ziel.get("url") or ""),
                # Seit 0.10.0: der Lesepfad. Ohne ihn kann eine Frage nach
                # einem Zustand nicht beantwortet werden.
                "url_lesen": str(ziel.get("url_lesen") or ""),
                "einheit": str(ziel.get("einheit") or ""),
                # Seit 0.10.0: heikle Ziele fragen zurueck, statt zu schalten.
                "bestaetigen": 1 if ziel.get("bestaetigen") else 0,
                "namen": list(dict.fromkeys(n for n in namen if n)),
                # Seit 0.12.0: zulaessiger Bereich fuer {wert} (wahlweise).
                "min": _zahl_oder_nichts(ziel.get("min")),
                "max": _zahl_oder_nichts(ziel.get("max")),
                # Seit 0.12.0: Schrittweite fuer relative Befehle ("heller",
                # "zwei grad waermer" nimmt die Zahl aus dem Satz). Nur > 0.
                "schritt": (_zahl_oder_nichts(ziel.get("schritt"))
                            if (_zahl_oder_nichts(ziel.get("schritt")) or 0) > 0 else None),
                # Ausdruecklich eingetragener Schaltweg - 'thema' faellt sonst
                # auf den Schluessel zurueck und waere immer gesetzt.
                "schaltweg": 1 if (ziel.get("thema") or ziel.get("url")) else 0,
                "alias_als_text": 1 if alias_als_text else 0,
            }

    @staticmethod
    def _eignung(ziel: dict, absicht: str) -> tuple:
        """Wie gut passt ein Ziel zur Absicht - nur bei GLEICH langen Namen.

        Eine Frage will lesen: Ziele mit 'url_lesen' vorn. Wer schaltet, will
        ein Ziel mit eigenem Schaltweg und eher keinen reinen Fuehler. So
        duerfen ein Licht und ein Temperaturfuehler beide 'wohnzimmer'
        heissen (Befund M3): 'wie warm ist es im wohnzimmer' fragt den
        Fuehler, 'mach das wohnzimmer an' schaltet das Licht.
        """
        if absicht == "frage":
            return (1 if ziel["url_lesen"] else 0, 0)
        return (ziel["schaltweg"], 0 if ziel["url_lesen"] else 1)

    def _ziel_suchen(self, text: str, absicht: str = ""):
        """(Ziel, getroffener Name) oder (None, '')."""
        gesucht = einebnen(text)
        if not gesucht:
            return None, ""
        bester, bester_name, bester_rang = None, "", None
        for ziel in self.ziele.values():
            for name in ziel["namen"]:
                if name == gesucht:
                    stufe = 2
                elif _enthaelt_wort(name, gesucht):
                    stufe = 1
                else:
                    continue
                rang = (stufe, len(name), self._eignung(ziel, absicht))
                if bester_rang is None or rang > bester_rang:
                    bester, bester_name, bester_rang = ziel, name, rang
        return bester, bester_name

    def ziel_finden(self, text: str, absicht: str = ""):
        """Das Ziel mit der laengsten passenden Bezeichnung gewinnt.

        Ohne diese Regel wuerde 'wohnzimmer' auch dann greifen, wenn
        'wohnzimmer decke' gemeint war - die laengere Uebereinstimmung ist die
        genauere. Ein genauer Treffer geht vor einem enthaltenen.

        Enthalten heisst seit 0.12.0: als GANZES Wort. Bis 0.11.15 genuegte
        eine Teilzeichenkette, und mit dem Alias 'tor' schaltete 'mach den
        motor an' und 'schalte den ventilator ein' das Garagentor, 'mach die
        ofenlampe aus' den Ofen (Befund H4).
        """
        return self._ziel_suchen(text, absicht)[0]

    @staticmethod
    def _wache(fang: str, name: str):
        """Was bleibt im Ziel-Fang uebrig, das das Muster nicht vorsieht?

        Rueckgabe: None oder ein Ergebnis {'ok':0, 'grund':...}.
        """
        rest = einebnen(fang)
        if name:
            # Der Name zaehlt fuer 'mehrteilig' als Inhalt, deshalb ein
            # Platzhalter statt einer Luecke.
            rest = re.sub(r"(?<![a-z0-9])" + re.escape(name) + r"(?![a-z0-9])",
                          " \x00 ", rest, count=1)
        treffer = _VERNEINUNG.search(rest)
        if treffer:
            return {"ok": 0, "grund": "verneint", "gesucht": treffer.group(0)}
        zeit = _ZEIT_SPUR.search(rest)
        # Das 'and' in 'an hour and a half' oder 'und' in '2 stunden und 30
        # minuten' verbindet keine zwei Befehle: Zeitangaben zaehlen fuer
        # 'mehrteilig' als ein Stueck Inhalt.
        stuecke = _UND.split(_ZEIT_SPUR.sub(" \x01 ", rest))
        if len(stuecke) > 1 and sum(1 for s in stuecke if _hat_inhalt(s)) >= 2:
            return {"ok": 0, "grund": "mehrteilig", "gesucht": einebnen(fang)}
        if zeit:
            return {"ok": 0, "grund": "dauer_unklar", "gesucht": zeit.group(0).strip()}
        return None

    def erkennen(self, satz: str, vorgabeziel: str = "", jetzt=None,
                 eingebaut=EINGEBAUT_VORGABE) -> dict:
        """Rueckgabe: {'ok':1, 'absicht':..., 'ziel':..., ...} oder {'ok':0, 'grund':...}

        vorgabeziel ist das Ziel, das gilt, wenn der Satz KEINES nennt - in der
        Regel der Raum, in dem das Mikrofon steht. 'Mach das Licht an' bedeutet
        im Wohnzimmer etwas anderes als in der Kueche, und das Mikrofon weiss,
        wo es steht. Nennt der Satz ein Ziel, gewinnt der Satz.

        jetzt (datetime, wahlweise) gibt fuer Pruefungen die Uhrzeit vor, gegen
        die 'um 22 uhr' gerechnet wird.

        Gruende ohne Treffer, zusaetzlich zu den bisherigen (seit 0.12.0):
          dauer_unklar  Zeitangabe im Satz, die keine Regel aufnimmt, oder eine
                        Dauer von null / eine unlesbare Uhrzeit
          verneint      'nicht', 'kein', 'not', 'don't' im Satz, das Muster
                        sieht keine Verneinung vor
          mehrteilig    zwei Befehle oder Ziele, verbunden mit 'und'/'and'
          wert_bereich  {wert} ausserhalb min/max des Ziels (ohne Angabe bei
                        Prozent 0-100); Felder gesucht, wert, min, max

        eingebaut: welche Gruppen der eingebauten Regeln nach denen der
        Satzdatei versucht werden (EINGEBAUT_VORGABE; die Musik nur auf
        Anfrage, siehe EINGEBAUTE_REGELN). () schaltet sie ab.
        """
        eingeebnet = einebnen(satz)
        if not eingeebnet:
            return {"ok": 0, "grund": "leer"}
        if len(eingeebnet) > SATZ_HOECHSTLAENGE:
            return {"ok": 0, "grund": "kein_muster", "zu_lang": 1}
        # Schlaegt eine Wache an, wird die NAECHSTE Regel versucht - eine
        # weiter unten, die die Zeitangabe oder Verneinung aufnimmt, ist
        # gemeint. Erst wenn keine passt, gilt der erste Befund. Damit tut
        # auch eine alte Satzdatei, in der '[schalte|mach] {ziel} [aus|ab]'
        # noch VOR der Regel mit {dauer} steht, nichts Falsches mehr.
        zurueckgestellt = None
        gruppen = tuple(eingebaut or ())
        zusatz_regeln = [(a, r) for g in _GRUPPEN_FOLGE if g in gruppen
                         for a, r in self.eingebaut if r.get("gruppe") == g]
        for ausdruck, regel in list(self.regeln) + zusatz_regeln:
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
            if regel.get("eingebaut"):
                # Feste Zusatzfelder der eingebauten Regel (umfang, art).
                erg["eingebaut"] = 1
                erg.update(regel.get("_zusatz") or {})
            if felder.get("wert") is not None:
                erg["wert"] = zahl_lesen(felder["wert"])
                if erg["wert"] is None:
                    return {"ok": 0, "grund": "wert_unklar",
                            "gesucht": str(felder["wert"]).strip()}
            if felder.get("dauer") is not None:
                erg["dauer_s"] = dauer_in_sekunden(felder["dauer"], jetzt)
                if not erg["dauer_s"]:
                    # Das Muster hat gegriffen, die Zeitangabe laesst sich aber
                    # nicht umrechnen - oder sie ergibt null Sekunden ('in 0
                    # minuten'), und der Befehl liefe sofort. Abweisen und
                    # benennen, nicht raten.
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
                if name == "rest":
                    # Der Wortlaut fuer die Ansage (Erinnerungen) - mit
                    # Umlauten, siehe original_stueck(). Ein dict, damit
                    # antwort_fuellen() es nicht als Platzhalter nimmt.
                    erg.setdefault("original", {})["rest"] = original_stueck(satz, wert)
            gesucht_ziel = felder.get("ziel")
            # Ein Fang, der nach dem Einebnen nichts uebrig laesst, ist kein
            # genanntes Ziel - dann gilt die Vorgabe des Mikrofons.
            if gesucht_ziel is not None and einebnen(gesucht_ziel) == "":
                gesucht_ziel = None
            ziel = None
            if gesucht_ziel is not None:
                ziel, getroffen = self._ziel_suchen(gesucht_ziel, erg["absicht"])
                befund = self._wache(gesucht_ziel, getroffen)
                if befund is not None:
                    if zurueckgestellt is None:
                        zurueckgestellt = dict(befund, muster=erg["muster"])
                    continue
                # Nur Fuellwoerter ('das licht', 'the light') und kein Ziel,
                # das so heisst: kein genanntes Ziel.
                if ziel is None and _nur_fuellwoerter(gesucht_ziel):
                    gesucht_ziel = None
            aus_vorgabe = False
            if gesucht_ziel is None and regel.get("ziel_pflicht"):
                # Ohne GENANNTES Ziel gilt die Regel nicht (siehe
                # EINGEBAUTE_REGELN) - die naechste wird versucht.
                continue
            # Eine eingebaute Regel ohne {ziel} ("welche timer laufen",
            # "erinnere mich ...") bekommt KEIN Vorgabeziel: sonst fragte eine
            # Erinnerung in einem Raum mit heiklem Ziel "Soll ich ... wirklich
            # schalten?". Fuer die Regeln der Satzdatei bleibt es wie bisher.
            if gesucht_ziel is None and vorgabeziel \
                    and not (regel.get("eingebaut") and "ziel" not in felder):
                gesucht_ziel = vorgabeziel
                aus_vorgabe = True
                ziel = self._ziel_suchen(vorgabeziel, erg["absicht"])[0]
            if gesucht_ziel is None and "ziel" in felder:
                # Das Muster verlangt ein Ziel, der Satz nennt keines, und es
                # gibt keine Vorgabe. Ohne diesen Zweig ginge ein Schaltbefehl
                # mit leerem Ziel nach Loxone hinaus.
                return {"ok": 0, "grund": "ziel_fehlt",
                        "bekannt": sorted(z["name"] for z in self.ziele.values())}
            if gesucht_ziel is not None:
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
            # Bereich: bis 0.11.15 ging 'dimme das wohnzimmer auf 9999 prozent'
            # mit wert=9999 an Loxone (Befund M12). Gilt min/max des Ziels,
            # sonst bei Prozent 0 bis 100; ohne beides wird nicht geprueft.
            # Bei einem relativen Befehl ist {wert} die SCHRITTWEITE ("zwei
            # grad waermer"), kein Stellwert - die Grenzen prueft der Dienst am
            # Ergebnis aus Istwert und Schritt.
            if erg["wert"] is not None and erg["absicht"] != "relativ":
                unten = oben = None
                if ziel is not None and (ziel["min"] is not None or ziel["max"] is not None):
                    unten, oben = ziel["min"], ziel["max"]
                elif erg["einheit"].strip().lower() in ("prozent", "percent", "%"):
                    unten, oben = 0, 100
                if (unten is not None and erg["wert"] < unten) \
                        or (oben is not None and erg["wert"] > oben):
                    return {"ok": 0, "grund": "wert_bereich",
                            "gesucht": str(felder.get("wert") or "").strip(),
                            "wert": erg["wert"],
                            "min": None if unten is None else _runden(unten),
                            "max": None if oben is None else _runden(oben),
                            "einheit": erg["einheit"],
                            "zielname": erg["zielname"]}
            erg["antwort_vorlage"] = str(regel.get("antwort") or "")
            erg["antwort"] = self.antwort_fuellen(erg["antwort_vorlage"], erg)
            return erg
        return zurueckgestellt or {"ok": 0, "grund": "kein_muster"}

    def teilsaetze(self, satz: str) -> list:
        """teilsaetze() mit den Zielnamen dieser Satzdatei als Schutz."""
        return teilsaetze(satz, [n for z in self.ziele.values() for n in z["namen"]])

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

    @staticmethod
    def _freie_folge(muster: str) -> int:
        """Die laengste Folge freier Platzhalter ohne festes Wort dazwischen."""
        laengste = folge = 0
        for stueck in re.split(r"(\[[^\]]*\]|\{[a-z]+\})", muster):
            if stueck.startswith("{") and stueck.endswith("}"):
                if stueck[1:-1] in ("wert", "dauer"):
                    folge = 0       # eng umrissen, keine Rueckverfolgung
                else:
                    folge += 1
                    laengste = max(laengste, folge)
            elif stueck.startswith("[") and stueck.endswith("]"):
                alternativen = [einebnen(a) for a in stueck[1:-1].split("|")]
                if alternativen and all(alternativen):
                    folge = 0       # eine Alternative steht immer da
            elif einebnen(stueck):
                folge = 0
        return laengste

    def pruefen(self, hinweise: bool = False) -> list:
        """Beanstandungen an den Regeln - fuer den Reiter Saetze.

        Jede Zeile ist ein FEHLER (der Selbsttest zaehlt sie so). Mit
        hinweise=True kommen die Hinweise aus hinweise() dazu, jeweils mit
        'Hinweis: ' vorn - sie sind keine Fehler und gehoeren nicht rot.
        """
        meldungen = []
        for ausdruck, regel in self.regeln:
            if ausdruck is None:
                meldungen.append("Muster %r ist kein gueltiger Ausdruck: %s"
                                 % (regel.get("muster"), regel.get("_fehler")))
        # Mehr als zwei freie Platzhalter hintereinander: der Satz laesst sich
        # auf sehr viele Arten aufteilen, und jede wird durchprobiert (N6).
        for _, regel in self.regeln:
            anzahl = self._freie_folge(str(regel.get("muster") or ""))
            if anzahl > 2:
                meldungen.append(
                    "Muster %r hat %d freie Platzhalter ohne festes Wort dazwischen - "
                    "unklar, welcher Teil des Satzes wohin gehoert, und die Suche "
                    "kann den Dienst spuerbar aufhalten. Ein festes Wort "
                    "dazwischensetzen." % (regel.get("muster"), anzahl))
        for ziel in self.ziele.values():
            if not ziel["namen"]:
                meldungen.append("Ziel %r hat keine einzige Bezeichnung." % ziel["schluessel"])
        # Zwei Ziele mit derselben Bezeichnung: dann entscheidet der Zufall -
        # es sei denn, eines ist zum Lesen da (url_lesen) und das andere
        # nicht; dann entscheidet die Absicht (siehe _eignung), und es ist
        # nur ein Hinweis.
        gesehen = {}
        for ziel in self.ziele.values():
            for name in ziel["namen"]:
                frueher = gesehen.get(name)
                if frueher is not None and frueher["schluessel"] != ziel["schluessel"] \
                        and bool(frueher["url_lesen"]) == bool(ziel["url_lesen"]):
                    meldungen.append("Die Bezeichnung %r gehoert zu zwei Zielen (%s und %s)."
                                     % (name, frueher["schluessel"], ziel["schluessel"]))
                gesehen.setdefault(name, ziel)
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
        if hinweise:
            meldungen += ["Hinweis: " + h for h in self.hinweise()]
        return meldungen

    def hinweise(self) -> list:
        """Was auffaellt, aber funktioniert - kein Fehler, nur ein Hinweis."""
        meldungen = []
        for ziel in self.ziele.values():
            if ziel["alias_als_text"]:
                meldungen.append(
                    "Ziel %r: 'alias' ist ein einzelner Text statt einer Liste - "
                    "gelesen als ein Alias. Eintragen als [\"...\"]." % ziel["schluessel"])
            for name in ziel["namen"]:
                if len(name) < 4:
                    meldungen.append(
                        "Ziel %r: die Bezeichnung %r ist kuerzer als vier Zeichen - "
                        "die Spracherkennung verhoert sich bei so kurzen Woertern "
                        "leicht." % (ziel["schluessel"], name))
        # Ein Name als TEIL eines Wortes eines anderen Ziels ('tor' in
        # 'garagentor'): seit 0.12.0 trifft das nicht mehr, aber wer
        # 'garagentor' sagt und die Spracherkennung 'tor' hoert, schaltet das
        # andere Ziel. Ganze Woerter ('wohnzimmer' in 'wohnzimmer decke') sind
        # gewollt - dort gewinnt der laengere Name.
        for a in self.ziele.values():
            for b in self.ziele.values():
                if a["schluessel"] == b["schluessel"]:
                    continue
                for kurz in a["namen"]:
                    if kurz in b["namen"]:
                        continue    # gleicher Name an beiden Zielen: eigener Befund
                    for lang in b["namen"]:
                        if kurz != lang and kurz in lang and not _enthaelt_wort(kurz, lang):
                            meldungen.append(
                                "Die Bezeichnung %r (Ziel %s) steckt im Wort %r (Ziel %s) - "
                                "getrennt wird nur an Wortgrenzen, ein Verhoerer kann "
                                "aber das falsche Ziel treffen."
                                % (kurz, a["schluessel"], lang, b["schluessel"]))
        gesehen = {}
        for ziel in self.ziele.values():
            for name in ziel["namen"]:
                frueher = gesehen.get(name)
                if frueher is not None and frueher["schluessel"] != ziel["schluessel"] \
                        and bool(frueher["url_lesen"]) != bool(ziel["url_lesen"]):
                    meldungen.append(
                        "Die Bezeichnung %r gehoert zu %s und %s - eine Frage geht an "
                        "das Ziel mit 'url_lesen', ein Schaltbefehl an das andere."
                        % (name, frueher["schluessel"], ziel["schluessel"]))
                gesehen.setdefault(name, ziel)
        return meldungen


def laden(pfad: Path) -> Verstehen:
    try:
        return Verstehen(json.loads(pfad.read_text(encoding="utf-8")))
    except (OSError, ValueError):
        return Verstehen({"regeln": [], "ziele": {}})


if __name__ == "__main__":
    # Die Wurzel wie in sprachsteuerung_dienst.py: zuerst $LBHOMEDIR, wenn es
    # eine bezeichnet, dann die Suche - beide mit general.json. Ohne Wurzel
    # wird abgebrochen. Bis 0.11.8 fragte der Direktaufruf $LBHOMEDIR nicht,
    # nahm einen fremden Baum ohne general.json als Wurzel und las dessen
    # saetze.json; ohne Fund wurde "" + "/config/plugins/..." ein Pfad unter
    # / (gemessen am 18.09.2026, messe_nachtrag2.sh, Faelle W5, W10).
    _umgebung = os.environ.get("LBHOMEDIR") or ""
    if _umgebung and os.path.isdir(os.path.join(_umgebung, "config", "plugins")) \
            and os.path.isdir(os.path.join(_umgebung, "webfrontend")) \
            and os.path.isfile(os.path.join(_umgebung, "config", "system", "general.json")):
        _wurzel = _umgebung
    else:
        _wurzel = lb_wurzel_ermitteln()
    if not _wurzel:
        sys.stderr.write(
            "FEHLER: Es wurde kein LoxBerry-Wurzelverzeichnis gefunden. "
            "LBHOMEDIR bezeichnet keine, und oberhalb von %s traegt kein "
            "Verzeichnis config/plugins, webfrontend und "
            "config/system/general.json. Es wurde nichts gelesen.\n"
            % Path(__file__).resolve().parent)
        sys.exit(1)
    kandidaten = [Path(p) for p in (
        _wurzel + "/config/plugins/sprachsteuerung/saetze.json",
        str(Path(__file__).resolve().parent.parent / "templates" / "saetze_de.json"),
    )]
    quelle = next((k for k in kandidaten if k.is_file()), kandidaten[-1])
    v = laden(quelle)
    for beanstandung in v.pruefen():
        print("[FEHL]", beanstandung)
    for hinweis in v.hinweise():
        print("[HINW]", hinweis)
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
