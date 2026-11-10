<?php

declare(strict_types=1);

namespace WatchRss\Service\Tracking;

use WatchRss\Http\Request;
use WatchRss\Http\Response;

/**
 * Ein einzelner, bereits anonymisierter Seitenaufruf.
 *
 * Die ganze Logik "was wird überhaupt gemeldet und wie sieht es anonymisiert
 * aus" steckt in der Fabrikmethode unten - nicht verteilt über Controller.
 *
 * Grundsätze:
 *
 *  - Tokens verlassen den Server nie. Aus /e/<edit_token> wird /e/, aus
 *    /f/<feed_token> wird /f/. Interessant ist "der Editor wurde geöffnet",
 *    nicht welcher Feed.
 *  - Interne Referrer werden verworfen. Klickt man im Editor auf "Neuen Feed
 *    anlegen", stünde der Edit-Token sonst im Referrer der Folgeseite -
 *    und damit in der Statistik.
 *  - Die Besucherkennung ist ein gesalzener Tageshash aus IP und User-Agent.
 *    Er trennt Besucher sauber, ist aber nicht zurückrechenbar und wechselt
 *    jede Nacht. Die IP selbst wird nicht übermittelt.
 *  - Do Not Track / Global Privacy Control werden respektiert.
 */
final class TrackingEvent
{
    public function __construct(
        /** Absolute, bereinigte Adresse (ohne Tokens). */
        public readonly string $url,
        /** Name der Aktion, "/" bildet in Matomo eine Hierarchie. */
        public readonly string $actionName,
        /** 16 Hex-Zeichen, wie von der Matomo-API verlangt. */
        public readonly string $visitorId,
        public readonly ?string $userAgent = null,
        public readonly ?string $language = null,
        /** Nur externe Referrer. */
        public readonly ?string $referrer = null,
    ) {
    }

    /**
     * Baut aus Anfrage und Antwort ein meldbares Ereignis - oder null, wenn
     * dieser Aufruf nichts in der Statistik verloren hat (API, Cron, Assets,
     * oder der Besucher hat Do Not Track gesetzt).
     */
    public static function fromExchange(Request $request, Response $response, string $salt): ?self
    {
        if (self::isLocal($request) || self::doNotTrack($request)) {
            return null;
        }

        $section = self::section($request->path);
        if ($section === null) {
            return null;
        }

        [$path, $label] = $section;

        return new self(
            url: rtrim($request->baseUrl(), '/') . $path,
            actionName: self::actionName($label, $path, $response->status),
            visitorId: self::visitorId($request, $salt),
            userAgent: $request->header('user-agent'),
            language: $request->header('accept-language'),
            referrer: self::externalReferrer($request),
        );
    }

    /**
     * Ordnet den Pfad einer der drei beobachteten Ecken zu und wirft dabei
     * den Token weg.
     *
     * @return array{0:string,1:string}|null [bereinigter Pfad, Bezeichnung]
     */
    private static function section(string $path): ?array
    {
        return match (true) {
            $path === '/'                   => ['/', 'Startseite'],
            str_starts_with($path, '/e/')   => ['/e/', 'Editor'],
            str_starts_with($path, '/f/')   => ['/f/', 'RSS-Feed'],
            // /api/, /cron/, Assets und alles andere bleiben außen vor.
            default                         => null,
        };
    }

    /**
     * Beim Feed ist der Status die eigentliche Information: 304 heißt, dass
     * ein Reader sich brav an den Cache hält, 429 heißt, dass er zu oft
     * fragt. Deshalb steht er dort immer im Namen.
     */
    private static function actionName(string $label, string $path, int $status): string
    {
        if ($path === '/f/') {
            return $label . ' / ' . match ($status) {
                200     => 'Ausgeliefert',
                304     => 'Unverändert (304)',
                429     => 'Gebremst (429)',
                404     => 'Unbekannt (404)',
                default => 'HTTP ' . $status,
            };
        }

        return $status >= 400 ? $label . ' / Fehler (' . $status . ')' : $label;
    }

    /**
     * Gesalzener Tageshash. Ohne Kennung würde Matomo alle Aufrufe zu einem
     * einzigen Besucher zusammenfassen (serverseitiges Tracking kommt immer
     * von derselben Server-IP), mit der Klartext-IP wäre es personenbezogen.
     */
    private static function visitorId(Request $request, string $salt): string
    {
        return substr(hash('sha256', implode('|', [
            $salt,
            $request->clientIp,
            $request->header('user-agent') ?? '',
            gmdate('Y-m-d'),
        ])), 0, 16);
    }

    /**
     * Referrer nur übernehmen, wenn er von außerhalb kommt. Interne Referrer
     * enthielten sonst Tokens und sind ohnehin uninteressant.
     */
    private static function externalReferrer(Request $request): ?string
    {
        $referrer = $request->header('referer');
        if ($referrer === null || $referrer === '') {
            return null;
        }

        $host = parse_url($referrer, PHP_URL_HOST);
        if (!is_string($host) || strcasecmp($host, $request->host) === 0) {
            return null;
        }

        return mb_substr($referrer, 0, 1000);
    }

    /**
     * Entwicklungsumgebungen melden nichts.
     *
     * Sonst landen beim lokalen Testen Aufrufe von "127.0.0.1" in der
     * Statistik der echten Seite - und weil Frontend und Live-Instanz sich
     * dieselbe Konfiguration teilen, passiert das sonst bei jedem Testlauf.
     */
    private static function isLocal(Request $request): bool
    {
        // Der eingebaute PHP-Entwicklungsserver ist nie Produktion.
        if (PHP_SAPI === 'cli-server') {
            return true;
        }

        $host = strtolower((string) parse_url('http://' . $request->host, PHP_URL_HOST));

        return $host === 'localhost'
            || $host === '127.0.0.1'
            || $host === '::1'
            || str_ends_with($host, '.local')
            || str_ends_with($host, '.test')
            || str_ends_with($host, '.localhost');
    }

    /** Respektiert Do Not Track und Global Privacy Control. */
    private static function doNotTrack(Request $request): bool
    {
        if (defined('TRACKING_HONOR_DNT') && !TRACKING_HONOR_DNT) {
            return false;
        }

        return $request->header('dnt') === '1' || $request->header('sec-gpc') === '1';
    }
}
