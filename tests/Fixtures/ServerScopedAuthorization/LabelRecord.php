<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\ServerScopedAuthorization;

final class LabelRecord
{
    public function __construct(
        public string $id = '',
        public string $name = '',
    ) {}
}
