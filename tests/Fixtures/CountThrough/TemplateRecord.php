<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\CountThrough;

/**
 * The in-memory twin of {@see Template}.
 */
final class TemplateRecord
{
    public function __construct(
        public string $id,
        public string $name,
    ) {}
}
