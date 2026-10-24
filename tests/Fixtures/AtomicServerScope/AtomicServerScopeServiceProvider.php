<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\AtomicServerScope;

use haddowg\JsonApi\Resource\Field\Accessor;
use haddowg\JsonApiLaravel\DataPersister\InMemoryDataPersister;
use haddowg\JsonApiLaravel\DataProvider\InMemoryDataProvider;
use haddowg\JsonApiLaravel\Facades\JsonApi;
use haddowg\JsonApiLaravel\Tests\Fixtures\AtomicAllowList\Entry;
use Illuminate\Support\ServiceProvider;

/**
 * Two writable in-memory types on different servers: `atomic_note` on `default` and
 * `atomic_vault` on `admin`, each with a transactional persister and one seed row.
 */
final class AtomicServerScopeServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        JsonApi::register([NoteResource::class, VaultResource::class]);

        foreach (['atomic_note', 'atomic_vault'] as $type) {
            $provider = new InMemoryDataProvider(
                $type,
                ['1' => new Entry('1', 'Seed')],
                identify: static fn(object $item): string => \is_string($id = Accessor::get($item, 'id')) ? $id : '',
                assignId: static function (object $item, string $id): void {
                    Accessor::set($item, 'id', $id);
                },
            );
            JsonApi::provider($provider);
            JsonApi::persister(new InMemoryDataPersister($type, $provider->store(), static fn(): Entry => new Entry()));
        }
    }
}
