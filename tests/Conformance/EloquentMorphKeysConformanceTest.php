<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Conformance;

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
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->request('/api/mkNotes?filter[subject][type]=mkCounters')->assertOk();
        DB::disableQueryLog();

        $statements = \array_map(static fn(array $entry): string => \strtolower($entry['query']), DB::getQueryLog());
        $distinct = \array_filter($statements, static fn(string $sql): bool => \str_starts_with($sql, 'select distinct') && \str_contains($sql, '"mk_notes"'));
        self::assertCount(1, $distinct, "the distinct stored ids are read once:\n" . \implode("\n", $statements));
        $compared = \array_filter($statements, static fn(string $sql): bool => \str_contains($sql, 'from "mk_notes"') && \str_contains($sql, 'from "mk_counters"'));
        self::assertSame([], $compared, "no statement compares the text column with the integer keys in SQL:\n" . \implode("\n", $statements));
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

        foreach (MorphKeysServiceProvider::notes() as $row) {
            DB::table('mk_notes')->insert(['id' => $row['id'], 'subject_type' => $row['subject_type'], 'subject_id' => $row['subject_id']]);
            foreach ($row['pins'] as [$type, $id]) {
                DB::table('mk_pins')->insert(['note_id' => $row['id'], 'pin_type' => $type, 'pin_id' => $id]);
            }
        }
    }
}
