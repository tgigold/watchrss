<?php

declare(strict_types=1);

namespace WatchRss\Migration;

use PDO;
use RuntimeException;

/**
 * SQL-Dump in PHP (MySQL/MariaDB) - funktioniert auch ohne Shell-Zugang
 * und ohne mysqldump, also auf jedem Webhosting.
 *
 * Ergebnis: var/backups/backup-YYYYmmdd-HHMMSS.sql.gz, importierbar per
 * phpMyAdmin oder "gunzip < datei | mysql <db>". Es werden höchstens
 * $keep Backups aufbewahrt, ältere werden gelöscht.
 */
final class SqlDumpBackup implements Backup
{
    private const ROWS_PER_INSERT = 200;

    public function __construct(
        private readonly PDO $pdo,
        private readonly string $directory,
        private readonly int $keep = 10,
    ) {
    }

    public function create(): string
    {
        if (!is_dir($this->directory) && !@mkdir($this->directory, 0775, true)) {
            throw new RuntimeException('Backup-Verzeichnis nicht anlegbar: ' . $this->directory);
        }

        $file   = $this->directory . '/backup-' . gmdate('Ymd-His') . '.sql.gz';
        $handle = @gzopen($file, 'wb6');
        if ($handle === false) {
            throw new RuntimeException('Backup-Datei nicht schreibbar: ' . $file);
        }

        try {
            $this->write($handle, "-- watchrss Backup " . gmdate('Y-m-d H:i:s') . " UTC\n");
            $this->write($handle, "SET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS = 0;\n\n");

            foreach ($this->tables() as $table) {
                $this->dumpTable($handle, $table);
            }

            $this->write($handle, "SET FOREIGN_KEY_CHECKS = 1;\n");
        } catch (\Throwable $e) {
            gzclose($handle);
            @unlink($file);
            throw new RuntimeException('Backup fehlgeschlagen: ' . $e->getMessage(), 0, $e);
        }

        gzclose($handle);
        $this->prune();

        return $file;
    }

    /** @return list<string> */
    private function tables(): array
    {
        $stmt = $this->pdo->query("SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'");
        if ($stmt === false) {
            throw new RuntimeException('Tabellenliste nicht lesbar.');
        }

        $tables = [];
        foreach ($stmt->fetchAll(PDO::FETCH_NUM) as $row) {
            $tables[] = (string) $row[0];
        }

        return $tables;
    }

    /** @param resource $handle */
    private function dumpTable($handle, string $table): void
    {
        $quoted = '`' . str_replace('`', '``', $table) . '`';

        $create = $this->pdo->query('SHOW CREATE TABLE ' . $quoted);
        $row    = $create !== false ? $create->fetch(PDO::FETCH_NUM) : false;
        if (!is_array($row)) {
            throw new RuntimeException('Struktur von ' . $table . ' nicht lesbar.');
        }

        $this->write($handle, "DROP TABLE IF EXISTS {$quoted};\n" . $row[1] . ";\n\n");

        $rows = $this->pdo->query('SELECT * FROM ' . $quoted);
        if ($rows === false) {
            throw new RuntimeException('Daten von ' . $table . ' nicht lesbar.');
        }

        $batch = [];
        while (($record = $rows->fetch(PDO::FETCH_NUM)) !== false) {
            $batch[] = '(' . implode(',', array_map($this->literal(...), $record)) . ')';
            if (count($batch) >= self::ROWS_PER_INSERT) {
                $this->write($handle, "INSERT INTO {$quoted} VALUES\n" . implode(",\n", $batch) . ";\n");
                $batch = [];
            }
        }
        if ($batch !== []) {
            $this->write($handle, "INSERT INTO {$quoted} VALUES\n" . implode(",\n", $batch) . ";\n");
        }

        $this->write($handle, "\n");
    }

    private function literal(mixed $value): string
    {
        return match (true) {
            $value === null  => 'NULL',
            is_int($value), is_float($value) => (string) $value,
            is_bool($value)  => $value ? '1' : '0',
            default          => (string) $this->pdo->quote((string) $value),
        };
    }

    /** @param resource $handle */
    private function write($handle, string $data): void
    {
        if (gzwrite($handle, $data) === false) {
            throw new RuntimeException('Schreiben fehlgeschlagen.');
        }
    }

    private function prune(): void
    {
        $files = glob($this->directory . '/backup-*.sql.gz') ?: [];
        sort($files);

        foreach (array_slice($files, 0, max(0, count($files) - $this->keep)) as $old) {
            @unlink($old);
        }
    }
}
