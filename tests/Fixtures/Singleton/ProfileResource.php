<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\Singleton;

use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\SingletonContext;
use haddowg\JsonApiLaravel\Attribute\AsJsonApiResource;

/**
 * A singleton with no allow-list of its own: fetch and update.
 *
 * @internal
 */
#[AsJsonApiResource]
final class ProfileResource extends AbstractResource
{
    public static string $type = 'profiles';

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
