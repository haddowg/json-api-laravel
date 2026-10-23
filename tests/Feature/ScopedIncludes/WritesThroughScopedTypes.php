<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Feature\ScopedIncludes;

use haddowg\JsonApi\Resource\Field\Accessor;
use haddowg\JsonApiLaravel\DataProvider\DataProviderRegistry;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;

/**
 * Writes that name `coins` or `marks` resolve each id through the same scope the type's own
 * endpoint reads through. An id the scope hides is a `404`, exactly like an id that names no row,
 * on every write path: a create or update body, the relationship endpoints, and an atomic batch.
 * A to-many replace changes only the members the client can see. Stored state is read straight
 * from the store, past the scope, to show what a rejected write left in place.
 *
 * @internal
 */
trait WritesThroughScopedTypes
{
    private const string ATOMIC_MEDIA_TYPE = 'application/vnd.api+json;ext="https://jsonapi.org/ext/atomic"';

    #[Test]
    public function aCreateNamingAHiddenRowIsRejectedLikeAMissingOne(): void
    {
        foreach ([
            'featuredCoin' => [['type' => 'coins', 'id' => '2'], ['type' => 'coins', 'id' => '99']],
            'spotlight' => [['type' => 'marks', 'id' => '3'], ['type' => 'marks', 'id' => '99']],
            'coins' => [[['type' => 'coins', 'id' => '4']], [['type' => 'coins', 'id' => '99']]],
        ] as $relationship => [$hidden, $missing]) {
            $this->assertRejectedLikeAMissingRow(
                $this->send('POST', '/vaults', $this->vaultDocument([$relationship => ['data' => $hidden]])),
                $this->send('POST', '/vaults', $this->vaultDocument([$relationship => ['data' => $missing]])),
            );
        }

        self::assertSame(['1', '2'], $this->storedVaultIds());
        self::assertSame(['4', '5'], $this->storedCoinsOf('2'));
    }

    #[Test]
    public function anUpdateNamingAHiddenRowIsRejectedLikeAMissingOne(): void
    {
        foreach ([
            'featuredCoin' => [['type' => 'coins', 'id' => '4'], ['type' => 'coins', 'id' => '99']],
            'spotlight' => [['type' => 'marks', 'id' => '2'], ['type' => 'marks', 'id' => '99']],
            'coins' => [[['type' => 'coins', 'id' => '1'], ['type' => 'coins', 'id' => '4']], [['type' => 'coins', 'id' => '1'], ['type' => 'coins', 'id' => '99']]],
        ] as $relationship => [$hidden, $missing]) {
            $this->assertRejectedLikeAMissingRow(
                $this->send('PATCH', '/vaults/1', $this->vaultDocument([$relationship => ['data' => $hidden]], '1')),
                $this->send('PATCH', '/vaults/1', $this->vaultDocument([$relationship => ['data' => $missing]], '1')),
            );
        }

        self::assertSame('1', $this->storedFeaturedCoinOf('1'));
        self::assertSame('coins:2', $this->storedSpotlightOf('1'));
        self::assertSame(['1', '2', '3'], $this->storedCoinsOf('1'));
        self::assertSame(['4', '5'], $this->storedCoinsOf('2'));
    }

    #[Test]
    public function aToOneRelationshipWriteNamingAHiddenRowIsRejectedLikeAMissingOne(): void
    {
        $this->assertRejectedLikeAMissingRow(
            $this->send('PATCH', '/vaults/1/relationships/featuredCoin', ['data' => ['type' => 'coins', 'id' => '2']]),
            $this->send('PATCH', '/vaults/1/relationships/featuredCoin', ['data' => ['type' => 'coins', 'id' => '99']]),
        );
        $this->assertRejectedLikeAMissingRow(
            $this->send('PATCH', '/vaults/2/relationships/spotlight', ['data' => ['type' => 'marks', 'id' => '3']]),
            $this->send('PATCH', '/vaults/2/relationships/spotlight', ['data' => ['type' => 'marks', 'id' => '99']]),
        );

        self::assertSame('1', $this->storedFeaturedCoinOf('1'));
        self::assertSame('marks:1', $this->storedSpotlightOf('2'));
    }

