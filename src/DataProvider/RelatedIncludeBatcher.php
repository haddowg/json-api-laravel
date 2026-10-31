<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\DataProvider;

use haddowg\JsonApi\Collection\CollectionResult;
use haddowg\JsonApi\Operation\QueryParameters;
use haddowg\JsonApi\Request\JsonApiRequestInterface;
use haddowg\JsonApi\Resource\Field\RelationInterface;
use haddowg\JsonApi\Schema\Relationship\RelationshipLinkage;
use haddowg\JsonApi\Schema\Relationship\ToOneRelationshipLinkage;
use haddowg\JsonApi\Serializer\IncludeControlsInterface;
use haddowg\JsonApi\Serializer\RelationshipLinkageInterface;
use haddowg\JsonApi\Serializer\SerializerInterface;
use haddowg\JsonApi\Server\Server;
use haddowg\JsonApiLaravel\Serializer\WindowedRelationshipLinkage;
use haddowg\JsonApiLaravel\Server\TypeMetadataResolver;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Model;

/**
 * Laravel-style batch eager-loading of a read's effective `?include` tree, so an included
 * relationship does not N+1 (PLAN decision 8): one batched call loads a relation for every
 * source entity at a level, then its loaded targets seed the next level. Provider-agnostic
 * — the per-relation load runs through the SPI's
 * {@see DataProviderInterface::fetchRelatedCollectionBatch()}, so it
 * batches includes for EVERY batching provider (the in-memory witness AND the Eloquent
 * reference) per level, with no per-provider branching.
 *
 * Each level loads in PLAIN-INCLUDE (fast-path) mode: an empty filter/sort criteria with a
 * NULL window, so the batch loads the WHOLE related set per parent — byte-for-byte the rows
 * a lazy read would have materialised. How a parent takes its batched value depends on the
 * parent (PLAN decision 8):
 *  - an **Eloquent model** takes `$model->setRelation($column, $value)` — which makes
 *    `relationLoaded()` true, so the load-state seam then reports loaded and core reads the
 *    linkage off the model without a query;
 *  - any **other object** (an in-memory POPO) is left untouched and gets the value as
 *    out-of-band linkage for this request. An in-memory record is shared by every request, so
 *    a write onto it would outlive the read.
 *
 * Batching is a pure optimization: a relation/provider that cannot batch falls back to a
 * lazy load and the rendered document is identical. The orchestrator skips such a relation
 * (the serializer reads it off the parent on demand):
 *  - a **polymorphic** relation (more than one related type) is skipped here, so even the
 *    in-memory provider (which CAN read a mixed set) is left lazy, keeping includes
 *    byte-identical across providers;
 *  - a related type with no batching provider is skipped;
 *  - a parent the provider's {@see RelatedBatch} holds no entry for was not loaded, so it
 *    renders its own relation. A provider that cannot batch a relation returns an empty
 *    batch, and every parent renders lazily.
 *
 * A to-many whose related type is served by its own provider (not the parent's, not a
 * reference provider) is different: that provider may scope its rows, and the related endpoint
 * reads the members through it. Such a relation is never batched through the parent or read
 * off it. Its members load through the related provider, per parent, whenever they render
 * (included, or as linkage without an include), batching on or off, and are handed back as
 * out-of-band linkage rather than written onto the parent. A polymorphic relation reaching
 * such a type has its members narrowed per member type instead.
 *
 * Beyond the `?include` tree it also preloads the one relation class that renders linkage
 * on EVERY read regardless of `?include` — an **eager monomorphic to-one** (`BelongsTo`/
 * `MorphTo`, or a `withData()` override) — for an Eloquent page ({@see preloadEagerLinkage()}).
 * Core never consults the load-state seam for an eager relation, so without this an Eloquent
 * collection would lazy-load that to-one once per parent just to emit identifiers (the N+1
 * PLAN decision 8 eliminates). It rides the SAME batched fast path + `setRelation`
 * and does not recurse; it is inert for the in-memory witness (whose value is already in
 * memory) so the two providers stay byte-identical.
 *
 * It honours the three include safeguards, resolved once against the ROOT
 * resource and threaded unchanged through the recursion: it never batches a non-includable
 * relation, never descends past the effective max include depth, and never batches a path
 * the root resource's allowed-include-paths whitelist excludes. A request that violates any
 * of these `400`s in core before this runs, so this is belt and braces — and the recursion
 * is bounded by the same effective depth so a mutual default-include cycle terminates here.
 *
 * A `MorphTo` declared with `identifiedBy()` renders its linkage from the pair its parent stores.
 * Its targets load through each type's own provider, one read per type for the level, batching on
 * or off, since nothing else can read them ({@see IdentifiedMorphTargets}): every type's
 * when included, and the nested include walk continues from them, otherwise only the guarded
 * types', so a hidden target's linkage renders as none.
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
     * that disabling it reveals the N+1). When false {@see preload()} batches nothing, so
     * every relation renders lazily, except one whose related type has its own scoping
     * provider, which still loads through it. Not readonly: the witness flips it at runtime.
     *
     * @internal a test/diagnostic seam, not part of any contract
     */
    private bool $enabled = true;

    public function __construct(
        private readonly DataProviderRegistry $providers,
        private readonly TypeMetadataResolver $types,
        private readonly ScopedPolymorphicMembers $polymorphicMembers,
        private readonly IdentifiedMorphTargets $identified,
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
     * `$entities`, recursing one level per `.`-separated include segment, and returns the
     * linkage of every rendered to-many whose related type is served by its own scoping
     * provider (see {@see DataProviderRegistry::scopedRelatedProvider()}), for the caller to
     * supply out-of-band. `null` when there is none.
     *
     * Those scoped relations are loaded through the related type's provider whether batching is
     * enabled or not, included or only rendered as linkage: the related endpoint reads them
     * that way, and nothing else may show more. A polymorphic relation reaching a scoped type
     * has no such read, so rendering its members is refused rather than read off the parent.
     *
     * @param iterable<object> $entities
     */
    public function preload(
        Server $server,
        iterable $entities,
        string $type,
        JsonApiRequestInterface $request,
    ): ?WindowedRelationshipLinkage {
        $linkages = [];
        $reach = [];
        $this->preloadLevel($server, $this->materialize($entities), $type, $request, '', null, null, false, $this->enabled, $linkages, $reach);

        return $linkages === [] ? null : new WindowedRelationshipLinkage($linkages);
    }

    /**
     * Continues {@see preload()} below the windowed to-many relations of `$parents`: the
     * Relationship Queries window fetches its own copies of the members it renders, so the
     * preload never saw them. Only relations whose related type has its own scoping provider
     * are loaded here (batching stays as it was), returning their out-of-band linkage.
     *
     * @param list<object> $parents
     */
    public function preloadWindowed(
        Server $server,
        string $type,
        array $parents,
        RelationshipLinkageInterface $windowed,
        JsonApiRequestInterface $request,
    ): ?WindowedRelationshipLinkage {
        if ($parents === [] || !$server->hasSerializerFor($type)) {
            return null;
        }

        $serializer = $server->serializerFor($type);
        $maxDepth = $this->effectiveMaxDepth($serializer, $server);
        $allowedPaths = $serializer instanceof IncludeControlsInterface ? $serializer->getAllowedIncludePaths() : null;
        $resource = $this->types->resourceFor($server, $type);
        $defaults = $resource === null ? [] : \array_flip($resource->getDefaultIncludedRelationships($parents[0]));

        $linkages = [];
        $reach = [];
        foreach ($this->types->readableRelationsFor($server, $type, $request) as $relation) {
            $relatedTypes = $relation->relatedTypes();
            if (!$relation->isToMany()
                || \count($relatedTypes) !== 1
                || !$relation->isIncludableFor($request, $parents[0])
                || !$request->isIncludedRelationship('', $relation->name(), $defaults)
                || ($maxDepth !== null && $maxDepth < 1)
                || ($allowedPaths !== null && !\in_array($relation->name(), $allowedPaths, true))
                || !$this->reachesScopedRelation($server, $relatedTypes, $request, $reach)) {
                continue;
            }

            $targets = [];
            foreach ($parents as $parent) {
                $page = $windowed->linkageForRelationship($parent, $relation, $request);
                if (\is_iterable($page?->data)) {
                    foreach ($page->data as $target) {
                        if (\is_object($target)) {
                            $targets[] = $target;
                        }
                    }
                }
            }

            $this->preloadLevel($server, $targets, $relatedTypes[0], $request, $relation->name(), $maxDepth, $allowedPaths, true, false, $linkages, $reach);
        }

        return $linkages === [] ? null : new WindowedRelationshipLinkage($linkages);
    }

    /**
     * One level of {@see preload()}.
     *
     * @param list<object>                                                            $entities
     * @param ?int                                                                    $maxDepth     the effective include-depth cap resolved at the root (null = unlimited)
     * @param list<string>|null                                                       $allowedPaths the root resource's allowed-include-paths whitelist (null = unrestricted)
     * @param bool                                                                    $batch        whether trusted relations at this level are batch-loaded
     * @param array<int, array<string, RelationshipLinkage|ToOneRelationshipLinkage>> $linkages     the scoped linkage collected so far
     * @param array<string, bool>                                                     $reach        memo for {@see reachesScopedRelation()}
     */
    private function preloadLevel(
        Server $server,
        array $entities,
        string $type,
        JsonApiRequestInterface $request,
        string $basePath,
        ?int $maxDepth,
        ?array $allowedPaths,
        bool $rootResolved,
        bool $batch,
        array &$linkages,
        array &$reach,
    ): void {
        if ($entities === [] || !$server->hasSerializerFor($type)) {
            return;
        }

        $relations = $this->types->readableRelationsFor($server, $type, $request);
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
            // rendering authority) — is loaded AND recursed into for nested includes.
            $included = $relation->isIncludableFor($request, $entities[0])
                && $request->isIncludedRelationship($basePath, $relation->name(), $defaults)
                && ($maxDepth === null || (\substr_count($childPath, '.') + 1) <= $maxDepth)
                && ($allowedPaths === null || \in_array($childPath, $allowedPaths, true));

            if (IdentifiedMorphTargets::identifies($relation)) {
                if (!$included) {
                    $this->identified->load($entities, $relation, $request, $this->identified->guardedTypes($relation));

                    continue;
                }

                foreach ($this->identified->load($entities, $relation, $request) as $targetType => $targets) {
                    $this->preloadLevel($server, $targets, $targetType, $request, $childPath, $maxDepth, $allowedPaths, true, $batch, $linkages, $reach);
                }

                continue;
            }

            if ($relation->isToMany() && $this->providers->relatesToScopedType($type, $relation)) {
                $targets = $this->loadScoped($server, $entities, $type, $relation, $request, $included, $linkages);
                if ($included) {
                    foreach ($targets as $targetType => $typed) {
                        $this->preloadLevel($server, $typed, $targetType, $request, $childPath, $maxDepth, $allowedPaths, true, $batch, $linkages, $reach);
                    }
                }

                continue;
            }

            if ($included) {
                $this->loadRelation($server, $entities, $type, $relation, $request, $childPath, $maxDepth, $allowedPaths, $batch, $linkages, $reach);

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
            if ($batch) {
                $this->preloadEagerLinkage($server, $entities, $type, $relation, $request, $linkages);
            }
        }
    }

    /**
     * Loads a to-many whose related type is served by its own scoping provider through that
     * provider, one {@see DataProviderInterface::fetchRelatedCollection()} per parent (the
     * related endpoint's read), for every parent that renders the relation's data: all of them
     * when it is included, otherwise those whose linkage renders anyway. Each parent's members
     * go into `$linkages` for out-of-band rendering, never onto the parent, which may be a
     * shared in-memory object. Returns the loaded members of an included relation.
     *
     * A polymorphic relation has no single provider to load through, so its members are loaded
     * off each parent as the candidates and narrowed per member type through each scoped type's
     * provider, in one lookup per type for the whole level ({@see ScopedPolymorphicMembers}).
     *
     * @param list<object>                                                            $entities
     * @param array<int, array<string, RelationshipLinkage|ToOneRelationshipLinkage>> $linkages
     *
     * @return array<string, list<object>> the loaded members of an included relation, by type
     */
    private function loadScoped(
        Server $server,
        array $entities,
        string $type,
        RelationInterface $relation,
        JsonApiRequestInterface $request,
        bool $included,
        array &$linkages,
    ): array {
        $relatedTypes = $relation->relatedTypes();
        $rendering = $included
            ? $entities
            : \array_values(\array_filter($entities, fn(object $entity): bool => $this->rendersData($server, $entity, $relation)));

        if ($rendering === []) {
            return [];
        }

        if (\count($relatedTypes) !== 1) {
            $candidates = [];
            foreach ($rendering as $entity) {
                $value = $relation->readValue($entity, $request);
                $candidates[\spl_object_id($entity)] = \is_iterable($value) ? $value : [];
            }

            $targets = [];
            foreach ($this->polymorphicMembers->narrow($server, $type, $relation, $candidates) as $objectId => $items) {
                $linkages[$objectId][$relation->name()] = new RelationshipLinkage($items);
                foreach ($items as $item) {
                    $memberType = $relation->resolveSerializer($item, $server)?->getType($item);
                    if ($memberType !== null) {
                        $targets[$memberType][] = $item;
                    }
                }
            }

            return $targets;
        }

        $provider = $this->providers->scopedRelatedProvider($type, $relatedTypes[0]);
        \assert($provider !== null);

        $criteria = $this->plainIncludeCriteria($request);
        $targets = [];
        foreach ($rendering as $entity) {
            $items = $this->itemsOf($provider->fetchRelatedCollection($relatedTypes[0], $entity, $relation, $criteria, $request));
            $linkages[\spl_object_id($entity)][$relation->name()] = new RelationshipLinkage($items);
            foreach ($items as $item) {
                $targets[$relatedTypes[0]][] = $item;
            }
        }

        return $targets;
    }

    /**
     * Whether `$relation`'s linkage `data` renders on `$entity` without an include: an eager
     * relation always does, a lazy one when the load-state seam reports it loaded (or there is
     * no seam, as for the in-memory witness). Mirrors core's linkage deferral.
     */
    private function rendersData(Server $server, object $entity, RelationInterface $relation): bool
    {
        if (!$relation->emitsDataOnlyWhenLoaded() || !$relation->includesLinks()) {
            return true;
        }

        $loadState = $server->relationshipLoadState();

        return $loadState === null || $loadState->isRelationshipLoaded($entity, $relation);
    }

    /**
     * Whether a relation reachable from any of `$types` (themselves included) relates to a
     * type served by its own scoping provider, or is an identified `MorphTo`, so an unbatched
     * level must still be walked to reach it. Resolved for the whole reachable type graph at once, cycles included, and
     * memoized in `$reach` per {@see preload()} call.
     *
     * @param list<string>        $types
     * @param array<string, bool> $reach
     */
    private function reachesScopedRelation(Server $server, array $types, JsonApiRequestInterface $request, array &$reach): bool
    {
        $edges = [];
        $queue = $types;
        while ($queue !== []) {
            $type = \array_pop($queue);
            if (isset($edges[$type]) || \array_key_exists($type, $reach)) {
                continue;
            }

            $edges[$type] = [];
            $seed = false;
            foreach ($this->types->readableRelationsFor($server, $type, $request) as $relation) {
                $seed = $seed
                    || ($relation->isToMany() && $this->providers->relatesToScopedType($type, $relation))
                    || IdentifiedMorphTargets::identifies($relation);
                foreach ($relation->relatedTypes() as $relatedType) {
                    $edges[$type][] = $relatedType;
                    $queue[] = $relatedType;
                }
            }
            $reach[$type] = $seed;
        }

        do {
            $changed = false;
            foreach ($edges as $type => $relatedTypes) {
                if ($reach[$type]) {
                    continue;
                }
                foreach ($relatedTypes as $relatedType) {
                    if ($reach[$relatedType] ?? false) {
                        $reach[$type] = true;
                        $changed = true;

                        break;
                    }
                }
            }
        } while ($changed);

        foreach ($types as $type) {
            if ($reach[$type] ?? false) {
                return true;
            }
        }

        return false;
    }

    /**
     * Batch-preloads a relation that renders linkage `data` on every read but is NOT part of
     * the ?include tree — an eager monomorphic to-one — so an Eloquent page emits its
     * identifiers without a per-parent lazy load. It reuses the include fast path's batched
     * load + `setRelation` and DISCARDS the loaded targets (an eager linkage
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
     * @param list<object>                                                            $entities
     * @param array<int, array<string, RelationshipLinkage|ToOneRelationshipLinkage>> $linkages
     */
    private function preloadEagerLinkage(
        Server $server,
        array $entities,
        string $type,
        RelationInterface $relation,
        JsonApiRequestInterface $request,
        array &$linkages,
    ): void {
        if (!$entities[0] instanceof Model
            || $relation->emitsDataOnlyWhenLoaded()
            || \count($relation->relatedTypes()) !== 1) {
            return;
        }

        $this->executeLoad($server, $entities, $type, $relation, $request, $linkages);
    }

    /**
     * Loads a single included relation across `$entities` and recurses into the loaded
     * targets for nested includes. With batching on, a monomorphic relation is batch-loaded
     * (through {@see executeLoad()}). Otherwise the relation renders lazily, and its targets are
     * only read off the parents here when a scoped relation is reachable below them, so that
     * level is still scoped; a polymorphic relation's targets are grouped by their own type.
     *
     * @param list<object>                                                            $entities
     * @param ?int                                                                    $maxDepth     the effective include-depth cap (null = unlimited)
     * @param list<string>|null                                                       $allowedPaths the root resource's allowed-include-paths whitelist (null = unrestricted)
     * @param array<int, array<string, RelationshipLinkage|ToOneRelationshipLinkage>> $linkages
     * @param array<string, bool>                                                     $reach
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
        bool $batch,
        array &$linkages,
        array &$reach,
    ): void {
        $relatedTypes = $relation->relatedTypes();
        $monomorphic = \count($relatedTypes) === 1;

        $targets = $batch && $monomorphic ? $this->executeLoad($server, $entities, $type, $relation, $request, $linkages) : null;
        if ($targets !== null) {
            // Thread the root-scoped safeguards into the next level unchanged.
            $this->preloadLevel($server, $targets, $relatedTypes[0], $request, $childPath, $maxDepth, $allowedPaths, true, $batch, $linkages, $reach);

            return;
        }

        if (!$this->reachesScopedRelation($server, $relatedTypes, $request, $reach)) {
            return;
        }

        $byType = [];
        foreach ($entities as $entity) {
            foreach ($this->readTargets($entity, $relation, $request) as $target) {
                $targetType = $monomorphic ? $relatedTypes[0] : $relation->resolveSerializer($target, $server)?->getType($target);
                if ($targetType !== null) {
                    $byType[$targetType][] = $target;
                }
            }
        }

        foreach ($byType as $targetType => $targets) {
            $this->preloadLevel($server, $targets, $targetType, $request, $childPath, $maxDepth, $allowedPaths, true, $batch && $monomorphic, $linkages, $reach);
        }
    }

    /**
     * The related object(s) `$relation` holds on `$entity`, read the way the render reads them.
     *
     * @return list<object>
     */
    private function readTargets(object $entity, RelationInterface $relation, JsonApiRequestInterface $request): array
    {
        $value = $relation->readValue($entity, $request);

        if (\is_object($value) && !\is_iterable($value)) {
            return [$value];
        }

        if (!\is_iterable($value)) {
            return [];
        }

        $targets = [];
        foreach ($value as $target) {
            if (\is_object($target)) {
                $targets[] = $target;
            }
        }

        return $targets;
    }

    /**
     * Batch-loads `$relation` across `$entities` through the PRIMARY type's provider in
     * PLAIN-INCLUDE (fast-path) mode — an empty filter/sort criteria with a NULL window
     * loads the WHOLE related set per parent — and returns the flat list of targets every
     * parent renders (to seed a nested include level). Returns `null` when the relation cannot
     * be batched (a polymorphic relation, or a related type with no batching provider), so the
     * relation renders lazily.
     *
     * A parent the batch loaded renders the batched value: an Eloquent model takes it as its
     * loaded relation, any other object gets it as linkage for this request in `$linkages`,
     * never written onto the object, which may be a record every request shares. A parent the
     * batch did not load, every parent of a provider that does not batch, renders its own
     * relation, and that is what seeds the next level for it.
     *
     * @param list<object>                                                            $entities
     * @param array<int, array<string, RelationshipLinkage|ToOneRelationshipLinkage>> $linkages
     *
     * @return list<object>|null the targets, or `null` when the relation cannot be batched
     */
    private function executeLoad(
        Server $server,
        array $entities,
        string $type,
        RelationInterface $relation,
        JsonApiRequestInterface $request,
        array &$linkages,
    ): ?array {
        $relatedTypes = $relation->relatedTypes();
        if (\count($relatedTypes) !== 1
            || !$this->providers->supportsType($relatedTypes[0])
            || !$this->providers->supportsType($type)) {
            return null;
        }

        $column = $relation->column() ?? $relation->name();

        // A relation over a column a plain object does not carry (a computed `extractUsing`
        // view) renders lazily.
        if (!$this->carriesColumn($entities[0], $column)) {
            return null;
        }

        $batch = $this->providers->forType($type)
            ->fetchRelatedCollectionBatch($type, $entities, $relation, $this->plainIncludeCriteria($request), $request);

        $serializer = $server->serializerFor($type);

        $targets = [];
        foreach ($entities as $entity) {
            $wireId = $serializer->getId($entity);
            if (!$batch->has($wireId)) {
                foreach ($this->readTargets($entity, $relation, $request) as $target) {
                    $targets[] = $target;
                }

                continue;
            }

            $items = $this->itemsOf($batch->for($wireId));
            $this->hold($entity, $relation, $column, $items, $linkages);
            foreach ($items as $target) {
                $targets[] = $target;
            }
        }

        return $targets;
    }

    /**
     * Makes a parent render its batched related value. An Eloquent model takes
     * {@see Model::setRelation()} (a to-many wrapped in an {@see EloquentCollection}, a to-one
     * the single model or `null`), the same write its own lazy load makes, so `relationLoaded()`
     * is true and the load-state seam reports loaded (PLAN decision 8). Any other object gets the
     * value as out-of-band linkage in `$linkages` and is left as it is.
     *
     * @param list<object>                                                            $loaded
     * @param array<int, array<string, RelationshipLinkage|ToOneRelationshipLinkage>> $linkages
     */
    private function hold(object $entity, RelationInterface $relation, string $column, array $loaded, array &$linkages): void
    {
        if ($entity instanceof Model) {
            if ($relation->isToMany()) {
                /** @var list<Model> $models */
                $models = $loaded;
                $entity->setRelation($column, new EloquentCollection($models));
            } else {
                $entity->setRelation($column, $loaded[0] ?? null);
            }

            return;
        }

        $linkages[\spl_object_id($entity)][$relation->name()] = $relation->isToMany()
            ? new RelationshipLinkage($loaded)
            : ($loaded === [] ? ToOneRelationshipLinkage::none() : ToOneRelationshipLinkage::to($loaded[0]));
    }

    /**
     * Whether `$entity` carries `$column` as a relation: an Eloquent model (always), a
     * declared property, a `setX()` method, explicit dynamic-property support, or an
     * {@see \ArrayAccess}. False for a relation exposed over a column a plain object does not
     * carry (a computed `extractUsing` view), which renders lazily.
     */
    private function carriesColumn(object $entity, string $column): bool
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
