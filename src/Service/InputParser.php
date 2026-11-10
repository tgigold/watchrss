<?php

declare(strict_types=1);

namespace WatchRss\Service;

/**
 * Zerlegt die Freitext-Eingabe des Nutzers in einzelne Kanal-Kandidaten.
 *
 * Erlaubt sind Zeilenumbrueche, Kommata, Semikola, Pipes und Leerzeichen als
 * Trenner. URLs enthalten selbst keine dieser Zeichen (Query-Parameter mit
 * "&" bleiben erhalten), daher ist das Aufsplitten gefahrlos.
 */
final class InputParser
{
    public const MAX_ENTRIES = 100;

    /**
     * @return list<string> Eindeutige, getrimmte Kandidaten in Eingabereihenfolge.
     */
    public function parse(string $raw): array
    {
        $raw   = str_replace(["\r\n", "\r"], "\n", trim($raw));
        $parts = preg_split('/[\s,;|]+/u', $raw, -1, PREG_SPLIT_NO_EMPTY) ?: [];

        $result = [];
        foreach ($parts as $part) {
            // Markdown-/Zitat-Reste entfernen, die beim Kopieren mitkommen
            $part = trim($part, " \t\n\"'<>()[],");
            if ($part === '') {
                continue;
            }
            $key = mb_strtolower($part);
            if (!isset($result[$key])) {
                $result[$key] = $part;
            }
            if (count($result) >= self::MAX_ENTRIES) {
                break;
            }
        }

        return array_values($result);
    }
}
