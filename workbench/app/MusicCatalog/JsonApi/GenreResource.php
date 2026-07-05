<?php

declare(strict_types=1);

namespace Workbench\App\MusicCatalog\JsonApi;

use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApiLaravel\Attribute\AsJsonApiResource;

/**
 * The `genres` resource type (music-catalog domain) — the client-supplied natural-key
 * id strategy (`requireClientId()` + a lowercase-slug `pattern()`), and the declarative
 * HTTP cache-header witness: a one-hour client / one-day CDN lifetime, `public`, `Vary:
 * Accept`, with a shorter five-minute `max_age` for the collection read shape.
 */
#[AsJsonApiResource(
    cacheHeaders: [
        'max_age' => 3600,
        's_maxage' => 86400,
        'public' => true,
        'vary' => ['Accept'],
        'operations' => [
            'collection' => ['max_age' => 300],
        ],
    ],
)]
final class GenreResource extends AbstractResource
{
    public static string $type = 'genres';

    public function fields(): array
    {
        return [
            Id::make()->requireClientId()->pattern('^[a-z0-9]+(?:-[a-z0-9]+)*$'),
            Str::make('name')->required(),
        ];
    }
}
