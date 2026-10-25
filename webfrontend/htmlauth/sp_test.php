<?php
/**
 * Sprachsteuerung lokal - Selbstpruefung und die Aktionen des Reiters Test
 *
 * Die Selbstpruefung beantwortet OHNE Loxone und ohne Mikrofon die Frage:
 * traegt die Einrichtung? Jede Zeile nennt die Abhilfe mit.
 */

function sp_pruefzeile($stand, $frage, $antwort)
{
    return array('stand' => $stand, 'frage' => $frage, 'antwort' => $antwort);
}

/** Nimmt jemand auf diesem Port Verbindungen an? */
function sp_erreichbar($host, $port, $zeit = 3)
{
    $fp = @fsockopen($host, (int) $port, $errno, $errstr, $zeit);
    if ($fp) { fclose($fp); return array(1, ''); }
    return array(0, $errstr !== '' ? $errstr : sprintf(sp_t('UI012.FEHLER_NR'), (int) $errno));
}

function sp_pruefungen()
{
    $cfg = sp_config();
    $p = sp_paths();
    $zeilen = array();

    /* ---- Der eigene Endpunkt zuerst ----
     * Achtzehn Pruefzeilen und keine einzige rief den Weg auf, den Loxone
     * geht. Genau dort ist das Heimkino-Plugin zwei Fassungen lang gestorben,
     * ohne dass es jemand bemerkt hat. */
    list($sp_st, $sp_tx) = sp_endpunkt_probe();
    $zeilen[] = sp_pruefzeile($sp_st, sp_t('TEST.F_ENDPUNKT'), $sp_tx);

    $pid = sp_dienst_pid();
    $zeilen[] = sp_pruefzeile($pid > 0 ? 1 : 0, sp_t('TEST.F_DIENST'),
        $pid > 0 ? sp_t('TEST.A_DIENST_LAEUFT') . ' ' . $pid
                 : (sp_dienst_soll() ? sp_t('TEST.A_DIENST_SOLL_TOT') : sp_t('TEST.A_DIENST_GESTOPPT')));

    // Die virtuelle Python-Umgebung - ohne sie startet der Dienst nicht.
    $venv = $p['bindir'] . '/venv/bin/python3';
    $zeilen[] = sp_pruefzeile(is_file($venv) ? 1 : 0, sp_t('TEST.F_VENV'),
        is_file($venv) ? '<span class="sm-mono">' . sp_e($venv) . '</span>'
                       : sprintf(sp_t('TEST.A_VENV_FEHLT'), sp_e($venv)));

    // Adresse und Port kommen aus sp_dienst_ziel() - derselben Stelle, die
    // auch der Endpunkt benutzt. Bis 0.10.1 las diese Schleife die
    // Konfiguration roh; bei leerem Rechnernamen meldete sie eine Stoerung,
    // waehrend diag mit 127.0.0.1 richtig antwortete. Zwei Abrufwege, zwei
    // Antworten auf dieselbe Frage.
    /* S15 (0.12.7, Pruefbericht): ein offener Port ist noch kein Sprachdienst. Erkannt wird wie in der Ampel des
     * Reiters Dienste (sp_dienst_erkennen(): Wyoming-describe bzw. /health des Sprachmodells); ein anderer Dienst auf
     * dem Port ist rot. Bis 0.12.6 war die Zeile dann gruen, die Ampel daneben rot.
     * S14: den Wortwecker braucht nur ein Wyoming-Mikrofon - ohne eines ist er grau mit dem Satz des
     * Dienst-Selbsttests (dort seit 0.12.0 [INFO]); bis 0.12.6 stand die Zeile rot. */
    $sp_wyoming = 0;
    foreach ((isset($cfg['satelliten']) && is_array($cfg['satelliten']) ? $cfg['satelliten'] : array()) as $sp_s) {
        if (is_array($sp_s) && !empty($sp_s['host'])
            && (isset($sp_s['art']) ? (string) $sp_s['art'] : 'wyoming') !== 'esphome') {
            $sp_wyoming++;
        }
    }
    foreach (array('whisper' => 'TEST.F_WHISPER', 'piper' => 'TEST.F_PIPER',
                   'wake' => 'TEST.F_WAKE', 'llm' => 'TEST.F_LLM') as $dienst => $schluessel) {
        if ($dienst === 'llm' && empty($cfg['llm_ein'])) {
            $zeilen[] = sp_pruefzeile(-1, sp_t('TEST.F_LLM'), sp_t('TEST.A_LLM_AUS'));
            continue;
        }
        $sp_dn = $dienst === 'wake' ? 'wakeword' : $dienst;
        list($host, $port) = sp_dienst_ziel($sp_dn, $cfg);
        list($ok, $grund) = sp_dienst_erkennen($sp_dn, $host, $port, 3.0);
        if ($ok === 1) {
            $zeilen[] = sp_pruefzeile(1, sp_t($schluessel), sp_e($host . ':' . $port));
        } elseif ($ok === 2) {
            $sp_laedt = $grund === sp_t('LIB012.ERKENNEN_LAEDT');
            $zeilen[] = sp_pruefzeile($sp_laedt ? -1 : 0, sp_t($schluessel),
                sprintf(sp_t('TEST.A_DIENST_FREMD'), sp_e($host . ':' . $port), sp_e($grund)));
        } else {
            list(, $grund) = sp_erreichbar($host, $port);
            $zeilen[] = ($dienst === 'wake' && $sp_wyoming === 0)
                ? sp_pruefzeile(-1, sp_t($schluessel), sprintf(sp_t('TEST.A_WAKE_UNNOETIG'), sp_e($host . ':' . $port)))
                : sp_pruefzeile(0, sp_t($schluessel),
                    sprintf(sp_t('TEST.A_DIENST_STUMM'), sp_e($host . ':' . $port), sp_e($grund)));
        }
    }

    /* Seit 0.11.11 (Bauliste Einrichtung E8): die Gesamtzeile der Ampel aus
     * dem Reiter Dienste. Kein Haken ueber einer leeren Menge - ohne einen
     * einzigen hier vorgesehenen Dienst bleibt die Zeile grau, und
     * ausgelagerte Dienste stehen getrennt dahinter. */
    $sp_ca = sp_ct_ampel($cfg, null);
    $zeilen[] = sp_pruefzeile($sp_ca['gesamt'][0], sp_t('TEST.F_CT_EINGERICHTET'),
        sp_e($sp_ca['gesamt'][1])
        . ($sp_ca['gesamt'][2] !== '' ? '<br>' . sp_e($sp_ca['gesamt'][2]) : ''));

    $sats = isset($cfg['satelliten']) && is_array($cfg['satelliten']) ? $cfg['satelliten'] : array();
    $saetze = sp_saetze();
    $ziele = isset($saetze['ziele']) && is_array($saetze['ziele']) ? $saetze['ziele'] : array();
    if (!$sats) {
        $zeilen[] = sp_pruefzeile(-1, sp_t('TEST.F_MIKROFONE'), sp_t('TEST.A_KEINE_MIKROFONE'));
    } else {
        foreach ($sats as $s) {
            $art = isset($s['art']) && $s['art'] === 'esphome' ? 'esphome' : 'wyoming';
            $host = (string) (isset($s['host']) ? $s['host'] : '');
            $port = (int) (isset($s['port']) && $s['port'] ? $s['port'] : ($art === 'esphome' ? 6053 : 10700));
            list($ok, $grund) = sp_erreichbar($host, $port);
            /* Bei ESPHome ist ein offener Port KEIN Beleg dafuer, dass der
             * Audioweg traegt - das ist der ungepruefte Teil des Plugins. Bis
             * 0.9.11 stand hier ein gruener Haken, und der war eine
             * Behauptung. */
            $stand = $ok ? ($art === 'esphome' ? -1 : 1) : 0;
            $text = $ok
                ? sp_e($host . ':' . $port)
                  . ($art === 'esphome' ? ' &mdash; ' . sp_t('TEST.A_ESPHOME_UNGEPRUEFT') : '')
                : sprintf(sp_t('TEST.A_MIKRO_STUMM'), sp_e($host . ':' . $port), sp_e($grund));
            /* Der eingetragene Raum muss in der Zielliste stehen, sonst geht
             * 'mach an' an diesem Mikrofon ins Leere. */
            $raum = trim((string) (isset($s['raum']) ? $s['raum'] : ''));
            if ($raum !== '' && !sp_raum_bekannt($raum, $ziele)) {
                $stand = 0;
                $text .= ' &mdash; ' . sprintf(sp_t('TEST.A_RAUM_UNBEKANNT'), sp_e($raum));
            }
            $zeilen[] = sp_pruefzeile($stand,
                sp_e((string) (isset($s['name']) ? $s['name'] : $host))
                . ' <span class="sm-mono">' . sp_e($art) . '</span>', $text);
        }
    }

    // Die Satzdatei prueft der Dienst; hier nur die groben Zahlen.
    $regeln = isset($saetze['regeln']) ? count((array) $saetze['regeln']) : 0;
    $zeilen[] = sp_pruefzeile($regeln > 0 && $ziele ? 1 : 0, sp_t('TEST.F_SAETZE'),
        $regeln > 0 && $ziele ? sprintf(sp_t('TEST.A_SAETZE'), $regeln, count($ziele))
                              : sp_t('TEST.A_KEINE_SAETZE'));

    /* Veroeffentlicht DIESES Plugin ueberhaupt? (Regeln/04, B46 aus
     * BatterieBMS 0.9.17, 06.09.2026)
     *
     * Die Zeile darunter liest den Autostart des GATEWAYS aus der
     * general.json - das ist eine Aussage ueber LoxBerry, nicht ueber dieses
     * Plugin. Steht der eigene Schalter auf aus, geht nichts an den Broker
     * und damit nichts an Loxone; der Reiter zeigte dazu trotzdem einen
     * gruenen Haken und konnte die beiden Faelle gar nicht unterscheiden.
     * Am Geraet gemessen (BatterieBMS, 06.09.2026): Dienst lief, Gateway
     * lief, 35 s Mithoeren am Broker bei 30 s Takt - keine einzige Nachricht.
     *
     * Grau statt rot: ausgeschaltet ist eine Entscheidung, kein Fehler. */
    $mqttEin = !empty($cfg['mqtt_ein']);
    $zeilen[] = sp_pruefzeile($mqttEin ? 1 : -1, sp_t('TEST.F_MQTT_EIN'),
        sp_t($mqttEin ? 'TEST.A_MQTT_EIN_JA' : 'TEST.A_MQTT_EIN_NEIN'));

    $m = sp_mqtt_zustand();
    if (!$m['gefunden']) {
        $zeilen[] = sp_pruefzeile(0, sp_t('TEST.F_MQTT'), sp_t('TEST.A_MQTT_NICHT_GEFUNDEN'));
    } elseif ($m['autostart']) {
        $zeilen[] = sp_pruefzeile(1, sp_t('TEST.F_MQTT'),
            sp_e($m['broker']) . ':' . sp_e($m['brokerport']) . ' (UDP ' . (int) $m['udpport'] . ', '
            . sprintf(sp_t('TEST.A_MQTT_FASSUNG'), $m['fassung'] ?: sp_t('TEST.A_MQTT_UNBEKANNT')) . ')');
    } else {
        $zeilen[] = sp_pruefzeile(0, sp_t('TEST.F_MQTT'), sp_t('TEST.A_MQTT_AUS'));
    }

    /* Gruppe 11 (0.12.7, Entwurf 3.5): Befehlsbruecke (laeuft sie, status/befehle, letzte Quittung), ihre
     * Einrichtung (--pruefen: mosquitto-clients, Zuordnung), Praefix in ihrer Grenze, Namensfilter der gemeinsamen
     * Warteschlange, Lebenszeichen, Namenstabelle, Abo-Datei und Vorlage MQTT-Befehle. Alle lesen nur. */
    foreach (array('BEFEHL.F_BRUECKE' => sp_pruef_bruecke($cfg),
                   'BEFEHL.F_EINRICHTUNG' => sp_pruef_bruecke_einrichtung($cfg),
                   'BEFEHL.F_PRAEFIX' => sp_pruef_praefix($cfg),
                   'BEFEHL.F_NAMENSFILTER' => sp_pruef_namensfilter(),
                   'BEFEHL.F_HERZ' => sp_pruef_herzschlag($cfg),
                   'BEFEHL.F_NAMEN' => sp_pruef_namen(),
                   'BEFEHL.F_ABO' => sp_pruef_abo($cfg),
                   'BEFEHL.F_VORLAGE' => sp_pruef_vorlage_mqtt()) as $sp_k => $sp_r) {
        $zeilen[] = sp_pruefzeile($sp_r[0], sp_t($sp_k), $sp_r[1]);
    }

    // Ruhezeit und Bremse - beides wirkt auf JEDE Ansage.
    list($ruhe, $rgrund) = sp_ruhe_aktiv($cfg);
    if (empty($cfg['ruhe']['ein'])) {
        $zeilen[] = sp_pruefzeile(-1, sp_t('TEST.F_RUHE'), sp_t('TEST.A_RUHE_AUS'));
    } else {
        // Der Schluessel steht NICHT in einem Ternaer innerhalb von sp_t():
        // sprachplatzhalter_pruefen.py liest die Aufrufstelle woertlich und
        // meldete beide Schluessel sonst als 'nirgends durch sprintf gereicht'.
        $antwort = $ruhe
            ? sprintf(sp_t('TEST.A_RUHE_AKTIV'),
                      sp_e($cfg['ruhe']['von']), sp_e($cfg['ruhe']['bis']))
            : sprintf(sp_t('TEST.A_RUHE_EIN'),
                      sp_e($cfg['ruhe']['von']), sp_e($cfg['ruhe']['bis']));
        $zeilen[] = sp_pruefzeile($ruhe ? -1 : 1, sp_t('TEST.F_RUHE'), $antwort);
    }

    // Zusaetzliche Ansage (Ansage-1): ist das Ziel da? Faellt es aus,
    // bleibt der bisherige Weg - die Zeile sagt es.
    // Gruppe 20 (0.12.8): die Selbstpruefung laeuft nur bei offenem Reiter Test - SonoX darf dann /zones fragen.
    $sp_al = sp_ansage_lage($cfg, true);
    if ($sp_al !== null) {
        $zeilen[] = sp_pruefzeile($sp_al[0], sp_t('TEST.F_ANSAGE'), $sp_al[1]);
    }

    // A10: Dauerverbindung, letzte Ansage, laufende Timer - aus dem Abbild.
    foreach (sp_test_dienstzeilen($cfg, sp_loxone()) as $z) { $zeilen[] = $z; }

    // Vorgaben, Zweitschrift, Suchmuster, Vorlage, Oberflaeche
    list($st, $tx) = sp_vorgaben_probe();
    $zeilen[] = sp_pruefzeile($st, sp_t('TEST.F_VORGABEN'), $tx);
    list($st, $tx) = sp_zweitschrift_probe();
    $zeilen[] = sp_pruefzeile($st, sp_t('TEST.F_ZWEITSCHRIFT'), $tx);
    list($st, $tx) = sp_suchmuster_probe();
    $zeilen[] = sp_pruefzeile($st, sp_t('TEST.F_MUSTER'), $tx);

    $sp_vg = 0; $sp_vges = 0;
    $befunde = sp_vorlage_pruefen($sp_vg, $sp_vges);
    $zeilen[] = sp_pruefzeile($befunde ? 0 : 1, sp_t('TEST.F_VORLAGE'),
        $befunde ? sp_e(implode('; ', $befunde))
                 : sprintf(sp_t('TEST.A_VORLAGE_OK'), $sp_vg, $sp_vges));

    list($st, $tx) = sp_smactive_probe();
    $zeilen[] = sp_pruefzeile($st, sp_t('TEST.F_SMACTIVE'), $tx);
    list($st, $tx) = sp_formularprobe(__DIR__ . '/index.php');
    $zeilen[] = sp_pruefzeile($st, sp_t('TEST.F_FORMULAR'), $tx);

    return $zeilen;
}

