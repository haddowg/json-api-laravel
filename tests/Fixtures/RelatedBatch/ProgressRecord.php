<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\RelatedBatch;

final class ProgressRecord
{
    public function __construct(
        public string $id = '',
        public int $percent = 0,
    ) {}
}
