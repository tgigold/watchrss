<?php

declare(strict_types=1);

namespace WatchRss\Repository;

use PDO;
use WatchRss\Model\Feed;
use WatchRss\Model\Video;
use WatchRss\Support\Database;

/**
 * Datenzugriff für die Tabelle `videos`.
 */
final class VideoRepository
{
    private const SELECT = 'v.id, v.channel_ref, v.video_id, v.title, v.description, v.link,
        v.thumbnail_url, v.is_short, v.published_at, v.updated_at,
        c.title AS channel_title, c.channel_id AS channel_youtube_id';

    public function __construct(private readonly Database $db)
    {
    }

    /**
     * Schreibt die Einträge eines Kanalfeeds. Bereits bekannte Videos werden
     * aktualisiert (Titel/Beschreibung ändern sich nachträglich häufig),
     * `first_seen_at` bleibt dabei erhalten.
     *
     * @param  list<array<string,mixed>> $videos
     * @return array{seen:int,new:int}
     */
    public function upsertMany(int $channelRef, array $videos): array
    {
        if ($videos === []) {
            return ['seen' => 0, 'new' => 0];
        }

        $pdo  = $this->db->pdo();
        $stmt = $pdo->prepare(
            'INSERT INTO videos
                (channel_ref, video_id, title, description, link, thumbnail_url, is_short, published_at, updated_at)
             VALUES
                (:channel_ref, :video_id, :title, :description, :link, :thumbnail_url, :is_short, :published_at, :updated_at)
             ON DUPLICATE KEY UPDATE
                title         = VALUES(title),
                description   = VALUES(description),
                link          = VALUES(link),
                thumbnail_url = VALUES(thumbnail_url),
                is_short      = VALUES(is_short),
                updated_at    = VALUES(updated_at)'
        );

        $new = 0;
        foreach ($videos as $video) {
            $stmt->execute([
                'channel_ref'   => $channelRef,
                'video_id'      => $video['video_id'],
                'title'         => mb_substr((string) $video['title'], 0, 500),
                'description'   => $video['description'],
                'link'          => $video['link'],
                'thumbnail_url' => $video['thumbnail_url'],
                'is_short'      => (int) $video['is_short'],
                'published_at'  => $video['published_at'],
                'updated_at'    => $video['updated_at'],
            ]);
            // rowCount(): 1 = INSERT, 2 = UPDATE, 0 = unverändert.
            if ($stmt->rowCount() === 1) {
                $new++;
            }
        }

        return ['seen' => count($videos), 'new' => $new];
    }

    /**
     * Die Einträge eines Kombi-Feeds - bereits gefiltert nach den
     * Shorts/Longform-Schaltern der jeweiligen Kanal-Zuordnung.
     *
     * @return list<Video>
     */
    public function forFeed(Feed $feed, ?int $limit = null): array
    {
        $conditions = [];
        $params     = [];
        $i          = 0;

        foreach ($feed->channels as $feedChannel) {
            $ref = $feedChannel->channel->id;
            if ($ref === null || $feedChannel->isMuted()) {
                continue; // Kanal ohne aktiven Filter liefert nichts.
            }

            $i++;
            $params['ref' . $i] = $ref;

            if ($feedChannel->includeLong && $feedChannel->includeShorts) {
                $conditions[] = "v.channel_ref = :ref{$i}";
            } else {
                $conditions[]          = "(v.channel_ref = :ref{$i} AND v.is_short = :short{$i})";
                $params['short' . $i] = $feedChannel->includeShorts ? 1 : 0;
            }
        }

        if ($conditions === []) {
            return [];
        }

        $sql = 'SELECT ' . self::SELECT . '
                FROM videos v
                INNER JOIN channels c ON c.id = v.channel_ref
                WHERE ' . implode(' OR ', $conditions) . '
                ORDER BY v.published_at DESC, v.id DESC
                LIMIT :limit';

        $stmt = $this->db->pdo()->prepare($sql);
        foreach ($params as $name => $value) {
            $stmt->bindValue($name, $value, PDO::PARAM_INT);
        }
        $stmt->bindValue('limit', $limit ?? $feed->maxItems, PDO::PARAM_INT);
        $stmt->execute();

        return array_map(Video::fromRow(...), $stmt->fetchAll());
    }

    /**
     * Fingerabdruck des Datenbestands für eine Kanalmenge.
     *
     * Geht in den ETag des generierten Feeds ein: solange sich Anzahl und
     * jüngster Zeitstempel nicht ändern, ändert sich der Feed nicht und
     * Reader bekommen ein 304.
     *
     * @param list<int> $channelRefs
     */
    public function versionForChannels(array $channelRefs): string
    {
        if ($channelRefs === []) {
            return 'empty';
        }

        $placeholders = implode(',', array_fill(0, count($channelRefs), '?'));
        $stmt         = $this->db->pdo()->prepare(
            "SELECT COUNT(*) AS total,
                    COALESCE(MAX(published_at), '') AS newest,
                    COALESCE(MAX(first_seen_at), '') AS last_import,
                    COALESCE(MAX(updated_at), '') AS last_update
             FROM videos WHERE channel_ref IN ({$placeholders})"
        );
        $stmt->execute(array_values($channelRefs));
        $row = $stmt->fetch() ?: [];

        return implode('|', [
            (string) ($row['total'] ?? 0),
            (string) ($row['newest'] ?? ''),
            (string) ($row['last_import'] ?? ''),
            (string) ($row['last_update'] ?? ''),
        ]);
    }

    /**
     * Hält die Tabelle klein: pro Kanal nur die jüngsten $keep Videos behalten.
     */
    public function prune(int $channelRef, int $keep): int
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT published_at FROM videos
             WHERE channel_ref = :ref
             ORDER BY published_at DESC
             LIMIT 1 OFFSET :keep'
        );
        $stmt->bindValue('ref', $channelRef, PDO::PARAM_INT);
        $stmt->bindValue('keep', $keep, PDO::PARAM_INT);
        $stmt->execute();

        $cutoff = $stmt->fetchColumn();
        if ($cutoff === false) {
            return 0; // weniger Videos als das Limit - nichts zu tun
        }

        $delete = $this->db->pdo()->prepare(
            'DELETE FROM videos WHERE channel_ref = ? AND published_at <= ?'
        );
        $delete->execute([$channelRef, $cutoff]);

        return $delete->rowCount();
    }

    /**
     * Jüngste Videos eines einzelnen Kanals - für die Vorschau im Editor.
     *
     * @return list<Video>
     */
    public function latestForChannel(int $channelRef, int $limit = 5): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT ' . self::SELECT . '
             FROM videos v
             INNER JOIN channels c ON c.id = v.channel_ref
             WHERE v.channel_ref = :ref
             ORDER BY v.published_at DESC
             LIMIT :limit'
        );
        $stmt->bindValue('ref', $channelRef, PDO::PARAM_INT);
        $stmt->bindValue('limit', $limit, PDO::PARAM_INT);
        $stmt->execute();

        return array_map(Video::fromRow(...), $stmt->fetchAll());
    }
}
