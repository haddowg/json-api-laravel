<?php

declare(strict_types=1);

namespace Workbench\App\Extensions;

use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApiLaravel\Attribute\AsJsonApiResource;

/**
 * A type declaring no vendor extensions, so its operations project without any.
 */
#[AsJsonApiResource(readOnly: true)]
final class MakerResource extends AbstractResource
{
    public static string $type = 'makers';

    public function fields(): array
    {
        return [
            Id::make(),
            Str::make('name'),
        ];
    }
}
