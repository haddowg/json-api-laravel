<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\FillUsingLinkage;

final class TemplateSetting
{
    public function __construct(
        public readonly string $id,
        public readonly string $name,
    ) {}
}
