<?php

declare(strict_types=1);

namespace WatchRss\Service;

/**
 * Ergebnis eines kompletten Abruflaufs - gemeinsame Rückgabe für CLI und
 * Web-Trigger.
 */
final class FetchSummary
{
    /**
     * @param list<string> $lines Zeilenprotokoll für Konsole bzw. HTTP-Antwort
     */
    public function __construct(
        public readonly int $due = 0,
        public readonly int $updated = 0,
        public readonly int $notModified = 0,
        public readonly int $failed = 0,
        public readonly int $newVideos = 0,
        public readonly int $prunedVideos = 0,
        public readonly int $removedChannels = 0,
        public readonly int $removedCacheFiles = 0,
        public readonly float $seconds = 0.0,
        public readonly bool $stoppedEarly = false,
        public readonly array $lines = [],
    ) {
    }

    public function hasErrors(): bool
    {
        return $this->failed > 0;
    }

    /** Eine Zeile, die als Cron-Mail oder Monitoring-Ausgabe taugt. */
    public function headline(): string
    {
        return sprintf(
            '%d aktualisiert, %d unverändert, %d Fehler, %d neue Videos in %.1fs%s',
            $this->updated,
            $this->notModified,
            $this->failed,
            $this->newVideos,
            $this->seconds,
            $this->stoppedEarly ? ' (Zeitbudget erreicht, Rest folgt beim nächsten Lauf)' : '',
        );
    }

    /** @return array<string,mixed> Für die JSON-Ausgabe des Web-Triggers. */
    public function toArray(): array
    {
        return [
            'due'                 => $this->due,
            'updated'             => $this->updated,
            'not_modified'        => $this->notModified,
            'failed'              => $this->failed,
            'new_videos'          => $this->newVideos,
            'pruned_videos'       => $this->prunedVideos,
            'removed_channels'    => $this->removedChannels,
            'removed_cache_files' => $this->removedCacheFiles,
            'seconds'             => round($this->seconds, 2),
            'stopped_early'       => $this->stoppedEarly,
        ];
    }
}
