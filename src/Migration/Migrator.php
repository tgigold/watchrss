<?php

declare(strict_types=1);

namespace WatchRss\Migration;

use PDO;
use RuntimeException;
use Throwable;

/**
 * Führt nummerierte Migrationen aus migrations/ aus.
 *
 * Welche Migrationen gelaufen sind, steht in der Tabelle schema_migrations.
 * Vor einem Lauf mit offenen Migrationen wird automatisch ein Backup
 * angelegt; schlägt das fehl, läuft nichts. Bricht eine Migration ab,
 * bleiben die folgenden unberührt.
 */
final class Migrator
{
    private const TABLE = 'schema_migrations';

    /** Dateiname: vierstellige Nummer, Unterstrich, Kleinbuchstaben/Ziffern/_. */
    private const FILE_PATTERN = '/^(\d{4})_([a-z0-9_]+)\.php$/';

    public function __construct(
        private readonly PDO $pdo,
        private readonly string $directory,
        private readonly ?Backup $backup,
    ) {
    }

    /**
     * Alle Migrationen mit Status, aufsteigend nach Nummer.
     *
     * @return list<array{version:int,name:string,description:string,applied_at:?string}>
     */
    public function status(): array
    {
        $applied = $this->applied();
        $result  = [];

        foreach ($this->available() as $version => $entry) {
            $result[] = [
                'version'     => $version,
                'name'        => $entry['name'],
                'description' => $entry['migration']->description(),
                'applied_at'  => $applied[$version] ?? null,
            ];
        }

        return $result;
    }

    /** @return list<int> Nummern der offenen Migrationen */
    public function pending(): array
    {
        return array_values(array_diff(array_keys($this->available()), array_keys($this->applied())));
    }

    /**
     * Führt alle offenen Migrationen aus.
     *
     * @return array{backup:?string,applied:list<array{version:int,name:string,result:string}>}
     *
     * @throws MigrationFailed wenn eine Migration scheitert (die vorher
     *                         erfolgreichen bleiben eingetragen)
     */
    public function migrate(): array
    {
        $available = $this->available();
        $pending   = $this->pending();

        if ($pending === []) {
            return ['backup' => null, 'applied' => []];
        }

        $backupFile = $this->backup?->create();
        $applied    = [];

        foreach ($pending as $version) {
            $entry = $available[$version];

            try {
                $this->pdo->beginTransaction();
                $result = $entry['migration']->up($this->pdo, new Schema($this->pdo));
                $this->record($version, $entry['name']);

                // DDL hat die Transaktion bei MySQL ggf. schon implizit beendet.
                if ($this->pdo->inTransaction()) {
                    $this->pdo->commit();
                }
            } catch (Throwable $e) {
                if ($this->pdo->inTransaction()) {
                    $this->pdo->rollBack();
                }

                throw new MigrationFailed($version, $entry['name'], $applied, $backupFile, $e);
            }

            $applied[] = ['version' => $version, 'name' => $entry['name'], 'result' => $result];
        }

        return ['backup' => $backupFile, 'applied' => $applied];
    }

    /** Legt schema_migrations an (portables SQL: MySQL und SQLite). */
    private function ensureTable(): void
    {
        $this->pdo->exec(
            'CREATE TABLE IF NOT EXISTS ' . self::TABLE . ' (
                version    INT          NOT NULL PRIMARY KEY,
                name       VARCHAR(190) NOT NULL,
                applied_at VARCHAR(19)  NOT NULL
            )'
        );
    }

    /** @return array<int,string> Nummer => Zeitpunkt (UTC) */
    private function applied(): array
    {
        $this->ensureTable();

        $stmt = $this->pdo->query('SELECT version, applied_at FROM ' . self::TABLE . ' ORDER BY version');
        if ($stmt === false) {
            throw new RuntimeException('schema_migrations nicht lesbar.');
        }

        $applied = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $applied[(int) $row['version']] = (string) $row['applied_at'];
        }

        return $applied;
    }

    private function record(int $version, string $name): void
    {
        $stmt = $this->pdo->prepare('INSERT INTO ' . self::TABLE . ' (version, name, applied_at) VALUES (?, ?, ?)');
        $stmt->execute([$version, $name, gmdate('Y-m-d H:i:s')]);
    }

    /**
     * @return array<int,array{name:string,migration:Migration}> sortiert nach Nummer
     */
    private function available(): array
    {
        $files = glob($this->directory . '/*.php') ?: [];
        $found = [];

        foreach ($files as $file) {
            $basename = basename($file);
            if (preg_match(self::FILE_PATTERN, $basename, $m) !== 1) {
                throw new RuntimeException('Ungültiger Migrationsname: ' . $basename . ' (erwartet NNNN_name.php)');
            }

            $version = (int) $m[1];
            if (isset($found[$version])) {
                throw new RuntimeException('Migrationsnummer doppelt vergeben: ' . $m[1]);
            }

            $migration = require $file;
            if (!$migration instanceof Migration) {
                throw new RuntimeException($basename . ' gibt keine Migration zurück.');
            }

            $found[$version] = ['name' => $m[2], 'migration' => $migration];
        }

        ksort($found);

        return $found;
    }
}
