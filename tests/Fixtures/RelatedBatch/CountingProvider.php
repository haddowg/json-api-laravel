<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\RelatedBatch;

use haddowg\JsonApi\Collection\CollectionResult;
use haddowg\JsonApi\Request\JsonApiRequestInterface;
use haddowg\JsonApi\Resource\Field\Accessor;
use haddowg\JsonApi\Resource\Field\RelationInterface;
use haddowg\JsonApiLaravel\DataProvider\AbstractDataProvider;
use haddowg\JsonApiLaravel\DataProvider\CollectionCriteria;
use haddowg\JsonApiLaravel\DataProvider\RelatedBatch;

/**
 * Serves one read-only type whose rows hang off runs, the way a provider answering a page of
 * runs with one grouped query does. It counts every related read it is asked for. With
 * `$batches` off it keeps the base class's empty batch.
 *
 * @extends AbstractDataProvider<object>
 */
final class CountingProvider extends AbstractDataProvider
{
    public int $batchCalls = 0;

    public int $relatedCalls = 0;

    /**
     * The parent type and parent ids of each batch call.
     *
     * @var list<array{string, list<string>}>
     */
    public array $batchParents = [];

    /**
     * @param array<int|string, list<object>> $rowsByRun the rows of each run, by run id
     */
    public function __construct(
        private readonly string $type,
        private readonly array $rowsByRun,
        private readonly bool $batches,
    ) {}

    public function reset(): void
    {
        $this->batchCalls = 0;
        $this->relatedCalls = 0;
        $this->batchParents = [];
    }

    public function supports(string $type): bool
    {
        return $type === $this->type;
    }

    public function fetchOne(string $type, string $id): ?object
    {
        foreach ($this->rowsByRun as $rows) {
            foreach ($rows as $row) {
                if (Accessor::get($row, 'id') === $id) {
                    return $row;
                }
            }
        }

        return null;
    }

    public function fetchCollection(string $type, CollectionCriteria $criteria): CollectionResult
    {
        return new CollectionResult(\array_merge(...\array_values($this->rowsByRun)));
    }

    public function fetchRelatedCollection(
        string $relatedType,
        object $parent,
        RelationInterface $relation,
        CollectionCriteria $criteria,
        JsonApiRequestInterface $request,
    ): CollectionResult {
        ++$this->relatedCalls;

        return new CollectionResult($this->rowsByRun[self::idOf($parent)] ?? []);
    }

    public function fetchRelatedCollectionBatch(
        string $parentType,
        array $parents,
        RelationInterface $relation,
        CollectionCriteria $criteria,
        JsonApiRequestInterface $request,
    ): RelatedBatch {
        ++$this->batchCalls;
        $this->batchParents[] = [$parentType, \array_map(self::idOf(...), $parents)];
        if (!$this->batches) {
            return parent::fetchRelatedCollectionBatch($parentType, $parents, $relation, $criteria, $request);
        }

        $results = [];
        foreach ($parents as $parent) {
            $results[self::idOf($parent)] = new CollectionResult($this->rowsByRun[self::idOf($parent)] ?? []);
        }

        return new RelatedBatch($results);
    }

    /**
     * Matches a `percent` filter against the target, the one filter its type declares.
     */
    public function relatedToOneMatches(
        string $relatedType,
        object $related,
        RelationInterface $relation,
        CollectionCriteria $criteria,
        JsonApiRequestInterface $request,
    ): bool {
        $wanted = $criteria->queryParameters->filter['percent'] ?? null;
        $percent = Accessor::get($related, 'percent');

        return !\is_scalar($wanted) || (\is_scalar($percent) && (string) $percent === (string) $wanted);
    }

    private static function idOf(object $parent): string
    {
        $id = Accessor::get($parent, 'id');

        return \is_scalar($id) ? (string) $id : '';
    }
}
