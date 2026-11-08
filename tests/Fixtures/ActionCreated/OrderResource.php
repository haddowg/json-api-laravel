<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\ActionCreated;

use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Str;

final class OrderResource extends AbstractResource
{
    public static string $type = 'orders';

    public function fields(): array
    {
        return [Id::make(), Str::make('reference')];
    }
}
