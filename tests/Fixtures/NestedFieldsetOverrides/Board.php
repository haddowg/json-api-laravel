<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\NestedFieldsetOverrides;

/**
 * @internal
 */
final class Board
{
    /**
     * @param array<string, mixed>|null       $values
     * @param array<string, mixed>|null       $address
     * @param list<array<string, mixed>>|null $rows
     */
    public function __construct(
        public string $id = '',
        public string $name = '',
        public ?array $values = null,
        public ?array $address = null,
        public ?array $rows = null,
    ) {}
}
