<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\Inbox;

use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Str;

final class RfpResource extends AbstractResource
{
    public static string $type = 'inbRfps';

    public function fields(): array
    {
        return [Id::make()->numeric(), Str::make('name')];
    }

    public function getType(mixed $object): string
    {
        return $object instanceof Rfp || ($object instanceof NamedRecord && $object->type === static::$type) ? static::$type : '';
    }
}
