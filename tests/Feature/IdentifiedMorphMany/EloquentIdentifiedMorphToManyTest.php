<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Feature\IdentifiedMorphMany;

use haddowg\JsonApiLaravel\Tests\Fixtures\IdentifiedMorphMany\IdentifiedMorphManyServiceProvider;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;

/**
 * @internal
 */
class EloquentIdentifiedMorphToManyTest extends IdentifiedMorphToManyTestCase
{
    protected function store(): string
    {
        return 'eloquent';
    }

    protected function hides(): bool
    {
        return false;
    }

    protected function storedCompanies(int $user): array
    {
        return \array_values(\array_map(
            static fn(object $row): string => (\is_string($row->company_type ?? null) ? $row->company_type : '') . ':' . (\is_scalar($row->company_id ?? null) ? (string) $row->company_id : ''),
            DB::table('mtm_user_companies')->where('mtm_user_id', $user)->orderBy('id')->get()->all(),
        ));
    }

    protected function storeCompany(int $user, string $type, int $id): void
    {
        DB::table('mtm_user_companies')->insert(['mtm_user_id' => $user, 'company_type' => $type, 'company_id' => $id]);
    }

    #[Test]
    public function theLinkageAdmitsEachScopedTypeInOneKeyOnlyQueryAndLoadsNothingElse(): void
    {
        $queries = $this->queries(fn() => $this->read('/users'));

        self::assertSame([], $this->reads($queries, 'mtm_advertisers'), \implode("\n", $queries));
        self::assertSame([], $this->reads($queries, 'mtm_publishers'), \implode("\n", $queries));
        $agencies = $this->reads($queries, 'mtm_agencies');
        self::assertCount($this->hides() ? 1 : 0, $agencies, \implode("\n", $queries));
        foreach ($agencies as $sql) {
            self::assertStringNotContainsString('*', $sql);
        }
    }

    #[Test]
    public function theRelatedPageLoadsOnlyThePagesRows(): void
    {
        // The second page of two is publisher 1 then agency 2, or advertiser 2 once agency 2 is
        // hidden.
        $queries = $this->queries(fn() => $this->read('/users/1/companies?page[number]=2&page[size]=2'));

        self::assertCount(1, $this->reads($queries, 'mtm_publishers'), \implode("\n", $queries));
        self::assertCount($this->hides() ? 1 : 0, $this->reads($queries, 'mtm_advertisers'), \implode("\n", $queries));
        // Agency 2's row on the plain page; the key-only admit query once agencies are scoped.
        $agencies = $this->reads($queries, 'mtm_agencies');
        self::assertCount(1, $agencies, \implode("\n", $queries));
        self::assertSame($this->hides(), !\str_contains($agencies[0], '*'), $agencies[0]);
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
        foreach (['mtm_agencies', 'mtm_advertisers', 'mtm_publishers'] as $table) {
            Schema::create($table, static function (Blueprint $table): void {
                $table->increments('id');
                $table->string('name');
                $table->string('code');
                $table->boolean('hidden')->default(false);
            });
        }
        Schema::create('mtm_users', static function (Blueprint $table): void {
            $table->increments('id');
            $table->string('name');
        });
        Schema::create('mtm_user_companies', static function (Blueprint $table): void {
            $table->increments('id');
            $table->unsignedInteger('mtm_user_id');
            $table->string('company_type');
            $table->unsignedInteger('company_id');
        });

        foreach (IdentifiedMorphManyServiceProvider::COMPANIES as $type => $rows) {
            foreach ($rows as [$id, $name, $code, $hidden]) {
                DB::table('mtm_' . $type)->insert(['id' => $id, 'name' => $name, 'code' => $code, 'hidden' => $hidden]);
            }
        }
        foreach (IdentifiedMorphManyServiceProvider::USERS as $id => [$name, $companies]) {
            DB::table('mtm_users')->insert(['id' => $id, 'name' => $name]);
            foreach ($companies as $company) {
                [$type, $companyId] = \explode(':', $company);
                $this->storeCompany($id, $type, (int) $companyId);
            }
        }
    }

    /**
     * The SQL `$read` runs.
     *
     * @return list<string>
     */
    private function queries(\Closure $read): array
    {
        $connection = DB::connection();
        $connection->flushQueryLog();
        $connection->enableQueryLog();

        try {
            $read();
        } finally {
            $connection->disableQueryLog();
        }

        return \array_map(static fn(array $query): string => (string) $query['query'], $connection->getQueryLog());
    }

    /**
     * The statements among `$queries` that read `$table`.
     *
     * @param list<string> $queries
     *
     * @return list<string>
     */
    private function reads(array $queries, string $table): array
    {
        return \array_values(\array_filter($queries, static fn(string $query): bool => \str_contains($query, 'from "' . $table . '"')));
    }
}
