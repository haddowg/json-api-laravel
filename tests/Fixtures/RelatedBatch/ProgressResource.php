<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\RelatedBatch;

use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Integer;
use haddowg\JsonApi\Resource\Filter\Where;

final class ProgressResource extends AbstractResource
{
    public static string $type = 'progresses';

    public function fields(): array
    {
        return [Id::make(), Integer::make('percent')];
    }

    public function filters(): array
    {
        return [Where::make('percent')];
    }
}
