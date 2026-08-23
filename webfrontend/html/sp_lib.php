<?php
/**
 * Sprachsteuerung lokal - gemeinsame Bibliothek
 *
 * Liegt unter webfrontend/html/, weil der Miniserver-Endpunkt sie ebenso
 * braucht wie die Oberflaeche. So gibt es EINE Datei statt zweier Kopien.
 *
 * Das Plugin ist die Vermittlung zwischen Mikrofonen, Spracherkennung,
 * Sprachausgabe und Loxone. Die schweren Teile laufen in Containern; diese
 * Bibliothek verwaltet sie ueber die Docker-Kommandozeile, liest den
 * Zwischenspeicher des Dienstes und legt Befehle in einer Warteschlange ab.
 * Sie spricht selbst nie mit einem Mikrofon.
 *
 * Praefix 'sp_', weil LBWeb::lbheader() SDK-Globale setzt.
 * Kompatibel mit PHP 7.4 und PHP 8.x (LoxBerry 3.x/4.x).
 */

if (!function_exists('sp_e')) {
    function sp_e($s)
    {
        return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
    }
}


/* Den LoxBerry-Wurzelordner ohne festen Systempfad bestimmen.
 *
 * Vom eigenen Ablageort aufwaerts, bis ein Verzeichnis gefunden ist, das
 * config/plugins UND webfrontend enthaelt. Das trifft die uebliche
 * Installation genauso wie eine an einem anderen Ort - und es trifft auch
 * den Fall, dass das Plugin noch als entpacktes Archiv daliegt (dann findet
 * es nichts und gibt einen Leerstring zurueck, was der Aufrufer ohnehin
 * abfangen muss).
 *
 * Der Name traegt kein Plugin-Kuerzel und ist deshalb abgesichert: zwei
 * Bibliotheken landen nie im selben Prozess, aber die Pruefung kostet nichts.
 */
if (!function_exists('lb_wurzel_ermitteln')) {
    function lb_wurzel_ermitteln()
    {
        $d = __DIR__;
        for ($i = 0; $i < 8; $i++) {
            if (is_dir($d . '/config/plugins') && is_dir($d . '/webfrontend')) {
                return $d;
            }
            $eltern = dirname($d);
            if ($eltern === $d) { break; }
            $d = $eltern;
        }
        return '';
    }
}

function sp_paths()
{
    static $p = null;
    if ($p !== null) {
        return $p;
    }
    $home = getenv('LBHOMEDIR');
    if (!$home || !is_dir($home)) {
        foreach (array(lb_wurzel_ermitteln(), '/home/loxberry/loxberry') as $k) {
            if (is_dir($k)) { $home = $k; break; }
        }
    }
    // Der Pluginordner ergibt sich aus dem Ablageort dieser Datei. Der
    // MD5-Schluessel aus der plugindatabase.json wird bewusst NICHT benutzt.
    $dir = basename(dirname(__FILE__));
    /* Frueher wurde hier auf den festen Namen "sprachsteuerung" zurueckgefallen,
     * sobald config/plugins/<ordner> noch fehlte - etwa im Augenblick der
     * Installation. Haengt LoxBerry bei einer Zweitinstallation einen Zaehler
     * an (sprachsteuerung_01, weil der Name schon belegt war), zeigten deren
     * Pfade damit auf die ERSTE Installation: gemeinsame Konfiguration,
     * gemeinsame Warteschlange, gemeinsames Protokoll.
     *
     * LBPPLUGINDIR ist die Auskunft von LoxBerry selbst und bleibt deshalb.
     * Der feste Name greift nur noch dort, wo der ermittelte nachweislich kein
     * Plugin-Ordner sein kann: aus dem ausgepackten Archiv heraus heisst er
     * "html". */
    $lbp = getenv('LBPPLUGINDIR');
    if ($lbp) {
        $dir = $lbp;
    } elseif ($dir === '' || $dir === '.' || $dir === '/' || $dir === 'html') {
        $dir = 'sprachsteuerung';
    }
    if ($home) {
        $p = array(
            'home' => $home, 'plugin' => $dir,
            'configdir' => $home . '/config/plugins/' . $dir,
            'config'    => $home . '/config/plugins/' . $dir . '/sprachsteuerung.json',
            'saetze'    => $home . '/config/plugins/' . $dir . '/saetze.json',
            'sicherung' => $home . '/config/plugins/' . $dir . '.backup.sprachsteuerung.json',
            'sicherung_saetze' => $home . '/config/plugins/' . $dir . '.backup.saetze.json',
            'datadir'   => $home . '/data/plugins/' . $dir,
            'bindir'    => $home . '/bin/plugins/' . $dir,
            'logdir'    => $home . '/log/plugins/' . $dir,
            'log'       => $home . '/log/plugins/' . $dir . '/sprachsteuerung.log',
            'modelle'   => $home . '/templates/plugins/' . $dir . '/modelle.json',
        );
    } else {
        $basis = dirname(dirname(__DIR__));
        $p = array(
            'home' => '', 'plugin' => $dir,
            'configdir' => $basis . '/config',
            'config'    => $basis . '/config/sprachsteuerung.json',
            'saetze'    => $basis . '/config/saetze.json',
            'sicherung' => $basis . '/config/sprachsteuerung.backup.json',
            'sicherung_saetze' => $basis . '/config/saetze.backup.json',
            'datadir'   => $basis . '/data',
            'bindir'    => $basis . '/bin',
            'logdir'    => $basis . '/log',
            'log'       => $basis . '/log/sprachsteuerung.log',
            'modelle'   => $basis . '/templates/modelle.json',
        );
    }
    return $p;
}