/**
 * Text einebnen wie einebnen() in bin/verstehen.py: NFC zuerst (ein "ü" kann
 * als u plus Trema kommen), Umlaute und ß ausschreiben, klein, Beizeichen
 * weg, alles ausser Buchstaben und Ziffern wird Leerraum.
 */
function sp_test_einebnen($t)
{
    $t = trim((string) $t);
    if (class_exists('Normalizer', false)) {
        $n = Normalizer::normalize($t, Normalizer::FORM_C);
        if (is_string($n)) { $t = $n; }
    }
    $t = function_exists('mb_strtolower') ? mb_strtolower($t, 'UTF-8') : strtolower($t);
    $t = strtr($t, array("\xC3\xA4" => 'ae', "\xC3\xB6" => 'oe', "\xC3\xBC" => 'ue', "\xC3\x9F" => 'ss',
                         "\xC3\x84" => 'ae', "\xC3\x96" => 'oe', "\xC3\x9C" => 'ue', "\xE1\xBA\x9E" => 'ss'));
    if (class_exists('Normalizer', false)) {
        $n = Normalizer::normalize($t, Normalizer::FORM_KD);
        if (is_string($n)) { $t = (string) preg_replace('/\p{Mn}+/u', '', $n); }
    }
    $t = strtolower($t);
    return trim((string) preg_replace('/\s+/', ' ', (string) preg_replace('/[^a-z0-9]+/', ' ', $t)));
}