    #[Test]
    public function addingOrRemovingAHiddenMemberIsRejectedLikeAMissingOne(): void
    {
        foreach (['POST', 'PATCH', 'DELETE'] as $method) {
            $this->assertRejectedLikeAMissingRow(
                $this->send($method, '/vaults/1/relationships/coins', ['data' => [['type' => 'coins', 'id' => $method === 'DELETE' ? '2' : '4']]]),
                $this->send($method, '/vaults/1/relationships/coins', ['data' => [['type' => 'coins', 'id' => '99']]]),
            );
        }

        self::assertSame(['1', '2', '3'], $this->storedCoinsOf('1'));
        self::assertSame(['4', '5'], $this->storedCoinsOf('2'));
    }

    #[Test]
    public function attachingAHiddenPivotMemberIsRejectedLikeAMissingOne(): void
    {
        $member = static fn(string $id): array => ['data' => [['type' => 'marks', 'id' => $id, 'meta' => ['pivot' => ['position' => 9]]]]];

        foreach (['POST', 'PATCH'] as $method) {
            $this->assertRejectedLikeAMissingRow(
                $this->send($method, '/coins/3/relationships/marks', $member('2')),
                $this->send($method, '/coins/3/relationships/marks', $member('99')),
            );
        }
        $this->assertRejectedLikeAMissingRow(
            $this->send('DELETE', '/coins/1/relationships/marks', ['data' => [['type' => 'marks', 'id' => '2']]]),
            $this->send('DELETE', '/coins/1/relationships/marks', ['data' => [['type' => 'marks', 'id' => '99']]]),
        );

        self::assertSame(['3'], $this->storedMarksOf('3'));
        self::assertSame(['1', '2'], $this->storedMarksOf('1'));
    }

    #[Test]
    public function aReplaceKeepsTheMembersTheClientCannotSee(): void
    {
        $endpoint = $this->send('PATCH', '/vaults/1/relationships/coins', ['data' => [['type' => 'coins', 'id' => '1']]]);
        $endpoint->assertOk();
        $this->assertNothingHidden($endpoint);
        self::assertSame(['1'], $this->ids($endpoint, 'data'));
        self::assertSame(['1', '2'], $this->storedCoinsOf('1'));

        $embedded = $this->send('PATCH', '/vaults/1?include=coins', $this->vaultDocument(['coins' => ['data' => [['type' => 'coins', 'id' => '3']]]], '1'));
        $embedded->assertOk();
        $this->assertNothingHidden($embedded);
        self::assertSame(['coins:3'], $this->included($embedded, 'coins'));
        self::assertSame(['2', '3'], $this->storedCoinsOf('1'));

        $plain = $this->send('PATCH', '/vaults/1', $this->vaultDocument(['coins' => ['data' => [['type' => 'coins', 'id' => '1'], ['type' => 'coins', 'id' => '3']]]], '1'));
        $plain->assertOk();
        $this->assertNothingHidden($plain);
        self::assertSame(['1', '2', '3'], $this->storedCoinsOf('1'));

        $pivot = $this->send('PATCH', '/coins/1/relationships/marks', ['data' => [['type' => 'marks', 'id' => '4', 'meta' => ['pivot' => ['position' => 7]]]]]);
        $pivot->assertOk();
        $this->assertNothingHidden($pivot);
        self::assertSame(['4'], $this->ids($pivot, 'data'));
        self::assertSame(['2', '4'], $this->storedMarksOf('1'));
        $this->assertStoredPosition('1', '2', 2);
        $this->assertStoredPosition('1', '4', 7);

        $emptied = $this->send('PATCH', '/coins/1/relationships/marks', ['data' => []]);
        $emptied->assertOk();
        self::assertSame([], $this->ids($emptied, 'data'));
        self::assertSame(['2'], $this->storedMarksOf('1'));
    }

