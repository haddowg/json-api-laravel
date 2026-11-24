<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\IdKinds;

use Illuminate\Database\Eloquent\Concerns\HasUuids;

final class IkUuidBook extends IkBook
{
    use HasUuids;

    public function newUniqueId(): string
    {
        return IdKinds::mint();
    }
}
