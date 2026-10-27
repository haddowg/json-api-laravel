<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\ActionLinks;

/**
 * The in-memory model behind the `records` type: a title and a status.
 */
final class Record
{
    public function __construct(
        public ?int $id = null,
        public string $title = '',
        public string $status = '',
    ) {}
}
