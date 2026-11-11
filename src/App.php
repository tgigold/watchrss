<?php

declare(strict_types=1);

namespace WatchRss;

use WatchRss\Http\Controller\ApiController;
use WatchRss\Http\Controller\CronController;
use WatchRss\Http\Controller\FeedController;
use WatchRss\Http\Controller\PageController;
use WatchRss\Http\Request;
use WatchRss\Http\Response;
use WatchRss\Http\Router;
use WatchRss\Repository\ChannelRepository;
use WatchRss\Repository\FeedRepository;
use WatchRss\Repository\VideoRepository;
use WatchRss\Service\ChannelResolver;
use WatchRss\Service\FeedBuilder;
use WatchRss\Service\FeedService;
use WatchRss\Service\FeedThrottle;
use WatchRss\Service\FetchRunner;
use WatchRss\Service\InputParser;
use WatchRss\Service\Tracking\MatomoTracker;
use WatchRss\Service\Tracking\TrackerInterface;
use WatchRss\Service\Tracking\TrackingEvent;
use WatchRss\Service\YouTubeFeedFetcher;
use WatchRss\Support\Database;
use WatchRss\Support\FileCache;
use WatchRss\Support\HttpClient;
use WatchRss\View\Renderer;

/**
 * Service-Container und Einstiegspunkt.
 *
 * Alle Abhängigkeiten werden hier einmal verdrahtet und lazy erzeugt.
 * Ein späteres Login braucht damit nur eine weitere Methode (z. B. auth())
 * und ein paar Routen - die übrige Anwendung bleibt unangetastet.
 */
final class App
{
    /** @var array<string,object> */
    private array $instances = [];

    public function __construct(public readonly string $rootPath)
    {
    }

    /* ---------------------------------------------------------------------
     * Infrastruktur
     * ------------------------------------------------------------------ */

    public function database(): Database
    {
        return $this->singleton(Database::class, static fn (): Database => Database::fromConfig());
    }

    public function cache(): FileCache
    {
        return $this->singleton(FileCache::class, static fn (): FileCache => new FileCache((string) CACHE_DIR));
    }

    public function httpClient(): HttpClient
    {
        return $this->singleton(HttpClient::class, static fn (): HttpClient => HttpClient::fromConfig());
    }

    public function renderer(): Renderer
    {
        return $this->singleton(Renderer::class, fn (): Renderer => new Renderer($this->rootPath . '/templates'));
    }

    /* ---------------------------------------------------------------------
     * Repositories
     * ------------------------------------------------------------------ */

    public function channels(): ChannelRepository
    {
        return $this->singleton(
            ChannelRepository::class,
            fn (): ChannelRepository => new ChannelRepository($this->database())
        );
    }

    public function videos(): VideoRepository
    {
        return $this->singleton(
            VideoRepository::class,
            fn (): VideoRepository => new VideoRepository($this->database())
        );
    }

    public function feeds(): FeedRepository
    {
        return $this->singleton(FeedRepository::class, fn (): FeedRepository => new FeedRepository($this->database()));
    }

    /* ---------------------------------------------------------------------
     * Services
     * ------------------------------------------------------------------ */

    public function fetcher(): YouTubeFeedFetcher
    {
        return $this->singleton(
            YouTubeFeedFetcher::class,
            fn (): YouTubeFeedFetcher => new YouTubeFeedFetcher($this->httpClient(), $this->channels(), $this->videos())
        );
    }

    public function resolver(): ChannelResolver
    {
        return $this->singleton(
            ChannelResolver::class,
            fn (): ChannelResolver => new ChannelResolver(
                $this->httpClient(),
                $this->channels(),
                $this->fetcher(),
                (int) RESOLVE_BUDGET_SECONDS,
            )
        );
    }

    public function feedService(): FeedService
    {
        return $this->singleton(FeedService::class, fn (): FeedService => new FeedService(
            $this->feeds(),
            $this->channels(),
            $this->videos(),
            $this->resolver(),
            new InputParser(),
            $this->fetcher(),
        ));
    }

