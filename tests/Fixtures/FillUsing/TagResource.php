<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\FillUsing;

use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Str;

final class TagResource extends AbstractResource
{
    public static string $type = 'tags';

    public function fields(): array
    {
        return [
            Id::make(),
            Str::make('name'),
        ];
    }
}
