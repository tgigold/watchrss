<?php

declare(strict_types=1);

use WatchRss\Migration\Migration;
use WatchRss\Migration\Schema;

/**
 * Edit-Tokens nur noch als SHA-256-Hash speichern.
 *
 * Legt feeds.edit_token_hash an, füllt sie mit SHA2(edit_token, 256) - das
 * entspricht hash('sha256', $token) in PHP (Support\Token::hash) - und
 * entfernt danach die Klartext-Spalte. Bestehende Bearbeiten-Links
 * funktionieren weiter: Der Token aus der URL wird gehasht und verglichen.
 *
 * Danach lässt sich ein verlorener Bearbeiten-Link nicht mehr aus der
 * Datenbank wiederherstellen. Das ist Absicht.
 */
return new class implements Migration {
    public function description(): string
    {
        return 'Bearbeiten-Token nur noch als SHA-256-Hash speichern';
    }

    public function up(PDO $pdo, Schema $schema): string
    {
        $done = [];

        if (!$schema->hasColumn('feeds', 'edit_token_hash')) {
            $pdo->exec('ALTER TABLE `feeds` ADD COLUMN `edit_token_hash` CHAR(64) NULL DEFAULT NULL AFTER `id`');
            $done[] = 'edit_token_hash angelegt';
        }

        if ($schema->hasColumn('feeds', 'edit_token')) {
            $hashed = $pdo->exec('UPDATE `feeds` SET `edit_token_hash` = SHA2(`edit_token`, 256) WHERE `edit_token_hash` IS NULL');
            $done[] = sprintf('%d Token gehasht', (int) $hashed);
        }

        $missing = $pdo->query('SELECT COUNT(*) FROM `feeds` WHERE `edit_token_hash` IS NULL');
        $count   = $missing !== false ? (int) $missing->fetchColumn() : 0;
        if ($count > 0) {
            throw new RuntimeException("Abbruch: {$count} Feed(s) ohne edit_token_hash.");
        }

        if (!$schema->isNotNull('feeds', 'edit_token_hash')) {
            $pdo->exec('ALTER TABLE `feeds` MODIFY `edit_token_hash` CHAR(64) NOT NULL');
        }
        if (!$schema->hasIndex('feeds', 'uniq_feeds_edit_token_hash')) {
            $pdo->exec('ALTER TABLE `feeds` ADD UNIQUE KEY `uniq_feeds_edit_token_hash` (`edit_token_hash`)');
        }

        if ($schema->hasColumn('feeds', 'edit_token')) {
            if ($schema->hasIndex('feeds', 'uniq_feeds_edit_token')) {
                $pdo->exec('ALTER TABLE `feeds` DROP INDEX `uniq_feeds_edit_token`');
            }
            $pdo->exec('ALTER TABLE `feeds` DROP COLUMN `edit_token`');
            $done[] = 'Klartext-Spalte edit_token entfernt';
        }

        return $done === [] ? 'nichts zu tun' : implode(', ', $done);
    }
};