/**
 * Steht dieser Raum als Ziel in der Satzdatei?
 *
 * A6 (Runde 2): verglichen wird nach Wortgrenzen wie im Dienst. Bis Runde 1
 * genuegte ein Teilstueck (strpos): der Raum "Motorraum" galt als bekannt,
 * weil es ein Ziel "tor" gab - im Dienst traf "tor" in "motorraum" nie.
 * Ein Name gilt, wenn er als ganze Wortfolge im eingeebneten Raum steht.
 */
function sp_raum_bekannt($raum, $ziele)
{
    $gesucht = sp_test_einebnen($raum);
    if ($gesucht === '') { return false; }
    $mit_grenzen = ' ' . $gesucht . ' ';
    foreach ((array) $ziele as $k => $z) {
        $namen = array(sp_test_einebnen(str_replace('_', ' ', (string) $k)));
        if (is_array($z)) {
            $namen[] = sp_test_einebnen(isset($z['name']) && is_scalar($z['name']) ? $z['name'] : '');
            foreach ((array) (isset($z['alias']) ? $z['alias'] : array()) as $a) {
                if (is_scalar($a)) { $namen[] = sp_test_einebnen($a); }
            }
        }
        foreach ($namen as $n) {
            if ($n !== '' && strpos($mit_grenzen, ' ' . $n . ' ') !== false) {
                return true;
            }
        }
    }
    return false;
}

