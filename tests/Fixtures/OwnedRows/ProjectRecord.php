<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\OwnedRows;

final class ProjectRecord
{
    /**
     * @param list<TaskRecord> $tasks
     */
    public function __construct(
        public string $id = '',
        public string $name = '',
        public array $tasks = [],
    ) {}
}
