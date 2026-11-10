<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\PivotMembership;

use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Str;

final class RecordResource extends AbstractResource
{
    public static string $type = 'records';

    public function fields(): array
    {
        return [Id::make(), Str::make('title')];
    }
}
