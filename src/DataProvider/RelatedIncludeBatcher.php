<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\DataProvider;

use haddowg\JsonApi\Collection\CollectionResult;
use haddowg\JsonApi\Operation\QueryParameters;
use haddowg\JsonApi\Request\JsonApiRequestInterface;
use haddowg\JsonApi\Resource\Field\Accessor;
use haddowg\JsonApi\Resource\Field\RelationInterface;
use haddowg\JsonApi\Serializer\IncludeControlsInterface;
use haddowg\JsonApi\Serializer\SerializerInterface;
use haddowg\JsonApi\Server\Server;
use haddowg\JsonApiLaravel\Server\TypeMetadataResolver;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Model;

/**
 * Laravel-style batch eager-loading of a read's effective `?include` tree, so an included
 * relationship does not N+1 (PLAN decision 8): one batched call loads a relation for every
 * source entity at a level, then its loaded targets seed the next level. Provider-agnostic
 * — the per-relation load runs through the SPI's
 * {@see DataProviderInterface::fetchRelatedCollectionBatch()} and a write-back, so it
 * batches includes for EVERY batching provider (the in-memory witness AND the Eloquent
 * reference) per level, with no per-provider branching.
 *
 * Each level loads in PLAIN-INCLUDE (fast-path) mode: an empty filter/sort criteria with a
 * NULL window, so the batch loads the WHOLE related set per parent — byte-for-byte the rows
 * a lazy read would have materialised. The write-back is provider-aware (PLAN decision 8):
 *  - an **Eloquent model** takes `$model->setRelation($column, $value)` — which makes
 *    `relationLoaded()` true, so the load-state seam then reports loaded and core reads the
 *    linkage off the model without a query;
 *  - any **other object** (an in-memory POPO) takes {@see Accessor::set} — idempotent for
 *    the witness, whose relation property already holds the whole set.
 *
 * Batching is a pure optimization: a relation/provider that cannot batch falls back to a
 * lazy load and the rendered document is identical. The orchestrator skips such a relation
 * (the serializer reads it off the parent on demand):
 *  - a **polymorphic** relation (more than one related type) is skipped here, so even the
 *    in-memory provider (which CAN read a mixed set) is left lazy, keeping includes
 *    byte-identical across providers;
 *  - a related type with no batching provider is skipped;
 *  - a relation the provider cannot batch returns an empty {@see RelatedBatch}, so the
 *    write-back is a no-op and the relation renders lazily.
 *
 * Beyond the `?include` tree it also preloads the one relation class that renders linkage
 * on EVERY read regardless of `?include` — an **eager monomorphic to-one** (`BelongsTo`/
 * `MorphTo`, or a `withData()` override) — for an Eloquent page ({@see preloadEagerLinkage()}).
 * Core never consults the load-state seam for an eager relation, so without this an Eloquent
 * collection would lazy-load that to-one once per parent just to emit identifiers (the N+1
 * PLAN decision 8 eliminates). It rides the SAME batched fast path + `setRelation` write-back
 * and does not recurse; it is inert for the in-memory witness (whose value is already in
 * memory) so the two providers stay byte-identical.
 *
 * It honours the three include safeguards (core ADR 0037), resolved once against the ROOT
 * resource and threaded unchanged through the recursion: it never batches a non-includable
 * relation, never descends past the effective max include depth, and never batches a path
 * the root resource's allowed-include-paths whitelist excludes. A request that violates any
 * of these `400`s in core before this runs, so this is belt and braces — and the recursion
 * is bounded by the same effective depth so a mutual default-include cycle terminates here.
 *
 * The `on()` eager-load pass the bundle also runs (flattened to-one chains) is a phase-4
 * flattened-attribute concern with no declarations in this package yet, so it is a
 * documented seam here (inert without `on()`), not ported.
 */
final class RelatedIncludeBatcher
{
    /**
     * Process-wide on/off for include batching — the disable seam the conformance witness
     * toggles to prove the rendered document is identical with and without batching (and
     * that disabling it reveals the N+1). When false {@see preload()} early-returns, so
     * every relation renders lazily. Not readonly: the witness flips it at runtime.
     *
     * @internal a test/diagnostic seam, not part of any contract
     */
    private bool $enabled = true;

