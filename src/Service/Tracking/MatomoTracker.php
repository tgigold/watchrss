<?php

declare(strict_types=1);

namespace WatchRss\Service\Tracking;

use WatchRss\App;
use WatchRss\Support\HttpClient;

/**
 * Serverseitiges Tracking über die Matomo HTTP Tracking API.
 *
 * Kein JavaScript: Das Frontend lädt keinen fremden Code, und vor allem
 * lassen sich damit auch RSS-Abrufe zählen - ein Feed-Reader führt kein JS
 * aus, ein JS-Tracker würde also genau den interessantesten Teil verpassen.
 *
 * Eigener cURL-Aufruf statt des HttpClient: Der ist auf YouTube-Seiten
 * zugeschnitten (Consent-Cookie, HTML-Accept-Header, Redirects). Hier braucht
 * es das Gegenteil - ein kurzer POST, knappes Zeitlimit, Ergebnis egal.
 *
 * Aufgerufen wird das Ganze erst NACH dem Senden der Antwort (siehe
 * public/index.php), der Besucher wartet also nie auf Matomo.
 */
final class MatomoTracker implements TrackerInterface
{
    public function __construct(
        /** Vollständige Adresse des Endpunkts, z. B. https://…/matomo.php */
        private readonly string $endpoint,
        private readonly int $siteId,
        private readonly int $timeoutSeconds = 2,
    ) {
    }

    /**
     * Baut den Tracker aus der Konfiguration - oder einen NullTracker,
     * wenn nichts eingerichtet ist.
     */
    public static function fromConfig(): TrackerInterface
    {
        $url    = defined('MATOMO_URL') ? trim((string) MATOMO_URL) : '';
        $siteId = defined('MATOMO_SITE_ID') ? (int) MATOMO_SITE_ID : 0;
        $on     = !defined('MATOMO_ENABLED') || (bool) MATOMO_ENABLED;

        if (!$on || $url === '' || $siteId <= 0 || !function_exists('curl_init')) {
            return new NullTracker();
        }
        if (!str_starts_with(strtolower($url), 'https://')) {
            error_log('[watchrss] MATOMO_URL muss mit https:// beginnen - Tracking aus.');

            return new NullTracker();
        }

        return new self(
            self::normalizeEndpoint($url),
            $siteId,
            defined('MATOMO_TIMEOUT') ? max(1, (int) MATOMO_TIMEOUT) : 2,
        );
    }

    /**
     * Erlaubt, in der Konfiguration nur "https://analytics.example.de/"
     * anzugeben - der Dateiname wird bei Bedarf ergänzt.
     */
    private static function normalizeEndpoint(string $url): string
    {
        $path = (string) (parse_url($url, PHP_URL_PATH) ?? '');

        if (str_ends_with($path, '.php')) {
            return $url;
        }

        return rtrim($url, '/') . '/matomo.php';
    }

    public function track(TrackingEvent $event): void
    {
        $parameters = [
            'idsite'     => (string) $this->siteId,
            'rec'        => '1',
            'apiv'       => '1',
            'url'        => $event->url,
            'action_name' => $event->actionName,
            // 16 Hex-Zeichen, so verlangt es die API. Siehe TrackingEvent:
            // gesalzener Tageshash, keine IP im Klartext.
            '_id'        => $event->visitorId,
            // Liefert 204 statt eines 1x1-Bildes zurück.
            'send_image' => '0',
            'rand'       => (string) random_int(100000, 999999),
        ];

        if ($event->userAgent !== null && $event->userAgent !== '') {
            // Ohne diesen Parameter würde Matomo den User-Agent des Servers
            // sehen und jeden Aufruf als "PHP/cURL" einsortieren.
            $parameters['ua'] = $event->userAgent;
        }
        if ($event->language !== null && $event->language !== '') {
            $parameters['lang'] = $event->language;
        }
        if ($event->referrer !== null) {
            $parameters['urlref'] = $event->referrer;
        }

        $this->send($parameters);
    }

    /**
     * Feuert den Request ab und ignoriert das Ergebnis. Fehler landen im
     * Error-Log, gehen aber niemals an den Besucher.
     *
     * @param array<string,string> $parameters
     */
    private function send(array $parameters): void
    {
        $handle = curl_init();
        if ($handle === false) {
            return;
        }

        curl_setopt_array($handle, [
            CURLOPT_URL            => $this->endpoint,
            CURLOPT_POST           => true,
            // POST statt GET: Der User-Agent kann lang sein, und in einer
            // Query-Zeile stünde alles zusätzlich im Access-Log von Matomo.
            CURLOPT_POSTFIELDS     => http_build_query($parameters),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => $this->timeoutSeconds,
            CURLOPT_CONNECTTIMEOUT => $this->timeoutSeconds,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            // Ohne NOSIGNAL kann cURL bei kurzen Timeouts Signale benutzen,
            // was in manchen SAPIs Ärger macht.
            CURLOPT_NOSIGNAL       => true,
            CURLOPT_USERAGENT      => 'watchrss/' . App::VERSION . ' (+server-side tracking)',
        ] + HttpClient::httpsOnlyOptions());

        curl_exec($handle);
        $errno = curl_errno($handle);
        curl_close($handle);

        if ($errno !== 0) {
            error_log('[watchrss] Matomo nicht erreichbar (cURL ' . $errno . ')');
        }
    }
}
