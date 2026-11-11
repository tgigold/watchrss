<?php

declare(strict_types=1);

namespace WatchRss\Model;

/**
 * Ein vom Nutzer zusammengestellter Kombi-Feed.
 */
final class Feed
{
    /**
     * @param list<FeedChannel> $channels
     */
    public function __construct(
        public readonly ?int $id,
        /**
         * SHA-256 des geheimen Bearbeiten-Tokens. Der Token selbst wird nie
         * gespeichert - er steht nur im Link des Nutzers.
         */
        public readonly string $editTokenHash,
        /** Öffentlich - steht in der RSS-Adresse. */
        public readonly string $feedToken,
        public readonly string $title = 'watchrss',
        public readonly ?int $userId = null,
        public readonly int $ttlMinutes = 120,
        public readonly int $maxItems = 100,
        public readonly bool $prefixTitles = true,
        public readonly ?string $lastBuiltAt = null,
        public readonly ?string $lastServedAt = null,
        public readonly int $serveCount = 0,
        public readonly ?string $createdAt = null,
        public readonly ?string $updatedAt = null,
        public array $channels = [],
    ) {
    }

    /**
     * @param array<string,mixed> $row
     */
    public static function fromRow(array $row): self
    {
        return new self(
            id: isset($row['id']) ? (int) $row['id'] : null,
            editTokenHash: (string) $row['edit_token_hash'],
            feedToken: (string) $row['feed_token'],
            title: (string) ($row['title'] ?? 'watchrss'),
            userId: isset($row['user_id']) ? (int) $row['user_id'] : null,
            ttlMinutes: (int) ($row['ttl_minutes'] ?? 120),
            maxItems: (int) ($row['max_items'] ?? 100),
            prefixTitles: (bool) ($row['prefix_titles'] ?? true),
            lastBuiltAt: $row['last_built_at'] ?? null,
            lastServedAt: $row['last_served_at'] ?? null,
            serveCount: (int) ($row['serve_count'] ?? 0),
            createdAt: $row['created_at'] ?? null,
            updatedAt: $row['updated_at'] ?? null,
        );
    }

    public function ttlSeconds(): int
    {
        return max(60, $this->ttlMinutes * 60);
    }

    /** @return list<int> IDs der enthaltenen Kanäle (channels.id). */
    public function channelRefs(): array
    {
        $refs = [];
        foreach ($this->channels as $feedChannel) {
            if ($feedChannel->channel->id !== null) {
                $refs[] = $feedChannel->channel->id;
            }
        }

        return $refs;
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'feed_token'    => $this->feedToken,
            'title'         => $this->title,
            'ttl_minutes'   => $this->ttlMinutes,
            'max_items'     => $this->maxItems,
            'prefix_titles' => $this->prefixTitles,
            'channels'      => array_map(static fn (FeedChannel $c): array => $c->toArray(), $this->channels),
        ];
    }
}
