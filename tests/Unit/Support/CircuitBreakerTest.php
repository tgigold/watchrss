<?php

declare(strict_types=1);

namespace WatchRss\Tests\Unit\Support;

use PHPUnit\Framework\TestCase;
use WatchRss\Support\CircuitBreaker;
use WatchRss\Support\FileCache;
use WatchRss\Support\HttpClient;

final class CircuitBreakerTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/watchrss-breaker-' . bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->dir . '/*') ?: []);
        @rmdir($this->dir);
    }

    public function testSingleFailureDoesNotOpen(): void
    {
        $breaker = $this->breaker();
        $breaker->recordFailure('youtube.com');

        self::assertNull($breaker->retryAfter('youtube.com'));
    }

    public function testOpensAfterThresholdConsecutiveFailures(): void
    {
        $breaker = $this->breaker();
        for ($i = 0; $i < 3; $i++) {
            $breaker->recordFailure('youtube.com');
        }

        self::assertGreaterThan(0, $breaker->retryAfter('youtube.com'));
        self::assertNull($breaker->retryAfter('example.org'), 'andere Dienste bleiben frei');
    }

    public function testSuccessResetsTheCount(): void
    {
        $breaker = $this->breaker();
        $breaker->recordFailure('youtube.com');
        $breaker->recordFailure('youtube.com');
        $breaker->recordSuccess('youtube.com');
        $breaker->recordFailure('youtube.com');

        self::assertNull($breaker->retryAfter('youtube.com'));
    }

    public function testOpenBreakerSkipsRequestWithoutNetwork(): void
    {
        $breaker = $this->breaker();
        for ($i = 0; $i < 3; $i++) {
            $breaker->recordFailure('example.invalid');
        }

        $response = (new HttpClient('test', 1, 1, $breaker))->get('https://example.invalid/');

        self::assertTrue($response->skipped);
        self::assertSame(0, $response->status);
        self::assertStringContainsString('übersprungen', (string) $response->error);
    }

    private function breaker(): CircuitBreaker
    {
        return new CircuitBreaker(new FileCache($this->dir), threshold: 3, cooldownSeconds: 300);
    }
}
