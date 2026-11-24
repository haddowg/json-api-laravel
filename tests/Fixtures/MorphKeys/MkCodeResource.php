<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\MorphKeys;

use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Str;

/**
 * Codes with string keys, hidden ones left out.
 */
final class MkCodeResource extends AbstractResource
{
    public static string $type = 'mkCodes';

    public function fields(): array
    {
        return [Id::make(), Str::make('name')];
    }

    public function getType(mixed $object): string
    {
        return $object instanceof MkCode || ($object instanceof MkCodeRecord) ? static::$type : '';
    }
}
