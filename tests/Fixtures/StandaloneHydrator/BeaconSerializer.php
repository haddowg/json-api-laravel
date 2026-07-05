<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\StandaloneHydrator;

use haddowg\JsonApi\Request\JsonApiRequestInterface;
use haddowg\JsonApi\Schema\Link\ResourceLinks;
use haddowg\JsonApi\Serializer\AbstractSerializer;
use haddowg\JsonApiLaravel\Attribute\AsJsonApiSerializer;
use haddowg\JsonApiLaravel\Operation\Operation;

/**
 * The read half of the write-capable standalone `beacons` pair: a hand-written wire
 * shape with no `AbstractResource`, its allow-list opening ALL five operations — the
 * write verbs are legal because {@see BeaconHydrator} registers the type's decoupled
 * write half (the Laravel twin of the bundle's ADR 0024 serializer+hydrator pairing).
 *
 * @internal
 */
#[AsJsonApiSerializer(type: 'beacons', operations: [
    Operation::FetchCollection,
    Operation::FetchOne,
    Operation::Create,
    Operation::Update,
    Operation::Delete,
])]
final class BeaconSerializer extends AbstractSerializer
{
    public function getType(mixed $object): string
    {
        return 'beacons';
    }

    public function getId(mixed $object): string
    {
        return $object instanceof Beacon ? (string) $object->id : '';
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
            'label' => static fn(mixed $beacon, JsonApiRequestInterface $request, string $field): string
                => $beacon instanceof Beacon ? $beacon->label : '',
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
