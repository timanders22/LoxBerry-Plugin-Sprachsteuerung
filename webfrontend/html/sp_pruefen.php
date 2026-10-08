<?php
/**
 * Sprachsteuerung lokal - die Feldregeln der Einstellungen
 *
 * EINE Stelle fuer die Regeln, nach denen ein Wert der Konfiguration taugt:
 * Rechnernamen, Ports, Themenpraefix, Sprache, Weckwort, Modelle, Ruhezeit,
 * Mikrofone und der tts-Block, soweit ihn das Formular fuehrt.
 *
 * WARUM EINE EIGENE DATEI (0.12.0): bis 0.11.15 standen die Regeln nur im
 * Formular (htmlauth/index.php). Eine Sicherung lief an ihnen vorbei - was
 * das Formular abwies (Port 99999, Ruhezeit 25:99, neun Mikrofone, zwei mit
 * demselben Namen), kam ueber "Sicherung einspielen" unbesehen in die
 * Konfiguration. Jetzt prueft das Formular feldweise mit
 * sp_wert_pruefen()/sp_satelliten_befunde(), und das Einspielen (in
 * sp_sicherung_lesen()) mit sp_cfg_pruefen() - dieselben Regeln.
 *
 * Die Datei liegt unter html/, weil die Bibliothek sie einbindet; sie
 * definiert nur Funktionen und gibt nichts aus. Sie setzt sp_lib.php zur
 * Laufzeit voraus (sp_t, sp_grenzen, sp_auswahl, sp_url_ok, sp_cc_*), wird
 * aber erst beim Aufruf darauf angewiesen - das Einbinden selbst braucht
 * nichts.
 *
 * Rueckgabe ist KLARTEXT, unmaskiert: wer ihn in eine Seite schreibt, maskiert
 * ihn (sp_e). Die Sprachtexte tragen teils Auszeichnung (<span class=...>);
 * sp_pruef_klartext() nimmt sie heraus, damit ein Text in Protokoll,
 * Sicherungsmeldung und Seite gleich lautet.
 *
 * Kompatibel mit PHP 7.4 bis 8.5.
 */

/** Auszeichnung und Entitaeten weg - aus einem Sprachtext wird Klartext. */
function sp_pruef_klartext($text)
{
    return trim(html_entity_decode(strip_tags((string) $text), ENT_QUOTES, 'UTF-8'));
}

/**
 * Eine ganze Zahl aus der Konfiguration ODER aus dem Formular: int bleibt
 * int, eine Zeichenkette nur aus Ziffern (hoechstens neun, mit einem
 * Minuszeichen davor, wenn $negativ) wird zur Zahl. Alles andere - Kommazahl,
 * Wahrheitswert, Liste, Leerraum - ist null. Bewusst kein (int)-Guss:
 * (int) "80abc" ist 80, und genau das soll nicht still durchgehen.
 */
function sp_pruef_zahl($w, $negativ = false)
{
    if (is_int($w)) { return $w; }
    if (is_string($w) && preg_match($negativ ? '/^-?[0-9]{1,9}\z/' : '/^[0-9]{1,9}\z/', $w)) {
        return (int) $w;
    }
    return null;
}

/** Rechnername oder IP: dasselbe Muster wie bis 0.11.15 im Formular. */
function sp_pruef_host($w)
{
    return is_string($w) && preg_match('/^[A-Za-z0-9][A-Za-z0-9\.\-:_]{0,80}\z/', $w) === 1;
}

/**
 * Uhrzeit der Ruhezeit: 0:00 bis 23:59 (H:MM oder HH:MM); mit $ende auch
 * 24:00 - das Tagesende, nur als Ende ("bis").
 *
 * Bis 0.11.15 genuegte \d{1,2}:\d{2} - auch 25:99 ging durch, und Dienst wie
 * Bibliothek rechneten daraus still 23:59 (min(23, ..)). In Runde 1 von
 * 0.12.0 wurde 24:00 abgewiesen, weil beide Seiten es als 23:00 lasen. Seit
 * Runde 2 rechnen beide 24:00 als 1440 (_minuten() im Dienst,
 * sp_ruhe_aktiv()), und "bis 24:00" heisst bis Mitternacht. Als Beginn
 * bleibt 24:00 abgewiesen: ein Fenster, das um Mitternacht des Folgetags
 * beginnt, ist 0:00.
 */
