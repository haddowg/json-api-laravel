<?php

declare(strict_types=1);

namespace Workbench\App\Providers;

use haddowg\JsonApiLaravel\DataPersister\InMemoryDataPersister;
use haddowg\JsonApiLaravel\DataProvider\InMemoryDataProvider;
use haddowg\JsonApiLaravel\DataProvider\InMemorySnapshotCoordinator;
use haddowg\JsonApiLaravel\Facades\JsonApi;
use Illuminate\Support\ServiceProvider;
use Workbench\App\Domain\Player;
use Workbench\App\Domain\Squad;
use Workbench\App\RelationBounds\Fixtures;
use Workbench\App\RelationBounds\PlayerResource;
use Workbench\App\RelationBounds\SquadResource;

/**
 * The in-memory half of the relation-bounds conformance wiring, seeded from
 * {@see Fixtures}. Both stores share one snapshot coordinator so an atomic rollback
 * restores the whole graph.
 */
final class RelationBoundsInMemoryServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        JsonApi::register([SquadResource::class, PlayerResource::class]);

        $players = [];
        foreach (Fixtures::players() as $id => $name) {
            $players[(string) $id] = new Player((string) $id, $name);
        }

        $squads = [];
        foreach (Fixtures::squads() as $id => $row) {
            $members = [];
            foreach ($row['players'] as $playerId) {
                $members[] = $players[(string) $playerId];
            }
            $squads[(string) $id] = new Squad((string) $id, $row['name'], $members);
        }

        /** @var InMemorySnapshotCoordinator $coordinator */
        $coordinator = $this->app->make(InMemorySnapshotCoordinator::class);

        $playerProvider = new InMemoryDataProvider('players', $players, coordinator: $coordinator);
        JsonApi::provider($playerProvider);

        $squadProvider = new InMemoryDataProvider('squads', $squads, identify: static fn(object $squad): string => $squad instanceof Squad ? $squad->id : '', coordinator: $coordinator);
        JsonApi::provider($squadProvider);

        $relatedResolver = static fn(string $type, string $id): ?object => $type === 'players' ? $playerProvider->store()->find($id) : null;
        JsonApi::persister(new InMemoryDataPersister('squads', $squadProvider->store(), static fn(): Squad => new Squad(), $relatedResolver));
    }
}
