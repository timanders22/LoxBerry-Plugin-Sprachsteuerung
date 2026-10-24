<?php
/**
 * bin/befehle_zuordnung.php - Zuordnungstabelle der Befehlsbruecke, Linie Sprachsteuerung.
 *
 * Gruppe 11 / MQTT (0.12.7; Bauvorschrift wz_bild/GRUPPE11_BAUVORSCHRIFT.md, Entwurf Musterprojekt/entwurf_gruppe11_sp.txt
 * Abschnitte 3.2-3.4, ENTSCHEIDUNGEN_GRUPPE11_SPRACHSTEUERUNG.md E1-E12; Muster vb_g8_mt_bau/bin/befehle_zuordnung.php). Die
 * Bruecke selbst (bin/befehlsbruecke.php und .sh) ist byte-gleich die Stammfassung Werkzeuge/gemeinsam/ - linieneigen ist
 * nur diese Datei.
 *
 * Themen: <praefix>/befehl/sprechen | alarm | satz | ruhe (E1). sprechen und alarm nehmen Klartext oder JSON
 * {"text", "zone", "mikrofon"}; alarm spricht dringend (uebergeht Ruhezeit und Stilllegung) - dringend gibt es NUR ueber
 * diesen eigenen Namen, nie als JSON-Feld: so gelten Haken und Sperre je Name, und ein Text auf sprechen kann die Ruhe nie
 * uebergehen. satz schickt einen Satz durch die Sprachkette, ruhe legt die Ansagen still (1) oder gibt sie frei (0).
 *
 * Regeln (Entwurf gemeinsam 2.5): die Datei gibt ein Feld zurueck, setzt keine Konfiguration und schreibt nichts. 'tun'
 * ruft sp_bb_tun() in webfrontend/html/sp_lib.php: Upgrade-Marke, dann sp_steuern() - DIESELBE Pruefung wie der
 * HTTP-Endpunkt (Text 1-400 Zeichen, Zone, Mikrofon, laeuft der Dienst?) und dieselbe Warteschlange befehle/ ueber
 * sp_befehl_absetzen(). Kein HTTP auf sich selbst, kein Kindprozess. sprechen, alarm und satz werden nur eingereiht
 * (Quittung "uebergeben"; das Ergebnis zeigen ok/grund bzw. ansage_ok), ruhe wartet hoechstens 3 s auf den Dienst, der
 * ruhe.json schreibt (Quittung "ausgefuehrt").
 *
 * Haken (E2): "Befehle ueber MQTT annehmen" (befehle_mqtt_ein, ab Werk aus) schaltet die Bruecke; eingreifend sind
 * sprechen, alarm und satz - sie brauchen zusaetzlich "Ansagen und Saetze ueber MQTT zulassen" (befehle_mqtt_steuern, ab
 * Werk aus). Ansagen erzeugen Ton im Haus, ein Satz kann ueber die Ziele schalten, Timer anlegen und Musik steuern.
 *
 * Sperrzeiten (E3): 10 s fuer sprechen und alarm (gleicher Text), 0 s fuer satz und ruhe.
 *
 * Die Bibliothek liegt unter webfrontend/html/ (der HTTP-Endpunkt teilt sie): installiert
 * <wurzel>/webfrontend/html/plugins/<ordner>/sp_lib.php, im Archiv bzw. Pruefstand ../webfrontend/html/sp_lib.php.
 */
if (!function_exists('sp_bb_tun')) {
    $sp_bb_kand = array();
    if (basename(dirname(__DIR__)) === 'plugins') {
        $sp_bb_kand[] = dirname(dirname(dirname(__DIR__))) . '/webfrontend/html/plugins/' . basename(__DIR__) . '/sp_lib.php';
    }
    $sp_bb_kand[] = dirname(__DIR__) . '/webfrontend/html/sp_lib.php';
    foreach ($sp_bb_kand as $sp_bb_k) {
        if (is_file($sp_bb_k)) { require_once $sp_bb_k; break; }
    }
}
$sp_bb_ansage = 'faengt das Prellen einer Klingel ab; ein zweites Klingeln nach 10 s soll wieder angesagt werden; die '
    . 'Bremse gilt je Text, ein anderer Text geht sofort (Bauart Chromecast tts).';
/* Ein Eintrag: Art, Grenzen, eingreifend, Sperre, Titel, Kachel, Quittung. tun() reicht Name und Wert an sp_bb_tun(). */
$sp_bb_e = function ($name, $art, $eingreifend, $titel, $kachel, $sperre, $grund, $quittung, $extra = array()) {
    return array_merge(array(
        'art' => $art, 'eingreifend' => $eingreifend, 'titel' => 'Sprachsteuerung ' . $titel . ' (MQTT)',
        'kachel' => $kachel, 'quittung' => $quittung, 'sperre_s' => $sperre, 'begruendung' => $grund,
        'tun' => function ($w, $t) use ($name) {
            return sp_bb_tun($name, (string) $w);
        }), $extra);
};
return array(
    'fassung' => 1,
    'linie'   => 'sprachsteuerung',
    'plugin'  => 'Sprachsteuerung',
    'geladen' => function_exists('sp_bb_tun') && function_exists('sp_config')
                 && function_exists('sp_bb_einstellungen') && function_exists('sp_bb_protokoll'),
    'zweiter_haken' => 'befehle_mqtt_steuern',
    'einstellungen' => function () {
        return sp_bb_einstellungen();
    },
    'protokoll' => function ($stufe, $text) {
        sp_bb_protokoll($stufe, $text);
    },
    'befehle' => array(
        'sprechen' => $sp_bb_e('sprechen', 'text', true, 'Ansage', 'BEFEHL.K_SPRECHEN', 10, $sp_bb_ansage, 'uebergeben',
                               array('max_byte' => 1600)),
        'alarm'    => $sp_bb_e('alarm', 'text', true, 'Alarmansage', 'BEFEHL.K_ALARM', 10, $sp_bb_ansage, 'uebergeben',
                               array('max_byte' => 1600)),
        'satz'     => $sp_bb_e('satz', 'text', true, 'Satz', 'BEFEHL.K_SATZ', 0,
                               'Taster, jeder Druck zaehlt; derselbe Satz zweimal ist gewollt (Licht aus, dann noch einmal).',
                               'uebergeben', array('max_byte' => 1600)),
        'ruhe'     => $sp_bb_e('ruhe', 'schalter', false, 'Ansagen stilllegen', 'BEFEHL.K_RUHE', 0,
                               'Schaltuhr und Schalter senden ein-aus-ein binnen einer Minute; ein gleicher Wert schreibt nur '
                               . 'ruhe.json neu.', 'ausgefuehrt'),
    ),
);
