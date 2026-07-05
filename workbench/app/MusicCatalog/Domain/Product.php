<?php

declare(strict_types=1);

namespace Workbench\App\MusicCatalog\Domain;

/**
 * A product — a plain mutable domain object whose integer storage key is encoded to a
 * `prod-…` wire token. `parent` is the self-referential to-one object reference.
 */
final class Product
{
    public function __construct(
        public string $id = '',
        public string $name = '',
        public ?Product $parent = null,
    ) {}
}
