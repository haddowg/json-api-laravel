<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\IdentifiedMorph;

use haddowg\JsonApi\Collection\CollectionResult;
use haddowg\JsonApi\Request\JsonApiRequestInterface;
use haddowg\JsonApi\Resource\Field\RelationInterface;
use haddowg\JsonApiLaravel\DataProvider\AbstractDataProvider;
use haddowg\JsonApiLaravel\DataProvider\CollectionCriteria;
use haddowg\JsonApiLaravel\DataProvider\InMemoryDataProvider;
use haddowg\JsonApiLaravel\DataProvider\RelatedBatch;

/**
 * Serves `advertisers` from in-memory records, leaving out the hidden ones. Their owners load
 * in one batch, the way the reference provider loads them.
 *
 * @extends AbstractDataProvider<object>
 */
final class VisibleAdvertisersOnly extends AbstractDataProvider
{
    /**
     * @param array<array-key, AdvertiserRecord> $advertisers
     */
    public function __construct(private readonly array $advertisers) {}

    public function supports(string $type): bool
    {
        return $type === 'advertisers';
    }

    public function fetchOne(string $type, string $id): ?object
    {
        $advertiser = $this->advertisers[$id] ?? null;

        return $advertiser !== null && !$advertiser->hidden ? $advertiser : null;
    }

    public function fetchCollection(string $type, CollectionCriteria $criteria): CollectionResult
    {
        return $this->visible($this->advertisers)->fetchCollection($type, $criteria);
    }

    public function fetchRelatedCollection(
        string $relatedType,
        object $parent,
        RelationInterface $relation,
        CollectionCriteria $criteria,
        JsonApiRequestInterface $request,
    ): CollectionResult {
        $members = $relation->readValue($parent, $request);

        return $this->visible(\is_iterable($members) ? $members : [])->fetchCollection($relatedType, $criteria);
    }

    public function fetchRelatedCollectionBatch(
        string $parentType,
        array $parents,
        RelationInterface $relation,
        CollectionCriteria $criteria,
        JsonApiRequestInterface $request,
    ): RelatedBatch {
        return $this->visible($this->advertisers)->fetchRelatedCollectionBatch($parentType, $parents, $relation, $criteria, $request);
    }

    /**
     * @param iterable<mixed> $rows
     */
    private function visible(iterable $rows): InMemoryDataProvider
    {
        $visible = [];
        foreach ($rows as $row) {
            if ($row instanceof AdvertiserRecord && !$row->hidden) {
                $visible[$row->id] = $row;
            }
        }

        return new InMemoryDataProvider('advertisers', $visible, identify: static fn(object $row): string => $row instanceof AdvertiserRecord ? $row->id : '');
    }
}
