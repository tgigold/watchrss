<?php

declare(strict_types=1);

namespace WatchRss\Tests\Unit\Support;

use PHPUnit\Framework\TestCase;
use WatchRss\Support\HttpClient;

/**
 * Ohne Netzwerk: geprüft wird nur die Konfiguration des Clients.
 */
final class HttpClientTest extends TestCase
{
    public function testOnlyHttpsIsAllowedForRequestsAndRedirects(): void
    {
        $options = HttpClient::httpsOnlyOptions();

        if (defined('CURLOPT_PROTOCOLS_STR') && defined('CURLOPT_REDIR_PROTOCOLS_STR')) {
            self::assertSame('https', $options[CURLOPT_PROTOCOLS_STR]);
            self::assertSame('https', $options[CURLOPT_REDIR_PROTOCOLS_STR]);
        } else {
            self::assertSame(CURLPROTO_HTTPS, $options[CURLOPT_PROTOCOLS]);
            self::assertSame(CURLPROTO_HTTPS, $options[CURLOPT_REDIR_PROTOCOLS]);
        }
    }

    public function testRedirectsAreLimited(): void
    {
        self::assertLessThanOrEqual(3, HttpClient::MAX_REDIRECTS);
    }

    public function testEmptyUrlFailsWithoutRequest(): void
    {
        $response = (new HttpClient('test'))->get('');

        self::assertSame(0, $response->status);
        self::assertNotNull($response->error);
    }
}
