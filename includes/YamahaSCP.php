<?php
/**
 * YamahaSCP
 * -------------------------------------------------
 * Minimaler TCP-Client für das Yamaha "SCP"-Textprotokoll, wie es von
 * QL/CL/TF-Mischpulten auf Port 49280 gesprochen wird.
 *
 * Hinweis zur Zuverlässigkeit:
 * Yamaha veröffentlicht dieses Protokoll nicht offiziell in vollem Umfang.
 * Diese Klasse basiert auf inoffiziell dokumentierten Befehlen (u.a. aus
 * Open-Source-Projekten wie companion-module-yamaha-rcp). Grundprinzip:
 *
 *   Anfrage:   get <Pfad> <Index1> <Index2>\n
 *   Antwort:   OK <Pfad> <Index1> <Index2> <Wert>\n
 *   Unerwartet: NOTIFY ... (Änderung durch anderen Client/Bediener)
 *   Fehler:    ERROR ...
 *
 * Über das "Debug"-Tab der Weboberfläche kann der komplette Rohverkehr
 * (gesendet/empfangen) eingesehen werden – das hilft, die Kommunikation
 * gegen das echte Pult zu verifizieren und ggf. anzupassen.
 */

class YamahaSCP
{
    private string $host;
    private int $port;
    private int $timeout;
    /** @var resource|null */
    private $socket = null;

    /** @var array<int, array{dir:string, line:string}> */
    public array $log = [];

    public function __construct(string $host, int $port = 49280, int $timeout = 3)
    {
        $this->host = $host;
        $this->port = $port;
        $this->timeout = $timeout;
    }

    public function connect(): void
    {
        $errno = 0;
        $errstr = '';
        $this->socket = @fsockopen($this->host, $this->port, $errno, $errstr, $this->timeout);
        if (!$this->socket) {
            throw new RuntimeException("Verbindung zu {$this->host}:{$this->port} fehlgeschlagen: $errstr ($errno)");
        }
        stream_set_timeout($this->socket, $this->timeout);
        stream_set_blocking($this->socket, true);
    }

    public function disconnect(): void
    {
        if ($this->socket) {
            fclose($this->socket);
            $this->socket = null;
        }
    }

    public function isConnected(): bool
    {
        return $this->socket !== null;
    }

    /**
     * Sendet einen einzelnen Befehl und liest genau eine relevante
     * Antwortzeile (OK/OKm/ERROR). NOTIFY-Zeilen (unaufgefordert, z.B.
     * weil jemand am Pult selbst etwas ändert) werden übersprungen.
     *
     * @return string|null Rohe Antwortzeile oder null bei Timeout
     */
    public function sendCommand(string $cmd): ?string
    {
        if (!$this->socket) {
            throw new RuntimeException('Nicht verbunden.');
        }

        fwrite($this->socket, $cmd . "\n");
        $this->log[] = ['dir' => 'send', 'line' => $cmd];

        // Schutz gegen endlose NOTIFY-Flut: maximal N Zeilen pro Befehl lesen
        for ($i = 0; $i < 20; $i++) {
            $line = fgets($this->socket, 4096);
            $meta = stream_get_meta_data($this->socket);

            if ($line === false) {
                if (!empty($meta['timed_out'])) {
                    $this->log[] = ['dir' => 'recv', 'line' => '(Timeout – keine Antwort)'];
                }
                return null;
            }

            $line = trim($line);
            if ($line === '') {
                continue;
            }

            $this->log[] = ['dir' => 'recv', 'line' => $line];

            if (str_starts_with($line, 'NOTIFY')) {
                continue; // unaufgeforderte Meldung, ignorieren und weiterlesen
            }

            return $line;
        }

        return null;
    }

    /**
     * Sendet mehrere Befehle nacheinander über dieselbe Verbindung.
     *
     * @param array<string, string> $commands Schlüssel => Befehl
     * @return array<string, string|null> Schlüssel => rohe Antwortzeile
     */
    public function sendBatch(array $commands): array
    {
        $results = [];
        foreach ($commands as $key => $cmd) {
            $results[$key] = $this->sendCommand($cmd);
        }
        return $results;
    }

    /**
     * Zerlegt eine rohe Antwortzeile in ihre Bestandteile.
     * Erwartetes Format: OK[m] <Pfad> <Idx1> <Idx2> <Wert...>
     * Der Wert kann eine Zahl oder ein in Anführungszeichen stehender String sein.
     */
    public static function parseResponse(?string $raw): array
    {
        if ($raw === null) {
            return ['status' => 'TIMEOUT', 'value' => null, 'raw' => null];
        }

        if (str_starts_with($raw, 'ERROR')) {
            return ['status' => 'ERROR', 'value' => null, 'raw' => $raw];
        }

        // Das QL1 spiegelt das Schlüsselwort (get/set) in der Antwort:
        //   OK get MIXER:Current/InCh/Label/Name 0 0 "HH1 1"
        // statt (wie in mancher Doku beschrieben) ohne das Schlüsselwort.
        // Beide Varianten werden hier akzeptiert.
        if (!preg_match('/^OKm?\s+(?:(?:get|set)\s+)?"?([^"\s]+)"?\s+(-?\d+)\s+(-?\d+)\s+(.*)$/', $raw, $m)) {
            // Format weicht ab (z.B. Antwort ohne Indizes) – Rohwert zurückgeben
            return ['status' => 'UNPARSED', 'value' => null, 'raw' => $raw];
        }

        $value = trim($m[4]);
        if (str_starts_with($value, '"') && str_ends_with($value, '"')) {
            $value = substr($value, 1, -1);
        } elseif (is_numeric($value)) {
            $value = strpos($value, '.') !== false ? (float)$value : (int)$value;
        }

        return [
            'status' => 'OK',
            'path'   => $m[1],
            'idx1'   => (int)$m[2],
            'idx2'   => (int)$m[3],
            'value'  => $value,
            'raw'    => $raw,
        ];
    }
}
