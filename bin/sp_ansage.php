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
 *   {"art": "ng", "modus": "alexang"|"cc4lox", "port": <Webport>, "tmo": <s>, "felder": {...}}
 *   {"art": "get", "url": "<Adresse des Music Servers oder der Vorlage>", "tmo": <s>}
 * Antwort: EINE Zeile JSON auf der Standardausgabe, ohne Text und ohne Token:
 *   ng:  {"code": <HTTP oder 0>, "zeile": "<erste Antwortzeile>", "grund_id": "", "adresse": "..."}
 *   get: {"code": <HTTP oder 0>, "grund_id": "<Kennung oder leer>"}
 * Rueckgabewert 0 = Auftrag ausgefuehrt (das Ergebnis steht in der Zeile),
 * 2 = Aufruf falsch, 1 = die gemeinsame Sprachausgabe fehlt.
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
$tmo = max(1, min(30, $tmo));
$k = array('kopf' => array('User-Agent: LoxBerry Sprachsteuerung'));

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
    $port = isset($auftrag['port']) && is_int($auftrag['port']) ? $auftrag['port'] : 80;
    $adresse = ansage_adresse($modus, $port);
    $a = ansage_ng_rufen($adresse, $felder, $tmo, $k);
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
    $a = ansage_ausfuehren(ansage_anfrage('GET', $url, null, $tmo, $k), $k);
    $gid = ansage_http_grund_id($a['code'] > 0 ? 0 : ($a['errno'] === 0 ? -1 : $a['errno']), $a['code']);
    echo json_encode(array('code' => (int) $a['code'], 'grund_id' => $gid)), "\n";
    exit(0);
}

fwrite(STDERR, "Auftrag: art unbekannt.\n");
exit(2);
