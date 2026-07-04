<?php

declare(strict_types=1);

namespace Workbench\App\Pivot;

use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\BelongsToMany;
use haddowg\JsonApi\Resource\Field\DateTime;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApiLaravel\Attribute\AsJsonApiResource;

/**
 * The `tracks` resource type (Phase 3b) — the far side of the playlist pivot relation,
 * shared by BOTH provider suites. Read-only: tracks are rendered as the related members of a
 * playlist's `orderedTracks`/`tracks` and (via {@see PlaylistResource}) written only through
 * the playlist's relationship endpoints. `releasedAt` is a sortable column (its `storedAs`
 * resolves off the POPO property and the Eloquent cast alike) so a windowed/ordered related
 * fetch has a deterministic key.
 */
#[AsJsonApiResource(readOnly: true)]
final class TrackResource extends AbstractResource
{
    public static string $type = 'tracks';

    public function fields(): array
    {
        return [
            Id::make(),
            Str::make('title')->required()->sortable(),
            DateTime::make('releasedAt')->storedAs('released_at')->nullable()->sortable(),
            // The inverse membership — a track's playlists (lazy linkage). The relation name
            // IS the Eloquent relation method / in-memory POPO property (`playlists`).
            BelongsToMany::make('playlists', 'playlists'),
        ];
    }
}
