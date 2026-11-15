<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Conformance;

use haddowg\JsonApi\Atomic\AtomicExtension;
use haddowg\JsonApiLaravel\Event\AfterActionEvent;
use haddowg\JsonApiLaravel\Event\AfterCreateEvent;
use haddowg\JsonApiLaravel\Event\BeforeActionEvent;
use haddowg\JsonApiLaravel\Event\BeforeCreateEvent;
use haddowg\JsonApiLaravel\Event\BeforeDeleteEvent;
use haddowg\JsonApiLaravel\Event\BeforeRelationshipMutateEvent;
use haddowg\JsonApiLaravel\Event\BeforeSaveEvent;
use haddowg\JsonApiLaravel\Event\BeforeUpdateEvent;
use haddowg\JsonApiLaravel\JsonApiServiceProvider;
use haddowg\JsonApiLaravel\Testing\InteractsWithJsonApi;
use Illuminate\Testing\TestResponse;
use Orchestra\Testbench\TestCase as Orchestra;
use PHPUnit\Framework\Attributes\Test;
use Workbench\App\WriteTransaction\TxScenario;

/**
 * Every single write runs in one transaction, from its first write step through the
 * persister's write: a Before* listener writes a note through the notes persister, a later
 * step throws, and neither the note nor the write survives. A writing action runs the same
 * way, a GET action does not, and an Atomic Operations batch keeps its own transaction. Runs
 * on the in-memory witness ({@see InMemoryWriteTransactionConformanceTest}) and Eloquent
 * ({@see EloquentWriteTransactionConformanceTest}), as the Symfony bundle's suite does.
 */
abstract class WriteTransactionConformanceTestCase extends Orchestra
{
    use InteractsWithJsonApi;

    public const string ATOMIC_MEDIA_TYPE = 'application/vnd.api+json; ext="' . AtomicExtension::URI . '"';

    /**
     * @return class-string
     */
    abstract protected function conformanceServiceProvider(): string;

