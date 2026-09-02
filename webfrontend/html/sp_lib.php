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
            'mitschnitt' => $home . '/log/plugins/' . $dir . '/mitschnitt.log',
            'modelle'   => $home . '/templates/plugins/' . $dir . '/modelle.json',
            'vorgaben'  => $home . '/templates/plugins/' . $dir . '/vorgaben.json',
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
            'mitschnitt' => $basis . '/log/mitschnitt.log',
            'modelle'   => $basis . '/templates/modelle.json',
            'vorgaben'  => $basis . '/templates/vorgaben.json',
        );
    }
    return $p;
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
    // Die PID im Namen: der Dienst schreibt dieselben Dateien und benutzte
    // bis 0.10.1 denselben .tmp-Namen. Zwei Schreiber, ein Name, keine Sperre.
    $tmp = $pfad . '.tmp.' . getmypid();
    $json = json_encode($daten, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false) { return false; }
    // Rechte VOR dem Inhalt: sonst steht die Miniserver-Anmeldung einen
    // Augenblick lang mit den Vorgaberechten auf der Platte.
    if (@file_put_contents($tmp, '') === false) { @unlink($tmp); return false; }
    if ($rechte !== null) { @chmod($tmp, $rechte); }
    $n = @file_put_contents($tmp, $json);
    // Eine Kurzschreibung ist ein Fehler, kein Erfolg - eine halbe
    // JSON-Datei liest sich hinterher als leere Konfiguration.
    if ($n === false || $n !== strlen($json)) { @unlink($tmp); return false; }
    if (!@rename($tmp, $pfad)) { @unlink($tmp); return false; }
    return true;
}

/* ==================================================================
 * Vorgaben - EINE Datei fuer beide Sprachen
 *
 * Bis 0.9.11 stand die Liste zweimal: hier als sp_vorgaben() und als VORGABEN
 * in bin/sprachsteuerung_dienst.py. Die Oberflaeche kannte 22 Schluessel, der
 * Dienst 19 - die drei Modellnamen fehlten drueben. Solange die nur die
 * Oberflaeche braucht, faellt das nicht auf; beim naechsten Schluessel, den
 * beide lesen, faellt es teuer auf (bei Gardena bedeutete ein fehlender
 * Schluessel in der Oberflaeche 'an' und im Dienst 'aus').
 *
 * Ueber die Sprachgrenze hinweg gibt es keine gemeinsame Funktion - also eine
 * gemeinsame DATEI. Der Reiter Test zaehlt beide Seiten gegeneinander.
 * ================================================================== */

function sp_vorgabendatei()
{
    static $d = null;
    if ($d !== null) { return $d; }
    $p = sp_paths();
    foreach (array($p['vorgaben'], dirname(dirname(__DIR__)) . '/templates/vorgaben.json') as $kand) {
        $x = sp_json_lesen($kand);
        if (!empty($x['vorgaben'])) { $d = $x; return $d; }
    }
    $d = array('vorgaben' => array(), 'grenzen' => array(),
               'auswahl' => array(), 'wakewords' => array());
    return $d;
}

/** Voreinstellungen. Quelle: templates/vorgaben.json - siehe oben. */
function sp_vorgaben()
{
    $d = sp_vorgabendatei();
    return isset($d['vorgaben']) && is_array($d['vorgaben']) ? $d['vorgaben'] : array();
}

function sp_grenzen()
{
    $d = sp_vorgabendatei();
    return isset($d['grenzen']) && is_array($d['grenzen']) ? $d['grenzen'] : array();
}

function sp_auswahl($name)
{
    $d = sp_vorgabendatei();
    return isset($d['auswahl'][$name]) && is_array($d['auswahl'][$name])
        ? $d['auswahl'][$name] : array();
}

function sp_wakewords()
{
    $d = sp_vorgabendatei();
    return isset($d['wakewords']) && is_array($d['wakewords']) ? $d['wakewords'] : array();
}

/**
 * Die Konfiguration lesen.
 *
 * $erzeugen = false schaltet JEDEN Schreibvorgang ab. Der unangemeldete
 * Endpunkt ruft so auf: wer sich nicht ausweisen kann, legt nichts an - auch
 * nichts Harmloses. Bei EVCC hinterliess ein einziger, korrekt mit 403
 * abgewiesener Aufruf eine frisch erzeugte Konfiguration samt Token und
 * Zweitschrift.
 */
function sp_config($erzeugen = true)
{
    $p = sp_paths();
    $roh = is_file($p['config']) ? trim((string) @file_get_contents($p['config'])) : '';
    if ($erzeugen && ($roh === '' || $roh === '{}') && is_file($p['sicherung'])) {
        @mkdir($p['configdir'], 0775, true);
        @copy($p['sicherung'], $p['config']);
    }
    $vor = sp_vorgaben();
    $cfg = array_merge($vor, sp_json_lesen($p['config']));

    // array_merge ersetzt einen verschachtelten Block vollstaendig. Steht in
    // der gespeicherten Datei nur ein Teil des tts-Blocks - etwa allein die
    // Adresse -, fehlten sonst alle uebrigen Felder. Deshalb wird er auf die
    // Vorgaben GELEGT. Dieselbe Regel gilt im Dienst (config() in
    // sprachsteuerung_dienst.py); beide Seiten muessen gleich rechnen.
    $tts = is_array(isset($cfg['tts']) ? $cfg['tts'] : null)
         ? array_merge($vor['tts'], $cfg['tts']) : $vor['tts'];
    if (!in_array($tts['mode'], sp_auswahl('tts_mode'), true)) {
        $tts['mode'] = 'musicserver';
    }
    $tts['ip'] = trim((string) $tts['ip']);
    $tts['port'] = max(1, min(65535, (int) $tts['port']));
    $tts['volume'] = max(1, min(100, (int) $tts['volume']));
    $tts['zones'] = trim((string) $tts['zones']) !== '' ? trim((string) $tts['zones']) : '1';
    $tts['lang'] = preg_replace('/[^a-z]/', '', strtolower((string) $tts['lang'])) ?: 'de';
    $tts['template'] = trim((string) $tts['template']);
    $tts['stimme'] = trim((string) (isset($tts['stimme']) ? $tts['stimme'] : ''));
    $cfg['tts'] = $tts;

    $ruhe = is_array(isset($cfg['ruhe']) ? $cfg['ruhe'] : null)
          ? array_merge($vor['ruhe'], $cfg['ruhe']) : $vor['ruhe'];
    $ruhe['ein'] = !empty($ruhe['ein']) ? 1 : 0;
    foreach (array('von', 'bis') as $f) {
        if (!preg_match('/^\d{1,2}:\d{2}$/', (string) $ruhe[$f])) {
            $ruhe[$f] = $vor['ruhe'][$f];
        }
    }
    $cfg['ruhe'] = $ruhe;

    foreach (sp_grenzen() as $feld => $g) {
        if (!isset($cfg[$feld])) { continue; }
        $cfg[$feld] = max((int) $g[0], min((int) $g[1], (int) $cfg[$feld]));
    }

    if (!in_array($cfg['antwortweg'], sp_auswahl('antwortweg'), true)) {
        $cfg['antwortweg'] = 'beide';
    }
    return $cfg;
}

/**
 * Fehlende Schluessel EINMAL mit ihrer Vorgabe in die Datei schreiben.
 *
 * Ergaenzen heisst: beim Lesen tritt fuer einen fehlenden Schluessel seine
 * Vorgabe ein. Die Datei bleibt dann lueckenhaft, und "fehlt" ist von "steht
 * auf dem Vorgabewert" nicht mehr zu unterscheiden. Vervollstaendigen heisst:
 * der fehlende Schluessel wird geschrieben. Danach heisst "fehlt" nie mehr
 * "gilt als 1".
 *
 * Geprueft wird mit array_key_exists(), NICHT mit isset(): isset() haelt einen
 * leeren Wert fuer nicht vorhanden und wuerde eine bewusst geleerte Angabe bei
 * jedem Lauf zurueckschreiben.
 *
 * Rueckgabe: welche Schluessel gefehlt haben.
 */
