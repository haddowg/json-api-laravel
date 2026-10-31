<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\DataProvider;

/**
 * An optional {@see DataProviderInterface} capability: looks up several rows of a type by their
 * wire ids in one read, returning those the provider serves for this request. The handler uses
 * it to narrow a polymorphic relation's members to the rows each member type's scoping provider
 * admits in one lookup per type (ADR 0032); a provider without it is asked through
 * {@see DataProviderInterface::fetchOne()} once per id.
 */
interface FetchesManyInterface
{
    /**
     * The rows of `$type` among `$ids` that this provider serves, in any order. An id it doesn't
     * serve, hidden or absent, is simply left out.
     *
     * @param list<string> $ids wire ids
     *
     * @return list<object>
     */
    public function fetchMany(string $type, array $ids): array;
}
