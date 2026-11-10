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
 */
final class HttpClient
{
    public function __construct(
        private readonly string $userAgent,
        private readonly int $timeout = 15,
        private readonly int $connectTimeout = 8,
    ) {
    }

    public static function fromConfig(): self
    {
        return new self((string) HTTP_USER_AGENT, (int) HTTP_TIMEOUT, (int) HTTP_CONNECT_TIMEOUT);
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

        $responseHeaders = [];
        $ch              = curl_init();

        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 5,
            CURLOPT_TIMEOUT        => $this->timeout,
            CURLOPT_CONNECTTIMEOUT => $this->connectTimeout,
            CURLOPT_USERAGENT      => $this->userAgent,
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

        $body   = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $final  = (string) curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
        $error  = curl_errno($ch) !== 0 ? curl_error($ch) : null;
        curl_close($ch);

        return new HttpResponse(
            $status,
            is_string($body) ? $body : '',
            $responseHeaders,
            $error,
            $final,
        );
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