    #[Test]
    public function writesNamingVisibleRowsStillApply(): void
    {
        $created = $this->send('POST', '/vaults', $this->vaultDocument([
            'featuredCoin' => ['data' => ['type' => 'coins', 'id' => '5']],
            'spotlight' => ['data' => ['type' => 'marks', 'id' => '4']],
        ]));
        $created->assertCreated();
        self::assertSame(['type' => 'coins', 'id' => '5'], $created->json('data.relationships.featuredCoin.data'));
        self::assertSame(['type' => 'marks', 'id' => '4'], $created->json('data.relationships.spotlight.data'));

        $added = $this->send('POST', '/vaults/1/relationships/coins', ['data' => [['type' => 'coins', 'id' => '5']]]);
        $added->assertOk();
        $this->assertNothingHidden($added);
        self::assertSame(['1', '3', '5'], $this->ids($added, 'data'));
        self::assertSame(['1', '2', '3', '5'], $this->storedCoinsOf('1'));

        $removed = $this->send('DELETE', '/vaults/1/relationships/coins', ['data' => [['type' => 'coins', 'id' => '3']]]);
        $removed->assertOk();
        self::assertSame(['1', '5'], $this->ids($removed, 'data'));
        self::assertSame(['1', '2', '5'], $this->storedCoinsOf('1'));

        $attached = $this->send('POST', '/coins/3/relationships/marks', ['data' => [['type' => 'marks', 'id' => '1', 'meta' => ['pivot' => ['position' => 5]]]]]);
        $attached->assertOk();
        $this->assertNothingHidden($attached);
        self::assertSame(['1'], $this->ids($attached, 'data'));
        self::assertSame(['1', '3'], $this->storedMarksOf('3'));
        $this->assertStoredPosition('3', '1', 5);

        $this->send('PATCH', '/vaults/2/relationships/featuredCoin', ['data' => ['type' => 'coins', 'id' => '5']])->assertOk();
        self::assertSame('5', $this->storedFeaturedCoinOf('2'));
    }

    #[Test]
    public function aCreateThroughAHiddenFlattenedHopIsRejectedLikeAMissingOwner(): void
    {
        $through = fn(string $coin): array => $this->vaultDocument(
            ['featuredCoin' => ['data' => ['type' => 'coins', 'id' => $coin]]],
            attributes: ['label' => 'East', 'featuredLabel' => 'renamed'],
        );

        $this->assertRejectedLikeAMissingRow(
            $this->send('POST', '/vaults', $through('2')),
            $this->send('POST', '/vaults', $through('99')),
        );
        self::assertSame(['1', '2'], $this->storedVaultIds());

        $visible = $this->send('POST', '/vaults', $through('1'));
        $visible->assertCreated();
        self::assertSame(['type' => 'coins', 'id' => '1'], $visible->json('data.relationships.featuredCoin.data'));
    }

    #[Test]
    public function anAtomicBatchNamingAHiddenRowRollsBack(): void
    {
        $response = $this->atomic([
            ['op' => 'add', 'data' => ['type' => 'vaults', 'lid' => 'east', 'attributes' => ['label' => 'East']]],
            ['op' => 'update', 'ref' => ['type' => 'vaults', 'id' => '1', 'relationship' => 'featuredCoin'], 'data' => ['type' => 'coins', 'id' => '3']],
            ['op' => 'add', 'ref' => ['type' => 'vaults', 'lid' => 'east', 'relationship' => 'coins'], 'data' => [['type' => 'coins', 'id' => '4']]],
        ]);

        $response->assertNotFound();
        self::assertSame('RESOURCE_NOT_FOUND', $response->json('errors.0.code'));
        self::assertSame(['1', '2'], $this->storedVaultIds());
        self::assertSame('1', $this->storedFeaturedCoinOf('1'));
        self::assertSame(['4', '5'], $this->storedCoinsOf('2'));
    }

