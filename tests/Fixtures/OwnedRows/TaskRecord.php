<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\OwnedRows;

final class TaskRecord
{
    public function __construct(
        public string $id = '',
        public string $title = '',
        public string $owner_id = '',
    ) {}
}
