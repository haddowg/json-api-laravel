<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Feature\PivotMembership;

use haddowg\JsonApi\Atomic\AtomicExtension;
use haddowg\JsonApiLaravel\JsonApiServiceProvider;
use haddowg\JsonApiLaravel\Tests\Fixtures\PivotMembership\PivotMembershipServiceProvider;
use Illuminate\Testing\TestResponse;
use Orchestra\Testbench\TestCase as Orchestra;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\HttpFoundation\Response;

/**
 * `cannotChangeMembership()` on a pivot relation that names its link-row class with
 * `through()` and declares no `extractUsing()`. Each link row has an id of its own, so the
 * check must compare the records the linkage renders, never the rows that link them: a replace
 * that only reorders is accepted, one that adds, drops or swaps a record for one whose id
 * matches a row id is a `403`, and the record a scope hides is kept. See
 * {@see PivotMembershipServiceProvider} for the seed.
 *
 * @internal
 */
abstract class PivotMembershipTestCase extends Orchestra
{
    public const string MEDIA_TYPE = 'application/vnd.api+json';

    /**
     * `eloquent` or `memory`.
     */
    abstract protected function store(): string;

    /**
     * The ids of the records crate `$crate` stores, hidden ones included, sorted.
     *
     * @return list<string>
     */
    abstract protected function storedRecords(int $crate): array;

    /**
     * @param \Illuminate\Foundation\Application $app
     *
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app): array
    {
        /** @var \Illuminate\Contracts\Config\Repository $config */
        $config = $app['config'];
        $config->set('pm.store', $this->store());
        $config->set('jsonapi.atomic_operations.enabled', true);

