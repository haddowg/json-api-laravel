<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\ThroughAccess;

use haddowg\JsonApi\Collection\CollectionResult;
use haddowg\JsonApi\Request\JsonApiRequestInterface;
use haddowg\JsonApi\Resource\Field\RelationInterface;
use haddowg\JsonApiLaravel\DataProvider\AbstractDataProvider;
use haddowg\JsonApiLaravel\DataProvider\CollectionCriteria;
use haddowg\JsonApiLaravel\DataProvider\FetchesManyInterface;
use haddowg\JsonApiLaravel\DataProvider\InMemoryDataProvider;
use Illuminate\Support\Facades\Auth;
use Workbench\App\Models\User;

/**
 * Serves secret tags to admins only, and fetches many rows in one read.
 *
 * @extends AbstractDataProvider<object>
 */
final class PublicTagsProvider extends AbstractDataProvider implements FetchesManyInterface
{
    /**
     * @param array<array-key, TagRecord> $tags
     */
    public function __construct(private readonly array $tags) {}

    public function supports(string $type): bool
    {
        return $type === 'tags';
    }

    public function fetchOne(string $type, string $id): ?object
    {
        $tag = $this->tags[$id] ?? null;

        return $tag !== null && self::sees($tag) ? $tag : null;
    }

    public function fetchMany(string $type, array $ids): array
    {
        $rows = [];
        foreach ($ids as $id) {
            $tag = $this->fetchOne($type, $id);
            if ($tag !== null) {
                $rows[] = $tag;
            }
        }

        return $rows;
    }

    public function fetchCollection(string $type, CollectionCriteria $criteria): CollectionResult
    {
        return $this->query($this->tags)->fetchCollection($type, $criteria);
    }

    public function fetchRelatedCollection(string $relatedType, object $parent, RelationInterface $relation, CollectionCriteria $criteria, JsonApiRequestInterface $request): CollectionResult
    {
        $members = $relation->readValue($parent, $request);

        return $this->query(\is_iterable($members) ? $members : [])->fetchCollection($relatedType, $criteria);
    }

    /**
     * @param iterable<mixed> $rows
     */
    private function query(iterable $rows): InMemoryDataProvider
    {
        $seen = [];
        foreach ($rows as $row) {
            if ($row instanceof TagRecord && self::sees($row)) {
                $seen[$row->id] = $row;
            }
        }

        return new InMemoryDataProvider('tags', $seen, identify: static fn(object $tag): string => $tag instanceof TagRecord ? $tag->id : '');
    }

    private static function sees(TagRecord $tag): bool
    {
        $user = Auth::user();

        return !$tag->secret || ($user instanceof User && $user->is_admin);
    }
}
