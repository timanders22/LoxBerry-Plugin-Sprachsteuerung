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

/* Welche Lage gilt, entscheidet der eigene Ablageort, nicht die Reihenfolge
 * der Versuche: liegt diese Datei unter .../plugins/<ordner>, ist sie
 * installiert (Bibliothek unter <home>/webfrontend/html/plugins/<ordner>),
 * sonst liegt sie in einem ausgepackten Archiv (../html/). Bis 0.11.9 wurden
 * drei Kandidaten der Reihe nach probiert, zwei davon VOR der eigenen
 * Bibliothek - aus einem Archiv unter / war das
 * /html/plugins/htmlauth/sp_lib.php ab der Laufwerkswurzel, und was dort lag,
 * lief als Bibliothek (gemessen am 25.09.2026 in WSL im chroot,
 * Pruefung-Sprachsteuerung-0.11.10, Fall P2). Bauart ZendureSolarFlow 0.9.26. */
$sp_gefunden = false;
if (basename(dirname(__DIR__)) === 'plugins') {
    $sp_kandidaten = array(dirname(dirname(dirname(__DIR__))) . '/html/plugins/' . basename(__DIR__) . '/sp_lib.php');
} else {
    $sp_kandidaten = array(dirname(__DIR__) . '/html/sp_lib.php');
}
foreach ($sp_kandidaten as $sp_kandidat) {
    if (is_file($sp_kandidat)) { require_once $sp_kandidat; $sp_gefunden = true; break; }
}
if (!$sp_gefunden) {
    echo '<p><b>Fehler:</b> sp_lib.php wurde nicht gefunden. Bitte das Plugin neu installieren.</p>';
    exit;
}
/* Die Feldregeln (0.12.0) liegen neben der Bibliothek, und die Bibliothek
 * bindet sie selbst ein. Hier nur fuer den Fall, dass sie es (noch) nicht
 * tut - require_once laedt dieselbe Datei kein zweites Mal. */
if (!function_exists('sp_cfg_pruefen')) {
    require_once dirname($sp_kandidat) . '/sp_pruefen.php';
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

/* ================= Skalare Parameter (F14, 0.12.0) =================
 *
 * Ein Feld, das eine Zeichenkette sein muss, kann als Liste ankommen: ein
 * fremdes oder veraendertes Formular schickt form[]=x. (string) darauf ist
 * die Warnung "Array to string conversion" - und mit display_errors steht
 * sie VOR dem Location-Kopf, die Umleitung nach dem Speichern scheitert
 * ("headers already sent"). Eine Liste gilt deshalb wie ein fehlendes Feld.
 * Bis 0.11.15 standen hier (string)-Guesse an ueber dreissig Stellen. */
function sp_text_param($quelle, $name, $vorgabe = '')
{
    return (is_array($quelle) && isset($quelle[$name]) && is_string($quelle[$name])) ? $quelle[$name] : $vorgabe;
}
function sp_post_text($name, $vorgabe = '')
{
    return sp_text_param($_POST, $name, $vorgabe);
}
function sp_get_text($name, $vorgabe = '')
{
    return sp_text_param($_GET, $name, $vorgabe);
}
/* Eine Zeile einer Tabellenspalte (name[]): die Zeichenkette, '' wenn sie
 * fehlt, false wenn dort keine Zeichenkette steht. */
function sp_post_zeile($name, $i)
{
    if (!isset($_POST[$name]) || !is_array($_POST[$name]) || !array_key_exists($i, $_POST[$name])) { return ''; }
    return is_string($_POST[$name][$i]) ? $_POST[$name][$i] : false;
}
/* Ist in einer Spalte von Haken (name[i]=1) die Zeile i angehakt? */
function sp_post_haken($name, $i)
{
    return isset($_POST[$name]) && is_array($_POST[$name]) && !empty($_POST[$name][$i]);
}

$sp_tab = 'tab-' . $sp_reiter_ids[0];
if (preg_match($sp_muster, sp_post_text('activetab'))) {
    $sp_tab = sp_post_text('activetab');
} elseif (preg_match($sp_muster, 'tab-' . sp_get_text('form'))) {
    $sp_tab = 'tab-' . sp_get_text('form');
}

$sp_meldungen = array();
$sp_fehler = array();
/* Hinweise sind Beanstandungen, die nichts blockieren.
 * Der Anlass: 'Der Antwortweg fuehrt ueber Loxone, aber es ist keine
 * Adresse fuer die Audioausgabe eingetragen' steht auf JEDER frischen
 * Anlage da (Vorgaben: antwortweg=beide, tts.mode=musicserver, tts.ip='').
 * In $sp_fehler verhinderte er das Speichern SAEMTLICHER Felder - auch
 * des Whisper-Rechnernamens, der damit nichts zu tun hat.
 * REGELN_2: 'Beanstandungen melden, nicht das ganze Speichern verhindern'. */
$sp_hinweise = array();
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
        $sp_behalten = sp_post_text('activetab');
        $_POST = array();
        if ($sp_behalten !== '') { $_POST['activetab'] = $sp_behalten; }
        $sp_post = false;
    }
}

/*
 * Fuer Felder, die KEIN Freitext sind: Rechnernamen, Anschlussnummern,
 * Kennungen, Auswahlwerte. Dort haben Anfuehrungszeichen nichts zu suchen.
 */
$sp_sauber = function ($feld) {
    return trim(preg_replace('/[\x00-\x1F\x7F"\']/', '', sp_post_text($feld)));
};

/*
 * Fuer Freitext: nur Steuerzeichen weg, Anfuehrungszeichen bleiben.
 * Eine Bezeichnung wie  Kueche "oben"  soll so stehen bleiben duerfen.
 * Keine Zeichenkette (name[][]=) ergibt den Leerwert, keine Warnung.
 */
$sp_freitext = function ($wert) {
    return trim((string) preg_replace('/\s+/u', ' ',
        (string) preg_replace('/[\x00-\x1F\x7F]/u', ' ', is_scalar($wert) ? (string) $wert : '')));
};

/*
 * Nr. 19: Wert eines Feldes, das KEIN Freitext ist - null, wenn es nicht
 * mitgeschickt wurde; false, wenn es keine Zeichenkette ist oder der Filter
 * oben es veraendert haette (Anfuehrungs- oder Steuerzeichen). Bis 0.11.12
 * wurde es still gesaeubert und gespeichert. Leerraum am Rand faellt still.
 */
$sp_x = function ($feld) use ($sp_sauber) {
    if (!isset($_POST[$feld])) { return null; }
    if (!is_string($_POST[$feld])) { return false; }
    $roh = trim($_POST[$feld]);
    return $roh === $sp_sauber($feld) ? $roh : false;
};

/* ================= PRG und X-2 (Entscheidungen Nr. 16 und 19) =================
 *
 * Bis 0.11.12 wurde die Seite unmittelbar nach dem POST gezeigt: ein
 * Neuladen schickte das Formular noch einmal ab (Regeln/04, "Jeder
 * POST-Handler endet mit einer Umleitung"). Jetzt endet jede POST-Anfrage an
 * EINER Stelle (vor "Laden") mit 303; das Ergebnis reist als Einmalmeldung:
 * data/plugins/<ordner>/einmalmeldung.json, 0600, hoechstens 120 s alt, nur
 * beim GET gelesen und dabei geloescht - beim POST hat sie nichts zu suchen,
 * sonst verhinderte ein alter Fehlertext das naechste Speichern.
 *
 * X-2: nach einer Beanstandung reisen die Eingaben des EINEN Formulars mit
 * (nur die Felder der Liste, Zeichenketten, gueltiges UTF-8, hoechstens 4096
 * Byte) und die Namen der beanstandeten Felder. NIE mit: Miniserver-Adresse
 * (kann Zugangsdaten tragen), Alexa-Sprechtoken, Google-Sprechtoken
 * (Chromecast 4 Lox NG), ESPHome-Schluessel,
 * Formularmerkmal - sie stehen in keiner Liste. Nach erfolgreichem
 * Speichern zeigt der GET die gespeicherten Werte.
 * Bauart: LoxBerry-Plugin-Abfahrtsassistent-1.6.19 (abf_flash_*, abf_w).
 *
 * Seit 0.12.0 (F15) traegt jede Umleitung ihre eigene Kennung (?m=<16 hex>),
 * und jede Einmalmeldung liegt in ihrer eigenen Datei. Bis 0.11.15 gab es
 * EINE Datei fuer alle: zwei Reiter oder zwei Bediener, die kurz
 * nacheinander speicherten, bekamen die Meldung des anderen - oder keine,
 * weil der erste GET sie schon geloescht hatte. Ohne Kennung im Aufruf wird
 * nichts gelesen. Liegengebliebene Dateien (Umleitung nie abgerufen) raeumt
 * das naechste Schreiben ab, sobald sie aelter als 120 s sind.
 *
 * Der Rohtext der Satzdatei reist nach einer Beanstandung in einer eigenen
 * Datei derselben Kennung mit (F5): in den 4096 Byte eines Feldes der
 * Einmalmeldung hat er keinen Platz, und bis 0.11.15 war er nach einem
 * Komma zu viel komplett weg.
 * ================================================================== */
define('SP_FLASH_ALTER', 120);

function sp_flash_datei($kennung, $art = 'meldung')
{
    return sp_paths()['datadir'] . '/einmal' . ($art === 'rohtext' ? 'rohtext_' . $kennung . '.txt'
                                                                   : 'meldung_' . $kennung . '.json');
}
/* Liegengebliebenes wegraeumen - auch die eine Datei der Fassungen bis 0.11.15. */
function sp_flash_aufraeumen()
{
    $d = sp_paths()['datadir'];
    @unlink($d . '/einmalmeldung.json');
    foreach (array_merge((array) glob($d . '/einmalmeldung_*.json'), (array) glob($d . '/einmalrohtext_*.txt')) as $f) {
        if (is_string($f) && is_file($f) && time() - (int) @filemtime($f) > SP_FLASH_ALTER) { @unlink($f); }
    }
}
/* Eine Datei mit 0600 schreiben, bevor der Inhalt hineinkommt (er kann
 * Zieladressen samt Zugangsdaten tragen). Rueckgabe: true/false. */