    /**
     * @param \Illuminate\Foundation\Application $app
     *
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [
            JsonApiServiceProvider::class,
            $this->conformanceServiceProvider(),
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

    protected function seedConformanceData(): void {}

    protected function setUp(): void
    {
        parent::setUp();
        TxScenario::reset();
        $this->seedConformanceData();
    }

    protected function tearDown(): void
    {
        TxScenario::reset();
        parent::tearDown();
    }

    #[Test]
    public function aCreateKeepsWhatABeforeListenerWroteWhenNothingThrows(): void
    {
        TxScenario::$sideWriteOn = BeforeSaveEvent::class;

        $this->jsonApi()->withDocument($this->widget('third'))->post('/api/txWidgets')->assertStatus(201);

        self::assertSame(['seeded', 'by BeforeSaveEvent'], $this->notes());
        self::assertSame(['first', 'second', 'third'], $this->widgetNames());
    }

    #[Test]
    public function aCreateRollsBackWhatABeforeListenerWroteWhenALaterListenerThrows(): void
    {
        TxScenario::$sideWriteOn = BeforeSaveEvent::class;
        TxScenario::$throwOn = BeforeCreateEvent::class;

        $this->jsonApi()->withDocument($this->widget('third', notes: ['1']))->post('/api/txWidgets')->assertStatus(403);

        self::assertSame(['seeded'], $this->notes());
        self::assertSame(['first', 'second'], $this->widgetNames());
        $this->assertNoTransactionOpen();
    }

    #[Test]
    public function anUpdateRollsBackWhatABeforeListenerWroteWhenALaterListenerThrows(): void
    {
        TxScenario::$sideWriteOn = BeforeSaveEvent::class;
        TxScenario::$throwOn = BeforeUpdateEvent::class;

        $this->jsonApi()->withDocument($this->widget('renamed', id: '1', notes: []))->patch('/api/txWidgets/1')->assertStatus(403);

        self::assertSame(['seeded'], $this->notes());
        self::assertSame(['first', 'second'], $this->widgetNames());
        self::assertSame(['1'], $this->linkage('/api/txWidgets/1/relationships/notes'));
        $this->assertNoTransactionOpen();
    }

    #[Test]
    public function aDeleteRollsBackWhatABeforeListenerWroteWhenItThrows(): void
    {
        TxScenario::$sideWriteOn = BeforeDeleteEvent::class;
        TxScenario::$throwOn = BeforeDeleteEvent::class;

        $this->jsonApi()->delete('/api/txWidgets/2')->assertStatus(403);

        self::assertSame(['seeded'], $this->notes());
        self::assertSame(['first', 'second'], $this->widgetNames());
        $this->assertNoTransactionOpen();
    }

    #[Test]
    public function aDeleteKeepsWhatABeforeListenerWroteWhenNothingThrows(): void
    {
        TxScenario::$sideWriteOn = BeforeDeleteEvent::class;

        $this->jsonApi()->delete('/api/txWidgets/2')->assertStatus(204);

        self::assertSame(['seeded', 'by BeforeDeleteEvent'], $this->notes());
        self::assertSame(['first'], $this->widgetNames());
    }

    #[Test]
    public function aRelationshipReplaceRollsBackWhatABeforeListenerWrote(): void
    {
        $this->assertRelationshipWriteRollsBack('PATCH', '1', []);
    }

    #[Test]
    public function aRelationshipAddRollsBackWhatABeforeListenerWrote(): void
    {
        $this->assertRelationshipWriteRollsBack('POST', '2', ['1']);
    }

    #[Test]
    public function aRelationshipRemoveRollsBackWhatABeforeListenerWrote(): void
    {
        $this->assertRelationshipWriteRollsBack('DELETE', '1', ['1']);
    }

    #[Test]
    public function aCreateAcceptedForProcessingCommitsWhatItsBeforeListenersWrote(): void
    {
        TxScenario::$sideWriteOn = BeforeSaveEvent::class;
        TxScenario::$accept = true;

        $this->jsonApi()->withDocument($this->widget('third'))->post('/api/txWidgets')->assertStatus(202);

        TxScenario::reset();
        self::assertSame(['seeded', 'by BeforeSaveEvent'], $this->notes());
        self::assertSame(['first', 'second'], $this->widgetNames());
        $this->assertNoTransactionOpen();
    }

    #[Test]
    public function anUpdateAcceptedForProcessingCommitsWhatItsBeforeListenersWrote(): void
    {
        TxScenario::$sideWriteOn = BeforeUpdateEvent::class;
        TxScenario::$accept = true;

        $this->jsonApi()->withDocument($this->widget('renamed', id: '1'))->patch('/api/txWidgets/1')->assertStatus(202);

        TxScenario::reset();
        self::assertSame(['seeded', 'by BeforeUpdateEvent'], $this->notes());
        $this->assertNoTransactionOpen();
    }

    #[Test]
    public function aWritingActionKeepsItsWritesWhenNothingThrows(): void
    {
        $this->jsonApi()->withHeader('Content-Type', 'application/vnd.api+json')->post('/api/txWidgets/1/-actions/stamp')->assertStatus(204);

        self::assertSame(['seeded', 'stamped'], $this->notes());
        self::assertSame(['stamped', 'second'], $this->widgetNames());
    }

    #[Test]
    public function aWritingActionRollsBackWhenItsHandlerThrows(): void
    {
        TxScenario::$sideWriteOn = BeforeActionEvent::class;
        TxScenario::$actionThrows = true;

        $this->jsonApi()->withHeader('Content-Type', 'application/vnd.api+json')->post('/api/txWidgets/1/-actions/stamp')->assertStatus(403);

        self::assertSame(['seeded'], $this->notes());
        self::assertSame(['first', 'second'], $this->widgetNames());
        $this->assertNoTransactionOpen();
    }

    #[Test]
    public function aWritingActionRollsBackWhenItsAfterEventThrows(): void
    {
        TxScenario::$throwOn = AfterActionEvent::class;

        $this->jsonApi()->withHeader('Content-Type', 'application/vnd.api+json')->post('/api/txWidgets/1/-actions/stamp')->assertStatus(403);

        self::assertSame(['seeded'], $this->notes());
        self::assertSame(['first', 'second'], $this->widgetNames());
    }

    #[Test]
    public function aWritingCollectionActionRollsBackWhenItsHandlerThrows(): void
    {
        TxScenario::$actionThrows = true;

        $this->jsonApi()->withHeader('Content-Type', 'application/vnd.api+json')->post('/api/txWidgets/-actions/sweep')->assertStatus(403);

        self::assertSame(['seeded'], $this->notes());
    }

    #[Test]
    public function aGetActionRunsWithoutATransaction(): void
    {
        TxScenario::$actionThrows = true;

        $this->jsonApi()->get('/api/txWidgets/1/-actions/peek')->assertStatus(403);

        self::assertSame(['seeded', 'peeked'], $this->notes());
    }

    #[Test]
    public function aListenerTransactionCommittedAfterTheWriteKeepsEverything(): void
    {
        TxScenario::$nestOn = BeforeSaveEvent::class;
        TxScenario::$sideWriteOn = BeforeCreateEvent::class;
        TxScenario::$unnestOn = AfterCreateEvent::class;

        $this->jsonApi()->withDocument($this->widget('third'))->post('/api/txWidgets')->assertStatus(201);

        self::assertSame(['seeded', 'by BeforeCreateEvent'], $this->notes());
        self::assertSame(['first', 'second', 'third'], $this->widgetNames());
        $this->assertNoTransactionOpen();

        TxScenario::reset();
        $this->jsonApi()->withDocument($this->widget('fourth'))->post('/api/txWidgets')->assertStatus(201);
        self::assertSame(['first', 'second', 'third', 'fourth'], $this->widgetNames());
    }

    #[Test]
    public function aListenerTransactionLeftOpenIsRolledBackWithTheWrite(): void
    {
        TxScenario::$nestOn = BeforeSaveEvent::class;
        TxScenario::$sideWriteOn = BeforeSaveEvent::class;
        TxScenario::$unnestOn = AfterCreateEvent::class;
        TxScenario::$throwOn = BeforeCreateEvent::class;

        $this->jsonApi()->withDocument($this->widget('third'))->post('/api/txWidgets')->assertStatus(403);

        self::assertSame(['seeded'], $this->notes());
        self::assertSame(['first', 'second'], $this->widgetNames());
        $this->assertNoTransactionOpen();
    }

    #[Test]
    public function anAtomicBatchStillRollsBackAsOne(): void
    {
        TxScenario::$sideWriteOn = BeforeSaveEvent::class;
        TxScenario::$throwOn = BeforeCreateEvent::class;
        TxScenario::$throwForName = 'boom';

        $this->atomic([
            ['op' => 'add', 'data' => ['type' => 'txWidgets', 'attributes' => ['name' => 'third']]],
            ['op' => 'add', 'data' => ['type' => 'txWidgets', 'attributes' => ['name' => 'boom']]],
        ])->assertStatus(403);

        self::assertSame(['seeded'], $this->notes());
        self::assertSame(['first', 'second'], $this->widgetNames());
        $this->assertNoTransactionOpen();
    }

    #[Test]
    public function anAtomicBatchStillCommitsAsOne(): void
    {
        TxScenario::$sideWriteOn = BeforeSaveEvent::class;

        $this->atomic([
            ['op' => 'add', 'data' => ['type' => 'txWidgets', 'attributes' => ['name' => 'third']]],
            ['op' => 'add', 'data' => ['type' => 'txWidgets', 'attributes' => ['name' => 'fourth']]],
        ])->assertStatus(200);

        self::assertSame(['seeded', 'by BeforeSaveEvent', 'by BeforeSaveEvent'], $this->notes());
        self::assertSame(['first', 'second', 'third', 'fourth'], $this->widgetNames());
        $this->assertNoTransactionOpen();
    }

    /**
     * Asserts nothing the request opened is still open, on stores that can tell.
     */
    protected function assertNoTransactionOpen(): void {}

