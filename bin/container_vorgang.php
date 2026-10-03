<?php
/**
 * Sprachsteuerung lokal - richtet die Container im Hintergrund ein (seit 0.11.11).
 *
 * Gestartet von der Oberflaeche (Reiter Dienste, Knopf "Sprachdienste
 * einrichten" bzw. "Abbild holen" und "Anlegen" unter "Einzeln verwalten")
 * ueber sp_ct_vorgang_starten(). Im Hintergrund, weil "docker pull" eines
 * Abbilds von mehreren Gigabyte Minuten dauert und die Seite nicht so lange
 * warten darf (Bauliste Einrichtung E2, Muster MGiSmart
 * bin/gateway_vorgang.php). Der Vorgang schreibt seinen Stand nach
 * data/plugins/<ordner>/container_vorgang.json; die Seite zeigt
 * "Schritt x von y, seit N s" und laedt sich neu, solange er laeuft.
 *
 * Kein Takt ruft diese Datei. uninstall/uninstall ruft sie mit
 * "deinstallieren": dann entfernt sie nur die eigenen Container und gibt
 * Zeilen fuer das Installationsprotokoll aus.
 *
 * Aufruf: php container_vorgang.php einrichten
 *         php container_vorgang.php holen <dienst>
 *         php container_vorgang.php anlegen <dienst>
 *         php container_vorgang.php deinstallieren
 * Rueckgabewert: 0 erledigt, 1 nicht vollstaendig, 2 falscher Aufruf,
 * 3 es laeuft schon ein Vorgang.
 */
error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE);

if (basename(dirname(__DIR__)) === 'plugins') {
    $sp_lib = dirname(dirname(dirname(__DIR__))) . '/webfrontend/html/plugins/'
        . basename(__DIR__) . '/sp_lib.php';
} else {
    $sp_lib = dirname(__DIR__) . '/webfrontend/html/sp_lib.php';
}
if (!is_file($sp_lib)) {
    fwrite(STDERR, "sp_lib.php nicht gefunden - Plugin neu installieren.\n");
    exit(1);
}
require_once $sp_lib;

$sp_p = sp_paths();
if ($sp_p['home'] === '') {
    fwrite(STDERR, "container_vorgang.php: keine LoxBerry-Installation gefunden - nichts angefasst.\n");
    exit(1);
}
/* Die Sprache der Meldungen wie in der Oberflaeche (LBSystem::lblanguage). */
if (is_file($sp_p['home'] . '/libs/phplib/loxberry_system.php')) {
    require_once $sp_p['home'] . '/libs/phplib/loxberry_system.php';
}

$sp_auftrag = isset($argv[1]) ? (string) $argv[1] : '';
$sp_dienst = isset($argv[2]) ? (string) $argv[2] : '';
$sp_erlaubt = (in_array($sp_auftrag, array('einrichten', 'deinstallieren'), true) && count($argv) === 2)
    || (in_array($sp_auftrag, array('holen', 'anlegen'), true) && count($argv) === 3
        && in_array($sp_dienst, sp_dienste(), true));
if (!$sp_erlaubt) {
    fwrite(STDERR, "Unbekannter Auftrag - erlaubt: einrichten | holen <dienst> | anlegen <dienst> | deinstallieren\n");
    exit(2);
}

if ($sp_auftrag === 'deinstallieren') {
    /* Nichts in den Datenordner schreiben: den raeumt LoxBerry gleich ab. */
    list($sp_ok, $sp_zeilen) = sp_ct_deinstallieren();
    foreach ($sp_zeilen as $sp_z) {
        echo $sp_z, "\n";
    }
    exit($sp_ok ? 0 : 1);
}

/* Laufzeitfehler in eine Datei, nicht auf die Fehlerausgabe: die geht beim
 * Start nach /dev/null (Regeln/03, "dritte Protokollart"). */
if (!is_dir($sp_p['logdir'])) {
    @mkdir($sp_p['logdir'], 0775, true);
}
ini_set('log_errors', '1');
ini_set('display_errors', '0');
ini_set('error_log', $sp_p['logdir'] . '/container_vorgang.err');

/* Zweimal laufen geht nicht. Bis 0.11.15 ohne Sperre und nur fuer "laeuft":
 * zwei Aufrufe kurz nacheinander (oder ein Aufruf von Hand, waehrend die
 * Oberflaeche gerade "gestartet" eingetragen hatte) sahen beide "frei" und
 * arbeiteten gleichzeitig. Jetzt unter derselben Sperre wie
 * sp_ct_vorgang_starten() (data/plugins/<ordner>/container_vorgang.lock):
 *   - "laeuft" mit einem anderen lebenden Vorgang: endet (3).
 *   - "gestartet": das ist der Eintrag der Oberflaeche fuer GENAU EINEN Start.
 *     Ihn uebernimmt nur ein Aufruf mit demselben Auftrag und Dienst; ein
 *     anderer Auftrag endet (3).
 * Uebernommen wird, indem "laeuft" mit der eigenen PID eingetragen wird, noch
 * unter der Sperre - ein zweiter Aufruf mit demselben Auftrag sieht danach
 * "laeuft" und endet. Die Sperre wird vor der Arbeit freigegeben: die Kinder
 * (docker) erbten sie sonst und hielten sie ueber das Ende hinaus. */
if (!is_dir($sp_p['datadir'])) {
    @mkdir($sp_p['datadir'], 0775, true);
}
$sp_sperre = @fopen($sp_p['datadir'] . '/container_vorgang.lock', 'c');
if ($sp_sperre === false) {
    fwrite(STDERR, "Sperrdatei nicht anlegbar: " . $sp_p['datadir'] . "/container_vorgang.lock\n");
    exit(1);
}
if (!flock($sp_sperre, LOCK_EX | LOCK_NB)) {
    fclose($sp_sperre);
    fwrite(STDERR, "Es laeuft bereits ein Vorgang (Sperre belegt).\n");
    exit(3);
}
$sp_v = sp_ct_vorgang();
$sp_grund = '';
$sp_ende = 0;
if ($sp_v['zustand'] === 'laeuft' && (int) $sp_v['pid'] !== getmypid()) {
    $sp_grund = 'Es laeuft bereits ein Vorgang (PID ' . (int) $sp_v['pid'] . ').';
    $sp_ende = 3;
} elseif ($sp_v['zustand'] === 'gestartet'
    && ((string) $sp_v['vorgang'] !== $sp_auftrag || (string) $sp_v['dienst'] !== $sp_dienst)) {
    $sp_grund = 'Die Oberflaeche hat gerade einen anderen Vorgang gestartet ("' . $sp_v['vorgang'] . '").';
    $sp_ende = 3;
} elseif (!sp_ct_vorgang_schreiben(array('vorgang' => $sp_auftrag, 'dienst' => $sp_dienst, 'zustand' => 'laeuft',
        'start' => ($sp_v['zustand'] === 'gestartet' && (int) $sp_v['start'] > 0) ? (int) $sp_v['start'] : time(),
        'pid' => getmypid(), 'schritt' => 'plan', 'schritt_nr' => 0, 'schritte' => 0, 'meldung' => ''))) {
    $sp_grund = 'Die Zustandsdatei laesst sich nicht schreiben.';
    $sp_ende = 1;
}
flock($sp_sperre, LOCK_UN);
fclose($sp_sperre);
if ($sp_ende !== 0) {
    fwrite(STDERR, $sp_grund . "\n");
    exit($sp_ende);
}
exit(sp_ct_vorgang_ausfuehren($sp_auftrag, $sp_dienst) ? 0 : 1);
