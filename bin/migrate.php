#!/usr/bin/env php
<?php
/**
 * watchrss - Datenbank-Migrationen (CLI).
 *
 * Führt alle offenen Migrationen aus migrations/ aus. Vorher wird
 * automatisch ein Backup nach var/backups/ geschrieben. Dasselbe geht im
 * Browser unter /admin (ADMIN_TOKEN in config.inc.php).
 *
 * Aufruf:
 *   php bin/migrate.php            offene Migrationen ausführen
 *   php bin/migrate.php --status   nur anzeigen, nichts ändern
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit('Nur über die Kommandozeile aufrufbar.');
}

/** @var WatchRss\App $app */
$app = require dirname(__DIR__) . '/bootstrap.php';

use WatchRss\Migration\MigrationFailed;

$options = getopt('', ['status', 'finish']);

echo "watchrss - Migrationen\n", str_repeat('-', 60), "\n";
echo 'Datenbank: ', DB_NAME, '@', DB_HOST, "\n\n";

if (isset($options['finish'])) {
    echo "Hinweis: --finish gibt es nicht mehr, alle Schritte laufen in einem Durchgang.\n\n";
}

$migrator = $app->migrator();

if (!isset($options['status'])) {
    try {
        $result = $migrator->migrate();
    } catch (MigrationFailed $e) {
        foreach ($e->applied as $entry) {
            printf("  [ausgeführt] %04d_%s -> %s\n", $entry['version'], $entry['name'], $entry['result']);
        }
        fwrite(STDERR, "  [FEHLER]     " . $e->getMessage() . "\n");
        if ($e->backupFile !== null) {
            fwrite(STDERR, "\nBackup vor dem Lauf: {$e->backupFile}\n");
        }
        exit(1);
    }

    if ($result['backup'] !== null) {
        echo "Backup: {$result['backup']}\n\n";
    }
    foreach ($result['applied'] as $entry) {
        printf("  [ausgeführt] %04d_%s -> %s\n", $entry['version'], $entry['name'], $entry['result']);
    }
    if ($result['applied'] === []) {
        echo "Keine offenen Migrationen.\n";
    }
    echo "\n";
}

foreach ($migrator->status() as $migration) {
    printf(
        "  %04d  %-10s %s\n",
        $migration['version'],
        $migration['applied_at'] === null ? 'offen' : 'erledigt',
        $migration['description'],
    );
}
