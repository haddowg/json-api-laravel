<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\ThroughAccess;

use haddowg\JsonApi\Collection\CollectionResult;
use haddowg\JsonApi\Request\JsonApiRequestInterface;
use haddowg\JsonApi\Resource\Field\RelationInterface;
use haddowg\JsonApiLaravel\DataProvider\AbstractDataProvider;
use haddowg\JsonApiLaravel\DataProvider\CollectionCriteria;
use haddowg\JsonApiLaravel\DataProvider\InMemoryDataProvider;
use Illuminate\Support\Facades\Auth;
use Workbench\App\Models\User;

/**
 * Serves each caller only their own parcels, an admin all of them. It doesn't fetch many rows
 * in one read, so a walk through it asks {@see fetchOne()} per row.
 *
 * @extends AbstractDataProvider<object>
 */
final class OwnParcelsProvider extends AbstractDataProvider
{
    /**
     * @param array<array-key, ParcelRecord> $parcels
     */
    public function __construct(private readonly array $parcels, private readonly string $type = 'parcels') {}

    public function supports(string $type): bool
    {
        return $type === $this->type;
    }

    public function fetchOne(string $type, string $id): ?object
    {
        $parcel = $this->parcels[$id] ?? null;

        return $parcel !== null && self::sees($parcel) ? $parcel : null;
    }

    public function fetchCollection(string $type, CollectionCriteria $criteria): CollectionResult
    {
        return $this->query($this->parcels)->fetchCollection($type, $criteria);
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
            if ($row instanceof ParcelRecord && self::sees($row)) {
                $seen[$row->id] = $row;
            }
        }

        return new InMemoryDataProvider($this->type, $seen, identify: static fn(object $parcel): string => $parcel instanceof ParcelRecord ? $parcel->id : '');
    }

    private static function sees(ParcelRecord $parcel): bool
    {
        $user = Auth::user();

        return ($user instanceof User && $user->is_admin) || $parcel->owner_id === (string) Auth::id();
    }
}
