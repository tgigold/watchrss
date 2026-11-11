<?php

declare(strict_types=1);

namespace WatchRss\Repository;

use PDO;
use WatchRss\Model\Channel;
use WatchRss\Support\Database;

/**
 * Datenzugriff für die Tabelle `channels`.
 */
final class ChannelRepository
{
    /** Spalten, die für Channel::fromRow() gebraucht werden. */
    private const COLUMNS = [
        'id', 'channel_id', 'title', 'handle', 'source_url', 'avatar_url', 'http_etag',
        'http_last_modified', 'last_fetched_at', 'last_success_at', 'last_status',
        'last_error', 'failure_count',
    ];

    public function __construct(private readonly Database $db)
    {
    }

    /**
     * Spaltenliste für ein SELECT, optional mit Tabellen-Alias.
     */
    private static function columns(string $alias = ''): string
    {
        $prefix = $alias !== '' ? $alias . '.' : '';

        return $prefix . implode(', ' . $prefix, self::COLUMNS);
    }

    public function findById(int $id): ?Channel
    {
        $stmt = $this->db->pdo()->prepare('SELECT ' . self::columns() . ' FROM channels WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch();

        return $row ? Channel::fromRow($row) : null;
    }

    public function findByChannelId(string $channelId): ?Channel
    {
        $stmt = $this->db->pdo()->prepare('SELECT ' . self::columns() . ' FROM channels WHERE channel_id = ?');
        $stmt->execute([$channelId]);
        $row = $stmt->fetch();

        return $row ? Channel::fromRow($row) : null;
    }

    /**
     * Treffer über das @handle - erspart bei bekannten Kanälen den
     * kompletten HTML-Abruf der YouTube-Seite.
     */
    public function findByHandle(string $handle): ?Channel
    {
        $stmt = $this->db->pdo()->prepare('SELECT ' . self::columns() . ' FROM channels WHERE handle = ? LIMIT 1');
        $stmt->execute([ltrim($handle, '@')]);
        $row = $stmt->fetch();

        return $row ? Channel::fromRow($row) : null;
    }

    /**
     * Legt den Kanal an oder aktualisiert Stammdaten und gibt ihn mit ID zurück.
     */
    public function save(Channel $channel): Channel
    {
        $pdo  = $this->db->pdo();
        $stmt = $pdo->prepare(
            'INSERT INTO channels (channel_id, title, handle, source_url, avatar_url)
             VALUES (:channel_id, :title, :handle, :source_url, :avatar_url)
             ON DUPLICATE KEY UPDATE
                title      = IF(VALUES(title) <> \'\', VALUES(title), title),
                handle     = COALESCE(VALUES(handle), handle),
                source_url = COALESCE(VALUES(source_url), source_url),
                avatar_url = COALESCE(VALUES(avatar_url), avatar_url)'
        );
        $stmt->execute([
            'channel_id' => $channel->channelId,
            'title'      => $channel->title,
            'handle'     => $channel->handle,
            'source_url' => $channel->sourceUrl,
            'avatar_url' => $channel->avatarUrl,
        ]);

        // Bei ON DUPLICATE KEY liefert lastInsertId() nicht zuverlaessig die
        // bestehende ID - deshalb immer frisch lesen.
        return $this->findByChannelId($channel->channelId) ?? $channel;
    }

    /**
     * Kanäle, die ein Cron-Lauf abholen soll.
     *
     * Berücksichtigt nur Kanäle, die in mindestens einem Feed stecken, und
     * setzt bei wiederholten Fehlern ein exponentielles Backoff (2^n * Intervall,
     * gedeckelt bei 24 Stunden).
     *
     * @return list<Channel>
     */
    public function dueForFetch(int $limit, int $refreshSeconds, bool $force = false): array
    {
        $sql = 'SELECT ' . self::columns('c') . '
                FROM channels c
                WHERE EXISTS (SELECT 1 FROM feed_channels fc WHERE fc.channel_ref = c.id)';

        if (!$force) {
            $sql .= ' AND (
                        c.last_fetched_at IS NULL
                        OR c.last_fetched_at < (NOW() - INTERVAL LEAST(
                               :refresh * POW(2, LEAST(c.failure_count, 6)),
                               86400
                           ) SECOND)
                      )';
        }

        $sql .= ' ORDER BY c.last_fetched_at IS NOT NULL, c.last_fetched_at ASC LIMIT :limit';

        $stmt = $this->db->pdo()->prepare($sql);
        if (!$force) {
            $stmt->bindValue('refresh', $refreshSeconds, PDO::PARAM_INT);
        }
        $stmt->bindValue('limit', $limit, PDO::PARAM_INT);
        $stmt->execute();

        return array_values(array_map(Channel::fromRow(...), $stmt->fetchAll()));
    }

    /**
     * Schreibt das Ergebnis eines Abrufs zurück (Erfolg wie Fehler).
     */
    public function markFetched(
        int $channelRef,
        string $status,
        ?string $error = null,
        ?string $etag = null,
        ?string $lastModified = null,
        ?string $title = null,
    ): void {
        $success = in_array($status, ['ok', 'not_modified'], true);

        $stmt = $this->db->pdo()->prepare(
            'UPDATE channels SET
                last_fetched_at    = UTC_TIMESTAMP(),
                last_success_at    = IF(:success_a, UTC_TIMESTAMP(), last_success_at),
                last_status        = :status,
                last_error         = :error,
                failure_count      = IF(:success_b, 0, failure_count + 1),
                http_etag          = COALESCE(:etag, http_etag),
                http_last_modified = COALESCE(:last_modified, http_last_modified),
                title              = COALESCE(NULLIF(:title, \'\'), title)
             WHERE id = :id'
        );
        $stmt->execute([
            'success_a'     => (int) $success,
            'success_b'     => (int) $success,
            'status'        => $status,
            'error'         => $error !== null ? mb_substr($error, 0, 500) : null,
            'etag'          => $etag,
            'last_modified' => $lastModified,
            'title'         => $title ?? '',
            'id'            => $channelRef,
        ]);
    }

    /**
     * Entfernt Kanäle, die in keinem Feed mehr vorkommen (Aufräumen per Cron).
     */
    public function deleteOrphans(): int
    {
        return (int) $this->db->pdo()->exec(
            'DELETE c FROM channels c
             LEFT JOIN feed_channels fc ON fc.channel_ref = c.id
             WHERE fc.channel_ref IS NULL'
        );
    }

    /** @return array{channels:int,videos:int,feeds:int} */
    public function stats(): array
    {
        return [
            'channels' => (int) $this->db->query('SELECT COUNT(*) FROM channels')->fetchColumn(),
            'videos'   => (int) $this->db->query('SELECT COUNT(*) FROM videos')->fetchColumn(),
            'feeds'    => (int) $this->db->query('SELECT COUNT(*) FROM feeds')->fetchColumn(),
        ];
    }
}
