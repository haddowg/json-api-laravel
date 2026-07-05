<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\ModelMap\JsonApi;

use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApiLaravel\Attribute\AsJsonApiResource;
use haddowg\JsonApiLaravel\Tests\Fixtures\ModelMap\Models\VinylRecord;

/**
 * The attribute-tier witness (ADR 0019): the `recordings` type's model name diverges
 * from the convention guess (`Recording` does not exist), so
 * `#[AsJsonApiResource(model: VinylRecord::class)]` is what maps it — the Laravel twin
 * of the bundle's `#[AsJsonApiResource(entity: …)]`.
 *
 * @internal
 */
#[AsJsonApiResource(model: VinylRecord::class)]
final class RecordingResource extends AbstractResource
{
    public static string $type = 'recordings';

    public function fields(): array
    {
        return [
            Id::make(),
            Str::make('title')->required(),
        ];
    }
}
