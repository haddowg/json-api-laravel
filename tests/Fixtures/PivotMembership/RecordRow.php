<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\PivotMembership;

final class RecordRow
{
    public function __construct(
        public string $id = '',
        public string $title = '',
        public bool $hidden = false,
    ) {}
}
