<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Feature\RelatedQuery;

use haddowg\JsonApiLaravel\JsonApiServiceProvider;
use haddowg\JsonApiLaravel\Tests\Fixtures\RelatedQuery\RelatedQueryServiceProvider;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Testing\TestResponse;
use Orchestra\Testbench\TestCase as Orchestra;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;

/**
 * `relatedQuery` on every operation that renders resources of one type, and its
 * refusal everywhere else, run against the in-memory and Eloquent providers.
 *
 * The rendered type's relations over one type that a read renders are addressable by
 * name: a to-many by `sort` and `filter`, a to-one by `filter` only. A polymorphic or
 * write-only relation and a dotted path are not, and neither is any path on a response
 * rendering several types. Shelf 1 holds books Alpha (1), Beta (2) and Gamma (3) and
 * features Beta. Ada (1) wrote Alpha and Gamma and mentors Grace (2), who wrote Beta and
 * mentors Alan (3).
 */
#[Group('spec:profiles')]
abstract class RelatedQueryOperationsTestCase extends Orchestra
{
    private const string MEDIA_TYPE = 'application/vnd.api+json';

    private const string PROFILE_ACCEPT = self::MEDIA_TYPE . ';profile="https://haddowg.github.io/json-api/profiles/relationship-queries/"';

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
        $config->set('related_query.store', $this->store());

