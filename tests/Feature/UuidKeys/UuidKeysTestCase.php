<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Feature\UuidKeys;

use haddowg\JsonApiLaravel\DataProvider\Eloquent\EloquentDataProvider;
use haddowg\JsonApiLaravel\JsonApiServiceProvider;
use haddowg\JsonApiLaravel\Tests\Fixtures\UuidKeys\Book;
use haddowg\JsonApiLaravel\Tests\Fixtures\UuidKeys\Label;
use haddowg\JsonApiLaravel\Tests\Fixtures\UuidKeys\Note;
use haddowg\JsonApiLaravel\Tests\Fixtures\UuidKeys\UuidKeysServiceProvider;
use haddowg\JsonApiLaravel\Tests\Fixtures\UuidKeys\Writer;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Testing\TestResponse;
use Orchestra\Testbench\TestCase as Orchestra;
use PHPUnit\Framework\Attributes\Test;

/**
 * Every read and write path over UUID primary and foreign keys, stored as Laravel's
 * {@see \Illuminate\Database\Eloquent\Concerns\HasUuids} strings or as 16-byte binary columns
 * behind a cast. The wire carries the canonical UUID either way.
 *
 * Seed: writer W1 wrote books B1 "Dune" and B2 "Emma"; W2 wrote B3 "Ulysses"; B1 carries
 * labels L1 and L2, B2 carries L1.
 *
 * @internal
 */
abstract class UuidKeysTestCase extends Orchestra
{
    protected const string W1 = '0b6a1c2e-6a8f-4d55-9a6e-1f2d3c4b5a61';
    protected const string W2 = '0b6a1c2e-6a8f-4d55-9a6e-1f2d3c4b5a62';
    protected const string B1 = '1c7b2d3f-7b9a-4e66-8b7f-2a3e4d5c6b71';
    protected const string B2 = '1c7b2d3f-7b9a-4e66-8b7f-2a3e4d5c6b72';
    protected const string B3 = '1c7b2d3f-7b9a-4e66-8b7f-2a3e4d5c6b73';
    protected const string L1 = '2d8c3e4a-8cab-4f77-9c8a-3b4f5e6d7c81';
    protected const string L2 = '2d8c3e4a-8cab-4f77-9c8a-3b4f5e6d7c82';
    protected const string L3 = '2d8c3e4a-8cab-4f77-9c8a-3b4f5e6d7c83';

    private const string MEDIA_TYPE = 'application/vnd.api+json';

    private const string COUNTABLE = 'application/vnd.api+json; profile="https://haddowg.github.io/json-api/profiles/countable/"';

    private const string QUERIES = 'application/vnd.api+json; profile="https://haddowg.github.io/json-api/profiles/relationship-queries/ https://haddowg.github.io/json-api/profiles/countable/"';

    /**
     * `string` or `binary`.
     */
    abstract protected function storage(): string;

    /**
     * @param \Illuminate\Foundation\Application $app
     *
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app): array
    {
        /** @var \Illuminate\Contracts\Config\Repository $config */
        $config = $app['config'];
        $config->set('uuid.storage', $this->storage());
        $config->set('jsonapi.atomic_operations.enabled', true);

