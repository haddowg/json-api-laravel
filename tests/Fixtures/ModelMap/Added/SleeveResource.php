<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\ModelMap\Added;

use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApiLaravel\Attribute\AsJsonApiResource;
use haddowg\JsonApiLaravel\Tests\Fixtures\ModelMap\Models\VinylRecord;

/**
 * A resource added to the app after a first `jsonapi:optimize`, so a second run has to
 * pick it up rather than rewrite the snapshot it already has.
 *
 * @internal
 */
#[AsJsonApiResource(model: VinylRecord::class)]
final class SleeveResource extends AbstractResource
{
    public static string $type = 'sleeves';

    public function fields(): array
    {
        return [
            Id::make(),
            Str::make('title'),
        ];
    }
}
