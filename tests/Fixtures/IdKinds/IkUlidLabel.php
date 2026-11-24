<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\IdKinds;

use Illuminate\Database\Eloquent\Concerns\HasUlids;

final class IkUlidLabel extends IkLabel
{
    use HasUlids;

    public function newUniqueId(): string
    {
        return IdKinds::mint();
    }
}
