<?php

declare(strict_types=1);

namespace Workbench\App\JsonApi;

use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApiLaravel\Attribute\AsJsonApiResource;

/**
 * The `genres` resource type — the smallest read-only type (the client-id strategy and
 * id route pattern are deferred to a later phase, so Phase 0 keeps a plain `Id`).
 */
#[AsJsonApiResource(readOnly: true)]
final class GenreResource extends AbstractResource
{
    public static string $type = 'genres';

    public function fields(): array
    {
        return [
            Id::make(),
            Str::make('name')->required(),
        ];
    }
}
