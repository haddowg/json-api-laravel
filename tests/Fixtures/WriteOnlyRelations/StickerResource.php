<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\WriteOnlyRelations;

use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApi\Resource\Sort\SortByField;

final class StickerResource extends AbstractResource
{
    public static string $type = 'stickers';

    public function fields(): array
    {
        return [
            Id::make()->build(),
            Str::make('label'),
        ];
    }

    public function sorts(): array
    {
        return [SortByField::make('label')];
    }
}
