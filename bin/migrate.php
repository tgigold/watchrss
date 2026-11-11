#!/usr/bin/env php
<?php
/**
 * watchrss - Schema-Migrationen.
 *
 * Jeder Schritt prüft selbst, ob er schon erledigt ist; das Skript kann also
 * beliebig oft laufen. Für eine frische Installation braucht man es nicht -
 * da legt bin/install.php direkt die aktuelle Fassung aus schema.sql an.
 *
 * Aufruf:
 *   php bin/migrate.php            Erweiterungsschritte (gefahrlos, jederzeit)
 *   php bin/migrate.php --finish   Aufräumschritte (erst NACH dem Upload!)
 *   php bin/migrate.php --status   nur anzeigen, nichts ändern
 *
 * Zum Vorgehen bei "feeds.token" -> "edit_token" + "feed_token":
 * Die Umbenennung läuft in zwei Stufen (expand/contract), damit alte und neue
 * Codefassung gleichzeitig laufen können und die Seite während des Uploads
 * nicht ausfällt:
 *
 *   Stufe 1 (ohne --finish): edit_token und feed_token anlegen und füllen,
 *      token nullable machen. Alte Fassung liest weiter token, neue Fassung
 *      liest edit_token - beide funktionieren.
 *   Stufe 2 (--finish): token löschen. Erst ausführen, wenn überall die neue
 *      Codefassung liegt.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit('Nur über die Kommandozeile aufrufbar.');
}

/** @var WatchRss\App $app */
$app = require dirname(__DIR__) . '/bootstrap.php';

use WatchRss\Support\Token;

$options = getopt('', ['finish', 'status']);
$finish  = isset($options['finish']);
$dryRun  = isset($options['status']);

$db  = $app->database();
$pdo = $db->pdo();

/** Gibt es diese Spalte? */
$hasColumn = static function (string $table, string $column) use ($pdo): bool {
    $stmt = $pdo->prepare(
        'SELECT COUNT(*) FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?'
    );
    $stmt->execute([$table, $column]);

    return (int) $stmt->fetchColumn() > 0;
};

/** Ist die Spalte NOT NULL? */
$isNotNull = static function (string $table, string $column) use ($pdo): bool {
    $stmt = $pdo->prepare(
        'SELECT IS_NULLABLE FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?'
    );
    $stmt->execute([$table, $column]);

    return $stmt->fetchColumn() === 'NO';
};

echo "watchrss - Migrationen\n", str_repeat('-', 60), "\n";
echo 'Datenbank: ', DB_NAME, '@', DB_HOST, "\n\n";

/**
 * @var list<array{name:string,finish:bool,needed:callable():bool,apply:callable():string}> $steps
 */
