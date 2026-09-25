<?php
/**
 * Zentrale Definition der verwendeten SCP-Pfade je Kategorie sowie
 * Hilfsfunktionen zur Umrechnung von Pegel-Rohwerten in dB.
 *
 * Pegel-Rohwert = dB * 100 (Integer). -32768 bedeutet -unendlich (aus).
 * Bereich lt. inoffizieller Doku: -13800 (-138.00 dB) bis 1200 (+12.00 dB),
 * zzgl. Sonderwert -32768 für -unendlich (Mute-Rastpunkt am unteren Anschlag).
 * Die Fader-Oberfläche selbst nutzt für die Bedienung eine nicht-lineare
 * "Audio-Taper"-Kennlinie (siehe assets/app.js, FADER_TAPER) statt diesen
 * vollen Bereich linear abzubilden.
 */

const QL1_PATHS = [
    'inch'   => [
        'name'  => 'MIXER:Current/InCh/Label/Name',
        'color' => 'MIXER:Current/InCh/Label/Color',
        'level' => 'MIXER:Current/InCh/Fader/Level',
        'on'    => 'MIXER:Current/InCh/Fader/On',
    ],
    'stinch' => [
        'name'  => 'MIXER:Current/StInCh/Label/Name',
        'color' => 'MIXER:Current/StInCh/Label/Color',
        'level' => 'MIXER:Current/StInCh/Fader/Level',
        'on'    => 'MIXER:Current/StInCh/Fader/On',
    ],
    'mix'    => [
        'name'  => 'MIXER:Current/Mix/Label/Name',
        'color' => 'MIXER:Current/Mix/Label/Color',
        'level' => 'MIXER:Current/Mix/Fader/Level',
        'on'    => 'MIXER:Current/Mix/Fader/On',
    ],
    'mtrx'   => [
        'name'  => 'MIXER:Current/Mtrx/Label/Name',
        'color' => 'MIXER:Current/Mtrx/Label/Color',
        'level' => 'MIXER:Current/Mtrx/Fader/Level',
        'on'    => 'MIXER:Current/Mtrx/Fader/On',
    ],
    'routing' => [
        // Mix -> Matrix Routing (Send von jedem MIX-Bus zu jedem MATRIX-Bus)
        'on'    => 'MIXER:Current/Mix/ToMtrx/On',
        'level' => 'MIXER:Current/Mix/ToMtrx/Level',
    ],
];

/**
 * Kanalfarbe (Label/Color): Das Pult liefert den Klarnamen als String
 * (bestätigt, z.B. "Blue"), keinen Zahlenindex. Das Mapping Name -> Hex-Farbe
 * liegt im Frontend (assets/app.js, CHANNEL_COLORS).
 */

const QL1_LEVEL_MIN = -13800;
const QL1_LEVEL_MAX = 1200; // +12.00 dB
const QL1_LEVEL_NEG_INF = -32768;

function ql1_level_to_db(?float $raw): ?float
{
    if ($raw === null) {
        return null;
    }
    if ((int)$raw <= QL1_LEVEL_NEG_INF + 1000) {
        return null; // -unendlich
    }
    return round($raw / 100, 1);
}

function ql1_level_to_percent(?float $raw): float
{
    if ($raw === null || (int)$raw <= QL1_LEVEL_NEG_INF + 1000) {
        return 0.0;
    }
    $pct = ($raw - QL1_LEVEL_MIN) / (QL1_LEVEL_MAX - QL1_LEVEL_MIN) * 100;
    return max(0.0, min(100.0, $pct));
}

/**
 * EQ- und Dynamics(Kompressor)-Pfade.
 *
 * WICHTIG: Nur InCh/Dyna2/Threshold und InCh/Dyna1/Threshold sind über die
 * inoffizielle CLQL-Parameterliste (bitfocus/companion-module-yamaha-rcp)
 * tatsächlich als getestet markiert. Alle EQ-Pfade sowie Dyna2/Ratio,
 * /Attack, /Release, /Knee, /Gain und die /On-Schalter sind NICHT bestätigt,
 * sondern nach dem an anderer Stelle beobachteten Namensschema angenommen
 * ("MIXER:Current/<Kategorie>/<Block>/<Parameter>" mit Kanal- und ggf.
 * Band-Index). Diese müssen gegen das echte Pult verifiziert werden – die
 * Modals zeigen dafür ein Mini-Log mit dem tatsächlichen Rohverkehr.
 */

