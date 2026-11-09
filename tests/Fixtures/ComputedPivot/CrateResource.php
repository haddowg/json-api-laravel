<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\ComputedPivot;

use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\Accessor;
use haddowg\JsonApi\Resource\Field\BelongsToMany;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Integer;
use haddowg\JsonApi\Resource\Field\PivotEdge;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApiLaravel\Attribute\AsJsonApiResource;
use haddowg\JsonApiLaravel\Operation\Operation;

/**
 * A `crates` view over the workbench playlists whose pivot fields are computed from the
 * playlist and the track. `labelledTracks` runs over the bare `playlist_track_plain` join,
 * which has no pivot columns, and every field it declares is computed; `mixedTracks` runs
 * over `playlist_track` and pairs a stored `position` with a computed `label`.
 *
 * @internal
 */
#[AsJsonApiResource(operations: [Operation::FetchOne, Operation::Update])]
final class CrateResource extends AbstractResource
{
    public static string $type = 'crates';

    public function fields(): array
    {
        return [
            Id::make()->build(),
            Str::make('title')->build(),
            BelongsToMany::make('labelledTracks', 'tracks')
                ->storedAs('tracks')
                ->fields(
                    Str::make('label')->computedUsing(self::label(...)),
                    // Computed through extractUsing alone, so it is never written either.
                    Integer::make('rank')->extractUsing(static function (mixed $edge): ?int {
                        $id = $edge instanceof PivotEdge ? Accessor::get($edge->related, 'id') : null;

                        return \is_numeric($id) ? (int) $id * 10 : null;
                    }),
                ),
            BelongsToMany::make('mixedTracks', 'tracks')
                ->storedAs('orderedTracks')
                ->fields(
                    Integer::make('position'),
                    Str::make('label')->computedUsing(self::label(...)),
                ),
        ];
    }

    private static function label(mixed $edge): string
    {
        if (!$edge instanceof PivotEdge) {
            return '';
        }
        $playlist = Accessor::get($edge->parent, 'title');
        $track = Accessor::get($edge->related, 'title');

        return (\is_string($playlist) ? $playlist : '') . ' / ' . (\is_string($track) ? $track : '');
    }
}
