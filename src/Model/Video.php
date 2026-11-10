<?php

declare(strict_types=1);

namespace WatchRss\Model;

/**
 * Ein Video aus dem Kanalfeed.
 */
final class Video
{
    public function __construct(
        public readonly ?int $id,
        public readonly int $channelRef,
        public readonly string $videoId,
        public readonly string $title,
        public readonly ?string $description,
        public readonly string $link,
        public readonly ?string $thumbnailUrl,
        public readonly bool $isShort,
        public readonly string $publishedAt,
        public readonly ?string $updatedAt = null,
        /** Wird beim JOIN aus `channels` mitgeliefert. */
        public readonly string $channelTitle = '',
        public readonly string $channelId = '',
    ) {
    }

    /**
     * @param array<string,mixed> $row
     */
    public static function fromRow(array $row): self
    {
        return new self(
            id: isset($row['id']) ? (int) $row['id'] : null,
            channelRef: (int) $row['channel_ref'],
            videoId: (string) $row['video_id'],
            title: (string) $row['title'],
            description: $row['description'] ?? null,
            link: (string) $row['link'],
            thumbnailUrl: $row['thumbnail_url'] ?? null,
            isShort: (bool) $row['is_short'],
            publishedAt: (string) $row['published_at'],
            updatedAt: $row['updated_at'] ?? null,
            channelTitle: (string) ($row['channel_title'] ?? ''),
            channelId: (string) ($row['channel_youtube_id'] ?? ''),
        );
    }

    /** "Short" oder "Longform" - für Badge und Feed-Kategorie. */
    public function formatLabel(): string
    {
        return $this->isShort ? 'Short' : 'Longform';
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'video_id'      => $this->videoId,
            'title'         => $this->title,
            'link'          => $this->link,
            'thumbnail_url' => $this->thumbnailUrl,
            'is_short'      => $this->isShort,
            'format'        => $this->formatLabel(),
            'published_at'  => $this->publishedAt,
            'channel_title' => $this->channelTitle,
        ];
    }
}