        return [JsonApiServiceProvider::class, RelatedQueryServiceProvider::class];
    }

    /**
     * @param \Illuminate\Foundation\Application $app
     */
    protected function defineEnvironment($app): void
    {
        /** @var \Illuminate\Contracts\Config\Repository $config */
        $config = $app['config'];
        $config->set('jsonapi.servers', [
            'default' => ['prefix' => 'api', 'middleware' => [], 'domain' => null],
        ]);
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

        Schema::create('rq_people', static function (Blueprint $table): void {
            $table->increments('id');
            $table->string('name');
            $table->unsignedInteger('mentor_id')->nullable();
        });
        Schema::create('rq_books', static function (Blueprint $table): void {
            $table->increments('id');
            $table->string('title');
            $table->unsignedInteger('writer_id')->nullable();
        });
        Schema::create('rq_shelves', static function (Blueprint $table): void {
            $table->increments('id');
            $table->string('name');
            $table->unsignedInteger('featured_id')->nullable();
        });
        Schema::create('rq_shelf_book', static function (Blueprint $table): void {
            $table->unsignedInteger('shelf_id');
            $table->unsignedInteger('book_id');
        });
    }

    protected function setUp(): void
    {
        parent::setUp();

        if ($this->store() !== 'eloquent') {
            return;
        }

        DB::table('rq_people')->insert([
            ['id' => 1, 'name' => 'Ada', 'mentor_id' => null],
            ['id' => 2, 'name' => 'Grace', 'mentor_id' => 1],
            ['id' => 3, 'name' => 'Alan', 'mentor_id' => 2],
        ]);
        DB::table('rq_books')->insert([
            ['id' => 1, 'title' => 'Alpha', 'writer_id' => 1],
            ['id' => 2, 'title' => 'Beta', 'writer_id' => 2],
            ['id' => 3, 'title' => 'Gamma', 'writer_id' => 1],
        ]);
        DB::table('rq_shelves')->insert(['id' => 1, 'name' => 'Main', 'featured_id' => 2]);
        DB::table('rq_shelf_book')->insert([
            ['shelf_id' => 1, 'book_id' => 1],
            ['shelf_id' => 1, 'book_id' => 2],
            ['shelf_id' => 1, 'book_id' => 3],
        ]);
    }

    #[Test]
    public function aCollectionReadSortsAndFiltersARelation(): void
    {
        $sorted = $this->document($this->send('/shelves?relatedQuery[books][sort]=-title'));
        self::assertSame(['3', '2', '1'], $this->linkageIds($this->resource($sorted, 'shelves', '1'), 'books'));

        $filtered = $this->document($this->send('/shelves?relatedQuery[books][filter][title]=Beta'));
        self::assertSame(['2'], $this->linkageIds($this->resource($filtered, 'shelves', '1'), 'books'));
    }

    #[Test]
    public function aSingleReadSortsAndFiltersARelation(): void
    {
        $document = $this->document($this->send('/shelves/1?relatedQuery[books][sort]=-title&relatedQuery[books][filter][id]=1,3'));
        self::assertSame(['3', '1'], $this->linkageIds($document['data'], 'books'));

        $kept = $this->document($this->send('/shelves/1?include=featured&relatedQuery[featured][filter][title]=Beta'));
        self::assertSame('2', $this->toOneId($kept['data'], 'featured'));

        $dropped = $this->document($this->send('/shelves/1?include=featured&relatedQuery[featured][filter][title]=Alpha'));
        self::assertNull($this->toOneId($dropped['data'], 'featured'));
        self::assertSame([], $this->includedIds($dropped, 'books'));
    }

    #[Test]
    public function aCreateAnsweringWithItsResourceSortsAndFiltersARelation(): void
    {
        $response = $this->send(
            '/shelves?include=featured&relatedQuery[books][sort]=-title&relatedQuery[books][filter][id]=1,2&relatedQuery[featured][filter][title]=Gamma',
            'POST',
            ['data' => [
                'type' => 'shelves',
                'attributes' => ['name' => 'Second'],
                'relationships' => [
                    'books' => ['data' => [['type' => 'books', 'id' => '1'], ['type' => 'books', 'id' => '2'], ['type' => 'books', 'id' => '3']]],
                    'featured' => ['data' => ['type' => 'books', 'id' => '1']],
                ],
            ]],
        );

        $document = $this->document($response, 201);
        self::assertSame(['2', '1'], $this->linkageIds($document['data'], 'books'));
        self::assertNull($this->toOneId($document['data'], 'featured'));
    }

    #[Test]
    public function anUpdateAnsweringWithItsResourceSortsAndFiltersARelation(): void
    {
        $response = $this->send(
            '/shelves/1?include=featured&relatedQuery[books][sort]=title&relatedQuery[books][filter][id]=2,3&relatedQuery[featured][filter][title]=Alpha',
            'PATCH',
            ['data' => ['type' => 'shelves', 'id' => '1', 'attributes' => ['name' => 'Renamed']]],
        );

        $document = $this->document($response);
        self::assertSame(['2', '3'], $this->linkageIds($document['data'], 'books'));
        self::assertNull($this->toOneId($document['data'], 'featured'));
    }

    #[Test]
    public function aToOneRelatedReadAddressesTheRelatedTypesRelations(): void
    {
        // GET /books/1/writer renders Ada, whose books are Alpha and Gamma.
        $sorted = $this->document($this->send('/books/1/writer?include=books&relatedQuery[books][sort]=-title'));
        self::assertSame('1', $this->primary($sorted)['id'] ?? null);
        self::assertSame(['3', '1'], $this->linkageIds($sorted['data'], 'books'));

        $filtered = $this->document($this->send('/books/1/writer?include=books&relatedQuery[books][filter][title]=Gamma'));
        self::assertSame(['3'], $this->linkageIds($filtered['data'], 'books'));
    }

    #[Test]
    public function aToManyRelatedReadAddressesTheRelatedTypesRelations(): void
    {
        // GET /people/1/books renders Alpha and Gamma, both written by Ada.
        $dropped = $this->document($this->send('/people/1/books?include=writer&relatedQuery[writer][filter][name]=Grace'));
        self::assertSame(['1', '3'], $this->dataIds($dropped));
        foreach ($this->primary($dropped) as $book) {
            self::assertNull($this->toOneId($book, 'writer'));
        }
        self::assertSame([], $this->includedIds($dropped, 'people'));

        $kept = $this->document($this->send('/people/1/books?include=writer&relatedQuery[writer][filter][name]=Ada'));
        foreach ($this->primary($kept) as $book) {
            self::assertSame('1', $this->toOneId($book, 'writer'));
        }
    }

    #[Test]
    public function aRelatedReadNeverFiltersItsTarget(): void
    {
        // GET /people/3/mentor renders Grace. `mentor` names Grace's own mentor, Ada, so
        // the filter drops Ada from Grace's linkage and leaves Grace in place.
        $dropped = $this->document($this->send('/people/3/mentor?include=mentor&relatedQuery[mentor][filter][name]=Nobody'));
        self::assertSame('2', $this->primary($dropped)['id'] ?? null);
        self::assertNull($this->toOneId($dropped['data'], 'mentor'));

        $kept = $this->document($this->send('/people/3/mentor?include=mentor&relatedQuery[mentor][filter][name]=Ada'));
        self::assertSame('2', $this->primary($kept)['id'] ?? null);
        self::assertSame('1', $this->toOneId($kept['data'], 'mentor'));

        // The related endpoint's own filter still narrows the target.
        $own = $this->document($this->send('/people/3/mentor?filter[name]=Nobody'));
        self::assertNull($own['data']);
    }

    #[Test]
    public function aToOneRelatedReadRefusesItsOwnRelationName(): void
    {
        // People declare no `writer` relation, so the path is unaddressable on the
        // people GET /books/1/writer renders.
        $this->assertUnaddressable($this->send('/books/1/writer?relatedQuery[writer][filter][name]=Ada'), 'relatedQuery[writer]');
    }

    #[Test]
    public function anEmptyToOneRelatedReadStillChecksThePath(): void
    {
        // Ada has no mentor, so nothing renders, but the path is still checked.
        $this->assertUnaddressable($this->send('/people/1/mentor?relatedQuery[nothing][filter][name]=x'), 'relatedQuery[nothing]');
    }

    #[Test]
    public function anActionAnsweringWithAResourceSortsAndFiltersARelation(): void
    {
        $document = $this->document($this->send('/shelves/1/-actions/show?include=featured&relatedQuery[books][sort]=-title&relatedQuery[featured][filter][title]=Alpha', 'POST'));
        self::assertSame(['3', '2', '1'], $this->linkageIds($document['data'], 'books'));
        self::assertNull($this->toOneId($document['data'], 'featured'));

        $filtered = $this->document($this->send('/shelves/1/-actions/show?relatedQuery[books][filter][title]=Alpha', 'POST'));
        self::assertSame(['1'], $this->linkageIds($filtered['data'], 'books'));
    }

    #[Test]
    public function anUnaddressablePathIsRefused(): void
    {
        foreach ($this->unaddressablePaths() as $case => [$method, $path, $parameter]) {
            $this->assertUnaddressable($this->send($path, $method), $parameter, $case);
        }
    }

    /**
     * @return iterable<string, array{string, string, string}>
     */
    private function unaddressablePaths(): iterable
    {
        yield 'a polymorphic to-many' => ['GET', '/shelves/1?relatedQuery[mixed][filter][title]=Alpha', 'relatedQuery[mixed]'];
        yield 'a polymorphic to-one' => ['GET', '/people/2?relatedQuery[favourite][filter][name]=Ada', 'relatedQuery[favourite]'];
        yield 'a dotted path' => ['GET', '/shelves/1?relatedQuery[books.writer][filter][name]=Ada', 'relatedQuery[books.writer]'];
        yield 'a write-only relation' => ['GET', '/shelves/1?relatedQuery[drafts][sort]=title', 'relatedQuery[drafts]'];
        yield 'a sort on a to-one' => ['GET', '/shelves/1?relatedQuery[featured][sort]=title', 'relatedQuery[featured]'];
        yield 'a sort on a to-one of a related read' => ['GET', '/books/1/writer?relatedQuery[mentor][sort]=name', 'relatedQuery[mentor]'];
        yield 'a polymorphic to-many related read' => ['GET', '/shelves/1/mixed?relatedQuery[writer][filter][name]=Ada', 'relatedQuery[writer]'];
        yield 'a polymorphic to-one related read' => ['GET', '/people/3/favourite?relatedQuery[favourite][filter][name]=Nobody', 'relatedQuery[favourite]'];
        yield 'a polymorphic to-one related read addressing a member relation' => ['GET', '/people/3/favourite?relatedQuery[books][sort]=-title', 'relatedQuery[books]'];
        yield 'an action answering with several types' => ['POST', '/shelves/-actions/mix?relatedQuery[books][sort]=-title', 'relatedQuery[books]'];
        yield 'a polymorphic relation on an action' => ['POST', '/shelves/1/-actions/show?relatedQuery[mixed][sort]=title', 'relatedQuery[mixed]'];
    }

    #[Test]
    public function aCreateRefusesAnUnaddressablePathBeforeItWrites(): void
    {
        $response = $this->send(
            '/shelves?relatedQuery[mixed][sort]=title',
            'POST',
            ['data' => ['type' => 'shelves', 'attributes' => ['name' => 'Second']]],
        );

        $this->assertUnaddressable($response, 'relatedQuery[mixed]');
        self::assertSame(['1'], $this->dataIds($this->document($this->send('/shelves'))));
    }

    #[Test]
    public function anUpdateRefusesAnUnknownSortBeforeItWrites(): void
    {
        $response = $this->send(
            '/shelves/1?relatedQuery[books][sort]=pages',
            'PATCH',
            ['data' => ['type' => 'shelves', 'id' => '1', 'attributes' => ['name' => 'Renamed']]],
        );

        self::assertSame(400, $response->getStatusCode(), (string) $response->getContent());
        self::assertSame('SORTING_UNRECOGNIZED', $this->firstError($this->decode($response))['code'] ?? null);
        self::assertSame(['name' => 'Main'], $this->primary($this->document($this->send('/shelves/1')))['attributes'] ?? null);
    }

    #[Test]
    public function aRelationshipReadTakesNoRelatedQuery(): void
    {
        // Linkage is the primary data here, not a relationship of a rendered resource,
        // so the family does not apply to it.
        $toOne = $this->document($this->send('/shelves/1/relationships/featured?relatedQuery[featured][filter][title]=Alpha'));
        self::assertSame('2', $this->primary($toOne)['id'] ?? null);

        $toMany = $this->document($this->send('/shelves/1/relationships/books?relatedQuery[books][filter][title]=Alpha'));
        self::assertSame(['1', '2', '3'], $this->dataIds($toMany));
    }

    /**
     * @param array<string, mixed>|null $body
     *
     * @return TestResponse<\Symfony\Component\HttpFoundation\Response>
     */
    private function send(string $path, string $method = 'GET', ?array $body = null): TestResponse
    {
        $server = ['HTTP_ACCEPT' => self::PROFILE_ACCEPT];
        if ($method !== 'GET') {
            $server['CONTENT_TYPE'] = self::MEDIA_TYPE;
        }

        return $this->call($method, '/api' . $path, server: $server, content: $body === null ? null : \json_encode($body, \JSON_THROW_ON_ERROR));
    }

    /**
     * @param TestResponse<\Symfony\Component\HttpFoundation\Response> $response
     *
     * @return array<string, mixed>
     */
    private function document(TestResponse $response, int $status = 200): array
    {
        self::assertSame($status, $response->getStatusCode(), (string) $response->getContent());

        return $this->decode($response);
    }

    /**
     * @param TestResponse<\Symfony\Component\HttpFoundation\Response> $response
     *
     * @return array<string, mixed>
     */
    private function decode(TestResponse $response): array
    {
        $document = \json_decode((string) $response->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($document);

        /** @var array<string, mixed> $document */
        return $document;
    }

    /**
     * @param array<string, mixed> $document
     *
     * @return array<array-key, mixed>
     */
    private function primary(array $document): array
    {
        $data = $document['data'] ?? null;
        self::assertIsArray($data);

        return $data;
    }

    /**
     * @param TestResponse<\Symfony\Component\HttpFoundation\Response> $response
     */
    private function assertUnaddressable(TestResponse $response, string $parameter, string $case = ''): void
    {
        self::assertSame(400, $response->getStatusCode(), $case . ' ' . $response->getContent());

        $error = $this->firstError($this->decode($response));
        self::assertSame('QUERY_PARAM_UNRECOGNIZED', $error['code'] ?? null, $case);
        self::assertSame(['parameter' => $parameter], $error['source'] ?? null, $case);
    }

    /**
     * @param array<string, mixed> $document
     *
     * @return array<string, mixed>
     */
    private function firstError(array $document): array
    {
        $errors = $document['errors'] ?? null;
        self::assertIsArray($errors);
        $error = $errors[0] ?? null;
        self::assertIsArray($error);

        return $error;
    }

    /**
     * @param array<string, mixed> $document
     *
     * @return array<string, mixed>
     */
    private function resource(array $document, string $type, string $id): array
    {
        $data = $document['data'] ?? null;
        self::assertIsArray($data);
        foreach ($data as $resource) {
            if (\is_array($resource) && ($resource['type'] ?? null) === $type && ($resource['id'] ?? null) === $id) {
                return $resource;
            }
        }

        self::fail(\sprintf('No %s "%s" in the primary data.', $type, $id));
    }

    /**
     * @param array<string, mixed> $document
     *
     * @return list<string>
     */
    private function dataIds(array $document): array
    {
        $data = $document['data'] ?? null;
        self::assertIsArray($data);

        $ids = [];
        foreach ($data as $resource) {
            self::assertIsArray($resource);
            self::assertIsString($resource['id'] ?? null);
            $ids[] = $resource['id'];
        }

        return $ids;
    }

    /**
     * @param array<string, mixed> $document
     *
     * @return list<string>
     */
    private function includedIds(array $document, string $type): array
    {
        $ids = [];
        foreach (\is_array($document['included'] ?? null) ? $document['included'] : [] as $resource) {
            if (\is_array($resource) && ($resource['type'] ?? null) === $type && \is_string($resource['id'] ?? null)) {
                $ids[] = $resource['id'];
            }
        }

        return $ids;
    }

    /**
     * @return list<string>
     */
    private function linkageIds(mixed $resource, string $relationship): array
    {
        $data = $this->linkage($resource, $relationship);
        self::assertIsArray($data, \sprintf('"%s" carries to-many linkage', $relationship));

        $ids = [];
        foreach ($data as $identifier) {
            self::assertIsArray($identifier);
            self::assertIsString($identifier['id'] ?? null);
            $ids[] = $identifier['id'];
        }

        return $ids;
    }

    private function toOneId(mixed $resource, string $relationship): ?string
    {
        $data = $this->linkage($resource, $relationship);
        if ($data === null) {
            return null;
        }
        self::assertIsArray($data);
        self::assertIsString($data['id'] ?? null);

        return $data['id'];
    }

    private function linkage(mixed $resource, string $relationship): mixed
    {
        self::assertIsArray($resource);
        $relationships = $resource['relationships'] ?? null;
        self::assertIsArray($relationships);
        $object = $relationships[$relationship] ?? null;
        self::assertIsArray($object, \sprintf('relationship "%s" is present', $relationship));
        self::assertArrayHasKey('data', $object, \sprintf('relationship "%s" renders linkage', $relationship));

        return $object['data'];
    }
}
