<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\DataProvider;

use haddowg\JsonApi\Request\JsonApiRequestInterface;
use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\FieldInterface;
use haddowg\JsonApi\Resource\Field\RelationInterface;
use haddowg\JsonApi\Resource\Filter\FilterInterface;
use haddowg\JsonApi\Resource\Filter\RelationHop;
use haddowg\JsonApi\Resource\Filter\ThroughPathResolver;
use haddowg\JsonApi\Resource\Filter\ThroughReadAccess;
use haddowg\JsonApi\Resource\SerializerResolverInterface;
use haddowg\JsonApi\Resource\Sort\SortInterface;
use haddowg\JsonApi\Resource\Through\PivotRowRelations;

/**
 * Resolves a matched filter's traversal path against the fields of the type the filters run
 * against, through core's {@see ThroughPathResolver}, so a `WhereThrough` or `WhereThroughIn`
 * whose path ends on a relationship compares the related resource's decoded id and validates
 * against its format. It rides a {@see CollectionCriteria}, and the applier and the filter
 * value validator resolve each filter after matching it, a family's included.
 *
 * They also carry what the caller may read through each relation a filter reaches through:
 * {@see hopsOf()} and {@see refusedHops()} decide which filters the caller may not use,
 * {@see hopAt()} names the relation a hop of a dotted path walks so an in-memory walk can
 * narrow it to the rows its type's provider admits, {@see rowAdmission()} is that narrowing as
 * the closure core's reference handlers take, and {@see refuses()} tells a data layer that a
 * hop admits no row at all. Sorts declared with `->through()` resolve and list their hops here
 * too.
 */
