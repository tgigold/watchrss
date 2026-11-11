<?php

declare(strict_types=1);

namespace WatchRss\Tests\Integration;

use PDO;
use PHPUnit\Framework\TestCase;
use WatchRss\App;
use WatchRss\Http\Request;
use WatchRss\Http\Response;
use WatchRss\Migration\Migrator;
use WatchRss\Migration\SqlFile;
use WatchRss\Support\Database;

/**
 * Der Weg eines Nutzers über HTTP: Feed anlegen, Editor öffnen, Feed-Adresse
 * rotieren, Feed abrufen. Echtes MySQL/MariaDB (Wegwerf-Container), kein
 * Netzwerk: Der Kanal liegt schon in der Datenbank.
 */
final class MysqlFeedFlowTest extends TestCase
{
    private const CHANNEL = 'UCaaaaaaaaaaaaaaaaaaaaaa';

    private App $app;

    protected function setUp(): void
    {
        $dsn = getenv('WATCHRSS_TEST_MYSQL_DSN');
        if (!is_string($dsn) || $dsn === '') {
            self::markTestSkipped('WATCHRSS_TEST_MYSQL_DSN nicht gesetzt.');
        }

        $pdo = new PDO($dsn, (string) getenv('WATCHRSS_TEST_MYSQL_USER'), (string) getenv('WATCHRSS_TEST_MYSQL_PASS'), [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);

        $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
        $tables = $pdo->query('SHOW TABLES');
        foreach ($tables !== false ? $tables->fetchAll(PDO::FETCH_COLUMN) : [] as $table) {
            $pdo->exec('DROP TABLE `' . (string) $table . '`');
        }
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');

        foreach (SqlFile::statements((string) file_get_contents(dirname(__DIR__, 2) . '/schema.sql')) as $statement) {
            $pdo->exec($statement);
        }
        (new Migrator($pdo, dirname(__DIR__, 2) . '/migrations', null))->migrate();

        $pdo->exec("INSERT INTO channels (channel_id, title, last_status) VALUES ('" . self::CHANNEL . "', 'Testkanal', 'ok')");

        array_map('unlink', glob(CACHE_DIR . '/*') ?: []);
        $this->app = new App(dirname(__DIR__, 2), Database::fromPdo($pdo));
    }

    public function testCreateEditRotateAndServe(): void
    {
        // Anlegen: der einzige Moment, in dem der Server den Token herausgibt.
        $created = $this->json($this->request('POST', '/api/feeds', [
            'title'       => 'Mein Feed',
            'ttl_minutes' => 120,
            'channels'    => [['channel_id' => self::CHANNEL, 'include_long' => true, 'include_shorts' => false]],
        ]), 201);

        $token = $created['edit_token'];
        self::assertIsString($token);
        self::assertSame('https://watchrss.de/e/' . $token, $created['edit_url']);
        self::assertArrayNotHasKey('edit_token', $created['feed'], 'Feed-Daten enthalten keinen Token');

        // Editor öffnen: Links tragen den Token aus der URL.
        $editor = $this->request('GET', '/e/' . $token);
        self::assertSame(200, $editor->status);
        self::assertStringContainsString('/api/feeds/' . $token, $editor->body);

        // Ändern.
        $updated = $this->json($this->request('POST', '/api/feeds/' . $token, [
            'title'    => 'Umbenannt',
            'channels' => [['channel_id' => self::CHANNEL]],
        ]), 200);
        self::assertSame('Umbenannt', $updated['feed']['title']);
        self::assertSame($token, $updated['edit_token']);

        // Feed-Adresse rotieren: neue öffentliche Adresse, Editor bleibt.
        $rotated = $this->json($this->request('POST', '/api/feeds/' . $token . '/rotate'), 200);
        self::assertNotSame($created['feed_token'], $rotated['feed_token']);
        self::assertSame($token, $rotated['edit_token']);

        // Der Feed ist unter der neuen Adresse abrufbar, unter der alten nicht.
        self::assertSame(200, $this->request('GET', '/f/' . $rotated['feed_token'])->status);
        self::assertSame(404, $this->request('GET', '/f/' . $created['feed_token'])->status);
    }

    public function testCollapseCookieIsRenderedServerSide(): void
    {
        $created = $this->json($this->request('POST', '/api/feeds', [
            'channels' => [['channel_id' => self::CHANNEL]],
        ]), 201);
        $path = '/e/' . $created['edit_token'];

        $open = $this->request('GET', $path)->body;
        self::assertStringContainsString('id="builder-steps">', $open);
        self::assertStringContainsString('aria-expanded="true"', $open);

        $closed = $this->request('GET', $path, cookies: ['watchrss_collapsed' => '1'])->body;
        self::assertStringContainsString('id="builder-steps" hidden', $closed);
        self::assertStringContainsString('aria-expanded="false"', $closed);

        // Unsinnige Werte werden ignoriert.
        $junk = $this->request('GET', $path, cookies: ['watchrss_collapsed' => ['x']])->body;
        self::assertStringContainsString('id="builder-steps">', $junk);
    }

    public function testUnknownEditTokenIsNotFound(): void
    {
        self::assertSame(404, $this->request('GET', '/e/' . str_repeat('a', 32))->status);
        self::assertSame(404, $this->request('POST', '/api/feeds/' . str_repeat('a', 32), ['channels' => []])->status);
    }

    /**
     * @param array<string,mixed> $body
     * @param array<string,mixed> $cookies
     */
    private function request(string $method, string $path, array $body = [], array $cookies = []): Response
    {
        return $this->app->handle(new Request(
            method: $method,
            path: $path,
            basePath: '',
            body: $body,
            headers: $method === 'POST' ? ['content-type' => 'application/json', 'origin' => 'https://watchrss.de'] : [],
            clientIp: '203.0.113.' . random_int(1, 250),
            secure: true,
            host: 'watchrss.de',
            configuredBaseUrl: 'https://watchrss.de',
            cookies: $cookies,
        ));
    }

    /** @return array<string,mixed> */
    private function json(Response $response, int $status): array
    {
        self::assertSame($status, $response->status, $response->body);
        $data = json_decode($response->body, true);
        self::assertIsArray($data);

        return $data;
    }
}