        return [JsonApiServiceProvider::class, PivotMembershipServiceProvider::class];
    }

    #[Test]
    public function theLinkageListsTheVisibleRecords(): void
    {
        $response = $this->get('/api/crates/1/relationships/records', ['Accept' => self::MEDIA_TYPE]);

        $response->assertOk();
        self::assertSame(['2', '3', '4'], $this->ids($response->json('data')));
    }

    #[Test]
    public function aReplaceThatReordersTheRecordsIsAccepted(): void
    {
        $response = $this->write('PATCH', '/api/crates/1/relationships/records', ['data' => $this->records('4', '2', '3')]);

        $response->assertOk();
        self::assertSame(['2', '3', '4'], $this->ids($response->json('data')));
        self::assertSame(['2', '3', '4', '6'], $this->storedRecords(1));
    }

    #[Test]
    public function aReplaceThatAddsARecordIsForbidden(): void
    {
        $response = $this->write('PATCH', '/api/crates/2/relationships/records', ['data' => $this->records('5', '1')]);

        $this->assertMembershipChangeProhibited($response);
        self::assertSame(['5'], $this->storedRecords(2));
    }

    #[Test]
    public function aReplaceThatDropsARecordIsForbidden(): void
    {
        $response = $this->write('PATCH', '/api/crates/1/relationships/records', ['data' => $this->records('2', '3')]);

        $this->assertMembershipChangeProhibited($response);
        self::assertSame(['2', '3', '4', '6'], $this->storedRecords(1));
    }

    #[Test]
    public function aReplaceNamingTheRecordWhoseIdIsALinkRowsIsForbidden(): void
    {
        // Crate 2 links record 5 by row 4, so record 4 is a change.
        $response = $this->write('PATCH', '/api/crates/2/relationships/records', ['data' => $this->records('4')]);

        $this->assertMembershipChangeProhibited($response);
        self::assertSame(['5'], $this->storedRecords(2));
    }

    #[Test]
    public function postingIsAnAdditionProhibited(): void
    {
        $response = $this->write('POST', '/api/crates/2/relationships/records', ['data' => $this->records('1')]);

        $response->assertStatus(403);
        self::assertSame('ADDITION_PROHIBITED', $response->json('errors.0.code'));
        self::assertSame(['5'], $this->storedRecords(2));
    }

    #[Test]
    public function deletingIsARemovalProhibited(): void
    {
        $response = $this->write('DELETE', '/api/crates/1/relationships/records', ['data' => $this->records('2')]);

        $response->assertStatus(403);
        self::assertSame('REMOVAL_PROHIBITED', $response->json('errors.0.code'));
        self::assertSame(['2', '3', '4', '6'], $this->storedRecords(1));
    }

    #[Test]
    public function anEmbeddedReplaceThatReordersTheRecordsIsAccepted(): void
    {
        $response = $this->patchCrate('1', $this->records('3', '4', '2'));

        $response->assertOk();
        self::assertSame('Renamed', $response->json('data.attributes.name'));
        self::assertSame(['2', '3', '4', '6'], $this->storedRecords(1));
    }

    #[Test]
    public function anEmbeddedReplaceThatChangesTheRecordsIsForbidden(): void
    {
        $response = $this->patchCrate('2', $this->records('4'));

        $this->assertMembershipChangeProhibited($response);
        self::assertSame(['5'], $this->storedRecords(2));
        self::assertSame('Single', $this->get('/api/crates/2', ['Accept' => self::MEDIA_TYPE])->json('data.attributes.name'));
    }

    #[Test]
    public function anAtomicBatchAcceptsAReorderAndRefusesAChange(): void
    {
        $reorder = $this->atomic([
            ['op' => 'update', 'ref' => ['type' => 'crates', 'id' => '1', 'relationship' => 'records'], 'data' => $this->records('3', '2', '4')],
            ['op' => 'update', 'ref' => ['type' => 'crates', 'id' => '1'], 'data' => ['type' => 'crates', 'id' => '1', 'relationships' => ['records' => ['data' => $this->records('4', '3', '2')]]]],
        ]);
        self::assertContains($reorder->getStatusCode(), [Response::HTTP_OK, Response::HTTP_NO_CONTENT], (string) $reorder->getContent());

        $change = $this->atomic([
            ['op' => 'update', 'ref' => ['type' => 'crates', 'id' => '2'], 'data' => ['type' => 'crates', 'id' => '2', 'attributes' => ['name' => 'Rolled back']]],
            ['op' => 'update', 'ref' => ['type' => 'crates', 'id' => '2', 'relationship' => 'records'], 'data' => $this->records('4')],
        ]);
        $change->assertStatus(403);
        self::assertSame('MEMBERSHIP_CHANGE_PROHIBITED', $change->json('errors.0.code'));
        self::assertSame('/atomic:operations/1/data/relationships/records', $change->json('errors.0.source.pointer'));

        self::assertSame(['2', '3', '4', '6'], $this->storedRecords(1));
        self::assertSame(['5'], $this->storedRecords(2));
        self::assertSame('Single', $this->get('/api/crates/2', ['Accept' => self::MEDIA_TYPE])->json('data.attributes.name'));
    }

    /**
     * @param TestResponse<Response> $response
     */
    protected function assertMembershipChangeProhibited(TestResponse $response): void
    {
        $response->assertStatus(403);
        self::assertSame('MEMBERSHIP_CHANGE_PROHIBITED', $response->json('errors.0.code'));
        self::assertSame('/data/relationships/records', $response->json('errors.0.source.pointer'));
    }

    /**
     * @return list<array{type: string, id: string}>
     */
    protected function records(string ...$ids): array
    {
        return \array_map(static fn(string $id): array => ['type' => 'records', 'id' => $id], \array_values($ids));
    }

    /**
     * @param list<array{type: string, id: string}> $records
     *
     * @return TestResponse<Response>
     */
    protected function patchCrate(string $id, array $records): TestResponse
    {
        return $this->write('PATCH', '/api/crates/' . $id, ['data' => [
            'type' => 'crates',
            'id' => $id,
            'attributes' => ['name' => 'Renamed'],
            'relationships' => ['records' => ['data' => $records]],
        ]]);
    }

    /**
     * @param array<string, mixed> $document
     *
     * @return TestResponse<Response>
     */
    protected function write(string $method, string $uri, array $document): TestResponse
    {
        return $this->json($method, $uri, $document, ['Accept' => self::MEDIA_TYPE, 'CONTENT_TYPE' => self::MEDIA_TYPE]);
    }

    /**
     * @param list<array<string, mixed>> $operations
     *
     * @return TestResponse<Response>
     */
    protected function atomic(array $operations): TestResponse
    {
        $type = 'application/vnd.api+json; ext="' . AtomicExtension::URI . '"';

        return $this->call('POST', '/api/operations', [], [], [], [
            'HTTP_ACCEPT' => $type,
            'CONTENT_TYPE' => $type,
        ], \json_encode(['atomic:operations' => $operations], \JSON_THROW_ON_ERROR));
    }

    /**
     * @return list<string>
     */
    protected function ids(mixed $data): array
    {
        $ids = [];
        foreach (\is_array($data) ? $data : [] as $identifier) {
            if (\is_array($identifier) && \is_string($identifier['id'] ?? null)) {
                $ids[] = $identifier['id'];
            }
        }
        \sort($ids);

        return $ids;
    }
}
