<?php

declare(strict_types=1);

namespace WatchRss\Http\Controller;

use Throwable;
use WatchRss\App;
use WatchRss\Exception\ValidationException;
use WatchRss\Http\Request;
use WatchRss\Http\Response;
use WatchRss\Setup\ConfigWriter;
use WatchRss\Setup\Installer;
use WatchRss\Setup\SetupCode;
use WatchRss\Support\Database;
use WatchRss\Support\FileCache;
use WatchRss\Support\Input;

/**
 * Geführtes Setup für Webhosting ohne Kommandozeile.
 *
 * Aktiv nur, solange es keine config.inc.php gibt. Dann landet jede Seite
 * hier. Ablauf:
 *  1. Beim ersten Aufruf entsteht var/setup-code.txt (nur per FTP lesbar).
 *  2. Formular: Setup-Code, Basis-URL, Datenbank-Zugang.
 *  3. Umgebung prüfen, DB-Verbindung testen, Tabellen + Migrationen anlegen,
 *     Geheimnisse erzeugen, config.inc.php schreiben.
 *  4. Setup sperrt sich: Config existiert, var/setup.lock liegt, der Code
 *     ist gelöscht. Erneut einrichten geht nur, wenn man beides per FTP
 *     entfernt.
 *
 * Schutz: Der Setup-Code kommt nur aus dem POST-Body. Nach 5 Fehlversuchen
 * je IP oder 25 insgesamt ist das Setup 15 Minuten gesperrt.
 */
final class SetupController
{
    private const MAX_FAILURES_PER_IP = 5;
    private const MAX_FAILURES_TOTAL  = 25;
    private const LOCK_SECONDS        = 900;

    public function __construct(private readonly App $app)
    {
    }

    public function handle(Request $request): Response
    {
        $this->app->renderer()->share([
            'appName' => (string) APP_NAME,
            // Ohne Config gibt es keine APP_BASE_URL: relative Links.
            'baseUrl' => $request->basePath,
        ]);

        if (is_file($this->app->configFile) || is_file($this->lockFile())) {
            return $this->closed();
        }

        if (!in_array($request->path, ['/setup', '/setup/'], true)) {
            return Response::redirect($request->basePath . '/setup');
        }

        return match ($request->method) {
            'GET', 'HEAD' => $this->show($request),
            'POST'        => $this->submit($request),
            default       => new Response('Method Not Allowed', 405, ['Content-Type' => 'text/plain; charset=utf-8']),
        };
    }

    private function show(Request $request): Response
    {
        try {
            $this->code()->ensure();
        } catch (Throwable $e) {
            return $this->page($request, ['error' => $e->getMessage()], 500);
        }

        return $this->page($request);
    }

    private function submit(Request $request): Response
    {
        $retryAfter = $this->lockedFor($request->clientIp);
        if ($retryAfter !== null) {
            return $this->page($request, ['error' => 'Zu viele Fehlversuche. Bitte in einigen Minuten erneut versuchen.'], 429)
                ->withDefaultHeaders(['Retry-After' => (string) $retryAfter]);
        }

        $code = $request->body['setup_code'] ?? '';
        if (!is_string($code) || !$this->code()->verify($code)) {
            $this->recordFailure($request->clientIp);

            return $this->page($request, ['error' => 'Der Setup-Code stimmt nicht.', 'values' => $this->echoValues($request)], 403);
        }

        try {
            $values = $this->validate($request->body);
        } catch (ValidationException $e) {
            return $this->page($request, [
                'error'  => $e->getMessage(),
                'values' => $this->echoValues($request),
            ], 422);
        }

        $checks = $this->environment();
        if (!Installer::allOk($checks)) {
            return $this->page($request, [
                'error'  => 'Die Umgebung erfüllt noch nicht alle Voraussetzungen (siehe Liste).',
                'values' => $this->echoValues($request),
            ], 422);
        }

        $database = new Database($values['DB_HOST'], $values['DB_PORT'], $values['DB_NAME'], $values['DB_USER'], $values['DB_PASS']);

        try {
            $database->pdo();
        } catch (Throwable $e) {
            return $this->page($request, [
                'error'  => 'Keine Verbindung zur Datenbank: ' . $e->getMessage(),
                'values' => $this->echoValues($request),
            ], 422);
        }

        try {
            $result = (new Installer($this->app->rootPath, $this->app->dataDir . '/backups'))->install($database);

            $secrets = [
                'CRON_TOKEN'    => bin2hex(random_bytes(24)),
                'TRACKING_SALT' => bin2hex(random_bytes(24)),
                'ADMIN_TOKEN'   => bin2hex(random_bytes(24)),
            ];
            ConfigWriter::write($this->app->configFile, $values + ['DB_CHARSET' => 'utf8mb4'] + $secrets);

            @file_put_contents($this->lockFile(), gmdate('c') . "\n");
            $this->code()->delete();
        } catch (Throwable $e) {
            error_log('[watchrss] Setup: ' . $e->getMessage());

            return $this->page($request, [
                'error'  => 'Einrichtung fehlgeschlagen: ' . $e->getMessage(),
                'values' => $this->echoValues($request),
            ], 500);
        }

        return $this->page($request, [
            'done'    => true,
            'result'  => $result,
            'siteUrl' => $values['APP_BASE_URL'],
            'secrets' => $secrets,
        ]);
    }

