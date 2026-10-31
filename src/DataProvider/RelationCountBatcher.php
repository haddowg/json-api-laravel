<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\DataProvider;

use haddowg\JsonApi\Operation\QueryParameters;
use haddowg\JsonApi\Request\JsonApiRequestInterface;
use haddowg\JsonApi\Resource\Field\RelationInterface;
use haddowg\JsonApi\Serializer\SerializerInterface;
use haddowg\JsonApi\Server\Server;
use haddowg\JsonApiLaravel\Serializer\BatchedRelationshipCount;
use haddowg\JsonApiLaravel\Server\TypeMetadataResolver;

/**
 * Batches the cardinality of every `?withCount`-named countable to-many relation across an
 * already-fetched page of parents, so a collection render of `meta.total` does not N+1
 * (bundle ADR 0052, re-idiomized). It runs once, before render, over the page the handler
 * already materialized, and asks the provider for ONE grouped count per relation
 * ({@see DataProviderInterface::countRelated()}) rather than one count per parent.
 *
 * The requested countable relations are the intersection of the request's flat `?withCount`
 * list ({@see JsonApiRequestInterface::getCountedRelationships()}) and the type's declared
 * to-many relations marked {@see RelationInterface::isCountable()} — core has already
 * validated `?withCount` up front (a non-countable or to-one name `400`s before the handler
 * runs), so this only ever counts a sound set.
 *
 * Each count is over the relation's **filtered** set: the batcher builds a filters-only
 * {@see CollectionCriteria} per relation through the shared {@see RelationCriteriaFactory}
 * (the same merge the related endpoint uses) from the request's `relatedQuery[<rel>][filter]`,
 * so a `?withCount`-named relation that also carries a relatedQuery filter counts the SAME
 * set the related-collection endpoint would page. In the common no-relatedQuery case the
 * criteria is empty and the count is raw membership.
 *
 * The provider returns each relation's counts keyed by the parent's wire id; this batcher
 * resolves each parent's wire id through the primary serializer
 * ({@see \haddowg\JsonApi\Serializer\SerializerInterface::getId()}) and re-keys the result
 * by the parent's object identity, producing a {@see BatchedRelationshipCount} the handler
 * injects into core's count seam for the render pass.
 */
final class RelationCountBatcher
{
    public function __construct(
        private readonly DataProviderRegistry $providers,
        private readonly TypeMetadataResolver $types,
        private readonly RelationCriteriaFactory $relationCriteria,
        private readonly ?ScopedPolymorphicMembers $polymorphicMembers = null,
    ) {}

    /**
     * Builds the per-page count seam for the `?withCount`-named countable relations of
     * `$type`, or `null` when the request named none (so the handler clears the seam and
     * renders exactly as before).
     *
     * @param list<object> $parents the already-fetched page of parents
     */
    public function batch(Server $server, string $type, array $parents, JsonApiRequestInterface $request): ?BatchedRelationshipCount
    {
        if ($parents === []) {
            return null;
        }

        $requested = $request->getCountedRelationships();
        if ($requested === []) {
            return null;
        }

        $relations = $this->countableRelations($server, $type, $requested, $request);
        if ($relations === []) {
            return null;
        }

        $provider = $this->providers->forType($type);
        $serializer = $server->serializerFor($type);

        // Resolve each parent's wire id once (the provider keys its counts by it), and
        // index the parents by wire id so a provider count maps back to the object whose
        // identity the render seam keys on.
        $byWireId = [];
        foreach ($parents as $parent) {
            $byWireId[$serializer->getId($parent)][] = $parent;
        }

        $counts = [];
        foreach ($relations as $relation) {
            $criteria = $this->countCriteria($server, $relation, $request);

            $relationCounts = $this->providers->relatesToScopedType($type, $relation)
                ? $this->countScoped($server, $type, $parents, $relation, $criteria, $request, $serializer)
                : $provider->countRelated($type, $parents, $relation, $criteria, $request);
            foreach ($relationCounts as $wireId => $count) {
                foreach ($byWireId[(string) $wireId] ?? [] as $parent) {
                    $counts[\spl_object_id($parent)][$relation->name()] = $count;
                }
            }
        }

        return $counts === [] ? null : new BatchedRelationshipCount($counts);
    }