/**
 * A10 (Runde 2): Dauerverbindung zum Broker (S3) und letzte Ansage - aus dem
 * Abbild des Dienstes (data/.../loxone.json). Welche Felder ein Dienst dort
 * schreibt, haengt an seiner Fassung; gelesen wird mit Rueckfall, und was
 * fehlt, steht grau als "meldet der Dienst nicht".
 * Rueckgabe: Liste von Pruefzeilen.
 */
function sp_test_dienstzeilen(array $cfg, array $abbild)
{
    $zeilen = array();
    $wert = function (array $d, array $namen) {
        foreach ($namen as $n) { if (array_key_exists($n, $d)) { return $d[$n]; } }
        return null;
    };
    // Dauerverbindung: ein Block mqtt_dauer {verbunden, seit, grund} oder einzelne Felder.
    if (empty($cfg['mqtt_dauer_ein'])) {
        $zeilen[] = sp_pruefzeile(-1, sp_t('UI013.F_MQTT_DAUER'), sp_e(sp_t('UI013.A_MQTT_DAUER_AUS')));
    } else {
        $blk = isset($abbild['mqtt_dauer']) && is_array($abbild['mqtt_dauer']) ? $abbild['mqtt_dauer'] : array();
        $verb = $wert($blk, array('verbunden', 'ok'));
        if ($verb === null) { $verb = $wert($abbild, array('mqtt_dauer_verbunden', 'mqtt_verbunden')); }
        $grund = $wert($blk, array('grund', 'fehler'));
        if ($grund === null) { $grund = $wert($abbild, array('mqtt_dauer_grund')); }
        $seit = $wert($blk, array('seit'));
        if ($verb === null || !is_scalar($verb)) {
            $zeilen[] = sp_pruefzeile(-1, sp_t('UI013.F_MQTT_DAUER'), sp_e(sp_t('UI013.A_MQTT_DAUER_UNBEKANNT')));
        } elseif (!empty($verb)) {
            $zeilen[] = sp_pruefzeile(1, sp_t('UI013.F_MQTT_DAUER'),
                sp_e(is_numeric($seit) && (int) $seit > 0
                     ? sprintf(sp_t('UI013.A_MQTT_DAUER_SEIT'), date('d.m.Y H:i', (int) $seit))
                     : sp_t('UI013.A_MQTT_DAUER_JA')));
        } else {
            $zeilen[] = sp_pruefzeile(0, sp_t('UI013.F_MQTT_DAUER'),
                sp_e(sprintf(sp_t('UI013.A_MQTT_DAUER_NEIN'), is_scalar($grund) && (string) $grund !== '' ? (string) $grund : '-')));
        }
    }
    /* Gruppe 20 (0.12.8, V3): Kalenderfragen aus dem Abbild-Block kalender {lage, datum, zaehler_ts, grund,
     * heute_frei, morgen_frei, ferien_in, feiertag_in}. Aus ist grau (Regeln/04), nicht rot; kein Knopf - ein
     * Satz laesst sich mit dem Satzfeld unten durchschicken. Das Alter rechnet diese Seite aus zaehler_ts (N2). */
    if (empty($cfg['kalender_fragen_ein'])) {
        $zeilen[] = sp_pruefzeile(-1, sp_t('UI020.F_KALENDER'), sp_e(sp_t('UI020.A_KALENDER_AUS')));
    } else {
        $sp_k = isset($abbild['kalender']) && is_array($abbild['kalender']) ? $abbild['kalender'] : array();
        $sp_kl = isset($sp_k['lage']) && is_string($sp_k['lage']) ? $sp_k['lage'] : '';
        $sp_kz = isset($sp_k['zaehler_ts']) && is_numeric($sp_k['zaehler_ts']) ? (int) $sp_k['zaehler_ts'] : 0;
        $sp_ka = $sp_kz > 0 ? max(0, time() - $sp_kz) : -1;
        $sp_kd = isset($sp_k['datum']) && is_scalar($sp_k['datum']) ? (string) $sp_k['datum'] : '';
        if (preg_match('/^(\d{4})(\d{2})(\d{2})\z/', $sp_kd, $sp_m)) { $sp_kd = $sp_m[3] . '.' . $sp_m[2] . '.' . $sp_m[1]; }
        if ($sp_kd === '') { $sp_kd = '-'; }
        $sp_jn = function ($k) use ($sp_k) {
            $w = isset($sp_k[$k]) ? $sp_k[$k] : null;
            return $w === 1 ? sp_t('ALLG.JA') : ($w === 0 ? sp_t('ALLG.NEIN') : '-');
        };
        $sp_tg = function ($k) use ($sp_k) {
            $w = isset($sp_k[$k]) ? $sp_k[$k] : null;
            return (is_int($w) && $w >= 0) ? (string) $w : '-';
        };
        $sp_kh = ' ' . sp_t('UI020.A_KALENDER_HILFE');
        if ($sp_kl === 'frisch') {
            $zeilen[] = sp_pruefzeile(1, sp_t('UI020.F_KALENDER'), sp_e(sprintf(sp_t('UI020.A_KALENDER_FRISCH'),
                $sp_kd, $sp_ka >= 0 ? $sp_ka : 0, $sp_jn('heute_frei'), $sp_jn('morgen_frei'), $sp_tg('ferien_in'),
                $sp_tg('feiertag_in'))));
        } elseif ($sp_kl === 'wartet') {
            $zeilen[] = sp_pruefzeile(-1, sp_t('UI020.F_KALENDER'), sp_e(sp_t('UI020.A_KALENDER_WARTET')));
        } elseif ($sp_kl === 'schweigt') {
            $zeilen[] = sp_pruefzeile(0, sp_t('UI020.F_KALENDER'), sp_e(($sp_ka >= 0
                ? sprintf(sp_t('UI020.A_KALENDER_SCHWEIGT'), $sp_ka) : sp_t('UI020.A_KALENDER_SCHWEIGT_NIE')) . $sp_kh));
        } elseif ($sp_kl === 'veraltet') {
            $zeilen[] = sp_pruefzeile(0, sp_t('UI020.F_KALENDER'),
                sp_e(sprintf(sp_t('UI020.A_KALENDER_VERALTET'), $sp_kd) . $sp_kh));
        } elseif ($sp_kl === 'getrennt') {
            $sp_kg = isset($sp_k['grund']) && is_scalar($sp_k['grund']) && (string) $sp_k['grund'] !== '' ? (string) $sp_k['grund'] : '-';
            $zeilen[] = sp_pruefzeile(0, sp_t('UI020.F_KALENDER'),
                sp_e(sprintf(sp_t('UI020.A_KALENDER_GETRENNT'), $sp_kg) . $sp_kh));
        } else {
            $zeilen[] = sp_pruefzeile(-1, sp_t('UI020.F_KALENDER'), sp_e(sp_t('UI020.A_KALENDER_UNBEKANNT')));
        }
    }
    // Letzte Ansage: Block letzte_ansage {ok, grund, ts} oder ansage_ok/ansage_grund/ansage_ts.
    $la = isset($abbild['letzte_ansage']) && is_array($abbild['letzte_ansage']) ? $abbild['letzte_ansage'] : array();
    $ok = $wert($la, array('ok'));
    $grund = $wert($la, array('grund', 'meldung'));
    $ts = $wert($la, array('ts', 'zeit'));
    if ($ok === null) {
        $ok = $wert($abbild, array('ansage_ok'));
        $grund = $wert($abbild, array('ansage_grund'));
        $ts = $wert($abbild, array('ansage_ts'));
    }
    if ($ok === null || !is_scalar($ok)) {
        $zeilen[] = sp_pruefzeile(-1, sp_t('UI013.F_LETZTE_ANSAGE'), sp_e(sp_t('UI013.A_LETZTE_ANSAGE_UNBEKANNT')));
    } else {
        $wann = is_numeric($ts) && (int) $ts > 0 ? date('d.m.Y H:i:s', (int) $ts) : '-';
        // ok -1: gesendet, aber ohne Beleg (Sonos4Lox nach Zeitablauf,
        // Chromecast4lox bei schon sprechendem Lautsprecher) - kein Ausfall.
        $zeilen[] = ((int) $ok === -1)
            ? sp_pruefzeile(-1, sp_t('UI013.F_LETZTE_ANSAGE'), sp_e(sprintf(sp_t('UI013.A_LETZTE_ANSAGE_UNKLAR'), $wann)))
            : (!empty($ok)
            ? sp_pruefzeile(1, sp_t('UI013.F_LETZTE_ANSAGE'), sp_e(sprintf(sp_t('UI013.A_LETZTE_ANSAGE_OK'), $wann)))
            : sp_pruefzeile(0, sp_t('UI013.F_LETZTE_ANSAGE'),
                sp_e(sprintf(sp_t('UI013.A_LETZTE_ANSAGE_FEHL'), $wann, is_scalar($grund) && (string) $grund !== '' ? (string) $grund : '-'))));
    }
    // Laufende Timer (das Abbild fuehrt sie seit 0.11: timer = Liste {faellig, ziel, zielname, aktion}).
    $timer = isset($abbild['timer']) && is_array($abbild['timer']) ? $abbild['timer'] : array();
    $teile = array();
    foreach (array_slice($timer, 0, 6) as $t) {
        if (!is_array($t)) { continue; }
        $n = isset($t['zielname']) && is_scalar($t['zielname']) && (string) $t['zielname'] !== '' ? (string) $t['zielname']
           : (isset($t['ziel']) && is_scalar($t['ziel']) ? (string) $t['ziel'] : '?');
        $teile[] = date('H:i', (int) (isset($t['faellig']) ? $t['faellig'] : 0)) . ' ' . $n
                 . (isset($t['aktion']) && is_scalar($t['aktion']) ? ' ' . (string) $t['aktion'] : '');
    }
    $zeilen[] = sp_pruefzeile(-1, sp_t('UI013.F_TIMER'),
        $timer ? sp_e(sprintf(sp_t('UI013.A_TIMER'), count($timer), implode('; ', $teile))) : sp_e(sp_t('UI013.A_TIMER_KEINE')));
    return $zeilen;
}