final readonly class FilterPaths
{
    /**
     * @param list<FieldInterface>                                                                $fields    the inventory of the type the filters run against
     * @param string|null                                                                         $type      the type the filters run against, when known
     * @param list<string>                                                                        $named     every type the declaration names: the type itself, the related types of its relations, and the parent type of a relation whose filters these are
     * @param (\Closure(RelationInterface, string, list<object>): list<object>)|null               $admission the rows of those a relation, declared on the given type, reached that its related type's provider admits
     * @param list<RelationInterface>                                                             $refused   the relations through which the filter being applied matches no row
     * @param RelationHop|null                                                                    $under     the relation the filters run under on its reads, whose pivot row a path starting with `pivot` reaches
     * @param (\Closure(RelationInterface, string, string, list<mixed>): list<mixed>)|null        $morphAdmission the storage keys of a type, reached through a polymorphic relation declared on the given type, that the type's provider admits
     */
    public function __construct(
        private ThroughPathResolver $resolver,
        private array $fields,
        public ?string $type = null,
        public array $named = [],
        private ?ThroughReadAccess $access = null,
        private ?\Closure $admission = null,
        private array $refused = [],
        public ?RelationHop $under = null,
        private ?\Closure $morphAdmission = null,
    ) {}

    /**
     * The paths of `$resource`'s filters, or of a relation's filters when `$resource` is its
     * related type. `null` when there is no single type to resolve against. `$admission`
     * narrows the rows an in-memory walk reaches through a relation to those the related
     * type's provider admits. `$relation`, declared on `$parentType`, is the relation the
     * filters run under on its reads, and `$pivotRows` names the relations a pivot row holds.
     * `$morphAdmission` narrows the targets a `WhereMorph` reaches to the storage keys the
     * requested type's provider admits.
     *
     * @param (\Closure(RelationInterface, string, list<object>): list<object>)|null $admission
     * @param (\Closure(RelationInterface, string, string, list<mixed>): list<mixed>)|null $morphAdmission
     */
    public static function of(SerializerResolverInterface $server, ?AbstractResource $resource, ?string $parentType = null, ?\Closure $admission = null, ?RelationInterface $relation = null, ?PivotRowRelations $pivotRows = null, ?\Closure $morphAdmission = null): ?self
    {
        if ($resource === null) {
            return null;
        }

        $type = $resource::$type !== '' ? $resource::$type : null;
        $under = $relation !== null && $parentType !== null ? new RelationHop($relation, $parentType, $relation->column() ?? $relation->name()) : null;

        return new self(ThroughPathResolver::forResolver($server, $pivotRows), $resource->allFields(), $type, self::namedTypes($resource->allFields(), $type, $parentType), ThroughReadAccess::forResolver($server, $pivotRows), $admission, under: $under, morphAdmission: $morphAdmission);
    }

    /**
     * The inventory of the type the filters run against.
     *
     * @return list<FieldInterface>
     */
    public function fields(): array
    {
        return $this->fields;
    }

    /**
     * A copy for the filter about to run, through whose `$refused` relations it matches no row.
     *
     * @param list<RelationInterface> $refused
     */
    public function scoped(array $refused = []): self
    {
        return new self($this->resolver, $this->fields, $this->type, $this->named, $this->access, $this->admission, $refused, $this->under, $this->morphAdmission);
    }

    /**
     * The relations `$filter` reaches through, as {@see ThroughReadAccess::hopsOf()} lists them.
     *
     * @return list<RelationHop>
     */
    public function hopsOf(FilterInterface $filter): array
    {
        return $this->type === null ? [] : $this->walker()->hopsOf($filter, $this->fields, $this->type, $this->under);
    }

    /**
     * The relations `$filter` reaches through for the requested `$value`, as
     * {@see ThroughReadAccess::hopsOfRequested()} lists them: a `WhereMorph` reaches only the
     * type the value names.
     *
     * @return list<RelationHop>
     */
    public function hopsOfRequested(FilterInterface $filter, mixed $value): array
    {
        return $this->type === null ? [] : $this->walker()->hopsOfRequested($filter, $value, $this->fields, $this->type, $this->under);
    }

    /**
     * The hops of `$hops` the caller may not read through, asked of the server's gate.
     *
     * @param list<RelationHop> $hops
     *
     * @return list<RelationHop>
     */
    public function refusedHops(array $hops, JsonApiRequestInterface $request): array
    {
        return $hops === [] || $this->access === null ? [] : $this->access->refused($hops, $request);
    }

    /**
     * Whether the filter being applied matches no row through `$relation`.
     */
    public function refuses(RelationInterface $relation): bool
    {
        return \in_array($relation, $this->refused, true);
    }

    /**
     * Whether the filter being applied matches no row through any relation `$filter` reaches.
     */
    public function refusesAnyOf(FilterInterface $filter): bool
    {
        if ($this->refused === []) {
            return false;
        }

        foreach ($this->hopsOf($filter) as $hop) {
            if ($this->refuses($hop->relation)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The hop the last segment of the dotted storage path `$path` walks, or null when that
     * segment is no relation.
     */
    public function hopAt(string $path): ?RelationHop
    {
        if ($this->type === null) {
            return null;
        }

        $hops = \array_values(\array_filter($this->walker()->hopsAlong($path, $this->fields, $this->type), static fn(RelationHop $hop): bool => $hop->path === $path));

        return $hops[0] ?? null;
    }

    /**
     * The access resolver that lists hops, one over the resolver alone when no gate came with
     * the paths.
     */
    private function walker(): ThroughReadAccess
    {
        return $this->access ?? new ThroughReadAccess($this->resolver);
    }

    /**
     * The rows of `$rows`, reached through `$hop`, that the related type's provider admits:
     * none through a refused relation, and all of them when no provider narrows them.
     *
     * @param list<object> $rows
     *
     * @return list<object>
     */
    public function admit(RelationHop $hop, array $rows): array
    {
        if ($this->refuses($hop->relation)) {
            return [];
        }

        return $this->admission === null ? $rows : ($this->admission)($hop->relation, $hop->parentType, $rows);
    }

    /**
     * The admission an in-memory walk applies to the rows each hop reaches, as core's reference
     * handlers' `withRowAdmission()` take it: the relation a `->through()` hop names, or else
     * the one the walked storage path names, keeps the rows {@see admit()} lets through. A
     * segment that is no relation, and a row that is no object, stay as they are.
     *
     * @return \Closure(string, list<mixed>, ?RelationInterface=): list<mixed>
     */
    public function rowAdmission(): \Closure
    {
        return $this->admitReached(...);
    }

    /**
     * @param list<mixed> $reached
     *
     * @return list<mixed>
     */
    private function admitReached(string $path, array $reached, ?RelationInterface $relation = null): array
    {
        $hop = $this->hopAt($path);
        if ($relation !== null && $hop?->relation !== $relation) {
            // Relations sharing a storage member stay apart: the one the hop names decides.
            $hop = new RelationHop($relation, $hop->parentType ?? '', $path);
        }
        if ($hop === null) {
            return $reached;
        }

        if ($this->refuses($hop->relation)) {
            return [];
        }

        // Only an object row has a provider to ask; an array row stays as it is.
        $objects = \array_values(\array_filter($reached, \is_object(...)));
        if ($objects === []) {
            return $reached;
        }
        $admitted = [];
        foreach ($this->admit($hop, $objects) as $row) {
            $admitted[\spl_object_id($row)] = true;
        }

        return \array_values(\array_filter($reached, static fn(mixed $row): bool => !\is_object($row) || isset($admitted[\spl_object_id($row)])));
    }

    /**
     * The storage keys among `$keys`, targets of `$type` a `WhereMorph` reaches through
     * `$relation`, that the type's provider admits: none through a refused relation, and all
     * of them when no provider narrows them.
     *
     * @param list<mixed> $keys
     *
     * @return list<mixed>
     */
    public function admitMorph(RelationInterface $relation, string $type, array $keys): array
    {
        if ($this->refuses($relation)) {
            return [];
        }

        return $this->morphAdmission === null || $this->type === null ? $keys : ($this->morphAdmission)($relation, $this->type, $type, $keys);
    }

    /**
     * The types a declaration names: `$type`, the related types of the relations among
     * `$fields`, and `$parentType`, the type that declares the relation whose filters these
     * are. A filter reaching a model several types serve decodes with the encoder these name.
     *
     * @param iterable<FieldInterface> $fields
     *
     * @return list<string>
     */
    public static function namedTypes(iterable $fields, ?string $type, ?string $parentType = null): array
    {
        $named = $type !== null ? [$type] : [];
        foreach ($fields as $field) {
            if ($field instanceof RelationInterface) {
                foreach ($field->relatedTypes() as $related) {
                    $named[] = $related;
                }
            }
        }
        if ($parentType !== null) {
            $named[] = $parentType;
        }

        return \array_values(\array_unique($named));
    }

    /**
     * @throws \LogicException when the path ends on a polymorphic relation, or the filter cannot compare what it ends on
     */
    public function resolve(FilterInterface $filter): FilterInterface
    {
        return $this->resolver->resolve($filter, $this->fields, $this->under, $this->type ?? '');
    }

    /**
     * `$sort` with a path reaching through relations resolved; any other sort unchanged.
     *
     * @throws \LogicException when the path cannot be resolved
     */
    public function resolveSort(SortInterface $sort): SortInterface
    {
        return $this->resolver->resolveSort($sort, $this->fields, $this->under, $this->type ?? '');
    }

    /**
     * The relations `$sort` reaches through, as {@see ThroughReadAccess::hopsOfSort()} lists them.
     *
     * @return list<RelationHop>
     */
    public function hopsOfSort(SortInterface $sort): array
    {
        return $this->type === null ? [] : $this->walker()->hopsOfSort($sort, $this->fields, $this->type, $this->under);
    }
}
