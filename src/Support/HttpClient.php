<?php

declare(strict_types=1);

namespace WatchRss\Support;

/**
 * Minimaler cURL-Client für die YouTube-Abrufe.
 *
 * Gegenüber file_get_contents() bringt das:
 *  - echte Timeouts (Connect + Gesamt)
 *  - transparente gzip-Dekomprimierung (YouTube-Seiten sind ~1,5 MB)
 *  - Zugriff auf Response-Header für Conditional GET (ETag/Last-Modified)
 *  - kontrollierte Redirects statt allow_url_fopen-Überraschungen
 *
 * Es geht ausschließlich über HTTPS - auch Weiterleitungen werden nur auf
 * https:// verfolgt, und höchstens MAX_REDIRECTS-mal.
 */
final class HttpClient
{
    public const MAX_REDIRECTS = 3;

    public function __construct(
        private readonly string $userAgent,
        private readonly int $timeout = 15,
        private readonly int $connectTimeout = 8,
        private readonly ?CircuitBreaker $breaker = null,
    ) {
    }

    public static function fromConfig(?CircuitBreaker $breaker = null): self
    {
        return new self((string) HTTP_USER_AGENT, (int) HTTP_TIMEOUT, (int) HTTP_CONNECT_TIMEOUT, $breaker);
    }

    /**
     * @param array<string,string> $extraHeaders
     */
    public function get(string $url, array $extraHeaders = []): HttpResponse
    {
        $headers = [
            'Accept-Language: en-US,en;q=0.9,de;q=0.8',
            'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
            // Umgeht die Consent-Weiterleitung, die YouTube für EU-IPs
            // ausliefert - ohne dieses Cookie bekommt man consent.youtube.com
            // statt der Kanalseite.
            'Cookie: SOCS=CAI; CONSENT=YES+cb',
        ];
        foreach ($extraHeaders as $name => $value) {
            $headers[] = $name . ': ' . $value;
        }

        if ($url === '') {
            return new HttpResponse(0, '', [], 'Leere URL', '');
        }

        // Ausgefallener Dienst? Dann gar nicht erst anfragen.
        $service    = strtolower((string) parse_url($url, PHP_URL_HOST));
        $retryAfter = $this->breaker?->retryAfter($service);
        if ($retryAfter !== null) {
            return new HttpResponse(0, '', [], sprintf('%s vorübergehend übersprungen (noch %d s)', $service, $retryAfter), $url, skipped: true);
        }

        $responseHeaders = [];
        $ch              = curl_init();

        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => self::MAX_REDIRECTS,
            CURLOPT_TIMEOUT        => $this->timeout,
            CURLOPT_CONNECTTIMEOUT => $this->connectTimeout,
            CURLOPT_USERAGENT      => $this->userAgent !== '' ? $this->userAgent : 'watchrss',
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_ENCODING       => '',            // alle unterstützten Encodings
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_HEADERFUNCTION => static function ($_ch, string $line) use (&$responseHeaders): int {
                $length = strlen($line);
                $parts  = explode(':', $line, 2);
                if (count($parts) === 2) {
                    $responseHeaders[strtolower(trim($parts[0]))] = trim($parts[1]);
                }

                return $length;
            },
        ]);

        curl_setopt_array($ch, self::httpsOnlyOptions());

        $body   = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $final  = (string) curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
        $error  = curl_errno($ch) !== 0 ? curl_error($ch) : null;
        curl_close($ch);

        // Komplettausfall: kein HTTP-Ergebnis, Serverfehler oder Drosselung.
        // 404 & Co. sind normale Antworten und zählen als Erfolg.
        if ($error !== null || $status >= 500 || $status === 429) {
            $this->breaker?->recordFailure($service);
        } else {
            $this->breaker?->recordSuccess($service);
        }

        return new HttpResponse(
            $status,
            is_string($body) ? $body : '',
            $responseHeaders,
            $error,
            $final,
        );
    }

    /**
     * cURL-Optionen, die Anfrage und Weiterleitungen auf HTTPS beschränken.
     * PHP 8.3+ kennt die String-Variante, ältere nur die Bitmaske.
     *
     * @return array<int,mixed>
     */
    public static function httpsOnlyOptions(): array
    {
        if (defined('CURLOPT_PROTOCOLS_STR') && defined('CURLOPT_REDIR_PROTOCOLS_STR')) {
            return [CURLOPT_PROTOCOLS_STR => 'https', CURLOPT_REDIR_PROTOCOLS_STR => 'https'];
        }

        return [CURLOPT_PROTOCOLS => CURLPROTO_HTTPS, CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS];
    }

    /**
     * GET mit Conditional-Headern. Liefert bei unverändertem Inhalt 304
     * und damit einen leeren Body - spart Traffic auf beiden Seiten.
     */
    public function conditionalGet(string $url, ?string $etag, ?string $lastModified): HttpResponse
    {
        $headers = [];
        if ($etag !== null && $etag !== '') {
            $headers['If-None-Match'] = $etag;
        }
        if ($lastModified !== null && $lastModified !== '') {
            $headers['If-Modified-Since'] = $lastModified;
        }

        return $this->get($url, $headers);
    }
}
