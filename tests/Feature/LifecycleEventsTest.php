<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Feature;

use haddowg\JsonApiLaravel\Event\AfterActionEvent;
use haddowg\JsonApiLaravel\Event\AfterCreateEvent;
use haddowg\JsonApiLaravel\Event\AfterDeleteEvent;
use haddowg\JsonApiLaravel\Event\AfterFetchCollectionEvent;
use haddowg\JsonApiLaravel\Event\AfterFetchOneEvent;
use haddowg\JsonApiLaravel\Event\AfterRelationshipMutateEvent;
use haddowg\JsonApiLaravel\Event\AfterSaveEvent;
use haddowg\JsonApiLaravel\Event\AfterUpdateEvent;
use haddowg\JsonApiLaravel\Event\BeforeActionEvent;
use haddowg\JsonApiLaravel\Event\BeforeCreateEvent;
use haddowg\JsonApiLaravel\Event\BeforeDeleteEvent;
use haddowg\JsonApiLaravel\Event\BeforeFetchCollectionEvent;
use haddowg\JsonApiLaravel\Event\BeforeFetchRelatedEvent;
use haddowg\JsonApiLaravel\Event\BeforeFetchRelationshipEvent;
use haddowg\JsonApiLaravel\Event\BeforeRelationshipMutateEvent;
use haddowg\JsonApiLaravel\Event\BeforeSaveEvent;
use haddowg\JsonApiLaravel\Event\BeforeUpdateEvent;
use haddowg\JsonApiLaravel\Event\ServingEvent;
use haddowg\JsonApiLaravel\JsonApiServiceProvider;
use Illuminate\Support\Facades\Event;
use Illuminate\Testing\TestResponse;
use Orchestra\Testbench\TestCase as Orchestra;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Workbench\App\Models\User;
use Workbench\App\Providers\SurfaceInMemoryServiceProvider;

/**
 * The 18 lifecycle events (PLAN decision 10) fire at the bundle's exact points with the
 * right payload, dispatched through Laravel's event Dispatcher. Driven over HTTP on the
 * in-memory surface wiring (albums + artists + the `artist` relation + the `publish`
 * action), with `Event::fake` recording the dispatches — so a listener that would otherwise
 * abort/replace stays inert and the whole CRUD + relationship + action surface reaches every
 * dispatch point.
 *
 * @internal
 */
final class LifecycleEventsTest extends Orchestra
{
    public const string MEDIA_TYPE = 'application/vnd.api+json';

    /**
     * Every lifecycle event class — faked so only these are recorded (framework events pass
     * through untouched).
     *
     * @var list<class-string>
     */
    private const array EVENTS = [
        ServingEvent::class,
        BeforeFetchCollectionEvent::class,
        AfterFetchCollectionEvent::class,
        AfterFetchOneEvent::class,
        BeforeFetchRelatedEvent::class,
        BeforeFetchRelationshipEvent::class,
        BeforeCreateEvent::class,
        AfterCreateEvent::class,
        BeforeUpdateEvent::class,
        AfterUpdateEvent::class,
        BeforeSaveEvent::class,
        AfterSaveEvent::class,
        BeforeDeleteEvent::class,
        AfterDeleteEvent::class,
        BeforeRelationshipMutateEvent::class,
        AfterRelationshipMutateEvent::class,
        BeforeActionEvent::class,
        AfterActionEvent::class,
    ];

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

    #[Test]
    #[Group('events')]
    public function servingEventFiresOncePerDispatch(): void
    {
        Event::fake(self::EVENTS);

        $this->readJsonApi('/api/albums/1')->assertOk();

        Event::assertDispatchedTimes(ServingEvent::class, 1);
        Event::assertDispatched(ServingEvent::class, static fn(ServingEvent $e): bool => $e->serverName === 'default');
    }

    #[Test]
    #[Group('events')]
    public function fetchOneDispatchesAfterFetchOne(): void
    {
        Event::fake(self::EVENTS);

        $this->readJsonApi('/api/albums/1')->assertOk();

        Event::assertDispatched(
            AfterFetchOneEvent::class,
            static fn(AfterFetchOneEvent $e): bool => $e->type === 'albums'
                && $e->serverName === 'default'
                && $e->entity instanceof \Workbench\App\Domain\Album
                && $e->entity->id === '1',
        );
    }

    #[Test]
    #[Group('events')]
    public function fetchCollectionDispatchesBeforeThenAfterWithMaterializedItems(): void
    {
        Event::fake(self::EVENTS);

        $this->readJsonApi('/api/albums')->assertOk();

        Event::assertDispatched(BeforeFetchCollectionEvent::class, static fn(BeforeFetchCollectionEvent $e): bool => $e->type === 'albums');
        Event::assertDispatched(
            AfterFetchCollectionEvent::class,
            static fn(AfterFetchCollectionEvent $e): bool => $e->type === 'albums' && $e->items !== [],
        );
        // A single fetch never fires the collection events.
        Event::assertNotDispatched(AfterFetchOneEvent::class);
    }

