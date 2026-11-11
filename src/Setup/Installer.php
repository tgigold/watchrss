<?php

declare(strict_types=1);

namespace WatchRss\Setup;

use PDO;
use RuntimeException;
use WatchRss\Migration\Migrator;
use WatchRss\Migration\SqlDumpBackup;
use WatchRss\Migration\SqlFile;
use WatchRss\Support\Database;

/**
 * Einrichtung der Datenbank: schema.sql einspielen, dann alle Migrationen.
 * Benutzt vom Web-Setup und von bin/install.php - ein Weg für beides.
 *
 * Gefahrlos wiederholbar (CREATE TABLE IF NOT EXISTS, Migrationen werden
 * nur einmal ausgeführt). Auf einer bestehenden Datenbank wird vor den
 * Migrationen ein Backup geschrieben.
 */
final class Installer
{
    /** PHP-Erweiterungen, ohne die watchrss nicht läuft. */
    public const EXTENSIONS = ['pdo_mysql', 'curl', 'simplexml', 'xmlwriter', 'libxml', 'mbstring', 'json', 'zlib'];

    public function __construct(
        private readonly string $rootPath,
        private readonly string $backupDir,
    ) {
    }

    /**
     * Prüft die Umgebung.
     *
     * @param  list<string> $writableDirs Verzeichnisse, in die geschrieben werden muss
     * @return list<array{label:string,ok:bool}>
     */
    public static function checkEnvironment(array $writableDirs): array
    {
        $checks = [[
            'label' => 'PHP 8.2 oder neuer (läuft: ' . PHP_VERSION . ')',
            'ok'    => version_compare(PHP_VERSION, '8.2.0', '>='),
        ]];

        foreach (self::EXTENSIONS as $extension) {
            $checks[] = ['label' => 'PHP-Erweiterung ' . $extension, 'ok' => extension_loaded($extension)];
        }

        foreach ($writableDirs as $dir) {
            if (!is_dir($dir)) {
                @mkdir($dir, 0775, true);
            }
            $checks[] = ['label' => 'beschreibbar: ' . basename($dir) . '/', 'ok' => is_dir($dir) && is_writable($dir)];
        }

        return $checks;
    }

    /**
     * @param  list<array{label:string,ok:bool}> $checks
     */
    public static function allOk(array $checks): bool
    {
        foreach ($checks as $check) {
            if (!$check['ok']) {
                return false;
            }
        }

        return true;
    }

    /**
     * @return array{tables:list<string>,backup:?string,applied:list<array{version:int,name:string,result:string}>}
     */
    public function install(Database $database): array
    {
        $sql = file_get_contents($this->rootPath . '/schema.sql');
        if ($sql === false) {
            throw new RuntimeException('schema.sql nicht lesbar.');
        }

        $pdo      = $database->pdo();
        $wasEmpty = $database->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN) === [];

        $tables = [];
        foreach (SqlFile::statements($sql) as $statement) {
            $pdo->exec($statement);
            if (preg_match('/CREATE TABLE IF NOT EXISTS `([a-z_]+)`/i', $statement, $m) === 1) {
                $tables[] = $m[1];
            }
        }

        // Bestehende Datenbank: Migrationen nur mit Backup.
        $migrator = new Migrator(
            $pdo,
            $this->rootPath . '/migrations',
            $wasEmpty ? null : new SqlDumpBackup($pdo, $this->backupDir),
        );
        $result = $migrator->migrate();

        return ['tables' => $tables, 'backup' => $result['backup'], 'applied' => $result['applied']];
    }
}
