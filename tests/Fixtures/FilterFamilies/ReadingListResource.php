<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\FilterFamilies;

use haddowg\JsonApi\Request\JsonApiRequestInterface;
use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\BelongsToMany;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Integer;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApi\Resource\Filter\FilterFamily;
use haddowg\JsonApi\Resource\Filter\FilterInterface;
use haddowg\JsonApi\Resource\Filter\Where;

/**
 * `rankedBooks` carries a `rank` pivot column, filtered by the exact `rank` key and by the
 * `join.rank` key its `join` family resolves to the same pivot filter.
 */
final class ReadingListResource extends AbstractResource
{
    public static string $type = 'readingLists';

    public function fields(): array
    {
        return [
            Id::make(),
            Str::make('name'),
            BelongsToMany::make('rankedBooks', 'books')
                ->fields(Integer::make('rank')->build())
                ->withFilters(
                    Where::make('rank', 'pivot.rank')->integer(),
                    FilterFamily::make('join', static fn(string $suffix, JsonApiRequestInterface $request): ?FilterInterface => $suffix === 'rank'
                        ? Where::make('join.rank', 'pivot.rank')->integer()->build()
                        : null),
                ),
        ];
    }
}
