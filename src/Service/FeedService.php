<?php

declare(strict_types=1);

namespace WatchRss\Service;

use WatchRss\Exception\ValidationException;
use WatchRss\Model\Channel;
use WatchRss\Model\Feed;
use WatchRss\Repository\ChannelRepository;
use WatchRss\Repository\FeedRepository;
use WatchRss\Repository\VideoRepository;
use WatchRss\Support\Input;

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

    /** Zeitbudget fürs Vorab-Abholen; der Rest kommt mit dem nächsten Cron. */
    private const PRIME_BUDGET_SECONDS = 10;

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
        // Erst alle Typen prüfen, dann erst Kanäle auflösen (kostet Requests).
        $title        = $this->validateTitle($payload);
        $ttlMinutes   = $this->validateTtl($payload);
        $maxItems     = Input::int($payload, 'max_items', (int) FEED_DEFAULT_MAX_ITEMS, 10, (int) FEED_MAX_ITEMS_LIMIT);
        $prefixTitles = Input::bool($payload, 'prefix_titles', true);
        $entries      = $this->validateChannelEntries($payload);
        $channels     = $this->resolveChannels($entries);

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
        $primed   = 0;
        $deadline = microtime(true) + self::PRIME_BUDGET_SECONDS;

        foreach ($channels as $channel) {
            if (microtime(true) >= $deadline) {
                break;
            }
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

    /**
     * @param array<string,mixed> $payload
     */
    private function validateTitle(array $payload): string
    {
        $title = trim((string) Input::string($payload, 'title', '', 190));

        return $title === '' ? 'watchrss' : $title;
    }

    /**
     * Nur die im Editor angebotenen Intervalle zulassen.
     *
     * @param array<string,mixed> $payload
     */
    private function validateTtl(array $payload): int
    {
        $allowed = array_map('intval', explode(',', (string) FEED_TTL_CHOICES));
        $ttl     = (int) Input::int($payload, 'ttl_minutes', (int) FEED_DEFAULT_TTL_MINUTES, 1, 10080);

        if (!in_array($ttl, $allowed, true)) {
            throw ValidationException::field(
                'ttl_minutes',
                'integer (' . implode('|', $allowed) . ')',
                'integer (nicht erlaubt)',
                'Dieses Abrufintervall wird nicht angeboten.'
            );
        }

        return $ttl;
    }

    /**
     * Prüft Form und Typen der Kanalliste, ohne etwas aufzulösen.
     *
     * @param  array<string,mixed> $payload
     * @return list<array{channel_id:string,include_long:bool,include_shorts:bool}>
     * @throws ValidationException
     */
    private function validateChannelEntries(array $payload): array
    {
        $list = Input::list($payload, 'channels', (int) FEED_MAX_CHANNELS);
        if ($list === []) {
            throw new ValidationException('Bitte mindestens einen Kanal auswählen.');
        }

        $entries = [];
        foreach ($list as $index => $entry) {
            $path = "channels[$index]";
            if (!is_array($entry) || array_is_list($entry)) {
                throw ValidationException::field($path, 'object', Input::typeOf($entry), 'Ungültiger Eintrag in der Kanalliste.');
            }

            $channelId = trim((string) Input::string($entry, 'channel_id', '', 200, "$path.channel_id"));
            if ($channelId === '') {
                throw ValidationException::field("$path.channel_id", 'string', 'leer', 'Kanal ohne ID in der Auswahl.');
            }

            $entries[] = [
                'channel_id'     => $channelId,
                'include_long'   => Input::bool($entry, 'include_long', true, "$path.include_long"),
                'include_shorts' => Input::bool($entry, 'include_shorts', true, "$path.include_shorts"),
            ];
        }

        return $entries;
    }

    /**
     * @param  list<array{channel_id:string,include_long:bool,include_shorts:bool}> $entries
     * @return list<array{channel_ref:int,include_long:bool,include_shorts:bool}>
     * @throws ValidationException
     */
    private function resolveChannels(array $entries): array
    {
        $result = [];
        $seen   = [];

        foreach ($entries as $entry) {
            $channelId = $entry['channel_id'];
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
                'include_long'   => $entry['include_long'],
                'include_shorts' => $entry['include_shorts'],
            ];
        }

        if ($result === []) {
            throw new ValidationException('Kein gültiger Kanal in der Auswahl.');
        }

        return $result;
    }
}
