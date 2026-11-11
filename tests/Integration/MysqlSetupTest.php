<?php

declare(strict_types=1);

namespace WatchRss\Tests\Integration;

use PDO;
use PHPUnit\Framework\TestCase;
use WatchRss\App;
use WatchRss\Http\Request;
use WatchRss\Http\Response;

/**
 * Komplettes Web-Setup gegen echtes MySQL/MariaDB (Wegwerf-Container).
 * Die Datenbank wird geleert, Config und var/ liegen im Temp-Verzeichnis.
 */
final class MysqlSetupTest extends TestCase
{
    private string $dir;
    private PDO $pdo;

    /** @var array{host:string,port:string,dbname:string} */
    private array $dsn;

    protected function setUp(): void
    {
        $dsn = getenv('WATCHRSS_TEST_MYSQL_DSN');
        if (!is_string($dsn) || $dsn === '') {
            self::markTestSkipped('WATCHRSS_TEST_MYSQL_DSN nicht gesetzt.');
        }

        $parts = [];
        foreach (explode(';', substr($dsn, strlen('mysql:'))) as $pair) {
            [$key, $value] = explode('=', $pair, 2) + [1 => ''];
            $parts[$key]   = $value;
        }
        $this->dsn = [
            'host'   => $parts['host'] ?? '',
            'port'   => $parts['port'] ?? '3306',
            'dbname' => $parts['dbname'] ?? '',
        ];

        $this->pdo = new PDO($dsn, (string) getenv('WATCHRSS_TEST_MYSQL_USER'), (string) getenv('WATCHRSS_TEST_MYSQL_PASS'), [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        ]);
        $this->pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
        $tables = $this->pdo->query('SHOW TABLES');
        foreach ($tables !== false ? $tables->fetchAll(PDO::FETCH_COLUMN) : [] as $table) {
            $this->pdo->exec('DROP TABLE `' . (string) $table . '`');
        }
        $this->pdo->exec('SET FOREIGN_KEY_CHECKS = 1');

        $this->dir = sys_get_temp_dir() . '/watchrss-mysql-setup-' . bin2hex(random_bytes(4));
        mkdir($this->dir . '/var', 0775, true);
    }

    protected function tearDown(): void
    {
        if (isset($this->dir)) {
            exec('rm -rf ' . escapeshellarg($this->dir));
        }
    }

    public function testCompleteSetupWritesConfigAndLocksItself(): void
    {
        $this->request('GET');
        $code = trim((string) file_get_contents($this->dir . '/var/setup-code.txt'));

        $response = $this->request('POST', [
            'setup_code' => $code,
            'base_url'   => 'https://neu.example/',
            'db_host'    => $this->dsn['host'],
            'db_port'    => $this->dsn['port'],
            'db_name'    => $this->dsn['dbname'],
            'db_user'    => (string) getenv('WATCHRSS_TEST_MYSQL_USER'),
            'db_pass'    => (string) getenv('WATCHRSS_TEST_MYSQL_PASS'),
        ]);

        self::assertSame(200, $response->status, strip_tags($response->body));
        self::assertStringContainsString('eingerichtet', $response->body);

        // Config: gültiges PHP mit den richtigen Werten und drei Geheimnissen.
        $config = $this->dir . '/config.inc.php';
        self::assertFileExists($config);
        $script = 'require ' . var_export($config, true) . '; echo json_encode([DB_NAME, APP_BASE_URL, strlen(ADMIN_TOKEN), strlen(CRON_TOKEN), strlen(TRACKING_SALT)]);';
        $out    = (string) shell_exec(escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg($script));
        self::assertSame([$this->dsn['dbname'], 'https://neu.example', 48, 48, 48], json_decode($out, true));

        // Datenbank: Tabellen und Migrationen.
        $tables = $this->pdo->query('SHOW TABLES');
        self::assertNotFalse($tables);
        self::assertContains('feeds', $tables->fetchAll(PDO::FETCH_COLUMN));
        $migrations = $this->pdo->query('SELECT COUNT(*) FROM schema_migrations');
        self::assertNotFalse($migrations);
        self::assertGreaterThanOrEqual(2, (int) $migrations->fetchColumn());

        // Selbstsperre: Code weg, Lock da, Setup antwortet 404.
        self::assertFileDoesNotExist($this->dir . '/var/setup-code.txt');
        self::assertFileExists($this->dir . '/var/setup.lock');
        self::assertSame(404, $this->request('GET')->status);
        self::assertSame(404, $this->request('POST', ['setup_code' => $code])->status);
    }

    /** @param array<string,string> $body */
    private function request(string $method, array $body = []): Response
    {
        $app = new App(
            dirname(__DIR__, 2),
            setupMode: true,
            configFile: $this->dir . '/config.inc.php',
            dataDir: $this->dir . '/var',
        );

        return $app->handle(new Request(
            method: $method,
            path: '/setup',
            basePath: '',
            body: $body,
            clientIp: '203.0.113.8',
            secure: true,
            host: 'neu.example',
        ));
    }
}
