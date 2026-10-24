<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\UriFieldName;

final class AuthorRecord
{
    public function __construct(
        public string $id = '',
        public string $name = '',
    ) {}
}
