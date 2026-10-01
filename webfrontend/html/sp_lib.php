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
 * config/plugins, webfrontend UND config/system/general.json enthaelt. Das
 * trifft die uebliche Installation genauso wie eine an einem anderen Ort -
 * und es trifft auch den Fall, dass das Plugin noch als entpacktes Archiv
 * daliegt (dann findet es nichts und gibt einen Leerstring zurueck, was der
 * Aufrufer ohnehin abfangen muss).
 *
 * general.json ist die dritte Bedingung (Regeln/06): ein Rest aus
 * Pruefstaenden traegt config/plugins und webfrontend, eine LoxBerry-Wurzel
 * immer auch general.json. Bis 0.11.8 fehlte sie; gemessen am 18.09.2026 in
 * WSL (Pruefung-Sprachsteuerung-0.11.9, messe_h2.sh, Faelle C1-C4): aus einem
 * Archiv unter einem solchen Rest las die Bibliothek dessen Konfiguration,
 * schrieb dort aus der Zweitschrift eine sprachsteuerung.json und lud dessen
 * Sprachdatei. Bauart: Govee 0.9.20 (gv_lib.php).
 *
 * Der Name traegt kein Plugin-Kuerzel und ist deshalb abgesichert: zwei
 * Bibliotheken landen nie im selben Prozess, aber die Pruefung kostet nichts.
 */
if (!function_exists('lb_wurzel_ermitteln')) {
    function lb_wurzel_ermitteln()
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
}

/* Die Wurzel: $LBHOMEDIR, wenn darunter config/plugins liegt, sonst die
 * Suche aufwaerts (mit general.json). Findet sie nichts, gibt es KEINE
 * Wurzel - sp_paths() arbeitet dann im Archivmodus auf dem eigenen Ordner.
 *
 * Bis 0.11.8 stand in sp_paths() und sp_t()
 *     foreach (array(lb_wurzel_ermitteln(), '/home/loxberry/loxberry') as $k)
 * hinter einem $LBHOMEDIR, das nur ein Verzeichnis sein musste. Gemessen am
 * 18.09.2026 in WSL (Pruefung-Sprachsteuerung-0.11.9, messe_h2.sh): aus
 * einem ausgepackten Archiv las die Bibliothek die Konfiguration eines
 * Baums unter /home/loxberry/loxberry, schrieb dort aus dessen Zweitschrift
 * eine sprachsteuerung.json und lud dessen Sprachdatei (B1-B4); ein auf
 * nichts zeigendes $LBHOMEDIR blieb als Wurzel stehen (B5), ein beliebiges
 * Verzeichnis wurde Wurzel (B6, B8). Unter /home/loxberry/loxberry liegt auf
 * einem LoxBerry keine Wurzel (Regeln/06) - der feste Pfad traf nie die
 * eigene Anlage.
 *
 * Fuer $LBHOMEDIR wird config/plugins verlangt, nicht general.json: die
 * Attrappe Werkzeuge/lb, gegen die rendern.py und wirkungstest.py die
 * Oberflaeche laufen lassen, traegt keine general.json. */
function sp_lbhome()
{
    $home = getenv('LBHOMEDIR');
    if ($home && is_dir($home . '/config/plugins')) {
        return $home;
    }
    return lb_wurzel_ermitteln();
}

