<?php

declare(strict_types=1);

namespace Workbench\App\JsonApi;

use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\Boolean;
use haddowg\JsonApi\Resource\Field\Date;
use haddowg\JsonApi\Resource\Field\DateTime;
use haddowg\JsonApi\Resource\Field\Decimal;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApiLaravel\Attribute\AsJsonApiResource;

/**
 * The `albums` resource type, re-themed from the Symfony bundle's example (relations,
 * the `Map`, `compareWith`, filters and default sort dropped — those are Phase 1/3).
 * `readOnly: true` exposes only the two read endpoints.
 */
#[AsJsonApiResource(readOnly: true)]
final class AlbumResource extends AbstractResource
{
    public static string $type = 'albums';

    public function fields(): array
    {
        return [
            Id::make(),
            Str::make('title')->required()->maxLength(200)->sortable(),
            Decimal::make('averageRating')->readOnly()->nullable(),
            Str::make('status')->sortable(),
            Boolean::make('explicit'),
            Date::make('availableFrom')->nullable(),
            DateTime::make('releasedAt')->sortable(),
        ];
    }
}
