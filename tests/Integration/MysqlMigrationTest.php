<?php

declare(strict_types=1);

namespace WatchRss\Tests\Integration;

use PDO;
use PHPUnit\Framework\TestCase;
use WatchRss\Migration\Migrator;
use WatchRss\Migration\Schema;
use WatchRss\Migration\SqlDumpBackup;
use WatchRss\Migration\SqlFile;

/**
 * Migrationen und Backup gegen ein echtes MySQL/MariaDB.
 *
 * Läuft nur, wenn WATCHRSS_TEST_MYSQL_DSN gesetzt ist (in der CI: ein
 * Wegwerf-Container). Die Datenbank wird bei jedem Test GELEERT - niemals
 * auf eine echte Datenbank zeigen lassen.
 */
final class MysqlMigrationTest extends TestCase
{
    private PDO $pdo;
    private string $backupDir;

    protected function setUp(): void
    {
        $dsn = getenv('WATCHRSS_TEST_MYSQL_DSN');
        if (!is_string($dsn) || $dsn === '') {
            self::markTestSkipped('WATCHRSS_TEST_MYSQL_DSN nicht gesetzt.');
        }

        $this->pdo = new PDO($dsn, (string) getenv('WATCHRSS_TEST_MYSQL_USER'), (string) getenv('WATCHRSS_TEST_MYSQL_PASS'), [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        $this->dropAllTables();

        $this->backupDir = sys_get_temp_dir() . '/watchrss-mysql-backups-' . bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        if (isset($this->backupDir)) {
            array_map('unlink', glob($this->backupDir . '/*') ?: []);
            @rmdir($this->backupDir);
        }
    }

    public function testFreshInstallNeedsNoChanges(): void
    {
        $this->loadSchema();

        $result = $this->migrator(withBackup: false)->migrate();

        self::assertSame([1], array_column($result['applied'], 'version'));
        self::assertStringContainsString('nichts zu tun', $result['applied'][0]['result']);
        $this->assertCurrentFeedsSchema();
    }

    public function testLegacyTokenColumnIsMigratedWithBackup(): void
    {
        $this->loadSchema();
        $this->downgradeFeedsToLegacy();
        $this->pdo->exec("INSERT INTO feeds (token, title) VALUES ('legacytokenAAAAAAAAAAAAAAAAAAAAA', 'Eins'), ('legacytokenBBBBBBBBBBBBBBBBBBBBB', 'Zwei')");

        $result = $this->migrator(withBackup: true)->migrate();

        self::assertNotNull($result['backup']);
        self::assertFileExists($result['backup']);
        $this->assertCurrentFeedsSchema();

        $rows = $this->rows('SELECT edit_token, feed_token, title FROM feeds ORDER BY id');
        self::assertSame('legacytokenAAAAAAAAAAAAAAAAAAAAA', $rows[0]['edit_token']);
        self::assertSame('legacytokenBBBBBBBBBBBBBBBBBBBBB', $rows[1]['edit_token']);
        self::assertSame(10, strlen((string) $rows[0]['feed_token']));
        self::assertNotSame($rows[0]['feed_token'], $rows[1]['feed_token']);
        self::assertSame([], $this->migrator(withBackup: true)->pending());
    }

    public function testBackupRestoresPreviousState(): void
    {
        $this->loadSchema();
        $this->downgradeFeedsToLegacy();
        $this->pdo->exec("INSERT INTO feeds (token, title) VALUES ('legacytokenCCCCCCCCCCCCCCCCCCCCC', 'Ümlaut \"quote\" ''single'' 😀')");

        $backup = $this->migrator(withBackup: true)->migrate()['backup'];
        self::assertIsString($backup);

        $dump = gzdecode((string) file_get_contents($backup));
        self::assertIsString($dump);
        foreach (SqlFile::statements($dump) as $statement) {
            $this->pdo->exec($statement);
        }

        $schema = new Schema($this->pdo);
        self::assertTrue($schema->hasColumn('feeds', 'token'));
        self::assertFalse($schema->hasColumn('feeds', 'edit_token'));
        self::assertSame(
            [['token' => 'legacytokenCCCCCCCCCCCCCCCCCCCCC', 'title' => 'Ümlaut "quote" \'single\' 😀']],
            $this->rows('SELECT token, title FROM feeds')
        );
        // Stand vor dem Lauf: Migration 0001 noch nicht eingetragen.
        self::assertSame([1], $this->migrator(withBackup: false)->pending());
    }

    private function migrator(bool $withBackup): Migrator
    {
        return new Migrator(
            $this->pdo,
            dirname(__DIR__, 2) . '/migrations',
            $withBackup ? new SqlDumpBackup($this->pdo, $this->backupDir) : null,
        );
    }

    private function loadSchema(): void
    {
        $sql = (string) file_get_contents(dirname(__DIR__, 2) . '/schema.sql');
        foreach (SqlFile::statements($sql) as $statement) {
            $this->pdo->exec($statement);
        }
    }

    /** Stand vor der Token-Aufteilung: eine Spalte feeds.token. */
    private function downgradeFeedsToLegacy(): void
    {
        $this->pdo->exec(
            'ALTER TABLE feeds
                DROP INDEX uniq_feeds_edit_token,
                DROP INDEX uniq_feeds_feed_token,
                DROP COLUMN edit_token,
                DROP COLUMN feed_token,
                ADD COLUMN token VARCHAR(64) NOT NULL AFTER id,
                ADD UNIQUE KEY uniq_feeds_token (token)'
        );
    }

    private function assertCurrentFeedsSchema(): void
    {
        $schema = new Schema($this->pdo);
        self::assertFalse($schema->hasColumn('feeds', 'token'));
        self::assertTrue($schema->isNotNull('feeds', 'edit_token'));
        self::assertTrue($schema->isNotNull('feeds', 'feed_token'));
        self::assertTrue($schema->hasIndex('feeds', 'uniq_feeds_feed_token'));
    }

    /** @return list<array<string,mixed>> */
    private function rows(string $sql): array
    {
        $stmt = $this->pdo->query($sql);
        self::assertNotFalse($stmt);

        return array_values($stmt->fetchAll());
    }

    private function dropAllTables(): void
    {
        $this->pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
        $stmt = $this->pdo->query('SHOW TABLES');
        foreach ($stmt !== false ? $stmt->fetchAll(PDO::FETCH_COLUMN) : [] as $table) {
            $this->pdo->exec('DROP TABLE `' . str_replace('`', '``', (string) $table) . '`');
        }
        $this->pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
    }
}
