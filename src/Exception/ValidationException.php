<?php

declare(strict_types=1);

namespace WatchRss\Exception;

use RuntimeException;

/**
 * Ungültige Eingabedaten aus der API (führt zu HTTP 422).
 *
 * $fields beschreibt, welche Felder in welcher Form angekommen sind -
 * Feldnamen und Typen, nie Inhalte. Damit lassen sich falsch verdrahtete
 * Clients finden, ohne Nutzerdaten in Antworten oder Logs zu tragen.
 */
final class ValidationException extends RuntimeException
{
    /**
     * @param array<string,array{expected:string,received:string}> $fields
     */
    public function __construct(string $message, public readonly array $fields = [])
    {
        parent::__construct($message);
    }

    public static function field(string $field, string $expected, string $received, string $message): self
    {
        return new self($message, [$field => ['expected' => $expected, 'received' => $received]]);
    }
}
