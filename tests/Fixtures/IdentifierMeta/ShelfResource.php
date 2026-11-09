<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\IdentifierMeta;

use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\Accessor;
use haddowg\JsonApi\Resource\Field\BelongsTo;
use haddowg\JsonApi\Resource\Field\BelongsToMany;
use haddowg\JsonApi\Resource\Field\Boolean;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Integer;
use haddowg\JsonApi\Resource\Field\Str;

/**
 * `shelves`: a `curator` to-one and a `books` pivot to-many, each declaring the members its
 * identifier meta resolver adds. The values come from the resolver; the fields only type them.
 */
final class ShelfResource extends AbstractResource
{
    public static string $type = 'shelves';

    public function fields(): array
    {
        return [
            Id::make()->build(),
            Str::make('label'),
            BelongsTo::make('curator', 'curators')
                ->withData()
                ->identifierMeta(
                    static fn(mixed $shelf, mixed $curator): array => ['role' => 'curator of ' . self::text($shelf, 'label')],
                    fields: [Str::make('role')->guaranteed()],
                ),
            BelongsToMany::make('books', 'books')
                ->withData()
                ->fields(Integer::make('position')->build())
                ->identifierMeta(
                    static fn(mixed $shelf, mixed $book): array => [
                        'slot' => self::text($shelf, 'id') . '-' . self::text($book, 'id'),
                        'featured' => self::text($book, 'id') === '1',
                    ],
                    fields: [Str::make('slot')->guaranteed(), Boolean::make('featured')],
                ),
        ];
    }

    private static function text(mixed $model, string $member): string
    {
        $value = Accessor::get($model, $member);

        return \is_scalar($value) ? (string) $value : '';
    }
}
