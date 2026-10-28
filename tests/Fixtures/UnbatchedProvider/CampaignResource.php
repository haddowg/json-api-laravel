<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\UnbatchedProvider;

use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Str;

final class CampaignResource extends AbstractResource
{
    public static string $type = 'campaigns';

    public function fields(): array
    {
        return [Id::make(), Str::make('title')->sortable()];
    }
}