function sp_pruef_uhrzeit($w, $ende = false)
{
    if ($ende && $w === '24:00') { return true; }
    return is_string($w) && preg_match('/^([01]?[0-9]|2[0-3]):[0-5][0-9]\z/', $w) === 1;
}

/** Lautstaerke je Ansage: -1 (leer = unveraendert) oder 1 bis 100, als Zahl oder Ziffernfolge. */
function sp_pruef_laut($w)
{
    $z = sp_pruef_zahl($w, true);
    return $z !== null && ($z === -1 || ($z >= 1 && $z <= 100));
}

/**
 * S2 (Runde 2): die Ausgabe eines Raums (satelliten[i].ausgabe) - ein Block
 * mit hoechstens diesen vier Feldern, jedes wahlweise; leer oder fehlend
 * heisst "wie global eingestellt". Dieselben Regeln wie die gleichnamigen
 * Felder im Block tts. Rueckgabe: '' (taugt) oder der Name des ersten
 * unbrauchbaren Felds ('ausgabe' fuer den Block selbst oder ein unbekanntes
 * Feld - eine Sicherung soll nichts hineintragen, das der Dienst nicht kennt).
 */
function sp_pruef_raumausgabe($a)
{
    if (!is_array($a)) { return 'ausgabe'; }
    foreach ($a as $k => $w) {
        if (!in_array((string) $k, array('alexa_geraet', 'google_geraet', 'cc_ziel', 'sonos_zone'), true)) {
            return 'ausgabe';
        }
        if (!is_string($w)) { return (string) $k; }
        if ($w === '') { continue; }
        $ok = $k === 'cc_ziel' ? sp_cc_ziel_ok($w) : sp_alexa_geraet_ok($w);
        if (!$ok) { return (string) $k; }
    }
    return '';
}

/** Die Sprachen, fuer die es Satzdateien gibt (templates/saetze_<sprache>.json). */
function sp_pruef_sprachen()
{
    return array('de', 'en');
}

/** Grenzen eines Zahlenfelds: aus templates/vorgaben.json, sonst die genannte Vorgabe. */
function sp_pruef_grenze($feld, $min, $max)
{
    $g = sp_grenzen();
    if (isset($g[$feld]) && is_array($g[$feld]) && count($g[$feld]) === 2) {
        return array((int) $g[$feld][0], (int) $g[$feld][1]);
    }
    return array((int) $min, (int) $max);
}

/** Ein Schalter (Haken): 0/1, auch als Wahrheitswert oder "0"/"1". */
function sp_pruef_schalter($w)
{
    return is_bool($w) || $w === 0 || $w === 1 || $w === '0' || $w === '1';
}

/**
 * EINEN Wert pruefen. $pfad ist der Schluessel der Konfiguration, in einem
 * Block mit Punkt ('tts.port', 'ruhe.von'). Rueckgabe: '' (taugt) oder der
 * Grund als Klartext. Unbekannte Schluessel taugen - geprueft wird, was eine
 * Regel hat, nicht was zufaellig in der Datei steht (messwerte,
 * mitschnitt_bis, aktionstoken).
 *
 * Ein Wert, der weder Zeichenkette noch Zahl ist (Liste aus name[]=, null),
 * faellt bei jeder Regel durch und bekommt deren Text.
 */
