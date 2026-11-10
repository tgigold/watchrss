<?php

declare(strict_types=1);

namespace WatchRss\Model;

/**
 * Ein YouTube-Kanal, wie er in der Tabelle `channels` liegt.
 */
final class Channel
{
    public function __construct(
        public readonly ?int $id,
        public readonly string $channelId,
        public readonly string $title = '',
        public readonly ?string $handle = null,
        public readonly ?string $sourceUrl = null,
        public readonly ?string $avatarUrl = null,
        public readonly ?string $httpEtag = null,
        public readonly ?string $httpLastModified = null,
        public readonly ?string $lastFetchedAt = null,
        public readonly ?string $lastSuccessAt = null,
        public readonly string $lastStatus = 'pending',
        public readonly ?string $lastError = null,
        public readonly int $failureCount = 0,
    ) {
    }

    /**
     * @param array<string,mixed> $row
     */
    public static function fromRow(array $row): self
    {
        return new self(
            id: isset($row['id']) ? (int) $row['id'] : null,
            channelId: (string) $row['channel_id'],
            title: (string) ($row['title'] ?? ''),
            handle: $row['handle'] ?? null,
            sourceUrl: $row['source_url'] ?? null,
            avatarUrl: $row['avatar_url'] ?? null,
            httpEtag: $row['http_etag'] ?? null,
            httpLastModified: $row['http_last_modified'] ?? null,
            lastFetchedAt: $row['last_fetched_at'] ?? null,
            lastSuccessAt: $row['last_success_at'] ?? null,
            lastStatus: (string) ($row['last_status'] ?? 'pending'),
            lastError: $row['last_error'] ?? null,
            failureCount: (int) ($row['failure_count'] ?? 0),
        );
    }

    /** URL des offiziellen YouTube-Kanalfeeds. */
    public function feedUrl(): string
    {
        return 'https://www.youtube.com/feeds/videos.xml?channel_id=' . $this->channelId;
    }

    /** Kanonische Kanal-URL (funktioniert auch ohne Handle). */
    public function channelUrl(): string
    {
        return 'https://www.youtube.com/channel/' . $this->channelId;
    }

    /** Anzeigename für das Frontend. */
    public function displayName(): string
    {
        if ($this->title !== '') {
            return $this->title;
        }

        return $this->handle !== null ? '@' . $this->handle : $this->channelId;
    }

    /** @return array<string,mixed> Serialisierung für die JSON-API. */
    public function toArray(): array
    {
        return [
            'id'          => $this->id,
            'channel_id'  => $this->channelId,
            'title'       => $this->displayName(),
            'handle'      => $this->handle,
            'avatar_url'  => $this->avatarUrl,
            'channel_url' => $this->channelUrl(),
            'feed_url'    => $this->feedUrl(),
            'last_status' => $this->lastStatus,
        ];
    }
}