/**
 * EQ-, HPF- und Dynamics-Pfade.
 *
 * WICHTIG: Nur InCh/Dyna1/Threshold und InCh/Dyna2/Threshold sind über die
 * inoffizielle CLQL-Parameterliste (bitfocus/companion-module-yamaha-rcp)
 * tatsächlich als getestet markiert. Alle übrigen Pfade unten (HPF, EQ,
 * Dyna1/Range/Attack/Hold/Decay, Dyna2/Ratio/Attack/Release/OutGain/Knee,
 * alle /On-Schalter) sind NICHT bestätigt, sondern nach dem an anderer
 * Stelle beobachteten Namensschema angenommen. Die Struktur (welche Regler
 * es gibt, HPF getrennt vom EQ mit eigenem Schalter, die 4 EQ-Bänder mit
 * EINEM gemeinsamen An/Aus-Schalter, Low/High als Shelf ohne Q, Low-Mid/
 * High-Mid parametrisch mit Q, Dyna1=Gate mit Range/Attack/Hold/Decay,
 * Dyna2=Kompressor mit Ratio/Attack/Release/OutGain/Knee) stammt vom Nutzer
 * direkt vom echten QL1-Bildschirm und sollte stimmen – nur die Pfadnamen
 * selbst sind Best-Guess und müssen gegen das Pult verifiziert werden
 * (Mini-Log in den Modals).
 */

const QL1_HPF_PATHS = [
    'on'   => 'MIXER:Current/InCh/HPF/On',        // Kanal-Index, 0
    'freq' => 'MIXER:Current/InCh/HPF/Frequency',  // Kanal-Index, 0
];
const QL1_HPF_FREQ_MIN = 20;
const QL1_HPF_FREQ_MAX = 400;

// Band-Reihenfolge: 0=Low (L.Shelf), 1=Low-Mid (parametrisch), 2=High-Mid (parametrisch), 3=High (H.Shelf)
const QL1_EQ_BANDS = [
    ['key' => 'low',     'label' => 'LOW (L.Shelf)',  'hasQ' => false, 'defFreq' => 100,  'defGain' => 0],
    ['key' => 'lowmid',  'label' => 'LOW-MID',        'hasQ' => true,  'defFreq' => 400,  'defGain' => 0],
    ['key' => 'highmid', 'label' => 'HIGH-MID',       'hasQ' => true,  'defFreq' => 2000, 'defGain' => 0],
    ['key' => 'high',    'label' => 'HIGH (H.Shelf)', 'hasQ' => false, 'defFreq' => 8000, 'defGain' => 0],
];
const QL1_EQ_PATHS = [
    'on'   => 'MIXER:Current/InCh/Eq/On',      // Kanal-Index, 0 (EIN gemeinsamer Schalter für alle 4 Bänder)
    'freq' => 'MIXER:Current/InCh/Eq/FreqHz',  // Kanal-Index, Band-Index 0-3
    'gain' => 'MIXER:Current/InCh/Eq/Gain',    // Kanal-Index, Band-Index 0-3
    'q'    => 'MIXER:Current/InCh/Eq/Q',       // Kanal-Index, Band-Index 0-3 (nur bei Low-Mid/High-Mid relevant)
];
const QL1_EQ_BAND_COUNT = 4;
const QL1_EQ_FREQ_MIN = 20;
const QL1_EQ_FREQ_MAX = 20000;
const QL1_EQ_GAIN_MIN = -1500; // -15.00 dB (Rohwert = dB * 100, wie Fader)
const QL1_EQ_GAIN_MAX = 1500;  // +15.00 dB
const QL1_EQ_Q_MIN = 10;       // Q 0.10 (Rohwert = Q * 100, angenommen)
const QL1_EQ_Q_MAX = 1200;     // Q 12.00

// Dyna1 = Gate/Limiter
const QL1_DYN1_PATHS = [
    'on'        => 'MIXER:Current/InCh/Dyna1/On',
    'threshold' => 'MIXER:Current/InCh/Dyna1/Threshold', // bestätigt: -720..0, Rohwert = dB*10
    'range'     => 'MIXER:Current/InCh/Dyna1/Range',
    'attack'    => 'MIXER:Current/InCh/Dyna1/Attack',
    'hold'      => 'MIXER:Current/InCh/Dyna1/Hold',
    'decay'     => 'MIXER:Current/InCh/Dyna1/Decay',
];
const QL1_DYN1_RANGES = [
    'threshold' => [-720, 0],   // -72.0..0 dB, Faktor 10 (bestätigt)
    'range'     => [-600, 0],   // -60.0..0 dB, angenommen, Faktor 10
    'attack'    => [0, 1000],   // 0..100.0 ms, angenommen, Faktor 10
    'hold'      => [0, 20000],  // 0..2000.0 ms, angenommen, Faktor 10
    'decay'     => [10, 4000],  // 1.0..400.0 ms, angenommen, Faktor 10
];

