<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\ThroughRelations;

use haddowg\JsonApi\Collection\CollectionResult;
use haddowg\JsonApiLaravel\DataProvider\AbstractDataProvider;
use haddowg\JsonApiLaravel\DataProvider\CollectionCriteria;
use haddowg\JsonApiLaravel\DataProvider\InMemoryDataProvider;

/**
 * Serves the visible advertisers only, scoping every read that reaches the type, as a custom
 * scoped provider does.
 *
 * @extends AbstractDataProvider<object>
 */
final class ScopedThrAdvertiserProvider extends AbstractDataProvider
{
    /**
     * @param list<ThrAdvertiserRecord> $advertisers
     */
    public function __construct(private readonly array $advertisers) {}

    public function supports(string $type): bool
    {
        return $type === 'thrAdvertisers';
    }

    public function fetchOne(string $type, string $id): ?object
    {
        foreach ($this->visible() as $advertiser) {
            if ($advertiser->id === $id) {
                return $advertiser;
            }
        }

        return null;
    }

    public function fetchCollection(string $type, CollectionCriteria $criteria): CollectionResult
    {
        $rows = [];
        foreach ($this->visible() as $advertiser) {
            $rows[$advertiser->id] = $advertiser;
        }

        return (new InMemoryDataProvider('thrAdvertisers', $rows, identify: static fn(object $row): string => $row instanceof ThrAdvertiserRecord ? $row->id : ''))->fetchCollection($type, $criteria);
    }

    /**
     * @return list<ThrAdvertiserRecord>
     */
    private function visible(): array
    {
        return \array_values(\array_filter($this->advertisers, static fn(ThrAdvertiserRecord $advertiser): bool => !$advertiser->hidden));
    }
}
