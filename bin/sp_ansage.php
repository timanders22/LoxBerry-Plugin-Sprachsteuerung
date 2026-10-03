<?php
/**
 * Sprachsteuerung lokal - Bruecke des Dienstes zur gemeinsamen Sprachausgabe
 *
 * Aufruf:  php sp_ansage.php <Pluginordner>   (Auftrag als JSON auf der Standardeingabe)
 *
 * Der Dienst ist in Python geschrieben; die gemeinsame Sprachausgabe der Plugins
 * dieses Hauses (sprachausgabe.php, Nr. 36 b) gibt es nur in PHP. Stufe 1
 * (verhaltensgleich): der Dienst behaelt seine Bewertung, seine Saetze und
 * seinen Rueckfall auf die Lautsprecher der Sprachgeraete - ueber diese Bruecke
 * geht nur der TRANSPORT (Alexa-NG, Chromecast 4 Lox NG, Music Server und
 * Adressvorlagen). Damit gelten dieselben Regeln wie in den PHP-Plugins: kein
 * Proxy, keine Weiterleitung, nur http/https, Sprechtoken nur im POST-Koerper und
 * aus der Antwortzeile ersetzt, Alexa-NG auf dem Webport des LoxBerry.
 *
 * Der Auftrag kommt auf der STANDARDEINGABE, nie auf der Kommandozeile: er traegt
 * Ansagetext und Sprechtoken, und die Kommandozeile sieht jeder in der Prozessliste.
 *   {"art": "ng", "modus": "alexang"|"cc4lox", "port": <Webport>, "tmo": <s>, "felder": {...},
 *    "dringend": 0|1}
 *   {"art": "get", "url": "<Adresse des Music Servers oder der Vorlage>", "tmo": <s>}
 *   {"art": "sonos4lox", "zone": "<Zone>", "laut": -1|1-100, "text": "...", "dringend": 0|1}
 * Antwort: EINE Zeile JSON auf der Standardausgabe, ohne Text und ohne Token:
 *   ng:        {"code": <HTTP oder 0>, "zeile": "<Antwortzeile>", "grund_id": "", "adresse": "..."}
 *   get:       {"code": <HTTP oder 0>, "grund_id": "<Kennung oder leer>"}
 *   sonos4lox: {"code": <HTTP oder 0>, "grund_id": "<Kennung oder leer>", "stand": 1|0|-1}
 * Rueckgabewert 0 = Auftrag ausgefuehrt (das Ergebnis steht in der Zeile),
 * 2 = Aufruf falsch, 1 = die gemeinsame Sprachausgabe fehlt.
 *
 * Seit 0.12.0 (Sprachausgabe 1.1.0):
 *   - get prueft die fertige Adresse wie die Sprachausgabe selbst (Heimnetz,
 *     keine Benutzerdaten, nur http/https). Bis 0.11.15 ging jede http(s)-
 *     Adresse hinaus - die Heimnetz-Regel galt auf diesem Weg gar nicht. Eine
 *     abgewiesene Adresse antwortet mit code 0 und grund_id HTTP_KEIN_HTTP,
 *     TTS_VORLAGE_HTTP, TTS_VORLAGE_BENUTZER oder TTS_VORLAGE_HEIMNETZ; der
 *     Dienst wertet code 0 mit grund_id als "nicht gesendet".
 *   - ng: "laut" muss 1 bis 100 sein (0 spraeche stumm und meldete OK=1),
 *     sonst code 0 und grund_id UNTER|tts.laut|AUSSERHALB|1|100. "dringend": 1
 *     setzt felder.dringend=1. Die Antwortzeile ist die erste, die mit
 *     SPRECHEN; bzw. SELFTEST; beginnt (eine Warnung oder BOM davor stoert nicht).
 *   - tmo hoechstens ANSAGE_TMO (10 s), wie in allen Linien.
 *   - Webport und Plugin-Ordner bestimmt die Bruecke selbst, wenn sie den
 *     LoxBerry findet (lbwebserverport(), plugindatabase.json); "port" gilt
 *     nur ohne LoxBerry-Wurzel.
 *
 * Der Pluginordner wird mitgegeben wie bei sp_notify.php: dem Dienst koennen die
 * LoxBerry-Umgebungsvariablen fehlen, und bei einer Zweitinstallation heisst der
 * Ordner sprachsteuerung_01.
 */

