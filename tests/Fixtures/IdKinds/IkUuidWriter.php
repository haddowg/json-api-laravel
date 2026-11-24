<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\IdKinds;

use Illuminate\Database\Eloquent\Concerns\HasUuids;

final class IkUuidWriter extends IkWriter
{
    use HasUuids;

    public function newUniqueId(): string
    {
        return IdKinds::mint();
    }
}
