<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\OwnedRows;

use haddowg\JsonApi\Collection\CollectionResult;
use haddowg\JsonApi\Request\JsonApiRequestInterface;
use haddowg\JsonApi\Resource\Field\RelationInterface;
use haddowg\JsonApiLaravel\DataProvider\AbstractDataProvider;
use haddowg\JsonApiLaravel\DataProvider\CollectionCriteria;
use haddowg\JsonApiLaravel\DataProvider\InMemoryDataProvider;
use Illuminate\Support\Facades\Auth;

/**
 * Serves `tasks` from in-memory records, limited to the ones the signed-in user owns. The
 * rows are narrowed before the query runs, so filters, sorting, paging and counts all work on
 * the user's rows only.
 *
 * @extends AbstractDataProvider<object>
 */
final class OwnTasksOnly extends AbstractDataProvider
{
    /**
     * @param array<array-key, TaskRecord> $tasks
     */
    public function __construct(private readonly array $tasks) {}

    public function supports(string $type): bool
    {
        return $type === 'tasks';
    }

    public function fetchOne(string $type, string $id): ?object
    {
        $task = $this->tasks[$id] ?? null;

        return $task !== null && $this->owns($task) ? $task : null;
    }

    public function fetchCollection(string $type, CollectionCriteria $criteria): CollectionResult
    {
        return $this->query($this->tasks)->fetchCollection($type, $criteria);
    }

    public function fetchRelatedCollection(
        string $relatedType,
        object $parent,
        RelationInterface $relation,
        CollectionCriteria $criteria,
        JsonApiRequestInterface $request,
    ): CollectionResult {
        $members = $relation->readValue($parent, $request);

        return $this->query(\is_iterable($members) ? $members : [])->fetchCollection($relatedType, $criteria);
    }

    /**
     * A provider over just the user's rows, which runs the request's criteria.
     *
     * @param iterable<mixed> $rows
     */
    private function query(iterable $rows): InMemoryDataProvider
    {
        $owned = [];
        foreach ($rows as $row) {
            if ($row instanceof TaskRecord && $this->owns($row)) {
                $owned[$row->id] = $row;
            }
        }

        return new InMemoryDataProvider('tasks', $owned, identify: static fn(object $task): string => $task instanceof TaskRecord ? $task->id : '');
    }

    private function owns(TaskRecord $task): bool
    {
        return $task->owner_id === (string) Auth::id();
    }
}