/** Voreinstellungen. Muessen zu VORGABEN in bin/sprachsteuerung_dienst.py passen. */
function sp_vorgaben()
{
    return array(
        'satelliten'       => array(),
        'whisper_host'     => '127.0.0.1', 'whisper_port' => 10300,
        'piper_host'       => '127.0.0.1', 'piper_port'   => 10200,
        'wake_host'        => '127.0.0.1', 'wake_port'    => 10400,
        'llm_host'         => '127.0.0.1', 'llm_port'     => 8080,
        'llm_ein'          => 0,
        'sprache'          => 'de',
        'wakeword'         => 'ok_nabu',
        'antwort_sprechen' => 1,
        // Wohin die Antwort geht: satellit | loxone | beide.
        // Feldnamen und Modi des tts-Blocks sind wortgleich mit
        // LoxBerry-Plugin-AWM-Abfuhr und dem Abfahrtsassistenten - im Haus
        // soll eine Bedienung gelten und nicht drei.
        'antwortweg'       => 'beide',
        'tts'              => array('mode' => 'musicserver', 'ip' => '', 'port' => 7091,
                                    'zones' => '1', 'volume' => 8, 'lang' => 'de',
                                    'template' => ''),
        'mqtt_ein'         => 1,
        'mqtt_topic'       => 'sprachsteuerung',
        'miniserver_url'   => '',
        'aktionstoken'     => '',
        'wartezeit'        => 10,
        'verlauf_zeilen'   => 50,
        'whisper_modell'   => '',
        'piper_stimme'     => '',
        'llm_modell'       => '',
    );
}

function sp_json_lesen($pfad)
{
    if (!is_file($pfad)) { return array(); }
    $d = json_decode((string) @file_get_contents($pfad), true);
    return is_array($d) ? $d : array();
}

function sp_json_schreiben($pfad, $daten, $rechte = null)
{
    $ordner = dirname($pfad);
    if (!is_dir($ordner) && !@mkdir($ordner, 0775, true) && !is_dir($ordner)) { return false; }
    $tmp = $pfad . '.tmp';
    $json = json_encode($daten, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false || @file_put_contents($tmp, $json) === false) { @unlink($tmp); return false; }
    if ($rechte !== null) { @chmod($tmp, $rechte); }
    return @rename($tmp, $pfad);
}

function sp_config()
{
    $p = sp_paths();
    $roh = is_file($p['config']) ? trim((string) @file_get_contents($p['config'])) : '';
    if (($roh === '' || $roh === '{}') && is_file($p['sicherung'])) {
        @mkdir($p['configdir'], 0775, true);
        @copy($p['sicherung'], $p['config']);
    }
    $cfg = array_merge(sp_vorgaben(), sp_json_lesen($p['config']));

    // array_merge ersetzt einen verschachtelten Block vollstaendig. Steht in
    // der gespeicherten Datei nur ein Teil des tts-Blocks - etwa allein die
    // Adresse -, fehlten sonst alle uebrigen Felder. Deshalb wird er auf die
    // Vorgaben GELEGT. Dieselbe Regel gilt im Dienst (config() in
    // sprachsteuerung_dienst.py); beide Seiten muessen gleich rechnen.
    $vor = sp_vorgaben();
    $tts = is_array(isset($cfg['tts']) ? $cfg['tts'] : null)
         ? array_merge($vor['tts'], $cfg['tts']) : $vor['tts'];
    if (!in_array($tts['mode'], array('musicserver', 'ms4h', 'audioserver', 'custom'), true)) {
        $tts['mode'] = 'musicserver';
    }
    $tts['ip'] = trim((string) $tts['ip']);
    $tts['port'] = max(1, min(65535, (int) $tts['port']));
    $tts['volume'] = max(1, min(100, (int) $tts['volume']));
    $tts['zones'] = trim((string) $tts['zones']) !== '' ? trim((string) $tts['zones']) : '1';
    $tts['lang'] = preg_replace('/[^a-z]/', '', strtolower((string) $tts['lang'])) ?: 'de';
    $tts['template'] = trim((string) $tts['template']);
    $cfg['tts'] = $tts;

    if (!in_array($cfg['antwortweg'], array('satellit', 'loxone', 'beide'), true)) {
        $cfg['antwortweg'] = 'beide';
    }
    return $cfg;
}

function sp_config_speichern($cfg)
{
    $p = sp_paths();
    // Die Konfiguration kann eine Miniserver-Adresse mit Zugangsdaten
    // enthalten - deshalb 0600, nicht 0644.
    if (!sp_json_schreiben($p['config'], $cfg, 0600)) { return false; }
    @copy($p['config'], $p['sicherung']);
    @chmod($p['sicherung'], 0600);
    return true;
}

function sp_saetze()
{
    return sp_json_lesen(sp_paths()['saetze']);
}

function sp_saetze_speichern($saetze)
{
    $p = sp_paths();
    if (!sp_json_schreiben($p['saetze'], $saetze)) { return false; }
    @copy($p['saetze'], $p['sicherung_saetze']);
    return true;
}

/** Die Empfehlungstabelle: EINE Datei fuer Dienst und Oberflaeche. */
function sp_modelle()
{
    static $t = null;
    if ($t !== null) { return $t; }
    $p = sp_paths();
    foreach (array($p['modelle'], dirname(dirname(__DIR__)) . '/templates/modelle.json') as $kand) {
        $d = sp_json_lesen($kand);
        if (!empty($d['stufen'])) { $t = $d; return $t; }
    }
    $t = array('stufen' => array(), 'dienste' => array());
    return $t;
}

function sp_token_erzeugen($laenge = 24)
{
    $zeichen = 'abcdefghijkmnpqrstuvwxyz23456789';
    $t = '';
    for ($i = 0; $i < $laenge; $i++) { $t .= $zeichen[random_int(0, strlen($zeichen) - 1)]; }
    return $t;
}

function sp_token()
{
    $cfg = sp_config();
    if (trim((string) $cfg['aktionstoken']) === '') {
        $cfg['aktionstoken'] = sp_token_erzeugen();
        sp_config_speichern($cfg);
    }
    return (string) $cfg['aktionstoken'];
}

/* ---------------- Zwischenspeicher ---------------- */