    /**
     * @param list<string> $ids
     */
    protected function assertRelationshipWriteRollsBack(string $method, string $widget, array $ids): void
    {
        TxScenario::$sideWriteOn = BeforeRelationshipMutateEvent::class;
        TxScenario::$throwOn = BeforeRelationshipMutateEvent::class;

        $request = $this->jsonApi()->withDocument([
            'data' => \array_map(static fn(string $id): array => ['type' => 'txNotes', 'id' => $id], $ids),
        ]);
        $uri = '/api/txWidgets/' . $widget . '/relationships/notes';
        $response = match ($method) {
            'PATCH' => $request->patch($uri),
            'POST' => $request->post($uri),
            default => $this->json('DELETE', $uri, ['data' => \array_map(static fn(string $id): array => ['type' => 'txNotes', 'id' => $id], $ids)], ['Accept' => 'application/vnd.api+json', 'CONTENT_TYPE' => 'application/vnd.api+json']),
        };
        $response->assertStatus(403);

        self::assertSame(['seeded'], $this->notes());
        self::assertSame(['1'], $this->linkage('/api/txWidgets/1/relationships/notes'));
        self::assertSame([], $this->linkage('/api/txWidgets/2/relationships/notes'));
        $this->assertNoTransactionOpen();
    }

    /**
     * @param list<string>|null $notes
     *
     * @return array<string, mixed>
     */
    protected function widget(string $name, ?string $id = null, ?array $notes = null): array
    {
        $data = ['type' => 'txWidgets', 'attributes' => ['name' => $name]];
        if ($id !== null) {
            $data['id'] = $id;
        }
        if ($notes !== null) {
            $data['relationships'] = ['notes' => ['data' => \array_map(static fn(string $note): array => ['type' => 'txNotes', 'id' => $note], $notes)]];
        }

        return ['data' => $data];
    }

