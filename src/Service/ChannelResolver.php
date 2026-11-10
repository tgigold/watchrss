<?php

declare(strict_types=1);

namespace WatchRss\Service;

use WatchRss\Exception\ResolveException;
use WatchRss\Model\Channel;
use WatchRss\Repository\ChannelRepository;
use WatchRss\Support\HttpClient;

/**
 * Ermittelt zu einer beliebigen Nutzereingabe die YouTube-Channel-ID.
 *
 * Akzeptiert werden u. a.:
 *   UCYwrS5QvBY_JbSdbINLey6Q          (ID direkt)
 *   @bmw / bmw                        (Handle bzw. Vanity-Name)
 *   youtube.com/bmw                   (Legacy-Vanity-URL)
 *   https://www.youtube.com/@BMW      (Handle-URL)
 *   https://www.youtube.com/c/BMW     (Custom-URL)
 *   https://www.youtube.com/user/BMW  (Legacy-User-URL)
 *   https://www.youtube.com/channel/UC...   (kanonische URL)
 *   https://www.youtube.com/watch?v=...     (über ein Video des Kanals)
 *   https://www.youtube.com/feeds/videos.xml?channel_id=UC...
 *
 * Gegenüber dem urspruenglichen file_get_contents()-Ansatz wurde geändert:
 *  - cURL statt file_get_contents: echte Timeouts, gzip, Redirects, Header
 *  - Consent-Cookie, sonst landet man in der EU auf consent.youtube.com
 *  - das Meta-Tag heißt heute itemprop="identifier" (nicht mehr "channelId"),
 *    deshalb mehrere Muster in sinnvoller Reihenfolge
 *  - Host-Whitelist: verhindert, dass der Dienst als SSRF-Proxy dient
 *  - Ergebnis wird gegen den echten RSS-Feed verifiziert und in der DB
 *    zwischengespeichert, jede weitere Auflösung ist damit gratis
 *  - Fehler werfen eine Exception mit verwertbarer Meldung statt null
 */
final class ChannelResolver
{
    /** Eine Channel-ID ist immer "UC" + 22 Zeichen aus [A-Za-z0-9_-]. */
    public const CHANNEL_ID_PATTERN = '/^UC[A-Za-z0-9_-]{22}$/';

    /** Nur diese Hosts werden abgerufen (SSRF-Schutz). */
    private const ALLOWED_HOSTS = ['youtube.com', 'www.youtube.com', 'm.youtube.com', 'youtu.be', 'www.youtu.be'];

    /**
     * Muster zur Extraktion der Channel-ID aus dem HTML - teuerste Variante
     * zuletzt. Reihenfolge = Verlässlichkeit.
     *
     * @var list<string>
     */
    private const ID_PATTERNS = [
        '~<meta\s+itemprop="(?:channelId|identifier)"\s+content="(UC[A-Za-z0-9_-]{22})"~i',
        '~<link\s+rel="canonical"\s+href="https://www\.youtube\.com/channel/(UC[A-Za-z0-9_-]{22})"~i',
        '~"externalId"\s*:\s*"(UC[A-Za-z0-9_-]{22})"~',
        '~"channelId"\s*:\s*"(UC[A-Za-z0-9_-]{22})"~',
        '~<meta\s+property="og:url"\s+content="https://www\.youtube\.com/channel/(UC[A-Za-z0-9_-]{22})"~i',
        '~youtube\.com/channel/(UC[A-Za-z0-9_-]{22})~',
    ];

    public function __construct(
        private readonly HttpClient $http,
        private readonly ChannelRepository $channels,
        private readonly YouTubeFeedFetcher $feedFetcher,
    ) {
    }

    /**
     * Loest mehrere Eingaben auf und trennt Treffer von Fehlern.
     *
     * @param  list<string> $inputs
     * @return array{channels:list<Channel>,errors:list<array{input:string,message:string}>}
     */
    public function resolveMany(array $inputs): array
    {
        $channels = [];
        $errors   = [];
        $seen     = [];

        foreach ($inputs as $input) {
            try {
                $channel = $this->resolve($input);
                if (isset($seen[$channel->channelId])) {
                    continue; // Duplikat (z. B. Handle und ID derselben Quelle)
                }
                $seen[$channel->channelId] = true;
                $channels[]                = $channel;
            } catch (ResolveException $e) {
                $errors[] = ['input' => $input, 'message' => $e->getMessage()];
            }
        }

        return ['channels' => $channels, 'errors' => $errors];
    }

    /**
     * Loest eine einzelne Eingabe auf und legt den Kanal in der DB ab.
     *
     * @throws ResolveException
     */
    public function resolve(string $input): Channel
    {
        $input = trim($input, " \t\n\r\0\x0B\"'<>");
        if ($input === '') {
            throw new ResolveException('Leere Eingabe.');
        }

        // 1. Channel-ID direkt in der Eingabe (als ID oder in einer URL)?
        $channelId = $this->extractIdFromInput($input);
        if ($channelId !== null) {
            return $this->store($channelId, null, $input);
        }

        // 2. Handle bekannt? Dann ist der Kanal ggf. schon in der Datenbank
        //    und es braucht keinen einzigen HTTP-Request.
        $handle = $this->extractHandle($input);
        if ($handle !== null) {
            $known = $this->channels->findByHandle($handle);
            if ($known !== null) {
                return $known;
            }
        }

        // 3. Kanalseite laden und ID aus dem HTML ziehen.
        $lastError = null;
        foreach ($this->candidateUrls($input) as $url) {
            $response = $this->http->get($url);

            if ($response->error !== null) {
                $lastError = 'Netzwerkfehler: ' . $response->error;
                continue;
            }
            if ($response->status === 404) {
                $lastError = 'YouTube kennt diese Seite nicht (404).';
                continue;
            }
            if (!$response->isOk()) {
                $lastError = 'YouTube antwortete mit HTTP ' . $response->status . '.';
                continue;
            }

            $channelId = $this->extractIdFromHtml($response->body)
                ?? $this->extractIdFromInput($response->effectiveUrl);

            if ($channelId !== null) {
                return $this->store(
                    $channelId,
                    $handle ?? $this->extractHandleFromHtml($response->body),
                    $url,
                    $this->extractAvatarFromHtml($response->body),
                );
            }

            $lastError = 'Auf der Seite steht keine Channel-ID - ist das wirklich ein Kanal?';
        }

        throw new ResolveException(sprintf(
            '"%s" konnte nicht aufgelöst werden. %s',
            mb_substr($input, 0, 80),
            $lastError ?? 'Unbekanntes Format.'
        ));
    }