function sp_loxone()   { return sp_json_lesen(sp_paths()['datadir'] . '/loxone.json'); }
function sp_verlauf()
{
    $d = sp_json_lesen(sp_paths()['datadir'] . '/verlauf.json');
    return isset($d['saetze']) && is_array($d['saetze']) ? $d['saetze'] : array();
}
function sp_satelliten()
{
    $l = sp_loxone();
    return isset($l['satelliten']) && is_array($l['satelliten']) ? $l['satelliten'] : array();
}
function sp_alter()
{
    $l = sp_loxone();
    return isset($l['ts']) ? max(0, time() - (int) $l['ts']) : -1;
}

/* ---------------- Protokollierung ---------------- */

function sp_log($text)
{
    $p = sp_paths();
    if (!is_dir($p['logdir'])) {
        @mkdir($p['logdir'], 0775, true);
    }
    clearstatcache(true, $p['log']);
    if (is_file($p['log']) && filesize($p['log']) > 512000) {
        // Rotation: die letzten 400 Zeilen behalten. sp_log_ende liefert sie
        // neueste zuerst - zum Zurueckschreiben wieder umdrehen.
        $rest = array_reverse(sp_log_ende($p['log'], 400));
        @file_put_contents($p['log'], implode("\n", $rest) . "\n");
    }
    @file_put_contents($p['log'], '[' . date('Y-m-d H:i:s') . '] ' . $text . "\n", FILE_APPEND);
}

/* ---------------- Dienst ---------------- */

/**
 * Ist das eine brauchbare http-Adresse? Platzhalter in geschweiften
 * Klammern sind ausdruecklich erlaubt.
 *
 * WARUM NICHT filter_var(FILTER_VALIDATE_URL), wie oft empfohlen: beide
 * Felder dieses Plugins arbeiten mit Platzhaltern. Die Miniserver-Adresse
 * kennt {ziel}, {aktion}, {wert}; die TTS-Vorlage {ip}, {port}, {text},
 * {zones}, {vol} - der Platzhaltertext im Eingabefeld lautet woertlich
 *     http://{ip}:{port}/tts?text={text}&zone={zones}&vol={vol}
 * Nachgemessen in PHP 7.4 und 8.1:
 *
 *   Eingabe                                        bisher      filter_var
 *   http://192.168.1.10:7091/tts?text={text}       angenommen  angenommen
 *   http://{ip}:{port}/tts?text={text}             angenommen  ABGEWIESEN
 *   http://192.168.1.10/x<01>y (Steuerzeichen)     angenommen  ABGEWIESEN
 *   http://a                                       ABGEWIESEN  angenommen
 *
 * filter_var wuerde also genau die Vorlage abweisen, die die Oberflaeche
 * selbst vorschlaegt. Berechtigt ist der andere Teil des Einwands: \S
 * schliesst nur Leerraum aus, Steuerzeichen kommen durch. Die werden jetzt
 * ausdruecklich abgewiesen - und die Mindestlaenge bleibt, weil "http://a"
 * keine Adresse ist, die jemand gemeint haben kann.
 */
function sp_url_ok($url)
{
    $url = (string) $url;
    if (preg_match('/[\x00-\x1F\x7F]/', $url)) {
        return false;   // Steuerzeichen - auch die, die \S durchlaesst
    }
    return (bool) preg_match('#^https?://[^\s\x00-\x1F\x7F]{3,300}$#', $url);
}

/**
 * Steuerzeichen aus einer beliebig tiefen Struktur entfernen.
 *
 * Die Satzdatei wird als JSON eingegeben und ohne Ansehen der einzelnen
 * Werte gespeichert. Ein Steuerzeichen in einem Alias oder Thema landet
 * damit unbesehen in der saetze.json - und von dort in ein MQTT-Thema oder
 * in eine Antwort, die vorgelesen wird. Schluessel werden mitgereinigt:
 * sie werden zu MQTT-Themen.
 */
function sp_steuerzeichen_weg($wert)
{
    if (is_array($wert)) {
        $neu = array();
        foreach ($wert as $k => $v) {
            $k = is_string($k) ? preg_replace('/[\x00-\x1F\x7F]/u', '', $k) : $k;
            $neu[$k] = sp_steuerzeichen_weg($v);
        }
        return $neu;
    }
    if (is_string($wert)) {
        // Zeilenumbrueche werden zu Leerzeichen, nicht geloescht: ein
        // mehrzeiliger Antworttext soll lesbar bleiben, nicht zusammenkleben.
        return trim(preg_replace('/\s+/u', ' ',
            preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $wert)));
    }
    return $wert;
}

/**
 * Die letzten $anzahl Zeilen einer Datei, neueste zuerst.
 *
 * Bis 0.9.1 las die Oberflaeche das ganze Protokoll mit file() ein und warf
 * fast alles wieder weg; dasselbe tat sp_log() beim Kuerzen. Nachgemessen an
 * einer Datei an der Rotationsgrenze, PHP 7.4 und 8.1:
 *
 *   file() + array_reverse   0,3 ms   Spitze rund 1,4 MB
 *   exec("tail -n 400")      1,9 ms   Spitze rund  75 kB
 *   rueckwaerts mit fseek    0,05 ms  Spitze rund 125 kB
 *
 * Der Hinweis auf den Speicher war berechtigt, der vorgeschlagene Weg ueber
 * tail aber der langsamste: ein Prozessstart kostet mehr, als das Einlesen
 * je gespart hat. Und er braucht eine Shell, die man wieder absichern muss.
 */
function sp_log_ende($datei, $anzahl = 400, $block = 8192)
{
    $fp = @fopen($datei, 'rb');
    if ($fp === false) {
        return array();
    }
    fseek($fp, 0, SEEK_END);
    $pos = ftell($fp);
    $puffer = '';
    $zeilen = array();
    while ($pos > 0 && count($zeilen) <= $anzahl) {
        $lese = (int) min($block, $pos);
        $pos -= $lese;
        fseek($fp, $pos, SEEK_SET);
        $puffer = fread($fp, $lese) . $puffer;
        $zeilen = explode("\n", $puffer);
    }
    fclose($fp);
    $zeilen = array_values(array_filter(array_map('rtrim', $zeilen), 'strlen'));
    return array_slice(array_reverse($zeilen), 0, $anzahl);
}

