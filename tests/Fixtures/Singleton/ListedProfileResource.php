<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\Singleton;

use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\SingletonContext;
use haddowg\JsonApiLaravel\Attribute\AsJsonApiResource;
use haddowg\JsonApiLaravel\Operation\Operation;

/**
 * A singleton declaring a collection fetch, which it cannot serve.
 *
 * @internal
 */
#[AsJsonApiResource(operations: [Operation::FetchCollection, Operation::FetchOne])]
final class ListedProfileResource extends AbstractResource
{
    public static string $type = 'listedProfiles';

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
