<?php

declare(strict_types=1);

namespace Workbench\App\Positioned;

use haddowg\JsonApi\Collection\CollectionResult;
use haddowg\JsonApi\Request\JsonApiRequestInterface;
use haddowg\JsonApi\Resource\Field\RelationInterface;
use haddowg\JsonApiLaravel\DataProvider\AbstractDataProvider;
use haddowg\JsonApiLaravel\DataProvider\CollectionCriteria;
use haddowg\JsonApiLaravel\DataProvider\InMemoryDataProvider;
use haddowg\JsonApiLaravel\DataProvider\RelatedBatch;

/**
 * Serves `songs` from the in-memory witness, leaving out the hidden ones, so a scope hides
 * members of the queues' relations.
 *
 * @extends AbstractDataProvider<object>
 */
final class VisibleSongsOnly extends AbstractDataProvider
{
    /**
     * @param array<array-key, Song> $songs
     * @param list<string>           $hidden
     */
    public function __construct(private readonly array $songs, private readonly array $hidden) {}

    public function supports(string $type): bool
    {
        return $type === 'songs';
    }

    public function fetchOne(string $type, string $id): ?object
    {
        return \in_array($id, $this->hidden, true) ? null : $this->songs[$id] ?? null;
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
        return new InMemoryDataProvider('songs', \array_filter(
            $this->songs,
            fn(Song $song): bool => !\in_array($song->id, $this->hidden, true),
        ));
    }
}
