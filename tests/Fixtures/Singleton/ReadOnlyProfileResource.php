<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\Singleton;

use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\SingletonContext;
use haddowg\JsonApiLaravel\Attribute\AsJsonApiResource;

/**
 * A read-only singleton: fetch alone.
 *
 * @internal
 */
#[AsJsonApiResource(readOnly: true)]
final class ReadOnlyProfileResource extends AbstractResource
{
    public static string $type = 'readOnlyProfiles';

    public static bool $singleton = true;

    public function fields(): array
    {
        return [Id::make()];
    }

    public function resolveSingletonId(SingletonContext $context): string
    {
        return '1';
    }
}
