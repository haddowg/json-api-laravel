<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\KeySelectionOverrides;

use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApiLaravel\Attribute\AsJsonApiResource;

/**
 * @internal
 */
#[AsJsonApiResource(serializer: EaselSerializer::class, readOnly: true)]
final class EaselResource extends AbstractResource
{
    public static string $type = 'easels';

    public function fields(): array
    {
        return BoardFields::fields();
    }
}
