<?php

declare(strict_types=1);

namespace WatchRss\Setup;

use RuntimeException;

/**
 * Einmal-Code für das Web-Setup.
 *
 * Liegt in einer Datei im Datenverzeichnis (var/, per .htaccess gesperrt),
 * ist also nur per FTP lesbar. Wer ihn eingeben kann, hat Zugriff auf den
 * Server - sonst könnte zwischen Deploy und Setup jeder die Instanz
 * übernehmen.
 */
final class SetupCode
{
    /** Ohne Verwechsler (0/O, 1/I/L). 12 Zeichen ≈ 59 Bit. */
    private const ALPHABET = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';

    public function __construct(private readonly string $file)
    {
    }

    public function file(): string
    {
        return $this->file;
    }

    /** Legt den Code an, falls es noch keinen gibt. */
    public function ensure(): void
    {
        if (is_file($this->file)) {
            return;
        }

        $dir = dirname($this->file);
        if (!is_dir($dir) && !@mkdir($dir, 0775, true)) {
            throw new RuntimeException('Verzeichnis für den Setup-Code nicht anlegbar: ' . $dir);
        }

        $code = '';
        for ($i = 0; $i < 12; $i++) {
            $code .= self::ALPHABET[random_int(0, strlen(self::ALPHABET) - 1)];
            if ($i % 4 === 3 && $i < 11) {
                $code .= '-';
            }
        }

        if (@file_put_contents($this->file, $code . "\n", LOCK_EX) === false) {
            throw new RuntimeException('Setup-Code nicht schreibbar: ' . $this->file);
        }
        @chmod($this->file, 0600);
    }

    public function verify(string $input): bool
    {
        $stored = is_file($this->file) ? (string) file_get_contents($this->file) : '';
        $stored = self::normalize($stored);

        return $stored !== '' && hash_equals($stored, self::normalize($input));
    }

    public function delete(): void
    {
        if (is_file($this->file)) {
            @unlink($this->file);
        }
    }

    /** Groß-/Kleinschreibung, Leerzeichen und Bindestriche egal. */
    private static function normalize(string $code): string
    {
        return strtoupper((string) preg_replace('/[^A-Za-z0-9]/', '', $code));
    }
}
