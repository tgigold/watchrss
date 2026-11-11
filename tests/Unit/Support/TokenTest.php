<?php

declare(strict_types=1);

namespace WatchRss\Tests\Unit\Support;

use PHPUnit\Framework\TestCase;
use WatchRss\Support\Token;

final class TokenTest extends TestCase
{
    public function testHashIsLowercaseHexSha256LikeMysqlSha2(): void
    {
        // Referenzwert: SELECT SHA2('abc', 256) in MySQL/MariaDB.
        self::assertSame('ba7816bf8f01cfea414140de5dae2223b00361a396177a9cb410ff61f20015ad', Token::hash('abc'));
    }

    public function testGeneratedTokensMatchTheirFormat(): void
    {
        self::assertTrue(Token::isValidEdit(Token::edit()));
        self::assertTrue(Token::isValidFeed(Token::feed()));
        self::assertSame(Token::LENGTH_EDIT, strlen(Token::edit()));
        self::assertNotSame(Token::edit(), Token::edit());
    }
}
