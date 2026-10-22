<?php

declare(strict_types=1);

namespace Workbench\App\Timezone;

use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\DateTime;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApiLaravel\Attribute\AsJsonApiResource;

#[AsJsonApiResource(readOnly: true, server: ['default', 'admin'])]
final class VenueResource extends AbstractResource
{
    public static string $type = 'venues';

    public function fields(): array
    {
        return [
            Id::make(),
            Str::make('name'),
            DateTime::make('openedAt')->storedAs('opened_at'),
        ];
    }
}
