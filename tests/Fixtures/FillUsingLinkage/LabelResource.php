<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\FillUsingLinkage;

use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Str;

final class LabelResource extends AbstractResource
{
    public static string $type = 'labels';

    public function fields(): array
    {
        return [Id::make(), Str::make('name')];
    }
}
