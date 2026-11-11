<?php

declare(strict_types=1);

namespace WatchRss\Tests\Unit\Service;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use WatchRss\Service\ChannelResolver;

/**
 * Welche URLs würden für eine Eingabe abgerufen? Ohne Netzwerk.
 */
final class ChannelResolverTest extends TestCase
{
    /** @return iterable<string,array{string,list<string>}> */
    public static function inputs(): iterable
    {
        yield 'http wird https'        => ['http://www.youtube.com/@bmw', ['https://www.youtube.com/@bmw']];
        yield 'ohne Schema'            => ['youtube.com/@bmw', ['https://youtube.com/@bmw']];
        yield 'https bleibt'           => ['https://m.youtube.com/c/bmw', ['https://m.youtube.com/c/bmw']];
        yield 'fremder Host'           => ['http://evil.com/youtube.com/x', []];
        yield 'YouTube-Attrappe'       => ['https://youtube.com.evil.com/x', []];
        yield 'nacktes Handle'         => ['@bmw', [
            'https://www.youtube.com/@bmw',
            'https://www.youtube.com/c/bmw',
            'https://www.youtube.com/bmw',
            'https://www.youtube.com/user/bmw',
        ]];
        yield 'Unsinn'                 => ['<script>', []];
    }

    /** @param list<string> $expected */
    #[DataProvider('inputs')]
    public function testCandidateUrls(string $input, array $expected): void
    {
        $class    = new ReflectionClass(ChannelResolver::class);
        $resolver = $class->newInstanceWithoutConstructor();

        self::assertSame($expected, $class->getMethod('candidateUrls')->invoke($resolver, $input));
    }
}
