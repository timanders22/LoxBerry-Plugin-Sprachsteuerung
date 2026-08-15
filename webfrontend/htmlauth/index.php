<?php
/**
 * Sprachsteuerung lokal - Bedienoberflaeche
 *
 * Reiter: Einstellungen | Dienste | Mikrofone | Saetze |
 *         Einbindung in Loxone | Test | Logdateien
 *
 * Zwei Reiter kommen zu den fuenf des Hausstandards hinzu. Beide haben einen
 * eigenen, weil sie eigene Vorgaenge sind: 'Dienste' verwaltet vier Container
 * samt Modellauswahl, 'Saetze' ist der Inhalt, den der Anwender pflegt. In den
 * Einstellungen wuerden beide untergehen.
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
}

/* EINE Quelle fuer Reihenfolge, Positivliste und Beschriftung.
 *
 * Bis 0.9.1 standen die Reiternamen an drei Stellen: in dieser Positivliste,
 * in der Reiterleiste und in den sieben Flaechen-ids. Wer einen Reiter
 * ergaenzt und eine davon vergisst, bekommt keinen Fehler, sondern eine
 * Seite, die nach jedem Absenden auf Einstellungen zurueckspringt.
 *
 * Die Beschriftungen brauchen sp_t() und kommen weiter unten dazu, wenn die
 * Sprachdatei geladen ist. */
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

/*
 * Fuer Felder, die KEIN Freitext sind: Rechnernamen, Anschlussnummern,
 * Kennungen, Auswahlwerte. Dort haben Anfuehrungszeichen nichts zu suchen,
 * und sie zu entfernen ist richtig.
 *
 * Zu einer Beanstandung, das treffe auch Freitextfelder: es geht durch
 * diese Funktion kein Freitext. Geprueft wurde jedes Feld - sprache,
 * wakeword, mqtt_topic, antwortweg, tts_mode, tts_ip, tts_port,
 * tts_volume, tts_zones, tts_lang, die Modellnamen sowie Rechner und
 * Anschluss der Dienste. Die TTS-Vorlage laeuft ausdruecklich NICHT
 * hierdurch (siehe weiter unten). Das einzige echte Freitextfeld ist die
 * Bezeichnung eines Mikrofons - die wird unten eigens behandelt.
 */
$sp_sauber = function ($feld) {
    return trim(preg_replace('/[\x00-\x1F\x7F"\']/', '',
        (string) (isset($_POST[$feld]) ? $_POST[$feld] : '')));
};

/*
 * Fuer Freitext: nur Steuerzeichen weg, Anfuehrungszeichen bleiben.
 * Eine Bezeichnung wie  Kueche "oben"  soll so stehen bleiben duerfen. Der
 * Wert landet in JSON und im Protokoll, nie in einer Shell; ausgegeben wird
 * er ueber sp_e().
 */
$sp_freitext = function ($wert) {
    return trim(preg_replace('/\s+/u', ' ',
        preg_replace('/[\x00-\x1F\x7F]/u', ' ', (string) $wert)));
};

