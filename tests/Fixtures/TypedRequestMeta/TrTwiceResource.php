<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\TypedRequestMeta;

use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApi\Resource\RequestMetaContext;
use haddowg\JsonApiLaravel\Attribute\AsJsonApiResource;
use haddowg\JsonApiLaravel\Operation\Operation;

/**
 * Declares one request meta member twice.
 */
#[AsJsonApiResource(operations: [Operation::FetchOne])]
final class TrTwiceResource extends TrPlainResource
{
    public static string $type = 'tr-bad-twice';

    public function requestDocumentMeta(RequestMetaContext $context): iterable
    {
        return [Str::make('reason'), Str::make('reason')];
    }
}
