<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\AtomicAllowList;

use haddowg\JsonApi\Resource\Field\Accessor;
use haddowg\JsonApiLaravel\DataPersister\InMemoryDataPersister;
use haddowg\JsonApiLaravel\DataProvider\InMemoryDataProvider;
use haddowg\JsonApiLaravel\Facades\JsonApi;
use Illuminate\Support\ServiceProvider;

/**
 * Wires the Atomic Operations allow-list fixtures: a read-only {@see ReadOnlyCatalogResource}
 * over a TRANSACTIONAL in-memory persister (proving the operation-exposure gate refuses a
 * write its HTTP surface forbids, not merely because it could not commit), and a writable
 * {@see LedgerResource} over a {@see NonTransactionalPersister} (proving the transactional
 * pre-flight refusal).
 */
final class AtomicAllowListServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        JsonApi::register([ReadOnlyCatalogResource::class, LedgerResource::class]);

        // A fully persistable in-memory pair (identifier closures wired) so that WITHOUT the
        // pre-flight gate the read-only `add` would genuinely create + commit a row — the gate
        // is the only thing standing between the batch and a forbidden write.
        $catalog = new InMemoryDataProvider(
            'atomic_catalog',
            ['1' => new Entry('1', 'Seed')],
            identify: static fn(object $item): string => \is_string($id = Accessor::get($item, 'id')) ? $id : '',
            assignId: static function (object $item, string $id): void {
                Accessor::set($item, 'id', $id);
            },
        );
        JsonApi::provider($catalog);
        JsonApi::persister(new InMemoryDataPersister('atomic_catalog', $catalog->store(), static fn(): Entry => new Entry()));

        JsonApi::provider(new InMemoryDataProvider('atomic_ledger', []));
        JsonApi::persister(new NonTransactionalPersister('atomic_ledger'));
    }
}
