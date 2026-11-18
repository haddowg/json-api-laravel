<?php

declare(strict_types=1);

namespace Workbench\App\TypedMeta;

use haddowg\JsonApi\OpenApi\Metadata\OperationType;
use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Integer;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApiLaravel\Attribute\AsJsonApiResource;

/**
 * Declares a collection member named after the `page` core writes there.
 */
#[AsJsonApiResource(readOnly: true)]
final class TmClashResource extends AbstractResource
{
    public static string $type = 'tm-clashes';

    public function fields(): array
    {
        return [Id::make(), Str::make('name')];
    }

    public function documentMeta(OperationType $operation): iterable
    {
        return $operation === OperationType::FetchCollection ? [Integer::make('page')] : [];
    }
}
