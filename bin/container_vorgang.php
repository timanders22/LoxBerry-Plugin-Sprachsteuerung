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

/* Zweimal laufen geht nicht: lebt schon ein anderer Vorgang, endet dieser. */
$sp_v = sp_ct_vorgang();
if ($sp_v['zustand'] === 'laeuft' && (int) $sp_v['pid'] !== getmypid()) {
    fwrite(STDERR, 'Es laeuft bereits ein Vorgang (PID ' . (int) $sp_v['pid'] . ").\n");
    exit(3);
}
exit(sp_ct_vorgang_ausfuehren($sp_auftrag, $sp_dienst) ? 0 : 1);
