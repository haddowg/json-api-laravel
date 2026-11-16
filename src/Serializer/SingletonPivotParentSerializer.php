<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Serializer;

use haddowg\JsonApi\Request\JsonApiRequestInterface;
use haddowg\JsonApi\Resource\DeclaresSingletonTypesInterface;
use haddowg\JsonApi\Resource\SingletonContext;
use haddowg\JsonApi\Resource\SingletonResourceInterface;
use haddowg\JsonApi\Serializer\SerializerInterface;

/**
 * A {@see PivotParentSerializer} for a singleton parent type. A singleton is declared on its
 * class, statically, so the decorator declares it too, and the linkage document's
 * relationship links stay on the type's path with no id.
 */
final class SingletonPivotParentSerializer extends AbstractPivotParentSerializer implements SingletonResourceInterface
{
    public function __construct(
        private readonly PivotParentSerializer $inner,
        private readonly DeclaresSingletonTypesInterface $singletons,
        private readonly string $type,
    ) {}

    public static function isSingleton(): bool
    {
        return true;
    }

    public function resolveSingletonId(SingletonContext $context): ?string
    {
        return $this->singletons->resolveSingletonId($this->type, $context);
    }

    public function getRelationships(mixed $object, JsonApiRequestInterface $request): array
    {
        return $this->inner->getRelationships($object, $request);
    }

    protected function inner(): SerializerInterface
    {
        return $this->inner;
    }
}
