<?php

declare(strict_types=1);

namespace Workbench\App\SortFamily;

use haddowg\JsonApi\Resource\Sort\InMemory\ArraySortArmInterface;
use haddowg\JsonApi\Resource\Sort\SortInterface;

/**
 * The in-memory twin of {@see PlaceholderValueEloquentArm} for page (offset) ordering. A
 * cursor page reads {@see PlaceholderValueSort::keysetValue()} instead.
 */
final class PlaceholderValueArrayArm implements ArraySortArmInterface
{
    public function supports(SortInterface $sort): bool
    {
        return $sort instanceof PlaceholderValueSort;
    }

    public function value(SortInterface $sort, mixed $row): mixed
    {
        \assert($sort instanceof PlaceholderValueSort);

        return $sort->keysetValue($row);
    }
}