function sp_flash_ablegen($pfad, $inhalt)
{
    $ordner = dirname($pfad);
    if (!is_dir($ordner) && !@mkdir($ordner, 0775, true) && !is_dir($ordner)) { return false; }
    $tmp = $pfad . '.tmp.' . getmypid();
    if (@file_put_contents($tmp, '') === false) { return false; }
    @chmod($tmp, 0600);
    if (@file_put_contents($tmp, $inhalt) !== strlen($inhalt) || !@rename($tmp, $pfad)) {
        @unlink($tmp);
        return false;
    }
    return true;
}
/* Rueckgabe: die Kennung oder '' (nicht geschrieben). */
function sp_flash_schreiben(array $inhalt, $rohtext = null)
{
    sp_flash_aufraeumen();
    $kennung = bin2hex(random_bytes(8));
    $inhalt['zeit'] = time();
    if ($rohtext !== null && sp_flash_ablegen(sp_flash_datei($kennung, 'rohtext'), (string) $rohtext)) {
        $inhalt['rohtext'] = 1;
    }
    /* JSON_INVALID_UTF8_SUBSTITUTE: eine Meldung mit kaputtem UTF-8 (etwa
     * die Antwortzeile eines fremden Geraets) liess json_encode bis 0.11.15
     * scheitern - dann ging gar keine Meldung mit, auch die guten nicht. */
    $json = json_encode($inhalt, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    if ($json === false || !sp_flash_ablegen(sp_flash_datei($kennung), $json)) { return ''; }
    return $kennung;
}
function sp_flash_lesen($kennung)
{
    if (!preg_match('/^[0-9a-f]{16}\z/', (string) $kennung)) { return array(); }
    $f = sp_flash_datei($kennung);
    $r = sp_flash_datei($kennung, 'rohtext');
    $roh = is_file($r) ? (string) @file_get_contents($r) : null;
    @unlink($r);
    if (!is_file($f)) { return array(); }
    $d = json_decode((string) @file_get_contents($f), true);
    @unlink($f);
    if (!is_array($d) || !isset($d['zeit']) || time() - (int) $d['zeit'] > SP_FLASH_ALTER
        || (int) $d['zeit'] > time() + 5) {
        return array();
    }
    $d['rohtext'] = (!empty($d['rohtext']) && $roh !== null) ? $roh : null;
    return $d;
}
function sp_eingabe_felder($formular)
{
    $liste = array(
        'dienste' => array('whisper_host', 'whisper_port', 'piper_host', 'piper_port', 'wake_host',
                           'wake_port', 'llm_host', 'llm_port', 'llm_ein', 'antwort_sprechen',
                           'kontext_s', 'bestaetigung_s', 'antwortweg', 'tts_mode', 'tts_ip',
                           'tts_port', 'tts_zones', 'tts_volume', 'tts_lang', 'tts_stimme',
                           'cc_praefix', 'cc_ziel', 'alexa_geraet', 'alexa_laut',
                           'alexa_token_loeschen', 'google_geraet', 'google_laut',
                           'google_token_loeschen', 'probe_text',
                           // Runde 2: Sonos4Lox (S1), Musik leiser/steuern (S5, S6)
                           'tts_sonos_zone', 'tts_sonos_laut', 'musik_ducken', 'musik_ducken_laut',
                           'musik_steuern'),
        'ansagen' => array('tts_template', 'ruhe_ein', 'ruhe_von', 'ruhe_bis', 'ansage_abstand_s',
                           'ansage_je_tag', 'miniserver_url_loeschen', 'wakeword', 'wakeword_frei',
                           'sprache', 'wartezeit', 'verlauf_zeilen'),
        'mqtt'    => array('mqtt_ein', 'mqtt_topic', 'herzschlag_s', 'mqtt_dauer_ein'),
        'modelle' => array('whisper_modell', 'whisper_modell_frei', 'piper_stimme', 'piper_stimme_frei',
                           'llm_modell', 'llm_modell_frei'),
        'mikros'  => array('m_name', 'm_art', 'm_host', 'm_port', 'm_raum', 'm_zone', 'm_alt',
                           'm_schluessel_loeschen',
                           // S2: die Ausgabe dieses Raums (leer = wie global)
                           'm_a_alexa', 'm_a_google', 'm_a_cc', 'm_a_sonos'),
        // S7: Optionen im Reiter Dienste
        'dienste_opt' => array('docker_neustart'),
        /* F5: die Zielmaske. z_url und z_lesen reisen nur ohne Zugangsdaten
         * mit (sp_eingaben_sammeln()) - wie die Miniserver-Adresse. */
        'ziele'   => array('z_alt', 'z_key', 'z_name', 'z_alias', 'z_thema', 'z_einheit', 'z_url',
                           'z_lesen', 'z_min', 'z_max', 'z_schritt', 'z_bestaetigen', 'z_loeschen'),
    );
    return isset($liste[$formular]) ? $liste[$formular] : array();
}
/* Ein einzelner Wert, der mitreisen darf. */
function sp_eingabe_tauglich($w)
{
    return is_string($w) && strlen($w) <= 4096 && preg_match('//u', $w) === 1;
}
/* Ein Feld beanstanden (bei Tabellenzeilen mit Index); ohne Argument die Liste. */
function sp_bean($feld = null, $idx = null)
{
    static $liste = array();
    if ($feld !== null) {
        $n = (string) $feld . ($idx !== null ? '[' . (int) $idx . ']' : '');
        if (!in_array($n, $liste, true)) { $liste[] = $n; }
    }
    return $liste;
}
/* Die Eingaben eines Formulars aus $_POST - nur die Felder der Liste.
 * Tabellen: bis 0.11.15 hoechstens 8 Zeilen (Mikrofone); die Zielmaske hat
 * so viele Zeilen wie Ziele, eine Sicherung kann mehr als 8 Mikrofone tragen. */
function sp_eingaben_sammeln($formular)
{
    $werte = array();
    $hoechstens = $formular === 'ziele' ? 999 : 32;
    foreach (sp_eingabe_felder($formular) as $f) {
        if (!isset($_POST[$f])) { continue; }
        $w = $_POST[$f];
        if (is_array($w)) {
            $zeilen = array();
            foreach ($w as $k => $v) {
                if (count($zeilen) >= $hoechstens || !preg_match('/^\d{1,3}\z/', (string) $k)) { continue; }
                if (($f === 'z_url' || $f === 'z_lesen') && is_string($v) && preg_match('#^[a-z]+://[^/]*@#i', $v)) {
                    continue;
                }
                if (sp_eingabe_tauglich($v)) { $zeilen[(string) (int) $k] = $v; }
            }
            $werte[$f] = $zeilen;
        } elseif (sp_eingabe_tauglich($w)) {
            $werte[$f] = $w;
        }
    }
    return array('formular' => $formular, 'werte' => $werte, 'falsch' => sp_bean());
}
/* Beim GET: die Eingaben aus der Einmalmeldung pruefen und ablegen. */
function sp_eingaben($setzen = null)
{
    static $e = null;
    if ($setzen !== null) {
        $e = null;
        if (is_array($setzen) && isset($setzen['formular'], $setzen['werte'], $setzen['falsch'])
            && is_string($setzen['formular']) && is_array($setzen['werte']) && is_array($setzen['falsch'])) {
            $erlaubt = sp_eingabe_felder($setzen['formular']);
            $werte = array();
            foreach ($setzen['werte'] as $f => $w) {
                if (!in_array((string) $f, $erlaubt, true)) { continue; }
                if (is_array($w)) {
                    $werte[$f] = array();
                    foreach ($w as $k => $v) { if (sp_eingabe_tauglich($v)) { $werte[$f][(string) $k] = $v; } }
                } elseif (sp_eingabe_tauglich($w)) {
                    $werte[$f] = $w;
                }
            }
            $falsch = array();
            foreach ($setzen['falsch'] as $n) {
                if (is_string($n) && preg_match('/^[a-z_]+(\[\d{1,3}\])?\z/', $n)) { $falsch[] = $n; }
            }
            if ($erlaubt) { $e = array('formular' => $setzen['formular'], 'werte' => $werte, 'falsch' => $falsch); }
        }
    }
    return $e;
}
/* Gilt fuer dieses Feld eine Eingabe? Nur, wenn es zum beanstandeten Formular gehoert. */
function sp_x2_aktiv($feld)
{
    $e = sp_eingaben();
    return $e !== null && in_array($feld, sp_eingabe_felder($e['formular']), true);
}
/* Wert eines Feldes: die Eingabe, sonst der gespeicherte Wert. */
function sp_x2_wert($feld, $gespeichert, $idx = null)
{
    if (sp_x2_aktiv($feld)) {
        $e = sp_eingaben();
        $w = isset($e['werte'][$feld]) ? $e['werte'][$feld] : null;
        if ($idx !== null) { $w = (is_array($w) && isset($w[(string) (int) $idx])) ? $w[(string) (int) $idx] : null; }
        if (is_string($w)) { return $w; }
    }
    return (string) $gespeichert;
}
/* Haken: nach einer Beanstandung so, wie er abgeschickt wurde. */
function sp_x2_haken($feld, $gespeichert)
{
    if (!sp_x2_aktiv($feld)) { return (bool) $gespeichert; }
    $e = sp_eingaben();
    return isset($e['werte'][$feld]);
}
/* Markierung eines beanstandeten Felds (Attribute, schon maskiert). */
function sp_x2_mark($feld, $idx = null)
{
    $e = sp_eingaben();
    $n = (string) $feld . ($idx !== null ? '[' . (int) $idx . ']' : '');
    return ($e !== null && in_array($n, $e['falsch'], true)) ? ' class="sm-beanstandet" aria-invalid="true"' : '';
}
/* Tabellen nach einer Beanstandung: die Zeilennummern, die abgeschickt
 * wurden (aus der Spalte $feld), sonst null - dann gilt der gespeicherte Stand. */
function sp_x2_zeilen($feld)
{
    if (!sp_x2_aktiv($feld)) { return null; }
    $e = sp_eingaben();
    if (!isset($e['werte'][$feld]) || !is_array($e['werte'][$feld])) { return null; }
    $z = array_map('intval', array_keys($e['werte'][$feld]));
    sort($z);
    return $z;
}
/* Haken in einer Tabellenspalte (name[i]) nach einer Beanstandung. */
function sp_x2_zeilenhaken($feld, $idx, $gespeichert)
{
    if (!sp_x2_aktiv($feld)) { return (bool) $gespeichert; }
    $e = sp_eingaben();
    return isset($e['werte'][$feld][(string) (int) $idx]);
}

/* Beim GET: das Ergebnis der vorigen Anfrage. */
$sp_formular_x2 = '';
$sp_x2_immer = false;      // X-2 auch ohne Beanstandung (Probehoeren, F4)
$sp_offen_einzeln = false; // "Einzeln verwalten" nach der Umleitung offen (F10)
$sp_rohtext_x2 = null;     // der beanstandete Rohtext der Satzdatei (F5)
if ((isset($_SERVER['REQUEST_METHOD']) ? $_SERVER['REQUEST_METHOD'] : '') !== 'POST') {
    $sp_fl = sp_flash_lesen(sp_get_text('m'));
    $sp_offen_einzeln = isset($sp_fl['offen']) && $sp_fl['offen'] === 'einzeln';
    if (isset($sp_fl['rohtext']) && is_string($sp_fl['rohtext'])) { $sp_rohtext_x2 = $sp_fl['rohtext']; }
    foreach ((isset($sp_fl['meldungen']) && is_array($sp_fl['meldungen']) ? $sp_fl['meldungen'] : array()) as $sp_z) {
        if (is_string($sp_z)) { $sp_meldungen[] = $sp_z; }
    }
    foreach ((isset($sp_fl['fehler']) && is_array($sp_fl['fehler']) ? $sp_fl['fehler'] : array()) as $sp_z) {
        if (is_string($sp_z)) { $sp_fehler[] = $sp_z; }
    }
    foreach ((isset($sp_fl['hinweise']) && is_array($sp_fl['hinweise']) ? $sp_fl['hinweise'] : array()) as $sp_z) {
        if (is_string($sp_z)) { $sp_hinweise[] = $sp_z; }
    }
    if (isset($sp_fl['ausgabe']) && is_string($sp_fl['ausgabe'])) { $sp_ausgabe = $sp_fl['ausgabe']; }
    if (isset($sp_fl['tab']) && is_string($sp_fl['tab']) && preg_match($sp_muster, $sp_fl['tab'])) {
        $sp_tab = $sp_fl['tab'];
    }
    sp_eingaben(isset($sp_fl['eingaben']) ? $sp_fl['eingaben'] : array());
}

/* ================= Hilfen der Oberflaeche (0.12.0) ================= */

/* Die Fassung dieses Plugins fuer den Kopf (E7): zuerst LoxBerry selbst,
 * dann die Pluginliste (sp_plugin_info, Bibliothek ab 0.12.0), zuletzt die
 * plugin.cfg eines ausgepackten Archivs. Nichts gefunden: ''. */
function sp_ui_fassung()
{
    if (class_exists('LBSystem', false) && method_exists('LBSystem', 'pluginversion')) {
        $v = (string) @LBSystem::pluginversion();
        if ($v !== '') { return $v; }
    }
    if (function_exists('sp_plugin_info')) {
        $i = sp_plugin_info(sp_paths()['plugin']);
        if (is_array($i) && isset($i['version']) && is_scalar($i['version']) && (string) $i['version'] !== '') {
            return (string) $i['version'];
        }
    }
    $cfg = dirname(dirname(__DIR__)) . '/plugin.cfg';
    if (is_file($cfg) && preg_match('/^VERSION=([0-9A-Za-z.\-]{1,30})\s*$/m', (string) @file_get_contents($cfg), $t)) {
        return $t[1];
    }
    return '';
}

/* E2: die letzten fuenf Staende der Satzdatei, je ein Stand VOR jedem
 * Speichern aus der Oberflaeche. Der Ordner liegt unter data/ (nicht
 * erreichbar von aussen), die Dateien mit 0600: ein Ziel kann eine
 * Miniserver-Adresse samt Zugangsdaten tragen (Feld url). */
define('SP_STAENDE', 5);
function sp_ui_staende_ordner()
{
    return sp_paths()['datadir'] . '/saetze_staende';
}
function sp_ui_stand_sichern()
{
    $quelle = sp_paths()['saetze'];
    if (!is_file($quelle)) { return false; }
    $roh = (string) @file_get_contents($quelle);
    $d = json_decode($roh, true);
    if (!is_array($d)) { return false; }
    $name = 'stand_' . date('Ymd_His') . '_' . bin2hex(random_bytes(2)) . '.json';
    if (!sp_flash_ablegen(sp_ui_staende_ordner() . '/' . $name, $roh)) { return false; }
    $alle = sp_ui_staende();
    foreach (array_slice($alle, SP_STAENDE) as $alt) { @unlink(sp_ui_staende_ordner() . '/' . $alt['datei']); }
    return true;
}
/* Neueste zuerst: array(datei, zeit, regeln, ziele). */
function sp_ui_staende()
{
    $aus = array();
    foreach ((array) glob(sp_ui_staende_ordner() . '/stand_*.json') as $f) {
        if (!is_string($f) || !preg_match('/^stand_(\d{8})_(\d{6})_[0-9a-f]{4}\.json\z/', basename($f), $t)) { continue; }
        $d = sp_json_lesen($f);
        $aus[] = array('datei' => basename($f), 'zeit' => (int) @filemtime($f),
                       'regeln' => isset($d['regeln']) && is_array($d['regeln']) ? count($d['regeln']) : 0,
                       'ziele' => isset($d['ziele']) && is_array($d['ziele']) ? count($d['ziele']) : 0);
    }
    usort($aus, function ($a, $b) { return strcmp($b['datei'], $a['datei']); });
    return $aus;
}
/* Die Satzdatei speichern - vorher den bisherigen Stand behalten. */
function sp_ui_saetze_speichern($d)
{
    sp_ui_stand_sichern();
    return sp_saetze_speichern($d);
}

/* E3: die Lautsprecher, die Chromecast4lox am Broker meldet - dieselbe
 * Quelle wie die Pruefzeile "Zusaetzliche Ansage" (sp_ansage_lage():
 * <praefix>/+/type, zurueckbehalten). Nur fuer die Auswahlliste; das Feld
 * bleibt Freitext. Die Antwort des Brokers wird 300 s behalten, damit der
 * Reiter Einstellungen nicht bei jedem Aufbau den Broker fragt. */
function sp_ui_cc_lautsprecher($praefix)
{
    $praefix = (string) $praefix;
    if (!sp_cc_praefix_ok($praefix) || !function_exists('sp_mqtt_retained_lesen')) { return array(); }
    $datei = sp_paths()['datadir'] . '/cc_lautsprecher.json';
    $c = sp_json_lesen($datei);
    if (isset($c['ts'], $c['praefix'], $c['liste']) && $c['praefix'] === $praefix && is_array($c['liste'])
        && time() - (int) $c['ts'] < 300 && (int) $c['ts'] <= time()) {
        return $c['liste'];
    }
    $liste = array();
    list($ok, , $werte) = sp_mqtt_retained_lesen(array($praefix . '/+/type'), 1.5);
    if ($ok && is_array($werte)) {
        foreach (array_keys($werte) as $thema) {
            $rest = substr((string) $thema, strlen($praefix) + 1);
            if (strpos((string) $thema, $praefix . '/') === 0 && substr($rest, -5) === '/type') {
                $g = substr($rest, 0, -5);
                if ($g !== '' && strpos($g, '/') === false) { $liste[] = $g; }
            }
        }
        sort($liste);
    }
    sp_json_schreiben($datei, array('ts' => time(), 'praefix' => $praefix, 'liste' => $liste));
    return $liste;
}

/* E1: der Verlauf als JSON fuer das Nachladen im Reiter Test. Nur lesend,
 * im angemeldeten Bereich, ohne Merkmal (ein GET aendert nichts). Die
 * Werte gehen roh hinaus; das Skript setzt sie als Text (textContent). */
if (!$sp_post && sp_get_text('ajax') === 'verlauf') {
    $sp_zeilen = array();
    foreach (array_slice(sp_verlauf(), 0, 20) as $sp_v2) {
        if (!is_array($sp_v2)) { continue; }
        $sp_f = function ($k) use ($sp_v2) { return isset($sp_v2[$k]) && is_scalar($sp_v2[$k]) ? (string) $sp_v2[$k] : ''; };
        $sp_zeilen[] = array(
            'zeit' => date('H:i:s', (int) $sp_f('ts')), 'satz' => $sp_f('satz'), 'mikrofon' => $sp_f('mikrofon'),
            'ok' => !empty($sp_v2['ok']) ? 1 : 0,
            'absicht' => !empty($sp_v2['ok']) ? $sp_f('absicht') . '/' . $sp_f('aktion') : $sp_f('grund'),
            'quelle' => $sp_f('quelle'), 'antwort' => $sp_f('antwort'));
    }
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode(array('ok' => 1, 'zeit' => time(), 'zeilen' => $sp_zeilen),
                     JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

/* ---------------- Protokoll und Mitschnitt herunterladen (E7) ----------------
 * Nur die beiden eigenen Dateien, fest aus sp_paths() - der Name kommt nicht
 * aus der Anfrage. Der Mitschnitt kann gesprochene Saetze tragen; deshalb
 * angemeldet und per POST mit Merkmal wie jeder andere Knopf. */
if ($sp_post && isset($_POST['log_holen'])) {
    $sp_was = sp_post_text('log_holen');
    $sp_datei = $sp_was === 'mitschnitt' ? $sp_p['mitschnitt'] : ($sp_was === 'protokoll' ? $sp_p['log'] : '');
    if ($sp_datei !== '' && is_file($sp_datei)) {
        header('Content-Type: text/plain; charset=utf-8');
        header('Content-Disposition: attachment; filename="sprachsteuerung_' . $sp_was . '_' . date('Ymd_Hi') . '.log"');
        header('Content-Length: ' . (int) filesize($sp_datei));
        readfile($sp_datei);
        exit;
    }
    $sp_fehler[] = sp_e(sp_t('UI012.LOG_FEHLT'));
    $sp_tab = 'tab-log';
}

/* ---------------- Vorlagen und Ausfuhren herunterladen ---------------- */
if ($sp_post && isset($_POST['vorlage'])) {
    $sp_was = sp_post_text('vorlage');
    $sp_paar = array('', '');
    if ($sp_was === 'eingang')      { $sp_paar = sp_vorlage(); }
    elseif ($sp_was === 'ausgang')  { $sp_paar = sp_vorlage_ausgang(); }
    elseif ($sp_was === 'ziele')    { $sp_paar = sp_vorlage_ziele(); }
    list($sp_name, $sp_inhalt) = $sp_paar;
    if (!in_array($sp_was, array('eingang', 'ausgang', 'ziele'), true)) {
        $sp_fehler[] = sp_t('TEST.M_UNBEKANNT');
        $sp_tab = 'tab-loxone';
    } elseif ($sp_inhalt === '') {
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
        || !isset($_FILES['sicherungsdatei']['tmp_name']) || !is_string($_FILES['sicherungsdatei']['tmp_name'])
        || !is_uploaded_file($_FILES['sicherungsdatei']['tmp_name'])) {
        $sp_fehler[] = sp_t('SICHER.FEHLER_KEINE_DATEI');
    } else {
        $sp_roh = (string) @file_get_contents($_FILES['sicherungsdatei']['tmp_name']);
        // Die Satzdatei vorher als Stand behalten (E2) - nur, wenn die
        // Sicherung angenommen wird; die Pruefung schreibt nichts.
        list($sp_ok) = sp_sicherung_lesen($sp_roh, true);
        if ($sp_ok) { sp_ui_stand_sichern(); }
        list($sp_ok, $sp_meld) = sp_sicherung_lesen($sp_roh);
        if ($sp_ok) { $sp_meldungen[] = sp_e($sp_meld); } else { $sp_fehler[] = sp_e($sp_meld); }
    }
    $sp_tab = 'tab-sentences';
}

/* Ein Feld nach der Regel aus sp_pruefen.php pruefen - EINE Stelle fuer
 * Formular und Sicherung (0.12.0, I2). $wert false (keine Zeichenkette,
 * oder ein Zeichen, das der Filter still entfernt haette) gilt als
 * unbrauchbar und bekommt den Text der Regel. Bei einem Befund: Meldung
 * (maskiert - die Regeln liefern Klartext), Feld markieren, false. */
$sp_regel = function ($pfad, $wert, $feld, $idx = null) use (&$sp_fehler) {
    $t = sp_wert_pruefen($pfad, $wert === false ? null : $wert);
    if ($t === '') { return true; }
    $sp_fehler[] = sp_e($t);
    sp_bean($feld, $idx);
    return false;
};

/* ---------------- MQTT speichern (eigener Reiter) ---------------- */
if ($sp_post && isset($_POST['mqtt_save'])) {
    $sp_cfg = sp_config();
    $sp_cfg['mqtt_ein'] = isset($_POST['mqtt_ein']) ? 1 : 0;
    // S3 (Runde 2): die Dauerverbindung des Dienstes zum Broker.
    $sp_cfg['mqtt_dauer_ein'] = isset($_POST['mqtt_dauer_ein']) ? 1 : 0;
    $sp_topic = $sp_x('mqtt_topic');
    if ($sp_regel('mqtt_topic', $sp_topic === null ? '' : $sp_topic, 'mqtt_topic')) {
        $sp_cfg['mqtt_topic'] = trim($sp_topic, '/');
    }
    // Die Grenzen aus templates/vorgaben.json (bis 0.11.15 fest 0..3600 hier).
    $sp_takt = $sp_x('herzschlag_s');
    if ($sp_regel('herzschlag_s', $sp_takt, 'herzschlag_s')) {
        $sp_cfg['herzschlag_s'] = (int) $sp_takt;
    }
    if ($sp_fehler) { $sp_formular_x2 = 'mqtt'; }
    if (!$sp_fehler) {
        if (sp_config_speichern($sp_cfg)) {
            $sp_meldungen[] = sp_t('EINST.GESPEICHERT');
            /* A9: die Abo-Datei fuer das Gateway V1 folgt dem Praefix. Ein
             * Fehler hier ist ein Hinweis - die Einstellungen sind gespeichert. */
            list($sp_ok, $sp_neu) = sp_mqtt_abo_schreiben($sp_cfg);
            if (!$sp_ok) { $sp_hinweise[] = sprintf(sp_t('UI013.ABO_DATEI_FEHLER'), sp_e(sp_mqtt_abo_datei())); }
            elseif ($sp_neu) { $sp_meldungen[] = sprintf(sp_t('UI013.ABO_DATEI_GESCHRIEBEN'), sp_e(trim(sp_mqtt_abo_inhalt($sp_cfg)))); }
        }
        else { $sp_fehler[] = sprintf(sp_t('EINST.FEHLER_SPEICHERN'), sp_e($sp_p['config'])); }
    }
    $sp_tab = 'tab-mqtt';
}

/* ---------------- Einstellungen speichern ----------------
 * F4 (0.12.0): "Stimme probehoeren" steht im selben Formular und schickt
 * dessen Felder mit - bis 0.11.15 speicherte jede Probe saemtliche
 * Einstellungen mit, auch halb eingetippte. Mit probe_stimme wird hier
 * nichts gespeichert; der Handler weiter unten spielt nur die Probe ab. */
if ($sp_post && isset($_POST['speichern']) && !isset($_POST['probe_stimme'])) {
    $sp_cfg = sp_config();
    /* WELCHES der beiden Formulare kam? Ein Feld, das nicht mitgeschickt
     * wurde, ist nicht leer - es wurde nicht angezeigt. Nur Ankreuzfelder
     * lassen sich davon nicht unterscheiden (ein nicht angehaktes schickt
     * ebenfalls nichts); fuer sie entscheidet das Kennzeichen. */
    $sp_formular = sp_post_text('formular');
    foreach (array('whisper', 'piper', 'wake', 'llm') as $sp_d) {
        // Nr. 19: ein leeres Feld behielt bis 0.11.12 still den alten
        // Rechnernamen - jetzt ist es eine Beanstandung (die Regel kennt das).
        if (isset($_POST[$sp_d . '_host'])) {
            $host = $sp_x($sp_d . '_host');
            if ($sp_regel($sp_d . '_host', $host, $sp_d . '_host')) { $sp_cfg[$sp_d . '_host'] = $host; }
        }
        if (isset($_POST[$sp_d . '_port'])) {
            $port = $sp_x($sp_d . '_port');
            if ($sp_regel($sp_d . '_port', $port, $sp_d . '_port')) { $sp_cfg[$sp_d . '_port'] = (int) $port; }
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
        $w = $sp_x($f);
        if ($sp_regel($f, $w, $f)) { $sp_cfg[$f] = (int) $w; }
    }
    // F13: eine Auswahl de/en (Satzdateien gibt es nur fuer diese beiden).
    if (isset($_POST['sprache'])) {
        $sp_spr = $sp_x('sprache');
        if ($sp_regel('sprache', $sp_spr, 'sprache')) { $sp_cfg['sprache'] = $sp_spr; }
    }
    /* Weckwort - dieselbe Regel wie bei den Modellen (F13): der Freitext
     * gewinnt, wenn er gefuellt ist, sonst gilt die Auswahl. Bis 0.11.15
     * galt der Freitext nur bei "eigener Wert"; stand die Auswahl auf einem
     * Listenwort, ging ein eingetipptes Weckwort still verloren.
     * Nr. 19: beide leer behielt bis 0.11.12 still das alte - jetzt eine
     * Beanstandung. ok_nabu und okay_nabu gelten beide (0.12.0, I4). */
    if (isset($_POST['wakeword']) || isset($_POST['wakeword_frei'])) {
        $sp_ww = $sp_x('wakeword');
        $sp_wwfrei = $sp_x('wakeword_frei');
        $sp_wwfeld = 'wakeword';
        if ($sp_wwfrei === false || (is_string($sp_wwfrei) && $sp_wwfrei !== '')) {
            $sp_ww = $sp_wwfrei;
            $sp_wwfeld = 'wakeword_frei';
        }
        if ($sp_ww === '' || $sp_ww === null) {
            $sp_regel('wakeword', '', 'wakeword');
            sp_bean('wakeword_frei');
        } elseif ($sp_regel('wakeword', $sp_ww, $sp_wwfeld)) {
            $sp_cfg['wakeword'] = $sp_ww;
        }
    }
    /* Die Miniserver-Adresse kann Zugangsdaten enthalten - deshalb NICHT
     * filtern, nur auf die Form pruefen. Und ein LEERES Feld loescht sie
     * nicht: bis 0.9.11 uebernahm der Handler den Leerwert unbesehen, ein
     * versehentlich geleertes Feld nahm damit die Anmeldung mit. Wer sie
     * wirklich entfernen will, setzt den Haken darunter. */
    $sp_url = trim(sp_post_text('miniserver_url'));
    if ($sp_formular === 'ansagen' && isset($_POST['miniserver_url_loeschen'])) {
        $sp_cfg['miniserver_url'] = '';
    } elseif ($sp_url !== '' && $sp_url !== sp_url_maskiert((string) $sp_cfg['miniserver_url'])) {
        if ($sp_regel('miniserver_url', $sp_url, 'miniserver_url')) {
            $sp_cfg['miniserver_url'] = $sp_url;
        }
    }
    /* Beide Haken stehen im Formular 'dienste'. Ohne diese Bedingung
     * haette jedes Speichern aus dem anderen Formular sie genullt -
     * abgeschaltetes Sprechen und abgeschaltetes Sprachmodell, ohne dass
     * jemand einen Haken angefasst haette. */
    if ($sp_formular === 'dienste') {
        $sp_cfg['llm_ein'] = isset($_POST['llm_ein']) ? 1 : 0;
        $sp_cfg['antwort_sprechen'] = isset($_POST['antwort_sprechen']) ? 1 : 0;
    }

    /* ---- Rueckweg nach Loxone ---- */
    if (isset($_POST['antwortweg'])) {
        $sp_weg = $sp_x('antwortweg');
        if ($sp_regel('antwortweg', $sp_weg, 'antwortweg')) { $sp_cfg['antwortweg'] = $sp_weg; }
    }
    $sp_tts = is_array(isset($sp_cfg['tts']) ? $sp_cfg['tts'] : null) ? $sp_cfg['tts'] : array();
    /* Die Felder des tts-Blocks, die keine Freitexte sind: Formularfeld =>
     * Schluessel im Block. Ein leeres Feld loescht nichts und wird auch
     * nicht auf einen Wert gebogen, den niemand gewaehlt hat: bis 0.10.1
     * wurde aus einer leeren Zonenangabe still '1' und aus einer leeren
     * Sprache still 'de' (Nr. 19: seit 0.11.12 beanstandet, die Regel
     * kennt das). */
    foreach (array('tts_mode' => 'mode', 'tts_ip' => 'ip', 'tts_port' => 'port', 'tts_volume' => 'volume',
                   'tts_zones' => 'zones', 'tts_lang' => 'lang', 'tts_stimme' => 'stimme') as $sp_f => $sp_k) {
        if (!isset($_POST[$sp_f])) { continue; }
        $sp_w = $sp_x($sp_f);
        if ($sp_regel('tts.' . $sp_k, $sp_w, $sp_f)) {
            $sp_tts[$sp_k] = in_array($sp_k, array('port', 'volume'), true) ? (int) $sp_w : $sp_w;
        }
    }
    /* ---- Zusaetzliche Ansage: Chromecast4lox und Alexa-NG (Ansage-1) ----
     * Abgewiesen wird benannt, nicht zurechtgebogen; ein Feld, das keine
     * Zeichenkette ist (name[]), ist eine Beanstandung. Das Sprechtoken
     * reist nie ins Formular zurueck: leer lassen behaelt es, der Haken
     * loescht es (nur im Formular, das ihn traegt). */
    $sp_roh = function ($feld) {
        if (!isset($_POST[$feld])) { return null; }
        return is_string($_POST[$feld]) ? trim($_POST[$feld]) : false;
    };
    // Die Vorlage traegt Platzhalter in geschweiften Klammern und darf
    // deshalb NICHT durch den Filter oben laufen.
    $sp_w = $sp_roh('tts_template');
    if ($sp_w !== null && $sp_regel('tts.template', $sp_w, 'tts_template')) { $sp_tts['template'] = $sp_w; }
    $sp_w = $sp_roh('cc_praefix');
    if ($sp_w !== null) {
        $sp_w = $sp_w === false ? false : trim($sp_w, '/');
        if ($sp_regel('tts.cc_praefix', $sp_w, 'cc_praefix')) { $sp_tts['cc_praefix'] = $sp_w; }
    }
    // Das Formular verlangt ein Ziel ("alle" fuer alle) - leer heisst in der
    // Konfiguration "kein brauchbares", das soll niemand eintippen.
    $sp_w = $sp_roh('cc_ziel');
    if ($sp_w !== null && $sp_regel('tts.cc_ziel', $sp_w === '' ? false : $sp_w, 'cc_ziel')) { $sp_tts['cc_ziel'] = $sp_w; }
    $sp_w = $sp_roh('alexa_geraet');
    if ($sp_w !== null && $sp_regel('tts.alexa_geraet', $sp_w, 'alexa_geraet')) { $sp_tts['alexa_geraet'] = $sp_w; }
    $sp_w = $sp_roh('google_geraet');
    if ($sp_w !== null && $sp_regel('tts.google_geraet', $sp_w, 'google_geraet')) { $sp_tts['google_geraet'] = $sp_w; }
    /* Lautstaerke fuer Alexa-NG und Google: leer = unveraendert (-1), sonst
     * 1 bis 100. 0 nimmt die Regel seit 0.12.0 nicht mehr an (F13): das
     * andere Plugin spricht dann stumm und meldet OK=1 - kein Rueckfall. */
    foreach (array('alexa_laut', 'google_laut') as $sp_f) {
        $sp_w = $sp_roh($sp_f);
        if ($sp_w === null) { continue; }
        if ($sp_w === '') { $sp_tts[$sp_f] = -1; continue; }
        if ($sp_w === '-1') { $sp_w = false; }      // -1 heisst leer lassen, nicht eintippen
        if ($sp_regel('tts.' . $sp_f, $sp_w, $sp_f)) { $sp_tts[$sp_f] = (int) $sp_w; }
    }
    /* ---- S1 (Runde 2): Sonos4Lox ----
     * Dieselben Regeln und Feldnamen wie ansage_formular_lesen() in
     * sprachausgabe.php: Zone wie ein Geraetename (leer = nicht eingestellt),
     * Lautstaerke leer = die von Sonos4Lox, sonst 1 bis 100. Ohne Zone
     * spricht Sonos4Lox nichts (SONOS_KEINE_ZONE) - bei gewaehlter Ausgabeart
     * deshalb eine Beanstandung, wie dort (M_SONOS_OHNE_ZONE). */
    $sp_w = $sp_roh('tts_sonos_zone');
    if ($sp_w !== null) {
        if ($sp_w === false || !sp_alexa_geraet_ok($sp_w)) {
            $sp_fehler[] = sp_e(sp_pruef_klartext(sp_t('UI013.M_SONOS_ZONE')));
            sp_bean('tts_sonos_zone');
        } elseif ($sp_w === '' && $sp_sauber('tts_mode') === 'sonos4lox') {
            $sp_fehler[] = sp_e(sp_pruef_klartext(sp_t('UI013.M_SONOS_OHNE_ZONE')));
            sp_bean('tts_sonos_zone');
        } else {
            $sp_tts['sonos_zone'] = $sp_w;
        }
    }
    $sp_w = $sp_roh('tts_sonos_laut');
    if ($sp_w === '') {
        $sp_tts['sonos_laut'] = -1;
    } elseif ($sp_w !== null) {
        if ($sp_w !== false && preg_match('/^[0-9]{1,3}\z/', $sp_w) && (int) $sp_w >= 1 && (int) $sp_w <= 100) {
            $sp_tts['sonos_laut'] = (int) $sp_w;
        } else {
            $sp_fehler[] = sp_e(sp_pruef_klartext(sp_t('UI013.M_SONOS_LAUT')));
            sp_bean('tts_sonos_laut');
        }
    }
    /* ---- S5/S6 (Runde 2): Musik leiser und Musik steuern ----
     * Beide Haken stehen im Formular 'dienste' (siehe llm_ein oben). Sinnvoll
     * nur mit dem Music Server oder MusicServer4Home als Ausgabeart - ohne
     * eine davon weiss der Dienst nicht, wo Musik laeuft. Ein Hinweis, keine
     * Sperre: wer die Ausgabeart gleich danach umstellt, soll nicht zweimal
     * speichern muessen. */
    if ($sp_formular === 'dienste') {
        $sp_cfg['musik_ducken'] = isset($_POST['musik_ducken']) ? 1 : 0;
        $sp_cfg['musik_steuern'] = isset($_POST['musik_steuern']) ? 1 : 0;
        if (isset($_POST['musik_ducken_laut'])) {
            $sp_w = $sp_x('musik_ducken_laut');
            if ($sp_regel('musik_ducken_laut', $sp_w, 'musik_ducken_laut')) { $sp_cfg['musik_ducken_laut'] = (int) $sp_w; }
        }
        if (($sp_cfg['musik_ducken'] || $sp_cfg['musik_steuern'])
            && $sp_sauber('tts_mode') !== 'musicserver') {
            $sp_hinweise[] = sp_t('UI013.HINWEIS_MUSIK_OHNE_MS');
        }
    }
    foreach (array('alexa' => 'EINST.HINWEIS_ALEXA_OHNE_TOKEN', 'google' => 'EINST.HINWEIS_GOOGLE_OHNE_TOKEN') as $sp_a => $sp_hw) {
        if ($sp_formular === 'dienste' && isset($_POST[$sp_a . '_token_loeschen'])) {
            $sp_tts[$sp_a . '_token'] = '';
        } else {
            $sp_w = $sp_roh($sp_a . '_token');
            if ($sp_w !== null && $sp_w !== '' && $sp_regel('tts.' . $sp_a . '_token', $sp_w, $sp_a . '_token')) {
                $sp_tts[$sp_a . '_token'] = $sp_w;
            }
        }
        if ($sp_formular === 'dienste' && $sp_sauber('tts_mode') === ($sp_a === 'alexa' ? 'alexang' : 'cc4lox')
            && (!isset($sp_tts[$sp_a . '_token']) || !sp_alexa_token_ok((string) $sp_tts[$sp_a . '_token']))) {
            $sp_hinweise[] = sp_t($sp_hw);
        }
    }
    /* Ein HINWEIS, keine Sperre - und nur, wenn das Formular kam, das die
     * drei Werte fuehrt. Sonst urteilt er ueber Felder, die gar nicht da
     * waren. Der Satz stimmt trotzdem: ohne Adresse geht keine Ansage
     * ueber Loxone hinaus. Nur fuer die beiden Wege, die die Adresse
     * immer brauchen ("aus", Audioserver, Chromecast4lox und die NG-Wege
     * brauchen keine). Eine eigene Vorlage braucht sie nur, wenn sie {ip}
     * enthaelt - bis 0.11.15 kam der Hinweis bei jeder eigenen Vorlage, auch
     * bei einer mit fester Adresse (F13). */
    $sp_vorl = isset($sp_tts['template']) ? (string) $sp_tts['template'] : '';
    if ($sp_formular === 'dienste'
        && isset($_POST['antwortweg'], $_POST['tts_mode'], $_POST['tts_ip'])
        && $sp_sauber('antwortweg') !== 'satellit'
        && (in_array($sp_sauber('tts_mode'), array('musicserver', 'ms4h'), true)
            || ($sp_sauber('tts_mode') === 'custom' && strpos($sp_vorl, '{ip}') !== false))
        && $sp_sauber('tts_ip') === '') {
        $sp_hinweise[] = sp_t('EINST.FEHLER_TTS_FEHLT');
    }
    $sp_cfg['tts'] = $sp_tts;

    /* ---- Ruhezeit ---- */
    $sp_ruhe = is_array(isset($sp_cfg['ruhe']) ? $sp_cfg['ruhe'] : null) ? $sp_cfg['ruhe'] : array();
    // Der Haken steht im Formular 'ansagen' - siehe oben zu llm_ein.
    if ($sp_formular === 'ansagen') {
        $sp_ruhe['ein'] = isset($_POST['ruhe_ein']) ? 1 : 0;
    }
    // F13: streng 0:00 bis 23:59 (sp_pruef_uhrzeit()); bis 0.11.15 ging 25:99 durch.
    // Runde 2 (A7): "bis" darf 24:00 sein - der Dienst rechnet es als Tagesende.
    foreach (array('von', 'bis') as $sp_f) {
        if (!isset($_POST['ruhe_' . $sp_f])) { continue; }
        $sp_w = $sp_x('ruhe_' . $sp_f);
        if ($sp_regel('ruhe.' . $sp_f, $sp_w, 'ruhe_' . $sp_f)) { $sp_ruhe[$sp_f] = $sp_w; }
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
            $sp_fehler[] = sprintf(sp_t('EINST.FEHLER_SPEICHERN'), sp_e($sp_p['config']));
        }
    }
    if ($sp_fehler && in_array($sp_formular, array('dienste', 'ansagen'), true)) {
        $sp_formular_x2 = $sp_formular;
    }
    $sp_tab = 'tab-settings';
}

/* ---------------- Modelle speichern ---------------- */
if ($sp_post && isset($_POST['modelle_speichern'])) {
    $sp_cfg = sp_config();
    foreach (array('whisper_modell', 'piper_stimme', 'llm_modell') as $feld) {
        $w = $sp_x($feld);
        // Auswahlliste oder Freitext - der Freitext gewinnt, wenn er gefuellt ist.
        $frei = $sp_x($feld . '_frei');
        $sp_mf = $feld;
        if (is_string($frei) && $frei !== '') { $w = $frei; $sp_mf = $feld . '_frei'; }
        if ($w === false || $frei === false) {
            $sp_mf = $w === false ? $feld : $feld . '_frei';
            $w = false;
        }
        if ($w === null) { $w = ''; }       // nicht mitgeschickt: "Vorschlag nehmen"
        if ($sp_regel($feld, $w, $sp_mf)) { $sp_cfg[$feld] = $w; }
    }
    if (!$sp_fehler) {
        if (sp_config_speichern($sp_cfg)) { $sp_meldungen[] = sp_t('DIENST.GESPEICHERT'); }
        else { $sp_fehler[] = sprintf(sp_t('EINST.FEHLER_SPEICHERN'), sp_e($sp_p['config'])); }
    }
    if ($sp_fehler) { $sp_formular_x2 = 'modelle'; }
    $sp_tab = 'tab-services';
}

/* ---------------- Optionen des Reiters Dienste (S7, Runde 2) ----------------
 * Ein eigenes Formular mit einem Haken: der Dienst darf den LOKALEN
 * Container eines Sprachdienstes nach drei Fehlschlaegen in Folge neu
 * starten, hoechstens alle 10 Minuten. Ab Werk aus - ein Neustart unterbricht
 * auch einen Satz, der gerade laeuft. */
if ($sp_post && isset($_POST['dienste_opt_speichern'])) {
    $sp_cfg = sp_config();
    $sp_cfg['docker_neustart'] = isset($_POST['docker_neustart']) ? 1 : 0;
    if (sp_config_speichern($sp_cfg)) { $sp_meldungen[] = sp_t('EINST.GESPEICHERT'); }
    else {
        $sp_fehler[] = sprintf(sp_t('EINST.FEHLER_SPEICHERN'), sp_e($sp_p['config']));
        $sp_formular_x2 = 'dienste_opt';
    }
    $sp_tab = 'tab-services';
}

/* ---------------- Mikrofone speichern ---------------- */
if ($sp_post && isset($_POST['mikros_speichern'])) {
    $sp_cfg = sp_config();
    $sp_altliste = isset($sp_cfg['satelliten']) && is_array($sp_cfg['satelliten']) ? array_values($sp_cfg['satelliten']) : array();
    /* Alle abgeschickten Zeilen, nicht nur acht (F12): eine Sicherung kann
     * mehr Mikrofone tragen, und die Tabelle zeigt sie dann alle. Bis
     * 0.11.15 fiel beim naechsten Speichern still weg, was hinter Zeile 8
     * stand. Mehr als acht weist die Regel ab - benannt, nichts geht verloren. */
    $sp_zeilen = array();
    foreach (array('m_host', 'm_name') as $sp_mfeld) {
        if (isset($_POST[$sp_mfeld]) && is_array($_POST[$sp_mfeld])) {
            foreach (array_keys($_POST[$sp_mfeld]) as $k) {
                if (is_int($k) && $k >= 0 && $k < 32) { $sp_zeilen[$k] = true; }
            }
        }
    }
    ksort($sp_zeilen);
    $sp_neu = array();
    foreach (array_keys($sp_zeilen) as $i) {
        // Nr. 19: was der Filter still veraendert haette, ist eine Beanstandung.
        $sp_zeichen_falsch = array();
        $sp_w = array();
        foreach (array('m_host', 'm_port', 'm_zone', 'm_art') as $sp_mfeld) {
            $roh = sp_post_zeile($sp_mfeld, $i);
            $sauber = $roh === false ? '' : trim((string) preg_replace('/[\x00-\x1F\x7F"\']/', '', $roh));
            if ($roh === false || $sauber !== trim($roh)) { $sp_zeichen_falsch[] = $sp_mfeld; }
            $sp_w[$sp_mfeld] = $sauber;
        }
        // Bezeichnung und Raum sind Freitext - Anfuehrungszeichen bleiben
        // stehen. Bis 0.11.15 nahm der Raum sie still heraus (F12).
        $name = $sp_freitext(sp_post_zeile('m_name', $i));
        $raum = $sp_freitext(sp_post_zeile('m_raum', $i));
        $host = $sp_w['m_host'];
        if ($host === '' && $name === '') { continue; }
        $art = $sp_w['m_art'] === 'esphome' ? 'esphome' : 'wyoming';
        if ($sp_zeichen_falsch) {
            $sp_fehler[] = sprintf(sp_t('MIKRO.FEHLER_ZEICHEN'), $i + 1);
            foreach ($sp_zeichen_falsch as $sp_mfeld) { sp_bean($sp_mfeld, $i); }
            continue;
        }
        $port = $sp_w['m_port'];
        if ($port === '') { $port = $art === 'esphome' ? '6053' : '10700'; }
        $eintrag = array('art' => $art, 'name' => $name !== '' ? $name : $host,
                         'host' => $host, 'port' => sp_pruef_zahl($port) !== null ? (int) $port : $port,
                         'raum' => $raum, 'zone' => $sp_w['m_zone']);
        if ($art === 'esphome') {
            /* Der Noise-Schluessel ist ein Geheimnis: leer heisst beibehalten
             * - aber nur fuer DASSELBE Geraet (F12). Bis 0.11.15 hing er an
             * der Zeilennummer: wer in Zeile 2 ein anderes Mikrofon eintrug,
             * schickte ihm den Schluessel des alten. Jetzt gilt er nur, wenn
             * die Zeile beim Anzeigen dieselbe Adresse trug (m_alt) und die
             * Adresse gleich geblieben ist; der Haken loescht ihn. */
            $schluessel = sp_post_zeile('m_schluessel', $i);
            $schluessel = is_string($schluessel) ? $schluessel : '';
            $althost = sp_post_zeile('m_alt', $i);
            $alt = '';
            if (is_string($althost) && $althost !== '' && isset($sp_altliste[$i]) && is_array($sp_altliste[$i])
                && isset($sp_altliste[$i]['host']) && (string) $sp_altliste[$i]['host'] === $althost
                && isset($sp_altliste[$i]['schluessel']) && is_string($sp_altliste[$i]['schluessel'])) {
                $alt = $sp_altliste[$i]['schluessel'];
                if ($althost !== $host && $alt !== '' && $schluessel === '') {
                    $sp_hinweise[] = sprintf(sp_t('UI012.MIKRO_SCHLUESSEL_VERWORFEN'), $i + 1);
                }
                if ($althost !== $host) { $alt = ''; }
            }
            if (sp_post_haken('m_schluessel_loeschen', $i)) { $alt = ''; $schluessel = ''; }
            $eintrag['schluessel'] = $schluessel !== '' ? $schluessel : $alt;
        }
        /* S2 (Runde 2): die Ausgabe dieses Raums. Nur gefuellte Felder kommen
         * in den Block; ohne eins fehlt er ganz - ein leerer Block waere in
         * JSON eine Liste ([]), und "leer heisst wie global" ist so eindeutig.
         * Nicht gesaeubert: was ein Steuerzeichen traegt, weist die Regel ab
         * (sp_pruef_raumausgabe()), statt es still zu entfernen. */
        $sp_ra = array();
        foreach (array('m_a_alexa' => 'alexa_geraet', 'm_a_google' => 'google_geraet',
                       'm_a_cc' => 'cc_ziel', 'm_a_sonos' => 'sonos_zone') as $sp_mfeld => $sp_rk) {
            $roh = sp_post_zeile($sp_mfeld, $i);
            if ($roh === false) { $sp_ra[$sp_rk] = false; continue; }
            $roh = trim($roh);
            if ($roh !== '') { $sp_ra[$sp_rk] = $roh; }
        }
        if ($sp_ra) { $eintrag['ausgabe'] = $sp_ra; }
        $sp_neu[$i] = $eintrag;
    }
    // Die Regeln aus sp_pruefen.php - dieselben wie beim Einspielen einer Sicherung.
    if (!$sp_fehler) {
        // Die Felder der Raumausgabe heissen in der Regel ausgabe.<name>, im Formular m_a_<kurz>.
        $sp_rafeld = array('ausgabe.alexa_geraet' => 'm_a_alexa', 'ausgabe.google_geraet' => 'm_a_google',
                           'ausgabe.cc_ziel' => 'm_a_cc', 'ausgabe.sonos_zone' => 'm_a_sonos', 'ausgabe' => 'm_a_alexa');
        foreach (sp_satelliten_befunde($sp_neu) as $sp_b) {
            $sp_fehler[] = sp_e($sp_b['text']);
            if ($sp_b['idx'] !== null && $sp_b['feld'] !== '') {
                sp_bean(isset($sp_rafeld[$sp_b['feld']]) ? $sp_rafeld[$sp_b['feld']] : 'm_' . $sp_b['feld'], $sp_b['idx']);
            }
        }
    }
    if (!$sp_fehler) {
        $sp_cfg['satelliten'] = array_values($sp_neu);
        if (sp_config_speichern($sp_cfg)) { $sp_meldungen[] = sp_t('MIKRO.GESPEICHERT'); }
        else { $sp_fehler[] = sprintf(sp_t('EINST.FEHLER_SPEICHERN'), sp_e($sp_p['config'])); }
    }
    if ($sp_fehler) { $sp_formular_x2 = 'mikros'; }
    $sp_tab = 'tab-mics';
}

/* ---------------- Ein Mikrofon einzeln pruefen ---------------- */
if ($sp_post && isset($_POST['mikro_pruefen'])) {
    $sp_i = (int) sp_post_text('mikro_pruefen', '-1');
    $sp_liste = (array) sp_config()['satelliten'];
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
/* F1 (0.12.0): bis 0.11.15 baute die Maske jedes Ziel aus ihren sechs
 * Feldern NEU. Alles andere, was ein Ziel trug - die eigene
 * Miniserver-Adresse (url), uuid, min, max und was der Rohtext sonst noch
 * hineingeschrieben hatte - fiel beim naechsten Speichern still weg. Und der
 * Schluessel wurde still umgeschrieben (aus "küche" wurde "kche"), womit
 * auch das MQTT-Thema wechselte, auf das Loxone hoert.
 *
 * Jetzt: jede Zeile traegt ihren alten Schluessel (z_alt) verdeckt mit; der
 * alte Eintrag wird uebernommen und nur die Felder der Maske werden
 * ueberschrieben oder entfernt. Ein unzulaessiger NEUER Schluessel wird
 * beanstandet, mit Vorschlag, nicht umgeschrieben. Ein Schluessel, der
 * schon so in der Satzdatei stand (etwa mit Umlaut aus dem Rohtext), bleibt
 * stehen: der Dienst ebnet ihn beim Vergleich selbst ein (einebnen() in
 * verstehen.py). Geloescht wird nur mit dem Haken der Zeile; Ziele, die gar
 * nicht in der Maske standen (inzwischen aus Loxone uebernommen), bleiben. */
if ($sp_post && isset($_POST['ziele_speichern'])) {
    $sp_d = sp_saetze();
    if (!isset($sp_d['regeln']) || !is_array($sp_d['regeln'])) { $sp_d['regeln'] = array(); }
    $sp_zalt = isset($sp_d['ziele']) && is_array($sp_d['ziele']) ? $sp_d['ziele'] : array();
    $sp_zneu = array();
    $sp_gesehen = array();
    $sp_aliase = array();
    $sp_keys = isset($_POST['z_key']) && is_array($_POST['z_key']) ? $_POST['z_key'] : array();
    $hol = function ($feld, $i) {
        $w = sp_post_zeile($feld, $i);
        return is_string($w) ? trim((string) preg_replace('/[\x00-\x1F\x7F]/u', ' ', $w)) : '';
    };
    foreach (array_keys($sp_keys) as $i) {
        if (!is_int($i) || $i < 0 || $i > 998) { continue; }
        $alt = sp_post_zeile('z_alt', $i);
        $alt = (is_string($alt) && array_key_exists($alt, $sp_zalt)) ? $alt : '';
        if ($alt !== '') { $sp_gesehen[$alt] = true; }
        if (sp_post_haken('z_loeschen', $i)) { continue; }
        $roh_key = sp_post_zeile('z_key', $i);
        $key = is_string($roh_key) ? trim($roh_key) : '';
        $name = $hol('z_name', $i);
        $leer = $key === '' && $name === '';
        foreach (array('z_alias', 'z_thema', 'z_einheit', 'z_url', 'z_lesen', 'z_min', 'z_max', 'z_schritt') as $f) {
            if ($hol($f, $i) !== '') { $leer = false; }
        }
        if ($leer && $alt === '') { continue; }            // leere neue Zeile
        if ($key === '') {
            $sp_fehler[] = sprintf(sp_t($alt !== '' ? 'UI012.ZIEL_SCHLUESSEL_LEER' : 'SATZ.FEHLER_SCHLUESSEL'), $i + 1);
            sp_bean('z_key', $i);
            continue;
        }
        $zulaessig = preg_match('/^[A-Za-z0-9_\-]{1,64}\z/', $key) === 1;
        $wie_vorher = $alt !== '' && $key === $alt && !preg_match('#[\x00-\x1F\x7F/]#', $key);
        if (!$zulaessig && !$wie_vorher) {
            $vorschlag = sp_lox_schluessel($key);
            $sp_fehler[] = $vorschlag !== '' && $vorschlag !== $key
                ? sprintf(sp_t('UI012.ZIEL_SCHLUESSEL_VORSCHLAG'), $i + 1, sp_e($key), sp_e($vorschlag))
                : sprintf(sp_t('SATZ.FEHLER_SCHLUESSEL'), $i + 1);
            sp_bean('z_key', $i);
            continue;
        }
        if (isset($sp_zneu[$key])) {
            $sp_fehler[] = sprintf(sp_t('SATZ.FEHLER_DOPPELT'), sp_e($key));
            sp_bean('z_key', $i);
            continue;
        }
        // Leeres Thema heisst: der Schluessel ist das Thema - dann muss er
        // als MQTT-Thema taugen (ein uebernommener Schluessel mit Umlaut nicht).
        $thema = trim($hol('z_thema', $i), '/');
        $thema_eff = $thema !== '' ? $thema : $key;
        if (!preg_match('#^[A-Za-z0-9_/\-]{1,80}\z#', $thema_eff)) {
            $sp_fehler[] = sprintf(sp_t('SATZ.FEHLER_THEMA'), sp_e($key));
            sp_bean('z_thema', $i);
            continue;
        }
        /* Adressen (url, url_lesen) koennen Zugangsdaten tragen und stehen
         * deshalb maskiert in der Maske. Kommt die maskierte Form zurueck,
         * bleibt die gespeicherte Adresse; leer entfernt sie. */
        $altz = ($alt !== '' && is_array($sp_zalt[$alt])) ? $sp_zalt[$alt] : array();
        $adressen = array();
        $adr_falsch = false;
        /* Runde 2 (A1): die Adresse darf auch ms://<nr>/<pfad> sein (Miniserver
         * aus der LoxBerry-Einstellung), die Leseadresse zusaetzlich
         * mqtt:<thema> (braucht die Dauerverbindung, mqtt_dauer_ein). */
        foreach (array('url' => array('z_url', 'UI013.ZIEL_URL_FALSCH', 'ms'),
                       'url_lesen' => array('z_lesen', 'UI013.ZIEL_LESEN_FALSCH', 'lesen')) as $sp_k => $sp_inf) {
            $w = sp_post_zeile($sp_inf[0], $i);
            $w = is_string($w) ? trim($w) : '';
            $bisher = isset($altz[$sp_k]) && is_string($altz[$sp_k]) ? $altz[$sp_k] : '';
            if ($w !== '' && $bisher !== '' && $w === sp_url_maskiert($bisher)) { $w = $bisher; }
            if ($w !== '' && !sp_url_ok($w, $sp_inf[2])) {
                $sp_fehler[] = sprintf(sp_t($sp_inf[1]), sp_e($key));
                sp_bean($sp_inf[0], $i);
                $adr_falsch = true;
            }
            $adressen[$sp_k] = $w;
        }
        if ($adr_falsch) { continue; }
        // min und max: frei, aber wenn, dann eine Zahl (sie gehen unveraendert in die Satzdatei).
        // S8 (Runde 2): schritt - um wie viel "heller" oder "zwei Grad waermer"
        // den Wert aendert; eine Zahl groesser als 0.
        $zahlen = array();
        $zahl_falsch = false;
        foreach (array('min' => 'z_min', 'max' => 'z_max', 'schritt' => 'z_schritt') as $sp_k => $sp_f) {
            $w = str_replace(',', '.', $hol($sp_f, $i));
            if ($w === '') { $zahlen[$sp_k] = null; continue; }
            if (!preg_match('/^-?[0-9]{1,9}(\.[0-9]{1,6})?\z/', $w)) {
                $sp_fehler[] = sprintf(sp_t('UI012.ZIEL_ZAHL_FALSCH'), sp_e($key));
                sp_bean($sp_f, $i);
                $zahl_falsch = true;
                continue;
            }
            $zahlen[$sp_k] = strpos($w, '.') !== false ? (float) $w : (int) $w;
            if ($sp_k === 'schritt' && $zahlen[$sp_k] <= 0) {
                $sp_fehler[] = sprintf(sp_t('UI013.ZIEL_SCHRITT_FALSCH'), sp_e($key));
                sp_bean($sp_f, $i);
                $zahl_falsch = true;
            }
        }
        if ($zahl_falsch) { continue; }
        $alias = array();
        foreach (explode(',', $hol('z_alias', $i)) as $a) {
            $a = trim($a);
            if ($a !== '' && !in_array($a, $alias, true)) { $alias[] = $a; }
        }
        // Der alte Eintrag ist die Grundlage; die Kurzform "ziel": "thema" wird zum Block.
        $eintrag = $altz;
        if ($alt !== '' && is_string($sp_zalt[$alt])) { $eintrag = array('thema' => $sp_zalt[$alt]); }
        $eintrag = array_merge($eintrag, array('name' => $name !== '' ? $name : $key,
                                               'alias' => $alias, 'thema' => $thema_eff));
        $einheit = $hol('z_einheit', $i);
        $setzen = array('einheit' => $einheit !== '' ? $einheit : null,
                        'url' => $adressen['url'] !== '' ? $adressen['url'] : null,
                        'url_lesen' => $adressen['url_lesen'] !== '' ? $adressen['url_lesen'] : null,
                        'min' => $zahlen['min'], 'max' => $zahlen['max'], 'schritt' => $zahlen['schritt'],
                        'bestaetigen' => sp_post_haken('z_bestaetigen', $i) ? true : null);
        foreach ($setzen as $sp_k => $w) {
            if ($w === null) { unset($eintrag[$sp_k]); } else { $eintrag[$sp_k] = $w; }
        }
        $sp_zneu[$key] = $eintrag;
        // Doppelte Aliasnamen: nur ein Hinweis - es gewinnt der laengste
        // passende Name, bei gleich langen das erste Ziel. Verglichen wird
        // eingeebnet wie im Dienst (Umlaute, Gross-/Kleinschreibung).
        foreach (array_merge(array($eintrag['name']), $alias) as $a) {
            $e = sp_lox_schluessel($a);
            if ($e === '') { continue; }
            if (isset($sp_aliase[$e]) && $sp_aliase[$e] !== $key) {
                $sp_hinweise[] = sprintf(sp_t('UI012.ZIEL_ALIAS_DOPPELT'), sp_e($a), sp_e($sp_aliase[$e]), sp_e($key));
            } else {
                $sp_aliase[$e] = $key;
            }
        }
    }
    // Was nicht in der Maske stand, bleibt (siehe oben) - hinten angehaengt.
    foreach ($sp_zalt as $sp_k => $sp_z) {
        if (isset($sp_gesehen[$sp_k]) || isset($sp_zneu[$sp_k])) { continue; }
        $sp_zneu[$sp_k] = $sp_z;
    }
    /* S4: eine Leseadresse mqtt:<thema> liest der Dienst nur ueber seine
     * Dauerverbindung zum Broker. Ist sie aus, bleibt die Frage unbeantwortet -
     * ein Hinweis, keine Sperre (eingeschaltet wird sie im Reiter MQTT). */
    if (empty(sp_config()['mqtt_dauer_ein'])) {
        foreach ($sp_zneu as $sp_z) {
            if (is_array($sp_z) && isset($sp_z['url_lesen']) && is_string($sp_z['url_lesen'])
                && strncasecmp($sp_z['url_lesen'], 'mqtt:', 5) === 0) {
                $sp_hinweise[] = sp_t('UI013.HINWEIS_MQTT_LESEN');
                break;
            }
        }
    }
    if (!$sp_fehler) {
        $sp_d['ziele'] = $sp_zneu;
        $sp_d = sp_steuerzeichen_weg($sp_d);
        if (sp_ui_saetze_speichern($sp_d)) { $sp_meldungen[] = sp_t('SATZ.ZIELE_GESPEICHERT'); }
        else { $sp_fehler[] = sprintf(sp_t('EINST.FEHLER_SPEICHERN'), sp_e($sp_p['saetze'])); }
    }
    if ($sp_fehler) { $sp_formular_x2 = 'ziele'; }
    $sp_tab = 'tab-sentences';
}

/* ---------------- Saetze: Rohtext ----------------
 * F5: nach einer Beanstandung reist der eingegebene Text mit (eigene Datei,
 * 0600, 120 s - sp_flash_schreiben()); bis 0.11.15 stand danach wieder der
 * gespeicherte Stand im Feld, und die Arbeit war weg. */
$sp_rohtext_zurueck = null;
if ($sp_post && isset($_POST['saetze_speichern'])) {
    $sp_roh = sp_post_text('saetze');
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
            if (sp_ui_saetze_speichern($sp_d)) { $sp_meldungen[] = sp_t('SATZ.GESPEICHERT'); }
            else { $sp_fehler[] = sprintf(sp_t('EINST.FEHLER_SPEICHERN'), sp_e($sp_p['saetze'])); }
        }
    }
    // Hoechstens 2 MB - dieselbe Grenze wie fuer eine Sicherung.
    if ($sp_fehler && trim($sp_roh) !== '' && strlen($sp_roh) <= 2 * 1024 * 1024) {
        $sp_rohtext_zurueck = $sp_roh;
        $sp_fehler[] = sp_e(sp_t('UI012.ROHTEXT_ZURUECK'));
    }
    $sp_tab = 'tab-sentences';
}

/* ---------------- Saetze: einen frueheren Stand zurueckholen (E2) ----------------
 * Der Name kommt aus der Anfrage und wird deshalb gegen das feste Muster
 * und gegen die Liste der vorhandenen Staende geprueft - nie als Pfad. */
if ($sp_post && isset($_POST['saetze_zurueck'])) {
    $sp_name = sp_post_text('saetze_zurueck');
    $sp_gefunden = null;
    foreach (sp_ui_staende() as $sp_st) {
        if ($sp_st['datei'] === $sp_name) { $sp_gefunden = $sp_st; }
    }
    $sp_d = $sp_gefunden !== null ? sp_json_lesen(sp_ui_staende_ordner() . '/' . $sp_gefunden['datei']) : array();
    if ($sp_gefunden === null) {
        $sp_fehler[] = sp_e(sp_t('UI012.STAND_FEHLT'));
    } elseif (!isset($sp_d['regeln'], $sp_d['ziele']) || !is_array($sp_d['regeln']) || !is_array($sp_d['ziele'])) {
        $sp_fehler[] = sp_e(sp_t('UI012.STAND_KAPUTT'));
    } elseif (sp_ui_saetze_speichern(sp_steuerzeichen_weg($sp_d))) {
        // Der Stand davor liegt jetzt selbst als Stand bereit - zurueckholen ist umkehrbar.
        $sp_meldungen[] = sprintf(sp_t('UI012.STAND_ZURUECK'), sp_e(date('d.m.Y H:i:s', $sp_gefunden['zeit'])),
                                  count($sp_d['regeln']), count($sp_d['ziele']));
    } else {
        $sp_fehler[] = sprintf(sp_t('EINST.FEHLER_SPEICHERN'), sp_e($sp_p['saetze']));
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
/* F4: die Probe speichert nichts (der Handler "Einstellungen speichern"
 * laeuft bei probe_stimme nicht an). Die Eingaben des Formulars reisen
 * trotzdem zurueck (X-2 ohne Beanstandung), damit eine halb eingetippte
 * Einstellung nach dem Hoeren noch dasteht - mit dem Hinweis, dass sie
 * nicht gespeichert ist. */
if ($sp_post && isset($_POST['probe_stimme'])) {
    $sp_ptext = trim((string) preg_replace('/[\x00-\x1F\x7F]/u', ' ', sp_post_text('probe_text')));
    if ($sp_ptext === '') { $sp_ptext = sp_t('UI012.PROBE_TEXT'); }
    $sp_pstimme = $sp_sauber('tts_stimme');
    list($sp_ok, $sp_meld) = sp_befehl_absetzen(
        array('aktion' => 'probe', 'text' => $sp_ptext, 'stimme' => $sp_pstimme), null, 'web');
    if ($sp_ok === 1) { $sp_meldungen[] = sp_e($sp_meld) . ' ' . sp_t('EINST.PROBE_FERTIG'); }
    else { $sp_fehler[] = sp_e($sp_meld); }
    if (sp_post_text('formular') === 'dienste') {
        $sp_formular_x2 = 'dienste';
        $sp_x2_immer = true;
        $sp_hinweise[] = sp_e(sp_t('UI012.PROBE_NICHT_GESPEICHERT'));
    }
    $sp_tab = 'tab-settings';
}

/* ---------------- Ziele aus Loxone vorschlagen ---------------- */
if ($sp_post && isset($_POST['lox_holen'])) {
    /* Die Zugangsdaten werden EINMAL benutzt und nicht gespeichert. Abgelegt
     * wird nur die Vorschlagsliste - darin stehen Namen, keine Kennwoerter.
     *
     * E4 (0.12.0): kennt die Bibliothek die Miniserver aus der
     * LoxBerry-Einstellung (sp_lb_miniserver()), genuegt deren Nummer - die
     * Zugangsdaten holt sp_lox_struktur_holen() selbst, wenn es statt einer
     * Adresse eine Zahl bekommt; sie kommen nie in die Seite. Bis Runde 1
     * rief diese Stelle sp_lox_struktur_holen_ms() auf, eine Funktion, die
     * die Bibliothek nie hatte - die Auswahl erschien deshalb gar nicht
     * (Runde 2, A2). Rueckgabe: array(ok, Meldung, Vorschlaege). Die Meldung
     * wird hier als Klartext behandelt und maskiert. */
    $sp_lms = sp_post_text('lox_ms');
    if ($sp_lms !== '') {
        if (!preg_match('/^[1-9][0-9]?\z/', $sp_lms) || !function_exists('sp_lb_miniserver')) {
            $sp_erg = array(0, sp_t('UI012.LOX_MS_FEHLT'), array());
        } else {
            $sp_erg = sp_lox_struktur_holen((int) $sp_lms);
        }
        $sp_ok = isset($sp_erg[0]) ? $sp_erg[0] : 0;
        $sp_meld = sp_e(sp_pruef_klartext(isset($sp_erg[1]) ? (string) $sp_erg[1] : ''));
        $sp_vor = isset($sp_erg[2]) && is_array($sp_erg[2]) ? $sp_erg[2] : array();
    } else {
        $sp_lhost = $sp_sauber('lox_host');
        $sp_lben = trim(sp_post_text('lox_benutzer'));
        $sp_lkw = sp_post_text('lox_kennwort');
        list($sp_ok, $sp_meld, $sp_vor) = sp_lox_struktur_holen($sp_lhost, $sp_lben, $sp_lkw);
    }
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
    $sp_gewaehlt = isset($_POST['lox_ziel']) && is_array($_POST['lox_ziel']) ? $_POST['lox_ziel'] : array();
    $sp_d = sp_saetze();
    if (!isset($sp_d['ziele']) || !is_array($sp_d['ziele'])) { $sp_d['ziele'] = array(); }
    if (!isset($sp_d['regeln']) || !is_array($sp_d['regeln'])) { $sp_d['regeln'] = array(); }
    $sp_neu = 0;
    $sp_schon = 0;
    foreach ($sp_gewaehlt as $sp_k) {
        if (!is_string($sp_k) || !isset($sp_vor[$sp_k])) { continue; }
        if (isset($sp_d['ziele'][$sp_k])) { $sp_schon++; continue; }
        $sp_v2 = $sp_vor[$sp_k];
        if (!is_array($sp_v2)) { continue; }
        $sp_d['ziele'][$sp_k] = array(
            'name'  => $sp_v2['name'],
            'alias' => array_values((array) $sp_v2['alias']),
            'thema' => $sp_v2['thema'],
        );
        /* A3 (Runde 2): uuid und Leseadresse gehen mit. Bis Runde 1 fielen
         * sie hier weg, obwohl der Import sie lieferte - ein uebernommenes
         * Ziel konnte keine Frage ("wie warm ist es ...") beantworten. Die
         * Leseadresse kommt nur ueber die Miniserver-Nummer (ms://<nr>/...,
         * ohne Zugangsdaten) und wird trotzdem nach derselben Regel geprueft
         * wie in der Zielmaske: die Vorschlagsdatei liegt unter data/ und
         * koennte veraendert sein. */
        if (isset($sp_v2['uuid']) && is_string($sp_v2['uuid']) && preg_match('/^[0-9A-Fa-f\-]{8,64}\z/', $sp_v2['uuid'])) {
            $sp_d['ziele'][$sp_k]['uuid'] = $sp_v2['uuid'];
        }
        if (isset($sp_v2['url_lesen']) && is_string($sp_v2['url_lesen']) && sp_url_ok($sp_v2['url_lesen'], 'lesen')) {
            $sp_d['ziele'][$sp_k]['url_lesen'] = $sp_v2['url_lesen'];
        }
        $sp_neu++;
    }
    if (!$sp_neu && !$sp_schon) {
        $sp_fehler[] = sp_t('LOXIMP.NICHTS_GEWAEHLT');
    } elseif (sp_ui_saetze_speichern(sp_steuerzeichen_weg($sp_d))) {
        $sp_meldungen[] = sprintf(sp_t('LOXIMP.UEBERNOMMEN'), $sp_neu, $sp_schon);
        sp_lox_vorschlaege_weg();
    } else {
        $sp_fehler[] = sprintf(sp_t('EINST.FEHLER_SPEICHERN'), sp_e($sp_p['saetze']));
    }
    $sp_tab = 'tab-sentences';
}

/* ---------------- Alias aus dem Verlauf uebernehmen ---------------- */
if ($sp_post && isset($_POST['alias_uebernehmen']) && isset($_POST['alias_ziel'])) {
    $sp_alias = $sp_freitext(sp_post_text('alias_uebernehmen'));
    /* Der Schluessel wird nachgeschlagen, nicht zurechtgebogen: bis 0.11.15
     * nahm ein Filter alles ausser [A-Za-z0-9_-] heraus, und ein Ziel mit
     * Umlaut im Schluessel war hier nie zu finden. */
    $sp_zk = sp_post_text('alias_ziel');
    $sp_d = sp_saetze();
    if ($sp_alias === '' || $sp_zk === '' || !isset($sp_d['ziele'][$sp_zk])
        || !is_array($sp_d['ziele'][$sp_zk])) {
        $sp_fehler[] = sp_t('TEST.M_ALIAS_FEHL');
    } else {
        $sp_liste = isset($sp_d['ziele'][$sp_zk]['alias'])
                  ? (array) $sp_d['ziele'][$sp_zk]['alias'] : array();
        if (!in_array($sp_alias, $sp_liste, true)) { $sp_liste[] = $sp_alias; }
        $sp_d['ziele'][$sp_zk]['alias'] = array_values($sp_liste);
        if (sp_ui_saetze_speichern(sp_steuerzeichen_weg($sp_d))) {
            $sp_meldungen[] = sprintf(sp_t('TEST.M_ALIAS_OK'), sp_e($sp_alias), sp_e($sp_zk));
        } else {
            $sp_fehler[] = sprintf(sp_t('EINST.FEHLER_SPEICHERN'), sp_e($sp_p['saetze']));
        }
    }
    $sp_tab = 'tab-test';
}

/* ---------------- Dienst ---------------- */
if ($sp_post && isset($_POST['dienst'])) {
    $sp_was = sp_post_text('dienst');
    list($sp_ok, $sp_aus) = sp_dienst($sp_was);
    if ($sp_ok) {
        $sp_meldungen[] = sp_t('EINST.DIENST_' . strtoupper($sp_was)) . ' ' . sp_e($sp_aus);
    } else { $sp_fehler[] = sp_e($sp_aus); }
    $sp_tab = 'tab-settings';
}

/* ---------------- Container ----------------
 * Seit 0.11.11: "Sprachdienste einrichten", "Abbild holen" und "Anlegen"
 * starten nur noch den Hintergrundvorgang (bin/container_vorgang.php) und
 * leiten dann um (303) - ein Neuladen wiederholt so keinen POST, und die
 * Seite zeigt den Stand aus der Zustandsdatei. Starten, Anhalten, Neu
 * starten und Entfernen laufen im Seitenaufruf, mit Zeitgrenze, und nur am
 * eigenen Container; bei einem fremden gibt es einen Hinweis mit Grund. */
/* Seit 0.12.0 enden auch diese Knoepfe in der gemeinsamen Umleitung unten
 * (mit Einmalmeldung) statt mit einer eigenen: nur so bleibt "Einzeln
 * verwalten" nach dem Knopf offen (F10). */
if ($sp_post && isset($_POST['ct_einrichten'])) {
    list($sp_ok, $sp_satz) = sp_ct_vorgang_starten('einrichten');
    if (!$sp_ok) { $sp_fehler[] = sp_e($sp_satz); }
    $sp_tab = 'tab-services';
}
if ($sp_post && isset($_POST['container']) && isset($_POST['dienstname'])) {
    $sp_was = sp_post_text('container');
    $sp_dn = sp_post_text('dienstname');
    $sp_offen_einzeln = true;
    if (!in_array($sp_was, array('anlegen', 'start', 'stop', 'restart', 'entfernen', 'holen', 'neu_anlegen'), true)
        || !in_array($sp_dn, sp_dienste(), true)) {
        $sp_fehler[] = sp_t('DIENST.FEHLER_BEFEHL');
    } elseif ($sp_was === 'neu_anlegen') {
        /* E5: der laufende Container weicht von dem ab, was das Plugin heute
         * anlegen wuerde (sp_ct_abweichung()). Neu anlegen = die beiden
         * vorhandenen Wege hintereinander: Entfernen (nur am EIGENEN
         * Container - sp_container() fasst einen fremden nicht an) und
         * Anlegen im Hintergrund. */
        $sp_erg = sp_container($sp_dn, 'entfernen');
        if ($sp_erg[0]) {
            list($sp_ok, $sp_satz) = sp_ct_vorgang_starten('anlegen', $sp_dn);
            if ($sp_ok) { $sp_meldungen[] = sp_e($sp_erg[1]); }
            else { $sp_fehler[] = sprintf(sp_t('DIENST.CONTAINER_FEHL'), sp_e($sp_dn), 'anlegen') . ' ' . sp_e($sp_satz); }
        } elseif ($sp_erg[2] === 'hinweis') {
            $sp_hinweise[] = sp_e($sp_erg[1]);
        } else {
            $sp_fehler[] = sprintf(sp_t('DIENST.CONTAINER_FEHL'), sp_e($sp_dn), 'entfernen') . ' ' . sp_e($sp_erg[1]);
        }
    } elseif ($sp_was === 'holen' || $sp_was === 'anlegen') {
        list($sp_ok, $sp_satz) = sp_ct_vorgang_starten($sp_was, $sp_dn);
        if (!$sp_ok) {
            $sp_fehler[] = sprintf(sp_t('DIENST.CONTAINER_FEHL'), sp_e($sp_dn), sp_e($sp_was)) . ' ' . sp_e($sp_satz);
        }
    } else {
        $sp_erg = sp_container($sp_dn, $sp_was);
        if ($sp_erg[0]) {
            $sp_meldungen[] = sprintf(sp_t('DIENST.CONTAINER_OK'), sp_e($sp_dn), sp_e($sp_was)) . ' ' . sp_e($sp_erg[1]);
        } elseif ($sp_erg[2] === 'hinweis') {
            $sp_hinweise[] = sp_e($sp_erg[1]);
        } else {
            $sp_fehler[] = sprintf(sp_t('DIENST.CONTAINER_FEHL'), sp_e($sp_dn), sp_e($sp_was)) . ' ' . sp_e($sp_erg[1]);
        }
    }
    $sp_tab = 'tab-services';
}
if ($sp_post && isset($_POST['containerlog'])) {
    $sp_ausgabe = sp_container_log(sp_post_text('containerlog'), 200);
    $sp_offen_einzeln = true;
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
    else { $sp_fehler[] = sprintf(sp_t('EINST.FEHLER_SPEICHERN'), sp_e($sp_p['config'])); }
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
// Nur ein Text schaltet: eine Liste (mitschnitt[]=) hiesse sonst 0 = aus.
if ($sp_post && isset($_POST['mitschnitt']) && is_string($_POST['mitschnitt'])) {
    list($sp_ok, $sp_meld) = sp_mitschnitt_schalten((int) sp_post_text('mitschnitt', '0'));
    if ($sp_ok) { $sp_meldungen[] = $sp_meld; } else { $sp_fehler[] = sp_e($sp_meld); }
    $sp_tab = 'tab-log';
}
if ($sp_post && isset($_POST['test'])) {
    // sp_test_aktion() liefert seit 0.12.0 maskiertes HTML (F2) - auch die
    // Meldung des Dienstes, die Fremdtext tragen kann.
    list($sp_stand, $sp_text) = sp_test_aktion(sp_post_text('test'));
    if ($sp_stand === 1) { $sp_meldungen[] = $sp_text; } else { $sp_fehler[] = $sp_text; }
    $sp_tab = 'tab-test';
}
if ($sp_post && isset($_POST['selbsttest'])) {
    $sp_ausgabe = sp_selbsttest_ausgabe();
    $sp_tab = 'tab-test';
}

/* ---------------- PRG: jede POST-Anfrage endet hier mit 303 ----------------
 * Downloads (Vorlage, Sicherung, CSV, Probe, Protokoll) sind vorher schon
 * mit exit ausgestiegen. Die Umleitung traegt die Kennung der
 * Einmalmeldung (F15). */
if ((isset($_SERVER['REQUEST_METHOD']) ? $_SERVER['REQUEST_METHOD'] : '') === 'POST') {
    if (preg_match('//u', $sp_ausgabe) !== 1) {
        $sp_ausgabe = (string) preg_replace('/[\x80-\xFF]/', '?', $sp_ausgabe);
    }
    $sp_fl = array('tab' => $sp_tab, 'meldungen' => $sp_meldungen, 'fehler' => $sp_fehler,
                   'hinweise' => $sp_hinweise,
                   'ausgabe' => strlen($sp_ausgabe) > 262144 ? substr($sp_ausgabe, -262144) : $sp_ausgabe);
    if ($sp_offen_einzeln) { $sp_fl['offen'] = 'einzeln'; }
    if (($sp_fehler || $sp_x2_immer) && $sp_formular_x2 !== '') {
        $sp_fl['eingaben'] = sp_eingaben_sammeln($sp_formular_x2);
        if (!$sp_x2_immer) { $sp_fl['fehler'][] = sp_t('EINST.NICHTS_GESPEICHERT'); }
    }
    $sp_kennung = sp_flash_schreiben($sp_fl, $sp_rohtext_zurueck);
    if ($sp_kennung === '') {
        sp_log('Die Einmalmeldung liess sich nicht schreiben: ' . $sp_p['datadir']);
    }
    header('Location: index.php?form=' . rawurlencode((string) preg_replace('/^tab-/', '', $sp_tab))
           . ($sp_kennung !== '' ? '&m=' . $sp_kennung : ''), true, 303);
    exit;
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
/* F6 (0.12.0): die Adresse, die Loxone aufrufen soll. Bis 0.11.15 stand
 * hier der Name aus der Adresszeile des Browsers (HTTP_HOST) - wer die
 * Oberflaeche ueber "loxberry.local", einen Tunnel oder eine
 * Weiterleitung oeffnete, bekam Adressen, die der Miniserver nicht
 * erreicht. sp_lb_adresse() (Bibliothek ab 0.12.0) nennt die LAN-Adresse
 * samt Webport aus der LoxBerry-Einstellung; ohne sie bleibt es beim
 * bisherigen Weg, und der Reiter sagt, welcher galt. */
$sp_host = sp_hostname();
$sp_lb_adr = function_exists('sp_lb_adresse') ? (string) sp_lb_adresse() : '';
$sp_adr_quelle = 'UI012.LOX_ADRESSE_LB';
if (!preg_match('#^https?://[A-Za-z0-9.\-:\[\]]{1,120}\z#', $sp_lb_adr)) {
    $sp_lb_adr = 'http://' . $sp_host;
    $sp_adr_quelle = 'UI012.LOX_ADRESSE_BROWSER';
}
$sp_basis = $sp_lb_adr . '/plugins/' . $sp_p['plugin'] . '/index.php';
$sp_logzeilen = is_file($sp_p['log']) ? sp_log_ende($sp_p['log'], 400) : array();
list($sp_ruhe_jetzt, $sp_ruhe_grund) = sp_ruhe_aktiv($sp_cfg);
$sp_praefix = trim((string) $sp_cfg['mqtt_topic'], '/');
/* A9: fehlt die Abo-Datei fuer das Gateway V1 (frische Installation, oder
 * vor Runde 2 nie geschrieben), wird sie beim Aufbau angelegt - einmal, sie
 * traegt nur das Praefix. Danach schreibt sie nur noch "MQTT speichern". */
list($sp_abo_lage) = sp_mqtt_abo_lage($sp_cfg);
if ($sp_abo_lage === 'fehlt') {
    list($sp_abo_ok) = sp_mqtt_abo_schreiben($sp_cfg);
    list($sp_abo_lage) = sp_mqtt_abo_lage($sp_cfg);
}

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
/* Seit 0.11.11: Stand des Hintergrundvorgangs und Ampel - nur im offenen
 * Reiter Dienste. Die Ampel nicht, solange ein Vorgang laeuft: die Seite
 * laedt sich dann alle 5 s neu und soll dabei nicht jedes Mal Docker
 * fragen. */
$sp_ctv = $sp_offen('tab-services') ? sp_ct_vorgang() : array('zustand' => 'keiner');
$sp_ct_amp = ($sp_offen('tab-services')
              && !in_array($sp_ctv['zustand'], array('gestartet', 'laeuft'), true))
    ? sp_ct_ampel($sp_cfg, $sp_emp) : null;

/* E7: Fassung im Kopf; der Hilfe-Link zeigt auf das eigene Repository -
 * bis 0.11.15 auf die Startseite von wiki.loxberry.de, wo es keine Seite
 * zu diesem Plugin gibt. */
$sp_fassung = sp_ui_fassung();
$sp_rahmen = class_exists('LBWeb', false);
if ($sp_rahmen) {
    LBWeb::lbheader('Sprachsteuerung lokal' . ($sp_fassung !== '' ? ' ' . $sp_fassung : ''),
                    'https://github.com/timanders22/LoxBerry-Plugin-Sprachsteuerung', 'help.html');
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
/* Ein Auswahlfeld muss man als Auswahlfeld erkennen - wortgetreu aus
   VORLAGE_hausstandard.css.html. Ohne diese Regel nimmt die Rahmen-CSS
   des LoxBerry den Pfeil weg, und hinter einem Feld, das wie ein
   Textfeld aussieht, findet niemand die Vorlagen. Zweimal am Geraet
   gemeldet, nie von einem Werkzeug: rendern.py sieht HTML, kein Bild. */
.sm-wrap select {
    appearance: none; -webkit-appearance: none; -moz-appearance: none;
    background-image: url("data:image/svg+xml;charset=UTF-8,%3Csvg xmlns='http://www.w3.org/2000/svg' width='14' height='9' viewBox='0 0 14 9'%3E%3Cpath d='M1 1l6 6 6-6' fill='none' stroke='%234f7d17' stroke-width='2'/%3E%3C/svg%3E");
    background-repeat: no-repeat; background-position: right 10px center;
    padding-right: 32px; cursor: pointer; }
.sm-tbl select { padding-right: 28px; background-position: right 7px center; }
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
/* X-2: ein beanstandetes Feld nach der Umleitung - eigene Zutat, nicht Teil der Hausvorlage. */
.sm-wrap .sm-beanstandet { border: 2px solid #c62828 !important; background: #fff5f5 !important; }
.sm-wrap input[type=checkbox].sm-beanstandet { outline: 2px solid #c62828; outline-offset: 2px; }
.sm-log { background: #1e1e1e; color: #d4d4d4; font-family: Consolas, "Courier New", monospace;
    font-size: 0.82em; padding: 12px; border-radius: 8px; max-height: 480px; overflow: auto;
    white-space: pre-wrap; }
</style>
<div class="sm-wrap">
<?php if ($sp_fassung !== '') { ?>
<p class="sm-hilfe" style="text-align:right;margin:4px 0 0;max-width:none;"><?= sp_e(sprintf(sp_t('UI012.FASSUNG'), $sp_fassung)) ?></p>
<?php } ?>

<?php foreach ($sp_meldungen as $sp_m) { ?>
<div class="sm-hinweis"><?= $sp_m ?></div>
<?php } ?>
<?php if ($sp_fehler) { ?>
<div class="sm-fehler"><b><?= sp_e(sp_t('ALLG.BEANSTANDUNG')) ?></b>
<ul style="margin:6px 0 0 18px;padding:0;">
<?php foreach ($sp_fehler as $sp_f) { ?><li><?= $sp_f ?></li><?php } ?>
</ul></div>
<?php } ?>
<?php if ($sp_hinweise) { ?>
<div class="sm-warnung"><b><?= sp_e(sp_t('ALLG.HINWEIS')) ?></b>
<ul style="margin:6px 0 0 18px;padding:0;">
<?php foreach ($sp_hinweise as $sp_h) { ?><li><?= $sp_h ?></li><?php } ?>
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
  <!-- Der grosse Wert ist die MQTT-Veroeffentlichung DIESES Plugins (mqtt_ein),
       der Autostart des Gateways steht klein darunter. Bis 0.11.9 stand hier
       der Autostart des Gateways; "MQTT ein" las sich, als sende das Plugin,
       auch wenn es gar nicht veroeffentlichte.
       Vorbild ZendureSolarFlow 0.9.21 und BatterieBMS 0.9.22. Ohne
       MQTT-Abschnitt in general.json heisst der Autostart "nicht feststellbar"
       statt "aus". -->
  <div class="sm-kachel">MQTT
    <b class="<?= !empty($sp_cfg['mqtt_ein']) ? 'sm-an' : 'sm-aus' ?>"><?= !empty($sp_cfg['mqtt_ein']) ? sp_e(sp_t('ALLG.EIN')) : sp_e(sp_t('ALLG.AUS')) ?></b>
    <span class="sm-hilfe"><?= sp_e(sprintf(sp_t('ALLG.KACHEL_MQTT_HILFE'),
        !$sp_mqtt['gefunden'] ? sp_t('ALLG.NICHT_FESTSTELLBAR')
        : ($sp_mqtt['autostart'] ? sp_t('ALLG.EIN') : sp_t('ALLG.AUS')))) ?></span>
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
<?php $sp_la = isset($sp_letzter['antwort']) && is_scalar($sp_letzter['antwort']) ? (string) $sp_letzter['antwort'] : ''; ?>
&rarr; <?= !empty($sp_letzter['ok']) ? sp_e($sp_la) : '<span class="sm-aus">' . sp_e($sp_la) . '</span>' ?>
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
/* min/max der Zahlenfelder aus templates/vorgaben.json - dieselben Grenzen,
 * die der Handler prueft. Bis 0.11.15 standen sie zusaetzlich fest im HTML
 * und konnten von der Datei abweichen. */
$sp_mm = function ($feld, $min, $max) {
    list($lo, $hi) = sp_pruef_grenze($feld, $min, $max);
    return ' min="' . (int) $lo . '" max="' . (int) $hi . '"';
};
?>
<div class="sm-tabs">
<?php foreach ($sp_reiter_ids as $sp_r) { ?>
	<a data-ajax="false" class="sm-tab<?= $sp_tab === 'tab-' . $sp_r ? ' sm-active' : '' ?>" data-ziel="tab-<?= $sp_r ?>"<?= in_array($sp_r, $sp_teuer, true) ? ' data-laden="1"' : '' ?> href="index.php?form=<?= $sp_r ?>"><?= sp_e(isset($sp_beschriftung[$sp_r]) ? sp_t($sp_beschriftung[$sp_r]) : $sp_r) ?></a>
<?php } ?>
</div>

<!-- ================= Reiter: Einstellungen ================= -->
<div class="sm-seite<?= $sp_tab === 'tab-settings' ? ' sm-active' : '' ?>" id="tab-settings">
<div class="sm-hinweis"><?= sp_t('EINST.WAS_IST_DAS') ?></div>

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
  <form action="index.php" method="post" data-ajax="false">
    <?php $sp_hidden('tab-settings'); ?>
    <button data-role="none" class="sm-btn sm-b-lesen" type="submit" name="dienst" value="start"><?= sp_e(sp_t('EINST.K_START')) ?></button>
  </form>
  <form action="index.php" method="post" data-ajax="false">
    <?php $sp_hidden('tab-settings'); ?>
    <button data-role="none" class="sm-btn sm-b-aktion" type="submit" name="dienst" value="restart"><?= sp_e(sp_t('EINST.K_RESTART')) ?></button>
  </form>
  <form action="index.php" method="post" data-ajax="false">
    <?php $sp_hidden('tab-settings'); ?>
    <button data-role="none" class="sm-btn sm-b-aktion" type="submit" name="dienst" value="stop"><?= sp_e(sp_t('EINST.K_STOP')) ?></button>
  </form>
</div>
<p class="sm-hilfe"><?= sp_e(sp_t('UI013.HINWEIS_WAECHTER')) ?></p>

<?php /* Zwei Formulare tragen name="speichern", weil der Knopf 'Probe
   holen' dazwischenliegt und kein Formular im Formular stehen darf. Das
   Kennzeichen sagt dem Handler, WELCHES abgeschickt wurde - ohne das
   behandelte er die Felder des jeweils anderen als leer und wies sie ab.
   Gemessen am 02.09.2026: der Speichern-Knopf speicherte gar nichts. */ ?>
<form action="index.php" method="post" data-ajax="false" autocomplete="off">
<?php $sp_hidden('tab-settings'); ?>
<input data-role="none" type="hidden" name="speichern" value="1">
<input data-role="none" type="hidden" name="formular" value="dienste">

<h2><?= sp_e(sp_t('EINST.H_DIENSTE')) ?></h2>
<p class="sm-hilfe"><?= sp_t('EINST.DIENSTE_ERKLAERUNG') ?></p>
<div class="sm-roll">
<table class="sm-tbl">
<tr><th><?= sp_e(sp_t('EINST.T_DIENST')) ?></th><th><?= sp_e(sp_t('EINST.T_ADRESSE')) ?></th><th><?= sp_e(sp_t('EINST.T_PORT')) ?></th></tr>
<?php foreach (array('whisper', 'piper', 'wake', 'llm') as $sp_d) { ?>
<tr><td><?= sp_e(sp_t('EINST.L_' . strtoupper($sp_d))) ?></td>
    <td><input data-role="none" type="text" name="<?= $sp_d ?>_host" value="<?= sp_e(sp_x2_wert($sp_d . '_host', $sp_cfg[$sp_d . '_host'])) ?>" size="16"<?= sp_x2_mark($sp_d . '_host') ?>></td>
    <td><input data-role="none" type="text" name="<?= $sp_d ?>_port" value="<?= sp_e(sp_x2_wert($sp_d . '_port', (int) $sp_cfg[$sp_d . '_port'])) ?>" size="6"<?= sp_x2_mark($sp_d . '_port') ?>></td></tr>
<?php } ?>
</table>
</div>

<h2><?= sp_e(sp_t('EINST.H_VERSTEHEN')) ?></h2>
<div class="sm-hinweis"><?= sp_t('EINST.VERSTEHEN_ERKLAERUNG') ?></div>
<div class="sm-feld">
  <label style="display:inline-flex;align-items:center;gap:8px;">
    <input data-role="none" type="checkbox" name="llm_ein" value="1" <?= sp_x2_haken('llm_ein', !empty($sp_cfg['llm_ein'])) ? 'checked' : '' ?>>
    <?= sp_e(sp_t('EINST.L_LLM_EIN')) ?>
  </label>
  <div class="sm-hilfe"><?= sp_t('EINST.H_LLM_EIN') ?></div>
</div>
<div class="sm-feld">
  <label style="display:inline-flex;align-items:center;gap:8px;">
    <input data-role="none" type="checkbox" name="antwort_sprechen" value="1" <?= sp_x2_haken('antwort_sprechen', !empty($sp_cfg['antwort_sprechen'])) ? 'checked' : '' ?>>
    <?= sp_e(sp_t('EINST.L_ANTWORT')) ?>
  </label>
</div>
<div class="sm-feld">
  <label for="kontext_s"><?= sp_e(sp_t('EINST.L_KONTEXT_S')) ?></label>
  <input data-role="none" type="number" id="kontext_s" name="kontext_s" value="<?= sp_e(sp_x2_wert('kontext_s', (int) $sp_cfg['kontext_s'])) ?>"<?= $sp_mm('kontext_s', 0, 300) ?><?= sp_x2_mark('kontext_s') ?>>
  <div class="sm-hilfe"><?= sp_t('EINST.H_KONTEXT_S') ?></div>
</div>
<div class="sm-feld">
  <label for="bestaetigung_s"><?= sp_e(sp_t('EINST.L_BESTAETIGUNG_S')) ?></label>
  <input data-role="none" type="number" id="bestaetigung_s" name="bestaetigung_s" value="<?= sp_e(sp_x2_wert('bestaetigung_s', (int) $sp_cfg['bestaetigung_s'])) ?>"<?= $sp_mm('bestaetigung_s', 0, 120) ?><?= sp_x2_mark('bestaetigung_s') ?>>
  <div class="sm-hilfe"><?= sp_t('EINST.H_BESTAETIGUNG_S') ?></div>
</div>

<h2><?= sp_e(sp_t('EINST.H_ANTWORTWEG')) ?></h2>
<div class="sm-hinweis"><?= sp_t('EINST.H_ANTWORTWEG_TEXT') ?></div>
<div class="sm-feld">
  <label for="antwortweg"><?= sp_e(sp_t('EINST.L_ANTWORTWEG')) ?></label>
  <select data-role="none" id="antwortweg" name="antwortweg"<?= sp_x2_mark('antwortweg') ?>>
<?php foreach (sp_auswahl('antwortweg') as $sp_w) { ?>
    <option value="<?= sp_e($sp_w) ?>"<?= sp_x2_wert('antwortweg', $sp_cfg['antwortweg']) === $sp_w ? ' selected' : '' ?>><?= sp_e(sp_t('EINST.WEG_' . strtoupper($sp_w))) ?></option>
<?php } ?>
  </select>
  <div class="sm-hilfe"><?= sp_t('EINST.H_ANTWORTWEG_FELD') ?></div>
</div>
<div class="sm-feld">
  <label for="tts_mode"><?= sp_e(sp_t('EINST.L_TTS_MODE')) ?></label>
  <select data-role="none" id="tts_mode" name="tts_mode"<?= sp_x2_mark('tts_mode') ?>>
<?php /* sp_tts_modi(): die Liste aus vorgaben.json, notfalls um Sonos4Lox
   ergaenzt (Runde 2). Fuer sonos4lox steht die Beschriftung in [UI013]. */
foreach (sp_tts_modi() as $sp_m) { ?>
    <option value="<?= sp_e($sp_m) ?>"<?= sp_x2_wert('tts_mode', $sp_cfg['tts']['mode']) === $sp_m ? ' selected' : '' ?>><?= sp_e($sp_m === 'sonos4lox' ? sp_t('UI013.TTS_SONOS4LOX') : sp_t('EINST.TTS_' . strtoupper($sp_m))) ?></option>
<?php } ?>
  </select>
  <div class="sm-hilfe"><?= sp_t('EINST.H_TTS_MODE') ?></div>
<?php
/* E6: sind die beiden Plugins, ueber die Alexa-NG und Google-Lautsprecher
 * sprechen, installiert - und in welcher Fassung? Nur mit sp_plugin_info()
 * aus der Bibliothek (ab 0.12.0); ohne sie steht hier nichts, statt etwas
 * zu raten. Chromecast 4 Lox NG nimmt Ansagen erst ab 1.3.15 an. */
if (function_exists('sp_plugin_info')) { ?>
  <ul class="sm-hilfe">
<?php foreach (array('alexang' => array('Alexa-NG', ''), 'chromecast-4lox-ng' => array('Chromecast 4 Lox NG', '1.3.15'),
                     'sonos4lox' => array('Sonos4Lox', '')) as $sp_pn => $sp_pi) {
    $sp_inf = sp_plugin_info($sp_pn);
    if (!is_array($sp_inf)) { ?>
    <li><?= sp_e(sprintf(sp_t('UI012.PLUGIN_FEHLT'), $sp_pi[0])) ?></li>
<?php continue; }
    $sp_pv = isset($sp_inf['version']) && is_scalar($sp_inf['version']) ? (string) $sp_inf['version'] : '';
    $sp_po = isset($sp_inf['ordner']) && is_string($sp_inf['ordner']) && preg_match('/^[A-Za-z0-9_.\-]{1,64}\z/', $sp_inf['ordner'])
           ? $sp_inf['ordner'] : $sp_pn; ?>
    <li><?= sp_e(sprintf(sp_t('UI012.PLUGIN_DA'), $sp_pi[0], $sp_pv !== '' ? $sp_pv : '?')) ?>
      <a data-ajax="false" href="/admin/plugins/<?= sp_e($sp_po) ?>/" target="_blank"><?= sp_e(sp_t('UI012.PLUGIN_EINSTELLUNGEN')) ?></a>
<?php if ($sp_pi[1] !== '' && $sp_pv !== '' && version_compare($sp_pv, $sp_pi[1], '<')) { ?>
      &mdash; <span class="sm-aus"><?= sp_e(sprintf(sp_t('UI012.PLUGIN_ZU_ALT'), $sp_pi[0], $sp_pi[1])) ?></span>
<?php } ?></li>
<?php } ?>
  </ul>
<?php } ?>
</div>
<div class="sm-feld">
  <label for="tts_ip"><?= sp_e(sp_t('EINST.L_TTS_IP')) ?></label>
  <input data-role="none" type="text" id="tts_ip" name="tts_ip" value="<?= sp_e(sp_x2_wert('tts_ip', $sp_cfg['tts']['ip'])) ?>" placeholder="192.168.1.20"<?= sp_x2_mark('tts_ip') ?>>
</div>
<div class="sm-feld">
  <label for="tts_port"><?= sp_e(sp_t('EINST.L_TTS_PORT')) ?></label>
  <input data-role="none" type="number" id="tts_port" name="tts_port" value="<?= sp_e(sp_x2_wert('tts_port', (int) $sp_cfg['tts']['port'])) ?>"<?= $sp_mm('tts_port', 1, 65535) ?><?= sp_x2_mark('tts_port') ?>>
</div>
<div class="sm-feld">
  <label for="tts_zones"><?= sp_e(sp_t('EINST.L_TTS_ZONES')) ?></label>
  <input data-role="none" type="text" id="tts_zones" name="tts_zones" value="<?= sp_e(sp_x2_wert('tts_zones', $sp_cfg['tts']['zones'])) ?>" placeholder="2,4"<?= sp_x2_mark('tts_zones') ?>>
  <div class="sm-hilfe"><?= sp_t('EINST.H_TTS_ZONES') ?></div>
</div>
<div class="sm-feld">
  <label for="tts_volume"><?= sp_e(sp_t('EINST.L_TTS_VOLUME')) ?></label>
  <input data-role="none" type="number" id="tts_volume" name="tts_volume" value="<?= sp_e(sp_x2_wert('tts_volume', (int) $sp_cfg['tts']['volume'])) ?>"<?= $sp_mm('tts_volume', 1, 100) ?><?= sp_x2_mark('tts_volume') ?>>
</div>
<div class="sm-feld">
  <label for="tts_lang"><?= sp_e(sp_t('EINST.L_TTS_LANG')) ?></label>
  <input data-role="none" type="text" id="tts_lang" name="tts_lang" value="<?= sp_e(sp_x2_wert('tts_lang', $sp_cfg['tts']['lang'])) ?>" maxlength="5"<?= sp_x2_mark('tts_lang') ?>>
</div>
<div class="sm-feld">
  <label for="tts_stimme"><?= sp_e(sp_t('EINST.L_TTS_STIMME')) ?></label>
  <input data-role="none" type="text" id="tts_stimme" name="tts_stimme" value="<?= sp_e(sp_x2_wert('tts_stimme', $sp_cfg['tts']['stimme'])) ?>" placeholder="<?= sp_e($sp_cfg['piper_stimme']) ?>"<?= sp_x2_mark('tts_stimme') ?>>
  <div class="sm-hilfe"><?= sp_t('EINST.H_TTS_STIMME') ?></div>
</div>
<h3><?= sp_e(sp_t('EINST.H_ZUSATZ')) ?></h3>
<div class="sm-hilfe"><?= sp_t('EINST.H_ZUSATZ_TEXT') ?></div>
<div class="sm-feld">
  <label for="cc_praefix"><?= sp_e(sp_t('EINST.L_CC_PRAEFIX')) ?></label>
  <input data-role="none" type="text" id="cc_praefix" name="cc_praefix" value="<?= sp_e(sp_x2_wert('cc_praefix', $sp_cfg['tts']['cc_praefix'])) ?>" placeholder="chromecast4lox"<?= sp_x2_mark('cc_praefix') ?>>
  <div class="sm-hilfe"><?= sp_t('EINST.H_CC_PRAEFIX') ?></div>
</div>
<div class="sm-feld">
  <label for="cc_ziel"><?= sp_e(sp_t('EINST.L_CC_ZIEL')) ?></label>
  <input data-role="none" type="text" id="cc_ziel" name="cc_ziel" list="sp_cc_liste" value="<?= sp_e(sp_x2_wert('cc_ziel', $sp_cfg['tts']['cc_ziel'])) ?>" placeholder="alle"<?= sp_x2_mark('cc_ziel') ?>>
<?php
/* E3: Auswahl mit Freitext - die Lautsprecher, die Chromecast4lox am
 * Broker meldet. Nur bei gewaehltem Chromecast4lox im offenen Reiter
 * (sonst fragte jeder Seitenaufbau den Broker); Google- und Alexa-Geraete
 * nennt keine Quelle, die das Plugin lesen kann - dort bleibt es Freitext. */
$sp_cc_l = ($sp_offen('tab-settings') && $sp_cfg['tts']['mode'] === 'chromecast')
         ? sp_ui_cc_lautsprecher((string) $sp_cfg['tts']['cc_praefix']) : array(); ?>
  <datalist id="sp_cc_liste"><option value="alle"></option>
<?php foreach ($sp_cc_l as $sp_g) { ?>    <option value="<?= sp_e($sp_g) ?>"></option>
<?php } ?>
  </datalist>
  <div class="sm-hilfe"><?= sp_t('EINST.H_CC_ZIEL') ?><?= $sp_cc_l ? ' ' . sp_e(sprintf(sp_t('UI012.CC_GEFUNDEN'), count($sp_cc_l))) : '' ?></div>
</div>
<div class="sm-feld">
  <label for="alexa_geraet"><?= sp_e(sp_t('EINST.L_ALEXA_GERAET')) ?></label>
  <input data-role="none" type="text" id="alexa_geraet" name="alexa_geraet" value="<?= sp_e(sp_x2_wert('alexa_geraet', $sp_cfg['tts']['alexa_geraet'])) ?>" placeholder="<?= sp_e(sp_t('UI012.P_ALEXA_GERAET')) ?>"<?= sp_x2_mark('alexa_geraet') ?>>
  <div class="sm-hilfe"><?= sp_t('EINST.H_ALEXA_GERAET') ?></div>
</div>
<div class="sm-feld">
  <label for="alexa_token"><?= sp_e(sp_t('EINST.L_ALEXA_TOKEN')) ?></label>
<?php /* Das Token reist NIE ins Formular zurueck (wie ein Kennwort):
   angezeigt wird nur, ob eins gespeichert ist und wie lang es ist. */ ?>
  <input data-role="none" type="password" id="alexa_token" name="alexa_token" value="" autocomplete="new-password" placeholder="<?= $sp_cfg['tts']['alexa_token'] !== '' ? sp_e(sprintf(sp_t('EINST.P_ALEXA_TOKEN_GESETZT'), strlen((string) $sp_cfg['tts']['alexa_token']))) : sp_e(sp_t('EINST.P_ALEXA_TOKEN_LEER')) ?>"<?= sp_x2_mark('alexa_token') ?>>
  <div class="sm-hilfe"><?= sp_t('EINST.H_ALEXA_TOKEN') ?></div>
  <label style="display:inline-flex;align-items:center;gap:8px;margin-top:6px;font-weight:400;">
    <input data-role="none" type="checkbox" name="alexa_token_loeschen" value="1"<?= sp_x2_haken('alexa_token_loeschen', false) ? ' checked' : '' ?>>
    <?= sp_e(sp_t('EINST.L_ALEXA_TOKEN_LOESCHEN')) ?>
  </label>
</div>
<div class="sm-feld">
  <label for="alexa_laut"><?= sp_e(sp_t('EINST.L_ALEXA_LAUT')) ?></label>
  <input data-role="none" type="number" id="alexa_laut" name="alexa_laut" value="<?= sp_e(sp_x2_wert('alexa_laut', (int) $sp_cfg['tts']['alexa_laut'] >= 0 ? (int) $sp_cfg['tts']['alexa_laut'] : '')) ?>"<?= $sp_mm('alexa_laut', 1, 100) ?><?= sp_x2_mark('alexa_laut') ?>>
  <div class="sm-hilfe"><?= sp_t('EINST.H_ALEXA_LAUT') ?></div>
</div>
<h3><?= sp_e(sp_t('EINST.H_GOOGLE')) ?></h3>
<div class="sm-hilfe"><?= sp_t('EINST.H_GOOGLE_TEXT') ?></div>
<div class="sm-feld">
  <label for="google_geraet"><?= sp_e(sp_t('EINST.L_GOOGLE_GERAET')) ?></label>
  <input data-role="none" type="text" id="google_geraet" name="google_geraet" value="<?= sp_e(sp_x2_wert('google_geraet', $sp_cfg['tts']['google_geraet'])) ?>" placeholder="<?= sp_e(sp_t('UI012.P_GOOGLE_GERAET')) ?>"<?= sp_x2_mark('google_geraet') ?>>
  <div class="sm-hilfe"><?= sp_t('EINST.H_GOOGLE_GERAET') ?></div>
</div>
<div class="sm-feld">
  <label for="google_token"><?= sp_e(sp_t('EINST.L_GOOGLE_TOKEN')) ?></label>
<?php /* Ansage-3: wie das Alexa-Token - es reist NIE ins Formular zurueck. */ ?>
  <input data-role="none" type="password" id="google_token" name="google_token" value="" autocomplete="new-password" placeholder="<?= $sp_cfg['tts']['google_token'] !== '' ? sp_e(sprintf(sp_t('EINST.P_ALEXA_TOKEN_GESETZT'), strlen((string) $sp_cfg['tts']['google_token']))) : sp_e(sp_t('EINST.P_ALEXA_TOKEN_LEER')) ?>"<?= sp_x2_mark('google_token') ?>>
  <div class="sm-hilfe"><?= sp_t('EINST.H_GOOGLE_TOKEN') ?></div>
  <label style="display:inline-flex;align-items:center;gap:8px;margin-top:6px;font-weight:400;">
    <input data-role="none" type="checkbox" name="google_token_loeschen" value="1"<?= sp_x2_haken('google_token_loeschen', false) ? ' checked' : '' ?>>
    <?= sp_e(sp_t('EINST.L_GOOGLE_TOKEN_LOESCHEN')) ?>
  </label>
</div>
<div class="sm-feld">
  <label for="google_laut"><?= sp_e(sp_t('EINST.L_GOOGLE_LAUT')) ?></label>
  <input data-role="none" type="number" id="google_laut" name="google_laut" value="<?= sp_e(sp_x2_wert('google_laut', (int) $sp_cfg['tts']['google_laut'] >= 0 ? (int) $sp_cfg['tts']['google_laut'] : '')) ?>"<?= $sp_mm('google_laut', 1, 100) ?><?= sp_x2_mark('google_laut') ?>>
  <div class="sm-hilfe"><?= sp_t('EINST.H_GOOGLE_LAUT') ?></div>
</div>
<?php /* S1 (Runde 2): Sonos4Lox. Die Feldnamen sind die der gemeinsamen
   Sprachausgabe (tts_sonos_zone, tts_sonos_laut - ansage_feldnamen()). */
$sp_sz = isset($sp_cfg['tts']['sonos_zone']) && is_string($sp_cfg['tts']['sonos_zone']) ? $sp_cfg['tts']['sonos_zone'] : '';
$sp_sl = isset($sp_cfg['tts']['sonos_laut']) ? (int) $sp_cfg['tts']['sonos_laut'] : -1; ?>
<h3><?= sp_e(sp_t('UI013.H_SONOS')) ?></h3>
<div class="sm-hilfe"><?= sp_e(sp_t('UI013.H_SONOS_TEXT')) ?></div>
<div class="sm-feld">
  <label for="tts_sonos_zone"><?= sp_e(sp_t('UI013.L_SONOS_ZONE')) ?></label>
  <input data-role="none" type="text" id="tts_sonos_zone" name="tts_sonos_zone" maxlength="200" value="<?= sp_e(sp_x2_wert('tts_sonos_zone', $sp_sz)) ?>" placeholder="<?= sp_e(sp_t('UI013.P_SONOS_ZONE')) ?>"<?= sp_x2_mark('tts_sonos_zone') ?>>
  <div class="sm-hilfe"><?= sp_e(sp_t('UI013.H_SONOS_ZONE')) ?></div>
</div>
<div class="sm-feld">
  <label for="tts_sonos_laut"><?= sp_e(sp_t('UI013.L_SONOS_LAUT')) ?></label>
  <input data-role="none" type="number" id="tts_sonos_laut" name="tts_sonos_laut" value="<?= sp_e(sp_x2_wert('tts_sonos_laut', $sp_sl >= 1 ? $sp_sl : '')) ?>" min="1" max="100"<?= sp_x2_mark('tts_sonos_laut') ?>>
  <div class="sm-hilfe"><?= sp_e(sp_t('UI013.H_SONOS_LAUT')) ?></div>
</div>
<?php /* S5/S6 (Runde 2): Musik leiser waehrend einer Ansage und Musik per
   Sprache steuern - beides ueber den Music Server bzw. MusicServer4Home. */ ?>
<h3><?= sp_e(sp_t('UI013.H_MUSIK')) ?></h3>
<div class="sm-hilfe"><?= sp_e(sp_t('UI013.H_MUSIK_TEXT')) ?></div>
<?php if ((!empty($sp_cfg['musik_ducken']) || !empty($sp_cfg['musik_steuern']))
          && (string) $sp_cfg['tts']['mode'] !== 'musicserver') { ?>
<div class="sm-warnung"><?= sp_e(sp_t('UI013.HINWEIS_MUSIK_OHNE_MS')) ?></div>
<?php } ?>
<div class="sm-feld">
  <label style="display:inline-flex;align-items:center;gap:8px;">
    <input data-role="none" type="checkbox" name="musik_ducken" value="1"<?= sp_x2_haken('musik_ducken', !empty($sp_cfg['musik_ducken'])) ? ' checked' : '' ?><?= sp_x2_mark('musik_ducken') ?>>
    <?= sp_e(sp_t('UI013.L_MUSIK_DUCKEN')) ?>
  </label>
</div>
<div class="sm-feld">
  <label for="musik_ducken_laut"><?= sp_e(sp_t('UI013.L_MUSIK_DUCKEN_LAUT')) ?></label>
  <input data-role="none" type="number" id="musik_ducken_laut" name="musik_ducken_laut" value="<?= sp_e(sp_x2_wert('musik_ducken_laut', (int) $sp_cfg['musik_ducken_laut'])) ?>"<?= $sp_mm('musik_ducken_laut', 0, 100) ?><?= sp_x2_mark('musik_ducken_laut') ?>>
  <div class="sm-hilfe"><?= sp_e(sp_t('UI013.H_MUSIK_DUCKEN_LAUT')) ?></div>
</div>
<div class="sm-feld">
  <label style="display:inline-flex;align-items:center;gap:8px;">
    <input data-role="none" type="checkbox" name="musik_steuern" value="1"<?= sp_x2_haken('musik_steuern', !empty($sp_cfg['musik_steuern'])) ? ' checked' : '' ?><?= sp_x2_mark('musik_steuern') ?>>
    <?= sp_e(sp_t('UI013.L_MUSIK_STEUERN')) ?>
  </label>
  <div class="sm-hilfe"><?= sp_e(sp_t('UI013.H_MUSIK_STEUERN')) ?></div>
</div>
<div class="sm-knopfreihe">
  <button data-role="none" class="sm-btn sm-b-aktion" type="submit"><?= sp_e(sp_t('ALLG.SPEICHERN')) ?></button>
</div>
<div class="sm-feld">
  <label for="probe_text"><?= sp_e(sp_t('EINST.L_PROBE_TEXT')) ?></label>
  <input data-role="none" type="text" id="probe_text" name="probe_text" value="<?= sp_e(sp_x2_wert('probe_text', sp_t('UI012.PROBE_TEXT'))) ?>">
  <div class="sm-hilfe"><?= sp_t('EINST.H_PROBE') ?> <?= sp_e(sp_t('UI012.H_PROBE_OHNE_SPEICHERN')) ?></div>
</div>
<div class="sm-legende">
<span><i class="sm-punkt sm-b-aktion"></i> <?= sp_t('LEGENDE.AKTION') ?></span>
<span><i class="sm-punkt sm-b-technik"></i> <?= sp_t('LEGENDE.TECHNIK') ?></span>
</div>
<?php /* F4: dieser Knopf speichert nichts - der Handler "Einstellungen
   speichern" laeuft bei probe_stimme nicht an. Er bleibt in diesem
   Formular, damit die eben eingetippte Stimme (tts_stimme) mitkommt. */ ?>
<div class="sm-knopfreihe">
  <button data-role="none" class="sm-btn sm-b-technik" type="submit" name="probe_stimme" value="1"><?= sp_e(sp_t('EINST.K_PROBE')) ?></button>
</div>
</form>
<?php if (is_file($sp_p['datadir'] . '/probe.wav')) { ?>
<div class="sm-knopfreihe">
  <form action="index.php" method="post" data-ajax="false">
    <?php $sp_hidden('tab-settings'); ?>
    <button data-role="none" class="sm-btn sm-b-technik" type="submit" name="probe_holen" value="1"><?= sp_e(sp_t('EINST.K_PROBE_HOLEN')) ?></button>
  </form>
</div>
<?php } ?>
<form action="index.php" method="post" data-ajax="false" autocomplete="off">
<?php $sp_hidden('tab-settings'); ?>
<input data-role="none" type="hidden" name="speichern" value="1">
<input data-role="none" type="hidden" name="formular" value="ansagen">
<div class="sm-feld">
  <label for="tts_template"><?= sp_e(sp_t('EINST.L_TTS_TEMPLATE')) ?></label>
  <input data-role="none" type="text" id="tts_template" name="tts_template" value="<?= sp_e(sp_x2_wert('tts_template', $sp_cfg['tts']['template'])) ?>"<?= sp_x2_mark('tts_template') ?> placeholder="http://{ip}:{port}/tts?text={text}&amp;zone={zones}&amp;vol={vol}">
  <div class="sm-hilfe"><?= sp_t('EINST.H_TTS_TEMPLATE') ?></div>
</div>

<h2><?= sp_e(sp_t('EINST.H_RUHE')) ?></h2>
<div class="sm-warnung"><?= sp_t('EINST.H_RUHE_TEXT') ?></div>
<div class="sm-feld">
  <label style="display:inline-flex;align-items:center;gap:8px;">
    <input data-role="none" type="checkbox" name="ruhe_ein" value="1" <?= sp_x2_haken('ruhe_ein', !empty($sp_cfg['ruhe']['ein'])) ? 'checked' : '' ?>>
    <?= sp_e(sp_t('EINST.L_RUHE_EIN')) ?>
  </label>
</div>
<div class="sm-feld">
  <label for="ruhe_von"><?= sp_e(sp_t('EINST.L_RUHE_VON')) ?></label>
  <input data-role="none" type="text" id="ruhe_von" name="ruhe_von" value="<?= sp_e(sp_x2_wert('ruhe_von', $sp_cfg['ruhe']['von'])) ?>" size="6" placeholder="22:00" pattern="([01]?[0-9]|2[0-3]):[0-5][0-9]"<?= sp_x2_mark('ruhe_von') ?>>
</div>
<div class="sm-feld">
  <label for="ruhe_bis"><?= sp_e(sp_t('EINST.L_RUHE_BIS')) ?></label>
  <input data-role="none" type="text" id="ruhe_bis" name="ruhe_bis" value="<?= sp_e(sp_x2_wert('ruhe_bis', $sp_cfg['ruhe']['bis'])) ?>" size="6" placeholder="07:00" pattern="([01]?[0-9]|2[0-3]):[0-5][0-9]|24:00"<?= sp_x2_mark('ruhe_bis') ?>>
  <div class="sm-hilfe"><?= sp_e(sp_t('UI013.H_RUHE_BIS')) ?></div>
</div>
<div class="sm-feld">
  <label for="ansage_abstand_s"><?= sp_e(sp_t('EINST.L_ANSAGE_ABSTAND_S')) ?></label>
  <input data-role="none" type="number" id="ansage_abstand_s" name="ansage_abstand_s" value="<?= sp_e(sp_x2_wert('ansage_abstand_s', (int) $sp_cfg['ansage_abstand_s'])) ?>"<?= $sp_mm('ansage_abstand_s', 0, 3600) ?><?= sp_x2_mark('ansage_abstand_s') ?>>
  <div class="sm-hilfe"><?= sp_t('EINST.H_ANSAGE_ABSTAND_S') ?></div>
</div>
<div class="sm-feld">
  <label for="ansage_je_tag"><?= sp_e(sp_t('EINST.L_ANSAGE_JE_TAG')) ?></label>
  <input data-role="none" type="number" id="ansage_je_tag" name="ansage_je_tag" value="<?= sp_e(sp_x2_wert('ansage_je_tag', (int) $sp_cfg['ansage_je_tag'])) ?>"<?= $sp_mm('ansage_je_tag', 0, 500) ?><?= sp_x2_mark('ansage_je_tag') ?>>
  <div class="sm-hilfe"><?= sp_t('EINST.H_ANSAGE_JE_TAG') ?></div>
</div>

<h2><?= sp_e(sp_t('EINST.H_LOXONE')) ?></h2>
<div class="sm-feld">
  <label for="miniserver_url"><?= sp_e(sp_t('EINST.L_URL')) ?></label>
  <input data-role="none" type="text" id="miniserver_url" name="miniserver_url" value="<?= sp_e(sp_url_maskiert((string) $sp_cfg['miniserver_url'])) ?>"<?= sp_x2_mark('miniserver_url') ?>>
  <div class="sm-hilfe"><?= sp_t('EINST.H_URL') ?></div>
  <label style="display:inline-flex;align-items:center;gap:8px;margin-top:6px;font-weight:400;">
    <input data-role="none" type="checkbox" name="miniserver_url_loeschen" value="1"<?= sp_x2_haken('miniserver_url_loeschen', false) ? ' checked' : '' ?>>
    <?= sp_e(sp_t('EINST.L_URL_LOESCHEN')) ?>
  </label>
</div>
<div class="sm-feld">
  <label for="wakeword"><?= sp_e(sp_t('EINST.L_WAKEWORD')) ?></label>
  <select data-role="none" id="wakeword" name="wakeword"<?= sp_x2_mark('wakeword') ?>>
<?php
$sp_ww_liste = sp_wakewords();
if (!in_array((string) $sp_cfg['wakeword'], $sp_ww_liste, true) && $sp_cfg['wakeword'] !== '') {
    $sp_ww_liste[] = (string) $sp_cfg['wakeword'];
}
$sp_ww_ist = sp_x2_wert('wakeword', $sp_cfg['wakeword']);
/* F13: dieselbe Regel wie bei den Modellen - Auswahl, darunter ein
 * Freitext, und der Freitext gewinnt, wenn er gefuellt ist. Die leere
 * Wahl "eigener Wert" bleibt fuer den Fall, dass nur der Freitext gilt. */
foreach ($sp_ww_liste as $sp_w) { ?>
    <option value="<?= sp_e($sp_w) ?>"<?= $sp_ww_ist === $sp_w ? ' selected' : '' ?>><?= sp_e($sp_w) ?></option>
<?php } ?>
    <option value=""<?= $sp_ww_ist === '' ? ' selected' : '' ?>><?= sp_e(sp_t('ALLG.EIGENER_WERT')) ?></option>
  </select>
  <input data-role="none" type="text" name="wakeword_frei" value="<?= sp_e(sp_x2_wert('wakeword_frei', '')) ?>" placeholder="<?= sp_e(sp_t('ALLG.EIGENER_WERT_H')) ?>" style="margin-top:6px;"<?= sp_x2_mark('wakeword_frei') ?>>
  <div class="sm-hilfe"><?= sp_t('EINST.H_WAKEWORD') ?> <?= sp_e(sp_t('UI012.H_FREITEXT_GEWINNT')) ?></div>
</div>
<div class="sm-feld">
  <label for="sprache"><?= sp_e(sp_t('EINST.L_SPRACHE')) ?></label>
<?php
/* F13: eine Auswahl statt zweier Buchstaben - Satzdateien gibt es fuer de
 * und en. Steht nichts Brauchbares in der Konfiguration, ist die Sprache
 * der LoxBerry-Oberflaeche vorgewaehlt. */
$sp_spr_ist = sp_x2_wert('sprache', $sp_cfg['sprache']);
if (!in_array($sp_spr_ist, sp_pruef_sprachen(), true) && !sp_x2_aktiv('sprache')) { $sp_spr_ist = sp_sprache(); }
?>
  <select data-role="none" id="sprache" name="sprache"<?= sp_x2_mark('sprache') ?>>
<?php foreach (sp_pruef_sprachen() as $sp_w) { ?>
    <option value="<?= sp_e($sp_w) ?>"<?= $sp_spr_ist === $sp_w ? ' selected' : '' ?>><?= sp_e(sp_t('UI012.SPRACHE_' . strtoupper($sp_w))) ?></option>
<?php } ?>
  </select>
</div>
<div class="sm-feld">
  <label for="wartezeit"><?= sp_e(sp_t('EINST.L_WARTEZEIT')) ?></label>
  <input data-role="none" type="number" id="wartezeit" name="wartezeit" value="<?= sp_e(sp_x2_wert('wartezeit', (int) $sp_cfg['wartezeit'])) ?>"<?= $sp_mm('wartezeit', 1, 12) ?><?= sp_x2_mark('wartezeit') ?>>
  <div class="sm-hilfe"><?= sp_t('EINST.H_WARTEZEIT') ?></div>
</div>
<div class="sm-feld">
  <label for="verlauf_zeilen"><?= sp_e(sp_t('EINST.L_VERLAUF_ZEILEN')) ?></label>
  <input data-role="none" type="number" id="verlauf_zeilen" name="verlauf_zeilen" value="<?= sp_e(sp_x2_wert('verlauf_zeilen', (int) $sp_cfg['verlauf_zeilen'])) ?>"<?= $sp_mm('verlauf_zeilen', 5, 500) ?><?= sp_x2_mark('verlauf_zeilen') ?>>
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
<?php /* A9 (Runde 2): die Abo-Datei, die das Gateway V1 von selbst liest. */ ?>
<div class="<?= $sp_abo_lage === 'passt' ? 'sm-hinweis' : 'sm-warnung' ?>"><b><?= sp_e(sp_t('UI013.H_ABO_DATEI')) ?></b>
<span class="sm-mono"><?= sp_e(sp_mqtt_abo_datei()) ?></span><br>
<?php if ($sp_abo_lage === 'passt') { ?>
<?= sp_e(sprintf(sp_t('UI013.ABO_DATEI_PASST'), trim(sp_mqtt_abo_inhalt($sp_cfg)))) ?>
<?php } elseif ($sp_abo_lage === 'anders') { ?>
<?= sp_e(sprintf(sp_t('UI013.ABO_DATEI_ANDERS'), trim(sp_mqtt_abo_inhalt($sp_cfg)))) ?>
<?php } else { ?>
<?= sp_e(sprintf(sp_t('UI013.ABO_DATEI_FEHLER'), sp_mqtt_abo_datei())) ?>
<?php } ?>
<br><?= sp_e(sp_t($sp_gw['fassung'] >= 2 ? 'UI013.ABO_DATEI_V2' : 'UI013.ABO_DATEI_V1')) ?>
<br><?= sp_e(sp_t('UI013.ABO_KEINE_UMWANDLUNG')) ?></div>

<h3><?= sp_e(sp_t('MQTT.H_THEMEN')) ?></h3>
<p class="sm-hilfe"><?= sp_t('MQTT.THEMEN_ERKLAERUNG') ?></p>
<p class="sm-hilfe"><?= sp_t('MQTT.RETAIN_ERKLAERUNG') ?></p>
<div class="sm-hinweis"><?= sp_t('MQTT.RETAIN_GEMESSEN') ?></div>
<div class="sm-roll">
<table class="sm-tbl">
<tr><th><?= sp_e(sp_t('MQTT.T_THEMA')) ?></th><th><?= sp_e(sp_t('MQTT.T_BEDEUTUNG')) ?></th><th><?= sp_e(sp_t('MQTT.T_RETAIN')) ?></th></tr>
<?php
$sp_themen = array(
    'satz' => 'MQTT.B_SATZ', 'absicht' => 'MQTT.B_ABSICHT', 'aktion' => 'MQTT.B_AKTION',
    'ziel' => 'MQTT.B_ZIEL', 'wert' => 'MQTT.B_WERT', 'einheit' => 'MQTT.B_EINHEIT',
    'quelle' => 'MQTT.B_QUELLE', 'mikrofon' => 'MQTT.B_MIKROFON', 'zeit' => 'MQTT.B_ZEIT',
    '&lt;Thema&gt;/aktion' => 'MQTT.B_ZIELTHEMA',
    '&lt;Thema&gt;/wert' => 'MQTT.B_ZIELWERT',
    'antwort' => 'MQTT.B_ANTWORT', 'ok' => 'MQTT.B_OK', 'grund' => 'MQTT.B_GRUND',
    'ansage' => 'MQTT.B_ANSAGE',
    'online' => 'MQTT.B_ONLINE', 'ts' => 'MQTT.B_TS',
    'mikrofone' => 'MQTT.B_MIKROFONE', 'bereit' => 'MQTT.B_BEREIT',
    'dienste_ok' => 'MQTT.B_DIENSTE_OK',
    'dienste_gesamt' => 'MQTT.B_DIENSTE_GESAMT', 'regeln' => 'MQTT.B_REGELN',
    'ziele' => 'MQTT.B_ZIELE', 'ruhe' => 'MQTT.B_RUHE',
    'letzter_satz_alter' => 'MQTT.B_LETZTER',
);
// Die beiden Zielthemen stehen hier mit maskierten spitzen Klammern
// ('&lt;Thema&gt;/aktion'), weil sie so angezeigt werden. Fuer die
// Retain-Auskunft macht das nichts: entschieden wird ueber die Endung.
foreach ($sp_themen as $sp_th => $sp_sch) {
    $sp_r = sp_retain_fuer($sp_th); ?>
<tr><td><span class="sm-mono"><?= sp_e($sp_praefix) ?>/<?= $sp_th ?></span></td><td><?= sp_t($sp_sch) ?></td><td><?= sp_e(sp_t($sp_r ? 'ALLG.JA' : 'ALLG.NEIN')) ?></td></tr>
<?php } ?>
</table>
</div>
<div class="sm-hinweis"><?= sp_t('MQTT.EMPFEHLUNG') ?></div>

<h3><?= sp_e(sp_t('MQTT.H_EINSTELLEN')) ?></h3>
<form action="index.php" method="post" data-ajax="false">
<?php $sp_hidden('tab-mqtt'); ?>
<input data-role="none" type="hidden" name="mqtt_save" value="1">
<div class="sm-feld">
  <label style="display:inline-flex;align-items:center;gap:8px;">
    <input data-role="none" type="checkbox" name="mqtt_ein" value="1" <?= sp_x2_haken('mqtt_ein', !empty($sp_cfg['mqtt_ein'])) ? 'checked' : '' ?>>
    <?= sp_e(sp_t('EINST.L_MQTT_EIN')) ?>
  </label>
</div>
<?php /* S3 (Runde 2): die Dauerverbindung des Dienstes. Ohne sie verbindet
   er sich nur kurz zum Senden; mit ihr hoert er auf Befehle und meldet
   Zustaende. Die Themen stehen darunter, gebildet aus dem Praefix. */ ?>
<div class="sm-feld">
  <label style="display:inline-flex;align-items:center;gap:8px;">
    <input data-role="none" type="checkbox" name="mqtt_dauer_ein" value="1" <?= sp_x2_haken('mqtt_dauer_ein', !empty($sp_cfg['mqtt_dauer_ein'])) ? 'checked' : '' ?><?= sp_x2_mark('mqtt_dauer_ein') ?>>
    <?= sp_e(sp_t('UI013.L_MQTT_DAUER')) ?>
  </label>
  <div class="sm-hilfe"><?= sp_e(sp_t('UI013.H_MQTT_DAUER')) ?></div>
  <div class="sm-roll">
  <table class="sm-tbl">
  <tr><th><?= sp_e(sp_t('MQTT.T_THEMA')) ?></th><th><?= sp_e(sp_t('UI013.T_RICHTUNG')) ?></th><th><?= sp_e(sp_t('MQTT.T_BEDEUTUNG')) ?></th></tr>
<?php foreach (array('cmd/sprechen' => array('UI013.R_EIN', 'UI013.B_CMD_SPRECHEN'),
                     'cmd/satz' => array('UI013.R_EIN', 'UI013.B_CMD_SATZ'),
                     'cmd/ruhe' => array('UI013.R_EIN', 'UI013.B_CMD_RUHE'),
                     'mikrofon/<name>/zustand' => array('UI013.R_AUS', 'UI013.B_MIKRO_ZUSTAND'),
                     'ansage_ok' => array('UI013.R_AUS', 'UI013.B_ANSAGE_OK'),
                     'ansage_grund' => array('UI013.R_AUS', 'UI013.B_ANSAGE_GRUND'),
                     'online' => array('UI013.R_AUS', 'UI013.B_ONLINE_LW')) as $sp_th => $sp_ti) { ?>
  <tr><td><span class="sm-mono"><?= sp_e($sp_praefix . '/' . $sp_th) ?></span></td><td><?= sp_e(sp_t($sp_ti[0])) ?></td><td><?= sp_e(sp_t($sp_ti[1])) ?></td></tr>
<?php } ?>
  </table>
  </div>
  <div class="sm-hilfe"><?= sp_e(sp_t('UI013.H_MQTT_DAUER_LESEN')) ?></div>
</div>
<div class="sm-feld">
  <label for="mqtt_topic"><?= sp_e(sp_t('EINST.L_MQTT_TOPIC')) ?></label>
  <input data-role="none" type="text" id="mqtt_topic" name="mqtt_topic" value="<?= sp_e(sp_x2_wert('mqtt_topic', $sp_cfg['mqtt_topic'])) ?>" placeholder="sprache"<?= sp_x2_mark('mqtt_topic') ?>>
</div>
<div class="sm-feld">
  <label for="herzschlag_s"><?= sp_e(sp_t('MQTT.L_HERZSCHLAG')) ?></label>
  <input data-role="none" type="number" id="herzschlag_s" name="herzschlag_s" value="<?= sp_e(sp_x2_wert('herzschlag_s', (int) $sp_cfg['herzschlag_s'])) ?>"<?= $sp_mm('herzschlag_s', 0, 3600) ?><?= sp_x2_mark('herzschlag_s') ?>>
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
<a data-ajax="false" href="index.php?form=services"><?= sp_e(sp_t('DIENST.K_JETZT_LADEN')) ?></a></div>
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
<form action="index.php" method="post" data-ajax="false" autocomplete="off">
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
    $sp_ist = sp_x2_wert($sp_feld, (string) $sp_cfg[$sp_feld]);
?>
<div class="sm-feld">
  <label for="<?= $sp_feld ?>"><?= sp_e(sp_t($sp_info[0])) ?></label>
  <select data-role="none" id="<?= $sp_feld ?>" name="<?= $sp_feld ?>"<?= sp_x2_mark($sp_feld) ?>>
    <option value=""><?= sp_e(sp_t('DIENST.VORSCHLAG_NEHMEN')) ?></option>
<?php   foreach ($sp_werte as $sp_w) { ?>
    <option value="<?= sp_e($sp_w) ?>"<?= $sp_ist === $sp_w ? ' selected' : '' ?>><?= sp_e($sp_w) ?></option>
<?php   }
        if ($sp_ist !== '' && !in_array($sp_ist, $sp_werte, true)) { ?>
    <option value="<?= sp_e($sp_ist) ?>" selected><?= sp_e($sp_ist) ?></option>
<?php   } ?>
  </select>
  <input data-role="none" type="text" name="<?= $sp_feld ?>_frei" value="<?= sp_e(sp_x2_wert($sp_feld . '_frei', '')) ?>" placeholder="<?= sp_e(sp_t('ALLG.EIGENER_WERT_H')) ?>" style="margin-top:6px;"<?= sp_x2_mark($sp_feld . '_frei') ?>>
</div>
<?php } ?>
<div class="sm-hilfe"><?= sp_t('DIENST.H_LLM_MODELL') ?></div>
<div class="sm-legende"><span><i class="sm-punkt sm-b-aktion"></i> <?= sp_t('LEGENDE.AKTION') ?></span></div>
<div class="sm-knopfreihe">
  <button data-role="none" class="sm-btn sm-b-aktion" type="submit"><?= sp_e(sp_t('ALLG.SPEICHERN')) ?></button>
</div>
</form>
<div class="sm-hilfe"><?= sp_t('DIENST.MODELLE_LEER') ?></div>

<?php
/* Seit 0.11.11 (Bauliste Einrichtung E1, E3, E6, E7): EIN Knopf richtet die
 * Sprachdienste ein - im Hintergrund, mit Ampel. Die Einzelknoepfe stehen
 * darunter unter "Einzeln verwalten", die docker-run-Zeile nur noch dort
 * (fuer ausgelagerte Dienste bleibt sie sichtbar wie bisher).
 * Die Zeichen der Ampel sind feste Klassen (Regeln/04: CSS-Klassen
 * woertlich, nicht zusammengesetzt). */
$sp_ct_zeichen = function ($w) {
    if ($w === 1) { return '<span class="sm-an">&#10004;</span>'; }
    if ($w === 0) { return '<span class="sm-aus">&#10008;</span>'; }
    return '<span style="color:#888;">&#9679;</span>';
};
$sp_ct_lauf = in_array($sp_ctv['zustand'], array('gestartet', 'laeuft'), true);
$sp_ct_lage = $sp_ct_amp !== null ? $sp_ct_amp['lage'] : '';
/* Der Zustand eines Containers in Worten: dieselben Schluessel ALLG.CONT_*
 * wie bis 0.11.10, dazu CONT_FREMD und CONT_UNBEKANNT. */
$sp_ct_ztext = function ($z) use ($sp_ct_lage) {
    if ($z['container'] === 'unbekannt' && $sp_ct_lage === 'fehlt') {
        return sp_t('ALLG.CONT_KEIN_DOCKER');
    }
    return sp_t('ALLG.CONT_' . strtoupper($z['container']));
};
$sp_ct_ohne_docker = in_array($sp_ct_lage, array('fehlt', 'kein_zugriff', 'dienst_aus'), true);
?>
<h2><?= sp_e(sp_t('CT.H_EINRICHTEN')) ?></h2>
<?php if ($sp_ct_lauf) {
    /* F9: bis 0.11.15 lud ein meta refresh die GANZE Seite alle 5 s neu -
     * was gerade in einem anderen Reiter eingetippt wurde, war weg. Jetzt
     * laedt das Skript unten nur, solange der Reiter Dienste sichtbar ist
     * (data-neu-laden); ohne Skript bleibt der Link. */ ?>
<div class="sm-hinweis" id="sp_ct_lauf" data-neu-laden="5"><b><?= sp_e(sp_ct_vorgang_satz($sp_ctv)) ?></b><br><?= sp_e(sp_t('CT.V_NEU_LADEN')) ?>
<a data-ajax="false" href="index.php?form=services"><?= sp_e(sp_t('DIENST.K_JETZT_LADEN')) ?></a>
<noscript><br><?= sp_e(sp_t('UI012.CT_OHNE_SKRIPT')) ?></noscript></div>
<?php } elseif ($sp_ctv['zustand'] === 'fertig') { ?>
<div class="sm-hinweis"><?= sp_e(sp_ct_vorgang_satz($sp_ctv)) ?></div>
<?php } elseif ($sp_ctv['zustand'] === 'fehler' || $sp_ctv['zustand'] === 'abgebrochen') { ?>
<div class="sm-warnung"><?= sp_e(sp_ct_vorgang_satz($sp_ctv)) ?></div>
<?php } ?>
<?php if ($sp_ct_lage === 'fehlt') { ?>
<div class="sm-fehler"><?= sp_t('DIENST.KEIN_DOCKER') ?></div>
<?php } elseif ($sp_ct_lage === 'kein_zugriff' || $sp_ct_lage === 'dienst_aus') { ?>
<div class="sm-fehler"><?= sp_e($sp_ct_amp['lagesatz']) ?><br><?= sp_t('DIENST.DOCKER_ANTWORTET_NICHT') ?></div>
<?php } elseif ($sp_ct_lage === 'haengt' || $sp_ct_lage === 'fehler') { ?>
<div class="sm-warnung"><?= sp_e($sp_ct_amp['lagesatz']) ?></div>
<?php } ?>
<p class="sm-hilfe"><?= sp_t('CT.EINRICHTEN_ERKLAERUNG') ?></p>
<?php
/* A5 (Runde 2): auf einem 32-Bit-Userland gibt es keine passenden Abbilder
 * (sp_ct_vorschau(): Art 'nicht64'). Der Knopf wuerde abgewiesen
 * (sp_ct_vorgang_starten()) - er steht deshalb gar nicht erst da, und die
 * Seite sagt, warum und was hilft. */
$sp_vorschau = sp_ct_vorschau($sp_cfg, $sp_emp);
$sp_nicht64 = false;
foreach ($sp_vorschau as $sp_cz) { if ($sp_cz['art'] === 'nicht64') { $sp_nicht64 = true; } } ?>
<ul class="sm-hilfe">
<?php foreach ($sp_vorschau as $sp_cz) { ?>
<li<?= $sp_cz['art'] === 'nicht64' ? ' class="sm-aus"' : '' ?>><?= sp_e(sp_ct_vorschau_satz($sp_cz)) ?></li>
<?php } ?>
</ul>
<?php if ($sp_nicht64) { ?>
<div class="sm-fehler"><b><?= sp_e(sp_t('UI013.H_NICHT64')) ?></b><br><?= sp_e(sp_t('LIB012.CT_NICHT64_SPERRE')) ?></div>
<?php } ?>
<?php if (!$sp_ct_lauf && !$sp_ct_ohne_docker && !$sp_nicht64) { ?>
<div class="sm-legende"><span><i class="sm-punkt sm-b-aktion"></i> <?= sp_t('LEGENDE.AKTION') ?></span></div>
<div class="sm-knopfreihe">
  <form action="index.php" method="post" data-ajax="false">
    <?php $sp_hidden('tab-services'); ?>
    <button data-role="none" class="sm-btn sm-b-aktion" type="submit" name="ct_einrichten" value="1"><?= sp_e(sp_t('CT.K_EINRICHTEN')) ?></button>
  </form>
</div>
<?php } ?>

<h3><?= sp_e(sp_t('CT.H_AMPEL')) ?></h3>
<?php if ($sp_ct_amp === null) { ?>
<div class="sm-hinweis"><?= sp_e(sp_t('CT.AMPEL_WARTET')) ?></div>
<?php } else { ?>
<div class="sm-roll">
<table class="sm-tbl">
<tr><th><?= sp_e(sp_t('CT.T_DIENST')) ?></th><th><?= sp_e(sp_t('CT.T_ABBILD')) ?></th><th><?= sp_e(sp_t('CT.T_LAEUFT')) ?></th><th><?= sp_e(sp_t('CT.T_PORT')) ?></th><th><?= sp_e(sp_t('CT.T_HINWEIS')) ?></th></tr>
<?php foreach ($sp_ct_amp['dienste'] as $sp_cd => $sp_cz) {
    if ($sp_cz['art'] === 'ausgelagert') {
        $sp_ch = $sp_cz['antwortet'] === 1 ? sp_t('ALLG.CONT_EXTERN') : sp_t('ALLG.CONT_EXTERN_WEG');
    } elseif ($sp_cz['art'] === 'ausgeschaltet') {
        $sp_ch = sp_t('CT.A_AUSGESCHALTET');
    } elseif ($sp_cz['art'] === 'nicht_vorgesehen') {
        $sp_ch = sp_t('CT.A_NICHT_VORGESEHEN');
    } else {
        $sp_ch = $sp_ct_ztext($sp_cz) . ($sp_cz['grund'] !== '' ? ' - ' . $sp_cz['grund'] : '');
    }
    /* A5 (Runde 2): antwortet auf dem Port ein ANDERER Dienst (sp_dienst_erkennen():
     * fremd = 1, erkannt = was), ist das rot und benannt - bis 0.11.15 hiess
     * "Port offen" gruen, auch wenn dort etwa Zigbee2MQTT lauschte. */
    $sp_fremd = !empty($sp_cz['fremd']) && in_array($sp_cz['art'], array('lokal', 'ausgelagert'), true);
    $sp_ch_fremd = $sp_fremd ? sprintf(sp_t('UI013.CT_FREMD_PORT'), (int) $sp_cz['port'],
                                       isset($sp_cz['erkannt']) && is_scalar($sp_cz['erkannt']) ? (string) $sp_cz['erkannt'] : '') : ''; ?>
<tr><td><?= sp_e(sp_ct_dname($sp_cd)) ?></td>
    <td style="text-align:center;"><?= $sp_cz['art'] === 'lokal' ? $sp_ct_zeichen($sp_cz['abbild']) : ($sp_cz['art'] === 'ausgeschaltet' ? $sp_ct_zeichen(-1) : '&mdash;') ?></td>
    <td style="text-align:center;"><?= $sp_cz['art'] === 'lokal' ? $sp_ct_zeichen($sp_cz['laeuft']) : ($sp_cz['art'] === 'ausgeschaltet' ? $sp_ct_zeichen(-1) : '&mdash;') ?></td>
    <td style="text-align:center;"><?= in_array($sp_cz['art'], array('lokal', 'ausgelagert'), true) ? $sp_ct_zeichen($sp_cz['antwortet']) : ($sp_cz['art'] === 'ausgeschaltet' ? $sp_ct_zeichen(-1) : '&mdash;') ?> <span class="sm-mono"><?= sp_e(($sp_cz['art'] === 'lokal' ? '127.0.0.1' : $sp_cz['host']) . ':' . $sp_cz['port']) ?></span></td>
    <td><?= sp_e($sp_ch) ?><?php if ($sp_ch_fremd !== '') { ?><br><span class="sm-aus"><?= sp_e($sp_ch_fremd) ?></span><?php } ?></td></tr>
<?php } ?>
</table>
</div>
<div class="<?= $sp_ct_amp['gesamt'][0] === 1 ? 'sm-hinweis' : 'sm-warnung' ?>"><b><?= sp_e(sp_t('CT.GESAMT')) ?></b> <?= sp_e($sp_ct_amp['gesamt'][1]) ?>
<?php if ($sp_ct_amp['gesamt'][2] !== '') { ?><br><?= sp_e($sp_ct_amp['gesamt'][2]) ?><?php } ?></div>
<p class="sm-hilfe"><?= sp_e(sprintf(sp_t('CT.AMPEL_ZEIT'), date('d.m.Y H:i:s', (int) $sp_ct_amp['zeit']))) ?></p>
<?php
/* E5: weicht ein laufender Container von dem ab, was das Plugin heute
 * anlegen wuerde (Abbild, Modell, Port - sp_ct_abweichung() aus der
 * Bibliothek ab 0.12.0)? Dann ein Hinweis und "neu anlegen" - nur fuer
 * eigene, lokale Container und nicht waehrend eines Vorgangs. Ohne die
 * Funktion steht hier nichts. */
if (function_exists('sp_ct_abweichung') && !$sp_ct_ohne_docker) {
    foreach ($sp_ct_amp['dienste'] as $sp_cd => $sp_cz) {
        if ($sp_cz['art'] !== 'lokal' || !in_array($sp_cd, sp_dienste(), true)) { continue; }
        $sp_ab = sp_ct_abweichung($sp_cd);
        if (!is_string($sp_ab) || $sp_ab === '') { continue; } ?>
<div class="sm-warnung"><b><?= sp_e(sprintf(sp_t('UI012.CT_ABWEICHUNG'), sp_ct_dname($sp_cd))) ?></b> <?= sp_e($sp_ab) ?>
<div class="sm-knopfreihe">
  <form action="index.php" method="post" data-ajax="false">
    <?php $sp_hidden('tab-services'); ?>
    <input data-role="none" type="hidden" name="dienstname" value="<?= sp_e($sp_cd) ?>">
    <button data-role="none" class="sm-btn sm-b-aktion" type="submit" name="container" value="neu_anlegen"><?= sp_e(sp_t('UI012.K_NEU_ANLEGEN')) ?></button>
  </form>
</div></div>
<?php }
} ?>
<?php } ?>

<?php foreach (sp_dienste() as $sp_d) {
    list($sp_dhost, $sp_dport) = sp_dienst_ziel($sp_d, $sp_cfg);
    if (sp_ist_lokal($sp_dhost)) { continue; }
    $sp_bef = sp_container_befehl($sp_d, $sp_cfg, $sp_emp ?: null, true); ?>
<h3><?= sp_e(sp_ct_dname($sp_d)) ?> <span class="sm-mono">(<?= sp_e($sp_dhost . ':' . $sp_dport) ?>)</span></h3>
<div class="sm-hinweis"><?= sprintf(sp_t('DIENST.AUSGELAGERT'), sp_e($sp_dhost . ':' . $sp_dport)) ?></div>
<?php if ($sp_bef !== '') { ?>
<p><span class="sm-mono">docker <?= sp_e($sp_bef) ?></span></p>
<?php } ?>
<div class="sm-legende"><span><i class="sm-punkt sm-b-technik"></i> <?= sp_t('LEGENDE.TECHNIK') ?></span></div>
<div class="sm-knopfreihe">
  <form action="index.php" method="post" data-ajax="false">
    <?php $sp_hidden('tab-services'); ?>
    <button data-role="none" class="sm-btn sm-b-technik" type="submit" name="containerlog" value="<?= $sp_d ?>"><?= sp_e(sp_t('DIENST.KC_LOG')) ?></button>
  </form>
</div>
<?php } ?>

<?php /* F10: nach der Umleitung gibt es kein $_POST mehr - bis 0.11.15 stand
   hier isset($_POST['container']), und der Bereich war nach jedem Knopf
   wieder zu. Jetzt traegt die Einmalmeldung das Kennzeichen 'offen'. */ ?>
<details class="sm-step"<?= $sp_offen_einzeln ? ' open' : '' ?>>
<summary><b><?= sp_e(sp_t('CT.H_EINZELN')) ?></b></summary>
<p class="sm-hilfe"><?= sp_t('CT.EINZELN_ERKLAERUNG') ?></p>
<div class="sm-warnung"><?= sp_t('DIENST.MODELL_WARNUNG') ?></div>
<div class="sm-legende">
<span><i class="sm-punkt sm-b-lesen"></i><?= sp_t('LEGENDE.LESEN_START') ?></span>
<span><i class="sm-punkt sm-b-technik"></i><?= sp_t('LEGENDE.TECHNIK') ?></span>
<span><i class="sm-punkt sm-b-aktion"></i><?= sp_t('LEGENDE.AKTION') ?></span>
</div>
<?php foreach (sp_dienste() as $sp_d) {
    list($sp_dhost, $sp_dport) = sp_dienst_ziel($sp_d, $sp_cfg);
    if (!sp_ist_lokal($sp_dhost)) { continue; }
    $sp_cz = ($sp_ct_amp !== null && isset($sp_ct_amp['dienste'][$sp_d])) ? $sp_ct_amp['dienste'][$sp_d] : null;
    $sp_bef = sp_container_befehl($sp_d, $sp_cfg, $sp_emp ?: null, false); ?>
<h3><?= sp_e(sp_ct_dname($sp_d)) ?>
<?php if ($sp_cz !== null && $sp_cz['art'] === 'lokal') { ?>
    <span class="<?= $sp_cz['container'] === 'laeuft' ? 'sm-an' : 'sm-aus' ?>">&mdash; <?= sp_e($sp_ct_ztext($sp_cz)) ?></span>
<?php } ?>
    <span class="sm-mono">(<?= sp_e($sp_dhost . ':' . $sp_dport) ?>)</span></h3>
<p class="sm-hilfe"><?= sp_t($sp_modelle['dienste'][$sp_d]['text']) ?></p>
<?php if ($sp_bef !== '') { ?>
<p><span class="sm-mono">docker <?= sp_e($sp_bef) ?></span></p>
<?php } else { ?>
<div class="sm-hinweis"><?= sp_t('DIENST.KEIN_BEFEHL') ?></div>
<?php } ?>
<div class="sm-knopfreihe">
  <form action="index.php" method="post" data-ajax="false">
    <?php $sp_hidden('tab-services'); ?>
    <input data-role="none" type="hidden" name="dienstname" value="<?= $sp_d ?>">
    <button data-role="none" class="sm-btn sm-b-lesen" type="submit" name="container" value="anlegen"><?= sp_e(sp_t('DIENST.KC_ANLEGEN')) ?></button>
  </form>
  <form action="index.php" method="post" data-ajax="false">
    <?php $sp_hidden('tab-services'); ?>
    <input data-role="none" type="hidden" name="dienstname" value="<?= $sp_d ?>">
    <button data-role="none" class="sm-btn sm-b-lesen" type="submit" name="container" value="start"><?= sp_e(sp_t('DIENST.KC_START')) ?></button>
  </form>
  <form action="index.php" method="post" data-ajax="false">
    <?php $sp_hidden('tab-services'); ?>
    <input data-role="none" type="hidden" name="dienstname" value="<?= $sp_d ?>">
    <button data-role="none" class="sm-btn sm-b-technik" type="submit" name="container" value="holen"><?= sp_e(sp_t('DIENST.KC_HOLEN')) ?></button>
  </form>
  <form action="index.php" method="post" data-ajax="false">
    <?php $sp_hidden('tab-services'); ?>
    <input data-role="none" type="hidden" name="dienstname" value="<?= $sp_d ?>">
    <button data-role="none" class="sm-btn sm-b-aktion" type="submit" name="container" value="restart"><?= sp_e(sp_t('DIENST.KC_RESTART')) ?></button>
  </form>
  <form action="index.php" method="post" data-ajax="false">
    <?php $sp_hidden('tab-services'); ?>
    <input data-role="none" type="hidden" name="dienstname" value="<?= $sp_d ?>">
    <button data-role="none" class="sm-btn sm-b-aktion" type="submit" name="container" value="stop"><?= sp_e(sp_t('DIENST.KC_STOP')) ?></button>
  </form>
  <form action="index.php" method="post" data-ajax="false">
    <?php $sp_hidden('tab-services'); ?>
    <input data-role="none" type="hidden" name="dienstname" value="<?= $sp_d ?>">
    <button data-role="none" class="sm-btn sm-b-aktion" type="submit" name="container" value="entfernen"><?= sp_e(sp_t('DIENST.KC_ENTFERNEN')) ?></button>
  </form>
  <form action="index.php" method="post" data-ajax="false">
    <?php $sp_hidden('tab-services'); ?>
    <button data-role="none" class="sm-btn sm-b-technik" type="submit" name="containerlog" value="<?= $sp_d ?>"><?= sp_e(sp_t('DIENST.KC_LOG')) ?></button>
  </form>
</div>
<?php } ?>
</details>

<?php /* S7 (Runde 2) und E: was nach einem Ausfall geschieht. Der Dienst selbst
   haengt am minuetlichen Waechter (cron), nicht am Webserver - gestartet aus
   dieser Seite liegt er zwar in der Prozessgruppe des Webservers und endet
   mit dessen Neustart, der Waechter holt ihn aber zurueck (der Sollmerker
   bleibt stehen). Ihn ohne root aus dieser Gruppe zu loesen geht nicht. */ ?>
<h2><?= sp_e(sp_t('UI013.H_AUSFALL')) ?></h2>
<div class="sm-hinweis"><?= sp_e(sp_t('UI013.HINWEIS_WAECHTER')) ?></div>
<form action="index.php" method="post" data-ajax="false">
<?php $sp_hidden('tab-services'); ?>
<input data-role="none" type="hidden" name="dienste_opt_speichern" value="1">
<div class="sm-feld">
  <label style="display:inline-flex;align-items:center;gap:8px;">
    <input data-role="none" type="checkbox" name="docker_neustart" value="1"<?= sp_x2_haken('docker_neustart', !empty($sp_cfg['docker_neustart'])) ? ' checked' : '' ?><?= sp_x2_mark('docker_neustart') ?>>
    <?= sp_e(sp_t('UI013.L_DOCKER_NEUSTART')) ?>
  </label>
  <div class="sm-hilfe"><?= sp_e(sp_t('UI013.H_DOCKER_NEUSTART')) ?></div>
</div>
<div class="sm-legende"><span><i class="sm-punkt sm-b-aktion"></i> <?= sp_t('LEGENDE.AKTION') ?></span></div>
<div class="sm-knopfreihe">
  <button data-role="none" class="sm-btn sm-b-aktion" type="submit"><?= sp_e(sp_t('ALLG.SPEICHERN')) ?></button>
</div>
</form>

<h2><?= sp_e(sp_t('DIENST.H_MESSEN')) ?></h2>
<div class="sm-hinweis"><?= sp_t('DIENST.MESSEN_ERKLAERUNG') ?></div>
<div class="sm-legende"><span><i class="sm-punkt sm-b-technik"></i> <?= sp_t('LEGENDE.TECHNIK') ?></span></div>
<div class="sm-knopfreihe">
  <form action="index.php" method="post" data-ajax="false">
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
<form action="index.php" method="post" data-ajax="false" autocomplete="off">
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
$sp_liste = isset($sp_cfg['satelliten']) && is_array($sp_cfg['satelliten']) ? array_values($sp_cfg['satelliten']) : array();
/* F12: so viele Zeilen, wie Mikrofone eingetragen sind, mindestens acht -
 * nach einer Beanstandung so viele, wie abgeschickt wurden. Bis 0.11.15
 * zeigte die Tabelle fest acht, und was eine Sicherung dahinter trug, fiel
 * beim naechsten Speichern still weg. Die Zeilennummer steht im Namen
 * (m_host[3]): der Handler ordnet Schluessel und Haken danach zu. */
$sp_mz = sp_x2_zeilen('m_host');
$sp_anz = max(8, count($sp_liste), $sp_mz ? max($sp_mz) + 1 : 0);
// F12: Raum als Auswahl aus den Zielnamen (Freitext bleibt erlaubt).
$sp_raum_liste = array();
foreach ((isset($sp_saetze['ziele']) && is_array($sp_saetze['ziele']) ? $sp_saetze['ziele'] : array()) as $sp_k => $sp_z) {
    $sp_rn = is_array($sp_z) && isset($sp_z['name']) && is_scalar($sp_z['name']) ? (string) $sp_z['name'] : (string) $sp_k;
    if ($sp_rn !== '' && !in_array($sp_rn, $sp_raum_liste, true)) { $sp_raum_liste[] = $sp_rn; }
}
for ($sp_i = 0; $sp_i < $sp_anz; $sp_i++) {
    $sp_z = isset($sp_liste[$sp_i]) && is_array($sp_liste[$sp_i]) ? $sp_liste[$sp_i] : array();
    $sp_v = function ($k) use ($sp_z) { return isset($sp_z[$k]) && is_scalar($sp_z[$k]) ? (string) $sp_z[$k] : ''; };
    $sp_name = $sp_v('name');
    $sp_zust = isset($sp_sats[$sp_name]['zustand']) ? $sp_sats[$sp_name]['zustand'] : '';
?>
<tr><td><?= $sp_i + 1 ?></td>
<td><input data-role="none" type="text" name="m_name[<?= $sp_i ?>]" value="<?= sp_e(sp_x2_wert('m_name', $sp_name, $sp_i)) ?>" size="12"<?= sp_x2_mark('m_name', $sp_i) ?>></td>
<?php $sp_art_ist = sp_x2_wert('m_art', $sp_v('art') === 'esphome' ? 'esphome' : 'wyoming', $sp_i); ?>
<td><select data-role="none" name="m_art[<?= $sp_i ?>]"<?= sp_x2_mark('m_art', $sp_i) ?>>
    <option value="wyoming"<?= $sp_art_ist !== 'esphome' ? ' selected' : '' ?>>Wyoming</option>
    <option value="esphome"<?= $sp_art_ist === 'esphome' ? ' selected' : '' ?>>ESPHome</option>
</select></td>
<td><input data-role="none" type="hidden" name="m_alt[<?= $sp_i ?>]" value="<?= sp_e(sp_x2_wert('m_alt', $sp_v('host'), $sp_i)) ?>"><input data-role="none" type="text" name="m_host[<?= $sp_i ?>]" value="<?= sp_e(sp_x2_wert('m_host', $sp_v('host'), $sp_i)) ?>" size="14" placeholder="<?= $sp_i === 0 ? '192.168.1.60' : '' ?>"<?= sp_x2_mark('m_host', $sp_i) ?>></td>
<td><input data-role="none" type="text" name="m_port[<?= $sp_i ?>]" value="<?= sp_e(sp_x2_wert('m_port', $sp_v('port'), $sp_i)) ?>" size="5"<?= sp_x2_mark('m_port', $sp_i) ?>></td>
<td><input data-role="none" type="text" name="m_raum[<?= $sp_i ?>]" list="sp_raeume" value="<?= sp_e(sp_x2_wert('m_raum', $sp_v('raum'), $sp_i)) ?>" size="12" placeholder="<?= $sp_i === 0 ? sp_e(sp_t('UI012.P_RAUM')) : '' ?>"<?= sp_x2_mark('m_raum', $sp_i) ?>></td>
<td><input data-role="none" type="text" name="m_zone[<?= $sp_i ?>]" value="<?= sp_e(sp_x2_wert('m_zone', $sp_v('zone'), $sp_i)) ?>" size="5"<?= sp_x2_mark('m_zone', $sp_i) ?>></td>
<td><input data-role="none" type="password" name="m_schluessel[<?= $sp_i ?>]" value="" autocomplete="new-password"
    placeholder="<?= $sp_v('schluessel') !== '' ? sp_e(sp_t('MIKRO.SCHLUESSEL_DA')) : sp_e(sp_t('MIKRO.SCHLUESSEL_LEER')) ?>" size="12">
<?php if ($sp_v('schluessel') !== '') { ?>
    <label style="display:block;font-weight:400;font-size:0.85em;"><input data-role="none" type="checkbox" name="m_schluessel_loeschen[<?= $sp_i ?>]" value="1"<?= sp_x2_zeilenhaken('m_schluessel_loeschen', $sp_i, false) ? ' checked' : '' ?>> <?= sp_e(sp_t('UI012.L_SCHLUESSEL_LOESCHEN')) ?></label>
<?php } ?></td>
<td class="<?= $sp_zust === 'getrennt' || $sp_zust === '' ? 'sm-aus' : 'sm-an' ?>"><?= $sp_zust !== '' ? sp_e($sp_zust) : '&mdash;' ?></td></tr>
<?php
    /* S2 (Runde 2): die Ausgabe dieses Raums, aufklappbar unter der Zeile.
     * Leer heisst: wie unter Einstellungen eingestellt. Offen, wenn etwas
     * eingetragen oder beanstandet ist. */
    $sp_ra = isset($sp_z['ausgabe']) && is_array($sp_z['ausgabe']) ? $sp_z['ausgabe'] : array();
    $sp_raw = function ($k) use ($sp_ra) { return isset($sp_ra[$k]) && is_string($sp_ra[$k]) ? $sp_ra[$k] : ''; };
    $sp_rafelder = array('m_a_alexa' => array('alexa_geraet', 'UI013.L_RA_ALEXA_GERAET', ''),
                         'm_a_google' => array('google_geraet', 'UI013.L_RA_GOOGLE_GERAET', ''),
                         'm_a_cc' => array('cc_ziel', 'UI013.L_RA_CC_ZIEL', 'sp_cc_raum'),
                         'm_a_sonos' => array('sonos_zone', 'UI013.L_RA_SONOS_ZONE', ''));
    $sp_ra_offen = false;
    foreach ($sp_rafelder as $sp_rf => $sp_ri) {
        if (sp_x2_wert($sp_rf, $sp_raw($sp_ri[0]), $sp_i) !== '' || sp_x2_mark($sp_rf, $sp_i) !== '') { $sp_ra_offen = true; }
    } ?>
<tr><td></td><td colspan="8"><details<?= $sp_ra_offen ? ' open' : '' ?>><summary><?= sp_e(sp_t('UI013.H_RAUMAUSGABE')) ?></summary>
<div class="sm-hilfe"><?= sp_e(sp_t('UI013.RAUMAUSGABE_HILFE')) ?></div>
<div style="display:flex;flex-wrap:wrap;gap:10px;margin:6px 0;">
<?php foreach ($sp_rafelder as $sp_rf => $sp_ri) { ?>
  <label style="font-weight:400;font-size:0.9em;"><?= sp_e(sp_t($sp_ri[1])) ?><br>
    <input data-role="none" type="text" name="<?= $sp_rf ?>[<?= $sp_i ?>]" maxlength="200" size="16" value="<?= sp_e(sp_x2_wert($sp_rf, $sp_raw($sp_ri[0]), $sp_i)) ?>"<?= $sp_ri[2] !== '' ? ' list="' . $sp_ri[2] . '"' : '' ?><?= sp_x2_mark($sp_rf, $sp_i) ?>></label>
<?php } ?>
</div></details></td></tr>
<?php } ?>
</table>
</div>
<?php /* Die Lautsprecher, die Chromecast4lox zuletzt am Broker meldete - aus
   dem Zwischenspeicher des Reiters Einstellungen (data/cc_lautsprecher.json),
   ohne den Broker hier noch einmal zu fragen. Freitext bleibt erlaubt. */
$sp_ccr = sp_json_lesen($sp_p['datadir'] . '/cc_lautsprecher.json');
$sp_ccr = isset($sp_ccr['liste']) && is_array($sp_ccr['liste']) ? array_filter($sp_ccr['liste'], 'is_string') : array(); ?>
<datalist id="sp_cc_raum"><option value="alle"></option>
<?php foreach ($sp_ccr as $sp_g) { ?>  <option value="<?= sp_e($sp_g) ?>"></option>
<?php } ?></datalist>
<datalist id="sp_raeume">
<?php foreach ($sp_raum_liste as $sp_rn) { ?>  <option value="<?= sp_e($sp_rn) ?>"></option>
<?php } ?></datalist>
<?php if (count($sp_liste) > 8) { ?>
<div class="sm-warnung"><?= sp_e(sprintf(sp_t('UI012.MIKRO_MEHR_ALS_ACHT'), count($sp_liste))) ?></div>
<?php } ?>
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
  <form action="index.php" method="post" data-ajax="false">
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
<tr><td><?= sp_e(sp_t('UI012.G_PI_NAME')) ?></td><td>Wyoming</td><td><?= sp_t('MIKRO.G_PI') ?></td></tr>
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
<form action="index.php" method="post" data-ajax="false" autocomplete="off">
<?php $sp_hidden('tab-sentences'); ?>
<input data-role="none" type="hidden" name="ziele_speichern" value="1">
<div class="sm-roll">
<table class="sm-tbl">
<tr><th><?= sp_e(sp_t('SATZ.T_SCHLUESSEL')) ?></th><th><?= sp_e(sp_t('SATZ.T_NAME')) ?></th>
    <th><?= sp_e(sp_t('SATZ.T_ALIAS')) ?></th><th><?= sp_e(sp_t('SATZ.T_THEMA')) ?></th>
    <th style="width:70px;"><?= sp_e(sp_t('SATZ.T_EINHEIT')) ?></th>
    <th><?= sp_e(sp_t('UI012.T_URL')) ?></th>
    <th><?= sp_e(sp_t('SATZ.T_LESEN')) ?></th>
    <th style="width:60px;"><?= sp_e(sp_t('UI012.T_MIN')) ?></th><th style="width:60px;"><?= sp_e(sp_t('UI012.T_MAX')) ?></th>
    <th style="width:60px;"><?= sp_e(sp_t('UI013.T_SCHRITT')) ?></th>
    <th style="width:60px;"><?= sp_e(sp_t('SATZ.T_BESTAETIGEN')) ?></th>
    <th style="width:60px;"><?= sp_e(sp_t('UI012.T_LOESCHEN')) ?></th></tr>
<?php
$sp_zliste = isset($sp_saetze['ziele']) && is_array($sp_saetze['ziele']) ? $sp_saetze['ziele'] : array();
/* Eine Zeile der Maske. $w traegt die Werte als Text: alt, key, name,
 * alias, thema, einheit, url, lesen, min, max, schritt, best, loe. z_alt ist der
 * Schluessel, unter dem das Ziel gespeichert ist (F1) - der Handler
 * uebernimmt den alten Eintrag und ueberschreibt nur die Felder der Maske. */
$sp_zeile = function ($i, $w) {
    $f = function ($n) use ($w) { return isset($w[$n]) ? (string) $w[$n] : ''; }; ?>
<tr><td><input data-role="none" type="hidden" name="z_alt[<?= (int) $i ?>]" value="<?= sp_e($f('alt')) ?>"><input data-role="none" type="text" name="z_key[<?= (int) $i ?>]" value="<?= sp_e($f('key')) ?>" size="14"<?= sp_x2_mark('z_key', $i) ?>></td>
    <td><input data-role="none" type="text" name="z_name[<?= (int) $i ?>]" value="<?= sp_e($f('name')) ?>" size="16"<?= sp_x2_mark('z_name', $i) ?>></td>
    <td><input data-role="none" type="text" name="z_alias[<?= (int) $i ?>]" value="<?= sp_e($f('alias')) ?>" size="24"<?= sp_x2_mark('z_alias', $i) ?>></td>
    <td><input data-role="none" type="text" name="z_thema[<?= (int) $i ?>]" value="<?= sp_e($f('thema')) ?>" size="16"<?= sp_x2_mark('z_thema', $i) ?>></td>
    <td><input data-role="none" type="text" name="z_einheit[<?= (int) $i ?>]" value="<?= sp_e($f('einheit')) ?>" size="5"<?= sp_x2_mark('z_einheit', $i) ?>></td>
    <td><input data-role="none" type="text" name="z_url[<?= (int) $i ?>]" value="<?= sp_e($f('url')) ?>" size="24"<?= sp_x2_mark('z_url', $i) ?>></td>
    <td><input data-role="none" type="text" name="z_lesen[<?= (int) $i ?>]" value="<?= sp_e($f('lesen')) ?>" size="24"<?= sp_x2_mark('z_lesen', $i) ?>></td>
    <td><input data-role="none" type="text" name="z_min[<?= (int) $i ?>]" value="<?= sp_e($f('min')) ?>" size="5"<?= sp_x2_mark('z_min', $i) ?>></td>
    <td><input data-role="none" type="text" name="z_max[<?= (int) $i ?>]" value="<?= sp_e($f('max')) ?>" size="5"<?= sp_x2_mark('z_max', $i) ?>></td>
    <td><input data-role="none" type="text" name="z_schritt[<?= (int) $i ?>]" value="<?= sp_e($f('schritt')) ?>" size="5"<?= sp_x2_mark('z_schritt', $i) ?>></td>
    <td style="text-align:center;"><input data-role="none" type="checkbox" name="z_bestaetigen[<?= (int) $i ?>]" value="1"<?= !empty($w['best']) ? ' checked' : '' ?>></td>
    <td style="text-align:center;"><?php if ($f('alt') !== '') { ?><input data-role="none" type="checkbox" name="z_loeschen[<?= (int) $i ?>]" value="1"<?= !empty($w['loe']) ? ' checked' : '' ?>><?php } else { echo '&mdash;'; } ?></td></tr>
<?php };
/* Adressen maskiert (sie koennen Zugangsdaten tragen) - zurueck kommt die
 * maskierte Form, und der Handler behaelt dann die gespeicherte. */
$sp_zwerte = function ($k, $z) {
    $hol = function ($f) use ($z) {
        if (!is_array($z)) { return $f === 'thema' ? (string) $z : ''; }
        return isset($z[$f]) && is_scalar($z[$f]) ? (string) $z[$f] : '';
    };
    return array('alt' => $k, 'key' => $k, 'name' => $hol('name'),
                 'alias' => is_array($z) && isset($z['alias']) ? implode(', ', array_filter((array) $z['alias'], 'is_scalar')) : '',
                 'thema' => $hol('thema'), 'einheit' => $hol('einheit'),
                 'url' => sp_url_maskiert($hol('url')), 'lesen' => sp_url_maskiert($hol('url_lesen')),
                 'min' => $hol('min'), 'max' => $hol('max'), 'schritt' => $hol('schritt'),
                 'best' => is_array($z) && !empty($z['bestaetigen']), 'loe' => false);
};
$sp_zx = sp_x2_zeilen('z_key');
if ($sp_zx !== null) {
    // F5: nach einer Beanstandung die abgeschickten Zeilen, nicht der gespeicherte Stand.
    foreach ($sp_zx as $sp_i) {
        $sp_alt = sp_x2_wert('z_alt', '', $sp_i);
        $sp_basis_z = ($sp_alt !== '' && array_key_exists($sp_alt, $sp_zliste)) ? $sp_zwerte($sp_alt, $sp_zliste[$sp_alt]) : array();
        $sp_w = array('alt' => $sp_alt, 'best' => sp_x2_zeilenhaken('z_bestaetigen', $sp_i, false),
                      'loe' => sp_x2_zeilenhaken('z_loeschen', $sp_i, false));
        foreach (array('key' => 'z_key', 'name' => 'z_name', 'alias' => 'z_alias', 'thema' => 'z_thema',
                       'einheit' => 'z_einheit', 'url' => 'z_url', 'lesen' => 'z_lesen', 'min' => 'z_min', 'max' => 'z_max',
                       'schritt' => 'z_schritt') as $sp_n => $sp_f) {
            $sp_w[$sp_n] = sp_x2_wert($sp_f, isset($sp_basis_z[$sp_n]) ? $sp_basis_z[$sp_n] : '', $sp_i);
        }
        $sp_zeile($sp_i, $sp_w);
    }
    $sp_zi = $sp_zx ? max($sp_zx) + 1 : 0;
} else {
    $sp_zi = 0;
    foreach ($sp_zliste as $sp_k => $sp_z) { $sp_zeile($sp_zi, $sp_zwerte((string) $sp_k, $sp_z)); $sp_zi++; }
    for ($sp_j = 0; $sp_j < 3; $sp_j++) { $sp_zeile($sp_zi, array()); $sp_zi++; }
}
?>
</table>
</div>
<div class="sm-hilfe"><?= sp_t('SATZ.ZIELE_HILFE') ?> <?= sp_e(sp_t('UI012.ZIELE_HILFE')) ?></div>
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
<tr><td><span class="sm-mono">{ziel}</span></td><td><?= sp_t('SATZ.B_ZIEL') ?></td><td><span class="sm-mono"><?= sp_e(sp_t('UI012.BSP_ZIEL')) ?></span></td></tr>
<tr><td><span class="sm-mono">{wert}</span></td><td><?= sp_t('SATZ.B_WERT') ?></td><td><span class="sm-mono"><?= sp_e(sp_t('UI012.BSP_WERT')) ?></span></td></tr>
<tr><td><span class="sm-mono">{dauer}</span></td><td><?= sp_t('SATZ.B_DAUER') ?></td><td><span class="sm-mono"><?= sp_e(sp_t('UI012.BSP_DAUER')) ?></span></td></tr>
<tr><td><span class="sm-mono">{rest}</span></td><td><?= sp_t('SATZ.B_REST') ?></td><td><span class="sm-mono"><?= sp_e(sp_t('UI012.BSP_REST')) ?></span></td></tr>
<tr><td><span class="sm-mono">{istwert}</span></td><td><?= sp_t('SATZ.B_ISTWERT') ?></td><td><span class="sm-mono"><?= sp_e(sp_t('UI012.BSP_ISTWERT')) ?></span></td></tr>
<tr><td><span class="sm-mono">[a|b]</span></td><td><?= sp_t('SATZ.B_ALT') ?></td><td><span class="sm-mono"><?= sp_e(sp_t('UI012.BSP_ALT')) ?></span></td></tr>
<tr><td><span class="sm-mono">[a|]</span></td><td><?= sp_t('SATZ.B_LEER') ?></td><td><span class="sm-mono"><?= sp_e(sp_t('UI012.BSP_LEER')) ?></span></td></tr>
</table>
</div>
<div class="sm-warnung"><?= sp_t('SATZ.REIHENFOLGE_WARNUNG') ?></div>

<h3><?= sp_e(sp_t('SATZ.H_BEARBEITEN')) ?></h3>
<div class="sm-warnung"><?= sp_t('SATZ.BEARBEITEN_WARNUNG') ?></div>
<?php /* E2: das Skript unten prueft das JSON vor dem Absenden und nennt Zeile
   und Spalte (id sp_rohtext, Meldung in sp_rohtext_fehler). Der Server
   prueft trotzdem - ohne Skript, oder wenn die Form stimmt, der Inhalt
   aber nicht. F5: nach einer Beanstandung steht der eingegebene Text da. */ ?>
<form action="index.php" method="post" data-ajax="false" id="sp_rohtext_form">
<?php $sp_hidden('tab-sentences'); ?>
<input data-role="none" type="hidden" name="saetze_speichern" value="1">
<div class="sm-feld">
  <textarea data-role="none" name="saetze" id="sp_rohtext" rows="20" style="width:100%;font-family:Consolas,monospace;font-size:0.86em;"<?= $sp_rohtext_x2 !== null ? ' class="sm-beanstandet" aria-invalid="true"' : '' ?>><?= sp_e($sp_rohtext_x2 !== null ? $sp_rohtext_x2 : json_encode($sp_saetze, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?></textarea>
  <div class="sm-fehler" id="sp_rohtext_fehler" style="display:none;"></div>
</div>
<div class="sm-legende">
<span><i class="sm-punkt sm-b-aktion"></i><?= sp_t('LEGENDE.AKTION') ?></span>
</div>
<div class="sm-knopfreihe">
  <button data-role="none" class="sm-btn sm-b-aktion" type="submit"><?= sp_e(sp_t('ALLG.SPEICHERN')) ?></button>
</div>
</form>
<div class="sm-hilfe"><?= sp_t('SATZ.NEU_LADEN') ?></div>

<h3><?= sp_e(sp_t('UI012.H_STAENDE')) ?></h3>
<p class="sm-hilfe"><?= sp_e(sprintf(sp_t('UI012.STAENDE_ERKLAERUNG'), SP_STAENDE)) ?></p>
<?php $sp_staende = sp_ui_staende(); if (!$sp_staende) { ?>
<div class="sm-hinweis"><?= sp_e(sp_t('UI012.STAENDE_KEINE')) ?></div>
<?php } else { ?>
<div class="sm-roll">
<table class="sm-tbl">
<tr><th><?= sp_e(sp_t('TEST.T_ZEIT')) ?></th><th><?= sp_e(sp_t('UI012.T_REGELN')) ?></th><th><?= sp_e(sp_t('ALLG.ZIELE')) ?></th><th>&nbsp;</th></tr>
<?php foreach ($sp_staende as $sp_st) { ?>
<tr><td><?= sp_e(date('d.m.Y H:i:s', $sp_st['zeit'])) ?></td><td><?= (int) $sp_st['regeln'] ?></td><td><?= (int) $sp_st['ziele'] ?></td>
    <td><form action="index.php" method="post" data-ajax="false" style="margin:0;">
      <?php $sp_hidden('tab-sentences'); ?>
      <button data-role="none" class="sm-btn sm-b-aktion" style="min-width:auto!important;" type="submit" name="saetze_zurueck" value="<?= sp_e($sp_st['datei']) ?>"><?= sp_e(sp_t('UI012.K_ZURUECKHOLEN')) ?></button>
    </form></td></tr>
<?php } ?>
</table>
</div>
<?php } ?>

<h2><?= sp_e(sp_t('LOXIMP.H_TITEL')) ?></h2>
<div class="sm-hinweis"><?= sp_t('LOXIMP.ERKLAERUNG') ?></div>
<?php $sp_lvor = sp_lox_vorschlaege(); ?>
<?php if (!$sp_lvor) {
/* E4: die Miniserver aus der LoxBerry-Einstellung (ohne Zugangsdaten -
 * die liest die Bibliothek selbst). Nur, wenn die Bibliothek beides kann;
 * die Eingabe von Hand bleibt darunter. */
$sp_lms_liste = function_exists('sp_lb_miniserver') ? sp_lb_miniserver() : array();
if (is_array($sp_lms_liste) && $sp_lms_liste) { ?>
<form action="index.php" method="post" data-ajax="false" autocomplete="off">
<?php $sp_hidden('tab-sentences'); ?>
<div class="sm-feld">
  <label for="lox_ms"><?= sp_e(sp_t('UI012.L_LOX_MS')) ?></label>
  <select data-role="none" id="lox_ms" name="lox_ms">
<?php foreach ($sp_lms_liste as $sp_ms) {
    if (!is_array($sp_ms) || !isset($sp_ms['nr']) || !preg_match('/^[1-9][0-9]?\z/', (string) $sp_ms['nr'])) { continue; } ?>
    <option value="<?= (int) $sp_ms['nr'] ?>"><?= sp_e((isset($sp_ms['name']) && is_scalar($sp_ms['name']) ? $sp_ms['name'] : '#' . (int) $sp_ms['nr'])
        . (isset($sp_ms['ip']) && is_scalar($sp_ms['ip']) ? ' (' . $sp_ms['ip'] . (!empty($sp_ms['port']) && is_scalar($sp_ms['port']) ? ':' . $sp_ms['port'] : '') . ')' : '')) ?></option>
<?php } ?>
  </select>
  <div class="sm-hilfe"><?= sp_e(sp_t('UI012.H_LOX_MS')) ?></div>
</div>
<div class="sm-legende"><span><i class="sm-punkt sm-b-lesen"></i> <?= sp_t('LEGENDE.LESEN') ?></span></div>
<div class="sm-knopfreihe">
  <button data-role="none" class="sm-btn sm-b-lesen" type="submit" name="lox_holen" value="1"><?= sp_e(sp_t('LOXIMP.K_HOLEN')) ?></button>
</div>
</form>
<h3><?= sp_e(sp_t('UI012.H_LOX_HAND')) ?></h3>
<?php } ?>
<form action="index.php" method="post" data-ajax="false" autocomplete="off">
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
<?php
/* E4: Raum- und Artfilter und "alle an/abwaehlen" vor der Uebernahme -
 * nur mit Skript (sp_lox_filter, unten). Ausgeblendete Zeilen werden
 * dabei abgeschaltet (disabled) und gehen nicht mit; ohne Skript ist alles
 * sichtbar und angehakt wie bisher. */
$sp_lraeume = array();
$sp_larten = array();
foreach ($sp_lvor as $sp_v4) {
    if (!is_array($sp_v4)) { continue; }
    $sp_r4 = isset($sp_v4['raum']) && is_scalar($sp_v4['raum']) ? (string) $sp_v4['raum'] : '';
    $sp_a4 = isset($sp_v4['art']) && is_scalar($sp_v4['art']) ? (string) $sp_v4['art'] : '';
    if (!in_array($sp_r4, $sp_lraeume, true)) { $sp_lraeume[] = $sp_r4; }
    if ($sp_a4 !== '' && !in_array($sp_a4, $sp_larten, true)) { $sp_larten[] = $sp_a4; }
}
sort($sp_lraeume);
sort($sp_larten);
?>
<div class="sm-knopfreihe" id="sp_lox_filter" style="display:none;align-items:center;">
  <span class="sm-legende" style="margin:0;"><span><i class="sm-punkt sm-b-technik"></i> <?= sp_t('LEGENDE.TECHNIK') ?></span></span>
  <label><?= sp_e(sp_t('UI012.L_FILTER_RAUM')) ?>
    <select data-role="none" id="sp_lox_raum"><option value="*"><?= sp_e(sp_t('UI012.FILTER_ALLE')) ?></option>
<?php foreach ($sp_lraeume as $sp_r4) { ?>      <option value="<?= sp_e($sp_r4) ?>"><?= sp_e($sp_r4 !== '' ? $sp_r4 : sp_t('UI012.FILTER_OHNE_RAUM')) ?></option>
<?php } ?>    </select></label>
  <label><?= sp_e(sp_t('UI012.L_FILTER_ART')) ?>
    <select data-role="none" id="sp_lox_art"><option value="*"><?= sp_e(sp_t('UI012.FILTER_ALLE')) ?></option>
<?php foreach ($sp_larten as $sp_a4) { ?>      <option value="<?= sp_e($sp_a4) ?>"><?= sp_e($sp_a4) ?></option>
<?php } ?>    </select></label>
  <button data-role="none" class="sm-btn sm-b-technik" type="button" id="sp_lox_alle" style="min-width:auto!important;"><?= sp_e(sp_t('UI012.K_ALLE_AN')) ?></button>
  <button data-role="none" class="sm-btn sm-b-technik" type="button" id="sp_lox_keine" style="min-width:auto!important;"><?= sp_e(sp_t('UI012.K_ALLE_AB')) ?></button>
</div>
<form action="index.php" method="post" data-ajax="false">
<?php $sp_hidden('tab-sentences'); ?>
<div class="sm-roll">
<table class="sm-tbl" id="sp_lox_tabelle">
<tr><th style="width:40px;">&nbsp;</th><th><?= sp_e(sp_t('SATZ.T_SCHLUESSEL')) ?></th>
    <th><?= sp_e(sp_t('SATZ.T_NAME')) ?></th><th><?= sp_e(sp_t('SATZ.T_ALIAS')) ?></th>
    <th><?= sp_e(sp_t('SATZ.T_THEMA')) ?></th><th><?= sp_e(sp_t('SATZ.T_LESEN')) ?></th><th><?= sp_e(sp_t('LOXIMP.T_ART')) ?></th></tr>
<?php
$sp_zvorhanden = isset($sp_saetze['ziele']) && is_array($sp_saetze['ziele']) ? $sp_saetze['ziele'] : array();
foreach ($sp_lvor as $sp_k4 => $sp_v4) {
    if (!is_array($sp_v4)) { continue; }
    $sp_da = isset($sp_zvorhanden[$sp_k4]); ?>
<tr data-raum="<?= sp_e(isset($sp_v4['raum']) && is_scalar($sp_v4['raum']) ? (string) $sp_v4['raum'] : '') ?>" data-art="<?= sp_e(isset($sp_v4['art']) && is_scalar($sp_v4['art']) ? (string) $sp_v4['art'] : '') ?>"><td style="text-align:center;"><?php if ($sp_da) { echo '&mdash;'; } else { ?>
    <input data-role="none" type="checkbox" name="lox_ziel[]" value="<?= sp_e((string) $sp_k4) ?>" checked><?php } ?></td>
    <td><span class="sm-mono"><?= sp_e((string) $sp_k4) ?></span></td>
    <td><?= sp_e((string) $sp_v4['name']) ?></td>
    <td><?= sp_e(implode(', ', (array) $sp_v4['alias'])) ?></td>
    <td><span class="sm-mono"><?= sp_e((string) $sp_v4['thema']) ?></span></td>
    <td><?= isset($sp_v4['url_lesen']) && is_string($sp_v4['url_lesen']) ? '<span class="sm-mono">' . sp_e($sp_v4['url_lesen']) . '</span>' : '&mdash;' ?></td>
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
<?php /* X-3: wuerde die eigene Sicherung beim Zurueckspielen abgewiesen?
   Dieselbe Pruefung wie das Zurueckspielen; nur der Grund, nie Werte. */
$sp_x3 = sp_rueckspiel_altwerte();
if ($sp_x3 !== '') { ?>
<div class="sm-warnung"><?= sprintf(sp_t('SICHER.WARN_RUECKSPIEL'), sp_e($sp_x3)) ?></div>
<?php }
/* Ansage-3, X-3: ein gespeicherter Google-Wert, den dieselbe Pruefung wie
   das Zurueckspielen abweist - nur die Feldnamen, nie Werte. */
$sp_x3g = sp_google_gespeichert_falsch();
if ($sp_x3g) { ?>
<div class="sm-warnung"><?= sprintf(sp_t('SICHER.WARN_GESPEICHERT'), sp_e(implode(', ', $sp_x3g))) ?></div>
<?php } ?>
<div class="sm-legende">
<span><i class="sm-punkt sm-b-lesen"></i> <?= sp_t('LEGENDE.LESEN') ?></span>
<span><i class="sm-punkt sm-b-aktion"></i> <?= sp_t('LEGENDE.AKTION') ?></span>
</div>
<div class="sm-knopfreihe">
  <form action="index.php" method="post" data-ajax="false">
    <?php $sp_hidden('tab-sentences'); ?>
    <button data-role="none" class="sm-btn sm-b-lesen" type="submit" name="sicherung_holen" value="1"><?= sp_e(sp_t('SICHER.K_HOLEN')) ?></button>
  </form>
</div>
<form action="index.php" method="post" data-ajax="false" enctype="multipart/form-data">
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
<?php /* F6: welche Adresse die Tabellen dieses Reiters nennen und woher sie stammt. */ ?>
<div class="<?= $sp_adr_quelle === 'UI012.LOX_ADRESSE_LB' ? 'sm-hinweis' : 'sm-warnung' ?>"><?= sp_e(sprintf(sp_t('UI012.LOX_ADRESSE'), $sp_lb_adr)) ?> <?= sp_e(sp_t($sp_adr_quelle)) ?></div>

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
  <form action="index.php" method="post" data-ajax="false">
    <?php $sp_hidden('tab-loxone'); ?>
    <button data-role="none" class="sm-btn sm-b-lesen" type="submit" name="vorlage" value="eingang"><?= sp_e(sp_t('LOX.K_VORLAGE')) ?></button>
  </form>
  <form action="index.php" method="post" data-ajax="false">
    <?php $sp_hidden('tab-loxone'); ?>
    <button data-role="none" class="sm-btn sm-b-lesen" type="submit" name="vorlage" value="ziele"><?= sp_e(sp_t('LOX.K_VORLAGE_ZIELE')) ?></button>
  </form>
  <form action="index.php" method="post" data-ajax="false">
    <?php $sp_hidden('tab-loxone'); ?>
    <button data-role="none" class="sm-btn sm-b-lesen" type="submit" name="vorlage" value="ausgang"><?= sp_e(sp_t('LOX.K_VORLAGE_AUSGANG')) ?></button>
  </form>
</div>
<?php /* A4 (Runde 2): die Zielvorlage ist seit 0.12.0 ein virtueller
   UDP-Eingang (VirtualInUdp) mit dem Namen VIU_Sprachsteuerung_Ziele.xml,
   versorgt vom MQTT-Gateway. Der Hinweis zu "Noncached" steht auch im Kopf
   der Vorlage - hier, damit man ihn vor dem Import liest. */ ?>
<div class="sm-hinweis"><?= sp_e(sprintf(sp_t('UI013.VORLAGE_ZIELE'), 'VIU_Sprachsteuerung_Ziele.xml', sp_mqtt_gateway_udpport())) ?></div>
<div class="sm-warnung"><?= sp_e(sprintf(sp_t('UI013.VORLAGE_NONCACHED'), $sp_praefix)) ?></div>
</div>

<div class="sm-step"><b><?= sp_e(sp_t('LOX.S4_TITEL')) ?></b><br>
<?= sp_t('LOX.S4_TEXT') ?>
<div class="sm-roll">
<table class="sm-tbl">
<tr><th><?= sp_e(sp_t('ALLG.EIGENSCHAFT')) ?></th><th><?= sp_e(sp_t('ALLG.WERT')) ?></th></tr>
<tr><td><?= sp_e(sp_t('LOX.T_VA_ADRESSE')) ?></td><td><span class="sm-mono"><?= sp_e($sp_lb_adr) ?></span></td></tr>
<tr><td><?= sp_e(sp_t('LOX.T_VA_ANSAGE')) ?></td>
    <td><span class="sm-mono">/plugins/<?= sp_e($sp_p['plugin']) ?>/index.php?token=<?= sp_e($sp_token) ?>&amp;aktion=sprechen&amp;text=<?= sp_e(rawurlencode(sp_t('UI012.BSP_ANSAGE'))) ?></span></td></tr>
<tr><td><?= sp_e(sp_t('LOX.T_VA_ZONE')) ?></td>
    <td><span class="sm-mono">&hellip;&amp;aktion=sprechen&amp;text=<?= sp_e(rawurlencode(sp_t('UI012.BSP_ZONE'))) ?>&amp;zone=4</span></td></tr>
<tr><td><?= sp_e(sp_t('LOX.T_VA_DRINGEND')) ?></td>
    <td><span class="sm-mono">&hellip;&amp;aktion=sprechen&amp;text=<?= sp_e(rawurlencode(sp_t('UI012.BSP_DRINGEND'))) ?>&amp;dringend=1</span></td></tr>
<tr><td><?= sp_e(sp_t('LOX.T_VA_SATZ')) ?></td>
    <td><span class="sm-mono">/plugins/<?= sp_e($sp_p['plugin']) ?>/index.php?token=<?= sp_e($sp_token) ?>&amp;aktion=satz&amp;text=<?= sp_e(rawurlencode(sp_t('UI012.BSP_SATZ'))) ?></span></td></tr>
<tr><td><?= sp_e(sp_t('LOX.T_VA_RUHE')) ?></td>
    <td><span class="sm-mono">/plugins/<?= sp_e($sp_p['plugin']) ?>/index.php?token=<?= sp_e($sp_token) ?>&amp;aktion=ruhe&amp;wert=1</span></td></tr>
</table>
</div>
<?= sp_t('LOX.S4_ANSAGE') ?>
<?php /* D (Runde 2): Ansage trotz Ruhezeit mit dringend=1 - als vollstaendige
   Adresse - und das Token ausserhalb der Adresse (Kopf X-Token oder
   POST-Feld token): der Endpunkt nimmt beides seit 0.12.0 an. Aktion und
   Text bleiben in der Adresse, nur das Token zieht um - so steht es nicht im
   Zugriffsprotokoll des Webservers. Ein virtueller Ausgang in Loxone kann
   meist nur GET und bleibt bei ?token=. */ ?>
<div class="sm-roll">
<table class="sm-tbl">
<tr><th><?= sp_e(sp_t('UI013.T_BEISPIEL')) ?></th><th><?= sp_e(sp_t('ALLG.WERT')) ?></th></tr>
<tr><td><?= sp_e(sp_t('UI013.BSP_DRINGEND_VOLL')) ?></td>
    <td><span class="sm-mono"><?= sp_e($sp_basis) ?>?token=<?= sp_e($sp_token) ?>&amp;aktion=sprechen&amp;dringend=1&amp;text=<?= sp_e(rawurlencode(sp_t('UI012.BSP_DRINGEND'))) ?></span></td></tr>
<tr><td><?= sp_e(sp_t('UI013.BSP_DRINGEND_MIKRO')) ?></td>
    <td><span class="sm-mono">&hellip;&amp;aktion=sprechen&amp;dringend=1&amp;mikrofon=<?= sp_e(rawurlencode(sp_t('UI013.BSP_MIKROFON'))) ?>&amp;text=<?= sp_e(rawurlencode(sp_t('UI012.BSP_DRINGEND'))) ?></span></td></tr>
<tr><td><?= sp_e(sp_t('UI013.BSP_KOPF')) ?></td>
    <td><span class="sm-mono">curl -H 'X-Token: <?= sp_e($sp_token) ?>' '<?= sp_e($sp_basis) ?>?aktion=sprechen&amp;dringend=1&amp;text=<?= sp_e(rawurlencode(sp_t('UI012.BSP_DRINGEND'))) ?>'</span></td></tr>
<tr><td><?= sp_e(sp_t('UI013.BSP_POST')) ?></td>
    <td><span class="sm-mono">curl -d 'token=<?= sp_e($sp_token) ?>' '<?= sp_e($sp_basis) ?>?aktion=sprechen&amp;dringend=1&amp;text=<?= sp_e(rawurlencode(sp_t('UI012.BSP_DRINGEND'))) ?>'</span></td></tr>
</table>
</div>
<div class="sm-hinweis"><?= sp_e(sp_t('UI013.HINWEIS_TOKEN_POST')) ?></div>
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
  <form action="index.php" method="post" data-ajax="false">
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
    /* Suchtext und Themen werden GEBILDET, nicht abgeschrieben.
     * Der Suchtext entsteht in sp_check() - das ist laut Kommentar dort
     * die einzige Stelle, an der das Muster entsteht. Das Themenpraefix
     * ist einstellbar; bis 0.10.1 stand in dieser Tabelle fest
     * 'sprachsteuerung/aktion', waehrend der Reiter MQTT daneben das
     * eingestellte Praefix zeigte. Der Anwender tippt DIESE Tabelle ab. */
    $mono = function ($s) { return '<span class="sm-mono">' . sp_e($s) . '</span>'; };
    $praefix = trim((string) sp_config(false)['mqtt_topic'], '/');
    if ($praefix === '') { $praefix = 'sprachsteuerung'; }
    /* Die fuenf sprintf-Aufrufe stehen AUSGESCHRIEBEN und nicht in einer
     * Huelle: sprachplatzhalter_pruefen.py liest die Aufrufstelle und
     * zaehlt Platzhalter gegen Argumente. Ueber eine Huelle saehe es
     * nichts mehr und meldete fuenf Schluessel je Sprache als 'nirgends
     * durch sprintf gereicht'. Ein Werkzeug blind zu machen ist dasselbe,
     * wie es abzuschaffen. */
    return array(
        array(1,  'BAUSTEIN.T_VE',      'BAUSTEIN.N01',
              array('text' => sprintf(sp_t('BAUSTEIN.P01'), $mono(sp_check('OK')))),
              '&mdash;'),
        array(2,  'BAUSTEIN.T_VE',      'BAUSTEIN.N02',
              array('text' => sprintf(sp_t('BAUSTEIN.P02'), $mono(sp_check('ALTER')))),
              '&mdash;'),
        array(3,  'BAUSTEIN.T_VET',     'BAUSTEIN.N03',
              array('text' => sprintf(sp_t('BAUSTEIN.P03'), $mono($praefix . '/aktion'))),
              '&mdash;'),
        array(4,  'BAUSTEIN.T_VET',     'BAUSTEIN.N04',
              array('text' => sprintf(sp_t('BAUSTEIN.P04'), $mono($praefix . '/ziel'))),
              '&mdash;'),
        array(5,  'BAUSTEIN.T_VET',     'BAUSTEIN.N05',
              array('text' => sprintf(sp_t('BAUSTEIN.P05'), $mono($praefix . '/grund'))),
              '&mdash;'),
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
    <td><?= is_array($sp_b[3]) ? $sp_b[3]['text']
                               : ($sp_b[3] !== '' ? sp_t($sp_b[3]) : '&mdash;') ?></td>
    <td><?= $sp_b[4] ?></td></tr>
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
<a data-ajax="false" href="index.php?form=test"><?= sp_e(sp_t('TEST.K_JETZT_PRUEFEN')) ?></a></div>
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
<?php /* E1: Tabelle und Hinweis stehen beide da; das Skript laedt den Verlauf
   alle paar Sekunden nach (index.php?ajax=verlauf), solange der Reiter
   sichtbar ist, und schaltet zwischen beiden um. */ ?>
<div class="sm-hinweis" id="sp_verlauf_leer"<?= $sp_verlauf ? ' style="display:none;"' : '' ?>><?= sp_t('TEST.KEIN_VERLAUF') ?></div>
<div class="sm-roll" id="sp_verlauf_rahmen"<?= $sp_verlauf ? '' : ' style="display:none;"' ?>>
<table class="sm-tbl">
<thead><tr><th><?= sp_e(sp_t('TEST.T_ZEIT')) ?></th><th><?= sp_e(sp_t('TEST.T_VERSTANDEN')) ?></th>
    <th><?= sp_e(sp_t('TEST.T_MIKROFON')) ?></th>
    <th><?= sp_e(sp_t('TEST.T_ABSICHT')) ?></th><th><?= sp_e(sp_t('TEST.T_QUELLE')) ?></th>
    <th><?= sp_e(sp_t('TEST.T_ANTWORT')) ?></th></tr></thead>
<tbody id="sp_verlauf">
<?php foreach (array_slice($sp_verlauf, 0, 20) as $sp_v2) {
    if (!is_array($sp_v2)) { continue; }
    $sp_f = function ($k) use ($sp_v2) { return isset($sp_v2[$k]) && is_scalar($sp_v2[$k]) ? (string) $sp_v2[$k] : ''; }; ?>
<tr><td><?= sp_e(date('H:i:s', (int) $sp_f('ts'))) ?></td>
    <td><span class="sm-mono"><?= sp_e($sp_f('satz')) ?></span></td>
    <td><?= sp_e($sp_f('mikrofon')) ?></td>
    <td><?= !empty($sp_v2['ok']) ? sp_e($sp_f('absicht') . '/' . $sp_f('aktion')) : '<span class="sm-aus">' . sp_e($sp_f('grund')) . '</span>' ?></td>
    <td><?= sp_e($sp_f('quelle')) ?></td>
    <td><?= sp_e($sp_f('antwort')) ?></td></tr>
<?php } ?>
</tbody>
</table>
</div>

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
      <form action="index.php" method="post" data-ajax="false" style="display:flex;gap:6px;align-items:center;">
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
  <a data-ajax="false" class="sm-btn sm-b-lesen" href="<?= sp_e($sp_basis) ?>?token=<?= sp_e($sp_token) ?>&amp;aktion=status" target="_blank"><?= sp_e(sp_t('TEST.K_STATUS')) ?></a>
  <a data-ajax="false" class="sm-btn sm-b-lesen" href="<?= sp_e($sp_basis) ?>?token=<?= sp_e($sp_token) ?>&amp;aktion=verlauf" target="_blank"><?= sp_e(sp_t('TEST.K_VERLAUF')) ?></a>
  <a data-ajax="false" class="sm-btn sm-b-lesen" href="<?= sp_e($sp_basis) ?>?token=<?= sp_e($sp_token) ?>&amp;aktion=diag" target="_blank"><?= sp_e(sp_t('TEST.K_DIAG')) ?></a>
  <form action="index.php" method="post" data-ajax="false">
    <?php $sp_hidden('tab-test'); ?>
    <button data-role="none" class="sm-btn sm-b-lesen" type="submit" name="verlauf_csv" value="1"><?= sp_e(sp_t('TEST.K_CSV')) ?></button>
  </form>
</div>

<h3><?= sp_e(sp_t('TEST.H_TECHNIK')) ?></h3>
<div class="sm-knopfreihe">
  <form action="index.php" method="post" data-ajax="false">
    <?php $sp_hidden('tab-test'); ?>
    <button data-role="none" class="sm-btn sm-b-technik" type="submit" name="selbsttest" value="1"><?= sp_e(sp_t('TEST.K_SELBSTTEST')) ?></button>
  </form>
  <form action="index.php" method="post" data-ajax="false">
    <?php $sp_hidden('tab-test'); ?>
    <button data-role="none" class="sm-btn sm-b-technik" type="submit" name="test" value="dienste"><?= sp_e(sp_t('TEST.K_DIENSTE')) ?></button>
  </form>
  <form action="index.php" method="post" data-ajax="false">
    <?php $sp_hidden('tab-test'); ?>
    <button data-role="none" class="sm-btn sm-b-technik" type="submit" name="test" value="neu_laden"><?= sp_e(sp_t('TEST.K_NEU_LADEN')) ?></button>
  </form>
  <a data-ajax="false" class="sm-btn sm-b-technik" href="<?= sp_e($sp_basis) ?>?token=<?= sp_e($sp_token) ?>&amp;aktion=roh" target="_blank"><?= sp_e(sp_t('TEST.K_ROH')) ?></a>
</div>
<?php if ($sp_ausgabe !== '' && $sp_tab === 'tab-test') { ?>
<div class="sm-pre"><?= sp_e($sp_ausgabe) ?></div>
<?php } ?>

<h3><?= sp_e(sp_t('TEST.H_TROCKEN')) ?></h3>
<div class="sm-hinweis"><?= sp_t('TEST.TROCKEN_ERKLAERUNG') ?></div>
<form action="index.php" method="post" data-ajax="false">
<?php $sp_hidden('tab-test'); ?>
<div class="sm-feld">
  <label for="test_satz"><?= sp_e(sp_t('TEST.L_SATZ')) ?></label>
  <input data-role="none" type="text" id="test_satz" name="test_satz" value="<?= sp_e(sp_t('UI012.TEST_SATZ')) ?>">
  <div class="sm-hilfe"><?= sp_t('TEST.H_SATZ') ?></div>
</div>
<div class="sm-feld">
  <label for="test_raum"><?= sp_e(sp_t('TEST.L_RAUM')) ?></label>
  <input data-role="none" type="text" id="test_raum" name="test_raum" value="" placeholder="<?= sp_e(sp_t('UI012.P_RAUM')) ?>">
  <div class="sm-hilfe"><?= sp_t('TEST.H_RAUM') ?></div>
</div>
<div class="sm-feld">
  <label for="test_ansage"><?= sp_e(sp_t('TEST.L_ANSAGE')) ?></label>
  <input data-role="none" type="text" id="test_ansage" name="test_ansage" value="<?= sp_e(sp_t('UI012.PROBE_TEXT')) ?>">
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
<div class="sm-legende"><span><i class="sm-punkt sm-b-lesen"></i> <?= sp_t('LEGENDE.LESEN') ?></span></div>
<div class="sm-knopfreihe">
  <form action="index.php" method="post" data-ajax="false">
    <?php $sp_hidden('tab-log'); ?>
    <button data-role="none" class="sm-btn sm-b-lesen" type="submit" name="log_holen" value="protokoll"><?= sp_e(sp_t('UI012.K_LOG_HOLEN')) ?></button>
  </form>
</div>
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
  <form action="index.php" method="post" data-ajax="false">
    <?php $sp_hidden('tab-log'); ?>
    <button data-role="none" class="sm-btn sm-b-technik" type="submit" name="mitschnitt" value="300"><?= sp_e(sp_t('LOG.K_MITSCHNITT_5')) ?></button>
  </form>
  <form action="index.php" method="post" data-ajax="false">
    <?php $sp_hidden('tab-log'); ?>
    <button data-role="none" class="sm-btn sm-b-technik" type="submit" name="mitschnitt" value="900"><?= sp_e(sp_t('LOG.K_MITSCHNITT_15')) ?></button>
  </form>
  <form action="index.php" method="post" data-ajax="false">
    <?php $sp_hidden('tab-log'); ?>
    <button data-role="none" class="sm-btn sm-b-technik" type="submit" name="mitschnitt" value="0"><?= sp_e(sp_t('LOG.K_MITSCHNITT_AUS')) ?></button>
  </form>
</div>
<?php $sp_mz = sp_log_ende($sp_p['mitschnitt'], 200); if ($sp_mz) { ?>
<div class="sm-log"><?= sp_e(implode("\n", $sp_mz)) ?></div>
<div class="sm-legende"><span><i class="sm-punkt sm-b-lesen"></i> <?= sp_t('LEGENDE.LESEN') ?></span></div>
<div class="sm-knopfreihe">
  <form action="index.php" method="post" data-ajax="false">
    <?php $sp_hidden('tab-log'); ?>
    <button data-role="none" class="sm-btn sm-b-lesen" type="submit" name="log_holen" value="mitschnitt"><?= sp_e(sp_t('UI012.K_MITSCHNITT_HOLEN')) ?></button>
  </form>
</div>
<?php } ?>

<div class="sm-legende"><span><i class="sm-punkt sm-b-aktion"></i> <?= sp_t('LEGENDE.AKTION_LOG') ?></span></div>
<div class="sm-knopfreihe">
  <form action="index.php" method="post" data-ajax="false">
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
	// KEIN sp_e() hier: in einem <script>-Block loest der Browser keine
	// Entitaeten auf. Bis 0.10.1 kam der Reitername hier maskiert an, mit
	// den Anfuehrungszeichen als Entitaet - ein Syntaxfehler, der den
	// EINZIGEN Skriptblock der Seite mitnahm. Der Wortlaut steht bewusst
	// NICHT hier: js_pruefen.py weist jede Entitaet im Skriptblock ab und
	// wuerde sonst auf diesen Kommentar anschlagen.
	// Die Regel 'json_encode gehoert durch die Maskierfunktion' gilt fuer
	// ATTRIBUTE. json_encode maskiert < > & selbst.
	zeige(<?= json_encode($sp_tab) ?>);

	// Sichtbar ist ein Reiter, wenn seine Flaeche sm-active traegt und das
	// Browserfenster nicht im Hintergrund liegt.
	function sichtbar(id) {
		var s = document.getElementById(id);
		return !!s && s.classList.contains('sm-active') && !document.hidden;
	}

	// F9: waehrend eines Container-Vorgangs neu laden - aber nur, solange der
	// Reiter Dienste zu sehen ist. Bis 0.11.15 lud ein meta refresh die
	// ganze Seite alle 5 s, und Eingaben in einem anderen Reiter waren weg.
	var lauf = document.getElementById('sp_ct_lauf');
	if (lauf) {
		var sek = parseInt(lauf.getAttribute('data-neu-laden'), 10) || 5;
		setInterval(function () {
			if (sichtbar('tab-services')) { window.location.href = 'index.php?form=services'; }
		}, sek * 1000);
	}

	// E1: den Verlauf im Reiter Test nachladen (nur lesend, GET), solange der
	// Reiter sichtbar ist. Die Werte kommen roh und werden als Text gesetzt.
	var vk = document.getElementById('sp_verlauf');
	if (vk && window.fetch) {
		var zelle = function (zeile, text, klasse, mono) {
			var td = document.createElement('td');
			var ziel = td;
			if (mono || klasse) {
				ziel = document.createElement('span');
				if (mono) { ziel.className = 'sm-mono'; }
				if (klasse) { ziel.className = klasse; }
				td.appendChild(ziel);
			}
			ziel.textContent = text;
			zeile.appendChild(td);
		};
		setInterval(function () {
			if (!sichtbar('tab-test')) { return; }
			fetch('index.php?ajax=verlauf', { credentials: 'same-origin', cache: 'no-store' })
				.then(function (a) { return a.ok ? a.json() : null; })
				.then(function (d) {
					if (!d || !d.zeilen) { return; }
					while (vk.firstChild) { vk.removeChild(vk.firstChild); }
					d.zeilen.forEach(function (z) {
						var tr = document.createElement('tr');
						zelle(tr, z.zeit, '', false);
						zelle(tr, z.satz, '', true);
						zelle(tr, z.mikrofon, '', false);
						zelle(tr, z.absicht, z.ok ? '' : 'sm-aus', false);
						zelle(tr, z.quelle, '', false);
						zelle(tr, z.antwort, '', false);
						vk.appendChild(tr);
					});
					document.getElementById('sp_verlauf_rahmen').style.display = d.zeilen.length ? '' : 'none';
					document.getElementById('sp_verlauf_leer').style.display = d.zeilen.length ? 'none' : '';
				})
				.catch(function () { /* naechster Versuch beim naechsten Takt */ });
		}, 5000);
	}

	// E2: das JSON des Rohtexts vor dem Absenden pruefen und Zeile/Spalte
	// nennen. Die Browser melden die Stelle verschieden ("line 3 column 5"
	// oder "at position 42") - beides wird gelesen.
	var rf = document.getElementById('sp_rohtext_form');
	if (rf) {
		var texte = <?= json_encode(array('fehler' => sp_t('UI012.JSON_FEHLER'), 'stelle' => sp_t('UI012.JSON_STELLE')),
		                            JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE) ?>;
		rf.addEventListener('submit', function (e) {
			var feld = document.getElementById('sp_rohtext');
			var meld = document.getElementById('sp_rohtext_fehler');
			try {
				JSON.parse(feld.value);
				meld.style.display = 'none';
			} catch (fehler) {
				var m = String(fehler && fehler.message ? fehler.message : fehler);
				var zeile = 0, spalte = 0, t;
				if ((t = /line (\d+) column (\d+)/i.exec(m))) {
					zeile = parseInt(t[1], 10); spalte = parseInt(t[2], 10);
				} else if ((t = /position (\d+)/i.exec(m))) {
					var vor = feld.value.substring(0, parseInt(t[1], 10)).split('\n');
					zeile = vor.length; spalte = vor[vor.length - 1].length + 1;
				}
				meld.textContent = texte.fehler + ' ' + m
					+ (zeile ? ' ' + texte.stelle.replace('{zeile}', zeile).replace('{spalte}', spalte) : '');
				meld.style.display = '';
				feld.focus();
				e.preventDefault();
			}
		});
	}

	// E4: Raum- und Artfilter fuer die Vorschlaege aus Loxone. Ausgeblendete
	// Zeilen werden abgeschaltet und gehen nicht mit.
	var lf = document.getElementById('sp_lox_filter');
	var lt = document.getElementById('sp_lox_tabelle');
	if (lf && lt) {
		lf.style.display = 'flex';
		var raum = document.getElementById('sp_lox_raum');
		var art = document.getElementById('sp_lox_art');
		var zeilen = lt.querySelectorAll('tr[data-art]');
		var filtern = function () {
			zeilen.forEach(function (z) {
				var zeigen = (raum.value === '*' || z.getAttribute('data-raum') === raum.value)
					&& (art.value === '*' || z.getAttribute('data-art') === art.value);
				z.style.display = zeigen ? '' : 'none';
				var h = z.querySelector('input[type=checkbox]');
				if (h) { h.disabled = !zeigen; }
			});
		};
		var alle = function (an) {
			zeilen.forEach(function (z) {
				var h = z.querySelector('input[type=checkbox]');
				if (h && !h.disabled) { h.checked = an; }
			});
		};
		raum.addEventListener('change', filtern);
		art.addEventListener('change', filtern);
		document.getElementById('sp_lox_alle').addEventListener('click', function () { alle(true); });
		document.getElementById('sp_lox_keine').addEventListener('click', function () { alle(false); });
	}
})();
</script>
<?php
if ($sp_rahmen) {
    LBWeb::lbfooter();
}