    public function __construct(
        private readonly DataProviderRegistry $providers,
        private readonly TypeMetadataResolver $types,
    ) {}

    /**
     * Disables include batching process-wide (the witness's cold-read seam). Batching is a
     * pure optimization, so turning it off only changes HOW includes are loaded (lazily),
     * never WHAT is rendered.
     *
     * @internal
     */
    public function disable(): void
    {
        $this->enabled = false;
    }

    /**
     * Re-enables include batching process-wide (restores the default).
     *
     * @internal
     */
    public function enable(): void
    {
        $this->enabled = true;
    }

    /**
     * Batch-loads the effective include tree rooted at the `$type` entities in
     * `$entities`, recursing one level per `.`-separated include segment. A no-op when
     * batching is disabled, there are no entities, the type has no provider/serializer, or
     * no relation at this level is included.
     *
     * @param iterable<object>  $entities
     * @param ?int              $maxDepth     the effective include-depth cap resolved at the root (null = unlimited)
     * @param list<string>|null $allowedPaths the root resource's allowed-include-paths whitelist (null = unrestricted)
     */
    public function preload(
        Server $server,
        iterable $entities,
        string $type,
        JsonApiRequestInterface $request,
        string $basePath = '',
        ?int $maxDepth = null,
        ?array $allowedPaths = null,
        bool $rootResolved = false,
    ): void {
        if (!$this->enabled) {
            return;
        }

        $entities = $this->materialize($entities);
        if ($entities === []) {
            return;
        }

        if (!$this->providers->supportsType($type) || !$server->hasSerializerFor($type)) {
            return;
        }

        $relations = $this->types->relationsFor($server, $type);
        if ($relations === []) {
            return;
        }

        $serializer = $server->serializerFor($type);

        // Resolve the root-scoped safeguards once: the effective depth cap and the
        // allowed-include-paths whitelist. Both ride the recursion unchanged — they are a
        // property of the root resource.
        if (!$rootResolved) {
            $maxDepth = $this->effectiveMaxDepth($serializer, $server);
            $allowedPaths = $serializer instanceof IncludeControlsInterface
                ? $serializer->getAllowedIncludePaths()
                : null;
            $rootResolved = true;
        }

        $resource = $this->types->resourceFor($server, $type);
        $defaults = $resource === null
            ? []
            : \array_flip($resource->getDefaultIncludedRelationships($entities[0]));

        foreach ($relations as $relation) {
            $childPath = $basePath === '' ? $relation->name() : $basePath . '.' . $relation->name();

            // A relation in the effective ?include tree — includable, requested-or-default,
            // within the depth cap AND the allowed-paths whitelist (each resolved once off
            // the first entity as the representative; the transformer stays the per-object
            // rendering authority) — is batch-loaded AND recursed into for nested includes.
            if ($relation->isIncludableFor($request, $entities[0])
                && $request->isIncludedRelationship($basePath, $relation->name(), $defaults)
                && ($maxDepth === null || (\substr_count($childPath, '.') + 1) <= $maxDepth)
                && ($allowedPaths === null || \in_array($childPath, $allowedPaths, true))) {
                $this->loadRelation($server, $entities, $type, $relation, $request, $childPath, $maxDepth, $allowedPaths);

                continue;
            }

            // Otherwise the relation is not expanded into the compound document — but an
            // EAGER monomorphic to-one (BelongsTo/MorphTo, or a withData() override) still
            // emits its linkage `data` on every read regardless of ?include. Core never
            // consults the load-state seam for an eager relation, so on an Eloquent page that
            // is one lazy round-trip PER PARENT just to serialize identifiers — the N+1 PLAN
            // decision 8 set out to eliminate ("preload via setRelation BEFORE any readValue").
            // Preload it through the SAME batched fast path so the linkage renders from the
            // loaded relation in ONE query for the whole page.
            $this->preloadEagerLinkage($server, $entities, $type, $relation, $request);
        }
    }

