<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\RelationshipEntityConstraints;

use haddowg\JsonApiLaravel\DataPersister\InMemoryDataPersister;
use haddowg\JsonApiLaravel\DataProvider\InMemoryDataProvider;
use haddowg\JsonApiLaravel\DataProvider\InMemorySnapshotCoordinator;
use haddowg\JsonApiLaravel\Facades\JsonApi;
use Illuminate\Support\ServiceProvider;

/**
 * Serves the seeded graph from in-memory stores: user 1 has company 1, user 2 has
 * companies 1 and 2 and a stored name too long for its constraint, team 1 has
 * company 1. The stores share one snapshot coordinator so an atomic rollback restores
 * the whole graph.
 */
final class InMemoryServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        JsonApi::discover([__DIR__]);
        JsonApi::constraintTranslator(new EntityCheckTranslator());

        $acme = new Domain('1', 'Acme');
        $globex = new Domain('2', 'Globex');
        $initech = new Domain('3', 'Initech');
        $companies = ['1' => $acme, '2' => $globex, '3' => $initech];
        $users = [
            '1' => new Domain('1', 'Ada', $acme, [$acme]),
            '2' => new Domain('2', 'A stored name too long', $acme, [$acme, $globex]),
        ];
        $teams = ['1' => new Domain('1', companies: [$acme])];

        $identify = static fn(object $item): string => $item instanceof Domain ? $item->id : '';
        $coordinator = $this->app->make(InMemorySnapshotCoordinator::class);
        \assert($coordinator instanceof InMemorySnapshotCoordinator);

        $providers = [];
        foreach (['companies' => $companies, 'integration-users' => $users, 'teams' => $teams] as $type => $items) {
            $providers[$type] = new InMemoryDataProvider($type, $items, identify: $identify, coordinator: $coordinator);
            JsonApi::provider($providers[$type]);
        }

        $companyStore = $providers['companies']->store();
        $resolver = static fn(string $type, string $id): ?object => $type === 'companies' ? $companyStore->find($id) : null;
        foreach ($providers as $type => $provider) {
            JsonApi::persister(new InMemoryDataPersister($type, $provider->store(), static fn(): Domain => new Domain(), $resolver));
        }
    }
}
