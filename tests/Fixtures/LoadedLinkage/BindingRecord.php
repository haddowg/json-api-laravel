<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\LoadedLinkage;

final class BindingRecord
{
    public function __construct(
        public string $id,
        public string $name,
        public bool $hidden = false,
        public bool $detached = false,
        public ?TemplateRecord $template = null,
    ) {}
}
