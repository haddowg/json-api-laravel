<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\DataProvider;

use haddowg\JsonApi\Resource\Field\RelationInterface;
use haddowg\JsonApi\Resource\Filter\FilterHandlerInterface;
use haddowg\JsonApi\Resource\Filter\FilterInterface;
use haddowg\JsonApi\Resource\Filter\InMemory\ArrayFilterHandler;

/**
 * Core's {@see ArrayFilterHandler} with the related types' scopes applied: a copy made by
 * {@see forPaths()} narrows the rows each relation a filter walks reaches to those
 * {@see FilterPaths::admit()} lets through, so a row the related type's provider hides is never
 * matched, and a `WhereMorph`'s targets to those {@see FilterPaths::admitMorph()} lets through.
 *
 * @internal
 *
 * @implements FilterHandlerInterface<list<mixed>>
 */
final class ScopedArrayFilterHandler implements FilterHandlerInterface, ScopesFilterHopsInterface
{
    public function __construct(private readonly ArrayFilterHandler $handler) {}

    public function apply(FilterInterface $filter, mixed $query, mixed $value): mixed
    {
        return $this->handler->apply($filter, $query, $value);
    }

    public function forPaths(FilterPaths $paths): static
    {
        return new static($this->handler->withRowAdmission(static function (string $path, array $reached) use ($paths): array {
            $hop = $paths->hopAt($path);
            if ($hop === null) {
                return $reached;
            }

            if ($paths->refuses($hop->relation)) {
                return [];
            }

            // Only an object row has a provider to ask; an array row stays as it is.
            $objects = \array_values(\array_filter($reached, \is_object(...)));
            if ($objects === []) {
                return $reached;
            }
            $admitted = [];
            foreach ($paths->admit($hop, $objects) as $row) {
                $admitted[\spl_object_id($row)] = true;
            }

            return \array_values(\array_filter($reached, static fn(mixed $row): bool => !\is_object($row) || isset($admitted[\spl_object_id($row)])));
        })->withMorphAdmission(static fn(RelationInterface $relation, string $type, array $keys): array => $paths->admitMorph($relation, $type, $keys)));
    }
}
