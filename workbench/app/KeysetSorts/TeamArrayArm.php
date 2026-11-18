<?php

declare(strict_types=1);

namespace Workbench\App\KeysetSorts;

use haddowg\JsonApi\Resource\Sort\InMemory\ArraySortArmInterface;
use haddowg\JsonApi\Resource\Sort\SortInterface;

/**
 * Orders an in-memory page by a team sort's keyset value.
 */
final class TeamArrayArm implements ArraySortArmInterface
{
    public function supports(SortInterface $sort): bool
    {
        return $sort instanceof TeamSort;
    }

    public function value(SortInterface $sort, mixed $row): mixed
    {
        \assert($sort instanceof TeamSort);

        return $sort->keysetValue($row);
    }
}
