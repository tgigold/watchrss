<?php

declare(strict_types=1);

namespace WatchRss\Support;

/**
 * Überspringt einen ausgefallenen Dienst eine Zeit lang.
 *
 * Erst nach $threshold Komplettausfällen in Folge (Netzwerkfehler,
 * Timeout, HTTP 5xx oder 429) ist der Dienst für $cooldownSeconds
 * "offen" - ein einzelner Timeout sperrt nichts. Jede erfolgreiche Antwort
 * setzt den Zähler zurück. Nach Ablauf der Pause darf ein Versuch durch;
 * scheitert er, ist der Dienst sofort wieder für eine Pause gesperrt.
 *
 * Der Zustand liegt im Datei-Cache und gilt damit über Requests und
 * Cron-Läufe hinweg.
 */
final class CircuitBreaker
{
    public function __construct(
        private readonly FileCache $cache,
        private readonly int $threshold = 5,
        private readonly int $cooldownSeconds = 300,
    ) {
    }

    /** Sekunden bis zum nächsten erlaubten Versuch, null = Dienst frei. */
    public function retryAfter(string $service): ?int
    {
        $state     = $this->state($service);
        $remaining = $state['openUntil'] - time();

        return $remaining > 0 ? $remaining : null;
    }

    public function recordSuccess(string $service): void
    {
        $this->cache->delete($this->key($service));
    }

    public function recordFailure(string $service): void
    {
        $state    = $this->state($service);
        $failures = $state['failures'] + 1;
        $openUntil = $state['openUntil'];

        if ($failures >= max(1, $this->threshold)) {
            $openUntil = time() + $this->cooldownSeconds;
            // Halb offen: Nach der Pause reicht ein weiterer Ausfall.
            $failures = max(0, $this->threshold - 1);
        }

        $this->cache->set(
            $this->key($service),
            (string) json_encode(['f' => $failures, 'u' => $openUntil]),
            max(3600, $this->cooldownSeconds * 4),
        );
    }

    /** @return array{failures:int,openUntil:int} */
    private function state(string $service): array
    {
        $raw     = $this->cache->get($this->key($service));
        $decoded = $raw !== null ? json_decode($raw, true) : null;

        if (!is_array($decoded) || !is_int($decoded['f'] ?? null) || !is_int($decoded['u'] ?? null)) {
            return ['failures' => 0, 'openUntil' => 0];
        }

        return ['failures' => $decoded['f'], 'openUntil' => $decoded['u']];
    }

    private function key(string $service): string
    {
        return 'breaker:' . substr(hash('sha256', strtolower($service)), 0, 16);
    }
}
