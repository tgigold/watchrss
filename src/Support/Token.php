<?php

declare(strict_types=1);

namespace WatchRss\Support;

/**
 * Erzeugung und Validierung der beiden Token-Arten eines Feeds.
 *
 * watchrss unterscheidet bewusst zwei Tokens, weil sie unterschiedlich
 * schutzbedürftig sind:
 *
 *   Edit-Token  - steht nur im Bearbeiten-Link und ist faktisch das Passwort
 *                 des Feeds. Lang (32 Zeichen base62, ~190 Bit), wandert
 *                 nirgendwo hin.
 *   Feed-Token  - steht in der öffentlichen RSS-Adresse. Die wandert
 *                 naturgemäß herum (Reader, OPML-Exporte, Screenshots), darf
 *                 also nichts freischalten. Er schützt nur sich selbst und
 *                 darf deshalb kurz sein.
 *
 * Beide werden unabhängig gewürfelt: Aus dem einen lässt sich der andere
 * nicht ableiten. Wer die Feed-Adresse kennt, kommt damit nicht an den
 * Editor - genau das war der Grund für die Trennung.
 */
final class Token
{
    /** Voller base62-Zeichenvorrat für das Geheimnis. */
    private const ALPHABET_EDIT = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';

    /**
     * Zeichenvorrat für die öffentliche Adresse: base62 ohne die Paare, die
     * man beim Abtippen oder Vorlesen verwechselt (0/O, 1/l/I). Bleiben
     * 56 Zeichen - bei 10 Stellen immer noch rund 3 · 10^17 Möglichkeiten.
     */
    private const ALPHABET_FEED = '23456789abcdefghijkmnpqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ';

    public const LENGTH_EDIT = 32;
    public const LENGTH_FEED = 10;

    /** Geheimer Token für den Bearbeiten-Link. */
    public static function edit(): string
    {
        return self::random(self::ALPHABET_EDIT, self::LENGTH_EDIT);
    }

    /** Öffentlicher Token für die Feed-Adresse. */
    public static function feed(): string
    {
        return self::random(self::ALPHABET_FEED, self::LENGTH_FEED);
    }

    /**
     * Formatprüfung, bevor überhaupt die Datenbank gefragt wird.
     * Bewusst großzügig: Länge und Zeichenvorrat können sich ändern, alte
     * Tokens müssen weiter funktionieren.
     */
    public static function isValidEdit(string $token): bool
    {
        return (bool) preg_match('/^[A-Za-z0-9]{16,64}$/', $token);
    }

    /**
     * So wird der geheime Edit-Token gespeichert: nur als SHA-256-Hash.
     * Muss zu SHA2(token, 256) in MySQL passen (Migration 0002).
     */
    public static function hash(string $token): string
    {
        return hash('sha256', $token);
    }

    public static function isValidFeed(string $token): bool
    {
        return (bool) preg_match('/^[A-Za-z0-9]{6,32}$/', $token);
    }

    private static function random(string $alphabet, int $length): string
    {
        $max   = strlen($alphabet) - 1;
        $token = '';

        for ($i = 0; $i < $length; $i++) {
            // random_int() nutzt den kryptografischen Zufallsgenerator des
            // Systems - mt_rand() wäre hier vorhersagbar.
            $token .= $alphabet[random_int(0, $max)];
        }

        return $token;
    }
}
