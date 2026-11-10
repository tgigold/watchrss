<?php

declare(strict_types=1);

namespace WatchRss\Service;

/**
 * Ergebnis eines Kanal-Abrufs (für Logging im Cron).
 */
final class FetchResult
{
    public function __construct(
        public readonly string $channelId,
        public readonly string $status,     // ok | not_modified | error
        public readonly int $seen = 0,
        public readonly int $new = 0,
        public readonly ?string $message = null,
    ) {
    }

    public function isError(): bool
    {
        return $this->status === 'error';
    }

    public function summary(): string
    {
        return match ($this->status) {
            'ok'           => sprintf('%s: %d Einträge, %d neu', $this->channelId, $this->seen, $this->new),
            'not_modified' => sprintf('%s: unverändert (304)', $this->channelId),
            default        => sprintf('%s: FEHLER - %s', $this->channelId, $this->message ?? 'unbekannt'),
        };
    }
}
