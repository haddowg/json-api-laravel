<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\UnbatchedProvider;

use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApi\Resource\Filter\Where;

final class OwnerResource extends AbstractResource
{
    public static string $type = 'owners';

    public function fields(): array
    {
        return [Id::make(), Str::make('name')];
    }

    public function filters(): array
    {
        return [Where::make('name')];
    }
}
