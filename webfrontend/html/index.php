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
 * Pruefend (loest NICHTS aus):
 *   ?selftest=1           stimmt das Token? Sonst passiert nichts.
 *
 * Lesend:
 *   status                Zustand der Anlage in einer Zeile
 *   satelliten            Liste der Mikrofone
 *   verlauf               die letzten erkannten Saetze
 *   roh                   vollstaendiges Abbild als JSON
 *   diag                  Klartextbefund samt Handgriffen
 *
 * Schaltend:
 *   satz     &text=...    einen Satz durch die Kette schicken, als haette ihn
 *                         jemand gesprochen. So kann auch Loxone die
 *                         Sprachlogik benutzen - etwa fuer einen Taster.
 *   sprechen &text=...    einen Text ansagen. Wahlweise &zone=4 fuer eine
 *                         bestimmte Zone, &mikrofon=Kueche fuer einen
 *                         bestimmten Lautsprecher und &dringend=1, um die
 *                         Ruhezeit zu uebergehen (Alarm).
 *   ruhe     &wert=0|1    die Ansagen voruebergehend stilllegen.
 *
 * DER ENDPUNKT SCHREIBT NICHTS. Er liest den Zwischenspeicher und legt
 * Befehle in einer Warteschlange ab; alles Schreibende macht der Dienst.
 * sp_config(false) sorgt dafuer, dass auch ein abgewiesener Aufruf keine
 * Datei anlegt - bei EVCC hinterliess ein einziger 403-Aufruf eine frisch
 * erzeugte Konfiguration samt Token.
 *
 * Der Endpunkt spricht NIE selbst mit einem Mikrofon oder Sprachdienst.
 */

error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE);
require_once __DIR__ . '/sp_lib.php';
header('Content-Type: text/plain; charset=utf-8');

$sp_cfg = sp_config(false);

/* ---------------- Die Parameter EINMAL einsammeln ----------------
 *
 * Was kein Skalar ist, wird abgewiesen - vor jeder Umwandlung. Bis
 * 0.10.1 stand an acht Stellen (string) $_GET[...]; ein Aufruf mit
 * ?token[]=x erzeugte unter PHP 8 eine Warnung, und die geht hinaus,
 * BEVOR http_response_code(403) laufen kann - die Abweisung erreichte
 * den Miniserver dann als HTTP 200. */
$sp_par = function ($name) {
    if (!isset($_GET[$name])) { return ''; }
    if (!is_scalar($_GET[$name])) {
        http_response_code(400);
        echo "FEHLER;OK=0;GRUND=PARAMETER\n";
        echo 'Der Parameter ' . htmlspecialchars($name, ENT_QUOTES, 'UTF-8')
           . " muss ein einzelner Wert sein.\n";
        exit;
    }
    return (string) $_GET[$name];
};

/* ---------------- Token ---------------- */
$sp_soll = (string) $sp_cfg['aktionstoken'];
$sp_ist = $sp_par('token');
$sp_selftest = isset($_GET['selftest']) && $sp_par('selftest') !== '0';

/* Der Selbsttest steht unmittelbar hinter der Tokenpruefung und VOR jeder
 * Wirkung. Hausregel: ein Token muss sich pruefen lassen, ohne dass etwas
 * passiert. Bei diesem Plugin waere die Alternative, das Haus zum Reden zu
 * bringen oder das Licht zu schalten, nur um zu erfahren, ob die Adresse im
 * Miniserver noch stimmt. Ein falsches Token bekommt dieselbe Abweisung wie
 * sonst auch - der Selbsttest ist keine Abkuerzung an der Sicherheit vorbei. */
if ($sp_soll === '') {
    http_response_code(403);
    if ($sp_selftest) {
        echo "SELFTEST;OK=0;ERR=KEIN_TOKEN_EINGERICHTET\n";
        exit;
    }
    echo "FEHLER;OK=0;GRUND=KEIN_TOKEN_GESETZT\n";
    echo "Die Plugin-Oberflaeche wurde noch nie geoeffnet - es gibt noch kein Token.\n";
    exit;
}
if (!hash_equals($sp_soll, $sp_ist)) {
    http_response_code(403);
    echo $sp_selftest ? "SELFTEST;OK=0;ERR=TOKEN\n" : "FEHLER;OK=0;GRUND=TOKEN\n";
    exit;
}
if ($sp_selftest) {
    echo "SELFTEST;OK=1;TOKEN=OK\n";
    exit;
}

/* ---------------- Aktion (Weissliste) ---------------- */
$sp_lesend = array('status', 'satelliten', 'verlauf', 'roh', 'diag');
$sp_schaltend = array('satz', 'sprechen', 'ruhe');
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
$sp_text = $sp_par('text');
$sp_sauber = preg_replace('/[\x00-\x1F\x7F]/u', ' ', $sp_text);
if ($sp_sauber === null) {
    // Abweisen und benennen, nicht zurechtbiegen: bis 0.10.1 wurde aus
    // ungueltigem UTF-8 stillschweigend ein leerer Text, und die Antwort
    // lautete TEXT_FEHLT - der Text fehlte aber nicht.
    http_response_code(400);
    echo "FEHLER;OK=0;GRUND=TEXT_UNGUELTIG\n";
    echo "Der Text ist kein gueltiges UTF-8.\n";
    exit;
}
$sp_text = trim($sp_sauber);
/* Gezaehlt werden ZEICHEN, nicht Bytes. strlen() zaehlt Bytes, und ein
 * Umlaut belegt in UTF-8 zwei davon. Nachgemessen: 201 Umlaute sind 201
 * Zeichen, aber 402 Bytes - und wurden bis 0.9.1 abgewiesen. */
