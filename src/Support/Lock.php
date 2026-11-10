<?php

declare(strict_types=1);

namespace WatchRss\Support;

/**
 * Dateibasierte Sperre gegen parallele Läufe.
 *
 * Nötig, weil der Abruf über zwei Wege angestoßen werden kann (CLI-Cron und
 * Web-Trigger) und beide gleichzeitig laufen könnten - etwa wenn ein Lauf
 * länger dauert als der Cron-Abstand. Ohne Sperre würden dieselben Kanäle
 * doppelt geholt.
 *
 * Benutzt flock(): wird vom Betriebssystem auch dann freigegeben, wenn der
 * Prozess abstürzt - anders als eine selbstgebaute "lock"-Datei.
 */
final class Lock
{
    /** @var resource|null */
    private $handle = null;

    public function __construct(private readonly string $file)
    {
    }

    /**
     * Versucht die Sperre zu bekommen, ohne zu warten.
     */
    public function acquire(): bool
    {
        $directory = dirname($this->file);
        if (!is_dir($directory)) {
            @mkdir($directory, 0775, true);
        }

        $handle = @fopen($this->file, 'c');
        if ($handle === false) {
            // Kein Schreibzugriff: lieber laufen lassen als gar nicht laufen.
            return true;
        }

        if (!flock($handle, LOCK_EX | LOCK_NB)) {
            fclose($handle);

            return false;
        }

        // Zeitstempel für die Fehlersuche hinterlegen.
        ftruncate($handle, 0);
        fwrite($handle, gmdate('c') . ' pid=' . getmypid() . "\n");
        fflush($handle);

        $this->handle = $handle;

        return true;
    }

    public function release(): void
    {
        if ($this->handle !== null) {
            flock($this->handle, LOCK_UN);
            fclose($this->handle);
            $this->handle = null;
        }
    }

    public function __destruct()
    {
        $this->release();
    }
}