function sp_paths()
{
    static $p = null;
    if ($p !== null) {
        return $p;
    }
    $home = sp_lbhome();
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
    $lbp = basename(rtrim((string) getenv('LBPPLUGINDIR'), '/'));
    $lbp_gilt = ($lbp !== '' && !in_array($lbp, array('.', '/', 'html', 'bin', 'plugins'), true));
    if ($lbp_gilt) {
        $dir = $lbp;
    } elseif ($dir === '' || $dir === '.' || $dir === '/' || $dir === 'html') {
        $dir = 'sprachsteuerung';
    }
    /* Archivmodus. Die Pfade DER ANLAGE gelten nur, wenn diese Bibliothek
     * dort installiert liegt (<Wurzel>/webfrontend/html/plugins/<ordner>,
     * physisch verglichen) oder der Aufrufer Wurzel UND Ordner ausdruecklich
     * nennt ($LBHOMEDIR und $LBPPLUGINDIR - so arbeiten die Pruefwerkzeuge mit
     * ihrer Attrappe). Sonst ist das ein ausgepacktes Archiv oder ein
     * Pruefordner, und alles bleibt in dessen eigenem Ordner.
     *
     * Bis 0.11.9 nahm eine Archiv-Bibliothek unterhalb einer echten Wurzel
     * diese Wurzel und den festen Namen 'sprachsteuerung' - ohne Umgebung
     * ebenso wie mit $LBHOMEDIR allein, wie es am Geraet in /etc/environment
     * steht; mit $LBPPLUGINDIR allein hielt sp_dienst('stop') den Dienst der
     * Anlage an (gemessen am 25.09.2026 in WSL,
     * Pruefung-Sprachsteuerung-0.11.10, Faelle A1, A2, A12). Bauart
     * Spotpreis-Tibber 0.9.19 (tb_paths()). */
    $gefunden = (string) $home;
    if ($home) {
        $soll = @realpath($home . '/webfrontend/html/plugins/' . basename(__DIR__));
        $ist = @realpath(__DIR__);
        $installiert = ($soll !== false && $ist !== false && $soll === $ist);
        $ausdruecklich = $lbp_gilt
            && rtrim((string) $home, '/') === rtrim((string) getenv('LBHOMEDIR'), '/');
        if (!$installiert && !$ausdruecklich) { $home = ''; }
    }
    if ($home) {
        $p = array(
            'home' => $home, 'plugin' => $dir, 'archiv' => '',
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
            // Die gefundene Wurzel, wenn diese Datei NICHT darin installiert
            // liegt (Archivmodus) - fuer die Meldung; sonst leer.
            'archiv' => $gefunden,
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
 * Wird dieses MQTT-Thema vom Broker zurueckbehalten?
 *
 * Die Tabelle steht in templates/vorgaben.json, damit Dienst und Oberflaeche
 * dieselbe Auskunft geben - dasselbe Motiv wie bei den Vorgaben selbst. Hier
 * wird nur ANGEZEIGT; gesendet wird im Dienst (mqtt_retain_fuer()).
 *
 * Ohne Eintrag: nein. Fehlt die Datei, lautet die Antwort ueberall nein, und
 * das ist die harmlose Richtung.
 */
function sp_retain_fuer($schluessel)
{
    $d = sp_vorgabendatei();
    $r = isset($d['retain']) && is_array($d['retain']) ? $d['retain'] : array();
    $themen = isset($r['themen']) && is_array($r['themen']) ? $r['themen'] : array();
    $k = (string) $schluessel;
    if (array_key_exists($k, $themen)) {
        return (bool) $themen[$k];
    }
    $endungen = isset($r['endungen']) && is_array($r['endungen']) ? $r['endungen'] : array();
    foreach ($endungen as $endung => $wie) {
        $e = (string) $endung;
        if ($e !== '' && strlen($k) >= strlen($e)
            && substr($k, -strlen($e)) === $e) {
            return (bool) $wie;
        }
    }
    return false;
}

/**
 * Traegt diese Datei ueberhaupt etwas?
 *
 * Nicht "ist sie leer?", sondern "laesst sie sich als JSON-Objekt mit
 * mindestens einem Schluessel lesen?". Der Unterschied ist gemessen
 * (17.09.2026, WSL): eine abgeschnittene sprachsteuerung.json - nicht leer,
 * nicht "{}", aber unlesbar - ging bis 0.11.6 an der Selbstheilung vorbei.
 * json_decode lieferte null, sp_json_lesen() daraus array(), sp_config()
 * gab die blanken Vorgaben zurueck, sp_token() wuerfelte ein NEUES
 * Aktionstoken und sp_config_speichern() schrieb es samt Zweitschrift: die
 * Zweitschrift mit dem alten Token war weg, alle Loxone-Aufrufe scheiterten.
 * Dieselbe Bauart wie Intercom 2.2.10 und GardenaSmartSystem 1.2.8.
 *
 * Rueckgabe: die gelesenen Daten oder null, wenn die Datei nichts traegt.
 */
function sp_inhalt_oder_null($pfad)
{
    if (!is_file($pfad)) { return null; }
    $roh = trim((string) @file_get_contents($pfad));
    if ($roh === '') { return null; }
    $d = json_decode($roh, true);
    if (!is_array($d) || $d === array()) { return null; }
    return $d;
}

/**
 * Traegt diese Konfiguration das, was nur sie tragen kann?
 *
 * Das Aktionstoken. Es steht in JEDER Loxone-Adresse dieses Plugins; geht es
 * verloren, scheitern alle virtuellen Eingaenge im Miniserver, und es gibt
 * keinen Weg, es zurueckzurechnen. Alles andere laesst sich in der
 * Oberflaeche noch einmal eintragen.
 *
 * Eine Konfiguration OHNE Token gibt es auf keinem Weg der Oberflaeche:
 * sp_token() fuellt es beim ersten Seitenaufbau. Steht dort keines, ist die
 * Datei nicht aus einem gespeicherten Stand hervorgegangen - dann wird aus
 * der Zweitschrift geheilt, statt ein NEUES Token zu wuerfeln und damit die
 * Anbindung an Loxone stillzulegen. Bauart: Intercom 2.2.11
 * (ic_config_hat_inhalt(): Token oder Station).
 */
function sp_config_hat_inhalt($c)
{
    return is_array($c) && $c !== array()
        && trim((string) (isset($c['aktionstoken']) ? $c['aktionstoken'] : '')) !== '';
}

/**
 * Die Konfiguration lesen.
 *
 * $erzeugen = false schaltet JEDEN Schreibvorgang ab. Der unangemeldete
 * Endpunkt ruft so auf: wer sich nicht ausweisen kann, legt nichts an - auch
 * nichts Harmloses. Bei EVCC hinterliess ein einziger, korrekt mit 403
 * abgewiesener Aufruf eine frisch erzeugte Konfiguration samt Token und
 * Zweitschrift.
 *
 * Geheilt wird nach INHALT (sp_inhalt_oder_null(), sp_config_hat_inhalt()),
 * nicht nach Dateigroesse, und nur aus einer Zweitschrift, die selbst
 * Inhalt traegt: ein Stand ohne Inhalt darf keinen anderen ersetzen - in
 * keine der beiden Richtungen. Was vorher in der Datei stand, wird nicht
 * weggeworfen, sondern liegt als <datei>.kaputt daneben (0600, es koennen
 * Zugangsdaten darin stehen).
 */
function sp_config($erzeugen = true)
{
    $p = sp_paths();
    if ($erzeugen && !sp_config_hat_inhalt(sp_inhalt_oder_null($p['config']))
        && sp_config_hat_inhalt(sp_inhalt_oder_null($p['sicherung']))) {
        @mkdir($p['configdir'], 0775, true);
        $alt = is_file($p['config']) ? (string) @file_get_contents($p['config']) : '';
        $rest = preg_replace('/\s+/', '', $alt);
        if ($rest !== '' && $rest !== '{}' && $rest !== '[]') {
            @copy($p['config'], $p['config'] . '.kaputt');
            @chmod($p['config'] . '.kaputt', 0600);
        }
        if (@copy($p['sicherung'], $p['config'])) {
            @chmod($p['config'], 0600);
            sp_log('Die Konfiguration trug kein Aktionstoken und wurde aus der Zweitschrift '
                . 'wiederhergestellt: ' . $p['sicherung']
                . ($rest !== '' && $rest !== '{}' && $rest !== '[]'
                    ? ' (der vorherige Inhalt liegt unter ' . $p['config'] . '.kaputt)' : '') . '.');
        }
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
    // Ansage-1: ab Werk "aus". Ein gespeicherter Block OHNE Modus stammt von
    // vor dieser Fassung, als "musicserver" die Vorgabe war - er behaelt sie,
    // sonst schaltete das Update eine eingerichtete Ansage ab. Dieselbe Regel
    // in config() (bin/sprachsteuerung_dienst.py).
    if (is_array(isset($cfg['tts']) ? $cfg['tts'] : null) && !array_key_exists('mode', $cfg['tts'])) {
        $tts['mode'] = 'musicserver';
    }
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
    $sp_skalar = function ($k) use ($tts) {
        return (isset($tts[$k]) && is_scalar($tts[$k])) ? (string) $tts[$k] : '';
    };
    $tts['cc_praefix'] = trim($sp_skalar('cc_praefix'), '/');
    if (!sp_cc_praefix_ok($tts['cc_praefix'])) {
        $tts['cc_praefix'] = (string) $vor['tts']['cc_praefix'];
    }
    // Ein unbrauchbares Ziel wird NICHT zu "alle" - sonst spraeche das ganze
    // Haus, weil ein Name nicht stimmt. Leer heisst: kein Ziel, Rueckfall.
    $tts['cc_ziel'] = $sp_skalar('cc_ziel');
    if (!sp_cc_ziel_ok($tts['cc_ziel'])) { $tts['cc_ziel'] = ''; }
    $tts['alexa_geraet'] = trim($sp_skalar('alexa_geraet'));
    if (!sp_alexa_geraet_ok($tts['alexa_geraet'])) { $tts['alexa_geraet'] = ''; }
    $tts['alexa_token'] = $sp_skalar('alexa_token');
    if (!sp_alexa_token_ok($tts['alexa_token'])) { $tts['alexa_token'] = ''; }
    $sp_laut = $sp_skalar('alexa_laut');
    $tts['alexa_laut'] = preg_match('/^[0-9]{1,3}$/', $sp_laut) && (int) $sp_laut <= 100 ? (int) $sp_laut : -1;
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
        // Diese Funktion liest die Datei ROH, ohne die Selbstheilung aus
        // sp_config(). Traegt die Datei nichts Lesbares, stuenden hier nur
        // die Vorgaben - und die duerfen die Zweitschrift nicht ersetzen.
        sp_zweitschrift_ziehen($p['config'], $p['sicherung'], $roh,
                               array('aktionstoken'), 0600);
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
    // Die Zweitschrift wird NICHT erneuert, wenn der neue Stand das
    // Aktionstoken nicht traegt, das dort steht (sp_zweitschrift_fehlt()).
    // Gespeichert wird trotzdem - nur der Rueckweg bleibt stehen.
    sp_zweitschrift_ziehen($p['config'], $p['sicherung'], (array) $cfg,
                           array('aktionstoken'), 0600);
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
    // Wie bei sp_config(): nach INHALT entscheiden. Eine Satzdatei ohne die
    // beiden Schluessel 'regeln' und 'ziele' ist keine Satzdatei - eine
    // abgeschnittene erst recht nicht. Ein LEERER Regelsatz mit beiden
    // Schluesseln ist dagegen ein gewolltes Loeschen und bleibt stehen.
    $d = sp_inhalt_oder_null($p['saetze']);
    $hat = is_array($d) && (array_key_exists('regeln', $d) || array_key_exists('ziele', $d));
    $z = sp_inhalt_oder_null($p['sicherung_saetze']);
    $z_hat = is_array($z) && (array_key_exists('regeln', $z) || array_key_exists('ziele', $z));
    if ($erzeugen && !$hat && $z_hat) {
        @mkdir($p['configdir'], 0775, true);
        $alt = is_file($p['saetze']) ? (string) @file_get_contents($p['saetze']) : '';
        $rest = preg_replace('/\s+/', '', $alt);
        if ($rest !== '' && $rest !== '{}' && $rest !== '[]') {
            @copy($p['saetze'], $p['saetze'] . '.kaputt');
        }
        if (@copy($p['sicherung_saetze'], $p['saetze'])) {
            sp_log('Die Satzdatei trug keine Regeln und keine Ziele - aus der Zweitschrift '
                . 'wiederhergestellt: ' . $p['sicherung_saetze']
                . ($rest !== '' && $rest !== '{}' && $rest !== '[]'
                    ? ' (der vorherige Inhalt liegt unter ' . $p['saetze'] . '.kaputt)' : '') . '.');
        }
    }
    return sp_json_lesen($p['saetze']);
}

/**
 * Was die Zweitschrift traegt und der neue Stand nicht.
 *
 * Leere Rueckgabe heisst: die Zweitschrift darf erneuert werden. Verglichen
 * wird, ob ein SCHLUESSEL fehlt, nicht ob ein Wert leer ist - eine geleerte
 * Regelliste ('regeln' vorhanden, aber leer) ist ein gewolltes Loeschen und
 * wird nachgezogen; ein fehlender Schluessel heisst, der neue Stand ist gar
 * nicht aus dem gespeicherten hervorgegangen. Ein leeres Aktionstoken gibt
 * es auf keinem Weg der Oberflaeche (sp_token() fuellt es sofort) und gilt
 * deshalb als fehlend.
 *
 * Bauart uebernommen aus Intercom 2.2.11 / GardenaSmartSystem 1.2.9
 * (17.09.2026): eine Zweitschrift MIT Inhalt darf nie durch einen Stand
 * OHNE Inhalt ersetzt werden. Das Speichern selbst wird nicht verhindert -
 * nur der einzige Rueckweg nicht zerstoert; das Protokoll sagt es.
 */
function sp_zweitschrift_fehlt($sicherung, array $neu, array $felder)
{
    $z = sp_inhalt_oder_null($sicherung);
    if ($z === null) { return array(); }
    $fehlt = array();
    foreach ($felder as $feld) {
        if (!array_key_exists($feld, $z)) { continue; }
        $hat_z = is_string($z[$feld]) ? (trim($z[$feld]) !== '') : !empty($z[$feld]);
        if (!$hat_z) { continue; }
        $hat_n = array_key_exists($feld, $neu)
               && (is_string($neu[$feld]) ? (trim($neu[$feld]) !== '') : true);
        if (!$hat_n) { $fehlt[] = $feld; }
    }
    return $fehlt;
}

/** Die Zweitschrift erneuern - oder begruendet nicht. */
function sp_zweitschrift_ziehen($quelle, $ziel, array $neu, array $felder, $rechte = null)
{
    $fehlt = sp_zweitschrift_fehlt($ziel, $neu, $felder);
    if ($fehlt) {
        sp_log('WARNUNG: Die Zweitschrift bleibt unveraendert - der gespeicherte Stand '
            . 'traegt nicht, was dort steht (' . implode(', ', $fehlt) . '): ' . $ziel);
        return false;
    }
    @copy($quelle, $ziel);
    if ($rechte !== null) { @chmod($ziel, $rechte); }
    return true;
}

function sp_saetze_speichern($saetze)
{
    $p = sp_paths();
    if (!sp_json_schreiben($p['saetze'], $saetze)) { return false; }
    sp_zweitschrift_ziehen($p['saetze'], $p['sicherung_saetze'], (array) $saetze,
                           array('regeln', 'ziele'));
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

/**
 * Die Nummer des laufenden Dienstes, oder 0.
 *
 * Bis 0.11.7 entschied hier ein strpos() ueber die ganze Befehlszeile. Das
 * ist eine Teilzeichenkette, kein Argument: gemessen am 18.09.2026 in WSL
 * (Fall oberflaeche_koeder) meldete die Funktion ein "tail -f <dienstpfad>"
 * als laufenden Dienst - und mit ihm die Statuszeile, der Reiter Test und
 * der Endpunkt (OK=1). Ein Editor mit der Datei offen faellt in dieselbe
 * Klasse, und bei einer Zweitinstallation traf der blosse Dateiname auch den
 * Nachbarn.
 *
 * Seit 0.11.8 argumentweise, wie in den Hakenskripten: /proc/<pid>/cmdline
 * trennt die Argumente mit Nullbytes; argv[0] muss ein Python sein und
 * argv[1] genau der eigene Dienstpfad.
 *
 * Diese Funktion schickt kein Signal. Sie ist aber die Vorstufe dazu - die
 * Oberflaeche zeigt nach ihr den Knopf "Anhalten" - und sie beantwortet die
 * Frage, auf die sich Loxone verlaesst.
 */
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
    return sp_ist_dienst($pid) ? $pid : 0;
}

/** Gehoert die Prozessnummer $pid dem eigenen Dienst? Argumentweise. */
function sp_ist_dienst($pid)
{
    $pid = (int) $pid;
    if ($pid <= 0) {
        return false;
    }
    $roh = @file_get_contents('/proc/' . $pid . '/cmdline');
    if ($roh === false || $roh === '') {
        return false;
    }
    // Am Nullbyte trennen, nicht am Leerzeichen: ein Pfad darf Leerzeichen
    // tragen. Das letzte Feld ist nach dem abschliessenden Nullbyte leer.
    $argv = explode("\0", (string) $roh);
    // Genau zwei Argumente: der Dienst traegt argv[0] und argv[1], danach
    // nur das abschliessende Nullbyte (drittes Feld leer). Ein drittes
    // Argument (--selbsttest, --satz, --trocken) ist ein Einmallauf; bis
    // 0.11.9 zeigte die Oberflaeche einen Selbsttest als laufenden Dienst
    // (gemessen am 25.09.2026 in WSL, Pruefung-Sprachsteuerung-0.11.10,
    // Fall D6).
    if (count($argv) !== 3 || $argv[2] !== '') {
        return false;
    }
    // argv[0] ist der Interpreter - nur der Name, der Pfad ist beliebig
    // (venv/bin/python3, /usr/bin/python3.11).
    $a0 = basename($argv[0]);
    if (preg_match('#^python[0-9.]*$#', $a0) !== 1) {
        return false;
    }
    return $argv[1] === sp_paths()['bindir'] . '/sprachsteuerung_dienst.py';
}

function sp_dienst_soll()
{
    return is_file(sp_paths()['datadir'] . '/soll_laufen') ? 1 : 0;
}

/**
 * Leer, wenn diese Bibliothek in einer Installation liegt; sonst die
 * Begruendung, warum ein Knopf nichts schaltet.
 *
 * Aus einem ausgepackten Archiv oder einem Pruefordner (sp_paths() im
 * Archivmodus) wird weder der Dienst noch ein Container angefasst: Container
 * sind rechnerweit, ein "stop" aus einem Archiv traefe die Container der
 * Anlage (Muster 3 der Nachlese 24.09.2026; gemessen am 25.09.2026 in WSL,
 * Pruefung-Sprachsteuerung-0.11.10, Fall A13).
 */
function sp_archiv_verweigert()
{
    $p = sp_paths();
    if ($p['home'] !== '') { return ''; }
    return 'Diese Oberflaeche liegt nicht in einer LoxBerry-Installation'
        . (!empty($p['archiv']) ? ' (die Wurzel ' . $p['archiv'] . ' wurde gefunden, diese Datei liegt aber nicht darin)' : '')
        . ' - ausgepacktes Archiv oder Pruefordner. Es wurde nichts geschaltet.';
}

/** $befehl ist 'start', 'stop' oder 'restart'. Rueckgabe: array(ok, Ausgabe) */
function sp_dienst($befehl)
{
    if (!in_array($befehl, array('start', 'stop', 'restart'), true)) {
        return array(0, 'Unbekannter Befehl.');
    }
    $sp_nein = sp_archiv_verweigert();
    if ($sp_nein !== '') { return array(0, $sp_nein); }
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
            'comment' => 'Aktion für ' . $name . ' - MQTT-Thema '
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
        'comment' => 'Je Ziel ein Texteingang. Diese Bausteine werden über MQTT '
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
function sp_sicherung_bauen($pruefen = true)
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
    // Und das Sprechtoken fuer Alexa-NG (Ansage-1) - es wird wie ein
    // Kennwort behandelt.
    unset($cfg['tts']['alexa_token']);
    $sp_sich = array(
        'art'      => 'sprachsteuerung-sicherung',
        'fassung'  => 1,
        'erzeugt'  => date('c'),
        'hinweis'  => 'Aktionstoken, Miniserver-Adresse, Mikrofon-Schluessel und das '
                    . 'Alexa-Sprechtoken sind absichtlich NICHT enthalten.',
        'config'   => $cfg,
        'saetze'   => sp_saetze(),
    );
    /* X-3: wuerde das eigene Zurueckspielen diese Datei abweisen, sagt es
     * der Kopf - nur der Grund, nie Werte. Geliefert wird sie trotzdem. */
    if ($pruefen) {
        $sp_grund = sp_rueckspiel_altwerte();
        if ($sp_grund !== '') {
            $sp_sich = array('_warnung' => sprintf(sp_t('SICHER.WARN_KOPF'), $sp_grund)) + $sp_sich;
        }
    }
    return json_encode($sp_sich, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

/**
 * X-3: Wuerde die EIGENE Sicherung beim Zurueckspielen abgewiesen? Gebaut
 * wird genau die Datei, die "Sicherung herunterladen" liefert, und durch
 * dieselbe Pruefung geschickt (sp_sicherung_lesen(..., true) - schreibt
 * nichts). Rueckgabe: der Grund, leer heisst "wuerde angenommen".
 */
function sp_rueckspiel_altwerte()
{
    list($ok, $meldung) = sp_sicherung_lesen(sp_sicherung_bauen(false), true);
    return $ok ? '' : (string) $meldung;
}

/**
 * Eine Sicherung pruefen und einspielen.
 *
 * Geprueft wird die FORM, und abgewiesen wird benannt - nicht
 * zurechtgebogen. Rueckgabe: array(ok, Meldung).
 */
function sp_sicherung_lesen($roh, $nur_pruefen = false)
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
    // Ansage-1: die neuen Felder werden geprueft wie im Formular - eine
    // Sicherung mit einem unbrauchbaren Wert wird benannt abgewiesen, nicht
    // zurechtgebogen (Klasse 10). Ein leeres Ziel ist erlaubt: so steht es in
    // der eigenen Sicherung, wenn kein brauchbares gespeichert war.
    if (isset($d['config']['tts']) && is_array($d['config']['tts'])) {
        $t = $d['config']['tts'];
        $falsch = array();
        if (array_key_exists('cc_praefix', $t) && !sp_cc_praefix_ok($t['cc_praefix'])) { $falsch[] = 'tts.cc_praefix'; }
        if (array_key_exists('cc_ziel', $t) && $t['cc_ziel'] !== '' && !sp_cc_ziel_ok($t['cc_ziel'])) { $falsch[] = 'tts.cc_ziel'; }
        if (array_key_exists('alexa_geraet', $t) && !sp_alexa_geraet_ok($t['alexa_geraet'])) { $falsch[] = 'tts.alexa_geraet'; }
        if (array_key_exists('alexa_laut', $t)
            && !(is_int($t['alexa_laut']) && $t['alexa_laut'] >= -1 && $t['alexa_laut'] <= 100)) {
            $falsch[] = 'tts.alexa_laut';
        }
        if ($falsch) {
            return array(0, 'Die Sicherung traegt unbrauchbare Werte (' . implode(', ', $falsch)
                          . '). Es wurde nichts eingespielt.');
        }
    }
    // X-3: bis hierher geprueft, nichts geschrieben.
    if ($nur_pruefen) {
        return array(1, '');
    }
    // Das laufende Token und die Miniserver-Adresse BLEIBEN - sie stehen
    // nicht in der Sicherung, und ein Einspielen darf die Adressen im
    // Miniserver nicht ungueltig machen.
    $alt = sp_config();
    $neu = array_merge($alt, $d['config']);
    $neu['aktionstoken'] = $alt['aktionstoken'];
    $neu['miniserver_url'] = $alt['miniserver_url'];
    // Das Alexa-Sprechtoken steht nicht in der Sicherung (Ansage-1) und
    // bleibt. Ist der tts-Block der Sicherung kein Block, gilt der alte
    // ganz - sonst ginge das Token mit ihm verloren.
    if (!isset($neu['tts']) || !is_array($neu['tts'])) {
        $neu['tts'] = $alt['tts'];
    }
    $neu['tts']['alexa_token'] = $alt['tts']['alexa_token'];
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

/* ================= Zusaetzliche Ansage (Ansage-1, ab Werk aus) =================
 *
 * Chromecast4lox ueber MQTT und Alexa-NG ueber seinen Endpunkt. Der Dienst
 * spricht mit beiden (bin/sprachsteuerung_dienst.py, cc_ansagen() und
 * alexa_ansagen()); die Oberflaeche prueft nur, ob sie da sind - fuer den
 * Reiter Test. Dieselben Pruefregeln wie im Dienst: ein Feld, zwei Sprachen,
 * gleiche Muster.
 * ================================================================== */
define('SP_ALEXANG_ADRESSE', 'http://127.0.0.1/plugins/alexang/index.php');

function sp_cc_praefix_ok($p)
{
    return is_string($p) && (bool) preg_match('#^[A-Za-z0-9_\-]+(/[A-Za-z0-9_\-]+){0,3}$#', $p);
}

/** 1 bis 60 Zeichen, gueltiges UTF-8, ohne Steuerzeichen, / + # und Rand-Leerraum. */
function sp_cc_ziel_ok($z)
{
    return is_string($z) && (bool) preg_match('/^.{1,60}$/us', $z)
        && !preg_match('#[\x00-\x1F\x7F/+\#]#', $z) && trim($z) === $z;
}

function sp_alexa_token_ok($t)
{
    return is_string($t) && (bool) preg_match('/^[A-Za-z0-9_\-]{8,128}$/', $t);
}

/** Leer (= Standardgeraet von Alexa-NG) oder bis 200 Zeichen ohne Steuerzeichen. */
function sp_alexa_geraet_ok($g)
{
    return is_string($g) && ($g === ''
        || ((bool) preg_match('/^.{1,200}$/us', $g) && !preg_match('/[\x00-\x1F\x7F]/', $g)));
}

/** Geraetename -> Themenebene wie thema_saeubern() in Chromecast4lox. */
function sp_cc_thema($name)
{
    $name = (string) $name;
    if (in_array(strtolower($name), array('alle', 'all', '*'), true)) {
        return strtolower($name);
    }
    $name = strtr($name, array("\xC3\xA4" => 'ae', "\xC3\xB6" => 'oe', "\xC3\xBC" => 'ue',
                               "\xC3\x84" => 'Ae', "\xC3\x96" => 'Oe', "\xC3\x9C" => 'Ue',
                               "\xC3\x9F" => 'ss'));
    if (class_exists('Normalizer', false)) {
        $n = Normalizer::normalize($name, Normalizer::FORM_KD);
        if (is_string($n)) {
            $name = preg_replace('/\p{Mn}+/u', '', $n);
        }
    }
    $name = trim((string) preg_replace('/[^A-Za-z0-9_-]+/', '_', (string) $name), '_');
    return $name !== '' ? $name : 'geraet';
}

/**
 * Zurueckbehaltene Werte am Broker lesen: MQTT 3.1.1 von Hand, eine
 * Verbindung, abonnieren, einsammeln, abmelden. Das Kennwort steht nur im
 * CONNECT-Paket und in keiner Meldung.
 * Rueckgabe: array(ok, Fehlertext, array(thema => wert)).
 */
function sp_mqtt_retained_lesen(array $filter, $sekunden = 2.0)
{
    $m = sp_mqtt_zustand();
    $host = trim((string) $m['broker']);
    if ($host === '' || $host === 'localhost') { $host = '127.0.0.1'; }
    $port = (int) $m['brokerport'];
    if ($port < 1 || $port > 65535) {
        return array(false, 'in der general.json steht kein Brokerport', array());
    }
    $fp = @fsockopen($host, $port, $errno, $errstr, 3);
    if (!$fp) {
        return array(false, 'keine Verbindung zum Broker ' . $host . ':' . $port
                            . ' (' . ($errstr !== '' ? $errstr : 'Fehler ' . $errno) . ')', array());
    }
    $zk = function ($s) { return pack('n', strlen($s)) . $s; };
    $laenge = function ($n) {
        $o = '';
        do {
            $b = $n % 128;
            $n = intdiv($n, 128);
            if ($n) { $b |= 128; }
            $o .= chr($b);
        } while ($n);
        return $o;
    };
    $puffer = '';
    $fuellen = function ($n, $bis) use ($fp, &$puffer) {
        while (strlen($puffer) < $n) {
            $rest = $bis - microtime(true);
            if ($rest <= 0) { return false; }
            stream_set_timeout($fp, (int) floor($rest), (int) (($rest - floor($rest)) * 1000000));
            $d = @fread($fp, 4096);
            if ($d === false || $d === '') {
                $meta = stream_get_meta_data($fp);
                if (!empty($meta['timed_out']) || feof($fp)) { return false; }
                continue;
            }
            $puffer .= $d;
        }
        return true;
    };
    $paket = function ($bis) use ($fuellen, &$puffer) {
        if (!$fuellen(2, $bis)) { return null; }
        $i = 1; $n = 0; $mult = 1;
        while (true) {
            if (!$fuellen($i + 1, $bis)) { return null; }
            $b = ord($puffer[$i]);
            $n += ($b & 127) * $mult;
            $mult *= 128;
            $i++;
            if (!($b & 128) || $i > 4) { break; }
        }
        if (!$fuellen($i + $n, $bis)) { return null; }
        $k = ord($puffer[0]);
        $rumpf = (string) substr($puffer, $i, $n);
        $puffer = (string) substr($puffer, $i + $n);
        return array($k, $rumpf);
    };
    $flags = 0x02;
    $nutz = $zk('spui' . getmypid());
    if ((string) $m['user'] !== '') {
        $flags |= 0x80;
        $nutz .= $zk((string) $m['user']);
        if ((string) $m['pw'] !== '') {
            $flags |= 0x40;
            $nutz .= $zk((string) $m['pw']);
        }
    }
    $kopf = $zk('MQTT') . chr(4) . chr($flags) . pack('n', 10);
    $werte = array();
    $fehler = '';
    if (@fwrite($fp, chr(0x10) . $laenge(strlen($kopf) + strlen($nutz)) . $kopf . $nutz) === false) {
        $fehler = 'der Broker nimmt nichts an';
    } else {
        $p = $paket(microtime(true) + 3.0);
        if ($p === null || ($p[0] >> 4) !== 2 || strlen($p[1]) < 2) {
            $fehler = 'der Broker hat die Anmeldung nicht beantwortet';
        } elseif (ord($p[1][1]) !== 0) {
            $fehler = 'der Broker weist die Anmeldung ab (CONNACK ' . ord($p[1][1]) . ')';
        } else {
            $sub = pack('n', 1);
            foreach ($filter as $f) { $sub .= $zk((string) $f) . chr(0); }
            @fwrite($fp, chr(0x82) . $laenge(strlen($sub)) . $sub);
            $ende = microtime(true) + (float) $sekunden;
            $bestaetigt = false;
            while (true) {
                $bis = $bestaetigt ? min($ende, microtime(true) + 0.6) : $ende;
                $p = $paket($bis);
                if ($p === null) { break; }
                $art = $p[0] >> 4;
                if ($art === 9) {
                    $rc = substr($p[1], 2);
                    if (strlen($rc) !== count($filter) || preg_match('/[\x80-\xFF]/', $rc)) {
                        $fehler = 'der Broker hat das Abonnement abgewiesen';
                        break;
                    }
                    $bestaetigt = true;
                } elseif ($art === 3 && strlen($p[1]) >= 2 && ($p[0] & 1)) {
                    $tl = unpack('n', substr($p[1], 0, 2));
                    $tl = (int) $tl[1];
                    $thema = (string) substr($p[1], 2, $tl);
                    $versatz = 2 + $tl + ((($p[0] >> 1) & 3) ? 2 : 0);
                    $werte[$thema] = (string) substr($p[1], $versatz);
                }
            }
            if ($fehler === '' && !$bestaetigt) {
                $fehler = 'der Broker hat das Abonnement nicht bestaetigt';
            }
            @fwrite($fp, chr(0xE0) . chr(0));
        }
    }
    fclose($fp);
    return array($fehler === '', $fehler, $werte);
}

/**
 * POST an Alexa-NG - das Token steht so in keiner Adresse und keinem
 * Zugriffsprotokoll. Ohne $http_response_header (PHP 8.5): die erste Zeile
 * der Antwort sagt alles (SELFTEST;OK=1;... bzw. SPRECHEN;OK=...).
 * Rueckgabe: array(erste Zeile oder '', Fehlertext).
 */
function sp_alexa_rufen(array $felder, $sekunden = 5)
{
    $ctx = stream_context_create(array('http' => array(
        'method'        => 'POST',
        'header'        => "Content-Type: application/x-www-form-urlencoded\r\n",
        'content'       => http_build_query($felder),
        'timeout'       => (float) $sekunden,
        'ignore_errors' => true,
    )));
    $roh = @file_get_contents(SP_ALEXANG_ADRESSE, false, $ctx, 0, 600);
    if ($roh === false) {
        return array('', 'keine Antwort von ' . SP_ALEXANG_ADRESSE);
    }
    $z = preg_split('/\r?\n/', trim((string) $roh));
    return array(trim((string) $z[0]), '');
}

/**
 * Die Zeile "Zusaetzliche Ansage" im Reiter Test: array(Stand, Antwort) oder
 * null (die vier Loxone-Wege prueft der Selbsttest des Dienstes).
 */
function sp_ansage_lage($cfg = null)
{
    if ($cfg === null) { $cfg = sp_config(); }
    $tts = $cfg['tts'];
    $modus = (string) $tts['mode'];
    $weg = (string) $cfg['antwortweg'];
    if ($modus === 'aus') {
        return array(-1, sp_t('TEST.A_ANSAGE_AUS'));
    }
    if (!in_array($modus, array('chromecast', 'alexang'), true)) {
        return null;
    }
    if ($weg === 'satellit') {
        return array(-1, sp_t('TEST.A_ANSAGE_SATELLIT'));
    }
    $rueck = ' ' . sp_t('TEST.A_RUECKFALL');
    if ($modus === 'chromecast') {
        $p = (string) $tts['cc_praefix'];
        $ziel = (string) $tts['cc_ziel'];
        if ($ziel === '') {
            return array(0, sp_t('TEST.A_CC_EINSTELLUNG') . $rueck);
        }
        list($ok, $fehler, $werte) = sp_mqtt_retained_lesen(array($p . '/server/online', $p . '/+/type'));
        if (!$ok) {
            return array(0, sprintf(sp_t('TEST.A_CC_BROKER'), sp_e($fehler)) . $rueck);
        }
        $online = isset($werte[$p . '/server/online']) ? $werte[$p . '/server/online'] : '';
        $geraete = array();
        foreach ($werte as $thema => $w) {
            $rest = substr($thema, strlen($p) + 1);
            if (strpos($thema, $p . '/') === 0 && substr($rest, -5) === '/type'
                && strpos(substr($rest, 0, -5), '/') === false && substr($rest, 0, -5) !== '') {
                $geraete[] = substr($rest, 0, -5);
            }
        }
        sort($geraete);
        $liste = $geraete ? implode(', ', $geraete) : '-';
        if ($online !== '1') {
            return array(0, sprintf(sp_t('TEST.A_CC_FEHLT'), sp_e($p), sp_e($online === '' ? '-' : $online)) . $rueck);
        }
        $gesucht = strtolower(sp_cc_thema($ziel));
        $bekannt = in_array($gesucht, array('alle', 'all', '*'), true);
        foreach ($geraete as $g) {
            if (strtolower($g) === $gesucht) { $bekannt = true; }
        }
        if (!$bekannt) {
            return array(0, sprintf(sp_t('TEST.A_CC_GERAET'), sp_e($ziel), sp_e($liste)) . $rueck);
        }
        $stand = 1;
        $text = sprintf(sp_t('TEST.A_CC_OK'), sp_e($p), sp_e($ziel), sp_e($liste));
    } else {
        if (!sp_alexa_token_ok((string) $tts['alexa_token'])) {
            return array(0, sp_t('TEST.A_ALEXA_TOKEN') . $rueck);
        }
        list($zeile, $fehler) = sp_alexa_rufen(array('selftest' => '1', 'token' => (string) $tts['alexa_token']), 5);
        if (strpos($zeile, 'SELFTEST;OK=1') === 0) {
            $stand = 1;
            $text = sp_t('TEST.A_ALEXA_OK');
        } elseif (strpos($zeile, 'SELFTEST;') === 0) {
            return array(0, sprintf(sp_t('TEST.A_ALEXA_ABGEWIESEN'), sp_e(substr($zeile, 0, 80))) . $rueck);
        } else {
            return array(0, sprintf(sp_t('TEST.A_ALEXA_FEHLT'), sp_e($fehler !== '' ? $fehler : substr($zeile, 0, 80))) . $rueck);
        }
    }
    $letzte = sp_json_lesen(sp_paths()['datadir'] . '/ausgabe.json');
    if (isset($letzte['modus']) && $letzte['modus'] === $modus) {
        $alter = max(0, time() - (int) (isset($letzte['ts']) ? $letzte['ts'] : 0));
        $text .= '<br>' . (!empty($letzte['ok'])
            ? sprintf(sp_t('TEST.A_LETZTE_OK'), $alter)
            : sprintf(sp_t('TEST.A_LETZTE_FEHL'), $alter,
                      sp_e((string) (isset($letzte['meldung']) ? $letzte['meldung'] : ''))));
    }
    return array($stand, $text);
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
        // Die Wurzel aus sp_paths(): so bleibt ein Archiv unter einer echten
        // Wurzel auch hier im eigenen Ordner. Ohne Wurzel NICHTS ab der
        // Laufwerkswurzel: bis 0.11.9 hiess das
        // '' . '/templates/plugins/html/lang', und was dort lag, galt vor den
        // eigenen Sprachdateien (gemessen am 25.09.2026 in WSL im chroot,
        // Pruefung-Sprachsteuerung-0.11.10, Fall P1; dieselbe Stelle fanden
        // Spotpreis-Tibber 0.9.19 und ZendureSolarFlow 0.9.26).
        $home = sp_paths()['home'];
        $ordner = basename(dirname(__FILE__));
        $pfad = $home !== '' ? $home . '/templates/plugins/' . $ordner . '/lang' : '';
        if ($pfad === '' || !is_dir($pfad)) {
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
 * Projekte. Entfernen nimmt nur den Container, nie den Modellordner
 * data/plugins/<ordner>/modelle - darin liegen Gigabyte, die sonst erneut
 * aus dem Netz kommen muessten. (Den Datenordner samt Modellordner raeumt
 * LoxBerry selbst beim Deinstallieren ab; das tut nicht dieses Plugin.)
 *
 * Seit 0.11.11 (Bauliste "Einrichtung", Muster MGiSmart 1.1.18/1.1.19):
 *   - Jeder docker-Aufruf geht argumentweise (proc_open mit einer Liste,
 *     keine Schale) und mit "timeout -k 5": inspect und ps 30 s, start, stop,
 *     restart und rm 60 s. Bis 0.11.10 lief exec('docker ...') ohne Grenze
 *     im Seitenaufruf; ein "docker pull" eines Abbilds von mehreren Gigabyte
 *     hielt die Seite, bis Apache oder PHP abbrach, und der Anwender sah
 *     nichts.
 *   - pull und run laufen nur noch im Hintergrundvorgang
 *     (bin/container_vorgang.php), pull mit 3600 s.
 *   - Angefasst wird nur ein EIGENER Container: er traegt beide Labels
 *     de.loxberry.plugin.folder=<ordner> und de.loxberry.plugin.name=
 *     sprachsteuerung, oder er ist Altbestand ohne diese Labels, heisst genau
 *     sprachsteuerung-<dienst> UND stammt aus dem Abbild, das
 *     templates/modelle.json fuer diesen Dienst nennt (docker inspect).
 *     Jeder andere bleibt stehen, und die Oberflaeche sagt, warum
 *     (sinngemaess ENTSCHEIDUNGEN 2026-09-29 Nr. 9, Docker NG).
 * ================================================================== */

define('SP_CT_ZEIT_LAGE', 10);
define('SP_CT_ZEIT_LESEN', 30);
define('SP_CT_ZEIT_SCHALTEN', 60);
define('SP_CT_ZEIT_PULL', 3600);
define('SP_CT_ZEIT_RUN', 600);
define('SP_CT_LABEL_ORDNER', 'de.loxberry.plugin.folder');
define('SP_CT_LABEL_NAME', 'de.loxberry.plugin.name');
define('SP_CT_NAME', 'sprachsteuerung');

/** Pfad zum docker-Programm, oder ''. */
function sp_docker_bin()
{
    static $pfad = null;
    if ($pfad === null) {
        $out = array();
        @exec('command -v docker 2>/dev/null', $out);
        $k = isset($out[0]) ? trim($out[0]) : '';
        $pfad = ($k !== '' && is_file($k)) ? $k : '';
    }
    return $pfad;
}

/* Die fruehere Funktion, die nur fragte, ob das Programm docker da ist,
 * gibt es seit 0.11.11 nicht mehr: ob docker da ist UND antwortet, sagt
 * sp_docker_lage(). Die blosse Frage stellte nur die alte Anzeige. */

/**
 * docker argumentweise ausfuehren, begrenzt durch "timeout -k 5".
 * Rueckgabe array(rc, stdout, stderr). rc 124 heisst Zeitablauf (137, wenn
 * erst das KILL nach weiteren 5 s griff), 127 docker fehlt. Ohne -k schickt
 * timeout nur SIGTERM; ein docker, der es nicht annimmt, hielte den Aufrufer
 * fest (gemessen im Pruefstand 0.11.8, Fall uninstall_hartnaeckig).
 */
function sp_docker_ruf(array $args, $sekunden)
{
    $bin = sp_docker_bin();
    if ($bin === '' || !function_exists('proc_open')) {
        return array(127, '', 'docker fehlt');
    }
    $cmd = array('timeout', '-k', '5', (string) max(1, (int) $sekunden), $bin);
    foreach ($args as $a) {
        $cmd[] = (string) $a;
    }
    $desk = array(0 => array('file', '/dev/null', 'r'), 1 => array('pipe', 'w'), 2 => array('pipe', 'w'));
    $pipes = array();
    $proc = @proc_open($cmd, $desk, $pipes);
    if (!is_resource($proc)) {
        return array(127, '', 'proc_open gescheitert');
    }
    stream_set_blocking($pipes[1], false);
    stream_set_blocking($pipes[2], false);
    $aus = array(1 => '', 2 => '');
    $offen = array(1 => $pipes[1], 2 => $pipes[2]);
    $runden = 0;
    // Rundenobergrenze und Puffergrenze (Regeln/03, fgets auf einer Pipe).
    while ($offen && $runden < 100000) {
        $runden++;
        $lesen = array_values($offen);
        $w = null;
        $e = null;
        if (@stream_select($lesen, $w, $e, 1) === false) {
            break;
        }
        foreach ($offen as $n => $h) {
            $t = fread($h, 65536);
            if ($t !== false && $t !== '' && strlen($aus[$n]) < 1048576) {
                $aus[$n] .= $t;
            }
            if (feof($h)) {
                fclose($h);
                unset($offen[$n]);
            }
        }
    }
    foreach ($offen as $h) {
        fclose($h);
    }
    $rc = proc_close($proc);
    return array((int) $rc, $aus[1], $aus[2]);
}

/** Hat timeout zugeschlagen? 124 nach TERM, 137 nach dem KILL von -k. */
function sp_ct_zeitablauf($rc)
{
    return $rc === 124 || $rc === 137;
}

/** Einen Text fuer eine Meldung kuerzen, ohne ein UTF-8-Zeichen zu zerschneiden. */
function sp_ct_kurz($text, $laenge = 200)
{
    $text = trim(preg_replace('/\s+/', ' ', (string) $text));
    if (strlen($text) <= $laenge) {
        return $text;
    }
    return rtrim(preg_replace('/[\x80-\xBF]+$/', '', preg_replace('/[\xC0-\xFF]$/', '', substr($text, 0, $laenge)))) . ' ...';
}

/**
 * Ist Docker erreichbar? Rueckgabe array(lage, satz) mit lage
 * ok | fehlt | kein_zugriff | dienst_aus | haengt | fehler.
 * Bauart mg_docker_lage() aus MGiSmart 1.1.18.
 */
function sp_docker_lage($sekunden = SP_CT_ZEIT_LAGE)
{
    if (sp_docker_bin() === '') {
        return array('fehlt', sp_t('CT.DOCKER_FEHLT'));
    }
    list($rc, $out, $err) = sp_docker_ruf(array('info', '--format', '{{.ServerVersion}}'), $sekunden);
    if ($rc === 0) {
        return array('ok', sprintf(sp_t('CT.DOCKER_OK'), trim($out)));
    }
    if (sp_ct_zeitablauf($rc)) {
        return array('haengt', sprintf(sp_t('CT.DOCKER_HAENGT'), (int) $sekunden));
    }
    $t = strtolower($err);
    if (strpos($t, 'permission denied') !== false) {
        return array('kein_zugriff', sp_t('CT.DOCKER_KEIN_ZUGRIFF'));
    }
    if (strpos($t, 'cannot connect') !== false || strpos($t, 'daemon running') !== false) {
        return array('dienst_aus', sp_t('CT.DOCKER_DIENST_AUS'));
    }
    return array('fehler', sprintf(sp_t('CT.DOCKER_FEHLER'), $rc, sp_ct_kurz($err, 200)));
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

/** Der Anzeigename eines Dienstes (dieselben Schluessel wie die Einstellungen). */
function sp_ct_dname($dienst)
{
    return sp_t('EINST.L_' . strtoupper($dienst === 'wakeword' ? 'WAKE' : (string) $dienst));
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

/** Das Abbild eines Dienstes laut templates/modelle.json, oder ''. */
function sp_ct_abbild($dienst)
{
    $tab = sp_modelle();
    return isset($tab['dienste'][$dienst]['abbild']) ? (string) $tab['dienste'][$dienst]['abbild'] : '';
}

/**
 * Einen Abbildnamen vergleichbar machen: docker.io/ und library/ vorne weg,
 * ohne Tag gilt :latest. So heissen 'rhasspy/wyoming-whisper',
 * 'rhasspy/wyoming-whisper:latest' und 'docker.io/rhasspy/wyoming-whisper'
 * gleich; 'ghcr.io/ggml-org/llama.cpp:server' bleibt, wie es ist.
 */
function sp_ct_abbild_norm($a)
{
    $a = trim((string) $a);
    if ($a === '' || strpos($a, '@') !== false) {
        return $a;
    }
    foreach (array('docker.io/library/', 'index.docker.io/library/', 'docker.io/', 'index.docker.io/') as $v) {
        if (strpos($a, $v) === 0) {
            $a = substr($a, strlen($v));
            break;
        }
    }
    $letzt = strrpos($a, '/');
    $name = $letzt === false ? $a : substr($a, $letzt + 1);
    if (strpos($name, ':') === false) {
        $a .= ':latest';
    }
    return $a;
}

/** Traegt der Container BEIDE eigenen Labels dieses Ordners? */
function sp_ct_label_eigen($info, $ordner)
{
    $l = (is_array($info) && isset($info['Config']['Labels']) && is_array($info['Config']['Labels']))
        ? $info['Config']['Labels'] : array();
    return isset($l[SP_CT_LABEL_ORDNER], $l[SP_CT_LABEL_NAME])
        && (string) $l[SP_CT_LABEL_ORDNER] === (string) $ordner
        && (string) $l[SP_CT_LABEL_NAME] === SP_CT_NAME;
}

/**
 * Gehoert dieser Container (docker inspect, erstes Element) dem Plugin?
 * Rueckgabe array(art, grund): art 'label' | 'altbestand' | '' (fremd),
 * grund ein Satz, warum er fremd ist.
 *
 * Nur die EIGENEN Label-Schluessel zaehlen: Abbilder bringen oft eigene
 * Labels mit (org.opencontainers.image.*), die sagen nichts ueber den
 * Eigentuemer.
 */
function sp_ct_eigentum($info, $dienst, $ordner = null)
{
    if ($ordner === null) { $ordner = sp_paths()['plugin']; }
    $l = (is_array($info) && isset($info['Config']['Labels']) && is_array($info['Config']['Labels']))
        ? $info['Config']['Labels'] : array();
    $lo = isset($l[SP_CT_LABEL_ORDNER]) ? (string) $l[SP_CT_LABEL_ORDNER] : '';
    $ln = isset($l[SP_CT_LABEL_NAME]) ? (string) $l[SP_CT_LABEL_NAME] : '';
    $name = ltrim(isset($info['Name']) ? (string) $info['Name'] : '', '/');
    if ($lo !== '' || $ln !== '') {
        if (sp_ct_label_eigen($info, $ordner)) {
            return array('label', '');
        }
        return array('', sprintf(sp_t('CT.FREMD_LABEL'), $name, $lo !== '' ? $lo : '-',
                                 $ln !== '' ? $ln : '-', $ordner));
    }
    if ($name !== sp_container_name($dienst)) {
        return array('', sprintf(sp_t('CT.FREMD_NAME'), $name, sp_container_name($dienst)));
    }
    $ist = isset($info['Config']['Image']) ? (string) $info['Config']['Image'] : '';
    $soll = sp_ct_abbild($dienst);
    if ($soll === '' || sp_ct_abbild_norm($ist) !== sp_ct_abbild_norm($soll)) {
        return array('', sprintf(sp_t('CT.FREMD_ABBILD'), $name, $ist !== '' ? $ist : '-',
                                 $soll !== '' ? $soll : '-'));
    }
    return array('altbestand', '');
}

/** docker inspect eines Containers; null, wenn es ihn nicht gibt oder docker nicht antwortet. */
function sp_ct_inspect($ref, $sekunden = SP_CT_ZEIT_LESEN)
{
    list($rc, $out, ) = sp_docker_ruf(array('inspect', '--type', 'container', (string) $ref), $sekunden);
    if ($rc !== 0) {
        return null;
    }
    $d = json_decode($out, true);
    return (is_array($d) && isset($d[0]) && is_array($d[0])) ? $d[0] : null;
}

/**
 * Den Container eines Dienstes suchen (nach dem Namen) und einordnen.
 * 'fehler' ist gesetzt, wenn docker nicht zu fragen war - dann ist der
 * Zustand UNBEKANNT, nicht "fehlt": sonst legte das Einrichten nach einer
 * Zeitueberschreitung einen zweiten Container an.
 */
function sp_ct_finden($dienst, $sekunden = SP_CT_ZEIT_LESEN)
{
    $erg = array('da' => false, 'eigen' => '', 'grund' => '', 'laeuft' => false, 'status' => '',
                 'id' => '', 'id_voll' => '', 'name' => sp_container_name($dienst), 'fehler' => '');
    list($rc, $out, $err) = sp_docker_ruf(array('inspect', '--type', 'container', sp_container_name($dienst)),
                                          $sekunden);
    if ($rc !== 0) {
        $t = strtolower($err);
        if (sp_ct_zeitablauf($rc)) {
            $erg['fehler'] = sprintf(sp_t('CT.ZEITABLAUF'), 'docker inspect', (int) $sekunden);
        } elseif (strpos($t, 'no such') === false) {
            $erg['fehler'] = sprintf(sp_t('CT.DOCKER_FEHLER'), $rc, sp_ct_kurz($err, 200));
        }
        return $erg;
    }
    $d = json_decode($out, true);
    $info = (is_array($d) && isset($d[0]) && is_array($d[0])) ? $d[0] : null;
    if ($info === null) {
        $erg['fehler'] = sprintf(sp_t('CT.DOCKER_FEHLER'), $rc, sp_ct_kurz($out, 120));
        return $erg;
    }
    list($art, $grund) = sp_ct_eigentum($info, $dienst);
    $s = isset($info['State']) && is_array($info['State']) ? $info['State'] : array();
    $erg['da'] = true;
    $erg['eigen'] = $art;
    $erg['grund'] = $grund;
    $erg['laeuft'] = !empty($s['Running']);
    $erg['status'] = isset($s['Status']) ? (string) $s['Status'] : '';
    $erg['id_voll'] = isset($info['Id']) ? (string) $info['Id'] : '';
    $erg['id'] = substr($erg['id_voll'], 0, 12);
    return $erg;
}

/** Liegt das Abbild schon auf dem LoxBerry? 1 ja, 0 nein, -1 nicht feststellbar. */
function sp_ct_abbild_da($abbild, $sekunden = SP_CT_ZEIT_LESEN)
{
    if ((string) $abbild === '') {
        return -1;
    }
    list($rc, , $err) = sp_docker_ruf(array('image', 'inspect', '--format', '{{.Id}}', (string) $abbild), $sekunden);
    if ($rc === 0) {
        return 1;
    }
    return (!sp_ct_zeitablauf($rc) && stripos($err, 'no such') !== false) ? 0 : -1;
}

/**
 * Das Modell, mit dem ein Dienst angelegt wird: eingestellt vor empfohlen
 * vor Vorgabe. Beim Sprachmodell gibt es keine Vorgabe - '' heisst: kein
 * Sprachmodell vorgesehen.
 */
function sp_ct_modell($dienst, $cfg, $emp)
{
    $emp = is_array($emp) ? $emp : array();
    if ($dienst === 'whisper') {
        $m = trim((string) (isset($cfg['whisper_modell']) ? $cfg['whisper_modell'] : ''));
        if ($m === '' && isset($emp['whisper']['modell'])) { $m = (string) $emp['whisper']['modell']; }
        return $m !== '' ? $m : 'base-int8';
    }
    if ($dienst === 'piper') {
        $m = trim((string) (isset($cfg['piper_stimme']) ? $cfg['piper_stimme'] : ''));
        if ($m === '' && isset($emp['piper']['stimme'])) { $m = (string) $emp['piper']['stimme']; }
        return $m !== '' ? $m : 'de_DE-thorsten-low';
    }
    if ($dienst === 'wakeword') {
        $m = trim((string) (isset($cfg['wakeword']) ? $cfg['wakeword'] : ''));
        return $m !== '' ? $m : 'ok_nabu';
    }
    if ($dienst === 'llm') {
        $m = trim((string) (isset($cfg['llm_modell']) ? $cfg['llm_modell'] : ''));
        if ($m === '' && !empty($emp['llm']) && isset($emp['llm']['quelle'])) { $m = (string) $emp['llm']['quelle']; }
        return $m;
    }
    return '';
}

/**
 * Die Argumente von "docker run" fuer einen Dienst, argumentweise; array(),
 * wenn fuer diesen Dienst nichts anzulegen ist (Sprachmodell ohne Modell).
 *
 * Absichtlich OHNE --network=host: diese Dienste brauchen kein Wirtsnetz,
 * eine Portweiterleitung genuegt. Das haelt sie vom uebrigen Netz fern.
 *
 * $fuer_extern = true liefert die Zeile zum Mitnehmen auf einen ANDEREN
 * Rechner: der Port wird ans Netz gebunden (sonst kaeme der LoxBerry nicht
 * heran), der Modellordner ist ein neutraler Pfad, und die Labels fehlen -
 * sie sagen dort nichts. Diese Zeile wird NIE ausgefuehrt, nur angezeigt.
 */
function sp_ct_run_liste($dienst, $cfg = null, $emp = null, $fuer_extern = false)
{
    if ($cfg === null) { $cfg = sp_config(); }
    $p = sp_paths();
    $tab = sp_modelle();
    $d = isset($tab['dienste'][$dienst]) ? $tab['dienste'][$dienst] : null;
    if ($d === null || !isset($d['abbild'], $d['port'])) { return array(); }
    $modell = sp_ct_modell($dienst, $cfg, $emp);
    if ($modell === '') { return array(); }
    $ordner = $fuer_extern ? '/opt/sprachsteuerung/modelle' : $p['datadir'] . '/modelle';
    $port = (int) $d['port'];
    $a = array('run', '-d', '--name', sp_container_name($dienst), '--restart=unless-stopped',
               '-p', ($fuer_extern ? '' : '127.0.0.1:') . $port . ':' . $port,
               '-v', $ordner . '/' . $dienst . ':/data');
    if (!$fuer_extern) {
        $a[] = '--label';
        $a[] = SP_CT_LABEL_ORDNER . '=' . $p['plugin'];
        $a[] = '--label';
        $a[] = SP_CT_LABEL_NAME . '=' . SP_CT_NAME;
    }
    $a[] = (string) $d['abbild'];
    if ($dienst === 'whisper') {
        $sprache = isset($cfg['sprache']) ? (string) $cfg['sprache'] : 'de';
        array_push($a, '--model', $modell, '--language', $sprache);
    } elseif ($dienst === 'piper') {
        array_push($a, '--voice', $modell);
    } elseif ($dienst === 'wakeword') {
        array_push($a, '--preload-model', $modell);
    } elseif ($dienst === 'llm') {
        // llama.cpp laedt das Modell selbst von HuggingFace, wenn -hf gesetzt ist.
        array_push($a, '-hf', $modell, '--host', '0.0.0.0', '--port', (string) $port, '-c', '2048');
    }
    return $a;
}

/** Ein Argument fuer die ANZEIGE schreiben (einfach gequotet, wo noetig). */
function sp_ct_zeigen($a)
{
    $a = (string) $a;
    if ($a !== '' && preg_match('#^[A-Za-z0-9_./:=@,+-]+\z#', $a)) {
        return $a;
    }
    return "'" . str_replace("'", "'\\''", $a) . "'";
}

/**
 * Die Aufrufzeile fuer einen Dienst - fuer die Anzeige (ohne "docker"
 * davor). Seit 0.11.11 aus derselben Liste, die der Hintergrundvorgang
 * ausfuehrt; bis 0.11.10 gab es dafuer eine eigene Zeichenkette, und
 * "Anlegen" benutzte sie ohne die Empfehlung, waehrend die Anzeige sie mit
 * Empfehlung zeigte.
 */
function sp_container_befehl($dienst, $cfg = null, $emp = null, $fuer_extern = false)
{
    $liste = sp_ct_run_liste($dienst, $cfg, $emp, $fuer_extern);
    if (!$liste) { return ''; }
    return implode(' ', array_map('sp_ct_zeigen', $liste));
}

/**
 * Das Sprachmodell ist ausgeschaltet (llm_ein=0)? Dann legt der Knopf
 * "Sprachdienste einrichten" es NICHT an, auch wenn eines empfohlen ist - wer
 * es ausgeschaltet hat, bekommt keine 1 bis 5 GB heruntergeladen
 * (Entscheidung des Hausherrn, 30.09.2026). Die Einzelknoepfe bleiben nutzbar.
 */
function sp_ct_llm_aus($dienst, $cfg)
{
    return $dienst === 'llm' && empty($cfg['llm_ein']);
}

/**
 * Was der Knopf "Sprachdienste einrichten" mit jedem Dienst tun wird -
 * ohne docker zu fragen, fuer die Anzeige vor dem Druecken.
 * Rueckgabe: je Dienst array(dienst, art, modell, host, port) mit art
 * 'einrichten' | 'ausgelagert' | 'ausgeschaltet' | 'kein_modell'.
 */
function sp_ct_vorschau($cfg, $emp)
{
    $aus = array();
    foreach (sp_dienste() as $d) {
        list($host, $port) = sp_dienst_ziel($d, $cfg);
        $art = 'einrichten';
        if (!sp_ist_lokal($host)) {
            $art = 'ausgelagert';
        } elseif (sp_ct_llm_aus($d, $cfg)) {
            $art = 'ausgeschaltet';
        } elseif (!sp_ct_run_liste($d, $cfg, $emp, false)) {
            $art = 'kein_modell';
        }
        $aus[] = array('dienst' => $d, 'art' => $art, 'modell' => sp_ct_modell($d, $cfg, $emp),
                       'host' => $host, 'port' => $port);
    }
    return $aus;
}

/** Ein Satz je Zeile der Vorschau. */
function sp_ct_vorschau_satz($z)
{
    if ($z['art'] === 'ausgelagert') {
        return sprintf(sp_t('CT.P_AUSGELAGERT'), sp_ct_dname($z['dienst']), $z['host'] . ':' . $z['port']);
    }
    if ($z['art'] === 'ausgeschaltet') {
        return sprintf(sp_t('CT.P_AUSGESCHALTET'), sp_ct_dname($z['dienst']));
    }
    if ($z['art'] === 'kein_modell') {
        return sprintf(sp_t('CT.P_KEIN_MODELL'), sp_ct_dname($z['dienst']));
    }
    return sprintf(sp_t('CT.P_EINRICHTEN'), sp_ct_dname($z['dienst']), $z['modell'],
                   sp_ct_abbild($z['dienst']));
}

/* ---------------- Der Hintergrundvorgang ---------------- */

function sp_ct_vorgang_datei()
{
    return sp_paths()['datadir'] . '/container_vorgang.json';
}

function sp_ct_vorgang_schreiben(array $d)
{
    return sp_json_schreiben(sp_ct_vorgang_datei(), $d, 0600);
}

/** Das Programm des Hintergrundvorgangs (installiert unter bin/plugins/<ordner>/). */
function sp_ct_vorgang_programm()
{
    return sp_paths()['bindir'] . '/container_vorgang.php';
}

/** Laeuft der Prozess $pid wirklich als dieser Hintergrundvorgang? Argumentweise. */
function sp_ct_vorgang_prozess($pid)
{
    $pid = (int) $pid;
    if ($pid <= 0 || !is_readable('/proc/' . $pid . '/cmdline')) {
        return false;
    }
    $a = explode("\0", (string) @file_get_contents('/proc/' . $pid . '/cmdline'));
    return isset($a[1]) && $a[1] === sp_ct_vorgang_programm()
        && preg_match('#(^|/)php[0-9.]*\z#', (string) $a[0]) === 1;
}

/**
 * Der Stand des Hintergrundvorgangs. zustand: keiner | gestartet | laeuft |
 * fertig | fehler | abgebrochen. "abgebrochen": die Datei sagt "laeuft",
 * aber der Prozess ist fort - oder er ist nach 20 s nie angelaufen.
 */
function sp_ct_vorgang()
{
    $d = sp_json_lesen(sp_ct_vorgang_datei());
    if (!isset($d['zustand']) || !is_string($d['zustand'])) {
        return array('zustand' => 'keiner');
    }
    $d += array('vorgang' => '', 'dienst' => '', 'start' => 0, 'pid' => 0, 'meldung' => '',
                'schritt' => '', 'schritt_nr' => 0, 'schritte' => 0, 'ende' => 0);
    if ($d['zustand'] === 'laeuft' && !sp_ct_vorgang_prozess($d['pid'])) {
        $d['zustand'] = 'abgebrochen';
    }
    if ($d['zustand'] === 'gestartet' && time() - (int) $d['start'] > 20) {
        $d['zustand'] = 'abgebrochen';
    }
    return $d;
}

/**
 * Einen Hintergrundvorgang starten: 'einrichten' (alle Dienste), 'holen'
 * oder 'anlegen' (ein Dienst). Kein Warten im Seitenaufbau: die Seite zeigt
 * danach "wird eingerichtet ... Schritt x von y, seit N s" und laedt sich
 * neu, solange er laeuft. Zweimal starten geht nicht: Pruefen und Eintragen
 * stehen unter einer Sperre, die VOR dem Abzweigen wieder freigegeben wird -
 * eine offene Sperre vererbte sich sonst an den Kindprozess (Gedaechtnis
 * "Sperre vererbt sich an Kinder").
 * Rueckgabe array(ok, satz).
 */
function sp_ct_vorgang_starten($auftrag, $dienst = '')
{
    if (!in_array($auftrag, array('einrichten', 'holen', 'anlegen'), true)) {
        return array(false, sp_t('DIENST.FEHLER_BEFEHL'));
    }
    if ($auftrag === 'einrichten') {
        $dienst = '';
    } elseif (!in_array($dienst, sp_dienste(), true)) {
        return array(false, sp_t('DIENST.FEHLER_BEFEHL'));
    }
    $nein = sp_archiv_verweigert();
    if ($nein !== '') {
        return array(false, $nein);
    }
    if ($dienst !== '') {
        list($host, $port) = sp_dienst_ziel($dienst);
        if (!sp_ist_lokal($host)) {
            return array(false, sprintf(sp_t('CT.P_AUSGELAGERT'), sp_ct_dname($dienst), $host . ':' . $port));
        }
    }
    $prog = sp_ct_vorgang_programm();
    if (!is_file($prog) || !function_exists('proc_open')) {
        return array(false, sprintf(sp_t('CT.VORGANG_FEHLT'), $prog));
    }
    $p = sp_paths();
    if (!is_dir($p['datadir'])) {
        @mkdir($p['datadir'], 0775, true);
    }
    $sperre = @fopen($p['datadir'] . '/container_vorgang.lock', 'c');
    if ($sperre === false) {
        return array(false, sp_t('CT.VORGANG_DATEI'));
    }
    if (!flock($sperre, LOCK_EX | LOCK_NB)) {
        fclose($sperre);
        return array(false, sp_t('CT.VORGANG_LAEUFT_SCHON'));
    }
    $v = sp_ct_vorgang();
    $frei = !in_array($v['zustand'], array('gestartet', 'laeuft'), true);
    $geschrieben = $frei && sp_ct_vorgang_schreiben(array('vorgang' => $auftrag, 'dienst' => $dienst,
        'zustand' => 'gestartet', 'start' => time(), 'pid' => 0, 'schritt' => '', 'schritt_nr' => 0,
        'schritte' => 0, 'meldung' => ''));
    flock($sperre, LOCK_UN);
    fclose($sperre);
    if (!$frei) {
        return array(false, sp_t('CT.VORGANG_LAEUFT_SCHON'));
    }
    if (!$geschrieben) {
        return array(false, sp_t('CT.VORGANG_DATEI'));
    }
    $desk = array(0 => array('file', '/dev/null', 'r'), 1 => array('file', '/dev/null', 'w'),
                  2 => array('file', '/dev/null', 'w'));
    $pipes = array();
    $args = array('sh', '-c', 'setsid "$0" "$@" </dev/null >/dev/null 2>&1 &', 'php', $prog, $auftrag);
    if ($dienst !== '') {
        $args[] = $dienst;
    }
    // setsid loest den Vorgang von Apache; "&" laesst die Schale sofort enden.
    $proc = @proc_open($args, $desk, $pipes);
    if (!is_resource($proc)) {
        sp_ct_vorgang_schreiben(array('vorgang' => $auftrag, 'dienst' => $dienst, 'zustand' => 'fehler',
            'start' => time(), 'ende' => time(), 'pid' => 0, 'meldung' => sp_t('CT.VORGANG_START')));
        return array(false, sp_t('CT.VORGANG_START'));
    }
    proc_close($proc);
    sp_log('Container: Vorgang "' . $auftrag . ($dienst !== '' ? ' ' . $dienst : '') . '" gestartet.');
    // Gemeldet wird "angelaufen" erst, wenn der Vorgang seine Prozessnummer
    // eingetragen hat - nicht auf den Rueckgabewert der Schale.
    for ($i = 0; $i < 20; $i++) {
        $v = sp_ct_vorgang();
        if ($v['zustand'] !== 'gestartet') {
            return array(true, sp_t('CT.VORGANG_GESTARTET'));
        }
        usleep(100000);
    }
    return array(true, sp_t('CT.VORGANG_NOCH_NICHT'));
}

/** Ein Satz zum Stand des Vorgangs, fuer Seite und Reiter Test. */
function sp_ct_vorgang_satz($v, $jetzt = null)
{
    if ($jetzt === null) { $jetzt = time(); }
    $seit = max(0, $jetzt - (int) (isset($v['start']) ? $v['start'] : $jetzt));
    $z = isset($v['zustand']) ? $v['zustand'] : 'keiner';
    $dn = (isset($v['dienst']) && $v['dienst'] !== '') ? sp_ct_dname($v['dienst']) : '-';
    $schritt = isset($v['schritt']) && $v['schritt'] !== ''
        ? sp_t('CT.S_' . strtoupper((string) $v['schritt'])) : '-';
    if ($z === 'gestartet') {
        return sprintf(sp_t('CT.V_GESTARTET'), $seit);
    }
    if ($z === 'laeuft') {
        if ((int) $v['schritte'] > 0 && (int) $v['schritt_nr'] > 0) {
            return sprintf(sp_t('CT.V_LAEUFT'), (int) $v['schritt_nr'], (int) $v['schritte'],
                           $schritt, $dn, $seit);
        }
        return sprintf(sp_t('CT.V_PLANT'), $seit);
    }
    if ($z === 'abgebrochen') {
        if ((int) $v['schritte'] < 1 || (int) $v['schritt_nr'] < 1) {
            return sp_t('CT.V_ABGEBROCHEN_PLAN');
        }
        return sprintf(sp_t('CT.V_ABGEBROCHEN'), (int) $v['schritt_nr'], (int) $v['schritte'], $schritt, $dn);
    }
    $wann = (int) (isset($v['ende']) ? $v['ende'] : 0) > 0 ? date('d.m.Y H:i', (int) $v['ende']) : '-';
    if ($z === 'fertig') {
        return sprintf(sp_t('CT.V_FERTIG'), $wann) . ' ' . (string) $v['meldung'];
    }
    if ($z === 'fehler') {
        return sprintf(sp_t('CT.V_FEHLER'), $wann) . ' ' . (string) $v['meldung'];
    }
    return '';
}

/** Ein Abbild holen - nur aus dem Hintergrundvorgang. Rueckgabe array(ok, satz). */
function sp_ct_holen($dienst)
{
    $abbild = sp_ct_abbild($dienst);
    if ($abbild === '') {
        return array(false, sprintf(sp_t('CT.KEIN_ABBILD'), sp_ct_dname($dienst)));
    }
    list($rc, , $err) = sp_docker_ruf(array('pull', $abbild), SP_CT_ZEIT_PULL);
    if (sp_ct_zeitablauf($rc)) {
        return array(false, sprintf(sp_t('CT.PULL_ZEITABLAUF'), $abbild, (int) (SP_CT_ZEIT_PULL / 60)));
    }
    if ($rc !== 0) {
        return array(false, sprintf(sp_t('CT.PULL_FEHLER'), $abbild, $rc, sp_ct_kurz($err, 200)));
    }
    // Die Wirkung, nicht den Rueckgabewert: liegt das Abbild jetzt da?
    if (sp_ct_abbild_da($abbild) !== 1) {
        return array(false, sprintf(sp_t('CT.PULL_NICHT_DA'), $abbild));
    }
    return array(true, sprintf(sp_t('CT.GEHOLT'), $abbild));
}

/** Einen Container anlegen (docker run) - nur aus dem Hintergrundvorgang. */
function sp_ct_anlegen($dienst, $cfg, $emp)
{
    $liste = sp_ct_run_liste($dienst, $cfg, $emp, false);
    if (!$liste) {
        return array(false, sprintf(sp_t('CT.P_KEIN_MODELL'), sp_ct_dname($dienst)));
    }
    $ordner = sp_paths()['datadir'] . '/modelle/' . $dienst;
    if (!is_dir($ordner)) {
        @mkdir($ordner, 0775, true);
    }
    list($rc, , $err) = sp_docker_ruf($liste, SP_CT_ZEIT_RUN);
    if (sp_ct_zeitablauf($rc)) {
        return array(false, sprintf(sp_t('CT.RUN_ZEITABLAUF'), sp_container_name($dienst), SP_CT_ZEIT_RUN));
    }
    if ($rc !== 0) {
        return array(false, sprintf(sp_t('CT.RUN_FEHLER'), sp_container_name($dienst), $rc, sp_ct_kurz($err, 200)));
    }
    $f = sp_ct_finden($dienst);
    if (!$f['da'] || $f['eigen'] !== 'label') {
        return array(false, sprintf(sp_t('CT.NICHT_DA'), sp_container_name($dienst)));
    }
    if (!$f['laeuft']) {
        return array(false, sprintf(sp_t('CT.ANGELEGT_STEHT'), sp_container_name($dienst), $f['id'], $f['status']));
    }
    return array(true, sprintf(sp_t('CT.ANGELEGT'), sp_container_name($dienst), $f['id']));
}

/**
 * Starten, Anhalten, Neu starten, Entfernen - nur am EIGENEN Container, mit
 * Zeitgrenze, und gemeldet wird die Wirkung (docker inspect danach), nicht
 * der Rueckgabewert. Rueckgabe array(ok, satz, art) mit art ok | fehler |
 * hinweis ('hinweis': fremder Container, bewusst nichts getan).
 */
function sp_ct_schalten($dienst, $was)
{
    $f = sp_ct_finden($dienst);
    if ($f['fehler'] !== '') {
        return array(0, $f['fehler'], 'fehler');
    }
    if (!$f['da']) {
        return array(0, sprintf(sp_t('CT.NICHT_DA'), sp_container_name($dienst)), 'fehler');
    }
    if ($f['eigen'] === '') {
        return array(0, sprintf(sp_t('CT.NICHT_ANGEFASST'), $f['grund']), 'hinweis');
    }
    $befehle = array('start' => array('start'), 'stop' => array('stop', '-t', '20'),
                     'restart' => array('restart', '-t', '20'), 'entfernen' => array('rm', '-f'));
    if (!isset($befehle[$was])) {
        return array(0, sp_t('DIENST.FEHLER_BEFEHL'), 'fehler');
    }
    $args = $befehle[$was];
    $args[] = $f['id_voll'];
    list($rc, , $err) = sp_docker_ruf($args, SP_CT_ZEIT_SCHALTEN);
    $nach = sp_ct_finden($dienst);
    if ($was === 'entfernen') {
        $wirkt = !$nach['da'] && $nach['fehler'] === '';
    } elseif ($was === 'stop') {
        $wirkt = $nach['da'] && !$nach['laeuft'];
    } else {
        $wirkt = $nach['da'] && $nach['laeuft'];
    }
    if ($wirkt) {
        return array(1, sprintf(sp_t('CT.GESCHALTET_' . strtoupper($was)), $f['name'], $f['id']), 'ok');
    }
    if (sp_ct_zeitablauf($rc)) {
        return array(0, sprintf(sp_t('CT.ZEITABLAUF'), 'docker ' . $args[0], SP_CT_ZEIT_SCHALTEN), 'fehler');
    }
    return array(0, sprintf(sp_t('CT.OHNE_WIRKUNG'), 'docker ' . $args[0], $f['name'], $rc,
                            sp_ct_kurz($err, 200)), 'fehler');
}

/**
 * Die Einzelknoepfe Starten, Anhalten, Neu starten, Entfernen.
 * Rueckgabe array(ok, satz, art) - siehe sp_ct_schalten(). "Abbild holen"
 * und "Anlegen" laufen seit 0.11.11 ueber den Hintergrundvorgang und nicht
 * mehr hier.
 */
function sp_container($dienst, $was)
{
    if (!in_array($dienst, sp_dienste(), true)) {
        return array(0, sp_t('DIENST.FEHLER_BEFEHL'), 'fehler');
    }
    if (!in_array($was, array('start', 'stop', 'restart', 'entfernen'), true)) {
        return array(0, sp_t('CT.NUR_HINTERGRUND'), 'fehler');
    }
    $sp_nein = sp_archiv_verweigert();
    if ($sp_nein !== '') { return array(0, $sp_nein, 'fehler'); }
    // Ausgelagerter Dienst: abweisen statt den falschen Rechner anzufassen.
    list($host, $port) = sp_dienst_ziel($dienst);
    if (!sp_ist_lokal($host)) {
        return array(0, sprintf(sp_t('CT.P_AUSGELAGERT'), sp_ct_dname($dienst), $host . ':' . $port), 'hinweis');
    }
    list($lage, $satz) = sp_docker_lage();
    if ($lage !== 'ok') {
        return array(0, $satz, 'fehler');
    }
    return sp_ct_schalten($dienst, $was);
}

/** Die letzten Zeilen des eigenen Containers, ohne Farbcodes. */
function sp_container_log($dienst, $zeilen = 200)
{
    if (!in_array($dienst, sp_dienste(), true)) {
        return sp_t('DIENST.FEHLER_BEFEHL');
    }
    list($host, $port) = sp_dienst_ziel($dienst);
    if (!sp_ist_lokal($host)) {
        return 'Dieser Dienst laeuft auf ' . $host . ':' . $port . '.' . "\n"
             . 'Sein Protokoll steht dort - hier gibt es keinen Container dazu.';
    }
    list($lage, $satz) = sp_docker_lage();
    if ($lage !== 'ok') {
        return $satz;
    }
    $f = sp_ct_finden($dienst);
    if ($f['fehler'] !== '') { return $f['fehler']; }
    if (!$f['da']) { return sprintf(sp_t('CT.NICHT_DA'), sp_container_name($dienst)); }
    if ($f['eigen'] === '') { return sprintf(sp_t('CT.NICHT_ANGEFASST'), $f['grund']); }
    list($rc, $out, $err) = sp_docker_ruf(array('logs', '--tail', (string) (int) $zeilen, $f['id_voll']),
                                          SP_CT_ZEIT_LESEN);
    if (sp_ct_zeitablauf($rc)) {
        return sprintf(sp_t('CT.ZEITABLAUF'), 'docker logs', SP_CT_ZEIT_LESEN);
    }
    // Programmprotokolle vor dem Auswerten von Farbcodes befreien.
    return preg_replace('/\x1B\[[0-9;]*[A-Za-z]/', '', trim($out . "\n" . $err));
}

/**
 * Der Hintergrundvorgang selbst - gerufen von bin/container_vorgang.php.
 * Er plant zuerst (docker fragen), dann arbeitet er die Schritte ab und
 * schreibt vor jedem Schritt Schritt, Dienst, Beginn, PID und Meldung in
 * die Zustandsdatei; das Plugin-Protokoll nennt jeden Schritt.
 * Rueckgabe: true, wenn jeder vorgesehene Dienst danach laeuft.
 */
function sp_ct_vorgang_ausfuehren($auftrag, $dienst = '')
{
    $v = sp_json_lesen(sp_ct_vorgang_datei());
    $start = isset($v['start']) ? (int) $v['start'] : time();
    $stand = array('vorgang' => $auftrag, 'dienst' => $dienst, 'zustand' => 'laeuft', 'start' => $start,
                   'pid' => getmypid(), 'schritt' => 'plan', 'schritt_nr' => 0, 'schritte' => 0,
                   'meldung' => '');
    sp_ct_vorgang_schreiben($stand);
    sp_log('Container ' . $auftrag . ($dienst !== '' ? ' ' . $dienst : '') . ': Vorgang laeuft (PID '
           . getmypid() . ').');
    $berichte = array();
    $ok_d = array();
    try {
        list($lage, $lagesatz) = sp_docker_lage();
        if ($lage !== 'ok') {
            return sp_ct_vorgang_ende($stand, false, $lagesatz);
        }
        $cfg = sp_config();
        $hw = ($auftrag === 'holen') ? array() : sp_hardware(false);
        $emp = (isset($hw['empfehlung']) && is_array($hw['empfehlung'])) ? $hw['empfehlung'] : array();
        $schritte = array();
        $dienste = ($auftrag === 'einrichten') ? sp_dienste() : array($dienst);
        foreach ($dienste as $d) {
            $dn = sp_ct_dname($d);
            list($host, $port) = sp_dienst_ziel($d, $cfg);
            if (!sp_ist_lokal($host)) {
                $berichte[$d] = sprintf(sp_t('CT.P_AUSGELAGERT'), $dn, $host . ':' . $port);
                continue;
            }
            if ($auftrag === 'einrichten' && sp_ct_llm_aus($d, $cfg)) {
                $berichte[$d] = sprintf(sp_t('CT.P_AUSGESCHALTET'), $dn);
                continue;
            }
            if ($auftrag === 'holen') {
                $schritte[] = array('holen', $d);
                $ok_d[$d] = false;
                continue;
            }
            if (!sp_ct_run_liste($d, $cfg, $emp, false)) {
                $berichte[$d] = sprintf(sp_t('CT.P_KEIN_MODELL'), $dn);
                continue;
            }
            $ok_d[$d] = false;
            $f = sp_ct_finden($d);
            if ($f['fehler'] !== '') {
                $berichte[$d] = $dn . ': ' . $f['fehler'];
                continue;
            }
            if ($f['da']) {
                if ($f['eigen'] === '') {
                    $berichte[$d] = sprintf(sp_t('CT.NICHT_ANGEFASST'), $f['grund']);
                } elseif ($auftrag === 'anlegen') {
                    $berichte[$d] = sprintf(sp_t('CT.SCHON_DA'), $f['name']);
                } elseif ($f['laeuft']) {
                    $berichte[$d] = sprintf(sp_t('CT.LAEUFT_SCHON'), $f['name'], $f['id']);
                    $ok_d[$d] = true;
                } else {
                    $schritte[] = array('starten', $d);
                }
                continue;
            }
            if (sp_ct_abbild_da(sp_ct_abbild($d)) !== 1) {
                $schritte[] = array('holen', $d);
            }
            $schritte[] = array('anlegen', $d);
        }
        $n = count($schritte);
        $stand['schritte'] = $n;
        sp_log('Container ' . $auftrag . ': ' . $n . ' Schritte geplant.');
        $gescheitert = array();
        foreach ($schritte as $i => $s) {
            list($art, $d) = $s;
            if (isset($gescheitert[$d])) {
                continue;
            }
            $stand['schritt_nr'] = $i + 1;
            $stand['schritt'] = $art;
            $stand['dienst'] = $d;
            $stand['schritt_start'] = time();
            sp_ct_vorgang_schreiben($stand);
            sp_log(sprintf('Container %s: Schritt %d von %d - %s %s', $auftrag, $i + 1, $n, $art,
                           sp_container_name($d)));
            if ($art === 'holen') {
                list($ok, $satz) = sp_ct_holen($d);
            } elseif ($art === 'anlegen') {
                list($ok, $satz) = sp_ct_anlegen($d, $cfg, $emp);
            } else {
                $r = sp_ct_schalten($d, 'start');
                $ok = (bool) $r[0];
                $satz = $r[1];
            }
            $berichte[$d] = $satz;
            $ok_d[$d] = $ok;
            if (!$ok) {
                $gescheitert[$d] = true;
            }
            sp_log(sprintf('Container %s: Schritt %d von %d %s - %s', $auftrag, $i + 1, $n,
                           $ok ? 'erledigt' : 'gescheitert', $satz));
        }
        $alle = !in_array(false, $ok_d, true);
        return sp_ct_vorgang_ende($stand, $alle, implode(' ', $berichte));
    } catch (Throwable $e) {
        return sp_ct_vorgang_ende($stand, false, sprintf(sp_t('CT.VORGANG_AUSNAHME'),
            sp_ct_kurz($e->getMessage(), 200)));
    }
}

function sp_ct_vorgang_ende($stand, $ok, $meldung)
{
    $stand['zustand'] = $ok ? 'fertig' : 'fehler';
    $stand['ende'] = time();
    $stand['meldung'] = (string) $meldung;
    sp_ct_vorgang_schreiben($stand);
    sp_log('Container ' . $stand['vorgang'] . ': ' . ($ok ? 'fertig' : 'nicht vollstaendig') . ' - ' . $meldung);
    return $ok;
}

/**
 * Die Deinstallation: nur die EIGENEN Container entfernen (Label oder
 * Altbestand wie oben), mit Zeitgrenzen. Gerufen von uninstall/uninstall
 * ueber bin/container_vorgang.php deinstallieren. Rueckgabe array(ok, zeilen)
 * mit Zeilen fuer das Installationsprotokoll (<OK>/<INFO>/<WARNING>).
 * Die Modelle liegen im Datenordner und werden hier nicht angefasst.
 */
function sp_ct_deinstallieren()
{
    $zeilen = array();
    list($lage, $satz) = sp_docker_lage();
    if ($lage !== 'ok') {
        $zeilen[] = '<WARNING> ' . $satz;
        $zeilen[] = '<WARNING> ' . sprintf(sp_t('CT.U_NICHTS'), sp_paths()['plugin']);
        return array(false, $zeilen);
    }
    $alles = true;
    $weg = array();
    foreach (sp_dienste() as $d) {
        $f = sp_ct_finden($d);
        if ($f['fehler'] !== '') {
            $alles = false;
            $zeilen[] = '<WARNING> ' . $f['name'] . ': ' . $f['fehler'];
        } elseif (!$f['da']) {
            $zeilen[] = '<INFO> ' . sprintf(sp_t('CT.U_KEINER'), $f['name']);
        } elseif ($f['eigen'] === '') {
            $zeilen[] = '<INFO> ' . sprintf(sp_t('CT.NICHT_ANGEFASST'), $f['grund']);
        } else {
            $weg[$f['id_voll']] = $f['name'];
        }
    }
    // Dazu jeder Container mit beiden eigenen Labels unter anderem Namen
    // (von Hand umbenannt) - per Label gesucht, per inspect gegengeprueft.
    list($rc, $out, ) = sp_docker_ruf(array('ps', '-a', '-q', '--no-trunc', '--filter',
        'label=' . SP_CT_LABEL_ORDNER . '=' . sp_paths()['plugin']), SP_CT_ZEIT_LESEN);
    if ($rc !== 0) {
        $alles = false;
        $zeilen[] = '<WARNING> ' . sprintf(sp_t('CT.DOCKER_FEHLER'), $rc, 'docker ps');
    } else {
        foreach (preg_split('/\s+/', trim($out)) as $id) {
            if (!preg_match('/^[0-9a-f]{12,64}\z/', $id) || isset($weg[$id])) {
                continue;
            }
            $info = sp_ct_inspect($id);
            if ($info !== null && sp_ct_label_eigen($info, sp_paths()['plugin'])) {
                $weg[$id] = ltrim(isset($info['Name']) ? (string) $info['Name'] : $id, '/');
            }
        }
    }
    foreach ($weg as $id => $name) {
        list($rc, , $err) = sp_docker_ruf(array('rm', '-f', $id), SP_CT_ZEIT_SCHALTEN);
        list($rc2, $out2, ) = sp_docker_ruf(array('ps', '-a', '-q', '--no-trunc', '--filter', 'id=' . $id),
                                            SP_CT_ZEIT_LESEN);
        if ($rc2 === 0 && trim($out2) === '') {
            $zeilen[] = '<OK> ' . sprintf(sp_t('CT.U_ENTFERNT'), $name, substr($id, 0, 12));
        } else {
            $alles = false;
            $zeilen[] = '<WARNING> ' . sprintf(sp_t('CT.U_BLEIBT'), $name, substr($id, 0, 12),
                sp_ct_zeitablauf($rc) ? sprintf(sp_t('CT.ZEITABLAUF'), 'docker rm', SP_CT_ZEIT_SCHALTEN)
                                      : sp_ct_kurz($err, 160));
        }
    }
    return array($alles, $zeilen);
}

/* ---------------- Die Ampel ---------------- */

/**
 * Je Dienst: Abbild da / Container laeuft / Port antwortet (127.0.0.1:Port),
 * dazu eine Gesamtzeile. Werte 1 ja, 0 nein, -1 nicht feststellbar.
 * Kostet docker-Aufrufe mit kurzen Grenzen - nur im offenen Reiter Dienste
 * oder Test rufen, und nicht, solange ein Vorgang laeuft.
 * $emp === null: die Empfehlung selbst holen (bin/hardware.py).
 */
function sp_ct_ampel($cfg = null, $emp = null)
{
    if ($cfg === null) { $cfg = sp_config(); }
    if ($emp === null) {
        $hw = sp_hardware(false);
        $emp = (isset($hw['empfehlung']) && is_array($hw['empfehlung'])) ? $hw['empfehlung'] : array();
    }
    list($lage, $lagesatz) = sp_docker_lage();
    $a = array('zeit' => time(), 'lage' => $lage, 'lagesatz' => $lagesatz, 'dienste' => array());
    foreach (sp_dienste() as $d) {
        list($host, $port) = sp_dienst_ziel($d, $cfg);
        $z = array('dienst' => $d, 'host' => $host, 'port' => $port, 'art' => 'lokal',
                   'modell' => sp_ct_modell($d, $cfg, $emp), 'abbild' => -1, 'container' => 'unbekannt',
                   'laeuft' => -1, 'antwortet' => -1, 'grund' => '');
        if (!sp_ist_lokal($host)) {
            $z['art'] = 'ausgelagert';
            $z['container'] = '-';
            $z['antwortet'] = sp_port_offen($host, $port) ? 1 : 0;
        } elseif (sp_ct_llm_aus($d, $cfg)) {
            $z['art'] = 'ausgeschaltet';
            $z['container'] = '-';
        } elseif (!sp_ct_run_liste($d, $cfg, $emp, false)) {
            $z['art'] = 'nicht_vorgesehen';
            $z['container'] = '-';
        } else {
            $z['antwortet'] = sp_port_offen('127.0.0.1', $port) ? 1 : 0;
            if ($lage === 'ok') {
                $z['abbild'] = sp_ct_abbild_da(sp_ct_abbild($d));
                $f = sp_ct_finden($d);
                if ($f['fehler'] !== '') {
                    $z['grund'] = $f['fehler'];
                } elseif (!$f['da']) {
                    $z['container'] = 'fehlt';
                    $z['laeuft'] = 0;
                } elseif ($f['eigen'] === '') {
                    $z['container'] = 'fremd';
                    $z['laeuft'] = 0;
                    $z['grund'] = $f['grund'];
                } else {
                    $z['container'] = $f['laeuft'] ? 'laeuft' : 'gestoppt';
                    $z['laeuft'] = $f['laeuft'] ? 1 : 0;
                }
            }
        }
        $a['dienste'][$d] = $z;
    }
    $a['gesamt'] = sp_ct_gesamt($a);
    return $a;
}

/**
 * Die Gesamtzeile der Ampel, in Worten. Rueckgabe array(stand, satz, ausgelagert)
 * mit stand 1 bereit, 0 es fehlt etwas, -1 nicht feststellbar oder keine
 * Menge: ohne einen einzigen hier vorgesehenen Dienst gibt es keinen Haken.
 * Ausgelagerte Dienste stehen getrennt in 'ausgelagert'.
 */
function sp_ct_gesamt($a)
{
    $lokal = array();
    $aus = array();
    $abgeschaltet = array();
    foreach ($a['dienste'] as $d => $z) {
        if ($z['art'] === 'ausgeschaltet') {
            $abgeschaltet[] = sp_ct_dname($d);
        }
        if ($z['art'] === 'lokal') {
            $lokal[$d] = $z;
        } elseif ($z['art'] === 'ausgelagert') {
            // Zwei woertliche Aufrufe statt eines Ternaers in sp_t(): die
            // Pruefwerkzeuge lesen die Aufrufstelle woertlich (vgl. sp_test.php).
            $aus[] = $z['antwortet'] === 1
                ? sprintf(sp_t('CT.G_AUS_ANTWORTET'), sp_ct_dname($d), $z['host'] . ':' . $z['port'])
                : sprintf(sp_t('CT.G_AUS_STUMM'), sp_ct_dname($d), $z['host'] . ':' . $z['port']);
        }
    }
    $aussatz = $aus ? sprintf(sp_t('CT.G_AUSGELAGERT'), implode(', ', $aus)) : '';
    // Ausgeschaltet ist eine Entscheidung, kein Mangel: getrennt genannt, nie rot.
    if ($abgeschaltet) {
        $aussatz = trim($aussatz . ' ' . sprintf(sp_t('CT.G_AUSGESCHALTET'), implode(', ', $abgeschaltet)));
    }
    if (!$lokal) {
        return array(-1, sp_t('CT.G_KEIN_LOKALER'), $aussatz);
    }
    $fehlt = array();
    $offen = 0;
    foreach ($lokal as $d => $z) {
        $dn = sp_ct_dname($d);
        if ($a['lage'] === 'ok') {
            if ($z['abbild'] === 0) {
                $fehlt[] = sprintf(sp_t('CT.G_ABBILD_FEHLT'), $dn);
            }
            if ($z['container'] === 'fehlt') {
                $fehlt[] = sprintf(sp_t('CT.G_NICHT_ANGELEGT'), $dn);
            } elseif ($z['container'] === 'gestoppt') {
                $fehlt[] = sprintf(sp_t('CT.G_ANGEHALTEN'), $dn);
            } elseif ($z['container'] === 'fremd') {
                $fehlt[] = sprintf(sp_t('CT.G_FREMD'), $dn);
            } elseif ($z['container'] === 'unbekannt') {
                $offen++;
                $fehlt[] = sprintf(sp_t('CT.G_UNBEKANNT'), $dn);
            }
        }
        if ($z['antwortet'] !== 1) {
            $fehlt[] = sprintf(sp_t('CT.G_PORT_STUMM'), $dn, (int) $z['port']);
        }
    }
    if ($a['lage'] !== 'ok') {
        // Ohne Docker ist nur der Port messbar. Antworten alle, ist das kein
        // Beleg fuer eigene Container (es koennte ein anderer Dienst sein).
        if (!$fehlt) {
            return array(-1, sprintf(sp_t('CT.G_NUR_PORTS'), $a['lagesatz']), $aussatz);
        }
        return array(0, sprintf(sp_t('CT.G_FEHLT'),
                                implode('; ', array_merge(array(rtrim($a['lagesatz'], '.')), $fehlt))), $aussatz);
    }
    if (!$fehlt) {
        $namen = array();
        foreach (array_keys($lokal) as $d) { $namen[] = sp_ct_dname($d); }
        return array(1, sprintf(sp_t('CT.G_BEREIT'), implode(', ', $namen)), $aussatz);
    }
    return array($offen === count($lokal) ? -1 : 0, sprintf(sp_t('CT.G_FEHLT'), implode('; ', $fehlt)), $aussatz);
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
        // PHP 8.5: $http_response_header ist veraltet; der Name steht deshalb
        // nicht im Quelltext (Verfallsmeldung schon beim Uebersetzen).
        if (function_exists('http_get_last_response_headers')) {
            $kz = http_get_last_response_headers();
        } else {
            $kn = 'http_response_header';
            $kz = isset($$kn) ? $$kn : null;
        }
        if (is_array($kz)) {
            foreach ($kz as $z) {
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
    // PHP 8.5: $http_response_header ist veraltet; der Name steht deshalb
    // nicht im Quelltext (Verfallsmeldung schon beim Uebersetzen).
    if (function_exists('http_get_last_response_headers')) {
        $kz = http_get_last_response_headers();
    } else {
        $kn = 'http_response_header';
        $kz = isset($$kn) ? $$kn : null;
    }
    if (is_array($kz)) {
        foreach ($kz as $z) {
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
