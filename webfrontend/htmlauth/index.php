<?php
/**
 * Sprachsteuerung lokal - Bedienoberflaeche
 *
 * Reiter: Einstellungen | MQTT | Dienste | Mikrofone | Saetze |
 *         Einbindung in Loxone | Test | Logdateien
 *
 * Drei Reiter kommen zu den fuenf des Hausstandards hinzu. Jeder hat einen
 * eigenen, weil er ein eigener Vorgang ist: 'Dienste' verwaltet vier
 * Container samt Modellauswahl, 'Mikrofone' die Geraete, 'Saetze' den Inhalt,
 * den der Anwender pflegt. In den Einstellungen wuerden alle drei untergehen.
 *
 * Diese Datei ist NUR Oberflaeche. Der Dienst haelt die Verbindungen, der
 * Miniserver spricht mit webfrontend/html/index.php.
 */

error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE);

$sp_gefunden = false;
foreach (array(
    dirname(dirname(__DIR__)) . '/html/plugins/' . basename(__DIR__) . '/sp_lib.php',
    dirname(dirname(dirname(__DIR__))) . '/html/plugins/' . basename(__DIR__) . '/sp_lib.php',
    dirname(__DIR__) . '/html/sp_lib.php',
) as $sp_kandidat) {
    if (is_file($sp_kandidat)) { require_once $sp_kandidat; $sp_gefunden = true; break; }
}
if (!$sp_gefunden) {
    echo '<p><b>Fehler:</b> sp_lib.php wurde nicht gefunden. Bitte das Plugin neu installieren.</p>';
    exit;
}
require_once __DIR__ . '/sp_test.php';

$sp_p = sp_paths();
if ($sp_p['home'] !== '' && is_file($sp_p['home'] . '/libs/phplib/loxberry_system.php')) {
    require_once $sp_p['home'] . '/libs/phplib/loxberry_system.php';
    require_once $sp_p['home'] . '/libs/phplib/loxberry_web.php';
    $sp_p = sp_paths();
}

/* EINE Quelle fuer Reihenfolge, Positivliste und Beschriftung.
 *
 * Bis 0.9.1 standen die Reiternamen an drei Stellen: in dieser Positivliste,
 * in der Reiterleiste und in den Flaechen-ids. Wer einen Reiter ergaenzt und
 * eine davon vergisst, bekommt keinen Fehler, sondern eine Seite, die nach
 * jedem Absenden auf Einstellungen zurueckspringt. */
$sp_reiter_ids = array('settings', 'mqtt', 'services', 'mics', 'sentences', 'loxone', 'test', 'log');
$sp_muster = '/^tab-(' . implode('|', $sp_reiter_ids) . ')$/';
$sp_tab = 'tab-' . $sp_reiter_ids[0];
if (isset($_POST['activetab']) && preg_match($sp_muster, (string) $_POST['activetab'])) {
    $sp_tab = (string) $_POST['activetab'];
} elseif (isset($_GET['form']) && preg_match($sp_muster, 'tab-' . (string) $_GET['form'])) {
    $sp_tab = 'tab-' . (string) $_GET['form'];
}

$sp_meldungen = array();
$sp_fehler = array();
$sp_ausgabe = '';
$sp_post = (isset($_SERVER['REQUEST_METHOD']) ? $_SERVER['REQUEST_METHOD'] : '') === 'POST';

/* ================= Der Wachposten gegen fremde Formulare =================
 *
 * htmlauth/ schuetzt gegen den unangemeldeten Aufruf, NICHT dagegen, dass der
 * Browser eines ANGEMELDETEN Bedieners ein Formular abschickt, das auf einer
 * fremden Seite steht: die HTTP-Basic-Anmeldung schickt er automatisch mit,
 * SameSite greift nicht.
 *
 * Gemessen an Docker NG 1.2.3: ein POST von einer beliebigen fremden Seite
 * mit 'token_neu=1' wuerfelte das Merkwort neu - danach bekamen saemtliche
 * virtuellen Eingaenge im Miniserver HTTP 403, und ueber 'log_leeren=1' liess
 * sich gleich die Spur wegraeumen. Dieses Plugin hat genau diese beiden
 * Knoepfe und hatte den Schutz bis 0.9.11 nicht.
 *
 * EINE Pruefung, VOR allen Handlern und VOR der Reiterwahl. Einen einzelnen
 * Handler kann man beim Erweitern vergessen, einen Wachposten am Eingang
 * nicht. */
$sp_fmt = sp_formtoken();
$sp_csrf_ok = true;
if ($sp_post) {
    $sp_mit = (isset($_POST['fmt']) && is_string($_POST['fmt'])) ? $_POST['fmt'] : '';
    if ($sp_fmt === '') {
        $sp_csrf_ok = false;
        $sp_fehler[] = sp_t('FEHLER.CSRF_KEIN_TOKEN');
    } elseif (!hash_equals($sp_fmt, $sp_mit)) {
        $sp_csrf_ok = false;
        $sp_fehler[] = sp_t('FEHLER.CSRF');
        sp_log('Ein Formular ohne gueltiges Merkmal wurde abgewiesen.');
    }
    if (!$sp_csrf_ok) {
        // $_POST leeren, damit danach KEIN Handler mehr anlaeuft, ohne dass
        // jeder einzelne davon wissen muesste. Den aktiven Reiter behalten -
        // der Anwender soll die Meldung dort sehen, wo er war.
        $sp_behalten = isset($_POST['activetab']) ? $_POST['activetab'] : null;
        $_POST = array();
        if ($sp_behalten !== null) { $_POST['activetab'] = $sp_behalten; }
        $sp_post = false;
    }
}

/*
 * Fuer Felder, die KEIN Freitext sind: Rechnernamen, Anschlussnummern,
 * Kennungen, Auswahlwerte. Dort haben Anfuehrungszeichen nichts zu suchen.
 */
$sp_sauber = function ($feld) {
    return trim(preg_replace('/[\x00-\x1F\x7F"\']/', '',
        (string) (isset($_POST[$feld]) ? $_POST[$feld] : '')));
};

/*
 * Fuer Freitext: nur Steuerzeichen weg, Anfuehrungszeichen bleiben.
 * Eine Bezeichnung wie  Kueche "oben"  soll so stehen bleiben duerfen.
 */
$sp_freitext = function ($wert) {
    return trim(preg_replace('/\s+/u', ' ',
        preg_replace('/[\x00-\x1F\x7F]/u', ' ', (string) $wert)));
};

/* ---------------- Vorlagen und Ausfuhren herunterladen ---------------- */
if ($sp_post && isset($_POST['vorlage'])) {
    $sp_was = (string) $_POST['vorlage'];
    $sp_paar = array('', '');
    if ($sp_was === 'eingang')      { $sp_paar = sp_vorlage(); }
    elseif ($sp_was === 'ausgang')  { $sp_paar = sp_vorlage_ausgang(); }
    elseif ($sp_was === 'ziele')    { $sp_paar = sp_vorlage_ziele(); }
    list($sp_name, $sp_inhalt) = $sp_paar;
    if ($sp_inhalt === '') {
        $sp_fehler[] = sp_t('LOX.KEINE_ZIELE_VORLAGE');
        $sp_tab = 'tab-loxone';
    } else {
        header('Content-Type: application/x-download');
        header('Content-Disposition: attachment; filename="' . $sp_name . '"');
        echo $sp_inhalt;
        exit;
    }
}
if ($sp_post && isset($_POST['sicherung_holen'])) {
    header('Content-Type: application/x-download');
    header('Content-Disposition: attachment; filename="sprachsteuerung_sicherung_'
           . date('Ymd_Hi') . '.json"');
    echo sp_sicherung_bauen();
    exit;
}
if ($sp_post && isset($_POST['verlauf_csv'])) {
    header('Content-Type: application/x-download');
    header('Content-Disposition: attachment; filename="sprachsteuerung_verlauf_'
           . date('Ymd_Hi') . '.csv"');
    echo sp_verlauf_csv();
    exit;
}

/* ---------------- Sicherung einspielen ---------------- */
if ($sp_post && isset($_POST['sicherung_einspielen'])) {
    if (!isset($_FILES['sicherungsdatei']) || !is_array($_FILES['sicherungsdatei'])
        || !is_uploaded_file((string) $_FILES['sicherungsdatei']['tmp_name'])) {
        $sp_fehler[] = sp_t('SICHER.FEHLER_KEINE_DATEI');
    } else {
        $sp_roh = (string) @file_get_contents($_FILES['sicherungsdatei']['tmp_name']);
        list($sp_ok, $sp_meld) = sp_sicherung_lesen($sp_roh);
        if ($sp_ok) { $sp_meldungen[] = sp_e($sp_meld); } else { $sp_fehler[] = sp_e($sp_meld); }
    }
    $sp_tab = 'tab-sentences';
}

/* ---------------- MQTT speichern (eigener Reiter) ---------------- */
if ($sp_post && isset($_POST['mqtt_save'])) {
    $sp_cfg = sp_config();
    $sp_cfg['mqtt_ein'] = isset($_POST['mqtt_ein']) ? 1 : 0;
    $sp_topic = $sp_sauber('mqtt_topic');
    if ($sp_topic === '' || !preg_match('#^[A-Za-z0-9_/\-]{1,64}$#', $sp_topic)) {
        $sp_fehler[] = sp_t('EINST.FEHLER_TOPIC');
    } else {
        $sp_cfg['mqtt_topic'] = trim($sp_topic, '/');
    }
    $sp_takt = $sp_sauber('herzschlag_s');
    if (!preg_match('/^[0-9]+$/', $sp_takt) || (int) $sp_takt < 0 || (int) $sp_takt > 3600) {
        $sp_fehler[] = sprintf(sp_t('EINST.FEHLER_BEREICH'), sp_t('MQTT.L_HERZSCHLAG'), 0, 3600);
    } else {
        $sp_cfg['herzschlag_s'] = (int) $sp_takt;
    }
    if (!$sp_fehler) {
        if (sp_config_speichern($sp_cfg)) { $sp_meldungen[] = sp_t('EINST.GESPEICHERT'); }
        else { $sp_fehler[] = sprintf(sp_t('EINST.FEHLER_SPEICHERN'), $sp_p['config']); }
    }
    $sp_tab = 'tab-mqtt';
}

/* ---------------- Einstellungen speichern ---------------- */
if ($sp_post && isset($_POST['speichern'])) {
    $sp_cfg = sp_config();
    foreach (array('whisper', 'piper', 'wake', 'llm') as $sp_d) {
        $host = $sp_sauber($sp_d . '_host');
        if ($host !== '' && !preg_match('/^[A-Za-z0-9][A-Za-z0-9\.\-:_]{0,80}$/', $host)) {
            $sp_fehler[] = sprintf(sp_t('EINST.FEHLER_HOST'), sp_t('EINST.L_' . strtoupper($sp_d)));
        } elseif ($host !== '') {
            $sp_cfg[$sp_d . '_host'] = $host;
        }
        $port = $sp_sauber($sp_d . '_port');
        if (!preg_match('/^[0-9]+$/', $port) || (int) $port < 1 || (int) $port > 65535) {
            $sp_fehler[] = sprintf(sp_t('EINST.FEHLER_PORT'), sp_t('EINST.L_' . strtoupper($sp_d)));
        } else {
            $sp_cfg[$sp_d . '_port'] = (int) $port;
        }
    }
    /* Die Grenzen stehen in templates/vorgaben.json - EINE Stelle fuer
     * Formular, Oberflaeche und Dienst. Bis 0.9.11 liess das Formular fuer
     * die Wartezeit 1 bis 120 zu, waehrend sp_befehl_absetzen() ausnahmslos
     * auf 12 stutzte: die obere Haelfte des Feldes hatte keine Wirkung. */
    $sp_gr = sp_grenzen();
    foreach (array('wartezeit', 'verlauf_zeilen', 'ansage_abstand_s', 'ansage_je_tag',
                   'kontext_s', 'bestaetigung_s') as $f) {
        if (!isset($sp_gr[$f]) || !isset($_POST[$f])) { continue; }
        $w = $sp_sauber($f);
        if (!preg_match('/^[0-9]+$/', $w)) {
            $sp_fehler[] = sprintf(sp_t('EINST.FEHLER_ZAHL'), sp_t('EINST.L_' . strtoupper($f)));
        } elseif ((int) $w < (int) $sp_gr[$f][0] || (int) $w > (int) $sp_gr[$f][1]) {
            $sp_fehler[] = sprintf(sp_t('EINST.FEHLER_BEREICH'),
                                   sp_t('EINST.L_' . strtoupper($f)),
                                   (int) $sp_gr[$f][0], (int) $sp_gr[$f][1]);
        } else {
            $sp_cfg[$f] = (int) $w;
        }
    }
    $sp_spr = $sp_sauber('sprache');
    if (!preg_match('/^[a-z]{2}$/', $sp_spr)) {
        $sp_fehler[] = sp_t('EINST.FEHLER_SPRACHE');
    } else {
        $sp_cfg['sprache'] = $sp_spr;
    }
    $sp_ww = $sp_sauber('wakeword');
    if ($sp_ww === '' && isset($_POST['wakeword_frei'])) {
        $sp_ww = $sp_sauber('wakeword_frei');
    }
    if ($sp_ww !== '' && !preg_match('/^[a-z0-9_\-]{1,40}$/', $sp_ww)) {
        $sp_fehler[] = sp_t('EINST.FEHLER_WAKEWORD');
    } elseif ($sp_ww !== '') {
        $sp_cfg['wakeword'] = $sp_ww;
    }
    /* Die Miniserver-Adresse kann Zugangsdaten enthalten - deshalb NICHT
     * filtern, nur auf die Form pruefen. Und ein LEERES Feld loescht sie
     * nicht: bis 0.9.11 uebernahm der Handler den Leerwert unbesehen, ein
     * versehentlich geleertes Feld nahm damit die Anmeldung mit. Wer sie
     * wirklich entfernen will, setzt den Haken darunter. */
    $sp_url = trim((string) (isset($_POST['miniserver_url']) ? $_POST['miniserver_url'] : ''));
    if (isset($_POST['miniserver_url_loeschen'])) {
        $sp_cfg['miniserver_url'] = '';
    } elseif ($sp_url !== '' && $sp_url !== sp_url_maskiert((string) $sp_cfg['miniserver_url'])) {
        if (!sp_url_ok($sp_url)) {
            $sp_fehler[] = sp_t('EINST.FEHLER_URL');
        } else {
            $sp_cfg['miniserver_url'] = $sp_url;
        }
    }
    $sp_cfg['llm_ein'] = isset($_POST['llm_ein']) ? 1 : 0;
    $sp_cfg['antwort_sprechen'] = isset($_POST['antwort_sprechen']) ? 1 : 0;

    /* ---- Rueckweg nach Loxone ---- */
    $sp_weg = $sp_sauber('antwortweg');
    if (!in_array($sp_weg, sp_auswahl('antwortweg'), true)) {
        $sp_fehler[] = sp_t('EINST.FEHLER_ANTWORTWEG');
    } else {
        $sp_cfg['antwortweg'] = $sp_weg;
    }
    $sp_tts = is_array(isset($sp_cfg['tts']) ? $sp_cfg['tts'] : null) ? $sp_cfg['tts'] : array();
    $sp_modus = $sp_sauber('tts_mode');
    if (!in_array($sp_modus, sp_auswahl('tts_mode'), true)) {
        $sp_fehler[] = sp_t('EINST.FEHLER_TTS_MODUS');
    } else {
        $sp_tts['mode'] = $sp_modus;
    }
    $sp_tts_ip = $sp_sauber('tts_ip');
    if ($sp_tts_ip !== '' && !preg_match('/^[A-Za-z0-9][A-Za-z0-9\.\-:_]{0,80}$/', $sp_tts_ip)) {
        $sp_fehler[] = sp_t('EINST.FEHLER_TTS_IP');
    } else {
        $sp_tts['ip'] = $sp_tts_ip;
    }
    $sp_tts_port = $sp_sauber('tts_port');
    if (!preg_match('/^[0-9]+$/', $sp_tts_port) || (int) $sp_tts_port < 1 || (int) $sp_tts_port > 65535) {
        $sp_fehler[] = sprintf(sp_t('EINST.FEHLER_PORT'), sp_t('EINST.L_TTS_PORT'));
    } else {
        $sp_tts['port'] = (int) $sp_tts_port;
    }
    $sp_tts_laut = $sp_sauber('tts_volume');
    if (!preg_match('/^[0-9]+$/', $sp_tts_laut) || (int) $sp_tts_laut < 1 || (int) $sp_tts_laut > 100) {
        $sp_fehler[] = sprintf(sp_t('EINST.FEHLER_BEREICH'), sp_t('EINST.L_TTS_VOLUME'), 1, 100);
    } else {
        $sp_tts['volume'] = (int) $sp_tts_laut;
    }
    $sp_tts_zonen = $sp_sauber('tts_zones');
    if ($sp_tts_zonen !== '' && !preg_match('/^[0-9~,\s]{1,80}$/', $sp_tts_zonen)) {
        $sp_fehler[] = sp_t('EINST.FEHLER_TTS_ZONEN');
    } else {
        $sp_tts['zones'] = $sp_tts_zonen !== '' ? $sp_tts_zonen : '1';
    }
    $sp_tts_spr = $sp_sauber('tts_lang');
    if ($sp_tts_spr !== '' && !preg_match('/^[a-z]{2,5}$/', $sp_tts_spr)) {
        $sp_fehler[] = sp_t('EINST.FEHLER_SPRACHE');
    } else {
        $sp_tts['lang'] = $sp_tts_spr !== '' ? $sp_tts_spr : 'de';
    }
    $sp_tts_stimme = $sp_sauber('tts_stimme');
    if ($sp_tts_stimme !== '' && !preg_match('/^[A-Za-z0-9_.\-]{0,60}$/', $sp_tts_stimme)) {
        $sp_fehler[] = sprintf(sp_t('DIENST.FEHLER_MODELL'), sp_t('EINST.L_TTS_STIMME'));
    } else {
        $sp_tts['stimme'] = $sp_tts_stimme;
    }
    // Die Vorlage traegt Platzhalter in geschweiften Klammern und darf
    // deshalb NICHT durch den Filter oben laufen.
    $sp_tts_vorl = trim((string) (isset($_POST['tts_template']) ? $_POST['tts_template'] : ''));
    if ($sp_tts_vorl !== '' && !sp_url_ok($sp_tts_vorl)) {
        $sp_fehler[] = sp_t('EINST.FEHLER_TTS_VORLAGE');
    } else {
        $sp_tts['template'] = $sp_tts_vorl;
    }
    if ($sp_weg !== 'satellit' && $sp_modus !== 'audioserver' && $sp_tts_ip === '') {
        $sp_fehler[] = sp_t('EINST.FEHLER_TTS_FEHLT');
    }
    $sp_cfg['tts'] = $sp_tts;

    /* ---- Ruhezeit ---- */
    $sp_ruhe = is_array(isset($sp_cfg['ruhe']) ? $sp_cfg['ruhe'] : null) ? $sp_cfg['ruhe'] : array();
    $sp_ruhe['ein'] = isset($_POST['ruhe_ein']) ? 1 : 0;
    foreach (array('von', 'bis') as $sp_f) {
        $sp_w = $sp_sauber('ruhe_' . $sp_f);
        if ($sp_w !== '' && !preg_match('/^\d{1,2}:\d{2}$/', $sp_w)) {
            $sp_fehler[] = sp_t('EINST.FEHLER_RUHEZEIT');
        } elseif ($sp_w !== '') {
            $sp_ruhe[$sp_f] = $sp_w;
        }
    }
    $sp_cfg['ruhe'] = $sp_ruhe;

    if (!$sp_fehler) {
        if (sp_config_speichern($sp_cfg)) {
            $sp_meldungen[] = sp_t('EINST.GESPEICHERT');
            // Beim Speichern vervollstaendigen: danach heisst 'fehlt' nie
            // mehr 'gilt als Vorgabe'.
            $sp_erg = sp_cfg_vervollstaendigen();
            if ($sp_erg) {
                $sp_meldungen[] = sprintf(sp_t('EINST.ERGAENZT'), count($sp_erg),
                                          sp_e(implode(', ', $sp_erg)));
            }
        } else {
            $sp_fehler[] = sprintf(sp_t('EINST.FEHLER_SPEICHERN'), $sp_p['config']);
        }
    }
    $sp_tab = 'tab-settings';
}

