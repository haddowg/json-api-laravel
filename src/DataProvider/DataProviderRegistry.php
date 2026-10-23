<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\DataProvider;

use haddowg\JsonApi\Request\JsonApiRequestInterface;
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
     * @param iterable<DataProviderInterface<object>> $providers in priority order, highest first
     */
    public function __construct(iterable $providers)
    {
        $this->providers = \is_array($providers) ? \array_values($providers) : \iterator_to_array($providers, false);
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
     * Fetches `$relation`'s related members for every parent in `$parents`, keyed by each
     * parent's wire id: through the parent type's batch when that matches the related endpoint,
     * otherwise one {@see DataProviderInterface::fetchRelatedCollection()} per parent through
     * the related type's own provider (the related endpoint's exact read), so a render path
     * never shows a member the related endpoint would not.
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
        $scoped = $this->scopedRelatedProvider($parentType, $relatedType);
        if ($scoped === null) {
            return $this->forType($parentType)->fetchRelatedCollectionBatch($parentType, $parents, $relation, $criteria, $request);
        }

        $results = [];
        foreach ($parents as $parent) {
            $results[$wireIdOf($parent)] = $scoped->fetchRelatedCollection($relatedType, $parent, $relation, $criteria, $request);
        }

        return new RelatedBatch($results);
    }
}