/**
 * Rueckgabe: array(stand, Meldung als HTML).
 *
 * F2 (0.12.0): die Meldung ist MASKIERT. Bis 0.11.15 reichte diese Funktion
 * die Meldung des Dienstes roh weiter, und die Oberflaeche gab sie roh aus -
 * darin stehen aber Texte, die nicht von hier stammen: Zielnamen aus einer
 * eingespielten Sicherung oder dem Loxone-Import, Antwortzeilen fremder
 * Geraete, der Text eines Sprachmodells. Ein Zielname wie
 * <img src=x onerror=...> lief damit im angemeldeten Browser. Jetzt geht
 * alles Fremde durch sp_e(); HTML entsteht nur hier, aus festen Teilen.
 */
function sp_test_aktion($aktion)
{
    // F14: eine Liste (test_satz[]=) ist kein Text - Leerwert statt Warnung.
    $feld_text = function ($feld) {
        return isset($_POST[$feld]) && is_string($_POST[$feld]) ? $_POST[$feld] : '';
    };
    $reinigen = function ($feld) use ($feld_text) {
        return trim((string) preg_replace('/[\x00-\x1F\x7F]/u', ' ', $feld_text($feld)));
    };
    $raum = trim((string) preg_replace('/[\x00-\x1F\x7F"\']/u', '', $feld_text('test_raum')));
    // Die Antwort des Dienstes: Stand bleibt, die Meldung wird maskiert.
    $maskiert = function ($erg) {
        return array(isset($erg[0]) ? $erg[0] : 0, sp_e(isset($erg[1]) ? (string) $erg[1] : ''));
    };

    switch ($aktion) {
        case 'satz':
            $text = $reinigen('test_satz');
            if ($text === '') { return array(0, sp_t('TEST.M_SATZ_LEER')); }
            /* Ohne eigene Wartezeit: sp_befehl_absetzen() deckelt auf
             * SP_WARTEN_WEB. Hier standen 30 bzw. 60 Sekunden - Zahlen, die
             * nie zur Wirkung kamen. Sie vorzutaeuschen ist schlimmer, als sie
             * wegzulassen: wer sie liest, glaubt, der Reiter warte eine
             * Minute. */
            return $maskiert(sp_befehl_absetzen(array('aktion' => 'satz', 'satz' => $text,
                                                      'raum' => $raum), null, 'web'));

        case 'trocken':
            /* Der Trockenlauf braucht KEINEN laufenden Dienst - gerade dann
             * will man wissen, welche Regel greifen wuerde. Er ruft dieselbe
             * Kette auf und sendet nichts. */
            $text = $reinigen('test_satz');
            if ($text === '') { return array(0, sp_t('TEST.M_SATZ_LEER')); }
            list($ok, $antwort, $d) = sp_trockenlauf($text, $raum);
            if (!$d) { return array(0, sp_e($antwort)); }
            $teile = array();
            foreach (array('absicht', 'aktion', 'ziel', 'zielname', 'wert', 'einheit',
                           'dauer_s', 'quelle', 'grund') as $k) {
                if (isset($d[$k]) && is_scalar($d[$k]) && $d[$k] !== '') {
                    $teile[] = sp_e($k) . '=<span class="sm-mono">' . sp_e($d[$k]) . '</span>';
                }
            }
            $themen = isset($d['themen']) && is_array($d['themen']) ? array_filter($d['themen'], 'is_scalar') : array();
            return array($ok ? 1 : 0,
                sprintf(sp_t('TEST.M_TROCKEN'), implode(', ', $teile),
                        sp_e($antwort), count($themen))
                . ($themen ? '<br><span class="sm-mono">' . sp_e(implode(', ', $themen)) . '</span>' : ''));

        case 'sprechen':
            $text = $reinigen('test_ansage');
            if ($text === '') { return array(0, sp_t('TEST.M_ANSAGE_LEER')); }
            $befehl = array('aktion' => 'sprechen', 'text' => $text);
            $zone = trim((string) preg_replace('/[^0-9~,]/', '', $feld_text('test_zone')));
            if ($zone !== '') { $befehl['zone'] = $zone; }
            return $maskiert(sp_befehl_absetzen($befehl, null, 'web'));

        case 'neu_laden':
            return $maskiert(sp_befehl_absetzen(array('aktion' => 'neu_laden'), null, 'web'));

        case 'dienste':
            // Die Funktion liefert an vier von sechs Stellen nur zwei
            // Elemente. Ein list() mit drei Zielen erzeugt unter PHP 8
            // eine Warnung auf der Seite - der dritte Wert wird deshalb
            // einzeln geholt.
            $sp_erg = sp_befehl_absetzen(array('aktion' => 'dienste'), null, 'web');
            $ok = $sp_erg[0];
            $a = isset($sp_erg[2]) && is_array($sp_erg[2]) ? $sp_erg[2] : array();
            if (!$ok || empty($a['dienste']) || !is_array($a['dienste'])) { return $maskiert($sp_erg); }
            $zeilen = array();
            foreach ($a['dienste'] as $name => $d) {
                $d = is_array($d) ? $d : array();
                if (!empty($d['ok'])) {
                    $modelle = isset($d['modelle']) && is_array($d['modelle']) ? array_filter($d['modelle'], 'is_scalar') : array();
                    $zeilen[] = '<b>' . sp_e($name) . '</b>: '
                              . ($modelle
                                 ? '<span class="sm-mono">' . sp_e(implode(', ', $modelle)) . '</span>'
                                 : sp_t('TEST.A_KEINE_MODELLE'));
                } else {
                    $zeilen[] = '<b>' . sp_e($name) . '</b>: '
                              . sp_e(isset($d['fehler']) && is_scalar($d['fehler']) ? $d['fehler'] : '');
                }
            }
            return array(1, implode('<br>', $zeilen));

        default:
            return array(0, sp_t('TEST.M_UNBEKANNT'));
    }
}