    /**
     * Batch-preloads a relation that renders linkage `data` on every read but is NOT part of
     * the ?include tree — an eager monomorphic to-one — so an Eloquent page emits its
     * identifiers without a per-parent lazy load. It reuses the include fast path's batched
     * load + `setRelation` write-back and DISCARDS the loaded targets (an eager linkage
     * preload never recurses — its targets render as identifiers, not as included resources).
     *
     * Inert unless every condition holds, so it changes only HOW an Eloquent page's eager
     * to-one linkage is loaded, never WHAT any provider renders:
     *  - the parent is an **Eloquent model** — a POPO already holds the related value in
     *    memory (the in-memory witness renders its linkage with no query), so preloading it
     *    would be pure overhead and is skipped, leaving the witness's document and its
     *    (zero) query profile untouched;
     *  - the relation is **eager** ({@see RelationInterface::emitsDataOnlyWhenLoaded()} is
     *    `false`) — a lazy relation renders links-only via the load-state seam until it is
     *    `?include`d, so it is never read off the parent here;
     *  - the relation is **monomorphic** — a polymorphic to-one (`MorphTo`) has no single
     *    batching provider, so the orchestrator leaves it lazy exactly as the include path does.
     *
     * @param list<object> $entities
     */
    private function preloadEagerLinkage(
        Server $server,
        array $entities,
        string $type,
        RelationInterface $relation,
        JsonApiRequestInterface $request,
    ): void {
        if (!$entities[0] instanceof Model
            || $relation->emitsDataOnlyWhenLoaded()
            || \count($relation->relatedTypes()) !== 1) {
            return;
        }

        $this->executeLoad($server, $entities, $type, $relation, $request);
    }

    /**
     * Batch-loads a single included relation across `$entities` (through
     * {@see executeLoad()}), then recurses into the loaded targets for nested includes. A
     * polymorphic relation, or a related type with no batching provider, is left to a lazy
     * load (the orchestrator simply does not batch it).
     *
     * @param list<object>      $entities
     * @param ?int              $maxDepth     the effective include-depth cap (null = unlimited)
     * @param list<string>|null $allowedPaths the root resource's allowed-include-paths whitelist (null = unrestricted)
     */
    private function loadRelation(
        Server $server,
        array $entities,
        string $type,
        RelationInterface $relation,
        JsonApiRequestInterface $request,
        string $childPath,
        ?int $maxDepth,
        ?array $allowedPaths,
    ): void {
        $relatedTypes = $relation->relatedTypes();
        if (\count($relatedTypes) !== 1) {
            return;
        }

        $targets = $this->executeLoad($server, $entities, $type, $relation, $request);
        if ($targets === null) {
            return;
        }

        // Thread the root-scoped safeguards into the next level unchanged.
        $this->preload($server, $targets, $relatedTypes[0], $request, $childPath, $maxDepth, $allowedPaths, rootResolved: true);
    }

    /**
     * Batch-loads `$relation` across `$entities` through the PRIMARY type's provider in
     * PLAIN-INCLUDE (fast-path) mode — an empty filter/sort criteria with a NULL window
     * loads the WHOLE related set per parent — and writes each parent's result back onto
     * its relation column, returning the flat list of loaded targets (to seed a nested
     * include level). Returns `null` when the relation cannot be batched (a polymorphic
     * relation, or a related type with no batching provider), so the column is left
     * untouched and the relation renders lazily.
     *
     * @param list<object> $entities
     *
     * @return list<object>|null the loaded targets, or `null` when the relation cannot be batched
     */
    private function executeLoad(
        Server $server,
        array $entities,
        string $type,
        RelationInterface $relation,
        JsonApiRequestInterface $request,
    ): ?array {
        $relatedTypes = $relation->relatedTypes();
        if (\count($relatedTypes) !== 1 || !$this->providers->supportsType($relatedTypes[0])) {
            return null;
        }

        $column = $relation->column() ?? $relation->name();

        // The write-back needs somewhere to land — an Eloquent relation (via setRelation),
        // a declared property, a setter, or dynamic-property support. Otherwise the
        // relation renders lazily instead of creating a deprecated dynamic property.
        if (!$this->columnTakesWriteBack($entities[0], $column)) {
            return null;
        }

        $batch = $this->providers->forType($type)
            ->fetchRelatedCollectionBatch($type, $entities, $relation, $this->plainIncludeCriteria($request), $request);

        $serializer = $server->serializerFor($type);

        $targets = [];
        foreach ($entities as $entity) {
            $result = $batch->for($serializer->getId($entity));
            $items = $this->itemsOf($result);
            $this->writeBack($entity, $relation, $column, $items);
            foreach ($items as $target) {
                $targets[] = $target;
            }
        }

        return $targets;
    }

