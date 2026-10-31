<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\IdentifiedMorphMany;

final class UserRecord
{
    /**
     * @param list<array{0: string, 1: int}> $companies each company's type and id, in stored order
     */
    public function __construct(
        public string $id = '',
        public string $name = '',
        public array $companies = [],
    ) {}
}
