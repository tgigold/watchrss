<?php

declare(strict_types=1);

namespace WatchRss\Repository;

use PDO;
use PDOException;
use RuntimeException;
use WatchRss\Model\Feed;
use WatchRss\Model\FeedChannel;
use WatchRss\Support\Database;
use WatchRss\Support\Token;

/**
 * Datenzugriff für `feeds` und `feed_channels`.
 *
 * Ein Feed hat zwei Tokens (siehe Support\Token): den geheimen `edit_token`
 * und den öffentlichen `feed_token`. Es gibt deshalb zwei getrennte
 * Suchmethoden - welche benutzt wird, entscheidet bewusst der Aufrufer.
 * Der Editor darf nur über den Edit-Token erreichbar sein, die XML nur
 * über den Feed-Token.
 */
final class FeedRepository
{
    /** So oft wird bei einer Token-Kollision neu gewürfelt. */
    private const TOKEN_ATTEMPTS = 5;

    public function __construct(private readonly Database $db)
    {
    }

    /**
     * Feed über den geheimen Bearbeiten-Token laden.
     */
    public function findByEditToken(string $token, bool $withChannels = true): ?Feed
    {
        if (!Token::isValidEdit($token)) {
            return null;
        }

        return $this->findBy('edit_token', $token, $withChannels);
    }

    /**
     * Feed über den öffentlichen Token aus der RSS-Adresse laden.
     */
    public function findByFeedToken(string $token, bool $withChannels = true): ?Feed
    {
        if (!Token::isValidFeed($token)) {
            return null;
        }

        return $this->findBy('feed_token', $token, $withChannels);
    }

    /**
     * @param string $column 'edit_token' oder 'feed_token' - niemals aus
     *                       Nutzereingaben, nur aus den Methoden oben.
     */
    private function findBy(string $column, string $value, bool $withChannels): ?Feed
    {
        $stmt = $this->db->pdo()->prepare("SELECT * FROM feeds WHERE {$column} = ? LIMIT 1");
        $stmt->execute([$value]);
        $row = $stmt->fetch();

        if (!$row) {
            return null;
        }

        $feed = Feed::fromRow($row);
        if ($withChannels && $feed->id !== null) {
            $feed->channels = $this->channelsForFeed($feed->id);
        }

        return $feed;
    }

    /**
     * @return list<FeedChannel>
     */
    public function channelsForFeed(int $feedId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT c.id, c.channel_id, c.title, c.handle, c.source_url, c.avatar_url,
                    c.last_fetched_at, c.last_success_at, c.last_status, c.last_error, c.failure_count,
                    fc.include_long, fc.include_shorts, fc.position
             FROM feed_channels fc
             INNER JOIN channels c ON c.id = fc.channel_ref
             WHERE fc.feed_id = ?
             ORDER BY fc.position ASC, c.title ASC'
        );
        $stmt->execute([$feedId]);

