<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\ThroughPivot;

use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\HasMany;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApi\Resource\Filter\DateRange;
use haddowg\JsonApi\Resource\Filter\Where;
use haddowg\JsonApiLaravel\Attribute\AsJsonApiResource;

/**
 * Albums filtered through their tracks' playlist pivot rows, a `pivot` in the middle of the
 * path.
 */
#[AsJsonApiResource(readOnly: true)]
final class TpAlbumResource extends AbstractResource
{
    public static string $type = 'tpAlbums';

    public function fields(): array
    {
        return [
            Id::make(),
            Str::make('title'),
            HasMany::make('tracks', 'tpTracks'),
        ];
    }

    public function filters(): array
    {
        $addedAt = DateRange::make('addedAt', 'addedAt')->through('tracks.playlists.pivot');

        // An in-memory store can't name the relations a pivot row holds.
        if (config('through_pivot.store') === 'memory') {
            return match (config('through_pivot.memory_probe')) {
                'default' => [$addedAt, Where::make('position', 'position')->through('tracks.playlists.pivot')->integer()->default(1)],
                'past_pivot' => [$addedAt, Where::make('adder', 'name')->through('tracks.playlists.pivot.addedBy')],
                default => [$addedAt],
            };
        }

        return [
            $addedAt,
            Where::make('adder', 'name')->through('tracks.playlists.pivot.addedBy'),
            Where::make('auditor', 'name')->through('tracks.playlists.pivot.auditor'),
        ];
    }
}
