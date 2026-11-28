<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\EncodedPivot;

use haddowg\JsonApiLaravel\DataPersister\InMemoryDataPersister;
use haddowg\JsonApiLaravel\DataProvider\InMemoryDataProvider;
use haddowg\JsonApiLaravel\Facades\JsonApi;
use Illuminate\Support\ServiceProvider;

/**
 * Serves the in-memory `enc-teams` and `enc-players`, both behind id encoders. Team 1 holds
 * players 1 and 2, wearing shirts 7 and 9. Each record keeps its stored id and each store
 * keys it by its wire id, while the seeded pivot rows key the players by the ids the records
 * keep.
 *
 * @internal
 */
final class EncodedPivotServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        JsonApi::register([EncTeamResource::class, EncPlayerResource::class]);

        $players = ['player-1' => (object) ['id' => '1', 'name' => 'Ann'], 'player-2' => (object) ['id' => '2', 'name' => 'Bob']];
        $team = (object) ['id' => '1', 'name' => 'Reds', 'players' => \array_values($players)];

        $playerProvider = new InMemoryDataProvider('enc-players', $players, identify: static fn(object $item): string => \is_scalar($item->id ?? null) ? 'player-' . $item->id : '');
        $teamProvider = new InMemoryDataProvider('enc-teams', ['team-1' => $team], identify: static fn(object $item): string => \is_scalar($item->id ?? null) ? 'team-' . $item->id : '');
        $teamProvider->store()->writePivotRows($team, 'players', ['1' => ['shirt' => 7], '2' => ['shirt' => 9]]);

        JsonApi::provider($playerProvider);
        JsonApi::provider($teamProvider);
        JsonApi::persister(new InMemoryDataPersister(
            'enc-teams',
            $teamProvider->store(),
            static fn(): object => (object) ['id' => null, 'name' => null, 'players' => []],
            static fn(string $type, string $id): ?object => $type === 'enc-players' ? $playerProvider->store()->find($id) : null,
        ));
    }
}
