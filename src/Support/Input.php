<?php

declare(strict_types=1);

namespace WatchRss\Support;

use WatchRss\Exception\ValidationException;

/**
 * Explizite Typprüfung für Eingaben aus Request-Bodys.
 *
 * Jede Methode liest ein Feld aus einem Array, prüft Typ und Grenzen und
 * wirft sonst eine ValidationException mit Feldname, erwartetem und
 * erhaltenem Typ. Ein Array im Textfeld wird also abgelehnt, statt als
 * "Array" in der Datenbank zu landen.
 */
final class Input
{
    /**
     * Text: nur Strings, gültiges UTF-8, höchstens $maxLength Zeichen.
     * Fehlt das Feld (oder ist null), kommt $default zurück.
     *
     * @param array<array-key,mixed> $data
     */
    public static function string(array $data, string $field, ?string $default, int $maxLength, ?string $path = null): ?string
    {
        $value = $data[$field] ?? null;
        $name  = $path ?? $field;

        if ($value === null) {
            return $default;
        }
        if (!is_string($value)) {
            throw ValidationException::field($name, 'string', self::typeOf($value), "Feld \"$name\" muss Text sein.");
        }
        if (!mb_check_encoding($value, 'UTF-8')) {
            throw ValidationException::field($name, 'string (UTF-8)', 'string (ungültiges UTF-8)', "Feld \"$name\" enthält ungültige Zeichen.");
        }
        if (mb_strlen($value) > $maxLength) {
            throw ValidationException::field(
                $name,
                "string (max. $maxLength Zeichen)",
                'string (' . mb_strlen($value) . ' Zeichen)',
                "Feld \"$name\" ist zu lang (max. $maxLength Zeichen)."
            );
        }

        return $value;
    }

    /**
     * Ganzzahl in [$min, $max]. Akzeptiert int und Ziffern-Strings.
     *
     * @param array<array-key,mixed> $data
     */
    public static function int(array $data, string $field, ?int $default, int $min, int $max): ?int
    {
        $value = $data[$field] ?? null;

        if ($value === null) {
            return $default;
        }
        if (is_string($value) && preg_match('/^-?\d{1,18}$/', $value) === 1) {
            $value = (int) $value;
        }
        if (!is_int($value)) {
            throw ValidationException::field($field, 'integer', self::typeOf($value), "Feld \"$field\" muss eine ganze Zahl sein.");
        }
        if ($value < $min || $value > $max) {
            throw ValidationException::field(
                $field,
                "integer ({$min}–{$max})",
                'integer (außerhalb)',
                "Feld \"$field\" muss zwischen $min und $max liegen."
            );
        }

        return $value;
    }

    /**
     * Wahrheitswert. Akzeptiert true/false, 0/1 sowie "0"/"1"/"true"/"false".
     *
     * @param array<array-key,mixed> $data
     */
    public static function bool(array $data, string $field, bool $default, ?string $path = null): bool
    {
        $value = $data[$field] ?? null;
        $name  = $path ?? $field;

        if ($value === null) {
            return $default;
        }
        if (is_bool($value)) {
            return $value;
        }
        if ($value === 0 || $value === 1) {
            return $value === 1;
        }
        if (is_string($value) && in_array($value, ['0', '1', 'true', 'false'], true)) {
            return $value === '1' || $value === 'true';
        }

        throw ValidationException::field($name, 'boolean', self::typeOf($value), "Feld \"$name\" muss true oder false sein.");
    }

    /**
     * Liste (JSON-Array) mit höchstens $maxItems Einträgen.
     *
     * @param  array<array-key,mixed> $data
     * @return list<mixed>
     */
    public static function list(array $data, string $field, int $maxItems): array
    {
        $value = $data[$field] ?? null;

        if ($value === null) {
            return [];
        }
        if (!is_array($value) || !array_is_list($value)) {
            throw ValidationException::field($field, 'array', self::typeOf($value), "Feld \"$field\" muss eine Liste sein.");
        }
        if (count($value) > $maxItems) {
            throw ValidationException::field(
                $field,
                "array (max. $maxItems Einträge)",
                'array (' . count($value) . ' Einträge)',
                "Maximal $maxItems Einträge in \"$field\"."
            );
        }

        return $value;
    }

    /** JSON-nahe Typbezeichnung für Fehlermeldungen - nie der Inhalt. */
    public static function typeOf(mixed $value): string
    {
        return match (true) {
            $value === null                            => 'null',
            is_bool($value)                            => 'boolean',
            is_int($value)                             => 'integer',
            is_float($value)                           => 'number',
            is_string($value)                          => 'string',
            is_array($value) && array_is_list($value)  => 'array',
            is_array($value)                           => 'object',
            default                                    => get_debug_type($value),
        };
    }
}
