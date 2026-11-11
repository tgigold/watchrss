<?php

declare(strict_types=1);

namespace WatchRss\Tests\Unit\Http;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use WatchRss\Http\Request;

/**
 * TRUSTED_PROXIES ist in tests/constants.php auf 10.0.0.0/8 gesetzt.
 */
final class RequestTest extends TestCase
{
    /** @var array<string,mixed> */
    private array $server;

    protected function setUp(): void
    {
        $this->server = $_SERVER;
    }

    protected function tearDown(): void
    {
        $_SERVER = $this->server;
        $_GET    = [];
        $_POST   = [];
    }

    public function testForwardedHeadersFromUntrustedClientAreIgnored(): void
    {
        $request = $this->fromGlobals([
            'REMOTE_ADDR'            => '203.0.113.5',
            'HTTP_X_FORWARDED_FOR'   => '1.1.1.1',
            'HTTP_X_FORWARDED_PROTO' => 'https',
        ]);

        self::assertSame('203.0.113.5', $request->clientIp);
        self::assertFalse($request->secure);
    }

    public function testForwardedForIsReadFromTheRightViaTrustedProxy(): void
    {
        $request = $this->fromGlobals([
            'REMOTE_ADDR'            => '10.0.0.2',
            'HTTP_X_FORWARDED_FOR'   => '6.6.6.6, 7.7.7.7, 10.0.0.9',
            'HTTP_X_FORWARDED_PROTO' => 'https',
        ]);

        // 6.6.6.6 könnte der Client selbst vorangestellt haben - maßgeblich
        // ist der erste Eintrag von rechts, der kein eigener Proxy ist.
        self::assertSame('7.7.7.7', $request->clientIp);
        self::assertTrue($request->secure);
    }

    public function testInvalidForwardedForFallsBackToRemoteAddr(): void
    {
        $request = $this->fromGlobals(['REMOTE_ADDR' => '10.0.0.2', 'HTTP_X_FORWARDED_FOR' => 'garbage']);

        self::assertSame('10.0.0.2', $request->clientIp);
    }

    public function testBaseUrlComesFromConfigNeverFromHostHeader(): void
    {
        $request = $this->make(host: 'evil.example', configured: 'https://watchrss.de');

        self::assertSame('https://watchrss.de', $request->baseUrl());
    }

    public function testBaseUrlWithoutConfigOnlyForLocalHosts(): void
    {
        self::assertSame('http://localhost:8080', $this->make(host: 'localhost:8080')->baseUrl());
        self::assertSame('http://watchrss.test', $this->make(host: 'watchrss.test')->baseUrl());

        $this->expectException(RuntimeException::class);
        $this->make(host: 'evil.example')->baseUrl();
    }

    public function testFromGlobalsTakesConfiguredBaseUrl(): void
    {
        self::assertSame('https://watchrss.test', $this->fromGlobals([])->configuredBaseUrl);
    }

    /** @param array<string,string> $server */
    private function fromGlobals(array $server): Request
    {
        $_SERVER = $server + ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/', 'SCRIPT_NAME' => '/index.php', 'HTTP_HOST' => 'evil.example'];
        $_GET    = [];
        $_POST   = [];

        return Request::fromGlobals();
    }

    private function make(string $host, string $configured = ''): Request
    {
        return new Request('GET', '/', '', host: $host, configuredBaseUrl: $configured);
    }
}