if (sp_zeichen($sp_text) > 400) {
    http_response_code(400);
    echo "FEHLER;OK=0;GRUND=TEXT_ZU_LANG\n";
    echo "Mehr als 400 Zeichen nimmt der Endpunkt nicht an.\n";
    exit;
}

/* Zone, Mikrofon und Dringlichkeit: enge Muster, alles andere abgewiesen. */
$sp_zone = $sp_par('zone');
if ($sp_zone !== '' && !preg_match('/^[0-9~,]{1,80}$/', $sp_zone)) {
    http_response_code(400);
    echo "FEHLER;OK=0;GRUND=ZONE_UNGUELTIG\n";
    echo "Zonen bestehen aus Ziffern, Komma und der Tilde, zum Beispiel 2,4 oder 2~15.\n";
    exit;
}
$sp_mikro = $sp_par('mikrofon');
$sp_mikro_sauber = preg_replace('/[\x00-\x1F\x7F]/u', '', $sp_mikro);
if ($sp_mikro_sauber === null) {
    // Ein still weggefallenes Mikrofon schickt die Ansage an ALLE
    // Lautsprecher statt an einen.
    http_response_code(400);
    echo "FEHLER;OK=0;GRUND=MIKROFON_UNGUELTIG\n";
    exit;
}
$sp_mikro = trim($sp_mikro_sauber);
if (sp_zeichen($sp_mikro) > 40) {
    http_response_code(400);
    echo "FEHLER;OK=0;GRUND=MIKROFON_ZU_LANG\n";
    exit;
}
$sp_dringend = $sp_par('dringend') === '1' ? 1 : 0;

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
           . ';' . $sp_s['zustand']
           . ';' . (string) (isset($sp_s['raum']) ? $sp_s['raum'] : '')
           . ';' . (string) (isset($sp_s['zone']) ? $sp_s['zone'] : '') . "\n";
    }
    exit;
}

if ($sp_aktion === 'verlauf') {
    $sp_v = sp_verlauf();
    printf("VERLAUF;OK=1;N=%d\n", count($sp_v));
    foreach (array_slice($sp_v, 0, 20) as $sp_e) {
        printf("%s;%s;%s;%s;%s;%s\n",
            date('H:i:s', (int) (isset($sp_e['ts']) ? $sp_e['ts'] : 0)),
            isset($sp_e['ok']) && $sp_e['ok'] ? 'ok' : 'nein',
            str_replace(';', ',', (string) (isset($sp_e['satz']) ? $sp_e['satz'] : '')),
            (string) (isset($sp_e['absicht']) ? $sp_e['absicht'] : ''),
            (string) (isset($sp_e['ziel']) ? $sp_e['ziel'] : ''),
            (string) (isset($sp_e['mikrofon']) ? $sp_e['mikrofon'] : ''));
    }
    exit;
}

if ($sp_aktion === 'status') {
    /* EINE Quelle: dieselbe Liste, aus der auch die Loxone-Vorlage und die
     * Tabelle im Reiter entstehen. Bis 0.9.11 stand die Zeile hier zum
     * zweiten Mal - sie fuehrte sechs Werte, die Vorlage vier, und REGELN
     * und ZIELE kamen in Loxone nie an. */
    echo sp_statuszeile() . "\n";
    exit;
}

