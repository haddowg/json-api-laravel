<?php

declare(strict_types=1);

namespace Workbench\App\MusicCatalog\Provider;

use haddowg\JsonApi\Collection\CollectionResult;
use haddowg\JsonApi\Request\JsonApiRequestInterface;
use haddowg\JsonApi\Resource\Field\Accessor;
use haddowg\JsonApi\Resource\Field\RelationInterface;
use haddowg\JsonApiLaravel\DataProvider\AbstractDataProvider;
use haddowg\JsonApiLaravel\DataProvider\CollectionCriteria;
use haddowg\JsonApiLaravel\DataProvider\InMemoryDataProvider;
use haddowg\JsonApiLaravel\DataProvider\RelatedBatch;
use Illuminate\Support\Facades\Auth;
use Workbench\App\MusicCatalog\Domain\User;

/**
 * Serves the `accounts` singleton from the in-memory users, showing every read only the
 * signed-in user's row and a guest none, as the {@see \Workbench\App\MusicCatalog\Models\Account}
 * model's global scope does on Eloquent.
 *
 * @extends AbstractDataProvider<object>
 */
final class OwnAccountProvider extends AbstractDataProvider
{
    /**
     * @param array<array-key, User> $users
     */
    public function __construct(private readonly array $users) {}

    public function supports(string $type): bool
    {
        return $type === 'accounts';
    }

    public function fetchOne(string $type, string $id): ?object
    {
        return $id === self::callerId() ? $this->users[$id] ?? null : null;
    }

    public function fetchCollection(string $type, CollectionCriteria $criteria): CollectionResult
    {
        return $this->own()->fetchCollection($type, $criteria);
    }

    public function fetchRelatedCollection(
        string $relatedType,
        object $parent,
        RelationInterface $relation,
        CollectionCriteria $criteria,
        JsonApiRequestInterface $request,
    ): CollectionResult {
        return $this->own()->fetchRelatedCollection($relatedType, $parent, $relation, $criteria, $request);
    }

    public function fetchRelatedCollectionBatch(
        string $parentType,
        array $parents,
        RelationInterface $relation,
        CollectionCriteria $criteria,
        JsonApiRequestInterface $request,
    ): RelatedBatch {
        return $this->own()->fetchRelatedCollectionBatch($parentType, $parents, $relation, $criteria, $request);
    }

    private function own(): InMemoryDataProvider
    {
        $id = self::callerId();

        return new InMemoryDataProvider(
            'accounts',
            $id !== null && isset($this->users[$id]) ? [$id => $this->users[$id]] : [],
            identify: static function (object $item): string {
                $id = Accessor::get($item, 'id');

                return \is_scalar($id) ? (string) $id : '';
            },
        );
    }

    private static function callerId(): ?string
    {
        $id = Auth::id();

        return \is_int($id) || \is_string($id) ? (string) $id : null;
    }
}
