<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\IdentifiedMorph;

final class PersonRecord
{
    public function __construct(
        public string $id = '',
        public string $name = '',
    ) {}
}