function sp_cfg_vervollstaendigen()
{
    $p = sp_paths();
    $roh = sp_json_lesen($p['config']);
    $fehlten = array();
    foreach (sp_vorgaben() as $k => $v) {
        if (!array_key_exists($k, $roh)) { $roh[$k] = $v; $fehlten[] = $k; }
    }
    if ($fehlten) {
        // Nicht bei jedem Lauf schreiben: sonst ist das Protokoll voll und die
        // Datei aendert sich ohne Anlass.
        sp_json_schreiben($p['config'], $roh, 0600);
        @copy($p['config'], $p['sicherung']);
        @chmod($p['sicherung'], 0600);
        sp_log('Konfiguration ergaenzt: ' . implode(', ', $fehlten));
    }
    return $fehlten;
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

/**
 * Die Satzdatei lesen - mit Rueckfall auf die Zweitschrift.
 *
 * Bis 0.9.11 stand hier nur sp_json_lesen(). sp_config() greift bei leerer
 * oder fehlender Datei auf die Zweitschrift zurueck, sp_saetze() nicht -
 * obwohl sp_saetze_speichern() sie brav anlegt. Die Satzdatei ist der
 * eigentliche Wert dieses Plugins (Regeln, Ziele, Aliasnamen, Themen) und war
 * damit die EINZIGE Datei ohne Rueckfallebene.
 */
function sp_saetze($erzeugen = true)
{
    $p = sp_paths();
    $roh = is_file($p['saetze']) ? trim((string) @file_get_contents($p['saetze'])) : '';
    if ($erzeugen && ($roh === '' || $roh === '{}') && is_file($p['sicherung_saetze'])) {
        @mkdir($p['configdir'], 0775, true);
        @copy($p['sicherung_saetze'], $p['saetze']);
        sp_log('Satzdatei war leer - aus der Zweitschrift wiederhergestellt.');
    }
    return sp_json_lesen($p['saetze']);
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

/**
 * Das Merkmal gegen fremde Formulare - abgeleitet, nicht gespeichert.
 *
 * htmlauth/ schuetzt gegen den unangemeldeten Aufruf, NICHT dagegen, dass der
 * Browser eines ANGEMELDETEN Bedieners ein Formular abschickt, das auf einer
 * fremden Seite steht: die HTTP-Basic-Anmeldung schickt er automatisch mit,
 * und SameSite greift nicht. Gemessen an Docker NG 1.2.3 wuerfelte ein POST
 * von einer beliebigen fremden Seite mit 'token_neu=1' das Merkwort neu -
 * danach bekamen saemtliche virtuellen Eingaenge im Miniserver HTTP 403, und
 * ueber 'log_leeren=1' liess sich gleich die Spur wegraeumen.
 *
 * Dieses Plugin hat genau diese beiden Knoepfe. Bis 0.9.11 hatte es das
 * Merkmal nicht.
 *
 * Abgeleitet statt gespeichert: es gibt damit keinen zweiten Wert, der
 * verlorengehen kann, und es wechselt automatisch mit, wenn das Aktionstoken
 * neu gewuerfelt wird.
 */
function sp_formtoken()
{
    $cfg = sp_config(false);
    $t = trim((string) $cfg['aktionstoken']);
    // Fail closed: ohne Aktionstoken gibt es kein Merkmal. Ein aus dem
    // Leerstring abgeleiteter Wert waere fuer jeden ausrechenbar und damit
    // kein Schutz, sondern die Behauptung eines Schutzes.
    if ($t === '') { return ''; }
    return hash_hmac('sha256', 'formular-v1', $t);
}

/* ---------------- Zwischenspeicher ---------------- */

function sp_loxone()   { return sp_json_lesen(sp_paths()['datadir'] . '/loxone.json'); }
function sp_verlauf()
{
    $d = sp_json_lesen(sp_paths()['datadir'] . '/verlauf.json');
    return isset($d['saetze']) && is_array($d['saetze']) ? $d['saetze'] : array();
}
function sp_messwerte()
{
    $d = sp_json_lesen(sp_paths()['datadir'] . '/messwerte.json');
    return isset($d['messungen']) && is_array($d['messungen']) ? $d['messungen'] : array();
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
 * Eine Adresse fuer die Anzeige entschaerfen.
 *
 * In der Miniserver-Adresse koennen Zugangsdaten stehen - der Handler sagt
 * das selbst. Bis 0.9.11 zeigte die Oberflaeche sie im Klartext an, obwohl
 * die Datei mit 0600 geschrieben wird. Laenge zeigen, Inhalt nicht.
 */
function sp_url_maskiert($url)
{
    $url = (string) $url;
    return preg_replace_callback('#^(https?://)([^:@/]+):([^@/]*)@#',
        function ($t) {
            return $t[1] . $t[2] . ':' . str_repeat('*', 8)
                 . '(' . sp_zeichen($t[3]) . ' Zeichen)@';
        }, $url);
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
    // Erst fragen, dann oeffnen: ein @fopen() auf eine fehlende Datei ist
    // stumm, aber nicht folgenlos - ein gesetzter Fehlerbehandler sieht die
    // Warnung trotzdem.
    if (!is_file($datei)) {
        return array();
    }
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

/* ---------------- Ruhezeit ----------------
 *
 * Dieselbe Rechnung wie ruhe_aktiv() im Dienst. Sie steht hier ein zweites
 * Mal, weil ueber die Sprachgrenze hinweg keine gemeinsame Funktion moeglich
 * ist - die WERTE kommen aber aus derselben Konfiguration, und der Reiter
 * Test zeigt das Ergebnis beider Seiten nebeneinander.
 *
 * Bis 0.10.1 fehlte hier die ERSTE der beiden Quellen des Dienstes: die
 * Stilllegung durch Loxone (data/.../ruhe.json, Schluessel 'still'). Damit
 * meldete die Statuszeile RUHE=0, waehrend der Dienst schwieg und ueber
 * MQTT 1 schickte - zwei Wege, zwei Antworten auf dieselbe Frage.
 */
function sp_ruhe_aktiv($cfg = null, $jetzt = null)
{
    // sp_config(false): diese Funktion wird auch aus dem unangemeldeten
    // Endpunkt heraus benutzt und darf deshalb nichts anlegen.
    if ($cfg === null) { $cfg = sp_config(false); }
    // Erste Quelle: von Loxone stillgelegt. Der Merker liegt unter data/,
    // weil der unangemeldete Endpunkt nichts schreiben darf - umgelegt
    // wird er vom Dienst ueber die Warteschlange.
    $still = sp_json_lesen(sp_paths()['datadir'] . '/ruhe.json');
    if (!empty($still['still'])) {
        return array(1, sp_t('TEST.A_RUHE_LOXONE'));
    }
    $r = isset($cfg['ruhe']) && is_array($cfg['ruhe']) ? $cfg['ruhe'] : array();
    if (empty($r['ein'])) { return array(0, ''); }
    $minuten = function ($hhmm) {
        if (!preg_match('/^(\d{1,2}):(\d{2})$/', (string) $hhmm, $t)) { return -1; }
        return min(23, (int) $t[1]) * 60 + min(59, (int) $t[2]);
    };
    $von = $minuten(isset($r['von']) ? $r['von'] : '');
    $bis = $minuten(isset($r['bis']) ? $r['bis'] : '');
    if ($von < 0 || $bis < 0 || $von === $bis) { return array(0, ''); }
    $jetzt = $jetzt === null ? time() : $jetzt;
    $nun = (int) date('H', $jetzt) * 60 + (int) date('i', $jetzt);
    $drin = $von < $bis ? ($nun >= $von && $nun < $bis) : ($nun >= $von || $nun < $bis);
    return $drin ? array(1, 'Ruhezeit ' . $r['von'] . ' bis ' . $r['bis']) : array(0, '');
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
     * SEIT 0.10.0 steht die Obergrenze auch in den GRENZEN der
     * Vorgabendatei (wartezeit: 1..12). Bis dahin liess das Formular Werte
     * bis 120 zu, die hier ausnahmslos auf 12 gestutzt wurden - ein Feld,
     * dessen obere Haelfte keine Wirkung hatte.
     *
     * Der Dienst arbeitet den Befehl trotzdem zu Ende - die Warteschlange
     * liegt im Dateisystem, nicht in dieser Anfrage. */
    $wartezeit = max(0, min(SP_WARTEN_WEB, (int) $wartezeit));

    /* Laeuft der Dienst ueberhaupt? Ohne diese Frage wartete die Anfrage die
     * volle Zeit auf eine Antwort, die niemand schreiben kann - gemessen
     * 20,06 s, wo 0,00 s genuegen. Es wird KEIN Befehl eingereiht: er laege
     * sonst herum, bis der Dienst irgendwann startet, und wuerde dann
     * verspaetet ausgefuehrt. Bei einer Sprachausgabe ist das kein
     * Schoenheitsfehler, sondern eine Stimme aus dem Nichts. */
    if (sp_dienst_pid() === 0) {
        return array(0, 'Der Dienst laeuft nicht - der Befehl wurde nicht eingereiht. '
                      . 'Im Reiter Einstellungen starten.');
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
                         (string) (isset($a['meldung']) ? $a['meldung'] : ''),
                         $a);
        }
        usleep(100000);
    }
    return array(2, 'Eingereiht, aber der Dienst hat innerhalb von ' . $wartezeit . ' s nicht geantwortet. '
                  . 'Er arbeitet den Befehl zu Ende - das Ergebnis steht im Protokoll.',
                 array());
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
                  'brokerport' => '', 'user' => '', 'pw' => '', 'lokal' => 0,
                  'fassung' => 0);
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
        // 0 heisst 'nicht lesbar' und NICHT '1'. Wer hier auf 1 vorbelegt,
        // behauptet fuer die Haelfte der Anlagen etwas Falsches - siehe
        // sp_mqtt_gateway_info().
        'fassung'    => (int) $hol('Gatewayversion', 'gatewayversion'),
        'broker'     => (string) $hol('Brokerhost', 'brokerhost'),
        'brokerport' => (string) $hol('Brokerport', 'brokerport'),
        'user'       => (string) $hol('Brokeruser', 'brokeruser'),
        'pw'         => (string) $hol('Brokerpass', 'brokerpass'),
        'lokal'      => in_array((string) $hol('Uselocalbroker', 'uselocalbroker'), array('1', 'true'), true) ? 1 : 0,
    );
}

