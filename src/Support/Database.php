<?php

declare(strict_types=1);

namespace WatchRss\Support;

use PDO;
use PDOException;
use PDOStatement;
use RuntimeException;

/**
 * Dünne Hülle um PDO.
 *
 * Die Verbindung wird erst beim ersten Zugriff aufgebaut (lazy), damit
 * Seiten ohne DB-Zugriff nicht unnötig verbinden.
 */
final class Database
{
    private ?PDO $pdo = null;

    public function __construct(
        private readonly string $host,
        private readonly string $port,
        private readonly string $name,
        private readonly string $user,
        private readonly string $password,
        private readonly string $charset = 'utf8mb4',
    ) {
    }

    /**
     * Erzeugt eine Instanz aus den Konstanten der config.inc.php.
     */
    public static function fromConfig(): self
    {
        return new self(
            (string) DB_HOST,
            (string) DB_PORT,
            (string) DB_NAME,
            (string) DB_USER,
            (string) DB_PASS,
            defined('DB_CHARSET') ? (string) DB_CHARSET : 'utf8mb4',
        );
    }

    /**
     * Statement ohne Parameter (z. B. COUNT(*), SHOW TABLES). Mit
     * ERRMODE_EXCEPTION wirft PDO bei Fehlern ohnehin; false wird hier
     * trotzdem abgefangen, damit Aufrufer immer ein Statement bekommen.
     */
    public function query(string $sql): PDOStatement
    {
        $stmt = $this->pdo()->query($sql);
        if ($stmt === false) {
            throw new RuntimeException('Abfrage fehlgeschlagen.');
        }

        return $stmt;
    }

    public function pdo(): PDO
    {
        if ($this->pdo instanceof PDO) {
            return $this->pdo;
        }

        $dsn = sprintf(
            'mysql:host=%s;port=%s;dbname=%s;charset=%s',
            $this->host,
            $this->port,
            $this->name,
            $this->charset,
        );

        try {
            $this->pdo = new PDO($dsn, $this->user, $this->password, [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                // Echte Prepared Statements statt Emulation: saubere Typen,
                // insbesondere bei LIMIT-Parametern.
                PDO::ATTR_EMULATE_PREPARES   => false,
                PDO::ATTR_STRINGIFY_FETCHES  => false,
            ]);
        } catch (PDOException $e) {
            throw new RuntimeException('Datenbankverbindung fehlgeschlagen: ' . $e->getMessage(), 0, $e);
        }

        // Serverseitige Zeitzone auf UTC zwingen, damit NOW() und PHP
        // dieselbe Basis benutzen - unabhängig vom Hosting-Default.
        $this->pdo->exec("SET time_zone = '+00:00'");

        return $this->pdo;
    }

    /**
     * Führt einen Callback innerhalb einer Transaktion aus.
     *
     * @template T
     * @param  callable(PDO):T $callback
     * @return T
     */
    public function transaction(callable $callback): mixed
    {
        $pdo = $this->pdo();
        $pdo->beginTransaction();

        try {
            $result = $callback($pdo);
            $pdo->commit();

            return $result;
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }
}
