<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Feature;

use haddowg\JsonApi\Atomic\AtomicExtension;
use haddowg\JsonApiLaravel\Event\AfterCreateEvent;
use haddowg\JsonApiLaravel\Event\AfterSaveEvent;
use haddowg\JsonApiLaravel\Event\BeforeCreateEvent;
use haddowg\JsonApiLaravel\JsonApiServiceProvider;
use Illuminate\Support\Facades\Event;
use Illuminate\Testing\TestResponse;
use Orchestra\Testbench\TestCase as Orchestra;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Workbench\App\Providers\SurfaceInMemoryServiceProvider;

/**
 * Atomic Operations post-commit deferral (PLAN decisions 10/12): under an active batch the
 * handler enqueues each After* dispatch on the {@see \haddowg\JsonApiLaravel\DataPersister\WriteTransactionContext}
 * instead of firing it inline; the backend drains the queue only after the batch's
 * transaction commits, and discards it on rollback. So After* events fire once per
 * committed sub-op and never for a rolled-back batch — while the Before* events still fire
 * inline mid-batch. Driven over `POST /api/operations` on the transactional in-memory
 * surface wiring.
 *
 * @internal
 */
final class AtomicEventDeferralTest extends Orchestra
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
            SurfaceInMemoryServiceProvider::class,
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
    #[Group('events')]
    public function afterEventsFireOncePerSubOpAfterACommittedBatch(): void
    {
        $counts = $this->countEvents();

        $this->atomic([
            ['op' => 'add', 'data' => ['type' => 'artists', 'lid' => 'a', 'attributes' => ['name' => 'Boards of Canada', 'slug' => 'boc']]],
            ['op' => 'add', 'data' => [
                'type' => 'albums',
                'attributes' => ['title' => 'Music Has the Right', 'status' => 'released', 'releasedAt' => '1998-04-20T00:00:00+00:00'],
                'relationships' => ['artist' => ['data' => ['type' => 'artists', 'lid' => 'a']]],
            ]],
        ])->assertOk();

        // Before* fired inline per sub-op; After* drained once per committed sub-op.
        $this->assertSame(2, $counts()['before'], 'BeforeCreate fires inline for each sub-op');
        $this->assertSame(2, $counts()['afterCreate'], 'AfterCreate is deferred and drained once per committed sub-op');
        $this->assertSame(2, $counts()['afterSave'], 'AfterSave is deferred and drained once per committed sub-op');
    }

    #[Test]
    #[Group('spec:atomic')]
    #[Group('events')]
    public function afterEventsNeverFireForARolledBackBatch(): void
    {
        $counts = $this->countEvents();

        // op0 adds an artist (its After* enqueued); op1 removes a non-existent album → the
        // whole batch rolls back, discarding the deferred queue undrained.
        $this->atomic([
            ['op' => 'add', 'data' => ['type' => 'artists', 'attributes' => ['name' => 'Ghost', 'slug' => 'ghost']]],
            ['op' => 'remove', 'ref' => ['type' => 'albums', 'id' => '999']],
        ])->assertStatus(404);

        // The op0 create ran inline (Before* fired) but the batch rolled back, so no After*
        // hook ever fires — a rolled-back batch never drains its deferred queue.
        $this->assertSame(1, $counts()['before'], 'BeforeCreate fired inline for the executed sub-op');
        $this->assertSame(0, $counts()['afterCreate'], 'a rolled-back batch fires no deferred AfterCreate');
        $this->assertSame(0, $counts()['afterSave'], 'a rolled-back batch fires no deferred AfterSave');
    }

    #[Test]
    #[Group('events')]
    public function aSingleOpWriteFiresItsAfterEventsInline(): void
    {
        $counts = $this->countEvents();

        // The control: outside a batch the WriteTransactionContext is inactive, so After*
        // fires inline exactly as a plain create does.
        $this->json('POST', '/api/artists', [
            'data' => ['type' => 'artists', 'attributes' => ['name' => 'Solo', 'slug' => 'solo']],
        ], ['Accept' => self::MEDIA_TYPE, 'CONTENT_TYPE' => self::MEDIA_TYPE])->assertStatus(201);

        $this->assertSame(1, $counts()['before']);
        $this->assertSame(1, $counts()['afterCreate']);
        $this->assertSame(1, $counts()['afterSave']);
    }

    /**
     * Registers counting listeners for the create lifecycle events and returns an accessor
     * yielding their current tallies.
     *
     * @return callable(): array{before: int, afterCreate: int, afterSave: int}
     */
    private function countEvents(): callable
    {
        $counts = ['before' => 0, 'afterCreate' => 0, 'afterSave' => 0];

        Event::listen(BeforeCreateEvent::class, static function () use (&$counts): void {
            $counts['before']++;
        });
        Event::listen(AfterCreateEvent::class, static function () use (&$counts): void {
            $counts['afterCreate']++;
        });
        Event::listen(AfterSaveEvent::class, static function () use (&$counts): void {
            $counts['afterSave']++;
        });

        return static function () use (&$counts): array {
            /** @var array{before: int, afterCreate: int, afterSave: int} $counts */
            return $counts;
        };
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