/**
 * Laenge in ZEICHEN, nicht in Bytes.
 *
 * Bewusst ohne mb_strlen: mbstring ist eine eigene Erweiterung, dieses
 * Plugin bringt keine dpkg/apt-Liste mit und benutzt mbstring sonst
 * nirgends. Ein "Call to undefined function" waere hier ein toter Endpunkt -
 * Loxone bekaeme auf jeden Satz eine leere Antwort. PCRE mit /u kann das
 * ohne zusaetzliches Paket.
 */
function sp_zeichen($s)
{
    $n = preg_match_all('/./us', (string) $s);
    // Bei ungueltigem UTF-8 liefert preg_match_all false - dann lieber die
    // Bytezahl nehmen als gar nichts.
    return $n === false ? strlen((string) $s) : $n;
}

function sp_dienst_pid()
{
    $f = sp_paths()['datadir'] . '/dienst.pid';
    if (!is_file($f)) {
        return 0;
    }
    $pid = (int) trim((string) @file_get_contents($f));
    if ($pid <= 0 || !is_dir('/proc/' . $pid)) {
        return 0;
    }
    $cmd = (string) @file_get_contents('/proc/' . $pid . '/cmdline');
    return strpos($cmd, 'sprachsteuerung_dienst.py') !== false ? $pid : 0;
}

function sp_dienst_soll()
{
    return is_file(sp_paths()['datadir'] . '/soll_laufen') ? 1 : 0;
}

/** $befehl ist 'start', 'stop' oder 'restart'. Rueckgabe: array(ok, Ausgabe) */
function sp_dienst($befehl)
{
    if (!in_array($befehl, array('start', 'stop', 'restart'), true)) {
        return array(0, 'Unbekannter Befehl.');
    }
    $skript = sp_paths()['bindir'] . '/dienst.sh';
    if (!is_file($skript)) {
        return array(0, 'dienst.sh nicht gefunden: ' . $skript);
    }
    $ausgabe = array();
    $code = 0;
    @exec(escapeshellcmd($skript) . ' ' . escapeshellarg($befehl) . ' 2>&1', $ausgabe, $code);
    return array($code === 0 ? 1 : 0, implode("\n", $ausgabe));
}

/* ---------------- Befehlswarteschlange ----------------
 *
 * Sowohl der Miniserver-Endpunkt als auch der Reiter Test setzen Befehle ueber
 * diese eine Funktion ab. Zwei Kopien derselben Logik laufen zwangslaeufig
 * auseinander.
 *
 * Rueckgabe: array(ok, Meldung). ok = 1 erledigt, 0 abgelehnt,
 * 2 eingereiht, aber ohne Antwort in der Wartezeit - Ergebnis unbekannt.
 * Es wird nie ein Erfolg gemeldet, den niemand geprueft hat.
 */
/** Obergrenze fuer eine Wartezeit, die aus einer Web-Anfrage kommt. */
define('SP_WARTEN_WEB', 12);

function sp_befehl_absetzen($befehl, $wartezeit = null)
{
    $p = sp_paths();
    $cfg = sp_config();
    if ($wartezeit === null) {
        $wartezeit = (int) $cfg['wartezeit'];
    }
    /* Bis 0.9.1 war hier bei 20 s gedeckelt. Der Reiter Test uebergab 30 bzw.
     * 60 - beides wurde also ohnehin auf 20 gestutzt, die Zahlen im Aufruf
     * waren irrefuehrend. 20 Sekunden sind aber immer noch zu lang: ein
     * Webserver bricht die Anfrage typischerweise nach 15 bis 30 Sekunden mit
     * 504 ab, und der Miniserver wartet ebenfalls nicht beliebig.
     *
     * Der Dienst arbeitet den Befehl trotzdem zu Ende - die Warteschlange
     * liegt im Dateisystem, nicht in dieser Anfrage. Wer laenger warten will,
     * sieht das Ergebnis im Protokoll. */
    $wartezeit = max(0, min(SP_WARTEN_WEB, (int) $wartezeit));

    /* Laeuft der Dienst ueberhaupt? Ohne diese Frage wartete die Anfrage die
     * volle Zeit auf eine Antwort, die niemand schreiben kann - gemessen
     * 20,06 s, wo 0,00 s genuegen. Es wird KEIN Befehl eingereiht: er laege
     * sonst herum, bis der Dienst irgendwann startet, und wuerde dann
     * verspaetet ausgefuehrt. Bei einer Sprachausgabe ist das kein
     * Schoenheitsfehler, sondern eine Stimme aus dem Nichts. */
    if (sp_dienst_pid() === 0) {
        return array(0, 'Der Dienst laeuft nicht - der Befehl wurde nicht eingereiht. '
                      . 'Im Reiter Dienste starten.');
    }

    $ordner = $p['datadir'] . '/befehle';
    if (!is_dir($ordner) && !@mkdir($ordner, 0775, true) && !is_dir($ordner)) {
        return array(0, 'Der Ordner fuer die Warteschlange liess sich nicht anlegen: ' . $ordner);
    }
    $kennung = bin2hex(random_bytes(8));
    $datei = $ordner . '/' . $kennung . '.json';
    $tmp = $datei . '.tmp';
    /* json_encode gibt bei ungueltigem UTF-8 false zurueck. file_put_contents
     * macht daraus eine leere Zeichenkette, schreibt null Byte und meldet
     * das als Erfolg - der Rueckgabewert ist 0, nicht false, die Pruefung
     * unten greift also nicht. In der Warteschlange laege dann eine leere
     * Befehlsdatei, die der Dienst nicht deuten kann. Deshalb zuerst
     * kodieren und den Rueckgabewert ansehen. Dieselbe Vorsicht wie in
     * sp_json_schreiben(). */
    $sp_js = json_encode($befehl);
    if ($sp_js === false) {
        return array(0, 'Der Befehl liess sich nicht als JSON darstellen (ungueltiges UTF-8).');
    }
    if (@file_put_contents($tmp, $sp_js) !== strlen($sp_js) || !@rename($tmp, $datei)) {
        @unlink($tmp);
        return array(0, 'Der Befehl liess sich nicht ablegen: ' . $datei);
    }
    $antwort = $p['datadir'] . '/antworten/' . $kennung . '.json';
    for ($i = 0; $i < $wartezeit * 10; $i++) {
        if (is_file($antwort)) {
            $a = sp_json_lesen($antwort);
            /* Gelesen ist erledigt. Bis 0.9.1 blieb die Datei liegen; der
             * Dienst raeumt sie zwar nach 900 s weg, bis dahin sammeln sich
             * bei einem gespraechigen Loxone aber hunderte kleiner Dateien
             * im Ordner an - und jedes Aufraeumen muss sie alle durchgehen. */
            @unlink($antwort);
            return array((int) (isset($a['ok']) ? $a['ok'] : 0),
                         (string) (isset($a['meldung']) ? $a['meldung'] : ''));
        }
        usleep(100000);
    }
    return array(2, 'Eingereiht, aber der Dienst hat innerhalb von ' . $wartezeit . ' s nicht geantwortet. '
                  . 'Er arbeitet den Befehl zu Ende - das Ergebnis steht im Protokoll.');
}

