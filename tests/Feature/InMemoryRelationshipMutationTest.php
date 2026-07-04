<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Feature;

use haddowg\JsonApiLaravel\JsonApiServiceProvider;
use haddowg\JsonApiLaravel\Tests\Fixtures\Mutations\InMemoryMutationsServiceProvider;
use PHPUnit\Framework\Attributes\CoversNothing;

/**
 * {@see RelationshipMutationTestCase} against the **in-memory witness** — the
 * {@see InMemoryMutationsServiceProvider} seeds the linked object graph and shares each
 * provider's store with an {@see \haddowg\JsonApiLaravel\DataPersister\InMemoryDataPersister},
 * so a mutation is immediately readable and no database is touched.
 *
 * @internal
 */
#[CoversNothing]
final class InMemoryRelationshipMutationTest extends RelationshipMutationTestCase
{
    /**
     * @param \Illuminate\Foundation\Application $app
     *
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [
            JsonApiServiceProvider::class,
            InMemoryMutationsServiceProvider::class,
        ];
    }
}
