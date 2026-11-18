<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\CursorSortServability;

use haddowg\JsonApi\Resource\Sort\SortInterface;

/**
 * A custom sort with no keyset value, which a cursor page can't be ordered by.
 */
final readonly class UnkeyableSort implements SortInterface
{
    public function key(): string
    {
        return 'unkeyable';
    }
}