/* ---------------- MQTT-Gateway des LoxBerry ----------------
 *
 * Das MQTT-Gateway ist seit LoxBerry 3 Bestandteil des Systems, kein Plugin.
 * Es wird nicht nachinstalliert, sondern unter System -> MQTT Gateway
 * eingeschaltet.
 *
 * Mqtt.Brokerhost ist ab Werk auf 'localhost' gesetzt. Eine Pruefung darauf
 * beantwortet also NICHT die Frage, ob Nachrichten ankommen koennen -
 * massgeblich ist Gatewayautostart.
 */
function sp_mqtt_zustand()
{
    $p = sp_paths();
    $leer = array('gefunden' => 0, 'autostart' => 0, 'udpport' => 0, 'broker' => '',
                  'brokerport' => '', 'user' => '', 'pw' => '', 'lokal' => 0);
    if ($p['home'] === '') {
        return $leer;
    }
    $gen = sp_json_lesen($p['home'] . '/config/system/general.json');
    $m = array();
    if (isset($gen['Mqtt']) && is_array($gen['Mqtt'])) {
        $m = $gen['Mqtt'];
    } elseif (isset($gen['mqtt']) && is_array($gen['mqtt'])) {
        $m = $gen['mqtt'];
    }
    if (!$m) {
        return $leer;
    }
    $hol = function ($gross, $klein) use ($m) {
        if (isset($m[$gross])) {
            return $m[$gross];
        }
        return isset($m[$klein]) ? $m[$klein] : '';
    };
    return array(
        'gefunden'   => 1,
        'autostart'  => in_array((string) $hol('Gatewayautostart', 'gatewayautostart'), array('1', 'true'), true) ? 1 : 0,
        'udpport'    => (int) $hol('Udpinport', 'udpinport'),
        'broker'     => (string) $hol('Brokerhost', 'brokerhost'),
        'brokerport' => (string) $hol('Brokerport', 'brokerport'),
        'user'       => (string) $hol('Brokeruser', 'brokeruser'),
        'pw'         => (string) $hol('Brokerpass', 'brokerpass'),
        'lokal'      => in_array((string) $hol('Uselocalbroker', 'uselocalbroker'), array('1', 'true'), true) ? 1 : 0,
    );
}

/* ==================================================================
 * Loxone-Vorlagen
 *
 * Nachbau der Bausteine aus LoxBerry::LoxoneTemplateBuilder; das Modul gibt es
 * nur in Perl. Attributreihenfolge, CRLF als Zeilenende und der Tabulator vor
 * den Kindelementen entsprechen dem Original. Wortgleich uebernommen aus
 * LoxBerry-Plugin-APC-UPS-1.0.0 (ap_xml_virtual_in_http) - nicht neu
 * geschrieben, weil die Fassung dort geprueft ist.
 * ================================================================== */

function sp_x($s)
{
    return htmlspecialchars((string) $s, ENT_QUOTES | ENT_XML1, 'UTF-8');
}

function sp_xml_virtual_in_http($kopf, $cmds)
{
    $crlf = "\r\n";
    $o = '<?xml version="1.0" encoding="utf-8"?>' . $crlf;
    $o .= '<VirtualInHttp ';
    $o .= 'Title="' . sp_x($kopf['title']) . '" ';
    $o .= 'Comment="' . sp_x(isset($kopf['comment']) ? $kopf['comment'] : '') . '" ';
    $o .= 'Address="' . sp_x(isset($kopf['address']) ? $kopf['address'] : '') . '" ';
    $o .= 'PollingTime="' . sp_x(isset($kopf['polling']) ? $kopf['polling'] : '60') . '"';
    $o .= '>' . $crlf;
    foreach ($cmds as $c) {
        $o .= "\t" . '<VirtualInHttpCmd ';
        $o .= 'Title="' . sp_x($c['title']) . '" ';
        $o .= 'Comment="' . sp_x(isset($c['comment']) ? $c['comment'] : '') . '" ';
        $o .= 'Check="' . sp_x(isset($c['check']) ? $c['check'] : ' ') . '" ';
        $o .= 'Signed="true" ';
        $o .= 'Analog="true" ';
        $o .= 'SourceValLow="0" ';
        $o .= 'DestValLow="0" ';
        $o .= 'SourceValHigh="100" ';
        $o .= 'DestValHigh="100" ';
        $o .= 'DefVal="0" ';
        $o .= 'MinVal="-2147483647" ';
        $o .= 'MaxVal="2147483647"';
        $o .= '/>' . $crlf;
    }
    $o .= '</VirtualInHttp>' . $crlf;
    return $o;
}

