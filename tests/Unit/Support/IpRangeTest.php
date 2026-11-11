<?php

declare(strict_types=1);

namespace WatchRss\Tests\Unit\Support;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use WatchRss\Support\IpRange;

final class IpRangeTest extends TestCase
{
    /** @return iterable<string,array{string,string,bool}> */
    public static function cases(): iterable
    {
        yield 'v4 im Netz'          => ['10.1.2.3', '10.0.0.0/8', true];
        yield 'v4 außerhalb'        => ['11.1.2.3', '10.0.0.0/8', false];
        yield 'v4 /25 unten'        => ['192.168.1.100', '192.168.1.0/25', true];
        yield 'v4 /25 oben'         => ['192.168.1.200', '192.168.1.0/25', false];
        yield 'v4 Einzeladresse'    => ['1.2.3.4', '1.2.3.4', true];
        yield 'v4 Einzel daneben'   => ['1.2.3.5', '1.2.3.4', false];
        yield 'v6 im Netz'          => ['2001:db8::5', '2001:db8::/32', true];
        yield 'v6 außerhalb'        => ['2001:db9::5', '2001:db8::/32', false];
        yield 'v4 gegen v6-Netz'    => ['10.0.0.1', '::/0', false];
        yield 'keine IP'            => ['nope', '10.0.0.0/8', false];
        yield 'Präfix zu groß'      => ['10.0.0.1', '10.0.0.0/99', false];
        yield 'Präfix keine Zahl'   => ['10.0.0.1', '10.0.0.0/x', false];
        yield '/0 passt auf alles'  => ['8.8.8.8', '0.0.0.0/0', true];
    }

    #[DataProvider('cases')]
    public function testMatches(string $ip, string $range, bool $expected): void
    {
        self::assertSame($expected, IpRange::matches($ip, $range));
    }

    public function testParseListIgnoresBlanks(): void
    {
        self::assertSame(['1.2.3.4', '10.0.0.0/8'], IpRange::parseList(' 1.2.3.4, ,10.0.0.0/8,'));
    }

    public function testMatchesAny(): void
    {
        self::assertTrue(IpRange::matchesAny('10.0.0.1', ['1.2.3.4', '10.0.0.0/8']));
        self::assertFalse(IpRange::matchesAny('10.0.0.1', []));
    }
}
