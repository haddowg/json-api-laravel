<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\IdKinds;

use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\IdBuilder;
use haddowg\JsonApiLaravel\Eloquent\BinaryUuidEncoder;
use haddowg\JsonApiLaravel\Tests\Fixtures\MorphKeys\PrefixCodec;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Ramsey\Uuid\Uuid;

/**
 * The key kind the id-kinds fixture runs with, read from `id_kinds.kind`, and the wire id and
 * stored key of each seeded row under it.
 *
 * - `int`: an auto-incrementing integer key.
 * - `string`: a plain string key.
 * - `uuid` and `ulid`: `HasUuids` and `HasUlids` text keys.
 * - `binary`: a `HasUuids` key held as 16 bytes behind `BinaryUuidEncoder`, with the
 *   field-only foreign key `writer_ref` cast to a UUID object that stores the bytes.
 * - `prefix`: an integer key served as `<prefix><key>` by an id encoder.
 * - `object`: a text key and foreign keys cast to a `Stringable` value object.
 */
final class IdKinds
{
    public const array KINDS = ['int', 'string', 'uuid', 'ulid', 'binary', 'prefix', 'object'];

    /**
     * Each row's wire id per kind, by row reference.
     */
    private const array WIRE = [
        'int' => ['w1' => '1', 'w2' => '2', 'w3' => '3', 'w9' => '9', 'b1' => '1', 'b2' => '2', 'b3' => '3', 'b4' => '4', 'l1' => '1', 'l2' => '2', 'l3' => '3'],
        'string' => ['w1' => 'ada', 'w2' => 'bronte', 'w3' => 'cleo', 'w9' => 'zed', 'b1' => 'dune', 'b2' => 'emma', 'b3' => 'ulysses', 'b4' => 'middlemarch', 'l1' => 'classic', 'l2' => 'scifi', 'l3' => 'secret'],
        'uuid' => self::UUIDS,
        'binary' => self::UUIDS,
        'object' => self::UUIDS,
        'ulid' => [
            'w1' => '01jd5w8m2a3b4c5d6e7f8g9hw1', 'w2' => '01jd5w8m2a3b4c5d6e7f8g9hw2', 'w3' => '01jd5w8m2a3b4c5d6e7f8g9hw3', 'w9' => '01jd5w8m2a3b4c5d6e7f8g9hw9',
            'b1' => '01jd5w8m2a3b4c5d6e7f8g9hb1', 'b2' => '01jd5w8m2a3b4c5d6e7f8g9hb2', 'b3' => '01jd5w8m2a3b4c5d6e7f8g9hb3', 'b4' => '01jd5w8m2a3b4c5d6e7f8g9hb4',
            'l1' => '01jd5w8m2a3b4c5d6e7f8g9hc1', 'l2' => '01jd5w8m2a3b4c5d6e7f8g9hc2', 'l3' => '01jd5w8m2a3b4c5d6e7f8g9hc3',
        ],
        'prefix' => ['w1' => 'w-1', 'w2' => 'w-2', 'w3' => 'w-3', 'w9' => 'w-9', 'b1' => 'b-1', 'b2' => 'b-2', 'b3' => 'b-3', 'b4' => 'b-4', 'l1' => 'l-1', 'l2' => 'l-2', 'l3' => 'l-3'],
    ];

