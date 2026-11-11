<?php

declare(strict_types=1);

namespace WatchRss\Http\Controller;

use WatchRss\App;
use WatchRss\Http\Request;
use WatchRss\Http\Response;
use WatchRss\Service\FetchSummary;

/**
 * Web-Trigger für den Abruflauf.
 *
 * Für Hoster, deren Panel nur URLs als Cron-Ziel akzeptiert und keine
 * Konsolen-Befehle. Ruft exakt dieselbe Logik auf wie bin/fetch.php.
 *
 * Aufruf:
 *   https://watchrss.de/cron/<CRON_TOKEN>
 *   https://watchrss.de/cron?key=<CRON_TOKEN>        (falls Pfade stören)
 *
 * Zusätzliche Parameter:
 *   &limit=25     Kanäle pro Lauf (Standard: FETCH_BATCH_SIZE)
 *   &prune=1      zusätzlich aufräumen (einmal täglich sinnvoll)
 *   &force=1      Abrufintervall ignorieren
 *   &format=json  Ausgabe als JSON statt Text
 *
 * Absicherung:
 *   - Zugriff nur mit dem Token aus der Konfiguration, zeitkonstant verglichen
 *   - falscher Token => 404, der Endpunkt verrät sich also nicht
 *   - Dateisperre: parallele Läufe sind unmöglich
 *   - Zeitbudget (CRON_MAX_SECONDS): der Lauf endet sauber, bevor der Server
 *     die Verbindung kappt; der Rest kommt beim nächsten Aufruf dran
 */
final class CronController
{
    public function __construct(private readonly App $app)
    {
    }

    /**
     * @param array<string,string> $params
     */
    public function run(Request $request, array $params = []): Response
    {
        $configured = defined('CRON_TOKEN') ? trim((string) CRON_TOKEN) : '';
        $wantsJson  = ($request->query['format'] ?? '') === 'json';

        // Nicht eingerichtet: für den Betreiber verständlich, ohne etwas zu verraten.
        if ($configured === '') {
            return $this->respond(
                $wantsJson,
                ['error' => 'Web-Trigger ist nicht konfiguriert.'],
                "watchrss: Web-Trigger ist nicht konfiguriert.\n"
                . "Bitte CRON_TOKEN in config.inc.php setzen.\n",
                503,
            );
        }

        $provided = $params['token'] ?? $request->query['key'] ?? '';
        $provided = is_string($provided) ? $provided : '';
        if (!hash_equals($configured, $provided)) {
            // Bewusst 404 statt 403: ohne gültigen Token existiert der Endpunkt
            // für Fremde schlicht nicht.
            return $this->respond($wantsJson, ['error' => 'Nicht gefunden.'], "Nicht gefunden.\n", 404);
        }

        // Der Lauf soll auch zu Ende gehen, wenn der aufrufende Dienst die
        // Verbindung vorher schließt.
        ignore_user_abort(true);
        $maxSeconds = defined('CRON_MAX_SECONDS') ? max(5, (int) CRON_MAX_SECONDS) : 50;
        @set_time_limit($maxSeconds + 30);

        $summary = $this->app->fetchRunner()->run(
            limit: $this->intParam($request, 'limit', (int) FETCH_BATCH_SIZE, 1, 200),
            force: ($request->query['force'] ?? '') === '1',
            maxSeconds: $maxSeconds,
            prune: ($request->query['prune'] ?? '') === '1',
        );

        // null = es läuft bereits ein Abruf. 409 statt Fehler: das ist ein
        // normaler Zustand, wenn Cron-Intervall und Laufzeit sich überlappen.
        if ($summary === null) {
            return $this->respond(
                $wantsJson,
                ['status' => 'busy'],
                "watchrss: Es läuft bereits ein Abruf - dieser Aufruf wird übersprungen.\n",
                409,
            );
        }

        return $this->respond(
            $wantsJson,
            ['status' => $summary->hasErrors() ? 'partial' : 'ok'] + $summary->toArray(),
            $this->textReport($summary),
            // 500 bei Fehlern, damit Monitoring und Cron-Mails anschlagen.
            $summary->hasErrors() ? 500 : 200,
        );
    }

    private function textReport(FetchSummary $summary): string
    {
        $report = "watchrss – Abruflauf " . gmdate('Y-m-d H:i:s') . " UTC\n";
        $report .= str_repeat('-', 60) . "\n";

        foreach ($summary->lines as $line) {
            $report .= '  ' . $line . "\n";
        }
        if ($summary->lines === []) {
            $report .= "  (kein Kanal fällig)\n";
        }

        if ($summary->prunedVideos > 0 || $summary->removedChannels > 0 || $summary->removedCacheFiles > 0) {
            $report .= sprintf(
                "\nAufgeräumt: %d Videos, %d verwaiste Kanäle, %d Cache-Dateien\n",
                $summary->prunedVideos,
                $summary->removedChannels,
                $summary->removedCacheFiles,
            );
        }

        return $report . "\n" . $summary->headline() . "\n";
    }

    /**
     * @param array<string,mixed> $json
     */
    private function respond(bool $wantsJson, array $json, string $text, int $status): Response
    {
        // Ein Cron-Ergebnis darf niemals aus einem Cache kommen.
        $headers = [
            'Cache-Control' => 'no-store, no-cache, must-revalidate',
            'X-Robots-Tag'  => 'noindex, nofollow',
        ];

        return $wantsJson
            ? Response::json($json, $status, $headers)
            : new Response($text, $status, $headers + ['Content-Type' => 'text/plain; charset=utf-8']);
    }

    private function intParam(Request $request, string $name, int $default, int $min, int $max): int
    {
        $value = $request->query[$name] ?? null;
        if ($value === null || !is_numeric($value)) {
            return $default;
        }

        return max($min, min($max, (int) $value));
    }
}
