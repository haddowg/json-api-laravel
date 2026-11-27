<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\NestedPivot;

use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\IdBuilder;
use haddowg\JsonApiLaravel\Eloquent\BinaryUuidEncoder;
use haddowg\JsonApiLaravel\Tests\Fixtures\MorphKeys\PrefixCodec;

/**
 * The key kind the crates and records are stored under, set by `nested_pivot.keys`: plain
 * integers, uuids stored in capitals, integers served through a prefix encoder, 16-byte
 * binary uuids served through {@see BinaryUuidEncoder}, or uuids the models cast to a value
 * object.
 *
 * @internal
 */
final class NpKeys
{
    public static function kind(): string
    {
        $kind = config('nested_pivot.keys');

        return \is_string($kind) && \in_array($kind, ['uuid', 'prefix', 'binary', 'object'], true) ? $kind : 'int';
    }

    /**
     * Whether the key is a string the model doesn't increment.
     */
    public static function stringKeys(): bool
    {
        return \in_array(self::kind(), ['uuid', 'binary', 'object'], true);
    }

    /**
     * The id field of a crate (`c`) or a record (`r`).
     */
    public static function id(string $role): IdBuilder
    {
        return match (self::kind()) {
            'prefix' => Id::make()->encodeUsing(new PrefixCodec($role . '-')),
            'binary' => Id::make()->uuid()->encodeUsing(new BinaryUuidEncoder()),
            default => Id::make(),
        };
    }

    /**
     * The wire id of crate (`c`) or record (`r`) `$n`.
     */
    public static function wire(string $role, int $n): string
    {
        return match (self::kind()) {
            'uuid' => \strtoupper(self::uuid($role, $n)),
            'prefix' => $role . '-' . $n,
            'binary', 'object' => self::uuid($role, $n),
            default => (string) $n,
        };
    }

    /**
     * The key crate (`c`) or record (`r`) `$n` is stored under.
     */
    public static function stored(string $role, int $n): int|string
    {
        return match (self::kind()) {
            'uuid' => \strtoupper(self::uuid($role, $n)),
            'binary' => (string) \hex2bin(\str_replace('-', '', self::uuid($role, $n))),
            'object' => self::uuid($role, $n),
            default => $n,
        };
    }

    private static function uuid(string $role, int $n): string
    {
        return \sprintf('%s0b6a1c2-6a8f-4d55-9a6e-1f2d3c4b5a%02d', $role === 'c' ? 'c' : 'd', $n);
    }
}
