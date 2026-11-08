<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\ActionCreated;

final class OrderRecord
{
    public function __construct(
        public string $id = '',
        public string $reference = '',
    ) {}
}
