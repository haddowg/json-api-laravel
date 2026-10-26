<?php

declare(strict_types=1);

namespace Workbench\App\Examples;

use haddowg\JsonApi\OpenApi\Metadata\OperationExample;
use haddowg\JsonApi\Request\JsonApiRequestInterface;
use haddowg\JsonApi\Schema\Link\ResourceLinks;
use haddowg\JsonApi\Serializer\AbstractSerializer;
use haddowg\JsonApiLaravel\Attribute\AsJsonApiSerializer;
use haddowg\JsonApiLaravel\Operation\Operation;

/**
 * A resource-less type on the `legacy` server. It has no fields to build an example from,
 * so it declares its fetch-one example whole.
 */
#[AsJsonApiSerializer(
    type: 'gauges',
    server: 'legacy',
    operations: [Operation::FetchOne],
    operationExamples: [
        'fetchOne' => new OperationExample(responses: [200 => ['data' => ['type' => 'gauges', 'id' => 'g1', 'attributes' => ['reading' => 42]]]]),
    ],
)]
final class GaugeSerializer extends AbstractSerializer
{
    public function getType(mixed $object): string
    {
        return 'gauges';
    }

    public function getId(mixed $object): string
    {
        return $object instanceof Gauge ? $object->id : '';
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
            'reading' => static fn(mixed $gauge): ?int => $gauge instanceof Gauge ? $gauge->reading : null,
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
