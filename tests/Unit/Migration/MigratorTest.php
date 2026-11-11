<?php

declare(strict_types=1);

namespace WatchRss\Tests\Unit\Migration;

use PDO;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use WatchRss\Migration\Backup;
use WatchRss\Migration\Migration;
use WatchRss\Migration\MigrationFailed;
use WatchRss\Migration\Migrator;

/**
 * Migrator mit SQLite im Speicher und Migrationsdateien im Temp-Verzeichnis.
 */
final class MigratorTest extends TestCase
{
    private PDO $pdo;
    private string $dir;
    private FakeBackup $backup;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $this->pdo->exec('CREATE TABLE log (entry TEXT)');
        $this->dir    = sys_get_temp_dir() . '/watchrss-migrations-' . bin2hex(random_bytes(4));
        $this->backup = new FakeBackup();
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->dir . '/*') ?: []);
        rmdir($this->dir);
    }

    public function testRunsPendingMigrationsInOrderAndRecordsThem(): void
    {
        $this->migration('0002_second', "INSERT INTO log VALUES ('2')");
        $this->migration('0001_first', "INSERT INTO log VALUES ('1')");

        $result = $this->migrator()->migrate();

        self::assertSame('/backup/1.sql.gz', $result['backup']);
        self::assertSame([1, 2], array_column($result['applied'], 'version'));
        self::assertSame(['1', '2'], $this->log());
        self::assertSame([], $this->migrator()->pending());
    }

    public function testSecondRunDoesNothingAndTakesNoBackup(): void
    {
        $this->migration('0001_first', "INSERT INTO log VALUES ('1')");
        $this->migrator()->migrate();

        $result = $this->migrator()->migrate();

        self::assertNull($result['backup']);
        self::assertSame([], $result['applied']);
        self::assertSame(1, $this->backup->calls);
        self::assertSame(['1'], $this->log());
    }

    public function testFailureRollsBackAndStopsFollowingMigrations(): void
    {
        $this->migration('0001_ok', "INSERT INTO log VALUES ('1')");
        $this->migration('0002_broken', "INSERT INTO log VALUES ('2')", fail: true);
        $this->migration('0003_never', "INSERT INTO log VALUES ('3')");

        try {
            $this->migrator()->migrate();
            self::fail('MigrationFailed erwartet');
        } catch (MigrationFailed $e) {
            self::assertSame(2, $e->version);
            self::assertSame([1], array_column($e->applied, 'version'));
            self::assertSame('/backup/1.sql.gz', $e->backupFile);
        }

        // Die Zeile aus 0002 ist zurückgerollt, 0003 lief nie.
        self::assertSame(['1'], $this->log());
        self::assertSame([2, 3], $this->migrator()->pending());
    }

    public function testNothingRunsWhenBackupFails(): void
    {
        $this->migration('0001_first', "INSERT INTO log VALUES ('1')");
        $this->backup->fail = true;

        try {
            $this->migrator()->migrate();
            self::fail('RuntimeException erwartet');
        } catch (RuntimeException $e) {
            self::assertStringContainsString('Backup', $e->getMessage());
        }

        self::assertSame([], $this->log());
        self::assertSame([1], $this->migrator()->pending());
    }

    public function testStatusShowsAppliedAndOpen(): void
    {
        $this->migration('0001_first', "INSERT INTO log VALUES ('1')");
        $this->migrator()->migrate();
        $this->migration('0002_second', "INSERT INTO log VALUES ('2')");

        $status = $this->migrator()->status();

        self::assertSame([1, 2], array_column($status, 'version'));
        self::assertNotNull($status[0]['applied_at']);
        self::assertNull($status[1]['applied_at']);
    }

    public function testRejectsInvalidFileName(): void
    {
        file_put_contents($this->dir . '/1_kaputt.php', '<?php return null;');

        $this->expectExceptionMessage('Ungültiger Migrationsname');
        $this->migrator()->status();
    }

    public function testRejectsDuplicateNumber(): void
    {
        $this->migration('0001_a', 'SELECT 1');
        $this->migration('0001_b', 'SELECT 1');

        $this->expectExceptionMessage('doppelt');
        $this->migrator()->status();
    }

    public function testRejectsFileWithoutMigration(): void
    {
        file_put_contents($this->dir . '/0001_leer.php', '<?php return 42;');

        $this->expectExceptionMessage('gibt keine Migration zurück');
        $this->migrator()->status();
    }

    public function testShippedMigrationsAreValid(): void
    {
        $migrator = new Migrator($this->pdo, dirname(__DIR__, 3) . '/migrations', null);
        $status   = $migrator->status();

        self::assertNotEmpty($status);
        self::assertSame(range(1, count($status)), array_column($status, 'version'), 'Nummern lückenlos ab 0001');
        foreach ($status as $entry) {
            self::assertNotSame('', $entry['description']);
        }
    }

    private function migrator(): Migrator
    {
        return new Migrator($this->pdo, $this->dir, $this->backup);
    }

    private function migration(string $name, string $sql, bool $fail = false): void
    {
        $body = '<?php return new class implements ' . Migration::class . ' {'
            . 'public function description(): string { return ' . var_export($name, true) . '; }'
            . 'public function up(PDO $pdo, ' . \WatchRss\Migration\Schema::class . ' $schema): string {'
            . '$pdo->exec(' . var_export($sql, true) . ');'
            . ($fail ? 'throw new RuntimeException("absichtlich");' : '')
            . 'return "ok"; } };';
        file_put_contents($this->dir . '/' . $name . '.php', $body);
    }

    /** @return list<string> */
    private function log(): array
    {
        $stmt = $this->pdo->query('SELECT entry FROM log ORDER BY rowid');
        self::assertNotFalse($stmt);

        return array_values(array_map('strval', $stmt->fetchAll(PDO::FETCH_COLUMN)));
    }
}

final class FakeBackup implements Backup
{
    public int $calls = 0;
    public bool $fail = false;

    public function create(): string
    {
        if ($this->fail) {
            throw new RuntimeException('Backup fehlgeschlagen: Test');
        }

        return '/backup/' . (++$this->calls) . '.sql.gz';
    }
}