error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    echo "ANSAGE;OK=0;GRUND=KEIN_ENDPUNKT\n";
    exit;
}

/* Den LoxBerry-Wurzelordner bestimmen - dieselbe Regel wie sp_notify.php. */
function sp_ansage_wurzel()
{
    $d = __DIR__;
    for ($i = 0; $i < 8; $i++) {
        if (is_dir($d . '/config/plugins') && is_dir($d . '/webfrontend')
            && is_file($d . '/config/system/general.json')) {
            return $d;
        }
        $eltern = dirname($d);
        if ($eltern === $d) { break; }
        $d = $eltern;
    }
    return '';
}

$home = getenv('LBHOMEDIR');
if (!$home || !is_dir($home . '/config/plugins')) {
    $home = sp_ansage_wurzel();
}
$paket = isset($argv[1]) ? preg_replace('/[^A-Za-z0-9_\-]/', '', (string) $argv[1]) : '';
if ($paket === '') { $paket = preg_replace('/[^A-Za-z0-9_\-]/', '', (string) getenv('LBPPLUGINDIR')); }
if ($paket === '') { $paket = 'sprachsteuerung'; }
$modul = $home . '/webfrontend/html/plugins/' . $paket . '/sprachausgabe.php';
if (!$home || !is_file($modul)) {
    fwrite(STDERR, "Gemeinsame Sprachausgabe nicht gefunden: " . $modul . "\n");
    exit(1);
}
require_once $modul;

$roh = stream_get_contents(STDIN);
$auftrag = is_string($roh) ? json_decode($roh, true) : null;
if (!is_array($auftrag) || !isset($auftrag['art']) || !is_string($auftrag['art'])) {
    fwrite(STDERR, "Auftrag fehlt oder ist kein JSON.\n");
    exit(2);
}
$tmo = isset($auftrag['tmo']) && is_numeric($auftrag['tmo']) ? (int) ceil((float) $auftrag['tmo']) : ANSAGE_TMO;
/* Hoechstens ANSAGE_TMO: bis 0.11.15 bis 30 s, und der Dienst schickte fuer
 * Alexa-NG 15 s - laenger als die 10 s, die in allen Linien gelten. */
$tmo = max(1, min(ANSAGE_TMO, $tmo));
$k = array('kopf' => array('User-Agent: LoxBerry Sprachsteuerung'), 'home' => (string) $home);
$general = $home . '/config/system/general.json';
$k['sslport'] = ansage_sslport($general);
/* Der Webport: die Bruecke fragt den LoxBerry selbst (lbwebserverport(), sonst
 * general.json); was der Dienst mitgibt, gilt nur, wenn general.json fehlt. */
$port = isset($auftrag['port']) && is_int($auftrag['port']) ? $auftrag['port'] : 80;
if (is_file($general)) { $port = ansage_webport($general); }
$k['port'] = $port;
$dringend = isset($auftrag['dringend']) && ($auftrag['dringend'] === true || $auftrag['dringend'] === 1
                                             || $auftrag['dringend'] === '1');

