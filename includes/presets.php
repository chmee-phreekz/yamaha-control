<?php
/**
 * Presets: vollständige Snapshots des Pult-Zustands als JSON-Dateien unter
 * data/presets/preset_<slot>.json. Damit lassen sich Setups einfach als
 * Dateien exportieren/importieren (Datei kopieren = Preset kopieren).
 *
 * Ein Snapshot enthält alles, was diese Webapp bislang auslesen/steuern
 * kann: Fader (Name/Level/On) für InCh/StInCh/Mix/Mtrx, das komplette
 * Mix->Matrix-Routing, sowie EQ/HPF und Dynamics (Dyna1+Dyna2) für jeden
 * InCh/StInCh-Kanal. Das sind je nach Kanalanzahl weit über 1000 einzelne
 * SCP-Befehle in einer einzigen Verbindung – Speichern/Laden kann daher
 * spürbar dauern.
 */

function ql1_preset_path(array $config, int $slot): string
{
    return rtrim($config['preset_dir'], '/') . "/preset_{$slot}.json";
}

function ql1_preset_list(array $config): array
{
    $count = (int)$config['preset_count'];
    $out = [];
    for ($slot = 1; $slot <= $count; $slot++) {
        $path = ql1_preset_path($config, $slot);
        if (is_file($path)) {
            $json = json_decode((string)file_get_contents($path), true);
            $out[] = [
                'slot'     => $slot,
                'name'     => is_array($json) ? ($json['name'] ?? "Preset {$slot}") : "Preset {$slot}",
                'saved_at' => is_array($json) ? ($json['saved_at'] ?? null) : null,
                'exists'   => true,
            ];
        } else {
            $out[] = ['slot' => $slot, 'name' => '', 'saved_at' => null, 'exists' => false];
        }
    }
    return $out;
}

function ql1_preset_read(array $config, int $slot): ?array
{
    $path = ql1_preset_path($config, $slot);
    if (!is_file($path)) {
        return null;
    }
    $json = json_decode((string)file_get_contents($path), true);
    return is_array($json) ? $json : null;
}

function ql1_preset_write(array $config, int $slot, array $data): bool
{
    $dir = $config['preset_dir'];
    if (!is_dir($dir)) {
        mkdir($dir, 0775, true);
    }
    $path = ql1_preset_path($config, $slot);
    $ok = file_put_contents(
        $path,
        json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
    );
    return $ok !== false;
}

/**
 * Baut die komplette, eindeutig benannte Liste an get-Befehlen für einen
 * Voll-Snapshot des Pults.
 */
function ql1_snapshot_get_commands(array $config): array
{
    $cmds = [];

    $categories = [
        'inch'   => (int)$config['inch_count'],
        'stinch' => (int)$config['stinch_count'],
        'mix'    => (int)$config['mix_count'],
        'mtrx'   => (int)$config['mtrx_count'],
    ];
    foreach ($categories as $cat => $count) {
        foreach (ql1_build_channel_commands($cat, $count) as $key => $cmd) {
            $cmds["f.{$key}"] = $cmd; // Schlüssel bereits z.B. "inch.0.name" -> "f.inch.0.name"
        }
    }

    $cmds = array_merge(
        $cmds,
        ql1_build_routing_commands((int)$config['mix_count'], (int)$config['mtrx_count'], true)
    );

    foreach (['inch', 'stinch'] as $cat) {
        $count = $cat === 'inch' ? (int)$config['inch_count'] : (int)$config['stinch_count'];
        for ($i = 0; $i < $count; $i++) {
            foreach (ql1_build_eq_get_commands($cat, $i) as $k => $cmd) {
                $cmds["eq.{$cat}.{$i}.{$k}"] = $cmd;
            }
            foreach (['dyna1', 'dyna2'] as $unit) {
                foreach (ql1_build_dyn_get_commands($cat, $i, $unit) as $k => $cmd) {
                    $cmds["dyn.{$unit}.{$cat}.{$i}.{$k}"] = $cmd;
                }
            }
        }
    }

    return $cmds;
}

/**
 * Zerlegt die Antworten aus ql1_snapshot_get_commands() in eine
 * strukturierte, speicherbare Zustandsstruktur (Rohwerte, unskaliert).
 */
