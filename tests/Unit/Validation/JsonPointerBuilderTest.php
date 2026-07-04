<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Unit\Validation;

use haddowg\JsonApiLaravel\Validation\JsonPointerBuilder;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The dot-notation → RFC 6901 `source.pointer` mapping the bridge uses to locate a
 * validation error in the request document.
 *
 * @internal
 */
#[CoversClass(JsonPointerBuilder::class)]
final class JsonPointerBuilderTest extends TestCase
{
    #[Test]
    #[DataProvider('keys')]
    public function itMapsALaravelErrorKeyToAnAttributePointer(string $key, string $expected): void
    {
        self::assertSame($expected, (new JsonPointerBuilder())->forAttribute($key));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function keys(): iterable
    {
        yield 'top-level attribute' => ['title', '/data/attributes/title'];
        yield 'nested map child' => ['address.postcode', '/data/attributes/address/postcode'];
        yield 'array element' => ['tags.0', '/data/attributes/tags/0'];
        yield 'deep nesting' => ['a.b.c', '/data/attributes/a/b/c'];
        yield 'document level (empty)' => ['', '/data/attributes'];
        yield 'RFC 6901 escaping of tilde' => ['a~b', '/data/attributes/a~0b'];
        yield 'RFC 6901 escaping of slash' => ['a/b', '/data/attributes/a~1b'];
    }
}
