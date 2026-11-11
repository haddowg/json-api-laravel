<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\RelatedBatch;

use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\HasMany;
use haddowg\JsonApi\Resource\Field\HasOne;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Str;

final class RunResource extends AbstractResource
{
    public static string $type = 'runs';

    public function fields(): array
    {
        return [
            Id::make(),
            Str::make('name'),
            HasOne::make('progress', 'progresses')->withData(),
            HasMany::make('advertisers', 'advertisers')->withData()->countable(),
        ];
    }
}