    /**
     * Writes a parent's batched-loaded related value back onto its relation column. An
     * Eloquent model takes {@see Model::setRelation()} (a to-many wrapped in an
     * {@see EloquentCollection}, a to-one the single model or `null`) — which makes
     * `relationLoaded()` true so the load-state seam reports loaded (PLAN decision 8); any
     * other object (an in-memory POPO) takes {@see Accessor::set}. A to-one always writes
     * `items[0] ?? null`, never an array/CollectionResult.
     *
     * @param list<object> $loaded
     */
    private function writeBack(object $entity, RelationInterface $relation, string $column, array $loaded): void
    {
        if ($entity instanceof Model) {
            if ($relation->isToMany()) {
                // A batched related set is Models for an Eloquent parent (the provider
                // returns them); wrap in an EloquentCollection so `relationLoaded()` and
                // the render read an idiomatic to-many relation value.
                /** @var list<Model> $models */
                $models = $loaded;
                $entity->setRelation($column, new EloquentCollection($models));
            } else {
                $entity->setRelation($column, $loaded[0] ?? null);
            }

            return;
        }

        Accessor::set($entity, $column, $relation->isToMany() ? $loaded : ($loaded[0] ?? null));
    }

    /**
     * Whether the write-back can land `$column` on `$entity`: an Eloquent model (via
     * `setRelation`, always), a declared property, a `setX()` method, explicit
     * dynamic-property support, or an {@see \ArrayAccess}. False for a relation exposed
     * over a column a plain object does not carry (a computed `extractUsing` view), so it
     * renders lazily instead of gaining a deprecated dynamic property.
     */
    private function columnTakesWriteBack(object $entity, string $column): bool
    {
        return $entity instanceof Model
            || \property_exists($entity, $column)
            || \method_exists($entity, 'set' . \ucfirst($column))
            || \method_exists($entity, '__set')
            || $entity instanceof \stdClass
            || $entity instanceof \ArrayAccess
            || (new \ReflectionClass($entity))->getAttributes(\AllowDynamicProperties::class) !== [];
    }

    /**
     * The plain-include (fast-path) criteria: empty filters/sorts and a NULL window, so
     * {@see DataProviderInterface::fetchRelatedCollectionBatch()} loads the WHOLE related
     * set per parent with no slice.
     */
    private function plainIncludeCriteria(JsonApiRequestInterface $request): CollectionCriteria
    {
        return new CollectionCriteria(
            new QueryParameters(
                fields: [],
                includes: [],
                sort: [],
                filter: [],
                pagination: $request->getPagination(),
            ),
        );
    }

    /**
     * Materializes a {@see CollectionResult}'s items to a `list<object>`.
     *
     * @param CollectionResult<object> $result
     *
     * @return list<object>
     */
    private function itemsOf(CollectionResult $result): array
    {
        $items = \is_array($result->items)
            ? \array_values($result->items)
            : \iterator_to_array($result->items, false);

        return \array_values(\array_filter($items, static fn(mixed $item): bool => \is_object($item)));
    }

    /**
     * The effective include-depth cap for a root `$serializer` on `$server`: the
     * resource's own {@see IncludeControlsInterface::maxIncludeDepth()} override `??` the
     * server default, normalised so a non-positive value means unlimited (`null`).
     */
    private function effectiveMaxDepth(SerializerInterface $serializer, Server $server): ?int
    {
        $depth = ($serializer instanceof IncludeControlsInterface ? $serializer->maxIncludeDepth() : null)
            ?? $server->maxIncludeDepth();

        return ($depth !== null && $depth <= 0) ? null : $depth;
    }

    /**
     * Materializes `$entities` to a `list<object>`.
     *
     * @param iterable<object> $entities
     *
     * @return list<object>
     */
    private function materialize(iterable $entities): array
    {
        if (\is_array($entities)) {
            return \array_values($entities);
        }

        return \iterator_to_array($entities, false);
    }
}
