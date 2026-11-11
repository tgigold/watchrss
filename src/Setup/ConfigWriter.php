<?php

declare(strict_types=1);

namespace WatchRss\Setup;

use RuntimeException;

/**
 * Schreibt config.inc.php aus den Setup-Angaben.
 *
 * Werte werden mit var_export() eingesetzt - damit ist jedes Zeichen im
 * Passwort sicher, nichts davon kann als PHP-Code wirken. Geschrieben wird
 * erst in eine Temp-Datei und dann umbenannt: Eine halbe Config gibt es nie.
 */
final class ConfigWriter
{
    /**
     * @param array<string,string> $values Konstantenname => Wert
     */
    public static function render(array $values): string
    {
        $lines = [
            '<?php',
            '/**',
            ' * watchrss - lokale Zugangsdaten und Geheimnisse.',
            ' * Erzeugt vom Web-Setup am ' . gmdate('Y-m-d H:i') . ' UTC. Nicht ins Git!',
            ' * Weitere Werte aus config.app.inc.php lassen sich hier überschreiben.',
            ' */',
            '',
        ];

        foreach ($values as $name => $value) {
            if (preg_match('/^[A-Z][A-Z0-9_]*$/', $name) !== 1) {
                throw new RuntimeException('Ungültiger Konstantenname: ' . $name);
            }
            $lines[] = sprintf('define(%s, %s);', var_export($name, true), var_export($value, true));
        }

        return implode("\n", $lines) . "\n";
    }

    /**
     * @param array<string,string> $values
     */
    public static function write(string $file, array $values): void
    {
        if (is_file($file)) {
            throw new RuntimeException('config.inc.php existiert bereits - Setup bricht ab.');
        }

        $temp = $file . '.' . bin2hex(random_bytes(4)) . '.tmp';
        if (@file_put_contents($temp, self::render($values), LOCK_EX) === false) {
            throw new RuntimeException('config.inc.php nicht schreibbar: ' . dirname($file) . ' braucht Schreibrechte.');
        }
        @chmod($temp, 0640);

        if (!@rename($temp, $file)) {
            @unlink($temp);
            throw new RuntimeException('config.inc.php konnte nicht angelegt werden.');
        }
    }
}