    /**
     * @param  array<string,mixed> $body
     * @return array{APP_BASE_URL:string,DB_HOST:string,DB_PORT:string,DB_NAME:string,DB_USER:string,DB_PASS:string}
     * @throws ValidationException
     */
    private function validate(array $body): array
    {
        $baseUrl = rtrim(trim((string) Input::string($body, 'base_url', '', 255)), '/');
        $parts   = parse_url($baseUrl);
        $host    = is_array($parts) ? (string) ($parts['host'] ?? '') : '';
        $scheme  = is_array($parts) ? strtolower((string) ($parts['scheme'] ?? '')) : '';
        if ($host === '' || !in_array($scheme, ['https', 'http'], true) || isset($parts['query']) || isset($parts['fragment'])) {
            throw ValidationException::field('base_url', 'URL (https://…)', 'ungültig', 'Bitte die öffentliche Adresse angeben, z. B. https://watchrss.de');
        }

        // DSN-Bestandteile: nur Zeichen, die keinen weiteren DSN-Parameter
        // (";key=value") einschleusen können.
        $dbHost = trim((string) Input::string($body, 'db_host', '', 255));
        if (preg_match('/^[A-Za-z0-9.\-]+$/', $dbHost) !== 1) {
            throw ValidationException::field('db_host', 'Hostname', 'ungültig', 'Datenbank-Host: nur Buchstaben, Ziffern, Punkt und Bindestrich.');
        }

        $dbPort = Input::int($body, 'db_port', 3306, 1, 65535);

        $dbName = trim((string) Input::string($body, 'db_name', '', 64));
        $dbUser = trim((string) Input::string($body, 'db_user', '', 64));
        foreach (['db_name' => $dbName, 'db_user' => $dbUser] as $field => $value) {
            if (preg_match('/^[A-Za-z0-9_\-$]+$/', $value) !== 1) {
                throw ValidationException::field($field, 'Name', 'ungültig', 'Datenbankname und Benutzer: nur Buchstaben, Ziffern, _ - und $.');
            }
        }

        return [
            'APP_BASE_URL' => $baseUrl,
            'DB_HOST'      => $dbHost,
            'DB_PORT'      => (string) $dbPort,
            'DB_NAME'      => $dbName,
            'DB_USER'      => $dbUser,
            'DB_PASS'      => (string) Input::string($body, 'db_pass', '', 255),
        ];
    }

    /** @return list<array{label:string,ok:bool}> */
    private function environment(): array
    {
        return Installer::checkEnvironment([dirname($this->app->configFile), $this->app->dataDir]);
    }

    /**
     * Eingaben fürs erneute Anzeigen - nie Passwort oder Setup-Code.
     *
     * @return array<string,string>
     */
    private function echoValues(Request $request): array
    {
        $values = [];
        foreach (['base_url', 'db_host', 'db_port', 'db_name', 'db_user'] as $field) {
            $value = $request->body[$field] ?? '';
            $values[$field] = is_string($value) && mb_check_encoding($value, 'UTF-8') ? mb_substr($value, 0, 255) : '';
        }

        return $values;
    }

    /**
     * @param array<string,mixed> $data
     */
    private function page(Request $request, array $data = [], int $status = 200): Response
    {
        $suggestedBase = ($request->secure ? 'https://' : 'http://') . $request->host . $request->basePath;

        $html = $this->app->renderer()->page('setup', $data + [
            'pageTitle' => 'Einrichtung – watchrss',
            'noindex'   => true,
            'error'     => null,
            'done'      => false,
            'checks'    => $this->environment(),
            'codeFile'  => 'var/' . basename($this->code()->file()),
            // Nur ein Vorschlag fürs Formular - übernommen wird, was der
            // Betreiber bestätigt, nie der Host-Header selbst.
            'values'    => ['base_url' => $suggestedBase, 'db_host' => 'localhost', 'db_port' => '3306', 'db_name' => '', 'db_user' => ''],
        ]);

        return Response::html($html, $status)->withDefaultHeaders([
            'Cache-Control' => 'no-store',
            'X-Robots-Tag'  => 'noindex, nofollow',
        ]);
    }

    private function closed(): Response
    {
        return Response::html($this->app->renderer()->page('error', [
            'title'   => 'Einrichtung abgeschlossen',
            'message' => 'Diese Installation ist bereits eingerichtet.',
            'noindex' => true,
        ]), 404);
    }

    private function code(): SetupCode
    {
        return new SetupCode($this->app->dataDir . '/setup-code.txt');
    }

    private function lockFile(): string
    {
        return $this->app->dataDir . '/setup.lock';
    }

    /* ---------------------------------------------------------------------
     * Sperre nach Fehlversuchen (je IP und insgesamt)
     * ------------------------------------------------------------------ */

    private function cache(): FileCache
    {
        return new FileCache($this->app->dataDir . '/cache');
    }

    private function lockedFor(string $ip): ?int
    {
        foreach ([[$this->ipKey($ip), self::MAX_FAILURES_PER_IP], ['setup-fail:all', self::MAX_FAILURES_TOTAL]] as [$key, $max]) {
            $raw = $this->cache()->get($key);
            if ($raw === null) {
                continue;
            }
            [$count, $since] = array_map('intval', explode('|', $raw) + [1 => '0']);
            $remaining = $since + self::LOCK_SECONDS - time();
            if ($count >= $max && $remaining > 0) {
                return $remaining;
            }
        }

        return null;
    }

    private function recordFailure(string $ip): void
    {
        foreach ([$this->ipKey($ip), 'setup-fail:all'] as $key) {
            $raw   = $this->cache()->get($key);
            $count = $raw === null ? 0 : (int) explode('|', $raw)[0];
            $this->cache()->set($key, ($count + 1) . '|' . time(), self::LOCK_SECONDS);
        }
    }

    private function ipKey(string $ip): string
    {
        return 'setup-fail:' . substr(hash('sha256', $ip), 0, 16);
    }
}
