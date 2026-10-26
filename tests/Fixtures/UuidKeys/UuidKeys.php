<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\UuidKeys;

use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\IdBuilder;
use haddowg\JsonApiLaravel\Eloquent\BinaryUuidEncoder;

final class UuidKeys
{
    /**
     * The `id` field for the configured storage: binary keys go out as canonical UUIDs.
     */
    public static function id(): IdBuilder
    {
        $id = Id::make()->uuid();

        return config('uuid.storage') === 'binary' ? $id->encodeUsing(new BinaryUuidEncoder()) : $id;
    }
}