function sp_wert_pruefen($pfad, $wert)
{
    $kt = function ($text) { return sp_pruef_klartext($text); };
    switch ((string) $pfad) {
        case 'whisper_host':
        case 'piper_host':
        case 'wake_host':
        case 'llm_host':
            $lab = sp_t('EINST.L_' . strtoupper(substr($pfad, 0, -5)));
            if ($wert === '') { return $kt(sprintf(sp_t('EINST.FEHLER_LEER'), $lab)); }
            return sp_pruef_host($wert) ? '' : $kt(sprintf(sp_t('EINST.FEHLER_HOST'), $lab));

        case 'whisper_port':
        case 'piper_port':
        case 'wake_port':
        case 'llm_port':
            list($lo, $hi) = sp_pruef_grenze($pfad, 1, 65535);
            $z = sp_pruef_zahl($wert);
            return ($z !== null && $z >= $lo && $z <= $hi) ? ''
                : $kt(sprintf(sp_t('EINST.FEHLER_PORT'), sp_t('EINST.L_' . strtoupper(substr($pfad, 0, -5)))));

        case 'wartezeit':
        case 'verlauf_zeilen':
        case 'ansage_abstand_s':
        case 'ansage_je_tag':
        case 'kontext_s':
        case 'bestaetigung_s':
        case 'herzschlag_s':
            // Ohne Eintrag in den Grenzen keine Regel - wie bisher im Formular.
            $g = sp_grenzen();
            if (!isset($g[$pfad])) { return ''; }
            $lab = $pfad === 'herzschlag_s' ? sp_t('MQTT.L_HERZSCHLAG') : sp_t('EINST.L_' . strtoupper($pfad));
            $z = sp_pruef_zahl($wert);
            if ($z === null) { return $kt(sprintf(sp_t('EINST.FEHLER_ZAHL'), $lab)); }
            if ($z < (int) $g[$pfad][0] || $z > (int) $g[$pfad][1]) {
                return $kt(sprintf(sp_t('EINST.FEHLER_BEREICH'), $lab, (int) $g[$pfad][0], (int) $g[$pfad][1]));
            }
            return '';

        case 'sprache':
            return (is_string($wert) && in_array($wert, sp_pruef_sprachen(), true)) ? ''
                : $kt(sprintf(sp_t('UI012.FEHLER_SPRACHE_LISTE'), implode(', ', sp_pruef_sprachen())));

        case 'wakeword':
            if ($wert === '') { return $kt(sprintf(sp_t('EINST.FEHLER_LEER'), sp_t('EINST.L_WAKEWORD'))); }
            return (is_string($wert) && preg_match('/^[a-z0-9_\-]{1,40}\z/', $wert)) ? '' : $kt(sp_t('EINST.FEHLER_WAKEWORD'));

        case 'mqtt_topic':
            return (is_string($wert) && preg_match('#^[A-Za-z0-9_/\-]{1,64}\z#', $wert) && trim($wert, '/') !== '')
                ? '' : $kt(sp_t('EINST.FEHLER_TOPIC'));

        case 'miniserver_url':
            // Leer heisst: keine eigene Adresse. Die Form allein - Zugangsdaten
            // darin sind erlaubt und erscheinen in keinem Text. Seit Runde 2
            // auch ms://<nr>/<pfad> (Miniserver aus der LoxBerry-Einstellung).
            return (is_string($wert) && ($wert === '' || sp_url_ok($wert, 'ms'))) ? '' : $kt(sp_t('UI013.FEHLER_URL_MS'));

        case 'llm_ein':
        case 'antwort_sprechen':
        case 'mqtt_ein':
        case 'ruhe.ein':
        case 'mqtt_dauer_ein':
        case 'musik_ducken':
        case 'musik_steuern':
        case 'docker_neustart':
            return sp_pruef_schalter($wert) ? '' : $kt(sprintf(sp_t('UI012.FEHLER_SCHALTER'), $pfad));

        case 'musik_ducken_laut':
            // S5: 0 bis 100 (0 = Musik ganz aus, solange gesprochen wird).
            list($lo, $hi) = sp_pruef_grenze('musik_ducken_laut', 0, 100);
            $z = sp_pruef_zahl($wert);
            return ($z !== null && $z >= $lo && $z <= $hi) ? ''
                : $kt(sprintf(sp_t('EINST.FEHLER_BEREICH'), sp_t('UI013.L_MUSIK_DUCKEN_LAUT'), $lo, $hi));

        case 'antwortweg':
            return (is_string($wert) && in_array($wert, sp_auswahl('antwortweg'), true)) ? '' : $kt(sp_t('EINST.FEHLER_ANTWORTWEG'));

        case 'whisper_modell':
        case 'piper_stimme':
        case 'llm_modell':
            $muster = array('whisper_modell' => '/^[A-Za-z0-9_.\/\-]{0,80}\z/',
                            'piper_stimme'   => '/^[A-Za-z0-9_.\-]{0,60}\z/',
                            'llm_modell'     => '/^[A-Za-z0-9_.\/\-:]{0,120}\z/');
            return (is_string($wert) && preg_match($muster[$pfad], $wert)) ? ''
                : $kt(sprintf(sp_t('DIENST.FEHLER_MODELL'), sp_t('DIENST.L_' . strtoupper($pfad))));

        case 'tts.mode':
            // sp_tts_modi(): die Liste der Vorgabendatei, notfalls um sonos4lox ergaenzt.
            return (is_string($wert) && in_array($wert, sp_tts_modi(), true)) ? '' : $kt(sp_t('EINST.FEHLER_TTS_MODUS'));

        case 'tts.sonos_zone':
            // S1: wie in sprachausgabe.php (ansage_geraet_ok()); leer = nicht eingestellt.
            return sp_alexa_geraet_ok($wert) ? '' : $kt(sp_t('UI013.M_SONOS_ZONE'));

        case 'tts.sonos_laut':
            return sp_pruef_laut($wert) ? '' : $kt(sp_t('UI013.M_SONOS_LAUT'));

        case 'tts.ip':
            return (is_string($wert) && ($wert === '' || sp_pruef_host($wert))) ? '' : $kt(sp_t('EINST.FEHLER_TTS_IP'));

        case 'tts.port':
            $z = sp_pruef_zahl($wert);
            return ($z !== null && $z >= 1 && $z <= 65535) ? '' : $kt(sprintf(sp_t('EINST.FEHLER_PORT'), sp_t('EINST.L_TTS_PORT')));

        case 'tts.volume':
            list($lo, $hi) = sp_pruef_grenze('tts_volume', 1, 100);
            $z = sp_pruef_zahl($wert);
            return ($z !== null && $z >= $lo && $z <= $hi) ? ''
                : $kt(sprintf(sp_t('EINST.FEHLER_BEREICH'), sp_t('EINST.L_TTS_VOLUME'), $lo, $hi));

        case 'tts.zones':
            if ($wert === '') { return $kt(sprintf(sp_t('EINST.FEHLER_LEER'), sp_t('EINST.L_TTS_ZONES'))); }
            return (is_string($wert) && preg_match('/^[0-9~,\s]{1,80}\z/', $wert)) ? '' : $kt(sp_t('EINST.FEHLER_TTS_ZONEN'));

        case 'tts.lang':
            if ($wert === '') { return $kt(sprintf(sp_t('EINST.FEHLER_LEER'), sp_t('EINST.L_TTS_LANG'))); }
            return (is_string($wert) && preg_match('/^[a-z]{2,5}\z/', $wert)) ? '' : $kt(sp_t('EINST.FEHLER_SPRACHE'));

        case 'tts.stimme':
            return (is_string($wert) && preg_match('/^[A-Za-z0-9_.\-]{0,60}\z/', $wert)) ? ''
                : $kt(sprintf(sp_t('DIENST.FEHLER_MODELL'), sp_t('EINST.L_TTS_STIMME')));

        case 'tts.template':
            return (is_string($wert) && ($wert === '' || sp_url_ok($wert))) ? '' : $kt(sp_t('EINST.FEHLER_TTS_VORLAGE'));

        case 'tts.cc_praefix':
            return sp_cc_praefix_ok($wert) ? '' : $kt(sp_t('EINST.FEHLER_CC_PRAEFIX'));

        case 'tts.cc_ziel':
            // Leer steht in der eigenen Sicherung, wenn kein brauchbares Ziel
            // gespeichert war (sp_config()). Das Formular verlangt selbst
            // einen Wert - das prueft es vorher.
            return ($wert === '' || sp_cc_ziel_ok($wert)) ? '' : $kt(sp_t('EINST.FEHLER_CC_ZIEL'));

        case 'tts.alexa_geraet':
            return sp_alexa_geraet_ok($wert) ? '' : $kt(sp_t('EINST.FEHLER_ALEXA_GERAET'));

        case 'tts.google_geraet':
            return sp_alexa_geraet_ok($wert) ? '' : $kt(sp_t('EINST.FEHLER_GOOGLE_GERAET'));

        case 'tts.alexa_laut':
        case 'tts.google_laut':
            /* -1 heisst "unveraendert" (leeres Feld). 0 wird seit 0.12.0
             * abgewiesen: das andere Plugin spricht dann stumm und meldet
             * trotzdem OK=1 - eine Ansage, die als gesendet gilt und die
             * niemand hoert, und der Rueckfall auf die Lautsprecher der
             * Sprachgeraete greift nicht. */
            $z = sp_pruef_zahl($wert, true);
            $lab = $pfad === 'tts.alexa_laut' ? sp_t('EINST.L_ALEXA_LAUT') : sp_t('EINST.L_GOOGLE_LAUT');
            return ($z !== null && ($z === -1 || ($z >= 1 && $z <= 100))) ? ''
                : $kt(sprintf(sp_t('UI012.FEHLER_LAUT'), $lab));

        case 'tts.alexa_token':
            return (is_string($wert) && ($wert === '' || sp_alexa_token_ok($wert))) ? '' : $kt(sp_t('EINST.FEHLER_ALEXA_TOKEN'));

        case 'tts.google_token':
            return (is_string($wert) && ($wert === '' || sp_alexa_token_ok($wert))) ? '' : $kt(sp_t('EINST.FEHLER_GOOGLE_TOKEN'));

        case 'ruhe.von':
        case 'ruhe.bis':
            if ($wert === '') {
                return $kt(sprintf(sp_t('EINST.FEHLER_LEER'), sp_t('EINST.L_RUHE_' . strtoupper(substr($pfad, 5)))));
            }
            // 24:00 nur als Ende (Runde 2, A7).
            if ($pfad === 'ruhe.bis') {
                return sp_pruef_uhrzeit($wert, true) ? '' : $kt(sp_t('UI013.FEHLER_RUHE_BIS'));
            }
            return sp_pruef_uhrzeit($wert) ? '' : $kt(sp_t('UI012.FEHLER_RUHEZEIT'));
    }
    return '';
}

