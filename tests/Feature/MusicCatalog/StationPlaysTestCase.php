<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Feature\MusicCatalog;

use haddowg\JsonApiLaravel\Testing\InteractsWithJsonApi;
use Orchestra\Testbench\TestCase as Orchestra;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;

/**
 * The `stations` type is served by a custom provider with no database behind it, and its
 * `plays` relation reads the stored `plays` whose `station` holds the station's id
 * (docs/relationships.md). KEXP played plays 1 to 3, NTS play 4, and FIP nothing.
 *
 * @internal
 */
#[CoversNothing]
#[Group('spec:fetching-relationships')]
abstract class StationPlaysTestCase extends Orchestra
{
    use InteractsWithJsonApi;

    /**
     * @param \Illuminate\Foundation\Application $app
     */
    protected function defineEnvironment($app): void
    {
        /** @var \Illuminate\Contracts\Config\Repository $config */
        $config = $app['config'];
        $config->set('jsonapi.servers', [
            'default' => ['prefix' => 'api', 'middleware' => [], 'domain' => null],
            'admin' => ['prefix' => 'admin', 'middleware' => [], 'domain' => null],
        ]);
    }

    #[Test]
    public function aStationsRelatedEndpointServesThePlaysThatNameIt(): void
    {
        self::assertSame(['1', '2', '3'], $this->ids('/api/stations/kexp/plays'));
        self::assertSame([], $this->ids('/api/stations/fip/plays'));
    }

    #[Test]
    public function aStationsRelationshipEndpointServesTheSameLinkage(): void
    {
        self::assertSame(['4'], $this->ids('/api/stations/nts/relationships/plays'));
    }

    #[Test]
    public function anIncludeReadsEveryStationsPlays(): void
    {
        $document = $this->jsonApi()->get('/api/stations?include=plays')->assertOk()->json();
        self::assertIsArray($document);
        $stations = $document['data'] ?? null;
        self::assertIsArray($stations);

        $linkage = [];
        foreach ($stations as $station) {
            self::assertIsArray($station);
            self::assertIsString($station['id'] ?? null);
            $relationships = $station['relationships'] ?? null;
            self::assertIsArray($relationships);
            $relationship = $relationships['plays'] ?? null;
            self::assertIsArray($relationship);
            $plays = $relationship['data'] ?? null;
            self::assertIsArray($plays);
            $linkage[$station['id']] = \array_column($plays, 'id');
        }

        self::assertSame(['kexp' => ['1', '2', '3'], 'nts' => ['4'], 'fip' => []], $linkage);
    }

    /**
     * @return list<string>
     */
    private function ids(string $uri): array
    {
        $data = $this->jsonApi()->get($uri)->assertOk()->json('data');
        self::assertIsArray($data);

        /** @var list<string> */
        return \array_column($data, 'id');
    }
}
