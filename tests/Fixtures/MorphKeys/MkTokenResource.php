<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\MorphKeys;

use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Str;

/**
 * Tokens served as `t-<id>`, none hidden.
 */
final class MkTokenResource extends AbstractResource
{
    public static string $type = 'mkTokens';

    public function fields(): array
    {
        return [Id::make()->encodeUsing(new PrefixCodec('t-')), Str::make('name')];
    }

    public function getType(mixed $object): string
    {
        return $object instanceof MkToken || ($object instanceof MkTargetRecord && $object->type === static::$type) ? static::$type : '';
    }
}