/* ==================================================================
 * Sprache (Pflicht: Deutsch und Englisch)
 *
 * Englisch ist die Rueckfallebene, nicht Deutsch: wer eine dritte Sprache
 * eingestellt hat, versteht eher Englisch. Deshalb muss language_en.ini immer
 * vollstaendig sein.
 *
 * Die Funktion setzt kein sp_paths() voraus, damit derselbe Block in jedes
 * Plugin passt. Der Pfad wird zweistufig gesucht:
 *   installiert: <home>/templates/plugins/<ordner>/lang
 *   Archiv:      <pluginwurzel>/templates/lang
 * ================================================================== */

function sp_sprache()
{
    $sprache = 'de';
    if (class_exists('LBSystem', false) && method_exists('LBSystem', 'lblanguage')) {
        $sprache = LBSystem::lblanguage();
    } elseif (getenv('LBLANG')) {
        $sprache = getenv('LBLANG');
    }
    $sprache = strtolower(substr((string) $sprache, 0, 2));
    return in_array($sprache, array('de', 'en'), true) ? $sprache : 'en';
}

function sp_t($schluessel)
{
    static $texte = null;
    if ($texte === null) {
        $home = getenv('LBHOMEDIR');
        if (!$home || !is_dir($home)) {
            foreach (array(lb_wurzel_ermitteln(), '/home/loxberry/loxberry') as $k) {
                if (is_dir($k)) {
                    $home = $k;
                    break;
                }
            }
        }
        $ordner = basename(dirname(__FILE__));
        $pfad = $home . '/templates/plugins/' . $ordner . '/lang';
        if (!is_dir($pfad)) {
            $pfad = dirname(dirname(dirname(__FILE__))) . '/templates/lang';
        }
        $texte = @parse_ini_file($pfad . '/language_' . sp_sprache() . '.ini', true, INI_SCANNER_RAW);
        if (!is_array($texte)) {
            $texte = array();
        }
        $rueck = @parse_ini_file($pfad . '/language_en.ini', true, INI_SCANNER_RAW);
        if (is_array($rueck)) {
            $texte = array_replace_recursive($rueck, $texte);
        }
        // INI_SCANNER_RAW liefert die Werte samt der Anfuehrungszeichen
        // zurueck, in die sie in der Datei stehen muessen. Die gehoeren nicht
        // in die Ausgabe.
        foreach ($texte as $ab => $paare) {
            if (!is_array($paare)) {
                continue;
            }
            foreach ($paare as $s => $w) {
                $texte[$ab][$s] = trim((string) $w, '"');
            }
        }
    }
    $teile = array_pad(explode('.', $schluessel, 2), 2, '');
    return isset($texte[$teile[0]][$teile[1]]) ? $texte[$teile[0]][$teile[1]] : $schluessel;
}


/* ==================================================================
 * Die Sprachdienste in Containern
 *
 * Vier Container, alle nur im Heimnetz: Spracherkennung (Whisper),
 * Sprachausgabe (Piper), Wortwecker (openWakeWord) und wahlweise das
 * Sprachmodell. Die Aufrufzeilen folgen den Anleitungen der jeweiligen
 * Projekte. Der Modellordner wird NIE mitgeloescht - darin liegen Gigabyte,
 * die sonst erneut aus dem Netz kommen muessten.
 * ================================================================== */

function sp_docker_da()
{
    $a = array();
    @exec('command -v docker 2>/dev/null', $a);
    return count($a) > 0 ? 1 : 0;
}

function sp_docker($argumente)
{
    if (!sp_docker_da()) {
        return array(0, 'Docker ist auf diesem LoxBerry nicht installiert.');
    }
    $ausgabe = array();
    $code = 0;
    @exec('docker ' . $argumente . ' 2>&1', $ausgabe, $code);
    return array($code === 0 ? 1 : 0, implode("\n", $ausgabe));
}

/** Die vier Dienste mit Containernamen. */
function sp_dienste()
{
    return array('whisper', 'piper', 'wakeword', 'llm');
}

function sp_container_name($dienst)
{
    $dienst = preg_replace('/[^a-z]/', '', (string) $dienst);
    return 'sprachsteuerung-' . ($dienst !== '' ? $dienst : 'unbekannt');
}

/**
 * Adresse und Port eines Dienstes aus der Konfiguration.
 *
 * Die Schluessel heissen nicht durchgaengig wie die Dienste: der Wortwecker
 * heisst im Dienst 'wakeword', in der Konfiguration aber 'wake_host' und
 * 'wake_port' (so steht es in VORGABEN in bin/sprachsteuerung_dienst.py).
 * Diese Abbildung steht genau hier und nirgends sonst.
 */
function sp_dienst_ziel($dienst, $cfg = null)
{
    if ($cfg === null) { $cfg = sp_config(); }
    $schluessel = $dienst === 'wakeword' ? 'wake' : $dienst;
    $tab = sp_modelle();
    $vorgabe_port = isset($tab['dienste'][$dienst]['port'])
                  ? (int) $tab['dienste'][$dienst]['port'] : 0;
    $host = isset($cfg[$schluessel . '_host']) ? trim((string) $cfg[$schluessel . '_host']) : '';
    $port = isset($cfg[$schluessel . '_port']) ? (int) $cfg[$schluessel . '_port'] : 0;
    if ($host === '') { $host = '127.0.0.1'; }
    if ($port < 1 || $port > 65535) { $port = $vorgabe_port; }
    return array($host, $port);
}

/**
 * Laeuft der Dienst auf DIESER Maschine?
 *
 * Nur dann darf das Plugin ihn mit Docker anfassen. Steht dort die Adresse
 * eines anderen Rechners, wuerde jeder Docker-Befehl den FALSCHEN Rechner
 * treffen - naemlich den LoxBerry, auf dem es gar keinen solchen Container
 * gibt. Deshalb wird das geprueft und nicht angenommen.
 */
function sp_ist_lokal($host)
{
    $host = strtolower(trim((string) $host));
    if ($host === '') { return true; }
    return in_array($host, array('127.0.0.1', 'localhost', '::1', '0.0.0.0',
                                 'localhost.localdomain'), true);
}