    public function feedBuilder(): FeedBuilder
    {
        return $this->singleton(FeedBuilder::class, static fn (): FeedBuilder => new FeedBuilder());
    }

    /** Der Abruflauf - benutzt von bin/fetch.php und vom Web-Trigger. */
    public function fetchRunner(): FetchRunner
    {
        return $this->singleton(FetchRunner::class, fn (): FetchRunner => new FetchRunner(
            $this->channels(),
            $this->videos(),
            $this->fetcher(),
            $this->cache(),
            rtrim((string) CACHE_DIR, '/') . '/fetch.lock',
        ));
    }

    public function throttle(): FeedThrottle
    {
        return $this->singleton(FeedThrottle::class, fn (): FeedThrottle => FeedThrottle::fromConfig($this->cache()));
    }

    public function tracker(): TrackerInterface
    {
        return $this->singleton(TrackerInterface::class, static fn (): TrackerInterface => MatomoTracker::fromConfig());
    }

    /**
     * Meldet einen abgeschlossenen Aufruf an die Statistik.
     *
     * Wird bewusst erst NACH dem Senden der Antwort aufgerufen (siehe
     * www/index.php): Der Besucher wartet nie auf Matomo. Was gemeldet wird
     * und wie es anonymisiert ist, entscheidet TrackingEvent.
     */
    public function track(Request $request, Response $response): void
    {
        try {
            $event = TrackingEvent::fromExchange($request, $response, $this->trackingSalt());
            if ($event !== null) {
                $this->tracker()->track($event);
            }
        } catch (\Throwable $e) {
            // Statistik darf nie etwas kaputtmachen.
            error_log('[watchrss] Tracking fehlgeschlagen: ' . $e->getMessage());
        }
    }

    /**
     * Salz für die Besucherkennung. Fehlt es, wird ersatzweise aus den
     * DB-Zugangsdaten eines abgeleitet - immer noch geheim und stabil,
     * aber ein eigener Wert in der config.inc.php ist sauberer.
     */
    private function trackingSalt(): string
    {
        if (defined('TRACKING_SALT') && TRACKING_SALT !== '') {
            return (string) TRACKING_SALT;
        }

        return hash('sha256', DB_NAME . '|' . DB_PASS . '|watchrss-tracking');
    }

    /* ---------------------------------------------------------------------
     * Routing
     * ------------------------------------------------------------------ */

    /**
     * Token-Muster für Routen (siehe Support\Token).
     * Zwei Muster, weil öffentlicher und geheimer Token unterschiedlich
     * lang sind - ein kurzer Feed-Token passt damit gar nicht erst auf
     * eine Editor-Route.
     */
    private const EDIT_TOKEN = '(?P<token>[A-Za-z0-9]{16,64})';
    private const FEED_TOKEN = '(?P<token>[A-Za-z0-9]{6,32})';

    public function router(): Router
    {
        return $this->singleton(Router::class, function (): Router {
            $router = new Router();
            $page   = new PageController($this);
            $feed   = new FeedController($this);
            $api    = new ApiController($this);
            $cron   = new CronController($this);

            // Oberfläche
            $router->get('#^/$#', $page->home(...));
            $router->get('#^/e/' . self::EDIT_TOKEN . '/?$#', $page->edit(...));

            // Generierter Feed - mit und ohne .xml-Endung
            $router->get('#^/f/' . self::FEED_TOKEN . '(?:\.xml)?$#', $feed->serve(...));

            // Web-Trigger für den Abruf (Ersatz für einen Konsolen-Cron)
            $router->get('#^/cron(?:/(?P<token>[A-Za-z0-9._-]{8,128}))?$#', $cron->run(...));

            // JSON-API
            $router->post('#^/api/resolve$#', $api->resolve(...));
            $router->post('#^/api/feeds$#', $api->create(...));
            $router->post('#^/api/feeds/' . self::EDIT_TOKEN . '$#', $api->update(...));
            $router->get('#^/api/feeds/' . self::EDIT_TOKEN . '/preview$#', $api->preview(...));
            $router->post('#^/api/feeds/' . self::EDIT_TOKEN . '/rotate$#', $api->rotate(...));

            return $router;
        });
    }