    #[Test]
    #[Group('events')]
    public function relatedAndRelationshipReadsDispatchTheirBeforeEvents(): void
    {
        Event::fake(self::EVENTS);

        $this->readJsonApi('/api/albums/1/artist')->assertOk();
        Event::assertDispatched(
            BeforeFetchRelatedEvent::class,
            static fn(BeforeFetchRelatedEvent $e): bool => $e->type === 'albums' && $e->relation->name() === 'artist' && $e->parent instanceof \Workbench\App\Domain\Album,
        );

        $this->readJsonApi('/api/albums/1/relationships/artist')->assertOk();
        Event::assertDispatched(
            BeforeFetchRelationshipEvent::class,
            static fn(BeforeFetchRelationshipEvent $e): bool => $e->type === 'albums' && $e->relation->name() === 'artist',
        );
    }

    #[Test]
    #[Group('events')]
    public function relatedAndRelationshipReadsFireAfterFetchOneOnTheParent(): void
    {
        // Bundle parity: the parent is fetched as a single resource to serve a related /
        // relationship read, so a resource's afterFetchOne hook must fire on those reads too
        // (it does in the bundle) — not only on the primary single fetch.
        Event::fake(self::EVENTS);

        $this->readJsonApi('/api/albums/1/artist')->assertOk();
        $this->readJsonApi('/api/albums/1/relationships/artist')->assertOk();

        // Fired on the parent for BOTH the related and the relationship read (twice), each
        // carrying the loaded parent album.
        Event::assertDispatchedTimes(AfterFetchOneEvent::class, 2);
        Event::assertDispatched(
            AfterFetchOneEvent::class,
            static fn(AfterFetchOneEvent $e): bool => $e->type === 'albums' && $e->entity instanceof \Workbench\App\Domain\Album,
        );
    }

    #[Test]
    #[Group('events')]
    public function createDispatchesEventsInBeforeSaveBeforeCreateAfterCreateAfterSaveOrder(): void
    {
        $log = [];
        foreach ([BeforeSaveEvent::class, BeforeCreateEvent::class, AfterCreateEvent::class, AfterSaveEvent::class] as $event) {
            Event::listen($event, static function (object $e) use (&$log): void {
                $log[] = $e::class;
            });
        }

        $this->writeJsonApi('POST', '/api/albums', [
            'data' => ['type' => 'albums', 'attributes' => ['title' => 'Amnesiac', 'status' => 'draft']],
        ])->assertStatus(201);

        self::assertSame(
            [BeforeSaveEvent::class, BeforeCreateEvent::class, AfterCreateEvent::class, AfterSaveEvent::class],
            $log,
        );
    }

    #[Test]
    #[Group('events')]
    public function updateDispatchesEventsInBeforeSaveBeforeUpdateAfterUpdateAfterSaveOrder(): void
    {
        $log = [];
        foreach ([BeforeSaveEvent::class, BeforeUpdateEvent::class, AfterUpdateEvent::class, AfterSaveEvent::class] as $event) {
            Event::listen($event, static function (object $e) use (&$log): void {
                $log[] = $e::class;
            });
        }

        $this->writeJsonApi('PATCH', '/api/albums/1', [
            'data' => ['type' => 'albums', 'id' => '1', 'attributes' => ['status' => 'archived']],
        ])->assertOk();

        self::assertSame(
            [BeforeSaveEvent::class, BeforeUpdateEvent::class, AfterUpdateEvent::class, AfterSaveEvent::class],
            $log,
        );
    }

    #[Test]
    #[Group('events')]
    public function createDispatchesBeforeSaveBeforeCreateThenAfterCreateAfterSave(): void
    {
        Event::fake(self::EVENTS);

        $this->writeJsonApi('POST', '/api/albums', [
            'data' => ['type' => 'albums', 'attributes' => ['title' => 'Kid A', 'status' => 'draft']],
        ])->assertStatus(201);

        Event::assertDispatched(BeforeSaveEvent::class, static fn(BeforeSaveEvent $e): bool => $e->type === 'albums' && $e->creating === true);
        Event::assertDispatched(BeforeCreateEvent::class, static fn(BeforeCreateEvent $e): bool => $e->type === 'albums');
        Event::assertDispatched(AfterCreateEvent::class, static fn(AfterCreateEvent $e): bool => $e->type === 'albums');
        Event::assertDispatched(AfterSaveEvent::class, static fn(AfterSaveEvent $e): bool => $e->type === 'albums' && $e->creating === true);
        Event::assertNotDispatched(BeforeUpdateEvent::class);
    }

