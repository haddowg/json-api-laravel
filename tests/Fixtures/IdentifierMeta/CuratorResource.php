<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\IdentifierMeta;

use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Str;

final class CuratorResource extends AbstractResource
{
    public static string $type = 'curators';

    public function fields(): array
    {
        return [
            Id::make()->build(),
            Str::make('name'),
        ];
    }
}
