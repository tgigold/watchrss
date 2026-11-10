<?php

declare(strict_types=1);

namespace WatchRss\Service;

use SimpleXMLElement;
use WatchRss\Model\Channel;
use WatchRss\Repository\ChannelRepository;
use WatchRss\Repository\VideoRepository;
use WatchRss\Support\HttpClient;

/**
 * Holt den offiziellen Atom-Feed eines YouTube-Kanals und spiegelt ihn
 * in die eigene Datenbank.
 *
 * YouTube liefert dort immer die letzten ~15 Einträge. Weil wir die Daten
 * behalten, waechst der eigene Feed über die Zeit über diese 15 hinaus.
 */
final class YouTubeFeedFetcher
{
    private const NS_YT    = 'http://www.youtube.com/xml/schemas/2015';
    private const NS_MEDIA = 'http://search.yahoo.com/mrss/';

    public function __construct(
        private readonly HttpClient $http,
        private readonly ChannelRepository $channels,
        private readonly VideoRepository $videos,
    ) {
    }

    /**
     * Nur die Kopfdaten eines Kanalfeeds lesen (wird beim Auflösen benutzt,
     * um Channel-ID und Kanalnamen zu verifizieren).
     *
     * @return array{title:string,channel_id:string}|null
     */
    public function fetchMetadata(string $channelId): ?array
    {
        $response = $this->http->get(self::feedUrl($channelId));
        if (!$response->isOk() || $response->body === '') {
            return null;
        }

        $xml = $this->parseXml($response->body);
        if ($xml === null) {
            return null;
        }

        return [
            'title'      => trim((string) $xml->title),
            'channel_id' => $channelId,
        ];
    }

    /**
     * Holt einen Kanal ab und schreibt neue/geänderte Videos in die DB.
     * Nutzt Conditional GET, damit unveränderte Feeds nur 304 kosten.
     */
    public function fetch(Channel $channel): FetchResult
    {
        if ($channel->id === null) {
            return new FetchResult($channel->channelId, 'error', message: 'Kanal ohne ID.');
        }

        $response = $this->http->conditionalGet(
            $channel->feedUrl(),
            $channel->httpEtag,
            $channel->httpLastModified,
        );

        if ($response->error !== null) {
            $this->channels->markFetched($channel->id, 'error', $response->error);

            return new FetchResult($channel->channelId, 'error', message: $response->error);
        }

        if ($response->isNotModified()) {
            $this->channels->markFetched($channel->id, 'not_modified');

            return new FetchResult($channel->channelId, 'not_modified');
        }

        if (!$response->isOk()) {
            $message = 'HTTP ' . $response->status;
            $this->channels->markFetched($channel->id, 'error', $message);

            return new FetchResult($channel->channelId, 'error', message: $message);
        }

        $xml = $this->parseXml($response->body);
        if ($xml === null) {
            $this->channels->markFetched($channel->id, 'error', 'Antwort ist kein gültiges XML.');

            return new FetchResult($channel->channelId, 'error', message: 'Ungültiges XML.');
        }

        $entries = $this->extractEntries($xml);
        $result  = $this->videos->upsertMany($channel->id, $entries);

        $this->channels->markFetched(
            $channel->id,
            'ok',
            null,
            $response->header('etag'),
            $response->header('last-modified'),
            trim((string) $xml->title),
        );

        return new FetchResult($channel->channelId, 'ok', $result['seen'], $result['new']);
    }

    public static function feedUrl(string $channelId): string
    {
        return 'https://www.youtube.com/feeds/videos.xml?channel_id=' . urlencode($channelId);
    }

    /**
     * Wandelt die Atom-Einträge in Zeilen für die videos-Tabelle.
     *
     * @return list<array<string,mixed>>
     */
    private function extractEntries(SimpleXMLElement $xml): array
    {
        $rows = [];

        foreach ($xml->entry as $entry) {
            $yt      = $entry->children(self::NS_YT);
            $videoId = trim((string) $yt->videoId);
            if ($videoId === '') {
                continue;
            }

            // <link rel="alternate" href="..."> ist die einzige verlässliche
            // Stelle, an der YouTube Shorts von Longform unterscheidet:
            //   Short:    https://www.youtube.com/shorts/<id>
            //   Longform: https://www.youtube.com/watch?v=<id>
            $link = '';
            foreach ($entry->link as $linkNode) {
                if ((string) $linkNode['rel'] === 'alternate') {
                    $link = (string) $linkNode['href'];
                    break;
                }
            }
            if ($link === '') {
                $link = 'https://www.youtube.com/watch?v=' . $videoId;
            }

            $isShort = str_contains($link, '/shorts/');

            // media:group traegt Beschreibung und Thumbnail.
            $group       = $entry->children(self::NS_MEDIA)->group;
            $description = isset($group->description) ? trim((string) $group->description) : '';
            $thumbnail   = null;
            if (isset($group->thumbnail)) {
                $thumbnail = (string) $group->thumbnail->attributes()['url'];
            }
            // Fallback, falls media:group fehlt - Thumbnails sind bei YouTube
            // über ein festes Schema erreichbar.
            $thumbnail ??= 'https://i.ytimg.com/vi/' . $videoId . '/hqdefault.jpg';

            $publishedAt = self::toUtc((string) $entry->published);
            if ($publishedAt === null) {
                continue; // ohne Datum keine sinnvolle Sortierung
            }

            $rows[] = [
                'video_id'      => $videoId,
                'title'         => trim((string) $entry->title),
                'description'   => $description !== '' ? $description : null,
                'link'          => $link,
                'thumbnail_url' => $thumbnail,
                'is_short'      => $isShort,
                'published_at'  => $publishedAt,
                'updated_at'    => self::toUtc((string) $entry->updated),
            ];
        }

        return $rows;
    }

    private function parseXml(string $body): ?SimpleXMLElement
    {
        $previous = libxml_use_internal_errors(true);
        // LIBXML_NONET: keine externen Entities nachladen (XXE-Schutz).
        $xml = simplexml_load_string($body, SimpleXMLElement::class, LIBXML_NOCDATA | LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        return $xml === false ? null : $xml;
    }

    /**
     * ISO-8601 aus dem Atom-Feed nach "Y-m-d H:i:s" in UTC.
     */
    private static function toUtc(string $timestamp): ?string
    {
        $timestamp = trim($timestamp);
        if ($timestamp === '') {
            return null;
        }

        try {
            return (new \DateTimeImmutable($timestamp))
                ->setTimezone(new \DateTimeZone('UTC'))
                ->format('Y-m-d H:i:s');
        } catch (\Exception) {
            return null;
        }
    }
}
