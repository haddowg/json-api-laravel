<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\StandaloneHydrator;

use haddowg\JsonApi\Request\JsonApiRequestInterface;
use haddowg\JsonApi\Schema\Link\ResourceLinks;
use haddowg\JsonApi\Serializer\AbstractSerializer;
use haddowg\JsonApiLaravel\Attribute\AsJsonApiSerializer;
use haddowg\JsonApiLaravel\Operation\Operation;

/**
 * The write-capability guard's failure fixture: a standalone serializer whose
 * allow-list opens `Create` with **no** standalone hydrator registered for the type —
 * the misconfiguration route registration must refuse loudly (the Laravel twin of the
 * bundle's compile-time `validateWriteCapability` guard). Registered only by the guard
 * test, never scanned.
 *
 * @internal
 */
#[AsJsonApiSerializer(type: 'unpaired-notes', operations: [Operation::FetchCollection, Operation::Create])]
final class UnpairedWriteSerializer extends AbstractSerializer
{
    public function getType(mixed $object): string
    {
        return 'unpaired-notes';
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