$steps = [
    [
        'name'   => 'feeds.edit_token anlegen und aus feeds.token füllen',
        'finish' => false,
        'needed' => static fn (): bool => !$hasColumn('feeds', 'edit_token'),
        'apply'  => static function () use ($pdo, $hasColumn): string {
            $pdo->exec('ALTER TABLE `feeds` ADD COLUMN `edit_token` VARCHAR(64) NULL DEFAULT NULL AFTER `id`');

            if ($hasColumn('feeds', 'token')) {
                $pdo->exec('UPDATE `feeds` SET `edit_token` = `token` WHERE `edit_token` IS NULL');
            }

            $pdo->exec('ALTER TABLE `feeds` ADD UNIQUE KEY `uniq_feeds_edit_token` (`edit_token`)');

            return 'Spalte angelegt, Werte übernommen, Unique-Index gesetzt';
        },
    ],
    [
        'name'   => 'feeds.feed_token anlegen und öffentliche Tokens erzeugen',
        'finish' => false,
        'needed' => static fn (): bool => !$hasColumn('feeds', 'feed_token'),
        'apply'  => static function () use ($pdo, $db): string {
            $pdo->exec('ALTER TABLE `feeds` ADD COLUMN `feed_token` VARCHAR(32) NULL DEFAULT NULL AFTER `edit_token`');

            $ids     = $db->query('SELECT id FROM feeds')->fetchAll(PDO::FETCH_COLUMN);
            $update  = $pdo->prepare('UPDATE feeds SET feed_token = ? WHERE id = ?');
            $created = 0;

            foreach ($ids as $id) {
                // Bei einer Kollision (praktisch nie) einfach neu würfeln.
                for ($attempt = 0; $attempt < 5; $attempt++) {
                    try {
                        $update->execute([Token::feed(), $id]);
                        $created++;
                        break;
                    } catch (PDOException $e) {
                        if ($e->getCode() !== '23000') {
                            throw $e;
                        }
                    }
                }
            }

            $pdo->exec('ALTER TABLE `feeds` ADD UNIQUE KEY `uniq_feeds_feed_token` (`feed_token`)');

            return sprintf('Spalte angelegt, %d öffentliche Token erzeugt', $created);
        },
    ],
    [
        'name'   => 'feeds.token auf NULL erlauben (damit die neue Fassung schreiben kann)',
        'finish' => false,
        'needed' => static fn (): bool => $hasColumn('feeds', 'token') && $isNotNull('feeds', 'token'),
        'apply'  => static function () use ($pdo): string {
            $pdo->exec('ALTER TABLE `feeds` MODIFY `token` VARCHAR(64) NULL DEFAULT NULL');

            return 'Spalte ist jetzt optional';
        },
    ],
    [
        'name'   => 'feeds.edit_token / feed_token auf NOT NULL setzen',
        'finish' => true,
        'needed' => static fn (): bool => $hasColumn('feeds', 'edit_token') && !$isNotNull('feeds', 'edit_token'),
        'apply'  => static function () use ($pdo): string {
            $pdo->exec('ALTER TABLE `feeds` MODIFY `edit_token` VARCHAR(64) NOT NULL');
            $pdo->exec('ALTER TABLE `feeds` MODIFY `feed_token` VARCHAR(32) NOT NULL');

            return 'beide Spalten sind jetzt Pflicht';
        },
    ],
    [
        'name'   => 'alte Spalte feeds.token entfernen',
        'finish' => true,
        'needed' => static fn (): bool => $hasColumn('feeds', 'token'),
        'apply'  => static function () use ($pdo, $db): string {
            // Sicherheitsnetz: nur löschen, wenn jede Zeile einen edit_token hat.
            $orphans = (int) $db->query(
                "SELECT COUNT(*) FROM feeds WHERE edit_token IS NULL OR edit_token = ''"
            )->fetchColumn();

            if ($orphans > 0) {
                throw new RuntimeException("Abbruch: {$orphans} Feed(s) ohne edit_token.");
            }

            $pdo->exec('ALTER TABLE `feeds` DROP INDEX `uniq_feeds_token`');
            $pdo->exec('ALTER TABLE `feeds` DROP COLUMN `token`');

            return 'Spalte und Index gelöscht';
        },
    ],
];

$applied = $pending = $skipped = 0;

foreach ($steps as $step) {
    $needed = ($step['needed'])();
    $stage  = $step['finish'] ? 'Stufe 2' : 'Stufe 1';

    if (!$needed) {
        echo "  [übersprungen] {$step['name']}\n";
        $skipped++;
        continue;
    }

    if ($step['finish'] && !$finish) {
        echo "  [wartet]       {$step['name']}  ({$stage}: erst mit --finish nach dem Upload)\n";
        $pending++;
        continue;
    }

    if ($dryRun) {
        echo "  [offen]        {$step['name']}  ({$stage})\n";
        $pending++;
        continue;
    }

    try {
        $result = ($step['apply'])();
        echo "  [ausgeführt]   {$step['name']}\n                 -> {$result}\n";
        $applied++;
    } catch (Throwable $e) {
        echo "  [FEHLER]       {$step['name']}\n                 -> ", $e->getMessage(), "\n";
        exit(1);
    }
}

echo "\n{$applied} ausgeführt, {$skipped} bereits erledigt, {$pending} offen\n";

if ($pending > 0 && !$finish) {
    echo "\nNach dem Hochladen der neuen Codefassung abschließen mit:\n";
    echo '  ', PHP_BINARY, ' ', __FILE__, " --finish\n";
}