/* ---------------- Modelle speichern ---------------- */
if ($sp_post && isset($_POST['modelle_speichern'])) {
    $sp_cfg = sp_config();
    foreach (array('whisper_modell' => '/^[A-Za-z0-9_.\/\-]{0,80}$/',
                   'piper_stimme'   => '/^[A-Za-z0-9_.\-]{0,60}$/',
                   'llm_modell'     => '/^[A-Za-z0-9_.\/\-:]{0,120}$/') as $feld => $muster) {
        $w = $sp_sauber($feld);
        // Auswahlliste oder Freitext - der Freitext gewinnt, wenn er gefuellt ist.
        $frei = $sp_sauber($feld . '_frei');
        if ($frei !== '') { $w = $frei; }
        if ($w !== '' && !preg_match($muster, $w)) {
            $sp_fehler[] = sprintf(sp_t('DIENST.FEHLER_MODELL'), sp_t('DIENST.L_' . strtoupper($feld)));
            continue;
        }
        $sp_cfg[$feld] = $w;
    }
    if (!$sp_fehler) {
        if (sp_config_speichern($sp_cfg)) { $sp_meldungen[] = sp_t('DIENST.GESPEICHERT'); }
        else { $sp_fehler[] = sprintf(sp_t('EINST.FEHLER_SPEICHERN'), $sp_p['config']); }
    }
    $sp_tab = 'tab-services';
}

/* ---------------- Mikrofone speichern ---------------- */
if ($sp_post && isset($_POST['mikros_speichern'])) {
    $sp_cfg = sp_config();
    $sp_neu = array();
    $sp_anz = 8;
    for ($i = 0; $i < $sp_anz; $i++) {
        $hol = function ($feld) use ($i) {
            $a = isset($_POST[$feld]) ? (array) $_POST[$feld] : array();
            return isset($a[$i]) ? trim(preg_replace('/[\x00-\x1F\x7F"\']/', '', (string) $a[$i])) : '';
        };
        $host = $hol('m_host');
        // Die Bezeichnung ist Freitext - Anfuehrungszeichen bleiben stehen.
        $a_name = isset($_POST['m_name']) ? (array) $_POST['m_name'] : array();
        $name = $sp_freitext(isset($a_name[$i]) ? $a_name[$i] : '');
        if ($host === '' && $name === '') { continue; }
        $art = $hol('m_art') === 'esphome' ? 'esphome' : 'wyoming';
        if ($host === '') {
            $sp_fehler[] = sprintf(sp_t('MIKRO.FEHLER_HOST_FEHLT'), $i + 1);
            continue;
        }
        if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9\.\-:_]{0,80}$/', $host)) {
            $sp_fehler[] = sprintf(sp_t('MIKRO.FEHLER_HOST'), $i + 1);
            continue;
        }
        $port = $hol('m_port');
        if ($port === '') { $port = $art === 'esphome' ? '6053' : '10700'; }
        if (!preg_match('/^[0-9]+$/', $port) || (int) $port < 1 || (int) $port > 65535) {
            $sp_fehler[] = sprintf(sp_t('MIKRO.FEHLER_PORT'), $i + 1);
            continue;
        }
        $zone = $hol('m_zone');
        if ($zone !== '' && !preg_match('/^[0-9~,]{1,40}$/', $zone)) {
            $sp_fehler[] = sprintf(sp_t('MIKRO.FEHLER_ZONE'), $i + 1);
            continue;
        }
        $eintrag = array('art' => $art, 'name' => $name !== '' ? $name : $host,
                         'host' => $host, 'port' => (int) $port,
                         'raum' => $sp_freitext($hol('m_raum')), 'zone' => $zone);
        if ($art === 'esphome') {
            // Der Noise-Schluessel ist ein Geheimnis: leer heisst beibehalten.
            $schluessel = isset($_POST['m_schluessel'][$i]) ? (string) $_POST['m_schluessel'][$i] : '';
            $alt = isset($sp_cfg['satelliten'][$i]['schluessel']) ? $sp_cfg['satelliten'][$i]['schluessel'] : '';
            $eintrag['schluessel'] = $schluessel !== '' ? $schluessel : $alt;
        }
        $sp_neu[] = $eintrag;
    }
    if (!$sp_fehler) {
        $sp_cfg['satelliten'] = $sp_neu;
        if (sp_config_speichern($sp_cfg)) { $sp_meldungen[] = sp_t('MIKRO.GESPEICHERT'); }
        else { $sp_fehler[] = sprintf(sp_t('EINST.FEHLER_SPEICHERN'), $sp_p['config']); }
    }
    $sp_tab = 'tab-mics';
}

/* ---------------- Ein Mikrofon einzeln pruefen ---------------- */
if ($sp_post && isset($_POST['mikro_pruefen'])) {
    $sp_i = (int) $_POST['mikro_pruefen'];
    $sp_liste = sp_config()['satelliten'];
    if (isset($sp_liste[$sp_i]) && is_array($sp_liste[$sp_i])) {
        $sp_s = $sp_liste[$sp_i];
        $sp_art = isset($sp_s['art']) && $sp_s['art'] === 'esphome' ? 'esphome' : 'wyoming';
        $sp_pt = (int) (isset($sp_s['port']) && $sp_s['port'] ? $sp_s['port'] : ($sp_art === 'esphome' ? 6053 : 10700));
        list($sp_ok, $sp_grund) = sp_erreichbar((string) $sp_s['host'], $sp_pt);
        $sp_txt = sp_e((string) $sp_s['name']) . ': ' . sp_e($sp_s['host'] . ':' . $sp_pt);
        if ($sp_ok) { $sp_meldungen[] = $sp_txt . ' &mdash; ' . sp_t('MIKRO.ANTWORTET'); }
        else { $sp_fehler[] = $sp_txt . ' &mdash; ' . sp_e($sp_grund); }
    }
    $sp_tab = 'tab-mics';
}

/* ---------------- Saetze: Maske ---------------- */
if ($sp_post && isset($_POST['ziele_speichern'])) {
    $sp_d = sp_saetze();
    if (!isset($sp_d['regeln']) || !is_array($sp_d['regeln'])) { $sp_d['regeln'] = array(); }
    $sp_zneu = array();
    $sp_keys = isset($_POST['z_key']) ? (array) $_POST['z_key'] : array();
    foreach ($sp_keys as $i => $roh_key) {
        $key = trim(preg_replace('/[^A-Za-z0-9_\-]/', '', (string) $roh_key));
        $hol = function ($feld) use ($i) {
            $a = isset($_POST[$feld]) ? (array) $_POST[$feld] : array();
            return isset($a[$i]) ? trim(preg_replace('/[\x00-\x1F\x7F]/u', ' ', (string) $a[$i])) : '';
        };
        $name = $hol('z_name');
        if ($key === '' && $name === '') { continue; }
        if ($key === '') {
            $sp_fehler[] = sprintf(sp_t('SATZ.FEHLER_SCHLUESSEL'), $i + 1);
            continue;
        }
        if (isset($sp_zneu[$key])) {
            $sp_fehler[] = sprintf(sp_t('SATZ.FEHLER_DOPPELT'), sp_e($key));
            continue;
        }
        $thema = trim($hol('z_thema'), '/');
        if ($thema !== '' && !preg_match('#^[A-Za-z0-9_/\-]{1,80}$#', $thema)) {
            $sp_fehler[] = sprintf(sp_t('SATZ.FEHLER_THEMA'), sp_e($key));
            continue;
        }
        $lesen = trim($hol('z_lesen'));
        if ($lesen !== '' && !sp_url_ok($lesen)) {
            $sp_fehler[] = sprintf(sp_t('SATZ.FEHLER_LESEN'), sp_e($key));
            continue;
        }
        $alias = array();
        foreach (explode(',', $hol('z_alias')) as $a) {
            $a = trim($a);
            if ($a !== '') { $alias[] = $a; }
        }
        $eintrag = array('name' => $name !== '' ? $name : $key,
                         'alias' => $alias,
                         'thema' => $thema !== '' ? $thema : $key);
        $einheit = $hol('z_einheit');
        if ($einheit !== '') { $eintrag['einheit'] = $einheit; }
        if ($lesen !== '') { $eintrag['url_lesen'] = $lesen; }
        $bestaetigen = isset($_POST['z_bestaetigen']) ? (array) $_POST['z_bestaetigen'] : array();
        if (!empty($bestaetigen[$i])) { $eintrag['bestaetigen'] = true; }
        $sp_zneu[$key] = $eintrag;
    }
    if (!$sp_fehler) {
        $sp_d['ziele'] = $sp_zneu;
        $sp_d = sp_steuerzeichen_weg($sp_d);
        if (sp_saetze_speichern($sp_d)) { $sp_meldungen[] = sp_t('SATZ.ZIELE_GESPEICHERT'); }
        else { $sp_fehler[] = sprintf(sp_t('EINST.FEHLER_SPEICHERN'), $sp_p['saetze']); }
    }
    $sp_tab = 'tab-sentences';
}

/* ---------------- Saetze: Rohtext ---------------- */
if ($sp_post && isset($_POST['saetze_speichern'])) {
    $sp_roh = (string) (isset($_POST['saetze']) ? $_POST['saetze'] : '');
    $sp_d = json_decode($sp_roh, true);
    if (!is_array($sp_d)) {
        // Kaputtes JSON wird NICHT gespeichert und NICHT zurechtgebogen.
        $sp_fehler[] = sp_t('SATZ.FEHLER_JSON') . ' ' . sp_e(json_last_error_msg());
    } elseif (!isset($sp_d['regeln']) || !is_array($sp_d['regeln'])) {
        $sp_fehler[] = sp_t('SATZ.FEHLER_REGELN');
    } elseif (!isset($sp_d['ziele']) || !is_array($sp_d['ziele'])) {
        $sp_fehler[] = sp_t('SATZ.FEHLER_ZIELE');
    } else {
        foreach ($sp_d['regeln'] as $sp_i => $sp_r) {
            if (!is_array($sp_r) || trim((string) (isset($sp_r['muster']) ? $sp_r['muster'] : '')) === '') {
                $sp_fehler[] = sprintf(sp_t('SATZ.FEHLER_MUSTER'), (int) $sp_i + 1);
            }
        }
        foreach ($sp_d['ziele'] as $sp_k => $sp_z) {
            if (!is_array($sp_z) && !is_string($sp_z)) {
                $sp_fehler[] = sprintf(sp_t('SATZ.FEHLER_ZIEL_FORM'), sp_e((string) $sp_k));
            }
        }
        if (!$sp_fehler) {
            /* Erst jetzt reinigen, nicht vorher: die Pruefungen oben sollen
             * das sehen, was eingegeben wurde. */
            $sp_d = sp_steuerzeichen_weg($sp_d);
            if (sp_saetze_speichern($sp_d)) { $sp_meldungen[] = sp_t('SATZ.GESPEICHERT'); }
            else { $sp_fehler[] = sprintf(sp_t('EINST.FEHLER_SPEICHERN'), $sp_p['saetze']); }
        }
    }
    $sp_tab = 'tab-sentences';
}

/* ---------------- Stimme probehoeren ---------------- */
if ($sp_post && isset($_POST['probe_holen'])) {
    // Die fertige WAV-Datei ausliefern. Sie liegt unter data/ und ist von
    // aussen nicht erreichbar - deshalb geht sie durch die angemeldete
    // Oberflaeche.
    $sp_wav = $sp_p['datadir'] . '/probe.wav';
    if (is_file($sp_wav)) {
        header('Content-Type: audio/wav');
        header('Content-Disposition: attachment; filename="sprachprobe.wav"');
        header('Content-Length: ' . (int) filesize($sp_wav));
        readfile($sp_wav);
        exit;
    }
    $sp_fehler[] = sp_t('EINST.PROBE_FEHLT');
    $sp_tab = 'tab-settings';
}
if ($sp_post && isset($_POST['probe_stimme'])) {
    $sp_ptext = trim(preg_replace('/[\x00-\x1F\x7F]/u', ' ',
        (string) (isset($_POST['probe_text']) ? $_POST['probe_text'] : '')));
    if ($sp_ptext === '') { $sp_ptext = 'Die Sprachsteuerung ist bereit.'; }
    $sp_pstimme = $sp_sauber('tts_stimme');
    list($sp_ok, $sp_meld) = sp_befehl_absetzen(
        array('aktion' => 'probe', 'text' => $sp_ptext, 'stimme' => $sp_pstimme));
    if ($sp_ok === 1) { $sp_meldungen[] = sp_e($sp_meld) . ' ' . sp_t('EINST.PROBE_FERTIG'); }
    else { $sp_fehler[] = sp_e($sp_meld); }
    $sp_tab = 'tab-settings';
}

/* ---------------- Ziele aus Loxone vorschlagen ---------------- */
if ($sp_post && isset($_POST['lox_holen'])) {
    /* Die Zugangsdaten werden EINMAL benutzt und nicht gespeichert. Abgelegt
     * wird nur die Vorschlagsliste - darin stehen Namen, keine Kennwoerter. */
    $sp_lhost = $sp_sauber('lox_host');
    $sp_lben = trim((string) (isset($_POST['lox_benutzer']) ? $_POST['lox_benutzer'] : ''));
    $sp_lkw = (string) (isset($_POST['lox_kennwort']) ? $_POST['lox_kennwort'] : '');
    list($sp_ok, $sp_meld, $sp_vor) = sp_lox_struktur_holen($sp_lhost, $sp_lben, $sp_lkw);
    if (!$sp_ok) {
        $sp_fehler[] = $sp_meld;
    } elseif (!$sp_vor) {
        $sp_fehler[] = sp_t('LOXIMP.NICHTS');
    } else {
        sp_lox_vorschlaege_ablegen($sp_vor);
        $sp_meldungen[] = $sp_meld;
    }
    $sp_tab = 'tab-sentences';
}
if ($sp_post && isset($_POST['lox_verwerfen'])) {
    sp_lox_vorschlaege_weg();
    $sp_meldungen[] = sp_t('LOXIMP.VERWORFEN');
    $sp_tab = 'tab-sentences';
}
if ($sp_post && isset($_POST['lox_uebernehmen'])) {
    $sp_vor = sp_lox_vorschlaege();
    $sp_gewaehlt = isset($_POST['lox_ziel']) ? (array) $_POST['lox_ziel'] : array();
    $sp_d = sp_saetze();
    if (!isset($sp_d['ziele']) || !is_array($sp_d['ziele'])) { $sp_d['ziele'] = array(); }
    if (!isset($sp_d['regeln']) || !is_array($sp_d['regeln'])) { $sp_d['regeln'] = array(); }
    $sp_neu = 0;
    $sp_schon = 0;
    foreach ($sp_gewaehlt as $sp_k) {
        $sp_k = (string) $sp_k;
        if (!isset($sp_vor[$sp_k])) { continue; }
        if (isset($sp_d['ziele'][$sp_k])) { $sp_schon++; continue; }
        $sp_v2 = $sp_vor[$sp_k];
        $sp_d['ziele'][$sp_k] = array(
            'name'  => $sp_v2['name'],
            'alias' => array_values((array) $sp_v2['alias']),
            'thema' => $sp_v2['thema'],
        );
        $sp_neu++;
    }
    if (!$sp_neu && !$sp_schon) {
        $sp_fehler[] = sp_t('LOXIMP.NICHTS_GEWAEHLT');
    } elseif (sp_saetze_speichern(sp_steuerzeichen_weg($sp_d))) {
        $sp_meldungen[] = sprintf(sp_t('LOXIMP.UEBERNOMMEN'), $sp_neu, $sp_schon);
        sp_lox_vorschlaege_weg();
    } else {
        $sp_fehler[] = sprintf(sp_t('EINST.FEHLER_SPEICHERN'), $sp_p['saetze']);
    }
    $sp_tab = 'tab-sentences';
}