/**
 * Antwortet an dieser Adresse etwas auf dem Port?
 *
 * NICHT sp_erreichbar() nennen: so heisst bereits eine Funktion in
 * webfrontend/htmlauth/sp_test.php, die dasselbe misst, aber
 * array(ok, Fehlertext) zurueckgibt. Die liegt im angemeldeten Bereich und
 * steht dieser Datei nicht zur Verfuegung; gleicher Name waere ein
 * "Cannot redeclare" gewesen, sobald beide geladen sind.
 */
function sp_port_offen($host, $port, $timeout = 2.0)
{
    $host = trim((string) $host);
    $port = (int) $port;
    if ($host === '' || $port < 1 || $port > 65535) { return false; }
    $fehlernr = 0;
    $fehlertext = '';
    $verbindung = @fsockopen($host, $port, $fehlernr, $fehlertext, $timeout);
    if ($verbindung === false) { return false; }
    fclose($verbindung);
    return true;
}

/**
 * 'laeuft', 'gestoppt', 'fehlt', 'kein_docker' fuer eigene Container;
 * 'extern' oder 'extern_weg' fuer Dienste auf einem anderen Rechner.
 *
 * Bei einem ausgelagerten Dienst ist die Frage nach dem Container sinnlos -
 * es gibt hier keinen. Die einzige ehrliche Aussage ist, ob unter der
 * eingetragenen Adresse jemand antwortet.
 */
function sp_container_zustand($dienst, $cfg = null)
{
    list($host, $port) = sp_dienst_ziel($dienst, $cfg);
    if (!sp_ist_lokal($host)) {
        return sp_port_offen($host, $port) ? 'extern' : 'extern_weg';
    }
    if (!sp_docker_da()) { return 'kein_docker'; }
    list($ok, $aus) = sp_docker('inspect -f {{.State.Running}} '
                                . escapeshellarg(sp_container_name($dienst)));
    if (!$ok) { return 'fehlt'; }
    return trim($aus) === 'true' ? 'laeuft' : 'gestoppt';
}

/**
 * Die Aufrufzeile fuer einen Dienst - auch fuer die Anzeige.
 *
 * Absichtlich OHNE --network=host: diese Dienste brauchen kein Wirtsnetz,
 * eine Portweiterleitung genuegt. Das haelt sie vom uebrigen Netz fern.
 *
 * $fuer_extern = true liefert dieselbe Zeile zum Mitnehmen auf einen ANDEREN
 * Rechner. Zwei Unterschiede sind noetig: der Port muss ans Netz gebunden
 * werden (sonst kaeme der LoxBerry nicht heran), und der Modellordner liegt
 * dort natuerlich woanders - deshalb ein neutraler Pfad statt des hiesigen.
 * Diese Zeile wird NICHT ausgefuehrt, sie wird nur angezeigt.
 */
function sp_container_befehl($dienst, $cfg = null, $emp = null, $fuer_extern = false)
{
    if ($cfg === null) { $cfg = sp_config(); }
    $p = sp_paths();
    $tab = sp_modelle();
    $d = isset($tab['dienste'][$dienst]) ? $tab['dienste'][$dienst] : null;
    if ($d === null) { return ''; }
    $modelle = $fuer_extern ? '/opt/sprachsteuerung/modelle' : $p['datadir'] . '/modelle';
    $name = sp_container_name($dienst);
    $port = (int) $d['port'];
    $abbild = (string) $d['abbild'];

    $zeile = 'run -d --name ' . escapeshellarg($name)
           . ' --restart=unless-stopped'
           . ' -p ' . ($fuer_extern ? '' : '127.0.0.1:') . $port . ':' . $port
           . ' -v ' . escapeshellarg($modelle . '/' . $dienst . ':/data');

    if ($dienst === 'whisper') {
        $modell = trim((string) $cfg['whisper_modell']);
        if ($modell === '' && $emp !== null) { $modell = (string) $emp['whisper']['modell']; }
        if ($modell === '') { $modell = 'base-int8'; }
        $zeile .= ' ' . escapeshellarg($abbild)
                . ' --model ' . escapeshellarg($modell)
                . ' --language ' . escapeshellarg((string) $cfg['sprache']);
    } elseif ($dienst === 'piper') {
        $stimme = trim((string) $cfg['piper_stimme']);
        if ($stimme === '' && $emp !== null) { $stimme = (string) $emp['piper']['stimme']; }
        if ($stimme === '') { $stimme = 'de_DE-thorsten-low'; }
        $zeile .= ' ' . escapeshellarg($abbild) . ' --voice ' . escapeshellarg($stimme);
    } elseif ($dienst === 'wakeword') {
        $wort = trim((string) $cfg['wakeword']);
        if ($wort === '') { $wort = 'ok_nabu'; }
        $zeile .= ' ' . escapeshellarg($abbild)
                . ' --preload-model ' . escapeshellarg($wort);
    } elseif ($dienst === 'llm') {
        $modell = trim((string) $cfg['llm_modell']);
        if ($modell === '' && $emp !== null && !empty($emp['llm'])) {
            $modell = (string) $emp['llm']['quelle'];
        }
        if ($modell === '') { return ''; }
        // llama.cpp laedt das Modell selbst von HuggingFace, wenn -hf gesetzt ist.
        $zeile .= ' ' . escapeshellarg($abbild)
                . ' -hf ' . escapeshellarg($modell)
                . ' --host 0.0.0.0 --port ' . $port . ' -c 2048';
    }
    return $zeile;
}

