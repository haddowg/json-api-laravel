<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\RestrictedPivot;

use haddowg\JsonApi\Request\JsonApiRequestInterface;
use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\Accessor;
use haddowg\JsonApi\Resource\Field\BelongsToMany;
use haddowg\JsonApi\Resource\Field\DateTime;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Integer;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApiLaravel\Attribute\AsJsonApiResource;
use haddowg\JsonApiLaravel\Operation\Operation;

/**
 * A `mixtapes` view over the workbench playlists whose pivot fields restrict what a response
 * may show: `orderedTracks` declares a write-only `weight` and a hidden `addedAt`;
 * `rankedTracks` (the same join) hides `position` for track 2 and makes `weight` write-only
 * while the request carries an `X-Conceal-Weight` header.
 *
 * @internal
 */
#[AsJsonApiResource(operations: [Operation::FetchOne, Operation::Update])]
final class MixtapeResource extends AbstractResource
{
    public static string $type = 'mixtapes';

    public function fields(): array
    {
        return [
            Id::make()->build(),
            Str::make('title')->build(),
            BelongsToMany::make('orderedTracks', 'tracks')->fields(
                Integer::make('position')->build(),
                Integer::make('weight')->writeOnly()->build(),
                DateTime::make('addedAt')->storedAs('added_at')->hidden()->build(),
            ),
            BelongsToMany::make('rankedTracks', 'tracks')
                ->storedAs('orderedTracks')
                ->fields(
                    Integer::make('position')
                        ->hidden(static fn(mixed $track): bool => Accessor::get($track, 'id') === 2)
                        ->build(),
                    Integer::make('weight')
                        ->writeOnly(static fn(JsonApiRequestInterface $request): bool => \request()->hasHeader('X-Conceal-Weight'))
                        ->build(),
                    DateTime::make('addedAt')->storedAs('added_at')->build(),
                ),
        ];
    }
}