function ql1_snapshot_parse(array $config, array $raw): array
{
    $state = ['channels' => [], 'routing' => [], 'eq' => [], 'dyn' => []];

    $categories = [
        'inch'   => (int)$config['inch_count'],
        'stinch' => (int)$config['stinch_count'],
        'mix'    => (int)$config['mix_count'],
        'mtrx'   => (int)$config['mtrx_count'],
    ];
    foreach ($categories as $cat => $count) {
        $state['channels'][$cat] = [];
        for ($i = 0; $i < $count; $i++) {
            $name  = YamahaSCP::parseResponse($raw["f.{$cat}.{$i}.name"] ?? null);
            $color = YamahaSCP::parseResponse($raw["f.{$cat}.{$i}.color"] ?? null);
            $level = YamahaSCP::parseResponse($raw["f.{$cat}.{$i}.level"] ?? null);
            $on    = YamahaSCP::parseResponse($raw["f.{$cat}.{$i}.on"] ?? null);
            $state['channels'][$cat][] = [
                'name'  => is_string($name['value'] ?? null) ? $name['value'] : null,
                'color' => is_string($color['value'] ?? null) ? $color['value'] : null,
                'level' => is_numeric($level['value'] ?? null) ? (int)$level['value'] : null,
                'on'    => is_numeric($on['value'] ?? null) ? (bool)(int)$on['value'] : null,
            ];
        }
    }

    $mixCount = (int)$config['mix_count'];
    $mtrxCount = (int)$config['mtrx_count'];
    for ($m = 0; $m < $mixCount; $m++) {
        $row = [];
        for ($x = 0; $x < $mtrxCount; $x++) {
            $on    = YamahaSCP::parseResponse($raw["route.{$m}.{$x}.on"] ?? null);
            $level = YamahaSCP::parseResponse($raw["route.{$m}.{$x}.level"] ?? null);
            $row[] = [
                'on'    => is_numeric($on['value'] ?? null) ? (bool)(int)$on['value'] : null,
                'level' => is_numeric($level['value'] ?? null) ? (int)$level['value'] : null,
            ];
        }
        $state['routing'][] = $row;
    }

    foreach (['inch', 'stinch'] as $cat) {
        $count = $cat === 'inch' ? (int)$config['inch_count'] : (int)$config['stinch_count'];
        $state['eq'][$cat] = [];
        $state['dyn'][$cat] = [];
        for ($i = 0; $i < $count; $i++) {
            $hpfOn   = YamahaSCP::parseResponse($raw["eq.{$cat}.{$i}.hpf.on"] ?? null);
            $hpfFreq = YamahaSCP::parseResponse($raw["eq.{$cat}.{$i}.hpf.freq"] ?? null);
            $eqOn    = YamahaSCP::parseResponse($raw["eq.{$cat}.{$i}.eq_on"] ?? null);

            $bands = [];
            for ($b = 0; $b < QL1_EQ_BAND_COUNT; $b++) {
                $freq = YamahaSCP::parseResponse($raw["eq.{$cat}.{$i}.band{$b}.freq"] ?? null);
                $gain = YamahaSCP::parseResponse($raw["eq.{$cat}.{$i}.band{$b}.gain"] ?? null);
                $q    = YamahaSCP::parseResponse($raw["eq.{$cat}.{$i}.band{$b}.q"] ?? null);
                $bands[] = [
                    'freq' => is_numeric($freq['value'] ?? null) ? (int)$freq['value'] : null,
                    'gain' => is_numeric($gain['value'] ?? null) ? (int)$gain['value'] : null,
                    'q'    => is_numeric($q['value'] ?? null) ? (int)$q['value'] : null,
                ];
            }

            $state['eq'][$cat][] = [
                'hpf_on'   => is_numeric($hpfOn['value'] ?? null) ? (bool)(int)$hpfOn['value'] : null,
                'hpf_freq' => is_numeric($hpfFreq['value'] ?? null) ? (int)$hpfFreq['value'] : null,
                'eq_on'    => is_numeric($eqOn['value'] ?? null) ? (bool)(int)$eqOn['value'] : null,
                'bands'    => $bands,
            ];

            $dynaData = [];
            foreach (['dyna1' => QL1_DYN1_PATHS, 'dyna2' => QL1_DYN2_PATHS] as $unit => $paths) {
                $fields = [];
                foreach (array_keys($paths) as $field) {
                    $p = YamahaSCP::parseResponse($raw["dyn.{$unit}.{$cat}.{$i}.{$field}"] ?? null);
                    $fields[$field] = is_numeric($p['value'] ?? null)
                        ? ($field === 'on' ? (bool)(int)$p['value'] : (int)$p['value'])
                        : null;
                }
                $dynaData[$unit] = $fields;
            }
            $state['dyn'][$cat][] = $dynaData;
        }
    }

    return $state;
}

