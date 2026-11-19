<?php

declare(strict_types=1);

namespace Workbench\App\ForeignKey;

use haddowg\JsonApi\Collection\CollectionResult;
use haddowg\JsonApiLaravel\DataProvider\AbstractDataProvider;
use haddowg\JsonApiLaravel\DataProvider\CollectionCriteria;

/**
 * Serves `fk-grants` and `fk-revisable-grants` from a list {@see FkGrantPersister} writes to.
 * Each type starts with grant `g1`, whose locked secrets are 1 and 2.
 *
 * @extends AbstractDataProvider<FkGrant>
 */
final class FkGrantProvider extends AbstractDataProvider
{
    /**
     * @var array<string, array<string, FkGrant>>
     */
    public array $grants = [];

    public function __construct()
    {
        foreach (['fk-grants', 'fk-revisable-grants'] as $type) {
            $this->grants[$type] = ['g1' => new FkGrant('g1', lockedSecrets: ['1', '2'])];
        }
    }

    public function supports(string $type): bool
    {
        return isset($this->grants[$type]);
    }

    public function fetchOne(string $type, string $id): ?object
    {
        return $this->grants[$type][$id] ?? null;
    }

    public function fetchCollection(string $type, CollectionCriteria $criteria): CollectionResult
    {
        return new CollectionResult(\array_values($this->grants[$type] ?? []));
    }
}
