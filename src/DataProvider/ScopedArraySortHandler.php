<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\DataProvider;

use haddowg\JsonApi\Resource\Sort\InMemory\ArraySortHandler;
use haddowg\JsonApi\Resource\Sort\SortHandlerInterface;

/**
 * Core's {@see ArraySortHandler} with the related types' scopes applied, the sort twin of
 * {@see ScopedArrayFilterHandler}: a copy made by {@see forPaths()} reads each hop of a sort
 * reaching through relations through the rows {@see FilterPaths::rowAdmission()} lets through,
 * so a row the related type's provider hides sorts as `null`.
 *
 * @internal
 *
 * @implements SortHandlerInterface<list<mixed>>
 */
final class ScopedArraySortHandler implements SortHandlerInterface, ScopesFilterHopsInterface
{
    public function __construct(private readonly ArraySortHandler $handler) {}

    public function apply(array $sorts, mixed $query): mixed
    {
        return $this->handler->apply($sorts, $query);
    }

    public function forPaths(FilterPaths $paths): static
    {
        return new static($this->handler->withRowAdmission($paths->rowAdmission()));
    }
}
