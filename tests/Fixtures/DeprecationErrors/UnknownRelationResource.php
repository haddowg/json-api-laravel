<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\DeprecationErrors;

use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApiLaravel\Attribute\AsJsonApiResource;

/**
 * Deprecates the related endpoint of a relation the type does not have, which only core's
 * projection can catch.
 *
 * @internal
 */
#[AsJsonApiResource(readOnly: true, deprecatedOperations: ['fetchRelated.owner'])]
final class UnknownRelationResource extends AbstractResource
{
    public static string $type = 'gadgets';

    public function fields(): array
    {
        return [Id::make()];
    }
}