/**
 * Welcher Satz gilt fuer das Abo - und gilt er ueberhaupt?
 *
 * Der Satz "Ohne diesen Eintrag kommt am Miniserver nichts an" ist die
 * haeufigste Fehlerursache ueberhaupt - er gilt aber NUR fuer Gateway V1.
 * Unter V2 gibt es das Eingabefeld nicht mehr; die Datenpunkte werden in den
 * Abonnements angehakt. Bis 0.9.11 stand der Satz unbedingt da und schickte
 * jeden V2-Anwender zu einem Feld, das es nicht gibt.
 *
 * Ist die Fassung nicht lesbar, gelten BEIDE Saetze - einen von beiden zu
 * behaupten waere fuer die Haelfte der Anlagen falsch.
 *
 * Rueckgabe: array('fassung' => 0|1|2, 'v1' => bool, 'v2' => bool)
 */
function sp_mqtt_gateway_info()
{
    $m = sp_mqtt_zustand();
    $f = (int) $m['fassung'];
    return array('fassung' => $f,
                 'v1' => ($f === 0 || $f === 1),
                 'v2' => ($f === 0 || $f >= 2));
}

/* ==================================================================
 * Die Statuszeile - EINE Quelle
 *
 * Bis 0.9.11 gab es zwei: sp_status_felder() kannte vier Felder, die
 * printf-Zeile in webfrontend/html/index.php sechs. REGELN und ZIELE - die
 * beiden Werte, an denen man sieht, ob die Satzdatei ueberhaupt geladen ist -
 * kamen in Loxone deshalb NIE an: die XML-Vorlage und die Tabelle im Reiter
 * schoepfen beide aus sp_status_felder().
 *
 * Jetzt bauen Vorlage, Tabelle UND Zeile aus dieser einen Liste.
 *
 * Je Feld: Einheit, Sprachschluessel, kleinster und groesster Wert. Die
 * Grenzen sind nicht Kosmetik - Loxone zieht daraus die Reglergrenzen und die
 * Plausibilitaetspruefung. -1 bedeutet bei ALTER und LETZTER 'nicht bekannt';
 * ohne MinVal=-1 zeigt die Visualisierung dort eine 0, und 0 heisst 'gerade
 * eben' - eine stille Falschaussage genau im Fehlerfall.
 * ================================================================== */
function sp_status_felder()
{
    return array(
        'OK'         => array('',  'SP_FELD.OK',         0, 1),
        'MIKROFONE'  => array('',  'SP_FELD.MIKROFONE',  0, 32),
        'BEREIT'     => array('',  'SP_FELD.BEREIT',     0, 32),
        'DIENSTE'    => array('',  'SP_FELD.DIENSTE',    0, 4),
        'REGELN'     => array('',  'SP_FELD.REGELN',     0, 999),
        'ZIELE'      => array('',  'SP_FELD.ZIELE',      0, 999),
        'RUHE'       => array('',  'SP_FELD.RUHE',       0, 1),
        'LETZTER'    => array('s', 'SP_FELD.LETZTER',   -1, 2592000),
        'ALTER'      => array('s', 'SP_FELD.ALTER',     -1, 2592000),
    );
}

/**
 * Der Suchtext eines Feldes - an EINER Stelle.
 *
 * Das fuehrende Semikolon ist Pflicht: Loxone nimmt die ERSTE Fundstelle, und
 * ein Feldname, der Endstueck eines anderen ist, wird sonst vom laengeren
 * getroffen. In dieser Zeile ist 'ZIELE' das Endstueck von nichts, aber
 * 'OK' waere es beim naechsten Feld namens 'MQTT_OK'. Die Regel kostet nichts
 * und verhindert eine Fehlmessung, die aussieht wie ein Messwert.
 *
 * Diese Funktion ist die einzige Stelle, an der das Muster entsteht - Vorlage
 * und Oberflaeche rufen beide sie. In der betroffenen Plugin-Familie stand
 * das Muster fuenfmal woertlich im Quelltext, und genau diese Verdopplung
 * liess die Regel auseinanderlaufen.
 */
function sp_check($feld)
{
    return '\i;' . $feld . '=\i\v';
}

/** Die Statuszeile fuer den Endpunkt - aus derselben Liste wie die Vorlage. */
function sp_statuszeile()
{
    $lox = sp_loxone();
    $cfg = sp_config(false);
    $sats = sp_satelliten();
    $bereit = 0;
    foreach ($sats as $s) {
        if (isset($s['zustand']) && $s['zustand'] !== 'getrennt') { $bereit++; }
    }
    list($ruhe, ) = sp_ruhe_aktiv($cfg);
    $hol = function ($k, $vorgabe = 0) use ($lox) {
        return isset($lox[$k]) ? (int) $lox[$k] : $vorgabe;
    };
    $werte = array(
        // OK sagt: der Dienst lebt. Bis 0.9.11 stand hier 'irgendein Mikrofon
        // ist verbunden' - eine Anlage ohne Mikrofon meldete damit dauerhaft
        // Stoerung, obwohl der Reiter Test Saetze durchschickt.
        'OK'        => sp_dienst_pid() > 0 ? 1 : 0,
        'MIKROFONE' => count($sats),
        'BEREIT'    => $bereit,
        'DIENSTE'   => $hol('dienste_ok'),
        'REGELN'    => $hol('anzahl_regeln'),
        'ZIELE'     => $hol('anzahl_ziele'),
        'RUHE'      => $ruhe ? 1 : 0,
        'LETZTER'   => isset($lox['letzter_satz_alter']) ? (int) $lox['letzter_satz_alter'] : -1,
        'ALTER'     => sp_alter(),
    );
    $teile = array('SPRACHSTEUERUNG');
    foreach (sp_status_felder() as $feld => $info) {
        $teile[] = $feld . '=' . (isset($werte[$feld]) ? $werte[$feld] : 0);
    }
    return implode(';', $teile);
}

