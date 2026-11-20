<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Feature\TypedRequestMeta;

use haddowg\JsonApi\Atomic\AtomicExtension;
use haddowg\JsonApi\Resource\Field\Accessor;
use haddowg\JsonApiLaravel\DataProvider\DataProviderRegistry;
use haddowg\JsonApiLaravel\JsonApiServiceProvider;
use haddowg\JsonApiLaravel\OpenApi\DocumentFactory;
use haddowg\JsonApiLaravel\OpenApi\JsonSchemaFactory;
use haddowg\JsonApiLaravel\Testing\InteractsWithJsonApi;
use haddowg\JsonApiLaravel\Tests\Fixtures\TypedRequestMeta\TrMetaRecorder;
use haddowg\JsonApiLaravel\Tests\Fixtures\TypedRequestMeta\TypedRequestMetaServiceProvider;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Testing\TestResponse;
use Orchestra\Testbench\TestCase as Orchestra;
use PHPUnit\Framework\Attributes\Test;

/**
 * A write's top-level `meta` and its resource object's `data.meta` are validated against
 * what the type declares, as attributes are, and their typed values reach the write's
 * hooks. A resource object renders its declared meta, a relationship write may answer a
 * meta-only document and a 202 types its job document's meta. Debug mode refuses a
 * rendered meta that does not match its declaration. Runs on Eloquent
 * ({@see EloquentTypedRequestMetaTest}) and the in-memory witness
 * ({@see InMemoryTypedRequestMetaTest}).
 */
abstract class TypedRequestMetaTestCase extends Orchestra
{
    use InteractsWithJsonApi;

    private const string ATOMIC = 'application/vnd.api+json; ext="' . AtomicExtension::URI . '"';

    /**
     * `eloquent` or `memory`.
     */
    abstract protected function store(): string;

    /**
     * @param \Illuminate\Foundation\Application $app
     *
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app): array
    {
        /** @var \Illuminate\Contracts\Config\Repository $config */
        $config = $app['config'];
        $config->set('typed_request_meta.store', $this->store());

