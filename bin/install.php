#!/usr/bin/env php
<?php
/**
 * watchrss - Datenbank einrichten.
 *
 * Legt alle Tabellen aus schema.sql an (CREATE TABLE IF NOT EXISTS, der
 * Aufruf ist also gefahrlos wiederholbar) und prüft die Umgebung.
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

echo "watchrss - Installation\n";
echo str_repeat('-', 48), "\n";

// --- 1. Umgebung prüfen ---------------------------------------------------
foreach (['pdo_mysql', 'curl', 'simplexml', 'mbstring', 'json'] as $extension) {
    if (!extension_loaded($extension)) {
        fwrite(STDERR, "FEHLER: PHP-Erweiterung '{$extension}' fehlt.\n");
        exit(1);
    }
}
echo "PHP-Erweiterungen: ok\n";

if (!is_dir(CACHE_DIR) && !@mkdir(CACHE_DIR, 0775, true)) {
    fwrite(STDERR, 'FEHLER: Cache-Verzeichnis nicht anlegbar: ' . CACHE_DIR . "\n");
    exit(1);
}
if (!is_writable(CACHE_DIR)) {
    fwrite(STDERR, 'FEHLER: Cache-Verzeichnis nicht beschreibbar: ' . CACHE_DIR . "\n");
    exit(1);
}
echo 'Cache-Verzeichnis: ', CACHE_DIR, " (beschreibbar)\n";

// --- 2. Schema einspielen --------------------------------------------------
$schemaFile = $root . '/schema.sql';
$sql        = file_get_contents($schemaFile);
if ($sql === false) {
    fwrite(STDERR, "FEHLER: schema.sql nicht lesbar.\n");
    exit(1);
}

$statements = WatchRss\Migration\SqlFile::statements($sql);

$pdo = $app->database()->pdo();

// Bestehende Installation? Dann laufen Migrationen nur mit Backup.
$wasEmpty = $app->database()->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN) === [];
echo 'Datenbank: ', DB_NAME, '@', DB_HOST, ' (', $app->database()->query('SELECT VERSION()')->fetchColumn(), ")\n\n";

foreach ($statements as $statement) {
    preg_match('/CREATE TABLE IF NOT EXISTS `([a-z_]+)`/i', $statement, $m);
    $label = $m[1] ?? substr(preg_replace('/\s+/', ' ', $statement) ?? '', 0, 40);

    try {
        $pdo->exec($statement);
        echo "  [ok] {$label}\n";
    } catch (PDOException $e) {
        echo "  [!!] {$label}: ", $e->getMessage(), "\n";
        exit(1);
    }
}

// --- 3. Migrationen ---------------------------------------------------------
// schema.sql ist der eingefrorene Ausgangsstand; alles danach kommt über
// migrations/. Nur bei einer vorher leeren Datenbank ohne Backup.
echo "\nMigrationen:\n";
$result = $app->migrator(withBackup: !$wasEmpty)->migrate();
if ($result['backup'] !== null) {
    echo "  Backup: {$result['backup']}\n";
}
foreach ($result['applied'] as $entry) {
    printf("  [ok] %04d_%s -> %s\n", $entry['version'], $entry['name'], $entry['result']);
}

// --- 4. Ergebnis -----------------------------------------------------------
$tables = $app->database()->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
echo "\nTabellen: ", implode(', ', $tables), "\n";
echo "\nFertig. Nächster Schritt: Cronjob einrichten\n";
echo "  */10 * * * * ", PHP_BINARY, ' ', $root, "/bin/fetch.php --quiet\n";
