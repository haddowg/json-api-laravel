<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\DataProvider;

use haddowg\JsonApi\Collection\CollectionResult;
use haddowg\JsonApi\Request\JsonApiRequestInterface;
use haddowg\JsonApi\Resource\Field\ForeignKey;
use haddowg\JsonApi\Resource\Field\RelationInterface;
use haddowg\JsonApiLaravel\DataProvider\Eloquent\EloquentDataProvider;

/**
 * Resolves the {@see DataProviderInterface} for a resource type from the registered
 * providers. The operation handler asks it for a provider, then calls `fetchOne()` /
 * `fetchCollection()`.
 *
 * Resolution is first-`supports()`-match over the injected iteration order. Providers
 * are supplied sorted by descending priority (highest first); the bundled Eloquent
 * reference provider — which supports *every* Eloquent-mapped type — registers at the
 * lowest priority (`-128`), so an application provider at the default priority (`0`)
 * takes precedence for the types it supports.
 *
 * A type with no matching provider is a wiring error — a registered resource type with
 * no data source — so it raises a {@see \LogicException} (a configuration bug), never a
 * JSON:API error document.
 */
final class DataProviderRegistry
{
    /**
     * @var list<DataProviderInterface<object>>
     */
    private readonly array $providers;

    /**
     * The pivot values each parent's pivot pages were read with, keyed by parent, then parent
     * type and relation, then member id.
     *
     * @var \WeakMap<object, array<string, array<array-key, array<string, mixed>>>>
     */
    private \WeakMap $loadedPivots;

    /**
     * @param iterable<DataProviderInterface<object>> $providers in priority order, highest first
     */
    public function __construct(iterable $providers)
    {
        $this->providers = \is_array($providers) ? \array_values($providers) : \iterator_to_array($providers, false);
        $this->loadedPivots = new \WeakMap();
    }

    /**
     * Keeps the pivot values `$parent`'s page of `$relation` (of `$parentType`) was read with,
     * when the page carries them, so its members' `meta.pivot` renders without reading the
     * rows again. Several pages of one relation merge.
     *
     * @param CollectionResult<object> $result
     */
    public function rememberPivotMap(string $parentType, RelationInterface $relation, object $parent, CollectionResult $result): void
    {
        if (!$result instanceof PivotCollectionResult && !$result instanceof PivotCursorCollectionResult) {
            return;
        }

        $key = $parentType . "\0" . $relation->name();
        $maps = $this->loadedPivots[$parent] ?? [];
        $maps[$key] = $result->pivotMap + ($maps[$key] ?? []);
        $this->loadedPivots[$parent] = $maps;
    }

    /**
     * The pivot values of the members of `$parent`'s `$relationName` (of `$parentType`) that a
     * page read in this operation loaded, keyed by member id, or `null` when none was read.
     *
     * @return array<array-key, array<string, mixed>>|null
     */
    public function loadedPivotMap(string $parentType, string $relationName, object $parent): ?array
    {
        return $this->loadedPivots[$parent][$parentType . "\0" . $relationName] ?? null;
    }

    /**
     * Drops the pivot values read with pivot pages so far.
     */
    public function forgetPivotMaps(): void
    {
        $this->loadedPivots = new \WeakMap();
    }

    /**
     * The highest-priority provider whose {@see DataProviderInterface::supports()}
     * is true for `$type`.
     *
     * @return DataProviderInterface<object>
     *
     * @throws \LogicException when no registered provider supports the type
     */
    public function forType(string $type): DataProviderInterface
    {
        foreach ($this->providers as $provider) {
            if ($provider->supports($type)) {
                return $provider;
            }
        }

        throw new \LogicException(\sprintf('No JSON:API data provider is registered for type "%s".', $type));
    }