if ($sp_aktion === 'diag') {
    /* Klartextbefund samt Handgriffen - der zweite Schritt nach ?selftest=1.
     * Rein lesend: gemessen wird, was ohnehin dasteht, plus die Frage, ob die
     * Sprachdienste antworten. */
    $sp_p = sp_paths();
    echo "SPRACHSTEUERUNG - DIAGNOSE\n";
    echo str_repeat('-', 60) . "\n";
    printf("Dienst              : %s\n", sp_dienst_pid() > 0
        ? 'laeuft (PID ' . sp_dienst_pid() . ')'
        : (sp_dienst_soll() ? 'GESTOPPT, obwohl er laufen soll' : 'gestoppt'));
    printf("Abbild              : %s\n", $sp_alter < 0
        ? 'noch keins - der Dienst hat nie geschrieben'
        : $sp_alter . ' s alt');
    printf("Mikrofone           : %d eingetragen, %d verbunden\n",
        count($sp_sats), (int) (isset($sp_lox['bereit']) ? $sp_lox['bereit'] : 0));
    foreach (sp_dienste() as $sp_d) {
        list($sp_h, $sp_pt) = sp_dienst_ziel($sp_d, $sp_cfg);
        // Ein abgeschaltetes Sprachmodell ist keine Stoerung. Bei der
        // Werksvorgabe llm_ein=0 meldete diese Zeile auf JEDER Anlage
        // 'ANTWORTET NICHT' - ein Kreuz, das nichts bedeutet.
        if ($sp_d === 'llm' && empty($sp_cfg['llm_ein'])) {
            printf("%-20s: abgeschaltet\n", $sp_d);
            continue;
        }
        printf("%-20s: %s auf %s:%d\n", $sp_d,
            sp_port_offen($sp_h, $sp_pt, 2.0) ? 'antwortet' : 'ANTWORTET NICHT',
            $sp_h, $sp_pt);
    }
    $sp_m = sp_mqtt_zustand();
    printf("MQTT-Gateway        : %s, UDP-Eingang %d, Fassung %s\n",
        $sp_m['autostart'] ? 'Autostart an' : 'AUTOSTART AUS',
        (int) $sp_m['udpport'], $sp_m['fassung'] ?: 'unbekannt');
    list($sp_ruhe, $sp_rgrund) = sp_ruhe_aktiv($sp_cfg);
    printf("Ruhezeit            : %s\n", $sp_ruhe ? 'AKTIV - ' . $sp_rgrund : 'nicht aktiv');
    printf("Satzdatei           : %d Regeln, %d Ziele\n",
        (int) (isset($sp_lox['anzahl_regeln']) ? $sp_lox['anzahl_regeln'] : 0),
        (int) (isset($sp_lox['anzahl_ziele']) ? $sp_lox['anzahl_ziele'] : 0));
    echo str_repeat('-', 60) . "\n";
    echo "WENN ETWAS NICHT GEHT:\n";
    echo "1. Dienst gestoppt: Reiter Einstellungen, Knopf 'Dienst starten'.\n";
    echo "2. Ein Sprachdienst antwortet nicht: Reiter Dienste, Container starten.\n";
    echo "   Steht dort eine fremde Adresse, laeuft der Dienst auf einem anderen\n";
    echo "   Rechner - dann dort nachsehen.\n";
    echo "3. Autostart aus: System -> MQTT Gateway einschalten. Ohne das kommt\n";
    echo "   am Miniserver nichts an.\n";
    echo "4. Mikrofone verbunden, aber nichts wird verstanden: Reiter Test,\n";
    echo "   'Was zuletzt verstanden wurde'. Dort steht, was Whisper GEHOERT hat -\n";
    echo "   sehr oft etwas anderes, als gesagt wurde.\n";
    echo "5. Es kommt keine Ansage: Ruhezeit pruefen (oben) und den Antwortweg\n";
    echo "   im Reiter Einstellungen.\n";
    exit;
}

/* ================= Schaltende Aktionen ================= */

if (sp_dienst_pid() === 0) {
    http_response_code(503);
    echo "SET;OK=0;GRUND=DIENST_LAEUFT_NICHT\n";
    echo "Der Dienst laeuft nicht. Reiter Einstellungen, Knopf 'Dienst starten'.\n";
    exit;
}

if ($sp_aktion === 'ruhe') {
    $sp_wert = isset($_GET['wert']) ? (string) $_GET['wert'] : '';
    if (!in_array($sp_wert, array('0', '1'), true)) {
        http_response_code(400);
        echo "SET;OK=0;GRUND=WERT_UNGUELTIG\n";
        echo "Erlaubt ist wert=0 oder wert=1.\n";
        exit;
    }
    /* Auch das geht ueber die Warteschlange: der DIENST schreibt, nicht der
     * unangemeldete Endpunkt. */
    list($sp_erg, $sp_meldung) = sp_befehl_absetzen(
        array('aktion' => 'ruhe', 'wert' => (int) $sp_wert));
    if ($sp_erg === 0) { http_response_code(500); }
    printf("SET;OK=%d;AKTION=ruhe;WERT=%d;MELDUNG=%s\n", $sp_erg, (int) $sp_wert,
        str_replace(array("\r", "\n", ';'), ' ', $sp_meldung));
    exit;
}

if ($sp_text === '') {
    http_response_code(400);
    echo "SET;OK=0;GRUND=TEXT_FEHLT\n";
    exit;
}

$sp_befehl = array('aktion' => $sp_aktion,
                   ($sp_aktion === 'satz' ? 'satz' : 'text') => $sp_text);
if ($sp_aktion === 'sprechen') {
    if ($sp_zone !== '')  { $sp_befehl['zone'] = $sp_zone; }
    if ($sp_mikro !== '') { $sp_befehl['mikrofon'] = $sp_mikro; }
    if ($sp_dringend)     { $sp_befehl['dringend'] = 1; }
}
list($sp_erg, $sp_meldung) = sp_befehl_absetzen($sp_befehl);
if ($sp_erg === 0) {
    http_response_code(500);
}
printf("SET;OK=%d;AKTION=%s;MELDUNG=%s\n", $sp_erg, $sp_aktion,
    str_replace(array("\r", "\n", ';'), ' ', $sp_meldung));
