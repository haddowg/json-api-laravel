<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\LoadedLinkage;

final class TemplateRecord
{
    /**
     * @param list<BindingRecord> $bindings
     * @param list<BindingRecord> $sharedBindings
     */
    public function __construct(
        public string $id,
        public string $name,
        public array $bindings = [],
        public array $sharedBindings = [],
    ) {}
}