/**
 * Die Mikrofonliste pruefen. Die Schluessel der Liste sind die Zeilen (0 =
 * Zeile 1): das Formular reicht die Zeilennummer der Tabelle durch, eine
 * Sicherung ihre Listenstelle. Rueckgabe: Liste von array('idx' => Zeile
 * oder null, 'feld' => name|art|host|port|raum|zone|schluessel|'', 'text').
 *
 * Je Zeile der ERSTE Befund (wie bis 0.11.15 im Formular); danach doppelte
 * Namen. Der Name eines Mikrofons ist sein Schluessel im Dienst
 * (satellit_eintrag(): name, sonst host) - zwei gleiche Namen hiessen, dass
 * der Zustand des einen beim anderen steht. Verglichen wird ohne Gross-
 * und Kleinschreibung, und der leere Name zaehlt als die Adresse.
 */
function sp_satelliten_befunde($liste)
{
    $kt = function ($text) { return sp_pruef_klartext($text); };
    $aus = array();
    if (!is_array($liste)) {
        return array(array('idx' => null, 'feld' => '', 'text' => $kt(sp_t('UI012.FEHLER_MIKRO_LISTE'))));
    }
    $belegt = 0;
    foreach ($liste as $s) { if ($s !== null) { $belegt++; } }
    if ($belegt > 8) {
        $aus[] = array('idx' => null, 'feld' => '', 'text' => $kt(sprintf(sp_t('UI012.FEHLER_MIKRO_ACHT'), $belegt)));
    }
    $namen = array();
    foreach ($liste as $i => $s) {
        $zeile = is_int($i) ? $i + 1 : 0;
        $idx = is_int($i) ? $i : null;
        if (!is_array($s)) {
            $aus[] = array('idx' => $idx, 'feld' => '', 'text' => $kt(sprintf(sp_t('UI012.FEHLER_MIKRO_EINTRAG'), $zeile)));
            continue;
        }
        $art = array_key_exists('art', $s) ? $s['art'] : 'wyoming';
        if (!is_string($art) || !in_array($art, array('wyoming', 'esphome'), true)) {
            $aus[] = array('idx' => $idx, 'feld' => 'art', 'text' => $kt(sprintf(sp_t('UI012.FEHLER_MIKRO_ART'), $zeile)));
            continue;
        }
        $host = array_key_exists('host', $s) ? $s['host'] : '';
        if ($host === '' || $host === null) {
            $aus[] = array('idx' => $idx, 'feld' => 'host', 'text' => $kt(sprintf(sp_t('MIKRO.FEHLER_HOST_FEHLT'), $zeile)));
            continue;
        }
        if (!sp_pruef_host($host)) {
            $aus[] = array('idx' => $idx, 'feld' => 'host', 'text' => $kt(sprintf(sp_t('MIKRO.FEHLER_HOST'), $zeile)));
            continue;
        }
        if (array_key_exists('port', $s)) {
            $z = sp_pruef_zahl($s['port']);
            if ($z === null || $z < 1 || $z > 65535) {
                $aus[] = array('idx' => $idx, 'feld' => 'port', 'text' => $kt(sprintf(sp_t('MIKRO.FEHLER_PORT'), $zeile)));
                continue;
            }
        }
        if (array_key_exists('zone', $s)
            && !(is_string($s['zone']) && ($s['zone'] === '' || preg_match('/^[0-9~,]{1,40}\z/', $s['zone'])))) {
            $aus[] = array('idx' => $idx, 'feld' => 'zone', 'text' => $kt(sprintf(sp_t('MIKRO.FEHLER_ZONE'), $zeile)));
            continue;
        }
        $falsch = '';
        foreach (array('name', 'raum') as $f) {
            if (array_key_exists($f, $s) && !(is_string($s[$f]) && strlen($s[$f]) <= 200
                && preg_match('//u', $s[$f]) === 1 && !preg_match('/[\x00-\x1F\x7F]/', $s[$f]))) {
                $falsch = $f;
                break;
            }
        }
        if ($falsch !== '') {
            $aus[] = array('idx' => $idx, 'feld' => $falsch, 'text' => $kt(sprintf(sp_t('UI012.FEHLER_MIKRO_TEXT'), $zeile)));
            continue;
        }
        if (array_key_exists('schluessel', $s) && !is_string($s['schluessel'])) {
            $aus[] = array('idx' => $idx, 'feld' => 'schluessel', 'text' => $kt(sprintf(sp_t('UI012.FEHLER_MIKRO_EINTRAG'), $zeile)));
            continue;
        }
        // S2: die Ausgabe dieses Raums - feld 'ausgabe.<name>' fuer die Markierung im Formular.
        if (array_key_exists('ausgabe', $s)) {
            $af = sp_pruef_raumausgabe($s['ausgabe']);
            if ($af !== '') {
                // Zwei woertliche Aufrufe statt eines Ternaers in sp_t() (die
                // Pruefwerkzeuge lesen die Aufrufstelle woertlich).
                $text = $af === 'ausgabe'
                    ? sprintf(sp_t('UI013.FEHLER_RAUMAUSGABE_BLOCK'), $zeile)
                    : sprintf(sp_t('UI013.FEHLER_RAUMAUSGABE'), $zeile, sp_t('UI013.L_RA_' . strtoupper($af)));
                $aus[] = array('idx' => $idx, 'feld' => $af === 'ausgabe' ? 'ausgabe' : 'ausgabe.' . $af,
                               'text' => $kt($text));
                continue;
            }
        }
        $name = (isset($s['name']) && $s['name'] !== '') ? (string) $s['name'] : (string) $host;
        $vgl = strtolower($name);
        if (isset($namen[$vgl])) {
            $aus[] = array('idx' => $idx, 'feld' => 'name',
                           'text' => $kt(sprintf(sp_t('UI012.FEHLER_MIKRO_DOPPELT'), $zeile, $name, $namen[$vgl])));
            continue;
        }
        $namen[$vgl] = $zeile;
    }
    return $aus;
}

