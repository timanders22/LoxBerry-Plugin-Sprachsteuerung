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
 * Das Token darf seit 0.12.0 auch als POST-Feld "token" oder im Kopf
 * "X-Token" kommen - dann steht es nicht im Zugriffsprotokoll des
 * Webservers. ?token= bleibt, weil Loxone-Ausgaenge meist nur GET koennen.
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
 *
 * SEIT 0.12.7 (Gruppe 11 / MQTT, Entscheidung 1, E4) steht der GANZE Endpunkt hinter dem Haken "HTTP-Endpunkt
 * fuer Loxone annehmen" (endpunkt_ein, ab Werk aus; beim Update an, wenn ein Token gesetzt ist). Ohne ihn: 403
 * ENDPUNKT_AUS, vor der Tokenpruefung und vor jeder Wirkung. Der Standardweg ist MQTT (Werte ueber das Gateway,
 * Befehle ueber die Befehlsbruecke). Die schaltenden Aktionen pruefen und reihen seit 0.12.7 in sp_steuern()
 * (sp_lib.php) ein - EINE Logik fuer HTTP und Bruecke; die Antworten sind byte-gleich wie bis 0.12.6.
 */

error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE);
require_once __DIR__ . '/sp_lib.php';
header('Content-Type: text/plain; charset=utf-8');
/* Keine Antwort in einen Zwischenspeicher: sie kann Zustand, Verlauf oder
 * das Abbild tragen, und die Anfrage traegt das Token. */
header('Cache-Control: no-store');

$sp_cfg = sp_config(false);

/* ---------------- Haken endpunkt_ein (0.12.7) ----------------
 * Vor der Tokenpruefung: ohne Haken nimmt der Endpunkt nichts an, auch keinen Selbsttest und keine lesende
 * Aktion (Entwurf 3.6, E4). Es wird nichts angelegt (sp_config(false)). Text in ASCII wie die uebrigen Antworten. */
if ((int) $sp_cfg['endpunkt_ein'] !== 1) {
    http_response_code(403);
    if (isset($_GET['selftest'])) {
        echo "SELFTEST;OK=0;ERR=ENDPUNKT_AUS\n";
        exit;
    }
    echo "FEHLER;OK=0;GRUND=ENDPUNKT_AUS\n";
    echo "Im Plugin Reiter Einbindung den Haken 'HTTP-Endpunkt fuer Loxone annehmen' setzen.\n";
    exit;
}

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
/* Getrimmt und mit Mindestlaenge: bis 0.11.15 genuegte ein Soll-Token aus
 * Leerzeichen - "   " und ?token=%20%20%20 ergaben SELFTEST;OK=1. Ein
 * erzeugtes Token ist 24 Zeichen lang (sp_token_erzeugen()); unter 16 gilt
 * keins als eingerichtet. */
$sp_soll = trim((string) $sp_cfg['aktionstoken']);
/* Woher das Token kommt: Kopf X-Token, POST-Feld token, sonst ?token= (Loxone).
 * Ein POST-Feld als Liste wird abgewiesen wie bei GET. */
if (isset($_SERVER['HTTP_X_TOKEN']) && is_string($_SERVER['HTTP_X_TOKEN']) && $_SERVER['HTTP_X_TOKEN'] !== '') {
    $sp_ist = $_SERVER['HTTP_X_TOKEN'];
} elseif (isset($_POST['token'])) {
    if (!is_scalar($_POST['token'])) {
        http_response_code(400);
        echo "FEHLER;OK=0;GRUND=PARAMETER\n";
        echo "Der Parameter token muss ein einzelner Wert sein.\n";
        exit;
    }
    $sp_ist = (string) $_POST['token'];
} else {
    $sp_ist = $sp_par('token');
}
$sp_selftest = isset($_GET['selftest']) && $sp_par('selftest') !== '0';

/* Der Selbsttest steht unmittelbar hinter der Tokenpruefung und VOR jeder
 * Wirkung. Hausregel: ein Token muss sich pruefen lassen, ohne dass etwas
 * passiert. Bei diesem Plugin waere die Alternative, das Haus zum Reden zu
 * bringen oder das Licht zu schalten, nur um zu erfahren, ob die Adresse im
 * Miniserver noch stimmt. Ein falsches Token bekommt dieselbe Abweisung wie
 * sonst auch - der Selbsttest ist keine Abkuerzung an der Sicherheit vorbei. */
