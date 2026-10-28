<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\IdentifiedMorph;

use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\BelongsTo;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Str;

final class AdvertiserResource extends AbstractResource
{
    public static string $type = 'advertisers';

    public function getType(mixed $object): string
    {
        return $object instanceof Advertiser || $object instanceof AdvertiserRecord ? 'advertisers' : '';
    }

    public function fields(): array
    {
        return [
            Id::make(),
            Str::make('name'),
            BelongsTo::make('owner', 'people'),
        ];
    }
}
