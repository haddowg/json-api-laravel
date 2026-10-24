<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\WriteOnlyRelations;

use haddowg\JsonApi\Request\JsonApiRequestInterface;
use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\BelongsTo;
use haddowg\JsonApi\Resource\Field\BelongsToMany;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Integer;
use haddowg\JsonApi\Resource\Field\Str;

/**
 * `boxes`: a readable `keeper`, a write-only `secretKeeper` to-one, a write-only `stickers`
 * pivot to-many that would otherwise render its data on every read, and `badges`, write-only
 * only when the request carries an `X-Hide-Badges` header.
 */
final class BoxResource extends AbstractResource
{
    public static string $type = 'boxes';

    public function fields(): array
    {
        return [
            Id::make()->build(),
            Str::make('label'),
            BelongsTo::make('keeper', 'keepers'),
            BelongsTo::make('secretKeeper', 'keepers')->writeOnly(),
            BelongsToMany::make('stickers', 'stickers')
                ->withData()
                ->writeOnly()
                ->fields(Integer::make('position')->build()),
            BelongsToMany::make('badges', 'stickers')
                ->withData()
                ->writeOnly(static fn(JsonApiRequestInterface $request): bool => $request->getHeaderLine('X-Hide-Badges') !== ''),
        ];
    }
}
