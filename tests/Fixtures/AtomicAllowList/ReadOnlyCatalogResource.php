<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\AtomicAllowList;

use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApiLaravel\Attribute\AsJsonApiResource;

/**
 * A **read-only** type backed by a fully transactional in-memory persister — the fixture
 * proving an Atomic Operations batch cannot write a type its HTTP surface forbids. Without
 * the pre-flight operation-exposure gate an `add` here would create a row (the persister is
 * transactional and could commit), so it exercises the real bypass the gate closes.
 */
#[AsJsonApiResource(readOnly: true)]
final class ReadOnlyCatalogResource extends AbstractResource
{
    public static string $type = 'atomic_catalog';

    public function fields(): array
    {
        return [
            Id::make(),
            Str::make('name'),
        ];
    }
}