    #[Test]
    public function anAtomicBatchResolvesALocalIdOfAVisibleRow(): void
    {
        $response = $this->atomic([
            ['op' => 'add', 'data' => ['type' => 'coins', 'lid' => 'fresh', 'attributes' => ['label' => 'foxtrot']]],
            ['op' => 'add', 'data' => [
                'type' => 'vaults',
                'lid' => 'east',
                'attributes' => ['label' => 'East'],
                'relationships' => ['featuredCoin' => ['data' => ['type' => 'coins', 'lid' => 'fresh']]],
            ]],
            ['op' => 'add', 'ref' => ['type' => 'vaults', 'lid' => 'east', 'relationship' => 'coins'], 'data' => [['type' => 'coins', 'lid' => 'fresh']]],
        ]);

        $response->assertOk();
        $coin = $response->json('atomic:results.0.data.id');
        $vault = $response->json('atomic:results.1.data.id');
        self::assertIsString($coin);
        self::assertIsString($vault);
        self::assertSame(['type' => 'coins', 'id' => $coin], $response->json('atomic:results.1.data.relationships.featuredCoin.data'));
        self::assertSame($coin, $this->storedFeaturedCoinOf($vault));
        self::assertSame([$coin], $this->storedCoinsOf($vault));
    }

    /**
     * @param TestResponse<\Symfony\Component\HttpFoundation\Response> $hidden
     * @param TestResponse<\Symfony\Component\HttpFoundation\Response> $missing
     */
    private function assertRejectedLikeAMissingRow(TestResponse $hidden, TestResponse $missing): void
    {
        $missing->assertNotFound();
        self::assertSame('RESOURCE_NOT_FOUND', $missing->json('errors.0.code'));
        $hidden->assertNotFound();
        self::assertSame($missing->json(), $hidden->json());
    }

    /**
     * @param array<string, mixed> $relationships
     * @param array<string, mixed> $attributes
     *
     * @return array<string, mixed>
     */
    private function vaultDocument(array $relationships, ?string $id = null, array $attributes = ['label' => 'East']): array
    {
        $data = ['type' => 'vaults'];
        if ($id !== null) {
            $data['id'] = $id;
        }

        return ['data' => [...$data, 'attributes' => $attributes, 'relationships' => $relationships]];
    }

    /**
     * @param array<string, mixed> $document
     *
     * @return TestResponse<\Symfony\Component\HttpFoundation\Response>
     */
    private function send(string $method, string $path, array $document): TestResponse
    {
        return $this->call($method, $this->prefix() . $path, [], [], [], [
            'CONTENT_TYPE' => self::MEDIA_TYPE,
            'HTTP_ACCEPT' => self::MEDIA_TYPE,
        ], \json_encode($document, \JSON_THROW_ON_ERROR));
    }

    /**
     * @param list<array<string, mixed>> $operations
     *
     * @return TestResponse<\Symfony\Component\HttpFoundation\Response>
     */
    private function atomic(array $operations): TestResponse
    {
        return $this->call('POST', $this->prefix() . '/operations', [], [], [], [
            'CONTENT_TYPE' => self::ATOMIC_MEDIA_TYPE,
            'HTTP_ACCEPT' => self::ATOMIC_MEDIA_TYPE,
        ], \json_encode(['atomic:operations' => $operations], \JSON_THROW_ON_ERROR));
    }

    private function prefix(): string
    {
        return $this->server() === 'partner' ? '/partner-api' : '/api';
    }

