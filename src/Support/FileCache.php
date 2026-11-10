<?php

declare(strict_types=1);

namespace WatchRss\Support;

/**
 * Sehr einfacher Datei-Cache.
 *
 * Wird für zwei Dinge benutzt:
 *  - fertig gerenderte Feed-XML (Key = ETag, damit nie veraltet ausgeliefert wird)
 *  - Zähler für die Abruf-Bremse (siehe FeedThrottle)
 *
 * Absichtlich ohne externe Abhängigkeit: läuft auf jedem Shared Hosting.
 */
final class FileCache
{
    public function __construct(private readonly string $directory)
    {
        if (!is_dir($this->directory)) {
            @mkdir($this->directory, 0775, true);
        }
    }

    public function get(string $key): ?string
    {
        $file = $this->path($key);
        if (!is_file($file)) {
            return null;
        }

        $raw = @file_get_contents($file);
        if ($raw === false || strlen($raw) < 11) {
            return null;
        }

        // Format: 10-stelliger Unix-Timestamp des Ablaufs + "|" + Payload.
        $expiresAt = (int) substr($raw, 0, 10);
        if ($expiresAt !== 0 && $expiresAt < time()) {
            @unlink($file);

            return null;
        }

        return substr($raw, 11);
    }

    public function set(string $key, string $value, int $ttlSeconds = 3600): void
    {
        $expiresAt = $ttlSeconds > 0 ? time() + $ttlSeconds : 0;
        $file      = $this->path($key);
        $tmp       = $file . '.' . bin2hex(random_bytes(4)) . '.tmp';

        // Atomar schreiben: parallele Requests sehen nie eine halbe Datei.
        if (@file_put_contents($tmp, sprintf('%010d|%s', $expiresAt, $value), LOCK_EX) !== false) {
            @rename($tmp, $file);
        } else {
            @unlink($tmp);
        }
    }

    public function delete(string $key): void
    {
        @unlink($this->path($key));
    }

    /**
     * Entfernt abgelaufene Einträge. Wird gelegentlich vom Cron aufgerufen.
     */
    public function prune(): int
    {
        $removed = 0;
        foreach (glob($this->directory . '/*.cache') ?: [] as $file) {
            $handle = @fopen($file, 'rb');
            if ($handle === false) {
                continue;
            }
            $stamp = (int) fread($handle, 10);
            fclose($handle);

            if ($stamp !== 0 && $stamp < time()) {
                @unlink($file);
                $removed++;
            }
        }

        return $removed;
    }

    private function path(string $key): string
    {
        return $this->directory . '/' . sha1($key) . '.cache';
    }
}
