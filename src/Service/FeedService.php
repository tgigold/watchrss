<?php

declare(strict_types=1);

namespace WatchRss\Service;

use WatchRss\Exception\ValidationException;
use WatchRss\Model\Channel;
use WatchRss\Model\Feed;
use WatchRss\Repository\ChannelRepository;
use WatchRss\Repository\FeedRepository;
use WatchRss\Repository\VideoRepository;

/**
 * Anwendungsfälle rund um Nutzer-Feeds.
 *
 * Buendelt das, was Controller sonst selbst zusammenstückeln müssten:
 * Eingabe auflösen, Payload validieren, Feed anlegen/aktualisieren und
 * frisch hinzugefuegte Kanäle direkt einmal abholen.
 */
final class FeedService
{
    /** So viele neue Kanäle werden im Request direkt abgeholt. */
    private const PRIME_LIMIT = 12;

    public function __construct(
        private readonly FeedRepository $feeds,
        private readonly ChannelRepository $channels,
        private readonly VideoRepository $videos,
        private readonly ChannelResolver $resolver,
        private readonly InputParser $parser,
        private readonly YouTubeFeedFetcher $fetcher,
    ) {
    }

    /**
     * Freitext -> aufgelöste Kanäle + Fehlerliste.
     *
     * @return array{channels:list<Channel>,errors:list<array{input:string,message:string}>}
     */
    public function resolveInput(string $raw): array
    {
        $candidates = $this->parser->parse($raw);
        if ($candidates === []) {
            return ['channels' => [], 'errors' => []];
        }

        $result = $this->resolver->resolveMany($candidates);
        $this->primeChannels($result['channels']);

        return $result;
    }

    /**
     * Legt einen Feed an oder aktualisiert einen bestehenden.
     *
     * @param  array<string,mixed> $payload Rohdaten aus der JSON-API
     * @throws ValidationException
     */
    public function save(?Feed $existing, array $payload): Feed
    {
        $title        = $this->validateTitle($payload['title'] ?? null);
        $ttlMinutes   = $this->validateTtl($payload['ttl_minutes'] ?? null);
        $maxItems     = $this->validateMaxItems($payload['max_items'] ?? null);
        $prefixTitles = (bool) ($payload['prefix_titles'] ?? true);
        $channels     = $this->validateChannels($payload['channels'] ?? []);

        if ($existing === null) {
            return $this->feeds->create($title, $channels, $ttlMinutes, $maxItems, $prefixTitles);
        }

        $this->feeds->updateSettings($existing->id ?? 0, $title, $ttlMinutes, $maxItems, $prefixTitles);
        $this->feeds->syncChannels($existing->id ?? 0, $channels);

        return $this->feeds->findByEditToken($existing->editToken) ?? $existing;
    }

    /**
     * Holt Kanäle, zu denen noch nichts in der DB liegt, sofort einmal ab.
     * Ohne das wäre ein frisch erstellter Feed bis zum nächsten Cron-Lauf
     * leer - der häufigste "das funktioniert ja nicht"-Moment.
     *
     * @param list<Channel> $channels
     */
    public function primeChannels(array $channels): void
    {
        $primed = 0;

        foreach ($channels as $channel) {
            if ($channel->id === null || $channel->lastSuccessAt !== null) {
                continue;
            }
            if ($primed >= self::PRIME_LIMIT) {
                break;
            }

            $this->fetcher->fetch($channel);
            $primed++;
        }
    }

    /**
     * Vorschau-Daten für den Editor: die jüngsten Einträge des Feeds.
     *
     * @return list<array<string,mixed>>
     */
    public function preview(Feed $feed, int $limit = 30): array
    {
        return array_map(
            static fn ($video): array => $video->toArray(),
            $this->videos->forFeed($feed, $limit)
        );
    }

    /* ---------------------------------------------------------------------
     * Validierung
     * ------------------------------------------------------------------ */

    private function validateTitle(mixed $value): string
    {
        $title = trim((string) ($value ?? ''));
        if ($title === '') {
            return 'watchrss';
        }

        return mb_substr($title, 0, 190);
    }

    /** Nur die im Editor angebotenen Intervalle zulassen. */
    private function validateTtl(mixed $value): int
    {
        $allowed = array_map('intval', explode(',', (string) FEED_TTL_CHOICES));
        $ttl     = (int) ($value ?? FEED_DEFAULT_TTL_MINUTES);

        return in_array($ttl, $allowed, true) ? $ttl : (int) FEED_DEFAULT_TTL_MINUTES;
    }

    private function validateMaxItems(mixed $value): int
    {
        $items = (int) ($value ?? FEED_DEFAULT_MAX_ITEMS);

        return max(10, min((int) FEED_MAX_ITEMS_LIMIT, $items));
    }

    /**
     * @param  mixed $value Liste von {channel_id, include_long, include_shorts}
     * @return list<array{channel_ref:int,include_long:bool,include_shorts:bool}>
     * @throws ValidationException
     */
    private function validateChannels(mixed $value): array
    {
        if (!is_array($value) || $value === []) {
            throw new ValidationException('Bitte mindestens einen Kanal auswählen.');
        }
        if (count($value) > (int) FEED_MAX_CHANNELS) {
            throw new ValidationException('Maximal ' . FEED_MAX_CHANNELS . ' Kanäle pro Feed.');
        }

        $result = [];
        $seen   = [];

        foreach ($value as $entry) {
            if (!is_array($entry) || !isset($entry['channel_id'])) {
                continue;
            }

            $channelId = trim((string) $entry['channel_id']);
            if (isset($seen[$channelId])) {
                continue;
            }

            // Der Kanal muss bereits aufgelöst sein (das passiert beim
            // Prüfen der Eingabe). Falls nicht, hier nachholen.
            $channel = $this->channels->findByChannelId($channelId)
                ?? $this->resolver->resolve($channelId);

            if ($channel->id === null) {
                continue;
            }

            $seen[$channelId] = true;
            $result[]         = [
                'channel_ref'    => $channel->id,
                'include_long'   => (bool) ($entry['include_long'] ?? true),
                'include_shorts' => (bool) ($entry['include_shorts'] ?? true),
            ];
        }

        if ($result === []) {
            throw new ValidationException('Kein gültiger Kanal in der Auswahl.');
        }

        return $result;
    }
}
