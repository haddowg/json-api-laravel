<?php

declare(strict_types=1);

namespace Workbench\App\Surface;

use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\BelongsTo;
use haddowg\JsonApi\Resource\Field\DateTime;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApiLaravel\Attribute\AsJsonApiResource;

/**
 * The writable `albums` type for the Phase-4 surface suites (custom actions + Atomic
 * Operations), lightweight and provider-agnostic (its columns resolve off both the
 * in-memory {@see \Workbench\App\Domain\Album} POPO and the Eloquent
 * {@see \Workbench\App\Models\Album} model). It carries the `artist` to-one so an atomic
 * batch can create an artist and, in the same batch, create an album that references it by
 * local id (the headline cross-store lid case).
 *
 * It lives outside the scanned `app/JsonApi` path (registered explicitly by the surface
 * wirings) so it never collides with the music-suite `albums` resource.
 */
#[AsJsonApiResource(tags: ['Catalog'])]
final class AlbumResource extends AbstractResource
{
    public static string $type = 'albums';

    public function fields(): array
    {
        return [
            Id::make(),
            // Not `required()`: the Document-input `publish` action validates the `albums`
            // inputType in the create context sending only `status`, and the atomic album
            // creates always send a title anyway — so leaving title optional keeps the
            // surface suite's write documents minimal without a spurious 422.
            Str::make('title')->maxLength(200),
            Str::make('status'),
            // The Eloquent `albums.released_at` column is NOT NULL, so the atomic album
            // creates send it; the in-memory POPO's column is nullable, so it is harmless
            // there. The `publish` action's Document input (status only) does not require it.
            DateTime::make('releasedAt')->storedAs('released_at'),
            BelongsTo::make('artist', 'artists'),
        ];
    }
}
