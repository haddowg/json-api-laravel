<?php

declare(strict_types=1);

namespace Workbench\App\Support;

use haddowg\JsonApi\Collection\CollectionResult;
use haddowg\JsonApi\Request\JsonApiRequestInterface;
use haddowg\JsonApi\Resource\Field\RelationInterface;
use haddowg\JsonApiLaravel\DataProvider\CollectionCriteria;
use haddowg\JsonApiLaravel\DataProvider\DataProviderInterface;
use haddowg\JsonApiLaravel\DataProvider\InMemoryDataProvider;
use haddowg\JsonApiLaravel\DataProvider\RelatedBatch;
use Workbench\App\Domain\CursorBoard;

/**
 * The in-memory `cursorBoards` provider for the pivot-cursor conformance suite: every
 * read delegates to a wrapped {@see InMemoryDataProvider} (the ground-truth keyset
 * execution over the POPO graph), while {@see fetchRelationshipPivot()} — the one seam
 * the built-in witness deliberately leaves empty (`meta.pivot` needs an association
 * store, ADR 0008) — serves the pivot map straight off the {@see CursorBoard} POPO's
 * `positions`. The suite's `meta.pivot` assertions are then IDENTICAL on both
 * providers: the Eloquent half reads the join table, this half reads the fixture map,
 * and the handler's pivot wrap composes with the cursor page the same way on each.
 *
 * @implements DataProviderInterface<object>
 */
final class CursorBoardInMemoryDataProvider implements DataProviderInterface
{
    public function __construct(private readonly InMemoryDataProvider $inner) {}

    public function supports(string $type): bool
    {
        return $this->inner->supports($type);
    }

    public function fetchOne(string $type, string $id): ?object
    {
        return $this->inner->fetchOne($type, $id);
    }

    public function fetchCollection(string $type, CollectionCriteria $criteria): CollectionResult
    {
        return $this->inner->fetchCollection($type, $criteria);
    }

    public function fetchRelatedCollection(
        string $relatedType,
        object $parent,
        RelationInterface $relation,
        CollectionCriteria $criteria,
        JsonApiRequestInterface $request,
    ): CollectionResult {
        return $this->inner->fetchRelatedCollection($relatedType, $parent, $relation, $criteria, $request);
    }

    public function fetchRelatedCollectionBatch(
        string $parentType,
        array $parents,
        RelationInterface $relation,
        CollectionCriteria $criteria,
        JsonApiRequestInterface $request,
    ): RelatedBatch {
        return $this->inner->fetchRelatedCollectionBatch($parentType, $parents, $relation, $criteria, $request);
    }

    public function countRelated(
        string $type,
        array $parents,
        RelationInterface $relation,
        CollectionCriteria $criteria,
        JsonApiRequestInterface $request,
    ): array {
        return $this->inner->countRelated($type, $parents, $relation, $criteria, $request);
    }

    public function relatedToOneMatches(
        string $relatedType,
        object $related,
        RelationInterface $relation,
        CollectionCriteria $criteria,
        JsonApiRequestInterface $request,
    ): bool {
        return $this->inner->relatedToOneMatches($relatedType, $related, $relation, $criteria, $request);
    }

    public function relatedToOneMatchesBatch(
        string $parentType,
        array $parents,
        RelationInterface $relation,
        CollectionCriteria $criteria,
        JsonApiRequestInterface $request,
    ): array {
        return $this->inner->relatedToOneMatchesBatch($parentType, $parents, $relation, $criteria, $request);
    }

    public function fetchRelationshipPivot(string $type, object $parent, RelationInterface $relation): array
    {
        if (!$parent instanceof CursorBoard) {
            return [];
        }

        $pivots = [];
        foreach ($parent->positions as $widgetId => $position) {
            $pivots[(string) $widgetId] = ['position' => $position];
        }

        return $pivots;
    }
}