        return [JsonApiServiceProvider::class, UuidKeysServiceProvider::class];
    }

    /**
     * @param \Illuminate\Foundation\Application $app
     */
    protected function defineEnvironment($app): void
    {
        /** @var \Illuminate\Contracts\Config\Repository $config */
        $config = $app['config'];
        $config->set('database.default', 'testing');
        $config->set('database.connections.testing', ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
    }

    protected function defineDatabaseMigrations(): void
    {
        $binary = $this->storage() === 'binary';
        $key = static function (Blueprint $table, string $column) use ($binary) {
            return $binary ? $table->binary($column, 16, fixed: true) : $table->uuid($column);
        };

        Schema::create('uk_writers', static function (Blueprint $table) use ($key): void {
            $key($table, 'id')->primary();
            $table->string('name');
        });
        Schema::create('uk_books', static function (Blueprint $table) use ($key): void {
            $key($table, 'id')->primary();
            $table->string('title');
            $key($table, 'writer_id')->nullable();
        });
        Schema::create('uk_labels', static function (Blueprint $table) use ($key): void {
            $key($table, 'id')->primary();
            $table->string('name');
        });
        Schema::create('uk_book_label', static function (Blueprint $table) use ($key): void {
            $key($table, 'book_id');
            $key($table, 'label_id');
        });

        Schema::create('uk_notes', static function (Blueprint $table): void {
            $table->integer('id')->primary();
            $table->string('subject_type')->nullable();
            $table->string('subject_id')->nullable();
        });

        $k = $this->storageKey(...);
        Writer::query()->create(['id' => $k(self::W1), 'name' => 'Ada']);
        Writer::query()->create(['id' => $k(self::W2), 'name' => 'Brontë']);
        Book::query()->create(['id' => $k(self::B1), 'title' => 'Dune', 'writer_id' => $k(self::W1)]);
        Book::query()->create(['id' => $k(self::B2), 'title' => 'Emma', 'writer_id' => $k(self::W1)]);
        Book::query()->create(['id' => $k(self::B3), 'title' => 'Ulysses', 'writer_id' => $k(self::W2)]);
        Label::query()->create(['id' => $k(self::L1), 'name' => 'classic']);
        Label::query()->create(['id' => $k(self::L2), 'name' => 'sci-fi']);
        Book::query()->findOrFail($k(self::B1))->labels()->attach([$k(self::L1), $k(self::L2)]);
        Book::query()->findOrFail($k(self::B2))->labels()->attach([$k(self::L1)]);
    }

    #[Test]
    public function aMorphIdentifiedByColumnsIsWrittenThroughAnUpdateAndItsRelationship(): void
    {
        $this->seedNotes();

        foreach ([self::L2, \strtoupper(self::L2)] as $sent) {
            $this->write('PATCH', '/api/notes/2', ['data' => ['type' => 'notes', 'id' => '2', 'relationships' => ['subject' => ['data' => ['type' => 'labels', 'id' => $sent]]]]])
                ->assertOk()
                ->assertJsonPath('data.relationships.subject.data', ['type' => 'labels', 'id' => self::L2]);
            $this->read('/api/notes/2/subject')->assertJsonPath('data.attributes.name', 'sci-fi');
            $this->write('PATCH', '/api/notes/2', ['data' => ['type' => 'notes', 'id' => '2', 'relationships' => ['subject' => ['data' => null]]]])->assertOk();
        }

        $this->write('PATCH', '/api/notes/2/relationships/subject', ['data' => ['type' => 'writers', 'id' => \strtoupper(self::W2)]])->assertSuccessful();
        $this->read('/api/notes/2')->assertJsonPath('data.relationships.subject.data', ['type' => 'writers', 'id' => self::W2]);
        $this->read('/api/notes/2/subject')->assertJsonPath('data.attributes.name', 'Brontë');
        self::assertSame(['2'], $this->ids($this->read('/api/notes?filter[subject][type]=writers&filter[subject][id]=' . self::W2)->json('data')));
        // The id column holds the writer's key as its row stores it, or its wire id behind an encoder.
        self::assertSame(['writers', self::W2], [DB::table('uk_notes')->where('id', 2)->value('subject_type'), DB::table('uk_notes')->where('id', 2)->value('subject_id')]);
    }

    #[Test]
    public function aMorphFilterMatchesUuidKeyedTargetsTheScopeAdmits(): void
    {
        $this->seedNotes();

        self::assertSame(['1', '4', '6'], $this->sorted($this->ids($this->read('/api/notes?filter[subject][type]=labels')->json('data'))));
        self::assertSame(['4', '6'], $this->sorted($this->ids($this->read('/api/notes?filter[subject][type]=labels&filter[subject][id]=' . self::L2)->json('data'))));
        self::assertSame(['3'], $this->ids($this->read('/api/notes?filter[subject][type]=writers')->json('data')));
        self::assertSame(['1'], $this->ids($this->read('/api/notes?filter[subject][type]=labels&filter[subject][id]=' . self::L1)->json('data')));
        self::assertSame([], $this->ids($this->read('/api/notes?filter[subject][type]=labels&filter[subject][id]=' . self::L3)->json('data')));
        self::assertSame(['3'], $this->ids($this->read('/api/notes?filter[subject][type]=writers&filter[subject][id]=' . self::W1)->json('data')));
    }

    #[Test]
    public function aScopedUuidKeyComparesInOneStatement(): void
    {
        $this->seedNotes();
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->read('/api/notes?filter[subject][type]=labels');
        DB::disableQueryLog();
        // Without identifier quotes, which differ between databases.
        $statements = \array_map(static fn(array $entry): string => \str_replace(['"', '`'], '', \strtolower($entry['query'])), DB::getQueryLog());

        self::assertSame([], \array_values(\array_filter($statements, static fn(string $sql): bool => \str_contains($sql, 'select distinct') && \str_contains($sql, 'uk_notes'))), "no stored ids are read first:\n" . \implode("\n", $statements));
        $compared = \array_filter($statements, static fn(string $sql): bool => \str_contains($sql, 'from uk_notes') && \str_contains($sql, 'from uk_labels'));
        self::assertCount(1, $compared, "one statement compares the keys in SQL:\n" . \implode("\n", $statements));
        // hex() on SQLite, BIN_TO_UUID() on MySQL 8 and encode() on PostgreSQL.
        $sql = (string) \reset($compared);
        self::assertSame($this->storage() === 'binary', \str_contains($sql, 'hex(') || \str_contains($sql, 'bin_to_uuid(') || \str_contains($sql, 'encode('), 'a binary key is read as its canonical text');
    }

    #[Test]
    public function aMorphTargetReadsAgreeWithTheFilter(): void
    {
        $this->seedNotes();

        $this->read('/api/notes/1')->assertJsonPath('data.relationships.subject.data', ['type' => 'labels', 'id' => self::L1]);
        $this->read('/api/notes/1/subject')->assertJsonPath('data.attributes.name', 'classic');
        $this->read('/api/notes/3/subject')->assertJsonPath('data.attributes.name', 'Ada');
        $this->read('/api/notes/2/subject')->assertJsonPath('data', null);
        $this->read('/api/notes/6')->assertJsonPath('data.relationships.subject.data', ['type' => 'labels', 'id' => self::L2]);
        $this->read('/api/notes/6/subject')->assertJsonPath('data.attributes.name', 'sci-fi');
        self::assertSame([self::L1, self::L2], $this->sorted($this->ids($this->read('/api/notes?include=subject&filter[subject][type]=labels')->json('included'))));
    }

    #[Test]
    public function fetchOneReadsTheRowAndRendersTheCanonicalId(): void
    {
        $book = $this->read('/api/books/' . self::B1);

        $book->assertJsonPath('data.id', self::B1);
        $book->assertJsonPath('data.attributes.title', 'Dune');
        $this->get('/api/books/' . self::W1, ['Accept' => self::MEDIA_TYPE])->assertNotFound();
    }

    #[Test]
    public function aCollectionRendersCanonicalIdsAndLinkage(): void
    {
        $books = $this->read('/api/books?include=writer,labels&sort=title');

        self::assertSame([self::B1, self::B2, self::B3], $this->ids($books->json('data')));
        $books->assertJsonPath('data.0.relationships.writer.data.id', self::W1);
        $books->assertJsonPath('data.2.relationships.writer.data.id', self::W2);
        self::assertSame([self::L1, self::L2], $this->sorted($this->ids($books->json('data.0.relationships.labels.data'))));
        self::assertSame([self::W1, self::W2, self::L1, self::L2], $this->sorted($this->ids($books->json('included'))));
    }

    #[Test]
    public function aToOneAndAToManyIncludeOnASingleResource(): void
    {
        $book = $this->read('/api/books/' . self::B1 . '?include=writer,labels');
        $book->assertJsonPath('data.relationships.writer.data.id', self::W1);
        self::assertSame([self::L1, self::L2], $this->sorted($this->ids($book->json('data.relationships.labels.data'))));

        $writer = $this->read('/api/writers/' . self::W1 . '?include=books');
        self::assertSame([self::B1, self::B2], $this->sorted($this->ids($writer->json('data.relationships.books.data'))));
        self::assertSame([self::B1, self::B2], $this->sorted($this->ids($writer->json('included'))));
    }

    #[Test]
    public function theRelatedAndRelationshipEndpointsResolve(): void
    {
        $this->read('/api/books/' . self::B1 . '/writer')->assertJsonPath('data.id', self::W1);
        $this->read('/api/books/' . self::B1 . '/relationships/writer')->assertJsonPath('data.id', self::W1);

        self::assertSame([self::B1, self::B2], $this->sorted($this->ids($this->read('/api/writers/' . self::W1 . '/books')->json('data'))));
        self::assertSame([self::B1, self::B2], $this->sorted($this->ids($this->read('/api/writers/' . self::W1 . '/relationships/books')->json('data'))));
        self::assertSame([self::L1, self::L2], $this->sorted($this->ids($this->read('/api/books/' . self::B1 . '/labels')->json('data'))));
    }

    #[Test]
    public function withCountCountsEachParentsRows(): void
    {
        $writers = $this->read('/api/writers?withCount=books&sort=name', self::COUNTABLE);
        $writers->assertJsonPath('data.0.relationships.books.meta.total', 2);
        $writers->assertJsonPath('data.1.relationships.books.meta.total', 1);

        $book = $this->read('/api/books/' . self::B1 . '?withCount=labels', self::COUNTABLE);
        $book->assertJsonPath('data.relationships.labels.meta.total', 2);

        $related = $this->read('/api/writers/' . self::W1 . '/books?page[size]=1&withCount=_self_', self::COUNTABLE);
        $related->assertJsonPath('meta.total', 2);
    }

    #[Test]
    public function withCountCountsTheTargetOfAToOneRelatedEndpoint(): void
    {
        $this->read('/api/books/' . self::B1 . '/writer?withCount=books', self::COUNTABLE)
            ->assertJsonPath('data.relationships.books.meta.total', 2);
        $this->read('/api/books/' . self::B3 . '/penName?withCount=books', self::COUNTABLE)
            ->assertJsonPath('data.id', self::W2)
            ->assertJsonPath('data.relationships.books.meta.total', 1);
    }

    #[Test]
    public function aRelatedQueryWindowsEachParent(): void
    {
        $writers = $this->read('/api/writers?sort=name&include=books&withCount=books&relatedQuery[books][filter][title]=m', self::QUERIES);

        self::assertSame([self::B2], $this->ids($writers->json('data.0.relationships.books.data')));
        $writers->assertJsonPath('data.0.relationships.books.meta.total', 1);
        self::assertSame([], $writers->json('data.1.relationships.books.data'));

        $books = $this->read('/api/books?sort=title&include=labels&relatedQuery[labels][filter][name]=sci', self::QUERIES);
        self::assertSame([self::L2], $this->ids($books->json('data.0.relationships.labels.data')));
    }

    #[Test]
    public function fetchManyReadsTheRowsAmongWireIds(): void
    {
        $provider = new EloquentDataProvider(['books' => Book::class]);

        $rows = $provider->fetchMany('books', [self::B1, self::B3, 'not-a-uuid', self::W1]);

        self::assertSame([self::B1, self::B3], $this->sorted(\array_map(fn(object $row): string => $this->wireId($row), $rows)));
    }

    #[Test]
    public function aMalformedIdNeverReachesTheDatabase(): void
    {
        $provider = new EloquentDataProvider(['books' => Book::class]);
        DB::flushQueryLog();
        DB::enableQueryLog();

        self::assertNull($provider->fetchOne('books', 'not-a-uuid'));
        self::assertSame([], $provider->fetchMany('books', ['not-a-uuid']));
        self::assertSame([], $provider->admitIds('books', ['not-a-uuid']));
        self::assertCount(1, $provider->fetchMany('books', [self::B1, 'not-a-uuid']));

        DB::disableQueryLog();
        $bindings = [];
        foreach (DB::getQueryLog() as $entry) {
            \array_push($bindings, ...$entry['bindings']);
        }
        self::assertNotContains('not-a-uuid', $bindings, 'a malformed id is a missing row, which PostgreSQL would refuse against a native uuid column');
        self::assertCount(1, DB::getQueryLog());
    }

    #[Test]
    public function aToOneLinkageWriteRepointsTheForeignKey(): void
    {
        $this->write('PATCH', '/api/books/' . self::B1 . '/relationships/writer', ['data' => ['type' => 'writers', 'id' => self::W2]])
            ->assertSuccessful();

        $this->read('/api/books/' . self::B1)->assertJsonPath('data.relationships.writer.data.id', self::W2);
        self::assertSame($this->storageKey(self::W2), Book::query()->findOrFail($this->storageKey(self::B1))->writer?->id);
    }

    #[Test]
    public function toManyLinkageWritesReplaceAddAndRemoveMembers(): void
    {
        $this->write('PATCH', '/api/books/' . self::B3 . '/relationships/labels', ['data' => [['type' => 'labels', 'id' => self::L2]]])->assertSuccessful();
        $this->write('POST', '/api/books/' . self::B3 . '/relationships/labels', ['data' => [['type' => 'labels', 'id' => self::L1]]])->assertSuccessful();
        self::assertSame([self::L1, self::L2], $this->sorted($this->ids($this->read('/api/books/' . self::B3 . '/relationships/labels')->json('data'))));

        $this->write('DELETE', '/api/books/' . self::B3 . '/relationships/labels', ['data' => [['type' => 'labels', 'id' => self::L2]]])->assertSuccessful();
        self::assertSame([self::L1], $this->ids($this->read('/api/books/' . self::B3 . '/relationships/labels')->json('data')));

        $this->write('PATCH', '/api/writers/' . self::W2 . '/relationships/books', ['data' => [['type' => 'books', 'id' => self::B2]]])->assertSuccessful();
        self::assertSame([self::B2], $this->ids($this->read('/api/writers/' . self::W2 . '/relationships/books')->json('data')));
    }

    #[Test]
    public function createAcceptsAClientIdAndLinkage(): void
    {
        $id = '3e9d4f5b-9dbc-4a88-8d9b-4c5a6f7e8d91';
        $created = $this->write('POST', '/api/books', ['data' => [
            'type' => 'books',
            'id' => $id,
            'attributes' => ['title' => 'Middlemarch'],
            'relationships' => [
                'writer' => ['data' => ['type' => 'writers', 'id' => self::W2]],
                'labels' => ['data' => [['type' => 'labels', 'id' => self::L1]]],
            ],
        ]])->assertCreated();

        $created->assertJsonPath('data.id', $id);
        $book = $this->read('/api/books/' . $id . '?include=labels');
        $book->assertJsonPath('data.relationships.writer.data.id', self::W2);
        self::assertSame([self::L1], $this->ids($book->json('data.relationships.labels.data')));
    }

    #[Test]
    public function createMintsAKeyWhenTheClientSendsNone(): void
    {
        $created = $this->write('POST', '/api/writers', ['data' => ['type' => 'writers', 'attributes' => ['name' => 'Cleo']]])->assertCreated();

        $id = $created->json('data.id');
        self::assertIsString($id);
        self::assertMatchesRegularExpression('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $id);
        $this->read('/api/writers/' . $id)->assertJsonPath('data.attributes.name', 'Cleo');
    }

    #[Test]
    public function updateWritesAttributesAndLinkage(): void
    {
        $updated = $this->write('PATCH', '/api/books/' . self::B2, ['data' => [
            'type' => 'books',
            'id' => self::B2,
            'attributes' => ['title' => 'Persuasion'],
            'relationships' => [
                'writer' => ['data' => ['type' => 'writers', 'id' => self::W2]],
                'labels' => ['data' => [['type' => 'labels', 'id' => self::L2]]],
            ],
        ]])->assertOk();

        $updated->assertJsonPath('data.id', self::B2);
        $book = $this->read('/api/books/' . self::B2 . '?include=writer,labels');
        $book->assertJsonPath('data.attributes.title', 'Persuasion');
        $book->assertJsonPath('data.relationships.writer.data.id', self::W2);
        self::assertSame([self::L2], $this->ids($book->json('data.relationships.labels.data')));
    }

    #[Test]
    public function anAtomicBatchWritesThroughTheSamePaths(): void
    {
        $body = (string) \json_encode(['atomic:operations' => [
            ['op' => 'add', 'data' => ['type' => 'writers', 'lid' => 'w', 'attributes' => ['name' => 'Dora']]],
            ['op' => 'update', 'ref' => ['type' => 'books', 'id' => self::B3], 'data' => ['type' => 'books', 'id' => self::B3, 'relationships' => ['writer' => ['data' => ['type' => 'writers', 'lid' => 'w']]]]],
            ['op' => 'add', 'ref' => ['type' => 'books', 'id' => self::B3, 'relationship' => 'labels'], 'data' => [['type' => 'labels', 'id' => self::L2]]],
        ]]);
        $atomic = 'application/vnd.api+json; ext="https://jsonapi.org/ext/atomic"';

        $response = $this->call('POST', '/api/operations', [], [], [], ['HTTP_ACCEPT' => $atomic, 'CONTENT_TYPE' => $atomic], $body);

        $response->assertOk();
        $writer = $response->json('atomic:results.0.data.id');
        self::assertIsString($writer);
        $book = $this->read('/api/books/' . self::B3 . '?include=labels');
        $book->assertJsonPath('data.relationships.writer.data.id', $writer);
        self::assertSame([self::L2], $this->ids($book->json('data.relationships.labels.data')));
    }

    #[Test]
    public function deleteRemovesTheRow(): void
    {
        $this->delete('/api/books/' . self::B3, [], ['Accept' => self::MEDIA_TYPE])->assertNoContent();
        $this->get('/api/books/' . self::B3, ['Accept' => self::MEDIA_TYPE])->assertNotFound();
    }

    /**
     * The value the key column stores for a canonical UUID.
     */
    /**
     * Notes 1 and 4 are about visible labels, 2 about a label the scope hides, 3 about a
     * writer, each id stored as its canonical string. Note 6 is about label L2 stored as
     * its storage key, 16 bytes when keys are binary and the database is SQLite.
     */
    private function seedNotes(): void
    {
        Label::query()->create(['id' => $this->storageKey(self::L3), 'name' => 'hidden']);
        Note::query()->create(['id' => 1, 'subject_type' => 'labels', 'subject_id' => self::L1]);
        Note::query()->create(['id' => 2, 'subject_type' => 'labels', 'subject_id' => self::L3]);
        Note::query()->create(['id' => 3, 'subject_type' => 'writers', 'subject_id' => self::W1]);
        Note::query()->create(['id' => 4, 'subject_type' => 'labels', 'subject_id' => self::L2]);
        // Only SQLite keeps raw bytes in a text column.
        $sqlite = DB::connection()->getDriverName() === 'sqlite';
        Note::query()->create(['id' => 6, 'subject_type' => 'labels', 'subject_id' => $sqlite ? $this->storageKey(self::L2) : self::L2]);
    }

    protected function storageKey(string $uuid): string
    {
        return $this->storage() === 'binary' ? \Ramsey\Uuid\Uuid::fromString($uuid)->getBytes() : $uuid;
    }

    private function wireId(object $row): string
    {
        self::assertInstanceOf(Book::class, $row);
        $key = $row->getKey();
        self::assertIsString($key);

        return $this->storage() === 'binary' ? \Ramsey\Uuid\Uuid::fromBytes($key)->toString() : $key;
    }

    /**
     * @return TestResponse<\Symfony\Component\HttpFoundation\Response>
     */
    private function read(string $uri, string $accept = self::MEDIA_TYPE): TestResponse
    {
        $response = $this->get($uri, ['Accept' => $accept]);
        $response->assertOk();

        return $response;
    }

    /**
     * @param array<string, mixed> $body
     *
     * @return TestResponse<\Symfony\Component\HttpFoundation\Response>
     */
    private function write(string $method, string $uri, array $body): TestResponse
    {
        return $this->json($method, $uri, $body, ['Accept' => self::MEDIA_TYPE, 'Content-Type' => self::MEDIA_TYPE]);
    }

    /**
     * @return list<string>
     */
    private function ids(mixed $data): array
    {
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
     * @param list<string> $ids
     *
     * @return list<string>
     */
    private function sorted(array $ids): array
    {
        \sort($ids);

        return $ids;
    }
}
