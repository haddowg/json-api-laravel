<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Conformance;

use haddowg\JsonApiLaravel\DataProvider\Eloquent\EloquentFilterHandler;
use haddowg\JsonApiLaravel\Tests\Fixtures\MorphKeys\MorphKeysServiceProvider;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;

/**
 * {@see MorphKeysConformanceTestCase} against the reference Eloquent provider over in-memory
 * SQLite, where counters and badges carry a global scope.
 *
 * @internal
 */
#[CoversNothing]
final class EloquentMorphKeysConformanceTest extends MorphKeysConformanceTestCase
{
    #[Test]
    #[Group('spec:fetching-filtering')]
    public function aTextColumnAgainstAnIntegerKeyAdmitsTheStoredIdsInsteadOfASubquery(): void
    {
        $statements = $this->statementsOf('/api/mkNotes?filter[subject][type]=mkCounters');

        self::assertCount(1, $this->containing($statements, 'select distinct', 'mk_notes'), "the distinct stored ids are read once:\n" . \implode("\n", $statements));
        self::assertSame([], $this->containing($statements, 'from mk_notes', 'from mk_counters'), "no statement compares the text column with the integer keys in SQL:\n" . \implode("\n", $statements));
    }

    #[Test]
    #[Group('spec:fetching-filtering')]
    public function aTextColumnAgainstAStringKeyKeepsTheSubquery(): void
    {
        $statements = $this->statementsOf('/api/mkNotes?filter[subject][type]=mkCodes');

        self::assertSame([], $this->containing($statements, 'select distinct', 'mk_notes'), "no stored ids are read first:\n" . \implode("\n", $statements));
        self::assertCount(1, $this->containing($statements, 'from mk_notes', 'from mk_codes'), "one statement compares the keys in SQL:\n" . \implode("\n", $statements));
    }

    #[Test]
    #[Group('spec:fetching-filtering')]
    public function aLongListOfAdmittedIdsBindsInChunks(): void
    {
        EloquentFilterHandler::chunkMorphIdsBy(1);
        try {
            $statements = $this->statementsOf('/api/mkNotes?filter[subject][type]=mkBadges');
            $ids = [];
            foreach ((array) $this->request('/api/mkNotes?filter[subject][type]=mkBadges')->json('data') as $row) {
                self::assertIsArray($row);
                $ids[] = $row['id'] ?? null;
            }
        } finally {
            EloquentFilterHandler::chunkMorphIdsBy(2000);
        }

        \sort($ids);
        self::assertSame(['3', '4'], $ids);
        self::assertNotSame([], $this->containing($statements, 'from mk_notes', ' in (?) or '), "the admitted ids bind as several lists:\n" . \implode("\n", $statements));
    }

    /**
     * The lower-cased SQL each statement of a request ran.
     *
     * @return list<string>
     */
    private function statementsOf(string $path): array
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->request($path)->assertOk();
        DB::disableQueryLog();

        // Without identifier quotes, which differ between databases.
        return \array_map(static fn(array $entry): string => \str_replace(['"', '`'], '', \strtolower($entry['query'])), DB::getQueryLog());
    }

    /**
     * @param list<string> $statements
     *
     * @return list<string>
     */
    private function containing(array $statements, string ...$needles): array
    {
        return \array_values(\array_filter($statements, static function (string $sql) use ($needles): bool {
            foreach ($needles as $needle) {
                if (!\str_contains($sql, $needle)) {
                    return false;
                }
            }

            return true;
        }));
    }

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
        $config->set('database.default', 'testing');
        $config->set('database.connections.testing', ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
    }

    protected function defineDatabaseMigrations(): void
    {
        Schema::create('mk_notes', static function (Blueprint $table): void {
            $table->increments('id');
            $table->string('subject_type')->nullable();
            $table->string('subject_id')->nullable();
        });
        Schema::create('mk_pins', static function (Blueprint $table): void {
            $table->increments('id');
            $table->unsignedInteger('note_id');
            $table->string('pin_type');
            $table->string('pin_id');
        });
        foreach (MorphKeysServiceProvider::targets() as $target) {
            Schema::create($target['table'], static function (Blueprint $table): void {
                $table->increments('id');
                $table->string('name');
                $table->boolean('hidden')->default(false);
            });
            foreach ($target['rows'] as [$id, $name, $hidden]) {
                DB::table($target['table'])->insert(['id' => $id, 'name' => $name, 'hidden' => $hidden]);
            }
        }

        Schema::create('mk_codes', static function (Blueprint $table): void {
            $table->string('id')->primary();
            $table->string('name');
            $table->boolean('hidden')->default(false);
        });
        foreach (MorphKeysServiceProvider::codes() as [$id, $name, $hidden]) {
            DB::table('mk_codes')->insert(['id' => $id, 'name' => $name, 'hidden' => $hidden]);
        }

        foreach (MorphKeysServiceProvider::notes() as $row) {
            DB::table('mk_notes')->insert(['id' => $row['id'], 'subject_type' => $row['subject_type'], 'subject_id' => $row['subject_id']]);
            foreach ($row['pins'] as [$type, $id]) {
                DB::table('mk_pins')->insert(['note_id' => $row['id'], 'pin_type' => $type, 'pin_id' => $id]);
            }
        }
    }
}
