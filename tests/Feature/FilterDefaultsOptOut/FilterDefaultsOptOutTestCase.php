<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Feature\FilterDefaultsOptOut;

use haddowg\JsonApiLaravel\JsonApiServiceProvider;
use haddowg\JsonApiLaravel\Tests\Fixtures\FilterDefaultsOptOut\FilterDefaultsOptOutServiceProvider;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Testing\TestResponse;
use Orchestra\Testbench\TestCase as Orchestra;
use PHPUnit\Framework\Attributes\Test;

/**
 * A relation declaring withoutFilterDefaults() stops the books type's defaults narrowing
 * every read of it, while `books` keeps them and `/books` keeps them. Shelf 1 holds book 1,
 * archived book 2 and draft book 3.
 */
abstract class FilterDefaultsOptOutTestCase extends Orchestra
{
    protected const string MEDIA_TYPE = 'application/vnd.api+json';

    private const string COUNTABLE_PROFILE = 'https://haddowg.github.io/json-api/profiles/countable/';

    private const string RELATIONSHIP_QUERIES_PROFILE = 'https://haddowg.github.io/json-api/profiles/relationship-queries/';

    /**
     * Each relation of shelf 1 and the books it lists.
     */
    private const array MEMBERS = [
        'books' => ['1'],
        'allBooks' => ['1', '2', '3'],
        'withArchived' => ['1', '2'],
    ];

    abstract protected function store(): string;

    protected function getPackageProviders($app): array
    {
        $app['config']->set('filter_defaults_opt_out.store', $this->store());

        return [JsonApiServiceProvider::class, FilterDefaultsOptOutServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
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

        Schema::create('fd_shelves', static function (Blueprint $table): void {
            $table->increments('id');
            $table->string('name');
        });
        Schema::create('fd_books', static function (Blueprint $table): void {
            $table->increments('id');
            $table->unsignedInteger('shelf_id')->nullable();
            $table->string('title');
            $table->boolean('archived')->default(false);
            $table->boolean('draft')->default(false);
        });
    }

    protected function setUp(): void
    {
        parent::setUp();

        if ($this->store() !== 'eloquent') {
            return;
        }

        DB::table('fd_shelves')->insert([['id' => 1, 'name' => 'Fiction'], ['id' => 2, 'name' => 'Poetry']]);
        DB::table('fd_books')->insert([
            ['id' => 1, 'shelf_id' => 1, 'title' => 'Plain', 'archived' => false, 'draft' => false],
            ['id' => 2, 'shelf_id' => 1, 'title' => 'Archived', 'archived' => true, 'draft' => false],
            ['id' => 3, 'shelf_id' => 1, 'title' => 'Draft', 'archived' => false, 'draft' => true],
            ['id' => 4, 'shelf_id' => 2, 'title' => 'Old', 'archived' => true, 'draft' => false],
            ['id' => 5, 'shelf_id' => 2, 'title' => 'New', 'archived' => false, 'draft' => false],
        ]);
    }

    #[Test]
    public function alwaysEmittedLinkageDropsTheDefaults(): void
    {
        $response = $this->read('/shelves/1');

        self::assertSame(['1', '2', '3'], $this->ids($response->json('data.relationships.listedBooks.data')));
    }

    #[Test]
    public function anIncludeDropsTheDefaultsItsRelationNames(): void
    {
        foreach (self::MEMBERS as $name => $ids) {
            $response = $this->read('/shelves/1?include=' . $name);

            self::assertSame($ids, $this->ids($response->json('data.relationships.' . $name . '.data')), $name);
            self::assertSame($ids, $this->ids($response->json('included')), $name);
        }
    }

    #[Test]
    public function anIncludeAcrossAPageDropsTheDefaultsPerParent(): void
    {
        $response = $this->read('/shelves?include=allBooks,withArchived,books');

        $linkage = [];
        foreach ((array) $response->json('data') as $shelf) {
            self::assertIsArray($shelf);
            self::assertIsString($shelf['id'] ?? null);
            foreach (\array_keys(self::MEMBERS) as $name) {
                $linkage[$shelf['id']][$name] = $this->ids(data_get($shelf, 'relationships.' . $name . '.data'));
            }
        }

        self::assertSame([
            '1' => self::MEMBERS,
            '2' => ['books' => ['5'], 'allBooks' => ['4', '5'], 'withArchived' => ['4', '5']],
        ], $linkage);
    }

    #[Test]
    public function theCountDropsTheDefaults(): void
    {
        $response = $this->read('/shelves/1?withCount=books,allBooks,withArchived', self::MEDIA_TYPE . ';profile="' . self::COUNTABLE_PROFILE . '"');

        foreach (self::MEMBERS as $name => $ids) {
            self::assertSame(\count($ids), $response->json('data.relationships.' . $name . '.meta.total'), $name);
        }
    }

    #[Test]
    public function theRelatedAndRelationshipEndpointsDropTheDefaults(): void
    {
        foreach (self::MEMBERS as $name => $ids) {
            self::assertSame($ids, $this->ids($this->read('/shelves/1/' . $name)->json('data')), $name . ' related');
            self::assertSame($ids, $this->ids($this->read('/shelves/1/relationships/' . $name)->json('data')), $name . ' relationship');
        }
    }

    #[Test]
    public function aRelatedQueryWindowDropsTheDefaults(): void
    {
        $accept = self::MEDIA_TYPE . ';profile="' . self::RELATIONSHIP_QUERIES_PROFILE . '"';

        foreach (self::MEMBERS as $name => $ids) {
            $response = $this->read('/shelves/1?include=' . $name . '&relatedQuery[' . $name . '][sort]=-id', $accept);

            self::assertSame(\array_reverse($ids), $this->ids($response->json('data.relationships.' . $name . '.data')), $name);
        }

        $response = $this->read('/shelves/1?include=allBooks&relatedQuery[allBooks][filter][title]=Draft', $accept);
        self::assertSame(['3'], $this->ids($response->json('data.relationships.allBooks.data')));
    }

    #[Test]
    public function aFilterTheClientSendsStillApplies(): void
    {
        self::assertSame(['2'], $this->ids($this->read('/shelves/1/allBooks?filter[archived]=true')->json('data')));
        self::assertSame(['1', '3'], $this->ids($this->read('/shelves/1/relationships/allBooks?filter[archived]=false')->json('data')));
        self::assertSame(['2'], $this->ids($this->read('/shelves/1/withArchived?filter[archived]=true')->json('data')));

        // `withArchived` keeps the draft default unless the client sends the key.
        self::assertSame(['3'], $this->ids($this->read('/shelves/1/withArchived?filter[draft]=true')->json('data')));
    }

    #[Test]
    public function thePrimaryCollectionKeepsItsDefaults(): void
    {
        self::assertSame(['1', '5'], $this->ids($this->read('/books')->json('data')));
    }

    /**
     * @return TestResponse<\Symfony\Component\HttpFoundation\Response>
     */
    protected function read(string $path, string $accept = self::MEDIA_TYPE): TestResponse
    {
        $response = $this->get('/api' . $path, ['Accept' => $accept]);
        $response->assertOk();

        return $response;
    }

    /**
     * The ids of `$identifiers`, in document order.
     *
     * @return list<string>
     */
    protected function ids(mixed $identifiers): array
    {
        self::assertIsArray($identifiers);

        $ids = [];
        foreach ($identifiers as $identifier) {
            self::assertIsArray($identifier);
            self::assertIsString($identifier['id'] ?? null);
            $ids[] = $identifier['id'];
        }

        return $ids;
    }
}
