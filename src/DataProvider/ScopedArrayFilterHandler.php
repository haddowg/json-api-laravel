<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\DataProvider;

use haddowg\JsonApi\Exception\FilterParamUnrecognized;
use haddowg\JsonApi\Resource\Field\RelationInterface;
use haddowg\JsonApi\Resource\Filter\FilterHandlerInterface;
use haddowg\JsonApi\Resource\Filter\FilterInterface;
use haddowg\JsonApi\Resource\Filter\InMemory\ArrayFilterHandler;
use haddowg\JsonApi\Resource\Filter\WhereGroup;
use haddowg\JsonApi\Resource\Through\FiltersThrough;

/**
 * Core's {@see ArrayFilterHandler} with the related types' scopes applied: a copy made by
 * {@see forPaths()} narrows the rows each relation a filter walks reaches to those
 * {@see FilterPaths::rowAdmission()} lets through, so a row the related type's provider hides is
 * never matched, and a `WhereMorph`'s targets to those {@see FilterPaths::admitMorph()} lets
 * through. A path reaching a pivot row is unrecognised, as a `pivot.` column filter is here,
 * since no in-memory row holds one.
 *
 * @internal
 *
 * @implements FilterHandlerInterface<list<mixed>>
 */
final class ScopedArrayFilterHandler implements FilterHandlerInterface, ScopesFilterHopsInterface
{
    public function __construct(private readonly ArrayFilterHandler $handler) {}

    /**
     * @throws FilterParamUnrecognized when the filter's path reaches a pivot row, which no in-memory row holds
     */
    public function apply(FilterInterface $filter, mixed $query, mixed $value): mixed
    {
        if (self::reachesPivot($filter)) {
            throw new FilterParamUnrecognized($filter->key());
        }

        return $this->handler->apply($filter, $query, $value);
    }

    public function forPaths(FilterPaths $paths): static
    {
        $handler = $this->handler
            ->withRowAdmission($paths->rowAdmission())
            ->withMorphAdmission(static fn(RelationInterface $relation, string $type, array $keys): array => $paths->admitMorph($relation, $type, $keys));
        $read = $paths->relationReader();
        if ($read === null) {
            return new static($handler);
        }

        // A filter keyed by relation name passes no relation, so the walked path names it.
        return new static($handler->withRelationReader(static fn(mixed $row, string $walked, ?RelationInterface $relation): ?iterable => $read($row, $walked, $relation ?? $paths->hopAt($walked)?->relation)));
    }

    private static function reachesPivot(FilterInterface $filter): bool
    {
        if ($filter instanceof WhereGroup) {
            foreach ($filter->children as $child) {
                if (self::reachesPivot($child)) {
                    return true;
                }
            }

            return false;
        }

        return $filter instanceof FiltersThrough && $filter->resolvedThrough()?->reachesPivot() === true;
    }
}
