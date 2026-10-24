<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Feature;

use haddowg\JsonApi\Atomic\AtomicExtension;
use haddowg\JsonApiLaravel\JsonApiServiceProvider;
use haddowg\JsonApiLaravel\Tests\Fixtures\AtomicServerScope\AtomicServerScopeServiceProvider;
use Illuminate\Testing\TestResponse;
use Orchestra\Testbench\TestCase as Orchestra;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;

/**
 * An Atomic Operations batch only reaches the types of the server it is posted to:
 * `atomic_vault` is on `admin` only, so the `default` server's `/api/operations` cannot
 * write it, while `/admin-api/operations` can.
 *
 * @internal
 */
final class AtomicServerScopeTest extends Orchestra
{
    public const string MEDIA_TYPE = 'application/vnd.api+json';
    public const string ATOMIC_MEDIA_TYPE = 'application/vnd.api+json; ext="' . AtomicExtension::URI . '"';

    /**
     * @param \Illuminate\Foundation\Application $app
     *
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [
            JsonApiServiceProvider::class,
            AtomicServerScopeServiceProvider::class,
        ];
    }

    /**
     * @param \Illuminate\Foundation\Application $app
     */
    protected function defineEnvironment($app): void
    {
        /** @var \Illuminate\Contracts\Config\Repository $config */
        $config = $app['config'];
        $config->set('jsonapi.atomic_operations.enabled', true);
        $config->set('jsonapi.servers', [
            'default' => ['prefix' => 'api', 'middleware' => [], 'domain' => null],
            'admin' => ['prefix' => 'admin-api', 'middleware' => [], 'domain' => null],
        ]);
    }

    /**
     * @return iterable<string, array{list<array<string, mixed>>}>
     */
    public static function otherServerOperations(): iterable
    {
        yield 'add' => [[['op' => 'add', 'data' => ['type' => 'atomic_vault', 'attributes' => ['name' => 'Added']]]]];
        yield 'update' => [[['op' => 'update', 'ref' => ['type' => 'atomic_vault', 'id' => '1'], 'data' => ['type' => 'atomic_vault', 'id' => '1', 'attributes' => ['name' => 'Changed']]]]];
        yield 'remove' => [[['op' => 'remove', 'ref' => ['type' => 'atomic_vault', 'id' => '1']]]];
        yield 'update by href' => [[['op' => 'update', 'href' => '/admin-api/atomic_vault/1', 'data' => ['type' => 'atomic_vault', 'id' => '1', 'attributes' => ['name' => 'Changed']]]]];
        yield 'remove by href' => [[['op' => 'remove', 'href' => '/admin-api/atomic_vault/1']]];
        yield 'after an allowed operation' => [[
            ['op' => 'update', 'ref' => ['type' => 'atomic_note', 'id' => '1'], 'data' => ['type' => 'atomic_note', 'id' => '1', 'attributes' => ['name' => 'Changed']]],
            ['op' => 'remove', 'ref' => ['type' => 'atomic_vault', 'id' => '1']],
        ]];
    }

    /**
     * @param list<array<string, mixed>> $operations
     */
    #[Test]
    #[Group('spec:atomic')]
    #[DataProvider('otherServerOperations')]
    public function aBatchCannotWriteATypeOfAnotherServer(array $operations): void
    {
        $response = $this->atomic('/api/operations', $operations);

        self::assertContains($response->getStatusCode(), [403, 404]);
        $response->assertJsonMissingPath('atomic:results');

        $this->get('/admin-api/atomic_vault', ['Accept' => self::MEDIA_TYPE])
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.attributes.name', 'Seed');
        $this->get('/api/atomic_note/1', ['Accept' => self::MEDIA_TYPE])
            ->assertJsonPath('data.attributes.name', 'Seed');
    }

    #[Test]
    #[Group('spec:atomic')]
    public function aTypeOfAnotherServerIsUnknownToTheBatch(): void
    {
        $this->atomic('/api/operations', [['op' => 'remove', 'ref' => ['type' => 'atomic_vault', 'id' => '1']]])
            ->assertStatus(404)
            ->assertJsonPath('errors.0.code', 'ATOMIC_TARGET_TYPE_UNKNOWN');
    }

    #[Test]
    #[Group('spec:atomic')]
    public function eachServerWritesItsOwnTypes(): void
    {
        $this->atomic('/admin-api/operations', [
            ['op' => 'update', 'ref' => ['type' => 'atomic_vault', 'id' => '1'], 'data' => ['type' => 'atomic_vault', 'id' => '1', 'attributes' => ['name' => 'Changed']]],
        ])->assertOk()->assertJsonPath('atomic:results.0.data.attributes.name', 'Changed');

        $this->atomic('/api/operations', [
            ['op' => 'update', 'ref' => ['type' => 'atomic_note', 'id' => '1'], 'data' => ['type' => 'atomic_note', 'id' => '1', 'attributes' => ['name' => 'Changed']]],
        ])->assertOk()->assertJsonPath('atomic:results.0.data.attributes.name', 'Changed');
    }

    /**
     * @param list<array<string, mixed>> $operations
     *
     * @return TestResponse<\Symfony\Component\HttpFoundation\Response>
     */
    private function atomic(string $uri, array $operations): TestResponse
    {
        return $this->call('POST', $uri, [], [], [], [
            'HTTP_ACCEPT' => self::ATOMIC_MEDIA_TYPE,
            'CONTENT_TYPE' => self::ATOMIC_MEDIA_TYPE,
        ], (string) \json_encode(['atomic:operations' => $operations]));
    }
}
