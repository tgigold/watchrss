<?php

declare(strict_types=1);

namespace WatchRss\Tests\Feature;

use PHPUnit\Framework\TestCase;
use WatchRss\App;
use WatchRss\Http\Request;
use WatchRss\Http\Response;

/**
 * Ganze Requests durch App::handle() - nur Pfade, die ohne Datenbank
 * auskommen (Redirect, 404, Validierung, Web-Trigger).
 */
final class HttpTest extends TestCase
{
    private const BASE = 'https://watchrss.de';
    private const JSON = ['content-type' => 'application/json'];

    public function testPlainHttpIsRedirectedToConfiguredDomain(): void
    {
        $response = $this->handle('GET', '/e/abc', secure: false, query: ['x' => '1']);

        self::assertSame(301, $response->status);
        self::assertSame(self::BASE . '/e/abc?x=1', $response->headers['Location']);
    }

    public function testPostOverHttpKeepsMethod(): void
    {
        self::assertSame(308, $this->handle('POST', '/api/feeds', secure: false)->status);
    }

    public function testNoRedirectWithoutHttpsBaseUrl(): void
    {
        $response = $this->handle('GET', '/gibtsnicht', secure: false, host: 'localhost', base: '');

        self::assertSame(404, $response->status);
    }

    public function testSecurityHeadersOnEveryResponse(): void
    {
        $response = $this->handle('GET', '/gibtsnicht');

        self::assertSame(404, $response->status);
        self::assertStringContainsString("script-src 'self'", $response->headers['Content-Security-Policy']);
        self::assertStringContainsString("object-src 'none'", $response->headers['Content-Security-Policy']);
        self::assertSame('DENY', $response->headers['X-Frame-Options']);
        self::assertSame('nosniff', $response->headers['X-Content-Type-Options']);
        self::assertSame('strict-origin-when-cross-origin', $response->headers['Referrer-Policy']);
        self::assertArrayHasKey('Strict-Transport-Security', $response->headers);
    }

    public function testNoHstsOverPlainHttp(): void
    {
        $response = $this->handle('GET', '/gibtsnicht', secure: false, host: 'localhost', base: '');

        self::assertArrayNotHasKey('Strict-Transport-Security', $response->headers);
    }

    public function testLinksUseConfiguredDomainNotHostHeader(): void
    {
        $response = $this->handle('GET', '/gibtsnicht', host: 'evil.example');

        self::assertStringContainsString(self::BASE . '/', $response->body);
        self::assertStringNotContainsString('evil.example', $response->body);
    }

    public function testMissingBaseUrlOnPublicHostIsAnError(): void
    {
        $response = $this->handle('GET', '/gibtsnicht', host: 'evil.example', base: '');

        self::assertSame(500, $response->status);
        self::assertStringNotContainsString('evil.example', $response->body);
    }

    public function testValidationErrorListsFieldTypesWithoutContent(): void
    {
        $response = $this->handle('POST', '/api/resolve', body: ['input' => ['geheim']], headers: self::JSON);
        $payload  = json_decode($response->body, true);

        self::assertSame(422, $response->status);
        self::assertSame(['expected' => 'string', 'received' => 'array'], $payload['fields']['input']);
        self::assertStringNotContainsString('geheim', $response->body);
    }

    public function testApiPostWithoutJsonIsRejected(): void
    {
        $response = $this->handle('POST', '/api/feeds', body: ['title' => 'x'], headers: ['content-type' => 'application/x-www-form-urlencoded']);

        self::assertSame(415, $response->status);
    }

    public function testCrossSiteApiPostIsRejected(): void
    {
        $response = $this->handle('POST', '/api/resolve', body: ['input' => '@x'], headers: self::JSON + ['origin' => 'https://evil.example']);

        self::assertSame(403, $response->status);
    }

    public function testCrossSiteAdminPostIsRejected(): void
    {
        $response = $this->handle('POST', '/admin', body: ['token' => 'x'], headers: ['origin' => 'https://evil.example']);

        self::assertSame(403, $response->status);
    }

    public function testSameOriginPostPasses(): void
    {
        $response = $this->handle('POST', '/api/resolve', body: ['input' => ''], headers: self::JSON + ['origin' => 'https://watchrss.de']);

        self::assertSame(422, $response->status, 'Validierung statt CSRF-Abweisung');
    }

    public function testNullOriginIsRejected(): void
    {
        $response = $this->handle('POST', '/api/resolve', body: ['input' => ''], headers: self::JSON + ['origin' => 'null']);

        self::assertSame(403, $response->status);
    }

    public function testEveryRouteDeclaresAccessAndEveryPostCsrf(): void
    {
        $routes = (new App(dirname(__DIR__, 2)))->router()->routes();

        self::assertNotEmpty($routes);
        foreach ($routes as $route) {
            if (in_array('POST', $route['methods'], true)) {
                self::assertNotNull($route['csrf'], 'POST ohne CSRF-Schutz: ' . $route['pattern']);
            }
        }
    }

    public function testAssetUrlsCarryAppVersion(): void
    {
        $response = $this->handle('GET', '/gibtsnicht');

        self::assertMatchesRegularExpression('#/assets/css/app\.css\?v=' . preg_quote(App::VERSION, '#') . '-\d+#', $response->body);
    }

    public function testCronWithArrayKeyIsNotFound(): void
    {
        $response = $this->handle('GET', '/cron', query: ['key' => ['a']]);

        self::assertSame(404, $response->status);
    }

    public function testCronWithWrongTokenIsNotFound(): void
    {
        self::assertSame(404, $this->handle('GET', '/cron/falscher-token-123')->status);
    }

    /**
     * @param array<string,mixed>  $query
     * @param array<string,mixed>  $body
     * @param array<string,string> $headers
     */
    private function handle(
        string $method,
        string $path,
        bool $secure = true,
        string $host = 'watchrss.de',
        string $base = self::BASE,
        array $query = [],
        array $body = [],
        array $headers = [],
    ): Response {
        $request = new Request(
            method: $method,
            path: $path,
            basePath: '',
            query: $query,
            body: $body,
            headers: $headers,
            clientIp: '203.0.113.5',
            secure: $secure,
            host: $host,
            configuredBaseUrl: $base,
        );

        return (new App(dirname(__DIR__, 2)))->handle($request);
    }
}
