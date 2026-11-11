<?php

declare(strict_types=1);

namespace WatchRss\Http\Controller;

use Throwable;
use WatchRss\App;
use WatchRss\Exception\ValidationException;
use WatchRss\Http\Request;
use WatchRss\Http\Response;
use WatchRss\Migration\MigrationFailed;
use WatchRss\Support\Input;

/**
 * Wartungsseite /admin: Migrationsstatus anzeigen und Migrationen ausführen.
 *
 * Absicherung (die App hat bewusst kein Login):
 *  - ADMIN_TOKEN aus config.inc.php; leer = Seite existiert nicht (404)
 *  - der Token kommt nur aus dem POST-Body, nie aus der URL - er landet
 *    damit weder in Logs noch im Browserverlauf
 *  - nach ADMIN_MAX_FAILURES Fehlversuchen ist die Seite für diese IP
 *    ADMIN_LOCK_MINUTES gesperrt
 *  - Antworten sind no-store und noindex
 */
final class AdminController
{
    public function __construct(private readonly App $app)
    {
    }

    public function show(Request $request): Response
    {
        if (!$this->enabled()) {
            return $this->notFound();
        }

        return $this->page(['authorized' => false]);
    }

    public function submit(Request $request): Response
    {
        if (!$this->enabled()) {
            return $this->notFound();
        }

        $retryAfter = $this->lockedFor($request->clientIp);
        if ($retryAfter !== null) {
            return $this->page(['authorized' => false, 'error' => 'Zu viele Fehlversuche. Bitte später erneut versuchen.'], 429)
                ->withDefaultHeaders(['Retry-After' => (string) $retryAfter]);
        }

        try {
            $token  = (string) Input::string($request->body, 'token', '', 200);
            $action = (string) Input::string($request->body, 'action', 'status', 20);
        } catch (ValidationException) {
            $token  = '';
            $action = 'status';
        }

        if (!hash_equals((string) ADMIN_TOKEN, $token)) {
            $this->recordFailure($request->clientIp);

            return $this->page(['authorized' => false, 'error' => 'Token ist falsch.'], 403);
        }

        $this->clearFailures($request->clientIp);

        $data = ['authorized' => true, 'token' => $token];

        try {
            if ($action === 'migrate') {
                $data['result'] = $this->app->migrator()->migrate();
            }
            $data['migrations'] = $this->app->migrator()->status();
        } catch (MigrationFailed $e) {
            error_log('[watchrss] ' . $e->getMessage());
            $data['error']   = $e->getMessage();
            $data['result']  = ['backup' => $e->backupFile, 'applied' => $e->applied];
            $data['migrations'] = $this->safeStatus();
        } catch (Throwable $e) {
            error_log('[watchrss] Admin: ' . $e->getMessage());
            $data['error'] = 'Datenbank nicht erreichbar oder Migration nicht ausführbar. Details stehen im Fehler-Log.';
        }

        return $this->page($data);
    }

    private function enabled(): bool
    {
        return defined('ADMIN_TOKEN') && (string) ADMIN_TOKEN !== '';
    }

    /**
     * @return list<array{version:int,name:string,description:string,applied_at:?string}>
     */
    private function safeStatus(): array
    {
        try {
            return $this->app->migrator()->status();
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * @param array<string,mixed> $data
     */
    private function page(array $data, int $status = 200): Response
    {
        $html = $this->app->renderer()->page('admin', $data + [
            'pageTitle'  => 'Wartung – watchrss',
            'noindex'    => true,
            'error'      => null,
            'result'     => null,
            'migrations' => [],
            'token'      => '',
            'rootPath'   => $this->app->rootPath,
        ]);

        return Response::html($html, $status)->withDefaultHeaders([
            'Cache-Control' => 'no-store',
            'X-Robots-Tag'  => 'noindex, nofollow',
        ]);
    }

    private function notFound(): Response
    {
        return Response::html($this->app->renderer()->page('error', [
            'title'   => 'Seite nicht gefunden',
            'message' => 'Diese Adresse gibt es hier nicht.',
            'noindex' => true,
        ]), 404);
    }

    /* ---------------------------------------------------------------------
     * Sperre nach Fehlversuchen
     * ------------------------------------------------------------------ */

    private function lockedFor(string $ip): ?int
    {
        $raw = $this->app->cache()->get($this->failureKey($ip));
        if ($raw === null) {
            return null;
        }

        [$count, $since] = array_map('intval', explode('|', $raw) + [1 => '0']);
        if ($count < (int) ADMIN_MAX_FAILURES) {
            return null;
        }

        $remaining = $since + $this->lockSeconds() - time();

        return $remaining > 0 ? $remaining : null;
    }

    private function recordFailure(string $ip): void
    {
        $key   = $this->failureKey($ip);
        $raw   = $this->app->cache()->get($key);
        $count = $raw === null ? 0 : (int) explode('|', $raw)[0];

        $this->app->cache()->set($key, ($count + 1) . '|' . time(), $this->lockSeconds());
    }

    private function clearFailures(string $ip): void
    {
        $this->app->cache()->delete($this->failureKey($ip));
    }

    private function lockSeconds(): int
    {
        return max(1, (int) ADMIN_LOCK_MINUTES) * 60;
    }

    /** IP nur gehasht im Cache. */
    private function failureKey(string $ip): string
    {
        return 'admin-fail:' . substr(hash('sha256', $ip), 0, 16);
    }
}
