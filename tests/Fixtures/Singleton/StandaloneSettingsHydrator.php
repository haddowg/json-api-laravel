<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\Singleton;

use haddowg\JsonApi\Hydrator\HydratorInterface;
use haddowg\JsonApi\Request\JsonApiRequestInterface;
use haddowg\JsonApiLaravel\Attribute\AsJsonApiHydrator;

/**
 * The write half of {@see StandaloneSettingsSerializer}, which makes its update routable.
 *
 * @internal
 */
#[AsJsonApiHydrator(type: 'standaloneSettings')]
final class StandaloneSettingsHydrator implements HydratorInterface
{
    public function hydrate(JsonApiRequestInterface $request, mixed $domainObject): mixed
    {
        return $domainObject;
    }
}
