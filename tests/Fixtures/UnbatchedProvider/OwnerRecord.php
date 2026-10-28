<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\UnbatchedProvider;

final class OwnerRecord
{
    public function __construct(
        public string $id = '',
        public string $name = '',
    ) {}
}
