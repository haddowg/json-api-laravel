<?php

declare(strict_types=1);

namespace Workbench\App\ForeignKey;

use haddowg\JsonApi\Collection\CollectionResult;
use haddowg\JsonApiLaravel\DataProvider\AbstractDataProvider;
use haddowg\JsonApiLaravel\DataProvider\CollectionCriteria;

/**
 * Serves `fk-grants` and `fk-revisable-grants` from a list {@see FkGrantPersister} writes to.
 * Each type starts with grant `g1`, whose locked secrets are 1 and 2, and
 * `fk-revisable-grants` also with `g2`, whose locked secret is 1, which every wiring serves.
 *
 * @extends AbstractDataProvider<GrantInterface>
 */
final class FkGrantProvider extends AbstractDataProvider
{
    /**
     * @var array<string, array<string, GrantInterface>>
     */
    public array $grants = [];

    /**
     * @param \Closure(string, list<string>): GrantInterface $make builds a grant from its id and locked secret ids
     */
    public function __construct(public readonly \Closure $make)
    {
        foreach (['fk-grants', 'fk-revisable-grants'] as $type) {
            $this->grants[$type] = ['g1' => $make('g1', ['1', '2'])];
        }
        $this->grants['fk-revisable-grants']['g2'] = $make('g2', ['1']);
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
