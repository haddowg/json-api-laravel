<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Conformance;

use haddowg\JsonApiLaravel\Server\ServableResourceWarmer;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use Workbench\App\ForeignKey\ForeignKeySeed;
use Workbench\App\ForeignKey\Models\FkSecret;
use Workbench\App\Providers\ForeignKeyEloquentServiceProvider;

/**
 * {@see ForeignKeyConformanceTestCase} on Eloquent over SQLite, where each read through a
 * foreign key is one bounded query for the whole page of parents under the related model's
 * global scopes, plus what only storage shows: a lazy relation renders links only, a NOT NULL
 * key can't be cleared, and a replace leaves the rows a scope hides alone.
 *
 * @internal
 */
#[CoversNothing]
final class EloquentForeignKeyConformanceTest extends ForeignKeyConformanceTestCase
{
    protected function conformanceServiceProvider(): string
    {
        return ForeignKeyEloquentServiceProvider::class;
    }

    /**
     * @param \Illuminate\Foundation\Application $app
     */
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        /** @var \Illuminate\Contracts\Config\Repository $config */
        $config = $app['config'];
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
        $this->loadMigrationsFrom(\dirname(__DIR__, 2) . '/workbench/database/migrations');
    }

    protected function seedConformanceData(): void
    {
        ForeignKeySeed::seedEloquent();
    }

    #[Test]
    public function aWriteOnlyToManyNoRequestReadsPassesOptimizeAndOneAnUpdateReadsDoesNot(): void
    {
        $problems = \implode("\n", $this->app?->make(ServableResourceWarmer::class)->warm() ?? []);

        self::assertStringNotContainsString('resource type "fk-grants"', $problems);
        self::assertStringContainsString('Relation "secrets" on resource type "fk-revisable-grants" reads type "fk-secrets"', $problems);
    }

    #[Test]
    public function aCreateNamingASecretTheScopeHidesIsNotFound(): void
    {
        $this->jsonApi()
            ->withData(['type' => 'fk-grants', 'relationships' => ['secrets' => ['data' => [['type' => 'fk-secrets', 'id' => '2']]]]])
            ->post('/api/fk-grants')
            ->assertNotFound();
    }

    #[Test]
    public function aLockedToManyComparesAMemberTheScopeHides(): void
    {
        // The grant holds secret 2, which the scope hides, so no linkage a client can send keeps
        // its members unchanged: this is the read jsonapi:optimize refuses a type with Update for.
        $this->jsonApi()
            ->withData(['type' => 'fk-revisable-grants', 'id' => 'g1', 'relationships' => ['lockedSecrets' => ['data' => [['type' => 'fk-secrets', 'id' => '1'], ['type' => 'fk-secrets', 'id' => '2']]]]])
            ->patch('/api/fk-revisable-grants/g1')
            ->assertNotFound();
    }

    #[Test]
    public function aLazyRelationRendersNoLinkageUntilIncluded(): void
    {
        $document = $this->fetch('/fk-users/ada');

        self::assertArrayNotHasKey('data', $this->relationship($document['data'] ?? null, 'items'));
        self::assertArrayNotHasKey('data', $this->relationship($document['data'] ?? null, 'profile'));
    }

    #[Test]
    public function clearingAForeignKeyStorageRequiresIsRefused(): void
    {
        $this->jsonApi()->withData([])->patch('/api/fk-users/ada/relationships/notes')->assertStatus(403);
        $this->jsonApi()->withData([['type' => 'fk-notes', 'id' => '1']])->delete('/api/fk-users/ada/relationships/notes')->assertStatus(403);
        self::assertSame(['1'], $this->ids($this->fetch('/fk-users/ada/notes')['data'] ?? null));

        // Joining a row to the parent clears nothing, so it is allowed.
        $this->jsonApi()->withData([['type' => 'fk-notes', 'id' => '3']])->post('/api/fk-users/ada/relationships/notes')->assertOk();
        self::assertSame(['1', '3'], $this->ids($this->fetch('/fk-users/ada/notes')['data'] ?? null));
    }

    #[Test]
    public function aReplaceLeavesTheRowsTheScopeHides(): void
    {
        $this->jsonApi()->withData([])->patch('/api/fk-users/ada/relationships/secrets')->assertOk();
        self::assertSame([], $this->ids($this->fetch('/fk-users/ada/secrets')['data'] ?? null));

        $hidden = FkSecret::query()->withoutGlobalScopes()->find(2);
        self::assertInstanceOf(FkSecret::class, $hidden);
        self::assertSame('ada', $hidden->getAttribute('owner'));
    }

    #[Test]
    public function aScopedRelatedTypeServesOnlyTheRowsItsScopeAdmits(): void
    {
        self::assertSame(['1'], $this->ids($this->fetch('/fk-users/ada/secrets')['data'] ?? null));
        self::assertSame(['1'], $this->ids($this->fetch('/fk-users/ada/relationships/secrets')['data'] ?? null));

        $document = $this->fetch('/fk-users?include=secrets&withCount=secrets', [self::COUNTABLE_PROFILE]);
        $users = $document['data'] ?? null;
        self::assertIsArray($users);
        $ada = $this->relationship($users[0] ?? null, 'secrets');
        self::assertSame(['1'], $this->ids($ada['data'] ?? null));
        self::assertSame(['total' => 1], $ada['meta'] ?? null);
        self::assertSame(['fk-secrets:1'], $this->includedKeys($document));
    }

    #[Test]
    public function aCollectionIncludeIsOneBoundedQueryPerRelation(): void
    {
        $this->fetch('/fk-users'); // warm the provider-served users' own item reads
        DB::flushQueryLog();
        DB::enableQueryLog();

        $this->fetch('/fk-users?include=items,cursorItems');

        // The provider loads Ada's pinned and favourite items by id; the rest read through the foreign key.
        $items = \array_values(\array_filter($this->selectsFrom('fk_items'), static fn(string $sql): bool => \str_contains($sql, 'row_number() over')));
        self::assertCount(2, $items, \implode("\n", $items));
    }

    #[Test]
    public function withCountIsOneGroupedQuery(): void
    {
        DB::flushQueryLog();
        DB::enableQueryLog();

        $this->fetch('/fk-users?withCount=items', [self::COUNTABLE_PROFILE]);

        $counts = \array_values(\array_filter($this->selectsFrom('fk_items'), static fn(string $sql): bool => \str_contains($sql, 'count(')));
        self::assertCount(1, $counts, \implode("\n", $counts));
        self::assertStringContainsString('group by', $counts[0]);
    }

    /**
     * The logged selects reading `$table`, lowercased.
     *
     * @return list<string>
     */
    private function selectsFrom(string $table): array
    {
        $selects = [];
        foreach (DB::getQueryLog() as $entry) {
            $sql = \strtolower((string) $entry['query']);
            if (\str_starts_with(\ltrim($sql), 'select') && \str_contains($sql, '"' . $table . '"')) {
                $selects[] = $sql;
            }
        }

        return $selects;
    }
}
