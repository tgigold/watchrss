<?php

declare(strict_types=1);

namespace WatchRss\Tests\Integration;

use PDO;
use PHPUnit\Framework\TestCase;
use WatchRss\Migration\Migrator;
use WatchRss\Migration\SqlFile;
use WatchRss\Repository\FeedRepository;
use WatchRss\Support\Database;
use WatchRss\Support\Token;

/**
 * Edit-Token: nur der Hash liegt in der Datenbank. Echtes MySQL/MariaDB,
 * nur mit WATCHRSS_TEST_MYSQL_DSN (Wegwerf-Container) - die DB wird geleert.
 */
final class MysqlFeedRepositoryTest extends TestCase
{
    private PDO $pdo;
    private FeedRepository $feeds;

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

        $this->pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
        $tables = $this->pdo->query('SHOW TABLES');
        foreach ($tables !== false ? $tables->fetchAll(PDO::FETCH_COLUMN) : [] as $table) {
            $this->pdo->exec('DROP TABLE `' . (string) $table . '`');
        }
        $this->pdo->exec('SET FOREIGN_KEY_CHECKS = 1');

        foreach (SqlFile::statements((string) file_get_contents(dirname(__DIR__, 2) . '/schema.sql')) as $statement) {
            $this->pdo->exec($statement);
        }
        (new Migrator($this->pdo, dirname(__DIR__, 2) . '/migrations', null))->migrate();

        $this->feeds = new FeedRepository(Database::fromPdo($this->pdo));
    }

    public function testCreateReturnsTokenOnceAndStoresOnlyItsHash(): void
    {
        $created = $this->feeds->create('Test', [], 120, 100, true);
        $token   = $created['editToken'];

        self::assertTrue(Token::isValidEdit($token));
        self::assertSame(Token::hash($token), $created['feed']->editTokenHash);

        $rows = $this->pdo->query('SELECT * FROM feeds');
        self::assertNotFalse($rows);
        $dump = json_encode($rows->fetchAll());
        self::assertIsString($dump);
        self::assertStringNotContainsString($token, $dump, 'Klartext-Token steht in der Datenbank');
    }

    public function testFindByEditTokenUsesHash(): void
    {
        $created = $this->feeds->create('Test', [], 120, 100, true);

        self::assertSame($created['feed']->id, $this->feeds->findByEditToken($created['editToken'])?->id);
        self::assertNull($this->feeds->findByEditToken(Token::edit()));
        // Wer nur den Hash kennt (z. B. aus einem DB-Leak), kommt nicht rein.
        self::assertNull($this->feeds->findByEditToken($created['feed']->editTokenHash));
    }
}
