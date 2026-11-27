<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\NestedPivot;

use haddowg\JsonApi\Collection\CollectionResult;
use haddowg\JsonApi\Request\JsonApiRequestInterface;
use haddowg\JsonApi\Resource\Field\RelationInterface;
use haddowg\JsonApiLaravel\DataProvider\CollectionCriteria;
use haddowg\JsonApiLaravel\DataProvider\DataProviderInterface;
use haddowg\JsonApiLaravel\DataProvider\PivotAwareProviderInterface;
use haddowg\JsonApiLaravel\DataProvider\RelatedBatch;

/**
 * A provider that keeps the crates' pivot rows beside an in-memory store, reads them through
 * the pivot SPI and doesn't query the join for its related reads. Its batched read answers
 * for the crates' relations only. It counts its pivot reads.
 *
 * @internal
 *
 * @implements DataProviderInterface<object>
 */
final class NpPivotStoreProvider implements DataProviderInterface, PivotAwareProviderInterface
{
    public static int $singleReads = 0;

    /**
     * @var list<int>
     */
    public static array $batchSizes = [];

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
        ++self::$singleReads;

        return $this->pivotsOf($parent, $relation);
    }

    public function fetchRelatedPivotMapBatch(string $parentType, string $relatedType, array $parents, RelationInterface $relation): array
    {
        if ($parentType !== 'npCrates') {
            return [];
        }
        self::$batchSizes[] = \count($parents);

        $maps = [];
        foreach ($parents as $parent) {
            $pivots = $this->pivotsOf($parent, $relation);
            if ($pivots !== [] && \is_scalar($parent->id ?? null)) {
                $maps[(string) $parent->id] = $pivots;
            }
        }

        return $maps;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function pivotsOf(object $parent, RelationInterface $relation): array
    {
        if ($relation->name() !== 'records' || !\is_scalar($parent->id ?? null)) {
            return [];
        }

        $pivots = [];
        foreach (\is_iterable($parent->records ?? null) ? $parent->records : [] as $member) {
            $id = \is_object($member) ? ($member->id ?? null) : null;
            if (!\is_scalar($id)) {
                continue;
            }
            foreach (NestedPivotServiceProvider::LINKS as [$crate, $record, $position, $addedAt]) {
                if ((string) $crate === (string) $parent->id && (string) $record === (string) $id) {
                    $pivots[\ltrim((string) $id)] = ['position' => $position, 'addedAt' => new \DateTimeImmutable($addedAt, new \DateTimeZone('UTC'))];
                }
            }
        }

        return $pivots;
    }
}
