<?php
/**
 * SQLite-Anbindung.
 * Dient aktuell als Cache (letzter bekannter Stand des Pults, damit die
 * Oberfläche sofort etwas anzeigen kann, bevor die Live-Abfrage fertig ist)
 * und als Grundlage für später geplante gespeicherte "Setups"/Szenen.
 */

function ql1_db(array $config): PDO
{
    static $pdo = null;
    if ($pdo !== null) {
        return $pdo;
    }

    $dir = dirname($config['db_path']);
    if (!is_dir($dir)) {
        mkdir($dir, 0775, true);
    }

    $pdo = new PDO('sqlite:' . $config['db_path']);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec('PRAGMA journal_mode = WAL;');

    $pdo->exec('
        CREATE TABLE IF NOT EXISTS cache (
            category   TEXT NOT NULL,
            idx1       INTEGER NOT NULL,
            idx2       INTEGER NOT NULL,
            param      TEXT NOT NULL,
            value      TEXT,
            updated_at INTEGER NOT NULL,
            PRIMARY KEY (category, idx1, idx2, param)
        );
    ');

    // Grundgerüst für später: gespeicherte Setups/Szenen aus dieser Webapp
    // (unabhängig von den Szenenspeichern des Pults selbst).
    $pdo->exec('
        CREATE TABLE IF NOT EXISTS setups (
            id         INTEGER PRIMARY KEY AUTOINCREMENT,
            name       TEXT NOT NULL,
            created_at INTEGER NOT NULL,
            data       TEXT NOT NULL
        );
    ');

    return $pdo;
}

/**
 * Speichert einen kompletten Snapshot (category => [ [idx1, idx2, param, value], ... ])
 * in einer Transaktion.
 */
function ql1_cache_store(PDO $pdo, array $rows): void
{
    $stmt = $pdo->prepare('
        INSERT INTO cache (category, idx1, idx2, param, value, updated_at)
        VALUES (:category, :idx1, :idx2, :param, :value, :updated_at)
        ON CONFLICT(category, idx1, idx2, param) DO UPDATE SET
            value = excluded.value,
            updated_at = excluded.updated_at
    ');

    $now = time();
    $pdo->beginTransaction();
    foreach ($rows as $row) {
        $stmt->execute([
            ':category'   => $row['category'],
            ':idx1'       => $row['idx1'],
            ':idx2'       => $row['idx2'],
            ':param'      => $row['param'],
            ':value'      => is_bool($row['value']) ? (int)$row['value'] : (string)$row['value'],
            ':updated_at' => $now,
        ]);
    }
    $pdo->commit();
}

/**
 * Liest alle gecachten Zeilen einer oder mehrerer Kategorien.
 */
function ql1_cache_fetch(PDO $pdo, array $categories): array
{
    $placeholders = implode(',', array_fill(0, count($categories), '?'));
    $stmt = $pdo->prepare("SELECT * FROM cache WHERE category IN ($placeholders)");
    $stmt->execute($categories);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}
