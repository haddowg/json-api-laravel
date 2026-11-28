<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\NestedPivot;

use haddowg\JsonApi\Collection\CollectionResult;
use haddowg\JsonApi\Request\JsonApiRequestInterface;
use haddowg\JsonApi\Resource\Field\RelationInterface;
use haddowg\JsonApiLaravel\DataProvider\CollectionCriteria;
use haddowg\JsonApiLaravel\DataProvider\DataProviderInterface;
use haddowg\JsonApiLaravel\DataProvider\PivotAwareProviderInterface;
use haddowg\JsonApiLaravel\DataProvider\PivotCollectionResult;
use haddowg\JsonApiLaravel\DataProvider\PivotCursorCollectionResult;
use haddowg\JsonApiLaravel\DataProvider\RelatedBatch;

/**
 * A provider that serves its types through another provider and reads pivot rows only
 * through the batched pivot SPI, never querying the join for its related reads, as a custom
 * store might.
 *
 * @internal
 *
 * @implements DataProviderInterface<object>
 * @implements PivotAwareProviderInterface<object>
 */
final class NpBatchOnlyProvider implements DataProviderInterface, PivotAwareProviderInterface
{
    /**
     * @param DataProviderInterface<object> $inner
     */
    public function __construct(private readonly DataProviderInterface $inner) {}

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

    public function fetchRelatedCollection(string $type, object $parent, RelationInterface $relation, CollectionCriteria $criteria, JsonApiRequestInterface $request): CollectionResult
    {
        return $this->inner->fetchRelatedCollection($type, $parent, $relation, $criteria, $request);
    }

    public function fetchRelatedCollectionBatch(string $parentType, array $parents, RelationInterface $relation, CollectionCriteria $criteria, JsonApiRequestInterface $request): RelatedBatch
    {
        return $this->inner->fetchRelatedCollectionBatch($parentType, $parents, $relation, $criteria, $request);
    }

    public function countRelated(string $parentType, array $parents, RelationInterface $relation, CollectionCriteria $criteria, JsonApiRequestInterface $request): array
    {
        return $this->inner->countRelated($parentType, $parents, $relation, $criteria, $request);
    }

    public function relatedToOneMatches(string $type, object $related, RelationInterface $relation, CollectionCriteria $criteria, JsonApiRequestInterface $request): bool
    {
        return $this->inner->relatedToOneMatches($type, $related, $relation, $criteria, $request);
    }

    public function relatedToOneMatchesBatch(string $type, array $related, RelationInterface $relation, CollectionCriteria $criteria, JsonApiRequestInterface $request): array
    {
        return $this->inner->relatedToOneMatchesBatch($type, $related, $relation, $criteria, $request);
    }

    public function fetchRelationshipPivot(string $type, object $parent, RelationInterface $relation): array
    {
        return $this->inner->fetchRelationshipPivot($type, $parent, $relation);
    }

    public function supportsPivot(string $relatedType, RelationInterface $relation): bool
    {
        return false;
    }

    public function fetchRelatedPivotCollection(string $relatedType, object $parent, RelationInterface $relation, CollectionCriteria $criteria, JsonApiRequestInterface $request): PivotCollectionResult|PivotCursorCollectionResult
    {
        throw new \LogicException('This provider does not query pivot rows.');
    }

    public function fetchRelatedPivotMap(string $relatedType, object $parent, RelationInterface $relation): array
    {
        return $this->inner instanceof PivotAwareProviderInterface ? $this->inner->fetchRelatedPivotMap($relatedType, $parent, $relation) : [];
    }

    public function fetchRelatedPivotMapBatch(string $parentType, string $relatedType, array $parents, RelationInterface $relation): array
    {
        return $this->inner instanceof PivotAwareProviderInterface ? $this->inner->fetchRelatedPivotMapBatch($parentType, $relatedType, $parents, $relation) : [];
    }
}