    /**
     * Counts a relation whose related type is served by its own scoping provider by fetching
     * each parent's members through that provider (the related endpoint's read), so a total
     * never counts a row the related endpoint hides. A polymorphic relation counts its members
     * narrowed per member type, as its related endpoint lists them (ADR 0032).
     *
     * @param list<object> $parents
     *
     * @return array<string, int>
     */
    private function countScoped(
        Server $server,
        string $type,
        array $parents,
        RelationInterface $relation,
        CollectionCriteria $criteria,
        JsonApiRequestInterface $request,
        SerializerInterface $serializer,
    ): array {
        if (\count($relation->relatedTypes()) !== 1) {
            $candidates = [];
            foreach ($parents as $parent) {
                $value = $relation->readValue($parent, $request);
                $candidates[$serializer->getId($parent)] = \is_iterable($value) ? $value : [];
            }

            $polymorphicMembers = $this->polymorphicMembers ?? new ScopedPolymorphicMembers($this->providers);

            return \array_map(\count(...), $polymorphicMembers->narrow($server, $type, $relation, $candidates));
        }

        $batch = $this->providers->fetchRelatedBatch(
            $type,
            $parents,
            $relation,
            $criteria,
            $request,
            static fn(object $parent): string => $serializer->getId($parent),
        );

        $counts = [];
        foreach ($parents as $parent) {
            $wireId = $serializer->getId($parent);
            $result = $batch->for($wireId);
            $counts[$wireId] = \is_array($result->items) ? \count($result->items) : \iterator_count($result->items);
        }

        return $counts;
    }

    /**
     * The filters-only {@see CollectionCriteria} for one `?withCount`-named relation: the
     * relation's `relatedQuery[<rel>][filter]` resolved against the merged related-collection
     * filter vocabulary, with NO window (a count needs no page) and NO sort/default order
     * (order is irrelevant to a count — and a parent-rooted grouped count cannot ORDER BY a
     * related column). In the common no-relatedQuery case the request's filter is empty, so
     * the criteria is inert and the count is raw membership.
     */
    private function countCriteria(Server $server, RelationInterface $relation, JsonApiRequestInterface $request): CollectionCriteria
    {
        $relatedType = $relation->relatedTypes()[0] ?? null;
        $relatedResource = $relatedType !== null && Polymorphism::isMonomorphic($relation)
            ? $this->types->resourceFor($server, $relatedType)
            : null;

        $relatedQuery = $request->getRelatedQuery($relation->name());

        $criteria = $this->relationCriteria->criteriaFor(
            new QueryParameters(
                fields: [],
                includes: [],
                sort: [],
                filter: $relatedQuery->filter,
                pagination: $request->getPagination(),
            ),
            $relatedResource,
            $relation,
            null,
        );

        // Drop the related resource's default order — a count needs none, and on a
        // parent-rooted grouped count it would resolve a related column against the parent.
        return new CollectionCriteria(
            $criteria->queryParameters,
            $criteria->filters,
            $criteria->sorts,
            $criteria->window,
            defaultSort: [],
            aliasOf: $criteria->aliasOf,
        );
    }

    /**
     * The declared to-many countable relations of `$type` whose name appears in
     * `$requested` (the `?withCount` list). Core has already rejected a name that is not
     * countable / not to-many, so this filter is belt-and-braces.
     *
     * @param list<string> $requested
     *
     * @return list<RelationInterface>
     */
    private function countableRelations(Server $server, string $type, array $requested, JsonApiRequestInterface $request): array
    {
        $wanted = \array_flip($requested);

        $relations = [];
        foreach ($this->types->readableRelationsFor($server, $type, $request) as $relation) {
            if ($relation->isToMany()
                && $relation->isCountable()
                && isset($wanted[$relation->name()])) {
                $relations[] = $relation;
            }
        }

        return $relations;
    }
}
