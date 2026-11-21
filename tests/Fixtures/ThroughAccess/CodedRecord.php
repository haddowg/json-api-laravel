<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\ThroughAccess;

/**
 * An in-memory vault, safe or ledger.
 */
final class CodedRecord
{
    public function __construct(
        public string $id = '',
        public string $code = '',
    ) {}
}