        return [JsonApiServiceProvider::class, TypedRequestMetaServiceProvider::class];
    }

    /**
     * @param \Illuminate\Foundation\Application $app
     */
    protected function defineEnvironment($app): void
    {
        /** @var \Illuminate\Contracts\Config\Repository $config */
        $config = $app['config'];
        $config->set('app.debug', false);
        $config->set('logging.default', 'null');
        $config->set('jsonapi.atomic_operations.enabled', true);
        $config->set('database.default', 'testing');
        $config->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => false,
        ]);
    }

    protected function defineDatabaseMigrations(): void
    {
        if ($this->store() !== 'eloquent') {
            return;
        }

        Schema::create('tr_items', static function (Blueprint $table): void {
            $table->increments('id');
            $table->string('name');
            $table->json('extras')->nullable();
        });
        Schema::create('tr_notes', static function (Blueprint $table): void {
            $table->increments('id');
            $table->string('title');
            $table->integer('revision')->default(1);
            $table->string('source')->nullable();
            $table->string('created_with')->nullable();
            $table->unsignedInteger('lead_id')->nullable();
        });
        Schema::create('tr_note_item', static function (Blueprint $table): void {
            $table->unsignedInteger('note_id');
            $table->unsignedInteger('item_id');
        });
        Schema::create('tr_plains', static function (Blueprint $table): void {
            $table->increments('id');
            $table->string('name');
        });
        Schema::create('tr_shelves', static function (Blueprint $table): void {
            $table->increments('id');
            $table->string('name');
        });
        Schema::create('tr_shelf_item', static function (Blueprint $table): void {
            $table->unsignedInteger('shelf_id');
            $table->unsignedInteger('item_id');
            $table->integer('position')->nullable();
            $table->json('extras')->nullable();
        });
    }

    protected function setUp(): void
    {
        parent::setUp();
        TrMetaRecorder::$seen = [];
        TrMetaRecorder::$raw = [];

        if ($this->store() !== 'eloquent') {
            return;
        }

        DB::table('tr_items')->insert([['id' => 1, 'name' => 'Pen', 'extras' => '{"size":3}'], ['id' => 2, 'name' => 'Ink', 'extras' => '[]']]);
        DB::table('tr_notes')->insert([['id' => 1, 'title' => 'Plan', 'lead_id' => 1], ['id' => 2, 'title' => 'Broken', 'lead_id' => null]]);
        DB::table('tr_note_item')->insert([['note_id' => 1, 'item_id' => 1]]);
        DB::table('tr_plains')->insert([['id' => 1, 'name' => 'Plain']]);
        DB::table('tr_shelves')->insert([['id' => 1, 'name' => 'Top']]);
    }

    #[Test]
    public function aCreatesRequestMetaReachesItsHooksTyped(): void
    {
        $response = $this->send('POST', '/api/tr-notes', [
            'data' => ['type' => 'tr-notes', 'attributes' => ['title' => 'Draft'], 'meta' => ['source' => 'import', 'label' => 'blue']],
            'meta' => ['idempotencyKey' => 'k-1', 'scheduledAt' => '2026-11-05T09:42:00+00:00', 'attempt' => 5.0],
        ]);
        $response->assertStatus(201);

        $data = $response->json('data');
        self::assertIsArray($data);
        self::assertSame('k-1', self::find($data, 'attributes', 'createdWith'), 'the create hook read the key through HookContext::writtenMeta()');
        self::assertSame(['revision' => 1], $data['meta'] ?? null, 'only the read-only member renders');

        $seen = TrMetaRecorder::$seen['tr-notes:BeforeCreateEvent'] ?? null;
        self::assertIsArray($seen);
        self::assertSame('k-1', $seen['document']['idempotencyKey'] ?? null);
        self::assertSame(5, $seen['document']['attempt'] ?? null, 'a whole 5.0 is normalised to its declared integer');
        $scheduled = $seen['document']['scheduledAt'] ?? null;
        self::assertInstanceOf(\DateTimeImmutable::class, $scheduled);
        self::assertSame('2026-11-05T09:42:00+00:00', $scheduled->format(\DATE_ATOM));
        self::assertSame(['source' => 'import', 'label' => 'blue'], $seen['resource']);
        self::assertSame($seen, TrMetaRecorder::$seen['tr-notes:AfterCreateEvent'] ?? null);

        // The fillUsing() hook stored the write-only `source`; `label` stayed on the meta.
        $id = $data['id'] ?? null;
        self::assertIsString($id);
        self::assertSame('import', $this->sourceOf($id));
    }

    #[Test]
    public function anUndeclaredOrMistypedMemberIs422(): void
    {
        $create = static fn(array $meta, array $resourceMeta = []): array => [
            'data' => ['type' => 'tr-notes', 'attributes' => ['title' => 'Draft'], ...($resourceMeta === [] ? [] : ['meta' => $resourceMeta])],
            'meta' => $meta,
        ];

        self::assertSame(['/meta/bogus' => 'The member "bogus" is not declared.'], $this->violations($this->send('POST', '/api/tr-notes', $create(['idempotencyKey' => 'k', 'bogus' => 1]))));
        self::assertSame(['/data/meta/bogus' => 'The member "bogus" is not declared.'], $this->violations($this->send('POST', '/api/tr-notes', $create(['idempotencyKey' => 'k'], ['bogus' => 1]))));
        self::assertSame(['/meta/attempt'], \array_keys($this->violations($this->send('POST', '/api/tr-notes', $create(['idempotencyKey' => 'k', 'attempt' => 'five'])))));
        self::assertSame(['/data/meta/source'], \array_keys($this->violations($this->send('POST', '/api/tr-notes', $create(['idempotencyKey' => 'k'], ['source' => 5])))));
        self::assertSame(['/meta/scheduledAt'], \array_keys($this->violations($this->send('POST', '/api/tr-notes', $create(['idempotencyKey' => 'k', 'scheduledAt' => '2026-11-05 09:42'])))));
        self::assertSame(['/meta/idempotencyKey'], \array_keys($this->violations($this->send('POST', '/api/tr-notes', $create(['attempt' => 1])))));
        self::assertSame(['/meta/idempotencyKey'], \array_keys($this->violations($this->send('POST', '/api/tr-notes', ['data' => ['type' => 'tr-notes', 'attributes' => ['title' => 'No meta']]]))));
        // The batch-only member is undeclared outside a batch.
        self::assertSame(['/meta/batchTag'], \array_keys($this->violations($this->send('POST', '/api/tr-notes', $create(['idempotencyKey' => 'k', 'batchTag' => 'b'])))));
        self::assertSame(['/meta'], \array_keys($this->violations($this->send('POST', '/api/tr-notes', $create(['k'])))));
    }

    #[Test]
    public function aReadOnlyResourceMetaMemberIsIgnoredOnAWrite(): void
    {
        $response = $this->send('PATCH', '/api/tr-notes/1', ['data' => ['type' => 'tr-notes', 'id' => '1', 'attributes' => ['title' => 'Plan again'], 'meta' => ['revision' => 7]], 'meta' => ['reason' => 'typo']]);
        $response->assertOk();
        self::assertSame(['revision' => 1], $response->json('data.meta'));
        self::assertSame(['document' => ['reason' => 'typo'], 'resource' => []], TrMetaRecorder::$seen['tr-notes:BeforeUpdateEvent'] ?? null);
    }

    #[Test]
    public function aDeleteTakesAMetaOnlyBody(): void
    {
        self::assertSame(['/meta/reason'], \array_keys($this->violations($this->send('DELETE', '/api/tr-notes/2'))));

        $this->send('DELETE', '/api/tr-notes/2', ['meta' => ['reason' => 'duplicate']])->assertNoContent();
        self::assertSame(['reason' => 'duplicate'], TrMetaRecorder::$seen['tr-notes:BeforeDeleteEvent']['document'] ?? null);
    }

    #[Test]
    public function eachRelationshipWriteTakesTheRelationsMeta(): void
    {
        $patch = $this->send('PATCH', '/api/tr-notes/1/relationships/items', ['data' => [['type' => 'tr-items', 'id' => '2']], 'meta' => ['reason' => 'swap']]);
        $patch->assertOk();
        self::assertSame([['type' => 'tr-items', 'id' => '2']], $patch->json('data'), 'a replace declares no document meta, so it answers its linkage');
        self::assertSame(['reason' => 'swap'], TrMetaRecorder::$seen['tr-notes:BeforeRelationshipMutateEvent']['document'] ?? null);

        // Adding answers the meta-only document its documentMeta() declares for AddRelationship.
        $add = $this->send('POST', '/api/tr-notes/1/relationships/items', ['data' => [['type' => 'tr-items', 'id' => '1']], 'meta' => ['reason' => 'more']]);
        $add->assertOk();
        $document = $add->json();
        self::assertIsArray($document);
        self::assertSame(['count' => 2], $document['meta'] ?? null);
        self::assertArrayNotHasKey('data', $document);

        $this->send('DELETE', '/api/tr-notes/1/relationships/items', ['data' => [['type' => 'tr-items', 'id' => '1']], 'meta' => ['reason' => 'less']])->assertOk();
        self::assertSame(['reason' => 'less'], TrMetaRecorder::$seen['tr-notes:BeforeRelationshipMutateEvent']['document'] ?? null);

        self::assertSame(['/meta/bogus' => 'The member "bogus" is not declared.'], $this->violations($this->send('POST', '/api/tr-notes/1/relationships/items', ['data' => [['type' => 'tr-items', 'id' => '2']], 'meta' => ['bogus' => true]])));
    }

    #[Test]
    public function anOpenResourceMetaTakesAnyMemberItsValueFieldAccepts(): void
    {
        $response = $this->send('POST', '/api/tr-items', ['data' => ['type' => 'tr-items', 'attributes' => ['name' => 'Nib'], 'meta' => ['floor' => 3.0, 'shelf' => 2]]]);
        $response->assertStatus(201);
        self::assertSame(['floor' => 3, 'shelf' => 2], $response->json('data.meta'));

        self::assertSame(['/data/meta/floor'], \array_keys($this->violations($this->send('POST', '/api/tr-items', ['data' => ['type' => 'tr-items', 'attributes' => ['name' => 'Nib'], 'meta' => ['floor' => 'third']]]))));
    }

    #[Test]
    public function aLinkageIdentifierCarriesNoResourceMeta(): void
    {
        $response = $this->send('GET', '/api/tr-notes/1?include=items');
        $response->assertOk();
        self::assertSame([['type' => 'tr-items', 'id' => '1']], $response->json('data.relationships.items.data'));
        self::assertSame(['size' => 3], $response->json('included.0.meta'), 'the full resource object renders its declared meta');
    }

    #[Test]
    public function anActionsRequestMetaDependsOnItsMethod(): void
    {
        self::assertSame(['silent' => true, 'method' => 'POST'], $this->send('POST', '/api/tr-notes/1/-actions/archive', ['meta' => ['silent' => true]])->json('meta'));
        self::assertSame(['silent' => false, 'method' => 'GET'], $this->send('GET', '/api/tr-notes/1/-actions/archive')->json('meta'));
        self::assertSame(['/meta/silent'], \array_keys($this->violations($this->send('POST', '/api/tr-notes/1/-actions/archive', ['meta' => ['silent' => 'yes']]))));
        self::assertSame(['/meta/bogus' => 'The member "bogus" is not declared.'], $this->violations($this->send('POST', '/api/tr-notes/1/-actions/archive', ['meta' => ['bogus' => 1]])));
    }

    #[Test]
    public function aDocumentActionsDataMetaIsTheInputTypesResourceMeta(): void
    {
        $response = $this->send('POST', '/api/tr-items/-actions/import', ['data' => ['type' => 'tr-items', 'attributes' => ['name' => 'Quill'], 'meta' => ['aisle' => 4]]]);
        $response->assertOk();
        self::assertSame(['aisle' => 4], $response->json('data.meta'));

        self::assertSame(['/data/meta/aisle'], \array_keys($this->violations($this->send('POST', '/api/tr-items/-actions/import', ['data' => ['type' => 'tr-items', 'attributes' => ['name' => 'Quill'], 'meta' => ['aisle' => 'four']]]))));
    }

    #[Test]
    public function eachAtomicOperationsMetaIsValidatedWithTheAtomicFlag(): void
    {
        $ok = $this->atomic(['atomic:operations' => [
            ['op' => 'add', 'data' => ['type' => 'tr-notes', 'attributes' => ['title' => 'Batch']], 'meta' => ['idempotencyKey' => 'k', 'batchTag' => 'b']],
            ['op' => 'add', 'ref' => ['type' => 'tr-notes', 'id' => '1', 'relationship' => 'items'], 'data' => [['type' => 'tr-items', 'id' => '2']]],
        ], 'meta' => ['anything' => ['goes' => true]]]);
        $ok->assertOk();
        self::assertSame('b', TrMetaRecorder::$seen['tr-notes:BeforeCreateEvent']['document']['batchTag'] ?? null);
        self::assertSame(['count' => 2], $ok->json('atomic:results.1.meta'), 'a relationship add answers its meta-only result');

        self::assertSame(['/atomic:operations/0/meta/batchTag'], \array_keys($this->violations($this->atomic(['atomic:operations' => [
            ['op' => 'add', 'data' => ['type' => 'tr-notes', 'attributes' => ['title' => 'Batch']], 'meta' => ['idempotencyKey' => 'k']],
        ]]))));
        self::assertSame(['/atomic:operations/0/data/meta/bogus' => 'The member "bogus" is not declared.'], $this->violations($this->atomic(['atomic:operations' => [
            ['op' => 'add', 'data' => ['type' => 'tr-notes', 'attributes' => ['title' => 'Batch'], 'meta' => ['bogus' => 1]], 'meta' => ['idempotencyKey' => 'k', 'batchTag' => 'b']],
        ]])));
    }

    #[Test]
    public function anAtomicOperationsDataMetaFillsThroughTheAtomicDeclaration(): void
    {
        $ok = $this->atomic(['atomic:operations' => [
            ['op' => 'add', 'data' => ['type' => 'tr-notes', 'attributes' => ['title' => 'Batch'], 'meta' => ['batchSource' => 'feed']], 'meta' => ['idempotencyKey' => 'k', 'batchTag' => 'b']],
        ]]);
        $ok->assertOk();
        $id = $ok->json('atomic:results.0.data.id');
        self::assertIsString($id);
        self::assertSame('feed', $this->sourceOf($id), 'the atomic declaration\'s fill hook stored it');

        // The standalone member is not part of the atomic shape.
        self::assertSame(['/atomic:operations/0/data/meta/source' => 'The member "source" is not declared.'], $this->violations($this->atomic(['atomic:operations' => [
            ['op' => 'add', 'data' => ['type' => 'tr-notes', 'attributes' => ['title' => 'Batch'], 'meta' => ['source' => 'feed']], 'meta' => ['idempotencyKey' => 'k', 'batchTag' => 'b']],
        ]])));
        // And the atomic member is not part of the standalone one.
        self::assertSame(['/data/meta/batchSource' => 'The member "batchSource" is not declared.'], $this->violations($this->send('POST', '/api/tr-notes', [
            'data' => ['type' => 'tr-notes', 'attributes' => ['title' => 'Draft'], 'meta' => ['batchSource' => 'feed']],
            'meta' => ['idempotencyKey' => 'k'],
        ])));
    }

    #[Test]
    public function aForbiddenUpdateIsRefusedBeforeItsMetaIsValidated(): void
    {
        $invalid = ['data' => ['type' => 'tr-notes', 'id' => '1', 'attributes' => ['title' => 'Again'], 'meta' => ['bogus' => 1]], 'meta' => ['reason' => 5]];

        $denied = $this->send('PATCH', '/api/tr-notes/1', $invalid, ['X-Deny-Update' => '1']);
        $denied->assertStatus(403);

        self::assertSame(['/meta/reason', '/data/meta/bogus'], \array_keys($this->violations($this->send('PATCH', '/api/tr-notes/1', $invalid))));
    }

    #[Test]
    public function aForbiddenDeleteIsRefusedBeforeItsMetaIsValidated(): void
    {
        $this->send('DELETE', '/api/tr-notes/2', ['meta' => ['reason' => 5]], ['X-Deny-Delete' => '1'])->assertStatus(403);
        self::assertArrayNotHasKey('tr-notes:BeforeDeleteEvent', TrMetaRecorder::$seen);

        self::assertSame(['/meta/reason'], \array_keys($this->violations($this->send('DELETE', '/api/tr-notes/2', ['meta' => ['reason' => 5]]))));
    }

    #[Test]
    public function anHrefAddressedAtomicOperationTakesItsTargetsDeclaration(): void
    {
        $ok = $this->atomic(['atomic:operations' => [
            ['op' => 'add', 'href' => '/api/tr-notes', 'data' => ['type' => 'tr-notes', 'attributes' => ['title' => 'Batch'], 'meta' => ['batchSource' => 'feed']], 'meta' => ['idempotencyKey' => 'k', 'batchTag' => 'b']],
            ['op' => 'update', 'href' => '/api/tr-notes/1', 'data' => ['type' => 'tr-notes', 'id' => '1', 'attributes' => ['title' => 'Plan again']], 'meta' => ['reason' => 'typo']],
            ['op' => 'add', 'href' => '/api/tr-notes/1/relationships/items', 'data' => [['type' => 'tr-items', 'id' => '2']], 'meta' => ['reason' => 'more']],
        ]]);
        $ok->assertOk();
        self::assertSame('b', TrMetaRecorder::$seen['tr-notes:BeforeCreateEvent']['document']['batchTag'] ?? null);
        self::assertSame(['reason' => 'typo'], TrMetaRecorder::$seen['tr-notes:BeforeUpdateEvent']['document'] ?? null);
        self::assertSame(['reason' => 'more'], TrMetaRecorder::$seen['tr-notes:BeforeRelationshipMutateEvent']['document'] ?? null);
        self::assertSame(['count' => 2], $ok->json('atomic:results.2.meta'), 'a relationship add answers its meta-only result');
        $id = $ok->json('atomic:results.0.data.id');
        self::assertIsString($id);
        self::assertSame('feed', $this->sourceOf($id), 'the atomic declaration\'s fill hook stored it');

        self::assertSame(['/atomic:operations/0/meta/batchTag'], \array_keys($this->violations($this->atomic(['atomic:operations' => [
            ['op' => 'add', 'href' => '/api/tr-notes', 'data' => ['type' => 'tr-notes', 'attributes' => ['title' => 'Batch']], 'meta' => ['idempotencyKey' => 'k']],
        ]]))));
        self::assertSame(['/atomic:operations/0/data/meta/source' => 'The member "source" is not declared.'], $this->violations($this->atomic(['atomic:operations' => [
            ['op' => 'add', 'href' => '/api/tr-notes', 'data' => ['type' => 'tr-notes', 'attributes' => ['title' => 'Batch'], 'meta' => ['source' => 'feed']], 'meta' => ['idempotencyKey' => 'k', 'batchTag' => 'b']],
        ]])));
        self::assertSame(['/atomic:operations/0/meta/bogus' => 'The member "bogus" is not declared.'], $this->violations($this->atomic(['atomic:operations' => [
            ['op' => 'update', 'href' => '/api/tr-notes/1/relationships/items', 'data' => [['type' => 'tr-items', 'id' => '1']], 'meta' => ['bogus' => true]],
        ]])));
        self::assertSame(['/atomic:operations/0/meta/reason'], \array_keys($this->violations($this->atomic(['atomic:operations' => [
            ['op' => 'remove', 'href' => '/api/tr-notes/2'],
        ]]))));
    }

    #[Test]
    public function aWritesAcceptedJobDocumentCarriesItsDeclaredMeta(): void
    {
        $export = $this->send('POST', '/api/tr-exports', ['data' => ['type' => 'tr-exports', 'attributes' => ['name' => 'All']]]);
        $export->assertStatus(202);
        self::assertSame(['jobStatus' => 'queued'], $export->json('meta'));

        $queue = $this->send('POST', '/api/tr-notes/1/-actions/queue', headers: ['Content-Type' => 'application/vnd.api+json']);
        $queue->assertStatus(202);
        $meta = $queue->json('meta');
        self::assertIsArray($meta);
        \ksort($meta);
        self::assertSame(['queue' => 'default', 'queuedAs' => 'job-9'], $meta);
    }

    #[Test]
    public function aTypeDeclaringNothingStaysOpen(): void
    {
        $response = $this->send('POST', '/api/tr-plains', ['data' => ['type' => 'tr-plains', 'attributes' => ['name' => 'Free'], 'meta' => ['anything' => 1]], 'meta' => ['whatever' => ['x' => 1]]]);
        $response->assertStatus(201);
        self::assertSame(['free' => true], $response->json('data.meta'));
        // The typed accessor reads no undeclared request meta, as an action's requestMeta() does,
        // while the raw meta stays on the request and data.meta is carried as sent.
        self::assertSame(['document' => [], 'resource' => ['anything' => 1]], TrMetaRecorder::$seen['tr-plains:BeforeCreateEvent'] ?? null);
        self::assertSame(['whatever' => ['x' => 1]], TrMetaRecorder::$raw['tr-plains:BeforeCreateEvent'] ?? null);

        config(['app.debug' => true]);
        $this->send('GET', '/api/tr-plains/1')->assertOk();
    }

    #[Test]
    public function productionRendersAMismatchedMetaAsItIs(): void
    {
        self::assertSame(['revision' => 'many'], $this->send('GET', '/api/tr-notes/2')->json('data.meta'));
        self::assertSame(['uses' => 2, 'bogus' => true], $this->send('GET', '/api/tr-labels/urgent', headers: ['X-Bogus-Meta' => '1'])->json('data.meta'));
        $this->send('GET', '/api/tr-notes/1?include=lead', headers: ['X-Bogus-Meta' => '1'])->assertOk();
    }

    #[Test]
    public function debugRefusesARenderedMetaThatDoesNotMatch(): void
    {
        config(['app.debug' => true]);

        $this->send('GET', '/api/tr-notes/1')->assertOk();
        self::assertSame(['uses' => 2], $this->send('GET', '/api/tr-labels/urgent')->json('data.meta'));

        $wrongType = $this->send('GET', '/api/tr-notes/2');
        $wrongType->assertStatus(500);
        self::assertStringContainsString('the member \"revision\" is string, not integer', (string) $wrongType->getContent());

        $undeclared = $this->send('GET', '/api/tr-labels/urgent', headers: ['X-Bogus-Meta' => '1']);
        $undeclared->assertStatus(500);
        self::assertStringContainsString('the member \"bogus\" is not declared', (string) $undeclared->getContent());

        $identifier = $this->send('GET', '/api/tr-notes/1?include=lead', headers: ['X-Bogus-Meta' => '1']);
        $identifier->assertStatus(500);
        self::assertStringContainsString('relationship \"lead\": the member \"bogus\" is not declared', (string) $identifier->getContent());
    }

    #[Test]
    public function productionRendersTheDeclaredMemberOverAClashingEntryMember(): void
    {
        $response = $this->send('GET', '/api/tr-tags/blue', headers: ['X-Clash-Meta' => '1', 'X-Clash-Document-Meta' => '1']);

        $response->assertOk();
        self::assertSame(['uses' => 2, 'color' => 'red'], $response->json('data.meta'));
        self::assertSame(['total' => 1], $response->json('meta'));
    }

    #[Test]
    public function debugRefusesAnEntryMemberClashingWithADeclaredOne(): void
    {
        config(['app.debug' => true]);

        $this->send('GET', '/api/tr-tags/blue')->assertOk();

        $resource = $this->send('GET', '/api/tr-tags/blue', headers: ['X-Clash-Meta' => '1']);
        $resource->assertStatus(500);
        self::assertStringContainsString('returned the member \\"uses\\", which a declared member of the same name replaced', (string) $resource->getContent());

        $document = $this->send('GET', '/api/tr-tags/blue', headers: ['X-Clash-Document-Meta' => '1']);
        $document->assertStatus(500);
        self::assertStringContainsString('returned the member \\"total\\"', (string) $document->getContent());
    }

    #[Test]
    public function anAtomicResultRendersTheDeclaredMemberOverAClashingEntryMember(): void
    {
        $response = $this->atomic(['atomic:operations' => [self::renameStamp('Clash')]]);

        $response->assertOk();
        self::assertSame(['stamp' => 'declared', 'ink' => 'blue'], $response->json('atomic:results.0.data.meta'));
    }

    #[Test]
    public function debugRefusesAClashingEntryMemberInsideAnAtomicBatch(): void
    {
        config(['app.debug' => true]);
        $this->atomic(['atomic:operations' => [self::renameStamp('Calm')]])->assertOk();

        $clash = $this->atomic(['atomic:operations' => [self::renameStamp('Clash')]]);
        $clash->assertStatus(500);
        self::assertStringContainsString('returned the member \\"stamp\\", which a declared member of the same name replaced', (string) $clash->getContent());
    }

    /**
     * An atomic update renaming the stamp stored as plain row 1.
     *
     * @return array<string, mixed>
     */
    private static function renameStamp(string $name): array
    {
        return ['op' => 'update', 'ref' => ['type' => 'tr-stamps', 'id' => '1'], 'data' => ['type' => 'tr-stamps', 'id' => '1', 'attributes' => ['name' => $name]]];
    }

    #[Test]
    public function debugChecksEachAtomicResult(): void
    {
        config(['app.debug' => true]);

        $broken = $this->atomic(['atomic:operations' => [
            ['op' => 'update', 'ref' => ['type' => 'tr-notes', 'id' => '2'], 'data' => ['type' => 'tr-notes', 'id' => '2', 'attributes' => ['title' => 'Broken']]],
        ]]);
        $broken->assertStatus(500);
        self::assertStringContainsString('the member \"revision\" is string, not integer', (string) $broken->getContent());
    }

    #[Test]
    public function theStandaloneSchemaTypesTheDeclaredResourceMeta(): void
    {
        $notes = $this->standaloneSchema('tr-notes');
        self::assertSame(['revision'], \array_keys((array) self::find($notes, 'properties', 'meta', 'properties')));
        self::assertFalse(self::find($notes, 'properties', 'meta', 'additionalProperties'));

        $plains = $this->standaloneSchema('tr-plains');
        self::assertSame(['type' => 'object'], self::find($plains, 'properties', 'meta'));
        self::assertSame(['type' => 'object'], self::find($plains, 'properties', 'relationships'));
    }

    /**
     * @return array<array-key, mixed>
     */
    protected function standaloneSchema(string $type): array
    {
        $factory = $this->app?->make(JsonSchemaFactory::class);
        self::assertInstanceOf(JsonSchemaFactory::class, $factory);
        $schema = \json_decode((string) \json_encode($factory->forType($type)), true);
        self::assertIsArray($schema);

        return $schema;
    }

    #[Test]
    public function theGeneratedDocumentTypesTheDeclaredMeta(): void
    {
        $factory = $this->app?->make(DocumentFactory::class);
        self::assertInstanceOf(DocumentFactory::class, $factory);
        $document = \json_decode((string) \json_encode($factory->forServer('default')->toArray()), true);
        self::assertIsArray($document);

        foreach ([
            ['/tr-notes', 'post', 'TrNotesCreateRequestDocument'],
            ['/tr-notes/{id}', 'patch', 'TrNotesUpdateRequestDocument'],
            ['/tr-notes/{id}', 'delete', 'TrNotesDeleteRequestDocument'],
            ['/tr-notes/{id}/relationships/items', 'post', 'TrNotesItemsAddRelationshipRequestDocument'],
            ['/tr-notes/{id}/-actions/archive', 'post', 'TrNotesArchiveActionPostRequestDocument'],
        ] as [$path, $method, $component]) {
            self::assertSame(['$ref' => '#/components/schemas/' . $component], self::find($document, 'paths', $path, $method, 'requestBody', 'content', 'application/vnd.api+json', 'schema'), $method . ' ' . $path);
            self::assertTrue(self::find($document, 'components', 'schemas', $component, 'x-request-meta'), $component);
        }
        self::assertNull(self::find($document, 'components', 'schemas', 'TrNotesArchiveActionGetRequestDocument'));

        // A delete taking request meta validates its body, so it answers 422; one taking none does not.
        self::assertNotNull(self::find($document, 'paths', '/tr-notes/{id}', 'delete', 'responses', '422'));
        self::assertNull(self::find($document, 'paths', '/tr-plains/{id}', 'delete', 'responses', '422'));
        self::assertSame(['$ref' => '#/components/examples/VALIDATION_FAILED'], self::find($document, 'paths', '/tr-notes/{id}', 'delete', 'responses', '422', 'content', 'application/vnd.api+json', 'examples', 'VALIDATION_FAILED'));
        self::assertSame(['$ref' => '#/components/examples/VALIDATION_FAILED'], self::find($document, 'paths', '/tr-notes/{id}/-actions/archive', 'post', 'responses', '422', 'content', 'application/vnd.api+json', 'examples', 'VALIDATION_FAILED'));

        foreach (['TrNotesResourceMeta', 'TrNotesCreateResourceMeta', 'TrNotesUpdateResourceMeta', 'TrItemsResourceMeta'] as $component) {
            self::assertTrue(self::find($document, 'components', 'schemas', $component, 'x-resource-meta'), $component);
        }
        self::assertSame(['$ref' => '#/components/schemas/TrNotesResourceMeta'], self::find($document, 'components', 'schemas', 'TrNotesResource', 'properties', 'meta'));
        self::assertFalse(self::find($document, 'components', 'schemas', 'TrNotesResourceMeta', 'additionalProperties'));
        self::assertSame(['type' => ['integer', 'null']], self::find($document, 'components', 'schemas', 'TrItemsResourceMeta', 'additionalProperties'));
        self::assertSame(['type' => 'integer'], self::find($document, 'components', 'schemas', 'TrItemsCreateResourceMeta', 'additionalProperties'));
        self::assertNull(self::find($document, 'components', 'schemas', 'TrPlainsResourceMeta'));

        self::assertSame(['$ref' => '#/components/schemas/TrNotesItemsAddRelationshipDocument'], self::find($document, 'paths', '/tr-notes/{id}/relationships/items', 'post', 'responses', '200', 'content', 'application/vnd.api+json', 'schema'));
        self::assertSame(['$ref' => '#/components/schemas/TrExportsCreateAcceptedDocument'], self::find($document, 'paths', '/tr-exports', 'post', 'responses', '202', 'content', 'application/vnd.api+json', 'schema'));
        self::assertSame(['$ref' => '#/components/schemas/TrNotesQueueActionAcceptedDocument'], self::find($document, 'paths', '/tr-notes/{id}/-actions/queue', 'post', 'responses', '202', 'content', 'application/vnd.api+json', 'schema'));

        self::assertTrue(self::find($document, 'components', 'schemas', 'TrNotesAtomicAddOperation', 'x-request-meta'));
        self::assertTrue(self::find($document, 'components', 'schemas', 'TrNotesItemsAtomicRelationshipAddOperation', 'x-request-meta'));
        self::assertSame(['idempotencyKey', 'batchTag'], self::find($document, 'components', 'schemas', 'TrNotesAtomicAddOperation', 'properties', 'meta', 'required'));
    }

    /**
     * The stored `source` of a note, read back through the data layer.
     */
    private function sourceOf(string $id): ?string
    {
        $registry = $this->app?->make(DataProviderRegistry::class);
        self::assertInstanceOf(DataProviderRegistry::class, $registry);
        $note = $registry->forType('tr-notes')->fetchOne('tr-notes', $id);
        self::assertNotNull($note);
        $source = Accessor::get($note, 'source');

        return \is_string($source) ? $source : null;
    }

    /**
     * @param array<string, mixed>|null $document
     * @param array<string, string>     $headers
     *
     * @return TestResponse<\Symfony\Component\HttpFoundation\Response>
     */
    private function send(string $method, string $uri, ?array $document = null, array $headers = []): TestResponse
    {
        return $this->sendJsonApiRequest($method, $uri, $document, $headers);
    }

    /**
     * @param array<string, mixed> $batch
     *
     * @return TestResponse<\Symfony\Component\HttpFoundation\Response>
     */
    private function atomic(array $batch): TestResponse
    {
        return $this->call('POST', '/api/operations', [], [], [], [
            'HTTP_ACCEPT' => self::ATOMIC,
            'CONTENT_TYPE' => self::ATOMIC,
        ], (string) \json_encode($batch));
    }

    /**
     * Each `422` error's pointer and detail.
     *
     * @param TestResponse<\Symfony\Component\HttpFoundation\Response> $response
     *
     * @return array<string, string>
     */
    private function violations(TestResponse $response): array
    {
        $response->assertStatus(422);
        $errors = $response->json('errors');
        self::assertIsArray($errors);
        $violations = [];
        foreach ($errors as $error) {
            self::assertIsArray($error);
            $pointer = self::find($error, 'source', 'pointer');
            self::assertIsString($pointer);
            $violations[$pointer] = \is_string($error['detail'] ?? null) ? $error['detail'] : '';
        }

        return $violations;
    }

    /**
     * @param array<array-key, mixed> $document
     */
    protected static function find(array $document, int|string ...$keys): mixed
    {
        $node = $document;
        foreach ($keys as $key) {
            if (!\is_array($node) || !\array_key_exists($key, $node)) {
                return null;
            }
            $node = $node[$key];
        }

        return $node;
    }
}
