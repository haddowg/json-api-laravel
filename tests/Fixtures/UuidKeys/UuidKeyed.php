<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\UuidKeys;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Support\Str;

/**
 * A UUID-keyed model: Laravel's {@see HasUuids} mints the key, as a canonical string or, with
 * `uuid.storage` set to `binary`, as the 16 raw bytes a `BINARY(16)` column holds.
 */
trait UuidKeyed
{
    use HasUuids;

    public function newUniqueId(): string
    {
        $uuid = Str::orderedUuid();

        return config('uuid.storage') === 'binary' ? $uuid->getBytes() : $uuid->toString();
    }
}
