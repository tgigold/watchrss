<?php

declare(strict_types=1);

namespace WatchRss\Migration;

use PDO;

/**
 * Abfragen zum aktuellen Schema (MySQL/MariaDB, information_schema).
 */
final class Schema
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function hasTable(string $table): bool
    {
        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*) FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?'
        );
        $stmt->execute([$table]);

        return (int) $stmt->fetchColumn() > 0;
    }

    public function hasColumn(string $table, string $column): bool
    {
        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?'
        );
        $stmt->execute([$table, $column]);

        return (int) $stmt->fetchColumn() > 0;
    }

    public function isNotNull(string $table, string $column): bool
    {
        $stmt = $this->pdo->prepare(
            'SELECT IS_NULLABLE FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?'
        );
        $stmt->execute([$table, $column]);

        return $stmt->fetchColumn() === 'NO';
    }

    public function hasIndex(string $table, string $index): bool
    {
        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*) FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?'
        );
        $stmt->execute([$table, $index]);

        return (int) $stmt->fetchColumn() > 0;
    }
}
