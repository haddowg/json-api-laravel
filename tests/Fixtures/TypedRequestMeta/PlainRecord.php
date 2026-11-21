<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\TypedRequestMeta;

/**
 * The in-memory witness's plain row.
 */
final class PlainRecord
{
    public function __construct(
        public string $id = '',
        public string $name = '',
    ) {}
}
