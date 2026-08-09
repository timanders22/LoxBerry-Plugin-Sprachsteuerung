<?php
/**
 * Sprachsteuerung lokal - Selbstpruefung und die Aktionen des Reiters Test
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
    $zeilen = array();

    $pid = sp_dienst_pid();
    $zeilen[] = sp_pruefzeile($pid > 0 ? 1 : 0, sp_t('TEST.F_DIENST'),
        $pid > 0 ? sp_t('TEST.A_DIENST_LAEUFT') . ' ' . $pid
                 : (sp_dienst_soll() ? sp_t('TEST.A_DIENST_SOLL_TOT') : sp_t('TEST.A_DIENST_GESTOPPT')));

    foreach (array('whisper' => 'TEST.F_WHISPER', 'piper' => 'TEST.F_PIPER',
                   'wake' => 'TEST.F_WAKE') as $dienst => $schluessel) {
        $host = $cfg[$dienst . '_host'];
        $port = (int) $cfg[$dienst . '_port'];
        list($ok, $grund) = sp_erreichbar($host, $port);
        $zeilen[] = sp_pruefzeile($ok, sp_t($schluessel),
            $ok ? sp_e($host . ':' . $port)
                : sprintf(sp_t('TEST.A_DIENST_STUMM'), sp_e($host . ':' . $port), sp_e($grund)));
    }
    if (!empty($cfg['llm_ein'])) {
        list($ok, $grund) = sp_erreichbar($cfg['llm_host'], (int) $cfg['llm_port']);
        $zeilen[] = sp_pruefzeile($ok, sp_t('TEST.F_LLM'),
            $ok ? sp_e($cfg['llm_host'] . ':' . $cfg['llm_port'])
                : sprintf(sp_t('TEST.A_DIENST_STUMM'),
                          sp_e($cfg['llm_host'] . ':' . $cfg['llm_port']), sp_e($grund)));
    } else {
        $zeilen[] = sp_pruefzeile(-1, sp_t('TEST.F_LLM'), sp_t('TEST.A_LLM_AUS'));
    }

    $sats = isset($cfg['satelliten']) && is_array($cfg['satelliten']) ? $cfg['satelliten'] : array();
    if (!$sats) {
        $zeilen[] = sp_pruefzeile(-1, sp_t('TEST.F_MIKROFONE'), sp_t('TEST.A_KEINE_MIKROFONE'));
    } else {
        foreach ($sats as $s) {
            $art = isset($s['art']) && $s['art'] === 'esphome' ? 'esphome' : 'wyoming';
            $host = (string) (isset($s['host']) ? $s['host'] : '');
            $port = (int) (isset($s['port']) && $s['port'] ? $s['port'] : ($art === 'esphome' ? 6053 : 10700));
            list($ok, $grund) = sp_erreichbar($host, $port);
            $zeilen[] = sp_pruefzeile($ok,
                sp_e((string) (isset($s['name']) ? $s['name'] : $host))
                . ' <span class="sm-mono">' . sp_e($art) . '</span>',
                $ok ? sp_e($host . ':' . $port)
                    : sprintf(sp_t('TEST.A_MIKRO_STUMM'), sp_e($host . ':' . $port), sp_e($grund)));
        }
    }

    // Die Satzdatei prueft der Dienst; hier nur die groben Zahlen.
    $saetze = sp_saetze();
    $regeln = isset($saetze['regeln']) ? count((array) $saetze['regeln']) : 0;
    $ziele = isset($saetze['ziele']) ? count((array) $saetze['ziele']) : 0;
    $zeilen[] = sp_pruefzeile($regeln > 0 && $ziele > 0 ? 1 : 0, sp_t('TEST.F_SAETZE'),
        $regeln > 0 && $ziele > 0 ? sprintf(sp_t('TEST.A_SAETZE'), $regeln, $ziele)
                                  : sp_t('TEST.A_KEINE_SAETZE'));

    $m = sp_mqtt_zustand();
    if (!$m['gefunden']) {
        $zeilen[] = sp_pruefzeile(0, sp_t('TEST.F_MQTT'), sp_t('TEST.A_MQTT_NICHT_GEFUNDEN'));
    } elseif ($m['autostart']) {
        $zeilen[] = sp_pruefzeile(1, sp_t('TEST.F_MQTT'),
            sp_e($m['broker']) . ':' . sp_e($m['brokerport']) . ' (UDP ' . (int) $m['udpport'] . ')');
    } else {
        $zeilen[] = sp_pruefzeile(0, sp_t('TEST.F_MQTT'), sp_t('TEST.A_MQTT_AUS'));
    }
    return $zeilen;
}

/** Rueckgabe: array(stand, Meldung) */
function sp_test_aktion($aktion)
{
    switch ($aktion) {
        case 'satz':
            $text = isset($_POST['test_satz']) ? (string) $_POST['test_satz'] : '';
            $text = trim(preg_replace('/[\x00-\x1F\x7F]/u', ' ', $text));
            if ($text === '') { return array(0, sp_t('TEST.M_SATZ_LEER')); }
            /* Ohne eigene Wartezeit: sp_befehl_absetzen() deckelt auf
             * SP_WARTEN_WEB. Hier standen 30 bzw. 60 Sekunden - Zahlen, die
             * nie zur Wirkung kamen, weil die Funktion schon auf 20 stutzte.
             * Sie vorzutaeuschen ist schlimmer, als sie wegzulassen: wer sie
             * liest, glaubt, der Reiter warte eine Minute. */
            return sp_befehl_absetzen(array('aktion' => 'satz', 'satz' => $text));

        case 'sprechen':
            $text = isset($_POST['test_ansage']) ? (string) $_POST['test_ansage'] : '';
            $text = trim(preg_replace('/[\x00-\x1F\x7F]/u', ' ', $text));
            if ($text === '') { return array(0, sp_t('TEST.M_ANSAGE_LEER')); }
            return sp_befehl_absetzen(array('aktion' => 'sprechen', 'text' => $text));

        default:
            return array(0, sp_t('TEST.M_UNBEKANNT'));
    }
}
