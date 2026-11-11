<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\RelatedBatch;

final class RunRecord
{
    public function __construct(
        public string $id = '',
        public string $name = '',
    ) {}
}
