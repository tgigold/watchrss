<?php

declare(strict_types=1);

namespace WatchRss\Model;

/**
 * Ein Kanal *innerhalb* eines Nutzer-Feeds - inklusive der Filter-Schalter.
 */
final class FeedChannel
{
    public function __construct(
        public readonly Channel $channel,
        public readonly bool $includeLong = true,
        public readonly bool $includeShorts = true,
        public readonly int $position = 0,
    ) {
    }

    /**
     * @param array<string,mixed> $row Zeile aus feed_channels JOIN channels
     */
    public static function fromRow(array $row): self
    {
        return new self(
            channel: Channel::fromRow($row),
            includeLong: (bool) $row['include_long'],
            includeShorts: (bool) $row['include_shorts'],
            position: (int) ($row['position'] ?? 0),
        );
    }

    /** Kanal ohne aktiven Filter liefert nie Einträge - im UI markieren. */
    public function isMuted(): bool
    {
        return !$this->includeLong && !$this->includeShorts;
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return $this->channel->toArray() + [
            'include_long'   => $this->includeLong,
            'include_shorts' => $this->includeShorts,
            'position'       => $this->position,
        ];
    }
}
