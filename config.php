<?php
/**
 * Yamaha QL1 Regie – Konfiguration
 * -------------------------------------------------
 * Hier die IP-Adresse deines QL1 eintragen. Der Port 49280 ist der
 * Standard-Port für das Yamaha SCP-Protokoll (Steuerung QL/CL-Serie)
 * und muss in der Regel nicht verändert werden.
 */
return [
    // --- App ---
    'app_version' => '1.1.0',

    // --- Verbindung ---
    'mixer_ip'   => '192.168.0.128', // <-- IP-Adresse des QL1 anpassen
    'mixer_port' => 49280,
    'timeout'    => 3,               // Timeout in Sekunden pro Verbindung/Antwort

    // --- Kanalanzahl (Werksstandard QL1 – bei Bedarf anpassen) ---
    'inch_count'   => 32, // Mono-Eingangskanäle (InCh)
    'stinch_count' => 8,  // Stereo-Eingangskanäle (StInCh)
    'mix_count'    => 16, // MIX-Busse
    'mtrx_count'   => 8,  // MATRIX-Busse

    // --- Anzeige ---
    'poll_interval_ms' => 4000, // Auto-Refresh-Intervall für den Status-Tab

    // --- Cache / SQLite ---
    'db_path' => __DIR__ . '/data/cache.sqlite',

    // --- Presets ---
    'preset_count' => 8,                        // Anzahl der Preset-Plätze
    'preset_dir'   => __DIR__ . '/data/presets', // je Preset eine JSON-Datei preset_<slot>.json
];
