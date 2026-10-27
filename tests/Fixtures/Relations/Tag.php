<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\Relations;

/**
 * A blog tag, the other member of a {@see Post}'s polymorphic relations and the target
 * of its `belongsToMany`-style `tags` relation. Its `owner` gives an include path through
 * a relation that targets tags.
 */
final class Tag
{
    public function __construct(
        public string $id = '',
        public string $label = '',
        public ?Author $owner = null,
    ) {}
}
