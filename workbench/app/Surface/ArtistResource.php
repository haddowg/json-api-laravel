<?php

declare(strict_types=1);

namespace Workbench\App\Surface;

use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApiLaravel\Attribute\AsJsonApiResource;

/**
 * The writable `artists` type for the Phase-4 surface suites — the target an atomic batch
 * creates first (assigning it a local id) so a following album `add` can reference it by
 * `lid`. Provider-agnostic (its columns resolve off both the in-memory
 * {@see \Workbench\App\Domain\Artist} POPO and the Eloquent {@see \Workbench\App\Models\Artist}
 * model), registered explicitly so it never collides with the read-suite (read-only)
 * `artists` resource.
 */
#[AsJsonApiResource]
final class ArtistResource extends AbstractResource
{
    public static string $type = 'artists';

    public function fields(): array
    {
        return [
            Id::make(),
            Str::make('name')->required()->maxLength(120),
            Str::make('slug'),
        ];
    }
}