/** Vorlage fuer den Import in Loxone Config. Rueckgabe: array(name, inhalt) */
function sp_vorlage()
{
    $p = sp_paths();
    $host = sp_hostname();
    $token = sp_token();
    $cmds = array();
    foreach (sp_status_felder() as $feld => $info) {
        $cmds[] = array(
            'title'   => 'SPRACHSTEUERUNG_' . $feld,
            'comment' => trim(strip_tags(html_entity_decode(sp_t($info[1]), ENT_QUOTES, 'UTF-8')))
                       . ($info[0] !== '' ? ' [' . $info[0] . ']' : ''),
            'check'   => sp_check($feld),
            'unit'    => '<v.1>' . ($info[0] !== '' ? ' ' . $info[0] : ''),
            'min'     => $info[2],
            'max'     => $info[3],
        );
    }
    return array('VI_Sprachsteuerung.xml', sp_xml_virtual_in_http(array(
        'title'   => 'Sprachsteuerung lokal',
        'address' => 'http://' . $host . '/plugins/' . $p['plugin']
                   . '/index.php?token=' . $token . '&aktion=status',
        'polling' => '60',
        'comment' => 'Erzeugt vom LoxBerry-Plugin Sprachsteuerung lokal (' . date('d.m.Y') . ')',
    ), $cmds));
}

/**
 * Vorlage fuer den virtuellen AUSGANG - Loxone laesst das Haus sprechen.
 *
 * WARUM DAS SEIT 0.10.0 DAZUGEHOERT: der Reiter beschreibt seit jeher zwei
 * Ausgangsbefehle, aber ZUM ABTIPPEN. Das sind die laengsten und
 * fehleranfaelligsten Zeichenketten der ganzen Oberflaeche - Adresse samt
 * Token und URL-kodiertem Text. Ein Tippfehler im Token faellt erst auf, wenn
 * das Haus schweigt.
 */
function sp_vorlage_ausgang()
{
    $p = sp_paths();
    $host = sp_hostname();
    $token = sp_token();
    $basis = '/plugins/' . $p['plugin'] . '/index.php?token=' . $token;
    $cmds = array(
        array('title'   => 'Sprachsteuerung Ansage',
              'comment' => 'Ein: sagt den festen Text an. Den Text hinter text= '
                         . 'anpassen; Leerzeichen als %20 schreiben.',
              'cmdon'   => $basis . '&aktion=sprechen&text=Das%20Garagentor%20steht%20offen',
              'cmdoff'  => ''),
        array('title'   => 'Sprachsteuerung Satz',
              'comment' => 'Ein: schickt einen Satz durch dieselbe Kette wie ein '
                         . 'gesprochener. Damit laesst sich die Sprachlogik auch '
                         . 'von einem Taster aus benutzen.',
              'cmdon'   => $basis . '&aktion=satz&text=schalte%20das%20licht%20im%20wohnzimmer%20aus',
              'cmdoff'  => ''),
        array('title'   => 'Sprachsteuerung Ruhe',
              'comment' => 'Ein schaltet die Ruhezeit ein (keine Ansagen), Aus wieder ab. '
                         . 'Damit laesst sich die Nachtruhe aus Loxone steuern.',
              'cmdon'   => $basis . '&aktion=ruhe&wert=1',
              'cmdoff'  => $basis . '&aktion=ruhe&wert=0'),
    );
    return array('VQ_Sprachsteuerung.xml', sp_xml_virtual_out(array(
        'title'   => 'Sprachsteuerung lokal - Befehle',
        'address' => 'http://' . $host,
        'comment' => 'Erzeugt vom LoxBerry-Plugin Sprachsteuerung lokal (' . date('d.m.Y') . ')',
    ), $cmds));
}

/**
 * Vorlage mit einem virtuellen Texteingang JE ZIEL.
 *
 * Genau der Fall, den der Hausstandard meint: bei drei Zielen tippt man das
 * noch ab, bei dreissig nicht mehr. Die Themen stehen in der Satzdatei, also
 * kann das Plugin die Datei bauen.
 */
function sp_vorlage_ziele()
{
    $p = sp_paths();
    $cfg = sp_config();
    $saetze = sp_saetze();
    $praefix = trim((string) $cfg['mqtt_topic'], '/');
    $ziele = isset($saetze['ziele']) && is_array($saetze['ziele']) ? $saetze['ziele'] : array();
    if (!$ziele) { return array('', ''); }
    $cmds = array();
    foreach ($ziele as $k => $z) {
        $name = is_array($z) && isset($z['name']) ? $z['name'] : $k;
        $thema = is_array($z) ? (isset($z['thema']) ? $z['thema'] : $k) : (string) $z;
        // Der Titel ist fuer Menschen, der Suchtext fuer die Maschine.
        $cmds[] = array(
            'title'   => 'SPR_' . strtoupper(preg_replace('/[^A-Za-z0-9]+/', '_', $k)),
            'comment' => 'Aktion fuer ' . $name . ' - MQTT-Thema '
                       . $praefix . '/' . $thema . '/aktion (ALS TEXT verwenden)',
            'check'   => '\i' . $praefix . '/' . $thema . '/aktion=\i\v',
            'unit'    => '<v.1>',
            'min'     => 0, 'max' => 1,
        );
    }
    return array('VI_Sprachsteuerung_Ziele.xml', sp_xml_virtual_in_http(array(
        'title'   => 'Sprachsteuerung lokal - Ziele',
        'address' => '',
        'polling' => '60',
        'comment' => 'Je Ziel ein Texteingang. Diese Bausteine werden ueber MQTT '
                   . 'versorgt, nicht ueber die Adresse im Kopf.',
    ), $cmds));
}

function sp_hostname()
{
    return isset($_SERVER['HTTP_HOST']) && $_SERVER['HTTP_HOST'] !== ''
        ? preg_replace('/[^A-Za-z0-9\.\-:]/', '', (string) $_SERVER['HTTP_HOST'])
        : (gethostname() ?: 'loxberry');
}

