<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\Inbox;

use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Str;

/**
 * Campaigns served as `cmp-<key>`.
 */
final class InboxCampaignResource extends AbstractResource
{
    public static string $type = 'inbCampaigns';

    public function fields(): array
    {
        return [Id::make()->encodeUsing(new CampaignIdCodec()), Str::make('name')];
    }

    public function getType(mixed $object): string
    {
        return $object instanceof InboxCampaign || ($object instanceof NamedRecord && $object->type === static::$type) ? static::$type : '';
    }
}
