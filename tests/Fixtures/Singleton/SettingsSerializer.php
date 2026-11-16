<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\Singleton;

use haddowg\JsonApi\Request\JsonApiRequestInterface;
use haddowg\JsonApi\Resource\SingletonContext;
use haddowg\JsonApi\Resource\SingletonResourceInterface;
use haddowg\JsonApi\Schema\Link\ResourceLinks;
use haddowg\JsonApi\Serializer\AbstractSerializer;

/**
 * The serializer {@see OverriddenProfileResource} renders through, declaring the type a singleton.
 *
 * @internal
 */
final class SettingsSerializer extends AbstractSerializer implements SingletonResourceInterface
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
        return 'settings';
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
