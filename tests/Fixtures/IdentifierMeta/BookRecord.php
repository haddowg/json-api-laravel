<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\IdentifierMeta;

/**
 * The in-memory twin of {@see Book}.
 */
final class BookRecord
{
    public function __construct(
        public string $id = '',
        public string $title = '',
    ) {}
}
