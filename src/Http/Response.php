<?php

declare(strict_types=1);

namespace WatchRss\Http;

/**
 * Antwort-Objekt. Wird vom Front-Controller ausgegeben.
 */
final class Response
{
    /**
     * @param array<string,string> $headers
     */
    public function __construct(
        public readonly string $body = '',
        public readonly int $status = 200,
        public readonly array $headers = [],
    ) {
    }

    public static function html(string $body, int $status = 200): self
    {
        return new self($body, $status, ['Content-Type' => 'text/html; charset=utf-8']);
    }

    /**
     * @param array<string,mixed>  $data
     * @param array<string,string> $headers
     */
    public static function json(array $data, int $status = 200, array $headers = []): self
    {
        return new self(
            (string) json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            $status,
            $headers + ['Content-Type' => 'application/json; charset=utf-8'],
        );
    }

    /**
     * @param array<string,string> $headers
     */
    public static function xml(string $body, int $status = 200, array $headers = []): self
    {
        return new self($body, $status, $headers + ['Content-Type' => 'application/rss+xml; charset=utf-8']);
    }

    public static function redirect(string $location, int $status = 302): self
    {
        return new self('', $status, ['Location' => $location]);
    }

    /**
     * 304 darf laut HTTP keinen Body haben, aber sehr wohl die
     * Cache-Header der urspruenglichen Antwort.
     *
     * @param array<string,string> $headers
     */
    public static function notModified(array $headers = []): self
    {
        return new self('', 304, $headers);
    }

    /**
     * Ergänzt Header, die die Antwort noch nicht selbst setzt.
     *
     * @param array<string,string> $headers
     */
    public function withDefaultHeaders(array $headers): self
    {
        return new self($this->body, $this->status, $this->headers + $headers);
    }

    public function send(): void
    {
        if (!headers_sent()) {
            http_response_code($this->status);
            foreach ($this->headers as $name => $value) {
                header($name . ': ' . $value, true);
            }
        }

        if ($this->status !== 304 && $this->body !== '') {
            echo $this->body;
        }
    }
}
