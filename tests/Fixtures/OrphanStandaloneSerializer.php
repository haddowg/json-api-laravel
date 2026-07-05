<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures;

use haddowg\JsonApi\Request\JsonApiRequestInterface;
use haddowg\JsonApi\Schema\Link\ResourceLinks;
use haddowg\JsonApi\Serializer\AbstractSerializer;
use haddowg\JsonApiLaravel\Attribute\AsJsonApiSerializer;
use haddowg\JsonApiLaravel\Operation\Operation;

/**
 * A standalone serializer (no resource) whose allow-list opens the two fetch endpoints
 * but has **no** registered data provider — the serializer-channel twin of
 * {@see OrphanResource}, the unservable configuration the
 * {@see \haddowg\JsonApiLaravel\Server\ServableResourceWarmer} must flag at
 * `jsonapi:optimize`. Registered explicitly by {@see OrphanStandaloneServiceProvider},
 * never scanned.
 *
 * @internal
 */
#[AsJsonApiSerializer(type: 'orphan-charts', operations: [Operation::FetchCollection, Operation::FetchOne])]
final class OrphanStandaloneSerializer extends AbstractSerializer
{
    public function getType(mixed $object): string
    {
        return 'orphan-charts';
    }

    public function getId(mixed $object): string
    {
        return '';
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
