<?php

declare(strict_types=1);

namespace WatchRss\Tests\Feature;

use PHPUnit\Framework\TestCase;
use WatchRss\App;
use WatchRss\Http\Request;
use WatchRss\Http\Response;
use WatchRss\Setup\ConfigWriter;

/**
 * Web-Setup ohne Datenbank: Code, Sperren, Validierung, Selbstsperre.
 * Config und Datenverzeichnis liegen in einem Temp-Verzeichnis.
 */
final class SetupTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/watchrss-setup-' . bin2hex(random_bytes(4));
        mkdir($this->dir . '/var', 0775, true);
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->dir));
    }

    public function testEveryPageRedirectsToSetup(): void
    {
        $response = $this->request('GET', '/e/irgendwas');

        self::assertSame(302, $response->status);
        self::assertSame('/setup', $response->headers['Location']);
    }

    public function testFirstVisitCreatesCodeFileOnly(): void
    {
        $response = $this->request('GET', '/setup');

        self::assertSame(200, $response->status);
        self::assertFileExists($this->dir . '/var/setup-code.txt');
        self::assertMatchesRegularExpression('/^[A-Z2-9]{4}-[A-Z2-9]{4}-[A-Z2-9]{4}$/', trim((string) file_get_contents($this->dir . '/var/setup-code.txt')));
        self::assertStringNotContainsString($this->code(), $response->body, 'Code darf nie in der Seite stehen');
        self::assertSame('no-store', $response->headers['Cache-Control']);
    }

    public function testCodeIsStableAcrossVisits(): void
    {
        $this->request('GET', '/setup');
        $first = $this->code();
        $this->request('GET', '/setup');

        self::assertSame($first, $this->code());
    }

    public function testWrongCodeIsRejectedAndNothingIsWritten(): void
    {
        $this->request('GET', '/setup');
        $response = $this->request('POST', '/setup', ['setup_code' => 'AAAA-AAAA-AAAA'] + $this->validInput());

        self::assertSame(403, $response->status);
        self::assertFileDoesNotExist($this->dir . '/config.inc.php');
    }

    public function testLocksAfterFiveWrongCodes(): void
    {
        $this->request('GET', '/setup');
        for ($i = 0; $i < 5; $i++) {
            $this->request('POST', '/setup', ['setup_code' => 'falsch']);
        }

        $response = $this->request('POST', '/setup', ['setup_code' => $this->code()] + $this->validInput());

        self::assertSame(429, $response->status);
    }

    public function testCodeIsCaseAndDashInsensitive(): void
    {
        $this->request('GET', '/setup');
        $sloppy = strtolower(str_replace('-', ' ', $this->code()));

        // Richtiger Code -> weiter zur Validierung (hier: kaputter Host).
        $response = $this->request('POST', '/setup', ['setup_code' => $sloppy] + $this->validInput(['db_host' => 'a;unix_socket=/x']));

        self::assertSame(422, $response->status);
    }

    public function testDsnInjectionInHostIsRejected(): void
    {
        $this->request('GET', '/setup');
        $response = $this->request('POST', '/setup', ['setup_code' => $this->code()] + $this->validInput(['db_host' => 'localhost;unix_socket=/tmp/x']));

        self::assertSame(422, $response->status);
        self::assertStringContainsString('Datenbank-Host', $response->body);
    }

    public function testBaseUrlMustBeAUrl(): void
    {
        $this->request('GET', '/setup');
        $response = $this->request('POST', '/setup', ['setup_code' => $this->code()] + $this->validInput(['base_url' => 'javascript:alert(1)']));

        self::assertSame(422, $response->status);
    }

    public function testUnreachableDatabaseIsReportedAndNothingIsWritten(): void
    {
        $this->request('GET', '/setup');
        $response = $this->request('POST', '/setup', ['setup_code' => $this->code()] + $this->validInput(['db_host' => '127.0.0.1', 'db_port' => '1']));

        self::assertSame(422, $response->status);
        self::assertStringContainsString('Keine Verbindung zur Datenbank', $response->body);
        self::assertFileDoesNotExist($this->dir . '/config.inc.php');
        self::assertStringNotContainsString('geheimes-pw', $response->body, 'Passwort wird nicht zurückgegeben');
    }

    public function testSetupIsClosedOnceLockExists(): void
    {
        file_put_contents($this->dir . '/var/setup.lock', 'x');

        self::assertSame(404, $this->request('GET', '/setup')->status);
        self::assertFileDoesNotExist($this->dir . '/var/setup-code.txt');
    }

    public function testConfigWriterEscapesEverything(): void
    {
        $php = ConfigWriter::render(['DB_PASS' => "a'b\\c\");system('x');//"]);

        self::assertStringContainsString("define('DB_PASS', 'a\\'b\\\\c\");system(\\'x\\');//');", $php);

        $file = $this->dir . '/check.php';
        file_put_contents($file, $php);
        exec(escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg($file), $out, $exit);
        self::assertSame(0, $exit, implode("\n", $out));
    }

    /** @param array<string,mixed> $body */
    private function request(string $method, string $path, array $body = []): Response
    {
        $app = new App(
            dirname(__DIR__, 2),
            setupMode: true,
            configFile: $this->dir . '/config.inc.php',
            dataDir: $this->dir . '/var',
        );

        return $app->handle(new Request(
            method: $method,
            path: $path,
            basePath: '',
            body: $body,
            clientIp: '203.0.113.7',
            secure: true,
            host: 'neu.example',
        ));
    }

    private function code(): string
    {
        return trim((string) file_get_contents($this->dir . '/var/setup-code.txt'));
    }

    /**
     * @param  array<string,string> $override
     * @return array<string,string>
     */
    private function validInput(array $override = []): array
    {
        return $override + [
            'base_url' => 'https://neu.example',
            'db_host'  => 'localhost',
            'db_port'  => '3306',
            'db_name'  => 'watchrss',
            'db_user'  => 'watchrss',
            'db_pass'  => 'geheimes-pw',
        ];
    }
}