/* ==================================================================
 * Loxone-Vorlagen
 *
 * Nachbau der Bausteine aus LoxBerry::LoxoneTemplateBuilder; das Modul gibt es
 * nur in Perl. Attributreihenfolge, CRLF als Zeilenende und der Tabulator vor
 * den Kindelementen entsprechen dem Original.
 *
 * NACHGEZOGEN AM 24.08.2026 gegen die massgebliche Ausfuhr aus Loxone Config
 * (XML_Vorlagen_0.9.10/VI_weissware_geraet1_verbrauch.xml und
 * VQ_weissware_geraet1_befehle.xml). Bis 0.9.11 fehlten hier vier Dinge, die
 * Config selbst schreibt: HintText am Wurzelelement, das erste Kindelement
 * <Info templateType=... minVersion=...>, je Befehl ein Unit und ein
 * HintText. Ausserdem standen MinVal/MaxVal pauschal auf +-2147483647 -
 * damit verschenkt man die Reglergrenzen und die Plausibilitaetspruefung.
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
    $o .= 'HintText="" ';
    $o .= 'Title="' . sp_x($kopf['title']) . '" ';
    $o .= 'Comment="' . sp_x(isset($kopf['comment']) ? $kopf['comment'] : '') . '" ';
    $o .= 'Address="' . sp_x(isset($kopf['address']) ? $kopf['address'] : '') . '" ';
    $o .= 'PollingTime="' . sp_x(isset($kopf['polling']) ? $kopf['polling'] : '60') . '"';
    $o .= '>' . $crlf;
    $o .= "\t" . '<Info templateType="2" minVersion="17010727"/>' . $crlf;
    foreach ($cmds as $c) {
        $o .= "\t" . '<VirtualInHttpCmd ';
        $o .= 'Title="' . sp_x($c['title']) . '" ';
        $o .= 'Comment="' . sp_x(isset($c['comment']) ? $c['comment'] : '') . '" ';
        $o .= 'Check="' . sp_x(isset($c['check']) ? $c['check'] : ' ') . '" ';
        $o .= 'Signed="' . ((isset($c['min']) && (int) $c['min'] < 0) ? 'true' : 'false') . '" ';
        $o .= 'Analog="true" ';
        $o .= 'SourceValLow="0" ';
        $o .= 'DestValLow="0" ';
        $o .= 'SourceValHigh="1" ';
        $o .= 'DestValHigh="1" ';
        $o .= 'DefVal="0" ';
        $o .= 'MinVal="' . (int) (isset($c['min']) ? $c['min'] : 0) . '" ';
        $o .= 'MaxVal="' . (int) (isset($c['max']) ? $c['max'] : 1) . '" ';
        $o .= 'Unit="' . sp_x(isset($c['unit']) ? $c['unit'] : '<v.1>') . '" ';
        $o .= 'HintText=""';
        $o .= '/>' . $crlf;
    }
    $o .= '</VirtualInHttp>' . $crlf;
    return $o;
}

/**
 * Virtueller Ausgang.
 *
 * Attributreihenfolge gegen VQ_weissware_geraet1_befehle.xml gemessen:
 * Title, Comment, CmdOnMethod, CmdOffMethod, CmdOn, CmdOffMethod-Wert, ...
 * Ein DIGITALER Befehl traegt Analog="false" und KEINE Source/Dest-Werte -
 * die stehen nur am analogen. Hier sind alle Befehle digital.
 */
function sp_xml_virtual_out($kopf, $cmds)
{
    $crlf = "\r\n";
    $o = '<?xml version="1.0" encoding="utf-8"?>' . $crlf;
    $o .= '<VirtualOut ';
    $o .= 'HintText="" ';
    $o .= 'Title="' . sp_x($kopf['title']) . '" ';
    $o .= 'Comment="' . sp_x(isset($kopf['comment']) ? $kopf['comment'] : '') . '" ';
    $o .= 'Address="' . sp_x(isset($kopf['address']) ? $kopf['address'] : '') . '" ';
    $o .= 'CmdInit="" ';
    $o .= 'CloseAfterSend="false" ';
    $o .= 'CmdSep=""';
    $o .= '>' . $crlf;
    $o .= "\t" . '<Info templateType="3" minVersion="17010727"/>' . $crlf;
    foreach ($cmds as $c) {
        $o .= "\t" . '<VirtualOutCmd ';
        $o .= 'Title="' . sp_x($c['title']) . '" ';
        $o .= 'Comment="' . sp_x(isset($c['comment']) ? $c['comment'] : '') . '" ';
        $o .= 'CmdOnMethod="GET" ';
        $o .= 'CmdOn="' . sp_x(isset($c['cmdon']) ? $c['cmdon'] : '') . '" ';
        $o .= 'CmdOffMethod="GET" ';
        $o .= 'CmdOff="' . sp_x(isset($c['cmdoff']) ? $c['cmdoff'] : '') . '" ';
        $o .= 'Analog="false" ';
        $o .= 'Repeat="0" ';
        $o .= 'RepeatRate="0" ';
        $o .= 'HintText=""';
        $o .= '/>' . $crlf;
    }
    $o .= '</VirtualOut>' . $crlf;
    return $o;
}

/** Ist die erzeugte Vorlage wohlgeformt? Gehoert in den Reiter Test. */
function sp_vorlage_pruefen(&$geprueft = null, &$gesamt = null)
{
    /* Gezaehlt wird, was WIRKLICH gemessen wurde. Bis 0.10.1 meldete die
     * Pruefzeile 'alle drei Vorlagen', nachdem sie zwei angesehen hatte: eine
     * leere Zielliste ergibt eine leere Vorlage, und die wird uebersprungen.
     * 'Alle 0 von 0 sind in Ordnung' ist kein Haken (REGELN_1). */
    $befunde = array();
    $vorlagen = array('Eingang' => sp_vorlage(), 'Ausgang' => sp_vorlage_ausgang(),
                      'Ziele' => sp_vorlage_ziele());
    $gesamt = count($vorlagen);
    $geprueft = 0;
    foreach ($vorlagen as $art => $paar) {
        list($name, $inhalt) = $paar;
        if ($inhalt === '') { continue; }
        $geprueft++;
        $vorher = libxml_use_internal_errors(true);
        $x = simplexml_load_string($inhalt);
        libxml_clear_errors();
        libxml_use_internal_errors($vorher);
        if ($x === false) {
            $befunde[] = $art . ': NICHT wohlgeformt';
            continue;
        }
        if (substr_count($inhalt, "\r\n") < 3) {
            $befunde[] = $art . ': Zeilenenden sind nicht CRLF';
        }
        if (strpos($inhalt, '<Info template') === false) {
            $befunde[] = $art . ': das Info-Element fehlt';
        }
    }
    return $befunde;
}

/* ==================================================================
 * Sicherung: herunterladen und wieder einspielen
 *
 * Die Satzdatei ist der eigentliche Wert dieses Plugins - Regeln, Ziele,
 * Aliasnamen, MQTT-Themen. Bis 0.9.11 liess sie sich nur ueber ein einziges
 * Textfeld bearbeiten und nirgends sichern.
 *
 * Das AKTIONSTOKEN und die Miniserver-Adresse gehen NICHT mit: in ihnen
 * stecken Zugangsdaten, und eine Sicherungsdatei liegt am Ende im Download-
 * Ordner eines Rechners, der nicht der LoxBerry ist.
 * ================================================================== */
