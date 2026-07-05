<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\AtomicAllowList;

use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApiLaravel\Attribute\AsJsonApiResource;

/**
 * A **writable** type (all operations exposed) backed by a NON-transactional persister —
 * the fixture proving an Atomic Operations batch is refused when a participating type
 * cannot transact. It passes the operation-exposure gate (it exposes Create) and is caught
 * by the transactional pre-flight scan instead.
 */
#[AsJsonApiResource]
final class LedgerResource extends AbstractResource
{
    public static string $type = 'atomic_ledger';

    public function fields(): array
    {
        return [
            Id::make(),
            Str::make('name'),
        ];
    }
}