/* ---------------- Vorlage herunterladen ---------------- */
if ($sp_post && isset($_POST['vorlage'])) {
    list($sp_name, $sp_inhalt) = sp_vorlage();
    header('Content-Type: application/xml; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $sp_name . '"');
    echo $sp_inhalt;
    exit;
}

/* ---------------- Einstellungen speichern ---------------- */
// ---------------- MQTT speichern (eigener Reiter seit 0.9.6, Hausstandard) ----------------
if ($sp_post && isset($_POST['mqtt_save'])) {
    $sp_cfg = sp_config();
    $sp_cfg['mqtt_ein'] = isset($_POST['mqtt_ein']) ? 1 : 0;
    $sp_topic = trim(preg_replace('/[\x00-\x1F\x7F"\']/', '', (string) (isset($_POST['mqtt_topic']) ? $_POST['mqtt_topic'] : '')));
    if ($sp_topic === '' || !preg_match('#^[A-Za-z0-9_/\-]{1,64}$#', $sp_topic)) {
        $sp_fehler[] = sp_t('EINST.FEHLER_TOPIC');
    } else {
        $sp_cfg['mqtt_topic'] = trim($sp_topic, '/');
    }
    if (!$sp_fehler) {
        if (sp_config_speichern($sp_cfg)) { $sp_meldungen[] = sp_t('EINST.GESPEICHERT'); }
        else { $sp_fehler[] = sprintf(sp_t('EINST.FEHLER_SPEICHERN'), $sp_p['config']); }
    }
    $sp_tab = 'tab-mqtt';
}

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
    foreach (array('wartezeit' => array(1, 120), 'verlauf_zeilen' => array(5, 500)) as $f => $g) {
        $w = $sp_sauber($f);
        if (!preg_match('/^[0-9]+$/', $w)) {
            $sp_fehler[] = sprintf(sp_t('EINST.FEHLER_ZAHL'), sp_t('EINST.L_' . strtoupper($f)));
        } elseif ((int) $w < $g[0] || (int) $w > $g[1]) {
            $sp_fehler[] = sprintf(sp_t('EINST.FEHLER_BEREICH'), sp_t('EINST.L_' . strtoupper($f)), $g[0], $g[1]);
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
    if ($sp_ww !== '' && !preg_match('/^[a-z0-9_\-]{1,40}$/', $sp_ww)) {
        $sp_fehler[] = sp_t('EINST.FEHLER_WAKEWORD');
    } elseif ($sp_ww !== '') {
        $sp_cfg['wakeword'] = $sp_ww;
    }
    // MQTT wohnt seit 0.9.6 im eigenen Reiter - hier nur pruefen, wenn das
    // Feld wirklich mitkommt, sonst meldete jedes Speichern der
    // Einstellungen einen Themenfehler.
    if (isset($_POST['mqtt_topic'])) {
        $sp_topic = $sp_sauber('mqtt_topic');
        if ($sp_topic === '' || !preg_match('#^[A-Za-z0-9_/\-]{1,64}$#', $sp_topic)) {
            $sp_fehler[] = sp_t('EINST.FEHLER_TOPIC');
        } else {
            $sp_cfg['mqtt_topic'] = trim($sp_topic, '/');
        }
    }
    // Die Miniserver-Adresse kann Zugangsdaten enthalten - deshalb NICHT
    // filtern, nur auf die Form pruefen.
    $sp_url = trim((string) (isset($_POST['miniserver_url']) ? $_POST['miniserver_url'] : ''));
    if ($sp_url !== '' && !sp_url_ok($sp_url)) {
        $sp_fehler[] = sp_t('EINST.FEHLER_URL');
    } else {
        $sp_cfg['miniserver_url'] = $sp_url;
    }
    $sp_cfg['llm_ein'] = isset($_POST['llm_ein']) ? 1 : 0;
    // mqtt_ein wohnt im MQTT-Reiter (eigenes Formular) - hier nicht anfassen.
    $sp_cfg['antwort_sprechen'] = isset($_POST['antwort_sprechen']) ? 1 : 0;

    /* ---- Rueckweg nach Loxone ---- */
    $sp_weg = $sp_sauber('antwortweg');
    if (!in_array($sp_weg, array('satellit', 'loxone', 'beide'), true)) {
        $sp_fehler[] = sp_t('EINST.FEHLER_ANTWORTWEG');
    } else {
        $sp_cfg['antwortweg'] = $sp_weg;
    }
    $sp_tts = is_array(isset($sp_cfg['tts']) ? $sp_cfg['tts'] : null) ? $sp_cfg['tts'] : array();
    $sp_modus = $sp_sauber('tts_mode');
    if (!in_array($sp_modus, array('musicserver', 'ms4h', 'audioserver', 'custom'), true)) {
        $sp_fehler[] = sp_t('EINST.FEHLER_TTS_MODUS');
    } else {
        $sp_tts['mode'] = $sp_modus;
    }
    // Die Adresse darf ein Name sein, nicht nur eine IP - geprueft wird die
    // Form, nicht der Inhalt.
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
    // Zonen: Ziffern, Komma und die Tilde fuer 'Zone~Lautstaerke'.
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

    if (!$sp_fehler) {
        if (sp_config_speichern($sp_cfg)) { $sp_meldungen[] = sp_t('EINST.GESPEICHERT'); }
        else { $sp_fehler[] = sprintf(sp_t('EINST.FEHLER_SPEICHERN'), $sp_p['config']); }
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
    for ($i = 0; $i < 8; $i++) {
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
        $eintrag = array('art' => $art, 'name' => $name !== '' ? $name : $host,
                         'host' => $host, 'port' => (int) $port);
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

/* ---------------- Saetze speichern ---------------- */
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
        // Ein Ziel ist entweder ein Block mit name/alias/thema oder die
        // Kurzform "schluessel": "thema". Alles andere wird benannt, nicht
        // stillschweigend uebergangen - der Dienst laedt dieselbe Datei.
        foreach ($sp_d['ziele'] as $sp_k => $sp_z) {
            if (!is_array($sp_z) && !is_string($sp_z)) {
                $sp_fehler[] = sprintf(sp_t('SATZ.FEHLER_ZIEL_FORM'), sp_e((string) $sp_k));
            }
        }
        if (!$sp_fehler) {
            /* Erst jetzt reinigen, nicht vorher: die Pruefungen oben sollen
             * das sehen, was eingegeben wurde. Steuerzeichen in einem Alias
             * oder Thema landeten bis 0.9.1 unbesehen in der saetze.json -
             * und von dort in ein MQTT-Thema oder in einen Text, der
             * vorgelesen wird. */
            $sp_d = sp_steuerzeichen_weg($sp_d);
            if (sp_saetze_speichern($sp_d)) { $sp_meldungen[] = sp_t('SATZ.GESPEICHERT'); }
            else { $sp_fehler[] = sprintf(sp_t('EINST.FEHLER_SPEICHERN'), $sp_p['saetze']); }
        }
    }
    $sp_tab = 'tab-sentences';
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

/* ---------------- Token, Log, Test ---------------- */
if ($sp_post && isset($_POST['token_neu'])) {
    $sp_cfg = sp_config();
    $sp_cfg['aktionstoken'] = sp_token_erzeugen();
    if (sp_config_speichern($sp_cfg)) { $sp_meldungen[] = sp_t('LOX.TOKEN_NEU'); }
    else { $sp_fehler[] = sprintf(sp_t('EINST.FEHLER_SPEICHERN'), $sp_p['config']); }
    $sp_tab = 'tab-loxone';
}
if ($sp_post && isset($_POST['log_leeren'])) {
    @mkdir(dirname($sp_p['log']), 0775, true);
    // In die Logdatei gehoert Klartext, kein HTML: der Reiter zeigt die Datei
    // maskiert an, sonst stuende dort woertlich 'Bedienoberfl&auml;che'.
    $sp_klartext = trim(strip_tags(html_entity_decode(sp_t('LOG.GELEERT'), ENT_QUOTES, 'UTF-8')));
    @file_put_contents($sp_p['log'], '[' . date('Y-m-d H:i:s') . '] ' . $sp_klartext . "\n");
    $sp_meldungen[] = sp_t('LOG.GELEERT');
    $sp_tab = 'tab-log';
}
if ($sp_post && isset($_POST['test'])) {
    list($sp_stand, $sp_text) = sp_test_aktion((string) $_POST['test']);
    if ($sp_stand === 1) { $sp_meldungen[] = sp_e($sp_text); } else { $sp_fehler[] = sp_e($sp_text); }
    $sp_tab = 'tab-test';
}
if ($sp_post && isset($_POST['selbsttest'])) {
    $sp_ausgabe = sp_selbsttest_ausgabe();
    $sp_tab = 'tab-test';
}

/* ---------------- Laden ---------------- */
$sp_cfg = sp_config();
$sp_token = sp_token();
$sp_saetze = sp_saetze();
$sp_sats = sp_satelliten();
$sp_verlauf = sp_verlauf();
$sp_alter = sp_alter();
$sp_pid = sp_dienst_pid();
$sp_mqtt = sp_mqtt_zustand();
$sp_modelle = sp_modelle();
$sp_hw = sp_hardware(false);
$sp_emp = isset($sp_hw['empfehlung']) ? $sp_hw['empfehlung'] : array();
$sp_host = isset($_SERVER['HTTP_HOST']) && $_SERVER['HTTP_HOST'] !== ''
    ? preg_replace('/[^A-Za-z0-9\.\-:]/', '', (string) $_SERVER['HTTP_HOST'])
    : (gethostname() ?: 'loxberry');
$sp_basis = 'http://' . $sp_host . '/plugins/' . $sp_p['plugin'] . '/index.php';
$sp_logzeilen = is_file($sp_p['log']) ? sp_log_ende($sp_p['log'], 400) : array();

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
</div>

<?php if ($sp_verlauf) { $sp_letzter = $sp_verlauf[0]; ?>
<div class="sm-hinweis"><b><?= sp_e(sp_t('ALLG.ZULETZT')) ?></b>
<span class="sm-mono"><?= sp_e((string) (isset($sp_letzter['satz']) ? $sp_letzter['satz'] : '')) ?></span>
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
?>
<div class="sm-tabs">
<?php foreach ($sp_reiter_ids as $sp_r) { ?>
	<a class="sm-tab<?= $sp_tab === 'tab-' . $sp_r ? ' sm-active' : '' ?>" data-ziel="tab-<?= $sp_r ?>" href="index.php?form=<?= $sp_r ?>"><?= sp_e(isset($sp_beschriftung[$sp_r]) ? sp_t($sp_beschriftung[$sp_r]) : $sp_r) ?></a>
<?php } ?>
</div>

<!-- ================= Reiter: Einstellungen ================= -->
<div class="sm-seite<?= $sp_tab === 'tab-settings' ? ' sm-active' : '' ?>" id="tab-settings">
<div class="sm-warnung"><?= sp_t('EINST.WAS_IST_DAS') ?></div>

<h2><?= sp_e(sp_t('EINST.H_DIENST')) ?></h2>
<p class="sm-hilfe"><?= sp_t('EINST.DIENST_ERKLAERUNG') ?></p>
<div class="sm-legende">
<span><i class="sm-punkt sm-b-aktion"></i><?= sp_t('LEGENDE.AKTION') ?></span>
</div>
<div class="sm-knopfreihe">
<?php foreach (array('start' => 'sm-b-lesen', 'restart' => 'sm-b-aktion', 'stop' => 'sm-b-aktion') as $sp_b => $sp_farbe) { ?>
  <form action="index.php" method="post">
    <input data-role="none" type="hidden" name="activetab" value="tab-settings">
    <button data-role="none" class="sm-btn <?= $sp_farbe ?>" type="submit" name="dienst" value="<?= $sp_b ?>"><?= sp_e(sp_t('EINST.K_' . strtoupper($sp_b))) ?></button>
  </form>
<?php } ?>
</div>

<form action="index.php" method="post" autocomplete="off">
<input data-role="none" type="hidden" name="speichern" value="1">
<input data-role="none" type="hidden" name="activetab" value="tab-settings">

<h2><?= sp_e(sp_t('EINST.H_DIENSTE')) ?></h2>
<p class="sm-hilfe"><?= sp_t('EINST.DIENSTE_ERKLAERUNG') ?></p>
<table class="sm-tbl">
<tr><th><?= sp_e(sp_t('EINST.T_DIENST')) ?></th><th><?= sp_e(sp_t('EINST.T_ADRESSE')) ?></th><th><?= sp_e(sp_t('EINST.T_PORT')) ?></th></tr>
<?php foreach (array('whisper', 'piper', 'wake', 'llm') as $sp_d) { ?>
<tr><td><?= sp_e(sp_t('EINST.L_' . strtoupper($sp_d))) ?></td>
    <td><input data-role="none" type="text" name="<?= $sp_d ?>_host" value="<?= sp_e($sp_cfg[$sp_d . '_host']) ?>" size="16"></td>
    <td><input data-role="none" type="text" name="<?= $sp_d ?>_port" value="<?= (int) $sp_cfg[$sp_d . '_port'] ?>" size="6"></td></tr>
<?php } ?>
</table>

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

<h2><?= sp_e(sp_t('EINST.H_ANTWORTWEG')) ?></h2>
<div class="sm-hinweis"><?= sp_t('EINST.H_ANTWORTWEG_TEXT') ?></div>
<div class="sm-feld">
  <label for="antwortweg"><?= sp_e(sp_t('EINST.L_ANTWORTWEG')) ?></label>
  <select data-role="none" id="antwortweg" name="antwortweg">
<?php foreach (array('satellit', 'loxone', 'beide') as $sp_w) { ?>
    <option value="<?= $sp_w ?>"<?= $sp_cfg['antwortweg'] === $sp_w ? ' selected' : '' ?>><?= sp_e(sp_t('EINST.WEG_' . strtoupper($sp_w))) ?></option>
<?php } ?>
  </select>
  <div class="sm-hilfe"><?= sp_t('EINST.H_ANTWORTWEG_FELD') ?></div>
</div>
<div class="sm-feld">
  <label for="tts_mode"><?= sp_e(sp_t('EINST.L_TTS_MODE')) ?></label>
  <select data-role="none" id="tts_mode" name="tts_mode">
<?php foreach (array('musicserver', 'ms4h', 'audioserver', 'custom') as $sp_m) { ?>
    <option value="<?= $sp_m ?>"<?= $sp_cfg['tts']['mode'] === $sp_m ? ' selected' : '' ?>><?= sp_e(sp_t('EINST.TTS_' . strtoupper($sp_m))) ?></option>
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
  <label for="tts_template"><?= sp_e(sp_t('EINST.L_TTS_TEMPLATE')) ?></label>
  <input data-role="none" type="text" id="tts_template" name="tts_template" value="<?= sp_e($sp_cfg['tts']['template']) ?>" placeholder="http://{ip}:{port}/tts?text={text}&amp;zone={zones}&amp;vol={vol}">
  <div class="sm-hilfe"><?= sp_t('EINST.H_TTS_TEMPLATE') ?></div>
</div>
<div class="sm-feld">
  <label for="sprache"><?= sp_e(sp_t('EINST.L_SPRACHE')) ?></label>
  <input data-role="none" type="text" id="sprache" name="sprache" value="<?= sp_e($sp_cfg['sprache']) ?>" maxlength="2">
</div>
<div class="sm-feld">
  <label for="wakeword"><?= sp_e(sp_t('EINST.L_WAKEWORD')) ?></label>
  <input data-role="none" type="text" id="wakeword" name="wakeword" value="<?= sp_e($sp_cfg['wakeword']) ?>">
  <div class="sm-hilfe"><?= sp_t('EINST.H_WAKEWORD') ?></div>
</div>

<h2><?= sp_e(sp_t('EINST.H_LOXONE')) ?></h2>
<div class="sm-feld">
  <label for="miniserver_url"><?= sp_e(sp_t('EINST.L_URL')) ?></label>
  <input data-role="none" type="text" id="miniserver_url" name="miniserver_url" value="<?= sp_e($sp_cfg['miniserver_url']) ?>">
  <div class="sm-hilfe"><?= sp_t('EINST.H_URL') ?></div>
</div>
<div class="sm-feld">
  <label for="wartezeit"><?= sp_e(sp_t('EINST.L_WARTEZEIT')) ?></label>
  <input data-role="none" type="number" id="wartezeit" name="wartezeit" value="<?= (int) $sp_cfg['wartezeit'] ?>" min="1" max="120">
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

<!-- ================= Reiter: MQTT (eigener Reiter seit 0.9.6, Hausstandard) ================= -->
<div class="sm-seite<?= $sp_tab === 'tab-mqtt' ? ' sm-active' : '' ?>" id="tab-mqtt">
<h2>MQTT</h2>
<form action="index.php" method="post">
<input data-role="none" type="hidden" name="mqtt_save" value="1">
<input data-role="none" type="hidden" name="activetab" value="tab-mqtt">
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

<div class="sm-knopfreihe">
  <button data-role="none" class="sm-btn sm-b-aktion" type="submit"><?= sp_e(sp_t('ALLG.SPEICHERN')) ?></button>
</div>
<div class="sm-legende"><span><i class="sm-punkt sm-b-aktion"></i> <?= sp_t('LEGENDE.AKTION') ?></span></div>
</form>
</div>

<!-- ================= Reiter: Dienste ================= -->
<div class="sm-seite<?= $sp_tab === 'tab-services' ? ' sm-active' : '' ?>" id="tab-services">
<h2><?= sp_e(sp_t('DIENST.H_HARDWARE')) ?></h2>
<?php if (!$sp_hw) { ?>
<div class="sm-warnung"><?= sp_t('DIENST.KEINE_HARDWARE') ?></div>
<?php } else { $sp_h = $sp_hw['hardware']; ?>
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
<?php if ($sp_emp) { ?>
<div class="sm-hinweis"><b><?= sprintf(sp_t('DIENST.VORSCHLAG'), sp_e($sp_emp['name'])) ?></b>
<?= sp_t('STUFE.' . strtoupper($sp_emp['name'])) ?>
<table class="sm-tbl">
<tr><th><?= sp_e(sp_t('DIENST.T_TEIL')) ?></th><th><?= sp_e(sp_t('DIENST.T_VORSCHLAG')) ?></th><th><?= sp_e(sp_t('DIENST.T_GROESSE')) ?></th></tr>
<tr><td><?= sp_e(sp_t('EINST.L_WHISPER')) ?></td><td><span class="sm-mono"><?= sp_e($sp_emp['whisper']['modell']) ?></span></td><td><?= (int) $sp_emp['whisper']['datei_mb'] ?> MB</td></tr>
<tr><td><?= sp_e(sp_t('EINST.L_PIPER')) ?></td><td><span class="sm-mono"><?= sp_e($sp_emp['piper']['stimme']) ?></span></td><td><?= (int) $sp_emp['piper']['datei_mb'] ?> MB</td></tr>
<tr><td><?= sp_e(sp_t('EINST.L_LLM')) ?></td>
    <td><?= !empty($sp_emp['llm']) ? '<span class="sm-mono">' . sp_e($sp_emp['llm']['modell']) . '</span>' : sp_e(sp_t('DIENST.KEIN_LLM')) ?></td>
    <td><?= !empty($sp_emp['llm']) ? (int) $sp_emp['llm']['datei_mb'] . ' MB' : '&mdash;' ?></td></tr>
</table>
<?= sp_t('DIENST.KEINE_ZEITEN') ?>
</div>
<?php } } ?>

<h2><?= sp_e(sp_t('DIENST.H_MODELLE')) ?></h2>
<form action="index.php" method="post" autocomplete="off">
<input data-role="none" type="hidden" name="modelle_speichern" value="1">
<input data-role="none" type="hidden" name="activetab" value="tab-services">
<div class="sm-feld">
  <label for="whisper_modell"><?= sp_e(sp_t('DIENST.L_WHISPER_MODELL')) ?></label>
  <input data-role="none" type="text" id="whisper_modell" name="whisper_modell" value="<?= sp_e($sp_cfg['whisper_modell']) ?>"
         placeholder="<?= $sp_emp ? sp_e($sp_emp['whisper']['modell']) : 'base-int8' ?>">
</div>
<div class="sm-feld">
  <label for="piper_stimme"><?= sp_e(sp_t('DIENST.L_PIPER_STIMME')) ?></label>
  <input data-role="none" type="text" id="piper_stimme" name="piper_stimme" value="<?= sp_e($sp_cfg['piper_stimme']) ?>"
         placeholder="<?= $sp_emp ? sp_e($sp_emp['piper']['stimme']) : 'de_DE-thorsten-low' ?>">
</div>
<div class="sm-feld">
  <label for="llm_modell"><?= sp_e(sp_t('DIENST.L_LLM_MODELL')) ?></label>
  <input data-role="none" type="text" id="llm_modell" name="llm_modell" value="<?= sp_e($sp_cfg['llm_modell']) ?>"
         placeholder="<?= ($sp_emp && !empty($sp_emp['llm'])) ? sp_e($sp_emp['llm']['quelle']) : '' ?>">
  <div class="sm-hilfe"><?= sp_t('DIENST.H_LLM_MODELL') ?></div>
</div>
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
<span><i class="sm-punkt sm-b-technik"></i><?= sp_t('LEGENDE.TECHNIK') ?></span>
<span><i class="sm-punkt sm-b-aktion"></i><?= sp_t('LEGENDE.AKTION') ?></span>
</div>
<?php foreach (sp_dienste() as $sp_d) {
    list($sp_host, $sp_port) = sp_dienst_ziel($sp_d, $sp_cfg);
    $sp_ext = !sp_ist_lokal($sp_host);
    $sp_zu = sp_container_zustand($sp_d, $sp_cfg);
    $sp_bef = sp_container_befehl($sp_d, $sp_cfg, $sp_emp ?: null, $sp_ext); ?>
<h3><?= sp_e(sp_t('EINST.L_' . strtoupper($sp_d === 'wakeword' ? 'WAKE' : $sp_d))) ?>
    <span class="<?= ($sp_zu === 'laeuft' || $sp_zu === 'extern') ? 'sm-an' : 'sm-aus' ?>">&mdash; <?= sp_e(sp_t('ALLG.CONT_' . strtoupper($sp_zu))) ?></span>
    <span class="sm-mono">(<?= sp_e($sp_host . ':' . $sp_port) ?>)</span></h3>
<p class="sm-hilfe"><?= sp_t($sp_modelle['dienste'][$sp_d]['text']) ?></p>
<?php if ($sp_ext) { ?>
<div class="sm-hinweis"><?= sprintf(sp_t('DIENST.AUSGELAGERT'), sp_e($sp_host . ':' . $sp_port)) ?></div>
<?php if ($sp_bef !== '') { ?>
<p><span class="sm-mono">docker <?= sp_e($sp_bef) ?></span></p>
<?php } ?>
<div class="sm-knopfreihe">
  <form action="index.php" method="post">
    <input data-role="none" type="hidden" name="activetab" value="tab-services">
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
<?php foreach (array('anlegen' => 'sm-b-lesen', 'start' => 'sm-b-lesen', 'holen' => 'sm-b-technik',
                     'restart' => 'sm-b-aktion', 'stop' => 'sm-b-aktion', 'entfernen' => 'sm-b-aktion') as $sp_w => $sp_farbe) { ?>
  <form action="index.php" method="post">
    <input data-role="none" type="hidden" name="activetab" value="tab-services">
    <input data-role="none" type="hidden" name="dienstname" value="<?= $sp_d ?>">
    <button data-role="none" class="sm-btn <?= $sp_farbe ?>" type="submit" name="container" value="<?= $sp_w ?>"><?= sp_e(sp_t('DIENST.KC_' . strtoupper($sp_w))) ?></button>
  </form>
<?php } ?>
  <form action="index.php" method="post">
    <input data-role="none" type="hidden" name="activetab" value="tab-services">
    <button data-role="none" class="sm-btn sm-b-technik" type="submit" name="containerlog" value="<?= $sp_d ?>"><?= sp_e(sp_t('DIENST.KC_LOG')) ?></button>
  </form>
</div>
<?php } ?>
<?php } ?>

<h2><?= sp_e(sp_t('DIENST.H_MESSEN')) ?></h2>
<div class="sm-hinweis"><?= sp_t('DIENST.MESSEN_ERKLAERUNG') ?></div>
<div class="sm-knopfreihe">
  <form action="index.php" method="post">
    <input data-role="none" type="hidden" name="activetab" value="tab-services">
    <button data-role="none" class="sm-btn sm-b-technik" type="submit" name="messen" value="1"><?= sp_e(sp_t('DIENST.K_MESSEN')) ?></button>
  </form>
</div>
<?php if ($sp_ausgabe !== '') { ?>
<div class="sm-pre"><?= sp_e($sp_ausgabe) ?></div>
<?php } ?>
</div>

<!-- ================= Reiter: Mikrofone ================= -->
<div class="sm-seite<?= $sp_tab === 'tab-mics' ? ' sm-active' : '' ?>" id="tab-mics">
<h2><?= sp_e(sp_t('MIKRO.H_TITEL')) ?></h2>
<div class="sm-hinweis"><?= sp_t('MIKRO.ERKLAERUNG') ?></div>
<form action="index.php" method="post" autocomplete="off">
<input data-role="none" type="hidden" name="mikros_speichern" value="1">
<input data-role="none" type="hidden" name="activetab" value="tab-mics">
<table class="sm-tbl">
<tr><th style="width:28px;">#</th><th><?= sp_e(sp_t('MIKRO.T_NAME')) ?></th>
    <th style="width:110px;"><?= sp_e(sp_t('MIKRO.T_ART')) ?></th>
    <th><?= sp_e(sp_t('MIKRO.T_HOST')) ?></th><th style="width:80px;"><?= sp_e(sp_t('MIKRO.T_PORT')) ?></th>
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
<td><input data-role="none" type="text" name="m_name[]" value="<?= sp_e($sp_name) ?>" size="14"></td>
<td><select data-role="none" name="m_art[]">
    <option value="wyoming"<?= $sp_v('art') !== 'esphome' ? ' selected' : '' ?>>Wyoming</option>
    <option value="esphome"<?= $sp_v('art') === 'esphome' ? ' selected' : '' ?>>ESPHome</option>
</select></td>
<td><input data-role="none" type="text" name="m_host[]" value="<?= sp_e($sp_v('host')) ?>" size="16" placeholder="<?= $sp_i === 0 ? '192.168.1.60' : '' ?>"></td>
<td><input data-role="none" type="text" name="m_port[]" value="<?= sp_e($sp_v('port')) ?>" size="6"></td>
<td><input data-role="none" type="password" name="m_schluessel[]" value=""
    placeholder="<?= $sp_v('schluessel') !== '' ? sp_e(sp_t('MIKRO.SCHLUESSEL_DA')) : sp_e(sp_t('MIKRO.SCHLUESSEL_LEER')) ?>" size="14"></td>
<td class="<?= $sp_zust === 'getrennt' || $sp_zust === '' ? 'sm-aus' : 'sm-an' ?>"><?= $sp_zust !== '' ? sp_e($sp_zust) : '&mdash;' ?></td></tr>
<?php } ?>
</table>
<div class="sm-legende">
<span><i class="sm-punkt sm-b-aktion"></i><?= sp_t('LEGENDE.AKTION') ?></span>
</div>
<div class="sm-knopfreihe">
  <button data-role="none" class="sm-btn sm-b-aktion" type="submit"><?= sp_e(sp_t('ALLG.SPEICHERN')) ?></button>
</div>
</form>
<div class="sm-hilfe"><?= sp_t('MIKRO.HILFE') ?></div>
<div class="sm-warnung"><?= sp_t('MIKRO.ESPHOME_WARNUNG') ?></div>

<h2><?= sp_e(sp_t('MIKRO.H_WELCHE')) ?></h2>
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

<!-- ================= Reiter: Saetze ================= -->
<div class="sm-seite<?= $sp_tab === 'tab-sentences' ? ' sm-active' : '' ?>" id="tab-sentences">
<h2><?= sp_e(sp_t('SATZ.H_TITEL')) ?></h2>
<div class="sm-hinweis"><?= sp_t('SATZ.ERKLAERUNG') ?></div>
<table class="sm-tbl">
<tr><th><?= sp_e(sp_t('SATZ.T_ZEICHEN')) ?></th><th><?= sp_e(sp_t('SATZ.T_BEDEUTUNG')) ?></th><th><?= sp_e(sp_t('SATZ.T_BEISPIEL')) ?></th></tr>
<tr><td><span class="sm-mono">{ziel}</span></td><td><?= sp_t('SATZ.B_ZIEL') ?></td><td><span class="sm-mono">schalte {ziel} ein</span></td></tr>
<tr><td><span class="sm-mono">{wert}</span></td><td><?= sp_t('SATZ.B_WERT') ?></td><td><span class="sm-mono">dimme {ziel} auf {wert}</span></td></tr>
<tr><td><span class="sm-mono">[a|b]</span></td><td><?= sp_t('SATZ.B_ALT') ?></td><td><span class="sm-mono">[schalte|mach] {ziel} [an|ein]</span></td></tr>
<tr><td><span class="sm-mono">[a|]</span></td><td><?= sp_t('SATZ.B_LEER') ?></td><td><span class="sm-mono">{ziel} auf {wert} [prozent|]</span></td></tr>
</table>

<h2><?= sp_e(sp_t('SATZ.H_ZIELE')) ?></h2>
<?php if (empty($sp_saetze['ziele'])) { ?>
<div class="sm-warnung"><?= sp_t('SATZ.KEINE_ZIELE') ?></div>
<?php } else { ?>
<table class="sm-tbl">
<tr><th><?= sp_e(sp_t('SATZ.T_SCHLUESSEL')) ?></th><th><?= sp_e(sp_t('SATZ.T_NAME')) ?></th>
    <th><?= sp_e(sp_t('SATZ.T_ALIAS')) ?></th><th><?= sp_e(sp_t('SATZ.T_THEMA')) ?></th></tr>
<?php foreach ((array) $sp_saetze['ziele'] as $sp_k => $sp_z) { ?>
<tr><td><span class="sm-mono"><?= sp_e($sp_k) ?></span></td>
    <td><?= sp_e(isset($sp_z['name']) ? $sp_z['name'] : '') ?></td>
    <td><?= sp_e(implode(', ', (array) (isset($sp_z['alias']) ? $sp_z['alias'] : array()))) ?></td>
    <td><span class="sm-mono"><?= sp_e($sp_cfg['mqtt_topic']) ?>/<?= sp_e(isset($sp_z['thema']) ? $sp_z['thema'] : $sp_k) ?>/aktion</span></td></tr>
<?php } ?>
</table>
<?php } ?>

<h2><?= sp_e(sp_t('SATZ.H_BEARBEITEN')) ?></h2>
<div class="sm-warnung"><?= sp_t('SATZ.BEARBEITEN_WARNUNG') ?></div>
<form action="index.php" method="post">
<input data-role="none" type="hidden" name="saetze_speichern" value="1">
<input data-role="none" type="hidden" name="activetab" value="tab-sentences">
<div class="sm-feld">
  <textarea data-role="none" name="saetze" rows="24" style="width:100%;font-family:Consolas,monospace;font-size:0.86em;"><?= sp_e(json_encode($sp_saetze, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?></textarea>
</div>
<div class="sm-legende">
<span><i class="sm-punkt sm-b-aktion"></i><?= sp_t('LEGENDE.AKTION') ?></span>
</div>
<div class="sm-knopfreihe">
  <button data-role="none" class="sm-btn sm-b-aktion" type="submit"><?= sp_e(sp_t('ALLG.SPEICHERN')) ?></button>
</div>
</form>
<div class="sm-hilfe"><?= sp_t('SATZ.NEU_LADEN') ?></div>
</div>

<!-- ================= Reiter: Einbindung in Loxone ================= -->
<div class="sm-seite<?= $sp_tab === 'tab-loxone' ? ' sm-active' : '' ?>" id="tab-loxone">
<h2><?= sp_e(sp_t('LOX.H_TITEL')) ?></h2>
<p><?= sp_t('LOX.EINLEITUNG') ?></p>

<div class="sm-step"><b><?= sp_e(sp_t('LOX.S1_TITEL')) ?></b><br>
<?= sp_t('LOX.S1_TEXT') ?>
<p><span class="sm-mono"><?= sp_e($sp_cfg['mqtt_topic']) ?>/#</span></p>
<div class="sm-warnung"><?= sp_t('LOX.S1_WARNUNG') ?></div>
</div>

<div class="sm-step"><b><?= sp_e(sp_t('LOX.S2_TITEL')) ?></b><br>
<?= sp_t('LOX.S2_TEXT') ?>
<table class="sm-tbl">
<tr><th><?= sp_e(sp_t('MQTT.T_THEMA')) ?></th><th><?= sp_e(sp_t('MQTT.T_BEDEUTUNG')) ?></th></tr>
<tr><td><span class="sm-mono"><?= sp_e($sp_cfg['mqtt_topic']) ?>/absicht</span></td><td><?= sp_t('MQTT.B_ABSICHT') ?></td></tr>
<tr><td><span class="sm-mono"><?= sp_e($sp_cfg['mqtt_topic']) ?>/aktion</span></td><td><?= sp_t('MQTT.B_AKTION') ?></td></tr>
<tr><td><span class="sm-mono"><?= sp_e($sp_cfg['mqtt_topic']) ?>/ziel</span></td><td><?= sp_t('MQTT.B_ZIEL') ?></td></tr>
<tr><td><span class="sm-mono"><?= sp_e($sp_cfg['mqtt_topic']) ?>/wert</span></td><td><?= sp_t('MQTT.B_WERT') ?></td></tr>
<tr><td><span class="sm-mono"><?= sp_e($sp_cfg['mqtt_topic']) ?>/quelle</span></td><td><?= sp_t('MQTT.B_QUELLE') ?></td></tr>
<tr><td><span class="sm-mono"><?= sp_e($sp_cfg['mqtt_topic']) ?>/&lt;Thema&gt;/aktion</span></td><td><?= sp_t('MQTT.B_ZIELTHEMA') ?></td></tr>
<tr><td><span class="sm-mono"><?= sp_e($sp_cfg['mqtt_topic']) ?>/antwort</span></td><td><?= sp_t('MQTT.B_ANTWORT') ?></td></tr>
<tr><td><span class="sm-mono"><?= sp_e($sp_cfg['mqtt_topic']) ?>/ok</span></td><td><?= sp_t('MQTT.B_OK') ?></td></tr>
</table>
<?= sp_t('LOX.S2_EMPFEHLUNG') ?>
</div>

<div class="sm-step"><b><?= sp_e(sp_t('LOX.S3_TITEL')) ?></b><br>
<?= sp_t('LOX.S3_TEXT') ?>
<table class="sm-tbl">
<tr><th><?= sp_e(sp_t('LOX.T_ADRESSE')) ?></th>
    <td colspan="2"><span class="sm-mono"><?= sp_e($sp_basis) ?>?token=<?= sp_e($sp_token) ?>&amp;aktion=status</span></td></tr>
<tr><th><?= sp_e(sp_t('LOX.T_TITEL')) ?></th><th><?= sp_e(sp_t('LOX.T_BEFEHL')) ?></th><th><?= sp_e(sp_t('LOX.T_BEDEUTUNG')) ?></th></tr>
<?php foreach (sp_status_felder() as $sp_feld => $sp_info) { ?>
<tr><td><span class="sm-mono">SPRACHSTEUERUNG_<?= sp_e($sp_feld) ?></span></td>
    <td><span class="sm-mono">\i<?= sp_e($sp_feld) ?>=\i\v</span></td>
    <td><?= sp_t($sp_info[1]) ?></td></tr>
<?php } ?>
</table>
<div class="sm-knopfreihe">
  <form action="index.php" method="post">
    <input data-role="none" type="hidden" name="activetab" value="tab-loxone">
    <input data-role="none" type="hidden" name="vorlage" value="1">
    <button data-role="none" class="sm-btn sm-b-lesen" type="submit"><?= sp_e(sp_t('LOX.K_VORLAGE')) ?></button>
  </form>
</div>
<div class="sm-legende"><span><i class="sm-punkt sm-b-lesen"></i> <?= sp_t('LEGENDE.LESEN') ?></span></div>
</div>

<div class="sm-step"><b><?= sp_e(sp_t('LOX.S4_TITEL')) ?></b><br>
<?= sp_t('LOX.S4_TEXT') ?>
<table class="sm-tbl">
<tr><th><?= sp_e(sp_t('ALLG.EIGENSCHAFT')) ?></th><th><?= sp_e(sp_t('ALLG.WERT')) ?></th></tr>
<tr><td><?= sp_e(sp_t('LOX.T_VA_ADRESSE')) ?></td><td><span class="sm-mono">http://<?= sp_e($sp_host) ?></span></td></tr>
<tr><td><?= sp_e(sp_t('LOX.T_VA_ANSAGE')) ?></td>
    <td><span class="sm-mono">/plugins/<?= sp_e($sp_p['plugin']) ?>/index.php?token=<?= sp_e($sp_token) ?>&amp;aktion=sprechen&amp;text=Das%20Garagentor%20steht%20offen</span></td></tr>
<tr><td><?= sp_e(sp_t('LOX.T_VA_SATZ')) ?></td>
    <td><span class="sm-mono">/plugins/<?= sp_e($sp_p['plugin']) ?>/index.php?token=<?= sp_e($sp_token) ?>&amp;aktion=satz&amp;text=schalte%20das%20licht%20im%20wohnzimmer%20aus</span></td></tr>
</table>
<?= sp_t('LOX.S4_ANSAGE') ?>
</div>

<div class="sm-step"><b><?= sp_e(sp_t('LOX.S5_TITEL')) ?></b>
<table class="sm-tbl">
<tr><th><?= sp_e(sp_t('ALLG.EIGENSCHAFT')) ?></th><th><?= sp_e(sp_t('ALLG.WERT')) ?></th></tr>
<tr><td><?= sp_e(sp_t('LOX.T_TOKEN')) ?></td><td><span class="sm-mono"><?= sp_e($sp_token) ?></span></td></tr>
</table>
<?= sp_t('LOX.S5_TEXT') ?>
<div class="sm-knopfreihe">
  <form action="index.php" method="post">
    <input data-role="none" type="hidden" name="activetab" value="tab-loxone">
    <button data-role="none" class="sm-btn sm-b-aktion" type="submit" name="token_neu" value="1"><?= sp_e(sp_t('LOX.K_TOKEN_NEU')) ?></button>
  </form>
</div>
<div class="sm-legende"><span><i class="sm-punkt sm-b-aktion"></i> <?= sp_t('LEGENDE.AKTION_TOKEN') ?></span></div>
</div>

<?php
/**
 * Die komplette Baustein-Liste. Pflicht im Hausstandard.
 *
 * Nachgebaut wird: ein gesprochener Schaltbefehl wirkt auf eine Lichtgruppe,
 * und der Ausfall der Sprachkette wird gemeldet. Loxone Config fuehrt alle
 * Bausteine in der Baustein-Suche (F5).
 */
function sp_bausteine()
{
    return array(
        array(1,  'BAUSTEIN.T_VE',      'BAUSTEIN.N01', 'BAUSTEIN.P01', '&mdash;'),
        array(2,  'BAUSTEIN.T_VE',      'BAUSTEIN.N02', 'BAUSTEIN.P02', '&mdash;'),
        array(3,  'BAUSTEIN.T_VET',     'BAUSTEIN.N03', 'BAUSTEIN.P03', '&mdash;'),
        array(4,  'BAUSTEIN.T_VET',     'BAUSTEIN.N04', 'BAUSTEIN.P04', '&mdash;'),
        array(5,  'BAUSTEIN.T_VERGL',   'BAUSTEIN.N05', 'BAUSTEIN.P05', 'I1 &larr; #3'),
        array(6,  'BAUSTEIN.T_VERGL',   'BAUSTEIN.N06', 'BAUSTEIN.P06', 'I1 &larr; #3'),
        array(7,  'BAUSTEIN.T_UND',     'BAUSTEIN.N07', '',             'I1 &larr; #5, I2 &larr; #4'),
        array(8,  'BAUSTEIN.T_UND',     'BAUSTEIN.N08', '',             'I1 &larr; #6, I2 &larr; #4'),
        array(9,  'BAUSTEIN.T_LICHT',   'BAUSTEIN.N09', 'BAUSTEIN.P09', 'AI &larr; #7, AUS &larr; #8'),
        array(10, 'BAUSTEIN.T_SWS',     'BAUSTEIN.N10', 'BAUSTEIN.P10', 'I &larr; #2'),
        array(11, 'BAUSTEIN.T_NICHT',   'BAUSTEIN.N11', '',             'I &larr; #1'),
        array(12, 'BAUSTEIN.T_ODER',    'BAUSTEIN.N12', '',             'I1 &larr; #10, I2 &larr; #11'),
        array(13, 'BAUSTEIN.T_EVZ',     'BAUSTEIN.N13', 'BAUSTEIN.P13', 'I &larr; #12'),
        array(14, 'BAUSTEIN.T_BENACHR', 'BAUSTEIN.N14', 'BAUSTEIN.P14', 'I &larr; #13'),
        array(15, 'BAUSTEIN.T_VA',      'BAUSTEIN.N15', 'BAUSTEIN.P15', 'I &larr; ' . sp_t('BAUSTEIN.EREIGNIS')),
    );
}
?>
<div class="sm-step"><b><?= sp_e(sp_t('LOX.S6_TITEL')) ?></b><br>
<?= sp_t('LOX.S6_TEXT') ?>
<table class="sm-tbl">
<tr><th>#</th><th><?= sp_e(sp_t('LOX.T_BAUSTEIN')) ?></th><th><?= sp_e(sp_t('LOX.T_NAMENSVORSCHLAG')) ?></th>
    <th><?= sp_e(sp_t('LOX.T_PARAMETER')) ?></th><th><?= sp_e(sp_t('LOX.T_EINGAENGE')) ?></th></tr>
<?php foreach (sp_bausteine() as $sp_b) { ?>
<tr><td><?= (int) $sp_b[0] ?></td><td><?= sp_t($sp_b[1]) ?></td><td><?= sp_t($sp_b[2]) ?></td>
    <td><?= $sp_b[3] !== '' ? sp_t($sp_b[3]) : '&mdash;' ?></td><td><?= $sp_b[4] ?></td></tr>
<?php } ?>
</table>
<?= sp_t('LOX.S6_ERLAEUTERUNG') ?>
</div>

<div class="sm-step"><b><?= sp_e(sp_t('LOX.S7_TITEL')) ?></b><br>
<?= sp_t('LOX.S7_TEXT') ?>
<table class="sm-tbl">
<tr><th><?= sp_e(sp_t('LOX.T_PRUEFUNG')) ?></th><th><?= sp_e(sp_t('LOX.T_ERWARTUNG')) ?></th></tr>
<tr><td><span class="sm-mono"><?= sp_e($sp_basis) ?>?token=<?= sp_e($sp_token) ?>&amp;aktion=status</span></td>
    <td><span class="sm-mono">SPRACHSTEUERUNG;OK=1;SATELLITEN=...</span></td></tr>
<tr><td><span class="sm-mono"><?= sp_e($sp_basis) ?>?aktion=status</span></td>
    <td><span class="sm-mono">FEHLER;OK=0;GRUND=TOKEN</span> (HTTP 403)</td></tr>
<tr><td><span class="sm-mono"><?= sp_e($sp_basis) ?>?token=<?= sp_e($sp_token) ?>&amp;aktion=quatsch</span></td>
    <td><span class="sm-mono">FEHLER;OK=0;GRUND=UNBEKANNTE_AKTION</span> (HTTP 400)</td></tr>
</table>
</div>
</div>

<!-- ================= Reiter: Test ================= -->
<div class="sm-seite<?= $sp_tab === 'tab-test' ? ' sm-active' : '' ?>" id="tab-test">
<h2><?= sp_e(sp_t('TEST.H_SELBSTPRUEFUNG')) ?></h2>
<p class="sm-hilfe"><?= sp_t('TEST.EINLEITUNG') ?></p>
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

<h2><?= sp_e(sp_t('TEST.H_VERLAUF')) ?></h2>
<p class="sm-hilfe"><?= sp_t('TEST.VERLAUF_ERKLAERUNG') ?></p>
<?php if (!$sp_verlauf) { ?>
<div class="sm-hinweis"><?= sp_t('TEST.KEIN_VERLAUF') ?></div>
<?php } else { ?>
<table class="sm-tbl">
<tr><th><?= sp_e(sp_t('TEST.T_ZEIT')) ?></th><th><?= sp_e(sp_t('TEST.T_VERSTANDEN')) ?></th>
    <th><?= sp_e(sp_t('TEST.T_ABSICHT')) ?></th><th><?= sp_e(sp_t('TEST.T_QUELLE')) ?></th>
    <th><?= sp_e(sp_t('TEST.T_ANTWORT')) ?></th></tr>
<?php foreach (array_slice($sp_verlauf, 0, 20) as $sp_v2) { ?>
<tr><td><?= sp_e(date('H:i:s', (int) (isset($sp_v2['ts']) ? $sp_v2['ts'] : 0))) ?></td>
    <td><span class="sm-mono"><?= sp_e((string) (isset($sp_v2['satz']) ? $sp_v2['satz'] : '')) ?></span></td>
    <td><?= !empty($sp_v2['ok']) ? sp_e((string) $sp_v2['absicht'] . '/' . (string) $sp_v2['aktion']) : '<span class="sm-aus">' . sp_e((string) (isset($sp_v2['grund']) ? $sp_v2['grund'] : '')) . '</span>' ?></td>
    <td><?= sp_e((string) (isset($sp_v2['quelle']) ? $sp_v2['quelle'] : '')) ?></td>
    <td><?= sp_e((string) (isset($sp_v2['antwort']) ? $sp_v2['antwort'] : '')) ?></td></tr>
<?php } ?>
</table>
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
</div>

<h3><?= sp_e(sp_t('TEST.H_TECHNIK')) ?></h3>
<div class="sm-knopfreihe">
  <form action="index.php" method="post">
    <input data-role="none" type="hidden" name="activetab" value="tab-test">
    <button data-role="none" class="sm-btn sm-b-technik" type="submit" name="selbsttest" value="1"><?= sp_e(sp_t('TEST.K_SELBSTTEST')) ?></button>
  </form>
  <a class="sm-btn sm-b-technik" href="<?= sp_e($sp_basis) ?>?token=<?= sp_e($sp_token) ?>&amp;aktion=roh" target="_blank"><?= sp_e(sp_t('TEST.K_ROH')) ?></a>
</div>
<?php if ($sp_ausgabe !== '') { ?>
<div class="sm-pre"><?= sp_e($sp_ausgabe) ?></div>
<?php } ?>

<h3><?= sp_e(sp_t('TEST.H_SCHALTEN')) ?></h3>
<div class="sm-warnung"><?= sp_t('TEST.SCHALTEN_WARNUNG') ?></div>
<form action="index.php" method="post">
<input data-role="none" type="hidden" name="activetab" value="tab-test">
<div class="sm-feld">
  <label for="test_satz"><?= sp_e(sp_t('TEST.L_SATZ')) ?></label>
  <input data-role="none" type="text" id="test_satz" name="test_satz" value="schalte das Licht im Wohnzimmer ein">
  <div class="sm-hilfe"><?= sp_t('TEST.H_SATZ') ?></div>
</div>
<div class="sm-feld">
  <label for="test_ansage"><?= sp_e(sp_t('TEST.L_ANSAGE')) ?></label>
  <input data-role="none" type="text" id="test_ansage" name="test_ansage" value="Die Sprachsteuerung ist bereit.">
</div>
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
<div class="sm-legende"><span><i class="sm-punkt sm-b-aktion"></i> <?= sp_t('LEGENDE.AKTION_LOG') ?></span></div>
<div class="sm-knopfreihe">
  <form action="index.php" method="post">
    <input data-role="none" type="hidden" name="activetab" value="tab-log">
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
		r.addEventListener('click', function (e) { e.preventDefault(); zeige(r.dataset.ziel); });
	});
	zeige(<?= json_encode($sp_tab) ?>);
})();
</script>
<?php
if ($sp_rahmen) {
    LBWeb::lbfooter();
}