    /**
     * Speichert bzw. aktualisiert den Kanal und holt dabei den echten
     * Kanalnamen aus dem YouTube-Feed. Schlaegt der Feed-Abruf fehl,
     * existiert der Kanal nicht (oder hat keine oeffentlichen Videos).
     *
     * @throws ResolveException
     */
    private function store(string $channelId, ?string $handle, string $sourceUrl, ?string $avatarUrl = null): Channel
    {
        $existing = $this->channels->findByChannelId($channelId);
        $title    = $existing?->title ?? '';

        if ($title === '') {
            $meta = $this->feedFetcher->fetchMetadata($channelId);
            if ($meta === null) {
                throw new ResolveException(
                    'Zu dieser Channel-ID liefert YouTube keinen Feed (' . $channelId . ').'
                );
            }
            $title = $meta['title'];
        }

        return $this->channels->save(new Channel(
            id: $existing?->id,
            channelId: $channelId,
            title: $title,
            handle: $handle !== null ? ltrim($handle, '@') : $existing?->handle,
            sourceUrl: $sourceUrl,
            avatarUrl: $avatarUrl ?? $existing?->avatarUrl,
        ));
    }

    /**
     * Channel-ID direkt aus der Eingabe/URL lesen.
     */
    private function extractIdFromInput(string $input): ?string
    {
        if (preg_match(self::CHANNEL_ID_PATTERN, $input) === 1) {
            return $input;
        }

        if (preg_match('~(?:/channel/|channel_id=)(UC[A-Za-z0-9_-]{22})~', $input, $m) === 1) {
            return $m[1];
        }

        return null;
    }

    /**
     * @return string|null Handle ohne "@"
     */
    private function extractHandle(string $input): ?string
    {
        if (preg_match('~^@([A-Za-z0-9._-]{3,60})$~', $input, $m) === 1) {
            return $m[1];
        }
        if (preg_match('~youtube\.com/@([A-Za-z0-9._-]{3,60})~i', $input, $m) === 1) {
            return $m[1];
        }

        return null;
    }

    private function extractIdFromHtml(string $html): ?string
    {
        foreach (self::ID_PATTERNS as $pattern) {
            if (preg_match($pattern, $html, $m) === 1) {
                return $m[1];
            }
        }

        return null;
    }

    private function extractHandleFromHtml(string $html): ?string
    {
        if (preg_match('~"vanityChannelUrl"\s*:\s*"https?://(?:www\.)?youtube\.com/@([A-Za-z0-9._-]+)"~', $html, $m) === 1) {
            return $m[1];
        }
        if (preg_match('~"canonicalBaseUrl"\s*:\s*"/@([A-Za-z0-9._-]+)"~', $html, $m) === 1) {
            return $m[1];
        }

        return null;
    }

    /** Kanalbild für die Anzeige im Editor (og:image der Kanalseite). */
    private function extractAvatarFromHtml(string $html): ?string
    {
        if (preg_match('~<meta\s+property="og:image"\s+content="([^"]+)"~i', $html, $m) === 1) {
            $url = html_entity_decode($m[1], ENT_QUOTES, 'UTF-8');

            // Nur Google-eigene Bild-Hosts übernehmen.
            if (preg_match('~^https://(yt\d\.ggpht\.com|yt\d\.googleusercontent\.com|i\.ytimg\.com)/~', $url) === 1) {
                return mb_substr($url, 0, 500);
            }
        }

        return null;
    }

    /**
     * Baut die Liste der Seiten, die für diese Eingabe probiert werden.
     *
     * @return list<string>
     */
    private function candidateUrls(string $input): array
    {
        // Vollständige URL (ggf. ohne Schema) -> direkt übernehmen
        if (preg_match('~^(?:https?://)?(?:[a-z0-9-]+\.)*youtu(?:be\.com|\.be)/~i', $input) === 1) {
            $url = str_starts_with($input, 'http') ? $input : 'https://' . ltrim($input, '/');

            return $this->isAllowed($url) ? [$url] : [];
        }

        // Nackte Eingabe: als Handle, Custom- und Legacy-URL probieren.
        $name = ltrim($input, '@/');
        if (preg_match('~^[A-Za-z0-9._-]{2,80}$~', $name) !== 1) {
            return [];
        }

        return [
            'https://www.youtube.com/@' . rawurlencode($name),
            'https://www.youtube.com/c/' . rawurlencode($name),
            'https://www.youtube.com/' . rawurlencode($name),
            'https://www.youtube.com/user/' . rawurlencode($name),
        ];
    }

    /**
     * Host-Whitelist - ohne diese Prüfung wäre der Dienst ein offener
     * Proxy für beliebige URLs.
     */
    private function isAllowed(string $url): bool
    {
        $host = parse_url($url, PHP_URL_HOST);
        if (!is_string($host)) {
            return false;
        }

        return in_array(strtolower($host), self::ALLOWED_HOSTS, true);
    }
}
