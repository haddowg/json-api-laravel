<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\TypedRequestMeta;

use haddowg\JsonApi\Request\JsonApiRequestInterface;
use haddowg\JsonApi\Resource\Field\Integer;
use haddowg\JsonApi\Resource\MetaContext;
use haddowg\JsonApiLaravel\Attribute\AsJsonApiResource;
use haddowg\JsonApiLaravel\Operation\Operation;

/**
 * Overrides the deprecated getMeta() while declaring its resource meta.
 */
#[AsJsonApiResource(operations: [Operation::FetchOne])]
final class TrGetMetaResource extends TrPlainResource
{
    public static string $type = 'tr-bad-get-meta';

    public function resourceMeta(MetaContext $context): iterable
    {
        return [Integer::make('uses')->readOnly()];
    }

    public function getMeta(mixed $object, JsonApiRequestInterface $request): array
    {
        return ['uses' => 1];
    }
}
