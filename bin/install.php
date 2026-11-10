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

// Kommentarzeilen entfernen, dann an Statement-Grenzen zerlegen.
$sql = preg_replace('/^--.*$/m', '', $sql) ?? $sql;

$statements = array_values(array_filter(
    array_map('trim', explode(";\n", $sql . "\n")),
    static fn (string $statement): bool => $statement !== '' && $statement !== ';'
));

$pdo = $app->database()->pdo();
echo 'Datenbank: ', DB_NAME, '@', DB_HOST, ' (', $pdo->query('SELECT VERSION()')->fetchColumn(), ")\n\n";

foreach ($statements as $statement) {
    $statement = rtrim($statement, ";\n ");
    if ($statement === '') {
        continue;
    }

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

// --- 3. Ergebnis -----------------------------------------------------------
$tables = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
echo "\nTabellen: ", implode(', ', $tables), "\n";
echo "\nFertig. Nächster Schritt: Cronjob einrichten\n";
echo "  */10 * * * * ", PHP_BINARY, ' ', $root, "/bin/fetch.php --quiet\n";