/**
 * Eine ganze Konfiguration (oder den config-Teil einer Sicherung) pruefen.
 * Geprueft wird jeder vorhandene Schluessel, fuer den es eine Regel gibt;
 * ein fehlender ist kein Fehler (er bekommt beim Lesen seine Vorgabe).
 * Rueckgabe: Liste der Gruende als Klartext, leer = taugt.
 */
function sp_cfg_pruefen(array $cfg)
{
    $fehler = array();
    $einfach = array('whisper_host', 'whisper_port', 'piper_host', 'piper_port', 'wake_host', 'wake_port',
                     'llm_host', 'llm_port', 'wartezeit', 'verlauf_zeilen', 'ansage_abstand_s', 'ansage_je_tag',
                     'kontext_s', 'bestaetigung_s', 'herzschlag_s', 'sprache', 'wakeword', 'mqtt_topic',
                     'miniserver_url', 'llm_ein', 'antwort_sprechen', 'mqtt_ein', 'antwortweg',
                     'whisper_modell', 'piper_stimme', 'llm_modell',
                     // Runde 2 (S3, S5-S7): fehlen sie (aeltere Sicherung), ist das kein Fehler.
                     'mqtt_dauer_ein', 'musik_ducken', 'musik_ducken_laut', 'musik_steuern', 'docker_neustart');
    foreach ($einfach as $k) {
        if (!array_key_exists($k, $cfg)) { continue; }
        $t = sp_wert_pruefen($k, $cfg[$k]);
        if ($t !== '') { $fehler[] = $t; }
    }
    foreach (array('tts' => array('mode', 'ip', 'port', 'volume', 'zones', 'lang', 'stimme', 'template',
                                  'cc_praefix', 'cc_ziel', 'alexa_geraet', 'alexa_laut', 'alexa_token',
                                  'google_geraet', 'google_laut', 'google_token', 'sonos_zone', 'sonos_laut'),
                   'ruhe' => array('ein', 'von', 'bis')) as $block => $felder) {
        if (!array_key_exists($block, $cfg)) { continue; }
        if (!is_array($cfg[$block])) {
            $fehler[] = sp_pruef_klartext(sprintf(sp_t('UI012.FEHLER_BLOCK'), $block));
            continue;
        }
        foreach ($felder as $f) {
            if (!array_key_exists($f, $cfg[$block])) { continue; }
            $t = sp_wert_pruefen($block . '.' . $f, $cfg[$block][$f]);
            if ($t !== '') { $fehler[] = $t; }
        }
    }
    if (array_key_exists('satelliten', $cfg)) {
        $liste = $cfg['satelliten'];
        // Eine Liste, kein Block mit Namen: nur fortlaufende Stellen.
        if (is_array($liste) && $liste !== array() && array_keys($liste) !== range(0, count($liste) - 1)) {
            $liste = null;
        }
        foreach (sp_satelliten_befunde($liste) as $b) { $fehler[] = $b['text']; }
    }
    return $fehler;
}
