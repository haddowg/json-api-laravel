<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\FirstPage;

final class FpLabelRecord
{
    public function __construct(
        public string $id = '',
        public string $name = '',
    ) {}
}