function sp_sicherung_bauen()
{
    $cfg = sp_config();
    foreach (array('aktionstoken', 'miniserver_url') as $geheim) {
        unset($cfg[$geheim]);
    }
    // Auch die Schluessel der ESPHome-Mikrofone bleiben hier.
    if (isset($cfg['satelliten']) && is_array($cfg['satelliten'])) {
        foreach ($cfg['satelliten'] as $i => $s) {
            if (is_array($s)) { unset($cfg['satelliten'][$i]['schluessel']); }
        }
    }
    return json_encode(array(
        'art'      => 'sprachsteuerung-sicherung',
        'fassung'  => 1,
        'erzeugt'  => date('c'),
        'hinweis'  => 'Aktionstoken, Miniserver-Adresse und Mikrofon-Schluessel '
                    . 'sind absichtlich NICHT enthalten.',
        'config'   => $cfg,
        'saetze'   => sp_saetze(),
    ), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

/**
 * Eine Sicherung pruefen und einspielen.
 *
 * Geprueft wird die FORM, und abgewiesen wird benannt - nicht
 * zurechtgebogen. Rueckgabe: array(ok, Meldung).
 */
function sp_sicherung_lesen($roh)
{
    $roh = (string) $roh;
    if (strlen($roh) > 2 * 1024 * 1024) {
        return array(0, 'Die Datei ist groesser als 2 MB - das ist keine Sicherung dieses Plugins.');
    }
    if (trim($roh) === '') {
        return array(0, 'Die Datei ist leer.');
    }
    $d = json_decode($roh, true);
    if (!is_array($d)) {
        return array(0, 'Das ist kein gueltiges JSON: ' . json_last_error_msg());
    }
    if (!isset($d['art']) || $d['art'] !== 'sprachsteuerung-sicherung') {
        return array(0, 'Das ist keine Sicherung dieses Plugins (Kennzeichen fehlt).');
    }
    if (!isset($d['config']) || !is_array($d['config'])
        || !isset($d['saetze']) || !is_array($d['saetze'])) {
        return array(0, 'In der Sicherung fehlt die Konfiguration oder die Satzdatei.');
    }
    if (!isset($d['saetze']['regeln']) || !is_array($d['saetze']['regeln'])
        || !isset($d['saetze']['ziele']) || !is_array($d['saetze']['ziele'])) {
        return array(0, 'Die Satzdatei in der Sicherung hat keine Listen regeln und ziele.');
    }
    // Das laufende Token und die Miniserver-Adresse BLEIBEN - sie stehen
    // nicht in der Sicherung, und ein Einspielen darf die Adressen im
    // Miniserver nicht ungueltig machen.
    $alt = sp_config();
    $neu = array_merge($alt, $d['config']);
    $neu['aktionstoken'] = $alt['aktionstoken'];
    $neu['miniserver_url'] = $alt['miniserver_url'];
    if (isset($alt['satelliten']) && is_array($alt['satelliten'])
        && isset($neu['satelliten']) && is_array($neu['satelliten'])) {
        foreach ($neu['satelliten'] as $i => $s) {
            if (is_array($s) && empty($s['schluessel']) && !empty($alt['satelliten'][$i]['schluessel'])) {
                $neu['satelliten'][$i]['schluessel'] = $alt['satelliten'][$i]['schluessel'];
            }
        }
    }
    if (!sp_config_speichern($neu)) {
        return array(0, 'Die Konfiguration liess sich nicht schreiben.');
    }
    if (!sp_saetze_speichern(sp_steuerzeichen_weg($d['saetze']))) {
        return array(0, 'Die Satzdatei liess sich nicht schreiben.');
    }
    sp_log('Sicherung eingespielt (' . count($d['saetze']['regeln']) . ' Regeln, '
           . count($d['saetze']['ziele']) . ' Ziele).');
    return array(1, sprintf('Sicherung eingespielt: %d Regeln, %d Ziele. '
                          . 'Token und Miniserver-Adresse sind unveraendert geblieben.',
                            count($d['saetze']['regeln']), count($d['saetze']['ziele'])));
}

/** Der Verlauf als CSV - fuer die Frage, was regelmaessig NICHT verstanden wird. */
function sp_verlauf_csv()
{
    $zeilen = array("Zeit;Verstanden;Satz;Mikrofon;Absicht;Aktion;Ziel;Quelle;Grund;Antwort");
    foreach (sp_verlauf() as $e) {
        $f = function ($k) use ($e) {
            $w = isset($e[$k]) ? (string) $e[$k] : '';
            return str_replace(array(';', "\r", "\n"), array(',', ' ', ' '), $w);
        };
        $zeilen[] = implode(';', array(
            date('Y-m-d H:i:s', (int) (isset($e['ts']) ? $e['ts'] : 0)),
            !empty($e['ok']) ? 'ja' : 'nein',
            $f('satz'), $f('mikrofon'), $f('absicht'), $f('aktion'),
            $f('ziel'), $f('quelle'), $f('grund'), $f('antwort'),
        ));
    }
    return implode("\r\n", $zeilen) . "\r\n";
}

/** Welche Saetze wurden am haeufigsten NICHT verstanden? */
function sp_nicht_verstanden($hoechstens = 10)
{
    $zaehler = array();
    foreach (sp_verlauf() as $e) {
        if (!empty($e['ok'])) { continue; }
        $satz = trim((string) (isset($e['satz']) ? $e['satz'] : ''));
        if ($satz === '') { continue; }
        $k = $satz . "\x00" . (string) (isset($e['grund']) ? $e['grund'] : '');
        if (!isset($zaehler[$k])) {
            $zaehler[$k] = array('satz' => $satz, 'anzahl' => 0,
                                 'grund' => (string) (isset($e['grund']) ? $e['grund'] : ''),
                                 'gesucht' => (string) (isset($e['gesucht']) ? $e['gesucht'] : ''),
                                 'ts' => (int) (isset($e['ts']) ? $e['ts'] : 0));
        }
        $zaehler[$k]['anzahl']++;
    }
    uasort($zaehler, function ($a, $b) { return $b['anzahl'] - $a['anzahl']; });
    return array_slice(array_values($zaehler), 0, $hoechstens);
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
 * 'wake_port'. Diese Abbildung steht genau hier und nirgends sonst.
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

/**
 * Den Satz deuten, ohne zu schalten.
 *
 * Ruft DIESELBE Kette wie der Dienst - sprachsteuerung_dienst.py --trocken -
 * und sendet nichts. Ein Trockenlauf, der einen anderen Weg nimmt, prueft den
 * anderen Weg. Er braucht auch keinen laufenden Dienst: gerade dann will man
 * wissen, welche Regel greifen wuerde.
 */
function sp_trockenlauf($satz, $raum = '')
{
    $p = sp_paths();
    $py = is_file($p['bindir'] . '/venv/bin/python3') ? $p['bindir'] . '/venv/bin/python3' : 'python3';
    $skript = $p['bindir'] . '/sprachsteuerung_dienst.py';
    if (!is_file($skript)) { return array(0, 'sprachsteuerung_dienst.py fehlt.', array()); }
    $ausgabe = array();
    $befehl = escapeshellcmd($py) . ' ' . escapeshellarg($skript)
            . ' --trocken ' . escapeshellarg((string) $satz);
    if (trim((string) $raum) !== '') {
        $befehl .= ' --raum ' . escapeshellarg((string) $raum);
    }
    @exec($befehl . ' 2>&1', $ausgabe);
    $d = json_decode(implode("\n", $ausgabe), true);
    if (!is_array($d)) {
        return array(0, 'Der Trockenlauf lieferte keine verwertbare Antwort: '
                      . implode(' ', array_slice($ausgabe, 0, 3)), array());
    }
    return array(!empty($d['ok']) ? 1 : 0,
                 (string) (isset($d['antwort']) ? $d['antwort'] : ''), $d);
}

/* ==================================================================
 * Pruefzeilen fuer den Reiter Test
 * ================================================================== */

/**
 * Ruft der Endpunkt sich selbst erfolgreich auf - und weist er ein falsches
 * Token wirklich ab?
 *
 * Die Gegenprobe gehoert in dieselbe Pruefung: ein Endpunkt, der antwortet,
 * aber JEDEM antwortet, ist schlimmer als einer, der schweigt.
 *
 * Drei Ausgaenge, nicht zwei - der dritte ist wichtig: ein Webserver, der nur
 * eine Anfrage zugleich bearbeitet, kann sich waehrend des Seitenaufbaus
 * nicht selbst aufrufen. Ein Kreuz waere dort ein Kreuz, das nichts bedeutet.
 */
function sp_endpunkt_probe()
{
    $p = sp_paths();
    $basis = 'http://' . sp_hostname() . '/plugins/' . $p['plugin'] . '/index.php';
    $token = sp_token();
    $hol = function ($url) {
        // Auch hier keine Weiterleitung: in der Adresse steht das
        // Aktionstoken, und es soll nirgendwo sonst ankommen.
        $ctx = stream_context_create(array('http' => array(
            'timeout' => 5, 'ignore_errors' => true,
            'follow_location' => 0, 'max_redirects' => 1,
            'header' => "User-Agent: LoxBerry-Sprachsteuerung-Selbsttest\r\n")));
        $rumpf = @file_get_contents($url, false, $ctx);
        $code = 0;
        if (isset($http_response_header) && is_array($http_response_header)) {
            foreach ($http_response_header as $z) {
                if (preg_match('#^HTTP/\S+\s+(\d{3})#', $z, $t)) { $code = (int) $t[1]; }
            }
        }
        return array($code, (string) $rumpf);
    };
    list($code, $rumpf) = $hol($basis . '?selftest=1&token=' . urlencode($token));
    if ($code === 0) {
        return array(-1, sp_t('TEST.A_ENDPUNKT_STUMM'));
    }
    if ($code !== 200 || strpos($rumpf, 'SELFTEST;OK=1') === false) {
        return array(0, sprintf(sp_t('TEST.A_ENDPUNKT_FEHL'), $code,
                                sp_e(substr(trim($rumpf), 0, 80))));
    }
    // Gegenprobe: ein falsches Token MUSS abgewiesen werden.
    list($code2, ) = $hol($basis . '?selftest=1&token=' . urlencode($token . 'x'));
    if ($code2 !== 403) {
        return array(0, sprintf(sp_t('TEST.A_ENDPUNKT_OFFEN'), $code2));
    }
    return array(1, sp_t('TEST.A_ENDPUNKT_OK'));
}

/**
 * Setzt der SERVER das sm-active, oder haengt die Seite am JavaScript?
 *
 * Geeicht durch Rueckbau: nimmt man das serverseitige sm-active an einem
 * Bereich weg, muss diese Zeile rot werden.
 */
function sp_oberflaechendatei()
{
    $p = sp_paths();
    $kandidaten = array();
    $auth = getenv('LBPHTMLAUTHDIR');
    if ($auth) { $kandidaten[] = $auth . '/index.php'; }
    if ($p['home'] !== '') {
        $kandidaten[] = $p['home'] . '/webfrontend/htmlauth/plugins/'
                      . $p['plugin'] . '/index.php';
    }
    // Der Archivfall: html/ und htmlauth/ liegen nebeneinander.
    $kandidaten[] = dirname(dirname(__FILE__)) . '/htmlauth/index.php';
    foreach ($kandidaten as $k) {
        if (is_file($k)) { return $k; }
    }
    return '';
}

function sp_smactive_probe()
{
    $datei = sp_oberflaechendatei();
    $s = $datei === '' ? '' : (string) @file_get_contents($datei);
    if ($s === '') { return array(0, sp_t('TEST.A_SMACTIVE_NICHTS')); }
    // Leiste und Bereiche entstehen aus EINER Liste; die Zahl der
    // Reiter steht deshalb dort und nicht in der gerenderten Leiste.
    // Bis 0.10.1 wurde 'data-ziel="tab-..."' gezaehlt - das kommt in
    // der Schleifenform genau einmal vor, und die Zeile war dauerhaft
    // rot (gemessen: Leiste 1, Bereiche 8, von 0).
    $anzahl = 0;
    if (preg_match('/\$sp_reiter_ids\s*=\s*array\(([^)]*)\)/', $s, $r)) {
        $anzahl = preg_match_all("/'[a-z_]+'/", $r[1]);
    }
    $leiste   = preg_match_all('/class="sm-tab<\?=[^>]*sm-active/', $s);
    $bereiche = preg_match_all('/class="sm-seite<\?=[^>]*sm-active/', $s);
    if ($anzahl > 0 && $leiste >= 1 && $bereiche >= $anzahl) {
        return array(1, sprintf(sp_t('TEST.A_SMACTIVE_OK'), $anzahl));
    }
    return array(0, sprintf(sp_t('TEST.A_SMACTIVE_FEHL'), $leiste, $bereiche, $anzahl));
}

/** Traegt JEDES Formular das Merkmal gegen fremde Absender? */
function sp_formularprobe($datei = null)
{
    if ($datei === null) { $datei = sp_oberflaechendatei(); }
    $s = $datei === '' ? '' : (string) @file_get_contents($datei);
    // Die Huelle muss das Merkmal auch wirklich ausgeben. Ohne diese
    // Probe wuerde ein Aufruf von sp_hidden() genuegen, um gruen zu
    // sein - auch dann, wenn die Huelle es gar nicht mehr schreibt.
    if ($s !== '' && strpos($s, '$sp_hidden = function') !== false
        && strpos($s, 'name="fmt"') === false) {
        return array(0, sp_t('TEST.A_FORM_HUELLE'));
    }
    $gesamt = 0; $ohne = 0;
    if (preg_match_all('/<form\s/', $s, $y, PREG_OFFSET_CAPTURE)) {
        foreach ($y[0] as $f) {
            $gesamt++;
            $ende = strpos($s, '</form>', $f[1]);
            $blk  = substr($s, $f[1], ($ende === false ? 600 : $ende - $f[1]));
            if (strpos($blk, 'name="fmt"') === false
                && strpos($blk, 'sp_hidden(') === false) { $ohne++; }
        }
    }
    // Die leere Menge zuerst: "alle 0 von 0 sind in Ordnung" ist kein Haken.
    if ($gesamt === 0) { return array(0, sp_t('TEST.A_FORM_KEINS')); }
    if ($ohne > 0)     { return array(0, sprintf(sp_t('TEST.A_FORM_OHNE'), $ohne, $gesamt)); }
    return array(1, sprintf(sp_t('TEST.A_FORM_OK'), $gesamt));
}

/**
 * Kennen beide Sprachen dieselbe Vorgabenliste?
 *
 * Verglichen wird die Zahl der Schluessel in templates/vorgaben.json mit
 * der Zahl, die in der Konfiguration wirklich steht. Fehlt einer, gilt die
 * Vorgabe - das ist kein Fehler, aber es gehoert sichtbar dazu.
 *
 * Bis 0.10.1 stand hier, verglichen werde mit der Zahl aus dem Selbsttest
 * des DIENSTES. Das hat die Funktion nie getan.
 */
function sp_vorgaben_probe()
{
    $vor = sp_vorgaben();
    if (!$vor) {
        return array(0, sp_t('TEST.A_VORGABEN_FEHLT'));
    }
    $roh = sp_json_lesen(sp_paths()['config']);
    $fehlend = array();
    foreach ($vor as $k => $v) {
        if (!array_key_exists($k, $roh)) { $fehlend[] = $k; }
    }
    if ($fehlend) {
        return array(0, sprintf(sp_t('TEST.A_VORGABEN_LUECKE'),
                                count($vor) - count($fehlend), count($vor),
                                sp_e(implode(', ', array_slice($fehlend, 0, 6)))));
    }
    return array(1, sprintf(sp_t('TEST.A_VORGABEN_OK'),
                            count($vor), count($vor)));
}

/** Gibt es eine Zweitschrift, und wie alt ist sie? */
function sp_zweitschrift_probe()
{
    $p = sp_paths();
    $da = array();
    foreach (array('sicherung' => 'sprachsteuerung.json',
                   'sicherung_saetze' => 'saetze.json') as $k => $name) {
        if (is_file($p[$k])) {
            $da[] = $name . ' (' . date('d.m.Y H:i', (int) @filemtime($p[$k])) . ')';
        }
    }
    if (count($da) < 2) {
        return array(0, sprintf(sp_t('TEST.A_ZWEIT_FEHLT'), count($da)));
    }
    return array(1, implode(', ', $da));
}

/** Ist jedes Suchmuster der Statuszeile eindeutig? */
function sp_suchmuster_probe()
{
    $zeile = sp_statuszeile();
    $doppelt = array();
    foreach (sp_status_felder() as $feld => $info) {
        $muster = ';' . $feld . '=';
        if (substr_count($zeile, $muster) !== 1) {
            $doppelt[] = $feld;
        }
    }
    if ($doppelt) {
        return array(0, sprintf(sp_t('TEST.A_MUSTER_DOPPELT'), sp_e(implode(', ', $doppelt))));
    }
    return array(1, sprintf(sp_t('TEST.A_MUSTER_OK'), count(sp_status_felder())));
}

/* ---------------- Mitschnitt ---------------- */

/**
 * Den Mitschnitt fuer eine FRIST einschalten, nicht als Schalter.
 *
 * Ein vergessener Mitschnitt schriebe die Ramdisk voll, auf der log/plugins
 * liegt. Deshalb ein Ablaufzeitpunkt statt eines Hakens - der Dienst schaltet
 * sich selbst ab.
 */
function sp_mitschnitt_schalten($sekunden)
{
    $cfg = sp_config();
    $sekunden = max(0, min(1800, (int) $sekunden));
    $cfg['mitschnitt_bis'] = $sekunden > 0 ? time() + $sekunden : 0;
    if (!sp_config_speichern($cfg)) { return array(0, 'Nicht gespeichert.'); }
    if ($sekunden > 0) {
        sp_log('Mitschnitt fuer ' . $sekunden . ' s eingeschaltet.');
        return array(1, sprintf(sp_t('LOG.MITSCHNITT_AN'), $sekunden));
    }
    sp_log('Mitschnitt abgeschaltet.');
    return array(1, sp_t('LOG.MITSCHNITT_AUS'));
}

function sp_mitschnitt_rest($cfg = null)
{
    if ($cfg === null) { $cfg = sp_config(); }
    $bis = (int) (isset($cfg['mitschnitt_bis']) ? $cfg['mitschnitt_bis'] : 0);
    return $bis > time() ? $bis - time() : 0;
}

/* ==================================================================
 * Ziele aus der Loxone-Struktur vorschlagen
 *
 * WARUM: die Zielliste ist der Inhalt, den der Anwender pflegt, und beim
 * Einrichten tippt er sie vollstaendig ab - Raum fuer Raum, Gerät fuer Gerät.
 * Der Miniserver kennt diese Liste bereits: die Strukturdatei LoxAPP3.json
 * fuehrt jeden Baustein samt Raum und Anzeigenamen.
 *
 * DREI GRENZEN, die dabei gelten und die hier auch so gesagt werden:
 *
 * 1. Es bleibt ein VORSCHLAG. Uebernommen wird nur, was der Anwender anhakt -
 *    das Plugin weiss nicht, welche Bausteine er ansprechen will.
 * 2. Die Zugangsdaten werden NICHT gespeichert. Sie werden einmal benutzt und
 *    fallen mit der Anfrage weg; abgelegt wird nur die Vorschlagsliste, und in
 *    der stehen Namen, keine Kennwoerter.
 * 3. Die Typnamen der Strukturdatei sind NICHT die der Projektdatei: Config
 *    schreibt 'PushButton' und 'LightController2', die Strukturdatei
 *    'Pushbutton' und 'LightControllerV2'. Massgeblich ist hier die
 *    Strukturdatei, weil sie die Quelle ist.
 * ================================================================== */

/** Bausteinarten, die sich sinnvoll ansprechen lassen. */
function sp_lox_arten()
{
    return array(
        'LightControllerV2' => 'Licht', 'LightController' => 'Licht',
        'Switch' => 'Schalter', 'Pushbutton' => 'Taster',
        'Dimmer' => 'Dimmer', 'ColorPickerV2' => 'Farblicht',
        'Jalousie' => 'Beschattung', 'CentralJalousie' => 'Beschattung',
        'CentralLightController' => 'Licht',
        'IRoomControllerV2' => 'Raumklima', 'IRoomController' => 'Raumklima',
        'InfoOnlyAnalog' => 'Messwert', 'InfoOnlyDigital' => 'Zustand',
        'Gate' => 'Tor', 'Alarm' => 'Alarm', 'Intercom' => 'Gegensprechen',
    );
}

/**
 * Die Strukturdatei holen und Vorschlaege daraus bauen.
 *
 * Rueckgabe: array(ok, Meldung, Vorschlaege)
 */
function sp_lox_struktur_holen($host, $benutzer, $kennwort)
{
    $host = trim((string) $host);
    if ($host === '' || !preg_match('/^[A-Za-z0-9][A-Za-z0-9\.\-:_]{0,80}$/', $host)) {
        return array(0, sp_t('LOXIMP.FEHLER_HOST'), array());
    }
    $url = 'http://' . $host . '/data/LoxAPP3.json';
    $kopf = "User-Agent: LoxBerry-Sprachsteuerung-Plugin/0.10\r\n"
          . "Accept: application/json\r\n";
    if ($benutzer !== '') {
        // Die Zugangsdaten gehen in den KOPF, nicht in die Adresse: eine
        // Adresse landet im Protokoll des Webservers, ein Kopf nicht.
        $kopf .= 'Authorization: Basic ' . base64_encode($benutzer . ':' . $kennwort) . "\r\n";
    }
    // KEINE Weiterleitung: der Authorization-Kopf ginge sonst an ein
    // Ziel, das die Gegenstelle bestimmt.
    $ctx = stream_context_create(array('http' => array(
        'timeout' => 15, 'ignore_errors' => true, 'header' => $kopf,
        'follow_location' => 0, 'max_redirects' => 1)));
    $roh = @file_get_contents($url, false, $ctx);
    $code = 0;
    if (isset($http_response_header) && is_array($http_response_header)) {
        foreach ($http_response_header as $z) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $z, $t)) { $code = (int) $t[1]; }
        }
    }
    if ($roh === false || $code === 0) {
        return array(0, sprintf(sp_t('LOXIMP.FEHLER_STUMM'), sp_e($host)), array());
    }
    if ($code === 401) {
        return array(0, sp_t('LOXIMP.FEHLER_401'), array());
    }
    if ($code !== 200) {
        return array(0, sprintf(sp_t('LOXIMP.FEHLER_HTTP'), $code), array());
    }
    $d = json_decode($roh, true);
    if (!is_array($d) || !isset($d['controls']) || !is_array($d['controls'])) {
        return array(0, sp_t('LOXIMP.FEHLER_FORM'), array());
    }
    $raeume = array();
    foreach ((array) (isset($d['rooms']) ? $d['rooms'] : array()) as $uuid => $r) {
        $raeume[$uuid] = is_array($r) && isset($r['name']) ? (string) $r['name'] : '';
    }
    $arten = sp_lox_arten();
    $vorschlaege = array();
    foreach ($d['controls'] as $uuid => $c) {
        if (!is_array($c)) { continue; }
        $typ = (string) (isset($c['type']) ? $c['type'] : '');
        if (!isset($arten[$typ])) { continue; }
        $name = trim((string) (isset($c['name']) ? $c['name'] : ''));
        if ($name === '') { continue; }
        $raum = isset($c['room'], $raeume[$c['room']]) ? $raeume[$c['room']] : '';
        $schluessel = sp_lox_schluessel($raum . '_' . $name);
        if ($schluessel === '' || isset($vorschlaege[$schluessel])) { continue; }
        $alias = array();
        foreach (array($name, trim($raum . ' ' . $name), trim($name . ' ' . $raum)) as $a) {
            $a = trim($a);
            if ($a !== '' && !in_array($a, $alias, true)) { $alias[] = $a; }
        }
        $vorschlaege[$schluessel] = array(
            'schluessel' => $schluessel,
            'name'       => $raum !== '' ? $name . ' (' . $raum . ')' : $name,
            'alias'      => $alias,
            'thema'      => sp_lox_schluessel($raum) . '/' . sp_lox_schluessel($name),
            'raum'       => $raum,
            'art'        => $arten[$typ],
            'typ'        => $typ,
        );
    }
    ksort($vorschlaege);
    return array(1, sprintf(sp_t('LOXIMP.GEFUNDEN'), count($vorschlaege),
                            count($d['controls'])), $vorschlaege);
}

/** Aus einem Anzeigenamen einen Schluessel machen - dieselbe Einebnung wie im Dienst. */
function sp_lox_schluessel($text)
{
    $t = strtolower(trim((string) $text));
    $t = strtr($t, array('ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue', 'ß' => 'ss'));
    $t = preg_replace('/[^a-z0-9]+/', '_', $t);
    return trim((string) $t, '_');
}

function sp_lox_vorschlaege_ablegen($v)
{
    return sp_json_schreiben(sp_paths()['datadir'] . '/vorschlaege.json',
                             array('ts' => time(), 'ziele' => $v));
}

function sp_lox_vorschlaege()
{
    $d = sp_json_lesen(sp_paths()['datadir'] . '/vorschlaege.json');
    return isset($d['ziele']) && is_array($d['ziele']) ? $d['ziele'] : array();
}

function sp_lox_vorschlaege_weg()
{
    @unlink(sp_paths()['datadir'] . '/vorschlaege.json');
}