    /**
     * Content Security Policy: nur eigene Skripte und Styles, Bilder
     * zusätzlich von den YouTube-CDNs (Vorschaubilder, Kanal-Avatare).
     */
    private const CSP = "default-src 'self'; script-src 'self'; style-src 'self'; "
        . "img-src 'self' data: https://*.ytimg.com https://*.ggpht.com https://*.googleusercontent.com; "
        . "connect-src 'self'; font-src 'self'; object-src 'none'; base-uri 'self'; "
        . "form-action 'self'; frame-ancestors 'none'";

    /**
     * Verarbeitet einen Request und liefert die Antwort.
     */
    public function handle(Request $request): Response
    {
        $response = $this->httpsRedirect($request) ?? $this->dispatch($request);

        return $response->withDefaultHeaders($this->securityHeaders($request));
    }

    /**
     * Unverschlüsselte Aufrufe auf HTTPS umleiten - immer auf die Domain
     * aus APP_BASE_URL, nie auf den Host-Header. Greift nur, wenn
     * APP_BASE_URL eine https-Adresse ist (lokal also nie).
     */
    private function httpsRedirect(Request $request): ?Response
    {
        $base = $request->configuredBaseUrl;
        if ($request->secure || !str_starts_with($base, 'https://')) {
            return null;
        }

        $target = $base . $request->path;
        if ($request->query !== []) {
            $target .= '?' . http_build_query($request->query);
        }

        // 308 erhält Methode und Body (POST), 301 ist für GET am verbreitetsten.
        $status = in_array($request->method, ['GET', 'HEAD'], true) ? 301 : 308;

        return Response::redirect($target, $status);
    }

    /**
     * @return array<string,string>
     */
    private function securityHeaders(Request $request): array
    {
        $headers = [
            'Content-Security-Policy' => self::CSP,
            'X-Frame-Options'         => 'DENY',
            'X-Content-Type-Options'  => 'nosniff',
            // Fremde Server sehen nur die Domain, nie den Pfad (der enthält
            // im Editor den geheimen Token).
            'Referrer-Policy'         => 'strict-origin-when-cross-origin',
        ];

        if ($request->secure) {
            $headers['Strict-Transport-Security'] = 'max-age=31536000';
        }

        return $headers;
    }

    private function dispatch(Request $request): Response
    {
        try {
            $this->renderer()->share([
                'appName' => (string) APP_NAME,
                'baseUrl' => $request->baseUrl(),
            ]);

            return $this->router()->dispatch($request, function (Request $request): Response {
                if (str_starts_with($request->path, '/api/')) {
                    return Response::json(['error' => 'Nicht gefunden.'], 404);
                }

                return Response::html($this->renderer()->page('error', [
                    'title'   => 'Seite nicht gefunden',
                    'message' => 'Diese Adresse gibt es hier nicht.',
                    'noindex' => true,
                ]), 404);
            });
        } catch (\Throwable $e) {
            error_log('[watchrss] ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());

            $message = APP_DEBUG
                ? $e->getMessage() . ' (' . $e->getFile() . ':' . $e->getLine() . ')'
                : 'Da ist serverseitig etwas schiefgegangen. Bitte später erneut versuchen.';

            if ($request->wantsJson() || str_starts_with($request->path, '/api/')) {
                return Response::json(['error' => $message], 500);
            }

            return Response::html($this->renderer()->page('error', [
                'title'   => 'Fehler',
                'message' => $message,
                'noindex' => true,
            ]), 500);
        }
    }

    /**
     * @template T of object
     * @param  class-string<T>|string $key
     * @param  callable():T           $factory
     * @return T
     */
    private function singleton(string $key, callable $factory): object
    {
        /** @var T */
        return $this->instances[$key] ??= $factory();
    }
}
