<?php

declare(strict_types=1);

namespace WatchRss\Support;

/**
 * Prüft, ob eine IP-Adresse in einer Liste von Adressen/Netzen liegt.
 *
 * Einträge sind einzelne Adressen ("203.0.113.7", "2001:db8::1") oder Netze
 * in CIDR-Schreibweise ("10.0.0.0/8", "2001:db8::/32"). IPv4 und IPv6 werden
 * nie miteinander verglichen.
 */
final class IpRange
{
    /**
     * Zerlegt eine kommagetrennte Liste (z. B. aus TRUSTED_PROXIES).
     *
     * @return list<string>
     */
    public static function parseList(string $list): array
    {
        return array_values(array_filter(
            array_map('trim', explode(',', $list)),
            static fn (string $entry): bool => $entry !== ''
        ));
    }

    /**
     * @param list<string> $ranges
     */
    public static function matchesAny(string $ip, array $ranges): bool
    {
        foreach ($ranges as $range) {
            if (self::matches($ip, $range)) {
                return true;
            }
        }

        return false;
    }

    public static function matches(string $ip, string $range): bool
    {
        $ipBin = @inet_pton($ip);
        if ($ipBin === false) {
            return false;
        }

        $parts  = explode('/', $range, 2);
        $net    = $parts[0];
        $bits   = $parts[1] ?? null;
        $netBin = @inet_pton(trim($net));
        if ($netBin === false || strlen($netBin) !== strlen($ipBin)) {
            return false;
        }

        $maxBits = strlen($ipBin) * 8;
        if ($bits === null) {
            $bits = $maxBits;
        } elseif (!ctype_digit(trim($bits)) || (int) $bits > $maxBits) {
            return false;
        }
        $bits = (int) $bits;

        $fullBytes = intdiv($bits, 8);
        if (substr($ipBin, 0, $fullBytes) !== substr($netBin, 0, $fullBytes)) {
            return false;
        }

        $rest = $bits % 8;
        if ($rest === 0) {
            return true;
        }

        $mask = (0xFF << (8 - $rest)) & 0xFF;

        return (ord($ipBin[$fullBytes]) & $mask) === (ord($netBin[$fullBytes]) & $mask);
    }
}
