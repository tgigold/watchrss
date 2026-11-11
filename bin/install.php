#!/usr/bin/env php
<?php
/**
 * watchrss - Einrichtung per Kommandozeile.
 *
 * Prüft die Umgebung, spielt schema.sql ein und führt alle Migrationen aus
 * (auf einer bestehenden Datenbank mit Backup vorher). Gefahrlos
 * wiederholbar. Ohne Kommandozeile: im Browser /setup aufrufen.
 *
 * Aufruf:  php bin/install.php
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit('Nur über die Kommandozeile aufrufbar.');
}

/** @var WatchRss\App $app */
$app  = require dirname(__DIR__) . '/bootstrap.php';
$root = dirname(__DIR__);

use WatchRss\Setup\Installer;

echo "watchrss - Installation\n", str_repeat('-', 48), "\n";

// --- 1. Umgebung prüfen ---------------------------------------------------
$checks = Installer::checkEnvironment([(string) CACHE_DIR, (string) BACKUP_DIR]);
foreach ($checks as $check) {
    echo '  [', $check['ok'] ? 'ok' : '!!', '] ', $check['label'], "\n";
}
if (!Installer::allOk($checks)) {
    fwrite(STDERR, "\nFEHLER: Voraussetzungen nicht erfüllt.\n");
    exit(1);
}

// --- 2. Schema und Migrationen ---------------------------------------------
echo "\nDatenbank: ", DB_NAME, '@', DB_HOST, "\n";

try {
    $result = (new Installer($root, (string) BACKUP_DIR))->install($app->database());
} catch (Throwable $e) {
    fwrite(STDERR, '  [!!] ' . $e->getMessage() . "\n");
    exit(1);
}

echo '  Tabellen: ', implode(', ', $result['tables']), "\n";
if ($result['backup'] !== null) {
    echo '  Backup: ', $result['backup'], "\n";
}
foreach ($result['applied'] as $entry) {
    printf("  [ok] Migration %04d_%s -> %s\n", $entry['version'], $entry['name'], $entry['result']);
}

echo "\nFertig. Nächster Schritt: Cronjob einrichten\n";
echo '  */10 * * * * ', PHP_BINARY, ' ', $root, "/bin/fetch.php --quiet\n";
