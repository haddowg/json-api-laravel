<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\StandaloneHydrator;

use haddowg\JsonApi\Request\JsonApiRequestInterface;
use haddowg\JsonApi\Schema\Link\ResourceLinks;
use haddowg\JsonApi\Serializer\AbstractSerializer;
use haddowg\JsonApiLaravel\Attribute\AsJsonApiSerializer;
use haddowg\JsonApiLaravel\Operation\Operation;

/**
 * The write-capability guard's Delete boundary fixture: a standalone serializer whose
 * allow-list opens `FetchOne` + `Delete` with no hydrator — legal, because a delete
 * hydrates nothing (it loads then removes; only `Create`/`Update` populate an entity
 * from the document). Registered only by the guard test, never scanned.
 *
 * @internal
 */
#[AsJsonApiSerializer(type: 'retirements', operations: [Operation::FetchOne, Operation::Delete])]
final class DeleteOnlySerializer extends AbstractSerializer
{
    public function getType(mixed $object): string
    {
        return 'retirements';
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
