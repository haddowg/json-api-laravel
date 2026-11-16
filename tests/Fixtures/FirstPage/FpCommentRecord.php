<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\FirstPage;

final class FpCommentRecord
{
    public function __construct(
        public string $id = '',
        public string $body = '',
    ) {}
}
