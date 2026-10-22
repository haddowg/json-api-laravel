<?php

declare(strict_types=1);

namespace Workbench\App\Timezone;

use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApiLaravel\Attribute\AsJsonApiResource;

#[AsJsonApiResource(readOnly: true, server: ['default', 'admin'])]
final class PerformerResource extends AbstractResource
{
    public static string $type = 'performers';

    public function fields(): array
    {
        return [
            Id::make(),
            Str::make('name'),
        ];
    }
}
