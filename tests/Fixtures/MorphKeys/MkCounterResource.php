<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\MorphKeys;

use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Str;

/**
 * Counters with plain integer ids, hidden ones left out.
 */
final class MkCounterResource extends AbstractResource
{
    public static string $type = 'mkCounters';

    public function fields(): array
    {
        return [Id::make()->numeric(), Str::make('name')];
    }

    public function getType(mixed $object): string
    {
        return $object instanceof MkCounter || ($object instanceof MkTargetRecord && $object->type === static::$type) ? static::$type : '';
    }
}
