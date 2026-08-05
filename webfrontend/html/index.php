<?php
/**
 * Sprachsteuerung lokal - Endpunkt fuer den Miniserver
 *
 * Liegt im unangemeldeten Bereich, damit Loxone ihn ohne Zugangsdaten
 * erreicht, und ist deshalb durch ein Token geschuetzt. Verglichen wird mit
 * hash_equals, also in gleichbleibender Zeit.
 *
 *   /plugins/<ordner>/index.php?token=<TOKEN>&aktion=<Befehl>
 *
 * Lesend:
 *   status                Zustand der Anlage in einer Zeile
 *   satelliten            Liste der Mikrofone
 *   verlauf               die letzten erkannten Saetze
 *   roh                   vollstaendiges Abbild als JSON
 *
 * Schaltend:
 *   satz    &text=...     einen Satz durch die Kette schicken, als haette ihn
 *                         jemand gesprochen. So kann auch Loxone die
 *                         Sprachlogik benutzen - etwa fuer einen Taster.
 *   sprechen &text=...    einen Text aussprechen lassen (Ansage)
 *
 * Der Endpunkt spricht NIE selbst mit einem Mikrofon oder Sprachdienst. Er
 * liest den Zwischenspeicher und legt Befehle in einer Warteschlange ab.
 */

error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE);
require_once __DIR__ . '/sp_lib.php';
header('Content-Type: text/plain; charset=utf-8');

$sp_cfg = sp_config();

/* ---------------- Token ---------------- */
$sp_soll = (string) $sp_cfg['aktionstoken'];
$sp_ist = isset($_GET['token']) ? (string) $_GET['token'] : '';
if ($sp_soll === '') {
    http_response_code(403);
    echo "FEHLER;OK=0;GRUND=KEIN_TOKEN_GESETZT\n";
    echo "Die Plugin-Oberflaeche wurde noch nie geoeffnet - es gibt noch kein Token.\n";
    exit;
}
if (!hash_equals($sp_soll, $sp_ist)) {
    http_response_code(403);
    echo "FEHLER;OK=0;GRUND=TOKEN\n";
    exit;
}

/* ---------------- Aktion (Weissliste) ---------------- */
$sp_lesend = array('status', 'satelliten', 'verlauf', 'roh');
$sp_schaltend = array('satz', 'sprechen');
$sp_aktion = isset($_GET['aktion']) ? (string) $_GET['aktion'] : 'status';
if (!in_array($sp_aktion, array_merge($sp_lesend, $sp_schaltend), true)) {
    http_response_code(400);
    echo "FEHLER;OK=0;GRUND=UNBEKANNTE_AKTION\n";
    echo 'Erlaubt sind: ' . implode(', ', array_merge($sp_lesend, $sp_schaltend)) . "\n";
    exit;
}

/* Der gesprochene Text wird NICHT gefiltert - nur Steuerzeichen fallen weg.
 * Ein hartes Filtern zerstoerte gueltige Saetze, und was ein Satz enthalten
 * darf, weiss hier niemand besser als der Sprecher. */
$sp_text = isset($_GET['text']) ? (string) $_GET['text'] : '';
$sp_text = trim(preg_replace('/[\x00-\x1F\x7F]/u', ' ', $sp_text));
if (strlen($sp_text) > 400) {
    http_response_code(400);
    echo "FEHLER;OK=0;GRUND=TEXT_ZU_LANG\n";
    echo "Mehr als 400 Zeichen nimmt der Endpunkt nicht an.\n";
    exit;
}

$sp_lox = sp_loxone();
$sp_sats = sp_satelliten();
$sp_alter = sp_alter();

/* ================= Lesende Aktionen ================= */

if ($sp_aktion === 'roh') {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($sp_lox, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if ($sp_aktion === 'satelliten') {
    printf("SATELLITEN;OK=%d;N=%d;ALTER=%d\n",
        (int) (!empty($sp_lox['ok'])), count($sp_sats), $sp_alter);
    foreach ($sp_sats as $sp_name => $sp_s) {
        echo $sp_name . ';' . $sp_s['art'] . ';' . $sp_s['host'] . ':' . $sp_s['port']
           . ';' . $sp_s['zustand'] . "\n";
    }
    exit;
}

if ($sp_aktion === 'verlauf') {
    $sp_v = sp_verlauf();
    printf("VERLAUF;OK=1;N=%d\n", count($sp_v));
    foreach (array_slice($sp_v, 0, 20) as $sp_e) {
        printf("%s;%s;%s;%s;%s\n",
            date('H:i:s', (int) (isset($sp_e['ts']) ? $sp_e['ts'] : 0)),
            isset($sp_e['ok']) && $sp_e['ok'] ? 'ok' : 'nein',
            str_replace(';', ',', (string) (isset($sp_e['satz']) ? $sp_e['satz'] : '')),
            (string) (isset($sp_e['absicht']) ? $sp_e['absicht'] : ''),
            (string) (isset($sp_e['ziel']) ? $sp_e['ziel'] : ''));
    }
    exit;
}

if ($sp_aktion === 'status') {
    $sp_bereit = 0;
    foreach ($sp_sats as $sp_s) {
        if ($sp_s['zustand'] !== 'getrennt') { $sp_bereit++; }
    }
    printf("SPRACHE;OK=%d;SATELLITEN=%d;BEREIT=%d;REGELN=%d;ZIELE=%d;ALTER=%d\n",
        (int) (!empty($sp_lox['ok'])), count($sp_sats), $sp_bereit,
        (int) (isset($sp_lox['anzahl_regeln']) ? $sp_lox['anzahl_regeln'] : 0),
        (int) (isset($sp_lox['anzahl_ziele']) ? $sp_lox['anzahl_ziele'] : 0),
        $sp_alter);
    exit;
}

/* ================= Schaltende Aktionen ================= */

if (sp_dienst_pid() === 0) {
    http_response_code(503);
    echo "SET;OK=0;GRUND=DIENST_LAEUFT_NICHT\n";
    echo "Der Dienst laeuft nicht. Reiter Einstellungen, Knopf 'Dienst starten'.\n";
    exit;
}
if ($sp_text === '') {
    http_response_code(400);
    echo "SET;OK=0;GRUND=TEXT_FEHLT\n";
    exit;
}

list($sp_erg, $sp_meldung) = sp_befehl_absetzen(
    array('aktion' => $sp_aktion, ($sp_aktion === 'satz' ? 'satz' : 'text') => $sp_text));
if ($sp_erg === 0) {
    http_response_code(500);
}
printf("SET;OK=%d;AKTION=%s;MELDUNG=%s\n", $sp_erg, $sp_aktion,
    str_replace(array("\r", "\n", ';'), ' ', $sp_meldung));
