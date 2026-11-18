<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Conformance;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Workbench\App\Providers\KeysetSortConformanceEloquentServiceProvider;
use Workbench\Database\Seeders\ScoreTeamSeeder;

/**
 * {@see KeysetSortConformanceTestCase} against the reference Eloquent provider over
 * in-memory SQLite, on the team collection and on a league's teams through their pivot
 * table.
 *
 * @internal
 */
#[CoversNothing]
final class EloquentKeysetSortConformanceTest extends KeysetSortConformanceTestCase
{
    protected function conformanceServiceProvider(): string
    {
        return KeysetSortConformanceEloquentServiceProvider::class;
    }

    /**
     * @param \Illuminate\Foundation\Application $app
     */
    protected function defineEnvironment($app): void
    {
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
        (new ScoreTeamSeeder())->run();
    }

    protected function collectionPaths(): array
    {
        return [...parent::collectionPaths(), '/api/scoreLeagues/1/teams'];
    }

    #[Test]
    #[Group('spec:fetching-includes')]
    #[Group('spec:fetching-pagination')]
    public function anIncludedPageOrderedByTheSubqueryLinksToItsNextPage(): void
    {
        $response = $this->get('/api/scoreLeagues/1?include=rankedTeams', ['Accept' => self::MEDIA_TYPE]);
        $response->assertOk();

        $relationship = $response->json('data.relationships.rankedTeams');
        self::assertIsArray($relationship);
        self::assertSame([['type' => 'scoreTeams', 'id' => '5'], ['type' => 'scoreTeams', 'id' => '2']], $relationship['data'] ?? null);

        $links = $relationship['links'] ?? null;
        self::assertIsArray($links);
        $next = $links['next'] ?? null;
        $next = \is_array($next) ? ($next['href'] ?? null) : $next;
        self::assertIsString($next);

        $path = (string) \parse_url($next, \PHP_URL_PATH) . '?' . (string) \parse_url($next, \PHP_URL_QUERY);
        $page = $this->get($path, ['Accept' => self::MEDIA_TYPE]);
        $page->assertOk();
        self::assertSame([['type' => 'scoreTeams', 'id' => '4'], ['type' => 'scoreTeams', 'id' => '1']], $page->json('data'));
    }
}
