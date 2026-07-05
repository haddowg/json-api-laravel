<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\StandaloneHydrator;

use haddowg\JsonApi\Hydrator\HydratorInterface;
use haddowg\JsonApi\Request\JsonApiRequestInterface;
use haddowg\JsonApi\Schema\Link\ResourceLinks;
use haddowg\JsonApi\Serializer\AbstractSerializer;
use haddowg\JsonApiLaravel\Attribute\AsJsonApiHydrator;
use haddowg\JsonApiLaravel\Attribute\AsJsonApiSerializer;

/**
 * A single class carrying BOTH standalone attributes: one `SerializerInterface` +
 * `HydratorInterface` implementer registering both halves of the resource-less
 * `pulses` type in one place — the dual-attribute classification the scanner must land
 * in both buckets (a serializer descriptor AND a hydrator descriptor). Used only by the
 * discovery unit tests, never scanned.
 *
 * @internal
 */
#[AsJsonApiSerializer(type: 'pulses')]
#[AsJsonApiHydrator(type: 'pulses')]
final class PulseCapability extends AbstractSerializer implements HydratorInterface
{
    public function getType(mixed $object): string
    {
        return 'pulses';
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

    public function hydrate(JsonApiRequestInterface $request, mixed $domainObject): mixed
    {
        return $domainObject;
    }
}
