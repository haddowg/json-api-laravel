<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\NestedFieldsetOverrides;

use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApiLaravel\Attribute\AsJsonApiResource;

/**
 * @internal
 */
#[AsJsonApiResource(serializer: BoardSerializer::class, readOnly: true)]
final class BoardResource extends AbstractResource
{
    public static string $type = 'boards';

    public function fields(): array
    {
        return BoardFields::fields();
    }
}