if (strlen($sp_soll) < 16) {
    http_response_code(403);
    if ($sp_selftest) {
        echo "SELFTEST;OK=0;ERR=KEIN_TOKEN_EINGERICHTET\n";
        exit;
    }
    echo "FEHLER;OK=0;GRUND=KEIN_TOKEN_GESETZT\n";
    echo "Es ist kein gueltiges Token eingerichtet (mindestens 16 Zeichen) - die Plugin-Oberflaeche oeffnen.\n";
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
// Ueber $sp_par: ?aktion[]=status ergab bis 0.11.15 eine Warnung samt Pfad und HTTP 200 statt 400.
$sp_aktion = isset($_GET['aktion']) ? $sp_par('aktion') : 'status';
if (!in_array($sp_aktion, array_merge($sp_lesend, $sp_schaltend), true)) {
    http_response_code(400);
    echo "FEHLER;OK=0;GRUND=UNBEKANNTE_AKTION\n";
    echo 'Erlaubt sind: ' . implode(', ', array_merge($sp_lesend, $sp_schaltend)) . "\n";
    exit;
}

/* Text, Zone, Mikrofon und Dringlichkeit - seit 0.12.7 EINE Pruefung fuer diesen Endpunkt und die Befehlsbruecke
 * (sp_eingaben_pruefen() in sp_lib.php). Sie steht an derselben Stelle wie bisher, also auch vor den lesenden
 * Aktionen, liest die Felder ueber $sp_par in derselben Reihenfolge und antwortet byte-gleich: der Text wird nicht
 * gefiltert (nur Steuerzeichen fallen weg), gezaehlt werden Zeichen (hoechstens 400), Zonen wie in der Sprachausgabe
 * (ansage_zonen_ok()), das Mikrofon hoechstens 40 Zeichen, ungueltiges UTF-8 wird benannt statt geleert. */
$sp_ein = sp_eingaben_pruefen($sp_par);
if (!$sp_ein['ok']) {
    http_response_code($sp_ein['http']);
    echo $sp_ein['ausgabe'];
    exit;
}

$sp_lox = sp_loxone();
$sp_sats = sp_satelliten();
$sp_alter = sp_alter();

/* Ein Feld in einer Antwortzeile: kein ; (trennt die Felder) und kein
 * Steuerzeichen (eine neue Zeile gaebe sich als eigener Eintrag aus). Bis
 * 0.11.15 nur beim Satz; Raum, Zone, Absicht, Ziel, Mikrofon und Namen gingen
 * roh hinaus. */
$sp_feld = function ($w) {
    $w = is_scalar($w) ? (string) $w : '';
    return str_replace(';', ',', (string) preg_replace('/[\x00-\x1F\x7F]+/', ' ', $w));
};

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
        echo $sp_feld($sp_name) . ';' . $sp_feld(isset($sp_s['art']) ? $sp_s['art'] : '')
           . ';' . $sp_feld(isset($sp_s['host']) ? $sp_s['host'] : '') . ':' . $sp_feld(isset($sp_s['port']) ? $sp_s['port'] : '')
           . ';' . $sp_feld(isset($sp_s['zustand']) ? $sp_s['zustand'] : '')
           . ';' . $sp_feld(isset($sp_s['raum']) ? $sp_s['raum'] : '')
           . ';' . $sp_feld(isset($sp_s['zone']) ? $sp_s['zone'] : '') . "\n";
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
            $sp_feld(isset($sp_e['satz']) ? $sp_e['satz'] : ''),
            $sp_feld(isset($sp_e['absicht']) ? $sp_e['absicht'] : ''),
            $sp_feld(isset($sp_e['ziel']) ? $sp_e['ziel'] : ''),
            $sp_feld(isset($sp_e['mikrofon']) ? $sp_e['mikrofon'] : ''));
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
        /* S15 (0.12.7): ein offener Port ist noch kein Sprachdienst - erkannt wird wie in der Ampel des Reiters
         * Dienste (sp_dienst_erkennen()). Bis 0.12.6 hiess ein fremder Dienst auf dem Port "antwortet". */
        list($sp_st, $sp_gr) = sp_dienst_erkennen($sp_d, $sp_h, $sp_pt, 2.0);
        printf("%-20s: %s auf %s:%d\n", $sp_d,
            $sp_st === 1 ? 'antwortet' : ($sp_st === 2 ? 'FALSCHER DIENST (' . $sp_gr . ')' : 'ANTWORTET NICHT'),
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

/* Seit 0.12.7 (Gruppe 11, Entwurf 3.4) in sp_steuern() (sp_lib.php): laeuft der Dienst?, ruhe mit wert=0|1, ist ein
 * Text da?, dann die Warteschlange des Dienstes - EINE Logik fuer diesen Endpunkt und die Befehlsbruecke. Ausgabe und
 * HTTP-Code byte-gleich wie bis 0.12.6 (Pruefstand: je Fall Vorher/Nachher gleich). DER ENDPUNKT SCHREIBT WEITER
 * NICHTS - auch "ruhe" geht ueber die Warteschlange, der Dienst schreibt ruhe.json. */
$sp_r = sp_steuern($sp_aktion, $sp_par, 'endpunkt', null, $sp_ein);
if ($sp_r['http'] !== 200) {
    http_response_code($sp_r['http']);
}
echo $sp_r['ausgabe'];
