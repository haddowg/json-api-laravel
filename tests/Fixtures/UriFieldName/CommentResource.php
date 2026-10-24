<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\UriFieldName;

use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Str;

final class CommentResource extends AbstractResource
{
    public static string $type = 'comments';

    public function fields(): array
    {
        return [
            Id::make()->build(),
            Str::make('body'),
        ];
    }
}