// Dyna2 = Kompressor
const QL1_DYN2_PATHS = [
    'on'        => 'MIXER:Current/InCh/Dyna2/On',
    'threshold' => 'MIXER:Current/InCh/Dyna2/Threshold', // bestätigt: -540..0, Rohwert = dB*10
    'ratio'     => 'MIXER:Current/InCh/Dyna2/Ratio',
    'attack'    => 'MIXER:Current/InCh/Dyna2/Attack',
    'release'   => 'MIXER:Current/InCh/Dyna2/Release',
    'outgain'   => 'MIXER:Current/InCh/Dyna2/OutGain',
    'knee'      => 'MIXER:Current/InCh/Dyna2/Knee',
];
const QL1_DYN2_RANGES = [
    'threshold' => [-540, 0],   // -54.0..0 dB, Faktor 10 (bestätigt)
    'ratio'     => [10, 200],   // 1.0:1..20.0:1, angenommen, Faktor 10
    'attack'    => [0, 1000],   // 0..100.0 ms, angenommen, Faktor 10
    'release'   => [5, 40000],  // 5..4000 ms, angenommen, Faktor 10
    'outgain'   => [0, 240],    // 0..24.0 dB, angenommen, Faktor 10
    'knee'      => [0, 100],    // 0..100 %, angenommen, Faktor 1
];


function ql1_build_channel_commands(string $category, int $count): array
{
    $paths = QL1_PATHS[$category];
    $cmds = [];
    for ($i = 0; $i < $count; $i++) {
        $cmds["{$category}.{$i}.name"]  = "get {$paths['name']} {$i} 0";
        $cmds["{$category}.{$i}.color"] = "get {$paths['color']} {$i} 0";
        $cmds["{$category}.{$i}.level"] = "get {$paths['level']} {$i} 0";
        $cmds["{$category}.{$i}.on"]    = "get {$paths['on']} {$i} 0";
    }
    return $cmds;
}

/**
 * Baut die Befehlsliste für die Mix->Matrix Routing-Matrix.
 */
function ql1_build_routing_commands(int $mixCount, int $mtrxCount, bool $withLevel = false): array
{
    $paths = QL1_PATHS['routing'];
    $cmds = [];
    for ($m = 0; $m < $mixCount; $m++) {
        for ($x = 0; $x < $mtrxCount; $x++) {
            $cmds["route.{$m}.{$x}.on"] = "get {$paths['on']} {$m} {$x}";
            if ($withLevel) {
                $cmds["route.{$m}.{$x}.level"] = "get {$paths['level']} {$m} {$x}";
            }
        }
    }
    return $cmds;
}

/**
 * Baut die Befehlsliste zum Auslesen von HPF + komplettem EQ-Block
 * (pro Band: Ein/Aus, Frequenz, Gain, Q) eines Kanals.
 */
function ql1_build_eq_get_commands(string $category, int $index): array
{
    $target = $category === 'stinch' ? 'StInCh' : 'InCh';
    $cmds = [];

    foreach (QL1_HPF_PATHS as $key => $pathTemplate) {
        $path = str_replace('InCh', $target, $pathTemplate);
        $cmds["hpf.{$key}"] = "get {$path} {$index} 0";
    }

    foreach (['freq', 'gain', 'q'] as $p) {
        $path = str_replace('InCh', $target, QL1_EQ_PATHS[$p]);
        for ($b = 0; $b < QL1_EQ_BAND_COUNT; $b++) {
            $cmds["band{$b}.{$p}"] = "get {$path} {$index} {$b}";
        }
    }
    $onPath = str_replace('InCh', $target, QL1_EQ_PATHS['on']);
    $cmds['eq_on'] = "get {$onPath} {$index} 0";
    return $cmds;
}

/**
 * Baut die Befehlsliste zum Auslesen eines Dynamics-Blocks (Dyna1 oder Dyna2).
 */
function ql1_build_dyn_get_commands(string $category, int $index, string $unit): array
{
    $target = $category === 'stinch' ? 'StInCh' : 'InCh';
    $paths = $unit === 'dyna1' ? QL1_DYN1_PATHS : QL1_DYN2_PATHS;
    $cmds = [];
    foreach ($paths as $key => $pathTemplate) {
        $path = str_replace('InCh', $target, $pathTemplate);
        $cmds[$key] = "get {$path} {$index} 0";
    }
    return $cmds;
}
