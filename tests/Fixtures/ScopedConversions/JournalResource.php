<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\ScopedConversions;

use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApi\Resource\Field\StrBuilder;

/**
 * A board seen as a journal. Its `mood` converts to the enum only on a create and its
 * `name` is upper-cased only on an update, each declared inside a scope. A hook records
 * what each write's `mood` arrived as.
 *
 * @internal
 */
final class JournalResource extends AbstractResource
{
    public static string $type = 'journals';

    /**
     * The PHP type of each `mood` written, in write order.
     *
     * @var list<string>
     */
    public static array $moods = [];

    public function fields(): array
    {
        return [
            Id::make(),
            Str::make('name')->onUpdate(static fn(StrBuilder $field) => $field->deserializeUsing(static fn(mixed $value): string => \strtoupper(\is_string($value) ? $value : ''))),
            Str::make('mood')
                ->onCreate(static fn(StrBuilder $field) => $field->enum(Mood::class))
                ->fillUsing(static function (mixed $journal, Mood|string $mood): mixed {
                    self::$moods[] = \get_debug_type($mood);

                    return $journal;
                }),
        ];
    }
}