    /**
     * @return list<string>
     */
    private function storedVaultIds(): array
    {
        if ($this->store() === 'eloquent') {
            return $this->sortedStrings(DB::table('si_vaults')->pluck('id')->all());
        }

        $provider = app(DataProviderRegistry::class)->forType('vaults');

        return \array_values(\array_filter(['1', '2', '3', '4', '5', '6'], static fn(string $id): bool => $provider->fetchOne('vaults', $id) !== null));
    }

    /**
     * @return list<string>
     */
    private function storedCoinsOf(string $vault): array
    {
        if ($this->store() === 'eloquent') {
            return $this->sortedStrings(DB::table('si_coins')->where('vault_id', (int) $vault)->pluck('id')->all());
        }

        return $this->memberIds($this->storedRecord('vaults', $vault), 'coins');
    }

    /**
     * @return list<string>
     */
    private function storedMarksOf(string $coin): array
    {
        if ($this->store() === 'eloquent') {
            return $this->sortedStrings(DB::table('si_coin_mark')->where('coin_id', (int) $coin)->pluck('mark_id')->all());
        }

        return $this->memberIds($this->storedRecord('coins', $coin), 'marks');
    }

    private function storedFeaturedCoinOf(string $vault): ?string
    {
        if ($this->store() === 'eloquent') {
            $id = DB::table('si_vaults')->where('id', (int) $vault)->value('featured_coin_id');

            return \is_scalar($id) ? (string) $id : null;
        }

        $coin = Accessor::get($this->storedRecord('vaults', $vault), 'featuredCoin');

        return \is_object($coin) ? $this->idOf($coin) : null;
    }

    private function storedSpotlightOf(string $vault): ?string
    {
        if ($this->store() === 'eloquent') {
            $row = DB::table('si_vaults')->where('id', (int) $vault)->first(['spotlight_type', 'spotlight_id']);
            self::assertIsObject($row);
            $type = Accessor::get($row, 'spotlight_type');
            $id = Accessor::get($row, 'spotlight_id');
            if (!\is_string($type) || !\is_scalar($id)) {
                return null;
            }

            return (\str_ends_with($type, 'Mark') ? 'marks' : 'coins') . ':' . $id;
        }

        $target = Accessor::get($this->storedRecord('vaults', $vault), 'spotlight');
        if (!\is_object($target)) {
            return null;
        }

        return (\str_ends_with($target::class, 'MarkRecord') ? 'marks' : 'coins') . ':' . $this->idOf($target);
    }

    /**
     * The pivot `position` stored for a coin's mark; the in-memory store keeps no pivot data.
     */
    private function assertStoredPosition(string $coin, string $mark, int $position): void
    {
        if ($this->store() !== 'eloquent') {
            return;
        }

        $stored = DB::table('si_coin_mark')->where('coin_id', (int) $coin)->where('mark_id', (int) $mark)->value('position');
        self::assertIsNumeric($stored);
        self::assertSame($position, (int) $stored);
    }

    private function storedRecord(string $type, string $id): object
    {
        $record = app(DataProviderRegistry::class)->forType($type)->fetchOne($type, $id);
        self::assertIsObject($record, \sprintf('No stored %s %s.', $type, $id));

        return $record;
    }

    /**
     * @return list<string>
     */
    private function memberIds(object $record, string $relation): array
    {
        $members = Accessor::get($record, $relation);
        self::assertIsArray($members);

        $ids = [];
        foreach ($members as $member) {
            self::assertIsObject($member);
            $ids[] = $this->idOf($member);
        }

        return $this->sortedStrings($ids);
    }

    private function idOf(object $record): string
    {
        $id = Accessor::get($record, 'id');
        self::assertIsScalar($id);

        return (string) $id;
    }

    /**
     * @param array<mixed> $values
     *
     * @return list<string>
     */
    private function sortedStrings(array $values): array
    {
        $strings = \array_map(static fn(mixed $value): string => \is_scalar($value) ? (string) $value : '', \array_values($values));
        \sort($strings, \SORT_NATURAL);

        return $strings;
    }
}
