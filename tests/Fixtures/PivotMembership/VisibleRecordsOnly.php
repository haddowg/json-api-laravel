<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\PivotMembership;

use haddowg\JsonApi\Collection\CollectionResult;
use haddowg\JsonApi\Request\JsonApiRequestInterface;
use haddowg\JsonApi\Resource\Field\RelationInterface;
use haddowg\JsonApiLaravel\DataProvider\AbstractDataProvider;
use haddowg\JsonApiLaravel\DataProvider\CollectionCriteria;
use haddowg\JsonApiLaravel\DataProvider\InMemoryDataProvider;
use haddowg\JsonApiLaravel\DataProvider\PivotAwareProviderInterface;
use haddowg\JsonApiLaravel\DataProvider\PivotCollectionResult;
use haddowg\JsonApiLaravel\DataProvider\PivotCursorCollectionResult;

/**
 * Serves `records` from in-memory rows, never a hidden one: by id, in a collection, or as a
 * related member.
 *
 * @extends AbstractDataProvider<object>
 * @implements PivotAwareProviderInterface<object>
 */
final class VisibleRecordsOnly extends AbstractDataProvider implements PivotAwareProviderInterface
{
    public function __construct(private readonly InMemoryDataProvider $inner) {}

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
        return $this->inner->fetchRelatedPivotMap($relatedType, $parent, $relation);
    }

    public function fetchRelatedPivotMapBatch(string $parentType, string $relatedType, array $parents, RelationInterface $relation): array
    {
        return $this->inner->fetchRelatedPivotMapBatch($parentType, $relatedType, $parents, $relation);
    }

    public function supports(string $type): bool
    {
        return $type === 'records';
    }

    public function fetchOne(string $type, string $id): ?object
    {
        $record = $this->inner->fetchOne($type, $id);

        return $record instanceof RecordRow && !$record->hidden ? $record : null;
    }

    public function fetchCollection(string $type, CollectionCriteria $criteria): CollectionResult
    {
        return self::visible($this->inner->fetchCollection($type, $criteria));
    }

    public function fetchRelatedCollection(
        string $relatedType,
        object $parent,
        RelationInterface $relation,
        CollectionCriteria $criteria,
        JsonApiRequestInterface $request,
    ): CollectionResult {
        return self::visible($this->inner->fetchRelatedCollection($relatedType, $parent, $relation, $criteria, $request));
    }

    /**
     * @param CollectionResult<object> $result
     *
     * @return CollectionResult<object>
     */
    private static function visible(CollectionResult $result): CollectionResult
    {
        $items = \is_array($result->items) ? $result->items : \iterator_to_array($result->items, false);
        $visible = \array_values(\array_filter($items, static fn(object $item): bool => !$item instanceof RecordRow || !$item->hidden));

        return new CollectionResult($visible, $result->total === null ? null : \count($visible));
    }
}
