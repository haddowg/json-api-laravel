<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\IdentifiedMorphMany;

use haddowg\JsonApi\Collection\CollectionResult;
use haddowg\JsonApi\Request\JsonApiRequestInterface;
use haddowg\JsonApi\Resource\Field\RelationInterface;
use haddowg\JsonApiLaravel\DataProvider\AbstractDataProvider;
use haddowg\JsonApiLaravel\DataProvider\CollectionCriteria;
use haddowg\JsonApiLaravel\DataProvider\InMemoryDataProvider;
use haddowg\JsonApiLaravel\DataProvider\RelatedBatch;

/**
 * Serves `agencies` from in-memory records, leaving out the hidden ones.
 *
 * @extends AbstractDataProvider<object>
 */
final class VisibleAgenciesOnly extends AbstractDataProvider
{
    /**
     * @param array<array-key, CompanyRecord> $agencies
     */
    public function __construct(private readonly array $agencies) {}

    public function supports(string $type): bool
    {
        return $type === 'agencies';
    }

    public function fetchOne(string $type, string $id): ?object
    {
        $agency = $this->agencies[$id] ?? null;

        return $agency !== null && !$agency->hidden ? $agency : null;
    }

    public function fetchCollection(string $type, CollectionCriteria $criteria): CollectionResult
    {
        return $this->visible()->fetchCollection($type, $criteria);
    }

    public function fetchRelatedCollection(
        string $relatedType,
        object $parent,
        RelationInterface $relation,
        CollectionCriteria $criteria,
        JsonApiRequestInterface $request,
    ): CollectionResult {
        return $this->visible()->fetchRelatedCollection($relatedType, $parent, $relation, $criteria, $request);
    }

    public function fetchRelatedCollectionBatch(
        string $parentType,
        array $parents,
        RelationInterface $relation,
        CollectionCriteria $criteria,
        JsonApiRequestInterface $request,
    ): RelatedBatch {
        return $this->visible()->fetchRelatedCollectionBatch($parentType, $parents, $relation, $criteria, $request);
    }

    private function visible(): InMemoryDataProvider
    {
        return new InMemoryDataProvider(
            'agencies',
            \array_filter($this->agencies, static fn(CompanyRecord $agency): bool => !$agency->hidden),
            identify: static fn(object $row): string => $row instanceof CompanyRecord ? $row->id : '',
        );
    }
}
