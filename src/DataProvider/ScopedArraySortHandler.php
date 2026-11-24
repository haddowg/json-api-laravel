<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\DataProvider;

use haddowg\JsonApi\Resource\Field\RelationInterface;
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
        $handler = $this->handler->withRowAdmission($paths->rowAdmission());
        $read = $paths->relationReader();

        // A hop read through a foreign key reads its rows as the filters do.
        return new static($read !== null ? $handler->withRelationReader(static fn(mixed $row, string $walked, ?RelationInterface $relation): ?iterable => $read($row, $walked, $relation ?? $paths->hopAt($walked)?->relation)) : $handler);
    }
}
