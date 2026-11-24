<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\IdKinds;

/**
 * The in-memory twin of {@see IkLabel}, holding only the labels its scope admits.
 */
final class IkLabelRecord
{
    /**
     * @param list<IkBookRecord> $books
     */
    public function __construct(
        public string $id = '',
        public string $name = '',
        public array $books = [],
    ) {}
}
