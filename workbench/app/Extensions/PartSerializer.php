<?php

declare(strict_types=1);

namespace Workbench\App\Extensions;

use haddowg\JsonApi\Request\JsonApiRequestInterface;
use haddowg\JsonApi\Schema\Link\ResourceLinks;
use haddowg\JsonApi\Serializer\AbstractSerializer;
use haddowg\JsonApiLaravel\Attribute\AsJsonApiSerializer;
use haddowg\JsonApiLaravel\Operation\Operation;

/**
 * The standalone-serializer vendor-extension witness: a resource-less `parts` type declaring
 * a type-wide set and a per-operation set on its fetch-one read.
 */
#[AsJsonApiSerializer(
    type: 'parts',
    operations: [Operation::FetchCollection, Operation::FetchOne],
    extensions: ['x-feature' => 'parts', 'x-stability' => 'beta'],
    operationExtensions: [
        'FetchOne' => ['x-stability' => 'stable', 'x-cache' => ['ttl' => 60]],
    ],
)]
final class PartSerializer extends AbstractSerializer
{
    public function getType(mixed $object): string
    {
        return 'parts';
    }

    public function getId(mixed $object): string
    {
        return $object instanceof Part ? $object->id : '';
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
        return [
            'name' => static fn(mixed $part): string => $part instanceof Part ? $part->name : '',
        ];
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
