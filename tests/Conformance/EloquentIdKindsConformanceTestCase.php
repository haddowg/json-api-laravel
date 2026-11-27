<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Conformance;

use haddowg\JsonApiLaravel\DataProvider\Eloquent\EloquentFilterHandler;
use haddowg\JsonApiLaravel\Tests\Fixtures\IdKinds\IdKinds;
use haddowg\JsonApiLaravel\Tests\Fixtures\IdKinds\IdKindsServiceProvider;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\ColumnDefinition;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;

/**
 * The id-kinds matrix over the reference Eloquent provider, on SQLite unless
 * `JSONAPI_TEST_DB` names `mysql` or `pgsql`, reached through `JSONAPI_TEST_DB_PORT`. A binary
 * key is a fixed 16-byte column, and the native `morphTo` id column holds the storage key
 * `associate()` writes, so every comparison sees the bytes.
 */
abstract class EloquentIdKindsConformanceTestCase extends IdKindsConformanceTestCase
{
    protected function store(): string
    {
        return 'eloquent';
    }

    /**
     * @param \Illuminate\Foundation\Application $app
     */
    protected function defineEnvironment($app): void
    {
        /** @var \Illuminate\Contracts\Config\Repository $config */
        $config = $app['config'];
        $driver = \getenv('JSONAPI_TEST_DB');
        $config->set('database.default', 'testing');
        $config->set('database.connections.testing', match ($driver) {
            'mysql', 'pgsql' => [
                'driver' => $driver,
                'host' => '127.0.0.1',
                'port' => (int) \getenv('JSONAPI_TEST_DB_PORT'),
                'database' => 'jsonapi',
                'username' => $driver === 'mysql' ? 'root' : 'postgres',
                'password' => 'secret',
                'charset' => $driver === 'mysql' ? 'utf8mb4' : 'utf8',
                'prefix' => '',
            ],
            default => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => ''],
        });
    }

    /**
     * A MorphMany's included first pages are one ROW_NUMBER query partitioned by the id column,
     * in lower case when a parent's forms differ in case only, and one query per parent when an
     * id encoder makes them differ beyond case.
     */
    #[Test]
    public function aMorphManyIncludeIsOneWindowedQueryUnlessAnEncoderSplitsItsForms(): void
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->get('/api/ikWriters?sort=name&include=originated&relatedQuery[originated][sort]=-title', ['Accept' => 'application/vnd.api+json; profile="https://haddowg.github.io/json-api/profiles/relationship-queries/"'])->assertOk();
        DB::disableQueryLog();

        // Without identifier quotes, which differ between databases.
        $statements = \array_values(\array_filter(
            \array_map(static fn(array $entry): string => \str_replace(['"', '`'], '', \strtolower($entry['query'])), DB::getQueryLog()),
            static fn(string $sql): bool => \str_contains($sql, 'from ik_books') && \str_contains($sql, 'origin_type'),
        ));
        $windowed = \array_values(\array_filter($statements, static fn(string $sql): bool => \str_contains($sql, 'row_number()')));

        if (\in_array(IdKinds::kind(), ['prefix', 'binary'], true)) {
            self::assertSame([], $windowed, "an encoder's forms read each parent on its own:\n" . \implode("\n", $statements));
            self::assertCount(3, $statements, \implode("\n", $statements));

            return;
        }

        self::assertCount(1, $statements, \implode("\n", $statements));
        self::assertCount(1, $windowed, \implode("\n", $statements));
        self::assertSame(\in_array(IdKinds::kind(), ['uuid', 'object', 'upper'], true), \str_contains($windowed[0], 'partition by lower(ik_books.origin_id)'), $windowed[0]);
    }

    /**
     * A linkage write stores each foreign key as the row it names stores its key, whatever
     * case the request spelled a uuid in.
     */
    #[Test]
    public function aLinkageWriteStoresTheKeyAsTheRelatedRowStoresIt(): void
    {
        $headers = ['Accept' => 'application/vnd.api+json', 'Content-Type' => 'application/vnd.api+json'];
        $book = '/api/ikBooks/' . IdKinds::sent('b3');
        $this->json('PATCH', $book . '/relationships/writer', ['data' => ['type' => 'ikWriters', 'id' => IdKinds::sent('w3')]], $headers)->assertSuccessful();
        $this->json('PATCH', $book . '/relationships/subject', ['data' => ['type' => 'ikLabels', 'id' => IdKinds::sent('l2')]], $headers)->assertSuccessful();
        $this->json('POST', $book . '/relationships/labels', ['data' => [['type' => 'ikLabels', 'id' => IdKinds::sent('l1')]]], $headers)->assertSuccessful();
        $this->json('POST', '/api/ikWriters/' . IdKinds::sent('w3') . '/relationships/authored', ['data' => [['type' => 'ikBooks', 'id' => IdKinds::sent('b1')]]], $headers)->assertSuccessful();
        $this->json('POST', '/api/ikBooks', ['data' => [
            'type' => 'ikBooks',
            'id' => IdKinds::sent('b4'),
            'attributes' => ['title' => 'Middlemarch'],
            'relationships' => [
                'writer' => ['data' => ['type' => 'ikWriters', 'id' => IdKinds::sent('w2')]],
                'labels' => ['data' => [['type' => 'ikLabels', 'id' => IdKinds::sent('l2')]]],
            ],
        ]], $headers)->assertCreated()->assertJsonPath('data.id', IdKinds::wire('b4'));

        $stored = static fn(string $ref): string => (string) IdKinds::stored($ref);
        $row = static fn(string $ref): ?object => DB::table('ik_books')->where('id', IdKinds::stored($ref))->first();
        self::assertSame($stored('w3'), (string) ($row('b3')->writer_id ?? ''));
        self::assertSame($stored('l2'), (string) ($row('b3')->subject_id ?? ''));
        self::assertSame($stored('w3'), (string) ($row('b1')->writer_id ?? ''));
        self::assertSame([$stored('l1')], DB::table('ik_book_label')->where('book_id', IdKinds::stored('b3'))->pluck('label_id')->map(static fn(mixed $key): string => \is_scalar($key) ? (string) $key : '')->all());
        $created = DB::table('ik_books')->where('title', 'Middlemarch')->first();
        self::assertNotNull($created);
        self::assertSame($stored('w2'), (string) ($created->writer_id ?? ''));
        self::assertSame([$stored('l2')], DB::table('ik_book_label')->where('book_id', $created->id ?? null)->pluck('label_id')->map(static fn(mixed $key): string => \is_scalar($key) ? (string) $key : '')->all());
    }

    /**
     * Each side of a seat renders and filters by the rank of the parent's own seat, and a
     * path through the seat reaches the side it names.
     */
    #[Test]
    public function aSelfReferencingPivotRendersAndFiltersItsOwnSeats(): void
    {
        $accept = ['Accept' => 'application/vnd.api+json'];
        $peers = '/api/ikWriters/' . IdKinds::wire('w2') . '/peers';
        $followers = '/api/ikWriters/' . IdKinds::wire('w2') . '/followers';

        $this->get('/api/ikWriters/' . IdKinds::wire('w2') . '/relationships/peers', $accept)->assertOk()
            ->assertJsonPath('data.0.id', IdKinds::wire('w3'))->assertJsonPath('data.0.meta.pivot.rank', 2);
        $this->get('/api/ikWriters/' . IdKinds::wire('w2') . '/relationships/followers', $accept)->assertOk()
            ->assertJsonPath('data.0.id', IdKinds::wire('w1'))->assertJsonPath('data.0.meta.pivot.rank', 1);

        $this->get($peers . '?filter[rank]=2', $accept)->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', IdKinds::wire('w3'));
        $this->get($peers . '?filter[rank]=1', $accept)->assertOk()->assertJsonCount(0, 'data');
        $this->get($followers . '?filter[rank]=1', $accept)->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', IdKinds::wire('w1'));

        $this->get($peers . '?filter[seatedBy]=' . IdKinds::wire('w2'), $accept)->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', IdKinds::wire('w3'));
        $this->get($peers . '?filter[seatedBy]=' . IdKinds::wire('w3'), $accept)->assertOk()->assertJsonCount(0, 'data');
        $this->get($peers . '?filter[seating]=' . IdKinds::wire('w3'), $accept)->assertOk()->assertJsonCount(1, 'data');
        $this->get($followers . '?filter[seatedBy]=' . IdKinds::wire('w2'), $accept)->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', IdKinds::wire('w1'));
        $this->get($followers . '?filter[seating]=' . IdKinds::wire('w2'), $accept)->assertOk()->assertJsonCount(0, 'data');

        $this->json('POST', '/api/ikWriters/' . IdKinds::wire('w3') . '/relationships/peers', ['data' => [['type' => 'ikWriters', 'id' => IdKinds::wire('w1'), 'meta' => ['pivot' => ['rank' => 7]]]]], ['Accept' => 'application/vnd.api+json', 'Content-Type' => 'application/vnd.api+json'])->assertSuccessful();
        $this->json('PATCH', '/api/ikWriters/' . IdKinds::wire('w2') . '/relationships/peers', ['data' => [['type' => 'ikWriters', 'id' => IdKinds::wire('w3'), 'meta' => ['pivot' => ['rank' => 5]]]]], ['Accept' => 'application/vnd.api+json', 'Content-Type' => 'application/vnd.api+json'])->assertSuccessful();

        $seats = DB::table('ik_writer_seats')->orderBy('rank')->get()->map(static fn(object $row): array => [(array) $row])->all();
        $stored = \array_map(static fn(array $row): array => [$row[0]['owner_id'], $row[0]['peer_id'], $row[0]['rank']], $seats);
        self::assertSame([
            [IdKinds::stored('w1'), IdKinds::stored('w2'), 1],
            [IdKinds::stored('w2'), IdKinds::stored('w3'), 5],
            [IdKinds::stored('w3'), IdKinds::stored('w1'), 7],
        ], $stored);
    }

    protected function defineDatabaseMigrations(): void
    {
        // Laravel binds a string as text, which a bytea column refuses, and PDO reads bytea back
        // as a stream, so Eloquent can't hold a binary key on PostgreSQL at all.
        if (IdKinds::kind() === 'binary' && \getenv('JSONAPI_TEST_DB') === 'pgsql') {
            self::markTestSkipped('Eloquent can neither bind nor read a 16-byte key in a PostgreSQL bytea column.');
        }
        EloquentFilterHandler::forgetColumnTypes();
        foreach (['ik_writer_seats', 'ik_pins', 'ik_book_label', 'ik_books', 'ik_labels', 'ik_writers'] as $table) {
            Schema::dropIfExists($table);
        }
        $this->beforeApplicationDestroyed(static function (): void {
            foreach (['ik_writer_seats', 'ik_pins', 'ik_book_label', 'ik_books', 'ik_labels', 'ik_writers'] as $table) {
                Schema::dropIfExists($table);
            }
        });

        $key = static function (Blueprint $table, string $column): ColumnDefinition {
            return match (IdKinds::kind()) {
                'int', 'prefix' => $table->unsignedBigInteger($column),
                'uuid' => $table->uuid($column),
                'ulid' => $table->ulid($column),
                'binary' => $table->binary($column, 16, fixed: true),
                default => $table->string($column, 64),
            };
        };
        $primary = static function (Blueprint $table) use ($key): void {
            IdKinds::integerKeys() ? $table->bigIncrements('id') : $key($table, 'id')->primary();
        };

        Schema::create('ik_writers', static function (Blueprint $table) use ($primary): void {
            $primary($table);
            $table->string('name');
        });
        Schema::create('ik_labels', static function (Blueprint $table) use ($primary): void {
            $primary($table);
            $table->string('name');
        });
        Schema::create('ik_books', static function (Blueprint $table) use ($primary, $key): void {
            $primary($table);
            $table->string('title');
            $key($table, 'writer_id')->nullable();
            $key($table, 'writer_ref')->nullable();
            $key($table, 'featured_by')->nullable();
            $table->string('subject_type')->nullable();
            $key($table, 'subject_id')->nullable();
            $table->string('origin_type')->nullable();
            $table->string('origin_id')->nullable();
        });
        Schema::create('ik_book_label', static function (Blueprint $table) use ($key): void {
            $key($table, 'book_id');
            $key($table, 'label_id');
            $table->integer('weight')->default(0);
        });
        Schema::create('ik_writer_seats', static function (Blueprint $table) use ($key): void {
            $key($table, 'owner_id');
            $key($table, 'peer_id');
            $table->integer('rank')->nullable();
        });
        Schema::create('ik_pins', static function (Blueprint $table) use ($key): void {
            $table->increments('id');
            $key($table, 'book_id');
            $table->string('pin_type');
            $table->string('pin_id');
        });

        $seed = IdKindsServiceProvider::seed();
        foreach ($seed['writers'] as [$ref, $name]) {
            DB::table('ik_writers')->insert(['id' => IdKinds::stored($ref), 'name' => $name]);
        }
        foreach ($seed['seats'] as [$owner, $peer, $rank]) {
            DB::table('ik_writer_seats')->insert(['owner_id' => IdKinds::stored($owner), 'peer_id' => IdKinds::stored($peer), 'rank' => $rank]);
        }
        foreach ($seed['labels'] as [$ref, $name]) {
            DB::table('ik_labels')->insert(['id' => IdKinds::stored($ref), 'name' => $name]);
        }
        foreach ($seed['books'] as $row) {
            DB::table('ik_books')->insert([
                'id' => IdKinds::stored($row['id']),
                'title' => $row['title'],
                'writer_id' => $row['writer'] !== null ? IdKinds::stored($row['writer']) : null,
                'writer_ref' => $row['writer'] !== null ? IdKinds::stored($row['writer']) : null,
                'featured_by' => $row['featuredBy'] !== null ? IdKinds::stored($row['featuredBy']) : null,
                'subject_type' => $row['subject'][0] ?? null,
                'subject_id' => $row['subject'] !== null ? IdKinds::stored($row['subject'][1]) : null,
                'origin_type' => $row['origin'][0] ?? null,
                'origin_id' => $row['origin'] !== null ? $this->storedReference($row['id'], IdKinds::wire($row['origin'][1])) : null,
            ]);
            foreach ($row['labels'] as $label) {
                DB::table('ik_book_label')->insert(['book_id' => IdKinds::stored($row['id']), 'label_id' => IdKinds::stored($label)]);
            }
            foreach ($row['pins'] as [$type, $ref]) {
                DB::table('ik_pins')->insert(['book_id' => IdKinds::stored($row['id']), 'pin_type' => $type, 'pin_id' => $this->storedReference($row['id'], IdKinds::wire($ref))]);
            }
        }

        // The seeded integer keys leave PostgreSQL's sequences behind them.
        if (IdKinds::integerKeys() && DB::connection()->getDriverName() === 'pgsql') {
            foreach (['ik_writers', 'ik_books', 'ik_labels'] as $table) {
                DB::statement(\sprintf("SELECT setval(pg_get_serial_sequence('%1\$s', 'id'), (SELECT MAX(id) FROM %1\$s))", $table));
            }
        }
    }

    /**
     * A wire id as a text column holding references keeps it: the references of book `b2` and
     * `b1` hold a UUID in capitals, which must still name the lowercase row.
     */
    private function storedReference(string $book, string $wire): string
    {
        return \in_array($book, ['b1', 'b2'], true) && \preg_match('/^[0-9a-f]{8}-/', $wire) === 1 ? \strtoupper($wire) : $wire;
    }
}