/* ---------------- Alias aus dem Verlauf uebernehmen ---------------- */
if ($sp_post && isset($_POST['alias_uebernehmen']) && isset($_POST['alias_ziel'])) {
    $sp_alias = $sp_freitext((string) $_POST['alias_uebernehmen']);
    $sp_zk = trim(preg_replace('/[^A-Za-z0-9_\-]/', '', (string) $_POST['alias_ziel']));
    $sp_d = sp_saetze();
    if ($sp_alias === '' || $sp_zk === '' || !isset($sp_d['ziele'][$sp_zk])
        || !is_array($sp_d['ziele'][$sp_zk])) {
        $sp_fehler[] = sp_t('TEST.M_ALIAS_FEHL');
    } else {
        $sp_liste = isset($sp_d['ziele'][$sp_zk]['alias'])
                  ? (array) $sp_d['ziele'][$sp_zk]['alias'] : array();
        if (!in_array($sp_alias, $sp_liste, true)) { $sp_liste[] = $sp_alias; }
        $sp_d['ziele'][$sp_zk]['alias'] = array_values($sp_liste);
        if (sp_saetze_speichern(sp_steuerzeichen_weg($sp_d))) {
            $sp_meldungen[] = sprintf(sp_t('TEST.M_ALIAS_OK'), sp_e($sp_alias), sp_e($sp_zk));
        } else {
            $sp_fehler[] = sprintf(sp_t('EINST.FEHLER_SPEICHERN'), $sp_p['saetze']);
        }
    }
    $sp_tab = 'tab-test';
}

/* ---------------- Dienst ---------------- */
if ($sp_post && isset($_POST['dienst'])) {
    list($sp_ok, $sp_aus) = sp_dienst((string) $_POST['dienst']);
    if ($sp_ok) {
        $sp_meldungen[] = sp_t('EINST.DIENST_' . strtoupper((string) $_POST['dienst'])) . ' ' . sp_e($sp_aus);
    } else { $sp_fehler[] = sp_e($sp_aus); }
    $sp_tab = 'tab-settings';
}

