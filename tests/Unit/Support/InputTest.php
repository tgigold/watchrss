<?php

declare(strict_types=1);

namespace WatchRss\Tests\Unit\Support;

use PHPUnit\Framework\TestCase;
use WatchRss\Exception\ValidationException;
use WatchRss\Support\Input;

final class InputTest extends TestCase
{
    public function testStringAcceptsUtf8AndUsesDefault(): void
    {
        self::assertSame('Hallo ü', Input::string(['a' => 'Hallo ü'], 'a', null, 10));
        self::assertSame('d', Input::string([], 'a', 'd', 10));
        self::assertNull(Input::string(['a' => null], 'a', null, 10));
    }

    public function testStringRejectsArray(): void
    {
        $this->assertRejected(fn () => Input::string(['a' => ['x']], 'a', null, 10), 'a', 'string', 'array');
    }

    public function testStringRejectsObjectAndNumber(): void
    {
        $this->assertRejected(fn () => Input::string(['a' => ['k' => 1]], 'a', null, 10), 'a', 'string', 'object');
        $this->assertRejected(fn () => Input::string(['a' => 5], 'a', null, 10), 'a', 'string', 'integer');
    }

    public function testStringRejectsInvalidUtf8(): void
    {
        $this->assertRejected(fn () => Input::string(['a' => "ab\xC3\x28"], 'a', null, 10), 'a');
    }

    public function testStringLengthCountsCharactersNotBytes(): void
    {
        self::assertSame(str_repeat('ü', 10), Input::string(['a' => str_repeat('ü', 10)], 'a', null, 10));
        $this->assertRejected(fn () => Input::string(['a' => str_repeat('ü', 11)], 'a', null, 10), 'a');
    }

    public function testStringUsesPathInFieldName(): void
    {
        $this->assertRejected(fn () => Input::string(['c' => 1], 'c', null, 5, 'channels[0].c'), 'channels[0].c');
    }

    public function testInt(): void
    {
        self::assertSame(42, Input::int(['n' => '42'], 'n', null, 1, 100));
        self::assertSame(7, Input::int(['n' => 7], 'n', null, 1, 100));
        self::assertSame(5, Input::int([], 'n', 5, 1, 100));
        $this->assertRejected(fn () => Input::int(['n' => 101], 'n', null, 1, 100), 'n', 'integer (1–100)');
        $this->assertRejected(fn () => Input::int(['n' => 1.5], 'n', null, 1, 100), 'n', 'integer', 'number');
        $this->assertRejected(fn () => Input::int(['n' => '12abc'], 'n', null, 1, 100), 'n', 'integer', 'string');
    }

    public function testBool(): void
    {
        self::assertFalse(Input::bool(['b' => false], 'b', true));
        self::assertTrue(Input::bool(['b' => '1'], 'b', false));
        self::assertTrue(Input::bool(['b' => 'true'], 'b', false));
        self::assertFalse(Input::bool(['b' => 0], 'b', true));
        self::assertTrue(Input::bool([], 'b', true));
        $this->assertRejected(fn () => Input::bool(['b' => 'yes'], 'b', true), 'b', 'boolean', 'string');
        $this->assertRejected(fn () => Input::bool(['b' => []], 'b', true), 'b', 'boolean', 'array');
    }

    public function testList(): void
    {
        self::assertSame([1, 2], Input::list(['l' => [1, 2]], 'l', 5));
        self::assertSame([], Input::list([], 'l', 5));
        $this->assertRejected(fn () => Input::list(['l' => ['a' => 1]], 'l', 5), 'l', 'array', 'object');
        $this->assertRejected(fn () => Input::list(['l' => [1, 2, 3]], 'l', 2), 'l');
    }

    public function testErrorsNeverContainTheValue(): void
    {
        try {
            Input::string(['a' => str_repeat('geheim', 5)], 'a', null, 10);
            self::fail('Exception erwartet');
        } catch (ValidationException $e) {
            self::assertStringNotContainsString('geheim', $e->getMessage() . json_encode($e->fields));
        }
    }

    private function assertRejected(callable $call, string $field, ?string $expected = null, ?string $received = null): void
    {
        try {
            $call();
            self::fail('ValidationException erwartet');
        } catch (ValidationException $e) {
            self::assertArrayHasKey($field, $e->fields);
            if ($expected !== null) {
                self::assertSame($expected, $e->fields[$field]['expected']);
            }
            if ($received !== null) {
                self::assertSame($received, $e->fields[$field]['received']);
            }
        }
    }
}
