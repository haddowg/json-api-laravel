<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\Singleton;

use haddowg\JsonApi\Request\JsonApiRequestInterface;
use haddowg\JsonApi\Resource\SingletonContext;
use haddowg\JsonApi\Resource\SingletonResourceInterface;
use haddowg\JsonApi\Schema\Link\ResourceLinks;
use haddowg\JsonApi\Serializer\AbstractSerializer;
use haddowg\JsonApiLaravel\Attribute\AsJsonApiSerializer;

/**
 * A standalone serializer declaring its type a singleton, so it serves `GET /standaloneSettings`.
 *
 * @internal
 */
#[AsJsonApiSerializer(type: 'standaloneSettings')]
final class StandaloneSettingsSerializer extends AbstractSerializer implements SingletonResourceInterface
{
    public static function isSingleton(): bool
    {
        return true;
    }

    public function resolveSingletonId(SingletonContext $context): string
    {
        return '1';
    }

    public function getType(mixed $object): string
    {
        return 'standaloneSettings';
    }

    public function getId(mixed $object): string
    {
        return '1';
    }

    public function getMeta(mixed $object, JsonApiRequestInterface $request): array
    {
        return [];
    }

    public function getLinks(mixed $object, JsonApiRequestInterface $request): ?ResourceLinks
    {
        return null;
    }

    public function getAttributes(mixed $object, JsonApiRequestInterface $request): array
    {
        return [];
    }

    public function getDefaultIncludedRelationships(mixed $object): array
    {
        return [];
    }

    public function getRelationships(mixed $object, JsonApiRequestInterface $request): array
    {
        return [];
    }
}
