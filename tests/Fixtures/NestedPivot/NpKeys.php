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
 * binary uuids served through {@see BinaryUuidEncoder}, uuids the models cast to a value
 * object, or `nocase`: uuids stored in capitals whose join rows hold them in lower case, in
 * columns that compare without case, as do the records' keys the joins read through.
 *
 * @internal
 */
final class NpKeys
{
    public static function kind(): string
    {
        $kind = config('nested_pivot.keys');

        return \is_string($kind) && \in_array($kind, ['uuid', 'prefix', 'binary', 'object', 'nocase'], true) ? $kind : 'int';
    }

    /**
     * Whether the key is a string the model doesn't increment.
     */
    public static function stringKeys(): bool
    {
        return \in_array(self::kind(), ['uuid', 'binary', 'object', 'nocase'], true);
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
            'prefix' => $role . '-' . $n,
            'uuid', 'binary', 'object', 'nocase' => self::uuid($role, $n),
            default => (string) $n,
        };
    }

    /**
     * The key crate (`c`) or record (`r`) `$n` is stored under.
     */
    public static function stored(string $role, int $n): int|string
    {
        return match (self::kind()) {
            'uuid', 'nocase' => \strtoupper(self::uuid($role, $n)),
            'binary' => (string) \hex2bin(\str_replace('-', '', self::uuid($role, $n))),
            'object' => self::uuid($role, $n),
            default => $n,
        };
    }

    /**
     * The key a join row holds for crate (`c`) or record (`r`) `$n`: the stored key, or in
     * lower case for `nocase`.
     */
    public static function joined(string $role, int $n): int|string
    {
        return self::kind() === 'nocase' ? self::uuid($role, $n) : self::stored($role, $n);
    }

    /**
     * How a room's `pinned` pair names crate `$n`: by the key it is stored under, as a uuid
     * stored in capitals, or by its wire id, which the in-memory stores and an encoder take.
     */
    public static function pinned(int $n): string
    {
        return \in_array(self::kind(), ['uuid', 'nocase'], true) && config('nested_pivot.store') === null ? (string) self::stored('c', $n) : self::wire('c', $n);
    }

    private static function uuid(string $role, int $n): string
    {
        return \sprintf('%s0b6a1c2-6a8f-4d55-9a6e-1f2d3c4b5a%02d', $role === 'c' ? 'c' : 'd', $n);
    }
}
