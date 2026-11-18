<?php

declare(strict_types=1);

namespace Workbench\App\ForeignKey;

use haddowg\JsonApi\Collection\CollectionResult;
use haddowg\JsonApiLaravel\DataProvider\AbstractDataProvider;
use haddowg\JsonApiLaravel\DataProvider\CollectionCriteria;
use haddowg\JsonApiLaravel\DataProvider\DataProviderRegistry;

/**
 * Serves `fk-users` and the `fk-inboxes` singleton from a fixed list, keeping
 * {@see AbstractDataProvider}'s defaults for every related read. Ada's pinned items, 5, 4 and 3
 * in that order, and her favourite, item 2, are loaded onto her model from the items' own
 * provider.
 *
 * @extends AbstractDataProvider<FkUser>
 */
final class FkUserProvider extends AbstractDataProvider
{
    /**
     * @param \Closure(): DataProviderRegistry $providers
     */
    public function __construct(private readonly \Closure $providers) {}

    public function supports(string $type): bool
    {
        return $type === 'fk-users' || $type === 'fk-inboxes';
    }

    public function fetchOne(string $type, string $id): ?object
    {
        foreach (self::users() as $user) {
            if ($user->id === $id) {
                return $this->withItems($user);
            }
        }

        return null;
    }

    public function fetchCollection(string $type, CollectionCriteria $criteria): CollectionResult
    {
        return new CollectionResult(\array_map($this->withItems(...), self::users()));
    }

    private function withItems(FkUser $user): FkUser
    {
        if ($user->id !== 'ada') {
            return $user;
        }

        $user->pinned = \array_values(\array_filter(\array_map($this->item(...), [5, 4, 3])));
        $user->favourite = $this->item(2);

        return $user;
    }

    private function item(int $id): ?object
    {
        return ($this->providers)()->forType('fk-items')->fetchOne('fk-items', (string) $id);
    }

    /**
     * @return list<FkUser>
     */
    private static function users(): array
    {
        return [new FkUser('ada', 'Ada'), new FkUser('bob', 'Bob'), new FkUser('cy', 'Cy')];
    }
}
