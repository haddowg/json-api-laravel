<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\DataProvider;

/**
 * An optional {@see DataProviderInterface} capability: says which of several ids of a type it
 * serves for this request, in one read that loads no rows. The handler narrows the stored pairs of
 * a `MorphToMany` declared with `identifiedBy()` through it before it cuts a page or takes a
 * count. A provider without it is asked through {@see FetchesManyInterface::fetchMany()} when it
 * has that, and otherwise through {@see DataProviderInterface::fetchOne()} once per id.
 */
interface AdmitsIdsInterface
{
    /**
     * The ids among `$ids` whose rows this provider serves, in any order. An id it doesn't serve,
     * hidden or absent, is left out.
     *
     * @param list<string> $ids wire ids
     *
     * @return list<string>
     */
    public function admitIds(string $type, array $ids): array;
}
