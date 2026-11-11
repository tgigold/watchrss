<?php

declare(strict_types=1);

namespace WatchRss\Http;

use RuntimeException;
use WatchRss\Support\IpRange;

/**
 * Sehr schlanke Request-Abstraktion.
 *
 * Ziel ist nicht PSR-7, sondern: Controller sollen nicht direkt in
 * $_SERVER/$_GET/$_POST greifen müssen und dadurch testbar bleiben.
 */
final class Request
{
    /**
     * @param array<string,mixed>  $query
     * @param array<string,mixed>  $body
     * @param array<string,string> $headers Namen in Kleinbuchstaben
     */
    public function __construct(
        public readonly string $method,
        public readonly string $path,
        public readonly string $basePath,
        public readonly array $query = [],
        public readonly array $body = [],
        public readonly array $headers = [],
        public readonly string $clientIp = '0.0.0.0',
        public readonly bool $secure = false,
        public readonly string $host = 'localhost',
    ) {
    }

    public static function fromGlobals(): self
    {
        $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');

        // Basispfad = Verzeichnis des Front-Controllers. Damit läuft die
        // Anwendung auch in einem Unterverzeichnis ohne Konfiguration.
        $scriptDir = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/index.php')), '/');
        $basePath  = $scriptDir === '.' ? '' : $scriptDir;

        $uri  = (string) ($_SERVER['REQUEST_URI'] ?? '/');
        $path = (string) (parse_url($uri, PHP_URL_PATH) ?? '/');
        if ($basePath !== '' && str_starts_with($path, $basePath)) {
            $path = substr($path, strlen($basePath));
        }
        $path = '/' . ltrim(rawurldecode($path), '/');

        $headers = [];
        foreach ($_SERVER as $key => $value) {
            if (str_starts_with((string) $key, 'HTTP_')) {
                $name           = strtolower(str_replace('_', '-', substr((string) $key, 5)));
                $headers[$name] = (string) $value;
            }
        }

        // Content-Type und Content-Length stehen laut CGI-Spezifikation OHNE
        // HTTP_-Präfix in $_SERVER. Apache/php-fpm halten sich daran, PHPs
        // eingebauter Entwicklungsserver setzt zusätzlich HTTP_CONTENT_TYPE.
        // Ohne diesen Block sieht parseBody() lokal einen JSON-Body, auf dem
        // echten Server aber nicht - genau das führte zu leeren Requests.
        foreach (['CONTENT_TYPE' => 'content-type', 'CONTENT_LENGTH' => 'content-length'] as $key => $name) {
            if (isset($_SERVER[$key]) && (string) $_SERVER[$key] !== '') {
                $headers[$name] = (string) $_SERVER[$key];
            }
        }

        $trustedProxies = IpRange::parseList(defined('TRUSTED_PROXIES') ? (string) TRUSTED_PROXIES : '');
        $remoteAddr     = (string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
        $viaProxy       = $trustedProxies !== [] && IpRange::matchesAny($remoteAddr, $trustedProxies);

        return new self(
            method: $method,
            path: $path,
            basePath: $basePath,
            query: $_GET,
            body: self::parseBody($headers),
            headers: $headers,
            clientIp: self::detectClientIp($remoteAddr, $viaProxy, $trustedProxies),
            secure: self::detectHttps($viaProxy),
            host: (string) ($_SERVER['HTTP_HOST'] ?? 'localhost'),
        );
    }

    /**
     * Wert aus dem Request-Body (JSON oder Formular).
     */
    public function input(string $key, mixed $default = null): mixed
    {
        return $this->body[$key] ?? $default;
    }

    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)] ?? null;
    }

    public function wantsJson(): bool
    {
        $accept = $this->header('accept') ?? '';

        return str_contains($accept, 'application/json')
            || str_contains($this->header('content-type') ?? '', 'application/json');
    }

    /**
     * Absolute Basis-URL der Installation, z. B. "https://watchrss.de".
     *
     * Kommt immer aus APP_BASE_URL, nie aus dem Host-Header - sonst könnte
     * ein Client fremde Domains in Feed- und Editor-Links schmuggeln. Nur
     * bei lokaler Entwicklung (localhost, *.test, ...) darf sie fehlen.
     */
    public function baseUrl(): string
    {
        if (defined('APP_BASE_URL') && APP_BASE_URL !== '') {
            return rtrim((string) APP_BASE_URL, '/');
        }

        if ($this->isLocalHost()) {
            return ($this->secure ? 'https://' : 'http://') . $this->host . $this->basePath;
        }

        throw new RuntimeException('APP_BASE_URL ist nicht gesetzt (config.inc.php).');
    }

    /** Lokale Entwicklungsumgebung (localhost, *.test, *.local, ...)? */
    public function isLocalHost(): bool
    {
        $host = strtolower((string) parse_url('http://' . $this->host, PHP_URL_HOST));
        $host = trim($host, '[]');

        return $host === 'localhost'
            || $host === '127.0.0.1'
            || $host === '::1'
            || str_ends_with($host, '.local')
            || str_ends_with($host, '.test')
            || str_ends_with($host, '.localhost');
    }

    /**
     * @param  array<string,string> $headers
     * @return array<string,mixed>
     */
    private static function parseBody(array $headers): array
    {
        $contentType = strtolower($headers['content-type'] ?? '');
        $raw         = null;

        if (str_contains($contentType, 'application/json')) {
            $raw = file_get_contents('php://input') ?: '';
        } elseif ($_POST === [] && !str_contains($contentType, 'multipart/')) {
            // Sicherheitsnetz für Server, die den Content-Type nicht
            // durchreichen: sieht der Rumpf nach JSON aus, wird er auch als
            // JSON gelesen.
            $candidate = ltrim(file_get_contents('php://input') ?: '');
            if ($candidate !== '' && ($candidate[0] === '{' || $candidate[0] === '[')) {
                $raw = $candidate;
            }
        }

        if ($raw !== null) {
            $decoded = json_decode($raw, true);

            return is_array($decoded) ? $decoded : [];
        }

        return $_POST;
    }

    /**
     * Client-IP für Abruf-Bremse und Statistik (beides nur gehasht).
     *
     * X-Forwarded-For zählt nur, wenn der Request von einem Proxy aus
     * TRUSTED_PROXIES kommt - sonst könnte jeder Client sich per Header eine
     * beliebige IP geben und damit die Bremsen umgehen. Gelesen wird von
     * rechts: Der erste Eintrag, der kein eigener Proxy ist, ist der Client.
     *
     * @param list<string> $trustedProxies
     */
    private static function detectClientIp(string $remoteAddr, bool $viaProxy, array $trustedProxies): string
    {
        if (!$viaProxy) {
            return $remoteAddr;
        }

        $forwarded = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? '';
        if (!is_string($forwarded) || $forwarded === '') {
            return $remoteAddr;
        }

        foreach (array_reverse(array_map('trim', explode(',', $forwarded))) as $candidate) {
            if (filter_var($candidate, FILTER_VALIDATE_IP) === false) {
                break;
            }
            if (!IpRange::matchesAny($candidate, $trustedProxies)) {
                return $candidate;
            }
        }

        return $remoteAddr;
    }

    /** X-Forwarded-Proto zählt nur von einem vertrauenswürdigen Proxy. */
    private static function detectHttps(bool $viaProxy): bool
    {
        if (($_SERVER['HTTPS'] ?? '') !== '' && ($_SERVER['HTTPS'] ?? 'off') !== 'off') {
            return true;
        }
        if ($viaProxy && strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https') {
            return true;
        }

        return ((int) ($_SERVER['SERVER_PORT'] ?? 80)) === 443;
    }
}