        return array_map(FeedChannel::fromRow(...), $stmt->fetchAll());
    }

    /**
     * Legt einen neuen Feed an. Beide Tokens werden hier erzeugt; bei der
     * (astronomisch unwahrscheinlichen) Kollision wird neu gewürfelt.
     *
     * @param list<array{channel_ref:int,include_long:bool,include_shorts:bool}> $channels
     */
    public function create(
        string $title,
        array $channels,
        int $ttlMinutes,
        int $maxItems,
        bool $prefixTitles,
        ?int $userId = null,
    ): Feed {
        $pdo = $this->db->pdo();

        for ($attempt = 0; $attempt < self::TOKEN_ATTEMPTS; $attempt++) {
            $editToken = Token::edit();
            $feedToken = Token::feed();

            try {
                $stmt = $pdo->prepare(
                    'INSERT INTO feeds (edit_token, feed_token, title, user_id, ttl_minutes, max_items, prefix_titles)
                     VALUES (?, ?, ?, ?, ?, ?, ?)'
                );
                $stmt->execute([$editToken, $feedToken, $title, $userId, $ttlMinutes, $maxItems, (int) $prefixTitles]);
            } catch (PDOException $e) {
                if ($this->isDuplicateKey($e)) {
                    continue;
                }
                throw $e;
            }

            $feedId = (int) $pdo->lastInsertId();
            $this->syncChannels($feedId, $channels);

            return $this->findByEditToken($editToken)
                ?? throw new RuntimeException('Feed konnte nicht geladen werden.');
        }

        throw new RuntimeException('Es konnte kein freier Token erzeugt werden.');
    }

    /**
     * Erzeugt einen neuen öffentlichen Token. Die alte RSS-Adresse wird damit
     * ungültig - genau dafür ist die Trennung der beiden Tokens da: Man kann
     * einen versehentlich geteilten Feed-Link zurückziehen, ohne den eigenen
     * Zugang zu verlieren.
     *
     * @return string der neue Feed-Token
     */
    public function rotateFeedToken(int $feedId): string
    {
        $pdo = $this->db->pdo();

        for ($attempt = 0; $attempt < self::TOKEN_ATTEMPTS; $attempt++) {
            $token = Token::feed();

            try {
                // updated_at bewusst mitziehen: Die Adresse ändert sich, also
                // darf sich auch der ETag ändern.
                $stmt = $pdo->prepare(
                    'UPDATE feeds SET feed_token = ?, updated_at = UTC_TIMESTAMP() WHERE id = ?'
                );
                $stmt->execute([$token, $feedId]);
            } catch (PDOException $e) {
                if ($this->isDuplicateKey($e)) {
                    continue;
                }
                throw $e;
            }

            return $token;
        }

        throw new RuntimeException('Es konnte kein freier Token erzeugt werden.');
    }

    /**
     * Aktualisiert die Feed-Einstellungen (ohne Kanäle).
     */
    public function updateSettings(int $feedId, string $title, int $ttlMinutes, int $maxItems, bool $prefixTitles): void
    {
        $stmt = $this->db->pdo()->prepare(
            'UPDATE feeds SET title = ?, ttl_minutes = ?, max_items = ?, prefix_titles = ? WHERE id = ?'
        );
        $stmt->execute([$title, $ttlMinutes, $maxItems, (int) $prefixTitles, $feedId]);
    }

    /**
     * Setzt die Kanalliste eines Feeds auf genau die übergebene Menge.
     * Entfernte Kanäle verschwinden, neue kommen dazu, Schalter werden
     * aktualisiert - alles in einer Transaktion.
     *
     * @param list<array{channel_ref:int,include_long:bool,include_shorts:bool}> $channels
     */
    public function syncChannels(int $feedId, array $channels): void
    {
        $this->db->transaction(function (PDO $pdo) use ($feedId, $channels): void {
            $keep = [];
            $stmt = $pdo->prepare(
                'INSERT INTO feed_channels (feed_id, channel_ref, include_long, include_shorts, position)
                 VALUES (:feed_id, :channel_ref, :include_long, :include_shorts, :position)
                 ON DUPLICATE KEY UPDATE
                    include_long   = VALUES(include_long),
                    include_shorts = VALUES(include_shorts),
                    position       = VALUES(position)'
            );

            foreach (array_values($channels) as $position => $channel) {
                $stmt->execute([
                    'feed_id'        => $feedId,
                    'channel_ref'    => $channel['channel_ref'],
                    'include_long'   => (int) $channel['include_long'],
                    'include_shorts' => (int) $channel['include_shorts'],
                    'position'       => $position,
                ]);
                $keep[] = (int) $channel['channel_ref'];
            }

            if ($keep === []) {
                $pdo->prepare('DELETE FROM feed_channels WHERE feed_id = ?')->execute([$feedId]);

                return;
            }

            $placeholders = implode(',', array_fill(0, count($keep), '?'));
            $delete       = $pdo->prepare(
                "DELETE FROM feed_channels WHERE feed_id = ? AND channel_ref NOT IN ({$placeholders})"
            );
            $delete->execute(array_merge([$feedId], $keep));
        });

        $this->touch($feedId);
    }

    /** Aktualisiert `updated_at`, damit sich der ETag des Feeds ändert. */
    public function touch(int $feedId): void
    {
        $this->db->pdo()->prepare('UPDATE feeds SET updated_at = UTC_TIMESTAMP() WHERE id = ?')->execute([$feedId]);
    }

    /**
     * Protokolliert eine Auslieferung des XML-Feeds (für Statistik/Bremse).
     *
     * `updated_at = updated_at` ist Absicht: die explizite Zuweisung
     * unterdrückt das ON UPDATE CURRENT_TIMESTAMP der Spalte. Sonst würde
     * jeder Abruf den ETag ändern und der Cache wäre wertlos.
     */
    public function markServed(int $feedId): void
    {
        $this->db->pdo()->prepare(
            'UPDATE feeds
                SET last_served_at = UTC_TIMESTAMP(),
                    serve_count    = serve_count + 1,
                    updated_at     = updated_at
              WHERE id = ?'
        )->execute([$feedId]);
    }

    /** Merkt sich, wann die XML zuletzt neu gebaut wurde (ohne ETag-Änderung). */
    public function markBuilt(int $feedId): void
    {
        $this->db->pdo()->prepare(
            'UPDATE feeds SET last_built_at = UTC_TIMESTAMP(), updated_at = updated_at WHERE id = ?'
        )->execute([$feedId]);
    }

    public function delete(int $feedId): void
    {
        $this->db->pdo()->prepare('DELETE FROM feeds WHERE id = ?')->execute([$feedId]);
    }

    /** SQLSTATE 23000 = Integritätsverletzung, hier also ein belegter Token. */
    private function isDuplicateKey(PDOException $e): bool
    {
        return $e->getCode() === '23000';
    }
}
