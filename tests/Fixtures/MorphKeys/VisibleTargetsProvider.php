<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\MorphKeys;

use haddowg\JsonApi\Collection\CollectionResult;
use haddowg\JsonApiLaravel\DataProvider\AbstractDataProvider;
use haddowg\JsonApiLaravel\DataProvider\CollectionCriteria;
use haddowg\JsonApiLaravel\DataProvider\InMemoryDataProvider;

/**
 * Serves a type's visible rows only, keyed by wire id, so the targets a WhereMorph matches
 * go through it as the Eloquent global scope keeps them.
 *
 * @extends AbstractDataProvider<object>
 */
final class VisibleTargetsProvider extends AbstractDataProvider
{
    /**
     * @param array<string, MkTargetRecord> $visible
     */
    public function __construct(private readonly string $type, private readonly array $visible) {}

    public function supports(string $type): bool
    {
        return $type === $this->type;
    }

    public function fetchOne(string $type, string $id): ?object
    {
        return $this->visible[$id] ?? null;
    }

    public function fetchCollection(string $type, CollectionCriteria $criteria): CollectionResult
    {
        return (new InMemoryDataProvider($this->type, $this->visible, identify: fn(object $row): string => (string) \array_search($row, $this->visible, true)))->fetchCollection($type, $criteria);
    }
}
