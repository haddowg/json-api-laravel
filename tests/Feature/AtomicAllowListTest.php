<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Feature;

use haddowg\JsonApi\Atomic\AtomicExtension;
use haddowg\JsonApiLaravel\JsonApiServiceProvider;
use haddowg\JsonApiLaravel\Tests\Fixtures\AtomicAllowList\AtomicAllowListServiceProvider;
use Illuminate\Testing\TestResponse;
use Orchestra\Testbench\TestCase as Orchestra;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;

/**
 * Atomic sub-operations never touch the router, so the executor re-applies the per-type
 * operation allow-list (a read-only type cannot be written via `POST /operations`) and the
 * transactional pre-flight scan (a non-transactional type refuses the batch) — proven here
 * on the in-memory witness with a deliberately mixed wiring.
 *
 * @internal
 */
final class AtomicAllowListTest extends Orchestra
{
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
            AtomicAllowListServiceProvider::class,
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
    }

    #[Test]
    #[Group('spec:atomic')]
    public function aReadOnlyTypeCannotBeWrittenViaTheBatch(): void
    {
        // The catalog type is read-only, but its persister is transactional and could commit —
        // without the pre-flight operation-exposure gate this `add` would create a row. The
        // gate refuses the whole batch with a 403 before opening any transaction.
        $this->atomic([
            ['op' => 'add', 'data' => ['type' => 'atomic_catalog', 'attributes' => ['name' => 'sneaky']]],
        ])
            ->assertStatus(403)
            ->assertJsonPath('errors.0.code', 'ATOMIC_OPERATION_NOT_EXPOSED');

        // The batch never ran: the seed row is the only catalog entry.
        $this->getJson('/api/atomic_catalog', ['Accept' => 'application/vnd.api+json'])
            ->assertJsonCount(1, 'data');
    }

    #[Test]
    #[Group('spec:atomic')]
    public function aNonTransactionalTypeRefusesTheBatch(): void
    {
        // The ledger type exposes writes (it passes the operation-exposure gate) but its
        // persister is not transactional, so the batch is refused in pre-flight.
        $this->atomic([
            ['op' => 'add', 'data' => ['type' => 'atomic_ledger', 'attributes' => ['name' => 'entry']]],
        ])
            ->assertStatus(403)
            ->assertJsonPath('errors.0.code', 'ATOMIC_OPERATIONS_NOT_SUPPORTED');
    }

    /**
     * @param list<array<string, mixed>> $operations
     *
     * @return TestResponse<\Symfony\Component\HttpFoundation\Response>
     */
    private function atomic(array $operations): TestResponse
    {
        $body = (string) \json_encode(['atomic:operations' => $operations]);

        return $this->call('POST', '/api/operations', [], [], [], [
            'HTTP_ACCEPT' => self::ATOMIC_MEDIA_TYPE,
            'CONTENT_TYPE' => self::ATOMIC_MEDIA_TYPE,
        ], $body);
    }
}