if ($auftrag['art'] === 'ng') {
    $modus = isset($auftrag['modus']) ? $auftrag['modus'] : '';
    $felder = isset($auftrag['felder']) && is_array($auftrag['felder']) ? $auftrag['felder'] : null;
    if (!in_array($modus, array('alexang', 'cc4lox'), true) || $felder === null) {
        fwrite(STDERR, "Auftrag ng: modus oder felder falsch.\n");
        exit(2);
    }
    foreach ($felder as $n => $w) {
        if (!is_string($n) || !is_scalar($w)) {
            fwrite(STDERR, "Auftrag ng: felder nur als Namen und Einzelwerte.\n");
            exit(2);
        }
        $felder[$n] = (string) $w;
    }
    if ($dringend) { $felder['dringend'] = '1'; }
    $adresse = ansage_adresse($modus, $port, $k['home']);
    /* laut=0 nehmen Alexa-NG und Chromecast an, sprechen stumm und melden OK=1 -
     * gesendet, aber nicht zu hoeren, und kein Rueckfall. Abgewiesen wird hier,
     * vor dem Ruf, mit derselben Kennung wie in der Sprachausgabe. */
    if (isset($felder['laut'])) {
        $lg = '';
        if (ansage_laut_pruefen($felder['laut'], $lg) === null || (int) $felder['laut'] < 1) {
            if ($lg === '') { $lg = 'AUSSERHALB|1|100'; }
            echo json_encode(array('code' => 0, 'zeile' => '', 'grund_id' => 'UNTER|tts.laut|' . $lg,
                                   'adresse' => $adresse), JSON_UNESCAPED_SLASHES), "\n";
            exit(0);
        }
    }
    $a = ansage_ng_rufen($adresse, $felder, $tmo, $k, isset($felder['selftest']) ? 'SELFTEST' : 'SPRECHEN');
    echo json_encode(array('code' => (int) $a['code'], 'zeile' => (string) $a['roh'],
                           'grund_id' => (string) $a['grund_id'], 'adresse' => $adresse),
                     JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE), "\n";
    exit(0);
}

if ($auftrag['art'] === 'get') {
    $url = isset($auftrag['url']) && is_string($auftrag['url']) ? $auftrag['url'] : '';
    if ($url === '') {
        fwrite(STDERR, "Auftrag get: url fehlt.\n");
        exit(2);
    }
    /* Die Adresse baut der Dienst; bis 0.11.15 ging sie nach einer reinen
     * http/https-Pruefung hinaus, auch https://beliebig.example/?t=<Text>. Jetzt
     * dieselbe Pruefung wie in der Sprachausgabe (ansage_url_grund()). */
    $gid = ansage_url_grund($url);
    if ($gid !== '') {
        echo json_encode(array('code' => 0, 'grund_id' => $gid)), "\n";
        exit(0);
    }
    $a = ansage_ausfuehren(ansage_anfrage('GET', $url, null, $tmo, $k), $k);
    $gid = ansage_http_grund_id($a['code'] > 0 ? 0 : ($a['errno'] === 0 ? -1 : $a['errno']), $a['code']);
    echo json_encode(array('code' => (int) $a['code'], 'grund_id' => $gid)), "\n";
    exit(0);
}

if ($auftrag['art'] === 'sonos4lox') {
    /* Sonos4Lox ueber die Sprachausgabe (ansage_sprechen()); Text, Zone und
     * Lautstaerke prueft sie. Die Zeitgrenze ist ANSAGE_TMO - Sonos4Lox antwortet
     * erst nach der Wiedergabe, siehe SONOS_ZEIT_UNKLAR dort. */
    if (!isset($auftrag['text']) || !is_string($auftrag['text'])) {
        fwrite(STDERR, "Auftrag sonos4lox: text fehlt.\n");
        exit(2);
    }
    $tts = array('mode' => 'sonos4lox',
                 'sonos_zone' => isset($auftrag['zone']) ? $auftrag['zone'] : '',
                 'sonos_laut' => isset($auftrag['laut']) ? $auftrag['laut'] : -1);
    $r = ansage_sprechen($auftrag['text'], $tts, $k, array('dringend' => $dringend));
    echo json_encode(array('code' => (int) $r['http'], 'grund_id' => (string) $r['kennung'], 'stand' => (int) $r['stand']),
                     JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE), "\n";
    exit(0);
}

fwrite(STDERR, "Auftrag: art unbekannt.\n");
exit(2);
