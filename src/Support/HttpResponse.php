<?php

declare(strict_types=1);

namespace WatchRss\Support;

/**
 * Ergebnis eines HTTP-Abrufs (unveränderlich).
 */
final class HttpResponse
{
    /**
     * @param array<string,string> $headers Header-Namen in Kleinbuchstaben.
     */
    public function __construct(
        public readonly int $status,
        public readonly string $body,
        public readonly array $headers = [],
        public readonly ?string $error = null,
        public readonly string $effectiveUrl = '',
    ) {
    }

    public function isOk(): bool
    {
        return $this->status >= 200 && $this->status < 300;
    }

    public function isNotModified(): bool
    {
        return $this->status === 304;
    }

    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)] ?? null;
    }
}