    /**
     * @param list<array<string, mixed>> $operations
     *
     * @return TestResponse<\Symfony\Component\HttpFoundation\Response>
     */
    protected function atomic(array $operations): TestResponse
    {
        return $this->call('POST', '/api/operations', [], [], [], [
            'HTTP_ACCEPT' => self::ATOMIC_MEDIA_TYPE,
            'CONTENT_TYPE' => self::ATOMIC_MEDIA_TYPE,
        ], (string) \json_encode(['atomic:operations' => $operations]));
    }

    /**
     * @return list<string>
     */
    protected function notes(): array
    {
        return $this->column('/api/txNotes', 'body');
    }

    /**
     * @return list<string>
     */
    protected function widgetNames(): array
    {
        return $this->column('/api/txWidgets', 'name');
    }

    /**
     * @return list<string>
     */
    protected function linkage(string $uri): array
    {
        $response = $this->jsonApi()->get($uri)->assertOk();
        $data = $response->json('data');
        self::assertIsArray($data);

        $ids = [];
        foreach ($data as $identifier) {
            self::assertIsArray($identifier);
            self::assertIsString($identifier['id'] ?? null);
            $ids[] = $identifier['id'];
        }
        \sort($ids);

        return $ids;
    }

    /**
     * @return list<string>
     */
    private function column(string $uri, string $attribute): array
    {
        $response = $this->jsonApi()->get($uri)->assertOk();
        $data = $response->json('data');
        self::assertIsArray($data);

        $values = [];
        foreach ($data as $resource) {
            self::assertIsArray($resource);
            $attributes = $resource['attributes'] ?? null;
            self::assertIsArray($attributes);
            self::assertIsString($attributes[$attribute] ?? null);
            $values[] = $attributes[$attribute];
        }

        return $values;
    }
}
