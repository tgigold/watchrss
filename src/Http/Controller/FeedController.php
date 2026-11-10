<?php

declare(strict_types=1);

namespace WatchRss\Http\Controller;

use WatchRss\App;
use WatchRss\Http\Request;
use WatchRss\Http\Response;
use WatchRss\Model\Feed;

/**
 * Liefert den generierten Kombi-Feed aus - inklusive der kompletten
 * Cache-Mechanik.
 *
 * Ablauf einer Anfrage:
 *   1. Feed + Kanäle laden, Fingerabdruck des Datenbestands bilden
 *   2. ETag/Last-Modified vergleichen -> ggf. 304 (kostet fast nichts)
 *   3. Abruf-Bremse prüfen -> ggf. 429 mit Retry-After
 *   4. XML aus dem Datei-Cache nehmen oder neu bauen
 */
final class FeedController
{
    public function __construct(private readonly App $app)
    {
    }

    /**
     * @param array<string,string> $params
     */
    public function serve(Request $request, array $params): Response
    {
        $token = $params['token'] ?? '';
        $feed  = $this->app->feeds()->findByFeedToken($token);

        if ($feed === null) {
            // Wer reihenweise Feed-Adressen durchprobiert, wird ausgebremst -
            // ein normaler Tippfehler merkt davon nichts.
            $retryAfter = $this->app->throttle()->unknownTokenRetryAfter($request->clientIp);
            if ($retryAfter !== null) {
                return new Response("Zu viele Fehlversuche.\n", 429, [
                    'Content-Type' => 'text/plain; charset=utf-8',
                    'Retry-After'  => (string) $retryAfter,
                ]);
            }
            $this->app->throttle()->recordUnknownToken($request->clientIp);

            return new Response("Feed nicht gefunden.\n", 404, ['Content-Type' => 'text/plain; charset=utf-8']);
        }

        $version      = $this->app->videos()->versionForChannels($feed->channelRefs());
        $etag         = $this->etag($feed, $version);
        $lastModified = $this->lastModified($feed, $version);
        $ttlSeconds   = $feed->ttlSeconds();

        $cacheHeaders = [
            'ETag'          => $etag,
            'Last-Modified' => $lastModified,
            // max-age: so lange gilt die Antwort als frisch. stale-while-revalidate
            // erlaubt Proxies, während der Aktualisierung noch kurz die alte
            // Fassung auszuliefern, statt alle Clients gleichzeitig durchzulassen.
            'Cache-Control' => sprintf('public, max-age=%d, stale-while-revalidate=%d', $ttlSeconds, $ttlSeconds),
            'Expires'       => gmdate('D, d M Y H:i:s', time() + $ttlSeconds) . ' GMT',
            'X-Robots-Tag'  => 'noindex, nofollow',
            'Vary'          => 'Accept-Encoding',
        ];

        // --- 2. Bedingte Anfrage: unverändert? -----------------------------
        if ($this->isNotModified($request, $etag, $lastModified)) {
            return Response::notModified($cacheHeaders);
        }

        // --- 3. Abruf-Bremse ------------------------------------------------
        $retryAfter = $this->app->throttle()->retryAfter($token, $request->clientIp, $ttlSeconds, $etag);
        if ($retryAfter !== null) {
            return new Response(
                "Zu häufige Abrufe - der Feed hat sich seitdem nicht geändert.\n"
                . "Er aktualisiert sich alle {$feed->ttlMinutes} Minuten.\n",
                429,
                $cacheHeaders + [
                    'Content-Type' => 'text/plain; charset=utf-8',
                    'Retry-After'  => (string) $retryAfter,
                ],
            );
        }

        // --- 4. XML aus dem Cache oder frisch bauen -------------------------
        $cacheKey = 'feedxml:' . $token . ':' . $etag;
        $xml      = $this->app->cache()->get($cacheKey);

        if ($xml === null) {
            $videos = $this->app->videos()->forFeed($feed);
            $xml    = $this->app->feedBuilder()->build(
                $feed,
                $videos,
                $request->baseUrl() . '/f/' . $token . '.xml',
                $request->baseUrl(),
            );

            // TTL großzügig: der Schlüssel enthält den ETag, veraltete
            // Einträge können also gar nicht ausgeliefert werden.
            $this->app->cache()->set($cacheKey, $xml, max($ttlSeconds, 86400));

            if ($feed->id !== null) {
                $this->app->feeds()->markBuilt($feed->id);
            }
        }

        if ($feed->id !== null) {
            $this->app->feeds()->markServed($feed->id);
        }
        $this->app->throttle()->recordFullResponse($token, $request->clientIp, $ttlSeconds, $etag);

        $headers = $cacheHeaders + ['Content-Length' => (string) strlen($xml)];

        // HEAD: gleiche Header, kein Body.
        return Response::xml($request->method === 'HEAD' ? '' : $xml, 200, $headers);
    }

    /**
     * ETag = Feed-Einstellungen + Fingerabdruck der Videodaten.
     * Aendert sich nichts davon, ändert sich der Feed nicht.
     */
    private function etag(Feed $feed, string $version): string
    {
        $material = implode('|', [
            $feed->feedToken,
            $feed->updatedAt ?? '',
            $feed->maxItems,
            $feed->ttlMinutes,
            (int) $feed->prefixTitles,
            $feed->title,
            $version,
        ]);

        return '"' . substr(hash('sha256', $material), 0, 32) . '"';
    }

    private function lastModified(Feed $feed, string $version): string
    {
        // versionForChannels() liefert "anzahl|neuestes|letzter_import|letztes_update"
        $parts     = explode('|', $version);
        $timestamps = array_filter([
            $parts[1] ?? '',
            $parts[2] ?? '',
            $feed->updatedAt ?? '',
        ]);

        $newest = $timestamps !== [] ? max($timestamps) : gmdate('Y-m-d H:i:s');

        return gmdate('D, d M Y H:i:s', strtotime($newest . ' UTC') ?: time()) . ' GMT';
    }

    /**
     * Prüft If-None-Match und If-Modified-Since.
     */
    private function isNotModified(Request $request, string $etag, string $lastModified): bool
    {
        $ifNoneMatch = $request->header('if-none-match');
        if ($ifNoneMatch !== null) {
            foreach (explode(',', $ifNoneMatch) as $candidate) {
                // W/"..." (weak) wie "..." behandeln
                $candidate = trim(ltrim(trim($candidate), 'W/'));
                if ($candidate === $etag || $candidate === '*') {
                    return true;
                }
            }
        }

        $ifModifiedSince = $request->header('if-modified-since');
        if ($ifModifiedSince !== null) {
            $since = strtotime($ifModifiedSince);
            $last  = strtotime($lastModified);
            if ($since !== false && $last !== false && $last <= $since) {
                return true;
            }
        }

        return false;
    }
}
