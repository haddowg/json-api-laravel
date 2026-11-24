<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\MorphKeys;

use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Str;

/**
 * Badges served as `b-<id>`, hidden ones left out.
 */
final class MkBadgeResource extends AbstractResource
{
    public static string $type = 'mkBadges';

    public function fields(): array
    {
        return [Id::make()->encodeUsing(new PrefixCodec('b-')), Str::make('name')];
    }

    public function getType(mixed $object): string
    {
        return $object instanceof MkBadge || ($object instanceof MkTargetRecord && $object->type === static::$type) ? static::$type : '';
    }
}
