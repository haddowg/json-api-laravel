<?php

declare(strict_types=1);

namespace Workbench\App\JsonApi;

use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApiLaravel\Attribute\AsJsonApiResource;

/**
 * The `genres` resource type — a **natural string-key** id (`trip-hop`). Phase 2 makes it
 * writable on both providers with the **client-generated-id** strategy: because a genre's
 * id is its own natural key (no server sequence to fall back on), the `Id` field
 * `requireClientId()`s a `data.id` on create — so a `POST /genres` with an id round-trips
 * to a `201` carrying it, while omitting the id is a `403` (`ClientGeneratedIdRequired`).
 * It is the client-id counterpart to the server-generated-id `albums` type.
 */
#[AsJsonApiResource]
final class GenreResource extends AbstractResource
{
    public static string $type = 'genres';

    public function fields(): array
    {
        return [
            Id::make()->requireClientId(),
            Str::make('name')->required(),
        ];
    }
}