    /**
     * Whether any registered provider {@see DataProviderInterface::supports()} the type —
     * so a caller can resolve a provider without risking the wiring-error
     * {@see \LogicException} of {@see forType()}. Used by the include-preloader path, where
     * a related type read off the parent (a to-one related endpoint) may have no provider
     * of its own (it is only ever resolved through the parent).
     */
    public function supportsType(string $type): bool
    {
        foreach ($this->providers as $provider) {
            if ($provider->supports($type)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The provider that must load a relation's related members whenever they are rendered from
     * a parent of `$parentType` (an include, linkage, a `?withCount` total, a Relationship
     * Queries window), or `null` when the parent's own batch or a read off the parent already
     * yields what the related endpoint would.
     *
     * The related endpoint asks the RELATED type's provider for the members. The render paths
     * ask the PARENT type's provider, or read the relation off the parent. The two agree when
     * one provider serves both types, and when the related type is served by a reference
     * provider (Eloquent or in-memory), whose related read is the relation read itself, so an
     * Eloquent global scope applies on both. Any other provider owns its type's rows: it may
     * scope them (visibility, tenancy) in ways a relation read never sees, so the members must
     * load through it.
     *
     * @return DataProviderInterface<object>|null
     */
    public function scopedRelatedProvider(string $parentType, string $relatedType): ?DataProviderInterface
    {
        if (!$this->supportsType($relatedType)) {
            return null;
        }

        $related = $this->forType($relatedType);
        if ($related instanceof EloquentDataProvider || $related instanceof InMemoryDataProvider) {
            return null;
        }

        if ($this->supportsType($parentType) && $this->forType($parentType) === $related) {
            return null;
        }

        return $related;
    }

    /**
     * The provider that decides whether a to-one target of `$type` may render, or `null` when a
     * read off the parent already agrees with it. A to-one is read off the parent on every path,
     * with no provider call in between, so unlike {@see scopedRelatedProvider()} it does not
     * matter which provider serves the parent: only a reference provider (Eloquent, in-memory)
     * is trusted, since its rows are exactly what a relation read yields.
     *
     * @return DataProviderInterface<object>|null
     */
    public function scopingProviderFor(string $type): ?DataProviderInterface
    {
        if (!$this->supportsType($type)) {
            return null;
        }

        $provider = $this->forType($type);

        return $provider instanceof EloquentDataProvider || $provider instanceof InMemoryDataProvider ? null : $provider;
    }

    /**
     * Whether any type `$relation` can relate to is served by a {@see scopedRelatedProvider()}
     * for a `$parentType` parent.
     */
    public function relatesToScopedType(string $parentType, RelationInterface $relation): bool
    {
        foreach ($relation->relatedTypes() as $relatedType) {
            if ($this->scopedRelatedProvider($parentType, $relatedType) !== null) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether `$relation` is a monomorphic to-many with no foreign key whose members are the
     * ones its `$parentType` parent model holds: its related type is served by a reference
     * provider (Eloquent, in-memory), whose related read is the parent's own relation, while
     * the parent is served by another provider. Unless that provider batches or counts it,
     * its pages and counts are cut in memory from the model's value, as its related endpoint
     * cuts them.
     */
    public function readsOffModel(string $parentType, RelationInterface $relation): bool
    {
        $relatedTypes = $relation->relatedTypes();
        if (!$relation->isToMany() || \count($relatedTypes) !== 1 || ForeignKey::nameOf($relation) !== null
            || !$this->supportsType($relatedTypes[0])) {
            return false;
        }

        $related = $this->forType($relatedTypes[0]);
        if (!$related instanceof EloquentDataProvider && !$related instanceof InMemoryDataProvider) {
            return false;
        }

        if (!$this->supportsType($parentType)) {
            return true;
        }

        $parent = $this->forType($parentType);

        return !$parent instanceof EloquentDataProvider && !$parent instanceof InMemoryDataProvider;
    }

    /**
     * Fetches `$relation`'s related members for every parent in `$parents`, keyed by each
     * parent's wire id, in one {@see DataProviderInterface::fetchRelatedCollectionBatch()}: the
     * parent type's batch when that matches the related endpoint, otherwise the related type's
     * own provider's batch, asked about parents of another type, so a render path never shows a
     * member the related endpoint would not. A parent the batch did not answer is read on its
     * own with {@see DataProviderInterface::fetchRelatedCollection()} through the same provider,
     * never taken as having no members.
     *
     * Monomorphic relations only; a polymorphic relation has no single related provider.
     *
     * @param list<object>              $parents
     * @param \Closure(object): string  $wireIdOf the parent type's wire id of a parent
     */
    public function fetchRelatedBatch(
        string $parentType,
        array $parents,
        RelationInterface $relation,
        CollectionCriteria $criteria,
        JsonApiRequestInterface $request,
        \Closure $wireIdOf,
    ): RelatedBatch {
        $relatedType = $relation->relatedTypes()[0] ?? $parentType;
        $reader = $this->scopedRelatedProvider($parentType, $relatedType) ?? $this->forType($parentType);
        $batch = $reader->fetchRelatedCollectionBatch($parentType, $parents, $relation, $criteria, $request);

        // A parent the batch leaves out is read as its related endpoint reads it: by the related
        // type's provider when its members are the ones its model holds.
        $single = $this->readsOffModel($parentType, $relation) ? $this->forType($relatedType) : $reader;

        $results = [];
        foreach ($parents as $parent) {
            $wireId = $wireIdOf($parent);
            $results[$wireId] = $batch->has($wireId)
                ? $batch->for($wireId)
                : $single->fetchRelatedCollection($relatedType, $parent, $relation, $criteria, $request);
            $this->rememberPivotMap($parentType, $relation, $parent, $results[$wireId]);
        }

        return new RelatedBatch($results);
    }
}