    #[Test]
    #[Group('events')]
    public function updateDispatchesBeforeSaveBeforeUpdateWithOriginalThenAfterUpdateAfterSave(): void
    {
        Event::fake(self::EVENTS);

        $this->writeJsonApi('PATCH', '/api/albums/1', [
            'data' => ['type' => 'albums', 'id' => '1', 'attributes' => ['status' => 'archived']],
        ])->assertOk();

        Event::assertDispatched(BeforeSaveEvent::class, static fn(BeforeSaveEvent $e): bool => $e->creating === false);
        Event::assertDispatched(
            BeforeUpdateEvent::class,
            // `$original` is the pre-change snapshot (status still 'draft'); the mutated
            // `$entity` already carries the incoming 'archived'.
            static fn(BeforeUpdateEvent $e): bool => $e->type === 'albums'
                && $e->original instanceof \Workbench\App\Domain\Album
                && $e->original->status === 'draft'
                && $e->entity instanceof \Workbench\App\Domain\Album
                && $e->entity->status === 'archived',
        );
        Event::assertDispatched(AfterUpdateEvent::class);
        Event::assertDispatched(AfterSaveEvent::class, static fn(AfterSaveEvent $e): bool => $e->creating === false);
        Event::assertNotDispatched(BeforeCreateEvent::class);
    }

    #[Test]
    #[Group('events')]
    public function deleteDispatchesBeforeThenAfterDelete(): void
    {
        Event::fake(self::EVENTS);

        $this->deleteJsonApi('/api/albums/1')->assertNoContent();

        Event::assertDispatched(BeforeDeleteEvent::class, static fn(BeforeDeleteEvent $e): bool => $e->type === 'albums' && $e->entity instanceof \Workbench\App\Domain\Album);
        Event::assertDispatched(AfterDeleteEvent::class, static fn(AfterDeleteEvent $e): bool => $e->type === 'albums');
    }

    #[Test]
    #[Group('events')]
    public function relationshipMutationDispatchesBeforeThenAfterRelationshipMutate(): void
    {
        Event::fake(self::EVENTS);

        $this->writeJsonApi('PATCH', '/api/albums/1/relationships/artist', [
            'data' => ['type' => 'artists', 'id' => '2'],
        ])->assertOk();

        Event::assertDispatched(
            BeforeRelationshipMutateEvent::class,
            static fn(BeforeRelationshipMutateEvent $e): bool => $e->type === 'albums' && $e->relation->name() === 'artist',
        );
        Event::assertDispatched(
            AfterRelationshipMutateEvent::class,
            static fn(AfterRelationshipMutateEvent $e): bool => $e->type === 'albums' && $e->relation->name() === 'artist',
        );
    }

    #[Test]
    #[Group('events')]
    #[Group('actions')]
    public function customActionDispatchesBeforeThenAfterActionCarryingTheAbility(): void
    {
        Event::fake(self::EVENTS);
        $this->actingAs($this->writer());

        $this->writeJsonApi('POST', '/api/albums/1/-actions/publish', [
            'data' => ['type' => 'albums', 'attributes' => ['status' => 'released']],
        ])->assertOk();

        Event::assertDispatched(
            BeforeActionEvent::class,
            static fn(BeforeActionEvent $e): bool => $e->type === 'albums'
                && $e->action === 'publish'
                && $e->ability === 'publish'
                && $e->subject instanceof \Workbench\App\Domain\Album,
        );
        Event::assertDispatched(
            AfterActionEvent::class,
            static fn(AfterActionEvent $e): bool => $e->type === 'albums' && $e->action === 'publish' && $e->subject instanceof \Workbench\App\Domain\Album,
        );
    }

    private function writer(): User
    {
        return new User(['id' => 1, 'name' => 'Writer', 'can_write' => true, 'can_read' => true, 'is_admin' => false]);
    }

    /**
     * @param array<string, mixed> $document
     *
     * @return TestResponse<\Symfony\Component\HttpFoundation\Response>
     */
    private function writeJsonApi(string $method, string $uri, array $document): TestResponse
    {
        return $this->json($method, $uri, $document, [
            'Accept' => self::MEDIA_TYPE,
            'CONTENT_TYPE' => self::MEDIA_TYPE,
        ]);
    }

    /**
     * @return TestResponse<\Symfony\Component\HttpFoundation\Response>
     */
    private function readJsonApi(string $uri): TestResponse
    {
        return $this->get($uri, ['Accept' => self::MEDIA_TYPE]);
    }

    /**
     * @return TestResponse<\Symfony\Component\HttpFoundation\Response>
     */
    private function deleteJsonApi(string $uri): TestResponse
    {
        return $this->json('DELETE', $uri, [], ['Accept' => self::MEDIA_TYPE]);
    }
}
