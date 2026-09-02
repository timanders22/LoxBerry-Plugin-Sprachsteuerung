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
    return array(0, $errstr !== '' ? $errstr : ('Fehler ' . $errno));
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
    foreach (array('whisper' => 'TEST.F_WHISPER', 'piper' => 'TEST.F_PIPER',
                   'wake' => 'TEST.F_WAKE') as $dienst => $schluessel) {
        list($host, $port) = sp_dienst_ziel($dienst === 'wake' ? 'wakeword' : $dienst, $cfg);
        list($ok, $grund) = sp_erreichbar($host, $port);
        $zeilen[] = sp_pruefzeile($ok, sp_t($schluessel),
            $ok ? sp_e($host . ':' . $port)
                : sprintf(sp_t('TEST.A_DIENST_STUMM'), sp_e($host . ':' . $port), sp_e($grund)));
    }
    if (!empty($cfg['llm_ein'])) {
        list($llm_h, $llm_p) = sp_dienst_ziel('llm', $cfg);
        list($ok, $grund) = sp_erreichbar($llm_h, $llm_p);
        $zeilen[] = sp_pruefzeile($ok, sp_t('TEST.F_LLM'),
            $ok ? sp_e($llm_h . ':' . $llm_p)
                : sprintf(sp_t('TEST.A_DIENST_STUMM'),
                          sp_e($llm_h . ':' . $llm_p), sp_e($grund)));
    } else {
        $zeilen[] = sp_pruefzeile(-1, sp_t('TEST.F_LLM'), sp_t('TEST.A_LLM_AUS'));
    }

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

/** Steht dieser Raum als Ziel in der Satzdatei? */
function sp_raum_bekannt($raum, $ziele)
{
    $ebnen = function ($t) {
        $t = strtolower(trim((string) $t));
        $t = strtr($t, array('ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue', 'ß' => 'ss'));
        return trim(preg_replace('/\s+/', ' ', preg_replace('/[^a-z0-9 ]+/', ' ', $t)));
    };
    $gesucht = $ebnen($raum);
    if ($gesucht === '') { return false; }
    foreach ($ziele as $k => $z) {
        $namen = array($ebnen($k));
        if (is_array($z)) {
            $namen[] = $ebnen(isset($z['name']) ? $z['name'] : '');
            foreach ((array) (isset($z['alias']) ? $z['alias'] : array()) as $a) {
                $namen[] = $ebnen($a);
            }
        }
        foreach ($namen as $n) {
            if ($n !== '' && ($n === $gesucht || strpos($gesucht, $n) !== false)) {
                return true;
            }
        }
    }
    return false;
}

/** Rueckgabe: array(stand, Meldung) */
function sp_test_aktion($aktion)
{
    $reinigen = function ($feld) {
        $t = isset($_POST[$feld]) ? (string) $_POST[$feld] : '';
        return trim(preg_replace('/[\x00-\x1F\x7F]/u', ' ', $t));
    };
    $raum = isset($_POST['test_raum'])
        ? trim(preg_replace('/[\x00-\x1F\x7F"\']/u', '', (string) $_POST['test_raum'])) : '';

    switch ($aktion) {
        case 'satz':
            $text = $reinigen('test_satz');
            if ($text === '') { return array(0, sp_t('TEST.M_SATZ_LEER')); }
            /* Ohne eigene Wartezeit: sp_befehl_absetzen() deckelt auf
             * SP_WARTEN_WEB. Hier standen 30 bzw. 60 Sekunden - Zahlen, die
             * nie zur Wirkung kamen. Sie vorzutaeuschen ist schlimmer, als sie
             * wegzulassen: wer sie liest, glaubt, der Reiter warte eine
             * Minute. */
            return sp_befehl_absetzen(array('aktion' => 'satz', 'satz' => $text,
                                            'raum' => $raum));

        case 'trocken':
            /* Der Trockenlauf braucht KEINEN laufenden Dienst - gerade dann
             * will man wissen, welche Regel greifen wuerde. Er ruft dieselbe
             * Kette auf und sendet nichts. */
            $text = $reinigen('test_satz');
            if ($text === '') { return array(0, sp_t('TEST.M_SATZ_LEER')); }
            list($ok, $antwort, $d) = sp_trockenlauf($text, $raum);
            if (!$d) { return array(0, $antwort); }
            $teile = array();
            foreach (array('absicht', 'aktion', 'ziel', 'zielname', 'wert', 'einheit',
                           'dauer_s', 'quelle', 'grund') as $k) {
                if (isset($d[$k]) && $d[$k] !== '' && $d[$k] !== null) {
                    $teile[] = sp_e($k) . '=<span class="sm-mono">' . sp_e($d[$k]) . '</span>';
                }
            }
            $themen = isset($d['themen']) && is_array($d['themen']) ? $d['themen'] : array();
            return array($ok ? 1 : 0,
                sprintf(sp_t('TEST.M_TROCKEN'), implode(', ', $teile),
                        sp_e($antwort), count($themen))
                . ($themen ? '<br><span class="sm-mono">' . sp_e(implode(', ', $themen)) . '</span>' : ''));

        case 'sprechen':
            $text = $reinigen('test_ansage');
            if ($text === '') { return array(0, sp_t('TEST.M_ANSAGE_LEER')); }
            $befehl = array('aktion' => 'sprechen', 'text' => $text);
            $zone = isset($_POST['test_zone'])
                ? trim(preg_replace('/[^0-9~,]/', '', (string) $_POST['test_zone'])) : '';
            if ($zone !== '') { $befehl['zone'] = $zone; }
            return sp_befehl_absetzen($befehl);

        case 'neu_laden':
            return sp_befehl_absetzen(array('aktion' => 'neu_laden'));

        case 'dienste':
            // Die Funktion liefert an vier von sechs Stellen nur zwei
            // Elemente. Ein list() mit drei Zielen erzeugt unter PHP 8
            // eine Warnung auf der Seite - der dritte Wert wird deshalb
            // einzeln geholt.
            $sp_erg = sp_befehl_absetzen(array('aktion' => 'dienste'));
            $ok = $sp_erg[0];
            $meldung = $sp_erg[1];
            $a = isset($sp_erg[2]) && is_array($sp_erg[2]) ? $sp_erg[2] : array();
            if (!$ok || empty($a['dienste'])) { return array($ok, $meldung); }
            $zeilen = array();
            foreach ($a['dienste'] as $name => $d) {
                if (!empty($d['ok'])) {
                    $zeilen[] = '<b>' . sp_e($name) . '</b>: '
                              . ($d['modelle']
                                 ? '<span class="sm-mono">' . sp_e(implode(', ', $d['modelle'])) . '</span>'
                                 : sp_t('TEST.A_KEINE_MODELLE'));
                } else {
                    $zeilen[] = '<b>' . sp_e($name) . '</b>: ' . sp_e($d['fehler']);
                }
            }
            return array(1, implode('<br>', $zeilen));

        default:
            return array(0, sp_t('TEST.M_UNBEKANNT'));
    }
}
