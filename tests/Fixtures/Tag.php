<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures;

/**
 * A related POPO used to exercise the relationship-existence and traversal filters
 * ({@see \haddowg\JsonApi\Resource\Filter\WhereHas} / {@see \haddowg\JsonApi\Resource\Filter\WhereThrough})
 * against the in-memory provider: core's `Accessor` reads its public `name`.
 *
 * @internal
 */
final class Tag
{
    public function __construct(
        public string $name,
    ) {}
}
