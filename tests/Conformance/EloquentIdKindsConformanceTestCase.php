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

    protected function defineDatabaseMigrations(): void
    {
        // Laravel binds a string as text, which a bytea column refuses, and PDO reads bytea back
        // as a stream, so Eloquent can't hold a binary key on PostgreSQL at all.
        if (IdKinds::kind() === 'binary' && \getenv('JSONAPI_TEST_DB') === 'pgsql') {
            self::markTestSkipped('Eloquent can neither bind nor read a 16-byte key in a PostgreSQL bytea column.');
        }
        EloquentFilterHandler::forgetColumnTypes();
        foreach (['ik_pins', 'ik_book_label', 'ik_books', 'ik_labels', 'ik_writers'] as $table) {
            Schema::dropIfExists($table);
        }
        $this->beforeApplicationDestroyed(static function (): void {
            foreach (['ik_pins', 'ik_book_label', 'ik_books', 'ik_labels', 'ik_writers'] as $table) {
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
