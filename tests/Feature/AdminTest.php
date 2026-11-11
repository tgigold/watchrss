<?php

declare(strict_types=1);

namespace WatchRss\Tests\Feature;

use PDO;
use PHPUnit\Framework\TestCase;
use WatchRss\App;
use WatchRss\Http\Request;
use WatchRss\Http\Response;
use WatchRss\Support\Database;

/**
 * /admin mit SQLite im Speicher - ohne echte Datenbank.
 * ADMIN_TOKEN steht in tests/constants.php.
 */
final class AdminTest extends TestCase
{
    private const TOKEN = 'test-admin-token-0123456789';

    protected function setUp(): void
    {
        // Sperr-Zähler aus vorherigen Tests entfernen.
        array_map('unlink', glob(CACHE_DIR . '/*') ?: []);
    }

    public function testShowsLoginFormWithoutStatus(): void
    {
        $response = $this->request('GET');

        self::assertSame(200, $response->status);
        self::assertStringContainsString('name="token"', $response->body);
        self::assertStringNotContainsString('Migrationen</h2>', $response->body);
        self::assertSame('no-store', $response->headers['Cache-Control']);
    }

    public function testWrongTokenIsRejected(): void
    {
        $response = $this->request('POST', ['token' => 'falsch']);

        self::assertSame(403, $response->status);
        self::assertStringNotContainsString('0001', $response->body);
    }

    public function testTokenInQueryStringIsIgnored(): void
    {
        $response = $this->request('POST', [], ['token' => self::TOKEN]);

        self::assertSame(403, $response->status);
    }

    public function testArrayAsTokenIsRejectedWithoutError(): void
    {
        self::assertSame(403, $this->request('POST', ['token' => [self::TOKEN]])->status);
    }

    public function testLocksAfterTooManyFailures(): void
    {
        for ($i = 0; $i < (int) ADMIN_MAX_FAILURES; $i++) {
            $this->request('POST', ['token' => 'falsch']);
        }

        $response = $this->request('POST', ['token' => self::TOKEN]);

        self::assertSame(429, $response->status);
        self::assertArrayHasKey('Retry-After', $response->headers);
    }

    public function testCorrectTokenShowsMigrationStatus(): void
    {
        $response = $this->request('POST', ['token' => self::TOKEN]);

        self::assertSame(200, $response->status);
        self::assertStringContainsString('0001', $response->body);
        self::assertStringContainsString('offen', $response->body);
        self::assertStringContainsString('value="migrate"', $response->body);
    }

    /**
     * @param array<string,mixed> $body
     * @param array<string,mixed> $query
     */
    private function request(string $method, array $body = [], array $query = []): Response
    {
        $pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $app = new App(dirname(__DIR__, 2), Database::fromPdo($pdo));

        return $app->handle(new Request(
            method: $method,
            path: '/admin',
            basePath: '',
            query: $query,
            body: $body,
            clientIp: '203.0.113.9',
            secure: true,
            host: 'watchrss.de',
            configuredBaseUrl: 'https://watchrss.de',
        ));
    }
}
