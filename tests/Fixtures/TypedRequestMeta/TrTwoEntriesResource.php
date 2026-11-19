<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\TypedRequestMeta;

use haddowg\JsonApi\Resource\Field\AdditionalProperties;
use haddowg\JsonApi\Resource\MetaContext;
use haddowg\JsonApiLaravel\Attribute\AsJsonApiResource;
use haddowg\JsonApiLaravel\Operation\Operation;

/**
 * Opens its resource meta twice.
 */
#[AsJsonApiResource(operations: [Operation::FetchOne])]
final class TrTwoEntriesResource extends TrPlainResource
{
    public static string $type = 'tr-bad-entries';

    public function resourceMeta(MetaContext $context): iterable
    {
        return [AdditionalProperties::make(), AdditionalProperties::make()];
    }
}