    private const array UUIDS = [
        'w1' => '0b6a1c2e-6a8f-4d55-9a6e-1f2d3c4b5a61', 'w2' => '0b6a1c2e-6a8f-4d55-9a6e-1f2d3c4b5a62', 'w3' => '0b6a1c2e-6a8f-4d55-9a6e-1f2d3c4b5a63', 'w9' => '0b6a1c2e-6a8f-4d55-9a6e-1f2d3c4b5a69',
        'b1' => '1c7b2d3f-7b9a-4e66-8b7f-2a3e4d5c6b71', 'b2' => '1c7b2d3f-7b9a-4e66-8b7f-2a3e4d5c6b72', 'b3' => '1c7b2d3f-7b9a-4e66-8b7f-2a3e4d5c6b73', 'b4' => '1c7b2d3f-7b9a-4e66-8b7f-2a3e4d5c6b74',
        'l1' => '2d8c3e4a-8cab-4f77-9c8a-3b4f5e6d7c81', 'l2' => '2d8c3e4a-8cab-4f77-9c8a-3b4f5e6d7c82', 'l3' => '2d8c3e4a-8cab-4f77-9c8a-3b4f5e6d7c83',
    ];

    private const array PREFIXES = ['ikWriters' => 'w-', 'ikBooks' => 'b-', 'ikLabels' => 'l-'];

    public static function kind(): string
    {
        $kind = config('id_kinds.kind');

        return \is_string($kind) && \in_array($kind, self::KINDS, true) ? $kind : 'int';
    }

    /**
     * The wire id of the row `$ref` names, such as `w1`.
     */
    public static function wire(string $ref): string
    {
        return self::WIRE[self::kind()][$ref];
    }

    /**
     * The value the database holds for the row `$ref` names.
     */
    public static function stored(string $ref): int|string
    {
        $wire = self::wire($ref);

        return match (self::kind()) {
            'int' => (int) $wire,
            'prefix' => (int) \substr($wire, 2),
            'binary' => Uuid::fromString($wire)->getBytes(),
            default => $wire,
        };
    }

    /**
     * A new key for a `HasUuids` or `HasUlids` model.
     */
    public static function mint(): string
    {
        if (self::kind() === 'ulid') {
            return \strtolower((string) Str::ulid());
        }
        $uuid = Str::orderedUuid();

        return self::kind() === 'binary' ? $uuid->getBytes() : $uuid->toString();
    }

    public static function integerKeys(): bool
    {
        return \in_array(self::kind(), ['int', 'prefix'], true);
    }

    /**
     * Whether a create with no client id mints a key.
     */
    public static function mintsKeys(): bool
    {
        return !\in_array(self::kind(), ['string', 'object'], true);
    }

    /**
     * The id field of `$type` under the current kind.
     */
    public static function id(string $type): IdBuilder
    {
        // The in-memory witness holds wire ids, so it serves them with no encoder.
        if (config('id_kinds.store') === 'memory') {
            return match (self::kind()) {
                'int' => Id::make()->numeric(),
                'uuid', 'object', 'binary' => Id::make()->uuid(),
                'ulid' => Id::make()->ulid(),
                default => Id::make(),
            };
        }

        return match (self::kind()) {
            'int' => Id::make()->numeric(),
            'string' => Id::make()->pattern('[a-z]+'),
            'uuid', 'object' => Id::make()->uuid(),
            'ulid' => Id::make()->ulid(),
            'binary' => Id::make()->uuid()->encodeUsing(new BinaryUuidEncoder()),
            default => Id::make()->encodeUsing(new PrefixCodec(self::PREFIXES[$type] ?? '')),
        };
    }

    /**
     * The model class serving `$role`, `writer`, `book` or `label`, under the current kind.
     *
     * @return class-string<Model>
     */
    public static function model(string $role): string
    {
        return match ([self::kind(), $role]) {
            ['uuid', 'writer'], ['binary', 'writer'] => IkUuidWriter::class,
            ['uuid', 'book'], ['binary', 'book'] => IkUuidBook::class,
            ['uuid', 'label'], ['binary', 'label'] => IkUuidLabel::class,
            ['ulid', 'writer'] => IkUlidWriter::class,
            ['ulid', 'book'] => IkUlidBook::class,
            ['ulid', 'label'] => IkUlidLabel::class,
            default => match ($role) {
                'writer' => IkWriter::class,
                'book' => IkBook::class,
                default => IkLabel::class,
            },
        };
    }
}