/** $was ist 'anlegen', 'start', 'stop', 'restart', 'entfernen' oder 'holen'. */
function sp_container($dienst, $was)
{
    if (!in_array($dienst, sp_dienste(), true)) {
        return array(0, 'Unbekannter Dienst.');
    }
    // Ausgelagerter Dienst: abweisen statt den falschen Rechner anzufassen.
    //
    // Ohne diese Pruefung liefe der Docker-Befehl auf dem LoxBerry, wo es
    // keinen solchen Container gibt. 'anlegen' wuerde dort einen ZWEITEN
    // Dienst starten, den niemand benutzt, und 'stop' meldete einen Fehler,
    // den man auf den entfernten Rechner beziehen wuerde. Beides ist
    // schlimmer als eine klare Absage (Hausregel: Eingaben abweisen, nicht
    // stillschweigend zurechtbiegen).
    list($host, $port) = sp_dienst_ziel($dienst);
    if (!sp_ist_lokal($host)) {
        return array(0, 'Dieser Dienst ist auf ' . $host . ':' . $port
                      . ' ausgelagert. Container dort verwalten, nicht hier.');
    }
    $p = sp_paths();
    $name = sp_container_name($dienst);
    $tab = sp_modelle();
    switch ($was) {
        case 'holen':
            $abbild = isset($tab['dienste'][$dienst]['abbild']) ? $tab['dienste'][$dienst]['abbild'] : '';
            return $abbild === '' ? array(0, 'Kein Abbild bekannt.')
                                  : sp_docker('pull ' . escapeshellarg($abbild));
        case 'anlegen':
            $ordner = $p['datadir'] . '/modelle/' . $dienst;
            if (!is_dir($ordner)) { @mkdir($ordner, 0775, true); }
            if (sp_container_zustand($dienst) !== 'fehlt') {
                return array(0, 'Es gibt bereits einen Container ' . $name
                              . '. Erst entfernen, dann neu anlegen.');
            }
            $befehl = sp_container_befehl($dienst);
            if ($befehl === '') {
                return array(0, 'Fuer diesen Dienst ist kein Modell eingestellt.');
            }
            return sp_docker($befehl);
        case 'start':     return sp_docker('start ' . escapeshellarg($name));
        case 'stop':      return sp_docker('stop ' . escapeshellarg($name));
        case 'restart':   return sp_docker('restart ' . escapeshellarg($name));
        case 'entfernen':
            // Nur der Container, NIE der Modellordner.
            return sp_docker('rm -f ' . escapeshellarg($name));
    }
    return array(0, 'Unbekannter Containerbefehl.');
}

function sp_container_log($dienst, $zeilen = 200)
{
    if (in_array($dienst, sp_dienste(), true)) {
        list($host, $port) = sp_dienst_ziel($dienst);
        if (!sp_ist_lokal($host)) {
            return 'Dieser Dienst laeuft auf ' . $host . ':' . $port . '.' . "\n"
                 . 'Sein Protokoll steht dort - hier gibt es keinen Container dazu.';
        }
    }
    list($ok, $aus) = sp_docker('logs --tail ' . (int) $zeilen . ' '
                                . escapeshellarg(sp_container_name($dienst)));
    // Programmprotokolle vor dem Auswerten von Farbcodes befreien.
    return preg_replace('/\x1B\[[0-9;]*[A-Za-z]/', '', (string) $aus);
}

/* ---------------- Hardware und Empfehlung ---------------- */

function sp_hardware($messen = false)
{
    $p = sp_paths();
    $py = is_file($p['bindir'] . '/venv/bin/python3') ? $p['bindir'] . '/venv/bin/python3' : 'python3';
    $skript = $p['bindir'] . '/hardware.py';
    if (!is_file($skript)) { return array(); }
    $ausgabe = array();
    @exec(escapeshellcmd($py) . ' ' . escapeshellarg($skript)
          . ($messen ? ' --messen' : '') . ' 2>/dev/null', $ausgabe);
    $d = json_decode(implode("\n", $ausgabe), true);
    return is_array($d) ? $d : array();
}

function sp_selbsttest_ausgabe()
{
    $p = sp_paths();
    $py = $p['bindir'] . '/venv/bin/python3';
    $skript = $p['bindir'] . '/sprachsteuerung_dienst.py';
    if (!is_file($py) || !is_file($skript)) {
        return "[FEHL] Die virtuelle Python-Umgebung oder sprachsteuerung_dienst.py fehlt.\n"
             . '       Erwartet: ' . $py . "\n                 " . $skript . "\n"
             . '       Abhilfe: Plugin neu installieren.';
    }
    $ausgabe = array();
    @exec(escapeshellcmd($py) . ' ' . escapeshellarg($skript) . ' --selbsttest 2>&1', $ausgabe);
    return implode("\n", $ausgabe);
}

/** Die Werte des Status-Endpunkts: Einheit und Sprachschluessel. */
function sp_status_felder()
{
    return array(
        'OK'        => array('',  'SP_FELD.OK'),
        'SATELLITEN'=> array('',  'SP_FELD.SATELLITEN'),
        'BEREIT'    => array('',  'SP_FELD.BEREIT'),
        'ALTER'     => array('s', 'SP_FELD.ALTER'),
    );
}

/** Vorlage fuer den Import in Loxone Config. Rueckgabe: array(name, inhalt) */
function sp_vorlage()
{
    $p = sp_paths();
    $host = isset($_SERVER['HTTP_HOST']) && $_SERVER['HTTP_HOST'] !== ''
        ? preg_replace('/[^A-Za-z0-9\.\-:]/', '', (string) $_SERVER['HTTP_HOST'])
        : (gethostname() ?: 'loxberry');
    $token = sp_token();
    $cmds = array();
    foreach (sp_status_felder() as $feld => $info) {
        $cmds[] = array(
            'title'   => 'SPRACHSTEUERUNG_' . $feld,
            'comment' => trim(strip_tags(html_entity_decode(sp_t($info[1]), ENT_QUOTES, 'UTF-8')))
                       . ($info[0] !== '' ? ' [' . $info[0] . ']' : ''),
            'check'   => '\i' . $feld . '=\i\v',
        );
    }
    return array('sprachsteuerung_status.xml', sp_xml_virtual_in_http(array(
        'title'   => 'Sprachsteuerung lokal',
        'address' => 'http://' . $host . '/plugins/' . $p['plugin']
                   . '/index.php?token=' . $token . '&aktion=status',
        'polling' => '60',
        'comment' => 'Erzeugt vom LoxBerry-Plugin Sprachsteuerung lokal (' . date('d.m.Y') . ')',
    ), $cmds));
}
