<?php

declare(strict_types=1);

namespace WatchRss\Service;

use WatchRss\Model\Channel;
use WatchRss\Repository\ChannelRepository;
use WatchRss\Repository\VideoRepository;
use WatchRss\Support\FileCache;
use WatchRss\Support\Lock;

/**
 * Der eigentliche Abruflauf - einmal geschrieben, zwei Aufrufwege:
 * bin/fetch.php (Konsolen-Cron) und CronController (Web-Trigger).
 *
 * Dass beide dieselbe Klasse benutzen, ist der Grund, warum der Web-Trigger
 * nicht "fast dasselbe" macht, sondern exakt dasselbe - inklusive Backoff,
 * Aufräumen und Sperre gegen parallele Läufe.
 */
final class FetchRunner
{
    public function __construct(
        private readonly ChannelRepository $channels,
        private readonly VideoRepository $videos,
        private readonly YouTubeFeedFetcher $fetcher,
        private readonly FileCache $cache,
        private readonly string $lockFile,
    ) {
    }

    /**
     * Führt einen Lauf aus.
     *
     * @param int         $limit         maximale Anzahl Kanäle
     * @param bool        $force         Intervall ignorieren
     * @param int         $maxSeconds    Zeitbudget; 0 = unbegrenzt. Der Lauf
     *                                   bricht sauber ab, sobald es aufgebraucht
     *                                   ist - wichtig im Web, wo nach ~60s der
     *                                   Server die Verbindung kappt.
     * @param bool        $prune         zusätzlich aufräumen
     * @param string|null $onlyChannelId nur diesen Kanal
     *
     * @return FetchSummary|null null, wenn bereits ein Lauf aktiv ist
     */
    public function run(
        int $limit,
        bool $force = false,
        int $maxSeconds = 0,
        bool $prune = false,
        ?string $onlyChannelId = null,
    ): ?FetchSummary {
        $lock = new Lock($this->lockFile);
        if (!$lock->acquire()) {
            return null;
        }

        $startedAt = microtime(true);
        $deadline  = $maxSeconds > 0 ? $startedAt + $maxSeconds : null;

        $lines   = [];
        $updated = $notModified = $failed = $newVideos = $pruned = 0;
        $stoppedEarly = false;

        try {
            $due = $this->selectChannels($limit, $force, $onlyChannelId);

            foreach ($due as $index => $channel) {
                // Zeitbudget vor jedem Kanal prüfen: ein angefangener Abruf
                // wird immer zu Ende gebracht, ein neuer nicht mehr begonnen.
                if ($deadline !== null && microtime(true) >= $deadline) {
                    $stoppedEarly = true;
                    break;
                }

                $result = $this->fetcher->fetch($channel);
                $lines[] = $channel->displayName() . ' – ' . $result->summary();

                // YouTube ist per Circuit Breaker gesperrt: Die übrigen Kanäle
                // liegen auf demselben Dienst - Rest beim nächsten Lauf.
                if ($result->status === 'skipped') {
                    $stoppedEarly = true;
                    break;
                }

                match ($result->status) {
                    'ok'           => $updated++,
                    'not_modified' => $notModified++,
                    default        => $failed++,
                };
                $newVideos += $result->new;

                if ($result->status === 'ok' && $channel->id !== null) {
                    $pruned += $this->videos->prune($channel->id, (int) VIDEO_RETENTION_PER_CHANNEL);
                }

                // Höflich bleiben - YouTube mag keine Bursts.
                if ($index < count($due) - 1) {
                    usleep((int) FETCH_SLEEP_MICROSECONDS);
                }
            }

            $orphans    = $prune ? $this->channels->deleteOrphans() : 0;
            $cacheFiles = $prune ? $this->cache->prune() : 0;

            return new FetchSummary(
                due: count($due),
                updated: $updated,
                notModified: $notModified,
                failed: $failed,
                newVideos: $newVideos,
                prunedVideos: $pruned,
                removedChannels: $orphans,
                removedCacheFiles: $cacheFiles,
                seconds: microtime(true) - $startedAt,
                stoppedEarly: $stoppedEarly,
                lines: $lines,
            );
        } finally {
            $lock->release();
        }
    }

    /**
     * @return list<Channel>
     */
    private function selectChannels(int $limit, bool $force, ?string $onlyChannelId): array
    {
        if ($onlyChannelId !== null && $onlyChannelId !== '') {
            $channel = $this->channels->findByChannelId($onlyChannelId);

            return $channel !== null ? [$channel] : [];
        }

        return $this->channels->dueForFetch($limit, (int) CHANNEL_REFRESH_SECONDS, $force);
    }
}
