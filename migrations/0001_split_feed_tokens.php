<?php

declare(strict_types=1);

use WatchRss\Migration\Migration;
use WatchRss\Migration\Schema;
use WatchRss\Support\Token;

/**
 * feeds.token -> feeds.edit_token (geheim) + feeds.feed_token (öffentlich).
 *
 * Übernommen aus dem früheren bin/migrate.php (Stufen 1 und 2). Jeder
 * Schritt prüft selbst, ob er nötig ist: Auf Installationen, die schon
 * umgestellt sind, und auf Neuinstallationen aus schema.sql passiert nichts.
 */
return new class implements Migration {
    public function description(): string
    {
        return 'Feed-Token in geheimen Editor- und öffentlichen Feed-Token aufteilen';
    }

    public function up(PDO $pdo, Schema $schema): string
    {
        $done = [];

        if (!$schema->hasColumn('feeds', 'edit_token')) {
            $pdo->exec('ALTER TABLE `feeds` ADD COLUMN `edit_token` VARCHAR(64) NULL DEFAULT NULL AFTER `id`');
            if ($schema->hasColumn('feeds', 'token')) {
                $pdo->exec('UPDATE `feeds` SET `edit_token` = `token` WHERE `edit_token` IS NULL');
            }
            $pdo->exec('ALTER TABLE `feeds` ADD UNIQUE KEY `uniq_feeds_edit_token` (`edit_token`)');
            $done[] = 'edit_token angelegt';
        }

        if (!$schema->hasColumn('feeds', 'feed_token')) {
            $pdo->exec('ALTER TABLE `feeds` ADD COLUMN `feed_token` VARCHAR(32) NULL DEFAULT NULL AFTER `edit_token`');

            $ids    = $pdo->query('SELECT id FROM feeds');
            $update = $pdo->prepare('UPDATE feeds SET feed_token = ? WHERE id = ?');
            foreach ($ids !== false ? $ids->fetchAll(PDO::FETCH_COLUMN) : [] as $id) {
                // Bei einer Kollision (praktisch nie) neu würfeln.
                for ($attempt = 0; $attempt < 5; $attempt++) {
                    try {
                        $update->execute([Token::feed(), $id]);
                        break;
                    } catch (PDOException $e) {
                        if ($e->getCode() !== '23000') {
                            throw $e;
                        }
                    }
                }
            }

            $pdo->exec('ALTER TABLE `feeds` ADD UNIQUE KEY `uniq_feeds_feed_token` (`feed_token`)');
            $done[] = 'feed_token angelegt und befüllt';
        }

        if (!$schema->isNotNull('feeds', 'edit_token')) {
            $pdo->exec('ALTER TABLE `feeds` MODIFY `edit_token` VARCHAR(64) NOT NULL');
            $pdo->exec('ALTER TABLE `feeds` MODIFY `feed_token` VARCHAR(32) NOT NULL');
            $done[] = 'Token-Spalten auf NOT NULL';
        }

        if ($schema->hasColumn('feeds', 'token')) {
            $orphans = $pdo->query("SELECT COUNT(*) FROM feeds WHERE edit_token IS NULL OR edit_token = ''");
            $count   = $orphans !== false ? (int) $orphans->fetchColumn() : 0;
            if ($count > 0) {
                throw new RuntimeException("Abbruch: {$count} Feed(s) ohne edit_token.");
            }

            if ($schema->hasIndex('feeds', 'uniq_feeds_token')) {
                $pdo->exec('ALTER TABLE `feeds` DROP INDEX `uniq_feeds_token`');
            }
            $pdo->exec('ALTER TABLE `feeds` DROP COLUMN `token`');
            $done[] = 'alte Spalte token entfernt';
        }

        return $done === [] ? 'nichts zu tun (bereits umgestellt)' : implode(', ', $done);
    }
};