/**
 * Baut aus einer gespeicherten Zustandsstruktur die set-Befehle, um sie
 * wieder ans Pult zu senden. Felder mit Wert null (nicht ausgelesen oder
 * nicht vorhanden) werden übersprungen statt einen ungültigen Befehl zu senden.
 */
function ql1_snapshot_set_commands(array $config, array $state): array
{
    $cmds = [];
    $n = 0;

    $categories = [
        'inch'   => (int)$config['inch_count'],
        'stinch' => (int)$config['stinch_count'],
        'mix'    => (int)$config['mix_count'],
        'mtrx'   => (int)$config['mtrx_count'],
    ];
    foreach ($categories as $cat => $count) {
        $paths = QL1_PATHS[$cat];
        foreach (($state['channels'][$cat] ?? []) as $idx => $ch) {
            if ($idx >= $count) break;
            if (isset($ch['level'])) {
                $cmds['k' . $n++] = "set {$paths['level']} {$idx} 0 " . (int)$ch['level'];
            }
            if (isset($ch['on'])) {
                $cmds['k' . $n++] = "set {$paths['on']} {$idx} 0 " . ($ch['on'] ? 1 : 0);
            }
            if (isset($ch['color']) && $ch['color'] !== null) {
                $safeColor = str_replace('"', "'", (string)$ch['color']);
                $cmds['k' . $n++] = "set {$paths['color']} {$idx} 0 \"{$safeColor}\"";
            }
            if (!empty($ch['name'])) {
                $safeName = str_replace('"', "'", (string)$ch['name']);
                $cmds['k' . $n++] = "set {$paths['name']} {$idx} 0 \"{$safeName}\"";
            }
        }
    }

    $mtrxCount = (int)$config['mtrx_count'];
    foreach (($state['routing'] ?? []) as $m => $row) {
        foreach ($row as $x => $cell) {
            if ($x >= $mtrxCount) break;
            if (isset($cell['on'])) {
                $cmds['k' . $n++] = 'set ' . QL1_PATHS['routing']['on'] . " {$m} {$x} " . ($cell['on'] ? 1 : 0);
            }
            if (isset($cell['level'])) {
                $cmds['k' . $n++] = 'set ' . QL1_PATHS['routing']['level'] . " {$m} {$x} " . (int)$cell['level'];
            }
        }
    }

    foreach (['inch', 'stinch'] as $cat) {
        $target = $cat === 'stinch' ? 'StInCh' : 'InCh';

        foreach (($state['eq'][$cat] ?? []) as $idx => $eq) {
            if (isset($eq['hpf_on'])) {
                $path = str_replace('InCh', $target, QL1_HPF_PATHS['on']);
                $cmds['k' . $n++] = "set {$path} {$idx} 0 " . ($eq['hpf_on'] ? 1 : 0);
            }
            if (isset($eq['hpf_freq'])) {
                $path = str_replace('InCh', $target, QL1_HPF_PATHS['freq']);
                $cmds['k' . $n++] = "set {$path} {$idx} 0 " . (int)$eq['hpf_freq'];
            }
            if (isset($eq['eq_on'])) {
                $path = str_replace('InCh', $target, QL1_EQ_PATHS['on']);
                $cmds['k' . $n++] = "set {$path} {$idx} 0 " . ($eq['eq_on'] ? 1 : 0);
            }
            foreach (($eq['bands'] ?? []) as $b => $band) {
                foreach (['freq', 'gain', 'q'] as $p) {
                    if (isset($band[$p])) {
                        $path = str_replace('InCh', $target, QL1_EQ_PATHS[$p]);
                        $cmds['k' . $n++] = "set {$path} {$idx} {$b} " . (int)$band[$p];
                    }
                }
            }
        }

        foreach (($state['dyn'][$cat] ?? []) as $idx => $dynaData) {
            foreach (['dyna1' => QL1_DYN1_PATHS, 'dyna2' => QL1_DYN2_PATHS] as $unit => $paths) {
                $fields = $dynaData[$unit] ?? [];
                foreach ($paths as $field => $pathTemplate) {
                    if (!isset($fields[$field])) continue;
                    $path = str_replace('InCh', $target, $pathTemplate);
                    $v = $field === 'on' ? ($fields[$field] ? 1 : 0) : (int)$fields[$field];
                    $cmds['k' . $n++] = "set {$path} {$idx} 0 {$v}";
                }
            }
        }
    }

    return $cmds;
}