/* ---------------- Container ---------------- */
if ($sp_post && isset($_POST['container']) && isset($_POST['dienstname'])) {
    $sp_was = (string) $_POST['container'];
    $sp_dn = (string) $_POST['dienstname'];
    if (!in_array($sp_was, array('anlegen', 'start', 'stop', 'restart', 'entfernen', 'holen'), true)) {
        $sp_fehler[] = sp_t('DIENST.FEHLER_BEFEHL');
    } else {
        list($sp_ok, $sp_aus) = sp_container($sp_dn, $sp_was);
        if ($sp_ok) {
            $sp_meldungen[] = sprintf(sp_t('DIENST.CONTAINER_OK'), sp_e($sp_dn), sp_e($sp_was))
                            . ' <span class="sm-mono">' . sp_e(substr($sp_aus, 0, 160)) . '</span>';
        } else {
            $sp_fehler[] = sprintf(sp_t('DIENST.CONTAINER_FEHL'), sp_e($sp_dn), sp_e($sp_was))
                         . ' <span class="sm-mono">' . sp_e(substr($sp_aus, 0, 400)) . '</span>';
        }
    }
    $sp_tab = 'tab-services';
}
if ($sp_post && isset($_POST['containerlog'])) {
    $sp_ausgabe = sp_container_log((string) $_POST['containerlog'], 200);
    $sp_tab = 'tab-services';
}
if ($sp_post && isset($_POST['messen'])) {
    $sp_messung = sp_hardware(true);
    $sp_ausgabe = json_encode($sp_messung, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $sp_tab = 'tab-services';
}

/* ---------------- Token, Log, Mitschnitt, Test ---------------- */
if ($sp_post && isset($_POST['token_neu'])) {
    $sp_cfg = sp_config();
    $sp_cfg['aktionstoken'] = sp_token_erzeugen();
    if (sp_config_speichern($sp_cfg)) { $sp_meldungen[] = sp_t('LOX.TOKEN_NEU'); }
    else { $sp_fehler[] = sprintf(sp_t('EINST.FEHLER_SPEICHERN'), $sp_p['config']); }
    $sp_tab = 'tab-loxone';
}
if ($sp_post && isset($_POST['log_leeren'])) {
    @mkdir(dirname($sp_p['log']), 0775, true);
    // In die Logdatei gehoert Klartext, kein HTML.
    $sp_klartext = trim(strip_tags(html_entity_decode(sp_t('LOG.GELEERT'), ENT_QUOTES, 'UTF-8')));
    @file_put_contents($sp_p['log'], '[' . date('Y-m-d H:i:s') . '] ' . $sp_klartext . "\n");
    $sp_meldungen[] = sp_t('LOG.GELEERT');
    $sp_tab = 'tab-log';
}
if ($sp_post && isset($_POST['mitschnitt'])) {
    list($sp_ok, $sp_meld) = sp_mitschnitt_schalten((int) $_POST['mitschnitt']);
    if ($sp_ok) { $sp_meldungen[] = $sp_meld; } else { $sp_fehler[] = sp_e($sp_meld); }
    $sp_tab = 'tab-log';
}
if ($sp_post && isset($_POST['test'])) {
    list($sp_stand, $sp_text) = sp_test_aktion((string) $_POST['test']);
    if ($sp_stand === 1) { $sp_meldungen[] = $sp_text; } else { $sp_fehler[] = $sp_text; }
    $sp_tab = 'tab-test';
}
if ($sp_post && isset($_POST['selbsttest'])) {
    $sp_ausgabe = sp_selbsttest_ausgabe();
    $sp_tab = 'tab-test';
}

/* ---------------- Laden ---------------- */
$sp_cfg = sp_config();
$sp_token = sp_token();
$sp_fmt = sp_formtoken();          // nach sp_token(): vorher gab es keins
$sp_saetze = sp_saetze();
$sp_sats = sp_satelliten();
$sp_verlauf = sp_verlauf();
$sp_alter = sp_alter();
$sp_pid = sp_dienst_pid();
$sp_mqtt = sp_mqtt_zustand();
$sp_gw = sp_mqtt_gateway_info();
$sp_modelle = sp_modelle();
$sp_lox = sp_loxone();
$sp_host = sp_hostname();
$sp_basis = 'http://' . $sp_host . '/plugins/' . $sp_p['plugin'] . '/index.php';
$sp_logzeilen = is_file($sp_p['log']) ? sp_log_ende($sp_p['log'], 400) : array();
list($sp_ruhe_jetzt, $sp_ruhe_grund) = sp_ruhe_aktiv($sp_cfg);
$sp_praefix = trim((string) $sp_cfg['mqtt_topic'], '/');

/* Zwei Reiter kosten etwas: 'Test' fragt vier Ports ab und ruft den eigenen
 * Endpunkt ueber HTTP auf, 'Dienste' startet hardware.py und fragt Docker.
 * Beides lief bis 0.9.11 bei JEDEM Seitenaufbau mit, auch wenn der Reiter gar
 * nicht offen war - die Hausregel nennt das ausdruecklich ('Eine
 * Selbstpruefung, die das Netz befragt, laeuft bei jedem Seitenaufbau').
 *
 * Der Selbstaufruf des Endpunkts macht es zusaetzlich heikel: ein Webserver,
 * der nur eine Anfrage zugleich bearbeitet, kann sich nicht selbst aufrufen.
 *
 * Gerechnet wird deshalb nur noch fuer den OFFENEN Reiter. Die Leiste laedt
 * fuer diese beiden Reiter die Seite wirklich neu (data-laden am Link), statt
 * nur umzuschalten - sonst staende dort eine leere Flaeche. */
/* Ohne Praefix - genau wie $sp_reiter_ids. Mit 'tab-' davor haelt
 * hausstandard_pruefen.py diese Liste fuer die Positivliste der Reiter
 * und meldet 'Liste 2' statt 8. */
$sp_teuer = array('test', 'services');
$sp_offen = function ($t) use ($sp_tab) { return $sp_tab === $t; };

$sp_hw = $sp_offen('tab-services') ? sp_hardware(false) : array();
$sp_emp = isset($sp_hw['empfehlung']) ? $sp_hw['empfehlung'] : array();

$sp_rahmen = class_exists('LBWeb', false);
if ($sp_rahmen) {
    LBWeb::lbheader('Sprachsteuerung lokal', 'https://wiki.loxberry.de/', 'help.html');
}
?>
<style>
/* Hausstandard, wortgetreu aus VORLAGE_hausstandard.css.html uebernommen.
   Nicht neu erfinden: der Knopf-Fehler vom 30.07.2026 steckte in sieben
   Plugins gleichzeitig, weil jedes seine eigene Kopie hatte. */
.sm-wrap { max-width: 980px; margin: 0 auto; font-family: -apple-system, 'Segoe UI', Roboto, sans-serif; color: #333; }
.sm-wrap, .sm-wrap *, .sm-tabs, .sm-tabs * { text-shadow: none !important; }
.sm-wrap h2 { color: #6dac20; margin: 24px 0 10px; font-size: 1.15em; border-bottom: 2px solid #e0e0e0; padding-bottom: 6px; }
.sm-wrap h3 { color: #4f7d17; font-size: 1.0em; font-weight: 700; margin: 16px 0 2px; }
.sm-tabs { display: flex; gap: 4px; margin: 14px 0 0; border-bottom: 2px solid #6dac20; flex-wrap: wrap; }
.sm-tab { background: #eee; border: 1px solid #ccc; border-bottom: 0; border-radius: 8px 8px 0 0;
          padding: 9px 18px; font-size: 0.95em; color: #444 !important; text-decoration: none; display: inline-block; }
.sm-tab.sm-active { background: #6dac20; color: #fff !important; border-color: #6dac20; font-weight: 600; }
.sm-feld { margin: 14px 0; }
.sm-feld > label { display: block; font-weight: 600; font-size: 0.9em; color: #555; margin: 0 0 4px; }
.sm-feld .ui-input-text, .sm-feld .ui-select, .sm-feld .ui-textinput { max-width: 520px; }
.sm-feld .ui-input-text input, .sm-feld .ui-input-text textarea { font-size: 0.95em; }
.sm-hilfe { font-size: 0.85em; color: #555; margin: 4px 0 0; max-width: 640px; }
.sm-step { border: 1px solid #ddd; border-left: 4px solid #6dac20; background: #fafafa;
    border-radius: 6px; padding: 12px 14px; margin: 12px 0; font-size: 0.92em; line-height: 1.5; }
.sm-tbl { border-collapse: collapse; width: 100%; margin: 8px 0; font-size: 0.9em; }
.sm-tbl th, .sm-tbl td { border: 1px solid #ccc; padding: 5px 7px; text-align: left; vertical-align: top; }
.sm-tbl th { background: #eef3e6; font-weight: 600; }
.sm-roll { overflow-x: auto; }
.sm-mono { font-family: Consolas, "Courier New", monospace; background: #f0f0f0;
    padding: 1px 4px; border-radius: 3px; font-size: 0.94em; word-break: break-all; }
.sm-pre { background: #f4f4f4; border: 1px solid #ccc; padding: 10px; font-size: 0.85em;
    overflow: auto; margin: 8px 0; white-space: pre-wrap; }
.sm-knopfreihe { display: flex; flex-wrap: wrap; gap: 10px; margin: 10px 0 4px; align-items: stretch; }
.sm-knopfreihe form { margin: 0; display: flex; }
.sm-wrap .sm-knopfreihe .sm-btn, .sm-wrap a.sm-btn, .sm-wrap button.sm-btn {
    flex: 0 0 auto; min-width: 250px; text-align: center; display: inline-flex;
    align-items: center; justify-content: center; line-height: 1.25;
    padding: 10px 14px !important; border-radius: 6px !important;
    color: #fff !important; text-decoration: none !important; font-size: 0.92em;
    border: 0 !important; cursor: pointer; font-weight: 600 !important;
    text-shadow: none !important; box-shadow: none !important;
    opacity: 1 !important; margin: 0 !important; width: auto !important; }
.sm-kacheln { display: flex; flex-wrap: wrap; gap: 10px; margin: 10px 0; }
.sm-kachel { border: 1px solid #ddd; border-radius: 10px; padding: 10px 14px; min-width: 130px; }
.sm-kachel b { display: block; font-size: 1.35em; color: #33691e; }
.sm-legende { display: flex; flex-wrap: wrap; gap: 14px; margin: 10px 0 2px; font-size: 0.86em; color: #555; }
.sm-legende span { display: inline-flex; align-items: center; gap: 6px; }
.sm-punkt { width: 13px; height: 13px; border-radius: 3px; display: inline-block; }
.sm-wrap .sm-btn.sm-b-lesen   { background: #6dac20 !important; }
.sm-wrap .sm-btn.sm-b-technik { background: #546e7a !important; }
.sm-wrap .sm-btn.sm-b-aktion  { background: #e0620d !important; }
.sm-wrap .sm-btn.sm-b-lesen:hover,   .sm-wrap .sm-btn.sm-b-lesen:focus   { background: #5c9219 !important; color: #fff !important; }
.sm-wrap .sm-btn.sm-b-technik:hover, .sm-wrap .sm-btn.sm-b-technik:focus { background: #435962 !important; color: #fff !important; }
.sm-wrap .sm-btn.sm-b-aktion:hover,  .sm-wrap .sm-btn.sm-b-aktion:focus  { background: #b84f0a !important; color: #fff !important; }
.sm-punkt.sm-b-lesen   { background: #6dac20; }
.sm-punkt.sm-b-technik { background: #546e7a; }
.sm-punkt.sm-b-aktion  { background: #e0620d; }
.sm-seite { display: none; padding-top: 4px; }
.sm-seite.sm-active { display: block; }
.sm-hinweis { border: 1px solid #cfe3b0; background: #f2f8ea; border-radius: 6px;
    padding: 10px 12px; margin: 12px 0; font-size: 0.9em; }
.sm-warnung { border: 1px solid #f0c9a0; background: #fdf4ec; border-radius: 6px;
    padding: 10px 12px; margin: 12px 0; font-size: 0.9em; }
.sm-fehler { border: 1px solid #ef9a9a; background: #ffebee; border-radius: 6px;
    padding: 10px 12px; margin: 12px 0; font-size: 0.9em; }
.sm-an  { color: #1a7f1a; font-weight: 700; }
.sm-aus { color: #b00000; font-weight: 700; }
.sm-log { background: #1e1e1e; color: #d4d4d4; font-family: Consolas, "Courier New", monospace;
    font-size: 0.82em; padding: 12px; border-radius: 8px; max-height: 480px; overflow: auto;
    white-space: pre-wrap; }
</style>
<div class="sm-wrap">

<?php foreach ($sp_meldungen as $sp_m) { ?>
<div class="sm-hinweis"><?= $sp_m ?></div>
<?php } ?>
<?php if ($sp_fehler) { ?>
<div class="sm-fehler"><b><?= sp_e(sp_t('ALLG.BEANSTANDUNG')) ?></b>
<ul style="margin:6px 0 0 18px;padding:0;">
<?php foreach ($sp_fehler as $sp_f) { ?><li><?= $sp_f ?></li><?php } ?>
</ul></div>
<?php } ?>

<div class="sm-kacheln">
  <div class="sm-kachel"><?= sp_e(sp_t('ALLG.DIENST')) ?>
    <b class="<?= $sp_pid ? 'sm-an' : 'sm-aus' ?>"><?= $sp_pid ? sp_e(sp_t('ALLG.LAEUFT')) : sp_e(sp_t('ALLG.GESTOPPT')) ?></b>
    <span class="sm-hilfe"><?= $sp_pid ? 'PID ' . (int) $sp_pid : sp_e(sp_t('ALLG.KEINE_PID')) ?></span>
  </div>
  <div class="sm-kachel"><?= sp_e(sp_t('ALLG.MIKROFONE')) ?>
    <b><?= count((array) $sp_cfg['satelliten']) ?></b>
    <span class="sm-hilfe"><?php
      $sp_bereit = 0;
      foreach ($sp_sats as $sp_s) { if ($sp_s['zustand'] !== 'getrennt') { $sp_bereit++; } }
      echo (int) $sp_bereit . ' ' . sp_e(sp_t('ALLG.VERBUNDEN'));
    ?></span>
  </div>
  <div class="sm-kachel"><?= sp_e(sp_t('ALLG.SAETZE')) ?>
    <b><?= isset($sp_saetze['regeln']) ? count((array) $sp_saetze['regeln']) : 0 ?></b>
    <span class="sm-hilfe"><?= isset($sp_saetze['ziele']) ? count((array) $sp_saetze['ziele']) : 0 ?> <?= sp_e(sp_t('ALLG.ZIELE')) ?></span>
  </div>
  <div class="sm-kachel">MQTT
    <b class="<?= $sp_mqtt['autostart'] ? 'sm-an' : 'sm-aus' ?>"><?= $sp_mqtt['autostart'] ? sp_e(sp_t('ALLG.EIN')) : sp_e(sp_t('ALLG.AUS')) ?></b>
    <span class="sm-hilfe"><?= sp_e(sp_t('ALLG.GATEWAY')) ?></span>
  </div>
  <div class="sm-kachel"><?= sp_e(sp_t('ALLG.RUHE')) ?>
    <b class="<?= $sp_ruhe_jetzt ? 'sm-aus' : 'sm-an' ?>"><?= $sp_ruhe_jetzt ? sp_e(sp_t('ALLG.STILL')) : sp_e(sp_t('ALLG.SPRICHT')) ?></b>
    <span class="sm-hilfe"><?= $sp_ruhe_jetzt ? sp_e($sp_ruhe_grund) : sp_e(sp_t('ALLG.RUHE_AUS')) ?></span>
  </div>
</div>

<?php if ($sp_verlauf) { $sp_letzter = $sp_verlauf[0]; ?>
<div class="sm-hinweis"><b><?= sp_e(sp_t('ALLG.ZULETZT')) ?></b>
<span class="sm-mono"><?= sp_e((string) (isset($sp_letzter['satz']) ? $sp_letzter['satz'] : '')) ?></span>
<?php if (!empty($sp_letzter['mikrofon'])) { ?>(<?= sp_e((string) $sp_letzter['mikrofon']) ?>)<?php } ?>
&rarr; <?= !empty($sp_letzter['ok']) ? sp_e((string) $sp_letzter['antwort']) : '<span class="sm-aus">' . sp_e((string) $sp_letzter['antwort']) . '</span>' ?>
</div>
<?php } ?>

<?php
$sp_beschriftung = array(
    'settings'  => 'REITER.EINSTELLUNGEN', 'mqtt' => 'REITER.MQTT',
    'services'  => 'REITER.DIENSTE',
    'mics'      => 'REITER.MIKROFONE',     'sentences' => 'REITER.SAETZE',
    'loxone'    => 'REITER.LOXONE',        'test'      => 'REITER.TEST',
    'log'       => 'REITER.LOG',
);
/* Jedes Formular fuehrt dieses versteckte Feld - gleich ob es etwas aendert
 * oder nur einen Download ausloest. Die Pruefzeile im Reiter Test zaehlt
 * nach, ob wirklich jedes es hat. */
$sp_hidden = function ($tab) use ($sp_fmt) {
    echo '<input data-role="none" type="hidden" name="fmt" value="' . sp_e($sp_fmt) . '">'
       . '<input data-role="none" type="hidden" name="activetab" value="' . sp_e($tab) . '">';
};
?>
<div class="sm-tabs">
<?php foreach ($sp_reiter_ids as $sp_r) { ?>
	<a class="sm-tab<?= $sp_tab === 'tab-' . $sp_r ? ' sm-active' : '' ?>" data-ziel="tab-<?= $sp_r ?>"<?= in_array($sp_r, $sp_teuer, true) ? ' data-laden="1"' : '' ?> href="index.php?form=<?= $sp_r ?>"><?= sp_e(isset($sp_beschriftung[$sp_r]) ? sp_t($sp_beschriftung[$sp_r]) : $sp_r) ?></a>
<?php } ?>
</div>

<!-- ================= Reiter: Einstellungen ================= -->
<div class="sm-seite<?= $sp_tab === 'tab-settings' ? ' sm-active' : '' ?>" id="tab-settings">
<div class="sm-warnung"><?= sp_t('EINST.WAS_IST_DAS') ?></div>

<h2><?= sp_e(sp_t('EINST.H_DIENST')) ?></h2>
<p class="sm-hilfe"><?= sp_t('EINST.DIENST_ERKLAERUNG') ?></p>
<div class="sm-legende">
<span><i class="sm-punkt sm-b-lesen"></i><?= sp_t('LEGENDE.LESEN_START') ?></span>
<span><i class="sm-punkt sm-b-aktion"></i><?= sp_t('LEGENDE.AKTION') ?></span>
</div>
<?php /* Die Knopfklassen stehen AUSGESCHRIEBEN und nicht als <?= $farbe ?>:
   hausstandard_pruefen.py sucht woertlich nach sm-btn ... sm-b-<farbe> und
   ist gegen eine zusammengesetzte Klasse blind. Der gruene Knopf war fuer
   die Pruefung damit nicht vorhanden, und die Legende sah falsch aus. Wer
   eine Pruefung blind macht, ERSETZT sie. */ ?>
<div class="sm-knopfreihe">
  <form action="index.php" method="post">
    <?php $sp_hidden('tab-settings'); ?>
    <button data-role="none" class="sm-btn sm-b-lesen" type="submit" name="dienst" value="start"><?= sp_e(sp_t('EINST.K_START')) ?></button>
  </form>
  <form action="index.php" method="post">
    <?php $sp_hidden('tab-settings'); ?>
    <button data-role="none" class="sm-btn sm-b-aktion" type="submit" name="dienst" value="restart"><?= sp_e(sp_t('EINST.K_RESTART')) ?></button>
  </form>
  <form action="index.php" method="post">
    <?php $sp_hidden('tab-settings'); ?>
    <button data-role="none" class="sm-btn sm-b-aktion" type="submit" name="dienst" value="stop"><?= sp_e(sp_t('EINST.K_STOP')) ?></button>
  </form>
</div>

<form action="index.php" method="post" autocomplete="off">
<?php $sp_hidden('tab-settings'); ?>
<input data-role="none" type="hidden" name="speichern" value="1">

<h2><?= sp_e(sp_t('EINST.H_DIENSTE')) ?></h2>
<p class="sm-hilfe"><?= sp_t('EINST.DIENSTE_ERKLAERUNG') ?></p>
<div class="sm-roll">
<table class="sm-tbl">
<tr><th><?= sp_e(sp_t('EINST.T_DIENST')) ?></th><th><?= sp_e(sp_t('EINST.T_ADRESSE')) ?></th><th><?= sp_e(sp_t('EINST.T_PORT')) ?></th></tr>
<?php foreach (array('whisper', 'piper', 'wake', 'llm') as $sp_d) { ?>
<tr><td><?= sp_e(sp_t('EINST.L_' . strtoupper($sp_d))) ?></td>
    <td><input data-role="none" type="text" name="<?= $sp_d ?>_host" value="<?= sp_e($sp_cfg[$sp_d . '_host']) ?>" size="16"></td>
    <td><input data-role="none" type="text" name="<?= $sp_d ?>_port" value="<?= (int) $sp_cfg[$sp_d . '_port'] ?>" size="6"></td></tr>
<?php } ?>
</table>
</div>

<h2><?= sp_e(sp_t('EINST.H_VERSTEHEN')) ?></h2>
<div class="sm-hinweis"><?= sp_t('EINST.VERSTEHEN_ERKLAERUNG') ?></div>
<div class="sm-feld">
  <label style="display:inline-flex;align-items:center;gap:8px;">
    <input data-role="none" type="checkbox" name="llm_ein" value="1" <?= !empty($sp_cfg['llm_ein']) ? 'checked' : '' ?>>
    <?= sp_e(sp_t('EINST.L_LLM_EIN')) ?>
  </label>
  <div class="sm-hilfe"><?= sp_t('EINST.H_LLM_EIN') ?></div>
</div>
<div class="sm-feld">
  <label style="display:inline-flex;align-items:center;gap:8px;">
    <input data-role="none" type="checkbox" name="antwort_sprechen" value="1" <?= !empty($sp_cfg['antwort_sprechen']) ? 'checked' : '' ?>>
    <?= sp_e(sp_t('EINST.L_ANTWORT')) ?>
  </label>
</div>
<div class="sm-feld">
  <label for="kontext_s"><?= sp_e(sp_t('EINST.L_KONTEXT_S')) ?></label>
  <input data-role="none" type="number" id="kontext_s" name="kontext_s" value="<?= (int) $sp_cfg['kontext_s'] ?>" min="0" max="300">
  <div class="sm-hilfe"><?= sp_t('EINST.H_KONTEXT_S') ?></div>
</div>
<div class="sm-feld">
  <label for="bestaetigung_s"><?= sp_e(sp_t('EINST.L_BESTAETIGUNG_S')) ?></label>
  <input data-role="none" type="number" id="bestaetigung_s" name="bestaetigung_s" value="<?= (int) $sp_cfg['bestaetigung_s'] ?>" min="0" max="120">
  <div class="sm-hilfe"><?= sp_t('EINST.H_BESTAETIGUNG_S') ?></div>
</div>

<h2><?= sp_e(sp_t('EINST.H_ANTWORTWEG')) ?></h2>
<div class="sm-hinweis"><?= sp_t('EINST.H_ANTWORTWEG_TEXT') ?></div>
<div class="sm-feld">
  <label for="antwortweg"><?= sp_e(sp_t('EINST.L_ANTWORTWEG')) ?></label>
  <select data-role="none" id="antwortweg" name="antwortweg">
<?php foreach (sp_auswahl('antwortweg') as $sp_w) { ?>
    <option value="<?= sp_e($sp_w) ?>"<?= $sp_cfg['antwortweg'] === $sp_w ? ' selected' : '' ?>><?= sp_e(sp_t('EINST.WEG_' . strtoupper($sp_w))) ?></option>
<?php } ?>
  </select>
  <div class="sm-hilfe"><?= sp_t('EINST.H_ANTWORTWEG_FELD') ?></div>
</div>
<div class="sm-feld">
  <label for="tts_mode"><?= sp_e(sp_t('EINST.L_TTS_MODE')) ?></label>
  <select data-role="none" id="tts_mode" name="tts_mode">
<?php foreach (sp_auswahl('tts_mode') as $sp_m) { ?>
    <option value="<?= sp_e($sp_m) ?>"<?= $sp_cfg['tts']['mode'] === $sp_m ? ' selected' : '' ?>><?= sp_e(sp_t('EINST.TTS_' . strtoupper($sp_m))) ?></option>
<?php } ?>
  </select>
  <div class="sm-hilfe"><?= sp_t('EINST.H_TTS_MODE') ?></div>
</div>
<div class="sm-feld">
  <label for="tts_ip"><?= sp_e(sp_t('EINST.L_TTS_IP')) ?></label>
  <input data-role="none" type="text" id="tts_ip" name="tts_ip" value="<?= sp_e($sp_cfg['tts']['ip']) ?>" placeholder="192.168.1.20">
</div>
<div class="sm-feld">
  <label for="tts_port"><?= sp_e(sp_t('EINST.L_TTS_PORT')) ?></label>
  <input data-role="none" type="number" id="tts_port" name="tts_port" value="<?= (int) $sp_cfg['tts']['port'] ?>" min="1" max="65535">
</div>
<div class="sm-feld">
  <label for="tts_zones"><?= sp_e(sp_t('EINST.L_TTS_ZONES')) ?></label>
  <input data-role="none" type="text" id="tts_zones" name="tts_zones" value="<?= sp_e($sp_cfg['tts']['zones']) ?>" placeholder="2,4">
  <div class="sm-hilfe"><?= sp_t('EINST.H_TTS_ZONES') ?></div>
</div>
<div class="sm-feld">
  <label for="tts_volume"><?= sp_e(sp_t('EINST.L_TTS_VOLUME')) ?></label>
  <input data-role="none" type="number" id="tts_volume" name="tts_volume" value="<?= (int) $sp_cfg['tts']['volume'] ?>" min="1" max="100">
</div>
<div class="sm-feld">
  <label for="tts_lang"><?= sp_e(sp_t('EINST.L_TTS_LANG')) ?></label>
  <input data-role="none" type="text" id="tts_lang" name="tts_lang" value="<?= sp_e($sp_cfg['tts']['lang']) ?>" maxlength="5">
</div>
<div class="sm-feld">
  <label for="tts_stimme"><?= sp_e(sp_t('EINST.L_TTS_STIMME')) ?></label>
  <input data-role="none" type="text" id="tts_stimme" name="tts_stimme" value="<?= sp_e($sp_cfg['tts']['stimme']) ?>" placeholder="<?= sp_e($sp_cfg['piper_stimme']) ?>">
  <div class="sm-hilfe"><?= sp_t('EINST.H_TTS_STIMME') ?></div>
</div>
<div class="sm-feld">
  <label for="probe_text"><?= sp_e(sp_t('EINST.L_PROBE_TEXT')) ?></label>
  <input data-role="none" type="text" id="probe_text" name="probe_text" value="Die Sprachsteuerung ist bereit.">
  <div class="sm-hilfe"><?= sp_t('EINST.H_PROBE') ?></div>
</div>
<div class="sm-legende">
<span><i class="sm-punkt sm-b-technik"></i> <?= sp_t('LEGENDE.TECHNIK') ?></span>
</div>
<div class="sm-knopfreihe">
  <button data-role="none" class="sm-btn sm-b-technik" type="submit" name="probe_stimme" value="1"><?= sp_e(sp_t('EINST.K_PROBE')) ?></button>
</div>
</form>
<?php if (is_file($sp_p['datadir'] . '/probe.wav')) { ?>
<div class="sm-knopfreihe">
  <form action="index.php" method="post">
    <?php $sp_hidden('tab-settings'); ?>
    <button data-role="none" class="sm-btn sm-b-technik" type="submit" name="probe_holen" value="1"><?= sp_e(sp_t('EINST.K_PROBE_HOLEN')) ?></button>
  </form>
</div>
<?php } ?>
<form action="index.php" method="post" autocomplete="off">
<?php $sp_hidden('tab-settings'); ?>
<input data-role="none" type="hidden" name="speichern" value="1">
<div class="sm-feld">
  <label for="tts_template"><?= sp_e(sp_t('EINST.L_TTS_TEMPLATE')) ?></label>
  <input data-role="none" type="text" id="tts_template" name="tts_template" value="<?= sp_e($sp_cfg['tts']['template']) ?>" placeholder="http://{ip}:{port}/tts?text={text}&amp;zone={zones}&amp;vol={vol}">
  <div class="sm-hilfe"><?= sp_t('EINST.H_TTS_TEMPLATE') ?></div>
</div>

<h2><?= sp_e(sp_t('EINST.H_RUHE')) ?></h2>
<div class="sm-warnung"><?= sp_t('EINST.H_RUHE_TEXT') ?></div>
<div class="sm-feld">
  <label style="display:inline-flex;align-items:center;gap:8px;">
    <input data-role="none" type="checkbox" name="ruhe_ein" value="1" <?= !empty($sp_cfg['ruhe']['ein']) ? 'checked' : '' ?>>
    <?= sp_e(sp_t('EINST.L_RUHE_EIN')) ?>
  </label>
</div>
<div class="sm-feld">
  <label for="ruhe_von"><?= sp_e(sp_t('EINST.L_RUHE_VON')) ?></label>
  <input data-role="none" type="text" id="ruhe_von" name="ruhe_von" value="<?= sp_e($sp_cfg['ruhe']['von']) ?>" size="6" placeholder="22:00">
</div>
<div class="sm-feld">
  <label for="ruhe_bis"><?= sp_e(sp_t('EINST.L_RUHE_BIS')) ?></label>
  <input data-role="none" type="text" id="ruhe_bis" name="ruhe_bis" value="<?= sp_e($sp_cfg['ruhe']['bis']) ?>" size="6" placeholder="07:00">
</div>
<div class="sm-feld">
  <label for="ansage_abstand_s"><?= sp_e(sp_t('EINST.L_ANSAGE_ABSTAND_S')) ?></label>
  <input data-role="none" type="number" id="ansage_abstand_s" name="ansage_abstand_s" value="<?= (int) $sp_cfg['ansage_abstand_s'] ?>" min="0" max="3600">
  <div class="sm-hilfe"><?= sp_t('EINST.H_ANSAGE_ABSTAND_S') ?></div>
</div>
<div class="sm-feld">
  <label for="ansage_je_tag"><?= sp_e(sp_t('EINST.L_ANSAGE_JE_TAG')) ?></label>
  <input data-role="none" type="number" id="ansage_je_tag" name="ansage_je_tag" value="<?= (int) $sp_cfg['ansage_je_tag'] ?>" min="0" max="500">
  <div class="sm-hilfe"><?= sp_t('EINST.H_ANSAGE_JE_TAG') ?></div>
</div>

<h2><?= sp_e(sp_t('EINST.H_LOXONE')) ?></h2>
<div class="sm-feld">
  <label for="miniserver_url"><?= sp_e(sp_t('EINST.L_URL')) ?></label>
  <input data-role="none" type="text" id="miniserver_url" name="miniserver_url" value="<?= sp_e(sp_url_maskiert((string) $sp_cfg['miniserver_url'])) ?>">
  <div class="sm-hilfe"><?= sp_t('EINST.H_URL') ?></div>
  <label style="display:inline-flex;align-items:center;gap:8px;margin-top:6px;font-weight:400;">
    <input data-role="none" type="checkbox" name="miniserver_url_loeschen" value="1">
    <?= sp_e(sp_t('EINST.L_URL_LOESCHEN')) ?>
  </label>
</div>
<div class="sm-feld">
  <label for="wakeword"><?= sp_e(sp_t('EINST.L_WAKEWORD')) ?></label>
  <select data-role="none" id="wakeword" name="wakeword">
<?php
$sp_ww_liste = sp_wakewords();
if (!in_array((string) $sp_cfg['wakeword'], $sp_ww_liste, true) && $sp_cfg['wakeword'] !== '') {
    $sp_ww_liste[] = (string) $sp_cfg['wakeword'];
}
foreach ($sp_ww_liste as $sp_w) { ?>
    <option value="<?= sp_e($sp_w) ?>"<?= (string) $sp_cfg['wakeword'] === $sp_w ? ' selected' : '' ?>><?= sp_e($sp_w) ?></option>
<?php } ?>
    <option value=""><?= sp_e(sp_t('ALLG.EIGENER_WERT')) ?></option>
  </select>
  <input data-role="none" type="text" name="wakeword_frei" value="" placeholder="<?= sp_e(sp_t('ALLG.EIGENER_WERT_H')) ?>" style="margin-top:6px;">
  <div class="sm-hilfe"><?= sp_t('EINST.H_WAKEWORD') ?></div>
</div>
<div class="sm-feld">
  <label for="sprache"><?= sp_e(sp_t('EINST.L_SPRACHE')) ?></label>
  <input data-role="none" type="text" id="sprache" name="sprache" value="<?= sp_e($sp_cfg['sprache']) ?>" maxlength="2">
</div>
<div class="sm-feld">
  <label for="wartezeit"><?= sp_e(sp_t('EINST.L_WARTEZEIT')) ?></label>
  <input data-role="none" type="number" id="wartezeit" name="wartezeit" value="<?= (int) $sp_cfg['wartezeit'] ?>" min="1" max="12">
  <div class="sm-hilfe"><?= sp_t('EINST.H_WARTEZEIT') ?></div>
</div>
<div class="sm-feld">
  <label for="verlauf_zeilen"><?= sp_e(sp_t('EINST.L_VERLAUF_ZEILEN')) ?></label>
  <input data-role="none" type="number" id="verlauf_zeilen" name="verlauf_zeilen" value="<?= (int) $sp_cfg['verlauf_zeilen'] ?>" min="5" max="500">
</div>

<div class="sm-knopfreihe">
  <button data-role="none" class="sm-btn sm-b-aktion" type="submit"><?= sp_e(sp_t('ALLG.SPEICHERN')) ?></button>
</div>
</form>
</div>

<!-- ================= Reiter: MQTT ================= -->
<div class="sm-seite<?= $sp_tab === 'tab-mqtt' ? ' sm-active' : '' ?>" id="tab-mqtt">
<h2>MQTT</h2>
<div class="sm-hinweis"><?= sp_t('MQTT.EINLEITUNG') ?></div>

<h3><?= sp_e(sp_t('MQTT.H_ZUSTAND')) ?></h3>
<div class="sm-roll">
<table class="sm-tbl">
<tr><th><?= sp_e(sp_t('ALLG.EIGENSCHAFT')) ?></th><th><?= sp_e(sp_t('ALLG.WERT')) ?></th></tr>
<tr><td><?= sp_e(sp_t('MQTT.T_GEFUNDEN')) ?></td>
    <td class="<?= $sp_mqtt['gefunden'] ? 'sm-an' : 'sm-aus' ?>"><?= $sp_mqtt['gefunden'] ? sp_e(sp_t('ALLG.JA')) : sp_e(sp_t('MQTT.A_NICHT_GEFUNDEN')) ?></td></tr>
<tr><td><?= sp_e(sp_t('MQTT.T_AUTOSTART')) ?></td>
    <td class="<?= $sp_mqtt['autostart'] ? 'sm-an' : 'sm-aus' ?>"><?= $sp_mqtt['autostart'] ? sp_e(sp_t('ALLG.EIN')) : sp_e(sp_t('MQTT.A_AUTOSTART_AUS')) ?></td></tr>
<tr><td><?= sp_e(sp_t('MQTT.T_BROKER')) ?></td><td><span class="sm-mono"><?= sp_e($sp_mqtt['broker'] . ':' . $sp_mqtt['brokerport']) ?></span></td></tr>
<tr><td><?= sp_e(sp_t('MQTT.T_UDP')) ?></td><td><span class="sm-mono"><?= (int) $sp_mqtt['udpport'] ?></span></td></tr>
<tr><td><?= sp_e(sp_t('MQTT.T_FASSUNG')) ?></td><td><?= $sp_mqtt['fassung'] ? (int) $sp_mqtt['fassung'] : sp_e(sp_t('MQTT.A_FASSUNG_UNBEKANNT')) ?></td></tr>
</table>
</div>

<h3><?= sp_e(sp_t('MQTT.H_ABO')) ?></h3>
<div class="sm-step"><?= sp_t('MQTT.ABO_EINLEITUNG') ?>
<p><span class="sm-mono"><?= sp_e($sp_praefix) ?>/#</span></p></div>
<?php if ($sp_gw['v1']) { ?>
<div class="sm-step"><b><?= sp_e(sp_t('MQTT.ABO_V1_TITEL')) ?></b><br>
<?= sp_t('MQTT.ABO_V1') ?>
<div class="sm-warnung"><?= sp_t('MQTT.ABO_V1_WARNUNG') ?></div>
</div>
<?php } ?>
<?php if ($sp_gw['v2']) { ?>
<div class="sm-step"><b><?= sp_e(sp_t('MQTT.ABO_V2_TITEL')) ?></b><br>
<?= sp_t('MQTT.ABO_V2') ?>
</div>
<?php } ?>
<?php if (!$sp_gw['fassung']) { ?>
<div class="sm-hinweis"><?= sp_t('MQTT.ABO_UNBEKANNT') ?></div>
<?php } ?>

<h3><?= sp_e(sp_t('MQTT.H_THEMEN')) ?></h3>
<p class="sm-hilfe"><?= sp_t('MQTT.THEMEN_ERKLAERUNG') ?></p>
<div class="sm-roll">
<table class="sm-tbl">
<tr><th><?= sp_e(sp_t('MQTT.T_THEMA')) ?></th><th><?= sp_e(sp_t('MQTT.T_BEDEUTUNG')) ?></th></tr>
<?php
$sp_themen = array(
    'satz' => 'MQTT.B_SATZ', 'absicht' => 'MQTT.B_ABSICHT', 'aktion' => 'MQTT.B_AKTION',
    'ziel' => 'MQTT.B_ZIEL', 'wert' => 'MQTT.B_WERT', 'einheit' => 'MQTT.B_EINHEIT',
    'quelle' => 'MQTT.B_QUELLE', 'mikrofon' => 'MQTT.B_MIKROFON', 'zeit' => 'MQTT.B_ZEIT',
    '&lt;Thema&gt;/aktion' => 'MQTT.B_ZIELTHEMA',
    'antwort' => 'MQTT.B_ANTWORT', 'ok' => 'MQTT.B_OK', 'grund' => 'MQTT.B_GRUND',
    'ansage' => 'MQTT.B_ANSAGE',
    'online' => 'MQTT.B_ONLINE', 'ts' => 'MQTT.B_TS', 'bereit' => 'MQTT.B_BEREIT',
    'dienste_ok' => 'MQTT.B_DIENSTE_OK', 'regeln' => 'MQTT.B_REGELN',
    'ziele' => 'MQTT.B_ZIELE', 'ruhe' => 'MQTT.B_RUHE',
    'letzter_satz_alter' => 'MQTT.B_LETZTER',
);
foreach ($sp_themen as $sp_th => $sp_sch) { ?>
<tr><td><span class="sm-mono"><?= sp_e($sp_praefix) ?>/<?= $sp_th ?></span></td><td><?= sp_t($sp_sch) ?></td></tr>
<?php } ?>
</table>
</div>
<div class="sm-hinweis"><?= sp_t('MQTT.EMPFEHLUNG') ?></div>

<h3><?= sp_e(sp_t('MQTT.H_EINSTELLEN')) ?></h3>
<form action="index.php" method="post">
<?php $sp_hidden('tab-mqtt'); ?>
<input data-role="none" type="hidden" name="mqtt_save" value="1">
<div class="sm-feld">
  <label style="display:inline-flex;align-items:center;gap:8px;">
    <input data-role="none" type="checkbox" name="mqtt_ein" value="1" <?= !empty($sp_cfg['mqtt_ein']) ? 'checked' : '' ?>>
    <?= sp_e(sp_t('EINST.L_MQTT_EIN')) ?>
  </label>
</div>
<div class="sm-feld">
  <label for="mqtt_topic"><?= sp_e(sp_t('EINST.L_MQTT_TOPIC')) ?></label>
  <input data-role="none" type="text" id="mqtt_topic" name="mqtt_topic" value="<?= sp_e($sp_cfg['mqtt_topic']) ?>" placeholder="sprache">
</div>
<div class="sm-feld">
  <label for="herzschlag_s"><?= sp_e(sp_t('MQTT.L_HERZSCHLAG')) ?></label>
  <input data-role="none" type="number" id="herzschlag_s" name="herzschlag_s" value="<?= (int) $sp_cfg['herzschlag_s'] ?>" min="0" max="3600">
  <div class="sm-hilfe"><?= sp_t('MQTT.H_HERZSCHLAG') ?></div>
</div>

<div class="sm-legende"><span><i class="sm-punkt sm-b-aktion"></i> <?= sp_t('LEGENDE.AKTION') ?></span></div>
<div class="sm-knopfreihe">
  <button data-role="none" class="sm-btn sm-b-aktion" type="submit"><?= sp_e(sp_t('ALLG.SPEICHERN')) ?></button>
</div>
</form>
</div>

<!-- ================= Reiter: Dienste ================= -->
<div class="sm-seite<?= $sp_tab === 'tab-services' ? ' sm-active' : '' ?>" id="tab-services">
<?php if (!$sp_offen('tab-services')) { ?>
<div class="sm-hinweis"><?= sp_t('DIENST.ERST_OEFFNEN') ?>
<a href="index.php?form=services"><?= sp_e(sp_t('DIENST.K_JETZT_LADEN')) ?></a></div>
<?php } else { ?>
<h2><?= sp_e(sp_t('DIENST.H_HARDWARE')) ?></h2>
<?php if (!$sp_hw) { ?>
<div class="sm-warnung"><?= sp_t('DIENST.KEINE_HARDWARE') ?></div>
<?php } else { $sp_h = $sp_hw['hardware']; ?>
<div class="sm-roll">
<table class="sm-tbl">
<tr><th><?= sp_e(sp_t('ALLG.EIGENSCHAFT')) ?></th><th><?= sp_e(sp_t('ALLG.WERT')) ?></th></tr>
<tr><td><?= sp_e(sp_t('DIENST.T_ARCH')) ?></td><td class="<?= !empty($sp_h['64bit']) ? 'sm-an' : 'sm-aus' ?>"><?= sp_e($sp_h['architektur']) ?><?= empty($sp_h['64bit']) ? ' &mdash; ' . sp_e(sp_t('DIENST.NICHT64')) : '' ?></td></tr>
<?php if (!empty($sp_h['pi'])) { ?>
<tr><td><?= sp_e(sp_t('DIENST.T_MODELL')) ?></td><td><?= sp_e($sp_h['pi']) ?></td></tr>
<?php } ?>
<tr><td><?= sp_e(sp_t('DIENST.T_CPU')) ?></td><td><?= sp_e($sp_h['cpu']) ?> (<?= (int) $sp_h['kerne'] ?> <?= sp_e(sp_t('DIENST.KERNE')) ?>)</td></tr>
<tr><td><?= sp_e(sp_t('DIENST.T_SPEICHER')) ?></td><td><?= (int) $sp_h['speicher_mb'] ?> MB (<?= (int) $sp_h['frei_mb'] ?> MB <?= sp_e(sp_t('DIENST.FREI')) ?>)</td></tr>
<tr><td><?= sp_e(sp_t('DIENST.T_GPU')) ?></td><td><?= $sp_h['gpu'] !== '' ? sp_e($sp_h['gpu']) : sp_e(sp_t('DIENST.KEINE_GPU')) ?></td></tr>
</table>
</div>
<?php if ($sp_emp) { ?>
<div class="sm-hinweis"><b><?= sprintf(sp_t('DIENST.VORSCHLAG'), sp_e($sp_emp['name'])) ?></b>
<?= sp_t('STUFE.' . strtoupper($sp_emp['name'])) ?>
<div class="sm-roll">
<table class="sm-tbl">
<tr><th><?= sp_e(sp_t('DIENST.T_TEIL')) ?></th><th><?= sp_e(sp_t('DIENST.T_VORSCHLAG')) ?></th><th><?= sp_e(sp_t('DIENST.T_GROESSE')) ?></th></tr>
<tr><td><?= sp_e(sp_t('EINST.L_WHISPER')) ?></td><td><span class="sm-mono"><?= sp_e($sp_emp['whisper']['modell']) ?></span></td><td><?= (int) $sp_emp['whisper']['datei_mb'] ?> MB</td></tr>
<tr><td><?= sp_e(sp_t('EINST.L_PIPER')) ?></td><td><span class="sm-mono"><?= sp_e($sp_emp['piper']['stimme']) ?></span></td><td><?= (int) $sp_emp['piper']['datei_mb'] ?> MB</td></tr>
<tr><td><?= sp_e(sp_t('EINST.L_LLM')) ?></td>
    <td><?= !empty($sp_emp['llm']) ? '<span class="sm-mono">' . sp_e($sp_emp['llm']['modell']) . '</span>' : sp_e(sp_t('DIENST.KEIN_LLM')) ?></td>
    <td><?= !empty($sp_emp['llm']) ? (int) $sp_emp['llm']['datei_mb'] . ' MB' : '&mdash;' ?></td></tr>
</table>
</div>
<?= sp_t('DIENST.KEINE_ZEITEN') ?>
</div>
<?php } } ?>

<h2><?= sp_e(sp_t('DIENST.H_MODELLE')) ?></h2>
<div class="sm-hinweis"><?= sp_t('DIENST.MODELLE_ERKLAERUNG') ?></div>
<form action="index.php" method="post" autocomplete="off">
<?php $sp_hidden('tab-services'); ?>
<input data-role="none" type="hidden" name="modelle_speichern" value="1">
<?php
/* Auswahllisten aus templates/modelle.json statt Freitext. Bis 0.9.11 war
 * das drei Freitextfelder; ein Vertipper wurde gespeichert (die Muster
 * pruefen nur den Zeichenvorrat) und schlug erst als Docker-Fehler beim
 * Anlegen des Containers auf. */
$sp_modellfelder = array(
    'whisper_modell' => array('DIENST.L_WHISPER_MODELL', 'whisper', 'modell'),
    'piper_stimme'   => array('DIENST.L_PIPER_STIMME',   'piper',   'stimme'),
    'llm_modell'     => array('DIENST.L_LLM_MODELL',     'llm',     'quelle'),
);
foreach ($sp_modellfelder as $sp_feld => $sp_info) {
    $sp_werte = array();
    foreach ((array) $sp_modelle['stufen'] as $sp_st) {
        if (!empty($sp_st[$sp_info[1]][$sp_info[2]])) {
            $sp_werte[] = (string) $sp_st[$sp_info[1]][$sp_info[2]];
        }
    }
    $sp_werte = array_values(array_unique($sp_werte));
    $sp_ist = (string) $sp_cfg[$sp_feld];
?>
<div class="sm-feld">
  <label for="<?= $sp_feld ?>"><?= sp_e(sp_t($sp_info[0])) ?></label>
  <select data-role="none" id="<?= $sp_feld ?>" name="<?= $sp_feld ?>">
    <option value=""><?= sp_e(sp_t('DIENST.VORSCHLAG_NEHMEN')) ?></option>
<?php   foreach ($sp_werte as $sp_w) { ?>
    <option value="<?= sp_e($sp_w) ?>"<?= $sp_ist === $sp_w ? ' selected' : '' ?>><?= sp_e($sp_w) ?></option>
<?php   }
        if ($sp_ist !== '' && !in_array($sp_ist, $sp_werte, true)) { ?>
    <option value="<?= sp_e($sp_ist) ?>" selected><?= sp_e($sp_ist) ?></option>
<?php   } ?>
  </select>
  <input data-role="none" type="text" name="<?= $sp_feld ?>_frei" value="" placeholder="<?= sp_e(sp_t('ALLG.EIGENER_WERT_H')) ?>" style="margin-top:6px;">
</div>
<?php } ?>
<div class="sm-hilfe"><?= sp_t('DIENST.H_LLM_MODELL') ?></div>
<div class="sm-legende"><span><i class="sm-punkt sm-b-aktion"></i> <?= sp_t('LEGENDE.AKTION') ?></span></div>
<div class="sm-knopfreihe">
  <button data-role="none" class="sm-btn sm-b-aktion" type="submit"><?= sp_e(sp_t('ALLG.SPEICHERN')) ?></button>
</div>
</form>
<div class="sm-hilfe"><?= sp_t('DIENST.MODELLE_LEER') ?></div>

<h2><?= sp_e(sp_t('DIENST.H_CONTAINER')) ?></h2>
<?php if (!sp_docker_da()) { ?>
<div class="sm-fehler"><?= sp_t('DIENST.KEIN_DOCKER') ?></div>
<?php } ?>
<div class="sm-warnung"><?= sp_t('DIENST.MODELL_WARNUNG') ?></div>
<div class="sm-legende">
<span><i class="sm-punkt sm-b-lesen"></i><?= sp_t('LEGENDE.LESEN_START') ?></span>
<span><i class="sm-punkt sm-b-technik"></i><?= sp_t('LEGENDE.TECHNIK') ?></span>
<span><i class="sm-punkt sm-b-aktion"></i><?= sp_t('LEGENDE.AKTION') ?></span>
</div>
<?php foreach (sp_dienste() as $sp_d) {
    list($sp_dhost, $sp_dport) = sp_dienst_ziel($sp_d, $sp_cfg);
    $sp_ext = !sp_ist_lokal($sp_dhost);
    $sp_zu = sp_container_zustand($sp_d, $sp_cfg);
    $sp_bef = sp_container_befehl($sp_d, $sp_cfg, $sp_emp ?: null, $sp_ext); ?>
<h3><?= sp_e(sp_t('EINST.L_' . strtoupper($sp_d === 'wakeword' ? 'WAKE' : $sp_d))) ?>
    <span class="<?= ($sp_zu === 'laeuft' || $sp_zu === 'extern') ? 'sm-an' : 'sm-aus' ?>">&mdash; <?= sp_e(sp_t('ALLG.CONT_' . strtoupper($sp_zu))) ?></span>
    <span class="sm-mono">(<?= sp_e($sp_dhost . ':' . $sp_dport) ?>)</span></h3>
<p class="sm-hilfe"><?= sp_t($sp_modelle['dienste'][$sp_d]['text']) ?></p>
<?php if ($sp_ext) { ?>
<div class="sm-hinweis"><?= sprintf(sp_t('DIENST.AUSGELAGERT'), sp_e($sp_dhost . ':' . $sp_dport)) ?></div>
<?php if ($sp_bef !== '') { ?>
<p><span class="sm-mono">docker <?= sp_e($sp_bef) ?></span></p>
<?php } ?>
<div class="sm-knopfreihe">
  <form action="index.php" method="post">
    <?php $sp_hidden('tab-services'); ?>
    <button data-role="none" class="sm-btn sm-b-technik" type="submit" name="containerlog" value="<?= $sp_d ?>"><?= sp_e(sp_t('DIENST.KC_LOG')) ?></button>
  </form>
</div>
<?php } else { ?>
<?php if ($sp_bef !== '') { ?>
<p><span class="sm-mono">docker <?= sp_e($sp_bef) ?></span></p>
<?php } else { ?>
<div class="sm-hinweis"><?= sp_t('DIENST.KEIN_BEFEHL') ?></div>
<?php } ?>
<div class="sm-knopfreihe">
  <form action="index.php" method="post">
    <?php $sp_hidden('tab-services'); ?>
    <input data-role="none" type="hidden" name="dienstname" value="<?= $sp_d ?>">
    <button data-role="none" class="sm-btn sm-b-lesen" type="submit" name="container" value="anlegen"><?= sp_e(sp_t('DIENST.KC_ANLEGEN')) ?></button>
  </form>
  <form action="index.php" method="post">
    <?php $sp_hidden('tab-services'); ?>
    <input data-role="none" type="hidden" name="dienstname" value="<?= $sp_d ?>">
    <button data-role="none" class="sm-btn sm-b-lesen" type="submit" name="container" value="start"><?= sp_e(sp_t('DIENST.KC_START')) ?></button>
  </form>
  <form action="index.php" method="post">
    <?php $sp_hidden('tab-services'); ?>
    <input data-role="none" type="hidden" name="dienstname" value="<?= $sp_d ?>">
    <button data-role="none" class="sm-btn sm-b-technik" type="submit" name="container" value="holen"><?= sp_e(sp_t('DIENST.KC_HOLEN')) ?></button>
  </form>
  <form action="index.php" method="post">
    <?php $sp_hidden('tab-services'); ?>
    <input data-role="none" type="hidden" name="dienstname" value="<?= $sp_d ?>">
    <button data-role="none" class="sm-btn sm-b-aktion" type="submit" name="container" value="restart"><?= sp_e(sp_t('DIENST.KC_RESTART')) ?></button>
  </form>
  <form action="index.php" method="post">
    <?php $sp_hidden('tab-services'); ?>
    <input data-role="none" type="hidden" name="dienstname" value="<?= $sp_d ?>">
    <button data-role="none" class="sm-btn sm-b-aktion" type="submit" name="container" value="stop"><?= sp_e(sp_t('DIENST.KC_STOP')) ?></button>
  </form>
  <form action="index.php" method="post">
    <?php $sp_hidden('tab-services'); ?>
    <input data-role="none" type="hidden" name="dienstname" value="<?= $sp_d ?>">
    <button data-role="none" class="sm-btn sm-b-aktion" type="submit" name="container" value="entfernen"><?= sp_e(sp_t('DIENST.KC_ENTFERNEN')) ?></button>
  </form>
  <form action="index.php" method="post">
    <?php $sp_hidden('tab-services'); ?>
    <button data-role="none" class="sm-btn sm-b-technik" type="submit" name="containerlog" value="<?= $sp_d ?>"><?= sp_e(sp_t('DIENST.KC_LOG')) ?></button>
  </form>
</div>
<?php } ?>
<?php } ?>

<h2><?= sp_e(sp_t('DIENST.H_MESSEN')) ?></h2>
<div class="sm-hinweis"><?= sp_t('DIENST.MESSEN_ERKLAERUNG') ?></div>
<div class="sm-legende"><span><i class="sm-punkt sm-b-technik"></i> <?= sp_t('LEGENDE.TECHNIK') ?></span></div>
<div class="sm-knopfreihe">
  <form action="index.php" method="post">
    <?php $sp_hidden('tab-services'); ?>
    <button data-role="none" class="sm-btn sm-b-technik" type="submit" name="messen" value="1"><?= sp_e(sp_t('DIENST.K_MESSEN')) ?></button>
  </form>
</div>
<?php $sp_mw = sp_messwerte(); if ($sp_mw) { ?>
<h3><?= sp_e(sp_t('DIENST.H_MESSVERLAUF')) ?></h3>
<p class="sm-hilfe"><?= sp_t('DIENST.MESSVERLAUF_ERKLAERUNG') ?></p>
<div class="sm-roll">
<table class="sm-tbl">
<tr><th><?= sp_e(sp_t('TEST.T_ZEIT')) ?></th><th>Whisper</th><th>Piper</th><th><?= sp_e(sp_t('EINST.L_WAKE')) ?></th><th><?= sp_e(sp_t('EINST.L_LLM')) ?></th></tr>
<?php foreach (array_slice($sp_mw, 0, 8) as $sp_m2) {
    $sp_ms = isset($sp_m2['messung']) ? $sp_m2['messung'] : array();
    $sp_z = function ($k) use ($sp_ms) {
        if (!isset($sp_ms[$k])) { return '&mdash;'; }
        return !empty($sp_ms[$k]['ok'])
            ? number_format((float) $sp_ms[$k]['sekunden'], 2, ',', '.') . ' s'
            : '<span class="sm-aus">&#10008;</span>';
    }; ?>
<tr><td><?= sp_e(date('d.m. H:i', (int) $sp_m2['ts'])) ?></td>
    <td><?= $sp_z('whisper') ?></td><td><?= $sp_z('piper') ?></td>
    <td><?= $sp_z('wakeword') ?></td><td><?= $sp_z('llm') ?></td></tr>
<?php } ?>
</table>
</div>
<?php } ?>
<?php if ($sp_ausgabe !== '' && $sp_tab === 'tab-services') { ?>
<div class="sm-pre"><?= sp_e($sp_ausgabe) ?></div>
<?php } ?>
<?php } ?>
</div>

<!-- ================= Reiter: Mikrofone ================= -->
<div class="sm-seite<?= $sp_tab === 'tab-mics' ? ' sm-active' : '' ?>" id="tab-mics">
<h2><?= sp_e(sp_t('MIKRO.H_TITEL')) ?></h2>
<div class="sm-hinweis"><?= sp_t('MIKRO.ERKLAERUNG') ?></div>
<div class="sm-hinweis"><?= sp_t('MIKRO.RAUM_ERKLAERUNG') ?></div>
<form action="index.php" method="post" autocomplete="off">
<?php $sp_hidden('tab-mics'); ?>
<input data-role="none" type="hidden" name="mikros_speichern" value="1">
<div class="sm-roll">
<table class="sm-tbl">
<tr><th style="width:28px;">#</th><th><?= sp_e(sp_t('MIKRO.T_NAME')) ?></th>
    <th style="width:110px;"><?= sp_e(sp_t('MIKRO.T_ART')) ?></th>
    <th><?= sp_e(sp_t('MIKRO.T_HOST')) ?></th><th style="width:70px;"><?= sp_e(sp_t('MIKRO.T_PORT')) ?></th>
    <th><?= sp_e(sp_t('MIKRO.T_RAUM')) ?></th><th style="width:70px;"><?= sp_e(sp_t('MIKRO.T_ZONE')) ?></th>
    <th><?= sp_e(sp_t('MIKRO.T_SCHLUESSEL')) ?></th><th><?= sp_e(sp_t('MIKRO.T_ZUSTAND')) ?></th></tr>
<?php
$sp_liste = isset($sp_cfg['satelliten']) && is_array($sp_cfg['satelliten']) ? $sp_cfg['satelliten'] : array();
for ($sp_i = 0; $sp_i < 8; $sp_i++) {
    $sp_z = isset($sp_liste[$sp_i]) && is_array($sp_liste[$sp_i]) ? $sp_liste[$sp_i] : array();
    $sp_v = function ($k) use ($sp_z) { return isset($sp_z[$k]) ? (string) $sp_z[$k] : ''; };
    $sp_name = $sp_v('name');
    $sp_zust = isset($sp_sats[$sp_name]['zustand']) ? $sp_sats[$sp_name]['zustand'] : '';
?>
<tr><td><?= $sp_i + 1 ?></td>
<td><input data-role="none" type="text" name="m_name[]" value="<?= sp_e($sp_name) ?>" size="12"></td>
<td><select data-role="none" name="m_art[]">
    <option value="wyoming"<?= $sp_v('art') !== 'esphome' ? ' selected' : '' ?>>Wyoming</option>
    <option value="esphome"<?= $sp_v('art') === 'esphome' ? ' selected' : '' ?>>ESPHome</option>
</select></td>
<td><input data-role="none" type="text" name="m_host[]" value="<?= sp_e($sp_v('host')) ?>" size="14" placeholder="<?= $sp_i === 0 ? '192.168.1.60' : '' ?>"></td>
<td><input data-role="none" type="text" name="m_port[]" value="<?= sp_e($sp_v('port')) ?>" size="5"></td>
<td><input data-role="none" type="text" name="m_raum[]" value="<?= sp_e($sp_v('raum')) ?>" size="12" placeholder="<?= $sp_i === 0 ? 'wohnzimmer' : '' ?>"></td>
<td><input data-role="none" type="text" name="m_zone[]" value="<?= sp_e($sp_v('zone')) ?>" size="5"></td>
<td><input data-role="none" type="password" name="m_schluessel[]" value=""
    placeholder="<?= $sp_v('schluessel') !== '' ? sp_e(sp_t('MIKRO.SCHLUESSEL_DA')) : sp_e(sp_t('MIKRO.SCHLUESSEL_LEER')) ?>" size="12"></td>
<td class="<?= $sp_zust === 'getrennt' || $sp_zust === '' ? 'sm-aus' : 'sm-an' ?>"><?= $sp_zust !== '' ? sp_e($sp_zust) : '&mdash;' ?></td></tr>
<?php } ?>
</table>
</div>
<div class="sm-legende">
<span><i class="sm-punkt sm-b-aktion"></i><?= sp_t('LEGENDE.AKTION') ?></span>
</div>
<div class="sm-knopfreihe">
  <button data-role="none" class="sm-btn sm-b-aktion" type="submit"><?= sp_e(sp_t('ALLG.SPEICHERN')) ?></button>
</div>
</form>
<div class="sm-hilfe"><?= sp_t('MIKRO.HILFE') ?></div>

<?php if ($sp_liste) { ?>
<h3><?= sp_e(sp_t('MIKRO.H_PRUEFEN')) ?></h3>
<p class="sm-hilfe"><?= sp_t('MIKRO.PRUEFEN_ERKLAERUNG') ?></p>
<div class="sm-legende"><span><i class="sm-punkt sm-b-lesen"></i> <?= sp_t('LEGENDE.LESEN') ?></span></div>
<div class="sm-knopfreihe">
<?php foreach ($sp_liste as $sp_i2 => $sp_s2) { if (!is_array($sp_s2)) { continue; } ?>
  <form action="index.php" method="post">
    <?php $sp_hidden('tab-mics'); ?>
    <button data-role="none" class="sm-btn sm-b-lesen" type="submit" name="mikro_pruefen" value="<?= (int) $sp_i2 ?>"><?= sprintf(sp_t('MIKRO.K_PRUEFEN'), sp_e((string) $sp_s2['name'])) ?></button>
  </form>
<?php } ?>
</div>
<?php } ?>

<div class="sm-warnung"><?= sp_t('MIKRO.ESPHOME_WARNUNG') ?></div>

<h2><?= sp_e(sp_t('MIKRO.H_WELCHE')) ?></h2>
<div class="sm-roll">
<table class="sm-tbl">
<tr><th><?= sp_e(sp_t('MIKRO.T_GERAET')) ?></th><th><?= sp_e(sp_t('MIKRO.T_ART')) ?></th><th><?= sp_e(sp_t('MIKRO.T_HINWEIS')) ?></th></tr>
<tr><td>Raspberry Pi Zero 2 W, Pi 3/4/5 mit USB-Mikrofon</td><td>Wyoming</td><td><?= sp_t('MIKRO.G_PI') ?></td></tr>
<tr><td>ReSpeaker 2-Mic / 4-Mic HAT</td><td>Wyoming</td><td><?= sp_t('MIKRO.G_RESPEAKER') ?></td></tr>
<tr><td>M5Stack Atom Echo</td><td>ESPHome</td><td><?= sp_t('MIKRO.G_ATOM') ?></td></tr>
<tr><td>Home Assistant Voice Preview Edition</td><td>ESPHome</td><td><?= sp_t('MIKRO.G_VOICEPE') ?></td></tr>
<tr><td>ESP32-S3-BOX / BOX-3</td><td>ESPHome</td><td><?= sp_t('MIKRO.G_BOX') ?></td></tr>
<tr><td><?= sp_t('MIKRO.G_LINUX_NAME') ?></td><td>Wyoming</td><td><?= sp_t('MIKRO.G_LINUX') ?></td></tr>
</table>
</div>
</div>

<!-- ================= Reiter: Saetze ================= -->
<div class="sm-seite<?= $sp_tab === 'tab-sentences' ? ' sm-active' : '' ?>" id="tab-sentences">
<h2><?= sp_e(sp_t('SATZ.H_ZIELE')) ?></h2>
<div class="sm-hinweis"><?= sp_t('SATZ.ZIELE_ERKLAERUNG') ?></div>
<form action="index.php" method="post" autocomplete="off">
<?php $sp_hidden('tab-sentences'); ?>
<input data-role="none" type="hidden" name="ziele_speichern" value="1">
<div class="sm-roll">
<table class="sm-tbl">
<tr><th><?= sp_e(sp_t('SATZ.T_SCHLUESSEL')) ?></th><th><?= sp_e(sp_t('SATZ.T_NAME')) ?></th>
    <th><?= sp_e(sp_t('SATZ.T_ALIAS')) ?></th><th><?= sp_e(sp_t('SATZ.T_THEMA')) ?></th>
    <th style="width:70px;"><?= sp_e(sp_t('SATZ.T_EINHEIT')) ?></th>
    <th><?= sp_e(sp_t('SATZ.T_LESEN')) ?></th>
    <th style="width:60px;"><?= sp_e(sp_t('SATZ.T_BESTAETIGEN')) ?></th></tr>
<?php
$sp_zliste = isset($sp_saetze['ziele']) && is_array($sp_saetze['ziele']) ? $sp_saetze['ziele'] : array();
$sp_zi = 0;
$sp_zeile = function ($i, $k, $z) {
    $hol = function ($f) use ($z) {
        if (!is_array($z)) { return $f === 'thema' ? (string) $z : ''; }
        return isset($z[$f]) ? (string) $z[$f] : '';
    };
    $alias = is_array($z) && isset($z['alias']) ? implode(', ', (array) $z['alias']) : '';
    $best = is_array($z) && !empty($z['bestaetigen']);
    ?>
<tr><td><input data-role="none" type="text" name="z_key[]" value="<?= sp_e($k) ?>" size="14"></td>
    <td><input data-role="none" type="text" name="z_name[]" value="<?= sp_e($hol('name')) ?>" size="16"></td>
    <td><input data-role="none" type="text" name="z_alias[]" value="<?= sp_e($alias) ?>" size="24"></td>
    <td><input data-role="none" type="text" name="z_thema[]" value="<?= sp_e($hol('thema')) ?>" size="16"></td>
    <td><input data-role="none" type="text" name="z_einheit[]" value="<?= sp_e($hol('einheit')) ?>" size="5"></td>
    <td><input data-role="none" type="text" name="z_lesen[]" value="<?= sp_e($hol('url_lesen')) ?>" size="24"></td>
    <td style="text-align:center;"><input data-role="none" type="checkbox" name="z_bestaetigen[<?= (int) $i ?>]" value="1" <?= $best ? 'checked' : '' ?>></td></tr>
<?php };
foreach ($sp_zliste as $sp_k => $sp_z) { $sp_zeile($sp_zi, (string) $sp_k, $sp_z); $sp_zi++; }
for ($sp_j = 0; $sp_j < 3; $sp_j++) { $sp_zeile($sp_zi, '', array()); $sp_zi++; }
?>
</table>
</div>
<div class="sm-hilfe"><?= sp_t('SATZ.ZIELE_HILFE') ?></div>
<div class="sm-legende"><span><i class="sm-punkt sm-b-aktion"></i> <?= sp_t('LEGENDE.AKTION') ?></span></div>
<div class="sm-knopfreihe">
  <button data-role="none" class="sm-btn sm-b-aktion" type="submit"><?= sp_e(sp_t('SATZ.K_ZIELE_SPEICHERN')) ?></button>
</div>
</form>

<h2><?= sp_e(sp_t('SATZ.H_TITEL')) ?></h2>
<div class="sm-hinweis"><?= sp_t('SATZ.ERKLAERUNG') ?></div>
<div class="sm-roll">
<table class="sm-tbl">
<tr><th><?= sp_e(sp_t('SATZ.T_ZEICHEN')) ?></th><th><?= sp_e(sp_t('SATZ.T_BEDEUTUNG')) ?></th><th><?= sp_e(sp_t('SATZ.T_BEISPIEL')) ?></th></tr>
<tr><td><span class="sm-mono">{ziel}</span></td><td><?= sp_t('SATZ.B_ZIEL') ?></td><td><span class="sm-mono">schalte {ziel} ein</span></td></tr>
<tr><td><span class="sm-mono">{wert}</span></td><td><?= sp_t('SATZ.B_WERT') ?></td><td><span class="sm-mono">dimme {ziel} auf {wert}</span></td></tr>
<tr><td><span class="sm-mono">{dauer}</span></td><td><?= sp_t('SATZ.B_DAUER') ?></td><td><span class="sm-mono">mach {ziel} in {dauer} aus</span></td></tr>
<tr><td><span class="sm-mono">{rest}</span></td><td><?= sp_t('SATZ.B_REST') ?></td><td><span class="sm-mono">sag mir {rest}</span></td></tr>
<tr><td><span class="sm-mono">{istwert}</span></td><td><?= sp_t('SATZ.B_ISTWERT') ?></td><td><span class="sm-mono">Es sind {istwert} Grad</span></td></tr>
<tr><td><span class="sm-mono">[a|b]</span></td><td><?= sp_t('SATZ.B_ALT') ?></td><td><span class="sm-mono">[schalte|mach] {ziel} [an|ein]</span></td></tr>
<tr><td><span class="sm-mono">[a|]</span></td><td><?= sp_t('SATZ.B_LEER') ?></td><td><span class="sm-mono">{ziel} auf {wert} [prozent|]</span></td></tr>
</table>
</div>
<div class="sm-warnung"><?= sp_t('SATZ.REIHENFOLGE_WARNUNG') ?></div>

<h3><?= sp_e(sp_t('SATZ.H_BEARBEITEN')) ?></h3>
<div class="sm-warnung"><?= sp_t('SATZ.BEARBEITEN_WARNUNG') ?></div>
<form action="index.php" method="post">
<?php $sp_hidden('tab-sentences'); ?>
<input data-role="none" type="hidden" name="saetze_speichern" value="1">
<div class="sm-feld">
  <textarea data-role="none" name="saetze" rows="20" style="width:100%;font-family:Consolas,monospace;font-size:0.86em;"><?= sp_e(json_encode($sp_saetze, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?></textarea>
</div>
<div class="sm-legende">
<span><i class="sm-punkt sm-b-aktion"></i><?= sp_t('LEGENDE.AKTION') ?></span>
</div>
<div class="sm-knopfreihe">
  <button data-role="none" class="sm-btn sm-b-aktion" type="submit"><?= sp_e(sp_t('ALLG.SPEICHERN')) ?></button>
</div>
</form>
<div class="sm-hilfe"><?= sp_t('SATZ.NEU_LADEN') ?></div>

<h2><?= sp_e(sp_t('LOXIMP.H_TITEL')) ?></h2>
<div class="sm-hinweis"><?= sp_t('LOXIMP.ERKLAERUNG') ?></div>
<?php $sp_lvor = sp_lox_vorschlaege(); ?>
<?php if (!$sp_lvor) { ?>
<form action="index.php" method="post" autocomplete="off">
<?php $sp_hidden('tab-sentences'); ?>
<div class="sm-feld">
  <label for="lox_host"><?= sp_e(sp_t('LOXIMP.L_HOST')) ?></label>
  <input data-role="none" type="text" id="lox_host" name="lox_host" value="" placeholder="192.168.1.5">
</div>
<div class="sm-feld">
  <label for="lox_benutzer"><?= sp_e(sp_t('LOXIMP.L_BENUTZER')) ?></label>
  <input data-role="none" type="text" id="lox_benutzer" name="lox_benutzer" value="" autocomplete="off">
</div>
<div class="sm-feld">
  <label for="lox_kennwort"><?= sp_e(sp_t('LOXIMP.L_KENNWORT')) ?></label>
  <input data-role="none" type="password" id="lox_kennwort" name="lox_kennwort" value="" autocomplete="new-password">
  <div class="sm-hilfe"><?= sp_t('LOXIMP.H_KENNWORT') ?></div>
</div>
<div class="sm-legende"><span><i class="sm-punkt sm-b-lesen"></i> <?= sp_t('LEGENDE.LESEN') ?></span></div>
<div class="sm-knopfreihe">
  <button data-role="none" class="sm-btn sm-b-lesen" type="submit" name="lox_holen" value="1"><?= sp_e(sp_t('LOXIMP.K_HOLEN')) ?></button>
</div>
</form>
<?php } else { ?>
<div class="sm-warnung"><?= sp_t('LOXIMP.PRUEFEN') ?></div>
<form action="index.php" method="post">
<?php $sp_hidden('tab-sentences'); ?>
<div class="sm-roll">
<table class="sm-tbl">
<tr><th style="width:40px;">&nbsp;</th><th><?= sp_e(sp_t('SATZ.T_SCHLUESSEL')) ?></th>
    <th><?= sp_e(sp_t('SATZ.T_NAME')) ?></th><th><?= sp_e(sp_t('SATZ.T_ALIAS')) ?></th>
    <th><?= sp_e(sp_t('SATZ.T_THEMA')) ?></th><th><?= sp_e(sp_t('LOXIMP.T_ART')) ?></th></tr>
<?php
$sp_zvorhanden = isset($sp_saetze['ziele']) && is_array($sp_saetze['ziele']) ? $sp_saetze['ziele'] : array();
foreach ($sp_lvor as $sp_k4 => $sp_v4) {
    $sp_da = isset($sp_zvorhanden[$sp_k4]); ?>
<tr><td style="text-align:center;"><?php if ($sp_da) { echo '&mdash;'; } else { ?>
    <input data-role="none" type="checkbox" name="lox_ziel[]" value="<?= sp_e((string) $sp_k4) ?>" checked><?php } ?></td>
    <td><span class="sm-mono"><?= sp_e((string) $sp_k4) ?></span></td>
    <td><?= sp_e((string) $sp_v4['name']) ?></td>
    <td><?= sp_e(implode(', ', (array) $sp_v4['alias'])) ?></td>
    <td><span class="sm-mono"><?= sp_e((string) $sp_v4['thema']) ?></span></td>
    <td><?= sp_e((string) $sp_v4['art']) ?><?= $sp_da ? ' &mdash; ' . sp_e(sp_t('LOXIMP.SCHON_DA')) : '' ?></td></tr>
<?php } ?>
</table>
</div>
<div class="sm-legende"><span><i class="sm-punkt sm-b-aktion"></i> <?= sp_t('LEGENDE.AKTION') ?></span></div>
<div class="sm-knopfreihe">
  <button data-role="none" class="sm-btn sm-b-aktion" type="submit" name="lox_uebernehmen" value="1"><?= sp_e(sp_t('LOXIMP.K_UEBERNEHMEN')) ?></button>
  <button data-role="none" class="sm-btn sm-b-aktion" type="submit" name="lox_verwerfen" value="1"><?= sp_e(sp_t('LOXIMP.K_VERWERFEN')) ?></button>
</div>
</form>
<?php } ?>

<h2><?= sp_e(sp_t('SICHER.H_TITEL')) ?></h2>
<div class="sm-hinweis"><?= sp_t('SICHER.ERKLAERUNG') ?></div>
<div class="sm-legende">
<span><i class="sm-punkt sm-b-lesen"></i> <?= sp_t('LEGENDE.LESEN') ?></span>
<span><i class="sm-punkt sm-b-aktion"></i> <?= sp_t('LEGENDE.AKTION') ?></span>
</div>
<div class="sm-knopfreihe">
  <form action="index.php" method="post">
    <?php $sp_hidden('tab-sentences'); ?>
    <button data-role="none" class="sm-btn sm-b-lesen" type="submit" name="sicherung_holen" value="1"><?= sp_e(sp_t('SICHER.K_HOLEN')) ?></button>
  </form>
</div>
<form action="index.php" method="post" enctype="multipart/form-data">
<?php $sp_hidden('tab-sentences'); ?>
<div class="sm-feld">
  <label for="sicherungsdatei"><?= sp_e(sp_t('SICHER.L_DATEI')) ?></label>
  <input data-role="none" type="file" id="sicherungsdatei" name="sicherungsdatei" accept=".json,application/json">
</div>
<div class="sm-knopfreihe">
  <button data-role="none" class="sm-btn sm-b-aktion" type="submit" name="sicherung_einspielen" value="1"><?= sp_e(sp_t('SICHER.K_EINSPIELEN')) ?></button>
</div>
</form>
</div>

<!-- ================= Reiter: Einbindung in Loxone ================= -->
<div class="sm-seite<?= $sp_tab === 'tab-loxone' ? ' sm-active' : '' ?>" id="tab-loxone">
<h2><?= sp_e(sp_t('LOX.H_TITEL')) ?></h2>
<p><?= sp_t('LOX.EINLEITUNG') ?></p>

<div class="sm-step"><b><?= sp_e(sp_t('LOX.S1_TITEL')) ?></b><br>
<?= sp_t('LOX.S1_TEXT') ?>
</div>

<div class="sm-step"><b><?= sp_e(sp_t('LOX.S3_TITEL')) ?></b><br>
<?= sp_t('LOX.S3_TEXT') ?>
<div class="sm-roll">
<table class="sm-tbl">
<tr><th><?= sp_e(sp_t('LOX.T_ADRESSE')) ?></th>
    <td colspan="3"><span class="sm-mono"><?= sp_e($sp_basis) ?>?token=<?= sp_e($sp_token) ?>&amp;aktion=status</span></td></tr>
<tr><th><?= sp_e(sp_t('LOX.T_TITEL')) ?></th><th><?= sp_e(sp_t('LOX.T_BEFEHL')) ?></th>
    <th><?= sp_e(sp_t('LOX.T_GRENZEN')) ?></th><th><?= sp_e(sp_t('LOX.T_BEDEUTUNG')) ?></th></tr>
<?php foreach (sp_status_felder() as $sp_feld => $sp_info) { ?>
<tr><td><span class="sm-mono">SPRACHSTEUERUNG_<?= sp_e($sp_feld) ?></span></td>
    <td><span class="sm-mono"><?= sp_e(sp_check($sp_feld)) ?></span></td>
    <td><span class="sm-mono"><?= (int) $sp_info[2] ?> &hellip; <?= (int) $sp_info[3] ?></span><?= $sp_info[0] !== '' ? ' ' . sp_e($sp_info[0]) : '' ?></td>
    <td><?= sp_t($sp_info[1]) ?></td></tr>
<?php } ?>
</table>
</div>
<div class="sm-warnung"><?= sp_t('LOX.IMPORT_WARNUNG') ?></div>
<div class="sm-legende"><span><i class="sm-punkt sm-b-lesen"></i> <?= sp_t('LEGENDE.LESEN') ?></span></div>
<div class="sm-knopfreihe">
  <form action="index.php" method="post">
    <?php $sp_hidden('tab-loxone'); ?>
    <button data-role="none" class="sm-btn sm-b-lesen" type="submit" name="vorlage" value="eingang"><?= sp_e(sp_t('LOX.K_VORLAGE')) ?></button>
  </form>
  <form action="index.php" method="post">
    <?php $sp_hidden('tab-loxone'); ?>
    <button data-role="none" class="sm-btn sm-b-lesen" type="submit" name="vorlage" value="ziele"><?= sp_e(sp_t('LOX.K_VORLAGE_ZIELE')) ?></button>
  </form>
  <form action="index.php" method="post">
    <?php $sp_hidden('tab-loxone'); ?>
    <button data-role="none" class="sm-btn sm-b-lesen" type="submit" name="vorlage" value="ausgang"><?= sp_e(sp_t('LOX.K_VORLAGE_AUSGANG')) ?></button>
  </form>
</div>
</div>

<div class="sm-step"><b><?= sp_e(sp_t('LOX.S4_TITEL')) ?></b><br>
<?= sp_t('LOX.S4_TEXT') ?>
<div class="sm-roll">
<table class="sm-tbl">
<tr><th><?= sp_e(sp_t('ALLG.EIGENSCHAFT')) ?></th><th><?= sp_e(sp_t('ALLG.WERT')) ?></th></tr>
<tr><td><?= sp_e(sp_t('LOX.T_VA_ADRESSE')) ?></td><td><span class="sm-mono">http://<?= sp_e($sp_host) ?></span></td></tr>
<tr><td><?= sp_e(sp_t('LOX.T_VA_ANSAGE')) ?></td>
    <td><span class="sm-mono">/plugins/<?= sp_e($sp_p['plugin']) ?>/index.php?token=<?= sp_e($sp_token) ?>&amp;aktion=sprechen&amp;text=Das%20Garagentor%20steht%20offen</span></td></tr>
<tr><td><?= sp_e(sp_t('LOX.T_VA_ZONE')) ?></td>
    <td><span class="sm-mono">&hellip;&amp;aktion=sprechen&amp;text=Guten%20Morgen&amp;zone=4</span></td></tr>
<tr><td><?= sp_e(sp_t('LOX.T_VA_DRINGEND')) ?></td>
    <td><span class="sm-mono">&hellip;&amp;aktion=sprechen&amp;text=Wasseralarm%20im%20Keller&amp;dringend=1</span></td></tr>
<tr><td><?= sp_e(sp_t('LOX.T_VA_SATZ')) ?></td>
    <td><span class="sm-mono">/plugins/<?= sp_e($sp_p['plugin']) ?>/index.php?token=<?= sp_e($sp_token) ?>&amp;aktion=satz&amp;text=schalte%20das%20licht%20im%20wohnzimmer%20aus</span></td></tr>
<tr><td><?= sp_e(sp_t('LOX.T_VA_RUHE')) ?></td>
    <td><span class="sm-mono">/plugins/<?= sp_e($sp_p['plugin']) ?>/index.php?token=<?= sp_e($sp_token) ?>&amp;aktion=ruhe&amp;wert=1</span></td></tr>
</table>
</div>
<?= sp_t('LOX.S4_ANSAGE') ?>
</div>

<div class="sm-step"><b><?= sp_e(sp_t('LOX.S5_TITEL')) ?></b>
<div class="sm-roll">
<table class="sm-tbl">
<tr><th><?= sp_e(sp_t('ALLG.EIGENSCHAFT')) ?></th><th><?= sp_e(sp_t('ALLG.WERT')) ?></th></tr>
<tr><td><?= sp_e(sp_t('LOX.T_TOKEN')) ?></td><td><span class="sm-mono"><?= sp_e($sp_token) ?></span></td></tr>
<tr><td><?= sp_e(sp_t('LOX.T_SELFTEST')) ?></td>
    <td><span class="sm-mono"><?= sp_e($sp_basis) ?>?selftest=1&amp;token=<?= sp_e($sp_token) ?></span></td></tr>
</table>
</div>
<?= sp_t('LOX.S5_TEXT') ?>
<div class="sm-legende"><span><i class="sm-punkt sm-b-aktion"></i> <?= sp_t('LEGENDE.AKTION_TOKEN') ?></span></div>
<div class="sm-knopfreihe">
  <form action="index.php" method="post">
    <?php $sp_hidden('tab-loxone'); ?>
    <button data-role="none" class="sm-btn sm-b-aktion" type="submit" name="token_neu" value="1"><?= sp_e(sp_t('LOX.K_TOKEN_NEU')) ?></button>
  </form>
</div>
</div>

<?php
/**
 * Die komplette Baustein-Liste. Pflicht im Hausstandard.
 */
function sp_bausteine()
{
    return array(
        array(1,  'BAUSTEIN.T_VE',      'BAUSTEIN.N01', 'BAUSTEIN.P01', '&mdash;'),
        array(2,  'BAUSTEIN.T_VE',      'BAUSTEIN.N02', 'BAUSTEIN.P02', '&mdash;'),
        array(3,  'BAUSTEIN.T_VET',     'BAUSTEIN.N03', 'BAUSTEIN.P03', '&mdash;'),
        array(4,  'BAUSTEIN.T_VET',     'BAUSTEIN.N04', 'BAUSTEIN.P04', '&mdash;'),
        array(5,  'BAUSTEIN.T_VET',     'BAUSTEIN.N05', 'BAUSTEIN.P05', '&mdash;'),
        array(6,  'BAUSTEIN.T_VERGL',   'BAUSTEIN.N06', 'BAUSTEIN.P06', 'I1 &larr; #3'),
        array(7,  'BAUSTEIN.T_VERGL',   'BAUSTEIN.N07', 'BAUSTEIN.P07', 'I1 &larr; #3'),
        array(8,  'BAUSTEIN.T_UND',     'BAUSTEIN.N08', '',             'I1 &larr; #6, I2 &larr; #4'),
        array(9,  'BAUSTEIN.T_UND',     'BAUSTEIN.N09', '',             'I1 &larr; #7, I2 &larr; #4'),
        array(10, 'BAUSTEIN.T_LICHT',   'BAUSTEIN.N10', 'BAUSTEIN.P10', 'AI &larr; #8, AUS &larr; #9'),
        array(11, 'BAUSTEIN.T_SWS',     'BAUSTEIN.N11', 'BAUSTEIN.P11', 'I &larr; #2'),
        array(12, 'BAUSTEIN.T_NICHT',   'BAUSTEIN.N12', '',             'I &larr; #1'),
        array(13, 'BAUSTEIN.T_ODER',    'BAUSTEIN.N13', '',             'I1 &larr; #11, I2 &larr; #12'),
        array(14, 'BAUSTEIN.T_EVZ',     'BAUSTEIN.N14', 'BAUSTEIN.P14', 'I &larr; #13'),
        array(15, 'BAUSTEIN.T_BENACHR', 'BAUSTEIN.N15', 'BAUSTEIN.P15', 'I &larr; #14'),
        array(16, 'BAUSTEIN.T_VA',      'BAUSTEIN.N16', 'BAUSTEIN.P16', 'I &larr; ' . sp_t('BAUSTEIN.EREIGNIS')),
        array(17, 'BAUSTEIN.T_VA',      'BAUSTEIN.N17', 'BAUSTEIN.P17', 'I &larr; ' . sp_t('BAUSTEIN.NACHTS')),
    );
}
?>
<div class="sm-step"><b><?= sp_e(sp_t('LOX.S6_TITEL')) ?></b><br>
<?= sp_t('LOX.S6_TEXT') ?>
<div class="sm-roll">
<table class="sm-tbl">
<tr><th>#</th><th><?= sp_e(sp_t('LOX.T_BAUSTEIN')) ?></th><th><?= sp_e(sp_t('LOX.T_NAMENSVORSCHLAG')) ?></th>
    <th><?= sp_e(sp_t('LOX.T_PARAMETER')) ?></th><th><?= sp_e(sp_t('LOX.T_EINGAENGE')) ?></th></tr>
<?php foreach (sp_bausteine() as $sp_b) { ?>
<tr><td><?= (int) $sp_b[0] ?></td><td><?= sp_t($sp_b[1]) ?></td><td><?= sp_t($sp_b[2]) ?></td>
    <td><?= $sp_b[3] !== '' ? sp_t($sp_b[3]) : '&mdash;' ?></td><td><?= $sp_b[4] ?></td></tr>
<?php } ?>
</table>
</div>
<?= sp_t('LOX.S6_ERLAEUTERUNG') ?>
</div>

<div class="sm-step"><b><?= sp_e(sp_t('LOX.S7_TITEL')) ?></b><br>
<?= sp_t('LOX.S7_TEXT') ?>
<div class="sm-roll">
<table class="sm-tbl">
<tr><th><?= sp_e(sp_t('LOX.T_PRUEFUNG')) ?></th><th><?= sp_e(sp_t('LOX.T_ERWARTUNG')) ?></th></tr>
<tr><td><span class="sm-mono"><?= sp_e($sp_basis) ?>?selftest=1&amp;token=<?= sp_e($sp_token) ?></span></td>
    <td><span class="sm-mono">SELFTEST;OK=1;TOKEN=OK</span></td></tr>
<tr><td><span class="sm-mono"><?= sp_e($sp_basis) ?>?token=<?= sp_e($sp_token) ?>&amp;aktion=status</span></td>
    <td><span class="sm-mono">SPRACHSTEUERUNG;OK=1;MIKROFONE=...</span></td></tr>
<tr><td><span class="sm-mono"><?= sp_e($sp_basis) ?>?aktion=status</span></td>
    <td><span class="sm-mono">FEHLER;OK=0;GRUND=TOKEN</span> (HTTP 403)</td></tr>
<tr><td><span class="sm-mono"><?= sp_e($sp_basis) ?>?token=<?= sp_e($sp_token) ?>&amp;aktion=quatsch</span></td>
    <td><span class="sm-mono">FEHLER;OK=0;GRUND=UNBEKANNTE_AKTION</span> (HTTP 400)</td></tr>
<tr><td><span class="sm-mono"><?= sp_e($sp_basis) ?>?token=<?= sp_e($sp_token) ?>&amp;aktion=diag</span></td>
    <td><?= sp_t('LOX.A_DIAG') ?></td></tr>
</table>
</div>
</div>
</div>

<!-- ================= Reiter: Test ================= -->
<div class="sm-seite<?= $sp_tab === 'tab-test' ? ' sm-active' : '' ?>" id="tab-test">
<h2><?= sp_e(sp_t('TEST.H_SELBSTPRUEFUNG')) ?></h2>
<p class="sm-hilfe"><?= sp_t('TEST.EINLEITUNG') ?></p>
<?php if (!$sp_offen('tab-test')) { ?>
<div class="sm-hinweis"><?= sp_t('TEST.ERST_OEFFNEN') ?>
<a href="index.php?form=test"><?= sp_e(sp_t('TEST.K_JETZT_PRUEFEN')) ?></a></div>
<?php } else { ?>
<div class="sm-roll">
<table class="sm-tbl">
<tr><th style="width:36px;">&nbsp;</th><th><?= sp_e(sp_t('TEST.T_FRAGE')) ?></th><th><?= sp_e(sp_t('TEST.T_BEFUND')) ?></th></tr>
<?php foreach (sp_pruefungen() as $sp_z) { ?>
<tr><td style="text-align:center;"><?php
    if ($sp_z['stand'] === 1) { echo '<span class="sm-an">&#10004;</span>'; }
    elseif ($sp_z['stand'] === 0) { echo '<span class="sm-aus">&#10008;</span>'; }
    else { echo '<span style="color:#888;">&#9679;</span>'; }
?></td><td><?= $sp_z['frage'] ?></td><td><?= $sp_z['antwort'] ?></td></tr>
<?php } ?>
</table>
</div>
<?php } ?>

<h2><?= sp_e(sp_t('TEST.H_VERLAUF')) ?></h2>
<p class="sm-hilfe"><?= sp_t('TEST.VERLAUF_ERKLAERUNG') ?></p>
<?php if (!$sp_verlauf) { ?>
<div class="sm-hinweis"><?= sp_t('TEST.KEIN_VERLAUF') ?></div>
<?php } else { ?>
<div class="sm-roll">
<table class="sm-tbl">
<tr><th><?= sp_e(sp_t('TEST.T_ZEIT')) ?></th><th><?= sp_e(sp_t('TEST.T_VERSTANDEN')) ?></th>
    <th><?= sp_e(sp_t('TEST.T_MIKROFON')) ?></th>
    <th><?= sp_e(sp_t('TEST.T_ABSICHT')) ?></th><th><?= sp_e(sp_t('TEST.T_QUELLE')) ?></th>
    <th><?= sp_e(sp_t('TEST.T_ANTWORT')) ?></th></tr>
<?php foreach (array_slice($sp_verlauf, 0, 20) as $sp_v2) { ?>
<tr><td><?= sp_e(date('H:i:s', (int) (isset($sp_v2['ts']) ? $sp_v2['ts'] : 0))) ?></td>
    <td><span class="sm-mono"><?= sp_e((string) (isset($sp_v2['satz']) ? $sp_v2['satz'] : '')) ?></span></td>
    <td><?= sp_e((string) (isset($sp_v2['mikrofon']) ? $sp_v2['mikrofon'] : '')) ?></td>
    <td><?= !empty($sp_v2['ok']) ? sp_e((string) $sp_v2['absicht'] . '/' . (string) $sp_v2['aktion']) : '<span class="sm-aus">' . sp_e((string) (isset($sp_v2['grund']) ? $sp_v2['grund'] : '')) . '</span>' ?></td>
    <td><?= sp_e((string) (isset($sp_v2['quelle']) ? $sp_v2['quelle'] : '')) ?></td>
    <td><?= sp_e((string) (isset($sp_v2['antwort']) ? $sp_v2['antwort'] : '')) ?></td></tr>
<?php } ?>
</table>
</div>
<?php } ?>

<?php $sp_nv = sp_nicht_verstanden(8); if ($sp_nv) { ?>
<h3><?= sp_e(sp_t('TEST.H_NICHT_VERSTANDEN')) ?></h3>
<p class="sm-hilfe"><?= sp_t('TEST.NICHT_VERSTANDEN_ERKLAERUNG') ?></p>
<div class="sm-roll">
<table class="sm-tbl">
<tr><th><?= sp_e(sp_t('TEST.T_ANZAHL')) ?></th><th><?= sp_e(sp_t('TEST.T_SATZ')) ?></th>
    <th><?= sp_e(sp_t('TEST.T_GRUND')) ?></th><th><?= sp_e(sp_t('TEST.T_UEBERNEHMEN')) ?></th></tr>
<?php foreach ($sp_nv as $sp_n) { ?>
<tr><td><?= (int) $sp_n['anzahl'] ?>&times;</td>
    <td><span class="sm-mono"><?= sp_e($sp_n['satz']) ?></span></td>
    <td><?= sp_e($sp_n['grund']) ?><?= $sp_n['gesucht'] !== '' ? ' (' . sp_e($sp_n['gesucht']) . ')' : '' ?></td>
    <td><?php if ($sp_n['grund'] === 'ziel_unbekannt' && $sp_n['gesucht'] !== '' && $sp_zliste) { ?>
      <form action="index.php" method="post" style="display:flex;gap:6px;align-items:center;">
        <?php $sp_hidden('tab-test'); ?>
        <input data-role="none" type="hidden" name="alias_uebernehmen" value="<?= sp_e($sp_n['gesucht']) ?>">
        <select data-role="none" name="alias_ziel">
<?php foreach ($sp_zliste as $sp_k3 => $sp_z3) { ?>
          <option value="<?= sp_e((string) $sp_k3) ?>"><?= sp_e(is_array($sp_z3) && isset($sp_z3['name']) ? $sp_z3['name'] : (string) $sp_k3) ?></option>
<?php } ?>
        </select>
        <button data-role="none" class="sm-btn sm-b-aktion" style="min-width:auto!important;" type="submit"><?= sp_e(sp_t('TEST.K_ALIAS')) ?></button>
      </form>
    <?php } else { echo '&mdash;'; } ?></td></tr>
<?php } ?>
</table>
</div>
<?php } ?>

<div class="sm-legende">
<span><i class="sm-punkt sm-b-lesen"></i> <?= sp_t('LEGENDE.LESEN') ?></span>
<span><i class="sm-punkt sm-b-technik"></i> <?= sp_t('LEGENDE.TECHNIK') ?></span>
<span><i class="sm-punkt sm-b-aktion"></i> <?= sp_t('LEGENDE.AKTION') ?></span>
</div>

<h3><?= sp_e(sp_t('TEST.H_LESEN')) ?></h3>
<div class="sm-knopfreihe">
  <a class="sm-btn sm-b-lesen" href="<?= sp_e($sp_basis) ?>?token=<?= sp_e($sp_token) ?>&amp;aktion=status" target="_blank"><?= sp_e(sp_t('TEST.K_STATUS')) ?></a>
  <a class="sm-btn sm-b-lesen" href="<?= sp_e($sp_basis) ?>?token=<?= sp_e($sp_token) ?>&amp;aktion=verlauf" target="_blank"><?= sp_e(sp_t('TEST.K_VERLAUF')) ?></a>
  <a class="sm-btn sm-b-lesen" href="<?= sp_e($sp_basis) ?>?token=<?= sp_e($sp_token) ?>&amp;aktion=diag" target="_blank"><?= sp_e(sp_t('TEST.K_DIAG')) ?></a>
  <form action="index.php" method="post">
    <?php $sp_hidden('tab-test'); ?>
    <button data-role="none" class="sm-btn sm-b-lesen" type="submit" name="verlauf_csv" value="1"><?= sp_e(sp_t('TEST.K_CSV')) ?></button>
  </form>
</div>

<h3><?= sp_e(sp_t('TEST.H_TECHNIK')) ?></h3>
<div class="sm-knopfreihe">
  <form action="index.php" method="post">
    <?php $sp_hidden('tab-test'); ?>
    <button data-role="none" class="sm-btn sm-b-technik" type="submit" name="selbsttest" value="1"><?= sp_e(sp_t('TEST.K_SELBSTTEST')) ?></button>
  </form>
  <form action="index.php" method="post">
    <?php $sp_hidden('tab-test'); ?>
    <button data-role="none" class="sm-btn sm-b-technik" type="submit" name="test" value="dienste"><?= sp_e(sp_t('TEST.K_DIENSTE')) ?></button>
  </form>
  <form action="index.php" method="post">
    <?php $sp_hidden('tab-test'); ?>
    <button data-role="none" class="sm-btn sm-b-technik" type="submit" name="test" value="neu_laden"><?= sp_e(sp_t('TEST.K_NEU_LADEN')) ?></button>
  </form>
  <a class="sm-btn sm-b-technik" href="<?= sp_e($sp_basis) ?>?token=<?= sp_e($sp_token) ?>&amp;aktion=roh" target="_blank"><?= sp_e(sp_t('TEST.K_ROH')) ?></a>
</div>
<?php if ($sp_ausgabe !== '' && $sp_tab === 'tab-test') { ?>
<div class="sm-pre"><?= sp_e($sp_ausgabe) ?></div>
<?php } ?>

<h3><?= sp_e(sp_t('TEST.H_TROCKEN')) ?></h3>
<div class="sm-hinweis"><?= sp_t('TEST.TROCKEN_ERKLAERUNG') ?></div>
<form action="index.php" method="post">
<?php $sp_hidden('tab-test'); ?>
<div class="sm-feld">
  <label for="test_satz"><?= sp_e(sp_t('TEST.L_SATZ')) ?></label>
  <input data-role="none" type="text" id="test_satz" name="test_satz" value="schalte das Licht im Wohnzimmer ein">
  <div class="sm-hilfe"><?= sp_t('TEST.H_SATZ') ?></div>
</div>
<div class="sm-feld">
  <label for="test_raum"><?= sp_e(sp_t('TEST.L_RAUM')) ?></label>
  <input data-role="none" type="text" id="test_raum" name="test_raum" value="" placeholder="wohnzimmer">
  <div class="sm-hilfe"><?= sp_t('TEST.H_RAUM') ?></div>
</div>
<div class="sm-feld">
  <label for="test_ansage"><?= sp_e(sp_t('TEST.L_ANSAGE')) ?></label>
  <input data-role="none" type="text" id="test_ansage" name="test_ansage" value="Die Sprachsteuerung ist bereit.">
</div>
<div class="sm-feld">
  <label for="test_zone"><?= sp_e(sp_t('TEST.L_ZONE')) ?></label>
  <input data-role="none" type="text" id="test_zone" name="test_zone" value="" placeholder="<?= sp_e($sp_cfg['tts']['zones']) ?>">
</div>
<div class="sm-knopfreihe">
  <button data-role="none" class="sm-btn sm-b-technik" type="submit" name="test" value="trocken"><?= sp_e(sp_t('TEST.K_TROCKEN')) ?></button>
</div>
<h3><?= sp_e(sp_t('TEST.H_SCHALTEN')) ?></h3>
<div class="sm-warnung"><?= sp_t('TEST.SCHALTEN_WARNUNG') ?></div>
<div class="sm-knopfreihe">
  <button data-role="none" class="sm-btn sm-b-aktion" type="submit" name="test" value="satz"><?= sp_e(sp_t('TEST.K_SATZ')) ?></button>
  <button data-role="none" class="sm-btn sm-b-aktion" type="submit" name="test" value="sprechen"><?= sp_e(sp_t('TEST.K_SPRECHEN')) ?></button>
</div>
</form>

<div class="sm-warnung"><b><?= sp_e(sp_t('TEST.H_UNGEPRUEFT')) ?></b><br><?= sp_t('TEST.UNGEPRUEFT') ?></div>
</div>

<!-- ================= Reiter: Logdateien ================= -->
<div class="sm-seite<?= $sp_tab === 'tab-log' ? ' sm-active' : '' ?>" id="tab-log">
<h2><?= sp_e(sp_t('LOG.H_TITEL')) ?></h2>
<?php
if (class_exists('LBWeb', false) && method_exists('LBWeb', 'loglist_html')) {
    echo LBWeb::loglist_html();
}
?>
<p class="sm-hilfe"><?= sp_t('LOG.ERKLAERUNG') ?><br>
<span class="sm-mono"><?= sp_e($sp_p['log']) ?></span></p>
<?php if ($sp_logzeilen) { ?>
<div class="sm-log"><?= sp_e(implode("\n", $sp_logzeilen)) ?></div>
<?php } else { ?>
<div class="sm-hinweis"><?= sp_t('LOG.LEER') ?></div>
<?php } ?>

<h2><?= sp_e(sp_t('LOG.H_MITSCHNITT')) ?></h2>
<div class="sm-hinweis"><?= sp_t('LOG.MITSCHNITT_ERKLAERUNG') ?></div>
<?php $sp_rest = sp_mitschnitt_rest($sp_cfg); if ($sp_rest > 0) { ?>
<div class="sm-warnung"><?= sprintf(sp_t('LOG.MITSCHNITT_LAEUFT'), (int) $sp_rest) ?></div>
<?php } ?>
<div class="sm-legende"><span><i class="sm-punkt sm-b-technik"></i> <?= sp_t('LEGENDE.TECHNIK') ?></span></div>
<div class="sm-knopfreihe">
  <form action="index.php" method="post">
    <?php $sp_hidden('tab-log'); ?>
    <button data-role="none" class="sm-btn sm-b-technik" type="submit" name="mitschnitt" value="300"><?= sp_e(sp_t('LOG.K_MITSCHNITT_5')) ?></button>
  </form>
  <form action="index.php" method="post">
    <?php $sp_hidden('tab-log'); ?>
    <button data-role="none" class="sm-btn sm-b-technik" type="submit" name="mitschnitt" value="900"><?= sp_e(sp_t('LOG.K_MITSCHNITT_15')) ?></button>
  </form>
  <form action="index.php" method="post">
    <?php $sp_hidden('tab-log'); ?>
    <button data-role="none" class="sm-btn sm-b-technik" type="submit" name="mitschnitt" value="0"><?= sp_e(sp_t('LOG.K_MITSCHNITT_AUS')) ?></button>
  </form>
</div>
<?php $sp_mz = sp_log_ende($sp_p['mitschnitt'], 200); if ($sp_mz) { ?>
<div class="sm-log"><?= sp_e(implode("\n", $sp_mz)) ?></div>
<?php } ?>

<div class="sm-legende"><span><i class="sm-punkt sm-b-aktion"></i> <?= sp_t('LEGENDE.AKTION_LOG') ?></span></div>
<div class="sm-knopfreihe">
  <form action="index.php" method="post">
    <?php $sp_hidden('tab-log'); ?>
    <button data-role="none" class="sm-btn sm-b-aktion" type="submit" name="log_leeren" value="1"><?= sp_e(sp_t('LOG.K_LEEREN')) ?></button>
  </form>
</div>
</div>

</div><!-- /sm-wrap -->

<script>
(function () {
	var reiter = document.querySelectorAll('.sm-tab');
	function zeige(id) {
		reiter.forEach(function (r) { r.classList.toggle('sm-active', r.dataset.ziel === id); });
		document.querySelectorAll('.sm-seite').forEach(function (s) { s.classList.toggle('sm-active', s.id === id); });
		document.querySelectorAll('input[name="activetab"]').forEach(function (f) { f.value = id; });
		if (history.replaceState) { history.replaceState(null, '', 'index.php?form=' + id.replace('tab-', '')); }
	}
	reiter.forEach(function (r) {
		r.addEventListener('click', function (e) {
			// Reiter mit data-laden rechnen serverseitig etwas aus (Ports,
			// Docker, Endpunkt) und werden deshalb wirklich geladen. Ohne
			// das staende dort eine leere Flaeche.
			if (r.dataset.laden) { return; }
			e.preventDefault();
			zeige(r.dataset.ziel);
		});
	});
	// Der Server hat sm-active bereits gesetzt; dieser Aufruf richtet nur die
	// versteckten activetab-Felder aus und ist ansonsten wirkungslos.
	zeige(<?= sp_e(json_encode($sp_tab)) ?>);
})();
</script>
<?php
if ($sp_rahmen) {
    LBWeb::lbfooter();
}
