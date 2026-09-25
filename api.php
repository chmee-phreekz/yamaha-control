<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require __DIR__ . '/includes/YamahaSCP.php';
require __DIR__ . '/includes/db.php';
require __DIR__ . '/includes/channels.php';
require __DIR__ . '/includes/presets.php';

$config = require __DIR__ . '/config.php';
$action = $_GET['action'] ?? '';

function respond(array $data): void
{
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function respond_error(string $message, array $extra = []): void
{
    http_response_code(200); // bewusst 200: Frontend wertet "ok" aus, kein Transport-Fehler
    respond(array_merge(['ok' => false, 'error' => $message], $extra));
}

try {
    $pdo = ql1_db($config);
} catch (Throwable $e) {
    respond_error('Datenbankfehler: ' . $e->getMessage());
}

switch ($action) {

    // Schneller Erreichbarkeits-Check ohne Datenabfrage
    case 'ping': {
        $start = microtime(true);
        try {
            $scp = new YamahaSCP($config['mixer_ip'], $config['mixer_port'], $config['timeout']);
            $scp->connect();
            $scp->disconnect();
            respond([
                'ok' => true,
                'reachable' => true,
                'ms' => round((microtime(true) - $start) * 1000),
            ]);
        } catch (Throwable $e) {
            respond([
                'ok' => true,
                'reachable' => false,
                'error' => $e->getMessage(),
            ]);
        }
        break;
    }

    // Vollständiger Live-Status: InCh, StInCh, Mix, Mtrx (Name/Level/On)
    case 'status': {
        $categories = [
            'inch'   => (int)$config['inch_count'],
            'stinch' => (int)$config['stinch_count'],
            'mix'    => (int)$config['mix_count'],
            'mtrx'   => (int)$config['mtrx_count'],
        ];

        $commands = [];
        foreach ($categories as $cat => $count) {
            $commands += ql1_build_channel_commands($cat, $count);
        }

        try {
            $scp = new YamahaSCP($config['mixer_ip'], $config['mixer_port'], $config['timeout']);
            $scp->connect();
            $raw = $scp->sendBatch($commands);
            $scp->disconnect();
        } catch (Throwable $e) {
            respond_error('Verbindung zum Pult fehlgeschlagen: ' . $e->getMessage());
        }

        // Rohdaten strukturieren + parsen
        $result = [];
        $cacheRows = [];
        foreach ($categories as $cat => $count) {
            for ($i = 0; $i < $count; $i++) {
                $name  = YamahaSCP::parseResponse($raw["{$cat}.{$i}.name"]);
                $color = YamahaSCP::parseResponse($raw["{$cat}.{$i}.color"]);
                $level = YamahaSCP::parseResponse($raw["{$cat}.{$i}.level"]);
                $on    = YamahaSCP::parseResponse($raw["{$cat}.{$i}.on"]);

                $nameVal  = $name['value']  ?? null;
                if ($nameVal !== null && trim((string)$nameVal) === '') {
                    $nameVal = null; // leerer Name -> Platzhalter weiter unten verwenden
                }
                $levelVal = is_numeric($level['value'] ?? null) ? (float)$level['value'] : null;
                $onVal    = is_numeric($on['value'] ?? null) ? (bool)(int)$on['value'] : null;
                $colorVal = is_string($color['value'] ?? null) ? $color['value'] : null;

                $result[$cat][$i] = [
                    'index' => $i,
                    'name'  => $nameVal ?? '–',
                    'color' => $colorVal,
                    'level_raw' => $levelVal,
                    'level_db'  => ql1_level_to_db($levelVal),
                    'level_pct' => ql1_level_to_percent($levelVal),
                    'on'    => $onVal,
                    'status' => $name['status'],
                ];

                $cacheRows[] = ['category' => $cat, 'idx1' => $i, 'idx2' => 0, 'param' => 'name',  'value' => $nameVal ?? ''];
                $cacheRows[] = ['category' => $cat, 'idx1' => $i, 'idx2' => 0, 'param' => 'color', 'value' => $colorVal ?? ''];
                $cacheRows[] = ['category' => $cat, 'idx1' => $i, 'idx2' => 0, 'param' => 'level', 'value' => $levelVal ?? ''];
                $cacheRows[] = ['category' => $cat, 'idx1' => $i, 'idx2' => 0, 'param' => 'on',    'value' => $onVal === null ? '' : (int)$onVal];
            }
        }

        try {
            ql1_cache_store($pdo, $cacheRows);
        } catch (Throwable $e) {
            // Cache-Fehler sollen die Live-Anzeige nicht blockieren
        }

        respond([
            'ok' => true,
            'reachable' => true,
            'fetched_at' => time(),
            'data' => $result,
        ]);
        break;
    }

    // Mix -> Matrix Routing-Tabelle
    case 'routing': {
        $mixCount  = (int)$config['mix_count'];
        $mtrxCount = (int)$config['mtrx_count'];
        $commands = ql1_build_routing_commands($mixCount, $mtrxCount, true);

        try {
            $scp = new YamahaSCP($config['mixer_ip'], $config['mixer_port'], $config['timeout']);
            $scp->connect();
            $raw = $scp->sendBatch($commands);
            $scp->disconnect();
        } catch (Throwable $e) {
            respond_error('Verbindung zum Pult fehlgeschlagen: ' . $e->getMessage());
        }

        $grid = [];
        $cacheRows = [];
        for ($m = 0; $m < $mixCount; $m++) {
            for ($x = 0; $x < $mtrxCount; $x++) {
                $on    = YamahaSCP::parseResponse($raw["route.{$m}.{$x}.on"]);
                $level = YamahaSCP::parseResponse($raw["route.{$m}.{$x}.level"]);

                $onVal    = is_numeric($on['value'] ?? null) ? (bool)(int)$on['value'] : null;
                $levelVal = is_numeric($level['value'] ?? null) ? (float)$level['value'] : null;

                $grid[$m][$x] = [
                    'on' => $onVal,
                    'level_db' => ql1_level_to_db($levelVal),
                ];

                $cacheRows[] = ['category' => 'routing', 'idx1' => $m, 'idx2' => $x, 'param' => 'on', 'value' => $onVal === null ? '' : (int)$onVal];
                $cacheRows[] = ['category' => 'routing', 'idx1' => $m, 'idx2' => $x, 'param' => 'level', 'value' => $levelVal ?? ''];
            }
        }

        try {
            ql1_cache_store($pdo, $cacheRows);
        } catch (Throwable $e) {
            // ignorieren
        }

        respond([
            'ok' => true,
            'reachable' => true,
            'fetched_at' => time(),
            'grid' => $grid,
            'mix_count' => $mixCount,
            'mtrx_count' => $mtrxCount,
        ]);
        break;
    }

    // Wert auf dem Pult setzen (Fader-Level oder Mute/On)
    // Einzelnen Wert lesen (GET-Gegenstück zu 'set') – externe API zum
    // Auslesen von Fader-Level/On/Name/Color für InCh/StInCh/Mix/Mtrx.
    // Beispiel: api.php?action=get&category=inch&index=0&param=level
    case 'get': {
        $category = $_REQUEST['category'] ?? '';
        $index    = isset($_REQUEST['index']) ? (int)$_REQUEST['index'] : null;
        $param    = $_REQUEST['param'] ?? '';

        $counts = [
            'inch'   => (int)$config['inch_count'],
            'stinch' => (int)$config['stinch_count'],
            'mix'    => (int)$config['mix_count'],
            'mtrx'   => (int)$config['mtrx_count'],
        ];

        if (!isset($counts[$category]) || !isset(QL1_PATHS[$category][$param]) || $index === null) {
            respond_error('Ungültige Parameter für get. category=inch|stinch|mix|mtrx, param=level|on|name|color.');
        }
        if ($index < 0 || $index >= $counts[$category]) {
            respond_error('Kanalindex außerhalb des gültigen Bereichs.');
        }

        $path = QL1_PATHS[$category][$param];
        $cmd = "get {$path} {$index} 0";

        try {
            $scp = new YamahaSCP($config['mixer_ip'], $config['mixer_port'], $config['timeout']);
            $scp->connect();
            $raw = $scp->sendCommand($cmd);
            $scp->disconnect();
        } catch (Throwable $e) {
            respond_error('Verbindung zum Pult fehlgeschlagen: ' . $e->getMessage());
        }

        $parsed = YamahaSCP::parseResponse($raw);
        if ($parsed['status'] !== 'OK') {
            respond_error('Pult hat den Befehl nicht bestätigt.', ['raw' => $raw]);
        }

        $response = [
            'ok' => true,
            'category' => $category,
            'index' => $index,
            'param' => $param,
            'value' => $parsed['value'], // Rohwert, wie auch 'set' ihn erwartet
        ];
        if ($param === 'level' && is_numeric($parsed['value'])) {
            $response['value_db'] = ql1_level_to_db((float)$parsed['value']); // zur Orientierung
        }
        if ($param === 'on') {
            $response['value_bool'] = (bool)(int)$parsed['value'];
        }

        respond($response);
        break;
    }

    // Discovery: Kanalanzahl und Werteformate, damit externe Aufrufer wissen,
    // welche category/index/param-Kombinationen gültig sind.
    case 'config': {
        respond([
            'ok' => true,
            'channels' => [
                'inch'   => (int)$config['inch_count'],
                'stinch' => (int)$config['stinch_count'],
                'mix'    => (int)$config['mix_count'],
                'mtrx'   => (int)$config['mtrx_count'],
            ],
            'params' => [
                'level' => 'Rohwert = dB * 100 (z.B. -600 = -6.0 dB), -32768 = -unendlich. get liefert zusätzlich value_db.',
                'on'    => '0 oder 1 (Kanal an/aus, ON-Button). get liefert zusätzlich value_bool.',
                'name'  => 'String, bei set in Anführungszeichen nicht nötig (wird automatisch behandelt).',
                'color' => 'String, z.B. "Blue"/"Red"/"Off" - siehe README für die volle Liste.',
            ],
        ]);
        break;
    }

    case 'set': {
        $category = $_REQUEST['category'] ?? '';
        $index    = isset($_REQUEST['index']) ? (int)$_REQUEST['index'] : null;
        $param    = $_REQUEST['param'] ?? '';
        $rawValue = $_REQUEST['value'] ?? null;

        $counts = [
            'inch'   => (int)$config['inch_count'],
            'stinch' => (int)$config['stinch_count'],
            'mix'    => (int)$config['mix_count'],
            'mtrx'   => (int)$config['mtrx_count'],
        ];

        if (!isset($counts[$category]) || !in_array($param, ['level', 'on', 'name', 'color'], true)
            || $index === null || $rawValue === null || $rawValue === ''
        ) {
            respond_error('Ungültige Parameter für set. param muss level|on|name|color sein.');
        }
        if (in_array($param, ['level', 'on'], true) && !is_numeric($rawValue)) {
            respond_error('value muss für level/on numerisch sein.');
        }

        if (!isset(QL1_PATHS[$category][$param])) {
            respond_error('Unbekannter Pfad für set.');
        }

        if ($index < 0 || $index >= $counts[$category]) {
            respond_error('Kanalindex außerhalb des gültigen Bereichs.');
        }

        $path = QL1_PATHS[$category][$param];

        if ($param === 'level') {
            $value = (int)round((float)$rawValue);
            $value = max(QL1_LEVEL_NEG_INF, min(QL1_LEVEL_MAX, $value));
            $cmd = "set {$path} {$index} 0 {$value}";
        } elseif ($param === 'on') {
            $value = ((float)$rawValue) ? 1 : 0;
            $cmd = "set {$path} {$index} 0 {$value}";
        } else { // name / color: String, in Anführungszeichen senden
            $value = str_replace('"', "'", (string)$rawValue);
            $cmd = "set {$path} {$index} 0 \"{$value}\"";
        }

        try {
            $scp = new YamahaSCP($config['mixer_ip'], $config['mixer_port'], $config['timeout']);
            $scp->connect();
            $raw = $scp->sendCommand($cmd);
            $scp->disconnect();
        } catch (Throwable $e) {
            respond_error('Verbindung zum Pult fehlgeschlagen: ' . $e->getMessage());
        }

        $parsed = YamahaSCP::parseResponse($raw);
        if ($parsed['status'] !== 'OK') {
            respond_error('Pult hat den Befehl nicht bestätigt.', ['raw' => $raw]);
        }

        try {
            ql1_cache_store($pdo, [[
                'category' => $category, 'idx1' => $index, 'idx2' => 0,
                'param' => $param, 'value' => $value,
            ]]);
        } catch (Throwable $e) {
            // Cache-Fehler ignorieren, Set war erfolgreich
        }

        respond(['ok' => true, 'value' => $value, 'raw' => $raw, 'debug' => $scp->log]);
        break;
    }

    // EQ-Block eines Kanals auslesen (inkl. Rohverkehr fürs Debug-Panel im Modal)
    case 'eq_get': {
        $category = $_REQUEST['category'] ?? '';
        $index    = isset($_REQUEST['index']) ? (int)$_REQUEST['index'] : null;

        if (!in_array($category, ['inch', 'stinch'], true) || $index === null) {
            respond_error('Ungültige Parameter für eq_get.');
        }

        $commands = ql1_build_eq_get_commands($category, $index);

        try {
            $scp = new YamahaSCP($config['mixer_ip'], $config['mixer_port'], $config['timeout']);
            $scp->connect();
            $raw = $scp->sendBatch($commands);
            $scp->disconnect();
        } catch (Throwable $e) {
            respond_error('Verbindung zum Pult fehlgeschlagen: ' . $e->getMessage());
        }

        $hpfOn = YamahaSCP::parseResponse($raw['hpf.on']);
        $hpfFreq = YamahaSCP::parseResponse($raw['hpf.freq']);
        $eqOn = YamahaSCP::parseResponse($raw['eq_on']);

        $bands = [];
        for ($b = 0; $b < QL1_EQ_BAND_COUNT; $b++) {
            $freq = YamahaSCP::parseResponse($raw["band{$b}.freq"]);
            $gain = YamahaSCP::parseResponse($raw["band{$b}.gain"]);
            $q    = YamahaSCP::parseResponse($raw["band{$b}.q"]);
            $bands[] = [
                'freq_hz' => is_numeric($freq['value'] ?? null) ? (float)$freq['value'] : null,
                'gain_db' => is_numeric($gain['value'] ?? null) ? round((float)$gain['value'] / 100, 1) : null,
                'q'       => is_numeric($q['value'] ?? null) ? round((float)$q['value'] / 100, 2) : null,
                'status'  => $freq['status'],
            ];
        }

        respond([
            'ok' => true,
            'eq_on' => is_numeric($eqOn['value'] ?? null) ? (bool)(int)$eqOn['value'] : null,
            'hpf' => [
                'on'      => is_numeric($hpfOn['value'] ?? null) ? (bool)(int)$hpfOn['value'] : null,
                'freq_hz' => is_numeric($hpfFreq['value'] ?? null) ? (float)$hpfFreq['value'] : null,
                'status'  => $hpfFreq['status'],
            ],
            'bands' => $bands,
            'debug' => $scp->log,
        ]);
        break;
    }

    // Einen EQ- oder HPF-Wert setzen
    case 'eq_set': {
        $category = $_REQUEST['category'] ?? '';
        $index    = isset($_REQUEST['index']) ? (int)$_REQUEST['index'] : null;
        $unit     = $_REQUEST['unit'] ?? 'eq'; // 'eq' oder 'hpf'
        $field    = $_REQUEST['field'] ?? '';
        $band     = isset($_REQUEST['band']) ? (int)$_REQUEST['band'] : 0;
        $rawValue = $_REQUEST['value'] ?? null;

        if (!in_array($category, ['inch', 'stinch'], true) || $index === null
            || $rawValue === null || !is_numeric($rawValue)
        ) {
            respond_error('Ungültige Parameter für eq_set.');
        }

        $target = $category === 'stinch' ? 'StInCh' : 'InCh';
        $value = (float)$rawValue;

        if ($unit === 'hpf') {
            if (!isset(QL1_HPF_PATHS[$field])) {
                respond_error('Ungültiges HPF-Feld.');
            }
            $value = $field === 'on' ? ($value ? 1 : 0)
                : (int)round(max(QL1_HPF_FREQ_MIN, min(QL1_HPF_FREQ_MAX, $value)));
            $path = str_replace('InCh', $target, QL1_HPF_PATHS[$field]);
            $idx2 = 0;
        } else {
            if (!isset(QL1_EQ_PATHS[$field]) || $band < 0 || $band >= QL1_EQ_BAND_COUNT) {
                respond_error('Ungültiges EQ-Feld.');
            }
            if ($field === 'on') {
                $value = $value ? 1 : 0;
            } elseif ($field === 'freq') {
                $value = (int)round(max(QL1_EQ_FREQ_MIN, min(QL1_EQ_FREQ_MAX, $value)));
            } elseif ($field === 'gain') {
                $value = (int)round(max(QL1_EQ_GAIN_MIN, min(QL1_EQ_GAIN_MAX, $value)));
            } elseif ($field === 'q') {
                $value = (int)round(max(QL1_EQ_Q_MIN, min(QL1_EQ_Q_MAX, $value)));
            }
            $path = str_replace('InCh', $target, QL1_EQ_PATHS[$field]);
            $idx2 = $field === 'on' ? 0 : $band;
        }

        $cmd = "set {$path} {$index} {$idx2} {$value}";

        try {
            $scp = new YamahaSCP($config['mixer_ip'], $config['mixer_port'], $config['timeout']);
            $scp->connect();
            $raw = $scp->sendCommand($cmd);
            $scp->disconnect();
        } catch (Throwable $e) {
            respond_error('Verbindung zum Pult fehlgeschlagen: ' . $e->getMessage());
        }

        $parsed = YamahaSCP::parseResponse($raw);
        respond([
            'ok' => $parsed['status'] === 'OK',
            'status' => $parsed['status'],
            'value' => $value,
            'debug' => $scp->log,
        ]);
        break;
    }

    // Dynamics-Block eines Kanals auslesen (unit = 'dyna1' Gate oder 'dyna2' Kompressor)
    case 'dyn_get': {
        $category = $_REQUEST['category'] ?? '';
        $index    = isset($_REQUEST['index']) ? (int)$_REQUEST['index'] : null;
        $unit     = $_REQUEST['unit'] ?? 'dyna2';

        if (!in_array($category, ['inch', 'stinch'], true) || $index === null
            || !in_array($unit, ['dyna1', 'dyna2'], true)
        ) {
            respond_error('Ungültige Parameter für dyn_get.');
        }

        $commands = ql1_build_dyn_get_commands($category, $index, $unit);

        try {
            $scp = new YamahaSCP($config['mixer_ip'], $config['mixer_port'], $config['timeout']);
            $scp->connect();
            $raw = $scp->sendBatch($commands);
            $scp->disconnect();
        } catch (Throwable $e) {
            respond_error('Verbindung zum Pult fehlgeschlagen: ' . $e->getMessage());
        }

        $paths = $unit === 'dyna1' ? QL1_DYN1_PATHS : QL1_DYN2_PATHS;
        $values = [];
        foreach (array_keys($paths) as $key) {
            $parsed = YamahaSCP::parseResponse($raw[$key]);
            $values[$key] = [
                'raw'    => is_numeric($parsed['value'] ?? null) ? (float)$parsed['value'] : null,
                'status' => $parsed['status'],
            ];
        }

        respond(['ok' => true, 'unit' => $unit, 'values' => $values, 'debug' => $scp->log]);
        break;
    }

    // Einen Dynamics-Wert setzen (unit = 'dyna1' oder 'dyna2')
    case 'dyn_set': {
        $category = $_REQUEST['category'] ?? '';
        $index    = isset($_REQUEST['index']) ? (int)$_REQUEST['index'] : null;
        $unit     = $_REQUEST['unit'] ?? 'dyna2';
        $field    = $_REQUEST['field'] ?? '';
        $rawValue = $_REQUEST['value'] ?? null;

        if (!in_array($category, ['inch', 'stinch'], true) || $index === null
            || !in_array($unit, ['dyna1', 'dyna2'], true)
            || $rawValue === null || !is_numeric($rawValue)
        ) {
            respond_error('Ungültige Parameter für dyn_set.');
        }

        $paths = $unit === 'dyna1' ? QL1_DYN1_PATHS : QL1_DYN2_PATHS;
        $ranges = $unit === 'dyna1' ? QL1_DYN1_RANGES : QL1_DYN2_RANGES;

        if (!isset($paths[$field])) {
            respond_error('Ungültiges Dynamics-Feld.');
        }

        if ($field === 'on') {
            $value = ((float)$rawValue) ? 1 : 0;
        } else {
            [$min, $max] = $ranges[$field];
            $value = (int)round(max($min, min($max, (float)$rawValue)));
        }

        $target = $category === 'stinch' ? 'StInCh' : 'InCh';
        $path = str_replace('InCh', $target, $paths[$field]);
        $cmd = "set {$path} {$index} 0 {$value}";

        try {
            $scp = new YamahaSCP($config['mixer_ip'], $config['mixer_port'], $config['timeout']);
            $scp->connect();
            $raw = $scp->sendCommand($cmd);
            $scp->disconnect();
        } catch (Throwable $e) {
            respond_error('Verbindung zum Pult fehlgeschlagen: ' . $e->getMessage());
        }

        $parsed = YamahaSCP::parseResponse($raw);
        respond([
            'ok' => $parsed['status'] === 'OK',
            'status' => $parsed['status'],
            'value' => $value,
            'debug' => $scp->log,
        ]);
        break;
    }

    // Mehrere Fader-Werte in einer Verbindung setzen (für den Sinus-Demo-Modus,
    // damit nicht pro Kanal eine eigene TCP-Verbindung aufgebaut werden muss)
    case 'set_multi': {
        $body = json_decode(file_get_contents('php://input'), true);
        $items = is_array($body['items'] ?? null) ? $body['items'] : null;

        if (!$items || count($items) === 0) {
            respond_error('Keine gültigen Items für set_multi.');
        }

        $counts = [
            'inch'   => (int)$config['inch_count'],
            'stinch' => (int)$config['stinch_count'],
            'mix'    => (int)$config['mix_count'],
            'mtrx'   => (int)$config['mtrx_count'],
        ];

        $commands = [];
        $meta = [];
        foreach ($items as $i => $item) {
            $category = $item['category'] ?? '';
            $index    = isset($item['index']) ? (int)$item['index'] : null;
            $param    = $item['param'] ?? 'level';
            $value    = $item['value'] ?? null;

            if (!isset($counts[$category]) || $index === null || $index < 0 || $index >= $counts[$category]
                || !in_array($param, ['level', 'on'], true) || $value === null || !is_numeric($value)
            ) {
                continue; // ungültige Einträge stillschweigend überspringen
            }

            $v = $param === 'level'
                ? (int)round(max(QL1_LEVEL_NEG_INF, min(QL1_LEVEL_MAX, (float)$value)))
                : (((float)$value) ? 1 : 0);

            $path = QL1_PATHS[$category][$param];
            $key = "item{$i}";
            $commands[$key] = "set {$path} {$index} 0 {$v}";
            $meta[$key] = ['category' => $category, 'index' => $index, 'param' => $param, 'value' => $v];
        }

        if (empty($commands)) {
            respond_error('Keine gültigen Items in set_multi.');
        }

        try {
            $scp = new YamahaSCP($config['mixer_ip'], $config['mixer_port'], $config['timeout']);
            $scp->connect();
            $raw = $scp->sendBatch($commands);
            $scp->disconnect();
        } catch (Throwable $e) {
            respond_error('Verbindung zum Pult fehlgeschlagen: ' . $e->getMessage());
        }

        $results = [];
        foreach ($commands as $key => $cmd) {
            $parsed = YamahaSCP::parseResponse($raw[$key]);
            $results[] = array_merge($meta[$key], ['ok' => $parsed['status'] === 'OK']);
        }

        respond(['ok' => true, 'results' => $results]);
        break;
    }

    // Freien SCP/RCP-Befehl senden (Debug-Konsole) – genau eine Zeile, 1:1 ans Pult
    case 'raw_cmd': {
        $cmd = trim((string)($_REQUEST['cmd'] ?? ''));

        if ($cmd === '') {
            respond_error('Kein Befehl angegeben.');
        }
        if (strpos($cmd, "\n") !== false || strpos($cmd, "\r") !== false) {
            respond_error('Nur eine einzelne Zeile pro Befehl erlaubt.');
        }

        try {
            $scp = new YamahaSCP($config['mixer_ip'], $config['mixer_port'], $config['timeout']);
            $scp->connect();
            $raw = $scp->sendCommand($cmd);
            $scp->disconnect();
        } catch (Throwable $e) {
            respond_error('Verbindung zum Pult fehlgeschlagen: ' . $e->getMessage());
        }

        respond(['ok' => true, 'raw' => $raw, 'debug' => $scp->log]);
        break;
    }

    // Liste aller Preset-Plätze mit Name/Zeitstempel (ohne Detaildaten)
    case 'preset_list': {
        respond(['ok' => true, 'presets' => ql1_preset_list($config)]);
        break;
    }

    // Voll-Snapshot vom Pult lesen und als Preset-Datei speichern
    case 'preset_save': {
        $slot = isset($_REQUEST['slot']) ? (int)$_REQUEST['slot'] : null;
        $name = trim((string)($_REQUEST['name'] ?? ''));
        $count = (int)$config['preset_count'];

        if ($slot === null || $slot < 1 || $slot > $count) {
            respond_error('Ungültiger Preset-Slot.');
        }
        if ($name === '') {
            $name = "Preset {$slot}";
        }

        @set_time_limit(180); // Voll-Snapshot kann über 1000 Einzelbefehle umfassen

        $commands = ql1_snapshot_get_commands($config);

        try {
            $scp = new YamahaSCP($config['mixer_ip'], $config['mixer_port'], $config['timeout']);
            $scp->connect();
            $raw = $scp->sendBatch($commands);
            $scp->disconnect();
        } catch (Throwable $e) {
            respond_error('Verbindung zum Pult fehlgeschlagen: ' . $e->getMessage());
        }

        $state = ql1_snapshot_parse($config, $raw);
        $data = ['name' => $name, 'saved_at' => time(), 'state' => $state];

        if (!ql1_preset_write($config, $slot, $data)) {
            respond_error('Preset-Datei konnte nicht geschrieben werden (Berechtigungen prüfen).');
        }

        respond([
            'ok' => true,
            'slot' => $slot,
            'name' => $name,
            'saved_at' => $data['saved_at'],
            'command_count' => count($commands),
        ]);
        break;
    }

    // Preset-Datei lesen und alle enthaltenen Werte ans Pult senden
    case 'preset_load': {
        $slot = isset($_REQUEST['slot']) ? (int)$_REQUEST['slot'] : null;
        $count = (int)$config['preset_count'];

        if ($slot === null || $slot < 1 || $slot > $count) {
            respond_error('Ungültiger Preset-Slot.');
        }

        $data = ql1_preset_read($config, $slot);
        if ($data === null || !isset($data['state'])) {
            respond_error('Preset ist leer oder nicht vorhanden.');
        }

        @set_time_limit(180);

        $commands = ql1_snapshot_set_commands($config, $data['state']);
        if (empty($commands)) {
            respond_error('Preset enthält keine anwendbaren Werte.');
        }

        try {
            $scp = new YamahaSCP($config['mixer_ip'], $config['mixer_port'], $config['timeout']);
            $scp->connect();
            $raw = $scp->sendBatch($commands);
            $scp->disconnect();
        } catch (Throwable $e) {
            respond_error('Verbindung zum Pult fehlgeschlagen: ' . $e->getMessage());
        }

        $okCount = 0;
        foreach ($raw as $line) {
            if (YamahaSCP::parseResponse($line)['status'] === 'OK') {
                $okCount++;
            }
        }

        respond([
            'ok' => true,
            'slot' => $slot,
            'name' => $data['name'] ?? "Preset {$slot}",
            'command_count' => count($commands),
            'ok_count' => $okCount,
        ]);
        break;
    }

    // Letzten bekannten Stand aus der SQLite-Cache liefern (kein Live-Zugriff)
    case 'cached': {
        $type = $_GET['type'] ?? 'status';
        $categories = $type === 'routing' ? ['routing'] : ['inch', 'stinch', 'mix', 'mtrx'];

        try {
            $rows = ql1_cache_fetch($pdo, $categories);
        } catch (Throwable $e) {
            respond_error('Cache konnte nicht gelesen werden: ' . $e->getMessage());
        }

        respond([
            'ok' => true,
            'from_cache' => true,
            'rows' => $rows,
        ]);
        break;
    }

    default:
        respond_error('Unbekannte Aktion.');
}
