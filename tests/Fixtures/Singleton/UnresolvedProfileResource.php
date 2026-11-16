<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\Singleton;

use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApiLaravel\Attribute\AsJsonApiResource;

/**
 * A singleton that never says how to resolve the caller's id.
 *
 * @internal
 */
#[AsJsonApiResource]
final class UnresolvedProfileResource extends AbstractResource
{
    public static string $type = 'unresolvedProfiles';

    public static bool $singleton = true;

    public function fields(): array
    {
        return [Id::make()];
    }
}
