<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Feature\EncodedPivot;

use haddowg\JsonApiLaravel\JsonApiServiceProvider;
use haddowg\JsonApiLaravel\Tests\Fixtures\EncodedPivot\EncodedPivotServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;
use PHPUnit\Framework\Attributes\CoversNothing;

/**
 * An in-memory parent and member both behind id encoders: the seeded pivot rows key the
 * members by the ids the records keep, a write keys them by their wire ids, and every path
 * finds each member's row under its wire id.
 *
 * @internal
 */
#[CoversNothing]
final class InMemoryEncodedPivotTest extends Orchestra
{
    private const string MEDIA_TYPE = 'application/vnd.api+json';

    private const array SHIRTS = ['player-1' => ['shirt' => 7], 'player-2' => ['shirt' => 9]];

    /**
     * @param \Illuminate\Foundation\Application $app
     *
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [JsonApiServiceProvider::class, EncodedPivotServiceProvider::class];
    }

    public function test_every_path_finds_each_members_row_under_its_wire_id(): void
    {
        $document = $this->document('GET', '/api/enc-teams/team-1?include=players');
        self::assertSame(self::SHIRTS, $this->pivots(\data_get($document, 'data.relationships.players.data')));
        self::assertSame(self::SHIRTS, $this->pivots($document['included'] ?? null));
        self::assertSame(self::SHIRTS, $this->pivots(\data_get($this->document('GET', '/api/enc-teams?include=players'), 'data.0.relationships.players.data')));
        self::assertSame(self::SHIRTS, $this->pivots($this->document('GET', '/api/enc-teams/team-1/players')['data'] ?? null));
        self::assertSame(self::SHIRTS, $this->pivots($this->document('GET', '/api/enc-teams/team-1/relationships/players')['data'] ?? null));
    }

    public function test_a_write_stores_each_members_pivot_under_its_wire_id(): void
    {
        $written = $this->document('PATCH', '/api/enc-teams/team-1/relationships/players', ['data' => [
            ['type' => 'enc-players', 'id' => 'player-2', 'meta' => ['pivot' => ['shirt' => 10]]],
        ]]);

        self::assertSame(['player-2' => ['shirt' => 10]], $this->pivots($written['data'] ?? null));
        self::assertSame(['player-2' => ['shirt' => 10]], $this->pivots(\data_get($this->document('GET', '/api/enc-teams/team-1?include=players'), 'data.relationships.players.data')));
    }

    /**
     * @param array<string, mixed>|null $body
     *
     * @return array<mixed>
     */
    private function document(string $method, string $uri, ?array $body = null): array
    {
        $response = $this->call($method, $uri, [], [], [], ['HTTP_ACCEPT' => self::MEDIA_TYPE, 'CONTENT_TYPE' => self::MEDIA_TYPE], $body === null ? null : (string) \json_encode($body));
        self::assertSame(200, $response->getStatusCode(), $uri . ': ' . $response->getContent());
        $document = $response->json();
        self::assertIsArray($document);

        return $document;
    }

    /**
     * Each member's `meta.pivot`, keyed by id in id order.
     *
     * @return array<string, mixed>
     */
    private function pivots(mixed $members): array
    {
        self::assertIsArray($members);
        $pivots = [];
        foreach ($members as $member) {
            $id = \data_get($member, 'id');
            self::assertIsString($id);
            $pivots[$id] = \data_get($member, 'meta.pivot');
        }
        \ksort($pivots);

        return $pivots;
    }
}
